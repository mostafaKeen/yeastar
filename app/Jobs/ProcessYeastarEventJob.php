<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\YeastarConnection;
use App\Services\Bitrix24\BitrixApiException;
use App\Services\Telephony\TelephonyOrchestrator;
use App\Tenancy\Jobs\Middleware\TenantAwareJobMiddleware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessYeastarEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $yeastarConnectionId,
        public readonly array $payload,
        public readonly ?string $deliveryId = null,
    ) {
        $this->onQueue('telephony-realtime');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new TenantAwareJobMiddleware];
    }

    public function handle(TelephonyOrchestrator $orchestrator): void
    {
        try {
            $pbx = YeastarConnection::query()->find($this->yeastarConnectionId);
            if ($pbx === null) {
                $this->markDelivery('failed', 'Yeastar connection not found for tenant.');

                return;
            }

            $eventType = strtolower((string) ($this->payload['event'] ?? $this->payload['status'] ?? $this->payload['type'] ?? ''));
            $callId = (string) ($this->payload['call_id'] ?? $this->payload['uid'] ?? $this->payload['msg']['call_id'] ?? '');

            if ($callId === '') {
                $this->markDelivery('processed');

                return;
            }

            if (in_array($eventType, ['ringing', 'alert', 'inbound_ringing', '30011_ring'], true)) {
                $targets = $this->extractTargetExtensions($this->payload);

                $orchestrator->handleInboundRinging($pbx, [
                    'yeastar_call_id' => $callId,
                    'caller_number' => (string) ($this->payload['caller_number'] ?? $this->payload['call_from'] ?? 'unknown'),
                    'callee_number' => (string) ($this->payload['callee_number'] ?? $this->payload['call_to'] ?? ($targets[0] ?? 'unknown')),
                    'target_extensions' => $targets,
                    'line_number' => isset($this->payload['line_number']) ? (string) $this->payload['line_number'] : null,
                ]);
            } elseif (in_array($eventType, ['answered', 'answer', 'connected', '30011_answer'], true)) {
                $extension = (string) ($this->payload['extension'] ?? $this->payload['answered_by'] ?? '');
                if ($extension !== '') {
                    $orchestrator->handleCallAnswered($callId, $extension);
                }
            } elseif (in_array($eventType, ['hangup', 'ended', 'cdr', '30012'], true)) {
                $orchestrator->handleCallEnded([
                    'yeastar_call_id' => $callId,
                    'disposition' => isset($this->payload['disposition']) ? (string) $this->payload['disposition'] : null,
                    'duration_sec' => (int) ($this->payload['duration'] ?? $this->payload['duration_sec'] ?? 0),
                    'talk_duration_sec' => (int) ($this->payload['talk_duration'] ?? $this->payload['talk_duration_sec'] ?? $this->payload['duration'] ?? 0),
                    'extension' => isset($this->payload['extension']) ? (string) $this->payload['extension'] : null,
                    'recording_file' => isset($this->payload['record_file'])
                        ? (string) $this->payload['record_file']
                        : (isset($this->payload['recording_file']) ? (string) $this->payload['recording_file'] : null),
                ]);
            }

            $this->markDelivery('processed');
        } catch (BitrixApiException $e) {
            if ($e->isRetryable() && $this->attempts() < $this->tries) {
                $this->release($e->recommendedDelaySeconds());

                return;
            }

            $this->markDelivery('failed', "{$e->errorCode}: {$e->getMessage()}");
            throw $e;
        } catch (Throwable $e) {
            $this->markDelivery('failed', $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function extractTargetExtensions(array $payload): array
    {
        if (is_array($payload['target_extensions'] ?? null)) {
            return array_values(array_filter(array_map('strval', $payload['target_extensions'])));
        }

        if (is_array($payload['extensions'] ?? null)) {
            return array_values(array_filter(array_map('strval', $payload['extensions'])));
        }

        $single = (string) ($payload['extension'] ?? $payload['call_to'] ?? '');

        return $single !== '' ? [$single] : [];
    }

    private function markDelivery(string $status, ?string $error = null): void
    {
        if ($this->deliveryId === null) {
            return;
        }

        WebhookDelivery::query()
            ->where('id', $this->deliveryId)
            ->update([
                'status' => $status,
                'error_message' => $error,
            ]);
    }
}
