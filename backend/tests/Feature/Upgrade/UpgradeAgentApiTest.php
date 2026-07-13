<?php

declare(strict_types=1);

namespace Tests\Feature\Upgrade;

use PHPUnit\Framework\TestCase;

final class UpgradeAgentApiTest extends TestCase
{
    public function testAgentRoutesAreCapabilityProtectedAndNeverShareBrowserMiddleware(): void
    {
        $route = file_get_contents(dirname(__DIR__, 3) . '/route/upgrade.php');
        self::assertIsString($route);
        foreach ([
            'backup-database', 'migrations', 'confirm-paused', 'runtime-fence', 'resume', 'cancel',
            'platform-receipt',
            'persistent-state-verification', 'reconciliation', 'operations/:operationId',
            'health', 'writable-surface-audit',
        ] as $path) {
            self::assertStringContainsString("'{$path}'", $route);
        }
        self::assertMatchesRegularExpression(
            '/upgrade\/api\/agent\/jobs\/:jobId[\s\S]+UpgradeAgentCapabilityMiddleware::class/',
            $route,
        );
        self::assertStringNotContainsString(
            'UpgradeSessionAuthMiddleware::class, UpgradeAgentCapabilityMiddleware::class',
            $route,
        );
    }
}
