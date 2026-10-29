<?php
/**
 * TodoCMS 购物车AJAX处理器
 * 
 * 处理购物车和立即购买相关的AJAX请求
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问：定义ABSPATH并允许AJAX入口

// 定义AJAX环境
define('DOING_AJAX', true);

// 前台会话：强制使用独立会话名，避免被 /admin 路径误判为后台会话
$__todo_frontend_session_name = 'TODOCMS_FRONTENDSESSID';
if (session_status() === PHP_SESSION_ACTIVE) {
    if (session_name() !== $__todo_frontend_session_name) {
        session_write_close();
        session_name($__todo_frontend_session_name);
        @session_start();
    }
} else {
    session_name($__todo_frontend_session_name);
}

// 直接访问保护
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    echo json_encode(['error' => 'Direct access denied']);
    exit;
}

// 加载核心文件
require_once dirname(dirname(dirname(__FILE__))) . '/core/bootstrap.php';

require_once ABSPATH . 'core/functions/function-loader.php';
Todo_Function_Loader::load_core_functions();

// 启动会话
if (!session_id()) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) {
        if (is_array($text) || is_object($text)) return '';
        $text = trim((string) $text);
        $text = strip_tags($text);
        $text = preg_replace('/[\r\n\t ]+/', ' ', $text ?? '');
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sanitize_email')) {
    function sanitize_email($email) {
        if (is_array($email) || is_object($email)) return '';
        $email = trim((string) $email);
        return filter_var($email, FILTER_SANITIZE_EMAIL);
    }
}

if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($text) {
        if (is_array($text) || is_object($text)) return '';
        $text = (string) $text;
        $text = strip_tags($text);
        $text = trim($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/\n{2,}/", "\n\n", $text ?? '');
        return trim((string) $text);
    }
}

/**
 * 添加商品到购物车
 */
function todo_ajax_add_to_cart() {
    try {
        // CSRF验证
        $security = Todo_Security_Manager::getInstance();
        if (!$security->verify_request_csrf('add_to_cart', '_todononce')) {
            throw new Exception(__('安全验证失败'));
        }
        
        $product_id = intval($_POST['product_id'] ?? 0);
        $quantity = intval($_POST['quantity'] ?? 1);
        $specs_raw = $_POST['specs'] ?? array();
        $specs = is_string($specs_raw) ? (json_decode($specs_raw, true) ?: array()) : (is_array($specs_raw) ? $specs_raw : array());
        
        if ($product_id <= 0) {
            throw new Exception(__('无效的商品ID'));
        }
        
        // 验证商品存在性
        $product = get_post($product_id);
        if (!$product || $product->post_status !== 'publish') {
            throw new Exception(__('商品不存在或已下架'));
        }
        
        // 调用购物车管理器
        $result = Todo_Cart_Manager::add_to_cart($product_id, $quantity, $specs);
        
        todo_send_json($result);
        
    } catch (Exception $e) {
        todo_send_json(array(
            'success' => false,
            'message' => $e->getMessage()
        ));
    }
}

/**
 * 立即购买（创建订单）
 */
