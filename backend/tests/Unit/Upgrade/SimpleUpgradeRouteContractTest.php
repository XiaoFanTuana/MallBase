<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use PHPUnit\Framework\TestCase;

final class SimpleUpgradeRouteContractTest extends TestCase
{
    public function testExactSimpleRoutesUseDedicatedAuthMiddlewareAndController(): void
    {
        $route = (string) file_get_contents(dirname(__DIR__, 3) . '/route/upgrade.php');

        self::assertStringContainsString("Route::group('upgrade/api/simple/jobs/:jobId'", $route);
        self::assertStringContainsString("->prefix('upgrade.SimpleUpgradeController/')", $route);
        self::assertStringContainsString('SimpleUpgradeAuthMiddleware::class', $route);
        self::assertSame(1, preg_match(
            "~Route::group\\('upgrade/api/simple/jobs/:jobId'.*?function \\(\\) \\{(?<definitions>.*?)\\}\\)->prefix\\('upgrade\\.SimpleUpgradeController/'\\)~s",
            $route,
            $matches,
        ));
        $definitions = $matches['definitions'] ?? '';
        foreach ([
            "Route::post('pause', 'pause')",
            "Route::post('backup-database', 'backupDatabase')",
            "Route::post('migrations', 'migrations')",
            "Route::post('restore-database', 'restoreDatabase')",
            "Route::post('awaiting-restart', 'awaitingRestart')",
            "Route::post('resume', 'resume')",
        ] as $definition) {
            self::assertStringContainsString($definition, $definitions);
        }
    }
}
