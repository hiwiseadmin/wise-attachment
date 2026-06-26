<?php
declare(strict_types=1);

/**
 * 图片处理器
 *
 * 基于 GD 扩展，提供图片裁剪、缩放、旋转等功能。
 * 支持链式操作和尺寸白名单校验。
 *
 * 使用示例：
 * ```php
 * $processor = new ImageProcessor();
 * $processor->load('/path/to/image.jpg')
 *     ->resize(800, 600)
 *     ->quality(90)
 *     ->save('/path/to/output.jpg');
 * ```
 */
namespace wise\attachment\tools;

use think\facade\Config;

class ImageProcessor
{
    /**
     * 源图片路径
     *
     * @var string|null
     */
    protected $sourcePath = null;

    /**
     * 源图片 GD 资源
     *
     * @var \GdImage|resource|null
     */
    protected $sourceImage = null;

    /**
     * 处理后的图片 GD 资源
     *
     * @var \GdImage|resource|null
     */
    protected $processedImage = null;

    /**
     * 源图片宽度
     *
     * @var int
     */
    protected $sourceWidth = 0;

    /**
     * 源图片高度
     *
     * @var int
     */
    protected $sourceHeight = 0;

    /**
     * 源图片 MIME 类型
     *
     * @var string
     */
    protected $sourceMime = '';

    /**
     * 是否为 JPEG 格式
     *
     * @var bool
     */
    protected $isJpeg = false;

    /**
     * 输出质量 (0-100)
     *
     * @var int
     */
    protected $quality = 85;

    /**
     * 全局配置
     *
     * @var array
     */
    protected $config;

    /** @var bool 是否安装了 wise-image 包 */
    protected $hasWiseImage = false;

