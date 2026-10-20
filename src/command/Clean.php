<?php
declare(strict_types=1);

/**
 * 附件清理命令
 *
 * 物理删除超过保留期限的软删除附件。
 * 默认仅删除不再被任何业务模型引用的附件（可通过 --force 强制删除）。
 *
 * 使用:
 *   php think wise:attachment:clean                 # 安全清理
 *   php think wise:attachment:clean --days=7         # 保留 7 天
 *   php think wise:attachment:clean --force          # 忽略引用强制清理
 *   php think wise:attachment:clean --dry-run        # 预览将要删除的附件
 */
namespace wise\attachment\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use think\facade\Config;
use wise\attachment\factory\AttachmentStore;
use wise\attachment\model\AttachmentModel;
use wise\attachment\model\AttachmentRelation;

class Clean extends Command
{
    /**
     * 配置命令
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('wise:attachment:clean')
            ->setDescription('清理超过保留期限的软删除附件')
            ->addOption('days',    'd', Option::VALUE_OPTIONAL, '保留天数（覆盖配置）', null)
            ->addOption('force',   'f', Option::VALUE_NONE,     '强制删除，跳过引用检查')
            ->addOption('dry-run', null, Option::VALUE_NONE,    '预览模式，仅显示将要删除的附件，不实际删除');
    }

    /**
     * 执行清理
     *
     * @param Input  $input
     * @param Output $output
     * @return int
     */
    protected function execute(Input $input, Output $output): int
    {
        $daysOption    = $input->getOption('days');
        $retentionDays = is_numeric($daysOption) ? (int) $daysOption : (int) Config::get('wise-attachment.clean.retention_days', 30);
        if ($retentionDays <= 0) {
            $output->error("保留天数必须大于 0，当前值: {$retentionDays}");
            return 1;
        }
        $batchSize     = (int) Config::get('wise-attachment.clean.batch_size', 100);
        $force         = (bool) $input->getOption('force');
        $dryRun        = (bool) $input->getOption('dry-run');

        $cutoffTime = time() - ($retentionDays * 86400);

        $output->info("═══════════════════════════════════");
        $output->info("  附件清理任务启动");
        $output->info("═══════════════════════════════════");
        $output->info("  保留天数: {$retentionDays} 天（截止: " . date('Y-m-d H:i:s', $cutoffTime) . "）");
        $output->info("  批次大小: {$batchSize}");
        $output->info("  执行模式: " . ($dryRun ? '预览模式（不执行删除）' : ($force ? '强制模式（跳过引用检查）' : '安全模式（检查引用）')));
        $output->writeln('');

        /** @var AttachmentStore $store */
        $store = app()->make(AttachmentStore::class);

        $totalDeleted   = 0;
        $totalSkipped   = 0;
        $totalErrors    = 0;
        $totalProcessed = 0;

        // 分页查询被软删除的附件（仅获取超过保留期限的）
        AttachmentModel::onlyTrashed()
            ->where('delete_time', '<=', $cutoffTime)
            ->chunk($batchSize, function ($attachments) use ($store, $force, $dryRun, $output, &$totalDeleted, &$totalSkipped, &$totalErrors, &$totalProcessed) {
                // 批量预加载引用关系，避免 N+1 查询
                $referencedIds = [];
                if (!$force) {
                    $ids = array_map('intval', $attachments->column('id'));

                    // 1) 被业务模型引用的附件不可物理删除
                    //    （统一转 int，避免 in_array 强类型比较时 DB 返回字符串导致漏判）
                    $referencedIds = array_map('intval', AttachmentRelation::whereIn('attachment_id', $ids)
                        ->distinct()
                        ->column('attachment_id'));

                    // 2) 被去重记录复用的「原始附件」同样不可删除：存储对象是共享的，
                    //    删掉原始文件会让所有 is_duplicate=1 的活记录变成 404 悬空引用
                    $dedupReferencedIds = array_map('intval', AttachmentModel::whereIn('original_attachment_id', $ids)
                        ->where('is_duplicate', 1)
                        ->distinct()
                        ->column('original_attachment_id'));

                    $referencedIds = array_values(array_unique(array_merge($referencedIds, $dedupReferencedIds)));
                }

                /** @var AttachmentModel $attachment */
                foreach ($attachments as $attachment) {
                    $totalProcessed++;

                    // 检查引用关系（force 模式跳过）
                    if (!$force && in_array($attachment->id, $referencedIds, true)) {
                        $totalSkipped++;
                        if ($output->isVerbose()) {
                            $output->writeln("  [跳过] #{$attachment->id} — 仍被业务模型引用");
                        }
                        continue;
                    }

                    if ($dryRun) {
                        $totalDeleted++;
                        $output->writeln("  [预览] 将删除 #{$attachment->id} — {$attachment->object_name}");
                        continue;
                    }

                    try {
                        // 物理删除文件
                        $driver = $store->driver($attachment->storage);
                        $driver->delete($attachment->object_name);

                        // 硬删除数据库记录
                        // ThinkPHP SoftDelete: force(true) 后再 delete() 可绕过软删除
                        $attachment->force(true)->delete();

                        $totalDeleted++;
                        if ($output->isVerbose()) {
                            $output->writeln("  [完成] 已删除 #{$attachment->id} — {$attachment->object_name}");
                        }
                    } catch (\Throwable $e) {
                        $totalErrors++;
                        $output->writeln("  [错误] #{$attachment->id} (storage: {$attachment->storage}) — {$e->getMessage()}");
                    }
                }
            });

        // 汇总输出
        $output->writeln('');
        $output->info("═══════════════════════════════════");
        $output->info("  清理完成！");
        $output->info("  处理数量: {$totalProcessed}");
        $output->info("  已删除:   {$totalDeleted}" . ($dryRun ? '（预览模式）' : ''));
        $output->info("  已跳过:   {$totalSkipped}（仍被引用）");
        $output->info("  出错:     {$totalErrors}");
        $output->info("═══════════════════════════════════");

        return 0;
    }
}
