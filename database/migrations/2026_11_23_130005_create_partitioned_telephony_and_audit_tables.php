<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_logs', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('tenant_id');
            $table->ulid('yeastar_connection_id');
            $table->string('yeastar_call_id', 96);
            $table->string('bitrix_call_id', 128)->nullable();
            $table->string('direction', 16)->default('inbound');
            $table->string('caller_number', 64);
            $table->string('callee_number', 64);
            $table->string('extension', 32)->nullable();
            $table->string('bitrix_user_id', 32)->nullable();
            $table->string('crm_entity_type', 24)->nullable();
            $table->string('crm_entity_id', 32)->nullable();
            $table->string('crm_activity_id', 32)->nullable();
            $table->string('status_code', 16)->default('OTHER');
            $table->unsignedInteger('duration_sec')->default(0);
            $table->unsignedInteger('talk_duration_sec')->default(0);
            $table->string('recording_status', 24)->default('none');
            $table->string('yeastar_recording_id', 191)->nullable();
            $table->string('bitrix_file_id', 64)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->primary(['id', 'started_at']);
            $table->index(['tenant_id', 'started_at', 'id'], 'idx_calls_tenant_cursor');
            $table->index(['tenant_id', 'yeastar_call_id'], 'idx_calls_tenant_yscall');
            $table->index(['tenant_id', 'bitrix_call_id'], 'idx_calls_tenant_b24call');
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('tenant_id')->nullable();
            $table->string('source', 24);
            $table->string('event_name', 80);
            $table->string('idempotency_key', 128)->nullable();
            $table->string('status', 24)->default('received');
            $table->unsignedSmallInteger('http_status')->default(200);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->json('sanitized_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('created_at');

            $table->primary(['id', 'created_at']);
            $table->index(['tenant_id', 'created_at', 'id'], 'idx_wh_tenant_cursor');
            $table->index(['tenant_id', 'idempotency_key'], 'idx_wh_tenant_idemp');
        });

        Schema::create('api_audit_logs', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('tenant_id')->nullable();
            $table->string('provider', 24);
            $table->string('method', 128);
            $table->unsignedSmallInteger('http_status');
            $table->string('error_code', 80)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->decimal('b24_operating_sec', 8, 3)->nullable();
            $table->json('request_summary')->nullable();
            $table->json('response_summary')->nullable();
            $table->timestamp('created_at');

            $table->primary(['id', 'created_at']);
            $table->index(['tenant_id', 'created_at', 'id'], 'idx_audit_tenant_cursor');
            $table->index(['tenant_id', 'provider', 'error_code'], 'idx_audit_tenant_err');
        });

        if (DB::getDriverName() === 'mysql') {
            $this->partitionMonthly('call_logs', 'started_at');
            $this->partitionMonthly('webhook_deliveries', 'created_at');
            $this->partitionMonthly('api_audit_logs', 'created_at');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('api_audit_logs');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('call_logs');
    }

    private function partitionMonthly(string $table, string $column): void
    {
        $start = Carbon::now('UTC')->startOfMonth();
        $clauses = [];

        for ($i = 0; $i < 4; $i++) {
            $month = $start->copy()->addMonths($i);
            $nextMonth = $month->copy()->addMonth();
            $partitionName = 'p'.$month->format('Ym');
            $lessThan = $nextMonth->timestamp;
            $clauses[] = "PARTITION {$partitionName} VALUES LESS THAN ({$lessThan})";
        }

        $clauses[] = 'PARTITION p_future VALUES LESS THAN MAXVALUE';
        $partitionSql = implode(",\n", $clauses);

        DB::statement("ALTER TABLE `{$table}` PARTITION BY RANGE (UNIX_TIMESTAMP(`{$column}`)) ({$partitionSql})");
    }
};
