<?php
declare(strict_types=1);

namespace wise\attachment\trait;

use wise\attachment\model\AttachmentLog;

/**
 * 附件日志记录 Trait
 *
 * 控制器或 Service 引入后可以自动记录附件操作日志。
 *
 * 使用示例：
 * class AttachmentController
 * {
 *     use \wise\attachment\trait\LoggableAttachment;
 *
 *     public function delete($id)
 *     {
 *         $attachment = AttachmentModel::find($id);
 *         // ... 删除操作 ...
 *         $this->logAttachmentAction($attachment, 'delete', [
 *             'detail' => ['file_name' => $attachment->object_name]
 *         ]);
 *     }
 * }
 */
trait LoggableAttachment
{
    /**
     * 记录附件操作日志（便捷方法）
     *
     * @param \wise\attachment\model\AttachmentModel $attachment 附件
     * @param string                                  $action     操作类型
     * @param array                                   $extra      额外信息
     * @return \wise\attachment\model\AttachmentLog|null
     */
    protected function logAttachmentAction($attachment, string $action, array $extra = []): ?AttachmentLog
    {
        if (!$attachment || !$attachment->getKey()) {
            return null;
        }

        $context = array_merge([
            'operator_id'   => $this->getLogOperatorId(),
            'operator_type' => $this->getLogOperatorType(),
            'operator_name' => $this->getLogOperatorName(),
            'ip'            => request()->ip(),
            'detail'        => $extra,
        ], $extra);

        return AttachmentLog::record((int) $attachment->getKey(), $action, $context);
    }

    /**
     * 获取操作人ID（子类可覆盖）
     */
    protected function getLogOperatorId(): int
    {
        // 尝试从 session 获取
        $adminId = session('admin_id');
        return $adminId ? (int) $adminId : 0;
    }

    /**
     * 获取操作人类型（子类可覆盖）
     */
    protected function getLogOperatorType(): string
    {
        return session('admin_id') ? 'admin' : 'system';
    }

    /**
     * 获取操作人名称（子类可覆盖）
     */
    protected function getLogOperatorName(): string
    {
        return session('admin_nickname') ?: '';
    }
}
