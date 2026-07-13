<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\UpgradeGateRepository;
use app\service\upgrade\UpgradeGateSnapshot;
use app\service\upgrade\UpgradeOperationStore;
use app\service\upgrade\UpgradePlatformReceiptService;
use app\service\upgrade\UpgradePlatformSyncGateRepository;
use app\service\upgrade\UpgradeRuntimeIdentity;
use app\service\upgrade\UpgradeSharedFileStore;
use app\service\upgrade\UpgradeState;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UpgradePlatformReceiptServiceTest extends TestCase
{
    private const JOB_ID = '11111111-1111-4111-8111-111111111111';

    private string $root;
    private UpgradeOperationStore $operations;
    private PlatformReceiptGate $gate;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mallbase-platform-receipt-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0750, true);
        foreach (['state', 'run'] as $directory) {
            mkdir($this->root . '/' . $directory, 02770);
            chmod($this->root . '/' . $directory, 02770);
        }
        $files = new UpgradeSharedFileStore(
            $this->root,
            49001,
            49002,
            49003,
            65536,
            50,
            $this->statOperations(),
        );
        $this->operations = new UpgradeOperationStore($files);
        $this->gate = new PlatformReceiptGate($this->snapshot(11, true));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testConfirmedPlatformReceiptDurablyClearsThePendingGateFlagAndReplays(): void
    {
        $attempt = 'aaaaaaaaaaaa';
        $normalOperationId = $this->completeNormalTransition($attempt);
        $service = new UpgradePlatformReceiptService($this->operations, $this->gate);
        $operationId = $service->operationId(
            self::JOB_ID,
            11,
            UpgradeState::Cancelled,
            $normalOperationId,
            $attempt,
        );

        $first = $service->confirm(
            self::JOB_ID,
            11,
            UpgradeState::Cancelled,
            $normalOperationId,
            $attempt,
            'runtime-http-1',
            'boot-http-1',
            1002,
        );
        $replay = $service->confirm(
            self::JOB_ID,
            11,
            UpgradeState::Cancelled,
            $normalOperationId,
            $attempt,
            'runtime-http-2',
            'boot-http-2',
            1003,
        );

        self::assertSame($operationId, $first['operation_id']);
        self::assertSame('completed', $first['state']);
        self::assertSame('normal', $first['result']['state']);
        self::assertSame(12, $first['result']['revision']);
        self::assertFalse($first['result']['platform_sync_pending']);
        self::assertSame($first, $replay);
        self::assertSame(1, $this->gate->confirmationCount);
    }

    public function testReceiptCannotClearTheFlagWithoutTheExactNormalTransitionProof(): void
    {
        $service = new UpgradePlatformReceiptService($this->operations, $this->gate);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UPGRADE_PLATFORM_RECEIPT_INVALID');
        $service->confirm(
            self::JOB_ID,
            11,
            UpgradeState::Cancelled,
            '55555555-5555-4555-8555-555555555555',
            'aaaaaaaaaaaa',
            'runtime-http-1',
            'boot-http-1',
            1002,
        );
    }

    private function completeNormalTransition(string $attempt): string
    {
        $checksum = 'sha256:' . hash('sha256', json_encode([
            10,
            'cancelled',
            'normal',
            true,
            $attempt,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $operation = $this->operations->claim(
            self::JOB_ID,
            'state_cancelled_to_normal_r_' . $attempt,
            $checksum,
            'runtime-http-1',
            'boot-http-1',
            1000,
        );
        $completed = $this->operations->complete($operation['operation_id'], 'boot-http-1', [
            'state' => 'normal',
            'revision' => 11,
            'job_id' => null,
            'platform_sync_pending' => true,
            'runtime_identity' => $this->identity()->toArray(),
        ], 1001);

        return $completed['operation_id'];
    }

    private function snapshot(int $revision, bool $pending): UpgradeGateSnapshot
    {
        $identity = $this->identity();

        return new UpgradeGateSnapshot(
            UpgradeState::Normal,
            $revision,
            null,
            $identity->version,
            $identity->deploymentId,
            $identity->storageLayoutVersion,
            $identity->storageLayoutGeneration,
            1,
            1,
            str_repeat('a', 40),
            false,
            [],
            $pending,
            null,
            1000,
        );
    }

    private function identity(): UpgradeRuntimeIdentity
    {
        return new UpgradeRuntimeIdentity(
            '1.2.3',
            '33333333-3333-4333-8333-333333333333',
            1,
            7,
        );
    }

    /** @return array<string,callable> */
    private function statOperations(): array
    {
        return [
            'lstat' => static function (string $path): array|false {
                $stat = @lstat($path);
                if ($stat !== false) {
                    $stat['gid'] = 49002;
                    $stat['uid'] = ($stat['mode'] & 0170000) === 0040000 ? 49001 : 49003;
                }

                return $stat;
            },
            'fstat' => static function ($handle): array|false {
                $stat = fstat($handle);
                if ($stat !== false) {
                    $stat['gid'] = 49002;
                    $stat['uid'] = ($stat['mode'] & 0170000) === 0040000 ? 49001 : 49003;
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

final class PlatformReceiptGate implements UpgradeGateRepository, UpgradePlatformSyncGateRepository
{
    public int $confirmationCount = 0;

    public function __construct(private UpgradeGateSnapshot $current)
    {
    }

    public function snapshot(): UpgradeGateSnapshot
    {
        return $this->current;
    }

    public function confirmPlatformSync(int $expectedRevision): UpgradeGateSnapshot
    {
        if ($this->current->state !== UpgradeState::Normal || $this->current->jobId !== null
            || !$this->current->platformSyncPending || $this->current->revision !== $expectedRevision) {
            throw new RuntimeException('UPGRADE_STATE_CONFLICT');
        }
        $this->confirmationCount++;
        $this->current = new UpgradeGateSnapshot(
            UpgradeState::Normal,
            $expectedRevision + 1,
            null,
            $this->current->requiredRuntimeVersion,
            $this->current->requiredDeploymentId,
            $this->current->requiredStorageLayoutVersion,
            $this->current->requiredStorageLayoutGeneration,
            $this->current->deploymentEpoch,
            $this->current->activityGeneration,
            $this->current->redisIncarnation,
            $this->current->uncertain,
            $this->current->taintedBoots,
            false,
            null,
            $this->current->updatedAt + 1,
        );

        return $this->current;
    }

    public function compareAndSet(int $expectedRevision, UpgradeState $expectedState, UpgradeState $nextState, string $jobId): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
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
}
