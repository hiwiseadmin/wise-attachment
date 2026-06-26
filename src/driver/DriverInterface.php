<?php
declare(strict_types=1);

/**
 * 附件存储驱动接口
 *
 * 所有存储驱动（本地、FTP、七牛云、阿里云 OSS、腾讯云 COS）必须实现此接口。
 */
namespace wise\attachment\driver;

interface DriverInterface
{
    /**
     * 上传文件到存储服务
     *
     * @param string $localPath  本地文件完整路径
     * @param string $objectName 存储对象名（相对路径）
     * @return array 标准化返回数据:
     *   - url:        string 访问 URL
     *   - path:       string 存储路径（即 objectName）
     *   - size:       int    文件大小（字节）
     *   - mime_type:  string MIME 类型
     *   - storage:    string 存储驱动标识
     *   - width:      int    图片宽度（非图片为 0）
     *   - height:     int    图片高度（非图片为 0）
     */
    public function upload(string $localPath, string $objectName): array;

    /**
     * 删除存储中的文件
     *
     * @param string $objectName 存储对象名（相对路径）
     * @return bool 文件不存在时也应返回 true
     */
    public function delete(string $objectName): bool;

    /**
     * 获取文件访问 URL（支持图片处理参数）
     *
     * @param string $objectName 存储对象名（相对路径）
     * @param array  $options    可选参数:
     *   - width:   int    图片宽度
     *   - height:  int    图片高度
     *   - size:    string 预设尺寸 key（使用白名单配置）
     *   - quality: int    图片质量（0-100）
     * @return string
     */
    public function url(string $objectName, array $options = []): string;

    /**
     * 检查文件是否存在
     *
     * @param string $objectName 存储对象名（相对路径）
     * @return bool
     */
    public function exists(string $objectName): bool;

    /**
     * 获取文件大小
     *
     * @param string $objectName 存储对象名（相对路径）
     * @return int 文件大小（字节），不存在返回 0
     */
    public function size(string $objectName): int;

    /**
     * 获取文件 MIME 类型
     *
     * @param string $objectName 存储对象名（相对路径）
     * @return string
     */
    public function mimeType(string $objectName): string;

    /**
     * 获取驱动标识名称
     *
     * @return string 如 'local', 'qiniu', 'alioss', 'cos'
     */
    public function getDriverName(): string;

    /**
     * 复制文件（同驱动内）
     *
     * @param string $source      源文件对象名
     * @param string $destination 目标文件对象名
     * @return bool
     */
    public function copy(string $source, string $destination): bool;

    /**
     * 移动/重命名文件（同驱动内）
     *
     * @param string $source      源文件对象名
     * @param string $destination 目标文件对象名
     * @return bool
     */
    public function move(string $source, string $destination): bool;

    /**
     * 列出目录下的文件（最多返回 1000 条）
     *
     * @param string $directory 目录路径，空字符串表示根目录
     * @param bool   $recursive 是否递归列出子目录
     * @return array 文件信息数组，每个元素包含 [key, size, last_modified, ...]
     */
    public function listFiles(string $directory = '', bool $recursive = false): array;

    /**
     * 创建目录
     *
     * @param string $directory 目录路径
     * @return bool
     */
    public function makeDirectory(string $directory): bool;

    /**
     * 删除目录（含子文件，需谨慎）
     *
     * @param string $directory 目录路径
     * @return bool
     */
    public function deleteDirectory(string $directory): bool;

    /**
     * 获取临时签名 URL（私有文件访问）
     *
     * @param string $objectName 存储对象名
     * @param int    $expires    过期秒数，默认 3600
     * @param array  $options    额外选项
     * @return string
     */
    public function temporaryUrl(string $objectName, int $expires = 3600, array $options = []): string;

    /**
     * 批量删除文件
     *
     * @param array $objectNames 对象名数组
     * @return array ['success' => int, 'failed' => int]
     */
    public function deleteMultiple(array $objectNames): array;

    /**
     * 获取文件最后修改时间（Unix 时间戳）
     *
     * @param string $objectName 存储对象名
     * @return int Unix 时间戳，不存在返回 0
     */
    public function lastModified(string $objectName): int;

    /**
     * 获取文件元数据（key-value 数组）
     *
     * @param string $objectName 存储对象名
     * @return array 如 ['size' => 1024, 'mime' => 'image/png', 'last_modified' => 1234567890, 'visibility' => 'public']
     */
    public function getMetadata(string $objectName): array;

    /**
     * 设置文件可见性
     *
     * @param string $objectName 存储对象名
     * @param string $visibility 'public' 或 'private'
     * @return bool
     */
    public function setVisibility(string $objectName, string $visibility): bool;

    /**
     * 获取文件可见性
     *
     * @param string $objectName 存储对象名
     * @return string 'public' 或 'private'
     */
    public function getVisibility(string $objectName): string;
}
