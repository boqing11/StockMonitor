# GitHub 发布材料

> 三个部分：仓库 About 描述、Topics 话题标签、v1.0.0 Release 说明。  


---

## 一、仓库 About 描述

**Description**（设置页 About 右侧「Description」输入框，直接粘贴）：

```
MCY-SHOP 库存监控插件：精确到每个商品 SKU，缺货/低库存/补货时自动推送微信（WxPusher）。服务端定时运行、只读数据库、免商店授权，附 4 个开箱即用排错工具与小白教程。
```

**Website**（可选，留空或填教程链接）：

```
https://github.com/<你的用户名>/StockMonitor
```

**勾选 Releases / Packages / Deployments 按需**，建议至少勾选 **Releases**（方便别人下 `StockMonitor.zip`）。

---

## 二、Topics 话题标签

仓库主页右侧齿轮「Manage topics」→ 逐条粘贴（共 13 个，GitHub 上限）：

```
mcy-shop
php
hyperf
swoole
inventory-monitor
stock-monitor
wxpusher
wechat-notification
ecommerce
baota
cron
php8
plugin
```

---

## 三、第一份 Release 说明（v1.0.0）

**Create a new release** → `Choose a tag` 输入 `v1.0.0` → Release title 填 `v1.0.0 — 首个正式版` → 下方粘贴：

---

### 🎉 StockMonitor v1.0.0 — 首个正式版

MCY-SHOP 商城库存监控插件：解决前台库存恒显示"大量"、实际已缺货的监控盲区，**精确到每个商品 SKU**，缺货 / 低库存 / 补货时通过 WxPusher 自动推送微信。

#### ✨ 特性

- **SKU 级精确监控**：以系统内部「是否可购买」缓存为权威信号，而非前台"大量"文本
- **三种推送时机**：🔴 缺货 / 🟠 低库存（阈值可设）/ 🟢 补货恢复；只在状态变化时推送，不刷屏
- **服务端定时运行**：宝塔计划任务即可，无需本地挂机；数据库只读，零侵入
- **免商店授权**：自带 `stock-register.php` 直接注册命令，绕开官方插件授权校验，不修改框架任何文件，系统更新（kit.update）不失效
- **按商家/分站过滤**：`filter_user_id` 只监控指定供货商，多商家共用库不串扰
- **推送链路自诊断**：curl / stream 双通道自动降级，失败时日志带真实原因（错误码、服务端返回）

#### 📦 附件

| 文件                 | 说明                              |
| ------------------ | ------------------------------- |
| `StockMonitor.zip` | 打包上传包，解压即用（zip 内全英文文件名，宝塔解压不乱码） |
| 源码（Source code）    | 完整仓库，含 4 个排错工具与小白教程             |

#### 🛠 环境要求

- MCY-SHOP 5.x（Hyperf / Swoole）
- PHP 8.1+、MySQL 5.7+/8.0
- WxPusher 账号（微信扫码关注公众号即可，免费）

#### 🚀 5 分钟上手

```bash
# 1. 上传插件（解压 zip 后把 StockMonitor 放进 app/Plugin/，勿嵌套）
# 2. 免授权注册命令
php stock-register.php /www/wwwroot/你的站点
# 3. 填写 Config/Settings.php 的 app_token 与 uids（表前缀可自动探测）
php db-check.php --fix
# 4. 试运行（不推送、不改状态）
./bin index.php console stock.monitor.test
# 5. 宝塔计划任务：*/30 * * * * cd /www/wwwroot/你的站点 && ./bin index.php console stock.monitor >> runtime/stock-monitor.log 2>&1
```

> ⚠️ 计划任务**执行用户选 www**，不要选 root；详细注意事项见 [README](README.md)。

#### 🧰 附带排错工具

| 工具                   | 用途                          |
| -------------------- | --------------------------- |
| `stock-register.php` | 免授权注册 / 查看 / 移除命令           |
| `fix-settings.php`   | 自动修复 Settings.php 引号丢失/中文引号 |
| `db-check.php`       | 自动探测数据库表前缀并写入配置             |
| `wxpusher-test.php`  | 逐层诊断推送链路（扩展→配置→DNS→服务端返回）   |

#### 📝 已知说明

- 首次运行默认只建立基线不推送（`alert_on_first_run=false`），验证推送后请保持该值
- 日志中 `sudo: no tty present` 为框架安装 `mcy` 快捷方式的噪音，无害
- 若服务端缺 CA 证书（curl 错误码 77），按 README 注意事项第 4 条修复

#### 📄 License

[MIT](LICENSE)

**Full Changelog**: `v1.0.0`（首个版本）

---

## 四、发布后建议

1. 仓库主页把 **README 里的目录截图**补上（有图对小白更友好，star 率高一倍）
2. 开启 Issues，把 FAQ 里高频问题做成 Issue 模板
3. 想要国内访问量可同时推一份到 Gitee（文件名全英文，两边通用）
