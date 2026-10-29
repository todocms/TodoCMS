<?php
/**
 * TodoCMS Functions for TodoCMS
 *
 * @package TodoCMS
 * @subpackage Administration
 */

// Don't load directly
if (!defined('ABSPATH')) {
    die('-1');
}

/**
 * Check if admin user is logged in
 */
if (!function_exists('admin_logged_in')) {
    function admin_logged_in()
    {
        if (class_exists('Todo_Session_Manager')) {
            Todo_Session_Manager::init();
            return Todo_Session_Manager::is_admin_logged_in();
        }
        return false;
    }
}

/**
 * Check if user is logged in
 */
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in()
    {
        if (class_exists('Todo_Session_Manager')) {
            Todo_Session_Manager::init();
            if (defined('IS_ADMIN') && IS_ADMIN) {
                return Todo_Session_Manager::is_admin_logged_in();
            } else {
                return Todo_Session_Manager::is_frontend_logged_in();
            }
        }
        return false;
    }
}

/**
 * Redirect to login if not authenticated
 */
if (!function_exists('auth_redirect')) {
    function auth_redirect()
    {
        if (!is_user_logged_in()) {
            $qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? ('?' . $_SERVER['QUERY_STRING']) : '';
            $target = function_exists('todo_admin_login_proxy_url') ? todo_admin_login_proxy_url() : (function_exists('admin_url') ? rtrim(todo_admin_url(''), '/') . '/' : 'index.php');
            todo_redirect($target . $qs);
            exit;
        }
    }
}

/**
 * Get admin URL
 */
if (!function_exists('admin_url')) {
    function admin_url($path = '', $scheme = 'admin')
    {
        // 使用基础实现获取 URL
        $base = todo_get_site_url();
        $url = rtrim($base, '/') . '/admin/' . ltrim($path, '/');
        
        if ($scheme === 'relative') {
            $parts = parse_url($url);
            $relative = isset($parts['path']) ? $parts['path'] : '';
            return $relative !== '' ? $relative : ('/admin/' . ltrim($path, '/'));
        }
        return $url;
    }
}

/**
 * 获取管理员URL（支持iframe模式）
 * 
 * @param string $path 路径
 * @return string 完整URL
 */
if (!function_exists('todo_admin_url')) {
    function todo_admin_url($path = '', $clear_cache = false) {
        // 直接计算基础 URL，避免调用 admin_url 导致潜在递归
        $base = todo_get_site_url();
        $url = rtrim($base, '/') . '/admin/' . ltrim($path, '/');

        if ((string) $path === '') {
            return $url;
        }

        if ($clear_cache) {
            $url = add_query_arg('todocache', 'refresh', $url);
        }
        
        if (function_exists('todo_is_iframe_mode') && todo_is_iframe_mode()) {
            // 不为静态资源添加 format=iframe
            $path_str = (string) $path;
            $ext = strtolower(pathinfo($path_str, PATHINFO_EXTENSION));
            $static_exts = array('js', 'css', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'woff', 'woff2', 'ttf', 'eot');
            $path_norm = ltrim($path_str, '/');
            $is_dir = ($path_str !== '' && substr($path_str, -1) === '/');
            $is_ajax_dir = ($path_norm === 'ajax' || strpos($path_norm, 'ajax/') === 0);
            if (!in_array($ext, $static_exts) && !$is_dir && !$is_ajax_dir) {
                $url = add_query_arg('format', 'iframe', $url);
            }
        }
        return $url;
    }
}

if (!function_exists('todo_admin_login_proxy_url')) {
    function todo_admin_login_proxy_url()
    {
        if (defined('TODO_DEMO_LOGIN_PROXY') && TODO_DEMO_LOGIN_PROXY) {
            return todo_admin_url('login.php');
        }
        return rtrim(todo_admin_url(''), '/') . '/';
    }
}

if (!function_exists('todo_is_demo_admin')) {
    function todo_is_demo_admin()
    {
        if (!(defined('TODO_DEMO_ADMIN_ENABLED') && constant('TODO_DEMO_ADMIN_ENABLED'))) {
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
}

/**
 * Get site URL
 */
if (!function_exists('get_site_url')) {
    function get_site_url()
    {
        return todo_url();
    }
}

/**
 * Get blog info
 */
if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '', $filter = 'raw')
    {
        switch ($show) {
            case 'name':
                return get_option('site_title', 'TodoCMS');
            case 'version':
                return '1.0.0';
            default:
                return 'TodoCMS';
        }
    }
}

/**
 * Escape HTML
 */
if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Escape attributes
 */
if (!function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Escape URL
 */
if (!function_exists('esc_url')) {
    function esc_url($url)
    {
        return filter_var($url, FILTER_SANITIZE_URL);
    }
}

/**
 * Strip tags (TodoCMS version)
 */
if (!function_exists('todo_strip_tags')) {
    function todo_strip_tags($str, $allowable_tags = '')
    {
        return strip_tags($str, $allowable_tags);
    }
}

/**
 * Apply filters
 */
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value)
    {
        // 简化的过滤器系统
        return $value;
    }
}

