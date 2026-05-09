<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 客户端搜索与商品保障升级命令。
 *
 * 已安装环境执行本命令可补齐搜索日志表与客户端商品保障配置；新装环境由 install schema 覆盖。
 */
class UpgradeClientSearchSchema extends Command
{
    protected function configure(): void
    {
        $this->setName('upgrade:client-search-schema')
            ->setDescription('补齐客户端搜索日志表与商品保障配置');
    }

    protected function execute(Input $input, Output $output): int
    {
        $prefix = config('database.connections.mysql.prefix', 'mb_');
        $searchLogTable = $prefix . 'search_log';

        try {
            $this->createSearchLogTable($output, $searchLogTable);
            $this->syncClientGuaranteesSetting($output);

            $output->info('升级完成');
            return 0;
        } catch (\Throwable $e) {
            $output->error('升级失败：' . $e->getMessage());
            return 1;
        }
    }

    private function createSearchLogTable(Output $output, string $table): void
    {
        if ($this->tableExists($table)) {
            $output->writeln('<info>' . $table . ' 表已存在，跳过创建</info>');
            return;
        }

        Db::execute(sprintf(
            "CREATE TABLE `%s` (
              `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
              `keyword` varchar(50) NOT NULL COMMENT '搜索关键词',
              `normalized_keyword` varchar(50) NOT NULL COMMENT '归一化关键词',
              `user_id` int(11) unsigned DEFAULT NULL COMMENT '用户ID',
              `platform` varchar(30) NOT NULL DEFAULT 'h5' COMMENT '来源平台',
              `ip_hash` char(64) NOT NULL COMMENT '匿名 IP 哈希',
              `search_count` int(11) unsigned NOT NULL DEFAULT 1 COMMENT '搜索次数',
              `last_search_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '最后搜索时间',
              `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
              `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
              PRIMARY KEY (`id`),
              KEY `idx_normalized_keyword` (`normalized_keyword`),
              KEY `idx_last_search_time` (`last_search_time`),
              KEY `idx_user_platform` (`user_id`, `platform`),
              KEY `idx_ip_platform` (`ip_hash`, `platform`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户端搜索日志表'",
            $table,
        ));

        $output->writeln('<info>+ ' . $table . ' 表已创建</info>');
    }

    private function syncClientGuaranteesSetting(Output $output): void
    {
        $setting = Db::name('setting')->where('code', 'client_goods_guarantees')->find();
        if ($setting !== null) {
            $output->writeln('<info>client_goods_guarantees 配置已存在，跳过创建</info>');
            return;
        }

        $group = Db::name('setting_group')->where('code', 'ClientConfig')->find();
        if ($group === null) {
            $output->writeln('<comment>未找到 ClientConfig 设置分组，跳过商品保障配置写入</comment>');
            return;
        }

        Db::name('setting')->insert([
            'group_id' => (int) $group['id'],
            'name' => '商品保障',
            'code' => 'client_goods_guarantees',
            'value' => json_encode($this->defaultGuarantees(), JSON_UNESCAPED_UNICODE),
            'type' => 'json',
            'options' => null,
            'rules' => null,
            'placeholder' => null,
            'remark' => '客户端商品详情页展示的服务保障，JSON 数组格式',
            'sort' => 150,
        ]);

        $output->writeln('<info>+ client_goods_guarantees 配置已创建</info>');
    }

    private function tableExists(string $table): bool
    {
        $result = Db::query(
            'SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        );

        return ((int) ($result[0]['c'] ?? 0)) > 0;
    }

    /**
     * @return array<int, array{title:string, desc:string, icon:string}>
     */
    private function defaultGuarantees(): array
    {
        return [
            ['title' => '正品保障', 'desc' => '平台严选好物', 'icon' => 'shield'],
            ['title' => '极速发货', 'desc' => '订单快速出库', 'icon' => 'truck'],
            ['title' => '七天无理由', 'desc' => '符合条件可退换', 'icon' => 'refresh'],
            ['title' => '售后无忧', 'desc' => '专属服务跟进', 'icon' => 'service'],
        ];
    }
}
