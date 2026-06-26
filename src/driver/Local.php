<?php
declare(strict_types=1);

/**
 * 本地存储驱动
 *
 * 将文件存储在服务器本地磁盘。
 * 支持基本的图片处理 URL 生成（通过 ImageProcessor）。
 */
namespace wise\attachment\driver;

use think\facade\Config;
use wise\attachment\tools\ImageProcessor;

class Local implements DriverInterface
{
    /**
     * 驱动配置
     *
     * @var array
     */
    protected $config;

    /**
     * 图片处理器实例（延迟加载）
     *
     * @var ImageProcessor|null
     */
    protected $imageProcessor = null;

    /**
     * 构造函数
     *
     * @param array $config 驱动配置
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge(
            Config::get('wise-attachment.drivers.local', []),
            $config
        );
        $this->ensureRootPath();
    }

    /**
     * 确保上传根目录存在
     *
     * @return void
     */
    protected function ensureRootPath(): void
    {
        $rootPath = rtrim($this->config['root_path'], '/\\') . DIRECTORY_SEPARATOR;
        if (!is_dir($rootPath)) {
            mkdir($rootPath, 0755, true);
        }
    }

    /**
     * 获取文件的完整本地路径
     *
     * @param string $objectName 存储对象名
     * @return string
     */
    protected function getFullPath(string $objectName): string
    {
        return rtrim($this->config['root_path'], '/\\') . DIRECTORY_SEPARATOR . ltrim($objectName, '/\\');
    }

    /**
     * 上传文件到本地存储
     *
     * @param string $localPath  本地临时文件路径
     * @param string $objectName 存储对象名（相对路径）
     * @return array 标准化返回信息
     * @throws \RuntimeException
     */
    public function upload(string $localPath, string $objectName): array
    {
        $fullPath = $this->getFullPath($objectName);

        // 确保目标目录存在
        $targetDir = dirname($fullPath);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        // 移动文件到目标位置（优先用 rename，跨分区回退到 copy）
        if (@rename($localPath, $fullPath) === false) {
            if (!copy($localPath, $fullPath)) {
                throw new \RuntimeException("文件复制失败: {$objectName}");
            }
            @unlink($localPath);
        }

        // 获取文件信息
        $size     = (int) (filesize($fullPath) ?: 0);
        $mimeType = mime_content_type($fullPath) ?: 'application/octet-stream';

        // 判断是否为图片
        $imageSize = @getimagesize($fullPath);
        $width     = $imageSize ? $imageSize[0] : 0;
        $height    = $imageSize ? $imageSize[1] : 0;

        return [
            'url'       => $this->url($objectName),
            'path'      => $objectName,
            'size'      => $size,
            'mime_type' => $mimeType,
            'storage'   => 'local',
            'width'     => $width,
            'height'    => $height,
        ];
    }

    /**
     * 删除存储中的文件
     *
     * @param string $objectName 存储对象名（相对路径）
     * @return bool
     */
    public function delete(string $objectName): bool
    {
        $fullPath = $this->getFullPath($objectName);
        if (file_exists($fullPath)) {
            return unlink($fullPath);
        }
        return true; // 文件不存在视为删除成功
    }

    /**
     * 获取文件访问 URL（支持图片处理参数）
     *
     * @param string $objectName 存储对象名（相对路径）
     * @param array  $options    可选参数：width, height, size, quality
     * @return string
     */
    public function url(string $objectName, array $options = []): string
    {
        $baseUrl = rtrim($this->config['base_url'], '/');

        // 如果有图片处理参数，生成缩略图并返回缩略图 URL
        if (!empty($options)) {
            $thumbUrl = $this->generateThumbnail($objectName, $options);
            if ($thumbUrl !== null) {
                return $thumbUrl;
            }
        }

        return $baseUrl . '/' . ltrim($objectName, '/');
    }

