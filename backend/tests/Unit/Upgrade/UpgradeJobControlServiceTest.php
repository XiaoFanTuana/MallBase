<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\UpgradeDrainControl;
use app\service\upgrade\UpgradeGateRepository;
use app\service\upgrade\UpgradeGateSnapshot;
use app\service\upgrade\UpgradeJobControlService;
use app\service\upgrade\UpgradeRuntimeIdentity;
use app\service\upgrade\UpgradeSessionAuthStore;
use app\service\upgrade\UpgradeSharedFileStore;
use app\service\upgrade\UpgradeState;
use PHPUnit\Framework\TestCase;

final class UpgradeJobControlServiceTest extends TestCase
{
    private const AGENT_UID = 35001;
    private const SHARED_GID = 35002;
    private const PHP_UID = 35003;
    private const INSTANCE_ID = 'd3ec761b-c5d1-4663-8c76-7d2d351efad5';
    private const CREATE_REQUEST = '11111111-1111-4111-8111-111111111111';
    private const JOB_REQUEST = '22222222-2222-4222-8222-222222222222';
    private const CONTROL_REQUEST = '33333333-3333-4333-8333-333333333333';

    private string $root;
    private UpgradeSharedFileStore $files;
    private UpgradeSessionAuthStore $sessions;
    private JobControlTestGate $gate;
    private UpgradeJobControlService $service;
    private string $owner;
    private string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mallbase-job-control-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0750, true);
        foreach (['run', 'jobs'] as $directory) {
            mkdir($this->root . '/' . $directory, 02770);
            chmod($this->root . '/' . $directory, 02770);
        }
        chmod($this->root, 0750);
        $this->files = new UpgradeSharedFileStore(
            $this->root,
            self::AGENT_UID,
            self::SHARED_GID,
            self::PHP_UID,
            65536,
            50,
            $this->statOperations(),
        );
        $this->sessions = new UpgradeSessionAuthStore($this->files);
        $key = rtrim(strtr(base64_encode(str_repeat("\x42", 32)), '+/', '-_'), '=');
        $created = $this->sessions->create(self::INSTANCE_ID, $key, 1, self::CREATE_REQUEST, 1000);
        $this->sessions->confirm(
            $key,
            $created['owner_cookie'],
            self::CREATE_REQUEST,
            $created['confirmation_nonce'],
            1001,
        );
        $this->owner = $created['owner_cookie'];
        $this->sessionId = $created['session_id'];
        $this->gate = new JobControlTestGate($this->snapshot(UpgradeState::Normal, 0, null));
        $this->service = new UpgradeJobControlService(
            $this->files,
            $this->sessions,
            $this->gate,
            new JobControlTestDrain($this->gate),
            'https://shop.example.com',
            static fn(): int => 1100,
            static fn(): string => str_repeat('A', 43),
        );
        $this->writeCatalog();
        $this->writeAgentStatus(true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testCreatePublishesImmutableCapabilityRequestAndPreparesGateIdempotently(): void
    {
        $first = $this->service->create(
            $this->owner,
            $this->sessionId,
            2,
            '1.2.0',
            self::JOB_REQUEST,
        );
        $second = $this->service->create(
            $this->owner,
            $this->sessionId,
            2,
            '1.2.0',
            self::JOB_REQUEST,
        );

        self::assertSame($first, $second);
        self::assertSame('preparing', $first['state']);
        self::assertSame(3, $first['session_revision']);
        self::assertSame(UpgradeState::Preparing, $this->gate->snapshot()->state);
        $request = $this->files->readJobRequest($first['job_id']);
        self::assertNotNull($request);
        self::assertSame(str_repeat('A', 43), $request->capability_token);
        self::assertSame('https://shop.example.com', $request->callback_base_url);
        self::assertStringNotContainsString(str_repeat('A', 43), json_encode($first, JSON_THROW_ON_ERROR));
        self::assertSame(1, json_decode((string) file_get_contents(
            $this->root . '/jobs/' . $first['job_id'] . '/audit.json',
        ), true, 32, JSON_THROW_ON_ERROR)['revision']);
    }

    public function testFailedPreconditionDoesNotBindSessionOrOpenMaintenance(): void
    {
        $this->writeAgentStatus(false);

        $this->assertFailure('UPGRADE_AGENT_NOT_READY', fn() => $this->service->create(
            $this->owner,
            $this->sessionId,
            2,
            '1.2.0',
            self::JOB_REQUEST,
        ));

        $session = $this->sessions->load();
        self::assertNotNull($session);
        self::assertNull($session['job_id']);
        self::assertSame(2, $session['revision']);
        self::assertSame(UpgradeState::Normal, $this->gate->snapshot()->state);
    }

    public function testStartDrainAndImmutableControlIntentUseCurrentSessionAndJobRevision(): void
    {
        $created = $this->service->create(
            $this->owner,
            $this->sessionId,
            2,
            '1.2.0',
            self::JOB_REQUEST,
        );
        $jobId = $created['job_id'];
        $this->gate->set($this->snapshot(UpgradeState::ReadyToDrain, 2, $jobId));
        $draining = $this->service->startDrain($this->owner, $jobId, 3, 2);
        self::assertSame(['job_id' => $jobId, 'state' => 'draining', 'revision' => 3], $draining);

        $this->writeJobStatus($jobId, 7, 'draining');
        $control = $this->service->control(
            $this->owner,
            $jobId,
            3,
            7,
            self::CONTROL_REQUEST,
            'cancel',
        );
        self::assertSame('pending', $control['status']);
        self::assertSame(
            'cancel',
            $this->files->readJobControl($jobId, 7, self::CONTROL_REQUEST)?->action,
        );
        $this->assertFailure('UPGRADE_JOB_STATE_CONFLICT', fn() => $this->service->control(
            $this->owner,
            $jobId,
            3,
            6,
            '44444444-4444-4444-8444-444444444444',
            'cancel',
        ));
    }

    public function testStatusAdvertisesOnlyControlActionsImplementedAtSafeBoundaries(): void
    {
        $created = $this->service->create(
            $this->owner,
            $this->sessionId,
            2,
            '1.2.0',
            self::JOB_REQUEST,
        );
        $jobId = $created['job_id'];
        $this->gate->set($this->snapshot(UpgradeState::FailedMaintenance, 8, $jobId));
        $this->writeJobStatus($jobId, 12, 'failed_maintenance', 'migrate', 'failed_maintenance');

        self::assertSame(['resume'], $this->service->status($jobId)['allowed_actions']);

        $this->gate->set($this->snapshot(UpgradeState::FailedPreApply, 9, $jobId));
        $this->writeJobStatus($jobId, 13, 'failed_pre_apply', 'platform_receipt', 'failed_pre_apply');
        self::assertSame([], $this->service->status($jobId)['allowed_actions']);
    }

    private function writeCatalog(): void
    {
        $this->writeAgentDocument('release-catalog.json', [
            'schema_version' => 1,
            'catalog_revision' => 1,
            'generated_at' => 1000,
            'expires_at' => 1200,
            'current_version' => '1.1.0',
            'agent_version' => '1.0.0',
            'releases' => [[
                'version' => '1.2.0',
                'compatible' => true,
            ]],
        ]);
    }

    private function writeAgentStatus(bool $ready): void
    {
        $this->writeAgentDocument('agent-status.json', [
            'schema_version' => 1,
            'agent_version' => '1.0.0',
            'mode' => 'serve',
            'pid' => 123,
            'arch' => 'amd64',
            'state' => 'ready',
            'platform_state' => 'online',
            'platform_code' => '',
            'current_job_id' => '',
            'last_seen_at' => 1099,
            'lease_until' => 1110,
            'safe_to_stop' => true,
            'production_ready' => true,
            'upgrade_ready' => $ready,
            'revision' => 1,
        ]);
    }

    private function writeJobStatus(
        string $jobId,
        int $revision,
        string $state,
        string $nextStep = 'paused',
        string $failureClass = '',
    ): void
    {
        $path = $this->root . '/jobs/' . $jobId . '/status.json';
        file_put_contents($path, json_encode([
            'schema_version' => 1,
            'job_id' => $jobId,
            'revision' => $revision,
            'state' => $state,
            'next_step' => $nextStep,
            'failure_class' => $failureClass,
            'platform_sync_pending' => false,
            'platform_receipt_confirmed' => false,
            'safe_to_stop' => false,
            'updated_at' => 1100,
        ], JSON_THROW_ON_ERROR));
        chmod($path, 0660);
    }

    /** @param array<string,mixed> $document */
    private function writeAgentDocument(string $name, array $document): void
    {
        file_put_contents($this->root . '/run/' . $name, json_encode($document, JSON_THROW_ON_ERROR));
        chmod($this->root . '/run/' . $name, 0660);
    }

    private function snapshot(UpgradeState $state, int $revision, ?string $jobId): UpgradeGateSnapshot
    {
        return new UpgradeGateSnapshot(
            $state,
            $revision,
            $jobId,
            '1.1.0',
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            0,
            1,
            1,
            1,
            str_repeat('a', 40),
            false,
            [],
            false,
            null,
            1100,
        );
    }

    /** @return array<string,callable> */
    private function statOperations(): array
    {
        return [
            'lstat' => function (string $path): array|false {
                $stat = @lstat($path);
                if ($stat === false) {
                    return false;
                }
                $stat['gid'] = self::SHARED_GID;
                $stat['uid'] = $this->expectedOwner($path, ($stat['mode'] & 0170000) === 0040000);

                return $stat;
            },
            'fstat' => function ($handle): array|false {
                $stat = fstat($handle);
                if ($stat === false) {
                    return false;
                }
                $path = (string) (stream_get_meta_data($handle)['uri'] ?? '');
                $stat['gid'] = self::SHARED_GID;
                $stat['uid'] = $this->expectedOwner($path, ($stat['mode'] & 0170000) === 0040000);

                return $stat;
            },
        ];
    }

    private function expectedOwner(string $path, bool $directory): int
    {
        if ($directory || str_ends_with($path, '/run/session-auth.json')
            || str_ends_with($path, '/run/session-auth.lock')
            || str_ends_with($path, '/run/release-catalog.json')
            || str_ends_with($path, '/run/agent-status.json')
            || str_ends_with($path, '/status.json')) {
            return self::AGENT_UID;
        }

        return self::PHP_UID;
    }

    private function assertFailure(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected ' . $message);
        } catch (\RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
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

final class JobControlTestDrain implements UpgradeDrainControl
{
    public function __construct(private JobControlTestGate $gate)
    {
    }

    public function begin(string $jobId, int $expectedRevision): UpgradeGateSnapshot
    {
        return $this->gate->compareAndSet(
            $expectedRevision,
            UpgradeState::ReadyToDrain,
            UpgradeState::Draining,
            $jobId,
        );
    }
}

final class JobControlTestGate implements UpgradeGateRepository
{
    public function __construct(private UpgradeGateSnapshot $snapshot)
    {
    }

    public function set(UpgradeGateSnapshot $snapshot): void
    {
        $this->snapshot = $snapshot;
    }

    public function snapshot(): UpgradeGateSnapshot
    {
        return $this->snapshot;
    }

    public function compareAndSet(int $expectedRevision, UpgradeState $expectedState, UpgradeState $nextState, string $jobId): UpgradeGateSnapshot
    {
        if ($this->snapshot->revision !== $expectedRevision || $this->snapshot->state !== $expectedState
            || !($this->snapshot->jobId === null && $expectedState === UpgradeState::Normal
                || $this->snapshot->jobId === $jobId)) {
            throw new \RuntimeException('conflict');
        }
        $this->snapshot = $this->copy($nextState, $expectedRevision + 1, $jobId);

        return $this->snapshot;
    }

    public function returnToNormal(int $expectedRevision, UpgradeState $terminalState, string $jobId, bool $platformSyncPending): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function advanceRuntimeFence(int $expectedRevision, UpgradeRuntimeIdentity $current, UpgradeRuntimeIdentity $target, string $jobId): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function forceMaintenance(int $expectedRevision, string $jobId, string $failureCode): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function recordActivityUncertainty(int $expectedRevision, array $taintedBoots): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function acknowledgeRuntimeRegistration(int $expectedRevision, array $runtimeRecord): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function beginActivityRecovery(int $expectedRevision, string $redisIncarnation): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function recordRetiredTaintedOwner(int $expectedRevision, string $ownerKey): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function clearActivityUncertainty(int $expectedRevision, array $requiredRoles, array $cleanRoleRecords): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    private function copy(UpgradeState $state, int $revision, ?string $jobId): UpgradeGateSnapshot
    {
        $current = $this->snapshot;

        return new UpgradeGateSnapshot(
            $state,
            $revision,
            $jobId,
            $current->requiredRuntimeVersion,
            $current->requiredDeploymentId,
            $current->requiredStorageLayoutVersion,
            $current->requiredStorageLayoutGeneration,
            $current->deploymentEpoch,
            $current->activityGeneration,
            $current->redisIncarnation,
            $current->uncertain,
            $current->taintedBoots,
            $current->platformSyncPending,
            null,
            $current->updatedAt,
        );
    }
}
