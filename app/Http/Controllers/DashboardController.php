<?php

namespace App\Http\Controllers;

use App\Enums\CallDirection;
use App\Models\BitrixConnection;
use App\Models\CallLog;
use App\Models\ExtensionMapping;
use App\Models\ExternalLine;
use App\Models\YeastarConnection;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request): Response
    {
        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 403, 'Active tenant workspace required.');

        $bitrix = BitrixConnection::query()->first();
        $yeastarConnections = YeastarConnection::query()
            ->orderBy('created_at')
            ->get([
                'id',
                'name',
                'transport_mode',
                'pbx_base_url',
                'firmware_version',
                'agent_status',
                'agent_token_prefix',
                'status',
                'last_heartbeat_at',
            ]);

        $mappedExtensionsCount = ExtensionMapping::query()->where('is_active', true)->count();
        $externalLinesCount = ExternalLine::query()->where('is_active', true)->count();

        return Inertia::render('dashboard', [
            'connections' => [
                'bitrix' => $bitrix === null ? null : [
                    'id' => $bitrix->id,
                    'domain' => $bitrix->domain,
                    'portal_type' => $bitrix->portal_type,
                    'status' => $bitrix->status->value,
                    'granted_scopes' => $bitrix->granted_scopes ?? [],
                    'token_expires_at' => $bitrix->token_expires_at?->toIso8601String(),
                    'last_synced_at' => $bitrix->last_synced_at?->toIso8601String(),
                ],
                'yeastar' => $yeastarConnections->map(static fn (YeastarConnection $conn): array => [
                    'id' => $conn->id,
                    'name' => $conn->name,
                    'transport_mode' => $conn->transport_mode->value,
                    'pbx_base_url' => $conn->pbx_base_url,
                    'firmware_version' => $conn->firmware_version,
                    'agent_status' => $conn->agent_status,
                    'agent_token_prefix' => $conn->agent_token_prefix,
                    'status' => $conn->status->value,
                    'last_heartbeat_at' => $conn->last_heartbeat_at?->toIso8601String(),
                ])->values(),
            ],
            'summary' => [
                'mapped_extensions' => $mappedExtensionsCount,
                'external_lines' => $externalLinesCount,
                'rate_limit_rps' => $tenant->bitrixRateLimitPerSecond(),
                'rate_burst_limit' => $tenant->bitrixBurstLimit(),
                'recording_storage_mode' => 'stream_only',
            ],
            'callMetrics' => Inertia::defer(static function (): array {
                $since = now()->subDay();
                $total = CallLog::query()->where('started_at', '>=', $since)->count();
                $inbound = CallLog::query()->where('started_at', '>=', $since)->where('direction', CallDirection::Inbound->value)->count();
                $outbound = CallLog::query()->where('started_at', '>=', $since)->where('direction', CallDirection::Outbound->value)->count();
                $missed = CallLog::query()->where('started_at', '>=', $since)->where('status_code', '304')->count();

                return [
                    'total_24h' => $total,
                    'inbound_24h' => $inbound,
                    'outbound_24h' => $outbound,
                    'missed_24h' => $missed,
                ];
            }),
            'recentCalls' => Inertia::defer(static function () {
                return CallLog::query()
                    ->orderByDesc('started_at')
                    ->orderByDesc('id')
                    ->limit(8)
                    ->get([
                        'id',
                        'direction',
                        'caller_number',
                        'callee_number',
                        'extension',
                        'crm_entity_type',
                        'crm_entity_id',
                        'status_code',
                        'duration_sec',
                        'recording_status',
                        'started_at',
                    ])
                    ->map(static fn (CallLog $call): array => [
                        'id' => $call->id,
                        'direction' => $call->direction->value,
                        'caller_number' => $call->caller_number,
                        'callee_number' => $call->callee_number,
                        'extension' => $call->extension,
                        'crm_entity_type' => $call->crm_entity_type,
                        'crm_entity_id' => $call->crm_entity_id,
                        'status_code' => $call->status_code,
                        'duration_sec' => $call->duration_sec,
                        'recording_status' => $call->recording_status,
                        'started_at' => $call->started_at?->toIso8601String(),
                    ]);
            }),
        ]);
    }
}
