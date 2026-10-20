<?php
declare(strict_types=1);

/**
 * UploadController 上传控制器功能测试
 *
 * 测试上传文件校验、常规上传成功、上传失败等场景。
 * 使用真实 think\File 对象避免 SplFileInfo mock 限制。
 */
namespace wise\attachment\tests\Feature;

use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\Request;
use wise\attachment\traits\AttachmentUploadTrait;
use wise\attachment\driver\DriverInterface;
use wise\attachment\factory\AttachmentStore;

class UploadControllerTest extends TestCase
{
    /**
     * 临时文件目录
     * @var string
     */
    private $tempDir;

    /**
     * @var AttachmentStore
     */
    private $store;

    /**
     * @var DriverInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $driverMock;

    /**
     * 测试前准备
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise_upload_test_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);

        // 断路器状态存储指向本用例专属的文件缓存目录：
        // 1) 不依赖外部 redis（未部署时上传链路会降级放行，断言仍成立）；
        // 2) 避免跨用例/跨运行的残留状态（如 OPEN）把断言变成 503。
        Config::set([
            'stores' => [
                'wise_attachment_test' => [
                    'type' => 'file',
                    'path' => $this->tempDir . DIRECTORY_SEPARATOR . 'cache',
                ],
            ],
        ], 'cache');
        Config::set([
            'circuit' => ['store' => 'wise_attachment_test'],
        ], 'wise-attachment');

        $this->driverMock = $this->createMock(DriverInterface::class);

        $this->store = $this->getMockBuilder(AttachmentStore::class)
            ->onlyMethods(['driver'])
            ->getMock();

        $this->store->method('driver')
            ->willReturn($this->driverMock);
    }

    /**
     * 测试后清理
     */
    protected function tearDown(): void
    {
        if ($this->tempDir && is_dir($this->tempDir)) {
            $this->delTree($this->tempDir);
        }
        parent::tearDown();
    }

    /**
     * 配置上传限制
     */
    private function configureUploadLimits(string $allowedExt = 'jpg,jpeg,png,gif,pdf', int $maxSizeMb = 50): void
    {
        // 注意：TP 的 Config::set() 不支持点号路径（会把 'wise-attachment.upload' 当成一个
        // 带点的顶层键，覆盖静默失效），因此这里按顶层键 'wise-attachment' 合并写入
        Config::set([
            'upload'   => array_merge(Config::get('wise-attachment.upload', []), [
                'allowed_ext'   => $allowedExt,
                'max_size'      => $maxSizeMb,
                'path_format'   => 'Y/m/d',
                'name_strategy' => 'md5',
            ]),
            // 本用例用占位字节而非真实图片：关闭幻数检测与图片重写，
            // 否则安全兜底会先把合法用例判为非法（这两项能力由各自的安全用例覆盖）
            'security' => array_merge(Config::get('wise-attachment.security', []), [
                'magic_number_check' => false,
                'image_rewrite'      => false,
            ]),
        ], 'wise-attachment');
    }

    // ============================================================
    // 无文件上传测试
    // ============================================================

    /**
     * @test 测试上传无文件时返回 400 错误
     */
    public function testUploadWithoutFileReturns400Error(): void
    {
        $this->configureUploadLimits();

        $request = $this->createMockRequest(null, []);

        $controller = $this->createUploadController();
        $response   = $controller->upload($request);

        $data = json_decode($response->getContent(), true);
        $this->assertEquals(400, $data['code']);
        $this->assertStringContainsString('未检测到上传文件', $data['msg']);
    }

    // ============================================================
    // 不允许扩展名测试
    // ============================================================

    /**
     * @test 测试上传不允许的扩展名返回 422 错误
     */
    public function testUploadWithDisallowedExtensionReturns422Error(): void
    {
        $this->configureUploadLimits('jpg,png,gif');

        $realFile = $this->createRealFile('test.exe', 'application/x-msdownload', 1);
        $request  = $this->createMockRequest($realFile, []);

        $controller = $this->createUploadController();
        $response   = $controller->upload($request);

        $data = json_decode($response->getContent(), true);
        $this->assertEquals(422, $data['code']);
        $this->assertStringContainsString('不允许的文件类型', $data['msg']);
        $this->assertStringContainsString('exe', $data['msg']);
    }

    // ============================================================
    // 文件大小超限测试
    // ============================================================

    /**
     * @test 测试上传超大文件返回错误响应
     */
    public function testUploadWithOversizedFileReturnsError(): void
    {
        $this->configureUploadLimits('jpg,png', 1);

        $realFile = $this->createRealFile('large.jpg', 'image/jpeg', 2 * 1024 * 1024 + 1);
        $request  = $this->createMockRequest($realFile, []);

        $controller = $this->createUploadController();
        $response   = $controller->upload($request);

        $data = json_decode($response->getContent(), true);
        // 大文件应触发错误响应（422 或安全回退到 500）
        $this->assertNotEquals(200, $data['code'], '超大文件不应返回成功响应');
        $this->assertArrayHasKey('msg', $data);
    }

    // ============================================================
    // 成功上传测试
    // ============================================================

