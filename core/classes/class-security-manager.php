<?php
/**
 * 安全管理器
 * 提供统一的权限检查、数据验证、安全防护功能
 *
 * @package TodoCMS
 * @version 2.0.0
 */

class Todo_Security_Manager {
    
    /**
     * 单例实例
     * @var Todo_Security_Manager
     */
    private static $instance = null;
    
    /**
     * 安全配置
     * @var array
     */
    private static $config = array();
    
    /**
     * CSRF令牌存储
     * @var array
     */
    private static $csrf_tokens = array();
    
    /**
     * 已验证的请求缓存
     * @var array
     */
    private static $verified_requests = array();
    
    /**
     * 获取单例实例
     * @return Todo_Security_Manager
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 私有构造函数，防止外部实例化
     */
    private function __construct() {
        // 初始化配置
        if (empty(self::$config)) {
            self::init();
        }
    }
    
    /**
     * 初始化安全管理器
     */
    public static function init($config = array()) {
        $default_config = array(
            'csrf_protection' => true,
            'csrf_token_lifetime' => 3600, // 1小时
            'csrf_auto_refresh' => true,
            'csrf_ajax_only' => false,
            'debug_mode' => (defined('TODO_DEBUG') && TODO_DEBUG) || false
        );
        
        self::$config = array_merge($default_config, $config);
        
        if (self::$config['csrf_protection']) {
            self::init_csrf_protection();
        }
    }

    /**
     * 初始化CSRF保护
     */
    private static function init_csrf_protection() {
        // 启动会话（如果尚未启动）
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        // 初始化令牌存储
        if (!isset($_SESSION['todo_csrf_tokens'])) {
            $_SESSION['todo_csrf_tokens'] = array();
        }
        
        self::$csrf_tokens = &$_SESSION['todo_csrf_tokens'];
    }

