<?php
/**
 * TodoCMS 结算处理AJAX接口
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 标记为AJAX请求
if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}

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

    if (class_exists('Todo_Session_Manager')) {
        Todo_Session_Manager::init();
    } elseif (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

// PRG 防重复提交守卫（仅对携带 _submit_key 的 POST 生效）
if (!defined('PREVENT_AJAX_AUTO_RUN')) {
   Todo_PRG_Guard::guard_endpoint('checkout', 30);
}

// 检查请求头
if (!defined('PREVENT_AJAX_AUTO_RUN')) {
    if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
        http_response_code(403);
        todo_send_json_error('Direct access denied');
    }
}

if (!defined('PREVENT_AJAX_AUTO_RUN')) {
	try {
	    // 检查用户登录状态与访客下单开关
	    $user_id = get_current_user_id();
	    $is_guest_checkout = false;
	    if (!$user_id) {
	        $guest_checkout_enabled = 1;
	        if (function_exists('todo_shop_get_service_settings')) {
	            $settings = todo_shop_get_service_settings();
	            if (isset($settings['guest_checkout'])) {
	                $guest_checkout_enabled = intval($settings['guest_checkout']);
	            }
	        }
	        if (function_exists('todo_get_option') && todo_get_option('guest_checkout') !== null) {
	            $guest_checkout_enabled = intval(todo_get_option('guest_checkout'));
	        }
	        if (!$guest_checkout_enabled) {
	            throw new Exception(__('请先登录后再结算'));
	        }
	        $is_guest_checkout = true;

	        // === 国际标准：游客下单自动分配/注册临时账户并自动静默登录 ===
	        $post_email = trim($_POST['guest_email'] ?? $_POST['email'] ?? $_POST['recipient_email'] ?? '');
	        $post_phone = trim($_POST['recipient_phone'] ?? $_POST['phone'] ?? '');

	        if ($post_email !== '' || $post_phone !== '') {
	            global $tododb;
	            $existing_user = null;
	            $table_users = DB_PREFIX . 'users';

	            // 1. 优先查重是否已存在该邮箱/手机号账户
	            if ($post_email !== '') {
	                $existing_user = $tododb->get_row("SELECT * FROM {$table_users} WHERE user_email = ? LIMIT 1", array($post_email), 'ARRAY_A');
	            }
	            if (!$existing_user && $post_phone !== '') {
	                $existing_user = $tododb->get_row("SELECT u.* FROM {$table_users} u INNER JOIN " . DB_PREFIX . "user_meta um ON u.ID = um.user_id WHERE um.meta_key = 'phone' AND um.meta_value = ? LIMIT 1", array($post_phone), 'ARRAY_A');
	            }

	            if ($existing_user && isset($existing_user['ID'])) {
	                $user_id = intval($existing_user['ID']);
	            } else {
	                // 2. 首次下单游客：创建临时标准买家账户
	                $username_seed = !empty($post_email) ? explode('@', $post_email)[0] : 'guest_' . date('Ymd');
	                $username_seed = preg_replace('/[^a-zA-Z0-9_]/', '', $username_seed);
	                if (strlen($username_seed) < 3) { $username_seed = 'guest_' . rand(100, 999); }
	                
	                $username = $username_seed;
	                $counter = 1;
	                while ($tododb->get_var("SELECT COUNT(*) FROM {$table_users} WHERE user_login = ?", array($username)) > 0) {
	                    $username = $username_seed . '_' . rand(100, 9999);
	                    $counter++;
	                    if ($counter > 10) break;
	                }

	                $user_email = !empty($post_email) ? $post_email : $username . '@guest.local';
	                $display_name = !empty($_POST['recipient_name']) ? sanitize_text_field($_POST['recipient_name']) : $username;
	                $random_pass = function_exists('todo_generate_password')
	                    ? todo_generate_password(12, true)
	                    : bin2hex(random_bytes(8));

	                $user_data = array(
	                    'user_login' => $username,
	                    'user_pass' => password_hash($random_pass, PASSWORD_DEFAULT),
	                    'user_nicename' => $username,
	                    'user_email' => $user_email,
	                    'display_name' => $display_name,
	                    'user_registered' => current_time('mysql'),
	                    'user_status' => 0
	                );

	                if ($tododb->insert($table_users, $user_data)) {
	                    $user_id = intval($tododb->insert_id());
	                    if ($user_id > 0 && !empty($post_phone)) {
	                        $tododb->insert(DB_PREFIX . 'user_meta', array(
	                            'user_id' => $user_id,
	                            'meta_key' => 'phone',
	                            'meta_value' => $post_phone
	                        ));
	                    }
	                }
	            }

	            // 3. 执行静默登录（设置 Cookie 与 Session 身份），使游客直接享有该临时账号权限
	            if ($user_id > 0 && class_exists('Todo_Session_Manager')) {
	                Todo_Session_Manager::init();
	                Todo_Session_Manager::set_frontend_session($user_id, 'subscriber', true);
	            }
	        }
	    }

	    // 验证CSRF令牌
	    if (!Todo_Security_Manager::verify_request_csrf('create_order', $_POST['_todononce'] ?? null)) {
	        throw new Exception(__('安全验证失败'));
	    }
	    
	    // 获取购物车内容或直接购买内容
	    $is_buy_now = isset($_POST['buy_now_mode']) && $_POST['buy_now_mode'] === '1';
	    
	    if ($is_buy_now) {
	        $product_id = intval($_POST['buy_now_product_id'] ?? 0);
	        $quantity = intval($_POST['buy_now_quantity'] ?? 1);
	        $specs_json = $_POST['buy_now_specs'] ?? '{}';
	        $specs = json_decode(stripslashes($specs_json), true) ?: array();
	        
	        $info_func = 'todo_get_detailed_product_info';
	        if (!function_exists($info_func)) {
	            $pf_file = ABSPATH . 'includes/ajax/product-info.php';
	            if (file_exists($pf_file)) {
	                if (!defined('PREVENT_AJAX_AUTO_RUN')) {
	                    define('PREVENT_AJAX_AUTO_RUN', true);
	                }
	                require_once $pf_file;
	            }
	        }
	        
	        $product_info = function_exists('todo_get_detailed_product_info') 
	            ? todo_get_detailed_product_info($product_id, $quantity, $specs) 
	            : Todo_Cart_Manager::get_product_info($product_id, $specs);

	        if (!$product_info || (isset($product_info['available']) && !$product_info['available']) || (isset($product_info['is_on_sale']) && !$product_info['is_on_sale'])) {
	            throw new Exception(__('商品不存在或已下架'));
	        }
	        
	        if (function_exists('todo_is_product_on_sale') && !todo_is_product_on_sale($product_id)) {
	            throw new Exception(__('商品已下架'));
	        }
	        
	        if (isset($product_info['stock']) && $product_info['stock'] > 0 && $product_info['stock'] < $quantity) {
	            throw new Exception(__('商品库存不足'));
	        }
	        
	        $unit_price = floatval($product_info['price'] ?? 0);
	        
	        $cart = array(
	            'items' => array(
	                array(
	                    'product_id' => $product_id,
	                    'title' => $product_info['title'],
	                    'image' => $product_info['image'],
	                    'specs' => $specs,
	                    'quantity' => $quantity,
	                    'price' => $unit_price,
	                    'product_type' => $product_info['product_type'] ?? 'physical'
	                )
	            ),
	            'total' => $unit_price * $quantity,
	            'count' => $quantity
	        );
	        $checkout_selected_keys = array();
	    } else {
	        $cart = Todo_Cart_Manager::get_cart_details();
	        if (empty($cart['items'])) {
	            throw new Exception(__('购物车为空'));
	        }

	        // 支持从购物车传参 selected 选中的项目列表（如 selected=key1,key2）
	        $selected_param = trim($_REQUEST['selected'] ?? $_POST['selected'] ?? $_GET['selected'] ?? '');
	        $checkout_selected_keys = array();
	        if (!empty($selected_param)) {
	            $selected_keys = array_map('trim', explode(',', $selected_param));
	            $filtered_items = array();
	            foreach ($cart['items'] as $ck => $ci) {
	                if (in_array((string)$ck, $selected_keys, true) || in_array((string)($ci['product_id'] ?? ''), $selected_keys, true)) {
	                    $filtered_items[$ck] = $ci;
	                    $checkout_selected_keys[] = $ck;
	                }
	            }
	            if (!empty($filtered_items)) {
	                $cart['items'] = $filtered_items;
	                $tot_qty = 0;
	                $tot_price = 0;
	                foreach ($filtered_items as $fi) {
	                    $q = intval($fi['quantity'] ?? 1);
	                    $p = floatval($fi['price'] ?? 0);
	                    $tot_qty += $q;
	                    $tot_price += $q * $p;
	                }
	                $cart['count'] = $tot_qty;
	                $cart['total'] = $tot_price;

	                // 重新计算整单是否为虚拟商品
	                $is_virt = true;
	                foreach ($filtered_items as $fi) {
	                    if (($fi['product_type'] ?? 'physical') !== 'virtual') {
	                        $is_virt = false;
	                        break;
	                    }
	                }
	                $cart['is_virtual'] = $is_virt;
	            }
	        }
	    }
	    
	    // 验证表单数据
	    $order_data = validate_checkout_form($_POST, $cart);
	    
	    // 计算订单金额（包含表单提交的优惠/折扣金额及积分抵扣）
	    $amounts = calculate_order_amounts(
	        $cart, 
	        $order_data['shipping_method'], 
	        $order_data['discount_amount'] ?? 0, 
	        $order_data['is_virtual'] ?? false,
	        $order_data['points_discount_amount'] ?? 0
	    );

	    if (class_exists('Todo_Error_Handler')) {
	        Todo_Error_Handler::log(
	            '[Checkout Order Debug] Amounts Calculated',
	            Todo_Error_Handler::LEVEL_INFO,
	            [
	                'is_buy_now' => $is_buy_now,
	                'user_id' => function_exists('todo_get_current_user_id') ? todo_get_current_user_id() : 0,
	                'cart_items_count' => count($cart['items'] ?? []),
	                'cart_items' => $cart['items'] ?? [],
	                'calculated_amounts' => $amounts
	            ]
	        );
	    }
	    
	    // 创建订单
	    $order_id = create_checkout_order($cart, $order_data, $amounts);

	    if (class_exists('Todo_Error_Handler')) {
	        Todo_Error_Handler::log(
	            '[Checkout Order Debug] Order Created',
	            Todo_Error_Handler::LEVEL_INFO,
	            [
	                'order_id' => $order_id,
	                'user_id' => function_exists('todo_get_current_user_id') ? todo_get_current_user_id() : 0,
	                'order_total' => $amounts['total'] ?? 0
	            ]
	        );
	    }
	    
	    if (!$order_id) {
	        throw new Exception(__('订单创建失败'));
	    }

	    if ($is_guest_checkout && function_exists('todo_save_guest_order_id')) {
	        todo_save_guest_order_id($order_id);
	    }
	    
	    // 清理已结算商品（仅在非直接购买模式下）
	    if (!$is_buy_now) {
	        if (!empty($checkout_selected_keys)) {
	            foreach ($checkout_selected_keys as $del_key) {
	                Todo_Cart_Manager::remove_from_cart($del_key);
	            }
	        } else {
	            Todo_Cart_Manager::clear_cart();
	        }
	    }
	    
	    // 订单创建成功后，统一重定向至 A 站收银台页面进行支付网关发起
	    $selected_pay_method = $order_data['payment_method'] ?? ($_POST['payment_method'] ?? '');
	    $raw_payment_url = todo_home_url('/?user=payment&order_id=' . $order_id);
	    if (!empty($selected_pay_method)) {
	        $raw_payment_url .= '&pay_method=' . urlencode($selected_pay_method);
	    }
	    
	    // 优先从 HTTP Referer 推断买家提交前的多语言前缀（如 /en/）
	    $current_locale = function_exists('current_locale') ? current_locale() : 'zh-CN';
	    $referer = $_SERVER['HTTP_REFERER'] ?? '';
	    if (!empty($referer)) {
	        $ref_path = parse_url($referer, PHP_URL_PATH) ?? '';
	        if (class_exists('LanguageRegistry')) {
	            $reg = LanguageRegistry::get_instance();
	            $ref_locale = $reg->match_by_path_segment($ref_path);
	            if ($ref_locale) {
	                $current_locale = $ref_locale;
	            }
	        }
	    }

	    $payment_url = (function_exists('get_language_url'))
	        ? get_language_url($current_locale, $raw_payment_url)
	        : $raw_payment_url;
	    
	    todo_send_json_success(array(
	        'order_id' => $order_id,
	        'redirect_url' => $payment_url
	    ));
	    
	} catch (Exception $e) {
	    todo_send_json_error($e->getMessage());
	}
}

/**
 * 获取购物车小计
 *
 * @param array $cart
 * @return float
 */