    /** @var object|null wise-image ImageManager 懒加载 */
    protected $wiseImageManager = null;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->config  = Config::get('wise-attachment.image', []);
        $this->quality = (int) ($this->config['default_quality'] ?? 85);
        $this->hasWiseImage = class_exists('\\wise\\image\\ImageManager');
    }

    /**
     * 加载图片
     *
     * @param string $path 图片路径
     * @return $this
     * @throws \RuntimeException 文件不存在或不是有效图片时
     */
    public function load(string $path): self
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("图片文件不存在: {$path}");
        }

        $info = @getimagesize($path);
        if (!$info) {
            throw new \RuntimeException("无效的图片文件: {$path}");
        }

        $this->sourceWidth  = $info[0];
        $this->sourceHeight = $info[1];
        $this->sourceMime   = $info['mime'];
        $this->sourcePath   = $path;
        $this->isJpeg       = ($this->sourceMime === 'image/jpeg');

        // 尺寸上限检查
        $maxWidth  = (int) ($this->config['max_width'] ?? 4000);
        $maxHeight = (int) ($this->config['max_height'] ?? 4000);
        if ($this->sourceWidth > $maxWidth || $this->sourceHeight > $maxHeight) {
            throw new \RuntimeException("图片尺寸超过最大限制 ({$maxWidth}x{$maxHeight})");
        }

        // 根据 MIME 类型加载图片
        switch ($this->sourceMime) {
            case 'image/jpeg':
                $this->sourceImage = @imagecreatefromjpeg($path);
                break;
            case 'image/png':
                $this->sourceImage = @imagecreatefrompng($path);
                if ($this->sourceImage) {
                    imagealphablending($this->sourceImage, false);
                    imagesavealpha($this->sourceImage, true);
                }
                break;
            case 'image/gif':
                $this->sourceImage = @imagecreatefromgif($path);
                break;
            case 'image/webp':
                $this->sourceImage = @imagecreatefromwebp($path);
                break;
            case 'image/bmp':
                // PHP 7.2+ 原生支持 imagecreatefrombmp
                if (function_exists('imagecreatefrombmp')) {
                    $this->sourceImage = @imagecreatefrombmp($path);
                } else {
                    throw new \RuntimeException("当前 PHP 版本不支持 BMP 格式处理");
                }
                break;
            default:
                throw new \RuntimeException("不支持的图片类型: {$this->sourceMime}");
        }

        if (!$this->sourceImage) {
            throw new \RuntimeException("加载图片失败: {$path}");
        }

        $this->processedImage = $this->sourceImage;

        return $this;
    }

    /**
     * 等比缩放图片
     *
     * @param int|null $width  目标宽度
     * @param int|null $height 目标高度
     * @return $this
     */
    public function resize(?int $width = null, ?int $height = null): self
    {
        if (!$this->processedImage) {
            return $this;
        }

        $width  = $width ?? 0;
        $height = $height ?? 0;

        if ($width <= 0 && $height <= 0) {
            return $this;
        }

        // 等比缩放
        if ($width > 0 && $height > 0) {
            $ratio     = min($width / $this->sourceWidth, $height / $this->sourceHeight);
            $newWidth  = (int) ($this->sourceWidth * $ratio);
            $newHeight = (int) ($this->sourceHeight * $ratio);
        } elseif ($width > 0) {
            $ratio     = $width / $this->sourceWidth;
            $newWidth  = $width;
            $newHeight = (int) ($this->sourceHeight * $ratio);
        } else {
            $ratio     = $height / $this->sourceHeight;
            $newWidth  = (int) ($this->sourceWidth * $ratio);
            $newHeight = $height;
        }

        $this->doResize($newWidth, $newHeight);

        return $this;
    }

    /**
     * 居中裁剪图片
     *
     * @param int $width  目标宽度
     * @param int $height 目标高度
     * @return $this
     */
    public function crop(int $width, int $height): self
    {
        if (!$this->processedImage || $width <= 0 || $height <= 0) {
            return $this;
        }

        // 先按比例缩放到能覆盖目标尺寸的最小尺寸
        $ratio        = max($width / $this->sourceWidth, $height / $this->sourceHeight);
        $scaledWidth  = (int) ($this->sourceWidth * $ratio);
        $scaledHeight = (int) ($this->sourceHeight * $ratio);

        $scaledImg = imagecreatetruecolor($scaledWidth, $scaledHeight);
        $this->preserveAlpha($scaledImg);
        imagecopyresampled($scaledImg, $this->sourceImage, 0, 0, 0, 0, $scaledWidth, $scaledHeight, $this->sourceWidth, $this->sourceHeight);

        // 居中裁剪
        $cropX = (int) (($scaledWidth - $width) / 2);
        $cropY = (int) (($scaledHeight - $height) / 2);

        $croppedImg = imagecreatetruecolor($width, $height);
        $this->preserveAlpha($croppedImg);
        imagecopyresampled($croppedImg, $scaledImg, 0, 0, $cropX, $cropY, $width, $height, $width, $height);

        imagedestroy($scaledImg);
        $this->destroyProcessed();
        $this->processedImage = $croppedImg;

        return $this;
    }

    /**
     * 旋转图片
     *
     * @param float $angle 旋转角度（逆时针，度）
     * @return $this
     */
    public function rotate(float $angle): self
    {
        if (!$this->processedImage) {
            return $this;
        }

        $rotated = imagerotate($this->processedImage, $angle, 0);
        if ($rotated) {
            $this->destroyProcessed();
            $this->processedImage = $rotated;
        }

        return $this;
    }

    /**
     * 设置输出质量
     *
     * @param int $quality 0-100
     * @return $this
     */
    public function quality(int $quality): self
    {
        $this->quality = max(0, min(100, $quality));
        return $this;
    }

    /**
     * 保存处理后的图片
     *
     * @param string|null $path   保存路径，null 则覆盖源文件
     * @param string|null $format 输出格式 (jpg|png|gif|webp)
     * @return string 实际保存路径
     * @throws \RuntimeException
     */
    public function save(?string $path = null, ?string $format = null): string
    {
        if (!$this->processedImage) {
            throw new \RuntimeException("没有已加载的图片可供保存");
        }

        $savePath = $path ?? $this->sourcePath;
        $ext      = $format ?? strtolower(pathinfo($savePath, PATHINFO_EXTENSION));

        // 确保保存目录存在
        $saveDir = dirname($savePath);
        if (!is_dir($saveDir)) {
            mkdir($saveDir, 0755, true);
        }

        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                imageinterlace($this->processedImage, true); // 渐进式 JPEG
                imagejpeg($this->processedImage, $savePath, $this->quality);
                break;
            case 'png':
                // PNG 质量 0-9（GD 的 PNG 压缩级别，0 为无压缩）
                $pngQuality = (int) round(9 - ($this->quality / 100) * 9);
                imagepng($this->processedImage, $savePath, $pngQuality);
                break;
            case 'gif':
                imagegif($this->processedImage, $savePath);
                break;
            case 'webp':
                imagewebp($this->processedImage, $savePath, $this->quality);
                break;
            default:
                imagejpeg($this->processedImage, $savePath, $this->quality);
                break;
        }

        return $savePath;
    }

    /**
     * 处理图片（推荐的外部调用入口）
     *
     * @param string $sourcePath 源图片路径
     * @param array  $options    处理参数:
     *   - width:   int    目标宽度
     *   - height:  int    目标高度
     *   - size:    string 预设尺寸 key（使用白名单配置）
     *   - mode:    string 缩放模式: fit(等比) | crop(居中裁剪) | fill(填充) | exact(拉伸)
     *   - quality: int    输出质量
     * @return string 处理后的图片路径（临时文件）
     * @throws \RuntimeException
     */
    public function process(string $sourcePath, array $options = []): string
    {
        // 预设尺寸解析
        if (!empty($options['size'])) {
            $sizes = $this->config['whitelist_sizes'][$options['size']] ?? null;
            if ($sizes) {
                $options = array_merge($sizes, $options);
            }
        }

        // 尺寸白名单校验
        $w = (int) ($options['width'] ?? 0);
        $h = (int) ($options['height'] ?? 0);
        if ($w > 0 && $h > 0) {
            if (!$this->isSizeAllowed($w, $h)) {
                throw new \RuntimeException("图片尺寸 ({$w}x{$h}) 不在白名单中");
            }
        }

        $this->load($sourcePath);

        // 执行操作链
        $mode   = $options['mode'] ?? 'fit';
        $width  = (int) ($options['width'] ?? 0);
        $height = (int) ($options['height'] ?? 0);

        if ($width > 0 && $height > 0) {
            switch ($mode) {
                case 'crop':
                    $this->crop($width, $height);
                    break;
                default:
                    $this->resize($width, $height);
                    break;
            }
        } elseif ($width > 0) {
            $this->resize($width);
        } elseif ($height > 0) {
            $this->resize(null, $height);
        }

        if (!empty($options['quality'])) {
            $this->quality((int) $options['quality']);
        }

        // 水印处理
        if (!empty($options['watermark_text'])) {
            $watermarkOpts = array_intersect_key($options, array_flip(['watermark_position', 'watermark_font_size', 'watermark_color', 'watermark_opacity', 'watermark_offset_x', 'watermark_offset_y']));
            $wmOptions = [];
            foreach ($watermarkOpts as $k => $v) {
                $wmOptions[str_replace('watermark_', '', $k)] = $v;
            }
            $this->textWatermark($options['watermark_text'], $wmOptions);
        }
        if (!empty($options['watermark_image'])) {
            $watermarkOpts = array_intersect_key($options, array_flip(['watermark_position', 'watermark_opacity', 'watermark_offset_x', 'watermark_offset_y', 'watermark_scale']));
            $wmOptions = [];
            foreach ($watermarkOpts as $k => $v) {
                $wmOptions[str_replace('watermark_', '', $k)] = $v;
            }
            $this->imageWatermark($options['watermark_image'], $wmOptions);
        }

        // 生成临时输出路径
        $outputPath = $sourcePath . '_' . md5(serialize($options)) . '_' . ($options['size'] ?? 'custom') . '.jpg';
        return $this->save($outputPath);
    }

    /**
     * 获取图片信息
     *
     * @return array{width: int, height: int, mime: string}
     */
    public function getInfo(): array
    {
        return [
            'width'  => $this->sourceWidth,
            'height' => $this->sourceHeight,
            'mime'   => $this->sourceMime,
        ];
    }

    /**
     * 检查尺寸是否在白名单内
     *
     * @param int $width  宽度
     * @param int $height 高度
     * @return bool
     */
    protected function isSizeAllowed(int $width, int $height): bool
    {
        $whitelist = $this->config['whitelist_sizes'] ?? [];
        if (empty($whitelist)) {
            return true; // 无白名单配置时默认允许
        }

        foreach ($whitelist as $size) {
            $w = (int) ($size['width'] ?? 0);
            $h = (int) ($size['height'] ?? 0);
            if ($w === $width && $h === $height) {
                return true;
            }
        }
        return false;
    }

    /**
     * 执行缩放
     *
     * @param int $newWidth  新宽度
     * @param int $newHeight 新高度
     * @return void
     */
    protected function doResize(int $newWidth, int $newHeight): void
    {
        $resizedImg = imagecreatetruecolor($newWidth, $newHeight);
        $this->preserveAlpha($resizedImg);
        imagecopyresampled($resizedImg, $this->processedImage, 0, 0, 0, 0, $newWidth, $newHeight, $this->sourceWidth, $this->sourceHeight);

        $this->destroyProcessed();
        $this->processedImage = $resizedImg;
    }

    /**
     * 保留透明通道（仅对 PNG/GIF 等需要透明的格式调用）
     *
     * @param \GdImage|resource $img GD 图像资源
     * @return void
     */
    protected function preserveAlpha($img): void
    {
        // 对 JPEG 不需要保留透明通道
        if ($this->isJpeg) {
            return;
        }

        imagealphablending($img, false);
        imagesavealpha($img, true);
    }

    /**
     * 销毁已处理的图片资源（如果与源图片不同）
     *
     * @return void
     */
    protected function destroyProcessed(): void
    {
        if ($this->processedImage !== null && $this->processedImage !== $this->sourceImage) {
            imagedestroy($this->processedImage);
        }
    }

    /**
     * 获取 wise-image 管理器（如果可用）
     */
    protected function getWiseImageManager(): ?object
    {
        if (!$this->hasWiseImage) {
            return null;
        }
        if ($this->wiseImageManager === null && class_exists('\\wise\\image\\ImageManager')) {
            $wiseConfig = Config::get('wise-image', []);
            $this->wiseImageManager = new \wise\image\ImageManager($wiseConfig);
        }
        return $this->wiseImageManager;
    }

    /**
     * 是否可用 wise-image
     *
     * @return bool
     */
    public function isWiseImageAvailable(): bool
    {
        return $this->hasWiseImage;
    }

    /**
     * 使用 wise-image 增强处理（当可用时自动调用）
     *
     * 注意：当 wise-image 可用时，建议直接使用 wise\image\facade\Image facade
     * 本方法提供向后兼容的桥接
     *
     * @param string $sourcePath 源图片路径
     * @param array  $options    处理参数（与 process() 相同格式）
     * @return string 处理后的图片路径
     */
    public function processEnhanced(string $sourcePath, array $options = []): string
    {
        if (!$this->hasWiseImage) {
            // 回退到原 process 方法
            return $this->process($sourcePath, $options);
        }

        $manager = $this->getWiseImageManager();
        if ($manager === null) {
            return $this->process($sourcePath, $options);
        }

        try {
            $manager->open($sourcePath);

            $width  = (int)($options['width'] ?? 0);
            $height = (int)($options['height'] ?? 0);
            $quality = (int)($options['quality'] ?? $this->config['default_quality'] ?? 85);

            if ($width > 0 || $height > 0) {
                $mode = $options['mode'] ?? 'fit';
                $manager->addOperation('resize', [
                    'width'  => $width ?: null,
                    'height' => $height ?: null,
                    'mode'   => $mode,
                ]);
            }

            if (!empty($options['watermark_text'])) {
                $manager->addOperation('watermark', [
                    'text'     => $options['watermark_text'],
                    'position' => $options['watermark_position'] ?? 'bottom-right',
                    'font_size'=> $options['watermark_font_size'] ?? 16,
                    'color'    => $options['watermark_color'] ?? '#ffffff',
                    'opacity'  => $options['watermark_opacity'] ?? 50,
                ]);
            }

            if (isset($options['quality'])) {
                $manager->addOperation('quality', ['quality' => $quality]);
            }

            $outputPath = $sourcePath . '_enhanced_' . md5(serialize($options)) . '.jpg';
            return $manager->save($outputPath, 'jpg');
        } catch (\Throwable $e) {
            // wise-image 处理失败，回退到 GD
            return $this->process($sourcePath, $options);
        }
    }

    /**
     * 添加文字水印
     *
     * @param string $text      水印文字
     * @param array  $options   可选参数:
     *   - position: string  位置: top-left|top|top-right|left|center|right|bottom-left|bottom|bottom-right (默认 bottom-right)
     *   - font_size: int    字体大小 (默认 16)
     *   - color:    string  颜色 hex (默认 #ffffff)
     *   - opacity:  int     透明度 0-100 (默认 50, GD 无原生透明度支持)
     *   - offset_x: int     水平偏移 (默认 10)
     *   - offset_y: int     垂直偏移 (默认 10)
     *   - angle:    int     旋转角度 (默认 0，仅用于对角线效果)
     * @return $this
     */
    public function textWatermark(string $text, array $options = []): self
    {
        if (!$this->processedImage || empty($text)) {
            return $this;
        }

        $position = $options['position'] ?? 'bottom-right';
        $fontSize = (int)($options['font_size'] ?? 16);
        $color    = $options['color'] ?? '#ffffff';
        $opacity  = (int)($options['opacity'] ?? 50);
        $offsetX  = (int)($options['offset_x'] ?? 10);
        $offsetY  = (int)($options['offset_y'] ?? 10);
        $angle    = (int)($options['angle'] ?? 0);

        $canvasW = imagesx($this->processedImage);
        $canvasH = imagesy($this->processedImage);

        // 尝试查找 TTF 字体
        $fontFile = $this->findTtfFont();

        if ($fontFile !== null) {
            // 使用 TTF 字体
            $rgba = $this->hexToRgba($color, $opacity);
            $textColor = imagecolorallocatealpha($this->processedImage, $rgba[0], $rgba[1], $rgba[2], $rgba[3]);

            // 计算文本尺寸
            $bbox = imagettfbbox($fontSize, $angle, $fontFile, $text);
            $textW = abs($bbox[2] - $bbox[0]);
            $textH = abs($bbox[5] - $bbox[3]);

            [$x, $y] = $this->calculatePosition($position, $textW, $textH, $canvasW, $canvasH, $offsetX, $offsetY, 'ttf');

            imagettftext($this->processedImage, $fontSize, $angle, $x, $y, $textColor, $fontFile, $text);
        } else {
            // 回退到 GD 内置字体
            $gdFont = 5;
            $textW = imagefontwidth($gdFont) * strlen($text);
            $textH = imagefontheight($gdFont);

            [$x, $y] = $this->calculatePosition($position, $textW, $textH, $canvasW, $canvasH, $offsetX, $offsetY, 'gd');

            $rgba = $this->hexToRgba($color, $opacity);
            $textColor = imagecolorallocatealpha($this->processedImage, $rgba[0], $rgba[1], $rgba[2], $rgba[3]);

            imagestring($this->processedImage, $gdFont, $x, $y, $text, $textColor);
        }

        return $this;
    }

    /**
     * 添加图片水印（如 Logo）
     *
     * @param string $watermarkPath 水印图片路径
     * @param array  $options       可选参数:
     *   - position: string  位置 (默认 bottom-right)
     *   - opacity:  int     透明度 0-100 (默认 30)
     *   - offset_x: int     水平偏移 (默认 10)
     *   - offset_y: int     垂直偏移 (默认 10)
     *   - scale:    float   缩放比例 0.1-1.0 (默认 0.3，相对于原图宽度)
     *   - width:    int     固定水印宽度（优先于 scale）
     * @return $this
     */
    public function imageWatermark(string $watermarkPath, array $options = []): self
    {
        if (!$this->processedImage || !file_exists($watermarkPath)) {
            return $this;
        }

        $position = $options['position'] ?? 'bottom-right';
        $opacity  = (int)($options['opacity'] ?? 30);
        $offsetX  = (int)($options['offset_x'] ?? 10);
        $offsetY  = (int)($options['offset_y'] ?? 10);
        $scale    = (float)($options['scale'] ?? 0.3);

        $canvasW = imagesx($this->processedImage);
        $canvasH = imagesy($this->processedImage);

        // 加载水印图片
        $wmInfo = @getimagesize($watermarkPath);
        if (!$wmInfo) {
            return $this;
        }

        switch ($wmInfo['mime']) {
            case 'image/jpeg':
                $wmImage = @imagecreatefromjpeg($watermarkPath);
                break;
            case 'image/png':
                $wmImage = @imagecreatefrompng($watermarkPath);
                break;
            case 'image/gif':
                $wmImage = @imagecreatefromgif($watermarkPath);
                break;
            case 'image/webp':
                $wmImage = @imagecreatefromwebp($watermarkPath);
                break;
            default:
                return $this;
        }

        if (!$wmImage) {
            return $this;
        }

        $wmSrcW = imagesx($wmImage);
        $wmSrcH = imagesy($wmImage);

        // 计算水印尺寸
        if (!empty($options['width']) && (int)$options['width'] > 0) {
            $wmW = (int)$options['width'];
            $wmH = (int)($wmW * $wmSrcH / $wmSrcW);
        } else {
            $wmW = (int)($canvasW * $scale);
            $wmH = (int)($wmW * $wmSrcH / $wmSrcW);
        }

        // 计算位置
        [$x, $y] = $this->calculatePosition($position, $wmW, $wmH, $canvasW, $canvasH, $offsetX, $offsetY, 'image');

        // 如果需要缩放水印，先缩放到目标尺寸
        if ($wmW !== $wmSrcW || $wmH !== $wmSrcH) {
            $scaledWm = imagecreatetruecolor($wmW, $wmH);
            imagealphablending($scaledWm, false);
            imagesavealpha($scaledWm, true);
            imagecopyresampled($scaledWm, $wmImage, 0, 0, 0, 0, $wmW, $wmH, $wmSrcW, $wmSrcH);
            imagedestroy($wmImage);
            $wmImage = $scaledWm;
        }

        // PNG 支持透明度，JPG 不支持
        if ($wmInfo['mime'] === 'image/png') {
            imagecopymerge($this->processedImage, $wmImage, $x, $y, 0, 0, $wmW, $wmH, $opacity);
        } else {
            imagecopy($this->processedImage, $wmImage, $x, $y, 0, 0, $wmW, $wmH);
        }

        imagedestroy($wmImage);

        return $this;
    }

    /**
     * 计算九宫格位置
     *
     * @param string $position 位置名
     * @param int    $elemW    元素宽度
     * @param int    $elemH    元素高度
     * @param int    $canvasW  画布宽度
     * @param int    $canvasH  画布高度
     * @param int    $offsetX  水平偏移
     * @param int    $offsetY  垂直偏移
     * @param string $type     类型: ttf|gd|image
     * @return array [x, y]
     */
    protected function calculatePosition(string $position, int $elemW, int $elemH, int $canvasW, int $canvasH, int $offsetX, int $offsetY, string $type = 'image'): array
    {
        $centerX = (int)(($canvasW - $elemW) / 2);
        $centerY = (int)(($canvasH - $elemH) / 2);

        // TTF 文本的 y 坐标需要特殊处理（从基线开始）
        if ($type === 'ttf') {
            $topY    = $offsetY + $elemH;
            $centerY = (int)(($canvasH + $elemH) / 2);
            $bottomY = $canvasH - $offsetY;

            switch ($position) {
                case 'top-left':     return [$offsetX, $topY];
                case 'top':          return [$centerX, $topY];
                case 'top-right':    return [$canvasW - $elemW - $offsetX, $topY];
                case 'left':         return [$offsetX, $centerY];
                case 'center':       return [$centerX, $centerY];
                case 'right':        return [$canvasW - $elemW - $offsetX, $centerY];
                case 'bottom-left':  return [$offsetX, $bottomY];
                case 'bottom':       return [$centerX, $bottomY];
                case 'bottom-right': return [$canvasW - $elemW - $offsetX, $bottomY];
                default:             return [$canvasW - $elemW - $offsetX, $bottomY];
            }
        }

        // GD 内置字体和图片水印的坐标
        $bottomY = $canvasH - $elemH - $offsetY;

        switch ($position) {
            case 'top-left':     return [$offsetX, $offsetY];
            case 'top':          return [$centerX, $offsetY];
            case 'top-right':    return [$canvasW - $elemW - $offsetX, $offsetY];
            case 'left':         return [$offsetX, $centerY];
            case 'center':       return [$centerX, $centerY];
            case 'right':        return [$canvasW - $elemW - $offsetX, $centerY];
            case 'bottom-left':  return [$offsetX, $bottomY];
            case 'bottom':       return [$centerX, $bottomY];
            case 'bottom-right': return [$canvasW - $elemW - $offsetX, $bottomY];
            default:             return [$canvasW - $elemW - $offsetX, $bottomY];
        }
    }

    /**
     * 查找 TTF 字体文件
     *
     * @return string|null 字体路径，未找到返回 null
     */
    protected function findTtfFont(): ?string
    {
        // 1. 检查配置中指定的字体路径
        $configPath = $this->config['font_path'] ?? '';
        if (!empty($configPath) && file_exists($configPath)) {
            return $configPath;
        }

        // 2. 尝试查找通用字体路径
        $possibleFonts = [];
        if (PHP_OS_FAMILY === 'Windows') {
            $possibleFonts = [
                'C:/Windows/Fonts/simhei.ttf',
                'C:/Windows/Fonts/simsun.ttc',
                'C:/Windows/Fonts/msyh.ttc',
                'C:/Windows/Fonts/arial.ttf',
            ];
        } else {
            $possibleFonts = [
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
                '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
                '/usr/share/fonts/TTF/DejaVuSans.ttf',
            ];
        }

        foreach ($possibleFonts as $font) {
            if (file_exists($font)) {
                return $font;
            }
        }

        return null;
    }

    /**
     * Hex 颜色转 RGBA 数组
     *
     * @param string $hex    颜色 hex (#RRGGBB 或 #RRGGBBAA)
     * @param int    $opacity 透明度 0-100 (仅用于不包含 AA 的 hex)
     * @return array [r, g, b, alpha]
     */
    protected function hexToRgba(string $hex, int $opacity = 50): array
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 8) {
            // #RRGGBBAA
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
            $a = (int)(127 - (hexdec(substr($hex, 6, 2)) / 255) * 127);
        } else {
            // #RRGGBB
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
            // GD alpha: 0 (不透明) ~ 127 (全透明)
            $a = (int)(127 - ($opacity / 100) * 127);
        }

        return [$r, $g, $b, $a];
    }

    /**
     * 释放资源
     */
    public function __destruct()
    {
        // 先释放 processedImage（如果不是 sourceImage 本身）
        if ($this->processedImage !== null && $this->processedImage !== $this->sourceImage) {
            imagedestroy($this->processedImage);
        }
        // 再释放 sourceImage
        if ($this->sourceImage !== null) {
            imagedestroy($this->sourceImage);
        }
        $this->sourceImage    = null;
        $this->processedImage = null;
    }
}
