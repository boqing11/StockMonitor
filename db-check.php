#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * StockMonitor 数据库表前缀探测工具
 * =====================================================================
 *
 * 【解决什么问题】
 *   运行 stock.monitor.test 报错：
 *     SQLSTATE[42S02]: Base table or view not found: 1146
 *     Table 'xxx.item_sku' doesn't exist
 *
 *   原因：数据库表名带了前缀（如 mcy_item_sku），而
 *   Config/Settings.php 的 table_prefix 仍为空。
 *
 * 【它做什么】
 *   1. 读取站点 config/database.php 中的数据库账号；
 *   2. 连接数据库，列出与插件相关的表（item / item_sku / repertory_*）；
 *   3. 自动探测表前缀（如 "mcy_"）；
 *   4. 加 --fix 时自动把探测到的前缀写入 Settings.php（写前备份）。
 *
 * 【使用方法】（在站点根目录执行）
 *   php db-check.php                    # 只探测，显示建议
 *   php db-check.php --fix              # 探测并自动写入 Settings.php
 *   php db-check.php --fix=mcy_         # 手动指定前缀写入
 */

function out(string $s = ''): void { echo $s . "\n"; }
function ok(string $s): void { echo "  [√] {$s}\n"; }
function warn(string $s): void { echo "  [!] {$s}\n"; }
function err(string $s): void { echo "  [×] {$s}\n"; }

const SETTINGS = 'app/Plugin/StockMonitor/Config/Settings.php';

/* ---------------- 站点定位 ---------------- */

function isShopRoot(string $dir): bool
{
    return is_file($dir . '/index.php') && is_dir($dir . '/config') && is_dir($dir . '/vendor');
}

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
        if (is_string($dir) && $dir !== '' && isShopRoot(rtrim($dir, '/'))) {
            return rtrim(realpath($dir), '/');
        }
    }
    return '';
}

/* ---------------- 数据库配置读取 ---------------- */

/**
 * 从 config/database.php 提取连接参数（兼容多种键名写法）。
 * @return array{host:string,port:int,database:string,username:string,password:string}
 */
function loadDbConfig(string $root): array
{
    $file = $root . '/config/database.php';
    if (!is_file($file)) {
        throw new RuntimeException("数据库配置文件不存在：{$file}");
    }
    $cfg = require $file;
    if (!is_array($cfg)) {
        throw new RuntimeException("数据库配置文件格式异常：{$file}");
    }
    // Hyperf 风格：['default' => [...]] 或直接平铺
    $node = $cfg['default'] ?? $cfg;
    if (!is_array($node)) {
        throw new RuntimeException("数据库配置结构无法识别（{$file}），请手动查看表前缀");
    }
    $pick = static function (array $arr, array $keys, mixed $default = '') {
        foreach ($keys as $k) {
            if (isset($arr[$k]) && $arr[$k] !== '') {
                return $arr[$k];
            }
        }
        return $default;
    };
    return [
        'host'     => (string)$pick($node, ['host', 'hostname', 'server'], '127.0.0.1'),
        'port'     => (int)$pick($node, ['port'], 3306),
        'database' => (string)$pick($node, ['database', 'dbname', 'db', 'name'], ''),
        'username' => (string)$pick($node, ['username', 'user', 'uname'], ''),
        'password' => (string)$pick($node, ['password', 'pwd', 'pass'], ''),
    ];
}

/* ---------------- 前缀探测 ---------------- */

/** 从表名列表推导插件所需表的前缀 */
function detectPrefix(array $tables): ?string
{
    $has = static function (string $t) use ($tables): bool {
        return in_array($t, $tables, true);
    };
    foreach ($tables as $t) {
        if (preg_match('/^(.*)item_sku$/', $t, $m) && $m[1] !== '') {
            $p = $m[1];
            if ($has($p . 'item') && $has($p . 'repertory_item_sku_cache')) {
                return $p;
            }
        }
    }
    // 无前缀场景：裸表存在
    if ($has('item_sku') && $has('item') && $has('repertory_item_sku_cache')) {
        return '';
    }
    return null;
}

/* ---------------- Settings.php 写入 ---------------- */

function setPrefixInSettings(string $file, string $prefix): bool
{
    if (!is_file($file)) {
        return false;
    }
    $src = file_get_contents($file);
    if ($src === false) {
        return false;
    }
    $pattern = '/("table_prefix"\s*=>\s*)("[^"]*"|\'\'|""|\'\')/';
    if (!preg_match($pattern, $src)) {
        return false; // 找不到 table_prefix 配置行
    }
    $new = preg_replace($pattern, '${1}"' . $prefix . '"', $src, 1);
    if ($new === null) {
        return false;
    }
    if ($new === $src) {
        return true; // 已是目标值：幂等无操作，视为成功
    }
    return file_put_contents($file, $new) !== false;
}