function todo_checkout_get_cart_subtotal($cart) {
    return round(max(0, floatval($cart['total'] ?? 0)), 2);
}

/**
 * 获取订单项金额
 *
 * @param array $item
 * @return float
 */
function todo_checkout_get_item_total($item) {
    $qty = max(1, intval($item['quantity'] ?? 1));
    $price = floatval($item['price'] ?? 0);
    return round(max(0, $qty * $price), 2);
}

/**
 * 获取商品分类ID列表
 *
 * @param int $product_id
 * @return array
 */
function todo_checkout_get_product_category_ids($product_id) {
    $product_id = intval($product_id);
    if ($product_id <= 0) {
        return array();
    }

    $category_ids = array();
    if (function_exists('get_the_terms')) {
        $terms = get_the_terms($product_id, 'category');
        if ($terms && is_array($terms)) {
            foreach ($terms as $term) {
                $term_id = intval($term->term_id ?? 0);
                if ($term_id > 0) {
                    $category_ids[] = $term_id;
                }
            }
        }
    }

    if (empty($category_ids)) {
        global $tododb;
        if ($tododb && isset($tododb->prefix)) {
            $posts_table = $tododb->prefix . 'posts';
            $term_rel_table = $tododb->prefix . 'term_relationships';
            $term_tax_table = $tododb->prefix . 'term_taxonomy';

            $primary_category_id = intval($tododb->get_var("SELECT category_id FROM {$posts_table} WHERE ID = ? LIMIT 1", array($product_id)));
            if ($primary_category_id > 0) {
                $category_ids[] = $primary_category_id;
            }

            $term_rows = $tododb->get_results(
                "SELECT tt.term_id
                 FROM {$term_rel_table} tr
                 INNER JOIN {$term_tax_table} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                 WHERE tr.object_id = ? AND tt.taxonomy = 'category'",
                array($product_id),
                'ARRAY_A'
            );
            if (is_array($term_rows)) {
                foreach ($term_rows as $row) {
                    $term_id = intval($row['term_id'] ?? 0);
                    if ($term_id > 0) {
                        $category_ids[] = $term_id;
                    }
                }
            }
        }
    }

    $category_ids = array_values(array_unique(array_filter(array_map('intval', $category_ids))));
    return $category_ids;
}

