<?php
/**
 * TodoCMS 支付处理AJAX接口
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 定义AJAX环境
if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}

// 加载核心文件
if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    if (!defined('ABSPATH')) {
        define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
    }

    require_once ABSPATH . 'core/bootstrap.php';
    require_once ABSPATH . 'core/functions/function-loader.php';
    if (class_exists('Todo_Function_Loader')) {
        Todo_Function_Loader::load_core_functions();
        Todo_Function_Loader::load_function_group('shop');
    }
}

// 检查请求头（仅当作为入口页面单独请求时进行校验）
if (!defined('AB_INTERNAL_INCLUDE')) {
    if (function_exists('todo_is_ajax_request') && !todo_is_ajax_request()) {
        http_response_code(403);
        echo json_encode(['error' => 'Direct access denied']);
        exit;
    }
}

// PRG 防重复提交守卫（仅对携带 _submit_key 的 POST 生效）
if (!defined('PREVENT_AJAX_AUTO_RUN')) {
    Todo_PRG_Guard::guard_endpoint('payment_process', 30);
}

Todo_Order_Manager::init();

// 定义 HTTP 请求兼容 Helper 函数
if (!function_exists('todo_remote_post')) {
    function todo_remote_post($url, $args = array()) {
        $body = isset($args['body']) ? $args['body'] : array();
        $timeout = isset($args['timeout']) ? intval($args['timeout']) : 30;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        if (is_array($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($res === false || !empty($err)) {
            return new Exception($err ? $err : 'HTTP Request Failed');
        }
        return array('body' => $res);
    }
}

if (!function_exists('todo_is_error')) {
    function todo_is_error($thing) {
        return ($thing instanceof Exception || $thing instanceof Throwable);
    }
}

if (!function_exists('todo_remote_retrieve_body')) {
    function todo_remote_retrieve_body($response) {
        if (is_array($response) && isset($response['body'])) {
            return $response['body'];
        }
        return is_string($response) ? $response : '';
    }
}

function todo_payment_user_can_access_order($order, $current_uid = null) {
    if (empty($order) || !is_array($order)) {
        return false;
    }

    if ($current_uid === null) {
        $current_uid = function_exists('todo_get_current_user_id') ? intval(todo_get_current_user_id()) : 0;
    }

    $order_id = intval($order['id'] ?? 0);
    $order_user_id = intval($order['user_id'] ?? 0);

    if (($order['ab_order_type'] ?? '') === 'site_b') {
        return true;
    }

    if ($current_uid > 0) {
        return $order_user_id > 0 && $order_user_id === intval($current_uid);
    }

    if ($order_id <= 0 || !function_exists('todo_get_guest_order_ids')) {
        return false;
    }

    $guest_order_ids = array_map('intval', (array) todo_get_guest_order_ids());
    return in_array($order_id, $guest_order_ids, true);
}

function todo_payment_user_can_access_recharge($recharge_row, $current_uid = null) {
    if (empty($recharge_row) || !is_array($recharge_row)) {
        return false;
    }

    if ($current_uid === null) {
        $current_uid = function_exists('todo_get_current_user_id') ? intval(todo_get_current_user_id()) : 0;
    }

    if ($current_uid <= 0) {
        return false;
    }

    return intval($recharge_row['user_id'] ?? 0) === intval($current_uid);
}

/**
 * 处理支付请求
 */
