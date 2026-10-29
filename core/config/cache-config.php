<?php
/**
 * 缓存系统配置文件
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

/**
 * 缓存系统配置
 */
define('TODO_CACHE_ENABLED', true);
define('TODO_CACHE_DEFAULT_BACKEND', 'file'); // redis, memcached, file
define('TODO_CACHE_DEFAULT_TTL', 3600); // 1小时
if (!defined('TODO_CACHE_PREFIX')) {
    define('TODO_CACHE_PREFIX', defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_');
}

/**
 * 缓存目录配置
 */
define('TODO_CACHE_DIR', ABSPATH . 'content/cache/');
define('TODO_CACHE_OBJECTS_DIR', TODO_CACHE_DIR . 'objects/');
define('TODO_CACHE_TEMPLATES_DIR', TODO_CACHE_DIR . 'templates/');

/**
 * Redis配置（如果使用Redis作为缓存后端）
 */
define('TODO_REDIS_HOST', '127.0.0.1');
define('TODO_REDIS_PORT', 6379);
define('TODO_REDIS_PASSWORD', '');
define('TODO_REDIS_DATABASE', 0);
define('TODO_REDIS_TIMEOUT', 5);

/**
 * Memcached配置（如果使用Memcached作为缓存后端）
 */
define('TODO_MEMCACHED_HOST', '127.0.0.1');
define('TODO_MEMCACHED_PORT', 11211);

/**
 * 缓存组配置
 */
$todo_cache_groups = array(
    'posts' => array(
        'ttl' => 3600,
        'description' => '文章内容缓存'
    ),
    'categories' => array(
        'ttl' => 7200,
        'description' => '分类数据缓存'
    ),
    'tags' => array(
        'ttl' => 7200,
        'description' => '标签数据缓存'
    ),
    'users' => array(
        'ttl' => 1800,
        'description' => '用户数据缓存'
    ),
    'templates' => array(
        'ttl' => 86400,
        'description' => '模板编译缓存'
    ),
    'queries' => array(
        'ttl' => 1800,
        'description' => '数据库查询缓存'
    ),
    'options' => array(
        'ttl' => 3600,
        'description' => '系统选项缓存'
    ),
    'menus' => array(
        'ttl' => 7200,
        'description' => '菜单数据缓存'
    ),
    'widgets' => array(
        'ttl' => 3600,
        'description' => '小工具缓存'
    ),
    'media' => array(
        'ttl' => 86400,
        'description' => '媒体文件缓存'
    )
);

/**
 * 缓存预热配置
 */
// URL 严格规范化（todo_* 风格），用于缓存键一致性与去重
if (!function_exists('todo_normalize_url_for_cache')) {
    function todo_normalize_url_for_cache($url) {
        if (!is_string($url) || $url === '') {
            return null;
        }
        $url = trim($url);
        // 去除潜在的右括号/空白，并规范结尾斜杠（与 Unified Cache Manager 严格校验一致）
        $url = rtrim($url, ") \t\n\r\0\x0B");
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }
        return rtrim($url, '/');
    }
}

// 严格模式计算首页URL，避免不同写法产生重复缓存（与 Unified Cache Manager 严格校验一致）
$todo_warmup_home_url = null;
if (function_exists('todo_home_url')) {
    $home = todo_home_url();
    // 严格校验，过滤掉异常/不完整URL
    if (is_string($home)) {
        $home = trim($home);
        // 去除潜在的右括号/空白，并规范结尾斜杠
        $home = rtrim($home, ") \t\n\r\0\x0B");
        if (preg_match('#^https?://#i', $home)) {
            $todo_warmup_home_url = rtrim($home, '/');
        }
    }
} elseif (function_exists('home_url')) {
    // 兼容旧函数，仍然严格处理
    $home = home_url('/');
    if (is_string($home)) {
        $home = trim($home);
        $home = rtrim($home, ") \t\n\r\0\x0B");
        if (preg_match('#^https?://#i', $home)) {
            $todo_warmup_home_url = rtrim($home, '/');
        }
    }
}

