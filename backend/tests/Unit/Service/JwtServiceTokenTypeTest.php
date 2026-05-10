<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use mall_base\service\JwtService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class JwtServiceTokenTypeTest extends TestCase
{
    public function testEncodeForcesAccessAndRefreshTokenTypes(): void
    {
        $service = $this->makeJwtService();

        $tokens = $service->encode([
            'admin_id' => 1,
            'type' => 'refresh',
            'username' => 'admin',
        ]);

        $accessPayload = $service->decode($tokens['access_token'])->data;
        $refreshPayload = $service->decode($tokens['refresh_token'])->data;

        $this->assertSame('access', $accessPayload->type);
        $this->assertSame('refresh', $refreshPayload->type);
    }

    private function makeJwtService(): JwtService
    {
        $reflection = new ReflectionClass(JwtService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $this->setProperty($service, 'key', 'unit-test-secret-at-least-thirty-two-bytes');
        $this->setProperty($service, 'expire', 7200);
        $this->setProperty($service, 'refreshExpire', 2592000);
        $this->setProperty($service, 'algorithm', 'HS256');
        $this->setProperty($service, 'issuer', 'mall-admin-test');

        return $service;
    }

    private function setProperty(JwtService $service, string $name, mixed $value): void
    {
        $property = (new ReflectionClass($service))->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($service, $value);
    }
}
