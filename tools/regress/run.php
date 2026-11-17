<?php
/**
 * 回归执行器：对 discover.php 产出的链接清单发起并发 HTTP 请求并归类。
 *
 * 判定维度（不看业务 code，只看「服务是否健康」）：
 *   200 + 无 PHP 错误标记  → PASS
 *   200 + 有 PHP 错误标记  → FAIL_RUNTIME（页面里抛了 Notice/Warning/Fatal/异常页）
 *   200 + body 为空        → WARN_EMPTY
 *   302                    → REDIRECT（target 命中登录域名视为被踢出登录，--authhost 配置）
 *   404                    → FAIL_404
 *   500                    → FAIL_500
 *   超时/连接错误          → FAIL_NET
 *
 * 用法:
 *   php docs/regress/run.php [--kinds=page,ajax,route] [--concurrency=6]
 *                               [--timeout=30] [--limit=0] [--out=result.json]
 */
require_once __DIR__ . '/lib/env.php';

$opts = getopt('', ['kinds::', 'concurrency::', 'timeout::', 'limit::', 'out::', 'sid::', 'base::', 'cookie::', 'authhost::']);
$kinds = array_filter(explode(',', $opts['kinds'] ?? 'page,ajax,route'));
$concurrency = max(1, (int)($opts['concurrency'] ?? 6));
$timeout = max(5, (int)($opts['timeout'] ?? 30));
$limit = max(0, (int)($opts['limit'] ?? 0));
$outFile = $opts['out'] ?? __DIR__ . '/result.json';

$urlsFile = __DIR__ . '/urls.json';
if (!is_file($urlsFile)) {
    fwrite(STDERR, "缺少 urls.json，请先执行 discover.php\n");
    exit(1);
}
$discover = json_decode(file_get_contents($urlsFile), true);

// ---- 准备登录态 ----
$sid = $opts['sid'] ?? '';
if ($sid === '') {
    $sess = require_once __DIR__ . '/bootstrap_session.php';
    $sid = $sess['sid'];
}
// cookie 名 = 目标项目 config/session.php 的 name，可用 --cookie 覆盖
$cookieName = $opts['cookie'] ?? regress_cookie_name();
// 登录跳转域名：302 目标命中它即判 FAIL_AUTH；留空则不做该判定
$authHost = $opts['authhost'] ?? regress_cfg('REGRESS', 'AUTH_HOST', '');
// --base 可覆盖被测站点，用于「同一批链接在不同运行时/runtime 下对比」
// 例：--base=http://127.0.0.1:8089（PHP 8.4 内置 server）
$base = rtrim($opts['base'] ?? regress_base_url(), '/');

// ---- 待测清单 ----
$targets = array_values(array_filter($discover['urls'], fn($r) => in_array($r['kind'], $kinds, true)));
if ($limit > 0) {
    $targets = array_slice($targets, 0, $limit);
}

fwrite(STDERR, sprintf("待测 %d 条 | 并发 %d | 超时 %ds | base=%s\n", count($targets), $concurrency, $timeout, $base));

/** PHP 错误特征（按严重度排序返回首个命中） */
function detect_php_error(string $body): ?array
{
    $patterns = [
        'FATAL'      => '/Fatal error|系统发生错误|Uncaught\s+(Error|Exception|TypeError|ArgumentCountError)/i',
        'EXCEPTION'  => '/class="exception-|think\\\\exception\\\\|ErrorException in /i',
        'DEPRECATED' => '/(?:^|>|\s)(Deprecated|E_DEPRECATED)[:\s]/i',
        'WARNING'    => '/(?:^|>|\s)(Warning|E_WARNING)[:\s]/i',
        'NOTICE'     => '/(?:^|>|\s)(Notice|E_NOTICE)[:\s]/i',
    ];
    foreach ($patterns as $level => $re) {
        if (preg_match($re, $body, $m)) {
            return ['level' => $level, 'hit' => $m[0]];
        }
    }
    return null;
}

/** 从错误页里抽取一句可读摘要 */
function extract_error_summary(string $body): string
{
    // TP 错误页：<h1>标题</h1> 或 类名 + 文件行号
    if (preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $body, $m)) {
        $t = trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
        if ($t !== '') {
            return mb_substr($t, 0, 200);
        }
    }
    if (preg_match('/([A-Za-z0-9_\\\\]*(?:Error|Exception)[A-Za-z0-9_\\\\]*)\s+in\s+([^\s<]+)\s+line\s+(\d+)/i', $body, $m)) {
        return $m[1] . ' in ' . basename($m[2]) . ':' . $m[3];
    }
    if (preg_match('/(Deprecated|Warning|Notice|Fatal error)\s*:\s*([^<\n]{0,160})/i', $body, $m)) {
        return trim($m[1] . ': ' . $m[2]);
    }
    return '';
}

$results = [];
$mh = curl_multi_init();
$queue = $targets;
$active = [];

$startAll = microtime(true);

