<?php

declare(strict_types=1);

namespace app\service\upgrade;

/** Explicit recovery-only edge out of failed maintenance. */
interface UpgradeRecoveryGateRepository
{
    public function resumeFromFailedMaintenance(
        int $expectedRevision,
        UpgradeState $phase,
        string $jobId,
    ): UpgradeGateSnapshot;
}
