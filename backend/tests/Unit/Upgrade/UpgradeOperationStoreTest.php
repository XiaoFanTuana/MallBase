<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\UpgradeOperationStore;
use app\service\upgrade\UpgradeSharedFileStore;
use PHPUnit\Framework\TestCase;

final class UpgradeOperationStoreTest extends TestCase
{
    private string $root;
    private UpgradeOperationStore $operations;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mallbase-upgrade-operations-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0750, true);
        foreach (['state', 'run'] as $directory) {
            mkdir($this->root . '/' . $directory, 02770);
            chmod($this->root . '/' . $directory, 02770);
        }
        $this->operations = new UpgradeOperationStore(new UpgradeSharedFileStore(
            $this->root,
            36001,
            36002,
            36003,
            65536,
            50,
            $this->statOperations(),
        ), 10);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testDeterministicClaimCompletionAndReplay(): void
    {
        $job = '11111111-1111-4111-8111-111111111111';
        $checksum = 'sha256:' . str_repeat('a', 64);
        $first = $this->operations->claim($job, 'backup_database', $checksum, 'runtime-a', 'boot-a', 1000);
        $replay = $this->operations->claim($job, 'backup_database', $checksum, 'runtime-b', 'boot-b', 1005);

        $this->assertSame($first, $replay);
        $this->assertSame($this->operations->operationId($job, 'backup_database', $checksum), $first['operation_id']);
        $completed = $this->operations->complete($first['operation_id'], 'boot-a', ['size' => 42], 1006);
        $this->assertSame('completed', $completed['state']);
        $this->assertSame(['size' => 42], $completed['result']);
        $this->assertSame($completed, $this->operations->claim($job, 'backup_database', $checksum, 'runtime-z', 'boot-z', 2000));
    }

    public function testStaleOwnerRequiresExternalLockReleaseProofBeforeResume(): void
    {
        $operation = $this->operations->claim(
            '22222222-2222-4222-8222-222222222222',
            'migrations',
            'sha256:' . str_repeat('b', 64),
            'runtime-a',
            'boot-a',
            1000,
        );
        $blocked = $this->operations->claim(
            $operation['job_id'],
            $operation['action'],
            $operation['input_checksum'],
            'runtime-b',
            'boot-b',
            1011,
        );

        $this->assertSame('recovery_blocked', $blocked['state']);
        $this->assertSame('UPGRADE_OPERATION_OWNER_UNCERTAIN', $blocked['error_code']);
    }

    public function testStaleOwnerWithReleasedLockProofCanResumeButOldOwnerCannotPublish(): void
    {
        $operation = $this->operations->claim(
            '33333333-3333-4333-8333-333333333333',
            'migrations',
            'sha256:' . str_repeat('c', 64),
            'runtime-a',
            'boot-a',
            1000,
        );
        $resumed = $this->operations->claim(
            $operation['job_id'],
            $operation['action'],
            $operation['input_checksum'],
            'runtime-b',
            'boot-b',
            1011,
            true,
        );

        $this->assertSame('boot-b', $resumed['owner_boot_id']);
        $this->assertSame(2, $resumed['revision']);
        try {
            $this->operations->complete($operation['operation_id'], 'boot-a', [], 1012);
            self::fail('old owner completed resumed operation');
        } catch (\RuntimeException $exception) {
            $this->assertSame('UPGRADE_OPERATION_CONFLICT', $exception->getMessage());
        }
        $failed = $this->operations->fail($operation['operation_id'], 'boot-b', 'MIGRATION_UNCERTAIN', 1013, true);
        $this->assertSame('recovery_blocked', $failed['state']);
    }

    /** @return array<string, callable> */
    private function statOperations(): array
    {
        return [
            'lstat' => static function (string $path): array|false {
                $stat = @lstat($path);
                if ($stat !== false) {
                    $stat['gid'] = 36002;
                    $stat['uid'] = ($stat['mode'] & 0170000) === 0040000 ? 36001 : 36003;
                }

                return $stat;
            },
            'fstat' => static function ($handle): array|false {
                $stat = fstat($handle);
                if ($stat !== false) {
                    $stat['gid'] = 36002;
                    $stat['uid'] = ($stat['mode'] & 0170000) === 0040000 ? 36001 : 36003;
                }

                return $stat;
            },
        ];
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @chmod($path, 0660);
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
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
