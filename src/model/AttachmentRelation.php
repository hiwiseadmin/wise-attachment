<?php
declare(strict_types=1);

/**
 * 附件多态关联模型
 *
 * 对应数据表 attachment_relation，建立附件与任意业务模型的多态关联关系。
 *
 * @property int    $id              主键
 * @property int    $attachment_id   附件 ID
 * @property string $relation_type   关联业务模型类型（完整类名）
 * @property int    $relation_id     关联业务模型 ID
 */
namespace wise\attachment\model;

use think\Model;

class AttachmentRelation extends Model
{
    /**
     * 表名（不含前缀，框架自动添加）
     *
     * @var string
     */
    protected $name = 'attachment_relation';

    /**
     * 关闭自动时间戳（此表不记录时间）
     *
     * @var bool
     */
    protected $autoWriteTimestamp = false;

    /**
     * 允许批量赋值的字段
     *
     * @var array
     */
    protected $fillable = [
        'attachment_id',
        'relation_type',
        'relation_id',
    ];

    /**
     * 字段类型转换
     *
     * @var array
     */
    protected $type = [
        'attachment_id' => 'integer',
        'relation_id'   => 'integer',
    ];

    /**
     * 关联：所属附件
     *
     * @return \think\model\relation\BelongsTo
     */
    public function attachment()
    {
        return $this->belongsTo(AttachmentModel::class, 'attachment_id', 'id');
    }

    /**
     * 多态关联：获取关联的业务模型
     *
     * ThinkPHP 6 中 morphTo 需要指定 type 和 id 字段名。
     *
     * @return \think\model\relation\MorphTo
     */
    public function relation()
    {
        return $this->morphTo('relation', [
            'type' => 'relation_type',
            'id'   => 'relation_id',
        ]);
    }

    /**
     * 判断附件是否存在有效引用（未被软删除的附件仍然有引用关系）
     *
     * @param int $attachmentId 附件 ID
     * @return bool
     */
    public static function hasValidRef(int $attachmentId): bool
    {
        return (bool) self::where('attachment_id', $attachmentId)->count();
    }

    /**
     * 获取附件被引用的业务模型数量
     *
     * @param int $attachmentId 附件 ID
     * @return int
     */
    public static function getRefCount(int $attachmentId): int
    {
        return (int) self::where('attachment_id', $attachmentId)->count();
    }

    /**
     * 建立关联（幂等操作，已存在则直接返回）
     *
     * @param int    $attachmentId 附件 ID
     * @param string $relationType 业务模型类型（完整类名）
     * @param int    $relationId   业务模型 ID
     * @return static
     */
    public static function link(int $attachmentId, string $relationType, int $relationId): self
    {
        $exists = self::where('attachment_id', $attachmentId)
            ->where('relation_type', $relationType)
            ->where('relation_id', $relationId)
            ->find();

        if ($exists) {
            return $exists;
        }

        return self::create([
            'attachment_id' => $attachmentId,
            'relation_type' => $relationType,
            'relation_id'   => $relationId,
        ]);
    }

    /**
     * 解除关联
     *
     * @param int    $attachmentId 附件 ID
     * @param string $relationType 业务模型类型
     * @param int    $relationId   业务模型 ID
     * @return bool
     */
    public static function unlink(int $attachmentId, string $relationType, int $relationId): bool
    {
        return (bool) self::where('attachment_id', $attachmentId)
            ->where('relation_type', $relationType)
            ->where('relation_id', $relationId)
            ->delete();
    }

    /**
     * 清理指定业务模型的所有附件关联（用于业务模型删除时）
     *
     * @param string $relationType 业务模型类型
     * @param int    $relationId   业务模型 ID
     * @return bool
     */
    public static function clearFor(string $relationType, int $relationId): bool
    {
        return (bool) self::where('relation_type', $relationType)
            ->where('relation_id', $relationId)
            ->delete();
    }
}
