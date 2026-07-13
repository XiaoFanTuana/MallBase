<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Closure;
use Throwable;

/** Builds the two deliberately different public and owner-safe projections. */
final readonly class UpgradeViewService
{
    private const MAX_TIMESTAMP = 4_102_444_800;

    /** @var Closure(string):?array */
    private Closure $jobStatusReader;

    public function __construct(
        private UpgradeSharedFileStore $files,
        private UpgradeSessionAuthStore $sessions,
        ?Closure $jobStatusReader = null,
    ) {
        $this->jobStatusReader = $jobStatusReader ?? static fn(string $jobId): ?array => null;
    }

    /** @return array{state:string,reason:string,retry_after:int,updated_at:int} */
    public function maintenance(): array
    {
        $state = UpgradeState::Normal->value;
        $updatedAt = time();
        if ((bool) config('upgrade.enabled', false)) {
            try {
                $snapshot = app()->make(UpgradeGateRepository::class)->snapshot();
                $state = $snapshot->state->value;
                $updatedAt = $snapshot->updatedAt;
            } catch (Throwable) {
                // A configured runtime whose gate cannot be proven is not
                // presented as healthy to clients.
                $state = UpgradeState::FailedMaintenance->value;
            }
        }

        $available = in_array($state, [
            UpgradeState::Normal->value,
            UpgradeState::Preparing->value,
            UpgradeState::ReadyToDrain->value,
            UpgradeState::FailedPreApply->value,
            UpgradeState::Cancelled->value,
        ], true);

        return [
            'state' => $state,
            'reason' => $available ? 'SYSTEM_AVAILABLE' : 'SYSTEM_MAINTENANCE',
            'retry_after' => 5,
            'updated_at' => $updatedAt,
        ];
    }

    /** @param array<string, mixed> $principal @return array<string, mixed> */
    public function status(array $principal): array
    {
        $session = $this->sessions->load();
        if ($session === null || ($principal['session_id'] ?? null) !== $session['session_id']
            || ($principal['revision'] ?? null) !== $session['revision']) {
            throw new \RuntimeException('UPGRADE_SESSION_CONFLICT');
        }

        $agent = $this->agent();
        $catalog = $this->catalog();
        $maintenance = $this->maintenance();
        $gate = $this->ownerGate();
        $reader = $this->jobStatusReader;
        $job = $session['job_id'] !== null ? $reader($session['job_id']) : null;
        $allowedActions = $session['copy_confirmation_pending']
            ? ['confirm_recovery']
            : ['rotate_recovery', 'bootstrap_platform'];
        if (!$session['copy_confirmation_pending'] && $session['job_id'] === null
            && $agent['online'] && $agent['upgrade_ready'] && $catalog['available']
            && $maintenance['state'] === UpgradeState::Normal->value) {
            $allowedActions[] = 'create_job';
        }
        if (is_array($job)) {
            if ($gate['state'] === UpgradeState::ReadyToDrain->value) {
                $allowedActions[] = 'start_drain';
            }
            foreach ($job['allowed_actions'] ?? [] as $action) {
                if (is_string($action)) {
                    $allowedActions[] = $action;
                }
            }
        }
        $deployment = $this->deploymentProjection(
            $session['job_id'],
            $session['instance_id'],
            $job,
        );

        return [
            'session' => [
                'session_id' => $session['session_id'],
                'revision' => $session['revision'],
                'job_id' => $session['job_id'],
                'copy_confirmation_pending' => $session['copy_confirmation_pending'],
                'confirmed_request_id' => is_array($session['confirmation_receipt'])
                    ? $session['confirmation_receipt']['request_id']
                    : null,
                'owner_expires_at' => $session['owner_expires_at'],
            ],
            'csrf_nonce' => (string) ($principal['csrf_nonce'] ?? ''),
            'maintenance' => $maintenance,
            'gate' => $gate,
            'agent' => $agent,
            'catalog' => $catalog,
            'job' => $job,
            'deployment' => $deployment,
            'start_commands' => [
                'amd64' => './upgrade/bin/mallbase-agent-linux-amd64 serve',
                'arm64' => './upgrade/bin/mallbase-agent-linux-arm64 serve',
            ],
            'allowed_actions' => array_values(array_unique($allowedActions)),
        ];
    }

    /**
     * The browser receives copy-only, relative command templates. No value from
     * the job document is interpolated into a shell command.
     *
     * @param array<string,mixed>|null $job
     * @return array<string,mixed>|null
     */
    private function deploymentProjection(?string $jobId, string $instanceId, ?array $job): ?array
    {
        if ($jobId === null || !is_array($job)
            || ($job['job_id'] ?? null) !== $jobId
            || ($job['state'] ?? null) !== UpgradeState::AwaitingDeployment->value) {
            return null;
        }
        try {
            $request = $this->files->readJobRequest($jobId);
            if (!$request instanceof \stdClass) {
                return null;
            }
            $raw = get_object_vars($request);
            $fields = [
                'schema_version', 'job_id', 'instance_id', 'current_version',
                'current_storage_layout_version', 'target_version', 'requested_by_admin_id',
                'requested_at', 'callback_base_url', 'capability_token', 'expected_revision',
            ];
            if (array_keys($raw) !== $fields || ($raw['schema_version'] ?? null) !== 1
                || ($raw['job_id'] ?? null) !== $jobId || ($raw['instance_id'] ?? null) !== $instanceId
                || !$this->validVersion($raw['current_version'] ?? null)
                || !is_int($raw['current_storage_layout_version'] ?? null)
                || $raw['current_storage_layout_version'] < 0
                || !$this->validVersion($raw['target_version'] ?? null)
                || !is_int($raw['requested_by_admin_id'] ?? null)
                || $raw['requested_by_admin_id'] < 1
                || !is_int($raw['requested_at'] ?? null) || $raw['requested_at'] < 0
                || $raw['requested_at'] > self::MAX_TIMESTAMP
                || !$this->validOrigin($raw['callback_base_url'] ?? null)
                || !$this->safeCapability($raw['capability_token'] ?? null)
                || !is_int($raw['expected_revision'] ?? null) || $raw['expected_revision'] < 0) {
                return null;
            }

            return [
                'target_version' => $raw['target_version'],
                'commands' => [
                    'build' => 'sh deploy/docker/build-sealed-image.sh',
                    'start' => 'sh deploy/docker/start-sealed-image.sh <32位 receipt id>',
                ],
                'steps' => [
                    [
                        'id' => 'build_image',
                        'title' => '构建密封镜像',
                        'description' => '在商城根目录执行构建命令，并复制输出中的 MALLBASE_IMAGE_RECEIPT_ID。',
                    ],
                    [
                        'id' => 'verify_receipt',
                        'title' => '确认构建收据',
                        'description' => '收据必须是 32 位小写十六进制字符，镜像 tag 不能替代收据。',
                    ],
                    [
                        'id' => 'start_services',
                        'title' => '启动新镜像',
                        'description' => '将启动命令中的 receipt id 占位内容替换为构建收据，再按现有部署架构完成切换。',
                    ],
                ],
                'risks' => [
                    '执行前请确认队列和定时任务仍处于维护状态。',
                    '构建或启动失败时，不要删除旧容器、升级备份和 Agent 收据。',
                    '多节点部署请按既有编排逐个验证；本页面只支持复制命令，不会执行 Docker。',
                ],
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function validVersion(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= 128
            && preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D', $value) === 1;
    }

    private function validOrigin(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
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

    /** @return array<string, mixed> */
    private function agent(): array
    {
        try {
            $document = $this->files->readJson('agent_status');
            if ($document === null) {
                return $this->offlineAgent();
            }
            $raw = get_object_vars($document);
            $allowed = [
                'schema_version', 'agent_version', 'mode', 'pid', 'arch', 'state',
                'platform_state', 'platform_code', 'current_job_id', 'last_seen_at',
                'lease_until', 'safe_to_stop', 'production_ready', 'upgrade_ready', 'revision',
            ];
            if (array_diff(array_keys($raw), $allowed) !== []
                || ($raw['schema_version'] ?? null) !== 1
                || !is_string($raw['agent_version'] ?? null)
                || !is_string($raw['mode'] ?? null)
                || !is_string($raw['arch'] ?? null)
                || !in_array($raw['arch'], ['amd64', 'arm64'], true)
                || !is_string($raw['state'] ?? null)
                || !is_int($raw['lease_until'] ?? null)
                || !is_int($raw['last_seen_at'] ?? null)
                || !is_bool($raw['safe_to_stop'] ?? null)
                || !is_bool($raw['upgrade_ready'] ?? null)) {
                return $this->offlineAgent('AGENT_STATUS_INVALID');
            }
            $now = time();

            return [
                'online' => $raw['mode'] === 'serve' && $raw['lease_until'] >= $now,
                'mode' => $raw['mode'],
                'state' => $raw['state'],
                'version' => $raw['agent_version'],
                'arch' => $raw['arch'],
                'lease_until' => $raw['lease_until'],
                'upgrade_ready' => $raw['upgrade_ready'],
                'safe_to_stop' => $raw['safe_to_stop'],
                'error_code' => $this->safeCode($raw['platform_code'] ?? ''),
            ];
        } catch (Throwable) {
            return $this->offlineAgent('AGENT_STATUS_UNAVAILABLE');
        }
    }

    /** @return array<string, mixed> */
    private function offlineAgent(string $error = ''): array
    {
        return [
            'online' => false,
            'mode' => 'offline',
            'state' => 'offline',
            'version' => '',
            'arch' => '',
            'lease_until' => 0,
            'upgrade_ready' => false,
            'safe_to_stop' => true,
            'error_code' => $error,
        ];
    }

    /** @return array<string, mixed> */
    private function catalog(): array
    {
        try {
            $document = $this->files->readJson('release_catalog');
            if ($document === null) {
                return ['available' => false, 'revision' => 0, 'expires_at' => 0, 'releases' => []];
            }
            $raw = get_object_vars($document);
            $expected = [
                'schema_version', 'catalog_revision', 'generated_at', 'expires_at',
                'current_version', 'agent_version', 'releases',
            ];
            if (array_keys($raw) !== $expected || $raw['schema_version'] !== 1
                || !is_int($raw['catalog_revision']) || $raw['catalog_revision'] < 1
                || !is_int($raw['generated_at']) || !is_int($raw['expires_at'])
                || !is_string($raw['current_version']) || !is_string($raw['agent_version'])
                || !is_array($raw['releases']) || !array_is_list($raw['releases'])
                || count($raw['releases']) > 100) {
                throw new \RuntimeException('catalog invalid');
            }
            $releases = [];
            foreach ($raw['releases'] as $release) {
                if (!$release instanceof \stdClass) {
                    throw new \RuntimeException('catalog invalid');
                }
                $entry = get_object_vars($release);
                $required = [
                    'version', 'channel', 'summary', 'from_version', 'package_kind',
                    'min_agent_version', 'from_storage_layout_version',
                    'to_storage_layout_version', 'compatible',
                ];
                $allowed = [...$required, 'required_bootstrap_version'];
                if (array_diff(array_keys($entry), $allowed) !== []
                    || array_diff($required, array_keys($entry)) !== []) {
                    throw new \RuntimeException('catalog invalid');
                }
                foreach (['version', 'channel', 'summary', 'from_version', 'package_kind', 'min_agent_version'] as $field) {
                    if (!is_string($entry[$field] ?? null) || strlen($entry[$field]) > 1024
                        || preg_match('/[\x00-\x1f\x7f]/', $entry[$field]) === 1) {
                        throw new \RuntimeException('catalog invalid');
                    }
                }
                foreach (['from_storage_layout_version', 'to_storage_layout_version'] as $field) {
                    if (!is_int($entry[$field] ?? null) || $entry[$field] < 0) {
                        throw new \RuntimeException('catalog invalid');
                    }
                }
                if (!is_bool($entry['compatible'] ?? null)) {
                    throw new \RuntimeException('catalog invalid');
                }
                $bootstrapVersion = $entry['required_bootstrap_version'] ?? '';
                if (!is_string($bootstrapVersion) || strlen($bootstrapVersion) > 64
                    || preg_match('/[\x00-\x1f\x7f]/', $bootstrapVersion) === 1) {
                    throw new \RuntimeException('catalog invalid');
                }
                if ($entry['compatible']) {
                    $sanitized = [
                        'version' => $entry['version'],
                        'channel' => $entry['channel'],
                        'summary' => $entry['summary'],
                        'from_version' => $entry['from_version'],
                        'package_kind' => $entry['package_kind'],
                        'min_agent_version' => $entry['min_agent_version'],
                        'from_storage_layout_version' => $entry['from_storage_layout_version'],
                        'to_storage_layout_version' => $entry['to_storage_layout_version'],
                        'compatible' => true,
                    ];
                    if ($bootstrapVersion !== '') {
                        $sanitized['required_bootstrap_version'] = $bootstrapVersion;
                    }
                    $releases[] = $sanitized;
                }
            }

            return [
                'available' => $raw['expires_at'] >= time(),
                'revision' => $raw['catalog_revision'],
                'expires_at' => $raw['expires_at'],
                'current_version' => $raw['current_version'],
                'releases' => $releases,
            ];
        } catch (Throwable) {
            return ['available' => false, 'revision' => 0, 'expires_at' => 0, 'releases' => []];
        }
    }

    private function safeCode(mixed $value): string
    {
        return is_string($value) && preg_match('/^[A-Z0-9_]{0,64}$/D', $value) === 1 ? $value : '';
    }

    /** @return array{state:string,revision:int,job_id:?string,updated_at:int} */
    private function ownerGate(): array
    {
        if (!(bool) config('upgrade.enabled', false)) {
            return ['state' => UpgradeState::Normal->value, 'revision' => 0, 'job_id' => null, 'updated_at' => time()];
        }
        try {
            $snapshot = app()->make(UpgradeGateRepository::class)->snapshot();

            return [
                'state' => $snapshot->state->value,
                'revision' => $snapshot->revision,
                'job_id' => $snapshot->jobId,
                'updated_at' => $snapshot->updatedAt,
            ];
        } catch (Throwable) {
            return [
                'state' => UpgradeState::FailedMaintenance->value,
                'revision' => 0,
                'job_id' => null,
                'updated_at' => time(),
            ];
        }
    }
}
