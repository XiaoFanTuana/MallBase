<?php

declare(strict_types=1);

namespace app\middleware;

use think\Request;

/**
 * CORS 跨域中间件。
 *
 * 仅允许 CORS_ALLOWED_ORIGINS 中配置的来源，默认不允许跨域 Cookie 凭据。
 * 本中间件必须位于全局链首位，避免 OPTIONS 预检被安装检查或升级维护门禁拦截。
 * 升级页面使用 HttpOnly Cookie，并由服务端执行同源校验。
 */
class CorsMiddleware
{
    private const ALLOW_METHODS = 'GET, POST, PUT, DELETE, OPTIONS';
    private const ALLOW_HEADERS = 'Authorization, Content-Type, X-Requested-With, X-MallBase-Client';
    private const MAX_AGE       = '86400';

    public function handle(Request $request, \Closure $next)
    {
        $origin = trim((string) $request->header('origin', ''));

        $response = $request->isOptions()
            ? response('', 204)
            : $next($request);

        if ($origin === '' || !$this->isAllowedOrigin($origin)) {
            return $response;
        }

        $headers = [
            'Access-Control-Allow-Origin'  => $origin,
            'Access-Control-Allow-Methods' => self::ALLOW_METHODS,
            'Access-Control-Allow-Headers' => self::ALLOW_HEADERS,
            'Access-Control-Max-Age'       => self::MAX_AGE,
            'Vary'                         => 'Origin',
        ];

        if ($this->allowCredentials()) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        return $response->header($headers);
    }

    private function isAllowedOrigin(string $origin): bool
    {
        $allowedOrigins = $this->allowedOrigins();
        if ($allowedOrigins === []) {
            return false;
        }

        if (in_array($origin, $allowedOrigins, true)) {
            return true;
        }

        return !$this->allowCredentials() && in_array('*', $allowedOrigins, true);
    }

    /**
     * @return array<int, string>
     */
    private function allowedOrigins(): array
    {
        $raw = trim((string) env('CORS_ALLOWED_ORIGINS', ''));
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $origin): string => trim($origin),
            explode(',', $raw),
        ), static fn (string $origin): bool => $origin !== ''));
    }

    private function allowCredentials(): bool
    {
        return filter_var(env('CORS_ALLOW_CREDENTIALS', false), FILTER_VALIDATE_BOOLEAN);
    }
}
