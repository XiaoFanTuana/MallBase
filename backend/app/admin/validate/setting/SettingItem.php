<?php

declare (strict_types=1);

namespace app\admin\validate\setting;

use think\Validate;

/**
 * 设置项验证器
 */
class SettingItem extends Validate
{
    /**
     * 验证规则
     */
    protected $rule = [
        'group_id|分组ID' => 'require|number',
        'name|设置项名称' => 'require|max:100',
        'code|设置项编码' => 'require|alphaNum|max:50',
        'value|设置值' => 'max:65535',
        'type|表单类型' => 'in:input,textarea,number,password,switch,radio,checkbox,select,image,images,file,files,editor,json',
        'placeholder|输入提示' => 'max:255',
        'remark|备注说明' => 'max:255',
        'sort|排序' => 'number|between:0,9999',
        'is_required|是否必填' => 'in:0,1',
    ];

    /**
     * 验证场景
     */
    protected $scene = [
        'create' => ['group_id', 'name', 'code', 'value', 'type', 'placeholder', 'remark', 'sort', 'is_required'],
        'update' => ['name', 'code', 'value', 'type', 'placeholder', 'remark', 'sort', 'is_required'],
    ];
}