<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use app\middleware\upgrade\SimpleUpgradeAuthMiddleware;
use app\service\install\InstallLockService;
use PHPUnit\Framework\TestCase;
use think\Request;
use think\Response;

final class SimpleUpgradeAuthMiddlewareTest extends TestCase
{
    private const JOB_ID = '11111111-1111-4111-8111-111111111111';
    private const NOW = 1_900_000_000;

    private string $root;
    private string $token;
    private InstallLockService $installLock;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('response')) {
            require_once dirname(__DIR__, 3) . '/vendor/topthink/framework/src/helper.php';
        }
        $this->root = sys_get_temp_dir() . '/mallbase-simple-auth-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/install', 0770, true);
        $this->token = 'mbt_local_upgrade_token';
        $this->installLock = new InstallLockService($this->root . '/install/install.lock');
        $this->writeInstallLock();
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/install/install.lock');
        @rmdir($this->root . '/install');
        @rmdir($this->root);
        parent::tearDown();
    }

    public function testProductionMiddlewareExists(): void
    {
        self::assertTrue(
            class_exists(SimpleUpgradeAuthMiddleware::class),
            'SimpleUpgradeAuthMiddleware production class is missing',
        );
    }

    public function testValidSignatureAuthorizesExactPostWithoutQuery(): void
    {
        $called = false;
        $response = $this->middleware()->handle(
            $this->request('{"action":"upgrade"}'),
            static function () use (&$called): Response {
                $called = true;

                return response('authorized', 200);
            },
        );

        self::assertTrue($called);
        self::assertSame(200, $response->getCode());
    }

    public function testValidResumeSignatureIsAuthorized(): void
    {
        $called = false;
        $response = $this->middleware()->handle(
            $this->request('{}', action: 'resume'),
            static function () use (&$called): Response {
                $called = true;

                return response('authorized', 200);
            },
        );

        self::assertTrue($called);
        self::assertSame(200, $response->getCode());
    }

    public function testTamperedBodyStaleTimestampAndQueryReturnSame401(): void
    {
        $validBody = '{"action":"upgrade"}';
        $tampered = $this->request('{"action":"rollback_latest"}', self::NOW, $validBody);
        $stale = $this->request($validBody, self::NOW - 61);
        $query = $this->request($validBody)->withGet(['debug' => '1'])->withServer([
            'QUERY_STRING' => 'debug=1',
        ]);

        $responses = [];
        foreach ([$tampered, $stale, $query] as $request) {
            $responses[] = $this->middleware()->handle(
                $request,
                static fn(): Response => response('unexpected', 200),
            );
        }

        foreach ($responses as $response) {
            self::assertSame(401, $response->getCode());
            self::assertStringNotContainsString($this->token, $response->getContent());
        }
        self::assertSame($responses[0]->getContent(), $responses[1]->getContent());
        self::assertSame($responses[0]->getContent(), $responses[2]->getContent());
    }

    public function testDisabledEmptyOrNonPrintableTokenReturns401(): void
    {
        foreach ([
            ['disabled' => true],
            ['token' => ''],
            ['token' => "invalid\ntoken"],
            ['token' => 'invalid token'],
        ] as $override) {
            $this->writeInstallLock($override);
            $signingToken = is_string($override['token'] ?? null) ? $override['token'] : $this->token;
            $response = $this->middleware()->handle(
                $this->request('{}', self::NOW, null, $signingToken),
                static fn(): Response => response('unexpected', 200),
            );
            self::assertSame(401, $response->getCode());
        }
    }

    private function middleware(): SimpleUpgradeAuthMiddleware
    {
        return new SimpleUpgradeAuthMiddleware($this->installLock, static fn(): int => self::NOW);
    }

    private function request(
        string $body,
        int $timestamp = self::NOW,
        ?string $signedBody = null,
        ?string $signingToken = null,
        string $action = 'pause',
    ): Request
    {
        $path = '/upgrade/api/simple/jobs/' . self::JOB_ID . '/' . $action;
        $canonical = "POST\n{$path}\n{$timestamp}\n" . hash('sha256', $signedBody ?? $body);
        $key = hash_hmac('sha256', 'mallbase-local-upgrade-v1', $signingToken ?? $this->token, true);
        $signature = hash_hmac('sha256', $canonical, $key);

        return (new Request())
            ->setMethod('POST')
            ->setPathinfo(ltrim($path, '/'))
            ->withInput($body)
            ->withServer([
                'QUERY_STRING' => '',
                'REQUEST_URI' => $path,
            ])
            ->withHeader([
                'X-MallBase-Upgrade-Timestamp' => (string) $timestamp,
                'X-MallBase-Upgrade-Signature' => $signature,
            ]);
    }

    /** @param array<string,mixed> $override */
    private function writeInstallLock(array $override = []): void
    {
        file_put_contents($this->root . '/install/install.lock', json_encode([
            'installed_at' => '2026-07-14 10:00:00',
            'platform' => array_replace([
                'instance_id' => '22222222-2222-4222-8222-222222222222',
                'token' => $this->token,
                'disabled' => false,
            ], $override),
        ], JSON_THROW_ON_ERROR));
    }
}
