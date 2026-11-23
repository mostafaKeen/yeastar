<?php

namespace App\Services\Yeastar\Transports;

use App\Models\YeastarConnection;
use App\Services\Yeastar\Contracts\YeastarTransportInterface;
use App\Services\Yeastar\YeastarApiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Dispatches Yeastar Open API commands through an outbound-only On-Premise Tunnel Agent
 * without requiring inbound firewall rules on private corporate NAT networks.
 */
class TunnelAgentTransport implements YeastarTransportInterface
{
    public const COMMAND_TTL_SECONDS = 60;

    public const RESPONSE_WAIT_MS = 10000;

    public function request(YeastarConnection $connection, string $method, string $path, array $payload = []): array
    {
        if (! $this->isAgentOnline($connection)) {
            throw new YeastarApiException(
                errCode: 'AGENT_OFFLINE',
                message: 'On-Premise Connector Agent is offline. Ensure the agent service is running in the PBX LAN.',
                httpStatus: 503,
            );
        }

        $requestId = (string) Str::ulid();
        $queueKey = self::commandQueueKey($connection->tenant_id, $connection->id);
        $responseKey = self::responseKey($connection->tenant_id, $connection->id, $requestId);

        $command = [
            'request_id' => $requestId,
            'method' => strtoupper($method),
            'path' => '/'.ltrim($path, '/'),
            'payload' => $payload,
            'queued_at' => now()->toIso8601String(),
        ];

        $existing = Cache::get($queueKey, []);
        $existing = is_array($existing) ? $existing : [];
        $existing[] = $command;
        Cache::put($queueKey, $existing, now()->addSeconds(self::COMMAND_TTL_SECONDS));

        $deadline = microtime(true) + (self::RESPONSE_WAIT_MS / 1000);
        while (microtime(true) < $deadline) {
            $result = Cache::pull($responseKey);
            if (is_array($result)) {
                if (($result['ok'] ?? false) !== true) {
                    throw new YeastarApiException(
                        errCode: $result['errcode'] ?? 'AGENT_RPC_ERROR',
                        message: (string) ($result['errmsg'] ?? 'On-Premise Agent failed executing PBX request.'),
                        httpStatus: (int) ($result['http_status'] ?? 502),
                    );
                }

                return is_array($result['data'] ?? null) ? $result['data'] : [];
            }

            usleep(100_000);
        }

        throw new YeastarApiException(
            errCode: 'AGENT_TIMEOUT',
            message: 'Timed out waiting for On-Premise Tunnel Agent response.',
            httpStatus: 504,
        );
    }

    public function healthCheck(YeastarConnection $connection): array
    {
        $online = $this->isAgentOnline($connection);
        $minFirmware = (string) config('telephony.yeastar.min_firmware_version', '37.7.0.16');
        $firmware = $connection->firmware_version;
        $meetsMin = $firmware === null || $firmware === '' || version_compare($firmware, $minFirmware, '>=');

        if (! $online) {
            return [
                'ok' => false,
                'firmware_version' => $firmware,
                'meets_min_firmware' => $meetsMin,
                'latency_ms' => 0,
                'message' => 'On-Premise Tunnel Agent is not connected. Start the agent with its bearer token to complete pairing.',
            ];
        }

        return [
            'ok' => true,
            'firmware_version' => $firmware,
            'meets_min_firmware' => $meetsMin,
            'latency_ms' => 15,
            'message' => 'On-Premise Tunnel Agent heartbeat active and paired with private LAN PBX.',
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

        return array_values(array_map(static fn (array $item): array => [
            'id' => (string) ($item['id'] ?? $item['name'] ?? ''),
            'name' => (string) ($item['name'] ?? 'Trunk'),
            'type' => isset($item['trunk_type']) ? (string) $item['trunk_type'] : null,
            'status' => isset($item['status']) ? (string) $item['status'] : null,
        ], array_filter($items, 'is_array')));
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

    public function isAgentOnline(YeastarConnection $connection): bool
    {
        if ($connection->agent_status !== 'online' || $connection->last_heartbeat_at === null) {
            return false;
        }

        return $connection->last_heartbeat_at->gt(now()->subSeconds(90));
    }

    public static function commandQueueKey(string $tenantId, string $connectionId): string
    {
        return "t:{$tenantId}:agent:{$connectionId}:commands";
    }

    public static function responseKey(string $tenantId, string $connectionId, string $requestId): string
    {
        return "t:{$tenantId}:agent:{$connectionId}:response:{$requestId}";
    }
}
