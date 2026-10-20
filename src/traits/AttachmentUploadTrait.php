<?php
declare(strict_types=1);

namespace wise\attachment\traits;

use think\Request;
use think\facade\Config;
use think\facade\Cache;
use think\file\UploadedFile;
use wise\attachment\factory\AttachmentStore;
use wise\attachment\model\AttachmentModel;
use wise\attachment\tools\ImageProcessor;
use wise\attachment\exception\ServiceUnavailableException;
use wise\support\backoff\BackoffStrategy;
use wise\support\circuit\CircuitBreaker;
use wise\support\traits\JsonResponse;

/**
 * 附件上传 Trait
 *
 * 框架控制器引入此 Trait 即可获得统一上传能力（常规上传 + 分片上传）。
 * 需要控制器提供：
 *   - AttachmentStore 实例（通过 $this->attachmentStore 属性或构造函数注入）
 *   - success() / error() 方法（建议同时引入 wise\support\traits\JsonResponse）
 *
 * 路由建议：
 *   POST /admin/attachment/upload → attachmentUpload
 */
trait AttachmentUploadTrait
{
    use JsonResponse;

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
                // JsonResponse::error($msg, $data, $code)：状态码必须落在第 3 个参数，
                // 旧写法 error($msg, 400) 会把 400 当成 data 输出，code 恒为 999
                return $this->error('未检测到上传文件', [], 400);
            }

            $validateResult = $this->attachmentValidateUpload($file);
            if ($validateResult !== true) {
                return $this->error($validateResult, [], 422);
            }

            $chunk   = $request->param('chunk');
            $chunks  = $request->param('chunks');
            $chunkId = $request->param('chunk_id');

            if ($chunk !== null && $chunks !== null) {
                return $this->attachmentHandleChunkUpload($file, (int) $chunk, (int) $chunks, $chunkId, $request);
            }

            return $this->attachmentHandleUpload($file);

        } catch (ServiceUnavailableException $e) {
            return $this->circuitResponse($e);
        } catch (\Throwable $e) {
            // 内部细节只进日志，不进响应体（原始消息可能含服务器路径、存储后端标识等）
            \think\facade\Log::error('[wise-attachment] 上传失败: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            $message = env('APP_DEBUG', false) ? $e->getMessage() : '上传失败，请稍后重试';
            return $this->error($message, [], 500);
        }
    }

    /**
     * 处理常规上传
     * 
     */
    protected function attachmentHandleUpload(UploadedFile $file)
    {
        $objectName = $this->attachmentGenerateObjectName($file);
        $localPath  = $file->getPathname();
        $ext        = strtolower($file->getOriginalExtension());

        // 图片安全重写 — 通过 GD/Imagick 重新生成，剥离恶意代码
        if ($this->attachmentIsImage($ext)) {
            try {
                $localPath = $this->attachmentRewriteImage($localPath, $ext);
            } catch (\RuntimeException $e) {
                // 重写失败意味着文件不是有效图片（可能是伪装的恶意文件）
                throw new \RuntimeException("图片安全检查失败: " . $e->getMessage());
            }
        }

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

                return $this->success('上传成功（已去重）', [
                    'id'          => $attachment->id,
                    'url'         => $attachment->url,
                    'size'        => $attachment->size,
                    'mime'        => $attachment->mime_type,
                    'width'       => $attachment->image_width,
                    'height'      => $attachment->image_height,
                    'is_image'    => $attachment->is_image,
                    'is_duplicate'=> true,
                    'dedup_note'  => '文件内容已存在，复用已有存储',
                ]);
            }
        }

        // 非去重路径：真正执行上传（带熔断 + 防御性重试）
        $store  = $this->getAttachmentStore();
        $driver = $store->driver();
        $result = $this->attachmentUploadWithRetry($driver, $localPath, $objectName);

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

        return $this->success('上传成功', [
            'id'       => $attachment->id,
            'url'      => $attachment->url,
            'size'     => $attachment->size,
            'mime'     => $attachment->mime_type,
            'width'    => $attachment->image_width,
            'height'   => $attachment->image_height,
            'is_image' => $attachment->is_image,
        ]);
    }

    /**
     * 处理分片上传
     */
    protected function attachmentHandleChunkUpload(UploadedFile $file, int $chunk, int $chunks, ?string $chunkId, Request $request)
    {
        // 分片数量必须为正且在合理上限内：chunks=0 会产出 0 字节「成功」记录，极端值也无意义
        if ($chunks <= 0 || $chunks > 10000) {
            return $this->error('分片数量非法', [], 422);
        }

        // 目录名必须由「客户端可控、跨请求稳定」的种子派生：
        //   1) 客户端传了 chunk_id → 直接 md5（保留防路径穿越能力，回传同一值即可复现）；
        //   2) 未传 chunk_id → 用 filename + 总分片数派生，保证同一文件所有分片落到同一目录。
        // 旧实现用 uniqid() 兜底，会让每片落到不同目录，合并必然失败。
        $sessionSeed = ($chunkId !== null && $chunkId !== '')
            ? (string) $chunkId
            : (string) $request->param('filename', '') . '|' . $chunks;
        $safeChunkId = md5($sessionSeed);
        $tempDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'wise_attachment_chunks' . DIRECTORY_SEPARATOR . $safeChunkId . DIRECTORY_SEPARATOR;

        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        // 记录分片会话的最后活跃时间（用于定时清理孤儿分片）
        $touchFile = $tempDir . '.last_active';
        @touch($touchFile);

        $chunkFile = $tempDir . $chunk;
        if (!copy($file->getPathname(), $chunkFile)) {
            return $this->error("分片 {$chunk} 保存失败", [], 500);
        }
        @unlink($file->getPathname());

        if ($chunk + 1 >= $chunks) {
            $mergedFile = $tempDir . 'merged';
            $targetName = $request->param('filename', 'chunked_upload');
            // 过滤路径穿越字符，仅保留安全的文件名
            $targetName = basename(str_replace(['\\', '/', '..'], '', $targetName)) ?: 'chunked_upload';
            $mergedPath = $this->attachmentMergeChunks($tempDir, $mergedFile, $chunks);

            // 单片合规不代表合并后合规：必须按最终文件重新校验体积，
            // 否则「N 片 × max_size」即可绕过 upload.max_size
            $maxBytes = (int) ((float) Config::get('wise-attachment.upload.max_size', 50) * 1024 * 1024);
            if ($maxBytes > 0 && filesize($mergedPath) > $maxBytes) {
                $this->attachmentDelTree($tempDir);
                return $this->error('文件大小超过最大限制', [], 422);
            }

            $objectName = $this->attachmentGenerateObjectNameFromPath($targetName);
            $ext        = strtolower(pathinfo($targetName, PATHINFO_EXTENSION) ?: 'bin');

            // 图片安全重写 — 与常规上传保持一致
            if ($this->attachmentIsImage($ext)) {
                try {
                    $mergedPath = $this->attachmentRewriteImage($mergedPath, $ext);
                } catch (\RuntimeException $e) {
                    $this->attachmentDelTree($tempDir);
                    // 内部细节只进日志，响应体给固定文案（与 attachmentUpload 的兜底策略一致）
                    \think\facade\Log::warning('[wise-attachment] 图片安全检查失败: ' . $e->getMessage());
                    return $this->error('图片安全检查失败，请确认文件为有效图片', [], 422);
                }
            }

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

                    return $this->success('分片上传完成（已去重）', [
                        'id'          => $attachment->id,
                        'url'         => $attachment->url,
                        'size'        => $attachment->size,
                        'mime'        => $attachment->mime_type,
                        'width'       => $attachment->image_width,
                        'height'      => $attachment->image_height,
                        'is_image'    => $attachment->is_image,
                        'is_duplicate'=> true,
                        'dedup_note'  => '文件内容已存在，复用已有存储',
                    ]);
                }
            }

            // 非去重路径：真正执行上传（带熔断 + 防御性重试）
            $store  = $this->getAttachmentStore();
            $driver = $store->driver();
            $result = $this->attachmentUploadWithRetry($driver, $mergedPath, $objectName);

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

            return $this->success('分片上传完成', [
                'id'       => $attachment->id,
                'url'      => $attachment->url,
                'size'     => $attachment->size,
                'mime'     => $attachment->mime_type,
                'width'    => $attachment->image_width,
                'height'   => $attachment->image_height,
                'is_image' => $attachment->is_image,
            ]);
        }

        return $this->success("分片 {$chunk} 已接收", [
            'chunk'    => $chunk,
            'chunks'   => $chunks,
            'progress' => round((($chunk + 1) / $chunks) * 100, 2),
            // 回传「会话种子」而非 md5 值：客户端原样回传后 md5(seed) 仍是同一目录，
            // 若回传 $safeChunkId 会被二次 md5，导致后续分片落到新目录、合并失败
            'chunk_id' => $sessionSeed,
        ]);
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
    protected function attachmentGenerateObjectName(UploadedFile $file): string
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
     * 校验链（按顺序，任何一步失败即返回错误消息）：
     *   1. 扩展名白名单校验
     *   2. 可执行扩展名拦截（security.blocked_extensions）
     *   3. 文件大小限制
     *   4. 幻数检测（security.magic_number_check）
     *
     * @return bool|string true 表示通过，字符串为错误消息
     */
    protected function attachmentValidateUpload(UploadedFile $file)
    {
        $ext = strtolower($file->getOriginalExtension());

        // ── 第一步：扩展名白名单 ──
        $allowedExt = Config::get('wise-attachment.upload.allowed_ext', '');
        if ($allowedExt) {
            $allowedExts = array_map('trim', explode(',', $allowedExt));
            if (!in_array($ext, $allowedExts, true)) {
                return "不允许的文件类型 [{$ext}]，允许的类型: {$allowedExt}";
            }
        }

        // ── 第二步：可执行扩展名拦截（防御 Webshell）──
        $result = $this->attachmentBlockExecutableExt($ext);
        if ($result !== true) {
            return $result;
        }

        // ── 第三步：文件大小 ──
        $maxSize  = (float) Config::get('wise-attachment.upload.max_size', 50);
        $maxBytes = (int) ($maxSize * 1024 * 1024);
        if ($file->getSize() > $maxBytes) {
            return "文件大小超过最大限制 ({$maxSize}MB)";
        }

        // ── 第四步：幻数检测 ──
        $result = $this->attachmentValidateMagicNumber($file);
        if ($result !== true) {
            return $result;
        }

        return true;
    }

    // ==================== 上传安全：可执行扩展名拦截 ====================

    /**
     * 拦截可执行扩展名
     *
     * 即使 allowed_ext 误配置了高危扩展名，security.blocked_extensions 也会强制拦截。
     * 双层防护：upload.allowed_ext 白名单 + security.blocked_extensions 黑名单。
     *
     * @param string $ext 小写扩展名（不含点号）
     * @return bool|string true 通过；字符串为错误消息
     */
    protected function attachmentBlockExecutableExt(string $ext)
    {
        $blockedExts = Config::get('wise-attachment.security.blocked_extensions', []);
        if (empty($blockedExts)) {
            return true;
        }

        if (in_array($ext, $blockedExts, true)) {
            return "安全策略禁止上传 [.{$ext}] 类型文件";
        }

        return true;
    }

    // ==================== 上传安全：幻数检测 ====================

    /**
     * 已知文件类型的幻数映射表
     *
     * 键为小写扩展名，值为幻数签名数组。
     * 每个签名格式：[offset, hex_bytes] — offset 为偏移量，hex_bytes 为十六进制字节序列。
     * null offset 表示从文件开头匹配，负数 offset 表示从文件末尾偏移。
     *
     * 仅列出框架允许上传的文件类型。
     * 扩展性：各项目可通过覆盖 Config 来扩展此列表（不修改 Trait 源码）。
     *
     * @return array<string, array>
     */
    protected function attachmentMagicNumbers(): array
    {
        return [
            // 图片
            'jpg'  => [[0, 'FFD8FF']],
            'jpeg' => [[0, 'FFD8FF']],
            'png'  => [[0, '89504E47']],
            'gif'  => [[0, '47494638']],
            'bmp'  => [[0, '424D']],
            'webp' => [[0, '52494646'], [8, '57454250']],  // RIFF....WEBP
            // SVG 是 XML 文本，幻数检测不适用；通过 ImageProcessor 重写时会被拒绝
            // 保留扩展名白名单校验 + 后续图片重写作为防御

            // 文档
            'pdf'  => [[0, '25504446']],                    // %PDF
            'doc'  => [[0, 'D0CF11E0A1B11AE1']],            // OLE Compound Document
            'docx' => [[0, '504B0304']],                    // ZIP-based OOXML
            'xls'  => [[0, 'D0CF11E0A1B11AE1']],
            'xlsx' => [[0, '504B0304']],
            'ppt'  => [[0, 'D0CF11E0A1B11AE1']],
            'pptx' => [[0, '504B0304']],

            // 压缩包
            'zip'  => [[0, '504B0304']],
            'rar'  => [[0, '526172211A0700']],              // Rar!...
            '7z'   => [[0, '377ABCAF271C']],

            // 音视频 — 仅做基础检测，完整格式校验过于复杂
            'mp4'  => [[4, '66747970']],                    // ....ftyp
            'mp3'  => [[0, 'FFFB'], [0, 'FFFA'], [0, 'FFF3'], [0, '494433']],  // MPEG frame / ID3
            'avi'  => [[0, '52494646'], [8, '41564920']],   // RIFF....AVI
            'mov'  => [[4, '66747970']],                    // ....ftyp (QuickTime)
            'mkv'  => [[0, '1A45DFA3']],                    // EBML header
        ];
    }

    /**
     * 幻数校验 — 文件头字节 vs 扩展名
     *
     * 读取文件前 32 字节，与已知幻数签名表逐一比对。
     * 防止攻击者将 .php 或 .exe 文件重命名为 .jpg 绕过扩展名白名单。
     *
     * 注意：
     *   - SVG/CSV/TXT 等纯文本文件无固定幻数，不做检测
     *   - 若扩展名不在幻数映射表中，默认放行（仅依赖扩展名白名单）
     *
     * @param UploadedFile $file
     * @return bool|string true 通过；字符串为错误消息
     */
    protected function attachmentValidateMagicNumber(UploadedFile $file)
    {
        $enabled = Config::get('wise-attachment.security.magic_number_check', true);
        if (!$enabled) {
            return true;
        }

        $ext    = strtolower($file->getOriginalExtension());
        $magics = $this->attachmentMagicNumbers();

        // 未注册幻数的类型（SVG/TXT 等）→ 放行
        if (!isset($magics[$ext])) {
            return true;
        }

        $filePath = $file->getPathname();
        if (!$filePath || !file_exists($filePath)) {
            return true; // 无法读取文件时放行（避免阻塞合法上传）
        }

        // 读取文件头 32 字节用于幻数比对
        $head = @file_get_contents($filePath, false, null, 0, 32);
        if ($head === false || strlen($head) < 4) {
            return true; // 文件读取失败时放行
        }

        $headHex = strtoupper(bin2hex($head));

        // 签名语义：
        //   - 偏移 0 的多个签名互为「或」（同一格式的不同编码，如 mp3 的 FFFB/FFFA/ID3）；
        //   - 偏移 > 0 的签名是对偏移 0 的补充约束，必须全部命中（如 webp 的 RIFF + WEBP）；
        //   - 表中不存在偏移 0 的签名时（mp4/mov 的 ftyp 位于偏移 4），按「或」匹配。
        // 旧实现「任一签名命中即通过」会让任意 RIFF 开头的文件冒充 .webp/.avi。
        $hasOffsetZero  = false;
        $primaryOk      = false;
        $supplementalOk = true;

        foreach ($magics[$ext] as $signature) {
            [$offset, $hexBytes] = $signature;
            $sigHex = strtoupper($hexBytes);
            $sigLen = (int) (strlen($sigHex) / 2);

            if ($offset === 0) {
                $hasOffsetZero = true;
                if (substr($headHex, 0, $sigLen * 2) === $sigHex) {
                    $primaryOk = true;
                }
                continue;
            }

            if ($offset < 0) {
                // 从文件末尾偏移（用于某些特殊格式）
                $tail = @file_get_contents($filePath, false, null, $offset, abs($offset));
                if ($tail !== false && str_starts_with(strtoupper(bin2hex($tail)), $sigHex)) {
                    $primaryOk = true;
                }
                continue;
            }

            // 偏移 > 0：作为补充约束，任一不匹配即整体判定失败
            if (substr($headHex, $offset * 2, $sigLen * 2) !== $sigHex) {
                $supplementalOk = false;
            }
        }

        if ($hasOffsetZero ? ($primaryOk && $supplementalOk) : $supplementalOk) {
            return true;
        }

        // 所有签名都不匹配 — 文件头与扩展名不一致
        $expectedType = strtoupper($ext);
        return "文件内容与扩展名 [{$expectedType}] 不匹配，疑似伪造文件类型，已拒绝上传";
    }

    // ==================== 上传安全：图片重写 ====================

    /**
     * 图片文件重写 — 通过 GD/Imagick 重新生成
     *
     * 作用：
     *   1. 剥离嵌入的恶意代码（PHP webshell 注入到 EXIF / 文件尾）
     *   2. 移除 EXIF 中的敏感元数据（GPS 坐标、设备信息等）
     *   3. 修复损坏的图片结构（ImageProcessor 的 load → save 流程天然修复）
     *   4. SVG 文件拒绝重写（SVG 是 XML，可包含 <script> 标签，直接拒绝）
     *
     * 工作流程：
     *   源文件 → ImageProcessor::load() → ImageProcessor::save() → 重写后的安全文件 → 覆盖源文件
     *
     * 失败处理：
     *   重写失败的文件会被拒绝上传（可能是伪装的非图片文件）
     *
     * @param string $localPath 本地临时文件路径
     * @param string $ext       文件扩展名（小写）
     * @return string 重写后的文件路径（覆盖原文件则返回原路径）
     * @throws \RuntimeException 图片无法处理时抛出
     */
    protected function attachmentRewriteImage(string $localPath, string $ext): string
    {
        $enabled = Config::get('wise-attachment.security.image_rewrite', true);
        if (!$enabled) {
            return $localPath;
        }

        // SVG 必须先于「图片类型」判断被拒绝 —— 它是 XML 文本，GD 无法重写，且可内嵌 <script>。
        // 旧实现把该判断放在 $imageExts 白名单之后，而 svg 不在白名单里会先 return $localPath，
        // 使该判断成为不可达代码，含脚本的 SVG 被原样放行（存储型 XSS）。
        if ($ext === 'svg') {
            throw new \RuntimeException('SVG 文件存在 XSS 风险，禁止上传');
        }

        // 仅处理可被 GD 重写的位图类型
        $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
        if (!in_array($ext, $imageExts, true)) {
            return $localPath;
        }

        // 保存原始文件（如配置了 keep_original）
        $keepOriginal = Config::get('wise-attachment.security.keep_original', false);
        $originalPath = '';
        if ($keepOriginal) {
            $originalDir  = dirname($localPath) . DIRECTORY_SEPARATOR . '.original';
            $originalPath = $originalDir . DIRECTORY_SEPARATOR . basename($localPath);
            if (!is_dir($originalDir)) {
                mkdir($originalDir, 0755, true);
            }
            copy($localPath, $originalPath);
        }

        // 通过 ImageProcessor 重新生成图片
        try {
            $processor = new ImageProcessor();
            $processor->load($localPath);
            $processor->quality(85);

            // 临时文件必须带真实扩展名，并显式传入输出格式：
            // 旧实现保存到 '.rewritten'（无有效扩展名），ImageProcessor::save() 会落到
            // default 分支无条件写 JPEG，导致 PNG/GIF/WEBP 被静默转码、内容与扩展名不一致
            $rewrittenPath = $localPath . '.rewritten.' . $ext;
            $processor->save($rewrittenPath, $ext);

            // 验证重写后的文件是有效图片且不为空
            if (!file_exists($rewrittenPath) || filesize($rewrittenPath) === 0) {
                throw new \RuntimeException('图片重写后文件为空');
            }

            // 用重写后的安全文件覆盖源临时文件
            if (!rename($rewrittenPath, $localPath)) {
                // rename 失败时尝试 copy + unlink
                copy($rewrittenPath, $localPath);
                @unlink($rewrittenPath);
            }

            return $localPath;

        } catch (\RuntimeException $e) {
            // 图片重写失败 → 清理原始备份后拒绝上传
            if ($keepOriginal && $originalPath && file_exists($originalPath)) {
                @unlink($originalPath);
            }
            throw $e;
        }
    }

    /**
     * 检查是否为图片类型（用于判断是否需重写）
     *
     * @param string $ext 小写扩展名
     * @return bool
     */
    protected function attachmentIsImage(string $ext): bool
    {
        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg'], true);
    }

    /**
     * 带电断路保护 + 防御性重试的上传调用
     *
     * 断路器属于「韧性」能力而非「安全」能力：其后端（缓存）不可用时应降级放行，
     * 而不是把附件上传整体阻断。
     */
    protected function attachmentUploadWithRetry($driver, string $localPath, string $objectName): array
    {
        $breaker = null;

        try {
            $breaker = $this->getAttachmentBreaker($driver->getDriverName());
            if (!$breaker->isAvailable()) {
                throw new ServiceUnavailableException(driver: $driver->getDriverName());
            }
        } catch (ServiceUnavailableException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // 断路器后端不可用（如未部署 redis / 缓存连接失败）→ 降级为「无断路器」继续上传
            \think\facade\Log::warning('[wise-attachment] 断路器不可用，已降级放行: ' . $e->getMessage());
            $breaker = null;
        }

        try {
            $result = \retry(2, fn() => $driver->upload($localPath, $objectName), BackoffStrategy::fixed(1000));
            $this->attachmentReportBreaker($breaker, true);
            return $result;
        } catch (ServiceUnavailableException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->attachmentReportBreaker($breaker, false);
            throw $e;
        }
    }

    /**
     * 安全上报断路器状态
     *
     * 状态写入失败不得影响「上传已成功」或「上传已失败」的既有结论，
     * 否则会把一次成功的上传变成 500。
     */
    protected function attachmentReportBreaker(?CircuitBreaker $breaker, bool $success): void
    {
        if ($breaker === null) {
            return;
        }

        try {
            $success ? $breaker->reportSuccess() : $breaker->reportFailure();
        } catch (\Throwable $e) {
            \think\facade\Log::warning('[wise-attachment] 断路器状态写入失败: ' . $e->getMessage());
        }
    }

    /**
     * 按存储驱动创建/获取断路器实例
     */
    protected function getAttachmentBreaker(string $driverName): CircuitBreaker
    {
        static $breakers = [];
        if (!isset($breakers[$driverName])) {
            $cfg = Config::get('wise-attachment.circuit', []);

            // 状态存储可配置（circuit.store，默认 redis 以支持分布式共享状态），
            // 不再硬编码，便于无 redis 的环境改用 file 等本地缓存
            $storeName = (string) ($cfg['store'] ?? 'redis');
            $cache     = Cache::store($storeName);

            $breakers[$driverName] = new CircuitBreaker(
                $driverName,
                $cache,
                [
                    'failure_threshold' => $cfg['failure_threshold'] ?? 3,
                    'timeout'           => $cfg['timeout'] ?? 60,
                    'key_prefix'        => $cfg['key_prefix'] ?? 'wise_attachment:cb',
                ]
            );
        }
        return $breakers[$driverName];
    }
}