function todo_ajax_process_payment() {
    try {
        // 验证CSRF令牌
        $security = Todo_Security_Manager::getInstance();
        if (!$security->verify_request_csrf('process_payment', todo_post_string('_todononce'))) {
            throw new Exception(__('安全验证失败'));
        }
        
        $order_id = todo_post_int('order_id');
        $recharge_id = todo_post_int('recharge_id');
        $payment_method = sanitize_text_field(todo_post_string('payment_method'));
        
        if (!$order_id && !$recharge_id) {
            throw new Exception(__('订单ID无效'));
        }
        
        if (!$payment_method) {
            throw new Exception(__('请选择支付方式'));
        }
        
        // 如果是充值订单处理
        if ($recharge_id > 0 && !$order_id) {
            global $tododb;
            $rc_row = $tododb->get_row($tododb->prepare("SELECT * FROM {$tododb->prefix}recharge_records WHERE id = %d LIMIT 1", $recharge_id), [], 'ARRAY_A');
            if (is_object($rc_row)) {
                $rc_row = (array) $rc_row;
            }
            if (!$rc_row) {
                throw new Exception(__('订单不存在'));
            }
            if (($rc_row['status'] ?? '') === 'completed' || ($rc_row['status'] ?? '') === 'paid') {
                throw new Exception(__('订单已完成'));
            }
            if (!todo_payment_user_can_access_recharge($rc_row)) {
                throw new Exception(__('无权限操作此订单'));
            }
            $created_ts = !empty($rc_row['created_at']) ? strtotime($rc_row['created_at']) : time();
            $formatted_rc_no = 'RC' . date('YmdHis', $created_ts) . str_pad($rc_row['id'], 6, '0', STR_PAD_LEFT);
            $order = [
                'id' => $rc_row['id'],
                'order_no' => $formatted_rc_no,
                'total_amount' => $rc_row['amount'],
                'user_id' => $rc_row['user_id'],
                'payment_status' => $rc_row['status'],
                'order_status' => $rc_row['status'],
                'is_recharge' => true
            ];
        } else {
            // 获取商品订单信息
            $order = Todo_Order_Manager::get_order($order_id, true);
            if (class_exists('Todo_Error_Handler')) {
                Todo_Error_Handler::log(
                    '[Payment Debug] Order Retrieved for Payment',
                    Todo_Error_Handler::LEVEL_INFO,
                    [
                        'order_id' => $order_id,
                        'order_no' => $order['order_no'] ?? '',
                        'order_total_amount' => $order['total_amount'] ?? 0,
                        'shipping_fee' => $order['shipping_fee'] ?? 0,
                        'discount_amount' => $order['discount_amount'] ?? 0,
                        'current_user_id' => function_exists('todo_get_current_user_id') ? todo_get_current_user_id() : 0,
                        'order_items' => $order['items'] ?? []
                    ]
                );
            }
            if (!$order) {
                throw new Exception(__('订单不存在'));
            }
            if (!todo_payment_user_can_access_order($order)) {
                throw new Exception(__('无权限操作此订单'));
            }
        }
        
        // 验证订单状态
        $payment_status = $order['payment_status'] ?? ($order['status'] ?? '');
        $order_status = $order['order_status'] ?? ($order['status'] ?? '');

        if ($payment_status === 'paid') {
            throw new Exception(__('订单已支付'));
        }
        
        if ($order_status === 'cancelled') {
            throw new Exception(__('订单已取消'));
        }
        
        $current_uid = todo_get_current_user_id();

        if (!empty($order['id']) && function_exists('todo_save_order_anti_fraud_data')) {
            $client_ip = todo_get_client_ip();
            $device_info_raw = todo_post_string('device_info', todo_request_string('device_info', null));
            todo_save_order_anti_fraud_data($order['id'], $client_ip, $device_info_raw);
        }
        
        // 订单金额 <= 0.00（积分/优惠券全额抵扣、会员等级0%免费折扣、或免费商品）：进行严格服务端安全校验后，直接完成订单支付
        if (floatval($order['total_amount'] ?? 0) <= 0.00) {
            if (empty($order['is_recharge'])) {
                $pts_used = intval(Todo_Order_Manager::get_order_custom_field($order['id'], '_points_used') ?: 0);
                $pts_deducted = Todo_Order_Manager::get_order_custom_field($order['id'], '_points_deducted');
                $coupon_id = intval($order['coupon_id'] ?? 0);

                // 校验合法零元原因：积分抵扣、优惠券抵扣、或者会员享受合法0%免费折扣/本身为0元免费商品
                $has_valid_free_reason = ($pts_used > 0 || $coupon_id > 0);

                if (!$has_valid_free_reason) {
                    // 检查订单所属用户的会员等级折扣率是否为 0% 免费
                    $order_uid = intval($order['user_id'] ?? $current_uid);
                    if ($order_uid > 0 && function_exists('todo_get_user_level_manager')) {
                        $user_lvl = todo_get_user_level_manager()->get_user_current_level($order_uid);
                        if ($user_lvl && isset($user_lvl->discount_rate) && floatval($user_lvl->discount_rate) === 0.0) {
                            $has_valid_free_reason = true;
                        }
                    }
                }

                // 检查是否所有订单商品单价均合法为 0
                if (!$has_valid_free_reason) {
                    $order_items = Todo_Order_Manager::get_order_items($order['id']);
                    if (!empty($order_items)) {
                        $all_items_free = true;
                        foreach ($order_items as $oi) {
                            $oi_pid = intval($oi['product_id'] ?? 0);
                            $oi_specs = isset($oi['product_specs_data']) && is_array($oi['product_specs_data']) ? $oi['product_specs_data'] : [];
                            $chk_price = function_exists('todo_get_product_member_price') 
                                ? todo_get_product_member_price($oi_pid, floatval($oi['price'] ?? 0), intval($order['user_id'] ?? $current_uid), $oi_specs) 
                                : floatval($oi['price'] ?? 0);
                            if ($chk_price > 0.001) {
                                $all_items_free = false;
                                break;
                            }
                        }
                        if ($all_items_free) {
                            $has_valid_free_reason = true;
                        }
                    }
                }

                // 防越权漏洞校验：必须有合法的0元原因，禁止无原由零元支付
                if (!$has_valid_free_reason) {
                    throw new Exception(__('订单异常，拒绝支付'));
                }

                // 严格校验用户当前实际积分余额
                if ($pts_used > 0 && empty($pts_deducted)) {
                    $user_pts = 0;
                    if (class_exists('Points_Manager')) {
                        $user_pts = Points_Manager::get_instance()->get_balance($current_uid);
                    } else {
                        $user_pts = (float) get_user_meta($current_uid, '_user_points', true) ?: 0;
                    }
                    if ($user_pts < $pts_used) {
                        throw new Exception(__('积分不足，无法完成抵扣'));
                    }
                }

                $pay_note = $pts_used > 0 || $coupon_id > 0 ? __('积分/优惠抵扣，自动完成支付') : __('会员免费/零元商品，自动完成支付');
                Todo_Order_Manager::update_payment_status($order['id'], 'paid', array('paid_amount' => 0, 'paid_at' => current_time('mysql')), $current_uid, $pay_note);
                $redirect_target = function_exists('todo_get_user_page_url') 
                    ? todo_get_user_page_url('order-detail') . '&id=' . intval($order['id']) 
                    : (function_exists('todo_home_url') ? todo_home_url('/?user=order-detail&id=' . intval($order['id'])) : ('/?user=order-detail&id=' . intval($order['id'])));

                todo_send_json(array(
                    'success' => true,
                    'message' => $pay_note,
                    'order_id' => $order['id'],
                    'order_no' => $order['order_no'],
                    'redirect_url' => $redirect_target
                ));
                return;
            }
        }
        
        $normalized_method = $payment_method;
        if ($normalized_method === 'payjs') {
            $normalized_method = 'wechat';
        }
        if ($normalized_method === 'payjs_wechat') {
            $normalized_method = 'wechat';
        }
        if ($normalized_method === 'payjs_alipay') {
            $normalized_method = 'alipay';
        }
        
        $ab_secret = function_exists('todo_get_option') ? todo_get_option('ab_payment_shared_secret', '') : '';
        $ab_is_b = function_exists('todo_get_option') ? todo_get_option('ab_payment_is_b_site', false) : false;
        
        if ($ab_secret && $ab_secret !== 'YOUR_DEFAULT_SECRET_KEY_REPLACE_ME' && !$ab_is_b && in_array($normalized_method, ['wechat', 'alipay', 'paypal'], true) && floatval($order['total_amount'] ?? 0) > 0) {
            if (!class_exists('Todo_AB_Payment_API') && file_exists(ABSPATH . 'core/classes/class-ab-payment-api.php')) {
                require_once ABSPATH . 'core/classes/class-ab-payment-api.php';
            }
            
            // 如果配置了商品白名单且当前订单中的商品均不在白名单中，则跳过 AB 支付，降级走 A 站单站本地支付
            $is_ab_allowed = class_exists('Todo_AB_Payment_API') ? Todo_AB_Payment_API::is_order_in_whitelist($order) : true;
            
            if ($is_ab_allowed && class_exists('Todo_AB_Payment_API')) {
                if (class_exists('Todo_Error_Handler')) {
                    Todo_Error_Handler::log("AB_PAYMENT [A-Site] Intercepting order {$order['order_no']} in payment-process.php for AB jump payment ({$normalized_method}, amount: {$order['total_amount']})", Todo_Error_Handler::LEVEL_INFO);
                }
                
                $ab_api = new Todo_AB_Payment_API();
                $b_site_urls = todo_get_option('ab_payment_b_site_urls', '');
                if (!empty($b_site_urls)) {
                    $b_site_array = array_filter(array_map('trim', explode(',', $b_site_urls)));
                    if (!empty($b_site_array)) {
                        $selected_b_site = $b_site_array[array_rand($b_site_array)];
                        
                        $b_req = $ab_api->build_payment_request_for_b_site($order, $selected_b_site);
                        
                        global $tododb;
                        $orders_table = $tododb->prefix . 'orders';
                        $tododb->update($orders_table, [
                            'ab_order_type' => 'site_a',
                            'ab_b_site_url' => $selected_b_site
                        ], ['id' => $order['id']]);
                        
                        if (class_exists('Todo_Error_Handler')) {
                            Todo_Error_Handler::log("AB_PAYMENT [A-Site] Formed jump payload for order {$order['order_no']} -> B-Site: {$selected_b_site}", Todo_Error_Handler::LEVEL_INFO, ['payload' => $b_req]);
                        }
                        
                        // A 站后端通过 cURL 静默请求 B 站，直接拿回 PayPal 官方的 approve_url，避免暴露 B 站中间页
                        $b_req['format'] = 'json';
                        $b_api_endpoint = $b_req['b_site_endpoint'];
                        
                        $ch = curl_init($b_api_endpoint);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($b_req));
                        curl_setopt($ch, CURLOPT_TIMEOUT, 45);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        $b_resp_raw = curl_exec($ch);
                        $b_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);
                        
                        $b_resp_json = json_decode((string) $b_resp_raw, true);
                        $paypal_official_url = is_array($b_resp_json) && !empty($b_resp_json['pay_url']) ? $b_resp_json['pay_url'] : '';
                        $b_order_no = is_array($b_resp_json) && !empty($b_resp_json['b_order_no']) ? sanitize_text_field($b_resp_json['b_order_no']) : '';
                        
                        if ($b_http_code == 200 && $paypal_official_url !== '') {
                            if ($b_order_no !== '') {
                                $tododb->update($orders_table, ['ab_ref_order_no' => $b_order_no], ['id' => $order['id']]);
                            }
                            if (class_exists('Todo_Error_Handler')) {
                                Todo_Error_Handler::log("AB_PAYMENT [A-Site] Successfully fetched PayPal official URL via B-site: {$paypal_official_url}", Todo_Error_Handler::LEVEL_INFO);
                            }
                            todo_send_json(array(
                                'success' => true,
                                'message' => __('正在进行安全支付，请在弹出的窗口中完成付款'),
                                'order_no' => $order['order_no'],
                                'pay_url' => $paypal_official_url,
                                'redirect_url' => ''
                            ));
                            return;
                        } else {
                            $err_detail = is_array($b_resp_json) && !empty($b_resp_json['error']) ? $b_resp_json['error'] : __('通道响应异常');
                            if (class_exists('Todo_Error_Handler')) {
                                Todo_Error_Handler::log("AB_PAYMENT [A-Site] Failed to fetch PayPal URL from B-site (HTTP {$b_http_code}): {$b_resp_raw}", Todo_Error_Handler::LEVEL_ERROR);
                            }
                            // 坚决不进行 URL 重定向兜底（杜绝暴露 B 站地址），直接向前端抛出错误，提示支付发起失败
                            throw new Exception(sprintf(__('安全支付通道拉起失败 (%s)，请重试或更换支付方式'), $err_detail));
                        }
                    } else if (class_exists('Todo_Error_Handler')) {
                        Todo_Error_Handler::log("AB_PAYMENT [A-Site] B-Site URL array is empty after filter", Todo_Error_Handler::LEVEL_WARNING);
                    }
                } else if (class_exists('Todo_Error_Handler')) {
                    Todo_Error_Handler::log("AB_PAYMENT [A-Site] B-Site URLs option is empty", Todo_Error_Handler::LEVEL_WARNING);
                }
            }
        }

        switch ($normalized_method) {
            case 'balance':
                if (!empty($order['is_recharge'])) {
                    throw new Exception(__('账户充值订单禁止使用余额支付'));
                }
                $result = process_balance_payment($order, $current_uid);
                break;
            case 'wechat':
            case 'alipay':
                $result = process_online_qr_payment($order, $normalized_method);
                break;
            case 'unionpay':
                $result = process_unionpay_payment($order);
                break;
            case 'paypal':
                $result = process_paypal_payment($order);
                break;
            case 'cod':
                $result = process_cod_payment($order);
                break;
            case 'offline':
                $result = process_offline_payment($order);
                break;
            default:
                throw new Exception(__('不支持的支付方式'));
        }
        
        todo_send_json($result);
        
    } catch (Exception $e) {
        todo_send_json(array(
            'success' => false,
            'message' => $e->getMessage()
        ));
    }
}

