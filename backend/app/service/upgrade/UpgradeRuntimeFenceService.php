<?php

declare(strict_types=1);

namespace app\service\upgrade;

use RuntimeException;
use Throwable;

/** Idempotently advances the four-field immutable runtime fence. */
final readonly class UpgradeRuntimeFenceService
{
    public function __construct(
        private UpgradeOperationStore $operations,
        private UpgradeGateRepository $gate,
    ) {
    }

    /** @return array<string,mixed> */
    public function advance(
        string $jobId,
        int $expectedRevision,
        UpgradeRuntimeIdentity $source,
        UpgradeRuntimeIdentity $target,
        string $runtimeId,
        string $bootId,
        int $now,
        string $attempt = '',
    ): array {
        $action = UpgradeOperationAttempt::action('runtime_fence', $attempt);
        $input = 'sha256:' . hash('sha256', json_encode([
            $expectedRevision, $source->toArray(), $target->toArray(), UpgradeOperationAttempt::normalize($attempt),
        ], JSON_THROW_ON_ERROR));
        $operationId = $this->operations->operationId($jobId, $action, $input);
        $existing = $this->operations->get($operationId);
        if (is_array($existing)) {
            $this->operations->assertMatches($existing, $jobId, $action, $input);
        }
        if (is_array($existing) && ($existing['state'] !== 'running'
            || $existing['heartbeat_at'] + 15 >= $now)) {
            return $this->publicResult($existing);
        }
        $operation = $this->operations->claim(
            $jobId,
            $action,
            $input,
            $runtimeId,
            $bootId,
            $now,
            is_array($existing),
        );
        if ($operation['state'] !== 'running') {
            return $this->publicResult($operation);
        }
        try {
            $snapshot = $this->gate->snapshot();
            if ($snapshot->jobId === $jobId && $snapshot->acceptsRuntime($target)
                && $snapshot->revision === $expectedRevision + 1) {
                return $this->publicResult($this->operations->complete(
                    $operation['operation_id'],
                    $bootId,
                    $this->projection($snapshot, $source),
                    $now,
                ));
            }
            $snapshot = $this->gate->advanceRuntimeFence(
                $expectedRevision,
                $source,
                $target,
                $jobId,
            );

            return $this->publicResult($this->operations->complete(
                $operation['operation_id'],
                $bootId,
                $this->projection($snapshot, $source),
                $now,
            ));
        } catch (Throwable $exception) {
            try {
                $failed = $this->operations->fail(
                    $operation['operation_id'],
                    $bootId,
                    'UPGRADE_RUNTIME_FENCE_FAILED',
                    $now,
                    true,
                );

                return $this->publicResult($failed);
            } catch (Throwable) {
                throw new RuntimeException('UPGRADE_RUNTIME_FENCE_RECOVERY_BLOCKED', 0, $exception);
            }
        }
    }

    /** @return array<string,mixed> */
    private function projection(UpgradeGateSnapshot $snapshot, UpgradeRuntimeIdentity $source): array
    {
        return [
            'revision' => $snapshot->revision,
            'deployment_epoch' => $snapshot->deploymentEpoch,
            'source_runtime_identity' => $source->toArray(),
            'runtime_identity' => $snapshot->runtimeIdentity()->toArray(),
        ];
    }

    /** @param array<string,mixed> $operation @return array<string,mixed> */
    private function publicResult(array $operation): array
    {
        return [
            'operation_id' => $operation['operation_id'],
            'state' => $operation['state'],
            'result' => $operation['result'],
            'error_code' => $operation['error_code'],
            'revision' => $operation['revision'],
        ];
    }
}