/**
 * 获取订单项分类ID列表
 *
 * @param array $item
 * @return array
 */
function todo_checkout_get_item_category_ids($item) {
    $raw_ids = $item['category_ids'] ?? ($item['category_id'] ?? array());
    $category_ids = array();

    if (is_array($raw_ids)) {
        $category_ids = $raw_ids;
    } elseif (is_string($raw_ids) && $raw_ids !== '') {
        $category_ids = preg_split('/\s*,\s*/', $raw_ids);
    } elseif (!empty($raw_ids)) {
        $category_ids = array($raw_ids);
    }

    $category_ids = array_values(array_unique(array_filter(array_map('intval', $category_ids))));
    if (!empty($category_ids)) {
        return $category_ids;
    }

    return todo_checkout_get_product_category_ids(intval($item['product_id'] ?? 0));
}

/**
 * 计算可参与积分抵扣的真实小计
 *
 * @param array $cart
 * @return float
 */
function todo_checkout_get_points_eligible_subtotal($cart) {
    $raw_usable_categories = (string) (function_exists('get_option') ? get_option('points_usable_categories', '') : '');
    $usable_categories = array_values(array_unique(array_filter(
        array_map('intval', preg_split('/\s*,\s*/', $raw_usable_categories))
    )));

    if (empty($usable_categories)) {
        return todo_checkout_get_cart_subtotal($cart);
    }

    $eligible_subtotal = 0.0;
    foreach (($cart['items'] ?? array()) as $item) {
        $item_category_ids = todo_checkout_get_item_category_ids($item);
        if (empty($item_category_ids)) {
            continue;
        }
        if (!empty(array_intersect($usable_categories, $item_category_ids))) {
            $eligible_subtotal += todo_checkout_get_item_total($item);
        }
    }

    return round(max(0, $eligible_subtotal), 2);
}

/**
 * 服务端校验并计算优惠券抵扣
 *
 * @param string $coupon_code
 * @param int $user_id
 * @param array $post_data
 * @param array $cart
 * @return float
 * @throws Exception
 */
