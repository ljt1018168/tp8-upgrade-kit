<?php
// +----------------------------------------------------------------------
// | 控制器返回值兼容层（TP8 迁移适配）
// +----------------------------------------------------------------------
// | TP5.1 的 think\Response::create() 对「非 Response 的返回值」很宽容：
// |   数组 / ArrayObject  → 自动转 JSON
// |   false / true / null → 当成空字符串输出（HTTP 200 + 空 body）
// | TP8 改为由 route\Dispatch::autoResponse() 按 `$request->isJson()`
// | 二选一（json / html），上述两类值都会落到 html 分支，进而在
// | Response::getContent() 抛
// |   InvalidArgumentException: variable type error： array
// |   InvalidArgumentException: variable type error： boolean
// | 表现为整页 500。
// |
// | 本项目有大量「直接 return 数组」和「return 某方法调用结果（可能为 false）」
// | 的历史写法，逐个改控制器既易漏又易回归，故在框架外统一兜住。
// |
// | 只在精确的 think\response\Html 上生效，不触碰
// | View / Json / Redirect / File / app\response\Jump。
// +----------------------------------------------------------------------

namespace app\http\middleware;

use Closure;
use think\Request;
use think\Response;
use think\response\Html;

class LegacyResponseCompat
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response::class !== Html::class) {
            return $response;
        }

        $data = $response->getData();

        // 数组返回值 → JSON（TP5.1 行为）
        if (is_array($data) || $data instanceof \ArrayObject) {
            return json($data, $response->getCode());
        }

        // 布尔/null 返回值 → 空字符串或 '1'（TP5.1 行为）
        if (is_bool($data) || is_null($data)) {
            return response((string) $data, $response->getCode());
        }

        return $response;
    }
}