/**
 * Do action
 */
if (!function_exists('do_action')) {
    function do_action($tag)
    {
        // 简化的动作系统
        return true;
    }
}

/**
 * Has action
 */
if (!function_exists('has_action')) {
    function has_action($tag)
    {
        return false;
    }
}

/**
 * Has filter
 */
if (!function_exists('has_filter')) {
    function has_filter($tag)
    {
        return false;
    }
}

/**
 * Referer field
 */
if (!function_exists('todo_referer_field')) {
    function todo_referer_field($echo = true)
    {
        $referer_field = '<input type="hidden" name="_todo_http_referer" value="' . esc_attr(todo_unslash($_SERVER['REQUEST_URI'])) . '" />';

        if ($echo) {
            echo $referer_field;
        }

        return $referer_field;
    }
}

/**
 * Original referer field
 */
if (!function_exists('todo_original_referer_field')) {
    function todo_original_referer_field($echo = true, $jump_back_to = 'current')
    {
        $ref = todo_get_original_referer();
        if (!$ref) {
            $ref = 'current' == $jump_back_to ? todo_unslash($_SERVER['REQUEST_URI']) : $jump_back_to;
        }
        $orig_referer_field = '<input type="hidden" name="_todo_original_http_referer" value="' . esc_attr($ref) . '" />';
        if ($echo) {
            echo $orig_referer_field;
        }
        return $orig_referer_field;
    }
}

/**
 * Get referer
 */
if (!function_exists('todo_get_referer')) {
    function todo_get_referer()
    {
        if (!function_exists('todo_get_raw_referer')) {
            return false;
        }
        $ref = todo_get_raw_referer();
        if ($ref && todo_unslash($_SERVER['REQUEST_URI']) !== $ref && home_url() . todo_unslash($_SERVER['REQUEST_URI']) !== $ref) {
            return $ref;
        }
        return false;
    }
}

/**
 * Get original referer
 */
if (!function_exists('todo_get_original_referer')) {
    function todo_get_original_referer()
    {
        if (!empty($_REQUEST['_todo_original_http_referer']) && function_exists('todo_validate_redirect')) {
            return todo_validate_redirect(todo_unslash($_REQUEST['_todo_original_http_referer']));
        } elseif (!empty($_REQUEST['_todo_original_http_referer'])) {
            return todo_unslash($_REQUEST['_todo_original_http_referer']);
        }
        return false;
    }
}

/**
 * Home URL
 */
if (!function_exists('home_url')) {
    function home_url($path = '', $scheme = null)
    {
        return get_site_url() . '/' . ltrim($path, '/');
    }
}

/**
 * Translate function
 */
if (!function_exists('__')) {
    function __($text, $domain = 'default')
    {
        return $text;
    }
}

/**
 * Translate and echo
 */
if (!function_exists('_e')) {
    function _e($text, $domain = 'default')
    {
        echo __($text, $domain);
    }
}

/**
 * Printf translate - renamed to avoid conflict
 */
if (!function_exists('todo_printf')) {
    function todo_printf($format)
    {
        $args = func_get_args();
        array_shift($args);
        echo vsprintf($format, $args);
    }
}

/**
 * Sprintf translate - renamed to avoid conflict
 */
if (!function_exists('todo_sprintf')) {
    function todo_sprintf($format)
    {
        $args = func_get_args();
        return call_user_func_array('sprintf', $args);
    }
}

/**
 * Check if mobile
 */
if (!function_exists('todo_is_mobile')) {
    function todo_is_mobile()
    {
        static $is_mobile = null;

        if (isset($is_mobile)) {
            return $is_mobile;
        }

        if (empty($_SERVER['HTTP_USER_AGENT'])) {
            $is_mobile = false;
        } elseif (preg_match('/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Opera Mobi|Silk|Kindle|Mobile/i', $_SERVER['HTTP_USER_AGENT'])) {
            $is_mobile = true;
        } else {
            $is_mobile = false;
        }

        return $is_mobile;
    }
}

/**
 * Check if multisite
 */
if (!function_exists('is_multisite')) {
    function is_multisite()
    {
        return false;
    }
}

/**
 * Check if network admin
 */
if (!function_exists('is_network_admin')) {
    function is_network_admin()
    {
        return defined('TODO_NETWORK_ADMIN') && TODO_NETWORK_ADMIN;
    }
}

/**
 * Check if user admin
 */
if (!function_exists('is_user_admin')) {
    function is_user_admin()
    {
        return defined('TODO_USER_ADMIN') && TODO_USER_ADMIN;
    }
}

/**
 * Check if admin bar showing
 */
if (!function_exists('is_admin_bar_showing')) {
    function is_admin_bar_showing()
    {
        return false;
    }
}