function todo_checkout_calculate_coupon_discount($coupon_code, $user_id, $post_data, $cart) {
    $coupon_code = sanitize_text_field(trim((string) $coupon_code));
    if ($coupon_code === '') {
        return 0.0;
    }

    global $tododb;
    $coupons_table = $tododb->prefix . 'coupons';
    $user_coupons_table = $tododb->prefix . 'user_coupons';

    $sql_c = $tododb->prepare("SELECT * FROM {$coupons_table} WHERE code = %s LIMIT 1", $coupon_code);
    $coupon_row = $tododb->get_row($sql_c, [], 'ARRAY_A');
    if (!$coupon_row) {
        throw new Exception(__('优惠券码无效或不存在'));
    }

    $coupon_row = (array) $coupon_row;
    $c_id = (int) ($coupon_row['id'] ?? 0);
    $c_issue_mode = (string) ($coupon_row['issue_mode'] ?? 'redeem');

    if ($user_id > 0) {
        $sql_uc = $tododb->prepare(
            "SELECT id FROM {$user_coupons_table} WHERE coupon_id = %d AND user_id = %d AND status = 'unused' LIMIT 1",
            $c_id, $user_id
        );
        $has_uc = (int) $tododb->get_var($sql_uc);
        if ($has_uc <= 0) {
            throw new Exception(__('您的卡券中无可用优惠券'));
        }
    } else {
        if ($c_issue_mode !== 'redeem') {
            throw new Exception(__('会员券，请登录后使用'));
        }

        $guest_email = sanitize_email($post_data['guest_email'] ?? $post_data['email'] ?? '');
        $guest_phone = sanitize_text_field($post_data['recipient_phone'] ?? $post_data['phone'] ?? '');
        $user_limit = (int) ($coupon_row['user_limit'] ?? 1);
        $claim_limit = (int) ($coupon_row['claim_limit'] ?? 1);
        $max_per_user = max(1, $user_limit, $claim_limit);

        if (!empty($guest_email) || !empty($guest_phone)) {
            $orders_table = $tododb->prefix . 'orders';
            $where_parts = array("coupon_id = %d");
            $params = array($c_id);

            $sub_where = array();
            if (!empty($guest_email)) {
                $sub_where[] = "shipping_address LIKE %s";
                $params[] = '%' . $tododb->esc_like($guest_email) . '%';
            }
            if (!empty($guest_phone)) {
                $sub_where[] = "shipping_address LIKE %s";
                $params[] = '%' . $tododb->esc_like($guest_phone) . '%';
            }
            if (!empty($sub_where)) {
                $where_parts[] = "(" . implode(' OR ', $sub_where) . ")";
            }
            $where_parts[] = "status != 'cancelled'";

            $sql_guest_check = $tododb->prepare(
                "SELECT COUNT(*) FROM {$orders_table} WHERE " . implode(' AND ', $where_parts),
                ...$params
            );
            $guest_used_count = (int) $tododb->get_var($sql_guest_check);

            if ($guest_used_count >= $max_per_user) {
                throw new Exception(sprintf(__('优惠券限用 %d 次，您已达上限'), $max_per_user));
            }
        }
    }

    $cart_subtotal = todo_checkout_get_cart_subtotal($cart);
    $c_type = $coupon_row['type'] ?? 1;
    $c_value = floatval($coupon_row['value'] ?? 0);
    $max_discount = floatval($coupon_row['max_discount'] ?? 0);
    $min_amount = floatval($coupon_row['min_amount'] ?? 0);

    if ($min_amount > 0 && $cart_subtotal < $min_amount) {
        throw new Exception(__('订单金额未达到优惠券使用门槛'));
    }

    $calc_discount = 0.0;
    if ($c_type == 2 || $c_type === 'percent') {
        $rate = ($c_value > 1) ? ($c_value / 100) : $c_value;
        if ($rate <= 0 || $rate >= 1) {
            $rate = 0.9;
        }
        $calc_discount = $cart_subtotal * (1 - $rate);
        if ($max_discount > 0 && $calc_discount > $max_discount) {
            $calc_discount = $max_discount;
        }
    } else {
        $calc_discount = $c_value;
    }

    if ($calc_discount > $cart_subtotal) {
        $calc_discount = $cart_subtotal;
    }

    return round(max(0, $calc_discount), 2);
}

/**
 * 服务端校验并计算积分抵扣
 *
 * @param int $user_id
 * @param array $cart
 * @param float $coupon_discount_amount
 * @return float
 */
function todo_checkout_calculate_points_discount($user_id, $cart, $coupon_discount_amount = 0) {
    $user_id = intval($user_id);
    if ($user_id <= 0) {
        return 0.0;
    }

    global $tododb;
    $pts_ratio = (int) (function_exists('get_option') ? get_option('points_to_currency_ratio', 100) : 100);
    if ($pts_ratio <= 0) {
        $pts_ratio = 100;
    }

    $current_user_pts = 0;
    if (class_exists('Points_Manager')) {
        $current_user_pts = Points_Manager::get_instance()->get_balance($user_id);
    } else {
        $current_user_pts = (float) get_user_meta($user_id, '_user_points', true) ?: 0;
    }

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
    $available_pts = max(0, $current_user_pts - $locked_points);
    if ($available_pts <= 0) {
        throw new Exception(__('可用积分不足（包含待支付订单冻结积分）'));
    }

    $max_points_discount = round($available_pts / $pts_ratio, 2);
    $eligible_subtotal = todo_checkout_get_points_eligible_subtotal($cart);
    $remaining_eligible_amount = max(0, $eligible_subtotal - max(0, floatval($coupon_discount_amount)));

    return round(max(0, min($max_points_discount, $remaining_eligible_amount)), 2);
}

/**
 * 验证结算表单数据
 * 
 * @param array $post_data POST数据
 * @return array 验证后的数据
 * @throws Exception
 */
