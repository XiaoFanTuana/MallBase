<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Closure;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Linearizes browser job creation and immutable control intents against the
 * session authorization file and the maintenance gate.
 */
final readonly class UpgradeJobControlService
{
    private const MAX_TIMESTAMP = 4_102_444_800;
    private const CONTROL_ACTIONS = ['cancel', 'resume'];

    /** @var Closure():int */
    private Closure $clock;

    /** @var Closure():string */
    private Closure $capabilitySource;

    public function __construct(
        private UpgradeSharedFileStore $files,
        private UpgradeSessionAuthStore $sessions,
        private UpgradeGateRepository $gate,
        private UpgradeDrainControl $drain,
        private string $callbackBaseUrl,
        ?Closure $clock = null,
        ?Closure $capabilitySource = null,
    ) {
        if (!$this->validOrigin($this->callbackBaseUrl)) {
            throw new RuntimeException('UPGRADE_JOB_CONFIG_INVALID');
        }
        $this->clock = $clock ?? static fn(): int => time();
        $this->capabilitySource = $capabilitySource ?? static fn(): string => rtrim(
            strtr(base64_encode(random_bytes(32)), '+/', '-_'),
            '=',
        );
    }

    /** @return array<string,mixed> */
    public function create(
        string $ownerCookie,
        string $sessionId,
        int $expectedSessionRevision,
        string $targetVersion,
        string $requestId,
    ): array {
        if (!$this->validUuid($sessionId) || !$this->validUuid($requestId)
            || $expectedSessionRevision < 1 || !$this->validVersion($targetVersion)) {
            throw new RuntimeException('UPGRADE_JOB_ARGUMENT_INVALID');
        }
        $now = $this->now();
        $jobId = $this->deriveJobId($sessionId, $requestId);
        $principal = $this->sessions->bindJob(
            $ownerCookie,
            $jobId,
            $expectedSessionRevision,
            $now,
            function (array $session) use ($jobId, $targetVersion, $now): void {
                $this->publishAndPrepare($session, $jobId, $targetVersion, $now);
            },
        );
        $request = $this->validatedRequest($this->files->readJobRequest($jobId), $jobId);

        return [
            'job_id' => $jobId,
            'session_revision' => $principal['revision'],
            'gate_revision' => $request['expected_revision'] + 1,
            'state' => UpgradeState::Preparing->value,
            'target_version' => $request['target_version'],
        ];
    }

    /** @return array<string,mixed> */
    public function startDrain(
        string $ownerCookie,
        string $jobId,
        int $sessionRevision,
        int $expectedGateRevision,
    ): array {
        if ($expectedGateRevision < 0) {
            throw new RuntimeException('UPGRADE_JOB_ARGUMENT_INVALID');
        }
        $now = $this->now();

        return $this->sessions->withAuthorizedJobMutation(
            $ownerCookie,
            $jobId,
            $sessionRevision,
            $now,
            function () use ($jobId, $expectedGateRevision): array {
                $snapshot = $this->gate->snapshot();
                if ($snapshot->jobId !== $jobId) {
                    throw new RuntimeException('UPGRADE_JOB_STATE_CONFLICT');
                }
                if ($snapshot->state === UpgradeState::Draining
                    && $snapshot->revision === $expectedGateRevision + 1) {
                    return $this->gateProjection($snapshot);
                }
                try {
                    return $this->gateProjection($this->drain->begin($jobId, $expectedGateRevision));
                } catch (Throwable) {
                    throw new RuntimeException('UPGRADE_JOB_STATE_CONFLICT');
                }
            },
        );
    }

    /** @return array<string,mixed> */
    public function control(
        string $ownerCookie,
        string $jobId,
        int $sessionRevision,
        int $expectedJobRevision,
        string $requestId,
        string $action,
    ): array {
        if (!in_array($action, self::CONTROL_ACTIONS, true) || !$this->validUuid($requestId)
            || $expectedJobRevision < 1) {
            throw new RuntimeException('UPGRADE_JOB_ARGUMENT_INVALID');
        }
        $now = $this->now();

        return $this->sessions->withAuthorizedJobMutation(
            $ownerCookie,
            $jobId,
            $sessionRevision,
            $now,
            function () use ($jobId, $expectedJobRevision, $requestId, $action, $now): array {
                $status = $this->status($jobId);
                if (($status['revision'] ?? null) !== $expectedJobRevision
                    || !in_array($action, $status['allowed_actions'] ?? [], true)) {
                    throw new RuntimeException('UPGRADE_JOB_STATE_CONFLICT');
                }
                $intent = (object) [
                    'schema_version' => 1,
                    'job_id' => $jobId,
                    'action' => $action,
                    'requested_at' => $now,
                    'expected_revision' => $expectedJobRevision,
                    'request_id' => $requestId,
                ];
                $this->files->publishJobControl($jobId, $expectedJobRevision, $requestId, $intent);
                $this->files->appendJobAudit($jobId, [
                    'event' => 'browser_control_requested',
                    'action' => $action,
                    'request_id' => $requestId,
                    'expected_revision' => $expectedJobRevision,
                    'recorded_at' => $now,
                ]);

                return [
                    'job_id' => $jobId,
                    'request_id' => $requestId,
                    'action' => $action,
                    'expected_revision' => $expectedJobRevision,
                    'status' => 'pending',
                ];
            },
        );
    }

    /** @return array<string,mixed>|null */
    public function status(string $jobId): ?array
    {
        if (!$this->validUuid($jobId)) {
            throw new RuntimeException('UPGRADE_JOB_ARGUMENT_INVALID');
        }
        $document = $this->files->readJobStatus($jobId);
        if ($document === null) {
            $gate = $this->gate->snapshot();
            if ($gate->jobId !== $jobId) {
                return null;
            }

            return [
                'job_id' => $jobId,
                'revision' => 1,
                'state' => $gate->state->value,
                'next_step' => 'preflight',
                'failure_class' => '',
                'platform_sync_pending' => false,
                'platform_receipt_confirmed' => false,
                'safe_to_stop' => false,
                'updated_at' => $gate->updatedAt,
                'allowed_actions' => $this->allowedActions($gate->state->value, false),
            ];
        }
        $raw = get_object_vars($document);
        $allowedFields = [
            'schema_version', 'job_id', 'revision', 'state', 'next_step', 'failure_class',
            'platform_sync_pending', 'platform_receipt_confirmed', 'safe_to_stop',
            'updated_at', 'progress_percent', 'message_code',
        ];
        if (array_diff(array_keys($raw), $allowedFields) !== []
            || ($raw['schema_version'] ?? null) !== 1 || ($raw['job_id'] ?? null) !== $jobId
            || !is_int($raw['revision'] ?? null) || $raw['revision'] < 1
            || !is_string($raw['state'] ?? null) || UpgradeState::tryFrom($raw['state']) === null
            || !is_string($raw['next_step'] ?? null) || !$this->safeToken($raw['next_step'], 64)
            || !is_string($raw['failure_class'] ?? null) || !$this->safeToken($raw['failure_class'], 64, true)
            || !is_bool($raw['platform_sync_pending'] ?? null)
            || !is_bool($raw['platform_receipt_confirmed'] ?? null)
            || !is_bool($raw['safe_to_stop'] ?? null)
            || !is_int($raw['updated_at'] ?? null) || $raw['updated_at'] < 0
            || $raw['updated_at'] > self::MAX_TIMESTAMP
            || (isset($raw['progress_percent']) && (!is_int($raw['progress_percent'])
                || $raw['progress_percent'] < 0 || $raw['progress_percent'] > 100))
            || (isset($raw['message_code']) && (!is_string($raw['message_code'])
                || preg_match('/^[A-Z0-9_]{0,64}$/D', $raw['message_code']) !== 1))) {
            throw new RuntimeException('UPGRADE_JOB_INVALID');
        }
        $result = [
            'job_id' => $jobId,
            'revision' => $raw['revision'],
            'state' => $raw['state'],
            'next_step' => $raw['next_step'],
            'failure_class' => $raw['failure_class'],
            'platform_sync_pending' => $raw['platform_sync_pending'],
            'platform_receipt_confirmed' => $raw['platform_receipt_confirmed'],
            'safe_to_stop' => $raw['safe_to_stop'],
            'updated_at' => $raw['updated_at'],
            'allowed_actions' => $this->allowedActions($raw['state'], $raw['safe_to_stop']),
        ];
        if (isset($raw['progress_percent'])) {
            $result['progress_percent'] = $raw['progress_percent'];
        }
        if (isset($raw['message_code'])) {
            $result['message_code'] = $raw['message_code'];
        }

        return $result;
    }

    /** @param array<string,mixed> $session */
    private function publishAndPrepare(array $session, string $jobId, string $targetVersion, int $now): void
    {
        $gate = $this->gate->snapshot();
        $existing = $this->files->readJobRequest($jobId);
        if ($existing !== null) {
            $request = $this->validatedRequest($existing, $jobId);
            if ($request['instance_id'] !== $session['instance_id']
                || $request['target_version'] !== $targetVersion) {
                throw new RuntimeException('UPGRADE_JOB_CONFLICT');
            }
            if ($gate->jobId === $jobId && $gate->state === UpgradeState::Preparing
                && $gate->revision === $request['expected_revision'] + 1) {
                return;
            }
            if ($gate->state !== UpgradeState::Normal || $gate->revision !== $request['expected_revision']) {
                throw new RuntimeException('UPGRADE_JOB_STATE_CONFLICT');
            }
        } else {
            $catalog = $this->requireRelease($targetVersion, $now);
            $this->requireReadyAgent($now);
            if ($gate->state !== UpgradeState::Normal || $gate->jobId !== null || $gate->platformSyncPending) {
                throw new RuntimeException('UPGRADE_JOB_STATE_CONFLICT');
            }
            $capabilitySource = $this->capabilitySource;
            $capability = $capabilitySource();
            if (!is_string($capability) || !$this->safeCapability($capability)) {
                throw new RuntimeException('UPGRADE_JOB_UNAVAILABLE');
            }
            $request = [
                'schema_version' => 1,
                'job_id' => $jobId,
                'instance_id' => $session['instance_id'],
                'current_version' => $catalog['current_version'],
                'current_storage_layout_version' => $gate->requiredStorageLayoutVersion,
                'target_version' => $targetVersion,
                'requested_by_admin_id' => 1,
                'requested_at' => $now,
                'callback_base_url' => $this->callbackBaseUrl,
                'capability_token' => $capability,
                'expected_revision' => $gate->revision,
            ];
            $this->files->publishJobRequest($jobId, $this->toObject($request));
        }
        try {
            $prepared = $this->gate->compareAndSet(
                $gate->revision,
                UpgradeState::Normal,
                UpgradeState::Preparing,
                $jobId,
            );
        } catch (Throwable) {
            throw new RuntimeException('UPGRADE_JOB_STATE_CONFLICT');
        }
        if ($prepared->jobId !== $jobId || $prepared->state !== UpgradeState::Preparing) {
            throw new RuntimeException('UPGRADE_JOB_STATE_CONFLICT');
        }
        $this->files->appendJobAudit($jobId, [
            'event' => 'job_created',
            'target_version' => $targetVersion,
            'gate_revision' => $prepared->revision,
            'recorded_at' => $now,
        ]);
    }

    /** @return array{current_version:string} */
    private function requireRelease(string $targetVersion, int $now): array
    {
        $document = $this->files->readJson('release_catalog');
        if ($document === null) {
            throw new RuntimeException('UPGRADE_CATALOG_UNAVAILABLE');
        }
        $raw = get_object_vars($document);
        if (($raw['schema_version'] ?? null) !== 1 || !is_int($raw['expires_at'] ?? null)
            || $raw['expires_at'] < $now || !is_string($raw['current_version'] ?? null)
            || !$this->validVersion($raw['current_version']) || !is_array($raw['releases'] ?? null)
            || !array_is_list($raw['releases']) || count($raw['releases']) > 100) {
            throw new RuntimeException('UPGRADE_CATALOG_UNAVAILABLE');
        }
        foreach ($raw['releases'] as $release) {
            if (!$release instanceof stdClass) {
                throw new RuntimeException('UPGRADE_CATALOG_UNAVAILABLE');
            }
            $entry = get_object_vars($release);
            if (($entry['version'] ?? null) === $targetVersion && ($entry['compatible'] ?? null) === true) {
                return ['current_version' => $raw['current_version']];
            }
        }
        throw new RuntimeException('UPGRADE_RELEASE_NOT_COMPATIBLE');
    }

    private function requireReadyAgent(int $now): void
    {
        $document = $this->files->readJson('agent_status');
        $raw = $document instanceof stdClass ? get_object_vars($document) : [];
        if (($raw['schema_version'] ?? null) !== 1 || ($raw['mode'] ?? null) !== 'serve'
            || !is_int($raw['lease_until'] ?? null) || $raw['lease_until'] < $now
            || ($raw['upgrade_ready'] ?? null) !== true || ($raw['current_job_id'] ?? '') !== '') {
            throw new RuntimeException('UPGRADE_AGENT_NOT_READY');
        }
    }

    /** @return array<string,mixed> */
    private function validatedRequest(?object $document, string $jobId): array
    {
        if (!$document instanceof stdClass) {
            throw new RuntimeException('UPGRADE_JOB_INVALID');
        }
        $raw = get_object_vars($document);
        $fields = [
            'schema_version', 'job_id', 'instance_id', 'current_version',
            'current_storage_layout_version', 'target_version', 'requested_by_admin_id',
            'requested_at', 'callback_base_url', 'capability_token', 'expected_revision',
        ];
        if (array_keys($raw) !== $fields || $raw['schema_version'] !== 1 || $raw['job_id'] !== $jobId
            || !$this->validUuid($raw['instance_id'] ?? '') || !$this->validVersion($raw['current_version'] ?? '')
            || !is_int($raw['current_storage_layout_version'] ?? null) || $raw['current_storage_layout_version'] < 0
            || !$this->validVersion($raw['target_version'] ?? '') || $raw['requested_by_admin_id'] !== 1
            || !is_int($raw['requested_at'] ?? null) || $raw['requested_at'] < 0
            || $raw['requested_at'] > self::MAX_TIMESTAMP || $raw['callback_base_url'] !== $this->callbackBaseUrl
            || !$this->safeCapability($raw['capability_token'] ?? '')
            || !is_int($raw['expected_revision'] ?? null) || $raw['expected_revision'] < 0) {
            throw new RuntimeException('UPGRADE_JOB_INVALID');
        }

        return $raw;
    }

    /** @return array{job_id:string,state:string,revision:int} */
    private function gateProjection(UpgradeGateSnapshot $snapshot): array
    {
        return [
            'job_id' => (string) $snapshot->jobId,
            'state' => $snapshot->state->value,
            'revision' => $snapshot->revision,
        ];
    }

    /** @return list<string> */
    private function allowedActions(string $state, bool $safeToStop): array
    {
        if ($safeToStop) {
            return [];
        }

        return match ($state) {
            'preparing', 'ready_to_drain', 'draining', 'paused' => ['cancel'],
            'failed_pre_apply' => [],
            'failed_maintenance' => ['resume'],
            default => [],
        };
    }

    private function deriveJobId(string $sessionId, string $requestId): string
    {
        $bytes = substr(hash('sha256', "mallbase-upgrade-job-v1\0" . $sessionId . "\0" . $requestId, true), 0, 16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x50);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private function now(): int
    {
        $clock = $this->clock;
        $now = $clock();
        if (!is_int($now) || $now < 0 || $now > self::MAX_TIMESTAMP) {
            throw new RuntimeException('UPGRADE_JOB_UNAVAILABLE');
        }

        return $now;
    }

    private function validUuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }

    private function validVersion(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= 128
            && preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D', $value) === 1;
    }

    private function validOrigin(string $value): bool
    {
        $parts = parse_url($value);

        return is_array($parts) && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && is_string($parts['host'] ?? null) && ($parts['host'] ?? '') !== ''
            && !isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            && ($parts['path'] ?? '') === '';
    }

    private function safeCapability(mixed $value): bool
    {
        return is_string($value) && strlen($value) >= 43 && strlen($value) <= 128
            && preg_match('/^[A-Za-z0-9_-]+$/D', $value) === 1;
    }

    private function safeToken(string $value, int $maximum, bool $empty = false): bool
    {
        return ($empty || $value !== '') && strlen($value) <= $maximum
            && preg_match('/^[a-z0-9_]*$/D', $value) === 1;
    }

    /** @param array<string,mixed> $value */
    private function toObject(array $value): object
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR);
    }
}
