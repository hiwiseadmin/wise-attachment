<?php
declare(strict_types=1);

/**
 * AttachmentModel 附件模型单元测试
 *
 * 测试附件的类型判断、图标类名、文件大小格式化、扩展名提取、
 * 搜索查询构建、软删除等功能。
 */
namespace wise\attachment\tests\Unit;

use PHPUnit\Framework\TestCase;
use think\facade\Db;
use wise\attachment\model\AttachmentModel;

class AttachmentModelTest extends TestCase
{
    /**
     * 测试前清空数据表
     */
    protected function setUp(): void
    {
        parent::setUp();
        Db::execute('DELETE FROM ws_attachment');
        Db::execute('DELETE FROM ws_attachment_relation');
    }

    /**
     * 测试后清理
     */
    protected function tearDown(): void
    {
        Db::execute('DELETE FROM ws_attachment');
        Db::execute('DELETE FROM ws_attachment_relation');
        parent::tearDown();
    }

    // ============================================================
    // isImage() 测试
    // ============================================================

    /**
     * @test 测试 isImage() 对图片正确返回 true
     */
    public function testIsImageReturnsTrueForImageAttachment(): void
    {
        $model = $this->createModel(['is_image' => 1, 'mime_type' => 'image/png']);

        $this->assertTrue($model->isImage());
    }

    /**
     * @test 测试 isImage() 对非图片返回 false
     */
    public function testIsImageReturnsFalseForNonImageAttachment(): void
    {
        $model = $this->createModel(['is_image' => 0, 'mime_type' => 'application/pdf']);

        $this->assertFalse($model->isImage());
    }

    // ============================================================
    // isPdf() 测试
    // ============================================================

    /**
     * @test 测试 isPdf() 对 PDF 返回 true
     */
    public function testIsPdfReturnsTrueForPdfAttachment(): void
    {
        $model = $this->createModel(['mime_type' => 'application/pdf']);

        $this->assertTrue($model->isPdf());
    }

    /**
     * @test 测试 isPdf() 对非 PDF 返回 false
     * @dataProvider nonPdfMimeProvider
     */
    public function testIsPdfReturnsFalseForNonPdfAttachment(string $mimeType): void
    {
        $model = $this->createModel(['mime_type' => $mimeType]);

        $this->assertFalse($model->isPdf());
    }

    /**
     * 非 PDF MIME 类型数据提供器
     */
    public function nonPdfMimeProvider(): array
    {
        return [
            'image_png'  => ['image/png'],
            'text_plain' => ['text/plain'],
            'video_mp4'  => ['video/mp4'],
            'audio_mp3'  => ['audio/mp3'],
            'word_doc'   => ['application/msword'],
        ];
    }

    // ============================================================
    // isVideo() / isAudio() 测试
    // ============================================================

    /**
     * @test 测试 isVideo() 对视频类型返回 true
     * @dataProvider videoMimeProvider
     */
    public function testIsVideoReturnsTrueForVideoAttachment(string $mimeType): void
    {
        $model = $this->createModel(['mime_type' => $mimeType]);

        $this->assertTrue($model->isVideo());
    }

    public function videoMimeProvider(): array
    {
        return [
            'mp4'  => ['video/mp4'],
            'avi'  => ['video/avi'],
            'mov'  => ['video/quicktime'],
            'mkv'  => ['video/x-matroska'],
        ];
    }

    /**
     * @test 测试 isAudio() 对音频类型返回 true
     * @dataProvider audioMimeProvider
     */
    public function testIsAudioReturnsTrueForAudioAttachment(string $mimeType): void
    {
        $model = $this->createModel(['mime_type' => $mimeType]);

        $this->assertTrue($model->isAudio());
    }

    public function audioMimeProvider(): array
    {
        return [
            'mp3'  => ['audio/mpeg'],
            'wav'  => ['audio/wav'],
            'ogg'  => ['audio/ogg'],
        ];
    }

    // ============================================================
    // getIconClass() 测试
    // ============================================================

