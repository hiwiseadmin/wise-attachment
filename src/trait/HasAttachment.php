<?php
declare(strict_types=1);

/**
 * 多态附件关联 Trait
 *
 * 业务模型引入后，可通过以下方法操作附件：
 * - attachments()              一对多多态关联
 * - attachment()               一对一多态关联（第一个附件）
 * - attach($attachment, $extra)  添加附件关联
 * - detach($attachment)          移除附件关联
 * - syncAttachment($ids)         同步附件关联（先清空再添加）
 * - getAttachments()             获取附件完整数据
 * - clearAttachments()           清理所有关联
 *
 * 使用示例：
 * ```php
 * // 在业务模型中引入本 Trait
 * class Article extends Model
 * {
 *     use \wise\attachment\trait\HasAttachment;
 *
 *     // 可选：自定义关联类型标识
 *     // protected $relationType = 'article';
 *
 *     // 推荐：注册模型删除事件，自动清理关联
 *     protected static function init()
 *     {
 *         static::afterDelete(function ($model) {
 *             $model->clearAttachments();
 *         });
 *     }
 * }
 *
 * // 使用
 * $article = Article::find(1);
 * $article->attach(1);                        // 添加附件
 * $article->attach($attachmentModel);         // 支持模型或 ID
 * $article->syncAttachment([1, 2, 3]);        // 同步附件
 * $article->detach(1);                         // 移除单个附件
 * $attachments = $article->getAttachments();   // 获取所有附件
 * ```
 */
namespace wise\attachment\trait;

use think\db\Query;
use wise\attachment\model\AttachmentModel;
use wise\attachment\model\AttachmentRelation;

trait HasAttachment
{
    /**
     * 一对多多态关联：获取所有附件关联记录
     *
     * ThinkPHP 6 morphMany 签名:
     * morphMany($model, $morph, $type, $id = null, $localKey = null)
     *
     * @return \think\model\relation\MorphMany
     */
    public function attachments()
    {
        return $this->morphMany(
            AttachmentRelation::class,
            ['type' => 'relation_type', 'id' => 'relation_id'],
            'relation_type',
            'relation_id',
            $this->getPk()
        );
    }

    /**
     * 一对一多态关联：获取主附件（第一个关联的附件）
     *
     * @return \think\model\relation\MorphOne
     */
    public function attachment()
    {
        return $this->morphOne(
            AttachmentRelation::class,
            ['type' => 'relation_type', 'id' => 'relation_id'],
            'relation_type',
            'relation_id',
            $this->getPk()
        )->order('id', 'asc');
    }

    /**
     * 获取附件完整数据（含附件详情，非仅关联记录）
     *
     * 通过 JOIN 查询直接获取 AttachmentModel 的完整数据。
     *
     * @return \think\Collection
     */
    public function getAttachments()
    {
        $relationType = $this->getRelationType();
        $relationId   = $this->getKey();

        if (empty($relationId)) {
            return new \think\Collection([]);
        }

        $relationTable = config('wise-attachment.table_names.relation', 'attachment_relation');
        return AttachmentModel::alias('a')
            ->join($relationTable . ' r', 'a.id = r.attachment_id')
            ->where('r.relation_type', $relationType)
            ->where('r.relation_id', $relationId)
            ->field('a.*')
            ->select();
    }

    /**
     * 添加附件关联（幂等）
     *
     * @param AttachmentModel|int $attachment 附件模型或附件 ID
     * @param array               $extra      额外数据（预留字段）
     * @return $this
     */
    public function attach($attachment, array $extra = []): self
    {
        $attachmentId = $attachment instanceof AttachmentModel
            ? $attachment->getKey()
            : (int) $attachment;

        if ($attachmentId > 0) {
            AttachmentRelation::link(
                $attachmentId,
                $this->getRelationType(),
                (int) $this->getKey()
            );
        }

        return $this;
    }

    /**
     * 移除附件关联
     *
     * @param AttachmentModel|int $attachment 附件模型或附件 ID
     * @return $this
     */
    public function detach($attachment): self
    {
        $attachmentId = $attachment instanceof AttachmentModel
            ? $attachment->getKey()
            : (int) $attachment;

        if ($attachmentId > 0) {
            AttachmentRelation::unlink(
                $attachmentId,
                $this->getRelationType(),
                (int) $this->getKey()
            );
        }

        return $this;
    }

    /**
     * 同步附件关联（在事务中先清空再添加）
     *
     * @param array $ids 附件 ID 数组
     * @return $this
     */
    public function syncAttachment(array $ids): self
    {
        $relationType = $this->getRelationType();
        $relationId   = (int) $this->getKey();

        \think\facade\Db::transaction(function () use ($ids, $relationType, $relationId) {
            // 清空现有关联
            AttachmentRelation::clearFor($relationType, $relationId);

            // 重新建立关联
            foreach ($ids as $id) {
                $attachmentId = (int) $id;
                if ($attachmentId > 0) {
                    AttachmentRelation::link($attachmentId, $relationType, $relationId);
                }
            }
        });

        return $this;
    }

    /**
     * 批量移除指定附件 ID 的关联
     *
     * @param int|array $ids 单个 ID 或 ID 数组
     * @return $this
     */
    public function detachAttachments($ids): self
    {
        $relationType = $this->getRelationType();
        $relationId   = (int) $this->getKey();

        $ids = is_array($ids) ? $ids : [$ids];
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, function ($id) { return $id > 0; });

        if (!empty($ids)) {
            AttachmentRelation::where('relation_type', $relationType)
                ->where('relation_id', $relationId)
                ->whereIn('attachment_id', $ids)
                ->delete();
        }

        return $this;
    }

    /**
     * 获取附件的处理过的 URL（支持图片处理参数）
     *
     * @param array $options 图片处理参数，如 ['size' => 'thumb']
     * @return array 处理后的 URL 数组
     */
    public function getAttachmentUrls(array $options = []): array
    {
        $attachments = $this->getAttachments();
        $urls = [];

        foreach ($attachments as $attachment) {
            $urls[] = $attachment->getProcessedUrl($options);
        }

        return $urls;
    }

    /**
     * 获取业务模型的多态类型标识
     *
     * 优先级：1) 模型属性 $relationType  2) 模型完整类名（static::class）
     *
     * @return string
     */
    protected function getRelationType(): string
    {
        if (isset($this->relationType) && is_string($this->relationType)) {
            return $this->relationType;
        }

        return static::class;
    }

    /**
     * 模型删除时清理关联
     *
     * 推荐在模型的 init 方法中注册事件自动调用:
     * ```php
     * protected static function init()
     * {
     *     static::afterDelete(function ($model) {
     *         $model->clearAttachments();
     *     });
     * }
     * ```
     *
     * @return bool
     */
    public function clearAttachments(): bool
    {
        return AttachmentRelation::clearFor(
            $this->getRelationType(),
            (int) $this->getKey()
        );
    }
}