function validate_checkout_form($post_data, $cart = array()) {
    global $tododb;
    $data = array();
    $user_id = get_current_user_id();

    // 严密检查购物车商品库存（单规格/多规格）
    if (!empty($cart['items']) && is_array($cart['items'])) {
        $fields_table = DB_PREFIX . 'model_fields_product';
        foreach ($cart['items'] as $item) {
            $pid = intval($item['product_id'] ?? 0);
            $qty = intval($item['quantity'] ?? 1);
            if ($pid <= 0 || $qty <= 0) continue;

            $p_info = $tododb->get_row("SELECT stock, spec_type, spec_details, sale_status FROM {$fields_table} WHERE post_id = ? LIMIT 1", [$pid]);
            if (!$p_info) {
                throw new Exception(__('商品不存在或已下架'));
            }

            $sale_status = isset($p_info->sale_status) && $p_info->sale_status !== '' ? $p_info->sale_status : 'online';
            if ($sale_status === 'warehouse') {
                throw new Exception(__('商品已下架，无法结算'));
            }
            if (function_exists('todo_is_product_on_sale') && !todo_is_product_on_sale($pid)) {
                throw new Exception(__('商品已下架，无法结算'));
            }

            $specs_arr = $item['specs'] ?? array();
            if ($p_info->spec_type === 'multiple' && !empty($specs_arr)) {
                $details = json_decode($p_info->spec_details ?? '', true);
                if (function_exists('api_orders_match_spec_detail')) {
                    $matched = api_orders_match_spec_detail($details, $specs_arr);
                    if (!$matched) {
                        throw new Exception(__('所选规格不存在'));
                    }
                    $spec_detail = $matched[1];
                    $spec_stock = isset($spec_detail['stock']) ? intval($spec_detail['stock']) : intval($spec_detail['stock_count'] ?? -1);
                    if ($spec_stock >= 0) {
                        if ($spec_stock < $qty) {
                            throw new Exception(__('部分商品规格库存不足'));
                        }
                    } else {
                        if (intval($p_info->stock ?? 0) < $qty) {
                            throw new Exception(__('部分商品库存不足'));
                        }
                    }
                }
            } else {
                if (intval($p_info->stock ?? 0) < $qty) {
                    throw new Exception(__('部分商品库存不足'));
                }
            }
        }
    }

    // 访客邮箱校验：中国大陆/CN 地区可选，非中国大陆国家/地区强制要求有效邮箱
    if (!$user_id) {
        $guest_email = sanitize_email($post_data['guest_email'] ?? '');
        $region_type = trim($post_data['address_region_type'] ?? 'cn');
        $intl_country = strtoupper(trim($post_data['intl_country'] ?? ''));

        $is_cn = ($region_type === 'cn') || ($intl_country === 'CN' || $intl_country === 'CHINA' || $intl_country === '中国');

        if (!$is_cn) {
            // 其他国家/地区：强制要求电子邮箱
            if (empty($guest_email) || !is_email($guest_email)) {
                throw new Exception(__('请提供有效的电子邮箱地址，用于接收订单通知'));
            }
        } else {
            // 中国大陆地区：邮箱为可选项，若填写则校验格式
            if (!empty($guest_email) && !is_email($guest_email)) {
                throw new Exception(__('邮箱格式不正确'));
            }
        }
        $data['guest_email'] = $guest_email;
    }
    
    // 判断整单商品类型单一性，拒绝实物与虚拟混合下单
    $has_physical = false;
    $has_virtual = false;
    if (!empty($cart['items'])) {
        foreach ($cart['items'] as $item) {
            $pid = intval($item['product_id'] ?? 0);
            $pt = $item['product_type'] ?? ($pid > 0 ? get_post_meta($pid, 'product_type', true) : 'physical');
            if ($pt === 'virtual') {
                $has_virtual = true;
            } else {
                $has_physical = true;
            }
        }
    }
    if ($has_physical && $has_virtual) {
        throw new Exception(__('虚拟商品与实物商品不能同时下单，请分开结算'));
    }
    $is_virtual_order = $has_virtual && !$has_physical;
    $data['is_virtual'] = $is_virtual_order;

    if ($is_virtual_order) {
        // 虚拟商品不需要真实收货地址与物理配送方式
        $data['shipping_address'] = array('virtual' => true, 'name' => '', 'phone' => '');
        $data['shipping_method'] = 'virtual';
        
        // 记录虚拟商品真实的交付方式（如 text/download/manual/express）
        $virt_dt = 'text';
        if (!empty($cart['items']) && is_array($cart['items'])) {
            foreach ($cart['items'] as $item) {
                $pid = intval($item['product_id'] ?? 0);
                if ($pid > 0 && !empty($tododb)) {
                    $mfp = $tododb->prefix . 'model_fields_product';
                    $dt_found = $tododb->get_var($tododb->prepare("SELECT delivery_type FROM {$mfp} WHERE post_id = %d LIMIT 1", $pid));
                    if (!empty($dt_found)) {
                        $virt_dt = $dt_found;
                        break;
                    }
                }
            }
        }
        $data['delivery_method'] = $virt_dt;
    } else {
        // 收货地址验证
        if (!empty($post_data['address_id'])) {
            // 使用已有地址
            if (!$user_id) {
                throw new Exception(__('请先登录'));
            }
            
            $user_addresses = get_user_meta($user_id, 'shipping_addresses', true) ?: array();
            $address_index = intval($post_data['address_id']);
            
            if (!isset($user_addresses[$address_index])) {
                throw new Exception(__('选择的地址不存在'));
            }
            
            $data['shipping_address'] = $user_addresses[$address_index];
        } else {
            // 新地址（区分大陆地址与国际地址）
            $region_type = sanitize_text_field($post_data['address_region_type'] ?? 'cn');
            
            if ($region_type === 'intl') {
                $intl_fields = array('recipient_name', 'recipient_phone', 'intl_country', 'intl_state', 'intl_city', 'intl_address', 'intl_zipcode');
                foreach ($intl_fields as $field) {
                    if (empty($post_data[$field])) {
                        throw new Exception(__('请填写完整的国际/港澳台收货地址信息'));
                    }
                }
                
                $country = sanitize_text_field($post_data['intl_country']);
                $state = sanitize_text_field($post_data['intl_state']);
                $data['shipping_address'] = array(
                    'name' => sanitize_text_field($post_data['recipient_name']),
                    'phone' => sanitize_text_field($post_data['recipient_phone']),
                    'country' => $country,
                    'state' => $state,
                    'province' => $country ? ($state ? ($country . ' | ' . $state) : $country) : $state,
                    'city' => sanitize_text_field($post_data['intl_city']),
                    'district' => '',
                    'detail' => sanitize_text_field($post_data['intl_address']),
                    'postal_code' => sanitize_text_field($post_data['intl_zipcode']),
                    'zipcode' => sanitize_text_field($post_data['intl_zipcode'])
                );
            } else {
                $required_fields = array('recipient_name', 'recipient_phone', 'detail_address');
                foreach ($required_fields as $field) {
                    if (empty($post_data[$field])) {
                        throw new Exception(__('请填写完整的收货地址'));
                    }
                }
                
                $province = sanitize_text_field($post_data['province_name'] ?? ($post_data['province'] ?? ($post_data['province_id'] ?? '')));
                $city = sanitize_text_field($post_data['city_name'] ?? ($post_data['city'] ?? ($post_data['city_id'] ?? '')));
                $district = sanitize_text_field($post_data['district_name'] ?? ($post_data['district'] ?? ($post_data['district_id'] ?? '')));
                
                if (function_exists('todo_regions_find_name')) {
                    if (is_numeric($province)) { $p_n = todo_regions_find_name($province); if (!empty($p_n)) $province = $p_n; }
                    if (is_numeric($city)) { $c_n = todo_regions_find_name($city); if (!empty($c_n)) $city = $c_n; }
                    if (is_numeric($district)) { $d_n = todo_regions_find_name($district); if (!empty($d_n)) $district = $d_n; }
                }

                if ($province === '' || $city === '' || $district === '') {
                    throw new Exception(__('请选择完整的省市区县'));
                }

                $data['shipping_address'] = array(
                    'name' => sanitize_text_field($post_data['recipient_name']),
                    'phone' => sanitize_text_field($post_data['recipient_phone']),
                    'province' => $province,
                    'city' => $city,
                    'district' => $district,
                    'detail' => sanitize_text_field($post_data['detail_address'])
                );
            }
            
            // 保存地址
            if (!empty($post_data['save_address']) && get_current_user_id()) {
                save_user_address($data['shipping_address']);
            }
        }
        
        if (!empty($data['guest_email']) && is_array($data['shipping_address'])) {
            $data['shipping_address']['email'] = $data['guest_email'];
            $data['shipping_address']['guest_email'] = $data['guest_email'];
        }
        
        // 配送方式验证
        $valid_shipping_codes = array();
        if (!function_exists('todo_get_shipping_methods')) {
            $settings_file = ABSPATH . 'core/functions/shop-service-settings.php';
            if (file_exists($settings_file)) {
                require_once $settings_file;
            }
        }
        if (function_exists('todo_get_shipping_methods')) {
            $configured_shipping_methods = todo_get_shipping_methods(true);
            foreach ($configured_shipping_methods as $csm) {
                if (!empty($csm['code'])) {
                    $valid_shipping_codes[] = $csm['code'];
                }
            }
        }
        if (empty($valid_shipping_codes)) {
            $valid_shipping_codes = array('express', 'ems', 'self_pickup');
        }
        $shipping_method = $post_data['shipping_method'] ?? '';
        
        if (!in_array($shipping_method, $valid_shipping_codes, true)) {
            throw new Exception(__('请选择有效的配送方式'));
        }
        
        $data['shipping_method'] = $shipping_method;
    }
    
    // 支付方式验证
    $payment_methods = array();
    if (function_exists('todo_get_payment_methods')) {
        $methods_list = todo_get_payment_methods();
        foreach ($methods_list as $m) {
            $payment_methods[$m['code']] = $m;
        }
    } elseif (function_exists('todo_shop_get_service_settings')) {
        $settings = todo_shop_get_service_settings();
        $enabled_methods = isset($settings['payment_methods']) && is_array($settings['payment_methods']) ? $settings['payment_methods'] : array('alipay', 'wechat');
        foreach ($enabled_methods as $code) {
            $payment_methods[$code] = array('id' => $code, 'name' => $code);
        }
    } else {
        $payment_methods = method_exists('Todo_Payment_Manager', 'get_available_methods')
            ? Todo_Payment_Manager::get_available_methods()
            : array();
    }
    
    $payment_method = sanitize_text_field($post_data['payment_method'] ?? '');
    if (empty($payment_method)) {
        throw new Exception(__('请选择有效的支付方式'));
    }
    
    $data['payment_method'] = $payment_method;
    
    // 订单备注与优惠/积分抵扣
    $data['order_note'] = sanitize_textarea_field($post_data['order_note'] ?? '');
    $data['use_points'] = !empty($post_data['use_points']);
    $coupon_code = sanitize_text_field(trim($post_data['coupon_code'] ?? ''));
    $data['discount_amount'] = 0;
    $data['points_discount_amount'] = 0;

    if (!empty($coupon_code)) {
        $data['discount_amount'] = todo_checkout_calculate_coupon_discount($coupon_code, $user_id, $post_data, $cart);
    }

    if ($data['use_points']) {
        if ($user_id <= 0) {
            throw new Exception(__('请登录后使用积分抵扣'));
        }
        $data['points_discount_amount'] = todo_checkout_calculate_points_discount($user_id, $cart, $data['discount_amount']);
    }

    $data['coupon_code'] = $coupon_code;
    return $data;
}

