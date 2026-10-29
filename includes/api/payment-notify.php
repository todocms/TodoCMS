<?php
/**
 * TodoCMS 支付通知处理接口
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    if (!defined('ABSPATH')) {
        define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
    }
    require_once ABSPATH . 'core/bootstrap.php';
    require_once ABSPATH . 'core/functions/function-loader.php';
    if (class_exists('Todo_Function_Loader')) {
        Todo_Function_Loader::load_core_functions();
    }
}

// 允许支付异步回调及沙盒成功测试通行
if (defined('TODO_DEBUG') && TODO_DEBUG) {
    // 调试模式放行
} elseif (class_exists('Todo_Core_Kernel') && is_callable(['Todo_Core_Kernel', 'check_runtime_license']) && !call_user_func(['Todo_Core_Kernel', 'check_runtime_license'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Direct access denied']);
    exit;
}

/**
 * PayJS支付通知处理
 */
function todo_handle_payjs_notify() {
    try {
        // 获取POST数据
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data) {
            // 尝试从$_POST获取数据
            $data = $_POST;
        }
        
        if (class_exists('Todo_Error_Handler')) {
            Todo_Error_Handler::log("PAYJS_NOTIFY [Received] Payload: " . json_encode($data, JSON_UNESCAPED_UNICODE), Todo_Error_Handler::LEVEL_INFO);
        }
        
        // 验证必要参数
        if (!isset($data['out_trade_no']) || !isset($data['total_fee']) || !isset($data['sign'])) {
            throw new Exception('参数不完整');
        }
        
        $out_trade_no = $data['out_trade_no'];
        $total_fee = intval($data['total_fee']);
        $sign = $data['sign'];

        if (isset($data['return_code']) && intval($data['return_code']) !== 1) {
            throw new Exception('支付状态无效');
        }

        $key = '';
        $opts = function_exists('todo_get_option') ? todo_get_option('todo_payment_settings', array()) : array();
        if (is_array($opts) && isset($opts['third_party']) && is_array($opts['third_party']) && isset($opts['third_party']['payjs']) && is_array($opts['third_party']['payjs'])) {
            $key = isset($opts['third_party']['payjs']['key']) ? (string) $opts['third_party']['payjs']['key'] : '';
        }
        if ($key === '' && function_exists('todo_get_option')) {
            $key = (string) todo_get_option('payjs_key', '');
        }
        if ($key === '' && function_exists('get_option')) {
            $key = (string) get_option('payjs_key', '');
        }
        if ($key === '') {
            throw new Exception('PayJS配置不存在');
        }
        
        // 验证签名
        $sign_data = $data;
        unset($sign_data['sign']);
        ksort($sign_data);
        
        $sign_str = '';
        foreach ($sign_data as $k => $v) {
            if ($v !== '' && $v !== null) {
                $sign_str .= $k . '=' . $v . '&';
            }
        }
        $sign_str .= 'key=' . $key;
        
        $calculated_sign = strtoupper(md5($sign_str));
        
        if ($sign !== $calculated_sign) {
            if (class_exists('Todo_Error_Handler')) {
                Todo_Error_Handler::log("PAYJS_NOTIFY [Sign Error] Expected: {$calculated_sign}, Got: {$sign}", Todo_Error_Handler::LEVEL_ERROR);
            }
            throw new Exception('签名验证失败');
        }

        global $tododb;

        // 判断是否为充值订单（RC开头的单号）
        if (strpos($out_trade_no, 'RC') === 0) {
            $raw_num = substr($out_trade_no, 2);
            $rc_id = (strlen($raw_num) > 6) ? intval(substr($raw_num, -6)) : intval($raw_num);

            if (class_exists('Todo_Error_Handler')) {
                Todo_Error_Handler::log("PAYJS_NOTIFY [Recharge] Handling recharge order RC_ID: {$rc_id}, out_trade_no: {$out_trade_no}", Todo_Error_Handler::LEVEL_INFO);
            }

            $rc_row = $tododb->get_row($tododb->prepare("SELECT * FROM {$tododb->prefix}recharge_records WHERE id = %d LIMIT 1", $rc_id), [], 'ARRAY_A');
            if (is_object($rc_row)) $rc_row = (array) $rc_row;

            if (!$rc_row) {
                throw new Exception('充值记录不存在: ' . $out_trade_no);
            }

            // 校验充值金额
            $rc_cny = function_exists('todo_convert_base_to_cny') ? todo_convert_base_to_cny($rc_row['amount']) : floatval($rc_row['amount']);
            $expected_fee = intval(round($rc_cny * 100));
            if ($total_fee !== $expected_fee) {
                if (class_exists('Todo_Error_Handler')) {
                    Todo_Error_Handler::log("PAYJS_NOTIFY [Recharge Amount Mismatch] Expected: {$expected_fee}, Got: {$total_fee}", Todo_Error_Handler::LEVEL_ERROR);
                }
                throw new Exception('充值金额不匹配');
            }

            if (($rc_row['status'] ?? '') !== 'completed' && ($rc_row['status'] ?? '') !== 'paid') {
                $tododb->update(
                    $tododb->prefix . 'recharge_records',
                    [
                        'status' => 'completed',
                        'completed_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql')
                    ],
                    ['id' => $rc_id]
                );

                $user_id = intval($rc_row['user_id']);
                $amount = floatval($rc_row['amount']);
                $method_name = (isset($rc_row['payment_method']) && strpos($rc_row['payment_method'], 'alipay') !== false) ? 'PayJS支付宝' : 'PayJS微信';
                $log_desc = $method_name . ': ' . $out_trade_no;

                if (function_exists('todo_core_add_user_balance')) {
                    todo_core_add_user_balance($user_id, $amount, 'recharge', $log_desc, $rc_id);
                } else {
                    $old_balance = floatval(get_user_meta($user_id, 'todo_balance', true) ?: 0);
                    $new_balance = $old_balance + $amount;
                    update_user_meta($user_id, 'todo_balance', $new_balance);
                    if (function_exists('todo_add_balance_log')) {
                        todo_add_balance_log($user_id, $amount, 'recharge', $log_desc);
                    }
                }

                if (class_exists('Todo_Error_Handler')) {
                    Todo_Error_Handler::log("PAYJS_NOTIFY [Recharge Success] Updated balance for User {$user_id}, Amount: {$amount}", Todo_Error_Handler::LEVEL_INFO);
                }
            }

            echo 'success';
            exit;
        }

        // 普通商城订单
        $order = Todo_Order_Manager::get_order_by_no($out_trade_no);
        if (!$order) {
            if (class_exists('Todo_Error_Handler')) {
                Todo_Error_Handler::log("PAYJS_NOTIFY [Order Not Found] out_trade_no: {$out_trade_no}", Todo_Error_Handler::LEVEL_ERROR);
            }
            throw new Exception('订单不存在：' . $out_trade_no);
        }
        
        // 检查订单是否已支付
        if ($order['payment_status'] === 'paid') {
            echo 'success';
            exit;
        }
        
        // 验证金额
        $order_cny = function_exists('todo_convert_base_to_cny') ? todo_convert_base_to_cny($order['total_amount']) : floatval($order['total_amount']);
        $order_amount = intval(round($order_cny * 100));
        if ($total_fee !== $order_amount) {
            if (class_exists('Todo_Error_Handler')) {
                Todo_Error_Handler::log("PAYJS_NOTIFY [Order Amount Mismatch] Expected: {$order_amount}, Got: {$total_fee}", Todo_Error_Handler::LEVEL_ERROR);
            }
            throw new Exception('金额不匹配');
        }
        
        // 更新订单支付状态
        Todo_Order_Manager::update_payment_status($order['id'], 'paid', array(
            'payment_method' => 'payjs',
            'transaction_id' => $data['payjs_order_id'] ?? '',
            'paid_at' => current_time('mysql'),
            'notify_data' => json_encode($data)
        ));
        
        // 更新订单状态
        Todo_Order_Manager::update_order_status($order['id'], 'processing');
        
        // 发送支付成功通知
        do_action('todo_payment_completed', $order['id'], 'payjs');
        
        if (class_exists('Todo_Error_Handler')) {
            Todo_Error_Handler::log("PAYJS_NOTIFY [Order Success] Order {$order['id']} marked as paid.", Todo_Error_Handler::LEVEL_INFO);
        }

        // 返回成功响应
        echo 'success';
        
    } catch (Exception $e) {
        if (class_exists('Todo_Error_Handler')) {
            Todo_Error_Handler::log("PAYJS_NOTIFY [Exception] " . $e->getMessage(), Todo_Error_Handler::LEVEL_ERROR);
        }
        error_log('PayJS Notify Error: ' . $e->getMessage());
        echo 'fail';
    }
    
    exit;
}

