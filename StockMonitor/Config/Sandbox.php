<?php
declare(strict_types=1);

/**
 * 插件安全声明（Sandbox）。
 *
 * 说明：本插件以「只读」方式工作，声明如下，便于审计与安全审查：
 *
 *  - 数据库：仅对库存相关表执行 SELECT 查询，不做任何 INSERT / UPDATE / DELETE。
 *  - 文件系统：仅在插件自身目录（app/Plugin/StockMonitor/）下的 Runtime/ 写入巡检状态，
 *              不读取、不修改、不删除站点其它任何文件。
 *  - 网络：仅向 WxPusher 官方接口 wxpusher.zjiecode.com 发送一次 POST（推送通知），
 *          不访问其它外部地址，不上传、不外传任何服务器信息。
 *  - 敏感信息：绝不读取数据库账号、连接串等凭据；推送内容仅包含「商品名 / 规格名 / 库存」，
 *              不含任何服务器路径、IP、数据库信息；运行日志不记录 AppToken 与 UID。
 *
 * ignore_plugin 为框架字段：列出不需要被「分站环境」做命名空间/表前缀重写的子插件名（本插件无子插件，留空）。
 */
return [
    "ignore_plugin" => [],

    // —— 以下为本插件的安全自述字段（供人工审计参考，框架不会据此执行强制隔离）——
    "db"            => "read-only",
    "db_tables"     => ["item", "item_sku", "repertory_item_sku_cache"],
    "fs_write"      => "only:self/Runtime",
    "network"       => ["wxpusher.zjiecode.com"],
    "leak_secrets"  => false,
];
