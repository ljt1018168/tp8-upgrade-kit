#!/usr/bin/env python3
"""TP5.1 -> TP8 批量代码改造（第二批：Request::module -> 应用名）。

TP8 的 think\\Request 已无 module() 方法，多应用模式下应用名统一
通过 think\\Http::getName() 获取，等价写法 app('http')->getName()。
"""
import os
import re
import sys
from pathlib import Path

# 默认按「脚本放在项目 docs/ 下」推导项目根；可用环境变量 TP_ROOT 覆盖
ROOT = Path(os.environ.get("TP_ROOT", Path(__file__).resolve().parent.parent))
APP = ROOT / "app"
DRY = "--dry-run" in sys.argv

# Request::module() 与 $request->module() 统一替换
PATTERNS = [
    (re.compile(r"\bRequest::module\(\)"), "app('http')->getName()"),
    (re.compile(r"\$[A-Za-z_][A-Za-z0-9_]*->module\(\)"), "app('http')->getName()"),
]


def main():
    total = 0
    changed_files = 0
    for f in APP.rglob("*.php"):
        text = f.read_text(encoding="utf-8", errors="surrogateescape")
        original = text
        count = 0
        for pat, repl in PATTERNS:
            text, n = pat.subn(lambda m, r=repl: r, text)
            count += n
        if text != original:
            changed_files += 1
            total += count
            if not DRY:
                f.write_text(text, encoding="utf-8", errors="surrogateescape")
    print(f"module() 替换：{total} 处，涉及 {changed_files} 个文件")
    if DRY:
        print("(dry-run，未写入)")


if __name__ == "__main__":
    main()
