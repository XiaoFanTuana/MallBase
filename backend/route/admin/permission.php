<?php

use app\admin\middleware\JwtAuth;
use think\facade\Route;

// 权限接口路由
Route::group('auth/permission', function () {
    // 树形列表
    Route::get('tree', 'tree')->option(['_alias' => '树形列表', '_desc' => '权限树形列表', '_auth' => true]);
    // 列表
    Route::get('list', 'list')->option(['_alias' => '列表', '_desc' => '权限列表', '_auth' => true]);
    // 详情
    Route::get('info/:id', 'info')->option(['_alias' => '详情', '_desc' => '权限详情', '_auth' => true]);
    // 创建
    Route::post('create', 'create')->option(['_alias' => '创建', '_desc' => '创建权限', '_auth' => true]);
    // 更新
    Route::put('update/:id', 'update')->option(['_alias' => '更新', '_desc' => '更新权限', '_auth' => true]);
    // 删除
    Route::delete('delete/:id', 'delete')->option(['_alias' => '删除', '_desc' => '删除权限', '_auth' => true]);
})->prefix('auth/PermissionController/')
    ->option([
        '_group_name' => '权限',
        '_path' => '',
        '_auth' => true,
    ])
    ->middleware([
        JwtAuth::class
    ]);