function get_service_payment_provider($method) {
    $method = sanitize_text_field((string) $method);
    if (!function_exists('todo_shop_get_service_settings')) {
        if (class_exists('Todo_Function_Loader')) {
            Todo_Function_Loader::load_function('shop-service-settings');
        } else {
            $p = defined('ABSPATH') ? ABSPATH . 'core/functions/shop-service-settings.php' : '';
            if ($p && file_exists($p)) {
                require_once $p;
            }
        }
    }
    $settings = function_exists('todo_shop_get_service_settings') ? todo_shop_get_service_settings() : array();
    $providers = isset($settings['payment_providers']) && is_array($settings['payment_providers']) ? $settings['payment_providers'] : array();
    $provider = isset($providers[$method]) ? sanitize_text_field((string) $providers[$method]) : 'payjs';
    if ($method === 'wechat') {
        $allowed = array('payjs', 'hupijiao', 'wechat_direct');
        if (!in_array($provider, $allowed, true)) {
            $provider = 'payjs';
        }
    } elseif ($method === 'alipay') {
        $allowed = array('payjs', 'hupijiao', 'alipay_direct');
        if (!in_array($provider, $allowed, true)) {
            $provider = 'payjs';
        }
    } elseif ($method === 'unionpay') {
        $provider = 'unionpay_direct';
    } elseif ($method === 'paypal') {
        $provider = 'paypal_direct';
    } else {
        $provider = 'payjs';
    }
    return $provider;
}

function get_payjs_config() {
    $opts = function_exists('todo_get_option') ? todo_get_option('todo_payment_settings', array()) : array();
    $third = isset($opts['third_party']) && is_array($opts['third_party']) ? $opts['third_party'] : array();
    $payjs = isset($third['payjs']) && is_array($third['payjs']) ? $third['payjs'] : array();
    $mchid = isset($payjs['mchid']) ? (string) $payjs['mchid'] : '';
    $key = isset($payjs['key']) ? (string) $payjs['key'] : '';
    if ($mchid === '' && function_exists('todo_get_option')) {
        $mchid = (string) todo_get_option('payjs_mchid', '');
    }
    if ($key === '' && function_exists('todo_get_option')) {
        $key = (string) todo_get_option('payjs_key', '');
    }
    $enabled = !empty($third['enabled']) && !empty($payjs['enabled']) && $mchid !== '' && $key !== '';
    return array('enabled' => $enabled, 'mchid' => $mchid, 'key' => $key);
}

