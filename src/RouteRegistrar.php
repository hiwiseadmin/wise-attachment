<?php

declare(strict_types=1);

namespace wise\attachment;

use think\facade\Route;
use wise\support\router\RouteRegistrar as BaseRouteRegistrar;

/**
 * WiseAttachment 路由注册器（配置驱动）
 *
 * 由 AttachmentServiceProvider::boot() 调用 RouteRegistrar::register() 注册。
 * 前缀 / 中间件 / 限流通过 config('wise-attachment.web') 配置。
 *
 * ⚠️ 注意：附件接口由宿主应用控制器 \app\api\controller\Attachment 处理，
 * 本包仅注册路由。若宿主未提供该控制器，可自行将 web.enable 设为 false 或替换路由。
 *
 * @package wise\attachment
 */
class RouteRegistrar extends BaseRouteRegistrar
{
    protected string $configKey = 'wise-attachment';

    protected function define(array $config): void
    {
        $this->group('attachment', function () {
            // 附件上传
            Route::post('upload', '\app\api\controller\Attachment@upload');

            // 附件列表
            Route::get('list', '\app\api\controller\Attachment@getList');

            // 附件详情
            Route::get('info', '\app\api\controller\Attachment@info');

            // 附件下载
            Route::get('download', '\app\api\controller\Attachment@download');

            // 附件删除（需认证）
            Route::delete('delete', '\app\api\controller\Attachment@delete');
        });
    }
}
