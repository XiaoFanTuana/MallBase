<?php
declare(strict_types=1);

namespace app\admin\model\goods;

use mall_base\base\BaseModel;

/**
 * 商品规格模板模型
 */
class GoodsSpecTemplate extends BaseModel
{
    protected $name = 'goods_spec_template';

    /**
     * 搜索器-按名称搜索
     */
    public function searchNameAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->whereLike('name', '%' . $value . '%');
        }
    }

    /**
     * 搜索器-按状态搜索
     */
    public function searchStatusAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('status', $value);
        }
    }
}
