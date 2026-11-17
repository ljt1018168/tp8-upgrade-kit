<?php
// +----------------------------------------------------------------------
// | PHP 8「notice 升级为 warning」兼容服务
// +----------------------------------------------------------------------
// | 背景（本次迁移最影响面最广的一处行为差异）：
// |
// |   PHP 8.0 起，以下错误从 E_NOTICE 升格为 E_WARNING：
// |     - Undefined variable $x
// |     - Undefined array key "x"          （原 Undefined index / offset）
// |     - Trying to access array offset on value of type null
// |     - Attempt to read property "x" on null
// |     - Undefined property: Class::$x
// |   PHP 8.1 起新增 E_DEPRECATED：
// |     - Passing null to parameter #N ($x) of type string is deprecated
// |
// |   TP5.1 时代的业务代码大量依赖「读不到就是 null，继续跑」的语义，
// |   这些提示在 PHP 7.4 下只是 notice，框架与业务都不当回事。
// |
// |   而 TP8 的 think\initializer\Error::init() 无条件执行
// |   error_reporting(E_ALL)，appError() 里只要 error_reporting() 命中该级别
// |   就 throw ErrorException —— 于是业务里任何一个「数组键没判空」
// |   都会从「静默继续」变成「500 中断」。
// |
// | 策略：
// |   把这几类**在 PHP 7.4 下本就静默**的提示降级为写日志，
// |   语义与迁移前完全一致（变量/键的取值仍是 null，脚本继续执行）。
// |   其余错误（真正的问题）仍交给框架抛异常，不会被掩盖。
// |
// | 与 DeprecationFilter 的分工：
// |   DeprecationFilter 只管 vendor 内的 E_DEPRECATED（第三方 SDK 未适配）；
// |   本服务只管业务代码里「PHP 8 升级类」的提示。
// |
// | 长期建议：
// |   按日志逐条补 ?? 默认值后，可逐步收紧本服务的匹配范围，
// |   最终在 APP_DEBUG=1 下也能做到零告警。
// +----------------------------------------------------------------------

namespace app\common\service;

use think\Service;

class Php8LegacyNoticeCompat extends Service
{
    /**
     * 需要降级的错误级别
     * @var int[]
     */
    protected array $levels = [E_WARNING, E_NOTICE, E_DEPRECATED];

    /**
     * 需要降级的错误消息特征（PHP 8 中由 notice 升格或新增的提示）
     * @var string[]
     */
    protected array $patterns = [
        // ---- PHP 8.0：notice 升格为 warning ----
        '/^Undefined array key /i',
        '/^Undefined variable \$/i',
        '/^Undefined index:/i',
        '/^Undefined offset:/i',
        '/^Trying to access array offset on /i',
        '/^Attempt to read property .* on null/i',
        '/^Undefined property: /i',

        // ---- PHP 8.1 ----
        // 注意：这条消息形如
        //   "strpos(): Passing null to parameter #1 ($haystack) of type string is deprecated"
        // 以「函数名()」开头，**不能加 ^ 锚点**。
        // 历史缺陷：早期版本写成 /^Passing null to parameter .../，永远匹配不上，
        // 导致所有「传 null 给标量参数」的场景照旧抛 500（Jump::success 即由此触发）。
        '/Passing null to parameter #\d+ \(\$\w+\) of type /i',
        '/Implicit conversion from float .* to int loses precision/i',
        // 典型场景：缓存/查询未命中返回 false，业务接着写 $result['k'] = 1。
        // PHP 7.4 会静默把 false 转成 array（结果等价于 []），8.1 起报 deprecated，
        // TP8 会把它抛成 500。语义不变，只降级不掩盖。
        // 注意：同样**不加 ^ 锚点**，避免重蹈 /^Passing null .../ 永远匹配不上的覆辙。
        '/Automatic conversion of false to array is deprecated/i',

        // ---- PHP 8.2 ----
        '/Creation of dynamic property /i',

        // ---- PHP 8.4（本机运行版本）----
        '/Implicitly marking parameter \$?\w+ as nullable is deprecated/i',
        '/Implicitly marking return type as nullable is deprecated/i',
    ];

    /**
     * 服务启动：在框架错误处理器之上叠加一层降级
     * @return void
     */
    public function boot(): void
    {
        $previous = null;

        $previous = set_error_handler(function (int $errno, string $errstr, string $errfile = '', int $errline = 0) use (&$previous) {
            if (in_array($errno, $this->levels, true) && $this->isLegacyNotice($errstr)) {
                // 写日志留痕（便于后续按清单补默认值），但不中断请求
                error_log(sprintf('[TP8][php8-upgraded-notice] %s @ %s:%d', $errstr, $errfile, $errline));

                return true;
            }

            // 其余交回框架处理器（保持 TP8 原有语义）
            if (is_callable($previous)) {
                return $previous($errno, $errstr, $errfile, $errline);
            }

            return false;
        });
    }

    /**
     * 是否为「PHP 7.4 下本就静默」的提示
     * @param string $errstr
     * @return bool
     */
    protected function isLegacyNotice(string $errstr): bool
    {
        foreach ($this->patterns as $pattern) {
            if (preg_match($pattern, $errstr)) {
                return true;
            }
        }

        return false;
    }
}
