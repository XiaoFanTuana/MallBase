<?php

/**
 * 路由权限同步命令
 *
 * 功能说明：
 * - 读取所有路由配置
 * - 自动同步路由信息到权限表
 * - 以路由为主进行增量同步
 * - 通过 code 字段确定 parent_id
 * - 保留前端菜单权限
 *
 * 使用示例：
 * ```bash
 * # 同步路由到权限表（增量更新）
 * php think sync:permissions
 *
 * # 预览即将同步的权限（不实际执行）
 * php think sync:permissions --preview
 *
 * # 显示路由树结构
 * php think sync:permissions --tree
 * ```
 *
 * @package app\command
 */

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

class SyncPermissions extends Command
{
    /**
     * 权限类型映射
     */
    const TYPE_MENU = 1;      // 菜单
    const TYPE_BUTTON = 2;    // 按钮
    const TYPE_API = 3;       // 接口

    /**
     * 数据库表默认值
     */
    const DB_DEFAULTS = [
        'type' => 1,
        'path' => null,
        'icon' => null,
        'component' => null,
        'redirect' => null,
        'sort' => 0,
        'status' => 1,
        'is_show' => 1,
        'affix_tab' => 0,
        'no_basic_layout' => 0,
        'remark' => null,
    ];

    /**
     * 路由数据
     * @var array
     */
    protected $routeData = [];

    /**
     * 基础菜单数据
     * @var array
     */
    protected $baseMenus = [];

    /**
     * 数据库权限数据（按 code 索引）
     * @var array
     */
    protected $dbPermissions = [];

    /**
     * 数据库权限数据（按 name 索引，用于查找 parent_id）
     * @var array
     */
    protected $dbPermissionsByName = [];

    /**
     * 待创建的权限
     * @var array
     */
    protected $toCreate = [];

    /**
     * 待更新的权限
     * @var array
     */
    protected $toUpdate = [];

    /**
     * 待删除的权限 code 列表
     * @var array
     */
    protected $toDelete = [];

    /**
     * 配置命令
     */
    protected function configure()
    {
        $this->setName('sync:permissions')
            ->setDescription('同步路由配置到权限表')
            ->addOption('preview', 'p', Option::VALUE_NONE, '预览模式（不实际执行）')
            ->addOption('tree', 't', Option::VALUE_NONE, '显示路由树结构');
    }

    /**
     * 执行命令
     */
    protected function execute(Input $input, Output $output)
    {
        $preview = $input->getOption('preview');
        $showTree = $input->getOption('tree');

        $output->writeln('<info>开始同步路由到权限表...</info>');

        if ($preview) {
            $output->writeln('<comment>预览模式：不会实际修改数据库</comment>');
        }

        // 1. 读取路由配置
        $output->writeln('<comment>1. 读取路由配置...</comment>');
        $this->loadRoutes($output);

        // 2. 显示路由树（如果指定了 --tree 选项）
        if ($showTree) {
            $this->showMenuTree($output);
            return;
        }

        // 3. 读取数据库权限
        $output->writeln('<comment>2. 读取数据库权限...</comment>');
        $this->loadDbPermissions();

        // 4. 比对数据，确定操作
        $output->writeln('<comment>3. 比对数据...</comment>');
        $this->compareData();

        // 5. 预览或执行同步
        if ($preview) {
            $this->previewSync($output);
        } else {
            $this->executeSync($output);
        }

        $output->writeln('<info>同步完成！</info>');
    }

    /**
     * 加载路由配置
     */
    protected function loadRoutes($output)
    {
        // 1. 加载基础菜单
        $this->loadBaseMenus();

        // 2. 加载各模块路由
        $this->loadAppRoutes();

        $output->writeln('<info>   加载菜单数: ' . count($this->baseMenus) . '</info>');
        $output->writeln('<info>   加载路由数: ' . count($this->routeData) . '</info>');
    }

