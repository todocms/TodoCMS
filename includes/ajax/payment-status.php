<?php
/**
 * TodoCMS 支付状态查询AJAX接口
 * 
 * 用于前端轮询查询支付状态
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access denied.');
}

$security = Todo_Security_Manager::getInstance();
if (!$security->verify_request_csrf()) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: application/json');
    header('X-Robots-Tag: noindex, nofollow', true);
    echo json_encode(['error' => 'CSRF verification failed']);
    exit;
}

if (!defined('DOING_AJAX')) {
    if (!defined('DOING_AJAX')) {
        define('DOING_AJAX', true);
    }
}

// 检查AJAX请求
if (!defined('DOING_AJAX') || !DOING_AJAX || !todo_is_ajax_request()) {
    http_response_code(403);
    echo json_encode(array('error' => 'Direct access denied'));
    exit;
}

// 加载核心文件
require_once dirname(dirname(__DIR__)) . '/core/bootstrap.php';

// 设置响应头
header('Content-Type: application/json; charset=utf-8');

try {
    // 检查用户登录状态
    if (!is_user_logged_in()) {
        throw new Exception(__('用户未登录'));
    }
    
    // 获取当前用户ID
    $current_user_id = todo_get_current_user_id();
    
    // 获取请求参数
    $action = todo_post_string('action');
    $order_id = todo_post_int('order_id');
    $gateway_order_id = todo_post_string('gateway_order_id');
    
    // 验证参数
    if (empty($action)) {
        throw new Exception(__('缺少操作参数'));
    }
    
    if ($order_id <= 0) {
        throw new Exception(__('无效的订单ID'));
    }
    
    // 获取订单信息
    $order = Todo_Order_Manager::get_order($order_id);
    if (!$order) {
        throw new Exception(__('订单不存在'));
    }
    
    // 检查订单所有者
    if ($order['user_id'] != $current_user_id) {
        throw new Exception(__('无权限查看此订单'));
    }
    
    // 初始化支付管理器
    Todo_Payment_Manager::init();
    
    $response = array('success' => false);
    
    switch ($action) {
        case 'check_status':
            $response = handle_check_status($order, $gateway_order_id);
            break;
            
        case 'cancel_payment':
            $response = handle_cancel_payment($order);
            break;
            
        case 'retry_payment':
            $response = handle_retry_payment($order);
            break;
            
        default:
            throw new Exception(__('未知的操作类型'));
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    echo json_encode(array(
        'success' => false,
        'message' => $e->getMessage()
    ));
}

/**
 * 处理支付状态查询
 * 
 * @param array $order 订单信息
 * @param string $gateway_order_id 支付网关订单号
 * @return array
 */
function handle_check_status($order, $gateway_order_id) {
    // 首先检查本地订单状态
    if ($order['payment_status'] === Todo_Order_Manager::PAYMENT_PAID) {
        return array(
            'success' => true,
            'paid' => true,
            'message' => __('订单已支付')
        );
    }
    
    // 如果本地状态未更新，查询支付网关状态
    if (!empty($gateway_order_id)) {
        $query_result = Todo_Payment_Manager::query_payment_status($gateway_order_id);
        
        if ($query_result['success'] && isset($query_result['status'])) {
            if ($query_result['status'] === 'success') {
                // 支付网关显示已支付，但本地未更新，尝试手动更新
                $payment_data = array(
                    'amount' => $order['total_amount'],
                    'method' => $order['payment_method'] ?? 'payjs_wechat',
                    'gateway_order_id' => $gateway_order_id
                );
                
                if (Todo_Payment_Manager::handle_payment_success($order['id'], $payment_data)) {
                    return array(
                        'success' => true,
                        'paid' => true,
                        'message' => __('支付状态已更新')
                    );
                }
            }
        }
    }
    
    return array(
        'success' => true,
        'paid' => false,
        'message' => __('等待支付')
    );
}

/**
 * 处理取消支付
 * 
 * @param array $order 订单信息
 * @return array
 */
function handle_cancel_payment($order) {
    // 检查订单状态
    if ($order['payment_status'] === Todo_Order_Manager::PAYMENT_PAID) {
        return array(
            'success' => false,
            'message' => __('订单已支付')
        );
    }
    
    if ($order['status'] === Todo_Order_Manager::STATUS_CANCELLED) {
        return array(
            'success' => false,
            'message' => __('订单已取消')
        );
    }
    
    // 更新订单状态为已取消
    $update_data = array(
        'status' => Todo_Order_Manager::STATUS_CANCELLED,
        'payment_status' => Todo_Order_Manager::PAYMENT_CANCELLED
    );
    
    if (Todo_Order_Manager::update_order($order['id'], $update_data)) {
        // 记录订单日志
        Todo_Order_Manager::add_order_log($order['id'], 'payment_cancelled', '用户取消支付', $order['user_id']);
        
        return array(
            'success' => true,
            'message' => __('订单已取消')
        );
    } else {
        return array(
            'success' => false,
            'message' => __('取消订单失败')
        );
    }
}

/**
 * 处理重新支付
 * 
 * @param array $order 订单信息
 * @return array
 */
function handle_retry_payment($order) {
    // 检查订单状态
    if ($order['payment_status'] === Todo_Order_Manager::PAYMENT_PAID) {
        return array(
            'success' => false,
            'message' => __('订单已支付')
        );
    }
    
    if ($order['status'] === Todo_Order_Manager::STATUS_CANCELLED) {
        return array(
            'success' => false,
            'message' => __('订单已取消')
        );
    }
    
    // 检查订单是否过期（可选）
    $order_expire_hours = todo_get_option('order_expire_hours', 24);
    if ($order_expire_hours > 0) {
        $expire_time = strtotime($order['created_at']) + ($order_expire_hours * 3600);
        if (time() > $expire_time) {
            return array(
                'success' => false,
                'message' => __('订单已过期')
            );
        }
    }
    
    // 生成支付URL
    $payment_url = todo_get_site_url() . '/payment.php?order_id=' . $order['id'];
    
    return array(
        'success' => true,
        'payment_url' => $payment_url,
        'message' => __('可以重新支付')
    );
}