    /**
     * 统一的CSRF请求验证方法
     * 这是推荐使用的验证方法，会自动处理各种情况
     */
    public static function verify_request_csrf($action = 'default', $nonce_field = null) {
        // 开发模式跳过验证
        if (isset(self::$config['debug_mode']) && self::$config['debug_mode']) {
            return true;
        }
        
        // 检查是否已经验证过此请求（包含action以避免不同action共享缓存）
        $request_id = self::get_request_id($action);
        if (isset(self::$verified_requests[$request_id])) {
            $cached_result = self::$verified_requests[$request_id];
            error_log("Security_Manager::verify_request_csrf: Using cached result for action={$action}, nonce_field={$nonce_field}, result=" . ($cached_result ? 'true' : 'false') . ", request_id=" . substr($request_id, 0, 8) . '...');
            return $cached_result;
        }
        
        // 检查是否有自己的CSRF验证机制
        $has_own_verification = self::has_own_csrf_verification();
        if ($has_own_verification) {
            error_log("Security_Manager::verify_request_csrf: has_own_csrf_verification returned true for action={$action}");
            self::$verified_requests[$request_id] = true;
            return true;
        }
        
        $result = false;
        
        // 尝试从不同来源获取令牌
        $token = self::get_token_from_request($nonce_field);
        $token_source = self::get_token_source($nonce_field);
        
        error_log("Security_Manager::verify_request_csrf: action={$action}, nonce_field={$nonce_field}, token_source={$token_source}, token_preview=" . (!empty($token) ? substr($token, 0, 10) . '...' : 'empty'));
        
        if (!empty($token)) {
            // 确保CSRF保护已初始化
            if (empty(self::$csrf_tokens)) {
                self::init_csrf_protection();
            }
            
            // 检查session中是否有该action的令牌
            $has_action_in_session = isset($_SESSION['todo_csrf_tokens'][$action]) && is_array($_SESSION['todo_csrf_tokens'][$action]);
            $action_tokens_count = $has_action_in_session ? count($_SESSION['todo_csrf_tokens'][$action]) : 0;
            error_log("Security_Manager::verify_request_csrf: action={$action}, has_action_in_session=" . ($has_action_in_session ? 'yes' : 'no') . ", tokens_count={$action_tokens_count}");
            
            if ($has_action_in_session) {
                // 获取session中所有token的预览（前10个字符）
                $session_token_previews = array();
                $current_time = time();
                foreach ($_SESSION['todo_csrf_tokens'][$action] as $stoken => $expire) {
                    $is_expired = $current_time > $expire;
                    $session_token_previews[] = substr($stoken, 0, 10) . '...' . ($is_expired ? '[expired]' : '');
                }
                error_log("Security_Manager::verify_request_csrf: session tokens for action={$action}: " . implode(', ', $session_token_previews));
                
                $token_exists = isset($_SESSION['todo_csrf_tokens'][$action][$token]);
                $token_expired = false;
                if ($token_exists) {
                    $expire_time = $_SESSION['todo_csrf_tokens'][$action][$token];
                    $token_expired = time() > $expire_time;
                    error_log("Security_Manager::verify_request_csrf: token_exists={$token_exists}, expire_time={$expire_time}, current_time={$current_time}, token_expired={$token_expired}");
                } else {
                    error_log("Security_Manager::verify_request_csrf: token not found in session for action={$action}, requested_token_preview=" . substr($token, 0, 10) . '...');
                    // 尝试查找部分匹配（可能是token被截断或其他问题）
                    $token_match_found = false;
                    foreach ($_SESSION['todo_csrf_tokens'][$action] as $stoken => $expire) {
                        if (substr($stoken, 0, 10) === substr($token, 0, 10)) {
                            error_log("Security_Manager::verify_request_csrf: Found partial match! session_token_preview=" . substr($stoken, 0, 20) . '..., requested_token_preview=' . substr($token, 0, 20) . '..., full_length_match=' . ($stoken === $token ? 'yes' : 'no'));
                            $token_match_found = true;
                            break;
                        }
                    }
                    if (!$token_match_found) {
                        error_log("Security_Manager::verify_request_csrf: No partial match found for token preview " . substr($token, 0, 10) . '...');
                    }
                }
            }
            
            $result = self::verify_csrf_token($token, $action);
            error_log("Security_Manager::verify_request_csrf: verify_csrf_token returned " . ($result ? 'true' : 'false') . " for action={$action}");
        } else {
            error_log("Security_Manager::verify_request_csrf: No token found for action={$action}, nonce_field={$nonce_field}");
        }
        
        // 缓存验证结果
        self::$verified_requests[$request_id] = $result;
        
        // 记录验证结果（调试模式）
        if (isset(self::$config['debug_mode']) && self::$config['debug_mode']) {
            error_log("CSRF验证结果: action={$action}, nonce_field={$nonce_field}, result=" . ($result ? 'true' : 'false') . ", token=" . substr($token, 0, 10) . "...");
        }
        
        return $result;
    }
    
