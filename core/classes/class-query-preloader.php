<?php
/**
 * 查询预加载器
 * 
 * 在请求早期预加载关键查询，减少后续查询时间
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 查询预加载器类
 */
class Todo_Query_Preloader {
    
    /**
     * 是否已预加载
     * @var bool
     */
    private static $preloaded = false;
    
    /**
     * 预加载关键查询
     * 
     * @param bool $force 是否强制重新预加载
     */
    public static function preload_critical_queries($force = false) {
        if (self::$preloaded && !$force) {
            return;
        }
        
        global $tododb;
        if (!$tododb) {
            return;
        }
        
        // 预加载自动加载的选项（最常用）
        self::preload_autoload_options();
        
        // 预加载当前语言数据
        self::preload_language_data();
        
        // 预加载当前用户数据（如果已登录）
        self::preload_current_user();
        
        // 预加载站点配置
        self::preload_site_config();
        
        self::$preloaded = true;
    }
    
    /**
     * 预加载自动加载的选项
     */
    private static function preload_autoload_options() {
        global $tododb;
        
        // 检查缓存
        if (class_exists('Todo_Cache_Manager')) {
            $cache_key = 'autoload_options';
            $cached = Todo_Cache_Manager::get($cache_key, 'options', 'global');
            if ($cached !== false && is_array($cached)) {
                // 将选项加载到内存
                // 确保 CMS_Option 类已加载
                if (!class_exists('CMS_Option')) {
                    $option_file = ABSPATH . 'core/classes/class-option.php';
                    if (file_exists($option_file)) {
                        require_once $option_file;
                    }
                }
                // 检查 CMS_Option 类是否存在且 set_cached 方法可用
                if (class_exists('CMS_Option') && is_callable(['CMS_Option', 'set_cached'])) {
                    foreach ($cached as $name => $value) {
                        CMS_Option::set_cached($name, $value);
                    }
                }
                return;
            }
        }
        
        // 查询数据库（使用 system_config 表，与 CMS_Option 类保持一致）
        try {
            $options = $tododb->get_results(
                "SELECT config_key, config_value, config_type FROM " . TABLE_PREFIX . "system_config"
            );
            
            if ($options && is_array($options)) {
                $options_array = [];
                foreach ($options as $option) {
                    $name = is_object($option) ? $option->config_key : $option['config_key'];
                    $config_value = is_object($option) ? $option->config_value : $option['config_value'];
                    $config_type = is_object($option) ? $option->config_type : $option['config_type'];
                    
                    // 根据配置类型处理值（与 CMS_Option 类保持一致）
                    // convert_value 是私有方法，所以我们需要自己处理
                    $value = $config_value;
                    if ($config_type === 'array' || $config_type === 'object') {
                        $value = maybe_unserialize($config_value);
                    } elseif ($config_type === 'int' || $config_type === 'integer') {
                        $value = intval($config_value);
                    } elseif ($config_type === 'float' || $config_type === 'double') {
                        $value = floatval($config_value);
                    } elseif ($config_type === 'bool' || $config_type === 'boolean') {
                        $value = ($config_value === '1' || $config_value === 'true' || $config_value === true);
                    } elseif ($config_type === 'json') {
                        $decoded = json_decode($config_value, true);
                        $value = ($decoded !== null) ? $decoded : $config_value;
                    }
                    
                    // 缓存到内存
                    // 确保 CMS_Option 类已加载
                    if (!class_exists('CMS_Option')) {
                        $option_file = ABSPATH . 'core/classes/class-option.php';
                        if (file_exists($option_file)) {
                            require_once $option_file;
                        }
                    }
                    // 检查 CMS_Option 类是否存在且 set_cached 方法可用
                    if (class_exists('CMS_Option') && is_callable(['CMS_Option', 'set_cached'])) {
                        CMS_Option::set_cached($name, $value);
                    }
                    
                    $options_array[$name] = $value;
                }
                
                // 持久化缓存
                if (class_exists('Todo_Cache_Manager')) {
                    Todo_Cache_Manager::set('autoload_options', $options_array, 3600, 'options', 'global');
                }
            }
        } catch (Exception $e) {
            // 查询失败，记录错误但不中断执行
            error_log('Todo_Query_Preloader::preload_autoload_options error: ' . $e->getMessage());
        }
    }
    
