<?php
/**
 * TodoCMS 前端用户钱包充值处理器
 */

// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', 0);

// 设置JSON响应头
header('Content-Type: application/json; charset=utf-8');

// 定义 ABSPATH 常量
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
}

try {
    // 加载核心文件
    require_once(ABSPATH . 'config.php');
    require_once(ABSPATH . 'core/bootstrap.php');
    require_once(ABSPATH . 'core/functions/function-loader.php');

    // 使用函数加载器加载核心函数
    Todo_Function_Loader::load_core_functions();

    if (class_exists('Todo_Session_Manager')) {
        Todo_Session_Manager::init();
    } elseif (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

} catch (Exception $e) {
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'message' => 'Core loading error: ' . $e->getMessage()
    ]);
    exit;
}

// 检查登录状态
if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'message' => __('请先登录')
    ]);
    exit;
}

// 检查是否为POST请求
if (todo_server_string('REQUEST_METHOD') !== 'POST') {
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'message' => __('只允许POST请求')
    ]);
    exit;
}

// 检查AJAX请求
if (!todo_is_ajax_request()) {
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'message' => __('非法访问')
    ]);
    exit;
}

// PRG 防重复提交守卫（仅对携带 _submit_key 的 POST 生效）
Todo_PRG_Guard::guard_endpoint('user_recharge', 30);

try {
    // 验证CSRF令牌
    $csrf_token = todo_post_string('_csrf_token');
    if ($csrf_token === '') {
        $csrf_token = todo_post_string('_todononce');
    }
    if (function_exists('todo_verify_csrf_token')) {
        if (!empty($csrf_token) && !todo_verify_csrf_token($csrf_token, 'recharge')) {
            throw new Exception(__('安全验证失败，请刷新页面重试'));
        }
    }

    $amount = (float) todo_post_string('amount', '0');
    $payment_method = trim(todo_post_string('payment_method'));

    if ($amount < 0.01) {
        throw new Exception(__('充值金额不能少于 0.01'));
    }

    if (empty($payment_method)) {
        throw new Exception(__('请选择支付方式'));
    }

    // 充值严禁选择余额支付、线下汇款或货到付款
    if (in_array(strtolower($payment_method), ['balance', 'offline', 'cod'], true)) {
        throw new Exception(__('不支持的充值支付方式'));
    }

    $user_id = function_exists('get_current_user_id') ? get_current_user_id() : 0;
    if ($user_id <= 0) {
        throw new Exception(__('获取用户信息失败，请重新登录'));
    }

    global $tododb;

    // 检查并进行基准汇率转换：输入的金额 $amount 为当前语言/货币环境下的金额，需要转为系统基准/默认主货币保存
    if (function_exists('todo_get_currency_config') && function_exists('todo_get_default_currency_config')) {
        $currency_cfg = todo_get_currency_config();
        $default_cfg = todo_get_default_currency_config();
        $curr_rate = ($currency_cfg['status'] == 1 && floatval($currency_cfg['rate']) > 0) ? floatval($currency_cfg['rate']) : 1.0;
        $base_rate = floatval($default_cfg['rate']) > 0 ? floatval($default_cfg['rate']) : 1.0;
        $ratio = $curr_rate / $base_rate;
        if ($ratio > 0) {
            $amount = round($amount / $ratio, 2);
        }
    }

    if ($amount < 0.01) {
        throw new Exception(__('转换后的系统基准充值金额不能少于 0.01'));
    }

    // 创建充值记录
    $recharge_sn = 'RC' . date('YmdHis') . rand(1000, 9999);
    $data = [
        'user_id'        => $user_id,
        'amount'         => $amount,
        'bonus_amount'   => 0.00,
        'total_amount'   => $amount,
        'payment_method' => $payment_method,
        'status'         => 'pending',
        'created_at'     => date('Y-m-d H:i:s'),
        'updated_at'     => date('Y-m-d H:i:s')
    ];

    $result = $tododb->insert($tododb->prefix . 'recharge_records', $data);
    if (!$result) {
        throw new Exception(__('创建充值记录失败'));
    }

    $recharge_id = $tododb->insert_id;

    // 构建收银台支付链接（语言感知）
    if (function_exists('todo_get_user_page_url')) {
        $payment_url = todo_get_user_page_url('payment') . '&recharge_id=' . $recharge_id . '&pay_method=' . urlencode($payment_method);
    } else {
        $payment_url = function_exists('todo_get_site_url') ? todo_get_site_url('/?user=payment&recharge_id=' . $recharge_id . '&pay_method=' . urlencode($payment_method)) : '/?user=payment&recharge_id=' . $recharge_id;
    }

    echo json_encode([
        'success'     => true,
        'message'     => __('充值订单创建成功，跳转支付中'),
        'payment_url' => $payment_url,
        'data'        => [
            'recharge_id' => $recharge_id,
            'amount'      => $amount,
            'method'      => $payment_method
        ]
    ]);

} catch (Exception $e) {
    if (function_exists('todo_log_error')) {
        todo_log_error('Recharge Error: ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'ip'   => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
    }

    http_response_code(200);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
