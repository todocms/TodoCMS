<?php
/**
 * A站接收端点 - 接收B站支付成功后的异步通知
 */

define('TODO_LOADED', true);
define('ABSPATH', dirname(__DIR__) . '/');

require_once ABSPATH . 'core/bootstrap.php';

$api = new Todo_AB_Payment_API();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid request method']));
}

$post_data = $_POST;

if (class_exists('Todo_Error_Handler')) {
    Todo_Error_Handler::log("AB_PAYMENT [A-Site Webhook] Received webhook payload", Todo_Error_Handler::LEVEL_INFO, ['data' => $post_data]);
}

// 验证来自B站的签名
if (!$api->verify_signature($post_data)) {
    if (class_exists('Todo_Error_Handler')) {
        Todo_Error_Handler::log("AB_PAYMENT [A-Site Webhook] Signature verification failed", Todo_Error_Handler::LEVEL_ERROR, ['data' => $post_data]);
    }
    http_response_code(400);
    die(json_encode(['error' => 'Invalid signature']));
}

// 检查防重放
if (time() - intval($post_data['timestamp'] ?? 0) > 300) {
    if (class_exists('Todo_Error_Handler')) {
        Todo_Error_Handler::log("AB_PAYMENT [A-Site Webhook] Request expired", Todo_Error_Handler::LEVEL_ERROR, ['timestamp' => $post_data['timestamp'] ?? null]);
    }
    http_response_code(400);
    die(json_encode(['error' => 'Request expired']));
}

// 提取数据
$a_order_no = sanitize_text_field((string) ($post_data['a_order_no'] ?? ''));
$b_order_no = sanitize_text_field((string) ($post_data['b_order_no'] ?? ''));
$status = sanitize_text_field((string) ($post_data['status'] ?? ''));
$transaction_id = sanitize_text_field((string) ($post_data['transaction_id'] ?? ''));
$paid_amount = round(floatval($post_data['amount'] ?? 0), 2);
$paid_currency = strtoupper(trim(sanitize_text_field((string) ($post_data['currency'] ?? ''))));

if ($a_order_no === '' || $b_order_no === '' || $transaction_id === '') {
    if (class_exists('Todo_Error_Handler')) {
        Todo_Error_Handler::log("AB_PAYMENT [A-Site Webhook] Missing required fields", Todo_Error_Handler::LEVEL_ERROR, ['a_order_no' => $a_order_no, 'b_order_no' => $b_order_no, 'transaction_id' => $transaction_id]);
    }
    http_response_code(400);
    die(json_encode(['error' => 'Missing required fields']));
}

