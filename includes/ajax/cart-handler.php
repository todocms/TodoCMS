<?php
/**
 * TodoCMS 购物车AJAX接口
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}

if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    if (!defined('ABSPATH')) {
        define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
    }

    require_once ABSPATH . 'core/bootstrap.php';
    require_once ABSPATH . 'core/functions/function-loader.php';
    if (class_exists('Todo_Function_Loader')) {
        Todo_Function_Loader::load_core_functions();
    }

    if (class_exists('Todo_Session_Manager')) {
        Todo_Session_Manager::init();
    } elseif (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (class_exists('Todo_Security_Manager')) {
        Todo_Security_Manager::init();
    }
}

// 检查AJAX请求
if (!defined('DOING_AJAX') || !DOING_AJAX) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Direct access denied']);
    exit;
}

// 检查请求头
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    todo_send_json_error('Direct access denied');
}

header('Content-Type: application/json; charset=utf-8');

try {
    $action = $_POST['cart_action'] ?? '';

    // 写操作的 PRG 幂等检测（仅对包含 _submit_key 的请求）
    $__cart_write_actions = ['add', 'update', 'remove', 'clear'];
    $__cart_prg_guard = null;
    if (
        in_array($action, $__cart_write_actions, true) &&
        class_exists('Todo_PRG_Guard')
    ) {
        $__cart_submit_key = Todo_PRG_Guard::extract_submit_key();
        if (!empty($__cart_submit_key)) {
            $__cart_prg_guard = Todo_PRG_Guard::check('cart_' . $action, 15);
            if ($__cart_prg_guard->is_duplicate()) {
                $__cart_cached = $__cart_prg_guard->get_cached_result();
                echo json_encode(
                    $__cart_cached !== null
                        ? $__cart_cached
                        : ['success' => true, 'prg' => 'cached'],
                    JSON_UNESCAPED_UNICODE
                );
                exit;
            }
        }
        unset($__cart_submit_key);
    }
    unset($__cart_write_actions);
    
    switch ($action) {
        case 'add':
            handle_add_to_cart($__cart_prg_guard);
            break;
            
        case 'update':
            handle_update_cart($__cart_prg_guard);
            break;
            
        case 'remove':
            handle_remove_from_cart($__cart_prg_guard);
            break;
            
        case 'clear':
            handle_clear_cart($__cart_prg_guard);
            break;
            
        case 'get':
            handle_get_cart();
            break;

        case 'csrf_token':
            handle_get_csrf_token();
            break;
            
        default:
            throw new Exception(__('无效的操作'));
    }
    
} catch (Exception $e) {
    todo_send_json_error($e->getMessage());
}

/**
 * 处理添加到购物车
 * @param Todo_PRG_Guard|null $prg_guard 幂等守卫实例
 */
function handle_add_to_cart($prg_guard = null) {
    // 验证CSRF令牌
    if (!Todo_Security_Manager::verify_request_csrf('add_to_cart')) {
        throw new Exception(__('安全验证失败'));
    }
    
    $product_id = intval($_POST['product_id'] ?? 0);
    $quantity = intval($_POST['quantity'] ?? 1);
    $specs = $_POST['specs'] ?? array();
    $unit_qty = floatval($_POST['unit_qty'] ?? 1);
    
    if (!$product_id) {
        throw new Exception(__('商品ID无效'));
    }
    
    if ($quantity <= 0) {
        throw new Exception(__('数量必须大于0'));
    }
    
    // 验证商品存在
    $product = get_post($product_id);
    if (!$product || $product->post_type !== 'product') {
        throw new Exception(__('商品不存在'));
    }
    
    // 验证商品状态（发布状态且非仓库下架）
    if (function_exists('todo_is_product_on_sale')) {
        if (!todo_is_product_on_sale($product)) {
            throw new Exception(__('商品已下架'));
        }
    } else {
        if ($product->post_status !== 'publish') {
            throw new Exception(__('商品已下架'));
        }
    }
    
    // 验证库存
    $stock = get_post_meta($product_id, 'product_stock', true);
    if ($stock !== '' && intval($stock) < $quantity) {
        throw new Exception(__('库存不足'));
    }
    
    // 添加到购物车
    $result = Todo_Cart_Manager::add_to_cart($product_id, $quantity, $specs, $unit_qty);
    
    if ($result) {
        $cart = Todo_Cart_Manager::get_cart();
        $cart_count = is_array($cart) && isset($cart['count']) ? (int) $cart['count'] : 0;
        $response_data = array(
            'success' => true,
            'data'    => array(
                'message'    => __('已添加到购物车'),
                'cart_count' => $cart_count
            )
        );
        // 缓存结果供 PRG 重复请求复用
        if ($prg_guard instanceof Todo_PRG_Guard) {
            $prg_guard->cache_result($response_data);
        }
        todo_send_json_success(array(
            'message'    => __('已添加到购物车'),
            'cart_count' => $cart_count
        ));
    } else {
        throw new Exception(__('添加失败'));
    }
}

/**
 * 处理更新购物车
 * @param Todo_PRG_Guard|null $prg_guard
 */
