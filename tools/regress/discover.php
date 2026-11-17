<?php
/**
 * 链接发现：静态扫描所有控制器的 public 方法，生成可访问 URL 清单。
 *
 * 输出: docs/regress/urls.json
 *   [{ module, controller, action, url, url_raw, params_required, params_total,
 *      kind: page|ajax|write|param, source }]
 *
 * 分类说明（kind）：
 *   page  —— 返回 HTML 视图的 action
 *   ajax  —— 控制器名含 ajax，返回 JSON 接口
 *   write —— 方法名命中写操作关键词，默认跳过（避免污染测试库）
 *   param —— 存在必填参数，无参访问必然报错，默认跳过
 *
 * 用法: php docs/regress/discover.php [--with-write] [--with-param]
 */
require_once __DIR__ . '/lib/env.php';
require __DIR__ . '/../..' . '/vendor/autoload.php';
// 兼容层：把 think\Controller 别名到 app\BaseController（TP8 已移除该基类），
// 不加载它则 Base 及其子类声明时直接 Fatal error。
require __DIR__ . '/../../app/compat.php';

$opts = getopt('', ['with-write', 'with-param', 'out::']);
$outFile = $opts['out'] ?? __DIR__ . '/urls.json';

$root = dirname(__DIR__, 2);

// ---- 1. 写操作关键词（保守清单，命中即跳过） ----
$writeKeywords = [
    'add', 'insert', 'create', 'save', 'update', 'edit', 'modify', 'set', 'reset',
    'del', 'delete', 'remove', 'clear', 'truncate', 'drop',
    'import', 'upload', 'send', 'push', 'sync', 'copy', 'move', 'sort', 'bind',
    'confirm', 'cancel', 'audit', 'submit', 'approve', 'reject', 'refund',
    'change', 'transfer', 'assign', 'grant', 'login', 'logout', 'notify',
];


/** 方法体是否直接写了数据库（insert/update/delete/save/execute 等） */
function body_touches_db_write(\ReflectionMethod $m): bool
{
    static $cache = [];
    $key = $m->getDeclaringClass()->getName() . '::' . $m->getName();
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $file = $m->getFileName();
    $start = $m->getStartLine();
    $end = $m->getEndLine();
    if (!$file || !$start || !$end) {
        return $cache[$key] = false;
    }
    $lines = @file($file);
    if (!$lines) {
        return $cache[$key] = false;
    }
    $body = implode('', array_slice($lines, $start - 1, $end - $start + 1));

    $hit = (bool) preg_match(
        '/->\s*(insert|insertAll|insertGetId|insertGetId|update|delete|save|saveAll|replace|setInc|setDec|inc|dec)\s*\(|Db::\s*(execute|query)\s*\(|::\s*destroy\s*\(/i',
        $body
    );
    return $cache[$key] = $hit;
}

/** 方法名是否像写操作 */
function is_write_action(string $action, array $keywords): bool
{
    $a = strtolower($action);
    foreach ($keywords as $kw) {
        // 驼峰边界也命中：addUser / saveAll / delOne
        if (str_starts_with($a, $kw) || preg_match('/(?:' . $kw . ')$/', $a)) {
            return true;
        }
    }
    return false;
}

// ---- 2. 扫描控制器类 ----
$modules = [];
foreach (glob($root . '/app/*/controller', GLOB_ONLYDIR) as $dir) {
    $modules[] = $dir;
}

$rows = [];
$classErrors = [];