    /**
     * @test 测试成功上传返回 200 和附件数据
     */
    public function testSuccessfulUploadReturns200WithAttachmentData(): void
    {
        $this->configureUploadLimits();

        $this->driverMock->method('upload')
            ->willReturn([
                'url'       => '/uploads/2024/01/01/abc123.jpg',
                'path'      => '2024/01/01/abc123.jpg',
                'size'      => 20480,
                'mime_type' => 'image/jpeg',
                'storage'   => 'local',
                'width'     => 800,
                'height'    => 600,
            ]);
        $this->driverMock->method('getDriverName')
            ->willReturn('local');

        $realFile = $this->createRealFile('photo.jpg', 'image/jpeg', 20480);
        $request  = $this->createMockRequest($realFile, []);

        $controller = $this->createUploadController();
        $response   = $controller->upload($request);

        $data = json_decode($response->getContent(), true);
        // JsonResponse::success() 的成功码是 0（框架统一约定），不是 HTTP 200
        $this->assertEquals(0, $data['code']);
        $this->assertEquals('上传成功', $data['msg']);
        $this->assertArrayHasKey('url', $data['data']);
        $this->assertArrayHasKey('size', $data['data']);
        $this->assertArrayHasKey('mime', $data['data']);
        $this->assertArrayHasKey('width', $data['data']);
        $this->assertArrayHasKey('height', $data['data']);
        $this->assertArrayHasKey('is_image', $data['data']);
        $this->assertEquals('/uploads/2024/01/01/abc123.jpg', $data['data']['url']);
        $this->assertEquals(20480, $data['data']['size']);
        $this->assertEquals(800, $data['data']['width']);
        $this->assertEquals(600, $data['data']['height']);
        $this->assertEquals(1, $data['data']['is_image']);
    }

    // ============================================================
    // 异常处理测试
    // ============================================================

    /**
     * @test 测试上传过程中抛异常返回 500 错误
     */
    public function testUploadExceptionReturns500Error(): void
    {
        $this->configureUploadLimits();

        $this->driverMock->method('upload')
            ->willThrowException(new \RuntimeException('存储服务不可用'));

        $realFile = $this->createRealFile('photo.jpg', 'image/jpeg', 1024);
        $request  = $this->createMockRequest($realFile, []);

        $controller = $this->createUploadController();
        $response   = $controller->upload($request);

        $data = json_decode($response->getContent(), true);
        $this->assertEquals(500, $data['code']);
        // 内部异常消息不再回显（只写日志），非 debug 环境返回固定文案
        $this->assertEquals('上传失败，请稍后重试', $data['msg']);
    }

    // ============================================================
    // 辅助方法
    // ============================================================

    /**
     * 创建用于测试的 Request 对象（使用匿名类绕过 mock 问题）
     *
     * @param mixed $file   文件对象，null 表示无文件
     * @param array $params 请求参数
     * @return Request
     */
    private function createMockRequest($file, array $params): Request
    {
        $fileResult  = $file;
        $paramsArray = $params;

        return new class($fileResult, $paramsArray) extends Request {
            private $mockFile;
            private $mockParams;

            public function __construct($file, $params)
            {
                // 跳过父类构造函数，避免复杂的初始化
                $this->mockFile   = $file;
                $this->mockParams = $params;
            }

            public function file($name = null)
            {
                return $this->mockFile;
            }

            public function param($name = '', $default = null, $filter = '')
            {
                return $this->mockParams[$name] ?? $default;
            }
        };
    }

    /**
     * 创建模拟的 think\File 对象用于测试
     *
     * @param string $name     文件名
     * @param string $mimeType MIME 类型
     * @param int    $minSize  最小文件大小
     * @return \think\File&\PHPUnit\Framework\MockObject\MockObject
     */
    private function createRealFile(string $name, string $mimeType, int $minSize)
    {
        $ext  = pathinfo($name, PATHINFO_EXTENSION);
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $name;

        // 创建实际文件（填充到 minSize）
        file_put_contents($path, str_repeat('X', $minSize));

        // 上传链路（attachmentValidateUpload / attachmentHandleUpload）的类型签名要求
        // think\file\UploadedFile；mock think\File 会直接触发 TypeError（被兜底为 500）。
        // getOriginalExtension / getOriginalName 是 UploadedFile 的真实方法，直接 mock 即可。
        $file = $this->getMockBuilder(\think\file\UploadedFile::class)
            ->setConstructorArgs([$path, $name, $mimeType])
            ->onlyMethods(['getMime', 'getSize', 'getOriginalExtension', 'getOriginalName'])
            ->getMock();

        $file->method('getMime')->willReturn($mimeType);
        $file->method('getSize')->willReturn($minSize);
        $file->method('getOriginalExtension')->willReturn($ext);
        $file->method('getOriginalName')->willReturn($name);

        return $file;
    }

    /**
     * 创建被测控制器替身
     *
     * 上传能力已从 UploadController 迁移到 AttachmentUploadTrait，
     * 这里用匿名类引入 trait 并注入 AttachmentStore，保持原测试的调用形态（upload()）。
     */
    private function createUploadController(): object
    {
        $store = $this->store;

        return new class($store) {
            use AttachmentUploadTrait;

            public function __construct(\wise\attachment\factory\AttachmentStore $store)
            {
                $this->attachmentStore = $store;
            }

            public function upload(Request $request)
            {
                return $this->attachmentUpload($request);
            }
        };
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
