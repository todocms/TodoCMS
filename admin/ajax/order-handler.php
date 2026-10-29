<?php
/**
 * TodoCMS 订单AJAX处理器
 * 
 * 处理订单相关的AJAX请求
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

$token_aliases = [
    '_ajax_nonce' => '_todononce',
    '_csrf_token' => '_todononce',
];
foreach ($token_aliases as $from => $to) {
    if (!isset($_POST[$to]) && isset($_POST[$from]) && $_POST[$from] !== '') {
        $_POST[$to] = $_POST[$from];
    }
    if (!isset($_GET[$to]) && isset($_GET[$from]) && $_GET[$from] !== '') {
        $_GET[$to] = $_GET[$from];
    }
}

$security = Todo_Security_Manager::getInstance();
$reqAction = sanitize_text_field($_POST['action'] ?? '');
$csrfVerified = false;
switch ($reqAction) {
    case 'todo_get_order_details':
        // 针对订单详情获取，使用 _todononce 作为令牌字段
        $csrfVerified = Todo_Security_Manager::verify_request_csrf('todo_order_details', '_todononce');
        break;
    case 'todo_get_order_for_edit':
        // 针对订单编辑数据加载，使用 _todononce 作为令牌字段
        $csrfVerified = Todo_Security_Manager::verify_request_csrf('todo_order_edit', '_todononce');
        break;
    case 'todo_print_order':
        // 打印出货单，使用 _todononce 作为令牌字段
        $csrfVerified = Todo_Security_Manager::verify_request_csrf('todo_print_order', '_todononce');
        break;
    case 'update_order':
        // 订单信息更新（后台管理），使用 _todononce 作为令牌字段
        $csrfVerified = Todo_Security_Manager::verify_request_csrf('update_order', '_todononce');
        break;
    case 'create_order':
        // 创建订单（后台/前台通用），使用 _todononce 作为令牌字段
        $csrfVerified = Todo_Security_Manager::verify_request_csrf('create_order', '_todononce');
        break;
    case 'todo_add_payment_record':
        $csrfVerified = Todo_Security_Manager::verify_request_csrf('add_payment_record', '_todononce');
        break;
    case 'todo_add_shipping_record':
        $csrfVerified = Todo_Security_Manager::verify_request_csrf('add_shipping_record', '_todononce');
        break;
    case 'todo_test_logistics_query':
    case 'todo_fetch_order_logistics':
        $csrfVerified = Todo_Security_Manager::verify_request_csrf($_POST['action'] ?? '', '_todononce');
        break;
    case 'todo_ab_order_sync':
        $csrfVerified = Todo_Security_Manager::verify_request_csrf('todo_ab_order_sync', '_todononce');
        break;
    case 'send_order_email':
    case 'todo_send_order_email':
        $csrfVerified = Todo_Security_Manager::verify_request_csrf('send_order_email', '_todononce');
        break;
    default:
        // 其他情况使用统一验证（从头/默认字段自动获取）
        $csrfVerified = $security->verify_request_csrf();
        break;
}

if (!$csrfVerified) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: application/json');
    header('X-Robots-Tag: noindex, nofollow', true);
    echo json_encode(['error' => 'CSRF verification failed']);
    exit;
}

if (!defined('DOING_AJAX') || !DOING_AJAX) {
    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
        http_response_code(403);
        echo json_encode(['error' => 'Direct access denied']);
        exit;
    }
}

// 权限检查
if (!current_user_can('manage_options')) {
    todo_send_json_error('权限不足');
}

global $tododb;

/**
 * 打印订单出货单
 */
