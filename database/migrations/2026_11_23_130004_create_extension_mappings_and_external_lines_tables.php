<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extension_mappings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('yeastar_connection_id');
            $table->string('bitrix_user_id', 32);
            $table->string('bitrix_user_name', 160)->nullable();
            $table->string('bitrix_user_email', 191)->nullable();
            $table->string('yeastar_extension', 32);
            $table->string('yeastar_caller_name', 160)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('click_to_call_enabled')->default(true);
            $table->boolean('popup_enabled')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'yeastar_connection_id', 'yeastar_extension'], 'uq_ext_map_tenant_conn_ext');
            $table->index(['tenant_id', 'bitrix_user_id'], 'idx_ext_map_tenant_b24user');
            $table->foreign(['tenant_id', 'yeastar_connection_id'], 'fk_ext_map_tenant_ys')
                ->references(['tenant_id', 'id'])
                ->on('yeastar_connections')
                ->cascadeOnDelete();
        });

        Schema::create('external_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('yeastar_connection_id');
            $table->string('bitrix_line_number', 64);
            $table->string('bitrix_line_name', 120);
            $table->string('yeastar_trunk_name', 120)->nullable();
            $table->string('did_number', 64)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'bitrix_line_number'], 'uq_ext_lines_tenant_number');
            $table->foreign(['tenant_id', 'yeastar_connection_id'], 'fk_ext_lines_tenant_ys')
                ->references(['tenant_id', 'id'])
                ->on('yeastar_connections')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_lines');
        Schema::dropIfExists('extension_mappings');
    }
};
