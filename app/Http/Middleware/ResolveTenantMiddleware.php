<?php

namespace App\Http\Middleware;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantMiddleware
{
    public const SESSION_KEY = 'active_tenant_id';

    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            $this->tenantContext->clear();

            return $next($request);
        }

        $tenant = $this->resolveForUser($request, $user);

        if ($tenant !== null) {
            $this->tenantContext->set($tenant);
            $request->session()->put(self::SESSION_KEY, $tenant->id);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->tenantContext->clear();
    }

    private function resolveForUser(Request $request, User $user): ?Tenant
    {
        $sessionTenantId = $request->session()->get(self::SESSION_KEY);

        if (is_string($sessionTenantId) && $sessionTenantId !== '') {
            $tenant = $user->tenants()
                ->where('tenants.id', $sessionTenantId)
                ->where('tenants.status', 'active')
                ->first();

            if ($tenant !== null) {
                return $tenant;
            }
        }

        $defaultTenant = $user->tenants()
            ->where('tenants.status', 'active')
            ->orderByDesc('tenant_user.is_default')
            ->orderBy('tenants.created_at')
            ->first();

        if ($defaultTenant !== null) {
            return $defaultTenant;
        }

        return $this->provisionInitialTenant($user);
    }

    private function provisionInitialTenant(User $user): Tenant
    {
        $baseSlug = Str::slug($user->name.'-workspace') ?: 'workspace';
        $slug = $baseSlug.'-'.strtolower(Str::random(6));

        $tenant = Tenant::query()->create([
            'name' => $user->name.' Workspace',
            'slug' => $slug,
            'status' => 'active',
            'plan_tier' => 'standard',
            'settings' => [
                'auto_create_crm_lead' => false,
                'enable_call_popups' => true,
                'enable_click_to_call' => true,
                'enable_recording_stream' => true,
                'smart_crm_routing' => false,
            ],
        ]);

        $tenant->addMember($user, TenantRole::Owner, isDefault: true);

        return $tenant;
    }
}
