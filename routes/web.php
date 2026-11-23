<?php

use App\Http\Controllers\BitrixIframeController;
use App\Http\Controllers\CallLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiagnosticsController;
use App\Http\Controllers\ExtensionMappingController;
use App\Http\Controllers\IntegrationsController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\TenantSwitchController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

// Bitrix24 Marketplace Embedded App Slider Iframe Entrypoint
Route::match(['get', 'post'], 'bitrix/app', [BitrixIframeController::class, 'entry'])
    ->name('bitrix.embed');

Route::middleware(['auth', 'tenant'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::post('tenants/switch', [TenantSwitchController::class, 'switch'])->name('tenants.switch');
    Route::post('tenants', [TenantSwitchController::class, 'store'])->name('tenants.store');

    Route::get('onboarding', [OnboardingController::class, 'index'])->name('onboarding.index');
    Route::patch('onboarding/routing', [OnboardingController::class, 'updateRouting'])->name('onboarding.routing');

    Route::get('integrations', [IntegrationsController::class, 'index'])->name('integrations.index');
    Route::post('integrations/yeastar', [IntegrationsController::class, 'storeYeastar'])->name('integrations.yeastar.store');
    Route::post('integrations/yeastar/{connection}/test', [IntegrationsController::class, 'testYeastar'])->name('integrations.yeastar.test');
    Route::post('integrations/yeastar/{connection}/sync-phonebook', [IntegrationsController::class, 'syncPhonebook'])->name('integrations.yeastar.sync-phonebook');
    Route::post('integrations/yeastar/{connection}/rotate-agent-token', [IntegrationsController::class, 'rotateAgentToken'])->name('integrations.yeastar.rotate-token');
    Route::delete('integrations/yeastar/{connection}', [IntegrationsController::class, 'destroyYeastar'])->name('integrations.yeastar.destroy');
    Route::post('integrations/lines', [IntegrationsController::class, 'storeExternalLine'])->name('integrations.lines.store');
    Route::delete('integrations/lines/{externalLine}', [IntegrationsController::class, 'destroyExternalLine'])->name('integrations.lines.destroy');

    Route::get('mappings', [ExtensionMappingController::class, 'index'])->name('mappings.index');
    Route::post('mappings', [ExtensionMappingController::class, 'store'])->name('mappings.store');
    Route::post('mappings/auto-match', [ExtensionMappingController::class, 'autoMatch'])->name('mappings.auto-match');
    Route::patch('mappings/{mapping}', [ExtensionMappingController::class, 'update'])->name('mappings.update');
    Route::delete('mappings/{mapping}', [ExtensionMappingController::class, 'destroy'])->name('mappings.destroy');

    Route::get('calls', [CallLogController::class, 'index'])->name('calls.index');
    Route::get('calls/{callLogId}/recording', [CallLogController::class, 'streamRecording'])->name('calls.recording');

    Route::get('diagnostics', [DiagnosticsController::class, 'index'])->name('diagnostics.index');
    Route::post('diagnostics/webhooks/{deliveryId}/replay', [DiagnosticsController::class, 'replayWebhook'])->name('diagnostics.webhooks.replay');

    Route::get('security', [SecurityController::class, 'index'])->name('security.index');
    Route::post('security/members', [SecurityController::class, 'inviteMember'])->name('security.members.store');
    Route::patch('security/members/{member}', [SecurityController::class, 'updateMemberRole'])->name('security.members.update');
    Route::delete('security/members/{member}', [SecurityController::class, 'removeMember'])->name('security.members.destroy');
    Route::patch('security/yeastar/{connection}/ip-allowlist', [SecurityController::class, 'updateIpAllowlist'])->name('security.yeastar.ip-allowlist');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
