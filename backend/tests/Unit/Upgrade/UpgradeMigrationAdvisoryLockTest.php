<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\UpgradeMigrationAdvisoryLock;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UpgradeMigrationAdvisoryLockTest extends TestCase
{
    public function testUsesOneDedicatedConnectionAndReleasesTheExactBoundedLock(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $acquired = [];
        $released = [];
        $pdo->sqliteCreateFunction('GET_LOCK', static function (string $name, int $timeout) use (&$acquired): int {
            $acquired[] = [$name, $timeout];
            return 1;
        });
        $pdo->sqliteCreateFunction('RELEASE_LOCK', static function (string $name) use (&$released): int {
            $released[] = $name;
            return 1;
        });
        $sut = new UpgradeMigrationAdvisoryLock(static fn(): PDO => $pdo, 'mbs_lock_test', 2);
        $job = '11111111-1111-4111-8111-111111111111';

        $result = $sut->withLock($job, '202607130001', static fn(PDO $connection): string =>
            $connection === $pdo ? 'same-connection' : 'wrong-connection');

        self::assertSame('same-connection', $result);
        self::assertCount(1, $acquired);
        self::assertSame(2, $acquired[0][1]);
        self::assertLessThanOrEqual(64, strlen($acquired[0][0]));
        self::assertSame([$acquired[0][0]], $released);
    }

    public function testZeroOrNullAcquisitionFailsClosedBeforeCallback(): void
    {
        foreach ([0, null] as $value) {
            $pdo = new PDO('sqlite::memory:');
            $pdo->sqliteCreateFunction('GET_LOCK', static fn(): mixed => $value);
            $called = false;
            $sut = new UpgradeMigrationAdvisoryLock(static fn(): PDO => $pdo, 'mbs_lock_test', 0);
            try {
                $sut->withLock(
                    '11111111-1111-4111-8111-111111111111',
                    'migration',
                    static function () use (&$called): void { $called = true; },
                );
                self::fail('Unavailable advisory lock was accepted.');
            } catch (RuntimeException $exception) {
                self::assertSame('UPGRADE_MIGRATION_RECOVERY_BLOCKED', $exception->getMessage());
            }
            self::assertFalse($called);
        }
    }
}
