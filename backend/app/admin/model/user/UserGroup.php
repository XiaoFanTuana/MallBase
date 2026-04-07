<?php
declare(strict_types=1);

namespace app\admin\model\user;

use mall_base\base\BaseModel;

/**
 * 用户分组模型
 */
class UserGroup extends BaseModel
{
    protected $name = 'user_group';

    /**
     * 搜索器-按名称搜索
     */
    public function searchNameAttr($query, $value)
    {
        $query->whereLike('name', '%' . $value . '%');
    }

    /**
     * 搜索器-按编码搜索
     */
    public function searchCodeAttr($query, $value)
    {
        $query->whereLike('code', '%' . $value . '%');
    }

    /**
     * 搜索器-按状态搜索
     */
    public function searchStatusAttr($query, $value)
    {
        $query->where('status', $value);
    }
}
