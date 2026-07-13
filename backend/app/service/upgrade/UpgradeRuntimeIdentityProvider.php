<?php

declare(strict_types=1);

namespace app\service\upgrade;

interface UpgradeRuntimeIdentityProvider
{
    public function load(): UpgradeRuntimeIdentity;
}
