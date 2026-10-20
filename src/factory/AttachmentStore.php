<?php
declare(strict_types=1);

/**
 * 附件存储驱动工厂
 *
 * 根据配置文件动态创建并缓存存储驱动实例。
 * 通过容器单例绑定，全局统一入口。
 *
 * 使用:
 * $store = app()->make(\wise\attachment\factory\AttachmentStore::class);
 * $result = $store->driver('qiniu')->upload($localPath, $objectName);
 * $result = $store->local()->upload($localPath, $objectName);
 *
 * 扩展存储驱动（第三方包无需依赖 wise-attachment 的接口）：
 * <pre>
 *   class MyDriver implements \wise\support\contracts\StorageDriverInterface { ... }
 *
 *   ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'my_driver', MyDriver::class);
 *   // 之后即可 $store->driver('my_driver')
 * </pre>
 * 解析顺序：ExtPoints::EXTERNAL_CONNECTOR（连接器）注册项 → 包内置驱动映射。
 *
 * 面向契约：
 *   本工厂只依赖框架级契约 StorageDriverInterface，而非本包的 DriverInterface 别名。
 *   否则只实现 StorageDriverInterface 的第三方驱动会被 instanceof 校验挡在门外，
 *   契约下沉就失去意义。5 个内置驱动同时实现两者，故既有调用方行为不变。
 */
namespace wise\attachment\factory;

use think\facade\Config;
use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\support\contracts\StorageDriverInterface;

class AttachmentStore
{
    /**
     * 驱动实例缓存池
     *
     * @var array<string, StorageDriverInterface>
     */
    protected $drivers = [];

    /**
     * 获取指定或默认存储驱动实例
     *
     * @param string|null $driverName 驱动名称，null 则使用默认驱动
     * @return StorageDriverInterface
     * @throws \InvalidArgumentException 配置不存在或类不存在时
     */
    public function driver(?string $driverName = null): StorageDriverInterface
    {
        $driverName = $driverName ?: Config::get('wise-attachment.default_driver', 'local');
        return $this->resolve($driverName);
    }

    /**
     * 获取本地存储驱动
     *
     * @return StorageDriverInterface
     */
    public function local(): StorageDriverInterface
    {
        return $this->resolve('local');
    }

    /**
     * 获取 FTP 存储驱动
     *
     * @return StorageDriverInterface
     */
    public function ftp(): StorageDriverInterface
    {
        return $this->resolve('ftp');
    }

    /**
     * 获取七牛云存储驱动
     *
     * @return StorageDriverInterface
     */
    public function qiniu(): StorageDriverInterface
    {
        return $this->resolve('qiniu');
    }

    /**
     * 获取阿里云 OSS 存储驱动
     *
     * @return StorageDriverInterface
     */
    public function alioss(): StorageDriverInterface
    {
        return $this->resolve('alioss');
    }

    /**
     * 获取腾讯云 COS 存储驱动
     *
     * @return StorageDriverInterface
     */
    public function cos(): StorageDriverInterface
    {
        return $this->resolve('cos');
    }

    /**
     * 解析并创建驱动实例（带缓存）
     *
     * @param string $driverName 驱动名称
     * @return StorageDriverInterface
     * @throws \InvalidArgumentException 配置缺失或类不存在时
     */
    protected function resolve(string $driverName): StorageDriverInterface
    {
        if (isset($this->drivers[$driverName])) {
            return $this->drivers[$driverName];
        }

        $config = Config::get('wise-attachment.drivers.' . $driverName, []);

        // 优先从连接器点发现驱动：任何包只需
        //   ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, $name, $class)
        // 即可被本工厂解析，无需修改本包的硬编码映射。
        // 扩展点驱动的配置是可选的（可自带配置来源）；内置驱动则必须有配置。
        $concrete = ExtPoint::get(ExtPoints::EXTERNAL_CONNECTOR, $driverName);

        if ($concrete === null || $concrete === '') {
            if (empty($config)) {
                throw new \InvalidArgumentException("附件驱动 [{$driverName}] 的配置不存在");
            }

            $concrete = $this->getDriverClass($driverName);

            if ($concrete === '') {
                throw new \InvalidArgumentException("不支持的附件驱动类型 [{$driverName}]");
            }
        }

        $driver = $this->makeDriver($concrete, $config);

        $this->drivers[$driverName] = $driver;
        return $driver;
    }

    /**
     * 由类名或工厂闭包创建驱动实例
     *
     * @param string|callable $concrete 驱动类名，或工厂闭包 fn(array $config): StorageDriverInterface
     * @param array           $config   驱动配置
     * @return StorageDriverInterface
     * @throws \InvalidArgumentException 类不存在时
     * @throws \RuntimeException         未实现契约时
     */
    protected function makeDriver(string|callable $concrete, array $config): StorageDriverInterface
    {
        if (is_string($concrete)) {
            if (!class_exists($concrete)) {
                throw new \InvalidArgumentException("附件驱动类 [{$concrete}] 不存在");
            }
            $driver = new $concrete($config);
        } else {
            $driver = call_user_func($concrete, $config);
        }

        if (!$driver instanceof StorageDriverInterface) {
            throw new \RuntimeException(
                '驱动 [' . (is_string($concrete) ? $concrete : 'Closure') . '] 必须实现 StorageDriverInterface 接口'
            );
        }

        return $driver;
    }

    /**
     * 根据驱动名获取包内置驱动的类
     *
     * 仅覆盖本包自带的 5 种驱动；第三方驱动走 ExtPoints::EXTERNAL_CONNECTOR 注册项，
     * 在 resolve() 中优先于本方法解析。
     *
     * @param string $driverName 驱动名称
     * @return string 完整类名，找不到返回空字符串
     */
    protected function getDriverClass(string $driverName): string
    {
        $map = [
            'local'  => \wise\attachment\driver\Local::class,
            'ftp'    => \wise\attachment\driver\Ftp::class,
            'qiniu'  => \wise\attachment\driver\Qiniu::class,
            'alioss' => \wise\attachment\driver\Alioss::class,
            'cos'    => \wise\attachment\driver\Cos::class,
        ];

        return $map[$driverName] ?? '';
    }

    /**
     * 清除所有缓存的驱动实例（用于测试或重启场景）
     *
     * @return void
     */
    public function flush(): void
    {
        $this->drivers = [];
    }
}
