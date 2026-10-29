<?php
/**
 * TodoCMS 优惠券结算校验与计算 AJAX 端点
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

// 检查请求头
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    todo_send_json_error('Direct access denied');
}

header('Content-Type: application/json; charset=utf-8');

function todo_coupon_get_checkout_subtotal($post_data) {
    $is_buy_now = isset($post_data['buy_now_mode']) && (string) $post_data['buy_now_mode'] === '1';
    if ($is_buy_now) {
        $product_id = intval($post_data['buy_now_product_id'] ?? 0);
        $quantity = max(1, intval($post_data['buy_now_quantity'] ?? 1));
        $specs_json = (string) ($post_data['buy_now_specs'] ?? '{}');
        $specs = json_decode(stripslashes($specs_json), true);
        if (!is_array($specs)) {
            $specs = array();
        }

        if ($product_id <= 0) {
            throw new Exception(__('商品信息无效'));
        }

        $product_info = Todo_Cart_Manager::get_product_info($product_id, $specs);
        if (!$product_info) {
            throw new Exception(__('商品不存在或已下架'));
        }

        return round(max(0, floatval($product_info['price'] ?? 0) * $quantity), 2);
    }

    $cart = Todo_Cart_Manager::get_cart_details();
    if (empty($cart['items'])) {
        throw new Exception(__('购物车为空'));
    }

    $selected_param = trim($post_data['selected'] ?? $_REQUEST['selected'] ?? '');
    if (!empty($selected_param)) {
        $selected_keys = array_map('trim', explode(',', $selected_param));
        $filtered_tot = 0;
        foreach ($cart['items'] as $ck => $ci) {
            if (in_array((string)$ck, $selected_keys, true) || in_array((string)($ci['product_id'] ?? ''), $selected_keys, true)) {
                $q = intval($ci['quantity'] ?? 1);
                $p = floatval($ci['price'] ?? 0);
                $filtered_tot += $q * $p;
            }
        }
        if ($filtered_tot > 0) {
            return round(max(0, $filtered_tot), 2);
        }
    }

    return round(max(0, floatval($cart['total'] ?? 0)), 2);
}

// PRG 防重复提交守卫（仅对携带 _submit_key 的 POST 生效）
Todo_PRG_Guard::guard_endpoint('coupon_handler', 30);

try {
    $action = $_POST['action'] ?? '';
    if ($action !== 'apply_coupon') {
        throw new Exception(__('无效的操作'));
    }

    $code = sanitize_text_field(trim($_POST['coupon_code'] ?? ''));
    $subtotal = todo_coupon_get_checkout_subtotal($_POST);

    if (empty($code)) {
        throw new Exception(__('请输入优惠券兑换码'));
    }

    global $tododb;
    $coupons_table = $tododb->prefix . 'coupons';
    $user_coupons_table = $tododb->prefix . 'user_coupons';
    $user_id = function_exists('get_current_user_id') ? get_current_user_id() : 0;

    // 1. 查找对应的优惠券定义
    $sql_c = $tododb->prepare("SELECT * FROM {$coupons_table} WHERE code = %s LIMIT 1", $code);
    $coupon = $tododb->get_row($sql_c, [], 'ARRAY_A');

    if (!$coupon) {
        throw new Exception(__('优惠券码无效或不存在'));
    }

    $c_status = is_array($coupon) ? ($coupon['status'] ?? 0) : (is_object($coupon) ? ($coupon->status ?? 0) : 0);
    if (empty($c_status)) {
        throw new Exception(__('该优惠券已被禁用'));
    }

    $now_ts = current_time('timestamp');
    $start_date = is_array($coupon) ? ($coupon['start_date'] ?? null) : (is_object($coupon) ? ($coupon->start_date ?? null) : null);
    $end_date = is_array($coupon) ? ($coupon['end_date'] ?? null) : (is_object($coupon) ? ($coupon->end_date ?? null) : null);
    $start_ts = !empty($start_date) ? strtotime($start_date) : 0;
    $end_ts = !empty($end_date) ? strtotime($end_date) : 0;

    if ($start_ts > 0 && $now_ts < $start_ts) {
        throw new Exception(__('该优惠券活动尚未开始'));
    }
    if ($end_ts > 0 && $now_ts > $end_ts) {
        throw new Exception(__('该优惠券已过期'));
    }

    $coupon_id = is_array($coupon) ? intval($coupon['id'] ?? 0) : (is_object($coupon) ? intval($coupon->id ?? 0) : 0);
    $min_amount = is_array($coupon) ? floatval($coupon['min_amount'] ?? 0) : (is_object($coupon) ? floatval($coupon->min_amount ?? 0) : 0);
    $c_type = is_array($coupon) ? ($coupon['type'] ?? 1) : (is_object($coupon) ? ($coupon->type ?? 1) : 1);
    $c_value = is_array($coupon) ? floatval($coupon['value'] ?? 0) : (is_object($coupon) ? floatval($coupon->value ?? 0) : 0);
    $max_discount = is_array($coupon) ? floatval($coupon['max_discount'] ?? 0) : (is_object($coupon) ? floatval($coupon->max_discount ?? 0) : 0);
    $coupon_name = is_array($coupon) ? ($coupon['name'] ?? '') : (is_object($coupon) ? ($coupon->name ?? '') : '');

    // 2. 门槛检测
    $min_amount_active = function_exists('todo_apply_currency_exchange') ? todo_apply_currency_exchange($min_amount) : $min_amount;
    if ($min_amount_active > 0 && $subtotal < $min_amount_active) {
        $min_fmt = function_exists('todo_format_price') ? todo_format_price($min_amount) : number_format($min_amount, 2);
        throw new Exception(sprintf(__('该优惠券需满 %s 才能使用'), $min_fmt));
    }

    // 3. 优惠券领取类型与用户归属权限强校验
    $issue_mode = is_array($coupon) ? ($coupon['issue_mode'] ?? 'redeem') : (is_object($coupon) ? ($coupon->issue_mode ?? 'redeem') : 'redeem');
    $user_limit = is_array($coupon) ? intval($coupon['user_limit'] ?? 1) : (is_object($coupon) ? intval($coupon->user_limit ?? 1) : 1);
    $claim_limit = is_array($coupon) ? intval($coupon['claim_limit'] ?? 1) : (is_object($coupon) ? intval($coupon->claim_limit ?? 1) : 1);
    $max_per_user = max(1, $user_limit, $claim_limit);

    if ($user_id > 0) {
        $sql_uc = $tododb->prepare(
            "SELECT * FROM {$user_coupons_table} WHERE coupon_id = %d AND user_id = %d AND status = 'unused' LIMIT 1",
            $coupon_id, $user_id
        );
        $uc = $tododb->get_row($sql_uc, [], 'ARRAY_A');
        if (!$uc) {
            // 如果为公开兑换券 (redeem)，判断该用户是否已达到最大使用/领取次数
            if ($issue_mode === 'redeem') {
                $sql_used_count = $tododb->prepare(
                    "SELECT COUNT(*) FROM {$user_coupons_table} WHERE coupon_id = %d AND user_id = %d",
                    $coupon_id, $user_id
                );
                $used_by_user = (int) $tododb->get_var($sql_used_count);
                if ($used_by_user >= $max_per_user) {
                    throw new Exception(sprintf(__('该优惠券每人限用/限领 %d 次，您已达到上限'), $max_per_user));
                }
            } else {
                $sql_transferred = $tododb->prepare(
                    "SELECT id FROM {$user_coupons_table} WHERE coupon_id = %d AND (from_user_id = %d OR (user_id != %d AND order_id IS NULL)) LIMIT 1",
                    $coupon_id, $user_id, $user_id
                );
                $has_transferred = $tododb->get_var($sql_transferred);
                if ($has_transferred) {
                    throw new Exception(__('该优惠券已转赠给他人或不再属于您，无法兑换'));
                }

                throw new Exception(__('您名下没有可用的该优惠券（已被使用或已转赠）'));
            }
        }
    } else {
        // 游客模式下：只允许兑换全网公开兑换券 (issue_mode = 'redeem')
        if ($issue_mode !== 'redeem') {
            throw new Exception(__('该优惠券为会员专属发放券，请先登录账户后在您的优惠券列表中使用'));
        }

        // 国际标准防刷：检查输入的游客邮箱/手机号是否已达到该公开券的单人最大使用次数
        $guest_email = sanitize_email($_POST['guest_email'] ?? $_POST['email'] ?? '');
        $guest_phone = sanitize_text_field($_POST['recipient_phone'] ?? $_POST['phone'] ?? '');

        if (!empty($guest_email) || !empty($guest_phone)) {
            $orders_table = $tododb->prefix . 'orders';
            $where_parts = ["coupon_id = %d"];
            $params = [$coupon_id];

            $sub_where = [];
            if (!empty($guest_email)) {
                $sub_where[] = "shipping_address LIKE %s";
                $params[] = '%' . $tododb->esc_like($guest_email) . '%';
            }
            if (!empty($guest_phone)) {
                $sub_where[] = "shipping_address LIKE %s";
                $params[] = '%' . $tododb->esc_like($guest_phone) . '%';
            }
            $where_parts[] = "(" . implode(' OR ', $sub_where) . ")";
            $where_parts[] = "status != 'cancelled'";

            $sql_guest_check = $tododb->prepare(
                "SELECT COUNT(*) FROM {$orders_table} WHERE " . implode(' AND ', $where_parts),
                ...$params
            );
            $guest_used_count = (int) $tododb->get_var($sql_guest_check);

            if ($guest_used_count >= $max_per_user) {
                throw new Exception(sprintf(__('该优惠券每个邮箱/手机号限用 %d 次，您已达到上限'), $max_per_user));
            }
        }
    }

    // 4. 计算抵扣金额
    $discount = 0;
    if ($c_type == 2 || $c_type === 'percent') {
        $rate = ($c_value > 1) ? ($c_value / 100) : $c_value;
        if ($rate <= 0 || $rate >= 1) {
            $rate = 0.9;
        }
        $discount = $subtotal * (1 - $rate);
        if ($max_discount > 0 && $discount > $max_discount) {
            $discount = $max_discount;
        }
    } else {
        $discount = $c_value;
    }

    if ($discount > $subtotal) {
        $discount = $subtotal;
    }

    $discount = round(max(0, $discount), 2);

    $disc_fmt = function_exists('todo_format_price') ? todo_format_price($discount) : number_format($discount, 2);
    $display_discount = function_exists('todo_apply_currency_exchange') ? todo_apply_currency_exchange($discount) : $discount;
    $display_discount_format = function_exists('todo_format_price') ? todo_format_price($discount, 'number') : number_format($display_discount, 2, '.', '');

    todo_send_json_success([
        'code' => $code,
        'coupon_id' => $coupon_id,
        'coupon_name' => $coupon_name,
        'discount' => $discount,
        'discount_format' => number_format($discount, 2, '.', ''),
        'display_discount' => $display_discount,
        'display_discount_format' => $display_discount_format,
        'message' => sprintf(__('优惠券兑换成功，立减 %s'), $disc_fmt)
    ]);

} catch (Exception $e) {
    todo_send_json_error($e->getMessage());
}
