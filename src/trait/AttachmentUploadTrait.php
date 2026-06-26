<?php
declare(strict_types=1);

namespace wise\attachment\trait;

use think\Request;
use think\facade\Config;
use wise\attachment\factory\AttachmentStore;
use wise\attachment\model\AttachmentModel;

/**
 * 附件上传 Trait
 *
 * 框架控制器引入此 Trait 即可获得统一上传能力（常规上传 + 分片上传）。
 * 需要控制器提供：
 *   - AttachmentStore 实例（通过 $this->attachmentStore 属性或构造函数注入）
 *   - success($data, $msg) / error($msg, $code) 方法（通过 JsonResponse trait 或控制器自行实现）
 *
 * 路由建议：
 *   POST /admin/attachment/upload → attachmentUpload
 */
trait AttachmentUploadTrait
{
    /**
     * 附件存储工厂（由控制器设置）
     *
     * @var AttachmentStore|null
     */
    protected $attachmentStore = null;

    /**
     * 获取 AttachmentStore 实例
     *
     * 优先使用控制器属性，其次从容器获取。
     */
    protected function getAttachmentStore(): AttachmentStore
    {
        if ($this->attachmentStore === null) {
            $this->attachmentStore = app(AttachmentStore::class);
        }
        return $this->attachmentStore;
    }

