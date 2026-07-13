<?php

declare(strict_types=1);

namespace app\service\upgrade;

use RuntimeException;
use Throwable;

/** Redis-backed bounded counters for upgrade capabilities and direct peers. */
final readonly class UpgradeControlRateLimiter
{
    private const SCRIPT = <<<'LUA'
local value = redis.call('INCR', KEYS[1])
if value == 1 then
    redis.call('EXPIRE', KEYS[1], ARGV[1])
end
return value
LUA;

    public function __construct(
        private UpgradeRedisConnectionFactory $redis,
        private string $namespace,
    ) {
        if (preg_match('/^mbs_[a-z0-9][a-z0-9_-]{0,59}$/D', $this->namespace) !== 1) {
            throw new RuntimeException('UPGRADE_RATE_LIMIT_CONFIG_INVALID');
        }
    }

    public function consume(string $scope, string $subject, int $limit, int $windowSeconds): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $scope) !== 1
            || $subject === '' || strlen($subject) > 4096 || str_contains($subject, "\0")
            || $limit < 1 || $limit > 100_000 || $windowSeconds < 1 || $windowSeconds > 3600) {
            throw new RuntimeException('UPGRADE_RATE_LIMIT_CONFIG_INVALID');
        }
        $key = 'mallbase:upgrade:' . $this->namespace . ':rate:' . $scope . ':' . hash('sha256', $subject);
        try {
            $connection = $this->redis->create();
            if (!method_exists($connection, 'eval')) {
                throw new RuntimeException('redis eval unavailable');
            }
            $count = $connection->eval(self::SCRIPT, [$key, (string) $windowSeconds], 1);
            if (!is_int($count) || $count < 1) {
                throw new RuntimeException('redis counter invalid');
            }
        } catch (Throwable) {
            throw new RuntimeException('UPGRADE_RATE_LIMIT_UNAVAILABLE');
        }
        if ($count > $limit) {
            throw new RuntimeException('UPGRADE_RATE_LIMITED');
        }
    }

    /** @param array<string, mixed> $server */
    public function directPeer(array $server): string
    {
        $peer = $server['REMOTE_ADDR'] ?? null;
        if (!is_string($peer) || filter_var($peer, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('UPGRADE_RATE_LIMIT_UNAVAILABLE');
        }

        return strtolower($peer);
    }
}
