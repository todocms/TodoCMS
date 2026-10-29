<?php
/**
 * 数据库预处理语句标准化类
 * 提供安全的数据库操作接口，防止SQL注入
 *
 * @package TodoCMS
 * @version 1.0.0
 */

class Todo_DB_Prepared {
    
    /**
     * 数据库连接实例
     * @var mysqli
     */
    private static $connection = null;
    
    /**
     * 查询缓存
     * @var array
     */
    private static $query_cache = array();
    
    /**
     * 是否启用查询缓存
     * @var bool
     */
    private static $cache_enabled = true;
    
    /**
     * 查询统计
     * @var array
     */
    private static $query_stats = array(
        'total_queries' => 0,
        'cache_hits' => 0,
        'execution_time' => 0
    );

    private static $table_columns_cache = array();

    private static function normalize_identifier_name($identifier)
    {
        $identifier = (string) $identifier;
        $identifier = str_replace(['`', '"', "'"], '', $identifier);
        $identifier = preg_replace('/[^a-zA-Z0-9_]/', '', $identifier);
        return $identifier;
    }

    private static function get_table_columns($table)
    {
        if (!self::$connection) {
            if (!self::init()) {
                return [];
            }
        }

        $table_name = self::normalize_identifier_name($table);
        if ($table_name === '') {
            return [];
        }

        if (isset(self::$table_columns_cache[$table_name]) && is_array(self::$table_columns_cache[$table_name])) {
            return self::$table_columns_cache[$table_name];
        }

        if (class_exists('Todo_Cache_Manager')) {
            $cached = Todo_Cache_Manager::get('db_cols_' . $table_name, 'database', 'global');
            if ($cached !== false && is_array($cached)) {
                self::$table_columns_cache[$table_name] = $cached;
                return $cached;
            }
        }

        $cols = [];
        try {
            $table_escaped = self::escape_identifier($table_name);
            $result = @self::$connection->query("SHOW COLUMNS FROM {$table_escaped}");
            if ($result instanceof mysqli_result) {
                while ($row = $result->fetch_assoc()) {
                    if (isset($row['Field']) && $row['Field'] !== '') {
                        $cols[] = (string) $row['Field'];
                    }
                }
                $result->free();
            }
        } catch (Throwable $e) {
            $cols = [];
        }

        self::$table_columns_cache[$table_name] = $cols;
        if (!empty($cols) && class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::set('db_cols_' . $table_name, $cols, 86400, 'database', 'global');
        }
        return $cols;
    }
    
    /**
     * 初始化数据库连接
     * 
     * @return bool 初始化是否成功
     */
    public static function init() {
        global $tododb;
        
        if (isset($tododb) && $tododb instanceof todo) {
            // 使用现有的todo连接
            self::$connection = $tododb->dbh;
        } else {
            // 创建新的数据库连接
            self::$connection = new mysqli(
                defined('DB_HOST') ? DB_HOST : 'localhost',
                defined('DB_USER') ? DB_USER : 'root',
                defined('DB_PASSWORD') ? DB_PASSWORD : '',
                defined('DB_NAME') ? DB_NAME : 'todocms'
            );
            
            if (self::$connection->connect_error) {
                error_log('Todo DB: Connection failed: ' . self::$connection->connect_error);
                return false;
            }
            
            // 设置字符集
            self::$connection->set_charset('utf8mb4');
        }
        
        return true;
    }
    
