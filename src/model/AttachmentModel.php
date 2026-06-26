<?php
declare(strict_types=1);

/**
 * 附件模型
 *
 * 对应数据表 attachment，支持软删除。
 * 使用 ThinkPHP 6 的 SoftDelete trait。
 *
 * @property int    $id              主键
 * @property string $storage         存储驱动标识 (local, ftp, qiniu, alioss, cos)
 * @property string $object_name     存储对象名（相对路径）
 * @property string $url             文件访问 URL
 * @property int    $size            文件大小（字节）
 * @property string $mime_type       MIME 类型
 * @property int    $image_width     图片宽度（非图片为 0）
 * @property int    $image_height    图片高度（非图片为 0）
 * @property int    $is_image        是否为图片 (0/1)
 * @property datetime $delete_time      软删除时间戳
 * @property datetime $create_time      创建时间
 * @property datetime $update_time      更新时间
 */
namespace wise\attachment\model;

use think\Model;
use think\model\concern\SoftDelete;

class AttachmentModel extends Model
{
    use SoftDelete;

    protected $name = 'attachment';

    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $deleteTime = 'delete_time';
    protected $autoWriteTimestamp = true;

    /**
     * 允许批量赋值的字段
     *
     * @var array
     */
    protected $fillable = [
        'storage',
        'object_name',
        'url',
        'size',
        'mime_type',
        'image_width',
        'image_height',
        'is_image',
        'content_hash',
        'is_duplicate',
        'original_attachment_id',
        'category_id',
    ];

    /**
     * 字段类型转换
     *
     * @var array
     */
    protected $type = [
        'size'                  => 'integer',
        'image_width'           => 'integer',
        'image_height'          => 'integer',
        'is_image'              => 'integer',
        'is_duplicate'          => 'integer',
        'original_attachment_id'=> 'integer',
        'category_id'           => 'integer',
    ];

    public function __construct($data = [])
    {
        parent::__construct($data);
        $this->setName(config('wise-attachment.table_names.attachment', $this->name));
    }

    /**
     * 关联：所属分类
     */
    public function category()
    {
        return $this->belongsTo(AttachmentCategory::class, 'category_id', 'id');
    }

    /**
     * 关联：多态关联记录
     *
     * @return \think\model\relation\HasMany
     */
    public function relations()
    {
        return $this->hasMany(AttachmentRelation::class, 'attachment_id', 'id');
    }

    /**
     * 判断附件是否为图片
     *
     * @return bool
     */
    public function isImage(): bool
    {
        return $this->getAttr('is_image') == 1;
    }

    /**
     * 判断附件是否为 PDF
     *
     * @return bool
     */
    public function isPdf(): bool
    {
        return strtolower($this->getAttr('mime_type')) === 'application/pdf';
    }

    /**
     * 判断附件是否为视频
     *
     * @return bool
     */
    public function isVideo(): bool
    {
        return strpos($this->getAttr('mime_type'), 'video/') === 0;
    }

    /**
     * 判断附件是否为音频
     *
     * @return bool
     */
    public function isAudio(): bool
    {
        return strpos($this->getAttr('mime_type'), 'audio/') === 0;
    }

    /**
     * 带存储驱动的 URL 获取（支持图片处理参数）
     *
     * @param array $options 图片处理参数，如 ['size' => 'thumb']
     * @return string
     */
    public function getProcessedUrl(array $options = []): string
    {
        if (empty($options)) {
            return $this->getAttr('url');
        }

        try {
            /** @var \wise\attachment\factory\AttachmentStore $store */
            $store  = app()->make(\wise\attachment\factory\AttachmentStore::class);
            $driver = $store->driver($this->getAttr('storage'));
            return $driver->url($this->getAttr('object_name'), $options);
        } catch (\Throwable $e) {
            return $this->getAttr('url');
        }
    }

    /**
     * 获取文件扩展名
     *
     * @return string
     */
    public function getExtension(): string
    {
        return strtolower(pathinfo($this->getAttr('object_name'), PATHINFO_EXTENSION));
    }

