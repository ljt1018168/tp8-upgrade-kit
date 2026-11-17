# TP8 Upgrade Kit

ThinkPHP 5.1 → 8.x 大版本升级经验共享包：**兼容层参考实现 + 迁移工具集 + 实战参考手册**。

提炼自一个真实业务系统的完整迁移（PHP 7.4.33 + TP 5.1.41 → PHP 8.4 + TP 8.1.2，
252 个控制器、222 个模板、约 4,000 个文件，0 单测 0 CI 起步），
全站回归通过率 95.4%，500 错误从 35 降至 3（均定性为非代码缺陷）。

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