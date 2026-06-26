<?php
declare(strict_types=1);

namespace wise\attachment\model;

use think\Model;

/**
 * 附件分类模型
 *
 * @property int    $id
 * @property string $name        分类名称
 * @property int    $parent_id   父分类ID
 * @property string $icon        图标CSS类名
 * @property string $description 描述
 * @property int    $sort_order  排序权重
 * @property int    $is_system   是否系统预置
 */
class AttachmentCategory extends Model
{
    protected $name = 'attachment_category';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    protected $type = [
        'parent_id' => 'integer',
        'sort_order'=> 'integer',
        'is_system' => 'integer',
    ];

    public function __construct($data = [])
    {
        parent::__construct($data);
        $this->setName(config('wise-attachment.table_names.category', $this->name));
    }

    /**
     * 关联：父分类
     */
    public function parent()
    {
        return $this->belongsTo(static::class, 'parent_id', 'id');
    }

    /**
     * 关联：子分类
     */
    public function children()
    {
        return $this->hasMany(static::class, 'parent_id', 'id')->order('sort_order', 'desc');
    }

    /**
     * 获取树形分类结构
     */
    public static function getTree(int $parentId = 0): array
    {
        $categories = static::where('parent_id', $parentId)
            ->order('sort_order', 'desc')
            ->order('id', 'asc')
            ->select();

        $tree = [];
        foreach ($categories as $category) {
            $node = $category->toArray();
            $children = static::getTree($category->id);
            if (!empty($children)) {
                $node['children'] = $children;
            }
            // 统计该分类及其子分类下的附件数量
            $categoryIds = static::getAllChildIds($category->id);
            $categoryIds[] = $category->id;
            $node['attachment_count'] = AttachmentModel::whereIn('category_id', $categoryIds)->count();
            $tree[] = $node;
        }

        return $tree;
    }

    /**
     * 获取所有子分类ID（递归）
     */
    public static function getAllChildIds(int $parentId): array
    {
        $ids = [];
        $children = static::where('parent_id', $parentId)->column('id');
        foreach ($children as $childId) {
            $ids[] = $childId;
            $ids = array_merge($ids, static::getAllChildIds($childId));
        }
        return $ids;
    }

    /**
     * 分类下的附件
     */
    public function attachments()
    {
        return $this->hasMany(AttachmentModel::class, 'category_id', 'id');
    }
}