if ($status === 'paid') {
    // 找到A站订单并更新状态
    global $tododb;
    $table = $tododb->prefix . 'orders';
    
    // 获取订单ID（不限制 ab_order_type，允许空或 normal/site_a）
    $order = $tododb->get_row("SELECT * FROM {$table} WHERE order_no = ?", [$a_order_no], ARRAY_A);
    
    if (!$order) {
        if (class_exists('Todo_Error_Handler')) {
            Todo_Error_Handler::log("AB_PAYMENT [A-Site Webhook] Order {$a_order_no} not found in database", Todo_Error_Handler::LEVEL_ERROR);
        }
        http_response_code(404);
        die(json_encode(['error' => "Order {$a_order_no} not found"]));
    }

    $expected_amount = round(floatval($order['total_amount'] ?? 0), 2);
    $existing_b_order_no = sanitize_text_field((string) ($order['ab_ref_order_no'] ?? ''));

    if ($paid_amount <= 0 || abs($paid_amount - $expected_amount) > 0.01) {
        if (class_exists('Todo_Error_Handler')) {
            Todo_Error_Handler::log("AB_PAYMENT [A-Site Webhook] Amount mismatch for {$a_order_no} (Expected {$expected_amount}, Got {$paid_amount})", Todo_Error_Handler::LEVEL_ERROR);
        }
        http_response_code(400);
        die(json_encode(['error' => 'Amount mismatch']));
    }

    if ($existing_b_order_no !== '' && $existing_b_order_no !== $b_order_no) {
        if (class_exists('Todo_Error_Handler')) {
            Todo_Error_Handler::log("AB_PAYMENT [A-Site Webhook] B order mismatch for {$a_order_no} (Expected {$existing_b_order_no}, Got {$b_order_no})", Todo_Error_Handler::LEVEL_ERROR);
        }
        http_response_code(400);
        die(json_encode(['error' => 'B order mismatch']));
    }

    $pay_method = !empty($post_data['payment_method']) ? sanitize_text_field($post_data['payment_method']) : 'paypal';
    $now_time = date('Y-m-d H:i:s');

    // 1. 优先更新 A 站订单的关联 B 站订单号与 AB 订单类型标识
    Todo_Order_Manager::update_order($order['id'], [
        'ab_order_type'    => 'site_a',
        'ab_ref_order_no' => $b_order_no
    ]);

    // 2. 调用规范方法更新支付状态
    // (内部会自动将支付状态更变为 '已付款'，添加支付记录，并且当订单原状态为 '待付款' 时自动将订单状态更新为 '待发货'，记录两份对齐日志)
    Todo_Order_Manager::update_payment_status($order['id'], Todo_Order_Manager::PAYMENT_PAID, [
        'payment_method' => $pay_method,
        'transaction_id' => $transaction_id,
        'paid_at'        => $now_time,
        'payment_time'   => $now_time,
        'paid_amount'    => $paid_amount
    ], 0, "");

    // 3. 关联同步 B站传回的 PayPal 回调地址与风控凭证
    $paypal_order_id = !empty($post_data['paypal_order_id']) ? sanitize_text_field($post_data['paypal_order_id']) : '';
    $paypal_cb_addr = !empty($post_data['paypal_callback_address']) ? (is_array($post_data['paypal_callback_address']) ? $post_data['paypal_callback_address'] : json_decode($post_data['paypal_callback_address'], true)) : null;
    $anti_fraud_evidence = !empty($post_data['anti_fraud_evidence']) ? (is_array($post_data['anti_fraud_evidence']) ? $post_data['anti_fraud_evidence'] : json_decode($post_data['anti_fraud_evidence'], true)) : null;

    if (!empty($paypal_order_id)) {
        Todo_Order_Manager::save_order_custom_field($order['id'], '_paypal_order_id', $paypal_order_id);
    }

    if (!empty($anti_fraud_evidence) && is_array($anti_fraud_evidence)) {
        Todo_Order_Manager::save_order_custom_field($order['id'], '_anti_fraud_evidence', $anti_fraud_evidence);
        if (function_exists('todo_update_post_meta')) {
            todo_update_post_meta($order['id'], '_anti_fraud_evidence', $anti_fraud_evidence);
        }
        if (empty($paypal_cb_addr) && !empty($anti_fraud_evidence['paypal_callback_address'])) {
            $paypal_cb_addr = $anti_fraud_evidence['paypal_callback_address'];
        }
        if (!empty($anti_fraud_evidence['address_comparison']) && is_array($anti_fraud_evidence['address_comparison'])) {
            Todo_Order_Manager::save_order_custom_field($order['id'], '_address_match_status', $anti_fraud_evidence['address_comparison']['status'] ?? 'MATCH');
            Todo_Order_Manager::save_order_custom_field($order['id'], '_address_match_details', $anti_fraud_evidence['address_comparison']);
        }
    }

    if (!empty($paypal_cb_addr) && is_array($paypal_cb_addr)) {
        Todo_Order_Manager::save_order_custom_field($order['id'], '_paypal_callback_address', $paypal_cb_addr);
        
        $existing_cmp = Todo_Order_Manager::get_order_custom_field($order['id'], '_address_match_details');
        if (empty($existing_cmp) || empty($existing_cmp['paypal_address'])) {
            $user_shipping = Todo_Order_Manager::get_order_custom_field($order['id'], '_checkout_shipping_address') ?: ($order['shipping_address'] ?? '');
            if (function_exists('todo_compare_addresses')) {
                $cmp = todo_compare_addresses($user_shipping, $paypal_cb_addr);
                Todo_Order_Manager::save_order_custom_field($order['id'], '_address_match_status', $cmp['status']);
                Todo_Order_Manager::save_order_custom_field($order['id'], '_address_match_details', $cmp);
            }
        }
    }

    // 4. 触发 AB 订单拉取与全量动态数据对齐同步
    $api->sync_ab_order($order['id']);
    
    if (class_exists('Todo_Error_Handler')) {
        Todo_Error_Handler::log("AB_PAYMENT [A-Site Webhook] Order {$a_order_no} status updated to paid successfully via B order {$b_order_no}", Todo_Error_Handler::LEVEL_INFO);
    }

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Invalid payment status']);

