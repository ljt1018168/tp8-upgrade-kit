<?php
/**
 * 环境要求自检脚本 —— 与《系统版本要求.md》逐条对应
 *
 * 用法：php docs/check_env.php
 * 退出码：0 = 全部通过；1 = 存在不满足项
 */

$root = dirname(__DIR__);
$pass = [];
$fail = [];
$warn = [];

function ok($label, $detail = '')   { global $pass; $pass[] = "  [PASS] $label" . ($detail ? "  ($detail)" : ''); }
function ng($label, $detail = '')   { global $fail; $fail[] = "  [FAIL] $label" . ($detail ? "  ($detail)" : ''); }
function wk($label, $detail = '')   { global $warn; $warn[] = "  [WARN] $label" . ($detail ? "  ($detail)" : ''); }

echo "=== 1. PHP 版本（要求 >=8.1.0 且 <8.6.0）===\n";
$cur = PHP_VERSION;
$okMin = version_compare($cur, '8.1.0', '>=');
$okMax = version_compare($cur, '8.6.0', '<');
if ($okMin && $okMax) {
    ok("PHP {$cur} 在支持范围内");
} else {
    ng("PHP {$cur} 不在 [8.1.0, 8.6.0) 范围内");
}

echo "\n=== 2. 必需扩展 ===\n";
$required = ['curl','gd','iconv','json','mbstring','zip','zlib','ctype','dom','fileinfo',
             'libxml','simplexml','xml','xmlreader','xmlwriter','pdo_mysql','redis','openssl'];
$missing = [];
foreach ($required as $ext) {
    if (!extension_loaded($ext)) {
        $missing[] = $ext;
    }
}
if ($missing) {
    ng('缺少扩展: ' . implode(', ', $missing));
} else {
    ok('全部 ' . count($required) . ' 个必需扩展已加载');
}
if (php_sapi_name() === 'cli') {
    wk('opcache 状态需在 FPM 下确认（CLI 通常不启用 opcache.enable_cli）');
} elseif (ini_get('opcache.enable')) {
    ok('opcache 已开启');
} else {
    wk('opcache 未开启（生产建议开启）');
}

echo "\n=== 3. 目录可写性 ===\n";
$runtime = $root . '/runtime';
foreach (['', '/log', '/export', '/cache', '/temp'] as $sub) {
    $p = $runtime . $sub;
    if (!is_dir($p)) { wk("目录不存在: runtime{$sub}"); continue; }
    if (is_writable($p)) { ok("runtime{$sub} 可写"); } else { ng("runtime{$sub} 不可写"); }
}
foreach (['/public/download', '/public/uploads/download'] as $sub) {
    $p = $root . $sub;
    if (!is_dir($p)) { ng("目录不存在: {$sub}" . ($sub === '/public/uploads/download' ? '（需预先创建）' : '')); continue; }
    if (is_writable($p)) { ok("{$sub} 可写"); } else { ng("{$sub} 不可写"); }
}

echo "\n=== 4. 数据库（sql_mode / 排序规则 / 版本）===\n";
$envFile = $root . '/.env';
if (!is_file($envFile)) {
    ng('.env 不存在');
} else {
    $cfg = parse_ini_file($envFile, true);
    $db = $cfg['CONFIG_DATABASE'] ?? [];
    try {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $db['HOSTNAME'], $db['HOSTPORT'] ?? 3306, $db['DATABASE'] ?? '');
        $pdo = new PDO($dsn, $db['USERNAME'], $db['PASSWORD'], [
            PDO::ATTR_TIMEOUT => 8, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
        if (version_compare($ver, '5.7.0', '>=')) { ok("MySQL {$ver} 满足 >=5.7"); }
        else { ng("MySQL {$ver} 低于 5.7"); }

        $mode = $pdo->query('SELECT @@sql_mode')->fetchColumn();
        if (stripos($mode, 'ONLY_FULL_GROUP_BY') !== false) {
            ng("sql_mode 含 ONLY_FULL_GROUP_BY", $mode);
        } else {
            ok('sql_mode 不含 ONLY_FULL_GROUP_BY', $mode ?: '(空)');
        }
        if (stripos($mode, 'STRICT_TRANS_TABLES') !== false) {
            wk('sql_mode 含 STRICT_TRANS_TABLES（历史 0000-00-00 数据可能写入失败）', $mode);
        }

        $coll = $pdo->query('SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()')->fetchColumn();
        ok("库默认排序规则: {$coll}");
        $dist = $pdo->query('SELECT COLLATION_NAME, COUNT(*) FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND COLLATION_NAME IS NOT NULL
                             GROUP BY COLLATION_NAME ORDER BY 2 DESC')->fetchAll(PDO::FETCH_NUM);
        if (count($dist) > 2) {
            wk('字段排序规则为混合分布（' . count($dist) . ' 种），迁移新库须原样保留');
        }
    } catch (Throwable $e) {
        ng('数据库连接失败', $e->getMessage());
    }

    echo "\n=== 5. Redis ===\n";
    $rc = $cfg['CONFIG_CACHE'] ?? [];
    if ($rc && extension_loaded('redis')) {
        try {
            $r = new Redis();
            $r->connect($rc['HOSTNAME'], (int)($rc['PORT'] ?? 6379), 5);
            if (!empty($rc['PASSWORD'])) { $r->auth($rc['PASSWORD']); }
            $info = $r->info('server');
            $rv = $info['redis_version'] ?? 'unknown';
            if (version_compare($rv, '3.0.0', '>=')) { ok("Redis {$rv} 满足 >=3.0"); }
            else { ng("Redis {$rv} 低于 3.0"); }
            $r->close();
        } catch (Throwable $e) {
            ng('Redis 连接失败', $e->getMessage());
        }
    } else {
        ng('缺少 redis 扩展或 CONFIG_CACHE 配置');
    }
}

echo "\n=== 6. composer ===\n";
exec('composer --version 2>/dev/null', $out);
$cv = $out[0] ?? '';
if (preg_match('/(\d+\.\d+\.\d+)/', $cv, $m)) {
    if (version_compare($m[1], '2.2.0', '>=')) { ok("composer {$m[1]} 满足 >=2.2"); }
    else { ng("composer {$m[1]} 低于 2.2"); }
} else {
    wk('未检测到 composer CLI（部署机可不装，仅构建机需要）');
}

echo "\n" . implode("\n", array_merge($pass, $warn, $fail)) . "\n";
printf("\n=== 汇总：PASS %d / WARN %d / FAIL %d ===\n", count($pass), count($warn), count($fail));
exit(empty($fail) ? 0 : 1);
