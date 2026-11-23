<?php

namespace Tests\Feature;

use App\Enums\CallDirection;
use App\Enums\ConnectionStatus;
use App\Enums\TenantRole;
use App\Enums\YeastarTransportMode;
use App\Models\BitrixConnection;
use App\Models\CallLog;
use App\Models\Tenant;
use App\Models\User;
use App\Models\YeastarConnection;
use App\Tenancy\Exceptions\MissingTenantContextException;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenancyIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_querying_tenant_scoped_model_without_context_fails_closed(): void
    {
        app(TenantContext::class)->clear();

        $this->expectException(MissingTenantContextException::class);

        YeastarConnection::query()->get();
    }

    public function test_tenants_are_strictly_isolated_for_reads_and_cross_tenant_writes(): void
    {
        $tenantA = Tenant::query()->create(['name' => 'Alpha Corp', 'slug' => 'alpha-corp', 'status' => 'active', 'plan_tier' => 'standard']);
        $tenantB = Tenant::query()->create(['name' => 'Beta Corp', 'slug' => 'beta-corp', 'status' => 'active', 'plan_tier' => 'enterprise']);

        /** @var TenantContext $context */
        $context = app(TenantContext::class);

        $connA = $context->runFor($tenantA, static fn () => YeastarConnection::query()->create([
            'name' => 'Alpha P570',
            'transport_mode' => YeastarTransportMode::DirectHttps,
            'pbx_base_url' => 'https://alpha.ras.yeastar.com',
            'client_id' => 'alpha_client',
            'client_secret_enc' => 'alpha_super_secret',
            'status' => ConnectionStatus::Connected,
        ]));

        $context->runFor($tenantA, static fn () => CallLog::query()->create([
            'yeastar_connection_id' => $connA->id,
            'yeastar_call_id' => 'ys-call-alpha-1',
            'direction' => CallDirection::Inbound,
            'caller_number' => '+15550101',
            'callee_number' => '1001',
            'extension' => '1001',
            'status_code' => '200',
            'duration_sec' => 45,
            'started_at' => now(),
        ]));

        // Tenant B cannot see Tenant A's Yeastar connections or call logs
        $context->runFor($tenantB, function () use ($connA): void {
            $this->assertSame(0, YeastarConnection::query()->count());
            $this->assertNull(YeastarConnection::query()->find($connA->id));
            $this->assertSame(0, CallLog::query()->count());
        });

        // Cross-tenant write attempt under Tenant B's context targeting Tenant A is blocked
        $this->expectException(MissingTenantContextException::class);

        $context->runFor($tenantB, static function () use ($tenantA): void {
            YeastarConnection::query()->create([
                'tenant_id' => $tenantA->id,
                'name' => 'Spoofed PBX',
                'transport_mode' => YeastarTransportMode::TunnelAgent,
                'status' => ConnectionStatus::Pending,
            ]);
        });
    }

    public function test_user_cannot_switch_to_unauthorized_tenant_workspace(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $tenantA = Tenant::query()->create(['name' => 'Workspace A', 'slug' => 'workspace-a', 'status' => 'active', 'plan_tier' => 'standard']);
        $tenantB = Tenant::query()->create(['name' => 'Workspace B', 'slug' => 'workspace-b', 'status' => 'active', 'plan_tier' => 'enterprise']);

        $tenantA->addMember($userA, TenantRole::Owner, isDefault: true);
        $tenantB->addMember($userB, TenantRole::Owner, isDefault: true);

        $response = $this->actingAs($userA)->post(route('tenants.switch'), [
            'tenant_id' => $tenantB->id,
        ]);

        $response->assertForbidden();
    }

    public function test_dashboard_applies_security_headers_and_never_leaks_encrypted_secrets(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::query()->create(['name' => 'Secure Telecom', 'slug' => 'secure-telecom', 'status' => 'active', 'plan_tier' => 'enterprise']);
        $tenant->addMember($user, TenantRole::Owner, isDefault: true);

        /** @var TenantContext $context */
        $context = app(TenantContext::class);

        $context->runFor($tenant, static function (): void {
            BitrixConnection::query()->create([
                'member_id' => 'member_secure_123',
                'domain' => 'secure.bitrix24.com',
                'portal_type' => 'cloud',
                'client_endpoint' => 'https://secure.bitrix24.com/rest/',
                'access_token_enc' => 'b24_live_access_secret_value',
                'refresh_token_enc' => 'b24_live_refresh_secret_value',
                'application_token_enc' => 'b24_app_token_secret_value',
                'status' => ConnectionStatus::Connected,
                'granted_scopes' => ['telephony', 'crm', 'user_basic'],
            ]);

            $pbx = YeastarConnection::query()->create([
                'name' => 'HQ P570',
                'transport_mode' => YeastarTransportMode::TunnelAgent,
                'client_id' => 'ys_client_id',
                'client_secret_enc' => 'ys_super_secret_value',
                'status' => ConnectionStatus::Connected,
                'agent_status' => 'online',
            ]);

            $pbx->rotateAgentToken();
        });

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('frame-ancestors', (string) $response->headers->get('Content-Security-Policy'));

        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString('b24_live_access_secret_value', $body);
        $this->assertStringNotContainsString('b24_live_refresh_secret_value', $body);
        $this->assertStringNotContainsString('b24_app_token_secret_value', $body);
        $this->assertStringNotContainsString('ys_super_secret_value', $body);
        $this->assertStringNotContainsString('access_token_enc', $body);
        $this->assertStringNotContainsString('agent_token_hash', $body);
    }
}
