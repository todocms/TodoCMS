<?php
/**
 * 数据库查询代理层
 * 
 * 统一管理所有数据库查询，提供缓存、去重、批量优化等功能
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 数据库查询代理类
 */
class Todo_DB_Query_Proxy {
    
    /**
     * 查询统计
     * @var array
     */
    private static $stats = [
        'total_queries' => 0,
        'cached_queries' => 0,
        'merged_queries' => 0,
        'duplicate_queries' => 0,
        'total_time' => 0
    ];
    
    /**
     * 查询缓存（请求级别）
     * @var array
     */
    private static $query_cache = [];
    
    /**
     * 重复查询检测
     * @var array [normalized_query => count]
     */
    private static $query_patterns = [];
    
    /**
     * 是否启用缓存
     * @var bool
     */
    private static $cache_enabled = true;
    
    /**
     * 是否启用查询合并
     * @var bool
     */
    private static $merge_enabled = true;
    
    /**
     * 是否启用去重
     * @var bool
     */
    private static $dedupe_enabled = true;
    
    /**
     * 初始化
     */
    public static function init() {
        // 注册关闭时刷新查询合并器
        register_shutdown_function([__CLASS__, 'flush_merger']);
    }
    
    /**
     * 执行查询（代理方法）
     * 
     * @param string $query SQL查询
     * @param array $params 参数
     * @param string $method 方法名（get_results, get_row, get_var等）
     * @return mixed
     */
    public static function query($query, $params = [], $method = 'get_results') {
        $start_time = microtime(true);
        self::$stats['total_queries']++;
        
        // 规范化查询
        $normalized = self::normalize_query($query, $params);
        $cache_key = md5($normalized);
        
        // 统计查询模式
        if (!isset(self::$query_patterns[$normalized])) {
            self::$query_patterns[$normalized] = 0;
        }
        self::$query_patterns[$normalized]++;
        
        // 检查缓存
        if (self::$cache_enabled && isset(self::$query_cache[$cache_key])) {
            self::$stats['cached_queries']++;
            $result = self::$query_cache[$cache_key];
            self::$stats['total_time'] += (microtime(true) - $start_time);
            return $result;
        }
        
        // 检查重复查询（相同查询在短时间内执行多次）
        if (self::$dedupe_enabled && self::$query_patterns[$normalized] > 1) {
            // 如果查询合并器可用，使用它
            if (self::$merge_enabled && class_exists('Todo_Query_Merger')) {
                $result = Todo_Query_Merger::register($query, $params, $cache_key);
                
                // 如果是占位符，获取实际值
                if ($result instanceof Todo_Query_Placeholder) {
                    $result = $result->get_value();
                }
                
                if ($result !== null) {
                    self::$stats['merged_queries']++;
                    self::$query_cache[$cache_key] = $result;
                    self::$stats['total_time'] += (microtime(true) - $start_time);
                    return $result;
                }
            }
            
            self::$stats['duplicate_queries']++;
        }
        
        // 执行实际查询
        global $tododb;
        $result = call_user_func([$tododb, $method], $query, $params);
        
        // 缓存结果
        if (self::$cache_enabled) {
            self::$query_cache[$cache_key] = $result;
        }
        
        $execution_time = microtime(true) - $start_time;
        self::$stats['total_time'] += $execution_time;
        
        return $result;
    }
    
    /**
     * 获取查询结果（get_results的代理）
     * 
     * @param string $query SQL查询
     * @param array $params 参数
     * @return array
     */
    public static function get_results($query, $params = []) {
        return self::query($query, $params, 'get_results');
    }
    
    /**
     * 获取单行（get_row的代理）
     * 
     * @param string $query SQL查询
     * @param array $params 参数
     * @return object|array|null
     */
    public static function get_row($query, $params = []) {
        return self::query($query, $params, 'get_row');
    }
    
    /**
     * 获取单个值（get_var的代理）
     * 
     * @param string $query SQL查询
     * @param array $params 参数
     * @return mixed
     */
    public static function get_var($query, $params = []) {
        return self::query($query, $params, 'get_var');
    }
    
    /**
     * 规范化查询
     * 
     * @param string $query SQL查询
     * @param array $params 参数
     * @return string
     */
    private static function normalize_query($query, $params = []) {
        $normalized = $query;
        
        // 替换参数值为占位符
        foreach ($params as $param) {
            if (is_numeric($param)) {
                $normalized = preg_replace('/\?/', '?', $normalized, 1);
            } else {
                $normalized = preg_replace('/\?/', "'?'", $normalized, 1);
            }
        }
        
        // 移除多余空格
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        $normalized = trim($normalized);
        
        return $normalized;
    }
    
    /**
     * 刷新查询合并器
     */
    public static function flush_merger() {
        if (class_exists('Todo_Query_Merger')) {
            Todo_Query_Merger::flush(true);
        }
    }
    
    /**
     * 清除缓存
     */
    public static function clear_cache() {
        self::$query_cache = [];
        self::$query_patterns = [];
    }
    
    /**
     * 启用/禁用缓存
     * 
     * @param bool $enabled
     */
    public static function set_cache_enabled($enabled) {
        self::$cache_enabled = (bool)$enabled;
    }
    
    /**
     * 启用/禁用查询合并
     * 
     * @param bool $enabled
     */
    public static function set_merge_enabled($enabled) {
        self::$merge_enabled = (bool)$enabled;
        if (class_exists('Todo_Query_Merger')) {
            if ($enabled) {
                Todo_Query_Merger::enable();
            } else {
                Todo_Query_Merger::disable();
            }
        }
    }
    
    /**
     * 启用/禁用去重
     * 
     * @param bool $enabled
     */
    public static function set_dedupe_enabled($enabled) {
        self::$dedupe_enabled = (bool)$enabled;
    }
    
    /**
     * 获取统计信息
     * 
     * @return array
     */
    public static function get_stats() {
        $stats = self::$stats;
        $stats['cache_hit_rate'] = $stats['total_queries'] > 0 
            ? round($stats['cached_queries'] / $stats['total_queries'] * 100, 2) 
            : 0;
        $stats['average_time'] = $stats['total_queries'] > 0 
            ? round($stats['total_time'] / $stats['total_queries'] * 1000, 2) 
            : 0;
        $stats['unique_patterns'] = count(self::$query_patterns);
        $stats['top_patterns'] = self::get_top_patterns(5);
        
        return $stats;
    }
    
    /**
     * 获取最频繁的查询模式
     * 
     * @param int $limit 数量限制
     * @return array
     */
    private static function get_top_patterns($limit = 5) {
        arsort(self::$query_patterns);
        return array_slice(self::$query_patterns, 0, $limit, true);
    }
    
    /**
     * 重置统计
     */
    public static function reset_stats() {
        self::$stats = [
            'total_queries' => 0,
            'cached_queries' => 0,
            'merged_queries' => 0,
            'duplicate_queries' => 0,
            'total_time' => 0
        ];
        self::$query_patterns = [];
    }
}

