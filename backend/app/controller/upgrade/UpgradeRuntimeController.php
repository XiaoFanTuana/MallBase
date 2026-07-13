<?php

declare(strict_types=1);

namespace app\controller\upgrade;

use app\middleware\upgrade\UpgradeSessionAuthMiddleware;
use app\service\install\AgentPlatformBootstrapService;
use app\service\upgrade\UpgradeControlRateLimiter;
use app\service\upgrade\UpgradeRecoveryCapabilityService;
use app\service\upgrade\UpgradeSameOriginPolicy;
use app\service\upgrade\UpgradeStrictJsonDecoder;
use app\service\upgrade\UpgradeJobControlService;
use app\service\upgrade\UpgradeViewService;
use mall_base\base\BaseController;
use think\Response;
use Throwable;

final class UpgradeRuntimeController extends BaseController
{
    public function maintenance(): Response
    {
        try {
            $limiter = app()->make(UpgradeControlRateLimiter::class);
            $peer = $limiter->directPeer((array) $this->request->server());
            $limiter->consume('maintenance_peer', $peer, 3000, 60);
            $limiter->consume('maintenance_global', 'global', 100000, 60);

            return $this->success(app()->make(UpgradeViewService::class)->maintenance(), '获取成功')
                ->header($this->securityHeaders());
        } catch (Throwable $exception) {
            return $this->mapError($exception);
        }
    }

    public function status(): Response
    {
        try {
            return $this->success(
                app()->make(UpgradeViewService::class)->status($this->principal()),
                '获取成功',
            )->header($this->securityHeaders());
        } catch (Throwable $exception) {
            return $this->mapError($exception);
        }
    }

    public function recoveryTakeover(): Response
    {
        try {
            app()->make(UpgradeSameOriginPolicy::class)
                ->assert(trim((string) $this->request->header('Origin', '')));
            $limiter = app()->make(UpgradeControlRateLimiter::class);
            $peer = $limiter->directPeer((array) $this->request->server());
            $limiter->consume('recovery_global', 'global', 1000, 60);
            $limiter->consume('recovery_peer', $peer, 5, 60);
            $body = $this->strictBody(['recovery_credential']);
            $credential = is_string($body['recovery_credential']) ? $body['recovery_credential'] : '';
            $sessionSubject = preg_match('/^mbur1\.([0-9a-f-]{36})\./D', $credential, $match) === 1
                ? $match[1]
                : hash('sha256', $credential);
            $limiter->consume('recovery_session', $sessionSubject, 5, 60);
            $requestId = $this->requestId();
            $result = app()->make(UpgradeRecoveryCapabilityService::class)
                ->takeover($credential, $requestId, time());

            return $this->capabilityResponse($result, '升级会话已接管');
        } catch (Throwable $exception) {
            return $this->mapError($exception, true);
        }
    }

    public function rotateRecovery(): Response
    {
        try {
            $this->strictBody([]);
            $result = app()->make(UpgradeRecoveryCapabilityService::class)->rotate(
                $this->ownerCookie(),
                $this->requestId(),
                time(),
            );

            return $this->capabilityResponse($result, '恢复凭据已轮换');
        } catch (Throwable $exception) {
            return $this->mapError($exception);
        }
    }

    public function confirmRecovery(): Response
    {
        try {
            $body = $this->strictBody(['request_id', 'confirmation_nonce']);
            if (!is_string($body['request_id']) || !is_string($body['confirmation_nonce'])) {
                throw new \RuntimeException('UPGRADE_JSON_INVALID');
            }
            $result = app()->make(UpgradeRecoveryCapabilityService::class)->confirm(
                $this->ownerCookie(),
                strtolower($body['request_id']),
                $body['confirmation_nonce'],
                time(),
            );

            return $this->success($result, '恢复凭据已确认')->header($this->securityHeaders());
        } catch (Throwable $exception) {
            return $this->mapError($exception);
        }
    }

    public function bootstrapPlatform(): Response
    {
        try {
            $this->strictBody([]);
            $result = app()->make(AgentPlatformBootstrapService::class)->ensureConnected('backend_php');
            $data = [
                'connected' => $result->ok,
                'error_code' => $result->ok ? '' : $this->safeCode($result->error),
                'retryable' => !$result->ok && $result->error !== 'PLATFORM_TOKEN_RECOVERY_REQUIRED',
            ];

            return $this->success($data, $result->ok ? '平台连接正常' : '平台连接暂不可用')
                ->header($this->securityHeaders());
        } catch (Throwable $exception) {
            return $this->mapError($exception);
        }
    }

    public function createJob(): Response
    {
        try {
            $body = $this->strictBody(['target_version', 'expected_revision']);
            $principal = $this->principal();
            if (!is_string($body['target_version']) || !is_int($body['expected_revision'])
                || ($principal['revision'] ?? null) !== $body['expected_revision']) {
                throw new \RuntimeException('UPGRADE_JOB_ARGUMENT_INVALID');
            }
            $result = app()->make(UpgradeJobControlService::class)->create(
                $this->ownerCookie(),
                (string) ($principal['session_id'] ?? ''),
                $body['expected_revision'],
                $body['target_version'],
                $this->requestId(),
            );

            return $this->success($result, '升级任务已创建')->header($this->securityHeaders());
        } catch (Throwable $exception) {
            return $this->mapError($exception);
        }
    }