function handle_update_cart($prg_guard = null) {
    // 验证CSRF令牌
    if (!Todo_Security_Manager::verify_request_csrf('update_cart')) {
        throw new Exception(__('安全验证失败'));
    }
    
    $cart_key = sanitize_text_field($_POST['cart_key'] ?? '');
    $quantity = intval($_POST['quantity'] ?? 0);
    
    if (!$cart_key) {
        throw new Exception(__('购物车项目无效'));
    }
    
    if ($quantity <= 0) {
        throw new Exception(__('数量不能为负数'));
    }
    
    // 更新数量
    $result = Todo_Cart_Manager::update_cart_item($cart_key, $quantity);
    
    if ($result) {
        $cart_details = Todo_Cart_Manager::get_cart_details();
        $response_data = array(
            'success' => true,
            'data'    => array('message' => __('购物车已更新'), 'cart' => $cart_details)
        );
        if ($prg_guard instanceof Todo_PRG_Guard) {
            $prg_guard->cache_result($response_data);
        }
        todo_send_json_success(array(
            'message' => __('购物车已更新'),
            'cart'    => $cart_details
        ));
    } else {
        throw new Exception(__('更新失败'));
    }
}

/**
 * 处理从购物车移除
 */
function handle_remove_from_cart($prg_guard = null) {
    // 验证CSRF令牌
    if (!Todo_Security_Manager::verify_request_csrf('remove_from_cart')) {
        throw new Exception(__('安全验证失败'));
    }
    
    $cart_key = sanitize_text_field($_POST['cart_key'] ?? '');
    
    if (!$cart_key) {
        throw new Exception(__('购物车项目无效'));
    }
    
    $result = Todo_Cart_Manager::remove_from_cart($cart_key);
    
    if ($result) {
        $cart_details = Todo_Cart_Manager::get_cart_details();
        $response_data = array(
            'success' => true,
            'data'    => array('message' => __('商品已移除'), 'cart' => $cart_details)
        );
        if ($prg_guard instanceof Todo_PRG_Guard) {
            $prg_guard->cache_result($response_data);
        }
        todo_send_json_success($response_data['data']);
    } else {
        throw new Exception(__('移除失败'));
    }
}

/**
 * 处理清空购物车
 */
function handle_clear_cart($prg_guard = null) {
    // 验证CSRF令牌
    if (!Todo_Security_Manager::verify_request_csrf('clear_cart')) {
        throw new Exception(__('安全验证失败'));
    }
    
    Todo_Cart_Manager::clear_cart();
    
    $response_data = array(
        'success' => true,
        'data'    => array(
            'message' => __('购物车已清空'),
            'cart'    => array(
                'items' => array(),
                'count' => 0,
                'total' => 0
            )
        )
    );
    if ($prg_guard instanceof Todo_PRG_Guard) {
        $prg_guard->cache_result($response_data);
    }
    todo_send_json_success($response_data['data']);
}

/**
 * 处理获取购物车
 */
function handle_get_cart() {
    $cart_details = Todo_Cart_Manager::get_cart_details(array('persist' => false));
    if (!empty($cart_details['items']) && is_array($cart_details['items'])) {
        $total = 0;
        $count = 0;
        foreach ($cart_details['items'] as $key => &$item) {
            $pid = intval($item['product_id'] ?? 0);
            if ($pid <= 0) continue;

            $base_price = floatval($item['price'] ?? ($item['current_price'] ?? ($item['regular_price'] ?? ($item['sale_price'] ?? 0))));
            $base_regular_price = floatval($item['regular_price'] ?? $base_price);
            $base_sale_price = floatval($item['sale_price'] ?? 0);
            if ($base_regular_price < $base_price) {
                $base_regular_price = $base_price;
            }

            $p = $base_price;
            $regular_display = $base_regular_price;
            $sale_display = $base_sale_price;

            if (function_exists('todo_apply_currency_exchange')) {
                $p = todo_apply_currency_exchange($p);
                $regular_display = todo_apply_currency_exchange($regular_display);
                $sale_display = $base_sale_price > 0 ? todo_apply_currency_exchange($base_sale_price) : 0;
            }

            $item['price'] = $p;
            $item['current_price'] = $p;
            $item['regular_price'] = $regular_display;
            $item['sale_price'] = $sale_display;
            
            // 设置规格文本（解析 v0/v1 为规格组标题）
            if (empty($item['specs_text']) && !empty($item['specs'])) {
                $item['specs_text'] = todo_format_specs_text($item['specs'], $pid);
            }
            
            $q = intval($item['quantity'] ?? 0);
            $count += $q;
            $total += $q * $p;
        }
        $cart_details['count'] = $count;
        $cart_details['total'] = $total;
    }

    $currency_symbol = '';
    $currency_code = '';
    if (function_exists('todo_get_currency_config')) {
        $cfg = todo_get_currency_config();
        if (!empty($cfg['display_symbol'])) {
            $currency_symbol = $cfg['display_symbol'];
        } elseif (!empty($cfg['symbol'])) {
            $currency_symbol = $cfg['symbol'];
        }
        if (!empty($cfg['code'])) {
            $currency_code = $cfg['code'];
        }
    }
    $cart_details['currency_symbol'] = $currency_symbol;
    $cart_details['currency_code'] = $currency_code;

    todo_send_json_success(array(
        'cart' => $cart_details
    ));
}

function handle_get_csrf_token() {
    $action = isset($_POST['action']) ? sanitize_text_field($_POST['action']) : '';
    if ($action === '') {
        throw new Exception(__('缺少动作参数'));
    }

    $allowed_actions = [
        'add_to_cart',
        'buy_now',
        'update_cart',
        'remove_from_cart',
        'clear_cart',
        'get_cart',
        'create_order',
        'update_order',
        'delete_order',
        'get_product_info',
        'address_action',
        'process_payment',
        'check_payment_status',
        'get_order_info',
        'toggle_favorite',
        'add_favorite',
        'remove_favorite'
    ];
    if (!in_array($action, $allowed_actions, true)) {
        throw new Exception(__('无效的动作参数'));
    }

    $token = Todo_Security_Manager::generate_csrf_token($action);
    todo_send_json_success([
        'token' => $token,
        'action' => $action
    ]);
}
