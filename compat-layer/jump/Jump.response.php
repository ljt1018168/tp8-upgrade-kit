<?php
// +----------------------------------------------------------------------
// | Jump 跳转响应（TP8 补齐）
// +----------------------------------------------------------------------
// | 背景：
// |   TP5.1 内置 think\response\Jump，用于 $this->success() / $this->error()
// |   渲染跳转提示页；TP8 已移除该驱动与 dispatch_jump.tpl，
// |   本项目有 1000+ 处调用，故按原语义在此重建。
// +----------------------------------------------------------------------

namespace app\response;

use think\Container;
use think\Response;

class Jump extends Response
{
    protected $contentType = 'text/html';

    /**
     * 架构函数
     *
     * 【必须显式实现】TP8 的 think\Response 是抽象类且**没有构造函数**，
     * 而 Response::create() 的实现是
     *   Container::getInstance()->invokeClass($class, [$data, $code])
     * invokeClass 在没有构造函数时直接 new $class()，
     * 传入的 $data / $code 会被完全丢弃。
     * 结果是模板变量为空 → dispatch_jump.tpl 里 switch($code) 报
     * "Undefined variable $code"。
     * TP8 自带的 response\Html / response\Json 也都是各自实现构造函数。
     *
     * @param mixed $data 跳转数据（code/msg/data/url/wait）
     * @param int   $code HTTP 状态码
     */
    public function __construct($data = '', int $code = 200)
    {
        $this->data = $data;
        $this->code = $code;
    }

    /**
     * 渲染跳转提示页
     * @param mixed $data
     * @return string
     */
    protected function output($data)
    {
        $template = $this->options['jump_template'] ?? '';

        return Container::getInstance()->make('view')->fetch($template, (array) $data);
    }
}
