<?php
/**
 * CSRF令牌获取处理器
 * 为前端提供CSRF令牌获取接口
 */

if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}

if (
    !defined('DOING_AJAX') || !DOING_AJAX ||
    !isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'
) {
    http_response_code(403);
    echo json_encode(['error' => 'Direct access denied']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// 前台会话：强制使用独立会话名，避免被 /admin 路径误判为后台会话
$__todo_frontend_session_name = 'TODOCMS_FRONTENDSESSID';
if (session_status() === PHP_SESSION_ACTIVE) {
    if (session_name() !== $__todo_frontend_session_name) {
        session_write_close();
        session_name($__todo_frontend_session_name);
        @session_start();
    }
} else {
    session_name($__todo_frontend_session_name);
}

// 加载核心文件（确保 Todo_Security_Manager / session / autoload 可用）
require_once dirname(dirname(dirname(__FILE__))) . '/core/bootstrap.php';

// 初始化安全管理器
Todo_Security_Manager::init();

/**
 * 获取CSRF令牌
 */
function todo_ajax_get_csrf_token()
{
    try {
        $action = isset($_POST['action']) ? sanitize_text_field($_POST['action']) : '';

        if (empty($action)) {
            echo json_encode([
                'success' => false,
                'message' => '缺少动作参数'
            ]);
            return;
        }

        // 验证动作名称
        $allowed_actions = [
            'add_to_cart',
            'buy_now',
            'update_cart',
            'remove_from_cart',
            'clear_cart',
            'get_cart',
            'create_order',
            'update_order',
            'delete_order',
            'get_product_info'
        ];

        if (!in_array($action, $allowed_actions)) {
            echo json_encode([
                'success' => false,
                'message' => '无效的动作'
            ]);
            return;
        }

        // 生成CSRF令牌（使用统一安全管理器）
        $token = Todo_Security_Manager::generate_csrf_token($action);

        echo json_encode([
            'success' => true,
            'token' => $token,
            'action' => $action,
            'expires' => time() + 3600 // 1小时后过期
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '生成令牌失败：' . $e->getMessage()
        ]);
    }
}

/**
 * 验证CSRF令牌
 */
function todo_ajax_verify_csrf_token()
{
    try {
        $action = isset($_POST['action']) ? sanitize_text_field($_POST['action']) : '';
        $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';

        if (empty($action) || empty($token)) {
            echo json_encode([
                'success' => false,
                'message' => '缺少必要参数'
            ]);
            return;
        }

        // 验证CSRF令牌（使用统一安全管理器）
        $is_valid = Todo_Security_Manager::verify_csrf_token($token, $action);

        echo json_encode([
            'success' => true,
            'valid' => $is_valid,
            'action' => $action
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '验证令牌失败：' . $e->getMessage()
        ]);
    }
}

// 处理请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request_action = isset($_POST['request_action']) ? $_POST['request_action'] : 'get_token';

    switch ($request_action) {
        case 'get_token':
            todo_ajax_get_csrf_token();
            break;

        case 'verify_token':
            todo_ajax_verify_csrf_token();
            break;

        default:
            // 默认获取令牌
            todo_ajax_get_csrf_token();
            break;
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

/**
 * 清理文本字段
 */
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text)
    {
        return trim(strip_tags($text));
    }
}
?>