    public function startDrain(string $jobId): Response
    {
        try {
            $body = $this->strictBody(['expected_revision']);
            $principal = $this->principal();
            if (!is_int($body['expected_revision'])) {
                throw new \RuntimeException('UPGRADE_JOB_ARGUMENT_INVALID');
            }
            $result = app()->make(UpgradeJobControlService::class)->startDrain(
                $this->ownerCookie(),
                strtolower($jobId),
                (int) ($principal['revision'] ?? 0),
                $body['expected_revision'],
            );

            return $this->success($result, '系统开始安全排空')->header($this->securityHeaders());
        } catch (Throwable $exception) {
            return $this->mapError($exception);
        }
    }

    public function controlJob(string $jobId): Response
    {
        try {
            $body = $this->strictBody(['action', 'expected_revision']);
            $principal = $this->principal();
            if (!is_string($body['action']) || !is_int($body['expected_revision'])) {
                throw new \RuntimeException('UPGRADE_JOB_ARGUMENT_INVALID');
            }
            $result = app()->make(UpgradeJobControlService::class)->control(
                $this->ownerCookie(),
                strtolower($jobId),
                (int) ($principal['revision'] ?? 0),
                $body['expected_revision'],
                $this->requestId(),
                $body['action'],
            );

            return $this->success($result, '升级控制请求已提交')->header($this->securityHeaders());
        } catch (Throwable $exception) {
            return $this->mapError($exception);
        }
    }

    /** @param list<string> $fields @return array<string, mixed> */
    private function strictBody(array $fields): array
    {
        $lengthHeader = trim((string) $this->request->header('Content-Length', ''));
        $contentLength = null;
        if ($lengthHeader !== '') {
            if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $lengthHeader) !== 1
                || strlen($lengthHeader) > 5 || (int) $lengthHeader > 8192) {
                throw new \RuntimeException('UPGRADE_JSON_INVALID');
            }
            $contentLength = (int) $lengthHeader;
        }

        return app()->make(UpgradeStrictJsonDecoder::class)->decode(
            (string) $this->request->getContent(),
            (string) $this->request->header('Content-Type', ''),
            $contentLength,
            $fields,
        );
    }

    /** @return array<string, mixed> */
    private function principal(): array
    {
        $principal = $this->request->upgrade_session ?? null;
        if (!is_array($principal)) {
            throw new \RuntimeException('UPGRADE_SESSION_UNAUTHORIZED');
        }

        return $principal;
    }

    private function ownerCookie(): string
    {
        $owner = $this->request->upgrade_owner_cookie ?? '';
        if (!is_string($owner) || $owner === '') {
            throw new \RuntimeException('UPGRADE_SESSION_UNAUTHORIZED');
        }

        return $owner;
    }

    private function requestId(): string
    {
        return strtolower(trim((string) $this->request->header('Idempotency-Key', '')));
    }

    /** @param array<string, mixed> $result */
    private function capabilityResponse(array $result, string $message): Response
    {
        $ownerCookie = (string) ($result['owner_cookie'] ?? '');
        unset($result['owner_cookie']);
        $result['recovery_request_id'] = $result['request_id'] ?? '';
        $result['upgrade_url'] = '/upgrade/';

        return $this->success($result, $message)
            ->cookie(UpgradeSessionAuthMiddleware::COOKIE_NAME, $ownerCookie, [
                'expire' => 86400,
                'path' => '/upgrade/',
                'secure' => str_starts_with((string) config('upgrade.public_origin', ''), 'https://'),
                'httponly' => true,
                'samesite' => 'Strict',
            ])
            ->header($this->securityHeaders());
    }

    private function mapError(Throwable $exception, bool $genericRecovery = false): Response
    {
        $code = $exception->getMessage();
        if ($genericRecovery && in_array($code, [
            'UPGRADE_SESSION_UNAUTHORIZED', 'UPGRADE_SESSION_INVALID', 'UPGRADE_SESSION_UNAVAILABLE',
        ], true)) {
            $code = 'UPGRADE_SESSION_UNAUTHORIZED';
        }
        [$status, $message] = match ($code) {
            'UPGRADE_JSON_INVALID', 'UPGRADE_SESSION_ARGUMENT_INVALID', 'UPGRADE_JOB_ARGUMENT_INVALID' => [422, '升级请求参数无效'],
            'UPGRADE_OWNER_NOT_STALE', 'UPGRADE_SESSION_CONFLICT', 'UPGRADE_SESSION_CONFIRMATION_REQUIRED',
            'UPGRADE_SESSION_JOB_CONFLICT', 'UPGRADE_JOB_CONFLICT', 'UPGRADE_JOB_STATE_CONFLICT',
            'UPGRADE_CONTROL_CONFLICT', 'UPGRADE_RELEASE_NOT_COMPATIBLE' => [409, '升级会话或任务状态已变化'],
            'UPGRADE_RATE_LIMITED' => [429, '请求过于频繁，请稍后再试'],
            'UPGRADE_ORIGIN_INVALID', 'UPGRADE_CSRF_INVALID' => [403, '升级请求校验失败'],
            'UPGRADE_SESSION_UNAUTHORIZED' => [401, '恢复凭据无效或已失效'],
            default => [503, '升级服务暂时不可用'],
        };

        return json([
            'code' => $status,
            'message' => $message,
            'data' => ['reason' => preg_match('/^[A-Z0-9_]{1,64}$/D', $code) === 1 ? $code : 'UPGRADE_UNAVAILABLE'],
            'timestamp' => time(),
        ], $status)->header($this->securityHeaders());
    }

    private function safeCode(string $value): string
    {
        return preg_match('/^[A-Z0-9_]{1,64}$/D', $value) === 1 ? $value : 'PLATFORM_UNAVAILABLE';
    }

    /** @return array<string, string> */
    private function securityHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
        ];
    }
}
