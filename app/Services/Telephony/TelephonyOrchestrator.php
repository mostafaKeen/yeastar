<?php

namespace App\Services\Telephony;

use App\Enums\CallDirection;
use App\Enums\ConnectionStatus;
use App\Jobs\AttachCallRecordingJob;
use App\Models\BitrixConnection;
use App\Models\CallLog;
use App\Models\Tenant;
use App\Models\YeastarConnection;
use App\Services\Bitrix24\BitrixClient;
use App\Services\Yeastar\YeastarClientFactory;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Throwable;

class TelephonyOrchestrator
{
    private const CALL_STATE_TTL_SECONDS = 7200;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ExtensionDirectoryCache $directoryCache,
        private readonly SipDispositionMapper $dispositionMapper,
        private readonly BitrixClient $bitrixClient,
        private readonly YeastarClientFactory $yeastarFactory,
    ) {}

    /**
     * Handle inbound call ringing pushed by Yeastar P-Series (Direct or Tunnel Agent).
     *
     * @param  array{
     *   yeastar_call_id: string,
     *   caller_number: string,
     *   callee_number: string,
     *   target_extensions: list<string>,
     *   line_number?: string|null
     * }  $event
     */
    public function handleInboundRinging(YeastarConnection $pbx, array $event): ?CallLog
    {
        $tenant = $this->tenantContext->tenant();
        $bitrix = BitrixConnection::query()->where('status', ConnectionStatus::Connected->value)->first();

        if ($tenant === null || $bitrix === null) {
            return null;
        }

        $ysCallId = $event['yeastar_call_id'];
        $lockKey = "t:{$tenant->id}:call_lock:{$ysCallId}";

        return Cache::lock($lockKey, 10)->block(5, function () use ($tenant, $bitrix, $pbx, $event, $ysCallId): ?CallLog {
            $existing = CallLog::query()->where('yeastar_call_id', $ysCallId)->first();
            if ($existing !== null && ! empty($existing->bitrix_call_id)) {
                return $existing;
            }

            $settings = is_array($tenant->settings) ? $tenant->settings : [];
            $popupsEnabled = (bool) ($settings['enable_call_popups'] ?? true);
            $autoCreateLead = (bool) ($settings['auto_create_crm_lead'] ?? false);

            // 1. Lookup CRM entity & responsible manager
            $crmEntityType = null;
            $crmEntityId = null;
            $assignedById = null;

            try {
                $entities = $this->bitrixClient->searchCrmEntities($bitrix, $event['caller_number']);
                if (! empty($entities[0]) && is_array($entities[0])) {
                    $crmEntityType = isset($entities[0]['CRM_ENTITY_TYPE']) ? (string) $entities[0]['CRM_ENTITY_TYPE'] : null;
                    $crmEntityId = isset($entities[0]['CRM_ENTITY_ID']) ? (string) $entities[0]['CRM_ENTITY_ID'] : null;
                    $assignedById = isset($entities[0]['ASSIGNED_BY_ID']) ? (int) $entities[0]['ASSIGNED_BY_ID'] : null;
                }
            } catch (Throwable) {
                // Continue registering call even if CRM lookup fails
            }

            // 2. Map ringing Yeastar target extensions to Bitrix24 user IDs
            $popupUserIds = [];
            $primaryUserId = null;
            $primaryExtension = $event['target_extensions'][0] ?? null;

            foreach ($event['target_extensions'] as $ext) {
                $mapped = $this->directoryCache->findByExtension($tenant->id, $ext);
                if ($mapped !== null) {
                    $uid = (int) $mapped['bitrix_user_id'];
                    $primaryUserId ??= $uid;
                    if ($popupsEnabled && $mapped['popup_enabled']) {
                        $popupUserIds[] = $uid;
                    }
                }
            }

            $registerUserId = $primaryUserId ?? $assignedById;
            if ($registerUserId === null) {
                return null;
            }

            $lineNumber = ! empty($event['line_number'])
                ? (string) $event['line_number']
                : $this->directoryCache->resolveDefaultLineNumber($tenant->id, $pbx->id);

            // 3. Register incoming call in Bitrix24 (SHOW=0 so we control group show/hide explicitly)
            $regResult = $this->bitrixClient->registerExternalCall($bitrix, [
                'USER_ID' => $registerUserId,
                'PHONE_NUMBER' => $event['caller_number'],
                'TYPE' => 2,
                'LINE_NUMBER' => $lineNumber,
                'EXTERNAL_CALL_ID' => $ysCallId,
                'CRM_CREATE' => $autoCreateLead ? 1 : 0,
                'SHOW' => 0,
            ]);

            $b24CallId = isset($regResult['CALL_ID']) ? (string) $regResult['CALL_ID'] : null;

            $callLog = CallLog::query()->create([
                'yeastar_connection_id' => $pbx->id,
                'yeastar_call_id' => $ysCallId,
                'bitrix_call_id' => $b24CallId,
                'direction' => CallDirection::Inbound,
                'caller_number' => $event['caller_number'],
                'callee_number' => $event['callee_number'],
                'extension' => $primaryExtension,
                'bitrix_user_id' => (string) $registerUserId,
                'crm_entity_type' => (string) ($regResult['CRM_ENTITY_TYPE'] ?? $crmEntityType ?? '') ?: null,
                'crm_entity_id' => isset($regResult['CRM_ENTITY_ID']) ? (string) $regResult['CRM_ENTITY_ID'] : $crmEntityId,
                'status_code' => '304',
                'duration_sec' => 0,
                'talk_duration_sec' => 0,
                'recording_status' => 'none',
                'started_at' => now(),
            ]);

            // 4. Show call card to all mapped ringing agents (simultaneous ring group UX)
            $popupUserIds = array_values(array_unique($popupUserIds));
            if ($b24CallId !== null && $popupUserIds !== []) {
                $this->bitrixClient->showExternalCall($bitrix, $b24CallId, $popupUserIds);

                Cache::put(
                    $this->callStateKey($tenant->id, $ysCallId),
                    [
                        'bitrix_call_id' => $b24CallId,
                        'ringing_user_ids' => $popupUserIds,
                    ],
                    now()->addSeconds(self::CALL_STATE_TTL_SECONDS),
                );
            }

            return $callLog;
        });
    }

    /**
     * Handle call answered on Yeastar: hide call card for all non-answering agents in a ring group.
     */
    public function handleCallAnswered(string $yeastarCallId, string $answeredExtension): ?CallLog
    {
        $tenant = $this->tenantContext->tenant();
        $bitrix = BitrixConnection::query()->where('status', ConnectionStatus::Connected->value)->first();

        if ($tenant === null) {
            return null;
        }

        $callLog = CallLog::query()->where('yeastar_call_id', $yeastarCallId)->first();
        $mapped = $this->directoryCache->findByExtension($tenant->id, $answeredExtension);
        $answeredUserId = $mapped !== null ? (int) $mapped['bitrix_user_id'] : null;

        if ($callLog !== null) {
            $callLog->update([
                'extension' => $answeredExtension,
                'bitrix_user_id' => $answeredUserId !== null ? (string) $answeredUserId : $callLog->bitrix_user_id,
                'status_code' => '200',
                'answered_at' => $callLog->answered_at ?? now(),
            ]);
        }

        $state = Cache::get($this->callStateKey($tenant->id, $yeastarCallId));
        if ($bitrix !== null && is_array($state) && ! empty($state['bitrix_call_id']) && $answeredUserId !== null) {
            $ringingIds = array_map('intval', (array) ($state['ringing_user_ids'] ?? []));
            $usersToHide = array_values(array_filter(
                $ringingIds,
                static fn (int $uid): bool => $uid !== $answeredUserId,
            ));

            if ($usersToHide !== []) {
                $this->bitrixClient->hideExternalCall($bitrix, (string) $state['bitrix_call_id'], $usersToHide);
            }
        }

        return $callLog?->fresh();
    }

    /**
     * Finish call in Bitrix24 CRM statistics & activity timeline when Yeastar reports hangup / CDR ready.
     *
     * @param  array{
     *   yeastar_call_id: string,
     *   disposition?: string|null,
     *   duration_sec?: int,
     *   talk_duration_sec?: int,
     *   extension?: string|null,
     *   recording_file?: string|null
     * }  $event
     */
    public function handleCallEnded(array $event): ?CallLog
    {
        $tenant = $this->tenantContext->tenant();
        $bitrix = BitrixConnection::query()->where('status', ConnectionStatus::Connected->value)->first();

        if ($tenant === null) {
            return null;
        }

        $ysCallId = $event['yeastar_call_id'];
        $callLog = CallLog::query()->where('yeastar_call_id', $ysCallId)->first();

        if ($callLog === null) {
            return null;
        }

        $duration = max(0, (int) ($event['duration_sec'] ?? 0));
        $talkDuration = max(0, (int) ($event['talk_duration_sec'] ?? $duration));
        $statusCode = $this->dispositionMapper->toBitrixStatusCode($event['disposition'] ?? null, $talkDuration);

        if (! empty($event['extension'])) {
            $mapped = $this->directoryCache->findByExtension($tenant->id, (string) $event['extension']);
            if ($mapped !== null) {
                $callLog->bitrix_user_id = $mapped['bitrix_user_id'];
                $callLog->extension = (string) $event['extension'];
            }
        }

        $crmActivityId = $callLog->crm_activity_id;

        if ($bitrix !== null && ! empty($callLog->bitrix_call_id) && ! empty($callLog->bitrix_user_id)) {
            $finishResult = $this->bitrixClient->finishExternalCall($bitrix, [
                'CALL_ID' => $callLog->bitrix_call_id,
                'USER_ID' => (int) $callLog->bitrix_user_id,
                'DURATION' => $talkDuration,
                'STATUS_CODE' => $statusCode,
                'ADD_TO_CHAT' => 0,
            ]);

            if (isset($finishResult['CRM_ACTIVITY_ID'])) {
                $crmActivityId = (string) $finishResult['CRM_ACTIVITY_ID'];
            }
        }

        $recordingFile = ! empty($event['recording_file']) ? (string) $event['recording_file'] : null;
        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $recordingEnabled = (bool) ($settings['enable_recording_stream'] ?? true);

        $shouldStreamRecording = $recordingFile !== null && $recordingEnabled && ! empty($callLog->bitrix_call_id);

        $callLog->update([
            'extension' => $callLog->extension,
            'bitrix_user_id' => $callLog->bitrix_user_id,
            'crm_activity_id' => $crmActivityId,
            'status_code' => $statusCode,
            'duration_sec' => $duration,
            'talk_duration_sec' => $talkDuration,
            'yeastar_recording_id' => $recordingFile ?? $callLog->yeastar_recording_id,
            'recording_status' => $shouldStreamRecording ? 'pending' : $callLog->recording_status,
            'ended_at' => now(),
        ]);

        Cache::forget($this->callStateKey($tenant->id, $ysCallId));

        if ($shouldStreamRecording) {
            AttachCallRecordingJob::dispatch(
                tenantId: $tenant->id,
                callLogId: $callLog->id,
            );
        }

        return $callLog->fresh();
    }

    /**
     * Handle Bitrix24 Click-to-Call (ONEXTERNALCALLSTART).
     * Per Bitrix24 specification: CALL_ID is already created by Bitrix24 — never call register again!
     *
     * @param  array<string, mixed>  $data
     */
    public function handleOutboundClickToCall(array $data): ?CallLog
    {
        $tenant = $this->tenantContext->tenant();
        if ($tenant === null) {
            return null;
        }

        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        if (($settings['enable_click_to_call'] ?? true) === false) {
            return null;
        }

        $b24CallId = trim((string) ($data['CALL_ID'] ?? ''));
        $phoneNumber = trim((string) ($data['PHONE_NUMBER'] ?? ''));
        $b24UserId = trim((string) ($data['USER_ID'] ?? ''));

        if ($b24CallId === '' || $phoneNumber === '' || $b24UserId === '') {
            return null;
        }

        $mapped = $this->directoryCache->findByBitrixUser($tenant->id, $b24UserId);
        if ($mapped === null || ! $mapped['click_to_call_enabled']) {
            return null;
        }

        $pbx = YeastarConnection::query()->find($mapped['yeastar_connection_id']);
        if ($pbx === null) {
            return null;
        }

        $dialResponse = $this->yeastarFactory->for($pbx)->dial($pbx, $mapped['yeastar_extension'], $phoneNumber);
        $ysCallId = (string) ($dialResponse['call_id'] ?? $dialResponse['data']['call_id'] ?? "outbound-{$b24CallId}");

        return CallLog::query()->create([
            'yeastar_connection_id' => $pbx->id,
            'yeastar_call_id' => $ysCallId,
            'bitrix_call_id' => $b24CallId,
            'direction' => CallDirection::Outbound,
            'caller_number' => $mapped['yeastar_extension'],
            'callee_number' => $phoneNumber,
            'extension' => $mapped['yeastar_extension'],
            'bitrix_user_id' => $b24UserId,
            'crm_entity_type' => isset($data['CRM_ENTITY_TYPE']) ? (string) $data['CRM_ENTITY_TYPE'] : null,
            'crm_entity_id' => isset($data['CRM_ENTITY_ID']) ? (string) $data['CRM_ENTITY_ID'] : null,
            'status_code' => '304',
            'duration_sec' => 0,
            'talk_duration_sec' => 0,
            'recording_status' => 'none',
            'started_at' => now(),
        ]);
    }

    /**
     * Handle Bitrix24 CRM Callback form request (ONEXTERNALCALLBACKSTART).
     *
     * @param  array<string, mixed>  $data
     */
    public function handleCallbackRequest(array $data): ?CallLog
    {
        $tenant = $this->tenantContext->tenant();
        $bitrix = BitrixConnection::query()->where('status', ConnectionStatus::Connected->value)->first();

        if ($tenant === null || $bitrix === null) {
            return null;
        }

        $phoneNumber = trim((string) ($data['PHONE_NUMBER'] ?? ''));
        if ($phoneNumber === '') {
            return null;
        }

        // Resolve first active click-to-call extension (or responsible CRM owner if mapped)
        $map = $this->directoryCache->getMap($tenant->id);
        $targetUserId = null;
        $targetMapping = null;

        try {
            $entities = $this->bitrixClient->searchCrmEntities($bitrix, $phoneNumber);
            if (! empty($entities[0]['ASSIGNED_BY_ID'])) {
                $candidateId = (string) $entities[0]['ASSIGNED_BY_ID'];
                if (isset($map['by_bitrix_user'][$candidateId])) {
                    $targetUserId = $candidateId;
                    $targetMapping = $map['by_bitrix_user'][$candidateId];
                }
            }
        } catch (Throwable) {
            // Fall back to first active mapped agent
        }

        if ($targetMapping === null) {
            foreach ($map['by_bitrix_user'] as $b24UserId => $entry) {
                if ($entry['click_to_call_enabled']) {
                    $targetUserId = (string) $b24UserId;
                    $targetMapping = $entry;
                    break;
                }
            }
        }

        if ($targetMapping === null || $targetUserId === null) {
            return null;
        }

        $pbx = YeastarConnection::query()->find($targetMapping['yeastar_connection_id']);
        if ($pbx === null) {
            return null;
        }

        $dialResponse = $this->yeastarFactory->for($pbx)->dial($pbx, $targetMapping['yeastar_extension'], $phoneNumber);
        $ysCallId = (string) ($dialResponse['call_id'] ?? $dialResponse['data']['call_id'] ?? 'callback-'.uniqid());

        $lineNumber = ! empty($data['LINE_NUMBER'])
            ? (string) $data['LINE_NUMBER']
            : $this->directoryCache->resolveDefaultLineNumber($tenant->id, $pbx->id);

        $regResult = $this->bitrixClient->registerExternalCall($bitrix, [
            'USER_ID' => (int) $targetUserId,
            'PHONE_NUMBER' => $phoneNumber,
            'TYPE' => 4, // Callback call in Bitrix24 Telephony API
            'LINE_NUMBER' => $lineNumber,
            'EXTERNAL_CALL_ID' => $ysCallId,
            'SHOW' => 1,
        ]);

        return CallLog::query()->create([
            'yeastar_connection_id' => $pbx->id,
            'yeastar_call_id' => $ysCallId,
            'bitrix_call_id' => isset($regResult['CALL_ID']) ? (string) $regResult['CALL_ID'] : null,
            'direction' => CallDirection::Callback,
            'caller_number' => $targetMapping['yeastar_extension'],
            'callee_number' => $phoneNumber,
            'extension' => $targetMapping['yeastar_extension'],
            'bitrix_user_id' => $targetUserId,
            'crm_entity_type' => isset($data['CRM_ENTITY_TYPE']) ? (string) $data['CRM_ENTITY_TYPE'] : null,
            'crm_entity_id' => isset($data['CRM_ENTITY_ID']) ? (string) $data['CRM_ENTITY_ID'] : null,
            'status_code' => '304',
            'duration_sec' => 0,
            'talk_duration_sec' => 0,
            'recording_status' => 'none',
            'started_at' => now(),
        ]);
    }

    private function callStateKey(string $tenantId, string $yeastarCallId): string
    {
        return "t:{$tenantId}:call_state:{$yeastarCallId}";
    }
}
