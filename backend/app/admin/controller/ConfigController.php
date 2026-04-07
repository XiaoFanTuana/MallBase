<?php
declare(strict_types=1);

namespace app\admin\controller;

use mall_base\base\BaseController;

/**
 * 系统配置控制器
 */
class ConfigController extends BaseController
{
    /**
     * 获取颜色选项
     */
    public function colorOptions()
    {
        $options = [
            ['value' => 'gold', 'label' => '金色', 'color' => 'gold'],
            ['value' => 'blue', 'label' => '蓝色', 'color' => 'blue'],
            ['value' => 'green', 'label' => '绿色', 'color' => 'green'],
            ['value' => 'red', 'label' => '红色', 'color' => 'red'],
            ['value' => 'orange', 'label' => '橙色', 'color' => 'orange'],
            ['value' => 'purple', 'label' => '紫色', 'color' => 'purple'],
            ['value' => 'cyan', 'label' => '青色', 'color' => 'cyan'],
            ['value' => 'volcano', 'label' => '火山红', 'color' => 'volcano'],
            ['value' => 'magenta', 'label' => '洋红', 'color' => 'magenta'],
            ['value' => 'lime', 'label' => '青柠', 'color' => 'lime'],
        ];

        return $this->success(['options' => $options], '获取成功');
    }
}