/**
 * Check if RTL
 */
if (!function_exists('is_rtl')) {
    function is_rtl()
    {
        return false;
    }
}

/**
 * Get user locale
 */
if (!function_exists('get_user_locale')) {
    function get_user_locale()
    {
        return 'en_US';
    }
}

/**
 * Get user setting
 */
if (!function_exists('get_user_setting')) {
    function get_user_setting($name, $default = false)
    {
        return $default;
    }
}

/**
 * Get user option
 */
if (!function_exists('get_user_option')) {
    function get_user_option($option_name, $user = 0, $deprecated = '')
    {
        if ($option_name == 'admin_color') {
            return 'fresh';
        }
        return false;
    }
}

/**
 * Sanitize HTML class
 */
if (!function_exists('sanitize_html_class')) {
    function sanitize_html_class($class, $fallback = '')
    {
        $sanitized = preg_replace('/[^A-Za-z0-9_-]/', '', $class);
        if ('' === $sanitized && $fallback) {
            return sanitize_html_class($fallback);
        }
        return $sanitized;
    }
}

/**
 * TodoCMS user settings
 */
if (!function_exists('todo_user_settings')) {
    function todo_user_settings()
    {
        // 简化的用户设置
    }
}

/**
 * TodoCMS admin HTML begin
 */
if (!function_exists('todo_admin_html_begin')) {
    function todo_admin_html_begin()
    {
        $lang_attributes = get_language_attributes('html');
        echo "<!DOCTYPE html>\n";
        echo "<html $lang_attributes>\n";
        echo "<head>\n";
        echo '<meta http-equiv="Content-Type" content="' . get_bloginfo('html_type') . '; charset=' . get_option('blog_charset') . '" />' . "\n";
        echo '<meta name="generator" content="TodoCMS">' . "\n";
    }
}

/**
 * Get language attributes
 */
if (!function_exists('get_language_attributes')) {
    function get_language_attributes($doctype = 'html')
    {
        $attributes = array();

        if (function_exists('is_rtl') && is_rtl()) {
            $attributes[] = 'dir="rtl"';
        }

        $lang = get_bloginfo('language');
        if ($lang) {
            if (get_option('html_type') == 'text/html' || $doctype == 'html') {
                $attributes[] = "lang=\"$lang\"";
            }

            if (get_option('html_type') != 'text/html' || $doctype == 'xhtml') {
                $attributes[] = "xml:lang=\"$lang\"";
            }
        }

        $output = implode(' ', $attributes);
        return $output;
    }
}

/**
 * Enqueue style
 */
if (!function_exists('todo_enqueue_style')) {
    function todo_enqueue_style($handle, $src = '', $deps = array(), $ver = false, $media = 'all')
    {
        global $todo_styles;

        if (!isset($todo_styles)) {
            $todo_styles = array();
        }

        // 如果提供了 src，则注册或更新样式
        if (!empty($src)) {
            $todo_styles[$handle] = array(
                'src' => $src,
                'deps' => $deps,
                'ver' => $ver,
                'media' => $media,
                'enqueued' => true
            );
        } elseif (isset($todo_styles[$handle])) {
            // 如果样式已注册但没有提供新 src，则仅标记为入队
            $todo_styles[$handle]['enqueued'] = true;
        } else {
            // 既没有 src 也没有预注册，无法入队
            return false;
        }

        return true;
    }
}

if (!function_exists('todo_print_styles')) {
    function todo_print_styles()
    {
        global $todo_styles;

        if (!isset($todo_styles) || empty($todo_styles)) {
            return;
        }

        foreach ($todo_styles as $handle => $style) {
            if (isset($style['enqueued']) && $style['enqueued']) {
                $src = $style['src'];
                if (empty($src))
                    continue;

                if (isset($style['ver']) && $style['ver']) {
                    $src = add_query_arg('ver', $style['ver'], $src);
                }
                $media = isset($style['media']) ? $style['media'] : 'all';

                echo '<link rel="stylesheet" id="' . esc_attr($handle) . '-css" href="' . esc_url($src) . '" type="text/css" media="' . esc_attr($media) . '" />' . "\n";

                // 标记为已输出
                $todo_styles[$handle]['printed'] = true;
                $todo_styles[$handle]['enqueued'] = false;
            }
        }
    }
}

/**
 * Enqueue script
 */
if (!function_exists('todo_enqueue_script')) {
    function todo_enqueue_script($handle, $src = '', $deps = array(), $ver = false, $in_footer = false)
    {
        global $todo_scripts;

        if (!isset($todo_scripts)) {
            $todo_scripts = array();
        }

        // 如果提供了 src，则注册或更新脚本
        if (!empty($src)) {
            $todo_scripts[$handle] = array(
                'src' => $src,
                'deps' => $deps,
                'ver' => $ver,
                'in_footer' => $in_footer,
                'enqueued' => true
            );
        } elseif (isset($todo_scripts[$handle])) {
            // 如果脚本已注册但没有提供新 src，则仅标记为入队
            $todo_scripts[$handle]['enqueued'] = true;
        } else {
            // 既没有 src 也没有预注册，无法入队
            return false;
        }

        return true;
    }
}

