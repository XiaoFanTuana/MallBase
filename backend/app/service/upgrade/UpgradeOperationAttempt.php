<?php

declare(strict_types=1);

namespace app\service\upgrade;

use RuntimeException;

/** Derives one bounded immutable PHP operation namespace per explicit resume. */
final class UpgradeOperationAttempt
{
    public static function normalize(mixed $value): string
    {
        if (!is_string($value) || ($value !== '' && preg_match('/^[0-9a-f]{12}$/D', $value) !== 1)) {
            throw new RuntimeException('UPGRADE_AGENT_ARGUMENT_INVALID');
        }

        return $value;
    }

    public static function action(string $base, string $attempt): string
    {
        $attempt = self::normalize($attempt);
        $action = $attempt === '' ? $base : $base . '_r_' . $attempt;
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $action) !== 1) {
            throw new RuntimeException('UPGRADE_AGENT_ARGUMENT_INVALID');
        }

        return $action;
    }

    public static function fromRequestId(string $requestId): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $requestId) !== 1) {
            throw new RuntimeException('UPGRADE_AGENT_ARGUMENT_INVALID');
        }

        return substr(hash('sha256', "mallbase-upgrade-resume-attempt-v1\0" . $requestId), 0, 12);
    }
}
