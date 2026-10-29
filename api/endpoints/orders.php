<?php
/**
 * 订单 API 端点
 *
 * 提供订单相关的 API 端点
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

function api_orders_get_product_for_purchase($product_id) {
    global $tododb;

    $now_gmt = current_time('mysql', true);
    $product_table = TABLE_PREFIX . 'posts';
    $fields_table = TABLE_PREFIX . 'model_fields_product';

    $query = "SELECT p.ID, p.post_title, p.post_status, p.post_date_gmt, p.featured_image, mp.price, mp.wholesale_price, mp.wholesale_vip_price, mp.original_price, mp.stock, mp.spec_type, mp.spec_details, mp.sale_status, mp.product_type
              FROM {$product_table} p
              LEFT JOIN {$fields_table} mp ON p.ID = mp.post_id
              WHERE p.ID = ? AND p.post_type = 'product' LIMIT 1";
    $row = $tododb->get_row($query, [intval($product_id)]);
    if (!$row) {
        return null;
    }

    if ($row->post_status !== 'publish' || (isset($row->post_date_gmt) && $row->post_date_gmt > $now_gmt)) {
        return null;
    }
    $sale_status = isset($row->sale_status) && $row->sale_status !== '' ? $row->sale_status : 'online';
    if ($sale_status === 'warehouse') {
        return null;
    }

    return $row;
}

function api_orders_normalize_specs_array($specs_arr) {
    if (!is_array($specs_arr) || empty($specs_arr)) {
        return null;
    }

    $normalized = [];
    foreach ($specs_arr as $key => $value) {
        if (is_array($value) || is_object($value)) {
            return null;
        }

        $normalized_key = null;
        if (is_int($key) || ctype_digit((string) $key)) {
            $normalized_key = 'v' . intval($key);
        } elseif (is_string($key) && preg_match('/^v\d+$/', $key)) {
            $normalized_key = $key;
        } else {
            return null;
        }

        $normalized_value = trim((string) $value);
        if ($normalized_value === '') {
            return null;
        }

        $normalized[$normalized_key] = $normalized_value;
    }

    if (empty($normalized)) {
        return null;
    }

    uksort($normalized, function($a, $b) {
        return intval(substr($a, 1)) <=> intval(substr($b, 1));
    });

    return $normalized;
}

function api_orders_extract_detail_specs($detail) {
    if (!is_array($detail)) {
        return null;
    }

    $specs = [];
    foreach ($detail as $key => $value) {
        if (is_string($key) && preg_match('/^v\d+$/', $key)) {
            $specs[$key] = trim((string) $value);
        }
    }

    if (empty($specs)) {
        return null;
    }

    uksort($specs, function($a, $b) {
        return intval(substr($a, 1)) <=> intval(substr($b, 1));
    });

    return $specs;
}

function api_orders_match_spec_detail($details, $specs_arr) {
    $normalized_specs = api_orders_normalize_specs_array($specs_arr);
    if (!is_array($details) || !is_array($normalized_specs)) {
        return null;
    }
    foreach ($details as $idx => $detail) {
        if (!is_array($detail)) {
            continue;
        }

        $detail_specs = api_orders_extract_detail_specs($detail);
        if (!is_array($detail_specs)) {
            continue;
        }

        if (count($detail_specs) !== count($normalized_specs)) {
            continue;
        }

        if (array_keys($detail_specs) !== array_keys($normalized_specs)) {
            continue;
        }

        $matched = true;
        foreach ($normalized_specs as $nk => $nv) {
            if (!isset($detail_specs[$nk])) {
                $matched = false;
                break;
            }
            if (function_exists('todo_multilang_strings_match')) {
                if (!todo_multilang_strings_match($detail_specs[$nk], $nv)) {
                    $matched = false;
                    break;
                }
            } else {
                if ($detail_specs[$nk] !== $nv) {
                    $matched = false;
                    break;
                }
            }
        }

        if ($matched) {
            return [$idx, $detail];
        }
    }
    return null;
}

function api_orders_normalize_shipping_method($shipping_method_raw) {
    $shipping_method_raw = sanitize_text_field((string) $shipping_method_raw);
    if ($shipping_method_raw === '' || $shipping_method_raw === 'delivery' || $shipping_method_raw === 'express' || $shipping_method_raw === 'ems') {
        return 'delivery';
    }
    if ($shipping_method_raw === 'pickup' || $shipping_method_raw === 'self_pickup') {
        return 'pickup';
    }

    return '';
}

function api_orders_validate_shipping_address($address_raw) {
    if (is_array($address_raw)) {
        $address = $address_raw;
    } else {
        $address_raw = trim((string) $address_raw);
        if ($address_raw === '') {
            return '';
        }
        $decoded = json_decode($address_raw, true);
        $address = is_array($decoded) ? $decoded : null;
        if (!$address) {
            throw new Exception('收货地址格式无效');
        }
    }

    $name = trim((string) ($address['name'] ?? ''));
    $phone = trim((string) ($address['phone'] ?? ''));
    $detail = trim((string) ($address['detail'] ?? ''));
    if ($name === '' || $phone === '' || $detail === '') {
        throw new Exception('收货地址信息不完整');
    }

    return json_encode($address, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function api_orders_convert_amount_to_base($amount) {
    $amount = floatval($amount);
    if ($amount <= 0) {
        return 0.0;
    }

    if (function_exists('todo_get_currency_config') && function_exists('todo_get_default_currency_config')) {
        $currency_cfg = todo_get_currency_config();
        $default_cfg = todo_get_default_currency_config();
        $curr_rate = ($currency_cfg['status'] == 1 && floatval($currency_cfg['rate']) > 0) ? floatval($currency_cfg['rate']) : 1.0;
        $base_rate = floatval($default_cfg['rate'] ?? 0) > 0 ? floatval($default_cfg['rate']) : 1.0;
        $ratio = $curr_rate / $base_rate;
        if ($ratio > 0) {
            return round($amount / $ratio, 2);
        }
    }

    return round($amount, 2);
}

function api_orders_convert_amount_to_display($amount) {
    $amount = floatval($amount);
    if ($amount <= 0) {
        return 0.0;
    }

    if (function_exists('todo_apply_currency_exchange')) {
        return round(floatval(todo_apply_currency_exchange($amount)), 2);
    }

    return round($amount, 2);
}

function api_orders_get_points_usable_categories() {
    $raw_usable_categories = (string) (function_exists('get_option') ? get_option('points_usable_categories', '') : '');
    return array_values(array_unique(array_filter(
        array_map('intval', preg_split('/\s*,\s*/', $raw_usable_categories))
    )));
}

function api_orders_get_product_category_ids($product_id) {
    $product_id = intval($product_id);
    if ($product_id <= 0 || !function_exists('get_the_terms')) {
        return array();
    }

    $category_ids = array();
    $terms = get_the_terms($product_id, 'category');
    if ($terms && is_array($terms)) {
        foreach ($terms as $term) {
            $term_id = is_object($term) ? intval($term->term_id ?? 0) : intval($term['term_id'] ?? 0);
            if ($term_id > 0) {
                $category_ids[] = $term_id;
            }
        }
    }

    return array_values(array_unique($category_ids));
}

function api_orders_get_points_eligible_subtotal($order_items_data) {
    if (!is_array($order_items_data) || empty($order_items_data)) {
        return 0.0;
    }

    $usable_categories = api_orders_get_points_usable_categories();
    if (empty($usable_categories)) {
        $subtotal = 0.0;
        foreach ($order_items_data as $item) {
            $subtotal += floatval($item['total_price'] ?? 0);
        }
        return round(max(0, $subtotal), 2);
    }

    $eligible_subtotal = 0.0;
    foreach ($order_items_data as $item) {
        $product_id = intval($item['product_id'] ?? 0);
        $item_category_ids = api_orders_get_product_category_ids($product_id);
        if (!empty($item_category_ids) && !empty(array_intersect($usable_categories, $item_category_ids))) {
            $eligible_subtotal += floatval($item['total_price'] ?? 0);
        }
    }

    return round(max(0, $eligible_subtotal), 2);
}

function api_orders_adjust_spec_detail_stock($product_id, $spec_index, $quantity_delta, $fields_table, $current_spec_details_raw = null, $max_retries = 3) {
    global $tododb;

    $product_id = intval($product_id);
    $spec_index = intval($spec_index);
    $quantity_delta = intval($quantity_delta);
    $max_retries = max(1, intval($max_retries));

    for ($attempt = 0; $attempt < $max_retries; $attempt++) {
        $spec_details_raw = $current_spec_details_raw;
        if (!is_string($spec_details_raw) || $spec_details_raw === '') {
            $inventory_row = $tododb->get_row("SELECT spec_details FROM {$fields_table} WHERE post_id = ? LIMIT 1", [$product_id]);
            $spec_details_raw = isset($inventory_row->spec_details) ? (string) $inventory_row->spec_details : '';
        }

        if ($spec_details_raw === '') {
            throw new Exception('规格库存不存在');
        }

        $details = json_decode($spec_details_raw, true);
        if (!is_array($details) || !isset($details[$spec_index]) || !is_array($details[$spec_index])) {
            throw new Exception('规格不存在');
        }

        $stock_key = isset($details[$spec_index]['stock']) ? 'stock' : (isset($details[$spec_index]['stock_count']) ? 'stock_count' : null);
        if ($stock_key === null) {
            throw new Exception('规格库存不存在');
        }

        $current_stock = intval($details[$spec_index][$stock_key]);
        $new_stock = $current_stock + $quantity_delta;
        if ($new_stock < 0) {
            throw new Exception('库存不足');
        }

        $details[$spec_index][$stock_key] = $new_stock;
        $new_spec_details = json_encode($details, JSON_UNESCAPED_UNICODE);
        if (!is_string($new_spec_details) || $new_spec_details === '') {
            throw new Exception('规格库存更新失败');
        }

        $affected = $tododb->query(
            "UPDATE {$fields_table} SET spec_details = ? WHERE post_id = ? AND spec_details = ?",
            [$new_spec_details, $product_id, $spec_details_raw]
        );

        if ($affected) {
            return true;
        }

        $current_spec_details_raw = null;
    }

    throw new Exception('库存已变动，请刷新后重试');
}

function api_orders_adjust_product_inventory($product_id, $stock_delta, $sales_delta, $fields_table, $require_stock = false) {
    global $tododb;

    $product_id = intval($product_id);
    $stock_delta = intval($stock_delta);
    $sales_delta = intval($sales_delta);

    if ($stock_delta === 0 && $sales_delta === 0) {
        return true;
    }

    if ($stock_delta < 0 && $require_stock) {
        $need_stock = abs($stock_delta);
        $affected = $tododb->query(
            "UPDATE {$fields_table}
             SET stock = stock - ?, actual_sales = actual_sales + ?
             WHERE post_id = ? AND stock >= ?",
            [$need_stock, max(0, $sales_delta), $product_id, $need_stock]
        );
        if (!$affected) {
            throw new Exception('库存不足');
        }
        return true;
    }

    if ($stock_delta > 0) {
        $affected = $tododb->query(
            "UPDATE {$fields_table}
             SET stock = stock + ?, actual_sales = CASE WHEN actual_sales >= ? THEN actual_sales - ? ELSE 0 END
             WHERE post_id = ?",
            [$stock_delta, max(0, $sales_delta), max(0, $sales_delta), $product_id]
        );
        if ($affected === false) {
            throw new Exception('库存回滚失败');
        }
        return true;
    }

    if ($stock_delta === 0 && $sales_delta !== 0) {
        if ($sales_delta > 0) {
            $affected = $tododb->query(
                "UPDATE {$fields_table} SET actual_sales = actual_sales + ? WHERE post_id = ?",
                [$sales_delta, $product_id]
            );
        } else {
            $sales_reduce = abs($sales_delta);
            $affected = $tododb->query(
                "UPDATE {$fields_table}
                 SET actual_sales = CASE WHEN actual_sales >= ? THEN actual_sales - ? ELSE 0 END
                 WHERE post_id = ?",
                [$sales_reduce, $sales_reduce, $product_id]
            );
        }

        if ($affected === false) {
            throw new Exception('销量回滚失败');
        }
    }

    return true;
}

