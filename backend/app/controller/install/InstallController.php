<?php

declare(strict_types=1);

namespace app\controller\install;

use app\service\install\InstallService;
use think\Request;
use think\Response;

class InstallController
{
    protected InstallService $service;

    public function __construct()
    {
        $this->service = new InstallService();
    }

    public function check(): Response
    {
        $result = $this->service->checkEnvironment();
        return json(['code' => 200, 'data' => $result, 'message' => 'ok']);
    }

    public function status(): Response
    {
        $installed = $this->service->isInstalled();
        $data = [
            'installed'    => $installed,
            'installed_at' => null,
            'version'      => null,
        ];
        if ($installed) {
            $info = $this->service->getLockInfo() ?? [];
            $data['installed_at'] = $info['installed_at'] ?? null;
            $data['version']      = $info['version'] ?? null;
        }
        return json(['code' => 200, 'data' => $data, 'message' => 'ok']);
    }

    public function testDb(Request $request): Response
    {
        $config = [
            'host' => $request->post('db_host', '127.0.0.1'),
            'port' => $request->post('db_port', 3306),
            'user' => $request->post('db_user', 'root'),
            'pass' => $request->post('db_pass', ''),
            'name' => $request->post('db_name', 'mallbase'),
        ];

        $result = $this->service->testDatabase($config);
        $code = $result['success'] ? 200 : 400;

        return json(['code' => $code, 'data' => $result, 'message' => $result['message']]);
    }

    public function testRedis(Request $request): Response
    {
        $config = [
            'host'     => $request->post('redis_host', '127.0.0.1'),
            'port'     => $request->post('redis_port', 6379),
            'password' => $request->post('redis_password', ''),
        ];

        $result = $this->service->testRedis($config);
        $code = $result['success'] ? 200 : 400;

        return json(['code' => $code, 'data' => $result, 'message' => $result['message']]);
    }

    public function execute(Request $request): Response
    {
        $params = $request->post();
        $validation = $this->validateInstallParams($params);
        if ($validation !== null) {
            return $validation;
        }

        $result = $this->service->execute($params);
        $code = $result['success'] ? 200 : 400;

        return json(['code' => $code, 'data' => $result, 'message' => $result['message']]);
    }

    public function executeStream(Request $request)
    {
        $params = $request->post();

        $validation = $this->validateInstallInput($params);
        $this->prepareStreamOutput();

        if (!$validation['success']) {
            $this->sendStreamEvent('complete', [
                'success' => false,
                'message' => $validation['message'],
                'result'  => [
                    'success'  => false,
                    'step'     => 'validate',
                    'message'  => $validation['message'],
                    'steps'    => [],
                    'redirect' => false,
                ],
            ]);
            exit;
        }

        try {
            $result = $this->service->execute($params, function (array $event): void {
                $name = $event['event'] ?? 'progress';
                unset($event['event']);
                $this->sendStreamEvent($name, $event);
            });

            if (!$result['success']) {
                $this->sendStreamEvent('complete', [
                    'success' => false,
                    'message' => $result['message'],
                    'result'  => $result,
                ]);
            }
        } catch (\Throwable $e) {
            $this->sendStreamEvent('complete', [
                'success' => false,
                'message' => '安装执行异常：' . $e->getMessage(),
                'result'  => [
                    'success'  => false,
                    'step'     => 'exception',
                    'message'  => '安装执行异常：' . $e->getMessage(),
                    'steps'    => [],
                    'redirect' => false,
                ],
            ]);
        }

        exit;
    }

    private function validateInstallParams(array $params): ?Response
    {
        $validation = $this->validateInstallInput($params);
        if ($validation['success']) {
            return null;
        }

        return json(['code' => 400, 'message' => $validation['message'], 'data' => null]);
    }

    private function validateInstallInput(array $params): array
    {
        $required = ['db_host', 'db_user', 'db_name', 'admin_user', 'admin_pass', 'redis_host'];
        foreach ($required as $field) {
            if (empty($params[$field])) {
                return ['success' => false, 'message' => "缺少必填字段: {$field}"];
            }
        }

        if (strlen((string) $params['admin_pass']) < 6) {
            return ['success' => false, 'message' => '管理员密码至少 6 位'];
        }

        return ['success' => true, 'message' => 'ok'];
    }

    private function prepareStreamOutput(): void
    {
        ignore_user_abort(true);
        @set_time_limit(0);
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
    }

    private function sendStreamEvent(string $event, array $payload): void
    {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";

        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
    }
}
