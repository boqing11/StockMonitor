<?php
declare(strict_types=1);

namespace App\Plugin\StockMonitor\Command;

use App\Plugin\StockMonitor\Lib\State;
use App\Plugin\StockMonitor\Lib\Stock;
use App\Plugin\StockMonitor\Lib\WxPusher;
use Kernel\Plugin\Abstract\Command;

/**
 * 库存监控命令。
 *
 * 注册命令：
 *   stock.monitor       正式执行
 *   stock.monitor.test  试运行（不推送）
 *
 * 运行：站点根目录下  ./bin index.php console stock.monitor
 */
class Monitor extends Command
{
    /** 命令入口 */
    public function run(): void
    {
        // 试运行判定：通过命令名区分
        $dry = ($this->command->getCommand() === "stock.monitor.test");

        $settings = $this->loadSettings();

        // 1) 采集库存
        try {
            $stock = new Stock($settings);
            $snapshot = $stock->collect();
        } catch (\Throwable $e) {
            $this->error("库存采集失败：" . $e->getMessage());
            return;
        }

        if (empty($snapshot)) {
            $this->error("未采集到任何 SKU。请检查：数据源 source、表前缀 table_prefix、以及 only_onsale 设置。");
            return;
        }

        // 2) 与上轮状态比对，得到「发生变化」的事件
        $state = new State($this->statePath());
        $prev  = $state->all();
        $first = $state->isEmpty();

        $alertLow     = !empty($settings["low_stock_alert"]);
        $alertRestock = !empty($settings["restock_alert"]);
        $alertFirst   = !empty($settings["alert_on_first_run"]);

        $events = [];
        $counts = ["ok" => 0, "out" => 0, "low" => 0];

        foreach ($snapshot as $key => $row) {
            $now    = $row["status"];
            $counts[$now] = ($counts[$now] ?? 0) + 1;
            $before = $prev[$key] ?? null;

            if ($before === null) {
                // 首次运行 / 新增 SKU：仅当允许时才作为告警
                if ($alertFirst && ($now === "out" || ($now === "low" && $alertLow))) {
                    $events[] = $row + ["kind" => $now];
                }
                continue;
            }

            if ($before === $now) {
                continue; // 无变化
            }

            if ($now === "out") {
                $events[] = $row + ["kind" => "out"];
            } elseif ($now === "low" && $alertLow) {
                $events[] = $row + ["kind" => "low"];
            } elseif ($now === "ok" && ($before === "out" || $before === "low") && $alertRestock) {
                $events[] = $row + ["kind" => "restock"];
            }
        }

        $total = count($snapshot);
        $this->info(sprintf(
            "巡检完成：共 %d 个SKU（有货 %d / 缺货 %d / 低库存 %d），本次变化 %d 项。",
            $total, $counts["ok"], $counts["out"], $counts["low"], count($events)
        ));

        // 3) 无变化
        if (empty($events)) {
            $state->replace($this->statusMap($snapshot)); // 更新基线
            $this->success("无库存状态变化。");
            return;
        }

        // 4) 试运行：只打印，不推送、不改状态
        if ($dry) {
            $this->info("【试运行】以下为待推送内容（未发送、未更新状态）：");
            foreach ($events as $e) {
                $this->info("  - " . $this->eventTitle($e));
            }
            return;
        }

        // 5) 校验推送配置（注意：绝不打印 token / uid）
        $appToken = trim((string)($settings["app_token"] ?? ""));
        $uids     = (array)($settings["uids"] ?? []);
        if ($appToken === "" || empty($uids)) {
            $this->error("未配置 app_token 或 uids，无法推送。请编辑 Config/Settings.php。本次不更新状态，下一轮将重试。");
            return;
        }

        // 6) 聚合为一条消息推送
        [$summary, $content] = $this->buildMessage($events, $settings);
        $pusher = new WxPusher($appToken, $uids, $settings);
        if ($pusher->send($summary, $content)) {
            $state->replace($this->statusMap($snapshot)); // 仅在成功后推进基线
            $this->success("已推送库存告警（" . $summary . "）。");
        } else {
            $reason = $pusher->lastError();
            $this->error("WxPusher 推送失败" . ($reason !== "" ? "：{$reason}" : "")
                . "。本次不更新状态，下一轮将重试。");
        }
    }

