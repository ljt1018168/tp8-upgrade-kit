<?php
// +----------------------------------------------------------------------
// | File 缓存驱动（TP8 适配版）
// +----------------------------------------------------------------------
// | 与框架自带 think\cache\driver\File 的唯一差别：
// | 写入前把 Collection / Paginator 归一化成数组，并在序列化失败时降级重试。
// |
// | 覆盖场景：`Cache::set()`（默认 store=file）直接写入查询结果集，
// | 例如 Cache::set('key', Model::where(...)->select())。
// +----------------------------------------------------------------------

namespace app\common\cache;

class File extends \think\cache\driver\File
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
