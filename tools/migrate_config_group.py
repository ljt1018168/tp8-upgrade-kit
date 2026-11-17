#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
TP8 配置分组迁移脚本

背景：TP5.1 把所有配置文件的顶层键合并到同一层，config('cas_client_id') 可直接读；
      TP8 改为按文件名分组，必须写 config('app.cas_client_id')。
      裸键在 TP8 下会被当作「一级分组」解析，标量值根本取不出来。

用法：
    python3 migrate_config_group.py --dry-run   # 只看会改哪些地方
    python3 migrate_config_group.py --apply     # 实际写入

只处理 config/app.php 家族（含 development/testing/release/production）
中定义、且被业务代码以裸键形式引用的键。
"""

import argparse
import pathlib
import re
import sys

# config/app.php 家族中定义、且被裸键引用的键。
# ⚠️ 示例清单：请替换为你项目 config/app.php（含各环境目录）里的自定义顶层键；
# 更稳妥的做法是解析配置文件自动提取键名，再与代码中的裸键调用求交集。
KEYS = [
    "cas_client_id",
    "cas_client_secret",
    "cas_redirect_uri",
    "cas_url",
    "app_debug",
    "your_custom_key",
]

GROUP = "app"
ROOT = pathlib.Path(__file__).resolve().parent.parent / "app"

_alt = "|".join(re.escape(k) for k in KEYS)

# config('key')  /  config("key")
CALL_RE = re.compile(r"(\bconfig\s*\(\s*)(['\"])(" + _alt + r")\2")
# Config::get('key')
STATIC_RE = re.compile(r"(\bConfig::get\s*\(\s*)(['\"])(" + _alt + r")\2")


def main() -> int:
    ap = argparse.ArgumentParser()
    g = ap.add_mutually_exclusive_group(required=True)
    g.add_argument("--dry-run", action="store_true")
    g.add_argument("--apply", action="store_true")
    args = ap.parse_args()

    total = 0
    touched = 0

    for path in sorted(ROOT.rglob("*.php")):
        src = path.read_text(encoding="utf-8", errors="surrogateescape")

        new, n1 = CALL_RE.subn(lambda m: f"{m.group(1)}{m.group(2)}{GROUP}.{m.group(3)}{m.group(2)}", src)
        new, n2 = STATIC_RE.subn(lambda m: f"{m.group(1)}{m.group(2)}{GROUP}.{m.group(3)}{m.group(2)}", new)

        n = n1 + n2
        if n == 0:
            continue

        total += n
        touched += 1
        rel = path.relative_to(ROOT.parent)

        if args.dry_run:
            # 逐行打印命中位置，便于人工复核
            for lineno, line in enumerate(src.splitlines(), 1):
                if CALL_RE.search(line) or STATIC_RE.search(line):
                    print(f"{rel}:{lineno}: {line.strip()}")

        if args.apply:
            path.write_text(new, encoding="utf-8", errors="surrogateescape")

    print(f"\n{'[DRY-RUN]' if args.dry_run else '[APPLIED]'} 命中 {total} 处，涉及 {touched} 个文件")
    return 0


if __name__ == "__main__":
    sys.exit(main())
