<?php

use app\admin\middleware\CheckPermission;
use app\admin\middleware\JwtAuth;
use think\facade\Route;

// 管理员接口路由
Route::group('auth/admin', function () {
    Route::group('', function () {
        // 登录
        Route::post('login', 'login')->option(['_alias' => '登录', '_desc' => '管理员登录']);
    })->option([
        '_alias' => '无需授权',
    ])->withoutMiddleware([JwtAuth::class, CheckPermission::class]);

    // 列表
    Route::get('list', 'list')->option(['_alias' => '列表', '_desc' => '管理员列表', '_auth' => true]);
    // 详情
    Route::get('info', 'info')->option(['_alias' => '详情', '_desc' => '管理员详情', '_auth' => true]);
    // 创建
    Route::post('create', 'create')->option(['_alias' => '创建', '_desc' => '创建管理员', '_auth' => true]);
    // 更新
    Route::post('update', 'update')->option(['_alias' => '更新', '_desc' => '更新管理员', '_auth' => true]);
    // 删除
    Route::post('delete', 'delete')->option(['_alias' => '删除', '_desc' => '删除管理员', '_auth' => true]);
})->prefix('auth/AdminController/')
    ->option([
        '_group_name' => '管理员',
        '_path' => '',
        '_auth' => true,
    ]);
