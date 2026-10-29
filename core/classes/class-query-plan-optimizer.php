<?php
/**
 * 查询计划优化器
 * 
 * 分析查询执行计划，优化慢查询
 * 检测缺失的索引，提供优化建议
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 查询计划优化器类
 */
class Todo_Query_Plan_Optimizer {
    
    /**
     * 是否启用
     * @var bool
     */
    private static $enabled = true;
    
    /**
     * 慢查询阈值（毫秒）
     * @var float
     */
    private static $slow_query_threshold = 100.0;
    
    /**
     * 查询执行计划缓存
     * @var array
     */
    private static $explain_cache = [];
    
    /**
     * 慢查询日志
     * @var array
     */
    private static $slow_queries = [];
    
    /**
     * 统计信息
     * @var array
     */
    private static $stats = [
        'queries_analyzed' => 0,
        'slow_queries' => 0,
        'optimizations_suggested' => 0
    ];
    
    /**
     * 启用优化器
     */
    public static function enable() {
        self::$enabled = true;
    }
    
    /**
     * 禁用优化器
     */
    public static function disable() {
        self::$enabled = false;
    }
    
    /**
     * 分析查询执行计划
     * 
     * @param string $query SQL查询
     * @param array $params 参数
     * @param float $execution_time 执行时间（毫秒）
     * @return array 优化建议
     */
    public static function analyze($query, $params = [], $execution_time = 0) {
        if (!self::$enabled) {
            return [];
        }
        
        self::$stats['queries_analyzed']++;
        
        // 检查是否是慢查询
        if ($execution_time > self::$slow_query_threshold) {
            self::$stats['slow_queries']++;
            self::$slow_queries[] = [
                'query' => $query,
                'params' => $params,
                'time' => $execution_time,
                'timestamp' => time()
            ];
        }
        
        $suggestions = [];
        
        // 分析查询模式（仅对慢查询进行分析，避免性能开销）
        if ($execution_time > self::$slow_query_threshold) {
            if (preg_match('/SELECT.*FROM\s+(\w+).*WHERE\s+(\w+)\s*=/i', $query, $matches)) {
                $table = $matches[1];
                $column = $matches[2];
                
                // 跳过系统表和临时表
                if (strpos($table, 'temp_') === 0 || strpos($table, 'tmp_') === 0) {
                    return $suggestions;
                }
                
                // 检查是否有索引
                if (!self::has_index($table, $column)) {
                    $suggestions[] = [
                        'type' => 'missing_index',
                        'table' => $table,
                        'column' => $column,
                        'suggestion' => "建议在表 {$table} 的列 {$column} 上创建索引"
                    ];
                    self::$stats['optimizations_suggested']++;
                }
            }
            // 检查是否有全表扫描
            if (preg_match('/SELECT.*FROM\s+(\w+)(?!.*WHERE)/i', $query)) {
                $suggestions[] = [
                    'type' => 'full_table_scan',
                    'query' => substr($query, 0, 100),
                    'suggestion' => '查询可能执行全表扫描，建议添加WHERE条件或索引'
                ];
            }
            
            // 检查是否有ORDER BY但没有索引
            if (preg_match('/ORDER\s+BY\s+(\w+)/i', $query, $matches)) {
                $order_column = $matches[1];
                if (preg_match('/FROM\s+(\w+)/i', $query, $table_matches)) {
                    $table = $table_matches[1];
                    if (!self::has_index($table, $order_column)) {
                        $suggestions[] = [
                            'type' => 'missing_order_index',
                            'table' => $table,
                            'column' => $order_column,
                            'suggestion' => "ORDER BY {$order_column} 可能较慢，建议创建索引"
                        ];
                    }
                }
            }
        }
        
        return $suggestions;
    }
    
    /**
     * 检查表是否有索引
     * 
     * @param string $table 表名
     * @param string $column 列名
     * @return bool
     */
    private static function has_index($table, $column) {
        global $tododb;
        if (!$tododb) {
            return false;
        }
        
        // 检查缓存
        $cache_key = "index_{$table}_{$column}";
        if (isset(self::$explain_cache[$cache_key])) {
            return self::$explain_cache[$cache_key];
        }
        
        try {
            // 先检查表是否存在（避免查询不存在的表）
            // 注意：SHOW TABLES LIKE 不支持参数化查询，需要直接拼接表名
            // 但我们要确保表名是安全的（只包含字母、数字、下划线）
            $table_name_with_prefix = $tododb->prefix . $table;
            
            // 验证表名只包含安全字符（防止 SQL 注入）
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table_name_with_prefix)) {
                // 表名包含不安全字符，返回 false
                self::$explain_cache[$cache_key] = false;
                return false;
            }
            