function api_orders_payjs_build_sign($params, $key) {
    if (!is_array($params)) {
        $params = [];
    }
    $filtered = [];
    foreach ($params as $k => $v) {
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

function api_orders_payjs_request_jsapi($order_no, $total_fee, $openid, $body, $notify_url, $attach = '') {
    $mchid = '';
    $key = '';
    $opts = function_exists('todo_get_option') ? todo_get_option('todo_payment_settings', array()) : array();
    if (is_array($opts) && isset($opts['third_party']) && is_array($opts['third_party']) && isset($opts['third_party']['payjs']) && is_array($opts['third_party']['payjs'])) {
        if (empty($opts['third_party']['enabled']) || empty($opts['third_party']['payjs']['enabled'])) {
            return ['success' => false, 'message' => 'PayJS未启用'];
        }
        $mchid = isset($opts['third_party']['payjs']['mchid']) ? (string) $opts['third_party']['payjs']['mchid'] : '';
        $key = isset($opts['third_party']['payjs']['key']) ? (string) $opts['third_party']['payjs']['key'] : '';
    }
    if ($mchid === '' && function_exists('todo_get_option')) {
        $mchid = (string) todo_get_option('payjs_mchid', '');
    }
    if ($key === '' && function_exists('todo_get_option')) {
        $key = (string) todo_get_option('payjs_key', '');
    }
    if ($mchid === '' && function_exists('get_option')) {
        $mchid = (string) get_option('payjs_mchid', '');
    }
    if ($key === '' && function_exists('get_option')) {
        $key = (string) get_option('payjs_key', '');
    }

    if ($mchid === '' || $key === '') {
        return ['success' => false, 'message' => 'PayJS配置不完整'];
    }
    $openid = trim((string) $openid);
    if ($openid === '') {
        return ['success' => false, 'message' => '缺少openid'];
    }
    $total_fee = (int) $total_fee;
    if ($total_fee <= 0) {
        return ['success' => false, 'message' => '支付金额不正确'];
    }

    $params = [
        'mchid' => $mchid,
        'total_fee' => $total_fee,
        'out_trade_no' => (string) $order_no,
        'body' => (string) $body,
        'attach' => (string) $attach,
        'notify_url' => (string) $notify_url,
        'openid' => $openid,
    ];
    $params['sign'] = api_orders_payjs_build_sign($params, $key);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://payjs.cn/api/jsapi');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
        'User-Agent: TodoCMS-API/1.0',
    ]);
    $response = curl_exec($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $http_code !== 200) {
        $msg = $error ? ('PayJS请求失败：' . $error) : ('PayJS请求失败，HTTP ' . $http_code);
        return ['success' => false, 'message' => $msg];
    }

    $result = json_decode($response, true);
    if (!is_array($result)) {
        return ['success' => false, 'message' => 'PayJS响应格式错误'];
    }
    if (!isset($result['return_code']) || (int) $result['return_code'] !== 1) {
        $m = isset($result['return_msg']) ? (string) $result['return_msg'] : 'PayJS下单失败';
        return ['success' => false, 'message' => $m, 'raw' => $result];
    }
    $jsapi_raw = isset($result['jsapi']) ? (string) $result['jsapi'] : '';
    $jsapi = $jsapi_raw !== '' ? json_decode($jsapi_raw, true) : null;
    if (!is_array($jsapi)) {
        return ['success' => false, 'message' => 'PayJS jsapi 参数解析失败', 'raw' => $result];
    }

    return [
        'success' => true,
        'payjs_order_id' => isset($result['payjs_order_id']) ? (string) $result['payjs_order_id'] : '',
        'jsapi' => $jsapi,
        'raw' => $result,
    ];
}

function api_orders_get_wechat_openid($user_id) {
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return '';
    }
    if (function_exists('get_user_meta')) {
        $openid = get_user_meta($user_id, 'wechat_openid', true);
        if (is_string($openid) && trim($openid) !== '') {
            return trim($openid);
        }
    }
    global $tododb;
    $row = $tododb->get_row("SELECT meta_value FROM " . TABLE_PREFIX . "user_meta WHERE user_id = ? AND meta_key = 'wechat_openid' LIMIT 1", [$user_id], 'ARRAY_A');
    if ($row && isset($row['meta_value']) && is_string($row['meta_value'])) {
        return trim((string) $row['meta_value']);
    }
    return '';
}

function api_orders_build_notify_url() {
    $base = '';
    if (function_exists('todo_get_site_url')) {
        $base = (string) todo_get_site_url('/');
    } elseif (function_exists('home_url')) {
        $base = (string) home_url('/');
    }
    $base = trim($base);
    if ($base === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        if ($host !== '') {
            $base = $scheme . '://' . $host . '/';
        }
    }
    $base = rtrim($base, '/');
    if ($base === '') {
        return '/api/payment/payjs/notify';
    }
    return $base . '/api/payment/payjs/notify';
}

function api_orders_build_notify_url_for($path) {
    $path = '/' . ltrim((string) $path, '/');
    $base = '';
    if (function_exists('todo_get_site_url')) {
        $base = (string) todo_get_site_url('/');
    } elseif (function_exists('home_url')) {
        $base = (string) home_url('/');
    }
    $base = trim($base);
    if ($base === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        if ($host !== '') {
            $base = $scheme . '://' . $host . '/';
        }
    }
    $base = rtrim($base, '/');
    if ($base === '') {
        return $path;
    }
    return $base . $path;
}

function api_orders_get_service_settings() {
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
    if (!isset($settings['payment_methods']) || !is_array($settings['payment_methods'])) {
        $settings['payment_methods'] = array();
    }
    if (!isset($settings['payment_providers']) || !is_array($settings['payment_providers'])) {
        $settings['payment_providers'] = array('wechat' => 'payjs', 'alipay' => 'payjs');
    }
    return $settings;
}

function api_orders_get_payment_provider($method) {
    $method = sanitize_text_field((string) $method);
    $settings = api_orders_get_service_settings();
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
    } else {
        $provider = '';
    }
    return $provider;
}

function api_orders_is_payment_method_selected($method) {
    $method = sanitize_text_field((string) $method);
    if ($method === '') {
        return true;
    }
    $settings = api_orders_get_service_settings();
    $methods = isset($settings['payment_methods']) && is_array($settings['payment_methods']) ? $settings['payment_methods'] : array();
    if (empty($methods)) {
        return true;
    }
    return in_array($method, $methods, true);
}

function api_orders_wechat_sign_md5($params, $api_key) {
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
    $sign_str .= 'key=' . (string) $api_key;
    return strtoupper(md5($sign_str));
}

function api_orders_wechat_array_to_xml($data) {
    $xml = '<xml>';
    foreach ((array) $data as $k => $v) {
        $k = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $k);
        if ($k === '') {
            continue;
        }
        $v = (string) $v;
        if (preg_match('/^[0-9]+$/', $v)) {
            $xml .= "<{$k}>{$v}</{$k}>";
        } else {
            $xml .= "<{$k}><![CDATA[{$v}]]></{$k}>";
        }
    }
    $xml .= '</xml>';
    return $xml;
}

function api_orders_wechat_xml_to_array($xml) {
    if (!is_string($xml) || trim($xml) === '') {
        return array();
    }
    $obj = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
    if (!$obj) {
        return array();
    }
    return json_decode(json_encode($obj), true);
}

function api_orders_wechat_direct_request_jsapi($order_no, $total_fee, $openid, $body, $notify_url) {
    $opts = function_exists('todo_get_option') ? todo_get_option('todo_payment_settings', array()) : array();
    $wechat = isset($opts['wechat']) && is_array($opts['wechat']) ? $opts['wechat'] : array();
    $appid = isset($wechat['mini_appid']) ? (string) $wechat['mini_appid'] : '';
    $mch_id = isset($wechat['mch_id']) ? (string) $wechat['mch_id'] : '';
    $api_key = isset($wechat['api_key']) ? (string) $wechat['api_key'] : '';
    if (empty($wechat['enabled']) || $appid === '' || $mch_id === '' || $api_key === '') {
        return array('success' => false, 'message' => '微信支付未启用或配置不完整');
    }

    $nonce_str = bin2hex(random_bytes(8));
    $spbill_create_ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
    $params = array(
        'appid' => $appid,
        'mch_id' => $mch_id,
        'nonce_str' => $nonce_str,
        'body' => (string) $body,
        'out_trade_no' => (string) $order_no,
        'total_fee' => (int) $total_fee,
        'spbill_create_ip' => $spbill_create_ip,
        'notify_url' => (string) $notify_url,
        'trade_type' => 'JSAPI',
        'openid' => (string) $openid,
    );
    $params['sign'] = api_orders_wechat_sign_md5($params, $api_key);

    $xml = api_orders_wechat_array_to_xml($params);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.mch.weixin.qq.com/pay/unifiedorder');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: text/xml'));
    $resp = curl_exec($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $http_code !== 200) {
        $msg = $err ? ('微信支付请求失败：' . $err) : ('微信支付请求失败，HTTP ' . $http_code);
        return array('success' => false, 'message' => $msg);
    }
    $arr = api_orders_wechat_xml_to_array($resp);
    if (!is_array($arr) || !isset($arr['return_code']) || $arr['return_code'] !== 'SUCCESS') {
        $m = isset($arr['return_msg']) ? (string) $arr['return_msg'] : '微信支付返回失败';
        return array('success' => false, 'message' => $m, 'raw' => $arr);
    }
    if (!isset($arr['result_code']) || $arr['result_code'] !== 'SUCCESS') {
        $m = isset($arr['err_code_des']) ? (string) $arr['err_code_des'] : '微信支付下单失败';
        return array('success' => false, 'message' => $m, 'raw' => $arr);
    }
    $prepay_id = isset($arr['prepay_id']) ? (string) $arr['prepay_id'] : '';
    if ($prepay_id === '') {
        return array('success' => false, 'message' => '缺少prepay_id', 'raw' => $arr);
    }

    $timeStamp = (string) time();
    $nonceStr2 = bin2hex(random_bytes(8));
    $pkg = 'prepay_id=' . $prepay_id;
    $payParams = array(
        'appId' => $appid,
        'timeStamp' => $timeStamp,
        'nonceStr' => $nonceStr2,
        'package' => $pkg,
        'signType' => 'MD5',
    );
    $paySign = api_orders_wechat_sign_md5($payParams, $api_key);
    $payParams['paySign'] = $paySign;

    return array(
        'success' => true,
        'prepay_id' => $prepay_id,
        'jsapi' => $payParams,
        'raw' => $arr,
    );
}

