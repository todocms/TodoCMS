<?php
// 禁用错误显示，确保AJAX响应为纯JSON
ini_set('display_errors', 0);
error_reporting(0);

// 定义 AJAX 请求常量
if (!defined('DOING_AJAX')) {
    if (!defined('DOING_AJAX')) {
        define('DOING_AJAX', true);
    }
}

// 定义 TODO_LOADED 常量，避免类文件的直接访问保护
if (!defined('TODO_LOADED')) {
    define('TODO_LOADED', true);
}

// 检查是否为AJAX请求
if (
    !isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'
) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(array('error' => 'Direct access denied'));
    exit;
}

// 启动会话
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 加载配置文件
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
}
require_once ABSPATH . 'config.php';

// 加载Composer autoloader
if (!class_exists('Composer\Autoload\ClassLoader')) {
    $autoload_path = ABSPATH . 'vendor/autoload.php';
    if (file_exists($autoload_path)) {
        require_once $autoload_path;
    }
}

// 初始化数据库连接
global $cms_db, $tododb;
if (!$cms_db) {
    if (class_exists('CMS_DB')) {
        $cms_db = CMS_DB::get_instance();
    }
}
$tododb = $cms_db;

// 加载所有核心函数
require_once ABSPATH . 'core/functions/load_functions.php';

// 加载admin函数文件
require_once ABSPATH . 'admin/includes/todo-functions.php';

// 初始化安全管理器
Todo_Security_Manager::init();

// 检查用户权限
if (!admin_logged_in()) {
    error_log("CSRF刷新权限检查失败: 用户未登录");
    http_response_code(403);
    // 检查登录状态
    $logged_in = false;
    if (class_exists('Todo_Session_Manager')) {
        Todo_Session_Manager::init();
        $logged_in = Todo_Session_Manager::is_admin_logged_in();
    }

    echo json_encode([
        'success' => false,
        'error' => '权限不足',
        'session_status' => session_status(),
        'logged_in' => $logged_in,
        'timestamp' => time()
    ]);
    exit;
}

header('Content-Type: application/json');

try {
    // 验证请求方法
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    // 获取动作参数
    $action = $_POST['csrf_action'] ?? $_POST['action'] ?? 'default';

    // 刷新令牌
    $result = Todo_Security_Manager::refresh_csrf_token($action);

    // 返回成功响应
    echo json_encode([
        'success' => true,
        'token' => $result['token'],
        'expires' => $result['expires'],
        'action' => $action,
        'timestamp' => time()
    ]);

} catch (Exception $e) {
    // 返回错误响应
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => time()
    ]);
}
