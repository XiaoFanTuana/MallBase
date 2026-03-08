<?php

declare (strict_types=1);

namespace app\admin\service\auth;

use app\admin\model\auth\Permission as PermissionModel;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 权限服务
 * @extends BaseService<PermissionModel>
 */
class PermissionService extends BaseService
{
    /**
     * Model 类名
     */
    protected string $modelClass = PermissionModel::class;

    /**
     * 获取权限树形列表
     */
    public function getTree(array $where = [], $is_menu = false): array
    {
        $keyword = $where['keyword'] ?? '';
        $type = $where['type'] ?? null;
        $status = $where['status'] ?? null;

        $query = $this->model()->order('sort', 'asc')->order('id', 'asc');

        // 关键字搜索
        if ($keyword) {
            $query->whereLike('name|code', "%{$keyword}%");
        }

        // 类型筛选
        if ($type !== null) {
            $query->where('type', $type);
        }

        // 状态筛选
        if ($status !== null) {
            $query->where('status', $status);
        }

        $list = $query->select()->toArray();
        $tree = $this->buildTree($list);
        if ($is_menu) {
            // 转换为前端路由格式
            return $this->transformToRoutes($tree);
        } else {
            return $tree;
        }
    }

    /**
     * 获取权限列表（不分页）
     */
    public function getList(array $where = [], int $page = 1, int $limit = 10): array
    {
        $keyword = $where['keyword'] ?? '';
        $type = $where['type'] ?? null;
        $status = $where['status'] ?? null;

        $query = $this->model()->order('sort', 'asc')->order('id', 'asc');

        // 关键字搜索
        if ($keyword) {
            $query->whereLike('name|code', "%{$keyword}%");
        }

        // 类型筛选
        if ($type !== null) {
            $query->where('type', $type);
        }

        // 状态筛选
        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->page($page, $limit)->select()->toArray();
    }

    /**
     * 获取权限详情
     */
    public function getInfo(int $id): array
    {
        $permission = $this->model()->find($id);

        if (!$permission) {
            throw new BusinessException('权限不存在');
        }

        return $permission->toArray();
    }

    /**
     * 创建权限
     */
    public function create(array $data): int
    {
        // 检查权限编码是否存在
        if ($this->model()->where('code', $data['code'])->find()) {
            throw new BusinessException('权限编码已存在');
        }

        return $this->transaction(function () use ($data) {
            $permission = $this->model();
            $permission->save([
                'parent_id' => $data['parent_id'] ?? 0,
                'name' => $data['name'],
                'code' => $data['code'],
                'type' => $data['type'] ?? 1,
                'path' => $data['path'] ?? '',
                'icon' => $data['icon'] ?? '',
                'component' => $data['component'] ?? '',
                'redirect' => $data['redirect'] ?? '',
                'affix_tab' => $data['affix_tab'] ?? 0,
                'no_basic_layout' => $data['no_basic_layout'] ?? 0,
                'sort' => $data['sort'] ?? 0,
                'status' => $data['status'] ?? 1,
                'is_show' => $data['is_show'] ?? 1,
                'remark' => $data['remark'] ?? '',
            ]);

            return $permission->id;
        });
    }

    /**
     * 更新权限
     */
    public function update(int $id, array $data): bool
    {
        $permission = $this->model()->find($id);
        if (!$permission) {
            throw new BusinessException('权限不存在');
        }

        // 不允许将父级设置为自己或自己的子级
        if (isset($data['parent_id']) && $data['parent_id'] == $id) {
            throw new BusinessException('不能将自己设置为父级');
        }

        // 检查权限编码是否重复
        if (!empty($data['code']) && $data['code'] !== $permission->code) {
            if ($this->model()->where('code', $data['code'])->where('id', '<>', $id)->find()) {
                throw new BusinessException('权限编码已存在');
            }
        }

        $this->model()->updateById($id, $data);
        return true;
    }

    /**
     * 删除权限
     */
    public function delete(int $id): bool
    {
        $permission = $this->model()->find($id);
        if (!$permission) {
            throw new BusinessException('权限不存在');
        }

        // 检查是否有子级
        if ($this->model()->where('parent_id', $id)->count() > 0) {
            throw new BusinessException('请先删除子权限');
        }

        // 删除权限
        $permission->delete();

        return true;
    }

    /**
     * 获取所有子节点 ID（递归）
     */
    public function getAllChildIds(int $parentId): array
    {
        $ids = [];
        $children = $this->model()->where('parent_id', $parentId)->column('id');

        if (!empty($children)) {
            $ids = array_merge($ids, $children);
            foreach ($children as $childId) {
                $ids = array_merge($ids, $this->getAllChildIds($childId));
            }
        }

        return $ids;
    }

    /**
     * 构建树形结构
     */
    protected function buildTree(array $list, int $parentId = 0): array
    {
        $tree = [];
        foreach ($list as $item) {
            if ($item['parent_id'] == $parentId) {
                $children = $this->buildTree($list, $item['id']);
                if (!empty($children)) {
                    $item['children'] = $children;
                }
                $tree[] = $item;
            }
        }
        return $tree;
    }


    /**
     * 转换为前端路由格式
     */
    protected function transformToRoutes($nodes)
    {
        $routes = [];
        foreach ($nodes as $node) {
            $route = [
                'name' => convertToRouteName($node['code']),
                'path' => $node['path'] ?: '/' . strtolower($node['code']),
                'meta' => [
                    'title' => $node['name'],
                ],
            ];

            // 如果有图标，添加到 meta
            if (!empty($node['icon'])) {
                $route['meta']['icon'] = $node['icon'];
            }

            // 如果有排序，添加到 meta
            if (!empty($node['sort'])) {
                $route['meta']['order'] = $node['sort'];
            }

            // 如果需要固定标签页，添加 affixTab
            if (!empty($node['affix_tab'])) {
                $route['meta']['affixTab'] = true;
            }

            // 如果有组件路径，添加 component
            if (!empty($node['component'])) {
                // 移除 views/ 前缀和 .vue 后缀，只保留相对路径
                $component = $node['component'];
                if (strpos($component, 'views/') === 0) {
                    $component = substr($component, 6); // 移除 "views/" 前缀
                }
                // 移除 .vue 后缀
                $component = str_replace('.vue', '', $component);
                // 添加前缀斜杠
                $route['component'] = '/' . $component;
            }

            // 如果有 redirect，添加到路由
            if (!empty($node['redirect'])) {
                $route['redirect'] = $node['redirect'];
            }

            // 处理特殊配置（如 noBasicLayout）
            if (!empty($node['no_basic_layout'])) {
                $route['meta']['noBasicLayout'] = true;
            }

            // 如果有子节点，递归处理
            if (!empty($node['children']) && is_array($node['children'])) {
                $route['children'] = $this->transformToRoutes($node['children']);
            }

            $routes[] = $route;
        }
        return $routes;
    }
}