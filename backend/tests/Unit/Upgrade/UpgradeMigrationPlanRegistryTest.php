<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\UpgradeMigrationPlanRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UpgradeMigrationPlanRegistryTest extends TestCase
{
    private string $root;
    private string $directory;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mallbase-migration-plan-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0750, true);
        $this->root = (string) realpath($this->root);
        $this->directory = $this->root . '/jobs/11111111-1111-4111-8111-111111111111/migrations';
        mkdir($this->directory, 0750, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testReadsOnlyManifestListedFixedMigrationFile(): void
    {
        $sql = 'ALTER TABLE mb_test ADD COLUMN upgrade_flag INT NOT NULL DEFAULT 0';
        file_put_contents($this->directory . '/202607130001.sql', $sql);
        file_put_contents($this->directory . '/migration-plan.json', json_encode([
            'schema_version' => 1,
            'job_id' => '11111111-1111-4111-8111-111111111111',
            'migrations' => [[
                'id' => '202607130001',
                'version' => '1.2.4',
                'sha256' => 'sha256:' . hash('sha256', $sql),
            ]],
        ], JSON_THROW_ON_ERROR));

        $migration = (new UpgradeMigrationPlanRegistry($this->root))
            ->migration('11111111-1111-4111-8111-111111111111', '202607130001');

        self::assertSame($sql, $migration['sql']);
        self::assertSame('1.2.4', $migration['version']);
    }

    public function testRejectsUnknownIdChecksumMismatchAndSymlink(): void
    {
        $sql = 'SELECT 1';
        file_put_contents($this->directory . '/202607130001.sql', $sql);
        file_put_contents($this->directory . '/migration-plan.json', json_encode([
            'schema_version' => 1,
            'job_id' => '11111111-1111-4111-8111-111111111111',
            'migrations' => [[
                'id' => '202607130001',
                'version' => '1.2.4',
                'sha256' => 'sha256:' . str_repeat('0', 64),
            ]],
        ], JSON_THROW_ON_ERROR));
        $sut = new UpgradeMigrationPlanRegistry($this->root);
        $this->assertCode('UPGRADE_MIGRATION_UNKNOWN', fn() => $sut->migration(
            '11111111-1111-4111-8111-111111111111',
            'not-listed',
        ));
        $this->assertCode('UPGRADE_MIGRATION_CHECKSUM_MISMATCH', fn() => $sut->migration(
            '11111111-1111-4111-8111-111111111111',
            '202607130001',
        ));
        unlink($this->directory . '/202607130001.sql');
        symlink(__FILE__, $this->directory . '/202607130001.sql');
        $this->assertCode('UPGRADE_MIGRATION_STAGING_INVALID', fn() => $sut->migration(
            '11111111-1111-4111-8111-111111111111',
            '202607130001',
        ));
    }

    private function assertCode(string $code, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected migration staging failure.');
        } catch (RuntimeException $exception) {
            self::assertSame($code, $exception->getMessage());
        }
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
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
        @rmdir($path);
    }
}
