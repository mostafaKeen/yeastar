<?php

namespace App\Jobs;

use App\Models\CallLog;
use App\Services\Bitrix24\BitrixApiException;
use App\Services\Telephony\RecordingStreamService;
use App\Tenancy\Jobs\Middleware\TenantAwareJobMiddleware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class AttachCallRecordingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $callLogId,
    ) {
        $this->onQueue('telephony-media');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new TenantAwareJobMiddleware];
    }

    public function handle(RecordingStreamService $streamer): void
    {
        $callLog = CallLog::query()->where('id', $this->callLogId)->first();

        if ($callLog === null || $callLog->recording_status === 'attached') {
            return;
        }

        try {
            $streamer->transferToBitrix($callLog);
        } catch (BitrixApiException $e) {
            if ($e->isRetryable() && $this->attempts() < $this->tries) {
                $this->release($e->recommendedDelaySeconds());

                return;
            }

            $callLog->update(['recording_status' => 'failed']);
            throw $e;
        } catch (Throwable $e) {
            $callLog->update(['recording_status' => 'failed']);
            throw $e;
        }
    }
}
