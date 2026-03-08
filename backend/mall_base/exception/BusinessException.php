<?php

namespace mall_base\exception;

use mall_base\base\BaseException;

/**
 * 业务异常类
 *
 * 用于业务逻辑中的错误处理，如验证失败、数据不存在等业务层面的异常
 * 默认状态码：400
 *
 * 使用示例：
 * ```php
 * throw new BusinessException('用户不存在');
 * throw new BusinessException('参数错误', 400);
 * throw new BusinessException('自定义错误', 400);
 * ```
 */
class BusinessException extends BaseException
{
    /**
     * 构造函数
     *
     * @param string $message 错误消息
     * @param int $statusCode 业务状态码，默认 400
     */
    public function __construct(string $message = '操作失败', int $statusCode = 400)
    {
        parent::__construct($message, $statusCode);
    }
}
