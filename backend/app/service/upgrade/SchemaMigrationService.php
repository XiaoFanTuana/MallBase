<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Closure;
use PDO;
use RuntimeException;
use Throwable;

/** Executes signed, staged, statement-checkpointed MySQL migrations. */
final readonly class SchemaMigrationService
{
    /** @var Closure():int */
    private Closure $clock;

    public function __construct(
        private FileMigrationRegistry $registry,
        private UpgradeMigrationAdvisoryLock $advisoryLock,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** @return array<string, mixed> */
    public function execute(
        string $jobId,
        string $migrationId,
        string $version,
        string $expectedChecksum,
        string $sql,
    ): array {
        if (strlen($sql) < 1 || strlen($sql) > 16 * 1024 * 1024
            || !mb_check_encoding($sql, 'UTF-8')) {
            throw new RuntimeException('UPGRADE_MIGRATION_INPUT_INVALID');
        }
        $actualChecksum = 'sha256:' . hash('sha256', $sql);
        if (!hash_equals($expectedChecksum, $actualChecksum)) {
            throw new RuntimeException('UPGRADE_MIGRATION_CHECKSUM_MISMATCH');
        }
        $statements = $this->statements($sql);

        return $this->advisoryLock->withLock(
            $jobId,
            $migrationId,
            function (PDO $pdo) use ($jobId, $migrationId, $version, $expectedChecksum, $statements): array {
                $clock = $this->clock;
                $entry = $this->registry->claim(
                    $jobId,
                    $migrationId,
                    $version,
                    $expectedChecksum,
                    count($statements),
                    $clock(),
                );
                if ($entry['state'] === 'completed') {
                    return $entry;
                }
                if ($entry['state'] === 'failed') {
                    throw new RuntimeException('UPGRADE_MIGRATION_PREVIOUSLY_FAILED');
                }

                try {
                    for ($index = $entry['next_statement']; $index < count($statements); $index++) {
                        $result = $pdo->exec($statements[$index]);
                        if ($result === false) {
                            throw new RuntimeException('UPGRADE_MIGRATION_STATEMENT_FAILED');
                        }
                        $entry = $this->registry->checkpoint(
                            $migrationId,
                            $expectedChecksum,
                            $index + 1,
                            $clock(),
                        );
                    }

                    return $this->registry->complete($migrationId, $expectedChecksum, $clock());
                } catch (Throwable $exception) {
                    try {
                        $this->registry->fail(
                            $migrationId,
                            $expectedChecksum,
                            $this->publicError($exception),
                            $clock(),
                        );
                    } catch (Throwable) {
                        throw new RuntimeException('UPGRADE_MIGRATION_RECOVERY_BLOCKED', 0, $exception);
                    }
                    if ($exception instanceof RuntimeException && str_starts_with($exception->getMessage(), 'UPGRADE_')) {
                        throw $exception;
                    }
                    throw new RuntimeException('UPGRADE_MIGRATION_STATEMENT_FAILED', 0, $exception);
                }
            },
        );
    }

    /** @return list<string> */
    public function statements(string $sql): array
    {
        $parts = preg_split('/^-- mallbase:statement-breakpoint\r?$/m', $sql);
        if (!is_array($parts) || $parts === [] || count($parts) > 10000) {
            throw new RuntimeException('UPGRADE_MIGRATION_INPUT_INVALID');
        }
        $statements = [];
        foreach ($parts as $part) {
            $statement = trim($part);
            if ($statement === '') {
                throw new RuntimeException('UPGRADE_MIGRATION_INPUT_INVALID');
            }
            if (preg_match('/\b(?:DELIMITER|SOURCE|LOAD\s+DATA\s+LOCAL|START\s+TRANSACTION|BEGIN|COMMIT|ROLLBACK|SET\s+AUTOCOMMIT)\b/i', $statement) === 1) {
                throw new RuntimeException('UPGRADE_MIGRATION_STATEMENT_FORBIDDEN');
            }
            $withoutTrailing = preg_replace('/;\s*$/D', '', $statement, 1);
            if (!is_string($withoutTrailing) || str_contains($withoutTrailing, ';')) {
                throw new RuntimeException('UPGRADE_MIGRATION_MULTIPLE_STATEMENTS');
            }
            $statements[] = $statement;
        }

        return $statements;
    }

    private function publicError(Throwable $exception): string
    {
        $message = $exception->getMessage();

        return preg_match('/^[A-Z0-9_]{1,64}$/D', $message) === 1
            ? $message
            : 'UPGRADE_MIGRATION_STATEMENT_FAILED';
    }
}
