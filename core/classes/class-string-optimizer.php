<?php
/**
 * 字符串处理优化器
 * 
 * 优化字符串操作，减少内存分配和CPU开销
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 字符串处理优化器类
 */
class Todo_String_Optimizer {
    
    /**
     * 字符串缓存
     * @var array
     */
    private static $string_cache = [];
    
    /**
     * 统计信息
     * @var array
     */
    private static $stats = [
        'cache_hits' => 0,
        'cache_misses' => 0,
        'operations_optimized' => 0
    ];
    
    /**
     * 优化的字符串替换（批量替换）
     * 
     * @param string $subject 目标字符串
     * @param array $replacements 替换数组 [search => replace]
     * @return string
     */
    public static function batch_replace($subject, $replacements) {
        if (empty($replacements)) {
            return $subject;
        }
        
        // 检查缓存
        $cache_key = md5($subject . serialize($replacements));
        if (isset(self::$string_cache[$cache_key])) {
            self::$stats['cache_hits']++;
            return self::$string_cache[$cache_key];
        }
        
        // 使用strtr进行批量替换（比多次str_replace快）
        $result = strtr($subject, $replacements);
        
        // 缓存结果
        self::$string_cache[$cache_key] = $result;
        self::$stats['cache_misses']++;
        self::$stats['operations_optimized']++;
        
        return $result;
    }
    
    /**
     * 优化的字符串拼接
     * 
     * @param array $parts 字符串数组
     * @return string
     */
    public static function optimized_concat($parts) {
        if (empty($parts)) {
            return '';
        }
        
        if (count($parts) === 1) {
            return (string)$parts[0];
        }
        
        // 使用implode比多次拼接快
        return implode('', $parts);
    }
    
    /**
     * 清除缓存
     */
    public static function clear_cache() {
        self::$string_cache = [];
    }
    
    /**
     * 获取统计信息
     * @return array
     */
    public static function get_stats() {
        return self::$stats;
    }
}

