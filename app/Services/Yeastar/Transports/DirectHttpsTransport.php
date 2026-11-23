<?php

namespace App\Services\Yeastar\Transports;

use App\Enums\ConnectionStatus;
use App\Models\ApiAuditLog;
use App\Models\YeastarConnection;
use App\Security\SecretRedactor;
use App\Security\SsrfGuard;
use App\Services\Yeastar\Contracts\YeastarTransportInterface;
use App\Services\Yeastar\YeastarApiException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class DirectHttpsTransport implements YeastarTransportInterface
{
    public function __construct(
        private readonly SsrfGuard $ssrfGuard,
    ) {}

    public function request(
        YeastarConnection $connection,
        string $method,
        string $path,
        array $payload = [],
        bool $retriedAuth = false,
    ): array {
        $baseUrl = $this->validatedBaseUrl($connection);

        if ($connection->needsTokenRefresh((int) config('telephony.yeastar.token_refresh_buffer_seconds', 300))) {
            $connection = $this->authenticate($connection);
        }

        $cleanPath = '/'.ltrim($path, '/');
        $url = "{$baseUrl}/openapi/v1.0{$cleanPath}";
        $timeout = (int) config('telephony.yeastar.http_timeout_seconds', 15);
        $startedAt = microtime(true);

        $client = Http::timeout($timeout)
            ->acceptJson()
            ->withQueryParameters(['access_token' => $connection->access_token_enc]);

        /** @var Response $response */
        $response = strtoupper($method) === 'GET'
            ? $client->get($url, $payload)
            : $client->asJson()->post($url, $payload);

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $data = $response->json();
        $dataArray = is_array($data) ? $data : [];
        $errCode = isset($dataArray['errcode']) ? (int) $dataArray['errcode'] : ($response->successful() ? 0 : $response->status());

        $this->recordAudit(
            tenantId: $connection->tenant_id,
            method: strtoupper($method).' '.$cleanPath,
            httpStatus: $response->status(),
            errorCode: $errCode === 0 ? null : (string) $errCode,
            durationMs: $durationMs,
            requestSummary: SecretRedactor::redactArray($payload),
            responseSummary: [
                'errcode' => $errCode,
                'errmsg' => $dataArray['errmsg'] ?? null,
            ],
        );

        if ($errCode !== 0 || ! $response->successful()) {
            $exception = new YeastarApiException(
                errCode: $errCode,
                message: (string) ($dataArray['errmsg'] ?? 'Yeastar Open API request failed.'),
                httpStatus: $response->status(),
            );

            if ($exception->isTokenExpired() && ! $retriedAuth) {
                $refreshed = $this->authenticate($connection, force: true);

                return $this->request($refreshed, $method, $path, $payload, retriedAuth: true);
            }

            throw $exception;
        }

        return $dataArray;
    }

    public function authenticate(YeastarConnection $connection, bool $force = false): YeastarConnection
    {
        $lockKey = "t:{$connection->tenant_id}:refresh:yeastar:{$connection->id}";

        return Cache::lock($lockKey, 15)->block(10, function () use ($connection, $force): YeastarConnection {
            $fresh = $connection->fresh();
            if ($fresh instanceof YeastarConnection && ! $force && ! $fresh->needsTokenRefresh()) {
                return $fresh;
            }

            $active = $fresh ?? $connection;
            $baseUrl = $this->validatedBaseUrl($active);

            if (empty($active->client_id) || empty($active->client_secret_enc)) {
                throw new YeastarApiException(401, 'Yeastar Open API Client ID and Client Secret are required.', 401);
            }

            /** @var Response $response */
            $response = Http::timeout((int) config('telephony.yeastar.http_timeout_seconds', 15))
                ->acceptJson()
                ->asJson()
                ->post("{$baseUrl}/openapi/v1.0/get_token", [
                    'username' => $active->client_id,
                    'password' => $active->client_secret_enc,
                ]);

            $payload = $response->json();
            if (! $response->successful() || ! is_array($payload) || (int) ($payload['errcode'] ?? -1) !== 0 || empty($payload['access_token'])) {
                $active->update(['status' => ConnectionStatus::Degraded]);

                throw new YeastarApiException(
                    errCode: (int) ($payload['errcode'] ?? $response->status()),
                    message: (string) ($payload['errmsg'] ?? 'Failed to authenticate with Yeastar P-Series Open API.'),
                    httpStatus: $response->status(),
                );
            }

            // Per Yeastar P-Series V1.7 Sec 2.2.3: access_token expires after 30 minutes (1800s)
            $expiresIn = max(120, (int) ($payload['access_token_expire_time'] ?? 1800));

            $active->update([
                'access_token_enc' => (string) $payload['access_token'],
                'token_expires_at' => now()->addSeconds($expiresIn),
                'status' => ConnectionStatus::Connected,
                'last_heartbeat_at' => now(),
            ]);

            return $active->fresh() ?? $active;
        });
    }

    public function healthCheck(YeastarConnection $connection): array
    {
        $startedAt = microtime(true);
        $info = $this->request($connection, 'GET', '/system/info');
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        $firmware = (string) ($info['firmware_version'] ?? $info['data']['firmware_version'] ?? '');
        $minFirmware = (string) config('telephony.yeastar.min_firmware_version', '37.7.0.16');
        $meetsMin = $firmware === '' || version_compare($firmware, $minFirmware, '>=');

        $connection->update([
            'firmware_version' => $firmware !== '' ? $firmware : $connection->firmware_version,
            'status' => ConnectionStatus::Connected,
            'last_heartbeat_at' => now(),
        ]);

        return [
            'ok' => true,
            'firmware_version' => $firmware !== '' ? $firmware : null,
            'meets_min_firmware' => $meetsMin,
            'latency_ms' => $latencyMs,
            'message' => $meetsMin
                ? 'Yeastar P-Series Open API connection verified.'
                : "Connected, but PBX firmware ({$firmware}) is below recommended {$minFirmware}.",
        ];
    }

    public function listExtensions(YeastarConnection $connection): array
    {
        $response = $this->request($connection, 'GET', '/extension/list', [
            'page' => 1,
            'page_size' => 500,
        ]);

        $items = is_array($response['data'] ?? null) ? $response['data'] : [];
        $extensions = [];

        foreach ($items as $item) {
            if (! is_array($item) || empty($item['number'])) {
                continue;
            }

            $extensions[] = [
                'number' => (string) $item['number'],
                'caller_id_name' => (string) ($item['caller_id_name'] ?? $item['first_name'] ?? $item['number']),
                'email' => isset($item['email_addr']) && $item['email_addr'] !== '' ? (string) $item['email_addr'] : null,
                'presence_status' => isset($item['presence_status']) ? (string) $item['presence_status'] : null,
            ];
        }

        return $extensions;
    }

    public function listTrunks(YeastarConnection $connection): array
    {
        $response = $this->request($connection, 'GET', '/trunk/list', [
            'page' => 1,
            'page_size' => 200,
        ]);

        $items = is_array($response['data'] ?? null) ? $response['data'] : [];
        $trunks = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $trunks[] = [
                'id' => (string) ($item['id'] ?? $item['name'] ?? ''),
                'name' => (string) ($item['name'] ?? 'Trunk'),
                'type' => isset($item['trunk_type']) ? (string) $item['trunk_type'] : null,
                'status' => isset($item['status']) ? (string) $item['status'] : null,
            ];
        }

        return $trunks;
    }

    public function dial(YeastarConnection $connection, string $callerExtension, string $calleeNumber): array
    {
        return $this->request($connection, 'POST', '/call/dial', [
            'caller' => $callerExtension,
            'callee' => $calleeNumber,
            'auto_answer' => 'no',
        ]);
    }

    public function requestRecordingDownload(YeastarConnection $connection, string $recordingFile): array
    {
        return $this->request($connection, 'GET', '/recording/download', [
            'file' => $recordingFile,
        ]);
    }

    public function upsertPhonebookContact(YeastarConnection $connection, array $contact): array
    {
        $numberList = array_values(array_map(static fn (string $num): array => [
            'type' => 'business',
            'number' => $num,
        ], $contact['numbers']));

        return $this->request($connection, 'POST', '/contact/create', [
            'first_name' => $contact['first_name'],
            'last_name' => $contact['last_name'] ?? '',
            'email' => $contact['email'] ?? '',
            'number_list' => $numberList,
            'phonebook_name' => $contact['phonebook'] ?? 'CRM_Synchronization',
        ]);
    }

    private function validatedBaseUrl(YeastarConnection $connection): string
    {
        if (empty($connection->pbx_base_url)) {
            throw new YeastarApiException(400, 'Yeastar PBX Base URL (FQDN) is required for Direct HTTPS transport.', 400);
        }

        $resolveDns = ! app()->environment('testing');

        return $this->ssrfGuard->assertSafeExternalHttpsUrl($connection->pbx_base_url, resolveDns: $resolveDns);
    }

    /**
     * @param  array<string, mixed>  $requestSummary
     * @param  array<string, mixed>  $responseSummary
     */
    private function recordAudit(
        string $tenantId,
        string $method,
        int $httpStatus,
        ?string $errorCode,
        int $durationMs,
        array $requestSummary,
        array $responseSummary,
    ): void {
        ApiAuditLog::query()->create([
            'tenant_id' => $tenantId,
            'provider' => 'yeastar',
            'method' => $method,
            'http_status' => $httpStatus,
            'error_code' => $errorCode,
            'duration_ms' => $durationMs,
            'request_summary' => $requestSummary,
            'response_summary' => $responseSummary,
            'created_at' => now(),
        ]);
    }
}
