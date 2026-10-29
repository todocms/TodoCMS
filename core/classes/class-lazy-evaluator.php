<?php
/**
 * 延迟求值器
 * 
 * 延迟执行非关键操作，提升首次加载性能
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 延迟求值器类
 */
class Todo_Lazy_Evaluator {
    
    /**
     * 延迟任务队列
     * @var array
     */
    private static $deferred_tasks = [];
    
    /**
     * 是否启用延迟执行
     * @var bool
     */
    private static $enabled = true;
    
    /**
     * 统计信息
     * @var array
     */
    private static $stats = [
        'tasks_deferred' => 0,
        'tasks_executed' => 0,
        'time_saved' => 0
    ];
    
    /**
     * 启用延迟执行
     */
    public static function enable() {
        self::$enabled = true;
    }
    
    /**
     * 禁用延迟执行
     */
    public static function disable() {
        self::$enabled = false;
        self::flush();
    }
    
    /**
     * 延迟执行任务
     * 
     * @param callable $callback 回调函数
     * @param array $args 参数
     * @param int $priority 优先级（数字越大越先执行）
     */
    public static function defer($callback, $args = [], $priority = 0) {
        if (!self::$enabled) {
            return call_user_func_array($callback, $args);
        }
        
        self::$deferred_tasks[] = [
            'callback' => $callback,
            'args' => $args,
            'priority' => $priority
        ];
        
        self::$stats['tasks_deferred']++;
    }
    
    /**
     * 执行所有延迟任务
     */
    public static function flush() {
        if (empty(self::$deferred_tasks)) {
            return;
        }
        
        // 按优先级排序
        usort(self::$deferred_tasks, function($a, $b) {
            return $b['priority'] - $a['priority'];
        });
        
        $start_time = microtime(true);
        
        foreach (self::$deferred_tasks as $task) {
            call_user_func_array($task['callback'], $task['args']);
            self::$stats['tasks_executed']++;
        }
        
        $time_taken = (microtime(true) - $start_time) * 1000;
        self::$stats['time_saved'] += $time_taken;
        
        self::$deferred_tasks = [];
    }
    
    /**
     * 获取统计信息
     * @return array
     */
    public static function get_stats() {
        return self::$stats;
    }
}

