<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

class CreateAttachmentCategoriesTable extends Migrator
{
    protected function getTable(): string
    {
        return 'attachment_category';
    }

    public function up(): void
    {
        if (!$this->hasTable($this->getTable())) {
            $table = $this->table($this->getTable(), [
                'engine'    => 'InnoDB',
                'comment'   => '附件分类表',
                'collation' => 'utf8mb4_unicode_ci',
            ]);

            $table->addColumn(Column::string('name', 100)->setDefault('')->setNull(false)->setComment('分类名称'))
                ->addColumn(Column::integer('parent_id')->setDefault(0)->setNull(false)->setComment('父分类ID(0表示顶级)'))
                ->addColumn(Column::string('icon', 50)->setDefault('')->setNull(false)->setComment('图标CSS类名'))
                ->addColumn(Column::string('description', 500)->setDefault('')->setNull(false)->setComment('分类描述'))
                ->addColumn(Column::integer('sort_order')->setDefault(0)->setNull(false)->setComment('排序权重(越大越靠前)'))
                ->addColumn(Column::boolean('is_system')->setDefault(0)->setNull(false)->setComment('是否为系统预置分类(0:否 1:是)'))
                ->addColumn(Column::dateTime('create_time')->setNull(true)->setComment('创建时间'))
                ->addColumn(Column::dateTime('update_time')->setNull(true)->setComment('更新时间'));

            $table->addIndex(['parent_id'], ['name' => 'idx_parent_id']);

            $table->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable($this->getTable())) {
            $this->table($this->getTable())->drop()->save();
        }
    }
}
