<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Closure;
use PDO;
use RuntimeException;
use Throwable;

/** Dedicated-connection MySQL advisory lock used before any file registry lock. */
final readonly class UpgradeMigrationAdvisoryLock
{
    /** @var Closure():PDO */
    private Closure $connection;

    public function __construct(Closure $connection, private string $namespace, private int $timeoutSeconds = 2)
    {
        if (preg_match('/^mbs_[a-z0-9][a-z0-9_-]{0,59}$/D', $this->namespace) !== 1
            || $this->timeoutSeconds < 0 || $this->timeoutSeconds > 10) {
            throw new RuntimeException('UPGRADE_MIGRATION_LOCK_CONFIG_INVALID');
        }
        $this->connection = $connection;
    }

    public function name(string $jobId, string $migrationId): string
    {
        if (preg_match('/^[0-9a-f-]{36}$/D', $jobId) !== 1
            || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$/D', $migrationId) !== 1) {
            throw new RuntimeException('UPGRADE_MIGRATION_LOCK_ARGUMENT_INVALID');
        }

        return 'mbu:' . substr(hash('sha256', $this->namespace . "\0" . $jobId . "\0" . $migrationId), 0, 60);
    }

    public function withLock(string $jobId, string $migrationId, Closure $callback): mixed
    {
        $factory = $this->connection;
        $pdo = $factory();
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('UPGRADE_MIGRATION_LOCK_UNAVAILABLE');
        }
        $name = $this->name($jobId, $migrationId);
        $acquired = false;
        try {
            $statement = $pdo->prepare('SELECT GET_LOCK(:lock_name, :timeout_seconds) AS acquired');
            if ($statement === false || !$statement->execute([
                'lock_name' => $name,
                'timeout_seconds' => $this->timeoutSeconds,
            ])) {
                throw new RuntimeException('UPGRADE_MIGRATION_LOCK_UNAVAILABLE');
            }
            $value = $statement->fetchColumn();
            if ((string) $value !== '1') {
                throw new RuntimeException('UPGRADE_MIGRATION_RECOVERY_BLOCKED');
            }
            $acquired = true;

            return $callback($pdo);
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException && str_starts_with($exception->getMessage(), 'UPGRADE_')) {
                throw $exception;
            }
            throw new RuntimeException('UPGRADE_MIGRATION_RECOVERY_BLOCKED', 0, $exception);
        } finally {
            if ($acquired) {
                try {
                    $statement = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name) AS released');
                    if ($statement !== false) {
                        $statement->execute(['lock_name' => $name]);
                    }
                } catch (Throwable) {
                    // Closing this dedicated PDO connection releases the lock;
                    // no subsequent operation reuses it.
                }
                unset($pdo);
            }
        }
    }
}