    /**
     * 生成缩略图
     *
     * @param string $objectName 源文件对象名
     * @param array  $options    处理参数
     * @return string|null 缩略图 URL，失败返回 null
     */
    protected function generateThumbnail(string $objectName, array $options): ?string
    {
        $fullPath = $this->getFullPath($objectName);

        // 文件不存在
        if (!file_exists($fullPath)) {
            return null;
        }

        // 非图片不处理
        $mime = mime_content_type($fullPath);
        $imageMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
        if (!in_array($mime, $imageMimes, true)) {
            return null;
        }

        try {
            // 使用预设尺寸 key 或参数 hash 作为缓存标识
            $sizeKey    = $options['size'] ?? md5(serialize($options));
            $thumbName  = 'thumb/' . $sizeKey . '_' . basename($objectName);
            $thumbFull  = $this->getFullPath($thumbName);

            // 如果缩略图已存在，直接返回 URL（避免重复生成）
            if (file_exists($thumbFull)) {
                return rtrim($this->config['base_url'], '/') . '/' . ltrim($thumbName, '/');
            }

            // 生成缩略图
            $processor  = $this->getImageProcessor();
            $thumbPath  = $processor->process($fullPath, $options);

            // 确保缩略图目录存在
            $thumbDir = dirname($thumbFull);
            if (!is_dir($thumbDir)) {
                mkdir($thumbDir, 0755, true);
            }

            // 移动处理后的图片到目标位置
            rename($thumbPath, $thumbFull);

            return rtrim($this->config['base_url'], '/') . '/' . ltrim($thumbName, '/');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 获取图片处理器实例
     *
     * @return ImageProcessor
     */
    protected function getImageProcessor(): ImageProcessor
    {
        if ($this->imageProcessor === null) {
            $this->imageProcessor = app()->make(ImageProcessor::class);
        }
        return $this->imageProcessor;
    }

    /**
     * 检查文件是否存在
     *
     * @param string $objectName 存储对象名（相对路径）
     * @return bool
     */
    public function exists(string $objectName): bool
    {
        return file_exists($this->getFullPath($objectName));
    }

    /**
     * 获取文件大小
     *
     * @param string $objectName 存储对象名（相对路径）
     * @return int 文件大小（字节），不存在返回 0
     */
    public function size(string $objectName): int
    {
        $fullPath = $this->getFullPath($objectName);
        return file_exists($fullPath) ? (int) filesize($fullPath) : 0;
    }

    /**
     * 获取文件 MIME 类型
     *
     * @param string $objectName 存储对象名（相对路径）
     * @return string
     */
    public function mimeType(string $objectName): string
    {
        $fullPath = $this->getFullPath($objectName);
        return file_exists($fullPath) ? (mime_content_type($fullPath) ?: 'application/octet-stream') : '';
    }

    /**
     * 获取驱动标识名称
     *
     * @return string
     */
    public function getDriverName(): string
    {
        return 'local';
    }

    /**
     * 复制文件
     *
     * @param string $source      源文件对象名
     * @param string $destination 目标文件对象名
     * @return bool
     */
    public function copy(string $source, string $destination): bool
    {
        $srcPath  = $this->getFullPath($source);
        $destPath = $this->getFullPath($destination);

        if (!file_exists($srcPath)) {
            return false;
        }

        $destDir = dirname($destPath);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        return copy($srcPath, $destPath);
    }

    /**
     * 移动/重命名文件
     *
     * @param string $source      源文件对象名
     * @param string $destination 目标文件对象名
     * @return bool
     */
    public function move(string $source, string $destination): bool
    {
        $srcPath  = $this->getFullPath($source);
        $destPath = $this->getFullPath($destination);

        if (!file_exists($srcPath)) {
            return false;
        }

        $destDir = dirname($destPath);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        return rename($srcPath, $destPath);
    }

    /**
     * 列出目录下的文件
     *
     * @param string $directory 目录路径
     * @param bool   $recursive 是否递归
     * @return array
     */
    public function listFiles(string $directory = '', bool $recursive = false): array
    {
        $dirPath = $this->getFullPath($directory);

        if (!is_dir($dirPath)) {
            return [];
        }

        $files  = [];
        $items  = scandir($dirPath);
        $max    = 1000;
        $count  = 0;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            if ($count >= $max) {
                break;
            }

            $fullItemPath = $dirPath . DIRECTORY_SEPARATOR . $item;
            $relPath      = ($directory === '') ? $item : $directory . '/' . $item;

            if (is_dir($fullItemPath)) {
                if ($recursive) {
                    $subFiles = $this->listFiles($relPath, true);
                    foreach ($subFiles as $sf) {
                        if ($count >= $max) {
                            break;
                        }
                        $files[] = $sf;
                        $count++;
                    }
                }
            } else {
                $files[] = [
                    'key'           => $relPath,
                    'size'          => (int) filesize($fullItemPath),
                    'last_modified' => (int) filemtime($fullItemPath),
                ];
                $count++;
            }
        }

        return $files;
    }

    /**
     * 创建目录
     *
     * @param string $directory 目录路径
     * @return bool
     */
    public function makeDirectory(string $directory): bool
    {
        $dirPath = $this->getFullPath($directory);

        if (is_dir($dirPath)) {
            return true;
        }

        return mkdir($dirPath, 0755, true);
    }

    /**
     * 删除目录（含子文件）
     *
     * @param string $directory 目录路径
     * @return bool
     */
    public function deleteDirectory(string $directory): bool
    {
        $dirPath = $this->getFullPath($directory);

        if (!is_dir($dirPath)) {
            return true;
        }

        $items = scandir($dirPath);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $dirPath . DIRECTORY_SEPARATOR . $item;

            if (is_dir($fullPath)) {
                $relPath = ($directory === '') ? $item : $directory . '/' . $item;
                $this->deleteDirectory($relPath);
            } else {
                unlink($fullPath);
            }
        }

        return rmdir($dirPath);
    }

