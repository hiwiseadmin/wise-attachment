<?php
/**
 * WiseAttachment 配置文件
 *
 * 可通过应用 config/wise-attachment.php 覆盖默认值。
 */
return [
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

    // ---------------------------------------------------------------
    // 上传安全配置
    // ---------------------------------------------------------------
    'security' => [
        // 幻数检测 — 校验文件头字节是否与扩展名匹配
        // 防止攻击者伪装文件类型（如将 .php 重命名为 .jpg 上传）
        'magic_number_check' => true,

        // 图片重写 — 图片文件上传后通过 GD/Imagick 重新生成
        // 剥离嵌入的恶意代码（PHP webshell / SVG XSS / EXIF 注入）
        // 启用后图片文件体积可能略有变化，但安全性大幅提升
        'image_rewrite' => true,

        // 图片重写后保留原始文件（用于合规审计）
        // 原始文件存储在同目录 .original 子目录下
        'keep_original' => false,

        // 禁止的可执行扩展名（防御 Webshell 上传）
        // 即使 allowed_ext 误配置了这些扩展名，security 层也会拦截
        'blocked_extensions' => [
            'php', 'phtml', 'pht', 'php3', 'php4', 'php5', 'php7', 'php8',
            'phar', 'inc', 'shtml', 'shtm', 'cgi', 'pl', 'py', 'asp',
            'aspx', 'ashx', 'asmx', 'ascx', 'cshtml', 'vbhtml',
            'jsp', 'jspx', 'jsf', 'war',
            'exe', 'com', 'bat', 'cmd', 'vbs', 'vbe', 'js', 'jse',
            'wsf', 'wsh', 'ps1', 'psm1', 'psd1', 'psc1',
            'msi', 'msp', 'cpl', 'scr',
            'htaccess', 'htpasswd', 'user.ini',
        ],
    ],

    // ================================================================
    // 存储驱动级断路器（Circuit Breaker）
    // ================================================================
    'circuit' => [
        'failure_threshold' => 3,
        'timeout'           => 60,
        'key_prefix'        => 'wise_attachment:cb',
        // 断路器状态存储（cache store 名）。默认 redis 以支持分布式共享状态；
        // 该存储不可用时上传链路会降级放行，不会整体阻断。
        'store'             => 'redis',
    ],

    // ================================================================
    // Web 路由（配置驱动，由 RouteRegistrar 读取）
    // ================================================================
    'web' => [
        // 总开关：false 时本包不注册任何 HTTP 路由
        'enable' => true,
        // 默认路由前缀（分组级 prefix 可覆盖）
        'prefix' => 'api/attachment',
        // 全局默认中间件（所有分组生效）
        'middleware' => [],
        // 分组配置：key 对应 RouteRegistrar::define() 中的 group('key', ...)
        'groups' => [
            'attachment' => [
                'enable'     => true,
                // 包路由必须自带认证：否则宿主控制器一换，upload/delete 即匿名可达。
                // 用 class_exists 守卫，保证未安装该中间件的宿主不受影响（与默认行为一致）。
                'middleware' => class_exists(\app\common\middleware\AttachmentLogin::class)
                    ? [[\app\common\middleware\AttachmentLogin::class, []]]
                    : [],
            ],
        ],
    ],
];