function todo_hupijiao_build_hash($data, $app_secret) {
    if (!is_array($data)) {
        $data = array();
    }
    $filtered = array();
    foreach ($data as $k => $v) {
        if ($k === 'hash' || $k === 'sign' || $k === 'signature') {
            continue;
        }
        if ($v === '' || $v === null) {
            continue;
        }
        $filtered[(string) $k] = (string) $v;
    }
    ksort($filtered);
    $sign_str = '';
    foreach ($filtered as $k => $v) {
        $sign_str .= $k . '=' . $v . '&';
    }
    if ($sign_str !== '') {
        $sign_str = substr($sign_str, 0, -1);
    }
    $sign_str .= (string) $app_secret;
    return md5($sign_str);
}

function todo_handle_hupijiao_notify() {
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        if (!$data) {
            $data = $_POST;
        }

        

        $trade_order_id = isset($data['trade_order_id']) ? (string) $data['trade_order_id'] : '';
        $total_fee = isset($data['total_fee']) ? (float) $data['total_fee'] : 0.0;
        $appid = isset($data['appid']) ? (string) $data['appid'] : '';
        $hash = isset($data['hash']) ? (string) $data['hash'] : '';
        $status = isset($data['status']) ? (string) $data['status'] : '';

        if ($trade_order_id === '' || $total_fee <= 0 || $appid === '' || $hash === '') {
            throw new Exception('参数不完整');
        }
        if ($status !== '' && $status !== 'OD') {
            throw new Exception('支付未完成');
        }

        $opts = function_exists('todo_get_option') ? todo_get_option('todo_payment_settings', array()) : array();
        $tp = (is_array($opts) && isset($opts['third_party']) && is_array($opts['third_party'])) ? $opts['third_party'] : array();
        $hp = (isset($tp['hupijiao']) && is_array($tp['hupijiao'])) ? $tp['hupijiao'] : array();

        $app_secret = isset($hp['app_secret']) ? (string) $hp['app_secret'] : '';
        $config_appid = isset($hp['app_id']) ? (string) $hp['app_id'] : '';
        if ($app_secret === '') {
            throw new Exception('虎皮椒配置不存在');
        }
        if ($config_appid !== '' && $config_appid !== $appid) {
            throw new Exception('AppID不匹配');
        }

        $calculated = todo_hupijiao_build_hash($data, $app_secret);
        if (strtolower($hash) !== strtolower($calculated)) {
            throw new Exception('签名验证失败');
        }

        $order = Todo_Order_Manager::get_order_by_no($trade_order_id);
        if (!$order) {
            throw new Exception('订单不存在：' . $trade_order_id);
        }
        if (isset($order['payment_status']) && $order['payment_status'] === 'paid') {
            echo 'success';
            exit;
        }

        $order_cny = function_exists('todo_convert_base_to_cny') ? todo_convert_base_to_cny($order['total_amount']) : floatval($order['total_amount']);
        $order_amount = intval(round($order_cny * 100));
        $notify_amount = intval(round($total_fee * 100));
        if ($order_amount !== $notify_amount) {
            throw new Exception('金额不匹配');
        }

        $transaction_id = '';
        if (isset($data['transaction_id']) && $data['transaction_id'] !== '') {
            $transaction_id = (string) $data['transaction_id'];
        } elseif (isset($data['open_order_id']) && $data['open_order_id'] !== '') {
            $transaction_id = (string) $data['open_order_id'];
        }

        Todo_Order_Manager::update_payment_status($order['id'], 'paid', array(
            'payment_method' => 'hupijiao',
            'transaction_id' => $transaction_id,
            'paid_at' => current_time('mysql'),
            'notify_data' => json_encode($data)
        ));

        Todo_Order_Manager::update_order_status($order['id'], 'processing');
        do_action('todo_payment_completed', $order['id'], 'hupijiao');

        echo 'success';
    } catch (Exception $e) {
        error_log('Hupijiao Notify Error: ' . $e->getMessage());
        echo 'fail';
    }
    exit;
}

/**
 * 支付宝支付通知处理
 */
