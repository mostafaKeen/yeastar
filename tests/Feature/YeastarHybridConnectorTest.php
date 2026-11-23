<?php

namespace Tests\Feature;

use App\Enums\ConnectionStatus;
use App\Enums\TenantRole;
use App\Enums\YeastarTransportMode;
use App\Http\Middleware\ResolveTenantMiddleware;
use App\Models\BitrixConnection;
use App\Models\ExtensionMapping;
use App\Models\Tenant;
use App\Models\User;
use App\Models\YeastarConnection;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YeastarHybridConnectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_https_rejects_ssrf_private_ip_and_verifies_firmware_on_safe_fqdn(): void
    {
        $owner = User::factory()->create();
        $tenant = Tenant::query()->create(['name' => 'Direct PBX Org', 'slug' => 'direct-pbx-org', 'status' => 'active', 'plan_tier' => 'standard']);
        $tenant->addMember($owner, TenantRole::Owner, isDefault: true);

        // Private LAN IP is blocked in Direct HTTPS mode
        $this->actingAs($owner)
            ->withSession([ResolveTenantMiddleware::SESSION_KEY => $tenant->id])
            ->post(route('integrations.yeastar.store'), [
                'name' => 'Unsafe Local PBX',
                'transport_mode' => YeastarTransportMode::DirectHttps->value,
                'pbx_base_url' => 'https://192.168.1.150:8088',
                'client_id' => 'cid',
                'client_secret' => 'csec',
            ])
            ->assertSessionHasErrors('pbx_base_url');

        // Safe Yeastar FQDN succeeds
        $this->actingAs($owner)
            ->withSession([ResolveTenantMiddleware::SESSION_KEY => $tenant->id])
            ->post(route('integrations.yeastar.store'), [
                'name' => 'HQ P570',
                'transport_mode' => YeastarTransportMode::DirectHttps->value,
                'pbx_base_url' => 'https://hq.ras.yeastar.com',
                'client_id' => 'ys_api_client',
                'client_secret' => 'ys_api_secret',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        /** @var TenantContext $context */
        $context = app(TenantContext::class);
        $conn = $context->runFor($tenant, static fn () => YeastarConnection::query()->firstOrFail());

        Http::fake([
            'https://hq.ras.yeastar.com/openapi/v1.0/get_token' => Http::response([
                'errcode' => 0,
                'access_token' => 'ys_30min_token',
                'access_token_expire_time' => 1800,
            ], 200),
            'https://hq.ras.yeastar.com/openapi/v1.0/system/info*' => Http::response([
                'errcode' => 0,
                'firmware_version' => '37.14.0.18',
            ], 200),
        ]);

        $this->actingAs($owner)
            ->withSession([ResolveTenantMiddleware::SESSION_KEY => $tenant->id])
            ->post(route('integrations.yeastar.test', ['connection' => $conn->id]))
            ->assertRedirect()
            ->assertSessionHas('status');

        $updated = $context->runFor($tenant, static fn () => $conn->fresh());
        $this->assertSame('37.14.0.18', $updated?->firmware_version);
        $this->assertSame(ConnectionStatus::Connected, $updated?->status);
    }

    public function test_on_premise_tunnel_agent_authenticates_and_updates_heartbeat(): void
    {
        $owner = User::factory()->create();
        $tenant = Tenant::query()->create(['name' => 'NAT Office Org', 'slug' => 'nat-office-org', 'status' => 'active', 'plan_tier' => 'standard']);
        $tenant->addMember($owner, TenantRole::Owner, isDefault: true);

        $response = $this->actingAs($owner)
            ->withSession([ResolveTenantMiddleware::SESSION_KEY => $tenant->id])
            ->post(route('integrations.yeastar.store'), [
                'name' => 'Private Branch P560',
                'transport_mode' => YeastarTransportMode::TunnelAgent->value,
            ]);

        $response->assertRedirect();
        $tokenPayload = session('generated_agent_token');
        $this->assertIsArray($tokenPayload);
        $plaintextToken = (string) $tokenPayload['token'];
        $this->assertStringStartsWith('ysa_', $plaintextToken);

        // Unauthorized token is rejected
        $this->withToken('ysa_forged_token')
            ->postJson(route('api.agent.heartbeat'), ['firmware_version' => '37.12.0.10'])
            ->assertUnauthorized();

        // Valid agent token authenticates and brings PBX online
        $this->withToken($plaintextToken)
            ->postJson(route('api.agent.heartbeat'), [
                'firmware_version' => '37.12.0.10',
                'pbx_reachable' => true,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        /** @var TenantContext $context */
        $context = app(TenantContext::class);
        $conn = $context->runFor($tenant, static fn () => YeastarConnection::query()->firstOrFail());
        $this->assertSame('online', $conn->agent_status);
        $this->assertSame('37.12.0.10', $conn->firmware_version);
        $this->assertSame(ConnectionStatus::Connected, $conn->status);

        // Agent poll endpoint returns command array cleanly
        $this->withToken($plaintextToken)
            ->postJson(route('api.agent.poll'))
            ->assertOk()
            ->assertJsonStructure(['commands']);
    }

    public function test_auto_match_correlates_bitrix_users_and_yeastar_extensions_by_email_and_enforces_rbac(): void
    {
        $owner = User::factory()->create();
        $agentUser = User::factory()->create();
        $tenant = Tenant::query()->create(['name' => 'Sales Org', 'slug' => 'sales-org', 'status' => 'active', 'plan_tier' => 'enterprise']);
        $tenant->addMember($owner, TenantRole::Owner, isDefault: true);
        $tenant->addMember($agentUser, TenantRole::Agent, isDefault: true);

        /** @var TenantContext $context */
        $context = app(TenantContext::class);

        $pbx = $context->runFor($tenant, static function (): YeastarConnection {
            BitrixConnection::query()->create([
                'member_id' => 'member_sales_1',
                'domain' => 'sales.bitrix24.com',
                'portal_type' => 'cloud',
                'client_endpoint' => 'https://sales.bitrix24.com/rest/',
                'access_token_enc' => 'b24_tok',
                'refresh_token_enc' => 'b24_ref',
                'token_expires_at' => now()->addHour(),
                'status' => ConnectionStatus::Connected,
            ]);

            return YeastarConnection::query()->create([
                'name' => 'Sales P570',
                'transport_mode' => YeastarTransportMode::DirectHttps,
                'pbx_base_url' => 'https://sales.ras.yeastar.com',
                'client_id' => 'ys_cid',
                'client_secret_enc' => 'ys_csec',
                'access_token_enc' => 'ys_tok',
                'token_expires_at' => now()->addMinutes(25),
                'status' => ConnectionStatus::Connected,
            ]);
        });

        // Agent role cannot run auto-match (RBAC enforced)
        $this->actingAs($agentUser)
            ->withSession([ResolveTenantMiddleware::SESSION_KEY => $tenant->id])
            ->post(route('mappings.auto-match'), ['yeastar_connection_id' => $pbx->id])
            ->assertForbidden();

        Http::fake([
            'https://sales.bitrix24.com/rest/user.get.json' => Http::response([
                'result' => [
                    ['ID' => '101', 'NAME' => 'Alice', 'LAST_NAME' => 'Chen', 'EMAIL' => 'Alice@sales.example'],
                    ['ID' => '102', 'NAME' => 'Bob', 'LAST_NAME' => 'Smith', 'EMAIL' => 'bob@sales.example'],
                ],
            ], 200),
            'https://sales.ras.yeastar.com/openapi/v1.0/extension/list*' => Http::response([
                'errcode' => 0,
                'data' => [
                    ['number' => '1001', 'caller_id_name' => 'Alice Chen', 'email_addr' => 'alice@sales.example'],
                    ['number' => '1002', 'caller_id_name' => 'Unmatched User', 'email_addr' => 'other@sales.example'],
                ],
            ], 200),
        ]);

        // Owner runs 1-Click Auto-Match
        $this->actingAs($owner)
            ->withSession([ResolveTenantMiddleware::SESSION_KEY => $tenant->id])
            ->post(route('mappings.auto-match'), ['yeastar_connection_id' => $pbx->id])
            ->assertRedirect()
            ->assertSessionHas('status');

        $mappings = $context->runFor($tenant, static fn () => ExtensionMapping::query()->get());
        $this->assertCount(1, $mappings);
        $this->assertSame('101', $mappings[0]->bitrix_user_id);
        $this->assertSame('1001', $mappings[0]->yeastar_extension);
    }
}
