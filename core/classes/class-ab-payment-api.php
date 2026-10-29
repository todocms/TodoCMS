<?php
/**
 * AB站跳转支付 (A买B付) 核心通信与数据同步类
 *
 * @package TodoCMS
 * @subpackage Core\Classes
 */

if (!defined('TODO_LOADED')) {
    exit('Direct access denied');
}

class Todo_AB_Payment_API {

    // 可以在后台配置中设置的 Shared Secret
    private $shared_secret;
    private $is_b_site; // 当前站点是否扮演 B 站角色

    public function __construct() {
        // 从配置中获取，若没有则使用默认
        $this->shared_secret = todo_get_option('ab_payment_shared_secret', 'YOUR_DEFAULT_SECRET_KEY_REPLACE_ME');
        $this->is_b_site = todo_get_option('ab_payment_is_b_site', false);
    }

    /**
     * 生成请求签名
     * 
     * @param array $data 要签名的数据
     * @return string 签名串 (HMAC-SHA256)
     */
    public function generate_signature($data) {
        // 移除可能存在的已有签名以及不需要参与签名的辅助传输/路由控制字段
        unset($data['signature'], $data['b_site_endpoint'], $data['format'], $data['action']);
        
        // 按照键名排序，保证双方拼接顺序一致
        ksort($data);
        
        $query_string = http_build_query($data);
        return hash_hmac('sha256', $query_string, $this->shared_secret);
    }

