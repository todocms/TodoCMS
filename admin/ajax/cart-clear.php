<?php
/**
 * 购物车清空处理器
 * 处理清空购物车操作
 */

if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}

if (!defined('DOING_AJAX') || !DOING_AJAX || 
    !isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Direct access denied']);
    exit;
}

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

require_once dirname(dirname(__DIR__)) . '/core/bootstrap.php';

require_once ABSPATH . 'core/functions/function-loader.php';
Todo_Function_Loader::load_core_functions();

// CSRF验证
if (!Todo_Security_Manager::verify_request_csrf('cart_clear', $_POST['_token'] ?? '')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'CSRF验证失败'
    ]);
    exit;
}

try {
    // 清空购物车
    $result = Todo_Cart_Manager::clear_cart();
    
    if ($result) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => '购物车已清空',
            'data' => [
                'cart_count' => 0,
                'cart_total' => '0.00'
            ]
        ]);
    } else {
        throw new Exception('清空失败');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