// 注册核心前端栈句柄
if (!isset($todo_scripts['todo-modal'])) {
    global $todo_scripts;
    if (!isset($todo_scripts)) {
        $todo_scripts = array();
    }
    $todo_scripts['todo-modal'] = array(
        'src' => todo_admin_url('assets/js/modal.js'),
        'deps' => array('jquery'),
        'ver' => defined('TODO_VERSION') ? TODO_VERSION : '1.0',
        'in_footer' => false,
        'enqueued' => false
    );
}
// 确保别名句柄始终设置
if (isset($todo_scripts['todo-modal'])) {
    $todo_scripts['modal'] = &$todo_scripts['todo-modal'];
}

if (!isset($todo_scripts['todo-utils'])) {
    global $todo_scripts;
    $todo_scripts['todo-utils'] = array(
        'src' => todo_admin_url('assets/js/todo-common.js'),
        'deps' => array('jquery', 'todo-modal'),
        'ver' => defined('TODO_VERSION') ? TODO_VERSION : '1.0',
        'in_footer' => false,
        'enqueued' => false
    );
}
// 确保别名句柄始终设置
if (isset($todo_scripts['todo-utils'])) {
    $todo_scripts['utils'] = &$todo_scripts['todo-utils'];
    $todo_scripts['todo-common'] = &$todo_scripts['todo-utils'];
}

if (!isset($todo_scripts['todo-request'])) {
    global $todo_scripts;
    $todo_request_js_file = dirname(__DIR__) . '/assets/js/todo-request-handler.js';
    $todo_scripts['todo-request'] = array(
        'src' => todo_admin_url('assets/js/todo-request-handler.js'),
        'deps' => array('jquery', 'todo-utils'),
        'ver' => is_file($todo_request_js_file) ? filemtime($todo_request_js_file) : (defined('TODO_VERSION') ? TODO_VERSION : '1.0'),
        'in_footer' => false,
        'enqueued' => false
    );
}
// 确保别名句柄始终设置
if (isset($todo_scripts['todo-request'])) {
    $todo_scripts['todo-request-handler'] = &$todo_scripts['todo-request'];
}

/**
 * Print enqueued scripts
 * 正确处理依赖关系，确保依赖的脚本先输出
 */
if (!function_exists('todo_print_scripts')) {
    function todo_print_scripts($in_footer = false)
    {
        global $todo_scripts;

        if (!isset($todo_scripts) || empty($todo_scripts)) {
            return;
        }

        // 按依赖关系排序脚本
        $sorted_scripts = array();
        $processed = array();

        // 递归函数：处理依赖关系
        $process_deps = null;
        $process_deps = function ($handle) use (&$todo_scripts, &$sorted_scripts, &$processed, &$process_deps) {
            if (isset($processed[$handle])) {
                return; // 已处理
            }

            if (!isset($todo_scripts[$handle])) {
                return; // 脚本不存在
            }

            $script = $todo_scripts[$handle];

            // 先处理依赖
            if (!empty($script['deps']) && is_array($script['deps'])) {
                foreach ($script['deps'] as $dep) {
                    if (!isset($processed[$dep])) {
                        $process_deps($dep);
                    }
                }
            }

            // 然后处理当前脚本
            $sorted_scripts[] = $handle;
            $processed[$handle] = true;
        };

        // 处理所有入队的脚本
        foreach ($todo_scripts as $handle => $script) {
            if (isset($script['enqueued']) && $script['enqueued'] && $script['in_footer'] == $in_footer) {
                $process_deps($handle);
            }
        }

        // 输出排序后的脚本
        foreach ($sorted_scripts as $handle) {
            if (isset($todo_scripts[$handle]) && $todo_scripts[$handle]['in_footer'] == $in_footer) {
                $script = $todo_scripts[$handle];

                // 如果脚本已经输出过，跳过
                if (isset($script['printed']) && $script['printed']) {
                    continue;
                }

                $src = $script['src'];
                if (empty($src))
                    continue;

                if (isset($script['ver']) && $script['ver']) {
                    $src = add_query_arg('ver', $script['ver'], $src);
                }
                echo '<script id="' . esc_attr($handle) . '-js" src="' . esc_url($src) . '" type="text/javascript"></script>' . "\n";

                // 标记为已输出
                $todo_scripts[$handle]['printed'] = true;
                $todo_scripts[$handle]['enqueued'] = false;
            }
        }

        // 输出本地化脚本数据
        if (function_exists('todo_print_localized_scripts')) {
            todo_print_localized_scripts($in_footer);
        }
    }
}

