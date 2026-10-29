<?php
/**
 * TodoCMS 取消订单AJAX接口
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
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    todo_send_json_error('Direct access denied');
}

// PRG 防重复提交守卫（仅对携带 _submit_key 的 POST 生效）
if (!defined('PREVENT_AJAX_AUTO_RUN')) {
    Todo_PRG_Guard::guard_endpoint('cancel_order', 30);
}

try {
    // 验证CSRF令牌
    if (!Todo_Security_Manager::verify_request_csrf('cancel_order')) {
        throw new Exception(__('安全验证失败'));
    }
    
    $order_id = intval($_POST['order_id'] ?? 0);
    
    if (!$order_id) {
        throw new Exception(__('订单ID无效'));
    }
    
    // 获取订单信息
    $order = Todo_Order_Manager::get_order($order_id);
    if (!$order) {
        throw new Exception(__('订单不存在'));
    }
    
    // 检查订单状态
    if ($order['status'] === Todo_Order_Manager::STATUS_CANCELLED) {
        throw new Exception(__('订单已取消'));
    }
    
    if ($order['payment_status'] === Todo_Order_Manager::PAYMENT_COMPLETED) {
        throw new Exception(__('已支付订单无法取消'));
    }
    
    if ($order['status'] === Todo_Order_Manager::STATUS_SHIPPED) {
        throw new Exception(__('已发货订单无法取消'));
    }
    
    // 检查订单所有者（如果用户已登录）
    $current_user_id = get_current_user_id();
    if ($current_user_id && $order['user_id'] != $current_user_id) {
        throw new Exception(__('无权限操作此订单'));
    }
    
    // 取消订单
    $result = cancel_order($order_id, $order);
    
    if ($result) {
        todo_send_json_success(__('订单已取消'));
    } else {
        throw new Exception(__('取消订单失败'));
    }
    
} catch (Exception $e) {
    todo_send_json_error($e->getMessage());
}

/**
 * 取消订单
 * 
 * @param int $order_id 订单ID
 * @param array $order 订单信息
 * @return bool
 */
function cancel_order($order_id, $order) {
    global $tododb;
    
    try {
        $tododb->query('START TRANSACTION');
        
        $operator_id = function_exists('get_current_user_id') ? get_current_user_id() : 0;
        $update_result = Todo_Order_Manager::cancel_order($order_id, '用户取消订单', $operator_id);
        
        if (!$update_result) {
            throw new Exception('更新订单状态失败');
        }
        
        // 恢复商品库存
        restore_product_stock($order_id);
        
        // 如果有支付记录，处理退款
        if ($order['payment_status'] === Todo_Order_Manager::PAYMENT_PENDING) {
            // 取消待支付的支付记录
            $tododb->update(
                $tododb->prefix . 'payments',
                array(
                    'status' => 'cancelled',
                    'updated_at' => current_time('mysql')
                ),
                array(
                    'order_no' => $order['order_no'],
                    'status' => 'pending'
                ),
                array('%s', '%s'),
                array('%s', '%s')
            );
        }
        
        // 记录订单日志
        Todo_Order_Manager::add_order_log($order_id, 'cancelled', '订单已取消');
        
        $tododb->query('COMMIT');
        return true;
        
    } catch (Exception $e) {
        $tododb->query('ROLLBACK');
        error_log('Cancel order error: ' . $e->getMessage());
        return false;
    }
}

/**
 * 恢复商品库存
 * 
 * @param int $order_id 订单ID
 */
function restore_product_stock($order_id) {
    $order_items = Todo_Order_Manager::get_order_items($order_id);
    if (is_array($order_items)) {
        foreach ($order_items as $item) {
            $product_id = intval($item['product_id'] ?? 0);
            $quantity = intval($item['quantity'] ?? 0);
            if ($product_id <= 0 || $quantity <= 0) continue;

            $specs = $item['specs'] ?? ($item['product_specs'] ?? ($item['spec_name'] ?? ''));
            Todo_Order_Manager::restore_stock($product_id, $quantity, $specs);
        }
    }
}