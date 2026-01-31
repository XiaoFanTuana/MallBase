<?php

/**
 * 接口文档生成命令
 *
 * 功能说明：
 * - 使用 ThinkPHP 原生路由方法获取所有路由
 * - 生成完整的 API 接口文档（HTML 和 OpenAPI 格式）
 * - 从控制器方法 PHPDoc 中提取参数
 * - 支持按控制器分组显示
 *
 * 使用示例：
 * ```bash
 * # 生成文档（默认输出到 public/docs/api.html）
 * php think docs
 *
 * # 启用反射提取参数
 * php think docs --enable-reflection
 * ```
 *
 * @package app\command
 */

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Config;
use think\facade\Route;

class Docs extends Command
{
    /**
     * 是否启用反射提取参数
     * @var bool
     */
    protected $enableReflection = false;

    /**
     * 路由分组映射（用于分组展示）
     * @var array
     */
    protected $groupMap = [];

    /**
     * 配置命令
     */
    protected function configure()
    {
        $this->setName('docs')
            ->setDescription('生成 API 接口文档（HTML 和 OpenAPI 格式）')
            ->addOption('output', 'o', Option::VALUE_OPTIONAL, '指定 HTML 输出文件路径', 'public/docs/api.html')
            ->addOption('openapi', null, Option::VALUE_OPTIONAL, '指定 OpenAPI 输出文件路径', 'public/docs/openapi.json')
            ->addOption('enable-reflection', null, Option::VALUE_NONE, '启用反射提取参数（默认启用）');
    }

    /**
     * 执行命令
     */
    protected function execute(Input $input, Output $output)
    {
        $outputPath = $input->getOption('output');
        $openapiPath = $input->getOption('openapi');
        $this->enableReflection = $input->getOption('enable-reflection') ?: true; // 默认启用

        $output->writeln('<info>开始生成接口文档...</info>');
        $output->writeln('<comment>读取路由信息...</comment>');
        $output->writeln('<comment>反射提取参数：' . ($this->enableReflection ? '已启用' : '已禁用') . '</comment>');

        // 从配置文件读取基本信息
        $docs = Config::get('docs');
        $docs['apps'] = [];

        // 使用 ThinkPHP 原生方法获取路由列表
        $routeList = $this->getRouteList();

        $output->writeln('<comment>解析到的路由数量: ' . count($routeList) . '</comment>');

        // 按应用和分组组织路由
        $this->organizeRoutes($routeList, $docs);

        // 显示统计信息
        $totalGroups = 0;
        $totalRoutes = 0;
        foreach ($docs['apps'] as $appName => $app) {
            $totalGroups += count($app['groups']);
            foreach ($app['groups'] as $group) {
                $totalRoutes += count($group['routes']);
            }
        }

        $output->writeln('<comment>解析到的应用数量: ' . count($docs['apps']) . '</comment>');
        $output->writeln('<comment>  总分组数: ' . $totalGroups . '</comment>');
        $output->writeln('<comment>  总路由数: ' . $totalRoutes . '</comment>');

        $output->writeln('<comment>生成 HTML 文档...</comment>');
        $htmlContent = $this->generateHtml($docs);

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($outputPath, $htmlContent);
        $output->writeln('<info>HTML 文档生成成功！</info>');
        $output->writeln('<comment>文件路径: ' . $outputPath . '</comment>');

        $output->writeln('<comment>生成 OpenAPI 文档...</comment>');
        $openapiContent = $this->generateOpenApi($docs);

        $openapiDir = dirname($openapiPath);
        if (!is_dir($openapiDir)) {
            mkdir($openapiDir, 0755, true);
        }

        file_put_contents($openapiPath, $openapiContent);
        $output->writeln('<info>OpenAPI 文档生成成功！</info>');
        $output->writeln('<comment>文件路径: ' . $openapiPath . '</comment>');
        $output->writeln('<info>可以直接导入 Apifox、Postman 等接口测试工具</info>');
    }

