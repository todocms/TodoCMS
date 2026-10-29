<?php
/**
 * 数据库查询结果池
 * 
 * 缓存常用查询结果，减少数据库访问
 * 特别适用于首次加载时的性能优化
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 数据库查询结果池类
 */
class Todo_DB_Result_Pool {
    
    /**
     * 结果池
     * @var array
     */
    private static $pool = [];
    
    /**
     * 池统计
     * @var array
     */
    private static $stats = [
        'hits' => 0,
        'misses' => 0,
        'saves' => 0
    ];
    
    /**
     * 是否启用
     * @var bool
     */
    private static $enabled = true;
    
    /**
     * 启用结果池
     */
    public static function enable() {
        self::$enabled = true;
    }
    
    /**
     * 禁用结果池
     */
    public static function disable() {
        self::$enabled = false;
        self::$pool = [];
    }
    
    /**
     * 从池中获取结果
     * 
     * @param string $key 缓存键
     * @return mixed 结果或null
     */
    public static function get($key) {
        if (!self::$enabled) {
            return null;
        }
        
        if (isset(self::$pool[$key])) {
            self::$stats['hits']++;
            return self::$pool[$key];
        }
        
        self::$stats['misses']++;
        return null;
    }
    
    /**
     * 保存结果到池
     * 
     * @param string $key 缓存键
     * @param mixed $value 结果
     */
    public static function set($key, $value) {
        if (!self::$enabled) {
            return;
        }
        
        self::$pool[$key] = $value;
        self::$stats['saves']++;
    }
    
    /**
     * 批量保存结果
     * 
     * @param array $data 数据数组 [key => value]
     */
    public static function set_batch($data) {
        if (!self::$enabled || !is_array($data)) {
            return;
        }
        
        foreach ($data as $key => $value) {
            self::$pool[$key] = $value;
        }
        
        self::$stats['saves'] += count($data);
    }
    
    /**
     * 检查是否存在
     * 
     * @param string $key 缓存键
     * @return bool
     */
    public static function has($key) {
        return isset(self::$pool[$key]);
    }
    
    /**
     * 清除结果池
     */
    public static function clear() {
        self::$pool = [];
        self::$stats = [
            'hits' => 0,
            'misses' => 0,
            'saves' => 0
        ];
    }
    
    /**
     * 获取统计信息
     * @return array
     */
    public static function get_stats() {
        $total = self::$stats['hits'] + self::$stats['misses'];
        $hit_rate = $total > 0 ? (self::$stats['hits'] / $total * 100) : 0;
        
        return array_merge(self::$stats, [
            'hit_rate' => round($hit_rate, 2),
            'pool_size' => count(self::$pool)
        ]);
    }
}

