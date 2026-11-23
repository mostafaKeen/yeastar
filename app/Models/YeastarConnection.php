<?php

namespace App\Models;

use App\Enums\ConnectionStatus;
use App\Enums\YeastarTransportMode;
use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class YeastarConnection extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id',
        'name',
        'transport_mode',
        'pbx_base_url',
        'firmware_version',
        'client_id',
        'client_secret_enc',
        'access_token_enc',
        'token_expires_at',
        'webhook_ulid',
        'webhook_secret_enc',
        'agent_token_hash',
        'agent_token_prefix',
        'agent_status',
        'allowed_ips',
        'status',
        'last_heartbeat_at',
    ];

    protected $hidden = [
        'client_secret_enc',
        'access_token_enc',
        'webhook_secret_enc',
        'agent_token_hash',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $connection): void {
            if (empty($connection->webhook_ulid)) {
                $connection->webhook_ulid = (string) Str::ulid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'transport_mode' => YeastarTransportMode::class,
            'status' => ConnectionStatus::class,
            'client_secret_enc' => 'encrypted',
            'access_token_enc' => 'encrypted',
            'webhook_secret_enc' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'allowed_ips' => 'array',
        ];
    }

    public function extensionMappings(): HasMany
    {
        return $this->hasMany(ExtensionMapping::class);
    }

    public function externalLines(): HasMany
    {
        return $this->hasMany(ExternalLine::class);
    }

    /**
     * Rotate and return a new plaintext On-Premise Tunnel Agent token (shown once).
     */
    public function rotateAgentToken(): string
    {
        $plaintext = 'ysa_'.bin2hex(random_bytes(32));
        $this->agent_token_hash = hash('sha256', $plaintext);
        $this->agent_token_prefix = substr($plaintext, 0, 12);
        $this->save();

        return $plaintext;
    }

    public function verifyAgentToken(string $plaintext): bool
    {
        if (empty($this->agent_token_hash) || $plaintext === '') {
            return false;
        }

        return hash_equals($this->agent_token_hash, hash('sha256', $plaintext));
    }

    public function needsTokenRefresh(int $bufferSeconds = 300): bool
    {
        if ($this->token_expires_at === null) {
            return true;
        }

        return $this->token_expires_at->subSeconds($bufferSeconds)->isPast();
    }
}
