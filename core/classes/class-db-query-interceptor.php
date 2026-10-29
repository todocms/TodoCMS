<?php
/**
 * 数据库查询拦截器
 * 
 * 在CMS_DB类层面拦截所有查询，实现真正的查询优化
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 数据库查询拦截器类
 */
class Todo_DB_Query_Interceptor
{

    /**
     * 请求级查询缓存
     * @var array [cache_key => result]
     */
    private static $request_cache = [];

    /**
     * 待合并的查询队列
     * @var array [pattern => [queries]]
     */
    private static $merge_queue = [];

    /**
     * 查询统计
     * @var array
     */
    private static $stats = [
        'total_queries' => 0,
        'cached_queries' => 0,
        'merged_queries' => 0,
        'duplicate_queries' => 0
    ];

    /**
     * 是否启用拦截
     * @var bool
     */
    private static $enabled = true;

    /**
     * 启用拦截
     */
    public static function enable()
    {
        self::$enabled = true;
    }

    /**
     * 禁用拦截
     */
    public static function disable()
    {
        self::$enabled = false;
    }

    /**
     * 检查是否启用
     * 
     * @return bool
     */
    public static function is_enabled()
    {
        return self::$enabled;
    }

    /**
     * 拦截查询（在CMS_DB方法中调用）
     * 
     * @param string $method 方法名（get_results, get_row, get_var）
     * @param string $query SQL查询
     * @param array $params 参数
     * @param callable $original_callback 原始查询回调
     * @return mixed
     */
    public static function intercept($method, $query, $params, $original_callback, $output_type = null)
    {
        if (!self::$enabled) {
            return call_user_func($original_callback, $query, $params);
        }

        // 验证查询字符串（在拦截器处理之前）
        if (empty($query) || !is_string($query)) {
            $error_msg = "Todo_DB_Query_Interceptor::intercept: Invalid query - empty or not a string";
            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                $error_msg .= "\nQuery type: " . gettype($query);
                $error_msg .= "\nQuery value: " . var_export($query, true);
                $error_msg .= "\nMethod: " . $method;
                $error_msg .= "\nBacktrace: " . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5), true);
            }
            error_log($error_msg);
            // 如果查询无效，直接返回 null 或调用原始回调（让原始方法处理错误）
            return call_user_func($original_callback, $query, $params);
        }

        // 检查查询是否只包含占位符
        $query_trimmed = trim($query);
        if ($query_trimmed === '?' || preg_match('/^\s*\?\s*$/', $query_trimmed)) {
            $error_msg = "Todo_DB_Query_Interceptor::intercept: Invalid query - contains only placeholder '?'";
            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                $error_msg .= "\nQuery value: " . var_export($query, true);
                $error_msg .= "\nMethod: " . $method;
                $error_msg .= "\nBacktrace: " . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5), true);
            }
            error_log($error_msg);
            // 如果查询无效，直接返回 null 或调用原始回调（让原始方法处理错误）
            return call_user_func($original_callback, $query, $params);
        }

        self::$stats['total_queries']++;

        // 生成缓存键
        $cache_key = self::generate_cache_key($method, $query, $params, $output_type);

        // 检查请求级缓存
        if (isset(self::$request_cache[$cache_key])) {
            self::$stats['cached_queries']++;
            $result = self::$request_cache[$cache_key];

            // 根据方法类型返回适当的结果
            return self::format_result($result, $method, $output_type);
        }

        // 检查是否可以合并（仅对SELECT查询）
        // Note: Synchronous merging is structurally broken and causes duplicate execution.
        // It has been disabled to prevent performance degradation.

        // 执行原始查询（在调用之前再次验证）
        if (empty($query) || !is_string($query) || trim($query) === '' || trim($query) === '?') {
            $error_msg = "Todo_DB_Query_Interceptor::intercept: Query became invalid before callback execution";
            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                $error_msg .= "\nQuery value: " . var_export($query, true);
                $error_msg .= "\nMethod: " . $method;
                $error_msg .= "\nBacktrace: " . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5), true);
            }
            error_log($error_msg);
            // 返回 null 或让原始回调处理
            return call_user_func($original_callback, $query, $params);
        }

        $result = call_user_func($original_callback, $query, $params);

        // 缓存结果
        self::$request_cache[$cache_key] = $result;

        return $result;
    }

    /**
     * 检查是否可以合并查询
     * 
     * @param string $query SQL查询
     * @param array $params 参数
     * @return bool
     */
    private static function can_merge($query, $params)
    {
        // 检查是否是单参数WHERE条件查询
        if (count($params) !== 1) {
            return false;
        }

        // 检查是否是 WHERE field = ? 模式
        if (preg_match('/WHERE\s+\w+\s*=\s*\?/i', $query)) {
            return true;
        }

        return false;
    }

    /**
     * 尝试合并查询
     * 
     * @param string $query SQL查询
     * @param array $params 参数
     * @param string $method 方法名
     * @param callable $original_callback 原始回调
     * @return mixed|null
     */
    private static function try_merge($query, $params, $method, $original_callback, $output_type = null)
    {
        $normalized = self::normalize_query($query);
        // 安全检查：确保params不为空
        if (empty($params) || !isset($params[0])) {
            return null;
        }
        $param_value = $params[0];

        // 检查是否有相同模式的待合并查询
        if (!isset(self::$merge_queue[$normalized])) {
            self::$merge_queue[$normalized] = [];
        }

        // 检查是否已有相同参数的查询
        foreach (self::$merge_queue[$normalized] as $item) {
            if (!empty($item['params']) && isset($item['params'][0]) && $item['params'][0] === $param_value) {
                // 找到相同查询，返回缓存结果
                self::$stats['duplicate_queries']++;
                return self::format_result($item['result'], $method);
            }
        }

        // 添加到合并队列
        self::$merge_queue[$normalized][] = [
            'query' => $query,
            'params' => $params,
            'method' => $method,
            'output_type' => $output_type,
            'result' => null
        ];

        // 性能优化：降低批量执行阈值，更积极地合并查询
        // 如果队列中有2个或更多查询，立即批量执行
        if (count(self::$merge_queue[$normalized]) >= 2) {
            return self::execute_batch($normalized, $method, $original_callback);
        }

        // 延迟执行，等待更多查询（但设置最大延迟时间）
        // 如果队列中只有一个查询，延迟执行以等待更多查询合并
        // 但为了避免无限延迟，在flush时强制执行
        return null;
    }

    /**
     * 执行批量查询
     * 
     * @param string $normalized 规范化查询
     * @param string $method 方法名
     * @param callable $original_callback 原始回调
     * @return mixed
     */
    private static function execute_batch($normalized, $method, $original_callback)
    {
        $queries = self::$merge_queue[$normalized];

        // 提取所有参数值
        $param_values = [];
        foreach ($queries as $item) {
            if (!empty($item['params']) && isset($item['params'][0])) {
                $param_values[] = $item['params'][0];
            }
        }

        // 如果没有有效参数，返回null
        if (empty($param_values)) {
            return null;
        }

        // 构建IN查询
        $first_query = $queries[0]['query'];

        // 验证第一个查询不为空
        if (empty($first_query) || !is_string($first_query)) {
            error_log('Todo_DB_Query_Interceptor::execute_batch: First query is empty or not a string');
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($param_values), '?'));
        $merged_query = preg_replace('/WHERE\s+(\w+)\s*=\s*\?/i', 'WHERE $1 IN (' . $placeholders . ')', $first_query);

        // 验证合并后的查询不为空
        if (empty($merged_query) || !is_string($merged_query)) {
            error_log('Todo_DB_Query_Interceptor::execute_batch: Merged query is empty or not a string');
            error_log('Original query: ' . substr($first_query, 0, 200));
            return null;
        }

        // 执行合并查询
        $batch_results = call_user_func($original_callback, $merged_query, $param_values);

        // 分发结果
        $param_field = self::extract_param_field($first_query);
        $results_by_param = [];

        if (is_array($batch_results)) {
            foreach ($batch_results as $row) {
                $data = is_object($row) ? (array) $row : $row;
                $param_value = isset($data[$param_field]) ? $data[$param_field] : null;

                if ($param_value !== null) {
                    if (!isset($results_by_param[$param_value])) {
                        $results_by_param[$param_value] = [];
                    }
                    $results_by_param[$param_value][] = $row;
                }
            }
        }

        // 为每个查询设置结果并缓存
        foreach ($queries as $item) {
            if (empty($item['params']) || !isset($item['params'][0])) {
                continue;
            }
            $param_value = $item['params'][0];
            $result = isset($results_by_param[$param_value]) ? $results_by_param[$param_value] : [];

            // 根据方法类型格式化结果
            if ($method === 'get_row' && !empty($result)) {
                if (is_array($result) && isset($result[0])) {
                    $result = is_object($result[0]) ? $result[0] : (object) $result[0];
                } elseif (is_array($result)) {
                    // 关联数组，直接转换
                    $result = (object) $result;
                } elseif (is_object($result)) {
                    // 已经是对象，保持不变
                } else {
                    $result = null;
                }
            } elseif ($method === 'get_var' && !empty($result)) {
                if (is_array($result) && isset($result[0])) {
                    $first_row = is_object($result[0]) ? (array) $result[0] : $result[0];
                    $result = !empty($first_row) ? reset($first_row) : null;
                } elseif (is_object($result)) {
                    $arr = (array) $result;
                    $result = !empty($arr) ? reset($arr) : null;
                } elseif (!is_array($result)) {
                    // 已经是标量值
                } else {
                    $result = null;
                }
            }

            // 缓存结果
            $cache_key = self::generate_cache_key($item['method'], $item['query'], $item['params'], $item['output_type'] ?? null);
            self::$request_cache[$cache_key] = $result;
            $item['result'] = $result;
        }

        // 清空队列
        unset(self::$merge_queue[$normalized]);

        // 返回最后一个查询的结果
        return self::format_result(end($queries)['result'], $method, $queries[0]['output_type'] ?? null);
    }

    /**
     * 格式化结果
     * 
     * @param mixed $result 结果
     * @param string $method 方法名
     * @return mixed
     */
    private static function format_result($result, $method, $output_type = null)
    {
        if ($method === 'get_row') {
            // 如果需要数组格式
            if ($output_type === 'ARRAY_A') {
                if (is_object($result)) {
                    return (array) $result;
                }
                if (is_array($result)) {
                    return $result;
                }
                return null;
            }

            // 默认对象格式
            if (is_object($result)) {
                return $result;
            }
            // 如果是数组且不为空
            if (is_array($result) && !empty($result) && isset($result[0])) {
                return is_object($result[0]) ? $result[0] : (object) $result[0];
            }
            // 如果是非空数组但没有索引0，可能是关联数组，直接转换
            if (is_array($result) && !empty($result)) {
                return (object) $result;
            }
            return null;
        } elseif ($method === 'get_var') {
            // 如果已经是标量值，直接返回
            if (!is_array($result) && !is_object($result)) {
                return $result;
            }
            // 如果是数组且不为空
            if (is_array($result) && !empty($result) && isset($result[0])) {
                $first_row = is_object($result[0]) ? (array) $result[0] : $result[0];
                return !empty($first_row) ? reset($first_row) : null;
            }
            // 如果是对象，转换为数组后取第一个值
            if (is_object($result)) {
                $arr = (array) $result;
                return !empty($arr) ? reset($arr) : null;
            }
            return $result;
        }

        if ($method === 'get_results' && $output_type === 'ARRAY_A') {
            if (is_array($result)) {
                foreach ($result as &$row) {
                    if (is_object($row)) {
                        $row = (array) $row;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * 规范化查询
     * 
     * @param string $query SQL查询
     * @return string
     */
    private static function normalize_query($query)
    {
        $normalized = preg_replace('/\?/', '?', $query);
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        return trim($normalized);
    }

    /**
     * 提取参数字段名
     * 
     * @param string $query SQL查询
     * @return string|null
     */
    private static function extract_param_field($query)
    {
        if (preg_match('/WHERE\s+(\w+)\s*=\s*\?/i', $query, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * 生成缓存键
     * 
     * @param string $query SQL查询
     * @param array $params 参数
     * @return string
     */
    private static function generate_cache_key($method, $query, $params, $output_type = null)
    {
        $key_data = [
            'method' => (string) $method,
            'output_type' => $output_type === null ? null : (string) $output_type,
            'query' => self::normalize_query($query),
            'params' => $params
        ];
        return md5(serialize($key_data));
    }

    /**
     * 刷新合并队列（强制执行所有待合并的查询）
     */
    public static function flush()
    {
        global $tododb;

        foreach (self::$merge_queue as $normalized => $queries) {
            // 性能优化：如果队列中有多个查询，尝试批量执行
            if (count($queries) >= 2) {
                // 尝试批量执行
                $first_item = $queries[0];
                if (!empty($first_item['query']) && !empty($first_item['params']) && isset($first_item['params'][0])) {
                    // 构建批量查询回调（直接调用get_results，但会跳过拦截器）
                    $batch_callback = function ($query, $params) use ($tododb) {
                        // 临时禁用拦截器，直接执行查询
                        $was_enabled = self::is_enabled();
                        self::disable();
                        $result = $tododb->get_results($query, $params, 'OBJECT');
                        if ($was_enabled) {
                            self::enable();
                        }
                        return $result;
                    };

                    $batch_result = self::execute_batch($normalized, $first_item['method'], $batch_callback);
                    if ($batch_result !== null) {
                        // 批量执行成功，跳过单个执行
                        continue;
                    }
                }
            }

            // 逐个执行剩余的查询
            foreach ($queries as $item) {
                if ($item['result'] === null && !empty($item['query'])) {
                    $result = call_user_func([$tododb, $item['method']], $item['query'], $item['params']);
                    $cache_key = self::generate_cache_key($item['method'], $item['query'], $item['params'], $item['output_type'] ?? null);
                    self::$request_cache[$cache_key] = $result;
                }
            }
        }

        self::$merge_queue = [];
    }

    /**
     * 清除缓存
     */
    public static function clear_cache()
    {
        self::$request_cache = [];
        self::$merge_queue = [];
    }

    /**
     * 获取统计信息
     * 
     * @return array
     */
    public static function get_stats()
    {
        return self::$stats;
    }

    /**
     * 重置统计
     */
    public static function reset_stats()
    {
        self::$stats = [
            'total_queries' => 0,
            'cached_queries' => 0,
            'merged_queries' => 0,
            'duplicate_queries' => 0
        ];
    }
}
