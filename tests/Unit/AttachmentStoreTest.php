<?php
declare(strict_types=1);

/**
 * AttachmentStore 驱动工厂单元测试
 *
 * 测试驱动工厂的创建、缓存、异常处理等功能。
 */
namespace wise\attachment\tests\Unit;

use PHPUnit\Framework\TestCase;
use think\facade\Config;
use wise\attachment\driver\DriverInterface;
use wise\attachment\driver\Local;
use wise\attachment\factory\AttachmentStore;

class AttachmentStoreTest extends TestCase
{
    /**
     * @var AttachmentStore
     */
    private $store;

    /**
     * 每个测试前初始化工厂实例
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 确保配置存在
        $wiseConfig = Config::get('wise-attachment');
        if (empty($wiseConfig)) {
            Config::set(require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'wise-attachment.php', 'wise-attachment');
        }

        // 确保默认驱动配置存在
        Config::set(['default_driver' => 'local'], 'wise-attachment');

        $this->store = new AttachmentStore();
    }

    /**
     * 每个测试后清除驱动缓存
     */
    protected function tearDown(): void
    {
        $this->store->flush();
        parent::tearDown();
    }

    /**
     * @test 测试 driver() 无参调用返回默认驱动（Local）
     */
    public function testDriverWithoutParameterReturnsDefaultDriver(): void
    {
        $driver = $this->store->driver();

        $this->assertInstanceOf(DriverInterface::class, $driver);
        $this->assertInstanceOf(Local::class, $driver);
        $this->assertEquals('local', $driver->getDriverName());
    }

    /**
     * @test 测试 driver('local') 返回 Local 实例
     */
    public function testDriverWithLocalReturnsLocalInstance(): void
    {
        $driver = $this->store->driver('local');

        $this->assertInstanceOf(Local::class, $driver);
        $this->assertEquals('local', $driver->getDriverName());
    }

    /**
     * @test 测试不存在的驱动名称抛出异常
     */
    public function testDriverWithNonExistentNameThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->driver('nonexistent_driver');
    }

    /**
     * @test 测试驱动缓存：同一驱动名返回同一实例
     */
    public function testDriverReturnsSameInstanceForSameName(): void
    {
        $driver1 = $this->store->driver('local');
        $driver2 = $this->store->driver('local');

        $this->assertSame($driver1, $driver2);
    }

    /**
     * @test 测试 flush() 清除驱动缓存
     */
    public function testFlushClearsDriverCache(): void
    {
        $driver1 = $this->store->driver('local');
        $this->store->flush();
        $driver2 = $this->store->driver('local');

        // flush 后应该得到不同实例
        $this->assertNotSame($driver1, $driver2);
    }

    /**
     * @test 测试 local() 快捷方法返回 Local 驱动
     */
    public function testLocalMethodReturnsLocalDriver(): void
    {
        $driver = $this->store->local();

        $this->assertInstanceOf(Local::class, $driver);
        $this->assertEquals('local', $driver->getDriverName());
    }

    /**
     * @test 测试 driver(null) 与 driver() 行为一致
     */
    public function testDriverWithNullUsesDefault(): void
    {
        $driver1 = $this->store->driver(null);
        $driver2 = $this->store->driver();

        $this->assertInstanceOf(Local::class, $driver1);
        $this->assertInstanceOf(Local::class, $driver2);
    }
}
