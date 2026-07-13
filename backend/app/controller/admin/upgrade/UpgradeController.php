<?php

declare(strict_types=1);

namespace app\controller\admin\upgrade;

use app\middleware\upgrade\UpgradeSessionAuthMiddleware;
use app\model\auth\Admin;
use app\service\admin\upgrade\UpgradeSessionService;
use app\service\upgrade\UpgradeControlRateLimiter;
use mall_base\base\BaseController;
use RuntimeException;
use think\Response;
use Throwable;

/** @extends BaseController<UpgradeSessionService> */
final class UpgradeController extends BaseController
{
    protected string $serviceClass = UpgradeSessionService::class;

    public function createSession(): Response
    {
        $adminId = (int) ($this->request->admin_id ?? 0);
        if ($adminId !== Admin::SUPER_ADMIN_ID) {
            return $this->upgradeError(403, 'UPGRADE_SUPER_ADMIN_REQUIRED', '仅超级管理员可以进入系统升级');
        }
        $requestId = strtolower(trim((string) $this->request->header('Idempotency-Key', '')));

        try {
            app()->make(UpgradeControlRateLimiter::class)
                ->consume('session_create', (string) $adminId, 10, 60);
            $result = $this->service()->createSession($adminId, $requestId);
            $ownerCookie = (string) ($result['owner_cookie'] ?? '');
            unset($result['owner_cookie']);
            $result['recovery_request_id'] = $result['request_id'] ?? $requestId;

            return $this->success($result, '升级会话已创建')
                ->cookie(UpgradeSessionAuthMiddleware::COOKIE_NAME, $ownerCookie, [
                    'expire' => 86400,
                    'path' => '/upgrade/',
                    'secure' => str_starts_with((string) config('upgrade.public_origin', ''), 'https://'),
                    'httponly' => true,
                    'samesite' => 'Strict',
                ])
                ->header($this->securityHeaders());
        } catch (Throwable $exception) {
            return $this->mapError($exception);
        }
    }

    private function mapError(Throwable $exception): Response
    {
        return match ($exception->getMessage()) {
            'UPGRADE_SUPER_ADMIN_REQUIRED' => $this->upgradeError(403, 'UPGRADE_SUPER_ADMIN_REQUIRED', '仅超级管理员可以进入系统升级'),
            'UPGRADE_SESSION_ARGUMENT_INVALID' => $this->upgradeError(422, 'UPGRADE_SESSION_ARGUMENT_INVALID', '幂等请求标识无效'),
            'UPGRADE_SESSION_EXISTS' => $this->upgradeError(409, 'UPGRADE_SESSION_EXISTS', '已有升级会话，请使用恢复凭据接管'),
            'UPGRADE_RATE_LIMITED' => $this->upgradeError(429, 'UPGRADE_RATE_LIMITED', '请求过于频繁，请稍后再试'),
            'UPGRADE_RATE_LIMIT_UNAVAILABLE' => $this->upgradeError(503, 'UPGRADE_RATE_LIMIT_UNAVAILABLE', '升级会话暂时不可用'),
            default => $this->upgradeError(503, 'UPGRADE_SESSION_UNAVAILABLE', '升级会话暂时不可用'),
        };
    }

    private function upgradeError(int $status, string $reason, string $message): Response
    {
        return json([
            'code' => $status,
            'message' => $message,
            'data' => ['reason' => $reason],
            'timestamp' => time(),
        ], $status)->header($this->securityHeaders());
    }

    /** @return array<string, string> */
    private function securityHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ];
    }
}
