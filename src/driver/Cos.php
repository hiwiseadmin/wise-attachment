<?php
declare(strict_types=1);

/**
 * 腾讯云 COS 存储驱动
 *
 * 依赖: qcloud/cos-sdk-v5
 * 安装: composer require qcloud/cos-sdk-v5
 */
namespace wise\attachment\driver;

use think\facade\Config;

class Cos implements DriverInterface
{
    /**
     * 驱动配置
     *
     * @var array
     */
    protected $config;

    /**
     * COS Client 实例（延迟加载缓存）
     *
     * @var \Qcloud\Cos\Client|null
     */
    protected $client = null;

    /**
     * 构造函数
     *
     * @param array $config 驱动配置
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge(
            Config::get('wise-attachment.drivers.cos', []),
            $config
        );
    }

    /**
     * 获取 COS Client（延迟连接 + 缓存复用）
     *
     * @return \Qcloud\Cos\Client
     * @throws \RuntimeException SDK 未安装时
     */
    protected function getClient()
    {
        if ($this->client === null) {
            if (!class_exists('\\Qcloud\\Cos\\Client')) {
                throw new \RuntimeException('腾讯云 COS SDK 未安装，请执行: composer require qcloud/cos-sdk-v5');
            }

            $this->client = new \Qcloud\Cos\Client([
                'region'      => $this->config['region'],
                'schema'      => !empty($this->config['use_https']) ? 'https' : 'http',
                'credentials' => [
                    'secretId'  => $this->config['access_key'],
                    'secretKey' => $this->config['secret_key'],
                ],
            ]);
        }
        return $this->client;
    }

    /**
     * 上传文件到腾讯云 COS
     *
     * @param string $localPath  本地文件路径
     * @param string $objectName 存储对象名
     * @return array
     * @throws \RuntimeException
     */
    public function upload(string $localPath, string $objectName): array
    {
        $objectName = ltrim(($this->config['base_path'] ?? '') . '/' . $objectName, '/');
        $client     = $this->getClient();

        // 使用文件句柄上传，需确保关闭
        $handle = fopen($localPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("无法打开文件: {$localPath}");
        }

        try {
            $result = $client->upload(
                $this->config['bucket'],
                $objectName,
                $handle
            );
        } catch (\Throwable $e) {
            fclose($handle);
            throw new \RuntimeException('腾讯云 COS 上传失败: ' . $e->getMessage());
        }
        fclose($handle);

        $mimeType = mime_content_type($localPath) ?: 'application/octet-stream';
        $size     = (int) (filesize($localPath) ?: 0);

        // 图片尺寸
        $imageSize = @getimagesize($localPath);
        $width     = $imageSize ? $imageSize[0] : 0;
        $height    = $imageSize ? $imageSize[1] : 0;

        return [
            'url'       => $this->url($objectName),
            'path'      => $objectName,
            'size'      => $size,
            'mime_type' => $mimeType,
            'storage'   => 'cos',
            'width'     => $width,
            'height'    => $height,
        ];
    }

    /**
     * 删除 COS 上的文件
     *
     * @param string $objectName 存储对象名
     * @return bool
     */
    public function delete(string $objectName): bool
    {
        $objectName = ltrim(($this->config['base_path'] ?? '') . '/' . $objectName, '/');
        $client     = $this->getClient();

        try {
            $client->deleteObject([
                'Bucket' => $this->config['bucket'],
                'Key'    => $objectName,
            ]);
            return true;
        } catch (\Throwable $e) {
            // 文件不存在（NoSuchKey/404）也视为删除成功
            $msg = $e->getMessage();
            if (is_string($msg) && (strpos($msg, 'NoSuchKey') !== false || strpos($msg, 'Not Found') !== false || strpos($msg, '404') !== false)) {
                return true;
            }
            throw new \RuntimeException('腾讯云 COS 删除失败: ' . $msg);
        }
    }

