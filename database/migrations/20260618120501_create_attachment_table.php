<?php
declare(strict_types=1);

/**
 *
 * 创建 attachment 核心表。
 *
 */
use think\migration\Migrator;
use think\migration\db\Column;

class CreateAttachmentTable extends Migrator
{
    protected function getTable(): string
    {
        return config('wise-attachment.table_names.attachment', 'attachment');
    }

    /**
     * 执行迁移
     *
     * @return void
     */
    public function up(): void
    {
        if (!$this->hasTable($this->getTable())) {
            $table = $this->table($this->getTable(), [
                'engine'    => 'InnoDB',
                'comment'   => '附件表',
                'collation' => 'utf8mb4_unicode_ci',
            ]);

            $table->addColumn(Column::string('storage', 32)->setDefault('local')->setNull(false)->setComment('存储驱动标识(local/ftp/qiniu/alioss/cos)'))
                ->addColumn(Column::string('object_name', 500)->setDefault('')->setNull(false)->setComment('存储对象名/相对路径'))
                ->addColumn(Column::string('url', 1000)->setDefault('')->setNull(false)->setComment('文件访问URL'))
                ->addColumn(Column::bigInteger('size')->setDefault(0)->setNull(false)->setComment('文件大小(字节)'))
                ->addColumn(Column::string('mime_type', 128)->setDefault('application/octet-stream')->setNull(false)->setComment('MIME类型'))
                ->addColumn(Column::integer('image_width')->setDefault(0)->setNull(false)->setComment('图片宽度(非图片为0)'))
                ->addColumn(Column::integer('image_height')->setDefault(0)->setNull(false)->setComment('图片高度(非图片为0)'))
                ->addColumn(Column::boolean('is_image')->setDefault(0)->setNull(false)->setComment('是否为图片(0:否 1:是)'))
                // ↓ 已合并 AddContentHashToAttachment
                ->addColumn(Column::string('content_hash', 64)->setDefault('')->setNull(false)->setComment('文件内容 SHA256 哈希'))
                ->addColumn(Column::boolean('is_duplicate')->setDefault(0)->setNull(false)->setComment('是否为去重复用的记录(0:否 1:是)'))
                ->addColumn(Column::integer('original_attachment_id')->setDefault(0)->setNull(false)->setComment('去重时引用的原始附件ID(0表示非去重)'))
                // ↓ 已合并 AddCategoryIdToAttachment
                ->addColumn(Column::integer('category_id')->setDefault(0)->setNull(false)->setComment('所属分类ID(0表示未分类)'))
                ->addColumn(Column::dateTime('delete_time')->setNull(true)->setComment('软删除时间(null表示未删除)'))
                ->addColumn(Column::dateTime('create_time')->setNull(true)->setComment('创建时间'))
                ->addColumn(Column::dateTime('update_time')->setNull(true)->setComment('更新时间'))
                ->addIndex(['storage'], ['name' => 'idx_storage'])
                ->addIndex(['mime_type'], ['name' => 'idx_mime_type'])
                ->addIndex(['is_image'], ['name' => 'idx_is_image'])
                ->addIndex(['content_hash'], ['name' => 'idx_content_hash'])
                ->addIndex(['original_attachment_id'], ['name' => 'idx_original_id'])
                ->addIndex(['category_id'], ['name' => 'idx_category_id'])
                ->addIndex(['delete_time'], ['name' => 'idx_delete_time'])
                ->addIndex(['create_time'], ['name' => 'idx_create_time'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable($this->getTable())) {
            $this->table($this->getTable())->drop();
        }
    }
}
