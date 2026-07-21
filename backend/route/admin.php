<?php

use think\facade\Route;
use app\middleware\admin\{
    JwtAuth,
    CheckPermission,
    AdminOperationLogMiddleware,
    RequestLockMiddleware,
    UpgradeAdminGateMiddleware,
};

Route::group('admin', function () {

    /*
    |--------------------------------------------------------------------------
    | 后台 API
    |--------------------------------------------------------------------------
    */
    Route::group('api/', function () {

        $apiDir = __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'admin';
        foreach (glob($apiDir . DIRECTORY_SEPARATOR . '*.php') as $file) {
            require $file;
        }

        Route::miss(function () {
            return json([
                'code' => 404,
                'msg'  => '接口不存在',
                'data' => null,
            ]);
        });

    })
        ->option([
            '_lock'       => true,
            '_group_name' => '后台管理'
        ])
        ->middleware([
            UpgradeAdminGateMiddleware::class,
            JwtAuth::class,
            CheckPermission::class,
            RequestLockMiddleware::class,
            AdminOperationLogMiddleware::class,
        ]);

    /*
    |--------------------------------------------------------------------------
    | 后台 SPA 兜底
    |--------------------------------------------------------------------------
    | pathinfo 现在包含 admin/ 前缀，需要 strip 后查找静态文件
    */
    Route::miss(function () {
        $path = (string) preg_replace('#^admin/?#', '', (string) request()->pathinfo());
        $path = str_replace('\\', '/', trim($path, '/'));
        if (str_contains($path, "\0") || preg_match('#(?:^|/)\.\.(?:/|$)#', $path) === 1) {
            abort(404, '前端页面未找到');
        }
        $publicPath = app()->getRootPath() . 'public' . DIRECTORY_SEPARATOR;
        $adminRoot = realpath($publicPath . 'admin');

        $filePath = is_string($adminRoot) && $path !== ''
            ? realpath($adminRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path))
            : false;
        if (is_string($filePath) && str_starts_with($filePath, $adminRoot . DIRECTORY_SEPARATOR)
            && is_file($filePath)) {
            $mimeTypes = [
                'js'    => 'application/javascript',
                'mjs'   => 'application/javascript',
                'css'   => 'text/css',
                'svg'   => 'image/svg+xml',
                'png'   => 'image/png',
                'jpg'   => 'image/jpeg',
                'jpeg'  => 'image/jpeg',
                'gif'   => 'image/gif',
                'ico'   => 'image/x-icon',
                'woff'  => 'font/woff',
                'woff2' => 'font/woff2',
                'ttf'   => 'font/ttf',
                'eot'   => 'application/vnd.ms-fontobject',
                'json'  => 'application/json',
            ];

            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $mimeType = $mimeTypes[$ext] ?? (mime_content_type($filePath) ?: 'application/octet-stream');

            return response(file_get_contents($filePath), 200, [
                'Content-Type'  => $mimeType,
                'X-Content-Type-Options' => 'nosniff',
                // 不启用一年强缓存，避免构建产物或静态文件同名更新后浏览器继续使用旧文件。
                // 'Cache-Control' => 'public, max-age=31536000, immutable',
            ]);
        }

        $indexPath = is_string($adminRoot) ? realpath($adminRoot . DIRECTORY_SEPARATOR . 'index.html') : false;
        if (is_string($indexPath) && str_starts_with($indexPath, $adminRoot . DIRECTORY_SEPARATOR)
            && is_file($indexPath)) {
            return response(file_get_contents($indexPath), 200, [
                'Content-Type' => 'text/html; charset=utf-8',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $legacyPath = $publicPath . 'admin.html';
        if (is_file($legacyPath)) {
            return view($legacyPath);
        }

        abort(404, '前端页面未找到，请先构建前端');
    });

});
