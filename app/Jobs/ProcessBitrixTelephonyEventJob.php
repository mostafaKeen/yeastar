<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\Bitrix24\BitrixApiException;
use App\Services\Telephony\TelephonyOrchestrator;
use App\Tenancy\Jobs\Middleware\TenantAwareJobMiddleware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessBitrixTelephonyEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $eventName,
        public readonly array $data,
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
            if ($this->eventName === 'ONEXTERNALCALLSTART') {
                $orchestrator->handleOutboundClickToCall($this->data);
            } elseif ($this->eventName === 'ONEXTERNALCALLBACKSTART') {
                $orchestrator->handleCallbackRequest($this->data);
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
