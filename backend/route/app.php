<?php

use think\facade\Route;

Route::get('/', function () {
    return redirect('/client/');
});

/*
|--------------------------------------------------------------------------
| 静态文件访问（上传的文件）
|--------------------------------------------------------------------------
*/
Route::miss(function () {
    $path = str_replace('\\', '/', trim((string) request()->pathinfo(), '/'));
    if ($path === '' || str_contains($path, "\0")
        || preg_match('#(?:^|/)\.\.(?:/|$)#', $path) === 1) {
        abort(404, '文件不存在');
    }

    $publicRoot = realpath(public_path());
    $filePath = is_string($publicRoot)
        ? realpath($publicRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path))
        : false;
    if (!is_string($publicRoot) || !is_string($filePath)
        || !str_starts_with($filePath, $publicRoot . DIRECTORY_SEPARATOR)) {
        abort(404, '文件不存在');
    }

    if (is_dir($filePath)) {
        $indexPath = realpath(rtrim($filePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'index.html');
        if (is_string($indexPath) && str_starts_with($indexPath, $publicRoot . DIRECTORY_SEPARATOR)
            && is_file($indexPath)) {
            return response(file_get_contents($indexPath), 200, [
                'Content-Type' => 'text/html; charset=utf-8',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }
    }

    if (!is_file($filePath)) {
        abort(404, '文件不存在');
    }

    $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';

    return response(file_get_contents($filePath), 200, [
        'Content-Type'  => $mimeType,
        'X-Content-Type-Options' => 'nosniff',
        // 不启用一年强缓存，避免同名上传/演示静态文件更新后浏览器继续使用旧文件。
        // 'Cache-Control' => 'public, max-age=31536000',
    ]);
});
