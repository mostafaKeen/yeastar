<?php

namespace App\Services\Bitrix24;

use RuntimeException;

class BitrixApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 500,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?int $operatingResetAt = null,
    ) {
        parent::__construct($message, $httpStatus);
    }

    public function isExpiredToken(): bool
    {
        return strtolower($this->errorCode) === 'expired_token';
    }

    public function isRateLimited(): bool
    {
        return in_array(strtoupper($this->errorCode), [
            'QUERY_LIMIT_EXCEEDED',
            'OPERATION_TIME_LIMIT',
            'BITRIX_REST_V3_EXCEPTION_LIMITER_QUERYTIMELIMITEXCEPTION',
        ], true);
    }

    public function isRetryable(): bool
    {
        return $this->isRateLimited()
            || in_array(strtoupper($this->errorCode), [
                'INTERNAL_SERVER_ERROR',
                'ERROR_UNEXPECTED_ANSWER',
            ], true)
            || $this->httpStatus >= 500;
    }

    public function recommendedDelaySeconds(): int
    {
        if ($this->operatingResetAt !== null && $this->operatingResetAt > time()) {
            return max(1, $this->operatingResetAt - time());
        }

        if ($this->retryAfterSeconds !== null && $this->retryAfterSeconds > 0) {
            return $this->retryAfterSeconds;
        }

        return 5;
    }
}