    /**
     * 加载基础菜单
     */
    protected function loadBaseMenus()
    {
        $config = config('permissions');
        $this->baseMenus = $config['base_menus'] ?? [];
    }

    /**
     * 加载各模块路由
     */
    protected function loadAppRoutes()
    {
        $config = config('permissions');
        $apps = $config['app'] ?? [];

        foreach ($apps as $appName => $appConfig) {
            // 清除路由缓存
            $this->app->route->clear();
            $this->app->route->lazy(false);

            $path = $this->app->getRootPath() . 'route' . DIRECTORY_SEPARATOR . $appName . '.php';

            if (!is_file($path)) {
                continue;
            }

            include $path;

            // 获取路由列表
            $routeList = $this->app->route->getRuleList();

            // 处理所有路由
            foreach ($routeList as $route) {
                $option = $route['option'] ?? [];

                // 只处理有 _alias 的路由
                if (!isset($option['_alias'])) {
                    continue;
                }

                // 获取路由 name
                $routeName = $route['name'] ?? '';
                if (empty($routeName)) {
                    continue;
                }

                // 过滤：只处理 _auth 为 true 或类型为菜单的路由
                $type = $option['_type'] ?? null;

                // 检查是否有 without_middleware（排除了 JwtAuth 和 CheckPermission 的路由不需要权限控制）
                $withoutMiddleware = $option['without_middleware'] ?? [];
                $hasNoAuthMiddleware = in_array('app\\admin\\middleware\\JwtAuth', $withoutMiddleware) ||
                    in_array('app\\admin\\middleware\\CheckPermission', $withoutMiddleware);

                if ($type === null && !($option['_auth'] ?? false)) {
                    // 既不是菜单，也没有 _auth=true，跳过
                    continue;
                }

                // 如果路由排除了权限中间件，则不应该同步到权限表
                if ($hasNoAuthMiddleware) {
                    continue;
                }

                // 构建路由数据
                $this->routeData[$routeName] = $this->parseRouteOption($routeName, $route, $option);
            }
        }
    }

    /**
     * 解析路由 option
     */
    protected function parseRouteOption($routeName, $route, $option)
    {
        // 确定权限类型
        $type = $option['_type'] ?? null;

        if ($type === null) {
            // 没指定类型时：
            // 1. 路由有实际的 rule（路径）的，默认为接口
            // 2. 没有 rule 的（group 级别的），默认为菜单
            if (!empty($route['rule']) && $route['rule'] !== '/') {
                // 有实际路由路径的是接口
                $type = self::TYPE_API;
            } else {
                // 没有 rule 的是菜单（group 级别）
                $type = self::TYPE_MENU;
            }
        } else {
            // 支持数字和字符串类型
            if (is_numeric($type)) {
                // 数字类型，直接使用
                $type = (int)$type;
            } else {
                // 字符串类型，映射到常量
                $typeMap = [
                    'menu' => self::TYPE_MENU,
                    'button' => self::TYPE_BUTTON,
                    'api' => self::TYPE_API,
                ];
                $type = $typeMap[strtolower($type)] ?? self::TYPE_API;
            }
        }

        // 确定父级权限的 code
        $parentCode = '';
        if ($type === self::TYPE_MENU) {
            // 菜单类型：使用 _parent 字段（是 code）
            $parentCode = $option['_parent'] ?? '';
        } else {
            // 接口类型：也使用 _parent 字段（路由组的 _parent）
            // 这样接口可以直接挂在路由组的父级菜单下
            $parentCode = $option['_parent'] ?? '';
            
            // 如果没有 _parent，尝试使用 _group_name 查找
            if (empty($parentCode)) {
                $groupName = $option['_group_name'] ?? '';
                if (!empty($groupName)) {
                    // 在 base_menus 或 routeData 中查找对应的菜单
                    $parentCode = $this->findMenuCodeByName($groupName);
                }
            }
        }

        // 构建基础数据
        $data = [
            'code' => $routeName,
            'name' => $option['_alias'] ?? '',
            'type' => $type,
            'parent_code' => $parentCode,
        ];

        // 添加其他可选字段（路由中定义的）
        $fieldMap = [
            '_path' => 'path',
            '_icon' => 'icon',
            '_component' => 'component',
            '_redirect' => 'redirect',
            '_sort' => 'sort',
            '_status' => 'status',
            '_is_show' => 'is_show',
            '_affix_tab' => 'affix_tab',
            '_no_basic_layout' => 'no_basic_layout',
            '_remark' => 'remark',
            '_desc' => 'remark', // _desc 别名
        ];

        foreach ($fieldMap as $routeKey => $dbField) {
            if (isset($option[$routeKey])) {
                $data[$dbField] = $option[$routeKey];
            }
        }

        // 处理路由路径（只有接口才有实际路由路径）
        if ($type === self::TYPE_API && !isset($data['path'])) {
            $data['path'] = ltrim($route['rule'], '/');
        }

        // 接口默认不显示
        if ($type === self::TYPE_API && !isset($data['is_show'])) {
            $data['is_show'] = 0;
        }

        return $data;
    }

