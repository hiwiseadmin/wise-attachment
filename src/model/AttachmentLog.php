<?php
declare(strict_types=1);

namespace wise\attachment\model;

use think\Model;

/**
 * 附件操作日志模型
 *
 * @property int    $id
 * @property int    $attachment_id 附件ID
 * @property string $action        操作类型
 * @property int    $operator_id   操作人ID
 * @property string $operator_type 操作人类型
 * @property string $operator_name 操作人名称
 * @property string $ip            操作IP
 * @property string $detail        详情JSON
 */
class AttachmentLog extends Model
{
    protected $name = 'attachment_log';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = false;

    protected $type = [
        'attachment_id' => 'integer',
        'operator_id'   => 'integer',
    ];

    public function __construct($data = [])
    {
        parent::__construct($data);
        $this->setName(config('wise-attachment.table_names.log', $this->name));
    }

    /**
     * 关联：附件
     */
    public function attachment()
    {
        return $this->belongsTo(AttachmentModel::class, 'attachment_id', 'id');
    }

    /**
     * 记录操作日志
     *
     * @param int    $attachmentId   附件ID
     * @param string $action         操作类型: upload|delete|update|download|view|copy|move
     * @param array  $context        上下文信息:
     *   - operator_id:   int    操作人ID
     *   - operator_type: string 操作人类型
     *   - operator_name: string 操作人名称
     *   - ip:            string IP地址
     *   - detail:        array  额外详情
     * @return static
     */
    public static function record(int $attachmentId, string $action, array $context = []): self
    {
        return static::create([
            'attachment_id' => $attachmentId,
            'action'        => $action,
            'operator_id'   => $context['operator_id'] ?? 0,
            'operator_type' => $context['operator_type'] ?? 'system',
            'operator_name' => $context['operator_name'] ?? '',
            'ip'            => $context['ip'] ?? (request()->ip() ?? ''),
            'detail'        => !empty($context['detail']) ? json_encode($context['detail'], JSON_UNESCAPED_UNICODE) : '',
        ]);
    }

    /**
     * 查询附件的操作历史
     */
    public static function getAttachmentHistory(int $attachmentId, int $limit = 50): array
    {
        return static::where('attachment_id', $attachmentId)
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    /**
     * 按操作类型查询
     */
    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }
}
