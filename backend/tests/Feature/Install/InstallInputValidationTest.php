<?php

declare(strict_types=1);

namespace Tests\Feature\Install;

use app\controller\install\InstallController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class InstallInputValidationTest extends TestCase
{
    public function testUnsafeDatabaseNameBlocksInstall(): void
    {
        $validation = $this->validate($this->validParams([
            'db_name' => 'mall-base;drop',
        ]));

        $this->assertFalse($validation['success']);
        $this->assertSame('数据库名只能包含字母、数字和下划线', $validation['message']);
    }

    public function testSafeDatabaseNamePassesValidationWithoutConfigToken(): void
    {
        $validation = $this->validate($this->validParams());

        $this->assertTrue($validation['success']);
    }

    public function testShortAdminPasswordBlocksInstall(): void
    {
        $validation = $this->validate($this->validParams([
            'admin_pass' => 'secret123',
        ]));

        $this->assertFalse($validation['success']);
        $this->assertSame('管理员密码至少 12 位', $validation['message']);
    }

    public function testClientEntryChecksClientBuildIndexHtml(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/app/service/install/InstallService.php');

        $this->assertStringContainsString("'client' . DIRECTORY_SEPARATOR . 'index.html'", $source);
        $this->assertStringContainsString("'admin' . DIRECTORY_SEPARATOR . 'index.html'", $source);
        $this->assertStringNotContainsString("\$path = \$publicRoot . DIRECTORY_SEPARATOR . 'index.php';", $source);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validParams(array $overrides = []): array
    {
        return array_merge([
            'admin_pass' => 'secret123456',
            'admin_user' => 'admin',
            'db_host' => '127.0.0.1',
            'db_name' => 'mall_base',
            'db_user' => 'root',
            'redis_host' => '127.0.0.1',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{success: bool, message: string}
     */
    private function validate(array $params): array
    {
        $reflection = new ReflectionClass(InstallController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('validateInstallInput');
        $method->setAccessible(true);

        return $method->invoke($controller, $params);
    }
}
