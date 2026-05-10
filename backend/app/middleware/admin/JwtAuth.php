<?php

declare (strict_types=1);

namespace app\middleware\admin;

use Closure;
use mall_base\exception\AuthException;
use mall_base\service\JwtService;
use think\Request;
use think\Response;

/**
 * JWT 认证中间件
 */
class JwtAuth
{
    /**
     * Token 字段名
     */
    protected string $tokenField = 'token';

    /**
     * Token 位置：header、query、body
     */
    protected string $tokenLocation = 'header';

    /**
     * 处理请求
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     * @throws AuthException
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 获取 Token
        $token = $this->getToken($request);

        if (empty($token)) {
            throw new AuthException('未提供认证令牌');
        }

        // 验证 Token
        $jwtService = new JwtService();

        try {
            $decoded = $jwtService->decode($token);
        } catch (\Exception $e) {
            throw new AuthException('Token 无效或已过期');
        }

        if (($decoded->data->type ?? null) !== 'access') {
            throw new AuthException('Token 类型无效');
        }

        // 将用户信息存入请求对象
        $request->admin_id = $decoded->data->admin_id ?? null;
        $request->username = $decoded->data->username ?? null;
        $request->nickname = $decoded->data->nickname ?? null;
        $request->token = $token;

        // 强制首次改密：若 JWT 载荷标记 must_change_password，仅放行改密/自身信息/登出三个端点。
        // Why：避免用户跳过前端守卫直接调用业务 API（例如用 Postman 带 token 调 /admin/api/goods/list）。
        $mustChange = $decoded->data->must_change_password ?? false;
        if ($mustChange === true) {
            $path = trim($request->pathinfo(), '/');
            $allowed = [
                'admin/api/auth/admin/changePassword',
                'admin/api/auth/admin/adminInfo',
                'admin/api/auth/admin/logout',
            ];
            if (!in_array($path, $allowed, true)) {
                throw new AuthException('首次登录必须先修改默认密码');
            }
        }

        return $next($request);
    }

    /**
     * 获取 Token
     *
     * @param Request $request
     * @return string|null
     */
    protected function getToken(Request $request): ?string
    {
        $token = null;

        // 从 Header 获取
        if ($this->tokenLocation === 'header') {
            $token = $request->header('Authorization');
            if ($token && strpos($token, 'Bearer ') === 0) {
                $token = substr($token, 7);
            }
        }

        // 从 Query 获取
        if (empty($token) && $this->tokenLocation === 'query') {
            $token = $request->param($this->tokenField);
        }

        // 从 Body 获取
        if (empty($token) && $this->tokenLocation === 'body') {
            $token = $request->post($this->tokenField);
        }

        return $token ?: null;
    }
}
