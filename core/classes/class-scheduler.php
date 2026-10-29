<?php
/**
 * 任务调度器类
 *
 * 处理定时任务和计划任务
 *
 * @package TodoCMS
 * @version 1.0.0
 */
class Todo_Scheduler {
    public static function init() {
        if (function_exists('add_action')) {
            add_action('pre_request', [__CLASS__, 'runDue']);
        }
    }
    private static function load() {
        $s = function_exists('get_option') ? get_option('todo_schedules', []) : [];
        return is_array($s) ? $s : [];
    }
    private static function save($s) {
        return function_exists('update_option') ? update_option('todo_schedules', $s, 'general') : false;
    }
    public static function nextScheduled($hook) {
        $s = self::load();
        return isset($s[$hook]['next']) ? intval($s[$hook]['next']) : 0;
    }
    public static function scheduleEvent($timestamp, $recurrence, $hook) {
        $s = self::load();
        $s[$hook] = ['next' => intval($timestamp), 'recurrence' => strtolower($recurrence)];
        self::save($s);
        return true;
    }
    public static function clearHook($hook) {
        $s = self::load();
        if (isset($s[$hook])) {
            unset($s[$hook]);
            self::save($s);
        }
        return true;
    }
    private static function interval($recurrence) {
        $r = strtolower($recurrence);
        if ($r === 'five_minutes' || $r === '5min' || $r === '5mins' || $r === '5_minutes') return 300;
        if ($r === 'ten_minutes' || $r === '10min' || $r === '10mins' || $r === '10_minutes') return 600;
        if ($r === 'twicedaily') return 43200;
        if ($r === 'daily') return 86400;
        return 3600;
    }
    public static function runDue() {
        $now = time();
        $s = self::load();
        $changed = false;
        foreach ($s as $hook => $info) {
            $next = isset($info['next']) ? intval($info['next']) : 0;
            $rec = isset($info['recurrence']) ? $info['recurrence'] : 'hourly';
            if ($next > 0 && $now >= $next) {
                if (function_exists('do_action')) {
                    do_action($hook);
                }
                $s[$hook]['next'] = $now + self::interval($rec);
                $changed = true;
            }
        }
        if ($changed) {
            self::save($s);
        }
    }
}
