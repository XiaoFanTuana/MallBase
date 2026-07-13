<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Closure;
use JsonException;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Agent 与 PHP 共享文件的固定路径、权限和持久化边界。
 *
 * 构造参数只能来自服务端配置；I/O 替换表仅供单元测试注入故障。
 */
final class UpgradeSharedFileStore
{
    private const DOCUMENTS = [
        'instance' => ['path' => 'config/instance.json', 'owner' => 'php', 'mode' => 0660, 'directory_mode' => 02770, 'write' => true],
        'upgrade_gate' => ['path' => 'state/upgrade-gate.json', 'owner' => 'php', 'mode' => 0660, 'directory_mode' => 02770, 'write' => true],
        'upgrade_operations' => ['path' => 'state/upgrade-operations.json', 'owner' => 'php', 'mode' => 0660, 'directory_mode' => 02770, 'write' => true],
        'migration_registry' => ['path' => 'state/migrations.json', 'owner' => 'php', 'mode' => 0660, 'directory_mode' => 02770, 'write' => true],
        'agent_status' => ['path' => 'run/agent-status.json', 'owner' => 'agent', 'mode' => 0660, 'directory_mode' => 02770, 'write' => false],
        'release_catalog' => ['path' => 'run/release-catalog.json', 'owner' => 'agent', 'mode' => 0660, 'directory_mode' => 02770, 'write' => false],
        'active_job' => ['path' => 'run/active-job.json', 'owner' => 'php', 'mode' => 0660, 'directory_mode' => 02770, 'write' => true],
        'session_auth' => ['path' => 'run/session-auth.json', 'owner' => 'shared', 'mode' => 0660, 'directory_mode' => 02770, 'write' => true],
        'namespace_projection' => ['path' => 'staging/storage-namespace.json', 'owner' => 'agent', 'mode' => 0444, 'directory_mode' => 0750, 'write' => false],
        'runtime_retirement_evidence' => ['path' => 'run/runtime-retirement-evidence.json', 'owner' => 'php', 'mode' => 0660, 'directory_mode' => 02770, 'write' => true],
    ];

    private const INSTANCE_LOCK_PATH = 'run/instance-config.lock';
    private const UPGRADE_GATE_LOCK_PATH = 'state/upgrade-gate.lock';
    private const RUNTIME_REGISTRY_LOCK_PATH = 'run/runtime-registry.lock';
    private const SESSION_AUTH_LOCK_PATH = 'run/session-auth.lock';
    private const UPGRADE_OPERATION_LOCK_PATH = 'state/upgrade-operations.lock';
    private const MIGRATION_REGISTRY_LOCK_PATH = 'state/migrations.lock';
    private const JOB_CONTROL_LOCK_PATH = 'jobs/job-control.lock';

    private const OPERATION_NAMES = [
        'lstat', 'fstat', 'fopen', 'fwrite', 'fflush', 'fsync', 'rename',
        'flock', 'open_dir', 'close_dir', 'fault',
    ];

    /** @var array<string, callable> */
    private readonly array $operations;

    /**
     * @param array<string, callable> $testOperations
     */
    public function __construct(
        private readonly string $root,
        private readonly int $agentUid,
        private readonly int $expectedGid,
        private readonly int $phpEuid,
        private readonly int $maxJsonBytes = 65536,
        private readonly int $lockTimeoutMilliseconds = 2000,
        array $testOperations = [],
    ) {
        if ($this->root === '' || !str_starts_with($this->root, DIRECTORY_SEPARATOR)
            || $this->agentUid < 0 || $this->expectedGid < 0 || $this->phpEuid < 0
            || $this->agentUid === $this->phpEuid || $this->maxJsonBytes < 1
            || $this->lockTimeoutMilliseconds < 1) {
            $this->throwPublic('SHARED_FILE_PERMISSION_INVALID');
        }

        foreach ($testOperations as $name => $operation) {
            if (!in_array($name, self::OPERATION_NAMES, true) || !is_callable($operation)) {
                $this->throwPublic('SHARED_FILE_PERMISSION_INVALID');
            }
        }

        $this->operations = array_replace($this->nativeOperations(), $testOperations);
    }

    public function readJson(string $logicalName): ?object
    {
        if (!isset(self::DOCUMENTS[$logicalName])) {
            $this->throwPublic('SHARED_FILE_UNAVAILABLE');
        }

        try {
            $raw = $this->readRawDocument($logicalName);
            if ($raw === null) {
                return null;
            }

            return $this->decodeStrictObject($raw);
        } catch (Throwable $exception) {
            $code = $exception->getMessage() === 'SHARED_FILE_INVALID'
                ? 'SHARED_FILE_INVALID'
                : ($exception->getMessage() === 'SHARED_FILE_PERMISSION_INVALID'
                    ? 'SHARED_FILE_PERMISSION_INVALID'
                    : 'SHARED_FILE_UNAVAILABLE');
            $this->throwPublic($code);
        }
    }

