<?php

use app\middleware\upgrade\SimpleUpgradeAuthMiddleware;
use think\facade\Route;

Route::group('upgrade/api/simple/jobs/:jobId', function () {
    Route::post('pause', 'pause');
    Route::post('backup-database', 'backupDatabase');
    Route::post('migrations', 'migrations');
    Route::post('restore-database', 'restoreDatabase');
    Route::post('awaiting-restart', 'awaitingRestart');
    Route::post('resume', 'resume');
})->prefix('upgrade.SimpleUpgradeController/')
    ->middleware([SimpleUpgradeAuthMiddleware::class]);
