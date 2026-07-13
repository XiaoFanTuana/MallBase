<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Closure;
use RuntimeException;
use Throwable;

/** Streams a deterministic database dump to host-persistent shared backup storage. */
final readonly class DatabaseBackupService
{
    /** @var Closure():int */
    private Closure $clock;

    /** @param array{host:string,port:int,user:string,password:string,database:string} $database */
    public function __construct(
        private UpgradeOperationStore $operations,
        private UpgradeProcessSupervisor $processes,
        private string $backupRoot,
        private string $dumpExecutable,
        private array $database,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
        if (!$this->absolute($this->backupRoot) || !$this->absolute($this->dumpExecutable)
            || !$this->validDatabase($this->database)) {
            throw new RuntimeException('UPGRADE_BACKUP_CONFIG_INVALID');
        }
    }

    /** @return array<string, mixed> */
    public function backup(string $jobId, string $runtimeId, string $bootId, string $attempt = ''): array
    {
        $action = UpgradeOperationAttempt::action('backup_database', $attempt);
        $inputChecksum = $this->inputChecksum($attempt);
        $operationId = $this->operations->operationId($jobId, $action, $inputChecksum);
        $clock = $this->clock;
        $now = $clock();
        $existing = $this->operations->get($operationId);
        if (is_array($existing)) {
            $this->operations->assertMatches($existing, $jobId, $action, $inputChecksum);
        }
        $directory = $this->jobDirectory($jobId);
        $temporary = $directory . '/database.sql.tmp';
        $final = $directory . '/database.sql';
        $lock = $directory . '/database.lock';
        $staleOwnerReleased = is_array($existing)
            && $existing['state'] === 'running'
            && $existing['heartbeat_at'] + 15 < $now
            && $this->processes->lockReleased($lock);
        $operation = $this->operations->claim(
            $jobId,
            $action,
            $inputChecksum,
            $runtimeId,
            $bootId,
            $now,
            $staleOwnerReleased,
        );
        if ($operation['state'] !== 'running') {
            return $this->publicResult($operation);
        }
        if (is_array($existing) && $existing['state'] === 'running'
            && $existing['heartbeat_at'] + 15 >= $now) {
            return $this->publicResult($existing);
        }

        if (is_file($final)) {
            return $this->completeFromFile($operationId, $bootId, $final, $clock());
        }
        $this->removeStaleTemporary($temporary);

        try {
            $result = $this->processes->run(
                $lock,
                $this->dumpExecutable,
                $this->arguments(),
                $temporary,
                ['MYSQL_PWD' => $this->database['password']],
                function () use ($operationId, $bootId, $clock): void {
                    $this->operations->heartbeat($operationId, $bootId, $clock());
                },
            );
            if ($result['state'] === 'running') {
                return $this->publicResult($operation);
            }
            if ($result['state'] !== 'completed' || $result['exit_code'] !== 0) {
                $this->removeStaleTemporary($temporary);
                $failed = $this->operations->fail(
                    $operationId,
                    $bootId,
                    'UPGRADE_DATABASE_BACKUP_FAILED',
                    $clock(),
                );

                return $this->publicResult($failed);
            }
            $this->promote($temporary, $final);

            return $this->completeFromFile($operationId, $bootId, $final, $clock());
        } catch (Throwable $exception) {
            try {
                $failed = $this->operations->fail(
                    $operationId,
                    $bootId,
                    $exception->getMessage() === 'UPGRADE_PROCESS_GUARD_UNAVAILABLE'
                        ? 'UPGRADE_PROCESS_GUARD_UNAVAILABLE'
                        : 'UPGRADE_DATABASE_BACKUP_FAILED',
                    $clock(),
                    $exception->getMessage() === 'UPGRADE_PROCESS_GUARD_UNAVAILABLE',
                );

                return $this->publicResult($failed);
            } catch (Throwable) {
                throw new RuntimeException('UPGRADE_DATABASE_BACKUP_RECOVERY_BLOCKED', 0, $exception);
            }
        }
    }

    public function operationId(string $jobId, string $attempt = ''): string
    {
        return $this->operations->operationId(
            $jobId,
            UpgradeOperationAttempt::action('backup_database', $attempt),
            $this->inputChecksum($attempt),
        );
    }

    /** @return array<string,mixed> */
    private function completeFromFile(string $operationId, string $bootId, string $path, int $now): array
    {
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000
            || ($stat['nlink'] ?? 0) !== 1 || ($stat['size'] ?? 0) < 1) {
            throw new RuntimeException('UPGRADE_DATABASE_BACKUP_INVALID');
        }
        $checksum = hash_file('sha256', $path);
        if (!is_string($checksum)) {
            throw new RuntimeException('UPGRADE_DATABASE_BACKUP_INVALID');
        }
        $completed = $this->operations->complete($operationId, $bootId, [
            'file_name' => 'database.sql',
            'size' => (int) $stat['size'],
            'checksum' => 'sha256:' . $checksum,
        ], $now);

        return $this->publicResult($completed);
    }

    private function jobDirectory(string $jobId): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $jobId) !== 1) {
            throw new RuntimeException('UPGRADE_BACKUP_ARGUMENT_INVALID');
        }
        if (!is_dir($this->backupRoot) || realpath($this->backupRoot) !== $this->backupRoot) {
            throw new RuntimeException('UPGRADE_BACKUP_STORAGE_UNAVAILABLE');
        }
        $directory = $this->backupRoot . '/' . $jobId;
        if (!is_dir($directory)) {
            if (!mkdir($directory, 02770) || !chmod($directory, 02770)) {
                throw new RuntimeException('UPGRADE_BACKUP_STORAGE_UNAVAILABLE');
            }
        }
        $stat = @lstat($directory);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000
            || ($stat['mode'] & 07777) !== 02770 || realpath($directory) !== $directory) {
            throw new RuntimeException('UPGRADE_BACKUP_STORAGE_UNAVAILABLE');
        }

        return $directory;
    }

    private function promote(string $temporary, string $final): void
    {
        $handle = @fopen($temporary, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('UPGRADE_DATABASE_BACKUP_INVALID');
        }
        try {
            $stat = fstat($handle);
            $named = @lstat($temporary);
            if (!is_array($stat) || !is_array($named) || ($stat['mode'] & 0170000) !== 0100000
                || ($stat['nlink'] ?? 0) !== 1 || ($stat['size'] ?? 0) < 1
                || ($stat['dev'] ?? null) !== ($named['dev'] ?? null)
                || ($stat['ino'] ?? null) !== ($named['ino'] ?? null)
                || !fsync($handle)) {
                throw new RuntimeException('UPGRADE_DATABASE_BACKUP_INVALID');
            }
        } finally {
            fclose($handle);
        }
        if (!chmod($temporary, 0660) || !rename($temporary, $final)) {
            throw new RuntimeException('UPGRADE_DATABASE_BACKUP_INVALID');
        }
        $directory = fopen(dirname($final), 'rb');
        if (!is_resource($directory)) {
            throw new RuntimeException('UPGRADE_DATABASE_BACKUP_INVALID');
        }
        try {
            if (!fsync($directory)) {
                throw new RuntimeException('UPGRADE_DATABASE_BACKUP_INVALID');
            }
        } finally {
            fclose($directory);
        }
    }

    private function removeStaleTemporary(string $path): void
    {
        $stat = @lstat($path);
        if ($stat === false) {
            return;
        }
        if (($stat['mode'] & 0170000) !== 0100000 || ($stat['nlink'] ?? 0) !== 1 || !unlink($path)) {
            throw new RuntimeException('UPGRADE_DATABASE_BACKUP_RECOVERY_BLOCKED');
        }
    }

    /** @return list<string> */
    private function arguments(): array
    {
        return [
            '--protocol=tcp',
            '--host=' . $this->database['host'],
            '--port=' . $this->database['port'],
            '--user=' . $this->database['user'],
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--hex-blob',
            '--routines',
            '--events',
            '--triggers',
            '--',
            $this->database['database'],
        ];
    }

    private function inputChecksum(string $attempt = ''): string
    {
        $input = $this->database;
        unset($input['password']);
        ksort($input, SORT_STRING);

        return 'sha256:' . hash('sha256', json_encode(
            [$input, UpgradeOperationAttempt::normalize($attempt)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
    }

    /** @param array<string,mixed> $operation @return array<string,mixed> */
    private function publicResult(array $operation): array
    {
        return [
            'operation_id' => $operation['operation_id'],
            'state' => $operation['state'],
            'result' => $operation['result'],
            'error_code' => $operation['error_code'],
            'updated_at' => $operation['updated_at'],
            'revision' => $operation['revision'],
        ];
    }

    /** @param array<string,mixed> $database */
    private function validDatabase(array $database): bool
    {
        return array_keys($database) === ['host', 'port', 'user', 'password', 'database']
            && is_string($database['host']) && preg_match('/^[0-9A-Za-z_.:-]{1,255}$/D', $database['host']) === 1
            && is_int($database['port']) && $database['port'] >= 1 && $database['port'] <= 65535
            && is_string($database['user']) && preg_match('/^[^\x00-\x20\x7f]{1,128}$/D', $database['user']) === 1
            && is_string($database['password']) && !str_contains($database['password'], "\0") && strlen($database['password']) <= 4096
            && is_string($database['database']) && preg_match('/^[A-Za-z0-9_]{1,64}$/D', $database['database']) === 1;
    }

    private function absolute(string $path): bool
    {
        return $path !== '' && str_starts_with($path, '/') && !str_contains($path, "\0") && strlen($path) <= 4096;
    }
}
