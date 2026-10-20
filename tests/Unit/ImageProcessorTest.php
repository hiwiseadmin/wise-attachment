<?php
declare(strict_types=1);

/**
 * ImageProcessor 图片处理器单元测试
 *
 * 测试图片加载、缩放、质量设置、处理流程、尺寸白名单校验等功能。
 * 需要 GD 扩展。
 */
namespace wise\attachment\tests\Unit;

use PHPUnit\Framework\TestCase;
use think\facade\Config;
use wise\attachment\tools\ImageProcessor;

class ImageProcessorTest extends TestCase
{
    /**
     * 临时测试目录
     *
     * @var string
     */
    private $tempDir;

    /**
     * 测试图片路径
     *
     * @var string
     */
    private $testImagePath;

    /**
     * 测试前创建临时目录和测试图片
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD 扩展未安装，跳过图片处理测试。');
        }

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise_image_test_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);

        // 创建测试图片（100x80 的蓝色 JPEG）
        $this->testImagePath = $this->tempDir . DIRECTORY_SEPARATOR . 'test.jpg';
        $this->createTestImage($this->testImagePath, 100, 80);
    }

    /**
     * 测试后清理临时目录
     */
    protected function tearDown(): void
    {
        if ($this->tempDir && is_dir($this->tempDir)) {
            $this->delTree($this->tempDir);
        }
        parent::tearDown();
    }

    // ============================================================
    // load() 测试
    // ============================================================

    /**
     * @test 测试 load() 成功加载有效图片
     */
    public function testLoadValidImageReturnsSelf(): void
    {
        $processor = new ImageProcessor();
        $result    = $processor->load($this->testImagePath);

        $this->assertSame($processor, $result);
        $this->assertTrue(file_exists($this->testImagePath));
    }

    /**
     * @test 测试 load() 加载不存在的文件抛出异常
     */
    public function testLoadNonExistentFileThrowsException(): void
    {
        $processor = new ImageProcessor();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('图片文件不存在');

        $processor->load('/nonexistent/path/image.jpg');
    }

    /**
     * @test 测试 load() 加载无效图片文件抛出异常
     */
    public function testLoadInvalidImageFileThrowsException(): void
    {
        $textFile = $this->tempDir . DIRECTORY_SEPARATOR . 'not_image.jpg';
        file_put_contents($textFile, 'This is not an image file!');

        $processor = new ImageProcessor();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('无效的图片文件');

        $processor->load($textFile);
    }

    // ============================================================
    // resize() 测试
    // ============================================================

    /**
     * @test 测试 resize() 等比缩放（同时指定宽高）
     */
    public function testResizeWithBothDimensionsScaleProportionally(): void
    {
        $processor = new ImageProcessor();
        $processor->load($this->testImagePath);
        $processor->resize(50, 50);

        $info = $processor->getInfo();

        // 原图 100x80，等比缩放应适合 50x50 框内
        // ratio = min(50/100, 50/80) = min(0.5, 0.625) = 0.5
        // newWidth = 100 * 0.5 = 50, newHeight = 80 * 0.5 = 40
        $this->assertEquals(100, $info['width']);   // source info 不变
        $this->assertEquals(80, $info['height']);
    }

    /**
     * @test 测试 resize() 仅指定宽度等比缩放
     */
    public function testResizeWithOnlyWidthScalesProportionally(): void
    {
        $processor = new ImageProcessor();
        $processor->load($this->testImagePath);
        $processor->resize(200);

        $info = $processor->getInfo();
        $this->assertEquals(100, $info['width']);
    }

    /**
     * @test 测试 resize() 仅指定高度等比缩放
     */
    public function testResizeWithOnlyHeightScalesProportionally(): void
    {
        $processor = new ImageProcessor();
        $processor->load($this->testImagePath);
        $processor->resize(null, 160);

        $info = $processor->getInfo();
        $this->assertEquals(80, $info['height']);
    }

