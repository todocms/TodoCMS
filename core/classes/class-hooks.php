<?php
/**
 * 钩子系统类
 *
 * 实现TodoCMS的动作和过滤器钩子系统
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access denied.');
}
 
class CMS_Hooks {
    /**
     * 动作钩子存储
     *
     * @var array
     */
    private static $actions = [];
    
    /**
     * 过滤器钩子存储
     *
     * @var array
     */
    private static $filters = [];
    
    /**
     * 当前正在执行的钩子
     *
     * @var array
     */
    private static $current_hook = [];
    
    /**
     * 初始化钩子系统
     */
    public static function init() {
        // 初始化钩子存储
        self::$actions = [];
        self::$filters = [];
        self::$current_hook = [];
    }
    
    /**
     * 添加动作钩子
     *
     * @param string $hook_name 钩子名称
     * @param callable $callback 回调函数
     * @param int $priority 优先级
     * @param int $accepted_args 接受的参数数量
     */
    public static function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1) {
        // 使用过滤器机制来实现动作
        self::add_filter($hook_name, $callback, $priority, $accepted_args);
    }
    
    /**
     * 执行动作钩子
     *
     * @param string $hook_name 钩子名称
     * @param array $args 参数
     */
    public static function do_action($hook_name, $args = []) {
        // 如果没有回调，直接返回
        if (!isset(self::$filters[$hook_name])) {
            return;
        }
        
        // 将当前钩子添加到执行栈
        self::$current_hook[] = $hook_name;
        
        // 执行钩子前的操作
        self::do_action('before_' . $hook_name, $args);
        
        // 按优先级排序
        ksort(self::$filters[$hook_name]);
        
        // 执行所有回调
        foreach (self::$filters[$hook_name] as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                call_user_func_array($callback['function'], array_slice($args, 0, $callback['accepted_args']));
            }
        }
        
        // 执行钩子后的操作
        self::do_action('after_' . $hook_name, $args);
        
        // 从执行栈中移除当前钩子
        array_pop(self::$current_hook);
    }
    
    /**
     * 移除动作钩子
     *
     * @param string $hook_name 钩子名称
     * @param callable $callback 回调函数
     * @param int $priority 优先级
     */
    public static function remove_action($hook_name, $callback, $priority = 10) {
        // 使用过滤器机制来实现动作
        self::remove_filter($hook_name, $callback, $priority);
    }
    
    /**
     * 添加过滤器钩子
     *
     * @param string $hook_name 钩子名称
     * @param callable $callback 回调函数
     * @param int $priority 优先级
     * @param int $accepted_args 接受的参数数量
     */
    public static function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1) {
        // 初始化钩子数组
        if (!isset(self::$filters[$hook_name])) {
            self::$filters[$hook_name] = [];
        }
        
        // 初始化优先级数组
        if (!isset(self::$filters[$hook_name][$priority])) {
            self::$filters[$hook_name][$priority] = [];
        }
        
        // 添加回调
        self::$filters[$hook_name][$priority][] = [
            'function' => $callback,
            'accepted_args' => $accepted_args
        ];
    }
    
    /**
     * 应用过滤器
     *
     * @param string $hook_name 钩子名称
     * @param mixed $value 要过滤的值
     * @param array $args 其他参数
     * @return mixed 过滤后的值
     */
    public static function apply_filters($hook_name, $value, $args = []) {
        // 如果没有回调，直接返回值
        if (!isset(self::$filters[$hook_name])) {
            return $value;
        }
        
        // 将当前钩子添加到执行栈
        self::$current_hook[] = $hook_name;
        
        // 将值添加到参数数组的开头
        array_unshift($args, $value);
        
        // 按优先级排序
        ksort(self::$filters[$hook_name]);
        
        // 执行所有回调
        foreach (self::$filters[$hook_name] as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                // 执行回调并更新值
                $args[0] = call_user_func_array($callback['function'], array_slice($args, 0, $callback['accepted_args']));
            }
        }
        
        // 从执行栈中移除当前钩子
        array_pop(self::$current_hook);
        
        // 返回过滤后的值
        return $args[0];
    }
    
    /**
     * 移除过滤器钩子
     *
     * @param string $hook_name 钩子名称
     * @param callable $callback 回调函数
     * @param int $priority 优先级
     */
    public static function remove_filter($hook_name, $callback, $priority = 10) {
        // 如果钩子不存在，直接返回
        if (!isset(self::$filters[$hook_name][$priority])) {
            return;
        }
        
        // 查找并移除回调
        foreach (self::$filters[$hook_name][$priority] as $key => $filter) {
            if ($filter['function'] === $callback) {
                unset(self::$filters[$hook_name][$priority][$key]);
                break;
            }
        }
        
        // 如果优先级数组为空，移除它
        if (empty(self::$filters[$hook_name][$priority])) {
            unset(self::$filters[$hook_name][$priority]);
        }
        
        // 如果钩子数组为空，移除它
        if (empty(self::$filters[$hook_name])) {
            unset(self::$filters[$hook_name]);
        }
    }
    
    /**
     * 检查钩子是否已添加
     *
     * @param string $hook_name 钩子名称
     * @param callable $callback 回调函数
     * @param int $priority 优先级
     * @return bool 是否已添加
     */
    public static function has_filter($hook_name, $callback = false, $priority = 10) {
        // 如果钩子不存在，返回false
        if (!isset(self::$filters[$hook_name][$priority])) {
            return false;
        }
        
        // 如果没有指定回调，只检查钩子是否存在
        if ($callback === false) {
            return !empty(self::$filters[$hook_name]);
        }
        
        // 查找回调
        foreach (self::$filters[$hook_name][$priority] as $filter) {
            if ($filter['function'] === $callback) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 获取当前正在执行的钩子
     *
     * @return string|null 当前钩子名称
     */
    public static function current_hook() {
        return end(self::$current_hook) ?: null;
    }
    
    /**
     * 检查钩子是否正在执行
     *
     * @param string $hook_name 钩子名称
     * @return bool 是否正在执行
     */
    public static function doing_hook($hook_name) {
        return in_array($hook_name, self::$current_hook);
    }
    
    /**
     * 移除所有钩子
     *
     * @param string $hook_name 钩子名称
     * @param int $priority 优先级，如果为false则移除所有优先级
     */
    public static function remove_all_hooks($hook_name, $priority = false) {
        if ($priority !== false) {
            // 移除指定优先级的钩子
            if (isset(self::$filters[$hook_name][$priority])) {
                unset(self::$filters[$hook_name][$priority]);
            }
        } else {
            // 移除所有优先级的钩子
            if (isset(self::$filters[$hook_name])) {
                unset(self::$filters[$hook_name]);
            }
        }
    }
}