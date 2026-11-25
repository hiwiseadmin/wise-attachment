<?php

declare(strict_types=1);

namespace wise\image\facade;

use wise\image\ImageManager;

/**
 * Image Facade — 静态代理 ImageManager
 *
 * 提供简洁的静态入口，无需每次 new ImageManager。
 *
 * 用法示例：
 *   Image::setConfig(['driver' => 'gd']);
 *   Image::open('/path/to/img.jpg')
 *       ->resize(100, 100)
 *       ->save('/path/to/output.jpg');
 *
 *   Image::make(800, 600)
 *       ->blur(5)
 *       ->save('/path/to/blurred.jpg');
 */
class Image
{
    /**
     * 全局配置
     *
     * 仅保存「配置」这类与请求无关的数据；**不再缓存 ImageManager 实例**。
     *
     * 常驻运行时（Swoole / Workerman）下进程不重启，静态单例会把
     * sourcePath / operations / driver（GD、Imagick 资源）/ Request 跨请求复用，
     * 协程在 I/O 点交错时互相覆盖，导致「A 的图片被 B 的操作链处理」，
     * 且安全判定（签名 / 防盗链 / IP 黑名单）会读到上一个请求的上下文。
     *
     * @var array<string, mixed>
     */
    private static array $config = [];

    /**
     * 设置全局配置
     *
     * @param array<string, mixed> $config 完整配置数组
     */
    public static function setConfig(array $config): void
    {
        self::$config = $config;
    }

    /**
     * 打开已有图片
     *
     * @param string $path 图片文件路径
     * @return ImageManager
     */
    public static function open(string $path): ImageManager
    {
        return self::manager()->open($path);
    }

    /**
     * 创建空白画布
     *
     * @param int $width  画布宽度
     * @param int $height 画布高度
     * @return ImageManager
     */
    public static function make(int $width, int $height): ImageManager
    {
        return self::manager()->make($width, $height);
    }

    /**
     * 打开远程图片（先下载到本地再处理）
     *
     * 需在配置中开启 `remote.enabled = true`。
     *
     * @param string $url      远程图片 URL
     * @param bool   $useCache 是否复用本地已下载副本
     * @return ImageManager
     */
    public static function openRemote(string $url, bool $useCache = true): ImageManager
    {
        return self::manager()->openRemote($url, $useCache);
    }

    /**
     * 下载远程图片到本地并返回本地文件路径
     *
     * @param string $url      远程图片 URL
     * @param bool   $useCache 是否复用本地已下载副本
     * @return string
     */
    public static function download(string $url, bool $useCache = true): string
    {
        return self::manager()->download($url, $useCache);
    }

    /**
     * 创建 ImageManager（每次全新实例）
     *
     * 不做实例缓存：实例自带源图路径、操作链、驱动资源与注入的 Request，
     * 只有「一实例一次处理」才能保证常驻运行时的请求级隔离。
     *
     * @return ImageManager
     */
    private static function manager(): ImageManager
    {
        return new ImageManager(self::$config);
    }

    /**
     * 读取当前全局配置（供测试与诊断）
     *
     * 注意：`self::$config` 是 **worker 级**配置（由 setConfig() 在 boot 阶段写入），
     * 不属于请求级状态，因此**不做**请求级重置 —— 否则每个请求都会丢配置。
     * 需要按请求/租户改配置时，请直接 `new ImageManager($config)` 或 `Image::setConfig()`
     * 后立即使用，不要依赖静态配置承载请求数据。
     *
     * @return array<string, mixed>
     */
    public static function getConfig(): array
    {
        return self::$config;
    }

    /**
     * 魔术方法 — 将未定义的静态调用委托给 ImageManager 实例
     *
     * 支持如 Image::parseUrlParams(...), Image::style(...) 等调用。
     *
     * @param string                $method    方法名
     * @param array<int, mixed>     $arguments 方法参数
     * @return mixed
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return self::manager()->{$method}(...$arguments);
    }
}