    /**
     * @test 测试 getIconClass() 对图片返回图片图标
     */
    public function testGetIconClassReturnsImageIconForImage(): void
    {
        $model = $this->createModel(['is_image' => 1, 'mime_type' => 'image/jpeg']);

        $this->assertEquals('bi-file-image', $model->getIconClass());
    }

    /**
     * @test 测试 getIconClass() 对 PDF 返回 PDF 图标
     */
    public function testGetIconClassReturnsPdfIconForPdf(): void
    {
        $model = $this->createModel(['mime_type' => 'application/pdf', 'is_image' => 0]);

        $this->assertEquals('bi-file-pdf', $model->getIconClass());
    }

    /**
     * @test 测试 getIconClass() 对视频返回视频图标
     */
    public function testGetIconClassReturnsVideoIconForVideo(): void
    {
        $model = $this->createModel(['mime_type' => 'video/mp4', 'is_image' => 0]);

        $this->assertEquals('bi-file-play', $model->getIconClass());
    }

    /**
     * @test 测试 getIconClass() 对音频返回音频图标
     */
    public function testGetIconClassReturnsAudioIconForAudio(): void
    {
        $model = $this->createModel(['mime_type' => 'audio/mpeg', 'is_image' => 0]);

        $this->assertEquals('bi-file-music', $model->getIconClass());
    }

    /**
     * @test 测试 getIconClass() 根据扩展名返回对应图标
     * @dataProvider extensionIconProvider
     */
    public function testGetIconClassReturnsCorrectIconByExtension(string $objectName, string $expectedIcon): void
    {
        $model = $this->createModel([
            'mime_type'   => 'application/octet-stream',
            'is_image'    => 0,
            'object_name' => $objectName,
        ]);

        $this->assertEquals($expectedIcon, $model->getIconClass());
    }

    public function extensionIconProvider(): array
    {
        return [
            'zip'  => ['archive.zip',  'bi-file-zip'],
            'doc'  => ['document.doc',  'bi-file-word'],
            'docx' => ['document.docx', 'bi-file-word'],
            'xls'  => ['sheet.xls',    'bi-file-excel'],
            'xlsx' => ['sheet.xlsx',   'bi-file-excel'],
            'ppt'  => ['slides.ppt',   'bi-file-ppt'],
            'txt'  => ['readme.txt',   'bi-file-text'],
            'html' => ['index.html',   'bi-file-code'],
            'php'  => ['script.php',   'bi-file-code'],
            'json' => ['data.json',    'bi-file-code'],
            'unknown' => ['file.xyz',  'bi-file'],
        ];
    }

    // ============================================================
    // getHumanSize() 测试
    // ============================================================

    /**
     * @test 测试 getHumanSize() 格式化文件大小
     * @dataProvider humanSizeProvider
     */
    public function testGetHumanSizeFormatsCorrectly(int $bytes, string $expected, int $precision = 2): void
    {
        $model = $this->createModel(['size' => $bytes]);

        $this->assertEquals($expected, $model->getHumanSize($precision));
    }

    public function humanSizeProvider(): array
    {
        return [
            '0_bytes'   => [0,       '0 B'],
            '500_bytes' => [500,     '500 B'],
            '1_kb'      => [1024,    '1 KB'],
            '1500_bytes'=> [1536,    '1.5 KB'],
            '1_mb'      => [1048576, '1 MB'],
            '2500_kb'   => [2560000, '2.44 MB'],
            '1_gb'      => [1073741824, '1 GB'],
            '5_gb'      => [5368709120, '5 GB'],
            '0_precise' => [1536,    '2 KB', 0],
        ];
    }

    // ============================================================
    // getExtension() 测试
    // ============================================================

    /**
     * @test 测试 getExtension() 正确提取扩展名
     * @dataProvider extensionProvider
     */
    public function testGetExtensionExtractsCorrectly(string $objectName, string $expectedExt): void
    {
        $model = $this->createModel(['object_name' => $objectName]);

        $this->assertEquals($expectedExt, $model->getExtension());
    }

