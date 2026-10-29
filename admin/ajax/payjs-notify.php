<?php
/**
 * Payjs支付结果回调处理器
 * @package TodoCMS
 */
if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}
header('Content-Type: application/json; charset=utf-8');
require_once '../../core/bootstrap.php';
require_once '../../core/functions/upgrade-functions.php';
require_once '../includes/ajax-functions.php';
require_once '../../content/plugins/PayjsAli/payjs.php';

// Payjs回调参数
$data = $_POST;
if (!isset($data['payjs_order_id']) || !isset($data['out_trade_no']) || !isset($data['total_fee'])) {
    echo json_encode(['error'=>'参数不完整']);
    exit;
}

$order_no = $data['out_trade_no'];
$order = todo_get_upgrade_order_by_no($order_no);
if (!$order) {
    echo json_encode(['error'=>'订单不存在']);
    exit;
}
if ($order['status'] == 'paid') {
    echo json_encode(['success'=>true,'msg'=>'订单已支付']);
    exit;
}

// 验证支付金额
$order_cny = function_exists('todo_convert_base_to_cny') ? todo_convert_base_to_cny($order['amount']) : floatval($order['amount']);
if (intval($data['total_fee']) != intval(round($order_cny * 100))) {
    echo json_encode(['error'=>'支付金额不匹配']);
    exit;
}

// 验证Payjs签名
$payjs = new Payjs();
if (!$payjs->checkSign($data)) {
    echo json_encode(['error'=>'签名验证失败']);
    exit;
}

// 更新订单状态
$update = todo_update_upgrade_order_status($order['id'], 'paid', $data['payjs_order_id']);
if (!$update) {
    echo json_encode(['error'=>'订单状态更新失败']);
    exit;
}

// 升级会员等级
$upgrade = todo_upgrade_user_level($order['user_id'], $order['package_id']);
if (!$upgrade) {
    echo json_encode(['error'=>'会员等级升级失败']);
    exit;
}

// 发送通知
$notify = todo_create_upgrade_notification($order['user_id'], $order['package_id'], $order['id']);

// 返回成功
echo json_encode(['success'=>true,'msg'=>'会员升级成功']);
exit;
