<?php

declare (strict_types=1);

namespace app\admin\validate\auth;

use think\Validate;

/**
 * 管理员验证器
 */
class AdminValidate extends Validate
{
    /**
     * 验证规则
     *
     * @var array
     */
    protected $rule = [
        'username' => 'require|length:3,20|alphaNum',
        'password' => 'require|length:6,32',
        'password_confirm' => 'require|confirm:password',
        'nickname' => 'max:50',
        'avatar' => 'max:255',
        'email' => 'email|max:100',
        'mobile' => 'mobile|max:20',
        'status' => 'in:0,1',
        'remark' => 'max:500',
        'role_ids' => 'array',
    ];

    /**
     * 错误提示
     *
     * @var array
     */
    protected $message = [
        'username.require' => '用户名不能为空',
        'username.length' => '用户名长度必须在3-20个字符之间',
        'username.alphaNum' => '用户名只能包含字母和数字',
        'password.require' => '密码不能为空',
        'password.length' => '密码长度必须在6-32个字符之间',
        'password_confirm.require' => '确认密码不能为空',
        'password_confirm.confirm' => '两次密码输入不一致',
        'nickname.max' => '昵称不能超过50个字符',
        'avatar.max' => '头像地址不能超过255个字符',
        'email.email' => '邮箱格式不正确',
        'email.max' => '邮箱不能超过100个字符',
        'mobile.mobile' => '手机号格式不正确',
        'mobile.max' => '手机号不能超过20个字符',
        'status.in' => '状态值不正确',
        'remark.max' => '备注不能超过500个字符',
        'role_ids.array' => '角色ID必须是数组',
    ];

    /**
     * 验证场景
     *
     * @var array
     */
    protected $scene = [
        'login' => ['username', 'password'],
        'create' => ['username', 'password', 'password_confirm', 'nickname', 'avatar', 'email', 'mobile', 'status', 'remark', 'role_ids'],
        'update' => ['username', 'nickname', 'avatar', 'email', 'mobile', 'status', 'remark', 'role_ids'],
    ];

    /**
     * 手机号验证规则
     *
     * @param mixed $value
     * @param mixed $rule
     * @param array $data
     * @return bool|string
     */
    protected function mobile($value, $rule, $data = [])
    {
        // 为空时跳过验证
        if (empty($value)) {
            return true;
        }

        return preg_match('/^1[3-9]\d{9}$/', (string)$value) ? true : '手机号格式不正确';
    }
}