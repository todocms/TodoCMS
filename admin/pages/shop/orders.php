<?php
/**
 * TodoCMS 订单管理页面
 * 
 * 提供订单的查看、编辑、状态更新等功能
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('TODO_ADMIN')) {
    exit('Direct access denied');
}

// 权限检查
if (!current_user_can('manage_options')) {
    todo_die(__('You do not have sufficient permissions to access this page.'));
}

global $tododb;

Todo_Order_Manager::init();
if (!function_exists('todo_delete_order') && class_exists('Todo_Order_Manager')) {
    function todo_delete_order($order_id)
    {
        return Todo_Order_Manager::delete_order($order_id);
    }
}

// 读取GET消息用于PRG展示
$message = isset($_GET['msg']) ? sanitize_text_field(urldecode($_GET['msg'])) : (isset($message) ? $message : '');
$message_type = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : (isset($message_type) ? $message_type : 'success');

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF验证
    $nonce = $_POST['_todononce'] ?? '';
    $nonce_valid = $nonce && (todo_verify_nonce($nonce, 'todo_orders_action') || todo_verify_nonce($nonce, 'update_order'));
    if (!$nonce_valid) {
        todo_die('Security check failed');
    }

    $action = sanitize_text_field($_POST['action']);
    $message = '';
    $message_type = 'success';

    switch ($action) {
        case 'update_status':
        case 'update_order':
            $order_id = intval($_POST['order_id']);
            $new_status = sanitize_text_field($_POST['status']);
            $tracking_number = sanitize_text_field($_POST['tracking_number'] ?? '');
            $express_company = sanitize_text_field($_POST['express_company'] ?? '');
            $admin_note = sanitize_text_field($_POST['admin_note'] ?? '');

            // 读取当前订单信息以便校验与改价
            $orders_table = $tododb->prefix . 'orders';
            $sql_current_order = "SELECT * FROM {$orders_table} WHERE id = ?";
            $current_order = $tododb->get_row($sql_current_order, [$order_id], 'ARRAY_A');
            if (!$current_order) {
                $message = '订单不存在';
                $message_type = 'error';
                $redirect = add_query_arg(['msg' => $message, 'type' => $message_type], todo_admin_url('index.php?page=todo-orders'));
                todo_redirect($redirect);
                exit;
            }

            // 更新订单状态
            $update_data = ['status' => $new_status];
            if (!empty($tracking_number)) {
                $update_data['shipping_info'] = json_encode([
                    'tracking_number' => $tracking_number,
                    'express_company' => $express_company
                ]);
                $update_data['shipped_at'] = current_time('mysql');
            }
            if (!empty($admin_note)) {
                $update_data['remark'] = $admin_note;
            }

            // 当订单为待付款状态时，支持订单改价（以本次修改为最终价格）
            // 允许当前状态为 pending 或保存的新状态为 pending 时改价
            if ($current_order['status'] === 'pending' || $new_status === 'pending') {
                if (isset($_POST['new_total_amount']) && $_POST['new_total_amount'] !== '') {
                    $new_total_amount = floatval($_POST['new_total_amount']);
                    if ($new_total_amount >= 0) {
                        $update_data['total_amount'] = round($new_total_amount, 2);
                    }
                }
            }

            $result = $tododb->update(
                $orders_table,
                $update_data,
                ['id' => $order_id]
            );
            if ($result) {
                $message = '订单更新成功';
            } else {
                $message = '订单更新失败';
                $message_type = 'error';
            }
            // PRG重定向
            $redirect = add_query_arg(['msg' => $message, 'type' => $message_type], todo_admin_url('index.php?page=todo-orders'));
            // PRG重定向
            $redirect = add_query_arg(['msg' => $message, 'type' => $message_type], todo_admin_url('index.php?page=todo-orders'));
            todo_redirect($redirect);
            exit;

        case 'bulk_action':
            $order_ids = array_map('intval', $_POST['order_ids'] ?? []);
            $bulk_action = sanitize_text_field($_POST['bulk_action']);
            if (!empty($order_ids) && !empty($bulk_action)) {
                if ($bulk_action === 'delete') {
                    $success_count = 0;
                    foreach ($order_ids as $order_id) {
                        if (todo_delete_order(intval($order_id))) {
                            $success_count++;
                        }
                    }
                    $message = "成功删除 {$success_count} 个订单";
                } else {
                    $success_count = 0;
                    foreach ($order_ids as $order_id) {
                        if (class_exists('Todo_Order_Manager')) {
                            $result = Todo_Order_Manager::update_order_status($order_id, $bulk_action, todo_get_current_user_id(), '管理员批量更新状态');
                        } else {
                            $result = $tododb->update(
                                $tododb->prefix . 'orders',
                                ['status' => $bulk_action],
                                ['id' => $order_id]
                            );
                        }
                        if ($result !== false) {
                            $success_count++;
                        }
                    }
                    $message = "成功处理 {$success_count} 个订单";
                }
            } else {
                $message = '请选择订单和操作';
                $message_type = 'error';
            }
            // PRG重定向
            $redirect = add_query_arg(['msg' => $message, 'type' => $message_type], todo_admin_url('index.php?page=todo-orders'));
            // PRG重定向
            $redirect = add_query_arg(['msg' => $message, 'type' => $message_type], todo_admin_url('index.php?page=todo-orders'));
            todo_redirect($redirect);
            exit;

        case 'delete_order':
            $order_id = intval($_POST['order_id']);
            $result = todo_delete_order($order_id);
            if ($result) {
                $message = '订单删除成功';
            } else {
                $message = '订单删除失败';
                $message_type = 'error';
            }
            // PRG重定向
            $redirect = add_query_arg(['msg' => $message, 'type' => $message_type], todo_admin_url('index.php?page=todo-orders'));
            // PRG重定向
            $redirect = add_query_arg(['msg' => $message, 'type' => $message_type], todo_admin_url('index.php?page=todo-orders'));
            todo_redirect($redirect);
            exit;

        case 'update_order':
            $order_id = intval($_POST['order_id']);
            $status = sanitize_text_field($_POST['status'] ?? '');
            $payment_status = sanitize_text_field($_POST['payment_status'] ?? '');
            $payment_method = sanitize_text_field($_POST['payment_method'] ?? '');
            $shipping_method = sanitize_text_field($_POST['shipping_method'] ?? '');
            $shipping_address = sanitize_text_field($_POST['shipping_address'] ?? '');
            $remark = sanitize_text_field($_POST['remark'] ?? '');
            $shipping_fee = isset($_POST['shipping_fee']) ? floatval($_POST['shipping_fee']) : null;
            $discount_amount = isset($_POST['discount_amount']) ? floatval($_POST['discount_amount']) : null;

            $update_data = [];
            if ($status !== '')
                $update_data['status'] = $status;
            if ($payment_status !== '')
                $update_data['payment_status'] = $payment_status;
            if ($payment_method !== '')
                $update_data['payment_method'] = $payment_method;
            if ($shipping_method !== '')
                $update_data['shipping_method'] = $shipping_method;
            if ($shipping_address !== '')
                $update_data['shipping_address'] = $shipping_address;
            if ($remark !== '')
                $update_data['remark'] = $remark;
            if ($shipping_fee !== null)
                $update_data['shipping_fee'] = $shipping_fee;
            if ($discount_amount !== null)
                $update_data['discount_amount'] = $discount_amount;

            if (empty($update_data)) {
                $message = '无可更新的字段';
                $message_type = 'error';
            } else {
                $result = $tododb->update(
                    $tododb->prefix . 'orders',
                    $update_data,
                    ['id' => $order_id]
                );
                if ($result !== false) {
                    $message = '订单信息更新成功';
                } else {
                    $message = '订单信息更新失败';
                    $message_type = 'error';
                }
            }
            // PRG重定向
            $redirect = add_query_arg(['msg' => $message, 'type' => $message_type], todo_admin_url('index.php?page=todo-orders'));
            // PRG重定向
            $redirect = add_query_arg(['msg' => $message, 'type' => $message_type], todo_admin_url('index.php?page=todo-orders'));
            todo_redirect($redirect);
            exit;
    }
}

// 获取筛选参数
$status_filter = sanitize_text_field($_GET['status'] ?? '');
$date_from = sanitize_text_field($_GET['date_from'] ?? '');
$date_to = sanitize_text_field($_GET['date_to'] ?? '');
$search = sanitize_text_field($_GET['search'] ?? '');
$per_page = 20;
$current_page = max(1, intval($_GET['paged'] ?? 1));
$offset = ($current_page - 1) * $per_page;

// 构建查询条件
$where_conditions = ['1=1'];
$where_values = [];

if (!empty($status_filter)) {
    $where_conditions[] = 'o.status = ?';
    $where_values[] = $status_filter;
}

if (!empty($date_from)) {
    $where_conditions[] = 'DATE(o.created_at) >= ?';
    $where_values[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = 'DATE(o.created_at) <= ?';
    $where_values[] = $date_to;
}

if (!empty($search)) {
    $where_conditions[] = '(o.order_no LIKE ? OR u.display_name LIKE ?)';
    $search_term = '%' . $tododb->esc_like($search) . '%';
    $where_values[] = $search_term;
    $where_values[] = $search_term;
}

$where_clause = implode(' AND ', $where_conditions);

// 获取订单列表
$orders_table = $tododb->prefix . 'orders';
$users_table = $tododb->prefix . 'users';
$order_items_table = $tododb->prefix . 'order_items';

$query = "
    SELECT o.*, u.display_name as user_name, 
           COALESCE(SUM(oi.quantity), 0) as total_quantity,
           CASE 
                WHEN o.total_amount IS NULL OR o.total_amount = 0 
                THEN COALESCE(SUM(COALESCE(oi.total_price, oi.price * oi.quantity)), 0) + COALESCE(o.shipping_fee, 0) - COALESCE(o.discount_amount, 0)
                ELSE o.total_amount
           END AS display_total_amount
    FROM $orders_table o
    LEFT JOIN $users_table u ON o.user_id = u.ID
    LEFT JOIN $order_items_table oi ON o.id = oi.order_id
    WHERE $where_clause
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT ? OFFSET ?
";

$all_values = array_merge($where_values, [$per_page, $offset]);
$orders = $tododb->get_results($query, $all_values);

// 获取总数
$count_query = "
    SELECT COUNT(DISTINCT o.id)
    FROM $orders_table o
    LEFT JOIN $users_table u ON o.user_id = u.ID
    LEFT JOIN $order_items_table oi ON o.id = oi.order_id
    WHERE $where_clause
";
$total_orders = $tododb->get_var($count_query, $where_values);
$total_pages = ceil($total_orders / $per_page);

// 获取订单状态统计
$status_stats = todo_get_order_status_stats();

// 获取快递公司列表
$express_companies = todo_get_express_companies();

/**
 * 获取订单状态统计
 */
