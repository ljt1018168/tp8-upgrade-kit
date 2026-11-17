<?php
// +----------------------------------------------------------------------
// | Redis 缓存驱动（TP8 适配版）
// +----------------------------------------------------------------------
// | 与框架自带 think\cache\driver\Redis 的唯一差别：
// | 写入前把 Collection / Paginator 归一化成数组，
// | 并在序列化失败时降级重试，避免 "Serialization of 'Closure' is not allowed" 打爆页面。
// |
// | 说明：Session 也复用该 store（config/session.php type=cache store=redis），
// | 会话值是字符串，经过归一化不受影响。
// +----------------------------------------------------------------------

namespace app\common\cache;

class Redis extends \think\cache\driver\Redis
{
    use NormalizesCacheValue;

    public function set($name, $value, $expire = null): bool
    {
        try {
            return parent::set($name, $this->normalizeCacheValue($value), $expire);
        } catch (\Throwable $e) {
            if ($this->isSerializationError($e)) {
                return parent::set($name, $this->forceNormalizeCacheValue($value), $expire);
            }
            throw $e;
        }
    }

    protected function isSerializationError(\Throwable $e): bool
    {
        return stripos($e->getMessage(), 'Serialization of') !== false
            || stripos($e->getMessage(), 'serialize') !== false;
    }
}
