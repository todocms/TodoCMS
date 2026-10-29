<?php
/**
 * ORM查询策略优化器
 * 
 * 智能选择最优的查询方式：
 * - 单条查询 vs 批量查询
 * - 直接查询 vs 缓存查询
 * - 预加载 vs 延迟加载
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * ORM查询策略优化器类
 */
class Todo_ORM_Query_Strategy {
    
    /**
     * 查询策略配置
     * @var array
     */
    private static $strategies = [
        'batch_threshold' => 3, // 批量查询阈值
        'cache_ttl' => 300, // 缓存TTL（秒）
        'preload_enabled' => true,
        'lazy_load_enabled' => true
    ];
    
    /**
     * 查询统计
     * @var array
     */
    private static $stats = [
        'direct_queries' => 0,
        'batch_queries' => 0,
        'cached_queries' => 0,
        'preloaded_queries' => 0
    ];
    
    /**
     * 智能查询：根据上下文选择最优策略
     * 
     * @param string $model_class 模型类名
     * @param array $ids ID数组
     * @param array $options 选项
     * @return array 查询结果
     */
    public static function smart_query($model_class, $ids, $options = []) {
        if (empty($ids)) {
            return [];
        }
        
        // 去重
        $ids = array_unique(array_filter($ids));
        
        // 策略1：如果ID数量超过阈值，使用批量查询
        if (count($ids) >= self::$strategies['batch_threshold']) {
            return self::batch_query($model_class, $ids, $options);
        }
        
        // 策略2：检查缓存
        $cached_results = self::get_cached_results($model_class, $ids);
        $missing_ids = array_diff($ids, array_keys($cached_results));
        
        if (empty($missing_ids)) {
            // 所有结果都在缓存中
            self::$stats['cached_queries']++;
            return array_values($cached_results);
        }
        
        // 策略3：对于少量ID，使用单条查询（但可以合并）
        if (count($missing_ids) === 1) {
            return self::single_query($model_class, $missing_ids[0], $options);
        }
        
        // 策略4：多个ID但少于阈值，使用批量查询
        return self::batch_query($model_class, $missing_ids, $options);
    }
    
    /**
     * 批量查询
     * 
     * @param string $model_class 模型类名
     * @param array $ids ID数组
     * @param array $options 选项
     * @return array
     */
    private static function batch_query($model_class, $ids, $options = []) {
        self::$stats['batch_queries']++;
        
        // 使用ORM的批量加载
        if (class_exists($model_class) && method_exists($model_class, 'eager_load_all')) {
            call_user_func([$model_class, 'eager_load_all'], $ids, $options);
            
            // 从缓存获取结果
            $results = [];
            foreach ($ids as $id) {
                if (method_exists($model_class, 'find')) {
                    $result = call_user_func([$model_class, 'find'], $id);
                    if ($result) {
                        $results[] = $result;
                    }
                }
            }
            return $results;
        }
        
        // 回退到直接查询
        return self::direct_query($model_class, $ids, $options);
    }
    
    /**
     * 单条查询
     * 
     * @param string $model_class 模型类名
     * @param int $id ID
     * @param array $options 选项
     * @return mixed
     */
    private static function single_query($model_class, $id, $options = []) {
        self::$stats['direct_queries']++;
        
        // 检查缓存
        $cache_key = self::get_cache_key($model_class, $id);
        if (class_exists('Todo_Cache_Manager')) {
            $cached = Todo_Cache_Manager::get($cache_key, 'orm');
            if ($cached !== false) {
                self::$stats['cached_queries']++;
                return $cached;
            }
        }
        
        // 执行查询
        if (class_exists($model_class) && method_exists($model_class, 'find')) {
            $result = call_user_func([$model_class, 'find'], $id);
            
            // 缓存结果
            if ($result && class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set($cache_key, $result, self::$strategies['cache_ttl'], 'orm');
            }
            
            return $result;
        }
        
        return null;
    }
    
    /**
     * 直接查询（不使用ORM）
     * 
     * @param string $model_class 模型类名
     * @param array $ids ID数组
     * @param array $options 选项
     * @return array
     */
    private static function direct_query($model_class, $ids, $options = []) {
        global $tododb;
        if (!$tododb) {
            return [];
        }
        
        // 获取表名
        $table = self::get_table_name($model_class);
        if (!$table) {
            return [];
        }
        
        // 构建查询
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $primary_key = self::get_primary_key($model_class);
        $query = "SELECT * FROM {$tododb->prefix}{$table} WHERE {$primary_key} IN ({$placeholders})";
        
        $results = $tododb->get_results($query, $ids, 'ARRAY_A');
        
        // 缓存结果
        if ($results && class_exists('Todo_Cache_Manager')) {
            foreach ($results as $row) {
                $id = isset($row[$primary_key]) ? $row[$primary_key] : null;
                if ($id) {
                    $cache_key = self::get_cache_key($model_class, $id);
                    Todo_Cache_Manager::set($cache_key, $row, self::$strategies['cache_ttl'], 'orm');
                }
            }
        }
        
        return $results ?: [];
    }
    
    /**
     * 获取缓存结果
     * 
     * @param string $model_class 模型类名
     * @param array $ids ID数组
     * @return array
     */
    private static function get_cached_results($model_class, $ids) {
        $results = [];
        
        if (!class_exists('Todo_Cache_Manager')) {
            return $results;
        }
        
        foreach ($ids as $id) {
            $cache_key = self::get_cache_key($model_class, $id);
            $cached = Todo_Cache_Manager::get($cache_key, 'orm');
            if ($cached !== false) {
                $results[$id] = $cached;
            }
        }
        
        return $results;
    }
    
    /**
     * 获取缓存键
     * 
     * @param string $model_class 模型类名
     * @param int $id ID
     * @return string
     */
    private static function get_cache_key($model_class, $id) {
        $class_name = is_object($model_class) ? get_class($model_class) : $model_class;
        return 'orm_' . md5($class_name) . '_' . $id;
    }
    
    /**
     * 获取表名
     * 
     * @param string $model_class 模型类名
     * @return string|null
     */
    private static function get_table_name($model_class) {
        if (class_exists($model_class)) {
            if (defined("{$model_class}::TABLE")) {
                return constant("{$model_class}::TABLE");
            }
            if (property_exists($model_class, 'table')) {
                $reflection = new ReflectionClass($model_class);
                $property = $reflection->getProperty('table');
                $property->setAccessible(true);
                return $property->getValue();
            }
        }
        return null;
    }
    
    /**
     * 获取主键
     * 
     * @param string $model_class 模型类名
     * @return string
     */
    private static function get_primary_key($model_class) {
        if (class_exists($model_class)) {
            if (defined("{$model_class}::PRIMARY_KEY")) {
                return constant("{$model_class}::PRIMARY_KEY");
            }
            if (property_exists($model_class, 'primary_key')) {
                $reflection = new ReflectionClass($model_class);
                $property = $reflection->getProperty('primary_key');
                $property->setAccessible(true);
                return $property->getValue() ?: 'id';
            }
        }
        return 'id';
    }
    
    /**
     * 获取统计信息
     * @return array
     */
    public static function get_stats() {
        return self::$stats;
    }
    
    /**
     * 配置策略
     * 
     * @param array $config 配置
     */
    public static function configure($config) {
        self::$strategies = array_merge(self::$strategies, $config);
    }
}

