# wiseadmin/wise-attachment

> 为 ThinkPHP 6/8 提供的多驱动附件管理包——支持本地、FTP、七牛云、阿里云 OSS、腾讯云 COS 五种存储，集成图片处理、软删除、多态关联。

[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-blue)](https://www.php.net)
[![ThinkPHP](https://img.shields.io/badge/ThinkPHP-%5E6.0%20%7C%7C%20%5E8.0-green)](https://www.thinkphp.cn)
[![License](https://img.shields.io/badge/license-MIT-brightgreen)](LICENSE)
[![Packagist](https://img.shields.io/badge/packagist-wiseadmin%2Fwise--attachment-orange)](https://packagist.org/packages/wiseadmin/wise-attachment)

---

## 特性

| 模块 | 说明 |
|------|------|
| **多驱动存储** | 本地 / FTP / 七牛云 Kodo / 阿里云 OSS / 腾讯云 COS，统一接口一键切换 |
| **多态关联** | `HasAttachment` Trait，任意模型挂载即可获得附件关联能力 |
| **图片处理** | 内置 `ImageProcessor`，支持缩略图、水印、裁剪 |
| **软删除** | 附件记录支持软删除，数据可恢复 |
| **上传 Trait** | `AttachmentUploadTrait` 封装完整上传流程，控制器开箱即用 |
| **自动清理** | `wise:attachment:clean` 命令清理过期/孤儿附件 |

---

## 安装

```bash
composer require wiseadmin/wise-attachment
```

按需安装云存储 SDK：

```bash
# 七牛云
composer require qiniu/php-sdk

# 阿里云 OSS
composer require aliyuncs/oss-sdk-php

# 腾讯云 COS
composer require qcloud/cos-sdk-v5
```

---

## 配置

发布配置文件：

```bash
php think wise:attachment:publish
```

配置文件 `config/wise-attachment.php`：

```php
return [
    // 默认存储驱动
    'default_driver' => 'local',

    // 驱动配置
    'drivers' => [
        // 本地存储
        'local' => [
            'root' => public_path() . 'uploads',
            'url'  => '/uploads',
        ],

        // 七牛云 Kodo
        'qiniu' => [
            'access_key' => env('QINIU_ACCESS_KEY'),
            'secret_key' => env('QINIU_SECRET_KEY'),
            'bucket'     => env('QINIU_BUCKET'),
            'domain'     => env('QINIU_DOMAIN'),
        ],

        // 阿里云 OSS
        'alioss' => [
            'access_key_id'     => env('OSS_ACCESS_KEY_ID'),
            'access_key_secret' => env('OSS_ACCESS_KEY_SECRET'),
            'endpoint'          => env('OSS_ENDPOINT'),
            'bucket'            => env('OSS_BUCKET'),
            'domain'            => env('OSS_DOMAIN'),
        ],

        // 腾讯云 COS
        'cos' => [
            'secret_id'  => env('COS_SECRET_ID'),
            'secret_key' => env('COS_SECRET_KEY'),
            'region'     => env('COS_REGION'),
            'bucket'     => env('COS_BUCKET'),
            'domain'     => env('COS_DOMAIN'),
        ],

        // FTP
        'ftp' => [
            'host'     => env('FTP_HOST'),
            'port'     => 21,
            'username' => env('FTP_USERNAME'),
            'password' => env('FTP_PASSWORD'),
            'root'     => '/uploads',
            'ssl'      => false,
            'passive'  => true,
        ],
    ],

    // 上传配置
    'upload' => [
        'max_size'    => 10 * 1024 * 1024,  // 10MB
        'allow_types' => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'],
        'allow_mimes' => [],
        'auto_rename' => true,
        'save_path'   => '',
    ],

    // 图片缩略图
    'thumb' => [
        'enable' => false,
        'width'  => 200,
        'height' => 200,
    ],
];
```

---

## 使用指南

### 1. 上传附件

```php
use wise\attachment\factory\AttachmentStore;

$store = app(AttachmentStore::class);

// 使用默认驱动上传
$result = $store->driver()->upload('/tmp/photo.jpg', 'avatars/user_1.jpg');
// 返回: ['path' => 'avatars/user_1.jpg', 'url' => '/uploads/avatars/user_1.jpg', ...]

// 指定驱动上传
$result = $store->qiniu()->upload('/tmp/photo.jpg', 'avatars/user_1.jpg');
```

### 2. 保存附件记录

```php
use wise\attachment\model\AttachmentModel;

$attachment = AttachmentModel::create([
    'name'      => 'photo.jpg',
    'path'      => 'avatars/photo_20250101.jpg',
    'url'       => '/uploads/avatars/photo_20250101.jpg',
    'mime'      => 'image/jpeg',
    'size'      => 102400,
    'driver'    => 'local',
    'admin_id'  => 1,
]);
```

### 3. 模型多态关联

```php
namespace app\admin\model;

use think\Model;
use wise\attachment\trait\HasAttachment;

class Article extends Model
{
    use HasAttachment;
}

// 使用
$article = Article::find(1);

// 添加附件关联
$article->attach($attachmentId);
$article->attach([1, 2, 3]);

// 获取附件列表
$attachments = $article->getAttachments();

// 同步附件（先清空再设置）
$article->syncAttachment([1, 3, 5]);

// 移除关联
$article->detach($attachmentId);

// 清理所有关联
$article->clearAttachments();
```

### 4. 获取文件信息

```php
use wise\attachment\factory\AttachmentStore;

$store = app(AttachmentStore::class);

// 获取访问 URL
$url = $store->driver()->url('avatars/user_1.jpg');

// 检查文件是否存在
if ($store->driver()->exists('avatars/user_1.jpg')) {
    // ...
}

// 获取文件大小
$size = $store->driver()->size('avatars/user_1.jpg');

// 获取 MIME 类型
$mime = $store->driver()->mimeType('avatars/user_1.jpg');
```

### 5. 删除文件

```php
// 软删除附件记录
$attachment = AttachmentModel::find(1);
$attachment->delete();  // 仅标记删除

// 物理删除存储文件
$store->driver()->delete('avatars/user_1.jpg');
```

### 6. 清理命令

```bash
# 清理过期附件（软删除超过30天的记录）
php think wise:attachment:clean
```

---

## 驱动接口

所有驱动实现 `DriverInterface` 接口：

```php
interface DriverInterface
{
    public function upload(string $localPath, string $objectName): array;
    public function delete(string $objectName): bool;
    public function url(string $objectName, array $options = []): string;
    public function exists(string $objectName): bool;
    public function size(string $objectName): int;
    public function mimeType(string $objectName): string;
    public function getDriverName(): string;
}
```

---

## 目录结构

```
wise-attachment/
├── composer.json
├── config/
│   └── wise-attachment.php        # 配置文件
├── database/
│   └── migrations/                # 数据库迁移
└── src/
    ├── Service.php                # 服务提供者
    ├── command/
    │   └── Clean.php              # 清理命令
    ├── driver/
    │   ├── DriverInterface.php    # 驱动接口
    │   ├── Local.php              # 本地存储驱动
    │   ├── Ftp.php                # FTP 存储驱动
    │   ├── Qiniu.php              # 七牛云 Kodo 驱动
    │   ├── Alioss.php             # 阿里云 OSS 驱动
    │   └── Cos.php                # 腾讯云 COS 驱动
    ├── factory/
    │   └── AttachmentStore.php    # 驱动工厂（核心入口）
    ├── model/
    │   ├── AttachmentModel.php    # 附件主表模型
    │   └── AttachmentRelation.php # 多态关联中间表
    ├── tools/
    │   └── ImageProcessor.php     # 图片处理工具
    └── trait/
        ├── HasAttachment.php      # 模型多态关联 Trait
        └── AttachmentUploadTrait.php  # 上传 Trait
```

---

## License

MIT
