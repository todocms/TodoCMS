<?php
/**
 * B站接收端点 - 接收A站的支付请求并生成影子订单
 */

define('TODO_LOADED', true);
define('ABSPATH', dirname(__DIR__) . '/');

require_once ABSPATH . 'core/bootstrap.php'; 

$api = new Todo_AB_Payment_API();

$request_data = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

if (empty($request_data)) {
    die(json_encode(['error' => 'Invalid request data']));
}

$action = $request_data['action'] ?? '';
if ($action === 'sync_ab_order_remote') {
    header('Content-Type: application/json');
    $sync_res = $api->handle_remote_sync_request($request_data);
    if (is_todo_error($sync_res)) {
        echo json_encode(['error' => $sync_res->get_error_message()]);
    } else {
        echo json_encode($sync_res);
    }
    exit;
}

$result = $api->handle_a_site_request($request_data);

if (is_todo_error($result)) {
    die(json_encode(['error' => $result->get_error_message()]));
}

// B站直接调用 PayPal 接口生成真正扣款链接
if (isset($result['b_order_id'])) {
    global $tododb;
    $b_order = Todo_Order_Manager::get_order($result['b_order_id'], true);
    if ($b_order) {
        try {
            if (!function_exists('process_paypal_payment')) {
                define('AB_INTERNAL_INCLUDE', true);
                require_once ABSPATH . 'includes/ajax/payment-process.php';
            }
            $paypal_res = process_paypal_payment($b_order);
            if (!empty($paypal_res['pay_url'])) {
                if (class_exists('Todo_Error_Handler')) {
                    Todo_Error_Handler::log("AB_PAYMENT [B-Site Direct] Generated PayPal approve URL: {$paypal_res['pay_url']}", Todo_Error_Handler::LEVEL_INFO);
                }
                
                // 如果是 A 站后端 API 请求（包含 json 参数或 format=json），直接返回 JSON json 包含 pay_url
                if (isset($request_data['format']) && $request_data['format'] === 'json') {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'b_order_no' => $result['b_order_no'],
                        'pay_url' => $paypal_res['pay_url']
                    ]);
                    exit;
                }

                // 否则 HTTP 跳转到 PayPal 官方授权页面
                header('Location: ' . $paypal_res['pay_url']);
                exit;
            }
        } catch (Exception $e) {
            if (class_exists('Todo_Error_Handler')) {
                Todo_Error_Handler::log("AB_PAYMENT [B-Site Direct] PayPal order creation failed: " . $e->getMessage(), Todo_Error_Handler::LEVEL_ERROR);
            }
            header('Content-Type: application/json');
            die(json_encode(['error' => 'PayPal 创建支付订单失败: ' . $e->getMessage()]));
        }
    }
}
