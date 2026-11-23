<?php

namespace App\Http\Controllers;

use App\Enums\ConnectionStatus;
use App\Models\BitrixConnection;
use App\Models\CallLog;
use App\Models\ExtensionMapping;
use App\Models\ExternalLine;
use App\Models\YeastarConnection;
use App\Security\SsrfGuard;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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
                'is_admin' => (bool) ($payload['result']['ADMIN'] ?? false),
            ];
        } catch (Throwable $e) {
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                throw $e;
            }
            abort(502, 'Unable to verify Bitrix24 session with portal.');
        }

        return $this->tenantContext->runFor($connection->tenant_id, function () use ($connection, $b24User): Response {
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
                'consoleUrls' => [
                    'dashboard' => route('dashboard'),
                    'onboarding' => route('onboarding.index'),
                    'mappings' => route('mappings.index'),
                ],
            ]);
        });
    }
}
