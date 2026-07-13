<?php

declare(strict_types=1);

namespace app\controller\upgrade;

use mall_base\base\BaseController;
use think\Response;

final class UpgradePageController extends BaseController
{
    public function index(): Response
    {
        return $this->asset('index.html', 'text/html; charset=utf-8');
    }

    public function script(): Response
    {
        return $this->asset('app.js', 'application/javascript; charset=utf-8');
    }

    public function styles(): Response
    {
        return $this->asset('styles.css', 'text/css; charset=utf-8');
    }

    private function asset(string $name, string $contentType): Response
    {
        $path = $this->app->getRootPath() . 'public' . DIRECTORY_SEPARATOR . 'upgrade'
            . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path)) {
            abort(404, '升级页面未构建');
        }
        $content = file_get_contents($path);
        if (!is_string($content)) {
            abort(503, '升级页面暂时不可用');
        }
        $headers = [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
        ];

        return response($content, 200, $headers);
    }
}
