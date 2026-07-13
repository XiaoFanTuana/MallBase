<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\UpgradeControlRateLimiter;
use app\service\upgrade\UpgradeRedisConnectionFactory;
use PHPUnit\Framework\TestCase;

final class UpgradeControlRateLimiterTest extends TestCase
{
    public function testCounterKeysHashCapabilitiesAndRejectAtTheExactLimit(): void
    {
        $redis = new RateLimitRedis();
        $limiter = new UpgradeControlRateLimiter(new RateLimitRedisFactory($redis), 'mbs_test');
        $secret = 'mbur1.11111111-1111-4111-8111-111111111111.SECRET';

        $limiter->consume('recovery', $secret, 2, 60);
        $limiter->consume('recovery', $secret, 2, 60);
        $this->assertStringNotContainsString($secret, $redis->lastKey);
        try {
            $limiter->consume('recovery', $secret, 2, 60);
            self::fail('expected rate limit');
        } catch (\RuntimeException $exception) {
            self::assertSame('UPGRADE_RATE_LIMITED', $exception->getMessage());
            self::assertStringNotContainsString($secret, $exception->getMessage());
        }
    }

    public function testRecoveryLimiterFailsClosedWhenRedisIsUnavailable(): void
    {
        $limiter = new UpgradeControlRateLimiter(new class implements UpgradeRedisConnectionFactory {
            public function create(): object
            {
                throw new \RuntimeException('redis password leaked');
            }
        }, 'mbs_test');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('UPGRADE_RATE_LIMIT_UNAVAILABLE');
        $limiter->consume('recovery', 'session', 5, 60);
    }

    public function testDirectPeerUsesOnlyRemoteAddressAndIgnoresForwardedHeaders(): void
    {
        $limiter = new UpgradeControlRateLimiter(new RateLimitRedisFactory(new RateLimitRedis()), 'mbs_test');
        $server = [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
            'HTTP_X_REAL_IP' => '203.0.113.11',
        ];

        $this->assertSame('127.0.0.1', $limiter->directPeer($server));
    }
}

final class RateLimitRedisFactory implements UpgradeRedisConnectionFactory
{
    public function __construct(private readonly RateLimitRedis $redis)
    {
    }

    public function create(): object
    {
        return $this->redis;
    }
}

final class RateLimitRedis
{
    /** @var array<string, int> */
    private array $counts = [];
    public string $lastKey = '';

    public function eval(string $script, array $arguments, int $keyCount): int
    {
        self::assertContract($script, $arguments, $keyCount);
        $this->lastKey = (string) $arguments[0];

        return $this->counts[$this->lastKey] = ($this->counts[$this->lastKey] ?? 0) + 1;
    }

    private static function assertContract(string $script, array $arguments, int $keyCount): void
    {
        if ($script === '' || count($arguments) !== 2 || $keyCount !== 1) {
            throw new \RuntimeException('invalid redis script contract');
        }
    }
}