    /**
     * 获取人类可读的文件大小
     *
     * @param int $precision 小数精度
     * @return string 如 "2.5 MB"
     */
    public function getHumanSize(int $precision = 2): string
    {
        $bytes = (int) $this->getAttr('size');
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow   = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow   = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * 搜索查询构建器
     *
     * @param array $params 搜索参数:
     *   - keyword:    string  关键词（模糊搜索 object_name）
     *   - storage:    string  存储类型
     *   - mime_type:  string  MIME 类型前缀（如 'image/'）
     *   - is_image:   int     是否图片 (0/1)
     *   - start_time: string  起始时间
     *   - end_time:   string  结束时间
     * @return \think\db\Query
     */
    public function search(array $params = []): \think\db\Query
    {
        $query = $this->newQuery();

        if (!empty($params['keyword'])) {
            $keyword = trim($params['keyword']);
            $query->where('object_name', 'like', "%{$keyword}%");
        }

        if (!empty($params['storage'])) {
            $query->where('storage', '=', $params['storage']);
        }

        if (!empty($params['mime_type'])) {
            $query->where('mime_type', 'like', $params['mime_type'] . '%');
        }

        if (isset($params['is_image']) && $params['is_image'] !== '' && $params['is_image'] !== null) {
            $query->where('is_image', '=', (int) $params['is_image']);
        }

        if (!empty($params['category_id'])) {
            $query->where('category_id', '=', (int) $params['category_id']);
        }

        if (!empty($params['start_time'])) {
            $query->where('create_time', '>=', strtotime($params['start_time']));
        }

        if (!empty($params['end_time'])) {
            $query->where('create_time', '<=', strtotime($params['end_time']));
        }

        $query->order('id', 'desc');

        return $query;
    }

    /**
     * 关联：所有版本记录
     */
    public function versions()
    {
        return $this->hasMany(AttachmentVersion::class, 'attachment_id', 'id');
    }

    /**
     * 获取当前版本号
     */
    public function getCurrentVersion(): int
    {
        return AttachmentVersion::where('attachment_id', $this->getKey())
            ->max('version_number') ?: 1;
    }

    /**
     * 创建新版本（更新文件时）
     *
     * @param array  $uploadResult 上传结果（来自 Driver::upload()）
     * @param int    $operatorId   操作人ID
     * @param string $note         版本说明
     * @return AttachmentVersion
     */
    public function createVersion(array $uploadResult, int $operatorId = 0, string $note = ''): AttachmentVersion
    {
        $versionNumber = AttachmentVersion::getNextVersionNumber($this->getKey());

        $version = AttachmentVersion::create([
            'attachment_id'  => $this->getKey(),
            'version_number' => $versionNumber,
            'object_name'    => $uploadResult['path'] ?? $uploadResult['object_name'] ?? '',
            'url'            => $uploadResult['url'] ?? '',
            'size'           => $uploadResult['size'] ?? 0,
            'mime_type'      => $uploadResult['mime_type'] ?? '',
            'content_hash'   => $uploadResult['content_hash'] ?? '',
            'operator_id'    => $operatorId,
            'note'           => $note,
        ]);

        // 更新附件主记录
        $this->save([
            'object_name' => $version->object_name,
            'url'         => $version->url,
            'size'        => $version->size,
            'mime_type'   => $version->mime_type,
        ]);

        return $version;
    }

    /**
     * 回滚到指定版本
     *
     * @param int $versionNumber 版本号
     * @return bool
     */
    public function rollbackToVersion(int $versionNumber): bool
    {
        $version = AttachmentVersion::where('attachment_id', $this->getKey())
            ->where('version_number', $versionNumber)
            ->find();

        if (!$version) {
            return false;
        }

        return (bool) $this->save([
            'object_name' => $version->object_name,
            'url'         => $version->url,
            'size'        => $version->size,
            'mime_type'   => $version->mime_type,
        ]);
    }

    /**
     * 获取 MIME 类型对应的图标类名（Bootstrap Icons）
     *
     * @return string
     */
    public function getIconClass(): string
    {
        if ($this->isImage()) {
            return 'bi-file-image';
        }
        if ($this->isPdf()) {
            return 'bi-file-pdf';
        }
        if ($this->isVideo()) {
            return 'bi-file-play';
        }
        if ($this->isAudio()) {
            return 'bi-file-music';
        }

        $ext = $this->getExtension();
        $iconMap = [
            'zip'  => 'bi-file-zip',
            'rar'  => 'bi-file-zip',
            '7z'   => 'bi-file-zip',
            'gz'   => 'bi-file-zip',
            'doc'  => 'bi-file-word',
            'docx' => 'bi-file-word',
            'xls'  => 'bi-file-excel',
            'xlsx' => 'bi-file-excel',
            'ppt'  => 'bi-file-ppt',
            'pptx' => 'bi-file-ppt',
            'txt'  => 'bi-file-text',
            'csv'  => 'bi-file-text',
            'md'   => 'bi-file-text',
            'html' => 'bi-file-code',
            'php'  => 'bi-file-code',
            'js'   => 'bi-file-code',
            'css'  => 'bi-file-code',
            'json' => 'bi-file-code',
            'xml'  => 'bi-file-code',
            'sql'  => 'bi-file-code',
        ];

        return $iconMap[$ext] ?? 'bi-file';
    }

    /**
     * 根据内容哈希查找已存在的附件（去重查询）
     *
     * @param string $hash SHA256 哈希
     * @return AttachmentModel|null
     */
    public static function findByHash(string $hash): ?self
    {
        if (empty($hash)) {
            return null;
        }
        return static::where('content_hash', $hash)
            ->where('is_duplicate', 0) // 只找原始文件，不去重记录本身
            ->find();
    }

    /**
     * 判断是否为去重复用记录
     *
     * @return bool
     */
    public function isDuplicate(): bool
    {
        return $this->getAttr('is_duplicate') == 1;
    }

    /**
     * 获取原始附件（如果本记录是去重复用的话）
     *
     * @return AttachmentModel|null
     */
    public function getOriginal(): ?self
    {
        $originalId = (int) $this->getAttr('original_attachment_id');
        if ($originalId > 0) {
            return static::find($originalId);
        }
        return null;
    }
}