function todo_handle_alipay_notify() {
    try {
        $data = $_POST;
        
        

        $sign = isset($data['sign']) ? (string) $data['sign'] : '';
        $sign_type = isset($data['sign_type']) ? (string) $data['sign_type'] : 'RSA2';
        if ($sign === '') {
            throw new Exception('缺少签名');
        }

        $opts = function_exists('todo_get_option') ? todo_get_option('todo_payment_settings', array()) : array();
        $alipay = isset($opts['alipay']) && is_array($opts['alipay']) ? $opts['alipay'] : array();
        if (empty($alipay['enabled'])) {
            throw new Exception('支付宝未启用');
        }
        $public_key = isset($alipay['public_key']) ? (string) $alipay['public_key'] : '';
        if ($public_key === '') {
            throw new Exception('支付宝公钥缺失');
        }
        $public_key = trim($public_key);
        if (strpos($public_key, 'BEGIN') === false) {
            $public_key = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(str_replace(array("\r", "\n", ' '), '', $public_key), 64, "\n") . "-----END PUBLIC KEY-----\n";
        }

        $verify_data = $data;
        unset($verify_data['sign'], $verify_data['sign_type']);
        ksort($verify_data);
        $sign_content = '';
        foreach ($verify_data as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $sign_content .= $k . '=' . urldecode((string) $v) . '&';
        }
        if ($sign_content !== '') {
            $sign_content = substr($sign_content, 0, -1);
        }

        $algo = ($sign_type === 'RSA') ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256;
        $ok = openssl_verify($sign_content, base64_decode($sign), $public_key, $algo);
        if ($ok !== 1) {
            throw new Exception('签名验证失败');
        }

        $out_trade_no = isset($data['out_trade_no']) ? (string) $data['out_trade_no'] : '';
        $trade_status = isset($data['trade_status']) ? (string) $data['trade_status'] : '';
        $total_amount = isset($data['total_amount']) ? (float) $data['total_amount'] : 0.0;
        $trade_no = isset($data['trade_no']) ? (string) $data['trade_no'] : '';
        if ($out_trade_no === '' || $total_amount <= 0) {
            throw new Exception('参数不完整');
        }
        if ($trade_status !== 'TRADE_SUCCESS' && $trade_status !== 'TRADE_FINISHED') {
            throw new Exception('支付未完成');
        }

        $order = Todo_Order_Manager::get_order_by_no($out_trade_no);
        if (!$order) {
            throw new Exception('订单不存在：' . $out_trade_no);
        }
        if (isset($order['payment_status']) && (string) $order['payment_status'] === 'paid') {
            echo 'success';
            exit;
        }

        $order_cny = function_exists('todo_convert_base_to_cny') ? todo_convert_base_to_cny($order['total_amount']) : (float) $order['total_amount'];
        if (abs($order_cny - $total_amount) > 0.01) {
            throw new Exception('金额不匹配');
        }

        Todo_Order_Manager::update_payment_status($order['id'], 'paid', array(
            'payment_method' => 'alipay_direct',
            'transaction_id' => $trade_no,
            'paid_at' => current_time('mysql'),
            'notify_data' => json_encode($data)
        ));
        Todo_Order_Manager::update_order_status($order['id'], 'processing');
        do_action('todo_payment_completed', $order['id'], 'alipay_direct');
        
        echo 'success';
        
    } catch (Exception $e) {
        error_log('Alipay Notify Error: ' . $e->getMessage());
        echo 'fail';
    }
    
    exit;
}

/**
 * 微信支付通知处理
 */
function todo_handle_wechat_notify() {
    try {
        $input = file_get_contents('php://input');
        $data = simplexml_load_string($input, 'SimpleXMLElement', LIBXML_NOCDATA);
        
        if (!$data) {
            throw new Exception('数据格式错误');
        }
        
        $notify_data = json_decode(json_encode($data), true);
        
        

        $return_code = isset($notify_data['return_code']) ? (string) $notify_data['return_code'] : '';
        $result_code = isset($notify_data['result_code']) ? (string) $notify_data['result_code'] : '';
        if ($return_code !== 'SUCCESS' || $result_code !== 'SUCCESS') {
            throw new Exception('支付状态无效');
        }

        $sign = isset($notify_data['sign']) ? (string) $notify_data['sign'] : '';
        if ($sign === '') {
            throw new Exception('缺少签名');
        }

        $opts = function_exists('todo_get_option') ? todo_get_option('todo_payment_settings', array()) : array();
        $wechat = isset($opts['wechat']) && is_array($opts['wechat']) ? $opts['wechat'] : array();
        $api_key = isset($wechat['api_key']) ? (string) $wechat['api_key'] : '';
        if (empty($wechat['enabled']) || $api_key === '') {
            throw new Exception('微信支付未启用');
        }

        $verify = $notify_data;
        unset($verify['sign']);
        $filtered = array();
        foreach ($verify as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            $filtered[(string) $k] = (string) $v;
        }
        ksort($filtered);
        $sign_str = '';
        foreach ($filtered as $k => $v) {
            $sign_str .= $k . '=' . $v . '&';
        }
        $sign_str .= 'key=' . $api_key;
        $calc = strtoupper(md5($sign_str));
        if ($calc !== $sign) {
            throw new Exception('签名验证失败');
        }

        $out_trade_no = isset($notify_data['out_trade_no']) ? (string) $notify_data['out_trade_no'] : '';
        $total_fee = isset($notify_data['total_fee']) ? (int) $notify_data['total_fee'] : 0;
        $transaction_id = isset($notify_data['transaction_id']) ? (string) $notify_data['transaction_id'] : '';
        if ($out_trade_no === '' || $total_fee <= 0) {
            throw new Exception('参数不完整');
        }

        $order = Todo_Order_Manager::get_order_by_no($out_trade_no);
        if (!$order) {
            throw new Exception('订单不存在：' . $out_trade_no);
        }
        if (isset($order['payment_status']) && (string) $order['payment_status'] === 'paid') {
            echo '<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>';
            exit;
        }
        $order_amount = (int) round((float) $order['total_amount'] * 100);
        if ($order_amount !== $total_fee) {
            throw new Exception('金额不匹配');
        }

        Todo_Order_Manager::update_payment_status($order['id'], 'paid', array(
            'payment_method' => 'wechat_direct',
            'transaction_id' => $transaction_id,
            'paid_at' => current_time('mysql'),
            'notify_data' => json_encode($notify_data)
        ));
        Todo_Order_Manager::update_order_status($order['id'], 'processing');
        do_action('todo_payment_completed', $order['id'], 'wechat_direct');

        echo '<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>';
        
    } catch (Exception $e) {
        error_log('WeChat Notify Error: ' . $e->getMessage());
        
        // 返回微信要求的失败格式
        $response = '<xml><return_code><![CDATA[FAIL]]></return_code><return_msg><![CDATA[' . $e->getMessage() . ']]></return_msg></xml>';
        echo $response;
    }
    
    exit;
}

