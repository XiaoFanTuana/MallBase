<?php

declare(strict_types=1);

namespace app\service\upgrade;

use RuntimeException;
use Throwable;

/** Closes the PHP-side platform outbox after the Agent confirms the terminal receipt. */
final readonly class UpgradePlatformReceiptService
{
    public function __construct(
        private UpgradeOperationStore $operations,
        private UpgradeGateRepository $gate,
    ) {
    }

    /** @return array<string,mixed> */
    public function confirm(
        string $jobId,
        int $expectedRevision,
        UpgradeState $terminalState,
        string $normalTransitionOperationId,
        string $attempt,
        string $runtimeId,
        string $bootId,
        int $now,
    ): array {
        $repository = $this->gate;
        if (!$repository instanceof UpgradePlatformSyncGateRepository
            || !in_array($terminalState, [UpgradeState::Cancelled, UpgradeState::FailedPreApply], true)) {
            throw new RuntimeException('UPGRADE_PLATFORM_RECEIPT_INVALID');
        }
        $attempt = UpgradeOperationAttempt::normalize($attempt);
        $this->assertNormalTransition(
            $jobId,
            $expectedRevision,
            $terminalState,
            $normalTransitionOperationId,
            $attempt,
        );
        $action = UpgradeOperationAttempt::action('platform_receipt_' . $terminalState->value, $attempt);
        $checksum = $this->checksum(
            $expectedRevision,
            $terminalState,
            $normalTransitionOperationId,
            $attempt,
        );
        $operationId = $this->operations->operationId($jobId, $action, $checksum);
        $existing = $this->operations->get($operationId);
        if (is_array($existing)) {
            $this->operations->assertMatches($existing, $jobId, $action, $checksum);
            if ($existing['state'] !== 'running') {
                return $existing;
            }
        }
        $operation = $this->operations->claim(
            $jobId,
            $action,
            $checksum,
            $runtimeId,
            $bootId,
            $now,
        );
        if ($operation['state'] !== 'running') {
            return $operation;
        }
        try {
            $current = $repository->snapshot();
            if ($current->state === UpgradeState::Normal && $current->jobId === null
                && !$current->platformSyncPending && $current->revision === $expectedRevision + 1) {
                return $this->operations->complete(
                    $operationId,
                    $bootId,
                    $this->projection($current),
                    $now,
                );
            }
            $next = $repository->confirmPlatformSync($expectedRevision);

            return $this->operations->complete(
                $operationId,
                $bootId,
                $this->projection($next),
                $now,
            );
        } catch (Throwable) {
            return $this->operations->fail(
                $operationId,
                $bootId,
                'UPGRADE_PLATFORM_RECEIPT_FAILED',
                $now,
            );
        }
    }

    public function operationId(
        string $jobId,
        int $expectedRevision,
        UpgradeState $terminalState,
        string $normalTransitionOperationId,
        string $attempt,
    ): string {
        $action = UpgradeOperationAttempt::action('platform_receipt_' . $terminalState->value, $attempt);

        return $this->operations->operationId(
            $jobId,
            $action,
            $this->checksum(
                $expectedRevision,
                $terminalState,
                $normalTransitionOperationId,
                $attempt,
            ),
        );
    }

    private function assertNormalTransition(
        string $jobId,
        int $expectedRevision,
        UpgradeState $terminalState,
        string $operationId,
        string $attempt,
    ): void {
        $operation = $this->operations->get(strtolower($operationId));
        $action = UpgradeOperationAttempt::action(
            'state_' . $terminalState->value . '_to_normal',
            $attempt,
        );
        if (!is_array($operation) || ($operation['job_id'] ?? null) !== $jobId
            || ($operation['action'] ?? null) !== $action || ($operation['state'] ?? null) !== 'completed') {
            throw new RuntimeException('UPGRADE_PLATFORM_RECEIPT_INVALID');
        }
        $result = $operation['result'] ?? null;
        $gate = $this->gate->snapshot();
        if (!is_array($result) || ($result['state'] ?? null) !== UpgradeState::Normal->value
            || ($result['revision'] ?? null) !== $expectedRevision
            || !array_key_exists('job_id', $result) || $result['job_id'] !== null
            || ($result['platform_sync_pending'] ?? null) !== true
            || !$this->sameIdentity($result['runtime_identity'] ?? null, $gate->runtimeIdentity())) {
            throw new RuntimeException('UPGRADE_PLATFORM_RECEIPT_INVALID');
        }
    }

    private function checksum(
        int $expectedRevision,
        UpgradeState $terminalState,
        string $normalTransitionOperationId,
        string $attempt,
    ): string {
        return 'sha256:' . hash('sha256', json_encode([
            $expectedRevision,
            $terminalState->value,
            strtolower($normalTransitionOperationId),
            UpgradeOperationAttempt::normalize($attempt),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string,mixed> */
    private function projection(UpgradeGateSnapshot $snapshot): array
    {
        return [
            'state' => $snapshot->state->value,
            'revision' => $snapshot->revision,
            'job_id' => $snapshot->jobId,
            'platform_sync_pending' => $snapshot->platformSyncPending,
            'runtime_identity' => $snapshot->runtimeIdentity()->toArray(),
        ];
    }

    private function sameIdentity(mixed $value, UpgradeRuntimeIdentity $expected): bool
    {
        if (!is_array($value) || array_keys($value) !== [
            'version', 'deployment_id', 'storage_layout_version', 'storage_layout_generation',
        ] || !is_string($value['version']) || !is_string($value['deployment_id'])
            || !is_int($value['storage_layout_version']) || !is_int($value['storage_layout_generation'])) {
            return false;
        }
        try {
            $actual = new UpgradeRuntimeIdentity(
                $value['version'],
                strtolower($value['deployment_id']),
                $value['storage_layout_version'],
                $value['storage_layout_generation'],
            );
        } catch (Throwable) {
            return false;
        }

        return $actual->equals($expected);
    }
}
