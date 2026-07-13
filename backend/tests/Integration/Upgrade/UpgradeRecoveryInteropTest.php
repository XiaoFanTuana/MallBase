<?php

declare(strict_types=1);

namespace Tests\Integration\Upgrade;

use app\service\install\AgentInstanceConfigStore;
use app\service\install\InstallLockService;
use app\service\upgrade\UpgradeSessionAuthStore;
use app\service\upgrade\UpgradeSharedFileStore;
use PHPUnit\Framework\TestCase;

final class UpgradeRecoveryInteropTest extends TestCase
{
    private const AGENT_UID = 36001;
    private const SHARED_GID = 36002;
    private const PHP_UID = 36003;
    private const INSTANCE_ID = 'd3ec761b-c5d1-4663-8c76-7d2d351efad5';
    private const CREATE_REQUEST = '11111111-1111-4111-8111-111111111111';

    private string $root;
    private string $legacyPath;
    private UpgradeSharedFileStore $files;
    private AgentInstanceConfigStore $instances;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/mallbase-recovery-interop-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0750, true);
        foreach (['config', 'run'] as $directory) {
            mkdir($this->root . '/' . $directory, 02770);
            chmod($this->root . '/' . $directory, 02770);
        }
        mkdir($this->root . '/staging', 0750);
        chmod($this->root, 0750);
        chmod($this->root . '/staging', 0750);

        $this->legacyPath = $this->root . '/install.lock';
        file_put_contents($this->legacyPath, json_encode([
            'platform' => ['instance_id' => self::INSTANCE_ID, 'token' => 'mbt_interop_token'],
        ], JSON_THROW_ON_ERROR));
        chmod($this->legacyPath, 0660);
        file_put_contents($this->root . '/staging/storage-namespace.json', json_encode((object) [
            'schema_version' => 1,
            'installation_storage_namespace' => 'mbs_interop_namespace',
        ], JSON_THROW_ON_ERROR));
        chmod($this->root . '/staging/storage-namespace.json', 0444);

        $this->files = new UpgradeSharedFileStore(
            $this->root,
            self::AGENT_UID,
            self::SHARED_GID,
            self::PHP_UID,
            65536,
            100,
            $this->statOperations(),
        );
        $this->instances = new AgentInstanceConfigStore(
            $this->files,
            'https://platform.gosowong.cn',
            'mbs_interop_namespace',
            900,
            3600,
            100,
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testPhpV2InstanceAndSessionDocumentsKeepTheExactGoFieldContract(): void
    {
        $this->instances->initializeFromLegacy(new InstallLockService($this->legacyPath), 1000);
        $instance = $this->instances->ensureSessionDerivationKey(1001);
        $sessions = new UpgradeSessionAuthStore($this->files);
        $sessions->create(
            self::INSTANCE_ID,
            $instance['session_derivation_key'],
            1,
            self::CREATE_REQUEST,
            1002,
        );

        $instanceDocument = json_decode(
            (string) file_get_contents($this->root . '/config/instance.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $sessionDocument = json_decode(
            (string) file_get_contents($this->root . '/run/session-auth.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame([
            'activation_generation', 'activation_secret', 'activation_secret_expires_at',
            'activation_state', 'components', 'disabled', 'instance_id', 'platform_base_url',
            'report', 'revision', 'schema_version', 'session_derivation_key', 'token',
            'updated_at', 'upgrade_namespace_id',
        ], $this->sortedKeys($instanceDocument));
        $this->assertSame(2, $instanceDocument['schema_version']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $instanceDocument['session_derivation_key']);

        $this->assertSame([
            'confirmation_receipt', 'copy_confirmation_pending', 'instance_id', 'issued_at',
            'job_id', 'last_break_glass_event_id', 'last_seen_at', 'owner_expires_at',
            'owner_sha256', 'pending_request', 'previous_recovery_sha256', 'recovery_expires_at',
            'recovery_sha256', 'revision', 'schema_version', 'session_id',
        ], $this->sortedKeys($sessionDocument));
        $this->assertSame(
            ['action', 'issued_at', 'request_id', 'result_revision'],
            $this->sortedKeys($sessionDocument['pending_request']),
        );
        $this->assertNull($sessionDocument['job_id']);
        $this->assertNull($sessionDocument['confirmation_receipt']);
        $this->assertTrue($sessionDocument['copy_confirmation_pending']);
    }

    public function testCurrentGoConfigAndRecoveryContractTestsPassAgainstTheSameSchemaGeneration(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is unavailable; Go interoperability command cannot run.');
        }
        $agentRoot = $this->agentSourceRoot();
        if ($agentRoot === null || !is_file($agentRoot . '/go.mod')) {
            $this->markTestSkipped('mallbase-agent source is unavailable; set MALLBASE_AGENT_SOURCE to run the Go contract.');
        }
        $go = trim((string) shell_exec('command -v go 2>/dev/null'));
        if ($go === '' || !is_executable($go)) {
            $this->markTestSkipped('Go is unavailable; cross-language decoder tests cannot run.');
        }

        [$exitCode, $stdout, $stderr] = $this->runProcess([
            $go,
            'test',
            './internal/config',
            './internal/recovery',
            '-run',
            'TestDecodeInstanceAcceptsSchemaTwoSessionDerivationKey|TestIssueRotatesEligibleUnboundPendingSession|TestIssueIsNonblockingAndSerializesConcurrentCallers',
            '-count=1',
        ], $agentRoot);

        $this->assertSame(0, $exitCode, $stdout . "\n" . $stderr);
        $this->assertStringContainsString('internal/config', $stdout);
        $this->assertStringContainsString('internal/recovery', $stdout);
    }

    public function testReleasedLinuxRecoveryIssueOutputCanBeLoadedByThePhpStrictSchema(): void
    {
        $binary = getenv('MALLBASE_AGENT_RECOVERY_BINARY');
        if (PHP_OS_FAMILY !== 'Linux' || !is_string($binary) || $binary === '' || !is_executable($binary)) {
            $this->markTestSkipped(
                'Requires a released Linux upgrade/bin/mallbase-agent fixture with distinct Agent/PHP UIDs via MALLBASE_AGENT_RECOVERY_BINARY.',
            );
        }

        [$exitCode, $stdout, $stderr] = $this->runProcess([$binary, 'recovery', 'issue'], dirname($binary));
        $credential = trim($stdout);
        $this->assertSame(0, $exitCode, $stderr);
        $this->assertSame('', trim($stderr));
        $this->assertMatchesRegularExpression(
            '/^mbur1\.[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.[A-Za-z0-9_-]{43}$/D',
            $credential,
        );

        $upgradeRoot = dirname(dirname($binary));
        $binaryStat = lstat($binary);
        $instanceStat = lstat($upgradeRoot . '/config/instance.json');
        if (!is_array($binaryStat) || !is_array($instanceStat)) {
            self::fail('Released recovery fixture identities are unavailable.');
        }
        $agentUid = (int) $binaryStat['uid'];
        $phpUid = (int) $instanceStat['uid'];
        $gid = (int) $binaryStat['gid'];
        if ($agentUid === $phpUid) {
            self::fail('Released recovery fixture must use distinct Agent and PHP writers.');
        }
        $operations = $this->fixtureStatOperations($agentUid, $gid, $phpUid, $upgradeRoot);
        $files = new UpgradeSharedFileStore($upgradeRoot, $agentUid, $gid, $phpUid, 65536, 100, $operations);
        $session = (new UpgradeSessionAuthStore($files))->load();

        $this->assertIsArray($session);
        $this->assertNull($session['owner_sha256']);
        $this->assertSame('sha256:' . hash('sha256', $credential), $session['recovery_sha256']);
        $this->assertNotNull($session['last_break_glass_event_id']);
        $this->assertFalse($session['copy_confirmation_pending']);
    }

    /** @return list<string> */
    private function sortedKeys(array $document): array
    {
        $keys = array_keys($document);
        sort($keys, SORT_STRING);

        return $keys;
    }

    private function agentSourceRoot(): ?string
    {
        $configured = getenv('MALLBASE_AGENT_SOURCE');
        $candidate = is_string($configured) && $configured !== ''
            ? $configured
            : dirname(__DIR__, 5) . '/mallbase-agent';
        $resolved = realpath($candidate);

        return is_string($resolved) ? $resolved : null;
    }

    /** @return array{0:int,1:string,2:string} */
    private function runProcess(array $command, string $workingDirectory): array
    {
        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $workingDirectory);
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $stdout, (string) $stderr];
    }

    /** @return array<string, callable> */
    private function statOperations(): array
    {
        return $this->fixtureStatOperations(
            self::AGENT_UID,
            self::SHARED_GID,
            self::PHP_UID,
            $this->root,
        );
    }

    /** @return array<string, callable> */
    private function fixtureStatOperations(int $agentUid, int $gid, int $phpUid, string $root): array
    {
        $expectedOwner = static function (string $path, bool $directory) use ($agentUid, $phpUid, $root): int {
            if ($directory || str_ends_with($path, '/staging/storage-namespace.json')
                || str_ends_with($path, '/run/session-auth.json')
                || str_ends_with($path, '/run/session-auth.lock')
                || str_ends_with($path, '/run/session-audit.jsonl')
                || str_ends_with($path, '/run/session-audit.lock')) {
                return $agentUid;
            }
            if ($path === $root . '/config/instance.json') {
                return $phpUid;
            }

            return $phpUid;
        };

        return [
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
