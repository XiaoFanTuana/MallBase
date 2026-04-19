-- ============================================
-- 演示数据：管理员角色
-- ============================================

-- 默认角色
INSERT INTO `mb_role` (`id`, `name`, `code`, `remark`, `status`, `sort`) VALUES
(1, '超级管理员', 'super_admin', '拥有全部权限', 1, 1),
(2, '运营管理员', 'operator', '商品和订单管理权限', 1, 2),
(3, '客服', 'customer_service', '仅订单查看和售后处理权限', 1, 3);

-- 超级管理员关联角色
INSERT INTO `mb_admin_role` (`admin_id`, `role_id`) VALUES
(1, 1);
