<?php
/**
 * 订单详情编辑页面
 * 
 * @package TodoCMS
 * @subpackage Admin
 */

// 防止直接访问
if (!defined('TODO_LOADED')) {
    exit('Direct access denied');
}

// 权限检查
if (!current_user_can('manage_orders')) {
    todo_admin_die('您没有权限访问此页面');
}

global $tododb;

// 获取订单ID
$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$order_id) {
    todo_admin_die('无效的订单ID');
}

// 初始化订单管理器
Todo_Order_Manager::init();

// 获取订单信息
$order = Todo_Order_Manager::get_order($order_id, true);
if (!$order) {
    todo_admin_die('订单不存在');
}

$existing_express_company = (string) ($order['express_company'] ?? '');
$existing_tracking_no = (string) ($order['tracking_no'] ?? $order['tracking_number'] ?? '');
$current_logistics_info = null;

if ($existing_tracking_no !== '') {
    $current_logistics_info = Todo_Order_Manager::get_logistics_info($existing_tracking_no, $existing_express_company, [
        'order_id' => $order_id,
        'force'    => false,
        'persist'  => true,
    ]);
    if (!empty($current_logistics_info['success']) && !empty($current_logistics_info['traces'])) {
        $formatted_text = Todo_Order_Manager::format_logistics_traces_text($current_logistics_info['traces']);
        if (empty($order['tracking_info']) && $formatted_text !== '') {
            $order['tracking_info'] = $formatted_text;
            $tododb->update($tododb->prefix . 'orders', array('tracking_info' => $formatted_text), array('id' => $order_id));
        }
    }
}

$customer_email = !empty($order['user_email']) ? $order['user_email'] : (!empty($order['guest_email']) ? $order['guest_email'] : (!empty($order['shipping_address_data']['email']) ? $order['shipping_address_data']['email'] : (!empty($order['billing_address_data']['email']) ? $order['billing_address_data']['email'] : ($order['email'] ?? ''))));

if (!function_exists('todo_get_service_payment_methods')) {
    if (class_exists('Todo_Function_Loader')) {
        Todo_Function_Loader::load_function('shop-service-settings');
    } else {
        $p = defined('ABSPATH') ? ABSPATH . 'core/functions/shop-service-settings.php' : '';
        if ($p && file_exists($p)) {
            require_once $p;
        }
    }
}
$payment_methods_for_select = function_exists('todo_get_service_payment_methods')
    ? todo_get_service_payment_methods()
    : array();


if (!function_exists('todo_get_service_express_companies')) {
    if (class_exists('Todo_Function_Loader')) {
        Todo_Function_Loader::load_function('shop-service-settings');
    } else {
        $p = defined('ABSPATH') ? ABSPATH . 'core/functions/shop-service-settings.php' : '';
        if ($p && file_exists($p)) {
            require_once $p;
        }
    }
}
$express_companies_for_select = function_exists('todo_get_service_express_companies')
    ? todo_get_service_express_companies(ARRAY_A)
    : array();


$product_title_map = [];
if (!empty($order['items']) && is_array($order['items'])) {
    $product_ids = [];
    foreach ($order['items'] as $it) {
        if (is_array($it) && isset($it['product_id'])) {
            $pid = intval($it['product_id']);
            if ($pid > 0) {
                $product_ids[$pid] = true;
            }
        }
    }
    if (!empty($product_ids)) {
        $ids = array_keys($product_ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $tododb->get_results(
            "SELECT ID, post_title FROM {$tododb->prefix}posts WHERE ID IN ($placeholders)",
            $ids,
            'ARRAY_A'
        );
        if (!empty($rows)) {
            foreach ($rows as $r) {
                $rid = intval($r['ID'] ?? 0);
                if ($rid > 0) {
                    $product_title_map[$rid] = (string) ($r['post_title'] ?? '');
                }
            }
        }
    }
}

// 查询该订单使用的优惠明细（优惠券券码/名称、积分抵扣等）
$order_discounts = [];

// 1. 查询优惠券 (user_coupons & coupons 或 coupon_usage)
if (!empty($tododb)) {
    $user_coupons_table = $tododb->prefix . 'user_coupons';
    $coupons_table = $tododb->prefix . 'coupons';
    $coupon_usage_table = $tododb->prefix . 'coupon_usage';

    // 优先尝试通过 user_coupons 关联 coupons 查询
    if (method_exists($tododb, 'table_exists') ? $tododb->table_exists('user_coupons') : true) {
        $sql = "SELECT uc.*, c.name AS coupon_name, c.code AS coupon_code, c.value AS coupon_value, c.type AS coupon_type 
                FROM {$user_coupons_table} uc 
                LEFT JOIN {$coupons_table} c ON uc.coupon_id = c.id 
                WHERE uc.order_id = ?";
        $coupon_rows = $tododb->get_results($sql, [$order_id], 'ARRAY_A');
        if (!empty($coupon_rows) && is_array($coupon_rows)) {
            foreach ($coupon_rows as $cr) {
                $c_name = !empty($cr['coupon_name']) ? $cr['coupon_name'] : '优惠券';
                $c_code = !empty($cr['coupon_code']) ? $cr['coupon_code'] : '';
                $label = '优惠券: ' . $c_name . ($c_code ? ' (' . $c_code . ')' : '');
                $order_discounts[] = [
                    'type' => 'coupon',
                    'label' => $label,
                    'amount' => 0 // 实际打折金额在订单的 discount_amount 中
                ];
            }
        }
    }

    // 若没有查到，再尝试从 coupon_usage 表查询
    if (empty($order_discounts) && (method_exists($tododb, 'table_exists') ? $tododb->table_exists('coupon_usage') : true)) {
        $sql = "SELECT cu.*, c.name AS coupon_name, c.code AS coupon_code 
                FROM {$coupon_usage_table} cu 
                LEFT JOIN {$coupons_table} c ON cu.coupon_id = c.id 
                WHERE cu.order_id = ?";
        $usage_rows = $tododb->get_results($sql, [$order_id], 'ARRAY_A');
        if (!empty($usage_rows) && is_array($usage_rows)) {
            foreach ($usage_rows as $ur) {
                $c_name = !empty($ur['coupon_name']) ? $ur['coupon_name'] : '优惠券';
                $c_code = !empty($ur['coupon_code']) ? $ur['coupon_code'] : '';
                $label = '优惠券: ' . $c_name . ($c_code ? ' (' . $c_code . ')' : '');
                $order_discounts[] = [
                    'type' => 'coupon',
                    'label' => $label,
                    'amount' => floatval($ur['discount_amount'] ?? 0)
                ];
            }
        }
    }

    // 2. 查询积分抵扣 (user_points_log)
    $points_log_table = $tododb->prefix . 'user_points_log';
    if (method_exists($tododb, 'table_exists') ? $tododb->table_exists('user_points_log') : true) {
        $sql = "SELECT * FROM {$points_log_table} WHERE user_id = ? AND (action = 'order_deduct' OR action = 'order_pay' OR description LIKE ?) AND description LIKE ?";
        $points_params = [
            $order['user_id'] ?? 0,
            '%' . $order_id . '%',
            '%' . ($order['order_no'] ?? $order_id) . '%'
        ];
        $points_rows = $tododb->get_results($sql, $points_params, 'ARRAY_A');
        if (!empty($points_rows) && is_array($points_rows)) {
            foreach ($points_rows as $pr) {
                $pts = abs(floatval($pr['points'] ?? $pr['amount'] ?? 0));
                $desc = !empty($pr['description']) ? $pr['description'] : '积分抵扣';
                $order_discounts[] = [
                    'type' => 'points',
                    'label' => '积分抵扣: ' . $pts . ' 积分 (' . $desc . ')',
                    'amount' => 0
                ];
            }
        }
    }
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_order'])) {
    // CSRF验证
    if (!todo_verify_nonce($_POST['_todononce'], 'update_order_' . $order_id)) {
        todo_admin_die('安全验证失败');
    }
    
    $update_data = array();
    
    // 可更新的字段
    $updatable_fields = array(
        'status', 'payment_status', 'delivery_method', 'payment_method',
        'shipping_address', 'tracking_no', 'express_company', 'tracking_info', 'notes', 'admin_notes',
        'total_amount', 'shipping_fee', 'discount_amount'
    );
    
    foreach ($updatable_fields as $field) {
        if (isset($_POST[$field])) {
            if (in_array($field, ['notes', 'admin_notes', 'tracking_info'])) {
                $update_data[$field] = sanitize_textarea_field($_POST[$field]);
            } else {
                $update_data[$field] = sanitize_text_field($_POST[$field]);
            }
        }
    }
    
    // 特殊处理地址字段
    if (isset($_POST['shipping_address_data']) && is_array($_POST['shipping_address_data'])) {
        $existing_address = json_decode($order['shipping_address'] ?? '', true);
        if (!is_array($existing_address)) {
            $existing_address = array();
        }
        $address_data = $existing_address;
        foreach ($_POST['shipping_address_data'] as $key => $value) {
            $address_data[$key] = sanitize_text_field($value);
        }
        $update_data['shipping_address'] = json_encode($address_data, JSON_UNESCAPED_UNICODE);
    }
    
    // 物流单号自动连动与状态防倒退保护及自动拉取最新轨迹
    $posted_tracking_no = $update_data['tracking_no'] ?? '';
    $existing_tracking_no = $order['tracking_no'] ?? $order['tracking_number'] ?? '';
    $has_tracking_no = !empty($posted_tracking_no) || !empty($existing_tracking_no);
    $current_status = $order['status'] ?? 'pending';
    $posted_status = $update_data['status'] ?? $current_status;

    if ($has_tracking_no) {
        $target_tn = !empty($posted_tracking_no) ? $posted_tracking_no : $existing_tracking_no;
        $target_ec = $update_data['express_company'] ?? $order['express_company'] ?? '';
        $auto_logistics = Todo_Order_Manager::get_logistics_info($target_tn, $target_ec, [
            'order_id' => $order_id,
            'force'    => true,
            'persist'  => true,
        ]);
        if (!empty($auto_logistics['success']) && !empty($auto_logistics['traces'])) {
            $formatted_text = Todo_Order_Manager::format_logistics_traces_text($auto_logistics['traces']);
            if ($formatted_text !== '' && (empty($update_data['tracking_info']) || trim($update_data['tracking_info']) === '')) {
                $update_data['tracking_info'] = $formatted_text;
            }
        }

        // 1. 若已填写/提交快递单号，且当前或提交状态为待发货(processing/paid)，自动连动升级为已发货(shipped)
        if (in_array($posted_status, ['paid', 'processing', 'pending'], true) && in_array($current_status, ['paid', 'processing', 'pending', 'shipped'], true)) {
            $update_data['status'] = 'shipped';
        }
        // 2. 防倒退保护：如果数据库中当前订单状态已经是已发货或以上（shipped/delivered/completed），
        //    而旧页面表单提交的是未刷新的状态 (processing/paid)，禁止倒退状态
        elseif (in_array($current_status, ['shipped', 'delivered', 'completed'], true) && in_array($posted_status, ['paid', 'processing', 'pending'], true)) {
            $update_data['status'] = $current_status;
        }
    }

    // 更新订单
    if (Todo_Order_Manager::update_order($order_id, $update_data, get_current_user_id())) {
        $redirect_url = todo_admin_url('index.php?page=todo-order-edit&id=' . $order_id . '&updated=1');
        if (!headers_sent()) {
            header('Location: ' . $redirect_url);
            exit;
        } else {
            echo '<script type="text/javascript">location.href=' . json_encode($redirect_url) . ';</script>';
            exit;
        }
    } else {
        $error_message = '订单更新失败';
    }
}

// 处理添加支付记录
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_payment'])) {
    if (!todo_verify_nonce($_POST['_todononce'], 'add_payment_' . $order_id)) {
        todo_admin_die('安全验证失败');
    }
    
    $payment_data = array(
        'order_id' => $order_id,
        'amount' => floatval($_POST['payment_amount'] ?? 0),
        'payment_method' => sanitize_text_field($_POST['payment_method'] ?? ''),
        'method' => sanitize_text_field($_POST['payment_method'] ?? ''),
        'transaction_id' => sanitize_text_field($_POST['transaction_id'] ?? ''),
        'payment_status' => 'paid',
        'notes' => sanitize_text_field($_POST['payment_notes'] ?? '')
    );
    
    if (Todo_Order_Manager::add_payment_record($order_id, $payment_data)) {
        $redirect_url = todo_admin_url('index.php?page=todo-order-edit&id=' . $order_id . '&updated=payment_added');
        if (!headers_sent()) {
            header('Location: ' . $redirect_url);
            exit;
        } else {
            echo '<script type="text/javascript">location.href=' . json_encode($redirect_url) . ';</script>';
            exit;
        }
    } else {
        $error_message = '支付记录添加失败';
    }
}

