<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplySecurityHeadersMiddleware
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $ancestors = array_merge(
            ["'self'"],
            (array) config('telephony.bitrix24.frame_ancestors', []),
        );

        $tenant = $this->tenantContext->tenant();
        if ($tenant !== null) {
            $connection = $tenant->relationLoaded('bitrixConnection')
                ? $tenant->bitrixConnection
                : null;

            if ($connection !== null && $connection->portal_type === 'on_premise' && is_string($connection->domain)) {
                $host = preg_replace('/[^a-zA-Z0-9.\-:]/', '', $connection->domain);
                if ($host !== '') {
                    $ancestors[] = 'https://'.$host;
                }
            }
        }

        $response->headers->set(
            'Content-Security-Policy',
            'frame-ancestors '.implode(' ', array_unique($ancestors)).';',
        );

        return $response;
    }
}