/**
 * 计算订单金额
 * 
 * @param array $cart 购物车数据
 * @param string $shipping_method 配送方式
 * @return array 金额信息
 */
function calculate_order_amounts($cart, $shipping_method, $discount_amount = 0, $is_virtual = false, $points_discount_amount = 0) {
    $subtotal = $cart['total'];
    
    if ($is_virtual) {
        $shipping_fee = 0.00;
    } else {
        // 计算运费
        $shipping_fee = null;
        if (!function_exists('todo_get_shipping_methods')) {
            $settings_file = ABSPATH . 'core/functions/shop-service-settings.php';
            if (file_exists($settings_file)) {
                require_once $settings_file;
            }
        }
        if (function_exists('todo_get_shipping_methods')) {
            $methods = todo_get_shipping_methods(false);
            if (is_array($methods)) {
                foreach ($methods as $m) {
                    if (isset($m['code']) && (string)$m['code'] === (string)$shipping_method && isset($m['fee'])) {
                        $shipping_fee = floatval($m['fee']);
                        break;
                    }
                }
            }
        }
        if ($shipping_fee === null) {
            throw new Exception(__('所选配送方式不存在或费用计算异常'));
        }
    }
    $discount_amount = floatval($discount_amount);
    if ($discount_amount < 0) {
        $discount_amount = 0;
    }
    if ($discount_amount > ($subtotal + $shipping_fee)) {
        $discount_amount = $subtotal + $shipping_fee;
    }

    $points_discount_amount = floatval($points_discount_amount);
    if ($points_discount_amount < 0) {
        $points_discount_amount = 0;
    }
    if ($points_discount_amount > ($subtotal + $shipping_fee - $discount_amount)) {
        $points_discount_amount = $subtotal + $shipping_fee - $discount_amount;
    }
    
    // 计算总金额
    $total = max(0, $subtotal + $shipping_fee - $discount_amount - $points_discount_amount);
    
    return array(
        'subtotal' => $subtotal,
        'shipping_fee' => $shipping_fee,
        'discount_amount' => $discount_amount + $points_discount_amount,
        'coupon_discount_amount' => $discount_amount,
        'points_discount_amount' => $points_discount_amount,
        'total' => $total
    );
}

