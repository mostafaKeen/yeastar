<?php

namespace App\Http\Middleware;

use App\Enums\YeastarTransportMode;
use App\Models\YeastarConnection;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyYeastarAgentMiddleware
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (! is_string($bearer) || ! str_starts_with($bearer, 'ysa_')) {
            return response()->json(['error' => 'Missing or malformed agent bearer token.'], Response::HTTP_UNAUTHORIZED);
        }

        $tokenHash = hash('sha256', $bearer);

        $connection = $this->tenantContext->withoutTenancy(static function () use ($tokenHash): ?YeastarConnection {
            return YeastarConnection::query()
                ->where('agent_token_hash', $tokenHash)
                ->where('transport_mode', YeastarTransportMode::TunnelAgent->value)
                ->first();
        });

        if ($connection === null || ! $connection->verifyAgentToken($bearer)) {
            return response()->json(['error' => 'Invalid or revoked On-Premise Agent token.'], Response::HTTP_UNAUTHORIZED);
        }

        $this->tenantContext->setById($connection->tenant_id);
        $request->attributes->set('yeastar_connection', $connection);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->tenantContext->clear();
    }
}
