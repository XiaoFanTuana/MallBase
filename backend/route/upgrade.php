<?php

use app\middleware\upgrade\UpgradeSessionAuthMiddleware;
use app\middleware\upgrade\UpgradeAgentCapabilityMiddleware;
use think\facade\Route;

Route::get('upgrade/api/maintenance', 'upgrade.UpgradeRuntimeController/maintenance');
Route::post('upgrade/api/recovery/takeover', 'upgrade.UpgradeRuntimeController/recoveryTakeover');

Route::group('upgrade/api', function () {
    Route::get('status', 'status');
    Route::post('recovery/rotate', 'rotateRecovery');
    Route::post('recovery/confirm', 'confirmRecovery');
    Route::post('platform/bootstrap', 'bootstrapPlatform');
    Route::post('jobs', 'createJob');
    Route::post('jobs/:jobId/drain', 'startDrain');
    Route::post('jobs/:jobId/control', 'controlJob');
})->prefix('upgrade.UpgradeRuntimeController/')
    ->middleware([UpgradeSessionAuthMiddleware::class]);

Route::group('upgrade/api/agent/jobs/:jobId', function () {
    Route::post('backup-database', 'backupDatabase');
    Route::post('state-transition', 'stateTransition');
    Route::post('migrations', 'migrations');
    Route::post('confirm-paused', 'confirmPaused');
    Route::post('runtime-fence', 'runtimeFence');
    Route::post('resume', 'resume');
    Route::post('cancel', 'cancel');
    Route::post('platform-receipt', 'platformReceipt');
    Route::post('persistent-state-verification', 'persistentStateVerification');
    Route::post('reconciliation', 'reconciliation');
    Route::get('operations/:operationId', 'operation');
    Route::get('health', 'health');
    Route::get('writable-surface-audit', 'writableSurfaceAudit');
})->prefix('upgrade.UpgradeAgentController/')
    ->middleware([UpgradeAgentCapabilityMiddleware::class]);

// ThinkPHP evaluates matching routes in registration order. Keep the exact
// API/static paths ahead of the page fallback so /upgrade never shadows them.
Route::get('upgrade/app.js', 'upgrade.UpgradePageController/script');
Route::get('upgrade/styles.css', 'upgrade.UpgradePageController/styles');
Route::get('upgrade/', 'upgrade.UpgradePageController/index');
Route::get('upgrade', 'upgrade.UpgradePageController/index');
