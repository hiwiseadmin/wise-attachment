<?php

declare(strict_types=1);

namespace wise\image\tests;

use PHPUnit\Framework\TestCase;
use wise\image\driver\ImagickDriver;
use wise\image\exception\ErrorCode;
use wise\image\exception\ImageException;

/**
 * ImagickDriver 测试
 *
 * 仅在 Imagick 扩展加载时运行。
 * 未安装 Imagick 时所有测试自动跳过。
 */
class ImagickDriverTest extends TestCase
{
    private ?ImagickDriver $driver = null;

    protected function setUp(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick 扩展未安装，跳过测试。');
        }

        try {
            $this->driver = new ImagickDriver();
        } catch (ImageException $e) {
            $this->markTestSkipped('ImagickDriver 构造失败：' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->driver !== null) {
            $this->driver->destroy();
            $this->driver = null;
        }
    }


    public function testConstructorWhenImagickNotLoadedThrowsException(): void
    {
        if (!extension_loaded('imagick')) {
            $this->expectException(ImageException::class);
            $this->expectExceptionCode(120001);
            new ImagickDriver();
        } else {
            $d = new ImagickDriver();
            $this->assertInstanceOf(ImagickDriver::class, $d);
            $d->destroy();
        }
    }


    public function testOpenRemoteUrlIsRejectedWhenRemoteDisabled(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick 扩展未安装，跳过测试。');
        }

        $this->expectException(ImageException::class);
        $this->expectExceptionCode(ErrorCode::REMOTE_DISABLED->value);

        (new ImagickDriver())->open('https://cdn.example.com/a.jpg');
    }


    public function testCreateCanvas(): void
    {
        $this->assertNotNull($this->driver);
        $this->driver->create(300, 200);

        $this->assertSame(300, $this->driver->getWidth());
        $this->assertSame(200, $this->driver->getHeight());
        $this->assertSame('image/png', $this->driver->getMime());
    }


    public function testResizeFixedMode(): void
    {
        $this->assertNotNull($this->driver);
        $this->driver->create(400, 300);
        $this->driver->resize(200, 150, 'fixed');

        $this->assertSame(200, $this->driver->getWidth());
        $this->assertSame(150, $this->driver->getHeight());
    }

    public function testResizeLfitMode(): void
    {
        $this->assertNotNull($this->driver);
        $this->driver->create(400, 300);
        $this->driver->resize(200, 200, 'lfit');

        $this->assertLessThanOrEqual(200, $this->driver->getWidth());
        $this->assertLessThanOrEqual(200, $this->driver->getHeight());
    }

    public function testResizeRatioMode(): void
    {
        $this->assertNotNull($this->driver);
        $this->driver->create(400, 200);
        $this->driver->resize(50, 0, 'ratio');

        $this->assertSame(200, $this->driver->getWidth());
        $this->assertSame(100, $this->driver->getHeight());
    }


    public function testCrop(): void
    {
        $this->assertNotNull($this->driver);
        $this->driver->create(200, 200);
        $this->driver->crop(100, 100, 50, 50);

        $this->assertSame(100, $this->driver->getWidth());
        $this->assertSame(100, $this->driver->getHeight());
    }


    public function testRotate90(): void
    {
        $this->assertNotNull($this->driver);
        $this->driver->create(200, 100);
        $this->driver->rotate(90);

        $this->assertGreaterThan(0, $this->driver->getWidth());
        $this->assertGreaterThan(0, $this->driver->getHeight());
    }


    public function testBlur(): void
    {
        $this->assertNotNull($this->driver);
        $this->driver->create(200, 100);
        $this->driver->blur(5);

        $this->assertSame(200, $this->driver->getWidth());
    }

    public function testBrightness(): void
    {
        $this->assertNotNull($this->driver);
        $this->driver->create(200, 100);
        $this->driver->brightness(30);

        $this->assertSame(200, $this->driver->getWidth());
    }

    public function testContrast(): void
    {
        $this->assertNotNull($this->driver);
        $this->driver->create(200, 100);
        $this->driver->contrast(20);

        $this->assertSame(200, $this->driver->getWidth());
    }


    public function testSetQuality(): void
    {
        $this->assertNotNull($this->driver);
        $this->driver->create(100, 100);
        $this->driver->setQuality(80);

        $this->assertTrue(true);
    }

    public function testGetInfo(): void
    {
        $this->assertNotNull($this->driver);
        $this->driver->create(300, 200);

        $info = $this->driver->getInfo();
        $this->assertSame(300, $info['width']);
        $this->assertSame(200, $info['height']);
        $this->assertSame('image/png', $info['mime']);
        $this->assertSame('imagick', $info['driver']);
    }


    public function testOutputPng(): void
    {
        $this->assertNotNull($this->driver);
        $this->driver->create(100, 50);
        $data = $this->driver->output('png');

        $this->assertIsString($data);
        $this->assertNotEmpty($data);
    }


    public function testSavePng(): void
    {
        $this->assertNotNull($this->driver);
        $this->driver->create(100, 50);
        $savePath = sys_get_temp_dir() . '/imagick_test_output.png';

        $result = $this->driver->save($savePath, 'png');

        $this->assertTrue($result);
        $this->assertFileExists($savePath);

        @unlink($savePath);
    }


    public function testDestroy(): void
    {
        $this->assertNotNull($this->driver);
        $this->driver->create(100, 100);
        $this->driver->destroy();

        $this->assertSame(0, $this->driver->getWidth());
        $this->assertSame(0, $this->driver->getHeight());
    }
}
