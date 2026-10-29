<?php
/**
 * 查询合并器
 * 
 * 自动检测并合并重复查询，减少数据库查询次数
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 查询合并器类
 */
class Todo_Query_Merger {
    
    /**
     * 待执行的查询队列
     * @var array
     */
    private static $query_queue = [];
    
    /**
     * 查询结果缓存（请求级别）
     * @var array
     */
    protected static $result_cache = [];
    
    /**
     * 是否启用合并
     * @var bool
     */
    private static $enabled = true;
    
    /**
     * 批量查询延迟时间（毫秒）
     * @var int
     */
    private static $batch_delay = 10;
    
    /**
     * 启用查询合并
     */
    public static function enable() {
        self::$enabled = true;
    }
    
    /**
     * 禁用查询合并
     */
    public static function disable() {
        self::$enabled = false;
        self::flush();
    }
    
    /**
     * 注册查询（延迟执行）
     * 
     * @param string $query SQL查询
     * @param array $params 参数
     * @param string $cache_key 缓存键（可选）
     * @return mixed 查询结果
     */
    public static function register($query, $params = [], $cache_key = null) {
        if (!self::$enabled) {
            return self::execute_immediate($query, $params);
        }
        
        // 生成缓存键
        if ($cache_key === null) {
            $cache_key = self::generate_cache_key($query, $params);
        }
        
        // 检查结果缓存
        if (isset(self::$result_cache[$cache_key])) {
            return self::$result_cache[$cache_key];
        }
        
        // 检查是否已在队列中
        if (isset(self::$query_queue[$cache_key])) {
            // 返回占位符，等待批量执行
            return self::$query_queue[$cache_key]['placeholder'];
        }
        
        // 添加到队列
        $placeholder = new Todo_Query_Placeholder($cache_key);
        self::$query_queue[$cache_key] = [
            'query' => $query,
            'params' => $params,
            'placeholder' => $placeholder,
            'timestamp' => microtime(true)
        ];
        
        return $placeholder;
    }
    
    /**
     * 立即执行查询（用于不能延迟的查询）
     * 
     * @param string $query SQL查询
     * @param array $params 参数
     * @return mixed
     */
    private static function execute_immediate($query, $params) {
        global $tododb;
        return $tododb->get_results($query, $params);
    }
    
    /**
     * 执行批量查询
     * 
     * @param bool $force 是否强制执行（忽略延迟）
     */
    public static function flush($force = false) {
        if (empty(self::$query_queue)) {
            return;
        }
        
        // 按查询模式分组
        $query_groups = self::group_queries();
        
        // 执行每个组的批量查询
        foreach ($query_groups as $group_key => $queries) {
            self::execute_batch($group_key, $queries);
        }
        
        // 清空队列
        self::$query_queue = [];
    }
    
    /**
     * 按查询模式分组
     * 
     * @return array
     */
    private static function group_queries() {
        $groups = [];
        
        foreach (self::$query_queue as $cache_key => $query_data) {
            $normalized = self::normalize_query($query_data['query']);
            $group_key = $normalized;
            
            if (!isset($groups[$group_key])) {
                $groups[$group_key] = [];
            }
            
            $groups[$group_key][$cache_key] = $query_data;
        }
        
        return $groups;
    }
    
    /**
     * 执行批量查询
     * 
     * @param string $group_key 组键
     * @param array $queries 查询数组
     */
    private static function execute_batch($group_key, $queries) {
        global $tododb;
        
        // 检查是否可以合并为IN查询
        $can_merge = self::can_merge_to_in($queries);
        
        if ($can_merge) {
            // 合并为单个IN查询
            $merged = self::merge_to_in_query($queries);
            $results = self::execute_immediate($merged['query'], $merged['params']);
            
            // 分发结果
            self::distribute_results($queries, $results, $merged['param_map']);
        } else {
            // 逐个执行（但使用批量执行优化）
            foreach ($queries as $cache_key => $query_data) {
                $result = self::execute_immediate($query_data['query'], $query_data['params']);
                self::set_result($cache_key, $result, $query_data['placeholder']);
            }
        }
    }
    
