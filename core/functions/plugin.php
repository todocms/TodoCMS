<?php
/**
 * 插件函数 - 统一插件管理API
 *
 * 提供简化的函数式API，内部调用Todo_Plugin_Manager类
 * 这些函数作为Todo_Plugin_Manager的便捷包装器，保持向后兼容性
 *
 * @package TodoCMS
 * @version 2.0.0
 */

/**
 * 确保插件管理器已加载
 */
function ensure_plugin_manager_loaded() {
    // 初始化插件管理器
    Todo_Plugin_Manager::init();
}

/**
 * 获取所有插件
 *
 * @param bool $include_inactive 是否包含未激活的插件
 * @return array 插件列表
 */
function get_plugins($include_inactive = true) {
    ensure_plugin_manager_loaded();
    return Todo_Plugin_Manager::get_plugins($include_inactive);
}

/**
 * 获取激活插件列表
 *
 * @return array 激活的插件目录名列表
 */
function get_active_plugins() {
    ensure_plugin_manager_loaded();
    return Todo_Plugin_Manager::get_active_plugins();
}

/**
 * 激活插件
 *
 * @param string $plugin_dir 插件目录名
 * @return bool|array 成功返回true，失败返回错误信息数组
 */
function activate_plugin($plugin_dir) {
    ensure_plugin_manager_loaded();
    $result = Todo_Plugin_Manager::activate_plugin($plugin_dir);
    // 统一错误返回为 TODO_Error，便于 is_todo_error() 识别
    if (is_array($result) && isset($result['error'])) {
        if (class_exists('TODO_Error')) {
            return new TODO_Error('plugin_activation_failed', $result['error']);
        }
    }
    return $result;
}

/**
 * 停用插件
 *
 * @param string $plugin_dir 插件目录名
 * @return bool|array 成功返回true，失败返回错误信息数组
 */
function deactivate_plugin($plugin_dir) {
    ensure_plugin_manager_loaded();
    return Todo_Plugin_Manager::deactivate_plugin($plugin_dir);
}

/**
 * 安装插件
 *
 * @param string $plugin_dir 插件目录名
 * @return bool|array 成功返回true，失败返回错误信息数组
 */
function install_plugin($plugin_dir) {
    ensure_plugin_manager_loaded();
    return Todo_Plugin_Manager::install_plugin($plugin_dir);
}

/**
 * 卸载插件
 *
 * @param string $plugin_dir 插件目录名
 * @param bool $delete_files 是否删除文件
 * @return bool|array 成功返回true，失败返回错误信息数组
 */
function uninstall_plugin($plugin_dir, $delete_files = false) {
    ensure_plugin_manager_loaded();
    return Todo_Plugin_Manager::uninstall_plugin($plugin_dir, $delete_files);
}

/**
 * 检查插件是否激活
 *
 * @param string $plugin_dir 插件目录名
 * @return bool 插件是否激活
 */
function is_plugin_active($plugin_dir) {
    ensure_plugin_manager_loaded();
    return Todo_Plugin_Manager::is_plugin_active($plugin_dir);
}

/**
 * 检查插件是否已安装
 *
 * @param string $plugin_dir 插件目录名
 * @return bool 插件是否已安装
 */
function is_plugin_installed($plugin_dir) {
    ensure_plugin_manager_loaded();
    $plugin = Todo_Plugin_Manager::get_plugin($plugin_dir);
    return $plugin && !empty($plugin['installed']);
}

/**
 * 获取插件信息
 *
 * @param string $plugin_dir 插件目录名
 * @return array|null 插件信息，不存在返回null
 */
function get_plugin_info($plugin_dir) {
    ensure_plugin_manager_loaded();
    return Todo_Plugin_Manager::get_plugin($plugin_dir);
}

/**
 * 获取插件基本名称（兼容旧API）
 *
 * @param string $plugin_file 插件文件路径
 * @return string 插件基本名称
 */
