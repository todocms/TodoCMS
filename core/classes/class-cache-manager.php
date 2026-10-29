<?php
/**
 * 缓存管理器
 * 提供统一的缓存机制，支持多种缓存后端
 *
 * @package TodoCMS
 * @version 1.0.0
 */

class Todo_Cache_Manager
{

    /**
     * 单例实例
     * @var Todo_Cache_Manager
     */
    private static $instance = null;

    /**
     * 缓存配置
     * @var array
     */
    private static $config = array();

    /**
     * 是否正在初始化（防止递归调用）
     * @var bool
     */
    private static $initializing = false;

    /**
     * 是否已初始化
     * @var bool
     */
    private static $initialized = false;

    /**
     * 缓存后端实例
     * @var object
     */
    private static $backend = null;

    /**
     * 缓存统计
     * @var array
     */
    private static $stats = array(
        'hits' => 0,
        'misses' => 0,
        'sets' => 0,
        'deletes' => 0
    );

    /**
     * 缓存组
     * @var array
     */
    private static $groups = array(
        'default' => 3600,      // 1小时
        'posts' => 7200,        // 2小时
        'users' => 1800,        // 30分钟
        'categories' => 86400,  // 24小时
        'settings' => 86400,    // 24小时
        'queries' => 300,       // 5分钟
        'templates' => 3600,    // 1小时
        'media' => 86400,       // 24小时
    );

    /**
     * 获取单例实例
     * 
     * @return Todo_Cache_Manager
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 私有构造函数
     */
    private function __construct()
    {
        // 私有构造函数防止外部实例化
    }

    /**
     * 判断字符串是否为序列化格式
     * @param mixed $data
     * @return bool
     */
    private static function looks_serialized($data)
    {
        if (!is_string($data))
            return false;
        $data = trim($data);
        if ($data === 'N;')
            return true;
        if ($data === 'b:0;' || $data === 'b:1;')
            return true;
        if (strlen($data) < 4)
            return false;
        if ($data[1] !== ':')
            return false;
        $last = substr($data, -1);
        if ($last !== ';' && $last !== '}')
            return false;
        switch ($data[0]) {
            case 's': // string
            case 'a': // array
            case 'O': // object
            case 'i': // integer
            case 'd': // double
            case 'b': // boolean
                return true;
            default:
                return false;
        }
    }

    /**
     * 粗略判断是否为 zlib（gzcompress）数据头
     * @param mixed $data
     * @return bool
     */
    private static function looks_zlib($data)
    {
        if (!is_string($data) || strlen($data) < 2)
            return false;
        $b0 = ord($data[0]);
        $b1 = ord($data[1]);
        // 常见 zlib 头：78 01 / 78 5E / 78 9C / 78 DA
        return ($b0 === 0x78) && in_array($b1, array(0x01, 0x5E, 0x9C, 0xDA), true);
    }

