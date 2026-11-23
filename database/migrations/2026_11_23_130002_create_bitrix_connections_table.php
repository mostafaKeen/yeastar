<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitrix_connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('member_id', 64)->unique();
            $table->string('domain', 255)->index();
            $table->string('portal_type', 24)->default('cloud');
            $table->string('client_endpoint', 512);
            $table->string('server_endpoint', 512)->default('https://oauth.bitrix.info/rest/');
            $table->text('access_token_enc')->nullable();
            $table->text('refresh_token_enc')->nullable();
            $table->text('application_token_enc')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('status', 24)->default('pending');
            $table->json('granted_scopes')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique('tenant_id', 'uq_b24_conn_tenant');
            $table->unique(['tenant_id', 'id'], 'uq_b24_conn_tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitrix_connections');
    }
};
