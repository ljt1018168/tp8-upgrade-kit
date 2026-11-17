#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
扫描 think-orm 4.x（TP8）下「表名+别名」相关的问题写法（完整版）。

三类问题：
  A. Db::name('t a')        ❌ 空格被吃掉 → 表名 t_a
     Db::name('t as a')     ❌ 同上 → t_as_a
  B. Db::table('t as a')    ❌ 把 as 当别名 → FROM `t` `as`
     Db::name('t as a')->alias() 同 A
  C. join('t as a')         ❌ 把 as 当别名 → JOIN `t` `as`
     （join('t a') 两段式是 ✅ 正常，不用改）

正常、不要改：
  Db::table('t a')          ✅
  ->alias('a')              ✅
  join('t a')               ✅
  join(['t' => 'a'], ...)   ✅
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SCAN_DIRS = ['app']
EXTS = ('.php',)

# A: Db::name('...') / DB::name("...")
PAT_NAME = re.compile(r"""\b(?:Db|DB)::name\s*\(\s*(?P<q>['"])(?P<arg>[^'"]+?)(?P=q)""")
# B: Db::table('...')
PAT_TABLE = re.compile(r"""\b(?:Db|DB)::table\s*\(\s*(?P<q>['"])(?P<arg>[^'"]+?)(?P=q)""")
# C: ->join('...') / leftJoin / rightJoin 等
PAT_JOIN = re.compile(
    r"""->\s*(?P<fn>join|leftJoin|rightJoin|innerJoin|fullJoin)\s*\(\s*(?P<q>['"])(?P<arg>[^'"]+?)(?P=q)""",
    re.I,
)


def iter_php_files():
    for d in SCAN_DIRS:
        for dirpath, dirnames, filenames in os.walk(os.path.join(ROOT, d)):
            dirnames[:] = [x for x in dirnames if x not in ('vendor', 'runtime', '.git')]
            for fn in filenames:
                if fn.endswith(EXTS):
                    yield os.path.join(dirpath, fn)


def classify(kind, arg):
    """返回 (是否问题, 说明, 建议改写)"""
    s = arg.strip()
    # 子查询 / 函数调用：跳过
    if '(' in s or '$' in s and ' ' not in s:
        return (None, '', '')

    parts = s.split()
    has_as = len(parts) == 3 and parts[1].lower() == 'as'

    if kind == 'name':
        if len(parts) == 2:
            return (True, 'A 空格别名被当表名', f"Db::name('{parts[0]}')->alias('{parts[1]}')")
        if has_as:
            return (True, 'A as 别名被当表名', f"Db::name('{parts[0]}')->alias('{parts[2]}')")
    elif kind == 'table':
        if has_as:
            return (True, 'B table 把 as 当别名', f"Db::table('{parts[0]} {parts[2]}')")
    elif kind == 'join':
        if has_as:
            return (True, 'C join 把 as 当别名', f"join('{parts[0]} {parts[2]}', ...)")
    return (False, '', '')


def main():
    hits = []
    for path in iter_php_files():
        try:
            lines = open(path, encoding='utf-8').readlines()
        except UnicodeDecodeError:
            continue
        for i, line in enumerate(lines, 1):
            st = line.strip()
            if st.startswith('//') or st.startswith('*'):
                continue
            for kind, pat in (('name', PAT_NAME), ('table', PAT_TABLE), ('join', PAT_JOIN)):
                for m in pat.finditer(line):
                    bad, why, sugg = classify(kind, m.group('arg'))
                    if bad:
                        hits.append((os.path.relpath(path, ROOT), i, why, m.group(0), sugg))

    if not hits:
        print('未发现问题写法 ✓')
        return 0

    print(f'共 {len(hits)} 处：\n')
    cur = None
    for rel, ln, why, raw, sugg in hits:
        if rel != cur:
            print(f'--- {rel} ---')
            cur = rel
        print(f'  L{ln:<5} [{why}]  {raw}')
        print(f'         →  {sugg}')
    return 1


if __name__ == '__main__':
    sys.exit(main())
