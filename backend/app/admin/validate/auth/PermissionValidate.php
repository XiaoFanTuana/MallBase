<?php

declare (strict_types=1);

namespace app\admin\validate\auth;

use think\Validate;

/**
 * 权限验证器
 */
class PermissionValidate extends Validate
{
    /**
     * 验证规则
     *
     * @var array
     */
    protected $rule = [
        'parent_id' => 'number|egt:0',
        'name' => 'require|max:50',
        'code' => 'require|length:2,50|alphaNum',
        'type' => 'in:1,2,3',
        'path' => 'max:255',
        'icon' => 'max:100',
        'component' => 'max:255',
        'sort' => 'number|between:0,9999',
        'status' => 'in:0,1',
        'is_show' => 'in:0,1',
        'remark' => 'max:500',
    ];

    /**
     * 错误提示
     *
     * @var array
     */
    protected $message = [
        'parent_id.number' => '父级ID必须是数字',
        'parent_id.egt' => '父级ID必须大于等于0',
        'name.require' => '权限名称不能为空',
        'name.max' => '权限名称不能超过50个字符',
        'code.require' => '权限编码不能为空',
        'code.length' => '权限编码长度必须在2-50个字符之间',
        'code.alphaNum' => '权限编码只能包含字母和数字',
        'type.in' => '权限类型值不正确',
        'path.max' => '路径不能超过255个字符',
        'icon.max' => '图标不能超过100个字符',
        'component.max' => '组件名称不能超过255个字符',
        'sort.number' => '排序必须是数字',
        'sort.between' => '排序值必须在0-9999之间',
        'status.in' => '状态值不正确',
        'is_show.in' => '显示状态值不正确',
        'remark.max' => '备注不能超过500个字符',
    ];

    /**
     * 验证场景
     *
     * @var array
     */
    protected $scene = [
        'create' => ['parent_id', 'name', 'code', 'type', 'path', 'icon', 'component', 'sort', 'status', 'is_show', 'remark'],
        'update' => ['parent_id', 'name', 'code', 'type', 'path', 'icon', 'component', 'sort', 'status', 'is_show', 'remark'],
    ];
}