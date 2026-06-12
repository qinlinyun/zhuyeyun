<?php
/**
 * 其它设置 — 一级 / 二级导航配置
 * 后续在某一级下追加 children 即可展开二级导航
 */
return [
    [
        'id' => 'overview',
        'label' => '总览',
    ],
    [
        'id' => 'register',
        'label' => '注册功能',
        'children' => [
            ['id' => 'toggle', 'label' => '注册开关'],
            ['id' => 'closed_page', 'label' => '关闭注册页面配置'],
            ['id' => 'page', 'label' => '注册页面配置'],
            ['id' => 'schedule', 'label' => '定时开/关注册功能'],
        ],
    ],
    [
        'id' => 'analytics',
        'label' => '数据分析',
        'children' => [
            ['id' => 'settings', 'label' => '统计减负设置'],
            ['id' => 'user_growth', 'label' => '用户增长趋势'],
            ['id' => 'video_clicks', 'label' => '视频点击量排行'],
            ['id' => 'user_visits', 'label' => '用户访问趋势'],
            ['id' => 'ip_visits', 'label' => 'IP访问趋势'],
        ],
    ],
    [
        'id' => 'theme',
        'label' => '主题配置',
        'children' => [
            ['id' => 'background', 'label' => '背景设置'],
            ['id' => 'dark_colors', 'label' => '深色主题颜色'],
            ['id' => 'light_colors', 'label' => '浅色主题颜色'],
        ],
    ],
    [
        'id' => 'font',
        'label' => '字体配置',
        'children' => [
            ['id' => 'global', 'label' => '全局字体'],
        ],
    ],
    [
        'id' => 'player',
        'label' => '播放器管理',
        'children' => [
            ['id' => 'proxy', 'label' => '开启/关闭后端代理'],
            ['id' => 'video_data', 'label' => '视频数据'],
            ['id' => 'video_token', 'label' => '视频token设置'],
            ['id' => 'api_sync', 'label' => '视频数据API同步接口配置'],
        ],
    ],
    [
        'id' => 'redis',
        'label' => 'Redis',
        'children' => [
            ['id' => 'config', 'label' => 'Redis 配置'],
        ],
    ],
    [
        'id' => 'announcement',
        'label' => '公告管理',
    ],
    [
        'id' => 'earning_traffic',
        'label' => '收益流量管理',
    ],
];