    /**
     * 检查是否可以合并为IN查询
     * 
     * @param array $queries 查询数组
     * @return bool
     */
    private static function can_merge_to_in($queries) {
        if (count($queries) < 2) {
            return false;
        }
        
        // 检查所有查询是否具有相同的模式（只有一个参数不同）
        $first_query = reset($queries);
        $normalized = self::normalize_query($first_query['query']);
        
        foreach ($queries as $query_data) {
            if (self::normalize_query($query_data['query']) !== $normalized) {
                return false;
            }
        }
        
        // 检查是否只有一个参数
        if (count($first_query['params']) !== 1) {
            return false;
        }
        
        // 检查参数位置（WHERE field = ?）
        if (preg_match('/WHERE\s+\w+\s*=\s*\?/i', $first_query['query'])) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 合并为IN查询
     * 
     * @param array $queries 查询数组
     * @return array [query, params, param_map]
     */
    private static function merge_to_in_query($queries) {
        $first_query = reset($queries);
        $base_query = $first_query['query'];
        
        // 提取所有参数值
        $param_values = [];
        $param_map = []; // [cache_key => index]
        
        foreach ($queries as $cache_key => $query_data) {
            $value = $query_data['params'][0];
            $index = count($param_values);
            $param_values[] = $value;
            $param_map[$cache_key] = $index;
        }
        
        // 构建IN查询
        $placeholders = implode(',', array_fill(0, count($param_values), '?'));
        $merged_query = preg_replace('/WHERE\s+(\w+)\s*=\s*\?/i', 'WHERE $1 IN (' . $placeholders . ')', $base_query);
        
        return [
            'query' => $merged_query,
            'params' => $param_values,
            'param_map' => $param_map
        ];
    }
    
    /**
     * 分发批量查询结果
     * 
     * @param array $queries 查询数组
     * @param array $results 批量查询结果
     * @param array $param_map 参数映射
     */
    private static function distribute_results($queries, $results, $param_map) {
        // 按参数值分组结果
        $results_by_param = [];
        
        if ($results && is_array($results)) {
            $first_query = reset($queries);
            $param_field = self::extract_param_field($first_query['query']);
            
            foreach ($results as $row) {
                $data = is_object($row) ? (array)$row : $row;
                $param_value = isset($data[$param_field]) ? $data[$param_field] : null;
                
                if ($param_value !== null) {
                    if (!isset($results_by_param[$param_value])) {
                        $results_by_param[$param_value] = [];
                    }
                    $results_by_param[$param_value][] = $row;
                }
            }
        }
        
        // 为每个查询设置结果
        foreach ($queries as $cache_key => $query_data) {
            $param_value = $query_data['params'][0];
            $result = isset($results_by_param[$param_value]) 
                ? $results_by_param[$param_value] 
                : [];
            
            self::set_result($cache_key, $result, $query_data['placeholder']);
        }
    }
    
    /**
     * 提取参数字段名
     * 
     * @param string $query SQL查询
     * @return string|null
     */
    private static function extract_param_field($query) {
        if (preg_match('/WHERE\s+(\w+)\s*=\s*\?/i', $query, $matches)) {
            return $matches[1];
        }
        return null;
    }
    
    /**
     * 设置查询结果
     * 
     * @param string $cache_key 缓存键
     * @param mixed $result 结果
     * @param Todo_Query_Placeholder $placeholder 占位符
     */
    private static function set_result($cache_key, $result, $placeholder) {
        // 缓存结果
        self::$result_cache[$cache_key] = $result;
        
        // 设置占位符的值
        if ($placeholder instanceof Todo_Query_Placeholder) {
            $placeholder->set_value($result);
        }
    }
    
    /**
     * 规范化查询（移除参数值）
     * 
     * @param string $query SQL查询
     * @return string
     */
    private static function normalize_query($query) {
        // 移除参数值，统一为占位符
        $normalized = preg_replace('/\?/', '?', $query);
        // 移除多余空格
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        return trim($normalized);
    }
    
    /**
     * 生成缓存键
     * 
     * @param string $query SQL查询
     * @param array $params 参数
     * @return string
     */
    private static function generate_cache_key($query, $params) {
        $key_data = [
            'query' => self::normalize_query($query),
            'params' => $params
        ];
        return md5(serialize($key_data));
    }
    
    /**
     * 清除缓存
     */
    public static function clear_cache() {
        self::$result_cache = [];
        self::$query_queue = [];
    }
    
    /**
     * 获取统计信息
     * 
     * @return array
     */
    public static function get_stats() {
        return [
            'queued_queries' => count(self::$query_queue),
            'cached_results' => count(self::$result_cache),
            'enabled' => self::$enabled
        ];
    }
}

/**
 * 查询占位符类
 * 
 * 用于延迟查询的结果占位符
 */
class Todo_Query_Placeholder {
    
    /**
     * 缓存键
     * @var string
     */
    private $cache_key;
    
    /**
     * 值
     * @var mixed
     */
    private $value = null;
    
    /**
     * 是否已解析
     * @var bool
     */
    private $resolved = false;
    
    /**
     * 构造函数
     * 
     * @param string $cache_key 缓存键
     */
    public function __construct($cache_key) {
        $this->cache_key = $cache_key;
    }
    
    /**
     * 设置值
     * 
     * @param mixed $value 值
     */
    public function set_value($value) {
        $this->value = $value;
        $this->resolved = true;
    }
    
    /**
     * 获取值
     * 
     * @return mixed
     */
    public function get_value() {
        if (!$this->resolved) {
            // 如果还未解析，尝试从缓存获取
            $cache = self::get_result_cache();
            if (isset($cache[$this->cache_key])) {
                $this->value = $cache[$this->cache_key];
                $this->resolved = true;
            } else {
                // 强制刷新队列
                Todo_Query_Merger::flush(true);
                // 再次尝试获取
                $cache = self::get_result_cache();
                if (isset($cache[$this->cache_key])) {
                    $this->value = $cache[$this->cache_key];
                    $this->resolved = true;
                }
            }
        }
        
        return $this->value;
    }
    
    /**
     * 获取结果缓存（静态方法，供占位符访问）
     * 
     * @return array
     */
    private static function get_result_cache() {
        // 使用反射访问protected属性
        $reflection = new ReflectionClass('Todo_Query_Merger');
        $property = $reflection->getProperty('result_cache');
        $property->setAccessible(true);
        return $property->getValue();
    }
    
    /**
     * 转换为数组（兼容性）
     * 
     * @return array
     */
    public function to_array() {
        $value = $this->get_value();
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return (array)$value;
        }
        return [];
    }
    
    /**
     * 魔术方法：获取属性
     */
    public function __get($key) {
        $value = $this->get_value();
        if (is_object($value)) {
            return isset($value->$key) ? $value->$key : null;
        }
        if (is_array($value)) {
            return isset($value[$key]) ? $value[$key] : null;
        }
        return null;
    }
    
    /**
     * 魔术方法：检查属性是否存在
     */
    public function __isset($key) {
        $value = $this->get_value();
        if (is_object($value)) {
            return isset($value->$key);
        }
        if (is_array($value)) {
            return isset($value[$key]);
        }
        return false;
    }
}

