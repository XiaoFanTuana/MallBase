<?php

use think\facade\Route;

// 上传接口路由
Route::group('upload', function () {
    // 获取上传配置（前端 Upload 组件使用，登录即可，无需权限）
    Route::get('config', 'config')->name('UploadConfig')->option([
        '_alias' => '上传配置',
        '_desc' => '获取上传验证规则和文件图标配置',
        '_auth' => false,
    ]);

    // 单文件上传（图片/文件通用，通过 type 参数区分）
    Route::post('single', 'single')->name('UploadSingle')->option([
        '_alias' => '单文件上传',
        '_desc' => '单文件上传（图片/文件通用）',
        '_auth' => true,
    ]);

    // 批量文件上传（图片/文件通用，通过 type 参数区分）
    Route::post('batch', 'batch')->name('UploadBatch')->option([
        '_alias' => '批量上传',
        '_desc' => '批量文件上传（图片/文件通用）',
        '_auth' => true,
    ]);
})->prefix('UploadController/')
    ->name('Upload')
    ->option([
        '_group_name' => '文件上传',
        '_path' => '',
        '_auth' => true,
    ]);