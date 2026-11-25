<?php
/**
 * WiseAttachment 路由（已废弃）
 *
 * ⚠️ 本文件已不再注册任何路由，仅作历史参考。
 *
 * 路由已迁移到 \wise\attachment\RouteRegistrar（配置驱动）：
 *   1. 由 AttachmentServiceProvider::boot() 自动调用 RouteRegistrar::register()
 *   2. 前缀 / 中间件 / 限流通过 config('wise-attachment.web') 配置
 *   3. 无需在宿主手动导入本文件
 *
 * 请勿手动导入本文件，避免路由双份注册。
 */
