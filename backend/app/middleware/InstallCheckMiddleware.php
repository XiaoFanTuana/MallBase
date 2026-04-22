<?php

declare(strict_types=1);

namespace app\middleware;

use app\service\install\InstallService;
use Closure;
use think\Request;
use think\Response;

class InstallCheckMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var InstallService $installService */
        $installService = app()->make(InstallService::class);
        $path = $request->pathinfo();
        $isInstallRoute = str_starts_with($path, 'install/') || $path === 'install';
        $isInstallApi = str_starts_with($path, 'install/api/');
        $isInstallStatusApi = $path === 'install/api/status';
        $isInstallAdminReadyApi = $path === 'install/api/adminReady';
        $isInstalled = $installService->isInstalled();

        if ($isInstalled
            && $isInstallRoute
            && $isInstallApi
            && !$isInstallStatusApi
            && !$isInstallAdminReadyApi
        ) {
            return json([
                'code' => 400,
                'message' => '系统已安装，请刷新安装状态',
                'data' => ['installed' => true],
                'timestamp' => time(),
            ]);
        }

        if (!$isInstalled && !$isInstallRoute) {
            if ($request->isAjax() || str_contains($request->header('accept', ''), 'application/json')) {
                return json([
                    'code' => 400,
                    'message' => '系统未安装',
                    'data' => [
                        'installed' => false,
                        'redirect' => '/install',
                    ],
                    'timestamp' => time(),
                ]);
            }

            return redirect('/install');
        }

        return $next($request);
    }
}
