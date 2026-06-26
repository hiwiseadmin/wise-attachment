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
 */
namespace wise\attachment\factory;

use think\facade\Config;
use wise\attachment\driver\DriverInterface;

class AttachmentStore
{
    /**
     * 驱动实例缓存池
     *
     * @var array<string, DriverInterface>
     */
    protected $drivers = [];

    /**
     * 获取指定或默认存储驱动实例
     *
     * @param string|null $driverName 驱动名称，null 则使用默认驱动
     * @return DriverInterface
     * @throws \InvalidArgumentException 配置不存在或类不存在时
     */
    public function driver(?string $driverName = null): DriverInterface
    {
        $driverName = $driverName ?: Config::get('wise-attachment.default_driver', 'local');
        return $this->resolve($driverName);
    }

    /**
     * 获取本地存储驱动
     *
     * @return DriverInterface
     */
    public function local(): DriverInterface
    {
        return $this->resolve('local');
    }

    /**
     * 获取 FTP 存储驱动
     *
     * @return DriverInterface
     */
    public function ftp(): DriverInterface
    {
        return $this->resolve('ftp');
    }

    /**
     * 获取七牛云存储驱动
     *
     * @return DriverInterface
     */
    public function qiniu(): DriverInterface
    {
        return $this->resolve('qiniu');
    }

    /**
     * 获取阿里云 OSS 存储驱动
     *
     * @return DriverInterface
     */
    public function alioss(): DriverInterface
    {
        return $this->resolve('alioss');
    }

    /**
     * 获取腾讯云 COS 存储驱动
     *
     * @return DriverInterface
     */
    public function cos(): DriverInterface
    {
        return $this->resolve('cos');
    }

    /**
     * 解析并创建驱动实例（带缓存）
     *
     * @param string $driverName 驱动名称
     * @return DriverInterface
     * @throws \InvalidArgumentException 配置缺失或类不存在时
     */
    protected function resolve(string $driverName): DriverInterface
    {
        if (isset($this->drivers[$driverName])) {
            return $this->drivers[$driverName];
        }

        $config = Config::get('wise-attachment.drivers.' . $driverName, []);

        if (empty($config)) {
            throw new \InvalidArgumentException("附件驱动 [{$driverName}] 的配置不存在");
        }

        $driverClass = $this->getDriverClass($driverName);

        if ($driverClass === '' || !class_exists($driverClass)) {
            throw new \InvalidArgumentException("附件驱动类 [{$driverClass}] 不存在");
        }

        /** @var DriverInterface $driver */
        $driver = new $driverClass($config);

        if (!$driver instanceof DriverInterface) {
            throw new \RuntimeException("驱动 [{$driverClass}] 必须实现 DriverInterface 接口");
        }

        $this->drivers[$driverName] = $driver;
        return $driver;
    }

    /**
     * 根据驱动名获取对应的驱动类
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
