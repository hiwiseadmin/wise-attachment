<?php
declare(strict_types=1);

/**
 * PHPUnit 测试引导文件
 *
 * 初始化 ThinkPHP 6 框架环境，使测试可以访问：
 * - 容器 (app())
 * - 配置 (Config::get())
 * - 数据库 (Db)
 * - 门面 (Facade)
 */

$rootPath = dirname(__DIR__, 4);
$autoloadFile = $rootPath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

if (!file_exists($autoloadFile)) {
    throw new \RuntimeException("找不到 autoload.php，请确保根项目已执行 composer install。路径: {$autoloadFile}");
}

require $autoloadFile;

$packageSrc = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;
$autoloader = new \Composer\Autoload\ClassLoader();
$autoloader->addPsr4('wise\\attachment\\', $packageSrc . 'attachment' . DIRECTORY_SEPARATOR);
$autoloader->addPsr4('wise\\image\\', $packageSrc . 'image' . DIRECTORY_SEPARATOR);
$autoloader->register();

$app = new \think\App($rootPath);

\think\facade\App::setInstance($app);
$app->bind('app', $app);

$app->initialize();

$app->http = new \think\Http($app);

$app->bind('wise.attachment.store', \wise\attachment\factory\AttachmentStore::class);
$app->bind(\wise\attachment\factory\AttachmentStore::class, function () {
    return new \wise\attachment\factory\AttachmentStore();
});
$app->bind('wise.attachment.image', \wise\attachment\tools\ImageProcessor::class);
$app->bind(\wise\attachment\tools\ImageProcessor::class, function () {
    return new \wise\attachment\tools\ImageProcessor();
});

$packageConfigFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'wise-attachment.php';
if (file_exists($packageConfigFile)) {
    $wiseConfig = require $packageConfigFile;
    \think\facade\Config::set($wiseConfig, 'wise-attachment');
}

\think\facade\Config::set([
    'default'    => 'sqlite',
    'connections' => [
        'sqlite' => [
            'type'            => 'sqlite',
            'database'        => ':memory:',
            'prefix'          => 'ws_',
            'fields_strict'   => true,
            'fields_cache'    => false,
            'trigger_sql'     => false,
        ],
    ],
], 'database');

\think\facade\Config::set([
    'default_timezone' => 'Asia/Shanghai',
    'default_lang'     => 'zh-cn',
], 'app');

createTestTables();

/**
 * 创建测试用数据库表
 */
function createTestTables(): void
{
    $schema = \think\facade\Db::connect('sqlite');

    $schema->execute("
        CREATE TABLE IF NOT EXISTS ws_attachment (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            storage VARCHAR(20) NOT NULL DEFAULT 'local',
            object_name VARCHAR(500) NOT NULL DEFAULT '',
            url VARCHAR(1000) NOT NULL DEFAULT '',
            size INTEGER NOT NULL DEFAULT 0,
            mime_type VARCHAR(100) NOT NULL DEFAULT '',
            image_width INTEGER NOT NULL DEFAULT 0,
            image_height INTEGER NOT NULL DEFAULT 0,
            is_image INTEGER NOT NULL DEFAULT 0,
            content_hash VARCHAR(64) NOT NULL DEFAULT '',
            is_duplicate INTEGER NOT NULL DEFAULT 0,
            original_attachment_id INTEGER NOT NULL DEFAULT 0,
            category_id INTEGER NOT NULL DEFAULT 0,
            delete_time DATETIME NULL DEFAULT NULL,
            create_time INTEGER NOT NULL DEFAULT 0,
            update_time INTEGER NOT NULL DEFAULT 0
        )
    ");

    $schema->execute("
        CREATE TABLE IF NOT EXISTS ws_attachment_relation (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            attachment_id INTEGER NOT NULL DEFAULT 0,
            relation_type VARCHAR(255) NOT NULL DEFAULT '',
            relation_id INTEGER NOT NULL DEFAULT 0
        )
    ");

    $schema->execute("
        CREATE TABLE IF NOT EXISTS ws_attachment_version (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            attachment_id INTEGER NOT NULL DEFAULT 0,
            version_number INTEGER NOT NULL DEFAULT 1,
            object_name VARCHAR(500) NOT NULL DEFAULT '',
            url VARCHAR(1000) NOT NULL DEFAULT '',
            size INTEGER NOT NULL DEFAULT 0,
            mime_type VARCHAR(128) NOT NULL DEFAULT '',
            content_hash VARCHAR(64) NOT NULL DEFAULT '',
            operator_id INTEGER NOT NULL DEFAULT 0,
            note TEXT NULL,
            create_time INTEGER NOT NULL DEFAULT 0
        )
    ");

    $schema->execute("
        CREATE TABLE IF NOT EXISTS ws_attachment_category (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(100) NOT NULL DEFAULT '',
            parent_id INTEGER NOT NULL DEFAULT 0,
            icon VARCHAR(50) NOT NULL DEFAULT '',
            description VARCHAR(500) NOT NULL DEFAULT '',
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_system INTEGER NOT NULL DEFAULT 0,
            create_time INTEGER NOT NULL DEFAULT 0,
            update_time INTEGER NOT NULL DEFAULT 0
        )
    ");

    $schema->execute("
        CREATE TABLE IF NOT EXISTS ws_attachment_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            attachment_id INTEGER NOT NULL DEFAULT 0,
            action VARCHAR(32) NOT NULL DEFAULT '',
            operator_id INTEGER NOT NULL DEFAULT 0,
            operator_type VARCHAR(100) NOT NULL DEFAULT '',
            operator_name VARCHAR(100) NOT NULL DEFAULT '',
            ip VARCHAR(45) NOT NULL DEFAULT '',
            detail TEXT NULL,
            create_time INTEGER NOT NULL DEFAULT 0
        )
    ");

    $schema->execute("CREATE INDEX IF NOT EXISTS idx_att_storage ON ws_attachment(storage)");
    $schema->execute("CREATE INDEX IF NOT EXISTS idx_att_mime ON ws_attachment(mime_type)");
    $schema->execute("CREATE INDEX IF NOT EXISTS idx_att_delete_time ON ws_attachment(delete_time)");
    $schema->execute("CREATE INDEX IF NOT EXISTS idx_att_content_hash ON ws_attachment(content_hash)");
    $schema->execute("CREATE INDEX IF NOT EXISTS idx_att_original_id ON ws_attachment(original_attachment_id)");
    $schema->execute("CREATE INDEX IF NOT EXISTS idx_rel_att_id ON ws_attachment_relation(attachment_id)");
    $schema->execute("CREATE INDEX IF NOT EXISTS idx_rel_type_id ON ws_attachment_relation(relation_type, relation_id)");
}
