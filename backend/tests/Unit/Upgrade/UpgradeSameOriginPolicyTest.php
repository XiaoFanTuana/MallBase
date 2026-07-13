<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\UpgradeSameOriginPolicy;
use PHPUnit\Framework\TestCase;

final class UpgradeSameOriginPolicyTest extends TestCase
{
    public function testAcceptsOnlyTheConfiguredBrowserOrigin(): void
    {
        $policy = new UpgradeSameOriginPolicy('https://Mall.Example:443');
        $policy->assert('https://mall.example');
        $this->assertSame('https://mall.example', $policy->origin());

        foreach (['', 'null', 'https://attacker.example', 'http://mall.example', 'https://mall.example/path'] as $origin) {
            try {
                $policy->assert($origin);
                self::fail('accepted origin ' . $origin);
            } catch (\RuntimeException $exception) {
                self::assertSame('UPGRADE_ORIGIN_INVALID', $exception->getMessage());
            }
        }
    }

    public function testPolicyDoesNotAcceptForwardedHostOrPeerInputsAsAuthority(): void
    {
        $policy = new UpgradeSameOriginPolicy('https://mall.example');
        $forgedServer = [
            'HTTP_X_FORWARDED_HOST' => 'attacker.example',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
        ];

        $this->assertArrayHasKey('HTTP_X_FORWARDED_HOST', $forgedServer);
        $policy->assert('https://mall.example');
        $this->addToAssertionCount(1);
    }
}
