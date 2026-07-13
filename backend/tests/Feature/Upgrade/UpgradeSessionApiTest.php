<?php

declare(strict_types=1);

namespace Tests\Feature\Upgrade;

use app\service\admin\upgrade\UpgradeSessionService;
use app\service\install\AgentHeartbeatClient;
use app\service\install\AgentHeartbeatPayloadFactory;
use app\service\install\AgentHeartbeatResult;
use app\service\install\AgentInstanceConfigStore;
use app\service\install\AgentPlatformBootstrapService;
use app\service\install\InstallLockService;
use app\service\upgrade\UpgradeSessionAuthStore;
use app\service\upgrade\UpgradeSharedFileStore;
use PHPUnit\Framework\TestCase;

final class UpgradeSessionApiTest extends TestCase
{
    private const AGENT_UID = 34001;
    private const SHARED_GID = 34002;
    private const PHP_UID = 34003;
    private const INSTANCE_ID = 'd3ec761b-c5d1-4663-8c76-7d2d351efad5';
    private const REQUEST_ID = '11111111-1111-4111-8111-111111111111';

    private string $root;
    private string $legacyPath;
    private string $versionPath;
    private UpgradeSharedFileStore $files;
    private AgentInstanceConfigStore $instances;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mallbase-upgrade-session-api-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0750, true);
        foreach (['config', 'run'] as $directory) {
            mkdir($this->root . '/' . $directory, 02770);
            chmod($this->root . '/' . $directory, 02770);
        }
        mkdir($this->root . '/staging', 0750);
        chmod($this->root, 0750);
        chmod($this->root . '/staging', 0750);
        $this->legacyPath = $this->root . '/install.lock';
        $this->versionPath = $this->root . '/version.json';
        file_put_contents($this->versionPath, '{"version":"1.0.0"}');
        $this->files = new UpgradeSharedFileStore(
            $this->root,
            self::AGENT_UID,
            self::SHARED_GID,
            self::PHP_UID,
            65536,
            50,
            $this->statOperations(),
        );
        $this->instances = new AgentInstanceConfigStore(
            $this->files,
            'https://platform.gosowong.cn',
            'mbs_test_namespace',
            900,
            3600,
            50,
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testNormalAdminIsRejectedBeforePlatformFilesystemOrRedisSideEffects(): void
    {
        $service = $this->service();

        try {
            $service->createSession(2, self::REQUEST_ID);
            self::fail('normal admin created upgrade session');
        } catch (\RuntimeException $exception) {
            $this->assertSame('UPGRADE_SUPER_ADMIN_REQUIRED', $exception->getMessage());
        }
        $this->assertFileDoesNotExist($this->root . '/config/instance.json');
        $this->assertFileDoesNotExist($this->root . '/run/session-auth.json');
    }

    public function testSuperAdminCreatesAndExactlyReplaysPendingSessionWithoutSecretAtRest(): void
    {
        $this->writeProjection();
        file_put_contents($this->legacyPath, json_encode([
            'platform' => ['instance_id' => self::INSTANCE_ID, 'token' => 'mbt_token'],
        ], JSON_THROW_ON_ERROR));
        chmod($this->legacyPath, 0660);
        $service = $this->service();

        $first = $service->createSession(1, self::REQUEST_ID);
        $second = $service->createSession(1, self::REQUEST_ID);

        $this->assertSame($first, $second);
        $this->assertSame('/upgrade/', $first['upgrade_url']);
        $this->assertTrue($first['platform']['connected']);
        $this->assertSame('', $first['platform']['error_code']);
        $instance = $this->instances->load();
        $this->assertSame(2, $instance['schema_version']);
        $this->assertSame(43, strlen($instance['session_derivation_key']));
        $sessionRaw = (string) file_get_contents($this->root . '/run/session-auth.json');
        $this->assertStringNotContainsString($first['owner_cookie'], $sessionRaw);
        $this->assertStringNotContainsString($first['recovery_credential'], $sessionRaw);
        $this->assertStringNotContainsString($instance['session_derivation_key'], $sessionRaw);
    }

    private function service(): UpgradeSessionService
    {
        $legacy = new InstallLockService($this->legacyPath);
        $heartbeat = new class implements AgentHeartbeatClient {
            public function run(array $payload): AgentHeartbeatResult
            {
                throw new \LogicException('confirmed identity must not execute heartbeat');
            }
        };
        $platform = new AgentPlatformBootstrapService(
            $this->instances,
            $heartbeat,
            new AgentHeartbeatPayloadFactory($this->versionPath, static fn(): array => []),
            $legacy,
            static fn(): int => 1000,
        );

        return new UpgradeSessionService(
            $this->instances,
            $legacy,
            $platform,
            new UpgradeSessionAuthStore($this->files),
            static fn(): int => 1000,
        );
    }

    private function writeProjection(): void
    {
        file_put_contents($this->root . '/staging/storage-namespace.json', json_encode((object) [
            'schema_version' => 1,
            'installation_storage_namespace' => 'mbs_test_namespace',
        ], JSON_THROW_ON_ERROR));
        chmod($this->root . '/staging/storage-namespace.json', 0444);
    }

    /** @return array<string, callable> */
    private function statOperations(): array
    {
        return [
            'lstat' => function (string $path): array|false {
                $stat = @lstat($path);
                if ($stat === false) {
                    return false;
                }
                $stat['gid'] = self::SHARED_GID;
                $stat['uid'] = $this->expectedOwner($path, ($stat['mode'] & 0170000) === 0040000);

                return $stat;
            },
            'fstat' => function ($handle): array|false {
                $stat = fstat($handle);
                if ($stat === false) {
                    return false;
                }
                $uri = (string) (stream_get_meta_data($handle)['uri'] ?? '');
                $stat['gid'] = self::SHARED_GID;
                $stat['uid'] = $this->expectedOwner($uri, ($stat['mode'] & 0170000) === 0040000);

                return $stat;
            },
        ];
    }

    private function expectedOwner(string $path, bool $directory): int
    {
        if ($directory || str_ends_with($path, '/staging/storage-namespace.json')
            || str_ends_with($path, '/run/session-auth.json')
            || str_ends_with($path, '/run/session-auth.lock')) {
            return self::AGENT_UID;
        }

        return self::PHP_UID;
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @chmod($path, 0660);
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @chmod($path, 0770);
        @rmdir($path);
    }
}