/* ---------------- 主流程 ---------------- */

$argv = $_SERVER['argv'] ?? [];
$args = array_slice($argv, 1);
$fixMode = null;   // null=只探测 / ''=自动 / 'xxx_'=手动指定
$explicit = null;
foreach ($args as $a) {
    if ($a === '--fix') {
        $fixMode = '';
    } elseif (str_starts_with($a, '--fix=')) {
        $fixMode = substr($a, 6);
    } elseif ($a === '--help') {
        out('用法：php db-check.php [站点根目录] [--fix | --fix=前缀]');
        exit(0);
    } elseif (!str_starts_with($a, '--')) {
        $explicit = $a;
    }
}

out('StockMonitor 数据库表前缀探测 v1.0');

$root = findRoot($explicit);
if ($root === '') {
    err('未能定位站点根目录');
    out('请在站点根目录下运行，或指定：php db-check.php /www/wwwroot/你的站点');
    exit(1);
}
out('站点根目录：' . $root);

/* ---- 读数据库配置 ---- */
try {
    $db = loadDbConfig($root);
} catch (RuntimeException $e) {
    err($e->getMessage());
    out('');
    out('手动处理办法：宝塔 → 数据库 → 管理，看你的表名是否带前缀（如 mcy_item_sku）；');
    out('若带前缀，编辑 ' . SETTINGS . '，把 table_prefix 改成该前缀（如 "mcy_"）。');
    exit(1);
}
if ($db['database'] === '' || $db['username'] === '') {
    err('数据库配置不完整（database/username 为空）');
    out('请手动检查 ' . $root . '/config/database.php');
    exit(1);
}
out("数据库：{$db['database']} @ {$db['host']}:{$db['port']}（用户 {$db['username']}）");

/* ---- 连接并列出表 ---- */
try {
    $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['database']};charset=utf8mb4";
    $pdo = new PDO($dsn, $db['username'], $db['password'], [
        PDO::ATTR_TIMEOUT => 5,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (\Throwable $e) {
    err('数据库连接失败：' . $e->getMessage());
    if (str_contains($e->getMessage(), 'could not find driver')) {
        out('当前 PHP 未启用 pdo_mysql 扩展。宝塔：软件商店 → PHP → 设置 → 扩展 → 安装 pdo/mysql');
    } else {
        out('请检查 config/database.php 中的账号密码，或数据库服务是否运行。');
    }
    exit(1);
}
ok('数据库连接成功');

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
if (!$tables) {
    err('数据库中没有任何表');
    exit(1);
}

/* ---- 展示插件相关表 ---- */
out('');
out('插件相关的表：');
$related = array_values(array_filter($tables, static function (string $t): bool {
    return (bool)preg_match('/(^|_)(item|item_sku|repertory_item|repertory_item_sku|repertory_item_sku_cache)$/', $t);
}));
if ($related === []) {
    warn('未找到任何 item / repertory 相关表，数据库可能不对');
} else {
    foreach ($related as $t) {
        out('  ' . $t);
    }
}

/* ---- 探测前缀 ---- */
$prefix = detectPrefix($tables);
out('');
if ($prefix === null) {
    err('未能自动探测到表前缀');
    out('请从上面的表名中找规律（如 mcy_item_sku → 前缀 "mcy_"），然后：');
    out('  php db-check.php --fix=你的前缀_');
    exit(1);
}

if ($prefix === '') {
    ok('探测结果：表名无前缀（item_sku / repertory_item_sku_cache 直接存在）');
} else {
    ok("探测到表前缀：\"{$prefix}\"（如 {$prefix}item_sku）");
}

/* ---- 写入 ---- */
if ($fixMode === null) {
    out('');
    out('确认无误后执行：php db-check.php --fix   （自动写入 Settings.php）');
    exit(0);
}

$finalPrefix = $fixMode === '' ? $prefix : $fixMode;
$settingsFile = $root . '/' . SETTINGS;
if (!is_file($settingsFile)) {
    err("配置文件不存在：{$settingsFile}");
    exit(1);
}
$bak = $settingsFile . '.bak-dbcheck-' . date('Ymd-His');
if (!copy($settingsFile, $bak)) {
    err('备份失败，已终止');
    exit(1);
}
if (!setPrefixInSettings($settingsFile, $finalPrefix)) {
    err('写入失败（未找到 table_prefix 配置行），已保留原文件');
    out('请手动编辑 ' . SETTINGS . '，设置 "table_prefix" => "' . $finalPrefix . '",');
    @unlink($bak);
    exit(1);
}
ok("已写入 table_prefix = \"{$finalPrefix}\"（原文件备份：" . basename($bak) . '）');
out('');
out('现在重新运行试运行：');
out('  cd ' . $root . ' && ./bin index.php console stock.monitor.test');
exit(0);
