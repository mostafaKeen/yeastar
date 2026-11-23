<?php

namespace App\Models;

use App\Enums\TenantRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_super_admin',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_user')
            ->using(TenantMembership::class)
            ->withPivot(['id', 'role', 'is_default'])
            ->withTimestamps();
    }

    public function roleFor(Tenant|string $tenant): ?TenantRole
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;

        $membership = $this->tenants()
            ->where('tenants.id', $tenantId)
            ->first();

        if ($membership === null) {
            return null;
        }

        $role = $membership->pivot->role;

        return $role instanceof TenantRole ? $role : TenantRole::tryFrom((string) $role);
    }

    public function belongsToTenant(Tenant|string $tenant): bool
    {
        return $this->roleFor($tenant) !== null;
    }
}
