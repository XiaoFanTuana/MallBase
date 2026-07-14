<?php

declare(strict_types=1);

namespace app\middleware\upgrade;

use app\service\install\InstallLockService;
use Closure;
use RuntimeException;
use think\Request;
use think\Response;
use Throwable;

final class SimpleUpgradeAuthMiddleware
{
    /** @var Closure():int */
    private Closure $clock;

    public function __construct(
        private readonly InstallLockService $installLock,
        ?Closure $clock = null,
    )
    {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $this->authenticate($request);

            return $next($request);
        } catch (Throwable) {
            return Response::create([
                'code' => 401,
                'message' => '升级本地接口鉴权失败',
                'data' => null,
            ], 'json', 401)->header(['Cache-Control' => 'no-store']);
        }
    }

    private function authenticate(Request $request): void
    {
        $path = '/' . trim($request->pathinfo(), '/');
        $uri = (string) $request->server('REQUEST_URI', '');
        if (strtoupper($request->method()) !== 'POST'
            || preg_match('~^/upgrade/api/simple/jobs/[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/(?:pause|backup-database|migrations|restore-database|awaiting-restart|resume)$~D', $path) !== 1
            || (string) $request->server('QUERY_STRING', '') !== ''
            || $request->get() !== []
            || str_contains($uri, '?')) {
            throw new RuntimeException('SIMPLE_UPGRADE_AUTH_INVALID');
        }

        $timestampText = (string) $request->header('X-MallBase-Upgrade-Timestamp', '');
        $signature = (string) $request->header('X-MallBase-Upgrade-Signature', '');
        if (preg_match('/^(?:0|[1-9][0-9]{0,10})$/D', $timestampText) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $signature) !== 1) {
            throw new RuntimeException('SIMPLE_UPGRADE_AUTH_INVALID');
        }
        $timestamp = (int) $timestampText;
        $clock = $this->clock;
        if (abs($clock() - $timestamp) > 60) {
            throw new RuntimeException('SIMPLE_UPGRADE_AUTH_INVALID');
        }

        $key = $this->sessionKey();
        $body = (string) $request->getContent();
        $canonical = "POST\n{$path}\n{$timestampText}\n" . hash('sha256', $body);
        $expected = hash_hmac('sha256', $canonical, $key);
        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('SIMPLE_UPGRADE_AUTH_INVALID');
        }
    }

    private function sessionKey(): string
    {
        $platform = $this->installLock->getPlatformState();
        $token = $platform['token'] ?? null;
        if (($platform['disabled'] ?? false) === true || !is_string($token)
            || strlen($token) < 1 || strlen($token) > 4096
            || preg_match('/^[\x21-\x7E]+$/D', $token) !== 1) {
            throw new RuntimeException('SIMPLE_UPGRADE_AUTH_INVALID');
        }

        return hash_hmac('sha256', 'mallbase-local-upgrade-v1', $token, true);
    }
}
