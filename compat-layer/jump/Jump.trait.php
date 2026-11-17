<?php
// +----------------------------------------------------------------------
// | 控制器跳转 trait（TP8 移植版）
// +----------------------------------------------------------------------
// | 来源：TP5.1 thinkphp/library/traits/controller/Jump.php
// | 适配点：
// |   1. Response::create($data, 'jump') 在 TP8 无对应驱动，
// |      改为显式指定 app\response\Jump::class。
// |   2. 配置读取改为 TP8 的 app.* / route.* 命名空间。
// |   3. Url 构建改用容器内的 url 实例。
// +----------------------------------------------------------------------

namespace app\common\traits;

use think\Container;
use think\exception\HttpResponseException;
use think\Response;
use think\response\Redirect;

trait Jump
{
    /**
     * 应用实例
     * @var \think\App
     */
    protected $app;

    /**
     * 操作成功跳转
     * @param mixed  $msg    提示信息
     * @param string $url    跳转的 URL 地址
     * @param mixed  $data   返回的数据
     * @param int    $wait   跳转等待时间
     * @param array  $header 发送的 Header 信息
     * @return void
     */
    protected function success($msg = '', $url = null, $data = '', $wait = 3, array $header = [])
    {
        if (is_null($url) && isset($_SERVER['HTTP_REFERER'])) {
            $url = $_SERVER['HTTP_REFERER'];
        } elseif ('' !== $url) {
            // TP8 适配：$url 为 null 且无 HTTP_REFERER 时会走到这里，
            // PHP 8.1 起 strpos(null, ...) 抛 deprecated（TP8 下直接中断请求）。
            // TP5.1 隐含把 null 转成 ''，最终 urlBuild('') 得到当前 URL，此处用 (string) 显式对齐。
            $url = (strpos((string) $url, '://') || 0 === strpos((string) $url, '/')) ? $url : $this->urlBuild((string) $url);
        }

        $result = [
            'code' => 1,
            'msg'  => $msg,
            'data' => $data,
            'url'  => $url,
            'wait' => $wait,
        ];

        $type = $this->getResponseType();

        if ('html' == strtolower($type)) {
            $response = Response::create($result, \app\response\Jump::class)
                ->header($header)
                ->options(['jump_template' => $this->getConfig('app.dispatch_success_tmpl')]);
        } else {
            $response = Response::create($result, $type)->header($header);
        }

        throw new HttpResponseException($response);
    }

    /**
     * 操作错误跳转
     * @param mixed  $msg    提示信息
     * @param string $url    跳转的 URL 地址
     * @param mixed  $data   返回的数据
     * @param int    $wait   跳转等待时间
     * @param array  $header 发送的 Header 信息
     * @return void
     */
    protected function error($msg = '', $url = null, $data = '', $wait = 3, array $header = [])
    {
        $type = $this->getResponseType();

        if (is_null($url)) {
            $url = $this->getRequest()->isAjax() ? '' : 'javascript:history.back(-1);';
        } elseif ('' !== $url) {
            $url = (strpos($url, '://') || 0 === strpos($url, '/')) ? $url : $this->urlBuild($url);
        }

        $result = [
            'code' => 0,
            'msg'  => $msg,
            'data' => $data,
            'url'  => $url,
            'wait' => $wait,
        ];

        if ('html' == strtolower($type)) {
            $response = Response::create($result, \app\response\Jump::class)
                ->header($header)
                ->options(['jump_template' => $this->getConfig('app.dispatch_error_tmpl')]);
        } else {
            $response = Response::create($result, $type)->header($header);
        }

        throw new HttpResponseException($response);
    }

    /**
     * 返回封装后的 API 数据
     * @param mixed  $data   要返回的数据
     * @param int    $code   返回的 code
     * @param mixed  $msg    提示信息
     * @param string $type   返回数据格式
     * @param array  $header 发送的 Header 信息
     * @return void
     */
    protected function result($data, $code = 0, $msg = '', $type = '', array $header = [])
    {
        $result = [
            'code' => $code,
            'msg'  => $msg,
            'time' => time(),
            'data' => $data,
        ];

        $type = $type ?: $this->getResponseType();

        throw new HttpResponseException(Response::create($result, $type)->header($header));
    }

    /**
     * URL 重定向
     *
     * TP8 适配说明：
     *   TP8 的 Redirect 构造函数变为
     *   __construct(Cookie $cookie, Request $request, Session $session, $data, $code)，
     *   且移除了 params() 方法。此处改为注入依赖 + 手工拼接 query 参数。
     *
     * @param string    $url    跳转的 URL 表达式
     * @param array|int $params 其它 URL 参数
     * @param int       $code   http code
     * @param array     $with   隐式传参
     * @return void
     */
    protected function redirect($url, $params = [], $code = 302, $with = [])
    {
        if (is_integer($params)) {
            $code   = $params;
            $params = [];
        }

        // TP5.1 通过 params() 追加查询串，TP8 需自行拼接
        if (!empty($params)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
        }

        $app = $this->app ?: Container::getInstance()->make('app');

        $response = new Redirect($app->cookie, $app->request, $app->session, $url, $code);

        if (!empty($with)) {
            $response->with($with);
        }

        throw new HttpResponseException($response);
    }

    /**
     * 获取当前的 response 输出类型
     * @return string
     */
    protected function getResponseType()
    {
        $isAjax = $this->getRequest()->isAjax();

        return $isAjax
            ? $this->getConfig('route.default_ajax_return', 'json')
            : $this->getConfig('route.default_return_type', 'html');
    }

    /**
     * 读取配置（兼容 TP8 两级键名）
     * @param string $name
     * @param mixed  $default
     * @return mixed
     */
    protected function getConfig(string $name, $default = null)
    {
        $app = $this->app ?: Container::getInstance()->make('app');

        return $app->config->get($name, $default);
    }

    /**
     * 获取请求对象
     * @return \think\Request
     */
    protected function getRequest()
    {
        $app = $this->app ?: Container::getInstance()->make('app');

        return $app->request;
    }

    /**
     * 构建 URL
     *
     * TP8 适配说明：
     *   TP5.1 通过容器键 'url' 取 think\Url 实例，TP8 已无此绑定
     *   （$app->make('url') 会抛「类不存在: url」），改用 Route::buildUrl()。
     *   另外 TP5.1 的 Url::build('') 在 $url 为空时返回「当前请求 URL」，
     *   这里显式对齐，避免退化成站点根 '/'。
     *
     * @param string $url
     * @return string
     */
    protected function urlBuild(string $url)
    {
        $app = $this->app ?: Container::getInstance()->make('app');

        if ('' === $url) {
            return $app->request->url();
        }

        return (string) $app->make(\think\Route::class)->buildUrl($url);
    }
}