    /**
     * 获取临时签名 URL
     *
     * @param string $objectName 存储对象名
     * @param int    $expires    过期秒数
     * @param array  $options    额外选项
     * @return string
     */
    public function temporaryUrl(string $objectName, int $expires = 3600, array $options = []): string
    {
        $secret = $this->config['secret_key'] ?? 'wise_attachment_local_secret';
        $token  = hash_hmac('sha256', $objectName . '|' . $expires, $secret);

        return $this->url($objectName, $options) . '?token=' . $token . '&expires=' . $expires;
    }

    /**
     * 批量删除文件
     *
     * @param array $objectNames 对象名数组
     * @return array ['success' => int, 'failed' => int]
     */
    public function deleteMultiple(array $objectNames): array
    {
        $success = 0;
        $failed  = 0;

        foreach ($objectNames as $objectName) {
            if ($this->delete($objectName)) {
                $success++;
            } else {
                $failed++;
            }
        }

        return ['success' => $success, 'failed' => $failed];
    }

    /**
     * 获取文件最后修改时间
     *
     * @param string $objectName 存储对象名
     * @return int Unix 时间戳
     */
    public function lastModified(string $objectName): int
    {
        $fullPath = $this->getFullPath($objectName);

        return file_exists($fullPath) ? (int) filemtime($fullPath) : 0;
    }

    /**
     * 获取文件元数据
     *
     * @param string $objectName 存储对象名
     * @return array
     */
    public function getMetadata(string $objectName): array
    {
        $fullPath = $this->getFullPath($objectName);

        if (!file_exists($fullPath)) {
            return [];
        }

        $stat  = stat($fullPath);
        $mime  = mime_content_type($fullPath) ?: 'application/octet-stream';

        return [
            'size'          => (int) ($stat['size'] ?? 0),
            'mime'          => $mime,
            'last_modified' => (int) ($stat['mtime'] ?? 0),
            'visibility'    => $this->getVisibility($objectName),
        ];
    }

    /**
     * 设置文件可见性
     *
     * @param string $objectName 存储对象名
     * @param string $visibility 'public' 或 'private'
     * @return bool
     */
    public function setVisibility(string $objectName, string $visibility): bool
    {
        $fullPath = $this->getFullPath($objectName);
        $dirPath  = dirname($fullPath);
        $visFile  = $dirPath . DIRECTORY_SEPARATOR . '.visibility';

        $data = [];
        if (file_exists($visFile)) {
            $json = @file_get_contents($visFile);
            $data = $json ? json_decode($json, true) : [];
            if (!is_array($data)) {
                $data = [];
            }
        }

        $filename            = basename($objectName);
        $data[$filename]     = $visibility;

        return (bool) file_put_contents($visFile, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 获取文件可见性
     *
     * @param string $objectName 存储对象名
     * @return string 'public' 或 'private'
     */
    public function getVisibility(string $objectName): string
    {
        $fullPath = $this->getFullPath($objectName);
        $dirPath  = dirname($fullPath);
        $visFile  = $dirPath . DIRECTORY_SEPARATOR . '.visibility';

        if (!file_exists($visFile)) {
            return 'public';
        }

        $json = @file_get_contents($visFile);
        $data = $json ? json_decode($json, true) : [];
        if (!is_array($data)) {
            return 'public';
        }

        $filename = basename($objectName);

        return $data[$filename] ?? 'public';
    }
}
