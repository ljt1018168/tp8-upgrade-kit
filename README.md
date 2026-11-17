# TP8 Upgrade Kit

[![GitHub stars](https://img.shields.io/github/stars/ljt1018168/tp8-upgrade-kit?style=social)](https://github.com/ljt1018168/tp8-upgrade-kit/stargazers)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://www.php.net/)
[![ThinkPHP](https://img.shields.io/badge/ThinkPHP-8.1-1E90FF.svg)](https://doc.thinkphp.cn/@v8_0/)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)

ThinkPHP 5.1 → 8.x 大版本升级经验共享包：**兼容层参考实现 + 迁移工具集 + 实战参考手册**。
帮助卡在 PHP 7.4 / ThinkPHP 5.1 的老项目升级到受安全维护的 PHP 8.x + ThinkPHP 8，
修补 PHP 远程代码执行漏洞（CVE-2026-109618 / CVE-2026-109619，PHP2Shell）。

提炼自一个真实业务系统的完整迁移（PHP 7.4.33 + TP 5.1.41 → PHP 8.4 + TP 8.1.2，
252 个控制器、222 个模板、约 4,000 个文件，0 单测 0 CI 起步）——
**升级动因正是 CVE-2026-109618 / CVE-2026-109619**：PHP 7.4 无官方补丁可打，
ThinkPHP 5.1 又无法直接跑在新 PHP 上，只能连框架一起升级。
全站回归通过率 95.4%，500 错误从 35 降至 3（均定性为非代码缺陷）。

## 🔴 为什么现在必须升级：PHP 严重 RCE 漏洞（CVE-2026-109618 / CVE-2026-109619）

2026-10-21，PHP 官方紧急发布安全更新，修复两个**未认证远程代码执行（RCE）**漏洞
（媒体称 **PHP2Shell**，CVSS 4.0 均为 **9.2**，已被广泛在野利用）：

| CVE | 成因 | 影响 |
| --- | --- | --- |
| CVE-2026-109618 | OOM（内存耗尽）处理中的内存破坏 | 未认证 RCE |
| CVE-2026-109619 | 请求生命周期内的内存安全问题 | 未认证 RCE |

**修复版本**（2026-10-21 发布）：PHP **8.5.12 / 8.4.27 / 8.3.36 / 8.2.35**。
官方明确：**没有完整的缓解措施，升级是唯一安全选择**；Apache mod_php 风险最高
（未认证请求可在任何应用代码执行前触发），PHP-FPM、php-cgi、FrankenPHP、LiteSpeed 同样受影响。
详见 [PHP 官方安全公告](https://www.php.net/archive/2026.php#2026-10-21-5)、
[HKCERT 保安公告](https://www.hkcert.org/tc/security-bulletin/php-multiple-vulnerabilities_20261022)。

**老项目为什么不能「只换 PHP 运行时」**：

- PHP **7.4 / 8.0 / 8.1 已 EOL**，官方不再提供安全修复（8.2 也将于 2026-12-31 EOL）；
- ThinkPHP 5.1 同样停止维护，且**直接跑在 PHP 8.3+ 上会 Fatal**
  （容器编译期不兼容，实测结论见参考手册）——必须连框架一起升；
- 这正是本仓库的用途：给 TP5.1 老项目一条**经过真实全站回归验证**的
  TP8 + PHP 8.4 迁移路径，而不是停留在「关 warning 续命」。

**自查**：`php -v` 与 `php-fpm -v`（CLI 与 Web 可能是不同版本）；
对照上表修复版本。若你仍在 PHP ≤8.1 + ThinkPHP 5.1，从
[参考手册](docs/TP5.1到TP8升级参考手册.md) 第一章开始。

> 本仓库不修补 PHP 引擎本身——请先按官方公告升级 PHP 运行时；
> 框架与业务代码的迁移断层，用这里的兼容层与工具解决。

> ⚠️ **定位声明**：兼容层是「过渡期止血」手段，不是长期架构。错误降级类组件会掩盖
> 真实缺陷，请务必保留日志输出并制定收敛退场路线（见 `compat-layer/README.md`）。

## 内容导航

| 目录 | 内容 | 适合谁 |
| --- | --- | --- |
| [`docs/`](docs/) | [TP5.1→TP8 升级参考手册](docs/TP5.1到TP8升级参考手册.md)（七类断层模型 + 决策表 + 十一条红线 + 五层验收）、[部署与灰度回滚通用要点](docs/部署与灰度回滚通用要点.md) | 准备升级 / 正在升级的团队 |
| [`compat-layer/`](compat-layer/) | 15 个兼容层参考实现：全局类别名、错误降级链、缓存归一化驱动、上传兼容包装、自动时间戳、Jump/模板驱动重建、响应宽容中间件、助手函数语义还原 | 需要让 TP5.1 业务代码在 TP8 下先跑起来的项目 |
| [`tools/`](tools/) | 静态扫描器（Collection 误用 / 引用遍历 / 表别名）、dry-run→apply 机械修复脚本、环境自检、vendor 完整性 MD5 核验、[全站链接回归套件](tools/regress/README.md) | 零单测遗留项目的升级回归 |

## 快速开始

**升级自己的项目**：先读参考手册第一章判断「要不要升」，第三章照路线走，第五章遇坑查表。

**复用兼容层**：读 [`compat-layer/README.md`](compat-layer/README.md) 的接线方式
（autoload 时机 / service 注册顺序 / 中间件 / config 指向）与接入前必改项。
命名空间保留 `app\` 结构，按你的项目调整。

**跑迁移工具**：

```bash
python3 tools/scan_collection_misuse.py        # 静态扫描，无副作用
python3 tools/fix_collection_misuse.py --dry-run   # 预览修复
python3 tools/fix_collection_misuse.py --apply     # 确认后写入
php tools/check_env.php                        # 环境自检（退出码可挂门禁）
python3 tools/verify_vendor_integrity.py       # 证明 vendor 未被篡改
```

**全站回归**：见 [`tools/regress/README.md`](tools/regress/README.md) 末尾的参数化清单
（cookie 名 / 登录域 / 用户表 / session 结构需按你的项目配置）。

## 核心方法论（30 秒版）

1. **副本改造**：rsync 复制独立升级，旧库零改动 = 秒级回滚。
2. **先造尺子**：零单测项目先建全站回归工具，再动代码。
3. **配置先行**：配置体系没修好之前不动业务代码。
4. **兼容层收口**：能在驱动层/基类层/中间件层一处解决的，不改几百个调用点。
5. **脚本化修复**：dry-run 逐行复核 → apply，宁少不误。
6. **不 hack vendor**：依赖完整性用内容哈希证明，删依赖要三证据交叉。
7. **五层验收 + 灰度**：静态→组件→链路→业务→环境，5/20/50/100 切流，熔断线先定好。

## 验证状态（诚实声明）

- 全部 PHP 文件通过 `php -l`；Python 脚本通过语法解析；shell 脚本通过 `bash -n`。
- 兼容层行为在原项目（TP 8.1.2 + PHP 8.4）经过全站回归验证。
- **本仓库代码尚未在干净 TP8 骨架上做独立运行时回归**——接入你的项目前请先小范围验证。
- 扫描器基于正则，对跨文件/条件赋值有漏报；深化方向是 nikic/php-parser。

## 许可证

Apache-2.0。其中 `compat-layer/jump/` 四件套与 `compat-layer/view/Think.php`
移植/重建自 ThinkPHP 5.1 框架源码（Copyright (c) 2006-2018 ThinkPHP，
上海顶想信息科技有限公司），详见 [NOTICE](NOTICE)。

## 贡献

欢迎提交：其他 TP5.1→TP8 断层的修复方案、扫描器的 php-parser 重写、
回归套件的登录态构造器接口化、更多真实项目的验证反馈。
提交前请确认不携带任何真实凭证、内网地址与业务数据。

## ⭐ 支持本项目

如果这套兼容层、工具或手册帮你完成了升级、修补了 CVE-2026-109618 / CVE-2026-109619，
或者只是帮你少踩了一个坑——**请给仓库点个 Star**。

Star 不只是鼓励：它能让 GitHub 搜索和推荐把这个仓库排得更靠前，
让下一个被漏洞公告逼着升级、深夜搜「ThinkPHP 5.1 怎么升 TP8」的人更快找到这里。

升级路上遇到新断层？欢迎提 Issue 或 PR，把你的踩坑经验也沉淀进来。