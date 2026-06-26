<?php
declare(strict_types=1);

/**
 * CleanCommand 附件清理命令功能测试
 *
 * 测试 dry-run 预览模式、force 强制模式、安全模式跳过引用检查等功能。
 */
namespace wise\attachment\tests\Feature;

use PHPUnit\Framework\TestCase;
use think\console\Input;
use think\console\Output;
use think\facade\Config;
use think\facade\Db;
use wise\attachment\command\CleanCommand;
use wise\attachment\driver\DriverInterface;
use wise\attachment\factory\AttachmentStore;

class CleanCommandTest extends TestCase
{
    /**
     * 测试前准备数据和配置
     */
    protected function setUp(): void
    {
        parent::setUp();

        Db::execute('DELETE FROM ws_attachment_relation');
        Db::execute('DELETE FROM ws_attachment');

        Config::set([
            'retention_days' => 30,
            'batch_size'     => 100,
        ], 'wise-attachment.clean');
    }

    /**
     * 测试后清理
     */
    protected function tearDown(): void
    {
        Db::execute('DELETE FROM ws_attachment_relation');
        Db::execute('DELETE FROM ws_attachment');
        parent::tearDown();
    }

    // ============================================================
    // dry-run 预览模式测试
    // ============================================================

    /**
     * @test 测试 dry-run 模式不实际删除记录
     */
    public function testDryRunModeDoesNotActuallyDelete(): void
    {
        $this->insertTrashedAttachment('expired_file.jpg', time() - 40 * 86400);

        $count = Db::table('ws_attachment')->where('delete_time', '>', 0)->count();
        $this->assertEquals(1, $count);

        $command = $this->createCleanCommand();
        $input   = new Input(['--dry-run']);
        $output  = new Output();

        $exitCode = $command->run($input, $output);

        $this->assertEquals(0, $exitCode);

        $countAfter = Db::table('ws_attachment')->where('delete_time', '>', 0)->count();
        $this->assertEquals(1, $countAfter, 'dry-run 不应删除记录');
    }

    // ============================================================
    // force 强制模式测试
    // ============================================================

    /**
     * @test 测试 force 模式删除有引用的附件
     */
    public function testForceModeDeletesReferencedAttachments(): void
    {
        $attachId = $this->insertTrashedAttachment('referenced_file.jpg', time() - 40 * 86400);

        Db::table('ws_attachment_relation')->insert([
            'attachment_id' => $attachId,
            'relation_type' => 'app\\model\\Article',
            'relation_id'   => 1,
        ]);

        $command = $this->createCleanCommand();
        $input   = new Input(['--force']);
        $output  = new Output();

        $exitCode = $command->run($input, $output);

        $this->assertEquals(0, $exitCode);

        $count = Db::table('ws_attachment')->where('id', $attachId)->count();
        $this->assertEquals(0, $count, 'force 模式应删除有引用的附件');
    }

    // ============================================================
    // 安全模式测试（默认）
    // ============================================================

    /**
     * @test 测试安全模式跳过有引用的附件
     */
    public function testSafeModeSkipsReferencedAttachments(): void
    {
        $referencedId   = $this->insertTrashedAttachment('referenced_safe.jpg', time() - 40 * 86400);
        $unreferencedId = $this->insertTrashedAttachment('unreferenced_safe.jpg', time() - 40 * 86400);

        Db::table('ws_attachment_relation')->insert([
            'attachment_id' => $referencedId,
            'relation_type' => 'app\\model\\Article',
            'relation_id'   => 1,
        ]);

        $command = $this->createCleanCommand();
        $input   = new Input([]);
        $output  = new Output();

        $exitCode = $command->run($input, $output);

        $this->assertEquals(0, $exitCode);

        // 有引用的应被跳过（仍在表中）
        $this->assertGreaterThan(
            0,
            Db::table('ws_attachment')->where('id', $referencedId)->count()
        );

        // 无引用的应被删除
        $this->assertEquals(
            0,
            Db::table('ws_attachment')->where('id', $unreferencedId)->count(),
            '安全模式应删除无引用的超期附件'
        );
    }

    /**
     * @test 测试安全模式删除无引用的超期附件
     */
    public function testSafeModeDeletesExpiredUnreferencedAttachments(): void
    {
        $this->insertTrashedAttachment('expired_unref.jpg', time() - 40 * 86400);

        $command = $this->createCleanCommand();
        $input   = new Input([]);
        $output  = new Output();

        $exitCode = $command->run($input, $output);

        $this->assertEquals(0, $exitCode);

        $count = Db::table('ws_attachment')->count();
        $this->assertEquals(0, $count, '超期无引用附件应被删除');
    }

    /**
     * @test 测试安全模式不删除未超期的附件
     */
    public function testSafeModeDoesNotDeleteNotExpiredAttachments(): void
    {
        $this->insertTrashedAttachment('recent_file.jpg', time() - 1 * 86400);

        $command = $this->createCleanCommand();
        $input   = new Input([]);
        $output  = new Output();

        $exitCode = $command->run($input, $output);

        $this->assertEquals(0, $exitCode);

        $count = Db::table('ws_attachment')->where('delete_time', '>', 0)->count();
        $this->assertEquals(1, $count, '未超期附件不应被删除');
    }

    // ============================================================
    // 自定义保留天数测试
    // ============================================================

    /**
     * @test 测试自定义保留天数（--days）生效
     */
    public function testCustomRetentionDaysOption(): void
    {
        // 5 天前删除（使用 --days=3 自定义 3 天保留期，应被删）
        $this->insertTrashedAttachment('five_days_ago.jpg', time() - 5 * 86400);

        $command = $this->createCleanCommand();
        $input   = new Input(['--days=3']);
        $output  = new Output();

        $exitCode = $command->run($input, $output);

        $this->assertEquals(0, $exitCode);

        $count = Db::table('ws_attachment')->count();
        $this->assertEquals(0, $count, '超过自定义保留天数的附件应被删除');
    }

    // ============================================================
    // 辅助方法
    // ============================================================

    /**
     * 创建 CleanCommand 实例（Mock 存储驱动）
     */
    private function createCleanCommand(): CleanCommand
    {
        $command = new CleanCommand();

        $store = $this->getMockBuilder(AttachmentStore::class)
            ->onlyMethods(['driver'])
            ->getMock();

        $driverMock = $this->createMock(DriverInterface::class);
        $driverMock->method('delete')->willReturn(true);
        $driverMock->method('getDriverName')->willReturn('local');

        $store->method('driver')->willReturn($driverMock);

        app()->bind(AttachmentStore::class, $store);
        app()->bind('wise.attachment.store', $store);

        return $command;
    }

    /**
     * 插入超期的软删除附件记录
     *
     * @param string $objectName 对象名
     * @param int    $deleteTime 删除时间戳
     * @return int 插入的 ID
     */
    private function insertTrashedAttachment(string $objectName, int $deleteTime): int
    {
        return (int) Db::table('ws_attachment')->insertGetId([
            'storage'      => 'local',
            'object_name'  => $objectName,
            'url'          => '/uploads/' . $objectName,
            'size'         => 1024,
            'mime_type'    => 'image/jpeg',
            'image_width'  => 0,
            'image_height' => 0,
            'is_image'     => 0,
            'delete_time'  => $deleteTime,
            'create_time'  => $deleteTime - 86400,
            'update_time'  => $deleteTime,
        ]);
    }
}
