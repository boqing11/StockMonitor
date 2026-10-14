<?php
declare(strict_types=1);

namespace App\Plugin\StockMonitor\Lib;

use App\Model\ItemSku;

/**
 * 库存采集与判定。
 *
 * 原理：
 *  MCY-SHOP 中商品 SKU 的真实库存由「货源插件」提供，并被缓存到
 *  repertory_item_sku_cache 表：
 *      type=0 -> 前台展示文本（可能是 "大量"、具体数字、或 "0"）
 *      type=1 -> 是否库存充足 "1"(有货) / "0"(无货)
 *  前台「显示大量但选中规格后提示暂时无货」，正是因为 type=0 文本恒为“大量”，
 *  而 type=1 才反映真实是否可购买。因此本类以 type=1 为权威信号，
 *  在缺失时退回用展示文本 + 关键词判定，精确到每个 SKU。
 *
 * 安全：仅执行 SELECT，且表前缀做了白名单过滤，杜绝 SQL 注入。
 */
class Stock
{
    private array $cfg;
    private string $prefix;
    private string $source;
    private int $lowThreshold;
    private ?int $filterUid;
    private array $outWords;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
        // 表前缀仅允许 字母/数字/下划线，防止注入
        $this->prefix = preg_replace('/[^A-Za-z0-9_]/', '', (string)($cfg["table_prefix"] ?? ""));
        $this->source = (string)($cfg["source"] ?? "item_sku");
        $this->lowThreshold = (int)($cfg["low_stock_threshold"] ?? 0);
        $this->outWords = array_map("strtolower", (array)($cfg["out_words"] ?? ["0"]));
        $this->filterUid = isset($cfg["filter_user_id"]) && $cfg["filter_user_id"] !== null && $cfg["filter_user_id"] !== ""
            ? (int)$cfg["filter_user_id"] : null;
    }

    /**
     * 采集当前所有 SKU 库存快照。
     *
     * @return array<string,array> ["key" => ["item","sku","url","stock_text","stock_num","status"], ...]
     *                             status ∈ ok | out | low
     */
    public function collect(): array
    {
        $conn = (new ItemSku())->getConnection();
        $rows = $conn->select($this->buildSql());

        $site = rtrim((string)($this->cfg["site_url"] ?? ""), "/");
        $result = [];

        foreach ($rows as $row) {
            $r = (array)$row;

            $stockText = array_key_exists("stock_text", $r) && $r["stock_text"] !== null ? (string)$r["stock_text"] : null;
            $hasStock  = array_key_exists("has_stock", $r) && $r["has_stock"] !== null ? (string)$r["has_stock"] : null;

            $num = null;
            $status = $this->classify($stockText, $hasStock, $num);
            if ($status === null) {
                // 两个信号都缺失，无法判定，跳过（不误报）
                continue;
            }

            $key = $this->source === "repertory_item_sku"
                ? "rep_" . $r["sku_id"]
                : "item_" . $r["sku_id"];

            $url = "";
            if ($site !== "" && $this->source !== "repertory_item_sku") {
                $url = $site . "/item?id=" . rawurlencode((string)$r["item_id"]);
            }

            $result[$key] = [
                "item"       => (string)$r["item_name"],
                "sku"        => (string)$r["sku_name"],
                "url"        => $url,
                "stock_text" => $stockText ?? "",
                "stock_num"  => $num,
                "status"     => $status,
            ];
        }

        return $result;
    }

    /**
     * 判定单个 SKU 状态。
     *
     * @return string|null ok / out / low；null=无法判定
     */
    private function classify(?string $stockText, ?string $hasStock, ?int &$num): ?string
    {
        $num = $this->parseNum($stockText);

        // 权威信号：has_enough_stock 缓存
        if ($hasStock === "0") {
            return "out";
        }
        if ($hasStock === "1") {
            // 有货，再看是否低库存（仅当能取到具体数字）
            if ($num !== null && $num > 0 && $this->lowThreshold > 0 && $num <= $this->lowThreshold) {
                return "low";
            }
            return "ok";
        }

        // has_enough_stock 缓存缺失：退回展示文本判定
        if ($stockText === null) {
            return null; // 无任何信号
        }
        $t = strtolower(trim($stockText));
        if ($t === "") {
            return null;
        }
        if (in_array($t, $this->outWords, true)) {
            return "out";
        }
        if ($num !== null && $num <= 0) {
            return "out";
        }
        if ($num !== null && $num > 0 && $this->lowThreshold > 0 && $num <= $this->lowThreshold) {
            return "low";
        }
        return "ok"; // 例如 "大量"
    }

    /** 从展示文本中提取数字；"大量" 等无数字文本返回 null */
    private function parseNum(?string $stockText): ?int
    {
        if ($stockText === null) {
            return null;
        }
        $t = trim($stockText);
        if ($t === "") {
            return null;
        }
        if (preg_match('/\d+/', $t, $m)) {
            return (int)$m[0];
        }
        return null;
    }

    /** 组装查询 SQL（两条 LEFT JOIN 分别取 展示文本 / 是否充足） */
    private function buildSql(): string
    {
        $p = $this->prefix;

        if ($this->source === "repertory_item_sku") {
            $main     = "{$p}repertory_item_sku s";
            $itemTbl  = "{$p}repertory_item i";
            $joinCond = "i.id = s.repertory_item_id";
            $repCol   = "s.id";
        } else {
            $main     = "{$p}item_sku s";
            $itemTbl  = "{$p}item i";
            $joinCond = "i.id = s.item_id";
            $repCol   = "s.repertory_item_sku_id";
        }

        $where = "1=1";
        if (!empty($this->cfg["only_onsale"])) {
            $where .= " AND i.status = 1";
        }
        if ($this->filterUid !== null) {
            $where .= " AND i.user_id = " . $this->filterUid;
        }

        return "SELECT i.id AS item_id, i.name AS item_name,
                       s.id AS sku_id, s.name AS sku_name,
                       {$repCol} AS rep_sku_id,
                       c0.value AS stock_text, c1.value AS has_stock
                FROM {$main}
                JOIN {$itemTbl} ON {$joinCond}
                LEFT JOIN {$p}repertory_item_sku_cache c0 ON c0.sku_id = {$repCol} AND c0.type = 0
                LEFT JOIN {$p}repertory_item_sku_cache c1 ON c1.sku_id = {$repCol} AND c1.type = 1
                WHERE {$where}
                ORDER BY i.id, s.id";
    }
}
