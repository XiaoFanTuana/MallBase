<?php

declare(strict_types=1);

namespace app\service\upgrade;

use JsonException;
use RuntimeException;

/** Durable, job-scoped callback ledger and reconciliation cursor checkpoint. */
final readonly class UpgradePaymentReconciliationStore
{
    private const FILE = 'payment-reconciliation.json';

    public function __construct(private string $jobsRoot)
    {
        if ($this->jobsRoot === '' || !is_dir($this->jobsRoot) || is_link($this->jobsRoot)) {
            throw new RuntimeException('UPGRADE_PAYMENT_RECONCILIATION_ROOT_INVALID');
        }
    }

    /** @return array<string,mixed> */
    public function load(string $jobId): array
    {
        $this->assertJobId($jobId);

        return $this->withLock($jobId, fn(): array => $this->loadUnlocked($jobId));
    }

    /** @return array<string,mixed> */
    public function recordCallback(
        string $jobId,
        string $kind,
        string $resourceId,
        int $occurredAt,
    ): array {
        $this->assertJobId($jobId);
        if (!in_array($kind, ['payment', 'refund'], true) || trim($resourceId) === '' || !$this->timestamp($occurredAt)) {
            throw new RuntimeException('UPGRADE_PAYMENT_CALLBACK_ARGUMENT_INVALID');
        }

        return $this->withLock($jobId, function () use ($jobId, $kind, $resourceId, $occurredAt): array {
            $document = $this->loadUnlocked($jobId);
            $document['revision']++;
            $document['callback_revision']++;
            $document['last_callback_at'] = max((int) ($document['last_callback_at'] ?? 0), $occurredAt);
            $document['callback_counts'][$kind]++;
            $document['last_callback_digest'] = 'sha256:' . hash('sha256', $kind . "\0" . $resourceId);
            $this->writeUnlocked($jobId, $document);

            return $document;
        });
    }

    /** @param array<string,mixed> $checkpoint @return array<string,mixed> */
    public function saveCheckpoint(string $jobId, int $expectedRevision, array $checkpoint): array
    {
        $this->assertJobId($jobId);
        if ($expectedRevision < 0) {
            throw new RuntimeException('UPGRADE_PAYMENT_RECONCILIATION_ARGUMENT_INVALID');
        }
        try {
            $encoded = json_encode($checkpoint, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new RuntimeException('UPGRADE_PAYMENT_RECONCILIATION_CHECKPOINT_INVALID');
        }
        if (!is_string($encoded) || strlen($encoded) > 16 * 1024 * 1024) {
            throw new RuntimeException('UPGRADE_PAYMENT_RECONCILIATION_CHECKPOINT_INVALID');
        }

        return $this->withLock($jobId, function () use ($jobId, $expectedRevision, $checkpoint): array {
            $document = $this->loadUnlocked($jobId);
            if ($document['revision'] !== $expectedRevision) {
                throw new RuntimeException('UPGRADE_PAYMENT_RECONCILIATION_REVISION_CONFLICT');
            }
            $document['revision']++;
            $document['checkpoint'] = $checkpoint;
            $this->writeUnlocked($jobId, $document);

            return $document;
        });
    }

    /** @return array<string,mixed> */
    private function loadUnlocked(string $jobId): array
    {
        $path = $this->path($jobId);
        if (!is_file($path)) {
            return $this->emptyDocument();
        }
        if (is_link($path)) {
            throw new RuntimeException('UPGRADE_PAYMENT_RECONCILIATION_STATE_INVALID');
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            throw new RuntimeException('UPGRADE_PAYMENT_RECONCILIATION_STATE_INVALID');
        }
        try {
            $document = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('UPGRADE_PAYMENT_RECONCILIATION_STATE_INVALID');
        }
        if (!is_array($document)
            || array_keys($document) !== [
                'schema_version', 'revision', 'callback_revision', 'last_callback_at',
                'callback_counts', 'last_callback_digest', 'checkpoint',
            ]
            || $document['schema_version'] !== 1
            || !is_int($document['revision']) || $document['revision'] < 0
            || !is_int($document['callback_revision']) || $document['callback_revision'] < 0
            || !($document['last_callback_at'] === null || $this->timestamp($document['last_callback_at']))
            || !is_array($document['callback_counts'])
            || array_keys($document['callback_counts']) !== ['payment', 'refund']
            || !is_int($document['callback_counts']['payment']) || $document['callback_counts']['payment'] < 0
            || !is_int($document['callback_counts']['refund']) || $document['callback_counts']['refund'] < 0
            || !($document['last_callback_digest'] === null
                || (is_string($document['last_callback_digest'])
                    && preg_match('/^sha256:[0-9a-f]{64}$/D', $document['last_callback_digest']) === 1))
            || !($document['checkpoint'] === null || is_array($document['checkpoint']))) {
            throw new RuntimeException('UPGRADE_PAYMENT_RECONCILIATION_STATE_INVALID');
        }

        return $document;
    }

    /** @param array<string,mixed> $document */
    private function writeUnlocked(string $jobId, array $document): void
    {
        try {
            $bytes = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        } catch (JsonException) {
            throw new RuntimeException('UPGRADE_PAYMENT_RECONCILIATION_STATE_INVALID');
        }
        $directory = $this->directory($jobId);
        $path = $this->path($jobId);
        $temp = $directory . '/.' . self::FILE . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = fopen($temp, 'xb');
        if ($handle === false) {
            throw new RuntimeException('UPGRADE_PAYMENT_RECONCILIATION_WRITE_FAILED');
        }
        try {
            chmod($temp, 0600);
            $offset = 0;
            $length = strlen($bytes);
            while ($offset < $length) {
                $written = fwrite($handle, substr($bytes, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('UPGRADE_PAYMENT_RECONCILIATION_WRITE_FAILED');
                }
                $offset += $written;
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new RuntimeException('UPGRADE_PAYMENT_RECONCILIATION_WRITE_FAILED');
            }
        } finally {
            fclose($handle);
        }
        if (!rename($temp, $path)) {
            @unlink($temp);
            throw new RuntimeException('UPGRADE_PAYMENT_RECONCILIATION_WRITE_FAILED');
        }
        chmod($path, 0600);
    }

    /** @template T @param callable():T $callback @return T */
    private function withLock(string $jobId, callable $callback): mixed
    {
        $directory = $this->directory($jobId);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('UPGRADE_PAYMENT_RECONCILIATION_WRITE_FAILED');
        }
        if (is_link($directory)) {
            throw new RuntimeException('UPGRADE_PAYMENT_RECONCILIATION_STATE_INVALID');
        }
        $lock = fopen($directory . '/payment-reconciliation.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('UPGRADE_PAYMENT_RECONCILIATION_LOCK_FAILED');
        }
        chmod($directory . '/payment-reconciliation.lock', 0600);
        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string,mixed> */
    private function emptyDocument(): array
    {
        return [
            'schema_version' => 1,
            'revision' => 0,
            'callback_revision' => 0,
            'last_callback_at' => null,
            'callback_counts' => ['payment' => 0, 'refund' => 0],
            'last_callback_digest' => null,
            'checkpoint' => null,
        ];
    }

    private function directory(string $jobId): string
    {
        return rtrim($this->jobsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $jobId;
    }

    private function path(string $jobId): string
    {
        return $this->directory($jobId) . DIRECTORY_SEPARATOR . self::FILE;
    }

    private function assertJobId(string $jobId): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $jobId) !== 1) {
            throw new RuntimeException('UPGRADE_PAYMENT_RECONCILIATION_ARGUMENT_INVALID');
        }
    }

    private function timestamp(mixed $value): bool
    {
        return is_int($value) && $value >= 0 && $value <= 4_102_444_800;
    }
}
