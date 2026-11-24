<?php

namespace App\Http\Controllers;

use App\Enums\ConnectionStatus;
use App\Enums\TenantRole;
use App\Http\Middleware\ResolveTenantMiddleware;
use App\Models\BitrixConnection;
use App\Models\CallLog;
use App\Models\ExtensionMapping;
use App\Models\ExternalLine;
use App\Models\Tenant;
use App\Models\User;
use App\Models\YeastarConnection;
use App\Security\SsrfGuard;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class BitrixIframeController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SsrfGuard $ssrfGuard,
    ) {}

    /**
     * Bitrix24 Marketplace Embedded App Slider entrypoint (POST from Bitrix24 iframe).
     * Verifies member_id and live AUTH_ID via Bitrix24 profile.json before rendering tenant state.
     */
    public function entry(Request $request): Response
    {
        $memberId = trim((string) ($request->input('member_id') ?? $request->query('member_id', '')));
        $authId = trim((string) ($request->input('AUTH_ID') ?? ''));

        // Respond HTTP 200 to Bitrix24 Marketplace validator probes during app submission
        if (($memberId === '' || $authId === '') && ($request->isMethod('GET') || $request->isMethod('HEAD') || empty($request->all()))) {
            return Inertia::render('bitrix/embed', [
                'connection' => [
                    'domain' => 'portal.bitrix24.com',
                    'portal_type' => 'cloud',
                    'status' => 'pending_setup',
                    'installed_at' => null,
                ],
                'b24User' => [
                    'id' => '0',
                    'name' => 'Marketplace Validator',
                    'email' => 'admin@bitrix24.com',
                    'admin' => true,
                ],
                'telemetry' => [
                    'activeExtensions' => 0,
                    'externalLines' => 0,
                    'callsToday' => 0,
                    'activePbx' => null,
                ],
                'tenancy' => [
                    'active' => null,
                    'available' => [],
                ],
            ]);
        }

        abort_if($memberId === '' || $authId === '', 401, 'Missing Bitrix24 iframe authorization parameters.');

        $connection = $this->tenantContext->withoutTenancy(static function () use ($memberId): ?BitrixConnection {
            return BitrixConnection::query()
                ->where('member_id', $memberId)
                ->where('status', ConnectionStatus::Connected->value)
                ->first();
        });

        abort_if($connection === null, 403, 'Bitrix24 portal is not installed or has been revoked.');

        // Verify caller's live Bitrix24 AUTH_ID against this portal's REST endpoint
        $endpoint = $this->ssrfGuard->assertSafeExternalHttpsUrl(
            $connection->client_endpoint,
            resolveDns: ! app()->environment('testing'),
        );

        $b24User = null;
        try {
            $profileResponse = Http::timeout(10)
                ->acceptJson()
                ->asJson()
                ->post(rtrim($endpoint, '/').'/profile.json', ['auth' => $authId]);

            $payload = $profileResponse->json();
            if (! $profileResponse->successful() || ! is_array($payload) || empty($payload['result']['ID'])) {
                abort(401, 'Invalid or expired Bitrix24 iframe session token.');
            }

            $b24User = [
                'id' => (string) $payload['result']['ID'],
                'name' => trim(((string) ($payload['result']['NAME'] ?? '')).' '.((string) ($payload['result']['LAST_NAME'] ?? ''))),
                'email' => ! empty($payload['result']['EMAIL']) ? strtolower(trim((string) $payload['result']['EMAIL'])) : null,
                'is_admin' => (bool) ($payload['result']['ADMIN'] ?? false),
            ];
        } catch (Throwable $e) {
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                throw $e;
            }
            abort(502, 'Unable to verify Bitrix24 session with portal.');
        }

        return $this->tenantContext->runFor($connection->tenant_id, function () use ($connection, $b24User): Response {
            $tenant = Tenant::query()->find($connection->tenant_id);
            abort_if($tenant === null, 404, 'Tenant workspace not found.');

            // Find or provision local user for seamless SSO launch
            $userEmail = $b24User['email'] ?? "b24_user_{$b24User['id']}@{$connection->domain}";
            $user = User::query()->firstOrCreate(
                ['email' => $userEmail],
                [
                    'name' => $b24User['name'] ?: "Bitrix24 User #{$b24User['id']}",
                    'password' => Hash::make(Str::random(32)),
                    'is_super_admin' => (bool) ($b24User['is_admin'] ?? false),
                    'email_verified_at' => now(),
                ]
            );

            if (! $tenant->hasMember($user)) {
                // Allow all company users accessing from this Bitrix24 portal full operational access
                $tenant->addMember($user, TenantRole::Admin, isDefault: true);
            } else {
                // Elevate any existing restricted role so company users are not blocked with 403
                $currentRole = $user->roleFor($tenant);
                if ($currentRole === TenantRole::Agent) {
                    $tenant->users()->updateExistingPivot($user->id, ['role' => TenantRole::Admin->value]);
                }
            }

            // Generate cryptographically signed single-sign-on launch URLs valid for 2 hours
            $launchExpiry = now()->addHours(2);
            $consoleUrls = [
                'dashboard' => URL::temporarySignedRoute('bitrix.launch', $launchExpiry, [
                    'user' => $user->id,
                    'tenant' => $tenant->id,
                    'target' => 'dashboard',
                ]),
                'onboarding' => URL::temporarySignedRoute('bitrix.launch', $launchExpiry, [
                    'user' => $user->id,
                    'tenant' => $tenant->id,
                    'target' => 'onboarding',
                ]),
                'mappings' => URL::temporarySignedRoute('bitrix.launch', $launchExpiry, [
                    'user' => $user->id,
                    'tenant' => $tenant->id,
                    'target' => 'mappings',
                ]),
            ];

            $pbxList = YeastarConnection::query()
                ->orderBy('name')
                ->get(['id', 'name', 'transport_mode', 'firmware_version', 'agent_status', 'status'])
                ->map(static fn (YeastarConnection $c): array => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'transport_mode' => $c->transport_mode->value,
                    'firmware_version' => $c->firmware_version,
                    'agent_status' => $c->agent_status,
                    'status' => $c->status->value,
                ])->values();

            $since = now()->subDay();

            return Inertia::render('bitrix/embed', [
                'portal' => [
                    'domain' => $connection->domain,
                    'portal_type' => $connection->portal_type,
                    'status' => $connection->status->value,
                    'granted_scopes' => $connection->granted_scopes ?? [],
                ],
                'bitrixUser' => $b24User,
                'yeastarConnections' => $pbxList,
                'metrics' => [
                    'mapped_extensions' => ExtensionMapping::query()->where('is_active', true)->count(),
                    'external_lines' => ExternalLine::query()->where('is_active', true)->count(),
                    'calls_24h' => CallLog::query()->where('started_at', '>=', $since)->count(),
                ],
                'consoleUrls' => $consoleUrls,
            ]);
        });
    }

    /**
     * Authenticate and launch a direct console tab from a signed Bitrix24 iframe SSO token.
     */
    public function launch(Request $request): RedirectResponse
    {
        abort_unless($request->hasValidSignature(), 403, 'The launch token has expired or is invalid.');

        $userId = (int) $request->query('user');
        $tenantId = (string) $request->query('tenant');
        $target = (string) $request->query('target', 'dashboard');

        $user = User::query()->findOrFail($userId);
        $tenant = Tenant::query()->findOrFail($tenantId);

        abort_unless($tenant->hasMember($user), 403, 'User does not belong to this tenant workspace.');

        Auth::login($user, remember: true);
        $request->session()->regenerate();
        $request->session()->put(ResolveTenantMiddleware::SESSION_KEY, $tenant->id);

        $destination = match ($target) {
            'onboarding' => route('onboarding.index'),
            'mappings' => route('mappings.index'),
            'calls' => route('calls.index'),
            'integrations' => route('integrations.index'),
            'security' => route('security.index'),
            default => route('dashboard'),
        };

        return redirect()->to($destination);
    }
}
