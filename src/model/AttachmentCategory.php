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
        // 一次性加载所有分类，避免 N+1 查询
        $allCategories = static::order('sort_order', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        // 构建 parent_id => children 索引
        $grouped = [];
        foreach ($allCategories as $cat) {
            $groupId = (int) $cat['parent_id'];
            $grouped[$groupId][] = $cat;
        }

        // 递归构建树（纯内存操作，无 DB 查询）
        $buildTree = function (int $pid) use (&$buildTree, $grouped) {
            $nodes = [];
            if (isset($grouped[$pid])) {
                foreach ($grouped[$pid] as $cat) {
                    $node = $cat;
                    $children = $buildTree((int) $cat['id']);
                    if (!empty($children)) {
                        $node['children'] = $children;
                    }
                    // 统计附件数暂不计算（避免子查询），可按需在业务层请求
                    $node['attachment_count'] = 0;
                    $nodes[] = $node;
                }
            }
            return $nodes;
        };

        return $buildTree($parentId);
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
