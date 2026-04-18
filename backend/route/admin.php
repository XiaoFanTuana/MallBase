<?php

use think\facade\Route;

/*
|--------------------------------------------------------------------------
| 静态文件访问（上传的文件）
|--------------------------------------------------------------------------
| /uploads/... 不经过 MultiApp（无 app/uploads/ 目录）
*/
Route::group('uploads', function () {
    Route::miss(function () {
        $path = request()->pathinfo();
        $filePath = public_path() . DIRECTORY_SEPARATOR . str_replace('/uploads/', '', $path);

        if (!file_exists($filePath)) {
            abort(404, '文件不存在');
        }

        $mimeType = mime_content_type($filePath);

        return response(file_get_contents($filePath), 200, [
            'Content-Type'  => $mimeType,
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    });
})->allowCrossDomain();

/*
|--------------------------------------------------------------------------
| 全局兜底
|--------------------------------------------------------------------------
| 注意：此 miss 会被 Swoole 预加载并 clone 到每个请求，
| 若在此设置 Route::miss()，会拦截多应用（admin/client/install）的路由匹配。
| 因此不在此设置全局 miss，各应用自行在 app/{name}/route/app.php 中处理。
*/
