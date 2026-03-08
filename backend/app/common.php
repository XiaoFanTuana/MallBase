<?php
// 应用公共文件

if (!function_exists('load_routes')) {
    function load_routes(string $name): void
    {
        $path = app()->getRootPath() . 'route' . DIRECTORY_SEPARATOR . $name;

        foreach (glob($path . DIRECTORY_SEPARATOR . '*.php') as $file) {
            require_once $file;
        }
    }
}


if (!function_exists('convertToRouteName')) {
    /**
     * 转换为路由名称格式
     */
    function convertToRouteName($code)
    {
        // 将下划线或短横线转换为驼峰
        return str_replace(['-', '_'], '', ucwords($code, '-_'));
    }
}
