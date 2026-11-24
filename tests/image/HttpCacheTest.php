<?php

declare(strict_types=1);

namespace wise\image\tests;

use PHPUnit\Framework\TestCase;
use wise\image\ImageManager;

/**
 * HTTP 协商缓存测试（item 8）
 *
 * 通过 httpCacheState() 断言 ETag / Last-Modified / Cache-Control 与 304 判定，
 * 无需真实 HTTP 上下文（CLI 下 headers_list() 不可用）。
 */
class HttpCacheTest extends TestCase
{
    private string $tmpDir;
    private string $source;

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD 扩展不可用');
        }

        $this->tmpDir = sys_get_temp_dir() . '/wise-image-http-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);

        $this->source = $this->tmpDir . '/src.png';
        $img = imagecreatetruecolor(120, 60);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 30, 160, 90));
        imagepng($img, $this->source);
        imagedestroy($img);

        unset($_SERVER['HTTP_IF_NONE_MATCH'], $_SERVER['HTTP_IF_MODIFIED_SINCE']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_IF_NONE_MATCH'], $_SERVER['HTTP_IF_MODIFIED_SINCE']);

        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->tmpDir);
        }
    }

    /**
     * @param array $config
     * @param array $server 模拟的请求头（经 Request 注入，不再依赖 $_SERVER 超全局）
     */
    private function manager(array $config = [], array $server = []): ImageManager
    {
        $manager = new ImageManager(array_merge([
            'driver' => 'gd',
            'cache'  => ['driver' => 'file', 'ttl' => 2592000, 'path' => $this->tmpDir . '/cache', 'prefix' => 'http:'],
        ], $config));

        $manager->setRequest((new \think\Request())->withServer($server));

        return $manager->open($this->source);
    }


    public function testDefaultState(): void
    {
        $state = $this->manager()->httpCacheState();

        $this->assertNotNull($state['etag']);
        $this->assertStringStartsWith('"', $state['etag']);
        $this->assertStringEndsWith('"', $state['etag']);
        $this->assertSame((int) filemtime($this->source), $state['last_modified']);
        $this->assertSame('public, max-age=2592000', $state['cache_control']);
        $this->assertFalse($state['not_modified']);
    }

    public function testCustomMaxAgeImmutableAndVisibility(): void
    {
        $state = $this->manager([
            'response' => ['max_age' => 600, 'immutable' => true, 'visibility' => 'private'],
        ])->httpCacheState();

        $this->assertSame('private, max-age=600, immutable', $state['cache_control']);
    }

    public function testDisableEtagsAndCacheControl(): void
    {
        $state = $this->manager([
            'response' => ['etag' => false, 'last_modified' => false, 'cache_control' => false],
        ])->httpCacheState();

        $this->assertNull($state['etag']);
        $this->assertNull($state['last_modified']);
        $this->assertNull($state['cache_control']);
        $this->assertFalse($state['not_modified']);
    }


    public function testNotModifiedWhenIfNoneMatchMatches(): void
    {
        $etag = (string) $this->manager()->httpCacheState()['etag'];

        $manager = $this->manager([], ['HTTP_IF_NONE_MATCH' => $etag]);
        $this->assertTrue($manager->httpCacheState()['not_modified']);
    }

    public function testNotModifiedWhenIfNoneMatchIsWildcard(): void
    {
        $manager = $this->manager([], ['HTTP_IF_NONE_MATCH' => '*']);
        $this->assertTrue($manager->httpCacheState()['not_modified']);
    }

    public function testModifiedWhenIfNoneMatchDiffers(): void
    {
        $manager = $this->manager([], ['HTTP_IF_NONE_MATCH' => '"deadbeef"']);
        $this->assertFalse($manager->httpCacheState()['not_modified']);
    }

    public function testNotModifiedWhenIfModifiedSinceIsNewer(): void
    {
        $since = gmdate('D, d M Y H:i:s', (int) filemtime($this->source) + 60) . ' GMT';

        $manager = $this->manager([], ['HTTP_IF_MODIFIED_SINCE' => $since]);
        $this->assertTrue($manager->httpCacheState()['not_modified']);
    }

    public function testModifiedWhenIfModifiedSinceIsOlder(): void
    {
        $since = gmdate('D, d M Y H:i:s', (int) filemtime($this->source) - 60) . ' GMT';

        $manager = $this->manager([], ['HTTP_IF_MODIFIED_SINCE' => $since]);
        $this->assertFalse($manager->httpCacheState()['not_modified']);
    }

    public function testIfNoneMatchTakesPrecedenceOverIfModifiedSince(): void
    {
        $manager = $this->manager([], [
            'HTTP_IF_NONE_MATCH'     => '"deadbeef"',
            'HTTP_IF_MODIFIED_SINCE' => gmdate('D, d M Y H:i:s', (int) filemtime($this->source) + 600) . ' GMT',
        ]);

        $this->assertFalse($manager->httpCacheState()['not_modified']);
    }

    public function testEtagChangesWhenSourceChanges(): void
    {
        $manager = $this->manager();
        $before  = (string) $manager->httpCacheState()['etag'];

        touch($this->source, time() + 300);
        clearstatcache(true, $this->source);

        $after = (string) $this->manager()->httpCacheState()['etag'];

        $this->assertNotSame($before, $after);
    }


    public function testResponseBuildsFrameworkResponse(): void
    {
        $response = $this->manager()->response();

        $this->assertInstanceOf(\think\Response::class, $response);
        $this->assertSame(200, $response->getCode());
        $this->assertSame('image/png', $response->getHeader('Content-Type'));
        $this->assertSame('public, max-age=2592000', $response->getHeader('Cache-Control'));
        $this->assertNotNull($response->getHeader('ETag'));
        $this->assertNotSame('', $response->getContent(), '正常响应必须携带图片二进制');
    }

    public function testResponseReturns304WithEmptyBodyWhenNotModified(): void
    {
        $etag = (string) $this->manager()->httpCacheState()['etag'];

        $response = $this->manager([], ['HTTP_IF_NONE_MATCH' => $etag])->response();

        $this->assertSame(304, $response->getCode());
        $this->assertSame('', $response->getContent());
        $this->assertSame($etag, $response->getHeader('ETag'));
    }
}
