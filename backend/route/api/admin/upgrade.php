<?php

use app\model\auth\Permission;
use think\facade\Route;

Route::group('system/upgrade', function () {
    Route::post('session', 'createSession')
        ->name('SystemUpgradeSessionCreate')
        ->option([
            '_alias' => '进入系统升级',
            '_desc' => '创建升级控制会话',
            '_auth' => true,
            '_type' => Permission::TYPE_BUTTON,
            '_request_lock' => false,
        ]);
})->prefix('admin.upgrade.UpgradeController/')
    ->option([
        '_group_name' => '系统升级',
        '_group_code' => 'SystemUpgrade',
        '_path' => '/system/upgrade',
        '_auth' => true,
        '_icon' => 'lucide:refresh-cw',
        '_parent' => 'SystemManagement',
        '_component' => '/system/upgrade/index',
    ]);
