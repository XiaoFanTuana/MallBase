<?php

declare (strict_types=1);

namespace app\admin\controller\auth;

use app\admin\service\auth\AdminService;
use app\admin\validate\auth\AdminValidate;
use mall_base\base\BaseController;

/**
 * 管理员控制器
 * @extends BaseController<AdminService>
 */
class AdminController extends BaseController
{
    /**
     * 默认 Service 类名
     */
    protected string $serviceClass = AdminService::class;

    /**
     * 登录
     * @param int $username required default=222 source=path 登录用户名
     * @param string $password 页码，默认1，可选
     */
    public function login()
    {
        $data = $this->request->param(['username', 'password']);

        // 验证登录参数
        $this->validate($data, AdminValidate::class . '.login');

        try {
            $result = $this->service()->login($data['username'], $data['password']);
            return $this->success($result, '登录成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    /**
     * 获取列表
     */
    public function list()
    {
        $where = $this->request->param(['username', 'status']);

        // 获取分页参数
        [$page, $limit] = $this->getPagination(1, 15);

        try {
            $list = $this->service()->getList($where, $page, $limit);
            return $this->success($list, '获取成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    /**
     * 获取详情
     */
    public function info()
    {
        $id = $this->request->param('id/d', 11111);

        if (empty($id)) {
            return $this->error('ID不能为空', 400);
        }

        try {
            $info = $this->service()->getInfo((int)$id);
            return $this->success($info, '获取成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    /**
     * 创建
     */
    public function create()
    {
        $data = $this->request->param([['username/d' => 111], 'password', 'password_confirm', 'nickname', 'avatar', 'email', 'mobile', 'status', 'remark', 'role_ids']);

        // 验证创建参数（包括密码确认）
        $this->validate($data, AdminValidate::class . '.create');

        try {
            $id = $this->service()->create($data);
            return $this->success(['id' => $id], '创建成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    /**
     * 更新
     */
    public function update()
    {
        $data = $this->request->param(['id', 'username', 'nickname', 'avatar', 'email', 'mobile', 'status', 'remark', 'role_ids']);
        $id = $data['id'] ?? null;

        if (empty($id)) {
            return $this->error('ID不能为空', 400);
        }

        // 验证更新参数
        $this->validate($data, AdminValidate::class . '.update');

        try {
            $this->service()->update((int)$id, $data);
            return $this->success(null, '更新成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    /**
     * 删除
     */
    public function delete()
    {
        $id = $this->request->param('id');

        if (empty($id)) {
            return $this->error('ID不能为空', 400);
        }

        try {
            $this->service()->delete((int)$id);
            return $this->success(null, '删除成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 500);
        }
    }
}