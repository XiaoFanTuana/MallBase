<?php

declare(strict_types=1);

namespace Tests\Feature\Upgrade;

use app\service\install\InstallService;
use app\service\upgrade\UpgradeControlRateLimiter;
use app\service\upgrade\UpgradeRedisConnectionFactory;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Request;
use think\Response;

final class MaintenanceStatusApiTest extends TestCase
{
    public function testPublicMaintenanceStatusIsJsonAndContainsOnlyThePublicProjection(): void
    {
        $response = $this->dispatchMaintenanceStatus();
        $headers = $response->getHeader();

        self::assertSame(200, $response->getCode());
        self::assertStringStartsWith('application/json', (string) ($headers['Content-Type'] ?? ''));
        self::assertSame('no-store', $headers['Cache-Control'] ?? null);

        $body = json_decode($response->getContent(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(['code', 'message', 'data', 'timestamp'], array_keys($body));
        self::assertSame(200, $body['code']);
        self::assertIsArray($body['data']);
        self::assertSame(
            ['state', 'reason', 'retry_after', 'updated_at'],
            array_keys($body['data']),
            'Public maintenance status must not expose session, job, event, Agent, path, or migration details.',
        );
        self::assertContains($body['data']['reason'], ['SYSTEM_AVAILABLE', 'SYSTEM_MAINTENANCE']);
        self::assertIsInt($body['data']['retry_after']);
        self::assertGreaterThan(0, $body['data']['retry_after']);
        self::assertIsInt($body['data']['updated_at']);
    }

    private function dispatchMaintenanceStatus(): Response
    {
        $app = new App(dirname(__DIR__, 3));
        $app->instance(InstallService::class, new class extends InstallService {
            public function isInstalled(): bool
            {
                return true;
            }
        });
        $app->instance(
            UpgradeControlRateLimiter::class,
            new UpgradeControlRateLimiter(
                new MaintenanceStatusRedisFactory(),
                'mbs_maintenance_status_test',
            ),
        );

        $request = $app->make(Request::class);
        $request->setPathinfo('upgrade/api/maintenance');
        $request->withServer([
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'localhost',
            'SERVER_NAME' => 'localhost',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        return $app->http->run($request);
    }
}

final class MaintenanceStatusRedisFactory implements UpgradeRedisConnectionFactory
{
    public function create(): object
    {
        return new class {
            public function eval(string $script, array $arguments, int $keyCount): int
            {
                if ($script === '' || count($arguments) !== 2 || $keyCount !== 1) {
                    throw new \RuntimeException('invalid rate-limit request');
                }

                return 1;
            }
        };
    }
}
