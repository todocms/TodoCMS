<?php
/**
 * 更新检查 AJAX 处理器
 * 
 * 注意：此文件应通过 admin-ajax.php 统一入口访问
 * 如果被直接访问，需要正确计算项目根目录路径
 * 
 * @package TodoCMS
 */

// 开启输出缓冲，防止意外输出破坏JSON格式
if (ob_get_level() === 0) {
    ob_start();
}

// 设置基础路径（必须在加载autoload之前定义）
// 文件位置：admin/ajax/update-check.php
// 需要向上两级目录到达项目根目录
if (!defined('ABSPATH')) {
    // 从 admin/ajax/ 向上两级到项目根目录
    define('ABSPATH', rtrim(dirname(dirname(dirname(__FILE__))), '/') . '/');
}

// 检查是否通过统一AJAX入口加载
// 如果已经加载了autoload（通过admin-ajax.php），则不需要重复加载
if (!class_exists('Composer\Autoload\ClassLoader')) {
    // 只有在未加载时才加载autoload（直接访问的情况）
    $autoload_path = ABSPATH . 'vendor/autoload.php';
    if (file_exists($autoload_path)) {
        require_once $autoload_path;
    } else {
        // 如果autoload不存在，说明路径计算错误或项目结构异常
        ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => '系统初始化失败',
            'message' => '无法加载Composer autoload文件',
            'path' => $autoload_path
        ]);
        exit;
    }
}

// 如果通过统一AJAX入口加载，这些已经完成，跳过
// 如果是直接访问，需要完成初始化
if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
    
    // 统一直接访问保护与AJAX环境标识
    if (!isset($_SERVER['REQUEST_METHOD'])) {
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }
    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
        ob_end_clean();
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Direct access not permitted']);
        exit;
    }

    // 定义管理后台常量，防止被包含的文件拒绝访问
    if (!defined('TODO_ADMIN')) {
        define('TODO_ADMIN', true);
    }
    if (!defined('IS_ADMIN')) {
        define('IS_ADMIN', true);
    }
    if (!defined('ADMIN_ACCESS')) {
        define('ADMIN_ACCESS', true);
    }

    // 加载配置文件和引导文件（autoload已加载，constants.php已自动加载）
    require_once ABSPATH . 'config.php';
    require_once ABSPATH . 'core/bootstrap.php';
    require_once ABSPATH . 'core/functions/transient-functions.php';
}

// 会话与权限（使用新的会话管理器）
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 使用 Todo_Session_Manager 检查后台登录状态
if (!class_exists('Todo_Session_Manager')) {
    if (!defined('TODO_LOADED')) {
        define('TODO_LOADED', true);
    }
    if (!class_exists('Todo_Session_Manager')) {
        ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => '会话管理器无法加载']);
        exit;
    }
}
Todo_Session_Manager::init();

if (!Todo_Session_Manager::is_admin_logged_in()) {
    ob_end_clean();
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => '权限不足']);
    exit;
}
if (function_exists('current_user_can') && !current_user_can('manage_options')) {
    ob_end_clean();
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => '缺少更新管理权限']);
    exit;
}

header('Content-Type: application/json');

// CSRF 验证
try {
    Todo_Security_Manager::verify_ajax_csrf('dashboard_updates');
} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['error' => 'CSRF验证失败']);
    exit;
}

$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
if ($action !== 'get_updates') {
    ob_end_clean();
    echo json_encode(['error' => 'Invalid action']);
    exit;
}
// 使用瞬时缓存减少外部调用压力
$cache_ttl = 1800; // 30分钟
$core_key = 'todo_updates_core';
$plugin_key = 'todo_updates_plugins';

try {
    $core = get_transient($core_key);
    if ($core === false) {
        $core = check_core_updates();
        set_transient($core_key, $core, $cache_ttl);
    }

    $plugins = get_transient($plugin_key);
    if ($plugins === false) {
        $plugins = check_plugin_updates();
        set_transient($plugin_key, $plugins, $cache_ttl);
    }

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'data' => [
            'core' => $core,
            'plugins' => $plugins,
        ]
    ]);
    exit;
} catch (Throwable $e) {
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'error' => '更新检查执行出错',
        'message' => $e->getMessage(),
    ]);
    exit;
}
