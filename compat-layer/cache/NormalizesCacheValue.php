<?php
// +----------------------------------------------------------------------
// | 缓存值归一化（TP8 迁移适配）
// +----------------------------------------------------------------------
// | 背景：
// |   TP5.1 的 `Model::select()` 返回**数组**，业务习惯把查询结果直接丢进
// |   `setRedis()` / `Cache::set()`。TP8(think-orm 4.x) 的 `select()` 返回
// |   `think\model\Collection`，该对象内部持有闭包（事件/场景），一旦进入
// |   `serialize()` 就抛
// |       Serialization of 'Closure' is not allowed
// |   表现为凡是缓存过结果集的页面整页 500。
// |
// | 处理原则：
// |   1. 只把「TP5.1 时代本就是数组」的容器降级为数组（Collection / Paginator），
// |      不改变缓存的业务语义——读出来仍然是数组，与旧版一致。
// |   2. 普通 Model 对象保持原样（TP5.1 也是对象），避免改变读取方预期。
// |   3. 万一仍有不可序列化的值，再退一步做深度降级，保证「缓存写失败」
// |      不会升级成「整页 500」。
// |
// | 为什么放在驱动层：缓存入口往往有自定义助手（如 setRedis()）与 Cache::set()
// | 两套，调用点分散，逐个调用点加 ->toArray() 既易漏又易回归。
// | 驱动层是所有写缓存的必经之路，一处收口。
// +----------------------------------------------------------------------

namespace app\common\cache;

trait NormalizesCacheValue
{
    /**
     * 把缓存值归一化成可安全序列化的结构
     */
    protected function normalizeCacheValue($value)
    {
        // TP5.1 的 select()/paginate() 返回数组，这两类容器必须还原为数组
        if ($value instanceof \think\Collection || $value instanceof \think\Paginator) {
            $value = $value->toArray();
        }

        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (is_array($v) || is_object($v)) {
                    $value[$k] = $this->normalizeCacheValue($v);
                }
            }
            return $value;
        }

        // 闭包无法序列化，直接存 null，避免整页崩溃
        if ($value instanceof \Closure) {
            return null;
        }

        return $value;
    }

    /**
     * 兜底归一化：只在正常路径序列化失败时使用
     * 比 normalizeCacheValue 更激进——所有对象都尝试转数组
     */
    protected function forceNormalizeCacheValue($value, int $depth = 0)
    {
        if ($depth > 8) {
            return null;
        }
        if ($value instanceof \Closure) {
            return null;
        }
        if (is_object($value)) {
            if (method_exists($value, 'toArray')) {
                try {
                    $value = $value->toArray();
                } catch (\Throwable $e) {
                    return null;
                }
            } elseif ($value instanceof \JsonSerializable) {
                $value = $value->jsonSerialize();
            } else {
                $value = get_object_vars($value);
            }
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (is_array($v) || is_object($v)) {
                    $value[$k] = $this->forceNormalizeCacheValue($v, $depth + 1);
                }
            }
        }
        return $value;
    }
}
