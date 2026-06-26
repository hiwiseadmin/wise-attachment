<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

class CreateAttachmentVersionsTable extends Migrator
{
    protected function getTable(): string
    {
        return config('wise-attachment.table_names.versions', 'attachment_version');
    }

    public function up(): void
    {
        if (!$this->hasTable($this->getTable())) {
            $table = $this->table($this->getTable(), [
                'engine'    => 'InnoDB',
                'comment'   => '附件版本表',
                'collation' => 'utf8mb4_unicode_ci',
            ]);

            $table->addColumn(Column::integer('attachment_id')->setDefault(0)->setNull(false)->setComment('附件ID'))
                ->addColumn(Column::integer('version_number')->setDefault(1)->setNull(false)->setComment('版本号'))
                ->addColumn(Column::string('object_name', 500)->setDefault('')->setNull(false)->setComment('该版本的存储对象名'))
                ->addColumn(Column::string('url', 1000)->setDefault('')->setNull(false)->setComment('该版本的访问URL'))
                ->addColumn(Column::bigInteger('size')->setDefault(0)->setNull(false)->setComment('文件大小(字节)'))
                ->addColumn(Column::string('mime_type', 128)->setDefault('application/octet-stream')->setNull(false)->setComment('MIME类型'))
                ->addColumn(Column::string('content_hash', 64)->setDefault('')->setNull(false)->setComment('SHA256 哈希'))
                ->addColumn(Column::integer('operator_id')->setDefault(0)->setNull(false)->setComment('操作人ID'))
                ->addColumn(Column::text('note')->setNull(true)->setComment('版本说明'))
                ->addColumn(Column::dateTime('create_time')->setNull(true)->setComment('创建时间'));

            $table->addIndex(['attachment_id'], ['name' => 'idx_attachment_id']);
            $table->addIndex(['attachment_id', 'version_number'], ['name' => 'idx_attach_ver']);

            $table->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable($this->getTable())) {
            $this->table($this->getTable())->drop();
        }
    }
}
