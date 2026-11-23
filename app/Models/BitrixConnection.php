<?php

namespace App\Models;

use App\Enums\ConnectionStatus;
use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BitrixConnection extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id',
        'member_id',
        'domain',
        'portal_type',
        'client_endpoint',
        'server_endpoint',
        'access_token_enc',
        'refresh_token_enc',
        'application_token_enc',
        'token_expires_at',
        'status',
        'granted_scopes',
        'installed_at',
        'last_synced_at',
    ];

    protected $hidden = [
        'access_token_enc',
        'refresh_token_enc',
        'application_token_enc',
    ];

    protected function casts(): array
    {
        return [
            'access_token_enc' => 'encrypted',
            'refresh_token_enc' => 'encrypted',
            'application_token_enc' => 'encrypted',
            'token_expires_at' => 'datetime',
            'installed_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'granted_scopes' => 'array',
            'status' => ConnectionStatus::class,
        ];
    }

    public function verifyApplicationToken(?string $candidateToken): bool
    {
        $stored = $this->application_token_enc;

        if (! is_string($stored) || $stored === '' || ! is_string($candidateToken) || $candidateToken === '') {
            return false;
        }

        return hash_equals($stored, $candidateToken);
    }

    public function needsTokenRefresh(int $bufferSeconds = 600): bool
    {
        if ($this->token_expires_at === null) {
            return true;
        }

        return $this->token_expires_at->subSeconds($bufferSeconds)->isPast();
    }
}