function todo_get_paypal_config($client_id = '') {
    $opts = function_exists('todo_get_option') ? todo_get_option('todo_payment_settings', array()) : array();
    $tp = isset($opts['third_party']) && is_array($opts['third_party']) ? $opts['third_party'] : array();
    $pp = isset($tp['paypal']) && is_array($tp['paypal']) ? $tp['paypal'] : array();

    $currency = function_exists('todo_get_default_currency_config') ? (todo_get_default_currency_config()['code'] ?? '') : '';
    $enabled = !empty($tp['enabled']) && !empty($pp['enabled']);

    $accounts = isset($pp['accounts']) && is_array($pp['accounts']) ? $pp['accounts'] : [];
    if (empty($accounts) && !empty($pp['client_id'])) {
        $accounts[] = [
            'client_id' => $pp['client_id'],
            'client_secret' => $pp['client_secret'],
            'webhook_id' => $pp['webhook_id'] ?? ''
        ];
    }

    $result = array(
        'enabled' => $enabled && !empty($accounts),
        'currency' => $currency,
        'accounts' => $accounts,
    );
    
    $selected = null;
    if ($client_id !== '') {
        foreach ($accounts as $acc) {
            if ($acc['client_id'] === $client_id) {
                $selected = $acc;
                break;
            }
        }
    }
    if (!$selected && !empty($accounts)) {
        $selected = $accounts[0];
    }
    
    if ($selected) {
        $result['client_id'] = $selected['client_id'];
        $result['client_secret'] = $selected['client_secret'];
        $result['webhook_id'] = $selected['webhook_id'] ?? '';
    }
    
    return $result;
}

function todo_paypal_api_base() {
    $opts = function_exists('todo_get_option') ? todo_get_option('todo_payment_settings', array()) : array();
    $tp = isset($opts['third_party']) && is_array($opts['third_party']) ? $opts['third_party'] : array();
    $pp = isset($tp['paypal']) && is_array($tp['paypal']) ? $tp['paypal'] : array();
    $is_sandbox = !empty($pp['sandbox']);

    if ($is_sandbox) {
        return 'https://api-m.sandbox.paypal.com';
    }
    return 'https://api-m.paypal.com';
}

function todo_paypal_http_request($method, $url, $headers, $body) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper((string) $method));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    $h = array();
    foreach ((array) $headers as $k => $v) {
        $h[] = $k . ': ' . $v;
    }
    if (!empty($h)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    }
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return array('ok' => false, 'status' => 0, 'body' => '', 'error' => $err);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $body_text = substr($resp, $header_size);
    return array('ok' => true, 'status' => $status, 'body' => $body_text, 'error' => '');
}

function todo_paypal_get_access_token($client_id, $client_secret) {
    static $cached = null;
    if (is_array($cached) && !empty($cached['token'])) {
        return (string) $cached['token'];
    }
    $auth = base64_encode($client_id . ':' . $client_secret);
    $headers = array(
        'Authorization' => 'Basic ' . $auth,
        'Content-Type' => 'application/x-www-form-urlencoded',
    );
    $resp = todo_paypal_http_request('POST', todo_paypal_api_base() . '/v1/oauth2/token', $headers, 'grant_type=client_credentials');
    if (empty($resp['ok']) || (int) $resp['status'] < 200 || (int) $resp['status'] >= 300) {
        throw new Exception('PayPal鉴权失败');
    }
    $data = json_decode((string) $resp['body'], true);
    $token = is_array($data) && isset($data['access_token']) ? (string) $data['access_token'] : '';
    if ($token === '') {
        throw new Exception('PayPal鉴权失败');
    }
    $cached = array('token' => $token);
    return $token;
}

function todo_paypal_verify_webhook_signature($access_token, $webhook_id, $event_body, $server_headers) {
    $payload = array(
        'transmission_id' => isset($server_headers['transmission_id']) ? (string) $server_headers['transmission_id'] : '',
        'transmission_time' => isset($server_headers['transmission_time']) ? (string) $server_headers['transmission_time'] : '',
        'cert_url' => isset($server_headers['cert_url']) ? (string) $server_headers['cert_url'] : '',
        'auth_algo' => isset($server_headers['auth_algo']) ? (string) $server_headers['auth_algo'] : '',
        'transmission_sig' => isset($server_headers['transmission_sig']) ? (string) $server_headers['transmission_sig'] : '',
        'webhook_id' => (string) $webhook_id,
        'webhook_event' => json_decode((string) $event_body, true),
    );

    if (!is_array($payload['webhook_event'])) {
        return false;
    }
    if ($payload['transmission_id'] === '' || $payload['transmission_time'] === '' || $payload['cert_url'] === '' || $payload['auth_algo'] === '' || $payload['transmission_sig'] === '') {
        return false;
    }

    $headers = array(
        'Authorization' => 'Bearer ' . $access_token,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    );
    $resp = todo_paypal_http_request('POST', todo_paypal_api_base() . '/v1/notifications/verify-webhook-signature', $headers, json_encode($payload));
    if (empty($resp['ok']) || (int) $resp['status'] < 200 || (int) $resp['status'] >= 300) {
        return false;
    }
    $data = json_decode((string) $resp['body'], true);
    return is_array($data) && isset($data['verification_status']) && (string) $data['verification_status'] === 'SUCCESS';
}

function todo_paypal_get_order($access_token, $paypal_order_id) {
    $headers = array(
        'Authorization' => 'Bearer ' . $access_token,
        'Accept' => 'application/json',
    );
    $resp = todo_paypal_http_request('GET', todo_paypal_api_base() . '/v2/checkout/orders/' . rawurlencode((string) $paypal_order_id), $headers, null);
    if (empty($resp['ok']) || (int) $resp['status'] < 200 || (int) $resp['status'] >= 300) {
        throw new Exception('PayPal订单查询失败');
    }
    $data = json_decode((string) $resp['body'], true);
    if (!is_array($data) || empty($data['id'])) {
        throw new Exception('PayPal订单查询失败');
    }
    return $data;
}

function todo_paypal_capture_order($access_token, $paypal_order_id) {
    $headers = array(
        'Authorization' => 'Bearer ' . $access_token,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    );
    $resp = todo_paypal_http_request('POST', todo_paypal_api_base() . '/v2/checkout/orders/' . rawurlencode((string) $paypal_order_id) . '/capture', $headers, '{}');
    if (empty($resp['ok']) || (int) $resp['status'] < 200 || (int) $resp['status'] >= 300) {
        throw new Exception('PayPal扣款失败');
    }
    $data = json_decode((string) $resp['body'], true);
    if (!is_array($data) || empty($data['id'])) {
        throw new Exception('PayPal扣款失败');
    }
    return $data;
}

