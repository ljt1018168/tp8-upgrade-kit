# 全站链接回归测试（TP5.1 → TP8 迁移通用工具）

面向 ThinkPHP 多应用项目的自动化回归工具：静态扫描出所有可访问入口，
自动构造登录态并发请求，按失败根因归类并输出报告。
提炼自一个真实 TP5.1 → TP8 + PHP 8.4 迁移项目，**接入前需按你的项目参数化**（见文末清单）。

## 快速开始

```bash
# 一键：扫描 → 回归 → 报告
bash docs/regress/regress.sh

# 只跑某几类
php docs/regress/run.php --kinds=page --concurrency=8

# 改超时（批处理接口慢，默认 60s）
php docs/regress/run.php --timeout=120

# 指定 cookie 名与登录跳转域名（判定 FAIL_AUTH 用）
php docs/regress/run.php --cookie=session_app_ --authhost=login.example.test
```

产物：

| 文件 | 说明 |
| --- | --- |
| `urls.json` | 扫描出的全部入口清单（含类型、参数个数） |
| `result.json` | 本次回归明细（状态码、耗时、判定、错误摘要） |
| `reports/regression-report.md` | 汇总报告（分类统计 + 失败明细） |
| `reports/regression-result.csv` | 明细表，可直接用 Excel 打开 |

> ⚠️ `result.json` 内含会话 sid，`urls.json` 暴露全部入口名——两者都**不要提交到公开仓库**。

## 四个脚本各自干什么

### 1. `bootstrap_session.php` — 构造登录态（示例驱动，需按项目改造）

后台若被鉴权中间件 + 控制器基类双重校验、且依赖 SSO/CAS 单点登录，
自动化不可能每次走登录流程，所以直接构造 session 写进 redis。

关键点（TP8 与 TP5.1 不同，照抄会踩坑）：

| 项 | TP5.1 | TP8 |
| --- | --- | --- |
| cookie 名 | PHPSESSID | config/session.php 的 `name`（示例 `session_app_`） |
| session id | 任意 | **必须 32 位纯字母数字**（`Store::setId()` 强校验，否则重新生成） |
| redis key | `{cookie名}_{sid}` | cache 前缀 + session 前缀 + `{sid}`（示例 `app_` + `think` + sid） |
| 值 | `serialize($data)` | `serialize(serialize($data))`（session 层一次 + cache 驱动一次） |

只写一层 serialize 的话，读时 cache 驱动 `unserialize` 会把它变成数组，
`Cache::read()` 里的 `(string)` 强转数组 → `Array to string conversion` → 整页 500。

```bash
php docs/regress/bootstrap_session.php --user=your_admin
```

### 2. `discover.php` — 链接发现

反射扫描 `app/*/controller/*.php` 的全部 public 方法，加上路由文件里显式注册的规则，
按「是否可能产生副作用」分类：

| kind | 含义 | 默认执行 |
| --- | --- | --- |
| `page` | 返回 HTML 视图的方法 | ✅ |
| `ajax` | 控制器名含 ajax，返回 JSON | ✅ |
| `route` | 路由文件里注册的无参规则 | ✅ |
| `write` | 疑似写操作 | ❌ |
| `param` | 有必填参数 | ❌ |

`write` 的判定是**两道**：

1. 方法名命中 `add/save/update/del/import/send/confirm/change...` 等关键词；
2. 方法体里出现 `->insert(` `->update(` `->save(` `Db::execute(` 等写库调用。

第二道是必须的——真实项目里出现过「名字完全看不出在写库、方法体里却 `insertAll()`」
的入口，第一版脚本把它当只读请求跑，卡在 60s 超时。

`AjaxDemo` / `Demo` 控制器整体排除（一次性脚本性质，混着大量临时写逻辑）。

### 3. `run.php` — 并发执行

curl_multi 并发，单请求只保留前 64KB 响应体用于错误检测。

判定不看业务 `code`，只看「服务是否健康」：

| 判定 | 含义 |
| --- | --- |
| `PASS` | 200 且无 PHP 错误标记 |
| `FAIL_500` | HTTP 500 |
| `FAIL_RUNTIME` | 200 但页面含 Fatal / Deprecated / Warning / 异常页 |
| `FAIL_404` | 路由或模板缺失 |
| `FAIL_AUTH` | 302 到登录域名（`--authhost` 配置，登录态失效） |
| `FAIL_NET` | 超时/连接失败 |
| `WARN_EMPTY` | 200 但响应为空 |

`WARN_EMPTY` 不一定是问题：批处理接口和「`return false`」的接口本来就返回空，
需要结合业务判断。

> ⚠️ **但 `WARN_EMPTY` 里可能藏着真 bug，必须逐个抽查，不能整类放过。**
> 真实案例：某二维码接口的参数是 `Request::get('content', '')` 取的（不在方法签名里），
> 静态扫描得 `params_required=0` → 被当作可自动执行的 ajax 入口；无参调用时
> `if (!$url) return '';` 直接早退 → 200 空响应 → 判 `WARN_EMPTY`。
> 而它内部调用的服务因依赖被删已经完全是坏的，**这个错误被"空响应"盖到用户点击那天才暴露**。
>
> 识别方法：对「签名无参、但方法体含 `Request::get/post('key')`」的入口单列一份清单，逐个填占位值验证。

### 4. `report.php` — 生成报告

按错误特征做二次聚类（表不存在 / 字段不存在 / 对 null 调用 / 数组当字符串 / 模板缺失…），
同一根因的失败合并成一组，便于批量修。

失败明细里会标注「疑似缺参数」：这类是无参调用触发的必然报错
（`->validate() on null`、`Division by zero`、表名为空），真实调用带参数就正常。

## 已知边界

- **有必填参数的入口不自动测**：多为模板辅助方法（如 `get_xxx_name($id)`），
  外部不会直接访问；真实页面入口需要人工按业务传参。
- **写操作不自动测**：GET 无参也可能改数据，宁可漏测不可误写。
- **批处理接口会超时**：`batch*`、`calculate*` 类定时任务入口，
  无参调用会跑全量数据，60s 超时属预期行为。
- **测试库缺表导致的 500 不算代码缺陷**：如按年份分表的进度表，
  确认生产库是否真有该表再判断。
- **`Request::get/post` 取参的入口存在盲区**：这类参数不在方法签名里，
  `params_required` 恒为 0，无参调用只走早退分支 → 判 `WARN_EMPTY`。
  有参才执行的缺陷测不出来，需人工带参抽查。
  改进方向：让 `discover.php` 解析方法体里的 `Request::*('key')` 并生成占位值。

## 接入你的项目：参数化清单

| 位置 | 需要改什么 |
| --- | --- |
| `lib/env.php` | `.env` 分组名（CONFIG_DATABASE / CONFIG_CACHE / CONFIG_CAS）、站点基址默认值、session 前缀 |
| `bootstrap_session.php` | 用户表 / 权限表 SQL、session 字段结构（与鉴权中间件读取的键一致） |
| `run.php` | `--cookie`（config/session.php 的 name）、`--authhost`（登录跳转域名） |
| `discover.php` | 排除控制器清单（Demo 等）、`app/compat.php` 引导路径（若有兼容层） |
| `regress.sh` | 路径与默认参数 |

## 加新页面后怎么回归

不用改脚本，直接重跑 `regress.sh` —— `discover.php` 会重新扫描控制器，
新方法自动进入清单。