    /**
     * 读取配置：以 Config/Settings.php 为主；若后台插件配置中存在同名字段则覆盖。
     * @return array
     */
    private function loadSettings(): array
    {
        $file = dirname(__DIR__) . "/Config/Settings.php";
        $cfg  = is_file($file) ? (array)require($file) : [];

        try {
            $plugin = $this->getPlugin();
            $admin  = (array)$plugin->getConfig();
            if (!empty($admin)) {
                $cfg = array_merge($cfg, $admin);
            }
        } catch (\Throwable $e) {
            // CLI 环境可能取不到插件实体，忽略，使用 Settings.php
        }
        return $cfg;
    }

    /** 状态文件路径（插件自身目录下） */
    private function statePath(): string
    {
        return dirname(__DIR__) . "/Runtime/state.json";
    }

    /** 从快照中提取 状态集合，用于持久化 */
    private function statusMap(array $snapshot): array
    {
        $map = [];
        foreach ($snapshot as $key => $row) {
            $map[$key] = $row["status"];
        }
        return $map;
    }

    /** 单条事件标题（试运行 / 日志用，仅业务信息） */
    private function eventTitle(array $e): string
    {
        $icon = ["out" => "缺货", "low" => "低库存", "restock" => "补货"][$e["kind"]] ?? $e["kind"];
        $stock = $e["stock_text"] !== "" ? $e["stock_text"] : "未知";
        return sprintf("[%s] %s · %s（库存：%s）", $icon, $e["item"], $e["sku"], $stock);
    }

    /**
     * 聚合所有事件为一条推送消息。
     * @return array{0:string,1:string} [summary, content]
     */
    private function buildMessage(array $events, array $settings): array
    {
        $type = (int)($settings["content_type"] ?? 3);
        $nOut = $nLow = $nRe = 0;
        foreach ($events as $e) {
            if ($e["kind"] === "out") $nOut++;
            elseif ($e["kind"] === "low") $nLow++;
            elseif ($e["kind"] === "restock") $nRe++;
        }

        $parts = [];
        if ($nOut)  $parts[] = "缺货 {$nOut}";
        if ($nLow)  $parts[] = "低库存 {$nLow}";
        if ($nRe)   $parts[] = "补货 {$nRe}";
        $summary = "库存监控：" . implode(" / ", $parts);

        $time = date("Y-m-d H:i:s");
        $site = rtrim((string)($settings["site_url"] ?? ""), "/");

        // 组装正文
        $sections = [
            "out"     => ["title" => "🔴 缺货", "list" => []],
            "low"     => ["title" => "🟠 低库存", "list" => []],
            "restock" => ["title" => "🟢 补货", "list" => []],
        ];
        foreach ($events as $e) {
            $stock = $e["stock_text"] !== "" ? $e["stock_text"] : "未知";
            $line  = "- **" . $e["item"] . "** · " . $e["sku"] . "（库存：" . $stock . "）";
            if (!empty($e["url"])) {
                $line .= " [查看](" . $e["url"] . ")";
            }
            $sections[$e["kind"]]["list"][] = $line;
        }

        if ($type === 1) {
            // 纯文本
            $content = $summary . "\n";
            foreach ($sections as $sec) {
                if (empty($sec["list"])) continue;
                $content .= "\n" . $sec["title"] . "\n" . implode("\n", $sec["list"]) . "\n";
            }
            $content .= "\n时间：" . $time;
            return [$summary, $content];
        }

        // Markdown(3) 或 HTML(2) 统一用 Markdown 语法（WxPusher 的 HTML 模式也能较好渲染文本）
        $content = "# " . $summary . "\n\n";
        foreach ($sections as $sec) {
            if (empty($sec["list"])) continue;
            $content .= "## " . $sec["title"] . "\n" . implode("\n", $sec["list"]) . "\n\n";
        }
        $content .= "> 时间：" . $time;
        if ($site !== "") {
            $content .= "\n>\n> [进入商城](" . $site . ")";
        }
        return [$summary, $content];
    }
}
