<?php
/**
 * 回归报告生成器：把 result.json 汇总成 Markdown + CSV。
 *
 * 用法: php docs/regress/report.php [--in=result.json] [--md=...] [--csv=...]
 */
require_once __DIR__ . '/lib/env.php';

$opts = getopt('', ['in::', 'md::', 'csv::']);
$inFile  = $opts['in'] ?? __DIR__ . '/result.json';
$mdFile  = $opts['md'] ?? __DIR__ . '/reports/regression-report.md';
$csvFile = $opts['csv'] ?? __DIR__ . '/reports/regression-result.csv';

if (!is_file($inFile)) {
    fwrite(STDERR, "缺少结果文件: {$inFile}\n");
    exit(1);
}
$data    = json_decode(file_get_contents($inFile), true);
$results = $data['results'];

$discover = is_file(__DIR__ . '/urls.json')
    ? json_decode(file_get_contents(__DIR__ . '/urls.json'), true)
    : null;

// ---------------- 分类 ----------------
$groups = [];
foreach ($results as $r) {
    $groups[$r['verdict']][] = $r;
}

$verdictOrder = ['FAIL_500', 'FAIL_RUNTIME', 'FAIL_404', 'FAIL_AUTH', 'FAIL_NET', 'FAIL_OTHER', 'WARN_EMPTY', 'REDIRECT', 'PASS'];
$verdictLabel = [
    'FAIL_500'     => 'HTTP 500 服务端异常',
    'FAIL_RUNTIME' => 'HTTP 200 但页面含 PHP 错误',
    'FAIL_404'     => 'HTTP 404 路由/模板缺失',
    'FAIL_AUTH'    => '被踢出登录（302 → 登录域名）',
    'FAIL_NET'     => '连接失败 / 超时',
    'FAIL_OTHER'   => '其他非 2xx 状态',
    'WARN_EMPTY'   => 'HTTP 200 但响应为空',
    'REDIRECT'     => '业务跳转（302）',
    'PASS'         => '正常',
];

$total = count($results);
$pass  = count($groups['PASS'] ?? []);
$passRate = $total ? round($pass / $total * 100, 1) : 0;

$failCount = 0;
$warnCount = 0;
foreach ($groups as $v => $list) {
    if (str_starts_with($v, 'FAIL')) {
        $failCount += count($list);
    } elseif (str_starts_with($v, 'WARN')) {
        $warnCount += count($list);
    }
}

/**
 * 给失败项定性：区分「代码缺陷」与「无参调用 / 环境导致」
 * 这列直接决定要不要改代码，所以单独成一列而不是塞在摘要里。
 */
function nature_of(array $r): string
{
    $d = $r['detail'];
    if (preg_match("/doesn't exist|Base table or view not found/i", $d)) {
        // 表名以下划线结尾（如 logs_）说明表名是由请求参数拼出来的，
        // 无参调用才会拼成空后缀，真实调用带上参数就正常 —— 属缺参数而非环境缺表
        if (preg_match("/Table &#039;([^&]+)&#039;|Table '([^']+)'/", $d, $m)) {
            $table = $m[1] ?? $m[2] ?? '';
            if (str_ends_with($table, '_')) {
                return '缺参数';
            }
        }
        return '缺表(环境)';
    }
    if ($r['verdict'] === 'FAIL_NET') {
        return '批处理超时';
    }
    if (stripos($d, 'on null') !== false
        || stripos($d, 'Division by zero') !== false
        || stripos($d, 'Incorrect table name') !== false) {
        return '缺参数';
    }
    if (stripos($d, '非法请求') !== false) {
        return '需签名/参数';
    }
    if ($r['verdict'] === 'WARN_EMPTY') {
        return '无数据可返回';
    }
    return '待查';
}

/** 错误特征聚类，同一根因的失败合并成一组 */
function cluster_key(array $r): string
{
    $d = $r['detail'];
    if (preg_match("/Table &#039;([^&]+)&#039; doesn&#039;t exist|Table '([^']+)' doesn't exist/i", $d, $m)) {
        return 'SQL: 表不存在 (' . ($m[1] ?: $m[2]) . ')';
    }
    if (preg_match("/Unknown column '([^']+)'/i", $d, $m)) {
        return 'SQL: 字段不存在 (' . $m[1] . ')';
    }
    if (stripos($d, 'Call to a member function') !== false) {
        return 'PHP: 对 null 调用方法';
    }
    if (stripos($d, 'Too few arguments') !== false) {
        return 'PHP: 参数不足';
    }
    if (stripos($d, 'Array to string') !== false) {
        return 'PHP: 数组当字符串';
    }
    if (stripos($d, 'Serialization of') !== false) {
        return 'PHP: Closure 序列化';
    }
    if (stripos($d, 'variable type error') !== false) {
        return 'PHP: 返回值类型不支持';
    }
    if (stripos($d, '模板') !== false || stripos($d, 'template') !== false) {
        return '模板文件缺失';
    }
    if (preg_match('/\[(DEPRECATED|WARNING|NOTICE)\]/', $d, $m)) {
        return 'PHP: ' . $m[1] . ' 提示';
    }
    if ($r['verdict'] === 'FAIL_NET') {
        return '网络 / 超时';
    }
    if ($r['verdict'] === 'WARN_EMPTY') {
        return '响应为空';
    }
    $s = trim(preg_replace('/\s+/', ' ', $d));
    return $s === '' ? '（无摘要）' : mb_substr($s, 0, 40);
}

