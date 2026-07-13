<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use PHPUnit\Framework\TestCase;

final class UpgradeStaticPageContractTest extends TestCase
{
    public function testPageIsIndependentAndDoesNotReadAdminCredentials(): void
    {
        $root = dirname(__DIR__, 3) . '/public/upgrade/';
        $html = (string) file_get_contents($root . 'index.html');
        $script = (string) file_get_contents($root . 'app.js');

        $this->assertStringContainsString('data-mallbase-upgrade-shell="v1"', $html);
        $this->assertStringContainsString('src="/upgrade/app.js"', $html);
        $this->assertStringContainsString('href="/upgrade/styles.css"', $html);
        $this->assertStringNotContainsString('admin/', $html);
        $this->assertStringNotContainsString('localStorage', $script);
        $this->assertStringNotContainsString('document.cookie', $script);
        $this->assertStringNotContainsString('innerHTML', $script);
        $this->assertStringContainsString("credentials: 'same-origin'", $script);
        $this->assertStringContainsString("'/upgrade/api/status'", $script);
        $this->assertStringContainsString('textContent', $script);
        $this->assertStringContainsString('id="deployment-panel"', $html);
        $this->assertMatchesRegularExpression('/id="deployment-panel"[^>]*hidden/', $html);
    }

    public function testDisplayedCommandsAreFixedServerProjectionNotBrowserInput(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/public/upgrade/app.js');

        $this->assertStringContainsString("commands?.[architecture]", $script);
        $this->assertStringNotContainsString('eval(', $script);
        $this->assertStringNotContainsString('Function(', $script);
        $this->assertStringNotContainsString('docker build', $script);
        $this->assertStringNotContainsString('docker compose', $script);
        $this->assertStringContainsString("status.job?.state !== 'awaiting_deployment'", $script);
        $this->assertStringContainsString('navigator.clipboard.writeText(command)', $script);
        $this->assertStringContainsString('root.hidden = true', $script);
        $this->assertStringContainsString('root.hidden = false', $script);
        $this->assertStringNotContainsString('shell_command', $script);
    }
}
