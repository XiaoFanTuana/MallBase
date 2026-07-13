<?php

declare(strict_types=1);

namespace app\service\upgrade;

/** Browser/Agent-facing subset of the drain coordinator. */
interface UpgradeDrainControl
{
    public function begin(string $jobId, int $expectedRevision): UpgradeGateSnapshot;
}
