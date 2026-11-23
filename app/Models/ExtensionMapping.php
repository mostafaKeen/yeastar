<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtensionMapping extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id',
        'yeastar_connection_id',
        'bitrix_user_id',
        'bitrix_user_name',
        'bitrix_user_email',
        'yeastar_extension',
        'yeastar_caller_name',
        'is_active',
        'click_to_call_enabled',
        'popup_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'click_to_call_enabled' => 'boolean',
            'popup_enabled' => 'boolean',
        ];
    }

    public function yeastarConnection(): BelongsTo
    {
        return $this->belongsTo(YeastarConnection::class);
    }
}
