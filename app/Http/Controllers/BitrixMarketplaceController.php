<?php

namespace App\Http\Controllers;

use App\Enums\ConnectionStatus;
use App\Jobs\ProcessBitrixTelephonyEventJob;
use App\Models\BitrixConnection;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Security\SecretRedactor;
use App\Services\Bitrix24\BitrixClient;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Throwable;

class BitrixMarketplaceController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly BitrixClient $bitrixClient,
    ) {}

    /**
     * Handle Bitrix24 Marketplace installation callback (ONAPPINSTALL or iframe install POST).
     * Persists per-tenant OAuth tokens keyed by immutable member_id and completes installation.
     */
    public function install(Request $request): Response|JsonResponse
    {
        $auth = is_array($request->input('auth')) ? $request->input('auth') : [];

        $memberId = (string) ($auth['member_id'] ?? $request->input('member_id') ?? '');
        $domain = (string) ($auth['domain'] ?? $request->input('DOMAIN') ?? $request->query('DOMAIN', ''));
        $accessToken = (string) ($auth['access_token'] ?? $request->input('AUTH_ID') ?? '');
        $refreshToken = (string) ($auth['refresh_token'] ?? $request->input('REFRESH_ID') ?? '');
        $applicationToken = (string) ($auth['application_token'] ?? $request->input('APP_SID') ?? '');
        $expiresIn = max(60, (int) ($auth['expires_in'] ?? $request->input('AUTH_EXPIRES') ?? 3600));

        if ($memberId === '' || $domain === '' || $accessToken === '') {
            return response()->json(['error' => 'Invalid Bitrix24 installation payload.'], 422);
        }

        $cleanDomain = preg_replace('#^https?://#i', '', trim($domain)) ?? $domain;
        $cleanDomain = rtrim($cleanDomain, '/');
        $clientEndpoint = (string) ($auth['client_endpoint'] ?? "https://{$cleanDomain}/rest/");
        $serverEndpoint = (string) ($auth['server_endpoint'] ?? config('telephony.bitrix24.oauth_server', 'https://oauth.bitrix.info/rest/'));
        $portalType = preg_match('/\.bitrix24\.[a-z.]+$/i', $cleanDomain) === 1 ? 'cloud' : 'on_premise';
        $scopes = array_values(array_filter(array_map('trim', explode(',', (string) ($auth['scope'] ?? 'telephony,crm,user_basic')))));

        $connection = $this->tenantContext->withoutTenancy(function () use (
            $memberId,
            $cleanDomain,
            $portalType,
            $clientEndpoint,
            $serverEndpoint,
            $accessToken,
            $refreshToken,
            $applicationToken,
            $expiresIn,
            $scopes,
        ): BitrixConnection {
            $existing = BitrixConnection::query()->where('member_id', $memberId)->first();

            if ($existing !== null) {
                $existing->update([
                    'domain' => $cleanDomain,
                    'portal_type' => $portalType,
                    'client_endpoint' => $clientEndpoint,
                    'server_endpoint' => $serverEndpoint,
                    'access_token_enc' => $accessToken,
                    'refresh_token_enc' => $refreshToken !== '' ? $refreshToken : $existing->refresh_token_enc,
                    'application_token_enc' => $applicationToken !== '' ? $applicationToken : $existing->application_token_enc,
                    'token_expires_at' => now()->addSeconds($expiresIn),
                    'granted_scopes' => $scopes,
                    'status' => ConnectionStatus::Connected,
                    'installed_at' => $existing->installed_at ?? now(),
                ]);

                return $existing->fresh() ?? $existing;
            }

            $tenant = Tenant::query()->create([
                'name' => $cleanDomain,
                'slug' => Str::slug($cleanDomain).'-'.strtolower(Str::random(6)),
                'status' => 'active',
                'plan_tier' => 'standard',
                'settings' => [
                    'auto_create_crm_lead' => false,
                    'enable_call_popups' => true,
                    'enable_click_to_call' => true,
                    'enable_recording_stream' => true,
                    'smart_crm_routing' => false,
                ],
            ]);

            return BitrixConnection::query()->create([
                'tenant_id' => $tenant->id,
                'member_id' => $memberId,
                'domain' => $cleanDomain,
                'portal_type' => $portalType,
                'client_endpoint' => $clientEndpoint,
                'server_endpoint' => $serverEndpoint,
                'access_token_enc' => $accessToken,
                'refresh_token_enc' => $refreshToken !== '' ? $refreshToken : null,
                'application_token_enc' => $applicationToken !== '' ? $applicationToken : null,
                'token_expires_at' => now()->addSeconds($expiresIn),
                'granted_scopes' => $scopes,
                'status' => ConnectionStatus::Connected,
                'installed_at' => now(),
            ]);
        });

        $this->tenantContext->runFor($connection->tenant_id, function () use ($connection, $request): void {
            WebhookDelivery::query()->create([
                'source' => 'bitrix24',
                'event_name' => (string) ($request->input('event') ?? 'ONAPPINSTALL'),
                'status' => 'processed',
                'http_status' => 200,
                'sanitized_payload' => SecretRedactor::redactArray($request->all()),
                'created_at' => now(),
            ]);

            $eventsUrl = route('api.bitrix.events');
            foreach (['ONEXTERNALCALLSTART', 'ONEXTERNALCALLBACKSTART'] as $eventName) {
                try {
                    $this->bitrixClient->bindEvent($connection, $eventName, $eventsUrl);
                } catch (Throwable) {
                    // Event binding can be re-run cleanly from the onboarding wizard
                }
            }
        });

        if ($request->expectsJson() || $request->input('event') === 'ONAPPINSTALL') {
            return response()->json([
                'status' => 'installed',
                'member_id' => $connection->member_id,
                'tenant_id' => $connection->tenant_id,
            ]);
        }

        // Bitrix24 Market requirement: complete iframe install and redirect automatically (never show an empty page)
        $onboardingUrl = e(route('onboarding.index'));

        return response(
            '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><script src="//api.bitrix24.com/api/v1/"></script></head><body><script>if(window.BX24){BX24.init(function(){BX24.installFinish();window.location.href="'.$onboardingUrl.'";});}else{window.location.href="'.$onboardingUrl.'";}</script></body></html>',
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }

    /**
     * Handle ONAPPUNINSTALL verified by VerifyBitrixEventMiddleware.
     */
    public function uninstall(Request $request): JsonResponse
    {
        /** @var BitrixConnection $connection */
        $connection = $request->attributes->get('bitrix_connection');

        $connection->update([
            'status' => ConnectionStatus::Revoked,
            'access_token_enc' => null,
            'refresh_token_enc' => null,
            'token_expires_at' => null,
        ]);

        WebhookDelivery::query()->create([
            'source' => 'bitrix24',
            'event_name' => 'ONAPPUNINSTALL',
            'status' => 'processed',
            'http_status' => 200,
            'sanitized_payload' => SecretRedactor::redactArray($request->all()),
            'created_at' => now(),
        ]);

        return response()->json(['status' => 'uninstalled']);
    }

    /**
     * Fast ACK (< 100ms) receiver for Bitrix24 telephony events (ONEXTERNALCALLSTART, ONEXTERNALCALLBACKSTART).
     */
    public function events(Request $request): JsonResponse
    {
        $startedAt = microtime(true);
        $eventName = strtoupper((string) $request->input('event', 'UNKNOWN'));
        $callId = (string) ($request->input('data.CALL_ID') ?? '');
        $idempotencyKey = $callId !== '' ? "{$eventName}:{$callId}" : null;

        $delivery = WebhookDelivery::query()->create([
            'source' => 'bitrix24',
            'event_name' => $eventName,
            'idempotency_key' => $idempotencyKey,
            'status' => 'received',
            'http_status' => 200,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'sanitized_payload' => SecretRedactor::redactArray($request->all()),
            'created_at' => now(),
        ]);

        $tenantId = $this->tenantContext->id();
        $data = is_array($request->input('data')) ? $request->input('data') : [];

        if ($tenantId !== null && in_array($eventName, ['ONEXTERNALCALLSTART', 'ONEXTERNALCALLBACKSTART'], true)) {
            ProcessBitrixTelephonyEventJob::dispatch(
                tenantId: $tenantId,
                eventName: $eventName,
                data: $data,
                deliveryId: $delivery->id,
            );
        }

        return response()->json(['status' => 'accepted']);
    }
}
