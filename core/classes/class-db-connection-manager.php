<?php
/**
 * 数据库连接管理器
 *
 * 统一管理数据库连接，防止连接泄漏
 *
 * @package TodoCMS
 * @version 1.0.0
 */

class TodoDBConnectionManager
{
    /**
     * 单例实例
     *
     * @var TodoDBConnectionManager
     */
    private static $instance = null;

    /**
     * 数据库连接池
     *
     * @var array
     */
    private $connections = [];

    /**
     * 最大连接数
     *
     * @var int
     */
    private $max_connections = 10;

    /**
     * 当前连接数
     *
     * @var int
     */
    private $connection_count = 0;

    /**
     * 私有构造函数
     */
    private function __construct()
    {
        // 注册关闭时清理连接
        register_shutdown_function([$this, 'cleanup_connections']);
    }

    /**
     * 获取单例实例
     *
     * @return TodoDBConnectionManager
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 获取数据库连接
     *
     * @param string $connection_id 连接标识
     * @return PDO
     * @throws Exception
     */
    public function get_connection($connection_id = 'default')
    {
        $perf_started = false;
        if (class_exists('Todo_Performance_Monitor')) {
            Todo_Performance_Monitor::start('db_pool_get');
            $perf_started = true;
        }

        try {
            if (isset($this->connections[$connection_id]) && $this->connections[$connection_id] !== null) {
                if ($this->is_connection_alive($this->connections[$connection_id])) {
                    return $this->connections[$connection_id];
                }
                $this->close_connection($connection_id);
            }

            if ($this->connection_count >= $this->max_connections) {
                $this->cleanup_invalid_connections();
                if ($this->connection_count >= $this->max_connections) {
                    throw new Exception('Database connection pool exhausted');
                }
            }

            return $this->create_connection($connection_id);
        } finally {
            if ($perf_started && class_exists('Todo_Performance_Monitor')) {
                Todo_Performance_Monitor::end('db_pool_get', false, 'db manager get_connection');
            }
        }
    }

