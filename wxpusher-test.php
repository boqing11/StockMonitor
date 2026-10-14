#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * WxPusher 推送链路诊断工具
 * =====================================================================
 *
 * 【解决什么问题】
 *   运行 stock.monitor 报「WxPusher 推送失败」。插件只返回布尔值，
 *   看不到真实原因；本脚本逐层排查：
 *     1. PHP 扩展（curl / openssl / allow_url_fopen）
 *     2. Settings.php 配置（app_token / uids / topic_ids 是否有效）
 *     3. DNS 解析
 *     4. 按当前运行时会用的通道真实发送一条测试消息，
 *        打印 curl 错误码、HTTP 状态、原始响应与结论
 *
 * 【使用方法】（上传到站点根目录，与 index.php 同级）
 *   ./bin wxpusher-test.php      # 用与插件相同的运行时跑（推荐，最能反映真实情况）
 *   php wxpusher-test.php        # 或用系统 PHP 跑（对照）
 */

function wxt_out(string $s = ''): void { echo $s . "\n"; }
function wxt_ok(string $s): void { echo "  [√] {$s}\n"; }
function wxt_bad(string $s): void { echo "  [×] {$s}\n"; }
function wxt_info(string $s): void { echo "  [i] {$s}\n"; }
/** 安全截断/长度：无 mbstring 扩展时降级 */
function wxt_strCut(string $s, int $len): string
{
    return function_exists('mb_substr') ? mb_substr($s, 0, $len) : substr($s, 0, $len);
}
function wxt_strLen(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}

const ENDPOINT = 'https://wxpusher.zjiecode.com/api/send/message';

/** 按 WxPusher 返回内容判定，返回 [是否成功, 说明] */
function wxt_judge(string $resp): array
{
    $json = json_decode($resp, true);
    if (!is_array($json)) {
        return [false, '响应无法解析：' . wxt_strCut(var_export($resp, true), 150)];
    }
    $code = (int)($json['code'] ?? 0);
    $msg = (string)($json['msg'] ?? '(无)');
    if ($code === 1000) {
        return [true, "服务端返回成功（code=1000）"];
    }
    $hint = [
        1001 => 'AppToken 不正确（重新到 WxPusher 公众号复制）',
        1002 => 'AppToken 不存在',
        1003 => 'AppToken 已被禁用',
        1004 => '参数错误（检查 uid 格式）',
        1005 => '内容过长',
        1009 => 'UID 不存在（复制错误，或该微信未关注公众号）',
    ][$code] ?? '见上方常见 code 对照';
    return [false, "服务端返回 code={$code} msg={$msg} —— {$hint}"];
}

/** curl 通道发送，返回 [是否成功, 说明] */
function wxt_sendViaCurl(string $body): array
{
    $ch = curl_init();
    if ($ch === false) {
        return [false, 'curl_init() 失败'];
    }
    curl_setopt_array($ch, [
        CURLOPT_URL            => ENDPOINT,
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $body,
    ]);
    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $errmsg = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($resp) || $resp === '') {
        $hint = [6 => 'DNS 解析失败', 7 => '无法连接（出网被拦或服务故障）', 28 => '超时（出网被丢包）', 35 => 'SSL 握手失败', 60 => 'CA 证书问题', 77 => 'CA 证书问题'][$errno] ?? '';
        return [false, "curl 未完成请求：错误码 {$errno}" . ($errmsg !== '' ? "（{$errmsg}）" : '') . ($hint !== '' ? " —— {$hint}" : '')];
    }
    [$success, $note] = wxt_judge($resp);
    return [$success, "curl HTTP {$http} · {$note}"];
}