    /**
     * 执行预处理查询
     * 
     * @param string $query SQL查询语句
     * @param array $params 参数数组
     * @param string $types 参数类型字符串
     * @return mysqli_result|bool 查询结果
     */
    public static function query($query, $params = array(), $types = '') {
        if (!self::$connection) {
            if (!self::init()) {
                return false;
            }
        }

        if (self::demo_should_block_write_query($query)) {
            return false;
        }
        
        $start_time = microtime(true);
        self::$query_stats['total_queries']++;
        
        // 检查缓存
        $cache_key = md5($query . serialize($params));
        if (self::$cache_enabled && isset(self::$query_cache[$cache_key])) {
            self::$query_stats['cache_hits']++;
            return self::$query_cache[$cache_key];
        }
        
        // 准备语句
        $stmt = self::$connection->prepare($query);
        if (!$stmt) {
            error_log('Todo DB: Prepare failed: ' . self::$connection->error);
            return false;
        }
        
        // 绑定参数
        if (!empty($params)) {
            if (empty($types)) {
                $types = self::auto_detect_types($params);
            }
            
            if (!$stmt->bind_param($types, ...$params)) {
                error_log('Todo DB: Bind param failed: ' . $stmt->error);
                $stmt->close();
                return false;
            }
        }
        
        // 执行查询
        if (!$stmt->execute()) {
            error_log('Todo DB: Execute failed: ' . $stmt->error);
            $stmt->close();
            return false;
        }
        
        // 根据语句类型获取结果：
        // - SELECT/SHOW/DESCRIBE/EXPLAIN 返回 mysqli_result
        // - 其他（INSERT/UPDATE/DELETE等）返回布尔 true 表示成功
        $is_result_set_query = preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $query) === 1;
        $result = $is_result_set_query ? $stmt->get_result() : true;
        $stmt->close();
        
        // 记录执行时间
        self::$query_stats['execution_time'] += microtime(true) - $start_time;
        
        // 缓存结果（仅对SELECT查询）
        if ($is_result_set_query && self::$cache_enabled && stripos(trim($query), 'SELECT') === 0) {
            self::$query_cache[$cache_key] = $result;
        }
        
