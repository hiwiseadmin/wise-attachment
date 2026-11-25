<?php
declare(strict_types=1);

/**
 * WiseAttachment 服务提供者
 *
 * 职责：
 * 1. 绑定 AttachmentStore 工厂到容器
 * 2. 注册控制台命令
 * 3. 注册路由
 * 4. 合并配置文件
 */
namespace wise\attachment;


use wise\support\traits\RegistersMigrations;
use wise\support\traits\ServiceTrait;
use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\support\context\RequestStateRegistry;

class AttachmentServiceProvider extends \think\Service
{

    use ServiceTrait;
    use RegistersMigrations;

    /**
     * 包根目录覆盖：本 Provider 位于 `src/attachment/`（比默认约定深一层），
     * `dirname(__FILE__, 2)` 会得到 `src/`，而迁移在包根 `database/migrations`。
     */
    protected function migrationPackageRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * 包根目录覆盖：配置同样在包根 `config/`，而非 `src/config/`。
     */
    protected function getPackageConfigFilePath(string $configName): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $configName . '.php';
    }

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

        RouteRegistrar::register();

        $this->registerStorageExtPoints();

        $this->registerRecycleModels();

        $this->registerRequestStateResetter();
    }

    /**
     * 注册请求级状态重置器
     *
     * 注册动作必须放在 boot（worker 级，只执行一次），
     * 重置内容见 {@see \wise\attachment\RequestState\AttachmentStateResetter}。
     */
    protected function registerRequestStateResetter(): void
    {
        if (!class_exists(RequestStateRegistry::class)) {
            return;
        }

        RequestStateRegistry::register(
            'wise-attachment',
            \wise\attachment\RequestState\AttachmentStateResetter::class,
            300
        );
    }

    /**
     * 注册回收站模型（包侧）
     *
     * 附件删除走软删（delete_time），纳入回收站后可还原；
     * 标题展示用 object_name（对象存储路径）。
     */
    protected function registerRecycleModels(): void
    {
        if (!class_exists(ExtPoint::class) || !class_exists(ExtPoints::class)) {
            return;
        }

        if (!class_exists(\wise\attachment\model\AttachmentModel::class)) {
            return;
        }

        ExtPoint::register(
            ExtPoints::RECYCLE_PROVIDER,
            'wise-attachment',
            static fn (): array => [
                \wise\attachment\model\AttachmentModel::class => [
                    'title'  => 'object_name',
                    'group'  => '附件',
                    'fields' => ['storage', 'mime_type', 'size'],
                ],
            ]
        );
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