    public function writeJson(string $logicalName, object $document): void
    {
        $definition = self::DOCUMENTS[$logicalName] ?? null;
        if ($definition === null || $definition['write'] !== true) {
            $this->throwPublic('SHARED_FILE_UNAVAILABLE');
        }

        try {
            $bytes = json_encode(
                $document,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException) {
            $this->throwPublic('SHARED_FILE_INVALID');
        }
        if (!is_string($bytes) || strlen($bytes) > $this->maxJsonBytes) {
            $this->throwPublic('SHARED_FILE_INVALID');
        }

        $this->writeEncodedDefinition($definition, $bytes);
    }

    public function readRuntimeInstance(string $fileName): ?object
    {
        $definition = $this->runtimeDefinition($fileName);
        try {
            $raw = $this->readRawDefinition($definition);

            return $raw === null ? null : $this->decodeStrictObject($raw);
        } catch (Throwable $exception) {
            $code = $exception->getMessage() === 'SHARED_FILE_INVALID'
                ? 'SHARED_FILE_INVALID'
                : ($exception->getMessage() === 'SHARED_FILE_PERMISSION_INVALID'
                    ? 'SHARED_FILE_PERMISSION_INVALID'
                    : 'SHARED_FILE_UNAVAILABLE');
            $this->throwPublic($code);
        }
    }

    public function writeRuntimeInstance(string $fileName, object $document): void
    {
        $definition = $this->runtimeDefinition($fileName);
        try {
            $bytes = json_encode(
                $document,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException) {
            $this->throwPublic('SHARED_FILE_INVALID');
        }
        if (!is_string($bytes) || strlen($bytes) > $this->maxJsonBytes) {
            $this->throwPublic('SHARED_FILE_INVALID');
        }
        $this->writeEncodedDefinition($definition, $bytes);
    }

    public function readDrainCheckpoint(string $jobId): ?object
    {
        $definition = $this->drainCheckpointDefinition($jobId);
        try {
            $raw = $this->readRawDefinition($definition);

            return $raw === null ? null : $this->decodeStrictObject($raw);
        } catch (Throwable $exception) {
            $code = $exception->getMessage() === 'SHARED_FILE_INVALID'
                ? 'SHARED_FILE_INVALID'
                : ($exception->getMessage() === 'SHARED_FILE_PERMISSION_INVALID'
                    ? 'SHARED_FILE_PERMISSION_INVALID'
                    : 'SHARED_FILE_UNAVAILABLE');
            $this->throwPublic($code);
        }
    }

    public function writeDrainCheckpoint(string $jobId, object $document): void
    {
        $definition = $this->drainCheckpointDefinition($jobId);
        try {
            $bytes = json_encode(
                $document,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException) {
            $this->throwPublic('SHARED_FILE_INVALID');
        }
        if (!is_string($bytes) || strlen($bytes) > $this->maxJsonBytes) {
            $this->throwPublic('SHARED_FILE_INVALID');
        }
        $this->writeEncodedDefinition($definition, $bytes);
    }

    public function readJobRequest(string $jobId): ?object
    {
        return $this->readJobDocument($this->jobDefinition($jobId, 'request.json', 'php'));
    }

    public function readJobStatus(string $jobId): ?object
    {
        return $this->readJobDocument($this->jobDefinition($jobId, 'status.json', 'agent'));
    }

    public function readJobControlResult(string $jobId, string $requestId): ?object
    {
        $this->requireUuid($requestId);

        return $this->readJobDocument($this->jobDefinition(
            $jobId,
            'control-results/' . $requestId . '.json',
            'agent',
        ));
    }

    public function readJobControl(string $jobId, int $expectedRevision, string $requestId): ?object
    {
        if ($expectedRevision < 0) {
            $this->throwPublic('UPGRADE_JOB_ARGUMENT_INVALID');
        }
        $this->requireUuid($requestId);

        try {
            return $this->readJobDocument($this->jobDefinition(
                $jobId,
                'controls/' . $expectedRevision . '-' . $requestId . '.json',
                'php',
            ));
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'UPGRADE_JOB_UNAVAILABLE') {
                return null;
            }
            throw $exception;
        }
    }

    public function publishJobRequest(string $jobId, object $document): void
    {
        $bytes = $this->encodeDocument($document);
        $this->withJobControlLock(function () use ($jobId, $bytes): void {
            $this->ensureJobDirectory($jobId);
            $definition = $this->jobDefinition($jobId, 'request.json', 'php');
            $existing = $this->readRawDefinition($definition);
            if ($existing !== null) {
                if (!hash_equals($existing, $bytes)) {
                    $this->throwPublic('UPGRADE_JOB_CONFLICT');
                }
            } else {
                $this->writeEncodedDefinition($definition, $bytes);
            }
            $this->writeEncodedDefinition(self::DOCUMENTS['active_job'], $this->encodeDocument((object) [
                'schema_version' => 1,
                'job_id' => $jobId,
            ]));
        });
    }

    /** Atomically publishes one immutable control intent for a gate revision. */
    public function publishJobControl(
        string $jobId,
        int $expectedRevision,
        string $requestId,
        object $document,
    ): void {
        if ($expectedRevision < 0) {
            $this->throwPublic('UPGRADE_JOB_ARGUMENT_INVALID');
        }
        $this->requireUuid($requestId);
        $bytes = $this->encodeDocument($document);
        $this->withJobControlLock(function () use ($jobId, $expectedRevision, $requestId, $bytes): void {
            $this->ensureJobDirectory($jobId, 'controls');
            $prefix = $expectedRevision . '-';
            $directory = $this->path('jobs/' . $jobId . '/controls');
            $entries = @scandir($directory);
            if (!is_array($entries) || count($entries) > 10_002) {
                $this->throwPublic('UPGRADE_JOB_UNAVAILABLE');
            }
            $fileName = $prefix . $requestId . '.json';
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || !str_starts_with($entry, $prefix)) {
                    continue;
                }
                if ($entry !== $fileName) {
                    $this->throwPublic('UPGRADE_CONTROL_CONFLICT');
                }
            }
            $definition = $this->jobDefinition($jobId, 'controls/' . $fileName, 'php');
            $existing = $this->readRawDefinition($definition);
            if ($existing !== null) {
                if (!hash_equals($existing, $bytes)) {
                    $this->throwPublic('UPGRADE_CONTROL_CONFLICT');
                }

                return;
            }
            $this->writeEncodedDefinition($definition, $bytes);
        });
    }

