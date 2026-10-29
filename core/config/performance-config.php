<?php
/**
 * TodoCMS Performance Configuration
 * 
 * Centralized performance optimization settings
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access denied.');
}

// ===== 查询缓存配置 =====

/**
 * 启用查询结果缓存
 * 注意：CMS_Model类已经实现了内存级缓存
 */
if (!defined('TODO_ENABLE_QUERY_CACHE')) {
    define('TODO_ENABLE_QUERY_CACHE', true);
}

/**
 * 查询缓存TTL（秒）
 * 仅用于持久化缓存（Redis/Memcached）
 */
if (!defined('TODO_QUERY_CACHE_TTL')) {
    define('TODO_QUERY_CACHE_TTL', 3600); // 1小时
}

/**
 * 启用查询批处理器
 */
if (!defined('TODO_ENABLE_QUERY_BATCH')) {
    define('TODO_ENABLE_QUERY_BATCH', true);
}

// ===== 延迟加载配置 =====

/**
 * 启用管理器延迟加载
 * 当前bootstrap.php已经加载所有管理器
 * 未来可以实现按需加载
 */
if (!defined('TODO_LAZY_LOAD_MANAGERS')) {
    define('TODO_LAZY_LOAD_MANAGERS', false); // 暂时禁用，需要重构bootstrap.php
}

// ===== OPcache检查 =====

/**
 * 检查OPcache状态并记录警告
 */
if (function_exists('opcache_get_status')) {
    $opcache_status = @opcache_get_status();

    if (!$opcache_status || !isset($opcache_status['opcache_enabled']) || !$opcache_status['opcache_enabled']) {
        if (defined('TODO_DEBUG') && TODO_DEBUG) {
            error_log('TODO PERF WARNING: OPcache is not enabled. Enable it in php.ini for better performance:');
            error_log('  opcache.enable=1');
            error_log('  opcache.memory_consumption=128');
            error_log('  opcache.max_accelerated_files=10000');
            error_log('  opcache.revalidate_freq=2');
        }
    }
} else {
    if (defined('TODO_DEBUG') && TODO_DEBUG) {
        error_log('TODO PERF WARNING: OPcache extension is not installed. Install it for significant performance improvements.');
    }
}

// ===== 性能监控配置 =====

/**
 * 加载性能监控类
 */
if (TODO_ENABLE_PERF_MONITOR && class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::init(true);
}

/**
 * 初始化批处理器
 */
if (TODO_ENABLE_QUERY_BATCH && class_exists('Todo_Query_Batch_Processor')) {
    Todo_Query_Batch_Processor::enable();
}

/**
 * 配置查询结果缓存
 */
if (TODO_ENABLE_QUERY_CACHE && class_exists('Todo_Query_Result_Cache')) {
    Todo_Query_Result_Cache::enable();
    Todo_Query_Result_Cache::configure([
        'default_ttl' => TODO_QUERY_CACHE_TTL,
        'cacheable_patterns' => [
            '/SELECT.*FROM.*post_custom_fields.*WHERE.*post_id/i',
            '/SELECT.*FROM.*term_relationships.*WHERE.*object_id/i',
            '/SELECT.*FROM.*users.*WHERE.*ID/i',
            '/SELECT.*FROM.*system_config/i',
            '/SELECT.*FROM.*models.*WHERE.*model_key/i',
            '/SELECT.*FROM.*menus.*WHERE.*location/i',
            '/SELECT.*FROM.*menu_items.*WHERE.*menu_id/i',
            '/SELECT.*FROM.*posts.*WHERE.*ID\s*=\s*\?/i'
        ]
    ]);
}

// ===== 数据库优化配置 =====

/**
 * 慢查询阈值（秒）
 */
if (!defined('TODO_SLOW_QUERY_THRESHOLD')) {
    define('TODO_SLOW_QUERY_THRESHOLD', 0.5); // 500ms
}

/**
 * 启用查询日志（仅在TODO_DEBUG模式）
 */
if (!defined('TODO_LOG_QUERIES')) {
    define('TODO_LOG_QUERIES', defined('TODO_DEBUG') && TODO_DEBUG);
}
