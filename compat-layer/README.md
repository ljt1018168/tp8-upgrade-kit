# TP5.1 → TP8 兼容层参考实现

从一个真实 TP5.1.41 + PHP 7.4 → TP8.1.2 + PHP 8.4 迁移项目中提炼的兼容层代码。
**定位是「参考实现」而非即装即用的 composer 包**：命名空间保留原项目的 `app\` 结构，
接入你的项目时按下方说明调整。所有文件均为通用化实现，不含业务数据与凭证。

## 文件清单与作用

| 文件 | 解决的断层 | 通用性 |
| --- | --- | --- |
| `compat.php` | TP8 移除全局类别名（Env/Db/Cache…）与 `think\Controller`、`controller()` 助手 | 改默认应用名即可用 |
| `service/DeprecationFilter.php` | vendor 内 E_DEPRECATED 被 TP8 抛成异常（如阿里云日志 SDK 隐式可空参数） | 可直接用 |
| `service/Php8LegacyNoticeCompat.php` | PHP8 把大量 NOTICE 升格为 WARNING，TP8 的 Error::appError 直接抛异常 | 可直接用（正则清单按需裁剪） |
| `service/LegacyModelAutoFill.php` | think-orm 4.x 移除 `$auto/$insert/$update` 自动完成；`$type` 的 timestamp 语义反转 | 可直接用（字段名可配置化） |
| `cache/NormalizesCacheValue.php` + `cache/Redis.php` + `cache/File.php` | `select()` 返回持闭包的 Collection，写缓存 `Serialization of 'Closure'` 整页 500 | 可直接用 |
| `file/LegacyUploadedFile.php` | TP8 UploadedFile 无 `validate()`、`move()` 失败抛异常、无 `getSaveName()/getInfo()` | 改命名空间即可用 |
| `middleware/LegacyResponseCompat.php` | TP8 不再宽容「控制器 return 数组/false」，Html 响应抛 variable type error | 可直接用 |
| `jump/Jump.trait.php` + `jump/Jump.response.php` + `jump/BaseController.php` + `jump/dispatch_jump.tpl` | 重建 TP5.1 的 success/error/result/redirect/assign/fetch/validate 全套 | 需统一改命名空间与 config 键 |
| `view/Think.php` | TP8 模板引擎拆包后丢失「应用级 view_path / @跨模块 / auto_rule」定位逻辑；`action($convert)` 参数语义反转 | 可直接用（模糊匹配兜底建议做成可选开关） |
| `helpers.php` | setRedis/getRedis/delRedis 语义还原、日志路径锚定、upload_file 入口 | 可直接用 |

## 接线方式（原项目验证过的组装顺序）

1. **autoload**：`compat.php` 必须在 **config 目录加载之前**执行（配置文件里有 `Env::get()`）。
   原项目靠 `app/common.php` 首行 include 实现；做包时建议用 composer `files` autoload
   或 Service 的早期钩子。
2. **service.php** 显式注册（顺序有意义）：
   ```php
   return [
       \think\app\Service::class,              // think-multi-app
       \app\common\service\DeprecationFilter::class,      // 先：vendor 弃用降级
       \app\common\service\Php8LegacyNoticeCompat::class, // 后：业务 notice 降级
       \app\common\service\LegacyModelAutoFill::class,    // Model::maker 钩子
   ];
   ```
   两个错误处理器是**叠加链**：各自只处理自己认领的消息，其余回传框架。
3. **全局中间件** `app/middleware.php`：
   ```php
   return [
       \think\middleware\SessionInit::class,          // 必须最前；TP8 不再自动托管 session
       \app\http\middleware\LegacyResponseCompat::class,
   ];
   ```
   且入口文件必须 `$http->end($response)`——否则 session 不落盘。
4. **config 指向自定义类**：
   - `cache.php` stores.redis.type → `app\common\cache\Redis`；stores.file.type → `app\common\cache\File`
   - `view.php` type → `app\common\view\driver\Think`
   - `app.php` 的 `dispatch_success_tmpl / dispatch_error_tmpl` → `app/tpl/dispatch_jump.tpl`
5. **类别名注意**：不要注册全局 `Log` 别名——阿里云日志 SDK 在全局命名空间声明了
   `class Log`，会触发 Cannot redeclare。

## 接入前必改项

- 所有 `app\` 命名空间 → 你的包/应用命名空间（`helpers.php` 里 `LegacyUploadedFile` 引用同步改）。
- `compat.php` 的 `controller()` 默认应用名读 `app.default_app`，确认你的默认应用配置。
- `Jump.trait.php` 读 `app.dispatch_*_tmpl`、`route.default_ajax_return` 等键，按你的配置对齐。
- `Php8LegacyNoticeCompat` 的正则清单按你项目的真实 notice 收敛，**不要全局关 warning**。

## 重要警示

1. **错误降级类组件（DeprecationFilter / Php8LegacyNoticeCompat）是过渡期止血手段**，
   会掩盖真实缺陷。务必保留日志输出，并制定「日志清单收敛 → 逐步移除降级规则」的退场路线。
2. `LegacyModelAutoFill` 用 `withFieldAttr` 直通获取器保持 int 时间戳原样输出；
   **不要把 `dateFormat` 设为 `false`**——那会反噬真正的 datetime 列（原项目实测 Fatal）。
3. 缓存归一化必须**读写两端类型一致**：只改写入端，命中缓存返回 array、未命中返回
   Collection，调用方写死 `->toArray()` 仍会 500。
4. 判断响应类型用 `get_class($response) === Html::class`，**不要用 instanceof**（继承链误伤）。

## 许可证

- `jump/` 四件套与 `view/Think.php` 移植/重建自 ThinkPHP 5.1 框架源码
  （Copyright (c) 2006-2018 ThinkPHP，Apache-2.0），公开分发时须保留 Apache-2.0
  署名与 LICENSE 副本。
- 其余文件为迁移过程原创实现，公开时建议同样采用 Apache-2.0 以与框架生态兼容。