    /**
     * 根据名称查找菜单的 code（递归）
     * 先在 base_menus 中查找，再在 routeData 中查找
     * 
     * @param string $name 菜单名称
     * @param array $menus 菜单数组
     * @return string
     */
    protected function findMenuCodeByName($name, $menus = null)
    {
        if ($menus === null) {
            $menus = $this->baseMenus;
        }

        // 先在 base_menus 中查找
        foreach ($menus as $menu) {
            if ($menu['name'] === $name) {
                return $menu['code'];
            }

            // 递归查找子菜单
            if (isset($menu['children']) && !empty($menu['children'])) {
                $code = $this->findMenuCodeByName($name, $menu['children']);
                if ($code) {
                    return $code;
                }
            }
        }

        // 如果在 base_menus 中没找到，在 routeData 中查找（路由组创建的菜单）
        foreach ($this->routeData as $code => $route) {
            if ($route['type'] === self::TYPE_MENU && $route['name'] === $name) {
                return $code;
            }
        }

        return '';
    }

    /**
     * 加载数据库权限
     */
    protected function loadDbPermissions()
    {
        // 加载所有权限数据
        $permissions = Db::name('permission')
            ->select()
            ->toArray();

        foreach ($permissions as $permission) {
            $code = $permission['code'];

            // 按 code 索引
            $this->dbPermissions[$code] = $permission;

            // 按 name 索引（用于查找 parent_id）
            if (!empty($permission['name'])) {
                $this->dbPermissionsByName[$permission['name']] = $permission;
            }
        }
    }

    /**
     * 比对数据
     */
    protected function compareData()
    {
        // 先遍历 base_menus，标记哪些需要创建或更新
        $this->compareMenuData($this->baseMenus, '');

        // 再遍历路由数据，标记哪些需要创建或更新
        foreach ($this->routeData as $code => $route) {
            $dbPermission = $this->dbPermissions[$code] ?? null;

            if ($dbPermission) {
                // 数据库中已存在，需要更新
                $this->toUpdate[] = [
                    'code' => $code,
                    'db_id' => $dbPermission['id'],
                    'data' => $this->buildRoutePermissionData($route, false),
                ];
            } else {
                // 数据库中不存在，需要创建
                $this->toCreate[] = [
                    'code' => $code,
                    'data' => $this->buildRoutePermissionData($route, false),
                ];
            }
        }

        // 找出需要删除的权限（只在数据库中存在，但路由中没有的）
        foreach ($this->dbPermissions as $code => $permission) {
            // 只删除接口权限（type=3），保留菜单和按钮权限
            if ($permission['type'] === self::TYPE_API) {
                if (!isset($this->routeData[$code])) {
                    $this->toDelete[] = $code;
                }
            }
        }
    }

