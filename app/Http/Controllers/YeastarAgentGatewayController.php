<?php

namespace App\Http\Controllers;

use App\Enums\ConnectionStatus;
use App\Jobs\ProcessYeastarEventJob;
use App\Models\WebhookDelivery;
use App\Models\YeastarConnection;
use App\Security\SecretRedactor;
use App\Services\Yeastar\Transports\TunnelAgentTransport;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class YeastarAgentGatewayController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function heartbeat(Request $request): JsonResponse
    {
        /** @var YeastarConnection $connection */
        $connection = $request->attributes->get('yeastar_connection');

        $validated = $request->validate([
            'firmware_version' => ['nullable', 'string', 'max:40'],
            'pbx_reachable' => ['sometimes', 'boolean'],
        ]);

        $pbxReachable = (bool) ($validated['pbx_reachable'] ?? true);

        $connection->update([
            'firmware_version' => $validated['firmware_version'] ?? $connection->firmware_version,
            'agent_status' => 'online',
            'status' => $pbxReachable ? ConnectionStatus::Connected : ConnectionStatus::Degraded,
            'last_heartbeat_at' => now(),
        ]);

        return response()->json([
            'status' => 'ok',
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function poll(Request $request): JsonResponse
    {
        /** @var YeastarConnection $connection */
        $connection = $request->attributes->get('yeastar_connection');

        $connection->update([
            'agent_status' => 'online',
            'last_heartbeat_at' => now(),
        ]);

        $queueKey = TunnelAgentTransport::commandQueueKey($connection->tenant_id, $connection->id);
        $commands = Cache::pull($queueKey, []);

        return response()->json([
            'commands' => is_array($commands) ? $commands : [],
        ]);
    }

    public function respond(Request $request): JsonResponse
    {
        /** @var YeastarConnection $connection */
        $connection = $request->attributes->get('yeastar_connection');

        $validated = $request->validate([
            'request_id' => ['required', 'string', 'max:40'],
            'ok' => ['required', 'boolean'],
            'http_status' => ['sometimes', 'integer'],
            'errcode' => ['nullable'],
            'errmsg' => ['nullable', 'string'],
            'data' => ['nullable', 'array'],
        ]);

        $responseKey = TunnelAgentTransport::responseKey(
            $connection->tenant_id,
            $connection->id,
            $validated['request_id'],
        );

        Cache::put($responseKey, $validated, now()->addSeconds(30));

        return response()->json(['status' => 'recorded']);
    }

    public function events(Request $request): JsonResponse
    {
        /** @var YeastarConnection $connection */
        $connection = $request->attributes->get('yeastar_connection');
        $eventName = (string) ($request->input('event') ?? $request->input('type') ?? 'PBX_EVENT');

        $delivery = WebhookDelivery::query()->create([
            'source' => 'yeastar_agent',
            'event_name' => $eventName,
            'idempotency_key' => $request->input('msg_id') ? "ys_agent:{$request->input('msg_id')}" : null,
            'status' => 'received',
            'http_status' => 200,
            'sanitized_payload' => SecretRedactor::redactArray($request->all()),
            'created_at' => now(),
        ]);

        ProcessYeastarEventJob::dispatch(
            tenantId: $connection->tenant_id,
            yeastarConnectionId: $connection->id,
            payload: $request->all(),
            deliveryId: $delivery->id,
        );

        return response()->json(['status' => 'accepted']);
    }

    public function directWebhook(Request $request, string $webhookUlid): JsonResponse
    {
        $connection = $this->tenantContext->withoutTenancy(static function () use ($webhookUlid): ?YeastarConnection {
            return YeastarConnection::query()
                ->where('webhook_ulid', $webhookUlid)
                ->first();
        });

        if ($connection === null) {
            return response()->json(['error' => 'Unknown Yeastar webhook endpoint.'], Response::HTTP_NOT_FOUND);
        }

        $allowedIps = is_array($connection->allowed_ips) ? array_filter($connection->allowed_ips) : [];
        if ($allowedIps !== [] && ! in_array($request->ip(), $allowedIps, true)) {
            return response()->json(['error' => 'Source IP not permitted for this PBX connection.'], Response::HTTP_FORBIDDEN);
        }

        if (! empty($connection->webhook_secret_enc)) {
            $provided = (string) ($request->header('X-Yeastar-Secret') ?? $request->input('secret', ''));
            if ($provided === '' || ! hash_equals($connection->webhook_secret_enc, $provided)) {
                return response()->json(['error' => 'Invalid Yeastar webhook secret.'], Response::HTTP_UNAUTHORIZED);
            }
        }

        $deliveryId = $this->tenantContext->runFor($connection->tenant_id, static function () use ($request): string {
            $eventName = (string) ($request->input('event') ?? $request->input('type') ?? 'PBX_DIRECT_EVENT');

            $delivery = WebhookDelivery::query()->create([
                'source' => 'yeastar_direct',
                'event_name' => $eventName,
                'idempotency_key' => $request->input('msg_id') ? "ys_direct:{$request->input('msg_id')}" : null,
                'status' => 'received',
                'http_status' => 200,
                'sanitized_payload' => SecretRedactor::redactArray($request->all()),
                'created_at' => now(),
            ]);

            return $delivery->id;
        });

        ProcessYeastarEventJob::dispatch(
            tenantId: $connection->tenant_id,
            yeastarConnectionId: $connection->id,
            payload: $request->all(),
            deliveryId: $deliveryId,
        );

        return response()->json(['status' => 'accepted']);
    }
}
