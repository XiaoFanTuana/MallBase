<?php

declare(strict_types=1);

namespace app\service\upgrade;

use RuntimeException;
use Throwable;

/** No-follow verification of fixed persistent artifact roots after deployment. */
final readonly class PersistentStateVerificationService
{
    private const ARTIFACTS = [
        'install', 'local_storage', 'runtime_backup', 'public_storage',
        'uploads', 'cert', 'demo',
    ];

    /** @param array<string,string> $roots */
    public function __construct(private UpgradeOperationStore $operations, private array $roots)
    {
        if (array_keys($this->roots) !== self::ARTIFACTS) {
            throw new RuntimeException('UPGRADE_PERSISTENT_ROOT_CONFIG_INVALID');
        }
        foreach ($this->roots as $root) {
            if (!is_string($root) || !str_starts_with($root, '/') || realpath($root) !== $root) {
                throw new RuntimeException('UPGRADE_PERSISTENT_ROOT_CONFIG_INVALID');
            }
        }
    }

    /**
     * Captures the drained source baseline without exposing configured paths.
     *
     * @return list<array{artifact:string,merkle_root:string,count:int,file_mode:int,directory_mode:int,receipt_checksum:string}>
     */
    public function capture(string $jobId, int $fileMode = 0660, int $directoryMode = 0770): array
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $jobId) !== 1
            || $fileMode < 0 || $fileMode > 0777 || $directoryMode < 0 || $directoryMode > 0777) {
            throw new RuntimeException('UPGRADE_PERSISTENT_VERIFY_ARGUMENT_INVALID');
        }
        $captures = [];
        foreach (self::ARTIFACTS as $artifact) {
            [$merkleRoot, $count] = $this->scan($this->roots[$artifact], $fileMode, $directoryMode);
            $captures[] = [
                'artifact' => $artifact,
                'merkle_root' => $merkleRoot,
                'count' => $count,
                'file_mode' => $fileMode,
                'directory_mode' => $directoryMode,
                'receipt_checksum' => $this->receiptChecksum(
                    $jobId,
                    $artifact,
                    $merkleRoot,
                    $count,
                    $fileMode,
                    $directoryMode,
                ),
            ];
        }

        return $captures;
    }

    /** @return array<string,mixed> */
    public function verify(
        string $jobId,
        string $artifact,
        string $expectedMerkleRoot,
        int $expectedCount,
        int $expectedFileMode,
        int $expectedDirectoryMode,
        string $targetIdentity,
        string $receiptChecksum,
        string $runtimeId,
        string $bootId,
        int $now,
        string $attempt = '',
    ): array {
        if (!in_array($artifact, self::ARTIFACTS, true)
            || preg_match('/^sha256:[0-9a-f]{64}$/D', $expectedMerkleRoot) !== 1
            || preg_match('/^sha256:[0-9a-f]{64}$/D', $receiptChecksum) !== 1
            || $expectedCount < 0 || $expectedCount > 1_000_000
            || $expectedFileMode < 0 || $expectedFileMode > 0777
            || $expectedDirectoryMode < 0 || $expectedDirectoryMode > 0777
            || preg_match('/^[0-9A-Za-z_.:-]{1,255}$/D', $targetIdentity) !== 1) {
            throw new RuntimeException('UPGRADE_PERSISTENT_VERIFY_ARGUMENT_INVALID');
        }
        $expectedReceipt = $this->receiptChecksum(
            $jobId,
            $artifact,
            $expectedMerkleRoot,
            $expectedCount,
            $expectedFileMode,
            $expectedDirectoryMode,
        );
        if (!hash_equals($expectedReceipt, $receiptChecksum)) {
            throw new RuntimeException('UPGRADE_PERSISTENT_VERIFY_ARGUMENT_INVALID');
        }
        $attempt = UpgradeOperationAttempt::normalize($attempt);
        $inputChecksum = 'sha256:' . hash('sha256', json_encode([
            $artifact, $expectedMerkleRoot, $expectedCount, $expectedFileMode,
            $expectedDirectoryMode, $targetIdentity, $receiptChecksum, $attempt,
        ], JSON_THROW_ON_ERROR));
        $action = UpgradeOperationAttempt::action('verify_' . $artifact, $attempt);
        $operation = $this->operations->claim(
            $jobId,
            $action,
            $inputChecksum,
            $runtimeId,
            $bootId,
            $now,
        );
        if ($operation['state'] !== 'running') {
            return $this->publicResult($operation, $artifact);
        }

        try {
            [$root, $count] = $this->scan(
                $this->roots[$artifact],
                $expectedFileMode,
                $expectedDirectoryMode,
            );
            if ($count !== $expectedCount || !hash_equals($expectedMerkleRoot, $root)) {
                $failed = $this->operations->fail(
                    $operation['operation_id'],
                    $bootId,
                    'UPGRADE_PERSISTENT_STATE_MISMATCH',
                    $now,
                );

                return $this->publicResult($failed, $artifact);
            }
            $completed = $this->operations->complete($operation['operation_id'], $bootId, [
                'artifact' => $artifact,
                'merkle_root' => $root,
                'count' => $count,
            ], $now);

            return $this->publicResult($completed, $artifact);
        } catch (Throwable $exception) {
            $code = preg_match('/^[A-Z0-9_]{1,64}$/D', $exception->getMessage()) === 1
                ? $exception->getMessage()
                : 'UPGRADE_PERSISTENT_STATE_UNAVAILABLE';
            $failed = $this->operations->fail($operation['operation_id'], $bootId, $code, $now);

            return $this->publicResult($failed, $artifact);
        }
    }

    /** @return array{string,int} */
    public function scan(string $root, int $fileMode, int $directoryMode): array
    {
        $before = @lstat($root);
        if (!$this->directoryStat($before) || realpath($root) !== $root
            || (($before['mode'] ?? 0) & 0777) !== $directoryMode) {
            throw new RuntimeException('UPGRADE_PERSISTENT_STATE_UNAVAILABLE');
        }
        $entries = [];
        $this->walk($root, '', $fileMode, $directoryMode, $entries);
        if (count($entries) > 1_000_000) {
            throw new RuntimeException('UPGRADE_PERSISTENT_STATE_LIMIT_REACHED');
        }
        ksort($entries, SORT_STRING);
        $hash = hash_init('sha256');
        foreach ($entries as $path => $value) {
            hash_update($hash, pack('N', strlen($path)) . $path . pack('N', strlen($value)) . $value);
        }
        $after = @lstat($root);
        foreach (['dev', 'ino', 'mode', 'uid', 'gid'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                throw new RuntimeException('UPGRADE_PERSISTENT_STATE_CHANGED');
            }
        }

        return ['sha256:' . hash_final($hash), count($entries)];
    }

    /** @param array<string,string> $entries */
    private function walk(string $root, string $relative, int $fileMode, int $directoryMode, array &$entries): void
    {
        $directory = $relative === '' ? $root : $root . '/' . $relative;
        $names = @scandir($directory);
        if (!is_array($names) || count($names) > 100002) {
            throw new RuntimeException('UPGRADE_PERSISTENT_STATE_UNAVAILABLE');
        }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if ($name === '' || str_contains($name, '/') || str_contains($name, "\0")) {
                throw new RuntimeException('UPGRADE_PERSISTENT_STATE_UNAVAILABLE');
            }
            $path = $relative === '' ? $name : $relative . '/' . $name;
            $absolute = $root . '/' . $path;
            $stat = @lstat($absolute);
            if (!is_array($stat)) {
                throw new RuntimeException('UPGRADE_PERSISTENT_STATE_CHANGED');
            }
            $kind = $stat['mode'] & 0170000;
            if ($kind === 0040000) {
                if (($stat['mode'] & 0777) !== $directoryMode || realpath($absolute) !== $absolute) {
                    throw new RuntimeException('UPGRADE_PERSISTENT_STATE_MODE_MISMATCH');
                }
                $entries[$path] = 'd:' . decoct($directoryMode);
                $this->walk($root, $path, $fileMode, $directoryMode, $entries);
                continue;
            }
            if ($kind !== 0100000 || ($stat['mode'] & 0777) !== $fileMode || ($stat['nlink'] ?? 0) !== 1) {
                throw new RuntimeException('UPGRADE_PERSISTENT_STATE_MODE_MISMATCH');
            }
            $handle = @fopen($absolute, 'rb');
            if (!is_resource($handle)) {
                throw new RuntimeException('UPGRADE_PERSISTENT_STATE_CHANGED');
            }
            try {
                $opened = fstat($handle);
                if (!is_array($opened) || ($opened['dev'] ?? null) !== ($stat['dev'] ?? null)
                    || ($opened['ino'] ?? null) !== ($stat['ino'] ?? null)
                    || ($opened['size'] ?? null) !== ($stat['size'] ?? null)) {
                    throw new RuntimeException('UPGRADE_PERSISTENT_STATE_CHANGED');
                }
                $hash = hash_init('sha256');
                hash_update_stream($hash, $handle);
                $after = fstat($handle);
                if (!is_array($after) || ($after['size'] ?? null) !== ($opened['size'] ?? null)
                    || ($after['mtime'] ?? null) !== ($opened['mtime'] ?? null)) {
                    throw new RuntimeException('UPGRADE_PERSISTENT_STATE_CHANGED');
                }
                $entries[$path] = 'f:' . decoct($fileMode) . ':' . (int) $stat['size'] . ':' . hash_final($hash);
            } finally {
                fclose($handle);
            }
        }
    }

    private function directoryStat(array|false $stat): bool
    {
        return is_array($stat) && ($stat['mode'] & 0170000) === 0040000;
    }

    private function receiptChecksum(
        string $jobId,
        string $artifact,
        string $merkleRoot,
        int $count,
        int $fileMode,
        int $directoryMode,
    ): string {
        return 'sha256:' . hash('sha256', json_encode([
            'mallbase-persistent-baseline-v1',
            strtolower($jobId),
            $artifact,
            $merkleRoot,
            $count,
            $fileMode,
            $directoryMode,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string,mixed> $operation @return array<string,mixed> */
    private function publicResult(array $operation, string $artifact): array
    {
        return [
            'operation_id' => $operation['operation_id'],
            'artifact' => $artifact,
            'state' => $operation['state'],
            'result' => $operation['result'],
            'error_code' => $operation['error_code'],
            'revision' => $operation['revision'],
        ];
    }
}
