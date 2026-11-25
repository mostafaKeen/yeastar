<?php

namespace App\Http\Middleware;

use App\Enums\ConnectionStatus;
use App\Models\BitrixConnection;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class VerifyBitrixEventMiddleware
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $memberId = $request->input('auth.member_id');
        $applicationToken = $request->input('auth.application_token');
        $timestamp = $request->input('ts');

        if (! is_string($memberId) || $memberId === '' || ! is_string($applicationToken) || $applicationToken === '') {
            return response()->json(['error' => 'Unauthorized Bitrix24 event payload.'], Response::HTTP_UNAUTHORIZED);
        }

        if ($timestamp !== null && is_numeric($timestamp)) {
            $maxDrift = (int) config('telephony.bitrix24.event_max_drift_seconds', 300);
            if (abs(time() - (int) $timestamp) > $maxDrift) {
                return response()->json(['error' => 'Stale Bitrix24 event timestamp.'], Response::HTTP_UNAUTHORIZED);
            }
        }

        $connection = $this->tenantContext->withoutTenancy(static function () use ($memberId): ?BitrixConnection {
            return BitrixConnection::query()
                ->where('member_id', $memberId)
                ->whereIn('status', [ConnectionStatus::Connected->value, ConnectionStatus::Pending->value])
                ->first();
        });

        if ($connection === null) {
            return response()->json(['error' => 'Invalid Bitrix24 application token.'], Response::HTTP_UNAUTHORIZED);
        }

        if (! $connection->verifyApplicationToken($applicationToken)) {
            $liveAccessToken = (string) ($request->input('auth.access_token') ?? '');
            $healed = false;

            if ($liveAccessToken !== '' && ! empty($connection->client_endpoint)) {
                try {
                    $verifyResp = Http::timeout(5)
                        ->acceptJson()
                        ->asJson()
                        ->post(rtrim($connection->client_endpoint, '/').'/app.info.json', [
                            'auth' => $liveAccessToken,
                        ]);

                    if ($verifyResp->successful() && is_array($verifyResp->json('result'))) {
                        $this->tenantContext->withoutTenancy(static function () use ($connection, $applicationToken, $liveAccessToken): void {
                            $connection->update([
                                'application_token_enc' => $applicationToken,
                                'access_token_enc' => $liveAccessToken,
                            ]);
                        });
                        $healed = true;
                    }
                } catch (Throwable) {
                    $healed = false;
                }
            }

            if (! $healed) {
                return response()->json(['error' => 'Invalid Bitrix24 application token.'], Response::HTTP_UNAUTHORIZED);
            }
        }

        $this->tenantContext->setById($connection->tenant_id);
        $request->attributes->set('bitrix_connection', $connection);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->tenantContext->clear();
    }
}
