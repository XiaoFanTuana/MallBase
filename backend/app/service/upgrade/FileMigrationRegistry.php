<?php

declare(strict_types=1);

namespace app\service\upgrade;

use RuntimeException;
use stdClass;

/** Host-persistent migration checkpoints; the business database is not the registry. */
final readonly class FileMigrationRegistry
{
    public function __construct(private UpgradeSharedFileStore $files)
    {
    }

    /** @return array<string, mixed> */
    public function claim(
        string $jobId,
        string $migrationId,
        string $version,
        string $checksum,
        int $statementCount,
        int $now,
    ): array {
        $this->validateInput($jobId, $migrationId, $version, $checksum, $statementCount, $now);

        return $this->files->withMigrationRegistryLock(function () use (
            $jobId,
            $migrationId,
            $version,
            $checksum,
            $statementCount,
            $now,
        ): array {
            $document = $this->loadUnlocked();
            $entry = $document['migrations'][$migrationId] ?? null;
            if (is_array($entry)) {
                if ($entry['checksum'] !== $checksum || $entry['version'] !== $version
                    || $entry['statement_count'] !== $statementCount || $entry['job_id'] !== $jobId) {
                    throw new RuntimeException('UPGRADE_MIGRATION_CHECKSUM_CONFLICT');
                }

                return $entry;
            }
            if (count($document['migrations']) >= 1000) {
                throw new RuntimeException('UPGRADE_MIGRATION_LIMIT_REACHED');
            }
            $entry = [
                'migration_id' => $migrationId,
                'job_id' => $jobId,
                'version' => $version,
                'checksum' => $checksum,
                'state' => 'running',
                'statement_count' => $statementCount,
                'next_statement' => 0,
                'error_code' => null,
                'started_at' => $now,
                'updated_at' => $now,
                'completed_at' => null,
                'revision' => 1,
            ];
            $document['migrations'][$migrationId] = $entry;
            ksort($document['migrations'], SORT_STRING);
            $document['revision']++;
            $this->write($document);

            return $entry;
        });
    }

    /** @return array<string, mixed> */
    public function checkpoint(string $migrationId, string $checksum, int $nextStatement, int $now): array
    {
        return $this->mutate($migrationId, $checksum, $now, static function (array $entry) use ($nextStatement): array {
            if ($nextStatement < $entry['next_statement'] || $nextStatement > $entry['statement_count']) {
                throw new RuntimeException('UPGRADE_MIGRATION_STATE_CONFLICT');
            }
            $entry['next_statement'] = $nextStatement;

            return $entry;
        });
    }

    /** @return array<string, mixed> */
    public function complete(string $migrationId, string $checksum, int $now): array
    {
        if (!$this->migrationId($migrationId) || !$this->checksum($checksum) || !$this->timestamp($now)) {
            throw new RuntimeException('UPGRADE_MIGRATION_ARGUMENT_INVALID');
        }

        return $this->files->withMigrationRegistryLock(function () use ($migrationId, $checksum, $now): array {
            $document = $this->loadUnlocked();
            $entry = $document['migrations'][$migrationId] ?? null;
            if (!is_array($entry) || !hash_equals($entry['checksum'], $checksum)) {
                throw new RuntimeException('UPGRADE_MIGRATION_STATE_CONFLICT');
            }
            if ($entry['state'] === 'completed') {
                return $entry;
            }
            if ($entry['next_statement'] !== $entry['statement_count']) {
                throw new RuntimeException('UPGRADE_MIGRATION_STATE_CONFLICT');
            }
            $entry['state'] = 'completed';
            $entry['completed_at'] = $now;
            $entry['error_code'] = null;
            $entry['updated_at'] = $now;
            $entry['revision']++;
            $document['migrations'][$migrationId] = $entry;
            $document['revision']++;
            $this->write($document);

            return $entry;
        });
    }

    /** @return array<string, mixed> */
    public function fail(string $migrationId, string $checksum, string $errorCode, int $now): array
    {
        if (preg_match('/^[A-Z0-9_]{1,64}$/D', $errorCode) !== 1) {
            throw new RuntimeException('UPGRADE_MIGRATION_ARGUMENT_INVALID');
        }

        return $this->mutate($migrationId, $checksum, $now, static function (array $entry) use ($errorCode): array {
            $entry['state'] = 'failed';
            $entry['error_code'] = $errorCode;

            return $entry;
        });
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $mutator @return array<string,mixed> */
    private function mutate(string $migrationId, string $checksum, int $now, callable $mutator): array
    {
        if (!$this->migrationId($migrationId) || !$this->checksum($checksum) || !$this->timestamp($now)) {
            throw new RuntimeException('UPGRADE_MIGRATION_ARGUMENT_INVALID');
        }

        return $this->files->withMigrationRegistryLock(function () use ($migrationId, $checksum, $now, $mutator): array {
            $document = $this->loadUnlocked();
            $entry = $document['migrations'][$migrationId] ?? null;
            if (!is_array($entry) || !hash_equals($entry['checksum'], $checksum)
                || $entry['state'] === 'completed') {
                throw new RuntimeException('UPGRADE_MIGRATION_STATE_CONFLICT');
            }
            $entry = $mutator($entry);
            $entry['updated_at'] = $now;
            $entry['revision']++;
            $document['migrations'][$migrationId] = $entry;
            $document['revision']++;
            $this->write($document);

            return $entry;
        });
    }

    /** @return array{schema_version:int,revision:int,migrations:array<string,array<string,mixed>>} */
    private function loadUnlocked(): array
    {
        $document = $this->files->readJson('migration_registry');
        if ($document === null) {
            return ['schema_version' => 1, 'revision' => 0, 'migrations' => []];
        }
        $raw = get_object_vars($document);
        if (array_keys($raw) !== ['schema_version', 'revision', 'migrations']
            || $raw['schema_version'] !== 1 || !is_int($raw['revision']) || $raw['revision'] < 0
            || !$raw['migrations'] instanceof stdClass) {
            throw new RuntimeException('UPGRADE_MIGRATION_REGISTRY_INVALID');
        }
        $migrations = [];
        foreach (get_object_vars($raw['migrations']) as $migrationId => $value) {
            if (!is_string($migrationId) || !$this->migrationId($migrationId) || !$value instanceof stdClass) {
                throw new RuntimeException('UPGRADE_MIGRATION_REGISTRY_INVALID');
            }
            $entry = get_object_vars($value);
            $expected = [
                'migration_id', 'job_id', 'version', 'checksum', 'state', 'statement_count',
                'next_statement', 'error_code', 'started_at', 'updated_at', 'completed_at', 'revision',
            ];
            if (array_keys($entry) !== $expected || $entry['migration_id'] !== $migrationId
                || !$this->uuid((string) $entry['job_id']) || !$this->version((string) $entry['version'])
                || !$this->checksum((string) $entry['checksum'])
                || !in_array($entry['state'], ['running', 'failed', 'completed'], true)
                || !is_int($entry['statement_count']) || $entry['statement_count'] < 1 || $entry['statement_count'] > 10000
                || !is_int($entry['next_statement']) || $entry['next_statement'] < 0
                || $entry['next_statement'] > $entry['statement_count']
                || !($entry['error_code'] === null || preg_match('/^[A-Z0-9_]{1,64}$/D', $entry['error_code']) === 1)
                || !$this->timestamp($entry['started_at']) || !$this->timestamp($entry['updated_at'])
                || !($entry['completed_at'] === null || $this->timestamp($entry['completed_at']))
                || !is_int($entry['revision']) || $entry['revision'] < 1) {
                throw new RuntimeException('UPGRADE_MIGRATION_REGISTRY_INVALID');
            }
            $migrations[$migrationId] = $entry;
        }

        return ['schema_version' => 1, 'revision' => $raw['revision'], 'migrations' => $migrations];
    }

    /** @param array<string, mixed> $document */
    private function write(array $document): void
    {
        $this->files->writeJson(
            'migration_registry',
            json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR),
        );
    }

    private function validateInput(string $jobId, string $migrationId, string $version, string $checksum, int $count, int $now): void
    {
        if (!$this->uuid($jobId) || !$this->migrationId($migrationId) || !$this->version($version)
            || !$this->checksum($checksum) || $count < 1 || $count > 10000 || !$this->timestamp($now)) {
            throw new RuntimeException('UPGRADE_MIGRATION_ARGUMENT_INVALID');
        }
    }

    private function migrationId(string $value): bool
    {
        return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$/D', $value) === 1;
    }

    private function uuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }

    private function version(string $value): bool
    {
        return preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $value) === 1;
    }

    private function checksum(string $value): bool
    {
        return preg_match('/^sha256:[0-9a-f]{64}$/D', $value) === 1;
    }

    private function timestamp(mixed $value): bool
    {
        return is_int($value) && $value >= 0 && $value <= 4_102_444_800;
    }
}
