<?php

declare(strict_types=1);

namespace app\middleware\upgrade;

use app\service\upgrade\UpgradeControlRateLimiter;
use app\service\upgrade\UpgradeSameOriginPolicy;
use app\service\upgrade\UpgradeSessionAuthStore;
use Closure;
use RuntimeException;
use think\Request;
use think\Response;
use Throwable;

final readonly class UpgradeSessionAuthMiddleware
{
    public const COOKIE_NAME = 'mallbase_upgrade_session';

    public function __construct(
        private ?UpgradeSessionAuthStore $sessions = null,
        private ?UpgradeSameOriginPolicy $origin = null,
        private ?UpgradeControlRateLimiter $limiter = null,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $sessions = $this->sessions ?? app()->make(UpgradeSessionAuthStore::class);
            $ownerCookie = (string) $request->cookie(self::COOKIE_NAME, '');
            $principal = $sessions->authenticateOwner($ownerCookie, time());
            $ownerHash = (string) ($principal['owner_sha256'] ?? '');
            if ($ownerHash === '') {
                throw new RuntimeException('UPGRADE_SESSION_UNAUTHORIZED');
            }

            $method = strtoupper($request->method());
            $limiter = $this->limiter ?? app()->make(UpgradeControlRateLimiter::class);
            $limiter->consume($method === 'GET' ? 'session_status' : 'session_mutation', $ownerHash, $method === 'GET' ? 60 : 20, 60);

            if ($method !== 'GET' && $method !== 'HEAD') {
                ($this->origin ?? app()->make(UpgradeSameOriginPolicy::class))
                    ->assert(trim((string) $request->header('Origin', '')));
                if (!$this->confirmationEndpoint($request)) {
                    $provided = trim((string) $request->header('X-Upgrade-CSRF', ''));
                    $expected = (string) ($principal['csrf_nonce'] ?? '');
                    if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
                        throw new RuntimeException('UPGRADE_CSRF_INVALID');
                    }
                }
            }

            $request->upgrade_session = $principal;
            $request->upgrade_owner_cookie = $ownerCookie;

            return $next($request);
        } catch (Throwable $exception) {
            $code = $exception->getMessage();
            if ($code === 'UPGRADE_RATE_LIMITED') {
                return $this->error(429, 'UPGRADE_RATE_LIMITED', '请求过于频繁，请稍后再试');
            }
            if ($code === 'UPGRADE_RATE_LIMIT_UNAVAILABLE') {
                return $this->error(503, 'UPGRADE_RATE_LIMIT_UNAVAILABLE', '升级会话暂时不可用');
            }
            if (in_array($code, ['UPGRADE_ORIGIN_INVALID', 'UPGRADE_CSRF_INVALID'], true)) {
                return $this->error(403, $code, '升级请求校验失败');
            }

            return $this->error(401, 'UPGRADE_SESSION_UNAUTHORIZED', '升级会话无效或已过期');
        }
    }

    private function confirmationEndpoint(Request $request): bool
    {
        return trim($request->pathinfo(), '/') === 'upgrade/api/recovery/confirm';
    }

    private function error(int $status, string $reason, string $message): Response
    {
        return json([
            'code' => $status,
            'message' => $message,
            'data' => ['reason' => $reason],
            'timestamp' => time(),
        ], $status)->header(['Cache-Control' => 'no-store']);
    }
}
