<?php

declare (strict_types=1);

namespace app\admin\controller\auth;

use app\admin\service\auth\PermissionService;
use app\admin\validate\auth\PermissionValidate;
use mall_base\base\BaseController;

/**
 * 权限控制器
 * @extends BaseController<PermissionService>
 */
class PermissionController extends BaseController
{
    /**
     * 默认 Service 类名
     */
    protected string $serviceClass = PermissionService::class;

    /**
     * 获取树形列表
     */
    public function tree()
    {
        $where = $this->request->param(['keyword', 'type', 'status']);

        $tree = $this->service()->getTree($where);
        return $this->success($tree, '获取成功');
    }

    /**
     * 获取菜单路由
     */
    public function menu()
    {
        $where = $this->request->param(['keyword', 'type', 'status']);

        // 只获取菜单类型的权限，且状态为启用
        $where['type'] = 1;
        $where['status'] = 1;

        $tree = $this->service()->getTree($where);

        return $this->success($tree, '获取成功');

    }


    /**
     * 获取列表
     */
    public function list()
    {
        $where = $this->request->param(['keyword', 'type', 'status']);

        // 获取分页参数
        [$page, $limit] = $this->getPagination(1, 15);

        $list = $this->service()->getList($where, $page, $limit);
        return $this->success($list, '获取成功');

    }

    /**
     * 获取详情
     */
    public function info($id)
    {
        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $info = $this->service()->getInfo((int)$id);
        return $this->success($info, '获取成功');
    }

    /**
     * 创建
     */
    public function create()
    {
        $data = $this->request->param(['parent_id', 'name', 'code', 'type', 'path', 'icon', 'component', 'redirect', 'affix_tab', 'no_basic_layout', 'sort', 'status', 'is_show', 'remark']);

        // 验证创建参数
        $this->validate($data, PermissionValidate::class . '.create');

        try {
            $id = $this->service()->create($data);
            return $this->success(['id' => $id], '创建成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 更新
     */
    public function update($id)
    {
        $data = $this->request->param(['parent_id', 'name', 'code', 'type', 'path', 'icon', 'component', 'redirect', 'affix_tab', 'no_basic_layout', 'sort', 'status', 'is_show', 'remark']);

        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        // 验证更新参数
        $this->validate($data, PermissionValidate::class . '.update');

        try {
            $this->service()->update((int)$id, $data);
            return $this->success(null, '更新成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 删除
     */
    public function delete($id)
    {
        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $this->service()->delete((int)$id);
        return $this->success(null, '删除成功');
    }

    /**
     * 批量更新字段（处理上下级关系）
     */
    public function batchUpdate($id)
    {
        $field = $this->request->param('field'); // status, is_show, affix_tab, no_basic_layout
        $value = $this->request->param('value');
        $includeChildren = $this->request->param('include_children', true); // 是否同时更新子节点

        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        if (empty($field)) {
            return $this->error('字段不能为空');
        }

        // 验证字段是否合法
        $validFields = ['status', 'is_show', 'affix_tab', 'no_basic_layout'];
        if (!in_array($field, $validFields)) {
            return $this->error('字段不合法');
        }

        // 构建更新数据
        $updateData = [$field => $value];

        // 更新当前节点
        $this->service()->update((int)$id, $updateData);

        // 如果需要同时更新子节点，获取所有子节点ID
        if ($includeChildren) {
            $childIds = $this->service()->getAllChildIds((int)$id);
            if (!empty($childIds)) {
                foreach ($childIds as $childId) {
                    $this->service()->update($childId, $updateData);
                }
            }
        }

        return $this->success(null, '更新成功');

    }
}
