<?php
// +----------------------------------------------------------------------
// | 弃用告警过滤服务（TP8 迁移期专用）
// +----------------------------------------------------------------------
// | 背景：
// |   TP8 的 think\initializer\Error::appError() 在
// |   error_reporting() 包含对应级别时会 throw ErrorException。
// |   调试模式（APP_DEBUG=1）下 TP8 会执行 error_reporting(E_ALL)，
// |   于是 vendor 中未适配 PHP 8.4 的第三方库一旦触发
// |   E_DEPRECATED（如阿里云日志 SDK 的隐式可空参数），
// |   就会变成致命异常，把正常业务流程打断。
// |
// | 策略：
// |   - 仅对 **vendor 目录** 内的 E_DEPRECATED / E_USER_DEPRECATED
// |     降级为写日志，不中断请求；
// |   - 业务代码（app/）的弃用仍按 TP8 默认行为抛异常，
// |     确保自己代码的问题不会被掩盖；
// |   - 生产环境（APP_DEBUG=0）TP8 本身已排除 E_DEPRECATED，
// |     本服务在那种场景下等价于空操作。
// |
// | 长期建议：
// |   推动第三方 SDK 升级到 PHP 8.4 兼容版本后，可移除本服务。
// +----------------------------------------------------------------------

namespace app\common\service;

use think\Service;

class DeprecationFilter extends Service
{
    /**
     * 服务启动：在框架错误处理器之上叠加一层过滤
     * @return void
     */
    public function boot(): void
    {
        $previous = null;

        $previous = set_error_handler(function (int $errno, string $errstr, string $errfile = '', int $errline = 0) use (&$previous) {
            $isDeprecated = ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED);

            // 仅降级 vendor 内的弃用告警
            if ($isDeprecated && $this->isVendorFile($errfile)) {
                error_log('[TP8][vendor-deprecated] ' . $errstr . ' @ ' . $errfile . ':' . $errline);
                return true;
            }

            // 其余交回框架处理器（保持原有语义）
            if (is_callable($previous)) {
                return $previous($errno, $errstr, $errfile, $errline);
            }

            return false;
        });
    }

    /**
     * 判断文件是否位于 vendor 目录
     * @param string $file
     * @return bool
     */
    protected function isVendorFile(string $file): bool
    {
        if ($file === '') {
            return false;
        }

        $vendorPath = $this->app->getRootPath() . 'vendor' . DIRECTORY_SEPARATOR;

        return str_starts_with($file, $vendorPath);
    }
}
