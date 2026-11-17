<?php
// +----------------------------------------------------------------------
// | TP5.1 全局类别名兼容层（TP8 迁移专用）
// +----------------------------------------------------------------------
// | 背景：
// |   TP5.1 的 thinkphp/base.php 通过 Loader::addClassAlias() 注册了
// |   一批全局类别名（Env、Db、Cache、Config、Session、Request ...），
// |   因此业务代码与配置文件可以直接写 Env::get() / Db::name()。
// |   TP8 移除了该机制，若不做兼容，所有依赖全局别名的位置都会
// |   抛 "Class 'Env' not found"。
// |
// | 设计原则：
// |   1. 别名优先指向门面（Facade），而非 think\Db 这类实体类，
// |      因为 TP8 的 think\Db 没有 __callStatic()，静态调用会失败。
// |   2. 仅在全局类未定义时注册，不覆盖业务或框架已声明的类。
// |   3. 该文件由 app/common.php 最早期引入，早于 config 目录加载，
// |      可覆盖 config/{env}/*.php 中的 Env::get() 用法。
// |
// | 长期建议：
// |   逐步把业务代码改为显式 use think\facade\XXX，本兼容层仅作过渡。
// +----------------------------------------------------------------------

$compatAliases = [
    // 配置 / 环境
    'Env'        => \think\facade\Env::class,
    'Config'     => \think\facade\Config::class,
    // 数据库
    'Db'         => \think\facade\Db::class,
    // 缓存 / 会话 / Cookie
    'Cache'      => \think\facade\Cache::class,
    'Session'    => \think\facade\Session::class,
    'Cookie'     => \think\facade\Cookie::class,
    // 请求 / 响应 / 路由 / URL
    'Request'    => \think\facade\Request::class,
    'Response'   => \think\Response::class,
    'Route'      => \think\facade\Route::class,
    'Url'        => \think\facade\Url::class,
    // 应用 / 视图 / 语言
    // 注意：不注册全局 'Log' 别名。阿里云日志 SDK 的 sls.proto.php
    // 会在全局命名空间声明 class Log，若此处别名 'Log' 会触发
    // "Cannot redeclare class think\facade\Log"。
    // 业务代码均已显式 use think\facade\Log，无需全局别名。
    'App'        => \think\facade\App::class,
    'View'       => \think\facade\View::class,
    'Lang'       => \think\facade\Lang::class,
    // ORM / 验证
    'Model'      => \think\Model::class,
    'Validate'   => \think\Validate::class,
    'Collection' => \think\Collection::class,
];

foreach ($compatAliases as $alias => $class) {
    if (class_exists($alias, false)) {
        continue;
    }
    if (class_exists($class)) {
        class_alias($class, $alias);
    }
}

unset($compatAliases, $alias, $class);

// ---------------------------------------------------------------------
// think\Controller 兼容：TP8 已移除该基类
// ---------------------------------------------------------------------
// 遗留控制器若沿用 TP5.1 的 `use think\Controller;` 与
// `extends Controller`，此处把该名称别名到重建的 app\BaseController，
// 使其在 TP8 下保持相同的方法集合（assign / fetch / success / error ...）。
// 后续可按模块逐步改为显式 extends \app\BaseController。
if (!class_exists('think\Controller', false) && class_exists(\app\BaseController::class)) {
    class_alias(\app\BaseController::class, 'think\Controller');
}

// ---------------------------------------------------------------------
// TP5.1 助手函数兼容：controller()
// ---------------------------------------------------------------------
// TP8 已移除 controller() / action() / model() 等助手。迁移时建议先全量
// 扫描真实调用点，再按需重建。controller() 常用于跨控制器复用逻辑，
// 例如 controller('AjaxUser')->checkPendingChanges(...)。
// 此处按 TP5.1 语义重建：支持 'Name'、'app.Name' 两种写法。
if (!function_exists('controller')) {
    /**
     * 实例化控制器
     * @param string $name         控制器名（支持 app.Controller）
     * @param string $layer        控制器层目录
     * @param bool   $appendSuffix 是否追加 Controller 后缀
     * @return object
     */
    function controller(string $name, string $layer = 'controller', bool $appendSuffix = false)
    {
        $app = \think\Container::getInstance()->make(\think\App::class);

        $appName = $app->http->getName() ?: $app->config->get('app.default_app', 'admin');

        // 支持 'admin.AjaxUser' 形式
        if (strpos($name, '.') !== false) {
            [$appName, $name] = explode('.', $name, 2);
        }

        $class = 'app\\' . $appName . '\\' . $layer . '\\' . $name;

        if ($appendSuffix) {
            $class .= 'Controller';
        }

        return $app->make($class);
    }
}


