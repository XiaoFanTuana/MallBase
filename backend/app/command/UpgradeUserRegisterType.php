<?php

declare(strict_types=1);

namespace app\command;

use app\common\enum\RegisterType;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 历史兼容修复：把 mb_user.register_type 中的 legacy 'wechat' 升级为 'wechat_miniapp'
 *
 * 使用场景：
 *  - register_type 在引入"微信三端区分"之前只有一个笼统的 'wechat' 值
 *  - 在 wechat_miniapp / wechat_official / h5 等取值引入后，已装环境的存量
 *    'wechat' 数据语义保持兼容（历史阶段微信注册仅来自小程序），统一升级为
 *    'wechat_miniapp'
 *  - 新装环境不需要运行（schema 注释与默认值已采用新约定，但默认值仍保留 'mobile'）
 *
 * 幂等与重试安全：
 *  - 仅 UPDATE 命中 register_type='wechat' 的行，无该值时不动任何数据
 *  - 重复执行会返回 affected=0，不产生副作用
 *
 * 使用示例：
 * ```bash
 * # 宿主直跑
 * php think upgrade:user-register-type
 *
 * # Docker 环境
 * docker compose -f docker-compose.dev.yml exec -T backend php think upgrade:user-register-type
 * ```
 */
class UpgradeUserRegisterType extends Command
{
    protected function configure(): void
    {
        $this->setName('upgrade:user-register-type')
            ->setDescription('历史兼容修复：把 mb_user.register_type 的 legacy "wechat" 升级为 "wechat_miniapp"');
    }

    protected function execute(Input $input, Output $output): int
    {
        $prefix = config('database.connections.mysql.prefix', 'mb_');
        $table = $prefix . 'user';

        try {
            // 列存在性检测（INFORMATION_SCHEMA 兼容 MySQL 5.7 / 8.0）
            $exists = Db::query(
                'SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, 'register_type']
            );
            $columnExists = ((int) ($exists[0]['c'] ?? 0)) > 0;

            if (!$columnExists) {
                $output->warning(sprintf(
                    '表 %s 不存在 register_type 列，跳过升级',
                    $table
                ));
                return 0;
            }

            $legacyCount = Db::name('user')
                ->where('register_type', RegisterType::LEGACY_WECHAT)
                ->count();

            if ($legacyCount === 0) {
                $output->info('未发现 register_type=wechat 的存量数据，无需升级');
                return 0;
            }

            $output->writeln(sprintf(
                '<comment>检测到 %d 条 register_type=wechat 的存量数据，将统一升级为 wechat_miniapp</comment>',
                $legacyCount
            ));

            $affected = Db::name('user')
                ->where('register_type', RegisterType::LEGACY_WECHAT)
                ->update(['register_type' => RegisterType::WECHAT_MINIAPP]);

            $output->info(sprintf('升级完成：%d 行已更新', $affected));
            return 0;
        } catch (\Throwable $e) {
            $output->error('升级失败：' . $e->getMessage());
            return 1;
        }
    }
}
