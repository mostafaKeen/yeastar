<?php

namespace Tests\Feature;

use App\Enums\CallDirection;
use App\Enums\ConnectionStatus;
use App\Enums\TenantRole;
use App\Enums\YeastarTransportMode;
use App\Http\Middleware\ResolveTenantMiddleware;
use App\Jobs\AttachCallRecordingJob;
use App\Jobs\SyncCrmPhonebookJob;
use App\Models\BitrixConnection;
use App\Models\CallLog;
use App\Models\ExtensionMapping;
use App\Models\Tenant;
use App\Models\User;
use App\Models\YeastarConnection;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RecordingAndPhonebookSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_disk_recording_job_streams_from_yeastar_directly_to_bitrix_upload_url(): void
    {
        [$tenant, $pbx] = $this->seedTenant();

        /** @var TenantContext $context */
        $context = app(TenantContext::class);

        $call = $context->runFor($tenant, static fn () => CallLog::query()->create([
            'yeastar_connection_id' => $pbx->id,
            'yeastar_call_id' => 'ys-rec-100',
            'bitrix_call_id' => 'externalCall.rec.100',
            'direction' => CallDirection::Inbound,
            'caller_number' => '+15550111',
            'callee_number' => '1001',
            'extension' => '1001',
            'bitrix_user_id' => '101',
            'status_code' => '200',
            'duration_sec' => 60,
            'talk_duration_sec' => 55,
            'recording_status' => 'pending',
            'yeastar_recording_id' => 'call-rec-100.mp3',
            'started_at' => now(),
        ]));

        Http::fake([
            'https://media.ras.yeastar.com/openapi/v1.0/recording/download*' => Http::response([
                'errcode' => 0,
                'download_resource_url' => 'https://media.ras.yeastar.com/recordings/tmp-token/call-rec-100.mp3',
            ], 200),
            'https://media.bitrix24.com/rest/telephony.externalCall.attachRecord.json' => Http::response([
                'result' => [
                    'uploadUrl' => 'https://upload.bitrix24.com/disk/upload/abc',
                    'fieldName' => 'file',
                ],
            ], 200),
            'https://media.ras.yeastar.com/recordings/tmp-token/call-rec-100.mp3' => Http::response(
                'FAKE-MP3-BINARY-AUDIO-STREAM',
                200,
                ['Content-Type' => 'audio/mpeg'],
            ),
            'https://upload.bitrix24.com/disk/upload/abc' => Http::response([
                'result' => ['FILE_ID' => 9079],
            ], 200),
        ]);

        AttachCallRecordingJob::dispatchSync($tenant->id, $call->id);

        $updated = $context->runFor($tenant, static fn () => $call->fresh());
        $this->assertSame('attached', $updated?->recording_status);
        $this->assertSame('9079', $updated?->bitrix_file_id);

        Http::assertSent(static fn (HttpClientRequest $req): bool => $req->url() === 'https://upload.bitrix24.com/disk/upload/abc'
            && str_contains($req->body(), 'FAKE-MP3-BINARY-AUDIO-STREAM'));
    }

    public function test_recording_playback_proxy_enforces_rbac_and_strict_cross_tenant_isolation(): void
    {
        [$tenantA, $pbxA] = $this->seedTenant('Alpha Media', 'alpha-media');
        [$tenantB] = $this->seedTenant('Beta Media', 'beta-media');

        $supervisorA = User::factory()->create();
        $agentA = User::factory()->create();
        $ownerB = User::factory()->create();

        $tenantA->addMember($supervisorA, TenantRole::Supervisor, isDefault: true);
        $tenantA->addMember($agentA, TenantRole::Agent, isDefault: true);
        $tenantB->addMember($ownerB, TenantRole::Owner, isDefault: true);

        /** @var TenantContext $context */
        $context = app(TenantContext::class);

        $callA = $context->runFor($tenantA, static fn () => CallLog::query()->create([
            'yeastar_connection_id' => $pbxA->id,
            'yeastar_call_id' => 'ys-play-1',
            'bitrix_call_id' => 'externalCall.play.1',
            'direction' => CallDirection::Inbound,
            'caller_number' => '+15550111',
            'callee_number' => '1001',
            'extension' => '1001',
            'status_code' => '200',
            'duration_sec' => 30,
            'recording_status' => 'attached',
            'yeastar_recording_id' => 'play-1.mp3',
            'bitrix_file_id' => '9080',
            'started_at' => now(),
        ]));

        Http::fake([
            'https://media.ras.yeastar.com/openapi/v1.0/recording/download*' => Http::response([
                'errcode' => 0,
                'download_resource_url' => 'https://media.ras.yeastar.com/recordings/play-1.mp3',
            ], 200),
            'https://media.ras.yeastar.com/recordings/play-1.mp3' => Http::response(
                'STREAMED-AUDIO-BYTES',
                206,
                [
                    'Content-Type' => 'audio/mpeg',
                    'Content-Range' => 'bytes 0-19/20',
                    'Content-Length' => '20',
                ],
            ),
        ]);

        // Supervisor in Tenant A streams audio cleanly with Range headers
        $res = $this->actingAs($supervisorA)
            ->withSession([ResolveTenantMiddleware::SESSION_KEY => $tenantA->id])
            ->withHeaders(['Range' => 'bytes=0-19'])
            ->get(route('calls.recording', ['callLogId' => $callA->id]));

        $res->assertStatus(206);
        $res->assertHeader('Accept-Ranges', 'bytes');
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('STREAMED-AUDIO-BYTES', $res->streamedContent());

        // Agent in Tenant A is forbidden from streaming recordings
        $this->actingAs($agentA)
            ->withSession([ResolveTenantMiddleware::SESSION_KEY => $tenantA->id])
            ->get(route('calls.recording', ['callLogId' => $callA->id]))
            ->assertForbidden();

        // Owner in Tenant B cannot access Tenant A's recording (404 via TenantScope)
        $this->actingAs($ownerB)
            ->withSession([ResolveTenantMiddleware::SESSION_KEY => $tenantB->id])
            ->get(route('calls.recording', ['callLogId' => $callA->id]))
            ->assertNotFound();
    }

    public function test_crm_phonebook_sync_uses_id_pagination_and_pushes_to_yeastar_phonebook(): void
    {
        [$tenant, $pbx] = $this->seedTenant();

        Http::fake([
            'https://media.bitrix24.com/rest/crm.contact.list.json' => Http::response([
                'result' => [
                    [
                        'ID' => '15',
                        'NAME' => 'Jane',
                        'LAST_NAME' => 'Doe',
                        'PHONE' => [['VALUE' => '+15550123']],
                        'EMAIL' => [['VALUE' => 'jane@client.example']],
                    ],
                ],
            ], 200),
            'https://media.ras.yeastar.com/openapi/v1.0/contact/create*' => Http::response([
                'errcode' => 0,
                'errmsg' => 'SUCCESS',
            ], 200),
        ]);

        SyncCrmPhonebookJob::dispatchSync($tenant->id, $pbx->id);

        Http::assertSent(static fn (HttpClientRequest $req): bool => str_contains($req->url(), 'crm.contact.list')
            && $req['start'] === -1);

        Http::assertSent(static fn (HttpClientRequest $req): bool => str_contains($req->url(), '/openapi/v1.0/contact/create')
            && $req['first_name'] === 'Jane'
            && $req['phonebook_name'] === 'CRM_Synchronization');
    }

    public function test_on_external_callback_start_dials_yeastar_and_registers_callback_call(): void
    {
        [$tenant] = $this->seedTenant();

        Http::fake([
            'https://media.bitrix24.com/rest/telephony.externalCall.searchCrmEntities.json' => Http::response([
                'result' => [
                    ['CRM_ENTITY_TYPE' => 'CONTACT', 'CRM_ENTITY_ID' => 300, 'ASSIGNED_BY_ID' => 101],
                ],
            ], 200),
            'https://media.ras.yeastar.com/openapi/v1.0/call/dial*' => Http::response([
                'errcode' => 0,
                'call_id' => 'ys-cb-400',
            ], 200),
            'https://media.bitrix24.com/rest/telephony.externalCall.register.json' => Http::response([
                'result' => ['CALL_ID' => 'externalCall.cb.400'],
            ], 200),
        ]);

        $this->postJson(route('api.bitrix.events'), [
            'event' => 'ONEXTERNALCALLBACKSTART',
            'ts' => time(),
            'data' => [
                'PHONE_NUMBER' => '+494951234567',
                'CRM_ENTITY_TYPE' => 'CONTACT',
                'CRM_ENTITY_ID' => '300',
                'LINE_NUMBER' => 'line-cb-1',
            ],
            'auth' => [
                'member_id' => 'member_media-org',
                'application_token' => 'media_app_token_secret',
            ],
        ])->assertOk();

        Http::assertSent(static fn (HttpClientRequest $req): bool => str_contains($req->url(), '/openapi/v1.0/call/dial')
            && $req['caller'] === '1001'
            && $req['callee'] === '+494951234567');

        Http::assertSent(static fn (HttpClientRequest $req): bool => str_contains($req->url(), 'telephony.externalCall.register')
            && (int) $req['TYPE'] === 4);

        /** @var TenantContext $context */
        $context = app(TenantContext::class);
        $call = $context->runFor($tenant, static fn () => CallLog::query()->where('yeastar_call_id', 'ys-cb-400')->firstOrFail());
        $this->assertSame(CallDirection::Callback, $call->direction);
        $this->assertSame('externalCall.cb.400', $call->bitrix_call_id);
    }

    /**
     * @return array{0: Tenant, 1: YeastarConnection}
     */
    private function seedTenant(string $name = 'Media Org', string $slug = 'media-org'): array
    {
        $tenant = Tenant::query()->create([
            'name' => $name,
            'slug' => $slug,
            'status' => 'active',
            'plan_tier' => 'enterprise',
        ]);

        /** @var TenantContext $context */
        $context = app(TenantContext::class);

        $pbx = $context->runFor($tenant, static function () use ($slug): YeastarConnection {
            BitrixConnection::query()->create([
                'member_id' => "member_{$slug}",
                'domain' => 'media.bitrix24.com',
                'portal_type' => 'cloud',
                'client_endpoint' => 'https://media.bitrix24.com/rest/',
                'access_token_enc' => 'b24_tok',
                'refresh_token_enc' => 'b24_ref',
                'application_token_enc' => 'media_app_token_secret',
                'token_expires_at' => now()->addHour(),
                'status' => ConnectionStatus::Connected,
            ]);

            $conn = YeastarConnection::query()->create([
                'name' => 'Media P570',
                'transport_mode' => YeastarTransportMode::DirectHttps,
                'pbx_base_url' => 'https://media.ras.yeastar.com',
                'client_id' => 'ys_cid',
                'client_secret_enc' => 'ys_csec',
                'access_token_enc' => 'ys_tok',
                'token_expires_at' => now()->addMinutes(25),
                'status' => ConnectionStatus::Connected,
            ]);

            ExtensionMapping::query()->create([
                'yeastar_connection_id' => $conn->id,
                'bitrix_user_id' => '101',
                'bitrix_user_name' => 'Agent One',
                'bitrix_user_email' => 'agent1@media.example',
                'yeastar_extension' => '1001',
                'is_active' => true,
                'click_to_call_enabled' => true,
                'popup_enabled' => true,
            ]);

            return $conn;
        });

        return [$tenant, $pbx];
    }
}
