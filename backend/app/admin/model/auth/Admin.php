<?php

declare (strict_types=1);

namespace app\admin\model\auth;

use mall_base\base\BaseModel;

/**
 * 管理员模型
 */
class Admin extends BaseModel
{
    protected $name = 'admin';

    protected $pk = 'id';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    // 密码加密器
    protected $passwordHash = 'password_hash';

    /**
     * 隐藏密码字段
     */
    public function hidden(): array
    {
        return ['password'];
    }

    /**
     * 获取角色列表
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, AdminRole::class, 'admin_id', 'role_id');
    }

    /**
     * 获取所有权限
     */
    public function permissions()
    {
        return $this->hasManyThrough(Permission::class, Role::class, AdminRole::class, RolePermission::class);
    }

    /**
     * 验证密码
     */
    public function checkPassword(string $password): bool
    {
        return password_verify($password, $this->password);
    }

    /**
     * 设置密码
     */
    public function setPasswordAttr(string $password): void
    {
        $this->password = password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * 检查密码是否需要重新哈希
     */
    public function needsRehash(): bool
    {
        return password_needs_rehash($this->password);
    }
}