// 处理添加发货记录
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_shipping'])) {
    if (!todo_verify_nonce($_POST['_todononce'], 'add_shipping_' . $order_id)) {
        todo_admin_die('安全验证失败');
    }
    
    $shipping_data = array(
        'order_id' => $order_id,
        'express_company' => sanitize_text_field($_POST['shipping_company'] ?? $_POST['express_company'] ?? ''),
        'company' => sanitize_text_field($_POST['shipping_company'] ?? $_POST['express_company'] ?? ''),
        'tracking_no' => sanitize_text_field($_POST['tracking_no']),
        'notes' => sanitize_text_field($_POST['shipping_notes'])
    );
    
    if (Todo_Order_Manager::add_shipping_record($order_id, $shipping_data)) {
        // 更新订单状态为已发货（update_order 内部已防重保护并触发发货通知）
        Todo_Order_Manager::update_order($order_id, array(
            'status' => 'shipped',
            'express_company' => $shipping_data['express_company'],
            'tracking_no' => $shipping_data['tracking_no']
        ), get_current_user_id());

        $redirect_url = todo_admin_url('index.php?page=todo-order-edit&id=' . $order_id . '&updated=shipping_added');
        if (!headers_sent()) {
            header('Location: ' . $redirect_url);
            exit;
        } else {
            echo '<script type="text/javascript">location.href=' . json_encode($redirect_url) . ';</script>';
            exit;
        }
    } else {
        $error_message = '发货记录添加失败';
    }
}

// 获取相关数据
$payment_records = Todo_Order_Manager::get_payment_records($order_id);
$shipping_records = Todo_Order_Manager::get_shipping_records($order_id);
$order_logs = Todo_Order_Manager::get_order_logs($order_id);

// 获取防拒付风控与 IP / 设备指纹 / PayPal 地址自动化比对数据
$client_ip = Todo_Order_Manager::get_order_custom_field($order_id, '_client_ip');
$ip_location = Todo_Order_Manager::get_order_custom_field($order_id, '_ip_location');
$device_info = Todo_Order_Manager::get_order_custom_field($order_id, '_device_info');
$paypal_order_id = Todo_Order_Manager::get_order_custom_field($order_id, '_paypal_order_id');
$paypal_cb_addr = Todo_Order_Manager::get_order_custom_field($order_id, '_paypal_callback_address');
$addr_match_status = Todo_Order_Manager::get_order_custom_field($order_id, '_address_match_status');
$addr_match_details = Todo_Order_Manager::get_order_custom_field($order_id, '_address_match_details');
$ip_match_details = Todo_Order_Manager::get_order_custom_field($order_id, '_ip_address_match_details');
$anti_fraud_evidence = Todo_Order_Manager::get_order_custom_field($order_id, '_anti_fraud_evidence');

if (!empty($client_ip) && empty($ip_location) && function_exists('todo_get_ip_location')) {
    $ip_location = todo_get_ip_location($client_ip);
}

