<?php

namespace Tests\Feature;

use App\Enums\ConnectionStatus;
use App\Models\ApiAuditLog;
use App\Models\BitrixConnection;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Security\SecretRedactor;
use App\Services\Bitrix24\BitrixApiException;
use App\Services\Bitrix24\BitrixClient;
use App\Services\Bitrix24\BitrixRateLimiter;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BitrixMarketplaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketplace_install_provisions_tenant_and_binds_telephony_events(): void
    {
        Http::fake([
            'https://acme.bitrix24.com/rest/event.bind.json' => Http::response(['result' => true], 200),
        ]);

        $response = $this->postJson(route('api.bitrix.install'), [
            'event' => 'ONAPPINSTALL',
            'auth' => [
                'member_id' => 'member_acme_001',
                'domain' => 'acme.bitrix24.com',
                'client_endpoint' => 'https://acme.bitrix24.com/rest/',
                'server_endpoint' => 'https://oauth.bitrix.info/rest/',
                'access_token' => 'live_access_secret_1',
                'refresh_token' => 'live_refresh_secret_1',
                'application_token' => 'live_app_token_secret_1',
                'expires_in' => 3600,
                'scope' => 'telephony,crm,user_basic',
            ],
        ]);

        $response->assertOk()->assertJsonPath('status', 'installed');

        /** @var TenantContext $context */
        $context = app(TenantContext::class);

        $connection = $context->withoutTenancy(static fn () => BitrixConnection::query()->where('member_id', 'member_acme_001')->first());
        $this->assertNotNull($connection);
        $this->assertSame('cloud', $connection->portal_type);
        $this->assertSame(ConnectionStatus::Connected, $connection->status);
        $this->assertTrue($connection->verifyApplicationToken('live_app_token_secret_1'));

        // Verify stored webhook delivery redacted all tokens
        $delivery = $context->runFor($connection->tenant_id, static fn () => WebhookDelivery::query()->first());
        $this->assertNotNull($delivery);
        $this->assertSame(SecretRedactor::MASK, $delivery->sanitized_payload['auth']['access_token']);
        $this->assertSame(SecretRedactor::MASK, $delivery->sanitized_payload['auth']['refresh_token']);
        $this->assertSame(SecretRedactor::MASK, $delivery->sanitized_payload['auth']['application_token']);
    }

    public function test_bitrix_event_middleware_verifies_application_token_and_handles_uninstall(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Verify Org', 'slug' => 'verify-org', 'status' => 'active', 'plan_tier' => 'standard']);

        /** @var TenantContext $context */
        $context = app(TenantContext::class);

        $context->runFor($tenant, static function (): void {
            BitrixConnection::query()->create([
                'member_id' => 'member_verify_99',
                'domain' => 'verify.bitrix24.com',
                'portal_type' => 'cloud',
                'client_endpoint' => 'https://verify.bitrix24.com/rest/',
                'access_token_enc' => 'tok_1',
                'refresh_token_enc' => 'ref_1',
                'application_token_enc' => 'valid_secret_app_token',
                'status' => ConnectionStatus::Connected,
            ]);
        });

        // Invalid application token is rejected
        $this->postJson(route('api.bitrix.events'), [
            'event' => 'ONEXTERNALCALLSTART',
            'ts' => time(),
            'auth' => [
                'member_id' => 'member_verify_99',
                'application_token' => 'wrong_token',
            ],
        ])->assertUnauthorized();

        // Stale timestamp is rejected
        $this->postJson(route('api.bitrix.events'), [
            'event' => 'ONEXTERNALCALLSTART',
            'ts' => time() - 1000,
            'auth' => [
                'member_id' => 'member_verify_99',
                'application_token' => 'valid_secret_app_token',
            ],
        ])->assertUnauthorized();

        // Valid event is accepted quickly
        $this->postJson(route('api.bitrix.events'), [
            'event' => 'ONEXTERNALCALLSTART',
            'ts' => time(),
            'data' => [
                'CALL_ID' => 'externalCall.abc.123',
                'PHONE_NUMBER' => '+15551234567',
                'USER_ID' => 42,
            ],
            'auth' => [
                'member_id' => 'member_verify_99',
                'application_token' => 'valid_secret_app_token',
            ],
        ])->assertOk()->assertJsonPath('status', 'accepted');

        // Uninstall revokes tokens cleanly
        $this->postJson(route('api.bitrix.uninstall'), [
            'event' => 'ONAPPUNINSTALL',
            'ts' => time(),
            'auth' => [
                'member_id' => 'member_verify_99',
                'application_token' => 'valid_secret_app_token',
            ],
        ])->assertOk()->assertJsonPath('status', 'uninstalled');

        $revoked = $context->runFor($tenant, static fn () => BitrixConnection::query()->first());
        $this->assertSame(ConnectionStatus::Revoked, $revoked?->status);
        $this->assertNull($revoked?->access_token_enc);
        $this->assertNull($revoked?->refresh_token_enc);
    }

    public function test_bitrix_client_refreshes_expired_oauth_token_and_parses_batch_errors(): void
    {
        config()->set('telephony.bitrix24.client_id', 'app.marketplace.id');
        config()->set('telephony.bitrix24.client_secret', 'app.marketplace.secret');

        $tenant = Tenant::query()->create(['name' => 'OAuth Org', 'slug' => 'oauth-org', 'status' => 'active', 'plan_tier' => 'enterprise']);

        /** @var TenantContext $context */
        $context = app(TenantContext::class);

        $connection = $context->runFor($tenant, static fn () => BitrixConnection::query()->create([
            'member_id' => 'member_oauth_77',
            'domain' => 'oauth.bitrix24.com',
            'portal_type' => 'cloud',
            'client_endpoint' => 'https://oauth.bitrix24.com/rest/',
            'access_token_enc' => 'expired_tok',
            'refresh_token_enc' => 'valid_ref_tok',
            'application_token_enc' => 'app_tok_77',
            'token_expires_at' => now()->subMinutes(5),
            'status' => ConnectionStatus::Connected,
        ]));

        Http::fake([
            'https://oauth.bitrix.info/oauth/token/*' => Http::response([
                'access_token' => 'brand_new_access_tok',
                'refresh_token' => 'brand_new_refresh_tok',
                'expires_in' => 3600,
            ], 200),
            'https://oauth.bitrix24.com/rest/batch.json' => Http::response([
                'result' => [
                    'result' => ['cmd_ok' => ['ID' => 1]],
                    'result_error' => ['cmd_bad' => ['error' => 'NOT_FOUND', 'error_description' => 'Entity missing']],
                ],
                'time' => ['operating' => 350.5, 'operating_reset_at' => time() + 300],
            ], 200),
        ]);

        $batchResult = $context->runFor($tenant, function () use ($connection): array {
            return app(BitrixClient::class)->batch($connection, [
                'cmd_ok' => 'user.get?ID=1',
                'cmd_bad' => 'crm.contact.get?ID=999999',
            ], highPriority: true);
        });

        $this->assertTrue($batchResult['has_errors']);
        $this->assertArrayHasKey('cmd_bad', $batchResult['errors']);

        // Token was rotated using central Marketplace credentials
        $fresh = $context->runFor($tenant, static fn () => $connection->fresh());
        $this->assertSame('brand_new_access_tok', $fresh?->access_token_enc);
        $this->assertSame('brand_new_refresh_tok', $fresh?->refresh_token_enc);

        // Audit log recorded cleanly
        $auditCount = $context->runFor($tenant, static fn () => ApiAuditLog::query()->where('provider', 'bitrix24')->count());
        $this->assertSame(1, $auditCount);

        // Operating time governor defers subsequent low-priority background calls to preserve real-time headroom
        $this->expectException(BitrixApiException::class);
        app(BitrixRateLimiter::class)->acquire($tenant, 'batch', highPriority: false);
    }
}
