<?php
/**
 * TodoCMS 会话管理器
 * 
 * 管理前后台独立的会话系统，确保后台用户和前台用户完全隔离
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

class Todo_Session_Manager {
    
    /**
     * 会话类型常量
     */
    const SESSION_TYPE_FRONTEND = 'frontend'; // 前台会话
    const FRONTEND_SESSION_NAME = 'TODOCMS_FRONTENDSESSID';
    
    /**
     * 会话键名
     */
    const KEY_ADMIN_USER_ID = 'admin_user_id';
    const KEY_FRONTEND_USER_ID = 'frontend_user_id';
    const KEY_ADMIN_TOKEN = 'admin_auth_token';
    const KEY_FRONTEND_TOKEN = 'frontend_auth_token';
    const KEY_ADMIN_ROLE = 'admin_user_role';
    const KEY_FRONTEND_ROLE = 'frontend_user_role';
    const KEY_ADMIN_LOGIN_TIME = 'admin_login_time';
    const KEY_FRONTEND_LOGIN_TIME = 'frontend_login_time';
    
    /**
     * Cookie 名称
     */
    const COOKIE_ADMIN_TOKEN = 'todo_admin_auth';
    const COOKIE_FRONTEND_TOKEN = 'todo_frontend_auth';
    
    /**
     * 数据库连接
     * @var CMS_DB
     */
    private static $tododb;
    
    /**
     * 原始的 admin session name
     */
    private static $original_session_name = null;
    
    /**
     * 初始化会话管理器
     */
    public static function init() {
        global $tododb;
        self::$tododb = $tododb;
        
        if (self::$original_session_name === null) {
            self::$original_session_name = (string) ini_get('session.name') ?: 'PHPSESSID';
        }

        // 如果全局数据库连接不可用，尝试获取
        if (!self::$tododb && function_exists('get_database_connection')) {
            self::$tododb = get_database_connection();
        }
        
        self::ensure_session_started();
    }

    /**
     * 根据当前上下文启动正确的会话
     *
     * - 后台：使用系统默认会话名（通常为 PHPSESSID）
     * - 前台：使用独立会话名，避免与后台互相覆盖
     */
    private static function ensure_session_started() {
        $is_admin = (defined('IS_ADMIN') && IS_ADMIN) || (defined('TODO_ADMIN') && TODO_ADMIN);
        if (!$is_admin) {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            $path = (string) parse_url((string) $request_uri, PHP_URL_PATH);
            if ($path !== '') {
                if (strpos($path, '/admin/') !== false || preg_match('#^/admin(?:/|$)#', $path) === 1) {
                    $is_admin = true;
                }
            }
        }
        $desired_session_name = $is_admin ? (string) ini_get('session.name') : self::FRONTEND_SESSION_NAME;

        if ($desired_session_name === '') {
            $desired_session_name = $is_admin ? 'PHPSESSID' : self::FRONTEND_SESSION_NAME;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            if (session_name() !== $desired_session_name) {
                session_write_close();
                session_name($desired_session_name);
                // PHP does not automatically re-read the session ID from the new cookie if session_id is already set
                if (isset($_COOKIE[$desired_session_name])) {
                    session_id($_COOKIE[$desired_session_name]);
                } else {
                    // We must clear the session_id so PHP generates a new one or reads properly
                    session_id(session_create_id()); 
                }
                @session_start();
            }
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_name($desired_session_name);
            @session_start();
        }
    }

    private static function get_admin_session_name() {
        return self::$original_session_name ?: 'PHPSESSID';
    }

    private static function start_session_with_name($session_name) {
        $session_name = (string) $session_name;
        if ($session_name === '') {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            if (session_name() !== $session_name) {
                session_write_close();
                session_name($session_name);
                if (isset($_COOKIE[$session_name])) {
                    session_id($_COOKIE[$session_name]);
                } else {
                    session_id(session_create_id());
                }
                @session_start();
            }
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_name($session_name);
            @session_start();
        }
    }

    private static function with_session_name($session_name, $callback) {
        $original_status = session_status();
        $original_name = $original_status === PHP_SESSION_ACTIVE ? session_name() : null;

        self::start_session_with_name($session_name);
        $result = null;
        try {
            $result = is_callable($callback) ? $callback() : null;
        } catch (Exception $e) {
            if ($original_status === PHP_SESSION_ACTIVE) {
                @session_write_close();
                self::start_session_with_name($original_name);
            } else {
                @session_write_close();
            }
            throw $e;
        }

        @session_write_close();
        if ($original_status === PHP_SESSION_ACTIVE && $original_name) {
            self::start_session_with_name($original_name);
        }

        return $result;
    }
    
    /**
     * 设置后台用户会话
     * 
     * @param int $user_id 用户ID
     * @param string $role 用户角色
     * @param bool $remember 是否记住登录
     * @return bool
     */
    public static function set_admin_session($user_id, $role = '', $remember = false) {
        self::ensure_session_started();
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
        
        // 设置后台会话
        $_SESSION[self::KEY_ADMIN_USER_ID] = (int) $user_id;
        $_SESSION[self::KEY_ADMIN_ROLE] = $role;
        $_SESSION[self::KEY_ADMIN_LOGIN_TIME] = time();
        
        // 生成后台认证令牌
        $token = self::generate_token($user_id, 'admin');
        $_SESSION[self::KEY_ADMIN_TOKEN] = $token;
        
        // 记录会话到数据库
        self::record_session($user_id, 'admin');
        
        // 如果记住登录，设置Cookie
        if ($remember) {
            $expire = time() + (30 * 24 * 60 * 60); // 30天
            setcookie(self::COOKIE_ADMIN_TOKEN, $token, $expire, '/', '', false, true);
        }

        if (function_exists('todo_cleanup_default_admin_login_file')) {
            todo_cleanup_default_admin_login_file();
        } else if (function_exists('get_option')) {
            $root_dir = defined('ABSPATH') ? ABSPATH : dirname(__DIR__, 2) . '/';
            $install_file = rtrim($root_dir, '/\\') . '/install.php';
            if (file_exists($install_file)) {
                $random_suffix = substr(md5(time() . mt_rand(1000, 9999)), 0, 17);
                @rename($install_file, rtrim($root_dir, '/\\') . '/install_' . $random_suffix . '.php');
            }

            $admin_base = (string) get_option('site_adminbasefile', '');
            if (!empty($admin_base) && $admin_base !== 'todo-login') {
                $admin_dir = defined('ADMIN_DIR') ? ADMIN_DIR : (defined('ABSPATH') ? ABSPATH . 'admin' : dirname(__DIR__, 2) . '/admin');
                $admin_dir = rtrim($admin_dir, '/\\');
                $new_entry_file = $admin_dir . '/' . $admin_base . '.php';
                $default_file = $admin_dir . '/todo-login.php';
                if (file_exists($new_entry_file) && is_readable($new_entry_file) && file_exists($default_file)) {
                    @unlink($default_file);
                }
            }
        }
        
        return true;
    }
    
    /**
     * 设置前台用户会话
     * 
     * @param int $user_id 用户ID
     * @param string $role 用户角色
     * @param bool $remember 是否记住登录
     * @return bool
     */
    public static function set_frontend_session($user_id, $role = '', $remember = false) {
        self::ensure_session_started();
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
        
        $is_impersonate = (!empty($_COOKIE['todo_impersonate']) || !empty($_SESSION['is_impersonating']));

        // 设置前台会话
        $_SESSION[self::KEY_FRONTEND_USER_ID] = (int) $user_id;
        $_SESSION[self::KEY_FRONTEND_ROLE] = $role;
        
        if ($is_impersonate) {
            $_SESSION['is_impersonating'] = true;
            // 模拟查看时不覆盖登录时间，尽量保留用户原有的最后登录时间
            if (!isset($_SESSION[self::KEY_FRONTEND_LOGIN_TIME])) {
                $orig_login = function_exists('get_user_meta') ? (get_user_meta($user_id, 'last_login', true) ?: get_user_meta($user_id, '_last_login', true)) : '';
                if ($orig_login) {
                    $_SESSION[self::KEY_FRONTEND_LOGIN_TIME] = is_numeric($orig_login) ? (int)$orig_login : strtotime($orig_login);
                }
            }
        } else {
            $_SESSION[self::KEY_FRONTEND_LOGIN_TIME] = time();
        }
        
        // 生成前台认证令牌
        $token = self::generate_token($user_id, self::SESSION_TYPE_FRONTEND);
        $_SESSION[self::KEY_FRONTEND_TOKEN] = $token;
        
        // 模拟查看时不写入用户会话表，不执行游客订单绑定，做到不留痕迹
        if (!$is_impersonate) {
            // 记录会话到数据库
            self::record_session($user_id, self::SESSION_TYPE_FRONTEND);
            
            // 国际标准：登录/注册时自动将游客历史订单平滑合并归款至该用户名下
            if (function_exists('todo_claim_and_bind_guest_orders')) {
                todo_claim_and_bind_guest_orders($user_id);
            }
        }

        // 如果记住登录，设置Cookie（模拟登录时不设置永久Cookie）
        if ($remember && !$is_impersonate) {
            $expire = time() + (30 * 24 * 60 * 60); // 30天
            setcookie(self::COOKIE_FRONTEND_TOKEN, $token, $expire, '/', '', false, true);
        }
        
        return true;
    }
    
    /**
     * 获取后台用户ID
     * 
     * @return int|null
     */
    public static function get_admin_user_id() {
        self::ensure_session_started();
        return self::get_admin_user_id_from_current_session();
    }

    public static function get_admin_user_id_any_context() {
        return self::with_session_name(self::get_admin_session_name(), function () {
            return self::get_admin_user_id_from_current_session();
        });
    }

    private static function get_admin_user_id_from_current_session() {
        if (isset($_SESSION[self::KEY_ADMIN_USER_ID])) {
            $user_id = (int) $_SESSION[self::KEY_ADMIN_USER_ID];
            if (self::validate_admin_session($user_id)) {
                return $user_id;
            }
            self::clear_admin_session();
        }

        if (isset($_COOKIE[self::COOKIE_ADMIN_TOKEN])) {
            $token = $_COOKIE[self::COOKIE_ADMIN_TOKEN];
            $user_id = self::validate_token($token, 'admin');
            if ($user_id) {
                $role = self::get_user_role($user_id);
                self::set_admin_session($user_id, $role, true);
                return $user_id;
            }
        }

        if (defined('TODO_DEMO_ADMIN_ENABLED') && TODO_DEMO_ADMIN_ENABLED) {
            if (isset($_SESSION['todo_demo_admin']) && (int) $_SESSION['todo_demo_admin'] === 1 && isset($_SESSION['todo_demo_admin_uid'])) {
                return (int) $_SESSION['todo_demo_admin_uid'];
            }
        }

        return null;
    }
    
    /**
     * 获取前台用户ID
     * 
     * @return int|null
     */
    public static function get_frontend_user_id() {
        self::ensure_session_started();
        return self::get_frontend_user_id_from_current_session();
    }

    private static function get_frontend_user_id_from_current_session() {
        if (isset($_SESSION[self::KEY_FRONTEND_USER_ID])) {
            $user_id = (int) $_SESSION[self::KEY_FRONTEND_USER_ID];
            if (self::validate_frontend_session($user_id)) {
                return $user_id;
            }
            self::clear_frontend_session();
        }

        if (isset($_COOKIE[self::COOKIE_FRONTEND_TOKEN])) {
            $token = $_COOKIE[self::COOKIE_FRONTEND_TOKEN];
            $user_id = self::validate_token($token, self::SESSION_TYPE_FRONTEND);
            if ($user_id) {
                $role = self::get_user_role($user_id);
                self::set_frontend_session($user_id, $role, true);
                return $user_id;
            }
        }

        return null;
    }
    
    /**
     * 检查是否已登录后台
     * 
     * @return bool
     */
    public static function is_admin_logged_in() {
        return self::get_admin_user_id() !== null;
    }
    
    /**
     * 检查是否已登录前台
     * 
     * @return bool
     */
    public static function is_frontend_logged_in() {
        return self::get_frontend_user_id() !== null;
    }
    
    /**
     * 清除后台会话
     */
    public static function clear_admin_session() {
        self::ensure_session_started();
        $admin_user_id = isset($_SESSION[self::KEY_ADMIN_USER_ID]) ? (int) $_SESSION[self::KEY_ADMIN_USER_ID] : null;

        unset($_SESSION[self::KEY_ADMIN_USER_ID]);
        unset($_SESSION[self::KEY_ADMIN_ROLE]);
        unset($_SESSION[self::KEY_ADMIN_TOKEN]);
        unset($_SESSION[self::KEY_ADMIN_LOGIN_TIME]);
        
        // 清除Cookie
        if (isset($_COOKIE[self::COOKIE_ADMIN_TOKEN])) {
            setcookie(self::COOKIE_ADMIN_TOKEN, '', time() - 3600, '/', '', false, true);
        }
        
        // 删除数据库会话记录
        if (self::$tododb && $admin_user_id) {
            $session_id = session_id();
            if ($session_id) {
                try {
                    $prefix = self::$tododb->prefix;
                    self::$tododb->delete($prefix . 'user_sessions', array(
                        'session_id' => $session_id,
                        'user_id' => $admin_user_id
                    ));
                } catch (Exception $e) {
                    error_log('Failed to delete admin session: ' . $e->getMessage());
                }
            }
        }
    }
    
    /**
     * 清除前台会话
     */
    public static function clear_frontend_session() {
        self::ensure_session_started();
        $frontend_user_id = isset($_SESSION[self::KEY_FRONTEND_USER_ID]) ? (int) $_SESSION[self::KEY_FRONTEND_USER_ID] : null;

        unset($_SESSION[self::KEY_FRONTEND_USER_ID]);
        unset($_SESSION[self::KEY_FRONTEND_ROLE]);
        unset($_SESSION[self::KEY_FRONTEND_TOKEN]);
        unset($_SESSION[self::KEY_FRONTEND_LOGIN_TIME]);
        
        // 清除Cookie
        if (isset($_COOKIE[self::COOKIE_FRONTEND_TOKEN])) {
            setcookie(self::COOKIE_FRONTEND_TOKEN, '', time() - 3600, '/', '', false, true);
        }
        
        // 删除数据库会话记录
        if (self::$tododb && $frontend_user_id) {
            $session_id = session_id();
            if ($session_id) {
                try {
                    $prefix = self::$tododb->prefix;
                    self::$tododb->delete($prefix . 'user_sessions', array(
                        'session_id' => $session_id,
                        'user_id' => $frontend_user_id
                    ));
                } catch (Exception $e) {
                    error_log('Failed to delete frontend session: ' . $e->getMessage());
                }
            }
        }
    }
    
    /**
     * 验证后台会话
     * 
     * @param int $user_id 用户ID
     * @return bool
     */
    private static function validate_admin_session($user_id) {
        if (!self::$tododb) {
            // 如果数据库连接不可用，但会话变量存在，允许继续（可能是数据库临时问题）
            return true;
        }
        
        $session_id = session_id();
        if (!$session_id) {
            return false;
        }
        
        try {
            $prefix = self::$tododb->prefix;
            $count = (int) self::$tododb->get_var(
                "SELECT COUNT(*) FROM {$prefix}user_sessions WHERE session_id = ? AND user_id = ?",
                [$session_id, $user_id]
            );
            
            return $count > 0;
        } catch (Exception $e) {
            error_log('Todo_Session_Manager::validate_admin_session: 验证异常 - ' . $e->getMessage() . ', session_id=' . $session_id . ', user_id=' . $user_id);
            // 如果验证异常，但会话变量存在，允许继续（可能是表不存在或查询失败）
            return true;
        }
    }
    
    /**
     * 验证前台会话
     * 
     * @param int $user_id 用户ID
     * @return bool
     */
    private static function validate_frontend_session($user_id) {
        if (!empty($_SESSION[self::KEY_FRONTEND_USER_ID]) && (int)$_SESSION[self::KEY_FRONTEND_USER_ID] === (int)$user_id) {
            return true;
        }
        if (!self::$tododb) {
            return false;
        }
        
        $session_id = session_id();
        if (!$session_id) {
            return false;
        }
        
        try {
            $prefix = self::$tododb->prefix;
            $count = (int) self::$tododb->get_var(
                "SELECT COUNT(*) FROM {$prefix}user_sessions WHERE session_id = ? AND user_id = ?",
                [$session_id, $user_id]
            );
            
            return $count > 0;
        } catch (Exception $e) {
            error_log('Session validation error: ' . $e->getMessage());
            return true;
        }
    }
    
    /**
     * 验证令牌
     * 
     * @param string $token 令牌
     * @param string $type 会话类型
     * @return int|null 用户ID或null
     */
    private static function validate_token($token, $type) {
        if (!self::$tododb || empty($token)) {
            return null;
        }
        
        try {
            $prefix = self::$tododb->prefix;
            
            // 检查remember_tokens表
            $table_exists = false;
            try {
                $table_exists = (bool) self::$tododb->get_var(
                    "SHOW TABLES LIKE '{$prefix}remember_tokens'"
                );
            } catch (Exception $e) {
                // 表不存在
            }
            
            if ($table_exists) {
                $row = self::$tododb->get_row(
                    "SELECT user_id FROM {$prefix}remember_tokens WHERE token = ? AND expires_at > NOW()",
                    [$token]
                );
                
                if ($row) {
                    return (int) $row->user_id;
                }
            }
        } catch (Exception $e) {
            error_log("TodoCMS Error [Session]: Token验证失败 - " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * 生成令牌
     * 
     * @param int $user_id 用户ID
     * @param string $type 会话类型
     * @return string
     */
    private static function generate_token($user_id, $type) {
        $data = $user_id . '|' . $type . '|' . time() . '|' . uniqid();
        return hash('sha256', $data);
    }
    
    /**
     * 记录会话到数据库
     * 
     * @param int $user_id 用户ID
     * @param string $type 会话类型
     */
    private static function record_session($user_id, $type) {
        if (!empty($_COOKIE['todo_impersonate']) || !empty($_SESSION['is_impersonating'])) {
            return;
        }

        if (!self::$tododb) {
            error_log('Todo_Session_Manager::record_session: 数据库连接不可用');
            return;
        }
        
        $session_id = session_id();
        if (!$session_id) {
            error_log('Todo_Session_Manager::record_session: 会话ID不可用');
            return;
        }
        
        try {
            $prefix = self::$tododb->prefix;
            $ip_address = function_exists('todo_get_client_ip') ? todo_get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $expires_at = date('Y-m-d H:i:s', time() + (int) ini_get('session.gc_maxlifetime'));
            
            // 先删除相同session_id的旧记录
            self::$tododb->delete($prefix . 'user_sessions', array('session_id' => $session_id));
            
            // 插入新记录
            $result = self::$tododb->insert($prefix . 'user_sessions', array(
                'user_id' => $user_id,
                'session_id' => $session_id,
                'ip_address' => $ip_address,
                'user_agent' => $user_agent,
                'expires_at' => $expires_at,
                'created_at' => date('Y-m-d H:i:s')
            ));
            
            // insert() 返回 false 表示失败，返回 0 或正整数表示成功（0 可能是表没有自增ID）
            if ($result === false) {
                // 检查数据库错误
                $error = '';
                if (method_exists(self::$tododb, 'last_error')) {
                    $error = self::$tododb->last_error();
                }
                error_log('Todo_Session_Manager::record_session: 插入会话记录失败，user_id=' . $user_id . ', session_id=' . $session_id . ', error=' . $error);
            }
        } catch (Exception $e) {
            error_log('Todo_Session_Manager::record_session: 异常 - ' . $e->getMessage() . ', user_id=' . $user_id . ', session_id=' . $session_id);
        }
    }
    
    /**
     * 获取用户角色
     * 
     * @param int $user_id 用户ID
     * @return string
     */
    private static function get_user_role($user_id) {
        if (!self::$tododb) {
            return 'subscriber';
        }
        
        try {
            $prefix = self::$tododb->prefix;
            
            // 尝试从user_meta获取角色
            $role_meta = self::$tododb->get_var(
                "SELECT meta_value FROM {$prefix}user_meta WHERE user_id = ? AND meta_key = 'roles'",
                [$user_id]
            );
            
            if ($role_meta) {
                $roles = maybe_unserialize($role_meta);
                if (is_array($roles) && !empty($roles)) {
                    return reset($roles);
                } elseif (is_string($roles)) {
                    return $roles;
                }
            }
            
            // 尝试从users表获取角色
            $user = self::$tododb->get_row(
                "SELECT role FROM {$prefix}users WHERE id = ?",
                [$user_id]
            );
            
            if ($user && !empty($user->role)) {
                return $user->role;
            }
        } catch (Exception $e) {
            error_log('Failed to get user role: ' . $e->getMessage());
        }
        
        return 'subscriber';
    }
    
    /**
     * 检查用户是否有后台访问权限
     * 
     * @param int $user_id 用户ID
     * @return bool
     */
    public static function can_access_admin($user_id) {
        $role = self::get_user_role($user_id);
        $admin_roles = ['super_admin', 'admin', 'administrator'];
        return in_array(strtolower($role), $admin_roles);
    }
    
    /**
     * 检查用户是否有前台访问权限
     * 
     * @param int $user_id 用户ID
     * @return bool
     */
    public static function can_access_frontend($user_id) {
        // 所有用户都可以访问前台（除非被禁用）
        if (!self::$tododb) {
            return true;
        }
        
        try {
            $prefix = self::$tododb->prefix;
            $status = (int) self::$tododb->get_var(
                "SELECT status FROM {$prefix}users WHERE id = ?",
                [$user_id]
            );
            
            // status = 1 表示激活状态
            return $status === 1;
        } catch (Exception $e) {
            error_log('Failed to check frontend access: ' . $e->getMessage());
            return false;
        }
    }
}
