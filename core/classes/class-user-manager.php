<?php
/**
 * TodoCMS 用户管理器
 * 
 * 管理用户系统、权限管理和会员中心功能
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    exit('Direct access not permitted');
}

class Todo_User_Manager {
    
    /**
     * 数据库连接
     * @var CMS_DB
     */
    private static $tododb;
    
    /**
     * 用户角色常量
     */
    const ROLE_SUPER_ADMIN = 'super_admin';     // 超级管理员
    const ROLE_ADMIN = 'admin';                 // 管理员
    const ROLE_EDITOR = 'editor';               // 编辑者
    const ROLE_AUTHOR = 'author';               // 作者
    const ROLE_SUBSCRIBER = 'subscriber';       // 订阅者
    const ROLE_MEMBER = 'member';               // 会员
    const ROLE_VIP = 'vip';                     // VIP会员
    const ROLE_GUEST = 'guest';                 // 游客
    
    /**
     * 用户状态常量
     */
    const STATUS_ACTIVE = 1;                    // 激活
    const STATUS_INACTIVE = 0;                  // 未激活
    const STATUS_BANNED = -1;                   // 禁用
    const STATUS_PENDING = 2;                   // 待审核
    
    /**
     * 用户缓存
     * @var array
     */
    private static $users_cache = array();
    
    /**
     * 权限缓存
     * @var array
     */
    private static $permissions_cache = array();
    
    /**
     * 当前用户
     * @var array|null
     */
    private static $current_user = null;

    public static function flush_permissions_cache() {
        self::$permissions_cache = array();
    }
    
    /**
     * 批量预加载用户缓存
     * 
     * @param array $user_ids 用户ID数组
     * @return void
     */
    public static function prime_cache($user_ids) {
        if (empty($user_ids)) {
            return;
        }
        
        // 确保数据库连接已初始化
        if (self::$tododb === null) {
            global $tododb;
            self::$tododb = $tododb;
        }
        
        $user_ids = array_unique(array_filter(array_map('intval', $user_ids)));
        $uncached_ids = array();
        foreach ($user_ids as $id) {
            if (!isset(self::$users_cache[$id])) {
                $uncached_ids[] = $id;
            }
        }
        
        if (empty($uncached_ids)) {
            return;
        }
        
        $placeholders = implode(',', array_fill(0, count($uncached_ids), '?'));
        $query = "SELECT * FROM " . self::$tododb->prefix . "users WHERE id IN ($placeholders)";
        $results = self::$tododb->get_results($query, $uncached_ids);
        
        if ($results) {
            // 批量获取这些用户的元数据
            $meta_query = "SELECT user_id, meta_key, meta_value FROM " . self::$tododb->prefix . "user_meta WHERE user_id IN ($placeholders)";
            $meta_results = self::$tododb->get_results($meta_query, $uncached_ids);
            
            $all_meta = array();
            if ($meta_results) {
                foreach ($meta_results as $m) {
                    $uid = is_object($m) ? $m->user_id : $m['user_id'];
                    $key = is_object($m) ? $m->meta_key : $m['meta_key'];
                    $val = is_object($m) ? $m->meta_value : $m['meta_value'];
                    
                    if (!isset($all_meta[$uid])) {
                        $all_meta[$uid] = array();
                    }
                    
                    // 尝试解码
                    $decoded = json_decode($val, true);
                    if ($decoded !== null) {
                        $all_meta[$uid][$key] = $decoded;
                    } else {
                        if (is_string($val) && (strpos($val, 'a:') === 0 || strpos($val, 'O:') === 0 || strpos($val, 's:') === 0)) {
                            $unserialized = @unserialize($val);
                            $all_meta[$uid][$key] = ($unserialized !== false) ? $unserialized : $val;
                        } else {
                            $all_meta[$uid][$key] = $val;
                        }
                    }
                }
            }
            
            foreach ($results as $row) {
                $row = (array) $row;
                $uid = (int) $row['ID'];
                
                // 移除敏感信息
                unset($row['password']);
                unset($row['activation_code']);
                
                // 设置元数据
                $row['meta'] = isset($all_meta[$uid]) ? $all_meta[$uid] : array();
                
                // 角色处理（逻辑同 get_user）
                if (empty($row['role'])) {
                    if (isset($row['meta']['roles'])) {
                        $roles = $row['meta']['roles'];
                        if (is_array($roles) && !empty($roles)) {
                            $row['role'] = $roles[0];
                        } elseif (is_string($roles)) {
                            $row['role'] = $roles;
                        }
                    }
                    if (empty($row['role']) && isset($row['meta']['capabilities'])) {
                        if (isset($row['meta']['capabilities']['access_admin'])) {
                            $row['role'] = 'administrator';
                        }
                    }
                    if (empty($row['role'])) {
                        $row['role'] = self::ROLE_MEMBER;
                    }
                }
                
                // 归一化角色
                if (isset($row['role'])) {
                    $normalized = strtolower($row['role']);
                    switch ($normalized) {
                        case 'administrator': $row['role'] = self::ROLE_ADMIN; break;
                        case 'superadmin': $row['role'] = self::ROLE_SUPER_ADMIN; break;
                    }
                }
                
                self::$users_cache[$uid] = $row;
            }
        }
    }

    /**
     * 初始化用户管理器
     */
    public static function init() {
        global $tododb;
        self::$tododb = $tododb;
        
        // 启动会话
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // 加载当前用户
        self::load_current_user();
    }
    
    /**
     * 创建用户
     * 
     * @param array $user_data 用户数据
     * @return bool|int 成功返回用户ID，失败返回false
     */
    public static function create_user($user_data) {
        if (empty($user_data['username']) || empty($user_data['email'])) {
            return false;
        }
        
        // 检查用户名和邮箱是否已存在
        if (self::username_exists($user_data['username'])) {
            return false;
        }
        
        if (self::email_exists($user_data['email'])) {
            return false;
        }
        
        // 默认值
        $defaults = array(
            'password' => '',
            'display_name' => $user_data['username'],
            'role' => self::ROLE_MEMBER,
            'status' => self::STATUS_ACTIVE,
            'avatar' => '',
            'phone' => '',
            'gender' => '',
            'birthday' => null,
            'description' => '',
            'website' => '',
            'location' => '',
            'last_login_ip' => '',
            'login_count' => 0
        );
        
        $user_data = array_merge($defaults, $user_data);
        
        // 密码加密
        if (!empty($user_data['password'])) {
            $user_data['password'] = password_hash($user_data['password'], PASSWORD_DEFAULT);
        }
        
        // 生成激活码
        $activation_code = !empty($user_data['activation_code']) ? $user_data['activation_code'] : md5(uniqid(mt_rand(), true));
        
        $data = array(
            'username' => $user_data['username'],
            'email' => $user_data['email'],
            'password' => $user_data['password'],
            'display_name' => $user_data['display_name'],
            'role' => $user_data['role'],
            'status' => $user_data['status'],
            'avatar' => $user_data['avatar'],
            'phone' => $user_data['phone'],
            'gender' => $user_data['gender'],
            'birthday' => $user_data['birthday'],
            'description' => $user_data['description'] ?? '',
            'website' => $user_data['website'],
            'location' => $user_data['location'],
            'activation_code' => $activation_code,
            'created_at' => date('Y-m-d H:i:s')
        );
        
        if (self::$tododb->insert(self::$tododb->prefix . 'users', $data)) {
            $user_id = self::$tododb->insert_id();
            
            // 保存自定义字段
            if (!empty($user_data['custom_fields'])) {
                foreach ($user_data['custom_fields'] as $field_name => $field_value) {
                    self::save_user_meta($user_id, $field_name, $field_value);
                }
            }
            
            return $user_id;
        }
        
        return false;
    }
    
    /**
     * 更新用户
     * 
     * @param int $user_id 用户ID
     * @param array $user_data 用户数据
     * @return bool
     */
    public static function update_user($user_id, $user_data) {
        $set_clauses = array();
        $params = array();
        $types = '';
        
        // 可更新的字段
        $updatable_fields = array(
            'username' => 's',
            'email' => 's',
            'password' => 's',
            'display_name' => 's',
            'role' => 's',
            'status' => 'i',
            'avatar' => 's',
            'phone' => 's',
            'gender' => 's',
            'birthday' => 's',
            'description' => 's',
            'website' => 's',
            'location' => 's'
        );
        
        foreach ($updatable_fields as $field => $type) {
            if (isset($user_data[$field])) {
                // 特殊处理
                if ($field === 'username' && self::username_exists($user_data[$field], $user_id)) {
                    return false;
                }
                
                if ($field === 'email' && self::email_exists($user_data[$field], $user_id)) {
                    return false;
                }
                
                if ($field === 'password' && !empty($user_data[$field])) {
                    $user_data[$field] = password_hash($user_data[$field], PASSWORD_DEFAULT);
                }
                
                $set_clauses[] = "{$field} = ?";
                $params[] = $user_data[$field];
                $types .= $type;
            }
        }
        
        if (empty($set_clauses)) {
            return false;
        }
        
        $set_clauses[] = 'updated_at = NOW()';
        $params[] = $user_id;
        $types .= 'i';
        
        $update_data = array();
        foreach ($set_clauses as $i => $clause) {
            if ($clause !== 'updated_at = NOW()') {
                $field = explode(' = ', $clause)[0];
                $update_data[$field] = $params[$i];
            }
        }
        $update_data['updated_at'] = date('Y-m-d H:i:s');
        
        if (self::$tododb->update(self::$tododb->prefix . 'users', $update_data, array('id' => $user_id))) {
            // 更新自定义字段
            if (!empty($user_data['custom_fields'])) {
                foreach ($user_data['custom_fields'] as $field_name => $field_value) {
                    self::save_user_meta($user_id, $field_name, $field_value);
                }
            }
            
            // 清除缓存
            unset(self::$users_cache[$user_id]);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * 删除用户
     * 
     * @param int $user_id 用户ID
     * @param bool $delete_content 是否删除用户内容
     * @return bool
     */
    public static function delete_user($user_id, $delete_content = false) {
        // 不能删除超级管理员
        $user = self::get_user($user_id);
        if (!$user || $user['role'] === self::ROLE_SUPER_ADMIN) {
            return false;
        }
        
        // 开始事务
        self::$tododb->begin_transaction();
        
        try {
            // 删除用户元数据
            self::$tododb->delete(self::$tododb->prefix . 'user_meta', array('user_id' => $user_id));
            
            // 删除用户会话
            self::$tododb->delete(self::$tododb->prefix . 'user_sessions', array('user_id' => $user_id));
            
            if ($delete_content) {
                // 删除用户创建的内容（根据具体需求实现）
                // 这里可以调用内容管理器来删除用户的所有内容
            } else {
                // 将用户内容转移给管理员或标记为匿名
                // 这里可以实现内容转移逻辑
            }
            
            // 删除用户
            if (!self::$tododb->delete(self::$tododb->prefix . 'users', array('id' => $user_id))) {
                throw new Exception('Failed to delete user');
            }
            
            // 提交事务
            self::$tododb->commit();
            
            // 清除缓存
            unset(self::$users_cache[$user_id]);
            
            return true;
            
        } catch (Exception $e) {
            // 回滚事务
            self::$tododb->rollback();
            todo_log_error('User deletion failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取用户信息
     * 
     * @param int $user_id 用户ID
     * @return array|null
     */
    public static function get_user($user_id) {
        // 确保数据库连接已初始化
        if (self::$tododb === null) {
            global $tododb;
            if ($tododb) {
                self::$tododb = $tododb;
            } else {
                // 如果数据库连接不存在，尝试初始化
                if (function_exists('get_database_connection')) {
                    self::$tododb = get_database_connection();
                } else {
                    error_log("Todo_User_Manager::get_user() 错误: 数据库连接不可用");
                    return null;
                }
            }
        }
        
        if (isset(self::$users_cache[$user_id])) {
            return self::$users_cache[$user_id];
        }
        
        // 直接查询用户数据，如果表不存在会在查询时失败
        // 不预先检查表是否存在，避免 table_exists() 函数可能未加载的问题
        try {
            $row = self::$tododb->get_row("SELECT * FROM " . self::$tododb->prefix . "users WHERE id = ?", [$user_id]);
        } catch (Exception $e) {
            // 如果查询失败（可能是表不存在），记录错误并返回 null
            error_log("Todo_User_Manager::get_user() 查询失败: " . $e->getMessage());
            return null;
        }
        
        if ($row) {
            // 转换对象为数组
            $row = (array) $row;
            
            // 移除敏感信息
            unset($row['password']);
            unset($row['activation_code']);
            
            // 获取用户元数据
            $row['meta'] = self::get_user_meta($user_id);
            
            // 从元数据中提取角色信息并设置到 role 字段
            if (empty($row['role']) || $row['role'] === null) {
                // 尝试从 meta.roles 中获取角色
                if (isset($row['meta']['roles'])) {
                    $roles = $row['meta']['roles'];
                    if (is_string($roles) && (strpos($roles, 'a:') === 0 || strpos($roles, 'O:') === 0 || strpos($roles, 's:') === 0)) {
                        $unserialized_roles = @unserialize($roles);
                        if ($unserialized_roles !== false) {
                            $roles = $unserialized_roles;
                        }
                    }
                    
                    // 如果是数组，取第一个角色；如果是字符串，直接使用
                    if (is_array($roles) && !empty($roles)) {
                        $row['role'] = reset($roles);
                    } elseif (is_string($roles) && !empty($roles)) {
                        $row['role'] = $roles;
                    }
                }
                
                // 如果仍然没有角色，尝试从 capabilities 中推断
                if (empty($row['role']) || $row['role'] === null) {
                    if (isset($row['meta']['capabilities'])) {
                        $capabilities = $row['meta']['capabilities'];
                        
                        // 如果 capabilities 中包含 access_admin，推断为管理员
                        if (is_array($capabilities) && isset($capabilities['access_admin']) && $capabilities['access_admin']) {
                            $row['role'] = 'administrator'; // 使用 'administrator' 以便后续别名转换
                        }
                    }
                }
                
                // 如果仍然没有角色，使用默认角色
                if (empty($row['role']) || $row['role'] === null) {
                    $row['role'] = self::ROLE_MEMBER;
                }
            }
            
            // 统一角色别名，兼容历史数据中的 'administrator' 等别名
            if (isset($row['role']) && is_string($row['role'])) {
                $normalized = strtolower($row['role']);
                switch ($normalized) {
                    case 'administrator':
                        $row['role'] = self::ROLE_ADMIN; // 兼容管理员别名
                        break;
                    case 'superadministrator':
                    case 'super-admin':
                    case 'superadmin':
                        $row['role'] = self::ROLE_SUPER_ADMIN; // 兼容超级管理员别名
                        break;
                    // 可在此扩展更多别名映射
                }
            }
            
            self::$users_cache[$user_id] = $row;
            return $row;
        }
        
        return null;
    }
    
    /**
     * 根据用户名获取用户
     * 
     * @param string $username 用户名
     * @return array|null
     */
    public static function get_user_by_username($username) {
        $row = self::$tododb->get_row("SELECT id FROM " . self::$tododb->prefix . "users WHERE username = ?", [$username]);
        
        if ($row) {
            // 转换对象为数组
            $row = (array) $row;
            return self::get_user($row['id']);
        }
        
        return null;
    }
    
    /**
     * 根据邮箱获取用户
     * 
     * @param string $email 邮箱
     * @return array|null
     */
    public static function get_user_by_email($email) {
        $row = self::$tododb->get_row("SELECT id FROM " . self::$tododb->prefix . "users WHERE email = ?", [$email]);
        
        if ($row) {
            // 转换对象为数组
            $row = (array) $row;
            return self::get_user($row['id']);
        }
        
        return null;
    }
    
    /**
     * 获取用户列表
     * 
     * @param array $args 查询参数
     * @return array
     */
    public static function get_users($args = array()) {
        // 默认参数
        $defaults = array(
            'role' => null,
            'status' => null,
            'keyword' => null,
            'date_from' => null,
            'date_to' => null,
            'order_by' => 'created_at',
            'order' => 'DESC',
            'limit' => 20,
            'offset' => 0,
            'include_meta' => false
        );
        
        $args = array_merge($defaults, $args);
        
        $where_clauses = array();
        $params = array();
        $types = '';
        
        // 构建WHERE条件
        if (!empty($args['role'])) {
            if (is_array($args['role'])) {
                $placeholders = str_repeat('?,', count($args['role']) - 1) . '?';
                $where_clauses[] = "role IN ({$placeholders})";
                $params = array_merge($params, $args['role']);
                $types .= str_repeat('s', count($args['role']));
            } else {
                $where_clauses[] = 'role = ?';
                $params[] = $args['role'];
                $types .= 's';
            }
        }
        
        if ($args['status'] !== null) {
            $where_clauses[] = 'status = ?';
            $params[] = intval($args['status']);
            $types .= 'i';
        }
        
        if (!empty($args['keyword'])) {
            $where_clauses[] = '(username LIKE ? OR email LIKE ? OR display_name LIKE ?)';
            $keyword = '%' . $args['keyword'] . '%';
            $params[] = $keyword;
            $params[] = $keyword;
            $params[] = $keyword;
            $types .= 'sss';
        }
        
        if (!empty($args['date_from'])) {
            $where_clauses[] = 'created_at >= ?';
            $params[] = $args['date_from'];
            $types .= 's';
        }
        
        if (!empty($args['date_to'])) {
            $where_clauses[] = 'created_at <= ?';
            $params[] = $args['date_to'] . ' 23:59:59';
            $types .= 's';
        }
        
        $where_sql = empty($where_clauses) ? '' : 'WHERE ' . implode(' AND ', $where_clauses);
        
        // 构建ORDER BY
        $allowed_order_fields = array('id', 'username', 'email', 'display_name', 'role', 'status', 'created_at', 'last_login_at');
        $order_by = in_array($args['order_by'], $allowed_order_fields) ? $args['order_by'] : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        $order_sql = "ORDER BY {$order_by} {$order}";
        
        // 构建LIMIT
        $limit_sql = "LIMIT " . intval($args['offset']) . ", " . intval($args['limit']);
        
        $query = "SELECT id, username, email, display_name, role, status, avatar, 
                         phone, gender, birthday, description, website, location, 
                         last_login_at, login_count, created_at, updated_at 
                  FROM " . self::$tododb->prefix . "users 
                  {$where_sql} {$order_sql} {$limit_sql}";
        
        $users = self::$tododb->get_results($query, $params);
        
        if ($users && $args['include_meta']) {
            foreach ($users as &$user) {
                $user['meta'] = self::get_user_meta($user['id']);
            }
        }
        
        return $users;
    }
    
    /**
     * 用户登录
     * 
     * @param string $login 用户名或邮箱
     * @param string $password 密码
     * @param bool $remember 是否记住登录
     * @return array|false 成功返回用户信息，失败返回false
     */
    public static function login($login, $password, $remember = false) {
        // 获取用户（支持用户名或邮箱登录）
        $row = self::$tododb->get_row(
            "SELECT id, username, email, password, role, status 
             FROM " . self::$tododb->prefix . "users 
             WHERE (username = ? OR email = ?) AND status >= 0",
            [$login, $login]
        );
        
        if ($row) {
            // 转换对象为数组
            $row = (array) $row;
            
            // 验证密码
            if (password_verify($password, $row['password'])) {
                // 检查用户状态
                if ($row['status'] == self::STATUS_BANNED) {
                    return false; // 用户被禁用
                }
                
                if ($row['status'] == self::STATUS_INACTIVE) {
                    return false; // 用户未激活
                }
                
                // 更新登录信息
                self::update_login_info($row['id']);
                
                // 设置会话
                self::set_user_session($row['id'], $remember);
                
                // 获取完整用户信息
                $user = self::get_user($row['id']);
                self::$current_user = $user;
                
                return $user;
            }
        }
        
        return false;
    }
    
    /**
     * 用户注销
     * 
     * @return bool
     */
    public static function logout() {
        // 前台“退出模拟”：仅清理模拟相关Cookie与令牌，不销毁会话，避免影响后台管理员会话
        $is_admin = (defined('IS_ADMIN') && IS_ADMIN);
        $is_impersonation = (isset($_COOKIE['todo_impersonate']) && $_COOKIE['todo_impersonate'] === '1');
        if (!$is_admin && $is_impersonation) {
            // 删除模拟登录令牌（按Cookie中的令牌值撤销）
            $token = $_COOKIE['todo_remember_token'] ?? ($_COOKIE['remember_token'] ?? null);
            if ($token && self::$tododb) {
                try {
                    self::$tododb->delete(self::$tododb->prefix . 'remember_tokens', array('token' => $token));
                } catch (\Exception $e) {}
            }
            if (class_exists('Todo_Session_Manager')) {
                Todo_Session_Manager::init();
                Todo_Session_Manager::clear_frontend_session();
            }
            // 彻底销毁所有相关 Cookie
            foreach (['remember_token','todo_remember_token','todo_user_id','todo_impersonate','todo_frontend_token','TODOCMS_FRONTENDSESSID'] as $cname) {
                setcookie($cname, '', time() - 3600, '/');
                setcookie($cname, '', time() - 3600, '/', '', false, true);
            }
            self::$current_user = null;
            return true;
        }

        if (class_exists('Todo_Session_Manager')) {
            Todo_Session_Manager::init();
            if ($is_admin) {
                Todo_Session_Manager::clear_admin_session();
            } else {
                Todo_Session_Manager::clear_frontend_session();
            }
        } else {
            session_unset();
            session_destroy();
        }
        
        // 清除记住登录的数据库令牌与Cookie（含扩展的模拟相关Cookie）
        $token = $_COOKIE['todo_remember_token'] ?? ($_COOKIE['remember_token'] ?? null);
        if ($token && self::$tododb) {
            try {
                $token_table = self::$tododb->prefix . 'remember_tokens';
                if (method_exists(self::$tododb, 'table_exists') ? self::$tododb->table_exists('remember_tokens') : true) {
                    self::$tododb->delete($token_table, array('token' => $token));
                }
            } catch (\Exception $e) {}
        }

        foreach (['remember_token','todo_remember_token','todo_user_id','todo_impersonate','todo_frontend_token'] as $cname) {
            setcookie($cname, '', time() - 3600, '/');
            setcookie($cname, '', time() - 3600, '/', '', false, true);
        }
        
        // 安全检查：如果是管理员退出登录，清除所有管理员缓存
        if ($is_admin || (isset($original_session_user_id) && $original_session_user_id > 0)) {
            // 检查退出前是否是管理员
            $was_admin = false;
            if ($is_admin) {
                $was_admin = true;
            } elseif (isset($original_session_user_id) && $original_session_user_id > 0) {
                // 检查用户角色
                $user = self::get_user($original_session_user_id);
                if ($user && isset($user['role'])) {
                    $role = is_array($user) ? $user['role'] : (is_object($user) ? $user->role : '');
                    if (in_array($role, ['admin', 'super_admin', 'administrator'])) {
                        $was_admin = true;
                    }
                }
            }
            
            if ($was_admin && class_exists('Todo_Unified_Cache_Manager')) {
                Todo_Unified_Cache_Manager::clear_admin_cache();
            }
        }
        
        self::$current_user = null;
        
        return true;
    }
    
    /**
     * 获取当前用户
     * 
     * @return array|null
     */
    public static function get_current_user() {
        if (self::$current_user === null) {
            self::load_current_user();
        }
        return self::$current_user;
    }
    
    /**
     * 检查用户是否已登录
     * 
     * @return bool
     */
    public static function is_logged_in() {
        return self::$current_user !== null;
    }
    
    /**
     * 检查用户权限
     * 
     * @param string $permission 权限名称
     * @param int $user_id 用户ID（可选，默认当前用户）
     * @return bool
     */
    public static function user_can($permission, $user_id = null) {
        // 确保数据库连接已初始化
        if (self::$tododb === null) {
            global $tododb;
            if ($tododb) {
                self::$tododb = $tododb;
            } else {
                // 如果数据库连接不存在，尝试初始化
                if (function_exists('get_database_connection')) {
                    self::$tododb = get_database_connection();
                }
            }
        }
        
        // 初始化 session_user_id 变量（确保在作用域内）
        $session_user_id = null;
        
        if ($user_id === null) {
            $user = self::$current_user;
            // 如果当前用户未加载，尝试重新加载
            // 优先从 Todo_Session_Manager 获取用户ID（后台环境）
            if (defined('IS_ADMIN') && IS_ADMIN) {
                // 后台环境，使用 Todo_Session_Manager
                if (class_exists('Todo_Session_Manager')) {
                    Todo_Session_Manager::init();
                    $session_user_id = Todo_Session_Manager::get_admin_user_id();
                    
                    // 如果 get_admin_user_id() 返回 null，记录调试信息
                    if ($permission === 'access_admin' && !$session_user_id) {
                        // 调试：检查会话管理器状态
                        error_log("  - Todo_Session_Manager::get_admin_user_id() 返回 null");
                        // 检查所有会话键
                        $session_keys = array_keys($_SESSION);
                        error_log("  - 当前会话键: " . implode(', ', $session_keys));
                    }
                } else {
                    if ($permission === 'access_admin') {
                        error_log("  - Todo_Session_Manager 类不存在");
                    }
                }
            } else {
                // 前台环境，使用前台会话
                if (class_exists('Todo_Session_Manager')) {
                    Todo_Session_Manager::init();
                    $session_user_id = Todo_Session_Manager::get_frontend_user_id();
                    
                    // 如果 get_frontend_user_id() 返回 null，但会话变量存在，直接使用会话变量
                    if (!$session_user_id && isset($_SESSION['frontend_user_id'])) {
                        $session_user_id = (int) $_SESSION['frontend_user_id'];
                    }
                }
            }
            
            if (!$user && $session_user_id && $session_user_id > 0) {
                if ($permission === 'access_admin') {
                    error_log("  - 当前用户未加载，尝试重新加载，session user_id=" . $session_user_id);
                }
                // 确保数据库连接可用后直接加载用户
                if (self::$tododb) {
                    $user = self::get_user($session_user_id);
                    // 更新当前用户缓存
                    if ($user) {
                        self::$current_user = $user;
                    }
                    if ($permission === 'access_admin') {
                        error_log("  - 重新加载后，用户: " . ($user ? 'exists' : 'null'));
                    }
                } else {
                    error_log("  - 数据库连接不可用，无法加载用户");
                }
            }
        } else {
            $user = self::get_user($user_id);
        }
        
        if (!$user) {
            // 只在特定权限检查或调试模式下记录详细日志
            // 在系统初始化阶段（如 AJAX 请求、缓存初始化），用户可能未登录，这是正常情况
            $should_log_debug = false;
            
            // 检查是否在调试模式或特定权限检查
            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                $should_log_debug = true;
            }
            
            // 特定权限需要详细日志（如 access_admin）
            if (in_array($permission, array('access_admin', 'manage_options'), true)) {
                // 但只在后台环境或有会话变量的情况下记录
                if (defined('IS_ADMIN') && IS_ADMIN) {
                    $should_log_debug = true;
                } else {
                    // 检查是否有会话（通过会话管理器）
                    if (class_exists('Todo_Session_Manager')) {
                        Todo_Session_Manager::init();
                        if (Todo_Session_Manager::get_admin_user_id() || Todo_Session_Manager::get_frontend_user_id()) {
                            $should_log_debug = true;
                        }
                    }
                }
            }
            
            return false;
        }
        
        // 统一为数组，避免对象访问错误
        if (is_object($user)) {
            $user = (array) $user;
        }
        
        // 如果用户数据中没有 meta 信息，重新获取完整用户信息
        if (!isset($user['meta']) || empty($user['meta'])) {
            $user_id_for_reload = isset($user['id']) ? $user['id'] : (isset($user['ID']) ? $user['ID'] : null);
            if ($user_id_for_reload) {
                $full_user = self::get_user($user_id_for_reload);
                if ($full_user) {
                    $user = $full_user;
                    // 更新当前用户缓存
                    if ($user_id === null) {
                        self::$current_user = $user;
                    }
                }
            }
        }
        
        // 确保存在角色信息
        if (!isset($user['role']) || $user['role'] === '' || $user['role'] === null) {
            // 尝试根据ID重新获取完整用户信息
            $user_id_for_reload = isset($user['id']) ? $user['id'] : (isset($user['ID']) ? $user['ID'] : null);
            if ($user_id_for_reload) {
                $full_user = self::get_user($user_id_for_reload);
                if ($full_user) {
                    $user = $full_user;
                    // 更新当前用户缓存
                    if ($user_id === null) {
                        self::$current_user = $user;
                    }
                }
            }
            // 仍未获取到角色则设置为默认角色
            if (!isset($user['role']) || $user['role'] === '' || $user['role'] === null) {
                // 默认角色：会员（未登录情况下应走前置的 !$user 分支返回 false）
                $user['role'] = self::ROLE_MEMBER;
            }
        }
        
        // 统一角色别名，兼容历史数据中的 'administrator' 等别名
        if (isset($user['role']) && is_string($user['role'])) {
            $normalized = strtolower($user['role']);
            switch ($normalized) {
                case 'administrator':
                    $user['role'] = self::ROLE_ADMIN; // 兼容管理员别名
                    break;
                case 'superadministrator':
                case 'super-admin':
                case 'superadmin':
                    $user['role'] = self::ROLE_SUPER_ADMIN; // 兼容超级管理员别名
                    break;
                // 可在此扩展更多别名映射
            }
        }
        
        // 超级管理员拥有所有权限
        if ($user['role'] === self::ROLE_SUPER_ADMIN) {
            if ($permission === 'access_admin') {
                error_log("  - 超级管理员，返回 true");
            }
            return true;
        }
        
        // 获取角色权限
        $role_permissions = self::get_role_permissions($user['role']);
        
        // 管理员角色特殊处理：如果角色是 admin，直接允许所有权限检查
        if ($user['role'] === self::ROLE_ADMIN || $user['role'] === 'admin') {
            // 管理员拥有所有权限，直接返回 true
            if ($permission === 'access_admin') {
                error_log("  - 管理员角色，返回 true");
            }
            return true;
        }
        
        if ($permission === 'access_admin') {
            error_log("  - 不是管理员角色，继续检查角色权限");
        }
        
        if (is_array($role_permissions) && in_array('*', $role_permissions, true)) {
            return true;
        }

        // 如果角色权限中包含该权限，直接返回 true
        if (is_array($role_permissions) && in_array($permission, $role_permissions)) {
            if ($permission === 'access_admin') {
                error_log("  - 角色权限中包含 access_admin，返回 true");
            }
            return true;
        }
        
        if ($permission === 'access_admin') {
            error_log("  - 角色权限中不包含 access_admin");
            error_log("  - 角色权限列表: " . json_encode($role_permissions));
        }
        
        // 如果角色权限中没有该权限，检查用户的 capabilities（从 meta 中获取）
        if (isset($user['meta']['capabilities'])) {
            $capabilities = $user['meta']['capabilities'];
            if ($permission === 'access_admin') {
                error_log("  - 检查 capabilities");
                error_log("  - capabilities 类型: " . gettype($capabilities));
                error_log("  - capabilities 内容: " . json_encode($capabilities));
                error_log("  - capabilities[$permission] 存在: " . (isset($capabilities[$permission]) ? 'true' : 'false'));
                if (isset($capabilities[$permission])) {
                    error_log("  - capabilities[$permission] 值: " . ($capabilities[$permission] ? 'true' : 'false'));
                    error_log("  - capabilities[$permission] 类型: " . gettype($capabilities[$permission]));
                }
            }
            if (is_array($capabilities) && isset($capabilities[$permission]) && $capabilities[$permission]) {
                if ($permission === 'access_admin') {
                    error_log("  - capabilities 中包含 access_admin，返回 true");
                }
                return true;
            } else {
                if ($permission === 'access_admin') {
                    error_log("  - capabilities 检查失败");
                }
            }
        } else {
            if ($permission === 'access_admin') {
                error_log("  - 用户 meta 中不存在 capabilities");
            }
        }
        
        // 调试日志：记录权限检查失败的原因
        if ($permission === 'access_admin') {
            /*
            $debug_user_id = isset($user['id']) ? $user['id'] : (isset($user['ID']) ? $user['ID'] : 'unknown');
            $debug_role = isset($user['role']) ? $user['role'] : 'unknown';
            $debug_has_cap = isset($user['meta']['capabilities'][$permission]) ? ($user['meta']['capabilities'][$permission] ? 'true' : 'false') : 'not_set';
            error_log("Todo_User_Manager::user_can(access_admin) 失败:");
            error_log("  - 用户ID: {$debug_user_id}");
            error_log("  - 角色: {$debug_role}");
            error_log("  - capabilities.access_admin: {$debug_has_cap}");
            error_log("  - 角色权限: " . json_encode($role_permissions));
            error_log("  - 用户数据键: " . json_encode(array_keys($user)));
            if (isset($user['meta'])) {
                error_log("  - meta键: " . json_encode(array_keys($user['meta'])));
            }
            */
        }
        
        return false;
    }
    
    /**
     * 获取角色权限
     * 
     * @param string $role 角色
     * @return array
     */
    public static function get_role_permissions($role) {
        if (isset(self::$permissions_cache[$role])) {
            return self::$permissions_cache[$role];
        }

        $role_key = is_string($role) ? strtolower(trim($role)) : '';
        $roles_option = null;
        if (function_exists('get_option')) {
            $roles_option = get_option('cms_roles', null);
        } elseif (class_exists('CMS_Option')) {
            $roles_option = CMS_Option::get('cms_roles', null);
        }
        if (is_array($roles_option) && !empty($roles_option)) {
            $lookup = array();
            if ($role_key !== '') {
                $lookup[] = $role_key;
            }
            if ($role_key === self::ROLE_ADMIN || $role_key === 'admin') {
                $lookup[] = 'administrator';
            }
            if ($role_key === self::ROLE_SUPER_ADMIN) {
                $lookup[] = 'super_admin';
            }

            foreach ($lookup as $k) {
                if (!isset($roles_option[$k])) {
                    continue;
                }
                $entry = $roles_option[$k];
                $caps = null;
                if (is_array($entry) && isset($entry['capabilities'])) {
                    $caps = $entry['capabilities'];
                } else {
                    $caps = $entry;
                }
                if (!is_array($caps) || empty($caps)) {
                    continue;
                }

                $is_list = array_keys($caps) === range(0, count($caps) - 1);
                $out = array();
                if ($is_list) {
                    foreach ($caps as $c) {
                        if (is_string($c)) {
                            $c = trim($c);
                            if ($c !== '') {
                                $out[] = $c;
                            }
                        }
                    }
                } else {
                    foreach ($caps as $cap => $enabled) {
                        if (!is_string($cap)) {
                            continue;
                        }
                        $cap = trim($cap);
                        if ($cap === '') {
                            continue;
                        }
                        if ($enabled) {
                            $out[] = $cap;
                        }
                    }
                }

                if (!empty($out)) {
                    self::$permissions_cache[$role] = $out;
                    return $out;
                }
            }
        }
        
        if (function_exists('get_role_capabilities')) {
            $default_caps = get_role_capabilities($role_key);
            if (empty($default_caps) && ($role_key === self::ROLE_ADMIN || $role_key === 'admin')) {
                $default_caps = get_role_capabilities('administrator');
            }
            if (is_array($default_caps) && !empty($default_caps)) {
                $out = array();
                foreach ($default_caps as $cap => $enabled) {
                    if (is_string($cap) && trim($cap) !== '' && $enabled) {
                        $out[] = trim($cap);
                    }
                }
                if (!empty($out)) {
                    self::$permissions_cache[$role] = $out;
                    return $out;
                }
            }
        }

        // 默认权限配置
        $permissions = array(
            self::ROLE_SUPER_ADMIN => array('*'), // 所有权限
            self::ROLE_ADMIN => array(
                'access_admin', 'manage_options', 'edit_posts', 'publish_posts', 'moderate_comments',
                'manage_categories', 'manage_links', 'upload_files', 'edit_others_posts', 'edit_published_posts',
                'delete_others_posts', 'delete_published_posts', 'manage_users', 'create_users', 'delete_users',
                'promote_users', 'edit_theme_options', 'import', 'export', 'list_users', 'manage_plugins',
                'activate_plugins', 'update_plugins', 'delete_plugins', 'view_stats', 'manage_media',
                'manage_comments', 'manage_ads', 'manage_models', 'edit_models', 'manage_languages'
            ),
            'administrator' => array(
                'access_admin', 'manage_options', 'edit_posts', 'publish_posts', 'moderate_comments',
                'manage_categories', 'manage_links', 'upload_files', 'edit_others_posts', 'edit_published_posts',
                'delete_others_posts', 'delete_published_posts', 'manage_users', 'create_users', 'delete_users',
                'promote_users', 'edit_theme_options', 'import', 'export', 'list_users', 'manage_plugins',
                'activate_plugins', 'update_plugins', 'delete_plugins', 'view_stats', 'manage_media',
                'manage_comments', 'manage_ads', 'manage_models', 'edit_models', 'manage_languages'
            ),
            self::ROLE_EDITOR => array(
                'access_admin', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'publish_posts',
                'delete_posts', 'delete_others_posts', 'delete_published_posts', 'manage_categories',
                'manage_links', 'moderate_comments', 'upload_files', 'unfiltered_html', 'view_stats',
                'manage_media', 'manage_comments'
            ),
            self::ROLE_AUTHOR => array(
                'access_admin', 'edit_posts', 'edit_published_posts', 'publish_posts', 'delete_posts',
                'delete_published_posts', 'upload_files', 'manage_media'
            ),
            self::ROLE_SUBSCRIBER => array(
                'read'
            ),
            self::ROLE_MEMBER => array(
                'read', 'comment', 'edit_profile'
            ),
            self::ROLE_VIP => array(
                'read', 'comment', 'edit_profile', 'access_vip_content'
            ),
            self::ROLE_GUEST => array(
                'read_public_content'
            )
        );
        
        $role_permissions = isset($permissions[$role]) ? $permissions[$role] : (isset($permissions[$role_key]) ? $permissions[$role_key] : array());
        self::$permissions_cache[$role] = $role_permissions;
        
        return $role_permissions;
    }
    
    /**
     * 激活用户
     * 
     * @param string $activation_code 激活码
     * @return bool
     */
    public static function activate_user($activation_code) {
        $update_data = array(
            'status' => self::STATUS_ACTIVE,
            'activation_code' => '',
            'updated_at' => date('Y-m-d H:i:s')
        );
        
        $where = array(
            'activation_code' => $activation_code,
            'status' => self::STATUS_INACTIVE
        );
        
        return self::$tododb->update(self::$tododb->prefix . 'users', $update_data, $where);
    }
    
    /**
     * 重置密码
     * 
     * @param string $email 邮箱
     * @return string|false 成功返回重置令牌，失败返回false
     */
    public static function request_password_reset($email) {
        $user = self::get_user_by_email($email);
        if (!$user) {
            return false;
        }
        
        $reset_token = md5(uniqid(mt_rand(), true));
        $expires_at = date('Y-m-d H:i:s', time() + 3600); // 1小时后过期
        
        $data = array(
            'email' => $email,
            'token' => $reset_token,
            'expires_at' => $expires_at,
            'created_at' => date('Y-m-d H:i:s')
        );
        
        // 先尝试删除已存在的记录
        self::$tododb->delete(self::$tododb->prefix . 'password_resets', array('email' => $email));
        
        if (self::$tododb->insert(self::$tododb->prefix . 'password_resets', $data)) {
            return $reset_token;
        }
        
        return false;
    }
    
    /**
     * 重置密码
     * 
     * @param string $token 重置令牌
     * @param string $new_password 新密码
     * @return bool
     */
    public static function reset_password($token, $new_password) {
        // 验证令牌
        $row = self::$tododb->get_row(
            "SELECT email FROM " . self::$tododb->prefix . "password_resets 
             WHERE token = ? AND expires_at > NOW()",
            $token
        );
        
        if ($row) {
            $email = $row['email'];
            
            // 更新密码
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update_data = array(
                'password' => $hashed_password,
                'updated_at' => date('Y-m-d H:i:s')
            );
            
            if (self::$tododb->update(self::$tododb->prefix . 'users', $update_data, array('email' => $email))) {
                // 删除重置记录
                self::$tododb->delete(self::$tododb->prefix . 'password_resets', array('token' => $token));
                
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 保存用户元数据
     * 
     * @param int $user_id 用户ID
     * @param string $meta_key 元数据键
     * @param mixed $meta_value 元数据值
     * @return bool
     */
    public static function save_user_meta($user_id, $meta_key, $meta_value) {
        $meta_value_json = is_array($meta_value) ? json_encode($meta_value) : $meta_value;
        
        // 先尝试删除已存在的记录
        self::$tododb->delete(self::$tododb->prefix . 'user_meta', array(
            'user_id' => $user_id,
            'meta_key' => $meta_key
        ));
        
        $data = array(
            'user_id' => $user_id,
            'meta_key' => $meta_key,
            'meta_value' => $meta_value_json,
            'created_at' => date('Y-m-d H:i:s')
        );
        
        return self::$tododb->insert(self::$tododb->prefix . 'user_meta', $data);
    }
    
    /**
     * 获取用户元数据
     * 
     * @param int $user_id 用户ID
     * @param string $meta_key 元数据键（可选）
     * @return array|string|null
     */
    public static function get_user_meta($user_id, $meta_key = null) {
        if ($meta_key) {
            $row = self::$tododb->get_row(
                "SELECT meta_value FROM " . self::$tododb->prefix . "user_meta 
                 WHERE user_id = ? AND meta_key = ?",
                [$user_id, $meta_key]
            );
            
            if ($row) {
                $value = is_object($row) ? $row->meta_value : $row['meta_value'];
                $decoded = json_decode($value, true);
                if ($decoded !== null) {
                    return $decoded;
                }
                if (is_string($value) && (strpos($value, 'a:') === 0 || strpos($value, 'O:') === 0 || strpos($value, 's:') === 0)) {
                    $unserialized = @unserialize($value);
                    if ($unserialized !== false) {
                        return $unserialized;
                    }
                }
                return $value;
            }
            
            return null;
        } else {
            $results = self::$tododb->get_results(
                "SELECT meta_key, meta_value FROM " . self::$tododb->prefix . "user_meta WHERE user_id = ?",
                [$user_id]
            );
            
            $meta = array();
            if ($results) {
                foreach ($results as $row) {
                    $value = is_object($row) ? $row->meta_value : $row['meta_value'];
                    $meta_key = is_object($row) ? $row->meta_key : $row['meta_key'];
                    
                    // 尝试 JSON 解码
                    $decoded = json_decode($value, true);
                    if ($decoded !== null) {
                        $meta[$meta_key] = $decoded;
                    } else {
                        // 如果不是 JSON，尝试反序列化（PHP 序列化格式）
                        if (is_string($value) && (strpos($value, 'a:') === 0 || strpos($value, 'O:') === 0 || strpos($value, 's:') === 0)) {
                            $unserialized = @unserialize($value);
                            if ($unserialized !== false) {
                                $meta[$meta_key] = $unserialized;
                            } else {
                                $meta[$meta_key] = $value;
                            }
                        } else {
                            $meta[$meta_key] = $value;
                        }
                    }
                }
            }
            
            return $meta;
        }
    }
    
    /**
     * 删除用户元数据
     * 
     * @param int $user_id 用户ID
     * @param string $meta_key 元数据键（可选）
     * @return bool
     */
    public static function delete_user_meta($user_id, $meta_key = null) {
        if ($meta_key) {
            return self::$tododb->delete(self::$tododb->prefix . 'user_meta', array(
                'user_id' => $user_id,
                'meta_key' => $meta_key
            ));
        } else {
            return self::$tododb->delete(self::$tododb->prefix . 'user_meta', array(
                'user_id' => $user_id
            ));
        }
    }
    
    /**
     * 检查用户名是否存在
     * 
     * @param string $username 用户名
     * @param int $exclude_id 排除的用户ID
     * @return bool
     */
    private static function username_exists($username, $exclude_id = 0) {
        if ($exclude_id > 0) {
            $row = self::$tododb->get_row(
                "SELECT id FROM " . self::$tododb->prefix . "users WHERE username = ? AND id != ?",
                [$username, $exclude_id]
            );
        } else {
            $row = self::$tododb->get_row(
                "SELECT id FROM " . self::$tododb->prefix . "users WHERE username = ?",
                [$username]
            );
        }
        
        return $row !== null;
    }
    
    /**
     * 检查邮箱是否存在
     * 
     * @param string $email 邮箱
     * @param int $exclude_id 排除的用户ID
     * @return bool
     */
    private static function email_exists($email, $exclude_id = 0) {
        if ($exclude_id > 0) {
            $row = self::$tododb->get_row(
                "SELECT id FROM " . self::$tododb->prefix . "users WHERE email = ? AND id != ?",
                [$email, $exclude_id]
            );
        } else {
            $row = self::$tododb->get_row(
                "SELECT id FROM " . self::$tododb->prefix . "users WHERE email = ?",
                [$email]
            );
        }
        
        return $row !== null;
    }
    
    /**
     * 加载当前用户
     */
    private static function load_current_user() {
        $user_id = null;
        
        // 确保数据库连接已初始化
        if (self::$tododb === null) {
            global $tododb;
            if ($tododb) {
                self::$tododb = $tododb;
            } else {
                // 如果数据库连接不存在，尝试初始化
                if (function_exists('get_database_connection')) {
                    self::$tododb = get_database_connection();
                }
            }
        }
        
        // 前台模拟登录与记住登录校验：在非后台环境，优先使用 remember_token / todo_remember_token / todo_user_id 登录
        $is_frontend_ctx = (!defined('IS_ADMIN') || (defined('IS_ADMIN') && IS_ADMIN === false));
        if ($is_frontend_ctx) {
            $is_impersonate = (isset($_COOKIE['todo_impersonate']) && $_COOKIE['todo_impersonate'] === '1');
            $token = $_COOKIE['todo_remember_token'] ?? ($_COOKIE['remember_token'] ?? null);
            if ($token && self::$tododb) {
                $val_uid = self::validate_remember_token($token);
                if ($val_uid) {
                    $user_id = $val_uid;
                    if (class_exists('Todo_Session_Manager')) {
                        Todo_Session_Manager::init();
                        $role = function_exists('get_user_role') ? get_user_role($val_uid) : (function_exists('todo_get_user_role') ? todo_get_user_role($val_uid) : '');
                        Todo_Session_Manager::set_frontend_session($val_uid, $role, !$is_impersonate);
                    }
                }
            } elseif (!empty($_COOKIE['todo_user_id']) && !empty($_COOKIE['todo_impersonate']) && self::$tododb) {
                // 容错备选：直接从 todo_user_id 读取模拟登录的用户
                $impersonate_uid = intval($_COOKIE['todo_user_id']);
                if ($impersonate_uid > 0) {
                    $user_id = $impersonate_uid;
                    if (class_exists('Todo_Session_Manager')) {
                        Todo_Session_Manager::init();
                        $role = function_exists('get_user_role') ? get_user_role($impersonate_uid) : (function_exists('todo_get_user_role') ? todo_get_user_role($impersonate_uid) : '');
                        Todo_Session_Manager::set_frontend_session($impersonate_uid, $role, false);
                    }
                }
            }
        }
        
        // 检查会话（仅当未通过优先token确定用户时）
        // 优先从 Todo_Session_Manager 获取用户ID（后台环境）
        if (!$user_id) {
            if (defined('IS_ADMIN') && IS_ADMIN) {
                // 后台环境，使用 Todo_Session_Manager
                if (class_exists('Todo_Session_Manager')) {
                    Todo_Session_Manager::init();
                    $user_id = Todo_Session_Manager::get_admin_user_id();
                    
                    // get_admin_user_id() 已经处理了会话验证，无需额外检查
                }
            } else {
                // 前台环境，使用前台会话
                if (class_exists('Todo_Session_Manager')) {
                    Todo_Session_Manager::init();
                    $user_id = Todo_Session_Manager::get_frontend_user_id();
                    
                    // 如果 get_frontend_user_id() 返回 null，但会话变量存在，直接使用会话变量
                    if (!$user_id && isset($_SESSION['frontend_user_id'])) {
                        $user_id = (int) $_SESSION['frontend_user_id'];
                    }
                }
            }
        }
        
        // 若会话无效或不存在，再检查"记住登录"的 Cookie（仅当尚未确定用户时）
        if (!$user_id) {
            $token = isset($_COOKIE['remember_token']) ? $_COOKIE['remember_token'] : (isset($_COOKIE['todo_remember_token']) ? $_COOKIE['todo_remember_token'] : null);
            if ($token && self::$tododb) {
                $user_id = self::validate_remember_token($token);
                if ($user_id) {
                    if (class_exists('Todo_Session_Manager')) {
                        Todo_Session_Manager::init();
                        $role = function_exists('get_user_role') ? get_user_role($user_id) : (function_exists('todo_get_user_role') ? todo_get_user_role($user_id) : '');
                        Todo_Session_Manager::set_frontend_session($user_id, $role, true);
                    }
                } else {
                    // 若验证失败，主动清理 remember_token Cookie，减少无效 token 的反复校验
                    if (isset($_COOKIE['remember_token'])) {
                        setcookie('remember_token', '', time() - 3600, '/');
                    }
                    if (isset($_COOKIE['todo_remember_token'])) {
                        setcookie('todo_remember_token', '', time() - 3600, '/');
                    }
                }
            }
        }
        
        if ($user_id) {
            self::$current_user = self::get_user($user_id);
        }
    }
    
    /**
     * 设置用户会话
     * 
     * @param int $user_id 用户ID
     * @param bool $remember 是否记住登录
     */
    private static function set_user_session($user_id, $remember = false) {
        $is_impersonate = (!empty($_COOKIE['todo_impersonate']) || !empty($_SESSION['is_impersonating']));
        if ($is_impersonate) {
            return;
        }

        $is_admin = (defined('IS_ADMIN') && IS_ADMIN);
        if (class_exists('Todo_Session_Manager')) {
            Todo_Session_Manager::init();
            $user = self::get_user($user_id);
            $role = '';
            if (is_array($user) && isset($user['role'])) {
                $role = (string) $user['role'];
            } elseif (is_object($user) && isset($user->role)) {
                $role = (string) $user->role;
            }
            if ($is_admin) {
                Todo_Session_Manager::set_admin_session($user_id, $role, $remember);
            } else {
                Todo_Session_Manager::set_frontend_session($user_id, $role, $remember);
            }
            return;
        }

        $type = $is_admin ? 'admin' : 'frontend';
        $session_id = session_id();
        if ($session_id) {
            $session_id = $type . ':' . $session_id;
        }
        $ip_address = todo_get_client_ip();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $expires_at = date('Y-m-d H:i:s', time() + ini_get('session.gc_maxlifetime'));

        self::$tododb->delete(self::$tododb->prefix . 'user_sessions', array('session_id' => $session_id));

        $session_data = array(
            'user_id' => $user_id,
            'session_id' => $session_id,
            'ip_address' => $ip_address,
            'user_agent' => $user_agent,
            'expires_at' => $expires_at,
            'created_at' => date('Y-m-d H:i:s')
        );

        self::$tododb->insert(self::$tododb->prefix . 'user_sessions', $session_data);

        if ($remember) {
            $rememberTableExists = false;
            try {
                $rememberTableExists = (bool) self::$tododb->get_var("SHOW TABLES LIKE '" . self::$tododb->prefix . "remember_tokens'");
            } catch (\Exception $e) {
            }

            if ($rememberTableExists) {
                $remember_token = md5(uniqid(mt_rand(), true));
                $expires_at = date('Y-m-d H:i:s', time() + 30 * 24 * 3600);

                $purposeColumnExists = false;
                try {
                    $res = self::$tododb->query("SHOW COLUMNS FROM " . self::$tododb->prefix . "remember_tokens LIKE 'purpose'");
                    if ($res && (method_exists($res, 'rowCount') ? $res->rowCount() > 0 : true)) {
                        $purposeColumnExists = true;
                    }
                } catch (\Exception $e) {
                }

                $token_data = array(
                    'user_id' => $user_id,
                    'token' => $remember_token,
                    'expires_at' => $expires_at,
                    'created_at' => date('Y-m-d H:i:s')
                );
                if ($purposeColumnExists) {
                    $token_data['purpose'] = 'remember';
                }

                try {
                    if (self::$tododb->insert(self::$tododb->prefix . 'remember_tokens', $token_data)) {
                        setcookie('remember_token', $remember_token, time() + 30 * 24 * 3600, '/');
                    }
                } catch (\Exception $e) {
                }
            }
        }
    }
    
    /**
     * 验证记住登录令牌
     * 
     * @param string $token 令牌
     * @return int|null 用户ID
     */
    private static function validate_remember_token($token) {
        // 防御：部分环境下尚未创建 remember_tokens 表，避免 SQL 异常
        try {
            $exists = false;
            $res = self::$tododb->query("SHOW TABLES LIKE '" . self::$tododb->prefix . "remember_tokens'");
            // 使用 get_var 简化存在性判断，避免对非对象调用 method_exists 引发 TypeError
            $exists = self::$tododb->get_var("SHOW TABLES LIKE '" . self::$tododb->prefix . "remember_tokens'");
            if (!$exists) {
                // 表不存在则直接忽略记住登录令牌
                return null;
            }
            if (!$exists) {
                // 表不存在则直接忽略记住登录令牌
                return null;
            }
        } catch (\Exception $e) {
            // 数据库不支持 SHOW TABLES 或其他异常，降级为不使用令牌
            return null;
        }

        // 正常验证令牌
        $row = self::$tododb->get_row(
            "SELECT user_id FROM " . self::$tododb->prefix . "remember_tokens 
             WHERE token = ? AND expires_at > NOW()",
            [$token],
            'ARRAY_A'
        );
        
        if ($row) {
            return intval($row['user_id']);
        }
        
        return null;
    }
    
    /**
     * 更新登录信息（公开方法，供原生登录及第三方 QQ 登录等统一调用）
     * 
     * @param int $user_id 用户ID
     */
    public static function update_login_info($user_id) {
        // 管理员模拟登录查看用户中心时，绝不更新用户的登录时间、IP等信息，做到不留痕迹
        if (!empty($_COOKIE['todo_impersonate']) || !empty($_SESSION['is_impersonating'])) {
            return;
        }

        $ip_address = function_exists('todo_get_client_ip') ? todo_get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
        $now = date('Y-m-d H:i:s');
        
        $update_data = array(
            'last_login_at' => $now,
            'last_login_ip' => $ip_address,
        );
        
        if (self::$tododb) {
            @self::$tododb->update(self::$tododb->prefix . 'users', $update_data, array('id' => $user_id));
        }
        
        if (function_exists('update_user_meta')) {
            update_user_meta($user_id, 'last_login', $now);
            update_user_meta($user_id, '_last_login', $now);
        }
    }
    
    /**
     * 获取角色选项
     * 
     * @return array
     */
    public static function get_role_options() {
        return array(
            self::ROLE_SUPER_ADMIN => '超级管理员',
            self::ROLE_ADMIN => '管理员',
            self::ROLE_EDITOR => '编辑者',
            self::ROLE_AUTHOR => '作者',
            self::ROLE_SUBSCRIBER => '订阅者',
            self::ROLE_MEMBER => '会员',
            self::ROLE_VIP => 'VIP会员',
            self::ROLE_GUEST => '游客'
        );
    }
    
    /**
     * 获取状态选项
     * 
     * @return array
     */
    public static function get_status_options() {
        return array(
            self::STATUS_ACTIVE => '激活',
            self::STATUS_INACTIVE => '未激活',
            self::STATUS_BANNED => '禁用',
            self::STATUS_PENDING => '待审核'
        );
    }
}

// 便捷函数
if (!function_exists('todo_get_current_user')) {
    /**
     * 获取当前用户
     * 
     * @return array|null
     */
    function todo_get_current_user() {
        if (class_exists('Todo_User_Manager')) {
            $user = Todo_User_Manager::get_current_user();
            if ($user) {
                return $user;
            }
        }

        if (class_exists('Todo_Session_Manager')) {
            Todo_Session_Manager::init();
            $uid = 0;
            if (defined('IS_ADMIN') && IS_ADMIN) {
                $uid = (int) (Todo_Session_Manager::get_admin_user_id() ?: 0);
            } else {
                $uid = (int) (Todo_Session_Manager::get_frontend_user_id() ?: 0);
            }
            if ($uid > 0) {
                global $tododb;
                if ($tododb && isset($tododb->prefix)) {
                    $row = $tododb->get_row(
                        "SELECT * FROM {$tododb->prefix}users WHERE ID = ? LIMIT 1",
                        [$uid]
                    );
                    if ($row) {
                        return is_object($row) ? get_object_vars($row) : (is_array($row) ? $row : null);
                    }
                }
            }
        }

        return null;
    }
}

if (!function_exists('todo_is_logged_in')) {
    /**
     * 检查用户是否已登录
     * 
     * @return bool
     */
    function todo_is_logged_in() {
        if (class_exists('Todo_Session_Manager')) {
            Todo_Session_Manager::init();
            if (defined('IS_ADMIN') && IS_ADMIN) {
                return Todo_Session_Manager::is_admin_logged_in();
            }
            return Todo_Session_Manager::is_frontend_logged_in();
        }
        return class_exists('Todo_User_Manager') ? Todo_User_Manager::is_logged_in() : false;
    }
}

if (!function_exists('todo_user_can')) {
    /**
     * 检查用户权限
     * 
     * @param string $permission 权限名称
     * @param int $user_id 用户ID（可选）
     * @return bool
     */
    function todo_user_can($permission, $user_id = null) {
        return Todo_User_Manager::user_can($permission, $user_id);
    }
}

if (!function_exists('todo_get_user')) {
    /**
     * 获取用户信息
     * 
     * @param int $user_id 用户ID
     * @return array|null
     */
    function todo_get_user($user_id) {
        return Todo_User_Manager::get_user($user_id);
    }
}

if (!function_exists('todo_login')) {
    /**
     * 用户登录
     * 
     * @param string $login 用户名或邮箱
     * @param string $password 密码
     * @param bool $remember 是否记住登录
     * @return array|false
     */
    function todo_login($login, $password, $remember = false) {
        return Todo_User_Manager::login($login, $password, $remember);
    }
}

if (!function_exists('todo_logout')) {
    /**
     * 用户注销
     * 
     * @return bool
     */
    function todo_logout() {
        return Todo_User_Manager::logout();
    }
}

if (!function_exists('todo_update_user_last_login')) {
    /**
     * 更新用户最后登录时间与IP
     * 
     * @param int $user_id
     */
    function todo_update_user_last_login($user_id) {
        Todo_User_Manager::update_login_info($user_id);
    }
}

if (!class_exists('Todo_User') && class_exists('CMS_User')) {
    class_alias('CMS_User', 'Todo_User');
}

?>
