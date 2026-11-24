<?php
declare(strict_types=1);

/**
 * 附件存储驱动接口
 *
 * 契约链：
 *   wise\attachment\driver\DriverInterface
 *     extends wise\support\connector\StorageConnectorInterface
 *       extends wise\support\connector\ConnectorInterface       （连接器通用契约）
 *             , wise\support\contracts\StorageDriverInterface  （对象存储契约）
 *
 * 效果：
 *   - 5 个内置驱动与 AttachmentStore 的类型声明无需改动（本接口仍满足
 *     `instanceof StorageDriverInterface`，向后兼容）；
 *   - 内置驱动可同时注册到 ExtPoints::EXTERNAL_CONNECTOR，被 ConnectorManager
 *     统一发现 / 健康检查 / 配置，实现「存储能力接入连接器体系」。
 *
 * 实现方选择：
 *   - 只想提供存储能力、不接入连接器体系：继续只实现
 *     wise\support\contracts\StorageDriverInterface（AttachmentStore 仍兼容）；
 *   - 要接入连接器体系：实现本接口，并在类中 use StorageConnectorTrait 复用
 *     ConnectorInterface 的默认实现。
 */
namespace wise\attachment\driver;

use wise\support\connector\StorageConnectorInterface;

interface DriverInterface extends StorageConnectorInterface
{
}
