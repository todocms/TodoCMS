<?php
/**
 * TodoCMS Transient Functions
 * 临时缓存函数
 *
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 获取临时缓存值
 *
 * @param string $transient 缓存键名
 * @return mixed|false 缓存值，不存在或过期返回 false
 */
if (!function_exists('get_transient')) {
    function get_transient($transient) {
        // 获取缓存值和过期时间
        $value = get_option('_transient_' . $transient, false);
        $timeout = get_option('_transient_timeout_' . $transient, 0);
        
        // 检查是否过期
        if ($timeout && $timeout < time()) {
            delete_transient($transient);
            return false;
        }
        
        return $value;
    }
}

/**
 * 设置临时缓存值
 *
 * @param string $transient 缓存键名
 * @param mixed $value 缓存值
 * @param int $expiration 过期时间（秒），0表示不过期
 * @return bool 是否设置成功
 */
if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0) {
        $result = update_option('_transient_' . $transient, $value);
        
        if ($expiration > 0) {
            update_option('_transient_timeout_' . $transient, time() + $expiration);
        } else {
            delete_option('_transient_timeout_' . $transient);
        }
        
        return $result;
    }
}

/**
 * 删除临时缓存值
 *
 * @param string $transient 缓存键名
 * @return bool 是否删除成功
 */
if (!function_exists('delete_transient')) {
    function delete_transient($transient) {
        $result1 = delete_option('_transient_' . $transient);
        $result2 = delete_option('_transient_timeout_' . $transient);
        
        return $result1 || $result2;
    }
}

/**
 * 清理过期的临时缓存
 *
 * @return int 清理的缓存数量
 */
if (!function_exists('todo_cleanup_expired_transients')) {
    function todo_cleanup_expired_transients() {
        global $tododb;
        
        if (!$tododb) {
            return 0;
        }
        
        $current_time = time();
        $count = 0;
        
        try {
            // 查找所有过期的 transient
            // 注意：system_config 表使用 config_key 和 config_value 字段
            $sql = "SELECT config_key FROM {$tododb->prefix}system_config 
                   WHERE config_key LIKE '_transient_timeout_%' 
                   AND CAST(config_value AS UNSIGNED) < ?";
            
            $expired_timeouts = $tododb->get_col($sql, array($current_time));
            
            foreach ($expired_timeouts as $timeout_option) {
                // 提取 transient 名称
                $transient_name = str_replace('_transient_timeout_', '', $timeout_option);
                
                // 删除过期的 transient
                if (delete_transient($transient_name)) {
                    $count++;
                }
            }
            
        } catch (Exception $e) {
            error_log('TodoCMS Transient cleanup error: ' . $e->getMessage());
        }
        
        return $count;
    }
}

/**
 * 获取所有 transient 键名
 *
 * @return array transient 键名数组
 */
if (!function_exists('todo_get_all_transients')) {
    function todo_get_all_transients() {
        global $tododb;
        
        if (!$tododb) {
            return array();
        }
        
        try {
            // 注意：system_config 表使用 config_key 字段
            $sql = "SELECT config_key FROM {$tododb->prefix}system_config 
                   WHERE config_key LIKE '_transient_%' 
                   AND config_key NOT LIKE '_transient_timeout_%'";
            
            $transients = $tododb->get_col($sql);
            
            // 移除 '_transient_' 前缀
            return array_map(function($name) {
                return str_replace('_transient_', '', $name);
            }, $transients);
            
        } catch (Exception $e) {
            error_log('TodoCMS Get transients error: ' . $e->getMessage());
            return array();
        }
    }
}

/**
 * 清理所有 transient 缓存
 *
 * @return int 清理的缓存数量
 */
if (!function_exists('todo_clear_all_transients')) {
    function todo_clear_all_transients() {
        $transients = todo_get_all_transients();
        $count = 0;
        
        foreach ($transients as $transient) {
            if (delete_transient($transient)) {
                $count++;
            }
        }
        
        return $count;
    }
}

/**
 * 定时清理过期 transient（钩子函数）
 */
if (!function_exists('todo_scheduled_transient_cleanup')) {
    function todo_scheduled_transient_cleanup() {
        $cleaned = todo_cleanup_expired_transients();
        
        if ($cleaned > 0) {
            error_log("TodoCMS: Cleaned {$cleaned} expired transients");
        }
    }
}

// 添加定时清理钩子（每小时执行一次）
if (function_exists('todo_add_action')) {
    todo_add_action('todo_hourly_cleanup', 'todo_scheduled_transient_cleanup');
}

if (!function_exists('todo_maybe_run_transient_cleanup')) {
    function todo_maybe_run_transient_cleanup() {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }

        $now = time();
        $last_run = 0;
        if (function_exists('todo_get_option')) {
            $last_run = (int) todo_get_option('todo_transient_cleanup_last_run', 0);
        } elseif (function_exists('get_option')) {
            $last_run = (int) get_option('todo_transient_cleanup_last_run', 0);
        }

        if ($last_run > 0 && ($now - $last_run) < 3600) {
            return;
        }

        // 延迟执行，避免阻塞页面渲染
        register_shutdown_function(function() use ($now) {
            // 如果是 PHP-FPM 环境，提前结束请求，加速前端响应
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            if (function_exists('update_option')) {
                update_option('todo_transient_cleanup_last_run', $now);
            }
            todo_scheduled_transient_cleanup();
        });
    }
}

if (function_exists('todo_add_action')) {
    todo_add_action('todo_init', 'todo_maybe_run_transient_cleanup', 999);
}