    /**
     * 预加载当前语言数据
     */
    private static function preload_language_data() {
        $lang = null;
        if (function_exists('get_current_language')) {
            $lang = get_current_language();
        } elseif (function_exists('todo_get_current_language')) {
            $lang = todo_get_current_language();
        }
        
        if (!$lang) {
            return;
        }
        
        // 预加载语言配置
        if (class_exists('Todo_Cache_Manager')) {
            $cache_key = 'language_config_' . $lang;
            $cached = Todo_Cache_Manager::get($cache_key, 'language');
            if ($cached !== false) {
                return;
            }
        }
        
        // 查询语言配置并写入缓存
        try {
            $language_data = array();
            
            // 从 LanguageRegistry 获取语言配置
            if (class_exists('LanguageRegistry')) {
                $registry = LanguageRegistry::get_instance();
                $lang_info = $registry->language_by_locale($lang);
                if ($lang_info) {
                    $language_data = array(
                        'locale' => $lang,
                        'name' => $lang_info['title'] ?? $lang,
                        'native_name' => $lang_info['title'] ?? $lang,
                        'flag' => $lang_info['flag'] ?? '',
                        'is_default' => !empty($lang_info['is_default']),
                        'direction' => $lang_info['direction'] ?? 'ltr',
                        'date_format' => $lang_info['date_format'] ?? 'Y-m-d',
                        'time_format' => $lang_info['time_format'] ?? 'H:i:s'
                    );
                }
            }
            
            // 如果 LanguageRegistry 没有数据，尝试从 LanguageManager 获取
            if (empty($language_data) && class_exists('TodoLanguageManager')) {
                $manager = TodoLanguageManager::get_instance();
                $supported = $manager->get_supported_languages();
                if (isset($supported[$lang])) {
                    $language_data = $supported[$lang];
                }
            }
            
            // 如果仍然没有数据，使用默认值
            if (empty($language_data)) {
                $language_data = array(
                    'locale' => $lang,
                    'name' => $lang,
                    'native_name' => $lang,
                    'flag' => '',
                    'is_default' => ($lang === 'zh-CN'),
                    'direction' => 'ltr',
                    'date_format' => 'Y-m-d',
                    'time_format' => 'H:i:s'
                );
            }
            
            // 写入缓存
            if (class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set($cache_key, $language_data, 3600, 'language');
            }
        } catch (Exception $e) {
            // 查询失败，记录错误但不中断执行
            error_log('Todo_Query_Preloader::preload_language_data error: ' . $e->getMessage());
        }
    }
    
    /**
     * 预加载当前用户数据
     */
    private static function preload_current_user() {
        if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
            return;
        }
        
        $user_id = 0;
        if (function_exists('get_current_user_id')) {
            $user_id = get_current_user_id();
        }
        
        if ($user_id <= 0) {
            return;
        }
        
        // 检查缓存
        if (class_exists('Todo_Cache_Manager')) {
            $cache_key = 'user_' . $user_id;
            $cached = Todo_Cache_Manager::get($cache_key, 'users');
            if ($cached !== false) {
                return;
            }
        }
        
        // 预加载用户数据并写入缓存
        try {
            global $tododb;
            $user_data = null;
            
            // 优先使用 CMS_User 类（如果可用）
            if (class_exists('CMS_User')) {
                $user = new CMS_User($user_id);
                if ($user && $user->get_id() > 0) {
                    $user_data = $user->get_data();
                }
            }
            
            // 如果 CMS_User 不可用，尝试使用 get_userdata
            if (!$user_data && function_exists('get_userdata')) {
                $user_obj = get_userdata($user_id);
                if ($user_obj) {
                    // 转换为数组格式
                    $user_data = is_object($user_obj) ? get_object_vars($user_obj) : $user_obj;
                    // 移除敏感信息
                    if (isset($user_data['password'])) {
                        unset($user_data['password']);
                    }
                    if (isset($user_data['activation_code'])) {
                        unset($user_data['activation_code']);
                    }
                }
            }
            
            // 如果仍然没有数据，直接查询数据库
            if (!$user_data && $tododb) {
                $row = $tododb->get_row(
                    "SELECT * FROM " . TABLE_PREFIX . "users WHERE ID = ? LIMIT 1",
                    [$user_id],
                    'ARRAY_A'
                );
                if ($row) {
                    $user_data = $row;
                    // 移除敏感信息
                    if (isset($user_data['password'])) {
                        unset($user_data['password']);
                    }
                    if (isset($user_data['activation_code'])) {
                        unset($user_data['activation_code']);
                    }
                }
            }
            
            // 写入缓存
            if ($user_data && class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set($cache_key, $user_data, 3600, 'users');
            }
        } catch (Exception $e) {
            // 查询失败，记录错误但不中断执行
            error_log('Todo_Query_Preloader::preload_current_user error: ' . $e->getMessage());
        }
    }
    
    /**
     * 预加载站点配置
     */
    private static function preload_site_config() {
        global $tododb;
        // 预加载菜单（如果常用）
        // TODO: 根据实际需求实现
    }
    
    /**
     * 预加载文章相关数据（用于单篇文章页）
     * 
     * @param int $post_id 文章ID
     */
    public static function preload_post_data($post_id) {
        if ($post_id <= 0) {
            return;
        }
        
        // 批量预加载文章及其关系数据
        if (class_exists('Todo_Post_ORM')) {
            Todo_Post_ORM::eager_load_all([$post_id], [
                'meta' => true,
                'terms' => true,
                'multilang' => true,
                'tags' => true,
                'categories' => true
            ]);
        }
        
        // 预加载相关文章（如果需要）
        // TODO: 根据实际需求实现
    }
    
    /**
     * 预加载分类相关数据
     * 
     * @param int $term_id 分类ID
     */
    public static function preload_term_data($term_id) {
        if ($term_id <= 0) {
            return;
        }
        
        // 预加载分类数据
        if (function_exists('get_term')) {
            get_term($term_id, 'category');
        }
        
        // 预加载分类下的文章ID（如果常用）
        // TODO: 根据实际需求实现
    }
    
    /**
     * 检查是否已预加载
     * 
     * @return bool
     */
    public static function is_preloaded() {
        return self::$preloaded;
    }
    
    /**
     * 重置预加载状态
     */
    public static function reset() {
        self::$preloaded = false;
    }
}
