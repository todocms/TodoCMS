<?php
/**
 * 升级套餐支付状态检查AJAX处理器
 * 
 * @package TodoCMS
 * @subpackage Admin\Ajax
 */

if (!defined('TODO_VERSION')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(array('error' => 'Direct access denied'));
    exit;
}

// 定义AJAX环境
define('DOING_AJAX', true);

// 检查AJAX请求
if (!defined('DOING_AJAX') || !DOING_AJAX || 
    empty($_SERVER['HTTP_X_REQUESTED_WITH']) || 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(array('error' => 'Direct access denied'));
    exit;
}

// 检查用户登录状态
if (!is_user_logged_in()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(array(
        'success' => false,
        'data' => array('message' => '请先登录')
    ));
    exit;
}

// 验证CSRF令牌
if (!Todo_Security_Manager::verify_request_csrf('check_upgrade_payment_status')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(array(
        'success' => false,
        'data' => array('message' => 'CSRF验证失败')
    ));
    exit;
}

try {
    // 获取请求参数
    $out_trade_no = todo_sanitize_text_field($_POST['out_trade_no'] ?? '');
    
    // 验证参数
    if (empty($out_trade_no)) {
        throw new Exception('订单号不能为空');
    }
    
    // 获取当前用户
    $current_user = todo_get_current_user();
    $user_id = todo_get_current_user_id();
    
    // 获取升级套餐管理器
    $package_manager = todo_get_upgrade_package_manager();
    
    // 通过订单号获取订单详情
    $order = $package_manager->get_order_by_trade_no($out_trade_no);
    if (!$order) {
        throw new Exception('订单不存在');
    }
    
    $order_id = $order['id'];
    
    // 检查订单所有权
    if ($order['user_id'] != $user_id) {
        throw new Exception('无权访问此订单');
    }
    
    // 检查订单状态
    $status = $order['status'];
    $payment_status = $order['payment_status'] ?? 'pending';
    
    // 如果订单已完成，直接返回成功
    if ($status === 'completed' && $payment_status === 'paid') {
        header('Content-Type: application/json');
        echo json_encode(array(
            'success' => true,
            'data' => array(
                'status' => 'completed',
                'payment_status' => 'paid',
                'message' => '支付成功，升级已完成',
                'order' => array(
                    'id' => $order['id'],
                    'package_name' => $order['package_name'],
                    'final_price' => $order['final_price'],
                    'completed_at' => $order['completed_at']
                )
            )
        ));
        exit;
    }
    
    // 如果订单失败，返回失败状态
    if ($status === 'failed') {
        header('Content-Type: application/json');
        echo json_encode(array(
            'success' => false,
            'data' => array(
                'status' => 'failed',
                'message' => '支付失败',
                'error_message' => $order['error_message'] ?? '未知错误'
            )
        ));
        exit;
    }
    
    // 如果订单取消，返回取消状态
    if ($status === 'cancelled') {
        header('Content-Type: application/json');
        echo json_encode(array(
            'success' => false,
            'data' => array(
                'status' => 'cancelled',
                'message' => '订单已取消'
            )
        ));
        exit;
    }
    
    // 对于处理中的订单，检查支付状态
    if ($status === 'processing' || $status === 'pending') {
        // 获取支付管理器
        if (class_exists('Todo_Payment_Manager')) {
            $payment_manager = new Todo_Payment_Manager();
            
            // 查询支付状态
            $payment_result = $payment_manager->query_payment_status(
                $order_id, 
                'upgrade_package',
                $order['payment_method'] ?? ''
            );
            
            if ($payment_result && isset($payment_result['status'])) {
                switch ($payment_result['status']) {
                    case 'paid':
                        // 支付成功，处理升级
                        $upgrade_result = $package_manager->process_package_purchase_completed($order_id);
                        
                        if ($upgrade_result) {
                            header('Content-Type: application/json');
                            echo json_encode(array(
                                'success' => true,
                                'data' => array(
                                    'status' => 'completed',
                                    'payment_status' => 'paid',
                                    'message' => '支付成功，升级已完成',
                                    'order' => array(
                                        'id' => $order['id'],
                                        'package_name' => $order['package_name'],
                                        'final_price' => $order['final_price']
                                    )
                                )
                            ));
                        } else {
                            throw new Exception('升级处理失败');
                        }
                        break;
                        
                    case 'failed':
                        // 支付失败
                        $package_manager->update_order_status($order_id, 'failed', array(
                            'error_message' => $payment_result['message'] ?? '支付失败'
                        ));
                        
                        header('Content-Type: application/json');
                        echo json_encode(array(
                            'success' => false,
                            'data' => array(
                                'status' => 'failed',
                                'message' => '支付失败',
                                'error_message' => $payment_result['message'] ?? '支付失败'
                            )
                        ));
                        break;
                        
                    case 'pending':
                    case 'processing':
                    default:
                        // 支付处理中
                        header('Content-Type: application/json');
                        echo json_encode(array(
                            'success' => true,
                            'data' => array(
                                'status' => 'processing',
                                'payment_status' => 'pending',
                                'message' => '支付处理中，请稍候...'
                            )
                        ));
                        break;
                }
            } else {
                // 无法查询支付状态，返回处理中
                header('Content-Type: application/json');
                echo json_encode(array(
                    'success' => true,
                    'data' => array(
                        'status' => 'processing',
                        'payment_status' => 'pending',
                        'message' => '支付处理中，请稍候...'
                    )
                ));
            }
        } else {
            throw new Exception('支付系统未初始化');
        }
    } else {
        // 未知状态
        header('Content-Type: application/json');
        echo json_encode(array(
            'success' => false,
            'data' => array(
                'status' => $status,
                'message' => '订单状态异常'
            )
        ));
    }
    
} catch (Exception $e) {
    // 记录错误日志
    todo_log_error('升级套餐支付状态检查失败: ' . $e->getMessage());
    
    // 返回错误结果
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(array(
        'success' => false,
        'data' => array(
            'message' => $e->getMessage()
        )
    ));
}

exit;