#!/usr/bin/env python3
"""批量修复：think-orm 4.x 的 select() 返回 Collection，需显式 ->toArray() 才能传给 array_* 函数。

策略：
  对每个「array_xxx($var) 且 $var 最近赋值为查询语句」的位置，
  在该赋值语句中**最后一个 select() 所在行内**追加 ->toArray()。
  只做行内插入，不合并多行、不移动代码，避免破坏注释与缩进。

用法：
  python3 fix_collection_misuse.py --dry-run
  python3 fix_collection_misuse.py --apply
"""
import re
import pathlib
import sys

FN = re.compile(r'\b(array_column|array_map|array_filter|array_merge|in_array|array_search|array_unique)\s*\(\s*\$([A-Za-z_][A-Za-z0-9_]*)')
QUERY = re.compile(r'(->select\s*\(|::select\s*\(|->paginate\s*\(|->column\s*\(|->selectOrFail\s*\()')
CONV = re.compile(r'(->toArray\s*\(|->all\s*\()')
SELECT = re.compile(r'(->select(?:OrFail)?\s*\([^()]*\)|::select\s*\([^()]*\))')


def collect_assigns(lines):
    """返回 var -> [(stmt_start_line, stmt_end_line, expr, select_line)]"""
    assigns = {}
    buf, buf_start = [], 0
    for i, line in enumerate(lines, 1):
        if not buf:
            buf_start = i
        buf.append(line)
        if ';' in line:
            joined = '\n'.join(buf)
            m = re.search(r'\$([A-Za-z_][A-Za-z0-9_]*)\s*=', joined)
            if m:
                var = m.group(1)
                # `$var =` 实际所在行
                offset = joined[:m.start()].count('\n')
                real_start = buf_start + offset
                # 该语句中最后一个 select 所在行
                matches = list(SELECT.finditer(joined))
                sel_line = None
                if matches:
                    last = matches[-1]
                    sel_line = buf_start + joined[:last.start()].count('\n')
                assigns.setdefault(var, []).append((real_start, i, joined, sel_line))
            buf = []
    return assigns


def collect_hits(text):
    lines = text.splitlines()
    assigns = collect_assigns(lines)
    hits = []
    for i, line in enumerate(lines, 1):
        for m in FN.finditer(line):
            var = m.group(2)
            cand = [a for a in assigns.get(var, []) if a[0] <= i]
            if not cand:
                continue
            start, end, expr, sel_line = cand[-1]
            if QUERY.search(expr) and not CONV.search(expr) and sel_line:
                hits.append((i, var, start, end, sel_line))
    uniq = {}
    for h in hits:
        uniq.setdefault(h[4], h)   # 同一 select 行只改一次
    return sorted(uniq.values(), key=lambda x: x[4])


def fix_file(path, apply=False):
    text = path.read_text(encoding='utf-8', errors='surrogateescape')
    hits = collect_hits(text)
    if not hits:
        return []
    lines = text.splitlines(keepends=True)
    changes = []
    for hit_line, var, start, end, sel_line in sorted(hits, key=lambda x: -x[4]):
        raw = lines[sel_line - 1]
        matches = list(SELECT.finditer(raw))
        if not matches:
            continue
        last = matches[-1]
        if 'toArray' in raw[last.end():last.end() + 14]:
            continue
        new_raw = raw[:last.end()] + '->toArray()' + raw[last.end():]
        changes.append((sel_line, var, hit_line, raw.strip()[:88]))
        if apply:
            lines[sel_line - 1] = new_raw
    if apply and changes:
        path.write_text(''.join(lines), encoding='utf-8', errors='surrogateescape')
    return changes


def main():
    apply = '--apply' in sys.argv
    total = 0
    for p in sorted(pathlib.Path('app').rglob('*.php')):
        ch = fix_file(p, apply)
        if ch:
            print(f'\n### {p}  ({len(ch)} 处)')
            for sel_line, var, hit_line, snippet in ch:
                print(f'  L{sel_line:<5} (供 L{hit_line} 的 ${var} 使用)  {snippet}')
            total += len(ch)
    mode = '已应用' if apply else '预演'
    print(f'\n===== {mode}：共 {total} 处 =====')


main()