function api_orders_hupijiao_build_hash($data, $app_secret) {
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

function api_orders_hupijiao_request($order_no, $total_amount_yuan, $title, $notify_url, $return_url, $plugins = '') {
    $opts = function_exists('todo_get_option') ? todo_get_option('todo_payment_settings', array()) : array();
    $tp = isset($opts['third_party']) && is_array($opts['third_party']) ? $opts['third_party'] : array();
    $hp = isset($tp['hupijiao']) && is_array($tp['hupijiao']) ? $tp['hupijiao'] : array();
    if (empty($tp['enabled']) || empty($hp['enabled'])) {
        return array('success' => false, 'message' => '虎皮椒未启用');
    }
    $appid = isset($hp['app_id']) ? (string) $hp['app_id'] : '';
    $app_secret = isset($hp['app_secret']) ? (string) $hp['app_secret'] : '';
    $gateway = isset($hp['gateway']) ? (string) $hp['gateway'] : '';
    if ($gateway === '') {
        $gateway = 'https://api.xunhupay.com/payment/do.html';
    }
    if ($appid === '' || $app_secret === '') {
        return array('success' => false, 'message' => '虎皮椒配置不完整');
    }
    $time = (string) time();
    $nonce_str = bin2hex(random_bytes(8));
    $params = array(
        'version' => '1.1',
        'appid' => $appid,
        'trade_order_id' => (string) $order_no,
        'total_fee' => number_format((float) $total_amount_yuan, 2, '.', ''),
        'title' => (string) $title,
        'time' => $time,
        'notify_url' => (string) $notify_url,
        'return_url' => (string) $return_url,
        'nonce_str' => $nonce_str,
        'plugins' => (string) $plugins,
    );
    $params['hash'] = api_orders_hupijiao_build_hash($params, $app_secret);

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
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $http_code !== 200) {
        $msg = $err ? ('虎皮椒请求失败：' . $err) : ('虎皮椒请求失败，HTTP ' . $http_code);
        return array('success' => false, 'message' => $msg);
    }
    $result = json_decode($resp, true);
    if (!is_array($result)) {
        return array('success' => false, 'message' => '虎皮椒响应格式错误');
    }
    $errcode = isset($result['errcode']) ? (int) $result['errcode'] : 0;
    if ($errcode !== 0) {
        $m = isset($result['errmsg']) ? (string) $result['errmsg'] : '虎皮椒下单失败';
        return array('success' => false, 'message' => $m, 'raw' => $result);
    }
    $url = isset($result['url']) ? (string) $result['url'] : '';
    if ($url === '' && isset($result['url_qrcode'])) {
        $url = (string) $result['url_qrcode'];
    }
    if ($url === '') {
        return array('success' => false, 'message' => '虎皮椒未返回支付链接', 'raw' => $result);
    }
    return array('success' => true, 'url' => $url, 'raw' => $result);
}

function api_orders_payjs_cashier_url($order_no, $total_fee_fen, $body, $notify_url, $callback_url, $attach = '') {
    $mchid = '';
    $key = '';
    $opts = function_exists('todo_get_option') ? todo_get_option('todo_payment_settings', array()) : array();
    if (is_array($opts) && isset($opts['third_party']) && is_array($opts['third_party']) && isset($opts['third_party']['payjs']) && is_array($opts['third_party']['payjs'])) {
        if (empty($opts['third_party']['enabled']) || empty($opts['third_party']['payjs']['enabled'])) {
            return array('success' => false, 'message' => 'PayJS未启用');
        }
        $mchid = isset($opts['third_party']['payjs']['mchid']) ? (string) $opts['third_party']['payjs']['mchid'] : '';
        $key = isset($opts['third_party']['payjs']['key']) ? (string) $opts['third_party']['payjs']['key'] : '';
    }
    if ($mchid === '' && function_exists('todo_get_option')) {
        $mchid = (string) todo_get_option('payjs_mchid', '');
    }
    if ($key === '' && function_exists('todo_get_option')) {
        $key = (string) todo_get_option('payjs_key', '');
    }
    if ($mchid === '' || $key === '') {
        return array('success' => false, 'message' => 'PayJS配置不完整');
    }

    $params = array(
        'mchid' => $mchid,
        'total_fee' => (int) $total_fee_fen,
        'out_trade_no' => (string) $order_no,
        'body' => (string) $body,
        'attach' => (string) $attach,
        'notify_url' => (string) $notify_url,
        'callback_url' => (string) $callback_url,
    );
    $params['sign'] = api_orders_payjs_build_sign($params, $key);
    $url = 'https://payjs.cn/api/cashier?' . http_build_query($params);
    return array('success' => true, 'url' => $url);
}

function api_orders_alipay_normalize_key($key, $is_private) {
    $key = trim((string) $key);
    if ($key === '') {
        return '';
    }
    if (strpos($key, 'BEGIN') !== false) {
        return $key;
    }
    $key = str_replace(array("\r", "\n", ' ', "\t"), '', $key);
    $wrap = chunk_split($key, 64, "\n");
    if ($is_private) {
        return "-----BEGIN RSA PRIVATE KEY-----\n" . $wrap . "-----END RSA PRIVATE KEY-----\n";
    }
    return "-----BEGIN PUBLIC KEY-----\n" . $wrap . "-----END PUBLIC KEY-----\n";
}

function api_orders_alipay_rsa_sign($data, $private_key_str, $sign_type) {
    $data = (array) $data;
    unset($data['sign']);
    ksort($data);
    $sign_content = '';
    foreach ($data as $k => $v) {
        if ($v === null || $v === '') {
            continue;
        }
        $sign_content .= $k . '=' . (string) $v . '&';
    }
    if ($sign_content !== '') {
        $sign_content = substr($sign_content, 0, -1);
    }
    $algo = ($sign_type === 'RSA') ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256;
    
    $pkey = openssl_pkey_get_private($private_key_str);
    if (!$pkey) {
        if (class_exists('Todo_Error_Handler')) {
            Todo_Error_Handler::log("ALIPAY_SIGN_ERROR: openssl_pkey_get_private failed.", Todo_Error_Handler::LEVEL_ERROR);
        }
        return '';
    }
    
    $signature = '';
    $ok = openssl_sign($sign_content, $signature, $pkey, $algo);
    if (!$ok) {
        if (class_exists('Todo_Error_Handler')) {
            Todo_Error_Handler::log("ALIPAY_SIGN_ERROR: openssl_sign failed.", Todo_Error_Handler::LEVEL_ERROR);
        }
        return '';
    }
    $final_sign = base64_encode($signature);

    if (class_exists('Todo_Error_Handler')) {
        Todo_Error_Handler::log("ALIPAY_SIGN_DEBUG: Raw string to sign:\n" . $sign_content . "\nGenerated Sign: " . $final_sign, Todo_Error_Handler::LEVEL_INFO);
    }

    return $final_sign;
}

function api_orders_alipay_direct_request_wap($order_no, $total_amount_yuan, $subject, $notify_url, $return_url) {
    $opts = function_exists('todo_get_option') ? todo_get_option('todo_payment_settings', array()) : array();
    $alipay = isset($opts['alipay']) && is_array($opts['alipay']) ? $opts['alipay'] : array();
    if (empty($alipay['enabled'])) {
        return array('success' => false, 'message' => '支付宝未启用');
    }
    $app_id = isset($alipay['app_id']) ? (string) $alipay['app_id'] : '';
    $private_key = isset($alipay['private_key']) ? (string) $alipay['private_key'] : '';
    if ($app_id === '' || $private_key === '') {
        return array('success' => false, 'message' => '支付宝配置不完整');
    }
    $private_key = api_orders_alipay_normalize_key($private_key, true);
    if ($private_key === '') {
        return array('success' => false, 'message' => '支付宝私钥无效');
    }

    $biz = array(
        'subject' => (string) $subject,
        'out_trade_no' => (string) $order_no,
        'total_amount' => number_format((float) $total_amount_yuan, 2, '.', ''),
        'product_code' => 'QUICK_WAP_WAY',
    );

    $params = array(
        'app_id' => $app_id,
        'method' => 'alipay.trade.wap.pay',
        'format' => 'JSON',
        'charset' => 'utf-8',
        'sign_type' => 'RSA2',
        'timestamp' => date('Y-m-d H:i:s'),
        'version' => '1.0',
        'notify_url' => (string) $notify_url,
        'return_url' => (string) $return_url,
        'biz_content' => json_encode($biz, JSON_UNESCAPED_UNICODE),
    );

    $sign = api_orders_alipay_rsa_sign($params, $private_key, 'RSA2');
    if ($sign === '') {
        return array('success' => false, 'message' => '支付宝签名失败');
    }
    $params['sign'] = $sign;
    $url = 'https://openapi.alipay.com/gateway.do?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    if (class_exists('Todo_Error_Handler')) {
        Todo_Error_Handler::log("ALIPAY_WAP_REQUEST_URL: " . $url, Todo_Error_Handler::LEVEL_INFO);
    }

    return array('success' => true, 'url' => $url);
}

