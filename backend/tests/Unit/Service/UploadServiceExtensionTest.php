<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use app\service\UploadService;
use mall_base\exception\BusinessException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class UploadServiceExtensionTest extends TestCase
{
    private UploadService $service;

    protected function setUp(): void
    {
        $this->service = (new ReflectionClass(UploadService::class))->newInstanceWithoutConstructor();
    }

    public function testRejectsAllowedMimeWithExecutableExtension(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('文件扩展名不允许');

        $this->validate($this->makeFile('avatar.php', 'image/jpeg'));
    }

    public function testRejectsDisallowedMime(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('文件类型不允许');

        $this->validate($this->makeFile('avatar.jpg', 'text/plain'));
    }

    public function testAllowsImageExtensionAndStoresMimeDerivedExtension(): void
    {
        $file = $this->makeFile('avatar.jpeg', 'image/jpeg');

        $this->validate($file);

        $extension = $this->invoke('resolveStorageExtension', [$file]);
        $this->assertSame('jpg', $extension);
    }

    public function testDatabaseMimeConfigNarrowsAllowedExtensions(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('文件扩展名不允许');

        $this->invoke('validateUploadFile', [
            $this->makeFile('avatar.png', 'image/jpeg'),
            [
                'accept_types' => ['image/jpeg'],
                'max_size' => 2,
            ],
        ]);
    }

    public function testRejectsAllowedButUnmappedMime(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('文件类型未配置安全扩展名映射');

        $this->invoke('validateUploadFile', [
            $this->makeFile('avatar.svg', 'image/svg+xml'),
            [
                'accept_types' => ['image/svg+xml'],
                'max_size' => 2,
            ],
        ]);
    }

    private function validate(object $file): void
    {
        $this->invoke('validateUploadFile', [$file, $this->imageRules()]);
    }

    /**
     * @return array{max_size: int, accept_types: string[]}
     */
    private function imageRules(): array
    {
        return [
            'accept_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            'max_size' => 2,
        ];
    }

    private function makeFile(string $originalName, string $mime): object
    {
        return new class($originalName, $mime) {
            public function __construct(
                private readonly string $originalName,
                private readonly string $mime
            ) {
            }

            public function getSize(): int
            {
                return 1024;
            }

            public function getMime(): string
            {
                return $this->mime;
            }

            public function getOriginalName(): string
            {
                return $this->originalName;
            }
        };
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function invoke(string $methodName, array $arguments): mixed
    {
        $method = (new ReflectionClass(UploadService::class))->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($this->service, $arguments);
    }
}
