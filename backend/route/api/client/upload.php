<?php

use app\middleware\client\JwtAuth;
use think\facade\Route;

Route::group('upload', function () {
    Route::post('wechat-avatar', 'wechatAvatar');
})->prefix('client.UploadController/');

Route::group('upload', function () {
    Route::post('single', 'single');
})->prefix('client.UploadController/')->middleware([JwtAuth::class]);
