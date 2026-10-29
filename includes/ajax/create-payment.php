<?php
/**
 * TodoCMS 支付创建AJAX接口
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Direct access denied']);
    exit;
}

// 检查AJAX请求
if (!defined('DOING_AJAX') || !DOING_AJAX) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Direct access denied']);
    exit;
}

// 检查请求头
if (!todo_is_ajax_request()) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(array('success' => false, 'error' => 'Direct access denied'));
    exit;
}

// PRG 防重复提交守卫（仅对携带 _submit_key 的 POST 生效）
if (!defined('PREVENT_AJAX_AUTO_RUN')) {
    Todo_PRG_Guard::guard_endpoint('create_payment', 30);
}

try {
    // 验证CSRF令牌
    if (!Todo_Security_Manager::verify_request_csrf('create_payment', '_payment_nonce')) {
        throw new Exception(__('安全验证失败'));
    }
    
    $order_id = todo_post_int('order_id');
    $payment_method = sanitize_text_field(todo_post_string('payment_method'));
    
    if (!$order_id) {
        throw new Exception(__('订单ID无效'));
    }
    
    if (!$payment_method) {
        throw new Exception(__('请选择支付方式'));
    }
    
    // 获取订单信息
    $order = Todo_Order_Manager::get_order($order_id);
    if (!$order) {
        throw new Exception(__('订单不存在'));
    }
    
    // 检查订单状态
    if ($order['payment_status'] === Todo_Order_Manager::PAYMENT_PAID) {
        throw new Exception(__('订单已支付'));
    }
    
    if ($order['status'] === Todo_Order_Manager::STATUS_CANCELLED) {
        throw new Exception(__('订单已取消'));
    }
    
    // 检查订单所有者（如果用户已登录）
    $current_user_id = get_current_user_id();
    if ($current_user_id && $order['user_id'] != $current_user_id) {
        throw new Exception(__('无权限操作此订单'));
    }
    if (!$current_user_id) {
        if (!function_exists('todo_get_guest_order_ids')) {
            throw new Exception(__('无权限操作此订单'));
        }
        $guest_order_ids = array_map('intval', (array) todo_get_guest_order_ids());
        if (!in_array(intval($order['id'] ?? 0), $guest_order_ids, true)) {
            throw new Exception(__('无权限操作此订单'));
        }
    }
    
    // 验证支付方式
    $available_methods = Todo_Payment_Manager::get_available_methods();
    if (!isset($available_methods[$payment_method])) {
        throw new Exception(__('不支持的支付方式'));
    }
    
    // 特殊处理余额支付
    if ($payment_method === 'balance') {
        if (!$current_user_id) {
            throw new Exception(__('余额支付需要登录'));
        }
        
        // 使用Todo_Payment_Manager的公共方法获取用户余额
        $user_balance = Todo_Payment_Manager::get_user_balance($current_user_id);
        
        if ($user_balance < $order['total_amount']) {
            throw new Exception(__('余额不足'));
        }
    }
    
    // 创建支付
    $payment_result = Todo_Payment_Manager::create_payment(
        $order_id,
        $payment_method,
        array(
            'order_no' => $order['order_no'],
            'total_amount' => $order['total_amount'],
            'user_id' => $order['user_id'],
            'description' => '订单支付 - ' . $order['order_no']
        )
    );
    
    if (!$payment_result['success']) {
        throw new Exception($payment_result['message']);
    }
    
    // 更新订单支付方式
    Todo_Order_Manager::update_order($order_id, array(
        'payment_method' => $payment_method
    ));
    
    // 发送成功响应
    header('Content-Type: application/json');
    echo json_encode(array(
        'success' => true,
        'data' => $payment_result
    ));
    exit;
    
} catch (Exception $e) {
    // 发送错误响应
    header('Content-Type: application/json');
    echo json_encode(array(
        'success' => false,
        'error' => $e->getMessage()
    ));
    exit;
}
