<?php

declare(strict_types=1);

namespace app\service\upgrade;

use RuntimeException;
use Throwable;

/** Redis-backed, fail-closed replay protection for task capability requests. */
final readonly class UpgradeAgentNonceStore
{
    public function __construct(
        private UpgradeRedisConnectionFactory $redis,
        private string $namespace,
        private int $lifetimeSeconds = 300,
    ) {
        if (preg_match('/^mbs_[a-z0-9][a-z0-9_-]{0,59}$/D', $this->namespace) !== 1
            || $this->lifetimeSeconds < 60 || $this->lifetimeSeconds > 600) {
            throw new RuntimeException('UPGRADE_AGENT_NONCE_CONFIG_INVALID');
        }
    }

    public function consume(string $jobId, string $nonce): void
    {
        if (!$this->uuid($jobId) || !$this->uuid($nonce)) {
            throw new RuntimeException('UPGRADE_AGENT_AUTH_INVALID');
        }
        $key = $this->namespace . ':agent-nonce:' . hash('sha256', $jobId . "\0" . $nonce);
        try {
            $redis = $this->redis->create();
            $result = $redis->set($key, '1', ['nx', 'ex' => $this->lifetimeSeconds]);
            if ($result !== true && $result !== 'OK') {
                throw new RuntimeException('UPGRADE_AGENT_REPLAYED');
            }
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }
            throw new RuntimeException('UPGRADE_AGENT_NONCE_UNAVAILABLE', 0, $exception);
        }
    }

    private function uuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }
}
