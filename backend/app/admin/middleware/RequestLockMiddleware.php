<?php

declare (strict_types=1);

namespace app\admin\middleware;

use Closure;
use mall_base\exception\BusinessException;
use think\facade\Cache;
use think\Request;
use think\Response;

/**
 * 防重复提交中间件
 */
class RequestLockMiddleware
{
    /**
     * 缓存前缀
     */
    protected string $cachePrefix = 'request_lock:';

    /**
     * 锁定时间（秒）
     */
    protected int $lockTime = 3;

    /**
     * 是否启用
     */
    protected bool $enable = true;

    /**
     * 处理请求
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     * @throws BusinessException
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->enable) {
            return $next($request);
        }

        // 只对 POST/PUT/DELETE 请求进行锁定
        if (!in_array($request->method(), ['POST', 'PUT', 'DELETE'])) {
            return $next($request);
        }

        // 生成锁定键
        $lockKey = $this->getLockKey($request);

        // 检查是否已锁定
        if (Cache::get($lockKey)) {
            throw new BusinessException('操作过于频繁，请稍后再试', 429);
        }

        // 加锁
        Cache::set($lockKey, 1, $this->lockTime);

        try {
            // 执行请求
            $response = $next($request);

            // 如果请求成功，保留锁（防止重复提交）
            // 如果请求失败，可以释放锁（根据业务需求调整）
            if ($response->getCode() == 200) {
                // 请求成功，保留锁定时间
            } else {
                // 请求失败，释放锁（允许重试）
                // Cache::delete($lockKey);
            }

            return $response;
        } catch (\Exception $e) {
            // 发生异常，释放锁（允许重试）
            Cache::delete($lockKey);
            throw $e;
        }
    }

    /**
     * 生成锁定键
     *
     * @param Request $request
     * @return string
     */
    protected function getLockKey(Request $request): string
    {
        // 基于以下因素生成唯一键：
        // 1. 用户ID（如果已登录）
        // 2. 请求路径
        // 3. 请求参数（POST/PUT 数据）

        $adminId = $request->admin_id ?? 'guest';
        $path = $request->pathinfo();
        $method = $request->method();

        // 获取请求参数（排除 token 等字段）
        $params = $this->getRequestParams($request);
        $paramsString = md5(json_encode($params));

        return $this->cachePrefix . $adminId . ':' . $path . ':' . $method . ':' . $paramsString;
    }

    /**
     * 获取请求参数
     *
     * @param Request $request
     * @return array
     */
    protected function getRequestParams(Request $request): array
    {
        $params = $request->param();

        // 排除不需要参与锁定的字段
        $excludeFields = ['token', '_timestamp', '_nonce'];
        foreach ($excludeFields as $field) {
            unset($params[$field]);
        }

        return $params;
    }

    /**
     * 释放锁定
     *
     * @param Request $request
     * @return bool
     */
    public static function release(Request $request): bool
    {
        $middleware = new self();
        $lockKey = $middleware->getLockKey($request);
        return Cache::delete($lockKey);
    }
}