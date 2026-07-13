<?php

declare(strict_types=1);

namespace app\service\upgrade;

use RuntimeException;

/** First official container release supports one physical local upload root. */
final readonly class LocalUploadRootPolicy
{
    public const CANONICAL_ROOT = 'uploads';

    public function assertSupported(string $configuredRoot, string $publicRoot): void
    {
        if ($configuredRoot !== self::CANONICAL_ROOT || $publicRoot === ''
            || !str_starts_with($publicRoot, '/') || str_contains($publicRoot, "\0")) {
            throw new RuntimeException('UPGRADE_LOCAL_UPLOAD_ROOT_UNSUPPORTED');
        }
        $root = rtrim($publicRoot, '/') . '/' . self::CANONICAL_ROOT;
        $publicReal = realpath($publicRoot);
        $rootStat = @lstat($root);
        if (!is_string($publicReal) || $publicReal !== rtrim($publicRoot, '/')
            || !is_array($rootStat) || ($rootStat['mode'] & 0170000) !== 0040000
            || realpath($root) !== $root || !str_starts_with($root . '/', $publicReal . '/')) {
            throw new RuntimeException('UPGRADE_LOCAL_UPLOAD_ROOT_UNAVAILABLE');
        }
    }
}
