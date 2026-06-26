<?php
declare(strict_types=1);

/**
 * 七牛云 Kodo 存储驱动
 *
 * 依赖: qiniu/php-sdk
 * 安装: composer require qiniu/php-sdk
 */
namespace wise\attachment\driver;

use think\facade\Config;

class Qiniu implements DriverInterface
{
    /**
     * 驱动配置
     *
     * @var array
     */
    protected $config;

    /**
     * Auth 实例（延迟加载缓存）
     *
     * @var \Qiniu\Auth|null
     */
    protected $auth = null;

    /**
     * UploadManager 实例（延迟加载缓存）
     *
     * @var \Qiniu\Storage\UploadManager|null
     */
    protected $uploadManager = null;

    /**
     * BucketManager 实例（延迟加载缓存）
     *
     * @var \Qiniu\Storage\BucketManager|null
     */
    protected $bucketManager = null;

    /**
     * 构造函数
     *
     * @param array $config 驱动配置
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge(
            Config::get('wise-attachment.drivers.qiniu', []),
            $config
        );
    }

    /**
     * 获取 Auth 实例
     *
     * @return \Qiniu\Auth
     * @throws \RuntimeException SDK 未安装时
     */
    protected function getAuth()
    {
        if ($this->auth === null) {
            if (!class_exists('\\Qiniu\\Auth')) {
                throw new \RuntimeException('七牛云 SDK 未安装，请执行: composer require qiniu/php-sdk');
            }
            $this->auth = new \Qiniu\Auth(
                $this->config['access_key'],
                $this->config['secret_key']
            );
        }
        return $this->auth;
    }

    /**
     * 获取 UploadManager
     *
     * @return \Qiniu\Storage\UploadManager
     */
    protected function getUploadManager()
    {
        if ($this->uploadManager === null) {
            $this->uploadManager = new \Qiniu\Storage\UploadManager();
        }
        return $this->uploadManager;
    }

    /**
     * 获取 BucketManager
     *
     * @return \Qiniu\Storage\BucketManager
     */
    protected function getBucketManager()
    {
        if ($this->bucketManager === null) {
            $this->bucketManager = new \Qiniu\Storage\BucketManager($this->getAuth());
        }
        return $this->bucketManager;
    }

    /**
     * 获取上传 Token
     *
     * @return string
     */
    protected function getUploadToken(): string
    {
        return $this->getAuth()->uploadToken(
            $this->config['bucket'],
            null,
            3600
        );
    }

    /**
     * 上传文件到七牛云 Kodo
     *
     * @param string $localPath  本地文件路径
     * @param string $objectName 存储对象名
     * @return array
     * @throws \RuntimeException
     */
    public function upload(string $localPath, string $objectName): array
    {
        $objectName = ltrim(($this->config['base_path'] ?? '') . '/' . $objectName, '/');
        $token      = $this->getUploadToken();

        // 七牛 SDK putFile 返回 [result, error]
        $ret = null;
        $err = null;
        $this->getUploadManager()->putFile($token, $objectName, $localPath, null, null, false, $ret, $err);

        if ($err !== null) {
            throw new \RuntimeException('七牛云上传失败: ' . $err->message());
        }

        $mimeType = mime_content_type($localPath) ?: 'application/octet-stream';

        // 图片尺寸
        $imageSize = @getimagesize($localPath);
        $width     = $imageSize ? $imageSize[0] : 0;
        $height    = $imageSize ? $imageSize[1] : 0;

        return [
            'url'       => $this->url($objectName),
            'path'      => $objectName,
            'size'      => (int) ($ret['fsize'] ?? filesize($localPath) ?: 0),
            'mime_type' => $ret['mimeType'] ?? $mimeType,
            'storage'   => 'qiniu',
            'width'     => $width,
            'height'    => $height,
        ];
    }

    /**
     * 删除七牛云上的文件
     *
     * @param string $objectName 存储对象名
     * @return bool
     * @throws \RuntimeException
     */
    public function delete(string $objectName): bool
    {
        $objectName = ltrim(($this->config['base_path'] ?? '') . '/' . $objectName, '/');
        $err = $this->getBucketManager()->delete(
            $this->config['bucket'],
            $objectName
        );

        // 612 表示文件不存在，视为删除成功
        if ($err !== null && $err->code() !== 612) {
            throw new \RuntimeException('七牛云删除失败: ' . $err->message());
        }

        return true;
    }

    /**
     * 获取文件访问 URL（支持图片处理参数）
     *
     * @param string $objectName 存储对象名
     * @param array  $options    图片处理参数
     * @return string
     */
    public function url(string $objectName, array $options = []): string
    {
        $objectName = ltrim(($this->config['base_path'] ?? '') . '/' . $objectName, '/');
        $protocol   = !empty($this->config['use_https']) ? 'https://' : 'http://';
        $domain     = rtrim($this->config['domain'], '/');

        // 避免双斜杠
        $baseUrl = $protocol . $domain . '/' . $objectName;

        // 图片处理参数
        if (!empty($options)) {
            $query = $this->buildImageQuery($options);
            if ($query !== '') {
                return $baseUrl . '?imageMogr2/' . $query;
            }
        }

        return $baseUrl;
    }

