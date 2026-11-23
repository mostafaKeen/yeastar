<?php

namespace App\Jobs;

use App\Models\YeastarConnection;
use App\Services\Bitrix24\BitrixApiException;
use App\Services\Telephony\PhonebookSyncService;
use App\Tenancy\Jobs\Middleware\TenantAwareJobMiddleware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncCrmPhonebookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $yeastarConnectionId,
        public readonly int $lastContactId = 0,
    ) {
        $this->onQueue('crm-sync');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new TenantAwareJobMiddleware];
    }

    public function handle(PhonebookSyncService $syncService): void
    {
        $pbx = YeastarConnection::query()->find($this->yeastarConnectionId);
        if ($pbx === null) {
            return;
        }

        try {
            $result = $syncService->syncBatch($pbx, $this->lastContactId, maxPages: 4);

            if ($result['has_more'] && $result['last_id'] > $this->lastContactId) {
                self::dispatch(
                    tenantId: $this->tenantId,
                    yeastarConnectionId: $this->yeastarConnectionId,
                    lastContactId: $result['last_id'],
                )->delay(now()->addSeconds(2));
            }
        } catch (BitrixApiException $e) {
            if ($e->isRetryable() && $this->attempts() < $this->tries) {
                $this->release($e->recommendedDelaySeconds());

                return;
            }

            throw $e;
        }
    }
}
