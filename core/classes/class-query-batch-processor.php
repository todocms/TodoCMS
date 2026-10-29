<?php
/**
 * 智能查询批处理器
 * 
 * 自动检测并合并相似查询，将多个单条查询合并为批量查询
 * 这是更深层次的查询优化，在查询拦截器基础上进一步优化
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 查询批处理器类
 */
class Todo_Query_Batch_Processor {
    
    /**
     * 是否启用
     * @var bool
     */
    private static $enabled = false;
    
    /**
     * 待批处理的查询队列
     * @var array [pattern => [queries]]
     */
    private static $batch_queue = [];
    
    /**
     * 批处理延迟时间（微秒）
     * @var int
     */
    private static $batch_delay = 1000; // 1ms
    
    /**
     * 批处理最大等待时间（微秒）
     * @var int
     */
    private static $max_batch_wait = 10000; // 10ms
    
    /**
     * 批处理统计
     * @var array
     */
    private static $stats = [
        'total_queries' => 0,
        'batched_queries' => 0,
        'merged_queries' => 0,
        'time_saved' => 0
    ];
    
    /**
     * 查询模式缓存
     * @var array
     */
    private static $pattern_cache = [];
    
    /**
     * 启用批处理
     */
    public static function enable() {
        self::$enabled = true;
        self::$batch_queue = [];
        self::$stats = [
            'total_queries' => 0,
            'batched_queries' => 0,
            'merged_queries' => 0,
            'time_saved' => 0
        ];
    }
    
    /**
     * 禁用批处理
     */
    public static function disable() {
        self::flush_all();
        self::$enabled = false;
    }
    
    /**
     * 检查是否启用
     * @return bool
     */
    public static function is_enabled() {
        return self::$enabled;
    }
    
    /**
     * 标准化查询模式
     * 
     * @param string $query SQL查询
     * @param array $params 参数
     * @return string 标准化后的查询模式
     */
    private static function normalize_pattern($query, $params = []) {
        $cache_key = md5($query . serialize($params));
        if (isset(self::$pattern_cache[$cache_key])) {
            return self::$pattern_cache[$cache_key];
        }
        
        // 提取查询模式
        $pattern = $query;
        
        // 将参数占位符统一为 ?
        $pattern = preg_replace('/\?/', '?', $pattern);
        
        // 提取表名和WHERE条件
        if (preg_match('/FROM\s+([`"]?)(\w+)\1/i', $pattern, $matches)) {
            $table = $matches[2];
        } else {
            $table = 'unknown';
        }
        
        // 检测是否为单条查询模式（WHERE id = ? 或 WHERE field = ?）
        $is_single_query = false;
        $field_name = '';
        if (preg_match('/WHERE\s+([`"]?)(\w+)\1\s*=\s*\?/i', $pattern, $matches)) {
            $is_single_query = true;
            $field_name = $matches[2];
        }
        
        // 生成模式键
        if ($is_single_query) {
            $normalized = "SELECT_FROM_{$table}_WHERE_{$field_name}_EQ";
        } else {
            $normalized = md5($pattern);
        }
        
        self::$pattern_cache[$cache_key] = $normalized;
        return $normalized;
    }
    
    /**
     * 添加查询到批处理队列
     * 
     * @param string $query SQL查询
     * @param array $params 参数
     * @param callable $callback 回调函数（执行实际查询）
     * @return mixed 查询结果
     */
    public static function add_to_batch($query, $params, $callback) {
        if (!self::$enabled) {
            return call_user_func($callback, $query, $params);
        }
        
        self::$stats['total_queries']++;
        
        $pattern = self::normalize_pattern($query, $params);
        
        // 检查是否可以批处理
        if (!self::can_batch($pattern)) {
            return call_user_func($callback, $query, $params);
        }
        
        // 添加到批处理队列
        if (!isset(self::$batch_queue[$pattern])) {
            self::$batch_queue[$pattern] = [];
        }
        
        $query_id = uniqid('q_', true);
        self::$batch_queue[$pattern][] = [
            'id' => $query_id,
            'query' => $query,
            'params' => $params,
            'callback' => $callback,
            'result' => null,
            'processed' => false
        ];
        
        // 如果队列达到阈值，立即处理
        if (count(self::$batch_queue[$pattern]) >= 10) {
            return self::process_batch($pattern);
        }
        
        // 检查队列中是否有多个查询（不延迟，立即检查）
        if (count(self::$batch_queue[$pattern]) > 1) {
            // 有多个查询，立即批处理
            return self::process_batch($pattern);
        }
        
        // 单个查询，直接执行（不移除队列，等待可能的更多查询）
        // 注意：这里不立即执行，而是等待flush_all时处理
        // 如果确实需要立即返回，可以执行，但会失去批处理优势
        $item = self::$batch_queue[$pattern][0];
        unset(self::$batch_queue[$pattern]);
        return call_user_func($item['callback'], $item['query'], $item['params']);
    }
    
