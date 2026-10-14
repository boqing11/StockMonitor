#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * StockMonitor 插件「免授权」命令注册脚本
 * =====================================================================
 *
 * 【解决什么问题】
 *   后台启动插件报「此插件你没有授权……」，而该插件是你自己开发的。
 *   授权校验位于 kernel/Plugin/Plugin.php 的 start()（以及 setState 等
 *   混淆函数）中；本脚本完全绕开这条链路——不调用框架任何带校验的
 *   函数，直接向框架的命令注册表写入插件命令。
 *
 * 【原理】
 *   框架 CLI 执行命令时，Kernel\Console\Console::execute() 直接读取
 *   runtime/command（明文序列化的 Kernel\Context\Command 对象数组）。
 *   本脚本做的就是官方 Command::add() 的同一件事——把插件
 *   Config/Command.php 里声明的命令序列化进 runtime/command，
 *   区别是途中不经过 getState()/setState() 等授权校验函数。
 *
 *   runtime/command 是明文文件、重启后依然有效，因此：
 *     - 不需要改框架任何文件（kernel/、app/ 均不动）；
 *     - 不需要后台「启动」按钮；
 *     - 系统更新（kit.update）不会影响它；
 *     - 可放在服务器任意位置运行（站点内、站点外、/tmp 均可）。
 *
 * 【使用方法】
 *   php stock-register.php                 # 注册（默认读脚本所在目录的上级为站点根目录）
 *   php stock-register.php /www/wwwroot/你的站点   # 手动指定站点根目录
 *   php stock-register.php --list          # 查看当前已注册的命令
 *   php stock-register.php --remove        # 移除本插件注册的命令（不影响其它命令）
 *
 * 【注册之后】
 *   在站点根目录即可直接运行（无需后台启动）：
 *     ./bin index.php console stock.monitor          # 正式巡检 + 推送
 *     ./bin index.php console stock.monitor.test     # 试运行，不推送
 *   定时任务示例（每小时第 5 分钟巡检一次）：
 *     5 * * * * cd /www/wwwroot/你的站点 && ./bin index.php console stock.monitor >> runtime/stock-monitor.log 2>&1
 */

/* ------------------------------------------------------------------ */
/* 输出助手                                                            */
/* ------------------------------------------------------------------ */

function out(string $s = ''): void
{
    echo $s . "\n";
}

function ok(string $s): void
{
    echo "  [√] {$s}\n";
}

function warn(string $s): void
{
    echo "  [!] {$s}\n";
}

function err(string $s): void
{
    echo "  [×] {$s}\n";
}

/* ------------------------------------------------------------------ */
/* 配置                                                                */
/* ------------------------------------------------------------------ */

const PLUGIN_NAME = 'StockMonitor';          // 插件目录名（app/Plugin/ 下）
const PLUGIN_ENV  = '/app/Plugin';           // 主站插件环境
const CMD_FILE    = 'runtime/command';       // 框架命令注册表（明文序列化）
const BAK_PREFIX  = 'command.bak-stockreg-'; // 备份前缀

/* ------------------------------------------------------------------ */
/* 站点根目录定位                                                      */
/* ------------------------------------------------------------------ */

function isShopRoot(string $dir): bool
{
    return is_file($dir . '/index.php')
        && is_dir($dir . '/kernel/Plugin')
        && is_dir($dir . '/vendor');
}

/**
 * @return array{0:string,1:string} [根目录, 来源说明]
 */
function findRoot(?string $explicit): array
{
    $candidates = [];
    if ($explicit !== null && $explicit !== '') {
        $candidates[] = [$explicit, '由参数指定'];
    }
    $candidates[] = [getcwd(), '当前工作目录'];
    $candidates[] = [__DIR__, '脚本所在目录'];
    $candidates[] = [dirname(__DIR__), '脚本所在目录的上一级'];

    foreach ($candidates as [$dir, $from]) {
        if ($dir === '') {
            continue;
        }
        $real = realpath($dir);
        if ($real !== false && isShopRoot($real)) {
            return [rtrim($real, '/\\'), $from];
        }
    }
    return ['', ''];
}

/* ------------------------------------------------------------------ */
/* 框架最小引导（只加载类与函数定义，不执行任何业务/校验逻辑）            */
/* ------------------------------------------------------------------ */

function bootstrap(string $root): void
{
    if (!defined('BASE_PATH')) {
        define('BASE_PATH', $root . '/');
    }
    // Composer 自动加载：提供 Kernel\Context\Command 等框架类
    require BASE_PATH . 'vendor/autoload.php';
    // Store.php：仅定义 getHwId 等混淆函数（调用时才可能校验；本脚本从不调用）
    // 文件不存在时跳过（不影响命令注册）
    $store = BASE_PATH . 'kernel/Plugin/Store.php';
    if (is_file($store)) {
        require $store;
    }
}

