<?php
declare(strict_types=1);

namespace App\Plugin\StockMonitor\Lib;

/**
 * WxPusher 推送客户端。
 *
 * 只向官方接口 https://wxpusher.zjiecode.com/api/send/message 发送一次 POST。
 * 请求体仅包含 AppToken、内容、标题与接收人 UID / 主题ID；
 * 内容由调用方构造，本插件保证只包含「商品名 / 规格名 / 库存」等业务信息，
 * 绝不包含任何服务器路径、IP、数据库账号等敏感信息。
 *
 * 传输策略：
 *   优先使用 curl 扩展；当前 PHP 未编译 curl 时，自动降级为 stream
 *   （file_get_contents + stream context，只需 allow_url_fopen）。
 *   两种方式都保留完整 TLS 证书校验，不牺牲安全性。
 *   失败原因通过 lastError() 暴露，便于日志定位。
 */
class WxPusher
{
    private const ENDPOINT = "https://wxpusher.zjiecode.com/api/send/message";

    private string $appToken;
    private array $uids;
    private array $topicIds;
    private int $contentType;
    private string $lastError = "";
    private ?string $endpoint;

    public function __construct(string $appToken, array $uids, array $cfg = [], ?string $endpoint = null)
    {
        $this->appToken = trim($appToken);
        $this->uids = array_values(array_filter(array_map("strval", (array)$uids)));
        $this->topicIds = array_values(array_filter(array_map("intval", (array)($cfg["topic_ids"] ?? []))));
        $this->contentType = (int)($cfg["content_type"] ?? 3);
        $this->endpoint = $endpoint; // 仅测试用；生产环境传 null 走官方接口
    }

    /** 最近一次失败的详细原因（成功时为空串） */
    public function lastError(): string
    {
        return $this->lastError;
    }

    /**
     * 发送一条消息。
     *
     * @param string $summary 标题（简短，会作为通知预览）
     * @param string $content 正文（markdown / html / text，取决于 content_type）
     * @return bool 是否成功（WxPusher 返回 code=1000 视为成功）
     */
    public function send(string $summary, string $content): bool
    {
        $this->lastError = "";

        if ($this->appToken === "" || (empty($this->uids) && empty($this->topicIds))) {
            $this->lastError = "配置不完整（appToken 或 uids/topicIds 为空）";
            return false;
        }

        $payload = [
            "appToken"     => $this->appToken,
            "content"      => $content,
            "summary"      => $this->truncate($summary, 90),
            "contentType"  => $this->contentType,
        ];
        if (!empty($this->uids)) {
            $payload["uids"] = $this->uids;
        }
        if (!empty($this->topicIds)) {
            $payload["topicIds"] = $this->topicIds;
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        if (function_exists("curl_init")) {
            return $this->sendViaCurl($body);
        }
        return $this->sendViaStream($body);
    }

    /** 安全截断：无 mbstring 扩展时降级为 substr（ASCII 安全） */
    private function truncate(string $s, int $len): string
    {
        $real = function_exists("mb_strlen") ? mb_strlen($s) : strlen($s);
        if ($real <= $len) {
            return $s;
        }
        return function_exists("mb_substr") ? mb_substr($s, 0, $len) : substr($s, 0, $len);
    }

    /* ---------------- curl 通道 ---------------- */

    private function sendViaCurl(string $body): bool
    {
        $ch = curl_init();
        if ($ch === false) {
            $this->lastError = "curl_init() 失败";
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->endpoint ?? self::ENDPOINT,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
            CURLOPT_POSTFIELDS     => $body,
        ]);

        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($resp) || $resp === "") {
            $this->lastError = "curl 请求未完成（错误码 {$errno}" . ($errmsg !== "" ? "：{$errmsg}" : "；常见：6=DNS失败 7/28=无法连接或超时 35/60/77=SSL证书问题") . "）";
            return false;
        }
        return $this->judge($resp, "curl HTTP {$httpCode}");
    }

    /* ---------------- stream 通道（无 curl 扩展时降级） ---------------- */

    private function sendViaStream(string $body): bool
    {
        if (!ini_get("allow_url_fopen")) {
            $this->lastError = "当前 PHP 既无 curl 扩展，又禁用了 allow_url_fopen，无法发起 HTTPS 请求";
            return false;
        }
        $ctx = stream_context_create([
            "http" => [
                "method"        => "POST",
                "header"        => "Content-Type: application/json\r\n",
                "content"       => $body,
                "timeout"       => 15,
                "ignore_errors" => true, // 失败时也返回响应体，便于读取服务端错误
            ],
            "ssl" => [
                "verify_peer"      => true,
                "verify_peer_name" => true,
            ],
        ]);
        $resp = @file_get_contents($this->endpoint ?? self::ENDPOINT, false, $ctx);
        if ($resp === false) {
            $this->lastError = "stream 请求失败（网络不可达/超时/SSL证书问题；可先用 wxpusher-test.php 诊断）";
            return false;
        }
        return $this->judge($resp, "stream");
    }

    /* ---------------- 响应判定 ---------------- */

    private function judge(string $resp, string $via): bool
    {
        $json = json_decode($resp, true);
        if (!is_array($json)) {
            $this->lastError = "{$via}：响应无法解析（" . $this->truncate(var_export($resp, true), 120) . "）";
            return false;
        }
        if ((int)($json["code"] ?? 0) === 1000) {
            return true;
        }
        $this->lastError = "{$via}：服务端返回 code=" . (int)($json["code"] ?? 0)
            . " msg=" . (string)($json["msg"] ?? "(无)");
        return false;
    }
}
