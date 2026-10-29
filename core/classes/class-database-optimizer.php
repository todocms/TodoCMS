<?php
/**
 * 数据库查询优化器
 * 
 * 提供查询缓存、连接池、慢查询监控等功能
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

 
class Todo_Database_Optimizer {
    
    /**
     * 优化器实例
     */
    private static $instance = null;
    
    /**
     * 查询缓存
     */
    private $query_cache = [];
    
    /**
     * 预处理语句缓存
     */
    private $prepared_statements = [];
    
    /**
     * 连接池
     */
    private $connection_pool = [];
    
    /**
     * 慢查询日志
     */
    private $slow_queries = [];
    
    /**
     * 查询统计
     */
    private $query_stats = [
        'total_queries' => 0,
        'cached_queries' => 0,
        'slow_queries' => 0,
        'total_time' => 0
    ];
    
    /**
     * 配置选项
     */
    private $config = [
        'cache_enabled' => true,
        'cache_ttl' => 3600, // 1小时
        'slow_query_threshold' => 1.0, // 1秒
        'max_connections' => 10,
        'connection_timeout' => 30,
        'prepared_statement_cache' => true,
        'query_log_enabled' => true
    ];
    
    /**
     * 缓存管理器
     */
    private $cache_manager;
    
    /**
     * 获取优化器实例
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 私有构造函数
     */
    private function __construct() {
        $this->cache_manager = Todo_Cache_Manager::getInstance();
        $this->initializeConfig();
    }
    
    /**
     * 初始化配置
     */
    private function initializeConfig() {
        // 从数据库或配置文件加载配置
        $saved_config = get_option('tododb_optimizer_config', []);
        $this->config = array_merge($this->config, $saved_config);
    }
    
    /**
     * 执行优化查询
     * 
     * @param string $query SQL查询
     * @param array $params 参数
     * @param array $options 选项
     * @return mixed
     */
    public function query($query, $params = [], $options = []) {
        $start_time = microtime(true);
        $cache_key = $this->generateCacheKey($query, $params);
        
        // 尝试从缓存获取结果
        if ($this->config['cache_enabled'] && !isset($options['no_cache'])) {
            $cached_result = $this->getCachedResult($cache_key);
            if ($cached_result !== false) {
                $this->query_stats['cached_queries']++;
                return $cached_result;
            }
        }
        
        // 执行查询
        $result = $this->executeQuery($query, $params, $options);
        
        // 计算执行时间
        $execution_time = microtime(true) - $start_time;
        $this->query_stats['total_time'] += $execution_time;
        $this->query_stats['total_queries']++;
        
        // 检查是否为慢查询
        if ($execution_time > $this->config['slow_query_threshold']) {
            $this->logSlowQuery($query, $params, $execution_time);
        }
        
        // 缓存结果
        if ($this->config['cache_enabled'] && !isset($options['no_cache']) && $this->shouldCacheQuery($query)) {
            $this->cacheResult($cache_key, $result);
        }
        
        return $result;
    }
    
    /**
     * 执行实际查询
     * 
     * @param string $query SQL查询
     * @param array $params 参数
     * @param array $options 选项
     * @return mixed
     */
    private function executeQuery($query, $params = [], $options = []) {
        global $tododb;
        
        try {
            // 使用预处理语句
            if (!empty($params) && $this->config['prepared_statement_cache']) {
                return $this->executePreparedStatement($query, $params);
            }
            
            // 直接执行查询
            if (empty($params)) {
                return $tododb->get_results($query);
            } else {
                // 使用 Todo_DB_Prepared 类进行安全的预处理查询
                return Todo_DB_Prepared::get_results($query, $params);
            }
        } catch (Exception $e) {
            Todo_Logger::error('数据库查询执行失败', [
                'query' => $query,
                'params' => $params,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * 执行预处理语句
     * 
     * @param string $query SQL查询
     * @param array $params 参数
     * @return mixed
     */
    private function executePreparedStatement($query, $params) {
        $stmt_key = md5($query);
        
        // 检查预处理语句缓存
        if (!isset($this->prepared_statements[$stmt_key])) {
            // 缓存查询语句
            $this->prepared_statements[$stmt_key] = $query;
        }
        
        // 使用 Todo_DB_Prepared 类执行预处理查询
        return Todo_DB_Prepared::get_results($query, $params);
    }
    
    /**
     * 生成缓存键
     * 
     * @param string $query SQL查询
     * @param array $params 参数
     * @return string
     */
    private function generateCacheKey($query, $params = []) {
        $key_data = [
            'query' => trim(preg_replace('/\s+/', ' ', $query)),
            'params' => $params
        ];
        return 'db_query_' . md5(serialize($key_data));
    }
    
    /**
     * 获取缓存结果
     * 
     * @param string $cache_key 缓存键
     * @return mixed
     */
    private function getCachedResult($cache_key) {
        return $this->cache_manager->get($cache_key);
    }
    
    /**
     * 缓存查询结果
     * 
     * @param string $cache_key 缓存键
     * @param mixed $result 查询结果
     */
    private function cacheResult($cache_key, $result) {
        $this->cache_manager->set($cache_key, $result, $this->config['cache_ttl']);
    }
    
    /**
     * 检查是否应该缓存查询
     * 
     * @param string $query SQL查询
     * @return bool
     */
    private function shouldCacheQuery($query) {
        $query_lower = strtolower(trim($query));
        
        // 只缓存SELECT查询
        if (strpos($query_lower, 'select') !== 0) {
            return false;
        }
        
        // 不缓存包含随机函数的查询
        $random_functions = ['rand()', 'random()', 'now()', 'current_timestamp'];
        foreach ($random_functions as $func) {
            if (strpos($query_lower, $func) !== false) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * 记录慢查询
     * 
     * @param string $query SQL查询
     * @param array $params 参数
     * @param float $execution_time 执行时间
     */
    private function logSlowQuery($query, $params, $execution_time) {
        $this->query_stats['slow_queries']++;
        
        $slow_query = [
            'query' => $query,
            'params' => $params,
            'execution_time' => $execution_time,
            'timestamp' => time(),
            'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5)
        ];
        
        $this->slow_queries[] = $slow_query;
        
        // 记录到日志
        Todo_Logger::warning('慢查询检测', [
            'query' => $query,
            'execution_time' => $execution_time,
            'threshold' => $this->config['slow_query_threshold']
        ]);
        
        // 限制慢查询记录数量
        if (count($this->slow_queries) > 100) {
            array_shift($this->slow_queries);
        }
    }
    
    /**
     * 清除查询缓存
     * 
     * @param string $pattern 缓存键模式
     */
    public function clearQueryCache($pattern = null) {
        if ($pattern) {
            Todo_Cache_Manager::deleteByPattern('db_query_' . $pattern);
        } else {
            Todo_Cache_Manager::deleteByPattern('db_query_*');
        }
    }
    
    /**
     * 清除表相关缓存
     * 
     * @param string $table_name 表名
     */
    public function clearTableCache($table_name) {
        $this->clearQueryCache('*' . $table_name . '*');
    }
    
    /**
     * 获取查询统计信息
     * 
     * @return array
     */
    public function getQueryStats() {
        $stats = $this->query_stats;
        $stats['cache_hit_rate'] = $stats['total_queries'] > 0 ? 
            round(($stats['cached_queries'] / $stats['total_queries']) * 100, 2) : 0;
        $stats['average_time'] = $stats['total_queries'] > 0 ? 
            round($stats['total_time'] / $stats['total_queries'], 4) : 0;
        
        return $stats;
    }
    
    /**
     * 获取慢查询列表
     * 
     * @param int $limit 限制数量
     * @return array
     */
    public function getSlowQueries($limit = 20) {
        return array_slice($this->slow_queries, -$limit);
    }
    
    /**
     * 分析查询性能
     * 
     * @param string $query SQL查询
     * @return array
     */
    public function analyzeQuery($query) {
        global $tododb;
        
        $analysis = [
            'query' => $query,
            'explain' => null,
            'suggestions' => []
        ];
        
        try {
            // 执行EXPLAIN
            $explain_result = $tododb->get_results('EXPLAIN ' . $query);
            $analysis['explain'] = $explain_result;
            
            // 分析结果并提供建议
            $analysis['suggestions'] = $this->generateQuerySuggestions($explain_result);
            
        } catch (Exception $e) {
            $analysis['error'] = $e->getMessage();
        }
        
        return $analysis;
    }
    
    /**
     * 生成查询优化建议
     * 
     * @param array $explain_result EXPLAIN结果
     * @return array
     */
    private function generateQuerySuggestions($explain_result) {
        $suggestions = [];
        
        foreach ($explain_result as $row) {
            // 检查是否使用了索引
            if ($row->key === null) {
                $suggestions[] = "表 {$row->table} 没有使用索引，考虑添加适当的索引";
            }
            
            // 检查扫描行数
            if ($row->rows > 1000) {
                $suggestions[] = "表 {$row->table} 扫描了 {$row->rows} 行，考虑优化WHERE条件";
            }
            
            // 检查连接类型
            if ($row->type === 'ALL') {
                $suggestions[] = "表 {$row->table} 使用了全表扫描，考虑添加索引";
            }
            
            // 检查临时表
            if (strpos($row->Extra, 'Using temporary') !== false) {
                $suggestions[] = "查询使用了临时表，考虑优化ORDER BY或GROUP BY";
            }
            
            // 检查文件排序
            if (strpos($row->Extra, 'Using filesort') !== false) {
                $suggestions[] = "查询使用了文件排序，考虑添加适当的索引";
            }
        }
        
        return $suggestions;
    }
    
    /**
     * 优化表
     * 
     * @param string $table_name 表名
     * @return array
     */
    public function optimizeTable($table_name) {
        global $tododb;
        
        try {
            $result = $tododb->get_results("OPTIMIZE TABLE {$table_name}");
            
            Todo_Logger::info("表优化完成: {$table_name}");
            
            return [
                'success' => true,
                'result' => $result
            ];
        } catch (Exception $e) {
            Todo_Logger::error("表优化失败: {$table_name}", ['error' => $e->getMessage()]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 获取表状态信息
     * 
     * @param string $table_name 表名
     * @return array
     */
    public function getTableStatus($table_name = null) {
        global $tododb;
        
        try {
            if ($table_name) {
                $query = "SHOW TABLE STATUS LIKE '{$table_name}'";
            } else {
                $query = "SHOW TABLE STATUS";
            }
            
            return $tododb->get_results($query);
        } catch (Exception $e) {
            Todo_Logger::error('获取表状态失败', ['error' => $e->getMessage()]);
            return [];
        }
    }
    
    /**
     * 获取索引信息
     * 
     * @param string $table_name 表名
     * @return array
     */
    public function getTableIndexes($table_name) {
        global $tododb;
        
        try {
            return $tododb->get_results("SHOW INDEX FROM {$table_name}");
        } catch (Exception $e) {
            Todo_Logger::error("获取表索引失败: {$table_name}", ['error' => $e->getMessage()]);
            return [];
        }
    }
    
    /**
     * 更新配置
     * 
     * @param array $config 新配置
     */
    public function updateConfig($config) {
        $this->config = array_merge($this->config, $config);
        update_option('tododb_optimizer_config', $this->config);
    }
    
    /**
     * 获取配置
     * 
     * @return array
     */
    public function getConfig() {
        return $this->config;
    }
    
    /**
     * 重置统计信息
     */
    public function resetStats() {
        $this->query_stats = [
            'total_queries' => 0,
            'cached_queries' => 0,
            'slow_queries' => 0,
            'total_time' => 0
        ];
        $this->slow_queries = [];
    }
    
    /**
     * 生成性能报告
     * 
     * @return array
     */
    public function generatePerformanceReport() {
        return [
            'stats' => $this->getQueryStats(),
            'slow_queries' => $this->getSlowQueries(10),
            'config' => $this->config,
            'cache_info' => $this->cache_manager->get_stats(),
            'timestamp' => time()
        ];
    }
}

if (!function_exists('todo_clear_db_cache')) {
    /**
     * 清除数据库缓存
     * 
     * @param string $pattern 缓存键模式
     */
    function todo_clear_db_cache($pattern = null) {
        Todo_Database_Optimizer::getInstance()->clearQueryCache($pattern);
    }
}

if (!function_exists('tododb_stats')) {
    /**
     * 获取数据库查询统计
     * 
     * @return array
     */
    function tododb_stats() {
        return Todo_Database_Optimizer::getInstance()->getQueryStats();
    }
}