<?php

namespace App\Http\Controllers;

use App\Enums\ConnectionStatus;
use App\Enums\YeastarTransportMode;
use App\Jobs\SyncCrmPhonebookJob;
use App\Models\BitrixConnection;
use App\Models\ExternalLine;
use App\Models\YeastarConnection;
use App\Security\SsrfGuard;
use App\Services\Bitrix24\BitrixClient;
use App\Services\Yeastar\YeastarClientFactory;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Throwable;

class IntegrationsController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SsrfGuard $ssrfGuard,
        private readonly YeastarClientFactory $yeastarFactory,
        private readonly BitrixClient $bitrixClient,
    ) {}

    public function index(Request $request): Response
    {
        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 403);

        $bitrix = BitrixConnection::query()->first();
        $yeastarConnections = YeastarConnection::query()->orderBy('created_at')->get();
        $externalLines = ExternalLine::query()->with('yeastarConnection:id,name')->orderBy('bitrix_line_number')->get();

        return Inertia::render('integrations/index', [
            'bitrix' => $bitrix === null ? null : [
                'id' => $bitrix->id,
                'member_id' => $bitrix->member_id,
                'domain' => $bitrix->domain,
                'portal_type' => $bitrix->portal_type,
                'status' => $bitrix->status->value,
                'granted_scopes' => $bitrix->granted_scopes ?? [],
                'installed_at' => $bitrix->installed_at?->toIso8601String(),
                'token_expires_at' => $bitrix->token_expires_at?->toIso8601String(),
            ],
            'yeastarConnections' => $yeastarConnections->map(static fn (YeastarConnection $conn): array => [
                'id' => $conn->id,
                'name' => $conn->name,
                'transport_mode' => $conn->transport_mode->value,
                'pbx_base_url' => $conn->pbx_base_url,
                'firmware_version' => $conn->firmware_version,
                'client_id' => $conn->client_id,
                'webhook_url' => route('api.yeastar.webhook', ['webhookUlid' => $conn->webhook_ulid]),
                'agent_token_prefix' => $conn->agent_token_prefix,
                'agent_status' => $conn->agent_status,
                'allowed_ips' => $conn->allowed_ips ?? [],
                'status' => $conn->status->value,
                'last_heartbeat_at' => $conn->last_heartbeat_at?->toIso8601String(),
            ])->values(),
            'externalLines' => $externalLines->map(static fn (ExternalLine $line): array => [
                'id' => $line->id,
                'yeastar_connection_id' => $line->yeastar_connection_id,
                'yeastar_connection_name' => $line->yeastarConnection?->name,
                'bitrix_line_number' => $line->bitrix_line_number,
                'bitrix_line_name' => $line->bitrix_line_name,
                'yeastar_trunk_name' => $line->yeastar_trunk_name,
                'did_number' => $line->did_number,
                'is_default' => $line->is_default,
                'is_active' => $line->is_active,
            ])->values(),
            'platformMeta' => [
                'bitrix_install_url' => route('api.bitrix.install'),
                'bitrix_events_url' => route('api.bitrix.events'),
                'static_egress_ips' => config('telephony.yeastar.static_egress_ips', []),
                'min_firmware' => config('telephony.yeastar.min_firmware_version', '37.7.0.16'),
                'saas_url' => config('app.url'),
            ],
        ]);
    }

    public function storeYeastar(Request $request): RedirectResponse
    {
        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 403);
        Gate::authorize('manageIntegrations', $tenant);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'transport_mode' => ['required', Rule::in([
                YeastarTransportMode::DirectHttps->value,
                YeastarTransportMode::TunnelAgent->value,
            ])],
            'pbx_base_url' => ['nullable', 'string', 'max:512'],
            'client_id' => ['nullable', 'string', 'max:191'],
            'client_secret' => ['nullable', 'string', 'max:512'],
            'allowed_ips' => ['nullable', 'string', 'max:512'],
        ]);

        $mode = YeastarTransportMode::from($validated['transport_mode']);
        $safeBaseUrl = null;

        if ($mode === YeastarTransportMode::DirectHttps) {
            if (empty($validated['pbx_base_url']) || empty($validated['client_id']) || empty($validated['client_secret'])) {
                return back()->withErrors([
                    'pbx_base_url' => 'PBX FQDN URL, Client ID, and Client Secret are required for Direct HTTPS mode.',
                ]);
            }

            try {
                $safeBaseUrl = $this->ssrfGuard->assertSafeExternalHttpsUrl(
                    $validated['pbx_base_url'],
                    resolveDns: ! app()->environment('testing'),
                );
            } catch (InvalidArgumentException $e) {
                return back()->withErrors(['pbx_base_url' => $e->getMessage()]);
            }
        }

        $allowedIps = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) ($validated['allowed_ips'] ?? '')),
        )));

        $connection = YeastarConnection::query()->create([
            'name' => $validated['name'],
            'transport_mode' => $mode,
            'pbx_base_url' => $safeBaseUrl,
            'client_id' => ! empty($validated['client_id']) ? $validated['client_id'] : null,
            'client_secret_enc' => ! empty($validated['client_secret']) ? $validated['client_secret'] : null,
            'allowed_ips' => $allowedIps,
            'status' => ConnectionStatus::Pending,
            'agent_status' => 'offline',
        ]);

        if ($mode === YeastarTransportMode::TunnelAgent) {
            $oneTimeToken = $connection->rotateAgentToken();

            return back()
                ->with('status', "Created On-Premise Tunnel Agent connection [{$connection->name}]. Copy the bearer token now; it will not be displayed again.")
                ->with('generated_agent_token', [
                    'connection_id' => $connection->id,
                    'connection_name' => $connection->name,
                    'token' => $oneTimeToken,
                ]);
        }

        return back()->with('status', "Saved Yeastar P-Series connection [{$connection->name}]. Run Test Connection to verify API access.");
    }

    public function testYeastar(Request $request, YeastarConnection $connection): RedirectResponse
    {
        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 403);
        Gate::authorize('manageIntegrations', $tenant);

        try {
            $result = $this->yeastarFactory->for($connection)->healthCheck($connection);

            return back()->with(
                $result['ok'] ? 'status' : 'error',
                $result['message'],
            );
        } catch (Throwable $e) {
            return back()->with('error', 'Connection test failed: '.$e->getMessage());
        }
    }

    public function rotateAgentToken(Request $request, YeastarConnection $connection): RedirectResponse
    {
        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 403);
        Gate::authorize('manageIntegrations', $tenant);

        if ($connection->transport_mode !== YeastarTransportMode::TunnelAgent) {
            return back()->with('error', 'Agent tokens are only used for On-Premise Tunnel Agent connections.');
        }

        $token = $connection->rotateAgentToken();

        return back()
            ->with('status', "Rotated On-Premise Agent token for [{$connection->name}]. Previous token has been revoked.")
            ->with('generated_agent_token', [
                'connection_id' => $connection->id,
                'connection_name' => $connection->name,
                'token' => $token,
            ]);
    }

    public function destroyYeastar(Request $request, YeastarConnection $connection): RedirectResponse
    {
        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 403);
        Gate::authorize('manageIntegrations', $tenant);

        $name = $connection->name;
        $connection->delete();

        return back()->with('status', "Removed Yeastar connection [{$name}].");
    }

    public function syncPhonebook(Request $request, YeastarConnection $connection): RedirectResponse
    {
        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 403);
        Gate::authorize('manageIntegrations', $tenant);

        if (! BitrixConnection::query()->where('status', ConnectionStatus::Connected->value)->exists()) {
            return back()->with('error', 'Connect a Bitrix24 Marketplace portal before syncing CRM contacts to Yeastar Phonebooks.');
        }

        SyncCrmPhonebookJob::dispatch(
            tenantId: $tenant->id,
            yeastarConnectionId: $connection->id,
        );

        return back()->with('status', "Queued Bitrix24 CRM Contact sync to Yeastar Phonebook [{$connection->name}].");
    }

    public function storeExternalLine(Request $request): RedirectResponse
    {
        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 403);
        Gate::authorize('manageIntegrations', $tenant);

        $validated = $request->validate([
            'yeastar_connection_id' => ['required', 'string', 'size:26'],
            'bitrix_line_number' => ['required', 'string', 'max:64'],
            'bitrix_line_name' => ['required', 'string', 'max:120'],
            'yeastar_trunk_name' => ['nullable', 'string', 'max:120'],
            'did_number' => ['nullable', 'string', 'max:64'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        // Scope check guarantees the Yeastar connection belongs to the active tenant
        $pbx = YeastarConnection::query()->findOrFail($validated['yeastar_connection_id']);

        $bitrix = BitrixConnection::query()->first();
        if ($bitrix !== null && $bitrix->status === ConnectionStatus::Connected) {
            try {
                $this->bitrixClient->addExternalLine(
                    $bitrix,
                    $validated['bitrix_line_number'],
                    $validated['bitrix_line_name'],
                );
            } catch (Throwable $e) {
                return back()->with('error', 'Bitrix24 rejected external line registration: '.$e->getMessage());
            }
        }

        ExternalLine::query()->updateOrCreate(
            ['bitrix_line_number' => $validated['bitrix_line_number']],
            [
                'yeastar_connection_id' => $pbx->id,
                'bitrix_line_name' => $validated['bitrix_line_name'],
                'yeastar_trunk_name' => $validated['yeastar_trunk_name'] ?? null,
                'did_number' => $validated['did_number'] ?? null,
                'is_default' => (bool) ($validated['is_default'] ?? false),
                'is_active' => true,
            ],
        );

        return back()->with('status', "Registered external line [{$validated['bitrix_line_number']}].");
    }

    public function destroyExternalLine(Request $request, ExternalLine $externalLine): RedirectResponse
    {
        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 403);
        Gate::authorize('manageIntegrations', $tenant);

        $externalLine->delete();

        return back()->with('status', 'External line removed.');
    }
}