    public function extensionProvider(): array
    {
        return [
            'jpg'      => ['2024/01/01/photo.jpg',          'jpg'],
            'png'      => ['images/screenshot.png',         'png'],
            'pdf_upper'=> ['docs/report.PDF',               'pdf'],
            'no_ext'   => ['folder/file_without_ext',       ''],
            'deep_path'=> ['a/b/c/d/e/f/file.txt',          'txt'],
            'multi_dot'=> ['archive.tar.gz',                'gz'],
        ];
    }

    // ============================================================
    // search() 测试
    // ============================================================

    /**
     * @test 测试 search() 关键词模糊搜索
     */
    public function testSearchByKeywordReturnsMatchingQuery(): void
    {
        // 插入测试数据
        $this->insertAttachment([
            'storage'     => 'local',
            'object_name' => 'photos/sunset.jpg',
            'url'         => '/uploads/photos/sunset.jpg',
            'size'        => 102400,
            'mime_type'   => 'image/jpeg',
        ]);
        $this->insertAttachment([
            'storage'     => 'local',
            'object_name' => 'docs/readme.txt',
            'url'         => '/uploads/docs/readme.txt',
            'size'        => 2048,
            'mime_type'   => 'text/plain',
        ]);

        $model  = new AttachmentModel();
        $query  = $model->search(['keyword' => 'sunset']);
        $result = $query->select();

        $this->assertCount(1, $result);
        $this->assertEquals('photos/sunset.jpg', $result[0]->object_name);
    }

    /**
     * @test 测试 search() 按存储类型过滤
     */
    public function testSearchByStorageFilter(): void
    {
        $this->insertAttachment([
            'storage'     => 'local',
            'object_name' => 'file1.jpg',
            'url'         => '/uploads/file1.jpg',
            'size'        => 100,
            'mime_type'   => 'image/jpeg',
        ]);
        $this->insertAttachment([
            'storage'     => 'ftp',
            'object_name' => 'file2.jpg',
            'url'         => '/ftp/file2.jpg',
            'size'        => 200,
            'mime_type'   => 'image/jpeg',
        ]);

        $model  = new AttachmentModel();
        $query  = $model->search(['storage' => 'ftp']);
        $result = $query->select();

        $this->assertCount(1, $result);
        $this->assertEquals('ftp', $result[0]->storage);
    }

    /**
     * @test 测试 search() 按 MIME 类型前缀过滤
     */
    public function testSearchByMimeTypePrefix(): void
    {
        $this->insertAttachment([
            'storage'     => 'local',
            'object_name' => 'image.jpg',
            'url'         => '/uploads/image.jpg',
            'size'        => 1000,
            'mime_type'   => 'image/jpeg',
            'is_image'    => 1,
        ]);
        $this->insertAttachment([
            'storage'     => 'local',
            'object_name' => 'doc.pdf',
            'url'         => '/uploads/doc.pdf',
            'size'        => 2000,
            'mime_type'   => 'application/pdf',
            'is_image'    => 0,
        ]);

        $model  = new AttachmentModel();
        $query  = $model->search(['mime_type' => 'image/']);
        $result = $query->select();

        $this->assertCount(1, $result);
        $this->assertEquals('image/jpeg', $result[0]->mime_type);
    }

