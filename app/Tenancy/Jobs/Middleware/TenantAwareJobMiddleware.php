<?php

namespace App\Tenancy\Jobs\Middleware;

use App\Tenancy\Exceptions\MissingTenantContextException;
use App\Tenancy\TenantContext;
use Closure;

class TenantAwareJobMiddleware
{
    public function handle(object $job, Closure $next): void
    {
        /** @var TenantContext $context */
        $context = app(TenantContext::class);

        $tenantId = property_exists($job, 'tenantId') ? $job->tenantId : null;

        if (! is_string($tenantId) || $tenantId === '') {
            throw new MissingTenantContextException(
                sprintf('Queued job [%s] must define a non-empty string $tenantId property.', $job::class),
            );
        }

        $context->runFor($tenantId, static function () use ($job, $next): void {
            $next($job);
        });
    }
}
