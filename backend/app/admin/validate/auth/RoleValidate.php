<?php

declare (strict_types=1);

namespace app\admin\validate\auth;

use think\Validate;

/**
 * 角色验证器
 */
class RoleValidate extends Validate
{
    /**
     * 验证规则
     *
     * @var array
     */
    protected $rule = [
        'name' => 'require|max:50',
        'code' => 'require|length:2,30|alphaNum',
        'status' => 'in:0,1',
        'sort' => 'number|between:0,9999',
        'remark' => 'max:500',
        'permission_ids' => 'array',
    ];

    /**
     * 错误提示
     *
     * @var array
     */
    protected $message = [
        'name.require' => '角色名称不能为空',
        'name.max' => '角色名称不能超过50个字符',
        'code.require' => '角色编码不能为空',
        'code.length' => '角色编码长度必须在2-30个字符之间',
        'code.alphaNum' => '角色编码只能包含字母和数字',
        'status.in' => '状态值不正确',
        'sort.number' => '排序必须是数字',
        'sort.between' => '排序值必须在0-9999之间',
        'remark.max' => '备注不能超过500个字符',
        'permission_ids.array' => '权限ID必须是数组',
    ];

    /**
     * 验证场景
     *
     * @var array
     */
    protected $scene = [
        'create' => ['name', 'code', 'status', 'sort', 'remark', 'permission_ids'],
        'update' => ['name', 'code', 'status', 'sort', 'remark', 'permission_ids'],
    ];
}