    /**
     * 比对菜单数据（递归）
     * 
     * @param array $menus 菜单数组
     * @param string $parentCode 父级 code
     */
    protected function compareMenuData($menus, $parentCode)
    {
        foreach ($menus as $menu) {
            $code = $menu['code'];
            $dbPermission = $this->dbPermissions[$code] ?? null;

            if ($dbPermission) {
                // 数据库中已存在，需要更新
                $this->toUpdate[] = [
                    'code' => $code,
                    'db_id' => $dbPermission['id'],
                    'data' => $this->buildMenuPermissionData($menu, $parentCode, false),
                ];
            } else {
                // 数据库中不存在，需要创建
                $this->toCreate[] = [
                    'code' => $code,
                    'data' => $this->buildMenuPermissionData($menu, $parentCode, false),
                ];
            }

            // 递归处理子菜单
            if (isset($menu['children']) && !empty($menu['children'])) {
                $this->compareMenuData($menu['children'], $code);
            }
        }
    }

    /**
     * 构建 base_menus 中菜单的权限数据
     *
     * @param array $menu 菜单数据
     * @param string $parentCode 父级 code
     * @param bool $setParentId 是否设置 parent_id
     * @return array
     */
    protected function buildMenuPermissionData($menu, $parentCode = '', $setParentId = true)
    {
        // 构建数据
        $data = [
            'code' => $menu['code'],
            'name' => $menu['name'],
            'type' => $menu['type'],
            'path' => $menu['path'] ?? null,
            'icon' => $menu['icon'] ?? null,
            'component' => $menu['component'] ?? null,
            'redirect' => $menu['redirect'] ?? null,
            'sort' => $menu['sort'] ?? 0,
            'status' => $menu['status'] ?? 1,
            'is_show' => $menu['is_show'] ?? 1,
            'affix_tab' => $menu['affix_tab'] ?? 0,
            'no_basic_layout' => $menu['no_basic_layout'] ?? 0,
            'remark' => $menu['remark'] ?? null,
            'parent_id' => 0,
        ];

        if ($setParentId && !empty($parentCode)) {
            // 查找父级权限
            $parentPermission = $this->dbPermissions[$parentCode] ?? null;
            $data['parent_id'] = $parentPermission ? $parentPermission['id'] : 0;
        }

        // 添加更新时间
        $data['update_time'] = date('Y-m-d H:i:s');

        return $data;
    }

    /**
     * 构建路由权限数据
     *
     * @param array $route 路由数据
     * @param bool $setParentId 是否设置 parent_id
     * @return array
     */
    protected function buildRoutePermissionData($route, $setParentId = true)
    {
        // 构建数据，保留路由中定义的字段，未定义的用数据库默认值
        $data = array_merge(self::DB_DEFAULTS, $route);

        if ($setParentId) {
            // 查找父级权限
            $parentPermission = null;
            $parentCode = $route['parent_code'] ?? '';

            if (!empty($parentCode)) {
                $parentPermission = $this->dbPermissions[$parentCode] ?? null;
            }

            $data['parent_id'] = $parentPermission ? $parentPermission['id'] : 0;
        } else {
            // 不设置 parent_id，暂时为 0
            $data['parent_id'] = 0;
        }

        // 移除不需要的字段
        unset($data['parent_code']);

        // 添加更新时间
        $data['update_time'] = date('Y-m-d H:i:s');

        return $data;
    }

