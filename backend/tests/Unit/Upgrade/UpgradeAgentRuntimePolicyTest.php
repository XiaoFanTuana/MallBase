<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\UpgradeAgentRuntimePolicy;
use app\service\upgrade\UpgradeGateRepository;
use app\service\upgrade\UpgradeGateSnapshot;
use app\service\upgrade\UpgradeOperationStore;
use app\service\upgrade\UpgradeRuntimeIdentity;
use app\service\upgrade\UpgradeRuntimeIdentityProvider;
use app\service\upgrade\UpgradeSharedFileStore;
use app\service\upgrade\UpgradeState;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UpgradeAgentRuntimePolicyTest extends TestCase
{
    private const JOB_ID = '11111111-1111-4111-8111-111111111111';

    private string $root;
    private UpgradeOperationStore $operations;
    private UpgradeRuntimeIdentity $source;
    private UpgradeRuntimeIdentity $target;

    protected function setUp(): void
    {
        $path = sys_get_temp_dir() . '/mallbase-runtime-policy-' . bin2hex(random_bytes(8));
        mkdir($path, 0750, true);
        $this->root = (string) realpath($path);
        foreach (['state', 'run', 'jobs'] as $directory) {
            mkdir($this->root . '/' . $directory, 02770);
            chmod($this->root . '/' . $directory, 02770);
        }
        $files = new UpgradeSharedFileStore(
            $this->root,
            47001,
            47002,
            47003,
            65536,
            50,
            $this->statOperations(),
        );
        $this->operations = new UpgradeOperationStore($files);
        $this->source = new UpgradeRuntimeIdentity(
            '1.2.3',
            '33333333-3333-4333-8333-333333333333',
            1,
            7,
        );
        $this->target = new UpgradeRuntimeIdentity(
            '1.2.4',
            '44444444-4444-4444-8444-444444444444',
            1,
            7,
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testAllowsOnlyTheFencedSourceBoundToTheCompletedFenceOperation(): void
    {
        $operationId = $this->completeFenceOperation();
        $policy = $this->policy($this->source);

        $principal = $policy->authorize(self::JOB_ID, 'migrations', [
            'source' => $this->source->toArray(),
            'fence_operation_id' => $operationId,
        ]);
        self::assertTrue($principal['identity']->equals($this->source));

        foreach ([
            ['source' => $this->target->toArray(), 'fence_operation_id' => $operationId],
            ['source' => $this->source->toArray(), 'fence_operation_id' => '55555555-5555-4555-8555-555555555555'],
        ] as $context) {
            try {
                $policy->authorize(self::JOB_ID, 'migrations', $context);
                self::fail('fenced source context was accepted');
            } catch (RuntimeException $exception) {
                self::assertSame('RUNTIME_IDENTITY_FENCED', $exception->getMessage());
            }
        }
    }

    public function testAllowsExactRuntimeFenceReplayButNoGenericSourceHealthAccess(): void
    {
        $revision = 8;
        $checksum = $this->fenceChecksum($revision);
        $operation = $this->operations->claim(
            self::JOB_ID,
            'runtime_fence',
            $checksum,
            'runtime-source',
            'boot-source',
            1000,
        );
        $policy = $this->policy($this->source);
        $principal = $policy->authorize(self::JOB_ID, 'runtime-fence', [
            'operation_id' => $operation['operation_id'],
            'expected_revision' => $revision,
            'source' => $this->source->toArray(),
            'target' => $this->target->toArray(),
            'attempt' => '',
        ]);
        self::assertTrue($principal['identity']->equals($this->source));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RUNTIME_IDENTITY_FENCED');
        $policy->authorize(self::JOB_ID, 'health');
    }

    public function testAllowsOnlyTheExactCompletedTransitionReplayAfterTheGateReturnedToNormal(): void
    {
        $expectedRevision = 20;
        $attempt = '';
        $checksum = 'sha256:' . hash('sha256', json_encode([
            $expectedRevision,
            'completed',
            'normal',
            false,
            $attempt,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $operation = $this->operations->claim(
            self::JOB_ID,
            'state_completed_to_normal',
            $checksum,
            'runtime-target',
            'boot-target',
            1000,
        );
        $this->operations->complete($operation['operation_id'], 'boot-target', [
            'state' => 'normal',
            'revision' => 21,
            'job_id' => null,
            'platform_sync_pending' => false,
            'runtime_identity' => $this->target->toArray(),
        ], 1001);
        $policy = $this->policyForSnapshot($this->target, new UpgradeGateSnapshot(
            UpgradeState::Normal,
            21,
            null,
            $this->target->version,
            $this->target->deploymentId,
            $this->target->storageLayoutVersion,
            $this->target->storageLayoutGeneration,
            2,
            1,
            str_repeat('a', 40),
            false,
            [],
            false,
            null,
            1001,
        ));
        $context = [
            'operation_id' => $operation['operation_id'],
            'expected_revision' => $expectedRevision,
            'expected_state' => 'completed',
            'next_state' => 'normal',
            'platform_sync_pending' => false,
            'source' => $this->source->toArray(),
            'fence_operation_id' => '55555555-5555-4555-8555-555555555555',
            'attempt' => $attempt,
        ];

        $principal = $policy->authorize(self::JOB_ID, 'state-transition', $context);
        self::assertSame(UpgradeState::Normal, $principal['gate']->state);

        $context['platform_sync_pending'] = true;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RUNTIME_IDENTITY_FENCED');
        $policy->authorize(self::JOB_ID, 'state-transition', $context);
    }

    private function completeFenceOperation(): string
    {
        $operation = $this->operations->claim(
            self::JOB_ID,
            'runtime_fence',
            $this->fenceChecksum(8),
            'runtime-source',
            'boot-source',
            1000,
        );
        $completed = $this->operations->complete($operation['operation_id'], 'boot-source', [
            'revision' => 9,
            'deployment_epoch' => 2,
            'source_runtime_identity' => $this->source->toArray(),
            'runtime_identity' => $this->target->toArray(),
        ], 1001);

        return $completed['operation_id'];
    }

    private function fenceChecksum(int $revision): string
    {
        return 'sha256:' . hash('sha256', json_encode([
            $revision,
            $this->source->toArray(),
            $this->target->toArray(),
            '',
        ], JSON_THROW_ON_ERROR));
    }

    private function policy(UpgradeRuntimeIdentity $identity): UpgradeAgentRuntimePolicy
    {
        return $this->policyForSnapshot($identity, new UpgradeGateSnapshot(
            UpgradeState::Applying,
            9,
            self::JOB_ID,
            $this->target->version,
            $this->target->deploymentId,
            $this->target->storageLayoutVersion,
            $this->target->storageLayoutGeneration,
            2,
            1,
            str_repeat('a', 40),
            false,
            [],
            false,
            null,
            1000,
        ));
    }

    private function policyForSnapshot(
        UpgradeRuntimeIdentity $identity,
        UpgradeGateSnapshot $snapshot,
    ): UpgradeAgentRuntimePolicy {
        $gate = $this->createStub(UpgradeGateRepository::class);
        $gate->method('snapshot')->willReturn($snapshot);
        $loader = $this->createStub(UpgradeRuntimeIdentityProvider::class);
        $loader->method('load')->willReturn($identity);

        return new UpgradeAgentRuntimePolicy($gate, $loader, $this->operations);
    }

    /** @return array<string,callable> */
    private function statOperations(): array
    {
        return [
            'lstat' => static function (string $path): array|false {
                $stat = @lstat($path);
                if ($stat !== false) {
                    $stat['gid'] = 47002;
                    $stat['uid'] = ($stat['mode'] & 0170000) === 0040000 ? 47001 : 47003;
                }
                return $stat;
            },
            'fstat' => static function ($handle): array|false {
                $stat = fstat($handle);
                if ($stat !== false) {
                    $stat['gid'] = 47002;
                    $stat['uid'] = ($stat['mode'] & 0170000) === 0040000 ? 47001 : 47003;
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
