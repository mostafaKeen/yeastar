<?php

namespace App\Http\Controllers;

use App\Enums\TenantRole;
use App\Http\Middleware\ResolveTenantMiddleware;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TenantSwitchController extends Controller
{
    public function switch(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['required', 'string', 'size:26'],
        ]);

        $tenant = $request->user()
            ->tenants()
            ->where('tenants.id', $validated['tenant_id'])
            ->where('tenants.status', 'active')
            ->first();

        abort_if($tenant === null, 403, 'You do not have access to the requested workspace.');

        $request->session()->put(ResolveTenantMiddleware::SESSION_KEY, $tenant->id);

        return redirect()->route('dashboard');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'plan_tier' => ['sometimes', 'string', 'in:standard,enterprise'],
        ]);

        $baseSlug = Str::slug($validated['name']) ?: 'workspace';
        $slug = $baseSlug.'-'.strtolower(Str::random(6));

        $tenant = Tenant::query()->create([
            'name' => $validated['name'],
            'slug' => $slug,
            'status' => 'active',
            'plan_tier' => $validated['plan_tier'] ?? 'standard',
            'settings' => [
                'auto_create_crm_lead' => false,
                'enable_call_popups' => true,
                'enable_click_to_call' => true,
                'enable_recording_stream' => true,
                'smart_crm_routing' => false,
            ],
        ]);

        $tenant->addMember($request->user(), TenantRole::Owner, isDefault: false);
        $request->session()->put(ResolveTenantMiddleware::SESSION_KEY, $tenant->id);

        return redirect()->route('dashboard');
    }
}
