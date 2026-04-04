<?php
// 应用公共文件

use app\admin\service\setting\SettingService;
use app\service\UploadService;

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

if (!function_exists('getUploadDomain')) {
    /**
     * 获取上传域名
     * 统一调用公共 UploadService 获取，方便后续维护
     *
     * @return string 返回上传域名
     */
    function getUploadDomain(): string
    {
        return UploadService::getUploadDomain();
    }
}

if (!function_exists('getSystemSetting')) {
    /**
     * 获取系统设置项的值（带缓存）
     *
     * 使用示例：
     *   getSystemSetting('wechat_appid')                    // 不存在返回 null
     *   getSystemSetting('wechat_appid', 'default_value')   // 不存在时返回 'default_value'
     *
     * @param string $code 设置项编码
     * @param mixed $default 默认值（设置项不存在或值为空时返回）
     * @return mixed
     */
    function getSystemSetting(string $code, mixed $default = null): mixed
    {
        return app()->make(SettingService::class)
            ->getSettingValue($code, $default);
    }
}