    /**
     * @test 测试 search() 按 is_image 过滤
     */
    public function testSearchByIsImageFilter(): void
    {
        $this->insertAttachment([
            'storage'     => 'local',
            'object_name' => 'photo.jpg',
            'url'         => '/uploads/photo.jpg',
            'size'        => 5000,
            'mime_type'   => 'image/png',
            'is_image'    => 1,
        ]);
        $this->insertAttachment([
            'storage'     => 'local',
            'object_name' => 'doc.pdf',
            'url'         => '/uploads/doc.pdf',
            'size'        => 3000,
            'mime_type'   => 'application/pdf',
            'is_image'    => 0,
        ]);

        $model  = new AttachmentModel();
        $query  = $model->search(['is_image' => 1]);
        $result = $query->select();

        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]->is_image);
    }

    /**
     * @test 测试 search() 空参数返回所有记录（按 id 倒序）
     */
    public function testSearchWithEmptyParamsReturnsAllRecords(): void
    {
        $this->insertAttachment([
            'storage'     => 'local',
            'object_name' => 'first.jpg',
            'url'         => '/uploads/first.jpg',
            'size'        => 100,
            'mime_type'   => 'image/jpeg',
        ]);
        $this->insertAttachment([
            'storage'     => 'local',
            'object_name' => 'second.jpg',
            'url'         => '/uploads/second.jpg',
            'size'        => 200,
            'mime_type'   => 'image/jpeg',
        ]);

        $model  = new AttachmentModel();
        $query  = $model->search([]);
        $result = $query->select();

        $this->assertCount(2, $result);
        // 按 id 倒序，第二条应在前面
        $this->assertEquals('second.jpg', $result[0]->object_name);
    }

    // ============================================================
    // SoftDelete 测试
    // ============================================================

    /**
     * @test 测试软删除：delete() 后记录存在但 delete_time 不为 0
     */
    public function testSoftDeleteSetsDeleteTime(): void
    {
        $id = $this->insertAttachment([
            'storage'     => 'local',
            'object_name' => 'to_delete.jpg',
            'url'         => '/uploads/to_delete.jpg',
            'size'        => 100,
            'mime_type'   => 'image/jpeg',
        ]);

        $model = AttachmentModel::find($id);
        $this->assertNotNull($model, "应能通过 ID={$id} 找到记录");

        $model->delete();

        // 使用 withTrashed 查询被软删除的记录
        $deleted = AttachmentModel::withTrashed()->find($id);
        $this->assertNotNull($deleted, "withTrashed 应能找到软删除记录");
        $this->assertGreaterThan(0, $deleted->delete_time);
    }

    /**
     * @test 测试软删除后默认查询不返回已删记录
     */
    public function testSoftDeletedRecordNotReturnedInDefaultQuery(): void
    {
        $id = $this->insertAttachment([
            'storage'     => 'local',
            'object_name' => 'hidden.jpg',
            'url'         => '/uploads/hidden.jpg',
            'size'        => 100,
            'mime_type'   => 'image/jpeg',
        ]);

        $model = AttachmentModel::find($id);
        $this->assertNotNull($model, "应能通过 ID={$id} 找到记录");
        $model->delete();

        $found = AttachmentModel::find($id);
        $this->assertNull($found, "默认查询不应返回软删除记录");
    }

    // ============================================================
    // 辅助方法
    // ============================================================

    /**
     * 创建内存模型实例（不存储到数据库）
     *
     * @param array $attributes 模型属性
     * @return AttachmentModel
     */
    private function createModel(array $attributes): AttachmentModel
    {
        $model = new AttachmentModel();
        // 确保默认值存在
        $defaults = [
            'id'          => 1,
            'storage'     => 'local',
            'object_name' => 'test/file.jpg',
            'url'         => '/uploads/test/file.jpg',
            'size'        => 1024,
            'mime_type'   => 'image/jpeg',
            'is_image'    => 0,
            'delete_time' => 0,
        ];
        $attributes = array_merge($defaults, $attributes);

        foreach ($attributes as $key => $value) {
            $model->setAttr($key, $value);
        }

        // 标记为已存在的记录，避免 save() 时 insert
        $model->exists(true);

        return $model;
    }

    /**
     * 插入附件测试记录
     *
     * @param array $data 记录数据
     * @return int 插入后的 ID
     */
    private function insertAttachment(array $data): int
    {
        $defaults = [
            'storage'      => 'local',
            'object_name'  => 'test.jpg',
            'url'          => '/uploads/test.jpg',
            'size'         => 1024,
            'mime_type'    => 'image/jpeg',
            'image_width'  => 0,
            'image_height' => 0,
            'is_image'     => 0,
            'delete_time'  => 0,
            'create_time'  => time(),
            'update_time'  => time(),
        ];
        $data = array_merge($defaults, $data);

        return (int) Db::table('ws_attachment')->insertGetId($data);
    }
}
