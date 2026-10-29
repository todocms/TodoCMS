<?php
/**
 * AJAX基础处理器
 * 提供统一的AJAX请求处理、安全验证和错误处理
 *
 * @package TodoCMS
 * @version 2.0.0
 */

if (!defined('TODO_AJAX_BASE')) {
    define('TODO_AJAX_BASE', true);
}

/**
 * AJAX基础处理器类
 */
abstract class Todo_Ajax_Base {
    
    /**
     * 配置选项
     */
    protected $config = [
        'require_login' => true,        // 是否需要登录
        'require_csrf' => true,         // 是否需要CSRF验证
        'csrf_action' => 'default',     // CSRF动作名称
        'allowed_methods' => ['POST'],  // 允许的HTTP方法
        'require_ajax' => true,         // 是否必须是AJAX请求
        'auto_setup' => true,           // 是否自动设置环境
        'debug_mode' => false           // 调试模式（在构造函数中动态设置）
    ];
    
    /**
     * 错误信息
     */
    protected $errors = [];
    
    /**
     * 响应数据
     */
    protected $response_data = [];
    
    /**
     * 构造函数
     */
    public function __construct($config = []) {
        // 设置调试模式（如果未在配置中指定）
        if (!isset($config['debug_mode'])) {
            $this->config['debug_mode'] = (defined('TODO_DEBUG') && TODO_DEBUG) || false;
        }
        
        // 合并配置
        $this->config = array_merge($this->config, $config);
        
        // 自动设置环境
        if ($this->config['auto_setup']) {
            $this->setup_environment();
        }
        
        // 执行安全检查
        if (!$this->security_check()) {
            $this->error_exit();
        }
        
        // 处理请求
        $this->handle_request();
    }
    
    /**
     * 设置环境
     */
    protected function setup_environment() {
        // 设置错误报告
        if ($this->config['debug_mode']) {
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
        }
        
        // 启动会话
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // 设置内容类型
        header('Content-Type: application/json; charset=utf-8');
        
        // 加载必要文件
        $this->load_dependencies();
    }
    
    /**
     * 加载依赖文件
     */
    protected function load_dependencies() {
        $base_dir = dirname(dirname(__DIR__));
        
        // 加载配置文件
        if (file_exists($base_dir . '/config/config.php')) {
            require_once $base_dir . '/config/config.php';
        }
        
        // 加载核心类
        $core_files = [
            '/core/classes/class-security-manager.php',
            '/core/functions/functions.php',
            '/core/functions/user-functions.php'
        ];
        
        foreach ($core_files as $file) {
            $file_path = $base_dir . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }
    }
    
    /**
     * 安全检查
     */
    protected function security_check() {
        // 检查是否为AJAX请求
        if ($this->config['require_ajax'] && !$this->is_ajax_request()) {
            $this->add_error('Direct access denied', 403);
            return false;
        }
        
        // 检查HTTP方法
        if (!in_array($_SERVER['REQUEST_METHOD'], $this->config['allowed_methods'])) {
            $this->add_error('Method not allowed', 405);
            return false;
        }
        
        // 检查用户登录状态
        if ($this->config['require_login'] && !$this->is_user_logged_in()) {
            $this->add_error('Authentication required', 401);
            return false;
        }
        
        // 检查用户权限
        if (!$this->check_user_permission()) {
            $this->add_error('Permission denied', 403);
            return false;
        }
        
        // CSRF验证
        if ($this->config['require_csrf'] && !$this->verify_csrf()) {
            // 获取前端和后端nonce信息用于调试
            $debug_info = $this->get_csrf_debug_info();
            $this->add_error('CSRF verification failed', 403, $debug_info);
            return false;
        }
        
        return true;
    }
    