    /**
     * 检查 A 站订单包含的商品是否满足 AB 支付白名单条件
     * 
     * @param int|array $order 订单ID或订单数据数组
     * @return bool 是否允许走 AB 支付
     */
    public static function is_order_in_whitelist($order) {
        $whitelist_str = todo_get_option('ab_payment_product_whitelist', '');
        $whitelist_str = trim($whitelist_str);

        // 如果白名单留空，则全站默认均走 AB 支付
        if ($whitelist_str === '') {
            return true;
        }

        // 解析白名单配置 A_ID:B_ID（兼容逗号、空格、换行符分隔）
        $pairs = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $whitelist_str)));
        $whitelisted_a_ids = [];
        foreach ($pairs as $pair) {
            $parts = array_map('trim', explode(':', $pair));
            if (isset($parts[0]) && $parts[0] !== '') {
                $whitelisted_a_ids[] = intval($parts[0]);
            }
        }

        if (empty($whitelisted_a_ids)) {
            return true;
        }

        // 获取订单中的所有商品 ID
        $order_id = is_array($order) ? intval($order['id'] ?? 0) : intval($order);
        if ($order_id <= 0) {
            return false;
        }

        $order_items = [];
        if (class_exists('Todo_Order_Manager')) {
            $order_items = Todo_Order_Manager::get_order_items($order_id);
        } else {
            global $tododb;
            $items_table = $tododb->prefix . 'order_items';
            $order_items = $tododb->get_results($tododb->prepare("SELECT product_id FROM {$items_table} WHERE order_id = %d", $order_id), [], 'ARRAY_A');
        }

        if (empty($order_items) || !is_array($order_items)) {
            return false;
        }

        // 检查订单商品中是否有任意一个在 A 站白名单内
        foreach ($order_items as $item) {
            $pid = intval($item['product_id'] ?? 0);
            if ($pid > 0 && in_array($pid, $whitelisted_a_ids, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 根据 A 站商品 ID 解析 B 站白名单对应的真实商品 ID
     * 
     * @param int $a_product_id A 站商品 ID
     * @return int B 站商品 ID，如无映射返回 0
     */
    public static function get_b_product_id_from_whitelist($a_product_id) {
        $whitelist_str = todo_get_option('ab_payment_product_whitelist', '');
        $whitelist_str = trim($whitelist_str);
        if ($whitelist_str === '') {
            return 0;
        }

        // 兼容换行符、逗号与空格
        $pairs = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $whitelist_str)));
        foreach ($pairs as $pair) {
            $parts = array_map('trim', explode(':', $pair));
            if (count($parts) >= 2 && intval($parts[0]) === intval($a_product_id)) {
                return intval($parts[1]);
            }
        }

        return 0;
    }

    /**
     * 验证签名
     * 
     * @param array $data 接收到的数据（包含 signature）
     * @return bool 签名是否有效
     */
    public function verify_signature($data) {
        if (empty($data['signature'])) {
            return false;
        }

        $provided_signature = $data['signature'];
        $calculated_signature = $this->generate_signature($data);

        $is_valid = hash_equals($calculated_signature, $provided_signature);
        if (!$is_valid && class_exists('Todo_Error_Handler')) {
            Todo_Error_Handler::log("AB_PAYMENT Signature mismatch! Provided: {$provided_signature}, Calculated: {$calculated_signature}, SharedSecretLen: " . strlen($this->shared_secret), Todo_Error_Handler::LEVEL_ERROR);
        }

        return $is_valid;
    }

    /**
     * [A站调用] 组装发送给B站的支付请求数据
     *
     * @param array $order A站订单数据快照
     * @param string $b_site_url 选定的B站URL
     * @return array 包含签名和完整数据的请求包
     */
    public function build_payment_request_for_b_site($order, $b_site_url) {
        $amt = !empty($order['payment_amount']) && floatval($order['payment_amount']) > 0 
            ? floatval($order['payment_amount']) 
            : (isset($order['total_amount']) ? floatval($order['total_amount']) : 0.00);

        // 如果 guest_email 为空且订单绑有 user_id，从 users 表查询买家真实邮箱
        $email = $order['guest_email'] ?? ($order['email'] ?? '');
        if (empty($email) && !empty($order['user_id'])) {
            global $tododb;
            if ($tododb && isset($tododb->prefix)) {
                $users_table = $tododb->prefix . 'users';
                $email = (string) $tododb->get_var($tododb->prepare("SELECT user_email FROM {$users_table} WHERE ID = %d LIMIT 1", intval($order['user_id'])));
            }
        }

        $shipping_fee = isset($order['shipping_fee']) ? floatval($order['shipping_fee']) : 0.00;

        $payload = [
            'a_order_no'         => $order['order_no'],
            'amount'             => number_format($amt, 2, '.', ''),
            'shipping_fee'       => number_format($shipping_fee, 2, '.', ''),
            'currency'           => function_exists('todo_get_default_currency_config') ? (todo_get_default_currency_config()['code'] ?? '') : '',
            'timestamp'          => time(),
            'notify_url'         => todo_get_site_url() . '/api/ab-webhook.php', // B站异步通知A站的地址
            'return_url'         => todo_get_site_url() . '/?user=order-success&order_no=' . $order['order_no'], // 用户支付成功跳回A站
            'a_site_url'         => todo_get_site_url(),
            'payment_method'     => $order['payment_method'] ?? 'paypal',
            'customer_name'      => $order['customer_name'] ?? ($order['shipping_address_data']['name'] ?? ($order['shipping_address_data']['first_name'] ?? '')),
            'shipping_address'   => is_array($order['shipping_address_data'] ?? null) ? json_encode($order['shipping_address_data'], JSON_UNESCAPED_UNICODE) : (is_array($order['shipping_address'] ?? null) ? json_encode($order['shipping_address'], JSON_UNESCAPED_UNICODE) : ($order['shipping_address'] ?? '')),
            'billing_address'    => is_array($order['billing_address_data'] ?? null) ? json_encode($order['billing_address_data'], JSON_UNESCAPED_UNICODE) : (is_array($order['billing_address'] ?? null) ? json_encode($order['billing_address'], JSON_UNESCAPED_UNICODE) : ($order['billing_address'] ?? '')),
            'guest_email'        => $email,
            'remark'             => $order['remark'] ?? ($order['order_note'] ?? ''),
            'lang'               => class_exists('Todo_Order_Manager') ? Todo_Order_Manager::get_order_language($order) : ($order['lang'] ?? 'zh-CN'),
        ];

        // 获取 A 站订单首个商品 ID 及其在 A 站白名单中配置的 B 站目标商品 ID
        $a_product_id = 0;
        $b_product_id = 0;
        $order_id = intval($order['id'] ?? 0);
        if (!empty($order['items']) && is_array($order['items']) && !empty($order['items'][0]['product_id'])) {
            $a_product_id = intval($order['items'][0]['product_id']);
        } elseif ($order_id > 0 && class_exists('Todo_Order_Manager')) {
            $items = Todo_Order_Manager::get_order_items($order_id);
            if (!empty($items[0]['product_id'])) {
                $a_product_id = intval($items[0]['product_id']);
            }
        }
        if ($a_product_id > 0) {
            $b_product_id = self::get_b_product_id_from_whitelist($a_product_id);
        }
        $payload['a_product_id'] = $a_product_id;
        $payload['b_product_id'] = $b_product_id;

        $client_ip = class_exists('Todo_Order_Manager') ? (Todo_Order_Manager::get_order_custom_field($order_id, '_client_ip') ?: (function_exists('todo_get_client_ip') ? todo_get_client_ip() : '')) : '';
        $device_info = class_exists('Todo_Order_Manager') ? Todo_Order_Manager::get_order_custom_field($order_id, '_device_info') : null;
        $payload['client_ip'] = $client_ip;
        $payload['device_info'] = is_array($device_info) ? json_encode($device_info, JSON_UNESCAPED_UNICODE) : ($device_info ?? '');

        // 脱敏商品名称
        $payload['product_name'] = 'Standard Service / Goods'; 

        $payload['signature'] = $this->generate_signature($payload);
        $payload['b_site_endpoint'] = rtrim($b_site_url, '/') . '/api/ab-receive.php';

        return $payload;
    }

    /**
     * [B站调用] 接收A站请求并生成B站影子订单
     * 
     * @param array $post_data 接收到的POST数据
     * @return array|Todo_Error 处理结果
     */
    public function handle_a_site_request($post_data) {
        global $tododb;
        if (class_exists('Todo_Error_Handler')) {
            Todo_Error_Handler::log("AB_PAYMENT [B-Site] Received request from A-site", Todo_Error_Handler::LEVEL_INFO, ['data' => $post_data]);
        }

        if (!$this->is_b_site) {
            if (class_exists('Todo_Error_Handler')) {
                Todo_Error_Handler::log("AB_PAYMENT [B-Site] Rejected request: Not configured as B-Site", Todo_Error_Handler::LEVEL_ERROR);
            }
            return new Todo_Error('not_b_site', '当前站点未开启B站接收模式');
        }

        if (!$this->verify_signature($post_data)) {
            if (class_exists('Todo_Error_Handler')) {
                Todo_Error_Handler::log("AB_PAYMENT [B-Site] Signature verification failed for A order {$post_data['a_order_no']}", Todo_Error_Handler::LEVEL_ERROR, ['data' => $post_data]);
            }
            return new Todo_Error('invalid_signature', '签名验证失败，请求可能被篡改');
        }

        // 验证时效性 (防重放攻击)
        if (time() - $post_data['timestamp'] > 300) {
            if (class_exists('Todo_Error_Handler')) {
                Todo_Error_Handler::log("AB_PAYMENT [B-Site] Request timestamp expired for A order {$post_data['a_order_no']}", Todo_Error_Handler::LEVEL_ERROR);
            }
            return new Todo_Error('request_expired', '请求已过期');
        }

        // 确定 B 站影子单号与真实支付方式（与 A 站单号前缀与语言规则保持同步，如 EN2610091636443489）
        $lang = !empty($post_data['lang']) ? sanitize_text_field($post_data['lang']) : '';
        if (empty($lang) && !empty($post_data['a_order_no'])) {
            $lang = class_exists('Todo_Order_Manager') ? Todo_Order_Manager::get_order_language(['order_no' => $post_data['a_order_no']]) : 'en-US';
        }
        if (class_exists('Todo_Order_Manager')) {
            Todo_Order_Manager::init();
            $b_order_no = Todo_Order_Manager::generate_order_no($lang);
        } else {
            $lang_prefix = 'BB';
            if (!empty($lang)) {
                $clean = preg_replace('/[^a-z]/i', '', $lang);
                if (strlen($clean) >= 2) {
                    $lang_prefix = strtoupper(substr($clean, 0, 2));
                }
            }
            $b_order_no = $lang_prefix . date('ymdHis') . str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
        }
        $real_pay_method = !empty($post_data['payment_method']) ? sanitize_text_field($post_data['payment_method']) : 'paypal';

        // 在 B 站数据库自动匹配或注册真实用户，确保 user_id 为数据库真实自增长 ID
        $user_id = 0;
        $guest_email = !empty($post_data['guest_email']) ? sanitize_email($post_data['guest_email']) : '';
        $cust_name = sanitize_text_field((string) ($post_data['customer_name'] ?? ''));
        $users_table = $tododb->prefix . 'users';
        $user_id = 0;

        // 优先在 B 站根据 邮箱 或 用户名 自动检索查找匹配的现有用户（实现归一化，同一用户多单复用同一账号）
        if ($guest_email) {
            $user_row = $tododb->get_row($tododb->prepare("SELECT ID FROM {$users_table} WHERE user_email = %s LIMIT 1", $guest_email));
            $user_id = is_object($user_row) ? intval($user_row->ID ?? 0) : (is_array($user_row) ? intval($user_row['ID'] ?? 0) : 0);
        }
        if (!$user_id && $cust_name) {
            $user_row = $tododb->get_row($tododb->prepare("SELECT ID FROM {$users_table} WHERE user_login = %s OR display_name = %s LIMIT 1", $cust_name, $cust_name));
            $user_id = is_object($user_row) ? intval($user_row->ID ?? 0) : (is_array($user_row) ? intval($user_row['ID'] ?? 0) : 0);
        }

        // 如果在 B 站依然未找到该用户，才自动注册专属独立用户
        if (!$user_id) {
            if (!$guest_email) {
                $guest_email = 'customer_' . date('Ymd') . '_' . rand(10000, 99999) . '@guest.com';
            }
            $username = strstr($guest_email, '@', true) ?: ('user_' . rand(10000, 99999));
            $username = preg_replace('/[^a-zA-Z0-9_\-]/', '', $username) ?: ('user_' . rand(10000, 99999));
            // 防止 user_login 冲突
            $login_check = intval($tododb->get_var($tododb->prepare("SELECT COUNT(*) FROM {$users_table} WHERE user_login = ?", $username)));
            if ($login_check > 0) {
                $username .= '_' . rand(1000, 9999);
            }
            
            $tododb->insert($users_table, [
                'user_login'      => $username,
                'user_email'      => $guest_email,
                'user_pass'       => password_hash(uniqid('ab_user_'), PASSWORD_DEFAULT),
                'display_name'    => $cust_name ?: $username,
                'user_registered' => current_time('mysql')
            ]);
            $user_id = intval($tododb->insert_id);
        }

        // 查找或自动在 B 站关联真实商品 (posts/products)，优先匹配 A 站传递的目标 b_product_id 或本地白名单映射
        $p_name = !empty($post_data['product_name']) ? sanitize_text_field($post_data['product_name']) : 'Standard Service / Goods';
        $b_product_id = intval($post_data['b_product_id'] ?? 0);
        $posts_table = $tododb->prefix . 'posts';
        
        $a_product_id = intval($post_data['a_product_id'] ?? 0);
        if ($b_product_id <= 0 && $a_product_id > 0) {
            $b_product_id = self::get_b_product_id_from_whitelist($a_product_id);
        }

        if ($b_product_id > 0) {
            $check_b_prod = $tododb->get_var($tododb->prepare("SELECT ID FROM {$posts_table} WHERE ID = %d LIMIT 1", $b_product_id));
            if (!$check_b_prod) {
                $b_product_id = 0;
            }
        }

        $posts_table = $tododb->prefix . 'posts';
        if ($b_product_id <= 0) {
            // 优先在 B 站查找现有的真实商品
            $existing_product = $tododb->get_row("SELECT ID, post_title FROM {$posts_table} WHERE post_type IN ('product', 'shop_product', 'goods') OR model_id = 2 ORDER BY ID ASC LIMIT 1");
            if ($existing_product && !empty($existing_product->ID)) {
                $b_product_id = intval($existing_product->ID);
                if (!empty($existing_product->post_title)) {
                    $p_name = $existing_product->post_title;
                }
            } else {
                // 如果 B 站数据库没有任何商品，自动创建一个真实规范商品
                $tododb->insert($posts_table, [
                    'post_title' => $p_name,
                    'post_name' => 'standard-goods-' . rand(100, 999),
                    'post_content' => 'Standard compliance merchandise.',
                    'post_status' => 'publish',
                    'post_type' => 'product',
                    'post_date' => current_time('mysql')
                ]);
                $b_product_id = intval($tododb->insert_id);
            }
        } else {
            // 如果成功匹配/关联到了 B 站商品，自动读取 B 站商品的实际标题
            $b_title = $tododb->get_var($tododb->prepare("SELECT post_title FROM {$posts_table} WHERE ID = %d LIMIT 1", $b_product_id));
            if (!empty($b_title)) {
                $p_name = $b_title;
            }
        }

        $pay_amount = floatval($post_data['amount'] ?? 0);
        $shipping_fee = isset($post_data['shipping_fee']) ? floatval($post_data['shipping_fee']) : 0.00;
        if ($shipping_fee < 0) {
            $shipping_fee = 0.00;
        }
        if ($shipping_fee > $pay_amount) {
            $shipping_fee = $pay_amount;
        }

        // B影子订单商品总额 = 实付款 - 实际运费 (忽略优惠券/积分等优惠信息)
        $goods_total = round(max(0, $pay_amount - $shipping_fee), 2);

        $items = [
            [
                'product_id'    => $b_product_id,
                'product_title' => $p_name,
                'quantity'      => 1,
                'price'         => $goods_total,
                'total_price'   => $goods_total
            ]
        ];

        $order_data = [
            'order_no'         => $b_order_no,
            'user_id'          => $user_id,
            'total_amount'     => $goods_total,
            'shipping_fee'     => $shipping_fee,
            'payment_amount'   => $pay_amount,
            'payment_method'   => $real_pay_method,
            'status'           => 'pending',
            'payment_status'   => 'unpaid',
            'ab_order_type'    => 'site_b',
            'ab_ref_order_no'  => $post_data['a_order_no'],
            'customer_name'    => $cust_name,
            'shipping_address' => (string) ($post_data['shipping_address'] ?? ''),
            'billing_address'  => (string) ($post_data['billing_address'] ?? ''),
            'remark'           => sanitize_text_field((string) ($post_data['remark'] ?? '')),
            'items'            => $items
        ];
        
        $table = $tododb->prefix . 'orders';
        $db_order_data = [
            'order_no'         => $b_order_no,
            'user_id'          => $user_id,
            'total_amount'     => $pay_amount,
            'payment_amount'   => $pay_amount,
            'shipping_fee'     => $shipping_fee,
            'discount_amount'  => 0.00,
            'status'           => 'pending',
            'payment_status'   => 'unpaid',
            'shipping_status'  => 'unshipped',
            'payment_method'   => $real_pay_method,
            'shipping_method'  => 'express',
            'ab_order_type'    => 'site_b',
            'ab_ref_order_no'  => $post_data['a_order_no'],
            'shipping_address' => (string) ($post_data['shipping_address'] ?? ''),
            'remark'           => sanitize_text_field((string) ($post_data['remark'] ?? '')),
            'created_at'       => current_time('mysql')
        ];
        
        $b_order_id = 0;
        $result = $tododb->insert($table, $db_order_data);
        $last_err_captured = !empty($tododb->last_error) ? $tododb->last_error : (method_exists($tododb, 'get_last_error') ? $tododb->get_last_error() : '');
        
        if ($result) {
            $b_order_id = intval($tododb->insert_id);
            $items_table = $tododb->prefix . 'order_items';
            $tododb->insert($items_table, [
                'order_id'      => $b_order_id,
                'product_id'    => $b_product_id,
                'product_name'  => $p_name,
                'quantity'      => 1,
                'price'         => $goods_total,
                'total_price'   => $goods_total,
                'created_at'    => current_time('mysql')
            ]);

            if (class_exists('Todo_Order_Manager')) {
                Todo_Order_Manager::add_order_log($b_order_id, 'order_created', '订单创建', $user_id);
            }

            // 通过 custom_fields 保存 customer_name、shipping_address 与 billing_address
            if (class_exists('Todo_Order_Manager')) {
                if ($cust_name !== '') {
                    Todo_Order_Manager::save_order_custom_field($b_order_id, 'customer_name', $cust_name);
                }
                if (!empty($post_data['shipping_address'])) {
                    Todo_Order_Manager::save_order_custom_field($b_order_id, '_checkout_shipping_address', $post_data['shipping_address']);
                    Todo_Order_Manager::save_order_custom_field($b_order_id, 'shipping_address_data', is_string($post_data['shipping_address']) ? (json_decode($post_data['shipping_address'], true) ?: $post_data['shipping_address']) : $post_data['shipping_address']);
                }
                if (!empty($post_data['billing_address'])) {
                    Todo_Order_Manager::save_order_custom_field($b_order_id, 'billing_address', $post_data['billing_address']);
                }
            }
        }
        
        if (!$b_order_id) {
            if (class_exists('Todo_Error_Handler')) {
                $db_err = !empty($last_err_captured) ? $last_err_captured : (!empty($tododb->last_error) ? $tododb->last_error : 'Unknown DB error');
                Todo_Error_Handler::log("AB_PAYMENT [B-Site] Failed to create shadow order for A order {$post_data['a_order_no']}. DB Error: {$db_err}", Todo_Error_Handler::LEVEL_ERROR);
            }
            return new Todo_Error('create_order_failed', 'B站影子订单创建失败');
        }
        if ($guest_email && class_exists('Todo_Order_Manager')) {
            Todo_Order_Manager::save_order_custom_field($b_order_id, '_guest_email', $guest_email);
            Todo_Order_Manager::save_order_custom_field($b_order_id, 'guest_email', $guest_email);
        }

        if (class_exists('Todo_Order_Manager')) {
            if (!empty($lang)) {
                Todo_Order_Manager::save_order_custom_field($b_order_id, '_order_lang', $lang);
            }
            Todo_Order_Manager::save_order_custom_field($b_order_id, '_ab_source_amount', round(floatval($post_data['amount'] ?? 0), 2));
            Todo_Order_Manager::save_order_custom_field($b_order_id, '_ab_source_currency', sanitize_text_field((string) ($post_data['currency'] ?? '')));
            
            if (!empty($post_data['client_ip'])) {
                Todo_Order_Manager::save_order_custom_field($b_order_id, '_client_ip', sanitize_text_field($post_data['client_ip']));
            }
            if (!empty($post_data['device_info'])) {
                $dev_data = is_array($post_data['device_info']) ? $post_data['device_info'] : json_decode($post_data['device_info'], true);
                if ($dev_data) Todo_Order_Manager::save_order_custom_field($b_order_id, '_device_info', $dev_data);
            }
        }

        // 保存 A 站的回调地址
        update_option('ab_notify_' . $b_order_no, $post_data['notify_url']);
        update_option('ab_return_' . $b_order_no, $post_data['return_url']);

        if (class_exists('Todo_Error_Handler')) {
            Todo_Error_Handler::log("AB_PAYMENT [B-Site] Shadow order {$b_order_no} created successfully for A order {$post_data['a_order_no']}", Todo_Error_Handler::LEVEL_INFO);
        }

        return [
            'b_order_no' => $b_order_no,
            'b_order_id' => $b_order_id,
            'checkout_url' => todo_get_site_url() . '/?user=payment&order_id=' . $b_order_id // B站收银台地址
        ];
    }
    
    /**
     * [B站调用] 支付成功后通知A站
     * 
     * @param string $b_order_no B站订单号
     * @param string $transaction_id 支付网关交易流水号
     * @return bool
     */
    public function notify_a_site_success($b_order_no, $transaction_id) {
        global $tododb;
        $table = $tododb->prefix . 'orders';
        $order = $tododb->get_row("SELECT * FROM {$table} WHERE order_no = ? AND ab_order_type = 'site_b'", [$b_order_no], ARRAY_A);
        
        if (!$order) {
            if (class_exists('Todo_Error_Handler')) {
                Todo_Error_Handler::log("AB_PAYMENT [B-Site] Notify A-Site failed: B order {$b_order_no} not found", Todo_Error_Handler::LEVEL_ERROR);
            }
            return false;
        }
        
        $notify_url = todo_get_option('ab_notify_' . $b_order_no);
        if (!$notify_url) {
            if (class_exists('Todo_Error_Handler')) {
                Todo_Error_Handler::log("AB_PAYMENT [B-Site] Notify A-Site failed: notify_url for B order {$b_order_no} not found", Todo_Error_Handler::LEVEL_ERROR);
            }
            return false; // 找不到A站回调地址
        }
        
        $payload_amount = round(floatval($order['total_amount'] ?? 0), 2);
        if (class_exists('Todo_Order_Manager')) {
            $saved_amount = Todo_Order_Manager::get_order_custom_field(intval($order['id'] ?? 0), '_ab_source_amount');
            if ($saved_amount !== null && $saved_amount !== '') {
                $payload_amount = round(floatval($saved_amount), 2);
            }
        }

        $b_cb_addr = class_exists('Todo_Order_Manager') ? Todo_Order_Manager::get_order_custom_field(intval($order['id'] ?? 0), '_paypal_callback_address') : null;
        $b_evidence = class_exists('Todo_Order_Manager') ? Todo_Order_Manager::get_order_custom_field(intval($order['id'] ?? 0), '_anti_fraud_evidence') : null;
        $b_paypal_order_id = class_exists('Todo_Order_Manager') ? Todo_Order_Manager::get_order_custom_field(intval($order['id'] ?? 0), '_paypal_order_id') : null;

        $payload = [
            'a_order_no'              => $order['ab_ref_order_no'],
            'b_order_no'              => $b_order_no,
            'status'                  => 'paid',
            'transaction_id'          => $transaction_id,
            'amount'                  => $payload_amount,
            'currency'                => class_exists('Todo_Order_Manager') ? (Todo_Order_Manager::get_order_custom_field(intval($order['id'] ?? 0), '_ab_source_currency') ?: '') : '',
            'payment_method'          => $order['payment_method'] ?? 'paypal',
            'paypal_order_id'         => (string) ($b_paypal_order_id ?? ''),
            'paypal_callback_address' => is_array($b_cb_addr) ? json_encode($b_cb_addr, JSON_UNESCAPED_UNICODE) : ($b_cb_addr ?? ''),
            'anti_fraud_evidence'     => is_array($b_evidence) ? json_encode($b_evidence, JSON_UNESCAPED_UNICODE) : ($b_evidence ?? ''),
            'timestamp'               => time(),
        ];
        
        $payload['signature'] = $this->generate_signature($payload);
        
        if (class_exists('Todo_Error_Handler')) {
            Todo_Error_Handler::log("AB_PAYMENT [B-Site] Sending webhook notification to A-Site: {$notify_url}", Todo_Error_Handler::LEVEL_INFO, ['payload' => $payload]);
        }

        // 发送 POST 请求到 A站
        $ch = curl_init($notify_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($http_code == 200) {
            if (class_exists('Todo_Error_Handler')) {
                Todo_Error_Handler::log("AB_PAYMENT [B-Site] Webhook notification to A-Site succeeded (HTTP 200)", Todo_Error_Handler::LEVEL_INFO);
            }
            return true;
        } else {
            if (class_exists('Todo_Error_Handler')) {
                Todo_Error_Handler::log("AB_PAYMENT [B-Site] Webhook notification to A-Site failed (HTTP {$http_code}, curl_error: {$curl_error})", Todo_Error_Handler::LEVEL_ERROR, ['response' => $response]);
            }
            return false;
        }
    }

    /**
     * 手动/自动同步 AB 订单全量数据
     * 
     * @param int $order_id 本地订单ID
     * @return array|Todo_Error
     */
    public function sync_ab_order($order_id) {
        if (class_exists('Todo_Order_Manager')) {
            Todo_Order_Manager::init();
        }
        global $tododb;
        $table = $tododb->prefix . 'orders';
        $order_raw = $tododb->get_row("SELECT * FROM {$table} WHERE id = " . intval($order_id));
        $order = $order_raw ? (array)$order_raw : null;

        if (!$order) {
            return new Todo_Error('order_not_found', '订单不存在');
        }

        $ab_type = $order['ab_order_type'] ?? '';
        if (!$ab_type || !in_array($ab_type, ['site_a', 'site_b'])) {
            return new Todo_Error('not_ab_order', '该订单不是AB支付订单');
        }

        // 获取详细发货/物流记录、交易号与元数据
        $shipping_records = [];
        if (class_exists('Todo_Order_Manager')) {
            $shipping_meta = Todo_Order_Manager::get_order_custom_field($order_id, 'shipping_data');
            $shipping_address_data = Todo_Order_Manager::get_order_custom_field($order_id, 'shipping_address_data');
            $billing_address_data = Todo_Order_Manager::get_order_custom_field($order_id, 'billing_address_data');
            $shipping_records = Todo_Order_Manager::get_shipping_records($order_id);
        } else {
            $shipping_meta = null;
            $shipping_address_data = null;
            $billing_address_data = null;
        }

        $payload = [
            'action'                  => 'sync_ab_order_remote',
            'sender_type'             => $this->is_b_site ? 'site_b' : 'site_a',
            'order_no'                => $order['order_no'],
            'ab_ref_order_no'         => $order['ab_ref_order_no'] ?? '',
            'status'                  => $order['status'],
            'payment_status'          => $order['payment_status'],
            'payment_method'          => $order['payment_method'],
            'transaction_id'          => $order['transaction_id'] ?? '',
            'paid_at'                 => $order['paid_at'] ?? ($order['payment_time'] ?? ''),
            'created_at'              => $order['created_at'] ?? '',
            'express_company'         => $order['express_company'] ?? '',
            'tracking_no'             => $order['tracking_no'] ?? '',
            'customer_name'           => $order['customer_name'] ?? '',
            'shipping_address'        => $order['shipping_address'] ?? '',
            'billing_address'         => $order['billing_address'] ?? '',
            'remark'                  => $order['remark'] ?? '',
            'shipping_data'           => is_array($shipping_meta) ? json_encode($shipping_meta, JSON_UNESCAPED_UNICODE) : ($shipping_meta ?? ''),
            'shipping_address_data'  => is_array($shipping_address_data) ? json_encode($shipping_address_data, JSON_UNESCAPED_UNICODE) : ($shipping_address_data ?? ''),
            'billing_address_data'   => is_array($billing_address_data) ? json_encode($billing_address_data, JSON_UNESCAPED_UNICODE) : ($billing_address_data ?? ''),
            'shipping_records'        => is_array($shipping_records) ? json_encode($shipping_records, JSON_UNESCAPED_UNICODE) : '',
            'order_logs'              => class_exists('Todo_Order_Manager') ? json_encode($tododb->get_results("SELECT action, message, operator_id, created_at FROM " . DB_PREFIX . "order_logs WHERE order_id = " . intval($order_id), ARRAY_A) ?: [], JSON_UNESCAPED_UNICODE) : '',
            'timestamp'               => time(),
        ];

        $payload['signature'] = $this->generate_signature($payload);

        // 确定远程端点
        if ($this->is_b_site) {
            // B 站发往 A 站：优先从 ab_notify_{order_no} 或配置中解析 A 站 Endpoint
            $notify_url = todo_get_option('ab_notify_' . $order['order_no']) ?: todo_get_option('ab_notify_' . ($order['ab_ref_order_no'] ?? ''));
            $a_site_url = todo_get_option('ab_payment_a_site_url', '');
            if ($notify_url) {
                $target_endpoint = str_replace('ab-webhook.php', 'ab-receive.php', $notify_url);
            } else if ($a_site_url) {
                $target_endpoint = rtrim($a_site_url, '/') . '/api/ab-receive.php';
            } else {
                $target_endpoint = '';
            }
        } else {
            // A 站发往 B 站：优先使用订单记录时关联分配的 ab_b_site_url
            $b_site_url = !empty($order['ab_b_site_url']) ? $order['ab_b_site_url'] : '';
            if (!$b_site_url) {
                $b_site_urls = todo_get_option('ab_payment_b_site_urls', '') ?: todo_get_option('ab_payment_b_site_url', '');
                if ($b_site_urls) {
                    $b_arr = array_filter(array_map('trim', explode(',', $b_site_urls)));
                    $b_site_url = reset($b_arr);
                }
            }
            if (!$b_site_url && defined('AB_PAYMENT_B_SITE_URL')) {
                $b_site_url = AB_PAYMENT_B_SITE_URL;
            }
            
            if ($b_site_url && strpos($b_site_url, 'ab-receive.php') !== false) {
                $target_endpoint = $b_site_url;
            } else if ($b_site_url) {
                $target_endpoint = rtrim($b_site_url, '/') . '/api/ab-receive.php';
            } else {
                $target_endpoint = '';
            }
        }

        if (empty($target_endpoint) || filter_var($target_endpoint, FILTER_VALIDATE_URL) === false) {
            return new Todo_Error('missing_endpoint', '无法获取对端通信 Endpoint 地址');
        }

        $ch = curl_init($target_endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($http_code !== 200 || !$response) {
            return new Todo_Error('remote_sync_failed', "对端同步请求失败 (HTTP {$http_code}: {$curl_error})");
        }

        $res_data = json_decode($response, true);
        if (empty($res_data['success'])) {
            return new Todo_Error('remote_sync_error', $res_data['error'] ?? '对端返回同步失败');
        }

        // 双向同步打点回写：将对端拉取到的字段合并更新回本地数据库
        $synced_items = [];
        if (!empty($res_data['remote_order'])) {
            $remote = $res_data['remote_order'];
            $update_fields = [];
            
            if (!empty($remote['customer_name']) && empty($order['customer_name'])) {
                $update_fields['customer_name'] = $remote['customer_name'];
                $synced_items[] = '客户姓名';
            }
            if (!empty($remote['shipping_address']) && empty($order['shipping_address'])) {
                $update_fields['shipping_address'] = $remote['shipping_address'];
                $synced_items[] = '收货地址';
            }
            if (!empty($remote['billing_address']) && empty($order['billing_address'])) {
                $update_fields['billing_address'] = $remote['billing_address'];
                $synced_items[] = '账单地址';
            }
            $pay_ranks = ['pending' => 1, 'unpaid' => 1, 'paid' => 2, 'refunded' => 3, 'partial_refunded' => 3];
            $current_pst = $order['payment_status'] ?? 'unpaid';
            $remote_pst = $remote['payment_status'] ?? '';
            $current_prank = $pay_ranks[$current_pst] ?? 0;
            $remote_prank = $pay_ranks[$remote_pst] ?? 0;

            if (!empty($remote_pst) && $remote_pst !== $current_pst && $remote_prank > $current_prank) {
                $old_pst = ($current_pst === 'pending' || $current_pst === '') ? 'unpaid' : $current_pst;
                $new_pst = $remote_pst;
                $update_fields['payment_status'] = $new_pst;
                $synced_items[] = '支付状态(' . $new_pst . ')';
                if (class_exists('Todo_Order_Manager')) {
                    Todo_Order_Manager::add_order_log($order_id, 'payment_status_updated', "支付状态: 从 '" . $old_pst . "' 变更为 '" . $new_pst . "'", 0);
                }
            }
            // 状态顺向连动与防倒退保护：仅当对端状态处于更高级生命周期阶段时才允许向本地覆盖，防止旧状态覆盖回退
            $status_ranks = [
                'pending' => 1, 'unpaid' => 1, 'paid' => 2, 'processing' => 3, 
                'shipped' => 4, 'delivered' => 5, 'completed' => 6, 'refunded' => 7, 'cancelled' => 7
            ];
            $current_st = $order['status'] ?? 'pending';
            $remote_st = $remote['status'] ?? '';
            $current_rank = $status_ranks[$current_st] ?? 0;
            $remote_rank = $status_ranks[$remote_st] ?? 0;

            if (!empty($remote_st) && $remote_st !== $current_st && $remote_rank > $current_rank) {
                $old_st = $current_st;
                $new_st = $remote_st;
                $update_fields['status'] = $new_st;
                $synced_items[] = '订单状态(' . $new_st . ')';
                if (class_exists('Todo_Order_Manager')) {
                    Todo_Order_Manager::add_order_log($order_id, 'status_updated', "订单状态: 从 '" . $old_st . "' 变更为 '" . $new_st . "'", 0);
                    if ($new_st === 'completed') {
                        Todo_Order_Manager::add_order_log($order_id, 'confirm_received', '用户确认收货', 0);
                    }
                }
            }
            if (!empty($remote['paid_at']) && empty($order['paid_at'])) {
                $update_fields['paid_at'] = $remote['paid_at'];
                $synced_items[] = '支付时间(' . $remote['paid_at'] . ')';
            }
            if (!empty($remote['transaction_id']) && (empty($order['transaction_id']) || $order['transaction_id'] !== $remote['transaction_id'])) {
                $update_fields['transaction_id'] = $remote['transaction_id'];
                $synced_items[] = '交易号(' . $remote['transaction_id'] . ')';
                
                // 补全/同步 order_payments 支付记录表中的交易号
                if (class_exists('Todo_Order_Manager')) {
                    $existing_pay_recs = Todo_Order_Manager::get_payment_records($order_id);
                    $has_trans = false;
                    if (!empty($existing_pay_recs)) {
                        foreach ($existing_pay_recs as $rec) {
                            if (!empty($rec['transaction_id'])) {
                                $has_trans = true;
                                break;
                            }
                        }
                    }
                    if (!$has_trans) {
                        // 如果已存在无交易号的支付记录，更新第一条记录的交易号
                        if (!empty($existing_pay_recs) && isset($existing_pay_recs[0]['id']) && $existing_pay_recs[0]['id'] > 0) {
                            $tododb->update(DB_PREFIX . 'order_payments', ['transaction_id' => $remote['transaction_id']], ['id' => $existing_pay_recs[0]['id']]);
                        } else {
                            Todo_Order_Manager::add_payment_record($order_id, [
                                'payment_method' => $remote['payment_method'] ?? ($order['payment_method'] ?? 'paypal'),
                                'amount' => floatval($order['total_amount'] ?? 0),
                                'transaction_id' => $remote['transaction_id'],
                                'payment_status' => 'paid',
                                'notes' => '通过AB订单同步拉取'
                            ]);
                        }
                    }
                }
            }
            if (!empty($remote['express_company']) && ($order['express_company'] ?? '') !== $remote['express_company']) {
                $update_fields['express_company'] = $remote['express_company'];
                $synced_items[] = '快递公司(' . $remote['express_company'] . ')';
            }
            if (!empty($remote['tracking_no']) && ($order['tracking_no'] ?? '') !== $remote['tracking_no']) {
                $update_fields['tracking_no'] = $remote['tracking_no'];
                $synced_items[] = '快递单号(' . $remote['tracking_no'] . ')';
            }
            
            if ($update_fields) {
                $tododb->update($table, $update_fields, ['id' => $order_id]);
            }

            // 同步拉取对端发货记录
            if (!empty($remote['shipping_records_list']) && is_array($remote['shipping_records_list']) && class_exists('Todo_Order_Manager')) {
                $local_existing_ships = Todo_Order_Manager::get_shipping_records($order_id);
                $existing_nos = array_column($local_existing_ships, 'tracking_no');
                
                foreach ($remote['shipping_records_list'] as $remote_ship) {
                    $ship_company = $remote_ship['express_company'] ?? ($remote_ship['company'] ?? '');
                    $ship_no = trim((string) ($remote_ship['tracking_no'] ?? ($remote_ship['tracking_number'] ?? '')));
                    $ship_notes = trim((string) ($remote_ship['notes'] ?? ($remote_ship['shipping_notes'] ?? ($remote_ship['remark'] ?? ''))));
                    if ($ship_notes === '') {
                        $ship_notes = '通过AB订单同步拉取';
                    }
                    if ($ship_no !== '' && !in_array($ship_no, $existing_nos, true)) {
                        Todo_Order_Manager::add_shipping_record($order_id, [
                            'express_company' => $ship_company,
                            'tracking_no' => $ship_no,
                            'notes' => $ship_notes
                        ]);
                        $synced_items[] = "发货记录({$ship_company}: {$ship_no})";
                        $existing_nos[] = $ship_no;
                    }
                }
            }

            // 同步拉取对端 PayPal 回调地址、订单号与防拒付凭证
            if (class_exists('Todo_Order_Manager')) {
                if (!empty($remote['paypal_order_id'])) {
                    Todo_Order_Manager::save_order_custom_field($order_id, '_paypal_order_id', $remote['paypal_order_id']);
                }
                if (!empty($remote['anti_fraud_evidence'])) {
                    $ev = is_array($remote['anti_fraud_evidence']) ? $remote['anti_fraud_evidence'] : json_decode($remote['anti_fraud_evidence'], true);
                    if ($ev) {
                        Todo_Order_Manager::save_order_custom_field($order_id, '_anti_fraud_evidence', $ev);
                        if (!empty($ev['address_comparison']) && is_array($ev['address_comparison'])) {
                            Todo_Order_Manager::save_order_custom_field($order_id, '_address_match_status', $ev['address_comparison']['status'] ?? 'MATCH');
                            Todo_Order_Manager::save_order_custom_field($order_id, '_address_match_details', $ev['address_comparison']);
                        }
                    }
                }
                if (!empty($remote['paypal_callback_address'])) {
                    $cb_addr = is_array($remote['paypal_callback_address']) ? $remote['paypal_callback_address'] : json_decode($remote['paypal_callback_address'], true);
                    if ($cb_addr) {
                        Todo_Order_Manager::save_order_custom_field($order_id, '_paypal_callback_address', $cb_addr);
                        $existing_cmp = Todo_Order_Manager::get_order_custom_field($order_id, '_address_match_details');
                        if (empty($existing_cmp) || empty($existing_cmp['paypal_address'])) {
                            $user_shipping = Todo_Order_Manager::get_order_custom_field($order_id, '_checkout_shipping_address') ?: ($order['shipping_address'] ?? '');
                            if (function_exists('todo_compare_addresses')) {
                                $cmp = todo_compare_addresses($user_shipping, $cb_addr);
                                Todo_Order_Manager::save_order_custom_field($order_id, '_address_match_status', $cmp['status']);
                                Todo_Order_Manager::save_order_custom_field($order_id, '_address_match_details', $cmp);
                            }
                        }
                    }
                }

                // 双向 100% 日志对齐：拉取并补充对端缺失的订单日志
                if (!empty($remote['order_logs'])) {
                    $remote_logs = is_array($remote['order_logs']) ? $remote['order_logs'] : json_decode($remote['order_logs'], true);
                    if (is_array($remote_logs)) {
                        $logs_table = DB_PREFIX . 'order_logs';
                        $existing_logs = $tododb->get_results("SELECT action, message FROM {$logs_table} WHERE order_id = " . intval($order_id), ARRAY_A) ?: [];
                        $existing_keys = [];
                        $normalize_key = function($act, $msg) {
                            $act = trim((string)$act);
                            $msg = trim((string)$msg);
                            if ($act === 'payment_status_updated' || strpos($msg, '支付状态') !== false) {
                                $msg = str_replace("'pending'", "'unpaid'", $msg);
                            }
                            return $act . '|' . $msg;
                        };
                        foreach ($existing_logs as $ex) {
                            $existing_keys[$normalize_key($ex['action'], $ex['message'])] = true;
                        }
                        foreach ($remote_logs as $rlog) {
                            $act = trim((string)($rlog['action'] ?? ''));
                            $msg = trim((string)($rlog['message'] ?? ''));
                            if ($act !== '' && $msg !== '') {
                                $key = $normalize_key($act, $msg);
                                if (empty($existing_keys[$key])) {
                                    if ($act === 'payment_status_updated') {
                                        $msg = str_replace("'pending'", "'unpaid'", $msg);
                                    }
                                    $tododb->insert($logs_table, [
                                        'order_id'    => $order_id,
                                        'action'      => $act,
                                        'message'     => $msg,
                                        'operator_id' => intval($rlog['operator_id'] ?? 0),
                                        'created_at'  => !empty($rlog['created_at']) ? $rlog['created_at'] : current_time('mysql')
                                    ]);
                                    $existing_keys[$key] = true;
                                }
                            }
                        }
                    }
                }
            }
        }

        if (!empty($synced_items)) {
            $log_msg = '成功拉取并同步: ' . implode(', ', array_unique($synced_items));
            if (class_exists('Todo_Order_Manager')) {
                Todo_Order_Manager::add_order_log($order_id, 'ab_sync', $log_msg, 0);
            }
        } else {
            $log_msg = '双向数据与状态完全一致，无需重复更新';
        }

        return ['success' => true, 'message' => $log_msg];
    }

    /**
     * 接收并处理对端发起的 sync_ab_order_remote 请求
     * 
     * @param array $post_data 接收到的数据
     * @return array|Todo_Error
     */
    public function handle_remote_sync_request($post_data) {
        if (class_exists('Todo_Order_Manager')) {
            Todo_Order_Manager::init();
        }
        if (!$this->verify_signature($post_data)) {
            return new Todo_Error('invalid_signature', '签名验证失败');
        }

        global $tododb;
        $table = $tododb->prefix . 'orders';
        $order_no = trim(sanitize_text_field((string) ($post_data['order_no'] ?? '')));
        $ab_ref_order_no = trim(sanitize_text_field((string) ($post_data['ab_ref_order_no'] ?? '')));

        // 查找本地对应的订单（双向匹配：匹配本地 order_no，或匹配本地 ab_ref_order_no）
        $order = null;
        $conditions = [];
        $params = [];
        if ($order_no !== '') {
            $conditions[] = "order_no = ?";
            $params[] = $order_no;
            $conditions[] = "ab_ref_order_no = ?";
            $params[] = $order_no;
        }
        if ($ab_ref_order_no !== '') {
            $conditions[] = "order_no = ?";
            $params[] = $ab_ref_order_no;
            $conditions[] = "ab_ref_order_no = ?";
            $params[] = $ab_ref_order_no;
        }

        if (!empty($conditions)) {
            $sql = "SELECT * FROM {$table} WHERE " . implode(" OR ", $conditions) . " LIMIT 1";
            $row_raw = $tododb->get_row($sql, $params);
            $order = $row_raw ? (array) $row_raw : null;
        }

        if (!$order) {
            if (class_exists('Todo_Error_Handler')) {
                Todo_Error_Handler::log("AB_PAYMENT [Remote Sync] Order lookup failed. Received order_no: '{$order_no}', ab_ref_order_no: '{$ab_ref_order_no}'", Todo_Error_Handler::LEVEL_ERROR);
            }
            return new Todo_Error('order_not_found', "对端未查找到匹配的本地订单 (A单号: {$order_no}, B单号: {$ab_ref_order_no})");
        }

        $order_id = intval($order['id']);
        $update_data = [];

        if (!empty($post_data['customer_name'])) {
            $update_data['customer_name'] = sanitize_text_field($post_data['customer_name']);
        }
        if (!empty($post_data['shipping_address'])) {
            $update_data['shipping_address'] = (string)$post_data['shipping_address'];
        }
        if (!empty($post_data['billing_address'])) {
            $update_data['billing_address'] = (string)$post_data['billing_address'];
        }
        if (!empty($post_data['payment_method'])) {
            $update_data['payment_method'] = sanitize_text_field($post_data['payment_method']);
        }

        if (!empty($post_data['transaction_id'])) {
            $update_data['transaction_id'] = sanitize_text_field($post_data['transaction_id']);
        }
        if (!empty($post_data['paid_at'])) {
            $update_data['paid_at'] = sanitize_text_field($post_data['paid_at']);
        }
        if (!empty($post_data['express_company'])) {
            $update_data['express_company'] = sanitize_text_field($post_data['express_company']);
        }
        if (!empty($post_data['tracking_no'])) {
            $update_data['tracking_no'] = sanitize_text_field($post_data['tracking_no']);
        }

        // 状态更新逻辑：确保 A 站与 B 站状态 100% 对齐一致
        if (!empty($post_data['payment_status']) && $post_data['payment_status'] !== ($order['payment_status'] ?? '')) {
            $new_pst = sanitize_text_field($post_data['payment_status']);
            $old_pst = $order['payment_status'] ?? 'unpaid';
            if ($old_pst === 'pending' || $old_pst === '') {
                $old_pst = 'unpaid';
            }
            $update_data['payment_status'] = $new_pst;
            if (class_exists('Todo_Order_Manager')) {
                Todo_Order_Manager::add_order_log($order_id, 'payment_status_updated', "支付状态: 从 '" . $old_pst . "' 变更为 '" . $new_pst . "'", 0);
            }
        }
        if (!empty($post_data['status']) && $post_data['status'] !== $order['status']) {
            $new_st = sanitize_text_field($post_data['status']);
            $old_st = $order['status'] ?? 'pending';
            $update_data['status'] = $new_st;

            if (class_exists('Todo_Order_Manager')) {
                Todo_Order_Manager::add_order_log($order_id, 'status_updated', "订单状态: 从 '" . $old_st . "' 变更为 '" . $new_st . "'", 0);

                if ($new_st === 'completed') {
                    Todo_Order_Manager::add_order_log($order_id, 'confirm_received', '用户确认收货', 0);
                }
            }
        }

        if (!empty($update_data)) {
            $tododb->update($table, $update_data, ['id' => $order_id]);
        }

        // 同步扩展元数据与发货记录
        if (class_exists('Todo_Order_Manager')) {
            if (!empty($post_data['shipping_data'])) {
                $shipping_meta = json_decode($post_data['shipping_data'], true);
                if ($shipping_meta) {
                    Todo_Order_Manager::save_order_custom_field($order_id, 'shipping_data', $shipping_meta);
                }
            }
            if (!empty($post_data['shipping_address_data'])) {
                $addr_data = json_decode($post_data['shipping_address_data'], true);
                if ($addr_data) {
                    Todo_Order_Manager::save_order_custom_field($order_id, 'shipping_address_data', $addr_data);
                }
            }
            if (!empty($post_data['billing_address_data'])) {
                $baddr_data = json_decode($post_data['billing_address_data'], true);
                if ($baddr_data) {
                    Todo_Order_Manager::save_order_custom_field($order_id, 'billing_address_data', $baddr_data);
                }
            }
            if (!empty($post_data['shipping_records'])) {
                $remote_ship_list = json_decode($post_data['shipping_records'], true);
                if (is_array($remote_ship_list)) {
                    foreach ($remote_ship_list as $ship) {
                        $s_comp = $ship['express_company'] ?? ($ship['company'] ?? '');
                        $s_no = $ship['tracking_no'] ?? ($ship['tracking_number'] ?? '');
                        $s_notes = trim((string) ($ship['notes'] ?? ($ship['shipping_notes'] ?? ($ship['remark'] ?? ''))));
                        if ($s_notes === '') {
                            $s_notes = '通过AB订单同步拉取';
                        }
                        if ($s_no !== '') {
                            Todo_Order_Manager::add_shipping_record($order_id, [
                                'express_company' => $s_comp,
                                'tracking_no' => $s_no,
                                'notes' => $s_notes
                            ]);
                        }
                    }
                }
            }
            if (!empty($post_data['order_logs'])) {
                $remote_logs = is_array($post_data['order_logs']) ? $post_data['order_logs'] : json_decode($post_data['order_logs'], true);
                if (is_array($remote_logs)) {
                    $logs_table = DB_PREFIX . 'order_logs';
                    $existing_logs = $tododb->get_results("SELECT action, message FROM {$logs_table} WHERE order_id = " . intval($order_id), ARRAY_A) ?: [];
                    $existing_keys = [];
                    $normalize_key = function($act, $msg) {
                        $act = trim((string)$act);
                        $msg = trim((string)$msg);
                        if ($act === 'payment_status_updated' || strpos($msg, '支付状态') !== false) {
                            $msg = str_replace("'pending'", "'unpaid'", $msg);
                        }
                        return $act . '|' . $msg;
                    };
                    foreach ($existing_logs as $ex) {
                        $existing_keys[$normalize_key($ex['action'], $ex['message'])] = true;
                    }
                    foreach ($remote_logs as $rlog) {
                        $act = trim((string)($rlog['action'] ?? ''));
                        $msg = trim((string)($rlog['message'] ?? ''));
                        if ($act !== '' && $msg !== '') {
                            $key = $normalize_key($act, $msg);
                            if (empty($existing_keys[$key])) {
                                if ($act === 'payment_status_updated') {
                                    $msg = str_replace("'pending'", "'unpaid'", $msg);
                                }
                                $tododb->insert($logs_table, [
                                    'order_id'    => $order_id,
                                    'action'      => $act,
                                    'message'     => $msg,
                                    'operator_id' => intval($rlog['operator_id'] ?? 0),
                                    'created_at'  => !empty($rlog['created_at']) ? $rlog['created_at'] : current_time('mysql')
                                ]);
                                $existing_keys[$key] = true;
                            }
                        }
                    }
                }
            }
        }

        // 重新拉取最新的订单记录以返回最精确的字段给对端
        $fresh_order = $tododb->get_row("SELECT * FROM {$table} WHERE id = ?", [$order_id]);
        $order = $fresh_order ? (array) $fresh_order : $order;

        if (class_exists('Todo_Order_Manager')) {
            Todo_Order_Manager::init();
            $local_shipping_records = Todo_Order_Manager::get_shipping_records($order_id);
        } else {
            $local_shipping_records = [];
        }

        $tx_id = !empty($order['transaction_id']) ? (string) $order['transaction_id'] : '';
        if ($tx_id === '' && class_exists('Todo_Order_Manager')) {
            $pay_recs = Todo_Order_Manager::get_payment_records($order_id);
            if (!empty($pay_recs) && is_array($pay_recs)) {
                foreach ($pay_recs as $rec) {
                    if (!empty($rec['transaction_id'])) {
                        $tx_id = (string) $rec['transaction_id'];
                        break;
                    }
                }
            }
        }

        return [
            'success' => true,
            'message' => '同步成功',
            'remote_order' => [
                'order_no'             => $order['order_no'],
                'customer_name'        => $order['customer_name'] ?? '',
                'shipping_address'     => $order['shipping_address'] ?? '',
                'billing_address'      => $order['billing_address'] ?? '',
                'status'               => $order['status'],
                'payment_status'       => $order['payment_status'],
                'paid_at'              => $order['paid_at'] ?? ($order['payment_time'] ?? ''),
                'created_at'           => $order['created_at'] ?? '',
                'transaction_id'       => $tx_id,
                'express_company'      => $order['express_company'] ?? '',
                'tracking_no'          => $order['tracking_no'] ?? '',
                'shipping_records_list'=> $local_shipping_records,
                'order_logs'           => class_exists('Todo_Order_Manager') ? ($tododb->get_results("SELECT action, message, operator_id, created_at FROM " . DB_PREFIX . "order_logs WHERE order_id = " . intval($order_id), ARRAY_A) ?: []) : [],
                'paypal_order_id'         => class_exists('Todo_Order_Manager') ? (Todo_Order_Manager::get_order_custom_field($order_id, '_paypal_order_id') ?: '') : '',
                'paypal_callback_address' => class_exists('Todo_Order_Manager') ? Todo_Order_Manager::get_order_custom_field($order_id, '_paypal_callback_address') : null,
                'anti_fraud_evidence'     => class_exists('Todo_Order_Manager') ? Todo_Order_Manager::get_order_custom_field($order_id, '_anti_fraud_evidence') : null
            ]
        ];
    }
}