function api_orders_apply_coupon($user_id, $coupon_id, $subtotal, $items = []) {
    global $tododb;
    $user_id = (int) $user_id;
    $coupon_id = (int) $coupon_id;
    $subtotal = (float) $subtotal;

    if ($user_id <= 0 || $coupon_id <= 0 || $subtotal <= 0) {
        return ['discount' => 0.0, 'user_coupon_id' => 0];
    }
    if (function_exists('api_coupon_ensure_schema')) {
        api_coupon_ensure_schema();
    }

    $coupons_table = $tododb->prefix . 'coupons';
    $user_coupons_table = $tododb->prefix . 'user_coupons';

    $uc = $tododb->get_row(
        "SELECT uc.id AS user_coupon_id, uc.status AS uc_status, c.*
         FROM {$user_coupons_table} uc
         INNER JOIN {$coupons_table} c ON c.id = uc.coupon_id
         WHERE uc.user_id = ? AND uc.coupon_id = ? LIMIT 1",
        [$user_id, $coupon_id],
        'ARRAY_A'
    );
    if (!$uc) {
        return ['discount' => 0.0, 'user_coupon_id' => 0];
    }
    if (isset($uc['uc_status']) && (string) $uc['uc_status'] !== 'unused') {
        return ['discount' => 0.0, 'user_coupon_id' => 0];
    }
    if (isset($uc['status']) && (int) $uc['status'] !== 1) {
        return ['discount' => 0.0, 'user_coupon_id' => 0];
    }

    $now_ts = (int) current_time('timestamp');
    
    // 检查有效期（包含活动有效期闭环检查）
    if (function_exists('api_coupon_is_started') && !api_coupon_is_started($uc, $now_ts)) {
        return ['discount' => 0.0, 'user_coupon_id' => 0];
    }
    if (function_exists('api_coupon_is_expired') && api_coupon_is_expired($uc, $now_ts)) {
        return ['discount' => 0.0, 'user_coupon_id' => 0];
    }

    // 默认有效期检查（作为兜底）
    if (!function_exists('api_coupon_is_started')) {
        if (isset($uc['start_date']) && is_string($uc['start_date']) && trim($uc['start_date']) !== '') {
            $ts = strtotime((string) $uc['start_date']);
            if ($ts !== false && $ts > $now_ts) {
                return ['discount' => 0.0, 'user_coupon_id' => 0];
            }
        }
        if (isset($uc['end_date']) && is_string($uc['end_date']) && trim($uc['end_date']) !== '') {
            $ts = strtotime((string) $uc['end_date']);
            if ($ts !== false && $ts < $now_ts) {
                return ['discount' => 0.0, 'user_coupon_id' => 0];
            }
        }
    }

    // 检查活动分类限制
    $activity_id = isset($uc['activity_id']) ? (int) $uc['activity_id'] : 0;
    $effective_subtotal = $subtotal;
    if ($activity_id > 0 && !empty($items)) {
        $eligible_subtotal = 0;
        $category_ids = [$activity_id];
        
        // 获取该活动分类的所有子分类
        if (class_exists('Todo_Category_Manager')) {
            Todo_Category_Manager::init();
            $children = Todo_Category_Manager::get_children($activity_id, true);
            if (!empty($children)) {
                foreach ($children as $child) {
                    $category_ids[] = (int) ($child['id'] ?? $child['term_id']);
                }
            }
        }
        
        foreach ($items as $item) {
            $pid = intval($item['product_id'] ?? 0);
            if ($pid <= 0) continue;
            
            $product_categories = [];
            if (function_exists('get_the_terms')) {
                $terms = get_the_terms($pid, 'category');
                if ($terms && is_array($terms)) {
                    foreach ($terms as $t) {
                        $product_categories[] = (int)$t->term_id;
                    }
                }
            }
            
            // 如果商品属于指定分类或其子分类，则计入合格金额
            if (!empty(array_intersect($product_categories, $category_ids))) {
                $eligible_subtotal += (float)($item['price'] * $item['quantity']);
            }
        }
        $effective_subtotal = $eligible_subtotal;
    }

    $min_amount = isset($uc['min_amount']) ? (float) $uc['min_amount'] : 0.0;
    if ($min_amount > 0 && $effective_subtotal < $min_amount) {
        return ['discount' => 0.0, 'user_coupon_id' => 0];
    }

    $type = isset($uc['type']) ? (int) $uc['type'] : 1;
    $value = isset($uc['value']) ? (float) $uc['value'] : 0.0;
    if ($value <= 0) {
        return ['discount' => 0.0, 'user_coupon_id' => 0];
    }

    $discount = 0.0;
    if ($type === 2) { // 百分比
        $discount = $effective_subtotal * ($value / 100.0);
        $max_discount = isset($uc['max_discount']) && $uc['max_discount'] !== null ? (float) $uc['max_discount'] : 0.0;
        if ($max_discount > 0 && $discount > $max_discount) {
            $discount = $max_discount;
        }
    } else { // 固定金额
        $discount = $value;
    }

    if ($discount < 0) {
        $discount = 0.0;
    }
    
    // 优惠金额不能超过合格商品的总额
    if ($discount > $effective_subtotal) {
        $discount = $effective_subtotal;
    }

    return [
        'discount' => round($discount, 2),
        'user_coupon_id' => isset($uc['user_coupon_id']) ? (int) $uc['user_coupon_id'] : 0,
    ];
}

/**
 * 获取订单列表
 */
function api_orders_enrich_items($items) {
    global $tododb;
    if (!is_array($items)) return [];
    
    $fields_table = TABLE_PREFIX . 'model_fields_product';
    $posts_table = TABLE_PREFIX . 'posts';
    
    $api_base = get_option('todo_api_base_url', '');
    if (empty($api_base)) $api_base = site_url('/api/');
    $site_root = rtrim(str_replace('/api', '', $api_base), '/');

    return array_map(function($item) use ($tododb, $fields_table, $posts_table, $site_root) {
        $item_data = (array)$item;
        $pid = intval($item_data['product_id'] ?? 0);
        
        // 补全商品基本信息（针对旧订单）
        if ($pid > 0 && (empty($item_data['product_name']) || empty($item_data['product_image']))) {
            $product = $tododb->get_row("SELECT post_title, featured_image FROM {$posts_table} WHERE id = ?", [$pid]);
            if ($product) {
                if (empty($item_data['product_name'])) $item_data['product_name'] = $product->post_title;
                if (empty($item_data['product_image'])) $item_data['product_image'] = $product->featured_image;
            }
        }

        if (isset($item_data['spec_name'])) {
            $item_data['spec_name'] = Todo_Order_Manager::format_spec_name($item_data['spec_name']);
        }

        // 统一处理图片 URL（使用 Rule 1 + 3 前端解析函数）
        if (!empty($item_data['product_image'])) {
            if (function_exists('todo_media_url_to_runtime_absolute')) {
                $item_data['product_image'] = todo_media_url_to_runtime_absolute($item_data['product_image']);
            } elseif (function_exists('todo_media_url_to_absolute')) {
                $item_data['product_image'] = todo_media_url_to_absolute($item_data['product_image']);
            }
        }

        $item_data['price'] = api_orders_convert_amount_to_display(floatval($item_data['price'] ?? 0));
        $item_data['total_price'] = api_orders_convert_amount_to_display(floatval($item_data['total_price'] ?? 0));
        $item_data['specs'] = null;
        $s_idx = isset($item_data['spec_id']) ? intval($item_data['spec_id']) : -1;
        if ($s_idx >= 0) {
            $p_fields = $tododb->get_row("SELECT spec_details FROM {$fields_table} WHERE post_id = ?", [$pid]);
            if ($p_fields && $p_fields->spec_details) {
                $details = json_decode($p_fields->spec_details, true);
                if (isset($details[$s_idx])) {
                    $specs = [];
                    foreach ($details[$s_idx] as $k => $v) {
                        if (!in_array($k, ['price', 'wholesale_price', 'wholesale_vip_price', 'users_price', 'original_price', 'stock', 'sku'])) {
                            $specs[$k] = $v;
                        }
                    }
                    $item_data['specs'] = $specs;
                }
            }
        }
        $item_data['category_ids'] = [];
        if ($pid > 0 && function_exists('get_the_terms')) {
            $terms = get_the_terms($pid, 'category');
            if ($terms && is_array($terms)) {
                foreach ($terms as $t) {
                    $item_data['category_ids'][] = (int)$t->term_id;
                }
            }
        }
        return $item_data;
    }, $items);
}

function api_get_orders($params) {
    global $tododb;

    $user_id = get_current_user_id();
    if (!$user_id) {
        send_api_error(401, '未登录');
    }

    $status = isset($params['status']) ? sanitize_text_field((string) $params['status']) : '';
    $payment_status = isset($params['payment_status']) ? sanitize_text_field((string) $params['payment_status']) : '';
    $shipping_status = isset($params['shipping_status']) ? sanitize_text_field((string) $params['shipping_status']) : '';
    $aftersale = !empty($params['aftersale']);

    $page = isset($params['page']) ? max(1, intval($params['page'])) : 1;
    $per_page = isset($params['per_page']) ? max(1, min(100, intval($params['per_page']))) : 10;
    $offset = ($page - 1) * $per_page;

    $orders_table = TABLE_PREFIX . 'orders';
    $order_items_table = TABLE_PREFIX . 'order_items';

    $where = "user_id = ?";
    $args = [$user_id];

    if ($aftersale) {
        $where .= " AND payment_status IN ('refunding','refunded')";
    } else {
        if ($status !== '') {
            $where .= " AND status = ?";
            $args[] = $status;
        }
        if ($payment_status !== '') {
            $where .= " AND payment_status = ?";
            $args[] = $payment_status;
        }
        if ($shipping_status !== '') {
            $where .= " AND shipping_status = ?";
            $args[] = $shipping_status;
        }
    }

    $query = "SELECT * FROM $orders_table WHERE {$where} ORDER BY created_at DESC LIMIT $per_page OFFSET $offset";
    $orders = $tododb->get_results($query, $args);

    $result = [];
    foreach ($orders as $order) {
        $order_id = intval($order->id);
        
        // 获取订单项目
        $items_query = "SELECT * FROM $order_items_table WHERE order_id = ?";
        $items = $tododb->get_results($items_query, [$order_id]);
        
        $aftersale_status = '';
        if ($order->payment_status === 'refunding') {
            $aftersale_status = 'refunding';
        } elseif ($order->payment_status === 'refunded') {
            $aftersale_status = 'refunded';
        }

        $order_data = [
            'id' => $order_id,
            'order_no' => $order->order_no,
            'total_amount' => api_orders_convert_amount_to_display(floatval($order->total_amount)),
            'payment_amount' => isset($order->payment_amount) ? api_orders_convert_amount_to_display(floatval($order->payment_amount)) : 0,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'shipping_status' => isset($order->shipping_status) ? $order->shipping_status : '',
            'aftersale_status' => $aftersale_status,
            'created_at' => $order->created_at,
            'items' => api_orders_enrich_items($items)
        ];
        
        $result[] = $order_data;
    }

    return [
        'orders' => $result,
        'page' => $page,
        'per_page' => $per_page
    ];
}

function api_get_order_detail($params) {
    global $tododb;

    $user_id = get_current_user_id();
    if (!$user_id) {
        send_api_error(401, '未登录');
    }

    $order_id = isset($params['id']) ? intval($params['id']) : 0;
    if ($order_id <= 0) {
        send_api_error(400, '缺少订单ID');
    }

    $orders_table = TABLE_PREFIX . 'orders';
    $order_items_table = TABLE_PREFIX . 'order_items';

    $order = $tododb->get_row("SELECT * FROM {$orders_table} WHERE id = ? AND user_id = ? LIMIT 1", [$order_id, $user_id]);
    if (!$order) {
        send_api_error(404, '订单不存在');
    }

    $items = $tododb->get_results("SELECT * FROM {$order_items_table} WHERE order_id = ? ORDER BY id ASC", [$order_id]);

    $aftersale_status = '';
    if (isset($order->payment_status) && $order->payment_status === 'refunding') {
        $aftersale_status = 'refunding';
    } elseif (isset($order->payment_status) && $order->payment_status === 'refunded') {
        $aftersale_status = 'refunded';
    }

    $result = [
        'id' => intval($order->id),
        'order_no' => isset($order->order_no) ? (string) $order->order_no : '',
        'user_id' => intval($order->user_id),
        'total_amount' => isset($order->total_amount) ? api_orders_convert_amount_to_display(floatval($order->total_amount)) : 0,
        'payment_amount' => isset($order->payment_amount) ? api_orders_convert_amount_to_display(floatval($order->payment_amount)) : 0,
        'shipping_fee' => isset($order->shipping_fee) ? api_orders_convert_amount_to_display(floatval($order->shipping_fee)) : 0,
        'discount_amount' => isset($order->discount_amount) ? api_orders_convert_amount_to_display(floatval($order->discount_amount)) : 0,
        'status' => isset($order->status) ? (string) $order->status : '',
        'payment_status' => isset($order->payment_status) ? (string) $order->payment_status : '',
        'shipping_status' => isset($order->shipping_status) ? (string) $order->shipping_status : '',
        'payment_method' => isset($order->payment_method) ? (string) $order->payment_method : '',
        'shipping_method' => isset($order->shipping_method) ? (string) $order->shipping_method : '',
        'shipping_address' => isset($order->shipping_address) ? (string) $order->shipping_address : '',
        'shipping_info' => isset($order->shipping_info) ? (string) $order->shipping_info : '',
        'remark' => isset($order->remark) ? (string) $order->remark : '',
        'paid_at' => isset($order->paid_at) ? $order->paid_at : null,
        'shipped_at' => isset($order->shipped_at) ? $order->shipped_at : null,
        'completed_at' => isset($order->completed_at) ? $order->completed_at : null,
        'created_at' => isset($order->created_at) ? (string) $order->created_at : '',
        'updated_at' => isset($order->updated_at) ? (string) $order->updated_at : '',
        'points_used' => isset($order->points_used) ? intval($order->points_used) : 0,
        'points_discount' => isset($order->points_discount) ? api_orders_convert_amount_to_display(floatval($order->points_discount)) : 0,
        'aftersale_status' => $aftersale_status,
        'items' => api_orders_enrich_items($items)
    ];

    return [
        'order' => $result
    ];
}

