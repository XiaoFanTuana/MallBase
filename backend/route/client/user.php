<?php

use app\client\middleware\JwtAuth;
use think\facade\Route;

// 前台用户认证相关（无需登录）
Route::group('client/user/auth', function () {
    Route::post('register', 'register');
    Route::post('login', 'login');
    Route::post('wechat', 'wechatLogin'); // 微信小程序登录
    Route::post('bindMobile', 'bindMobile'); // 绑定手机号
    Route::post('decryptPhone', 'decryptPhoneNumber'); // 解密手机号
})->prefix('user/UserController/');

// 当前用户操作
Route::group('client/user/my', function () {
    Route::get('info', 'getMyInfo');
    Route::put('info', 'updateMyInfo');
    Route::put('password', 'updateMyPassword');
    Route::post('logout', 'logout');
})->prefix('user/UserController/')->middleware([JwtAuth::class]);
