<?php

use app\admin\middleware\CheckPermission;
use app\admin\middleware\JwtAuth;
use think\facade\Route;

// 前台用户管理（后台管理，需要登录+权限）
Route::group('user', function () {
    Route::get('list', 'list')->option(['_alias' => '列表', '_desc' => '获取前台用户列表', '_type' => 'api']);
    Route::get('info/:id', 'info')->option(['_alias' => '详情', '_desc' => '获取前台用户详情', '_type' => 'api']);
    Route::post('create', 'create')->option(['_alias' => '新增', '_desc' => '创建前台用户', '_type' => 'api']);
    Route::put('update/:id', 'update')->option(['_alias' => '编辑', '_desc' => '更新前台用户', '_type' => 'api']);
    Route::delete('delete/:id', 'delete')->option(['_alias' => '删除', '_desc' => '删除前台用户', '_type' => 'api']);
    Route::put('status/:id', 'updateStatus')->option(['_alias' => '状态', '_desc' => '更新用户状态', '_type' => 'api']);
    Route::put('resetPassword/:id', 'resetPassword')->option(['_alias' => '重置密码', '_desc' => '重置用户密码', '_type' => 'api']);
})->prefix('user/UserController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name' => '用户管理',
        '_group_code' => 'ClientUserList',
        '_group_name_desc' => '前台用户管理模块的菜单和接口权限',
        '_parent' => 'ClientUserManagement',
        '_icon' => 'lucide:users',
        '_path' => '/user',
        '_component' => '/user/index',
    ]);

// 用户分组管理
Route::group('user/group', function () {
    Route::get('list', 'list')->option(['_alias' => '分组列表', '_desc' => '获取用户分组列表', '_type' => 'api']);
    Route::get('info', 'info')->option(['_alias' => '分组详情', '_desc' => '获取分组详情', '_type' => 'api']);
    Route::post('create', 'create')->option(['_alias' => '创建分组', '_desc' => '创建用户分组', '_type' => 'api']);
    Route::put('update', 'update')->option(['_alias' => '更新分组', '_desc' => '更新用户分组', '_type' => 'api']);
    Route::delete('delete', 'delete')->option(['_alias' => '删除分组', '_desc' => '删除用户分组', '_type' => 'api']);
    Route::put('updateStatus', 'updateStatus')->option(['_alias' => '分组状态', '_desc' => '更新分组状态', '_type' => 'api']);
    Route::get('getUserCount', 'getUserCount')->option(['_alias' => '分组用户数', '_desc' => '获取分组下的用户数', '_type' => 'api']);
    Route::post('batchSetUsers', 'batchSetUsers')->option(['_alias' => '批量设置分组', '_desc' => '批量设置用户分组', '_type' => 'api']);
    Route::delete('removeUser', 'removeUser')->option(['_alias' => '移除分组', '_desc' => '移除用户分组', '_type' => 'api']);
    Route::get('getUserGroups', 'getUserGroups')->option(['_alias' => '用户分组列表', '_desc' => '获取用户的所有分组', '_type' => 'api']);
})->prefix('user.UserGroupController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name' => '用户分组',
        '_group_code' => 'ClientUserGroup',
        '_group_name_desc' => '用户分组管理模块的菜单和接口权限',
        '_parent' => 'ClientUserManagement',
        '_icon' => 'lucide:users',
        '_path' => '/user/group',
        '_component' => '/user/group/index',
    ]);

// 用户标签管理
Route::group('user/tag', function () {
    Route::get('list', 'list')->option(['_alias' => '标签列表', '_desc' => '获取用户标签列表', '_type' => 'api']);
    Route::get('info', 'info')->option(['_alias' => '标签详情', '_desc' => '获取标签详情', '_type' => 'api']);
    Route::post('create', 'create')->option(['_alias' => '创建标签', '_desc' => '创建用户标签', '_type' => 'api']);
    Route::put('update', 'update')->option(['_alias' => '更新标签', '_desc' => '更新用户标签', '_type' => 'api']);
    Route::delete('delete', 'delete')->option(['_alias' => '删除标签', '_desc' => '删除用户标签', '_type' => 'api']);
    Route::put('updateStatus', 'updateStatus')->option(['_alias' => '标签状态', '_desc' => '更新标签状态', '_type' => 'api']);
    Route::get('getUserCount', 'getUserCount')->option(['_alias' => '标签用户数', '_desc' => '获取标签下的用户数', '_type' => 'api']);
    Route::post('batchSetUsers', 'batchSetUsers')->option(['_alias' => '批量设置标签', '_desc' => '批量给用户打标签', '_type' => 'api']);
    Route::delete('removeUser', 'removeUser')->option(['_alias' => '移除标签', '_desc' => '移除用户标签', '_type' => 'api']);
    Route::get('getUserTags', 'getUserTags')->option(['_alias' => '用户标签列表', '_desc' => '获取用户的所有标签', '_type' => 'api']);
})->prefix('user.UserTagController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name' => '用户标签',
        '_group_code' => 'ClientUserTag',
        '_group_name_desc' => '用户标签管理模块的菜单和接口权限',
        '_parent' => 'ClientUserManagement',
        '_icon' => 'lucide:tag',
        '_path' => '/user/tag',
        '_component' => '/user/tag/index',
    ]);
