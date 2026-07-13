<?php

declare(strict_types=1);

namespace app\service\upgrade;

use RuntimeException;

/** Fixed deployment-origin policy for every upgrade mutation. */
final readonly class UpgradeSameOriginPolicy
{
    private string $origin;

    public function __construct(string $publicOrigin)
    {
        $normalized = $this->normalize($publicOrigin);
        if ($normalized === null) {
            throw new RuntimeException('UPGRADE_ORIGIN_CONFIG_INVALID');
        }
        $this->origin = $normalized;
    }

    public function assert(string $origin): void
    {
        $normalized = $this->normalize($origin);
        if ($normalized === null || !hash_equals($this->origin, $normalized)) {
            throw new RuntimeException('UPGRADE_ORIGIN_INVALID');
        }
    }

    public function origin(): string
    {
        return $this->origin;
    }

    private function normalize(string $value): ?string
    {
        if ($value === '' || trim($value) !== $value || strlen($value) > 2048
            || str_contains($value, "\0")) {
            return null;
        }
        $parts = parse_url($value);
        if (!is_array($parts) || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            || !isset($parts['scheme'], $parts['host']) || ($parts['path'] ?? '') !== '') {
            return null;
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        if (!in_array($scheme, ['http', 'https'], true) || $host === ''
            || preg_match('~[\x00-\x20\x7f/\\\\]~', $host) === 1) {
            return null;
        }
        $port = $parts['port'] ?? null;
        if ($port !== null && (!is_int($port) || $port < 1 || $port > 65535)) {
            return null;
        }
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }
        $renderedHost = str_contains($host, ':') ? '[' . trim($host, '[]') . ']' : $host;

        return $scheme . '://' . $renderedHost . ($port === null ? '' : ':' . $port);
    }
}
