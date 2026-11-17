<?php
// +----------------------------------------------------------------------
// | 模板引擎驱动（TP5.1 think\view\driver\Think 的 TP8 重建版）
// +----------------------------------------------------------------------
// | 迁移背景：
// |   TP5.1 的模板路径解析并不在 think\Template 里，而在 view 驱动层：
// |     thinkphp/library/think/view/driver/Think.php
// |   该驱动在构造时做 `view_path = $app->getModulePath() . 'view/'`，
// |   并在 parseTemplate() 里处理「控制器目录前缀」「@跨模块」「auto_rule」。
// |
// |   TP8 把模板引擎拆成独立的 topthink/think-template 包，包内
// |   Template::parseTemplateFile() 只做 `config['view_path'] . $name . '.html'`
// |   的纯字符串拼接，**不再有任何自动定位逻辑**。
// |   官方把这层逻辑放进了另一个包 topthink/think-view 的 Think 驱动里。
// |
// |   本项目直接指定 `type = think\Template` 绕过了驱动层，且 config/view.php
// |   里 view_path 留空，于是模板名 'public/default' 被当成相对路径，
// |   最终解析出 'public/default.html' → 模板文件不存在。
// |
// | 本类的作用：把驱动层补回来（不引入新依赖），行为与 TP5.1 对齐。
// |   - 视图根目录 = 当前应用目录 + view/（多应用下 app/admin/view/）
// |   - 模板编译缓存 = 当前应用 runtime/temp/（与 TP5.1 一致，按应用隔离）
// |   - 保留 @跨模块、控制器目录前缀、auto_rule 三个定位规则
// +----------------------------------------------------------------------
declare(strict_types=1);

namespace app\common\view\driver;

use think\App;
use think\Request;
use think\Template;
use think\helper\Str;

class Think
{
    protected App $app;

    /**
     * 模板引擎实例
     * @var Template
     */
    protected $template;

    /**
     * 驱动配置
     * @var array
     */
    protected $config = [
        // 默认模板渲染规则 1 解析为小写+下划线 2 全部转换小写 3 保持操作方法
        'auto_rule'   => 1,
        // 视图基础目录（集中式）
        'view_base'   => '',
        // 模板起始路径（留空则按当前应用自动定位）
        'view_path'   => '',
        // 模板文件后缀
        'view_suffix' => 'html',
        // 模板文件名分隔符
        'view_depr'   => DIRECTORY_SEPARATOR,
        // 是否开启模板编译缓存
        'tpl_cache'   => true,
    ];

    public function __construct(App $app, array $config = [])
    {
        $this->app    = $app;
        $this->config = array_merge($this->config, $config);

        // 关键：view_path 与 cache_path 都不在构造时固化。
        // 多应用下 appPath / runtimePath 要等 MultiApp 中间件解析完才有值，
        // 而驱动实例是在首次渲染时才创建的，此时取值已正确；
        // 这里仍保留运行时再同步一次的能力，避免同一实例跨应用复用时串目录。
        $this->template = new Template($this->config);
    }

    /**
     * 检测模板文件是否存在
     * @param string $template
     * @return bool
     */
    public function exists(string $template): bool
    {
        $file = $this->resolveFile($template);

        return $file !== null && is_file($file);
    }

    /**
     * 渲染模板文件
     * @param string $template 模板文件名
     * @param array  $vars     模板变量
     * @return void
     */
    public function fetch(string $template, array $vars = []): void
    {
        // 先同步路径，保证 {include} / {extend} 在解析期能定位到同级模板
        $this->syncPath();

        $file = $this->resolveFile($template);

        if (null === $file || !is_file($file)) {
            throw new \Exception('template not exists:' . ($file ?: $template));
        }

        $this->template->fetch($file, $vars);
    }

    /**
     * 渲染模板内容
     * @param string $content 模板内容
     * @param array  $vars    模板变量
     * @return void
     */
    public function display(string $content, array $vars = []): void
    {
        $this->syncPath();

        $this->template->display($content, $vars);
    }

    /**
     * 设置配置
     * @param array $config
     * @return $this
     */
    public function config(array $config): static
    {
        $this->config = array_merge($this->config, $config);
        $this->template->config($config);

        return $this;
    }

    /**
     * 解析模板名到实际文件路径
     * 与 TP5.1 驱动一致：已带扩展名的模板名视为**最终路径**，跳过自动定位。
     * 这一条支撑了 jump 页（config 里 dispatch_success_tmpl 配的是
     * app/tpl/dispatch_jump.tpl 这种绝对路径），否则会被当成
     * 相对模板名再拼一次 view_path，解析出 view/Users/.../dispatch_jump.tpl.html
     * @param string $template
     * @return string|null
     */
    protected function resolveFile(string $template): ?string
    {
        if ('' == pathinfo($template, PATHINFO_EXTENSION)) {
            return $this->parseTemplate($template);
        }

        return $template;
    }

