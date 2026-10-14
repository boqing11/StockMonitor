# StockMonitor — MCY-SHOP 库存监控插件

> 商城每个商品规格（SKU）真正缺货 / 低库存 / 补货时，**自动推送微信消息**。
> 服务端定时运行，不需要电脑挂机；只读数据库，不改任何业务数据。

## 它解决什么问题

MCY-SHOP 商城有个监控盲区：商品库存由「货源」决定，**前台经常一直显示"大量"**，
但买家点进商品、选中具体规格后，却提示"暂时无货"——下单失败，你也错过补货时机。

本插件直接读取系统内部的库存缓存，精确判断每个 SKU 的真实状态：

| 推送时机 | 说明 |
| --- | --- |
| 🔴 缺货 | 某个 SKU 变为不可购买 |
| 🟠 低库存 | 库存数量 ≤ 你设定的阈值 |
| 🟢 补货 | 由缺货/低库存恢复为有货 |

**只在状态变化时推送**，不会每轮重复轰炸。

---

## 一、文件清单

| 文件 / 目录 | 作用 | 要不要上传服务器 |
| --- | --- | --- |
| `StockMonitor/` | 插件本体 | ✅ 传到 `app/Plugin/` 下 |
| `StockMonitor.zip` | 打包好的上传包（解压即用） | ✅ 传这个也行，二选一 |
| `stock-register.php` | 免授权注册命令（见第三步） | ✅ 用到时传 |
| `db-check.php` | 自动探测数据库表前缀 | ✅ 用到时传 |
| `fix-settings.php` | 自动修复配置文件的引号错误 | ✅ 用到时传 |
| `wxpusher-test.php` | 推送链路诊断（收不到推送时用） | ✅ 用到时传 |

> 4 个 `.php` 小工具是"用到才传"，传到服务器**任何位置**都行（站点内外均可），用完可以删。

---

## 二、安装教程（共 6 步，每步都有"成功的标志"）

> 全程在**服务器终端**操作。宝塔用户：左侧「终端」或「文件」，均可。
> 下文把 `<站点目录>` 替换成你的网站根目录，例如 `/www/wwwroot/你的站点`。

### 第 0 步：准备（2 分钟）

1. 微信扫码关注公众号 **「WxPusher」**；
2. 公众号菜单 → 我的 → 记下两个值：
   - **AppToken**（形如 `AT_xxxxxxxx`）
   - **UID**（形如 `UID_xxxxxx`，接收人，可以有多个）

### 第 1 步：上传插件

方式 A（推荐，最不容易错）：上传 `StockMonitor.zip` 到**站点根目录**，在终端解压并把插件拖进 `app/Plugin/`：

```bash
cd <站点目录>
unzip -o StockMonitor.zip -d /tmp/sm_pkg
cp -r /tmp/sm_pkg/StockMonitor app/Plugin/
```

方式 B：用宝塔「文件」，把 `StockMonitor` 整个文件夹上传到 `<站点目录>/app/Plugin/` 下。

**成功的标志**：目录长这样，注意**不要嵌套**（`app/Plugin/StockMonitor/StockMonitor/` 是错的）：

```
<站点目录>/app/Plugin/StockMonitor/
├── Config/      ← 你的配置在这
├── Command/
├── Lib/
└── Runtime/     ← 运行状态目录
```

### 第 2 步：注册命令（免授权，关键一步）

MCY-SHOP 的插件在后台点「启动」时会向官方商店核对授权，**自己开发的插件没有授权记录，必然被拦**。
本插件不需要点启动——用 `stock-register.php` 直接把命令写进框架的命令表，功能完全一样：

```bash
# 把 stock-register.php 传到站点根目录，然后：
cd <站点目录>
php stock-register.php
```

**成功的标志**：输出里出现 `stock.monitor` 和 `stock.monitor.test` 两个命令，并提示写入成功。

> 这个脚本不修改框架任何文件、不访问网络，系统更新（kit.update）也不会使它失效。
> 查看已注册：`php stock-register.php --list`；卸载：`php stock-register.php --remove`。

### 第 3 步：填写配置

编辑 `<站点目录>/app/Plugin/StockMonitor/Config/Settings.php`，填两项必填：

```php
"app_token" => "AT_xxxxxxxx",        // 第 0 步记下的 AppToken
"uids"      => ["UID_xxxxxx"],       // 第 0 步记下的 UID
```

⚠️ 所有值必须用**英文双引号**。如果你从别处复制带进了中文引号“ ”，或引号莫名其妙丢了，
运行时会报 `Undefined constant "UID_xxx"`——把 `fix-settings.php` 传到站点根跑一下即可自动修：

```bash
cd <站点目录> && php fix-settings.php
```

**表前缀**（重要）：如果你的数据库表名带前缀（如 `mcy_item_sku`），必须告诉插件。
把 `db-check.php` 传到站点根，自动探测并写入：