/** stream 通道发送（无 curl 扩展时的降级路径），返回 [是否成功, 说明] */
function wxt_sendViaStream(string $body): array
{
    if (!ini_get('allow_url_fopen')) {
        return [false, 'allow_url_fopen 被禁用，stream 通道不可用'];
    }
    $ctx = stream_context_create([
        'http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $body, 'timeout' => 15, 'ignore_errors' => true],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $resp = @file_get_contents(ENDPOINT, false, $ctx);
    if ($resp === false) {
        return [false, 'stream 请求失败（网络不可达/超时/SSL 证书问题）'];
    }
    [$success, $note] = wxt_judge($resp);
    return [$success, "stream · {$note}"];
}

wxt_out('WxPusher 推送链路诊断');
wxt_out(str_repeat('=', 56));

/* ---- 1. 运行环境 ---- */
wxt_out('');
wxt_out('【1】运行环境（应与插件运行时一致：优先用 ./bin wxpusher-test.php 跑）');
wxt_info('PHP 版本：' . PHP_VERSION . '（' . PHP_SAPI . '）');
$hasCurl = extension_loaded('curl');
if ($hasCurl) {
    wxt_ok('curl 扩展已加载（' . (curl_version()['version'] ?? '?') . '）—— 插件会走 curl 通道');
} else {
    wxt_bad('curl 扩展未加载 —— 插件将自动降级为 stream 通道');
}
if (extension_loaded('openssl')) {
    wxt_ok('openssl 扩展已加载（HTTPS 必需）');
} else {
    wxt_bad('openssl 扩展未加载 —— HTTPS 会失败');
}
wxt_info('allow_url_fopen：' . (ini_get('allow_url_fopen') ? '开启' : '★关闭★（stream 通道将不可用）'));

/* ---- 2. 插件配置 ---- */
wxt_out('');
wxt_out('【2】插件配置');

$settingsFile = __DIR__ . '/app/Plugin/StockMonitor/Config/Settings.php';
if (!is_file($settingsFile)) {
    wxt_bad("找不到配置文件：{$settingsFile}");
    wxt_out('请把本脚本放到站点根目录（与 index.php 同级）再运行。');
    exit(1);
}
$cfg = include $settingsFile;
if (!is_array($cfg)) {
    wxt_bad('Settings.php 返回值不是数组，文件可能损坏');
    exit(1);
}

$token = trim((string)($cfg['app_token'] ?? ''));
$uids = array_values(array_filter(array_map('strval', (array)($cfg['uids'] ?? []))));
$topicIds = array_values(array_filter(array_map('intval', (array)($cfg['topic_ids'] ?? []))));

if ($token === '') {
    wxt_bad('app_token 为空');
} else {
    wxt_ok('app_token：' . substr($token, 0, 8) . '…（长度 ' . strlen($token) . (str_starts_with($token, 'AT_') ? '，格式正确' : '，★不是 AT_ 开头★') . '）');
}
if (empty($uids)) {
    wxt_bad('uids 为空');
} else {
    wxt_ok('uids：' . implode(' , ', $uids));
    foreach ($uids as $u) {
        if (!str_starts_with($u, 'UID_')) {
            wxt_bad("UID「{$u}」不是 UID_ 开头，疑似复制错误");
        }
    }
}
wxt_info('topic_ids：' . (empty($topicIds) ? '(未使用)' : implode(' , ', $topicIds)));

if ($token === '' || (empty($uids) && empty($topicIds))) {
    wxt_out('');
    wxt_bad('结论：配置不完整，推送必然失败。请先补全 app_token 与 uids。');
    exit(1);
}

/* ---- 3. DNS ---- */
wxt_out('');
wxt_out('【3】DNS 解析');
$host = 'wxpusher.zjiecode.com';
$ip = gethostbyname($host);
if ($ip === $host) {
    wxt_bad("无法解析 {$host} —— 服务器 DNS 异常");
    wxt_out('  临时应对：echo "114.114.114.114" >> /etc/resolv.conf 后重试');
    exit(1);
}
wxt_ok("{$host} → {$ip}");

/* ---- 4. 真实发送 ---- */
wxt_out('');
wxt_out('【4】真实发送测试消息（与插件参数一致）');

$payload = [
    'appToken'    => $token,
    'content'     => "StockMonitor 链路测试\n\n如果你收到这条消息，说明推送链路完全正常。\n时间：" . date('Y-m-d H:i:s'),
    'summary'     => 'StockMonitor 测试消息',
    'contentType' => 1,
];
if (!empty($uids)) { $payload['uids'] = $uids; }
if (!empty($topicIds)) { $payload['topicIds'] = $topicIds; }
$body = json_encode($payload, JSON_UNESCAPED_UNICODE);

$results = [];
if ($hasCurl) {
    // 与插件当前行为一致：优先 curl
    [$s, $note] = wxt_sendViaCurl($body);
    $results['curl（插件默认通道）'] = [$s, $note];
    if (!$s) {
        // curl 传输层失败时，补测 stream，确认降级通道是否可用
        [$s2, $note2] = wxt_sendViaStream($body);
        $results['stream（降级通道）'] = [$s2, $note2];
    }
} else {
    [$s, $note] = wxt_sendViaStream($body);
    $results['stream（插件将使用的通道）'] = [$s, $note];
}

$anyOk = false;
foreach ($results as $name => [$s, $note]) {
    if ($s) { wxt_ok("{$name}：{$note}"); $anyOk = true; }
    else { wxt_bad("{$name}：{$note}"); }
}

wxt_out('');
if ($anyOk) {
    wxt_ok('推送链路正常 —— 请检查微信是否收到「StockMonitor 测试消息」');
    wxt_out('');
    wxt_out('若微信仍未收到：确认该 UID 属于当前 AppToken 对应账号，');
    wxt_out('且此微信已关注 WxPusher 公众号（公众号菜单→我的→UID）。');
    exit(0);
}

wxt_bad('推送失败。根据上面的原因对症处理：');
wxt_out('  · curl 错误码 6/7/28      → 服务器出网被拦，换 stream 通道或联系主机商');
wxt_out('  · curl 错误码 35/60/77    → 运行时缺少 CA 证书包（stream 通道通常可绕过）');
wxt_out('  · code=1001/1002/1003     → app_token 错误/不存在/被禁用，重新复制');
wxt_out('  · code=1009               → UID 错误或未关注公众号');
wxt_out('  · curl 扩展未加载         → 使用新版插件（已内置 stream 降级通道）');
exit(1);