/**
 * Check if script is enqueued
 */
if (!function_exists('todo_script_is_enqueued')) {
    function todo_script_is_enqueued($handle)
    {
        global $todo_scripts;
        return isset($todo_scripts[$handle]) && $todo_scripts[$handle]['enqueued'];
    }
}

/**
 * Get current screen
 */
if (!function_exists('get_current_screen')) {
    function get_current_screen()
    {
        global $current_screen;

        // Ensure screen class is available
        if (!class_exists('TODO_Screen')) {
            $screen_class = __DIR__ . '/class-todo-screen.php';
            if (file_exists($screen_class)) {
                require_once $screen_class;
            }
        }

        if (!isset($current_screen) || !($current_screen instanceof TODO_Screen)) {
            $current_screen = TODO_Screen::get('');
        }

        return $current_screen;
    }
}

/**
 * Set current screen
 */
if (!function_exists('set_current_screen')) {
    function set_current_screen($hook_name = '')
    {
        global $current_screen;

        // Ensure screen class is available
        if (!class_exists('TODO_Screen')) {
            $screen_class = __DIR__ . '/class-todo-screen.php';
            if (file_exists($screen_class)) {
                require_once $screen_class;
            }
        }

        if (class_exists('TODO_Screen')) {
            $current_screen = TODO_Screen::get($hook_name);
        }
    }
}

/**
 * Add screen option
 */
function add_screen_option($option, $args = array())
{
    // 简化的屏幕选项
}

/**
 * Get network
 */
function get_network()
{
    return (object) array('site_name' => 'TodoCMS Network');
}

/**
 * Check for invalid UTF8 (simplified version)
 */
if (!function_exists('todo_check_invalid_utf8')) {
    function todo_check_invalid_utf8($string, $strip = false)
    {
        $string = (string) $string;
        if (0 === strlen($string)) {
            return '';
        }
        // For simplicity, just return the string as-is
        return $string;
    }
}

/**
 * Convert special characters to HTML entities (simplified version)
 */
if (!function_exists('todo_specialchars')) {
    function todo_specialchars($string, $quote_style = ENT_NOQUOTES, $charset = false, $double_encode = false)
    {
        $string = (string) $string;
        if (0 === strlen($string)) {
            return '';
        }
        // Use PHP's htmlspecialchars for basic escaping
        return htmlspecialchars($string, $quote_style, $charset ?: 'UTF-8', $double_encode);
    }
}

/**
 * Send a JSON response back to an Ajax request.
 */
if (!function_exists('todo_send_json')) {
    function todo_send_json($response, $status_code = null)
    {
        @header('Content-Type: application/json; charset=' . get_option('blog_charset'));
        if (null !== $status_code) {
            status_header($status_code);
        }
        echo todo_json_encode($response);
        // 直接退出，避免调用todo_die导致额外输出
        exit;
    }
}

/**
 * Send a JSON response back to an Ajax request, indicating success.
 */
if (!function_exists('todo_send_json_success')) {
    function todo_send_json_success($data = null, $status_code = null)
    {
        $response = array('success' => true);
        if (isset($data)) {
            $response['data'] = $data;
        }
        todo_send_json($response, $status_code);
    }
}

/**
 * Send a JSON response back to an Ajax request, indicating failure.
 */
if (!function_exists('todo_send_json_error')) {
    function todo_send_json_error($data = null, $status_code = null)
    {
        $response = array('success' => false);
        if (isset($data)) {
            if (is_todo_error($data)) {
                $result = array();
                foreach ($data->errors as $code => $messages) {
                    foreach ($messages as $message) {
                        $result[] = array('code' => $code, 'message' => $message);
                    }
                }
                $response['data'] = $result;
            } else {
                $response['data'] = $data;
            }
        }
        todo_send_json($response, $status_code);
    }
}

/**
 * Encode a variable into JSON, with some sanity checks.
 */
if (!function_exists('todo_json_encode')) {
    function todo_json_encode($data, $options = 0, $depth = 512)
    {
        $json = json_encode($data, $options, $depth);
        if (false === $json && json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }
        return $json;
    }
}

/**
 * Check whether we're in the middle of an Ajax request.
 */
if (!function_exists('todo_doing_ajax')) {
    function todo_doing_ajax()
    {
        return defined('DOING_AJAX') && DOING_AJAX;
    }
}

/**
 * Set HTTP status header.
 */
if (!function_exists('status_header')) {
    function status_header($code, $description = '')
    {
        if (!$description) {
            $description = get_status_header_desc($code);
        }
        if (empty($description)) {
            return;
        }
        $protocol = todo_get_server_protocol();
        $status_header = "$protocol $code $description";
        @header($status_header, true, $code);
    }
}

/**
 * Retrieve the description for the HTTP status.
 */