    /**
     * 初始化缓存管理器
     * 
     * @param array $config 缓存配置
     */
    public static function init($config = array())
    {
        // 防止递归调用
        if (self::$initializing || self::$initialized) {
            return;
        }

        self::$initializing = true;

        $default_config = array(
            'enabled' => true,
            'backend' => 'file', // file, redis, memcached, database
            'prefix' => defined('TODO_CACHE_PREFIX') ? TODO_CACHE_PREFIX : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_'),
            'default_expiration' => 3600,
            'max_size' => 100 * 1024 * 1024, // 100MB
            'cleanup_interval' => 3600, // 1小时
            'compression' => true,
            'serialization' => true,
            'file_cache_dir' => ABSPATH . 'content/cache/',
            'redis_host' => '127.0.0.1',
            'redis_port' => 6379,
            'redis_password' => '',
            'memcached_servers' => array(
                array('127.0.0.1', 11211)
            )
        );

        self::$config = array_merge($default_config, $config);

        if (isset(self::$config['backend']) && self::$config['backend'] === 'file') {
            $base = isset(self::$config['file_cache_dir']) ? (string) self::$config['file_cache_dir'] : '';
            if ($base === '') {
                $base = ABSPATH . 'content/cache/';
            }
            if (substr($base, -1) !== '/') {
                $base .= '/';
            }
            $ok = true;
            if (!is_dir($base)) {
                $ok = function_exists('todo_mkdir_p') ? todo_mkdir_p($base) : @mkdir($base, 0775, true);
            }
            if ($ok && (!is_writable($base))) {
                $ok = false;
            }
            if (!$ok) {
                $tmp = rtrim((string) sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'todocms-cache' . DIRECTORY_SEPARATOR;
                if (!is_dir($tmp)) {
                    $mk = @mkdir($tmp, 0775, true);
                    if (!$mk && function_exists('todo_mkdir_p')) {
                        $mk = todo_mkdir_p($tmp);
                    }
                }
                if (is_dir($tmp) && is_writable($tmp)) {
                    self::$config['file_cache_dir'] = str_replace(DIRECTORY_SEPARATOR, '/', $tmp);
                }
            }
        }

        if (!self::$config['enabled']) {
            self::$initializing = false;
            self::$initialized = true;
            return;
        }

        // 初始化缓存后端
        self::init_backend();

        // 注册清理任务
        self::schedule_cleanup();

        self::$initializing = false;
        self::$initialized = true;

        // Use error_log to avoid recursion with Logger -> Config -> Cache -> Logger
        if (defined('TODO_DEBUG') && TODO_DEBUG) {
            error_log('Todo_Cache_Manager: Initialized with backend: ' . self::$config['backend']);
        }
    }

    /**
     * 获取缓存
     * 
     * @param string $key 缓存键
     * @param string $group 缓存组
     * @return mixed 缓存值，不存在返回false
     */
    public static function get($key, $group = 'default', $lang = null)
    {
        // 确保配置已初始化（防止递归）
        if (!self::$initialized && !self::$initializing) {
            self::init();
        }
        if (!self::$initialized || !isset(self::$config['enabled']) || !self::$config['enabled'] || !self::$backend) {
            return false;
        }

        // 性能优化：检查是否应该跳过持久化缓存
        // 1. 检查全局刷新标志
        if (defined('TODO_CACHE_REFRESHED') && TODO_CACHE_REFRESHED) {
            return false;
        }

        // 2. 检查动态模式下的 TTL 设置
        if (function_exists('todo_get_cache_ttl')) {
            if (todo_get_cache_ttl($group) === 0) {
                // 如果是编译模板，虽然 TTL 为 0，但仍需检查文件修改时间
                // 但对于普通对象缓存，TTL=0 意味着不使用持久化缓存
                if ($group !== 'templates' && $group !== 'compiled') {
                    return false;
                }
            }
        }

        $cache_key = self::build_key($key, $group, $lang);
        $value = self::$backend->get($cache_key);

        if ($value !== false) {
            self::$stats['hits']++;

            // 解压缩（优先于反序列化）。为避免在非压缩数据上调用解压导致警告：
            // 1) 新写入统一使用前缀 ':gz:' 标识压缩
            // 2) 兼容历史数据：检测 zlib 头后再尝试解压
            if (self::$config['compression'] && is_string($value)) {
                if (strpos($value, ':gz:') === 0) {
                    $decompressed = @gzuncompress(substr($value, 4));
                    if ($decompressed !== false) {
                        $value = $decompressed;
                    }
                } elseif (self::looks_zlib($value)) {
                    $decompressed = @gzuncompress($value);
                    if ($decompressed !== false) {
                        $value = $decompressed;
                    }
                }
            }

            // 反序列化（仅在看起来是序列化字符串时进行）
            if (self::$config['serialization'] && is_string($value) && self::looks_serialized($value)) {
                $unserialized = @unserialize($value);
                if ($unserialized !== false || $value === 'b:0;') {
                    $value = $unserialized;
                }
            }
        } else {
            self::$stats['misses']++;
        }

        return $value;
    }

    /**
     * 设置缓存
     * 
     * @param string $key 缓存键
     * @param mixed $value 缓存值
     * @param int $expiration 过期时间（秒）
     * @param string $group 缓存组
     * @return bool 是否成功
     */
    public static function set($key, $value, $expiration = null, $group = 'default', $lang = null)
    {
        // 确保配置已初始化（防止递归）
        if (!self::$initialized && !self::$initializing) {
            self::init();
        }
        if (!self::$initialized || !isset(self::$config['enabled']) || !self::$config['enabled'] || !self::$backend) {
            return false;
        }

        // 性能优化：检查是否应该跳过持久化缓存写入
        if (function_exists('todo_get_cache_ttl')) {
            if (todo_get_cache_ttl($group) === 0) {
                // 如果是编译模板，虽然 TTL 为 0，但仍需写入文件
                if ($group !== 'templates' && $group !== 'compiled') {
                    return true; // 模拟成功，但不写入
                }
            }
        }

        if ($expiration === null) {
            $expiration = isset(self::$groups[$group]) ? self::$groups[$group] : self::$config['default_expiration'];
        }
        $expiration = self::effective_expiration($group, $expiration);

        $cache_key = self::build_key($key, $group, $lang);

        // 序列化：开启后统一序列化，避免 get() 误判
        if (self::$config['serialization']) {
            // 避免重复序列化：若已是序列化字符串则不再处理
            if (!is_string($value) || !self::looks_serialized($value)) {
                $value = serialize($value);
            }
        }

        // 压缩：统一使用 ':gz:' 前缀做标记，避免读取时误判
        if (self::$config['compression'] && is_string($value) && strlen($value) > 1024) {
            if (strpos($value, ':gz:') !== 0) { // 避免重复压缩
                $compressed = @gzcompress($value, 6);
                if ($compressed !== false) {
                    $value = ':gz:' . $compressed;
                }
            }
        }

        $result = self::$backend->set($cache_key, $value, $expiration);

        if ($result) {
            self::$stats['sets']++;
            if (function_exists('todo_log_debug')) {
                todo_log_debug('Cache set', array('key' => $cache_key, 'expiration' => $expiration));
            }
        } else {
            if (function_exists('todo_log_warning')) {
                todo_log_warning('Cache set failed', array('key' => $cache_key));
            }
        }

        return $result;
    }

    private static function effective_expiration($group, $expiration)
    {
        $mode = 'dynamic';
        if (function_exists('todo_get_option')) {
            $mode = todo_get_option('page_mode', 'dynamic');
        } elseif (function_exists('get_option')) {
            $mode = get_option('page_mode', 'dynamic');
        }
        if ($mode !== 'dynamic') {
            return $expiration;
        }
        $ttl = 120;
        if (function_exists('todo_get_option')) {
            $ttl = (int) todo_get_option('dynamic_template_cache_ttl', 120);
        } elseif (function_exists('get_option')) {
            $ttl = (int) get_option('dynamic_template_cache_ttl', 120);
        }
        if ($ttl < 30) {
            $ttl = 30;
        } elseif ($ttl > 3600) {
            $ttl = 3600;
        }
        $override = array('posts', 'post_fields', 'models', 'tags', 'users', 'categories', 'options', 'menus', 'queries');
        if (in_array($group, $override, true)) {
            return min($expiration, $ttl);
        }
        return $expiration;
    }

    /**
     * 删除缓存
     * 
     * @param string $key 缓存键
     * @param string $group 缓存组
     * @return bool 是否成功
     */
    public static function delete($key, $group = 'default', $lang = null)
    {
        // 确保配置已初始化（防止递归）
        if (!self::$initialized && !self::$initializing) {
            self::init();
        }
        if (!self::$initialized || !isset(self::$config['enabled']) || !self::$config['enabled'] || !self::$backend) {
            return false;
        }

        $cache_key = self::build_key($key, $group, $lang);
        $result = self::$backend->delete($cache_key);

        if ($result) {
            self::$stats['deletes']++;
            if (function_exists('todo_log_debug')) {
                todo_log_debug('Cache deleted', array('key' => $cache_key));
            }
        }

        return $result;
    }

    /**
     * 根据模式删除缓存
     * 
     * @param string $pattern 缓存键模式（支持通配符*）
     * @return bool 是否成功
     */
    public static function deleteByPattern($pattern)
    {
        // 确保配置已初始化（防止递归）
        if (!self::$initialized && !self::$initializing) {
            self::init();
        }
        if (!self::$initialized || !isset(self::$config['enabled']) || !self::$config['enabled'] || !self::$backend) {
            return false;
        }

        // 如果后端支持模式删除
        if (method_exists(self::$backend, 'deleteByPattern')) {
            return self::$backend->deleteByPattern($pattern);
        }

        // 如果后端支持获取键列表
        if (method_exists(self::$backend, 'get_keys')) {
            $keys = self::$backend->get_keys($pattern);
            $deleted = 0;
            foreach ($keys as $key) {
                if (self::$backend->delete($key)) {
                    $deleted++;
                }
            }
            return $deleted > 0;
        }

        // 对于文件缓存，使用目录扫描
        if (self::$config['backend'] === 'file') {
            return self::deleteFilesByPattern($pattern);
        }

        return false;
    }

    /**
     * 清空缓存组
     * 
     * @param string $group 缓存组
     * @return bool 是否成功
     */
    public static function flush_group($group)
    {
        // 确保配置已初始化（防止递归）
        if (!self::$initialized && !self::$initializing) {
            self::init();
        }
        if (!self::$initialized || !isset(self::$config['enabled']) || !self::$config['enabled'] || !self::$backend) {
            return false;
        }

        // 新的命名空间模式：清理该组下所有语言与键
        $pattern = self::$config['prefix'] . $group . ':*';

        if (method_exists(self::$backend, 'flush_group')) {
            return self::$backend->flush_group($pattern);
        }

        // 使用新的 deleteByPattern 方法
        return self::deleteByPattern($pattern);
    }

    /**
     * 清空所有缓存
     * 
     * @return bool 是否成功
     */
    public static function flush_all()
    {
        // 确保配置已初始化（防止递归）
        if (!self::$initialized && !self::$initializing) {
            self::init();
        }
        if (!self::$initialized || !isset(self::$config['enabled']) || !self::$config['enabled'] || !self::$backend) {
            return false;
        }

        $result = self::$backend->flush_all();

        if ($result) {
            self::$stats = array(
                'hits' => 0,
                'misses' => 0,
                'sets' => 0,
                'deletes' => 0
            );
            if (function_exists('todo_log')) {
                todo_log('info', 'All cache flushed');
            }
        }

        return $result;
    }

    /**
     * 获取或设置缓存（如果不存在则执行回调）
     * 
     * @param string $key 缓存键
     * @param callable $callback 回调函数
     * @param int $expiration 过期时间
     * @param string $group 缓存组
     * @return mixed 缓存值
     */
    public static function remember($key, $callback, $expiration = null, $group = 'default', $lang = null)
    {
        $value = self::get($key, $group, $lang);

        if ($value === false && is_callable($callback)) {
            $value = call_user_func($callback);
            if ($value !== false) {
                self::set($key, $value, $expiration, $group, $lang);
            }
        }

        return $value;
    }

    /**
     * 增加缓存值（仅数字）
     * 
     * @param string $key 缓存键
     * @param int $offset 增加量
     * @param string $group 缓存组
     * @return int|false 新值或false
     */
    public static function increment($key, $offset = 1, $group = 'default', $lang = null)
    {
        // 确保配置已初始化（防止递归）
        if (!self::$initialized && !self::$initializing) {
            self::init();
        }
        if (!self::$initialized || !isset(self::$config['enabled']) || !self::$config['enabled'] || !self::$backend) {
            return false;
        }

        $cache_key = self::build_key($key, $group, $lang);

        if (method_exists(self::$backend, 'increment')) {
            return self::$backend->increment($cache_key, $offset);
        }

        // 回退实现
        $value = self::get($key, $group, $lang);
        if ($value === false) {
            $value = 0;
        }

        $new_value = intval($value) + $offset;
        self::set($key, $new_value, null, $group, $lang);

        return $new_value;
    }

    /**
     * 减少缓存值（仅数字）
     * 
     * @param string $key 缓存键
     * @param int $offset 减少量
     * @param string $group 缓存组
     * @return int|false 新值或false
     */
    public static function decrement($key, $offset = 1, $group = 'default', $lang = null)
    {
        return self::increment($key, -$offset, $group, $lang);
    }

    /**
     * 获取缓存统计信息
     * 
     * @return array 统计信息
     */
    public static function get_stats()
    {
        $stats = self::$stats;
        $stats['hit_ratio'] = $stats['hits'] + $stats['misses'] > 0
            ? round($stats['hits'] / ($stats['hits'] + $stats['misses']) * 100, 2)
            : 0;

        if (method_exists(self::$backend, 'get_stats')) {
            $backend_stats = self::$backend->get_stats();
            $stats = array_merge($stats, $backend_stats);
        }

        return $stats;
    }

    /**
     * 构建缓存键
     * 
     * @param string $key 原始键
     * @param string $group 缓存组
     * @return string 完整缓存键
     */
    private static function build_key($key, $group, $lang = null)
    {
        // 确保配置已初始化（防止在初始化过程中访问未初始化的配置）
        if (empty(self::$config) || !isset(self::$config['prefix'])) {
            // 如果配置未初始化，使用默认值
            $prefix = defined('TODO_CACHE_PREFIX') ? TODO_CACHE_PREFIX : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_');
        } else {
            $prefix = self::$config['prefix'];
        }

        // 将语言纳入命名空间：prefix + group + :lang: + md5(key)
        if (is_string($lang) && strtolower($lang) === 'global') {
            return $prefix . $group . ':global:' . md5($key);
        }

        if ($lang === null) {
            // 在初始化过程中，避免调用可能触发缓存操作的函数
            if (self::$initializing) {
                $lang = 'zh-CN'; // 使用默认语言，避免递归
            } elseif (function_exists('current_locale')) {
                $lang = current_locale();
            } elseif (function_exists('get_default_language')) {
                $lang = get_default_language();
            } else {
                $lang = 'zh-CN';
            }
        }
        // 规范语言代码格式
        if (!self::$initializing && function_exists('canonical_locale')) {
            $lang = canonical_locale($lang);
        } else {
            $lang = str_replace('_', '-', $lang);
        }
        return $prefix . $group . ':' . $lang . ':' . md5($key);
    }

    /**
     * 初始化缓存后端
     */
    private static function init_backend()
    {
        switch (self::$config['backend']) {
            case 'redis':
                self::$backend = new Todo_Cache_Redis(self::$config);
                break;

            case 'memcached':
                self::$backend = new Todo_Cache_Memcached(self::$config);
                break;

            case 'database':
                self::$backend = new Todo_Cache_Database(self::$config);
                break;

            case 'file':
            default:
                self::$backend = new Todo_Cache_File(self::$config);
                break;
        }
    }

    /**
     * 根据模式删除文件缓存
     * 
     * @param string $pattern 缓存键模式
     * @return bool 是否成功
     */
    private static function deleteFilesByPattern($pattern)
    {
        if (self::$config['backend'] !== 'file' || !self::$backend) {
            return false;
        }

        // 将模式转换为正则表达式
        $regex_pattern = str_replace('*', '.*', preg_quote($pattern, '/'));
        $regex_pattern = '/^' . $regex_pattern . '$/';

        $cache_dir = self::$config['file_cache_dir'] . 'objects/';
        if (!is_dir($cache_dir)) {
            return false;
        }

        $deleted = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'cache') {
                $filename = $file->getBasename('.cache');

                // 尝试从文件内容中获取原始键名
                $data = @file_get_contents($file->getPathname());
                if ($data !== false) {
                    $cache_data = @unserialize($data);
                    if ($cache_data !== false && isset($cache_data['key'])) {
                        if (preg_match($regex_pattern, $cache_data['key'])) {
                            @unlink($file->getPathname());
                            $deleted++;
                        }
                    }
                }
            }
        }

