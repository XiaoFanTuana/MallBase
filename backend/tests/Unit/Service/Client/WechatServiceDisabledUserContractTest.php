<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Client;

use app\model\user\User;
use app\service\client\WechatService;
use mall_base\exception\BusinessException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class WechatServiceDisabledUserContractTest extends TestCase
{
    public function testDisabledUserIsRejectedBeforeTokenIssuance(): void
    {
        $reflection = new ReflectionClass(WechatService::class);
        /** @var WechatService $service */
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('assertUserEnabled');

        $user = $this->userWithStatus(0);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('账号已禁用');
        $method->invoke($service, $user);
    }

    public function testEnabledUserPassesTheDefensiveCheck(): void
    {
        $reflection = new ReflectionClass(WechatService::class);
        /** @var WechatService $service */
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('assertUserEnabled');

        $user = $this->userWithStatus(1);

        $method->invoke($service, $user);
        self::assertTrue(true);
    }

    private function userWithStatus(int $status): User
    {
        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->getMock();
        $user->method('getData')->with('status')->willReturn($status);
        return $user;
    }
}