foreach ($modules as $dir) {
    $module = basename(dirname($dir)); // admin / api / cc
    foreach (glob($dir . '/*.php') as $file) {
        $class = 'app\\' . $module . '\\controller\\' . basename($file, '.php');
        if (!class_exists($class)) {
            $classErrors[$class] = 'class_exists 失败';
            continue;
        }
        $rc = new ReflectionClass($class);
        if ($rc->isAbstract()) {
            continue;
        }
        $controller = $rc->getShortName();

        foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            // 只保留本类声明的方法，排除继承来的公共方法
            if ($m->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            $action = $m->getName();
            if (str_starts_with($action, '__') || in_array($action, ['initialize', 'success', 'error', 'redirect', 'fetch', 'display', 'assign', 'engine', 'view'], true)) {
                continue;
            }
            // 抽象/静态方法不构成可访问入口
            if ($m->isStatic() || $m->isAbstract()) {
                continue;
            }

            $required = 0;
            foreach ($m->getParameters() as $p) {
                if (!$p->isOptional()) {
                    $required++;
                }
            }

            $isAjax = stripos($controller, 'ajax') !== false;
            $isWrite = is_write_action($action, $writeKeywords);

            // 方法名看不出来但方法体里真的在写库（例如名字像查询、体内却 insertAll），
            // 只靠命名过滤会误跑，这里补一次方法体静态检测
            if (!$isWrite && body_touches_db_write($m)) {
                $isWrite = true;
            }
            // 演示/测试控制器整体不参与回归
            if (!$isWrite && preg_match('/^(Ajax)?Demo$/i', $controller)) {
                $isWrite = true;
            }

            if ($required > 0) {
                $kind = 'param';
            } elseif ($isWrite) {
                $kind = 'write';
            } elseif ($isAjax) {
                $kind = 'ajax';
            } else {
                $kind = 'page';
            }

            $path = "/{$module}/{$controller}/{$action}";
            $rows[] = [
                'module'     => $module,
                'controller' => $controller,
                'action'     => $action,
                'url'        => $path . '.html',
                'url_raw'    => $path,
                'params_required' => $required,
                'params_total'    => $m->getNumberOfParameters(),
                'kind'       => $kind,
                'source'     => 'controller',
            ];
        }
    }
}

// ---- 3. 补充：路由文件里显式注册的规则 ----
$routeFiles = glob($root . '/route/*.php') ?: [];
foreach (glob($root . '/app/*/route/*.php') as $f) {
    $routeFiles[] = $f;
}
$routeRules = [];
foreach ($routeFiles as $rf) {
    $content = file_get_contents($rf);
    // 多应用路由文件 app/{module}/route/*.php 中的 uri 不含模块前缀，
    // 但实际访问路径是 /{module}/{uri}，这里补回来
    $urlPrefix = '';
    if (preg_match('#/app/([^/]+)/route/#', str_replace(DIRECTORY_SEPARATOR, '/', $rf), $mm)) {
        $urlPrefix = '/' . $mm[1];
    }
    if (preg_match_all("/Route::(?:rule|get|post|any)\(\s*['\"]([^'\"]+)['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $content, $m, PREG_SET_ORDER)) {
        foreach ($m as $one) {
            $uri = $one[1];
            $routeRules[] = [
                'uri'  => $uri,
                'to'   => $one[2],
                'file' => str_replace($root . '/', '', $rf),
                // 应用内的根规则 '/' 对应站点根（多应用命中默认应用），
                // 不能拼成 /admin/ —— 那是 public/admin 静态目录，nginx 直接 403
                'full' => $uri === '/' ? '/' : $urlPrefix . '/' . ltrim($uri, '/'),
            ];
        }
    }
}

// 路由规则里没有占位符的，作为独立链接纳入
foreach ($routeRules as $rule) {
    if (str_contains($rule['uri'], ':') || str_contains($rule['uri'], '<')) {
        continue;
    }
    $uri = $rule['full'];
    $rows[] = [
        'module'     => 'route',
        'controller' => '',
        'action'     => $rule['to'],
        'url'        => $uri,
        'url_raw'    => $uri,
        'params_required' => 0,
        'params_total'    => 0,
        'kind'       => 'route',
        'source'     => $rule['file'],
    ];
}

// ---- 4. 去重 ----
$seen = [];
$uniq = [];
foreach ($rows as $r) {
    if (isset($seen[$r['url']])) {
        continue;
    }
    $seen[$r['url']] = true;
    $uniq[] = $r;
}
usort($uniq, fn($a, $b) => strcmp($a['url'], $b['url']));

$payload = [
    'generated_at' => date('Y-m-d H:i:s'),
    'base_url'     => regress_base_url(),
    'total'        => count($uniq),
    'by_kind'      => array_count_values(array_column($uniq, 'kind')),
    'by_module'    => array_count_values(array_column($uniq, 'module')),
    'class_errors' => $classErrors,
    'route_rules'  => $routeRules,
    'urls'         => $uniq,
];

file_put_contents($outFile, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

if (PHP_SAPI === 'cli') {
    echo "已生成: {$outFile}\n";
    echo "总计 {$payload['total']} 条\n";
    foreach ($payload['by_kind'] as $k => $v) {
        echo "  {$k}: {$v}\n";
    }
    if ($classErrors) {
        echo "类加载失败: " . count($classErrors) . "\n";
        foreach ($classErrors as $c => $e) {
            echo "  - {$c}: {$e}\n";
        }
    }
}