/**
 * 提交订单
 */
function api_submit_order($params) {
    global $tododb;

    $user_id = get_current_user_id();
    if (!$user_id) {
        send_api_error(401, '未登录');
    }

    if (!isset($params['items']) || !is_array($params['items'])) {
        send_api_error(400, '缺少订单项目');
    }

    $user_level = get_user_meta($user_id, 'user_level', true) ?: 'retail';
    $is_wholesale = strpos($user_level, 'wholesale') === 0;

    $orders_table = TABLE_PREFIX . 'orders';
    $order_items_table = TABLE_PREFIX . 'order_items';
    $fields_table = TABLE_PREFIX . 'model_fields_product';

    $items_total = 0;
    $order_items_data = [];
    $shipping_method = api_orders_normalize_shipping_method($params['shipping_method'] ?? '');
    $payment_method = isset($params['payment_method']) ? sanitize_text_field((string) $params['payment_method']) : '';
    $coupon_id = isset($params['coupon_id']) ? (int) $params['coupon_id'] : 0;
    $shipping_address = '';
    $requires_shipping = false;

    if ($shipping_method === '') {
        send_api_error(400, '请选择有效的配送方式');
    }

    if ($payment_method !== '' && !api_orders_is_payment_method_selected($payment_method)) {
        send_api_error(400, '支付方式未开放');
    }

    $tododb->begin_transaction();
    try {
        foreach ($params['items'] as $item) {
            $product_id = intval($item['product_id']);
            $quantity = intval($item['quantity']);
            if ($quantity <= 0) {
                throw new Exception('数量不正确');
            }

            $specs_arr = null;
            if (isset($item['specs']) && $item['specs'] !== null && $item['specs'] !== '') {
                if (is_string($item['specs'])) {
                    $decoded = json_decode($item['specs'], true);
                    if (is_array($decoded)) {
                        $specs_arr = $decoded;
                    }
                } elseif (is_array($item['specs'])) {
                    $specs_arr = $item['specs'];
                }
            }
            if (is_array($specs_arr)) {
                $specs_arr = api_orders_normalize_specs_array($specs_arr);
                if (!is_array($specs_arr)) {
                    throw new Exception('规格格式无效');
                }
            }

            $product = api_orders_get_product_for_purchase($product_id);
            if (!$product) {
                throw new Exception('商品已下架');
            }
            if (($product->product_type ?? 'physical') !== 'virtual') {
                $requires_shipping = true;
            }

            $price = 0;
            $spec_name = '';
            $spec_id = -1;
            if ($product->spec_type === 'multiple') {
                if (!is_array($specs_arr)) {
                    throw new Exception('缺少规格');
                }
                $details = json_decode($product->spec_details ?? '', true);
                $matched = api_orders_match_spec_detail($details, $specs_arr);
                if (!$matched) {
                    throw new Exception('规格不存在');
                }
                $idx = $matched[0];
                $spec_id = $idx;
                $detail = $matched[1];
                // 兼容 stock（旧字段）和 stock_count（统一库存字段名）
                $spec_stock = isset($detail['stock']) ? intval($detail['stock']) : intval($detail['stock_count'] ?? -1);
                if ($spec_stock >= 0) {
                    // 有明确的规格级库存
                    if ($spec_stock < $quantity) {
                        throw new Exception('库存不足');
                    }
                } else {
                    // 无规格级库存，回退到产品主库存
                    if (intval($product->stock ?? 0) < $quantity) {
                        throw new Exception('库存不足');
                    }
                }

                $base_p = floatval($detail['users_price'] ?? ($detail['price'] ?? 0));
                $candidates = array();
                if (in_array($user_level, ['wholesale_vip', 'senior_vip'], true)) {
                    $tp = floatval($detail['wholesale_vip_price'] ?? ($detail['wholesale_price'] ?? 0));
                    if ($tp > 0) $candidates[] = $tp;
                } elseif (in_array($user_level, ['wholesale', 'vip'], true)) {
                    $tp = floatval($detail['wholesale_price'] ?? 0);
                    if ($tp > 0) $candidates[] = $tp;
                }
                $mem_p = function_exists('todo_get_product_member_price') ? todo_get_product_member_price($product_id, $base_p, $user_id) : $base_p;
                if ($mem_p > 0 && $mem_p < $base_p) {
                    $candidates[] = $mem_p;
                }
                $price = !empty($candidates) ? min($candidates) : $base_p;

                if (function_exists('todo_format_specs_text')) {
                    $spec_name = todo_format_specs_text($specs_arr, $product_id);
                } else {
                    uksort($specs_arr, function($a, $b) {
                        $am = []; $bm = [];
                        preg_match('/^v(\d+)$/', $a, $am);
                        preg_match('/^v(\d+)$/', $b, $bm);
                        if ($am && $bm) return (int)$am[1] - (int)$bm[1];
                        return strcmp($a, $b);
                    });
                    $spec_name = implode(' / ', array_values($specs_arr));
                }

                // 扣减规格级库存（兼容 stock 和 stock_count 两种字段名）
                if (is_array($details) && isset($details[$idx]) && is_array($details[$idx])) {
                    api_orders_adjust_spec_detail_stock($product_id, $idx, -$quantity, $fields_table, (string) ($product->spec_details ?? ''));
                }
            } else {
                $base_p = floatval($product->price);
                $candidates = array();
                if (in_array($user_level, ['wholesale_vip', 'senior_vip'], true)) {
                    $tp = floatval($product->wholesale_vip_price > 0 ? $product->wholesale_vip_price : $product->wholesale_price);
                    if ($tp > 0) $candidates[] = $tp;
                } elseif (in_array($user_level, ['wholesale', 'vip'], true)) {
                    $tp = floatval($product->wholesale_price);
                    if ($tp > 0) $candidates[] = $tp;
                }
                $mem_p = function_exists('todo_get_product_member_price') ? todo_get_product_member_price($product_id, $base_p, $user_id) : $base_p;
                if ($mem_p > 0 && $mem_p < $base_p) {
                    $candidates[] = $mem_p;
                }
                $price = !empty($candidates) ? min($candidates) : $base_p;
            }

            if ($product->spec_type === 'multiple' && intval($product->stock ?? 0) <= 0) {
                api_orders_adjust_product_inventory($product_id, 0, $quantity, $fields_table, false);
            } else {
                api_orders_adjust_product_inventory($product_id, -$quantity, $quantity, $fields_table, true);
            }

            $items_total += $price * $quantity;

            // 从 featured_image JSON 数组中提取实际图片 URL
            $raw_image = $product->featured_image ?? '';
            $product_image_url = '';
            if (!empty($raw_image)) {
                $img_decoded = json_decode($raw_image, true);
                if (is_array($img_decoded) && !empty($img_decoded[0]['url'])) {
                    $product_image_url = $img_decoded[0]['url'];
                } else {
                    $product_image_url = $raw_image; // 兼容纯 URL 格式
                }
            }

            $order_items_data[] = [
                'product_id'   => $product_id,
                'product_name' => $product->post_title,
                'product_image'=> $product_image_url,
                'price'        => $price,
                'quantity'     => $quantity,
                'total_price'  => $price * $quantity,
                'spec_name'    => $spec_name,
                'spec_id'      => $spec_id,
                // 注意：user_type 不是 order_items 表的列，已移除
            ];
        }

        if (empty($order_items_data)) {
            throw new Exception('订单项目为空');
        }

        if ($requires_shipping && $shipping_method === 'delivery') {
            $shipping_address = api_orders_validate_shipping_address($params['address'] ?? '');
        } elseif (!$requires_shipping) {
            $shipping_address = '';
        }

        $shipping_fee = 0.0;
        if ($requires_shipping && $shipping_method !== 'pickup' && $items_total > 0) {
            $shipping_fee = $items_total > 50 ? 0.0 : 5.0;
        }

        $coupon_apply = api_orders_apply_coupon($user_id, $coupon_id, $items_total, $order_items_data);
        $discount_amount = isset($coupon_apply['discount']) ? (float) $coupon_apply['discount'] : 0.0;
        $user_coupon_id = isset($coupon_apply['user_coupon_id']) ? (int) $coupon_apply['user_coupon_id'] : 0;

        $points_used = isset($params['points_used']) ? intval($params['points_used']) : 0;
        $points_discount = 0.0;
        $points_mgr = null;
        if ($points_used > 0) {
            if (!class_exists('Points_Manager')) {
                throw new Exception('积分系统未启用');
            }
            $points_mgr = Points_Manager::get_instance();
            $user_points_balance = $points_mgr->get_balance($user_id);
            $orders_table = DB_PREFIX . 'orders';
            $ocf_table = DB_PREFIX . 'order_custom_fields';
            $sql_locked = $tododb->prepare(
                "SELECT SUM(CAST(ocf1.field_value AS UNSIGNED)) FROM {$ocf_table} ocf1
                 INNER JOIN {$orders_table} o ON ocf1.order_id = o.id
                 LEFT JOIN {$ocf_table} ocf2 ON ocf2.order_id = o.id AND ocf2.field_name = '_points_deducted'
                 WHERE o.user_id = %d
                   AND o.status IN ('pending', 'processing')
                   AND ocf1.field_name = '_points_used'
                   AND (ocf2.field_value IS NULL OR ocf2.field_value != '1')",
                $user_id
            );
            $locked_points = (int) $tododb->get_var($sql_locked);
            $available_pts = max(0, $user_points_balance - $locked_points);
            if ($available_pts < $points_used) {
                throw new Exception('可用积分不足（包含待支付订单冻结积分）');
            }
            $points_ratio = (int) (function_exists('get_option') ? get_option('points_to_currency_ratio', 100) : 100);
            if ($points_ratio <= 0) {
                $points_ratio = 100;
            }
            $eligible_subtotal = api_orders_get_points_eligible_subtotal($order_items_data);
            $remaining_eligible_amount = max(0.0, $eligible_subtotal - max(0.0, $discount_amount));
            $max_points_possible = (int) floor($remaining_eligible_amount * $points_ratio);
            if ($max_points_possible <= 0) {
                $points_used = 0;
            } else {
                $points_used = min($points_used, $max_points_possible);
            }
            $points_discount = round($points_used / $points_ratio, 2);
        }

        $total_amount = round(max(0.0, $items_total + $shipping_fee - $discount_amount - $points_discount), 2);

        $existing_order_id = isset($params['order_id']) ? intval($params['order_id']) : 0;
        if ($existing_order_id > 0) {
            $old_order = $tododb->get_row("SELECT * FROM {$orders_table} WHERE id = ? AND user_id = ? LIMIT 1", [$existing_order_id, $user_id]);
            if (!$old_order) {
                throw new Exception('原订单不存在');
            }
            if ($old_order->payment_status === 'paid') {
                throw new Exception('订单已支付，无法修改');
            }
            $order_no = $old_order->order_no;
            
            // 返回旧库存
            $old_items = $tododb->get_results("SELECT * FROM {$order_items_table} WHERE order_id = ?", [$existing_order_id]);
            foreach ($old_items as $old_item) {
                $pid = intval($old_item->product_id);
                $qty = intval($old_item->quantity);
                $s_idx = isset($old_item->spec_id) ? intval($old_item->spec_id) : -1;
                
                if ($s_idx >= 0) {
                    $p_fields = $tododb->get_row("SELECT stock, spec_details FROM {$fields_table} WHERE post_id = ?", [$pid]);
                    api_orders_adjust_spec_detail_stock($pid, $s_idx, $qty, $fields_table, isset($p_fields->spec_details) ? (string) $p_fields->spec_details : null);
                    $product_stock = isset($p_fields->stock) ? intval($p_fields->stock) : 0;
                    if ($product_stock > 0) {
                        api_orders_adjust_product_inventory($pid, $qty, -$qty, $fields_table, false);
                    } else {
                        api_orders_adjust_product_inventory($pid, 0, -$qty, $fields_table, false);
                    }
                } else {
                    api_orders_adjust_product_inventory($pid, $qty, -$qty, $fields_table, false);
                }
            }
            // 删除旧项目
            $tododb->query("DELETE FROM {$order_items_table} WHERE order_id = ?", [$existing_order_id]);
            
            $order_id = $existing_order_id;
            $order_data = [
                'total_amount' => $total_amount,
                'shipping_fee' => $shipping_fee,
                'discount_amount' => $discount_amount,
                'payment_method' => $payment_method,
                'shipping_method' => $shipping_method,
                'shipping_address' => $shipping_address,
                'remark' => isset($params['remark']) ? $params['remark'] : '',
                'updated_at' => current_time('mysql')
            ];
            if ($points_used > 0) {
                $order_data['points_used'] = $points_used;
                $order_data['points_discount'] = $points_discount;
            } else {
                $order_data['points_used'] = 0;
                $order_data['points_discount'] = 0;
            }
            
            $tododb->update($orders_table, $order_data, ['id' => $order_id]);
        } else {
            // 创建新订单
            $req_lang = $params['lang'] ?? ($params['language'] ?? ($params['locale'] ?? ''));
            if (empty($req_lang) && function_exists('todo_get_current_language')) {
                $req_lang = todo_get_current_language();
            }
            $order_no = class_exists('Todo_Order_Manager') ? Todo_Order_Manager::generate_order_no($req_lang) : date('YmdHis') . rand(1000, 9999);
            $order_data = [
                'order_no' => $order_no,
                'user_id' => $user_id,
                'total_amount' => $total_amount,
                'payment_amount' => 0,
                'status' => 'pending',
                'payment_status' => 'unpaid',
                'shipping_status' => 'unshipped',
                'shipping_fee' => $shipping_fee,
                'discount_amount' => $discount_amount,
                'payment_method' => $payment_method,
                'shipping_method' => $shipping_method,
                'shipping_address' => $shipping_address,
                'remark' => isset($params['remark']) ? $params['remark'] : '',
                'created_at' => current_time('mysql')
            ];
            
            if ($points_used > 0) {
                $order_data['points_used'] = $points_used;
                $order_data['points_discount'] = $points_discount;
            }

            if ($is_wholesale) {
                $order_data['payment_method'] = 'cod';
            }

            $tododb->insert($orders_table, $order_data);
            $order_id = $tododb->insert_id();

            if ($order_id > 0 && class_exists('Todo_Order_Manager') && !empty($req_lang)) {
                Todo_Order_Manager::save_order_custom_field($order_id, '_order_lang', $req_lang);
            }
        }

        // 1. 立即插入订单项目 (确保订单记录完整)
        foreach ($order_items_data as $item_data) {
            $item_data['order_id'] = $order_id;
            $tododb->insert($order_items_table, $item_data);
        }

        if ($points_used > 0 && class_exists('Todo_Order_Manager')) {
            Todo_Order_Manager::save_order_custom_field($order_id, '_points_used', $points_used);
            Todo_Order_Manager::save_order_custom_field($order_id, '_points_discount_amount', $points_discount);
        }

        // 2. 更新优惠券使用状态
        if ($user_coupon_id > 0 && $discount_amount > 0) {
            $user_coupons_table = $tododb->prefix . 'user_coupons';
            $tododb->update($user_coupons_table, [
                'status' => 'used',
                'used_at' => current_time('mysql'),
                'order_id' => $order_id,
                'updated_at' => current_time('mysql'),
            ], [
                'id' => $user_coupon_id,
                'user_id' => $user_id,
            ]);
        }

        $balance_error = '';
        // 3. 处理余额支付
        if ($payment_method === 'balance' && $total_amount > 0) {
            if (!todo_check_user_balance($user_id, $total_amount)) {
                // 余额不足时不抛出异常，允许订单以“待支付”状态生成
                $balance_error = '余额不足，订单已转为待支付状态';
            } else {
                $deducted = todo_core_deduct_user_balance($user_id, $total_amount, 'order_pay', __('订单支付') . ': ' . $order_no, $order_id);
                if (!$deducted) {
                    throw new Exception('余额扣减失败');
                }
                // 更新订单为已支付
                $tododb->update($orders_table, [
                    'status' => 'paid',
                    'payment_status' => 'paid',
                    'payment_amount' => $total_amount,
                    'paid_at' => current_time('mysql')
                ], ['id' => $order_id]);
                
                $order_data['status'] = 'paid';
                $order_data['payment_status'] = 'paid';
                $order_data['payment_amount'] = $total_amount;
            }
        }

        // 清空购物车
        if (isset($params['clear_cart']) && $params['clear_cart']) {
            $tododb->query("DELETE FROM " . TABLE_PREFIX . "cart WHERE user_id = ?", [$user_id]);
        }

        $tododb->commit();

        // Actual deduction after success if order paid instantly (e.g. balance payment)
        if ($points_used > 0 && ($order_data['payment_status'] ?? '') === 'paid') {
            $pts_deducted = $points_mgr->deduct_points($user_id, $points_used, 'order_deduction', "订单 {$order_no} 抵扣");
            if ($pts_deducted && class_exists('Todo_Order_Manager')) {
                Todo_Order_Manager::save_order_custom_field($order_id, '_points_deducted', '1');
            }
        }

        $resp = [
            'id' => $order_id,
            'order_no' => $order_no,
            'total_amount' => api_orders_convert_amount_to_display($total_amount),
            'items_total' => api_orders_convert_amount_to_display(round($items_total, 2)),
            'shipping_fee' => api_orders_convert_amount_to_display(round($shipping_fee, 2)),
            'discount_amount' => api_orders_convert_amount_to_display(round($discount_amount, 2)),
            'points_used' => (int) $points_used,
            'points_discount' => api_orders_convert_amount_to_display(round((float) $points_discount, 2)),
            'payment_method' => $order_data['payment_method'],
            'shipping_method' => $order_data['shipping_method'],
            'message' => $balance_error !== '' ? $balance_error : '订单已提交',
            'payment_error' => $balance_error !== ''
        ];

        // AB 跳转支付拦截 (A买B付)
        $ab_secret = function_exists('todo_get_option') ? todo_get_option('ab_payment_shared_secret', '') : '';
        $ab_is_b = function_exists('todo_get_option') ? todo_get_option('ab_payment_is_b_site', false) : false;
        
        if ($ab_secret && $ab_secret !== 'YOUR_DEFAULT_SECRET_KEY_REPLACE_ME' && !$ab_is_b && ($payment_method === 'wechat' || $payment_method === 'alipay' || $payment_method === 'paypal') && $total_amount > 0) {
            
            $is_ab_allowed = class_exists('Todo_AB_Payment_API') ? Todo_AB_Payment_API::is_order_in_whitelist($order_id) : true;
            
            if ($is_ab_allowed && class_exists('Todo_AB_Payment_API')) {
                if (class_exists('Todo_Error_Handler')) {
                    Todo_Error_Handler::log("AB_PAYMENT [A-Site] Intercepting order {$order_no} for AB jump payment ({$payment_method}, amount: {$total_amount})", Todo_Error_Handler::LEVEL_INFO);
                }
                
                $ab_api = new Todo_AB_Payment_API();
                $b_site_urls = todo_get_option('ab_payment_b_site_urls', ''); // 配置B站网址
                if (!empty($b_site_urls)) {
                    $b_site_array = array_filter(array_map('trim', explode(',', $b_site_urls)));
                    if (!empty($b_site_array)) {
                        $selected_b_site = $b_site_array[array_rand($b_site_array)]; // 简单轮询
                        
                        $full_order_obj = Todo_Order_Manager::get_order($order_id, true);
                        $b_req = $ab_api->build_payment_request_for_b_site($full_order_obj ?: [
                            'id' => $order_id,
                            'order_no' => $order_no,
                            'payment_amount' => $total_amount,
                            'payment_method' => $payment_method
                        ], $selected_b_site);
                        
                        $tododb->update($orders_table, [
                            'ab_order_type' => 'site_a',
                            'ab_b_site_url' => $selected_b_site
                        ], ['id' => $order_id]);
                        
                        if (class_exists('Todo_Error_Handler')) {
                            Todo_Error_Handler::log("AB_PAYMENT [A-Site] Formed jump payload for order {$order_no} -> B-Site: {$selected_b_site}", Todo_Error_Handler::LEVEL_INFO, ['payload' => $b_req]);
                        }
                        
                        $resp['pay'] = [
                            'provider' => $payment_method,
                            'type' => 'form_post',
                            'url' => $b_req['b_site_endpoint'],
                            'params' => $b_req
                        ];
                        return $resp;
                    } else if (class_exists('Todo_Error_Handler')) {
                        Todo_Error_Handler::log("AB_PAYMENT [A-Site] B-Site URL array is empty after filter", Todo_Error_Handler::LEVEL_WARNING);
                    }
                } else if (class_exists('Todo_Error_Handler')) {
                    Todo_Error_Handler::log("AB_PAYMENT [A-Site] B-Site URLs option is empty", Todo_Error_Handler::LEVEL_WARNING);
                }
            }
        }

        if ($payment_method === 'wechat' && !$is_wholesale && $total_amount > 0) {
            $provider = api_orders_get_payment_provider('wechat');
            $openid = api_orders_get_wechat_openid($user_id);
            if ($openid === '') {
                $resp['pay'] = [
                    'provider' => $provider !== '' ? $provider : 'payjs',
                    'type' => 'wechat',
                    'error' => '缺少openid，请使用微信登录后再支付',
                ];
                return $resp;
            }

            if (class_exists('Todo_Order_Manager')) {
                Todo_Order_Manager::save_order_custom_field((int) $order_id, 'payment_provider', $provider);
            }

            if ($provider === 'wechat_direct') {
                $notify_url = api_orders_build_notify_url_for('/api/payment/wechat/notify');
                $r = api_orders_wechat_direct_request_jsapi(
                    $order_no,
                    (int) round($total_amount * 100),
                    $openid,
                    '订单支付-' . $order_no,
                    $notify_url
                );
                if (!$r['success']) {
                    $resp['pay'] = ['provider' => 'wechat_direct', 'type' => 'wechat_jsapi', 'error' => $r['message']];
                    return $resp;
                }
                $resp['pay'] = ['provider' => 'wechat_direct', 'type' => 'wechat_jsapi', 'params' => $r['jsapi']];
                return $resp;
            }

            if ($provider === 'hupijiao') {
                $notify_url = api_orders_build_notify_url_for('/api/payment/hupijiao/notify');
                $return_url = api_orders_build_notify_url_for('/user/order-detail?id=' . (string) $order_id);
                $r = api_orders_hupijiao_request($order_no, $total_amount, '订单支付-' . $order_no, $notify_url, $return_url, (string) $order_id);
                if (!$r['success']) {
                    $resp['pay'] = ['provider' => 'hupijiao', 'type' => 'h5_redirect', 'error' => $r['message']];
                    return $resp;
                }
                $resp['pay'] = ['provider' => 'hupijiao', 'type' => 'h5_redirect', 'url' => $r['url']];
                return $resp;
            }

            $notify_url = api_orders_build_notify_url();
            $pay_result = api_orders_payjs_request_jsapi(
                $order_no,
                (int) round($total_amount * 100),
                $openid,
                '订单支付-' . $order_no,
                $notify_url,
                (string) $order_id
            );
            if (!$pay_result['success']) {
                $resp['pay'] = [
                    'provider' => 'payjs',
                    'type' => 'wechat_jsapi',
                    'error' => $pay_result['message'],
                ];
                return $resp;
            }
            $resp['pay'] = [
                'provider' => 'payjs',
                'type' => 'wechat_jsapi',
                'payjs_order_id' => $pay_result['payjs_order_id'],
                'params' => $pay_result['jsapi'],
            ];
        }
        if ($payment_method === 'alipay' && !$is_wholesale && $total_amount > 0) {
            $provider = api_orders_get_payment_provider('alipay');
            if (class_exists('Todo_Order_Manager')) {
                Todo_Order_Manager::save_order_custom_field((int) $order_id, 'payment_provider', $provider);
            }

            if ($provider === 'alipay_direct') {
                $notify_url = api_orders_build_notify_url_for('/api/payment/alipay/notify');
                $return_url = api_orders_build_notify_url_for('/user/order-detail?id=' . (string) $order_id);
                $r = api_orders_alipay_direct_request_wap($order_no, $total_amount, '订单支付-' . $order_no, $notify_url, $return_url);
                if (!$r['success']) {
                    $resp['pay'] = array('provider' => 'alipay_direct', 'type' => 'h5_redirect', 'error' => $r['message']);
                    return $resp;
                }
                $resp['pay'] = array('provider' => 'alipay_direct', 'type' => 'h5_redirect', 'url' => $r['url']);
                return $resp;
            }

            if ($provider === 'hupijiao') {
                $notify_url = api_orders_build_notify_url_for('/api/payment/hupijiao/notify');
                $return_url = api_orders_build_notify_url_for('/user/order-detail?id=' . (string) $order_id);
                $r = api_orders_hupijiao_request($order_no, $total_amount, '订单支付-' . $order_no, $notify_url, $return_url, (string) $order_id);
                if (!$r['success']) {
                    $resp['pay'] = array('provider' => 'hupijiao', 'type' => 'h5_redirect', 'error' => $r['message']);
                    return $resp;
                }
                $resp['pay'] = array('provider' => 'hupijiao', 'type' => 'h5_redirect', 'url' => $r['url']);
                return $resp;
            }

            $notify_url = api_orders_build_notify_url_for('/api/payment/payjs/notify');
            $callback_url = api_orders_build_notify_url_for('/user/order-detail?id=' . (string) $order_id);
            $r = api_orders_payjs_cashier_url($order_no, (int) round($total_amount * 100), '订单支付-' . $order_no, $notify_url, $callback_url, (string) $order_id);
            if (!$r['success']) {
                $resp['pay'] = array('provider' => 'payjs', 'type' => 'h5_redirect', 'error' => $r['message']);
                return $resp;
            }
            $resp['pay'] = array('provider' => 'payjs', 'type' => 'h5_redirect', 'url' => $r['url']);
            return $resp;
        }
        return $resp;
    } catch (Throwable $e) {
        $tododb->rollback();
        send_api_error(400, $e->getMessage());
    }
}

