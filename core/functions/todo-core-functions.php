<?php
/**
 * TodoCMS核心函数
 * 
 * @package TodoCMS
 * @subpackage Core/Functions
 */

// 防止直接访问（但允许AJAX请求）
if (!defined('ABSPATH') && !defined('DOING_AJAX')) {
    exit('Direct access denied');
}

/**
 * 获取 CMS 版本号
 */
if (!function_exists('todo_get_cms_version')) {
    function todo_get_cms_version()
    {
        return defined('TODO_VERSION') ? TODO_VERSION : '1.0.0';
    }
}

/**
 * 获取当前用户ID
 * 替换 get_current_user_id()
 */
if (!function_exists('todo_get_current_user_id')) {
    function todo_get_current_user_id()
    {
        // 严格物理隔离：前后台会话独立互不污染
        if (class_exists('Todo_Session_Manager')) {
            Todo_Session_Manager::init();
            if (defined('IS_ADMIN') && IS_ADMIN) {
                return Todo_Session_Manager::get_admin_user_id() ?: 0;
            } else {
                return Todo_Session_Manager::get_frontend_user_id() ?: 0;
            }
        }
        return 0;
    }
}

/**
 * 获取当前时间
 * 替换 current_time()
 */
if (!function_exists('todo_current_time')) {
    function todo_current_time($format = 'mysql', $gmt = false)
    {
        if ($format === 'mysql') {
            return $gmt ? (function_exists('todo_format_utc') ? todo_format_utc('Y-m-d H:i:s') : (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s')) : date('Y-m-d H:i:s');
        } elseif ($format === 'timestamp') {
            return $gmt ? time() : time();
        }
        return $gmt ? (function_exists('todo_format_utc') ? todo_format_utc($format) : (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format($format)) : date($format);
    }
}

/**
 * 获取网站首页URL
 * 替换 home_url()
 */
if (!function_exists('todo_home_url')) {
    function todo_home_url($path = '')
    {
        // 优先使用核心的 site_url 生成完整站点URL，确保协议/主机/子目录正确
        if (function_exists('site_url')) {
            // site_url 可直接处理附加路径
            $url = site_url($path);
            return rtrim($url, '/');
        }
        // 其次尝试 get_site_url（别名）
        if (function_exists('get_site_url')) {
            $base = get_site_url();
            $url = rtrim($base, '/');
            if ($path !== '') {
                $url .= '/' . ltrim($path, '/');
            }
            return $url;
        }
        // 常量回退：优先 TODO_URL，其次 HOME_URL
        $base = defined('TODO_URL') ? TODO_URL : (defined('HOME_URL') ? HOME_URL : null);
        if (!$base) {
            // 服务器环境回退：从当前请求推断协议与主机
            $https_val = function_exists('todo_server_string') ? todo_server_string('HTTPS', '') : (isset($_SERVER['HTTPS']) ? (string) $_SERVER['HTTPS'] : '');
            $port_val = function_exists('todo_server_string') ? todo_server_string('SERVER_PORT', '') : (isset($_SERVER['SERVER_PORT']) ? (string) $_SERVER['SERVER_PORT'] : '');
            $is_https = (!empty($https_val) && strtolower($https_val) !== 'off') || ($port_val === '443');
            $scheme = $is_https ? 'https' : 'http';
            $host = function_exists('todo_server_string') ? todo_server_string('HTTP_HOST', todo_server_string('SERVER_NAME', 'localhost')) : ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost'));
            $base = $scheme . '://' . $host;
        }
        $base = rtrim($base, '/');
        if ($path !== '') {
            $base .= '/' . ltrim($path, '/');
        }
        return $base;
    }
}

/**
 * 序列化数据（如果需要）
 */
if (!function_exists('maybe_serialize')) {
    function maybe_serialize($data)
    {
        if (is_array($data) || is_object($data)) {
            return serialize($data);
        }
        return $data;
    }
}

// 为了向后兼容，创建别名函数
if (!function_exists('get_current_user_id')) {
    function get_current_user_id()
    {
        return todo_get_current_user_id();
    }
}

if (!function_exists('current_time')) {
    function current_time($format = 'mysql', $gmt = false)
    {
        return todo_current_time($format, $gmt);
    }
}

if (!function_exists('home_url')) {
    function home_url($path = '')
    {
        return todo_home_url($path);
    }
}

if (!function_exists('get_option')) {
    function get_option($option_name, $default = false)
    {
        // Ensure the option class is loaded to prevent "Class CMS_Option not found"
        if (!class_exists('CMS_Option')) {
            $option_class = defined('ABSPATH') ? ABSPATH . 'core/classes/class-option.php' : __DIR__ . '/../classes/class-option.php';
            if (file_exists($option_class)) {
                require_once $option_class;
            }
        }
        if (class_exists('CMS_Option')) {
            return CMS_Option::get($option_name, $default);
        }
        // Fallback: return default if option system is not available yet
        return $default;
    }
}

// TodoCMS 选项相关统一封装
if (!function_exists('todo_add_option')) {
    function todo_add_option($option_name, $option_value = '', $group_name = 'general')
    {
        // 确保选项类已加载
        if (!class_exists('CMS_Option')) {
            $option_class = ABSPATH . 'core/classes/class-option.php';
            if (file_exists($option_class)) {
                require_once $option_class;
            }
        }
        // 优先使用统一选项类
        if (class_exists('CMS_Option')) {
            if (method_exists('CMS_Option', 'exists') && CMS_Option::exists($option_name)) {
                // 已存在则不再新增
                return false;
            }
            return CMS_Option::update($option_name, $option_value, $group_name);
        }
        // 回退到全局函数
        if (function_exists('update_option')) {
            return update_option($option_name, $option_value);
        }
        return false;
    }
}

if (!function_exists('todo_update_option')) {
    function todo_update_option($option_name, $option_value, $group_name = 'general')
    {
        if (!class_exists('CMS_Option')) {
            $option_class = ABSPATH . 'core/classes/class-option.php';
            if (file_exists($option_class)) {
                require_once $option_class;
            }
        }
        if (class_exists('CMS_Option')) {
            return CMS_Option::update($option_name, $option_value, $group_name);
        }
        if (function_exists('update_option')) {
            return update_option($option_name, $option_value);
        }
        return false;
    }
}

if (!function_exists('todo_delete_option')) {
    function todo_delete_option($option_name)
    {
        if (!class_exists('CMS_Option')) {
            $option_class = ABSPATH . 'core/classes/class-option.php';
            if (file_exists($option_class)) {
                require_once $option_class;
            }
        }
        if (class_exists('CMS_Option')) {
            return CMS_Option::delete($option_name);
        }
        if (function_exists('delete_option')) {
            return delete_option($option_name);
        }
        return false;
    }
}

if (!function_exists('add_option')) {
    function add_option($option_name, $option_value = '')
    {
        return todo_add_option($option_name, $option_value);
    }
}

if (!function_exists('delete_option')) {
    function delete_option($option_name)
    {
        return todo_delete_option($option_name);
    }
}

?>
