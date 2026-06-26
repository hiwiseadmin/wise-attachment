<?php
declare(strict_types=1);

/**
 * FTP 存储驱动
 *
 * 通过 FTP 协议上传和管理文件。
 * 支持被动模式、SSL 加密连接和匿名登录。
 */
namespace wise\attachment\driver;

use think\facade\Config;

class Ftp implements DriverInterface
{
    /**
     * 驱动配置
     *
     * @var array
     */
    protected $config;

    /**
     * FTP 连接资源
     *
     * PHP 7.x 返回 resource，PHP 8.1+ 返回 \FTP\Connection 对象
     *
     * @var \FTP\Connection|resource|null
     */
    protected $connection = null;

    /**
     * 构造函数
     *
     * @param array $config 驱动配置
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge(
            Config::get('wise-attachment.drivers.ftp', []),
            $config
        );
    }

    /**
     * 获取 FTP 连接（延迟连接 + 缓存复用）
     *
     * PHP 7.x 返回 resource，PHP 8.1+ 返回 \FTP\Connection 对象
     *
     * @return \FTP\Connection|resource
     * @throws \RuntimeException 连接或登录失败时
     */
    protected function getConnection()
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $host = $this->config['host'];
        $port = (int) ($this->config['port'] ?? 21);

        $ssl  = !empty($this->config['ssl']);
        $conn = $ssl ? @ftp_ssl_connect($host, $port, (int) ($this->config['timeout'] ?? 30))
                     : @ftp_connect($host, $port, (int) ($this->config['timeout'] ?? 30));

        if (!$conn) {
            throw new \RuntimeException("FTP 连接失败: {$host}:{$port}");
        }

        if (!@ftp_login($conn, $this->config['username'] ?? '', $this->config['password'] ?? '')) {
            ftp_close($conn);
            throw new \RuntimeException("FTP 登录失败，用户: " . ($this->config['username'] ?? 'anonymous'));
        }

        // 被动模式
        if (!empty($this->config['passive'])) {
            ftp_pasv($conn, true);
        }

