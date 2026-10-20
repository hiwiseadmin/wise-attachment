<?php
declare(strict_types=1);

/**
 * WiseAttachment 服务提供者
 *
 * 在 ThinkPHP 6 框架启动时自动注册（通过 composer.json 的 extra.think.services）。
 *
 * 职责：
 * 1. 绑定 AttachmentStore 工厂到容器
 * 2. 注册控制台命令
 * 3. 注册路由
 * 4. 发布配置文件到项目 config 目录
 */
namespace wise\attachment;


use wise\support\traits\RegistersMigrations;
use wise\support\traits\ServiceTrait;
use wise\support\ExtPoint;
use wise\support\ExtPoints;

class AttachmentServiceProvider extends \think\Service
{

    use ServiceTrait;
    use RegistersMigrations;

    /**
     * 注册服务
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfig('wise-attachment');

        $this->app->bind('wise.attachment.store', factory\AttachmentStore::class);
        $this->app->bind(factory\AttachmentStore::class, function () {
            return new factory\AttachmentStore();
        });

        $this->app->bind('wise.attachment.image', tools\ImageProcessor::class);
        $this->app->bind(tools\ImageProcessor::class, function () {
            return new tools\ImageProcessor();
        });

        $this->commands([
            command\Clean::class,
        ]);
    }

    /**
     * 启动服务
     *
     * @return void
     */
    public function boot(): void
    {
        $this->registerMigrations();
        // 注册 Web 路由（配置驱动，enable=false 时不注册；无条件注册以兼容 Swoole）
        RouteRegistrar::register();

        // 将内置存储驱动注册到连接器体系
        $this->registerStorageExtPoints();
    }

    /**
     * 将内置存储驱动注册到连接器体系
     *
     * 内置 5 种存储驱动：Local / Ftp / Qiniu / Alioss / Cos。
     * 存储能力统一由 connector 点承载（原 storage.driver 扩展点已废除）：
     * 驱动实现 StorageConnectorInterface（继承 StorageDriverInterface），
     * 注册到 ExtPoints::EXTERNAL_CONNECTOR（类型 ConnectorType::STORAGE），
     * 可经 ConnectorManager::storage($name) 强类型获取。
     */
    protected function registerStorageExtPoints(): void
    {
        $drivers = [
            'local'  => driver\Local::class,
            'ftp'    => driver\Ftp::class,
            'qiniu'  => driver\Qiniu::class,
            'alioss' => driver\Alioss::class,
            'cos'    => driver\Cos::class,
        ];

        foreach ($drivers as $name => $class) {
            if (class_exists($class)) {
                ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, $name, $class);
            }
        }
    }
}
