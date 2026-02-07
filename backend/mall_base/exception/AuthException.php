<?php

namespace mall_base\exception;

use mall_base\base\BaseException;

/**
 * 授权异常类
 * 
 * 用于登录、授权、权限相关的错误处理
 * 默认状态码：1000
 * 
 * 使用示例：
 * ```php
 * throw new AuthException('登录失败');
 * throw new AuthException('Token 过期', 1005);
 * throw new AuthException('权限不足', 1004);
 * ```
 */
class AuthException extends BaseException
{
    /**
     * 构造函数
     * 
     * @param string $message 错误消息
     * @param int $statusCode 业务状态码，默认 1000
     */
    public function __construct(string $message = '授权失败', int $statusCode = 401)
    {
        parent::__construct($message, $statusCode);
    }
}