function todo_paypal_extract_invoice_id($paypal_order) {
    if (!is_array($paypal_order)) {
        return '';
    }
    if (!empty($paypal_order['purchase_units']) && is_array($paypal_order['purchase_units'])) {
        foreach ($paypal_order['purchase_units'] as $pu) {
            if (is_array($pu) && isset($pu['invoice_id']) && (string) $pu['invoice_id'] !== '') {
                return (string) $pu['invoice_id'];
            }
        }
    }
    return '';
}

function todo_paypal_extract_capture_id($capture_result) {
    if (!is_array($capture_result)) {
        return '';
    }
    if (!empty($capture_result['purchase_units']) && is_array($capture_result['purchase_units'])) {
        foreach ($capture_result['purchase_units'] as $pu) {
            if (!is_array($pu)) {
                continue;
            }
            if (!empty($pu['payments']) && is_array($pu['payments']) && !empty($pu['payments']['captures']) && is_array($pu['payments']['captures'])) {
                foreach ($pu['payments']['captures'] as $cap) {
                    if (is_array($cap) && !empty($cap['id'])) {
                        return (string) $cap['id'];
                    }
                }
            }
        }
    }
    return '';
}

function todo_paypal_extract_amount_info($paypal_payload) {
    if (!is_array($paypal_payload)) {
        return array('value' => 0.0, 'currency' => '');
    }

    if (!empty($paypal_payload['purchase_units']) && is_array($paypal_payload['purchase_units'])) {
        foreach ($paypal_payload['purchase_units'] as $pu) {
            if (!is_array($pu)) {
                continue;
            }
            if (!empty($pu['payments']) && is_array($pu['payments']) && !empty($pu['payments']['captures']) && is_array($pu['payments']['captures'])) {
                foreach ($pu['payments']['captures'] as $cap) {
                    if (is_array($cap) && !empty($cap['amount']) && is_array($cap['amount'])) {
                        return array(
                            'value' => floatval($cap['amount']['value'] ?? 0),
                            'currency' => (string) ($cap['amount']['currency_code'] ?? '')
                        );
                    }
                }
            }
            if (!empty($pu['amount']) && is_array($pu['amount'])) {
                return array(
                    'value' => floatval($pu['amount']['value'] ?? 0),
                    'currency' => (string) ($pu['amount']['currency_code'] ?? '')
                );
            }
        }
    }

    if (!empty($paypal_payload['amount']) && is_array($paypal_payload['amount'])) {
        return array(
            'value' => floatval($paypal_payload['amount']['value'] ?? 0),
            'currency' => (string) ($paypal_payload['amount']['currency_code'] ?? '')
        );
    }

    return array('value' => 0.0, 'currency' => '');
}

function todo_paypal_assert_amount_matches($paypal_payload, $expected_amount_base, $expected_currency = '') {
    $amount_info = todo_paypal_extract_amount_info($paypal_payload);
    $actual_amount = round(floatval($amount_info['value'] ?? 0), 2);
    $actual_currency = strtoupper(trim((string) ($amount_info['currency'] ?? '')));
    $expected_currency = strtoupper(trim((string) $expected_currency));

    if ($expected_currency === '') {
        $expected_currency = $actual_currency;
    }

    $expected_amount = function_exists('todo_convert_base_to_currency') 
        ? todo_convert_base_to_currency($expected_amount_base, $expected_currency) 
        : round(floatval($expected_amount_base), 2);

    if (abs($actual_amount - $expected_amount) > 0.01) {
        throw new Exception('PayPal金额校验失败 (期望 ' . $expected_amount . ' ' . $expected_currency . ', 实际 ' . $actual_amount . ' ' . $actual_currency . ')');
    }
    if ($expected_currency !== '' && $actual_currency !== '' && $actual_currency !== $expected_currency) {
        throw new Exception('PayPal币种校验失败');
    }
}