$failGroups = [];
$warnGroups = [];
foreach ($groups as $verdict => $list) {
    if (in_array($verdict, ['PASS', 'REDIRECT'], true)) {
        continue;
    }
    foreach ($list as $r) {
        if (str_starts_with($verdict, 'WARN')) {
            $warnGroups[$verdict][cluster_key($r)][] = $r;
        } else {
            $failGroups[$verdict][cluster_key($r)][] = $r;
        }
    }
}

// ---------------- Markdown ----------------
$md = [];
$md[] = '# 全站链接回归测试报告';
$md[] = '';
$md[] = '> 生成时间：' . ($data['run_at'] ?? date('Y-m-d H:i:s'));
$md[] = '> 被测站点：`' . ($data['base_url'] ?? '') . '`　并发：' . ($data['concurrency'] ?? '-') . '　总耗时：' . ($data['elapsed_s'] ?? '-') . 's';
$md[] = '';

// ---- 结论 ----
$md[] = '## 结论';
$md[] = '';
$md[] = '- 实测 **' . $total . '** 个入口，通过 **' . $pass . '** 个，通过率 **' . $passRate . '%**；';
$md[] = '- 代码级失败（`FAIL_*`）**' . $failCount . '** 个，警告 **' . $warnCount . '** 个；';
if ($failCount === 0) {
    $md[] = '- 未发现代码级缺陷，剩余非 200 均为无参调用或测试库环境导致。';
} else {
    $md[] = '- 剩余失败项已按根因聚类并逐条定性，见第三节。';
}
$md[] = '';

// ---- 总览 ----
$md[] = '## 一、总览';
$md[] = '';
$md[] = '| 指标 | 数值 |';
$md[] = '| --- | --- |';
$md[] = '| 实际请求 | ' . $total . ' |';
$md[] = '| 通过 | ' . $pass . ' |';
$md[] = '| 通过率 | **' . $passRate . '%** |';
$md[] = '| 代码级失败（FAIL_*） | ' . $failCount . ' |';
$md[] = '| 警告（WARN_*） | ' . $warnCount . ' |';
$md[] = '';

if ($discover) {
    $md[] = '### 链接覆盖情况';
    $md[] = '';
    $md[] = '源码静态扫描共 **' . $discover['total'] . '** 个可访问入口：';
    $md[] = '';
    $md[] = '| 类型 | 数量 | 本次执行 | 说明 |';
    $md[] = '| --- | --- | --- | --- |';
    $kindDesc = [
        'page'  => '控制器视图方法，返回 HTML 模板',
        'ajax'  => 'Ajax 控制器方法，返回 JSON',
        'route' => '路由文件中显式注册的无参规则',
        'write' => '方法名或方法体命中写库特征，无参调用也可能改数据',
        'param' => '存在必填参数（多为模板辅助方法），无参访问必然报错',
    ];
    foreach ($discover['by_kind'] as $kind => $cnt) {
        $executed = in_array($kind, $data['kinds'] ?? [], true) ? '是' : '否';
        $md[] = '| ' . $kind . ' | ' . $cnt . ' | ' . $executed . ' | ' . ($kindDesc[$kind] ?? '') . ' |';
    }
    $md[] = '';
}

// ---- 结果分类 ----
$md[] = '## 二、结果分类';
$md[] = '';
$md[] = '| 类别 | 含义 | 数量 |';
$md[] = '| --- | --- | --- |';
foreach ($verdictOrder as $v) {
    if (!isset($groups[$v])) {
        continue;
    }
    $md[] = '| `' . $v . '` | ' . $verdictLabel[$v] . ' | ' . count($groups[$v]) . ' |';
}
$md[] = '';