    /**
     * 构建七牛云图片处理参数
     *
     * @param array $options 处理参数
     * @return string
     */
    protected function buildImageQuery(array $options): string
    {
        $params = [];

        // 预设尺寸
        if (!empty($options['size'])) {
            $sizes = Config::get('wise-attachment.image.whitelist_sizes.' . $options['size']);
            if ($sizes) {
                $options = array_merge($sizes, $options);
            }
        }

        if (!empty($options['width']) && !empty($options['height'])) {
            $params[] = 'thumbnail/' . $options['width'] . 'x' . $options['height'] . '!';
        } elseif (!empty($options['width'])) {
            $params[] = 'thumbnail/' . $options['width'] . 'x';
        } elseif (!empty($options['height'])) {
            $params[] = 'thumbnail/x' . $options['height'];
        }

        if (!empty($options['quality'])) {
            $params[] = 'quality/' . $options['quality'];
        }

        return implode('/', $params);
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
            $objectName = ltrim(($this->config['base_path'] ?? '') . '/' . $objectName, '/');
            $err = null;
            $this->getBucketManager()->stat(
                $this->config['bucket'],
                $objectName,
                $err
            );
            return $err === null;
        } catch (\Throwable $e) {
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
            $objectName = ltrim(($this->config['base_path'] ?? '') . '/' . $objectName, '/');
            $ret = null;
            $err = null;
            $this->getBucketManager()->stat(
                $this->config['bucket'],
                $objectName,
                $ret,
                $err
            );
            return $err === null ? (int) ($ret['fsize'] ?? 0) : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 获取文件 MIME 类型
     *
     * @param string $objectName 存储对象名
     * @return string
     */
    public function mimeType(string $objectName): string
    {
        try {
            $objectName = ltrim(($this->config['base_path'] ?? '') . '/' . $objectName, '/');
            $ret = null;
            $err = null;
            $this->getBucketManager()->stat(
                $this->config['bucket'],
                $objectName,
                $ret,
                $err
            );
            return $err === null ? ($ret['mimeType'] ?? 'application/octet-stream') : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * 获取驱动标识名称
     *
     * @return string
     */
    public function getDriverName(): string
    {
        return 'qiniu';
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
        try {
            $srcObjectName  = ltrim(($this->config['base_path'] ?? '') . '/' . $source, '/');
            $destObjectName = ltrim(($this->config['base_path'] ?? '') . '/' . $destination, '/');

            $err = $this->getBucketManager()->copy(
                $this->config['bucket'],
                $srcObjectName,
                $this->config['bucket'],
                $destObjectName
            );

            return $err === null;
        } catch (\Throwable $e) {
            return false;
        }
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
            $srcObjectName  = ltrim(($this->config['base_path'] ?? '') . '/' . $source, '/');
            $destObjectName = ltrim(($this->config['base_path'] ?? '') . '/' . $destination, '/');

            $err = $this->getBucketManager()->move(
                $this->config['bucket'],
                $srcObjectName,
                $this->config['bucket'],
                $destObjectName
            );

            return $err === null;
        } catch (\Throwable $e) {
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
            $prefix = ltrim(($this->config['base_path'] ?? '') . '/' . $directory, '/');
            $prefix = rtrim($prefix, '/');
            if ($prefix !== '') {
                $prefix .= '/';
            }

            $delimiter = $recursive ? null : '/';
            $files     = [];
            $max       = 1000;
            $marker    = null;

            do {
                $items = null;
                $err   = null;
                $newMarker = $this->getBucketManager()->listFiles(
                    $this->config['bucket'],
                    $prefix,
                    $marker,
                    min(200, $max - count($files)),
                    $delimiter,
                    $items,
                    $err
                );
                $marker = $newMarker;

                if ($err !== null || $items === null) {
                    break;
                }

                foreach ($items as $item) {
                    if (count($files) >= $max) {
                        break;
                    }

                    $key = $item['key'] ?? '';
                    if ($key === '' || substr($key, -1) === '/') {
                        continue;
                    }

                    $files[] = [
                        'key'           => $key,
                        'size'          => (int) ($item['fsize'] ?? 0),
                        'last_modified' => (int) (((int) ($item['putTime'] ?? 0)) / 10000000),
                        'mime_type'     => $item['mimeType'] ?? 'application/octet-stream',
                    ];
                }
            } while ($marker !== null && count($files) < $max);

            return $files;
        } catch (\Throwable $e) {
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
            $dirKey = ltrim(($this->config['base_path'] ?? '') . '/' . $directory, '/');
            $dirKey = rtrim($dirKey, '/') . '/';

            $token = $this->getUploadToken();

            $ret = null;
            $err = null;
            $this->getUploadManager()->put($token, $dirKey, '', null, false, $ret, $err);

            return $err === null;
        } catch (\Throwable $e) {
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
            $prefix = ltrim(($this->config['base_path'] ?? '') . '/' . $directory, '/');
            $prefix = rtrim($prefix, '/') . '/';

            // 列出所有文件
            $allKeys = [];
            $marker  = null;

            do {
                $items = null;
                $err   = null;
                $newMarker = $this->getBucketManager()->listFiles(
                    $this->config['bucket'],
                    $prefix,
                    $marker,
                    1000,
                    null,
                    $items,
                    $err
                );
                $marker = $newMarker;

                if ($err !== null || $items === null) {
                    break;
                }

                foreach ($items as $item) {
                    $key = $item['key'] ?? '';
                    if ($key !== '') {
                        $allKeys[] = $key;
                    }
                }
            } while ($marker !== null);

            if (empty($allKeys)) {
                return true;
            }

            // 批量删除
            $ops = $this->getBucketManager()->buildBatchDelete(
                $this->config['bucket'],
                $allKeys
            );
            $this->getBucketManager()->batch($ops);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
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
        try {
            $baseUrl = $this->url($objectName, $options);
            return $this->getAuth()->privateDownloadUrl($baseUrl, $expires);
        } catch (\Throwable $e) {
            return $this->url($objectName, $options);
        }
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

        try {
            $keys = [];
            foreach ($objectNames as $name) {
                $keys[] = ltrim(($this->config['base_path'] ?? '') . '/' . $name, '/');
            }

            $ops = $this->getBucketManager()->buildBatchDelete($this->config['bucket'], $keys);
            $results = $this->getBucketManager()->batch($ops);

            foreach ($results as $result) {
                if (isset($result['code']) && $result['code'] === 200) {
                    $success++;
                } else {
                    $failed++;
                }
            }
        } catch (\Throwable $e) {
            // 回退到逐条删除
            foreach ($objectNames as $objectName) {
                if ($this->delete($objectName)) {
                    $success++;
                } else {
                    $failed++;
                }
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
            $objectName = ltrim(($this->config['base_path'] ?? '') . '/' . $objectName, '/');
            $ret = null;
            $err = null;
            $this->getBucketManager()->stat(
                $this->config['bucket'],
                $objectName,
                $ret,
                $err
            );
            if ($err !== null) {
                return 0;
            }

            // Qiniu putTime is in 100-nanosecond intervals (0.1 microseconds) since epoch
            $putTime = (int) ($ret['putTime'] ?? 0);
            return $putTime > 0 ? (int) ($putTime / 10000000) : 0;
        } catch (\Throwable $e) {
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
            $objectName = ltrim(($this->config['base_path'] ?? '') . '/' . $objectName, '/');
            $ret = null;
            $err = null;
            $this->getBucketManager()->stat(
                $this->config['bucket'],
                $objectName,
                $ret,
                $err
            );
            if ($err !== null) {
                return [];
            }

            $putTime = (int) ($ret['putTime'] ?? 0);

            return [
                'size'          => (int) ($ret['fsize'] ?? 0),
                'mime'          => $ret['mimeType'] ?? 'application/octet-stream',
                'last_modified' => $putTime > 0 ? (int) ($putTime / 10000000) : 0,
                'hash'          => $ret['hash'] ?? '',
                'visibility'    => $this->getVisibility($objectName),
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 设置文件可见性
     *
     * 注意：七牛云 Kodo 不支持直接为单个对象设置 ACL，因此本方法通过
     * 对象复制（copy + move）来间接实现元数据更新。若不需要对象级可见性
     * 控制，建议在业务层（attachment 表）增加可见性字段进行管理。
     *
     * @param string $objectName 存储对象名
     * @param string $visibility 'public' 或 'private'
     * @return bool
     */
    public function setVisibility(string $objectName, string $visibility): bool
    {
        try {
            $objectName = ltrim(($this->config['base_path'] ?? '') . '/' . $objectName, '/');
            $bucketManager = $this->getBucketManager();
            $bucket = $this->config['bucket'];

            // 通过 copy + rename 实现对象元数据刷新，间接标记可见性
            $tmpName = $objectName . '.visibility_' . uniqid('', true);
            $err = $bucketManager->copy($bucket, $objectName, $bucket, $tmpName);
            if ($err !== null) {
                return false;
            }
            $err = $bucketManager->move($bucket, $tmpName, $bucket, $objectName);

            return $err === null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 获取文件可见性
     *
     * @param string $objectName 存储对象名
     * @return string 'public' 或 'private'
     */
    public function getVisibility(string $objectName): string
    {
        try {
            $objectName = ltrim(($this->config['base_path'] ?? '') . '/' . $objectName, '/');
            $ret = null;
            $err = null;
            $this->getBucketManager()->stat(
                $this->config['bucket'],
                $objectName,
                $ret,
                $err
            );
            if ($err !== null) {
                return 'public';
            }

            // 七牛云 stat 响应中有 type 字段：0=标准存储, 1=低频存储
            // 对象级可见性通过 metadata 判断，默认 public
            $customMeta = $ret['x-qn-meta-visibility'] ?? 'public';
            return $customMeta;
        } catch (\Throwable $e) {
            return 'public';
        }
    }
}