function plugin_basename($plugin_file) {
    $plugin_file = str_replace('\\', '/', $plugin_file);
    $plugin_file = preg_replace('|/+|', '/', $plugin_file);
    
    // 获取插件目录路径
    $plugin_dir = str_replace('\\', '/', todo_root_path() . '/content/plugins');
    $plugin_dir = preg_replace('|/+|', '/', $plugin_dir);
    
    if (strpos($plugin_file, $plugin_dir) === 0) {
        return substr($plugin_file, strlen($plugin_dir) + 1);
    }
    
    return basename(dirname($plugin_file));
}

/**
 * 获取插件数据（从文件头部读取）
 *
 * @param string $plugin_file 插件文件路径
 * @return array 插件数据
 */
function get_plugin_data($plugin_file) {
    $default_headers = array(
        'Name' => 'Plugin Name',
        'PluginURI' => 'Plugin URI',
        'Version' => 'Version',
        'Description' => 'Description',
        'Author' => 'Author',
        'AuthorURI' => 'Author URI',
        'TextDomain' => 'Text Domain',
        'DomainPath' => 'Domain Path',
        'RequiresTodoCMS' => 'Requires at least',
        'TestedUpTo' => 'Tested up to',
        'RequiresPHP' => 'Requires PHP',
        'Network' => 'Network',
        'UpdateURI' => 'Update URI',
    );
    
    $plugin_data = array();
    
    if (file_exists($plugin_file)) {
        $file_data = file_get_contents($plugin_file, false, null, 0, 8192);
        
        foreach ($default_headers as $field => $regex) {
            if (preg_match('/^[ \t\/*#@]*' . preg_quote($regex, '/') . ':(.*)$/mi', $file_data, $match) && $match[1]) {
                $plugin_data[$field] = trim(preg_replace('/\s*(?:\*\/|\?>).*/', '', $match[1]));
            } else {
                $plugin_data[$field] = '';
            }
        }
    }
    
    return $plugin_data;
}

/**
 * 获取插件目录URL
 *
 * @param string $plugin_dir 插件目录名
 * @return string 插件目录URL
 */
function plugin_dir_url($plugin_dir) {
    return todo_get_site_url() . '/content/plugins/' . $plugin_dir . '/';
}

/**
 * 获取TodoCMS根目录路径
 *
 * @return string 根目录路径
 */
function todo_root_path() {
    if (defined('ABSPATH')) {
        return rtrim(ABSPATH, '/');
    }
    return rtrim(dirname(dirname(__DIR__)), '/');
}

/**
 * 获取插件目录路径
 *
 * @param string $plugin_dir 插件目录名
 * @return string 插件目录路径
 */
function plugin_dir_path($plugin_dir) {
    return todo_root_path() . '/content/plugins/' . $plugin_dir . '/';
}

/**
 * 获取插件URL（兼容函数）
 *
 * @param string $plugin_dir 插件目录名
 * @return string 插件URL
 */
function todo_plugin_url($plugin_dir) {
    return plugin_dir_url($plugin_dir);
}

/**
 * 获取插件路径（兼容函数）
 *
 * @param string $plugin_dir 插件目录名
 * @return string 插件路径
 */
function todo_plugin_path($plugin_dir) {
    return plugin_dir_path($plugin_dir);
}

/**
 * 加载插件文本域
 *
 * @param string $domain 文本域
 * @param string $plugin_dir 插件目录名
 * @return bool 是否成功加载
 */
function load_plugin_textdomain($domain, $plugin_dir) {
    if (!class_exists('CMS_Translation')) {
        return false;
    }
    
    // 使用新方案：按 hyphen 风格 locale 构造 MO 文件路径
    $locale = function_exists('current_locale') ? current_locale() : str_replace('_','-', get_current_language());
    $base   = plugin_dir_path($plugin_dir) . 'languages/';
    $mo     = $base . $domain . '-' . $locale . '.mo';
    
    return CMS_Translation::load_textdomain($domain, $mo);
}

