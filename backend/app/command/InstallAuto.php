<?php

declare(strict_types=1);

namespace app\command;

use app\service\install\InstallService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * Docker 全套零向导自动安装命令
 *
 * 使用场景：
 * - 方式三 Docker 开发（全套）/ 方式四 Docker 生产（全套）
 * - 由 compose init 容器 install-auto 调用
 *
 * 幂等保证：
 * - install.lock 存在则直接退出 0，不重复执行安装
 *
 * 参数来源：
 * - 全部从进程 env 读取，不接受命令行参数
 * - 所需变量由 ensure-env 脚本写入 backend/.env 后，通过 env_file 注入容器
 *
 * 使用示例：
 * ```bash
 * # 在 install-auto 容器内由 compose 自动执行
 * php think install:auto
 * ```
 */
class InstallAuto extends Command
{
    protected function configure(): void
    {
        $this->setName('install:auto')
            ->setDescription('使用 env 中的连接信息自动执行安装（方式三/四 Docker 全套零向导）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $service = new InstallService();

        if ($service->isInstalled()) {
            $output->writeln('<info>[install:auto] install.lock 已存在，跳过</info>');
            return 0;
        }

        $output->writeln('<info>[install:auto] 开始自动安装…</info>');

        $params = $service->buildParamsFromEnv();

        $missing = [];
        foreach (['db_host', 'db_user', 'db_name', 'redis_host'] as $key) {
            if ($params[$key] === '' || $params[$key] === null) {
                $missing[] = strtoupper(str_replace('_', '_', $key));
            }
        }
        if ($missing !== []) {
            $output->writeln('<error>[install:auto] 缺少必要 env 变量：' . implode(', ', $missing) . '</error>');
            $output->writeln('<error>[install:auto] 请检查 ensure-env 是否已生成 backend/.env</error>');
            return 1;
        }

        $output->writeln(sprintf(
            '<comment>[install:auto] DB=%s@%s:%s/%s Redis=%s:%s Admin=%s Demo=%s</comment>',
            $params['db_user'],
            $params['db_host'],
            $params['db_port'],
            $params['db_name'],
            $params['redis_host'],
            $params['redis_port'],
            $params['admin_user'],
            $params['import_demo'] ? 'yes' : 'no'
        ));

        try {
            $result = $service->execute($params);
        } catch (\Throwable $e) {
            $output->writeln('<error>[install:auto] 执行异常: ' . $e->getMessage() . '</error>');
            return 1;
        }

        if (empty($result['success'])) {
            $step = $result['step'] ?? 'unknown';
            $message = $result['message'] ?? '未知错误';
            $output->writeln("<error>[install:auto] 失败（step={$step}）: {$message}</error>");
            return 1;
        }

        $output->writeln('<info>[install:auto] done</info>');
        return 0;
    }
}
