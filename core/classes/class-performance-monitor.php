<?php
/**
 * 性能监控类
 *
 * @package TodoCMS
 * @version 1.0.0
 */

class Todo_Performance_Monitor
{

    /**
     * 计时器存储
     * @var array
     */
    private static $timers = [];

    /**
     * 是否启用性能监控
     * @var bool
     */
    private static $enabled = false;

    /**
     * Server Timing 数据
     * @var array
     */
    private static $server_timings = [];

    /**
     * 是否已初始化
     * @var bool
     */
    private static $initialized = false;

    /**
     * 初始化性能监控
     *
     * @param bool $enabled 是否启用
     */
    public static function init($enabled = null)
    {
        if ($enabled !== null) {
            self::$enabled = (bool) $enabled;
        } else {
            // 检查常量配置
            if (defined('TODO_ENABLE_PERF_MONITOR') && TODO_ENABLE_PERF_MONITOR) {
                self::$enabled = true;
            } elseif (defined('TODO_DEBUG') && TODO_DEBUG) {
                self::$enabled = true;
            } else {
                self::$enabled = false;
            }
        }

        if (self::$enabled) {
            // 记录开始时间
            if (!defined('CMS_START_TIME')) {
                define('CMS_START_TIME', microtime(true));
            }
        }

        // 注册关闭函数以发送 Server-Timing 头
        if (self::$enabled && !self::$initialized) {
            register_shutdown_function([__CLASS__, 'send_server_timing_header']);
            self::$initialized = true;

            // 记录初始化时间
            self::$server_timings[] = [
                'name' => 'init',
                'duration' => (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000,
                'description' => 'Bootstrap Time'
            ];
        }
    }

    /**
     * 开始计时
     *
     * @param string $label 计时器标签
     */
    public static function start($label)
    {
        if (!self::$enabled) {
            return;
        }

        self::$timers[$label] = microtime(true);
    }

    /**
     * 结束计时并记录
     *
     * @param string $label 计时器标签
     * @param bool $log_to_error 是否记录到错误日志
     * @param string $description 描述（用于 Server-Timing）
     * @return float|null 执行时间（秒），如果未启用则返回null
     */
    public static function end($label, $log_to_error = false, $description = '')
    {
        if (!self::$enabled) {
            return null;
        }

        if (!isset(self::$timers[$label])) {
            // Timer might have been cleared or not started
            return null;
        }

        $elapsed = microtime(true) - self::$timers[$label];
        $ms = $elapsed * 1000;

        // 记录到 Server Timing
        self::$server_timings[] = [
            'name' => $label,
            'duration' => $ms,
            'description' => $description
        ];

        if ($log_to_error) {
            $ms_formatted = number_format($ms, 2);
            error_log("PERF: $label took {$ms_formatted}ms");
        }

        unset(self::$timers[$label]);

        return $elapsed;
    }

    /**
     * 测量代码块执行时间
     *
     * @param string $label 标签
     * @param callable $callback 回调函数
     * @param string $description 描述
     * @return mixed 回调返回值
     */
    public static function measure($label, callable $callback, $description = '')
    {
        self::start($label);
        try {
            return call_user_func($callback);
        } finally {
            self::end($label, false, $description);
        }
    }

    /**
     * 添加自定义指标（不计时的）
     * 
     * @param string $name
     * @param string $description
     * @param float|null $duration
     */
    public static function add_metric($name, $description = '', $duration = null)
    {
        if (!self::$enabled) {
            return;
        }

        self::$server_timings[] = [
            'name' => $name,
            'duration' => $duration,
            'description' => $description
        ];

        // Log to system error log if needed, or just remove
        // error_log("PERF_DEBUG: Added metric $name");
    }

    /**
     * 发送 Server-Timing 响应头
     */
    public static function send_server_timing_header()
    {
        if (!self::$enabled) {
            return;
        }

        // 检查是否已经发送了头部
        if (headers_sent()) {
            return;
        }

        // 尝试收集数据库统计信息（确保在发送头之前记录）
        if (class_exists('CMS_DB') && method_exists('CMS_DB', 'get_instance')) {
            $db = CMS_DB::get_instance();
            if ($db && method_exists($db, 'log_performance_stats')) {
                $db->log_performance_stats();
            }
        }

        if (empty(self::$server_timings)) {
            return;
        }

        $parts = [];
        $db_count = 0;
        $db_duration = 0;

        foreach (self::$server_timings as $timing) {
            // 聚合 DB 查询
            if ($timing['name'] === 'db') {
                $db_count++;
                $db_duration += $timing['duration'];

                // 仅保留超过 50ms 的慢查询作为独立条目
                if ($timing['duration'] < 50) {
                    continue;
                }
                $timing['name'] = 'db_slow'; // 重命名以免混淆
            }

            // 移除 name 中的非法字符
            $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $timing['name']);
            $part = $name;

            if ($timing['duration'] !== null) {
                $part .= ';dur=' . number_format($timing['duration'], 2, '.', '');
            }
            if (!empty($timing['description'])) {
                // 移除换行符以防止 Header 注入攻击和 PHP 警告
                $desc = str_replace(["\r", "\n"], ' ', $timing['description']);
                // 截断过长的描述
                $desc = substr($desc, 0, 100);
                $part .= ';desc="' . addslashes($desc) . '"';
            }
            $parts[] = $part;
        }

        // 添加 DB 总计
        if ($db_count > 0) {
            $parts[] = 'db_total;dur=' . number_format($db_duration, 2, '.', '') . ';desc="' . $db_count . ' queries"';
        }

        // 限制 Header 长度，防止溢出
        if (count($parts) > 50) {
            $parts = array_slice($parts, 0, 50);
            $parts[] = 'truncated;desc="Too many metrics"';
        }
        if (!empty($parts)) {
            $header_value = implode(', ', $parts);
            header('Server-Timing: ' . $header_value);
        }
    }

