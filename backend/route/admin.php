<?php

use think\facade\Route;
use app\admin\middleware\{
    JwtAuth,
    CheckPermission,
    AdminOperationLogMiddleware,
    RequestLockMiddleware
};

/*
|--------------------------------------------------------------------------
| 后台 API
|--------------------------------------------------------------------------
*/
Route::group('api/', function () {

    // 加载 admin 子路由
    load_routes('admin');

    // API 未匹配兜底
    Route::miss(function () {
        return json([
            'code' => 404,
            'msg' => '接口不存在',
            'data' => null,
        ]);
    });

})
    ->option([
        '_lock' => true, // 请求锁
        '_group_name' => '后台管理'
    ])
    ->middleware([
        JwtAuth::class,
        CheckPermission::class,   // CORS 必须最前
        RequestLockMiddleware::class,   // 防重复提交
        AdminOperationLogMiddleware::class,           // 操作日志（最后）
    ]);
/*
|--------------------------------------------------------------------------
| 后台管理页面（HTML）
|--------------------------------------------------------------------------
*/
Route::group('/', function () {
    Route::miss(function () {
        return view(
            app()->getRootPath() . 'public' . DIRECTORY_SEPARATOR . 'admin.html'
        );
    });
});