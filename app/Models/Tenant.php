<?php

namespace App\Models;

use App\Enums\TenantRole;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'status',
        'plan_tier',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_user')
            ->using(TenantMembership::class)
            ->withPivot(['id', 'role', 'is_default'])
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function bitrixConnection(): HasOne
    {
        return $this->hasOne(BitrixConnection::class);
    }

    public function yeastarConnections(): HasMany
    {
        return $this->hasMany(YeastarConnection::class);
    }

    public function extensionMappings(): HasMany
    {
        return $this->hasMany(ExtensionMapping::class);
    }

    public function externalLines(): HasMany
    {
        return $this->hasMany(ExternalLine::class);
    }

    public function callLogs(): HasMany
    {
        return $this->hasMany(CallLog::class);
    }

    public function bitrixRateLimitPerSecond(): int
    {
        return $this->plan_tier === 'enterprise' ? 5 : 2;
    }

    public function bitrixBurstLimit(): int
    {
        return $this->plan_tier === 'enterprise' ? 250 : 50;
    }

    public function hasMember(User|int $user): bool
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $this->users()->where('users.id', $userId)->exists();
    }

    public function addMember(User $user, TenantRole $role = TenantRole::Agent, bool $isDefault = false): void
    {
        $this->users()->syncWithoutDetaching([
            $user->id => [
                'id' => (string) str()->ulid(),
                'role' => $role->value,
                'is_default' => $isDefault,
            ],
        ]);
    }
}
