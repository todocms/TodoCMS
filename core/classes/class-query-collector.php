<?php
/**
 * 查询收集器
 * 
 * 自动检测和优化N+1查询问题
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 查询收集器类
 */
class Todo_Query_Collector {
    
    /**
     * 查询日志
     * @var array
     */
    protected static $queries = [];
    
    /**
     * 是否启用收集
     * @var bool
     */
    protected static $enabled = false;
    
    /**
     * 查询模式统计
     * @var array [pattern => count]
     */
    protected static $patterns = [];
    
    /**
     * 启用收集
     */
    public static function enable() {
        self::$enabled = true;
        self::$queries = [];
        self::$patterns = [];
    }
    
    /**
     * 禁用收集
     */
    public static function disable() {
        self::$enabled = false;
    }
    
    /**
     * 记录查询
     * 
     * @param string $query SQL查询
     * @param array $params 参数
     * @param float $time 执行时间（秒）
     * @param string $backtrace 调用堆栈
     */
    public static function log($query, $params = [], $time = 0, $backtrace = null) {
        if (!self::$enabled) {
            return;
        }
        
        // 规范化查询（移除参数值，保留占位符）
        $normalized = self::normalize_query($query, $params);
        
        self::$queries[] = [
            'query' => $query,
            'normalized' => $normalized,
            'params' => $params,
            'time' => $time,
            'backtrace' => $backtrace ?: debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
            'timestamp' => microtime(true)
        ];
        
        // 统计模式
        if (!isset(self::$patterns[$normalized])) {
            self::$patterns[$normalized] = 0;
        }
        self::$patterns[$normalized]++;
    }
    
    /**
     * 规范化查询
     * 
     * @param string $query SQL查询
     * @param array $params 参数
     * @return string
     */
    protected static function normalize_query($query, $params) {
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
     * 检测N+1查询
     * 
     * @return array 检测结果
     */
    public static function detect_n_plus_one() {
        $issues = [];
        
        // 查找重复的查询模式（可能是N+1）
        foreach (self::$patterns as $pattern => $count) {
            if ($count > 5) { // 如果同一查询执行超过5次，可能是N+1
                $issues[] = [
                    'type' => 'n_plus_one',
                    'pattern' => $pattern,
                    'count' => $count,
                    'suggestion' => '考虑使用批量查询或eager loading'
                ];
            }
        }
        
        // 查找在循环中执行的查询
        $loop_queries = self::detect_loop_queries();
        if (!empty($loop_queries)) {
            $issues = array_merge($issues, $loop_queries);
        }
        
        return $issues;
    }
    
    /**
     * 检测循环中的查询
     * 
     * @return array
     */
    protected static function detect_loop_queries() {
        $issues = [];
        $similar_queries = [];
        
        // 按时间窗口分组相似的查询
        foreach (self::$queries as $query) {
            $normalized = $query['normalized'];
            $time_window = floor($query['timestamp'] * 10) / 10; // 0.1秒窗口
            
            if (!isset($similar_queries[$normalized])) {
                $similar_queries[$normalized] = [];
            }
            
            if (!isset($similar_queries[$normalized][$time_window])) {
                $similar_queries[$normalized][$time_window] = [];
            }
            
            $similar_queries[$normalized][$time_window][] = $query;
        }
        
        // 检测短时间内大量相似查询
        foreach ($similar_queries as $pattern => $windows) {
            foreach ($windows as $window => $queries) {
                if (count($queries) > 3) { // 0.1秒内超过3次相同查询
                    $issues[] = [
                        'type' => 'loop_query',
                        'pattern' => $pattern,
                        'count' => count($queries),
                        'time_window' => $window,
                        'suggestion' => '在循环外批量查询，或使用eager loading'
                    ];
                }
            }
        }
        
        return $issues;
    }
    
    /**
     * 获取查询统计
     * 
     * @return array
     */
    public static function get_stats() {
        $total_time = 0;
        $slow_queries = 0;
        
        foreach (self::$queries as $query) {
            $total_time += $query['time'];
            if ($query['time'] > 0.1) { // 超过100ms的慢查询
                $slow_queries++;
            }
        }
        
        return [
            'total_queries' => count(self::$queries),
            'unique_patterns' => count(self::$patterns),
            'total_time' => $total_time,
            'average_time' => count(self::$queries) > 0 ? $total_time / count(self::$queries) : 0,
            'slow_queries' => $slow_queries,
            'patterns' => self::$patterns
        ];
    }
    
    /**
     * 获取所有查询
     * 
     * @return array
     */
    public static function get_queries() {
        return self::$queries;
    }
    
    /**
     * 生成性能报告
     * 
     * @return array
     */
    public static function generate_report() {
        $stats = self::get_stats();
        $issues = self::detect_n_plus_one();
        
        return [
            'stats' => $stats,
            'issues' => $issues,
            'top_queries' => self::get_top_queries(10),
            'recommendations' => self::generate_recommendations($stats, $issues)
        ];
    }
    
    /**
     * 获取最频繁的查询
     * 
     * @param int $limit 数量限制
     * @return array
     */
    protected static function get_top_queries($limit = 10) {
        arsort(self::$patterns);
        return array_slice(self::$patterns, 0, $limit, true);
    }
    
    /**
     * 生成优化建议
     * 
     * @param array $stats 统计信息
     * @param array $issues 问题列表
     * @return array
     */
    protected static function generate_recommendations($stats, $issues) {
        $recommendations = [];
        
        if ($stats['total_queries'] > 100) {
            $recommendations[] = [
                'priority' => 'high',
                'message' => '查询数量过多（' . $stats['total_queries'] . '），建议使用查询缓存和批量加载'
            ];
        }
        
        if ($stats['average_time'] > 0.05) {
            $recommendations[] = [
                'priority' => 'medium',
                'message' => '平均查询时间较长（' . round($stats['average_time'] * 1000, 2) . 'ms），建议优化慢查询'
            ];
        }
        
        if (!empty($issues)) {
            $recommendations[] = [
                'priority' => 'high',
                'message' => '检测到 ' . count($issues) . ' 个潜在的N+1查询问题，建议使用ORM的eager loading功能'
            ];
        }
        
        return $recommendations;
    }
    
    /**
     * 清除日志
     */
    public static function clear() {
        self::$queries = [];
        self::$patterns = [];
    }
}