        return $deleted > 0;
    }

    /**
     * 安排清理任务
     */
    private static function schedule_cleanup()
    {
        if (function_exists('todo_add_action')) {
            todo_add_action('todo_init', array(__CLASS__, 'maybe_trigger_cleanup'), 999);
        } elseif (function_exists('add_action')) {
            add_action('todo_init', array(__CLASS__, 'maybe_trigger_cleanup'), 999);
        }
    }

    public static function maybe_trigger_cleanup()
    {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }

        $now = time();
        $last_run = 0;
        if (function_exists('todo_get_option')) {
            $last_run = (int) todo_get_option('todo_cache_cleanup_last_run', 0);
        } elseif (function_exists('get_option')) {
            $last_run = (int) get_option('todo_cache_cleanup_last_run', 0);
        }

        if ($last_run > 0 && ($now - $last_run) < 3600) {
            return;
        }

        if (function_exists('update_option')) {
            update_option('todo_cache_cleanup_last_run', $now);
        }

        self::cleanup_expired();
    }

    /**
     * 清理过期缓存
     */
    public static function cleanup_expired()
    {
        // 确保配置已初始化（防止递归）
        if (!self::$initialized && !self::$initializing) {
            self::init();
        }
        if (!self::$initialized || !isset(self::$config['enabled']) || !self::$config['enabled'] || !self::$backend) {
            return;
        }

        if (method_exists(self::$backend, 'cleanup_expired')) {
            $cleaned = self::$backend->cleanup_expired();
            if (function_exists('todo_log')) {
                todo_log('info', 'Cache cleanup completed', array('cleaned' => $cleaned));
            }
        }
    }

    /**
     * 添加缓存组
     * 
     * @param string $group 组名
     * @param int $expiration 默认过期时间
     */
    public static function add_group($group, $expiration = 3600)
    {
        self::$groups[$group] = $expiration;
    }

    /**
     * 获取缓存配置
     * 
     * @return array 配置数组
     */
    public static function get_config()
    {
        return self::$config;
    }
}

