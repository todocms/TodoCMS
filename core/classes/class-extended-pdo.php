<?php
/**
 * 扩展PDO类，添加replace方法
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

class Extended_PDO extends PDO {
    
    /**
     * 表前缀
     * @var string
     */
    public $prefix = '';
    
    /**
     * 最后的错误信息
     * @var string
     */
    public $last_error = '';
    
    /**
     * 构造函数
     */
    public function __construct($dsn, $username = null, $password = null, $options = null) {
        parent::__construct($dsn, $username, $password, $options);
        
        // 设置表前缀
        if (defined('TODO_TABLE_PREFIX')) {
            $this->prefix = TODO_TABLE_PREFIX;
        } elseif (defined('TABLE_PREFIX')) {
            $this->prefix = TABLE_PREFIX;
        } elseif (defined('DB_PREFIX')) {
            $this->prefix = DB_PREFIX;
        } else {
            $this->prefix = 'todo_';
        }
    }
    
    /**
     * Replace 方法 - 如果存在则更新，不存在则插入
     * 
     * @param string $table 表名
     * @param array $data 数据数组
     * @return bool|PDOStatement
     */
    public function replace($table, $data) {
        if (empty($data) || !is_array($data)) {
            return false;
        }
        
        // 构建字段和值的数组
        $fields = array_keys($data);
        $values = array_values($data);
        
        // 转义表名
        $table = str_replace('`', '``', $table);
        
        // 构建字段列表
        $field_list = '`' . implode('`, `', array_map(function($field) {
            return str_replace('`', '``', $field);
        }, $fields)) . '`';
        
        // 构建占位符
        $placeholders = str_repeat('?,', count($values) - 1) . '?';
        
        // 构建REPLACE查询
        $sql = "REPLACE INTO `{$table}` ({$field_list}) VALUES ({$placeholders})";
        
        try {
            $stmt = parent::prepare($sql);
            return $stmt->execute($values);
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();
            error_log('PDO Replace error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取单行数据
     * 
     * @param string $sql SQL语句
     * @param array $params 参数
     * @param string $output_type 输出类型：OBJECT（默认）或 ARRAY_A
     * @return object|array|null
     */
    public function get_row($sql, $params = [], $output_type = 'OBJECT') {
        if (class_exists('Todo_Performance_Monitor')) {
            Todo_Performance_Monitor::start('db');
        }
        try {
            // 兼容 TodoCMS 风格：第二参数传入 'ARRAY_A' 作为输出格式
            if (is_string($params) && $params === 'ARRAY_A') {
                $output_type = 'ARRAY_A';
                $params = [];
            }
            // 自动将 %d/%s/%f 转换为 PDO 占位符
            if (is_array($params) && !empty($params) && preg_match('/%[dfs]/', $sql)) {
                $sql = preg_replace('/%[dfs]/', '?', $sql);
            }
            $stmt = parent::prepare($sql);
            $stmt->execute(is_array($params) ? $params : []);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                return null;
            }
            
            // 根据输出类型返回不同格式
            if ($output_type === 'ARRAY_A') {
                return $result; // 直接返回关联数组
            } else {
                return (object) $result; // 返回stdClass对象
            }
        } catch (PDOException $e) {
            error_log('PDO get_row error: ' . $e->getMessage());
            return null;
        } finally {
            if (class_exists('Todo_Performance_Monitor')) {
                Todo_Performance_Monitor::end('db', false, substr($sql, 0, 100));
            }
        }
    }
    
    /**
     * 获取多行数据
     * 
     * @param string $sql SQL语句
     * @param array $params 参数
     * @param string $output_type 输出类型：OBJECT（默认）或 ARRAY_A
     * @return array
     */
    public function get_results($sql, $params = [], $output_type = 'OBJECT') {
        if (class_exists('Todo_Performance_Monitor')) {
            Todo_Performance_Monitor::start('db');
        }
        try {
            // 兼容 TodoCMS 风格：第二参数传入 'ARRAY_A' 作为输出格式
            if (is_string($params) && $params === 'ARRAY_A') {
                $output_type = 'ARRAY_A';
                $params = [];
            }
            // 自动将 %d/%s/%f 转换为 PDO 占位符
            if (is_array($params) && !empty($params) && preg_match('/%[dfs]/', $sql)) {
                $sql = preg_replace('/%[dfs]/', '?', $sql);
            }
            $stmt = parent::prepare($sql);
            $stmt->execute(is_array($params) ? $params : []);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if ($output_type === 'ARRAY_A') {
                return $results;
            }
            
            return array_map(function($row) {
                return (object) $row;
            }, $results);
        } catch (PDOException $e) {
            error_log('PDO get_results error: ' . $e->getMessage());
            return [];
        } finally {
            if (class_exists('Todo_Performance_Monitor')) {
                Todo_Performance_Monitor::end('db', false, substr($sql, 0, 100));
            }
        }
    }
    
    /**
     * 获取单个值
     * 
     * @param string $sql SQL语句
     * @param array $params 参数
     * @return mixed
     */
    public function get_var($sql, $params = []) {
        if (class_exists('Todo_Performance_Monitor')) {
            Todo_Performance_Monitor::start('db');
        }
        try {
            // 自动将 %d/%s/%f 转换为 PDO 占位符
            if (is_array($params) && !empty($params) && preg_match('/%[dfs]/', $sql)) {
                $sql = preg_replace('/%[dfs]/', '?', $sql);
            }
            $stmt = parent::prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('PDO get_var error: ' . $e->getMessage());
            return null;
        } finally {
            if (class_exists('Todo_Performance_Monitor')) {
                Todo_Performance_Monitor::end('db', false, substr($sql, 0, 100));
            }
        }
    }
    
    /**
     * 获取单列的所有值
     * 
     * @param string $sql SQL语句
     * @param array $params 参数
     * @param int $column_number 列号（从0开始）
     * @return array
     */
    public function get_col($sql, $params = [], $column_number = 0) {
        if (class_exists('Todo_Performance_Monitor')) {
            Todo_Performance_Monitor::start('db');
        }
        try {
            // 自动将 %d/%s/%f 转换为 PDO 占位符
            if (is_array($params) && !empty($params) && preg_match('/%[dfs]/', $sql)) {
                $sql = preg_replace('/%[dfs]/', '?', $sql);
            }
            $stmt = parent::prepare($sql);
            $stmt->execute($params);
            $results = [];
            while ($value = $stmt->fetchColumn($column_number)) {
                $results[] = $value;
            }
            return $results;
        } catch (PDOException $e) {
            error_log('PDO get_col error: ' . $e->getMessage());
            return [];
        } finally {
            if (class_exists('Todo_Performance_Monitor')) {
                Todo_Performance_Monitor::end('db', false, substr($sql, 0, 100));
            }
        }
    }
    
    /**
     * 插入数据
     * 
     * @param string $table 表名
     * @param array $data 数据数组
     * @return bool|int 成功返回插入的行数或最后插入的ID，失败返回false
     */
    public function insert($table, $data) {
        if (empty($data) || !is_array($data)) {
            return false;
        }
        
        // 构建字段和值的数组
        $fields = array_keys($data);
        $values = array_values($data);
        
        // 转义表名
        $table = str_replace('`', '``', $table);
        
        // 构建字段列表
        $field_list = '`' . implode('`, `', array_map(function($field) {
            return str_replace('`', '``', $field);
        }, $fields)) . '`';
        
        // 构建占位符
        $placeholders = str_repeat('?,', count($values) - 1) . '?';
        
        // 构建INSERT查询
        $sql = "INSERT INTO `{$table}` ({$field_list}) VALUES ({$placeholders})";
        
        try {
            $stmt = parent::prepare($sql);
            $result = $stmt->execute($values);
            return $result ? $this->lastInsertId() : false;
        } catch (PDOException $e) {
            error_log('PDO Insert error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 更新数据
     * 
     * @param string $table 表名
     * @param array $data 要更新的数据
     * @param array $where WHERE条件
     * @return bool|int 成功返回影响的行数，失败返回false
     */
    public function update($table, $data, $where) {
        if (empty($data) || !is_array($data) || empty($where) || !is_array($where)) {
            return false;
        }
        
        // 转义表名
        $table = str_replace('`', '``', $table);
        
        // 构建SET子句
        $set_clauses = [];
        $values = [];
        foreach ($data as $field => $value) {
            $escaped_field = str_replace('`', '``', $field);
            $set_clauses[] = "`{$escaped_field}` = ?";
            $values[] = $value;
        }
        $set_clause = implode(', ', $set_clauses);
        
        // 构建WHERE子句
        $where_clauses = [];
        foreach ($where as $field => $value) {
            $escaped_field = str_replace('`', '``', $field);
            $where_clauses[] = "`{$escaped_field}` = ?";
            $values[] = $value;
        }
        $where_clause = implode(' AND ', $where_clauses);
        
        // 构建UPDATE查询
        $sql = "UPDATE `{$table}` SET {$set_clause} WHERE {$where_clause}";
        
        try {
            $stmt = parent::prepare($sql);
            $result = $stmt->execute($values);
            return $result ? $stmt->rowCount() : false;
        } catch (PDOException $e) {
            error_log('PDO Update error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 删除数据
     * 
     * @param string $table 表名
     * @param array $where WHERE条件
     * @return bool|int 成功返回影响的行数，失败返回false
     */
    public function delete($table, $where) {
        if (empty($where) || !is_array($where)) {
            return false;
        }
        
        // 转义表名
        $table = str_replace('`', '``', $table);
        
        // 构建WHERE子句
        $where_clauses = [];
        $values = [];
        foreach ($where as $field => $value) {
            $escaped_field = str_replace('`', '``', $field);
            $where_clauses[] = "`{$escaped_field}` = ?";
            $values[] = $value;
        }
        $where_clause = implode(' AND ', $where_clauses);
        
        // 构建DELETE查询
        $sql = "DELETE FROM `{$table}` WHERE {$where_clause}";
        
        try {
            $stmt = parent::prepare($sql);
            $result = $stmt->execute($values);
            return $result ? $stmt->rowCount() : false;
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();
            error_log('PDO Delete error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 开始事务
     * 
     * @return bool
     */
    public function begin_transaction() {
        try {
            return $this->beginTransaction();
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();
            error_log('PDO begin_transaction error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 提交事务
     * 
     * @return bool
     */
    public function commit_transaction() {
        try {
            return $this->commit();
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();
            error_log('PDO commit error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 回滚事务
     * 
     * @return bool
     */
    public function rollback_transaction() {
        try {
            return $this->rollBack();
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();
            error_log('PDO rollback error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取数据库字符集和排序规则
     * 
     * @return string
     */
    public function get_charset_collate() {
        try {
            // 获取当前数据库的字符集
            $charset_result = $this->query("SELECT @@character_set_database as charset")->fetch(PDO::FETCH_ASSOC);
            $charset = $charset_result ? $charset_result['charset'] : 'utf8mb4';
            
            // 获取当前数据库的排序规则
            $collate_result = $this->query("SELECT @@collation_database as collate")->fetch(PDO::FETCH_ASSOC);
            $collate = $collate_result ? $collate_result['collate'] : 'utf8mb4_unicode_ci';
            
            return "DEFAULT CHARACTER SET {$charset} COLLATE {$collate}";
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();
            error_log('PDO get_charset_collate error: ' . $e->getMessage());
            // 返回默认值
            return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }
    }

    /**
     * 字符串格式化：将 %d/%f/%s 占位符替换为安全值，返回字符串 SQL
     * 与 CMS_DB::prepare 保持一致，用于全局统一调用。
     * 支持传入数组作为第二参数（例如 IN 子句）。
     */
    public function format($query, ...$args) {
        // 兼容传入数组参数的场景：$db->format($sql, $params)
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        if (empty($args)) {
            return $query;
        }
        $i = 0;
        return preg_replace_callback('/%([dfs])/', function ($matches) use (&$i, $args) {
            if (!isset($args[$i])) {
                return $matches[0];
            }
            $value = $args[$i++];
            switch ($matches[1]) {
                case 'd':
                    return (string) (int) $value;
                case 'f':
                    // 保留浮点表示，避免科学计数法异常
                    return (string) (float) $value;
                case 's':
                    // 防止数组/对象被错误拼接
                    if (is_array($value) || is_object($value)) {
                        return "''";
                    }
                    // 使用 PDO::quote 进行转义并返回带引号的字符串
                    return $this->quote((string) $value);
                default:
                    return $matches[0];
            }
        }, $query);
    }

    /**
     * 提供一个真正的 PDO 预处理别名，便于类外调用（可选）
     */
    public function pdo_prepare($sql, $options = []) {
        return parent::prepare($sql, $options);
    }

    /**
     * 检查数据表是否存在（传入不带前缀的表名）
     * @param string $table_without_prefix 不带前缀的表名
     * @return bool
     */
    public function table_exists($table_without_prefix) {
        static $checked_tables = null;
        if ($checked_tables === null) {
            $checked_tables = [];
            try {
                $stmt = $this->query("SHOW TABLES");
                if ($stmt) {
                    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($tables as $t) {
                        $checked_tables[$t] = true;
                        $unp = (strpos($t, $this->prefix) === 0) ? substr($t, strlen($this->prefix)) : $t;
                        $checked_tables[$unp] = true;
                    }
                    $stmt->closeCursor();
                }
            } catch (Exception $e) {}
        }
        $full_table_name = (strpos($table_without_prefix, $this->prefix) === 0)
            ? $table_without_prefix
            : $this->prefix . $table_without_prefix;

        if (isset($checked_tables[$full_table_name])) {
            return $checked_tables[$full_table_name];
        }
        if (isset($checked_tables[$table_without_prefix])) {
            return $checked_tables[$table_without_prefix];
        }

        try {
            // 使用 PDO::quote 处理 LIKE 参数，避免特殊字符问题
            $escaped = $this->quote($full_table_name);
            $stmt = $this->query("SHOW TABLES LIKE $escaped");
            $exists = ($stmt && $stmt->fetchColumn() !== false);
            if ($stmt) { $stmt->closeCursor(); }
            $checked_tables[$full_table_name] = $exists;
            $checked_tables[$table_without_prefix] = $exists;
            return $exists;
        } catch (PDOException $e) {
            // 记录错误但返回 false
            $this->last_error = $e->getMessage();
            error_log('PDO table_exists error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 转义 LIKE 查询中的通配符字符（%, _ 和反斜杠）
     * 仅用于构造 LIKE 模式字符串的参数，不进行完整的SQL转义（使用预处理绑定）。
     */
    public function escape_like($string) {
        $s = (string) $string;
        // 先转义反斜杠，再转义 % 和 _
        $s = str_replace('\\', '\\\\', $s);
        $s = str_replace(['%', '_'], ['\\%', '\\_'], $s);
        return $s;
    }

    /**
     * 别名：esc_like
     */
    public function esc_like($string) {
        return $this->escape_like($string);
    }

    /**
     * 获取最后插入的自增ID（与 CMS_DB::insert_id 兼容）
     */
    public function insert_id() {
        try {
            return (int) $this->lastInsertId();
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();
            return 0;
        }
    }
}