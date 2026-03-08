<?php

declare (strict_types=1);

namespace app\admin\controller\auth;

use app\admin\service\auth\RoleService;
use app\admin\validate\auth\RoleValidate;
use mall_base\base\BaseController;

/**
 * 角色控制器
 * @extends BaseController<RoleService>
 */
class RoleController extends BaseController
{
    /**
     * 默认 Service 类名
     */
    protected string $serviceClass = RoleService::class;

    /**
     * 获取列表
     */
    public function list()
    {
        $where = $this->request->param(['name', 'code', 'status']);

        // 获取分页参数
        [$page, $limit] = $this->getPagination(1, 15);

        try {
            $list = $this->service()->getList($where, $page, $limit);
            return $this->success($list, '获取成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取所有角色
     */
    public function all()
    {
        try {
            $list = $this->service()->getAll();
            return $this->success($list, '获取成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取详情
     */
    public function info($id)
    {
        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        try {
            $info = $this->service()->getInfo((int)$id);
            return $this->success($info, '获取成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 创建
     */
    public function create()
    {
        $data = $this->request->param(['name', 'code', 'status', 'sort', 'remark', 'permission_ids']);

        // 验证创建参数
        $this->validate($data, RoleValidate::class . '.create');

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
        $data = $this->request->param(['name', 'code', 'status', 'sort', 'remark', 'permission_ids']);

        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        // 验证更新参数
        $this->validate($data, RoleValidate::class . '.update');

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

        try {
            $this->service()->delete((int)$id);
            return $this->success(null, '删除成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }
}