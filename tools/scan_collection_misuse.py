#!/usr/bin/env python3
"""静态分析：找出 array_column / array_map 等接收 think\\Collection 的可疑调用点。

判据（保守，宁少不误）：
  1. 对 array_xxx($var, ...) 中的 $var，回溯同文件内最近一次赋值；
  2. 若该赋值语句里出现 ->select( / ::select( / ->paginate( / ->column( / Db::name(...)->select 等，
     且该语句没有 ->toArray() / ->all()，则判为可疑；
  3. 输出并统计。
"""
import re
import pathlib
import sys

FN = re.compile(r'\b(array_column|array_map|array_filter|array_merge|in_array|array_search|array_unique)\s*\(\s*\$([A-Za-z_][A-Za-z0-9_]*)')
QUERY = re.compile(r'(->select\s*\(|::select\s*\(|->paginate\s*\(|->column\s*\(|->selectOrFail\s*\()')
CONV = re.compile(r'(->toArray\s*\(|->all\s*\(|->toArray\(\)|\barray_values\s*\()')


def analyse(path: pathlib.Path):
    text = path.read_text(encoding='utf-8', errors='surrogateescape')
    lines = text.splitlines()
    # 收集赋值：变量 -> (行号, 语句文本)
    assigns = {}
    stmt_buf = []
    stmt_start = 0
    for i, line in enumerate(lines, 1):
        if not stmt_buf:
            stmt_start = i
        stmt_buf.append(line)
        joined = '\n'.join(stmt_buf)
        if ';' in line:
            m = re.search(r'\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)', joined, re.S)
            if m:
                assigns.setdefault(m.group(1), []).append((stmt_start, m.group(2)))
            stmt_buf = []
    out = []
    for i, line in enumerate(lines, 1):
        for m in FN.finditer(line):
            fn, var = m.group(1), m.group(2)
            cand = [a for a in assigns.get(var, []) if a[0] <= i]
            if not cand:
                continue
            start, expr = cand[-1]
            if QUERY.search(expr) and not CONV.search(expr):
                out.append((i, fn, var, start, expr.replace('\n', ' ')[:100]))
    return out


def main():
    root = sys.argv[1] if len(sys.argv) > 1 else 'app'
    total = 0
    files = 0
    for p in sorted(pathlib.Path(root).rglob('*.php')):
        hits = analyse(p)
        if hits:
            files += 1
            print(f'\n### {p}  ({len(hits)} 处)')
            for line, fn, var, start, expr in hits:
                print(f'  L{line:<5} {fn}(${var})   <- L{start} ${var} = {expr}')
            total += len(hits)
    print(f'\n===== 可疑调用合计 {total} 处，涉及 {files} 个文件 =====')


main()
