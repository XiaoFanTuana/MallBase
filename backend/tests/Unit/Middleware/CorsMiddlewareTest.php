<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use app\middleware\CorsMiddleware;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use think\facade\Env;

final class CorsMiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->unsetEnv('CORS_ALLOWED_ORIGINS');
        $this->unsetEnv('CORS_ALLOW_CREDENTIALS');

        parent::tearDown();
    }

    public function testOriginMustBeAllowListed(): void
    {
        $this->setEnv('CORS_ALLOWED_ORIGINS', 'https://admin.example.com, https://shop.example.com');

        $method = new ReflectionMethod(CorsMiddleware::class, 'isAllowedOrigin');
        $method->setAccessible(true);
        $middleware = new CorsMiddleware();

        $this->assertTrue($method->invoke($middleware, 'https://admin.example.com'));
        $this->assertTrue($method->invoke($middleware, 'https://shop.example.com'));
        $this->assertFalse($method->invoke($middleware, 'https://evil.example.com'));
    }

    public function testCredentialsAreOptIn(): void
    {
        $method = new ReflectionMethod(CorsMiddleware::class, 'allowCredentials');
        $method->setAccessible(true);
        $middleware = new CorsMiddleware();

        $this->setEnv('CORS_ALLOW_CREDENTIALS', 'false');
        $this->assertFalse($method->invoke($middleware));

        $this->setEnv('CORS_ALLOW_CREDENTIALS', 'true');
        $this->assertTrue($method->invoke($middleware));
    }

    public function testWildcardIsIgnoredWhenCredentialsAreEnabled(): void
    {
        $this->setEnv('CORS_ALLOWED_ORIGINS', '*,https://admin.example.com');
        $this->setEnv('CORS_ALLOW_CREDENTIALS', 'true');

        $method = new ReflectionMethod(CorsMiddleware::class, 'isAllowedOrigin');
        $method->setAccessible(true);
        $middleware = new CorsMiddleware();

        $this->assertTrue($method->invoke($middleware, 'https://admin.example.com'));
        $this->assertFalse($method->invoke($middleware, 'https://evil.example.com'));

        $this->setEnv('CORS_ALLOW_CREDENTIALS', 'false');
        $this->assertTrue($method->invoke($middleware, 'https://evil.example.com'));
    }

    private function setEnv(string $key, string $value): void
    {
        Env::set($key, $value);
        putenv($key . '=' . $value);
        putenv('PHP_' . $key . '=' . $value);
        $_ENV[$key] = $value;
        $_ENV['PHP_' . $key] = $value;
        $_SERVER[$key] = $value;
        $_SERVER['PHP_' . $key] = $value;
    }

    private function unsetEnv(string $key): void
    {
        Env::set($key, null);
        putenv($key);
        putenv('PHP_' . $key);
        unset($_ENV[$key], $_ENV['PHP_' . $key], $_SERVER[$key], $_SERVER['PHP_' . $key]);
    }
}
