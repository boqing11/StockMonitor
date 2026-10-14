#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * StockMonitor 配置文件修复工具
 * =====================================================================
 *
 * 【解决什么问题】
 *   运行 stock.monitor.test 报错：
 *     Undefined constant "UID_xxxx..." / Undefined constant "AT_xxxx..."
 *
 *   原因：在面板编辑器里填写 app_token / uids 时，值的引号丢失
 *   （误删、或从别处复制带进了中文引号 “ ” ‘ ’），PHP 8 会把裸标识符
 *   当成常量名，直接抛 Error。
 *
 * 【它做什么】
 *   1. 备份原文件（Settings.php.bak-fix-时间戳）；
 *   2. 中文引号 “ ” ‘ ’ 统一替换为英文引号 " '；
 *   3. 给丢失引号的 AT_/UID_ 值补上英文双引号；
 *   4. 替换后做 PHP 语法检查（php -l），通过才写回，否则保留原样。
 *
 * 【使用方法】（在站点根目录执行）
 *   php fix-settings.php
 *   php fix-settings.php /www/wwwroot/你的站点        # 手动指定站点根目录
 */

function out(string $s = ''): void { echo $s . "\n"; }
function ok(string $s): void { echo "  [√] {$s}\n"; }
function err(string $s): void { echo "  [×] {$s}\n"; }

const PLUGIN_PATH = 'app/Plugin/StockMonitor/Config/Settings.php';

/* ---- 定位站点根目录 ---- */
function findRoot(?string $explicit): string
{
    $candidates = [];
    if ($explicit !== null && $explicit !== '') {
        $candidates[] = $explicit;
    }
    $candidates[] = getcwd();
    $candidates[] = __DIR__;
    $candidates[] = dirname(__DIR__);
    foreach ($candidates as $dir) {
        if (is_string($dir) && $dir !== '' && is_file(rtrim($dir, '/') . '/index.php')) {
            return rtrim(realpath($dir), '/');
        }
    }
    return '';
}

$argv = $_SERVER['argv'] ?? [];
$args = array_slice($argv, 1);
$explicit = null;
foreach ($args as $a) {
    if (!str_starts_with($a, '--')) {
        $explicit = $a;
    }
}

$root = findRoot($explicit);
if ($root === '') {
    err('未能定位站点根目录');
    out('请在站点根目录下运行，或指定：php fix-settings.php /www/wwwroot/你的站点');
    exit(1);
}
$file = $root . '/' . PLUGIN_PATH;
if (!is_file($file)) {
    err("配置文件不存在：{$file}");
    exit(1);
}

out('StockMonitor 配置修复 v1.0');
out('目标文件：' . $file);

$src = file_get_contents($file);
if ($src === false) {
    err('读取失败');
    exit(1);
}
$orig = $src;

/* ---- 1. 中文引号 → 英文引号 ---- */
$cn = ['“', '”', '‘', '’'];
$en = ['"', '"', "'", "'"];
$countCn = 0;
foreach ($cn as $i => $c) {
    $countCn += substr_count($src, $c);
}
$src = str_replace($cn, $en, $src);

/* ---- 2. 补引号：=> AT_xxx  /  => UID_xxx ---- */
$src = preg_replace('/=>\s*(AT_[A-Za-z0-9]+)\s*(,|\]|\))/', '=> "$1"$2', $src) ?? $src;
$src = preg_replace('/=>\s*(UID_[A-Za-z0-9]+)\s*(,|\]|\))/', '=> "$1"$2', $src) ?? $src;
/* ---- 3. 补引号：数组内的裸标识符 [AT_xxx] / [UID_xxx] ---- */
$src = preg_replace('/\[\s*(AT_[A-Za-z0-9]+)\s*\]/', '["$1"]', $src) ?? $src;
$src = preg_replace('/\[\s*(UID_[A-Za-z0-9]+)\s*\]/', '["$1"]', $src) ?? $src;
/* ---- 4. 补引号：数组内逗号后的裸标识符 ["UID_a", UID_b] ---- */
$src = preg_replace('/(\[\s*"[^"]*"\s*,\s*)((?:AT_|UID_)[A-Za-z0-9]+)(\s*[,\]])/', '$1"$2"$3', $src) ?? $src;

if ($src === $orig) {
    ok('未发现需要修复的问题（引号均正常）');
    exit(0);
}

/* ---- 语法检查（通过才写回） ---- */
$tmp = tempnam(sys_get_temp_dir(), 'stk') . '.php';
file_put_contents($tmp, $src);

// 优先使用当前 PHP 进程的可执行文件（避免 PATH 里没有 php 命令）
$phpBin = (defined('PHP_BINARY') && PHP_BINARY !== '' && file_exists(PHP_BINARY)) ? PHP_BINARY : 'php';

$code = -1;
$lintOut = '';
$desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = @proc_open(escapeshellarg($phpBin) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $desc, $pipes);
if (is_resource($proc)) {
    $lintOut = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
}
@unlink($tmp);

// proc_open 不可用或 php -l 无法执行时，退化为 token 解析（能发现真正的语法错误；
// 注意：裸标识符在 PHP 8 属于合法语法，token 解析会通过，这正符合本工具的修复目标）
if ($code < 0 || ($code !== 0 && str_contains($lintOut, 'not recognized'))) {
    try {
        token_get_all($src, TOKEN_PARSE);
        $code = 0;
        $lintOut = '';
    } catch (\Throwable $e) {
        $code = 1;
        $lintOut = $e->getMessage();
    }
}

if ($code !== 0) {
    err('修复后的代码仍未通过语法检查，已保留原文件未改动：');
    out(trim($lintOut));
    out('请手动用编辑器打开 ' . $file . '，检查 app_token 与 uids 两行的引号。');
    exit(1);
}

/* ---- 备份并写回 ---- */
$bak = $file . '.bak-fix-' . date('Ymd-His');
if (!copy($file, $bak)) {
    err('备份失败，已终止（未写入）');
    exit(1);
}
if (file_put_contents($file, $src) === false) {
    err('写入失败，原文件未改动');
    exit(1);
}

if ($countCn > 0) {
    ok("替换中文引号 {$countCn} 处 → 英文引号");
}
ok('已为丢失引号的 AT_/UID_ 值补上双引号');
ok('语法检查通过');
ok('原文件已备份：' . basename($bak));
out('');
out('现在重新运行试运行：');
out('  cd ' . $root . ' && ./bin index.php console stock.monitor.test');
exit(0);
