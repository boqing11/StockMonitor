<?php
declare(strict_types=1);

namespace App\Plugin\StockMonitor\Lib;

/**
 * 巡检状态持久化。
 *
 * 把上一轮每个 SKU 的状态（ok / out / low）以 JSON 保存在插件自身目录下的
 * Runtime/state.json，用于「仅在状态发生变化时推送」，避免重复轰炸。
 *
 * 仅在本插件目录内读写，不触碰站点其它文件。
 */
class State
{
    private string $file;

    public function __construct(string $file)
    {
        $this->file = $file;
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    /** 是否从未生成过状态（即首次运行） */
    public function isEmpty(): bool
    {
        return empty($this->all());
    }

    /**
     * 读取全部状态：["key" => "ok|out|low", ...]
     * @return array<string,string>
     */
    public function all(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $raw = @file_get_contents($this->file);
        if ($raw === false || $raw === "") {
            return [];
        }
        $arr = json_decode($raw, true);
        return is_array($arr) ? $arr : [];
    }

    /**
     * 覆盖写入状态（带文件锁 + 原子替换，防止并发写入损坏）。
     * @param array<string,string> $map
     */
    public function replace(array $map): void
    {
        $tmp = $this->file . "." . getmypid() . ".tmp";
        if (@file_put_contents($tmp, json_encode($map, JSON_UNESCAPED_UNICODE)) === false) {
            return;
        }
        $lock = $this->file . ".lock";
        $fp = @fopen($lock, "c");
        if ($fp) {
            if (flock($fp, LOCK_EX)) {
                @rename($tmp, $this->file);
                flock($fp, LOCK_UN);
            } else {
                @rename($tmp, $this->file);
            }
            fclose($fp);
        } else {
            @rename($tmp, $this->file);
        }
    }
}
