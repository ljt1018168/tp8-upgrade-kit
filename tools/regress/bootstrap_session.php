<?php
/**
 * 生成回归测试专用登录态 session（TP8 版，通用示例）。
 *
 * ⚠️ 本文件是示例驱动：用户表 / 权限表 / session 字段结构均需按你的项目改造。
 * 核心机制（双层 serialize、32 位 sid、cache 前缀拼 key）是 TP8 通用的，可直接复用。
 *
 * 背景：后台若被鉴权中间件 + 控制器基类双重校验、且依赖 SSO/CAS 单点登录，
 * 自动化回归无法每次走登录流程，因此直接构造 session 写进 redis。
 *
 * TP8 会话结构（与 TP5.1 不同，不能照搬）：
 *   cookie 名 = config/session.php 的 name（示例 session_app_）
 *   sid       = 必须 32 位纯字母数字（Store::setId 强制校验，否则重新生成）
 *   redis key = cache store prefix（示例 app_）+ session prefix (think) + sid
 *   value     = serialize(serialize(扁平 session 数组))
 *
 * ⚠️ 双层 serialize 不是笔误：
 *   Store::save() 先 serialize 一次得到 S1，交给 Cache session driver；
 *   Cache driver 再交给 cache\driver\Redis::set()，set() 内部又 serialize 一次。
 *   只写一层的话，读时 cache 驱动 unserialize 会返回数组，
 *   而 Cache driver read() 里 `(string)` 强转数组 → 「Array to string conversion」→ 500。
 *
 * 用法:
 *   php docs/regress/bootstrap_session.php [--user=admin] [--sid=32位] [--ttl=28800]
 */
require_once __DIR__ . '/lib/env.php';

$opts = getopt('', ['user::', 'sid::', 'ttl::']);
$sid  = $opts['sid'] ?? substr(md5('tp-regress-' . date('Ymd')), 0, 32);
if (!preg_match('/^[a-zA-Z0-9]{32}$/', $sid)) {
    fwrite(STDERR, "sid 必须是 32 位字母数字\n");
    exit(1);
}
$ttl = (int)($opts['ttl'] ?? 28800);

$db = regress_db();

// 1. 选一个可用的管理员账号
// TODO: 改成你的用户表与字段（示例表 admin_user：id / username / status）
$wantUser = $opts['user'] ?? '';
$where = $wantUser !== ''
    ? "username = '" . $db->real_escape_string($wantUser) . "'"
    : "1=1";
$user = $db->query("SELECT id, username FROM admin_user
    WHERE {$where} AND status = 1
    ORDER BY id ASC LIMIT 1")->fetch_assoc();
if (!$user) {
    fwrite(STDERR, "找不到可用的管理员账号，请用 --user= 指定\n");
    exit(1);
}

// 2. （可选）业务上下文：如分校 / 部门等，鉴权基类若校验这些字段则必须构造
// TODO: 按项目补充；示例略。

// 3. 权限清单：示例从权限表取全部启用的「控制器_方法」
// TODO: 改成你的权限表与字段（示例表 auth：auth_c / auth_a / status）
$permission = [];
$res = $db->query("SELECT DISTINCT auth_c, auth_a FROM auth
    WHERE status = 1 AND auth_a <> '' AND auth_c <> ''");
while ($row = $res->fetch_assoc()) {
    $permission[] = $row['auth_c'] . '_' . $row['auth_a'];
}

// 4. 组装 session 数据（TP8 为扁平结构，prefix 只参与 cache key）
// TODO: 字段必须与你的鉴权中间件 / 控制器基类读取的 session 键一致
$username = $user['username'];
$data = [
    'username'   => $username,
    'user_id'    => (int)$user['id'],
    'permission' => $permission,
    'role_code'  => 'GlobalTest',
    'role_name'  => '超级管理员(回归测试)',
    'IS_SUPER'   => 1,
];

$payload = serialize(serialize($data));
$key = regress_session_key($sid);

$redis = regress_redis();
if ($ttl > 0) {
    $redis->setex($key, $ttl, $payload);
} else {
    $redis->set($key, $payload);
}

$out = [
    'sid'        => $sid,
    'cookie'     => regress_cookie_name() . '=' . $sid,
    'redis_key'  => $key,
    'ttl'        => $ttl,
    'username'   => $username,
    'user_id'    => (int)$user['id'],
    'permission_count' => count($permission),
    'bytes'      => strlen($payload),
];

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
}
return $out;