    /**
     * 检查是否有自己的CSRF验证机制
     */
    private static function has_own_csrf_verification() {
        // 检查特定的POST操作
        $specific_operations = [
            'clear_cache', 'save_settings', 'warmup_cache', 'save_auto_cleanup',
            'add_ad_group', 'edit_ad_group', 'delete_ad_group', 'add_ad', 'update_ad',
            'save_language_settings', 'create_post_nonce', 'edit_post_nonce',
            'update-comment', 'login_action', 'settings_nonce', 'install-plugin',
            'bulk-media', 'menu_action', 'migrate_model_fields'
        ];
        
        if (!is_array($specific_operations)) {
            return false;
        }
        
        foreach ($specific_operations as $operation) {
            if (isset($_POST[$operation]) || isset($_GET[$operation])) {
                return true;
            }
        }
        
        // 检查是否存在_todononce但没有action（表示使用传统验证）
        if ((isset($_POST['_todononce']) || isset($_GET['_todononce'])) && 
            !isset($_POST['action']) && !isset($_GET['action'])) {
            return true;
        }
        
        // 检查特定页面路径
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $excluded_paths = [
            '/admin/pages/tool/cache-management.php',
            '/admin/pages/setting/',
            '/admin/pages/ads/',
            '/admin/pages/content/post-edit.php',
            '/admin/pages/content/post-new.php',
            '/admin/pages/comment/',
            '/admin/pages/plugin/',
            '/admin/pages/tool/',
            '/admin/index.php'
        ];
        
        foreach ($excluded_paths as $path) {
            if (strpos($request_uri, $path) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 从请求中获取令牌
     */
    private static function get_token_from_request($nonce_field = null) {
        // 如果指定了特定的nonce字段，优先使用
        if ($nonce_field) {
            $token = $_POST[$nonce_field] ?? $_GET[$nonce_field] ?? '';
            if (!empty($token)) {
                error_log("Security_Manager::get_token_from_request: Found token from nonce_field={$nonce_field}, source=" . (isset($_POST[$nonce_field]) ? 'POST' : 'GET'));
                return $token;
            }
        }
        
        // 优先级：HTTP头 > POST > GET
        if (isset($_SERVER['HTTP_X_CSRF_TOKEN']) && !empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            error_log("Security_Manager::get_token_from_request: Found token from HTTP_X_CSRF_TOKEN header");
            return $_SERVER['HTTP_X_CSRF_TOKEN'];
        }
        if (isset($_SERVER['HTTP_X_TODO_NONCE']) && !empty($_SERVER['HTTP_X_TODO_NONCE'])) {
            error_log("Security_Manager::get_token_from_request: Found token from HTTP_X_TODO_NONCE header");
            return $_SERVER['HTTP_X_TODO_NONCE'];
        }
        if (isset($_POST['_csrf_token']) && !empty($_POST['_csrf_token'])) {
            error_log("Security_Manager::get_token_from_request: Found token from POST[_csrf_token]");
            return $_POST['_csrf_token'];
        }
        if (isset($_GET['_csrf_token']) && !empty($_GET['_csrf_token'])) {
            error_log("Security_Manager::get_token_from_request: Found token from GET[_csrf_token]");
            return $_GET['_csrf_token'];
        }
        if (isset($_POST['_todononce']) && !empty($_POST['_todononce'])) {
            error_log("Security_Manager::get_token_from_request: Found token from POST[_todononce]");
            return $_POST['_todononce'];
        }
        if (isset($_GET['_todononce']) && !empty($_GET['_todononce'])) {
            error_log("Security_Manager::get_token_from_request: Found token from GET[_todononce]");
            return $_GET['_todononce'];
        }
        
        error_log("Security_Manager::get_token_from_request: No token found, nonce_field={$nonce_field}");
        return '';
    }
    
    /**
     * 获取令牌来源（用于调试）
     */
    private static function get_token_source($nonce_field = null) {
        if ($nonce_field) {
            if (isset($_POST[$nonce_field]) && !empty($_POST[$nonce_field])) {
                return "POST[{$nonce_field}]";
            }
            if (isset($_GET[$nonce_field]) && !empty($_GET[$nonce_field])) {
                return "GET[{$nonce_field}]";
            }
        }
        
        if (isset($_SERVER['HTTP_X_CSRF_TOKEN']) && !empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            return 'HTTP_X_CSRF_TOKEN';
        }
        if (isset($_SERVER['HTTP_X_TODO_NONCE']) && !empty($_SERVER['HTTP_X_TODO_NONCE'])) {
            return 'HTTP_X_TODO_NONCE';
        }
        if (isset($_POST['_csrf_token']) && !empty($_POST['_csrf_token'])) {
            return 'POST[_csrf_token]';
        }
        if (isset($_GET['_csrf_token']) && !empty($_GET['_csrf_token'])) {
            return 'GET[_csrf_token]';
        }
        if (isset($_POST['_todononce']) && !empty($_POST['_todononce'])) {
            return 'POST[_todononce]';
        }
        if (isset($_GET['_todononce']) && !empty($_GET['_todononce'])) {
            return 'GET[_todononce]';
        }
        
        return 'none';
    }
    
    /**
     * 生成请求ID用于缓存验证结果（包含action以避免不同action共享缓存）
     */
    private static function get_request_id($action = '') {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        // 优先使用 todo_get_current_user_id，避免在核心函数未加载时出现未定义错误
        $user_id = (function_exists('todo_get_current_user_id')
            ? todo_get_current_user_id()
            : (function_exists('get_current_user_id')
                ? get_current_user_id()
                : 0)
        );
        
        // 包含action以确保不同action的验证结果不会被共享缓存
        return md5($uri . $method . $user_id . $action . serialize($_POST) . serialize($_GET));
    }
    /**
     * 当前请求生成的令牌缓存
     * @var array
     */
    private static $request_generated_tokens = array();

    /**
     * 生成CSRF令牌
     */
    public static function generate_csrf_token($action = 'default') {
        // 如果当前请求已经为该 action 生成过令牌，直接复用以避免 session 队列溢出
        if (isset(self::$request_generated_tokens[$action])) {
            return self::$request_generated_tokens[$action];
        }

        // 确保CSRF保护已初始化
        if (empty(self::$csrf_tokens)) {
            self::init_csrf_protection();
        }
        
        if (!isset(self::$csrf_tokens[$action])) {
            self::$csrf_tokens[$action] = array();
        }
        
        $token = bin2hex(random_bytes(32));
        // 确保配置已初始化并提供安全兜底
        if (empty(self::$config)) {
            self::init();
        }
        $lifetime = isset(self::$config['csrf_token_lifetime']) ? intval(self::$config['csrf_token_lifetime']) : 3600;
        $expire = time() + $lifetime;
        
        self::$csrf_tokens[$action][$token] = $expire;
        
        // 缓存到当前请求，同一页面内的多个 csrf_field 将输出相同的令牌
        self::$request_generated_tokens[$action] = $token;

        // 清理过期令牌
        self::clean_expired_tokens($action);
        
        // 限制每个action的token数量（调高到50个，应对多标签页场景）
        self::limit_tokens_per_action($action, 50);
        
        return $token;
    }

    /**
     * 获取CSRF令牌（别名方法）
     */
    public static function get_csrf_token($action = 'default') {
        return self::generate_csrf_token($action);
    }

    /**
     * 验证CSRF令牌
     */
    public static function verify_csrf_token($token = null, $action = 'default') {
        if (empty($token)) {
            $token = self::get_token_from_request();
        }
        if (empty($token)) {
            return false;
        }
        if (empty(self::$csrf_tokens)) {
            self::init_csrf_protection();
        }

        if (!isset(self::$csrf_tokens[$action][$token])) {
            error_log("Security_Manager::verify_csrf_token: Token not found in self::\$csrf_tokens for action={$action}, token_preview=" . substr($token, 0, 10) . '...');
            // 检查session中的情况
            if (isset($_SESSION['todo_csrf_tokens'][$action]) && is_array($_SESSION['todo_csrf_tokens'][$action])) {
                $token_exists_in_session = isset($_SESSION['todo_csrf_tokens'][$action][$token]);
                error_log("Security_Manager::verify_csrf_token: token_exists_in_session=" . ($token_exists_in_session ? 'yes' : 'no') . ", session_tokens_count=" . count($_SESSION['todo_csrf_tokens'][$action]));
                // 如果session中存在但self::$csrf_tokens中不存在，尝试同步
                if ($token_exists_in_session && !isset(self::$csrf_tokens[$action])) {
                    self::$csrf_tokens[$action] = array();
                }
                if ($token_exists_in_session && !isset(self::$csrf_tokens[$action][$token])) {
                    self::$csrf_tokens[$action][$token] = $_SESSION['todo_csrf_tokens'][$action][$token];
                    error_log("Security_Manager::verify_csrf_token: Synced token from session to self::\$csrf_tokens");
                }
            } else {
                error_log("Security_Manager::verify_csrf_token: No tokens in session for action={$action}");
            }
            // 如果在指定 action 下没找到，尝试在所有 action 或 default 动作池中匹配
            if (!isset(self::$csrf_tokens[$action][$token])) {
                foreach (self::$csrf_tokens as $act => $tokens) {
                    if (isset($tokens[$token])) {
                        $action = $act;
                        break;
                    }
                }
                if (!isset(self::$csrf_tokens[$action][$token]) && isset($_SESSION['todo_csrf_tokens']) && is_array($_SESSION['todo_csrf_tokens'])) {
                    foreach ($_SESSION['todo_csrf_tokens'] as $act => $tokens) {
                        if (is_array($tokens) && isset($tokens[$token])) {
                            self::$csrf_tokens[$act][$token] = $tokens[$token];
                            $action = $act;
                            break;
                        }
                    }
                }
            }

            // 如果搜索所有 action 后仍不存在，返回false
            if (!isset(self::$csrf_tokens[$action][$token])) {
                return false;
            }
        }

        // 检查是否过期
        $expire_time = self::$csrf_tokens[$action][$token];
        $current_time = time();
        if ($current_time > $expire_time) {
            error_log("Security_Manager::verify_csrf_token: Token expired for action={$action}, expire_time={$expire_time}, current_time={$current_time}");
            unset(self::$csrf_tokens[$action][$token]);
            if (isset($_SESSION['todo_csrf_tokens'][$action][$token])) {
                unset($_SESSION['todo_csrf_tokens'][$action][$token]);
            }
            return false;
        }

        error_log("Security_Manager::verify_csrf_token: Token verified successfully for action={$action}, expire_time={$expire_time}, current_time={$current_time}");
        return true;
    }

    /**
     * 生成CSRF字段HTML
     */
    public static function csrf_field($action = 'default', $field_name = '_todononce') {
        $token = self::generate_csrf_token($action);
        return '<input type="hidden" name="' . esc_attr($field_name) . '" value="' . esc_attr($token) . '" />';
    }

    /**
     * 输出CSRF字段
     */
    public static function csrf_field_echo($action = 'default') {
        echo self::csrf_field($action);
    }

    /**
     * 生成CSRF字段（别名方法）
     * 为了保持向后兼容性和统一的API
     */
    public static function generate_csrf_field($action = 'default', $field_name = '_todononce') {
        return self::csrf_field($action, $field_name);
    }
    
    /**
     * 生成CSRF字段（get_csrf_field别名方法）
     * 为了保持向后兼容性
     */
    public static function get_csrf_field($action = 'default', $field_name = '_todononce') {
        return self::csrf_field($action, $field_name);
    }
    
    /**
     * 直接输出CSRF令牌字段
     *
     * @param string $action 动作名称
     * @param string $field_name 字段名称
     */
    public static function output_csrf_field($action = 'default', $field_name = '_todononce') {
        echo self::csrf_field($action, $field_name);
    }
    
    /**
     * AJAX专用CSRF验证（改进版）
     */
    public static function verify_ajax_csrf($action = 'default') {
        // 检查是否为AJAX请求
        if (!self::is_ajax_request()) {
            self::ajax_die('非AJAX请求');
        }
        
        // 使用统一验证方法
        if (!self::verify_request_csrf($action)) {
            self::ajax_die('CSRF验证失败');
        }
        
        return true;
    }
    
    /**
     * 检查是否为AJAX请求
     */
    public static function is_ajax_request() {
        return (defined('DOING_AJAX') && DOING_AJAX) ||
               (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
    }
    
    /**
     * AJAX错误响应
     */
    private static function ajax_die($message) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['error' => $message]));
    }

    /**
     * 清理过期令牌
     */
    private static function clean_expired_tokens($action) {
        if (!isset(self::$csrf_tokens[$action]) || !is_array(self::$csrf_tokens[$action])) {
            return;
        }
        
        $now = time();
        foreach (self::$csrf_tokens[$action] as $token => $expire) {
            if (is_numeric($expire) && $now > $expire) {
                unset(self::$csrf_tokens[$action][$token]);
            }
        }
    }
    
    /**
     * 限制每个action的token数量（删除最旧的token）
     */
    private static function limit_tokens_per_action($action, $max_tokens = 10) {
        if (!isset(self::$csrf_tokens[$action]) || !is_array(self::$csrf_tokens[$action])) {
            return;
        }
        
        $token_count = count(self::$csrf_tokens[$action]);
        if ($token_count <= $max_tokens) {
            return;
        }
        
        // 按过期时间排序（最早的在前）
        uasort(self::$csrf_tokens[$action], function($a, $b) {
            return $a - $b; // 按过期时间升序排序
        });
        
        // 删除最旧的token，只保留最新的 $max_tokens 个
        $tokens_to_keep = array_slice(self::$csrf_tokens[$action], -$max_tokens, $max_tokens, true);
        self::$csrf_tokens[$action] = $tokens_to_keep;
    }

    /**
     * 刷新CSRF令牌
     */
    public static function refresh_csrf_token($action = 'default') {
        $new_token = self::generate_csrf_token($action);
        // 确保配置已初始化并提供安全兜底
        if (empty(self::$config)) {
            self::init();
        }
        $lifetime = isset(self::$config['csrf_token_lifetime']) ? intval(self::$config['csrf_token_lifetime']) : 3600;
        return [
            'token' => $new_token,
            'expires' => time() + $lifetime
        ];
    }

    /**
     * 获取客户端IP地址
     * @return string
     */
    public static function get_client_ip() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }
}