/* ------------------------------------------------------------------ */
/* 插件命令声明读取                                                    */
/* ------------------------------------------------------------------ */

/** 从 Config/Info.php 安全提取显示名（只读文本，不执行） */
function pluginDisplayName(string $infoFile): string
{
    $src = @file_get_contents($infoFile);
    if ($src !== false && preg_match('/"name"\s*=>\s*"([^"]*)"/', $src, $m)) {
        return $m[1];
    }
    return PLUGIN_NAME;
}

/**
 * @return array<string,array{0:string,1:string,2:?string,3:?string}>
 *         命令名 => [类, 方法, 标题, 说明]
 */
function loadPluginCommands(string $root): array
{
    $file = BASE_PATH . PLUGIN_ENV . '/' . PLUGIN_NAME . '/Config/Command.php';
    if (!is_file($file)) {
        throw new RuntimeException("插件命令声明不存在：{$file}");
    }
    $declared = require $file;
    if (!is_array($declared) || $declared === []) {
        throw new RuntimeException("插件命令声明为空或格式不符：{$file}");
    }
    $result = [];
    foreach ($declared as $command => $callable) {
        if (!is_array($callable) || count($callable) < 2) {
            continue;
        }
        $result[(string)$command] = [
            (string)$callable[0],
            (string)$callable[1],
            isset($callable[2]) ? (string)$callable[2] : null,
            isset($callable[3]) ? (string)$callable[3] : null,
        ];
    }
    if ($result === []) {
        throw new RuntimeException("未能从 {$file} 解析出任何命令");
    }
    return $result;
}

/* ------------------------------------------------------------------ */
/* 命令注册表读写（runtime/command，明文序列化）                        */
/* ------------------------------------------------------------------ */

function readCommandTable(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return [];
    }
    $table = @unserialize($raw);
    return is_array($table) ? $table : [];
}

function writeCommandTable(string $path, array $table): void
{
    // 写前备份（仅当文件非空）
    if (is_file($path) && filesize($path) > 0) {
        $bak = dirname($path) . '/' . BAK_PREFIX . date('Ymd-His');
        @copy($path, $bak);
    }
    $data = serialize($table);
    $fp = fopen($path, 'c+');
    if ($fp === false) {
        throw new RuntimeException("无法打开 {$path}（请检查 runtime 目录权限，属主应为 www）");
    }
    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException("无法锁定 {$path}");
        }
        ftruncate($fp, 0);
        fwrite($fp, $data);
        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }
}

/* ------------------------------------------------------------------ */
/* 模式：注册 / 查看 / 移除                                            */
/* ------------------------------------------------------------------ */

function modeRegister(string $root, array $commands): int
{
    $path = BASE_PATH . CMD_FILE;
    if (!is_dir(dirname($path))) {
        @mkdir(dirname($path), 0755, true);
    }

    $table = readCommandTable($path);
    $display = pluginDisplayName(BASE_PATH . PLUGIN_ENV . '/' . PLUGIN_NAME . '/Config/Info.php');
    $extend = ['name' => $display, 'env' => PLUGIN_ENV];

    $before = count($table);
    foreach ($commands as $command => [$class, $method, $name, $desc]) {
        // 与官方 Command::add() 中 Console::add() 的参数完全一致
        $table[$command] = new \Kernel\Context\Command($command, $class, $method, $extend, $name, $desc);
    }
    writeCommandTable($path, $table);

    out('');
    out('注册表：' . $path);
    out("原有命令 {$before} 个，本次写入/更新 " . count($commands) . " 个：");
    foreach ($commands as $command => [$class, $method, $name, $desc]) {
        ok("{$command}" . ($name !== null ? "（{$name}）" : ''));
    }
    out('');
    out('完成。现在可在站点根目录直接运行（无需后台启动）：');
    foreach ($commands as $command => $c) {
        out("  ./bin index.php console {$command}");
    }
    out('');
    out('提示：本脚本未修改框架任何文件；如需移除这些命令，运行：');
    out('  php ' . basename(__FILE__) . ' --remove');
    return 0;
}

