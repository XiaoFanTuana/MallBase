<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\FileMigrationRegistry;
use app\service\upgrade\SchemaMigrationService;
use app\service\upgrade\UpgradeMigrationAdvisoryLock;
use app\service\upgrade\UpgradeSharedFileStore;
use PDO;
use PHPUnit\Framework\TestCase;

final class SchemaMigrationServiceTest extends TestCase
{
    private string $root;
    private PDO $pdo;
    private FileMigrationRegistry $registry;
    private int $now = 1000;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mallbase-schema-migration-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0750, true);
        foreach (['state', 'run'] as $directory) {
            mkdir($this->root . '/' . $directory, 02770);
            chmod($this->root . '/' . $directory, 02770);
        }
        $files = new UpgradeSharedFileStore($this->root, 37001, 37002, 37003, 65536, 50, $this->statOperations());
        $this->registry = new FileMigrationRegistry($files);
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->sqliteCreateFunction('GET_LOCK', static fn(string $name, int $timeout): int => 1, 2);
        $this->pdo->sqliteCreateFunction('RELEASE_LOCK', static fn(string $name): int => 1, 1);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testExecutesCheckpointedStatementsAndReplaysCompletedResult(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS demo (id INTEGER PRIMARY KEY)\n"
            . "-- mallbase:statement-breakpoint\n"
            . 'CREATE INDEX IF NOT EXISTS idx_demo_id ON demo(id)';
        $checksum = 'sha256:' . hash('sha256', $sql);
        $service = $this->service();

        $first = $service->execute($this->job(), '20260713_demo', '1.2.0', $checksum, $sql);
        $second = $service->execute($this->job(), '20260713_demo', '1.2.0', $checksum, $sql);

        $this->assertSame('completed', $first['state']);
        $this->assertSame(2, $first['next_statement']);
        $this->assertSame($first, $second);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='demo'")->fetchColumn());
    }

    public function testRejectsChecksumReuseAndForbiddenOrMultipleStatements(): void
    {
        $sql = 'CREATE TABLE IF NOT EXISTS one (id INTEGER PRIMARY KEY)';
        $this->service()->execute($this->job(), 'same_id', '1.2.0', 'sha256:' . hash('sha256', $sql), $sql);

        $changed = 'CREATE TABLE IF NOT EXISTS two (id INTEGER PRIMARY KEY)';
        $this->assertFailure('UPGRADE_MIGRATION_CHECKSUM_CONFLICT', fn() => $this->service()->execute(
            $this->job(),
            'same_id',
            '1.2.0',
            'sha256:' . hash('sha256', $changed),
            $changed,
        ));
        $this->assertFailure('UPGRADE_MIGRATION_STATEMENT_FORBIDDEN', fn() => $this->service()->statements('BEGIN'));
        $this->assertFailure('UPGRADE_MIGRATION_MULTIPLE_STATEMENTS', fn() => $this->service()->statements('SELECT 1; SELECT 2'));
        $this->assertFailure('UPGRADE_MIGRATION_INPUT_INVALID', fn() => $this->service()->statements(
            "SELECT 1\n-- mallbase:statement-breakpoint\n",
        ));
    }

    public function testResumesFromPersistedStatementBoundary(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS first_step (id INTEGER PRIMARY KEY)\n"
            . "-- mallbase:statement-breakpoint\n"
            . 'CREATE TABLE IF NOT EXISTS resumed_step (id INTEGER PRIMARY KEY)';
        $checksum = 'sha256:' . hash('sha256', $sql);
        $this->registry->claim($this->job(), 'resume', '1.2.0', $checksum, 2, 999);
        $this->registry->checkpoint('resume', $checksum, 1, 1000);

        $result = $this->service()->execute($this->job(), 'resume', '1.2.0', $checksum, $sql);

        $this->assertSame('completed', $result['state']);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='first_step'")->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='resumed_step'")->fetchColumn());
    }

    private function service(): SchemaMigrationService
    {
        return new SchemaMigrationService(
            $this->registry,
            new UpgradeMigrationAdvisoryLock(fn(): PDO => $this->pdo, 'mbs_test_namespace', 0),
            fn(): int => ++$this->now,
        );
    }

    private function job(): string
    {
        return '33333333-3333-4333-8333-333333333333';
    }

    private function assertFailure(string $expected, callable $callback): void
    {
        try {
            $callback();
            self::fail('expected failure ' . $expected);
        } catch (\RuntimeException $exception) {
            $this->assertSame($expected, $exception->getMessage());
        }
    }

    /** @return array<string, callable> */
    private function statOperations(): array
    {
        return [
            'lstat' => static function (string $path): array|false {
                $stat = @lstat($path);
                if ($stat !== false) {
                    $stat['gid'] = 37002;
                    $stat['uid'] = ($stat['mode'] & 0170000) === 0040000 ? 37001 : 37003;
                }
                return $stat;
            },
            'fstat' => static function ($handle): array|false {
                $stat = fstat($handle);
                if ($stat !== false) {
                    $stat['gid'] = 37002;
                    $stat['uid'] = ($stat['mode'] & 0170000) === 0040000 ? 37001 : 37003;
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