/**
 * 文件缓存后端
 */
class Todo_Cache_File
{
    private $cache_dir;
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
        $base = isset($config['file_cache_dir']) ? (string) $config['file_cache_dir'] : (defined('ABSPATH') ? (ABSPATH . 'content/cache/') : '');
        if ($base === '') {
            $base = rtrim((string) sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'todocms-cache' . DIRECTORY_SEPARATOR;
        }
        if (substr($base, -1) !== '/') {
            $base .= '/';
        }
        $this->cache_dir = rtrim($base, '/') . '/objects/';

        if (!is_dir($this->cache_dir)) {
            todo_mkdir_p($this->cache_dir);
        }

        if (!is_writable($this->cache_dir)) {
            $tmp = rtrim((string) sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'todocms-cache' . DIRECTORY_SEPARATOR . 'objects' . DIRECTORY_SEPARATOR;
            if (!is_dir($tmp)) {
                @mkdir($tmp, 0775, true);
                if (function_exists('todo_mkdir_p')) {
                    todo_mkdir_p($tmp);
                }
            }
            if (is_dir($tmp) && is_writable($tmp)) {
                $this->cache_dir = str_replace(DIRECTORY_SEPARATOR, '/', $tmp);
            }
        }
    }

    public function get($key)
    {
        $file = $this->get_cache_file($key);

        if (!file_exists($file)) {
            return false;
        }

        $data = @file_get_contents($file);
        if ($data === false) {
            return false;
        }

        $cache_data = @unserialize($data);
        if ($cache_data === false) {
            @unlink($file);
            return false;
        }

        $mode = 'dynamic';
        if (function_exists('todo_get_option')) {
            $mode = todo_get_option('page_mode', 'dynamic');
        } elseif (function_exists('get_option')) {
            $mode = get_option('page_mode', 'dynamic');
        }
        if ($mode === 'dynamic' && isset($cache_data['created']) && isset($cache_data['key'])) {
            $full = (string) $cache_data['key'];
            $pos = strpos($full, ':');
            $seg = ($pos !== false) ? substr($full, 0, $pos) : $full;
            $cfg = array();
            if (class_exists('Todo_Cache_Manager') && method_exists('Todo_Cache_Manager', 'get_config')) {
                $cfg = (array) Todo_Cache_Manager::get_config();
            }
            $prefix = isset($cfg['prefix']) ? $cfg['prefix'] : (defined('TODO_CACHE_PREFIX') ? TODO_CACHE_PREFIX : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_'));
            $group = (strpos($seg, $prefix) === 0) ? substr($seg, strlen($prefix)) : $seg;
            $override = array('posts', 'post_fields', 'models', 'tags', 'users', 'categories', 'options', 'menus', 'queries', 'theme', 'fragments');
            if (in_array($group, $override, true)) {
                $ttl = 120;
                if (function_exists('todo_get_option')) {
                    $ttl = (int) todo_get_option('dynamic_template_cache_ttl', 120);
                } elseif (function_exists('get_option')) {
                    $ttl = (int) get_option('dynamic_template_cache_ttl', 120);
                }
                if ($ttl === 0) {
                    @unlink($file);
                    return false;
                }
                if ($ttl < 30) {
                    $ttl = 30;
                } elseif ($ttl > 3600) {
                    $ttl = 3600;
                }
                if (time() > ((int) $cache_data['created'] + $ttl)) {
                    @unlink($file);
                    return false;
                }
            }
        }

        // 检查是否过期
        if ($cache_data['expiration'] > 0 && time() > $cache_data['expiration']) {
            @unlink($file);
            return false;
        }

        return $cache_data['value'];
    }

    public function set($key, $value, $expiration)
    {
        $file = $this->get_cache_file($key);
        $dir = dirname($file);

        if (!is_dir($dir)) {
            todo_mkdir_p($dir);
        }

        $cache_data = array(
            'key' => $key,  // 保存原始键名以支持模式删除
            'value' => $value,
            'expiration' => $expiration > 0 ? time() + $expiration : 0,
            'created' => time()
        );

        $data = serialize($cache_data);
        return @file_put_contents($file, $data, LOCK_EX) !== false;
    }

    public function delete($key)
    {
        $file = $this->get_cache_file($key);
        // 若文件不存在或已被其他进程删除，视为成功，避免产生警告
        clearstatcache(true, $file);
        if (!is_string($file) || $file === '') {
            return false;
        }
        if (!file_exists($file)) {
            // 已被清理或从未创建，无需再次删除
            return true;
        }
        if (!is_file($file)) {
            // 非普通文件（可能是目录或链接），不执行删除
            return false;
        }
        $result = @unlink($file);
        if ($result) {
            return true;
        }
        clearstatcache(true, $file);
        return !file_exists($file);
    }

    public function flush_all()
    {
        $this->delete_directory($this->cache_dir);
        todo_mkdir_p($this->cache_dir);
        return true;
    }

    public function cleanup_expired()
    {
        $cleaned = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->cache_dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'cache') {
                $data = @file_get_contents($file->getPathname());
                if ($data !== false) {
                    $cache_data = @unserialize($data);
                    if (
                        $cache_data !== false &&
                        $cache_data['expiration'] > 0 &&
                        time() > $cache_data['expiration']
                    ) {
                        @unlink($file->getPathname());
                        $cleaned++;
                    }
                }
            }
        }

        return $cleaned;
    }

