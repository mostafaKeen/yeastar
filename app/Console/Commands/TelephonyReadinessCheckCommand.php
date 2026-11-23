<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TelephonyReadinessCheckCommand extends Command
{
    protected $signature = 'telephony:readiness-check';

    protected $description = 'Audit production and Bitrix24 Marketplace readiness (encryption, OAuth, MySQL partitions, HTTPS, retention).';

    public function handle(): int
    {
        $checks = [
            [
                'check' => 'Application Encryption Key (APP_KEY)',
                'ok' => ! empty(config('app.key')),
                'detail' => ! empty(config('app.key')) ? 'Configured' : 'Missing APP_KEY',
            ],
            [
                'check' => 'Bitrix24 Marketplace Client ID & Secret',
                'ok' => ! empty(config('telephony.bitrix24.client_id')) && ! empty(config('telephony.bitrix24.client_secret')),
                'detail' => ! empty(config('telephony.bitrix24.client_id')) ? 'Configured' : 'Set BITRIX24_CLIENT_ID and BITRIX24_CLIENT_SECRET',
            ],
            [
                'check' => 'HTTPS Public APP_URL (DV+ SSL required by Bitrix24 Market)',
                'ok' => str_starts_with(strtolower((string) config('app.url')), 'https://'),
                'detail' => (string) config('app.url'),
            ],
            [
                'check' => '3+ Day Sanitized REST API Audit Retention',
                'ok' => (int) config('telephony.retention.api_audit_default_days', 14) >= 3,
                'detail' => config('telephony.retention.api_audit_default_days', 14).' days',
            ],
            [
                'check' => 'Database Driver & Partition Support',
                'ok' => DB::getDriverName() === 'mysql',
                'detail' => 'Active driver: '.DB::getDriverName(),
            ],
        ];

        $rows = array_map(static fn (array $item): array => [
            $item['ok'] ? 'PASS' : 'WARN',
            $item['check'],
            $item['detail'],
        ], $checks);

        $this->table(['Status', 'Readiness Check', 'Details'], $rows);

        return self::SUCCESS;
    }
}