function modeList(string $root): int
{
    $path = BASE_PATH . CMD_FILE;
    $table = readCommandTable($path);
    if ($table === []) {
        warn("命令注册表为空或不存在：{$path}");
        return 0;
    }
    out("命令注册表：{$path}（共 " . count($table) . " 个命令）");
    foreach ($table as $command => $obj) {
        if ($obj instanceof \Kernel\Context\Command) {
            $extend = $obj->getExtend();
            $owner = is_array($extend) && ($extend['name'] ?? '') !== '' ? "[{$extend['name']}]" : '';
            $mine = is_array($extend) && ($extend['name'] ?? '') === pluginDisplayName(BASE_PATH . PLUGIN_ENV . '/' . PLUGIN_NAME . '/Config/Info.php') ? ' ← 本插件' : '';
            out(sprintf('  %-24s %-14s %s%s', $obj->getCommand(), $owner, $obj->getName() ?? '', $mine));
        } else {
            out('  ' . (is_string($command) ? $command : '?') . '（异常条目）');
        }
    }
    return 0;
}

function modeRemove(string $root): int
{
    $path = BASE_PATH . CMD_FILE;
    $table = readCommandTable($path);
    if ($table === []) {
        warn("命令注册表为空或不存在，无需移除");
        return 0;
    }
    $display = pluginDisplayName(BASE_PATH . PLUGIN_ENV . '/' . PLUGIN_NAME . '/Config/Info.php');
    $removed = [];
    foreach ($table as $command => $obj) {
        if ($obj instanceof \Kernel\Context\Command) {
            $extend = $obj->getExtend();
            $owner = is_array($extend) ? ($extend['name'] ?? '') : '';
            if ($owner === $display) {
                unset($table[$command]);
                $removed[] = $command;
            }
        }
    }
    if ($removed === []) {
        ok('未发现本插件注册的命令，无需移除');
        return 0;
    }
    writeCommandTable($path, $table);
    foreach ($removed as $command) {
        ok("已移除：{$command}");
    }
    out('其它命令未受影响；注册表已写回。');
    return 0;
}

/* ------------------------------------------------------------------ */
/* 入口                                                                */
/* ------------------------------------------------------------------ */

function main(array $argv): int
{
    $args = array_slice($argv, 1);
    $mode = 'register';
    $explicitRoot = null;
    foreach ($args as $a) {
        if ($a === '--list') {
            $mode = 'list';
        } elseif ($a === '--remove') {
            $mode = 'remove';
        } elseif ($a === '--help') {
            out('StockMonitor 免授权命令注册脚本');
            out('用法：php ' . basename(__FILE__) . ' [站点根目录] [--list] [--remove]');
            out('  （无参数）    注册插件命令到 runtime/command');
            out('  --list       查看当前已注册的命令');
            out('  --remove     移除本插件注册的命令');
            return 0;
        } elseif (!str_starts_with($a, '--')) {
            $explicitRoot = $a;
        }
    }

    out('StockMonitor 免授权命令注册 v1.0');

    [$root, $from] = findRoot($explicitRoot);
    if ($root === '') {
        err('未能定位 MCY-SHOP 站点根目录');
        out('请在站点根目录（含 index.php、kernel、vendor 的目录）下运行，或指定根目录：');
        out('  php ' . basename(__FILE__) . ' /www/wwwroot/你的站点');
        return 1;
    }
    out('站点根目录：' . $root . ($from !== '' ? "（{$from}）" : ''));

    if (PHP_VERSION_ID < 80100) {
        warn('当前 PHP 版本为 ' . PHP_VERSION . '，MCY-SHOP 需要 8.1+');
    }

    try {
        bootstrap($root);
    } catch (\Throwable $e) {
        err('框架引导失败：' . $e->getMessage());
        out('（请确认站点根目录下有 vendor/ 目录，即站点已正确安装）');
        return 1;
    }

    if (!class_exists(\Kernel\Context\Command::class)) {
        err('框架类 Kernel\Context\Command 不可用，vendor/autoload 可能不完整');
        return 1;
    }

    if ($mode === 'list') {
        return modeList($root);
    }

    if ($mode === 'remove') {
        return modeRemove($root);
    }

    try {
        $commands = loadPluginCommands($root);
    } catch (RuntimeException $e) {
        err($e->getMessage());
        out('请确认插件目录存在：' . BASE_PATH . PLUGIN_ENV . '/' . PLUGIN_NAME . '/');
        return 1;
    }

    // 注册前先确认命令类真实存在（避免注册了却跑不起来）
    $missing = [];
    foreach ($commands as $command => [$class, $method]) {
        if (!class_exists($class)) {
            $missing[] = "{$command} → {$class}";
        }
    }
    if ($missing !== []) {
        err('以下命令的类无法加载：');
        foreach ($missing as $m) {
            out('  ' . $m);
        }
        out('请检查插件文件是否上传完整（Command/Monitor.php 等）');
        return 1;
    }
    ok('命令类校验通过（' . count($commands) . ' 个命令的类均可加载）');

    return modeRegister($root, $commands);
}

exit(main($argv));
