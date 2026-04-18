<?php

/*
|--------------------------------------------------------------------------
| 前台 API
|--------------------------------------------------------------------------
| MultiApp 已截断 client/ 前缀，pathinfo 为 user/auth/login 等
*/

$apiDir = __DIR__ . DIRECTORY_SEPARATOR . 'api';
foreach (glob($apiDir . DIRECTORY_SEPARATOR . '*.php') as $file) {
    require $file;
}
