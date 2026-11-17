<?php
/**
 * 回归测试公共环境：解析项目 .env（INI 格式），不依赖框架启动。
 * 注意：TP8 项目的 .env 与 TP5.1 完全兼容，仍为 INI 分组格式。
 */

function regress_env(?string $file = null): array
{
    static $cache = [];
    $file = $file ?: dirname(__DIR__, 3) . '/.env';
    if (!isset($cache[$file])) {
        if (!is_file($file)) {
            throw new RuntimeException(".env 不存在: {$file}");
        }
        $data = parse_ini_file($file, true, INI_SCANNER_RAW);
        if ($data === false) {
            throw new RuntimeException(".env 解析失败: {$file}");
        }
        $cache[$file] = $data;
    }
    return $cache[$file];
}

function regress_cfg(string $section, string $key, $default = null)
{
    $env = regress_env();
    $section = strtoupper($section);
    if (!isset($env[$section][$key])) {
        return $default;
    }
    return trim((string)$env[$section][$key], " \t\n\r\0\x0B'\"");
}

/** 测试库连接（mysqli） */
function regress_db(): mysqli
{
    static $db = null;
    if ($db instanceof mysqli) {
        return $db;
    }
    $db = new mysqli(
        regress_cfg('CONFIG_DATABASE', 'HOSTNAME'),
        regress_cfg('CONFIG_DATABASE', 'USERNAME'),
        regress_cfg('CONFIG_DATABASE', 'PASSWORD'),
        regress_cfg('CONFIG_DATABASE', 'DATABASE'),
        (int)regress_cfg('CONFIG_DATABASE', 'HOSTPORT', 3306)
    );
    if ($db->connect_error) {
        throw new RuntimeException('DB 连接失败: ' . $db->connect_error);
    }
    $db->set_charset('utf8mb4');
    return $db;
}

/** session 用的缓存 redis（与 config/cache.php 的 redis store 对齐） */
function regress_redis(): Redis
{
    static $r = null;
    if ($r instanceof Redis) {
        return $r;
    }
    $r = new Redis();
    $host = regress_cfg('CONFIG_CACHE', 'HOSTNAME', '127.0.0.1');
    $port = (int)regress_cfg('CONFIG_CACHE', 'PORT', 6379);
    if (!$r->connect($host, $port, 3)) {
        throw new RuntimeException("Redis 连接失败: {$host}:{$port}");
    }
    $pwd = regress_cfg('CONFIG_CACHE', 'PASSWORD', '');
    if ($pwd !== '') {
        $r->auth($pwd);
    }
    $r->select((int)regress_cfg('CONFIG_CACHE', 'SELECT', 0));
    return $r;
}

/** 站点基址（默认取 CAS 回调地址，即本地 vhost；按项目修改默认值） */
function regress_base_url(): string
{
    return rtrim(regress_cfg('CONFIG_CAS', 'CAS_REDIRECT_URI', 'https://app.example.test'), '/');
}

/** 会话 cookie 名（= 目标项目 config/session.php 的 name），可在 .env [REGRESS] 段配置 */
function regress_cookie_name(): string
{
    return regress_cfg('REGRESS', 'COOKIE_NAME', 'PHPSESSID');
}

/** TP8 session 的 redis key：cache store prefix + session prefix + sid */
function regress_session_key(string $sid): string
{
    $storePrefix = regress_cfg('CONFIG_CACHE', 'PREFIX', '');
    $sessPrefix  = 'think'; // config/session.php 的 prefix
    return $storePrefix . $sessPrefix . $sid;
}
