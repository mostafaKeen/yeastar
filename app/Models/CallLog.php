<?php

namespace App\Models;

use App\Enums\CallDirection;
use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallLog extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id',
        'yeastar_connection_id',
        'yeastar_call_id',
        'bitrix_call_id',
        'direction',
        'caller_number',
        'callee_number',
        'extension',
        'bitrix_user_id',
        'crm_entity_type',
        'crm_entity_id',
        'crm_activity_id',
        'status_code',
        'duration_sec',
        'talk_duration_sec',
        'recording_status',
        'yeastar_recording_id',
        'bitrix_file_id',
        'started_at',
        'answered_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'direction' => CallDirection::class,
            'duration_sec' => 'integer',
            'talk_duration_sec' => 'integer',
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function yeastarConnection(): BelongsTo
    {
        return $this->belongsTo(YeastarConnection::class);
    }
}