    /**
     * 预览同步
     */
    protected function previewSync($output)
    {
        $output->writeln('');
        $output->writeln('<info>=== 预览结果 ===</info>');

        // 构建 code 到名称的映射
        $codeToName = [];
        foreach ($this->dbPermissions as $perm) {
            $codeToName[$perm['code']] = $perm['name'];
        }
        foreach ($this->toCreate as $item) {
            $codeToName[$item['code']] = $item['data']['name'];
        }

        // 辅助函数：获取父级名称
        $getParentName = function ($item) use ($codeToName) {
            $code = $item['code'];
            $data = $this->routeData[$code] ?? null;

            if ($data === null) {
                // 可能是菜单数据
                return '无';
            }

            $parentCode = $data['parent_code'] ?? '';
            if (empty($parentCode)) {
                return '无';
            }

            return $codeToName[$parentCode] ?? '无';
        };

        $output->writeln('');
        $output->writeln('<comment>1. 将新增的权限 (' . count($this->toCreate) . '):</comment>');
        foreach ($this->toCreate as $item) {
            $data = $item['data'];
            $typeName = $this->getTypeName($data['type']);
            $parentName = $getParentName($item);

            $output->writeln("   - {$item['code']} ({$data['name']}) type:{$typeName} parent:{$parentName}");
        }

        $output->writeln('');
        $output->writeln('<comment>2. 将更新的权限 (' . count($this->toUpdate) . '):</comment>');
        foreach ($this->toUpdate as $item) {
            $data = $item['data'];
            $typeName = $this->getTypeName($data['type']);
            $parentName = $getParentName($item);

            $output->writeln("   - {$item['code']} ({$data['name']}) type:{$typeName} parent:{$parentName}");
        }

        $output->writeln('');
        $output->writeln('<comment>3. 将删除的权限 (' . count($this->toDelete) . '):</comment>');
        foreach ($this->toDelete as $code) {
            $permission = $this->dbPermissions[$code];
            $output->writeln("   - {$code} ({$permission['name']})");
        }

        $output->writeln('');
        $output->writeln('<info>=== 统计 ===</info>');
        $output->writeln("新增: " . count($this->toCreate));
        $output->writeln("更新: " . count($this->toUpdate));
        $output->writeln("删除: " . count($this->toDelete));
    }

