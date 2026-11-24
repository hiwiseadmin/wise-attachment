<?php
declare(strict_types=1);

namespace wise\attachment\RequestState;

use wise\support\contracts\RequestStateResetterInterface;

/**
 * wise-attachment 请求级状态重置器
 *
 * 本包在常驻运行时下的「跨请求残留」风险点及处理：
 *   1. ImageManager::$processorCache —— 图片处理器实例进程内复用。能力市场启停后
 *      ExtPoint 活跃状态被重置，但旧实例仍在缓存里挡住新注册，故每请求清空；
 *   2. StatsCollector —— 协程运行时已按协程上下文隔离（见 getInstance()），
 *      非协程常驻运行时共用进程级单例，此处归零；
 *   3. Image facade 配置 / RemoteFetcher DNS 缓存 / Imagick 资源限制 —— 属 worker 级
 *      共享资产（配置 / 有界只读缓存 / 一次性副作用），**不重置**。
 *
 * 注册入口：{@see \wise\attachment\AttachmentServiceProvider::boot()}
 *
 * @package wise\attachment\RequestState
 */
class AttachmentStateResetter implements RequestStateResetterInterface
{
    /**
     * {@inheritDoc}
     */
    public function resetRequestState(): void
    {
        if (class_exists(\wise\image\ImageManager::class)) {
            \wise\image\ImageManager::clearProcessorCache();
        }

        if (class_exists(\wise\image\StatsCollector::class)) {
            \wise\image\StatsCollector::resetProcessState();
        }
    }
}
