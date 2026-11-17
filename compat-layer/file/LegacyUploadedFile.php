<?php
// +----------------------------------------------------------------------
// | TP5.1 上传语义兼容层（TP8 适配）
// +----------------------------------------------------------------------
// | 断层：TP5.1 的 think\File 上传接口是「验证 + move + 惰性取信息」三段式：
// |   $file->validate(['size'=>..,'ext'=>..])->move($dir)
// |   成功返回 File（getSaveName/getInfo/getExtension/getSize），
// |   失败返回 false，错误经 getError() 取回。
// |
// | TP8 的 think\file\UploadedFile 基于 Symfony UploadedFile 重写：
// |   1. validate() 方法不存在（规则无处传）→ Call to undefined method
// |   2. move() 返回 think\File，无 getSaveName()/getInfo()
// |   3. 失败【抛 FileException】而非返回 false，原代码的
// |      `if ($info) {...} else { $this->error($file->getError()); }`
// |      结构会被异常直接击穿成 500
// |
// | 本类完整复刻 TP5.1 语义（验证规则含 checkImg 的图像魔数检测、
// | 保存名 date('Ymd')/md5(microtime) 规则、move 失败返 false），
// | 业务控制器一行不改即可继续工作。
// +----------------------------------------------------------------------

namespace app\common\file;

use think\file\UploadedFile;

class LegacyUploadedFile
{
    /** @var UploadedFile */
    private $file;

    /** @var array 验证规则（TP5.1 validate(['size'=>, 'ext'=>, 'type'=>]) 语义） */
    private $rule = [];

    /** @var string 验证/移动失败时的错误信息 */
    private $error = '';

    /** @var string 相对保存名（date('Ymd') . '/' . md5 名），含扩展名 */
    private $saveName = '';

    /** @var array 原始上传信息（$_FILES 单项，getInfo() 数据源） */
    private $info = [];

    public function __construct(UploadedFile $file)
    {
        $this->file = $file;
        // getInfo() 数据源：$_FILES 原始字段（name/type/size/error...）
        // UploadedFile 只留了 originalName/mime，size 从实例上取。
        $this->info = [
            'name' => $file->getOriginalName(),
            'type' => $file->getOriginalMime(),
            'size' => $file->getSize(),
        ];
    }

    /**
     * 包一层 UploadedFile 上未代理的既有方法（getSize 等 SplFileInfo 原生能力）
     */
    public function __call($method, $args)
    {
        if (method_exists($this->file, $method) || method_exists($this->file, '__call')) {
            return $this->file->{$method}(...$args);
        }
        throw new \BadMethodCallException('Call to undefined method ' . static::class . '::' . $method . '()');
    }

    /** TP5.1 validate()：记录规则并返回自身（链式） */
    public function validate(array $rule = [])
    {
        $this->rule = $rule;
        return $this;
    }

    /**
     * TP5.1 move() 语义：
     *   1. 按规则校验（size/ext/type + 图像魔数），失败返回 false
     *   2. 保存名默认 date('Ymd')/md5(microtime).ext（TP5.1 autoBuildName 默认 rule）
     *   3. 移动文件，任何失败返回 false（不抛异常）
     *   4. 成功返回本对象（getSaveName 等方法可用）
     * @return static|false
     */
    public function move(string $path, $savename = true, bool $replace = true)
    {
        try {
            if (!$this->isValid()) {
                $this->error = 'upload illegal files';
                return false;
            }
            if (!$this->check($this->rule)) {
                return false;
            }

            // 保存名：沿 TP5.1 buildSaveName 语义（true=自动生成）
            if (true === $savename) {
                $ext        = strtolower(pathinfo($this->info['name'], PATHINFO_EXTENSION));
                $this->saveName = date('Ymd') . DIRECTORY_SEPARATOR . md5(microtime(true) . uniqid('', true)) . ($ext ? '.' . $ext : '');
            } else {
                $this->saveName = is_string($savename) ? $savename : $this->info['name'];
            }

            // 目标路径（相对项目根，与 TP5.1 一致：从 CWD 解析）
            $dir = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . dirname($this->saveName);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                $this->error = 'upload write error';
                return false;
            }

            $name = basename($this->saveName);
            $moved = $this->file->move($dir, $name);

            if (!$moved instanceof \think\File) {
                $this->error = 'upload write error';
                return false;
            }
            return $this;
        } catch (\Throwable $e) {
            // TP8 move 失败抛 FileException；TP5.1 语义是返 false + getError()
            $this->error = $e->getMessage();
            return false;
        }
    }

    /** TP5.1 isValid() */
    public function isValid(): bool
    {
        return $this->file->isValid();
    }

    /** TP5.1 check()：size / type / ext / 图像魔数 */
    protected function check(array $rule): bool
    {
        if ((isset($rule['size']) && !$this->checkSize($rule['size']))
            || (isset($rule['type']) && !$this->checkMime($rule['type']))
            || (isset($rule['ext']) && !$this->checkExt($rule['ext']))
            || !$this->checkImg()) {
            return false;
        }
        return true;
    }

    /** 后缀白名单（逗号分隔字符串或数组） */
    protected function checkExt($ext): bool
    {
        if (is_string($ext)) {
            $ext = explode(',', $ext);
        }
        $extension = strtolower(pathinfo($this->info['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $ext)) {
            $this->error = 'extensions to upload is not allowed';
            return false;
        }
        return true;
    }

    /** 图像魔数严格校验（防改后缀的伪图片，TP5.1 checkImg 原语义） */
    protected function checkImg(): bool
    {
        $extension = strtolower(pathinfo($this->info['name'], PATHINFO_EXTENSION));
        $imageTypes = [IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_BMP, 4 /* SWF */, 13 /* WEBP 以 exif 计 */];
        if (in_array($extension, ['gif', 'jpg', 'jpeg', 'bmp', 'png', 'swf'])
            && !in_array($this->getImageType($this->file->getPathname()), $imageTypes)) {
            $this->error = 'illegal image files';
            return false;
        }
        return true;
    }

    protected function getImageType($image)
    {
        if (function_exists('exif_imagetype')) {
            return exif_imagetype($image);
        }
        try {
            $info = getimagesize($image);
            return $info ? $info[2] : false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** 大小上限（字节） */
    protected function checkSize($size): bool
    {
        if ($this->info['size'] > (int) $size) {
            $this->error = 'filesize not match';
            return false;
        }
        return true;
    }

    /** MIME 白名单（逗号分隔字符串或数组） */
    protected function checkMime($mime): bool
    {
        if (is_string($mime)) {
            $mime = explode(',', $mime);
        }
        if (!in_array($this->info['type'], $mime)) {
            $this->error = 'mimetype to upload is not allowed';
            return false;
        }
        return true;
    }

    /** 相对保存名：20261028/0d56cfac1e66a25a1b185839e1e7271c.png */
    public function getSaveName(): string
    {
        return $this->saveName;
    }

    /** 扩展名（小写） */
    public function getExtension(): string
    {
        return strtolower(pathinfo($this->info['name'], PATHINFO_EXTENSION));
    }

    /** TP5.1 getInfo($name) / getInfo() 语义 */
    public function getInfo(?string $name = null)
    {
        if (null === $name) {
            return $this->info;
        }
        return $this->info[$name] ?? null;
    }

    /** 文件大小（字节） */
    public function getSize(): int|false
    {
        return $this->info['size'];
    }

    /** TP5.1 getError() */
    public function getError(): string
    {
        return $this->error;
    }
}
