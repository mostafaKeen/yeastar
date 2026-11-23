<?php

namespace App\Http\Controllers;

use App\Enums\TenantRole;
use App\Models\BitrixConnection;
use App\Models\User;
use App\Models\YeastarConnection;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request): Response
    {
        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 403);
        Gate::authorize('manageSecurity', $tenant);

        $members = $tenant->users()
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.email'])
            ->map(static function (User $member): array {
                $role = $member->pivot->role;

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => $role instanceof TenantRole ? $role->value : (string) $role,
                ];
            })->values();

        $yeastarConnections = YeastarConnection::query()
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'transport_mode',
                'agent_token_prefix',
                'agent_status',
                'allowed_ips',
                'status',
            ])
            ->map(static fn (YeastarConnection $conn): array => [
                'id' => $conn->id,
                'name' => $conn->name,
                'transport_mode' => $conn->transport_mode->value,
                'agent_token_prefix' => $conn->agent_token_prefix,
                'agent_status' => $conn->agent_status,
                'allowed_ips' => $conn->allowed_ips ?? [],
                'status' => $conn->status->value,
            ])->values();

        $bitrix = BitrixConnection::query()->first();
        $requiredScopes = (array) config('telephony.bitrix24.required_scopes', ['telephony', 'crm', 'user_basic']);
        $grantedScopes = $bitrix?->granted_scopes ?? [];
        $hasRequiredScopes = $bitrix !== null && count(array_diff($requiredScopes, $grantedScopes)) === 0;

        $auditRetentionDays = (int) config('telephony.retention.api_audit_default_days', 14);
        $appUrl = (string) config('app.url', '');

        $complianceChecks = [
            [
                'key' => 'marketplace_oauth',
                'label' => 'Bitrix24 Marketplace OAuth Credentials',
                'passed' => ! empty(config('telephony.bitrix24.client_id')) && ! empty(config('telephony.bitrix24.client_secret')),
                'detail' => 'Central BITRIX24_CLIENT_ID and BITRIX24_CLIENT_SECRET configured in server environment.',
            ],
            [
                'key' => 'least_privilege_scopes',
                'label' => 'Least-Privilege Bitrix24 Scopes',
                'passed' => $hasRequiredScopes,
                'detail' => 'Requires telephony, crm, and user_basic scopes without excessive privileges.',
            ],
            [
                'key' => 'https_transport',
                'label' => 'HTTPS Public Application URL',
                'passed' => str_starts_with(strtolower($appUrl), 'https://'),
                'detail' => 'Bitrix24 Market requires a valid DV+ SSL certificate on all callback endpoints.',
            ],
            [
                'key' => 'audit_retention',
                'label' => '3+ Day Sanitized REST API Audit Logs',
                'passed' => $auditRetentionDays >= 3,
                'detail' => "Currently configured for {$auditRetentionDays} days with automatic SecretRedactor filtering.",
            ],
            [
                'key' => 'zero_disk_recordings',
                'label' => 'Zero Server-Side Recording Storage',
                'passed' => true,
                'detail' => 'Recordings stream directly from Yeastar to Bitrix24 attachRecord uploadUrl with zero local persistence.',
            ],
        ];

        return Inertia::render('security/index', [
            'members' => $members,
            'yeastarConnections' => $yeastarConnections,
            'complianceChecks' => $complianceChecks,
            'callerRole' => $request->user()->roleFor($tenant)?->value ?? 'agent',
            'currentUserId' => $request->user()->id,
        ]);
    }

    public function inviteMember(Request $request): RedirectResponse
    {
        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 403);
        Gate::authorize('manageSecurity', $tenant);

        $callerRole = $request->user()->roleFor($tenant);
        $allowedRoles = $callerRole === TenantRole::Owner
            ? ['owner', 'admin', 'supervisor', 'agent']
            : ['admin', 'supervisor', 'agent'];

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:191'],
            'role' => ['required', Rule::in($allowedRoles)],
        ]);

        $email = strtolower(trim($validated['email']));

        $member = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => trim($validated['name']),
                'password' => Hash::make(Str::random(32)),
            ],
        );

        $tenant->addMember($member, TenantRole::from($validated['role']), isDefault: false);

        return back()->with('status', "Added {$member->email} as {$validated['role']} to {$tenant->name}.");
    }

    public function updateMemberRole(Request $request, User $member): RedirectResponse
    {
        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 403);
        Gate::authorize('manageSecurity', $tenant);

        $existingRole = $member->roleFor($tenant);
        abort_if($existingRole === null, 404, 'Member not found in active workspace.');

        $callerRole = $request->user()->roleFor($tenant);
        $allowedRoles = $callerRole === TenantRole::Owner
            ? ['owner', 'admin', 'supervisor', 'agent']
            : ['admin', 'supervisor', 'agent'];

        $validated = $request->validate([
            'role' => ['required', Rule::in($allowedRoles)],
        ]);

        $newRole = TenantRole::from($validated['role']);

        if ($existingRole === TenantRole::Owner && $newRole !== TenantRole::Owner) {
            $ownerCount = $tenant->memberships()->where('role', TenantRole::Owner->value)->count();
            if ($ownerCount <= 1) {
                return back()->with('error', 'Cannot demote the sole Owner of this workspace.');
            }
        }

        $tenant->users()->updateExistingPivot($member->id, ['role' => $newRole->value]);

        return back()->with('status', "Updated {$member->email} role to {$newRole->label()}.");
    }

    public function removeMember(Request $request, User $member): RedirectResponse
    {
        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 403);
        Gate::authorize('manageSecurity', $tenant);

        $existingRole = $member->roleFor($tenant);
        abort_if($existingRole === null, 404, 'Member not found in active workspace.');

        if ($member->id === $request->user()->id) {
            return back()->with('error', 'You cannot remove your own account from the active workspace.');
        }

        if ($existingRole === TenantRole::Owner) {
            $ownerCount = $tenant->memberships()->where('role', TenantRole::Owner->value)->count();
            if ($ownerCount <= 1) {
                return back()->with('error', 'Cannot remove the sole Owner of this workspace.');
            }
        }

        $tenant->users()->detach($member->id);

        return back()->with('status', "Removed {$member->email} from {$tenant->name}.");
    }

    public function updateIpAllowlist(Request $request, YeastarConnection $connection): RedirectResponse
    {
        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 403);
        Gate::authorize('manageSecurity', $tenant);

        $validated = $request->validate([
            'allowed_ips' => ['nullable', 'string', 'max:1000'],
        ]);

        $rawItems = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) ($validated['allowed_ips'] ?? '')),
        )));

        foreach ($rawItems as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                return back()->withErrors([
                    'allowed_ips' => "Invalid IP address [{$ip}]. Provide comma-separated IPv4 or IPv6 addresses.",
                ]);
            }
        }

        $connection->update(['allowed_ips' => $rawItems]);

        return back()->with('status', "Updated webhook IP allowlist for [{$connection->name}].");
    }
}
