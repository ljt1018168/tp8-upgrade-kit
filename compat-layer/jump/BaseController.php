<?php
// +----------------------------------------------------------------------
// | 应用控制器基类（TP8 重建 think\Controller）
// +----------------------------------------------------------------------
// | 背景：
// |   TP8 已移除 think\Controller，控制器需自建基类。遗留控制器若依赖
// |   $this->assign() / success() / error() / result() / redirect()
// |   等 TP5.1 语义，故按原行为在此重建，并通过 app/compat.php 中的
// |   class_alias 兼容原有的 use think\Controller; 写法。
// |
// | 迁移说明：
// |   - fetch() 使用 TP8 的 think\response\View 链式 API
// |   - validate() 使用 TP8 的 Validate（think-validate 3.x）
// |   - success/error/result/redirect 由 app\common\traits\Jump 提供
// +----------------------------------------------------------------------

namespace app;

use app\common\traits\Jump;
use think\App;
use think\exception\ValidateException;
use think\Response;

class BaseController
{
    use Jump;

    /**
     * 应用实例
     * @var App
     */
    protected $app;

    /**
     * 视图实例
     * @var \think\View
     */
    protected $view;

    /**
     * Request 实例
     * @var \think\Request
     */
    protected $request;

    /**
     * 验证失败是否抛出异常
     * @var bool
     */
    protected $failException = false;

    /**
     * 是否批量验证
     * @var bool
     */
    protected $batchValidate = false;

    /**
     * 前置操作方法列表（兼容旧写法）
     * @var array
     */
    protected $beforeActionList = [];

    /**
     * 控制器中间件
     * @var array
     */
    protected $middleware = [];

    /**
     * 构造方法
     * @param App $app
     */
    public function __construct(App $app)
    {
        $this->app     = $app;
        $this->request = $app->request;
        $this->view    = $app->view;

        // 控制器初始化
        $this->initialize();

        // 注册控制器中间件
        $this->registerMiddleware();

        // 前置操作方法（即将废弃，兼容保留）
        foreach ((array) $this->beforeActionList as $method => $options) {
            is_numeric($method)
                ? $this->beforeAction($options)
                : $this->beforeAction($method, $options);
        }
    }

    /**
     * 控制器初始化钩子
     * @return void
     */
    protected function initialize()
    {
    }

    /**
     * 注册控制器中间件
     * @return void
     */
    public function registerMiddleware()
    {
        foreach ($this->middleware as $key => $val) {
            if (!is_int($key)) {
                $only = $except = null;

                if (isset($val['only'])) {
                    $only = array_map('strtolower', $val['only']);
                } elseif (isset($val['except'])) {
                    $except = array_map('strtolower', $val['except']);
                }

                if (isset($only) && !in_array($this->request->action(), $only)) {
                    continue;
                } elseif (isset($except) && in_array($this->request->action(), $except)) {
                    continue;
                } else {
                    $val = $key;
                }
            }

            $this->app->middleware->controller($val);
        }
    }

    /**
     * 前置操作
     * @param string $method  前置操作方法名
     * @param array  $options 调用参数 ['only'=>[...]] 或 ['except'=>[...]]
     * @return void
     */
    protected function beforeAction($method, $options = [])
    {
        if (isset($options['only'])) {
            if (is_string($options['only'])) {
                $options['only'] = explode(',', $options['only']);
            }

            $only = array_map('strtolower', $options['only']);

            if (!in_array($this->request->action(), $only)) {
                return;
            }
        } elseif (isset($options['except'])) {
            if (is_string($options['except'])) {
                $options['except'] = explode(',', $options['except']);
            }

            $except = array_map('strtolower', $options['except']);

            if (in_array($this->request->action(), $except)) {
                return;
            }
        }

        call_user_func([$this, $method]);
    }

    /**
     * 加载模板输出
     * @param string $template 模板文件名
     * @param array  $vars     模板输出变量
     * @param array  $config   模板参数
     * @return Response
     */
    protected function fetch($template = '', $vars = [], $config = [])
    {
        return Response::create($template, 'view')->assign($vars)->config($config);
    }

    /**
     * 渲染内容输出
     * @param string $content 模板内容
     * @param array  $vars    模板输出变量
     * @param array  $config  模板参数
     * @return Response
     */
    protected function display($content = '', $vars = [], $config = [])
    {
        return Response::create($content, 'view')->assign($vars)->config($config)->isContent(true);
    }

    /**
     * 模板变量赋值
     * @param mixed $name  要显示的模板变量
     * @param mixed $value 变量的值
     * @return $this
     */
    protected function assign($name, $value = '')
    {
        $this->view->assign($name, $value);

        return $this;
    }

    /**
     * 视图过滤
     * @param callable $filter 过滤方法或闭包
     * @return $this
     */
    protected function filter($filter)
    {
        $this->view->filter($filter);

        return $this;
    }

    /**
     * 初始化模板引擎
     * @param array|string $engine 引擎参数
     * @return $this
     */
    protected function engine($engine)
    {
        $this->view->engine($engine);

        return $this;
    }

    /**
     * 设置验证失败后是否抛出异常
     * @param bool $fail 是否抛出异常
     * @return $this
     */
    protected function validateFailException($fail = true)
    {
        $this->failException = $fail;

        return $this;
    }

    /**
     * 验证数据
     * @param array        $data     数据
     * @param string|array $validate 验证器名或验证规则数组
     * @param array        $message  提示信息
     * @param bool         $batch    是否批量验证
     * @param mixed        $callback 回调方法（闭包）
     * @return array|string|true
     * @throws ValidateException
     */
    protected function validate($data, $validate, $message = [], $batch = false, $callback = null)
    {
        $scene = null;

        if (is_array($validate)) {
            $v = $this->app->validate();
            $v->rule($validate);
        } else {
            if (strpos($validate, '.')) {
                // 支持场景
                [$validate, $scene] = explode('.', $validate);
            }
            $v = $this->app->validate($validate);
            if (!empty($scene)) {
                $v->scene($scene);
            }
        }

        if ($batch || $this->batchValidate) {
            $v->batch(true);
        }

        if (is_array($message)) {
            $v->message($message);
        }

        if ($callback && is_callable($callback)) {
            call_user_func_array($callback, [$v, &$data]);
        }

        if (!$v->check($data)) {
            if ($this->failException) {
                throw new ValidateException($v->getError());
            }

            return $v->getError();
        }

        return true;
    }

    /**
     * 调试信息
     * @return array
     */
    public function __debugInfo()
    {
        $data = get_object_vars($this);
        unset($data['app'], $data['request'], $data['view']);

        return $data;
    }
}
