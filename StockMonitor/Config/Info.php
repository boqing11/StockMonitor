<?php
declare(strict_types=1);

/**
 * MCY-SHOP 插件：库存监控（StockMonitor）
 *
 * 该文件为插件清单（元数据），由系统在加载插件时读取。
 * 字段说明：
 *  - name        插件显示名称
 *  - author      作者
 *  - description 插件简介
 *  - version     版本号
 *  - arch        运行架构：ARCH_CLI(命令行) / ARCH_FPM(HTTP)；库存监控只在 CLI 定时任务中运行
 *  - scope       生效范围：HOOK_SCOPE_GLOBAL(仅主站) / HOOK_SCOPE_USR(主站+分站)
 *  - type        插件类型：TYPE_GENERAL(通用)
 *
 * 注意：本插件只读取库存数据并通过 WxPusher 推送，不会修改任何业务数据。
 */
return [
    "name"        => "库存监控",
    "author"      => "Sine",
    "description" => "监控商品 SKU 库存，缺货 / 低库存 / 补货时通过 WxPusher 推送到微信，服务端定时运行，无需本地挂机。",
    "version"     => "1.0.0",
    "arch"        => \Kernel\Plugin\Const\Plugin::ARCH_CLI,
    "scope"       => \Kernel\Plugin\Const\Plugin::HOOK_SCOPE_GLOBAL,
    "type"        => \Kernel\Plugin\Const\Plugin::TYPE_GENERAL,
];