$todo_cache_warmup_config = array(
    'enabled' => true,
    'urls' => array(
        $todo_warmup_home_url,
        home_url('/about/'),
        home_url('/contact/'),
        home_url('/products/'),
        home_url('/news/')
    ),
    'concurrent' => 3,
    'timeout' => 30,
    'user_agent' => 'TodoCMS Cache Warmup Bot/1.0'
);
// 对所有预热URL执行严格规范化、去空、去重并重建索引
$todo_cache_warmup_config['urls'] = array_map('todo_normalize_url_for_cache', $todo_cache_warmup_config['urls']);
$todo_cache_warmup_config['urls'] = array_values(array_unique(array_filter($todo_cache_warmup_config['urls'])));
/**
 * 自动清理配置
 */
$todo_cache_auto_cleanup_config = array(
    'enabled' => false,
    'schedule' => 'daily', // hourly, daily, weekly
    'max_age' => 86400 * 7, // 7天
    'max_size' => 100 * 1024 * 1024, // 100MB
    'cleanup_empty_dirs' => true,
    'log_cleanup' => true
);

/**
 * 缓存排除规则
 */
$todo_cache_exclude_rules = array(
    'urls' => array(
        '/admin/*',
        '/login*',
        '/logout*',
        '/register*',
        '/cart*',
        '/checkout*',
        '/my-account*',
        '/users/*',
        '/users*',
        '/user*'
    ),
    'query_vars' => array(
        'preview',
        'debug',
        'nocache',
        's', // 搜索页面
        'user'
    ),
    'user_agents' => array(
        'bot',
        'crawler',
        'spider'
    )
);

/**
 * 缓存压缩配置
 */
$todo_cache_compression_config = array(
    'enabled' => true,
    'method' => 'gzip', // gzip, deflate
    'level' => 6, // 1-9
    'min_size' => 1024 // 最小压缩文件大小（字节）
);

/**
 * 缓存统计配置
 */
$todo_cache_stats_config = array(
    'enabled' => true,
    'track_hits' => true,
    'track_misses' => true,
    'track_size' => true,
    'retention_days' => 30
);

/**
 * 初始化缓存系统
 */
function todo_init_cache_system() {
    global $todo_cache_groups, $todo_cache_warmup_config, $todo_cache_auto_cleanup_config;
    
    // 确保缓存目录存在
    todo_ensure_cache_directories();
    
    // 初始化统一缓存管理器
    if (class_exists('Todo_Unified_Cache_Manager')) {
        // 从数据库获取配置，覆盖硬编码的默认值
        $db_auto_cleanup = get_option('todo_auto_cleanup_config', array());
        if (!empty($db_auto_cleanup)) {
            $todo_cache_auto_cleanup_config = array_merge($todo_cache_auto_cleanup_config, $db_auto_cleanup);
        }

        Todo_Unified_Cache_Manager::init(array(
            'warmup' => $todo_cache_warmup_config,
            'auto_cleanup' => $todo_cache_auto_cleanup_config
        ));
    }
    
    // 初始化对象缓存
    if (class_exists('Todo_Cache_Manager')) {
        Todo_Cache_Manager::init(array(
            'backend' => TODO_CACHE_DEFAULT_BACKEND,
            'prefix' => TODO_CACHE_PREFIX,
            'default_ttl' => TODO_CACHE_DEFAULT_TTL,
            'groups' => $todo_cache_groups,
            'file_cache_dir' => TODO_CACHE_DIR
        ));
    }
}

/**
 * 确保缓存目录存在
 */
function todo_ensure_cache_directories() {
    $directories = array(
        TODO_CACHE_DIR,
        TODO_CACHE_OBJECTS_DIR,
        TODO_CACHE_TEMPLATES_DIR,
    );
    
    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            if (function_exists('todo_mkdir_p')) {
                todo_mkdir_p($dir);
            } else {
                @mkdir($dir, 0775, true);
            }
            
            // 创建.htaccess文件保护缓存目录
            $htaccess_file = $dir . '.htaccess';
            if (!file_exists($htaccess_file)) {
                file_put_contents($htaccess_file, "Deny from all\n");
            }
            
            // 创建index.php文件
            $index_file = $dir . 'index.php';
            if (!file_exists($index_file)) {
                file_put_contents($index_file, "<?php\n// Silence is golden.\n");
            }
        }
    }
}