function todo_ajax_buy_now() {
    try {
        // 登录校验（后端必须登录）
        if (!is_user_logged_in()) {
            $redirect = sanitize_text_field($_POST['redirect_to'] ?? ($_SERVER['HTTP_REFERER'] ?? home_url('/')));
            $login_url = function_exists('todo_login_url') ? todo_login_url($redirect) : (home_url('/login/') . (!empty($redirect) ? ('?redirect_to=' . urlencode($redirect)) : ''));
            todo_send_json(array(
                'success' => false,
                'message' => __('请先登录'),
                'login_url' => $login_url
            ));
            return;
        }
        
        // CSRF验证
        $security = Todo_Security_Manager::getInstance();
        if (!$security->verify_request_csrf('buy_now', '_todononce')) {
            throw new Exception(__('安全验证失败'));
        }
        
        $product_id = intval($_POST['product_id'] ?? 0);
        $quantity = intval($_POST['quantity'] ?? 1);
        $specs_raw = $_POST['specs'] ?? array();
        $specs = is_string($specs_raw) ? (json_decode($specs_raw, true) ?: array()) : (is_array($specs_raw) ? $specs_raw : array());
        $customer_info = $_POST['customer_info'] ?? array();
        $payment_method = sanitize_text_field($_POST['payment_method'] ?? '');
        $order_note = sanitize_textarea_field($_POST['order_note'] ?? '');
        
        if ($product_id <= 0) {
            throw new Exception(__('无效的商品ID'));
        }
        
        // 验证商品
        $product = get_post($product_id);
        if (!$product || $product->post_status !== 'publish') {
            throw new Exception(__('商品不存在或已下架'));
        }
        if (function_exists('todo_is_product_on_sale') && !todo_is_product_on_sale($product)) {
            throw new Exception(__('商品已下架'));
        }
        
        // 获取商品信息
        $product_info = todo_get_product_info($product_id, $specs);
        if (!$product_info || (isset($product_info['available']) && !$product_info['available'])) {
            throw new Exception(__('商品已下架或获取商品信息失败'));
        }
        
        // 检查库存
        if ($product_info['stock'] > 0 && $quantity > $product_info['stock']) {
            throw new Exception(sprintf(__('库存不足，当前库存：%d'), $product_info['stock']));
        }
        
        // 获取当前用户ID（如果已登录）
        $user_id = todo_get_current_user_id();
        
        // 构建订单数据
        $order_data = array(
            'user_id' => $user_id ?: 0,
            'customer_name' => sanitize_text_field($customer_info['name'] ?? ''),
            'customer_phone' => sanitize_text_field($customer_info['phone'] ?? ''),
            'customer_email' => sanitize_email($customer_info['email'] ?? ''),
            'shipping_address' => sanitize_textarea_field($customer_info['address'] ?? ''),
            'notes' => $order_note,
            'items' => array(
                array(
                    'product_id' => $product_id,
                    'product_name' => $product->post_title,
                    'product_spec' => !empty($specs) ? json_encode($specs) : '',
                    'price' => (float)$product_info['price'],
                    'quantity' => (int)$quantity,
                    'subtotal' => ((float)$product_info['price']) * (int)$quantity
                )
            ),
            'subtotal_amount' => ((float)$product_info['price']) * (int)$quantity,
            'shipping_fee' => 0, // 可以根据需要计算运费
            'discount_amount' => 0,
            'total_amount' => ((float)$product_info['price']) * (int)$quantity,
            'payment_method' => $payment_method
        );
        
        // 创建订单
        $order_id = Todo_Order_Manager::create_order($order_data);
        
        if (!$order_id) {
            throw new Exception(__('订单创建失败'));
        }
        
        // 减少库存
        todo_reduce_product_stock($product_id, $quantity, $specs);
        
        // 获取订单信息
        $order = Todo_Order_Manager::get_order($order_id);
        
        todo_send_json(array(
            'success' => true,
            'message' => __('订单创建成功'),
            'order_id' => $order_id,
            'order_no' => $order['order_no'],
            'total_amount' => $order['total_amount'],
            'redirect_url' => todo_get_checkout_url($order_id)
        ));
        
    } catch (Exception $e) {
        todo_send_json(array(
            'success' => false,
            'message' => $e->getMessage()
        ));
    }
}

/**
 * 获取购物车内容
 */
function todo_ajax_get_cart() {
    try {
        $cart = Todo_Cart_Manager::get_cart_details();
        
        todo_send_json(array(
            'success' => true,
            'cart' => $cart
        ));
        
    } catch (Exception $e) {
        todo_send_json(array(
            'success' => false,
            'message' => $e->getMessage()
        ));
    }
}

/**
 * 更新购物车商品数量
 */