    /**
     * 检查查询是否可以批处理
     * 
     * @param string $pattern 查询模式
     * @return bool
     */
    private static function can_batch($pattern) {
        // 只批处理单条查询模式
        return strpos($pattern, 'WHERE_') !== false && strpos($pattern, '_EQ') !== false;
    }
    
    /**
     * 处理批处理队列
     * 
     * @param string $pattern 查询模式
     * @return mixed 最后一个查询的结果
     */
    private static function process_batch($pattern) {
        if (!isset(self::$batch_queue[$pattern]) || empty(self::$batch_queue[$pattern])) {
            return null;
        }
        
        $queries = self::$batch_queue[$pattern];
        if (count($queries) <= 1) {
            // 单个查询，直接执行
            $item = array_pop($queries);
            unset(self::$batch_queue[$pattern]);
            return call_user_func($item['callback'], $item['query'], $item['params']);
        }
        
        // 提取所有参数值
        $param_values = [];
        $first_query = $queries[0]['query'];
        
        foreach ($queries as $item) {
            if (!empty($item['params']) && isset($item['params'][0])) {
                $param_values[] = $item['params'][0];
            }
        }
        
        if (empty($param_values)) {
            // 没有参数，无法合并
            foreach ($queries as $item) {
                $item['result'] = call_user_func($item['callback'], $item['query'], $item['params']);
            }
            unset(self::$batch_queue[$pattern]);
            return $queries[count($queries) - 1]['result'];
        }
        
        // 构建批量查询
        $placeholders = implode(',', array_fill(0, count($param_values), '?'));
        $merged_query = preg_replace('/WHERE\s+(\w+)\s*=\s*\?/i', 'WHERE $1 IN (' . $placeholders . ')', $first_query);
        
        // 执行批量查询
        $start_time = microtime(true);
        $batch_results = call_user_func($queries[0]['callback'], $merged_query, $param_values);
        $batch_time = (microtime(true) - $start_time) * 1000;
        
        // 估算单个查询时间
        $single_time = $batch_time / count($queries);
        $estimated_single_total = $single_time * count($queries);
        self::$stats['time_saved'] += max(0, $estimated_single_total - $batch_time);
        
        // 将结果分配给各个查询
        $results_by_param = [];
        if (is_array($batch_results)) {
            foreach ($batch_results as $row) {
                $param_value = null;
                // 提取参数值对应的字段值
                if (preg_match('/WHERE\s+(\w+)\s*IN/i', $merged_query, $matches)) {
                    $field_name = $matches[1];
                    if (is_array($row) && isset($row[$field_name])) {
                        $param_value = $row[$field_name];
                    } elseif (is_object($row) && isset($row->$field_name)) {
                        $param_value = $row->$field_name;
                    }
                }
                if ($param_value !== null) {
                    $results_by_param[$param_value] = $row;
                }
            }
        }
        
        // 为每个查询设置结果
        foreach ($queries as &$item) {
            $param_value = !empty($item['params']) && isset($item['params'][0]) ? $item['params'][0] : null;
            if ($param_value !== null && isset($results_by_param[$param_value])) {
                $item['result'] = $results_by_param[$param_value];
            } else {
                // 如果没有找到，执行原始查询
                $item['result'] = call_user_func($item['callback'], $item['query'], $item['params']);
            }
            $item['processed'] = true;
        }
        
        self::$stats['batched_queries'] += count($queries);
        self::$stats['merged_queries'] += count($queries) - 1;
        
        // 清空队列
        unset(self::$batch_queue[$pattern]);
        
        // 返回最后一个查询的结果
        return $queries[count($queries) - 1]['result'];
    }
    
    /**
     * 刷新所有待处理的批处理
     */
    public static function flush_all() {
        foreach (self::$batch_queue as $pattern => $queries) {
            self::process_batch($pattern);
        }
        self::$batch_queue = [];
    }
    
    /**
     * 获取统计信息
     * @return array
     */
    public static function get_stats() {
        return self::$stats;
    }
    
    /**
     * 重置统计
     */
    public static function reset_stats() {
        self::$stats = [
            'total_queries' => 0,
            'batched_queries' => 0,
            'merged_queries' => 0,
            'time_saved' => 0
        ];
    }
}

