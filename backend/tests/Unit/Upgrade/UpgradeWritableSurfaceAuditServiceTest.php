<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\LocalUploadRootPolicy;
use app\service\upgrade\UpgradeWritableSurfaceAuditService;
use PHPUnit\Framework\TestCase;

final class UpgradeWritableSurfaceAuditServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $path = sys_get_temp_dir() . '/mallbase-writable-audit-' . bin2hex(random_bytes(8));
        mkdir($path . '/uploads', 0770, true);
        $this->root = (string) realpath($path);
    }

    protected function tearDown(): void
    {
        @rmdir($this->root . '/uploads');
        @rmdir($this->root);
    }

    public function testAcceptsOnlyCanonicalUploadsWithoutReturningPath(): void
    {
        $result = (new UpgradeWritableSurfaceAuditService(
            new LocalUploadRootPolicy(),
            $this->root,
            static fn(): string => 'uploads',
        ))->audit();

        $this->assertTrue($result['supported']);
        $this->assertSame('WRITABLE_SURFACE_SUPPORTED', $result['code']);
        $this->assertStringNotContainsString($this->root, json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** @dataProvider invalidRoots */
    public function testRejectsAlternateAbsoluteTraversalAndEmptyRoots(string $root): void
    {
        $result = (new UpgradeWritableSurfaceAuditService(
            new LocalUploadRootPolicy(),
            $this->root,
            static fn(): string => $root,
        ))->audit();

        $this->assertFalse($result['supported']);
        $this->assertSame('UPGRADE_LOCAL_UPLOAD_ROOT_UNSUPPORTED', $result['code']);
        if ($root !== '') {
            $this->assertStringNotContainsString($root, json_encode($result, JSON_THROW_ON_ERROR));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidRoots(): iterable
    {
        yield 'absolute' => ['/var/lib/uploads'];
        yield 'alternate' => ['media'];
        yield 'traversal' => ['../uploads'];
        yield 'dot' => ['.'];
        yield 'empty' => [''];
    }
}