function todo_ajax_update_cart() {
    try {
        // CSRF验证
        $security = Todo_Security_Manager::getInstance();
        if (!$security->verify_request_csrf('update_cart', '_todononce')) {
            throw new Exception(__('安全验证失败'));
        }
        
        $cart_key = sanitize_text_field($_POST['cart_key'] ?? '');
        $quantity = intval($_POST['quantity'] ?? 0);
        
        if (empty($cart_key)) {
            throw new Exception(__('无效的购物车项目'));
        }
        
        $result = Todo_Cart_Manager::update_cart_item($cart_key, $quantity);
        
        todo_send_json($result);
        
    } catch (Exception $e) {
        todo_send_json(array(
            'success' => false,
            'message' => $e->getMessage()
        ));
    }
}

/**
 * 从购物车移除商品
 */
function todo_ajax_remove_from_cart() {
    try {
        // CSRF验证
        $security = Todo_Security_Manager::getInstance();
        if (!$security->verify_request_csrf('remove_from_cart', '_todononce')) {
            throw new Exception(__('安全验证失败'));
        }
        
        $cart_key = sanitize_text_field($_POST['cart_key'] ?? '');
        
        if (empty($cart_key)) {
            throw new Exception(__('无效的购物车项目'));
        }
        
        $result = Todo_Cart_Manager::remove_from_cart($cart_key);
        
        todo_send_json($result);
        
    } catch (Exception $e) {
        todo_send_json(array(
            'success' => false,
            'message' => $e->getMessage()
        ));
    }
}

/**
 * 清空购物车
 */
function todo_ajax_clear_cart() {
    try {
        // CSRF验证
        $security = Todo_Security_Manager::getInstance();
        if (!$security->verify_request_csrf('clear_cart', '_todononce')) {
            throw new Exception(__('安全验证失败'));
        }
        
        $result = Todo_Cart_Manager::clear_cart();
        
        todo_send_json($result);
        
    } catch (Exception $e) {
        todo_send_json(array(
            'success' => false,
            'message' => $e->getMessage()
        ));
    }
}

/**
 * 辅助函数：获取商品信息
 */
function todo_get_product_info($product_id, $specs = array()) {
    $product = get_post($product_id);
    if (!$product) {
        return false;
    }

    // 基础信息容器
    $info = null;
    if (class_exists('Todo_Cart_Manager')) {
        // 使用购物车管理器的统一逻辑（含折扣/会员价）
        $tmp = Todo_Cart_Manager::get_product_info($product_id, $specs);
        if (is_array($tmp)) {
            $info = $tmp;
        }
    }

    // 统一价格接口：post_custom_fields -> product_price(JSON: regular/sale)
    $base_price = 0.0;
    if (function_exists('get_product_price')) {
        $price_info = get_product_price($product_id);
        if (is_array($price_info)) {
            $sale = floatval($price_info['sale_price'] ?? 0);
            $regular = floatval($price_info['regular_price'] ?? 0);
            $base_price = $sale > 0 ? $sale : $regular;
        }
    }
    if ($base_price <= 0) {
        // 回退常见价格键
        $candidate_keys = array('todo_product_price', 'product_price', '_sale_price', '_regular_price', 'price');
        foreach ($candidate_keys as $k) {
            $v = get_post_meta($product_id, $k, true);
            if ($v !== '' && floatval($v) > 0) {
                $base_price = floatval($v);
                break;
            }
        }
    }

    // 库存：优先统一接口
    $stock = function_exists('get_product_stock') ? intval(get_product_stock($product_id)) : intval(get_post_meta($product_id, 'stock', true));
    $image = get_the_post_thumbnail_url($product_id, 'thumbnail');

    // 如果有规格，获取规格价格/库存（仅在返回有效值时覆盖）
    if (!empty($specs)) {
        $spec_data = todo_get_product_spec_data($product_id, $specs);
        if (is_array($spec_data)) {
            if (isset($spec_data['price']) && floatval($spec_data['price']) > 0) {
                $base_price = floatval($spec_data['price']);
            }
            if (isset($spec_data['stock'])) {
                $stock = intval($spec_data['stock']);
            }
        }
    }

    // 组装返回：优先用Cart_Manager返回并在价格为0时回填
    if (is_array($info)) {
        if (!isset($info['price']) || floatval($info['price']) <= 0) {
            $info['price'] = $base_price;
        }
        if (!isset($info['stock'])) { $info['stock'] = $stock; }
        if (!isset($info['image']) || $info['image'] === '') { $info['image'] = $image ?: ''; }
        if (!isset($info['title'])) { $info['title'] = $product->post_title; }
        if (!isset($info['available'])) { $info['available'] = ($product->post_status === 'publish'); }
        return $info;
    }

    // 兜底返回
    return array(
        'title' => $product->post_title,
        'price' => $base_price,
        'stock' => $stock,
        'image' => $image ?: '',
        'available' => $product->post_status === 'publish'
    );
}

