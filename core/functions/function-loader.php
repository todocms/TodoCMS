<?php
/**
 * 统一函数加载器
 * 提供模块化的函数加载机制，防止重复加载和函数冲突
 *
 * @package TodoCMS
 * @version 1.0.0
 */

class Todo_Function_Loader
{

    /**
     * 已加载的函数文件列表
     * @var array
     */
    private static $loaded_functions = array();

    /**
     * 函数文件依赖关系
     * @var array
     */
    private static $dependencies = array(
        // 核心依赖 - 必须最先加载
        'todo-functions' => array(),
        'todo-hooks' => array(),
        'core' => array('todo-functions', 'todo-hooks'),
        'class-todo-error' => array(),
        'class-hooks' => array('todo-hooks'),
        'class-prg-guard' => array(),
        'mysql-compatibility' => array('core'),

        // 基础函数
        'formatting' => array('core'),
        'security' => array('core', 'formatting'),
        'security-extend' => array('security'),

        // 数据库相关
        'insert_term' => array('core', 'security'),
        'update_term' => array('core', 'security'),
        'delete_term' => array('core', 'security'),
        'term-model-functions' => array('insert_term', 'update_term', 'delete_term'),

        // 内容管理
        'post' => array('core', 'security', 'formatting', 'language-functions', 'post-translation', 'post-data'),
        'post-data' => array('core', 'post-translation'),
        'post-translation' => array('language-functions'),
        'post-functions-extended' => array('post'),
        'content' => array('core', 'security', 'formatting'),
        'page-functions' => array('post'),
        'template' => array('core', 'formatting', 'post-translation'),
        'pagination' => array('core'),

        // 用户和权限
        'user' => array('core', 'security'),
        'auth' => array('user', 'security'),
        'sanitize_user' => array('user', 'security'),
        'user-functions' => array('user', 'auth', 'security'),
        'user-center-template' => array('core', 'security', 'user', 'template'),

        // 媒体和文件
        'media' => array('core', 'security'),

        // 系统功能
        'plugin' => array('core', 'todo-hooks'),
        'theme' => array('core', 'todo-hooks'),
        'menu' => array('core', 'security'),
        'admin' => array('core', 'security', 'todo-hooks'),
        'global-vars' => array('core', 'security'),
        'cache-functions' => array('core'),
        'cache-refresh' => array('core'),  // 缓存刷新处理器
        'transient-functions' => array('core'),
        'permalink-handler' => array('core', 'rewrite-functions'),
        'utility-functions-extended' => array('core', 'formatting'),
        'system-functions-extended' => array('core', 'security', 'formatting', 'utility-functions-extended'),
        'url-functions' => array('core', 'security', 'post-translation', 'system-functions-extended'),
        'ui-functions' => array('core', 'url-functions'),
        'sitemap-service' => array('core', 'post', 'url-functions'),
        'seo-functions' => array('core', 'post', 'url-functions'),
        'mail-extend' => array('core', 'formatting'),
        'sms-functions' => array('core', 'formatting'),

        // API和语言
        'api-functions' => array('core', 'security'),
        'language' => array('core'),
        'language-functions' => array('language'),
        // 多语言扩展字段支持
        'multilang-fields' => array('language-functions'),

        // 商城功能
        'product-functions' => array('post', 'media'),
        'order-functions' => array('core', 'security', 'user'),
        'shop-service-settings' => array('core', 'security', 'formatting'),

        // 模板系统
        'html-template' => array('template'),
        'loop' => array('post', 'template'),
        'template-helper-functions' => array('core', 'security', 'formatting'),

        // 友情链接
        'link-functions' => array('core', 'security'),

        // 安装相关
        'install' => array('core')
    );

    /**
     * 函数文件分组
     * @var array
     */
    private static $function_groups = array(
        'core' => array('todo-functions', 'todo-hooks', 'core', 'formatting', 'permalink-handler', 'mysql-compatibility', 'language', 'language-functions'),
        'security' => array('security', 'security-extend', 'sanitize_user'),
        'database' => array('insert_term', 'update_term', 'delete_term', 'term-model-functions'),
        'content' => array('post', 'post-data', 'post-translation', 'post-functions-extended', 'content', 'template', 'pagination', 'multilang-fields', 'html-template', 'loop', 'template-helper-functions', 'link-functions'),
        'user' => array('user', 'auth', 'user-functions', 'user-center-template'),
        'media' => array('media'),
        'system' => array('plugin', 'theme', 'menu', 'admin', 'global-vars', 'cache-functions', 'cache-refresh', 'transient-functions', 'url-functions', 'ui-functions', 'sitemap-service', 'seo-functions', 'mail-extend', 'sms-functions', 'system-functions-extended', 'utility-functions-extended'),
        'api' => array('api-functions', 'language', 'language-functions'),
        'shop' => array('product-functions', 'order-functions', 'shop-service-settings', 'payment-methods'),
        'admin' => array('admin', 'formatting', 'term-model-functions', 'menu'),
        'install' => array('install'),
        'core_classes' => array('class-prg-guard')
    );