    private function get_cache_file($key)
    {
        $hash = md5($key);
        $dir1 = substr($hash, 0, 2);
        $dir2 = substr($hash, 2, 2);
        return $this->cache_dir . $dir1 . '/' . $dir2 . '/' . $hash . '.cache';
    }

    private function delete_directory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->delete_directory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

/**
 * Redis缓存后端（如果需要）
 */
class Todo_Cache_Redis
{
    private $redis;
    private $config;

    public function __construct($config)
    {
        $this->config = $config;

        if (!class_exists('Redis')) {
            throw new Exception('Redis extension not installed');
        }

        $redisClass = '\\Redis';
        $this->redis = new $redisClass();
        $this->redis->connect($config['redis_host'], $config['redis_port']);

        if (!empty($config['redis_password'])) {
            $this->redis->auth($config['redis_password']);
        }
    }

    public function get($key)
    {
        return $this->redis->get($key);
    }

    public function set($key, $value, $expiration)
    {
        if ($expiration > 0) {
            return $this->redis->setex($key, $expiration, $value);
        } else {
            return $this->redis->set($key, $value);
        }
    }

    public function delete($key)
    {
        return $this->redis->del($key) > 0;
    }

    public function flush_all()
    {
        return $this->redis->flushDB();
    }

    public function increment($key, $offset = 1)
    {
        return $this->redis->incrBy($key, $offset);
    }

