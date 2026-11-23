<?php

namespace App\Security;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class SecretRedactor implements ProcessorInterface
{
    /**
     * @var list<string>
     */
    private const SENSITIVE_FRAGMENTS = [
        'access_token',
        'refresh_token',
        'application_token',
        'client_secret',
        'webhook_secret',
        'agent_token',
        'authorization',
        'password',
        'secret',
        'api_key',
        'token_enc',
    ];

    public const MASK = '[REDACTED]';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: self::redactArray($record->context),
            extra: self::redactArray($record->extra),
        );
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function redactArray(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $sanitized[$key] = self::MASK;

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = self::redactArray($value);
            } elseif (is_string($value)) {
                $sanitized[$key] = self::redactInlineSecrets($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    public static function maskFingerprint(?string $secret, int $visibleTail = 4): ?string
    {
        if ($secret === null || $secret === '') {
            return null;
        }

        $length = strlen($secret);
        if ($length <= $visibleTail) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', min(12, $length - $visibleTail)).substr($secret, -$visibleTail);
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private static function redactInlineSecrets(string $value): string
    {
        return (string) preg_replace(
            '/(Bearer\s+)[A-Za-z0-9\-._~+\/=]+/i',
            '$1'.self::MASK,
            $value,
        );
    }
}