function todo_handle_paypal_return() {
    try {
        $cfg = todo_get_paypal_config();
        if (empty($cfg['enabled'])) {
            throw new Exception('PayPal未启用');
        }

        $order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
        $order_no = isset($_GET['order_no']) ? (string) $_GET['order_no'] : '';
        $paypal_order_id = isset($_GET['token']) ? (string) $_GET['token'] : '';
        $is_recharge = isset($_GET['is_recharge']) && $_GET['is_recharge'] === '1';

        if ($order_id <= 0 || $order_no === '' || $paypal_order_id === '') {
            throw new Exception('参数不完整');
        }

        global $tododb;

        if ($is_recharge || strpos($order_no, 'RC') === 0) {
            // 处理充值订单 PayPal 回调
            $raw_num = substr($order_no, 2);
            $rid = (strlen($raw_num) > 6) ? intval(substr($raw_num, -6)) : intval($raw_num);
            if ($rid <= 0) $rid = $order_id;

            $rc_row = $tododb->get_row($tododb->prepare("SELECT * FROM {$tododb->prefix}recharge_records WHERE id = %d LIMIT 1", $rid), [], 'ARRAY_A');
            if (is_object($rc_row)) $rc_row = (array) $rc_row;
            if (!$rc_row) {
                throw new Exception('充值订单不存在');
            }

            if (($rc_row['status'] ?? '') !== 'completed') {
                $access_token = todo_paypal_get_access_token((string) $cfg['client_id'], (string) $cfg['client_secret']);
                $capture = todo_paypal_capture_order($access_token, $paypal_order_id);
                $cap_id = todo_paypal_extract_capture_id($capture);

                $user_id = intval($rc_row['user_id']);
                $amount = floatval($rc_row['amount']);
                todo_paypal_assert_amount_matches($capture, $amount, $cfg['currency'] ?? '');

                // 1. 更新充值记录状态为已完成
                $tododb->update(
                    $tododb->prefix . 'recharge_records',
                    [
                        'status' => 'completed',
                        'completed_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql')
                    ],
                    ['id' => $rid]
                );

                // 2. 增加用户账户余额
                if (function_exists('todo_core_add_user_balance')) {
                    todo_core_add_user_balance($user_id, $amount, 'recharge', '在线充值PayPal: ' . $order_no, $rid);
                } else {
                    $old_balance = floatval(get_user_meta($user_id, 'todo_balance', true) ?: 0);
                    $new_balance = $old_balance + $amount;
                    update_user_meta($user_id, 'todo_balance', $new_balance);
                    if (function_exists('todo_add_balance_log')) {
                        todo_add_balance_log($user_id, $amount, 'recharge', '在线充值PayPal: ' . $order_no);
                    }
                }

                // 3. 通知管理员充值成功
                if (function_exists('todo_notify_admin_order_payment')) {
                    $recharge_order_data = [
                        'id' => $rid,
                        'order_no' => $order_no,
                        'user_id' => $user_id,
                        'total_amount' => $amount,
                        'payment_amount' => $amount,
                        'payment_method' => 'paypal',
                        'paid_at' => current_time('mysql'),
                        'is_recharge' => true
                    ];
                    todo_notify_admin_order_payment($recharge_order_data);
                }
            }

            // 输出脚本，给前端轮询自动捕获或自关闭窗口
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Successful</title></head><body>';
            echo '<h1 style="width: 200px;height: 200px;margin: 10% auto 0;line-height: 200px;font-size: 200px;color: #0050c5;text-align: center;border-radius: 100%;"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 1200 1200"><path d="M0 0h1200v1200H0z" fill="none"></path><path fill="currentColor" d="M600 0C268.63 0 0 268.63 0 600s268.63 600 600 600s600-268.63 600-600S931.369 0 600 0m0 130.371c259.369 0 469.556 210.325 469.556 469.629S859.369 1069.556 600 1069.556c-259.37 0-469.556-210.251-469.556-469.556C130.445 340.696 340.63 130.371 600 130.371m229.907 184.717L482.153 662.915L369.36 550.122L258.691 660.718l112.793 112.793l111.401 111.401l110.597-110.669l347.826-347.754z"></path></svg></h1>';
            echo '<script>';
            echo 'setTimeout(function(){ if(window.opener) { window.close(); } else { window.location.href="' . esc_js(todo_home_url('/?user=wallet')) . '"; } }, 1500);';
            echo '</script>';
            echo '</body></html>';
            exit;
        }

        // 处理普通商品订单 PayPal 回调
        $order = Todo_Order_Manager::get_order_by_no($order_no);
        if (!$order || (int) $order['id'] !== $order_id) {
            throw new Exception('订单不存在');
        }
        if (isset($order['payment_status']) && (string) $order['payment_status'] === 'paid') {
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Successful</title></head><body>';
            echo '<h1 style="width: 200px;height: 200px;margin: 10% auto 0;line-height: 200px;font-size: 200px;color: #0050c5;text-align: center;border-radius: 100%;"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 1200 1200"><path d="M0 0h1200v1200H0z" fill="none"></path><path fill="currentColor" d="M600 0C268.63 0 0 268.63 0 600s268.63 600 600 600s600-268.63 600-600S931.369 0 600 0m0 130.371c259.369 0 469.556 210.325 469.556 469.629S859.369 1069.556 600 1069.556c-259.37 0-469.556-210.251-469.556-469.556C130.445 340.696 340.63 130.371 600 130.371m229.907 184.717L482.153 662.915L369.36 550.122L258.691 660.718l112.793 112.793l111.401 111.401l110.597-110.669l347.826-347.754z"></path></svg></h1>';
            echo '<script>';
            echo 'setTimeout(function(){ if(window.opener) { window.close(); } else { window.location.href="' . esc_js(todo_home_url('/user/order-success?order_id=' . $order_id)) . '"; } }, 1500);';
            echo '</script>';
            echo '</body></html>';
            exit;
        }

        $payment_info = function_exists('todo_get_post_meta') ? todo_get_post_meta($order_id, '_payment_info', true) : [];
        $used_client_id = is_array($payment_info) && isset($payment_info['paypal_client_id']) ? $payment_info['paypal_client_id'] : '';
        $cfg = todo_get_paypal_config($used_client_id);

        $access_token = todo_paypal_get_access_token((string) $cfg['client_id'], (string) $cfg['client_secret']);
        $capture = todo_paypal_capture_order($access_token, $paypal_order_id);
        $cap_id = todo_paypal_extract_capture_id($capture);
        todo_paypal_assert_amount_matches($capture, floatval($order['total_amount'] ?? 0), $cfg['currency'] ?? '');

        // 补全 PayPal 妥投收货地址：PayPal POST /v2/checkout/orders/{id}/capture 接口返回的数据中不直接包含 shipping 字段
        // 需通过 GET /v2/checkout/orders/{id} 获取完整订单详情并合并 shipping 属性，以便防拒付风控模块精确核对真正的妥投收货地址
        try {
            $paypal_order_details = todo_paypal_get_order($access_token, $paypal_order_id);
            if (!empty($paypal_order_details['purchase_units']) && is_array($paypal_order_details['purchase_units'])) {
                foreach ($paypal_order_details['purchase_units'] as $idx => $pu) {
                    if (!empty($pu['shipping']) && is_array($pu['shipping'])) {
                        if (empty($capture['purchase_units'][$idx]['shipping'])) {
                            $capture['purchase_units'][$idx]['shipping'] = $pu['shipping'];
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            if (class_exists('Todo_Error_Handler')) {
                Todo_Error_Handler::log("PAYPAL_RETURN [Warning] Fetching order shipping details failed: " . $e->getMessage(), Todo_Error_Handler::LEVEL_WARNING);
            }
        }

        if (function_exists('todo_save_order_anti_fraud_data')) {
            todo_save_order_anti_fraud_data($order_id, null, null, $capture, $paypal_order_id);
        }

        Todo_Order_Manager::update_payment_status($order_id, 'paid', array(
            'payment_method' => 'paypal',
            'transaction_id' => $cap_id,
            'paid_at' => current_time('mysql'),
            'notify_data' => json_encode($capture),
        ));
        do_action('todo_payment_completed', $order_id, 'paypal');

        // A买B付：如果是B站的影子订单，付款完成后主动通知A站
        if (isset($order['ab_order_type']) && $order['ab_order_type'] === 'site_b') {
            if (!class_exists('Todo_AB_Payment_API') && file_exists(ABSPATH . 'core/classes/class-ab-payment-api.php')) {
                require_once ABSPATH . 'core/classes/class-ab-payment-api.php';
            }
            if (class_exists('Todo_AB_Payment_API')) {
                $ab_api = new Todo_AB_Payment_API();
                $ab_api->notify_a_site_success($order['order_no'], $cap_id);
            }
        }

        // A买B付：如果是B站的影子订单，付款完成后通知A站并自动关闭当前新标签页
        if (isset($order['ab_order_type']) && $order['ab_order_type'] === 'site_b') {
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Successful</title></head><body>';
            echo '<h1 style="width: 200px;height: 200px;margin: 10% auto 0;line-height: 200px;font-size: 200px;color: #0050c5;text-align: center;border-radius: 100%;"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 1200 1200"><path d="M0 0h1200v1200H0z" fill="none"></path><path fill="currentColor" d="M600 0C268.63 0 0 268.63 0 600s268.63 600 600 600s600-268.63 600-600S931.369 0 600 0m0 130.371c259.369 0 469.556 210.325 469.556 469.629S859.369 1069.556 600 1069.556c-259.37 0-469.556-210.251-469.556-469.556C130.445 340.696 340.63 130.371 600 130.371m229.907 184.717L482.153 662.915L369.36 550.122L258.691 660.718l112.793 112.793l111.401 111.401l110.597-110.669l347.826-347.754z"></path></svg></h1>';
            echo '<script>';
            echo 'setTimeout(function(){ window.close(); }, 1200);';
            echo '</script>';
            echo '</body></html>';
            exit;
        }

        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Successful</title></head><body>';
        echo '<h1 style="width: 200px;height: 200px;margin: 10% auto 0;line-height: 200px;font-size: 200px;color: #0050c5;text-align: center;border-radius: 100%;"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 1200 1200"><path d="M0 0h1200v1200H0z" fill="none"></path><path fill="currentColor" d="M600 0C268.63 0 0 268.63 0 600s268.63 600 600 600s600-268.63 600-600S931.369 0 600 0m0 130.371c259.369 0 469.556 210.325 469.556 469.629S859.369 1069.556 600 1069.556c-259.37 0-469.556-210.251-469.556-469.556C130.445 340.696 340.63 130.371 600 130.371m229.907 184.717L482.153 662.915L369.36 550.122L258.691 660.718l112.793 112.793l111.401 111.401l110.597-110.669l347.826-347.754z"></path></svg></h1>';
        echo '<script>';
        echo 'setTimeout(function(){ if(window.opener) { window.close(); } else { window.location.href="' . esc_js(todo_home_url('/user/order-success?order_id=' . $order_id)) . '"; } }, 1500);';
        echo '</script>';
        echo '</body></html>';
        exit;
    } catch (Exception $e) {
        $order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
        $is_recharge = isset($_GET['is_recharge']) && $_GET['is_recharge'] === '1';
        $fallback_url = $is_recharge ? todo_home_url('/?user=wallet') : ($order_id > 0 ? todo_home_url('/user/order-success?order_id=' . $order_id) : todo_home_url('/'));
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Processing</title></head><body>';
        echo '<script>';
        echo 'if(window.opener) { window.close(); } else { window.location.href="' . esc_js($fallback_url) . '"; }';
        echo '</script>';
        echo '</body></html>';
    }
    exit;
}

function todo_handle_paypal_webhook() {
    try {
        $cfg = todo_get_paypal_config();
        if (empty($cfg['enabled']) || empty($cfg['accounts'])) {
            throw new Exception('PayPal未启用或未配置账户');
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || trim((string) $raw) === '') {
            throw new Exception('参数不完整');
        }

        $event = json_decode((string) $raw, true);
        if (!is_array($event)) {
            throw new Exception('参数不完整');
        }

        $headers = array(
            'transmission_id' => isset($_SERVER['HTTP_PAYPAL_TRANSMISSION_ID']) ? (string) $_SERVER['HTTP_PAYPAL_TRANSMISSION_ID'] : '',
            'transmission_time' => isset($_SERVER['HTTP_PAYPAL_TRANSMISSION_TIME']) ? (string) $_SERVER['HTTP_PAYPAL_TRANSMISSION_TIME'] : '',
            'cert_url' => isset($_SERVER['HTTP_PAYPAL_CERT_URL']) ? (string) $_SERVER['HTTP_PAYPAL_CERT_URL'] : '',
            'auth_algo' => isset($_SERVER['HTTP_PAYPAL_AUTH_ALGO']) ? (string) $_SERVER['HTTP_PAYPAL_AUTH_ALGO'] : '',
            'transmission_sig' => isset($_SERVER['HTTP_PAYPAL_TRANSMISSION_SIG']) ? (string) $_SERVER['HTTP_PAYPAL_TRANSMISSION_SIG'] : '',
        );

        $verified = false;
        $access_token = '';
        foreach ($cfg['accounts'] as $acc) {
            if (empty($acc['webhook_id'])) continue;
            try {
                $token = todo_paypal_get_access_token((string) $acc['client_id'], (string) $acc['client_secret']);
                if (todo_paypal_verify_webhook_signature($token, (string) $acc['webhook_id'], $raw, $headers)) {
                    $verified = true;
                    $access_token = $token;
                    break;
                }
            } catch (Exception $e) {
                continue;
            }
        }
        
        if (!$verified) {
            throw new Exception('所有账号签名验证失败');
        }

        $event_type = isset($event['event_type']) ? (string) $event['event_type'] : '';
        $resource = isset($event['resource']) && is_array($event['resource']) ? $event['resource'] : array();

        $paypal_order_id = '';
        if ($event_type === 'CHECKOUT.ORDER.APPROVED' && isset($resource['id'])) {
            $paypal_order_id = (string) $resource['id'];
        } elseif (!empty($resource['supplementary_data']) && is_array($resource['supplementary_data'])
            && !empty($resource['supplementary_data']['related_ids']) && is_array($resource['supplementary_data']['related_ids'])
            && !empty($resource['supplementary_data']['related_ids']['order_id'])) {
            $paypal_order_id = (string) $resource['supplementary_data']['related_ids']['order_id'];
        }

        if ($paypal_order_id === '') {
            echo 'success';
            exit;
        }

        $paypal_order = todo_paypal_get_order($access_token, $paypal_order_id);
        $invoice_id = todo_paypal_extract_invoice_id($paypal_order);
        if ($invoice_id === '') {
            echo 'success';
            exit;
        }

        $order = Todo_Order_Manager::get_order_by_no($invoice_id);
        if (!$order) {
            echo 'success';
            exit;
        }
        if (isset($order['payment_status']) && (string) $order['payment_status'] === 'paid') {
            echo 'success';
            exit;
        }

        $cap_id = '';
        $capture_raw = null;
        if ($event_type === 'CHECKOUT.ORDER.APPROVED') {
            $capture_raw = todo_paypal_capture_order($access_token, $paypal_order_id);
            $cap_id = todo_paypal_extract_capture_id($capture_raw);
        } elseif ($event_type === 'PAYMENT.CAPTURE.COMPLETED' && isset($resource['id'])) {
            $cap_id = (string) $resource['id'];
        }

        $amount_source = $capture_raw ? $capture_raw : (!empty($resource) ? $resource : $paypal_order);
        todo_paypal_assert_amount_matches($amount_source, floatval($order['total_amount'] ?? 0), $cfg['currency'] ?? '');

        if (function_exists('todo_save_order_anti_fraud_data')) {
            todo_save_order_anti_fraud_data($order['id'], null, null, $amount_source, $paypal_order_id);
        }

        Todo_Order_Manager::update_payment_status($order['id'], 'paid', array(
            'payment_method' => 'paypal',
            'transaction_id' => $cap_id,
            'paid_at' => current_time('mysql'),
            'notify_data' => json_encode($capture_raw ? $capture_raw : $event),
        ));
        do_action('todo_payment_completed', $order['id'], 'paypal');

        // A买B付：如果是B站的影子订单，付款完成后通知A站
        if (isset($order['ab_order_type']) && $order['ab_order_type'] === 'site_b') {
            if (!class_exists('Todo_AB_Payment_API') && file_exists(ABSPATH . 'core/classes/class-ab-payment-api.php')) {
                require_once ABSPATH . 'core/classes/class-ab-payment-api.php';
            }
            if (class_exists('Todo_AB_Payment_API')) {
                $ab_api = new Todo_AB_Payment_API();
                $ab_api->notify_a_site_success($order['order_no'], $cap_id);
            }
        }

        echo 'success';
    } catch (Exception $e) {
        http_response_code(400);
        echo 'fail';
    }
    exit;
}

/**
 * 云闪付 (UnionPay) 异步通知回调
 */
function todo_handle_unionpay_notify() {
    $opts = function_exists('todo_get_option') ? todo_get_option('todo_payment_settings', array()) : array();
    $up = isset($opts['unionpay']) && is_array($opts['unionpay']) ? $opts['unionpay'] : array();
    $pub_key = trim((string) ($up['public_key'] ?? ''));

    $data = $_POST;
    if (empty($data)) {
        http_response_code(400);
        echo 'fail';
        exit;
    }

    $resp_code = $data['respCode'] ?? '';
    $signature = $data['signature'] ?? '';
    $order_no = $data['orderId'] ?? '';
    $query_id = $data['queryId'] ?? ($data['traceNo'] ?? '');

    if ($resp_code !== '00') {
        echo 'fail';
        exit;
    }

    // 验证签名
    if ($pub_key !== '' && $signature !== '') {
        ksort($data);
        $sign_str = '';
        foreach ($data as $k => $v) {
            if ($k !== 'signature' && $k !== 'signMethod' && $v !== '' && $v !== null) {
                $sign_str .= $k . '=' . $v . '&';
            }
        }
        $sign_str = rtrim($sign_str, '&');

        if (strpos($pub_key, 'BEGIN') === false) {
            $pub_key = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(str_replace(array("\r", "\n", ' ', "\t"), '', $pub_key), 64, "\n") . "-----END PUBLIC KEY-----\n";
        }
        $pk = openssl_pkey_get_public($pub_key);
        if ($pk) {
            $verify_res = openssl_verify(sha1($sign_str, true), base64_decode($signature), $pk, OPENSSL_ALGO_SHA256);
            if ($verify_res !== 1) {
                // RSA SHA1 备用验证
                $verify_res = openssl_verify($sign_str, base64_decode($signature), $pk, OPENSSL_ALGO_SHA1);
            }
            openssl_free_key($pk);
            if ($verify_res !== 1) {
                http_response_code(400);
                echo 'fail';
                exit;
            }
        }
    }

    // 判断是否为充值订单
    if (strpos($order_no, 'RC') === 0) {
        if (!function_exists('todo_handle_recharge_payment_success')) {
            $inc = defined('ABSPATH') ? ABSPATH . 'includes/ajax/recharge-handler.php' : '';
            if ($inc && file_exists($inc)) {
                require_once $inc;
            }
        }
        if (function_exists('todo_handle_recharge_payment_success')) {
            todo_handle_recharge_payment_success($order_no, $query_id, 'UnionPay云闪付: ' . $order_no);
        }
        echo 'success';
        exit;
    }

    $order = Todo_Order_Manager::get_order_by_no($order_no);
    if (!$order) {
        http_response_code(404);
        echo 'fail';
        exit;
    }

    // 校验云闪付支付金额
    $txn_amt = isset($data['txnAmt']) ? intval($data['txnAmt']) : 0;
    $expected_cny = function_exists('todo_convert_base_to_cny') ? todo_convert_base_to_cny($order['total_amount']) : floatval($order['total_amount']);
    $expected_amt = intval(round($expected_cny * 100));

    if ($txn_amt > 0 && $expected_amt !== $txn_amt) {
        http_response_code(400);
        echo 'fail';
        exit;
    }

    if ($order['payment_status'] === 'paid') {
        echo 'success';
        exit;
    }

    Todo_Order_Manager::update_payment_status($order['id'], 'paid', array(
        'payment_method' => 'unionpay',
        'transaction_id' => $query_id,
        'paid_at' => current_time('mysql'),
        'notify_data' => json_encode($data),
    ));
    Todo_Order_Manager::update_order_status($order['id'], 'processing');
    do_action('todo_payment_completed', $order['id'], 'unionpay');

    echo 'success';
    exit;
}

// 根据URL路径处理不同的支付通知
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$api_path = isset($_GET['path']) ? ltrim($_GET['path'], '/') : '';

if (strpos($request_uri, '/api/payment/payjs/notify') !== false || strpos($api_path, 'payment/payjs/notify') === 0) {
    todo_handle_payjs_notify();
} elseif (strpos($request_uri, '/api/payment/alipay/notify') !== false || strpos($api_path, 'payment/alipay/notify') === 0) {
    todo_handle_alipay_notify();
} elseif (strpos($request_uri, '/api/payment/wechat/notify') !== false || strpos($api_path, 'payment/wechat/notify') === 0) {
    todo_handle_wechat_notify();
} elseif (strpos($request_uri, '/api/payment/hupijiao/notify') !== false || strpos($api_path, 'payment/hupijiao/notify') === 0) {
    todo_handle_hupijiao_notify();
} elseif (strpos($request_uri, '/api/payment/unionpay/notify') !== false || strpos($api_path, 'payment/unionpay/notify') === 0) {
    todo_handle_unionpay_notify();
} elseif (strpos($request_uri, '/api/payment/paypal/webhook') !== false || strpos($api_path, 'payment/paypal/webhook') === 0) {
    todo_handle_paypal_webhook();
} elseif (strpos($request_uri, '/api/payment/paypal/return') !== false || strpos($api_path, 'payment/paypal/return') === 0) {
    todo_handle_paypal_return();
} else {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

