<?php

namespace App\Services\Bitrix24;

use App\Enums\ConnectionStatus;
use App\Models\ApiAuditLog;
use App\Models\BitrixConnection;
use App\Models\Tenant;
use App\Security\SecretRedactor;
use App\Security\SsrfGuard;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class BitrixClient
{
    public function __construct(
        private readonly BitrixRateLimiter $rateLimiter,
        private readonly SsrfGuard $ssrfGuard,
    ) {}

    /**
     * Execute a Bitrix24 REST API method in Marketplace OAuth application context.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function call(
        BitrixConnection $connection,
        string $method,
        array $params = [],
        bool $highPriority = true,
        bool $retriedAuth = false,
    ): array {
        $tenant = $connection->tenant;
        if (! $tenant instanceof Tenant) {
            throw new BitrixApiException('NO_AUTH_FOUND', 'Tenant record missing for Bitrix24 connection.', 401);
        }

        $this->rateLimiter->acquire($tenant, $method, $highPriority);

        if ($connection->needsTokenRefresh()) {
            $connection = $this->refreshTokens($connection);
        }

        $endpointBase = $this->ssrfGuard->assertSafeExternalHttpsUrl($connection->client_endpoint, resolveDns: false);
        $url = rtrim($endpointBase, '/').'/'.ltrim($method, '/').'.json';

        $startedAt = microtime(true);
        $payload = array_merge($params, ['auth' => $connection->access_token_enc]);

        /** @var Response $response */
        $response = Http::timeout(20)
            ->acceptJson()
            ->asJson()
            ->post($url, $payload);

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $body = $response->json();
        $bodyArray = is_array($body) ? $body : [];

        $timeBlock = is_array($bodyArray['time'] ?? null) ? $bodyArray['time'] : [];
        if ($timeBlock !== []) {
            $this->rateLimiter->recordOperatingTelemetry($tenant, $method, $timeBlock);
        }

        $errorCode = array_key_exists('error', $bodyArray) ? (string) $bodyArray['error'] : null;

        $this->recordAuditLog(
            tenantId: $tenant->id,
            method: $method,
            httpStatus: $response->status(),
            errorCode: $errorCode,
            durationMs: $durationMs,
            operatingSec: isset($timeBlock['operating']) ? (float) $timeBlock['operating'] : null,
            requestSummary: SecretRedactor::redactArray($params),
            responseSummary: $errorCode !== null
                ? ['error' => $errorCode, 'error_description' => $bodyArray['error_description'] ?? null]
                : ['has_result' => array_key_exists('result', $bodyArray), 'next' => $bodyArray['next'] ?? null],
        );

        if ($errorCode !== null) {
            $exception = new BitrixApiException(
                errorCode: $errorCode,
                message: (string) ($bodyArray['error_description'] ?? 'Bitrix24 REST API error.'),
                httpStatus: $response->status(),
                retryAfterSeconds: $response->header('Retry-After') ? (int) $response->header('Retry-After') : null,
                operatingResetAt: isset($timeBlock['operating_reset_at']) ? (int) $timeBlock['operating_reset_at'] : null,
            );

            if ($exception->isExpiredToken() && ! $retriedAuth) {
                $refreshed = $this->refreshTokens($connection, force: true);

                return $this->call($refreshed, $method, $params, $highPriority, retriedAuth: true);
            }

            throw $exception;
        }

        if (! $response->successful()) {
            throw new BitrixApiException(
                errorCode: 'HTTP_ERROR',
                message: 'Unexpected HTTP failure calling Bitrix24 REST API.',
                httpStatus: $response->status(),
            );
        }

        return $bodyArray;
    }

    /**
     * Execute up to 50 Bitrix24 REST commands in a single HTTP batch request.
     *
     * @param  array<string, string>  $commands
     * @return array{result: array<string, mixed>, errors: array<string, mixed>, has_errors: bool}
     */
    public function batch(BitrixConnection $connection, array $commands, bool $halt = false, bool $highPriority = false): array
    {
        if (count($commands) > 50) {
            throw new BitrixApiException('ERROR_BATCH_LENGTH_EXCEEDED', 'Bitrix24 batch supports a maximum of 50 sub-commands.', 400);
        }

        $response = $this->call($connection, 'batch', [
            'halt' => $halt ? 1 : 0,
            'cmd' => $commands,
        ], highPriority: $highPriority);

        $resultBlock = is_array($response['result'] ?? null) ? $response['result'] : [];
        $batchErrors = is_array($resultBlock['result_error'] ?? null) ? $resultBlock['result_error'] : [];

        return [
            'result' => is_array($resultBlock['result'] ?? null) ? $resultBlock['result'] : [],
            'errors' => $batchErrors,
            'has_errors' => count($batchErrors) > 0,
        ];
    }

    /**
     * Refresh per-tenant OAuth 2.0 tokens using the central Marketplace App client_id & client_secret,
     * guarded by a distributed lock to prevent concurrent refresh token invalidation.
     */
    public function refreshTokens(BitrixConnection $connection, bool $force = false): BitrixConnection
    {
        $lockKey = "t:{$connection->tenant_id}:refresh:bitrix24";

        return Cache::lock($lockKey, 15)->block(10, function () use ($connection, $force): BitrixConnection {
            $fresh = $connection->fresh();
            if ($fresh instanceof BitrixConnection && ! $force && ! $fresh->needsTokenRefresh()) {
                return $fresh;
            }

            $active = $fresh ?? $connection;
            $clientId = (string) config('telephony.bitrix24.client_id');
            $clientSecret = (string) config('telephony.bitrix24.client_secret');
            $oauthUrl = (string) config('telephony.bitrix24.oauth_server', 'https://oauth.bitrix.info/oauth/token/');

            if ($clientId === '' || $clientSecret === '' || empty($active->refresh_token_enc)) {
                throw new BitrixApiException(
                    errorCode: 'INVALID_CREDENTIALS',
                    message: 'Bitrix24 Marketplace OAuth credentials or tenant refresh token missing.',
                    httpStatus: 401,
                );
            }

            /** @var Response $response */
            $response = Http::timeout(15)
                ->acceptJson()
                ->asForm()
                ->post($oauthUrl, [
                    'grant_type' => 'refresh_token',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'refresh_token' => $active->refresh_token_enc,
                ]);

            $payload = $response->json();
            if (! $response->successful() || ! is_array($payload) || empty($payload['access_token'])) {
                $active->update(['status' => ConnectionStatus::Degraded]);

                throw new BitrixApiException(
                    errorCode: (string) ($payload['error'] ?? 'OAUTH_REFRESH_FAILED'),
                    message: (string) ($payload['error_description'] ?? 'Failed to refresh Bitrix24 OAuth tokens.'),
                    httpStatus: $response->status(),
                );
            }

            $expiresIn = max(60, (int) ($payload['expires_in'] ?? 3600));

            $active->update([
                'access_token_enc' => (string) $payload['access_token'],
                'refresh_token_enc' => (string) ($payload['refresh_token'] ?? $active->refresh_token_enc),
                'token_expires_at' => now()->addSeconds($expiresIn),
                'status' => ConnectionStatus::Connected,
            ]);

            return $active->fresh() ?? $active;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function addExternalLine(BitrixConnection $connection, string $number, string $name): array
    {
        return $this->call($connection, 'telephony.externalLine.add', [
            'NUMBER' => $number,
            'NAME' => $name,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getExternalLines(BitrixConnection $connection): array
    {
        $response = $this->call($connection, 'telephony.externalLine.get');

        return is_array($response['result'] ?? null) ? $response['result'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function bindEvent(BitrixConnection $connection, string $event, string $handlerUrl): array
    {
        return $this->call($connection, 'event.bind', [
            'event' => $event,
            'handler' => $handlerUrl,
        ]);
    }

    /**
     * Fetch active Bitrix24 users (paginated, max 50 per page).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listUsers(BitrixConnection $connection, int $maxPages = 10): array
    {
        $users = [];
        $start = 0;

        for ($page = 0; $page < $maxPages; $page++) {
            $response = $this->call($connection, 'user.get', [
                'FILTER' => ['ACTIVE' => true],
                'start' => $start,
            ], highPriority: false);

            $chunk = is_array($response['result'] ?? null) ? $response['result'] : [];
            foreach ($chunk as $user) {
                if (is_array($user)) {
                    $users[] = $user;
                }
            }

            if (! isset($response['next'])) {
                break;
            }

            $start = (int) $response['next'];
        }

        return $users;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchCrmEntities(BitrixConnection $connection, string $phoneNumber): array
    {
        $response = $this->call($connection, 'telephony.externalCall.searchCrmEntities', [
            'PHONE_NUMBER' => $phoneNumber,
        ]);

        return is_array($response['result'] ?? null) ? $response['result'] : [];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function registerExternalCall(BitrixConnection $connection, array $params): array
    {
        $response = $this->call($connection, 'telephony.externalCall.register', $params);

        return is_array($response['result'] ?? null) ? $response['result'] : [];
    }

    /**
     * @param  int|list<int>  $userIds
     */
    public function showExternalCall(BitrixConnection $connection, string $callId, int|array $userIds): bool
    {
        $response = $this->call($connection, 'telephony.externalCall.show', [
            'CALL_ID' => $callId,
            'USER_ID' => $userIds,
        ]);

        return (bool) ($response['result'] ?? false);
    }

    /**
     * @param  int|list<int>  $userIds
     */
    public function hideExternalCall(BitrixConnection $connection, string $callId, int|array $userIds): bool
    {
        $response = $this->call($connection, 'telephony.externalCall.hide', [
            'CALL_ID' => $callId,
            'USER_ID' => $userIds,
        ]);

        return (bool) ($response['result'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function finishExternalCall(BitrixConnection $connection, array $params): array
    {
        $response = $this->call($connection, 'telephony.externalCall.finish', $params);

        return is_array($response['result'] ?? null) ? $response['result'] : [];
    }

    /**
     * Fetch CRM contacts using Bitrix24's recommended ID-based pagination (start = -1)
     * to avoid expensive COUNT(*) queries and stay within operating time budgets.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listCrmContacts(BitrixConnection $connection, int $lastId = 0): array
    {
        $response = $this->call($connection, 'crm.contact.list', [
            'order' => ['ID' => 'ASC'],
            'filter' => ['>ID' => $lastId],
            'select' => ['ID', 'NAME', 'LAST_NAME', 'PHONE', 'EMAIL'],
            'start' => -1,
        ], highPriority: false);

        return is_array($response['result'] ?? null) ? $response['result'] : [];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function attachCallRecord(BitrixConnection $connection, array $params): array
    {
        $response = $this->call($connection, 'telephony.externalCall.attachRecord', $params, highPriority: false);

        return is_array($response['result'] ?? null) ? $response['result'] : [];
    }

    /**
     * @param  array<string, mixed>  $requestSummary
     * @param  array<string, mixed>  $responseSummary
     */
    private function recordAuditLog(
        string $tenantId,
        string $method,
        int $httpStatus,
        ?string $errorCode,
        int $durationMs,
        ?float $operatingSec,
        array $requestSummary,
        array $responseSummary,
    ): void {
        ApiAuditLog::query()->create([
            'tenant_id' => $tenantId,
            'provider' => 'bitrix24',
            'method' => $method,
            'http_status' => $httpStatus,
            'error_code' => $errorCode,
            'duration_ms' => $durationMs,
            'b24_operating_sec' => $operatingSec,
            'request_summary' => $requestSummary,
            'response_summary' => $responseSummary,
            'created_at' => now(),
        ]);
    }
}
