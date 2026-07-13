<?php

declare(strict_types=1);

namespace app\service\upgrade;

use app\service\install\AgentInstanceConfigStore;
use RuntimeException;

/** Recovery/rotation facade that never consults the business database or Admin JWT. */
final readonly class UpgradeRecoveryCapabilityService
{
    public function __construct(
        private AgentInstanceConfigStore $instances,
        private UpgradeSessionAuthStore $sessions,
    ) {
    }

    /** @return array<string, mixed> */
    public function takeover(string $credential, string $requestId, int $now): array
    {
        return $this->sessions->takeover($this->key(), $credential, $requestId, $now);
    }

    /** @return array<string, mixed> */
    public function rotate(string $ownerCookie, string $requestId, int $now): array
    {
        return $this->sessions->rotateRecovery($this->key(), $ownerCookie, $requestId, $now);
    }

    /** @return array<string, mixed> */
    public function confirm(
        string $ownerCookie,
        string $requestId,
        string $confirmationNonce,
        int $now,
    ): array {
        return $this->sessions->confirm(
            $this->key(),
            $ownerCookie,
            $requestId,
            $confirmationNonce,
            $now,
        );
    }

    private function key(): string
    {
        $instance = $this->instances->load();
        $key = is_array($instance) ? ($instance['session_derivation_key'] ?? null) : null;
        if (($instance['schema_version'] ?? null) !== 2 || !is_string($key)) {
            throw new RuntimeException('UPGRADE_SESSION_UNAVAILABLE');
        }

        return $key;
    }
}
