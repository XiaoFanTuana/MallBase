<?php

declare(strict_types=1);

namespace Tests\Unit\Route;

use PHPUnit\Framework\TestCase;

final class PublicStaticRouteSecurityContractTest extends TestCase
{
    /**
     * @dataProvider staticRouteProvider
     */
    public function testStaticFallbackRejectsPathsOutsidePublicRoot(
        string $routeFile,
        string $rootVariable,
    ): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/route/' . $routeFile);

        self::assertStringContainsString("preg_match('#(?:^|/)\\.\\.(?:/|$)#'", $source);
        self::assertGreaterThanOrEqual(2, substr_count($source, 'realpath('));
        self::assertStringContainsString(
            sprintf('str_starts_with($filePath, $%s . DIRECTORY_SEPARATOR)', $rootVariable),
            $source,
        );
        self::assertStringContainsString("'X-Content-Type-Options' => 'nosniff'", $source);
    }

    public function testRootStaticFallbackDoesNotJoinUncheckedRequestPath(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/route/app.php');

        self::assertStringNotContainsString(
            "public_path() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, \$path)",
            $source,
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function staticRouteProvider(): array
    {
        return [
            'root' => ['app.php', 'publicRoot'],
            'admin' => ['admin.php', 'adminRoot'],
            'client' => ['client.php', 'clientRoot'],
        ];
    }
}
