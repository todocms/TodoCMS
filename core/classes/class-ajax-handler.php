<?php
/**
 * AJAX处理基类
 * 提供统一的AJAX请求处理、安全验证、错误处理
 *
 * @package TodoCMS
 * @version 1.0.0
 */

class Todo_Ajax_Handler {
    
    /**
     * 处理器配置
     * @var array
     */
    protected $config = array();
    
    /**
     * 是否需要登录
     * @var bool
     */
    protected $require_login = true;
    
    /**
     * 是否需要CSRF验证
     * @var bool
     */
    protected $require_csrf = true;
    
    /**
     * CSRF动作名称
     * @var string
     */
    protected $csrf_action = 'default';
    
    /**
     * 允许的HTTP方法
     * @var array
     */
    protected $allowed_methods = array('POST');
    
    /**
     * 构造函数
     */
    public function __construct($config = array()) {
        $this->config = array_merge(array(
            'require_login' => true,
            'require_csrf' => true,
            'csrf_action' => 'default',
            'allowed_methods' => array('POST'),
            'content_type' => 'application/json'
        ), $config);
        
        // 应用配置
        $this->require_login = $this->config['require_login'];
        $this->require_csrf = $this->config['require_csrf'];
        $this->csrf_action = $this->config['csrf_action'];
        $this->allowed_methods = $this->config['allowed_methods'];
    }
    
    /**
     * 初始化AJAX处理器
     */
    public static function init($handler_class, $config = array()) {
        // 设置基础环境
        self::setup_environment();
        
        // 创建处理器实例
        $handler = new $handler_class($config);
        
        // 执行安全检查
        $handler->security_checks();
        
        // 处理请求
        $handler->handle_request();
    }
    
    /**
     * 设置基础环境
     */
    private static function setup_environment() {
        // 确保 REQUEST_METHOD 存在
        if (!isset($_SERVER['REQUEST_METHOD'])) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }
        
        // 设置基础路径
        
        // 定义 AJAX 请求常量
        if (!defined('DOING_AJAX')) {
            define('DOING_AJAX', true);
        }
        
        // 定义 TODO_LOADED 常量
        if (!defined('TODO_LOADED')) {
            define('TODO_LOADED', true);
        }
        
        // 启动会话
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        // 加载核心文件
        self::load_core_files();
    }
    
    /**
     * 加载核心文件
     */
    private static function load_core_files() {
        $core_files = array(
            ABSPATH . 'config.php',
            ABSPATH . 'core/db/class-db.php',
            ABSPATH . 'core/functions/todo-functions.php',
            ABSPATH . 'core/functions/security.php',
            ABSPATH . 'core/functions/formatting.php',
            ABSPATH . 'core/functions/core.php',
            ABSPATH . 'admin/includes/todo-functions.php'
        );
        
        foreach ($core_files as $file) {
            if (file_exists($file)) {
                require_once $file;
            }
        }
        
        // 初始化数据库连接
        global $cms_db, $tododb;
        if (!$cms_db) {
            $cms_db = new CMS_DB();
        }
        $tododb = $cms_db;
    }
    
    /**
     * 执行安全检查
     */
    protected function security_checks() {
        // 检查是否为AJAX请求
        $this->check_ajax_request();
        
        // 检查HTTP方法
        $this->check_http_method();
        
        // 检查用户权限
        if ($this->require_login) {
            $this->check_user_permission();
        }
        
        // 检查CSRF令牌
        if ($this->require_csrf) {
            $this->check_csrf_token();
        }
    }
    
    /**
     * 检查是否为AJAX请求
     */
    protected function check_ajax_request() {
        if (!Todo_Security_Manager::is_ajax_request()) {
            $this->error_response('非AJAX请求', 403);
        }
    }
    
    /**
     * 检查HTTP方法
     */
    protected function check_http_method() {
        $method = $_SERVER['REQUEST_METHOD'];
        if (!in_array($method, $this->allowed_methods)) {
            $this->error_response('方法不允许', 405);
        }
    }
    
    /**
     * 检查用户权限
     */
    protected function check_user_permission() {
        if (!admin_logged_in()) {
            $this->error_response('权限不足', 403);
        }
    }
    
    /**
     * 检查CSRF令牌
     */
    protected function check_csrf_token() {
        if (!Todo_Security_Manager::verify_request_csrf($this->csrf_action)) {
            $this->error_response('CSRF验证失败', 403);
        }
    }
    
    /**
     * 处理请求（子类需要重写此方法）
     */
    protected function handle_request() {
        $this->error_response('未实现的处理方法', 501);
    }
    
    /**
     * 成功响应
     */
    protected function success_response($data = array(), $message = '') {
        $response = array(
            'success' => true,
            'data' => $data
        );
        
        if (!empty($message)) {
            $response['message'] = $message;
        }
        
        $this->json_response($response);
    }
    
    /**
     * 错误响应
     */
    protected function error_response($message, $code = 400) {
        http_response_code($code);
        $this->json_response(array(
            'success' => false,
            'error' => $message
        ));
    }
    
    /**
     * JSON响应
     */
    protected function json_response($data) {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
    
    /**
     * 获取POST参数
     */
    protected function get_post($key, $default = null) {
        return isset($_POST[$key]) ? sanitize_text_field($_POST[$key]) : $default;
    }
    
    /**
     * 获取GET参数
     */
    protected function get_get($key, $default = null) {
        return isset($_GET[$key]) ? sanitize_text_field($_GET[$key]) : $default;
    }
    
    /**
     * 获取整数参数
     */
    protected function get_int($key, $default = 0, $method = 'POST') {
        $value = $method === 'POST' ? $this->get_post($key, $default) : $this->get_get($key, $default);
        return max(0, intval($value));
    }
    
    /**
     * 记录错误日志
     */
    protected function log_error($message, $context = array()) {
        $log_message = 'AJAX Error: ' . $message;
        if (!empty($context)) {
            $log_message .= ' Context: ' . json_encode($context);
        }
        error_log($log_message);
    }
}