<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\PersistentStateVerificationService;
use app\service\upgrade\UpgradeOperationStore;
use app\service\upgrade\UpgradeSharedFileStore;
use PHPUnit\Framework\TestCase;

final class PersistentStateVerificationServiceTest extends TestCase
{
    private string $root;
    private string $artifactRoot;
    private PersistentStateVerificationService $service;

    protected function setUp(): void
    {
        $path = sys_get_temp_dir() . '/mallbase-persistent-verify-' . bin2hex(random_bytes(8));
        mkdir($path, 0750, true);
        $this->root = (string) realpath($path);
        foreach (['state', 'run'] as $directory) {
            mkdir($this->root . '/' . $directory, 02770);
            chmod($this->root . '/' . $directory, 02770);
        }
        mkdir($this->root . '/artifact', 0770);
        chmod($this->root . '/artifact', 0770);
        $this->artifactRoot = $this->root . '/artifact';
        file_put_contents($this->artifactRoot . '/one.txt', 'one');
        chmod($this->artifactRoot . '/one.txt', 0660);
        mkdir($this->artifactRoot . '/nested', 0770);
        chmod($this->artifactRoot . '/nested', 0770);
        file_put_contents($this->artifactRoot . '/nested/two.txt', 'two');
        chmod($this->artifactRoot . '/nested/two.txt', 0660);
        $files = new UpgradeSharedFileStore($this->root, 39001, 39002, 39003, 65536, 50, $this->statOperations());
        $roots = array_fill_keys([
            'install', 'local_storage', 'runtime_backup', 'public_storage',
            'uploads', 'cert', 'demo',
        ], $this->artifactRoot);
        $this->service = new PersistentStateVerificationService(new UpgradeOperationStore($files), $roots);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testVerifiesFixedArtifactWithoutReturningConfiguredPath(): void
    {
        $captures = $this->service->capture('66666666-6666-4666-8666-666666666666');
        $capture = $this->capture($captures, 'uploads');
        $result = $this->service->verify(
            '66666666-6666-4666-8666-666666666666',
            'uploads',
            $capture['merkle_root'],
            $capture['count'],
            $capture['file_mode'],
            $capture['directory_mode'],
            '1.2.0:deployment:1:3',
            $capture['receipt_checksum'],
            'runtime-target',
            'boot-target',
            1000,
        );

        $this->assertSame('completed', $result['state']);
        $this->assertSame($capture['merkle_root'], $result['result']['merkle_root']);
        $this->assertSame($capture['count'], $result['result']['count']);
        $this->assertStringNotContainsString($this->artifactRoot, json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testRejectsMismatchAndSymlinkWithoutFollowingIt(): void
    {
        $capture = $this->capture(
            $this->service->capture('77777777-7777-4777-8777-777777777777'),
            'cert',
        );
        file_put_contents($this->artifactRoot . '/one.txt', 'changed');
        $mismatch = $this->service->verify(
            '77777777-7777-4777-8777-777777777777',
            'cert',
            $capture['merkle_root'],
            $capture['count'],
            $capture['file_mode'],
            $capture['directory_mode'],
            '1.2.0:deployment:1:3',
            $capture['receipt_checksum'],
            'runtime-target',
            'boot-target',
            1000,
        );
        $this->assertSame('failed', $mismatch['state']);
        $this->assertSame('UPGRADE_PERSISTENT_STATE_MISMATCH', $mismatch['error_code']);

        symlink('/etc/passwd', $this->artifactRoot . '/escape');
        try {
            $this->service->scan($this->artifactRoot, 0660, 0770);
            self::fail('symlink accepted');
        } catch (\RuntimeException $exception) {
            $this->assertSame('UPGRADE_PERSISTENT_STATE_MODE_MISMATCH', $exception->getMessage());
        }
        $this->assertNotSame('', $capture['merkle_root']);
    }

    public function testRejectsReceiptThatDoesNotBindTheCapturedExpectation(): void
    {
        $capture = $this->capture(
            $this->service->capture('88888888-8888-4888-8888-888888888888'),
            'install',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('UPGRADE_PERSISTENT_VERIFY_ARGUMENT_INVALID');
        $this->service->verify(
            '88888888-8888-4888-8888-888888888888',
            'install',
            $capture['merkle_root'],
            $capture['count'],
            $capture['file_mode'],
            $capture['directory_mode'],
            '1.2.0:deployment:1:3',
            'sha256:' . str_repeat('f', 64),
            'runtime-target',
            'boot-target',
            1000,
        );
    }

    /**
     * @param list<array{artifact:string,merkle_root:string,count:int,file_mode:int,directory_mode:int,receipt_checksum:string}> $captures
     * @return array{artifact:string,merkle_root:string,count:int,file_mode:int,directory_mode:int,receipt_checksum:string}
     */
    private function capture(array $captures, string $artifact): array
    {
        foreach ($captures as $capture) {
            if ($capture['artifact'] === $artifact) {
                return $capture;
            }
        }
        self::fail('capture not found: ' . $artifact);
    }

    /** @return array<string, callable> */
    private function statOperations(): array
    {
        return [
            'lstat' => static function (string $path): array|false {
                $stat = @lstat($path);
                if ($stat !== false) {
                    $stat['gid'] = 39002;
                    $stat['uid'] = ($stat['mode'] & 0170000) === 0040000 ? 39001 : 39003;
                }
                return $stat;
            },
            'fstat' => static function ($handle): array|false {
                $stat = fstat($handle);
                if ($stat !== false) {
                    $stat['gid'] = 39002;
                    $stat['uid'] = ($stat['mode'] & 0170000) === 0040000 ? 39001 : 39003;
                }
                return $stat;
            },
        ];
    }

    private function removeTree(string $path): void
    {
        if (is_link($path)) {
            @unlink($path);
            return;
        }
        if (is_file($path)) {
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