if (!function_exists('get_status_header_desc')) {
    function get_status_header_desc($code)
    {
        $header_to_desc = array(
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            304 => 'Not Modified',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
        );
        if (isset($header_to_desc[$code])) {
            return $header_to_desc[$code];
        } else {
            return '';
        }
    }
}

/**
 * Get the HTTP protocol used by the server.
 */
if (!function_exists('todo_get_server_protocol')) {
    function todo_get_server_protocol()
    {
        $protocol = $_SERVER['SERVER_PROTOCOL'];
        if (!in_array($protocol, array('HTTP/1.1', 'HTTP/2', 'HTTP/2.0'), true)) {
            $protocol = 'HTTP/1.0';
        }
        return $protocol;
    }
}

/**
 * Convert value to non-negative integer
 */
if (!function_exists('absint')) {
    function absint($maybeint)
    {
        return abs(intval($maybeint));
    }
}

/**
 * TodoCMS admin redirect
 */
if (!function_exists('todo_admin_redirect')) {
    function todo_admin_redirect($location, $status = 302)
    {
        if (!headers_sent()) {
            header("Location: $location", true, $status);
            exit;
        }
    }
}

/**
 * Set flash message for admin
 */
if (!function_exists('todo_set_flash_message')) {
    function todo_set_flash_message($message, $type = 'success')
    {
        if (!isset($_SESSION)) {
            session_start();
        }
        $_SESSION['flash_message'] = array(
            'message' => $message,
            'type' => $type
        );
    }
}

/**
 * Get flash message for admin
 */
if (!function_exists('todo_get_flash_message')) {
    function todo_get_flash_message()
    {
        if (!isset($_SESSION)) {
            session_start();
        }
        if (isset($_SESSION['flash_message'])) {
            $message = $_SESSION['flash_message'];
            unset($_SESSION['flash_message']);
            return $message;
        }
        return null;
    }
}

/**
 * Sanitize URL
 */
if (!function_exists('sanitize_url')) {
    function sanitize_url($url)
    {
        // Remove any characters that are not allowed in URLs
        $url = trim($url);

        // Basic URL validation and sanitization
        if (empty($url)) {
            return '';
        }

        // Remove any control characters
        $url = preg_replace('/[\x00-\x1F\x7F]/', '', $url);

        // Basic URL encoding for special characters
        $url = filter_var($url, FILTER_SANITIZE_URL);

        // Validate URL format
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        return $url;
    }
}

/**
 * 安全地重命名后台入口文件
 * 
 * @param string $old_filename 旧文件名（不包含.php扩展名）
 * @param string $new_filename 新文件名（不包含.php扩展名）
 * @return array 操作结果
 */
if (!function_exists('todo_rename_admin_entry_file')) {
    function todo_rename_admin_entry_file($old_filename, $new_filename)
    {
        // 验证参数
        if (empty($old_filename) || empty($new_filename)) {
            return array(
                'success' => false,
                'message' => '文件名不能为空'
            );
        }

        // 保留关键入口与系统文件，禁止重命名或覆盖
        $reserved = array('admin-ajax', 'admin-footer', 'admin-header', 'admin', 'index');
        if (in_array($old_filename, $reserved, true)) {
            return array(
                'success' => false,
                'message' => '出于安全考虑，禁止重命名系统保留文件：' . $old_filename . '.php'
            );
        }
        if (in_array($new_filename, $reserved, true)) {
            return array(
                'success' => false,
                'message' => '出于安全考虑，新文件名不能为系统保留名称：' . $new_filename . '.php'
            );
        }

        // 验证新文件名安全性（放宽一点限制以支持更复杂的命名，但仍保持 URL 安全）
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $new_filename)) {
            return array(
                'success' => false,
                'message' => '文件名只能包含字母、数字、下划线、点或连字符'
            );
        }

        // 获取admin目录路径
        $admin_dir = ADMIN_DIR;
        $old_file = $admin_dir . '/' . $old_filename . '.php';
        $new_file = $admin_dir . '/' . $new_filename . '.php';

        // 检查旧文件是否存在
        if (!file_exists($old_file)) {
            return array(
                'success' => false,
                'message' => '原文件不存在：' . $old_filename . '.php'
            );
        }

        // 检查新文件是否已存在
        if (file_exists($new_file)) {
            return array(
                'success' => false,
                'message' => '目标文件已存在：' . $new_filename . '.php'
            );
        }

        // 直接重命名文件
        if (!rename($old_file, $new_file)) {
            return array(
                'success' => false,
                'message' => '文件重命名失败'
            );
        }

        // 验证重命名后的文件权限
        if (!is_readable($new_file)) {
            // 如果新文件不可读，尝试恢复原文件名
            @rename($new_file, $old_file);
            return array(
                'success' => false,
                'message' => '重命名后文件不可读，权限错误，已恢复原文件名'
            );
        }

        // 记录操作日志
        if (function_exists('error_log')) {
            error_log("TodoCMS: Admin entry file renamed from {$old_filename}.php to {$new_filename}.php");
        }

        return array(
            'success' => true,
            'message' => '后台入口文件已成功重命名',
            'old_file' => $old_filename . '.php',
            'new_file' => $new_filename . '.php'
        );
    }
}