/**
 * 获取缓存配置
 * 
 * @param string $key 配置键名
 * @return mixed 配置值
 */
function todo_get_cache_config($key = null) {
    global $todo_cache_groups, $todo_cache_warmup_config, $todo_cache_auto_cleanup_config;
    global $todo_cache_exclude_rules, $todo_cache_compression_config, $todo_cache_stats_config;
    
    $config = array(
        'groups' => $todo_cache_groups,
        'warmup' => $todo_cache_warmup_config,
        'auto_cleanup' => $todo_cache_auto_cleanup_config,
        'exclude_rules' => $todo_cache_exclude_rules,
        'compression' => $todo_cache_compression_config,
        'stats' => $todo_cache_stats_config
    );
    
    if ($key) {
        return isset($config[$key]) ? $config[$key] : null;
    }
    
    return $config;
}

/**
 * 更新缓存配置
 * 
 * @param string $key 配置键名
 * @param mixed $value 配置值
 */
function todo_update_cache_config($key, $value) {
    $current_config = get_option('todo_cache_config', array());
    $current_config[$key] = $value;
    update_option('todo_cache_config', $current_config);
}

/**
 * 检查是否应该缓存当前请求
 * 
 * @return bool 是否应该缓存
 */
function todo_should_cache_request() {
    global $todo_cache_exclude_rules;
    
    // 检查是否是缓存预热请求（通过X-TODO-WARMUP header或User-Agent）
    $is_warmup_request = false;
    if (isset($_SERVER['HTTP_X_TODO_WARMUP']) && $_SERVER['HTTP_X_TODO_WARMUP'] === '1') {
        $is_warmup_request = true;
    } else {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (stripos($user_agent, 'TodoCMS Cache Warmup') !== false) {
            $is_warmup_request = true;
        }
    }
    
    // 如果是缓存被禁用
    if (!TODO_CACHE_ENABLED) {
        return false;
    }
    
    // 如果是管理员页面
    if (is_admin()) {
        return false;
    }
    
    // 如果用户已登录（但预热请求允许缓存，即使已登录）
    if (is_user_logged_in() && !$is_warmup_request) {
        return false;
    }
    
    // 检查URL排除规则
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    foreach ($todo_cache_exclude_rules['urls'] as $pattern) {
        if (fnmatch($pattern, $request_uri)) {
            return false;
        }
    }
    
    // 检查查询参数排除规则
    foreach ($todo_cache_exclude_rules['query_vars'] as $var) {
        if (isset($_GET[$var])) {
            return false;
        }
    }
    
    // 检查User-Agent排除规则（对内部预热 UA 放行）
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ua_whitelist = array('TodoCMS Cache Warmup');
    $skip_ua_check = false;
    foreach ($ua_whitelist as $needle) {
        if ($needle && stripos($user_agent, $needle) !== false) {
            $skip_ua_check = true;
            break;
        }
    }
    if (!$skip_ua_check) {
        foreach ($todo_cache_exclude_rules['user_agents'] as $pattern) {
            if (stripos($user_agent, $pattern) !== false) {
                return false;
            }
        }
    }
    
    return true;
}

// 在系统初始化时自动加载缓存配置
if (!defined('TODO_CACHE_INIT_DONE')) {
    define('TODO_CACHE_INIT_DONE', true);
    
    // 注册初始化钩子（使用更高优先级，确保在其它 todo_init 任务之前完成，如定时发布）
    if (function_exists('todo_add_action')) {
        todo_add_action('todo_init', 'todo_init_cache_system', 0);
    } else {
        // 如果钩子系统还未加载，直接初始化
        todo_init_cache_system();
    }
}