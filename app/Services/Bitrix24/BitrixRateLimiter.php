<?php

namespace App\Services\Bitrix24;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

class BitrixRateLimiter
{
    private const OPERATING_SOFT_LIMIT_SECONDS = 330.0;

    /**
     * Enforce per-tenant leaky bucket limits and Bitrix24 operating-time headroom.
     *
     * @throws BitrixApiException
     */
    public function acquire(Tenant $tenant, string $method, bool $highPriority = true): void
    {
        $this->checkOperatingBudget($tenant, $method, $highPriority);

        $windowKey = "t:{$tenant->id}:b24:rl:".time();
        $maxPerSecond = $tenant->bitrixRateLimitPerSecond();

        $current = (int) Cache::get($windowKey, 0);
        if ($current >= $maxPerSecond) {
            throw new BitrixApiException(
                errorCode: 'QUERY_LIMIT_EXCEEDED',
                message: 'Per-tenant Bitrix24 local rate limit reached; retry in 1 second.',
                httpStatus: 503,
                retryAfterSeconds: 1,
            );
        }

        Cache::put($windowKey, $current + 1, now()->addSeconds(2));
    }

    /**
     * Record Bitrix24's response time.operating telemetry to protect real-time calls.
     *
     * @param  array<string, mixed>  $timeBlock
     */
    public function recordOperatingTelemetry(Tenant $tenant, string $method, array $timeBlock): void
    {
        if (! isset($timeBlock['operating'])) {
            return;
        }

        $operating = (float) $timeBlock['operating'];
        $resetAt = isset($timeBlock['operating_reset_at']) ? (int) $timeBlock['operating_reset_at'] : (time() + 600);
        $ttl = max(10, $resetAt - time());

        Cache::put(
            $this->operatingKey($tenant->id, $method),
            [
                'operating' => $operating,
                'operating_reset_at' => $resetAt,
            ],
            now()->addSeconds($ttl),
        );
    }

    /**
     * @return array{operating: float, operating_reset_at: int}|null
     */
    public function getOperatingTelemetry(string $tenantId, string $method): ?array
    {
        $value = Cache::get($this->operatingKey($tenantId, $method));

        return is_array($value) ? $value : null;
    }

    private function checkOperatingBudget(Tenant $tenant, string $method, bool $highPriority): void
    {
        if ($highPriority) {
            return;
        }

        $telemetry = $this->getOperatingTelemetry($tenant->id, $method);
        if ($telemetry === null) {
            return;
        }

        if ($telemetry['operating'] >= self::OPERATING_SOFT_LIMIT_SECONDS && $telemetry['operating_reset_at'] > time()) {
            throw new BitrixApiException(
                errorCode: 'OPERATION_TIME_LIMIT',
                message: 'Deferred background Bitrix24 call to preserve operating budget for real-time telephony.',
                httpStatus: 429,
                operatingResetAt: $telemetry['operating_reset_at'],
            );
        }
    }

    private function operatingKey(string $tenantId, string $method): string
    {
        return "t:{$tenantId}:b24:op:".strtolower($method);
    }
}
