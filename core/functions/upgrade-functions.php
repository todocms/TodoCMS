<?php
/**
 * 会员升级系统核心函数
 * 
 * @package TodoCMS
 * @subpackage Core\Functions
 */

if (!defined('TODO_LOADED')) {
    exit('Direct access denied');
}

// 注意：todo_get_upgrade_packages() 函数已移至 class-upgrade-package-manager.php 中

/**
 * 根据ID获取升级套餐
 * 
 * @param int $package_id 套餐ID
 * @return array|null 套餐信息
 */
// 注意：todo_get_upgrade_package() 函数已移至 class-upgrade-package-manager.php 中
// 请使用类中的统一封装方法：todo_get_upgrade_package_manager()->get_upgrade_package($package_id)

// 注意：todo_get_user_level() 函数已移至 class-user-level-manager.php 中

/**
 * 获取下一个用户等级
 * 
 * @param int $current_level 当前等级
 * @return array|null 下一等级信息
 */
function todo_get_next_user_level($current_level) {
    global $tododb;
    
    $table_name = $tododb->prefix . 'todo_levels';
    
    $next_level = $tododb->get_row(
        "SELECT * FROM {$table_name} 
         WHERE level > ? AND status = 'active' 
         ORDER BY level ASC LIMIT 1",
        [$current_level],
        ARRAY_A
    );
    
    return $next_level;
}

/**
 * 获取用户累计消费金额
 * 
 * @param int $user_id 用户ID
 * @return float 累计消费金额
 */
function todo_get_user_total_spent($user_id) {
    global $tododb;
    
    $table_name = $tododb->prefix . 'todo_orders';
    
    $total = $tododb->get_var(
        "SELECT SUM(amount) FROM {$table_name} 
         WHERE user_id = ? AND status = 'completed'",
        [$user_id]
    );
    
    return floatval($total);
}

/**
 * 获取用户升级历史
 * 
 * @param int $user_id 用户ID
 * @param int $limit 限制数量
 * @return array 升级历史
 */
function todo_get_user_upgrade_history($user_id, $limit = 10) {
    global $tododb;
    
    $table_name = $tododb->prefix . 'todo_orders';
    
    $history = $tododb->get_results(
        "SELECT o.*, p.name as package_name 
         FROM {$table_name} o 
         LEFT JOIN {$tododb->prefix}todo_upgrade_packages p ON o.package_id = p.id 
         WHERE o.user_id = ? AND o.order_type = 'upgrade' 
         ORDER BY o.created_at DESC LIMIT ?",
        [$user_id, $limit],
        ARRAY_A
    );
    
    return $history ?: [];
}

/**
 * 创建升级订单
 * 
 * @param int $user_id 用户ID
 * @param int $package_id 套餐ID
 * @param array $extra_data 额外数据
 * @return array|Todo_Error 订单信息或错误
 */
function todo_create_upgrade_order($user_id, $package_id, $extra_data = []) {
    global $tododb;
    
    // 获取套餐信息
    $package = todo_get_upgrade_package($package_id);
    if (!$package) {
        return new Todo_Error('invalid_package', '套餐不存在');
    }
    
    if ($package['status'] !== 'active') {
        return new Todo_Error('package_inactive', '套餐已停用');
    }
    
    // 检查用户购买限制
    $purchase_limit = todo_check_user_purchase_limit($user_id, $package_id);
    if (is_todo_error($purchase_limit)) {
        return $purchase_limit;
    }
    
    // 生成订单号
    $order_no = todo_generate_order_no('UP');
    
    // 准备订单数据
    $order_data = [
        'order_no' => $order_no,
        'user_id' => $user_id,
        'order_type' => 'upgrade',
        'package_id' => $package_id,
        'package_name' => $package['name'],
        'amount' => $package['price'],
        'status' => 'pending',
        'payment_method' => '',
        'payment_data' => json_encode($extra_data),
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql')
    ];
    
    // 插入订单
    $table_name = $tododb->prefix . 'todo_orders';
    $result = $tododb->insert($table_name, $order_data);
    
    if ($result === false) {
        return new Todo_Error('order_create_failed', '订单创建失败');
    }
    
    $order_id = $tododb->insert_id;
    $order_data['id'] = $order_id;
    
    // 记录日志
    todo_log_upgrade_action($user_id, 'order_created', [
        'order_id' => $order_id,
        'order_no' => $order_no,
        'package_id' => $package_id,
        'amount' => $package['price']
    ]);
    
    return $order_data;
}

/**
 * 处理升级订单支付成功
 * 
 * @param string $order_no 订单号
 * @param string $payment_method 支付方式
 * @param array $payment_data 支付数据
 * @return bool|Todo_Error 处理结果
 */
