<?php

use App\Http\Middleware\ApplySecurityHeadersMiddleware;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveTenantMiddleware;
use App\Http\Middleware\VerifyBitrixEventMiddleware;
use App\Http\Middleware\VerifyYeastarAgentMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'tenant' => ResolveTenantMiddleware::class,
            'bitrix.event' => VerifyBitrixEventMiddleware::class,
            'yeastar.agent' => VerifyYeastarAgentMiddleware::class,
        ]);

        // Bitrix24 loads embedded Marketplace apps via cross-origin POST into an iframe
        $middleware->validateCsrfTokens(except: [
            'bitrix/app',
        ]);

        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveTenantMiddleware::class,
        );

        $middleware->web(append: [
            ResolveTenantMiddleware::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            ApplySecurityHeadersMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