    /**
     * 使用 ThinkPHP 原生方法获取路由列表
     * 参考 RouteList 命令的实现
     */
    protected function getRouteList(): array
    {
        // 清除路由缓存
        $this->app->route->clear();
        $this->app->route->lazy(false);

        // 扫描路由目录
        $this->scanRouteDirectory($this->app->getRootPath() . 'route' . DIRECTORY_SEPARATOR);

        // 获取路由列表
        return $this->app->route->getRuleList();
    }

    /**
     * 扫描路由目录
     * 直接包含主要路由文件，保持分组上下文
     */
    protected function scanRouteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        // 直接包含顶层的路由文件（如 admin.php、app.php）
        // 这样可以保持外层分组的上下文（如 Route::group('api/', ...)）
        $files = glob($path . '*.php');
        foreach ($files as $file) {
            // 只包含顶层文件，不包含子目录中的文件
            // 子路由文件会通过 load_routes 函数被加载
            if (is_file($file)) {
                include $file;
            }
        }
    }

    /**
     * 组织路由信息
     */
    protected function organizeRoutes(array $routeList, array &$docs): void
    {
        foreach ($routeList as $route) {
            // 跳过没有 option 的路由（非业务接口）
            if (!isset($route['option']) || !is_array($route['option'])) {
                continue;
            }

            $option = $route['option'];

            // 跳过没有 _alias 或 _desc 的路由
            if (!isset($option['_alias']) && !isset($option['_desc'])) {
                continue;
            }

            // 解析控制器和方法
            $controllerClass = '';
            $action = '';
            $routeValue = $route['route'];
            $prefix = $route['option']['prefix'] ?? '';

            // 方法名
            $action = is_string($routeValue) ? $routeValue : '';

            // 从 prefix 构造控制器类名
            // prefix 格式: auth/AdminController/
            if (!empty($prefix)) {
                // 移除末尾的 /
                $prefix = rtrim($prefix, '/');

                // 将 / 转换为 \ 并添加命名空间前缀
                // auth/AdminController -> app\admin\controller\auth\AdminController
                $controllerClass = 'app\\admin\\controller\\' . str_replace('/', '\\', $prefix);
            }

            // 应用名默认为 admin（从入口文件推断）
            $appName = 'admin';

            // 获取参数
            $params = [];
            if ($this->enableReflection && !empty($controllerClass) && !empty($action)) {
                $params = $this->getMethodParams($controllerClass, $action, $route['method']);
            }

            // 从 option 中获取参数（优先级更高）
            if (isset($option['_params']) && is_array($option['_params'])) {
                $params = array_merge($params, $option['_params']);
            }

            // 路由规则
            $rule = ltrim($route['rule'], '/');

            // 在路由规则前添加应用名前缀（如 admin）
            // 这样完整的路径就是: admin/api/auth/admin/login
            if (!str_starts_with($rule, $appName . '/')) {
                $rule = $appName . '/' . $rule;
            }

            // 分组名：从控制器类名中提取模块名
            if (isset($option['_group'])) {
                $groupName = $option['_group'];
            } else if (!empty($controllerClass)) {
                // 从控制器类名中提取模块名
                // 例如：app\admin\controller\auth\AdminController -> admin
                $namespaceParts = explode('\\', $controllerClass);
                $controllerName = end($namespaceParts); // AdminController

                // 移除 "Controller" 后缀
                $groupName = str_replace('Controller', '', $controllerName);
            } else {
                $groupName = '其他';
            }

            // 初始化应用
            if (!isset($docs['apps'][$appName])) {
                $docs['apps'][$appName] = [
                    'name' => $appName,
                    'groups' => [],
                ];
            }

            // 初始化分组
            if (!isset($docs['apps'][$appName]['groups'][$groupName])) {
                $docs['apps'][$appName]['groups'][$groupName] = [
                    'name' => $groupName,
                    'routes' => [],
                ];
            }

            // 添加路由（使用修改后的 rule，包含应用名前缀）
            $docs['apps'][$appName]['groups'][$groupName]['routes'][] = [
                'method' => strtoupper($route['method']),
                'path' => $rule,
                'alias' => $option['_alias'] ?? $rule,
                'description' => $option['_desc'] ?? '',
                'controller' => $controllerClass,
                'action' => $action,
                'params' => $params,
                'response' => $option['_response'] ?? [],
                'is_closure' => is_object($routeValue) && $routeValue instanceof \Closure,
            ];
        }
    }

    /**
     * 通过反射获取控制器方法的参数信息
     */
    protected function getMethodParams(string $controllerClass, string $action, string $httpMethod): array
    {
        $params = [];

        try {
            if (!class_exists($controllerClass)) {
                return $params;
            }

            $reflection = new \ReflectionClass($controllerClass);

            if (!$reflection->hasMethod($action)) {
                return $params;
            }

            $method = $reflection->getMethod($action);

            // 从 PHPDoc 注释中读取参数
            $docComment = $method->getDocComment();

            if ($docComment) {
                $parsedParams = $this->parseDocParams($docComment, $httpMethod);
                if (!empty($parsedParams)) {
                    $params = $parsedParams;
                }
            }

            // 如果 PHPDoc 没有参数，分析方法体中的 getParam/getPost/getGet 调用
            if (empty($params)) {
                $bodyParams = $this->extractParamsFromMethodBody($method, $httpMethod);
                if (!empty($bodyParams)) {
                    $params = $bodyParams;
                }
            }
        } catch (\Exception $e) {
            // 记录错误但不中断执行
            error_log("Docs command - Reflection error for {$controllerClass}::{$action}: " . $e->getMessage());
        }

        return $params;
    }

    /**
     * 从方法体中提取参数
     */
    protected function extractParamsFromMethodBody(\ReflectionMethod $method, string $httpMethod): array
    {
        $params = [];

        // 获取方法体内容
        $fileName = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        $lines = file($fileName);
        $methodBody = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

        // 根据HTTP方法确定要匹配的方法调用
        if (in_array($httpMethod, ['GET', 'DELETE'])) {
            $pattern = '/\$this->request->param\s*\(\s*\[(.*?)\]/s';
            $paramSource = 'URL参数';
        } else {
            $pattern = '/\$this->request->param\s*\(\s*\[(.*?)\]/s';
            $paramSource = '请求体参数';
        }

        // 匹配所有参数调用
        if (preg_match_all($pattern, $methodBody, $matches)) {
            foreach ($matches[1] as $arrayContent) {
                // 匹配数组中的所有字符串（单引号或双引号）
                $paramPattern = '/[\'"]([^\'"]+)[\'"]/';
                if (preg_match_all($paramPattern, $arrayContent, $paramMatches)) {
                    foreach ($paramMatches[1] as $paramName) {
                        if (!isset($params[$paramName])) {
                            $params[$paramName] = 'string|' . $paramSource . '，必填';
                        }
                    }
                }
            }
        }

        return $params;
    }

    /**
     * 解析 PHPDoc 注释中的 @param 标签
     */
    protected function parseDocParams(string $docComment, string $httpMethod): array
    {
        $params = [];

        // 匹配 @param 标签
        // 格式: @param 类型 $参数名 描述
        if (preg_match_all('/@param\s+(\S+)\s+\$(\w+)\s*(.*)/i', $docComment, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $type = $match[1];
                $name = $match[2];
                $desc = trim($match[3]);

                // 检查是否包含"可选"字样
                $isRequired = !str_contains($desc, '可选') && !str_contains($desc, '（可选）');

                // 根据HTTP方法确定参数来源
                if (in_array($httpMethod, ['GET', 'DELETE'])) {
                    $paramSource = 'URL参数';
                } else {
                    $paramSource = '请求体参数';
                }

                // 构建参数描述
                if (empty($desc)) {
                    $paramDesc = $isRequired ? $paramSource . '，必填' : $paramSource . '，可选';
                } else {
                    $paramDesc = $desc . '（' . $paramSource . '）';
                }

                $params[$name] = $type . '|' . $paramDesc;
            }
        }

        return $params;
    }

    /**
     * 生成 HTML 格式文档
     */
    protected function generateHtml(array $docs): string
    {
        $appsJson = json_encode($docs['apps'], JSON_UNESCAPED_UNICODE);

        $html = '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . $docs['title'] . '</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            display: flex;
            height: 100vh;
            overflow: hidden;
            background: #f5f5f5;
        }
        
        /* 左侧导航栏 */
        .sidebar {
            width: 300px;
            background: #2c3e50;
            color: #ecf0f1;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            border-right: 1px solid #34495e;
        }
        
        .sidebar-header {
            padding: 20px;
            background: #1a252f;
            border-bottom: 1px solid #34495e;
        }
        
        .sidebar-header h1 {
            font-size: 18px;
            margin-bottom: 5px;
        }
        
        .sidebar-header p {
            font-size: 12px;
            color: #95a5a6;
        }
        
        /* 搜索框 */
        .search-box {
            padding: 15px;
            background: #2c3e50;
            border-bottom: 1px solid #34495e;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .search-box input {
            width: 100%;
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            background: #34495e;
            color: #ecf0f1;
            font-size: 14px;
        }
        
        .search-box input::placeholder {
            color: #7f8c8d;
        }
        
        .search-box input:focus {
            outline: none;
            background: #3d566e;
        }
        
        /* 导航菜单 */
        .nav-menu {
            flex: 1;
            overflow-y: auto;
        }
        
        .nav-app {
            border-bottom: 1px solid #34495e;
        }

        .nav-app-title {
            padding: 15px;
            font-size: 15px;
            font-weight: bold;
            background: #1a252f;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.2s;
        }

        .nav-app-title:hover {
            background: #2c3e50;
        }

        .nav-app-groups {
            background: #2c3e50;
        }

        .nav-app.collapsed .nav-app-groups {
            display: none;
        }

        .nav-group {
            border-left: 3px solid #3498db;
        }

        .nav-group-title {
            padding: 12px 15px 12px 20px;
            font-size: 14px;
            background: #34495e;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.2s;
        }

        .nav-group-title:hover {
            background: #3d566e;
        }

        .nav-group.collapsed .nav-routes {
            display: none;
        }

        .nav-routes {
            background: #2c3e50;
        }
        
        .nav-route {
            padding: 10px 15px 10px 30px;
            font-size: 13px;
            cursor: pointer;
            transition: background 0.2s;
            border-left: 3px solid transparent;
        }
        
        .nav-route:hover {
            background: #3d566e;
            border-left-color: #3498db;
        }
        
        .nav-route.active {
            background: #34495e;
            border-left-color: #3498db;
        }
        
        .nav-route-method {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-weight: bold;
            font-size: 11px;
            margin-right: 8px;
        }
        
        .nav-route-method.GET {
            background: #61affe;
            color: #fff;
        }
        
        .nav-route-method.POST {
            background: #49cc90;
            color: #fff;
        }
        
        .nav-route-method.PUT {
            background: #fca130;
            color: #fff;
        }
        
        .nav-route-method.DELETE {
            background: #f93e3e;
            color: #fff;
        }
        
        /* 主内容区域 */
        .main-content {
            flex: 1;
            overflow-y: auto;
            padding: 30px;
        }
        
        .content-wrapper {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            background: #fff;
            padding: 30px;
            border-radius: 8px;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            font-size: 32px;
            margin-bottom: 10px;
            color: #2c3e50;
        }
        
        .header p {
            font-size: 14px;
            color: #7f8c8d;
            margin: 5px 0;
        }
        
        .group-section {
            background: #fff;
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 25px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .group-title {
            font-size: 24px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #3498db;
            color: #2c3e50;
        }
        
        .route-card {
            background: #f8f9fa;
            padding: 20px;
            margin: 15px 0;
            border-radius: 6px;
            border-left: 4px solid #3498db;
        }
        
        .route-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .route-method {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 13px;
            margin-right: 12px;
            text-transform: uppercase;
        }
        
        .route-method.GET {
            background: #61affe;
            color: #fff;
        }
        
        .route-method.POST {
            background: #49cc90;
            color: #fff;
        }
        
        .route-method.PUT {
            background: #fca130;
            color: #fff;
        }
        
        .route-method.DELETE {
            background: #f93e3e;
            color: #fff;
        }
        
        .route-path {
            font-family: "Courier New", monospace;
            background: #e9ecef;
            padding: 4px 10px;
            border-radius: 3px;
            font-size: 14px;
            color: #2c3e50;
        }
        
        .route-title {
            flex: 1;
            font-size: 18px;
            margin-left: 15px;
            color: #2c3e50;
        }
        
        .route-description {
            font-size: 14px;
            color: #7f8c8d;
            margin: 10px 0;
        }
        
        .section-title {
            font-size: 14px;
            font-weight: bold;
            color: #2c3e50;
            margin: 15px 0 10px 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .params-block, .response-block {
            background: #fff;
            padding: 15px;
            border-radius: 4px;
            border: 1px solid #dee2e6;
            font-family: "Courier New", monospace;
            font-size: 13px;
            overflow-x: auto;
        }
        
        .param-item {
            padding: 8px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .param-item:last-child {
            border-bottom: none;
        }
        
        .param-name {
            color: #e67e22;
            font-weight: bold;
        }
        
        .param-type {
            color: #9b59b6;
            margin-left: 10px;
        }
        
        .param-desc {
            color: #7f8c8d;
            margin-left: 10px;
        }
        
        /* 滚动条样式 */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #34495e;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #5d6d7e;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #7f8c8d;
        }
        
        .main-content::-webkit-scrollbar-track {
            background: #ecf0f1;
        }
        
        .main-content::-webkit-scrollbar-thumb {
            background: #bdc3c7;
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h1>' . $docs['title'] . '</h1>
            <p>版本: ' . $docs['version'] . '</p>
            <p>' . $docs['base_url'] . '</p>
        </div>
        <div class="search-box">
            <input type="text" id="searchInput" placeholder="搜索接口...">
        </div>
        <div class="nav-menu" id="navMenu">';

        // 按应用分组展示
        foreach ($docs['apps'] as $appName => $app) {
            $html .= '<div class="nav-app">
                <div class="nav-app-title">' . $appName . ' <span class="toggle-icon">▼</span></div>
                <div class="nav-app-groups">';

            // 按路由分组展示
            foreach ($app['groups'] as $groupName => $group) {
                $html .= '<div class="nav-group">
                    <div class="nav-group-title">' . $groupName . ' <span class="toggle-icon">▼</span></div>
                    <div class="nav-routes">';

                foreach ($group['routes'] as $route) {
                    $routeId = 'route-' . md5($groupName . $route['path'] . $route['method']);
                    $html .= '<div class="nav-route" 
                    data-route-id="' . $routeId . '" 
                    data-path="' . htmlspecialchars($route['path']) . '"
                    data-method="' . $route['method'] . '">
                    <span class="nav-route-method ' . $route['method'] . '">' . $route['method'] . '</span>
                    <span class="nav-route-alias">' . htmlspecialchars($route['alias']) . '</span>
                </div>';
                }

                $html .= '</div></div>';  // 关闭 nav-routes 和 nav-group
            }

            $html .= '</div></div>';  // 关闭 nav-app-groups 和 nav-app
        }

        $html .= '</div>';  // 关闭 nav-menu
        $html .= '</div>';  // 关闭 sidebar

        $html .= '<div class="main-content">
        <div class="content-wrapper">
            <div class="header">
                <h1>' . $docs['title'] . '</h1>
                <p>' . $docs['description'] . '</p>
                <p>基础URL: ' . $docs['base_url'] . '</p>
            </div>';

        foreach ($docs['apps'] as $appName => $app) {
            $html .= '<div class="app-section" id="app-' . md5($appName) . '">
                <h2 class="app-title">' . $appName . '</h2>';

            foreach ($app['groups'] as $groupName => $group) {
                $html .= '<div class="group-section" id="group-' . md5($groupName) . '">
                    <h3 class="group-title">' . $groupName . '</h3>';

                foreach ($group['routes'] as $route) {
                    $routeId = 'route-' . md5($groupName . $route['path'] . $route['method']);
                    $html .= '<div class="route-card" id="' . $routeId . '">
                    <div class="route-header">
                        <span class="route-method ' . $route['method'] . '">' . $route['method'] . '</span>
                        <span class="route-path">' . htmlspecialchars($route['path']) . '</span>
                        <span class="route-title">' . htmlspecialchars($route['alias']) . '</span>
                    </div>
                    <p class="route-description">' . htmlspecialchars($route['description']) . '</p>';

                    if (!empty($route['params'])) {
                        $html .= '<div class="section-title">请求参数</div>
                        <div class="params-block">';
                        foreach ($route['params'] as $param => $desc) {
                            $parts = explode('|', $desc);
                            $type = $parts[0] ?? 'string';
                            $paramDesc = $parts[1] ?? '';
                            $html .= '<div class="param-item">
                            <span class="param-name">' . htmlspecialchars($param) . '</span>
                            <span class="param-type">[' . htmlspecialchars($type) . ']</span>
                            <span class="param-desc">' . htmlspecialchars($paramDesc) . '</span>
                        </div>';
                        }
                        $html .= '</div>';
                    }

                    if (!empty($route['response'])) {
                        $html .= '<div class="section-title">响应数据</div>
                        <div class="response-block">' . json_encode($route['response'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . '</div>';
                    }

                    $html .= '</div>';
                }

                $html .= '</div>';  // 关闭 group-section
            }

            $html .= '</div>';  // 关闭 app-section
        }

        $html .= '</div>
    
    <script>
        const navApps = document.querySelectorAll(".nav-app");
        const navGroups = document.querySelectorAll(".nav-group");
        const navRoutes = document.querySelectorAll(".nav-route");
        const searchInput = document.getElementById("searchInput");
        
        // 应用折叠/展开
        navApps.forEach(app => {
            const title = app.querySelector(".nav-app-title");
            title.addEventListener("click", () => {
                app.classList.toggle("collapsed");
            });
        });
        
        // 子分组折叠/展开
        navGroups.forEach(group => {
            const title = group.querySelector(".nav-group-title");
            title.addEventListener("click", () => {
                group.classList.toggle("collapsed");
            });
        });
        
        // 导航点击
        navRoutes.forEach(route => {
            route.addEventListener("click", () => {
                // 移除所有激活状态
                navRoutes.forEach(r => r.classList.remove("active"));
                
                // 添加当前激活状态
                route.classList.add("active");
                
                // 滚动到对应路由
                const routeId = route.dataset.routeId;
                const routeCard = document.getElementById(routeId);
                if (routeCard) {
                    routeCard.scrollIntoView({ behavior: "smooth", block: "start" });
                }
            });
        });
        
        // 搜索功能
        searchInput.addEventListener("input", (e) => {
            const keyword = e.target.value.toLowerCase().trim();
            
            navRoutes.forEach(route => {
                const alias = route.querySelector(".nav-route-alias").textContent.toLowerCase();
                const path = route.dataset.path ? route.dataset.path.toLowerCase() : "";
                const method = route.dataset.method ? route.dataset.method.toLowerCase() : route.querySelector(".nav-route-method").textContent.toLowerCase();
                
                if (keyword === "" || alias.includes(keyword) || path.includes(keyword) || method.includes(keyword)) {
                    route.style.display = "block";
                } else {
                    route.style.display = "none";
                }
            });
            
            // 展开所有包含匹配项的分组
            if (keyword !== "") {
                navApps.forEach(app => {
                    const visibleRoutes = app.querySelectorAll(".nav-route[style=\'display: block\']");
                    if (visibleRoutes.length > 0) {
                        app.classList.remove("collapsed");
                        // 展开子分组
                        const groups = app.querySelectorAll(".nav-group");
                        groups.forEach(group => {
                            const groupVisibleRoutes = group.querySelectorAll(".nav-route[style=\'display: block\']");
                            if (groupVisibleRoutes.length > 0) {
                                group.classList.remove("collapsed");
                            }
                        });
                    }
                });
            }
        });
        
    </script>
</body>
</html>';

        return $html;
    }

    /**
     * 生成 OpenAPI 格式文档
     */
    protected function generateOpenApi(array $docs): string
    {
        $openapi = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => $docs['title'],
                'description' => $docs['description'],
                'version' => $docs['version'],
            ],
            'servers' => [
                [
                    'url' => $docs['base_url'],
                    'description' => '开发环境',
                ],
            ],
            'paths' => [],
            'tags' => [],
        ];

        // 提取所有唯一的 tags（分组名称）
        foreach ($docs['apps'] as $appName => $app) {
            foreach ($app['groups'] as $groupName => $group) {
                // 为标签添加应用前缀，避免重复
                $tagKey = $appName . '/' . $groupName;
                $openapi['tags'][] = [
                    'name' => $tagKey,
                    'description' => $appName . ' - ' . $groupName . '相关接口',
                ];
            }
        }

        // 遍历所有应用、分组和路由
        foreach ($docs['apps'] as $appName => $app) {
            foreach ($app['groups'] as $groupName => $group) {
                foreach ($group['routes'] as $route) {
                    $method = strtolower($route['method']);
                    $path = '/' . ltrim($route['path'], '/');

                    $apiInfo = [
                        'summary' => $route['alias'],
                        'description' => $route['description'],
                        'tags' => [$appName . '/' . $groupName],
                    ];

                    // 处理参数
                    $parameters = [];
                    $requestBody = null;

                    foreach ($route['params'] as $paramName => $paramDesc) {
                        $parts = explode('|', $paramDesc);
                        $type = $parts[0] ?? 'string';
                        $desc = $parts[1] ?? '';

                        // 根据描述确定参数位置
                        if (str_contains($desc, 'URL参数')) {
                            // GET/DELETE 请求使用 query 参数
                            $parameters[] = [
                                'name' => $paramName,
                                'in' => 'query',
                                'description' => $desc,
                                'required' => !str_contains($desc, '可选'),
                                'schema' => [
                                    'type' => $this->mapType($type),
                                ],
                            ];
                        } elseif (str_contains($desc, '请求体参数')) {
                            // POST/PUT 请求使用请求体
                            if ($requestBody === null) {
                                $requestBody = [
                                    'description' => '请求体参数',
                                    'required' => true,
                                    'content' => [
                                        'application/json' => [
                                            'schema' => [
                                                'type' => 'object',
                                                'properties' => [],
                                            ],
                                        ],
                                        'application/x-www-form-urlencoded' => [
                                            'schema' => [
                                                'type' => 'object',
                                                'properties' => [],
                                            ],
                                        ],
                                    ],
                                ];
                            }

                            $schema = [
                                'type' => $this->mapType($type),
                                'description' => $desc,
                            ];

                            $requestBody['content']['application/json']['schema']['properties'][$paramName] = $schema;
                            $requestBody['content']['application/x-www-form-urlencoded']['schema']['properties'][$paramName] = $schema;
                        }
                    }

                    // 添加 parameters 到接口信息
                    if (!empty($parameters)) {
                        $apiInfo['parameters'] = $parameters;
                    }

                    // 添加请求体到接口信息
                    if ($requestBody !== null) {
                        $apiInfo['requestBody'] = $requestBody;
                    }

                    $openapi['paths'][$path][$method] = $apiInfo;
                }
            }
        }

        return json_encode($openapi, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * 映射 PHP 类型到 OpenAPI 类型
     */
    protected function mapType(string $phpType): string
    {
        $typeMap = [
            'int' => 'integer',
            'integer' => 'integer',
            'float' => 'number',
            'double' => 'number',
            'bool' => 'boolean',
            'boolean' => 'boolean',
            'string' => 'string',
            'array' => 'array',
            'object' => 'object',
        ];

        return $typeMap[strtolower($phpType)] ?? 'string';
    }
}