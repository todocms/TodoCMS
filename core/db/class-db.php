<?php
/**
 * 数据库类
 *
 * 处理数据库连接和查询操作
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 定义常量
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

class CMS_DB
{
    /**
     * 单例实例
     *
     * @var CMS_DB
     */
    private static $instance = null;

    /**
     * 数据库连接
     *
     * @var PDO
     */
    private $connection;

    /**
     * 最后一个SQL错误
     *
     * @var string
     */
    private $last_error = '';

    /**
     * 最后一个查询ID
     *
     * @var int
     */
    private $last_query_id = 0;

    /**
     * 查询计数
     *
     * @var int
     */
    private $query_count = 0;

    /**
     * 数据库表前缀
     *
     * @var string
     */
    public $prefix;

    /**
     * 活动的PDO语句
     *
     * @var array
     */
    private $active_statements = [];

    /**
     * 查询日志（用于调试）
     *
     * @var array
     */
    private $query_log = [];

    /**
     * 是否启用查询日志
     *
     * @var bool
     */
    private $enable_query_log = false;

    /**
     * 获取单例实例
     *
     * @return CMS_DB
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        $desired_prefix = self::resolve_table_prefix();
        if (!isset(self::$instance->prefix) || self::$instance->prefix !== $desired_prefix) {
            self::$instance->prefix = $desired_prefix;
        }
        return self::$instance;
    }

    private static function resolve_table_prefix()
    {
        $prefix = null;
        if (defined('TABLE_PREFIX')) {
            $prefix = TABLE_PREFIX;
        } elseif (defined('DB_PREFIX')) {
            $prefix = DB_PREFIX;
        }
        $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($prefix ?? ''));
        if ($prefix === '') {
            $prefix = 'todo_';
        }
        return $prefix;
    }

    /**
     * 构造函数
     *
     * 初始化数据库连接
     * 注意：为了向后兼容，仍然允许 new CMS_DB()，但会使用单例实例
     */
    public function __construct()
    {
        // 如果已经有实例，不重新初始化（向后兼容）
        if (self::$instance !== null) {
            // 将当前对象的属性指向单例实例的属性
            // 注意：PHP不允许构造函数返回值，所以我们让新对象保持独立
            // 但实际使用时应该通过 get_instance() 获取单例
            $this->connection = self::$instance->connection;
            $this->last_error = self::$instance->last_error;
            $this->prefix = self::$instance->prefix;
            $this->enable_query_log = self::$instance->enable_query_log;
            return;
        }

        // 启用查询日志（用于调试N+1查询问题）
        if (defined('TODO_DEBUG') && TODO_DEBUG) {
            $this->enable_query_log = true;
        }

        if (!$this->connect()) {
            // 记录详细错误（内部日志）
            $detailed_error = sprintf(
                '数据库连接失败: %s | 主机: %s | 数据库: %s | 用户: %s',
                $this->last_error,
                defined('DB_HOST') ? DB_HOST : '未定义',
                defined('DB_NAME') ? DB_NAME : '未定义',
                defined('DB_USER') ? DB_USER : '未定义'
            );
            error_log('TodoCMS Error [Database]: ' . $detailed_error);

            // 在安装过程中，允许连接失败但不抛出异常，以便安装流程可以继续
            if (defined('INSTALLING') && INSTALLING) {
                // 安装过程中，记录错误但不抛出异常
                error_log('数据库连接失败（安装模式）: ' . $this->last_error);
                // 不设置单例实例，因为连接失败
                return;
            }

            // 非安装模式：抛出异常，但使用用户友好的错误信息
            $user_message = '数据库连接失败，请检查配置';
            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                $user_message = '数据库连接失败: ' . $this->last_error;
            }
            throw new Exception($user_message);
        }

        $this->prefix = self::resolve_table_prefix();

        // 设置单例实例
        self::$instance = $this;
    }

    /**
     * 连接到数据库
     *
     * @return bool 是否成功连接
     */
    private function connect()
    {
        try {
            // 检查数据库配置常量是否已定义
            if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASSWORD')) {
                // 尝试加载配置文件
                $config_file = defined('ABSPATH') ? ABSPATH . 'config.php' : dirname(dirname(__DIR__)) . '/config.php';
                if (file_exists($config_file)) {
                    require_once $config_file;
                    // 加载配置文件后，再次检查常量是否已定义
                    if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASSWORD')) {
                        // 如果是在安装过程中，提供更友好的错误信息
                        if (defined('INSTALLING') && INSTALLING) {
                            $this->last_error = '配置文件已加载，但数据库配置常量未定义。请检查 config.php 文件内容是否正确。';
                            return false;
                        }
                        throw new Exception('数据库配置文件已加载，但配置常量未定义。请检查 config.php 文件内容是否正确。');
                    }
                } else {
                    // 如果是在安装过程中，配置文件不存在是正常的（在步骤1和步骤2）
                    if (defined('INSTALLING') && INSTALLING) {
                        $this->last_error = '数据库配置文件不存在。请先完成安装步骤2：数据库配置。';
                        return false;
                    }
                    throw new Exception('数据库配置文件不存在或配置常量未定义');
                }
            }

            // 构建DSN
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . (defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');

            // 连接选项
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ];

            // 使用数据库连接管理器获取连接
            if (!function_exists('todo_get_db_connection')) {
                $manager_path = defined('ABSPATH') ? ABSPATH . 'core/classes/class-db-connection-manager.php' : dirname(dirname(__DIR__)) . '/core/classes/class-db-connection-manager.php';
                if (file_exists($manager_path)) {
                    require_once $manager_path;
                }
            }
            $this->connection = todo_get_db_connection();

            return true;
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();

            // 如果在调试模式下，记录错误到日志而不是直接输出
            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                error_log('数据库连接错误: ' . $this->last_error);
            }

            return false;
        } catch (Exception $e) {
            // 捕获其他类型的异常（如配置相关的异常）
            $this->last_error = $e->getMessage();

            // 如果在调试模式下，记录错误到日志而不是直接输出
            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                error_log('数据库连接错误: ' . $this->last_error);
            }

            // 在安装过程中，不抛出异常，而是返回 false
            // 非安装模式下也返回 false，让构造函数决定是否抛出异常
            return false;
        }
    }

    /**
     * 检查是否在事务中
     *
     * @return bool 是否在事务中
     */
    public function in_transaction()
    {
        return $this->connection->inTransaction();
    }

    /**
     * 确保没有活动的事务
     *
     * @return bool 是否成功
     */
    public function ensure_no_transaction()
    {
        if ($this->in_transaction()) {
            try {
                $this->rollback();
            } catch (PDOException $e) {
                // 忽略回滚错误
            }
        }
        return true;
    }

    /**
     * 清理活动的PDO语句
     * 解决SQLSTATE[HY000]: General error: 2031错误
     */
    private function cleanup_statements()
    {
        foreach ($this->active_statements as $stmt) {
            if ($stmt instanceof PDOStatement) {
                try {
                    $stmt->closeCursor();
                } catch (PDOException $e) {
                    // 忽略关闭游标错误
                }
            }
        }
        $this->active_statements = [];
    }

    /**
     * 开始事务
     *
     * @return bool 是否成功
     */
    public function begin_transaction()
    {
        // 确保没有活动的事务
        $this->ensure_no_transaction();
        return $this->connection->beginTransaction();
    }

    /**
     * 提交事务
     *
     * @return bool 是否成功
     */
    public function commit()
    {
        if (!$this->in_transaction()) {
            return false;
        }
        return $this->connection->commit();
    }

    /**
     * 回滚事务
     *
     * @return bool 是否成功
     */
    public function rollback()
    {
        if (!$this->in_transaction()) {
            return false;
        }
        return $this->connection->rollBack();
    }

    /**
     * 执行查询
     *
     * @param string $query SQL查询
     * @param array|string $params 查询参数
     * @return mixed 查询结果或false
     */
    public function query($query, $params = [])
    {
        try {
            // 清空之前的错误
            $this->last_error = '';

            // 检查数据库连接
            if (!$this->is_connected()) {
                $this->last_error = 'Database connection is not active.';
                return false;
            }

            if ($this->demo_should_block_query($query)) {
                $this->last_error = 'Demo account restricted query.';
                return false;
            }

            // 清理之前的语句
            $this->cleanup_statements();

            // 准备语句
            try {
                $stmt = $this->connection->prepare($query);
            } catch (PDOException $e) {
                $this->last_error = $e->getMessage();
                // 检查是否是非致命错误（表/列不存在，通常是动态查询产生的）
                if (
                    strpos($this->last_error, "doesn't exist") !== false ||
                    strpos($this->last_error, 'Base table or view not found') !== false ||
                    strpos($this->last_error, 'Unknown column') !== false
                ) {
                    if (defined('TODO_DEBUG') && TODO_DEBUG) error_log('Non-fatal SQL error in query: ' . $this->last_error . ' | Query: ' . substr($query, 0, 200));
                } else {
                    error_log('PDO prepare error in query: ' . $this->last_error . ' | Query: ' . substr($query, 0, 200));
                }
                return false;
            }

            if (!($stmt instanceof PDOStatement)) {
                if (is_string($stmt)) {
                    try {
                        $stmt = $this->connection->prepare((string) $stmt);
                    } catch (PDOException $e) {
                        $this->last_error = $e->getMessage();
                        error_log('PDO prepare error (retry) in query: ' . $this->last_error);
                        return false;
                    }
                }
            }
            if (!($stmt instanceof PDOStatement)) {
                $this->last_error = 'Failed to prepare PDO statement in query';
                error_log($this->last_error . ' | Query: ' . substr($query, 0, 200));
                return false;
            }

            // 确保参数是数组
            if (!is_array($params)) {
                $params = empty($params) ? [] : [$params];
            }

            // 验证参数数量是否匹配SQL中的占位符
            $placeholder_count = substr_count($query, '?');
            if ($placeholder_count > 0 && count($params) !== $placeholder_count) {
                $error_msg = "Parameter count mismatch in get_col: SQL has {$placeholder_count} placeholders but " . count($params) . " parameters provided";
                if (defined('TODO_DEBUG') && TODO_DEBUG) {
                    $error_msg .= "\nSQL: " . substr($query, 0, 200);
                    $error_msg .= "\nParams: " . print_r($params, true);
                    $error_msg .= "\nBacktrace: " . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5), true);
                }
                error_log($error_msg);
                throw new PDOException($error_msg);
            }

            // 处理NULL值参数，避免datetime字段的空字符串错误
            $params = $this->process_datetime_params($params, $query);

            // 记录性能监控开始
            if (class_exists('Todo_Performance_Monitor')) {
                Todo_Performance_Monitor::start('db');
            }

            // 执行查询（如果没有占位符，传递空数组）
            try {
                $this->maybe_simulate_prod_latency();
                $stmt->execute($placeholder_count > 0 ? $params : []);
            } catch (PDOException $e) {
                $this->last_error = $e->getMessage();

                // 记录性能监控结束（即使出错也要结束）
                if (class_exists('Todo_Performance_Monitor')) {
                    Todo_Performance_Monitor::end('db', false, substr($query, 0, 100));
                }

                // 检查是否是非致命错误（表/列不存在，通常是动态查询产生的）
                if (
                    strpos($this->last_error, "doesn't exist") !== false ||
                    strpos($this->last_error, 'Base table or view not found') !== false ||
                    strpos($this->last_error, 'Unknown column') !== false
                ) {
                    if (defined('TODO_DEBUG') && TODO_DEBUG) error_log('Non-fatal SQL error in query execute: ' . $this->last_error . ' | Query: ' . substr($query, 0, 200));
                } else {
                    if (defined('TODO_DEBUG') && TODO_DEBUG) {
                        error_log('PDO execute error in query: ' . $this->last_error . ' | Query: ' . substr($query, 0, 200));
                    }
                }

                $stmt->closeCursor();
                return false;
            }

            // 记录性能监控结束
            if (class_exists('Todo_Performance_Monitor')) {
                Todo_Performance_Monitor::end('db', false, substr($query, 0, 100));
            }

            // 增加查询计数
            $this->query_count++;

            // 记录查询日志
            $this->log_query($query, $params);

            // 保存最后一个查询ID
            $this->last_query_id = $this->connection->lastInsertId();

            // 如果是SELECT查询，返回结果集
            if (stripos(trim($query), 'SELECT') === 0) {
                // 显式指定为 FETCH_ASSOC，避免"mode must be an integer"错误
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $stmt->closeCursor();
                return $result;
            }

            // 对于其他查询，返回受影响的行数
            $rowCount = $stmt->rowCount();
            $stmt->closeCursor();
            return $rowCount;
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();

            // 如果在调试模式下，记录错误到日志而不是直接输出
            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                error_log('查询错误: ' . $this->last_error);
            }

            return false;
        }
    }

    /**
     * 获取查询结果集
     *
     * @param string $query SQL查询
     * @param array|string $params 查询参数或输出格式（兼容第二参数传 'ARRAY_A' 的旧用法）
     * @param string $output_type 输出类型：'OBJECT'（默认）或 'ARRAY_A'
     * @return array 查询结果集
     */
    public function get_results($query, $params = [], $output_type = 'OBJECT')
    {
        // 兼容旧用法：当第二参数为输出格式字符串（如 'ARRAY_A' / 'OBJECT' / 'ARRAY_N'）时，自动调整参数和输出类型
        if (is_string($params) && in_array(strtoupper($params), ['ARRAY_A', 'OBJECT', 'ARRAY_N'], true)) {
            $output_type = strtoupper($params);
            $params = [];
        }

        // 查询批处理器（深度优化）- 在拦截器之前处理
        if (class_exists('Todo_Query_Batch_Processor') && Todo_Query_Batch_Processor::is_enabled()) {
            $actual_params = is_array($params) ? $params : (is_string($params) && in_array(strtoupper($params), ['ARRAY_A', 'OBJECT', 'ARRAY_N'], true) ? [] : (empty($params) ? [] : [$params]));
            return Todo_Query_Batch_Processor::add_to_batch($query, $actual_params, function ($q, $p) use ($output_type) {
                // 继续走拦截器流程
                return $this->get_results_with_interceptor($q, $p, $output_type);
            });
        }

        return $this->get_results_with_interceptor($query, $params, $output_type);
    }

    /**
     * 带拦截器的查询（内部方法）
     */
    private function get_results_with_interceptor($query, $params = [], $output_type = 'OBJECT')
    {
        // 深度优化：检查查询结果持久化缓存
        if (class_exists('Todo_Query_Result_Cache')) {
            $cached = Todo_Query_Result_Cache::get($query, $params, 'get_results', $output_type);
            if ($cached !== null) {
                return $cached;
            }
        }

        // 查询拦截器（性能优化）- 在方法开头拦截
        if (class_exists('Todo_DB_Query_Interceptor') && Todo_DB_Query_Interceptor::is_enabled()) {
            $actual_params = is_array($params) ? $params : (is_string($params) && in_array(strtoupper($params), ['ARRAY_A', 'OBJECT', 'ARRAY_N'], true) ? [] : (empty($params) ? [] : [$params]));
            $result = Todo_DB_Query_Interceptor::intercept('get_results', $query, $actual_params, function ($q, $p) use ($output_type) {
                // 递归调用会跳过拦截器，直接执行内部方法
                return $this->get_results_direct($q, $p, $output_type);
            }, $output_type);
            // 深度优化：保存到持久化缓存
            if (class_exists('Todo_Query_Result_Cache')) {
                Todo_Query_Result_Cache::set($query, $actual_params, $result, null, 'get_results', $output_type);
            }
            return $result;
        }

        $result = $this->get_results_direct($query, $params, $output_type);

        // 深度优化：保存到持久化缓存
        if (class_exists('Todo_Query_Result_Cache')) {
            Todo_Query_Result_Cache::set($query, $params, $result, null, 'get_results', $output_type);
        }

        return $result;
    }

    /**
     * 直接执行get_results（绕过拦截器）
     */
    private function get_results_direct($query, $params = [], $output_type = 'OBJECT')
    {
        try {
            // 检查数据库连接
            if (!$this->is_connected()) {
                $this->last_error = 'Database connection is not active.';
                return [];
            }

            if ($this->demo_should_block_query($query)) {
                $this->last_error = 'Demo account restricted query.';
                return [];
            }

            // 清理之前的语句
            $this->cleanup_statements();

            // 验证查询字符串
            if (empty($query) || !is_string($query)) {
                $error_msg = "Invalid query in get_results: query is empty or not a string";
                if (defined('TODO_DEBUG') && TODO_DEBUG) {
                    $error_msg .= "\nQuery type: " . gettype($query);
                    $error_msg .= "\nQuery value: " . var_export($query, true);
                    $error_msg .= "\nBacktrace: " . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5), true);
                }
                error_log($error_msg);
                $this->last_error = $error_msg;
                return [];
            }

            // 清理查询字符串（去除前后空白）
            $query = trim($query);
            if (empty($query)) {
                $error_msg = "Empty query string in get_results after trim";
                error_log($error_msg);
                $this->last_error = $error_msg;
                return [];
            }

            // 输出格式与参数处理
            $output_format = ($output_type === 'ARRAY_A') ? PDO::FETCH_ASSOC : PDO::FETCH_OBJ;
            $actual_params = [];

            // 兼容旧用法：当第二参数为输出格式（如 OBJECT, ARRAY_A 等）时，作为输出格式而不是查询参数
            if (is_string($params) && in_array(strtoupper($params), ['ARRAY_A', 'OBJECT', 'ARRAY_N'], true)) {
                $output_format = (strtoupper($params) === 'ARRAY_A') ? PDO::FETCH_ASSOC : PDO::FETCH_OBJ;
                $actual_params = [];
            } elseif (is_array($params)) {
                $actual_params = $params;
            } else {
                $actual_params = empty($params) ? [] : [$params];
            }

            // 验证参数数量是否匹配SQL中的占位符
            $placeholder_count = substr_count($query, '?');
            if ($placeholder_count > 0 && count($actual_params) !== $placeholder_count) {
                $error_msg = "Parameter count mismatch in get_results: SQL has {$placeholder_count} placeholders but " . count($actual_params) . " parameters provided";
                if (defined('TODO_DEBUG') && TODO_DEBUG) {
                    $error_msg .= "\nSQL: " . substr($query, 0, 200);
                    $error_msg .= "\nParams: " . print_r($actual_params, true);
                    $error_msg .= "\nBacktrace: " . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5), true);
                }
                error_log($error_msg);
                throw new PDOException($error_msg);
            }

            // 准备语句
            try {
                $stmt = $this->connection->prepare($query);
            } catch (PDOException $e) {
                // prepare() 阶段也可能抛出异常（当 ATTR_EMULATE_PREPARES 为 false 时，MySQL 会立即验证 SQL）
                $this->last_error = $e->getMessage();
                // 检查是否是非致命错误（表/列不存在，通常是动态查询产生的）
                if (
                    strpos($this->last_error, "doesn't exist") !== false ||
                    strpos($this->last_error, 'Base table or view not found') !== false ||
                    strpos($this->last_error, 'Unknown column') !== false
                ) {
                    if (defined('TODO_DEBUG') && TODO_DEBUG) error_log('Non-fatal SQL error in get_results prepare: ' . $this->last_error . ' | Query: ' . substr($query, 0, 200));
                } else {
                    // 提供更详细的错误信息
                    $error_details = 'PDO prepare error in get_results: ' . $this->last_error;
                    $error_details .= ' | Query: ' . substr($query, 0, 500);
                    $error_details .= ' | Query length: ' . strlen($query);
                    $error_details .= ' | Placeholder count: ' . $placeholder_count;
                    $error_details .= ' | Params count: ' . count($actual_params);
                    if (defined('TODO_DEBUG') && TODO_DEBUG) {
                        $error_details .= ' | Full query: ' . $query;
                        $error_details .= ' | Params: ' . print_r($actual_params, true);
                        $error_details .= ' | Backtrace: ' . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3), true);
                    }
                    error_log($error_details);
                }
                return [];
            } catch (Exception $e) {
                // 捕获所有其他类型的异常
                $this->last_error = $e->getMessage();
                $error_details = 'Exception in get_results prepare: ' . $this->last_error;
                $error_details .= ' | Query: ' . substr($query, 0, 500);
                if (defined('TODO_DEBUG') && TODO_DEBUG) {
                    $error_details .= ' | Full query: ' . $query;
                    $error_details .= ' | Backtrace: ' . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3), true);
                }
                error_log($error_details);
                return [];
            }

            if (!($stmt instanceof PDOStatement)) {
                if (is_string($stmt)) {
                    try {
                        $stmt = $this->connection->prepare((string) $stmt);
                    } catch (PDOException $e) {
                        $this->last_error = $e->getMessage();
                        error_log('PDO prepare error (retry) in get_results: ' . $this->last_error);
                        return [];
                    }
                }
            }
            if (!($stmt instanceof PDOStatement)) {
                $this->last_error = 'Failed to prepare PDO statement in get_results';
                error_log($this->last_error . ' | Query: ' . substr($query, 0, 200));
                return [];
            }

            // 处理NULL值参数，避免datetime字段的空字符串错误
            $actual_params = $this->process_datetime_params($actual_params, $query);

            // 记录性能监控开始
            if (class_exists('Todo_Performance_Monitor')) {
                Todo_Performance_Monitor::start('db');
            }

            // 执行查询（如果没有占位符，传递空数组）
            try {
                $this->maybe_simulate_prod_latency();
                $stmt->execute($placeholder_count > 0 ? $actual_params : []);
            } catch (PDOException $e) {
                // execute() 阶段抛出异常（如表不存在等）
                $this->last_error = $e->getMessage();

                // 记录性能监控结束（即使出错也要结束）
                if (class_exists('Todo_Performance_Monitor')) {
                    Todo_Performance_Monitor::end('db', false, substr($query, 0, 100));
                }

                // 检查是否是非致命错误（表/列不存在，通常是动态查询产生的）
                if (
                    strpos($this->last_error, "doesn't exist") !== false ||
                    strpos($this->last_error, 'Base table or view not found') !== false ||
                    strpos($this->last_error, 'Unknown column') !== false
                ) {
                    // 非致命错误（表/列不存在），记录日志但不抛出异常，返回空数组
                    if (defined('TODO_DEBUG') && TODO_DEBUG) error_log('Non-fatal SQL error in get_results: ' . $this->last_error . ' | Query: ' . substr($query, 0, 200));
                } else {
                    // 其他数据库错误
                    if (defined('TODO_DEBUG') && TODO_DEBUG) {
                        error_log('PDO execute error in get_results: ' . $this->last_error . ' | Query: ' . substr($query, 0, 200));
                    }
                }

                $stmt->closeCursor();
                return [];
            }

            // 记录性能监控结束
            $execution_time = 0;
            if (class_exists('Todo_Performance_Monitor')) {
                $end_result = Todo_Performance_Monitor::end('db', false, substr($query, 0, 100));
                // end()方法返回秒，需要转换为毫秒
                if (is_numeric($end_result)) {
                    $execution_time = (float) $end_result * 1000; // 转换为毫秒
                }
            }

            // 深度优化：分析查询执行计划（仅对慢查询）
            if (class_exists('Todo_Query_Plan_Optimizer') && $execution_time > 0) {
                Todo_Query_Plan_Optimizer::analyze($query, $actual_params, $execution_time);
            }

            // 增加查询计数
            $this->query_count++;

            // 记录查询日志
            $this->log_query($query, $actual_params);

            // 返回结果集（统一以 FETCH_ASSOC 获取，再按需要转换，避免"mode must be an integer"错误）
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($output_format === PDO::FETCH_ASSOC) {
                $result = $rows;
            } else {
                $result = array_map(function ($row) {
                    return (object) $row;
                }, $rows);
            }
            $stmt->closeCursor();
            return $result;
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();

            // 如果在调试模式下，记录错误到日志而不是直接输出
            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                error_log('PDOException in get_results: ' . $this->last_error . ' | Query: ' . substr($query, 0, 200));
            }

            return [];
        } catch (Exception $e) {
            // 捕获所有其他类型的异常
            $this->last_error = $e->getMessage();
            error_log('Exception in get_results: ' . $this->last_error . ' | Query: ' . substr($query, 0, 200));
            return [];
        }
    }

    /**
     * 获取查询结果并转换为索引数组（优化N+1查询）
     * 类似ZanCms的getAllWithIndex，根据指定字段建立索引，方便快速查找
     *
     * @param string $query SQL查询
     * @param array|string $params 查询参数
     * @param string $index_field 用于建立索引的字段名（如 'ID', 'post_id'）
     * @param string $output_type 输出类型：'OBJECT'（默认）或 'ARRAY_A'
     * @return array 索引数组，格式为 [index_value => row_data, ...]
     * @example
     *   $results = $db->get_results_indexed("SELECT * FROM posts WHERE ID IN (1,2,3)", [], 'ID');
     *   // 结果: [1 => (object)post1, 2 => (object)post2, 3 => (object)post3]
     *   // 使用: $post = $results[1]; // O(1) 快速查找
     */
    public function get_results_indexed($query, $params = [], $index_field = 'ID', $output_type = 'OBJECT')
    {
        // 先获取结果
        $results = $this->get_results($query, $params, $output_type);

        if (empty($results)) {
            return [];
        }

        // 转换为索引数组
        $indexed = [];
        foreach ($results as $row) {
            // 处理对象和数组两种情况
            if ($output_type === 'ARRAY_A' || is_array($row)) {
                $key = isset($row[$index_field]) ? $row[$index_field] : null;
                if ($key !== null) {
                    $indexed[$key] = $row;
                }
            } else {
                // 对象格式
                $key = isset($row->$index_field) ? $row->$index_field : null;
                if ($key !== null) {
                    $indexed[$key] = $row;
                }
            }
        }

        return $indexed;
    }

    /**
     * 获取单行数据
     *
     * @param string $query SQL查询
     * @param array|string $params 查询参数
     * @param string $output_type 输出类型：OBJECT（默认）或 ARRAY_A
     * @return object|array|null 单行数据对象、数组或null
     */
    public function get_row($query, $params = [], $output_type = 'OBJECT')
    {
        // 兼容旧用法：当第二参数为输出格式字符串（如 'ARRAY_A' / 'OBJECT' / 'ARRAY_N'）时，自动调整参数和输出类型
        if (is_string($params) && in_array(strtoupper($params), ['ARRAY_A', 'OBJECT', 'ARRAY_N'], true)) {
            $output_type = strtoupper($params);
            $params = [];
        }

        // 查询拦截器（性能优化）
        if (class_exists('Todo_DB_Query_Interceptor') && Todo_DB_Query_Interceptor::is_enabled()) {
            $actual_params = is_array($params) ? $params : (empty($params) ? [] : [$params]);
            return Todo_DB_Query_Interceptor::intercept('get_row', $query, $actual_params, function ($q, $p) use ($output_type) {
                return $this->get_row_direct($q, $p, $output_type);
            }, $output_type);
        }

        return $this->get_row_direct($query, $params, $output_type);
    }

    /**
     * 直接执行get_row（绕过拦截器）
     */
    private function get_row_direct($query, $params = [], $output_type = 'OBJECT')
    {
        try {
            // 检查数据库连接
            if (!$this->is_connected()) {
                $this->last_error = 'Database connection is not active.';
                return null;
            }

            if ($this->demo_should_block_query($query)) {
                $this->last_error = 'Demo account restricted query.';
                return null;
            }

            // 清理之前的语句
            $this->cleanup_statements();

            // 验证查询字符串
            if (empty($query) || !is_string($query)) {
                $error_msg = "Invalid query in get_row: query is empty or not a string";
                if (defined('TODO_DEBUG') && TODO_DEBUG) {
                    $error_msg .= "\nQuery type: " . gettype($query);
                    $error_msg .= "\nQuery value: " . var_export($query, true);
                    $error_msg .= "\nBacktrace: " . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5), true);
                }
                error_log($error_msg);
                $this->last_error = $error_msg;
                return null;
            }

            // 清理查询字符串（去除前后空白）
            $query = trim($query);
            if (empty($query)) {
                $error_msg = "Empty query string in get_row after trim";
                error_log($error_msg);
                $this->last_error = $error_msg;
                return null;
            }

            // 兼容第二参数为输出格式（与get_results一致）：当传入 'ARRAY_A' 时，作为输出格式而不是查询参数
            if (is_string($params) && ($params === 'ARRAY_A' || (defined('ARRAY_A') && $params === ARRAY_A))) {
                $output_type = 'ARRAY_A';
                $params = [];
            }

            // 准备语句
            try {
                $stmt = $this->connection->prepare($query);
            } catch (PDOException $e) {
                $this->last_error = $e->getMessage();
                // 检查是否是非致命错误（表/列不存在，通常是动态查询产生的）
                if (
                    strpos($this->last_error, "doesn't exist") !== false ||
                    strpos($this->last_error, 'Base table or view not found') !== false ||
                    strpos($this->last_error, 'Unknown column') !== false
                ) {
                    if (defined('TODO_DEBUG') && TODO_DEBUG) error_log('Non-fatal SQL error in get_row: ' . $this->last_error . ' | Query: ' . substr($query, 0, 200));
                } else {
                    // 提供更详细的错误信息
                    $error_details = 'PDO prepare error in get_row: ' . $this->last_error;
                    $error_details .= ' | Query: ' . substr($query, 0, 500);
                    $error_details .= ' | Query length: ' . strlen($query);
                    if (defined('TODO_DEBUG') && TODO_DEBUG) {
                        $error_details .= ' | Full query: ' . $query;
                        $error_details .= ' | Params: ' . print_r($params, true);
                        $error_details .= ' | Backtrace: ' . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3), true);
                    }
                    error_log($error_details);
                }
                return null;
            }

            if (!($stmt instanceof PDOStatement)) {
                if (is_string($stmt)) {
                    try {
                        $stmt = $this->connection->prepare((string) $stmt);
                    } catch (PDOException $e) {
                        $this->last_error = $e->getMessage();
                        error_log('PDO prepare error (retry) in get_row: ' . $this->last_error);
                        return null;
                    }
                }
            }
            if (!($stmt instanceof PDOStatement)) {
                $this->last_error = 'Failed to prepare PDO statement in get_row';
                error_log($this->last_error . ' | Query: ' . substr($query, 0, 200));
                return null;
            }

            // 确保参数是数组
            if (!is_array($params)) {
                $params = empty($params) ? [] : [$params];
            }

            // 验证参数数量是否匹配SQL中的占位符
            $placeholder_count = substr_count($query, '?');
            if ($placeholder_count > 0 && count($params) !== $placeholder_count) {
                $error_msg = "Parameter count mismatch in get_row: SQL has {$placeholder_count} placeholders but " . count($params) . " parameters provided";
                if (defined('TODO_DEBUG') && TODO_DEBUG) {
                    $error_msg .= "\nSQL: " . substr($query, 0, 200);
                    $error_msg .= "\nParams: " . print_r($params, true);
                    $error_msg .= "\nBacktrace: " . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5), true);
                }
                error_log($error_msg);
                throw new PDOException($error_msg);
            }

            // 处理NULL值参数，避免datetime字段的空字符串错误
            $params = $this->process_datetime_params($params, $query);

            // 记录性能监控开始
            if (class_exists('Todo_Performance_Monitor')) {
                Todo_Performance_Monitor::start('db');
            }

            // 执行查询（如果没有占位符，传递空数组）
            try {
                $this->maybe_simulate_prod_latency();
                $stmt->execute($placeholder_count > 0 ? $params : []);
            } catch (PDOException $e) {
                $this->last_error = $e->getMessage();

                // 记录性能监控结束（即使出错也要结束）
                if (class_exists('Todo_Performance_Monitor')) {
                    Todo_Performance_Monitor::end('db', false, substr($query, 0, 100));
                }

                // 检查是否是非致命错误（表/列不存在，通常是动态查询产生的）
                if (
                    strpos($this->last_error, "doesn't exist") !== false ||
                    strpos($this->last_error, 'Base table or view not found') !== false ||
                    strpos($this->last_error, 'Unknown column') !== false
                ) {
                    if (defined('TODO_DEBUG') && TODO_DEBUG) error_log('Non-fatal SQL error in get_row execute: ' . $this->last_error . ' | Query: ' . substr($query, 0, 200));
                } else {
                    if (defined('TODO_DEBUG') && TODO_DEBUG) {
                        error_log('PDO execute error in get_row: ' . $this->last_error . ' | Query: ' . substr($query, 0, 200));
                    }
                }

                $stmt->closeCursor();
                return null;
            }

            // 记录性能监控结束
            if (class_exists('Todo_Performance_Monitor')) {
                Todo_Performance_Monitor::end('db', false, substr($query, 0, 100));
            }

            // 增加查询计数
            $this->query_count++;

            // 记录查询日志
            $this->log_query($query, $params);

            // 根据输出类型返回不同格式
            if ($output_type === 'ARRAY_A') {
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $result = $stmt->fetch(PDO::FETCH_OBJ);
            }

            $stmt->closeCursor();
            return $result;
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();

            // 如果在调试模式下，记录错误到日志而不是直接输出
            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                error_log('查询错误: ' . $this->last_error);
            }

            return null;
        }
    }

    /**
     * 获取单个值
     *
     * @param string $query SQL查询
     * @param array|string $params 查询参数
     * @return mixed 查询结果的第一个字段值
     */
    public function get_var($query, $params = [])
    {
        // 验证查询字符串（在拦截器之前）
        if (empty($query) || !is_string($query)) {
            $error_msg = "Invalid query in get_var: query is empty or not a string";
            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                $error_msg .= "\nQuery type: " . gettype($query);
                $error_msg .= "\nQuery value: " . var_export($query, true);
                $error_msg .= "\nBacktrace: " . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5), true);
            }
            error_log($error_msg);
            $this->last_error = $error_msg;
            return null;
        }

        // 查询拦截器（性能优化）
        if (class_exists('Todo_DB_Query_Interceptor') && Todo_DB_Query_Interceptor::is_enabled()) {
            $actual_params = is_array($params) ? $params : (empty($params) ? [] : [$params]);
            $original_query = $query; // 保存原始查询用于调试
            return Todo_DB_Query_Interceptor::intercept('get_var', $query, $actual_params, function ($q, $p) use ($original_query) {
                // 在回调中再次验证查询字符串（拦截器可能修改了它）
                if (empty($q) || !is_string($q)) {
                    $error_msg = "Invalid query in get_var callback: query is empty or not a string after interceptor";
                    if (defined('TODO_DEBUG') && TODO_DEBUG) {
                        $error_msg .= "\nQuery type: " . gettype($q);
                        $error_msg .= "\nQuery value: " . var_export($q, true);
                        $error_msg .= "\nOriginal query: " . var_export($original_query, true);
                        $error_msg .= "\nBacktrace: " . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5), true);
                    }
                    error_log($error_msg);
                    $this->last_error = $error_msg;
                    return null;
                }

                // 检查查询是否只包含占位符（无效的 SQL）
                $q_clean = trim($q);
                if ($q_clean === '?' || preg_match('/^\s*\?\s*$/', $q_clean)) {
                    $error_msg = "Invalid query in get_var callback: query contains only placeholder '?' after interceptor";
                    if (defined('TODO_DEBUG') && TODO_DEBUG) {
                        $error_msg .= "\nQuery value: " . var_export($q, true);
                        $error_msg .= "\nOriginal query: " . var_export($original_query, true);
                        $error_msg .= "\nBacktrace: " . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5), true);
                    }
                    error_log($error_msg);
                    $this->last_error = $error_msg;
                    return null;
                }

                // 检查查询是否包含有效的 SQL 关键字
                if (!preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|SHOW|DESCRIBE|EXPLAIN|SET|BEGIN|COMMIT|ROLLBACK)\s+/i', $q_clean)) {
                    $error_msg = "Invalid query in get_var callback: query does not start with a valid SQL keyword after interceptor";
                    if (defined('TODO_DEBUG') && TODO_DEBUG) {
                        $error_msg .= "\nQuery value: " . var_export($q, true);
                        $error_msg .= "\nOriginal query: " . var_export($original_query, true);
                        $error_msg .= "\nBacktrace: " . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5), true);
                    }
                    error_log($error_msg);
                    $this->last_error = $error_msg;
                    return null;
                }

                return $this->get_var_direct($q, $p);
            });
        }

        return $this->get_var_direct($query, $params);
    }

    /**
     * 直接执行get_var（绕过拦截器）
     */
    private function get_var_direct($query, $params = [])
    {
        try {
            // 检查数据库连接
            if (!$this->is_connected()) {
                $this->last_error = 'Database connection is not active.';
                return null;
            }

            if ($this->demo_should_block_query($query)) {
                $this->last_error = 'Demo account restricted query.';
                return null;
            }

            // 清理之前的语句
            $this->cleanup_statements();

            // 验证查询字符串
            if (empty($query) || !is_string($query)) {
                $error_msg = "Invalid query in get_var: query is empty or not a string";
                if (defined('TODO_DEBUG') && TODO_DEBUG) {
                    $error_msg .= "\nQuery type: " . gettype($query);
                    $error_msg .= "\nQuery value: " . var_export($query, true);
                    $error_msg .= "\nBacktrace: " . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5), true);
                }
                error_log($error_msg);
                $this->last_error = $error_msg;
                return null;
            }

            // 清理查询字符串（去除前后空白）
            $query = trim($query);
            if (empty($query)) {
                $error_msg = "Empty query string in get_var after trim";
                error_log($error_msg);
                $this->last_error = $error_msg;
                return null;
            }

            // 检查查询是否只包含占位符（无效的 SQL）
            $query_clean = trim($query);
            if ($query_clean === '?' || preg_match('/^\s*\?\s*$/', $query_clean)) {
                $error_msg = "Invalid query in get_var: query contains only placeholder '?'";
                if (defined('TODO_DEBUG') && TODO_DEBUG) {
                    $error_msg .= "\nQuery value: " . var_export($query, true);
                    $error_msg .= "\nBacktrace: " . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5), true);
                }
                error_log($error_msg);
                $this->last_error = $error_msg;
                return null;
            }

            // 检查查询是否包含有效的 SQL 关键字（至少应该有一个 SELECT/INSERT/UPDATE/DELETE 等）
            if (!preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|SHOW|DESCRIBE|EXPLAIN|SET|BEGIN|COMMIT|ROLLBACK)\s+/i', $query_clean)) {
                $error_msg = "Invalid query in get_var: query does not start with a valid SQL keyword";
                if (defined('TODO_DEBUG') && TODO_DEBUG) {
                    $error_msg .= "\nQuery value: " . var_export($query, true);
                    $error_msg .= "\nBacktrace: " . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5), true);
                }
                error_log($error_msg);
                $this->last_error = $error_msg;
                return null;
            }

            // 确保参数是数组
            if (!is_array($params)) {
                $params = empty($params) ? [] : [$params];
            }

            // 验证参数数量是否匹配SQL中的占位符
            $placeholder_count = substr_count($query, '?');
            if ($placeholder_count > 0 && count($params) !== $placeholder_count) {
                $error_msg = "Parameter count mismatch in get_var: SQL has {$placeholder_count} placeholders but " . count($params) . " parameters provided";
                if (defined('TODO_DEBUG') && TODO_DEBUG) {
                    $error_msg .= "\nSQL: " . substr($query, 0, 200);
                    $error_msg .= "\nParams: " . print_r($params, true);
                    $error_msg .= "\nBacktrace: " . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5), true);
                }
                error_log($error_msg);
                throw new PDOException($error_msg);
            }

            // 在 prepare 之前再次验证查询（双重保险）
            $query_for_prepare = trim($query);
            if (empty($query_for_prepare) || !is_string($query) || $query_for_prepare === '' || $query_for_prepare === '?') {
                $error_msg = "Invalid query in get_var before prepare: query is empty, not a string, or contains only placeholder";
                if (defined('TODO_DEBUG') && TODO_DEBUG) {
                    $error_msg .= "\nQuery type: " . gettype($query);
                    $error_msg .= "\nQuery value: " . var_export($query, true);
                    $error_msg .= "\nQuery length: " . strlen($query);
                    $error_msg .= "\nQuery trimmed: " . var_export($query_for_prepare, true);
                    $error_msg .= "\nBacktrace: " . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5), true);
                }
                error_log($error_msg);
                $this->last_error = $error_msg;
                return null;
            }

            // 检查查询是否只包含占位符（更严格的检查）
            if (preg_match('/^\s*\?+\s*$/', $query_for_prepare)) {
                $error_msg = "Invalid query in get_var before prepare: query contains only placeholder(s) '?'";
                if (defined('TODO_DEBUG') && TODO_DEBUG) {
                    $error_msg .= "\nQuery value: " . var_export($query, true);
                    $error_msg .= "\nQuery trimmed: " . var_export($query_for_prepare, true);
                    $error_msg .= "\nBacktrace: " . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5), true);
                }
                error_log($error_msg);
                $this->last_error = $error_msg;
                return null;
            }

            // 检查查询是否以有效的 SQL 关键字开头（最终验证）
            if (!preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|SHOW|DESCRIBE|EXPLAIN|SET|BEGIN|COMMIT|ROLLBACK|CALL|EXECUTE)\s+/i', $query_for_prepare)) {
                $error_msg = "Invalid query in get_var before prepare: query does not start with a valid SQL keyword";
                if (defined('TODO_DEBUG') && TODO_DEBUG) {
                    $error_msg .= "\nQuery value: " . var_export($query, true);
                    $error_msg .= "\nQuery trimmed: " . var_export($query_for_prepare, true);
                    $error_msg .= "\nBacktrace: " . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5), true);
                }
                error_log($error_msg);
                $this->last_error = $error_msg;
                return null;
            }

            // 准备语句
            try {
                $stmt = $this->connection->prepare($query);
            } catch (PDOException $e) {
                $this->last_error = $e->getMessage();
                // 检查是否是非致命错误（表/列不存在，通常是动态查询产生的）
                if (
                    strpos($this->last_error, "doesn't exist") !== false ||
                    strpos($this->last_error, 'Base table or view not found') !== false ||
                    strpos($this->last_error, 'Unknown column') !== false
                ) {
                    if (defined('TODO_DEBUG') && TODO_DEBUG) error_log('Non-fatal SQL error in get_var: ' . $this->last_error . ' | Query: ' . substr($query, 0, 200));
                } else {
                    // 提供更详细的错误信息
                    $error_details = 'PDO prepare error in get_var: ' . $this->last_error;
                    $error_details .= ' | Query: ' . substr($query, 0, 500);
                    $error_details .= ' | Query length: ' . strlen($query);
                    $error_details .= ' | Query trimmed: ' . var_export(trim($query), true);
                    $error_details .= ' | Placeholder count: ' . $placeholder_count;
                    $error_details .= ' | Params count: ' . count($params);
                    $error_details .= ' | Is query only "?": ' . (trim($query) === '?' ? 'YES' : 'NO');
                    if (defined('TODO_DEBUG') && TODO_DEBUG) {
                        $error_details .= ' | Full query: ' . $query;
                        $error_details .= ' | Params: ' . print_r($params, true);
                        $error_details .= ' | Backtrace: ' . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5), true);
                    }
                    error_log($error_details);
                }
                return null;
            }

            if (!($stmt instanceof PDOStatement)) {
                if (is_string($stmt)) {
                    try {
                        $stmt = $this->connection->prepare((string) $stmt);
                    } catch (PDOException $e) {
                        $this->last_error = $e->getMessage();
                        error_log('PDO prepare error (retry) in get_var: ' . $this->last_error);
                        return null;
                    }
                }
            }
            if (!($stmt instanceof PDOStatement)) {
                $this->last_error = 'Failed to prepare PDO statement in get_var';
                error_log($this->last_error . ' | Query: ' . substr($query, 0, 200));
                return null;
            }

            // 处理NULL值参数，避免datetime字段的空字符串错误
            $params = $this->process_datetime_params($params, $query);

            // 记录性能监控开始
            if (class_exists('Todo_Performance_Monitor')) {
                Todo_Performance_Monitor::start('db');
            }

            // 执行查询（如果没有占位符，传递空数组）
            try {
                $this->maybe_simulate_prod_latency();
                $stmt->execute($placeholder_count > 0 ? $params : []);
            } catch (PDOException $e) {
                $this->last_error = $e->getMessage();

                // 记录性能监控结束（即使出错也要结束）
                if (class_exists('Todo_Performance_Monitor')) {
                    Todo_Performance_Monitor::end('db', false, substr($query, 0, 100));
                }

                // 检查是否是非致命错误（表/列不存在，通常是动态查询产生的）
                if (
                    strpos($this->last_error, "doesn't exist") !== false ||
                    strpos($this->last_error, 'Base table or view not found') !== false ||
                    strpos($this->last_error, 'Unknown column') !== false
                ) {
                    if (defined('TODO_DEBUG') && TODO_DEBUG) error_log('Non-fatal SQL error in get_var execute: ' . $this->last_error . ' | Query: ' . substr($query, 0, 200));
                } else {
                    if (defined('TODO_DEBUG') && TODO_DEBUG) {
                        error_log('PDO execute error in get_var: ' . $this->last_error . ' | Query: ' . substr($query, 0, 200));
                    }
                }

                $stmt->closeCursor();
                return null;
            }

            // 记录性能监控结束
            if (class_exists('Todo_Performance_Monitor')) {
                Todo_Performance_Monitor::end('db', false, substr($query, 0, 100));
            }

            // 增加查询计数
            $this->query_count++;

            // 记录查询日志
            $this->log_query($query, $params);

            // 返回第一个字段值
            $result = $stmt->fetchColumn();
            $stmt->closeCursor();
            return $result;
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();

            // 如果在调试模式下，记录错误到日志而不是直接输出
            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                error_log('查询错误: ' . $this->last_error);
            }

            return null;
        }
    }

    /**
     * 获取单列的所有值
     *
     * @param string $query SQL查询
     * @param array|string $params 查询参数
     * @param int $column_number 列索引（从0开始）
     * @return array 值数组
     */
    public function get_col($query, $params = [], $column_number = 0)
    {
        try {
            // 清理之前的语句
            $this->cleanup_statements();

            // 确保参数是数组
            if (!is_array($params)) {
                $params = empty($params) ? [] : [$params];
            }

            // 验证参数数量是否匹配SQL中的占位符
            $placeholder_count = substr_count($query, '?');
            if ($placeholder_count > 0 && count($params) !== $placeholder_count) {
                $error_msg = "Parameter count mismatch in get_col: SQL has {$placeholder_count} placeholders but " . count($params) . " parameters provided";
                if (defined('TODO_DEBUG') && TODO_DEBUG) {
                    $error_msg .= "\nSQL: " . substr($query, 0, 200);
                    $error_msg .= "\nParams: " . print_r($params, true);
                    $error_msg .= "\nBacktrace: " . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5), true);
                }
                error_log($error_msg);
                throw new PDOException($error_msg);
            }

            // 准备语句
            try {
                $stmt = $this->connection->prepare($query);
            } catch (PDOException $e) {
                $this->last_error = $e->getMessage();
                // 检查是否是非致命错误（表/列不存在，通常是动态查询产生的）
                if (
                    strpos($this->last_error, "doesn't exist") !== false ||
                    strpos($this->last_error, 'Base table or view not found') !== false ||
                    strpos($this->last_error, 'Unknown column') !== false
                ) {
                    if (defined('TODO_DEBUG') && TODO_DEBUG) error_log("Non-fatal SQL error in get_col: {$this->last_error} | Query: " . substr($query, 0, 200));
                } else {
                    error_log("PDO prepare error in get_col: {$this->last_error} | Query: " . substr($query, 0, 200));
                }
                return [];
            }

            if (!($stmt instanceof PDOStatement)) {
                $this->last_error = 'Failed to prepare PDO statement in get_col';
                error_log($this->last_error . ' | Query: ' . substr($query, 0, 200));
                return [];
            }

            // 处理NULL值参数，避免datetime字段的空字符串错误
            $params = $this->process_datetime_params($params, $query);

            // 记录性能监控开始
            if (class_exists('Todo_Performance_Monitor')) {
                Todo_Performance_Monitor::start('db');
            }

            // 执行查询（如果没有占位符，传递空数组）
            try {
                $this->maybe_simulate_prod_latency();
                $stmt->execute($placeholder_count > 0 ? $params : []);
            } catch (PDOException $e) {
                $this->last_error = $e->getMessage();

                // 记录性能监控结束（即使出错也要结束）
                if (class_exists('Todo_Performance_Monitor')) {
                    Todo_Performance_Monitor::end('db', false, substr($query, 0, 100));
                }

                // 检查是否是非致命错误（表/列不存在，通常是动态查询产生的）
                if (
                    strpos($this->last_error, "doesn't exist") !== false ||
                    strpos($this->last_error, 'Base table or view not found') !== false ||
                    strpos($this->last_error, 'Unknown column') !== false
                ) {
                    if (defined('TODO_DEBUG') && TODO_DEBUG) error_log('Non-fatal SQL error in get_col execute: ' . $this->last_error . ' | Query: ' . substr($query, 0, 200));
                } else {
                    if (defined('TODO_DEBUG') && TODO_DEBUG) {
                        error_log('PDO execute error in get_col: ' . $this->last_error . ' | Query: ' . substr($query, 0, 200));
                    }
                }

                $stmt->closeCursor();
                return [];
            }

            // 记录性能监控结束
            if (class_exists('Todo_Performance_Monitor')) {
                Todo_Performance_Monitor::end('db', false, substr($query, 0, 100));
            }

            // 增加查询计数
            $this->query_count++;

            // 记录查询日志
            $this->log_query($query, $params);

            // 返回指定列的值数组
            $results = $stmt->fetchAll(PDO::FETCH_COLUMN, (int) $column_number);
            $stmt->closeCursor();
            return $results;
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();

            // 如果在调试模式下，记录错误到日志而不是直接输出
            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                error_log('查询错误: ' . $this->last_error);
            }

            return [];
        }
    }

    /**
     * 获取最后插入的ID
     *
     * @return int 最后插入的ID
     */
    public function insert_id()
    {
        return $this->last_query_id;
    }

    /**
     * 魔术方法 __get，兼容访问 $tododb->insert_id 属性
     */
    public function __get($name)
    {
        if ($name === 'insert_id') {
            return $this->last_query_id;
        }
        // 动态支持获取常见的以 $tododb->tablename 命名的表名
        $common_tables = ['posts', 'postmeta', 'users', 'usermeta', 'comments', 'commentmeta', 'terms', 'term_taxonomy', 'term_relationships'];
        if (in_array($name, $common_tables, true)) {
            return $this->prefix . $name;
        }
        $trace = debug_backtrace();
        trigger_error(
            'Undefined property: CMS_DB::$' . $name .
            ' in ' . $trace[0]['file'] .
            ' on line ' . $trace[0]['line'],
            E_USER_NOTICE
        );
        return null;
    }

    /**
     * 处理datetime参数，确保NULL值正确传递
     *
     * @param array $params 参数数组
     * @param string $query SQL查询语句
     * @return array 处理后的参数数组
     */
    private function process_datetime_params($params, $query)
    {
        // 精准匹配参数与字段，避免误将非日期字段（如seo_title）转换为NULL
        $datetime_fields = [
            'publish_time',
            'created_at',
            'updated_at',
            'deleted_at',
            'start_time',
            'end_time',
            'expire_time',
            'last_login_time',
            // posts表中的日期时间字段
            'post_date',
            'post_date_gmt',
            'post_modified',
            'post_modified_gmt'
        ];

        $columns = [];

        // 解析 INSERT ... (col1, col2, ...) VALUES (?, ?, ...)
        if (preg_match('/INSERT\s+INTO\s+.+\((.+)\)\s+VALUES\s*\((.+)\)/is', $query, $m)) {
            $cols = $m[1];
            $parts = preg_split('/\s*,\s*/', $cols);
            foreach ($parts as $col) {
                $col = trim($col);
                $col = trim($col, " `");
                if ($col !== '') {
                    $columns[] = $col;
                }
            }
        }
        // 解析 UPDATE ... SET col1 = ?, col2 = ?, ... WHERE ...
        elseif (preg_match('/UPDATE\s+.+\s+SET\s+(.+?)\s+WHERE/is', $query, $m)) {
            $assigns = $m[1];
            $parts = preg_split('/\s*,\s*/', $assigns);
            foreach ($parts as $part) {
                if (preg_match('/`?([a-zA-Z0-9_]+)`?\s*=\s*\?/i', $part, $mm)) {
                    $columns[] = $mm[1];
                }
            }
        }

        // 如果成功解析到列名，则仅针对日期时间字段做空字符串到NULL的转换
        if (!empty($columns)) {
            foreach ($params as $i => $value) {
                if (!isset($columns[$i])) {
                    continue;
                }
                $col = $columns[$i];
                if (in_array($col, $datetime_fields, true)) {
                    if ($value === '' || $value === '0000-00-00 00:00:00' || $value === '0000-00-00') {
                        $params[$i] = null;
                    }
                }
            }
            return $params;
        }

        // 无法解析列名时，为安全起见，不做任何转换，避免误伤非日期字段
        return $params;
    }

    /**
     * 在本地调试时模拟生产环境单次 SQL 的固定延迟。
     *
     * `TODO_SIMULATE_PROD_LATENCY` 支持两种写法：
     * - 数字：表示每次 SQL 额外延迟的毫秒数，例如 5
     * - 布尔 true：使用默认 5ms
     *
     * @return void
     */
    private function maybe_simulate_prod_latency()
    {
        if (!defined('TODO_SIMULATE_PROD_LATENCY')) {
            return;
        }

        $latency_config = TODO_SIMULATE_PROD_LATENCY;
        if ($latency_config === false || $latency_config === null) {
            return;
        }

        $latency_ms = 0.0;
        if ($latency_config === true) {
            $latency_ms = 5.0;
        } elseif (is_numeric($latency_config)) {
            $latency_ms = (float) $latency_config;
        }

        if ($latency_ms <= 0) {
            return;
        }

        usleep((int) round($latency_ms * 1000));
    }

    /**
     * 获取最后一个SQL错误
     *
     * @return string 错误消息
     */
    public function last_error()
    {
        return $this->last_error;
    }

    /**
     * 获取最后一个SQL错误（别名方法）
     *
     * @return string 错误消息
     */
    public function get_last_error()
    {
        return $this->last_error;
    }

    /**
     * 获取查询计数
     *
     * @return int 查询计数
     */
    public function query_count()
    {
        return $this->query_count;
    }

    /**
     * 转义字符串
     *
     * @param string $string 要转义的字符串
     * @return string 转义后的字符串
     */
    public function escape($string)
    {
        // 类型检查：确保参数是字符串或可转换为字符串的类型
        if (is_array($string) || is_object($string)) {
            // 如果是数组或对象，记录错误并返回空字符串
            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                error_log('escape() 方法收到了无效的参数类型: ' . gettype($string));
                error_log('调用堆栈: ' . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5), true));
            }
            return '';
        }

        // 确保参数是字符串
        $string = (string) $string;

        return substr($this->connection->quote($string), 1, -1);
    }

    /**
     * 准备SQL语句
     *
     * @param string $query SQL查询模板
     * @param mixed ...$args 要替换的参数
     * @return string 准备好的SQL语句
     */
    public function prepare($query, ...$args)
    {
        if (empty($args)) {
            return $query;
        }

        // 兼容传入单个数组作为第二个参数的情况 prepare($query, [val1, val2])
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        $i = 0;
        return preg_replace_callback('/%([dfs])/', function ($matches) use ($args, &$i) {
            if (!isset($args[$i])) {
                return $matches[0];
            }

            $value = $args[$i++];

            switch ($matches[1]) {
                case 'd': // 整数
                    return (int) $value;
                case 'f': // 浮点数
                    return (float) $value;
                case 's': // 字符串
                    // 类型检查：确保不是数组或对象
                    if (is_array($value) || is_object($value)) {
                        if (defined('TODO_DEBUG') && TODO_DEBUG) {
                            error_log('prepare() 方法的 %s 格式化收到了无效的参数类型: ' . gettype($value));
                            error_log('参数值: ' . print_r($value, true));
                        }
                        return "''"; // 返回空字符串
                    }
                    return "'" . $this->escape($value) . "'";
                default:
                    return $matches[0];
            }
        }, $query);
    }

    /**
     * 转义LIKE查询中的特殊字符
     *
     * @param string $string 要转义的字符串
     * @return string 转义后的字符串
     */
    public function escape_like($string)
    {
        return str_replace(['%', '_'], ['\%', '\_'], $this->escape($string));
    }

    /**
     * 转义LIKE查询中的特殊字符
     *
     * @param string $string 要转义的字符串
     * @return string 转义后的字符串
     */
    public function esc_like($string)
    {
        return $this->escape_like($string);
    }

    /**
     * 插入数据
     *
     * @param string $table 表名
     * @param array $data 要插入的数据
     * @return int|false 插入的ID或false
     */
    public function insert($table, $data)
    {
        if (!$this->connection) {
            return false;
        }

        if ($this->demo_should_block_write_table($table)) {
            $this->last_error = 'Demo account is read-only.';
            return false;
        }

        // 清理之前的语句
        $this->cleanup_statements();

        $fields = array_keys($data);
        $values = array_values($data);
        $placeholders = array_fill(0, count($fields), '?');

        $quoted_table = $table;
        if (is_string($quoted_table) && strpos($quoted_table, '`') === false) {
            $t = trim($quoted_table);
            if ($t !== '' && preg_match('/^[a-zA-Z0-9_]+(\.[a-zA-Z0-9_]+)?$/', $t)) {
                $parts = explode('.', $t);
                $parts = array_map(function ($p) {
                    $p = str_replace('`', '', (string) $p);
                    return '`' . $p . '`';
                }, $parts);
                $quoted_table = implode('.', $parts);
            }
        }

        $quoted_fields = array_map(function ($f) {
            $f = str_replace('`', '', (string) $f);
            return '`' . $f . '`';
        }, $fields);

        $query = "INSERT INTO {$quoted_table} (" . implode(', ', $quoted_fields) . ") VALUES (" . implode(', ', $placeholders) . ")";

        try {
            $stmt = $this->connection->prepare($query);
            if (!($stmt instanceof PDOStatement)) {
                throw new PDOException('Failed to prepare PDO statement in insert');
            }

            // 验证参数数量（insert方法中占位符数量应该等于values数量）
            $placeholder_count = count($placeholders);
            if (count($values) !== $placeholder_count) {
                throw new PDOException("Parameter count mismatch in insert: expected {$placeholder_count} values but got " . count($values));
            }

            // 处理datetime字段的空值，转换为NULL，避免'0000-00-00 00:00:00'引发MySQL错误
            $values = $this->process_datetime_params($values, $query);

            // 记录性能监控开始
            if (class_exists('Todo_Performance_Monitor')) {
                Todo_Performance_Monitor::start('db');
            }

            $this->maybe_simulate_prod_latency();
            $stmt->execute($values);

            // 记录性能监控结束
            if (class_exists('Todo_Performance_Monitor')) {
                Todo_Performance_Monitor::end('db', false, substr($query, 0, 100));
            }

            // 增加查询计数
            $this->query_count++;

            // 记录查询日志
            $this->log_query($query, $values);

            // 保存最后一个查询ID
            $this->last_query_id = $this->connection->lastInsertId();

            $stmt->closeCursor();
            return $this->last_query_id;
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();

            // 如果在调试模式下，记录错误到日志而不是直接输出
            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                error_log('插入错误: ' . $this->last_error);
            }

            return false;
        }
    }

    /**
     * 更新数据
     *
     * @param string $table 表名
     * @param array $data 要更新的数据
     * @param array $where 更新条件
     * @return int|false 受影响的行数或false
     */
    public function update($table, $data, $where)
    {
        if (!$this->connection) {
            return false;
        }

        if ($this->demo_should_block_write_table($table)) {
            $this->last_error = 'Demo account is read-only.';
            return false;
        }

        // 清理之前的语句
        $this->cleanup_statements();

        $set_clauses = array();
        $set_values = array();

        $quoted_table = $table;
        if (is_string($quoted_table) && strpos($quoted_table, '`') === false) {
            $t = trim($quoted_table);
            if ($t !== '' && preg_match('/^[a-zA-Z0-9_]+(\.[a-zA-Z0-9_]+)?$/', $t)) {
                $parts = explode('.', $t);
                $parts = array_map(function ($p) {
                    $p = str_replace('`', '', (string) $p);
                    return '`' . $p . '`';
                }, $parts);
                $quoted_table = implode('.', $parts);
            }
        }

        // 构建SET子句
        foreach ($data as $field => $value) {
            $field = str_replace('`', '', (string) $field);
            $set_clauses[] = "`{$field}` = ?";
            $set_values[] = $value;
        }

        // 构建WHERE子句
        $where_clauses = array();
        $where_values = array();
        foreach ($where as $field => $value) {
            $field = str_replace('`', '', (string) $field);
            $where_clauses[] = "`{$field}` = ?";
            $where_values[] = $value;
        }

        $query = "UPDATE {$quoted_table} SET " . implode(', ', $set_clauses) . " WHERE " . implode(' AND ', $where_clauses);

        try {
            $stmt = $this->connection->prepare($query);
            if (!($stmt instanceof PDOStatement)) {
                throw new PDOException('Failed to prepare PDO statement in update');
            }

            // 仅对SET部分的datetime字段做清理，避免WHERE语义被改变
            $set_values = $this->process_datetime_params($set_values, $query);
            $values = array_merge($set_values, $where_values);

            // 验证参数数量
            $placeholder_count = substr_count($query, '?');
            if (count($values) !== $placeholder_count) {
                throw new PDOException("Parameter count mismatch in update: SQL has {$placeholder_count} placeholders but " . count($values) . " parameters provided");
            }

            // 记录性能监控开始
            if (class_exists('Todo_Performance_Monitor')) {
                Todo_Performance_Monitor::start('db');
            }

            $this->maybe_simulate_prod_latency();
            $stmt->execute($values);

            // 记录性能监控结束
            if (class_exists('Todo_Performance_Monitor')) {
                Todo_Performance_Monitor::end('db', false, substr($query, 0, 100));
            }

            // 增加查询计数
            $this->query_count++;

            // 记录查询日志
            $this->log_query($query, $values);

            $rowCount = $stmt->rowCount();
            $stmt->closeCursor();
            return $rowCount;
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();

            // 如果在调试模式下，记录错误到日志而不是直接输出
            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                error_log('更新错误: ' . $this->last_error);
            }

            return false;
        }
    }

    /**
     * 删除数据
     *
     * @param string $table 表名
     * @param array $where 删除条件
     * @return int|false 受影响的行数或false
     */
    public function delete($table, $where)
    {
        if (!$this->connection) {
            return false;
        }

        if ($this->demo_should_block_write_table($table)) {
            $this->last_error = 'Demo account is read-only.';
            return false;
        }

        // 清理之前的语句
        $this->cleanup_statements();

        $quoted_table = $table;
        if (is_string($quoted_table) && strpos($quoted_table, '`') === false) {
            $t = trim($quoted_table);
            if ($t !== '' && preg_match('/^[a-zA-Z0-9_]+(\.[a-zA-Z0-9_]+)?$/', $t)) {
                $parts = explode('.', $t);
                $parts = array_map(function ($p) {
                    $p = str_replace('`', '', (string) $p);
                    return '`' . $p . '`';
                }, $parts);
                $quoted_table = implode('.', $parts);
            }
        }

        // 构建WHERE子句
        $where_clauses = array();
        $values = array();
        foreach ($where as $field => $value) {
            $field = str_replace('`', '', (string) $field);
            $where_clauses[] = "`{$field}` = ?";
            $values[] = $value;
        }

        // 如果没有WHERE条件，抛出异常（防止误删除所有数据）
        if (empty($where_clauses)) {
            throw new PDOException('DELETE query requires WHERE clause to prevent accidental deletion of all rows');
        }

        $query = "DELETE FROM {$quoted_table} WHERE " . implode(' AND ', $where_clauses);

        try {
            $stmt = $this->connection->prepare($query);
            if (!($stmt instanceof PDOStatement)) {
                throw new PDOException('Failed to prepare PDO statement in delete');
            }

            // 验证参数数量
            $placeholder_count = substr_count($query, '?');
            if (count($values) !== $placeholder_count) {
                throw new PDOException("Parameter count mismatch in delete: SQL has {$placeholder_count} placeholders but " . count($values) . " parameters provided");
            }

            // 记录性能监控开始
            if (class_exists('Todo_Performance_Monitor')) {
                Todo_Performance_Monitor::start('db');
            }

            $this->maybe_simulate_prod_latency();
            $stmt->execute($values);

            // 记录性能监控结束
            if (class_exists('Todo_Performance_Monitor')) {
                Todo_Performance_Monitor::end('db', false, substr($query, 0, 100));
            }

            // 增加查询计数
            $this->query_count++;

            $rowCount = $stmt->rowCount();
            $stmt->closeCursor();
            return $rowCount;
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();

            // 如果在调试模式下，记录错误到日志而不是直接输出
            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                error_log('删除错误: ' . $this->last_error);
            }

            return false;
        }
    }

    /**
     * 检查数据库是否已连接
     *
     * @return bool 是否已连接
     */
    public function is_connected()
    {
        return $this->connection instanceof PDO;
    }

    /**
     * 关闭数据库连接
     */
    public function close()
    {
        $this->connection = null;
    }

    /**
     * @var array 记录已检查的表是否存在
     */
    private static $checked_tables = [];

    /**
     * 检查表是否存在
     *
     * @param string $table_name 表名（不包含前缀）
     * @return bool 表是否存在
     */
    public function table_exists($table_name)
    {
        $prefix = !empty($this->prefix) ? $this->prefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_');
        $unprefixed = (strpos($table_name, $prefix) === 0) ? substr($table_name, strlen($prefix)) : $table_name;
        $full_table_name = $prefix . $unprefixed;

        if (empty(self::$checked_tables)) {
            try {
                $tables = $this->get_col("SHOW TABLES");
                if (is_array($tables)) {
                    foreach ($tables as $t) {
                        self::$checked_tables[$t] = true;
                        $unp = (strpos($t, $prefix) === 0) ? substr($t, strlen($prefix)) : $t;
                        self::$checked_tables[$unp] = true;
                    }
                }
            } catch (Exception $e) {}
        }

        if (isset(self::$checked_tables[$table_name])) {
            return self::$checked_tables[$table_name];
        }
        if (isset(self::$checked_tables[$full_table_name])) {
            return self::$checked_tables[$full_table_name];
        }
        if (isset(self::$checked_tables[$unprefixed])) {
            return self::$checked_tables[$unprefixed];
        }

        try {
            $query = "SHOW TABLES LIKE '" . $this->escape($full_table_name) . "'";
            $result = $this->get_var($query);

            $exists = !empty($result);
            self::$checked_tables[$table_name] = $exists;
            self::$checked_tables[$full_table_name] = $exists;
            self::$checked_tables[$unprefixed] = $exists;
            return $exists;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();

            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                error_log('检查表存在性错误: ' . $this->last_error);
            }

            self::$checked_tables[$table_name] = false;
            self::$checked_tables[$full_table_name] = false;
            self::$checked_tables[$unprefixed] = false;
            return false;
        }
    }

    /**
     * 获取字符集和排序规则
     *
     * @return string 字符集和排序规则字符串
     */
    public function get_charset_collate()
    {
        $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
        $collate = defined('DB_COLLATE') ? DB_COLLATE : 'utf8mb4_unicode_ci';

        $charset_collate = "DEFAULT CHARACTER SET {$charset}";
        if (!empty($collate)) {
            $charset_collate .= " COLLATE {$collate}";
        }

        return $charset_collate;
    }

    /**
     * 获取PDO连接对象
     *
     * @return PDO PDO连接对象
     */
    public function get_connection()
    {
        return $this->connection;
    }

    private function demo_is_active()
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

    private function demo_is_write_query($query)
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

    private function demo_normalize_table_name($table)
    {
        $t = trim((string) $table);
        $t = str_replace('`', '', $t);
        $parts = explode('.', $t);
        $t = end($parts);
        return strtolower($t);
    }

    private function demo_is_allowed_write_table($table)
    {
        $t = $this->demo_normalize_table_name($table);
        $prefix = (string) $this->prefix;
        $allowed = [
            strtolower($prefix . 'user_sessions'),
        ];
        return in_array($t, $allowed, true);
    }

    private function demo_extract_primary_table_from_query($query)
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

    private function demo_should_block_write_table($table)
    {
        if (!$this->demo_is_active()) {
            return false;
        }
        return !$this->demo_is_allowed_write_table($table);
    }

    private function demo_should_block_write_query($query)
    {
        if (!$this->demo_is_active()) {
            return false;
        }
        if (!$this->demo_is_write_query($query)) {
            return false;
        }
        $table = $this->demo_extract_primary_table_from_query($query);
        if ($table === null) {
            return true;
        }
        return !$this->demo_is_allowed_write_table($table);
    }

    /**
     * 判断是否应拦截查询（读写通用）
     * 
     * @param string $query
     * @return bool
     */
    private function demo_should_block_query($query)
    {
        if (!$this->demo_is_active()) {
            return false;
        }

        $q = ltrim((string) $query);
        
        // 1. 拦截写操作
        if ($this->demo_is_write_query($q)) {
            return $this->demo_should_block_write_query($q);
        }

        // 2. 拦截敏感表的读操作（防止 Session 劫持和深度信息泄露）
        $upper_q = strtoupper($q);
        $prefix = (string) $this->prefix;
        $sensitive_tables = [
            'user_sessions', 'users', 'orders', 'order_status', 
            'member_balance', 'cart', 'member_favorites', 
            'shipping_address', 'email_subscriptions', 
            'website_messages', 'product_inquiries', 'feedback',
            'options'
        ];
        
        foreach ($sensitive_tables as $st) {
            $full_table = strtolower($prefix . $st);
            // 简单正则判断查询中是否包含敏感表名
            if (preg_match('/\b' . preg_quote($full_table, '/') . '\b/i', $upper_q)) {
                if ($st === 'options' && preg_match('/(site_adminbasefile|todo_admin_last_rotate_time|license|salt|key)/i', $upper_q)) {
                     return true;
                }

                // [PII 核心深度隔离]：绝不允许获取 Session 或 会话令牌，维持核心边界
                if ($st === 'user_sessions') {
                    return true;
                }

                // 为了增强等保安全性，即便放开 SELECT，也要禁止针对敏感字段（如 user_pass, user_reset_key）的越权扫描
                if ($st === 'users' && preg_match('/(user_pass|user_activation_key|user_reset_key)/i', $upper_q)) {
                     return true;
                }
            }
        }

        return false;
    }

    /**
     * 记录查询日志（用于调试）
     *
     * @param string $query SQL查询
     * @param array $params 查询参数
     * @return void
     */
    private function log_query($query, $params = [])
    {
        if (!$this->enable_query_log) {
            return;
        }

        // 限制日志数量防止内存溢出
        if (count($this->query_log) > 2000) {
            return;
        }

        // 获取调用堆栈 - 限制层级以节省内存
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);

        // 找到第一个不是CMS_DB类的调用者
        $caller = 'unknown';
        $file = 'unknown';
        $line = 0;

        foreach ($backtrace as $trace) {
            if (isset($trace['class']) && $trace['class'] === 'CMS_DB') {
                continue;
            }
            if (isset($trace['file'])) {
                $file = str_replace(ABSPATH, '', $trace['file']);
                $line = $trace['line'] ?? 0;
                $caller = (isset($trace['class']) ? $trace['class'] . '::' : '') . ($trace['function'] ?? 'unknown');
                break;
            }
        }

        // 记录查询信息
        $this->query_log[] = [
            'query' => $query,
            'params' => $params,
            'caller' => $caller,
            'file' => $file,
            'line' => $line,
            'time' => microtime(true)
        ];
    }

    /**
     * 获取查询日志
     *
     * @return array 查询日志数组
     */
    public function get_query_log()
    {
        return $this->query_log;
    }

    /**
     * 记录性能统计信息
     */
    public function log_performance_stats()
    {
        // 输出查询日志到性能监控
        if ($this->enable_query_log && class_exists('Todo_Performance_Monitor')) {
            // 避免重复记录
            static $logged = false;
            if ($logged) {
                return;
            }
            $logged = true;

            // 输出总查询统计
            /*Todo_Performance_Monitor::measure(
                'query_log_summary',
                function () {},
                sprintf(
                    'Total queries: %d, Logged: %d',
                    $this->query_count,
                    count($this->query_log)
                )
            );*/

            $grouped = [];
            foreach ($this->query_log as $log) {
                $key = $log['file'] . ':' . $log['line'];
                if (!isset($grouped[$key])) {
                    $grouped[$key] = [
                        'count' => 0,
                        'caller' => $log['caller'],
                        'file' => $log['file'],
                        'line' => $log['line'],
                        'sample_query' => substr($log['query'], 0, 100)
                    ];
                }
                $grouped[$key]['count']++;
            }

            // 按查询数量排序
            uasort($grouped, function ($a, $b) {
                return $b['count'] - $a['count'];
            });

            // 输出前20个最频繁的查询位置
            /*$top_queries = array_slice($grouped, 0, 20);
            foreach ($top_queries as $key => $info) {
                Todo_Performance_Monitor::measure(
                    'query_location_' . md5($key),
                    function () {},
                    sprintf(
                        '%d queries from %s (%s:%d)',
                        $info['count'],
                        $info['caller'],
                        $info['file'],
                        $info['line']
                    )
                );
            }*/
        }
    }

    /**
     * 析构函数
     *
     * 关闭数据库连接
     */
    public function __destruct()
    {
        $this->log_performance_stats();
        $this->connection = null;
    }
}
