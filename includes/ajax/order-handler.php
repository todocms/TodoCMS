<?php
/**
 * 前端订单处理 AJAX 接口
 * 
 * @package TodoCMS
 * @subpackage Includes
 */

// 检查AJAX请求
if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}

if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    if (!defined('ABSPATH')) {
        define('ABSPATH', rtrim(dirname(dirname(dirname(__FILE__))), '/') . '/');
    }

    require_once ABSPATH . 'core/bootstrap.php';
    require_once ABSPATH . 'core/functions/function-loader.php';
    if (class_exists('Todo_Function_Loader')) {
        Todo_Function_Loader::load_core_functions();
        Todo_Function_Loader::load_function_group('shop');
    }

    if (class_exists('Todo_Session_Manager')) {
        Todo_Session_Manager::init();
    } elseif (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
}

if (!todo_is_ajax_request()) {
    http_response_code(403);
    echo json_encode(['error' => 'Direct access denied']);
    exit;
}

// 获取操作类型
$action = sanitize_text_field(todo_request_string('action'));

// 验证CSRF令牌
if (!in_array($action, ['get_logistics', 'get_order_items', 'print_order'])) {
    $csrf_token = todo_post_string('_csrf_token');
    if ($csrf_token === '') {
        $csrf_token = todo_post_string('_todononce');
    }
    if (!empty($csrf_token) && function_exists('todo_verify_csrf_token')) {
        if (!todo_verify_csrf_token($csrf_token)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid CSRF token']);
            exit;
        }
    }
}

// 获取当前用户ID（游客模式下为0）
$current_user_id = is_user_logged_in() ? get_current_user_id() : 0;

// 辅助函数：校验对当前订单的操作权限（登录用户校验 user_id，游客模式校验订单所有权或游客记录）
function check_order_permission($order)
{
    global $current_user_id;
    if (empty($order)) {
        return false;
    }
    
    $order_user_id = isset($order['user_id']) ? intval($order['user_id']) : 0;
    $order_id = isset($order['id']) ? intval($order['id']) : 0;

    // 1. 登录用户且 user_id 匹配
    if ($current_user_id > 0 && $order_user_id === intval($current_user_id)) {
        return true;
    }

    // 2. 检查 Session/Cookie 中是否保存了该游客订单
    if (function_exists('todo_get_guest_order_ids')) {
        $guest_order_ids = todo_get_guest_order_ids();
        if (in_array($order_id, array_map('intval', $guest_order_ids), true)) {
            return true;
        }
    }

    return false;
}

// PRG 防重复提交守卫（仅对携带 _submit_key 的 POST 生效）
if (!defined('PREVENT_AJAX_AUTO_RUN')) {
    Todo_PRG_Guard::guard_endpoint('order_handler_' . ($action ?? ''), 30);
}

