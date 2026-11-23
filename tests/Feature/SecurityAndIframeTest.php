<?php

namespace Tests\Feature;

use App\Enums\ConnectionStatus;
use App\Enums\TenantRole;
use App\Enums\YeastarTransportMode;
use App\Http\Middleware\ResolveTenantMiddleware;
use App\Models\BitrixConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Models\YeastarConnection;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SecurityAndIframeTest extends TestCase
{
    use RefreshDatabase;

    public function test_rbac_team_management_and_sole_owner_protection(): void
    {
        $owner = User::factory()->create(['email' => 'owner@sec.example']);
        $agent = User::factory()->create(['email' => 'agent@sec.example']);
        $tenant = Tenant::query()->create(['name' => 'Sec Corp', 'slug' => 'sec-corp', 'status' => 'active', 'plan_tier' => 'enterprise']);
        $tenant->addMember($owner, TenantRole::Owner, isDefault: true);
        $tenant->addMember($agent, TenantRole::Agent, isDefault: true);

        // Agent cannot access Security Administration
        $this->actingAs($agent)
            ->withSession([ResolveTenantMiddleware::SESSION_KEY => $tenant->id])
            ->get(route('security.index'))
            ->assertForbidden();

        // Owner accesses Security page cleanly
        $this->actingAs($owner)
            ->withSession([ResolveTenantMiddleware::SESSION_KEY => $tenant->id])
            ->get(route('security.index'))
            ->assertOk();

        // Owner invites a new Supervisor
        $this->actingAs($owner)
            ->withSession([ResolveTenantMiddleware::SESSION_KEY => $tenant->id])
            ->post(route('security.members.store'), [
                'name' => 'Sam Supervisor',
                'email' => 'sam@sec.example',
                'role' => 'supervisor',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $sam = User::query()->where('email', 'sam@sec.example')->firstOrFail();
        $this->assertSame(TenantRole::Supervisor, $sam->roleFor($tenant));

        // Sole Owner cannot be demoted
        $this->actingAs($owner)
            ->withSession([ResolveTenantMiddleware::SESSION_KEY => $tenant->id])
            ->patch(route('security.members.update', ['member' => $owner->id]), ['role' => 'admin'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(TenantRole::Owner, $owner->roleFor($tenant));

        // Owner can promote Sam to Admin and remove Agent
        $this->actingAs($owner)
            ->withSession([ResolveTenantMiddleware::SESSION_KEY => $tenant->id])
            ->patch(route('security.members.update', ['member' => $sam->id]), ['role' => 'admin'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(TenantRole::Admin, $sam->roleFor($tenant));

        $this->actingAs($owner)
            ->withSession([ResolveTenantMiddleware::SESSION_KEY => $tenant->id])
            ->delete(route('security.members.destroy', ['member' => $agent->id]))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertFalse($agent->belongsToTenant($tenant));
    }

    public function test_ip_allowlist_validates_ips_and_blocks_cross_tenant_updates(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();

        $tenantA = Tenant::query()->create(['name' => 'Org A', 'slug' => 'org-a', 'status' => 'active', 'plan_tier' => 'standard']);
        $tenantB = Tenant::query()->create(['name' => 'Org B', 'slug' => 'org-b', 'status' => 'active', 'plan_tier' => 'standard']);
        $tenantA->addMember($ownerA, TenantRole::Owner, isDefault: true);
        $tenantB->addMember($ownerB, TenantRole::Owner, isDefault: true);

        /** @var TenantContext $context */
        $context = app(TenantContext::class);
        $pbxA = $context->runFor($tenantA, static fn () => YeastarConnection::query()->create([
            'name' => 'Org A PBX',
            'transport_mode' => YeastarTransportMode::DirectHttps,
            'pbx_base_url' => 'https://orga.ras.yeastar.com',
            'status' => ConnectionStatus::Connected,
        ]));

        // Cross-tenant update attempt returns 404 via tenant-scoped route model binding
        $this->actingAs($ownerB)
            ->withSession([ResolveTenantMiddleware::SESSION_KEY => $tenantB->id])
            ->patch(route('security.yeastar.ip-allowlist', ['connection' => $pbxA->id]), [
                'allowed_ips' => '203.0.113.5',
            ])
            ->assertNotFound();

        // Invalid IP rejected for Owner A
        $this->actingAs($ownerA)
            ->withSession([ResolveTenantMiddleware::SESSION_KEY => $tenantA->id])
            ->patch(route('security.yeastar.ip-allowlist', ['connection' => $pbxA->id]), [
                'allowed_ips' => 'not-an-ip',
            ])
            ->assertSessionHasErrors('allowed_ips');

        // Valid IPs persisted for Owner A
        $this->actingAs($ownerA)
            ->withSession([ResolveTenantMiddleware::SESSION_KEY => $tenantA->id])
            ->patch(route('security.yeastar.ip-allowlist', ['connection' => $pbxA->id]), [
                'allowed_ips' => '203.0.113.10, 203.0.113.11',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $fresh = $context->runFor($tenantA, static fn () => $pbxA->fresh());
        $this->assertSame(['203.0.113.10', '203.0.113.11'], $fresh?->allowed_ips);
    }

    public function test_bitrix_embedded_iframe_verifies_live_auth_id_and_sets_frame_ancestors_csp(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Embed Org', 'slug' => 'embed-org', 'status' => 'active', 'plan_tier' => 'enterprise']);

        /** @var TenantContext $context */
        $context = app(TenantContext::class);
        $context->runFor($tenant, static function (): void {
            BitrixConnection::query()->create([
                'member_id' => 'member_embed_55',
                'domain' => 'embed.bitrix24.com',
                'portal_type' => 'cloud',
                'client_endpoint' => 'https://embed.bitrix24.com/rest/',
                'access_token_enc' => 'super_secret_b24_token',
                'status' => ConnectionStatus::Connected,
                'granted_scopes' => ['telephony', 'crm', 'user_basic'],
            ]);
        });

        // Missing auth fails with 401
        $this->post(route('bitrix.embed'), ['member_id' => 'member_embed_55'])->assertUnauthorized();

        // Unknown portal fails with 403
        $this->post(route('bitrix.embed'), ['member_id' => 'unknown_member', 'AUTH_ID' => 'tok'])->assertForbidden();

        Http::fake([
            'https://embed.bitrix24.com/rest/profile.json' => Http::response([
                'result' => ['ID' => 7, 'NAME' => 'Nina', 'LAST_NAME' => 'Admin', 'ADMIN' => true],
            ], 200),
        ]);

        // Valid iframe POST verifies AUTH_ID and renders borderless Bitrix24 embed view without leaking secrets
        $response = $this->post(route('bitrix.embed'), [
            'member_id' => 'member_embed_55',
            'AUTH_ID' => 'live_iframe_user_auth',
        ]);

        $response->assertOk();
        $this->assertStringContainsString('frame-ancestors', (string) $response->headers->get('Content-Security-Policy'));
        $body = $response->getContent() ?: '';
        $this->assertStringContainsString('embed.bitrix24.com', $body);
        $this->assertStringNotContainsString('super_secret_b24_token', $body);
    }

    public function test_telephony_readiness_check_command_succeeds(): void
    {
        $this->artisan('telephony:readiness-check')->assertSuccessful();
    }
}
