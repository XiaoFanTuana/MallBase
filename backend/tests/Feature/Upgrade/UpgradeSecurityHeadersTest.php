<?php

declare(strict_types=1);

namespace Tests\Feature\Upgrade;

use PHPUnit\Framework\TestCase;

final class UpgradeSecurityHeadersTest extends TestCase
{
    public function testPageDeliveryDefinesStrictHeadersAndNoInlineScriptAllowance(): void
    {
        $controller = (string) file_get_contents(
            dirname(__DIR__, 3) . '/app/controller/upgrade/UpgradePageController.php',
        );

        $this->assertStringContainsString("'X-Frame-Options' => 'DENY'", $controller);
        $this->assertStringContainsString("'X-Content-Type-Options' => 'nosniff'", $controller);
        $this->assertStringContainsString("'Referrer-Policy' => 'no-referrer'", $controller);
        $this->assertStringContainsString("script-src 'self'", $controller);
        $this->assertStringContainsString("frame-ancestors 'none'", $controller);
        $this->assertStringNotContainsString("'unsafe-inline'", $controller);
        $this->assertStringNotContainsString("'unsafe-eval'", $controller);
    }

    public function testUpgradeCookieIsHttpOnlyStrictAndPathScoped(): void
    {
        $controllers = (string) file_get_contents(
            dirname(__DIR__, 3) . '/app/controller/admin/upgrade/UpgradeController.php',
        ) . (string) file_get_contents(
            dirname(__DIR__, 3) . '/app/controller/upgrade/UpgradeRuntimeController.php',
        );

        $this->assertSame(2, substr_count($controllers, "'path' => '/upgrade/'"));
        $this->assertSame(2, substr_count($controllers, "'httponly' => true"));
        $this->assertSame(2, substr_count($controllers, "'samesite' => 'Strict'"));
        $this->assertStringNotContainsString('Access-Control-Allow-Credentials', $controllers);
    }
}
