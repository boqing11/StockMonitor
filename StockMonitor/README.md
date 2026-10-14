# StockMonitor · MCY-SHOP 库存监控插件

服务端库存监控插件：精确到每个商品 SKU，缺货 / 低库存 / 补货时通过 WxPusher 推送到微信。

**完整使用教程请见上级目录的 `README.md`。**

快速开始：
1. 把本 `StockMonitor` 文件夹上传到 `<网站根目录>/app/Plugin/`（注意不要嵌套）；
2. 用上级目录的 `stock-register.php` 免授权注册命令：
   `php stock-register.php <站点根目录>`（后台点「启动」会报你没有授权，不用管，详见 README 第 2 步）；
3. 编辑 `Config/Settings.php` 填入 `app_token` 与 `uids`；
   - 若试运行报 `Table 'xxx.item_sku' doesn't exist`：用上级目录的 `db-check.php`
     自动探测并写入表前缀（`php db-check.php --fix`，详见 README 第 3 步 / FAQ Q4）；
4. 试运行：`./bin index.php console stock.monitor.test`；
5. 验证推送一次后，设置宝塔计划任务（执行用户选 **www**，详见 README 第 6 步）。