/**
 * 获取当前后台入口文件名
 *
 * 安全原则：
 *  1. 优先从数据库读取，并验证对应文件存在且不属于保留名单。
 *  2. DB 失败时，使用文件系统扫描查找符合命名规则的重命名入口。
 *  3. 找不到时返回 null，由调用方决定是否返回 404。
 *
 * @return string|null 入口文件名（不含 .php），找不到时返回 null
 */
if (!function_exists('todo_get_admin_entry_filename')) {
    function todo_get_admin_entry_filename()
    {
        // 系统保留文件名黑名单（禁止作为真实入口）
        $reserved = array('index', 'admin', 'login', 'main', 'admin-ajax', 'admin-footer', 'admin-header');
        $admin_dir = ADMIN_DIR;

        // 1. 优先从数据库获取当前设置的文件名
        $filename = get_option('site_adminbasefile', '');
        if (!empty($filename)
            && !in_array($filename, $reserved, true)
        ) {
            $file_path = $admin_dir . '/' . $filename . '.php';
            if (file_exists($file_path)) {
                return $filename;
            }
        }

        // 2. 数据库失效时，文件系统扫描：查找符合重命名规则的入口
        // 约定：真实入口文件名 = 大写拼音前缀 + 下划线 + 十六进制串（至少 10 位）
        $candidates = glob($admin_dir . '/*.php');
        if ($candidates) {
            foreach ($candidates as $candidate_path) {
                $candidate = basename($candidate_path, '.php');
                if (in_array($candidate, $reserved, true)) {
                    continue;
                }
                // 必须符合重命名格式：前缀_十六进制串（至少 10 位）
                if ($candidate !== 'todo-login' && !preg_match('/^[A-Za-z]+_[0-9a-f]{10,}$/', $candidate)) {
                    continue;
                }
                // 找到，尝试同步到数据库
                try {
                    update_option('site_adminbasefile', $candidate);
                } catch (Exception $e) {
                    // 忽略写入错误
                }
                return $candidate;
            }
        }

        // 3. 找不到任何真实入口，返回 null
        return null;
    }
}

/**
 * 自动清理遗留文件
 *
 * @return bool 是否成功删除或已不存在
 */
if (!function_exists('todo_cleanup_default_admin_login_file')) {
    function todo_cleanup_default_admin_login_file()
    {
        $admin_base = function_exists('get_option') ? (string) get_option('site_adminbasefile', '') : '';

        // 同步清理/重命名遗留文件
        $root_dir = defined('ABSPATH') ? ABSPATH : dirname(__DIR__, 2) . '/';
        $root_dir = rtrim($root_dir, '/\\') . '/';
        $install_file = $root_dir . 'install.php';
        if (file_exists($install_file)) {
            $random_suffix = substr(md5(time() . mt_rand(1000, 9999)), 0, 17);
            $new_install_file = $root_dir . 'install_' . $random_suffix . '.php';
            @rename($install_file, $new_install_file);
            if (function_exists('error_log')) {
                error_log("TodoCMS Security: Renamed leftover install.php to install_{$random_suffix}.php at {$install_file}");
            }
        }

        if (!empty($admin_base) && $admin_base !== 'todo-login') {
            $admin_dir = defined('ADMIN_DIR') ? ADMIN_DIR : (defined('ABSPATH') ? ABSPATH . 'admin' : dirname(__DIR__));
            $admin_dir = rtrim($admin_dir, '/\\');
            $new_entry_file = $admin_dir . '/' . $admin_base . '.php';
            $default_file = $admin_dir . '/todo-login.php';

            if (file_exists($new_entry_file) && is_readable($new_entry_file) && file_exists($default_file)) {
                @unlink($default_file);
                if (function_exists('error_log')) {
                    error_log("TodoCMS Security: Deleted default login entry file admin/todo-login.php because target file {$admin_base}.php exists at {$new_entry_file}");
                }
                return true;
            }
        }
        return false;
    }
}

/**
 * 验证后台入口文件名强度
 * 
 * @param string $name 文件名
 * @return bool 是否符合强度要求
 */
if (!function_exists('todo_is_strong_admin_entry')) {
    function todo_is_strong_admin_entry($name)
    {
        // 长度 10-32 位
        if (strlen($name) < 10 || strlen($name) > 32) {
            return false;
        }

        // 必须包含：大写字母、小写字母、数字
        if (!preg_match('/[A-Z]/', $name) || !preg_match('/[a-z]/', $name) || !preg_match('/[0-9]/', $name)) {
            return false;
        }

        // 建议包含下划线或连字符，但不强制（因为正则表达式已经包含在preg_match中了）
        
        return true;
    }
}

