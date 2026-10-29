<?php
/**
 * 性能优化器主类
 * 
 * 统一管理和协调所有性能优化功能
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 性能优化器类
 */
class Todo_Performance_Optimizer {
    
    /**
     * 是否已初始化
     * @var bool
     */
    private static $initialized = false;
    
    /**
     * 优化配置
     * @var array
     */
    private static $config = [];
    
    /**
     * 初始化性能优化器
     * 
     * @param array $config 配置选项
     */
    public static function init($config = []) {
        if (self::$initialized) {
            return;
        }
        
        // 默认配置
        $defaults = [
            'query_cache' => true,
            'query_merge' => true,
            'query_dedupe' => true,
            'batch_preload' => true,
            'template_optimization' => true,
            'theme_cache' => true,
            'auto_flush' => true, // 自动在请求结束时刷新
            'debug_mode' => false
        ];
        
        self::$config = array_merge($defaults, $config);
        
        // 初始化查询代理层
        if (class_exists('Todo_DB_Query_Proxy')) {
            Todo_DB_Query_Proxy::init();
            Todo_DB_Query_Proxy::set_cache_enabled(self::$config['query_cache']);
            Todo_DB_Query_Proxy::set_merge_enabled(self::$config['query_merge']);
            Todo_DB_Query_Proxy::set_dedupe_enabled(self::$config['query_dedupe']);
        }
        
        // 初始化查询合并器
        if (self::$config['query_merge'] && class_exists('Todo_Query_Merger')) {
            Todo_Query_Merger::enable();
        }
        
        // 初始化模板优化器
        if (self::$config['template_optimization'] && class_exists('Todo_Template_Optimizer')) {
            Todo_Template_Optimizer::enable_collecting();
        }
        
        // 初始化批量预加载器
        if (self::$config['batch_preload'] && class_exists('Todo_Batch_Preloader')) {
            Todo_Batch_Preloader::enable_collecting();
        }
        
        // 注册关闭时刷新
        if (self::$config['auto_flush']) {
            register_shutdown_function([__CLASS__, 'flush_all']);
        }
        
        self::$initialized = true;
        
        if (self::$config['debug_mode']) {
            error_log('Todo_Performance_Optimizer: Initialized');
        }
    }
    
    /**
     * 刷新所有优化器
     */
    public static function flush_all() {
        // 刷新查询合并器
        if (class_exists('Todo_Query_Merger')) {
            Todo_Query_Merger::flush(true);
        }
        
        // 刷新模板优化器
        if (class_exists('Todo_Template_Optimizer')) {
            Todo_Template_Optimizer::disable_and_load();
        }
        
        // 刷新批量预加载器
        if (class_exists('Todo_Batch_Preloader')) {
            Todo_Batch_Preloader::disable_and_load();
        }
    }
    
    /**
     * 获取性能统计
     * 
     * @return array
     */
    public static function get_stats() {
        $stats = [
            'initialized' => self::$initialized,
            'config' => self::$config
        ];
        
        // 查询代理统计
        if (class_exists('Todo_DB_Query_Proxy')) {
            $stats['query_proxy'] = Todo_DB_Query_Proxy::get_stats();
        }
        
        // 查询合并器统计
        if (class_exists('Todo_Query_Merger')) {
            $stats['query_merger'] = Todo_Query_Merger::get_stats();
        }
        
        // 模板优化器统计
        if (class_exists('Todo_Template_Optimizer')) {
            $stats['template_optimizer'] = [
                'pending_post_ids' => count(Todo_Template_Optimizer::get_pending_post_ids()),
                'query_patterns' => Todo_Template_Optimizer::get_query_patterns()
            ];
        }
        
        return $stats;
    }
    
    /**
     * 生成性能报告
     * 
     * @return array
     */
    public static function generate_report() {
        $report = [
            'timestamp' => time(),
            'stats' => self::get_stats(),
            'recommendations' => []
        ];
        
        // 分析查询统计
        if (isset($report['stats']['query_proxy'])) {
            $query_stats = $report['stats']['query_proxy'];
            
            // 查询数量建议
            if ($query_stats['total_queries'] > 200) {
                $report['recommendations'][] = [
                    'priority' => 'high',
                    'category' => 'query_count',
                    'message' => "查询数量过多（{$query_stats['total_queries']}），建议使用更多批量加载和查询合并"
                ];
            }
            
            // 缓存命中率建议
            if ($query_stats['cache_hit_rate'] < 30 && $query_stats['total_queries'] > 50) {
                $report['recommendations'][] = [
                    'priority' => 'medium',
                    'category' => 'cache',
                    'message' => "缓存命中率较低（{$query_stats['cache_hit_rate']}%），建议优化查询模式"
                ];
            }
            
            // 重复查询建议
            if ($query_stats['duplicate_queries'] > 10) {
                $report['recommendations'][] = [
                    'priority' => 'high',
                    'category' => 'duplicate',
                    'message' => "检测到 {$query_stats['duplicate_queries']} 个重复查询，建议使用查询缓存"
                ];
            }
        }
        
        return $report;
    }
    
    /**
     * 清除所有缓存
     */
    public static function clear_all_cache() {
        if (class_exists('Todo_DB_Query_Proxy')) {
            Todo_DB_Query_Proxy::clear_cache();
        }
        
        if (class_exists('Todo_Query_Merger')) {
            Todo_Query_Merger::clear_cache();
        }
        
        if (class_exists('Todo_Post_ORM')) {
            Todo_Post_ORM::clear_cache();
        }
    }
    
    /**
     * 重置所有统计
     */
    public static function reset_stats() {
        if (class_exists('Todo_DB_Query_Proxy')) {
            Todo_DB_Query_Proxy::reset_stats();
        }
    }
}