            if (function_exists('todo_db_table_exists')) {
                $table_exists = todo_db_table_exists($table);
            } elseif (function_exists('table_exists')) {
                $table_exists = table_exists($table_name_with_prefix);
            } elseif (class_exists('Todo_DB_Prepared')) {
                $table_exists = Todo_DB_Prepared::table_exists($table_name_with_prefix);
            } else {
                // 使用 information_schema 查询表是否存在（支持参数化查询）
                $db_name = defined('DB_NAME') ? DB_NAME : '';
                if (empty($db_name)) {
                    // 如果无法获取数据库名，使用 SHOW TABLES（直接拼接，但已验证表名安全）
                    $table_exists_query = "SHOW TABLES LIKE '{$table_name_with_prefix}'";
                    $table_exists = $tododb->get_var($table_exists_query);
                } else {
                    // 使用 information_schema（支持参数化查询）
                    $table_exists_query = "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?";
                    $table_exists = $tododb->get_var($table_exists_query, [$db_name, $table_name_with_prefix]);
                }
            }
            
            if (!$table_exists) {
                // 表不存在，缓存结果并返回false
                self::$explain_cache[$cache_key] = false;
                return false;
            }
            
            // 查询索引信息（使用 information_schema，避免二次拼前缀或 raw SHOW INDEX 查询）
            $query = "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?";
            $count = $tododb->get_var($query, [$table_name_with_prefix, $column]);
            
            $has_index = ((int)$count > 0);
            self::$explain_cache[$cache_key] = $has_index;
            
            return $has_index;
        } catch (Exception $e) {
            // 查询失败，假设没有索引（但不记录错误，避免日志污染）
            // 只记录表不存在的错误（已在get_results中处理）
            if (strpos($e->getMessage(), "doesn't exist") === false) {
                error_log('Query plan optimizer error: ' . $e->getMessage());
            }
            return false;
        }
    }
    
    /**
     * 获取慢查询日志
     * 
     * @param int $limit 限制数量
     * @return array
     */
    public static function get_slow_queries($limit = 10) {
        // 按执行时间排序
        usort(self::$slow_queries, function($a, $b) {
            return $b['time'] <=> $a['time'];
        });
        
        return array_slice(self::$slow_queries, 0, $limit);
    }
    
    /**
     * 生成优化报告
     * 
     * @return array
     */
    public static function generate_report() {
        $report = [
            'stats' => self::$stats,
            'slow_queries' => self::get_slow_queries(20),
            'recommendations' => []
        ];
        
        // 分析慢查询，生成建议
        $index_suggestions = [];
        foreach (self::$slow_queries as $slow_query) {
            $suggestions = self::analyze($slow_query['query'], $slow_query['params'], $slow_query['time']);
            foreach ($suggestions as $suggestion) {
                if ($suggestion['type'] === 'missing_index') {
                    $key = $suggestion['table'] . '_' . $suggestion['column'];
                    if (!isset($index_suggestions[$key])) {
                        $index_suggestions[$key] = $suggestion;
                    }
                }
            }
        }
        
        $report['recommendations'] = array_values($index_suggestions);
        
        return $report;
    }
    
    /**
     * 获取统计信息
     * @return array
     */
    public static function get_stats() {
        return self::$stats;
    }
    
    /**
     * 配置优化器
     * 
     * @param array $config 配置
     */
    public static function configure($config) {
        if (isset($config['enabled'])) {
            self::$enabled = (bool)$config['enabled'];
        }
        if (isset($config['slow_query_threshold'])) {
            self::$slow_query_threshold = (float)$config['slow_query_threshold'];
        }
    }
    
    /**
     * 清除统计
     */
    public static function clear_stats() {
        self::$stats = [
            'queries_analyzed' => 0,
            'slow_queries' => 0,
            'optimizations_suggested' => 0
        ];
        self::$slow_queries = [];
        self::$explain_cache = [];
    }
}