    public function get_stats()
    {
        $info = $this->redis->info();
        return array(
            'memory_used' => $info['used_memory_human'] ?? 'N/A',
            'connections' => $info['connected_clients'] ?? 'N/A',
            'hits' => $info['keyspace_hits'] ?? 0,
            'misses' => $info['keyspace_misses'] ?? 0
        );
    }
}

/**
 * Memcached缓存后端
 */
class Todo_Cache_Memcached
{
    private $memcached;
    private $config;

    public function __construct($config)
    {
        $this->config = $config;

        if (!class_exists('Memcached')) {
            throw new Exception('Memcached extension not installed');
        }

        $memcachedClass = '\\Memcached';
        $this->memcached = new $memcachedClass();

        // 添加服务器
        $servers = isset($config['memcached_servers']) ? $config['memcached_servers'] : array(
            array('127.0.0.1', 11211)
        );

        foreach ($servers as $server) {
            $this->memcached->addServer($server[0], $server[1]);
        }
    }

    public function get($key)
    {
        $value = $this->memcached->get($key);
        return $value !== false ? $value : false;
    }

    public function set($key, $value, $expiration)
    {
        $expiration = $expiration > 0 ? time() + $expiration : 0;
        return $this->memcached->set($key, $value, $expiration);
    }

    public function delete($key)
    {
        return $this->memcached->delete($key);
    }

