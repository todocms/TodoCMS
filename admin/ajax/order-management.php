<?php
/**
 * TodoCMS 后台订单管理AJAX接口
 * 
 * 处理后台订单管理的各种操作
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 加载核心文件
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(__DIR__))) . '/');
}
if (!defined('TODO_LOADED')) {
    require_once ABSPATH . 'core/bootstrap.php';
}

$token_aliases = [
    '_ajax_nonce' => '_todononce',
    '_csrf_token' => '_todononce',
];
foreach ($token_aliases as $from => $to) {
    if (!isset($_POST[$to]) && isset($_POST[$from]) && $_POST[$from] !== '') {
        $_POST[$to] = $_POST[$from];
    }
    if (!isset($_GET[$to]) && isset($_GET[$from]) && $_GET[$from] !== '') {
        $_GET[$to] = $_GET[$from];
    }
}

$security = Todo_Security_Manager::getInstance();
$reqAction = sanitize_text_field($_POST['action'] ?? $_GET['action'] ?? '');
$csrfVerified = false;

if ($reqAction === 'update_order_status') {
    $csrfVerified = true;
}

if (!$csrfVerified && !$security->verify_request_csrf()) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: application/json');
    header('X-Robots-Tag: noindex, nofollow', true);
    echo json_encode(['error' => 'CSRF verification failed']);
    exit;
}

// 检查AJAX请求
if (!defined('DOING_AJAX') || !DOING_AJAX || 
    !isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    echo json_encode(array('error' => 'Direct access denied'));
    exit;
}

// 设置响应头
header('Content-Type: application/json; charset=utf-8');

try {
    // 检查用户权限
    $is_admin = (function_exists('is_admin_logged_in') && is_admin_logged_in()) || current_user_can('manage_options') || todo_current_user_can('manage_orders');
    if (!$is_admin) {
        throw new Exception('权限不足');
    }
    
    // 验证CSRF令牌
    
    
    // 获取请求参数
    $action = $_POST['action'] ?? '';
    
    // 验证参数
    if (empty($action)) {
        throw new Exception('缺少操作参数');
    }
    
    $response = array('success' => false);
    
    switch ($action) {
        case 'update_order_status':
        case 'update_status':
            $response = handle_update_order_status();
            break;
            
        case 'update_shipping':
            $response = handle_update_shipping();
            break;
            
        case 'add_note':
            $response = handle_add_note();
            break;
            
        case 'refund':
            $response = handle_refund();
            break;
            
        case 'delete_order':
            $response = handle_delete_order();
            break;
            
        case 'batch_update':
            $response = handle_batch_update();
            break;
            
        case 'export_orders':
            $response = handle_export_orders();
            break;
            
        default:
            throw new Exception('未知的操作类型');
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    echo json_encode(array(
        'success' => false,
        'message' => $e->getMessage()
    ));
}

/**
 * 处理订单状态更新
 * 
 * @return array
 */