function todo_ajax_print_order() {
    $order_id = intval($_GET['order_id'] ?? 0);
    if (!$order_id) {
        die('订单ID无效');
    }
    
    $order_details = todo_get_order_details($order_id);
    if (!$order_details) {
        die('订单不存在');
    }
    
    $order = $order_details['order'];
    $items = $order_details['items'];
    $logistics = $order_details['logistics'];
    
    // 获取网站名称
    $site_name = todo_get_option('site_name', '文创特产批发零售系统');
    
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <title>出货清单 - <?php echo esc_html($order->order_no); ?></title>
        <style>
            body { font-family: "Microsoft YaHei", Arial, sans-serif; font-size: 14px; line-height: 1.6; color: #333; margin: 0; padding: 20px; }
            .print-container { max-width: 800px; margin: 0 auto; border: 1px solid #eee; padding: 30px; }
            .print-header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 10px; }
            .print-header h1 { margin: 0; font-size: 24px; }
            .info-section { display: flex; justify-content: space-between; margin-bottom: 20px; }
            .info-box { flex: 1; }
            .info-box h3 { margin: 0 0 10px 0; font-size: 16px; border-left: 4px solid #333; padding-left: 10px; }
            .info-item { margin-bottom: 5px; }
            .info-label { font-weight: bold; width: 80px; display: inline-block; }
            .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            .items-table th, .items-table td { border: 1px solid #333; padding: 10px; text-align: left; }
            .items-table th { background-color: #f5f5f5; }
            .total-section { text-align: right; margin-bottom: 30px; }
            .total-item { margin-bottom: 5px; }
            .total-label { font-weight: bold; font-size: 16px; }
            .total-value { font-size: 20px; color: #ff4700; font-weight: bold; }
            .footer-section { margin-top: 50px; display: flex; justify-content: space-between; border-top: 1px dashed #ccc; padding-top: 20px; }
            .signature { width: 200px; border-bottom: 1px solid #333; height: 30px; display: inline-block; }
            @media print {
                body { padding: 0; }
                .print-container { border: none; max-width: 100%; }
                .no-print { display: none; }
            }
        </style>
    </head>
    <body>
        <div class="no-print" style="text-align: right; margin-bottom: 10px;">
            <button onclick="window.print()" style="padding: 10px 20px; cursor: pointer; background: #2271b1; color: #fff; border: none; border-radius: 4px;">立即打印</button>
        </div>
        <div class="print-container">
            <div class="print-header">
                <h1><?php echo esc_html($site_name); ?> - 出货清单</h1>
            </div>
            
            <div class="info-section">
                <div class="info-box">
                    <h3>订单信息</h3>
                    <div class="info-item"><span class="info-label">订单编号：</span><?php echo esc_html($order->order_no); ?></div>
                    <div class="info-item"><span class="info-label">下单时间：</span><?php echo date('Y-m-d H:i', strtotime($order->created_at)); ?></div>
                    <div class="info-item"><span class="info-label">支付方式：</span><?php echo todo_format_payment_method($order->payment_method); ?></div>
                </div>
                <div class="info-box">
                    <h3>收货信息</h3>
                    <div class="info-item"><span class="info-label">收货人：</span><?php echo esc_html($order->customer_name); ?></div>
                    <div class="info-item"><span class="info-label">联系电话：</span><?php echo esc_html($order->customer_phone); ?></div>
                    <div class="info-item"><span class="info-label">收货地址：</span><?php echo esc_html($order->shipping_address); ?></div>
                </div>
            </div>
            
            <table class="items-table">
                <thead>
                    <tr>
                        <th>序号</th>
                        <th>商品名称</th>
                        <th>规格</th>
                        <th>单价</th>
                        <th>数量</th>
                        <th>小计</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($items as $item): ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo esc_html($item->product_name); ?></td>
                        <td><?php echo esc_html(Todo_Order_Manager::format_spec_name(($item->spec_name ?? '') ?: '-')); ?></td>
                        <td><?php echo todo_format_admin_price($item->price); ?></td>
                        <td><?php echo $item->quantity; ?></td>
                        <td><?php echo todo_format_admin_price($item->price * $item->quantity); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div class="total-section">
                <div class="total-item">商品小计：<?php echo todo_format_admin_price($order->subtotal_amount); ?></div>
                <?php if ($order->shipping_fee > 0): ?>
                    <div class="total-item">运费：<?php echo todo_format_admin_price($order->shipping_fee); ?></div>
                <?php endif; ?>
                <?php if ($order->discount_amount > 0): ?>
                    <div class="total-item">优惠：-<?php echo todo_format_admin_price($order->discount_amount); ?></div>
                <?php endif; ?>
                <div class="total-item">
                    <span class="total-label">实付总计：</span>
                    <span class="total-value"><?php echo todo_format_admin_price($order->total_amount); ?></span>
                </div>
            </div>
            
            <?php if (!empty($order->order_note ?? '')): ?>
            <div class="info-box" style="margin-top: 20px;">
                <h3>客户备注</h3>
                <div style="padding: 10px; border: 1px solid #eee;"><?php echo nl2br(esc_html($order->order_note ?? '')); ?></div>
            </div>
            <?php endif; ?>

            <div class="footer-section">
                <div>打印时间：<?php echo date('Y-m-d H:i:s'); ?></div>
                <div>发货人签名：<span class="signature"></span></div>
                <div>客户签收：<span class="signature"></span></div>
            </div>
        </div>
        <script>
            // 自动弹出打印对话框（可选）
            // window.onload = function() { window.print(); };
        </script>
    </body>
    </html>
    <?php
    exit;
}

/**
 * 获取订单详情
 */
function todo_ajax_get_order_details() {
    // CSRF验证
    $nonce = todo_post_string('_todononce');
    if ($nonce === '') {
        $nonce = todo_get_string('_todononce');
    }
    if ($nonce === '') {
        $nonce = todo_post_string('_csrf_token');
    }
    if ($nonce === '') {
        $nonce = todo_get_string('_csrf_token');
    }
    if ($nonce === '') {
        $nonce = todo_server_string('HTTP_X_CSRF_TOKEN');
    }
    if ($nonce === '') {
        $nonce = todo_server_string('HTTP_X_TODO_NONCE');
    }
    if ($nonce === '' || !todo_verify_nonce($nonce, 'todo_order_details')) {
        todo_send_json_error('安全验证失败');
    }
    
    $order_id = todo_post_int('order_id');
    if (!$order_id) {
        todo_send_json_error('订单ID无效');
    }
    
    $order_details = todo_get_order_details($order_id);
    if (!$order_details) {
        todo_send_json_error('订单不存在');
    }
    
    $html = todo_build_order_details_html($order_details);
    todo_send_json_success(['html' => $html]);
}

/**
 * 获取订单编辑信息
 */
function todo_ajax_get_order_for_edit() {
    // CSRF验证
    $nonce = $_POST['_todononce'] ?? $_GET['_todononce'] ?? $_POST['_csrf_token'] ?? $_GET['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['HTTP_X_TODO_NONCE'] ?? '';
    if ($nonce === '' || !todo_verify_nonce($nonce, 'todo_order_edit')) {
        todo_send_json_error('安全验证失败');
    }
    
    $order_id = intval($_POST['order_id']);
    if (!$order_id) {
        todo_send_json_error('订单ID无效');
    }
    
    global $tododb;
    $orders_table = $tododb->prefix . 'orders';
    $logistics_table = $tododb->prefix . 'order_logistics';
    
    // 获取订单信息
    $order = $tododb->get_row(
        "SELECT * FROM $orders_table WHERE id = ?",
        [$order_id]
    );
    
    if (!$order) {
        todo_send_json_error('订单不存在');
    }
    
    // 获取物流信息（增加表存在性检查）
    if (method_exists($tododb, 'table_exists') && !$tododb->table_exists('order_logistics')) {
        $logistics = null;
    } else {
        $logistics = $tododb->get_row(
            "SELECT * FROM $logistics_table WHERE order_id = ?",
            [$order_id]
        );
    }
    
    $order->logistics = $logistics;
    
    todo_send_json_success($order);
}

/**
 * 更新订单信息
 */
function todo_ajax_update_order() {
    // CSRF验证
    $nonce = $_POST['_todononce'] ?? $_GET['_todononce'] ?? $_POST['_csrf_token'] ?? $_GET['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['HTTP_X_TODO_NONCE'] ?? '';
    if ($nonce === '' || !todo_verify_nonce($nonce, 'update_order')) {
        todo_send_json_error('安全验证失败');
    }

    $order_id = intval($_POST['order_id'] ?? 0);
    if (!$order_id) {
        todo_send_json_error('订单ID无效');
    }

    // 确保 OrderManager 已初始化
    Todo_Order_Manager::init();

    $current_order = Todo_Order_Manager::get_order($order_id, false);
    if (!$current_order) {
        todo_send_json_error('订单不存在');
    }

    $new_status      = sanitize_text_field($_POST['status'] ?? '');
    $tracking_number = sanitize_text_field($_POST['tracking_number'] ?? '');
    $express_company = sanitize_text_field($_POST['express_company'] ?? '');
    $admin_note      = sanitize_text_field($_POST['admin_note'] ?? '');

    // 构建符合 Todo_Order_Manager::update_order() 参数规范的数据
    $order_data = [];
    if ($new_status !== '') {
        $order_data['status'] = $new_status;
    }
    if ($express_company !== '') {
        $order_data['express_company'] = $express_company;
    }
    if ($tracking_number !== '') {
        $order_data['tracking_no'] = $tracking_number;
    }
    if ($admin_note !== '') {
        $order_data['admin_notes'] = $admin_note;
    }

    // 改价：仅在"待付款"状态下允许（来源或目标状态均为 pending）
    $current_status = $current_order['status'] ?? '';
    if ($current_status === 'pending' || $new_status === 'pending') {
        if (isset($_POST['new_total_amount']) && $_POST['new_total_amount'] !== '') {
            $new_total_amount = floatval($_POST['new_total_amount']);
            if ($new_total_amount >= 0) {
                $order_data['total_amount'] = round($new_total_amount, 2);
            }
        }
    }

    if (empty($order_data)) {
        todo_send_json_error('无可更新的字段');
    }

    // 通过 Order Manager 更新，确保操作日志被正确记录
    $operator_id = function_exists('get_current_user_id') ? get_current_user_id() : 0;
    $result = Todo_Order_Manager::update_order($order_id, $order_data, $operator_id);
    if ($result) {
        todo_send_json_success(['order_id' => $order_id]);
    }

    todo_send_json_error('订单更新失败');
}

/**
 * 构建订单详情HTML
 */
function todo_build_order_details_html($order_details) {
    ob_start();
    $order = $order_details['order'];
    $items = $order_details['items'];
    $logistics = $order_details['logistics'];
    ?>
    <div class="order-details-section">
        <h3 style="padding-top: 10px; border-top: 1px dashed #eceef1;">订单信息</h3>
        <div class="order-info-grid m-l-10">
            <div class="order-info-item">
                <label>订单号</label>
                <strong><?php echo esc_html($order->order_no); ?></strong>
            </div>
            <div class="order-info-item">
                <label>订单状态</label>
                <div>
                    <?php 
                    $status_info = todo_format_order_status($order->status);
                    echo '<span class="order-status ' . $status_info['class'] . '">' . $status_info['label'] . '</span>';
                    ?>
                </div>
            </div>
            <div class="order-info-item">
                <label>下单时间</label>
                <div><?php echo date('Y-m-d H:i', strtotime($order->created_at)); ?></div>
            </div>
            <div class="order-info-item">
                <label>支付方式</label>
                <div><?php echo todo_format_payment_method($order->payment_method); ?></div>
            </div>
            <div class="order-info-item">
                <label>订单金额</label>
                <strong><?php echo todo_format_admin_price($order->total_amount); ?></strong>
            </div>
            <?php if ($order->discount_amount > 0): ?>
            <div class="order-info-item">
                <label>优惠金额</label>
                <div>-<?php echo todo_format_admin_price($order->discount_amount); ?></div>
            </div>
            <?php endif; ?>
        </div>
        
        <?php 
        $customer_note_val = is_array($order) ? ($order['notes'] ?? $order['remark'] ?? '') : ($order->notes ?? $order->remark ?? '');
        $admin_note_val    = is_array($order) ? ($order['admin_notes'] ?? $order['admin_note'] ?? '') : ($order->admin_notes ?? $order->admin_note ?? '');
        ?>
        <?php if (!empty($customer_note_val)): ?>
        <div class="order-info-item m-l-10">
            <label>客户备注</label>
            <div style="color: #f60;"><?php echo esc_html($customer_note_val); ?></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($admin_note_val)): ?>
        <div class="order-info-item m-l-10">
            <label>管理员备注</label>
            <div><?php echo esc_html($admin_note_val); ?></div>
        </div>
        <?php endif; ?>
    </div>

    <div class="order-details-section">
        <h3 style="margin: 15px 0 0; padding-top: 10px; border-top: 1px dashed #eceef1;">商品清单</h3>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>商品名称</th>
                    <th>规格</th>
                    <th>单价</th>
                    <th>数量</th>
                    <th style="padding-right: 20px; text-align: right;">小计</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <?php
                // 获取商品缩略图（逻辑与 order-edit.php / products.php 保持同步）
                $item_pid = intval($item->product_id ?? 0);
                $thumb_url = ($item_pid > 0 && function_exists('get_the_post_thumbnail_url'))
                    ? get_the_post_thumbnail_url($item_pid, 'full')
                    : '';
                if (!empty($thumb_url) && function_exists('todo_media_url_to_absolute')) {
                    $thumb_url = todo_media_url_to_absolute($thumb_url);
                }
                ?>
                <tr>
                    <td class="flex items-center gap-2">
                        <?php if (!empty($thumb_url)): ?>
                            <div class="product-thumbnail">
                                <img src="<?php echo esc_url($thumb_url); ?>" alt="<?php echo esc_attr($item->product_name ?: ''); ?>" style="width:40px;height:40px;object-fit:cover;border-radius:4px;display:block;">
                            </div>
                        <?php endif; ?>
                        <a href="<?php echo todo_admin_url('index.php?page=post-edit&from=todo-orders&id=' . $item_pid); ?>" style="font-size: 14px; display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 3; overflow: hidden;"><?php echo esc_html($item->product_name ?: '商品已删除'); ?></a>
                    </td>
                    <td style="font-size: 12px;"><?php echo esc_html(Todo_Order_Manager::format_spec_name(($item->spec_name ?? '') ?: '-')); ?></td>
                    <td><?php echo todo_format_admin_price($item->price); ?></td>
                    <td><?php echo $item->quantity; ?></td>
                    <td style="padding-right: 20px; text-align: right;"><?php echo todo_format_admin_price($item->price * $item->quantity); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" style="text-align: right;">商品小计</td>
                    <td style="padding-right: 20px; text-align: right;"><strong><?php echo todo_format_admin_price($order->subtotal_amount); ?></strong></td>
                </tr>
                <?php if ($order->shipping_fee > 0): ?>
                <tr style="background: linear-gradient(90deg, transparent, #eff8f7);">
                    <td colspan="4" style="text-align: right; color: #677476;">运费</td>
                    <td style="padding-right: 20px; text-align: right; color: #677476;"><?php echo todo_format_admin_price($order->shipping_fee); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($order->discount_amount > 0): ?>
                <tr>
                    <td colspan="4" style="text-align: right;">优惠</td>
                    <td style="padding-right: 20px; text-align: right;">-<?php echo todo_format_admin_price($order->discount_amount); ?></td>
                </tr>
                <?php endif; ?>
                <tr style="background: linear-gradient(90deg, transparent, #fff8ee);">
                    <td colspan="4" style="text-align: right; color: #927f71;">订单总计</td>
                    <td style="padding-right: 20px; text-align: right; color: #ff4700;"><strong><?php echo todo_format_admin_price($order->total_amount); ?></strong></td>
                </tr>
            </tfoot>
        </table>
    </div>
    
    <div class="order-details-section">
        <h3 style="margin: 15px 0 0; padding-top: 10px; border-top: 1px dashed #eceef1;">客户信息</h3>
        <div class="order-info-grid m-l-10">
            <div class="order-info-item">
                <label>客户姓名</label>
                <div><?php echo esc_html($order->customer_name); ?></div>
            </div>
            <?php if ($order->customer_phone): ?>
            <div class="order-info-item">
                <label>联系电话</label>
                <div><?php echo esc_html($order->customer_phone); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($order->customer_email): ?>
            <div class="order-info-item">
                <label>邮箱地址</label>
                <div><?php echo esc_html($order->customer_email); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($order->user_id): ?>
            <div class="order-info-item">
                <label>会员ID</label>
                <div><?php echo $order->user_id; ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if (!empty($order->shipping_address)): ?>
    <div class="order-details-section">
        <h3 style="margin: 15px 0 0; padding-top: 10px; border-top: 1px dashed #eceef1;">收货地址</h3>
        <div class="order-info-grid m-l-10">
            <?php 
            $address_data = json_decode($order->shipping_address, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($address_data)): 
            ?>
                <div class="order-info-item">
                    <label>收货人</label>
                    <div><?php echo esc_html($address_data['name'] ?? ''); ?></div>
                </div>
                <div class="order-info-item">
                    <label>联系电话</label>
                    <div><?php echo esc_html($address_data['phone'] ?? ''); ?></div>
                </div>
                <div class="order-info-item" style="grid-column: span 2;">
                    <label>详细地址</label>
                    <div>
                        <?php 
                        echo esc_html(($address_data['province'] ?? '') . ' ' . 
                                     ($address_data['city'] ?? '') . ' ' . 
                                     ($address_data['district'] ?? '') . ' ' . 
                                     ($address_data['detail'] ?? '')); 
                        ?>
                        <?php if (!empty($address_data['address_label'])): ?>
                            <span class="status-active" style="margin-left: 5px;"><?php echo esc_html($address_data['address_label']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="order-info-item" style="grid-column: span 2;">
                    <div><?php echo esc_html($order->shipping_address); ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($logistics): ?>
    <div class="order-details-section">
        <h3 style="margin: 15px 0 0; padding-top: 10px; border-top: 1px dashed #eceef1;">物流信息</h3>
        <div class="order-info-grid m-l-10">
            <div class="order-info-item">
                <label>快递公司</label>
                <div><?php echo esc_html($logistics->express_company); ?></div>
            </div>
            <div class="order-info-item">
                <label>快递单号</label>
                <div><?php echo esc_html($logistics->tracking_number); ?></div>
            </div>
            <div class="order-info-item">
                <label>发货时间</label>
                <div><?php echo date('Y-m-d H:i', strtotime($logistics->created_at)); ?></div>
            </div>
            <div class="order-info-item">
                <label>物流状态</label>
                <div><?php echo esc_html($logistics->status); ?></div>
            </div>
        </div>
        
        <?php if (!empty($logistics->tracking_info)): ?>
        <div class="order-info-item m-l-10">
            <label>物流跟踪</label>
            <div><?php echo nl2br(esc_html($logistics->tracking_info)); ?></div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($order->order_note ?? '')): ?>
    <div class="order-details-section">
        <h3 style="margin: 15px 0 0; padding-top: 10px; border-top: 1px dashed #eceef1;">订单备注</h3>
        <div class="order-info-item m-l-10">
            <div><?php echo nl2br(esc_html($order->order_note ?? '')); ?></div>
        </div>
    </div>
    <?php endif; ?>

    <div class="order-details-actions" style="margin: 15px 0 0; padding-top: 10px; border-top: 1px dashed #eceef1;">
        <a href="<?php echo esc_url(todo_admin_url('index.php?page=todo-order-edit&from=todo-orders&id=' . intval($order->id))); ?>"
           class="btn btn-normal">
            查看订单更多信息
        </a>
    </div>
    <?php
    
    return ob_get_clean();
}

/**
 * 获取订单详情（复用函数）
 */
function todo_get_order_details($order_id) {
    global $tododb;
    
    $orders_table = $tododb->prefix . 'orders';
    $order_items_table = $tododb->prefix . 'order_items';
    $posts_table = $tododb->prefix . 'posts';
    $logistics_table = $tododb->prefix . 'order_logistics';
    $users_table = $tododb->prefix . 'users';
    $user_meta_table = $tododb->prefix . 'user_meta';
    
    // 获取订单基本信息（联表用户获取显示名称和邮箱）
    $order = $tododb->get_row(
        "SELECT o.*, u.display_name AS customer_name, u.user_email AS customer_email
        FROM $orders_table o
        LEFT JOIN $users_table u ON o.user_id = u.ID
        WHERE o.id = ?",
        [$order_id]
    );
    
    if (!$order) {
        return null;
    }
    
    // 如果存在用户，尝试读取手机号（phone）
    $order->customer_phone = '';
    if (!empty($order->user_id)) {
        $phone_row = $tododb->get_row(
            "SELECT meta_value FROM $user_meta_table WHERE user_id = ? AND meta_key = 'phone' LIMIT 1",
            [$order->user_id]
        );
        if ($phone_row && isset($phone_row->meta_value)) {
            $order->customer_phone = $phone_row->meta_value;
        }
    }
    
    // 获取订单商品
    $order_items = $tododb->get_results(
        "SELECT oi.*, p.post_title as product_name
        FROM $order_items_table oi
        LEFT JOIN $posts_table p ON oi.product_id = p.ID
        WHERE oi.order_id = ?",
        [$order_id]
    );
    
    // 计算商品小计金额（subtotal_amount）
    $subtotal_amount = 0.0;
    if (!empty($order_items)) {
        foreach ($order_items as $oi) {
            $line_total = 0.0;
            if (isset($oi->total_price)) {
                $line_total = floatval($oi->total_price);
            } else {
                $line_total = floatval($oi->price) * intval($oi->quantity);
            }
            $subtotal_amount += $line_total;
        }
    }
    $order->subtotal_amount = round($subtotal_amount, 2);
    
    // 获取物流信息（增加表存在性检查）
    if (method_exists($tododb, 'table_exists') && !$tododb->table_exists('order_logistics')) {
        $logistics = null;
    } else {
        $logistics = $tododb->get_row(
            "SELECT * FROM $logistics_table WHERE order_id = ?",
            [$order_id]
        );
    }
    
    return [
        'order' => $order,
        'items' => $order_items,
        'logistics' => $logistics
    ];
}

/**
 * 格式化订单状态（复用函数）
 */
function todo_format_order_status($status) {
    $statuses = [
        'pending' => ['label' => '待付款', 'class' => 'status-pending'],
        'paid' => ['label' => '已付款', 'class' => 'status-spam'],
        'processing' => ['label' => '待发货', 'class' => 'status-spam'],
        'shipped' => ['label' => '已发货', 'class' => 'status-active'],
        'delivered' => ['label' => '已送达', 'class' => 'status-active'],
        'completed' => ['label' => '已完成', 'class' => 'status-inactive'],
        'cancelled' => ['label' => '已取消', 'class' => 'status-inactive'],
        'refunded' => ['label' => '已退款', 'class' => 'status-reject']
    ];
    
    return $statuses[$status] ?? ['label' => $status, 'class' => 'status-inactive'];
}

/**
 * 格式化支付方式（复用函数）
 */
function todo_format_payment_method($method) {
    if (!function_exists('todo_get_payment_method_label')) {
        if (class_exists('Todo_Function_Loader')) {
            Todo_Function_Loader::load_function('shop-service-settings');
        } else {
            $p = defined('ABSPATH') ? ABSPATH . 'core/functions/shop-service-settings.php' : '';
            if ($p && file_exists($p)) {
                require_once $p;
            }
        }
    }
    return function_exists('todo_get_payment_method_label')
        ? todo_get_payment_method_label($method)
        : $method;
}

// 注册AJAX处理函数
add_action('todocms_ajax_todo_get_order_details', 'todo_ajax_get_order_details');
add_action('todocms_ajax_todo_get_order_for_edit', 'todo_ajax_get_order_for_edit');
add_action('todocms_ajax_update_order', 'todo_ajax_update_order');
add_action('todocms_ajax_todo_add_payment_record', 'todo_ajax_add_payment_record');
add_action('todocms_ajax_todo_add_shipping_record', 'todo_ajax_add_shipping_record');

/**
 * AJAX：添加支付记录
 */
function todo_ajax_add_payment_record() {
    $nonce = $_POST['_todononce'] ?? '';
    if (!$nonce || !todo_verify_nonce($nonce, 'add_payment_record')) {
        todo_send_json_error('安全验证失败');
    }

    $order_id = intval($_POST['order_id'] ?? 0);
    if (!$order_id) {
        todo_send_json_error('订单ID无效');
    }

    Todo_Order_Manager::init();

    $payment_data = [
        'order_id'       => $order_id,
        'amount'         => floatval($_POST['payment_amount'] ?? 0),
        'payment_method' => sanitize_text_field($_POST['payment_method'] ?? ''),
        'transaction_id' => sanitize_text_field($_POST['transaction_id'] ?? ''),
        'notes'          => sanitize_text_field($_POST['payment_notes'] ?? ''),
    ];

    if (Todo_Order_Manager::add_payment_record($order_id, $payment_data)) {
        todo_send_json_success(['message' => '支付记录添加成功']);
    }
    todo_send_json_error('支付记录添加失败');
}

/**
 * AJAX：添加发货记录
 */
function todo_ajax_add_shipping_record() {
    $nonce = $_POST['_todononce'] ?? '';
    if (!$nonce || !todo_verify_nonce($nonce, 'add_shipping_record')) {
        todo_send_json_error('安全验证失败');
    }

    $order_id = intval($_POST['order_id'] ?? 0);
    if (!$order_id) {
        todo_send_json_error('订单ID无效');
    }

    Todo_Order_Manager::init();

    $shipping_data = [
        'order_id'        => $order_id,
        // 表单字段名为 shipping_company，对应 add_shipping_record() 期望的 express_company
        'express_company' => sanitize_text_field($_POST['shipping_company'] ?? ''),
        'tracking_no'     => sanitize_text_field($_POST['tracking_no'] ?? ''),
        'notes'           => sanitize_text_field($_POST['shipping_notes'] ?? ''),
    ];

    if (Todo_Order_Manager::add_shipping_record($order_id, $shipping_data)) {
        // 自动拉取物流轨迹并填充到订单及 order_logistics
        $logistics_info = Todo_Order_Manager::get_logistics_info($shipping_data['tracking_no'], $shipping_data['express_company'], [
            'order_id' => $order_id,
            'force'    => true,
            'persist'  => true,
        ]);
        if (!empty($logistics_info['success']) && !empty($logistics_info['traces'])) {
            $formatted_text = Todo_Order_Manager::format_logistics_traces_text($logistics_info['traces']);
            if ($formatted_text !== '') {
                global $tododb;
                $tododb->update(
                    $tododb->prefix . 'orders',
                    ['express_company' => $shipping_data['express_company'], 'tracking_no' => $shipping_data['tracking_no'], 'tracking_info' => $formatted_text],
                    ['id' => $order_id]
                );
            }
        }

        // 同步将订单状态更新为已发货
        $operator_id = function_exists('get_current_user_id') ? get_current_user_id() : 0;
        Todo_Order_Manager::update_order($order_id, ['status' => 'shipped'], $operator_id);
        
        // 自动将发货记录与“已发货”状态向对端（B站/A站）同步
        if (class_exists('Todo_AB_Payment_API')) {
            $ab_api = new Todo_AB_Payment_API();
            $ab_api->sync_ab_order($order_id);
        }
        
        todo_send_json_success(['message' => '发货记录添加成功，已自动拉取并关联最新物流轨迹']);
    }
    todo_send_json_error('发货记录添加失败');
}

/**
 * AJAX：自动拉取并获取订单最新物流轨迹
 */
function todo_ajax_fetch_order_logistics() {
    $nonce = $_POST['_todononce'] ?? '';
    if (!$nonce || (!todo_verify_nonce($nonce, 'fetch_order_logistics') && !todo_verify_nonce($nonce, 'test_logistics_query'))) {
        todo_send_json_error('安全验证失败');
    }

    $order_id = intval($_POST['order_id'] ?? 0);
    $company_code = sanitize_text_field($_POST['company_code'] ?? $_POST['express_company'] ?? '');
    $tracking_no = sanitize_text_field($_POST['tracking_no'] ?? '');

    if ($tracking_no === '') {
        todo_send_json_error('运单号不能为空');
    }

    Todo_Order_Manager::init();
    $result = Todo_Order_Manager::get_logistics_info($tracking_no, $company_code, array(
        'order_id' => $order_id,
        'force'    => true,
        'persist'  => true,
    ));

    if (!empty($result['success'])) {
        $formatted_text = Todo_Order_Manager::format_logistics_traces_text($result['traces'] ?? array());
        $result['formatted_text'] = $formatted_text;

        if ($order_id > 0) {
            global $tododb;
            $update_fields = array(
                'express_company' => $company_code,
                'tracking_no'     => $tracking_no,
            );
            if ($formatted_text !== '') {
                $update_fields['tracking_info'] = $formatted_text;
            }
            $tododb->update($tododb->prefix . 'orders', $update_fields, array('id' => $order_id));
        }

        todo_send_json_success($result);
    }

    todo_send_json_error($result['message'] ?? '物流信息拉取失败');
}

function todo_ajax_test_logistics_query() {
    $nonce = $_POST['_todononce'] ?? '';
    if (!$nonce || !todo_verify_nonce($nonce, 'test_logistics_query')) {
        todo_send_json_error('安全验证失败');
    }

    $company_code = sanitize_text_field($_POST['company_code'] ?? '');
    $tracking_no = sanitize_text_field($_POST['tracking_no'] ?? '');
    if ($tracking_no === '') {
        todo_send_json_error('运单号不能为空');
    }

    Todo_Order_Manager::init();
    $result = Todo_Order_Manager::get_logistics_info($tracking_no, $company_code, array(
        'force' => true,
        'persist' => false,
    ));

    if (!empty($result['success'])) {
        todo_send_json_success($result);
    }
    todo_send_json_error($result['message'] ?? '查询失败');
}

/**
 * AB订单手动全量同步处理器
 */
function todo_ajax_ab_order_sync() {
    $order_id = intval($_POST['order_id'] ?? 0);
    if (!$order_id) {
        todo_send_json_error('无效的订单ID');
    }

    $api = new Todo_AB_Payment_API();
    $result = $api->sync_ab_order($order_id);

    if (is_todo_error($result)) {
        todo_send_json_error($result->get_error_message());
    }

    todo_send_json_success($result);
}

/**
 * 发送订单邮件处理器
 */
function todo_ajax_send_order_email() {
    $nonce = $_POST['_todononce'] ?? '';
    if (!$nonce || !todo_verify_nonce($nonce, 'send_order_email')) {
        todo_send_json_error('安全验证失败');
    }

    $order_id = intval($_POST['order_id'] ?? 0);
    if (!$order_id) {
        todo_send_json_error('无效的订单ID');
    }

    $email_to = sanitize_email($_POST['email_to'] ?? '');
    if (empty($email_to) || !filter_var($email_to, FILTER_VALIDATE_EMAIL)) {
        todo_send_json_error('请输入有效的收件人邮箱');
    }

    Todo_Order_Manager::init();
    $order = Todo_Order_Manager::get_order($order_id, true);
    if (!$order) {
        todo_send_json_error('订单不存在');
    }

    $template_slug = sanitize_text_field($_POST['template_slug'] ?? '');
    $email_subject = sanitize_text_field($_POST['email_subject'] ?? '');
    $email_content = trim($_POST['email_content'] ?? '');

    $site_name = function_exists('CMS_Option') ? CMS_Option::get('site_title', 'TodoCMS') : (function_exists('get_option') ? get_option('site_title', 'TodoCMS') : 'TodoCMS');
    $site_url = function_exists('site_url') ? site_url('/') : '';
    $customer_name = !empty($order['customer_name']) ? $order['customer_name'] : (!empty($order['username']) ? $order['username'] : '客户');
    $order_lang = Todo_Order_Manager::get_order_language($order);
    $status_text = Todo_Order_Manager::get_order_status_text($order['status'] ?? '', $order_lang);

    // 获取最新物流节点及轨迹信息
    $logistics_latest = __('暂无物流节点动态');
    $logistics_traces = __('暂无物流轨迹信息');
    $logistics_status = '未知';

    $tracking_no = trim((string)($order['tracking_no'] ?? ''));
    $express_company = trim((string)($order['express_company'] ?? ''));

    if ($tracking_no !== '') {
        $logistics_info = Todo_Order_Manager::get_logistics_info($tracking_no, $express_company, [
            'order_id' => $order_id,
            'force' => false
        ]);

        if (!empty($logistics_info['success']) && !empty($logistics_info['traces']) && is_array($logistics_info['traces'])) {
            $traces = $logistics_info['traces'];
            $first_trace = reset($traces);
            if (is_array($first_trace)) {
                $time = $first_trace['time'] ?? ($first_trace['datetime'] ?? '');
                $context = $first_trace['context'] ?? ($first_trace['remark'] ?? ($first_trace['status'] ?? ''));
                $logistics_latest = trim($time . ' ' . $context);
            } elseif (is_string($first_trace)) {
                $logistics_latest = $first_trace;
            }

            $trace_lines = [];
            foreach ($traces as $tr) {
                if (is_array($tr)) {
                    $t = $tr['time'] ?? ($tr['datetime'] ?? '');
                    $c = $tr['context'] ?? ($tr['remark'] ?? ($tr['status'] ?? ''));
                    $trace_lines[] = trim($t . ' ' . $c);
                } elseif (is_string($tr)) {
                    $trace_lines[] = trim($tr);
                }
            }
            if (!empty($trace_lines)) {
                $logistics_traces = implode("\n", $trace_lines);
            }
            if (!empty($logistics_info['status_text'])) {
                $logistics_status = $logistics_info['status_text'];
            }
        } elseif (!empty($order['tracking_info'])) {
            $raw_info = $order['tracking_info'];
            $decoded = json_decode($raw_info, true);
            if (is_array($decoded) && isset($decoded['traces']) && is_array($decoded['traces'])) {
                $traces = $decoded['traces'];
                $first_trace = reset($traces);
                if (is_array($first_trace)) {
                    $time = $first_trace['time'] ?? ($first_trace['datetime'] ?? '');
                    $context = $first_trace['context'] ?? ($first_trace['remark'] ?? '');
                    $logistics_latest = trim($time . ' ' . $context);
                }
                $trace_lines = [];
                foreach ($traces as $tr) {
                    if (is_array($tr)) {
                        $trace_lines[] = trim(($tr['time'] ?? '') . ' ' . ($tr['context'] ?? ''));
                    }
                }
                if (!empty($trace_lines)) {
                    $logistics_traces = implode("\n", $trace_lines);
                }
            } else {
                $logistics_latest = $raw_info;
                $logistics_traces = $raw_info;
            }
        }
    }

    $base_vars = [
        'order_no'        => $order['order_no'] ?? '',
        'order_lang'      => $order_lang,
        'lang'            => $order_lang,
        'customer_name'   => $customer_name,
        'username'        => $customer_name,
        'email'           => $email_to,
        'total_amount'    => function_exists('todo_format_admin_price') ? todo_format_admin_price($order['total_amount'] ?? 0) : ($order['total_amount'] ?? 0),
        'status'          => $status_text,
        'payment_method'  => $order['payment_method'] ?? '',
        'shipping_method' => $order['shipping_method'] ?? '',
        'express_company' => $express_company,
        'tracking_no'     => $tracking_no,
        'logistics_latest'=> $logistics_latest,
        'logistics_traces'=> $logistics_traces,
        'logistics_status'=> $logistics_status,
        'created_at'      => $order['created_at'] ?? '',
        'site_name'       => $site_name,
        'site_url'        => $site_url,
    ];

    // 如果邮件内容/主题及基础变量中包含多语言嵌入块 [en|...][zh|...]，根据订单语言提取对应语言版本
    if (function_exists('todo_resolve_multilang_text')) {
        foreach ($base_vars as $k => $v) {
            if (is_scalar($v)) {
                $base_vars[$k] = todo_resolve_multilang_text((string) $v, $order_lang);
            }
        }
        $email_subject = todo_resolve_multilang_text($email_subject, $order_lang);
        $email_content = todo_resolve_multilang_text($email_content, $order_lang);
    }

    // 先在 email_content 与 email_subject 中对动态变量进行解析替换
    foreach ($base_vars as $k => $v) {
        $email_content = str_replace('{' . $k . '}', (string) $v, $email_content);
        if ($email_subject !== '') {
            $email_subject = str_replace('{' . $k . '}', (string) $v, $email_subject);
        }
    }

    $formatted_content = nl2br(htmlspecialchars($email_content, ENT_QUOTES, 'UTF-8'));

    $variables = array_merge($base_vars, [
        'content'         => $formatted_content,
        'email_content'   => $formatted_content,
        'reply_content'   => $formatted_content,
        'message_content' => $formatted_content,
    ]);

    $sent = false;

    if (!empty($template_slug) && function_exists('todo_send_template_mail')) {
        $meta = [
            'type' => 'order',
            'related_type' => 'order',
            'related_id' => $order_id,
            'thread_key' => 'order:' . $order_id,
            'subject' => $email_subject,
            'order_lang' => $order_lang,
            'lang' => $order_lang,
        ];
        $sent = todo_send_template_mail($email_to, $template_slug, $variables, false, $meta);
    } else {
        if (empty($email_subject)) {
            $email_subject = '订单通知 - ' . ($order['order_no'] ?? '');
        }

        foreach ($variables as $k => $v) {
            $email_subject = str_replace('{' . $k . '}', (string) $v, $email_subject);
            $formatted_content = str_replace('{' . $k . '}', (string) $v, $formatted_content);
        }

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $smtp_user = function_exists('CMS_Option') ? CMS_Option::get('mail_smtp_user') : '';
        if (!empty($smtp_user)) {
            $sender_name = function_exists('todo_resolve_sender_display_name') ? todo_resolve_sender_display_name('', $site_name) : $site_name;
            if (!empty($sender_name)) {
                $encoded_name = '=?UTF-8?B?' . base64_encode($sender_name) . '?=';
                $headers[] = "From: {$encoded_name} <{$smtp_user}>";
            } else {
                $headers[] = "From: <{$smtp_user}>";
            }
        }

        if (function_exists('todo_mail')) {
            $sent = todo_mail($email_to, $email_subject, $formatted_content, $headers);
        } else if (function_exists('todo_send_mail')) {
            $sent = todo_send_mail($email_to, $email_subject, $formatted_content, $headers);
        }

        if (function_exists('todo_log_email')) {
            todo_log_email($email_to, $email_subject, $sent ? 'sent' : 'failed', $sent ? '' : '发送失败', [
                'content' => $formatted_content,
                'type' => 'order',
                'direction' => 'out',
                'thread_key' => 'order:' . $order_id,
                'related_type' => 'order',
                'related_id' => $order_id,
                'is_read' => 1,
            ]);
        }
    }

    if ($sent) {
        $log_message = sprintf('向客户 (%s) 发送邮件通知：%s', $email_to, $email_subject ?: '订单通知');
        Todo_Order_Manager::add_order_log($order_id, 'email_sent', $log_message, get_current_user_id());
        todo_send_json_success('邮件发送成功！');
    } else {
        todo_send_json_error('邮件发送失败，请检查邮件配置（如SMTP服务器）');
    }
}

// 根据请求的action调用相应的函数
$action = sanitize_text_field($_POST['action'] ?? '');

switch ($action) {
    case 'todo_get_order_details':
        todo_ajax_get_order_details();
        break;
        
    case 'todo_get_order_for_edit':
        todo_ajax_get_order_for_edit();
        break;
        
    case 'update_order':
        todo_ajax_update_order();
        break;

    case 'todo_add_payment_record':
        todo_ajax_add_payment_record();
        break;

    case 'todo_add_shipping_record':
        todo_ajax_add_shipping_record();
        break;

    case 'todo_test_logistics_query':
        todo_ajax_test_logistics_query();
        break;

    case 'todo_fetch_order_logistics':
        todo_ajax_fetch_order_logistics();
        break;

    case 'todo_ab_order_sync':
        todo_ajax_ab_order_sync();
        break;

    case 'send_order_email':
    case 'todo_send_order_email':
        todo_ajax_send_order_email();
        break;
        
    default:
        todo_send_json_error('未知的操作');
        break;
}
