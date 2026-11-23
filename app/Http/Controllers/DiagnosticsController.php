<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessBitrixTelephonyEventJob;
use App\Jobs\ProcessYeastarEventJob;
use App\Models\ApiAuditLog;
use App\Models\WebhookDelivery;
use App\Models\YeastarConnection;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DiagnosticsController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request): Response
    {
        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 403);
        Gate::authorize('viewAllCalls', $tenant);

        $webhooks = WebhookDelivery::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(static fn (WebhookDelivery $d): array => [
                'id' => $d->id,
                'source' => $d->source,
                'event_name' => $d->event_name,
                'idempotency_key' => $d->idempotency_key,
                'status' => $d->status,
                'http_status' => $d->http_status,
                'latency_ms' => $d->latency_ms,
                'sanitized_payload' => $d->sanitized_payload,
                'error_message' => $d->error_message,
                'created_at' => $d->created_at?->toIso8601String(),
            ])->values();

        $auditLogs = ApiAuditLog::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(static fn (ApiAuditLog $log): array => [
                'id' => $log->id,
                'provider' => $log->provider,
                'method' => $log->method,
                'http_status' => $log->http_status,
                'error_code' => $log->error_code,
                'duration_ms' => $log->duration_ms,
                'b24_operating_sec' => $log->b24_operating_sec,
                'request_summary' => $log->request_summary,
                'response_summary' => $log->response_summary,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values();

        return Inertia::render('diagnostics/index', [
            'webhooks' => $webhooks,
            'auditLogs' => $auditLogs,
            'retentionDays' => (int) config('telephony.retention.api_audit_default_days', 14),
            'canReplay' => $request->user()->can('manageIntegrations', $tenant),
        ]);
    }

    public function replayWebhook(Request $request, string $deliveryId): RedirectResponse
    {
        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 403);
        Gate::authorize('manageIntegrations', $tenant);

        $delivery = WebhookDelivery::query()->where('id', $deliveryId)->firstOrFail();
        $payload = is_array($delivery->sanitized_payload) ? $delivery->sanitized_payload : [];

        if ($delivery->source === 'bitrix24') {
            $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            ProcessBitrixTelephonyEventJob::dispatch(
                tenantId: $tenant->id,
                eventName: $delivery->event_name,
                data: $data,
                deliveryId: $delivery->id,
            );
        } else {
            $pbx = YeastarConnection::query()->first();
            if ($pbx === null) {
                return back()->with('error', 'No active Yeastar connection found to replay this event.');
            }

            ProcessYeastarEventJob::dispatch(
                tenantId: $tenant->id,
                yeastarConnectionId: $pbx->id,
                payload: $payload,
                deliveryId: $delivery->id,
            );
        }

        WebhookDelivery::query()->where('id', $delivery->id)->update(['status' => 'replayed']);

        return back()->with('status', "Queued replay for {$delivery->event_name} ({$delivery->id}).");
    }
}