/**
 * 创建结算订单
 * 
 * @param array $cart 购物车数据
 * @param array $order_data 订单数据
 * @param array $amounts 金额信息
 * @return int|false 订单ID
 */
function create_checkout_order($cart, $order_data, $amounts) {
    global $tododb;
    Todo_Order_Manager::init();

    $user_id = function_exists('get_current_user_id') ? get_current_user_id() : 0;
    
    // 获取当前语言环境下的汇率比值，将提交订单的金额转为基础主货币保存
    $ratio = 1.0;
    if (function_exists('todo_get_currency_config') && function_exists('todo_get_default_currency_config')) {
        $currency_cfg = todo_get_currency_config();
        $default_cfg = todo_get_default_currency_config();
        $curr_rate = ($currency_cfg['status'] == 1 && floatval($currency_cfg['rate']) > 0) ? floatval($currency_cfg['rate']) : 1.0;
        $base_rate = floatval($default_cfg['rate']) > 0 ? floatval($default_cfg['rate']) : 1.0;
        $ratio = $curr_rate / $base_rate;
    }
    if ($ratio <= 0) {
        $ratio = 1.0;
    }

    $total_amount_base = round(floatval($amounts['total'] ?? 0), 4);
    $shipping_fee_base = round(floatval($amounts['shipping_fee'] ?? 0), 4);
    $discount_amount_base = round(floatval($amounts['discount_amount'] ?? 0), 4);

    // 准备订单数据
    $order_info = array(
        'user_id' => $user_id,
        'guest_email' => $order_data['guest_email'] ?? '',
        'custom_fields' => array(
            'guest_email' => $order_data['guest_email'] ?? '',
            '_guest_email' => $order_data['guest_email'] ?? '',
            'contact_email' => $order_data['guest_email'] ?? ''
        ),
        'order_type' => 'product',
        'total_amount' => $total_amount_base,
        'shipping_fee' => $shipping_fee_base,
        'discount_amount' => $discount_amount_base,
        'coupon_code' => $order_data['coupon_code'] ?? '',
        'payment_method' => $order_data['payment_method'],
        'shipping_method' => $order_data['shipping_method'],
        'shipping_address' => json_encode($order_data['shipping_address'], JSON_UNESCAPED_UNICODE),
        'notes' => $order_data['order_note'] ?? '',
        'order_note' => $order_data['order_note'] ?? '',
        'remark' => $order_data['order_note'] ?? '',
        'status' => Todo_Order_Manager::STATUS_PENDING,
        'payment_status' => Todo_Order_Manager::PAYMENT_PENDING
    );
    
    // 准备订单项目
    $order_items = array();
    foreach ($cart['items'] as $item) {
        $spec_name_text = '';
        if (!empty($item['specs']) && function_exists('todo_format_specs_text')) {
            $spec_name_text = todo_format_specs_text($item['specs'], intval($item['product_id'] ?? 0));
        }
        $product_image = isset($item['image']) ? (string) $item['image'] : '';
        if ($product_image === '' && !empty($item['product_id']) && function_exists('get_product_thumbnail')) {
            $product_image = (string) get_product_thumbnail(intval($item['product_id']));
        }
        if ($product_image !== '' && function_exists('todo_media_url_to_relative')) {
            $product_image = todo_media_url_to_relative($product_image);
        }
        $p_title = $item['title'] ?? ($item['product_title'] ?? '');
        $u_price_base = round(floatval($item['price'] ?? 0), 4);
        $t_price_base = round(floatval($item['quantity'] ?? 1) * $u_price_base, 4);

        $order_items[] = array(
            'product_id'    => $item['product_id'],
            'product_title' => $p_title,
            'product_name'  => $p_title,
            'product_image' => $product_image,
            'spec_name'     => $spec_name_text,
            'specs_text'    => $spec_name_text,
            'product_specs' => !empty($item['specs']) ? json_encode($item['specs'], JSON_UNESCAPED_UNICODE) : '',
            'quantity'      => $item['quantity'],
            'unit_price'    => $u_price_base,
            'total_price'   => $t_price_base
        );
    }
    
    // 创建订单
    // 将订单商品数组写入 $order_info，确保 create_order 能正确计算与保存订单金额
    $order_info['items'] = $order_items;
    $order_id = Todo_Order_Manager::create_order($order_info);

    if ($order_id) {
        // 扣减购物车商品的实际库存（兼容单规格与多规格）
        if (!empty($cart['items']) && is_array($cart['items'])) {
            $fields_table = DB_PREFIX . 'model_fields_product';
            foreach ($cart['items'] as $item) {
                $pid = intval($item['product_id'] ?? 0);
                $qty = intval($item['quantity'] ?? 1);
                if ($pid <= 0 || $qty <= 0) continue;

                $p_info = $tododb->get_row("SELECT stock, spec_type, spec_details FROM {$fields_table} WHERE post_id = ? LIMIT 1", [$pid]);
                if (!$p_info) continue;

                $specs_arr = $item['specs'] ?? array();
                if ($p_info->spec_type === 'multiple' && !empty($specs_arr) && !empty($p_info->spec_details)) {
                    $details = json_decode($p_info->spec_details, true);
                    if (function_exists('api_orders_match_spec_detail') && function_exists('api_orders_adjust_spec_detail_stock')) {
                        $matched = api_orders_match_spec_detail($details, $specs_arr);
                        if ($matched) {
                            $idx = $matched[0];
                            api_orders_adjust_spec_detail_stock($pid, $idx, -$qty, $fields_table, (string)$p_info->spec_details);
                        }
                    }
                }

                if (function_exists('api_orders_adjust_product_inventory')) {
                    $req_stk = ($p_info->spec_type === 'multiple' && intval($p_info->stock ?? 0) <= 0) ? false : true;
                    api_orders_adjust_product_inventory($pid, -$qty, $qty, $fields_table, $req_stk);
                } else {
                    $tododb->query("UPDATE {$fields_table} SET stock = GREATEST(0, stock - ?), actual_sales = actual_sales + ? WHERE post_id = ?", [$qty, $qty, $pid]);
                }
            }
        }

        $pts_discount = floatval($amounts['points_discount_amount'] ?? 0);
        if ($pts_discount > 0 && $user_id > 0) {
            $pts_ratio = (int) (function_exists('get_option') ? get_option('points_to_currency_ratio', 100) : 100);
            if ($pts_ratio <= 0) $pts_ratio = 100;
            $pts_discount_base = round($pts_discount, 4);
            $pts_used = (int) round($pts_discount_base * $pts_ratio);
            Todo_Order_Manager::save_order_custom_field($order_id, '_points_used', $pts_used);
            Todo_Order_Manager::save_order_custom_field($order_id, '_points_discount_amount', $pts_discount_base);
        }

        if (!empty($order_info['coupon_code'])) {
            global $tododb;
            $coupons_table = $tododb->prefix . 'coupons';
            $user_coupons_table = $tododb->prefix . 'user_coupons';
            
            $sql_c = $tododb->prepare("SELECT id FROM {$coupons_table} WHERE code = %s LIMIT 1", $order_info['coupon_code']);
            $coupon_id = (int) $tododb->get_var($sql_c);
            
            if ($coupon_id > 0) {
                // 保存订单关联的优惠券ID与优惠券码至订单 Meta
                Todo_Order_Manager::save_order_custom_field($order_id, 'coupon_id', $coupon_id);
                Todo_Order_Manager::save_order_custom_field($order_id, 'coupon_code', $order_info['coupon_code']);

                // 增加全局使用计数 used_count
                $tododb->query($tododb->prepare("UPDATE {$coupons_table} SET used_count = used_count + 1 WHERE id = %d", $coupon_id));

                // 如果为登录用户，将优惠券状态设为已使用/锁卡并记录订单ID
                if ($user_id > 0) {
                    $sql_uc = $tododb->prepare(
                        "SELECT id FROM {$user_coupons_table} WHERE coupon_id = %d AND user_id = %d AND status = 'unused' LIMIT 1",
                        $coupon_id, $user_id
                    );
                    $uc_id = (int) $tododb->get_var($sql_uc);
                    if ($uc_id > 0) {
                        $tododb->update($user_coupons_table, [
                            'status' => 'used',
                            'used_at' => current_time('mysql'),
                            'order_id' => $order_id
                        ], ['id' => $uc_id]);
                    } else {
                        // 若用户之前未领取该公开券，自动为其落库生成已使用卡券记录
                        $tododb->insert($user_coupons_table, [
                            'coupon_id' => $coupon_id,
                            'user_id' => $user_id,
                            'status' => 'used',
                            'source' => 'redeem',
                            'assigned_at' => current_time('mysql'),
                            'used_at' => current_time('mysql'),
                            'order_id' => $order_id
                        ]);
                    }
                }
            }
        }
    }

    return $order_id;
}

