<?php
declare(strict_types=1);

namespace wise\attachment\model;

use think\Model;

/**
 * 附件版本模型
 *
 * @property int    $id
 * @property int    $attachment_id 附件ID
 * @property int    $version_number 版本号（从1开始递增）
 * @property string $object_name   存储对象名
 * @property string $url           访问URL
 * @property int    $size          文件大小
 * @property string $mime_type     MIME类型
 * @property string $content_hash  SHA256
 * @property int    $operator_id   操作人ID
 * @property string $note          版本说明
 */
class AttachmentVersion extends Model
{
    protected $name = 'attachment_version';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = false;

    protected $type = [
        'attachment_id'  => 'integer',
        'version_number' => 'integer',
        'size'           => 'integer',
        'operator_id'    => 'integer',
    ];

    public function __construct($data = [])
    {
        parent::__construct($data);
        $this->setName(config('wise-attachment.table_names.versions', $this->name));
    }

    /**
     * 关联：所属附件
     */
    public function attachment()
    {
        return $this->belongsTo(AttachmentModel::class, 'attachment_id', 'id');
    }

    /**
     * 获取该附件的下一个版本号
     */
    public static function getNextVersionNumber(int $attachmentId): int
    {
        $max = static::where('attachment_id', $attachmentId)
            ->max('version_number');
        return ($max ? (int)$max : 0) + 1;
    }

    /**
     * 获取附件所有版本列表（倒序）
     */
    public static function getVersionsOf(int $attachmentId): array
    {
        return static::where('attachment_id', $attachmentId)
            ->order('version_number', 'desc')
            ->select()
            ->toArray();
    }
}
