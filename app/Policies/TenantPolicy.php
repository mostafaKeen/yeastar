<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

class TenantPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        return null;
    }

    public function view(User $user, Tenant $tenant): bool
    {
        return $user->belongsToTenant($tenant);
    }

    public function manageIntegrations(User $user, Tenant $tenant): bool
    {
        return $user->belongsToTenant($tenant);
    }

    public function manageSecurity(User $user, Tenant $tenant): bool
    {
        return $user->belongsToTenant($tenant);
    }

    public function viewAllCalls(User $user, Tenant $tenant): bool
    {
        return $user->roleFor($tenant)?->canViewAllCalls() ?? false;
    }

    public function streamRecordings(User $user, Tenant $tenant): bool
    {
        return $user->roleFor($tenant)?->canStreamRecordings() ?? false;
    }
}