/**
 * 辅助函数：获取商品规格数据
 */
function todo_get_product_spec_data($product_id, $specs) {
    // 这里可以根据实际的规格存储方式来实现
    // 暂时返回基础价格和库存
    return array(
        'price' => floatval(get_post_meta($product_id, 'price', true)),
        'stock' => intval(get_post_meta($product_id, 'stock', true))
    );
}

/**
 * 辅助函数：减少商品库存
 */
function todo_reduce_product_stock($product_id, $quantity, $specs = array()) {
    if (!empty($specs)) {
        // 处理规格库存
        $product_specs = get_post_meta($product_id, '_product_specs', true);
        if (is_array($product_specs)) {
            foreach ($product_specs as $key => $spec) {
                $match = true;
                foreach ($specs as $spec_name => $spec_value) {
                    if (!isset($spec[$spec_name]) || $spec[$spec_name] !== $spec_value) {
                        $match = false;
                        break;
                    }
                }
                
                if ($match) {
                    $current_stock = intval($spec['stock'] ?? 0);
                    $product_specs[$key]['stock'] = max(0, $current_stock - $quantity);
                    update_post_meta($product_id, '_product_specs', $product_specs);
                    break;
                }
            }
        }
    } else {
        // 处理单规格库存
        $current_stock = intval(get_post_meta($product_id, 'stock', true));
        update_post_meta($product_id, 'stock', max(0, $current_stock - $quantity));
    }
}

/**
 * 辅助函数：获取结账页面URL
 */
function todo_get_checkout_url($order_id) {
    return home_url('/checkout/?order_id=' . $order_id);
}

// 处理AJAX请求
$action = sanitize_text_field($_REQUEST['cart_action'] ?? ($_REQUEST['action'] ?? ''));

switch ($action) {
    case 'csrf_token':
        try {
            $target_action = sanitize_text_field($_REQUEST['action_name'] ?? ($_REQUEST['action'] ?? ''));
            if ($target_action === 'csrf_token' && isset($_REQUEST['cart_action']) && $_REQUEST['cart_action'] === 'csrf_token') {
                $target_action = sanitize_text_field($_REQUEST['action'] ?? '');
            }
            if (empty($target_action) || $target_action === 'csrf_token') {
                $target_action = 'general';
            }
            $security = Todo_Security_Manager::getInstance();
            $token = $security->create_csrf_token($target_action);
            todo_send_json(array(
                'success' => true,
                'token' => $token,
                'nonce' => $token
            ));
        } catch (Exception $e) {
            todo_send_json(array('success' => false, 'message' => $e->getMessage()));
        }
        break;

    case 'add_to_cart':
        todo_ajax_add_to_cart();
        break;
        
    case 'buy_now':
        todo_ajax_buy_now();
        break;
        
    case 'get_cart':
        todo_ajax_get_cart();
        break;
        
    case 'update_cart':
        todo_ajax_update_cart();
        break;
        
    case 'remove_from_cart':
        todo_ajax_remove_from_cart();
        break;
        
    case 'clear_cart':
        todo_ajax_clear_cart();
        break;
        
    default:
        todo_send_json(array(
            'success' => false,
            'message' => __('未知的操作')
        ));
        break;
}