    public function flush_all()
    {
        return $this->memcached->flush();
    }

    public function increment($key, $offset = 1)
    {
        return $this->memcached->increment($key, $offset);
    }

    public function get_stats()
    {
        $stats = $this->memcached->getStats();
        $server_stats = reset($stats);

        return array(
            'memory_used' => isset($server_stats['bytes']) ? $server_stats['bytes'] : 'N/A',
            'connections' => isset($server_stats['curr_connections']) ? $server_stats['curr_connections'] : 'N/A',
            'hits' => isset($server_stats['get_hits']) ? $server_stats['get_hits'] : 0,
            'misses' => isset($server_stats['get_misses']) ? $server_stats['get_misses'] : 0
        );
    }
}

/**
 * 数据库缓存后端
 */
class Todo_Cache_Database
{
    private $config;
    private $table_name;

    public function __construct($config)
    {
        $this->config = $config;
        $this->table_name = isset($config['cache_table']) ? $config['cache_table'] : 'todo_cache';
    }

    public function get($key)
    {
        $cache_key = $this->build_cache_key($key);

        $result = Todo_DB_Prepared::get_row(
            "SELECT cache_value, expiration FROM {$this->table_name} WHERE cache_key = ?",
            array($cache_key)
        );

        if (!$result) {
            return false;
        }

        // 处理结果可能是数组或对象的情况
        $expiration = is_object($result) ? $result->expiration : $result['expiration'];
        $cache_value = is_object($result) ? $result->cache_value : $result['cache_value'];

        // 检查是否过期
        if ($expiration > 0 && time() > $expiration) {
            $this->delete($key);
            return false;
        }

        return maybe_unserialize($cache_value);
    }

