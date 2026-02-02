<?php

namespace app\middleware;

use think\Request;
use think\Response;

class CorsMiddleware
{
    public function handle(Request $request, \Closure $next)
    {
        $origin = $request->header('origin') ?: '*';

        /** @var Response $response */
        $response = $next($request);

        $headers = [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Methods' => 'GET,POST,PUT,DELETE,OPTIONS',
            'Access-Control-Allow-Headers' => 'Authorization,Content-Type,X-Requested-With',
        ];

        // 预检请求
        if ($request->isOptions()) {
            return response('', 204)->header($headers);
        }

        return $response->header($headers);
    }
}