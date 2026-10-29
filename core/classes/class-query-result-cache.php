<?php
/**
 * 查询结果持久化缓存
 * 
 * 将常用查询结果持久化到文件或Redis
 * 特别适用于首次加载时的性能优化
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 查询结果持久化缓存类
 */
class Todo_Query_Result_Cache {
    
    /**
     * 是否启用
     * @var bool
     */
    private static $enabled = true;
    
    /**
     * 缓存TTL（秒）
     * @var int
     */
    private static $default_ttl = 300; // 5分钟
    
    /**
     * 可缓存的查询模式
     * @var array
     */
    private static $cacheable_patterns = [
        '/SELECT.*FROM.*options.*WHERE.*autoload/i',
        '/SELECT.*FROM.*themes.*WHERE.*status/i',
        '/SELECT.*FROM.*posts.*WHERE.*post_status.*publish/i',
        '/SELECT.*FROM.*terms.*WHERE.*taxonomy/i',
    ];
    
    /**
     * 统计信息
     * @var array
     */
    private static $stats = [
        'hits' => 0,
        'misses' => 0,
        'saves' => 0,
        'invalidations' => 0
    ];
    
    /**
     * 启用缓存
     */
    public static function enable() {
        self::$enabled = true;
    }
    
    /**
     * 禁用缓存
     */
    public static function disable() {
        self::$enabled = false;
    }
    
    /**
     * 检查查询是否可以缓存
     * 
     * @param string $query SQL查询
     * @return bool
     */
    private static function is_cacheable($query) {
        if (!self::$enabled) {
            return false;
        }
        
        // 只缓存SELECT查询
        if (stripos(trim($query), 'SELECT') !== 0) {
            return false;
        }
        
        // 检查是否匹配可缓存模式
        foreach (self::$cacheable_patterns as $pattern) {
            if (preg_match($pattern, $query)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 获取缓存的查询结果
     * 
     * @param string $query SQL查询
     * @param array $params 参数
     * @return mixed 结果或null
     */
    public static function get($query, $params = [], $method = 'get_results', $output_type = 'OBJECT') {
        if (!self::is_cacheable($query)) {
            return null;
        }
        
        // 使用Todo_Cache_Manager进行持久化缓存
        if (class_exists('Todo_Cache_Manager')) {
            $cache_key = self::generate_cache_key($query, $params, $method, $output_type);
            $cached = Todo_Cache_Manager::get($cache_key, 'query_results');
            
            if ($cached !== false) {
                self::$stats['hits']++;
                return $cached;
            }
        }
        
        self::$stats['misses']++;
        return null;
    }
    
    /**
     * 保存查询结果到缓存
     * 
     * @param string $query SQL查询
     * @param array $params 参数
     * @param mixed $result 查询结果
     * @param int $ttl 缓存TTL（秒）
     */
    public static function set($query, $params, $result, $ttl = null, $method = 'get_results', $output_type = 'OBJECT') {
        if (!self::is_cacheable($query)) {
            return;
        }
        
        if ($ttl === null) {
            $ttl = self::$default_ttl;
        }
        
        // 使用Todo_Cache_Manager进行持久化缓存
        if (class_exists('Todo_Cache_Manager')) {
            $cache_key = self::generate_cache_key($query, $params, $method, $output_type);
            Todo_Cache_Manager::set($cache_key, $result, $ttl, 'query_results');
            self::$stats['saves']++;
        }
    }
    
    /**
     * 生成缓存键
     * 
     * @param string $query SQL查询
     * @param array $params 参数
     * @return string
     */
    private static function generate_cache_key($query, $params = [], $method = 'get_results', $output_type = 'OBJECT') {
        // 标准化查询（移除多余空格）
        $normalized_query = preg_replace('/\s+/', ' ', trim($query));
        return 'query_' . md5($method . '|' . $output_type . '|' . $normalized_query . '|' . serialize($params));
    }
    
    /**
     * 清除查询缓存
     * 
     * @param string $pattern 查询模式（可选）
     */
    public static function clear($pattern = null) {
        if (class_exists('Todo_Cache_Manager')) {
            if ($pattern) {
                // 清除匹配模式的缓存
                // 这里需要实现模式匹配清除逻辑
            } else {
                // 清除所有查询缓存
                Todo_Cache_Manager::flush_group('query_results');
            }
            self::$stats['invalidations']++;
        }
    }
    
    /**
     * 获取统计信息
     * @return array
     */
    public static function get_stats() {
        $total = self::$stats['hits'] + self::$stats['misses'];
        $hit_rate = $total > 0 ? (self::$stats['hits'] / $total * 100) : 0;
        
        return array_merge(self::$stats, [
            'hit_rate' => round($hit_rate, 2)
        ]);
    }
    
    /**
     * 配置缓存
     * 
     * @param array $config 配置
     */
    public static function configure($config) {
        if (isset($config['enabled'])) {
            self::$enabled = (bool)$config['enabled'];
        }
        if (isset($config['default_ttl'])) {
            self::$default_ttl = max(60, (int)$config['default_ttl']);
        }
        if (isset($config['cacheable_patterns'])) {
            self::$cacheable_patterns = array_merge(self::$cacheable_patterns, $config['cacheable_patterns']);
        }
    }
}