function todo_get_order_status_stats()
{
    global $tododb;

    $orders_table = $tododb->prefix . 'orders';

    $stats = $tododb->get_results("
        SELECT status, COUNT(*) as count, SUM(total_amount) as total_amount
        FROM $orders_table
        GROUP BY status
    ", ARRAY_A);

    $result = [];
    foreach ($stats as $stat) {
        $result[$stat['status']] = [
            'count' => intval($stat['count']),
            'total_amount' => floatval($stat['total_amount'])
        ];
    }

    return $result;
}

/**
 * 获取订单详情
 */
function todo_get_order_details($order_id)
{
    global $tododb;

    $orders_table = $tododb->prefix . 'orders';
    $order_items_table = $tododb->prefix . 'order_items';
    $posts_table = $tododb->prefix . 'posts';
    $logistics_table = $tododb->prefix . 'order_logistics';

    // 获取订单基本信息
    $order = $tododb->get_row("
        SELECT * FROM $orders_table WHERE id = ?
    ", [$order_id]);

    if (!$order) {
        return null;
    }

    // 获取订单商品
    $order_items = $tododb->get_results("
        SELECT oi.*, p.post_title as product_name
        FROM $order_items_table oi
        LEFT JOIN $posts_table p ON oi.product_id = p.ID
        WHERE oi.order_id = ?
    ", [$order_id]);

    // 获取物流信息
    $logistics = $tododb->get_row("
        SELECT * FROM $logistics_table WHERE order_id = ?
    ", [$order_id]);

    return [
        'order' => $order,
        'items' => $order_items,
        'logistics' => $logistics
    ];
}

/**
 * 获取快递公司列表
 */
function todo_get_express_companies()
{
    if (!function_exists('todo_get_service_express_companies')) {
        if (class_exists('Todo_Function_Loader')) {
            Todo_Function_Loader::load_function('shop-service-settings');
        } else {
            $p = defined('ABSPATH') ? ABSPATH . 'core/functions/shop-service-settings.php' : '';
            if ($p && file_exists($p)) {
                require_once $p;
            }
        }
    }
    if (function_exists('todo_get_service_express_companies')) {
        return todo_get_service_express_companies(ARRAY_A);
    }

    global $tododb;
    $express_table = $tododb->prefix . 'shipping_companies';
    return $tododb->get_results("
        SELECT * FROM $express_table 
        WHERE status = 1 
        ORDER BY sort_order ASC, name ASC
    ", ARRAY_A);
}

/**
 * 格式化订单状态
 */
function todo_format_order_status($status)
{
    $statuses = [
        'pending' => ['label' => '待付款', 'class' => 'status-pending'],
        'paid' => ['label' => '已付款', 'class' => 'status-spam'],
        'processing' => ['label' => '待发货', 'class' => 'status-spam'],
        'shipped' => ['label' => '已发货', 'class' => 'status-active'],
        'delivered' => ['label' => '已送达', 'class' => 'status-active'],
        'completed' => ['label' => '已完成', 'class' => 'status-inactive'],
        'cancelled' => ['label' => '已取消', 'class' => 'status-inactive'],
        'refunded' => ['label' => '已退款', 'class' => 'status-reject']
    ];

    return $statuses[$status] ?? ['label' => $status, 'class' => 'status-inactive'];
}

/**
 * 格式化支付方式
 */
function todo_format_payment_method($method)
{
    if (!function_exists('todo_get_payment_method_label')) {
        if (class_exists('Todo_Function_Loader')) {
            Todo_Function_Loader::load_function('shop-service-settings');
        } else {
            $p = defined('ABSPATH') ? ABSPATH . 'core/functions/shop-service-settings.php' : '';
            if ($p && file_exists($p)) {
                require_once $p;
            }
        }
    }
    return function_exists('todo_get_payment_method_label')
        ? todo_get_payment_method_label($method)
        : $method;
}
?>

<div class="admin-page">
    <?php if (isset($message) && $message): ?>
        <div class="notice notice-<?php echo esc_attr($message_type); ?> is-dismissible">
            <p><?php echo esc_html($message); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <!-- 订单状态统计 -->
    <div class="stats-cards-group">
        <?php
        $total_count = 0;
        $total_amount = 0;
        foreach ($status_stats as $status => $stat) {
            $total_count += $stat['count'];
            $total_amount += $stat['total_amount'];
        }
        ?>
        <div class="stats-card">
            <h3>总订单</h3>
            <div class="stats-number"><?php echo number_format($total_count); ?></div>
            <div class="stats-amount"><?php echo todo_format_admin_price($total_amount); ?></div>
        </div>

        <?php foreach (['pending', 'paid', 'shipped', 'completed'] as $status): ?>
            <?php if (isset($status_stats[$status])): ?>
                <div class="stats-card">
                    <h3><?php echo todo_format_order_status($status)['label']; ?></h3>
                    <div class="stats-number"><?php echo number_format($status_stats[$status]['count']); ?></div>
                    <div class="stats-amount"><?php echo todo_format_admin_price($status_stats[$status]['total_amount']); ?></div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="admin-card">
        <div class="admin-card-content">
            <!-- 筛选表单 -->
            <form method="get" action="<?php echo todo_admin_url('index.php'); ?>">
                <input type="hidden" name="page" value="todo-orders">
                <?php if (function_exists('todo_is_iframe_mode') && todo_is_iframe_mode()): ?>
                    <input type="hidden" name="format" value="iframe">
                <?php endif; ?>
                <div class="bulk-actions-bar">
                    <div class="bulk-actions">
                        <select name="status" style="min-width: 140px;">
                            <option value="">全部状态</option>
                            <option value="pending" <?php selected($status_filter, 'pending'); ?>>待付款</option>
                            <option value="paid" <?php selected($status_filter, 'paid'); ?>>已付款</option>
                            <option value="shipped" <?php selected($status_filter, 'shipped'); ?>>已发货</option>
                            <option value="completed" <?php selected($status_filter, 'completed'); ?>>已完成</option>
                            <option value="cancelled" <?php selected($status_filter, 'cancelled'); ?>>已取消</option>
                            <option value="refunded" <?php selected($status_filter, 'refunded'); ?>>已退款</option>
                        </select>
                        <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>"
                            placeholder="开始日期">
                        <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>" placeholder="结束日期">

                        <input type="text" name="search" value="<?php echo esc_attr($search); ?>"
                            placeholder="搜索订单号、客户信息...">

                        <button type="submit" class="button button-secondary">筛选</button>
                        <?php if (!empty($status_filter) || !empty($date_from) || !empty($date_to) || !empty($search)): ?>
                            <a href="<?php echo todo_admin_url('index.php?page=todo-orders' . ((function_exists('todo_is_iframe_mode') && todo_is_iframe_mode()) ? '&format=iframe' : '')); ?>"
                                class="btn-normal m-l-05">重置</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>

            <!-- 批量操作 -->
            <form method="post" id="orders-form" action="<?php echo todo_admin_url('index.php?page=todo-orders'); ?>">
                <?php todo_nonce_field('todo_orders_action'); ?>
                <input type="hidden" name="action" value="bulk_action">

                <!-- 订单列表 -->
                <table class="admin-table">
                    <thead>
                        <tr>
                            <td class="check-column">
                                <input type="checkbox" id="cb-select-all">
                            </td>
                            <th>订单号</th>
                            <th style="min-width: 180px;">客户信息</th>
                            <th style="min-width: 90px;">商品</th>
                            <th style="min-width: 110px; text-align: right;">金额</th>
                            <th style="min-width: 90px;">支付方式</th>
                            <th style="min-width: 80px;">状态</th>
                            <th style="min-width: 160px; max-width: 200px;">下单时间</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($orders)): ?>
                            <tr class="empty">
                                <td colspan="9">
                                    <div class="no-items">
                                        <p>没有找到订单。</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($orders as $order): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="order_ids[]" value="<?php echo esc_attr($order->id); ?>">
                                    </td>
                                    <td>
                                        <a href="javascript:void(0);" class="row-title view-order"
                                            data-order-id="<?php echo esc_attr($order->id); ?>"><?php echo esc_html($order->order_no); ?></a>
                                        <div class="row-actions"><span class="edit"><a href="javascript:void(0);"
                                                    class="edit-order" data-order-id="<?php echo esc_attr($order->id); ?>"
                                                    data-status="<?php echo esc_attr($order->status); ?>"
                                                    data-payment_status="<?php echo esc_attr($order->payment_status); ?>"
                                                    data-payment_method="<?php echo esc_attr($order->payment_method); ?>"
                                                    data-shipping_method="<?php echo esc_attr($order->shipping_method); ?>"
                                                    data-shipping_address="<?php echo esc_attr($order->shipping_address); ?>"
                                                    data-remark="<?php echo esc_attr($order->remark); ?>"
                                                    data-shipping_fee="<?php echo esc_attr($order->shipping_fee); ?>"
                                                    data-discount_amount="<?php echo esc_attr($order->discount_amount); ?>">编辑</a>
                                                | </span>
                                            <span class="view"><a href="<?php echo todo_admin_url('index.php?page=todo-order-edit&from=todo-orders&id=' . $order->id); ?>">订单详情</a>
                                                | </span>
                                            <span class="trash"><a href="javascript:void(0);" class="submitdelete delete-order"
                                                    data-order-id="<?php echo esc_attr($order->id); ?>">删除</a></span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($order->user_id) && !empty($order->user_name)): ?>
                                            <?php echo esc_html($order->user_name); ?>
                                            <small class="description">ID: <?php echo esc_html($order->user_id); ?></small>
                                        <?php else: ?>
                                            <?php
                                            $addr_data = json_decode($order->shipping_address ?? '', true);
                                            $guest_label = !empty($order->guest_email) ? $order->guest_email : (!empty($addr_data['name']) ? $addr_data['name'] : '游客订单');
                                            ?>
                                            <span class="badge badge-secondary" style="font-weight: normal;">游客</span>
                                            <small class="description"><?php echo esc_html($guest_label); ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($order->shipping_address)): ?>
                                            <small class="tb-description" style="font-size: 11px;">
                                                <?php 
                                                $addr_data = json_decode($order->shipping_address, true);
                                                if (json_last_error() === JSON_ERROR_NONE && is_array($addr_data)) {
                                                    $name = $addr_data['name'] ?? '';
                                                    $addr_str = ($addr_data['province'] ?? '') . ($addr_data['city'] ?? '') . ($addr_data['district'] ?? '') . ($addr_data['detail'] ?? '');
                                                    $display = ($name ? $name . ': ' : '') . $addr_str;
                                                    echo esc_html(truncate_string($display, 50, '...'));
                                                } else {
                                                    echo esc_html(truncate_string((string) $order->shipping_address, 50, '...')); 
                                                }
                                                ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small><?php echo intval($order->total_quantity); ?> 件商品</small>
                                    </td>
                                    <td style="text-align: right;">
                                        <strong><?php echo todo_format_admin_price($order->display_total_amount); ?></strong>
                                        <?php if ($order->discount_amount > 0): ?>
                                            <small class="tb-meta" style="text-align: right;display: block;">优惠-<?php echo todo_format_admin_price($order->discount_amount); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo todo_format_payment_method($order->payment_method); ?></td>
                                    <td>
                                        <?php $status_info = todo_format_order_status($order->status); ?>
                                        <span class="<?php echo esc_attr($status_info['class']); ?>">
                                            <?php echo esc_html($status_info['label']); ?>
                                        </span>
                                    </td>
                                    <td style="max-width: 200px;">
                                        <span class="tb-meta"><?php echo date('Y-m-d H:i:s', strtotime($order->created_at)); ?></span>
                                        <?php 
                                        $c_note = is_array($order) ? ($order['notes'] ?? $order['remark'] ?? '') : ($order->notes ?? $order->remark ?? '');
                                        $a_note = is_array($order) ? ($order['admin_notes'] ?? $order['admin_note'] ?? '') : ($order->admin_notes ?? $order->admin_note ?? '');
                                        ?>
                                        <?php if (!empty($c_note)): ?>
                                            <small class="tb-meta" style="word-break: break-all;">客户备注: <b style="color: #f60;"><?php echo esc_html($c_note); ?></b></small>
                                        <?php endif; ?>
                                        <?php if (!empty($a_note)): ?>
                                            <small class="tb-meta" style="word-break: break-all;">管理员备注: <b style="color: #333;"><?php echo esc_html($a_note); ?></b></small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div class="bulk-actions-bar" style="margin: 20px 0 0;">
                    <div class="bulk-actions">
                        <select name="bulk_action" style="min-width: 140px;">
                            <option value="">批量操作</option>
                            <option value="paid">标记为已付款</option>
                            <option value="shipped">标记为已发货</option>
                            <option value="completed">标记为已完成</option>
                            <option value="cancelled">标记为已取消</option>
                            <option value="delete">批量删除订单</option>
                        </select>
                        <button type="submit" class="button button-secondary action">应用</button>
                    </div>
                    <!-- 分页 -->
                    <?php if ($total_pages > 1): ?>
                        <?php
                        $pagination_add_args = array();
                        if (!empty($status_filter)) {
                            $pagination_add_args['status'] = $status_filter;
                        }
                        if (!empty($date_from)) {
                            $pagination_add_args['date_from'] = $date_from;
                        }
                        if (!empty($date_to)) {
                            $pagination_add_args['date_to'] = $date_to;
                        }
                        if (!empty($search)) {
                            $pagination_add_args['search'] = $search;
                        }

                        echo todo_render_pagination(array(
                            'total_items' => $total_orders,
                            'per_page' => $per_page,
                            'current_page' => $current_page,
                            'base_url' => todo_admin_url('index.php?page=todo-orders'),
                            'add_args' => $pagination_add_args,
                            'show_count' => true,
                        ));
                        ?>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<template id="order-details-modal-template" data-size="lg">
    <div data-slot="title">订单详情</div>
    <div data-slot="body">
        <div id="order-details-content"></div>
    </div>
</template>

<template id="edit-order-modal-template">
    <div data-slot="title">编辑订单</div>
    <div data-slot="body">
        <form id="edit-order-form" method="post">
            <?php todo_nonce_field('update_order'); ?>
            <input type="hidden" name="action" value="update_order">
            <input type="hidden" name="order_id" id="edit-order-id">

            <table class="form-table">
                <tr id="price-adjustment" style="display: none;">
                    <th><label for="edit-new-total-amount">订单改价（最终价格）</label></th>
                    <td>
                        <input type="number" step="0.01" min="0" name="new_total_amount" id="edit-new-total-amount"
                            class="regular-text">
                        <p class="description">仅在“待付款”状态下可改价，保存后以该价格为准。</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="edit-status">订单状态</label></th>
                    <td>
                        <select name="status" id="edit-status" required>
                            <option value="pending">待付款</option>
                            <option value="paid">已付款</option>
                            <option value="shipped">已发货</option>
                            <option value="completed">已完成</option>
                            <option value="cancelled">已取消</option>
                            <option value="refunded">已退款</option>
                        </select>
                    </td>
                </tr>
                <tr id="shipping-info" style="display: none;">
                    <th><label for="edit-express-company">快递公司</label></th>
                    <td>
                        <select name="express_company" id="edit-express-company">
                            <option value="">请选择快递公司</option>
                            <?php foreach ($express_companies as $company): ?>
                                <option value="<?php echo esc_attr($company['code']); ?>">
                                    <?php echo esc_html($company['name']); ?> <?php echo esc_html($company['code']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr id="tracking-info" style="display: none;">
                    <th><label for="edit-tracking-number">快递单号</label></th>
                    <td>
                        <input type="text" name="tracking_number" id="edit-tracking-number" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th><label for="edit-admin-note">管理员备注</label></th>
                    <td>
                        <textarea name="admin_note" id="edit-admin-note" rows="3" class="large-text"></textarea>
                    </td>
                </tr>
            </table>
        </form>
    </div>
    <div data-slot="footer" class="todo-modal-footer">
        <button type="button" class="button button-secondary todo-modal-cancel">取消</button>
        <button type="submit" form="edit-order-form" class="button button-primary">更新订单</button>
    </div>
</template>

<style>
    .todo-filter-form {
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 15px;
        margin: 20px 0;
    }

    .filter-row {
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
    }

    .filter-row select,
    .filter-row input[type="text"],
    .filter-row input[type="date"] {
        min-width: 150px;
    }

    .order-info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }

    .order-info-item {
        background: #f9f9f9;
        padding: 15px;
        border-radius: 4px;
    }

    .order-info-item label {
        font-weight: 500;
        color: #666;
        display: block;
        margin-bottom: 5px;
    }

    #shipping-info,
    #tracking-info {
        transition: all 0.3s ease;
    }
</style>

<script type="text/javascript">
    jQuery(document).ready(function ($) {
        // ajaxurl Fallback，确保后台AJAX地址可用
        if (typeof window.ajaxurl === 'undefined' || !window.ajaxurl) {
            window.ajaxurl = '<?php echo todo_admin_url('admin-ajax.php'); ?>';
        }
        // 规范化 ajaxurl 的来源（包含端口），避免错误指向 80 端口
        if (typeof window.location !== 'undefined' && window.location.origin) {
            try {
                var _ajaxURL = new URL(window.ajaxurl, window.location.origin);
                if (_ajaxURL.origin !== window.location.origin) {
                    window.ajaxurl = (window.TODO && window.TODO.join ? window.TODO.join((window.TODO.ADMIN_BASE || (window.location.origin + '/admin')), 'admin-ajax.php') : (window.location.origin + '/admin/admin-ajax.php'));
                }
            } catch (e) {
                window.ajaxurl = (window.TODO && window.TODO.join ? window.TODO.join((window.TODO.ADMIN_BASE || (window.location.origin + '/admin')), 'admin-ajax.php') : (window.location.origin + '/admin/admin-ajax.php'));
            }
        }

        // 全选/取消全选
        $('#cb-select-all').on('change', function () {
            $('input[name="order_ids[]"]').prop('checked', this.checked);
        });

        let orderDetailsModal = null;
        let editOrderModal = null;

        // 查看订单详情
        $('.view-order').on('click', function (e) {
            e.preventDefault();
            var orderId = $(this).data('order-id');
            loadOrderDetails(orderId);
        });

        // 编辑订单
        $('.edit-order').on('click', function (e) {
            e.preventDefault();
            var orderId = $(this).data('order-id');
            const modal = new TodoModal({
                templateId: 'edit-order-modal-template',
                destroyOnClose: true
            });
            editOrderModal = modal;
            modal.onCloseCallback(function () {
                editOrderModal = null;
            });
            const $ctx = $(modal.element);
            bindEditOrderModal($ctx);
            loadOrderForEdit(orderId, $ctx);
            modal.open();
        });

        // 加载订单详情
        function loadOrderDetails(orderId) {
            const modal = new TodoModal({
                templateId: 'order-details-modal-template',
                destroyOnClose: true
            });
            orderDetailsModal = modal;
            modal.onCloseCallback(function () {
                orderDetailsModal = null;
            });
            const $ctx = $(modal.element);
            $ctx.find('#order-details-content').html('<div class="loading">加载中...</div>');
            modal.open();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'todo_get_order_details',
                    order_id: orderId,
                    _todononce: '<?php echo todo_create_nonce("todo_order_details"); ?>'
                },
                success: function (response) {
                    if (response.success) {
                        $ctx.find('#order-details-content').html(response.data.html);
                    } else {
                        $ctx.find('#order-details-content').html('<div class="error">加载失败: ' + response.data + '</div>');
                    }
                },
                error: function () {
                    $ctx.find('#order-details-content').html('<div class="error">网络错误</div>');
                }
            });
        }

        // 加载订单编辑信息
        function loadOrderForEdit(orderId, $ctx) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'todo_get_order_for_edit',
                    order_id: orderId,
                    _todononce: '<?php echo todo_create_nonce("todo_order_edit"); ?>'
                },
                success: function (response) {
                    if (response.success) {
                        var order = response.data;
                        $ctx.find('#edit-order-id').val(order.id);
                        $ctx.find('#edit-status').val(order.status).trigger('change');
                        $ctx.find('#edit-admin-note').val(order.admin_notes || order.admin_note || '');

                        // 根据订单状态显示改价区域，并填充当前订单金额
                        if (order.status === 'pending') {
                            $ctx.find('#price-adjustment').show();
                            $ctx.find('#edit-new-total-amount').val(order.total_amount);
                        } else {
                            $ctx.find('#price-adjustment').hide();
                            $ctx.find('#edit-new-total-amount').val('');
                        }

                        // 如果有物流信息，填充快递信息
                        if (order.logistics) {
                            $ctx.find('#edit-express-company').val(order.logistics.express_company);
                            $ctx.find('#edit-tracking-number').val(order.logistics.tracking_number);
                        }
                    } else {
                        TodoCMS.Utils.showMessage('加载订单信息失败: ' + response.data, 'error');
                    }
                },
                error: function () {
                    TodoCMS.Utils.showMessage('网络错误', 'error');
                }
            });
        }

        function bindEditOrderModal($ctx) {
            $ctx.find('#edit-status').on('change', function () {
                var val = $(this).val();
                if (val === 'shipped') {
                    $ctx.find('#shipping-info, #tracking-info').show();
                } else {
                    $ctx.find('#shipping-info, #tracking-info').hide();
                }
                if (val === 'pending') {
                    $ctx.find('#price-adjustment').show();
                } else {
                    $ctx.find('#price-adjustment').hide();
                }
            });

            $ctx.find('#edit-order-form').on('submit', function (e) {
                e.preventDefault();
                var status = $ctx.find('#edit-status').val();
                if (status === 'shipped') {
                    var expressCompany = $ctx.find('#edit-express-company').val();
                    var trackingNumber = $ctx.find('#edit-tracking-number').val();

                    if (!expressCompany || !trackingNumber) {
                        TodoCMS.Utils.showMessage('发货状态需要填写快递公司和快递单号', 'warning');
                        return false;
                    }
                }
                if (status === 'pending' && $ctx.find('#price-adjustment').is(':visible')) {
                    var newAmount = $ctx.find('#edit-new-total-amount').val();
                    if (newAmount !== '') {
                        var num = parseFloat(newAmount);
                        if (isNaN(num) || num < 0) {
                            TodoCMS.Utils.showMessage('订单改价需为非负数', 'warning');
                            return false;
                        }
                    }
                }
                
                var form = this;
                var formData = new FormData(form);
                formData.set('action', 'update_order');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function (response) {
                        if (response && response.success) {
                            TodoCMS.Utils.showMessage('订单更新成功', 'success');
                            if (editOrderModal) {
                                editOrderModal.close();
                            }
                            window.location.reload();
                        } else {
                            var msg = (response && (response.error || response.message)) ? (response.error || response.message) : '订单更新失败';
                            TodoCMS.Utils.showMessage(msg, 'error');
                        }
                    },
                    error: function () {
                        TodoCMS.Utils.showMessage('网络错误', 'error');
                    }
                });
            });
        }

        // 批量操作确认
        $('#orders-form').on('submit', function (e) {
            var action = $('select[name="bulk_action"]').val();
            var checkedItems = $('input[name="order_ids[]"]:checked').length;

            if (!action) {
                e.preventDefault();
                TodoCMS.Utils.showMessage('请选择批量操作', 'warning');
                return false;
            }

            if (checkedItems === 0) {
                e.preventDefault();
                TodoCMS.Utils.showMessage('请选择要操作的订单', 'warning');
                return false;
            }

            var actionText = $('select[name="bulk_action"] option:selected').text();
            var form = this;

            e.preventDefault();

            if (action === 'delete') {
                TodoModal.deleteConfirm({
                    title: '确认删除订单',
                    message: '确定要永久删除 ' + checkedItems + ' 个订单吗？',
                    onConfirm: function () {
                        form.submit();
                    }
                });
            } else {
                TodoModal.confirm({
                    title: '确认操作',
                    message: '确定要对 ' + checkedItems + ' 个订单执行"' + actionText + '"操作吗？',
                    onConfirm: function () {
                        form.submit();
                    }
                });
            }
        });

        // 单个删除订单
        $(document).on('click', '.delete-order', function (e) {
            e.preventDefault();
            var orderId = $(this).data('order-id');
            if (!orderId) return;

            TodoModal.deleteConfirm({
                title: '确认删除订单',
                message: '确定要删除该订单吗？删除后不可恢复。',
                onConfirm: function () {
                    $('#delete-order-id').val(orderId);
                    $('#delete-order-form').submit();
                }
            });
        });

    });
</script>

<!-- 隐藏删除表单：用于单个订单删除，保持PRG与CSRF -->
<form method="post" id="delete-order-form" style="display:none;">
    <?php todo_nonce_field('todo_orders_action'); ?>
    <input type="hidden" name="action" value="delete_order">
    <input type="hidden" name="order_id" id="delete-order-id" value="0">
</form>
