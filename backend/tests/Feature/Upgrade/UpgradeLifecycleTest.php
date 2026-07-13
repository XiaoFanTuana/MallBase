<?php

declare(strict_types=1);

namespace Tests\Feature\Upgrade;

use app\service\admin\order\RefundOrderAdminService;
use app\service\client\payment\WechatPaymentResultService;
use app\service\client\payment\WechatPayClient;
use app\service\client\payment\WechatPayFactory;
use app\service\upgrade\FileUpgradeDrainCheckpointRepository;
use app\service\upgrade\QueueInspector;
use app\service\upgrade\ReconciliationResult;
use app\service\upgrade\UpgradeActivityLease;
use app\service\upgrade\UpgradeActivitySnapshot;
use app\service\upgrade\UpgradeActivityTracker;
use app\service\upgrade\UpgradeAgentStateTransitionService;
use app\service\upgrade\UpgradeDrainCoordinator;
use app\service\upgrade\UpgradeDrainGateRepository;
use app\service\upgrade\UpgradeGateRepository;
use app\service\upgrade\UpgradeGateSnapshot;
use app\service\upgrade\UpgradeOperationStore;
use app\service\upgrade\UpgradePaymentReconciliationService;
use app\service\upgrade\UpgradePaymentReconciliationStore;
use app\service\upgrade\UpgradeQueueInventory;
use app\service\upgrade\UpgradeRuntimeFenceService;
use app\service\upgrade\UpgradeRuntimeIdentity;
use app\service\upgrade\UpgradeRuntimeInstance;
use app\service\upgrade\UpgradeRuntimeOwnerLiveness;
use app\service\upgrade\UpgradeSharedFileStore;
use app\service\upgrade\UpgradeState;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UpgradeLifecycleTest extends TestCase
{
    private const JOB_ID = '33333333-3333-4333-8333-333333333333';
    private const RUNTIME_ID = 'agent-runtime-1';
    private const BOOT_ID = 'agent-boot-1';

    private string $root;
    private string $reconciliationRoot;
    private UpgradeSharedFileStore $files;
    private UpgradeOperationStore $operations;
    private LifecycleGate $gate;
    private int $now = 1000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mallbase-upgrade-lifecycle-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0750, true);
        foreach (['state', 'run', 'jobs'] as $directory) {
            mkdir($this->root . '/' . $directory, 02770);
            chmod($this->root . '/' . $directory, 02770);
        }
        $this->reconciliationRoot = $this->root . '/agent-private-jobs';
        mkdir($this->reconciliationRoot, 0700);
        $this->files = new UpgradeSharedFileStore(
            $this->root,
            38001,
            38002,
            38003,
            1024 * 1024,
            50,
            $this->statOperations(),
        );
        $this->operations = new UpgradeOperationStore($this->files);
        $this->gate = new LifecycleGate($this->snapshot(
            UpgradeState::Preparing,
            1,
            $this->sourceIdentity(),
            false,
        ));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testRealPhpServicesCompleteDrainFenceReconciliationAndNormalLifecycle(): void
    {
        $transitions = new UpgradeAgentStateTransitionService($this->operations, $this->gate);
        $activity = new LifecycleActivityTracker();
        $drain = new UpgradeDrainCoordinator(
            $this->gate,
            $activity,
            new LifecycleQueueInspector(),
            new FileUpgradeDrainCheckpointRepository($this->files),
            fn(): int => $this->now,
            static function (int $microseconds): void {
                unset($microseconds);
            },
            1,
        );

        $ready = $this->transition(
            $transitions,
            1,
            UpgradeState::Preparing,
            UpgradeState::ReadyToDrain,
        );
        self::assertSame('ready_to_drain', $ready['result']['state']);

        $draining = $drain->begin(self::JOB_ID, 2);
        self::assertSame(UpgradeState::Draining, $draining->state);
        $paused = $drain->tryPause(3, false);
        self::assertSame(UpgradeState::Paused, $paused->state);
        self::assertTrue($drain->inspect()->safe);
        $backingUp = $drain->confirmAndEnterBackingUp(4);
        self::assertSame(UpgradeState::BackingUp, $backingUp->state);

        $fence = (new UpgradeRuntimeFenceService($this->operations, $this->gate))->advance(
            self::JOB_ID,
            5,
            $this->sourceIdentity(),
            $this->targetIdentity(),
            self::RUNTIME_ID,
            self::BOOT_ID,
            ++$this->now,
        );
        self::assertSame('completed', $fence['state']);
        self::assertSame($this->targetIdentity()->toArray(), $fence['result']['runtime_identity']);
        self::assertSame(2, $fence['result']['deployment_epoch']);

        $this->transition($transitions, 6, UpgradeState::BackingUp, UpgradeState::Applying);
        $this->transition($transitions, 7, UpgradeState::Applying, UpgradeState::AwaitingDeployment);
        $this->transition($transitions, 8, UpgradeState::AwaitingDeployment, UpgradeState::Verifying);
        $this->transition($transitions, 9, UpgradeState::Verifying, UpgradeState::Reconciling);

        $reconciliationStore = new UpgradePaymentReconciliationStore($this->reconciliationRoot);
        $reconciliation = new LifecyclePaymentReconciliationService(
            $reconciliationStore,
            $activity,
            $this->createStub(WechatPayFactory::class),
            $this->createStub(WechatPayClient::class),
            $this->createStub(WechatPaymentResultService::class),
            $this->createStub(RefundOrderAdminService::class),
            fn(): int => $this->now,
            100,
        );
        $waiting = $reconciliation->run(self::JOB_ID, 900, 990);
        self::assertSame(ReconciliationResult::WAITING_QUIET_WINDOW, $waiting->status);
        self::assertSame(60, $waiting->quietRemainingSeconds);

        $this->now = 1010;
        $reconciliationStore->recordCallback(
            self::JOB_ID,
            'payment',
            'transaction-that-must-not-be-persisted',
            $this->now,
        );
        $this->now = 1060;
        $resetWindow = $reconciliation->run(self::JOB_ID, 900, 990);
        self::assertSame(60, $resetWindow->quietRemainingSeconds);
        $this->now = 1120;
        $reconciled = $reconciliation->run(self::JOB_ID, 900, 990);
        self::assertTrue($reconciled->complete());

        $completed = $this->transition(
            $transitions,
            10,
            UpgradeState::Reconciling,
            UpgradeState::Completed,
        );
        self::assertSame('completed', $completed['result']['state']);
        $normal = $transitions->transition(
            self::JOB_ID,
            11,
            UpgradeState::Completed,
            UpgradeState::Normal,
            true,
            self::RUNTIME_ID,
            self::BOOT_ID,
            ++$this->now,
        );

        self::assertSame([
            'preparing',
            'ready_to_drain',
            'draining',
            'paused',
            'backing_up',
            'applying',
            'awaiting_deployment',
            'verifying',
            'reconciling',
            'completed',
            'normal',
        ], $this->gate->stateHistory);
        self::assertSame('normal', $normal['result']['state']);
        self::assertNull($normal['result']['job_id']);
        self::assertTrue($normal['result']['platform_sync_pending']);
        self::assertFalse($this->gate->snapshot()->state->blocksBusinessTraffic());
        self::assertSame($this->targetIdentity()->toArray(), $this->gate->snapshot()->runtimeIdentity()->toArray());

        $drainCheckpoint = $this->files->readDrainCheckpoint(self::JOB_ID);
        self::assertNotNull($drainCheckpoint);
        self::assertSame(3, $drainCheckpoint->draining_gate_revision);
        self::assertSame(3, $drainCheckpoint->deferred_gate_revision);
        self::assertSame([], $drainCheckpoint->deferred_jobs);

        $reconciliationCheckpoint = $reconciliationStore->load(self::JOB_ID);
        self::assertSame('completed', $reconciliationCheckpoint['checkpoint']['phase']);
        self::assertSame(1, $reconciliationCheckpoint['callback_revision']);
        self::assertSame(['payment' => 1, 'refund' => 0], $reconciliationCheckpoint['callback_counts']);
        $ledgerBytes = file_get_contents(
            $this->reconciliationRoot . '/' . self::JOB_ID . '/payment-reconciliation.json',
        );
        self::assertIsString($ledgerBytes);
        self::assertStringNotContainsString('transaction-that-must-not-be-persisted', $ledgerBytes);

        $operationDocument = $this->files->readJson('upgrade_operations');
        self::assertNotNull($operationDocument);
        $operations = get_object_vars($operationDocument->operations);
        self::assertCount(8, $operations);
        foreach ($operations as $operation) {
            self::assertSame('completed', $operation->state);
        }
    }

    /** @return array<string,mixed> */
    private function transition(
        UpgradeAgentStateTransitionService $service,
        int $revision,
        UpgradeState $from,
        UpgradeState $to,
    ): array {
        return $service->transition(
            self::JOB_ID,
            $revision,
            $from,
            $to,
            false,
            self::RUNTIME_ID,
            self::BOOT_ID,
            ++$this->now,
        );
    }

    private function sourceIdentity(): UpgradeRuntimeIdentity
    {
        return new UpgradeRuntimeIdentity(
            '1.1.0',
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            1,
            1,
        );
    }

    private function targetIdentity(): UpgradeRuntimeIdentity
    {
        return new UpgradeRuntimeIdentity(
            '1.2.0',
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            2,
            2,
        );
    }

    private function snapshot(
        UpgradeState $state,
        int $revision,
        UpgradeRuntimeIdentity $identity,
        bool $platformSyncPending,
    ): UpgradeGateSnapshot {
        return new UpgradeGateSnapshot(
            $state,
            $revision,
            $state === UpgradeState::Normal ? null : self::JOB_ID,
            $identity->version,
            $identity->deploymentId,
            $identity->storageLayoutVersion,
            $identity->storageLayoutGeneration,
            1,
            1,
            str_repeat('a', 40),
            false,
            [],
            $platformSyncPending,
            null,
            $this->now,
        );
    }

    /** @return array<string,callable> */
    private function statOperations(): array
    {
        return [
            'lstat' => static function (string $path): array|false {
                $stat = @lstat($path);
                if ($stat !== false) {
                    $stat['gid'] = 38002;
                    $stat['uid'] = ($stat['mode'] & 0170000) === 0040000 ? 38001 : 38003;
                }

                return $stat;
            },
            'fstat' => static function ($handle): array|false {
                $stat = fstat($handle);
                if ($stat !== false) {
                    $stat['gid'] = 38002;
                    $stat['uid'] = ($stat['mode'] & 0170000) === 0040000 ? 38001 : 38003;
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

final class LifecycleGate implements UpgradeGateRepository, UpgradeDrainGateRepository
{
    /** @var list<string> */
    public array $stateHistory;

    public function __construct(private UpgradeGateSnapshot $current)
    {
        $this->stateHistory = [$current->state->value];
    }

    public function snapshot(): UpgradeGateSnapshot
    {
        return $this->current;
    }

    public function compareAndSet(
        int $expectedRevision,
        UpgradeState $expectedState,
        UpgradeState $nextState,
        string $jobId,
    ): UpgradeGateSnapshot {
        $this->assertCurrent($expectedRevision, $expectedState, $jobId);
        $this->current = $this->next(
            $nextState,
            $expectedRevision + 1,
            $jobId,
            $this->current->runtimeIdentity(),
            $this->current->platformSyncPending,
            $this->current->deploymentEpoch,
        );
        $this->stateHistory[] = $nextState->value;

        return $this->current;
    }

    public function enterBackingUpAfterDrain(int $expectedRevision, string $jobId): UpgradeGateSnapshot
    {
        $this->assertCurrent($expectedRevision, UpgradeState::Paused, $jobId);
        $this->current = $this->next(
            UpgradeState::BackingUp,
            $expectedRevision + 1,
            $jobId,
            $this->current->runtimeIdentity(),
            false,
            $this->current->deploymentEpoch,
        );
        $this->stateHistory[] = UpgradeState::BackingUp->value;

        return $this->current;
    }

    public function returnToNormal(
        int $expectedRevision,
        UpgradeState $terminalState,
        string $jobId,
        bool $platformSyncPending,
    ): UpgradeGateSnapshot {
        $this->assertCurrent($expectedRevision, $terminalState, $jobId);
        $this->current = $this->next(
            UpgradeState::Normal,
            $expectedRevision + 1,
            null,
            $this->current->runtimeIdentity(),
            $platformSyncPending,
            $this->current->deploymentEpoch,
        );
        $this->stateHistory[] = UpgradeState::Normal->value;

        return $this->current;
    }

    public function advanceRuntimeFence(
        int $expectedRevision,
        UpgradeRuntimeIdentity $current,
        UpgradeRuntimeIdentity $target,
        string $jobId,
    ): UpgradeGateSnapshot {
        $this->assertCurrent($expectedRevision, UpgradeState::BackingUp, $jobId);
        if (!$this->current->acceptsRuntime($current)) {
            throw new RuntimeException('UPGRADE_RUNTIME_FENCE_SOURCE_MISMATCH');
        }
        $this->current = $this->next(
            $this->current->state,
            $expectedRevision + 1,
            $jobId,
            $target,
            false,
            $this->current->deploymentEpoch + 1,
        );

        return $this->current;
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

    private function assertCurrent(int $revision, UpgradeState $state, string $jobId): void
    {
        if ($this->current->revision !== $revision || $this->current->state !== $state
            || $this->current->jobId !== $jobId) {
            throw new RuntimeException('UPGRADE_LIFECYCLE_STATE_CONFLICT');
        }
    }

    private function next(
        UpgradeState $state,
        int $revision,
        ?string $jobId,
        UpgradeRuntimeIdentity $identity,
        bool $platformSyncPending,
        int $deploymentEpoch,
    ): UpgradeGateSnapshot {
        return new UpgradeGateSnapshot(
            $state,
            $revision,
            $jobId,
            $identity->version,
            $identity->deploymentId,
            $identity->storageLayoutVersion,
            $identity->storageLayoutGeneration,
            $deploymentEpoch,
            $this->current->activityGeneration,
            $this->current->redisIncarnation,
            false,
            [],
            $platformSyncPending,
            null,
            $this->current->updatedAt + 1,
        );
    }
}

final class LifecycleQueueInspector implements QueueInspector
{
    public function inventory(): UpgradeQueueInventory
    {
        return new UpgradeQueueInventory([], [], []);
    }
}

final class LifecycleActivityTracker implements UpgradeActivityTracker
{
    public function tryBeginHttp(string $requestId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease { return null; }
    public function tryBeginExternalCallback(string $requestId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease { return null; }
    public function tryBeginCron(string $taskId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease { return null; }
    public function beginQueuePop(string $workerId, string $connectorType, array $queues, string $executionAttemptId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease { return null; }
    public function bindQueueJob(UpgradeActivityLease $popLease, string $connection, string $queue, string $jobId): UpgradeActivityLease { return $popLease; }
    public function snapshot(): UpgradeActivitySnapshot { return new UpgradeActivitySnapshot(0, 0, 0, 0, 0, false); }
    public function heartbeatWorker(string $workerId, string $connectorType, array $queues, UpgradeRuntimeInstance $owner, int $ttl): void {}
    public function ackPaused(string $workerId, UpgradeRuntimeInstance $owner, int $revision, int $ttl): void {}
    public function liveWorkers(): array { return []; }
    public function reconcileQueueLeases(UpgradeQueueInventory $inventory, UpgradeRuntimeOwnerLiveness $owners): void {}
    public function reconcileOrphanActivityLeases(UpgradeRuntimeOwnerLiveness $owners): void {}
}

final class LifecyclePaymentReconciliationService extends UpgradePaymentReconciliationService
{
    protected function paymentCandidates(int $cursor, int $windowStart, int $cutoff, int $limit): array
    {
        return [];
    }

    protected function refundCandidates(int $cursor, int $windowStart, int $cutoff, int $limit): array
    {
        return [];
    }
}
