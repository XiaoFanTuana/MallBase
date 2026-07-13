<?php

declare(strict_types=1);

namespace app\service\upgrade;

use RuntimeException;

/** Exact action/state/runtime allowlist evaluated before Agent API side effects. */
final readonly class UpgradeAgentRuntimePolicy
{
    /** @var array<string,list<UpgradeState>> */
    private const ACTION_STATES = [
        'health' => [
            UpgradeState::Preparing, UpgradeState::ReadyToDrain, UpgradeState::Draining,
            UpgradeState::Paused, UpgradeState::BackingUp, UpgradeState::Applying,
            UpgradeState::AwaitingDeployment, UpgradeState::Verifying, UpgradeState::Reconciling,
            UpgradeState::Completed, UpgradeState::FailedMaintenance,
        ],
        'writable-surface-audit' => [
            UpgradeState::Preparing,
            UpgradeState::BackingUp,
            UpgradeState::AwaitingDeployment,
        ],
        'state-transition' => [
            UpgradeState::Preparing, UpgradeState::ReadyToDrain, UpgradeState::Draining,
            UpgradeState::Paused, UpgradeState::BackingUp, UpgradeState::Applying,
            UpgradeState::AwaitingDeployment, UpgradeState::Verifying,
            UpgradeState::Reconciling, UpgradeState::Completed,
            UpgradeState::Cancelled, UpgradeState::FailedPreApply,
        ],
        'confirm-paused' => [UpgradeState::Draining, UpgradeState::Paused],
        'backup-database' => [UpgradeState::BackingUp],
        'migrations' => [UpgradeState::Applying],
        'runtime-fence' => [UpgradeState::Applying, UpgradeState::AwaitingDeployment, UpgradeState::FailedMaintenance],
        'resume' => [
            UpgradeState::FailedMaintenance, UpgradeState::BackingUp, UpgradeState::Applying,
            UpgradeState::AwaitingDeployment, UpgradeState::Verifying, UpgradeState::Reconciling,
        ],
        'cancel' => [
            UpgradeState::Preparing, UpgradeState::ReadyToDrain, UpgradeState::Draining,
            UpgradeState::Paused, UpgradeState::Cancelled,
        ],
        'platform-receipt' => [UpgradeState::Normal],
        'persistent-state-verification' => [UpgradeState::AwaitingDeployment, UpgradeState::Verifying],
        'reconciliation' => [UpgradeState::Verifying, UpgradeState::Reconciling],
        'operation' => [
            UpgradeState::BackingUp, UpgradeState::Applying, UpgradeState::AwaitingDeployment,
            UpgradeState::Verifying, UpgradeState::Reconciling, UpgradeState::FailedMaintenance,
        ],
    ];

    public function __construct(
        private UpgradeGateRepository $gate,
        private UpgradeRuntimeIdentityProvider $identity,
        private UpgradeOperationStore $operations,
    ) {
    }

    /** @return array{gate:UpgradeGateSnapshot,identity:UpgradeRuntimeIdentity} */
    public function authorize(string $jobId, string $action, array $context = []): array
    {
        $states = self::ACTION_STATES[$action] ?? null;
        if ($states === null) {
            throw new RuntimeException('UPGRADE_AGENT_ACTION_FORBIDDEN');
        }
        $gate = $this->gate->snapshot();
        $identity = $this->identity->load();
        $normalReplay = $this->authorizedNormalTransitionReplay($jobId, $action, $context, $gate, $identity);
        $platformReceipt = $action === 'platform-receipt' && $gate->state === UpgradeState::Normal
            && $gate->jobId === null && $gate->acceptsRuntime($identity);
        if (!$normalReplay && !$platformReceipt
            && ($gate->jobId !== $jobId || !in_array($gate->state, $states, true))) {
            throw new RuntimeException('RUNTIME_IDENTITY_FENCED');
        }
        if (!$normalReplay && !$platformReceipt && !$gate->acceptsRuntime($identity)
            && !$this->authorizedFencedSource($jobId, $action, $context, $gate, $identity)) {
            throw new RuntimeException('RUNTIME_IDENTITY_FENCED');
        }

        return ['gate' => $gate, 'identity' => $identity];
    }

    /** @param array<string,mixed> $context */
    private function authorizedNormalTransitionReplay(
        string $jobId,
        string $action,
        array $context,
        UpgradeGateSnapshot $gate,
        UpgradeRuntimeIdentity $actual,
    ): bool {
        if ($action !== 'state-transition' || $gate->state !== UpgradeState::Normal
            || $gate->jobId !== null || !$gate->acceptsRuntime($actual)) {
            return false;
        }
        $expectedState = is_string($context['expected_state'] ?? null)
            ? UpgradeState::tryFrom($context['expected_state']) : null;
        $expectedRevision = $context['expected_revision'] ?? null;
        $pending = $context['platform_sync_pending'] ?? null;
        $operationId = strtolower((string) ($context['operation_id'] ?? ''));
        try {
            $attempt = UpgradeOperationAttempt::normalize($context['attempt'] ?? null);
        } catch (RuntimeException) {
            return false;
        }
        if (!in_array($expectedState, [
            UpgradeState::Completed,
            UpgradeState::Cancelled,
            UpgradeState::FailedPreApply,
        ], true) || ($context['next_state'] ?? null) !== UpgradeState::Normal->value
            || !is_int($expectedRevision) || $expectedRevision < 0
            || $expectedRevision + 1 !== $gate->revision || !is_bool($pending)
            || $pending !== $gate->platformSyncPending || !$this->uuid($operationId)) {
            return false;
        }
        $actionName = UpgradeOperationAttempt::action(
            'state_' . $expectedState->value . '_to_normal',
            $attempt,
        );
        $input = 'sha256:' . hash('sha256', json_encode([
            $expectedRevision,
            $expectedState->value,
            UpgradeState::Normal->value,
            $pending,
            $attempt,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if (!hash_equals($this->operations->operationId($jobId, $actionName, $input), $operationId)) {
            return false;
        }
        $operation = $this->operations->get($operationId);
        if (!is_array($operation) || ($operation['state'] ?? null) !== 'completed') {
            return false;
        }
        try {
            $this->operations->assertMatches($operation, $jobId, $actionName, $input);
        } catch (RuntimeException) {
            return false;
        }
        $result = $operation['result'] ?? null;

        return is_array($result)
            && ($result['state'] ?? null) === UpgradeState::Normal->value
            && ($result['revision'] ?? null) === $gate->revision
            && array_key_exists('job_id', $result) && $result['job_id'] === null
            && ($result['platform_sync_pending'] ?? null) === $gate->platformSyncPending
            && $this->sameIdentity($result['runtime_identity'] ?? null, $gate->runtimeIdentity());
    }

    /** @param array<string,mixed> $context */
    private function authorizedFencedSource(
        string $jobId,
        string $action,
        array $context,
        UpgradeGateSnapshot $gate,
        UpgradeRuntimeIdentity $actual,
    ): bool {
        if ($action === 'runtime-fence') {
            return $this->authorizedFenceReplay($jobId, $context, $gate, $actual);
        }
        if (!in_array($action, ['migrations', 'state-transition', 'resume'], true)) {
            return false;
        }
        $source = $this->contextIdentity($context['source'] ?? null);
        $fenceOperationId = strtolower((string) ($context['fence_operation_id'] ?? ''));
        if (!$source instanceof UpgradeRuntimeIdentity || !$source->equals($actual)
            || !$this->uuid($fenceOperationId)) {
            return false;
        }
        $operation = $this->operations->get($fenceOperationId);
        if (!is_array($operation) || ($operation['job_id'] ?? null) !== $jobId
            || preg_match('/^runtime_fence(?:_r_[0-9a-f]{12})?$/D', (string) ($operation['action'] ?? '')) !== 1
            || ($operation['state'] ?? null) !== 'completed') {
            return false;
        }
        $result = $operation['result'] ?? null;

        return is_array($result)
            && $this->sameIdentity($result['source_runtime_identity'] ?? null, $source)
            && $this->sameIdentity($result['runtime_identity'] ?? null, $gate->runtimeIdentity());
    }

    /** @param array<string,mixed> $context */
    private function authorizedFenceReplay(
        string $jobId,
        array $context,
        UpgradeGateSnapshot $gate,
        UpgradeRuntimeIdentity $actual,
    ): bool {
        $source = $this->contextIdentity($context['source'] ?? null);
        $target = $this->contextIdentity($context['target'] ?? null);
        $operationId = strtolower((string) ($context['operation_id'] ?? ''));
        $revision = $context['expected_revision'] ?? null;
        try {
            $attempt = UpgradeOperationAttempt::normalize($context['attempt'] ?? null);
        } catch (RuntimeException) {
            return false;
        }
        if (!$source instanceof UpgradeRuntimeIdentity || !$target instanceof UpgradeRuntimeIdentity
            || !$source->equals($actual) || !$target->equals($gate->runtimeIdentity())
            || !is_int($revision) || $revision < 0 || !$this->uuid($operationId)) {
            return false;
        }
        $input = 'sha256:' . hash('sha256', json_encode([
            $revision,
            $source->toArray(),
            $target->toArray(),
            $attempt,
        ], JSON_THROW_ON_ERROR));
        $action = UpgradeOperationAttempt::action('runtime_fence', $attempt);
        if (!hash_equals($this->operations->operationId($jobId, $action, $input), $operationId)) {
            return false;
        }
        $operation = $this->operations->get($operationId);
        if (!is_array($operation) || !in_array($operation['state'] ?? null, ['running', 'completed'], true)) {
            return false;
        }
        try {
            $this->operations->assertMatches($operation, $jobId, $action, $input);
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    private function contextIdentity(mixed $value): ?UpgradeRuntimeIdentity
    {
        if (!is_array($value) || array_keys($value) !== [
            'version', 'deployment_id', 'storage_layout_version', 'storage_layout_generation',
        ] || !is_string($value['version']) || !is_string($value['deployment_id'])
            || !is_int($value['storage_layout_version']) || !is_int($value['storage_layout_generation'])) {
            return null;
        }
        try {
            return new UpgradeRuntimeIdentity(
                $value['version'],
                strtolower($value['deployment_id']),
                $value['storage_layout_version'],
                $value['storage_layout_generation'],
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function sameIdentity(mixed $value, UpgradeRuntimeIdentity $expected): bool
    {
        $identity = $this->contextIdentity($value);

        return $identity instanceof UpgradeRuntimeIdentity && $identity->equals($expected);
    }

    private function uuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }
}
