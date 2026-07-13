<?php

declare(strict_types=1);

namespace app\service\upgrade;

use RuntimeException;
use stdClass;

/** Durable idempotency state for long Agent-triggered PHP operations. */
final readonly class UpgradeOperationStore
{
    private const STATES = ['running', 'completed', 'failed', 'recovery_blocked'];

    public function __construct(
        private UpgradeSharedFileStore $files,
        private int $ownerWindowSeconds = 15,
    ) {
        if ($this->ownerWindowSeconds < 2 || $this->ownerWindowSeconds > 300) {
            throw new RuntimeException('UPGRADE_OPERATION_CONFIG_INVALID');
        }
    }

    public function operationId(string $jobId, string $action, string $inputChecksum): string
    {
        if (!$this->uuid($jobId) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $action) !== 1
            || preg_match('/^sha256:[0-9a-f]{64}$/D', $inputChecksum) !== 1) {
            throw new RuntimeException('UPGRADE_OPERATION_ARGUMENT_INVALID');
        }
        // The input checksum remains part of the immutable operation record,
        // but the public operation ID is derivable by both Agent and PHP from
        // job + action so a lost POST response can always poll the same work.
        $bytes = substr(hash('sha256', "mallbase-upgrade-operation-v1\0{$jobId}\0{$action}", true), 0, 16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    /** @param array<string,mixed> $operation */
    public function assertMatches(array $operation, string $jobId, string $action, string $inputChecksum): void
    {
        $operationId = $this->operationId($jobId, $action, $inputChecksum);
        if (($operation['operation_id'] ?? null) !== $operationId
            || ($operation['job_id'] ?? null) !== $jobId
            || ($operation['action'] ?? null) !== $action
            || ($operation['input_checksum'] ?? null) !== $inputChecksum) {
            throw new RuntimeException('UPGRADE_OPERATION_CONFLICT');
        }
    }

    /** @return array<string, mixed> */
    public function claim(
        string $jobId,
        string $action,
        string $inputChecksum,
        string $ownerRuntimeId,
        string $ownerBootId,
        int $now,
        bool $staleOwnerLockProvenReleased = false,
    ): array {
        $operationId = $this->operationId($jobId, $action, $inputChecksum);
        $this->validateOwner($ownerRuntimeId, $ownerBootId, $now);

        return $this->files->withUpgradeOperationLock(function () use (
            $operationId,
            $jobId,
            $action,
            $inputChecksum,
            $ownerRuntimeId,
            $ownerBootId,
            $now,
            $staleOwnerLockProvenReleased,
        ): array {
            $document = $this->loadUnlocked();
            $existing = $document['operations'][$operationId] ?? null;
            if (is_array($existing)) {
                if ($existing['job_id'] !== $jobId || $existing['action'] !== $action
                    || $existing['input_checksum'] !== $inputChecksum) {
                    throw new RuntimeException('UPGRADE_OPERATION_CONFLICT');
                }
                if ($existing['state'] === 'completed' || $existing['state'] === 'failed'
                    || $existing['state'] === 'recovery_blocked') {
                    return $existing;
                }
                if ($existing['heartbeat_at'] + $this->ownerWindowSeconds >= $now) {
                    return $existing;
                }
                if (!$staleOwnerLockProvenReleased) {
                    $existing['state'] = 'recovery_blocked';
                    $existing['error_code'] = 'UPGRADE_OPERATION_OWNER_UNCERTAIN';
                    $existing['updated_at'] = $now;
                    $existing['revision']++;
                    $document['operations'][$operationId] = $existing;
                    $document['revision']++;
                    $this->write($document);

                    return $existing;
                }
                $existing['owner_runtime_id'] = $ownerRuntimeId;
                $existing['owner_boot_id'] = $ownerBootId;
                $existing['owner_pid'] = getmypid();
                $existing['heartbeat_at'] = $now;
                $existing['revision']++;
                $document['operations'][$operationId] = $existing;
                $document['revision']++;
                $this->write($document);

                return $existing;
            }
            if (count($document['operations']) >= 1000) {
                throw new RuntimeException('UPGRADE_OPERATION_LIMIT_REACHED');
            }
            $operation = [
                'operation_id' => $operationId,
                'job_id' => $jobId,
                'action' => $action,
                'input_checksum' => $inputChecksum,
                'state' => 'running',
                'owner_runtime_id' => $ownerRuntimeId,
                'owner_boot_id' => $ownerBootId,
                'owner_pid' => getmypid(),
                'heartbeat_at' => $now,
                'result_checksum' => null,
                'result' => null,
                'error_code' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'revision' => 1,
            ];
            $document['operations'][$operationId] = $operation;
            ksort($document['operations'], SORT_STRING);
            $document['revision']++;
            $this->write($document);

            return $operation;
        });
    }

    /** @return array<string, mixed> */
    public function heartbeat(string $operationId, string $ownerBootId, int $now): array
    {
        return $this->mutateOwned($operationId, $ownerBootId, $now, static function (array $operation) use ($now): array {
            $operation['heartbeat_at'] = $now;

            return $operation;
        });
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    public function complete(string $operationId, string $ownerBootId, array $result, int $now): array
    {
        $encoded = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || strlen($encoded) > 65536) {
            throw new RuntimeException('UPGRADE_OPERATION_RESULT_INVALID');
        }

        return $this->mutateOwned($operationId, $ownerBootId, $now, static function (array $operation) use ($result, $encoded, $now): array {
            $operation['state'] = 'completed';
            $operation['result_checksum'] = 'sha256:' . hash('sha256', $encoded);
            $operation['result'] = $result;
            $operation['error_code'] = null;
            $operation['heartbeat_at'] = $now;

            return $operation;
        });
    }

    /** @return array<string, mixed> */
    public function fail(string $operationId, string $ownerBootId, string $errorCode, int $now, bool $blocked = false): array
    {
        if (preg_match('/^[A-Z0-9_]{1,64}$/D', $errorCode) !== 1) {
            throw new RuntimeException('UPGRADE_OPERATION_RESULT_INVALID');
        }

        return $this->mutateOwned($operationId, $ownerBootId, $now, static function (array $operation) use ($errorCode, $now, $blocked): array {
            $operation['state'] = $blocked ? 'recovery_blocked' : 'failed';
            $operation['result_checksum'] = null;
            $operation['result'] = null;
            $operation['error_code'] = $errorCode;
            $operation['heartbeat_at'] = $now;

            return $operation;
        });
    }

    /** @return array<string, mixed>|null */
    public function get(string $operationId): ?array
    {
        if (!$this->uuid($operationId)) {
            throw new RuntimeException('UPGRADE_OPERATION_ARGUMENT_INVALID');
        }

        return $this->files->withUpgradeOperationLock(
            fn(): ?array => $this->loadUnlocked()['operations'][$operationId] ?? null,
        );
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $mutator @return array<string,mixed> */
    private function mutateOwned(string $operationId, string $ownerBootId, int $now, callable $mutator): array
    {
        if (!$this->uuid($operationId) || !$this->safeIdentity($ownerBootId) || !$this->timestamp($now)) {
            throw new RuntimeException('UPGRADE_OPERATION_ARGUMENT_INVALID');
        }

        return $this->files->withUpgradeOperationLock(function () use ($operationId, $ownerBootId, $now, $mutator): array {
            $document = $this->loadUnlocked();
            $operation = $document['operations'][$operationId] ?? null;
            if (!is_array($operation) || $operation['state'] !== 'running'
                || !hash_equals($operation['owner_boot_id'], $ownerBootId)) {
                throw new RuntimeException('UPGRADE_OPERATION_CONFLICT');
            }
            $operation = $mutator($operation);
            $operation['updated_at'] = $now;
            $operation['revision']++;
            $document['operations'][$operationId] = $operation;
            $document['revision']++;
            $this->write($document);

            return $operation;
        });
    }

    /** @return array{schema_version:int,revision:int,operations:array<string,array<string,mixed>>} */
    private function loadUnlocked(): array
    {
        $document = $this->files->readJson('upgrade_operations');
        if ($document === null) {
            return ['schema_version' => 1, 'revision' => 0, 'operations' => []];
        }
        $raw = get_object_vars($document);
        if (array_keys($raw) !== ['schema_version', 'revision', 'operations']
            || $raw['schema_version'] !== 1 || !is_int($raw['revision']) || $raw['revision'] < 0
            || !$raw['operations'] instanceof stdClass) {
            throw new RuntimeException('UPGRADE_OPERATION_STATE_INVALID');
        }
        $operations = [];
        foreach (get_object_vars($raw['operations']) as $id => $value) {
            if (!is_string($id) || !$this->uuid($id) || !$value instanceof stdClass) {
                throw new RuntimeException('UPGRADE_OPERATION_STATE_INVALID');
            }
            $operation = get_object_vars($value);
            $expected = [
                'operation_id', 'job_id', 'action', 'input_checksum', 'state',
                'owner_runtime_id', 'owner_boot_id', 'owner_pid', 'heartbeat_at',
                'result_checksum', 'result', 'error_code', 'created_at', 'updated_at', 'revision',
            ];
            if (array_keys($operation) !== $expected || $operation['operation_id'] !== $id
                || !$this->uuid((string) $operation['job_id'])
                || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', (string) $operation['action']) !== 1
                || preg_match('/^sha256:[0-9a-f]{64}$/D', (string) $operation['input_checksum']) !== 1
                || !in_array($operation['state'], self::STATES, true)
                || !$this->safeIdentity($operation['owner_runtime_id'])
                || !$this->safeIdentity($operation['owner_boot_id'])
                || !is_int($operation['owner_pid']) || $operation['owner_pid'] < 1
                || !$this->timestamp($operation['heartbeat_at'])
                || !($operation['result_checksum'] === null || preg_match('/^sha256:[0-9a-f]{64}$/D', $operation['result_checksum']) === 1)
                || !($operation['error_code'] === null || preg_match('/^[A-Z0-9_]{1,64}$/D', $operation['error_code']) === 1)
                || !$this->timestamp($operation['created_at']) || !$this->timestamp($operation['updated_at'])
                || !is_int($operation['revision']) || $operation['revision'] < 1) {
                throw new RuntimeException('UPGRADE_OPERATION_STATE_INVALID');
            }
            $operation['result'] = $this->normalizeJsonValue($operation['result']);
            $operations[$id] = $operation;
        }

        return ['schema_version' => 1, 'revision' => $raw['revision'], 'operations' => $operations];
    }

    /** @param array<string, mixed> $document */
    private function write(array $document): void
    {
        $this->files->writeJson('upgrade_operations', $this->toObject($document));
    }

    private function normalizeJsonValue(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $result = [];
            foreach (get_object_vars($value) as $key => $item) {
                $result[$key] = $this->normalizeJsonValue($item);
            }

            return $result;
        }
        if (is_array($value)) {
            return array_map($this->normalizeJsonValue(...), $value);
        }

        return $value;
    }

    private function toObject(array $value): object
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }

    private function validateOwner(string $runtimeId, string $bootId, int $now): void
    {
        if (!$this->safeIdentity($runtimeId) || !$this->safeIdentity($bootId) || !$this->timestamp($now)) {
            throw new RuntimeException('UPGRADE_OPERATION_ARGUMENT_INVALID');
        }
    }

    private function safeIdentity(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9A-Za-z_.:-]{1,128}$/D', $value) === 1;
    }

    private function uuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }

    private function timestamp(mixed $value): bool
    {
        return is_int($value) && $value >= 0 && $value <= 4_102_444_800;
    }
}
