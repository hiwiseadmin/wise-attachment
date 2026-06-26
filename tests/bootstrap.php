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

// 加载根项目 autoloader
$rootPath = dirname(__DIR__, 4);
$autoloadFile = $rootPath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

if (!file_exists($autoloadFile)) {
    throw new \RuntimeException("找不到 autoload.php，请确保根项目已执行 composer install。路径: {$autoloadFile}");
}

require $autoloadFile;

// 手动注册 wise-attachment 包的命名空间（因包可能未通过 composer update 安装）
$packageSrc = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;
$autoloader = new \Composer\Autoload\ClassLoader();
$autoloader->addPsr4('wise\\attachment\\', $packageSrc);
$autoloader->register();

// 初始化 ThinkPHP App
$app = new \think\App($rootPath);

// 绑定 app 到容器
\think\facade\App::setInstance($app);
$app->bind('app', $app);

// 核心初始化（加载配置、环境变量等）
$app->initialize();

// 创建 HTTP 实例用于 HTTP 上下文
$app->http = new \think\Http($app);

// 注册 wise-attachment 服务到容器
$app->bind('wise.attachment.store', \wise\attachment\factory\AttachmentStore::class);
$app->bind(\wise\attachment\factory\AttachmentStore::class, function () {
    return new \wise\attachment\factory\AttachmentStore();
});
$app->bind('wise.attachment.image', \wise\attachment\tools\ImageProcessor::class);
$app->bind(\wise\attachment\tools\ImageProcessor::class, function () {
    return new \wise\attachment\tools\ImageProcessor();
});

// 加载 wise-attachment 配置
$packageConfigFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'wise-attachment.php';
if (file_exists($packageConfigFile)) {
    $wiseConfig = require $packageConfigFile;
    \think\facade\Config::set($wiseConfig, 'wise-attachment');
}

// 设置测试用数据库配置为 SQLite 内存数据库
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

// 设置测试用 app 配置
\think\facade\Config::set([
    'default_timezone' => 'Asia/Shanghai',
    'default_lang'     => 'zh-cn',
], 'app');

// 创建测试数据库表结构
createTestTables();

/**
 * 创建测试用数据库表
 */
function createTestTables(): void
{
    $schema = \think\facade\Db::connect('sqlite');

    // 附件表
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
            delete_time INTEGER NOT NULL DEFAULT 0,
            create_time INTEGER NOT NULL DEFAULT 0,
            update_time INTEGER NOT NULL DEFAULT 0
        )
    ");

    // 附件关联表
    $schema->execute("
        CREATE TABLE IF NOT EXISTS ws_attachment_relation (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            attachment_id INTEGER NOT NULL DEFAULT 0,
            relation_type VARCHAR(255) NOT NULL DEFAULT '',
            relation_id INTEGER NOT NULL DEFAULT 0
        )
    ");

    // 创建索引
    $schema->execute("CREATE INDEX IF NOT EXISTS idx_att_storage ON ws_attachment(storage)");
    $schema->execute("CREATE INDEX IF NOT EXISTS idx_att_mime ON ws_attachment(mime_type)");
    $schema->execute("CREATE INDEX IF NOT EXISTS idx_att_delete_time ON ws_attachment(delete_time)");
    $schema->execute("CREATE INDEX IF NOT EXISTS idx_rel_att_id ON ws_attachment_relation(attachment_id)");
    $schema->execute("CREATE INDEX IF NOT EXISTS idx_rel_type_id ON ws_attachment_relation(relation_type, relation_id)");
}
