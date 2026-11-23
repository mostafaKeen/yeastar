<?php

namespace App\Http\Controllers;

use App\Models\BitrixConnection;
use App\Models\CallLog;
use App\Models\ExtensionMapping;
use App\Services\Telephony\RecordingStreamService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CallLogController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RecordingStreamService $recordingStreamer,
    ) {}

    public function index(Request $request): Response
    {
        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 403);

        $direction = trim((string) $request->query('direction', ''));
        $status = trim((string) $request->query('status', ''));
        $search = trim((string) $request->query('search', ''));

        $query = CallLog::query()
            ->with('yeastarConnection:id,name')
            ->orderByDesc('started_at')
            ->orderByDesc('id');

        // RBAC enforcement: Agent role sees only calls associated with their own mapped extension(s)
        if (! $request->user()->can('viewAllCalls', $tenant)) {
            $ownExtensions = ExtensionMapping::query()
                ->whereRaw('LOWER(bitrix_user_email) = ?', [strtolower($request->user()->email)])
                ->pluck('yeastar_extension')
                ->all();

            if ($ownExtensions === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('extension', $ownExtensions);
            }
        }

        if ($direction !== '' && in_array($direction, ['inbound', 'outbound', 'internal', 'callback'], true)) {
            $query->where('direction', $direction);
        }

        if ($status === 'answered') {
            $query->where('status_code', '200');
        } elseif ($status === 'missed') {
            $query->where('status_code', '304');
        } elseif ($status === 'failed') {
            $query->whereNotIn('status_code', ['200', '304']);
        }

        if ($search !== '') {
            $query->where(static function ($sub) use ($search): void {
                $like = "%{$search}%";
                $sub->where('caller_number', 'like', $like)
                    ->orWhere('callee_number', 'like', $like)
                    ->orWhere('extension', 'like', $like)
                    ->orWhere('bitrix_call_id', 'like', $like)
                    ->orWhere('yeastar_call_id', 'like', $like);
            });
        }

        $paginator = $query->cursorPaginate(25)->withQueryString();
        $bitrixDomain = BitrixConnection::query()->value('domain');

        return Inertia::render('calls/index', [
            'calls' => [
                'data' => collect($paginator->items())->map(static fn (CallLog $call): array => [
                    'id' => $call->id,
                    'yeastar_connection_name' => $call->yeastarConnection?->name,
                    'yeastar_call_id' => $call->yeastar_call_id,
                    'bitrix_call_id' => $call->bitrix_call_id,
                    'direction' => $call->direction->value,
                    'caller_number' => $call->caller_number,
                    'callee_number' => $call->callee_number,
                    'extension' => $call->extension,
                    'bitrix_user_id' => $call->bitrix_user_id,
                    'crm_entity_type' => $call->crm_entity_type,
                    'crm_entity_id' => $call->crm_entity_id,
                    'crm_activity_id' => $call->crm_activity_id,
                    'status_code' => $call->status_code,
                    'duration_sec' => $call->duration_sec,
                    'talk_duration_sec' => $call->talk_duration_sec,
                    'recording_status' => $call->recording_status,
                    'has_recording' => ! empty($call->yeastar_recording_id),
                    'bitrix_file_id' => $call->bitrix_file_id,
                    'started_at' => $call->started_at?->toIso8601String(),
                    'answered_at' => $call->answered_at?->toIso8601String(),
                    'ended_at' => $call->ended_at?->toIso8601String(),
                ])->values(),
                'next_page_url' => $paginator->nextPageUrl(),
                'prev_page_url' => $paginator->previousPageUrl(),
            ],
            'filters' => [
                'direction' => $direction,
                'status' => $status,
                'search' => $search,
            ],
            'bitrixDomain' => $bitrixDomain,
            'canViewAllCalls' => $request->user()->can('viewAllCalls', $tenant),
        ]);
    }

    public function streamRecording(Request $request, string $callLogId): StreamedResponse
    {
        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 403);
        Gate::authorize('streamRecordings', $tenant);

        $callLog = CallLog::query()->where('id', $callLogId)->firstOrFail();

        return $this->recordingStreamer->streamPlaybackResponse(
            $callLog,
            $request->header('Range'),
        );
    }
}
