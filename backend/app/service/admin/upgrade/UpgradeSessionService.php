<?php

declare(strict_types=1);

namespace app\service\admin\upgrade;

use app\model\auth\Admin;
use app\service\install\AgentInstanceConfigStore;
use app\service\install\AgentPlatformBootstrapService;
use app\service\install\InstallLockService;
use app\service\upgrade\UpgradeSessionAuthStore;
use Closure;
use RuntimeException;

/** Creates the only browser upgrade capability, after explicit super-admin authorization. */
final readonly class UpgradeSessionService
{
    /** @var Closure():int */
    private Closure $clock;

    public function __construct(
        private AgentInstanceConfigStore $instances,
        private InstallLockService $legacy,
        private AgentPlatformBootstrapService $platform,
        private UpgradeSessionAuthStore $sessions,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** @return array<string, mixed> */
    public function createSession(int $adminId, string $requestId): array
    {
        // This check intentionally precedes every platform, filesystem and Redis side effect.
        if ($adminId !== Admin::SUPER_ADMIN_ID) {
            throw new RuntimeException('UPGRADE_SUPER_ADMIN_REQUIRED');
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $requestId) !== 1) {
            throw new RuntimeException('UPGRADE_SESSION_ARGUMENT_INVALID');
        }
        $clock = $this->clock;
        $now = $clock();
        if (!is_int($now) || $now < 0 || $now > 4_102_444_800) {
            throw new RuntimeException('UPGRADE_SESSION_UNAVAILABLE');
        }

        $instance = $this->instances->initializeFromLegacy($this->legacy, $now);
        $instance = $this->instances->ensureSessionDerivationKey($now);
        $platform = $this->platform->ensureConnected('backend_php');
        $session = $this->sessions->create(
            (string) $instance['instance_id'],
            (string) $instance['session_derivation_key'],
            $adminId,
            $requestId,
            $now,
        );

        return $session + [
            'upgrade_url' => '/upgrade/',
            'platform' => [
                'connected' => $platform->ok,
                'error_code' => $platform->ok ? '' : $this->sanitizePlatformError($platform->error),
                'retryable' => !$platform->ok && $platform->error !== 'PLATFORM_TOKEN_RECOVERY_REQUIRED',
            ],
        ];
    }

    private function sanitizePlatformError(string $code): string
    {
        return preg_match('/^[A-Z0-9_]{1,64}$/D', $code) === 1
            ? $code
            : 'PLATFORM_UNAVAILABLE';
    }
}