/**
 * 获取插件文件路径
 *
 * @param string $plugin_dir 插件目录名
 * @return string|false 插件主文件路径，失败返回false
 */
function get_plugin_file($plugin_dir) {
    $plugin_path = plugin_dir_path($plugin_dir);
    
    if (!is_dir($plugin_path)) {
        return false;
    }
    
    // 按优先级查找主文件
    $possible_files = [
        $plugin_path . $plugin_dir . '.php',  // 目录名.php
        $plugin_path . 'index.php',           // index.php
        $plugin_path . 'plugin.php',          // plugin.php
    ];
    
    foreach ($possible_files as $file) {
        if (file_exists($file)) {
            // 检查是否为有效的插件文件
            $plugin_data = get_plugin_data($file);
            if (!empty($plugin_data['Name'])) {
                return $file;
            }
        }
    }
    
    return false;
}

/**
 * 注册插件激活钩子
 * 
 * @param string $file 插件文件路径
 * @param callable $callback 回调函数
 */
function register_activation_hook($file, $callback) {
    $plugin_dir = basename(dirname($file));
    ensure_plugin_manager_loaded();
    Todo_Plugin_Manager::register_activation_hook($plugin_dir, $callback);
}

/**
 * 注册插件停用钩子
 * 
 * @param string $file 插件文件路径
 * @param callable $callback 回调函数
 */
function register_deactivation_hook($file, $callback) {
    $plugin_dir = basename(dirname($file));
    ensure_plugin_manager_loaded();
    Todo_Plugin_Manager::register_deactivation_hook($plugin_dir, $callback);
}

/**
 * 注册插件卸载钩子
 * 
 * @param string $file 插件文件路径
 * @param callable $callback 回调函数
 */
function register_uninstall_hook($file, $callback) {
    $plugin_dir = basename(dirname($file));
    ensure_plugin_manager_loaded();
    Todo_Plugin_Manager::register_uninstall_hook($plugin_dir, $callback);
}

/**
 * 添加动作钩子
 * 
 * @param string $hook_name 钩子名称
 * @param callable $callback 回调函数
 * @param int $priority 优先级
 * @param int $accepted_args 接受的参数数量
 */
if (!function_exists('add_action')) {
    function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1) {
        ensure_plugin_manager_loaded();
        Todo_Plugin_Manager::add_action($hook_name, $callback, $priority, $accepted_args);
    }
}

/**
 * 执行动作钩子
 * 
 * @param string $hook_name 钩子名称
 * @param mixed ...$args 参数
 */
if (!function_exists('do_action')) {
    function do_action($hook_name, ...$args) {
        ensure_plugin_manager_loaded();
        Todo_Plugin_Manager::do_action($hook_name, ...$args);
    }
}

/**
 * 添加过滤器
 * 
 * @param string $filter_name 过滤器名称
 * @param callable $callback 回调函数
 * @param int $priority 优先级
 * @param int $accepted_args 接受的参数数量
 */
if (!function_exists('add_filter')) {
    function add_filter($filter_name, $callback, $priority = 10, $accepted_args = 1) {
        ensure_plugin_manager_loaded();
        Todo_Plugin_Manager::add_filter($filter_name, $callback, $priority, $accepted_args);
    }
}

/**
 * 应用过滤器
 * 
 * @param string $filter_name 过滤器名称
 * @param mixed $value 要过滤的值
 * @param mixed ...$args 额外参数
 * @return mixed
 */
if (!function_exists('apply_filters')) {
    function apply_filters($filter_name, $value, ...$args) {
        ensure_plugin_manager_loaded();
        return Todo_Plugin_Manager::apply_filters($filter_name, $value, ...$args);
    }
}

/**
 * 移除动作钩子
 * 
 * @param string $hook_name 钩子名称
 * @param callable $callback 回调函数
 * @param int $priority 优先级
 * @return bool
 */