function payjs_build_sign($params, $key) {
    $filtered = array();
    foreach ((array) $params as $k => $v) {
        if ($k === 'sign') {
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
    $sign_str .= 'key=' . (string) $key;
    return strtoupper(md5($sign_str));
}

function process_online_qr_payment($order, $method) {
    $method = sanitize_text_field((string) $method);
    $provider = get_service_payment_provider($method);

    // 汇率换算逻辑
    $base_amount = floatval($order['total_amount'] ?? 0);
    if ($base_amount <= 0) {
        throw new Exception(__('订单金额异常，无法发起支付'));
    }
    if (function_exists('todo_convert_base_to_cny')) {
        $cny_amount = todo_convert_base_to_cny($base_amount);
    } else {
        $cny_amount = $base_amount;
    }
    if ($cny_amount <= 0) {
        throw new Exception(__('换算后的支付金额异常'));
    }

    if ($provider === 'alipay_direct') {
        if (!function_exists('api_orders_alipay_direct_request_wap')) {
            $api_func = defined('ABSPATH') ? ABSPATH . 'core/functions/api-functions.php' : '';
            if ($api_func && file_exists($api_func)) {
                require_once $api_func;
            }
            $p = defined('ABSPATH') ? ABSPATH . 'api/endpoints/orders.php' : '';
            if ($p && file_exists($p)) {
                require_once $p;
            }
        }
        if (function_exists('api_orders_alipay_direct_request_wap')) {
            $notify_url = todo_home_url('/api/payment/alipay/notify');
            $return_url = !empty($order['is_recharge']) ? todo_home_url('/?user=wallet') : todo_home_url('/?user=order-detail&id=' . $order['id']);
            $r = api_orders_alipay_direct_request_wap($order['order_no'], $cny_amount, '订单支付-' . $order['order_no'], $notify_url, $return_url);
            if (!empty($r['success']) && !empty($r['url'])) {
                Todo_Order_Manager::update_order($order['id'], array('payment_method' => $method));
                return array(
                    'success' => true,
                    'message' => __('跳转支付中'),
                    'order_no' => (string) $order['order_no'],
                    'pay_url' => $r['url']
                );
            }
            $err = !empty($r['message']) ? $r['message'] : __('直连支付宝发起失败');
            throw new Exception(__('直连支付宝发起失败') . '：' . $err);
        }
        throw new Exception(__('直连支付宝服务模块不可用'));
    }

    if ($provider === 'wechat_direct') {
        throw new Exception(__('微信直连支付需要微信公众号/小程序环境，请使用扫码/第三方支付'));
    }

    if ($provider === 'hupijiao') {
        $order_cny = $order;
        $order_cny['total_amount'] = $cny_amount;
        $r = process_hupijiao_qr_payment($order_cny, $method);
        if ($r && !empty($r['success'])) {
            Todo_Order_Manager::update_order($order['id'], array('payment_method' => $method));
            return $r;
        }
        $err = !empty($r['message']) ? $r['message'] : __('虎皮椒支付发起失败');
        throw new Exception(__('虎皮椒发起失败') . '：' . $err);
    }

    $cfg = get_payjs_config();
    if (empty($cfg['enabled'])) {
        throw new Exception(__('PayJS未启用或配置不完整'));
    }

    $order_amount = (int) round($cny_amount * 100);
    $params = array(
        'mchid' => (string) $cfg['mchid'],
        'total_fee' => $order_amount,
        'out_trade_no' => (string) $order['order_no'],
        'body' => '订单支付-' . (string) $order['order_no'],
        'notify_url' => todo_home_url('/api/payment/payjs/notify'),
        'callback_url' => todo_home_url('/user/order-detail?id=' . $order['id']),
    );
    $params['sign'] = payjs_build_sign($params, (string) $cfg['key']);
    $endpoint = ($method === 'alipay') ? 'https://payjs.cn/api/cashier' : 'https://payjs.cn/api/native';

    $response = todo_remote_post($endpoint, array(
        'body' => $params,
        'timeout' => 30
    ));

    if (todo_is_error($response)) {
        $response_error = ($response instanceof Throwable || $response instanceof Exception) ? $response->getMessage() : 'HTTP Request Failed';
        throw new Exception(__('连接支付网关失败') . '：' . $response_error);
    }

    $body = todo_remote_retrieve_body($response);
    $result = json_decode($body, true);

    if (!$result || !isset($result['return_code']) || (int) $result['return_code'] !== 1) {
        $msg = $result && isset($result['return_msg']) ? (string) $result['return_msg'] : __('支付网关返回异常');
        throw new Exception(__('支付发起失败') . '：' . $msg);
    }

    $qr = isset($result['qrcode']) ? (string) $result['qrcode'] : '';
    if ($qr === '' && isset($result['qr_code'])) {
        $qr = (string) $result['qr_code'];
    }
    $cashier = isset($result['cashier']) ? (string) $result['cashier'] : '';

    todo_update_post_meta($order['id'], '_payment_info', array(
        'method' => 'payjs',
        'provider' => 'payjs',
        'payjs_order_id' => isset($result['payjs_order_id']) ? (string) $result['payjs_order_id'] : '',
        'qr_code' => $qr,
        'cashier_url' => $cashier,
    ));

    Todo_Order_Manager::update_order($order['id'], array('payment_method' => $method));

    $out = array(
        'success' => true,
        'message' => __('请扫码支付'),
        'order_no' => (string) $order['order_no'],
    );
    if ($qr !== '') {
        $out['qr_code'] = $qr;
        return $out;
    }
    if ($cashier !== '') {
        $out['pay_url'] = $cashier;
        return $out;
    }
    throw new Exception(__('未获取到二维码'));
}

function hupijiao_build_hash($data, $app_secret) {
    $filtered = array();
    foreach ((array) $data as $k => $v) {
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

function process_hupijiao_qr_payment($order, $method) {
    $opts = function_exists('todo_get_option') ? todo_get_option('todo_payment_settings', array()) : array();
    $tp = isset($opts['third_party']) && is_array($opts['third_party']) ? $opts['third_party'] : array();
    $hp = isset($tp['hupijiao']) && is_array($tp['hupijiao']) ? $tp['hupijiao'] : array();
    if (empty($tp['enabled']) || empty($hp['enabled'])) {
        return array('success' => false, 'message' => __('虎皮椒未启用'));
    }
    $appid = isset($hp['app_id']) ? (string) $hp['app_id'] : '';
    $app_secret = isset($hp['app_secret']) ? (string) $hp['app_secret'] : '';
    $gateway = isset($hp['gateway']) ? (string) $hp['gateway'] : '';
    if ($gateway === '') {
        $gateway = 'https://api.xunhupay.com/payment/do.html';
    }
    if ($appid === '' || $app_secret === '') {
        return array('success' => false, 'message' => __('虎皮椒配置不完整'));
    }

    $time = (string) time();
    $nonce_str = bin2hex(random_bytes(8));
    $params = array(
        'version' => '1.1',
        'appid' => $appid,
        'trade_order_id' => (string) $order['order_no'],
        'total_fee' => number_format((float) $order['total_amount'], 2, '.', ''),
        'title' => '订单支付-' . (string) $order['order_no'],
        'time' => $time,
        'notify_url' => todo_home_url('/api/payment/hupijiao/notify'),
        'return_url' => todo_home_url('/user/order-detail?id=' . $order['id']),
        'nonce_str' => $nonce_str,
        'plugins' => (string) $order['id'],
        'type' => ($method === 'alipay') ? 'alipay' : 'wechat',
    );
    $params['hash'] = hupijiao_build_hash($params, $app_secret);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $gateway);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
    $resp = curl_exec($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $http_code !== 200) {
        return array('success' => false, 'message' => __('虎皮椒请求失败'));
    }
    $result = json_decode($resp, true);
    if (!is_array($result)) {
        return array('success' => false, 'message' => __('虎皮椒响应格式错误'));
    }
    $errcode = isset($result['errcode']) ? (int) $result['errcode'] : 0;
    if ($errcode !== 0) {
        $m = isset($result['errmsg']) ? (string) $result['errmsg'] : __('虎皮椒下单失败');
        return array('success' => false, 'message' => $m);
    }
    $qr = isset($result['url_qrcode']) ? (string) $result['url_qrcode'] : '';
    $url = isset($result['url']) ? (string) $result['url'] : '';
    $out = array(
        'success' => true,
        'message' => __('请扫码支付'),
        'order_no' => (string) $order['order_no'],
    );
    if ($qr !== '') {
        $out['qr_code'] = $qr;
        return $out;
    }
    if ($url !== '') {
        $out['pay_url'] = $url;
        return $out;
    }
    return array('success' => false, 'message' => __('虎皮椒未返回支付信息'));
}

function get_paypal_config() {
    $opts = function_exists('todo_get_option') ? todo_get_option('todo_payment_settings', array()) : array();
    $tp = isset($opts['third_party']) && is_array($opts['third_party']) ? $opts['third_party'] : array();
    $pp = isset($tp['paypal']) && is_array($tp['paypal']) ? $tp['paypal'] : array();

    $currency = function_exists('todo_get_default_currency_config') ? (todo_get_default_currency_config()['code'] ?? '') : '';
    $enabled = !empty($tp['enabled']) && !empty($pp['enabled']);

    $accounts = isset($pp['accounts']) && is_array($pp['accounts']) ? $pp['accounts'] : [];
    
    // Backward compatibility
    if (empty($accounts) && !empty($pp['client_id'])) {
        $accounts[] = [
            'client_id' => $pp['client_id'],
            'client_secret' => $pp['client_secret'],
            'webhook_id' => $pp['webhook_id'] ?? ''
        ];
    }
    
    if (empty($accounts)) {
        return ['enabled' => false];
    }

    // 多账户轮询算法：随机抽取一个配置
    $selected_account = $accounts[array_rand($accounts)];

    return array(
        'enabled' => $enabled && !empty($selected_account['client_id']) && !empty($selected_account['client_secret']),
        'client_id' => $selected_account['client_id'],
        'client_secret' => $selected_account['client_secret'],
        'webhook_id' => $selected_account['webhook_id'] ?? '',
        'currency' => $currency,
    );
}

function paypal_api_base() {
    $opts = function_exists('todo_get_option') ? todo_get_option('todo_payment_settings', array()) : array();
    $tp = isset($opts['third_party']) && is_array($opts['third_party']) ? $opts['third_party'] : array();
    $pp = isset($tp['paypal']) && is_array($tp['paypal']) ? $tp['paypal'] : array();
    $is_sandbox = !empty($pp['sandbox']);

    if ($is_sandbox) {
        return 'https://api-m.sandbox.paypal.com';
    }
    return 'https://api-m.paypal.com';
}

function paypal_http_request($method, $url, $headers, $body) {
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

function paypal_get_access_token($client_id, $client_secret) {
    static $cached = null;
    if (is_array($cached) && !empty($cached['token'])) {
        return (string) $cached['token'];
    }

    $auth = base64_encode(trim($client_id) . ':' . trim($client_secret));
    $headers = array(
        'Authorization' => 'Basic ' . $auth,
        'Content-Type' => 'application/x-www-form-urlencoded',
    );
    $resp = paypal_http_request('POST', paypal_api_base() . '/v1/oauth2/token', $headers, 'grant_type=client_credentials');
    if (empty($resp['ok']) || (int) $resp['status'] < 200 || (int) $resp['status'] >= 300) {
        $err_msg = !empty($resp['error']) ? $resp['error'] : ('HTTP ' . ($resp['status'] ?? 0) . ' Body: ' . ($resp['body'] ?? ''));
        $cid_info = ' (Client ID: ' . substr($client_id, 0, 8) . '... len:' . strlen($client_id) . ' sec_len:' . strlen($client_secret) . ')';
        throw new Exception(__('PayPal鉴权失败') . $cid_info . ': ' . $err_msg);
    }
    $data = json_decode((string) $resp['body'], true);
    $token = is_array($data) && isset($data['access_token']) ? (string) $data['access_token'] : '';
    if ($token === '') {
        throw new Exception(__('PayPal鉴权失败') . ': ' . __('返回未包含access_token') . ' (' . ($resp['body'] ?? '') . ')');
    }
    $cached = array('token' => $token);
    return $token;
}

function paypal_create_order($access_token, $payload) {
    $headers = array(
        'Authorization' => 'Bearer ' . $access_token,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    );
    $resp = paypal_http_request('POST', paypal_api_base() . '/v2/checkout/orders', $headers, json_encode($payload));
    if (empty($resp['ok']) || (int) $resp['status'] < 200 || (int) $resp['status'] >= 300) {
        $err_msg = !empty($resp['error']) ? $resp['error'] : ('HTTP ' . ($resp['status'] ?? 0) . ' Body: ' . ($resp['body'] ?? ''));
        throw new Exception('PayPal ' . __('下单失败') . ': ' . $err_msg);
    }
    $data = json_decode((string) $resp['body'], true);
    if (!is_array($data) || empty($data['id'])) {
        throw new Exception('PayPal ' . __('下单失败'));
    }
    $approve_url = '';
    if (!empty($data['links']) && is_array($data['links'])) {
        foreach ($data['links'] as $lnk) {
            if (!is_array($lnk)) {
                continue;
            }
            $rel = isset($lnk['rel']) ? (string) $lnk['rel'] : '';
            if ($rel === 'approve') {
                $approve_url = isset($lnk['href']) ? (string) $lnk['href'] : '';
                break;
            }
        }
    }
    if ($approve_url === '') {
        throw new Exception('PayPal ' . __('下单失败'));
    }
    return array('paypal_order_id' => (string) $data['id'], 'approve_url' => $approve_url, 'raw' => $data);
}

function process_paypal_payment($order) {
    $order_id = isset($order['id']) ? (int) $order['id'] : 0;
    $order_no = isset($order['order_no']) ? (string) $order['order_no'] : '';
    if ($order_id <= 0 || $order_no === '') {
        throw new Exception(__('订单信息不完整'));
    }

    $cfg = get_paypal_config();
    if (empty($cfg['enabled']) || empty($cfg['client_id']) || empty($cfg['client_secret'])) {
        throw new Exception(__('PayPal支付未启用或配置不完整'));
    }

    $def_currency = function_exists('todo_get_default_currency_config') ? (todo_get_default_currency_config()['code'] ?? '') : '';
    $currency = !empty($cfg['currency']) ? strtoupper($cfg['currency']) : strtoupper($def_currency);
    $base_amt = (float) ($order['total_amount'] ?? 0);
    $pay_amt = function_exists('todo_convert_base_to_currency') ? todo_convert_base_to_currency($base_amt, $currency) : $base_amt;

    if (floatval($pay_amt) <= 0.00) {
        $current_uid = todo_get_current_user_id();
        $pts_used = intval(Todo_Order_Manager::get_order_custom_field($order_id, '_points_used') ?: 0);
        $pts_deducted = Todo_Order_Manager::get_order_custom_field($order_id, '_points_deducted');
        $coupon_id = intval($order['coupon_id'] ?? 0);

        if ($pts_used <= 0 && $coupon_id <= 0) {
            throw new Exception(__('订单金额异常，拒绝零元自动支付'));
        }

        if ($pts_used > 0 && empty($pts_deducted)) {
            $user_pts = 0;
            if (class_exists('Points_Manager')) {
                $user_pts = Points_Manager::get_instance()->get_balance($current_uid);
            } else {
                $user_pts = (float) get_user_meta($current_uid, '_user_points', true) ?: 0;
            }
            if ($user_pts < $pts_used) {
                throw new Exception(__('积分余额不足，无法完成全额抵扣支付'));
            }
        }

        $redirect_target = function_exists('todo_get_user_page_url') 
            ? todo_get_user_page_url('order-detail') . '&id=' . intval($order_id) 
            : (function_exists('todo_home_url') ? todo_home_url('/?user=order-detail&id=' . intval($order_id)) : ('/?user-detail&id=' . intval($order_id)));

        Todo_Order_Manager::update_payment_status($order_id, 'paid', array('paid_amount' => 0, 'paid_at' => current_time('mysql')), $current_uid, __('积分/优惠全额抵扣，自动完成支付'));
        return array(
            'success' => true,
            'message' => __('积分/优惠已全额抵扣，订单完成支付'),
            'order_id' => $order_id,
            'order_no' => $order_no,
            'redirect_url' => $redirect_target
        );
    }
    $amount = number_format($pay_amt, 2, '.', '');

    $access_token = paypal_get_access_token((string) $cfg['client_id'], (string) $cfg['client_secret']);

    $is_recharge = !empty($order['is_recharge']);
    $return_url = todo_home_url('/api/payment/paypal/return?order_id=' . $order_id . '&order_no=' . rawurlencode($order_no) . ($is_recharge ? '&is_recharge=1' : ''));
    $cancel_url = $is_recharge ? todo_home_url('/?user=wallet') : todo_home_url('/user/order-detail?id=' . $order_id);

    $shipping_raw = $order['shipping_address'] ?? ($order['shipping_address_data'] ?? null);
    if (empty($shipping_raw) && function_exists('Todo_Order_Manager')) {
        $shipping_raw = Todo_Order_Manager::get_order_custom_field($order_id, '_checkout_shipping_address');
    }

    $shipping_addr = [];
    if (is_array($shipping_raw)) {
        $shipping_addr = $shipping_raw;
    } elseif (is_string($shipping_raw) && $shipping_raw !== '') {
        $shipping_addr = json_decode($shipping_raw, true) ?: [];
    }

    $recipient_name = trim($shipping_addr['recipient_name'] ?? ($shipping_addr['name'] ?? (($shipping_addr['first_name'] ?? '') . ' ' . ($shipping_addr['last_name'] ?? ''))));
    if (empty($recipient_name) && !empty($order['customer_name'])) {
        $recipient_name = trim($order['customer_name']);
    }

    $raw_country  = trim($shipping_addr['country_code'] ?? ($shipping_addr['country'] ?? ''));
    $country_code = function_exists('todo_normalize_country_code') ? todo_normalize_country_code($raw_country) : strtoupper($raw_country);
    $city = trim($shipping_addr['city'] ?? ($shipping_addr['admin_area_2'] ?? ''));
    $state = trim($shipping_addr['state'] ?? ($shipping_addr['province'] ?? ($shipping_addr['admin_area_1'] ?? '')));
    $address_1 = trim($shipping_addr['address'] ?? ($shipping_addr['address_line_1'] ?? ($shipping_addr['street'] ?? ($shipping_addr['detail'] ?? ($shipping_addr['address_detail'] ?? '')))));
    $address_2 = trim($shipping_addr['address_line_2'] ?? ($shipping_addr['address_2'] ?? ($shipping_addr['street_2'] ?? '')));
    $postal_code = trim($shipping_addr['postal_code'] ?? ($shipping_addr['zipcode'] ?? ($shipping_addr['zip'] ?? '')));

    $purchase_unit = array(
        'reference_id' => $order_no,
        'custom_id'    => (string) $order_id,
        'invoice_id'   => $order_no,
        'amount'       => array(
            'currency_code' => $currency,
            'value'         => $amount,
        ),
        'description'  => 'Order ' . $order_no,
    );

    $phone = trim($shipping_addr['phone'] ?? ($shipping_addr['telephone'] ?? ($shipping_addr['mobile'] ?? ($order['phone'] ?? ($order['customer_phone'] ?? '')))));
    $clean_phone = preg_replace('/[^0-9]/', '', $phone);

    if (!empty($country_code) && (!empty($address_1) || !empty($city))) {
        $addr_payload = array(
            'address_line_1' => $address_1 ?: $city,
            'country_code'   => (strlen($country_code) === 2 ? $country_code : 'US'),
        );
        if ($city !== '') $addr_payload['admin_area_2'] = $city;
        if ($state !== '') $addr_payload['admin_area_1'] = $state;
        if ($postal_code !== '') $addr_payload['postal_code'] = $postal_code;
        if ($address_2 !== '') $addr_payload['address_line_2'] = $address_2;

        $shipping_payload = array(
            'type' => 'SHIPPING',
            'name' => array('full_name' => $recipient_name ?: 'Valued Customer'),
            'address' => $addr_payload
        );
        if (!empty($clean_phone)) {
            $shipping_payload['phone_number'] = array('national_number' => $clean_phone);
        }

        $purchase_unit['shipping'] = $shipping_payload;
    }

    $shipping_pref = !empty($purchase_unit['shipping']) ? 'SET_PROVIDED_ADDRESS' : 'GET_FROM_FILE';

    $payload = array(
        'intent' => 'CAPTURE',
        'purchase_units' => array($purchase_unit),
        'application_context' => array(
            'return_url' => $return_url,
            'cancel_url' => $cancel_url,
            'shipping_preference' => $shipping_pref,
            'user_action' => 'PAY_NOW',
        ),
    );

    $customer_email = trim($order['guest_email'] ?? ($order['user_email'] ?? ($order['email'] ?? ($shipping_addr['email'] ?? ''))));
    if (!empty($customer_email) && filter_var($customer_email, FILTER_VALIDATE_EMAIL)) {
        $payload['payer'] = array(
            'email_address' => $customer_email
        );
    }

    $created = paypal_create_order($access_token, $payload);

    if (!empty($created['paypal_order_id'])) {
        if (class_exists('Todo_Order_Manager')) {
            Todo_Order_Manager::save_order_custom_field($order_id, '_paypal_sent_payload', $payload);
        }
        if (function_exists('todo_save_order_anti_fraud_data')) {
            $client_ip = function_exists('todo_get_client_ip') ? todo_get_client_ip() : '';
            $device_info_raw = todo_post_string('device_info', todo_request_string('device_info', null));
            todo_save_order_anti_fraud_data($order_id, $client_ip, $device_info_raw, null, $created['paypal_order_id']);
        }
    }

    todo_update_post_meta($order_id, '_payment_info', array(
        'method' => 'paypal',
        'provider' => 'paypal',
        'paypal_order_id' => $created['paypal_order_id'],
        'pay_url' => $created['approve_url'],
        'currency' => $currency,
        'amount' => $amount,
        'paypal_client_id' => $cfg['client_id'] // 记录使用的账号以便回调验签
    ));
    Todo_Order_Manager::update_order($order_id, array('payment_method' => 'paypal'));

    return array(
        'success' => true,
        'message' => __('请完成支付'),
        'order_no' => $order_no,
        'pay_url' => $created['approve_url'],
    );
}

/**
 * 云闪付 (UnionPay 直连 & 银联国际) 支付发起处理
 */
function process_unionpay_payment($order) {
    $opts = function_exists('todo_get_option') ? todo_get_option('todo_payment_settings', array()) : array();
    $up = isset($opts['unionpay']) && is_array($opts['unionpay']) ? $opts['unionpay'] : array();

    if (empty($up['enabled']) || empty($up['mer_id']) || empty($up['private_key'])) {
        throw new Exception(__('云闪付支付未启用或配置不完整'));
    }

    $is_sandbox = !empty($up['sandbox']);
    $gateway_url = $is_sandbox 
        ? 'https://gateway.test.95516.com/gateway/api/frontTransReq.do' 
        : 'https://gateway.pay.unionpay.com/gateway/api/frontTransReq.do';

    // 获取当前订单货币代码
    $currency_cfg = function_exists('todo_get_currency_config') ? todo_get_currency_config() : array('code' => 'CNY');
    $curr_code = strtoupper($currency_cfg['code'] ?? 'CNY');

    // 云闪付金额计算
    $amount_base = floatval($order['total_amount'] ?? 0);
    $cny_amount_val = function_exists('todo_convert_base_to_cny') ? todo_convert_base_to_cny($amount_base) : $amount_base;
    $txn_amt = (int) round($cny_amount_val * 100);

    $order_no = (string) $order['order_no'];
    $order_id = (int) ($order['id'] ?? 0);
    $is_recharge = !empty($order['is_recharge']);

    $notify_url = todo_home_url('/api/payment/unionpay/notify');
    $return_url = $is_recharge ? todo_home_url('/?user=wallet') : todo_home_url('/?user=order-detail&id=' . $order_id);

    $params = array(
        'version' => '5.1.0',
        'encoding' => 'utf-8',
        'signMethod' => '01', // RSA2
        'txnType' => '01',     // 消费
        'txnSubType' => '01',
        'bizType' => '000201', // B2C网关支付
        'channelType' => '07', // 互联网PC/WAP
        'merId' => (string) $up['mer_id'],
        'accessType' => '0',
        'orderId' => $order_no,
        'txnTime' => date('YmdHis'),
        'currencyCode' => '156', // 银联全渠道固定使用人民币数字代码 156
        'txnAmt' => (string) $txn_amt,
        'backUrl' => $notify_url,
        'frontUrl' => $return_url,
    );

    $private_key_str = trim((string) $up['private_key']);
    if (strpos($private_key_str, 'BEGIN') === false) {
        $private_key_str = "-----BEGIN RSA PRIVATE KEY-----\n" . chunk_split(str_replace(array("\r", "\n", ' ', "\t"), '', $private_key_str), 64, "\n") . "-----END RSA PRIVATE KEY-----\n";
    }

    $pkey = openssl_pkey_get_private($private_key_str);
    if (!$pkey) {
        throw new Exception(__('云闪付签名私钥无效'));
    }

    // 银联全渠道测试证书 700000000000001_acp.pfx 官方登记的 certId 为 68757041797
    $cert_id = '68757041797';
    $public_key_str = trim((string) ($up['public_key'] ?? ''));
    if (!empty($public_key_str)) {
        if (strpos($public_key_str, 'BEGIN') === false) {
            $public_key_str = "-----BEGIN CERTIFICATE-----\n" . chunk_split(str_replace(array("\r", "\n", ' ', "\t"), '', $public_key_str), 64, "\n") . "-----END CERTIFICATE-----\n";
        }
        $cert_data = @openssl_x509_parse($public_key_str);
        if ($cert_data && !empty($cert_data['serialNumber'])) {
            $raw_sn = (string) $cert_data['serialNumber'];
            $cert_id = (ctype_xdigit($raw_sn) && strlen($raw_sn) > 8) ? (string) hexdec($raw_sn) : $raw_sn;
        }
    }
    $params['certId'] = (string) $cert_id;

    // 银联 5.1.0 官方签名拼接算法
    ksort($params);
    $sign_str = '';
    foreach ($params as $k => $v) {
        if ($v !== '' && $v !== null && $k !== 'signature') {
            $sign_str .= $k . '=' . $v . '&';
        }
    }
    $sign_str = rtrim($sign_str, '&');

    // 银联 5.1.0 官方签名规范：使用 OPENSSL_ALGO_SHA1 算法对 sign_str 签名
    $ok = openssl_sign($sign_str, $signature, $pkey, OPENSSL_ALGO_SHA1);
    if (!$ok) {
        $sha1_bytes = hash('sha1', $sign_str, true);
        $ok = openssl_sign($sha1_bytes, $signature, $pkey, OPENSSL_ALGO_SHA1);
    }
    if (!$ok) {
        throw new Exception(__('云闪付签名失败'));
    }
    $params['signature'] = base64_encode($signature);

    // 【调试打点】详细打点输出至 /logs/error.log
    if (class_exists('Todo_Error_Handler')) {
        Todo_Error_Handler::log("[UnionPay Debug] 待签名串 sign_str: {$sign_str}", Todo_Error_Handler::LEVEL_INFO);
        Todo_Error_Handler::log("[UnionPay Debug] 生成证书 certId: {$cert_id}", Todo_Error_Handler::LEVEL_INFO);
        Todo_Error_Handler::log("[UnionPay Debug] 生成签名 signature: {$params['signature']}", Todo_Error_Handler::LEVEL_INFO);

        // 尝试从私钥动态导出对应公钥，测试私钥本身数学匹配度
        $pkey_details = openssl_pkey_get_details($pkey);
        if (!empty($pkey_details['key'])) {
            $derived_pub_key = openssl_pkey_get_public($pkey_details['key']);
            $self_derived_verify = openssl_verify($sign_str, $signature, $derived_pub_key, OPENSSL_ALGO_SHA1);
            Todo_Error_Handler::log("[UnionPay Debug] 私钥自带导出的公钥验签结果 (1成功,0失败): {$self_derived_verify}", Todo_Error_Handler::LEVEL_INFO);
        }

        // 测试后台配置的【银联验签公钥】
        if (!empty($public_key_str)) {
            $pub_key_resource = @openssl_pkey_get_public($public_key_str);
            if ($pub_key_resource) {
                $v_sha1 = openssl_verify($sign_str, $signature, $pub_key_resource, OPENSSL_ALGO_SHA1);
                $v_sha256 = openssl_verify($sign_str, $signature, $pub_key_resource, OPENSSL_ALGO_SHA256);
                Todo_Error_Handler::log("[UnionPay Debug] 后台配置公钥验签 (SHA1: {$v_sha1}, SHA256: {$v_sha256})", Todo_Error_Handler::LEVEL_INFO);
            } else {
                Todo_Error_Handler::log("[UnionPay Debug] 后台配置公钥 openssl_pkey_get_public 失败，非有效 PEM 公钥", Todo_Error_Handler::LEVEL_WARNING);
            }
        } else {
            Todo_Error_Handler::log("[UnionPay Debug] 后台未配置【银联验签公钥】", Todo_Error_Handler::LEVEL_INFO);
        }
    }

    // 银联前台支付仅支持 POST 提交，构造自动 POST 提交 HTML 表单
    $html_form = '<form id="unionpay_submit_form" action="' . esc_url($gateway_url) . '" method="post" target="_blank">' . "\n";
    foreach ($params as $k => $v) {
        $html_form .= '    <input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '" />' . "\n";
    }
    $html_form .= '</form>' . "\n";
    $html_form .= '<script type="text/javascript">document.getElementById("unionpay_submit_form").submit();</script>';

    Todo_Order_Manager::update_order($order_id, array('payment_method' => 'unionpay'));

    return array(
        'success' => true,
        'message' => __('正在跳转支付页'),
        'order_no' => $order_no,
        'html_form' => $html_form,
        'pay_url' => $gateway_url,
    );
}

/**
 * 处理余额支付
 */
function process_balance_payment($order, $user_id) {
    if (!empty($order['is_recharge'])) {
        throw new Exception(__('账户充值订单禁止使用余额支付'));
    }
    if (!$user_id) {
        throw new Exception(__('余额支付需要登录'));
    }
    
    // 获取用户实际余额
    $user_balance = function_exists('todo_get_user_balance') ? todo_get_user_balance($user_id) : floatval(get_user_meta($user_id, 'todo_balance', true) ?: 0);
    $order_amount = floatval($order['total_amount']);
    
    if ($user_balance < $order_amount) {
        $bal_fmt = function_exists('todo_format_price') ? todo_format_price($user_balance) : number_format($user_balance, 2);
        throw new Exception(sprintf(__('余额不足，当前余额：%s'), $bal_fmt));
    }
    
    // 双重检查订单支付状态
    $fresh_order = Todo_Order_Manager::get_order($order['id'], false);
    if ($fresh_order && isset($fresh_order['payment_status']) && $fresh_order['payment_status'] === 'paid') {
        throw new Exception(__('订单已支付'));
    }

    // 执行余额扣减逻辑
    if (function_exists('todo_core_deduct_user_balance')) {
        $deducted = todo_core_deduct_user_balance($user_id, $order_amount, 'consume', __('订单支付') . ': ' . $order['order_no'], $order['id']);
        if (!$deducted) {
            throw new Exception(__('余额扣减失败'));
        }
    } else {
        $new_balance = $user_balance - $order_amount;
        if (function_exists('update_user_meta')) {
            update_user_meta($user_id, 'todo_balance', $new_balance);
        }
    }
    
    // 更新订单支付状态（传入 paid_amount 与 paid_at）
    Todo_Order_Manager::update_payment_status($order['id'], 'paid', array(
        'payment_method' => 'balance',
        'paid_amount' => $order_amount,
        'transaction_id' => 'balance_' . time(),
        'paid_at' => function_exists('todo_current_time') ? todo_current_time('mysql') : date('Y-m-d H:i:s')
    ));
    
    // 发送支付成功通知
    if (function_exists('todo_do_action')) {
        todo_do_action('todo_payment_completed', $order['id'], 'balance');
    }
    if (function_exists('todo_notify_admin_order_payment')) {
        todo_notify_admin_order_payment($order['id']);
    }
    
    $redirect_url = function_exists('todo_get_user_page_url') ? todo_get_user_page_url('order-detail') . '&id=' . $order['id'] : todo_home_url('/?user=order-detail&id=' . $order['id']);
    if (function_exists('get_language_url') && function_exists('current_locale')) {
        $redirect_url = get_language_url(current_locale(), $redirect_url);
    }

    return array(
        'success' => true,
        'message' => __('支付成功'),
        'redirect_url' => $redirect_url
    );
}

/**
 * 处理货到付款（COD）
 */
function process_cod_payment($order) {
    if (!empty($order['is_recharge'])) {
        throw new Exception(__('账户充值订单不支持货到付款'));
    }
    
    // 更新订单支付方式为 cod，支付状态保持 pending（或 cod_pending），订单状态更新为 processing（代发货）
    if (class_exists('Todo_Order_Manager')) {
        Todo_Order_Manager::update_payment_status($order['id'], 'pending', array(
            'payment_method' => 'cod',
            'transaction_id' => 'cod_' . time()
        ));
        Todo_Order_Manager::update_order_status($order['id'], 'processing');
    }

    if (function_exists('todo_do_action')) {
        todo_do_action('todo_order_cod_submitted', $order['id']);
    }
    if (function_exists('todo_notify_admin_order_payment')) {
        todo_notify_admin_order_payment($order['id']);
    }

    $redirect_url = function_exists('todo_get_user_page_url') ? todo_get_user_page_url('order-detail') . '&id=' . $order['id'] : (function_exists('todo_home_url') ? todo_home_url('/?user=order-detail&id=' . $order['id']) : ('/?user=order-detail&id=' . $order['id']));

    return array(
        'success' => true,
        'message' => __('货到付款订单提交成功！商家将为您安排配送'),
        'redirect_url' => $redirect_url
    );
}

/**
 * 处理线下转账（Bank Transfer / Offline）
 */
function process_offline_payment($order) {
    if (!empty($order['is_recharge'])) {
        throw new Exception(__('账户充值订单不支持线下转账'));
    }
    
    // 更新订单支付方式为 offline，支付状态保持 pending（待确认），订单状态保持 pending / processing
    if (class_exists('Todo_Order_Manager')) {
        Todo_Order_Manager::update_payment_status($order['id'], 'pending', array(
            'payment_method' => 'offline',
            'transaction_id' => 'offline_' . time()
        ));
        Todo_Order_Manager::update_order_status($order['id'], 'pending');
    }

    if (function_exists('todo_do_action')) {
        todo_do_action('todo_order_offline_submitted', $order['id']);
    }
    if (function_exists('todo_notify_admin_order_payment')) {
        todo_notify_admin_order_payment($order['id']);
    }

    $redirect_url = function_exists('todo_get_user_page_url') ? todo_get_user_page_url('order-detail') . '&id=' . $order['id'] : (function_exists('todo_home_url') ? todo_home_url('/?user=order-detail&id=' . $order['id']) : ('/?user=order-detail&id=' . $order['id']));

    return array(
        'success' => true,
        'message' => __('线下转账申请提交成功，请按照提示完成打款，商家核对后将为您处理'),
        'redirect_url' => $redirect_url
    );
}

/**
 * 处理PayJS微信支付
 */
function process_payjs_payment($order, $config) {
    if (!isset($config['mchid']) || !isset($config['key'])) {
        throw new Exception(__('PayJS配置不完整'));
    }
    
    $mchid = $config['mchid'];
    $key = $config['key'];
    $order_amount = intval(floatval($order['total_amount']) * 100); // 转换为分
    
    // 构建支付参数
    $params = array(
        'mchid' => $mchid,
        'total_fee' => $order_amount,
        'out_trade_no' => $order['order_no'],
        'body' => __('订单支付-') . $order['order_no'],
        'notify_url' => todo_home_url('/api/payment/payjs/notify'),
        'callback_url' => todo_home_url('/user/order-detail?id=' . $order['id'])
    );
    
    // 生成签名
    ksort($params);
    $sign_str = '';
    foreach ($params as $k => $v) {
        $sign_str .= $k . '=' . $v . '&';
    }
    $sign_str .= 'key=' . $key;
    $params['sign'] = strtoupper(md5($sign_str));
    
    // 发送请求到PayJS
    $response = todo_remote_post('https://payjs.cn/api/native', array(
        'body' => $params,
        'timeout' => 30
    ));
    
    if (todo_is_error($response)) {
        $response_error = ($response instanceof Throwable || $response instanceof Exception) ? $response->getMessage() : 'HTTP Request Failed';
        throw new Exception(__('支付请求失败') . '：' . $response_error);
    }
    
    $body = todo_remote_retrieve_body($response);
    $result = json_decode($body, true);
    
    if (!$result || $result['return_code'] != 1) {
        throw new Exception(__('支付请求失败') . '：' . ($result['return_msg'] ?? __('未知错误')));
    }
    
    // 保存支付信息
    todo_update_post_meta($order['id'], '_payment_info', array(
        'method' => 'payjs',
        'payjs_order_id' => $result['payjs_order_id'],
        'qr_code' => $result['qrcode']
    ));
    
    return array(
        'success' => true,
        'message' => __('请扫码支付'),
        'qr_code' => $result['qrcode'],
        'order_no' => $order['order_no']
    );
}

/**
 * 处理支付宝支付
 */
function process_alipay_payment($order, $config) {
    // 这里可以集成支付宝支付接口
    // 暂时返回模拟结果
    throw new Exception(__('支付宝支付暂未开放'));
}

/**
 * 检查支付状态
 */
function todo_ajax_check_payment_status() {
    try {
        // 验证CSRF令牌
        $security = Todo_Security_Manager::getInstance();
        if (!$security->verify_request_csrf('check_payment_status', todo_post_string('_todononce'))) {
            throw new Exception(__('安全验证失败'));
        }
        
        $order_no = sanitize_text_field(todo_post_string('order_no'));
        
        if (!$order_no) {
            throw new Exception(__('订单号无效'));
        }
        
        global $tododb;
        $is_paid = false;
        $redirect_url = null;

        if (strpos($order_no, 'RC') === 0) {
            $raw_num = substr($order_no, 2);
            $rc_id = (strlen($raw_num) > 6) ? intval(substr($raw_num, -6)) : intval($raw_num);
            if ($rc_id > 0) {
                $rc_row = $tododb->get_row($tododb->prepare("SELECT * FROM {$tododb->prefix}recharge_records WHERE id = %d LIMIT 1", $rc_id), [], 'ARRAY_A');
                if (is_object($rc_row)) $rc_row = (array) $rc_row;
                if (!$rc_row || !todo_payment_user_can_access_recharge($rc_row)) {
                    throw new Exception(__('无权限查看此订单'));
                }
                if ($rc_row && ($rc_row['status'] === 'completed' || $rc_row['status'] === 'paid')) {
                    $is_paid = true;
                    $redirect_url = function_exists('todo_get_user_page_url') ? todo_get_user_page_url('wallet') : todo_home_url('/?user=wallet');
                }
            }
        } else {
            $order = Todo_Order_Manager::get_order_by_no($order_no);
            if ($order) {
                if (!todo_payment_user_can_access_order($order)) {
                    throw new Exception(__('无权限查看此订单'));
                }
                $is_paid = ($order['payment_status'] === 'paid');
                if ($is_paid) {
                    $redirect_url = function_exists('todo_get_user_page_url') ? todo_get_user_page_url('order-detail') . '&id=' . intval($order['id']) : todo_home_url('/?user=order-detail&id=' . intval($order['id']));
                }
            } else {
                throw new Exception(__('订单不存在'));
            }
        }
        
        todo_send_json(array(
            'success' => true,
            'paid' => $is_paid,
            'redirect_url' => $redirect_url
        ));
        
    } catch (Exception $e) {
        todo_send_json(array(
            'success' => false,
            'message' => $e->getMessage()
        ));
    }
}

/**
 * 添加余额变动记录
 */
function todo_add_balance_log($user_id, $amount, $type, $description, $related_id = 0, $related_type = '') {
    global $tododb;
    if (!$tododb) return;
    
    $prefix = isset($tododb->prefix) ? $tododb->prefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_');
    $table = $prefix . 'balance_logs';
    
    $data = array(
        'user_id' => intval($user_id),
        'amount' => floatval($amount),
        'type' => sanitize_text_field($type),
        'description' => sanitize_text_field($description),
        'related_id' => intval($related_id) > 0 ? intval($related_id) : null,
        'related_type' => sanitize_text_field($related_type),
        'created_at' => function_exists('todo_current_time') ? todo_current_time('mysql') : date('Y-m-d H:i:s')
    );
    
    try {
        $tododb->insert($table, $data);
    } catch (\Exception $e) {}
}

// 处理AJAX请求（仅当单独作为入口请求时执行，内部 include 时跳过自动路由分发）
if (!defined('AB_INTERNAL_INCLUDE')) {
    $action = sanitize_text_field(todo_request_string('action'));

    switch ($action) {
        case 'process_payment':
            todo_ajax_process_payment();
            break;
            
        case 'get_order_info':
            todo_ajax_get_order_info();
            break;
            
        case 'check_payment_status':
            todo_ajax_check_payment_status();
            break;

        default:
            todo_send_json_error(__('无效的操作'));
            break;
    }
}

/**
 * AJAX 获取订单详情信息用于收银台渲染
 */
function todo_ajax_get_order_info() {
    try {
        $order_id = todo_get_int('order_id', todo_post_int('order_id'));
        $recharge_id = todo_get_int('recharge_id', todo_post_int('recharge_id'));
        
        if ($order_id <= 0 && $recharge_id <= 0) {
            throw new Exception(__('订单ID无效'));
        }

        if ($recharge_id > 0) {
            global $tododb;
            $row = $tododb->get_row($tododb->prepare("SELECT * FROM {$tododb->prefix}recharge_records WHERE id = %d LIMIT 1", $recharge_id), [], 'ARRAY_A');
            if (is_object($row)) {
                $row = (array) $row;
            }
            if (!$row) {
                throw new Exception(__('订单不存在'));
            }
            if (!todo_payment_user_can_access_recharge($row)) {
                throw new Exception(__('无权限查看此订单'));
            }
            $amt = function_exists('todo_format_price') ? todo_format_price(floatval($row['amount']), 'number') : number_format(floatval($row['amount']), 2, '.', '');
            $created_ts = !empty($row['created_at']) ? strtotime($row['created_at']) : time();
            $formatted_rc_no = 'RC' . date('YmdHis', $created_ts) . str_pad($row['id'], 6, '0', STR_PAD_LEFT);
            todo_send_json(array(
                'success' => true,
                'is_recharge' => true,
                'order' => array(
                    'id' => (int) $row['id'],
                    'order_no' => $formatted_rc_no,
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'subtotal_amount' => $amt,
                    'shipping_fee' => '0.00',
                    'discount_amount' => '0.00',
                    'total_amount' => $amt,
                    'payment_status' => (string) ($row['status'] ?? 'pending'),
                    'order_status' => (string) ($row['status'] ?? 'pending'),
                    'items' => array(
                        array(
                            'product_title' => __('账户充值') . ' (' . (function_exists('todo_format_price') ? todo_format_price($row['amount'], 'number') : floatval($row['amount'])) . ')',
                            'product_name'  => __('账户充值'),
                            'spec_name'     => __('在线充值'),
                            'specs_text'    => __('在线充值'),
                            'product_image' => '',
                            'quantity'      => 1,
                            'price'         => $amt,
                            'total_price'   => $amt
                        )
                    )
                )
            ));
            return;
        }

        $order = Todo_Order_Manager::get_order($order_id, true);
        if (!$order) {
            throw new Exception(__('订单不存在'));
        }
        if (!todo_payment_user_can_access_order($order)) {
            throw new Exception(__('无权限查看此订单'));
        }

        // 计算真实的商品小计金额
        $calculated_subtotal = 0;
        $formatted_items = array();

        if (is_array($order['items'] ?? null)) {
            $pay_pids = array();
            foreach ($order['items'] as $chk_it) {
                $chk_pid = intval($chk_it['product_id'] ?? 0);
                if ($chk_pid > 0) $pay_pids[] = $chk_pid;
            }
            $pay_units_cache = function_exists('todo_preload_product_units') ? todo_preload_product_units($pay_pids) : array();

            foreach ($order['items'] as $item) {
                $p_name = (string) ($item['product_title'] ?? $item['product_title'] ?? $item['title'] ?? '');
                $p_id = intval($item['product_id'] ?? 0);
                if (empty($p_name) && $p_id > 0) {
                    $prod_post = get_post($p_id);
                    if ($prod_post && !empty($prod_post->post_title)) {
                        $p_name = $prod_post->post_title;
                    } else {
                        $p_name = __('商品') . ' #' . $p_id;
                    }
                }
                $t_price = floatval($item['total_price'] ?? ($item['price'] * $item['quantity']));
                $calculated_subtotal += $t_price;

                $specs_raw = $item['product_specs'] ?? ($item['product_spec'] ?? '');
                $specs_arr = [];
                if (is_string($specs_raw) && $specs_raw !== '') {
                    $decoded = json_decode($specs_raw, true);
                    if (is_array($decoded)) {
                        $specs_arr = $decoded;
                    }
                } elseif (is_array($specs_raw)) {
                    $specs_arr = $specs_raw;
                }
                $specs_text = '';
                if (!empty($specs_arr) && function_exists('todo_format_specs_text')) {
                    $specs_text = todo_format_specs_text($specs_arr, $p_id);
                } else {
                    $sn = isset($item['spec_name']) ? (string) $item['spec_name'] : '';
                    if ($sn !== '' && function_exists('todo_normalize_specs_text_from_spec_name')) {
                        $specs_text = todo_normalize_specs_text_from_spec_name($sn, $p_id);
                    } else {
                        $specs_text = trim($sn);
                    }
                }

                $raw_img = (string) ($item['product_image'] ?? $item['image'] ?? '');
                if ($raw_img !== '' && function_exists('todo_media_url_to_runtime_absolute')) {
                    $p_img = todo_media_url_to_runtime_absolute($raw_img);
                } elseif ($raw_img !== '' && function_exists('todo_media_url_to_absolute')) {
                    $p_img = todo_media_url_to_absolute($raw_img);
                } else {
                    $p_img = $raw_img;
                }

                $item_fmt_price = function_exists('todo_format_price') ? todo_format_price(floatval($item['price'] ?? 0), 'number') : number_format(floatval($item['price'] ?? 0), 2, '.', '');
                $item_fmt_total = function_exists('todo_format_price') ? todo_format_price($t_price, 'number') : number_format($t_price, 2, '.', '');

                $u_raw = isset($item['unit']) ? (string)$item['unit'] : '';
                $uq_raw = isset($item['unit_qty']) ? $item['unit_qty'] : null;

                if (($u_raw === '' || $uq_raw === null) && $p_id > 0) {
                    $cached_u = $pay_units_cache[$p_id] ?? null;
                    if ($cached_u) {
                        if ($u_raw === '' && isset($cached_u['unit'])) $u_raw = (string)$cached_u['unit'];
                        if ($uq_raw === null && isset($cached_u['unit_qty'])) $uq_raw = $cached_u['unit_qty'];
                    }
                }

                $p_unit_txt = function_exists('todo_format_unit_text') ? todo_format_unit_text($uq_raw, $u_raw) : '';

                $formatted_items[] = array(
                    'product_title' => $p_name,
                    'product_name'  => $p_name,
                    'spec_name'     => $specs_text,
                    'specs_text'    => $specs_text,
                    'product_image' => $p_img,
                    'quantity'      => (int) ($item['quantity'] ?? 1),
                    'price'         => $item_fmt_price,
                    'total_price'   => $item_fmt_total,
                    'unit_text'     => $p_unit_txt,
                );
            }
        }

        $raw_subtotal = floatval($order['subtotal_amount'] ?? 0);
        if ($raw_subtotal <= 0) {
            $raw_subtotal = $calculated_subtotal;
        }

        $total_val = floatval($order['total_amount'] ?? 0);
        $ship_val = floatval($order['shipping_fee'] ?? 0);
        $disc_val = floatval($order['discount_amount'] ?? 0);
        $pay_val = floatval($order['payment_amount'] ?? 0) > 0 ? floatval($order['payment_amount']) : $total_val;

        $points_used = 0;
        $points_disc_base = 0.0;
        if (class_exists('Todo_Order_Manager')) {
            $points_used = intval(Todo_Order_Manager::get_order_custom_field((int) $order['id'], '_points_used') ?: 0);
            $points_disc_base = floatval(Todo_Order_Manager::get_order_custom_field((int) $order['id'], '_points_discount_amount') ?: 0);
        }
        $coupon_disc_base = max(0.0, $disc_val - $points_disc_base);

        // 近零抵扣（显示币四舍五入为 0.00）归零，避免前端展示出可见的 0 元抵扣
        $coupon_show_val = function_exists('todo_apply_currency_exchange') ? floatval(todo_apply_currency_exchange($coupon_disc_base)) : $coupon_disc_base;
        if (round($coupon_show_val, 2) <= 0.0) {
            $coupon_disc_base = 0.0;
        }
        $points_show_val = function_exists('todo_apply_currency_exchange') ? floatval(todo_apply_currency_exchange($points_disc_base)) : $points_disc_base;
        if (round($points_show_val, 2) <= 0.0) {
            $points_disc_base = 0.0;
            $points_used = 0;
        }

        todo_send_json(array(
            'success' => true,
            'order' => array(
                'id' => (int) $order['id'],
                'order_no' => (string) $order['order_no'],
                'created_at' => (string) ($order['created_at'] ?? ''),
                'subtotal_amount' => function_exists('todo_format_price') ? todo_format_price($raw_subtotal, 'number') : number_format($raw_subtotal, 2, '.', ''),
                'shipping_fee' => function_exists('todo_format_price') ? todo_format_price($ship_val, 'number') : number_format($ship_val, 2, '.', ''),
                'discount_amount' => function_exists('todo_format_price') ? todo_format_price($disc_val, 'number') : number_format($disc_val, 2, '.', ''),
                'coupon_discount_amount' => function_exists('todo_format_price') ? todo_format_price($coupon_disc_base, 'number') : number_format($coupon_disc_base, 2, '.', ''),
                'points_discount_amount' => function_exists('todo_format_price') ? todo_format_price($points_disc_base, 'number') : number_format($points_disc_base, 2, '.', ''),
                'points_used' => $points_used,
                'total_amount' => function_exists('todo_format_price') ? todo_format_price($total_val, 'number') : number_format($total_val, 2, '.', ''),
                'pay_amount' => function_exists('todo_format_price') ? todo_format_price($pay_val, 'number') : number_format($pay_val, 2, '.', ''),
                'pay_amount_format' => function_exists('todo_format_price') ? todo_format_price($pay_val, 'number') : number_format($pay_val, 2, '.', ''),
                'payment_method' => (string) ($order['payment_method'] ?? ''),
                'payment_status' => (string) ($order['payment_status'] ?? 'unpaid'),
                'order_status' => (string) ($order['order_status'] ?? 'pending'),
                'items' => $formatted_items
            )
        ));
    } catch (Exception $e) {
        todo_send_json(array('success' => false, 'message' => $e->getMessage()));
    }
}
