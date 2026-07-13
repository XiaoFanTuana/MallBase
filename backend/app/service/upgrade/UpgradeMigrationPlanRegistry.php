<?php

declare(strict_types=1);

namespace app\service\upgrade;

use JsonException;
use RuntimeException;

/** Reads only manifest-listed migration IDs from the Agent-owned staging mount. */
final readonly class UpgradeMigrationPlanRegistry
{
    public function __construct(private string $stagingRoot, private int $maximumSqlBytes = 16777216)
    {
        if ($this->stagingRoot === '' || !str_starts_with($this->stagingRoot, '/')
            || $this->maximumSqlBytes < 1 || $this->maximumSqlBytes > 16777216) {
            throw new RuntimeException('UPGRADE_MIGRATION_STAGING_CONFIG_INVALID');
        }
    }

    /** @return array{id:string,version:string,sha256:string,sql:string} */
    public function migration(string $jobId, string $migrationId): array
    {
        if (!$this->uuid($jobId) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $migrationId) !== 1) {
            throw new RuntimeException('UPGRADE_MIGRATION_ARGUMENT_INVALID');
        }
        $directory = $this->stagingRoot . '/jobs/' . $jobId . '/migrations';
        $this->assertDirectory($directory);
        $plan = $this->readRegular($directory . '/migration-plan.json', 1048576);
        try {
            $document = json_decode($plan, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('UPGRADE_MIGRATION_PLAN_INVALID');
        }
        if (!is_array($document) || array_keys($document) !== ['schema_version', 'job_id', 'migrations']
            || $document['schema_version'] !== 1 || $document['job_id'] !== $jobId
            || !is_array($document['migrations']) || !array_is_list($document['migrations'])
            || count($document['migrations']) > 10000) {
            throw new RuntimeException('UPGRADE_MIGRATION_PLAN_INVALID');
        }
        $selected = null;
        $seen = [];
        foreach ($document['migrations'] as $entry) {
            if (!is_array($entry) || array_keys($entry) !== ['id', 'version', 'sha256']
                || !is_string($entry['id']) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $entry['id']) !== 1
                || isset($seen[$entry['id']]) || !is_string($entry['version']) || !$this->semver($entry['version'])
                || !is_string($entry['sha256']) || preg_match('/^sha256:[0-9a-f]{64}$/D', $entry['sha256']) !== 1) {
                throw new RuntimeException('UPGRADE_MIGRATION_PLAN_INVALID');
            }
            $seen[$entry['id']] = true;
            if ($entry['id'] === $migrationId) {
                $selected = $entry;
            }
        }
        if (!is_array($selected)) {
            throw new RuntimeException('UPGRADE_MIGRATION_UNKNOWN');
        }
        $sql = $this->readRegular($directory . '/' . $migrationId . '.sql', $this->maximumSqlBytes);
        if (!hash_equals($selected['sha256'], 'sha256:' . hash('sha256', $sql))) {
            throw new RuntimeException('UPGRADE_MIGRATION_CHECKSUM_MISMATCH');
        }

        return [
            'id' => $migrationId,
            'version' => $selected['version'],
            'sha256' => $selected['sha256'],
            'sql' => $sql,
        ];
    }

    private function assertDirectory(string $path): void
    {
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000 || realpath($path) !== $path) {
            throw new RuntimeException('UPGRADE_MIGRATION_STAGING_UNAVAILABLE');
        }
    }

    private function readRegular(string $path, int $maximum): string
    {
        $named = @lstat($path);
        if (!is_array($named) || ($named['mode'] & 0170000) !== 0100000
            || ($named['nlink'] ?? 0) !== 1 || ($named['size'] ?? 0) < 1
            || ($named['size'] ?? 0) > $maximum) {
            throw new RuntimeException('UPGRADE_MIGRATION_STAGING_INVALID');
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('UPGRADE_MIGRATION_STAGING_INVALID');
        }
        try {
            $opened = fstat($handle);
            if (!is_array($opened) || ($opened['dev'] ?? null) !== ($named['dev'] ?? null)
                || ($opened['ino'] ?? null) !== ($named['ino'] ?? null)
                || ($opened['size'] ?? null) !== ($named['size'] ?? null)) {
                throw new RuntimeException('UPGRADE_MIGRATION_STAGING_INVALID');
            }
            $bytes = stream_get_contents($handle, $maximum + 1);
            $after = fstat($handle);
            if (!is_string($bytes) || strlen($bytes) > $maximum || !is_array($after)
                || ($after['size'] ?? null) !== ($opened['size'] ?? null)
                || ($after['mtime'] ?? null) !== ($opened['mtime'] ?? null)) {
                throw new RuntimeException('UPGRADE_MIGRATION_STAGING_INVALID');
            }

            return $bytes;
        } finally {
            fclose($handle);
        }
    }

    private function uuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }

    private function semver(string $value): bool
    {
        return preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D', $value) === 1;
    }
}
