<?php
/**
 * 会员升级套餐购买AJAX处理器
 * @package TodoCMS
 */
if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    echo json_encode(['error'=>'Direct access denied']);
    exit;
}
require_once '../../core/bootstrap.php';
require_once '../../core/functions/upgrade-functions.php';
require_once '../includes/ajax-functions.php';

// 权限验证
if (!is_user_logged_in()) {
    echo json_encode(['error'=>'未登录，无法购买套餐']);
    exit;
}
$user_id = todo_get_current_user_id();
if (!todo_user_can($user_id, 'upgrade_member')) {
    echo json_encode(['error'=>'权限不足']);
    exit;
}

// CSRF验证
if (!isset($_POST['_upgrade_nonce']) || !todo_verify_request_csrf($_POST['_upgrade_nonce'], 'upgrade_package_purchase')) {
    echo json_encode(['error'=>'CSRF令牌验证失败']);
    exit;
}

// 获取套餐ID
$package_id = intval($_POST['package_id'] ?? 0);
if ($package_id <= 0) {
    echo json_encode(['error'=>'无效的套餐ID']);
    exit;
}
$package = todo_get_upgrade_package($package_id);
if (!$package || $package['status'] != 1) {
    echo json_encode(['error'=>'套餐不存在或已下架']);
    exit;
}

// 检查购买限制
if (!todo_can_user_upgrade($user_id, $package_id)) {
    echo json_encode(['error'=>'不满足升级条件']);
    exit;
}

// 创建订单
$order = todo_create_upgrade_order($user_id, $package_id);
if (!$order) {
    echo json_encode(['error'=>'订单创建失败']);
    exit;
}

// 调用Payjs支付插件
require_once '../../content/plugins/PayjsAli/payjs.php';
$payjs = new Payjs();
$pay_params = [
    'body' => '会员升级-' . $package['title'],
    'out_trade_no' => $order['order_no'],
    'total_fee' => intval($order['amount'] * 100),
    'notify_url' => todo_get_payjs_notify_url(),
    'attach' => json_encode(['user_id'=>$user_id,'order_id'=>$order['id']]),
    'openid' => todo_get_user_openid($user_id)
];
$pay_result = $payjs->unifiedOrder($pay_params);
if (!$pay_result || $pay_result['return_code'] != 1) {
    echo json_encode(['error'=>'支付接口调用失败','msg'=>$pay_result['return_msg'] ?? '未知错误']);
    exit;
}

// 返回支付二维码和订单信息
$response = [
    'success'=>true,
    'order_id'=>$order['id'],
    'order_no'=>$order['order_no'],
    'amount'=>$order['amount'],
    'package_title'=>$package['title'],
    'payjs_qr'=>$pay_result['qrcode'],
    'payjs_order_id'=>$pay_result['payjs_order_id'],
    'expire_time'=>$order['expire_time']
];
echo json_encode($response);
exit;
