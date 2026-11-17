#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
修复 think-orm 4.x 下失效的「表名 + 空格 + 别名」写法。

改写规则（统一走 alias()，不依赖 prefix 是否为空）：
    Db::name('t a')            → Db::name('t')->alias('a')
    Db::name('t as a')         → Db::name('t')->alias('a')
    Db::name("$var cx")        → Db::name($var)->alias('cx')
    DB::name(...)              → 保留原大小写前缀

用法：
    python3 fix_name_alias.py --dry-run
    python3 fix_name_alias.py --apply
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SCAN_DIRS = ['app']
EXTS = ('.php',)

# 匹配 Db::name( '...' ) / DB::name("...") / ->name('...')
PAT = re.compile(
    r"""(?P<head>\b(?:Db|DB)::name\s*\(\s*)(?P<q>['"])(?P<arg>[^'"]+?)(?P=q)(?P<tail>\s*\))"""
)


def iter_php_files():
    for d in SCAN_DIRS:
        base = os.path.join(ROOT, d)
        for dirpath, dirnames, filenames in os.walk(base):
            dirnames[:] = [x for x in dirnames if x not in ('vendor', 'runtime', '.git')]
            for fn in filenames:
                if fn.endswith(EXTS):
                    yield os.path.join(dirpath, fn)


def build_replacement(m):
    arg = m.group('arg').strip()
    if ' ' not in arg:
        return None
    parts = arg.split()
    if len(parts) == 2:
        table, alias = parts
    elif len(parts) == 3 and parts[1].lower() == 'as':
        table, alias = parts[0], parts[2]
    else:
        return None

    head = m.group('head')
    if table.startswith('$'):
        # 变量插值："$table cx" → name($table)->alias('cx')
        new_head = head.replace('("', '(')  # 不会走到，占位
        return f"{head}{table})->alias('{alias}')"
    return f"{head}'{table}')->alias('{alias}')"


def main():
    dry = '--apply' not in sys.argv
    changes = []

    for path in iter_php_files():
        try:
            with open(path, encoding='utf-8') as f:
                src = f.read()
        except UnicodeDecodeError:
            continue

        lines = src.split('\n')
        out_lines = []
        for i, line in enumerate(lines, 1):
            stripped = line.strip()
            if stripped.startswith('//') or stripped.startswith('*'):
                out_lines.append(line)
                continue

            def repl(m):
                new = build_replacement(m)
                if new is None:
                    return m.group(0)
                changes.append((os.path.relpath(path, ROOT), i, m.group(0), new))
                return new

            out_lines.append(PAT.sub(repl, line))

        new_src = '\n'.join(out_lines)
        if new_src != src and not dry:
            with open(path, 'w', encoding='utf-8') as f:
                f.write(new_src)

    if not changes:
        print('无可改写项')
        return 0

    print(f'{"[DRY-RUN] " if dry else ""}共 {len(changes)} 处改写：\n')
    cur = None
    for rel, ln, old, new in changes:
        if rel != cur:
            print(f'--- {rel} ---')
            cur = rel
        print(f'  L{ln:<5} {old}')
        print(f'    →    {new}')
    return 0


if __name__ == '__main__':
    sys.exit(main())
