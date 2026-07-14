<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\controller\upgrade\SimpleUpgradeController;
use app\service\upgrade\SimpleUpgradeRuntimeService;
use app\service\upgrade\UpgradeStrictJsonDecoder;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Container;
use think\Request;

final class SimpleUpgradeControllerTest extends TestCase
{
    private const JOB_ID = '11111111-1111-4111-8111-111111111111';

    private Container $previousContainer;
    private App $app;
    private Request $request;
    private SimpleControllerRuntimeFake $runtime;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('json')) {
            require_once dirname(__DIR__, 3) . '/vendor/topthink/framework/src/helper.php';
        }
        $this->previousContainer = Container::getInstance();
        $this->app = new App(dirname(__DIR__, 3));
        $this->request = new Request();
        $this->runtime = new SimpleControllerRuntimeFake();
        $this->app->instance('request', $this->request);
        $this->app->instance(UpgradeStrictJsonDecoder::class, new UpgradeStrictJsonDecoder());
        $this->app->instance(SimpleUpgradeRuntimeService::class, $this->runtime);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function testProductionControllerExists(): void
    {
        self::assertTrue(
            class_exists(SimpleUpgradeController::class),
            'SimpleUpgradeController production class is missing',
        );
    }

    public function testUnknownPauseFieldIsRejectedBeforeService(): void
    {
        $controller = $this->controller('{"action":"upgrade","source_version":"1.2.0","target_version":"1.3.0","unknown":true}');

        $response = $controller->pause(self::JOB_ID);
        $body = json_decode($response->getContent(), true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(422, $response->getCode());
        self::assertGreaterThan(0, $body['timestamp'] ?? 0);
        self::assertSame([], $this->runtime->calls);
    }

    public function testExactEmptyBackupObjectDelegatesAndReturnsNonNullData(): void
    {
        $controller = $this->controller('{}');

        $response = $controller->backupDatabase(self::JOB_ID);
        $body = json_decode($response->getContent(), true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getCode(), $response->getContent());
        self::assertSame([['backup', self::JOB_ID, []]], $this->runtime->calls);
        self::assertSame(['operation' => 'backup'], $body['data']);
        self::assertGreaterThan(0, $body['timestamp'] ?? 0);
    }

    public function testExactEmptyResumeObjectDelegatesAndReturnsState(): void
    {
        $controller = $this->controller('{}');

        $response = $controller->resume(self::JOB_ID);
        $body = json_decode($response->getContent(), true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getCode(), $response->getContent());
        self::assertSame([['resume', self::JOB_ID, []]], $this->runtime->calls);
        self::assertSame(['state' => 'normal'], $body['data']);
        self::assertGreaterThan(0, $body['timestamp'] ?? 0);
    }

    private function controller(string $body): SimpleUpgradeController
    {
        $this->request->withInput($body)->withHeader([
            'content-type' => 'application/json',
            'content-length' => (string) strlen($body),
        ]);

        return new SimpleUpgradeController($this->app);
    }
}

final class SimpleControllerRuntimeFake
{
    /** @var list<array{string,string,array<string,mixed>}> */
    public array $calls = [];

    public function pause(string $jobId, array $body): array
    {
        $this->calls[] = ['pause', $jobId, $body];

        return ['operation' => 'pause'];
    }

    public function backup(string $jobId, array $body): array
    {
        $this->calls[] = ['backup', $jobId, $body];

        return ['operation' => 'backup'];
    }

    public function restore(string $jobId, array $body): array { return ['operation' => 'restore']; }
    public function migrate(string $jobId, array $body): array { return ['operation' => 'migrate']; }
    public function awaitingRestart(string $jobId, array $body): array { return ['operation' => 'awaiting']; }
    public function resume(string $jobId, array $body): array
    {
        $this->calls[] = ['resume', $jobId, $body];

        return ['state' => 'normal'];
    }
}
