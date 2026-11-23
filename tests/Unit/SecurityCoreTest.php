<?php

namespace Tests\Unit;

use App\Enums\ConnectionStatus;
use App\Enums\YeastarTransportMode;
use App\Models\BitrixConnection;
use App\Models\Tenant;
use App\Models\YeastarConnection;
use App\Security\SecretRedactor;
use App\Security\SsrfGuard;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SecurityCoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_secret_redactor_strips_nested_tokens_and_bearer_headers(): void
    {
        $sanitized = SecretRedactor::redactArray([
            'event' => 'ONEXTERNALCALLSTART',
            'auth' => [
                'member_id' => 'abc123',
                'access_token' => 'secret-access-token',
                'refresh_token' => 'secret-refresh-token',
                'application_token' => 'secret-app-token',
            ],
            'headers' => [
                'Authorization' => 'Bearer top-secret-jwt',
                'X-Request-Id' => 'req-1',
            ],
            'note' => 'Header was Bearer raw-token-here',
        ]);

        $this->assertSame('abc123', $sanitized['auth']['member_id']);
        $this->assertSame(SecretRedactor::MASK, $sanitized['auth']['access_token']);
        $this->assertSame(SecretRedactor::MASK, $sanitized['auth']['refresh_token']);
        $this->assertSame(SecretRedactor::MASK, $sanitized['auth']['application_token']);
        $this->assertSame(SecretRedactor::MASK, $sanitized['headers']['Authorization']);
        $this->assertSame('req-1', $sanitized['headers']['X-Request-Id']);
        $this->assertSame('Header was Bearer [REDACTED]', $sanitized['note']);
    }

    public function test_ssrf_guard_blocks_http_localhost_and_private_ip_ranges(): void
    {
        $guard = new SsrfGuard;

        $this->assertSame(
            'https://pbx.example.com',
            $guard->assertSafeExternalHttpsUrl('https://pbx.example.com/', resolveDns: false),
        );

        foreach ([
            'http://pbx.example.com',
            'https://localhost/api',
            'https://127.0.0.1/api',
            'https://169.254.169.254/latest/meta-data',
            'https://10.0.0.25/openapi',
            'https://192.168.1.50/openapi',
            'https://user:pass@pbx.example.com',
        ] as $unsafeUrl) {
            try {
                $guard->assertSafeExternalHttpsUrl($unsafeUrl, resolveDns: false);
                $this->fail("Expected SsrfGuard to reject unsafe URL: {$unsafeUrl}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_bitrix_application_token_and_yeastar_agent_token_verify_in_constant_time(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Token Check Org',
            'slug' => 'token-check-org',
            'status' => 'active',
            'plan_tier' => 'standard',
        ]);

        /** @var TenantContext $context */
        $context = app(TenantContext::class);

        $context->runFor($tenant, function (): void {
            $b24 = BitrixConnection::query()->create([
                'member_id' => 'member_verify_1',
                'domain' => 'verify.bitrix24.com',
                'portal_type' => 'cloud',
                'client_endpoint' => 'https://verify.bitrix24.com/rest/',
                'application_token_enc' => 'exact_app_token_value',
                'status' => ConnectionStatus::Connected,
            ]);

            $this->assertTrue($b24->verifyApplicationToken('exact_app_token_value'));
            $this->assertFalse($b24->verifyApplicationToken('wrong_app_token_value'));
            $this->assertFalse($b24->verifyApplicationToken(null));

            $ys = YeastarConnection::query()->create([
                'name' => 'Branch P550',
                'transport_mode' => YeastarTransportMode::TunnelAgent,
                'status' => ConnectionStatus::Pending,
            ]);

            $plaintext = $ys->rotateAgentToken();
            $this->assertStringStartsWith('ysa_', $plaintext);
            $this->assertTrue($ys->verifyAgentToken($plaintext));
            $this->assertFalse($ys->verifyAgentToken('ysa_invalid'));
        });
    }
}
