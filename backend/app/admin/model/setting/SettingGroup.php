<?php

declare (strict_types=1);

namespace app\admin\model\setting;

use mall_base\base\BaseModel;

/**
 * 设置分组模型
 */
class SettingGroup extends BaseModel
{
    /**
     * 表名
     */
    protected $name = 'setting_group';

    /**
     * 自动写入时间戳
     */
    protected $autoWriteTimestamp = true;

    /**
     * 关联设置项
     */
    public function settings()
    {
        return $this->hasMany(Setting::class, 'group_id', 'id')
            ->order('sort', 'asc');
    }

    /**
     * 关联子分组
     */
    public function children()
    {
        return $this->hasMany(self::class, 'parent_id', 'id')
            ->order('sort', 'asc');
    }

    /**
     * 关联父级分组
     */
    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id', 'id');
    }

    /**
     * 将扁平数据转换为树形结构
     *
     * @param array $list 扁平数据列表
     * @param int $parentId 起始父级ID
     * @return array 树形结构数据
     */
    public static function toTree(array $list, int $parentId = 0): array
    {
        $tree = [];
        foreach ($list as $item) {
            if ((int)$item['parent_id'] === $parentId) {
                $children = self::toTree($list, (int)$item['id']);
                if (!empty($children)) {
                    $item['children'] = $children;
                }
                $tree[] = $item;
            }
        }
        return $tree;
    }
}