    /**
     * Composer自动加载的函数文件列表
     * @var array
     */
    private static $autoloaded_functions = array(
        'todo-hooks',
        'core-system-functions',
        'todo-core-functions',
        'todo-functions',
        'formatting',
        'language',
        'core',
        'security',
        'user',
        'auth',
        'post',
        'media',
        'url-functions',
        'utility-functions',
        'admin',
        'plugin'
    );

    /**
     * 加载单个函数文件
     * 
     * @param string $function_name 函数文件名（不含.php扩展名）
     * @param bool $force 是否强制重新加载
     * @return bool 加载是否成功
     */
    public static function load_function($function_name, $force = false)
    {
        // 检查是否为Composer自动加载的文件
        if (in_array($function_name, self::$autoloaded_functions)) {
            return true;
        }

        // 检查是否已加载
        if (!$force && in_array($function_name, self::$loaded_functions)) {
            return true;
        }

        // 加载依赖
        if (isset(self::$dependencies[$function_name])) {
            foreach (self::$dependencies[$function_name] as $dependency) {
                if (!self::load_function($dependency)) {
                    error_log("Todo Function Loader: Failed to load dependency '{$dependency}' for '{$function_name}'");
                    return false;
                }
            }
        }

        // 确定文件路径
        $file_path = self::get_function_file_path($function_name);

        if (!$file_path || !file_exists($file_path)) {
            error_log("Todo Function Loader: Function file '{$function_name}' not found");
            return false;
        }

        // 实际加载文件
        require_once $file_path;

        self::$loaded_functions[] = $function_name;
        if (function_exists('do_action')) {
            do_action('todo_function_loaded', $function_name);
        }
        return true;
    }

    /**
     * 按组加载函数
     * 
     * @param string $group_name 组名
     * @return bool 加载是否成功
     */
    public static function load_function_group($group_name)
    {
        if (!isset(self::$function_groups[$group_name])) {
            error_log("Todo Function Loader: Unknown function group '{$group_name}'");
            return false;
        }

        $success = true;
        foreach (self::$function_groups[$group_name] as $function_name) {
            if (!self::load_function($function_name)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * 加载所有核心函数
     * 
     * @return bool 加载是否成功
     */
    public static function load_core_functions()
    {
        $core_groups = array('core_classes', 'core', 'security', 'database', 'content', 'user', 'system');

        $success = true;
        foreach ($core_groups as $group) {
            if (!self::load_function_group($group)) {
                $success = false;
            }
        }

        // 所有核心函数加载完成后，初始化核心系统
        if ($success) {
            // 初始化翻译系统
            if (class_exists('CMS_Translation')) {
                CMS_Translation::init();
            }

            // 初始化插件系统
            if (class_exists('Todo_Plugin_Manager')) {
                Todo_Plugin_Manager::init();
            }
        }

        return $success;
    }

    /**
     * 获取函数文件路径
     * 
     * @param string $function_name 函数名
     * @return string|false 文件路径或false
     */
    private static function get_function_file_path($function_name)
    {
        // 允许传入带或不带扩展名的名称，避免重复追加 .php
        $has_extension = (substr($function_name, -4) === '.php');
        $file = $has_extension ? $function_name : ($function_name . '.php');

        // 类文件路径
        if (strpos($function_name, 'class-') === 0) {
            return ABSPATH . 'core/classes/' . $file;
        }

        // 检查 services 目录
        $services_file = ABSPATH . 'core/services/' . $file;
        if (file_exists($services_file)) {
            return $services_file;
        }

        // 普通函数文件路径
        return ABSPATH . 'core/functions/' . $file;
    }

    /**
     * 获取已加载的函数列表
     * 
     * @return array 已加载的函数列表
     */
    public static function get_loaded_functions()
    {
        return self::$loaded_functions;
    }

    /**
     * 检查函数是否已加载
     * 
     * @param string $function_name 函数名
     * @return bool 是否已加载
     */
    public static function is_function_loaded($function_name)
    {
        return in_array($function_name, self::$loaded_functions);
    }

    /**
     * 获取函数加载统计信息
     * 
     * @return array 统计信息
     */
    public static function get_load_stats()
    {
        return array(
            'total_loaded' => count(self::$loaded_functions),
            'loaded_functions' => self::$loaded_functions,
            'available_groups' => array_keys(self::$function_groups)
        );
    }
}

// 初始化函数加载器
if (!function_exists('todo_load_function')) {
    /**
     * 便捷函数：加载单个函数文件
     * 
     * @param string $function_name 函数文件名
     * @param bool $force 是否强制重新加载
     * @return bool 加载是否成功
     */
    function todo_load_function($function_name, $force = false)
    {
        return Todo_Function_Loader::load_function($function_name, $force);
    }
}

if (!function_exists('todo_load_function_group')) {
    /**
     * 便捷函数：按组加载函数
     * 
     * @param string $group_name 组名
     * @return bool 加载是否成功
     */
    function todo_load_function_group($group_name)
    {
        return Todo_Function_Loader::load_function_group($group_name);
    }
}

if (!function_exists('todo_is_function_loaded')) {
    /**
     * 便捷函数：检查函数是否已加载
     * 
     * @param string $function_name 函数名
     * @return bool 是否已加载
     */
    function todo_is_function_loaded($function_name)
    {
        return Todo_Function_Loader::is_function_loaded($function_name);
    }
}
