<?php

namespace Tests\Feature;

use App\Enums\ConnectionStatus;
use App\Enums\TenantRole;
use App\Enums\YeastarTransportMode;
use App\Http\Middleware\ResolveTenantMiddleware;
use App\Jobs\AttachCallRecordingJob;
use App\Models\BitrixConnection;
use App\Models\CallLog;
use App\Models\ExtensionMapping;
use App\Models\ExternalLine;
use App\Models\Tenant;
use App\Models\User;
use App\Models\YeastarConnection;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelephonyEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbound_ring_group_call_lifecycle_registers_shows_hides_and_finishes(): void
    {
        Queue::fake([AttachCallRecordingJob::class]);
        [$tenant, $pbx] = $this->seedConnectedTenant();

        Http::fake([
            'https://tel.bitrix24.com/rest/telephony.externalCall.searchCrmEntities.json' => Http::response([
                'result' => [
                    ['CRM_ENTITY_TYPE' => 'CONTACT', 'CRM_ENTITY_ID' => 501, 'ASSIGNED_BY_ID' => 101],
                ],
            ], 200),
            'https://tel.bitrix24.com/rest/telephony.externalCall.register.json' => Http::response([
                'result' => [
                    'CALL_ID' => 'externalCall.in.777',
                    'CRM_ENTITY_TYPE' => 'CONTACT',
                    'CRM_ENTITY_ID' => 501,
                ],
            ], 200),
            'https://tel.bitrix24.com/rest/telephony.externalCall.show.json' => Http::response(['result' => true], 200),
            'https://tel.bitrix24.com/rest/telephony.externalCall.hide.json' => Http::response(['result' => true], 200),
            'https://tel.bitrix24.com/rest/telephony.externalCall.finish.json' => Http::response([
                'result' => ['CALL_ID' => 'externalCall.in.777', 'CRM_ACTIVITY_ID' => 9001],
            ], 200),
        ]);

        // 1. Ringing across extensions 1001 and 1002
        $this->postJson(route('api.yeastar.webhook', ['webhookUlid' => $pbx->webhook_ulid]), [
            'event' => 'ringing',
            'call_id' => 'ys-in-999',
            'caller_number' => '+15550199',
            'callee_number' => 'line-main',
            'target_extensions' => ['1001', '1002'],
        ])->assertOk();

        // 2. Agent on extension 1002 (Bitrix User 102) answers -> hide card for 101
        $this->postJson(route('api.yeastar.webhook', ['webhookUlid' => $pbx->webhook_ulid]), [
            'event' => 'answered',
            'call_id' => 'ys-in-999',
            'extension' => '1002',
        ])->assertOk();

        // 3. Call ends with ANSWERED and recording file ready
        $this->postJson(route('api.yeastar.webhook', ['webhookUlid' => $pbx->webhook_ulid]), [
            'event' => 'hangup',
            'call_id' => 'ys-in-999',
            'disposition' => 'ANSWERED',
            'duration' => 75,
            'talk_duration' => 68,
            'extension' => '1002',
            'record_file' => '20261123-ys-in-999.mp3',
        ])->assertOk();

        Http::assertSent(static fn (HttpClientRequest $req): bool => str_contains($req->url(), 'telephony.externalCall.show')
            && $req['CALL_ID'] === 'externalCall.in.777'
            && $req['USER_ID'] === [101, 102]);

        Http::assertSent(static fn (HttpClientRequest $req): bool => str_contains($req->url(), 'telephony.externalCall.hide')
            && $req['CALL_ID'] === 'externalCall.in.777'
            && $req['USER_ID'] === [101]);

        Http::assertSent(static fn (HttpClientRequest $req): bool => str_contains($req->url(), 'telephony.externalCall.finish')
            && $req['CALL_ID'] === 'externalCall.in.777'
            && (int) $req['USER_ID'] === 102
            && (int) $req['DURATION'] === 68
            && $req['STATUS_CODE'] === '200');

        /** @var TenantContext $context */
        $context = app(TenantContext::class);
        $call = $context->runFor($tenant, static fn () => CallLog::query()->where('yeastar_call_id', 'ys-in-999')->firstOrFail());

        $this->assertSame('externalCall.in.777', $call->bitrix_call_id);
        $this->assertSame('102', $call->bitrix_user_id);
        $this->assertSame('9001', $call->crm_activity_id);
        $this->assertSame('200', $call->status_code);
        $this->assertSame('pending', $call->recording_status);
        $this->assertSame('20261123-ys-in-999.mp3', $call->yeastar_recording_id);
        Queue::assertPushed(AttachCallRecordingJob::class, static fn (AttachCallRecordingJob $job): bool => $job->callLogId === $call->id);
    }

    public function test_outbound_click_to_call_dials_yeastar_without_re_registering_and_finishes_missed(): void
    {
        [$tenant, $pbx] = $this->seedConnectedTenant();

        Http::fake([
            'https://tel.ras.yeastar.com/openapi/v1.0/call/dial*' => Http::response([
                'errcode' => 0,
                'call_id' => 'ys-out-555',
            ], 200),
            'https://tel.bitrix24.com/rest/telephony.externalCall.finish.json' => Http::response([
                'result' => ['CALL_ID' => 'externalCall.out.1', 'CRM_ACTIVITY_ID' => 9100],
            ], 200),
        ]);

        // 1. Bitrix24 sends ONEXTERNALCALLSTART
        $this->postJson(route('api.bitrix.events'), [
            'event' => 'ONEXTERNALCALLSTART',
            'ts' => time(),
            'data' => [
                'CALL_ID' => 'externalCall.out.1',
                'PHONE_NUMBER' => '+15559990000',
                'USER_ID' => '101',
                'CRM_ENTITY_TYPE' => 'LEAD',
                'CRM_ENTITY_ID' => '77',
            ],
            'auth' => [
                'member_id' => 'member_tel_1',
                'application_token' => 'tel_app_token_secret',
            ],
        ])->assertOk();

        // Ensure Bitrix24 register was NEVER called for an outbound click-to-call
        Http::assertNotSent(static fn (HttpClientRequest $req): bool => str_contains($req->url(), 'telephony.externalCall.register'));

        Http::assertSent(static fn (HttpClientRequest $req): bool => str_contains($req->url(), '/openapi/v1.0/call/dial')
            && $req['caller'] === '1001'
            && $req['callee'] === '+15559990000');

        // 2. Yeastar reports NO ANSWER hangup
        $this->postJson(route('api.yeastar.webhook', ['webhookUlid' => $pbx->webhook_ulid]), [
            'event' => 'hangup',
            'call_id' => 'ys-out-555',
            'disposition' => 'NO ANSWER',
            'duration' => 0,
            'talk_duration' => 0,
        ])->assertOk();

        Http::assertSent(static fn (HttpClientRequest $req): bool => str_contains($req->url(), 'telephony.externalCall.finish')
            && $req['CALL_ID'] === 'externalCall.out.1'
            && $req['STATUS_CODE'] === '304');
    }

    public function test_call_explorer_and_diagnostics_enforce_rbac_and_render_cleanly(): void
    {
        [$tenant, $pbx] = $this->seedConnectedTenant();

        $owner = User::factory()->create(['email' => 'owner@tel.example']);
        $agent = User::factory()->create(['email' => 'alice@tel.example']);
        $tenant->addMember($owner, TenantRole::Owner, isDefault: true);
        $tenant->addMember($agent, TenantRole::Agent, isDefault: true);

        /** @var TenantContext $context */
        $context = app(TenantContext::class);
        $context->runFor($tenant, static function () use ($pbx): void {
            CallLog::query()->create([
                'yeastar_connection_id' => $pbx->id,
                'yeastar_call_id' => 'ys-call-ext-1001',
                'direction' => 'inbound',
                'caller_number' => '+15550101',
                'callee_number' => '1001',
                'extension' => '1001',
                'status_code' => '200',
                'duration_sec' => 42,
                'talk_duration_sec' => 40,
                'started_at' => now(),
            ]);

            CallLog::query()->create([
                'yeastar_connection_id' => $pbx->id,
                'yeastar_call_id' => 'ys-call-ext-1002',
                'direction' => 'outbound',
                'caller_number' => '1002',
                'callee_number' => '+15550202',
                'extension' => '1002',
                'status_code' => '200',
                'duration_sec' => 30,
                'talk_duration_sec' => 28,
                'started_at' => now(),
            ]);
        });

        // Owner sees both calls and can access Diagnostics
        $this->actingAs($owner)
            ->withSession([ResolveTenantMiddleware::SESSION_KEY => $tenant->id])
            ->get(route('calls.index'))
            ->assertOk()
            ->assertSee('ys-call-ext-1001')
            ->assertSee('ys-call-ext-1002');

        $this->actingAs($owner)
            ->withSession([ResolveTenantMiddleware::SESSION_KEY => $tenant->id])
            ->get(route('diagnostics.index'))
            ->assertOk();

        // Agent (alice@tel.example -> ext 1001) sees only ext 1001 and is forbidden from Diagnostics
        $this->actingAs($agent)
            ->withSession([ResolveTenantMiddleware::SESSION_KEY => $tenant->id])
            ->get(route('calls.index'))
            ->assertOk()
            ->assertSee('ys-call-ext-1001')
            ->assertDontSee('ys-call-ext-1002');

        $this->actingAs($agent)
            ->withSession([ResolveTenantMiddleware::SESSION_KEY => $tenant->id])
            ->get(route('diagnostics.index'))
            ->assertForbidden();
    }

    /**
     * @return array{0: Tenant, 1: YeastarConnection}
     */
    private function seedConnectedTenant(): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tel Co',
            'slug' => 'tel-co',
            'status' => 'active',
            'plan_tier' => 'enterprise',
            'settings' => [
                'enable_call_popups' => true,
                'enable_click_to_call' => true,
                'enable_recording_stream' => true,
                'auto_create_crm_lead' => false,
            ],
        ]);

        /** @var TenantContext $context */
        $context = app(TenantContext::class);

        $pbx = $context->runFor($tenant, static function (): YeastarConnection {
            BitrixConnection::query()->create([
                'member_id' => 'member_tel_1',
                'domain' => 'tel.bitrix24.com',
                'portal_type' => 'cloud',
                'client_endpoint' => 'https://tel.bitrix24.com/rest/',
                'access_token_enc' => 'tel_access_tok',
                'refresh_token_enc' => 'tel_refresh_tok',
                'application_token_enc' => 'tel_app_token_secret',
                'token_expires_at' => now()->addHour(),
                'status' => ConnectionStatus::Connected,
            ]);

            $conn = YeastarConnection::query()->create([
                'name' => 'Main P570',
                'transport_mode' => YeastarTransportMode::DirectHttps,
                'pbx_base_url' => 'https://tel.ras.yeastar.com',
                'client_id' => 'ys_tel_id',
                'client_secret_enc' => 'ys_tel_secret',
                'access_token_enc' => 'ys_live_token',
                'token_expires_at' => now()->addMinutes(25),
                'status' => ConnectionStatus::Connected,
            ]);

            ExternalLine::query()->create([
                'yeastar_connection_id' => $conn->id,
                'bitrix_line_number' => 'line-main',
                'bitrix_line_name' => 'Main Trunk',
                'is_default' => true,
                'is_active' => true,
            ]);

            ExtensionMapping::query()->create([
                'yeastar_connection_id' => $conn->id,
                'bitrix_user_id' => '101',
                'bitrix_user_name' => 'Alice Agent',
                'bitrix_user_email' => 'alice@tel.example',
                'yeastar_extension' => '1001',
                'is_active' => true,
                'click_to_call_enabled' => true,
                'popup_enabled' => true,
            ]);

            ExtensionMapping::query()->create([
                'yeastar_connection_id' => $conn->id,
                'bitrix_user_id' => '102',
                'bitrix_user_name' => 'Bob Agent',
                'bitrix_user_email' => 'bob@tel.example',
                'yeastar_extension' => '1002',
                'is_active' => true,
                'click_to_call_enabled' => true,
                'popup_enabled' => true,
            ]);

            return $conn;
        });

        return [$tenant, $pbx];
    }
}
