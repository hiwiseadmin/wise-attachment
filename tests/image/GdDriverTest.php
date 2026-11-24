<?php

declare(strict_types=1);

namespace wise\image\tests;

use PHPUnit\Framework\TestCase;
use wise\image\driver\GdDriver;
use wise\image\exception\ImageException;

/**
 * GdDriver 全面测试
 *
 * 验证 GD 驱动所有方法的正确行为，包括：
 * - 创建画布、打开图片
 * - 缩放、裁剪、旋转
 * - 水印（图片 + 文字）
 * - 滤镜效果（模糊、亮度、对比度）
 * - 输出、保存
 * - 错误处理
 */
class GdDriverTest extends TestCase
{
    private GdDriver $driver;
    private string $fixturesDir;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->driver = new GdDriver();
        $this->fixturesDir = __DIR__ . '/fixtures';
        $this->tmpDir = sys_get_temp_dir() . '/wise-image-tests';

        if (!is_dir($this->fixturesDir)) {
            mkdir($this->fixturesDir, 0755, true);
        }
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->driver)) {
            $this->driver->destroy();
        }
    }


    /**
     * 创建测试用的 PNG 图片并返回路径
     */
    private function createTestPng(int $width = 200, int $height = 100): string
    {
        $path = $this->fixturesDir . '/test_' . $width . 'x' . $height . '.png';
        if (!file_exists($path)) {
            $img = imagecreatetruecolor($width, $height);
            if ($img === false) {
                $this->markTestSkipped('GD 无法创建测试图片');
            }
            $red = imagecolorallocate($img, 255, 0, 0);
            if ($red !== false) {
                imagefill($img, 0, 0, $red);
            }
            $blue = imagecolorallocate($img, 0, 0, 255);
            if ($blue !== false) {
                imagefilledrectangle($img, 50, 20, 150, 80, $blue);
            }
            imagepng($img, $path);
            imagedestroy($img);
        }
        return $path;
    }

    /**
     * 创建水印用的小 PNG
     */
    private function createTestWatermarkPng(int $width = 60, int $height = 40): string
    {
        $path = $this->fixturesDir . '/watermark_' . $width . 'x' . $height . '.png';
        if (!file_exists($path)) {
            $img = imagecreatetruecolor($width, $height);
            if ($img === false) {
                $this->markTestSkipped('GD 无法创建水印图片');
            }
            imagealphablending($img, false);
            imagesavealpha($img, true);
            $trans = imagecolorallocatealpha($img, 0, 0, 0, 127);
            if ($trans !== false) {
                imagefill($img, 0, 0, $trans);
            }
            $green = imagecolorallocatealpha($img, 0, 255, 0, 0);
            if ($green !== false) {
                imagefilledrectangle($img, 0, 0, $width, $height, $green);
            }
            imagepng($img, $path);
            imagedestroy($img);
        }
        return $path;
    }


    public function testCreateCanvasSuccess(): void
    {
        $this->driver->create(300, 200);

        $this->assertSame(300, $this->driver->getWidth());
        $this->assertSame(200, $this->driver->getHeight());
        $this->assertSame('image/png', $this->driver->getMime());
    }

    public function testCreateCanvasReplacesExisting(): void
    {
        $this->driver->create(100, 100);
        $this->driver->create(50, 50);

        $this->assertSame(50, $this->driver->getWidth());
        $this->assertSame(50, $this->driver->getHeight());
    }


    public function testOpenPngSuccess(): void
    {
        $path = $this->createTestPng(200, 100);
        $this->driver->open($path);

        $this->assertSame(200, $this->driver->getWidth());
        $this->assertSame(100, $this->driver->getHeight());
        $this->assertSame('image/png', $this->driver->getMime());
    }

    public function testOpenFileNotFoundThrowsException(): void
    {
        $this->expectException(ImageException::class);
        $this->expectExceptionCode(120002);

        $this->driver->open('/nonexistent/file.png');
    }

    public function testOpenInvalidFormatThrowsException(): void
    {
        $badPath = $this->fixturesDir . '/not_image.txt';
        file_put_contents($badPath, 'this is not an image');

        $this->expectException(ImageException::class);
        $this->expectExceptionCode(120003);

        try {
            $this->driver->open($badPath);
        } finally {
            @unlink($badPath);
        }
    }


    public function testResizeLfitMode(): void
    {
        $this->driver->create(400, 200);
        $this->driver->resize(200, 150, 'lfit');

        $this->assertLessThanOrEqual(200, $this->driver->getWidth());
        $this->assertLessThanOrEqual(150, $this->driver->getHeight());
    }

    public function testResizeFixedMode(): void
    {
        $this->driver->create(400, 200);
        $this->driver->resize(100, 100, 'fixed');

        $this->assertSame(100, $this->driver->getWidth());
        $this->assertSame(100, $this->driver->getHeight());
    }

    public function testResizeFillMode(): void
    {
        $this->driver->create(400, 200);
        $this->driver->resize(100, 100, 'fill');

        $this->assertSame(100, $this->driver->getWidth());
        $this->assertSame(100, $this->driver->getHeight());
    }

    public function testResizeRatioMode(): void
    {
        $this->driver->create(400, 200);
        $this->driver->resize(50, 0, 'ratio');

        $this->assertSame(200, $this->driver->getWidth());
        $this->assertSame(100, $this->driver->getHeight());
    }

    public function testResizeInvalidModeThrowsException(): void
    {
        $this->driver->create(100, 100);

        $this->expectException(ImageException::class);
        $this->expectExceptionCode(120005);

        $this->driver->resize(50, 50, 'invalid_mode');
    }

    public function testResizeWithoutImageThrowsException(): void
    {
        $this->expectException(ImageException::class);
        $this->expectExceptionCode(120009);

        $this->driver->resize(100, 100, 'fixed');
    }


    public function testCropSuccess(): void
    {
        $this->driver->create(200, 200);
        $this->driver->crop(100, 100, 50, 50);

        $this->assertSame(100, $this->driver->getWidth());
        $this->assertSame(100, $this->driver->getHeight());
    }

    public function testCropBoundaryClamping(): void
    {
        $this->driver->create(200, 200);
        $this->driver->crop(100, 100, 199, 199);

        $this->assertSame(100, $this->driver->getWidth());
        $this->assertSame(100, $this->driver->getHeight());
    }


    public function testImageWatermarkSuccess(): void
    {
        $this->driver->create(300, 200);
        $wmPath = $this->createTestWatermarkPng();

        $this->driver->watermark($wmPath, 'se', 50);

        $this->assertSame(300, $this->driver->getWidth());
        $this->assertSame(200, $this->driver->getHeight());
    }

    public function testImageWatermarkFileNotFoundThrowsException(): void
    {
        $this->driver->create(100, 100);

        $this->expectException(ImageException::class);
        $this->expectExceptionCode(120002);

        $this->driver->watermark('/nonexistent/wm.png', 'se', 50);
    }


    public function testTextWatermarkWithBuiltinFont(): void
    {
        $this->driver->create(300, 200);

        $this->driver->textWatermark('Test Watermark', [
            'font' => null,
            'size' => 20,
            'color' => '#FFFFFF',
            'position' => 'se',
            'opacity' => 50,
            'angle' => 0,
        ]);

        $this->assertSame(300, $this->driver->getWidth());
    }

    public function testTextWatermarkDifferentPositions(): void
    {
        $positions = ['nw', 'n', 'ne', 'w', 'c', 'e', 'sw', 's', 'se'];
        foreach ($positions as $pos) {
            $driver = new GdDriver();
            $driver->create(200, 200);
            $driver->textWatermark('Test', [
                'font' => null,
                'size' => 12,
                'color' => '#FF0000',
                'position' => $pos,
                'opacity' => 80,
                'angle' => 0,
            ]);
            $this->assertSame(200, $driver->getWidth());
            $driver->destroy();
        }
    }


    public function testRotate90(): void
    {
        $this->driver->create(200, 100);
        $this->driver->rotate(90);

        $this->assertGreaterThan(0, $this->driver->getWidth());
        $this->assertGreaterThan(0, $this->driver->getHeight());
    }

    public function testRotate45(): void
    {
        $this->driver->create(200, 100);
        $this->driver->rotate(45);

        $this->assertGreaterThan(0, $this->driver->getWidth());
        $this->assertGreaterThan(0, $this->driver->getHeight());
    }


    public function testBlur(): void
    {
        $this->driver->create(200, 100);
        $this->driver->blur(10);

        $this->assertSame(200, $this->driver->getWidth());
    }

    public function testBlurRadiusClamped(): void
    {
        $this->driver->create(200, 100);
        $this->driver->blur(200);

        $this->assertSame(200, $this->driver->getWidth());
    }

    public function testBrightness(): void
    {
        $this->driver->create(200, 100);
        $this->driver->brightness(50);

        $this->assertSame(200, $this->driver->getWidth());
    }

    public function testBrightnessNegative(): void
    {
        $this->driver->create(200, 100);
        $this->driver->brightness(-30);

        $this->assertSame(200, $this->driver->getWidth());
    }

    public function testContrast(): void
    {
        $this->driver->create(200, 100);
        $this->driver->contrast(20);

        $this->assertSame(200, $this->driver->getWidth());
    }


    public function testSetQuality(): void
    {
        $this->driver->create(100, 100);
        $this->driver->setQuality(50);

        $this->assertTrue(true);
    }

    public function testSetQualityClamped(): void
    {
        $this->driver->create(100, 100);
        $this->driver->setQuality(150);
        $this->driver->setQuality(-10);

        $this->assertTrue(true);
    }


    public function testGetInfo(): void
    {
        $this->driver->create(300, 200);

        $info = $this->driver->getInfo();
        $this->assertSame(300, $info['width']);
        $this->assertSame(200, $info['height']);
        $this->assertSame('image/png', $info['mime']);
        $this->assertSame('png', $info['format']);
        $this->assertSame('gd', $info['driver']);
    }


    public function testOutputPng(): void
    {
        $this->driver->create(100, 50);
        $data = $this->driver->output('png');

        $this->assertIsString($data);
        $this->assertNotEmpty($data);
        $this->assertStringStartsWith("\x89PNG", $data);
    }

    public function testOutputJpeg(): void
    {
        $this->driver->create(100, 50);
        $data = $this->driver->output('jpeg');

        $this->assertIsString($data);
        $this->assertNotEmpty($data);
        $this->assertStringStartsWith("\xFF\xD8", $data);
    }

    public function testOutputDefaultFormat(): void
    {
        $this->driver->create(100, 50);
        $data = $this->driver->output();

        $this->assertIsString($data);
        $this->assertNotEmpty($data);
    }

    public function testOutputInvalidFormatThrowsException(): void
    {
        $this->driver->create(100, 100);

        $this->expectException(ImageException::class);
        $this->expectExceptionCode(120003);

        $this->driver->output('tiff');
    }


    public function testSavePng(): void
    {
        $this->driver->create(100, 50);
        $savePath = $this->tmpDir . '/output_test.png';

        $result = $this->driver->save($savePath, 'png');

        $this->assertTrue($result);
        $this->assertFileExists($savePath);

        $info = getimagesize($savePath);
        $this->assertNotFalse($info);
        $this->assertSame(100, $info[0]);
        $this->assertSame(50, $info[1]);

        @unlink($savePath);
    }

    public function testSaveJpeg(): void
    {
        $this->driver->create(60, 40);
        $savePath = $this->tmpDir . '/output_test.jpg';

        $result = $this->driver->save($savePath, 'jpeg');

        $this->assertTrue($result);
        $this->assertFileExists($savePath);

        @unlink($savePath);
    }

    public function testSaveAutoCreatesDirectory(): void
    {
        $this->driver->create(50, 50);
        $savePath = $this->tmpDir . '/subdir/auto_created.png';

        $result = $this->driver->save($savePath, 'png');

        $this->assertTrue($result);
        $this->assertFileExists($savePath);

        @unlink($savePath);
        @rmdir($this->tmpDir . '/subdir');
    }


    public function testDestroyClearsState(): void
    {
        $this->driver->create(100, 100);
        $this->driver->destroy();

        $this->assertSame(0, $this->driver->getWidth());
        $this->assertSame(0, $this->driver->getHeight());
    }

    public function testDestroyIsIdempotent(): void
    {
        $this->driver->destroy();
        $this->driver->destroy();

        $this->assertSame(0, $this->driver->getWidth());
    }


    public function testDriverNotReadyOnUnloadedImage(): void
    {
        $this->expectException(ImageException::class);
        $this->expectExceptionCode(120009);

        $this->driver->getWidth();

        $this->driver->save('/tmp/test.png');
    }


    public function testOpenAfterOpenReplacesImage(): void
    {
        $path1 = $this->createTestPng(200, 100);

        $path2 = $this->fixturesDir . '/test_150x75.png';
        if (!file_exists($path2)) {
            $img = imagecreatetruecolor(150, 75);
            imagepng($img, $path2);
            imagedestroy($img);
        }

        $this->driver->open($path1);
        $this->driver->open($path2);

        $this->assertSame(150, $this->driver->getWidth());
        $this->assertSame(75, $this->driver->getHeight());

        @unlink($path2);
    }
}
