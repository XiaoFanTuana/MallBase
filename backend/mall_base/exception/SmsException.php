<?php

declare(strict_types=1);

namespace mall_base\exception;

/**
 * 短信发送相关异常
 *
 * 继承 BusinessException 以便统一异常出口,前端可正常拿到 message
 */
class SmsException extends BusinessException
{
}
