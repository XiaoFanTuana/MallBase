<?php

use app\client\middleware\JwtAuth;
use think\facade\Route;

// 买家售后（全部需登录）
Route::group('client/refund', function () {
    Route::post('apply', 'apply');
    Route::post('cancel/:id', 'cancel');
    Route::get('list', 'list');
    Route::get('detail/:id', 'detail');
    Route::get('reasonOptions', 'reasonOptions');
})->prefix('order.RefundOrderController/')->middleware([JwtAuth::class]);
