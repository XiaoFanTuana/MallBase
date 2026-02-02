<?php

declare (strict_types=1);

namespace app\admin\service\auth;

use app\admin\model\auth\Admin as AdminModel;
use app\admin\model\auth\AdminRole;
use app\admin\model\auth\Role;
use mall_base\base\BaseService;
use mall_base\exception\AuthException;
use mall_base\exception\BusinessException;
use mall_base\service\JwtService;
use think\facade\Request;

/**
 * 管理员服务
 * @extends BaseService<AdminModel>
 */
class AdminService extends BaseService
{
    /**
     * Model 类名
     */
    protected string $modelClass = AdminModel::class;

    /**
     * 管理员登录
     */
    public function login(string $username, string $password): array
    {
        $admin = $this->model()->where('username', $username)
            ->where('status', 1)
            ->find();

        if (!$admin) {
            throw new AuthException('账号不存在或已禁用', 1001);
        }

        if (!$admin->checkPassword($password)) {
            throw new AuthException('密码错误', 1002);
        }

        // 更新登录信息
        $admin->last_login_time = date('Y-m-d H:i:s');
        $admin->last_login_ip = Request::ip();
        $admin->save();

        // 生成 JWT Token
        $token = $this->generateToken($admin);

        // 获取管理员信息（不包含密码）
        $adminInfo = $admin->toArray();
        unset($adminInfo['password']);

        return [
            'token' => $token,
            'admin' => $adminInfo,
        ];
    }

    /**
     * 获取管理员列表
     */
    public function getList(array $params = [], int $page = 1, int $limit = 10): array
    {
        $keyword = $params['keyword'] ?? '';
        $status = $params['status'] ?? null;

        $query = $this->model()->with(['roles']);

        // 关键字搜索
        if ($keyword) {
            $query->whereLike('username|nickname|mobile|email', "%{$keyword}%");
        }

        // 状态筛选
        if ($status !== null) {
            $query->where('status', $status);
        }

        $list = $query->order('id', 'desc')
            ->page($page, $limit);

        // 隐藏密码
        foreach ($list as $item) {
            unset($item['password']);
        }

        return $list->toArray();
    }

    /**
     * 获取管理员详情
     */
    public function getInfo(int $id): array
    {
        $admin = $this->model()->with(['roles'])->find($id);

        if (!$admin) {
            throw new BusinessException('管理员不存在');
        }

        $info = $admin->toArray();
        unset($info['password']);

        // 获取角色ID列表
        $info['role_ids'] = array_column($admin['roles'] ?? [], 'id');

        return $info;
    }

    /**
     * 创建管理员
     */
    public function create(array $data): int
    {
        // 检查用户名是否存在
        if ($this->model()->where('username', $data['username'])->find()) {
            throw new BusinessException('用户名已存在');
        }

        return $this->transaction(function () use ($data) {
            $admin = $this->model();
            $admin->save([
                'username' => $data['username'],
                'password' => $data['password'],
                'nickname' => $data['nickname'] ?? '',
                'avatar' => $data['avatar'] ?? '',
                'email' => $data['email'] ?? '',
                'mobile' => $data['mobile'] ?? '',
                'status' => $data['status'] ?? 1,
                'remark' => $data['remark'] ?? '',
            ]);

            // 分配角色
            if (!empty($data['role_ids'])) {
                $this->assignRoles($admin->id, $data['role_ids']);
            }

            return $admin->id;
        });
    }

    /**
     * 更新管理员
     */
    public function update(int $id, array $data): bool
    {
        $admin = $this->model()->find($id);
        if (!$admin) {
            throw new BusinessException('管理员不存在');
        }

        // 检查用户名是否重复
        if (!empty($data['username']) && $data['username'] !== $admin->username) {
            $exists = $this->model()
                ->where('username', $data['username'])
                ->where('id', '<>', $id)
                ->value('id');

            if ($exists) {
                throw new BusinessException('用户名已存在');
            }
        }
        return $this->transaction(function () use ($id, $data) {
            $this->model()->updateById($id, $data);
            // 重新分配角色
            if (!empty($data['role_ids'])) {
                $this->assignRoles($id, $data['role_ids']);
            }

            return true;
        });
    }

    /**
     * 删除管理员
     */
    public function delete(int $id): bool
    {
        // 不允许删除超级管理员
        if ($id === 1) {
            throw new BusinessException('不能删除超级管理员');
        }

        $admin = $this->model()->find($id);
        if (!$admin) {
            throw new BusinessException('管理员不存在');
        }

        // 删除角色关联
        $this->model(AdminRole::class)->where('admin_id', $id)->delete();

        // 删除管理员
        $admin->delete();

        return true;
    }

    /**
     * 分配角色（内部方法，不使用事务，由调用方控制）
     */
    public function assignRoles(int $adminId, array $roleIds): void
    {
        // 删除原有角色
        $this->model(AdminRole::class)->where('admin_id', $adminId)->delete();

        // 批量分配新角色
        if (!empty($roleIds)) {
            $insertData = [];
            foreach ($roleIds as $roleId) {
                $insertData[] = [
                    'admin_id' => $adminId,
                    'role_id' => $roleId,
                    'create_time' => date('Y-m-d H:i:s'),
                ];
            }
            $this->model(AdminRole::class)->insertAll($insertData);
        }
    }

    /**
     * 生成 JWT Token
     */
    protected function generateToken(AdminModel $admin): string
    {
        $jwtService = new JwtService();

        $payload = [
            'admin_id' => $admin->id,
            'username' => $admin->username,
            'nickname' => $admin->nickname,
        ];

        return $jwtService->encode($payload);
    }
}