function api_orders_pay($params) {
    global $tododb;
    $user_id = get_current_user_id();
    if (!$user_id) {
        send_api_error(401, '未登录');
    }
    $order_id = isset($params['id']) ? (int) $params['id'] : 0;
    if ($order_id <= 0) {
        send_api_error(400, '缺少订单ID');
    }
    $payment_method = isset($params['payment_method']) ? sanitize_text_field((string) $params['payment_method']) : 'wechat';
    if (!api_orders_is_payment_method_selected($payment_method)) {
        send_api_error(400, '支付方式未开放');
    }

    $orders_table = TABLE_PREFIX . 'orders';
    $order = $tododb->get_row("SELECT * FROM {$orders_table} WHERE id = ? AND user_id = ? LIMIT 1", [$order_id, $user_id], 'ARRAY_A');
    if (!$order) {
        send_api_error(404, '订单不存在');
    }
    if (isset($order['payment_status']) && (string) $order['payment_status'] === 'paid') {
        return [
            'order_id' => $order_id,
            'order_no' => (string) ($order['order_no'] ?? ''),
            'paid' => true,
        ];
    }
    if ($payment_method !== 'wechat' && $payment_method !== 'alipay') {
        send_api_error(400, '暂不支持该支付方式');
    }

    $order_no = isset($order['order_no']) ? (string) $order['order_no'] : '';
    $total_amount = isset($order['total_amount']) ? (float) $order['total_amount'] : 0.0;
    if ($order_no === '' || $total_amount <= 0) {
        send_api_error(400, '订单金额不正确');
    }

    // AB 跳转支付拦截 (A买B付)
    $ab_secret = function_exists('todo_get_option') ? todo_get_option('ab_payment_shared_secret', '') : '';
    $ab_is_b = function_exists('todo_get_option') ? todo_get_option('ab_payment_is_b_site', false) : false;
    
    if ($ab_secret && $ab_secret !== 'YOUR_DEFAULT_SECRET_KEY_REPLACE_ME' && !$ab_is_b && ($payment_method === 'wechat' || $payment_method === 'alipay') && $total_amount > 0) {
        
        $is_ab_allowed = class_exists('Todo_AB_Payment_API') ? Todo_AB_Payment_API::is_order_in_whitelist($order_id) : true;
        
        if ($is_ab_allowed) {
            if (class_exists('Todo_AB_Payment_API')) {
                $ab_api = new Todo_AB_Payment_API();
                $b_site_urls = todo_get_option('ab_payment_b_site_urls', ''); // 配置B站网址
                if (!empty($b_site_urls)) {
                    $b_site_array = array_filter(array_map('trim', explode(',', $b_site_urls)));
                    if (!empty($b_site_array)) {
                        $selected_b_site = $b_site_array[array_rand($b_site_array)]; // 简单轮询
                        
                        $full_order_obj2 = Todo_Order_Manager::get_order($order_id, true);
                        $b_req = $ab_api->build_payment_request_for_b_site($full_order_obj2 ?: [
                            'id' => $order_id,
                            'order_no' => $order_no,
                            'payment_amount' => $total_amount,
                            'payment_method' => $payment_method
                        ], $selected_b_site);
                        
                        $tododb->update($orders_table, [
                            'ab_order_type' => 'site_a',
                            'ab_b_site_url' => $selected_b_site
                        ], ['id' => $order_id]);
                        
                        return [
                            'order_id' => $order_id,
                            'order_no' => $order_no,
                            'pay' => [
                                'provider' => $payment_method,
                                'type' => 'form_post',
                                'url' => $b_req['b_site_endpoint'],
                                'params' => $b_req
                            ]
                        ];
                    }
                }
            }
        }
    }

    if ($payment_method === 'wechat') {
        $provider = api_orders_get_payment_provider('wechat');
        $openid = api_orders_get_wechat_openid($user_id);
        if ($openid === '') {
            send_api_error(400, '缺少openid，请使用微信登录后再支付');
        }

        if (class_exists('Todo_Order_Manager')) {
            Todo_Order_Manager::save_order_custom_field((int) $order_id, 'payment_provider', $provider);
        }

        if ($provider === 'wechat_direct') {
            $notify_url = api_orders_build_notify_url_for('/api/payment/wechat/notify');
            $r = api_orders_wechat_direct_request_jsapi(
                $order_no,
                (int) round($total_amount * 100),
                $openid,
                '订单支付-' . $order_no,
                $notify_url
            );
            if (!$r['success']) {
                send_api_error(400, $r['message']);
            }
            return array(
                'order_id' => $order_id,
                'order_no' => $order_no,
                'pay' => array(
                    'provider' => 'wechat_direct',
                    'type' => 'wechat_jsapi',
                    'params' => $r['jsapi'],
                ),
            );
        }

        if ($provider === 'hupijiao') {
            $notify_url = api_orders_build_notify_url_for('/api/payment/hupijiao/notify');
            $return_url = api_orders_build_notify_url_for('/user/order-detail?id=' . (string) $order_id);
            $r = api_orders_hupijiao_request($order_no, $total_amount, '订单支付-' . $order_no, $notify_url, $return_url, (string) $order_id);
            if (!$r['success']) {
                send_api_error(400, $r['message']);
            }
            return array(
                'order_id' => $order_id,
                'order_no' => $order_no,
                'pay' => array(
                    'provider' => 'hupijiao',
                    'type' => 'h5_redirect',
                    'url' => $r['url'],
                ),
            );
        }

        $notify_url = api_orders_build_notify_url();
        $pay_result = api_orders_payjs_request_jsapi(
            $order_no,
            (int) round($total_amount * 100),
            $openid,
            '订单支付-' . $order_no,
            $notify_url,
            (string) $order_id
        );
        if (!$pay_result['success']) {
            send_api_error(400, $pay_result['message']);
        }
        return array(
            'order_id' => $order_id,
            'order_no' => $order_no,
            'pay' => array(
                'provider' => 'payjs',
                'type' => 'wechat_jsapi',
                'payjs_order_id' => $pay_result['payjs_order_id'],
                'params' => $pay_result['jsapi'],
            ),
        );
    }

    $provider = api_orders_get_payment_provider('alipay');

    if (class_exists('Todo_Order_Manager')) {
        Todo_Order_Manager::save_order_custom_field((int) $order_id, 'payment_provider', $provider);
    }

    if ($provider === 'alipay_direct') {
        $notify_url = api_orders_build_notify_url_for('/api/payment/alipay/notify');
        $return_url = api_orders_build_notify_url_for('/user/order-detail?id=' . (string) $order_id);
        $r = api_orders_alipay_direct_request_wap($order_no, $total_amount, '订单支付-' . $order_no, $notify_url, $return_url);
        if (!$r['success']) {
            send_api_error(400, $r['message']);
        }
        return array(
            'order_id' => $order_id,
            'order_no' => $order_no,
            'pay' => array(
                'provider' => 'alipay_direct',
                'type' => 'h5_redirect',
                'url' => $r['url'],
            ),
        );
    }

    if ($provider === 'hupijiao') {
        $notify_url = api_orders_build_notify_url_for('/api/payment/hupijiao/notify');
        $return_url = api_orders_build_notify_url_for('/user/order-detail?id=' . (string) $order_id);
        $r = api_orders_hupijiao_request($order_no, $total_amount, '订单支付-' . $order_no, $notify_url, $return_url, (string) $order_id);
        if (!$r['success']) {
            send_api_error(400, $r['message']);
        }
        return array(
            'order_id' => $order_id,
            'order_no' => $order_no,
            'pay' => array(
                'provider' => 'hupijiao',
                'type' => 'h5_redirect',
                'url' => $r['url'],
            ),
        );
    }

    $notify_url = api_orders_build_notify_url_for('/api/payment/payjs/notify');
    $callback_url = api_orders_build_notify_url_for('/user/order-detail?id=' . (string) $order_id);
    $r = api_orders_payjs_cashier_url($order_no, (int) round($total_amount * 100), '订单支付-' . $order_no, $notify_url, $callback_url, (string) $order_id);
    if (!$r['success']) {
        send_api_error(400, $r['message']);
    }
    return array(
        'order_id' => $order_id,
        'order_no' => $order_no,
        'pay' => array(
            'provider' => 'payjs',
            'type' => 'h5_redirect',
            'url' => $r['url'],
        ),
    );
}

