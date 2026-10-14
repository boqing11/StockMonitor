<?php
declare(strict_types=1);

/**
 * 注册控制台命令。
 *
 * 结构：[ 命令名 => [类, 方法, 命令标题, 命令说明] ]
 * 启用本插件后，系统会把下面两个命令注册进 console：
 *
 *   stock.monitor       正式执行：巡检库存并按需推送 WxPusher
 *   stock.monitor.test  试运行  ：只巡检并打印结果，不发送任何推送
 *
 * 运行方式（在站点根目录）：
 *   ./bin index.php console stock.monitor
 *   ./bin index.php console stock.monitor.test
 * 若已安装 mcy 快捷命令（config/command.php 会自动 cp console.sh 到 /usr/local/bin/mcy）：
 *   mcy stock.monitor
 *   mcy stock.monitor.test
 */
return [
    "stock.monitor" => [
        \App\Plugin\StockMonitor\Command\Monitor::class,
        "run",
        "库存监控",
        "执行一次库存巡检，缺货/低库存/补货时通过 WxPusher 推送",
    ],
    "stock.monitor.test" => [
        \App\Plugin\StockMonitor\Command\Monitor::class,
        "run",
        "库存监控-试运行",
        "仅巡检并打印结果，不发送推送，用于验证配置是否正确",
    ],
];
