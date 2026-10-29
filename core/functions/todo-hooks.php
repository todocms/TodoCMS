<?php
/**
 * TodoCMS Hook System
 * 替换TodoCMS的钩子系统，提供add_action、apply_filters、do_action等功能
 *
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('TODO_VERSION') && !defined('DOING_AJAX') && !defined('ABSPATH')) {
    exit('Direct access not permitted');
}

class TodoHooks {
    private static $buffered_hooks = [];

    /**
     * 添加动作钩子
     */
    public static function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1) {
        if (class_exists('Todo_Core_Kernel')) {
            return Todo_Core_Kernel::register_hook($hook_name, $callback, $priority, $accepted_args);
        }
        self::$buffered_hooks[] = array(
            'hook_name' => $hook_name,
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args
        );
        return true;
    }
    
    /**
     * 刷新缓存的钩子到核心内核
     */
    public static function flush_buffered_hooks() {
        if (!class_exists('Todo_Core_Kernel')) {
            return false;
        }
        foreach (self::$buffered_hooks as $hook) {
            Todo_Core_Kernel::register_hook($hook['hook_name'], $hook['callback'], $hook['priority'], $hook['accepted_args']);
        }
        self::$buffered_hooks = [];
        return true;
    }
    
    /**
     * 添加过滤器钩子
     */
    public static function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1) {
        return self::add_action($hook_name, $callback, $priority, $accepted_args);
    }
    
    /**
     * 执行动作钩子
     */
    public static function do_action($hook_name, ...$args) {
        if (class_exists('Todo_Core_Kernel')) {
            return Todo_Core_Kernel::execute_hook($hook_name, $args);
        }
    }
    
    /**
     * 应用过滤器钩子
     */
    public static function apply_filters($hook_name, $value, ...$args) {
        if (class_exists('Todo_Core_Kernel')) {
            return Todo_Core_Kernel::filter_data($hook_name, $value, $args);
        }
        return $value;
    }
    
    /**
     * 移除动作钩子
     */
    public static function remove_action($hook_name, $callback, $priority = 10) {
        if (class_exists('Todo_Core_Kernel')) {
            return Todo_Core_Kernel::remove_hook($hook_name, $callback, $priority);
        }
        return false;
    }
    
    /**
     * 移除过滤器钩子
     */
    public static function remove_filter($hook_name, $callback, $priority = 10) {
        return self::remove_action($hook_name, $callback, $priority);
    }
    
    /**
     * 检查是否有钩子
     */
    public static function has_filter($hook_name, $callback = false) {
        if (class_exists('Todo_Core_Kernel')) {
            return Todo_Core_Kernel::has_hook($hook_name, $callback);
        }
        return false;
    }
    
    /**
     * 检查是否有动作钩子
     */
    public static function has_action($hook_name, $callback = false) {
        return self::has_filter($hook_name, $callback);
    }
    
    /**
     * 获取当前钩子名
     */
    public static function current_filter() {
        if (class_exists('Todo_Core_Kernel')) {
            return Todo_Core_Kernel::current_hook();
        }
        return '';
    }
    
    /**
     * 获取当前动作名
     */
    public static function current_action() {
        return self::current_filter();
    }
    
    /**
     * 检查是否正在执行某个钩子
     */
    public static function doing_action($hook_name = null) {
        $current = self::current_filter();
        if ($hook_name === null) return !empty($current);
        return $current === $hook_name;
    }
    
    /**
     * 检查是否正在执行某个过滤器
     */
    public static function doing_filter($hook_name = null) {
        return self::doing_action($hook_name);
    }

    /**
     * 获取所有已注册的钩子 (Disabled for Security)
     */
    public static function get_all_hooks() {
        return [];
    }
    
    /**
     * 清除所有钩子 (Disabled for Security)
     */
    public static function clear_all_hooks() {
        return;
    }
}

/**
 * 应用过滤器
 *
 * @param string $hook_name 钩子名称
 * @param mixed $value 要过滤的值
 * @param mixed ...$args 额外参数
 * @return mixed 过滤后的值
 */
function todo_apply_filters($hook_name, $value, ...$args) {
    return TodoHooks::apply_filters($hook_name, $value, ...$args);
}

/**
 * 执行动作
 *
 * @param string $hook_name 钩子名称
 * @param mixed ...$args 参数
 */
function todo_do_action($hook_name, ...$args) {
    return TodoHooks::do_action($hook_name, ...$args);
}

/**
 * 添加动作
 *
 * @param string $hook_name 钩子名称
 * @param callable $callback 回调函数
 * @param int $priority 优先级
 * @param int $accepted_args 接受的参数数量
 * @return bool
 */
function todo_add_action($hook_name, $callback, $priority = 10, $accepted_args = 1) {
    return TodoHooks::add_action($hook_name, $callback, $priority, $accepted_args);
}

/**
 * 添加AJAX动作
 *
 * @param string $action AJAX动作名称
 * @param callable $callback 回调函数
 * @param int $priority 优先级
 * @param int $accepted_args 接受的参数数量
 * @return bool
 */
function todo_add_ajax_action($action, $callback, $priority = 10, $accepted_args = 1) {
    // 为已登录用户注册AJAX动作
    $result1 = TodoHooks::add_action("todo_ajax_{$action}", $callback, $priority, $accepted_args);
    
    // 为未登录用户注册AJAX动作（如果需要）
    $result2 = TodoHooks::add_action("todo_ajax_nopriv_{$action}", $callback, $priority, $accepted_args);
    
    return $result1 && $result2;
}

// 全局函数别名，替换TodoCMS函数
if (!function_exists('add_action')) {
    function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1) {
        return TodoHooks::add_action($hook_name, $callback, $priority, $accepted_args);
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1) {
        return TodoHooks::add_filter($hook_name, $callback, $priority, $accepted_args);
    }
}

if (!function_exists('do_action')) {
    function do_action($hook_name, ...$args) {
        return TodoHooks::do_action($hook_name, ...$args);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook_name, $value, ...$args) {
        return TodoHooks::apply_filters($hook_name, $value, ...$args);
    }
}

if (!function_exists('remove_action')) {
    function remove_action($hook_name, $callback, $priority = 10) {
        return TodoHooks::remove_action($hook_name, $callback, $priority);
    }
}

if (!function_exists('remove_filter')) {
    function remove_filter($hook_name, $callback, $priority = 10) {
        return TodoHooks::remove_filter($hook_name, $callback, $priority);
    }
}

if (!function_exists('has_action')) {
    function has_action($hook_name, $callback = false) {
        return TodoHooks::has_action($hook_name, $callback);
    }
}

if (!function_exists('has_filter')) {
    function has_filter($hook_name, $callback = false) {
        return TodoHooks::has_filter($hook_name, $callback);
    }
}

if (!function_exists('current_filter')) {
    function current_filter() {
        return TodoHooks::current_filter();
    }
}

if (!function_exists('current_action')) {
    function current_action() {
        return TodoHooks::current_action();
    }
}

if (!function_exists('doing_action')) {
    function doing_action($hook_name = null) {
        return TodoHooks::doing_action($hook_name);
    }
}

if (!function_exists('doing_filter')) {
    function doing_filter($hook_name = null) {
        return TodoHooks::doing_filter($hook_name);
    }
}

if (!function_exists('todo_apply_filters')) {
    function todo_apply_filters($hook_name, $value, ...$args) {
        return TodoHooks::apply_filters($hook_name, $value, ...$args);
    }
}