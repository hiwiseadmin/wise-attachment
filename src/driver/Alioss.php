<?php
declare(strict_types=1);

/**
 * 阿里云 OSS 存储驱动
 *
 * 依赖: aliyuncs/oss-sdk-php
 * 安装: composer require aliyuncs/oss-sdk-php
 */
namespace wise\attachment\driver;

use think\facade\Config;

class Alioss implements DriverInterface
{
    use \wise\support\connector\StorageConnectorTrait;

    /**
     * 驱动配置
     *
     * @var array
     */
    protected $config;

    /**
     * OSS Client 实例（延迟加载缓存）
     *
     * @var \OSS\OssClient|null
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
            Config::get('wise-attachment.drivers.alioss', []),
            $config
        );
    }

    /**
     * 构建完整的存储 Key（base_path + objectName，避免双斜杠）
     *
     * @param string $objectName 存储对象名
     * @return string
     */
    protected function buildKey(string $objectName): string
    {
        $basePath = trim($this->config['base_path'] ?? '', '/');
        $name     = ltrim($objectName, '/');
        return $basePath !== '' ? $basePath . '/' . $name : $name;
    }

    /**
     * 获取 OSS Client（延迟连接 + 缓存复用）
     *
     * @return \OSS\OssClient
     * @throws \RuntimeException SDK 未安装时
     */
    protected function getClient()
    {
        if ($this->client === null) {
            if (!class_exists('\\OSS\\OssClient')) {
                throw new \RuntimeException('阿里云 OSS SDK 未安装，请执行: composer require aliyuncs/oss-sdk-php');
            }

            $this->client = new \OSS\OssClient(
                $this->config['access_key'],
                $this->config['secret_key'],
                $this->config['endpoint'],
                !empty($this->config['is_cname'])
            );
        }
        return $this->client;
    }

    /**
     * 上传文件到阿里云 OSS
     *
     * @param string $localPath  本地文件路径
     * @param string $objectName 存储对象名
     * @return array
     * @throws \RuntimeException
     */
    public function upload(string $localPath, string $objectName): array
    {
        $objectName = $this->buildKey($objectName);
        $client     = $this->getClient();

        try {
            $client->uploadFile(
                $this->config['bucket'],
                $objectName,
                $localPath
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException('阿里云 OSS 上传失败: ' . $e->getMessage());
        }

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
            'storage'   => 'alioss',
            'width'     => $width,
            'height'    => $height,
        ];
    }

    /**
     * 删除 OSS 上的文件
     *
     * @param string $objectName 存储对象名
     * @return bool
     * @throws \RuntimeException
     */
    public function delete(string $objectName): bool
    {
        $objectName = $this->buildKey($objectName);
        $client     = $this->getClient();

        try {
            $client->deleteObject($this->config['bucket'], $objectName);
            return true;
        } catch (\Throwable $e) {
            // NoSuchKey 错误表示文件不存在，视为删除成功
            $msg = $e->getMessage();
            if (is_string($msg) && (strpos($msg, 'NoSuchKey') !== false || strpos($msg, 'NotFound') !== false)) {
                return true;
            }
            throw new \RuntimeException('阿里云 OSS 删除失败: ' . $msg);
        }
    }

    /**
     * 获取文件访问 URL（支持 OSS 图片处理参数）
     *
     * @param string $objectName 存储对象名
     * @param array  $options    图片处理参数
     * @return string
     */
    public function url(string $objectName, array $options = []): string
    {
        $objectName = $this->buildKey($objectName);
        $protocol   = !empty($this->config['use_ssl']) ? 'https://' : 'http://';
        // 对路径分段进行 URL 编码，保留斜杠，防止特殊字符导致无效 URL
        $encodedName = implode('/', array_map('rawurlencode', explode('/', $objectName)));

        // 优先使用自定义域名
        if (!empty($this->config['domain'])) {
            $domain  = rtrim($this->config['domain'], '/');
            $baseUrl = $protocol . $domain . '/' . $encodedName;
        } else {
            $domain  = $this->config['bucket'] . '.' . $this->config['endpoint'];
            $baseUrl = $protocol . $domain . '/' . $encodedName;
        }

        // 图片处理参数（使用 OSS 图片处理）
        if (!empty($options)) {
            $query = $this->buildImageQuery($options);
            if ($query !== '') {
                return $baseUrl . '?x-oss-process=image/' . $query;
            }
        }

        return $baseUrl;
    }

