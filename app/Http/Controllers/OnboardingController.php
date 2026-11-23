<?php

namespace App\Http\Controllers;

use App\Enums\ConnectionStatus;
use App\Models\BitrixConnection;
use App\Models\ExtensionMapping;
use App\Models\ExternalLine;
use App\Models\YeastarConnection;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(): Response
    {
        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 403);

        $bitrix = BitrixConnection::query()->first();
        $yeastarConnections = YeastarConnection::query()->orderBy('created_at')->get();
        $mappingsCount = ExtensionMapping::query()->where('is_active', true)->count();
        $linesCount = ExternalLine::query()->where('is_active', true)->count();

        $step1Done = $bitrix !== null && $bitrix->status === ConnectionStatus::Connected;
        $step2Done = $yeastarConnections->contains(
            static fn (YeastarConnection $c): bool => $c->status === ConnectionStatus::Connected || $c->agent_status === 'online',
        );
        $step3Done = $mappingsCount > 0;
        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $step4Done = (bool) ($settings['onboarding_completed'] ?? false);

        return Inertia::render('onboarding/index', [
            'steps' => [
                'bitrix_connected' => $step1Done,
                'yeastar_connected' => $step2Done,
                'extensions_mapped' => $step3Done,
                'routing_configured' => $step4Done,
            ],
            'bitrix' => $bitrix === null ? null : [
                'domain' => $bitrix->domain,
                'portal_type' => $bitrix->portal_type,
                'status' => $bitrix->status->value,
                'granted_scopes' => $bitrix->granted_scopes ?? [],
            ],
            'yeastarConnections' => $yeastarConnections->map(static fn (YeastarConnection $conn): array => [
                'id' => $conn->id,
                'name' => $conn->name,
                'transport_mode' => $conn->transport_mode->value,
                'pbx_base_url' => $conn->pbx_base_url,
                'firmware_version' => $conn->firmware_version,
                'agent_status' => $conn->agent_status,
                'status' => $conn->status->value,
            ])->values(),
            'counts' => [
                'mapped_extensions' => $mappingsCount,
                'external_lines' => $linesCount,
            ],
            'routingPolicy' => [
                'enable_call_popups' => (bool) ($settings['enable_call_popups'] ?? true),
                'enable_click_to_call' => (bool) ($settings['enable_click_to_call'] ?? true),
                'smart_crm_routing' => (bool) ($settings['smart_crm_routing'] ?? false),
                'auto_create_crm_lead' => (bool) ($settings['auto_create_crm_lead'] ?? false),
                'enable_recording_stream' => (bool) ($settings['enable_recording_stream'] ?? true),
            ],
            'platformMeta' => [
                'bitrix_install_url' => route('api.bitrix.install'),
                'bitrix_events_url' => route('api.bitrix.events'),
                'static_egress_ips' => config('telephony.yeastar.static_egress_ips', []),
                'min_firmware' => config('telephony.yeastar.min_firmware_version', '37.7.0.16'),
                'saas_url' => config('app.url'),
            ],
        ]);
    }

    public function updateRouting(Request $request): RedirectResponse
    {
        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 403);
        Gate::authorize('manageIntegrations', $tenant);

        $validated = $request->validate([
            'enable_call_popups' => ['required', 'boolean'],
            'enable_click_to_call' => ['required', 'boolean'],
            'smart_crm_routing' => ['required', 'boolean'],
            'auto_create_crm_lead' => ['required', 'boolean'],
            'enable_recording_stream' => ['required', 'boolean'],
        ]);

        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $tenant->update([
            'settings' => array_merge($settings, $validated, [
                'onboarding_completed' => true,
            ]),
        ]);

        return back()->with('status', 'Routing and telephony policies saved.');
    }
}
