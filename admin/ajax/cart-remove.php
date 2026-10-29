<?php
/**
 * 购物车删除处理器
 * 处理购物车商品删除
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
if (!Todo_Security_Manager::verify_request_csrf('cart_remove', $_POST['_token'] ?? '')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'CSRF验证失败'
    ]);
    exit;
}

try {
    $item_id = $_POST['item_id'] ?? '';
    
    if (empty($item_id)) {
        throw new Exception('商品ID不能为空');
    }
    
    // 从购物车删除商品
    $result = Todo_Cart_Manager::remove_from_cart($item_id);
    
    if ($result) {
        // 获取更新后的购物车信息
        $cart = Todo_Cart_Manager::get_cart();
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => '删除成功',
            'data' => [
                'cart_count' => $cart['count'],
                'cart_total' => number_format($cart['total'], 2)
            ]
        ]);
    } else {
        throw new Exception('删除失败');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
