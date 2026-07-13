<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\middleware\upgrade\UpgradeAgentCapabilityMiddleware;
use app\service\upgrade\UpgradeAgentNonceStore;
use app\service\upgrade\UpgradeAgentRuntimePolicy;
use app\service\upgrade\UpgradeGateRepository;
use app\service\upgrade\UpgradeGateSnapshot;
use app\service\upgrade\UpgradeOperationStore;
use app\service\upgrade\UpgradeRedisConnectionFactory;
use app\service\upgrade\UpgradeRuntimeIdentity;
use app\service\upgrade\UpgradeRuntimeIdentityProvider;
use app\service\upgrade\UpgradeSameOriginPolicy;
use app\service\upgrade\UpgradeSharedFileStore;
use app\service\upgrade\UpgradeState;
use PHPUnit\Framework\TestCase;
use think\Request;
use think\Response;

final class UpgradeAgentCapabilityMiddlewareTest extends TestCase
{
    private const JOB_ID = '11111111-1111-4111-8111-111111111111';
    private const NONCE = '22222222-2222-4222-8222-222222222222';
    private const TOKEN = 'task-capability-token-with-at-least-thirty-two-bytes';

    private string $root;
    private UpgradeSharedFileStore $files;

    protected function setUp(): void
    {
        if (!function_exists('json')) {
            require_once dirname(__DIR__, 3) . '/vendor/topthink/framework/src/helper.php';
        }
        $this->root = sys_get_temp_dir() . '/mallbase-agent-auth-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0750, true);
        foreach (['state', 'run', 'jobs'] as $directory) {
            mkdir($this->root . '/' . $directory, 02770);
            chmod($this->root . '/' . $directory, 02770);
        }
        $this->files = new UpgradeSharedFileStore(
            $this->root,
            43001,
            43002,
            43003,
            65536,
            50,
            $this->statOperations(),
        );
        $this->files->publishJobRequest(self::JOB_ID, (object) [
            'schema_version' => 1,
            'job_id' => self::JOB_ID,
            'instance_id' => '33333333-3333-4333-8333-333333333333',
            'current_version' => '1.2.3',
            'current_storage_layout_version' => 1,
            'target_version' => '1.2.4',
            'requested_by_admin_id' => 1,
            'requested_at' => time(),
            'callback_base_url' => 'http://127.0.0.1:8080',
            'capability_token' => self::TOKEN,
            'expected_revision' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testValidSignedRequestIsAuthorizedOnce(): void
    {
        $redis = new AgentNonceRedisFactory();
        $middleware = $this->middleware($redis, $this->snapshot());
        $request = $this->request();

        $response = $middleware->handle($request, static function (Request $request): Response {
            self::assertSame(self::JOB_ID, $request->upgrade_agent['job_id'] ?? null);
            self::assertSame('health', $request->upgrade_agent['action'] ?? null);

            return response('authorized', 200);
        });

        self::assertSame(200, $response->getCode(), $response->getContent());
        self::assertSame('authorized', $response->getContent());

        $replay = $middleware->handle($this->request(), static fn(): Response => response('unexpected', 200));
        self::assertSame(409, $replay->getCode());
        self::assertSame('UPGRADE_AGENT_REPLAYED', $this->reason($replay));
    }

    public function testSignatureMismatchAndFencedRuntimeNeverReachController(): void
    {
        $invalid = $this->request('sha256=' . str_repeat('0', 64));
        $called = false;
        $response = $this->middleware(new AgentNonceRedisFactory(), $this->snapshot())->handle(
            $invalid,
            static function () use (&$called): Response {
                $called = true;
                return response('unexpected', 200);
            },
        );
        self::assertSame(401, $response->getCode());
        self::assertFalse($called);

        $fenced = $this->snapshot('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $response = $this->middleware(new AgentNonceRedisFactory(), $fenced)->handle(
            $this->request(),
            static function () use (&$called): Response {
                $called = true;
                return response('unexpected', 200);
            },
        );
        self::assertSame(409, $response->getCode(), $response->getContent());
        self::assertSame('RUNTIME_IDENTITY_FENCED', $this->reason($response));
        self::assertFalse($called);
    }

    private function middleware(AgentNonceRedisFactory $redis, UpgradeGateSnapshot $snapshot): UpgradeAgentCapabilityMiddleware
    {
        $gate = $this->createStub(UpgradeGateRepository::class);
        $gate->method('snapshot')->willReturn($snapshot);
        $loader = $this->createStub(UpgradeRuntimeIdentityProvider::class);
        $loader->method('load')->willReturn(new UpgradeRuntimeIdentity(
            '1.2.3',
            '123e4567-e89b-42d3-a456-426614174000',
            1,
            7,
        ));

        return new UpgradeAgentCapabilityMiddleware(
            $this->files,
            new UpgradeAgentNonceStore($redis, 'mbs_agent_auth_test'),
            new UpgradeAgentRuntimePolicy($gate, $loader, new UpgradeOperationStore($this->files)),
            new UpgradeSameOriginPolicy('http://127.0.0.1:8080'),
        );
    }

    private function request(?string $signature = null): Request
    {
        $path = '/upgrade/api/agent/jobs/' . self::JOB_ID . '/health';
        $timestamp = (string) time();
        $body = '';
        $canonical = "GET\n{$path}\n{$timestamp}\n" . self::NONCE . "\n" . hash('sha256', $body);
        $key = hash('sha256', "mallbase-agent-local-api-v1\0" . self::TOKEN, true);
        $signature ??= 'sha256=' . hash_hmac('sha256', $canonical, $key);

        return (new Request())
            ->setMethod('GET')
            ->setPathinfo(ltrim($path, '/'))
            ->withGet(['jobId' => self::JOB_ID])
            ->withServer(['REQUEST_URI' => $path])
            ->withHeader([
                'Authorization' => 'MallBase-Agent ' . self::TOKEN,
                'Origin' => 'http://127.0.0.1:8080',
                'X-MallBase-Agent-Timestamp' => $timestamp,
                'X-MallBase-Agent-Nonce' => self::NONCE,
                'X-MallBase-Agent-Signature' => $signature,
            ]);
    }

    private function snapshot(string $deploymentId = '123e4567-e89b-42d3-a456-426614174000'): UpgradeGateSnapshot
    {
        return new UpgradeGateSnapshot(
            UpgradeState::Preparing,
            2,
            self::JOB_ID,
            '1.2.3',
            $deploymentId,
            1,
            7,
            1,
            1,
            str_repeat('a', 40),
            false,
            [],
            false,
            null,
            time(),
        );
    }

    private function reason(Response $response): string
    {
        return (string) (json_decode($response->getContent(), true, 16, JSON_THROW_ON_ERROR)['data']['reason'] ?? '');
    }

    /** @return array<string,callable> */
    private function statOperations(): array
    {
        return [
            'lstat' => static function (string $path): array|false {
                $stat = @lstat($path);
                if ($stat !== false) {
                    $stat['gid'] = 43002;
                    $stat['uid'] = ($stat['mode'] & 0170000) === 0040000 ? 43001 : 43003;
                }
                return $stat;
            },
            'fstat' => static function ($handle): array|false {
                $stat = fstat($handle);
                if ($stat !== false) {
                    $stat['gid'] = 43002;
                    $stat['uid'] = ($stat['mode'] & 0170000) === 0040000 ? 43001 : 43003;
                }
                return $stat;
            },
        ];
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @chmod($path, 0660);
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @chmod($path, 0770);
        @rmdir($path);
    }
}

final class AgentNonceRedisFactory implements UpgradeRedisConnectionFactory
{
    /** @var array<string,bool> */
    private array $keys = [];

    public function create(): object
    {
        return new class($this->keys) {
            /** @param array<string,bool> $keys */
            public function __construct(private array &$keys)
            {
            }

            /** @param array<mixed> $options */
            public function set(string $key, string $value, array $options): bool
            {
                unset($value, $options);
                if (isset($this->keys[$key])) {
                    return false;
                }
                $this->keys[$key] = true;
                return true;
            }
        };
    }
}
