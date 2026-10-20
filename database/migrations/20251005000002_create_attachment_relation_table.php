<?php
declare(strict_types=1);

/**
 *
 * 创建 attachment_relation 多态关联表。
 *
 */
use think\migration\Migrator;
use think\migration\db\Column;

class CreateAttachmentRelationTable extends Migrator
{
    protected function getTable(): string
    {
        return 'attachment_relation';
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
                'comment'   => '附件多态关联表',
                'collation' => 'utf8mb4_unicode_ci',
            ]);

            $table->addColumn(Column::integer('attachment_id')->setDefault(0)->setNull(false)->setComment('附件ID'))
                ->addColumn(Column::string('relation_type', 200)->setDefault('')->setNull(false)->setComment('关联类型(业务模型类名)'))
                ->addColumn(Column::integer('relation_id')->setDefault(0)->setNull(false)->setComment('关联业务模型ID'))
                ->addIndex(['attachment_id', 'relation_type', 'relation_id'], ['unique' => true, 'name'   => 'uk_att_rel'])
                ->addIndex(['relation_type', 'relation_id'], ['name' => 'idx_relation'])
                ->addIndex(['attachment_id'], ['name' => 'idx_attachment_id'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable($this->getTable())) {
            $this->table($this->getTable())->drop()->save();
        }
    }
}
