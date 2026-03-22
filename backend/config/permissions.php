<?php

/**
 * 权限配置文件
 *
 * 此文件用于配置权限的基础数据
 *
 * @package config
 */

return [
    /**
     * 基础菜单
     * 这些菜单会作为权限表的初始数据
     */
    'base_menus' => [
        [
            'name' => '系统管理',
            'code' => 'System',
            'type' => 1,
            'path' => null,
            'icon' => 'lucide:settings',
            'component' => null,
            'redirect' => null,
            'sort' => 0,
            'status' => 1,
            'is_show' => 1,
            'affix_tab' => 0,
            'no_basic_layout' => 0,
            'remark' => null,
            'children' => [
                [
                    'name' => '权限管理',
                    'code' => 'SystemPermissionManagement',
                    'type' => 1,
                    'path' => null,
                    'icon' => 'lucide:settings',
                    'component' => null,
                    'redirect' => null,
                    'sort' => 0,
                    'status' => 1,
                    'is_show' => 1,
                    'affix_tab' => 0,
                    'no_basic_layout' => 0,
                    'remark' => null,
                ]
            ]
        ],
        [
            'name' => '概览',
            'code' => 'Dashboard',
            'type' => 1,
            'path' => null,
            'icon' => 'lucide:layout-dashboard',
            'component' => null,
            'redirect' => null,
            'sort' => 0,
            'status' => 1,
            'is_show' => 1,
            'affix_tab' => 0,
            'no_basic_layout' => 0,
            'remark' => null,
            'children' => [
                [
                    'name' => '分析页',
                    'code' => 'Analytics',
                    'type' => 1,
                    'path' => '/analytics',
                    'icon' => 'lucide:area-chart',
                    'component' => 'dashboard/analytics/index',
                    'redirect' => null,
                    'sort' => 0,
                    'status' => 1,
                    'is_show' => 1,
                    'affix_tab' => 0,
                    'no_basic_layout' => 0,
                    'remark' => null,
                ],
                [
                    'name' => '工作台',
                    'code' => 'Workspace',
                    'type' => 1,
                    'path' => '/workspace',
                    'icon' => 'carbon:workspace',
                    'component' => 'dashboard/workspace/index',
                    'redirect' => null,
                    'sort' => 0,
                    'status' => 1,
                    'is_show' => 1,
                    'affix_tab' => 0,
                    'no_basic_layout' => 0,
                    'remark' => null,
                ]
            ]
        ],
        [
            'name' => '关于',
            'code' => 'VbenAbout',
            'type' => 1,
            'path' => '/vben-admin/about',
            'icon' => 'lucide:copyright',
            'component' => '_core/about/index',
            'redirect' => null,
            'sort' => 0,
            'status' => 1,
            'is_show' => 1,
            'affix_tab' => 0,
            'no_basic_layout' => 0,
            'remark' => null,
        ],
        [
            'name' => '个人中心',
            'code' => 'Profile',
            'type' => 1,
            'path' => '/profile',
            'icon' => null,
            'component' => '_core/profile/index',
            'redirect' => null,
            'sort' => 0,
            'status' => 1,
            'is_show' => 0,
            'affix_tab' => 0,
            'no_basic_layout' => 0,
            'remark' => null,
        ],
    ],

    /**
     * 应用配置
     */
    'app' => [
        'admin' => [
            'is_folder' => true,
            'path' => 'admin',
            'alias' => '后台管理',
        ],
    ],
];