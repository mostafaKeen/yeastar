<?php

namespace App\Http\Controllers;

use App\Models\BitrixConnection;
use App\Models\ExtensionMapping;
use App\Models\YeastarConnection;
use App\Services\Bitrix24\BitrixClient;
use App\Services\Yeastar\YeastarClientFactory;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ExtensionMappingController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly BitrixClient $bitrixClient,
        private readonly YeastarClientFactory $yeastarFactory,
    ) {}

    public function index(): Response
    {
        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 403);

        $mappings = ExtensionMapping::query()
            ->with('yeastarConnection:id,name')
            ->orderBy('yeastar_extension')
            ->get();

        $yeastarConnections = YeastarConnection::query()
            ->orderBy('name')
            ->get(['id', 'name', 'transport_mode', 'status', 'agent_status']);

        $hasBitrix = BitrixConnection::query()->exists();

        return Inertia::render('mappings/index', [
            'mappings' => $mappings->map(static fn (ExtensionMapping $m): array => [
                'id' => $m->id,
                'yeastar_connection_id' => $m->yeastar_connection_id,
                'yeastar_connection_name' => $m->yeastarConnection?->name,
                'bitrix_user_id' => $m->bitrix_user_id,
                'bitrix_user_name' => $m->bitrix_user_name,
                'bitrix_user_email' => $m->bitrix_user_email,
                'yeastar_extension' => $m->yeastar_extension,
                'yeastar_caller_name' => $m->yeastar_caller_name,
                'is_active' => $m->is_active,
                'click_to_call_enabled' => $m->click_to_call_enabled,
                'popup_enabled' => $m->popup_enabled,
            ])->values(),
            'yeastarConnections' => $yeastarConnections->map(static fn (YeastarConnection $c): array => [
                'id' => $c->id,
                'name' => $c->name,
                'transport_mode' => $c->transport_mode->value,
                'status' => $c->status->value,
                'agent_status' => $c->agent_status,
            ])->values(),
            'hasBitrixConnection' => $hasBitrix,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 403);
        Gate::authorize('manageIntegrations', $tenant);

        $validated = $request->validate([
            'yeastar_connection_id' => ['required', 'string', 'size:26'],
            'bitrix_user_id' => ['required', 'string', 'max:32'],
            'bitrix_user_name' => ['nullable', 'string', 'max:160'],
            'bitrix_user_email' => ['nullable', 'email', 'max:191'],
            'yeastar_extension' => ['required', 'string', 'max:32'],
            'yeastar_caller_name' => ['nullable', 'string', 'max:160'],
            'click_to_call_enabled' => ['sometimes', 'boolean'],
            'popup_enabled' => ['sometimes', 'boolean'],
        ]);

        $pbx = YeastarConnection::query()->findOrFail($validated['yeastar_connection_id']);

        ExtensionMapping::query()->updateOrCreate(
            [
                'yeastar_connection_id' => $pbx->id,
                'yeastar_extension' => trim($validated['yeastar_extension']),
            ],
            [
                'bitrix_user_id' => trim($validated['bitrix_user_id']),
                'bitrix_user_name' => $validated['bitrix_user_name'] ?? null,
                'bitrix_user_email' => $validated['bitrix_user_email'] ?? null,
                'yeastar_caller_name' => $validated['yeastar_caller_name'] ?? null,
                'is_active' => true,
                'click_to_call_enabled' => (bool) ($validated['click_to_call_enabled'] ?? true),
                'popup_enabled' => (bool) ($validated['popup_enabled'] ?? true),
            ],
        );

        $this->invalidateHotPathCache($tenant->id);

        return back()->with('status', "Mapped Bitrix24 user #{$validated['bitrix_user_id']} to extension {$validated['yeastar_extension']}.");
    }

    public function update(Request $request, ExtensionMapping $mapping): RedirectResponse
    {
        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 403);
        Gate::authorize('manageIntegrations', $tenant);

        $validated = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'click_to_call_enabled' => ['sometimes', 'boolean'],
            'popup_enabled' => ['sometimes', 'boolean'],
        ]);

        $mapping->update($validated);
        $this->invalidateHotPathCache($tenant->id);

        return back()->with('status', "Updated preferences for extension {$mapping->yeastar_extension}.");
    }

    public function destroy(Request $request, ExtensionMapping $mapping): RedirectResponse
    {
        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 403);
        Gate::authorize('manageIntegrations', $tenant);

        $ext = $mapping->yeastar_extension;
        $mapping->delete();
        $this->invalidateHotPathCache($tenant->id);

        return back()->with('status', "Removed extension mapping {$ext}.");
    }

    /**
     * 1-Click Auto-Match by Email (Yeastar P-Series V1.7 Sec 6.5.1 pattern):
     * Correlates Bitrix24 users with Yeastar PBX extensions sharing the same normalized email address.
     */
    public function autoMatch(Request $request): RedirectResponse
    {
        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 403);
        Gate::authorize('manageIntegrations', $tenant);

        $validated = $request->validate([
            'yeastar_connection_id' => ['required', 'string', 'size:26'],
        ]);

        $bitrix = BitrixConnection::query()->first();
        if ($bitrix === null) {
            return back()->with('error', 'Connect a Bitrix24 Marketplace portal before running auto-match.');
        }

        $pbx = YeastarConnection::query()->findOrFail($validated['yeastar_connection_id']);

        try {
            $b24Users = $this->bitrixClient->listUsers($bitrix);
            $ysExtensions = $this->yeastarFactory->for($pbx)->listExtensions($pbx);
        } catch (Throwable $e) {
            return back()->with('error', 'Auto-match sync failed: '.$e->getMessage());
        }

        $usersByEmail = [];
        foreach ($b24Users as $user) {
            $email = strtolower(trim((string) ($user['EMAIL'] ?? '')));
            if ($email === '' || empty($user['ID'])) {
                continue;
            }

            $fullName = trim(((string) ($user['NAME'] ?? '')).' '.((string) ($user['LAST_NAME'] ?? '')));
            $usersByEmail[$email] = [
                'id' => (string) $user['ID'],
                'name' => $fullName !== '' ? $fullName : $email,
                'email' => $email,
            ];
        }

        $matched = 0;
        foreach ($ysExtensions as $ext) {
            $email = strtolower(trim((string) ($ext['email'] ?? '')));
            if ($email === '' || ! isset($usersByEmail[$email])) {
                continue;
            }

            $b24User = $usersByEmail[$email];

            ExtensionMapping::query()->updateOrCreate(
                [
                    'yeastar_connection_id' => $pbx->id,
                    'yeastar_extension' => $ext['number'],
                ],
                [
                    'bitrix_user_id' => $b24User['id'],
                    'bitrix_user_name' => $b24User['name'],
                    'bitrix_user_email' => $b24User['email'],
                    'yeastar_caller_name' => $ext['caller_id_name'],
                    'is_active' => true,
                    'click_to_call_enabled' => true,
                    'popup_enabled' => true,
                ],
            );

            $matched++;
        }

        $this->invalidateHotPathCache($tenant->id);

        return back()->with(
            'status',
            $matched > 0
                ? "Auto-matched {$matched} Bitrix24 users with Yeastar PBX extensions by email."
                : 'No matching email addresses were found between Bitrix24 users and Yeastar extensions.',
        );
    }

    private function invalidateHotPathCache(string $tenantId): void
    {
        Cache::forget("t:{$tenantId}:ext_map");
    }
}
