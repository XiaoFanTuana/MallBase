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
    public function getTree(array $where = []): array
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

        return $this->buildTree($list);
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
}