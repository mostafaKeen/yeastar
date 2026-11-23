<?php

namespace App\Http\Middleware;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * @var string
     */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        /** @var TenantContext $tenantContext */
        $tenantContext = app(TenantContext::class);
        $activeTenant = $tenantContext->tenant();

        $tenants = [];
        $activeRole = null;

        if ($user !== null) {
            $userTenants = $user->tenants()
                ->where('tenants.status', 'active')
                ->orderBy('tenants.name')
                ->get(['tenants.id', 'tenants.name', 'tenants.slug', 'tenants.plan_tier', 'tenants.status']);

            $tenants = $userTenants->map(static function (Tenant $tenant): array {
                $role = $tenant->pivot->role;
                $roleValue = $role instanceof TenantRole ? $role->value : (string) $role;

                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'plan_tier' => $tenant->plan_tier,
                    'status' => $tenant->status,
                    'role' => $roleValue,
                ];
            })->values()->all();

            if ($activeTenant !== null) {
                $activeRole = $user->roleFor($activeTenant);
            }
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => [
                'message' => 'Reliable multi-tenant telephony between Yeastar P-Series and Bitrix24.',
                'author' => config('app.name'),
            ],
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_super_admin' => (bool) $user->is_super_admin,
                    'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                    'created_at' => $user->created_at?->toIso8601String(),
                    'updated_at' => $user->updated_at?->toIso8601String(),
                ],
            ],
            'tenancy' => [
                'active' => $activeTenant === null ? null : [
                    'id' => $activeTenant->id,
                    'name' => $activeTenant->name,
                    'slug' => $activeTenant->slug,
                    'plan_tier' => $activeTenant->plan_tier,
                    'status' => $activeTenant->status,
                    'role' => $activeRole?->value,
                    'permissions' => [
                        'manage_integrations' => $activeRole?->canManageIntegrations() ?? false,
                        'manage_security' => $activeRole?->canManageSecurity() ?? false,
                        'view_all_calls' => $activeRole?->canViewAllCalls() ?? false,
                        'stream_recordings' => $activeRole?->canStreamRecordings() ?? false,
                    ],
                ],
                'workspaces' => $tenants,
            ],
            'flash' => [
                'status' => $request->session()->get('status'),
                'error' => $request->session()->get('error'),
                'generated_agent_token' => $request->session()->get('generated_agent_token'),
            ],
        ];
    }
}