    /**
     * 构建 OSS 图片处理参数
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

        // 缩放
        if (!empty($options['width']) && !empty($options['height'])) {
            $mode = $options['mode'] ?? 'fit';
            $modeMap = [
                'crop'  => 'm_fill',
                'fill'  => 'm_fill',
                'exact' => 'm_fixed',
                'fit'   => 'm_lfit',
            ];
            $m = $modeMap[$mode] ?? 'm_lfit';
            $params[] = 'resize,' . $m . ',w_' . $options['width'] . ',h_' . $options['height'];
        } elseif (!empty($options['width'])) {
            $params[] = 'resize,w_' . $options['width'];
        } elseif (!empty($options['height'])) {
            $params[] = 'resize,h_' . $options['height'];
        }

        // 质量
        if (!empty($options['quality'])) {
            $params[] = 'quality,q_' . $options['quality'];
        }

        return implode(',', $params);
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
            $objectName = $this->buildKey($objectName);
            return $this->getClient()->doesObjectExist(
                $this->config['bucket'],
                $objectName
            );
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
            $objectName = $this->buildKey($objectName);
            $meta = $this->getClient()->getObjectMeta(
                $this->config['bucket'],
                $objectName
            );
            return (int) ($meta['content-length'] ?? 0);
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
            $objectName = $this->buildKey($objectName);
            $meta = $this->getClient()->getObjectMeta(
                $this->config['bucket'],
                $objectName
            );
            return $meta['content-type'] ?? 'application/octet-stream';
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
        return 'alioss';
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
            $srcObjectName  = $this->buildKey($source);
            $destObjectName = $this->buildKey($destination);

            $this->getClient()->copyObject(
                $this->config['bucket'],
                $srcObjectName,
                $this->config['bucket'],
                $destObjectName
            );
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
            $srcObjectName  = $this->buildKey($source);
            $destObjectName = $this->buildKey($destination);

            $client = $this->getClient();

            $client->copyObject(
                $this->config['bucket'],
                $srcObjectName,
                $this->config['bucket'],
                $destObjectName
            );

            $client->deleteObject($this->config['bucket'], $srcObjectName);

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
            $prefix    = $this->buildKey($directory);
            $prefix    = rtrim($prefix, '/');
            if ($prefix !== '') {
                $prefix .= '/';
            }

            $delimiter = $recursive ? '' : '/';
            $files     = [];
            $max       = 1000;
            $marker    = '';

            do {
                $options = [
                    'prefix'    => $prefix,
                    'marker'    => $marker,
                    'max-keys'  => min(200, $max - count($files)),
                ];
                if ($delimiter !== '') {
                    $options['delimiter'] = $delimiter;
                }

                $result = $this->getClient()->listObjects(
                    $this->config['bucket'],
                    $options
                );

                $objectList = $result->getObjectList();
                foreach ($objectList as $objectInfo) {
                    if (count($files) >= $max) {
                        break;
                    }

                    $key = $objectInfo->getKey();
                    if (substr($key, -1) === '/') {
                        continue;
                    }

                    $files[] = [
                        'key'           => $key,
                        'size'          => (int) $objectInfo->getSize(),
                        'last_modified' => strtotime($objectInfo->getLastModified()),
                        'etag'          => $objectInfo->getETag(),
                    ];
                }

                $marker = $result->getNextMarker() ?? '';
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
            $dirKey = $this->buildKey($directory);
            $dirKey = rtrim($dirKey, '/') . '/';

            $this->getClient()->putObject(
                $this->config['bucket'],
                $dirKey,
                ''
            );
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
            $prefix = $this->buildKey($directory);
            $prefix = rtrim($prefix, '/') . '/';

            // 列出所有文件
            $allKeys = [];
            $marker  = '';

            do {
                $result = $this->getClient()->listObjects(
                    $this->config['bucket'],
                    [
                        'prefix'   => $prefix,
                        'marker'   => $marker,
                        'max-keys' => 1000,
                    ]
                );

                foreach ($result->getObjectList() as $objectInfo) {
                    $allKeys[] = $objectInfo->getKey();
                }

                $marker = $result->getNextMarker() ?? '';
            } while ($marker !== '');

            if (empty($allKeys)) {
                return true;
            }

            // 批量删除（每次最多 1000 个）
            $chunks = array_chunk($allKeys, 1000);
            foreach ($chunks as $chunk) {
                $this->getClient()->deleteObjects(
                    $this->config['bucket'],
                    $chunk
                );
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
        // 保留原始对象名：url() 内部会再次 buildKey()，
        // 回退时若传入已 buildKey() 的值会把 base_path 重复拼一次
        $rawObjectName = $objectName;

        try {
            $objectName = $this->buildKey($objectName);
            return $this->getClient()->signUrl(
                $this->config['bucket'],
                $objectName,
                $expires
            );
        } catch (\Throwable $e) {
            return $this->url($rawObjectName, $options);
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
            $keys = [];
            foreach ($objectNames as $name) {
                $keys[] = $this->buildKey($name);
            }

            // OSS deleteObjects 最多支持 1000 个
            $chunks  = array_chunk($keys, 1000);
            $success = 0;
            $failed  = 0;

            foreach ($chunks as $chunk) {
                $result = $this->getClient()->deleteObjects(
                    $this->config['bucket'],
                    $chunk
                );

                $deletedList = $result['deleted'] ?? [];
                $success += count($deletedList);
                $failed  += count($chunk) - count($deletedList);
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
            $objectName = $this->buildKey($objectName);
            $meta = $this->getClient()->getObjectMeta(
                $this->config['bucket'],
                $objectName
            );

            $lastModified = $meta['last-modified'] ?? '';
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
            $objectName = $this->buildKey($objectName);
            $meta = $this->getClient()->getObjectMeta(
                $this->config['bucket'],
                $objectName
            );

            $lastModified = $meta['last-modified'] ?? '';

            return [
                'size'          => (int) ($meta['content-length'] ?? 0),
                'mime'          => $meta['content-type'] ?? 'application/octet-stream',
                'last_modified' => $lastModified !== '' ? (strtotime($lastModified) ?: 0) : 0,
                'etag'          => $meta['etag'] ?? '',
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
            $objectName = $this->buildKey($objectName);

            // 确保 SDK 已加载后再引用常量
            $client = $this->getClient();
            $acl = \OSS\OssClient::OSS_ACL_TYPE_PUBLIC_READ;
            if ($visibility === 'private') {
                $acl = \OSS\OssClient::OSS_ACL_TYPE_PRIVATE;
            }

            $client->putObjectAcl(
                $this->config['bucket'],
                $objectName,
                $acl
            );
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
            $objectName = $this->buildKey($objectName);
            $acl = $this->getClient()->getObjectAcl(
                $this->config['bucket'],
                $objectName
            );

            if (strpos($acl, 'public') !== false) {
                return 'public';
            }
            return 'private';
        } catch (\Throwable $e) {
            return 'public';
        }
    }
}