    public function set($key, $value, $expiration)
    {
        $cache_key = $this->build_cache_key($key);
        $cache_value = maybe_serialize($value);
        $expiration_time = $expiration > 0 ? time() + $expiration : 0;

        $result = Todo_DB_Prepared::query(
            "INSERT INTO {$this->table_name} (cache_key, cache_value, expiration, created_at) 
             VALUES (?, ?, ?, ?) 
             ON DUPLICATE KEY UPDATE 
             cache_value = VALUES(cache_value), 
             expiration = VALUES(expiration), 
             created_at = VALUES(created_at)",
            array($cache_key, $cache_value, $expiration_time, time())
        );

        return $result !== false;
    }

    public function delete($key)
    {
        $cache_key = $this->build_cache_key($key);

        $result = Todo_DB_Prepared::query(
            "DELETE FROM {$this->table_name} WHERE cache_key = ?",
            array($cache_key)
        );

        return $result !== false;
    }

    public function flush_all()
    {
        global $tododb;

        return $tododb->query("TRUNCATE TABLE {$this->table_name}") !== false;
    }

    public function cleanup_expired()
    {
        global $tododb;

        $result = false;
        $result = $tododb->query(
            "DELETE FROM {$this->table_name} WHERE expiration > 0 AND expiration < " . time()
        );

        // 获取受影响的行数
        $affected_rows = 0;
        if ($result !== false) {
            // 对于删除操作，query方法返回受影响的行数
            if (is_numeric($result)) {
                $affected_rows = (int) $result;
            } else {
                // 如果无法获取受影响行数，返回1表示操作成功
                $affected_rows = 1;
            }
        }

        return $affected_rows;
    }

    public function get_stats()
    {
        global $tododb;

        // 检查表是否存在
        if (!table_exists($this->table_name)) {
            return array(
                'total_entries' => 0,
                'expired_entries' => 0,
                'active_entries' => 0
            );
        }

        $total = $tododb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $expired = $tododb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE expiration > 0 AND expiration < " . time()
        );

        return array(
            'total_entries' => $total,
            'expired_entries' => $expired,
            'active_entries' => $total - $expired
        );
    }

    private function build_cache_key($key)
    {
        return md5($key);
    }
}

// 便捷函数
if (!function_exists('todo_cache_get')) {
    /**
     * 便捷函数：获取缓存
     */
    function todo_cache_get($key, $group = 'default', $lang = null)
    {
        return Todo_Cache_Manager::get($key, $group, $lang);
    }
}

if (!function_exists('todo_cache_set')) {
    /**
     * 便捷函数：设置缓存
     */
    function todo_cache_set($key, $value, $expiration = null, $group = 'default', $lang = null)
    {
        return Todo_Cache_Manager::set($key, $value, $expiration, $group, $lang);
    }
}

if (!function_exists('todo_cache_delete')) {
    /**
     * 便捷函数：删除缓存
     */
    function todo_cache_delete($key, $group = 'default', $lang = null)
    {
        return Todo_Cache_Manager::delete($key, $group, $lang);
    }
}

if (!function_exists('todo_cache_remember')) {
    /**
     * 便捷函数：记忆缓存
     */
    function todo_cache_remember($key, $callback, $expiration = null, $group = 'default', $lang = null)
    {
        return Todo_Cache_Manager::remember($key, $callback, $expiration, $group, $lang);
    }
}
