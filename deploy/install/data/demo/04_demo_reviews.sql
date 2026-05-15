-- ============================================
-- 演示数据：用户 + 商品评论（与潮流商品 6-10 配套）
-- ============================================

-- ---- 演示用户（评论作者）----
INSERT INTO `mb_user` (`id`, `nickname`, `avatar`, `mobile`, `mobile_verified`, `gender`, `bio`, `status`) VALUES
(1, 'TrendSetter_A', '/static/demo/avatars/avatar-1.png', '13800000001', 1, 1, '街头穿搭爱好者', 1),
(2, 'HypeQueen_99', '/static/demo/avatars/avatar-2.png', '13800000002', 1, 2, '潮流买手 / Y2K 复兴', 1),
(3, 'MetroCity', '/static/demo/avatars/avatar-3.png', '13800000003', 1, 1, '通勤族 · 极简风', 1),
(4, 'LinaSwift', '/static/demo/avatars/avatar-4.png', '13800000004', 1, 2, '健身博主', 1),
(5, 'SoleMate', '/static/demo/avatars/avatar-5.png', '13800000005', 1, 1, '球鞋藏家', 1);

-- ---- 商品 6 StreetWave Air 1 评论 ----
INSERT INTO `mb_goods_comment` (`goods_id`, `user_id`, `sku_id`, `content`, `images`, `rating`, `status`, `create_time`) VALUES
(6, 1, NULL, '外观非常潮，白蓝配色款实物比图片更好看，脚感也比想象中软，包裹性不错。下次再回购烤白款。', '["/static/demo/goods/streetwave-air-1/swiper-0.png","/static/demo/goods/streetwave-air-1/swiper-1.png"]', 5, 1, '2025-04-12 09:24:11'),
(6, 2, NULL, '物流超级快！鞋盒包装也很精致，细节处的真心不错，皮质看着耐造，推荐购买。', NULL, 5, 1, '2025-04-11 18:02:34'),
(6, 5, NULL, '42 码偏小半码，建议肉脚朋友选大一号。鞋型和复古中底确实够潮。', '["/static/demo/goods/streetwave-air-1/swiper-2.png"]', 4, 1, '2025-04-10 11:55:08'),
(6, 3, NULL, '日常通勤搭配西裤、卫裤都没问题，做工没踩雷，整体满意。', NULL, 5, 1, '2025-04-09 21:14:50'),
(6, 4, NULL, '健身完拍照很出片，蓝色细节点睛，鞋底也防滑，五星好评。', NULL, 5, 1, '2025-04-08 14:31:22');

-- ---- 商品 7 NebulaWave T 恤 评论 ----
INSERT INTO `mb_goods_comment` (`goods_id`, `user_id`, `sku_id`, `content`, `images`, `rating`, `status`, `create_time`) VALUES
(7, 2, NULL, '面料 300g 重磅是真的，版型挺括，落肩处理很舒服。', '["/static/demo/goods/nebulawave-tee/swiper-0.png"]', 5, 1, '2025-04-13 10:08:00'),
(7, 1, NULL, '黑色 L 码，胸围 96 穿正好。胸前图案细节够看，洗后没明显变形。', NULL, 5, 1, '2025-04-12 19:42:17'),
(7, 3, NULL, '颜色饱和度不错，街拍很出片。', '["/static/demo/goods/nebulawave-tee/swiper-1.png","/static/demo/goods/nebulawave-tee/swiper-2.png"]', 4, 1, '2025-04-11 15:23:55'),
(7, 4, NULL, '夏天穿透气、不闷热，性价比挺高。', NULL, 5, 1, '2025-04-10 08:11:30'),
(7, 5, NULL, '比预期厚实，建议挑大半码。', NULL, 4, 1, '2025-04-09 22:48:01');

-- ---- 商品 8 CityRunner 卫衣 评论 ----
INSERT INTO `mb_goods_comment` (`goods_id`, `user_id`, `sku_id`, `content`, `images`, `rating`, `status`, `create_time`) VALUES
(8, 3, NULL, '碳灰款颜色非常正，做工没毛刺。袖口罗纹收得紧，骑车不灌风。', '["/static/demo/goods/cityrunner-hoodie/swiper-0.png"]', 5, 1, '2025-04-14 10:32:18'),
(8, 1, NULL, '抓绒内里挺暖和，过渡季节穿正好。版型偏宽松，时髦。', NULL, 5, 1, '2025-04-13 21:06:42'),
(8, 4, NULL, '运动后随手套一件就出门，干得快，胸口贴布 logo 也精致。', '["/static/demo/goods/cityrunner-hoodie/swiper-1.png","/static/demo/goods/cityrunner-hoodie/swiper-2.png"]', 4, 1, '2025-04-12 16:21:30'),
(8, 5, NULL, '尺码偏正常，平时 L 码穿 L 即可。', NULL, 5, 1, '2025-04-11 08:55:14'),
(8, 2, NULL, '墨绿色款非常潮，搭工装裤一绝。', NULL, 5, 1, '2025-04-10 20:14:09');

-- ---- 商品 9 StreetWave 帆布单肩包 评论 ----
INSERT INTO `mb_goods_comment` (`goods_id`, `user_id`, `sku_id`, `content`, `images`, `rating`, `status`, `create_time`) VALUES
(9, 4, NULL, '帆布厚实有质感，主仓能塞下 13 寸 iPad + 笔记本，通勤够用。', '["/static/demo/goods/streetwave-bag/swiper-0.png"]', 5, 1, '2025-04-14 09:01:00'),
(9, 1, NULL, '肩带可调节，金属扣件做工没瑕疵。', NULL, 5, 1, '2025-04-13 17:48:25'),
(9, 5, NULL, '街头风很正，男生女生都可背。', NULL, 5, 1, '2025-04-12 11:30:13'),
(9, 3, NULL, '内衬颜色有点深，找东西略费眼，整体还是值这个价。', NULL, 4, 1, '2025-04-11 14:09:50'),
(9, 2, NULL, '物流很快，包装严实，没气味。', '["/static/demo/goods/streetwave-bag/swiper-1.png"]', 5, 1, '2025-04-10 19:22:08');

-- ---- 商品 10 MetroFit 棒球帽 评论 ----
INSERT INTO `mb_goods_comment` (`goods_id`, `user_id`, `sku_id`, `content`, `images`, `rating`, `status`, `create_time`) VALUES
(10, 5, NULL, '速干面料是真的，出汗也不闷头。后部魔术贴调节范围够大。', '["/static/demo/goods/metrofit-cap/swiper-0.png"]', 5, 1, '2025-04-14 11:11:11'),
(10, 1, NULL, '黑色基础款百搭，跑步骑车都用得上。', NULL, 5, 1, '2025-04-13 20:55:33'),
(10, 4, NULL, '红色饱和度刚好不闹腾，街拍点睛。', '["/static/demo/goods/metrofit-cap/swiper-1.png","/static/demo/goods/metrofit-cap/swiper-2.png"]', 4, 1, '2025-04-12 18:40:02'),
(10, 3, NULL, '帽檐弧度做的挺好，戴着不压头。', NULL, 5, 1, '2025-04-11 09:18:46'),
(10, 2, NULL, '价格友好，质量超预期，回购了藏青色。', NULL, 5, 1, '2025-04-10 13:27:55');