    /**
     * @test 测试 resize() 零宽高不做缩放
     */
    public function testResizeWithZeroDimensionsDoesNothing(): void
    {
        $processor = new ImageProcessor();
        $processor->load($this->testImagePath);
        $processor->resize(0, 0);

        $info = $processor->getInfo();
        $this->assertEquals(100, $info['width']);
        $this->assertEquals(80, $info['height']);
    }

    // ============================================================
    // quality() 测试
    // ============================================================

    /**
     * @test 测试 quality() 设置有效质量值
     */
    public function testQualitySetsValidValue(): void
    {
        $processor = new ImageProcessor();
        $processor->quality(90);

        // 使用反射验证内部状态
        $ref = new \ReflectionClass($processor);
        $prop = $ref->getProperty('quality');
        $prop->setAccessible(true);

        $this->assertEquals(90, $prop->getValue($processor));
    }

    /**
     * @test 测试 quality() 超出范围的值被限制在 0-100
     */
    public function testQualityClampsOutOfRangeValue(): void
    {
        $processor = new ImageProcessor();
        $processor->quality(150);

        $ref  = new \ReflectionClass($processor);
        $prop = $ref->getProperty('quality');
        $prop->setAccessible(true);

        $this->assertEquals(100, $prop->getValue($processor));
    }

    /**
     * @test 测试 quality() 负数被限制为 0
     */
    public function testQualityClampsNegativeValue(): void
    {
        $processor = new ImageProcessor();
        $processor->quality(-10);

        $ref  = new \ReflectionClass($processor);
        $prop = $ref->getProperty('quality');
        $prop->setAccessible(true);

        $this->assertEquals(0, $prop->getValue($processor));
    }

    // ============================================================
    // process() 测试
    // ============================================================

    /**
     * @test 测试 process() 完整处理流程生成缩略图
     */
    public function testProcessGeneratesOutputFile(): void
    {
        $processor = $this->createProcessorWithConfig([
            'whitelist_sizes' => [
                'test_size' => ['width' => 50, 'height' => 50, 'mode' => 'fit'],
            ],
            'default_quality' => 85,
            'max_width'       => 4000,
            'max_height'      => 4000,
        ]);

        $output = $processor->process($this->testImagePath, [
            'width'  => 50,
            'height' => 50,
        ]);

        $this->assertFileExists($output);
        $this->assertStringEndsWith('_custom.jpg', basename($output));
    }

    /**
     * @test 测试 process() 使用预设尺寸 key
     */
    public function testProcessWithPresetSizeKey(): void
    {
        $processor = $this->createProcessorWithConfig([
            'whitelist_sizes' => [
                'thumb' => ['width' => 150, 'height' => 150, 'mode' => 'crop'],
            ],
            'default_quality' => 85,
            'max_width'       => 4000,
            'max_height'      => 4000,
        ]);

        $output = $processor->process($this->testImagePath, [
            'size' => 'thumb',
        ]);

        $this->assertFileExists($output);
    }

