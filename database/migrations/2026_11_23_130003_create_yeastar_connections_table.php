<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('yeastar_connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('transport_mode', 24)->default('direct_https');
            $table->string('pbx_base_url', 512)->nullable();
            $table->string('firmware_version', 40)->nullable();
            $table->string('client_id', 191)->nullable();
            $table->text('client_secret_enc')->nullable();
            $table->text('access_token_enc')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->ulid('webhook_ulid')->unique();
            $table->text('webhook_secret_enc')->nullable();
            $table->char('agent_token_hash', 64)->nullable()->unique();
            $table->string('agent_token_prefix', 16)->nullable();
            $table->string('agent_status', 24)->default('offline');
            $table->json('allowed_ips')->nullable();
            $table->string('status', 24)->default('pending');
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_ys_conn_tenant_id');
            $table->index(['tenant_id', 'status'], 'idx_ys_conn_tenant_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yeastar_connections');
    }
};