```bash
cd <站点目录>
php db-check.php          # 先看探测结果（会列出相关表名和建议前缀）
php db-check.php --fix    # 确认无误后自动写入 Settings.php（写前自动备份）
```

无前缀的站点保持 `"table_prefix" => ""` 不用动。

**只监控某个商家/分站（可选）**：多商家共用数据库时，只想监控其中一家（如 `qing`），
先查出它的用户 ID（宝塔 → 数据库 → 管理 → 左侧先点数据库名 → SQL 标签页执行）：

```sql
SELECT id, username FROM <前缀>user WHERE username = 'qing';
```

把查到的数字填进配置（纯数字，不要引号）：

```php
"filter_user_id" => 123,
```

### 第 4 步：试运行（不推送、不改状态）

```bash
cd <站点目录>
./bin index.php console stock.monitor.test
```

**成功的标志**：输出 `巡检完成：共 N 个SKU（有货 X / 缺货 Y / 低库存 Z）`。
看到这行说明数据库、表前缀、采集全部正常。

> 如果报 `Table 'xxx.item_sku' doesn't exist` → 表前缀没配对，回第 3 步跑 `db-check.php`。
> 如果提示找不到命令 → 回第 2 步确认 `stock-register.php` 执行成功。

### 第 5 步：手动推送一次，验证微信能收到

首次运行默认**只建基线、不推送**（防止一上来被刷屏），所以此时收不到消息是正常的。
想亲眼验证推送链路，手动触发一次全量推送：

```bash
cd <站点目录>
rm -f app/Plugin/StockMonitor/Runtime/state.json    # 删掉基线，下一轮算"首次运行"
```

把 `Settings.php` 里 `"alert_on_first_run"` 临时改成 `true`，然后：

```bash
./bin index.php console stock.monitor
```

**成功的标志**：微信收到推送，日志出现 `已推送库存告警`。
然后**把 `alert_on_first_run` 改回 `false`**（重要！否则以后每次删基线都会全量推一轮）。

> 如果你是 root 身份手动跑的，跑完把生成的文件属主改回 www，避免定时任务写不进去：
> ```bash
> chown www:www app/Plugin/StockMonitor/Runtime/state.json runtime/stock-monitor.log
> ```

### 第 6 步：设置定时任务（让它自动跑）

宝塔面板 → 计划任务 → 添加：

| 项目 | 填什么 |
| --- | --- |
| 任务类型 | Shell 脚本 |
| 执行周期 | 每 5～30 分钟（建议 30 分钟） |
| 执行用户 | **www**（不要选 root，见注意事项第 1 条） |
| 脚本内容 | 见下方 |

脚本内容（把 `<站点目录>` 换成你的）：

```bash
cd <站点目录>
./bin index.php console stock.monitor >> runtime/stock-monitor.log 2>&1
```

保存后点一次任务的 **「执行」** 按钮手动触发，然后查看日志确认真的在跑：

```bash
tail -5 <站点目录>/runtime/stock-monitor.log
```

**成功的标志**：日志里出现 `巡检完成：共 N 个SKU…本次变化 X 项`。

---

## 三、以后日常

- **什么都不用做**。定时任务每 30 分钟自动巡检，有变化才推微信。
- 想看日志：`tail <站点目录>/runtime/stock-monitor.log`
- 想改推送频率 → 改计划任务周期；想只推缺货不推补货 → `Settings.php` 关掉 `"restock_alert"`
- 想换接收人 → 改 `uids`；想加主题组推送 → 填 `topic_ids`

---

## 四、注意事项（⚠️ 都是实战踩坑总结）

1. **定时任务执行用户必须选 `www`，不要选 root。**
   root 跑会把日志和状态文件创建成 root 属主，之后网站（www 身份）写不进去，报 `Permission denied`。
   如果你已经用 root 跑过，或上传的文件属主是 root，先修属主：
   ```bash
   chown -R www:www <站点目录>/runtime <站点目录>/app/Plugin/StockMonitor
   chmod 755 <站点目录>/bin
   ```

2. **宝塔计划任务日志里的 `★ Successful` 不代表脚本成功**，它只表示"任务被调度了"。
   真正的结果看 `runtime/stock-monitor.log`。

3. **收不到推送先分清两种情况**：日志显示「无库存状态变化」= 正常（只在变化时推）；
   显示「WxPusher 推送失败」= 真故障，用 `wxpusher-test.php` 诊断：
   ```bash
   cd <站点目录> && ./bin wxpusher-test.php
   ```
   它会逐层打印：PHP 扩展 → token/uid 配置 → DNS → 服务端真实返回码。

4. **打包运行时可能缺 CA 证书**：若日志报 `curl 错误码 77: error setting certificate file`，
   说明服务器缺根证书。CentOS 补软链、Debian/Ubuntu 装包：
   ```bash
   # CentOS（证书包在 /etc/pki/tls/certs/ca-bundle.crt，只是软链丢了）：
   mkdir -p /etc/ssl/certs && ln -sf /etc/pki/tls/certs/ca-bundle.crt /etc/ssl/certs/ca-certificates.crt
   # Ubuntu / Debian：
   apt-get install -y ca-certificates
   ```

