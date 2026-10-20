<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

class CreateAttachmentLogsTable extends Migrator
{
    protected function getTable(): string
    {
        return 'attachment_log';
    }

    public function up(): void
    {
        if (!$this->hasTable($this->getTable())) {
            $table = $this->table($this->getTable(), [
                'engine'    => 'InnoDB',
                'comment'   => '附件操作日志表',
                'collation' => 'utf8mb4_unicode_ci',
            ]);

            $table->addColumn(Column::integer('attachment_id')->setDefault(0)->setNull(false)->setComment('附件ID'))
                ->addColumn(Column::string('action', 32)->setDefault('')->setNull(false)->setComment('操作类型: upload/delete/update/download/view/copy/move'))
                ->addColumn(Column::integer('operator_id')->setDefault(0)->setNull(false)->setComment('操作人ID(0表示系统)'))
                ->addColumn(Column::string('operator_type', 100)->setDefault('')->setNull(false)->setComment('操作人类型(admin/member/system)'))
                ->addColumn(Column::string('operator_name', 100)->setDefault('')->setNull(false)->setComment('操作人名称'))
                ->addColumn(Column::string('ip', 45)->setDefault('')->setNull(false)->setComment('操作IP'))
                ->addColumn(Column::text('detail')->setNull(true)->setComment('操作详情(JSON)'))
                ->addColumn(Column::dateTime('create_time')->setNull(true)->setComment('创建时间'));

            $table->addIndex(['attachment_id'], ['name' => 'idx_attachment_id']);
            $table->addIndex(['action'], ['name' => 'idx_action']);
            $table->addIndex(['operator_id'], ['name' => 'idx_operator_id']);
            $table->addIndex(['create_time'], ['name' => 'idx_create_time']);

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
