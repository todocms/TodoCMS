<?php
/**
 * 安全函数扩展文件
 *
 * 包含额外的安全函数
 *
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 清理URL但不进行HTML转义
 *
 * @param string $url 需要清理的URL
 * @return string 清理后的URL
 */
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) {
        return filter_var($url, FILTER_SANITIZE_URL);
    }
}

/**
 * 检查用户名是否已存在
 *
 * @param string $username 需要检查的用户名
 * @return int|bool 如果存在返回用户ID，否则返回false
 */
if (!function_exists('username_exists')) {
    function username_exists($username) {
        // 使用统一的预处理查询接口，避免直接调用 PDO::query 造成参数模式错误
        // 排除已注销账号 (user_status != -1)
        $row = tododb_get_row(
            "SELECT ID FROM " . TABLE_PREFIX . "users WHERE user_login = ? AND user_status != -1 LIMIT 1",
            array($username),
            's'
        );
        if (is_array($row) && isset($row['ID'])) {
            return (int)$row['ID'];
        }
        return false;
    }
}

/**
 * 验证管理员引用nonce
 *
 * @param string $action 动作名称
 * @param string $query_arg nonce参数名
 * @return bool 验证结果
 */
if (!function_exists('check_admin_referer')) {
    function check_admin_referer($action = -1, $query_arg = '_todononce') {
        $query_arg = (string) $query_arg;
        $nonce = function_exists('todo_post_string') ? todo_post_string($query_arg) : (isset($_POST[$query_arg]) && is_scalar($_POST[$query_arg]) ? (string) $_POST[$query_arg] : '');
        if ($nonce === '') {
            $nonce = function_exists('todo_get_query_context_value') ? (string) todo_get_query_context_value($query_arg, (function_exists('todo_get_string') ? todo_get_string($query_arg) : '')) : (function_exists('todo_get_string') ? todo_get_string($query_arg) : '');
        }
        if ($nonce === '' && isset($_GET[$query_arg]) && is_scalar($_GET[$query_arg])) {
            $nonce = (string) $_GET[$query_arg];
        }

        if ($nonce === '') {
            return false;
        }

        return Todo_Security_Manager::verify_csrf_token($nonce, $action);
    }
}

// 统一的图形验证码校验函数（全局实现）
if (!function_exists('todo_verify_captcha')) {
    /**
     * 验证图形验证码
     *
     * 优先读取类型化生成器的 session 键：captcha_{type}（core/class/captcha.php）
     * 兼容统一生成器的 session 键：captcha_code（api/captcha/generate.php）
     * 同时兼容默认类型键：captcha_default
     *
     * 验证成功后会清理相关 session 键，防止验证码被重复使用。
     *
     * @param string $user_code 用户输入的验证码
     * @param string|null $type 可选的验证码类型（如 users_login, users_mobile_login 等）
     * @return bool 验证结果
     */
    function todo_verify_captcha($user_code, $type = null) {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        // 如果滑块验证码已验证通过，则直接返回真，并清理状态
        if (isset($_SESSION['todo_captcha_verified']) && $_SESSION['todo_captcha_verified'] === true) {
            unset($_SESSION['todo_captcha_verified']);
            return true;
        }

        $user_code = trim((string)$user_code);
        if ($user_code === 'slider' || $user_code === 'placeholder') {
            return true;
        }
        if ($user_code === '') {
            return false;
        }

        $stored = null;
        // 1) 如果提供了类型，优先使用类型化键
        if (!empty($type) && isset($_SESSION['captcha_' . $type])) {
            $stored = $_SESSION['captcha_' . $type];
        }

        // 2) 若未找到，再尝试统一键与默认键
        if ($stored === null) {
            if (isset($_SESSION['captcha_code'])) {
                $stored = $_SESSION['captcha_code'];
            } elseif (isset($_SESSION['captcha_default'])) {
                $stored = $_SESSION['captcha_default'];
            }
        }

        if ($stored === null) {
            return false;
        }

        $valid = (strtolower($user_code) === strtolower($stored));

        // 验证后清理，避免重复使用
        unset($_SESSION['captcha_code'], $_SESSION['captcha_time']);
        if (!empty($type)) {
            unset($_SESSION['captcha_' . $type]);
        } else {
            unset($_SESSION['captcha_default']);
        }

        return $valid;
    }
}