try {
    switch ($action) {
        case 'cancel_order':
            handle_cancel_order();
            break;

        case 'confirm_received':
            handle_confirm_received();
            break;

        case 'get_logistics':
            handle_get_logistics();
            break;

        case 'get_order_items':
            handle_get_order_items();
            break;

        case 'submit_review':
            handle_submit_review();
            break;

        case 'delete_order':
            handle_delete_order();
            break;

        case 'print_order':
            handle_print_order();
            break;

        case 'buy_again':
            handle_buy_again();
            break;

        case 'apply_refund':
            handle_apply_refund();
            break;

        case 'cancel_refund':
            handle_cancel_refund();
            break;

        case 'submit_return_logistics':
            handle_submit_return_logistics();
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * 取消订单
 */
function handle_cancel_order()
{
    global $current_user_id;

    $order_id = todo_post_int('order_id');

    if (!$order_id) {
        throw new Exception(__('订单ID不能为空'));
    }

    Todo_Order_Manager::init();

    // 获取订单信息
    $order = Todo_Order_Manager::get_order($order_id);

    if (!$order) {
        throw new Exception(__('订单不存在'));
    }

    if (!check_order_permission($order)) {
        throw new Exception(__('无权操作此订单'));
    }

    if ($order['status'] !== 'pending') {
        throw new Exception(__('只能取消待付款订单'));
    }

    // 统一调用 Todo_Order_Manager::cancel_order 完成订单状态更新与优惠券解冻释放
    $result = Todo_Order_Manager::cancel_order($order_id, '用户取消订单', $current_user_id);

    if (!$result) {
        throw new Exception(__('取消订单失败'));
    }

    // 恢复库存
    $order_items = Todo_Order_Manager::get_order_items($order_id);
    if (is_array($order_items)) {
        foreach ($order_items as $item) {
            $specs = $item['specs'] ?? ($item['product_specs'] ?? ($item['spec_name'] ?? ''));
            Todo_Order_Manager::restore_stock($item['product_id'], $item['quantity'], $specs);
        }
    }

    echo json_encode(['success' => true, 'message' => __('订单已取消')]);
}

/**
 * 确认收货
 */
function handle_confirm_received()
{
    global $current_user_id;

    $order_id = todo_post_int('order_id');

    if (!$order_id) {
        throw new Exception(__('订单ID不能为空'));
    }

    // 获取订单信息
    $order = Todo_Order_Manager::get_order($order_id);

    if (!$order) {
        throw new Exception(__('订单不存在'));
    }

    if (!check_order_permission($order)) {
        throw new Exception(__('无权操作此订单'));
    }

    if (!in_array($order['status'], ['shipped', 'delivered'], true)) {
        throw new Exception(__('只能确认已发货或已送达订单'));
    }

    // 更新订单状态为已完成（包含状态变更及用户确认收货备注）
    $result = Todo_Order_Manager::update_order_status($order_id, Todo_Order_Manager::STATUS_COMPLETED, $current_user_id, '用户确认收货');

    if (!$result) {
        throw new Exception(__('确认收货失败'));
    }

    echo json_encode(['success' => true, 'message' => __('确认收货成功')]);
}

/**
 * 获取物流信息
 */
function handle_get_logistics()
{
    global $current_user_id;

    $order_id = todo_get_int('order_id');

    if (!$order_id) {
        throw new Exception(__('订单ID不能为空'));
    }

    // 获取订单信息
    $order = Todo_Order_Manager::get_order($order_id);

    if (!$order) {
        throw new Exception(__('订单不存在'));
    }

    if ($order['user_id'] != $current_user_id) {
        throw new Exception(__('无权查看此订单'));
    }

    $tracking_no = $order['tracking_no'] ?? ($order['tracking_number'] ?? '');
    $express_company = $order['express_company'] ?? ($order['shipping_company'] ?? '');
    if (!$tracking_no) {
        throw new Exception(__('暂无物流信息'));
    }

    // 获取物流信息
    $receiver_phone = $order['customer_phone'] ?? '';
    $logistics = Todo_Order_Manager::get_logistics_info($tracking_no, $express_company, array(
        'persist' => true,
        'order_id' => $order_id,
        'receiver_phone' => $receiver_phone,
    ));

    echo json_encode([
        'success' => true,
        'tracking_number' => $tracking_no,
        'shipping_company' => $express_company,
        'logistics' => $logistics
    ]);
}

/**
 * 获取订单商品列表
 */
function handle_get_order_items()
{
    global $current_user_id;

    $order_id = todo_get_int('order_id');

    if (!$order_id) {
        throw new Exception(__('订单ID不能为空'));
    }

    // 获取订单信息
    $order = Todo_Order_Manager::get_order($order_id);

    if (!$order) {
        throw new Exception(__('订单不存在'));
    }

    if ($order['user_id'] != $current_user_id) {
        throw new Exception(__('无权查看此订单'));
    }

    // 获取订单商品
    $order_items = Todo_Order_Manager::get_order_items($order_id);

    // 批量预加载商品元数据，避免N+1查询
    if (!empty($order_items)) {
        $product_ids = array_column($order_items, 'product_id');
        update_post_meta_cache($product_ids);
    }

    $items = [];
    foreach ($order_items as $item) {
        $image_url = '';
        if (!empty($item['product_id']) && function_exists('get_the_post_thumbnail_url')) {
            $image_url = (string) get_the_post_thumbnail_url((int) $item['product_id'], 'full');
        }
        if ($image_url === '') {
            $image_url = get_template_directory_uri() . '/assets/images/no-image.png';
        }

        $specs_text = todo_format_specs_text($item['specs'] ?? [], $item['product_id']);

        $items[] = [
            'id' => $item['product_id'],
            'title' => $item['product_title'],
            'image' => $image_url,
            'specs' => $specs_text
        ];
    }

    echo json_encode([
        'success' => true,
        'items' => $items
    ]);
}

/**
 * 提交评价
 */
function handle_submit_review()
{
    global $current_user_id;

    $order_id = todo_post_int('order_id');
    $overall_rating = todo_post_int('overall_rating', 5);
    $review_content = sanitize_textarea_field($_POST['review_content'] ?? '');
    $ratings = $_POST['ratings'] ?? [];

    if (!$order_id) {
        throw new Exception(__('订单ID不能为空'));
    }

    // 获取订单信息
    $order = Todo_Order_Manager::get_order($order_id);

    if (!$order) {
        throw new Exception(__('订单不存在'));
    }

    // 检查订单评价权限（已登录用户校验 user_id 匹配；游客订单校验 session 凭证或允许直接评价）
    $order_user_id = intval($order['user_id'] ?? 0);
    if ($order_user_id > 0) {
        if ($order_user_id != $current_user_id) {
            throw new Exception(__('无权评价此订单'));
        }
    } else {
        // 游客订单：检查当前 session / 游客购买授权
        $guest_allowed = false;
        if (!empty($_SESSION['todo_guest_order_ids']) && is_array($_SESSION['todo_guest_order_ids'])) {
            if (in_array($order_id, $_SESSION['todo_guest_order_ids'])) {
                $guest_allowed = true;
            }
        }
        if (!$guest_allowed && !empty($_SESSION['todo_guest_order_no'])) {
            if ($_SESSION['todo_guest_order_no'] === ($order['order_no'] ?? '')) {
                $guest_allowed = true;
            }
        }
        if (!$guest_allowed && $current_user_id > 0) {
            $guest_allowed = true;
        }
        if (!$guest_allowed && empty($order_user_id)) {
            // 游客购买：允许通过订单校验进行评价
            $guest_allowed = true;
        }
        if (!$guest_allowed) {
            throw new Exception(__('无权评价此订单'));
        }
    }

    if (!in_array($order['status'], ['delivered', 'completed'], true)) {
        throw new Exception(__('只能评价已完成订单'));
    }

    // 检查是否已评价
    if (Todo_Order_Manager::has_reviewed($order_id)) {
        throw new Exception(__('订单已评价，不能重复评价'));
    }

    // 获取订单商品
    $order_items = Todo_Order_Manager::get_order_items($order_id);

    // 获取评价人名称与邮箱
    $author_name = __('已购用户');
    $author_email = '';
    if ($current_user_id > 0) {
        $user = todo_get_user($current_user_id);
        if ($user) {
            $author_name = $user->display_name ?? $user->user_login ?? __('已购用户');
            $author_email = $user->user_email ?? '';
        }
    } else {
        $author_name = $order['shipping_name'] ?? $order['contact_name'] ?? __('游客买家');
        $author_email = $order['shipping_email'] ?? $order['contact_email'] ?? '';
    }

    // 支持针对各个商品的评分、评价内容和图片
    $items_reviews = $_POST['items'] ?? [];

    // 开始事务
    global $tododb;
    $tododb->query('START TRANSACTION');

    try {
        // 获取第一个商品的ID做为主评价关联的 product_id 兜底
        $first_item = reset($order_items);
        $first_product_id = intval($first_item['product_id'] ?? 0);

        $overall_need_approval = function_exists('todo_is_review_approval_enabled') ? todo_is_review_approval_enabled() : false;
        $review_data = [
            'order_id' => $order_id,
            'product_id' => $first_product_id,
            'user_id' => $current_user_id,
            'rating' => $overall_rating,
            'content' => $review_content,
            'status' => $overall_need_approval ? 'pending' : 'approved',
            'created_at' => current_time('mysql')
        ];

        $result = $tododb->insert(
            $tododb->prefix . 'order_reviews',
            $review_data
        );

        if (!$result) {
            throw new Exception(__('保存评价失败'));
        }

        if (empty($order_items)) {
            throw new Exception(__('找不到该订单对应的商品明细'));
        }

        // 遍历订单商品，同步生成统一评论表 (comments) 的 review 记录（供前台 comment_section.html 解析与互动）
        foreach ($order_items as $item) {
            $product_id = intval($item['product_id']);
            $item_data = $items_reviews[$product_id] ?? (reset($items_reviews) ?: []);

            $rating = intval($item_data['rating'] ?? $ratings[$product_id] ?? 5);
            $content = sanitize_textarea_field($item_data['content'] ?? $review_content ?? '');
            if (empty($content)) {
                $content = __('非常满意，好评');
            }

            // 校验后台 enable_upload 设置
            $enable_upload = function_exists('get_option') ? get_option('enable_upload', true) : true;
            if (function_exists('todo_get_max_upload_size') && (int) todo_get_max_upload_size() <= 0) {
                $enable_upload = false;
            }

            // 严格过滤晒图图片：限制数量最多6张，只允许合法图片 URL 或 DataURL 方案
            $raw_images = $item_data['images'] ?? [];
            if (is_array($raw_images) && count($raw_images) > 6) {
                throw new Exception(__('单个商品最多只能上传6张晒图'));
            }
            
            $safe_images = [];
            if (is_array($raw_images)) {
                if (!function_exists('todo_process_frontend_image_upload') && defined('ABSPATH')) {
                    require_once ABSPATH . 'core/functions/media.php';
                }
                foreach (array_slice($raw_images, 0, 6) as $img) {
                    $img = trim((string) $img);
                    if ($img === '') continue;
                    if (strpos($img, 'data:image/') === 0) {
                        $upload_res = todo_process_frontend_image_upload($img, 'review', $current_user_id, __('商品评价晒图'));
                        if (empty($upload_res['success'])) {
                            throw new Exception($upload_res['error'] ?? __('晒图图片上传失败'));
                        }
                        $safe_images[] = $upload_res['url'];
                    } elseif (filter_var($img, FILTER_VALIDATE_URL) || strpos($img, '/') === 0) {
                        $rel_img = function_exists('todo_media_url_to_relative') ? todo_media_url_to_relative($img) : $img;
                        $safe_images[] = $rel_img;
                    }
                }
            }
            $images = $safe_images;

            $spec_name = $item['spec_name'] ?? $item['specs_text'] ?? '';
            $images_json = (!empty($images) && is_array($images)) ? json_encode(array_values($images), JSON_UNESCAPED_UNICODE) : '';

            $need_approval = function_exists('todo_is_review_approval_enabled') ? todo_is_review_approval_enabled() : false;
            $review_status = $need_approval ? 0 : 1;

            if ($product_id <= 0 && $first_product_id > 0) {
                $product_id = $first_product_id;
            }

            $product_review_data = [
                'order_id' => $order_id,
                'user_id' => $current_user_id,
                'product_id' => $product_id,
                'rating' => $rating,
                'content' => $content,
                'spec_name' => $spec_name,
                'images' => $images_json,
                'status' => $review_status
            ];

            $res_pr = $tododb->insert($tododb->prefix . 'product_reviews', $product_review_data);
            if (!$res_pr) {
                throw new Exception(__('保存商品评价失败：') . ($tododb->last_error ?? __('数据库写入错误')));
            }

            // 仅在无需审核或已通过时更新商品评分
            if ($review_status === 1 && $product_id > 0) {
                Todo_Order_Manager::update_product_rating($product_id, $rating);
            }
        }

        // 标记订单已评价
        $tododb->update(
            $tododb->prefix . 'orders',
            ['reviewed' => 1],
            ['id' => $order_id]
        );

        // 记录日志
        Todo_Order_Manager::add_order_log($order_id, 'submit_review', '用户提交评价', $current_user_id);

        // 提交数据库事务
        $tododb->query('COMMIT');

        // 提交事务后，同步生成统一评论记录并发送管理员通知邮件
        if (!empty($order_items)) {
            foreach ($order_items as $item) {
                $p_id = intval($item['product_id'] ?? 0);
                if ($p_id <= 0 && $first_product_id > 0) $p_id = $first_product_id;
                $item_data = $items_reviews[$p_id] ?? (reset($items_reviews) ?: []);
                $rating = intval($item_data['rating'] ?? $ratings[$p_id] ?? 5);
                $content = sanitize_textarea_field($item_data['content'] ?? $review_content ?? '');
                if (empty($content)) $content = '无内容';

                $post = function_exists('get_post') ? get_post($p_id) : null;
                $p_title = $post ? ($post->post_title ?? ($item['product_name'] ?? '商品')) : ($item['product_name'] ?? '商品');
                $p_url = function_exists('todo_get_permalink') ? todo_get_permalink($p_id) : '';

                // 触发 comment 类型管理员通知邮件
                if (function_exists('todo_send_admin_notification')) {
                    $img_count = (isset($item_data['images']) && is_array($item_data['images'])) ? count($item_data['images']) : 0;
                    $mail_content = $content . '<br>评分：' . $rating . '星' . ($img_count > 0 ? '<br>[' . $img_count . '图]' : '');
                    try {
                        todo_send_admin_notification('comment', 'comment', [
                            'post_title'   => $p_title,
                            'author_name'  => $author_name,
                            'author_email' => $author_email,
                            'content'      => $mail_content,
                            'created_at'   => current_time('mysql'),
                            'post_url'     => $p_url
                        ]);
                    } catch (\Throwable $ne) {}
                }
            }
        }

        echo json_encode(['success' => true, 'message' => __('评价提交成功')]);

    } catch (Exception $e) {
        $tododb->query('ROLLBACK');
        throw $e;
    }
}

/**
 * 删除订单
 */
function handle_delete_order()
{
    global $current_user_id;

    $order_id = todo_post_int('order_id');

    if (!$order_id) {
        throw new Exception(__('订单ID不能为空'));
    }

    // 获取订单信息
    $order = Todo_Order_Manager::get_order($order_id);

    if (!$order) {
        throw new Exception(__('订单不存在'));
    }

    if ($order['user_id'] != $current_user_id) {
        throw new Exception(__('无权删除此订单'));
    }

    if (!in_array($order['status'], ['delivered', 'cancelled', 'completed'])) {
        throw new Exception(__('只能删除已完成或已取消的订单'));
    }

    // 删除订单
    $result = Todo_Order_Manager::delete_order($order_id);

    if (!$result) {
        throw new Exception(__('删除订单失败'));
    }

    echo json_encode(['success' => true, 'message' => __('订单已删除')]);
}

/**
 * 打印订单
 */
function handle_print_order()
{
    global $current_user_id;

    $order_id = todo_get_int('order_id');

    if (!$order_id) {
        throw new Exception(__('订单ID不能为空'));
    }

    // 获取订单信息
    $order = Todo_Order_Manager::get_order($order_id);

    if (!$order) {
        throw new Exception(__('订单不存在'));
    }

    if ($order['user_id'] != $current_user_id) {
        throw new Exception(__('无权打印此订单'));
    }

    // 获取订单商品
    $order_items = Todo_Order_Manager::get_order_items($order_id);

    // 获取用户信息
    $user = get_userdata($current_user_id);

    // 输出打印页面
    ?>
    <!DOCTYPE html>
    <html>

    <head>
        <meta charset="UTF-8">
        <title>订单打印 - <?php echo esc_html($order['order_no']); ?></title>
        <style>
            body {
                font-family: Arial, sans-serif;
                margin: 20px;
                color: #333;
            }

            .print-header {
                text-align: center;
                margin-bottom: 30px;
                border-bottom: 2px solid #333;
                padding-bottom: 20px;
            }

            .print-header h1 {
                margin: 0;
                font-size: 24px;
            }

            .order-info {
                margin-bottom: 30px;
            }

            .order-info table {
                width: 100%;
                border-collapse: collapse;
            }

            .order-info td {
                padding: 8px;
                border-bottom: 1px solid #ddd;
            }

            .order-info td:first-child {
                font-weight: 500;
                width: 120px;
            }

            .items-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 30px;
            }

            .items-table th,
            .items-table td {
                padding: 10px;
                border: 1px solid #ddd;
                text-align: left;
            }

            .items-table th {
                background-color: #f8f9fa;
                font-weight: 500;
            }

            .items-table .text-right {
                text-align: right;
            }

            .order-summary {
                margin-top: 20px;
                text-align: right;
            }

            .order-summary table {
                margin-left: auto;
                border-collapse: collapse;
            }

            .order-summary td {
                padding: 5px 10px;
                border-bottom: 1px solid #ddd;
            }

            .order-summary .total {
                font-weight: 500;
                font-size: 18px;
                border-top: 2px solid #333;
            }

            @media print {
                body {
                    margin: 0;
                }

                .no-print {
                    display: none;
                }
            }
        </style>
    </head>

    <body>
        <div class="print-header">
            <h1>订单详情</h1>
            <p>订单号：<?php echo esc_html($order['order_no']); ?></p>
        </div>

        <div class="order-info">
            <table>
                <tr>
                    <td>订单号：</td>
                    <td><?php echo esc_html($order['order_no']); ?></td>
                </tr>
                <tr>
                    <td>下单时间：</td>
                    <td><?php echo esc_html($order['created_at']); ?></td>
                </tr>
                <tr>
                    <td>客户姓名：</td>
                    <td><?php echo esc_html($user->display_name); ?></td>
                </tr>
                <tr>
                    <td>订单状态：</td>
                    <td><?php echo esc_html(Todo_Order_Manager::get_status_name($order['status'])); ?></td>
                </tr>
                <tr>
                    <td>支付状态：</td>
                    <td><?php echo esc_html(Todo_Order_Manager::get_payment_status_name($order['payment_status'])); ?></td>
                </tr>
                <?php if ($order['payment_method']): ?>
                    <tr>
                        <td>支付方式：</td>
                        <td><?php echo esc_html($order['payment_method']); ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ($order['shipping_method']): ?>
                    <tr>
                        <td>配送方式：</td>
                        <td><?php echo esc_html($order['shipping_method']); ?></td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>

        <?php if ($order['shipping_address']): ?>
            <div class="shipping-info">
                <h3>收货信息</h3>
                <?php
                $address = json_decode($order['shipping_address'], true);
                ?>
                <table>
                    <tr>
                        <td>收货人：</td>
                        <td><?php echo esc_html($address['name']); ?></td>
                    </tr>
                    <tr>
                        <td>联系电话：</td>
                        <td><?php echo esc_html($address['phone']); ?></td>
                    </tr>
                    <tr>
                        <td>收货地址：</td>
                        <td><?php echo esc_html($address['province'] . ' ' . $address['city'] . ' ' . $address['district'] . ' ' . $address['detail']); ?>
                        </td>
                    </tr>
                    <?php if (!empty($address['postcode'])): ?>
                        <tr>
                            <td>邮编：</td>
                            <td><?php echo esc_html($address['postcode']); ?></td>
                        </tr>
                    <?php endif; ?>
                </table>
            </div>
        <?php endif; ?>

        <div class="items-section">
            <h3>商品清单</h3>
            <table class="items-table">
                <thead>
                    <tr>
                        <th>商品名称</th>
                        <th>规格</th>
                        <th>单价</th>
                        <th>数量</th>
                        <th class="text-right">小计</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($order_items as $item): ?>
                        <tr>
                            <td><?php echo esc_html($item['product_title']); ?></td>
                            <td>
                                <?php
                                echo esc_html(todo_format_specs_text($item['specs'] ?? [], $item['product_id']));
                                if (empty($item['specs'])) {
                                    echo __('默认规格');
                                }
                                ?>
                            </td>
                            <td><?php echo todo_format_price($item['price']); ?></td>
                            <td><?php echo intval($item['quantity']); ?></td>
                            <td class="text-right"><?php echo todo_format_price($item['price'] * $item['quantity']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="order-summary">
            <table>
                <tr>
                    <td>商品总价：</td>
                    <td><?php echo todo_format_price($order['subtotal']); ?></td>
                </tr>
                <?php if ($order['shipping_fee'] > 0): ?>
                    <tr>
                        <td>运费：</td>
                        <td><?php echo todo_format_price($order['shipping_fee']); ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ($order['discount_amount'] > 0): ?>
                    <tr>
                        <td>优惠金额：</td>
                        <td>-<?php echo todo_format_price($order['discount_amount']); ?></td>
                    </tr>
                <?php endif; ?>
                <tr class="total">
                    <td>实付金额：</td>
                    <td><?php echo todo_format_price($order['total_amount']); ?></td>
                </tr>
            </table>
        </div>

        <div class="no-print" style="margin-top: 30px; text-align: center;">
            <button onclick="window.print()">打印订单</button>
            <button onclick="window.close()">关闭</button>
        </div>

        <script type="text/javascript">
            // 自动打印
            window.onload = function () {
                window.print();
            };
        </script>
    </body>

    </html>
    <?php
    exit;
}

/**
 * 再次购买
 */
function handle_buy_again()
{
    global $current_user_id;

    $order_id = intval($_POST['order_id'] ?? 0);

    if (!$order_id) {
        throw new Exception(__('订单ID不能为空'));
    }

    // 获取订单信息
    $order = Todo_Order_Manager::get_order($order_id);

    if (!$order) {
        throw new Exception(__('订单不存在'));
    }

    if ($order['user_id'] != $current_user_id) {
        throw new Exception(__('无权操作此订单'));
    }

    // 获取订单商品
    $order_items = Todo_Order_Manager::get_order_items($order_id);

    if (empty($order_items)) {
        throw new Exception(__('订单商品为空'));
    }

    $cart_manager = new Todo_Cart_Manager();
    $added_count = 0;
    $failed_items = [];

    // 批量预加载商品元数据，避免N+1查询
    if (!empty($order_items)) {
        $product_ids = array_column($order_items, 'product_id');
        update_post_meta_cache($product_ids);

        // 批量预加载商品对象，避免N+1查询
        // get_posts 会自动缓存查询到的文章对象
        get_posts([
            'post_type' => 'any',
            'post__in' => $product_ids,
            'posts_per_page' => -1,
            'update_post_meta_cache' => false, // 前面已经加载过了
            'update_post_term_cache' => false
        ]);
    }

    foreach ($order_items as $item) {
        try {
            // 检查商品是否存在且有库存
            $product = get_post($item['product_id']);
            if (!$product || $product->post_status !== 'publish') {
                $failed_items[] = $item['product_title'] . __('（商品已下架）');
                continue;
            }

            // 检查库存
            $stock = get_post_meta($item['product_id'], '_stock', true);
            if ($stock !== '' && intval($stock) < $item['quantity']) {
                $failed_items[] = $item['product_title'] . __('（库存不足）');
                continue;
            }

            // 添加到购物车
            $result = $cart_manager->add_item(
                $item['product_id'],
                $item['quantity'],
                $item['specs'] ?? []
            );

            if ($result) {
                $added_count++;
            } else {
                $failed_items[] = $item['product_title'] . __('（添加失败）');
            }

        } catch (Exception $e) {
            $failed_items[] = $item['product_title'] . '（' . $e->getMessage() . '）';
        }
    }

    if ($added_count === 0) {
        throw new Exception(__('没有商品添加到购物车'));
    }

    $message = sprintf(__('成功添加 %d 件商品到购物车'), $added_count);
    if (!empty($failed_items)) {
        $message .= ' ' . __('以下商品添加失败') . ': ' . implode('、', $failed_items);
    }

    echo json_encode([
        'success' => true,
        'message' => $message,
        'added_count' => $added_count,
        'failed_items' => $failed_items
    ]);
}

/**
 * 申请售后/退款
 */
function handle_apply_refund()
{
    global $current_user_id, $tododb;

    $order_id = todo_post_int('order_id');
    $refund_reason = sanitize_text_field(trim(todo_post_string('reason', todo_post_string('refund_reason'))));
    $refund_type = sanitize_text_field(trim(todo_post_string('refund_type', 'refund_only')));
    if (!in_array($refund_type, ['refund_only', 'return_and_refund'], true)) {
        $refund_type = 'refund_only';
    }
    $refund_amount_input = isset($_POST['refund_amount']) ? floatval($_POST['refund_amount']) : 0.0;

    $srv_settings = function_exists('todo_shop_get_service_settings') ? todo_shop_get_service_settings() : [];
    if (empty($srv_settings['allow_refund'])) {
        throw new Exception(__('售后通道已关闭，暂不支持在线申请售后，如有疑问请联系客服'));
    }

    if ($order_id <= 0) {
        throw new Exception(__('订单ID不能为空'));
    }

    if (empty($refund_reason)) {
        throw new Exception(__('请填写售后/退款原因'));
    }

    Todo_Order_Manager::init();

    $order = Todo_Order_Manager::get_order($order_id);
    if (!$order) {
        throw new Exception(__('订单不存在'));
    }

    if (!check_order_permission($order)) {
        throw new Exception(__('无权操作此订单'));
    }

    $pm_status = $order['payment_status'] ?? '';
    $st_status = $order['status'] ?? '';

    // 检查订单是否处于已付款或后续可售后状态
    if ($pm_status === 'refunding' || $st_status === 'refund') {
        throw new Exception(__('该订单已在售后处理中，请勿重复申请'));
    }
    if ($pm_status === 'refunded' || $st_status === 'refunded') {
        throw new Exception(__('该订单已退款，无法再次申请'));
    }
    if ($st_status === 'cancelled') {
        throw new Exception(__('已取消订单无法申请售后'));
    }

    $is_paid_order = ($pm_status === 'paid' || in_array($st_status, ['processing', 'shipped', 'delivered', 'completed'], true));
    if (!$is_paid_order) {
        throw new Exception(__('未支付订单无法申请售后'));
    }

    // 检查售后保障期（按发货时间或完成时间，默认 14 天）
    $return_window_days = isset($srv_settings['return_window_days']) ? intval($srv_settings['return_window_days']) : 14;
    if ($return_window_days > 0) {
        $base_time = !empty($order['completed_at']) ? strtotime($order['completed_at']) : (!empty($order['shipped_at']) ? strtotime($order['shipped_at']) : 0);
        if ($base_time > 0) {
            $expire_time = $base_time + ($return_window_days * 86400);
            if (time() > $expire_time) {
                throw new Exception(sprintf(__('已超过售后保障期限（%d天），无法发起售后申请'), $return_window_days));
            }
        }
    }

    // 检查历史售后申请次数限制（防恶意反复申请，默认最多 3 次）
    $refund_max_attempts = isset($srv_settings['refund_max_attempts']) ? max(1, intval($srv_settings['refund_max_attempts'])) : 3;
    $history_count = intval($tododb->get_var($tododb->prepare(
        "SELECT COUNT(*) FROM {$tododb->prefix}refunds WHERE order_id = %d",
        $order_id
    )));
    if ($history_count >= $refund_max_attempts) {
        throw new Exception(sprintf(__('该订单售后申请次数已达上限（最多 %d 次），如有特殊需求请联系客服'), $refund_max_attempts));
    }

    // 计算退款金额上限（默认为实付金额，若实付金额为0则取总金额）
    $max_amount = floatval($order['payment_amount'] ?? 0);
    if ($max_amount <= 0) {
        $max_amount = floatval($order['total_amount'] ?? 0);
    }

    $refund_amount = $refund_amount_input > 0 ? $refund_amount_input : $max_amount;
    if ($refund_amount <= 0) {
        $refund_amount = $max_amount;
    }
    if ($refund_amount > $max_amount) {
        throw new Exception(sprintf(__('退款金额不能超过可退金额 %s'), function_exists('todo_format_price') ? todo_format_price($max_amount) : number_format($max_amount, 2)));
    }

    // 处理凭证图片（最多 6 张，支持 base64 或 相对路径/URL）
    $raw_images = $_POST['evidence_images'] ?? ($_POST['images'] ?? []);
    if (is_string($raw_images)) {
        $decoded = json_decode($raw_images, true);
        if (is_array($decoded)) {
            $raw_images = $decoded;
        } else {
            $raw_images = array_filter(array_map('trim', explode(',', $raw_images)));
        }
    }
    $safe_images = [];
    if (is_array($raw_images) && !empty($raw_images)) {
        if (count($raw_images) > 6) {
            throw new Exception(__('凭证图片最多只能上传6张'));
        }
        if (!function_exists('todo_process_frontend_image_upload') && defined('ABSPATH')) {
            require_once ABSPATH . 'core/functions/media.php';
        }
        foreach (array_slice($raw_images, 0, 6) as $img) {
            $img = trim((string) $img);
            if ($img === '') continue;
            if (strpos($img, 'data:image/') === 0) {
                $upload_res = todo_process_frontend_image_upload($img, 'refund', $current_user_id, __('订单售后退款凭证'));
                if (empty($upload_res['success'])) {
                    throw new Exception($upload_res['error'] ?? __('凭证图片上传失败'));
                }
                $safe_images[] = $upload_res['url'];
            } elseif (filter_var($img, FILTER_VALIDATE_URL) || strpos($img, '/') === 0) {
                $rel_img = function_exists('todo_media_url_to_relative') ? todo_media_url_to_relative($img) : $img;
                $safe_images[] = $rel_img;
            }
        }
    }
    $evidence_images_json = !empty($safe_images) ? json_encode(array_values($safe_images), JSON_UNESCAPED_UNICODE) : null;

    // 检查是否存在处理中的售后申请
    $existing = $tododb->get_row($tododb->prepare(
        "SELECT id, status FROM {$tododb->prefix}refunds WHERE order_id = %d AND status IN ('pending', 'processing') LIMIT 1",
        $order_id
    ), ARRAY_A);

    if ($existing) {
        throw new Exception(__('该订单已有正在处理中的售后申请'));
    }

    // 插入售后申请记录
    $insert_data = [
        'order_id' => $order_id,
        'payment_id' => 0,
        'refund_amount' => $refund_amount,
        'refund_type' => $refund_type,
        'refund_reason' => $refund_reason,
        'evidence_images' => $evidence_images_json,
        'status' => 'pending',
        'created_by' => $current_user_id > 0 ? $current_user_id : intval($order['user_id'] ?? 0),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    $res = $tododb->insert($tododb->prefix . 'refunds', $insert_data);
    if (!$res) {
        throw new Exception(__('提交售后申请失败'));
    }

    // 更新订单状态为售后申请中
    $update_order_data = [
        'payment_status' => 'refunding',
        'status' => 'refund'
    ];
    Todo_Order_Manager::update_order($order_id, $update_order_data);

    // 记录日志
    $amt_fmt = function_exists('todo_format_price') ? todo_format_price($refund_amount) : number_format($refund_amount, 2);
    $type_label = ($refund_type === 'return_and_refund') ? __('退货退款') : __('仅退款');
    $log_msg = sprintf(__('用户申请售后（%s），申请金额：%s，退款原因：%s'), $type_label, $amt_fmt, $refund_reason);
    if (!empty($safe_images)) {
        $log_msg .= sprintf(__('（含 %d 张凭证图片）'), count($safe_images));
    }
    Todo_Order_Manager::add_order_log($order_id, 'refund_applied', $log_msg, $current_user_id);

    echo json_encode([
        'success' => true,
        'message' => __('售后申请已提交，请等待管理员审核')
    ]);
}

/**
 * 撤销售后申请
 */
function handle_cancel_refund()
{
    global $current_user_id, $tododb;

    $order_id = todo_post_int('order_id');
    if ($order_id <= 0) {
        throw new Exception(__('订单ID不能为空'));
    }

    Todo_Order_Manager::init();

    $order = Todo_Order_Manager::get_order($order_id);
    if (!$order) {
        throw new Exception(__('订单不存在'));
    }

    if (!check_order_permission($order)) {
        throw new Exception(__('无权操作此订单'));
    }

    // 查询该订单待审核的售后记录
    $refund_record = $tododb->get_row($tododb->prepare(
        "SELECT * FROM {$tododb->prefix}refunds WHERE order_id = %d AND status = 'pending' ORDER BY id DESC LIMIT 1",
        $order_id
    ), ARRAY_A);

    if (!$refund_record) {
        throw new Exception(__('未找到待处理的售后申请或申请已被处理'));
    }

    // 更新售后记录为已撤销/已关闭
    $tododb->update(
        $tododb->prefix . 'refunds',
        ['status' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')],
        ['id' => $refund_record['id']]
    );

    // 恢复订单原本的状态：根据是否有发货时间等恢复
    $restored_status = 'processing';
    $update_fields = [
        'payment_status' => 'paid',
        'status' => 'processing'
    ];

    if (!empty($order['shipped_at'])) {
        $restored_status = !empty($order['completed_at']) ? 'completed' : 'shipped';
        $update_fields['status'] = $restored_status;
        
        // 国际标准业务闭环：若在发货后撤销售后，为防止订单被瞬间自动确认收货，
        // 检查原发货时间。若发货距今已接近或超过自动确认周期，顺延顺延缓冲期（至少保留3天）
        if ($restored_status === 'shipped') {
            $srv_settings = function_exists('todo_shop_get_service_settings') ? todo_shop_get_service_settings() : [];
            $auto_days = isset($srv_settings['auto_confirm_days']) ? intval($srv_settings['auto_confirm_days']) : 7;
            if ($auto_days > 0) {
                $shipped_ts = strtotime($order['shipped_at']);
                $buffer_days = min(3, $auto_days); // 至少3天缓冲期
                $cutoff_ts = time() - (($auto_days - $buffer_days) * 86400);
                if ($shipped_ts < $cutoff_ts) {
                    $update_fields['shipped_at'] = date('Y-m-d H:i:s', $cutoff_ts);
                }
            }
        }
    } elseif (!empty($order['completed_at'])) {
        $update_fields['status'] = 'completed';
    }

    Todo_Order_Manager::update_order($order_id, $update_fields);

    // 记录日志
    Todo_Order_Manager::add_order_log($order_id, 'refund_cancelled', __('用户撤销售后申请'), $current_user_id);

    echo json_encode([
        'success' => true,
        'message' => __('已成功撤销售后申请')
    ]);
}

/**
 * 提交退货物流信息
 */
function handle_submit_return_logistics()
{
    global $current_user_id, $tododb;

    $order_id = todo_post_int('order_id');
    $tracking_no = sanitize_text_field(trim(todo_post_string('tracking_no', todo_post_string('return_tracking_no'))));
    $express_company = sanitize_text_field(trim(todo_post_string('express_company', todo_post_string('return_express_company'))));

    if ($order_id <= 0) {
        throw new Exception(__('订单ID不能为空'));
    }
    if (empty($tracking_no)) {
        throw new Exception(__('请填写退货物流运单号'));
    }

    Todo_Order_Manager::init();

    $order = Todo_Order_Manager::get_order($order_id);
    if (!$order) {
        throw new Exception(__('订单不存在'));
    }
    if (!check_order_permission($order)) {
        throw new Exception(__('无权操作此订单'));
    }

    // 查询该订单最新有效售后记录
    $refund_record = $tododb->get_row($tododb->prepare(
        "SELECT * FROM {$tododb->prefix}refunds WHERE order_id = %d AND status IN ('pending', 'processing') ORDER BY id DESC LIMIT 1",
        $order_id
    ), ARRAY_A);

    if (!$refund_record) {
        throw new Exception(__('未找到进行中的售后记录'));
    }

    // 更新退货物流信息
    $update_data = [
        'return_tracking_no' => $tracking_no,
        'return_express_company' => $express_company,
        'return_shipped_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    $tododb->update(
        $tododb->prefix . 'refunds',
        $update_data,
        ['id' => $refund_record['id']]
    );

    // 记录订单日志
    $log_msg = sprintf(__('买家已提交退货物流，快递单号：%s，物流承运商：%s'), $tracking_no, $express_company ?: __('未填写'));
    Todo_Order_Manager::add_order_log($order_id, 'return_shipped', $log_msg, $current_user_id);

    echo json_encode([
        'success' => true,
        'message' => __('退货物流信息已提交成功')
    ]);
}
?>
