<?php
/**
 * 升级套餐支付钩子处理
 * 
 * @package TodoCMS
 * @subpackage Core\Hooks
 */

// 防止直接访问
if (!defined('TODO_VERSION')) {
    exit('Direct access denied.');
}

/**
 * 初始化升级套餐支付钩子
 */
function todo_init_upgrade_package_payment_hooks() {
    // 支付成功钩子
    todo_add_action('todo_payment_success', 'todo_handle_upgrade_package_payment_success', 10, 3);
    
    // 支付失败钩子
    todo_add_action('todo_payment_failed', 'todo_handle_upgrade_package_payment_failed', 10, 3);
}

/**
 * 处理升级套餐支付成功
 * 
 * @param int $order_id 订单ID
 * @param string $payment_method 支付方式
 * @param array $payment_data 支付数据
 */
function todo_handle_upgrade_package_payment_success($order_id, $payment_method, $payment_data = array()) {
    try {
        // 获取升级套餐管理器
        $package_manager = todo_get_upgrade_package_manager();
        if (!$package_manager) {
            todo_log_error('升级套餐管理器未初始化');
            return;
        }
        
        // 获取订单详情
        $order = $package_manager->get_order_detail($order_id);
        if (!$order) {
            todo_log_error('升级套餐订单不存在: ' . $order_id);
            return;
        }
        
        // 检查是否为升级套餐订单
        if (empty($order['package_id'])) {
            // 不是升级套餐订单，跳过处理
            return;
        }
        
        // 检查订单状态
        if ($order['status'] === 'completed') {
            todo_log_info('升级套餐订单已完成，跳过处理: ' . $order_id);
            return;
        }
        
        // 处理升级套餐购买完成
        $result = $package_manager->process_package_purchase_completed($order_id);
        
        if ($result) {
            todo_log_info(sprintf(
                '升级套餐支付成功处理完成 - 订单ID: %d, 用户ID: %d, 套餐: %s',
                $order_id,
                $order['user_id'],
                $order['package_name']
            ));
            
            // 触发升级完成钩子
            todo_do_action('todo_upgrade_package_completed', $order_id, $order['user_id'], $order['package_id']);
            
            // 发送升级成功通知
            todo_send_upgrade_success_notification($order);
            
        } else {
            todo_log_error('升级套餐处理失败: ' . $order_id);
            
            // 更新订单状态为失败
            $package_manager->update_order_status($order_id, 'failed', array(
                'error_message' => '升级处理失败'
            ));
        }
        
    } catch (Exception $e) {
        todo_log_error('升级套餐支付成功处理异常: ' . $e->getMessage());
    }
}

/**
 * 处理升级套餐支付失败
 * 
 * @param int $order_id 订单ID
 * @param string $payment_method 支付方式
 * @param array $payment_data 支付数据
 */
function todo_handle_upgrade_package_payment_failed($order_id, $payment_method, $payment_data = array()) {
    try {
        // 获取升级套餐管理器
        $package_manager = todo_get_upgrade_package_manager();
        if (!$package_manager) {
            return;
        }
        
        // 获取订单详情
        $order = $package_manager->get_order_detail($order_id);
        if (!$order || empty($order['package_id'])) {
            // 不是升级套餐订单，跳过处理
            return;
        }
        
        // 更新订单状态为失败
        $error_message = '支付失败';
        if (!empty($payment_data['message'])) {
            $error_message = $payment_data['message'];
        }
        
        $package_manager->update_order_status($order_id, 'failed', array(
            'error_message' => $error_message
        ));
        
        todo_log_info(sprintf(
            '升级套餐支付失败 - 订单ID: %d, 用户ID: %d, 套餐: %s, 原因: %s',
            $order_id,
            $order['user_id'],
            $order['package_name'],
            $error_message
        ));
        
        // 触发升级失败钩子
        todo_do_action('todo_upgrade_package_failed', $order_id, $order['user_id'], $order['package_id'], $error_message);
        
    } catch (Exception $e) {
        todo_log_error('升级套餐支付失败处理异常: ' . $e->getMessage());
    }
}

/**
 * 发送升级成功通知
 * 
 * @param array $order 订单信息
 */
function todo_send_upgrade_success_notification($order) {
    try {
        // 获取用户信息
        $user = todo_get_user_by('id', $order['user_id']);
        if (!$user) {
            return;
        }
        
        // 获取套餐信息
        $package_manager = todo_get_upgrade_package_manager();
        $package = $package_manager->get_package($order['package_id']);
        if (!$package) {
            return;
        }
        
        // 获取用户新等级信息
        $new_level = todo_get_user_level($order['user_id']);
        $cfg_sym = function_exists('todo_get_currency_config') ? (todo_get_currency_config()['symbol'] ?? '') : '';
        $formatted_price = function_exists('todo_format_price') ? todo_format_price($order['final_price']) : ($cfg_sym . sprintf('%.2f', $order['final_price']));
        
        // 构建通知内容
        $subject = '会员升级成功通知';
        $message = sprintf(
            "尊敬的 %s，\n\n" .
            "恭喜您成功升级为 %s！\n\n" .
            "升级详情：\n" .
            "- 升级套餐：%s\n" .
            "- 支付金额：%s\n" .
            "- 升级时间：%s\n" .
            "- 订单号：%s\n\n" .
            "感谢您的支持！\n\n" .
            "此邮件为系统自动发送，请勿回复。",
            $user->display_name,
            $new_level ? $new_level['name'] : '高级会员',
            $package['name'],
            $formatted_price,
            $order['completed_at'] ?? current_time('mysql'),
            $order['order_no']
        );
        
        // 发送邮件通知（如果邮件功能可用）
        if (function_exists('todo_mail')) {
            todo_mail($user->user_email, $subject, $message);
        }
        
        // 添加站内消息（如果消息系统可用）
        if (function_exists('todo_add_user_message')) {
            todo_add_user_message($order['user_id'], $subject, $message, 'system');
        }
        
    } catch (Exception $e) {
        todo_log_error('发送升级成功通知失败: ' . $e->getMessage());
    }
}

// 初始化钩子
todo_init_upgrade_package_payment_hooks();