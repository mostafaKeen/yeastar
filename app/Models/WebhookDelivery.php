<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WebhookDelivery extends Model
{
    use BelongsToTenant, HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'source',
        'event_name',
        'idempotency_key',
        'status',
        'http_status',
        'latency_ms',
        'sanitized_payload',
        'error_message',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'latency_ms' => 'integer',
            'sanitized_payload' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
