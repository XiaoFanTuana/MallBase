<?php

use app\admin\controller\ConfigController;
use app\admin\middleware\JwtAuth;
use think\facade\Route;

// 系统配置接口（需要登录）
Route::group('config', function () {
    Route::get('colorOptions', 'colorOptions')->option(['_alias' => '颜色选项', '_desc' => '获取颜色选项列表', '_type' => 'api']);
})->prefix('ConfigController/')
    ->middleware([JwtAuth::class]);
