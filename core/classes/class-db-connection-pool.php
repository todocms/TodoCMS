<?php
/**
 * 数据库连接池
 * 
 * 复用数据库连接，减少连接开销
 * 特别适用于虚拟主机环境（1核CPU，1GB内存）
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 数据库连接池类
 */
class Todo_DB_Connection_Pool {
    
    /**
     * 连接池
     * @var array
     */
    private static $pool = [];
    
    /**
     * 最大连接数
     * @var int
     */
    private static $max_connections = 5;
    
    /**
     * 当前连接数
     * @var int
     */
    private static $current_connections = 0;
    
    /**
     * 连接统计
     * @var array
     */
    private static $stats = [
        'connections_created' => 0,
        'connections_reused' => 0,
        'connections_closed' => 0,
        'time_saved' => 0
    ];
    
    /**
     * 是否启用连接池
     * @var bool
     */
    private static $enabled = true;
    
    /**
     * 启用连接池
     */
    public static function enable() {
        self::$enabled = true;
    }
    
    /**
     * 禁用连接池
     */
    public static function disable() {
        self::$enabled = false;
        self::close_all();
    }
    
    /**
     * 获取数据库连接
     * 
     * @param string $key 连接键（可选，用于区分不同连接）
     * @return PDO|null 数据库连接
     */
    public static function get_connection($key = 'default') {
        if (!self::$enabled) {
            return null;
        }
        
        // 检查池中是否有可用连接
        if (isset(self::$pool[$key]) && self::$pool[$key] instanceof PDO) {
            try {
                // 检查连接是否有效
                self::$pool[$key]->query('SELECT 1');
                self::$stats['connections_reused']++;
                return self::$pool[$key];
            } catch (Exception $e) {
                // 连接已失效，移除
                unset(self::$pool[$key]);
                self::$current_connections--;
            }
        }
        
        // 检查是否超过最大连接数
        if (self::$current_connections >= self::$max_connections) {
            // 关闭最旧的连接
            self::close_oldest();
        }
        
        // 创建新连接
        $connection = self::create_connection();
        if ($connection) {
            self::$pool[$key] = $connection;
            self::$current_connections++;
            self::$stats['connections_created']++;
        }
        
        return $connection;
    }
    
    /**
     * 创建数据库连接
     * 
     * @return PDO|null
     */
    private static function create_connection() {
        try {
            if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER')) {
                // 尝试加载配置文件
                if (file_exists(ABSPATH . 'config.php')) {
                    require_once ABSPATH . 'config.php';
                }
            }
            
            if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER')) {
                return null;
            }
            
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false, // 不使用持久连接，由连接池管理
            ];
            
            $connection = new PDO($dsn, DB_USER, DB_PASSWORD, $options);
            return $connection;
        } catch (PDOException $e) {
            error_log('Database connection pool error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 关闭最旧的连接
     */
    private static function close_oldest() {
        if (empty(self::$pool)) {
            return;
        }
        
        // 关闭第一个连接
        $key = array_key_first(self::$pool);
        if ($key !== null) {
            unset(self::$pool[$key]);
            self::$current_connections--;
            self::$stats['connections_closed']++;
        }
    }
    
    /**
     * 关闭所有连接
     */
    public static function close_all() {
        foreach (self::$pool as $key => $connection) {
            unset(self::$pool[$key]);
        }
        self::$pool = [];
        self::$current_connections = 0;
    }
    
    /**
     * 获取统计信息
     * @return array
     */
    public static function get_stats() {
        return array_merge(self::$stats, [
            'current_connections' => self::$current_connections,
            'max_connections' => self::$max_connections,
            'pool_size' => count(self::$pool)
        ]);
    }
    
    /**
     * 配置连接池
     * 
     * @param array $config 配置
     */
    public static function configure($config) {
        if (isset($config['max_connections'])) {
            self::$max_connections = max(1, min(10, (int)$config['max_connections']));
        }
    }
}

