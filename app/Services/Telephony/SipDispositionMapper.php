<?php

namespace App\Services\Telephony;

class SipDispositionMapper
{
    /**
     * Map Yeastar P-Series / Asterisk call dispositions to official Bitrix24 telephony STATUS_CODE values.
     */
    public function toBitrixStatusCode(?string $disposition, int $durationSec = 0): string
    {
        $normalized = strtoupper(trim((string) $disposition));

        return match ($normalized) {
            'ANSWERED', 'CONNECTED', 'OK', '200' => '200',
            'NO ANSWER', 'NO_ANSWER', 'MISSED', 'UNANSWERED', '304' => '304',
            'BUSY', 'USER_BUSY', '486' => '486',
            'CANCEL', 'CANCELLED', '603-S' => '603-S',
            'REJECTED', 'DECLINED', 'DECLINE', '603' => '603',
            'UNAVAILABLE', 'CHANUNAVAIL', 'NO_USER_RESPONSE', '480' => '480',
            'CONGESTION', 'FAILED', 'TRUNK_FAILED', '503' => '503',
            'INVALID', 'ADDRESS_INCOMPLETE', '484' => '484',
            'NOT_FOUND', 'UNALLOCATED', '404' => '404',
            'FORBIDDEN', '403' => '403',
            default => $durationSec > 0 ? '200' : '304',
        };
    }
}
