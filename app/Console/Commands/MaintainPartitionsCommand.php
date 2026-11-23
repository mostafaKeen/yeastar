<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MaintainPartitionsCommand extends Command
{
    protected $signature = 'partitions:maintain {--months-ahead=3 : Number of future monthly partitions to ensure}';

    protected $description = 'Pre-create upcoming MySQL RANGE partitions and drop expired audit/webhook partitions.';

    /**
     * @var array<string, array{column: string, retention_days: int|null}>
     */
    private array $tables = [
        'call_logs' => ['column' => 'started_at', 'retention_days' => null],
        'webhook_deliveries' => ['column' => 'created_at', 'retention_days' => 14],
        'api_audit_logs' => ['column' => 'created_at', 'retention_days' => 14],
    ];

    public function handle(): int
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->info('Skipping MySQL partition maintenance: active database driver is '.DB::getDriverName().'.');

            return self::SUCCESS;
        }

        $monthsAhead = max(1, (int) $this->option('months-ahead'));
        $minAuditDays = max(3, (int) config('telephony.retention.api_audit_min_days', 3));
        $this->tables['api_audit_logs']['retention_days'] = max(
            $minAuditDays,
            (int) config('telephony.retention.api_audit_default_days', 14),
        );
        $this->tables['webhook_deliveries']['retention_days'] = max(
            $minAuditDays,
            (int) config('telephony.retention.webhook_deliveries_days', 14),
        );

        foreach ($this->tables as $table => $config) {
            $this->ensureFuturePartitions($table, $monthsAhead);

            if ($config['retention_days'] !== null) {
                $this->pruneExpiredPartitions($table, $config['retention_days']);
            }
        }

        $this->info('MySQL partition maintenance completed.');

        return self::SUCCESS;
    }

    private function ensureFuturePartitions(string $table, int $monthsAhead): void
    {
        $existing = collect(DB::select(
            'SELECT PARTITION_NAME FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL',
            [$table],
        ))->pluck('PARTITION_NAME')->all();

        $start = Carbon::now('UTC')->startOfMonth();
        $newClauses = [];

        for ($i = 0; $i <= $monthsAhead; $i++) {
            $month = $start->copy()->addMonths($i);
            $partitionName = 'p'.$month->format('Ym');

            if (! in_array($partitionName, $existing, true)) {
                $lessThan = $month->copy()->addMonth()->timestamp;
                $newClauses[] = "PARTITION {$partitionName} VALUES LESS THAN ({$lessThan})";
            }
        }

        if ($newClauses === []) {
            return;
        }

        $newClauses[] = 'PARTITION p_future VALUES LESS THAN MAXVALUE';
        $sql = sprintf(
            'ALTER TABLE `%s` REORGANIZE PARTITION p_future INTO (%s)',
            $table,
            implode(', ', $newClauses),
        );

        DB::statement($sql);
        $this->line("Added partitions to {$table}.");
    }

    private function pruneExpiredPartitions(string $table, int $retentionDays): void
    {
        $cutoff = Carbon::now('UTC')->subDays($retentionDays)->startOfMonth();

        $partitions = DB::select(
            'SELECT PARTITION_NAME, PARTITION_DESCRIPTION FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL AND PARTITION_NAME <> ?',
            [$table, 'p_future'],
        );

        foreach ($partitions as $partition) {
            if (! is_numeric($partition->PARTITION_DESCRIPTION)) {
                continue;
            }

            if ((int) $partition->PARTITION_DESCRIPTION <= $cutoff->timestamp) {
                DB::statement("ALTER TABLE `{$table}` DROP PARTITION `{$partition->PARTITION_NAME}`");
                $this->line("Dropped expired partition {$partition->PARTITION_NAME} from {$table}.");
            }
        }
    }
}