if (!function_exists('remove_action')) {
    function remove_action($hook_name, $callback, $priority = 10) {
        ensure_plugin_manager_loaded();
        return Todo_Plugin_Manager::remove_action($hook_name, $callback, $priority);
    }
}

/**
 * 移除过滤器
 * 
 * @param string $filter_name 过滤器名称
 * @param callable $callback 回调函数
 * @param int $priority 优先级
 * @return bool
 */
if (!function_exists('remove_filter')) {
    function remove_filter($filter_name, $callback, $priority = 10) {
        ensure_plugin_manager_loaded();
        return Todo_Plugin_Manager::remove_filter($filter_name, $callback, $priority);
    }
}

// ============================================================================
// 向后兼容的别名函数
// ============================================================================

/**
 * 获取插件信息（别名）
 */
function todo_get_plugin_info($plugin_dir) {
    return get_plugin_info($plugin_dir);
}

/**
 * 激活插件（别名）
 */
if (!function_exists('todo_plugin_activate')) {
    function todo_plugin_activate($plugin_dir = null) {
        if ($plugin_dir !== null) {
            return activate_plugin($plugin_dir);
        }
        return null; // 兼容运行插件脚本中无参调用
    }
}

/**
 * 停用插件（别名）
 */
if (!function_exists('todo_plugin_deactivate')) {
    function todo_plugin_deactivate($plugin_dir = null) {
        if ($plugin_dir !== null) {
            return deactivate_plugin($plugin_dir);
        }
        return null; // 兼容运行插件脚本中无参调用
    }
}

/**
 * 卸载插件（别名）
 */
if (!function_exists('todo_plugin_uninstall')) {
    function todo_plugin_uninstall($plugin_dir = null, $delete_files = false) {
        if ($plugin_dir !== null) {
            return uninstall_plugin($plugin_dir, $delete_files);
        }
        return null; // 兼容运行插件脚本中无参调用
    }
}

/**
 * 获取插件URL（别名）
 */
function todo_get_plugin_url($plugin_dir) {
    return plugin_dir_url($plugin_dir);
}

/**
 * 获取插件路径（别名）
 */
function todo_get_plugin_path($plugin_dir) {
    return plugin_dir_path($plugin_dir);
}

/**
 * 删除插件（兼容包装器）
 *
 * 语义等同于卸载插件，默认会删除插件文件。
 * @param string $plugin_dir 插件目录名
 * @param bool $delete_files 是否删除插件文件，默认删除
 * @return bool|array 成功返回true，失败返回错误信息数组
 */
if (!function_exists('delete_plugin')) {
    function delete_plugin($plugin_dir, $delete_files = true) {
        ensure_plugin_manager_loaded();
        return Todo_Plugin_Manager::uninstall_plugin($plugin_dir, $delete_files);
    }
}

/**
 * TodoCMS风格的插件钩子函数别名
 */

/**
 * 注册插件激活钩子（TodoCMS风格）
 * 
 * @param string $file 插件文件路径
 * @param callable $callback 回调函数
 */
if (!function_exists('todo_register_activation_hook')) {
    function todo_register_activation_hook($file, $callback) {
        return register_activation_hook($file, $callback);
    }
}

/**
 * 注册插件停用钩子（TodoCMS风格）
 * 
 * @param string $file 插件文件路径
 * @param callable $callback 回调函数
 */
if (!function_exists('todo_register_deactivation_hook')) {
    function todo_register_deactivation_hook($file, $callback) {
        return register_deactivation_hook($file, $callback);
    }
}

/**
 * 注册插件卸载钩子（TodoCMS风格）
 * 
 * @param string $file 插件文件路径
 * @param callable $callback 回调函数
 */
if (!function_exists('todo_register_uninstall_hook')) {
    function todo_register_uninstall_hook($file, $callback) {
        return register_uninstall_hook($file, $callback);
    }
}
