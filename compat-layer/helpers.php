<?php
// +----------------------------------------------------------------------
// | TP5.1 助手函数语义还原（参考实现，自真实迁移项目提炼）
// +----------------------------------------------------------------------
// | 收录三个高频断层的最小修复：
// |   1. setRedis/getRedis/delRedis —— 缓存助手语义对齐（null→false、rm→delete、
// |      expire 空串 TypeError）
// |   2. app_log_path —— 日志路径锚定，杜绝相对路径写进 web 根
// |   3. upload_file —— TP5.1 上传链统一入口（配合 file/LegacyUploadedFile.php）
// |
// | 接入方式：放入 app/common.php 或 composer files autoload；
// | 命名空间 app\common\file 按你的包结构自行调整。
// +----------------------------------------------------------------------

use think\facade\Cache;

/**
 * 设置 redis 缓存
 * @param string $key    键名
 * @param mixed  $value  值
 * @param int    $expire 过期时间(秒)，默认 7200。传 0 或 null 表示永不过期
 * @param string $prefix 自定义前缀；留空则用「当前应用_控制器_方法」自动生成
 * @return bool
 *
 * 【TP8 断层】框架驱动 getExpireTime() 形参为 int|DateInterval|DateTimeInterface
 * 且文件内 declare(strict_types=1)，传空字符串直接抛 TypeError
 * （TP5.1 时代常见的默认值 '' 在 TP8 下不可用），故默认值必须给 int。
 */
function setRedis($key, $value = '', $expire = 7200, $prefix = '')
{
    if ($key) {
        if (!$prefix) {
            $moduleName     = app('http')->getName();
            $controllerName = request()->controller();
            $actionName     = request()->action();
            $key = md5(strtolower($moduleName . '_' . $controllerName . '_' . $actionName)) . '_' . $key;
        } else {
            $key = md5($prefix) . '_' . $key;
        }
        return Cache::store('redis')->set($key, $value, $expire);
    }
    return false;
}

/**
 * 获取 redis 缓存
 * @return mixed 未命中返回 false（还原 TP5.1 语义）
 *
 * 【TP8 语义对齐】TP5.1 的 Cache::get() 未命中返回 false，TP8 返回 null。
 * 遗留代码常用 `=== false` 判断「缓存未命中 → 回源查库」，
 * 若原样返回 null 这些分支全部失效（典型表现：声明 string 返回值的函数
 * 触发 "Return value must be of type string, null returned"）。
 * 在助手层统一还原，避免逐个调用点修改。
 */
function getRedis($key, $prefix = '')
{
    if (!$prefix) {
        $moduleName     = app('http')->getName();
        $controllerName = request()->controller();
        $actionName     = request()->action();
        $key = md5(strtolower($moduleName . '_' . $controllerName . '_' . $actionName)) . '_' . $key;
    } else {
        $key = md5($prefix) . '_' . $key;
    }
    $value = Cache::store('redis')->get($key);

    return null === $value ? false : $value;
}

/**
 * 删除 redis 缓存
 *
 * 【TP8 语义对齐】TP5.1 缓存驱动把「删除单个 key」叫 rm()，TP8 已统一为
 * PSR-16 的 delete()。原样保留 rm() 会被驱动 __call 转发到 phpredis 原生对象，
 * 报 "class Redis does not have a method rm"。
 */
function delRedis($key, $prefix = '')
{
    if (!$prefix) {
        $moduleName     = app('http')->getName();
        $controllerName = request()->controller();
        $actionName     = request()->action();
        $key = md5(strtolower($moduleName . '_' . $controllerName . '_' . $actionName)) . '_' . $key;
    } else {
        $key = md5($prefix) . '_' . $key;
    }
    return Cache::store('redis')->delete($key);
}

/**
 * 应用日志根目录（锚定到项目根，不依赖进程 CWD）
 *
 * 历史问题：多处日志用 './xxx' 相对路径写盘，落点由当前工作目录决定。
 *   - FPM 下 cwd = public/  → 日志写进 web 根，可被匿名下载（可能含敏感个人信息）
 *   - CLI 下 cwd = 项目根   → 同一份日志又写到另一个位置，排查时找不到
 * 统一锚定到 runtime/log/：在 web 根之外，且被 .gitignore 忽略。
 * 配套 nginx 规则兜底：location ~* ^/(runtime|.*\.log) { return 404; }
 *
 * @param string $sub 子目录，如 'sls/20261027'
 * @return string 结尾带目录分隔符的绝对路径
 */
function app_log_path(string $sub = ''): string
{
    $base = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR;
    $path = $base;
    $sub  = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sub), DIRECTORY_SEPARATOR);
    if ($sub !== '') {
        $path .= $sub . DIRECTORY_SEPARATOR;
    }
    if (!is_dir($path)) {
        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $path));
        }
    }
    return $path;
}

/**
 * TP5.1 上传语义统一入口
 *
 * 断层背景：TP8 的 think\file\UploadedFile 删掉了 validate()，move() 失败改抛异常，
 * 返回的 File 也没有 getSaveName()/getInfo()——原 $file->validate(...)->move(...)
 * 链会直接 500（Call to undefined method）。
 *
 * 用法（与 TP5.1 完全一致，只是 file() 换成 upload_file()）：
 *   $file = upload_file('file');
 *   $info = $file->validate(['size'=>5*1024*1024,'ext'=>'jpg,jpeg,png,gif'])->move('uploads');
 *   $info->getSaveName(); $info->getInfo('name'); $file->getError();
 *
 * @param string $name 表单字段名（request()->file($name) 的键）
 * @return \app\common\file\LegacyUploadedFile|null 未携带文件时返回 null（与 TP8 file() 一致）
 */
function upload_file(string $name = 'file')
{
    $file = request()->file($name);
    if ($file instanceof \think\file\UploadedFile) {
        return new \app\common\file\LegacyUploadedFile($file);
    }
    return $file; // null：保持调用方原有 empty 判断逻辑不变
}