function todo_process_upgrade_payment_success($order_no, $payment_method, $payment_data = []) {
    global $tododb;
    
    // 开始事务
    $tododb->query('START TRANSACTION');
    
    try {
        // 获取订单信息
        $table_name = $tododb->prefix . 'todo_orders';
        $order = $tododb->get_row(
            "SELECT * FROM {$table_name} WHERE order_no = ?",
            [$order_no],
            ARRAY_A
        );
        
        if (!$order) {
            throw new Exception('订单不存在');
        }
        
        if ($order['status'] !== 'pending') {
            throw new Exception('订单状态异常');
        }
        
        // 获取套餐信息
        $package = todo_get_upgrade_package($order['package_id']);
        if (!$package) {
            throw new Exception('套餐不存在');
        }
        
        // 更新订单状态
        $update_result = $tododb->update(
            $table_name,
            [
                'status' => 'completed',
                'payment_method' => $payment_method,
                'payment_data' => json_encode($payment_data),
                'paid_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['id' => $order['id']]
        );
        
        if ($update_result === false) {
            throw new Exception('订单更新失败');
        }
        
        // 处理用户等级升级
        $upgrade_result = todo_upgrade_user_level($order['user_id'], $package);
        if (is_todo_error($upgrade_result)) {
            throw new Exception($upgrade_result->get_error_message());
        }
        
        // 提交事务
        $tododb->query('COMMIT');
        
        // 记录日志
        todo_log_upgrade_action($order['user_id'], 'payment_success', [
            'order_id' => $order['id'],
            'order_no' => $order_no,
            'payment_method' => $payment_method,
            'amount' => $order['amount']
        ]);
        
        // 发送通知
        todo_send_upgrade_notification($order['user_id'], $package, $order);
        
        return true;
        
    } catch (Exception $e) {
        // 回滚事务
        $tododb->query('ROLLBACK');
        
        // 记录错误日志
        todo_log_upgrade_action($order['user_id'] ?? 0, 'payment_failed', [
            'order_no' => $order_no,
            'error' => $e->getMessage()
        ]);
        
        return new Todo_Error('payment_process_failed', $e->getMessage());
    }
}

/**
 * 升级用户等级
 * 
 * @param int $user_id 用户ID
 * @param array $package 套餐信息
 * @return bool|Todo_Error 升级结果
 */
function todo_upgrade_user_level($user_id, $package) {
    global $tododb;
    
    // 获取当前用户等级
    $current_level = todo_get_user_level($user_id);
    
    // 计算新的到期时间
    $expire_time = null;
    if ($package['duration'] > 0) {
        $current_expire = $current_level['expire_time'] ?? null;
        if ($current_expire && strtotime($current_expire) > time()) {
            // 在当前到期时间基础上延长
            $expire_time = date('Y-m-d H:i:s', strtotime($current_expire . ' +' . $package['duration'] . ' days'));
        } else {
            // 从现在开始计算
            $expire_time = date('Y-m-d H:i:s', time() + ($package['duration'] * 86400));
        }
    }
    
    // 停用当前等级
    if (!empty($current_level['id'])) {
        $tododb->update(
            $tododb->prefix . 'todo_user_levels',
            ['status' => 'inactive', 'updated_at' => current_time('mysql')],
            ['id' => $current_level['id']]
        );
    }
    
    // 创建新的用户等级记录
    $level_data = [
        'user_id' => $user_id,
        'level_id' => $package['level_id'],
        'level' => $package['level'],
        'expire_time' => $expire_time,
        'status' => 'active',
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql')
    ];
    
    $result = $tododb->insert(
        $tododb->prefix . 'todo_user_levels',
        $level_data
    );
    
    if ($result === false) {
        return new Todo_Error('level_upgrade_failed', '等级升级失败');
    }
    
    return true;
}

/**
 * 检查用户购买限制
 * 
 * @param int $user_id 用户ID
 * @param int $package_id 套餐ID
 * @return bool|Todo_Error 检查结果
 */
function todo_check_user_purchase_limit($user_id, $package_id) {
    global $tododb;
    
    $package = todo_get_upgrade_package($package_id);
    if (!$package) {
        return new Todo_Error('invalid_package', '套餐不存在');
    }
    
    // 检查是否有未完成的订单
    $pending_orders = $tododb->get_var(
        "SELECT COUNT(*) FROM {$tododb->prefix}todo_orders 
         WHERE user_id = ? AND package_id = ? AND status = 'pending'",
        [$user_id, $package_id]
    );
    
    if ($pending_orders > 0) {
        return new Todo_Error('pending_order_exists', '您有未完成的订单，请先完成支付或取消订单');
    }
    
    // 检查购买频率限制（24小时内不能重复购买同一套餐）
    $recent_orders = $tododb->get_var(
        "SELECT COUNT(*) FROM {$tododb->prefix}todo_orders 
         WHERE user_id = ? AND package_id = ? 
         AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
        [$user_id, $package_id]
    );
    
    if ($recent_orders > 0) {
        return new Todo_Error('purchase_too_frequent', '24小时内不能重复购买同一套餐');
    }
    
    return true;
}

/**
 * 生成订单号
 * 
 * @param string $prefix 前缀
 * @return string 订单号
 */
function todo_generate_order_no($prefix = 'DX') {
    // 更长、更不易重复：时间戳(14位) + 6位随机数，可选前缀
    return $prefix . date('YmdHis') . str_pad(todo_rand(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * 记录升级操作日志
 * 
 * @param int $user_id 用户ID
 * @param string $action 操作类型
 * @param array $data 操作数据
 */
function todo_log_upgrade_action($user_id, $action, $data = []) {
    global $tododb;
    
    $log_data = [
        'user_id' => $user_id,
        'action' => $action,
        'data' => json_encode($data),
        'ip_address' => todo_get_client_ip(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'created_at' => current_time('mysql')
    ];
    
    $tododb->insert(
        $tododb->prefix . 'todo_upgrade_logs',
        $log_data
    );
}

/**
 * 发送升级通知
 * 
 * @param int $user_id 用户ID
 * @param array $package 套餐信息
 * @param array $order 订单信息
 */
function todo_send_upgrade_notification($user_id, $package, $order) {
    $user = get_userdata($user_id);
    if (!$user) {
        return;
    }
    
    // 发送邮件通知
    $subject = '会员升级成功通知';
    $message = sprintf(
        "尊敬的 %s，\n\n" .
        "恭喜您成功升级为 %s！\n\n" .
        "订单信息：\n" .
        "订单号：%s\n" .
        "套餐名称：%s\n" .
        "支付金额：%s\n" .
        "升级时间：%s\n\n" .
        "感谢您的支持！\n\n" .
        "%s",
        $user->display_name,
        $package['name'],
        $order['order_no'],
        $package['name'],
        function_exists('todo_format_price') ? todo_format_price($order['amount']) : number_format($order['amount'], 2),
        $order['paid_at'],
        get_bloginfo('name')
    );
    
    todo_mail($user->user_email, $subject, $message);
    
    // 创建站内通知
    todo_create_user_notification($user_id, [
        'type' => 'upgrade_success',
        'title' => '会员升级成功',
        'content' => sprintf('恭喜您成功升级为 %s！', $package['name']),
        'data' => [
            'package_id' => $package['id'],
            'order_id' => $order['id']
        ]
    ]);
}

/**
 * 获取订单状态文本
 * 
 * @param string $status 状态代码
 * @return string 状态文本
 */
function todo_get_order_status_text($status) {
    $status_texts = [
        'pending' => '待支付',
        'completed' => '已完成',
        'cancelled' => '已取消',
        'refunded' => '已退款',
        'failed' => '支付失败'
    ];
    
    return $status_texts[$status] ?? '未知状态';
}

/**
 * 检查是否有升级通知
 * 
 * @param int $user_id 用户ID
 * @return bool 是否有通知
 */
function todo_has_upgrade_notification($user_id) {
    // 检查是否有新的升级套餐或优惠活动
    $last_check = get_user_meta($user_id, 'last_upgrade_check', true);
    $latest_package = todo_get_latest_upgrade_package();
    
    if (!$last_check || !$latest_package) {
        return false;
    }
    
    return strtotime($latest_package['created_at']) > strtotime($last_check);
}

/**
 * 获取最新的升级套餐
 * 
 * @return array|null 最新套餐
 */
function todo_get_latest_upgrade_package() {
    global $tododb;
    
    $package = $tododb->get_row(
        "SELECT * FROM {$tododb->prefix}todo_upgrade_packages 
         WHERE status = 'active' 
         ORDER BY created_at DESC LIMIT 1",
        ARRAY_A
    );
    
    return $package;
}

/**
 * 获取用户待处理订单数量
 * 
 * @param int $user_id 用户ID
 * @return int 订单数量
 */
function todo_get_user_pending_orders_count($user_id) {
    global $tododb;
    
    $count = $tododb->get_var(
        "SELECT COUNT(*) FROM {$tododb->prefix}todo_orders 
         WHERE user_id = %d AND status = 'pending'",
        $user_id
    );
    
    return intval($count);
}

/**
 * 获取用户未读通知数量
 * 
 * @param int $user_id 用户ID
 * @return int 通知数量
 */
function todo_get_user_unread_notifications_count($user_id) {
    global $tododb;
    
    $count = $tododb->get_var(
        "SELECT COUNT(*) FROM {$tododb->prefix}todo_notifications 
         WHERE user_id = ? AND is_read = 0",
        [$user_id]
    );
    
    return intval($count);
}

/**
 * 创建用户通知
 * 
 * @param int $user_id 用户ID
 * @param array $notification_data 通知数据
 * @return bool 创建结果
 */
function todo_create_user_notification($user_id, $notification_data) {
    global $tododb;
    
    $data = array_merge([
        'user_id' => $user_id,
        'is_read' => 0,
        'created_at' => current_time('mysql')
    ], $notification_data);
    
    if (isset($data['data']) && is_array($data['data'])) {
        $data['data'] = json_encode($data['data']);
    }
    
    $result = $tododb->insert(
        $tododb->prefix . 'todo_notifications',
        $data
    );
    
    return $result !== false;
}