    /**
     * 获取结构化的性能调试数据
     *
     * @return array
     */
    public static function get_timing_debug_data()
    {
        $debug_val = defined('TODO_DEBUG') ? (TODO_DEBUG ? 'true' : 'false') : 'undefined';
        $perf_val = defined('TODO_ENABLE_PERF_MONITOR') ? (TODO_ENABLE_PERF_MONITOR ? 'true' : 'false') : 'undefined';

        if (!self::$enabled) {
            return [
                'enabled' => false,
                'debug' => $debug_val,
                'perfMonitor' => $perf_val,
                'metrics' => [],
                'dbTotalMs' => 0.0,
                'dbCount' => 0,
                'topQueries' => [],
            ];
        }

        $metrics = [];
        $db_queries = [];
        $db_total_time = 0.0;
        $db_count = 0;

        foreach (self::$server_timings as $timing) {
            if (($timing['name'] ?? '') === 'db') {
                $db_count++;
                $db_total_time += (float) ($timing['duration'] ?? 0);

                $sql = (string) ($timing['description'] ?? '');
                $key = md5($sql);

                if (!isset($db_queries[$key])) {
                    $db_queries[$key] = [
                        'count' => 0,
                        'duration' => 0.0,
                        'sql' => $sql,
                    ];
                }

                $db_queries[$key]['count']++;
                $db_queries[$key]['duration'] += (float) ($timing['duration'] ?? 0);
            } else {
                $metrics[] = [
                    'name' => (string) ($timing['name'] ?? ''),
                    'duration' => (float) ($timing['duration'] ?? 0),
                    'description' => (string) ($timing['description'] ?? ''),
                ];
            }
        }

        uasort($db_queries, function ($a, $b) {
            return $b['duration'] <=> $a['duration'];
        });

        $top_queries = array_slice(array_values($db_queries), 0, 20);
        foreach ($top_queries as &$query_item) {
            $sql_preview = substr($query_item['sql'], 0, 100);
            if (strlen($query_item['sql']) > 100) {
                $sql_preview .= '...';
            }
            $query_item['sqlPreview'] = $sql_preview;
        }
        unset($query_item);

        return [
            'enabled' => true,
            'debug' => $debug_val,
            'perfMonitor' => $perf_val,
            'metrics' => $metrics,
            'dbTotalMs' => $db_total_time,
            'dbCount' => $db_count,
            'topQueries' => $top_queries,
        ];
    }

    /**
     * 获取HTML格式的性能数据注释
     * @return string
     */
    public static function get_timing_html_comment()
    {
        $debug_data = self::get_timing_debug_data();
        if (!$debug_data['enabled']) {
            return "\n<!-- SERVER-TIMING: Monitor Disabled (TODO_DEBUG={$debug_data['debug']}, TODO_ENABLE_PERF_MONITOR={$debug_data['perfMonitor']}) -->\n";
        }

        $output = "\n<!-- SERVER-TIMING DEBUG INFO:\n";

        // 1. 基础耗时
        foreach ($debug_data['metrics'] as $metric) {
            $output .= sprintf("%s: %.2f ms (%s)\n", $metric['name'], $metric['duration'], $metric['description']);
        }

        // 2. 数据库查询摘要
        $output .= sprintf("\nDB Total: %.2f ms (%d queries)\n", $debug_data['dbTotalMs'], $debug_data['dbCount']);
        $output .= "----------------------------------------\n";
        $output .= "Top Queries:\n";

        foreach ($debug_data['topQueries'] as $data) {
            $output .= sprintf("- %.2f ms (%d calls): %s\n", $data['duration'], $data['count'], $data['sqlPreview']);
        }

        $output .= "-->\n";
        return $output;
    }
}
