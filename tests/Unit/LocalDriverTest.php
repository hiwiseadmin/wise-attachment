<?php
declare(strict_types=1);

/**
 * Local 本地存储驱动单元测试
 *
 * 测试本地驱动的上传、删除、URL 生成、文件检查等功能。
 * 使用临时目录模拟存储。
 */
namespace wise\attachment\tests\Unit;

use PHPUnit\Framework\TestCase;
use think\facade\Config;
use wise\attachment\driver\Local;

class LocalDriverTest extends TestCase
{
    /**
     * 临时测试根目录
     *
     * @var string
     */
    private $tempRoot;

    /**
     * 本地驱动实例
     *
     * @var Local
     */
    private $driver;

    /**
     * 测试前创建临时目录和驱动实例
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise_attachment_test_' . uniqid('', true);
        mkdir($this->tempRoot, 0755, true);

        $this->driver = new Local([
            'root_path' => $this->tempRoot,
            'base_url'  => '/uploads',
        ]);
    }

    /**
     * 测试后清理临时目录
     */
    protected function tearDown(): void
    {
        $this->delTree($this->tempRoot);
        parent::tearDown();
    }

    // ============================================================
    // upload() 测试
    // ============================================================

    /**
     * @test 测试 upload() 成功上传文件并返回标准化数组
     */
    public function testUploadSuccessfullyReturnsNormalizedArray(): void
    {
        $testFile   = $this->createTempFile('hello.txt', 'Hello World!');
        $objectName = '2024/01/01/test_upload.txt';

        $result = $this->driver->upload($testFile, $objectName);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('url', $result);
        $this->assertArrayHasKey('path', $result);
        $this->assertArrayHasKey('size', $result);
        $this->assertArrayHasKey('mime_type', $result);
        $this->assertArrayHasKey('storage', $result);
        $this->assertArrayHasKey('width', $result);
        $this->assertArrayHasKey('height', $result);

        $this->assertEquals($objectName, $result['path']);
        $this->assertEquals('local', $result['storage']);
        $this->assertEquals(12, $result['size']);
        $this->assertStringContainsString('/uploads/', $result['url']);
        $this->assertStringContainsString($objectName, $result['url']);

        // 验证文件确实存在
        $this->assertFileExists($this->tempRoot . DIRECTORY_SEPARATOR . $objectName);
    }

    /**
     * @test 测试 upload() 返回数组中图片尺寸信息（非图片为0）
     */
    public function testUploadReturnsImageDimensionsForNonImage(): void
    {
        $testFile   = $this->createTempFile('test.txt', 'plain text content');
        $objectName = '2024/01/01/test.txt';

        $result = $this->driver->upload($testFile, $objectName);

        $this->assertEquals(0, $result['width']);
        $this->assertEquals(0, $result['height']);
    }

    // ============================================================
    // delete() 测试
    // ============================================================

    /**
     * @test 测试 delete() 成功删除已存在文件
     */
    public function testDeleteSuccessfullyRemovesExistingFile(): void
    {
        $testFile   = $this->createTempFile('delete_me.txt', 'to be deleted');
        $objectName = '2024/01/01/delete_me.txt';

        $this->driver->upload($testFile, $objectName);
        $this->assertFileExists($this->tempRoot . DIRECTORY_SEPARATOR . $objectName);

        $result = $this->driver->delete($objectName);

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($this->tempRoot . DIRECTORY_SEPARATOR . $objectName);
    }

    /**
     * @test 测试 delete() 不存在的文件返回 true（接口约定）
     */
    public function testDeleteNonExistentFileReturnsTrue(): void
    {
        $result = $this->driver->delete('nonexistent/file.txt');

        $this->assertTrue($result);
    }

    // ============================================================
    // url() 测试
    // ============================================================

    /**
     * @test 测试 url() 返回正确的基础 URL
     */
    public function testUrlReturnsCorrectBaseUrl(): void
    {
        $url = $this->driver->url('path/to/file.jpg');

        $this->assertEquals('/uploads/path/to/file.jpg', $url);
    }

    /**
     * @test 测试 url() 带图片处理参数但不存在的文件返回原始 URL
     */
    public function testUrlWithImageOptionsForNonExistentFileReturnsBaseUrl(): void
    {
        $url = $this->driver->url('nonexistent.jpg', ['width' => 100, 'height' => 100]);

        // 文件不存在，应回退到原始 URL（generateThumbnail 返回 null）
        $this->assertEquals('/uploads/nonexistent.jpg', $url);
    }

    // ============================================================
    // exists() 测试
    // ============================================================

    /**
     * @test 测试 exists() 正确判断已存在文件
     */
    public function testExistsReturnsTrueForExistingFile(): void
    {
        $testFile   = $this->createTempFile('existing.txt', 'content');
        $objectName = '2024/01/01/existing.txt';
        $this->driver->upload($testFile, $objectName);

        $this->assertTrue($this->driver->exists($objectName));
    }

    /**
     * @test 测试 exists() 正确判断不存在文件
     */
    public function testExistsReturnsFalseForNonExistentFile(): void
    {
        $this->assertFalse($this->driver->exists('nonexistent/file.txt'));
    }

    // ============================================================
    // size() 测试
    // ============================================================

    /**
     * @test 测试 size() 返回正确文件大小
     */
    public function testSizeReturnsCorrectFileSize(): void
    {
        $content    = 'Hello World! This is a test file.';
        $testFile   = $this->createTempFile('sized.txt', $content);
        $objectName = '2024/01/01/sized.txt';
        $this->driver->upload($testFile, $objectName);

        $size = $this->driver->size($objectName);

        $this->assertEquals(strlen($content), $size);
    }

    /**
     * @test 测试 size() 对不存在的文件返回 0
     */
    public function testSizeReturnsZeroForNonExistentFile(): void
    {
        $this->assertEquals(0, $this->driver->size('nonexistent/file.txt'));
    }

    // ============================================================
    // mimeType() 测试
    // ============================================================

    /**
     * @test 测试 mimeType() 返回正确的 MIME 类型
     */
    public function testMimeTypeReturnsCorrectMimeType(): void
    {
        $testFile   = $this->createTempFile('text.txt', 'text content');
        $objectName = '2024/01/01/text.txt';
        $this->driver->upload($testFile, $objectName);

        $mime = $this->driver->mimeType($objectName);

        $this->assertStringContainsString('text/', $mime);
    }

    /**
     * @test 测试 mimeType() 对不存在的文件返回空字符串
     */
    public function testMimeTypeReturnsEmptyStringForNonExistentFile(): void
    {
        $this->assertEquals('', $this->driver->mimeType('nonexistent/file.txt'));
    }

    // ============================================================
    // getDriverName() 测试
    // ============================================================

    /**
     * @test 测试 getDriverName() 返回 'local'
     */
    public function testGetDriverNameReturnsLocal(): void
    {
        $this->assertEquals('local', $this->driver->getDriverName());
    }

    // ============================================================
    // 辅助方法
    // ============================================================

    /**
     * 创建临时测试文件
     *
     * @param string $filename 文件名
     * @param string $content  文件内容
     * @return string 文件完整路径
     */
    private function createTempFile(string $filename, string $content): string
    {
        $path = $this->tempRoot . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($path, $content);
        return $path;
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
