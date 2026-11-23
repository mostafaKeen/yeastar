<?php

namespace App\Services\Yeastar;

use RuntimeException;

class YeastarApiException extends RuntimeException
{
    public function __construct(
        public readonly int|string $errCode,
        string $message,
        public readonly int $httpStatus = 500,
    ) {
        parent::__construct($message, $httpStatus);
    }

    public function isTokenExpired(): bool
    {
        return in_array((int) $this->errCode, [10004, 10005, 10006], true) || $this->httpStatus === 401;
    }
}
