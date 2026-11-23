<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ApiAuditLog extends Model
{
    use BelongsToTenant, HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'provider',
        'method',
        'http_status',
        'error_code',
        'duration_ms',
        'b24_operating_sec',
        'request_summary',
        'response_summary',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'duration_ms' => 'integer',
            'b24_operating_sec' => 'float',
            'request_summary' => 'array',
            'response_summary' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
