<?php
/**
 * TodoCMS 确认收货AJAX接口
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

/**
 * 处理确认收货请求
 */
function todo_handle_confirm_receive() {
    try {
        // CSRF验证
        if (!todo_verify_nonce($_POST['_todononce'] ?? '', 'confirm_receive')) {
            todo_send_json_error(__('安全验证失败'));
            return;
        }

        // 检查用户登录
        if (!is_user_logged_in()) {
            todo_send_json_error(__('请先登录'));
            return;
        }

        $current_user_id = get_current_user_id();
        $order_id = intval($_POST['order_id'] ?? 0);

        if (!$order_id) {
            todo_send_json_error(__('订单ID不能为空'));
            return;
        }

        // 获取订单信息
        global $tododb;
        $order = $tododb->get_row(
            "SELECT * FROM {$tododb->prefix}orders WHERE id = ? AND user_id = ?",
            [$order_id, $current_user_id],
            'ARRAY_A'
        );

        if (!$order) {
            todo_send_json_error(__('订单不存在或无权限'));
            return;
        }

        // 检查订单状态
        if ($order['status'] !== Todo_Order_Manager::STATUS_SHIPPED) {
            todo_send_json_error(__('只有已发货的订单才能确认收货'));
            return;
        }

        // 确认收货
        $result = confirm_order_receive($order_id);
        
        if ($result) {
            todo_send_json_success(__('确认收货成功'));
        } else {
            todo_send_json_error(__('确认收货失败'));
        }

    } catch (Exception $e) {
        error_log('Confirm receive error: ' . $e->getMessage());
        todo_send_json_error(__('系统错误'));
    }
}

/**
 * 确认收货处理
 * 
 * @param int $order_id 订单ID
 * @return bool
 */
function confirm_order_receive($order_id) {
    global $tododb;
    
    try {
        $tododb->query('START TRANSACTION');
        
        $current_time = current_time('mysql');
        
        // 更新订单状态
        $updated = $tododb->update(
            $tododb->prefix . 'orders',
            array(
                'status' => Todo_Order_Manager::STATUS_COMPLETED,
                'completed_at' => $current_time,
                'updated_at' => $current_time
            ),
            array('id' => $order_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
        
        if ($updated === false) {
            throw new Exception('更新订单状态失败');
        }
        
        // 记录订单日志
        $log_inserted = $tododb->insert(
            $tododb->prefix . 'order_logs',
            array(
                'order_id' => $order_id,
                'action' => 'confirm_receive',
                'message' => '用户确认收货，订单完成',
                'operator_type' => 'user',
                'operator_id' => get_current_user_id(),
                'created_at' => $current_time
            ),
            array('%d', '%s', '%s', '%s', '%d', '%s')
        );
        
        if ($log_inserted === false) {
            throw new Exception('记录订单日志失败');
        }
        
        // 扣减订单消费/抵扣的积分
        if (class_exists('Todo_Order_Manager')) {
            Todo_Order_Manager::process_order_points_deduction($order_id);
        }
        
        // 触发订单完成钩子
        do_action('todo_order_completed', $order_id);
        
        $tododb->query('COMMIT');
        return true;
        
    } catch (Exception $e) {
        $tododb->query('ROLLBACK');
        error_log('Confirm receive error: ' . $e->getMessage());
        return false;
    }
}

// 注册AJAX处理函数
add_action('todocms_ajax_confirm_receive', 'todo_handle_confirm_receive');
add_action('todocms_ajax_nopriv_confirm_receive', function() {
    todo_send_json_error(__('请先登录'));
});
?>