    /** @param array<string,mixed> $event */
    public function appendJobAudit(string $jobId, array $event): void
    {
        $this->withJobControlLock(function () use ($jobId, $event): void {
            $this->ensureJobDirectory($jobId);
            $definition = $this->jobDefinition($jobId, 'audit.json', 'php');
            $existing = $this->readRawDefinition($definition);
            $revision = 0;
            $events = [];
            if ($existing !== null) {
                $raw = get_object_vars($this->decodeStrictObject($existing));
                if (array_keys($raw) !== ['schema_version', 'revision', 'events']
                    || $raw['schema_version'] !== 1 || !is_int($raw['revision'])
                    || $raw['revision'] < 0 || !is_array($raw['events']) || !array_is_list($raw['events'])) {
                    $this->throwPublic('UPGRADE_JOB_INVALID');
                }
                $revision = $raw['revision'];
                foreach ($raw['events'] as $item) {
                    if (!$item instanceof stdClass) {
                        $this->throwPublic('UPGRADE_JOB_INVALID');
                    }
                    $events[] = $item;
                }
            }
            if (count($events) >= 1000 || $revision === PHP_INT_MAX) {
                $this->throwPublic('UPGRADE_JOB_LIMIT_REACHED');
            }
            $events[] = $this->toObjectValue($event);
            $this->writeEncodedDefinition($definition, $this->encodeDocument((object) [
                'schema_version' => 1,
                'revision' => $revision + 1,
                'events' => $events,
            ]));
        });
    }

