<?php
/**
 * WiseAttachment 配置文件
 *
 * 可通过应用 config/wise-attachment.php 覆盖默认值。
 */
return [
    // ---------------------------------------------------------------
    // 表名配置（用于数据库迁移与模型）
    // ---------------------------------------------------------------
    'table_names' => [
        'attachment' => 'attachment',
        'relation'   => 'attachment_relation',
        'category'   => 'attachment_category',
        'versions'   => 'attachment_version',
        'log'        => 'attachment_log',
    ],

    // ---------------------------------------------------------------
    // 默认存储驱动
    // 可选: local, ftp, qiniu, alioss, cos
    // ---------------------------------------------------------------
    'default_driver' => 'local',

    // ---------------------------------------------------------------
    // 各驱动配置
    // ---------------------------------------------------------------
    'drivers' => [
        // 本地存储
        'local' => [
            'driver'       => 'local',
            'root_path'    => env('attachment.local_root', root_path() . 'public' . DIRECTORY_SEPARATOR . 'uploads'),
            'base_url'     => env('attachment.local_url', '/uploads'),
            'public_path'  => env('attachment.local_public', ''),
        ],

        // FTP 存储
        'ftp' => [
            'driver'       => 'ftp',
            'host'         => env('attachment.ftp_host', '127.0.0.1'),
            'port'         => env('attachment.ftp_port', 21),
            'username'     => env('attachment.ftp_username', ''),
            'password'     => env('attachment.ftp_password', ''),
            'root'         => env('attachment.ftp_root', '/'),
            'passive'      => env('attachment.ftp_passive', true),
            'ssl'          => env('attachment.ftp_ssl', false),
            'timeout'      => env('attachment.ftp_timeout', 30),
            'base_url'     => env('attachment.ftp_url', ''),
        ],

        // 七牛云存储
        'qiniu' => [
            'driver'       => 'qiniu',
            'access_key'   => env('attachment.qiniu_access_key', ''),
            'secret_key'   => env('attachment.qiniu_secret_key', ''),
            'bucket'       => env('attachment.qiniu_bucket', ''),
            'domain'       => env('attachment.qiniu_domain', ''),
            'base_path'    => env('attachment.qiniu_base_path', ''),
            'use_https'    => env('attachment.qiniu_https', true),
            'upload_url'   => env('attachment.qiniu_upload_url', ''),   // 自定义上传域名
        ],

        // 阿里云 OSS
        'alioss' => [
            'driver'       => 'alioss',
            'access_key'   => env('attachment.alioss_access_key', ''),
            'secret_key'   => env('attachment.alioss_secret_key', ''),
            'bucket'       => env('attachment.alioss_bucket', ''),
            'endpoint'     => env('attachment.alioss_endpoint', 'oss-cn-hangzhou.aliyuncs.com'),
            'domain'       => env('attachment.alioss_domain', ''),          // 自定义域名（CDN）
            'base_path'    => env('attachment.alioss_base_path', ''),
            'use_ssl'      => env('attachment.alioss_ssl', true),
            'is_cname'     => env('attachment.alioss_cname', false),
        ],

        // 腾讯云 COS
        'cos' => [
            'driver'       => 'cos',
            'access_key'   => env('attachment.cos_secret_id', ''),
            'secret_key'   => env('attachment.cos_secret_key', ''),
            'bucket'       => env('attachment.cos_bucket', ''),
            'region'       => env('attachment.cos_region', 'ap-guangzhou'),
            'domain'       => env('attachment.cos_domain', ''),              // 自定义域名（CDN）
            'base_path'    => env('attachment.cos_base_path', ''),
            'use_https'    => env('attachment.cos_https', true),
        ],
    ],

    // ---------------------------------------------------------------
    // 图片处理配置
    // ---------------------------------------------------------------
    'image' => [
        // 驱动: gd, imagick
        'driver' => 'gd',

        // 默认图片质量 (0-100)
        'default_quality' => 85,

        // 尺寸白名单 — 只允许生成列表中的尺寸，防止恶意请求
        // 格式: 'key' => ['width' => ?, 'height' => ?, 'mode' => 'mode']
        // mode: fit(等比缩放), fill(填充裁剪), exact(拉伸), crop(居中裁剪)
        'whitelist_sizes' => [
            'thumb'    => ['width' => 150,  'height' => 150,  'mode' => 'crop'],
            'small'    => ['width' => 320,  'height' => 240,  'mode' => 'fit'],
            'medium'   => ['width' => 800,  'height' => 600,  'mode' => 'fit'],
            'large'    => ['width' => 1200, 'height' => 900,  'mode' => 'fit'],
            'avatar'   => ['width' => 256,  'height' => 256,  'mode' => 'crop'],
            'cover'    => ['width' => 1920, 'height' => 1080, 'mode' => 'crop'],
        ],

        // 允许的最大原始尺寸（超出此尺寸拒绝处理）
        'max_width'  => 4000,
        'max_height' => 4000,

        // 缩略图存储路径（相对于上传根目录）
        'thumb_dir' => 'thumb',

        // 水印默认配置
        'watermark' => [
            // 是否默认启用水印
            'enabled' => false,
            // 水印类型: text|image
            'type' => 'text',
            // 文字水印
            'text' => [
                'content'  => '',
                'font_size'=> 16,
                'color'    => '#ffffff',
                'opacity'  => 50,
                'position' => 'bottom-right',
                'offset_x' => 10,
                'offset_y' => 10,
            ],
            // 图片水印
            'image' => [
                'path'     => '',
                'opacity'  => 30,
                'position' => 'bottom-right',
                'offset_x' => 10,
                'offset_y' => 10,
                'scale'    => 0.3,
            ],
            // 最小图片尺寸（小于此尺寸不加水印）
            'min_width'  => 200,
            'min_height' => 200,
        ],

        // TTF 字体路径（Windows/Linux 常见路径会自动尝试）
        'font_path' => '',

        // 是否启用 wise-image 增强（需要安装 wiseadmin/wise-image）
        'use_wise_image' => true,
    ],

    // ---------------------------------------------------------------
    // 清理任务配置
    // ---------------------------------------------------------------
    'clean' => [
        // 软删除后保留天数，超期后物理删除
        'retention_days' => 30,

        // 每次清理最大处理数量（防止阻塞）
        'batch_size' => 100,
    ],

    // ---------------------------------------------------------------
    // 上传限制
    // ---------------------------------------------------------------
    'upload' => [
        // 允许的文件扩展名（逗号分隔）
        'allowed_ext' => 'jpg,jpeg,png,gif,bmp,webp,svg,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,zip,rar,7z,mp4,mp3,avi,mov,mkv',

        // 最大文件大小 (MB)
        'max_size' => 50,

        // 上传路径前缀（年/月/日 目录）
        'path_format' => 'Y/m/d',

        // 文件名策略: md5(文件内容哈希), uniqid(唯一ID), keep(保留原名)
        'name_strategy' => 'md5',

        // 文件内容去重（SHA256 哈希），true 表示同内容文件复用已有存储
        'enable_dedup' => true,
    ],

];