$page_title = '#' . $order['order_no'];
?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title"><?php echo esc_html($page_title); ?></h2>
        <div class="admin-page-actions">
            <a href="<?php echo todo_admin_url('index.php?page=todo-orders'); ?>" class="button button-secondary">返回订单列表</a>
        </div>
    </div>
    
    <?php if (isset($_GET['updated'])): ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php
                if ($_GET['updated'] === 'payment_added') {
                    echo '支付记录添加成功。';
                } elseif ($_GET['updated'] === 'shipping_added') {
                    echo '发货记录添加成功。';
                } else {
                    echo '订单更新成功。';
                }
                ?>
            </p>
        </div>
    <?php endif; ?>
    
    <?php if (isset($success_message)): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html($success_message); ?></p>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error_message)): ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html($error_message); ?></p>
        </div>
    <?php endif; ?>

    <div class="order-edit-container">
        <div class="order-main-content">
            <!-- 订单基本信息 -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">订单信息</h3>
                </div>
                <div class="admin-card-content">
                    <form method="post" action="<?php echo todo_admin_url('index.php?page=todo-order-edit&id=' . $order_id); ?>">
                        <?php todo_nonce_field('update_order_' . $order_id); ?>
                        
                        <table class="form-table" style="margin: 0 auto 0 10%;">
                            <tr>
                                <th>订单号</th>
                                <td>
                                    <div class="flex flex-wrap gap-x-2">
                                        <?php
                                        $order_lang = class_exists('Todo_Order_Manager') ? Todo_Order_Manager::get_order_language($order) : 'zh-CN';
                                        $lang_prefix = class_exists('Todo_Order_Manager') ? Todo_Order_Manager::get_language_prefix($order_lang) : 'ZH';
                                        ?>
                                        <strong><?php echo esc_html($order['order_no']); ?></strong>
                                        <span style="padding: 2px 8px;background: #f1f1f1;font-size: 11px;line-height: 18px;">
                                            <?php echo esc_html($lang_prefix); ?>/<?php echo esc_html($order_lang); ?>
                                        </span>
                                    </div>
                                    <p class="description">创建时间：<?php echo date('Y-m-d H:i:s', strtotime($order['created_at'])); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th>客户信息</th>
                                <td>
                                    <?php
                                    $order_user_id = intval($order['user_id'] ?? 0);
                                    $customer_display_name = $order['customer_name'] ?: ($order['username'] ?: '游客');
                                    ?>
                                    <?php if ($order_user_id > 0): ?>
                                        <strong><a href="<?php echo esc_url(todo_admin_url('index.php?page=user-edit&user_id=' . $order_user_id)); ?>" title="查看用户资料"><?php echo esc_html($customer_display_name); ?></a></strong>
                                        <small class="description">#<?php echo esc_html($order_user_id); ?></small>
                                    <?php else: ?>
                                        <strong><?php echo esc_html($customer_display_name); ?></strong>
                                    <?php endif; ?>
                                    <?php if (!empty($order['user_email'])): ?>
                                        <p class="description" style="font-style: normal;">邮箱：<?php echo esc_html($order['user_email']); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($order['customer_phone'])): ?>
                                        <p class="description" style="font-style: normal;">电话：<?php echo esc_html($order['customer_phone']); ?></p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php
                            $is_order_locked = in_array($order['status'] ?? '', ['completed', 'cancelled', 'refunded'], true);
                            
                            // 确定真实的交付/发货方式：优先使用订单保存的 delivery_method 或 shipping_method
                            $delivery_val = $order['delivery_method'] ?? ($order['shipping_method'] ?? '');
                            if (empty($delivery_val) && !empty($order['items']) && is_array($order['items'])) {
                                foreach ($order['items'] as $it) {
                                    if (!empty($it['delivery_type'])) {
                                        $delivery_val = $it['delivery_type'];
                                        break;
                                    }
                                }
                            }
                            if (empty($delivery_val) && !empty($order['items']) && is_array($order['items']) && !empty($tododb)) {
                                foreach ($order['items'] as $it) {
                                    $pid = intval($it['product_id'] ?? 0);
                                    if ($pid > 0) {
                                        $mfp_table = $tododb->prefix . 'model_fields_product';
                                        $dt_found = $tododb->get_var($tododb->prepare("SELECT delivery_type FROM {$mfp_table} WHERE post_id = %d LIMIT 1", $pid));
                                        if (!empty($dt_found)) {
                                            $delivery_val = $dt_found;
                                            break;
                                        }
                                    }
                                }
                            }
                            if (empty($delivery_val)) {
                                $sm = $order['shipping_method'] ?? '';
                                if ($sm === 'virtual' || !empty($order['is_virtual'])) {
                                    $delivery_val = 'content';
                                } else {
                                    $delivery_val = 'express';
                                }
                            }
                            if ($delivery_val === 'text') {
                                $delivery_val = 'content';
                            }

                            $delivery_options = array(
                                'express' => '快递发货',
                                'self_pickup' => '线下自提',
                                'pickup' => '到店自提',
                                'download' => '网盘下载',
                                'content' => '文本内容',
                            );
                            if (!empty($delivery_val) && !isset($delivery_options[$delivery_val])) {
                                $delivery_options[$delivery_val] = $delivery_val;
                            }
                            ?>
                            <tr>
                                <th>订单状态</th>
                                <td>
                                    <?php if ($is_order_locked): ?>
                                        <input type="hidden" name="status" value="<?php echo esc_attr($order['status']); ?>" />
                                        <select disabled class="regular-text" style="background-color: #f5f5f5; cursor: not-allowed;">
                                            <option value="pending" <?php selected($order['status'], 'pending'); ?>>待付款</option>
                                            <option value="paid" <?php selected($order['status'], 'paid'); ?>>已付款</option>
                                            <option value="processing" <?php selected($order['status'], 'processing'); ?>>待发货</option>
                                            <option value="shipped" <?php selected($order['status'], 'shipped'); ?>>已发货</option>
                                            <option value="delivered" <?php selected($order['status'], 'delivered'); ?>>已送达</option>
                                            <option value="completed" <?php selected($order['status'], 'completed'); ?>>已完成</option>
                                            <option value="cancelled" <?php selected($order['status'], 'cancelled'); ?>>已取消</option>
                                            <option value="refunded" <?php selected($order['status'], 'refunded'); ?>>已退款</option>
                                        </select>
                                    <?php else: ?>
                                        <select name="status" class="regular-text">
                                            <option value="pending" <?php selected($order['status'], 'pending'); ?>>待付款</option>
                                            <option value="paid" <?php selected($order['status'], 'paid'); ?>>已付款</option>
                                            <option value="processing" <?php selected($order['status'], 'processing'); ?>>待发货</option>
                                            <option value="shipped" <?php selected($order['status'], 'shipped'); ?>>已发货</option>
                                            <option value="delivered" <?php selected($order['status'], 'delivered'); ?>>已送达</option>
                                            <option value="completed" <?php selected($order['status'], 'completed'); ?>>已完成</option>
                                            <option value="cancelled" <?php selected($order['status'], 'cancelled'); ?>>已取消</option>
                                            <option value="refunded" <?php selected($order['status'], 'refunded'); ?>>已退款</option>
                                        </select>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>支付状态</th>
                                <td>
                                    <?php if ($is_order_locked): ?>
                                        <input type="hidden" name="payment_status" value="<?php echo esc_attr($order['payment_status']); ?>" />
                                        <select disabled class="regular-text" style="background-color: #f5f5f5; cursor: not-allowed;">
                                            <option value="pending" <?php selected($order['payment_status'], 'pending'); ?>>待付款</option>
                                            <option value="paid" <?php selected($order['payment_status'], 'paid'); ?>>已付款</option>
                                            <option value="failed" <?php selected($order['payment_status'], 'failed'); ?>>付款失败</option>
                                            <option value="refunded" <?php selected($order['payment_status'], 'refunded'); ?>>已退款</option>
                                            <option value="partial_refunded" <?php selected($order['payment_status'], 'partial_refunded'); ?>>部分退款</option>
                                        </select>
                                    <?php else: ?>
                                        <select name="payment_status" class="regular-text">
                                            <option value="pending" <?php selected($order['payment_status'], 'pending'); ?>>待付款</option>
                                            <option value="paid" <?php selected($order['payment_status'], 'paid'); ?>>已付款</option>
                                            <option value="failed" <?php selected($order['payment_status'], 'failed'); ?>>付款失败</option>
                                            <option value="refunded" <?php selected($order['payment_status'], 'refunded'); ?>>已退款</option>
                                            <option value="partial_refunded" <?php selected($order['payment_status'], 'partial_refunded'); ?>>部分退款</option>
                                        </select>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>交付方式</th>
                                <td>
                                    <?php if ($is_order_locked): ?>
                                        <input type="hidden" name="delivery_method" value="<?php echo esc_attr($delivery_val); ?>" />
                                        <select disabled class="regular-text" style="background-color: #f5f5f5; cursor: not-allowed;">
                                            <?php foreach ($delivery_options as $opt_val => $opt_label): ?>
                                                <option value="<?php echo esc_attr($opt_val); ?>" <?php selected($delivery_val, $opt_val); ?>><?php echo esc_html($opt_label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <select name="delivery_method" class="regular-text">
                                            <?php foreach ($delivery_options as $opt_val => $opt_label): ?>
                                                <option value="<?php echo esc_attr($opt_val); ?>" <?php selected($delivery_val, $opt_val); ?>><?php echo esc_html($opt_label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>订单总额<?php echo todo_get_default_currency_config()['symbol']; ?></th>
                                <td>
                                    <input type="number" name="total_amount" value="<?php echo esc_attr($order['total_amount']); ?>" step="0.01" class="regular-text" />
                                    <p class="description">修改此项将直接改变订单最终结算金额。</p>
                                </td>
                            </tr>
                            <tr>
                                <th>运费<?php echo todo_get_default_currency_config()['symbol']; ?></th>
                                <td>
                                    <input type="number" name="shipping_fee" value="<?php echo esc_attr($order['shipping_fee']); ?>" step="0.01" class="regular-text" />
                                </td>
                            </tr>
                            <tr>
                                <th>优惠金额<?php echo todo_get_default_currency_config()['symbol']; ?></th>
                                <td>
                                    -<input type="number" name="discount_amount" style="min-width: 210px;" value="<?php echo esc_attr($order['discount_amount']); ?>" step="0.01" class="regular-text" />
                                    <?php if (floatval($order['discount_amount'] ?? 0) > 0 || !empty($order_discounts)): ?>
                                        <div style="margin-top: 6px; font-size: 13px; color: #666; line-height: 1.6;">
                                            <?php if (!empty($order_discounts)): ?>
                                                <ul style="margin: 4px 0 0 15px; padding: 0; list-style-type: disc;word-break: break-all;">
                                                    <?php foreach ($order_discounts as $d): ?>
                                                        <li>
                                                            <?php echo esc_html($d['label']); ?>
                                                            <?php if (!empty($d['amount'])): ?>
                                                                <span style="color: #d9534f;">-<?php echo todo_format_admin_price($d['amount']); ?></span>
                                                            <?php endif; ?>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php else: ?>
                                                <span style="color: #888;">自定义优惠金额 / 手动减免</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>支付方式</th>
                                <td>
                                    <?php
                                    $pm_code = (string) ($order['payment_method'] ?? '');
                                    $pm_name = function_exists('todo_get_payment_method_label') ? todo_get_payment_method_label($pm_code) : $pm_code;
                                    $pm_display = ($pm_name !== '' && $pm_code !== '' && $pm_code !== $pm_name)
                                        ? $pm_name . ' (' . $pm_code . ')'
                                        : ($pm_name ?: '未选择');
                                    ?>
                                    <span><?php echo esc_html($pm_display); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th>快递公司</th>
                                <td>
                                    <?php $current_express_company = (string) ($order['express_company'] ?? ''); ?>
                                    <select name="express_company" class="regular-text">
                                        <option value="">请选择</option>
                                        <?php
                                        $matched = false;
                                        foreach ($express_companies_for_select as $c) {
                                            $code = (string) ($c['code'] ?? '');
                                            $name = (string) ($c['name'] ?? '');
                                            if ($code === '' || $name === '') {
                                                continue;
                                            }
                                            $is_sel = ($current_express_company !== '' && ($current_express_company === $code || $current_express_company === $name));
                                            if ($is_sel) {
                                                $matched = true;
                                            }
                                            echo '<option value="' . esc_attr($code) . '"' . ($is_sel ? ' selected' : '') . '>' . esc_html($name) . ' ' . esc_html($code) . '</option>';
                                        }
                                        if ($current_express_company !== '' && !$matched) {
                                            echo '<option value="' . esc_attr($current_express_company) . '" selected>' . esc_html($current_express_company) . '</option>';
                                        }
                                        ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>快递单号</th>
                                <td>
                                    <div class="flex items-center" style="gap:8px;flex-wrap:wrap;">
                                        <input type="text" name="tracking_no" id="order-tracking-no" value="<?php echo esc_attr($order['tracking_no'] ?? $order['tracking_number'] ?? ''); ?>" class="regular-text" />
                                        <button type="button" class="button button-secondary" id="btn-auto-fetch-logistics"
                                                data-order-id="<?php echo esc_attr($order_id); ?>"
                                                data-nonce="<?php echo esc_attr(function_exists('todo_create_nonce') ? todo_create_nonce('fetch_order_logistics') : ''); ?>"
                                                data-ajax="<?php echo esc_attr(function_exists('todo_admin_url') ? todo_admin_url('admin-ajax.php') : ''); ?>">
                                            拉取物流
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th>物流跟踪信息</th>
                                <td>
                                    <textarea name="tracking_info" id="order-tracking-info" rows="4" class="large-text" style="width: 350px;"><?php echo esc_textarea($order['tracking_info'] ?? ''); ?></textarea>
                                    
                                    <!-- 实时可视化物流轨迹卡片面板 -->
                                    <div id="logistics-timeline-card" style="margin-top: 10px; max-width: 350px; font-size: 13px;">
                                        <div style="display: flex; align-items: center; justify-content: space-between; font-weight: 600; margin-bottom: 8px; border-bottom: 1px dashed #ddd; padding-bottom: 6px;">
                                            <span>📦 实时轨迹</span>
                                            <span id="logistics-status-badge" class="badge" style="padding: 2px 8px; border-radius: 4px; font-size: 12px; background: #e0e0e0; color: #333;">
                                                <?php echo esc_html($current_logistics_info['status_text'] ?? ($order['status'] === 'shipped' ? '已发货' : '未知')); ?>
                                            </span>
                                        </div>
                                        <div id="logistics-traces-list" style="max-height: 200px; overflow-y: auto;">
                                            <?php if (!empty($current_logistics_info['traces']) && is_array($current_logistics_info['traces'])): ?>
                                                <?php foreach ($current_logistics_info['traces'] as $idx => $tr): ?>
                                                    <div style="margin-bottom: 8px; padding-left: 12px; border-left: 2px solid <?php echo $idx === 0 ? '#10b981' : '#cbd5e1'; ?>;">
                                                        <div style="color: #64748b; font-size: 11px;"><?php echo esc_html($tr['time'] ?? ''); ?><?php echo !empty($tr['location']) ? ' · ' . esc_html($tr['location']) : ''; ?></div>
                                                        <div style="color: #1e293b; font-weight: <?php echo $idx === 0 ? '600' : '400'; ?>;"><?php echo esc_html($tr['status'] ?? ''); ?></div>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div style="color: #94a3b8; font-style: italic;">暂无结构化轨迹数据。</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th>客户备注</th>
                                <td>
                                    <?php if ($is_order_locked): ?>
                                        <textarea readonly name="notes" rows="3" class="large-text" style="width: 350px; color: #666; font-weight: bold; background-color: #f5f5f5; cursor: not-allowed;"><?php echo esc_textarea($order['notes'] ?? $order['remark'] ?? ''); ?></textarea>
                                    <?php else: ?>
                                        <textarea name="notes" rows="3" class="large-text" style="width: 350px; color: #f60; font-weight: bold;"><?php echo esc_textarea($order['notes'] ?? $order['remark'] ?? ''); ?></textarea>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>管理员备注</th>
                                <td>
                                    <textarea name="admin_notes" rows="3" class="large-text" style="width: 350px;"><?php echo esc_textarea($order['admin_notes'] ?? $order['admin_note'] ?? ''); ?></textarea>
                                </td>
                            </tr>
                        </table>
                        
                        <!-- 收货地址 -->
                        <?php 
                        $shipping_address_raw = $order['shipping_address'] ?? '';
                        $address_data = json_decode($shipping_address_raw, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($address_data)): 
                        ?>
                            <div class="admin-card-header">
                                <h3 class="admin-card-title">收货地址</h3>
                            </div>
                            <table class="form-table" style="margin: 0 auto 0 10%;">
                                <tr>
                                    <th>收货人</th>
                                    <td>
                                        <input type="text" name="shipping_address_data[name]" 
                                               value="<?php echo esc_attr($address_data['name'] ?? ''); ?>" 
                                               class="regular-text" />
                                    </td>
                                </tr>
                                <tr>
                                    <th>联系电话</th>
                                    <td>
                                        <input type="text" name="shipping_address_data[phone]" 
                                               value="<?php echo esc_attr($address_data['phone'] ?? ''); ?>" 
                                               class="regular-text" />
                                    </td>
                                </tr>
                                <tr>
                                    <th>详细地址</th>
                                    <td>
                                        <div>
                                            <?php 
                                            echo esc_html(($address_data['province'] ?? '') . ' ' . 
                                                        ($address_data['city'] ?? '') . ' ' . 
                                                        ($address_data['district'] ?? '') . ' ' . 
                                                        ($address_data['detail'] ?? '')); 
                                            ?>
                                        </div>
                                        <!-- 隐藏字段用于保留原地址组成部分 -->
                                        <?php 
                                        foreach ($address_data as $k => $v) {
                                            if (!in_array($k, ['name', 'phone'], true)) {
                                                echo '<input type="hidden" name="shipping_address_data[' . esc_attr($k) . ']" value="' . esc_attr(is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v) . '" />' . "\n";
                                            }
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php if (!empty($address_data['address_label'])): ?>
                                <tr>
                                    <th>地址标签</th>
                                    <td>
                                        <span class="status-active"><?php echo esc_html($address_data['address_label']); ?></span>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        <?php elseif (!empty($shipping_address_raw)): ?>
                            <div class="admin-card-header">
                                <h3 class="admin-card-title">收货地址</h3>
                            </div>
                            <table class="form-table" style="margin: 0 auto 0 10%;">
                                <tr>
                                    <th>地址信息</th>
                                    <td>
                                        <textarea name="shipping_address" rows="3" class="large-text" style="width: 350px;"><?php echo esc_textarea($shipping_address_raw); ?></textarea>
                                    </td>
                                </tr>
                            </table>
                        <?php endif; ?>
                        
                        <div class="form-submit">
                            <input type="submit" name="update_order" class="button button-primary" value="更新订单" />
                        </div>
                    </form>
                </div>
            </div>

            <!-- 订单项目 -->
            <div class="admin-card">
                <div class="admin-card-header no-border">
                    <h3 class="admin-card-title">订单项目</h3>
                </div>
                <div class="admin-card-content">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>商品</th>
                                <th>规格</th>
                                <th>单价</th>
                                <th>数量</th>
                                <th style="text-align: right;">小计</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($order['items'])): ?>
                                <?php foreach ($order['items'] as $item): ?>
                                    <?php
                                    // 获取商品缩略图（逻辑与 products.php 保持同步）
                                    $item_pid = intval($item['product_id'] ?? 0);
                                    $thumb_url = ($item_pid > 0 && function_exists('get_the_post_thumbnail_url'))
                                        ? get_the_post_thumbnail_url($item_pid, 'full')
                                        : '';
                                    if (!empty($thumb_url) && function_exists('todo_media_url_to_absolute')) {
                                        $thumb_url = todo_media_url_to_absolute($thumb_url);
                                    }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="product-info">
                                                <?php if (!empty($thumb_url)): ?>
                                                    <div class="product-thumbnail">
                                                        <img src="<?php echo esc_url($thumb_url); ?>" alt="<?php echo esc_attr($product_title_map[$item_pid] ?? ($item['product_name'] ?? '')); ?>" style="width:50px;height:50px;object-fit:cover;border-radius:4px;">
                                                    </div>
                                                <?php endif; ?>
                                                <div class="product-details">
                                                    <?php
                                                    $pid = intval($item['product_id'] ?? 0);
                                                    $display_title = $product_title_map[$pid] ?? ($item['product_name'] ?? '');
                                                    if ($display_title === '') {
                                                        $display_title = '商品已删除';
                                                    }
                                                    ?>
                                                    <a href="<?php echo todo_admin_url('index.php?page=post-edit&from=todo-orders&id=' . $pid); ?>">
                                                        <strong style="display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 3; overflow: hidden;"><?php echo esc_html($display_title); ?></strong>
                                                    </a>
                                                    <small>ID: <?php echo esc_html($pid); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="font-size: 12px;"><?php echo esc_html($item['spec_name']); ?></td>
                                        <td><?php echo todo_format_admin_price($item['price']); ?></td>
                                        <td><?php echo esc_html($item['quantity']); ?></td>
                                        <td style="text-align: right;"><strong><?php echo todo_format_admin_price($item['total_price']); ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="4" style="text-align: right;">商品小计</th>
                                <th style="text-align: right;"><strong><?php echo todo_format_admin_price($order['total_amount'] - $order['shipping_fee'] + $order['discount_amount']); ?></strong></th>
                            </tr>
                            <?php if ($order['shipping_fee'] > 0): ?>
                                <tr style="background: linear-gradient(90deg, transparent, #eff8f7);">
                                    <th colspan="4" style="text-align: right; color: #677476;">运费</th>
                                    <th style="text-align: right; color: #677476;"><?php echo todo_format_admin_price($order['shipping_fee']); ?></th>
                                </tr>
                            <?php endif; ?>
                            <?php if ($order['discount_amount'] > 0): ?>
                                <tr>
                                    <th colspan="4" style="text-align: right;">优惠</th>
                                    <th style="text-align: right;">-<?php echo todo_format_admin_price($order['discount_amount']); ?></th>
                                </tr>
                            <?php endif; ?>
                            <tr style="background: linear-gradient(90deg, transparent, #fff8ee);">
                                <th colspan="4" style="text-align: right; color: #927f71;">订单总计</th>
                                <th style="text-align: right; color: #ff4700;"><strong><?php echo todo_format_admin_price($order['total_amount']); ?></strong></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <div class="order-sidebar">
            <!-- 快速操作 -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">快速操作</h3>
                </div>
                <div class="admin-card-content">
                    <div class="quick-actions">
                        <?php if ($order['payment_status'] === 'pending'): ?>
                            <button type="button" class="button button-primary mark-paid" data-order-id="<?php echo $order_id; ?>">
                                标记已付款
                            </button>
                        <?php endif; ?>
                        
                        <?php if (in_array($order['status'], ['paid', 'processing'])): ?>
                            <button type="button" class="button mark-shipped" data-order-id="<?php echo $order_id; ?>">
                                标记已发货
                            </button>
                        <?php endif; ?>
                        
                        <?php if ($order['status'] === 'shipped'): ?>
                            <button type="button" class="button mark-delivered" data-order-id="<?php echo $order_id; ?>">
                                标记已送达
                            </button>
                        <?php endif; ?>
                        
                        <?php if (in_array($order['status'], ['shipped', 'delivered'])): ?>
                            <button type="button" class="button button-primary mark-completed" data-order-id="<?php echo $order_id; ?>">
                                标记已完成
                            </button>
                        <?php endif; ?>
                        
                        <?php 
                        $is_ab_enabled = !empty(get_option('ab_payment_shared_secret', ''));
                        $ab_type = $order['ab_order_type'] ?? '';
                        if ($is_ab_enabled && in_array($ab_type, ['site_a', 'site_b'], true)): 
                        ?>
                            <button type="button" class="button button-secondary ab-order-sync" data-order-id="<?php echo $order_id; ?>">
                                AB订单同步
                            </button>
                        <?php endif; ?>

                        <button type="button" class="button button-secondary print-order">
                            打印订单
                        </button>
                        
                        <button type="button" class="button button-primary print-shipping-list">
                            打印出货单
                        </button>
                        
                        <button type="button" class="button button-secondary send-email">
                            <i class="icon todofont todo-Mail"></i>
                            发邮件
                        </button>
                    </div>
                </div>
            </div>

            <!-- 支付记录 -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">支付记录</h3>
                    <div class="admin-card-actions">
                        <button type="button" class="btn button-secondary add-payment-btn">添加支付</button>
                    </div>
                </div>
                <div class="admin-card-content">
                    <?php if (!empty($payment_records)): ?>
                        <div class="payment-records">
                            <?php foreach ($payment_records as $payment): ?>
                                <?php $pay_notes = trim((string) ($payment['notes'] ?? $payment['payment_notes'] ?? '')); ?>
                                <div class="payment-record">
                                    <div class="flex justify-between items-center">
                                        <div class="payment-amount"><?php echo todo_format_admin_price($payment['amount'] ?? 0); ?></div>
                                        <b><?php echo esc_html($payment['method'] ?? ($payment['payment_method'] ?? '')); ?></b>
                                    </div>
                                    <?php if (!empty($payment['transaction_id'])): ?>
                                        <div style="font-size: 14px;">交易号：<?php echo esc_html($payment['transaction_id']); ?></div>
                                    <?php endif; ?>
                                    <p class="description"><?php echo date('Y-m-d H:i', strtotime($payment['created_at'] ?? $payment['payment_time'] ?? 'now')); ?></p>
                                    <?php if ($pay_notes !== ''): ?>
                                        <p class="description m-0"><?php echo esc_html($pay_notes); ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="no-items">
                            <p>暂无支付记录。</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 风控与防拒付证据 (IP / 设备指纹 / PayPal地址自动化比对) -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">风控与防拒付证据链</h3>
                    <div>
                        <?php if (!empty($addr_match_status)): ?>
                            <?php if ($addr_match_status === 'MATCH'): ?>
                                <span style="display: inline-flex; align-items: center; gap: 4px; background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; font-size: 13px; font-weight: 600; padding: 4px 10px; border-radius: 20px;">
                                    ✅ 地址100%一致
                                </span>
                            <?php elseif ($addr_match_status === 'PARTIAL_MATCH'): ?>
                                <span style="display: inline-flex; align-items: center; gap: 4px; background: #fef9c3; color: #a16207; border: 1px solid #fef08a; font-size: 13px; font-weight: 600; padding: 4px 10px; border-radius: 20px;">
                                    ⚠️ 包含微小差异 (请人工核查)
                                </span>
                            <?php else: ?>
                                <span style="display: inline-flex; align-items: center; gap: 4px; background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; font-size: 13px; font-weight: 600; padding: 4px 10px; border-radius: 20px;">
                                    🚨 警告：PayPal回调地址与收货地址不一致
                                </span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="font-size: 12px; color: #64748b; background: #f1f5f9; padding: 3px 8px; border-radius: 4px;">待支付回调比对</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="admin-card-content">
                    <!-- 1. 下单 IP 地址与地理位置 -->
                    <div style="margin-bottom: 16px;">
                        <div style="font-weight: 600; margin-bottom: 6px; font-size: 14px; display: flex; align-items: center; gap: 6px;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                            买家下单 IP 与地理位置定位
                        </div>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); font-size: 13px;">
                            <div><b>IP 地址：</b> <code><?php echo esc_html($client_ip ?: '未记录'); ?></code></div>
                            <div>
                                <b>地理定位：</b> 
                                <?php 
                                    if (!empty($ip_location) && is_array($ip_location)) {
                                        $loc_str = implode(', ', array_filter([$ip_location['city'] ?? '', $ip_location['region'] ?? '', $ip_location['country'] ?? '']));
                                        echo esc_html($loc_str ?: '未知');
                                        if (!empty($ip_location['is_middle_east'])) {
                                            echo ' <span style="background:#dbeafe; color:#1e40af; font-size:11px; padding:2px 6px; border-radius:10px; font-weight:600;">中东常用地</span>';
                                        }
                                    } else {
                                        echo '未知';
                                    }
                                ?>
                            </div>
                            <div><b>网络 ISP：</b> <?php echo esc_html($ip_location['isp'] ?? '未知'); ?></div>
                            <?php if (!empty($paypal_order_id)): ?>
                                <div><b>PayPal 订单号：</b> <code style="background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 4px; font-family: monospace;"><?php echo esc_html($paypal_order_id); ?></code></div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($ip_match_details['verdict'])): ?>
                            <div style="margin-top: 8px; padding-top: 8px; border-top: 1px dashed #cbd5e1; font-size: 12px; color: #334155; font-weight: 500;">
                                <b>妥投城地位比对结论：</b> <?php echo esc_html($ip_match_details['verdict']); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- 2. 下单设备指纹信息 (默认收起) -->
                    <details style="margin-bottom: 16px; background: #f8fafc; border: 1px solid #f1f5f9; border-radius: 6px; padding: 10px 14px;">
                        <summary style="font-weight: 600; color: #334155; font-size: 14px; cursor: pointer; display: flex; align-items: center; gap: 6px; user-select: none;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                            展开/查看 买家设备指纹环境信息
                        </summary>
                        <div style="margin-top: 10px;">
                            <?php if (!empty($device_info) && is_array($device_info)): ?>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); font-size: 12px; color: #475569;">
                                    <div><b>屏幕分辨率：</b> <?php echo esc_html($device_info['screen'] ?? '未知'); ?></div>
                                    <div><b>语言设置：</b> <?php echo esc_html($device_info['language'] ?? '未知'); ?></div>
                                    <div><b>系统平台：</b> <?php echo esc_html($device_info['platform'] ?? '未知'); ?></div>
                                    <div><b>买家时区：</b> <?php echo esc_html($device_info['timezone'] ?? '未知'); ?></div>
                                </div>
                                <?php if (!empty($device_info['user_agent'])): ?>
                                    <div style="font-size: 11px; color: #64748b; word-break: break-all;">
                                        <b>User Agent：</b> <?php echo esc_html($device_info['user_agent']); ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div style="font-size: 12px; color: #94a3b8;">未捕获到前端设备指纹细节数据</div>
                            <?php endif; ?>
                        </div>
                    </details>

                    <!-- 3. PayPal 回调地址 vs 买家填写收货地址 自动比对结果 (默认收起) -->
                    <?php if (!empty($addr_match_details) && is_array($addr_match_details)): ?>
                        <div style="margin-bottom: 12px;">
                            <div style="font-weight: 700; font-size: 14px; margin-bottom: 8px; color: <?php echo $addr_match_status === 'MATCH' ? '#166534' : '#991b1b'; ?>;">
                                <?php if ($addr_match_status === 'MATCH'): ?>
                                    ✅ PayPal 账单/收货地址与下单地址 100% 一致
                                <?php else: ?>
                                    ⚠️ 警告：PayPal 回调地址与收货地址存在不一致，请立即核对！
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($addr_match_details['mismatches'])): ?>
                                <div style="margin-bottom: 10px; font-size: 13px; color: #dc2626;">
                                    <b>差异项说明：</b>
                                    <ul style="margin: 4px 0 0 18px; padding: 0;">
                                        <?php foreach ($addr_match_details['mismatches'] as $m_item): ?>
                                            <li><?php echo esc_html($m_item); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>

                            <details style="margin-top: 8px;">
                                <summary style="font-size: 12px; font-weight: 600; color: #475569; cursor: pointer; user-select: none;">
                                    展开/查看 买家地址比对明细
                                </summary>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; font-size: 12px; margin: 8px -20px 0; color: #999; word-break: break-all;">
                                    <div style="padding: 10px;display: flex;flex-direction: column;gap: 5px;justify-content: space-between;border: 1px solid #eee;border-left: none;border-right: none;">
                                        <div style="font-weight: 600; color: #1e293b;">
                                            买家填写收货地址
                                        </div>
                                        <?php 
                                            $u_a = $addr_match_details['user_address'] ?? []; 
                                            $u_email = !empty($u_a['email']) ? $u_a['email'] : ($order['email'] ?? ($order['user_email'] ?? '-'));
                                            $u_phone = !empty($u_a['phone']) ? $u_phone = $u_a['phone'] : ($order['phone'] ?? ($order['shipping_phone'] ?? '-'));
                                        ?>
                                        <div>收货人:&nbsp;<b style="display: block;color: #666;"><?php echo esc_html($u_a['name'] ?? '-'); ?></b></div>
                                        <div>联系邮箱:&nbsp;<b style="display: block;color: #666;"><?php echo esc_html($u_email); ?></b></div>
                                        <div>联系电话:&nbsp;<b style="display: block;color: #666;"><?php echo esc_html($u_phone); ?></b></div>
                                        <div>国家/地区:&nbsp;<b style="display: block;color: #666;"><?php echo esc_html($u_a['country'] ?? '-'); ?></b></div>
                                        <div>城市/州:&nbsp;<b style="display: block;color: #666;"><?php echo esc_html(($u_a['city'] ?? '') . ' ' . ($u_a['state'] ?? '')); ?></b></div>
                                        <div>街道地址:&nbsp;<b style="display: block;color: #666;"><?php echo esc_html($u_a['street'] ?? '-'); ?></b></div>
                                        <div>邮政编码:&nbsp;<b style="display: block;color: #666;"><?php echo esc_html($u_a['postal'] ?? '-'); ?></b></div>
                                    </div>

                                    <div style="padding: 10px;display: flex;flex-direction: column;gap: 5px;justify-content: space-between;border: 1px solid #eee;border-right: none;">
                                        <div style="font-weight: 600; color: #1e293b;">
                                            💳 PayPal 回调地址
                                        </div>
                                        <?php 
                                            $p_a = $addr_match_details['paypal_address'] ?? []; 
                                            $p_email = !empty($p_a['email']) ? $p_a['email'] : ($paypal_callback_address['payer_email'] ?? '-');
                                            $p_phone = !empty($p_a['phone']) ? $p_a['phone'] : '-';
                                        ?>
                                        <div>付款人/收件人:&nbsp;<b style="display: block;color: #666;"><?php echo esc_html($p_a['name'] ?? '-'); ?></b></div>
                                        <div>PayPal邮箱:&nbsp;<b style="display: block;color: #666;"><?php echo esc_html($p_email); ?></b></div>
                                        <div>预留电话:&nbsp;<b style="display: block;color: #666;"><?php echo esc_html($p_phone); ?></b></div>
                                        <div>国家/地区:&nbsp;<b style="display: block;color: #666;"><?php echo esc_html($p_a['country'] ?? '-'); ?></b></div>
                                        <div>城市/州:&nbsp;<b style="display: block;color: #666;"><?php echo esc_html(($p_a['city'] ?? '') . ' ' . ($p_a['state'] ?? '')); ?></b></div>
                                        <div>街道地址:&nbsp;<b style="display: block;color: #666;"><?php echo esc_html($p_a['street'] ?? '-'); ?></b></div>
                                        <div>邮政编码:&nbsp;<b style="display: block;color: #666;"><?php echo esc_html($p_a['postal'] ?? '-'); ?></b></div>
                                    </div>
                                </div>
                            </details>
                        </div>
                    <?php endif; ?>

                    <!-- 4. 原始防拒付证据包 JSON 归档查看 -->
                    <?php if (!empty($anti_fraud_evidence)): ?>
                        <details style="margin-top: 10px; font-size: 12px;">
                            <summary style="font-weight: 600; color: #475569; cursor: pointer;">展开/复制 PayPal 拒付原始抗辩凭证</summary>
                            <pre style="margin: 8px -20px 0; padding: 0 10px; max-height: 250px; overflow: auto; font-family: monospace; font-size: 11px; white-space: pre-wrap; word-break: break-all;"><?php echo esc_html(json_encode($anti_fraud_evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                        </details>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 发货记录 -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">发货记录</h3>
                    <div class="admin-card-actions">
                        <button type="button" class="btn button-secondary add-shipping-btn">添加发货</button>
                    </div>
                </div>
                <div class="admin-card-content">
                    <?php if (!empty($shipping_records)): ?>
                        <div class="shipping-records">
                            <?php foreach ($shipping_records as $shipping): ?>
                                <div class="shipping-record">
                                    <div class="shipping-company"><?php echo esc_html($shipping['express_company'] ?? $shipping['company'] ?? ''); ?></div>
                                    <div style="font-size: 14px;">单号：<?php echo esc_html($shipping['tracking_no']); ?></div>
                                    <p class="description"><?php echo date('Y-m-d H:i', strtotime($shipping['created_at'])); ?></p>
                                    <?php
                                    $ship_notes = trim((string) ($shipping['shipping_notes'] ?? $shipping['notes'] ?? ''));
                                    if ($ship_notes !== ''):
                                    ?>
                                        <p class="description m-0"><?php echo esc_html($ship_notes); ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="no-items">
                            <p>暂无发货记录。</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 操作日志 -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">订单操作记录</h3>
                </div>
                <div class="admin-card-content" style="padding: 0 0 10px 10px;">
                    <?php if (!empty($order_logs)): ?>
                        <div class="order-logs">
                            <?php foreach ($order_logs as $log): ?>
                                <div class="log-entry log-type-<?php echo esc_attr($log['action']); ?>">
                                    <h5><?php echo esc_html($log['message']); ?></h5>
                                    <div class="log-meta">
                                        <span class="log-operator">
                                            <?php echo esc_html($log['operator_name'] ?: '系统'); ?>
                                        </span>
                                        <span class="log-time">
                                            <?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="no-items">
                            <p>暂无操作记录。</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<template id="add-payment-modal-template" data-size="md">
    <div data-slot="title">添加支付记录</div>
    <div data-slot="body">
        <form id="add-payment-form" method="post">
            <input type="hidden" name="action" value="todo_add_payment_record">
            <input type="hidden" name="order_id" value="<?php echo esc_attr($order_id); ?>">
            <input type="hidden" name="_todononce" value="<?php echo todo_create_nonce('add_payment_record'); ?>">
            <table class="form-table">
                <tr>
                    <th>支付金额</th>
                    <td>
                        <input type="number" name="payment_amount" step="0.01" min="0"
                               value="<?php echo esc_attr($order['total_amount'] - ($order['paid_amount'] ?? $order['payment_amount'] ?? 0)); ?>"
                               class="regular-text" required />
                    </td>
                </tr>
                <tr>
                    <th>支付方式</th>
                    <td>
                        <select name="payment_method" class="regular-text" required>
                            <option value="">请选择</option>
                            <?php foreach ($payment_methods_for_select as $m): ?>
                                <?php
                                $code = '';
                                $label = '';
                                if (is_array($m)) {
                                    $code = isset($m['code']) ? (string) $m['code'] : (isset($m['method_id']) ? (string) $m['method_id'] : '');
                                    $label = isset($m['name']) ? (string) $m['name'] : (isset($m['label']) ? (string) $m['label'] : $code);
                                }
                                if ($code === '') {
                                    continue;
                                }
                                ?>
                                <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($label); ?> <?php echo esc_html($code); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>交易号</th>
                    <td>
                        <input type="text" name="transaction_id" class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th>备注</th>
                    <td>
                        <textarea name="payment_notes" rows="3" class="large-text"></textarea>
                    </td>
                </tr>
            </table>
        </form>
    </div>
</template>

<template id="add-shipping-modal-template" data-size="md">
    <div data-slot="title">添加发货记录</div>
    <div data-slot="body">
        <form id="add-shipping-form" method="post">
            <input type="hidden" name="action" value="todo_add_shipping_record">
            <input type="hidden" name="order_id" value="<?php echo esc_attr($order_id); ?>">
            <input type="hidden" name="_todononce" value="<?php echo todo_create_nonce('add_shipping_record'); ?>">
            <table class="form-table">
                <tr>
                    <th>快递公司</th>
                    <td>
                        <select name="shipping_company" class="regular-text" required>
                            <option value="">请选择</option>
                            <?php foreach ($express_companies_for_select as $c): ?>
                                <?php
                                $code = (string) ($c['code'] ?? '');
                                $name = (string) ($c['name'] ?? '');
                                if ($code === '' || $name === '') {
                                    continue;
                                }
                                ?>
                                <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($name); ?> <?php echo esc_html($code); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>快递单号</th>
                    <td>
                        <input type="text" name="tracking_no" class="regular-text" required />
                    </td>
                </tr>
                <tr>
                    <th>备注</th>
                    <td>
                        <textarea name="shipping_notes" rows="3" class="large-text"></textarea>
                    </td>
                </tr>
            </table>
        </form>
    </div>
</template>

<template id="send-order-email-modal-template" data-size="md">
    <div data-slot="title">发送订单邮件</div>
    <div data-slot="body">
        <form id="send-order-email-form">
            <?php echo Todo_Security_Manager::csrf_field('send_order_email'); ?>
            <input type="hidden" name="order_id" value="<?php echo esc_attr($order_id); ?>">

            <div class="form-group">
                <label for="order-email-to">收件人邮箱：</label>
                <input type="email" id="order-email-to" name="email_to" class="regular-text" style="width: 100%;" value="<?php echo esc_attr($customer_email ?? ''); ?>" required>
            </div>

            <div class="form-group">
                <label for="order-email-subject">邮件主题：</label>
                <input type="text" id="order-email-subject" name="email_subject" class="regular-text" style="width: 100%;" placeholder="请输入邮件主题" required>
            </div>

            <div class="form-group">
                <label for="order-email-content">邮件内容：<span class="description m-0">{content}</span></label>
                <textarea id="order-email-content" name="email_content" style="width: 100%;" rows="6" class="large-text" placeholder="请输入要发送的邮件内容（若选了模板，则作为模板 {content} 的补充说明，可留空）"></textarea>
                <p class="description">支持变量：{order_no}, {customer_name}, {total_amount}, {status}, {express_company}, {tracking_no}, {logistics_latest}, {logistics_traces}, {site_name}</p>
            </div>

            <div class="form-group" id="template-select-group">
                <label for="order-email-template">邮件通知模板：</label>
                <select id="order-email-template" name="template_slug">
                    <option value="">-- 邮件模板(可选) --</option>
                </select>
                <p class="description">您可以在"工具 > 邮件设置"中 <a href="<?php echo todo_admin_url('index.php?page=mail&tab=templates'); ?>" target="_blank">管理模版</a></p>
            </div>
        </form>
    </div>
    <div data-slot="footer" class="todo-modal-footer admin-form-submit">
        <button type="button" class="button button-secondary todo-modal-cancel">取消</button>
        <button type="submit" form="send-order-email-form" class="button button-primary" id="btn-submit-send-email">发送邮件</button>
    </div>
</template>

<style>
.mbody .order-main-content .form-table {
    margin: 0 !important;
}

.mbody .order-main-content .form-table input, .mbody .order-main-content .form-table textarea, .mbody .order-main-content .form-table select {
    min-width: 180px;
    width: 180px !important;
}

.order-edit-container {
    display: flex;
    gap: 20px;
    margin-top: 20px;
}

.order-main-content {
    flex: 1;
    min-width: 0;
}

.order-sidebar {
    width: 320px;
    flex-shrink: 0;
}

.product-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.product-thumbnail {
    flex-shrink: 0;
}

.product-thumbnail img {
    width: 50px;
    height: 50px;
    object-fit: cover;
    border-radius: 4px;
    display: block;
    vertical-align: middle;
}

.quick-actions {
    display: grid;
    grid-template-columns: 50% 50%;
    gap: 10px;
}

.quick-actions .button {
    width: 100%;
}

.payment-records, .shipping-records, .order-logs {
    max-height: 600px;
    overflow-y: auto;
}

.payment-records, .shipping-records {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.log-entry {
    padding: 12px;
    border-bottom: 1px solid #f0f0f1;
    position: relative;
    padding-left: 15px;
}

.log-entry:before {
    content: '';
    position: absolute;
    left: 0;
    top: 15px;
    bottom: 15px;
    width: 3px;
    background: #ccd0d4;
    border-radius: 2px;
}

.log-type-price_changed:before { background: #ffb900; }
.log-type-status_updated:before { background: #54a3e4; }
.log-type-payment_status_updated:before { background: #11af35; }
.log-type-order_updated:before { background: #72777c; }

.log-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 11px;
    opacity: 0.5;
}

.log-time {
    color: #6c6c75;
}

.log-operator {
    font-weight: 500;
    color: #50575e;
}

@media (max-width: 768px) {
    .order-edit-container {
        flex-direction: column;
    }
    
    .order-sidebar {
        width: 100%;
    }
    
    .product-info {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // ajaxurl Fallback，确保后台AJAX地址可用
    if (typeof window.ajaxurl === 'undefined' || !window.ajaxurl) {
        window.ajaxurl = '<?php echo todo_admin_url('admin-ajax.php'); ?>';
    }
    // 规范化 ajaxurl 的来源（包含端口），避免错误指向 80 端口
    if (typeof window.location !== 'undefined' && window.location.origin) {
        try {
            var _ajaxURL = new URL(window.ajaxurl, window.location.origin);
            if (_ajaxURL.origin !== window.location.origin) {
                window.ajaxurl = (window.TODO && window.TODO.join ? window.TODO.join((window.TODO.ADMIN_BASE || (window.location.origin + '/admin')), 'admin-ajax.php') : (window.location.origin + '/admin/admin-ajax.php'));
            }
        } catch (e) {
            window.ajaxurl = (window.TODO && window.TODO.join ? window.TODO.join((window.TODO.ADMIN_BASE || (window.location.origin + '/admin')), 'admin-ajax.php') : (window.location.origin + '/admin/admin-ajax.php'));
        }
    }

    // 快速操作
    $('.mark-paid').on('click', function() {
        var orderId = $(this).data('order-id');
        TodoModal.confirm({
            message: '确定要标记此订单为已付款吗？',
            onConfirm: function() {
                updateOrderStatus(orderId, 'payment_status', 'paid');
            }
        });
    });
    
    $('.mark-shipped').on('click', function() {
        var orderId = $(this).data('order-id');
        TodoModal.confirm({
            message: '确定要标记此订单为已发货吗？',
            onConfirm: function() {
                updateOrderStatus(orderId, 'status', 'shipped');
            }
        });
    });
    
    $('.mark-delivered').on('click', function() {
        var orderId = $(this).data('order-id');
        TodoModal.confirm({
            message: '确定要标记此订单为已送达吗？',
            onConfirm: function() {
                updateOrderStatus(orderId, 'status', 'delivered');
            }
        });
    });

    $('.mark-completed').on('click', function() {
        var orderId = $(this).data('order-id');
        TodoModal.confirm({
            message: '确定要标记此订单为已完成吗？',
            onConfirm: function() {
                updateOrderStatus(orderId, 'status', 'completed');
            }
        });
    });
    
    function updateOrderStatus(orderId, field, value) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'update_order_status',
                order_id: orderId,
                field: field,
                value: value,
                _ajax_nonce: '<?php echo todo_create_nonce('update_order_status'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    TodoCMS.Utils.showMessage('操作失败：' + response.data, 'error');
                }
            }
        });
    }
    
    // 模态框操作
    $('.add-payment-btn').on('click', function() {
        var paymentModal = new TodoModal({
            templateId: 'add-payment-modal-template',
            destroyOnClose: true,
            footer: [
                {
                    text: '取消',
                    class: 'button button-secondary todo-modal-cancel'
                },
                {
                    text: '添加支付记录',
                    class: 'button button-primary',
                    handler: function() {
                        var $btn = $(this.element).find('.button-primary');
                        var $form = $(this.element).find('form#add-payment-form');
                        if (!$form.length) $form = $(this.element).find('form');
                        
                        var self = this;
                        self.showLoading('提交中...');
                        
                        var data = new FormData($form[0]);
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: data,
                            processData: false,
                            contentType: false,
                            dataType: 'json',
                            success: function(res) {
                                self.hideLoading();
                                if (res && res.success) {
                                    TodoCMS.Utils.showMessage('支付记录添加成功', 'success');
                                    self.close();
                                    window.location.reload();
                                } else {
                                    var msg = (res && res.data) ? (res.data.message || res.data) : '支付记录添加失败';
                                    TodoCMS.Utils.showMessage(msg, 'error');
                                }
                            },
                            error: function() {
                                self.hideLoading();
                                TodoCMS.Utils.showMessage('网络错误', 'error');
                            }
                        });
                        return false; // 防止模态框自动关闭
                    }
                }
            ]
        });
        paymentModal.open();
    });
    
    $('.add-shipping-btn').on('click', function() {
        var shippingModal = new TodoModal({
            templateId: 'add-shipping-modal-template',
            destroyOnClose: true,
            footer: [
                {
                    text: '取消',
                    class: 'button button-secondary todo-modal-cancel'
                },
                {
                    text: '添加发货记录',
                    class: 'button button-primary',
                    handler: function() {
                        var $btn = $(this.element).find('.button-primary');
                        var $form = $(this.element).find('form#add-shipping-form');
                        if (!$form.length) $form = $(this.element).find('form');
                        
                        var self = this;
                        self.showLoading('提交中...');
                        
                        var data = new FormData($form[0]);
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: data,
                            processData: false,
                            contentType: false,
                            dataType: 'json',
                            success: function(res) {
                                self.hideLoading();
                                if (res && res.success) {
                                    TodoCMS.Utils.showMessage('发货记录添加成功', 'success');
                                    self.close();
                                    window.location.reload();
                                } else {
                                    var msg = (res && res.data) ? (res.data.message || res.data) : '发货记录添加失败';
                                    TodoCMS.Utils.showMessage(msg, 'error');
                                }
                            },
                            error: function() {
                                self.hideLoading();
                                TodoCMS.Utils.showMessage('网络错误', 'error');
                            }
                        });
                        return false; // 防止模态框自动关闭
                    }
                }
            ]
        });
        shippingModal.open();
    });

    // AB 订单全量数据手动同步
    $('.ab-order-sync').on('click', function() {
        var orderId = $(this).data('order-id');
        var $btn = $(this);
        $btn.prop('disabled', true).text('同步中...');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'todo_ab_order_sync',
                order_id: orderId,
                _todononce: '<?php echo todo_create_nonce("todo_ab_order_sync"); ?>'
            },
            dataType: 'json',
            success: function(res) {
                $btn.prop('disabled', false).text('AB订单同步');
                if (res && res.success) {
                    var succMsg = typeof res.data === 'string' ? res.data : (res.data && res.data.message ? res.data.message : 'AB订单数据同步成功');
                    TodoCMS.Utils.showMessage(succMsg, 'success');
                    setTimeout(function() { window.location.reload(); }, 1000);
                } else {
                    var msg = (res && res.data) ? (typeof res.data === 'string' ? res.data : (res.data.message || '同步失败')) : (res.error || '同步失败');
                    TodoCMS.Utils.showMessage(msg, 'error');
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('AB订单同步');
                TodoCMS.Utils.showMessage('网络通信错误，同步失败', 'error');
            }
        });
    });

    $('.print-order').on('click', function() {
        window.print();
    });

    // 打印出货单
    $('.print-shipping-list').on('click', function() {
        var orderId = '<?php echo $order_id; ?>';
        var printUrl = ajaxurl + '?action=todo_print_order&order_id=' + orderId + '&_todononce=<?php echo todo_create_nonce('todo_print_order'); ?>';
        var win = window.open(printUrl, '_blank');
        if (win) {
            win.focus();
        } else {
            TodoCMS.Utils.showMessage('请允许弹出窗口以打印出货单', 'warning');
        }
    });
    
    // 发送邮件
    $('.send-email').on('click', function() {
        openSendOrderEmailModal();
    });

    function openSendOrderEmailModal() {
        var emailModal = new TodoModal({
            templateId: 'send-order-email-modal-template',
            destroyOnClose: true
        });

        var $ctx = $(emailModal.element);
        var $templateSelect = $ctx.find('#order-email-template');
        var $subjectInput = $ctx.find('#order-email-subject');
        var $contentInput = $ctx.find('#order-email-content');

        if ($templateSelect.length) {
            fetch('admin-ajax.php?action=get_templates_by_category&category=all', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success && data.data) {
                    data.data.forEach(function(tpl) {
                        var opt = document.createElement('option');
                        opt.value = tpl.slug;
                        opt.textContent = tpl.name;
                        $templateSelect.append(opt);
                    });
                }
            });

            $templateSelect.on('change', function() {
                var slug = this.value;
                if (slug) {
                    var orderLang = '<?php echo esc_js($order_lang); ?>';
                    fetch('admin-ajax.php?action=get_template_content&id=0&slug=' + slug + '&lang=' + encodeURIComponent(orderLang), {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    })
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        if (data.success && data.data) {
                            if (data.data.subject) $subjectInput.val(data.data.subject);
                        }
                    });
                }
            });
        }

        $ctx.find('#send-order-email-form').on('submit', function(e) {
            e.preventDefault();
            var $form = $(this);
            var tplSlug = $templateSelect.val();
            var contentVal = $.trim($contentInput.val());
            if (!tplSlug && !contentVal) {
                TodoCMS.Utils.showMessage('未选择邮件模板时，必须输入邮件内容', 'error');
                return;
            }
            if ($form.attr('data-submitting') === '1') {
                return;
            }
            $form.attr('data-submitting', '1');
            var $btn = $ctx.find('.todo-modal-footer .button-primary');
            var btnText = $btn.length ? $btn.text() : '';
            if ($btn.length) {
                $btn.prop('disabled', true).text('发送中...');
            }

            var formData = $form.serialize();
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData + '&action=send_order_email',
                dataType: 'json',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                },
                success: function(res) {
                    if (res.success) {
                        TodoCMS.Utils.showMessage('邮件发送成功！', 'success');
                        emailModal.close();
                        setTimeout(function() {
                            location.reload();
                        }, 800);
                    } else {
                        var msg = (res.message || res.data || '发送失败');
                        TodoCMS.Utils.showMessage(msg, 'error');
                        $form.attr('data-submitting', '0');
                        if ($btn.length) {
                            $btn.prop('disabled', false).text(btnText);
                        }
                    }
                },
                error: function() {
                    TodoCMS.Utils.showMessage('网络错误，邮件发送失败', 'error');
                    $form.attr('data-submitting', '0');
                    if ($btn.length) {
                        $btn.prop('disabled', false).text(btnText);
                    }
                }
            });
        });

        emailModal.open();
        return emailModal;
    }

    (function() {
        var btn = document.getElementById('btn-auto-fetch-logistics');
        if (!btn) return;
        btn.addEventListener('click', function() {
            var orderId = btn.getAttribute('data-order-id') || 0;
            var nonce = btn.getAttribute('data-nonce') || '';
            var ajaxUrl = btn.getAttribute('data-ajax') || '';
            var companySelect = document.querySelector('select[name="express_company"]');
            var company = companySelect ? companySelect.value : '';
            var trackingNoInput = document.getElementById('order-tracking-no');
            var trackingNo = trackingNoInput ? trackingNoInput.value.trim() : '';

            if (!trackingNo) {
                TodoCMS.Utils.showMessage('请先输入快递单号', 'warning');
                return;
            }

            btn.disabled = true;
            btn.textContent = '⏳ 拉取中...';

            var fd = new FormData();
            fd.append('action', 'todo_fetch_order_logistics');
            fd.append('_todononce', nonce);
            fd.append('order_id', orderId);
            fd.append('express_company', company);
            fd.append('tracking_no', trackingNo);

            fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                btn.disabled = false;
                btn.textContent = '拉取物流';
                if (res.success && res.data) {
                    var d = res.data;
                    var txtarea = document.getElementById('order-tracking-info');
                    if (txtarea && d.formatted_text) {
                        txtarea.value = d.formatted_text;
                    }
                    var badge = document.getElementById('logistics-status-badge');
                    if (badge && d.status_text) {
                        badge.textContent = d.status_text;
                        badge.style.background = (d.status === 'delivered') ? '#dcfce7' : '#dbeafe';
                        badge.style.color = (d.status === 'delivered') ? '#15803d' : '#1d4ed8';
                    }
                    var list = document.getElementById('logistics-traces-list');
                    if (list && Array.isArray(d.traces) && d.traces.length > 0) {
                        var html = '';
                        d.traces.forEach(function(tr, idx) {
                            var color = (idx === 0) ? '#10b981' : '#cbd5e1';
                            var fw = (idx === 0) ? '600' : '400';
                            var loc = tr.location ? (' · ' + tr.location) : '';
                            html += '<div style="margin-bottom:8px;padding-left:12px;border-left:2px solid ' + color + ';">';
                            html += '<div style="color:#64748b;font-size:11px;">' + (tr.time || '') + loc + '</div>';
                            html += '<div style="color:#1e293b;font-weight:' + fw + ';">' + (tr.status || '') + '</div>';
                            html += '</div>';
                        });
                        list.innerHTML = html;
                    }
                    TodoCMS.Utils.showMessage('物流轨迹已成功拉取并自动填入文本与状态卡片！', 'success');
                } else {
                    TodoCMS.Utils.showMessage('拉取失败: ' + (res.data || res.message || '未知错误'), 'error');
                }
            })
            .catch(function(err) {
                btn.disabled = false;
                btn.textContent = '拉取物流';
                TodoCMS.Utils.showMessage('请求异常，请检查网络或配置', 'error');
            });
        });
    })();
});
</script>
