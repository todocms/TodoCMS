<?php
/**
 * TodoCMS 演示登录入口
 *
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
require_once ABSPATH . 'config.php';
require_once ABSPATH . 'vendor/autoload.php';
require_once ABSPATH . 'core/bootstrap.php';

if (!(defined('TODO_DEMO_ADMIN_ENABLED') && TODO_DEMO_ADMIN_ENABLED)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Robots-Tag: noindex, nofollow', true);
    echo '404 Not Found';
    exit;
}

if (!defined('TODO_DEMO_LOGIN_PROXY')) {
    define('TODO_DEMO_LOGIN_PROXY', true);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
    if ($action === 'login') {
        // 加载安全函数（用于暴力破解防护）
        if (class_exists('Todo_Function_Loader')) {
            Todo_Function_Loader::load_function_group('security');
        }

        $demo_username = defined('TODO_DEMO_ADMIN_USERNAME') ? (string) TODO_DEMO_ADMIN_USERNAME : '';
        $demo_password = defined('TODO_DEMO_ADMIN_PASSWORD') ? (string) TODO_DEMO_ADMIN_PASSWORD : '';
        $username = isset($_POST['username']) ? trim((string) $_POST['username']) : '';
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';

        // 暴力破解防护
        $client_ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
        $lockout = function_exists('todo_check_login_lockout') ? todo_check_login_lockout($username, $client_ip) : false;
        if ($lockout) {
            $back = todo_admin_url('login.php');
            $back = add_query_arg(['login_message' => urlencode("尝试次数过多，请在 {$lockout['wait_minutes']} 分钟后再试")], $back);
            header('Location: ' . $back);
            exit;
        }

        $ok_user = ($demo_username !== '' && hash_equals($demo_username, $username));
        $ok_pass = ($demo_password !== '' && hash_equals($demo_password, $password));

        // 记录登录尝试
        if (function_exists('todo_register_login_attempt')) {
            todo_register_login_attempt($username, $client_ip, ($ok_user && $ok_pass));
        }

        if (!$ok_user || !$ok_pass) {
            $back = todo_admin_url('login.php');
            $back = add_query_arg(['login_message' => urlencode('该入口仅供体验账号登录')], $back);
            header('Location: ' . $back);
            exit;
        }
    } elseif (in_array($action, ['get_captcha', 'verify_captcha'], true)) {
        // 允许这些 AJAX 操作通过，以便在 demo 模式下显示和验证验证码
    } else {
        $accept = isset($_SERVER['HTTP_ACCEPT']) ? strtolower((string) $_SERVER['HTTP_ACCEPT']) : '';
        $want_json = strpos($accept, 'application/json') !== false || strpos($accept, '+json') !== false;
        http_response_code(403);
        if ($want_json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => '体验账号入口仅支持登录'], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/plain; charset=UTF-8');
            echo '403 Forbidden';
        }
        exit;
    }
}

// 安全增强：动态获取当前的后台入口文件名（以适配每周自动轮换功能）
$admin_entry = function_exists('todo_get_admin_entry_filename') ? todo_get_admin_entry_filename() : get_option('site_adminbasefile');
$file = ADMIN_DIR . '/' . $admin_entry . '.php';

if (file_exists($file)) {
    include $file;
    exit;
}

http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow', true);
echo '404 Not Found';
exit;