    /**
     * 自动定位模板文件
     * 逻辑移植自 TP5.1 think\view\driver\Think::parseTemplate()
     * @param string $template 模板文件规则
     * @return string
     */
    protected function parseTemplate(string $template): string
    {
        $request = $this->app->request;
        $module  = null;

        // 跨模块调用：'admin@public/default'
        if (str_contains($template, '@')) {
            [$module, $template] = explode('@', $template, 2);
        }

        if (!empty($this->config['view_base'])) {
            // 集中式视图目录
            $module = $module ?: $this->getAppName();
            $path   = $this->config['view_base'] . ($module ? $module . DIRECTORY_SEPARATOR : '');
        } elseif ($module) {
            // 多应用下 appPath 已被 MultiApp 指向 app/admin/，
            // 因此跨模块要从 app 根目录（getBasePath）重新拼接。
            $path = $this->app->getBasePath() . $module . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR;
        } else {
            $path = $this->app->getAppPath() . 'view' . DIRECTORY_SEPARATOR;
        }

        $depr = $this->config['view_depr'];

        if (!str_starts_with($template, '/')) {
            $template = str_replace(['/', ':'], $depr, $template);

            $controller = Str::snake($request->controller());

            if ($controller) {
                if ('' == $template) {
                    // 模板文件名为空，按默认规则定位
                    $template = str_replace('.', DIRECTORY_SEPARATOR, $controller) . $depr . $this->getActionTemplate($request);
                } elseif (!str_contains($template, $depr)) {
                    // 未带目录，补上当前控制器目录
                    $template = str_replace('.', DIRECTORY_SEPARATOR, $controller) . $depr . $template;
                }
            }
        } else {
            $template = str_replace(['/', ':'], $depr, substr($template, 1));
        }

        // 同步给模板引擎，供 {include} / {extend} 标签在解析期使用
        $this->syncPath($path);

        $file = $path . ltrim($template, '/') . '.' . ltrim($this->config['view_suffix'], '.');

        // 【兜底】模板文件名与操作方法名不是严格的 snake 关系时（大小写丢失、下划线有无、
        // 中文拼音命名不一致等），TP5.1 会直接抛「模板文件不存在」，把问题留给页面。
        // 这里在同目录内做一次「忽略大小写与分隔符」的归一化匹配，
        // 命中即用，避免因为一个字母的差异导致整页 500。
        if (!is_file($file)) {
            $matched = $this->matchTemplateFile($file);
            if (null !== $matched) {
                $file = $matched;
            }
        }

        return $file;
    }

    /**
     * 同目录内的模板文件模糊匹配
     * 归一化规则：去掉所有非字母数字字符并转小写，
     * 因此 allData / all_data / alldata / ALL-DATA 视为同一个名字。
     * 仅在默认定位规则拼出的文件不存在时才会走到这里，正常渲染零开销。
     * @param string $file 已拼好的绝对路径
     * @return string|null 命中的模板绝对路径，未命中返回 null
     */
    protected function matchTemplateFile(string $file): ?string
    {
        $suffix = '.' . ltrim($this->config['view_suffix'], '.');
        $dir    = dirname($file);
        $base   = basename($file, $suffix);

        if ('' === $base || !is_dir($dir)) {
            return null;
        }

        $normalize = static function (string $name) use ($suffix): string {
            $name = basename($name, $suffix);

            return preg_replace('/[^a-z0-9]/i', '', strtolower($name)) ?: '';
        };

        $target = $normalize($base);
        if ('' === $target) {
            return null;
        }

        foreach ((array) glob($dir . DIRECTORY_SEPARATOR . '*' . $suffix) as $candidate) {
            if ($normalize($candidate) === $target) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * 取得默认模板名
     *
     * 【TP8 语义反转】TP5.1 与 TP8 的 Request::action($convert) 参数含义正好相反：
     *   TP5.1: return $convert ? $name : strtolower($name);   // true=原始大小写，false=全小写
     *   TP8:   return $convert ? strtolower($name) : $name;   // true=全小写，  false=原始大小写
     *
     * TP5.1 原驱动 getActionTemplate() 的取值表是：
     *   $rule = [$request->action(true), Loader::parseName($request->action(true)), $request->action()];
     *         = [原始大小写,          小写+下划线,                                全小写]
     *   auto_rule 1 → 小写+下划线；2 → 全小写；3（越界）→ 回落原始大小写。
     *
     * 之前这里是原样照抄 TP5.1 的写法，因为参数语义反转，实际算成了
     *   [全小写, snake(全小写), 原始]
     * 导致 action 名带大写字母时下划线全部丢失：
     *   allData → alldata（正确应为 all_data）→ 模板文件不存在。
     * 修法是把参数换回 TP8 语义，从而还原出 TP5.1 的那张表。
     *
     * @param Request $request
     * @return string
     */
    protected function getActionTemplate(Request $request): string
    {
        $action = $request->action();          // TP8：原始大小写
        $rule   = [
            $action,                           // 1) 保持操作方法
            Str::snake($action),               // 2) 小写+下划线
            $request->action(true),            // 3) 全部小写
        ];
        $type = $this->config['auto_rule'];

        return $rule[$type] ?? $rule[0];
    }

    /**
     * 把 view_path / cache_path 同步到模板引擎实例
     * cache_path 按 TP5.1 语义取「当前应用」的 runtime/temp/
     * （多应用下 runtimePath 已被 MultiApp 改成 runtime/admin/）
     * @param string|null $viewPath
     * @return void
     */
    protected function syncPath(?string $viewPath = null): void
    {
        $viewPath = $viewPath ?: ($this->app->getAppPath() . 'view' . DIRECTORY_SEPARATOR);

        $this->template->config([
            'view_path'  => $viewPath,
            'cache_path' => $this->app->getRuntimePath() . 'temp' . DIRECTORY_SEPARATOR,
        ]);
    }

    /**
     * 当前应用名（多应用）；单应用返回空串
     * @return string
     */
    protected function getAppName(): string
    {
        try {
            return (string) $this->app->http->getName();
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function __call($method, $params)
    {
        return $this->template->$method(...$params);
    }

    public function __debugInfo(): array
    {
        return ['config' => $this->config];
    }
}
