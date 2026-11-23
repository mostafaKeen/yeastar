<?php

namespace App\Providers;

use App\Models\Tenant;
use App\Policies\TenantPolicy;
use App\Security\SecretRedactor;
use App\Security\SsrfGuard;
use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Monolog\Logger;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class, static fn (): TenantContext => new TenantContext);
        $this->app->singleton(SsrfGuard::class, static fn (): SsrfGuard => new SsrfGuard);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Tenant::class, TenantPolicy::class);

        $logger = Log::getLogger();
        if ($logger instanceof Logger) {
            $logger->pushProcessor(new SecretRedactor);
        }

        $this->configureRateLimiters();
    }

    private function configureRateLimiters(): void
    {
        RateLimiter::for('bitrix-webhooks', static function (Request $request): Limit {
            $memberId = (string) ($request->input('auth.member_id') ?? $request->ip());

            return Limit::perMinute(600)->by("b24:{$memberId}");
        });

        RateLimiter::for('yeastar-webhooks', static function (Request $request): Limit {
            $key = (string) ($request->route('webhookUlid') ?? $request->ip());

            return Limit::perMinute(600)->by("ys:{$key}");
        });

        RateLimiter::for('yeastar-agent', static function (Request $request): Limit {
            $tokenHash = hash('sha256', (string) $request->bearerToken());

            return Limit::perMinute(1200)->by("agent:{$tokenHash}");
        });
    }
}