/**
 * 自动每周随机更换后台入口文件名
 * 
 * 逻辑：检查上次更换时间，如果超过7天，则生成一个新的强随机文件名并重命名
 */
if (!function_exists('todo_maybe_auto_rotate_admin_entry')) {
    function todo_maybe_auto_rotate_admin_entry()
    {
        if (function_exists('todo_is_demo_admin') && todo_is_demo_admin()) {
            return false;
        }

        // 检查是否开启了自动更换功能
        if (get_option('todo_auto_rotate_admin_entry', '1') !== '1') {
            return false;
        }

        $last_rotate = (int) get_option('todo_admin_last_rotate_time', 0);
        $now = time();
        $interval = 7 * 24 * 3600; // 7天

        if ($last_rotate <= 0) {
            // 如果是首次运行（如刚安装），将当前时间设为起始点
            // 确保安装后有完整的 7 天周期，而不是立即触发更名
            update_option('todo_admin_last_rotate_time', $now);
            return false;
        }

        if (($now - $last_rotate) < $interval && $last_rotate > 0) {
            return false;
        }

        // 生成高难度随机文件名（大小写+数字+下划线）
        // 建议长度 12-20 位，混合大小写、数字
        $pinyins = array('guanli', 'houtai', 'todo', 'mulu', 'anquan', 'rukou', 'denglu', 'mima');
        $base_pinyin = $pinyins[array_rand($pinyins)];
        $mixed_pinyin = '';
        $len = strlen($base_pinyin);
        for ($i = 0; $i < $len; $i++) {
            $char = $base_pinyin[$i];
            if (ctype_alpha($char)) {
                $mixed_pinyin .= (mt_rand(0, 1) === 1) ? strtoupper($char) : strtolower($char);
            } else {
                $mixed_pinyin .= $char;
            }
        }
        $new_name = $mixed_pinyin;
        
        // 使用更安全的随机生成器
        try {
            $random_part = bin2hex(random_bytes(rand(6, 8)));
            $new_name .= '_' . $random_part;
        } catch (Exception $e) {
            // 回退方案
            $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            $new_name .= '_' . substr(str_shuffle($chars), 0, rand(10, 14));
        }
        
        $current_name = todo_get_admin_entry_filename();
        
        if ($current_name === $new_name) {
            return false;
        }

        // 执行重命名
        $result = todo_rename_admin_entry_file($current_name, $new_name);
        
        if ($result['success']) {
            // 更新选项和时间
            update_option('site_adminbasefile', $new_name);
            update_option('todo_admin_last_rotate_time', $now);
            
            // 记录到核心配置或单独日志，以便管理员紧急找回（虽然用户说看DB，但日志更直观）
            error_log("CRITICAL SECURITY: Admin entry rotated to {$new_name}.php at " . date('Y-m-d H:i:s'));
            return true;
        }
        
        return false;
    }
}

/**
 * Prime model caches to avoid N+1 queries in admin menu generation.
 * 
 * This function fetches all active models and their field counts in a single batch query
 * at the start of the admin menu generation process. This ensures that subsequent calls
 * to CMS_Model::get_fields_counts() within the menu loop will hit the cache.
 */
if (!function_exists('todo_prime_model_caches')) {
    function todo_prime_model_caches()
    {
        static $ran = false;
        if ($ran) {
            return;
        }

        if (function_exists('todo_is_iframe_mode') && todo_is_iframe_mode()) {
            return;
        }

        // Only run if CMS_Model class exists
        if (!class_exists('CMS_Model')) {
            return;
        }
        $ran = true;

        // Measure performance of cache priming
        if (class_exists('Todo_Performance_Monitor')) {
            Todo_Performance_Monitor::start('todo_prime_model_caches');
        }

        // 1. Fetch all models (assuming menu shows all or active ones)
        // We fetch all to be safe and cover all potential menu items.
        // CMS_Model::get_models() uses caching internally, so this is efficient.
        $models = CMS_Model::get_models();

        if (empty($models)) {
            if (class_exists('Todo_Performance_Monitor')) {
                Todo_Performance_Monitor::end('todo_prime_model_caches');
            }
            return;
        }

        // 2. Extract model IDs
        $model_ids = array();
        foreach ($models as $model) {
            $model_id = is_object($model) ? $model->model_id : $model['model_id'];
            $model_ids[] = $model_id;
        }

        // 3. Prime the field counts cache
        // This will execute a single query: SELECT model_id, COUNT(*) ... WHERE model_id IN (...)
        if (!empty($model_ids)) {
            CMS_Model::get_fields_counts($model_ids);
        }

        if (class_exists('Todo_Performance_Monitor')) {
            Todo_Performance_Monitor::end('todo_prime_model_caches');
        }
    }
}

// Hook into admin_menu with high priority (0) to run before other menu items are added
add_action('admin_menu', 'todo_prime_model_caches', 0);