// curl multi 需要持有 body 引用，用统一容器
$buffers = [];

$fill = function () use (&$queue, &$active, &$buffers, $mh, $concurrency, $base, $cookieName, $sid, $timeout, $authHost) {
    while (count($active) < $concurrency && $queue) {
        $t = array_shift($queue);
        $ch = curl_init();
        $buffers[spl_object_id($ch)] = '';
        $key = spl_object_id($ch);
        curl_setopt_array($ch, [
            CURLOPT_URL            => $base . $t['url'],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_COOKIE         => $cookieName . '=' . $sid,
            CURLOPT_USERAGENT      => 'tp-regress/1.0',
            CURLOPT_HTTPHEADER     => ['X-Requested-With: XMLHttpRequest'],
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$buffers, $key) {
                $len = strlen($chunk);
                if (strlen($buffers[$key]) < 65536) {
                    $buffers[$key] .= substr($chunk, 0, 65536 - strlen($buffers[$key]));
                }
                return $len;
            },
        ]);
        curl_multi_add_handle($mh, $ch);
        $active[$key] = ['ch' => $ch, 'target' => $t];
    }
};

$fill();
$done = 0;
$total = count($targets);

while ($active) {
    do {
        $status = curl_multi_exec($mh, $running);
    } while ($status === CURLM_CALL_MULTI_PERFORM);

    while ($info = curl_multi_info_read($mh)) {
        $ch = $info['handle'];
        $key = spl_object_id($ch);
        $t = $active[$key]['target'] ?? null;
        if (!$t) {
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            continue;
        }

        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $timeMs = (int)round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
        $size = (int)curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        $redirect = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $body = $buffers[$key] ?? '';

        if ($errno !== 0) {
            $verdict = 'FAIL_NET';
            $detail = $err ?: ('curl errno ' . $errno);
        } elseif ($code === 0) {
            $verdict = 'FAIL_NET';
            $detail = 'no response';
        } elseif ($code === 500) {
            $verdict = 'FAIL_500';
            $detail = extract_error_summary($body);
        } elseif ($code === 404) {
            $verdict = 'FAIL_404';
            $detail = '模板/路由不存在';
        } elseif ($code === 302 || $code === 301) {
            $verdict = 'REDIRECT';
            $detail = $redirect !== '' ? $redirect : '(no location)';
            if ($authHost !== '' && stripos($detail, $authHost) !== false) {
                $verdict = 'FAIL_AUTH';
            }
        } elseif ($code >= 200 && $code < 300) {
            $phpErr = detect_php_error($body);
            if ($phpErr) {
                $verdict = 'FAIL_RUNTIME';
                $detail = '[' . $phpErr['level'] . '] ' . extract_error_summary($body);
            } elseif ($size === 0) {
                $verdict = 'WARN_EMPTY';
                $detail = 'HTTP 200 但响应为空';
            } else {
                $verdict = 'PASS';
                $detail = '';
            }
        } else {
            $verdict = 'FAIL_OTHER';
            $detail = 'HTTP ' . $code;
        }

        $results[] = [
            'url'      => $t['url'],
            'method'   => $t['controller'] . '/' . $t['action'],
            'kind'     => $t['kind'],
            'module'   => $t['module'],
            'status'   => $code,
            'verdict'  => $verdict,
            'time_ms'  => $timeMs,
            'size'     => $size,
            'detail'   => mb_substr($detail, 0, 300),
        ];

        unset($active[$key], $buffers[$key]);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        $done++;

        if ($done % 25 === 0 || $done === $total) {
            fwrite(STDERR, sprintf("  %d/%d\n", $done, $total));
        }
    }

    if ($active) {
        curl_multi_select($mh, 0.5);
    }
    $fill();
}

curl_multi_close($mh);

$elapsed = round(microtime(true) - $startAll, 1);

// ---- 汇总 ----
$byVerdict = [];
foreach ($results as $r) {
    $byVerdict[$r['verdict']] = ($byVerdict[$r['verdict']] ?? 0) + 1;
}
ksort($byVerdict);
usort($results, fn($a, $b) => [$a['verdict'], $a['url']] <=> [$b['verdict'], $b['url']]);

$payload = [
    'run_at'     => date('Y-m-d H:i:s'),
    'base_url'   => $base,
    'sid'        => $sid,
    'kinds'      => $kinds,
    'concurrency' => $concurrency,
    'elapsed_s'  => $elapsed,
    'total'      => count($results),
    'by_verdict' => $byVerdict,
    'pass_rate'  => count($results) ? round(($byVerdict['PASS'] ?? 0) / count($results) * 100, 1) : 0,
    'results'    => $results,
];
file_put_contents($outFile, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo "\n==== 回归结果 ====\n";
echo "总请求: {$payload['total']}  耗时: {$elapsed}s  通过率: {$payload['pass_rate']}%\n";
foreach ($byVerdict as $k => $v) {
    printf("  %-14s %d\n", $k, $v);
}
echo "明细: {$outFile}\n";