        $this->connection = $conn;
        return $conn;
    }

    /**
     * 关闭 FTP 连接
     */
    public function __destruct()
    {
        if ($this->connection !== null) {
            @ftp_close($this->connection);
            $this->connection = null;
        }
    }

    /**
     * 获取远程完整路径
     *
     * @param string $objectName 对象名（相对路径）
     * @return string
     */
    protected function getRemotePath(string $objectName): string
    {
        $root = rtrim($this->config['root'] ?? '/', '/');
        return $root . '/' . ltrim($objectName, '/');
    }

    /**
     * 确保远程目录存在（递归创建）
     *
     * @param string $remotePath 远程完整路径
     * @return void
     * @throws \RuntimeException
     */
    protected function ensureRemoteDir(string $remotePath): void
    {
        $conn  = $this->getConnection();
        $dir   = dirname($remotePath);
        $parts = explode('/', trim($dir, '/'));
        // 解决 Windows 下盘符问题
        $parts = array_filter($parts, function ($p) { return $p !== ''; });

        $currentDir = '';
        foreach ($parts as $part) {
            $currentDir .= '/' . $part;

            // 尝试切换到该目录
            if (@ftp_chdir($conn, $currentDir)) {
                continue;
            }

            // 目录不存在，创建它
            if (!@ftp_mkdir($conn, $currentDir)) {
                throw new \RuntimeException("FTP 创建目录失败: {$currentDir}");
            }
        }
    }

    /**
     * 上传文件到 FTP 服务器
     *
     * @param string $localPath  本地文件路径
     * @param string $objectName 存储对象名
     * @return array
     * @throws \RuntimeException
     */
    public function upload(string $localPath, string $objectName): array
    {
        $conn       = $this->getConnection();
        $remotePath = $this->getRemotePath($objectName);

        $this->ensureRemoteDir($remotePath);

        if (!@ftp_put($conn, $remotePath, $localPath, FTP_BINARY)) {
            throw new \RuntimeException("FTP 上传失败: {$objectName}");
        }

        // 获取远程文件大小（ftp_size 失败返回 -1）
        $remoteSize = @ftp_size($conn, $remotePath);
        $size       = ($remoteSize !== -1 && $remoteSize !== false) ? $remoteSize : filesize($localPath);

        // 获取 MIME 类型
        $mimeType = mime_content_type($localPath) ?: 'application/octet-stream';

        // 图片尺寸
        $imageSize = @getimagesize($localPath);
        $width     = $imageSize ? $imageSize[0] : 0;
        $height    = $imageSize ? $imageSize[1] : 0;

        return [
            'url'       => $this->url($objectName),
            'path'      => $objectName,
            'size'      => (int) $size,
            'mime_type' => $mimeType,
            'storage'   => 'ftp',
            'width'     => $width,
            'height'    => $height,
        ];
    }

    /**
     * 删除 FTP 上的文件
     *
     * @param string $objectName 存储对象名
     * @return bool
     */
    public function delete(string $objectName): bool
    {
        $conn       = $this->getConnection();
        $remotePath = $this->getRemotePath($objectName);

        // 检查文件是否存在（ftp_size 失败时返回 -1 或 false）
        $size = @ftp_size($conn, $remotePath);
        if ($size === -1 || $size === false) {
            return true; // 文件不存在，视为删除成功
        }

        return @ftp_delete($conn, $remotePath);
    }

    /**
     * 获取文件访问 URL
     *
     * @param string $objectName 存储对象名
     * @param array  $options    可选参数（FTP 驱动不支持图片处理）
     * @return string
     */
    public function url(string $objectName, array $options = []): string
    {
        $baseUrl = rtrim($this->config['base_url'] ?? '', '/');
        return $baseUrl . '/' . ltrim($objectName, '/');
        // 注意：FTP 驱动不支持动态图片处理
    }

    /**
     * 检查文件是否存在
     *
     * @param string $objectName 存储对象名
     * @return bool
     */
    public function exists(string $objectName): bool
    {
        try {
            $conn = $this->getConnection();
            $size = @ftp_size($conn, $this->getRemotePath($objectName));
            return $size !== -1 && $size !== false;
        } catch (\Throwable $e) {
            unset($e);
            return false;
        }
    }

    /**
     * 获取文件大小
     *
     * @param string $objectName 存储对象名
     * @return int
     */
    public function size(string $objectName): int
    {
        try {
            $conn = $this->getConnection();
            $size = @ftp_size($conn, $this->getRemotePath($objectName));
            return ($size !== -1 && $size !== false) ? (int) $size : 0;
        } catch (\Throwable $e) {
            unset($e);
            return 0;
        }
    }

    /**
     * 获取文件 MIME 类型（基于扩展名推断）
     *
     * @param string $objectName 存储对象名
     * @return string
     */
    public function mimeType(string $objectName): string
    {
        $ext   = strtolower(pathinfo($objectName, PATHINFO_EXTENSION));
        $mimes = [
            'jpg'   => 'image/jpeg',
            'jpeg'  => 'image/jpeg',
            'png'   => 'image/png',
            'gif'   => 'image/gif',
            'webp'  => 'image/webp',
            'bmp'   => 'image/bmp',
            'svg'   => 'image/svg+xml',
            'pdf'   => 'application/pdf',
            'doc'   => 'application/msword',
            'docx'  => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'   => 'application/vnd.ms-excel',
            'xlsx'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt'   => 'application/vnd.ms-powerpoint',
            'pptx'  => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'zip'   => 'application/zip',
            'rar'   => 'application/x-rar-compressed',
            '7z'    => 'application/x-7z-compressed',
            'gz'    => 'application/gzip',
            'tar'   => 'application/x-tar',
            'mp4'   => 'video/mp4',
            'mp3'   => 'audio/mpeg',
            'avi'   => 'video/x-msvideo',
            'mov'   => 'video/quicktime',
            'mkv'   => 'video/x-matroska',
            'wav'   => 'audio/wav',
            'flac'  => 'audio/flac',
            'txt'   => 'text/plain',
            'csv'   => 'text/csv',
            'html'  => 'text/html',
            'css'   => 'text/css',
            'js'    => 'application/javascript',
            'json'  => 'application/json',
            'xml'   => 'application/xml',
        ];
        return $mimes[$ext] ?? 'application/octet-stream';
    }

    /**
     * 获取驱动标识名称
     *
     * @return string
     */
    public function getDriverName(): string
    {
        return 'ftp';
    }

    /**
     * 复制文件（FTP 驱动不支持原生 copy，返回 false 由调用方降级处理）
     *
     * @param string $source      源文件对象名
     * @param string $destination 目标文件对象名
     * @return bool
     */
    public function copy(string $source, string $destination): bool
    {
        return false;
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
        try {
            $conn       = $this->getConnection();
            $srcPath    = $this->getRemotePath($source);
            $destPath   = $this->getRemotePath($destination);

            $this->ensureRemoteDir($destPath);

            return @ftp_rename($conn, $srcPath, $destPath);
        } catch (\Throwable $e) {
            unset($e);
            return false;
        }
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
        try {
            $conn       = $this->getConnection();
            $remotePath = $this->getRemotePath($directory);

            $rawList = @ftp_mlsd($conn, $remotePath);
            if ($rawList === false) {
                return [];
            }

            $files  = [];
            $count  = 0;
            $max    = 1000;

            foreach ($rawList as $entry) {
                if ($count >= $max) {
                    break;
                }

                // 1. 解析 MLSD 条目中的元数据键值对（如 type=file, size=1024）
                $meta = [];
                foreach (explode(';', $entry) as $segment) {
                    $segment = trim($segment);
                    if ($segment === '') {
                        continue;
                    }
                    $kv = explode('=', $segment, 2);
                    if (count($kv) === 2) {
                        $meta[trim($kv[0])] = trim($kv[1]);
                    }
                }

                // 2. 从条目末尾提取文件名（MLSD 格式：fact1;fact2;... filename）
                $entryParts = explode(';', $entry);
                $name       = trim(preg_replace('/^.*\s/', '', end($entryParts)));

                if ($name === '.' || $name === '..') {
                    continue;
                }

                $type = $meta['type'] ?? 'file';

                if ($type === 'dir' || $type === 'cdir' || $type === 'pdir') {
                    if ($recursive) {
                        $subDir  = ($directory === '') ? $name : $directory . '/' . $name;
                        $subFiles = $this->listFiles($subDir, true);
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
                        'key'           => ($directory === '') ? $name : $directory . '/' . $name,
                        'size'          => (int) ($meta['size'] ?? 0),
                        'last_modified' => (int) ($meta['modify'] ?? 0),
                    ];
                    $count++;
                }
            }

            return $files;
        } catch (\Throwable $e) {
            unset($e);
            return [];
        }
    }

    /**
     * 创建目录
     *
     * @param string $directory 目录路径
     * @return bool
     */
    public function makeDirectory(string $directory): bool
    {
        try {
            $conn       = $this->getConnection();
            $remotePath = $this->getRemotePath($directory);

            $chdir = @ftp_chdir($conn, $remotePath);
            if ($chdir) {
                return true;
            }

            $parts    = explode('/', trim($remotePath, '/'));
            $parts    = array_filter($parts, function ($p) { return $p !== ''; });
            $current  = '';

            foreach ($parts as $part) {
                $current .= '/' . $part;
                if (!@ftp_chdir($conn, $current)) {
                    if (!@ftp_mkdir($conn, $current)) {
                        return false;
                    }
                }
            }

            return true;
        } catch (\Throwable $e) {
            unset($e);
            return false;
        }
    }

    /**
     * 删除目录（含子文件）
     *
     * @param string $directory 目录路径
     * @return bool
     */
    public function deleteDirectory(string $directory): bool
    {
        try {
            $conn       = $this->getConnection();
            $remotePath = $this->getRemotePath($directory);

            $items = @ftp_nlist($conn, $remotePath);
            if ($items !== false) {
                foreach ($items as $item) {
                    $itemName = basename($item);
                    if ($itemName === '.' || $itemName === '..') {
                        continue;
                    }

                    $itemPath = $remotePath . '/' . $itemName;

                    if (@ftp_size($conn, $itemPath) === -1) {
                        $subRel = ($directory === '') ? $itemName : $directory . '/' . $itemName;
                        $this->deleteDirectory($subRel);
                    } else {
                        @ftp_delete($conn, $itemPath);
                    }
                }
            }

            return @ftp_rmdir($conn, $remotePath);
        } catch (\Throwable $e) {
            unset($e);
            return false;
        }
    }

    /**
     * 获取临时签名 URL（FTP 不支持签名）
     *
     * @param string $objectName 存储对象名
     * @param int    $expires    过期秒数
     * @param array  $options    额外选项
     * @return string
     */
    public function temporaryUrl(string $objectName, int $expires = 3600, array $options = []): string
    {
        return $this->url($objectName, $options);
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
        try {
            $conn       = $this->getConnection();
            $remotePath = $this->getRemotePath($objectName);
            $time       = @ftp_mdtm($conn, $remotePath);

            return ($time !== -1 && $time !== false) ? (int) $time : 0;
        } catch (\Throwable $e) {
            unset($e);
            return 0;
        }
    }

    /**
     * 获取文件元数据
     *
     * @param string $objectName 存储对象名
     * @return array
     */
    public function getMetadata(string $objectName): array
    {
        try {
            $conn       = $this->getConnection();
            $remotePath = $this->getRemotePath($objectName);

            $size = @ftp_size($conn, $remotePath);
            if ($size === -1 || $size === false) {
                return [];
            }

            return [
                'size'          => (int) $size,
                'mime'          => $this->mimeType($objectName),
                'last_modified' => $this->lastModified($objectName),
                'visibility'    => $this->getVisibility($objectName),
            ];
        } catch (\Throwable $e) {
            unset($e);
            return [];
        }
    }

    /**
     * 设置文件可见性（FTP 不支持）
     *
     * @param string $objectName 存储对象名
     * @param string $visibility 'public' 或 'private'
     * @return bool
     */
    public function setVisibility(string $objectName, string $visibility): bool
    {
        return false;
    }

    /**
     * 获取文件可见性（FTP 默认 public）
     *
     * @param string $objectName 存储对象名
     * @return string 'public' 或 'private'
     */
    public function getVisibility(string $objectName): string
    {
        return 'public';
    }
}