        return $result;
    }

    private static function demo_is_active()
    {
        if (!(defined('TODO_DEMO_ADMIN_ENABLED') && TODO_DEMO_ADMIN_ENABLED)) {
            return false;
        }
        if (php_sapi_name() === 'cli') {
            return false;
        }
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        return isset($_SESSION['todo_demo_admin']) && (int) $_SESSION['todo_demo_admin'] === 1;
    }

    private static function demo_is_write_query($query)
    {
        $q = ltrim((string) $query);
        if ($q === '') {
            return false;
        }
        $m = [];
        if (!preg_match('/^([a-zA-Z]+)/', $q, $m)) {
            return false;
        }
        $verb = strtoupper($m[1]);
        if (in_array($verb, ['SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN'], true)) {
            return false;
        }
        if (in_array($verb, ['BEGIN', 'COMMIT', 'ROLLBACK', 'START', 'SET'], true)) {
            return false;
        }
        return in_array($verb, ['INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME'], true);
    }

    private static function demo_normalize_table_name($table)
    {
        $t = trim((string) $table);
        $t = str_replace(['`', '"', "'"], '', $t);
        $parts = preg_split('/\s*\.\s*/', $t);
        $t = end($parts);
        return strtolower($t);
    }

    private static function demo_is_allowed_write_table($table)
    {
        $t = self::demo_normalize_table_name($table);
        $prefix = defined('TABLE_PREFIX') ? (string) TABLE_PREFIX : 'todo_';
        $allowed = [
            strtolower($prefix . 'user_sessions'),
        ];
        return in_array($t, $allowed, true);
    }

    private static function demo_extract_primary_table_from_query($query)
    {
        $q = ltrim((string) $query);
        $m = [];
        if (preg_match('/^\s*INSERT\s+INTO\s+([`"\']?[a-zA-Z0-9_]+[`"\']?(?:\s*\.\s*[`"\']?[a-zA-Z0-9_]+[`"\']?)?)/i', $q, $m)) {
            return $m[1];
        }
        if (preg_match('/^\s*REPLACE\s+INTO\s+([`"\']?[a-zA-Z0-9_]+[`"\']?(?:\s*\.\s*[`"\']?[a-zA-Z0-9_]+[`"\']?)?)/i', $q, $m)) {
            return $m[1];
        }
        if (preg_match('/^\s*UPDATE\s+([`"\']?[a-zA-Z0-9_]+[`"\']?(?:\s*\.\s*[`"\']?[a-zA-Z0-9_]+[`"\']?)?)/i', $q, $m)) {
            return $m[1];
        }
        if (preg_match('/^\s*DELETE\s+FROM\s+([`"\']?[a-zA-Z0-9_]+[`"\']?(?:\s*\.\s*[`"\']?[a-zA-Z0-9_]+[`"\']?)?)/i', $q, $m)) {
            return $m[1];
        }
        return null;
    }

    private static function demo_should_block_write_query($query)
    {
        if (!self::demo_is_active()) {
            return false;
        }
        if (!self::demo_is_write_query($query)) {
            return false;
        }
        $table = self::demo_extract_primary_table_from_query($query);
        if ($table === null) {
            return true;
        }
        return !self::demo_is_allowed_write_table($table);
    }
    
    /**
     * 获取单行数据
     * 
     * @param string $query SQL查询语句
     * @param array $params 参数数组
     * @param string $types 参数类型字符串
     * @return array|null 查询结果
     */
    public static function get_row($query, $params = array(), $types = '') {
        $result = self::query($query, $params, $types);
        
        if ($result && $result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        
        return null;
    }
    
    /**
     * 获取多行数据
     * 
     * @param string $query SQL查询语句
     * @param array $params 参数数组
     * @param string $types 参数类型字符串
     * @return array 查询结果数组
     */
    public static function get_results($query, $params = array(), $types = '') {
        $result = self::query($query, $params, $types);
        $rows = array();
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        
        return $rows;
    }
    
    /**
     * 获取单个值
     * 
     * @param string $query SQL查询语句
     * @param array $params 参数数组
     * @param string $types 参数类型字符串
     * @return mixed 查询结果值
     */
    public static function get_var($query, $params = array(), $types = '') {
        $row = self::get_row($query, $params, $types);
        
        if ($row) {
            return reset($row); // 返回第一个值
        }
        
        return null;
    }
    
    /**
     * 插入数据
     * 
     * @param string $table 表名
     * @param array $data 数据数组
     * @return int|bool 插入的ID或false
     */
    public static function insert($table, $data) {
        if (empty($data)) {
            return false;
        }
        
        $columns_in_table = self::get_table_columns($table);
        if (!empty($columns_in_table)) {
            foreach (array_keys($data) as $k) {
                if (!in_array((string) $k, $columns_in_table, true)) {
                    unset($data[$k]);
                }
            }
        }
        if (empty($data)) {
            return false;
        }

        $table = self::escape_identifier($table);
        $columns = array_keys($data);
        $values = array_values($data);
        
        $column_list = '`' . implode('`, `', $columns) . '`';
        $placeholder_list = str_repeat('?,', count($values) - 1) . '?';
        
        $query = "INSERT INTO {$table} ({$column_list}) VALUES ({$placeholder_list})";
        $types = self::auto_detect_types($values);
        
        $result = self::query($query, $values, $types);
        
        if ($result) {
            // mysqli 使用属性 insert_id 获取最后插入ID
            return (int) self::$connection->insert_id;
        }
        
        return false;
    }
    
    /**
     * 更新数据
     * 
     * @param string $table 表名
     * @param array $data 数据数组
     * @param array $where 条件数组
     * @return bool 更新是否成功
     */
    public static function update($table, $data, $where) {
        if (empty($data) || empty($where)) {
            return false;
        }
        
        $columns_in_table = self::get_table_columns($table);
        if (!empty($columns_in_table)) {
            foreach (array_keys($data) as $k) {
                if (!in_array((string) $k, $columns_in_table, true)) {
                    unset($data[$k]);
                }
            }
            foreach (array_keys($where) as $k) {
                if (!in_array((string) $k, $columns_in_table, true)) {
                    unset($where[$k]);
                }
            }
        }
        if (empty($data) || empty($where)) {
            return false;
        }

        $table = self::escape_identifier($table);
        
        // 构建SET子句
        $set_clauses = array();
        $set_values = array();
        foreach ($data as $column => $value) {
            $set_clauses[] = "`{$column}` = ?";
            $set_values[] = $value;
        }
        $set_clause = implode(', ', $set_clauses);
        
        // 构建WHERE子句
        $where_clauses = array();
        $where_values = array();
        foreach ($where as $column => $value) {
            $where_clauses[] = "`{$column}` = ?";
            $where_values[] = $value;
        }
        $where_clause = implode(' AND ', $where_clauses);
        
        $query = "UPDATE {$table} SET {$set_clause} WHERE {$where_clause}";
        $all_values = array_merge($set_values, $where_values);
        $types = self::auto_detect_types($all_values);
        
        $result = self::query($query, $all_values, $types) !== false;
        
        if ($result) {
            self::clear_cache();
        }
        
        return $result;
    }
    
    /**
     * 删除数据
     * 
     * @param string $table 表名
     * @param array $where 条件数组
     * @return bool 删除是否成功
     */
    public static function delete($table, $where) {
        if (empty($where)) {
            return false;
        }
        
        $table = self::escape_identifier($table);
        
        // 构建WHERE子句
        $where_clauses = array();
        $where_values = array();
        foreach ($where as $column => $value) {
            $where_clauses[] = "`{$column}` = ?";
            $where_values[] = $value;
        }
        $where_clause = implode(' AND ', $where_clauses);
        
        $query = "DELETE FROM {$table} WHERE {$where_clause}";
        $types = self::auto_detect_types($where_values);
        
        $result = self::query($query, $where_values, $types) !== false;
        
        if ($result) {
            self::clear_cache();
        }
        
        return $result;
    }
    
    /**
     * 自动检测参数类型
     * 
     * @param array $params 参数数组
     * @return string 类型字符串
     */
    private static function auto_detect_types($params) {
        $types = '';
        
        foreach ($params as $param) {
            if (is_int($param)) {
                $types .= 'i';
            } elseif (is_float($param)) {
                $types .= 'd';
            } elseif (is_string($param)) {
                $types .= 's';
            } else {
                $types .= 'b'; // blob
            }
        }
        
        return $types;
    }
    
    /**
     * 转义标识符（表名、列名等）
     * 
     * @param string $identifier 标识符
     * @return string 转义后的标识符
     */
    private static function escape_identifier($identifier) {
        // 移除危险字符，只保留字母、数字、下划线
        $identifier = preg_replace('/[^a-zA-Z0-9_]/', '', $identifier);
        return "`{$identifier}`";
    }
    
    /**
     * 清空查询缓存
     */
    public static function clear_cache() {
        self::$query_cache = array();
        
        if (class_exists('Todo_DB_Query_Proxy')) {
            Todo_DB_Query_Proxy::clear_cache();
        }
    }
    
    /**
     * 启用/禁用查询缓存
     * 
     * @param bool $enabled 是否启用
     */
    public static function set_cache_enabled($enabled) {
        self::$cache_enabled = (bool) $enabled;
    }
    
    /**
     * 获取查询统计信息
     * 
     * @return array 统计信息
     */
    public static function get_query_stats() {
        return self::$query_stats;
    }
    
    /**
     * 重置查询统计
     */
    public static function reset_query_stats() {
        self::$query_stats = array(
            'total_queries' => 0,
            'cache_hits' => 0,
            'execution_time' => 0
        );
    }
    
    /**
     * 开始事务
     * 
     * @return bool 是否成功
     */
    public static function begin_transaction() {
        if (!self::$connection) {
            if (!self::init()) {
                return false;
            }
        }
        
        return self::$connection->autocommit(false);
    }
    
    /**
     * 提交事务
     * 
     * @return bool 是否成功
     */
    public static function commit() {
        if (!self::$connection) {
            return false;
        }
        
        $result = self::$connection->commit();
        self::$connection->autocommit(true);
        return $result;
    }
    
    /**
     * 回滚事务
     * 
     * @return bool 是否成功
     */
    public static function rollback() {
        if (!self::$connection) {
            return false;
        }
        
        $result = self::$connection->rollback();
        self::$connection->autocommit(true);
        return $result;
    }
    
    /**
     * 检查表是否存在
     * 注意：MySQL 原生预处理不支持在 SHOW/DESCRIBE 中使用占位符，这里改为查询 information_schema
     * 
     * @param string $table_name 表名
     * @return bool 表是否存在
     */
    public static function table_exists($table_name) {
        static $checked_tables = [];
        if (isset($checked_tables[$table_name])) {
            return $checked_tables[$table_name];
        }

        // Persistent cache check
        if (class_exists('Todo_Cache_Manager')) {
            $cache_key = 'table_exists_' . md5($table_name);
            $cached = Todo_Cache_Manager::get($cache_key, 'database', 'global');
            if ($cached !== false) {
                $checked_tables[$table_name] = $cached;
                return $cached;
            }
        }
        
        if (!self::$connection) {
            if (!self::init()) {
                return false;
            }
        }
        
        try {
            // 仅保留安全字符，避免传入反引号或其他符号
            $clean_table = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $table_name);
            $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : (defined('DB_PREFIX') ? DB_PREFIX : 'todo_');
            $full_table = (strpos($clean_table, $prefix) === 0) ? $clean_table : $prefix . $clean_table;
            
            // 通过 information_schema 检查当前数据库下是否存在该表
            $query = "SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?";
            $count = self::get_var($query, array($full_table), 's');
            
            $exists = (int)$count > 0;
            $checked_tables[$table_name] = $exists;
            $checked_tables[$full_table] = $exists;
            if (class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set('table_exists_' . md5($table_name), $exists, 86400, 'database', 'global');
                Todo_Cache_Manager::set('table_exists_' . md5($full_table), $exists, 86400, 'database', 'global');
            }
            return $exists;
        } catch (Exception $e) {
            error_log('Todo DB: table_exists error: ' . $e->getMessage());
            $checked_tables[$table_name] = false;
            return false;
        }
    }
    
    /**
     * 转义字符串以防止SQL注入
     * 
     * @param string $string 需要转义的字符串
     * @return string 转义后的字符串
     */
    public static function escape($string) {
        if (!self::$connection) {
            if (!self::init()) {
                return addslashes($string);
            }
        }
        
        return self::$connection->real_escape_string($string);
    }
    
    /**
     * 实例方法：转义字符串
     * 
     * @param string $string 需要转义的字符串
     * @return string 转义后的字符串
     */
    public function escape_string($string) {
        return self::escape($string);
    }
}