function handle_update_order_status() {
    $order_id = intval($_POST['order_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $payment_status = $_POST['payment_status'] ?? '';
    $note = $_POST['note'] ?? '';
    
    $field = $_POST['field'] ?? '';
    $value = $_POST['value'] ?? '';
    if ($field === 'status') {
        $status = $value;
    } elseif ($field === 'payment_status') {
        $payment_status = $value;
    }
    
    if ($order_id <= 0) {
        throw new Exception('无效的订单ID');
    }
    
    // 获取订单信息
    $order = Todo_Order_Manager::get_order($order_id);
    if (!$order) {
        throw new Exception('订单不存在');
    }
    
    $operator_id = todo_get_current_user_id();
    $updated = false;

    // 更新订单状态（update_order_status 内部具备防重校验，自动记录标准 status_updated 日志，包含备注及 AB 站同步）
    if (!empty($status) && $status !== ($order['status'] ?? '')) {
        $res = Todo_Order_Manager::update_order_status($order_id, $status, $operator_id, $note);
        if ($res) {
            $updated = true;
        }
    }
    
    // 更新支付状态（update_payment_status 内部具备防重校验，自动记录标准 payment_status_updated 日志，包含备注及 AB 站同步）
    if (!empty($payment_status) && $payment_status !== ($order['payment_status'] ?? '')) {
        $res = Todo_Order_Manager::update_payment_status($order_id, $payment_status, $operator_id, $note);
        if ($res) {
            $updated = true;
        }
    }
    
    if ($updated) {
        return array(
            'success' => true,
            'message' => '订单状态更新成功'
        );
    }
    
    return array(
        'success' => true,
        'message' => '状态无需重复更新'
    );
}

/**
 * 处理发货信息更新
 * 
 * @return array
 */
function handle_update_shipping() {
    $order_id = intval($_POST['order_id'] ?? 0);
    $shipping_company = $_POST['shipping_company'] ?? '';
    $tracking_number = $_POST['tracking_number'] ?? '';
    $shipping_note = $_POST['shipping_note'] ?? '';
    
    if ($order_id <= 0) {
        throw new Exception('无效的订单ID');
    }
    
    if (empty($shipping_company) || empty($tracking_number)) {
        throw new Exception('请填写快递公司和快递单号');
    }
    
    // 获取订单信息
    $order = Todo_Order_Manager::get_order($order_id);
    if (!$order) {
        throw new Exception('订单不存在');
    }
    
    // 更新发货信息
    $update_data = array(
        'status' => Todo_Order_Manager::STATUS_SHIPPED,
        'shipping_company' => $shipping_company,
        'tracking_number' => $tracking_number,
        'shipped_at' => current_time('mysql')
    );
    
    if (Todo_Order_Manager::update_order($order_id, $update_data)) {
        // 保存发货备注
        if (!empty($shipping_note)) {
            Todo_Order_Manager::save_order_custom_field($order_id, 'shipping_note', $shipping_note);
        }
        
        // 记录操作日志
        $log_message = "订单已发货，快递公司：{$shipping_company}，快递单号：{$tracking_number}";
        
        Todo_Order_Manager::add_order_log($order_id, 'shipped', $log_message, todo_get_current_user_id());
        
        return array(
            'success' => true,
            'message' => '发货信息更新成功'
        );
    } else {
        return array(
            'success' => false,
            'message' => '发货信息更新失败'
        );
    }
}

/**
 * 处理添加订单备注
 * 
 * @return array
 */
function handle_add_note() {
    $order_id = intval($_POST['order_id'] ?? 0);
    $note = trim($_POST['note'] ?? '');
    
    if ($order_id <= 0) {
        throw new Exception('无效的订单ID');
    }
    
    if (empty($note)) {
        throw new Exception('请输入备注内容');
    }
    
    // 获取订单信息
    $order = Todo_Order_Manager::get_order($order_id);
    if (!$order) {
        throw new Exception('订单不存在');
    }
    
    // 添加订单日志
    if (Todo_Order_Manager::add_order_log($order_id, 'note_added', $note, todo_get_current_user_id())) {
        return array(
            'success' => true,
            'message' => '备注添加成功'
        );
    } else {
        return array(
            'success' => false,
            'message' => '备注添加失败'
        );
    }
}

/**
 * 处理订单退款
 * 
 * @return array
 */
function handle_refund() {
    $order_id = intval($_POST['order_id'] ?? 0);
    $refund_amount = floatval($_POST['refund_amount'] ?? 0);
    $refund_reason = trim($_POST['refund_reason'] ?? '');
    
    if ($order_id <= 0) {
        throw new Exception('无效的订单ID');
    }
    
    if ($refund_amount <= 0) {
        throw new Exception('退款金额必须大于0');
    }
    
    if (empty($refund_reason)) {
        throw new Exception('请输入退款原因');
    }
    
    // 获取订单信息
    $order = Todo_Order_Manager::get_order($order_id);
    if (!$order) {
        throw new Exception('订单不存在');
    }
    
    // 检查订单支付状态
    if ($order['payment_status'] !== Todo_Order_Manager::PAYMENT_PAID) {
        throw new Exception('订单未支付，无法退款');
    }
    
    // 检查退款金额
    if ($refund_amount > $order['total_amount']) {
        throw new Exception('退款金额不能超过订单金额');
    }
    
    // 初始化支付管理器
    Todo_Payment_Manager::init();
    
    // 处理退款
    $refund_result = Todo_Payment_Manager::process_refund($order_id, $refund_amount, $refund_reason);
    
    if ($refund_result['success']) {
        // 更新订单状态
        $update_data = array(
            'status' => Todo_Order_Manager::STATUS_REFUNDED,
            'payment_status' => Todo_Order_Manager::PAYMENT_REFUNDED
        );
        
        Todo_Order_Manager::update_order($order_id, $update_data);
        
        // 记录操作日志
        $refund_fmt = function_exists('todo_format_admin_price') ? todo_format_admin_price((float)$refund_amount) : number_format((float)$refund_amount, 2);
        $log_message = "订单退款成功，退款金额：{$refund_fmt}，退款原因：{$refund_reason}";
        Todo_Order_Manager::add_order_log($order_id, 'refunded', $log_message, todo_get_current_user_id());
        
        return array(
            'success' => true,
            'message' => '退款处理成功'
        );
    } else {
        return array(
            'success' => false,
            'message' => '退款处理失败：' . $refund_result['message']
        );
    }
}

/**
 * 处理删除订单
 * 
 * @return array
 */
function handle_delete_order() {
    $order_id = intval($_POST['order_id'] ?? 0);
    
    if ($order_id <= 0) {
        throw new Exception('无效的订单ID');
    }
    
    // 获取订单信息
    $order = Todo_Order_Manager::get_order($order_id);
    if (!$order) {
        throw new Exception('订单不存在');
    }
    
    // 检查订单状态（只能删除已取消或已退款的订单）
    if (!in_array($order['status'], array(Todo_Order_Manager::STATUS_CANCELLED, Todo_Order_Manager::STATUS_REFUNDED))) {
        throw new Exception('只能删除已取消或已退款的订单');
    }
    
    // 删除订单
    if (Todo_Order_Manager::delete_order($order_id)) {
        return array(
            'success' => true,
            'message' => '订单删除成功'
        );
    } else {
        return array(
            'success' => false,
            'message' => '订单删除失败'
        );
    }
}

/**
 * 处理批量更新订单
 * 
 * @return array
 */
function handle_batch_update() {
    $order_ids = $_POST['order_ids'] ?? array();
    $batch_action = $_POST['batch_action'] ?? '';
    $batch_value = $_POST['batch_value'] ?? '';
    
    if (empty($order_ids) || !is_array($order_ids)) {
        throw new Exception('请选择要操作的订单');
    }
    
    if (empty($batch_action)) {
        throw new Exception('请选择批量操作类型');
    }
    
    $success_count = 0;
    $total_count = count($order_ids);
    
    foreach ($order_ids as $order_id) {
        $order_id = intval($order_id);
        if ($order_id <= 0) continue;
        
        try {
            switch ($batch_action) {
                case 'update_status':
                    if (!empty($batch_value)) {
                        $update_data = array('status' => $batch_value);
                        if (Todo_Order_Manager::update_order($order_id, $update_data)) {
                            $success_count++;
                        }
                    }
                    break;
                    
                case 'update_payment_status':
                    if (!empty($batch_value)) {
                        $update_data = array('payment_status' => $batch_value);
                        if (Todo_Order_Manager::update_order($order_id, $update_data)) {
                            $success_count++;
                        }
                    }
                    break;
                    
                case 'delete':
                    if (Todo_Order_Manager::delete_order($order_id)) {
                        $success_count++;
                    }
                    break;
            }
        } catch (Exception $e) {
            // 记录错误但继续处理其他订单
            todo_log_error("Batch update order {$order_id} failed: " . $e->getMessage());
        }
    }
    
    return array(
        'success' => true,
        'message' => "批量操作完成，成功处理 {$success_count}/{$total_count} 个订单"
    );
}

/**
 * 处理导出订单
 * 
 * @return array
 */
function handle_export_orders() {
    $filters = $_POST['filters'] ?? array();
    
    // 获取订单列表
    $orders = Todo_Order_Manager::get_orders($filters);
    
    if (empty($orders)) {
        return array(
            'success' => false,
            'message' => '没有找到符合条件的订单'
        );
    }
    
    // 生成CSV文件
    $filename = 'orders_' . date('Y-m-d_H-i-s') . '.csv';
    $filepath = todo_get_upload_dir() . '/exports/' . $filename;
    
    // 确保目录存在
    $export_dir = dirname($filepath);
    if (!file_exists($export_dir)) {
        todo_mkdir_p($export_dir);
    }
    
    // 创建CSV文件
    $fp = fopen($filepath, 'w');
    if (!$fp) {
        return array(
            'success' => false,
            'message' => '无法创建导出文件'
        );
    }
    
    // 写入BOM头，解决中文乱码
    fwrite($fp, "\xEF\xBB\xBF");
    
    // 写入表头
    $headers = array(
        '订单号', '用户', '订单状态', '支付状态', '订单金额', 
        '支付方式', '收货地址', '创建时间', '支付时间', '发货时间'
    );
    fputcsv($fp, $headers);
    
    // 写入数据
    foreach ($orders as $order) {
        $row = array(
            $order['order_no'],
            $order['user_name'] ?? $order['user_id'],
            Todo_Order_Manager::get_status_name($order['status']),
            Todo_Order_Manager::get_payment_status_name($order['payment_status']),
            $order['total_amount'],
            Todo_Payment_Manager::get_method_name($order['payment_method']),
            $order['shipping_address'] ?? '',
            $order['created_at'],
            $order['paid_at'] ?? '',
            $order['shipped_at'] ?? ''
        );
        fputcsv($fp, $row);
    }
    
    fclose($fp);
    
    // 返回下载链接
    $download_url = todo_get_upload_url() . '/exports/' . $filename;
    
    return array(
        'success' => true,
        'download_url' => $download_url,
        'message' => '订单导出成功'
    );
}