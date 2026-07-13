<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\DatabaseBackupService;
use app\service\upgrade\UpgradeOperationStore;
use app\service\upgrade\UpgradeProcessSupervisor;
use app\service\upgrade\UpgradeSharedFileStore;
use PHPUnit\Framework\TestCase;

final class UpgradeProcessSupervisorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mallbase-process-supervisor-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0750, true);
        $this->root = (string) realpath($this->root);
        foreach (['state', 'run', 'backups'] as $directory) {
            mkdir($this->root . '/' . $directory, 02770);
            chmod($this->root . '/' . $directory, 02770);
        }
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testUsesAbsoluteGuardedArgvAndStreamsResultOutsideStderrBuffer(): void
    {
        $observed = [];
        $supervisor = new UpgradeProcessSupervisor(
            '/usr/bin/setpriv',
            '/usr/local/bin/php',
            '/app/bin/upgrade-process-guard.php',
            function (array $command, array $environment, callable $heartbeat) use (&$observed): array {
                $observed = compact('command', 'environment');
                $resultArg = array_values(array_filter($command, static fn(string $value): bool => str_starts_with($value, '--result-file=')));
                file_put_contents(substr($resultArg[0], strlen('--result-file=')), str_repeat('DUMP', 20000));
                $heartbeat();

                return ['state' => 'completed', 'exit_code' => 0, 'stderr' => str_repeat('x', 70000)];
            },
            10,
            65536,
        );
        $backup = new DatabaseBackupService(
            $this->operations(),
            $supervisor,
            $this->root . '/backups',
            '/usr/bin/mariadb-dump',
            ['host' => 'db', 'port' => 3306, 'user' => 'mallbase', 'password' => 'secret-canary', 'database' => 'mallbase'],
            static fn(): int => 1000,
        );

        $result = $backup->backup('44444444-4444-4444-8444-444444444444', 'runtime-a', 'boot-a');

        $this->assertSame('completed', $result['state']);
        $this->assertSame('/usr/bin/setpriv', $observed['command'][0]);
        $this->assertSame(['--pdeathsig', 'SIGKILL', '--'], array_slice($observed['command'], 1, 3));
        $this->assertContains('/usr/local/bin/php', $observed['command']);
        $this->assertContains('/app/bin/upgrade-process-guard.php', $observed['command']);
        $this->assertNotContains('secret-canary', $observed['command']);
        $this->assertSame('secret-canary', $observed['environment']['MYSQL_PWD']);
        $this->assertFileExists($this->root . '/backups/44444444-4444-4444-8444-444444444444/database.sql');
        $this->assertFileDoesNotExist($this->root . '/backups/44444444-4444-4444-8444-444444444444/database.sql.tmp');
        $this->assertArrayNotHasKey('path', $result['result']);
    }

    public function testHeldOperationLockReturnsRunningWithoutStartingSecondWriter(): void
    {
        $directory = $this->root . '/backups/55555555-5555-4555-8555-555555555555';
        mkdir($directory, 02770);
        chmod($directory, 02770);
        $lock = fopen($directory . '/database.lock', 'c+b');
        $this->assertIsResource($lock);
        chmod($directory . '/database.lock', 0660);
        flock($lock, LOCK_EX);
        $executed = false;
        $supervisor = new UpgradeProcessSupervisor(
            executor: function () use (&$executed): array {
                $executed = true;
                return ['state' => 'completed', 'exit_code' => 0, 'stderr' => ''];
            },
        );

        $result = $supervisor->run(
            $directory . '/database.lock',
            '/usr/bin/mariadb-dump',
            [],
            $directory . '/database.sql.tmp',
            [],
            static function (): void {},
        );

        $this->assertSame('running', $result['state']);
        $this->assertFalse($executed);
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    private function operations(): UpgradeOperationStore
    {
        return new UpgradeOperationStore(new UpgradeSharedFileStore(
            $this->root,
            38001,
            38002,
            38003,
            65536,
            50,
            [
                'lstat' => static function (string $path): array|false {
                    $stat = @lstat($path);
                    if ($stat !== false) {
                        $stat['gid'] = 38002;
                        $stat['uid'] = ($stat['mode'] & 0170000) === 0040000 ? 38001 : 38003;
                    }
                    return $stat;
                },
                'fstat' => static function ($handle): array|false {
                    $stat = fstat($handle);
                    if ($stat !== false) {
                        $stat['gid'] = 38002;
                        $stat['uid'] = ($stat['mode'] & 0170000) === 0040000 ? 38001 : 38003;
                    }
                    return $stat;
                },
            ],
        ), 15);
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
