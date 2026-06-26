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

use think\facade\Config;

class Service extends \think\Service
{
    /**
     * 注册服务
     *
     * @return void
     */
    public function register(): void
    {
        // 绑定 AttachmentStore 工厂到容器（单例）
        $this->app->bind('wise.attachment.store', factory\AttachmentStore::class);
        $this->app->bind(factory\AttachmentStore::class, function () {
            return new factory\AttachmentStore();
        });

        // 绑定 ImageProcessor 工具到容器（单例）
        $this->app->bind('wise.attachment.image', tools\ImageProcessor::class);
        $this->app->bind(tools\ImageProcessor::class, function () {
            return new tools\ImageProcessor();
        });

        // 注册视图路径：将插件的 view 目录加入模板引擎查找路径
        $viewPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR;
        if (is_dir($viewPath)) {
            // ThinkPHP 6 视图配置追加路径
            $viewConfig = Config::get('view', []);
            $viewPaths  = $viewConfig['view_path'] ?? '';
            // 将插件视图路径通过配置注入，使 view('wise_attachment@xxx') 可用
            // ThinkPHP 的 view() 函数支持 `命名空间@模板路径` 格式，前提是已在配置中注册
            $dispatched = $viewConfig['view_dispatch'] ?? [];
            $dispatched['wise_attachment'] = $viewPath;
            Config::set([
                'view_dispatch' => $dispatched,
            ], 'view');
        }
    }

    /**
     * 启动服务
     *
     * @return void
     */
    public function boot(): void
    {
        // 注册路由文件
        $routeFile = __DIR__ . DIRECTORY_SEPARATOR . 'route/route.php';
        if (file_exists($routeFile)) {
            $this->loadRoutesFrom($routeFile);
        }

        // 注册控制台命令（仅 CLI 模式）
        if ($this->app->runningInConsole()) {
            $this->commands([
                command\Clean::class,
            ]);
        }
    }
}
