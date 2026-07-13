<?php

declare(strict_types=1);

namespace app\service\upgrade;

/** Clears the durable platform outbox flag only after the Agent confirms its receipt. */
interface UpgradePlatformSyncGateRepository
{
    public function confirmPlatformSync(int $expectedRevision): UpgradeGateSnapshot;
}