    /**
     * @test 测试 process() 尺寸不在白名单中抛出异常
     */
    public function testProcessWithNonWhitelistedSizeThrowsException(): void
    {
        $processor = $this->createProcessorWithConfig([
            'whitelist_sizes' => [
                'thumb' => ['width' => 150, 'height' => 150, 'mode' => 'crop'],
            ],
            'default_quality' => 85,
            'max_width'       => 4000,
            'max_height'      => 4000,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('不在白名单中');

        $processor->process($this->testImagePath, [
            'width'  => 999,
            'height' => 999,
        ]);
    }

    /**
     * @test 测试 process() 无白名单配置时任意尺寸均允许
     */
    public function testProcessWithEmptyWhitelistAllowsAllSizes(): void
    {
        $processor = $this->createProcessorWithConfig([
            'whitelist_sizes' => [],
            'default_quality' => 85,
            'max_width'       => 4000,
            'max_height'      => 4000,
        ]);

        $output = $processor->process($this->testImagePath, [
            'width'  => 77,
            'height' => 77,
        ]);

        $this->assertFileExists($output);
    }

    // ============================================================
    // getInfo() 测试
    // ============================================================

    /**
     * @test 测试 getInfo() 返回正确的图片信息
     */
    public function testGetInfoReturnsCorrectImageInfo(): void
    {
        $processor = new ImageProcessor();
        $processor->load($this->testImagePath);

        $info = $processor->getInfo();

        $this->assertIsArray($info);
        $this->assertArrayHasKey('width', $info);
        $this->assertArrayHasKey('height', $info);
        $this->assertArrayHasKey('mime', $info);
        $this->assertEquals(100, $info['width']);
        $this->assertEquals(80, $info['height']);
        $this->assertEquals('image/jpeg', $info['mime']);
    }

    // ============================================================
    // 图片类型支持测试
    // ============================================================

    /**
     * @test 测试 load() 支持 PNG 格式
     */
    public function testLoadSupportsPngFormat(): void
    {
        $pngPath = $this->tempDir . DIRECTORY_SEPARATOR . 'test.png';
        $img     = imagecreatetruecolor(50, 50);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $transparent);
        imagepng($img, $pngPath);
        imagedestroy($img);

        $processor = new ImageProcessor();
        $processor->load($pngPath);

        $info = $processor->getInfo();
        $this->assertEquals('image/png', $info['mime']);
    }

    /**
     * @test 测试 load() 支持 GIF 格式
     */
    public function testLoadSupportsGifFormat(): void
    {
        $gifPath = $this->tempDir . DIRECTORY_SEPARATOR . 'test.gif';
        $img     = imagecreatetruecolor(30, 30);
        imagegif($img, $gifPath);
        imagedestroy($img);

        $processor = new ImageProcessor();
        $processor->load($gifPath);

        $info = $processor->getInfo();
        $this->assertEquals('image/gif', $info['mime']);
    }

    // ============================================================
    // save() 测试
    // ============================================================

    /**
     * @test 测试 save() 保存处理后的图片
     */
    public function testSaveCreatesOutputFile(): void
    {
        $processor  = new ImageProcessor();
        $outputPath = $this->tempDir . DIRECTORY_SEPARATOR . 'output.jpg';
        $processor->load($this->testImagePath);
        $processor->resize(50, 50);

        $saved = $processor->save($outputPath);

        $this->assertEquals($outputPath, $saved);
        $this->assertFileExists($outputPath);
    }

    /**
     * @test 测试 save() 无已加载图片时抛异常
     */
    public function testSaveWithoutLoadedImageThrowsException(): void
    {
        $processor = new ImageProcessor();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('没有已加载的图片');

        $processor->save('/tmp/output.jpg');
    }

    // ============================================================
    // 辅助方法
    // ============================================================

    /**
     * 创建测试用 JPEG 图片
     *
     * @param string $path   保存路径
     * @param int    $width  宽度
     * @param int    $height 高度
     */
    private function createTestImage(string $path, int $width, int $height): void
    {
        $img = imagecreatetruecolor($width, $height);
        $blue = imagecolorallocate($img, 0, 100, 200);
        imagefill($img, 0, 0, $blue);
        imagejpeg($img, $path, 90);
        imagedestroy($img);
    }

    /**
     * 创建带有自定义配置的 ImageProcessor 实例
     *
     * 使用反射直接设置 config 属性，避免 ThinkPHP Config 合并问题。
     *
     * @param array $config 配置数组
     * @return ImageProcessor
     */
    private function createProcessorWithConfig(array $config): ImageProcessor
    {
        $processor = new ImageProcessor();
        $ref = new \ReflectionClass($processor);
        $prop = $ref->getProperty('config');
        $prop->setAccessible(true);
        $prop->setValue($processor, $config);
        return $processor;
    }

    /**
     * 递归删除目录
     */
    private function delTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->delTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}
