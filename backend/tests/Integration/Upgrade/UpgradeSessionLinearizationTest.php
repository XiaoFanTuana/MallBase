<?php

declare(strict_types=1);

namespace Tests\Integration\Upgrade;

use app\service\upgrade\UpgradeSessionAuthStore;
use app\service\upgrade\UpgradeSharedFileStore;
use PHPUnit\Framework\TestCase;

final class UpgradeSessionLinearizationTest extends TestCase
{
    private const AGENT_UID = 37001;
    private const SHARED_GID = 37002;
    private const PHP_UID = 37003;
    private const INSTANCE_ID = 'd3ec761b-c5d1-4663-8c76-7d2d351efad5';
    private const CREATE_REQUEST = '11111111-1111-4111-8111-111111111111';
    private const TAKEOVER_REQUEST_A = '22222222-2222-4222-8222-222222222222';
    private const TAKEOVER_REQUEST_B = '33333333-3333-4333-8333-333333333333';

    private string $root;
    private string $key;
    private UpgradeSharedFileStore $files;
    private UpgradeSessionAuthStore $sessions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/mallbase-session-linearization-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0750, true);
        mkdir($this->root . '/run', 02770);
        chmod($this->root, 0750);
        chmod($this->root . '/run', 02770);
        $this->files = $this->newFileStore(100);
        $this->sessions = new UpgradeSessionAuthStore($this->files);
        $this->key = rtrim(strtr(base64_encode(str_repeat("\x42", 32)), '+/', '-_'), '=');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testConcurrentTakeoversHaveOneWinnerAndThatWinnerAloneCanConfirm(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is unavailable; cross-process session race cannot run.');
        }
        $created = $this->sessions->create(
            self::INSTANCE_ID,
            $this->key,
            1,
            self::CREATE_REQUEST,
            1000,
        );
        $this->sessions->confirm(
            $this->key,
            $created['owner_cookie'],
            self::CREATE_REQUEST,
            $created['confirmation_nonce'],
            1001,
        );
        $before = (string) file_get_contents($this->root . '/run/session-auth.json');
        $barrier = $this->root . '/takeover.start';

        $children = [
            self::TAKEOVER_REQUEST_A => $this->startTakeoverProcess(
                self::TAKEOVER_REQUEST_A,
                $created['recovery_credential'],
                $barrier,
            ),
            self::TAKEOVER_REQUEST_B => $this->startTakeoverProcess(
                self::TAKEOVER_REQUEST_B,
                $created['recovery_credential'],
                $barrier,
            ),
        ];
        touch($barrier);

        $results = [];
        foreach ($children as $requestId => $child) {
            $results[$requestId] = $this->finishProcess($child);
        }
        $successes = array_filter($results, static fn(array $result): bool => $result['ok'] === true);
        $failures = array_filter($results, static fn(array $result): bool => $result['ok'] === false);
        $this->assertCount(1, $successes, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertCount(1, $failures, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame('UPGRADE_SESSION_UNAUTHORIZED', array_values($failures)[0]['error']);

        $winnerRequest = (string) array_key_first($successes);
        $winner = $successes[$winnerRequest]['result'];
        $loserRequest = (string) array_key_first($failures);
        $visible = $this->sessions->load();
        $this->assertNotSame($before, (string) file_get_contents($this->root . '/run/session-auth.json'));
        $this->assertSame(3, $visible['revision']);
        $this->assertTrue($visible['copy_confirmation_pending']);
        $this->assertSame('takeover', $visible['pending_request']['action']);
        $this->assertSame($winnerRequest, $visible['pending_request']['request_id']);
        $this->assertSame('sha256:' . hash('sha256', $winner['owner_cookie']), $visible['owner_sha256']);
        $this->assertSame('sha256:' . hash('sha256', $winner['recovery_credential']), $visible['recovery_sha256']);

        $confirmed = $this->sessions->confirm(
            $this->key,
            $winner['owner_cookie'],
            $winnerRequest,
            $winner['confirmation_nonce'],
            1071,
        );
        $this->assertSame(4, $confirmed['revision']);
        $this->assertFalse($confirmed['copy_confirmation_pending']);
        $this->assertFailure('UPGRADE_SESSION_UNAUTHORIZED', fn() => $this->sessions->takeover(
            $this->key,
            $created['recovery_credential'],
            $loserRequest,
            1200,
        ));
    }

    public function testSessionMutationReturnsBusyWhileAnotherProcessHoldsTheSharedAuthLock(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is unavailable; cross-process session lock cannot run.');
        }
        $created = $this->sessions->create(
            self::INSTANCE_ID,
            $this->key,
            1,
            self::CREATE_REQUEST,
            1000,
        );
        $before = (string) file_get_contents($this->root . '/run/session-auth.json');
        $child = $this->startLockHolderProcess();
        $ready = fgets($child['pipes'][1]);
        $this->assertSame("locked\n", $ready, 'lock holder did not publish readiness');

        $contendingStore = $this->newFileStore(40);
        $contendingSessions = new UpgradeSessionAuthStore($contendingStore);
        $this->assertFailure('UPGRADE_SESSION_BUSY', fn() => $contendingSessions->confirm(
            $this->key,
            $created['owner_cookie'],
            self::CREATE_REQUEST,
            $created['confirmation_nonce'],
            1001,
        ));
        $this->assertSame($before, file_get_contents($this->root . '/run/session-auth.json'));

        $result = $this->finishProcess($child);
        $this->assertTrue($result['ok'], json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** @return array{process:resource,pipes:array<int,resource>} */
    private function startTakeoverProcess(string $requestId, string $credential, string $barrier): array
    {
        $script = $this->childBootstrap() . <<<'PHP'
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
while (!file_exists($input['barrier'])) {
    usleep(1000);
}
try {
    $result = $sessions->takeover($input['key'], $input['credential'], $input['request_id'], 1070);
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR);
}
PHP;
        $child = $this->startProcess($script);
        fwrite($child['pipes'][0], json_encode([
            'barrier' => $barrier,
            'key' => $this->key,
            'credential' => $credential,
            'request_id' => $requestId,
        ], JSON_THROW_ON_ERROR));
        fclose($child['pipes'][0]);
        unset($child['pipes'][0]);

        return $child;
    }

    /** @return array{process:resource,pipes:array<int,resource>} */
    private function startLockHolderProcess(): array
    {
        $script = $this->childBootstrap() . <<<'PHP'
try {
    $files->withSessionAuthLock(static function (): void {
        echo "locked\n";
        fflush(STDOUT);
        usleep(400000);
    });
    echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR);
}
PHP;

        return $this->startProcess($script, false);
    }

    private function childBootstrap(): string
    {
        return <<<'PHP'
require $argv[1];
$root = $argv[2];
$agentUid = (int) $argv[3];
$gid = (int) $argv[4];
$phpUid = (int) $argv[5];
$expectedOwner = static function (string $path, bool $directory) use ($agentUid, $phpUid): int {
    return $directory || str_ends_with($path, '/run/session-auth.json')
        || str_ends_with($path, '/run/session-auth.lock') ? $agentUid : $phpUid;
};
$operations = [
    'lstat' => static function (string $path) use ($gid, $expectedOwner): array|false {
        $stat = @lstat($path);
        if ($stat !== false) {
            $stat['gid'] = $gid;
            $stat['uid'] = $expectedOwner($path, ($stat['mode'] & 0170000) === 0040000);
        }
        return $stat;
    },
    'fstat' => static function ($handle) use ($gid, $expectedOwner): array|false {
        $stat = fstat($handle);
        if ($stat !== false) {
            $uri = (string) (stream_get_meta_data($handle)['uri'] ?? '');
            $stat['gid'] = $gid;
            $stat['uid'] = $expectedOwner($uri, ($stat['mode'] & 0170000) === 0040000);
        }
        return $stat;
    },
];
$files = new \app\service\upgrade\UpgradeSharedFileStore($root, $agentUid, $gid, $phpUid, 65536, 2000, $operations);
$sessions = new \app\service\upgrade\UpgradeSessionAuthStore($files);
PHP;
    }

    /** @return array{process:resource,pipes:array<int,resource>} */
    private function startProcess(string $script, bool $stdin = true): array
    {
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        if ($stdin) {
            $descriptors[0] = ['pipe', 'r'];
        }
        $process = proc_open([
            PHP_BINARY,
            '-d',
            'opcache.jit=0',
            '-d',
            'opcache.jit_buffer_size=0',
            '-r',
            $script,
            '--',
            dirname(__DIR__, 3) . '/vendor/autoload.php',
            $this->root,
            (string) self::AGENT_UID,
            (string) self::SHARED_GID,
            (string) self::PHP_UID,
        ], $descriptors, $pipes, dirname(__DIR__, 3));
        $this->assertIsResource($process);

        return ['process' => $process, 'pipes' => $pipes];
    }

    /** @param array{process:resource,pipes:array<int,resource>} $child @return array<string, mixed> */
    private function finishProcess(array $child): array
    {
        $stdout = stream_get_contents($child['pipes'][1]);
        $stderr = stream_get_contents($child['pipes'][2]);
        fclose($child['pipes'][1]);
        fclose($child['pipes'][2]);
        $exitCode = proc_close($child['process']);
        $this->assertSame(0, $exitCode, (string) $stderr);
        $decoded = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function newFileStore(int $lockTimeoutMilliseconds): UpgradeSharedFileStore
    {
        return new UpgradeSharedFileStore(
            $this->root,
            self::AGENT_UID,
            self::SHARED_GID,
            self::PHP_UID,
            65536,
            $lockTimeoutMilliseconds,
            $this->statOperations(),
        );
    }

    /** @return array<string, callable> */
    private function statOperations(): array
    {
        return [
            'lstat' => function (string $path): array|false {
                $stat = @lstat($path);
                if ($stat !== false) {
                    $stat['gid'] = self::SHARED_GID;
                    $stat['uid'] = $this->expectedOwner($path, ($stat['mode'] & 0170000) === 0040000);
                }

                return $stat;
            },
            'fstat' => function ($handle): array|false {
                $stat = fstat($handle);
                if ($stat !== false) {
                    $uri = (string) (stream_get_meta_data($handle)['uri'] ?? '');
                    $stat['gid'] = self::SHARED_GID;
                    $stat['uid'] = $this->expectedOwner($uri, ($stat['mode'] & 0170000) === 0040000);
                }

                return $stat;
            },
        ];
    }

    private function expectedOwner(string $path, bool $directory): int
    {
        return $directory || str_ends_with($path, '/run/session-auth.json')
            || str_ends_with($path, '/run/session-auth.lock')
            ? self::AGENT_UID
            : self::PHP_UID;
    }

    private function assertFailure(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected ' . $message);
        } catch (\RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @chmod($path, 0660);
            @unlink($path);

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