// 便捷函数
if (!function_exists('tododb_query')) {
    /**
     * 便捷函数：执行预处理查询
     */
    function tododb_query($query, $params = array(), $types = '') {
        return Todo_DB_Prepared::query($query, $params, $types);
    }
}

if (!function_exists('tododb_get_row')) {
    /**
     * 便捷函数：获取单行数据
     */
    function tododb_get_row($query, $params = array(), $types = '') {
        return Todo_DB_Prepared::get_row($query, $params, $types);
    }
}

if (!function_exists('tododb_get_results')) {
    /**
     * 便捷函数：获取多行数据
     */
    function tododb_get_results($query, $params = array(), $types = '') {
        return Todo_DB_Prepared::get_results($query, $params, $types);
    }
}

if (!function_exists('tododb_insert')) {
    /**
     * 便捷函数：插入数据
     */
    function tododb_insert($table, $data) {
        return Todo_DB_Prepared::insert($table, $data);
    }
}

if (!function_exists('tododb_update')) {
    /**
     * 便捷函数：更新数据
     */
    function tododb_update($table, $data, $where) {
        return Todo_DB_Prepared::update($table, $data, $where);
    }
}

if (!function_exists('tododb_delete')) {
    /**
     * 便捷函数：删除数据
     */
    function tododb_delete($table, $where) {
        return Todo_DB_Prepared::delete($table, $where);
    }
}
