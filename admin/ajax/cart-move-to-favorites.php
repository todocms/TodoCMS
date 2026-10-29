<?php
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

require_once '../../core/bootstrap.php';

// 检查用户是否登录
if (!is_user_logged_in()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => '请先登录']);
    exit;
}

// 验证请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => '不支持的请求方法']);
    exit;
}

// CSRF验证
if (!todo_verify_csrf_token($_POST['_token'] ?? '', 'cart_move_favorites')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'CSRF验证失败']);
    exit;
}

// 获取参数
$item_id = intval($_POST['item_id'] ?? 0);

if ($item_id <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => '无效的商品ID']);
    exit;
}

try {
    // 获取购物车商品信息
    $cart_item = Todo_Cart_Manager::get_cart_item($item_id);
    if (!$cart_item) {
        throw new Exception('商品不在购物车中');
    }
    
    // 添加到收藏夹
    $favorites_result = Todo_Favorites_Manager::add_to_favorites($cart_item['product_id']);
    if (!$favorites_result) {
        throw new Exception('添加到收藏夹失败');
    }
    
    // 从购物车中移除
    $remove_result = Todo_Cart_Manager::remove_from_cart($item_id);
    if (!$remove_result) {
        throw new Exception('从购物车移除失败');
    }
    
    // 获取更新后的购物车统计
    $cart_stats = Todo_Cart_Manager::get_cart();
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => '商品已移至收藏夹',
        'cart_count' => $cart_stats['count'],
        'cart_total' => $cart_stats['total']
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