    /**
     * 统一上传入口
     *
     * POST /admin/attachment/upload
     *
     * 请求参数：
     * - file:      File   上传文件（必填）
     * - chunk:     int    当前分片序号（分片上传时，从 0 开始）
     * - chunks:    int    总分片数
     * - chunk_id:  string 分片上传会话 ID
     * - filename:  string 原文件名（分片上传时携带）
     */
    public function attachmentUpload(Request $request)
    {
        try {
            $file = $request->file('file');
            if (!$file) {
                return $this->error('未检测到上传文件', 400);
            }

            $validateResult = $this->attachmentValidateUpload($file);
            if ($validateResult !== true) {
                return $this->error($validateResult, 422);
            }

            $chunk   = $request->param('chunk');
            $chunks  = $request->param('chunks');
            $chunkId = $request->param('chunk_id');

            if ($chunk !== null && $chunks !== null) {
                return $this->attachmentHandleChunkUpload($file, (int) $chunk, (int) $chunks, $chunkId, $request);
            }

            return $this->attachmentHandleUpload($file);

        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * 处理常规上传
     */
    protected function attachmentHandleUpload($file)
    {
        $objectName = $this->attachmentGenerateObjectName($file);
        $localPath  = $file->getPathname();

        // 先计算内容哈希，做去重检查（避免先上传再检测到重复导致孤儿文件）
        $contentHash = hash_file('sha256', $localPath) ?: '';
        $enableDedup = Config::get('wise-attachment.upload.enable_dedup', true);

        if ($enableDedup && !empty($contentHash)) {
            $existing = AttachmentModel::findByHash($contentHash);
            if ($existing) {
                // 发现重复文件，直接创建去重记录，无需上传到存储
                $attachment = AttachmentModel::create([
                    'storage'               => $existing->storage,
                    'object_name'           => $existing->object_name,
                    'url'                   => $existing->url,
                    'size'                  => $existing->size,
                    'mime_type'             => $existing->mime_type,
                    'image_width'           => $existing->image_width,
                    'image_height'          => $existing->image_height,
                    'is_image'              => $existing->is_image,
                    'content_hash'          => $contentHash,
                    'is_duplicate'          => 1,
                    'original_attachment_id'=> $existing->id,
                ]);

                return $this->success([
                    'id'          => $attachment->id,
                    'url'         => $attachment->url,
                    'size'        => $attachment->size,
                    'mime'        => $attachment->mime_type,
                    'width'       => $attachment->image_width,
                    'height'      => $attachment->image_height,
                    'is_image'    => $attachment->is_image,
                    'is_duplicate'=> true,
                    'dedup_note'  => '文件内容已存在，复用已有存储',
                ], '上传成功（已去重）');
            }
        }

        // 非去重路径：真正执行上传
        $store  = $this->getAttachmentStore();
        $driver = $store->driver();
        $result = $driver->upload($localPath, $objectName);

        // 创建新附件记录（带 content_hash）
        $attachment = AttachmentModel::create([
            'storage'      => $result['storage'] ?? $driver->getDriverName(),
            'object_name'  => $result['path'] ?? $objectName,
            'url'          => $result['url'],
            'size'         => $result['size'],
            'mime_type'    => $result['mime_type'],
            'image_width'  => $result['width'] ?? 0,
            'image_height' => $result['height'] ?? 0,
            'is_image'     => (!empty($result['width']) && $result['width'] > 0) ? 1 : 0,
            'content_hash' => $contentHash,
        ]);

        return $this->success([
            'id'       => $attachment->id,
            'url'      => $attachment->url,
            'size'     => $attachment->size,
            'mime'     => $attachment->mime_type,
            'width'    => $attachment->image_width,
            'height'   => $attachment->image_height,
            'is_image' => $attachment->is_image,
        ], '上传成功');
    }

    /**
     * 处理分片上传
     */
    protected function attachmentHandleChunkUpload($file, int $chunk, int $chunks, ?string $chunkId, Request $request)
    {
        // 始终使用安全哈希作为目录名，防止用户输入的 chunkId 导致路径穿越
        $safeChunkId = md5($chunkId ?: ($request->param('filename', '') . uniqid('', true)));
        $tempDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'wise_attachment_chunks' . DIRECTORY_SEPARATOR . $safeChunkId . DIRECTORY_SEPARATOR;

        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $chunkFile = $tempDir . $chunk;
        if (!copy($file->getPathname(), $chunkFile)) {
            return $this->error("分片 {$chunk} 保存失败", 500);
        }
        @unlink($file->getPathname());

        if ($chunk + 1 >= $chunks) {
            $mergedFile = $tempDir . 'merged';
            $targetName = $request->param('filename', 'chunked_upload');
            $mergedPath = $this->attachmentMergeChunks($tempDir, $mergedFile, $chunks);

            $objectName = $this->attachmentGenerateObjectNameFromPath($targetName);

            // 先计算内容哈希，做去重检查（避免先上传再检测到重复导致孤儿文件）
            $contentHash = hash_file('sha256', $mergedPath) ?: '';
            $enableDedup = Config::get('wise-attachment.upload.enable_dedup', true);

            if ($enableDedup && !empty($contentHash)) {
                $existing = AttachmentModel::findByHash($contentHash);
                if ($existing) {
                    // 发现重复文件，直接创建去重记录，无需上传到存储
                    $attachment = AttachmentModel::create([
                        'storage'               => $existing->storage,
                        'object_name'           => $existing->object_name,
                        'url'                   => $existing->url,
                        'size'                  => $existing->size,
                        'mime_type'             => $existing->mime_type,
                        'image_width'           => $existing->image_width,
                        'image_height'          => $existing->image_height,
                        'is_image'              => $existing->is_image,
                        'content_hash'          => $contentHash,
                        'is_duplicate'          => 1,
                        'original_attachment_id'=> $existing->id,
                    ]);

                    $this->attachmentDelTree($tempDir);

                    return $this->success([
                        'id'          => $attachment->id,
                        'url'         => $attachment->url,
                        'size'        => $attachment->size,
                        'mime'        => $attachment->mime_type,
                        'width'       => $attachment->image_width,
                        'height'      => $attachment->image_height,
                        'is_image'    => $attachment->is_image,
                        'is_duplicate'=> true,
                        'dedup_note'  => '文件内容已存在，复用已有存储',
                    ], '分片上传完成（已去重）');
                }
            }

            // 非去重路径：真正执行上传
            $store  = $this->getAttachmentStore();
            $driver = $store->driver();
            $result = $driver->upload($mergedPath, $objectName);

            $attachment = AttachmentModel::create([
                'storage'      => $result['storage'] ?? $driver->getDriverName(),
                'object_name'  => $result['path'] ?? $objectName,
                'url'          => $result['url'],
                'size'         => $result['size'],
                'mime_type'    => $result['mime_type'],
                'image_width'  => $result['width'] ?? 0,
                'image_height' => $result['height'] ?? 0,
                'is_image'     => (!empty($result['width']) && $result['width'] > 0) ? 1 : 0,
                'content_hash' => $contentHash,
            ]);

            $this->attachmentDelTree($tempDir);

            return $this->success([
                'id'       => $attachment->id,
                'url'      => $attachment->url,
                'size'     => $attachment->size,
                'mime'     => $attachment->mime_type,
                'width'    => $attachment->image_width,
                'height'   => $attachment->image_height,
                'is_image' => $attachment->is_image,
            ], '分片上传完成');
        }

        return $this->success([
            'chunk'    => $chunk,
            'chunks'   => $chunks,
            'progress' => round((($chunk + 1) / $chunks) * 100, 2),
            'chunk_id' => $safeChunkId,
        ], "分片 {$chunk} 已接收");
    }

    /**
     * 合并分片文件
     */
    protected function attachmentMergeChunks(string $tempDir, string $target, int $chunks): string
    {
        $merged = fopen($target, 'wb');
        if ($merged === false) {
            throw new \RuntimeException("无法创建合并文件: {$target}");
        }

        for ($i = 0; $i < $chunks; $i++) {
            $chunkFile = $tempDir . $i;
            if (!file_exists($chunkFile)) {
                fclose($merged);
                throw new \RuntimeException("分片文件 {$i} 不存在");
            }

            $chunkData = file_get_contents($chunkFile);
            if ($chunkData === false) {
                fclose($merged);
                throw new \RuntimeException("读取分片文件 {$i} 失败");
            }

            fwrite($merged, $chunkData);
            unlink($chunkFile);
        }

        fclose($merged);
        return $target;
    }

    /**
     * 递归删除目录
     */
    protected function attachmentDelTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->attachmentDelTree($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * 根据上传文件生成存储对象名
     */
    protected function attachmentGenerateObjectName($file): string
    {
        $pathFormat   = Config::get('wise-attachment.upload.path_format', 'Y/m/d');
        $nameStrategy = Config::get('wise-attachment.upload.name_strategy', 'md5');

        $subDir = date($pathFormat);
        $ext    = strtolower($file->getOriginalExtension() ?: 'bin');

        switch ($nameStrategy) {
            case 'keep':
                $name     = $file->getOriginalName();
                $basename = pathinfo($name, PATHINFO_FILENAME);
                $basename = preg_replace('/[^\w\-\.\x{4e00}-\x{9fa5}]/u', '_', $basename);
                $name     = $basename . '.' . $ext;
                break;
            case 'uniqid':
                $name = uniqid('', true) . '.' . $ext;
                break;
            case 'md5':
            default:
                $hash = md5_file($file->getPathname());
                $name = ($hash !== false ? $hash : uniqid('', true)) . '.' . $ext;
                break;
        }

        return $subDir . '/' . $name;
    }

    /**
     * 根据文件名生成存储对象名（用于分片上传完成后的最终文件）
     */
    protected function attachmentGenerateObjectNameFromPath(string $filename): string
    {
        $pathFormat   = Config::get('wise-attachment.upload.path_format', 'Y/m/d');
        $nameStrategy = Config::get('wise-attachment.upload.name_strategy', 'md5');
        $subDir       = date($pathFormat);
        $ext          = strtolower(pathinfo($filename, PATHINFO_EXTENSION) ?: 'bin');

        // 对齐普通上传的命名策略，保证分片上传与普通上传行为一致
        switch ($nameStrategy) {
            case 'keep':
                $basename = pathinfo($filename, PATHINFO_FILENAME);
                $basename = preg_replace('/[^\w\-\.\x{4e00}-\x{9fa5}]/u', '_', $basename);
                $name     = $basename . '.' . $ext;
                break;
            case 'uniqid':
                $name = uniqid('', true) . '.' . $ext;
                break;
            case 'md5':
            default:
                $name = md5(uniqid('', true) . $filename) . '.' . $ext;
                break;
        }

        return $subDir . '/' . $name;
    }

    /**
     * 文件上传校验
     *
     * @return bool|string true 表示通过，字符串为错误消息
     */
    protected function attachmentValidateUpload($file)
    {
        $allowedExt = Config::get('wise-attachment.upload.allowed_ext', '');
        if ($allowedExt) {
            $allowedExts = array_map('trim', explode(',', $allowedExt));
            $ext = strtolower($file->getOriginalExtension());
            if (!in_array($ext, $allowedExts, true)) {
                return "不允许的文件类型 [{$ext}]，允许的类型: {$allowedExt}";
            }
        }

        $maxSize  = (float) Config::get('wise-attachment.upload.max_size', 50);
        $maxBytes = (int) ($maxSize * 1024 * 1024);
        if ($file->getSize() > $maxBytes) {
            return "文件大小超过最大限制 ({$maxSize}MB)";
        }

        return true;
    }
}