5. **日志里 `sudo: no tty present…` 是无害噪音**：框架每次执行命令都尝试安装 `mcy` 快捷方式，
   www 没有 sudo 权限就会打印这段，不影响巡检和推送，无视即可。

6. **`alert_on_first_run` 验证完推送后记得改回 `false`。**

7. **所有工具脚本都是"写前自动备份"**：`Settings.php` 会被备份成 `Settings.php.bak-xxx`，改坏了可以找回。

8. **zip 解压出乱码文件名不影响使用**：本仓库所有文件名均为英文，就是为了避免宝塔按 GBK 解压 UTF-8 文件名时乱码。

9. **不要在 `app/Plugin/StockMonitor/` 里再嵌一层 `StockMonitor/`**，层级必须是 `app/Plugin/StockMonitor/Config/…`。

---

## 五、常见问题（FAQ）

**Q1：后台插件列表点「启动」报"此插件你没有授权"？**
不需要点启动。这是 MCY-SHOP 对商店插件的授权校验，自研插件没有授权记录必然被拦。
用第 2 步的 `stock-register.php` 注册命令即可，后台按钮可以不用管。

**Q2：提示"未找到命令 stock.monitor"？**
命令没注册成功。重跑 `php stock-register.php <站点目录>`，或加参数指定站点目录
（脚本不在站点里时）：`php stock-register.php /www/wwwroot/你的站点`。

**Q3：报 `Undefined constant "UID_xxx"` 或 `"AT_xxx"`？**
`Settings.php` 里引号丢了或带进了中文引号。跑 `php fix-settings.php` 自动修，
或手动确保值是英文双引号：`"app_token" => "AT_xxx"`。

**Q4：报 `Table 'xxx.item_sku' doesn't exist`？**
表前缀没配。跑 `php db-check.php` 探测，`php db-check.php --fix` 写入。

**Q5：提示"未采集到任何 SKU"？**
检查 `table_prefix`；把 `only_onsale` 改成 `false` 再试；用 `stock.monitor.test` 看采集数量。

**Q6：收不到推送？**
先看日志有没有「推送失败」及具体原因（新版插件会带原因），再用 `./bin wxpusher-test.php` 诊断。
常见：token/uid 复制错（服务端返回 code=1001/1009）、服务器出网被拦（错误码 7/28）、缺 CA 证书（错误码 77）。

**Q7：推送里混进了别家商家的商品？**
配置 `filter_user_id` 为对应商家的 user_id（查法见第 3 步）。

**Q8：phpMyAdmin 执行 SQL 报 `#1046 No database selected`？**
左侧列表先点一下数据库名（或库里任意一张表），再开 SQL 标签页。

---

## 六、卸载

```bash
# 1. 移除本插件注册的命令（可选，不清也不影响，重启后框架会忘记）
php stock-register.php <站点目录> --remove
# 2. 删掉插件目录
rm -rf <站点目录>/app/Plugin/StockMonitor
# 3. 删掉对应的宝塔计划任务
```

---

## 七、安全说明

- **只读**：插件只对库存相关表执行 `SELECT`，不做任何增删改。
- **写入范围**：仅在 `app/Plugin/StockMonitor/Runtime/` 内写巡检状态文件，不碰站点其它文件。
- **网络**：仅向 WxPusher 官方域名 `wxpusher.zjiecode.com` 发送一次 POST，不访问其它外部地址。
- **不泄密**：推送内容只含商品名 / 规格名 / 库存（和可选商品链接）；
  不含服务器路径、IP、数据库账号等敏感信息；日志不记录 AppToken 与 UID。
- **凭据保管**：`Settings.php` 含你的 AppToken，属敏感文件——不要提交到公开仓库、
  不要明文外发，建议 `chmod 600 app/Plugin/StockMonitor/Config/Settings.php`。
  本仓库自带的 `Settings.php` 所有敏感项均为空，请自行填写。

---

## 八、目录结构

```
.
├── README.md               ← 本教程
├── LICENSE                 ← MIT 协议
├── StockMonitor/           ← 插件本体（上传到 app/Plugin/）
│   ├── Config/Settings.php ← 你的配置（改这个）
│   ├── Command/Monitor.php ← 监控命令
│   ├── Lib/                ← 采集/推送/状态
│   └── Runtime/            ← 运行状态（state.json 删它=重置基线）
├── stock-register.php      ← 免授权命令注册
├── fix-settings.php        ← 配置引号修复
├── db-check.php            ← 表前缀探测
├── wxpusher-test.php       ← 推送链路诊断
└── StockMonitor.zip        ← 打包上传包
```

## 九、开源协议

[MIT License](./LICENSE)。仅供学习交流，请勿用于任何违法用途。
