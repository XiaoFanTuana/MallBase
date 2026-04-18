<?php

declare(strict_types=1);

namespace app\middleware;

class MultiApp extends \think\app\MultiApp
{
    protected function setApp(string $appName): void
    {
        parent::setApp($appName);

        // Swoole 模式下 SwooleHttp::loadRoutes() 是空操作，
        // 需要在 MultiApp 设置完应用后手动加载路由文件
        $routePath = $this->app->http->getRoutePath();
        if ($routePath && is_dir($routePath)) {
            foreach (glob($routePath . '*.php') as $file) {
                include $file;
            }
        }
    }
}
