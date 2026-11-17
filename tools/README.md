# TP5.1 → TP8 迁移工具集（通用化整理 / 部分参数化）

自真实迁移项目沉淀的脚本工具，覆盖「扫描 → dry-run 预览 → 机械修复 → 环境自检 → 全站回归」
工作流。所有脚本默认 **dry-run / 只读**，写操作需显式 `--apply`。

## 工具清单

### 静态扫描（无副作用，可直接用于任何 TP 项目）

| 脚本 | 检测什么 | 状态 |
| --- | --- | --- |
| `scan_collection_misuse.py` | `array_*()` 接收 `select()` 返回的 Collection（回溯变量最近赋值） | 通用 |
| `scan_ref_foreach.py` | `foreach ($rs as &$v)` 引用遍历作用于查询结果（ArrayIterator 持副本，修改丢失）；退出码可挂 CI | 通用 |
| `scan_table_alias.py` | `Db::name('t a')` / `table('t as a')` / `join('t as a')` 三类别名坏写法（TP8 拼成 `t_a`） | 通用 |

### 机械修复（先 dry-run 逐行复核，再 --apply）

| 脚本 | 修什么 | 状态 |
| --- | --- | --- |
| `fix_collection_misuse.py` | 在赋值语句最后一个 `select()` 行内插 `->toArray()` | 通用（目录参数微调） |
| `fix_name_alias.py` | `name('t a')` → `name('t')->alias('a')` | 通用 |
| `migrate_api.py` | `use think\Db` → `use think\facade\Db` | 已参数化（TP_ROOT 环境变量） |
| `migrate_module.py` | `Request::module()` / `$request->module()` → `app('http')->getName()` | 已参数化（TP_ROOT 环境变量） |
| `migrate_config_group.py` | 裸键 `config('k')` → `config('app.k')` | KEYS 白名单需替换为你项目的配置键 |

### 校验

| 脚本 | 用途 | 副作用 |
| --- | --- | --- |
| `check_env.php` | PHP 版本/扩展/目录权限/MySQL sql_mode 与排序规则/Redis/composer 一键自检，退出码可挂门禁 | 连库连 Redis（只读查询） |
| `verify_vendor_integrity.py` | 按 composer.lock 的 dist reference 下载官方 zip，与 vendor 逐文件 MD5 比对，证明依赖未被本地篡改 | 发网络请求、写临时目录（自动清理） |

### 全站回归（`regress/`，接入前需参数化，见其 README）

反射发现全部控制器入口 → 写操作「方法名 + 方法体」双判定排除 → 直写 Redis 构造登录态 →
curl_multi 并发打靶 → 错误特征聚类定性 → MD/CSV 报告。支持 `--base` 在不同 PHP 运行时
下对同一批链接对拍。

## 推荐工作流

```
1. check_env.php            环境达标
2. scan_*.py                静态扫描，产出问题清单
3. fix_*/migrate_* --dry-run  逐行预览复核
4. fix_*/migrate_* --apply    机械修复
5. 人工处理「需人工确认」桶    （扫描器保守策略：宁少不误）
6. verify_vendor_integrity.py 依赖完整性核验
7. regress/regress.sh        全站回归，失败按根因聚类批量修
8. 重跑对拍（--base 换运行时）  判定不一致项必须为 0
```

## 已知局限

- 扫描器基于正则，对跨文件赋值、条件赋值有漏报；开源深化建议改用 nikic/php-parser。
- `migrate_config_group.py` 的 KEYS 白名单是示例，稳妥做法是解析配置文件自动提取键名。
- 回归工具只判「服务健康」不判业务正确性；`WARN_EMPTY` 必须人工抽查（见 regress/README）。
- 时间戳类扫描/修复脚本（scan_timestamp_type / fix_timestamp_type / scan_bad_timestamp）
  与业务模型目录耦合较深，未纳入本包，可按参考手册第五章思路自行实现。