    /** @return list<string> */
    public function listRuntimeInstances(): array
    {
        try {
            $relative = 'run/runtime-instances';
            $this->validateSharedDirectory($relative, 02770);
            $entries = @scandir($this->path($relative));
            if (!is_array($entries) || count($entries) > 10_002) {
                throw new RuntimeException('list runtime instances');
            }
            $result = [];
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->runtimeDefinition($entry);
                $result[] = $entry;
            }
            sort($result, SORT_STRING);

            return $result;
        } catch (Throwable $exception) {
            $this->throwPublic($exception->getMessage() === 'SHARED_FILE_PERMISSION_INVALID'
                ? 'SHARED_FILE_PERMISSION_INVALID'
                : 'SHARED_FILE_UNAVAILABLE');
        }
    }

    /** @param array{path:string,owner:string,mode:int,directory_mode:int,write:bool} $definition */
    private function writeEncodedDefinition(array $definition, string $bytes): void
    {

        $renamed = false;
        $temporaryPath = '';
        $temporaryHandle = null;
        $temporaryIdentity = null;

        try {
            $directory = dirname((string) $definition['path']);
            $this->validateDefinitionDirectory($directory, $definition);
            $targetPath = $this->path((string) $definition['path']);
            $targetStat = $this->lstat($targetPath);
            if ($targetStat !== null) {
                $this->validateDefinitionFile($targetStat, $definition);
            }

            $temporaryPath = $this->path($directory . '/.' . basename((string) $definition['path']) . '.' . bin2hex(random_bytes(16)) . '.tmp');
            $temporaryHandle = $this->operation('fopen', $temporaryPath, 'x+b');
            if (!is_resource($temporaryHandle) || !@chmod($temporaryPath, 0660)) {
                throw new RuntimeException('write temp');
            }
            $createdDescriptorStat = $this->operation('fstat', $temporaryHandle);
            $createdNameStat = $this->lstat($temporaryPath);
            if (!is_array($createdDescriptorStat) || $createdNameStat === null) {
                throw new RuntimeException('stat created temp');
            }
            $this->validateRegularFile($createdDescriptorStat, $this->phpEuid, 0660);
            $this->validateRegularFile($createdNameStat, $this->phpEuid, 0660);
            $this->assertSameInode($createdDescriptorStat, $createdNameStat);
            $temporaryIdentity = $createdDescriptorStat;
            $this->checkpoint('after_temp_create');

            $offset = 0;
            $length = strlen($bytes);
            while ($offset < $length) {
                $written = $this->operation('fwrite', $temporaryHandle, substr($bytes, $offset));
                if (!is_int($written) || $written <= 0) {
                    throw new RuntimeException('write temp');
                }
                $offset += $written;
            }
            $this->checkpoint('after_write');

            if ($this->operation('fflush', $temporaryHandle) !== true) {
                throw new RuntimeException('flush temp');
            }
            $this->checkpoint('after_fflush');
            if ($this->operation('fsync', $temporaryHandle) !== true) {
                throw new RuntimeException('sync temp');
            }
            $this->checkpoint('after_file_fsync');

            $descriptorStat = $this->operation('fstat', $temporaryHandle);
            $nameStat = $this->lstat($temporaryPath);
            if (!is_array($descriptorStat) || $nameStat === null) {
                throw new RuntimeException('stat temp');
            }
            $this->validateRegularFile($descriptorStat, $this->phpEuid, 0660);
            $this->validateRegularFile($nameStat, $this->phpEuid, 0660);
            $this->assertSameInode($descriptorStat, $nameStat);
            $this->checkpoint('after_verify');

            @fclose($temporaryHandle);
            $temporaryHandle = null;
            if ($this->operation('rename', $temporaryPath, $targetPath) !== true) {
                throw new RuntimeException('rename temp');
            }
            $renamed = true;
            $temporaryPath = '';
            $publishedStat = $this->lstat($targetPath);
            if ($publishedStat === null) {
                throw new RuntimeException('stat published file');
            }
            $this->validateDefinitionFile($publishedStat, $definition);
            $this->assertSameInode($descriptorStat, $publishedStat);
            $this->checkpoint('after_rename');
            $this->checkpoint('before_parent_fsync');

            $directoryHandle = $this->operation('open_dir', $this->path($directory));
            if (!is_resource($directoryHandle)) {
                throw new RuntimeException('open parent');
            }
            try {
                $directoryDescriptorStat = $this->operation('fstat', $directoryHandle);
                $directoryNameStat = $this->lstat($this->path($directory));
                if (!is_array($directoryDescriptorStat) || $directoryNameStat === null) {
                    throw new RuntimeException('stat parent');
                }
                $this->validateDefinitionDirectoryStat($directoryDescriptorStat, $definition);
                $this->validateDefinitionDirectoryStat($directoryNameStat, $definition);
                $this->assertSameInode($directoryDescriptorStat, $directoryNameStat);
                if ($this->operation('fsync', $directoryHandle) !== true) {
                    throw new RuntimeException('sync parent');
                }
            } finally {
                $this->operation('close_dir', $directoryHandle);
            }
            $this->checkpoint('after_parent_fsync');
        } catch (Throwable $exception) {
            if (is_resource($temporaryHandle)) {
                @fclose($temporaryHandle);
            }
            if (!$renamed) {
                $this->cleanupOwnedTemporaryPath($temporaryPath, $temporaryIdentity);
                $this->throwPublic($exception->getMessage() === 'SHARED_FILE_PERMISSION_INVALID'
                    ? 'SHARED_FILE_PERMISSION_INVALID'
                    : 'SHARED_FILE_UNAVAILABLE');
            }

            $this->classifyUncertainVisibleState($definition, $bytes);
            $this->throwPublic('DURABILITY_UNCERTAIN');
        }
    }

    public function withInstanceLock(Closure $callback): mixed
    {
        return $this->withLock(self::INSTANCE_LOCK_PATH, 'INSTANCE_CONFIG_BUSY', $callback);
    }

    public function withUpgradeGateLock(Closure $callback): mixed
    {
        return $this->withLock(self::UPGRADE_GATE_LOCK_PATH, 'UPGRADE_GATE_BUSY', $callback);
    }

    public function withRuntimeRegistryLock(Closure $callback): mixed
    {
        return $this->withLock(self::RUNTIME_REGISTRY_LOCK_PATH, 'RUNTIME_REGISTRY_BUSY', $callback);
    }

    public function withSessionAuthLock(Closure $callback): mixed
    {
        return $this->withLock(self::SESSION_AUTH_LOCK_PATH, 'UPGRADE_SESSION_BUSY', $callback, true);
    }

    public function withDrainCheckpointLock(Closure $callback): mixed
    {
        return $this->withLock(self::UPGRADE_GATE_LOCK_PATH, 'UPGRADE_GATE_BUSY', $callback);
    }

    public function withUpgradeOperationLock(Closure $callback): mixed
    {
        return $this->withLock(self::UPGRADE_OPERATION_LOCK_PATH, 'UPGRADE_OPERATION_BUSY', $callback);
    }

    public function withMigrationRegistryLock(Closure $callback): mixed
    {
        return $this->withLock(self::MIGRATION_REGISTRY_LOCK_PATH, 'UPGRADE_MIGRATION_BUSY', $callback);
    }

    public function withJobControlLock(Closure $callback): mixed
    {
        return $this->withLock(self::JOB_CONTROL_LOCK_PATH, 'UPGRADE_JOB_BUSY', $callback);
    }

    /** @return array{path:string,owner:string,mode:int,directory_mode:int,write:bool} */
    private function drainCheckpointDefinition(string $jobId): array
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $jobId) !== 1) {
            $this->throwPublic('SHARED_FILE_UNAVAILABLE');
        }

        return [
            'path' => 'jobs/drain-' . $jobId . '.json',
            'owner' => 'php',
            'mode' => 0660,
            'directory_mode' => 02770,
            'write' => true,
        ];
    }

    /** @return array{path:string,owner:string,mode:int,directory_mode:int,write:bool,directory_owners:list<int>} */
    private function jobDefinition(string $jobId, string $relative, string $owner): array
    {
        $this->requireUuid($jobId);
        if (!in_array($owner, ['php', 'agent'], true)
            || preg_match('#^(?:request|status|audit)\.json$|^(?:controls|control-results)/[0-9A-Za-z_.-]{1,160}\.json$#D', $relative) !== 1
            || str_contains($relative, '..')) {
            $this->throwPublic('UPGRADE_JOB_ARGUMENT_INVALID');
        }

        return [
            'path' => 'jobs/' . $jobId . '/' . $relative,
            'owner' => $owner,
            'mode' => 0660,
            'directory_mode' => 02770,
            'write' => $owner === 'php',
            'directory_owners' => [$this->agentUid, $this->phpEuid],
        ];
    }

    /** @param array<string,mixed> $definition */
    private function readJobDocument(array $definition): ?object
    {
        try {
            if (!$this->jobDocumentDirectoryExists($definition)) {
                return null;
            }
            $raw = $this->readRawDefinition($definition);

            return $raw === null ? null : $this->decodeStrictObject($raw);
        } catch (Throwable $exception) {
            if (in_array($exception->getMessage(), [
                'UPGRADE_JOB_ARGUMENT_INVALID', 'SHARED_FILE_INVALID',
                'SHARED_FILE_PERMISSION_INVALID',
            ], true)) {
                $this->throwPublic($exception->getMessage());
            }
            $this->throwPublic('UPGRADE_JOB_UNAVAILABLE');
        }
    }

    /** @param array<string,mixed> $definition */
    private function jobDocumentDirectoryExists(array $definition): bool
    {
        $this->validateSharedDirectory('jobs', 02770);
        $parts = explode('/', dirname((string) $definition['path']));
        if (($parts[0] ?? '') !== 'jobs' || count($parts) < 2 || count($parts) > 3) {
            $this->throwPublic('UPGRADE_JOB_ARGUMENT_INVALID');
        }
        $relative = 'jobs';
        for ($index = 1; $index < count($parts); $index++) {
            $relative .= '/' . $parts[$index];
            $path = $this->path($relative);
            $stat = $this->lstat($path);
            if ($stat === null) {
                return false;
            }
            $this->validateDirectoryStatOwners($stat, [$this->agentUid, $this->phpEuid], 02770);
            $canonicalRoot = realpath($this->root);
            $canonicalPath = realpath($path);
            if (!is_string($canonicalRoot) || $canonicalPath !== $canonicalRoot . substr($path, strlen($this->root))) {
                $this->throwPublic('SHARED_FILE_PERMISSION_INVALID');
            }
        }

        return true;
    }

    private function encodeDocument(object $document): string
    {
        try {
            $bytes = json_encode(
                $document,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException) {
            $this->throwPublic('SHARED_FILE_INVALID');
        }
        if (!is_string($bytes) || strlen($bytes) > $this->maxJsonBytes) {
            $this->throwPublic('SHARED_FILE_INVALID');
        }

        return $bytes;
    }

    /** @param array<string,mixed> $value */
    private function toObjectValue(array $value): object
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }

    private function ensureJobDirectory(string $jobId, ?string $child = null): void
    {
        $this->requireUuid($jobId);
        $this->validateSharedDirectory('jobs', 02770);
        $parts = ['jobs/' . $jobId];
        if ($child !== null) {
            if (!in_array($child, ['controls', 'control-results'], true)) {
                $this->throwPublic('UPGRADE_JOB_ARGUMENT_INVALID');
            }
            $parts[] = 'jobs/' . $jobId . '/' . $child;
        }
        foreach ($parts as $relative) {
            $path = $this->path($relative);
            $stat = $this->lstat($path);
            if ($stat === null) {
                if (!@mkdir($path, 02770) || !@chmod($path, 02770)) {
                    $this->throwPublic('UPGRADE_JOB_UNAVAILABLE');
                }
                $stat = $this->lstat($path);
            }
            if ($stat === null) {
                $this->throwPublic('UPGRADE_JOB_UNAVAILABLE');
            }
            $this->validateDirectoryStatOwners($stat, [$this->agentUid, $this->phpEuid], 02770);
            $canonicalRoot = realpath($this->root);
            $canonicalPath = realpath($path);
            $expectedCanonicalPath = is_string($canonicalRoot)
                ? $canonicalRoot . substr($path, strlen($this->root))
                : false;
            if ($canonicalPath === false || $canonicalPath !== $expectedCanonicalPath) {
                $this->throwPublic('SHARED_FILE_PERMISSION_INVALID');
            }
        }
    }

    private function requireUuid(string $value): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) !== 1) {
            $this->throwPublic('UPGRADE_JOB_ARGUMENT_INVALID');
        }
    }

    private function withLock(string $relativePath, string $busyCode, Closure $callback, bool $sharedOwners = false): mixed
    {
        $handle = null;
        $acquired = false;
        try {
            $directory = dirname($relativePath);
            $this->validateSharedDirectory($directory, 02770);
            $path = $this->path($relativePath);
            $deadline = hrtime(true) + $this->lockTimeoutMilliseconds * 1_000_000;
            do {
                $nameStat = $this->lstat($path);
                if ($nameStat === null) {
                    $handle = $this->operation('fopen', $path, 'x+b');
                    if (is_resource($handle)) {
                        if (!@chmod($path, 0660)) {
                            throw new RuntimeException('create lock');
                        }
                        if ($sharedOwners) {
                            $this->durablyPublishSharedLock($handle, $path, $directory);
                            @fclose($handle);
                            $handle = null;
                            $nameStat = null;
                            continue;
                        }
                        $nameStat = $this->lstat($path);
                        break;
                    }

                    usleep(1_000);
                    continue;
                }

                $owners = $sharedOwners ? [$this->phpEuid, $this->agentUid] : [$this->phpEuid];
                if (($nameStat['mode'] & 0170000) !== 0100000 || $nameStat['nlink'] !== 1
                    || !in_array($nameStat['uid'], $owners, true)
                    || $nameStat['gid'] !== $this->expectedGid) {
                    throw new RuntimeException('SHARED_FILE_PERMISSION_INVALID');
                }
                if (($nameStat['mode'] & 07777) !== 0660) {
                    if (hrtime(true) >= $deadline) {
                        throw new RuntimeException('SHARED_FILE_PERMISSION_INVALID');
                    }

                    usleep(1_000);
                    continue;
                }

                $handle = $this->operation('fopen', $path, 'r+b');
                if (!is_resource($handle)) {
                    usleep(1_000);
                }
            } while (!is_resource($handle) && hrtime(true) < $deadline);

            if (!is_resource($handle) || $nameStat === null) {
                throw new RuntimeException('open lock');
            }

            $descriptorStat = $this->operation('fstat', $handle);
            if (!is_array($descriptorStat)) {
                throw new RuntimeException('stat lock');
            }
            $this->validateRegularFileOwners(
                $descriptorStat,
                $sharedOwners ? [$this->phpEuid, $this->agentUid] : [$this->phpEuid],
                0660,
            );
            $this->assertSameInode($descriptorStat, $nameStat);

            do {
                $acquired = $this->operation('flock', $handle, LOCK_EX | LOCK_NB) === true;
                if ($acquired) {
                    break;
                }
                usleep(5_000);
            } while (hrtime(true) < $deadline);

            if (!$acquired) {
                @fclose($handle);
                $this->throwPublic($busyCode);
            }

            $descriptorStat = $this->operation('fstat', $handle);
            $lockedNameStat = $this->lstat($path);
            if (!is_array($descriptorStat) || $lockedNameStat === null) {
                throw new RuntimeException('restat lock');
            }
            $owners = $sharedOwners ? [$this->phpEuid, $this->agentUid] : [$this->phpEuid];
            $this->validateRegularFileOwners($descriptorStat, $owners, 0660);
            $this->validateRegularFileOwners($lockedNameStat, $owners, 0660);
            $this->assertSameInode($descriptorStat, $lockedNameStat);
        } catch (Throwable $exception) {
            if (is_resource($handle)) {
                if ($acquired) {
                    try {
                        $this->operation('flock', $handle, LOCK_UN);
                    } catch (Throwable) {
                    }
                }
                @fclose($handle);
            }
            if (in_array($exception->getMessage(), [$busyCode, 'SHARED_FILE_PERMISSION_INVALID'], true)) {
                $this->throwPublic($exception->getMessage());
            }
            $this->throwPublic('SHARED_FILE_UNAVAILABLE');
        }

        try {
            return $callback();
        } finally {
            try {
                $this->operation('flock', $handle, LOCK_UN);
            } catch (Throwable) {
            }
            @fclose($handle);
        }
    }

    /** @param resource $handle */
    private function durablyPublishSharedLock($handle, string $path, string $directory): void
    {
        $descriptorStat = $this->operation('fstat', $handle);
        $nameStat = $this->lstat($path);
        if (!is_array($descriptorStat) || $nameStat === null) {
            throw new RuntimeException('stat shared lock');
        }
        $owners = [$this->phpEuid, $this->agentUid];
        $this->validateRegularFileOwners($descriptorStat, $owners, 0660);
        $this->validateRegularFileOwners($nameStat, $owners, 0660);
        $this->assertSameInode($descriptorStat, $nameStat);
        if ($this->operation('fflush', $handle) !== true || $this->operation('fsync', $handle) !== true) {
            throw new RuntimeException('sync shared lock');
        }
        $this->checkpoint('after_shared_lock_file_fsync');

        $directoryPath = $this->path($directory);
        $directoryHandle = $this->operation('open_dir', $directoryPath);
        if (!is_resource($directoryHandle)) {
            throw new RuntimeException('open shared lock parent');
        }
        try {
            $directoryDescriptorStat = $this->operation('fstat', $directoryHandle);
            $directoryNameStat = $this->lstat($directoryPath);
            if (!is_array($directoryDescriptorStat) || $directoryNameStat === null) {
                throw new RuntimeException('stat shared lock parent');
            }
            $this->validateDirectoryStat($directoryDescriptorStat, 02770);
            $this->validateDirectoryStat($directoryNameStat, 02770);
            $this->assertSameInode($directoryDescriptorStat, $directoryNameStat);
            if ($this->operation('fsync', $directoryHandle) !== true) {
                throw new RuntimeException('sync shared lock parent');
            }
        } finally {
            $this->operation('close_dir', $directoryHandle);
        }
        $this->checkpoint('after_shared_lock_parent_fsync');
    }

    private function readRawDocument(string $logicalName): ?string
    {
        $definition = self::DOCUMENTS[$logicalName] ?? null;
        if ($definition === null) {
            throw new RuntimeException('SHARED_FILE_UNAVAILABLE');
        }

        return $this->readRawDefinition($definition);
    }

    /** @param array{path:string,owner:string,mode:int,directory_mode:int,write:bool} $definition */
    private function readRawDefinition(array $definition): ?string
    {

        $directory = dirname((string) $definition['path']);
        $this->validateDefinitionDirectory($directory, $definition);
        $path = $this->path((string) $definition['path']);
        $nameStat = $this->lstat($path);
        if ($nameStat === null) {
            return null;
        }
        $expectedOwners = $this->definitionOwners($definition);
        $this->validateRegularFileOwners($nameStat, $expectedOwners, (int) $definition['mode']);

        $handle = $this->operation('fopen', $path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('read file');
        }
        try {
            $descriptorStat = $this->operation('fstat', $handle);
            if (!is_array($descriptorStat)) {
                throw new RuntimeException('stat file');
            }
            $this->validateRegularFileOwners($descriptorStat, $expectedOwners, (int) $definition['mode']);
            $this->assertSameInode($descriptorStat, $nameStat);

            $raw = '';
            while (!feof($handle) && strlen($raw) <= $this->maxJsonBytes) {
                $chunk = fread($handle, min(8192, $this->maxJsonBytes + 1 - strlen($raw)));
                if ($chunk === false) {
                    throw new RuntimeException('read file');
                }
                $raw .= $chunk;
                if ($chunk === '') {
                    break;
                }
            }
            if (strlen($raw) > $this->maxJsonBytes) {
                throw new RuntimeException('SHARED_FILE_INVALID');
            }

            return $raw;
        } finally {
            @fclose($handle);
        }
    }

    /** @return array{path:string,owner:string,mode:int,directory_mode:int,write:bool} */
    private function runtimeDefinition(string $fileName): array
    {
        $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';
        if (preg_match('/^' . $uuid . '-' . $uuid . '-(?:http|queue|cron)\.json$/D', $fileName) !== 1
            || str_contains($fileName, '..')) {
            throw new RuntimeException('SHARED_FILE_UNAVAILABLE');
        }

        return [
            'path' => 'run/runtime-instances/' . $fileName,
            'owner' => 'php',
            'mode' => 0660,
            'directory_mode' => 02770,
            'write' => true,
        ];
    }

    private function decodeStrictObject(string $raw): object
    {
        if ($raw === '' || !mb_check_encoding($raw, 'UTF-8')) {
            throw new RuntimeException('SHARED_FILE_INVALID');
        }
        $offset = 0;
        try {
            $this->parseJsonValue($raw, $offset, 0);
            $this->skipWhitespace($raw, $offset);
            if ($offset !== strlen($raw)) {
                throw new RuntimeException('invalid json');
            }
            $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('SHARED_FILE_INVALID');
        }
        if (!$decoded instanceof stdClass) {
            throw new RuntimeException('SHARED_FILE_INVALID');
        }

        return $decoded;
    }

    private function parseJsonValue(string $json, int &$offset, int $depth): void
    {
        if ($depth > 32) {
            throw new RuntimeException('invalid json');
        }
        $this->skipWhitespace($json, $offset);
        $character = $json[$offset] ?? '';
        if ($character === '{') {
            $this->parseJsonObject($json, $offset, $depth + 1);

            return;
        }
        if ($character === '[') {
            $this->parseJsonArray($json, $offset, $depth + 1);

            return;
        }
        if ($character === '"') {
            $this->parseJsonString($json, $offset);

            return;
        }
        foreach (['true', 'false', 'null'] as $literal) {
            if (substr($json, $offset, strlen($literal)) === $literal) {
                $offset += strlen($literal);

                return;
            }
        }
        if (preg_match('/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', substr($json, $offset), $match) !== 1) {
            throw new RuntimeException('invalid json');
        }
        $offset += strlen($match[0]);
    }

    private function parseJsonObject(string $json, int &$offset, int $depth): void
    {
        $offset++;
        $seen = [];
        $this->skipWhitespace($json, $offset);
        if (($json[$offset] ?? '') === '}') {
            $offset++;

            return;
        }
        while (true) {
            $this->skipWhitespace($json, $offset);
            $key = $this->parseJsonString($json, $offset);
            if (array_key_exists($key, $seen)) {
                throw new RuntimeException('duplicate json key');
            }
            $seen[$key] = true;
            $this->skipWhitespace($json, $offset);
            if (($json[$offset] ?? '') !== ':') {
                throw new RuntimeException('invalid json');
            }
            $offset++;
            $this->parseJsonValue($json, $offset, $depth);
            $this->skipWhitespace($json, $offset);
            $delimiter = $json[$offset] ?? '';
            if ($delimiter === '}') {
                $offset++;

                return;
            }
            if ($delimiter !== ',') {
                throw new RuntimeException('invalid json');
            }
            $offset++;
        }
    }

    private function parseJsonArray(string $json, int &$offset, int $depth): void
    {
        $offset++;
        $this->skipWhitespace($json, $offset);
        if (($json[$offset] ?? '') === ']') {
            $offset++;

            return;
        }
        while (true) {
            $this->parseJsonValue($json, $offset, $depth);
            $this->skipWhitespace($json, $offset);
            $delimiter = $json[$offset] ?? '';
            if ($delimiter === ']') {
                $offset++;

                return;
            }
            if ($delimiter !== ',') {
                throw new RuntimeException('invalid json');
            }
            $offset++;
        }
    }

    private function parseJsonString(string $json, int &$offset): string
    {
        if (($json[$offset] ?? '') !== '"') {
            throw new RuntimeException('invalid json');
        }
        $start = $offset++;
        $length = strlen($json);
        while ($offset < $length) {
            $character = $json[$offset++];
            if ($character === '\\') {
                if ($offset >= $length) {
                    throw new RuntimeException('invalid json');
                }
                $offset++;
                continue;
            }
            if ($character === '"') {
                $encoded = substr($json, $start, $offset - $start);
                $decoded = json_decode($encoded, false, 512, JSON_THROW_ON_ERROR);
                if (!is_string($decoded)) {
                    throw new RuntimeException('invalid json');
                }

                return $decoded;
            }
        }
        throw new RuntimeException('invalid json');
    }

    private function skipWhitespace(string $json, int &$offset): void
    {
        $length = strlen($json);
        while ($offset < $length && str_contains(" \t\r\n", $json[$offset])) {
            $offset++;
        }
    }

    private function validateSharedDirectory(string $relativePath, int $mode): void
    {
        $this->validateDirectory($this->root, 0750);
        if ($relativePath !== '') {
            $this->validateDirectory($this->path($relativePath), $mode);
        }
    }

    private function validateDirectory(string $path, int $mode): void
    {
        $stat = $this->lstat($path);
        if ($stat === null) {
            throw new RuntimeException('SHARED_FILE_PERMISSION_INVALID');
        }
        $this->validateDirectoryStat($stat, $mode);
    }

    /** @param array<string|int, int> $stat */
    private function validateDirectoryStat(array $stat, int $mode): void
    {
        $this->validateDirectoryStatOwners($stat, [$this->agentUid], $mode);
    }

    /** @param array<string|int, int> $stat @param list<int> $owners */
    private function validateDirectoryStatOwners(array $stat, array $owners, int $mode): void
    {
        if (($stat['mode'] & 0170000) !== 0040000
            || !in_array($stat['uid'], $owners, true) || $stat['gid'] !== $this->expectedGid
            || ($stat['mode'] & 07777) !== $mode) {
            throw new RuntimeException('SHARED_FILE_PERMISSION_INVALID');
        }
    }

    /** @param array<string,mixed> $definition */
    private function validateDefinitionDirectory(string $relativePath, array $definition): void
    {
        $this->validateDirectory($this->root, 0750);
        if ($relativePath === '') {
            return;
        }
        $stat = $this->lstat($this->path($relativePath));
        if ($stat === null) {
            throw new RuntimeException('SHARED_FILE_PERMISSION_INVALID');
        }
        $this->validateDefinitionDirectoryStat($stat, $definition);
    }

    /** @param array<string|int,int> $stat @param array<string,mixed> $definition */
    private function validateDefinitionDirectoryStat(array $stat, array $definition): void
    {
        $owners = $definition['directory_owners'] ?? [$this->agentUid];
        if (!is_array($owners) || $owners === []) {
            throw new RuntimeException('SHARED_FILE_PERMISSION_INVALID');
        }
        $this->validateDirectoryStatOwners($stat, $owners, (int) $definition['directory_mode']);
    }

    /** @param array<string|int, int> $stat */
    private function validateRegularFile(array $stat, int $owner, int $mode): void
    {
        $this->validateRegularFileOwners($stat, [$owner], $mode);
    }

    /** @param array<string|int, int> $stat @param list<int> $owners */
    private function validateRegularFileOwners(array $stat, array $owners, int $mode): void
    {
        if (($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1
            || !in_array($stat['uid'], $owners, true) || $stat['gid'] !== $this->expectedGid
            || ($stat['mode'] & 07777) !== $mode) {
            throw new RuntimeException('SHARED_FILE_PERMISSION_INVALID');
        }
    }

    /** @param array{path:string,owner:string,mode:int,directory_mode:int,write:bool} $definition */
    private function validateDefinitionFile(array $stat, array $definition): void
    {
        $this->validateRegularFileOwners($stat, $this->definitionOwners($definition), (int) $definition['mode']);
    }

    /** @param array{path:string,owner:string,mode:int,directory_mode:int,write:bool} $definition @return list<int> */
    private function definitionOwners(array $definition): array
    {
        return match ($definition['owner']) {
            'agent' => [$this->agentUid],
            'shared' => [$this->phpEuid, $this->agentUid],
            default => [$this->phpEuid],
        };
    }

    /**
     * @param array<string|int, int> $left
     * @param array<string|int, int> $right
     */
    private function assertSameInode(array $left, array $right): void
    {
        if ($left['dev'] !== $right['dev'] || $left['ino'] !== $right['ino']) {
            throw new RuntimeException('SHARED_FILE_PERMISSION_INVALID');
        }
    }

    /** @return array<string|int, int>|null */
    private function lstat(string $path): ?array
    {
        clearstatcache(true, $path);
        $stat = $this->operation('lstat', $path);
        if (is_array($stat)) {
            return $stat;
        }
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('SHARED_FILE_UNAVAILABLE');
        }

        return null;
    }

    /** @param array{path:string,owner:string,mode:int,directory_mode:int,write:bool} $definition */
    private function classifyUncertainVisibleState(array $definition, string $intendedBytes): void
    {
        $checkpoint = 'uncertain_reread_missing';
        try {
            $visible = $this->readRawDefinition($definition);
            if ($visible !== null) {
                $checkpoint = hash_equals($intendedBytes, $visible)
                    ? 'uncertain_reread_match'
                    : 'uncertain_reread_mismatch';
            }
        } catch (Throwable) {
            $checkpoint = file_exists($this->path($definition['path']))
                ? 'uncertain_reread_mismatch'
                : 'uncertain_reread_missing';
        }
        try {
            $this->checkpoint($checkpoint);
        } catch (Throwable) {
        }
    }

    /** @param array<string|int, int>|null $identity */
    private function cleanupOwnedTemporaryPath(string $path, ?array $identity): void
    {
        if ($path === '' || $identity === null) {
            return;
        }
        try {
            $current = $this->operation('lstat', $path);
            if (!is_array($current)) {
                return;
            }
            $this->validateRegularFile($current, $this->phpEuid, 0660);
            $this->assertSameInode($identity, $current);
        } catch (Throwable) {
            return;
        }
        @unlink($path);
    }

    private function checkpoint(string $name): void
    {
        $this->operation('fault', $name);
    }

    private function path(string $relativePath): string
    {
        return rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    private function operation(string $name, mixed ...$arguments): mixed
    {
        return ($this->operations[$name])(...$arguments);
    }

    /** @return array<string, callable> */
    private function nativeOperations(): array
    {
        return [
            'lstat' => static fn(string $path): array|false => @lstat($path),
            'fstat' => static fn($handle): array|false => @fstat($handle),
            'fopen' => static fn(string $path, string $mode) => @fopen($path, $mode),
            'fwrite' => static fn($handle, string $bytes): int|false => @fwrite($handle, $bytes),
            'fflush' => static fn($handle): bool => @fflush($handle),
            'fsync' => static fn($handle): bool => @fsync($handle),
            'rename' => static fn(string $from, string $to): bool => @rename($from, $to),
            'flock' => static fn($handle, int $operation): bool => @flock($handle, $operation),
            'open_dir' => static fn(string $path) => @fopen($path, 'r'),
            'close_dir' => static fn($handle): bool => @fclose($handle),
            'fault' => static fn(string $checkpoint): null => null,
        ];
    }

    private function throwPublic(string $code): never
    {
        throw new RuntimeException($code);
    }
}
