-- ============================================
-- 演示数据：系统设置
-- ============================================

-- 基础设置分组
INSERT INTO `mb_setting_group` (`id`, `parent_id`, `name`, `code`, `icon`, `description`, `sort`, `display_type`, `status`) VALUES
(1, 0, '基础设置', 'basic', 'ant-design:setting-outlined', '网站基础信息配置', 1, 'category', 1),
(2, 1, '网站信息', 'site_info', 'ant-design:global-outlined', '网站名称、Logo、版权等', 1, 'page', 1),
(3, 1, '上传设置', 'upload', 'ant-design:upload-outlined', '文件上传相关配置', 2, 'page', 1);

-- 基础设置项
INSERT INTO `mb_setting` (`group_id`, `name`, `code`, `value`, `type`, `placeholder`, `remark`, `sort`) VALUES
(2, '网站名称', 'site_name', 'MallBase 演示商城', 'input', '请输入网站名称', '显示在页面标题和后台顶部', 1),
(2, '网站Logo', 'site_logo', '', 'image', '', '建议尺寸 200x60', 2),
(2, '版权信息', 'copyright', 'Copyright © 2024 MallBase', 'input', '请输入版权信息', '显示在页面底部', 3),
(2, 'ICP备案号', 'icp_number', '', 'input', '例如：京ICP备12345678号', '', 4),
(3, '上传大小限制(MB)', 'upload_max_size', '10', 'number', '最大上传文件大小', '单位 MB', 1),
(3, '允许上传类型', 'upload_allowed_ext', 'jpg,jpeg,png,gif,webp,mp4,pdf', 'input', '用逗号分隔', '', 2);
