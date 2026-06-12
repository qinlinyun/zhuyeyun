<?php

/**
 * 上传管理 — 导航配置（PHP 上传至远程后端）
 *
 * @return list<array<string, mixed>>
 */
return [
    [
        'id' => 'overview',
        'label' => '总览',
        'icon' => 'overview',
        'description' => 'PHP 上传与待审核概览',
    ],
    [
        'id' => 'infrastructure',
        'label' => '基础设施',
        'icon' => 'server',
        'description' => 'PHP 上传与转码后端',
        'children' => [
            [
                'id' => 'php',
                'label' => 'PHP 上传',
                'description' => '存储路径与用户目录规则',
            ],
            [
                'id' => 'api',
                'label' => '转码后端',
                'description' => '远程接收视频与 FFmpeg 切片',
            ],
        ],
    ],
    [
        'id' => 'content',
        'label' => '内容与审核',
        'icon' => 'content',
        'description' => '审核队列与已发布视频',
        'children' => [
            [
                'id' => 'review',
                'label' => '待审核',
                'description' => '用户 PHP 上传记录',
                'badge' => 'pending',
            ],
            [
                'id' => 'published',
                'label' => '已发布管理',
                'description' => '已审核视频与发布信息',
            ],
        ],
    ],
    [
        'id' => 'settings',
        'label' => '策略配置',
        'icon' => 'settings',
        'description' => '流量视频与播放域分配',
        'children' => [
            [
                'id' => 'video',
                'label' => '视频策略',
                'description' => '流量视频与加密播放',
            ],
            [
                'id' => 'domains',
                'label' => '域名分配',
                'description' => '用户组播放/封面域名',
            ],
        ],
    ],
];
