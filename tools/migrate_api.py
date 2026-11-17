#!/usr/bin/env python3
"""TP5.1 -> TP8 批量代码改造：Db 门面等机械替换。

用法：python3 migrate_api.py [--dry-run]
"""
import os
import re
import sys
from pathlib import Path

# 默认按「脚本放在项目 docs/ 下」推导项目根；可用环境变量 TP_ROOT 覆盖
ROOT = Path(os.environ.get("TP_ROOT", Path(__file__).resolve().parent.parent))
APP = ROOT / "app"
DRY = "--dry-run" in sys.argv

# (说明, 正则, 替换) —— 仅做等价语义替换，不改变业务逻辑
RULES = [
    # 1. think\Db 静态调用在 TP8 不存在，统一走门面（调用点无需改动）
    ("Db门面", re.compile(r"^use\s+think\\Db\s*;", re.M), "use think\\facade\\Db;"),
    # 2. think\Request 在 TP8 仍存在，但门面更稳定（保持原样，仅统一已存在的写法）
    #    此处不替换，避免过度改动
    # 3. utf8_encode 在 PHP 8.2 弃用，等价替换为 mb_convert_encoding
    ("utf8_encode", re.compile(r"\butf8_encode\("), "mb_convert_encoding(__ARG__, 'UTF-8', 'ISO-8859-1')"),
]


def files():
    for p in APP.rglob("*.php"):
        yield p


def main():
    total = 0
    changed_files = 0
    for f in files():
        text = f.read_text(encoding="utf-8", errors="surrogateescape")
        original = text
        # 规则1：Db 门面（用函数式替换，避免 backslash 被当作正则转义）
        text, n1 = RULES[0][1].subn(lambda m: "use think\\facade\\Db;", text)
        if text != original:
            changed_files += 1
            total += n1
            if not DRY:
                f.write_text(text, encoding="utf-8", errors="surrogateescape")
    print(f"Db 门面替换：{total} 处，涉及 {changed_files} 个文件")
    if DRY:
        print("(dry-run，未写入)")


if __name__ == "__main__":
    main()