    /**
     * 检查是否为AJAX请求
     */
    protected function is_ajax_request() {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
    
    /**
     * 检查用户登录状态
     */
    protected function is_user_logged_in() {
        // 使用 Todo_Session_Manager 检查登录状态
        if (class_exists('Todo_Session_Manager')) {
            Todo_Session_Manager::init();
            if (defined('IS_ADMIN') && IS_ADMIN) {
                return Todo_Session_Manager::is_admin_logged_in();
            } else {
                return Todo_Session_Manager::is_frontend_logged_in();
            }
        }
        return false;
    }
    
    /**
     * 检查用户权限
     */
    protected function check_user_permission() {
        // 默认实现，子类可以重写
        return $this->is_user_logged_in();
    }
    
    /**
     * CSRF验证
     */
    protected function verify_csrf() {
        if (!class_exists('Todo_Security_Manager')) {
            return false;
        }
        
        // 使用新的统一CSRF验证方法
        $action = $this->config['csrf_action'];
        $nonce_key = '_' . $action . '_todononce';
        
        return Todo_Security_Manager::verify_request_csrf($action, $nonce_key);
    }
    
    /**
     * 获取CSRF调试信息
     */
    protected function get_csrf_debug_info() {
        $action = $this->config['csrf_action'];
        $nonce_key = '_' . $action . '_todononce';
        
        // 获取前端发送的nonce
        $frontend_nonce = $_POST[$nonce_key] ?? $_GET[$nonce_key] ?? 
                         $_SERVER['HTTP_X_CSRF_TOKEN'] ?? 
                         $_SERVER['HTTP_X_TODO_NONCE'] ?? 
                         $_POST['_todononce'] ?? 
                         $_GET['_todononce'] ?? 
                         null;
        
        // 生成一个新的后端nonce用于比较
        $backend_nonce = null;
        if (class_exists('Todo_Security_Manager')) {
            $backend_nonce = Todo_Security_Manager::generate_csrf_token($action);
        }
        
        return [
            'action' => $action,
            'nonce_key' => $nonce_key,
            'frontend_nonce' => $frontend_nonce ? substr($frontend_nonce, 0, 16) . '...' : 'null',
            'backend_nonce' => $backend_nonce ? substr($backend_nonce, 0, 16) . '...' : 'null',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'timestamp' => date('Y-m-d H:i:s'),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];
    }
    
    /**
     * 添加错误
     */
    protected function add_error($message, $code = 400, $debug_info = null) {
        $error = [
            'message' => $message,
            'code' => $code
        ];
        
        if ($debug_info !== null) {
            $error['debug_info'] = $debug_info;
        }
        
        $this->errors[] = $error;
    }
    
    /**
     * 错误退出
     */
    protected function error_exit() {
        $error = !empty($this->errors) ? $this->errors[0] : ['message' => 'Unknown error', 'code' => 500];
        
        http_response_code($error['code']);
        
        $response = [
            'success' => false,
            'error' => $error['message'],
            'code' => $error['code']
        ];
        
        // 如果有CSRF调试信息，总是包含它（用于调试CSRF问题）
        if (isset($error['debug_info'])) {
            $response['csrf_debug'] = $error['debug_info'];
        }
        
        if ($this->config['debug_mode']) {
            $response['debug'] = [
                'errors' => $this->errors,
                'config' => $this->config,
                'request' => [
                    'method' => $_SERVER['REQUEST_METHOD'],
                    'headers' => getallheaders(),
                    'post' => $_POST,
                    'get' => $_GET
                ]
            ];
        }
        
        echo json_encode($response);
        exit;
    }
    
    /**
     * 成功响应
     */
    protected function success_response($data = [], $message = '') {
        $response = [
            'success' => true,
            'data' => $data
        ];
        
        if (!empty($message)) {
            $response['message'] = $message;
        }
        
        if ($this->config['debug_mode']) {
            $response['debug'] = [
                'execution_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
                'memory_usage' => memory_get_usage(true),
                'config' => $this->config
            ];
        }
        
        echo json_encode($response);
        exit;
    }
    
    /**
     * 错误响应
     */
    protected function error_response($message, $code = 400, $data = []) {
        http_response_code($code);
        
        $response = [
            'success' => false,
            'error' => $message,
            'code' => $code
        ];
        
        if (!empty($data)) {
            $response['data'] = $data;
        }
        
        if ($this->config['debug_mode']) {
            $response['debug'] = [
                'errors' => $this->errors,
                'execution_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
                'memory_usage' => memory_get_usage(true)
            ];
        }
        
        echo json_encode($response);
        exit;
    }
    
    /**
     * 获取请求参数
     */
    protected function get_param($key, $default = null, $method = 'REQUEST') {
        switch (strtoupper($method)) {
            case 'POST':
                return isset($_POST[$key]) ? $_POST[$key] : $default;
            case 'GET':
                return isset($_GET[$key]) ? $_GET[$key] : $default;
            case 'REQUEST':
            default:
                return isset($_REQUEST[$key]) ? $_REQUEST[$key] : $default;
        }
    }
    
    /**
     * 获取整数参数
     *
     * @param string $key 参数键
     * @param int $default 默认值
     * @param string $method 请求方法 (POST|GET|REQUEST)
     * @return int 整数值
     */
    protected function get_int_param($key, $default = 0, $method = 'REQUEST') {
        return intval($this->get_param($key, $default, $method));
    }
    
    /**
     * 获取字符串参数
     *
     * @param string $key 参数键
     * @param string $default 默认值
     * @param string $method 请求方法 (POST|GET|REQUEST)
     * @return string 字符串值
     */
    protected function get_string_param($key, $default = '', $method = 'REQUEST') {
        return sanitize_text_field($this->get_param($key, $default, $method));
    }
    
    /**
     * 获取数组参数
     *
     * @param string $key 参数键
     * @param array $default 默认值
     * @param string $method 请求方法 (POST|GET|REQUEST)
     * @return array 数组值
     */
    protected function get_array_param($key, $default = [], $method = 'REQUEST') {
        $value = $this->get_param($key, $default, $method);
        return is_array($value) ? $value : $default;
    }
    
    /**
     * 验证必需参数
     *
     * @param array $params 必需参数列表
     * @param string $method 请求方法 (POST|GET|REQUEST)
     * @return bool 验证是否通过
     * @throws Todo_Error 如果缺少必需参数
     */
    protected function validate_required_params($params, $method = 'REQUEST') {
        $missing = [];
        
        foreach ($params as $param) {
            if (empty($this->get_param($param, null, $method))) {
                $missing[] = $param;
            }
        }
        
        if (!empty($missing)) {
            $this->error_response('Missing required parameters: ' . implode(', ', $missing), 400);
        }
        
        return true;
    }
    
    /**
     * 日志记录
     *
     * @param string $message 日志消息
     * @param string $level 日志级别 (info|warning|error)
     * @return void
     */
    protected function log($message, $level = 'info') {
        if ($this->config['debug_mode']) {
            $timestamp = date('Y-m-d H:i:s');
            $log_message = "[{$timestamp}] [{$level}] {$message}";
            
            // 可以扩展为写入日志文件
            error_log($log_message);
        }
    }
    
    /**
     * 抽象方法：处理请求
     * 子类必须实现此方法
     */
    abstract protected function handle_request();
}

/**
 * 简化的AJAX处理器函数
 * 用于快速创建AJAX处理器
 */
function todo_ajax_handler($callback, $config = []) {
    $handler = new class($config, $callback) extends Todo_Ajax_Base {
        private $callback;
        
        public function __construct($config, $callback) {
            $this->callback = $callback;
            parent::__construct($config);
        }
        
        protected function handle_request() {
            if (is_callable($this->callback)) {
                call_user_func($this->callback, $this);
            } else {
                $this->error_response('Invalid callback');
            }
        }
    };
}