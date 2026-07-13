<?php

declare(strict_types=1);

namespace app\service\upgrade;

final readonly class ReconciliationResult
{
    public const RUNNING = 'running';
    public const WAITING_QUIET_WINDOW = 'waiting_quiet_window';
    public const RETRY_REQUIRED = 'retry_required';
    public const COMPLETED = 'completed';

    /** @param list<string> $errorCodes */
    public function __construct(
        public string $status,
        public string $phase,
        public int $processed,
        public array $errorCodes = [],
        public int $quietRemainingSeconds = 0,
    ) {
    }

    public function complete(): bool
    {
        return $this->status === self::COMPLETED;
    }
}
