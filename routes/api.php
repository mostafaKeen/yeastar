<?php

use App\Http\Controllers\BitrixMarketplaceController;
use App\Http\Controllers\YeastarAgentGatewayController;
use Illuminate\Support\Facades\Route;

// Bitrix24 Marketplace App Lifecycle & Telephony Event Receivers
Route::prefix('bitrix')->middleware('throttle:bitrix-webhooks')->name('api.bitrix.')->group(function () {
    Route::match(['get', 'post', 'head'], 'install', [BitrixMarketplaceController::class, 'install'])->name('install');

    Route::middleware('bitrix.event')->group(function () {
        Route::post('uninstall', [BitrixMarketplaceController::class, 'uninstall'])->name('uninstall');
        Route::post('events', [BitrixMarketplaceController::class, 'events'])->name('events');
    });
});

// Yeastar P-Series Direct HTTPS Event Webhook (Tenant-isolated via unguessable ULID)
Route::post('webhooks/yeastar/{webhookUlid}', [YeastarAgentGatewayController::class, 'directWebhook'])
    ->middleware('throttle:yeastar-webhooks')
    ->name('api.yeastar.webhook');

// Yeastar P-Series On-Premise Tunnel Agent Outbound Gateway
Route::prefix('agent')->middleware(['throttle:yeastar-agent', 'yeastar.agent'])->name('api.agent.')->group(function () {
    Route::post('heartbeat', [YeastarAgentGatewayController::class, 'heartbeat'])->name('heartbeat');
    Route::post('poll', [YeastarAgentGatewayController::class, 'poll'])->name('poll');
    Route::post('respond', [YeastarAgentGatewayController::class, 'respond'])->name('respond');
    Route::post('events', [YeastarAgentGatewayController::class, 'events'])->name('events');
});