function api_payment_payjs_notify($params) {
    header('Content-Type: text/plain; charset=utf-8');
    try {
        if (class_exists('Todo_Error_Handler')) {
            Todo_Error_Handler::log("PAYJS_API_NOTIFY [Received] Params: " . json_encode($params, JSON_UNESCAPED_UNICODE), Todo_Error_Handler::LEVEL_INFO);
        }

        $out_trade_no = isset($params['out_trade_no']) ? (string) $params['out_trade_no'] : '';
        $total_fee = isset($params['total_fee']) ? (int) $params['total_fee'] : 0;
        $sign = isset($params['sign']) ? (string) $params['sign'] : '';
        if ($out_trade_no === '' || $total_fee <= 0 || $sign === '') {
            throw new Exception('参数不完整');
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

        $verify_params = $params;
        unset($verify_params['sign']);
        $calc = api_orders_payjs_build_sign($verify_params, $key);
        if ($calc !== $sign) {
            if (class_exists('Todo_Error_Handler')) {
                Todo_Error_Handler::log("PAYJS_API_NOTIFY [Sign Error] Expected: {$calc}, Got: {$sign}", Todo_Error_Handler::LEVEL_ERROR);
            }
            throw new Exception('签名验证失败');
        }

        global $tododb;

        // 判断是否为充值订单（RC开头的单号）
        if (strpos($out_trade_no, 'RC') === 0) {
            $raw_num = substr($out_trade_no, 2);
            $rc_id = (strlen($raw_num) > 6) ? intval(substr($raw_num, -6)) : intval($raw_num);

            if (class_exists('Todo_Error_Handler')) {
                Todo_Error_Handler::log("PAYJS_API_NOTIFY [Recharge] Handling RC_ID: {$rc_id}, out_trade_no: {$out_trade_no}", Todo_Error_Handler::LEVEL_INFO);
            }

            $rc_row = $tododb->get_row($tododb->prepare("SELECT * FROM {$tododb->prefix}recharge_records WHERE id = %d LIMIT 1", $rc_id), [], 'ARRAY_A');
            if (is_object($rc_row)) $rc_row = (array) $rc_row;

            if (!$rc_row) {
                throw new Exception('充值记录不存在: ' . $out_trade_no);
            }

            $expected_fee = intval(round(floatval($rc_row['amount']) * 100));
            if ($total_fee !== $expected_fee) {
                if (class_exists('Todo_Error_Handler')) {
                    Todo_Error_Handler::log("PAYJS_API_NOTIFY [Recharge Amount Mismatch] Expected: {$expected_fee}, Got: {$total_fee}", Todo_Error_Handler::LEVEL_ERROR);
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
                    Todo_Error_Handler::log("PAYJS_API_NOTIFY [Recharge Success] Updated balance for User {$user_id}, Amount: {$amount}", Todo_Error_Handler::LEVEL_INFO);
                }
            }

            echo 'success';
            exit;
        }

        if (!class_exists('Todo_Order_Manager')) {
            throw new Exception('订单系统不可用');
        }
        Todo_Order_Manager::init();

        $order = Todo_Order_Manager::get_order_by_no($out_trade_no);
        if (!$order) {
            if (class_exists('Todo_Error_Handler')) {
                Todo_Error_Handler::log("PAYJS_API_NOTIFY [Order Not Found] out_trade_no: {$out_trade_no}", Todo_Error_Handler::LEVEL_ERROR);
            }
            throw new Exception('订单不存在');
        }
        if (isset($order['payment_status']) && (string) $order['payment_status'] === 'paid') {
            echo 'success';
            exit;
        }

        $order_amount = (int) round((float) $order['total_amount'] * 100);
        if ($order_amount !== $total_fee) {
            if (class_exists('Todo_Error_Handler')) {
                Todo_Error_Handler::log("PAYJS_API_NOTIFY [Amount Mismatch] Expected: {$order_amount}, Got: {$total_fee}", Todo_Error_Handler::LEVEL_ERROR);
            }
            throw new Exception('金额不匹配');
        }

        Todo_Order_Manager::update_payment_status((int) $order['id'], 'paid', array(
            'payment_method' => 'payjs',
            'transaction_id' => isset($params['payjs_order_id']) ? (string) $params['payjs_order_id'] : '',
            'paid_at' => current_time('mysql'),
            'notify_data' => json_encode($params, JSON_UNESCAPED_UNICODE),
        ));
        Todo_Order_Manager::update_order_status((int) $order['id'], 'processing');
        do_action('todo_payment_completed', (int) $order['id'], 'payjs');

        if (class_exists('Todo_Error_Handler')) {
            Todo_Error_Handler::log("PAYJS_API_NOTIFY [Order Success] Order {$order['id']} marked as paid.", Todo_Error_Handler::LEVEL_INFO);
        }

        echo 'success';
        exit;
    } catch (Throwable $e) {
        if (class_exists('Todo_Error_Handler')) {
            Todo_Error_Handler::log("PAYJS_API_NOTIFY [Exception] " . $e->getMessage(), Todo_Error_Handler::LEVEL_ERROR);
        }
        echo 'fail';
        exit;
    }
}

function api_hupijiao_build_hash($data, $app_secret) {
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

function api_payment_hupijiao_notify($params) {
    header('Content-Type: text/plain; charset=utf-8');
    try {
        $trade_order_id = isset($params['trade_order_id']) ? (string) $params['trade_order_id'] : '';
        $appid = isset($params['appid']) ? (string) $params['appid'] : '';
        $hash = isset($params['hash']) ? (string) $params['hash'] : '';
        $status = isset($params['status']) ? (string) $params['status'] : '';
        $total_fee = isset($params['total_fee']) ? (float) $params['total_fee'] : 0.0;
        if ($trade_order_id === '' || $appid === '' || $hash === '' || $total_fee <= 0) {
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

        $calc = api_hupijiao_build_hash($params, $app_secret);
        if (strtolower($calc) !== strtolower($hash)) {
            throw new Exception('签名验证失败');
        }

        if (!class_exists('Todo_Order_Manager')) {
            throw new Exception('订单系统不可用');
        }
        Todo_Order_Manager::init();
        $order = Todo_Order_Manager::get_order_by_no($trade_order_id);
        if (!$order) {
            throw new Exception('订单不存在');
        }
        if (isset($order['payment_status']) && (string) $order['payment_status'] === 'paid') {
            echo 'success';
            exit;
        }

        $order_amount = (int) round((float) $order['total_amount'] * 100);
        $notify_amount = (int) round($total_fee * 100);
        if ($order_amount !== $notify_amount) {
            throw new Exception('金额不匹配');
        }

        $transaction_id = '';
        if (isset($params['transaction_id']) && $params['transaction_id'] !== '') {
            $transaction_id = (string) $params['transaction_id'];
        } elseif (isset($params['open_order_id']) && $params['open_order_id'] !== '') {
            $transaction_id = (string) $params['open_order_id'];
        }

        Todo_Order_Manager::update_payment_status((int) $order['id'], 'paid', array(
            'payment_method' => 'hupijiao',
            'transaction_id' => $transaction_id,
            'paid_at' => current_time('mysql'),
            'notify_data' => json_encode($params, JSON_UNESCAPED_UNICODE),
        ));
        Todo_Order_Manager::update_order_status((int) $order['id'], 'processing');
        do_action('todo_payment_completed', (int) $order['id'], 'hupijiao');

        echo 'success';
        exit;
    } catch (Throwable $e) {
        echo 'fail';
        exit;
    }
}

function api_orders_delete($params) {
    global $tododb;
    $user_id = get_current_user_id();
    if (!$user_id) {
        send_api_error(401, '未登录');
    }
    $order_id = isset($params['id']) ? (int) $params['id'] : 0;
    if ($order_id <= 0) {
        send_api_error(400, '缺少订单ID');
    }

    $orders_table = TABLE_PREFIX . 'orders';
    $order_items_table = TABLE_PREFIX . 'order_items';
    $fields_table = TABLE_PREFIX . 'model_fields_product';

    $order = $tododb->get_row("SELECT * FROM {$orders_table} WHERE id = ? AND user_id = ? LIMIT 1", [$order_id, $user_id]);
    if (!$order) {
        send_api_error(404, '订单不存在');
    }

    $tododb->begin_transaction();
    try {
        // 如果是未支付订单，删除前归还库存
        if ($order->payment_status === 'unpaid' && $order->status !== 'cancelled') {
            $items = $tododb->get_results("SELECT * FROM {$order_items_table} WHERE order_id = ?", [$order_id]);
            foreach ($items as $item) {
                $pid = intval($item->product_id);
                $qty = intval($item->quantity);
                $s_idx = isset($item->spec_id) ? intval($item->spec_id) : -1;

                if ($s_idx >= 0) {
                    $p_fields = $tododb->get_row("SELECT stock, spec_details FROM {$fields_table} WHERE post_id = ?", [$pid]);
                    api_orders_adjust_spec_detail_stock($pid, $s_idx, $qty, $fields_table, isset($p_fields->spec_details) ? (string) $p_fields->spec_details : null);
                    $product_stock = isset($p_fields->stock) ? intval($p_fields->stock) : 0;
                    if ($product_stock > 0) {
                        api_orders_adjust_product_inventory($pid, $qty, -$qty, $fields_table, false);
                    } else {
                        api_orders_adjust_product_inventory($pid, 0, -$qty, $fields_table, false);
                    }
                } else {
                    api_orders_adjust_product_inventory($pid, $qty, -$qty, $fields_table, false);
                }
            }
        }

        // 删除订单和订单项
        $tododb->query("DELETE FROM {$order_items_table} WHERE order_id = ?", [$order_id]);
        $tododb->query("DELETE FROM {$orders_table} WHERE id = ?", [$order_id]);

        $tododb->commit();
        return ['success' => true, 'message' => '订单已删除'];
    } catch (Exception $e) {
        $tododb->rollback();
        send_api_error(500, '删除失败: ' . $e->getMessage());
    }
}

// 注册路由
if (function_exists('register_api_route') && function_exists('cms_api')) {
    register_api_route('orders', 'GET', 'api_get_orders');
    register_api_route('orders/([0-9]+)', 'GET', 'api_get_order_detail');
    register_api_route('orders/([0-9]+)', 'DELETE', 'api_orders_delete');
    register_api_route('orders', 'POST', 'api_submit_order');
    register_api_route('orders/([0-9]+)/pay', 'POST', 'api_orders_pay');
    register_api_route('payment/payjs/notify', 'POST', 'api_payment_payjs_notify');
    register_api_route('payment/hupijiao/notify', 'POST', 'api_payment_hupijiao_notify');
}