    /**
     * 创建数据库连接
     *
     * @param string $connection_id 连接标识
     * @param int $retry_count 重试次数
     * @return PDO
     * @throws Exception
     */
    private function create_connection($connection_id, $retry_count = 3)
    {
        $perf_started = false;
        if (class_exists('Todo_Performance_Monitor')) {
            Todo_Performance_Monitor::start('db_connect');
            $perf_started = true;
        }

        $last_exception = null;
        $force_tcp = defined('DB_FORCE_TCP') ? (bool) constant('DB_FORCE_TCP') : false;

        try {
            for ($i = 0; $i < $retry_count; $i++) {
                try {
                    $dsn = $this->build_dsn($force_tcp);

                    $options = [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                        PDO::ATTR_PERSISTENT => false,
                        PDO::ATTR_TIMEOUT => 30,
                        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                        PDO::MYSQL_ATTR_FOUND_ROWS => true,
                        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
                    ];

                    $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, $options);

                    $this->connections[$connection_id] = $pdo;
                    $this->connection_count++;

                    return $pdo;

                } catch (PDOException $e) {
                    $last_exception = $e;

                    $msg = $e->getMessage();
                    if (!$force_tcp && (stripos($msg, 'socket') !== false || stripos($msg, '2002') !== false)) {
                        $force_tcp = true;
                    }

                    if ($i < $retry_count - 1) {
                        usleep(100000);
                    }
                }
            }

            throw new Exception(sprintf(
                'Database connection failed after %d attempts: %s | Host: %s | Database: %s',
                $retry_count,
                $last_exception->getMessage(),
                defined('DB_HOST') ? DB_HOST : '未定义',
                defined('DB_NAME') ? DB_NAME : '未定义'
            ));
        } finally {
            if ($perf_started && class_exists('Todo_Performance_Monitor')) {
                Todo_Performance_Monitor::end('db_connect', false, 'pdo connect (with retry)');
            }
        }
    }

    /**
     * 构建数据源名称(DSN)
     *
     * @return string
     */
    private function build_dsn($force_tcp = false)
    {
        // 如果不是强制TCP，优先使用socket连接（更快更安全）
        if (!$force_tcp && (DB_HOST === 'localhost' || DB_HOST === '127.0.0.1')) {
            // 允许从配置覆盖默认socket路径
            $socket = defined('DB_SOCKET') ? (string) constant('DB_SOCKET') : '/tmp/mysql.sock';
            if (is_string($socket) && $socket && file_exists($socket)) {
                return 'mysql:unix_socket=' . $socket . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            }
        }

        // 使用TCP连接
        $port = defined('DB_PORT') ? intval(constant('DB_PORT')) : 3306;
        // 当强制TCP时，避免使用 localhost 触发socket，改用 127.0.0.1
        $host = ($force_tcp && (DB_HOST === 'localhost' || DB_HOST === '::1')) ? '127.0.0.1' : DB_HOST;
        return 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    }

    /**
     * 检查连接是否存活
     *
     * @param PDO $pdo
     * @return bool
     */
    private function is_connection_alive($pdo)
    {
        try {
            // 使用 getAttribute 代替 query，避免产生未关闭的结果集
            return (bool) $pdo->getAttribute(PDO::ATTR_SERVER_INFO);
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * 清理无效连接
     */
    private function cleanup_invalid_connections()
    {
        foreach ($this->connections as $id => $connection) {
            if ($connection !== null && !$this->is_connection_alive($connection)) {
                $this->close_connection($id);
            }
        }
    }

    /**
     * 关闭指定连接
     *
     * @param string $connection_id 连接标识
     */
    public function close_connection($connection_id = 'default')
    {
        if (isset($this->connections[$connection_id])) {
            $connection = $this->connections[$connection_id];

            // 安全关闭连接
            if ($connection instanceof PDO) {
                try {
                    // 取消所有准备好的语句
                    $connection = null;
                } catch (Exception $e) {
                    // 忽略关闭时的错误
                }
            }

            unset($this->connections[$connection_id]);
            $this->connection_count = max(0, $this->connection_count - 1);
        }
    }

    /**
     * 清理所有连接
     */
    public function cleanup_connections()
    {
        foreach (array_keys($this->connections) as $connection_id) {
            $this->close_connection($connection_id);
        }

        // 确保计数器重置
        $this->connections = [];
        $this->connection_count = 0;
    }

    /**
     * 析构函数 - 确保所有连接被正确关闭
     */
    public function __destruct()
    {
        $this->cleanup_connections();
    }

    /**
     * 获取连接统计信息
     *
     * @return array
     */
    public function get_stats()
    {
        $alive_connections = 0;
        $dead_connections = 0;

        foreach ($this->connections as $connection) {
            if ($connection !== null) {
                if ($this->is_connection_alive($connection)) {
                    $alive_connections++;
                } else {
                    $dead_connections++;
                }
            }
        }

        return [
            'total_connections' => $this->connection_count,
            'alive_connections' => $alive_connections,
            'dead_connections' => $dead_connections,
            'max_connections' => $this->max_connections,
            'connection_ids' => array_keys($this->connections),
            'pool_usage' => round(($this->connection_count / $this->max_connections) * 100, 2) . '%'
        ];
    }

    /**
     * 健康检查 - 检查所有连接状态
     *
     * @return array
     */
    public function health_check()
    {
        $results = [];

        foreach ($this->connections as $id => $connection) {
            if ($connection !== null) {
                $results[$id] = [
                    'alive' => $this->is_connection_alive($connection),
                    'last_check' => date('Y-m-d H:i:s')
                ];
            }
        }

        return $results;
    }

    /**
     * 强制清理死连接
     *
     * @return int 清理的连接数
     */
    public function force_cleanup_dead_connections()
    {
        $cleaned = 0;

        foreach ($this->connections as $id => $connection) {
            if ($connection !== null && !$this->is_connection_alive($connection)) {
                $this->close_connection($id);
                $cleaned++;
            }
        }

        return $cleaned;
    }

    /**
     * 设置最大连接数
     *
     * @param int $max_connections
     */
    public function set_max_connections($max_connections)
    {
        $this->max_connections = max(1, intval($max_connections));
    }

    /**
     * 防止克隆
     */
    private function __clone()
    {
    }

    /**
     * 防止反序列化
     */
    public function __wakeup()
    {
        throw new Exception("Cannot unserialize singleton");
    }
}

/**
 * 获取数据库连接管理器实例
 *
 * @return TodoDBConnectionManager
 */
function todo_get_db_manager()
{
    return TodoDBConnectionManager::get_instance();
}

/**
 * 获取数据库连接
 *
 * @param string $connection_id 连接标识
 * @return PDO
 */
function todo_get_db_connection($connection_id = 'default')
{
    return todo_get_db_manager()->get_connection($connection_id);
}

/**
 * 关闭数据库连接
 *
 * @param string $connection_id 连接标识
 */
function todo_close_db_connection($connection_id = 'default')
{
    todo_get_db_manager()->close_connection($connection_id);
}