/**
 * 处理支付
 * 
 * @param int $order_id 订单ID
 * @param string $payment_method 支付方式
 * @param float $amount 支付金额
 * @return array 支付结果
 */
function process_payment($order_id, $payment_method, $amount) {
    try {
        // 获取订单信息
        $order = Todo_Order_Manager::get_order($order_id);
        if (!$order) {
            throw new Exception(__('订单不存在'));
        }
        
        // 映射支付方式到支付管理器的内部ID
        $manager_method_id = $payment_method;
        if ($payment_method === 'alipay' || $payment_method === 'wechat') {
            $manager_method_id = 'payjs_' . $payment_method;
        }

        // 创建支付
        $payment_result = Todo_Payment_Manager::create_payment(
            $order_id,
            $manager_method_id,
            array(
                'order_no' => $order['order_no'],
                'total_amount' => $amount,
                'user_id' => $order['user_id'],
                'description' => '订单支付 - ' . $order['order_no']
            )
        );
        
        if (!$payment_result['success']) {
            throw new Exception($payment_result['message']);
        }
        
        return $payment_result;
        
    } catch (Exception $e) {
        error_log('[TodoCMS Checkout] 支付网关初始化失败: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * 保存用户地址
 * 
 * @param array $address 地址信息
 */
function save_user_address($address) {
    $user_id = get_current_user_id();
    if (!$user_id) {
        return;
    }
    
    global $tododb;
    $table = $tododb->prefix . 'member_addresses';

    $name = sanitize_text_field($address['name'] ?? '');
    $phone = sanitize_text_field($address['phone'] ?? '');
    
    $country = sanitize_text_field($address['country'] ?? '');
    $state = sanitize_text_field($address['state'] ?? '');
    if (!empty($country)) {
        $province = $country . ($state ? ' | ' . $state : '');
    } else {
        $province = sanitize_text_field($address['province'] ?? '');
    }

    $city = sanitize_text_field($address['city'] ?? '');
    $district = sanitize_text_field($address['district'] ?? '');
    $detail = sanitize_textarea_field($address['detail'] ?? ($address['address'] ?? ''));
    $zipcode = sanitize_text_field($address['postal_code'] ?? ($address['zipcode'] ?? ''));

    if (!empty($name) && !empty($phone) && !empty($detail)) {
        $existing_id = (int) ($tododb->get_var(
            $tododb->prepare(
                "SELECT id FROM {$table} WHERE user_id = %d AND name = %s AND phone = %s AND province = %s AND city = %s AND address = %s LIMIT 1",
                $user_id, $name, $phone, $province, $city, $detail
            )
        ) ?: 0);

        if ($existing_id <= 0) {
            $has_default = (int) ($tododb->get_var($tododb->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_default = 1", $user_id)) ?: 0);
            $is_default = ($has_default === 0) ? 1 : 0;

            $tododb->insert(
                $table,
                [
                    'user_id' => $user_id,
                    'name' => $name,
                    'phone' => $phone,
                    'province' => $province,
                    'city' => $city,
                    'district' => $district,
                    'address' => $detail,
                    'zipcode' => $zipcode,
                    'tag' => __('家'),
                    'is_default' => $is_default,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ]
            );
        }
    }

    $user_addresses = get_user_meta($user_id, 'shipping_addresses', true) ?: array();
    $address_key = md5(json_encode($address));
    $exists = false;
    
    foreach ($user_addresses as $existing_address) {
        if (md5(json_encode($existing_address)) === $address_key) {
            $exists = true;
            break;
        }
    }
    
    if (!$exists) {
        $user_addresses[] = $address;
        update_user_meta($user_id, 'shipping_addresses', $user_addresses);
    }
}
