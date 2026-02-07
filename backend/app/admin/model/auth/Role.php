<?php

declare (strict_types=1);

namespace app\admin\model\auth;

use mall_base\base\BaseModel;

/**
 * 角色模型
 */
class Role extends BaseModel
{
    protected $name = 'role';

    protected $pk = 'id';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    /**
     * 获取管理员列表
     */
    public function admins()
    {
        return $this->belongsToMany(Admin::class, 'admin_role', 'role_id', 'admin_id');
    }

    /**
     * 获取权限列表
     */
    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permission', 'role_id', 'permission_id');
    }
}