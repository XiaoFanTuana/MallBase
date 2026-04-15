-- ============================================
-- 升级：运费模板规则支持多层级覆盖
-- 新增 match_level 字段用于层级优先级匹配
-- 适用：已有存量数据的库（存量 region_ids 全部为街道级 ID → 默认 4）
-- ============================================

ALTER TABLE `mb_freight_template_rule`
    ADD COLUMN `match_level` TINYINT(1) NOT NULL DEFAULT 4
        COMMENT '规则最精确层级：1省 2市 3区 4街道（匹配优先级 4>3>2>1）'
        AFTER `region_path_texts`,
    ADD KEY `idx_match_level` (`match_level`);

-- 存量规则全部为街道级，保持默认值 4 即可；此 UPDATE 为幂等兜底
UPDATE `mb_freight_template_rule` SET `match_level` = 4 WHERE `match_level` IS NULL OR `match_level` = 0;

-- 将 region_ids / region_codes / region_names / region_path_texts 注释由「街道」改为「区域」
ALTER TABLE `mb_freight_template_rule`
    MODIFY COLUMN `region_ids` json NOT NULL COMMENT '区域ID集合（可为省/市/区/街道任意层级ID）',
    MODIFY COLUMN `region_codes` json NOT NULL COMMENT '区域编码集合',
    MODIFY COLUMN `region_names` json NOT NULL COMMENT '区域名称集合',
    MODIFY COLUMN `region_path_texts` json NOT NULL COMMENT '区域路径快照集合';