    /**
     * 执行同步
     */
    protected function executeSync($output)
    {
        $startTime = microtime(true);

        // 启动事务
        Db::startTrans();
        try {
            $createdCount = 0;
            $updatedCount = 0;
            $deletedCount = 0;

            // 1. 创建新权限
            if (!empty($this->toCreate)) {
                $output->writeln('<comment>1. 创建新权限...</comment>');
                foreach ($this->toCreate as $item) {
                    // 添加创建时间
                    $item['data']['create_time'] = date('Y-m-d H:i:s');

                    $insertId = Db::name('permission')->insertGetId($item['data']);
                    $output->writeln("   - 创建: {$item['code']} (ID: {$insertId})");
                    $createdCount++;
                }
            }

            // 2. 更新现有权限
            if (!empty($this->toUpdate)) {
                $output->writeln('<comment>2. 更新现有权限...</comment>');
                foreach ($this->toUpdate as $item) {
                    Db::name('permission')
                        ->where('id', $item['db_id'])
                        ->update($item['data']);
                    $output->writeln("   - 更新: {$item['code']}");
                    $updatedCount++;
                }
            }

            // 3. 删除无效权限
            if (!empty($this->toDelete)) {
                $output->writeln('<comment>3. 删除无效权限...</comment>');
                foreach ($this->toDelete as $code) {
                    Db::name('permission')->where('code', $code)->delete();
                    $output->writeln("   - 删除: {$code}");
                    $deletedCount++;
                }
            }

            // 提交事务
            Db::commit();

            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);

            $output->writeln('');
            $output->writeln('<info>=== 同步完成 ===</info>');
            $output->writeln("新增: {$createdCount}");
            $output->writeln("更新: {$updatedCount}");
            $output->writeln("删除: {$deletedCount}");
            $output->writeln("耗时: {$duration}ms");

        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            $output->writeln('<error>同步失败: ' . $e->getMessage() . '</error>');
            $output->writeln('<error>错误位置: ' . $e->getFile() . ':' . $e->getLine() . '</error>');
            throw $e;
        }
    }

    /**
     * 获取类型名称
     */
    protected function getTypeName($type)
    {
        $typeMap = [
            self::TYPE_MENU => '菜单',
            self::TYPE_BUTTON => '按钮',
            self::TYPE_API => '接口',
        ];
        return $typeMap[$type] ?? '未知';
    }

    /**
     * 构建菜单树（基于 base_menus，递归）
     * 
     * @param array $menus 菜单数组
     * @return array
     */
    protected function buildMenuTree($menus = null)
    {
        if ($menus === null) {
            $menus = $this->baseMenus;
        }

        $tree = [];

        foreach ($menus as $menu) {
            $node = [
                'name' => $menu['name'],
                'code' => $menu['code'],
                'type' => $menu['type'],
                'path' => $menu['path'] ?? null,
                'icon' => $menu['icon'] ?? null,
                'component' => $menu['component'] ?? null,
                'redirect' => $menu['redirect'] ?? null,
                'sort' => $menu['sort'] ?? 0,
                'status' => $menu['status'] ?? 1,
                'is_show' => $menu['is_show'] ?? 1,
                'affix_tab' => $menu['affix_tab'] ?? 0,
                'no_basic_layout' => $menu['no_basic_layout'] ?? 0,
                'remark' => $menu['remark'] ?? null,
            ];

            // 查找该菜单下的路由
            $children = $this->findRouteChildren($menu['code']);
            if (!empty($children)) {
                $node['children'] = $children;
            }

            // 如果有子菜单，递归处理
            if (isset($menu['children']) && !empty($menu['children'])) {
                $subMenuChildren = $this->buildMenuTree($menu['children']);
                if (!empty($subMenuChildren)) {
                    $node['children'] = isset($node['children']) ? array_merge($node['children'], $subMenuChildren) : $subMenuChildren;
                }
            }

            $tree[] = $node;
        }

        return $tree;
    }

    /**
     * 查找菜单下的路由
     * 
     * @param string $menuCode 菜单 code
     * @return array
     */
    protected function findRouteChildren($menuCode)
    {
        $children = [];

        foreach ($this->routeData as $code => $route) {
            $parentCode = $route['parent_code'] ?? '';
            if ($parentCode === $menuCode) {
                $children[] = [
                    'name' => $route['name'],
                    'code' => $route['code'],
                    'type' => $route['type'],
                    'path' => $route['path'] ?? null,
                    'icon' => $route['icon'] ?? null,
                    'component' => $route['component'] ?? null,
                    'redirect' => $route['redirect'] ?? null,
                    'sort' => $route['sort'] ?? 0,
                    'status' => $route['status'] ?? 1,
                    'is_show' => $route['is_show'] ?? 1,
                    'affix_tab' => $route['affix_tab'] ?? 0,
                    'no_basic_layout' => $route['no_basic_layout'] ?? 0,
                    'remark' => $route['remark'] ?? null,
                ];
            }
        }

        return $children;
    }

    /**
     * 显示菜单树
     * 
     * @param Output $output
     */
    protected function showMenuTree($output)
    {
        $tree = $this->buildMenuTree();
        
        $output->writeln('');
        $output->writeln('<info>=== 路由树结构 ===</info>');
        $output->writeln('');
        
        $this->printTree($tree, 0, $output);
    }

    /**
     * 打印树结构
     * 
     * @param array $tree 树数据
     * @param int $level 层级
     * @param Output $output
     */
    protected function printTree($tree, $level, $output)
    {
        foreach ($tree as $node) {
            $indent = str_repeat('  ', $level);
            $typeName = $this->getTypeName($node['type']);
            $prefix = $node['type'] === self::TYPE_MENU ? '📁 ' : '🔗 ';
            
            $output->writeln("{$indent}{$prefix}[{$typeName}] {$node['name']} ({$node['code']})");
            
            if (isset($node['children']) && !empty($node['children'])) {
                $this->printTree($node['children'], $level + 1, $output);
            }
        }
    }
}