    /**
     * 获取文件访问 URL（支持 CI 万象图片处理参数）
     *
     * @param string $objectName 存储对象名
     * @param array  $options    图片处理参数
     * @return string
     */
    public function url(string $objectName, array $options = []): string
    {
        $objectName = ltrim(($this->config['base_path'] ?? '') . '/' . $objectName, '/');

        // 优先使用自定义域名
        if (!empty($this->config['domain'])) {
            $protocol = !empty($this->config['use_https']) ? 'https://' : 'http://';
            $domain   = rtrim($this->config['domain'], '/');
            $baseUrl  = $protocol . $domain . '/' . $objectName;
        } else {
            // 默认 COS 域名
            $protocol = !empty($this->config['use_https']) ? 'https://' : 'http://';
            $domain   = $this->config['bucket'] . '.cos.' . $this->config['region'] . '.myqcloud.com';
            $baseUrl  = $protocol . $domain . '/' . $objectName;
        }

        // 图片处理（CI 万象）
        if (!empty($options)) {
            $query = $this->buildImageQuery($options);
            if ($query !== '') {
                return $baseUrl . '?imageMogr2/' . $query;
            }
        }

        return $baseUrl;
    }

    /**
     * 构建 COS 万象图片处理参数
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
            $mode = $options['mode'] ?? 'fit';
            switch ($mode) {
                case 'crop':
                    $params[] = 'thumbnail/' . $options['width'] . 'x' . $options['height'] . '!';
                    break;
                case 'exact':
                    $params[] = 'thumbnail/' . $options['width'] . 'x' . $options['height'] . '!';
                    break;
                default:
                    $params[] = 'thumbnail/' . $options['width'] . 'x' . $options['height'] . '>';
                    break;
            }
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
            $this->getClient()->headObject([
                'Bucket' => $this->config['bucket'],
                'Key'    => $objectName,
            ]);
            return true;
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
            $result = $this->getClient()->headObject([
                'Bucket' => $this->config['bucket'],
                'Key'    => $objectName,
            ]);
            return (int) ($result['ContentLength'] ?? 0);
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
            $result = $this->getClient()->headObject([
                'Bucket' => $this->config['bucket'],
                'Key'    => $objectName,
            ]);
            return $result['ContentType'] ?? 'application/octet-stream';
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
        return 'cos';
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

            $bucket  = $this->config['bucket'];
            $region  = $this->config['region'];
            $appId   = $this->config['app_id'] ?? '';

            $copySource = $bucket;
            if ($appId !== '') {
                $copySource .= '-' . $appId;
            }
            $copySource .= '.cos.' . $region . '.myqcloud.com/' . $srcObjectName;

            $this->getClient()->copyObject([
                'Bucket'     => $bucket,
                'Key'        => $destObjectName,
                'CopySource' => $copySource,
            ]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 移动/重命名文件（copy + delete）
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

            if ($srcObjectName === $destObjectName) {
                return true;
            }

            $client = $this->getClient();
            $bucket = $this->config['bucket'];
            $region = $this->config['region'];
            $appId  = $this->config['app_id'] ?? '';

            $copySource = $bucket;
            if ($appId !== '') {
                $copySource .= '-' . $appId;
            }
            $copySource .= '.cos.' . $region . '.myqcloud.com/' . $srcObjectName;

            $client->copyObject([
                'Bucket'     => $bucket,
                'Key'        => $destObjectName,
                'CopySource' => $copySource,
            ]);

            $client->deleteObject([
                'Bucket' => $bucket,
                'Key'    => $srcObjectName,
            ]);

            return true;
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

            $delimiter = $recursive ? '' : '/';
            $files     = [];
            $max       = 1000;
            $marker    = '';

            do {
                $args = [
                    'Bucket'   => $this->config['bucket'],
                    'Prefix'   => $prefix,
                    'MaxKeys'  => min(200, $max - count($files)),
                ];
                if ($marker !== '') {
                    $args['Marker'] = $marker;
                }
                if ($delimiter !== '') {
                    $args['Delimiter'] = $delimiter;
                }

                $result = $this->getClient()->listObjects($args);

                $contents = $result['Contents'] ?? [];
                foreach ($contents as $object) {
                    if (count($files) >= $max) {
                        break;
                    }

                    $key = $object['Key'] ?? '';
                    if ($key === '' || substr($key, -1) === '/') {
                        continue;
                    }

                    $files[] = [
                        'key'           => $key,
                        'size'          => (int) ($object['Size'] ?? 0),
                        'last_modified' => strtotime($object['LastModified'] ?? '') ?: 0,
                        'etag'          => $object['ETag'] ?? '',
                    ];
                }

                $marker = $result['NextMarker'] ?? '';
            } while ($marker !== '' && count($files) < $max);

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

            $this->getClient()->putObject([
                'Bucket' => $this->config['bucket'],
                'Key'    => $dirKey,
                'Body'   => '',
            ]);
            return true;
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
            $marker  = '';

            do {
                $args = [
                    'Bucket'  => $this->config['bucket'],
                    'Prefix'  => $prefix,
                    'MaxKeys' => 1000,
                ];
                if ($marker !== '') {
                    $args['Marker'] = $marker;
                }

                $result = $this->getClient()->listObjects($args);

                $contents = $result['Contents'] ?? [];
                foreach ($contents as $object) {
                    $key = $object['Key'] ?? '';
                    if ($key !== '') {
                        $allKeys[] = $key;
                    }
                }

                $marker = $result['NextMarker'] ?? '';
            } while ($marker !== '');

            if (empty($allKeys)) {
                return true;
            }

            // 批量删除
            $objects = array_map(function ($key) {
                return ['Key' => $key];
            }, $allKeys);

            $chunks = array_chunk($objects, 1000);
            foreach ($chunks as $chunk) {
                $this->getClient()->deleteObjects([
                    'Bucket'  => $this->config['bucket'],
                    'Objects' => $chunk,
                ]);
            }

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
            $objectName = ltrim(($this->config['base_path'] ?? '') . '/' . $objectName, '/');

            $expireStr = '+' . $expires . ' seconds';

            return (string) $this->getClient()->getPresignedUrl(
                'getObject',
                [
                    'Bucket'  => $this->config['bucket'],
                    'Key'     => $objectName,
                    'Expires' => $expireStr,
                ]
            );
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
        try {
            $objects = [];
            foreach ($objectNames as $name) {
                $key = ltrim(($this->config['base_path'] ?? '') . '/' . $name, '/');
                $objects[] = ['Key' => $key];
            }

            $chunks  = array_chunk($objects, 1000);
            $success = 0;
            $failed  = 0;

            foreach ($chunks as $chunk) {
                $result = $this->getClient()->deleteObjects([
                    'Bucket'  => $this->config['bucket'],
                    'Objects' => $chunk,
                ]);

                $deleted = $result['Deleted'] ?? [];
                $errors  = $result['Error'] ?? [];
                $success += count($deleted);
                $failed  += count($errors);
            }

            return ['success' => $success, 'failed' => $failed];
        } catch (\Throwable $e) {
            // 回退到逐条删除
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
            $result = $this->getClient()->headObject([
                'Bucket' => $this->config['bucket'],
                'Key'    => $objectName,
            ]);

            $lastModified = $result['LastModified'] ?? '';
            if ($lastModified !== '') {
                return strtotime($lastModified) ?: 0;
            }
            return 0;
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
            $result = $this->getClient()->headObject([
                'Bucket' => $this->config['bucket'],
                'Key'    => $objectName,
            ]);

            $lastModified = $result['LastModified'] ?? '';

            return [
                'size'          => (int) ($result['ContentLength'] ?? 0),
                'mime'          => $result['ContentType'] ?? 'application/octet-stream',
                'last_modified' => $lastModified !== '' ? (strtotime($lastModified) ?: 0) : 0,
                'etag'          => $result['ETag'] ?? '',
                'visibility'    => $this->getVisibility($objectName),
            ];
        } catch (\Throwable $e) {
            return [];
        }
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
        try {
            $objectName = ltrim(($this->config['base_path'] ?? '') . '/' . $objectName, '/');

            $acl = 'public-read';
            if ($visibility === 'private') {
                $acl = 'private';
            }

            $this->getClient()->putObjectAcl([
                'Bucket' => $this->config['bucket'],
                'Key'    => $objectName,
                'ACL'    => $acl,
            ]);
            return true;
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
            $result = $this->getClient()->getObjectAcl([
                'Bucket' => $this->config['bucket'],
                'Key'    => $objectName,
            ]);

            $acl = $result['ACL'] ?? '';
            if (strpos($acl, 'public') !== false) {
                return 'public';
            }
            return 'private';
        } catch (\Throwable $e) {
            return 'public';
        }
    }
}
