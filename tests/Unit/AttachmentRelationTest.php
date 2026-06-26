<?php
declare(strict_types=1);

/**
 * AttachmentRelation 多态关联模型单元测试
 *
 * 测试附件的关联建立（幂等）、解除、清理、引用检查等功能。
 */
namespace wise\attachment\tests\Unit;

use PHPUnit\Framework\TestCase;
use think\facade\Db;
use wise\attachment\model\AttachmentRelation;

class AttachmentRelationTest extends TestCase
{
    /**
     * 测试用附件 ID
     */
    private const TEST_ATTACHMENT_ID = 1;

    /**
     * 测试用业务模型类名
     */
    private const TEST_RELATION_TYPE = 'app\\model\\Article';

    /**
     * 测试用业务模型 ID
     */
    private const TEST_RELATION_ID = 100;

    /**
     * 测试前清空数据表
     */
    protected function setUp(): void
    {
        parent::setUp();
        Db::execute('DELETE FROM ws_attachment_relation');
    }

    /**
     * 测试后清理
     */
    protected function tearDown(): void
    {
        Db::execute('DELETE FROM ws_attachment_relation');
        parent::tearDown();
    }

    // ============================================================
    // link() 测试
    // ============================================================

    /**
     * @test 测试 link() 成功建立关联
     */
    public function testLinkCreatesNewRelation(): void
    {
        $relation = AttachmentRelation::link(
            self::TEST_ATTACHMENT_ID,
            self::TEST_RELATION_TYPE,
            self::TEST_RELATION_ID
        );

        $this->assertInstanceOf(AttachmentRelation::class, $relation);
        $this->assertEquals(self::TEST_ATTACHMENT_ID, $relation->attachment_id);
        $this->assertEquals(self::TEST_RELATION_TYPE, $relation->relation_type);
        $this->assertEquals(self::TEST_RELATION_ID, $relation->relation_id);

        // 验证数据库中存在
        $count = Db::table('ws_attachment_relation')
            ->where('attachment_id', self::TEST_ATTACHMENT_ID)
            ->count();
        $this->assertEquals(1, $count);
    }

    /**
     * @test 测试 link() 幂等操作：重复关联不创建新记录
     */
    public function testLinkIsIdempotent(): void
    {
        $first = AttachmentRelation::link(
            self::TEST_ATTACHMENT_ID,
            self::TEST_RELATION_TYPE,
            self::TEST_RELATION_ID
        );

        $second = AttachmentRelation::link(
            self::TEST_ATTACHMENT_ID,
            self::TEST_RELATION_TYPE,
            self::TEST_RELATION_ID
        );

        // 返回同一记录
        $this->assertEquals($first->id, $second->id);

        // 数据库中只有一条记录
        $count = Db::table('ws_attachment_relation')->count();
        $this->assertEquals(1, $count);
    }

    /**
     * @test 测试 link() 不同附件 ID 创建不同关联
     */
    public function testLinkWithDifferentAttachmentIdsCreatesSeparateRelations(): void
    {
        AttachmentRelation::link(1, self::TEST_RELATION_TYPE, self::TEST_RELATION_ID);
        AttachmentRelation::link(2, self::TEST_RELATION_TYPE, self::TEST_RELATION_ID);

        $count = Db::table('ws_attachment_relation')->count();
        $this->assertEquals(2, $count);
    }

    /**
     * @test 测试 link() 不同业务模型创建不同关联
     */
    public function testLinkWithDifferentRelationTypesCreatesSeparateRelations(): void
    {
        AttachmentRelation::link(self::TEST_ATTACHMENT_ID, 'app\\model\\Article', self::TEST_RELATION_ID);
        AttachmentRelation::link(self::TEST_ATTACHMENT_ID, 'app\\model\\Product', self::TEST_RELATION_ID);

        $count = Db::table('ws_attachment_relation')->count();
        $this->assertEquals(2, $count);
    }

    // ============================================================
    // unlink() 测试
    // ============================================================