if (!function_exists('todo_verify_sms_code')) {
    function todo_verify_sms_code($mobile, $code, $type = 'register')
    {
        $mobile = trim((string) $mobile);
        $code = trim((string) $code);
        $type = trim((string) $type);

        if ($mobile === '' || $code === '' || $type === '') {
            return false;
        }

        global $tododb;
        if (!$tododb && function_exists('get_database_connection')) {
            $tododb = get_database_connection();
        }
        if (!$tododb) {
            return false;
        }

        $table = $tododb->prefix . 'sms_codes';
        $row = $tododb->get_row(
            "SELECT * FROM {$table} 
             WHERE mobile = ? AND code = ? AND type = ? AND used = 0 AND expires_at > NOW() 
             ORDER BY created_at DESC LIMIT 1",
            [$mobile, $code, $type]
        );

        if (!$row) {
            return false;
        }

        $tododb->update(
            $table,
            ['used' => 1, 'used_at' => function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s')],
            ['id' => $row->id]
        );

        return true;
    }
}

/**
 * 注册登录尝试
 * 
 * @param string $username 用户名
 * @param string $ip IP地址
 * @param bool $success 是否成功
 */
if (!function_exists('todo_register_login_attempt')) {
    function todo_register_login_attempt($username, $ip, $success) {
        global $tododb;
        if (!$tododb) return;
        
        $tododb->insert(
            "{$tododb->prefix}login_attempts",
            [
                'login_name' => (string)$username,
                'ip_address' => (string)$ip,
                'success' => $success ? 1 : 0,
                'attempt_time' => current_time('mysql')
            ]
        );
    }
}

/**
 * 注册验证码尝试与刷新记录
 * 
 * @param string $ip IP地址
 * @param bool $success 是否成功
 * @param string $type 尝试类型：'verify' (验证) 或 'refresh' (刷新/获取)
 */
if (!function_exists('todo_register_captcha_attempt')) {
    function todo_register_captcha_attempt($ip, $success, $type = 'verify') {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $now = time();
        $ip_key = md5((string)$ip);

        // 1. Session 级别防抖与计数
        if ($type === 'refresh') {
            $_SESSION['captcha_last_refresh_time'] = $now;
            $_SESSION['captcha_refresh_count'] = ($_SESSION['captcha_refresh_count'] ?? 0) + 1;
        } elseif ($type === 'verify') {
            if (!$success) {
                $_SESSION['captcha_fail_count'] = ($_SESSION['captcha_fail_count'] ?? 0) + 1;
                $_SESSION['captcha_last_fail_time'] = $now;
            } else {
                $_SESSION['captcha_fail_count'] = 0;
            }
        }

        // 2. 数据库落盘记录（用于分布式/跨Session防刷与锁定）
        global $tododb;
        if (!$tododb) return;

        $login_name = ($type === 'refresh') ? '__CAPTCHA_REFRESH__' : '__CAPTCHA__';
        $tododb->insert(
            "{$tododb->prefix}login_attempts",
            [
                'login_name'   => $login_name,
                'ip_address'   => (string)$ip,
                'success'      => $success ? 1 : 0,
                'attempt_time' => current_time('mysql')
            ]
        );
    }
}

/**
 * 检查登录锁定状态
 * 
 * @param string $username 用户名
 * @param string $ip IP地址
 * @return array|bool 如果被锁定返回锁定信息数组，否则返回false
 */
if (!function_exists('todo_check_login_lockout')) {
    function todo_check_login_lockout($username, $ip) {
        global $tododb;
        if (!$tododb) return false;
        
        // 获取最近24小时内的失败尝试次数，以支持更长的锁定时间
        $one_day_ago = date('Y-m-d H:i:s', time() - 86400);
        $results = $tododb->get_results(
            "SELECT success, attempt_time FROM {$tododb->prefix}login_attempts 
             WHERE (login_name = ? OR ip_address = ?) 
             AND attempt_time > ? 
             ORDER BY attempt_time DESC",
            [$username, $ip, $one_day_ago]
        );
        
        if (empty($results)) {
            return false;
        }
        
        $consecutive_failures = 0;
        foreach ($results as $attempt) {
            if ($attempt->success == 1) {
                break; // 如果有成功登录，则重置连续失败计数
            }
            $consecutive_failures++;
        }
        
        if ($consecutive_failures < 5) {
            return false;
        }
        
        // 梯度锁定逻辑
        $last_attempt_time = strtotime($results[0]->attempt_time);
        $now = time();
        $wait_time = 0;
        
        if ($consecutive_failures >= 20) {
            $wait_time = 3600; // 1小时
        } elseif ($consecutive_failures >= 15) {
            $wait_time = 900; // 15分钟
        } elseif ($consecutive_failures >= 10) {
            $wait_time = 300; // 5分钟
        } elseif ($consecutive_failures >= 5) {
            $wait_time = 60; // 1分钟
        }
        
        $elapsed = $now - $last_attempt_time;
        if ($elapsed < $wait_time) {
            return [
                'locked' => true,
                'wait_seconds' => $wait_time - $elapsed,
                'wait_minutes' => ceil(($wait_time - $elapsed) / 60),
                'consecutive_failures' => $consecutive_failures
            ];
        }
        
        return false;
    }
}

/**
 * 检查验证码锁定状态（针对连续验证失败）
 * 
 * @param string $ip IP地址
 * @return array|bool 如果被锁定返回锁定信息，否则返回false
 */
if (!function_exists('todo_check_captcha_lockout')) {
    function todo_check_captcha_lockout($ip) {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $now = time();

        // 1. Session 级快速检查
        $fail_count = $_SESSION['captcha_fail_count'] ?? 0;
        $last_fail = $_SESSION['captcha_last_fail_time'] ?? 0;
        if ($fail_count >= 5 && ($now - $last_fail) < 60) {
            $wait = 60 - ($now - $last_fail);
            return [
                'locked' => true,
                'wait_seconds' => $wait,
                'wait_minutes' => ceil($wait / 60),
                'consecutive_failures' => $fail_count,
                'message' => sprintf(__('连续验证失败过多，%d 秒后再试'), $wait)
            ];
        }

        // 2. 数据库落盘多设备/IP级检查
        global $tododb;
        if (!$tododb) return false;
        
        $one_day_ago = date('Y-m-d H:i:s', time() - 86400);
        $results = $tododb->get_results(
            "SELECT success, attempt_time FROM {$tododb->prefix}login_attempts 
             WHERE login_name = '__CAPTCHA__' AND ip_address = ? 
             AND attempt_time > ? 
             ORDER BY attempt_time DESC",
            [$ip, $one_day_ago]
        );
        
        if (empty($results)) return false;
        
        $consecutive_failures = 0;
        foreach ($results as $attempt) {
            if ($attempt->success == 1) break;
            $consecutive_failures++;
        }
        
        if ($consecutive_failures < 5) return false;
        
        $last_attempt_time = strtotime($results[0]->attempt_time);
        $wait_time = 0;
        
        if ($consecutive_failures >= 20) $wait_time = 3600;
        elseif ($consecutive_failures >= 15) $wait_time = 900;
        elseif ($consecutive_failures >= 10) $wait_time = 300;
        elseif ($consecutive_failures >= 5) $wait_time = 60;
        
        $elapsed = $now - $last_attempt_time;
        if ($elapsed < $wait_time) {
            return [
                'locked' => true,
                'wait_seconds' => $wait_time - $elapsed,
                'wait_minutes' => ceil(($wait_time - $elapsed) / 60),
                'consecutive_failures' => $consecutive_failures,
                'message' => sprintf(__('验证失败过多，%d 分钟后再试'), ceil(($wait_time - $elapsed) / 60))
            ];
        }
        return false;
    }
}

/**
 * 检查验证码刷新限制 (防抖与频控)
 * 
 * @param string $ip IP地址
 * @return array|bool 如果达到限制返回信息，否则返回false
 */
if (!function_exists('todo_check_captcha_refresh_lockout')) {
    function todo_check_captcha_refresh_lockout($ip) {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $now = time();

        // 1. 单次刷新硬防抖 (1 秒内禁止连续刷新)
        $last_refresh = $_SESSION['captcha_last_refresh_time'] ?? 0;
        if ($last_refresh > 0 && ($now - $last_refresh) < 1) {
            return [
                'locked' => true,
                'wait_seconds' => 1,
                'wait_minutes' => 1,
                'message' => __('刷新太频繁')
            ];
        }

        // 2. 数据库落盘限制：10分钟内最多刷新 40 次
        global $tododb;
        if (!$tododb) return false;

        $window = 600;
        $max_refreshes = 40;
        $time_threshold = date('Y-m-d H:i:s', $now - $window);
        
        $count = $tododb->get_var(
            "SELECT COUNT(*) FROM {$tododb->prefix}login_attempts 
             WHERE login_name = '__CAPTCHA_REFRESH__' AND ip_address = ? 
             AND attempt_time > ?",
            [$ip, $time_threshold]
        );

        if ($count >= $max_refreshes) {
            $last_time = $tododb->get_var(
                "SELECT attempt_time FROM {$tododb->prefix}login_attempts 
                 WHERE login_name = '__CAPTCHA_REFRESH__' AND ip_address = ? 
                 ORDER BY attempt_time DESC LIMIT 1",
                [$ip]
            );
            $elapsed = $now - strtotime($last_time);
            $wait = $window - $elapsed;
            
            if ($wait > 0) {
                return [
                    'locked' => true,
                    'wait_seconds' => $wait,
                    'wait_minutes' => ceil($wait / 60),
                    'message' => __('验证码生成过于频繁，请稍后再试')
                ];
            }
        }
        return false;
    }
}

/**
 * 清理过期的日志、缓存和统计数据记录
 * 
 * 全面审查并清理系统各模块可能产生大量冗余数据的表，
 * 防止数据库因日志积压导致存储空间耗尽。
 */
if (!function_exists('todo_prune_old_logs')) {
    function todo_prune_old_logs() {
        global $tododb;
        if (!$tododb) return;

        // 检查上次执行时间，每天执行一次
        $last_prune = (int)get_option('todo_last_log_prune', 0);
        if (php_sapi_name() !== 'cli' && (time() - $last_prune < 86400)) {
            return;
        }

        // 定义需要清理的表及其保留策略
        $cleanup_configs = [
            'login_attempts'       => ['col' => 'attempt_time', 'days' => 7],   // 登录尝试记录 (7天)
            'activity_log'         => ['col' => 'created_at',   'days' => 30],  // 系统活动日志 (30天)
            'user_login_logs'      => ['col' => 'created_at',   'days' => 30],  // 用户登录详细日志 (30天)
            'user_operation_logs'  => ['col' => 'created_at',   'days' => 90],  // 用户操作审计日志 (90天)
            'email_logs'           => ['col' => 'created_at',   'days' => 30],  // 邮件发送记录 (30天)
            'email_codes'          => ['col' => 'expires_at',   'days' => 7],   // 邮件验证码 (过期7天后清理)
            'sms_codes'            => ['col' => 'expires_at',   'days' => 7],   // 短信验证码 (过期7天后清理)
            'password_reset_codes' => ['col' => 'expires_at',   'days' => 7],   // 密码重置码 (过期7天后清理)
            'visit_stats'          => ['col' => 'visit_time',   'days' => 14],  // 访问统计原始数据 (14天)
            'search_logs'          => ['col' => 'created_at',   'days' => 30],  // 搜索记录 (30天)
            'download_logs'        => ['col' => 'created_at',   'days' => 30],  // 下载记录 (30天)
            'language_cache'       => ['col' => 'expires_at',   'days' => 0],   // 语言缓存 (仅清理已过期)
            'user_sessions'        => ['col' => 'expires_at',   'days' => 0],   // 用户会话 (仅清理已过期)
        ];

        try {
            foreach ($cleanup_configs as $table => $config) {
                $full_table = $tododb->prefix . $table;
                
                // 检查表是否存在
                $table_exists = $tododb->get_var("SHOW TABLES LIKE '{$full_table}'");
                if (!$table_exists) continue;

                $time_col = $config['col'];
                $days = $config['days'];
                
                if ($days > 0) {
                    $threshold = date('Y-m-d H:i:s', time() - ($days * 86400));
                    $tododb->query(
                        "DELETE FROM `{$full_table}` WHERE `{$time_col}` < ?",
                        [$threshold]
                    );
                } else {
                    // 仅清理已过期的数据
                    $now = date('Y-m-d H:i:s');
                    $tododb->query(
                        "DELETE FROM `{$full_table}` WHERE `{$time_col}` < ?",
                        [$now]
                    );
                }
            }

            // 更新清理标记
            update_option('todo_last_log_prune', time());
            
        } catch (Exception $e) {
            error_log('TodoCMS Comprehensive Log Pruning Error: ' . $e->getMessage());
        }
    }
}

// 挂载到后台初始化钩子，确保每天自动清理
if (function_exists('add_action')) {
    add_action('admin_init', 'todo_prune_old_logs');
}