// ---- 失败明细 ----
$md[] = '## 三、失败明细（按根因聚类）';
$md[] = '';
if (!$failGroups) {
    $md[] = '无代码级失败。';
    $md[] = '';
} else {
    foreach ($verdictOrder as $v) {
        if (!isset($failGroups[$v])) {
            continue;
        }
        $md[] = '### ' . $verdictLabel[$v] . '（' . count($groups[$v]) . ' 条）';
        $md[] = '';
        foreach ($failGroups[$v] as $cluster => $list) {
            $md[] = '#### ' . $cluster . ' — ' . count($list) . ' 条';
            $md[] = '';
            $md[] = '| URL | 状态 | 耗时 | 性质 | 摘要 |';
            $md[] = '| --- | --- | --- | --- | --- |';
            foreach ($list as $r) {
                $md[] = '| `' . $r['url'] . '` | ' . $r['status'] . ' | ' . $r['time_ms'] . 'ms | '
                    . nature_of($r) . ' | '
                    . str_replace('|', '\\|', mb_substr($r['detail'], 0, 160)) . ' |';
            }
            $md[] = '';
        }
    }
}

// ---- 警告明细 ----
if ($warnGroups) {
    $md[] = '## 四、警告明细（非失败）';
    $md[] = '';
    $md[] = 'HTTP 200 但响应为空。批处理、`return false` 的接口本来就没有输出，';
    $md[] = '与 TP5.1 行为一致，不算升级回归，但要确认前端能正常处理空响应。';
    $md[] = '';
    $md[] = '| URL | 状态 | 耗时 | 性质 |';
    $md[] = '| --- | --- | --- | --- |';
    foreach ($warnGroups as $v => $clusters) {
        foreach ($clusters as $cluster => $list) {
            foreach ($list as $r) {
                $md[] = '| `' . $r['url'] . '` | ' . $r['status'] . ' | ' . $r['time_ms'] . 'ms | ' . nature_of($r) . ' |';
            }
        }
    }
    $md[] = '';
}

// ---- 慢请求 ----
$slow = $results;
usort($slow, fn($a, $b) => $b['time_ms'] <=> $a['time_ms']);
$slow = array_slice($slow, 0, 20);
$md[] = '## 五、最慢的 20 个请求';
$md[] = '';
$md[] = '| URL | 耗时 | 结果 |';
$md[] = '| --- | --- | --- |';
foreach ($slow as $r) {
    $md[] = '| `' . $r['url'] . '` | ' . $r['time_ms'] . 'ms | ' . $r['verdict'] . ' |';
}
$md[] = '';

// ---- 附录 A：未覆盖入口 ----
if ($discover) {
    $paramRows = array_filter($discover['urls'], fn($r) => $r['kind'] === 'param');
    $writeRows = array_filter($discover['urls'], fn($r) => $r['kind'] === 'write');
    $byCtrl = [];
    foreach ($paramRows as $r) {
        $byCtrl[$r['controller']][] = $r['action'];
    }
    arsort($byCtrl);

    $md[] = '## 六、未纳入自动回归的入口';
    $md[] = '';
    $md[] = '| 类型 | 数量 | 原因 |';
    $md[] = '| --- | --- | --- |';
    $md[] = '| 写操作 | ' . count($writeRows) . ' | 方法名或方法体命中写库特征，GET 无参也可能改数据 |';
    $md[] = '| 需必填参数 | ' . count($paramRows) . ' | 无参访问必然抛 ArgumentCountError，多为模板辅助方法 |';
    $md[] = '';
    $md[] = '### 需必填参数的入口分布（Top 20 控制器）';
    $md[] = '';
    $md[] = '| 控制器 | 数量 | 方法示例 |';
    $md[] = '| --- | --- | --- |';
    $i = 0;
    foreach ($byCtrl as $ctrl => $actions) {
        if (++$i > 20) {
            break;
        }
        $md[] = '| ' . $ctrl . ' | ' . count($actions) . ' | `' . implode('`, `', array_slice($actions, 0, 5)) . '` |';
    }
    $md[] = '';
}

// ---- 附录 B：本轮修复记录 ----
// 建议在此追加「类别 | 根因 | 影响面 | 修复方式」表格，沉淀每轮回归的修复结论。
// 典型根因类别：Closure 序列化 / 返回值类型 / 集合当数组用 / 废弃函数 /
// 方法名迁移 / 参数健壮性。示例见参考手册「七类断层」章节。

file_put_contents($mdFile, implode("\n", $md));

// ---------------- CSV ----------------
$fp = fopen($csvFile, 'w');
fwrite($fp, "\xEF\xBB\xBF"); // BOM：Excel 直接打开不乱码
fputcsv($fp, ['URL', '模块', '类型', 'HTTP状态', '判定', '性质', '耗时ms', '响应字节', '摘要']);
foreach ($results as $r) {
    fputcsv($fp, [
        $r['url'], $r['module'], $r['kind'], $r['status'], $r['verdict'],
        nature_of($r), $r['time_ms'], $r['size'], $r['detail'],
    ]);
}
fclose($fp);

echo "报告: {$mdFile}\n";
echo "明细: {$csvFile}\n";