    /**
     * @test 测试 unlink() 成功解除已存在的关联
     */
    public function testUnlinkRemovesExistingRelation(): void
    {
        AttachmentRelation::link(
            self::TEST_ATTACHMENT_ID,
            self::TEST_RELATION_TYPE,
            self::TEST_RELATION_ID
        );

        $result = AttachmentRelation::unlink(
            self::TEST_ATTACHMENT_ID,
            self::TEST_RELATION_TYPE,
            self::TEST_RELATION_ID
        );

        $this->assertTrue($result);

        $count = Db::table('ws_attachment_relation')->count();
        $this->assertEquals(0, $count);
    }

    /**
     * @test 测试 unlink() 解除不存在的关联返回 false
     */
    public function testUnlinkNonExistentRelationReturnsFalse(): void
    {
        $result = AttachmentRelation::unlink(
            999,
            'app\\model\\NonExistent',
            999
        );

        $this->assertFalse($result);
    }

    // ============================================================
    // clearFor() 测试
    // ============================================================

    /**
     * @test 测试 clearFor() 清理指定业务模型的所有关联
     */
    public function testClearForRemovesAllRelationsForGivenModel(): void
    {
        // 创建多个关联
        AttachmentRelation::link(1, self::TEST_RELATION_TYPE, self::TEST_RELATION_ID);
        AttachmentRelation::link(2, self::TEST_RELATION_TYPE, self::TEST_RELATION_ID);
        AttachmentRelation::link(3, self::TEST_RELATION_TYPE, self::TEST_RELATION_ID);

        // 创建其他模型的关联（不应被清理）
        AttachmentRelation::link(4, 'app\\model\\Product', 200);

        $result = AttachmentRelation::clearFor(self::TEST_RELATION_TYPE, self::TEST_RELATION_ID);

        $this->assertTrue($result);

        // 目标模型的关联已清除
        $count = Db::table('ws_attachment_relation')
            ->where('relation_type', self::TEST_RELATION_TYPE)
            ->where('relation_id', self::TEST_RELATION_ID)
            ->count();
        $this->assertEquals(0, $count);

        // 其他模型的关联未受影响
        $otherCount = Db::table('ws_attachment_relation')
            ->where('relation_type', 'app\\model\\Product')
            ->count();
        $this->assertEquals(1, $otherCount);
    }

    /**
     * @test 测试 clearFor() 清理不存在关联的模型返回 false
     */
    public function testClearForNonExistentModelReturnsFalse(): void
    {
        $result = AttachmentRelation::clearFor('app\\model\\EmptyModel', 999);
        $this->assertFalse($result);
    }

    // ============================================================
    // hasValidRef() 测试
    // ============================================================

    /**
     * @test 测试 hasValidRef() 对有引用的附件返回 true
     */
    public function testHasValidRefReturnsTrueForReferencedAttachment(): void
    {
        AttachmentRelation::link(
            self::TEST_ATTACHMENT_ID,
            self::TEST_RELATION_TYPE,
            self::TEST_RELATION_ID
        );

        $this->assertTrue(AttachmentRelation::hasValidRef(self::TEST_ATTACHMENT_ID));
    }

    /**
     * @test 测试 hasValidRef() 对无引用的附件返回 false
     */
    public function testHasValidRefReturnsFalseForUnreferencedAttachment(): void
    {
        $this->assertFalse(AttachmentRelation::hasValidRef(999));
    }

    // ============================================================
    // getRefCount() 测试
    // ============================================================

    /**
     * @test 测试 getRefCount() 返回正确的引用数量
     */
    public function testGetRefCountReturnsCorrectCount(): void
    {
        $this->assertEquals(0, AttachmentRelation::getRefCount(self::TEST_ATTACHMENT_ID));

        AttachmentRelation::link(self::TEST_ATTACHMENT_ID, 'app\\model\\Article', 1);
        $this->assertEquals(1, AttachmentRelation::getRefCount(self::TEST_ATTACHMENT_ID));

        AttachmentRelation::link(self::TEST_ATTACHMENT_ID, 'app\\model\\Article', 2);
        $this->assertEquals(2, AttachmentRelation::getRefCount(self::TEST_ATTACHMENT_ID));

        AttachmentRelation::link(self::TEST_ATTACHMENT_ID, 'app\\model\\Product', 1);
        $this->assertEquals(3, AttachmentRelation::getRefCount(self::TEST_ATTACHMENT_ID));
    }
}
