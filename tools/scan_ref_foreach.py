#!/usr/bin/env python3
"""静态分析：找出 TP5.1→TP8 升级后「引用遍历」失效的调用点。

背景（TP8 断层）：
  TP5.1 的 select()/paginate() 受 config/database.php 的 resultset_type=array 影响，
  返回**数组**；TP8 的 think-orm 完全不认这个配置，一律返回 Collection / Paginator。
  两者的迭代器都是 `new ArrayIterator($this->items)`，持有的是数组**副本**，
  因此 `foreach ($res as &$val)` 对 $val 的修改只落在副本上，遍历结束即丢失。
  另外 Model 被当作「数组容器」回写关联数据时，varchar 字段会走类型转换，
  赋数组直接抛 `Array to string conversion`。

两类可疑点：
  A. 引用遍历的来源是查询结果（select/paginate/column），且该语句没有 ->toArray()/->all()
     → 循环内的赋值全部丢失（含给数组元素赋关联数据、格式化字段等）。
  B. 引用遍历来源已是数组，但循环内把**数组/集合**写回 Model 属性
     → 触发类型转换异常（Array to string conversion）。

用法：
  python3 docs/scan_ref_foreach.py            # 全量扫描
  python3 docs/scan_ref_foreach.py app/admin  # 只扫指定目录
退出码：发现可疑点为 1，干净为 0（可挂到流水线门禁）。
"""
import re
import pathlib
import sys

REF_FOREACH = re.compile(r'foreach\s*\(\s*(\$[A-Za-z_][A-Za-z0-9_]*)\s+as\s+&\s*\$')
ASSIGN = re.compile(r'^\s*(\$[A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$', re.S)
QUERY = re.compile(r'(->select\s*\(|::select\s*\(|->paginate\s*\(|->column\s*\()')
CONV = re.compile(r'(->toArray\s*\(\s*\)|->all\s*\(\s*\))')


def statements(lines):
    """把多行语句拼成 (起始行, 结束行, 全文, 左侧变量, 右侧表达式)。"""
    out, buf, start = [], [], 0
    for i, line in enumerate(lines, 1):
        code = re.sub(r'//.*$', '', line)
        if not buf:
            start = i
        buf.append(code)
        stripped = code.strip()
        if ';' in code:
            text = '\n'.join(buf)
            m = ASSIGN.match(text.strip())
            if m:
                out.append((start, i, text, m.group(1), m.group(2)))
            buf = []
        elif stripped in ('{', '}') or stripped.endswith('{') or stripped.endswith('}'):
            # 单独的块边界不参与语句拼接，否则会污染下一条赋值语句的解析
            buf = []
    return out


def scan(path):
    lines = path.read_text(encoding='utf-8', errors='surrogateescape').splitlines()
    stmts = statements(lines)
    findings = []
    for idx, line in enumerate(lines, 1):
        m = REF_FOREACH.search(line)
        if not m:
            continue
        var = m.group(1)
        cand = [s for s in stmts if s[0] <= idx and s[3] == var]
        if not cand:
            findings.append(('B?', idx, var, '', '来源未在同文件内赋值，需人工确认'))
            continue
        start, end, text, _, rhs = cand[-1]
        if not QUERY.search(rhs):
            continue
        is_paginate = 'paginate' in rhs
        if CONV.search(rhs):
            continue
        kind = 'A-paginate' if is_paginate else 'A'
        findings.append((kind, idx, var, f'L{start}-{end}', rhs.strip().split('\n')[-1][:110]))
    return findings


def main():
    roots = [pathlib.Path(a) for a in sys.argv[1:]] or [pathlib.Path('app')]
    total = 0
    for root in roots:
        files = [root] if root.is_file() else sorted(root.rglob('*.php'))
        for f in files:
            try:
                hits = scan(f)
            except Exception as e:  # noqa: BLE001
                print(f'[skip] {f}: {e}')
                continue
            if not hits:
                continue
            print(f'\n{f}')
            for kind, line, var, where, expr in hits:
                total += 1
                print(f'  {kind:12s} L{line:<6d} [{var}] {where}')
                if expr:
                    print(f'                 {expr}')
    print(f'\n合计可疑点：{total}')
    return 1 if total else 0


if __name__ == '__main__':
    sys.exit(main())
