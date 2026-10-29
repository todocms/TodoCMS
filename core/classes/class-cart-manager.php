<?php
/**
 * TodoCMS 购物车管理器
 * 
 * 处理购物车相关功能
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    exit('Direct access not permitted');
}

class Todo_Cart_Manager
{
    private static $request_product_rows = array();
    private static $request_product_info_cache = array();
    private static $request_spec_details_cache = array();
    private static $request_specs_text_cache = array();

    /**
     * 添加商品到购物车（别名方法）
     * 
     * @param int $product_id 商品ID
     * @param int $quantity 数量
     * @param array $specs 规格信息
     * @return array
     */
    public static function add_item($product_id, $quantity = 1, $specs = array(), $unit_qty = 1)
    {
        return self::add_to_cart($product_id, $quantity, $specs, $unit_qty);
    }

    /**
     * 购物车会话键名
     */
    const CART_SESSION_KEY = 'todo_cart';
    const CART_COOKIE_KEY = 'todo_cart_cache';

    /**
     * 是否已初始化
     */
    private static $initialized = false;

    /**
     * 初始化购物车管理器（懒加载）
     */
    public static function init()
    {
        if (self::$initialized) {
            return;
        }

        // 启动会话
        if (!session_id()) {
            session_start();
        }
        self::hydrate_cart_from_cookie();

        // 注册AJAX处理器
        add_action('todocms_ajax_add_to_cart', array(__CLASS__, 'ajax_add_to_cart'));
        add_action('todocms_ajax_nopriv_add_to_cart', array(__CLASS__, 'ajax_add_to_cart'));

        add_action('todocms_ajax_update_cart', array(__CLASS__, 'ajax_update_cart'));
        add_action('todocms_ajax_nopriv_update_cart', array(__CLASS__, 'ajax_update_cart'));

        add_action('todocms_ajax_remove_from_cart', array(__CLASS__, 'ajax_remove_from_cart'));
        add_action('todocms_ajax_nopriv_remove_from_cart', array(__CLASS__, 'ajax_remove_from_cart'));

        add_action('todocms_ajax_get_cart', array(__CLASS__, 'ajax_get_cart'));
        add_action('todocms_ajax_nopriv_get_cart', array(__CLASS__, 'ajax_get_cart'));

        add_action('todocms_ajax_clear_cart', array(__CLASS__, 'ajax_clear_cart'));
        add_action('todocms_ajax_nopriv_clear_cart', array(__CLASS__, 'ajax_clear_cart'));

        self::$initialized = true;
    }

    private static function hydrate_cart_from_cookie()
    {
        $user_id = function_exists('todo_get_current_user_id') ? intval(todo_get_current_user_id()) : 0;
        if ($user_id > 0) {
            return;
        }
        if (!empty($_SESSION[self::CART_SESSION_KEY]['items']) && is_array($_SESSION[self::CART_SESSION_KEY]['items'])) {
            return;
        }
        if (empty($_COOKIE[self::CART_COOKIE_KEY])) {
            return;
        }

        $raw = (string) $_COOKIE[self::CART_COOKIE_KEY];
        $decoded_raw = base64_decode($raw, true);
        if ($decoded_raw === false) {
            return;
        }
        $payload = json_decode($decoded_raw, true);
        if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
            return;
        }

        $cart = [
            'items' => [],
            'count' => 0,
            'total' => 0,
            'updated_at' => current_time('mysql'),
            'user_id' => 0
        ];

        foreach ($payload['items'] as $cart_key => $item) {
            if (!is_string($cart_key) || $cart_key === '' || !is_array($item)) {
                continue;
            }
            $pid = intval($item['product_id'] ?? 0);
            $qty = intval($item['quantity'] ?? 0);
            $specs = $item['specs'] ?? [];
            if ($pid <= 0 || $qty <= 0) {
                continue;
            }
            if (!is_array($specs) && is_string($specs)) {
                $decoded_specs = json_decode($specs, true);
                $specs = is_array($decoded_specs) ? $decoded_specs : [];
            }
            $cart['items'][$cart_key] = [
                'product_id' => $pid,
                'quantity' => $qty,
                'specs' => is_array($specs) ? $specs : [],
                'price' => 0,
                'title' => '',
                'image' => '',
                'added_at' => current_time('mysql')
            ];
        }

        if (!empty($cart['items'])) {
            $_SESSION[self::CART_SESSION_KEY] = $cart;
        }
    }

    /**
     * 确保已初始化（懒加载检查）
     */
    private static function ensure_initialized() {
        if (!self::$initialized) {
            self::init();
        }
    }

    private static function normalize_price_value($value)
    {
        if (is_array($value) || is_object($value)) {
            return 0.0;
        }
        $clean = preg_replace('/[^\d.]/', '', (string) $value);
        return $clean === '' ? 0.0 : floatval($clean);
    }

    private static function get_model_table_name()
    {
        global $tododb;
        return (isset($tododb->prefix) ? $tododb->prefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_')) . 'model_fields_product';
    }

    private static function preload_product_runtime_rows($product_ids)
    {
        global $tododb;
        if (!$tododb) {
            return;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $product_ids))));
        if (empty($ids)) {
            return;
        }

        $missing_ids = array();
        foreach ($ids as $product_id) {
            if (!isset(self::$request_product_rows[$product_id])) {
                $missing_ids[] = $product_id;
            }
        }

        if (empty($missing_ids)) {
            return;
        }

        if (function_exists('update_post_meta_cache')) {
            update_post_meta_cache($missing_ids);
        }

        $placeholders = implode(',', array_fill(0, count($missing_ids), '?'));
        $model_rows = array();
        $pcf_rows = array();
        $model_table = self::get_model_table_name();
        $pcf_table = $tododb->prefix . 'post_custom_fields';

        try {
            $results = $tododb->get_results(
                "SELECT post_id, price, stock, unit, unit_qty, spec_config, spec_details, product_type, wholesale_price, wholesale_vip_price, sale_status
                 FROM {$model_table}
                 WHERE post_id IN ({$placeholders})",
                $missing_ids,
                'ARRAY_A'
            );
            if (is_array($results)) {
                foreach ($results as $row) {
                    $pid = intval($row['post_id'] ?? 0);
                    if ($pid > 0) {
                        $model_rows[$pid] = $row;
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        try {
            $pcf_results = $tododb->get_results(
                "SELECT post_id, field_key, field_value
                 FROM {$pcf_table}
                 WHERE post_id IN ({$placeholders})
                   AND field_key IN ('price','product_price','_sale_price','_regular_price')",
                $missing_ids,
                'ARRAY_A'
            );
            if (is_array($pcf_results)) {
                foreach ($pcf_results as $row) {
                    $pid = intval($row['post_id'] ?? 0);
                    $field_key = (string) ($row['field_key'] ?? '');
                    if ($pid <= 0 || $field_key === '') {
                        continue;
                    }
                    if (!isset($pcf_rows[$pid])) {
                        $pcf_rows[$pid] = array();
                    }
                    $pcf_rows[$pid][$field_key] = $row['field_value'] ?? '';
                }
            }
        } catch (\Throwable $e) {
        }

        foreach ($missing_ids as $product_id) {
            self::$request_product_rows[$product_id] = array(
                'model' => $model_rows[$product_id] ?? array(),
                'pcf' => $pcf_rows[$product_id] ?? array(),
            );
        }
    }

    private static function get_cached_specs_text($product_id, $specs)
    {
        $cache_key = intval($product_id) . ':' . md5(is_string($specs) ? $specs : json_encode($specs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (!array_key_exists($cache_key, self::$request_specs_text_cache)) {
            self::$request_specs_text_cache[$cache_key] = function_exists('todo_format_specs_text')
                ? todo_format_specs_text($specs, $product_id)
                : '';
        }
        return self::$request_specs_text_cache[$cache_key];
    }

    /**
     * 添加商品到购物车
     * 
     * @param int $product_id 商品ID
     * @param int $quantity 数量
     * @param array $specs 规格信息
     * @return array
     */
    public static function add_to_cart($product_id, $quantity = 1, $specs = array(), $unit_qty = 1)
    {
        self::ensure_initialized();
        try {
            // 验证商品
            $product = get_post($product_id);
            // 校验商品是否存在
            if (!$product) {
                throw new Exception(__('商品不存在'));
            }
            // 仅允许产品模型加入购物车，避免非产品内容误加
            if (isset($product->post_type) && $product->post_type !== 'product') {
                throw new Exception(__('商品类型不支持'));
            }

            // 检查商品状态（发布状态且非仓库下架）
            if (function_exists('todo_is_product_on_sale')) {
                if (!todo_is_product_on_sale($product)) {
                    throw new Exception(__('商品已下架'));
                }
            } else {
                if ($product->post_status !== 'publish') {
                    throw new Exception(__('商品已下架'));
                }
            }

            // 验证数量
            $quantity = max(1, intval($quantity));

            // 规格参数统一处理为数组
            if (is_string($specs)) {
                $decoded_specs = json_decode($specs, true);
                if (is_array($decoded_specs)) {
                    $specs = $decoded_specs;
                }
            }

            // 获取商品信息
            $product_info = self::get_product_info($product_id, $specs);
            if (!$product_info) {
                throw new Exception(__('获取商品信息失败'));
            }

            // 检查库存
            if ($product_info['stock'] > 0 && $quantity > $product_info['stock']) {
                throw new Exception(__('库存不足'));
            }

            // 获取当前购物车
            $cart = self::get_cart();

            // 校验商品类型单一性：禁止实物与虚拟商品混加到购物车
            $new_product_type = $product_info['product_type'] ?? 'physical';
            if (!empty($cart['items'])) {
                foreach ($cart['items'] as $item) {
                    $existing_pt = $item['product_type'] ?? 'physical';
                    if ($existing_pt !== $new_product_type) {
                        throw new Exception(__('虚拟商品与实物商品不能同时下单，请分开结算'));
                    }
                }
            }

            // 生成购物车项目键
            $cart_key = self::generate_cart_key($product_id, $specs);

            // 检查是否已存在
            if (isset($cart['items'][$cart_key])) {
                $new_quantity = $cart['items'][$cart_key]['quantity'] + $quantity;

                // 再次检查库存
                if ($product_info['stock'] > 0 && $new_quantity > $product_info['stock']) {
                    throw new Exception(__('库存不足'));
                }

                $cart['items'][$cart_key]['quantity'] = $new_quantity;
            } else {
                // 添加新项目
                $cart['items'][$cart_key] = array(
                    'product_id' => $product_id,
                    'quantity' => $quantity,
                    'specs' => $specs,
                    'unit_qty' => floatval($unit_qty),
                    'price' => $product_info['price'],
                    'regular_price' => $product_info['regular_price'],
                    'sale_price' => $product_info['sale_price'],
                    'title' => $product->post_title,
                    'image' => $product_info['image'],
                    'unit_text' => $product_info['unit_text'] ?? '',
                    'added_at' => current_time('mysql')
                );
            }

            // 更新购物车统计
            self::update_cart_totals($cart);

            // 保存购物车
            self::save_cart($cart);

            return array(
                'success' => true,
                'message' => __('商品已添加到购物车'),
                'cart_count' => $cart['count'],
                'cart_total' => $cart['total']
            );

        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }

    /**
     * 获取购物车内容
     * 
     * @return array
     */
    public static function get_cart()
    {
        self::ensure_initialized();
        $cart = $_SESSION[self::CART_SESSION_KEY] ?? array();

        // 初始化购物车结构
        if (empty($cart)) {
            $cart = array(
                'items' => array(),
                'count' => 0,
                'total' => 0,
                'updated_at' => current_time('mysql'),
                'user_id' => 0
            );
        }

        $current_user_id = function_exists('todo_get_current_user_id') ? intval(todo_get_current_user_id()) : 0;
        if ($current_user_id > 0) {
            $cart_user_id = intval($cart['user_id'] ?? 0);
            if ($cart_user_id !== $current_user_id) {
                $db_cart = self::get_cart_from_database($current_user_id);
                if (!empty($cart['items']) && is_array($cart['items'])) {
                    $db_cart = self::merge_cart($db_cart, $cart);
                }
                self::update_cart_totals($db_cart);
                self::save_cart($db_cart);
                return $db_cart;
            }
        }

        return $cart;
    }

    /**
     * 获取购物车详细信息
     * 
     * @return array
     */
    public static function get_cart_details($options = array())
    {
        $cart = self::get_cart();
        $persist = !isset($options['persist']) || (bool) $options['persist'];
        $cart_changed = false;

        // 验证和更新购物车项目
        if (!empty($cart['items'])) {
            // 批量预加载商品元数据，避免N+1查询
            $product_ids = array_column($cart['items'], 'product_id');
            self::preload_product_runtime_rows($product_ids);
        }

        foreach ($cart['items'] as $cart_key => $item) {
            $product_info = self::get_product_info($item['product_id'], $item['specs']);

            if (!$product_info) {
                // 商品不存在，移除
                unset($cart['items'][$cart_key]);
                $cart_changed = true;
                continue;
            }

            // 更新商品信息
            $cart['items'][$cart_key]['title'] = $product_info['title'];
            $cart['items'][$cart_key]['image'] = $product_info['image'];
            $cart['items'][$cart_key]['current_price'] = $product_info['price'];
            // 同步有效计价到购物车用于下单与结算，确保展示价/下单价/支付价一致
            $cart['items'][$cart_key]['price'] = $product_info['price'];
            $cart['items'][$cart_key]['regular_price'] = $product_info['regular_price'];
            $cart['items'][$cart_key]['sale_price'] = $product_info['sale_price'];
            $cart['items'][$cart_key]['stock'] = $product_info['stock'];
            $cart['items'][$cart_key]['available'] = $product_info['available'];
            $cart['items'][$cart_key]['unit_qty'] = $product_info['unit_qty'];
            $cart['items'][$cart_key]['unit_text'] = $product_info['unit_text'];
            $cart['items'][$cart_key]['unit'] = $product_info['unit'];
            $cart['items'][$cart_key]['product_type'] = $product_info['product_type'] ?? 'physical';
            
            // 设置规格文本（解析 v0/v1 为规格组标题）
            if (empty($cart['items'][$cart_key]['specs_text']) && !empty($item['specs'])) {
                $cart['items'][$cart_key]['specs_text'] = self::get_cached_specs_text($item['product_id'], $item['specs']);
            }

            // 费用计算与展示文本格式化
            $item_qty = intval($cart['items'][$cart_key]['quantity'] ?? 1);
            $item_price = floatval($cart['items'][$cart_key]['price'] ?? 0);
            $regular_price = floatval($cart['items'][$cart_key]['regular_price'] ?? 0);
            if ($regular_price < $item_price) {
                $regular_price = $item_price;
            }
            $is_wholesale = ($regular_price > $item_price + 0.001);
            $saved_amount = max(0, $regular_price - $item_price);

            $item_total_price = $item_qty * $item_price;
            $item_orig_total_price = $item_qty * $regular_price;
            $item_saved_total = $item_qty * $saved_amount;

            $cart['items'][$cart_key]['total_price'] = $item_total_price;
            $cart['items'][$cart_key]['total_price_format'] = number_format($item_total_price, 2);
            $cart['items'][$cart_key]['original_total_price'] = $item_orig_total_price;
            $cart['items'][$cart_key]['original_total_price_format'] = number_format($item_orig_total_price, 2);
            $cart['items'][$cart_key]['saved_amount'] = $item_saved_total;
            $cart['items'][$cart_key]['saved_amount_format'] = number_format($item_saved_total, 2);
            $cart['items'][$cart_key]['is_wholesale'] = $is_wholesale;
            $cart['items'][$cart_key]['discount_label'] = $is_wholesale ? (function_exists('todo_get_user_discount_label') ? todo_get_user_discount_label() : __('高级批发价')) : '';

            $u_qty = floatval($product_info['unit_qty'] ?? 1);
            $u_raw = $product_info['unit'] ?? __('件');
            $cart['items'][$cart_key]['product_total_unit_text'] = function_exists('todo_format_unit_text') ? todo_format_unit_text($u_qty, $u_raw) : ($product_info['unit_text'] ?? '');

            // 检查库存
            if ($product_info['stock'] > 0 && $item['quantity'] > $product_info['stock']) {
                $cart['items'][$cart_key]['quantity'] = $product_info['stock'];
                $cart['items'][$cart_key]['stock_warning'] = true;
                $cart_changed = true;
            }
        }

        // 计算整单是否为虚拟商品订单（购物车中非空且所有商品均是 virtual）
        $is_virtual = !empty($cart['items']);
        foreach ($cart['items'] as $item) {
            if (($item['product_type'] ?? 'physical') !== 'virtual') {
                $is_virtual = false;
                break;
            }
        }
        $cart['is_virtual'] = $is_virtual;

        // 重新计算总计
        self::update_cart_totals($cart);

        // 仅在数据发生修正时落盘，避免页面只读访问重复写 session / cookie / DB
        if ($persist && $cart_changed) {
            self::save_cart($cart);
        }

        return $cart;
    }

    /**
     * 获取商品信息
     * 
     * @param int $product_id 商品ID
     * @param array $specs 规格信息
     * @return array|false
     */
    public static function get_product_info($product_id, $specs = array())
    {
        self::preload_product_runtime_rows(array($product_id));

        if (is_string($specs)) {
            $decoded_specs = json_decode($specs, true);
            if (is_array($decoded_specs)) {
                $specs = $decoded_specs;
            }
        }
        if (!is_array($specs)) {
            $specs = array();
        }

        $user_id = function_exists('todo_get_current_user_id') ? intval(todo_get_current_user_id()) : (function_exists('get_current_user_id') ? intval(get_current_user_id()) : 0);
        $cache_key = intval($product_id) . ':' . md5(json_encode($specs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . ':u' . $user_id;
        if (isset(self::$request_product_info_cache[$cache_key])) {
            return self::$request_product_info_cache[$cache_key];
        }

        $product = get_post($product_id);
        if (!$product) {
            return false;
        }

        global $tododb;
        $runtime_rows = self::$request_product_rows[$product_id] ?? array();
        $model_row = isset($runtime_rows['model']) && is_array($runtime_rows['model']) ? $runtime_rows['model'] : array();
        $pcf_row = isset($runtime_rows['pcf']) && is_array($runtime_rows['pcf']) ? $runtime_rows['pcf'] : array();

        $base_price = 0.0;
        $sale_price = 0.0;
        $regular_price = 0.0;

        $pcf_price = $pcf_row['price'] ?? null;
        if ($pcf_price !== null && self::normalize_price_value($pcf_price) > 0) {
            $base_price = self::normalize_price_value($pcf_price);
            $regular_price = $base_price;
            $sale_price = 0;
        }

        if ($base_price <= 0) {
            $price_json = $pcf_row['product_price'] ?? null;
            if ($price_json) {
                $decoded = json_decode($price_json, true);
                if (is_array($decoded)) {
                    $sale_val = $decoded['sale_price'] ?? 0;
                    $reg_val = $decoded['regular_price'] ?? 0;
                    $sale_price = self::normalize_price_value($sale_val);
                    $regular_price = self::normalize_price_value($reg_val);
                    $base_price = $sale_price > 0 ? $sale_price : $regular_price;
                }
            }
        }

        if ($base_price <= 0) {
            $sale_price = self::normalize_price_value($pcf_row['_sale_price'] ?? '');
            $regular_price = self::normalize_price_value($pcf_row['_regular_price'] ?? '');
            $base_price = $sale_price > 0 ? $sale_price : $regular_price;
        }

        if ($base_price <= 0) {
            $candidate_keys = array('todo_product_price', 'product_price', '_sale_price', '_regular_price', 'price', 'wholesale_vip_price');
            foreach ($candidate_keys as $k) {
                $v = get_post_meta($product_id, $k, true);
                if ($v !== '') {
                    $v_clean = preg_replace('/[^\d.]/', '', $v);
                    if (floatval($v_clean) > 0) {
                        $base_price = floatval($v_clean);
                        break;
                    }
                }
            }
        }
        
        // 回退：查询 model_fields_product 表（TodoCMS 产品模型表）
        if ($base_price <= 0) {
            try {
                $model = !empty($model_row) ? (object) $model_row : null;
                if ($model) {
                    if (!$model->price || floatval($model->price) <= 0) {
                        // 尝试从 post_meta 获取 product_price JSON
                        $pp = get_post_meta($product_id, 'product_price', true);
                        if ($pp && is_string($pp)) {
                            $decoded = json_decode($pp, true);
                            if (is_array($decoded)) {
                                $sv = floatval(preg_replace('/[^\d.]/', '', (string)($decoded['sale_price'] ?? 0)));
                                $rv = floatval(preg_replace('/[^\d.]/', '', (string)($decoded['regular_price'] ?? 0)));
                                $model->price = $sv > 0 ? $sv : $rv;
                            }
                        }
                    }
                    if ($model->price && floatval($model->price) > 0) {
                        $base_price = floatval($model->price);
                        $regular_price = $base_price;
                    }
                    if ($model->stock !== null && $model->stock !== '') {
                        $stock_cache = intval($model->stock);
                    }
                }
            } catch (\Exception $e) {}
        }

        $price = $base_price;

        // 库存统一接口
        $stock = function_exists('get_product_stock') ? intval(get_product_stock($product_id)) : intval(get_post_meta($product_id, 'stock', true));
        // 回退：如果上述查询均未找到库存，使用 model_fields_product 表数据
        if (!$stock && isset($stock_cache)) {
            $stock = intval($stock_cache);
        }
        $image = '';
        if (function_exists('get_the_post_thumbnail_url')) {
            $image = (string) get_the_post_thumbnail_url($product_id, 'thumbnail');
        }
        if ($image === '' && function_exists('get_product_thumbnail')) {
            $image = (string) get_product_thumbnail($product_id);
        }
        if ($image === '' && is_object($product) && !empty($product->featured_image)) {
            $image = (string) $product->featured_image;
        }
        if ($image !== '' && function_exists('todo_media_url_to_runtime_absolute')) {
            $image = todo_media_url_to_runtime_absolute($image);
        }

        // 如果有规格，获取规格价格和库存（仅在有效时覆盖）
        if (!empty($specs)) {
            $spec_data = self::get_product_spec_data($product_id, $specs);
            if ($spec_data) {
                if (isset($spec_data['price']) && floatval($spec_data['price']) > 0) {
                    $price = floatval($spec_data['price']);
                }
                if (isset($spec_data['stock'])) {
                    $stock = intval($spec_data['stock']);
                }
            }
        }

        // 折扣与会员价计算
        $discount_type = get_post_meta($product_id, 'discount_type', true);
        $discount_cfg_raw = get_post_meta($product_id, 'discount_config', true);
        $discount_cfg = array();
        if (is_string($discount_cfg_raw) && $discount_cfg_raw !== '') {
            $tmp = json_decode($discount_cfg_raw, true);
            if (is_array($tmp)) {
                $discount_cfg = $tmp;
            }
        } elseif (is_array($discount_cfg_raw)) {
            $discount_cfg = $discount_cfg_raw;
        }

        $effective_price = $price;
        $uid = function_exists('todo_get_current_user_id') ? intval(todo_get_current_user_id()) : (function_exists('get_current_user_id') ? intval(get_current_user_id()) : 0);
        $effective_price = function_exists('todo_get_product_member_price') 
            ? todo_get_product_member_price($product_id, $price, $uid, $specs) 
            : $price;

        if ($uid > 0) {
            $candidate_prices = array($effective_price);
            $user_level_slug = '';
            $user_level_name = '';
            $level_id = 0;

            if (function_exists('todo_get_user_level_manager')) {
                $lvl = todo_get_user_level_manager()->get_user_current_level($uid);
                if ($lvl) {
                    if (isset($lvl->level_slug)) {
                        $user_level_slug = strtolower((string) $lvl->level_slug);
                    }
                    if (isset($lvl->level_name)) {
                        $user_level_name = (string) $lvl->level_name;
                    }
                    if (isset($lvl->level_id)) {
                        $level_id = intval($lvl->level_id);
                    } elseif (isset($lvl->id)) {
                        $level_id = intval($lvl->id);
                    }
                }
            }

            // 读取商品或规格的 wholesale_price / wholesale_vip_price
            $wholesale_price = 0.0;
            $wholesale_vip_price = 0.0;
            if (!empty($spec_data)) {
                $wholesale_price = floatval($spec_data['wholesale_price'] ?? 0);
                $wholesale_vip_price = floatval($spec_data['wholesale_vip_price'] ?? 0);
            }
            if ($wholesale_price <= 0 && $wholesale_vip_price <= 0) {
                $wholesale_price = floatval($model_row['wholesale_price'] ?? 0);
                $wholesale_vip_price = floatval($model_row['wholesale_vip_price'] ?? 0);
            }

            // 重构：严格按照数据库 user_levels 表的 level_order 动态判断用户价格阶梯层级
            $tier = 'normal';
            if (function_exists('todo_get_user_level_manager')) {
                $tier = todo_get_user_level_manager()->get_user_price_tier($uid);
            }
            $is_senior = ($tier === 'wholesale_vip');
            $is_wholesale = ($tier === 'wholesale_vip' || $tier === 'wholesale');

            if ($is_senior) {
                $tier_p = $wholesale_vip_price > 0 ? $wholesale_vip_price : ($wholesale_price > 0 ? $wholesale_price : 0.0);
                if ($tier_p > 0 && $tier_p < $price) $candidate_prices[] = $tier_p;
            } elseif ($is_wholesale) {
                $tier_p = $wholesale_price > 0 ? $wholesale_price : 0.0;
                if ($tier_p > 0 && $tier_p < $price) $candidate_prices[] = $tier_p;
            }

            // 防套利原则：如果产生备选价格，取其中最低单价（买家最优选且防止折上折叠加套利）
            if (!empty($candidate_prices)) {
                $effective_price = min($candidate_prices);
            }
        }

        $price = max(0, floatval($effective_price));

        // 从 model_fields_product 表读取真实 unit_qty 与 unit 字段
        $unit_qty_raw = null;
        $unit_raw = null;
        try {
            $m_row = !empty($model_row) ? (object) $model_row : null;
            if ($m_row) {
                if ($m_row->unit_qty !== null && $m_row->unit_qty !== '') $unit_qty_raw = $m_row->unit_qty;
                if ($m_row->unit !== null && $m_row->unit !== '') $unit_raw = $m_row->unit;
            }
        } catch (\Exception $e) {}

        $unit_qty_val = floatval($unit_qty_raw !== '' && $unit_qty_raw !== null ? $unit_qty_raw : 1);
        if ($unit_qty_val <= 0) $unit_qty_val = 1.0;
        $u = ($unit_raw !== '' && $unit_raw !== null) ? $unit_raw : 'piece';

        $u_map = array('piece'=>__('件'),'item'=>__('个'),'set'=>__('套'),'pair'=>__('双'),'pack'=>__('包'),'bag'=>__('袋'),'box'=>__('盒'),'bottle'=>__('瓶'),'can'=>__('罐'),'roll'=>__('卷'),'strip'=>__('条'),'sheet'=>__('张'),'portion'=>__('份'),'bowl'=>__('碗'),'plate'=>__('盘'),'cup'=>__('杯'),'bunch'=>__('把'),'head'=>__('颗/头'),'kg'=>__('千克'),'g'=>__('克'),'jin'=>'斤','liang'=>'两','ml'=>__('毫升'),'l'=>__('升'),'other'=>__('其他'));
        $u_display = isset($u_map[$u]) ? $u_map[$u] : ($u ?: __('件'));
        if (function_exists('todo_parse_inline_translations')) {
            $u_display = todo_parse_inline_translations($u_display);
        }
        if (function_exists('todo_translate')) {
            $u_display = todo_translate($u_display);
        }
        $u_text = $u_display;
        if ($unit_qty_val !== 1.0 && $unit_qty_val > 0) {
            $u_text = rtrim(rtrim(number_format($unit_qty_val, 2), '0'), '.') . $u_display;
        }

        // 获取产品类型（physical/virtual）
        $product_type = isset($model_row['product_type']) ? (string) $model_row['product_type'] : '';
        if (empty($product_type)) {
            $product_type = get_post_meta($product_id, 'product_type', true);
        }
        if (empty($product_type)) {
            try {
                if (!empty($model_row['product_type'])) { $product_type = $model_row['product_type']; }
            } catch (\Exception $e) {}
        }
        if (empty($product_type)) {
            $product_type = 'physical';
        }

        $is_on_sale = true;
        if (isset($model_row['sale_status']) && $model_row['sale_status'] === 'warehouse') {
            $is_on_sale = false;
        } elseif (function_exists('todo_is_product_on_sale')) {
            $is_on_sale = todo_is_product_on_sale($product);
        }

        $result = array(
            'title' => $product->post_title,
            'price' => $price,
            'regular_price' => $regular_price,
            'sale_price' => $sale_price,
            'stock' => $stock,
            'image' => $image ?: '',
            'available' => ($product->post_status === 'publish' && $is_on_sale),
            'sale_status' => $model_row['sale_status'] ?? ($is_on_sale ? 'online' : 'warehouse'),
            'unit_qty' => $unit_qty_val,
            'unit_text' => $u_text,
            'unit' => $u_display,
            'product_type' => $product_type,
        );
        self::$request_product_info_cache[$cache_key] = $result;
        return $result;
    }

    /**
     * 获取商品规格数据
     * 
     * @param int $product_id 商品ID
     * @param array $specs 规格信息
     * @return array|false
     */
    private static function get_product_spec_data($product_id, $specs)
    {
        global $tododb;

        // 兼容字符串JSON规格，统一转换为数组
        if (is_string($specs)) {
            $decoded = json_decode($specs, true);
            if (is_array($decoded)) {
                $specs = $decoded;
            }
        }

        if (empty($specs) || !is_array($specs)) {
            return false;
        }

        self::preload_product_runtime_rows(array($product_id));
        $table_name = self::get_model_table_name();
        if (!isset(self::$request_spec_details_cache[$product_id])) {
            $runtime_rows = self::$request_product_rows[$product_id] ?? array();
            $model_row = isset($runtime_rows['model']) && is_array($runtime_rows['model']) ? $runtime_rows['model'] : array();
            $spec_type = $model_row['spec_type'] ?? 'single';
            $spec_details_raw = $model_row['spec_details'] ?? '';
            $spec_config_raw = $model_row['spec_config'] ?? '';
            self::$request_spec_details_cache[$product_id] = array(
                'spec_type' => $spec_type,
                'spec_details_raw' => $spec_details_raw,
                'spec_config_raw' => $spec_config_raw,
            );
        }

        if ((self::$request_spec_details_cache[$product_id]['spec_type'] ?? 'single') !== 'multiple') {
            return false;
        }

        $spec_details_raw = self::$request_spec_details_cache[$product_id]['spec_details_raw'] ?? '';
        if (empty($spec_details_raw)) {
            return false;
        }

        $details = json_decode($spec_details_raw, true);
        if (!is_array($details)) {
            $details = maybe_unserialize($spec_details_raw);
        }
        if (!is_array($details) || empty($details)) {
            return false;
        }

        $spec_config_raw = self::$request_spec_details_cache[$product_id]['spec_config_raw'] ?? '';
        $spec_config = !empty($spec_config_raw) ? json_decode($spec_config_raw, true) : [];

        $normalized_specs = self::normalize_product_specs_array($specs, $spec_config);
        if (empty($normalized_specs)) {
            return false;
        }

        // 匹配对应的规格组合 (spec_details 使用 v0/v1/v2... 键名)
        foreach ($details as $d) {
            $detail_specs = self::extract_product_detail_specs($d);
            if (empty($detail_specs)) {
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
                $p = isset($d['users_price']) ? floatval($d['users_price']) : (isset($d['price']) ? floatval($d['price']) : 0);
                $s = isset($d['stock_count']) ? intval($d['stock_count']) : (isset($d['stock']) ? intval($d['stock']) : 0);
                return array(
                    'price' => $p,
                    'stock' => $s
                );
            }
        }

        return false;
    }

    private static function normalize_product_specs_array($specs, $spec_config = [])
    {
        if (!is_array($specs) || empty($specs)) {
            return array();
        }

        $normalized = array();

        $title_to_vkey = [];
        if (!empty($spec_config) && is_array($spec_config)) {
            foreach ($spec_config as $idx => $cfg) {
                $t = trim((string)($cfg['title'] ?? ($cfg['name'] ?? '')));
                if ($t !== '') {
                    $cands = function_exists('todo_get_string_multilang_candidates')
                        ? todo_get_string_multilang_candidates($t)
                        : array($t);
                    foreach ($cands as $cand) {
                        $title_to_vkey[$cand] = 'v' . intval($idx);
                    }
                }
            }
        }

        foreach ($specs as $key => $value) {
            if (is_array($value) || is_object($value)) {
                return array();
            }

            $normalized_key = '';
            $key_str = (string) $key;

            if (isset($title_to_vkey[$key_str])) {
                $normalized_key = $title_to_vkey[$key_str];
            } elseif (is_int($key) || ctype_digit($key_str)) {
                $normalized_key = 'v' . intval($key);
            } elseif (preg_match('/^v\d+$/', $key_str)) {
                $normalized_key = $key_str;
            }

            if ($normalized_key === '') {
                continue;
            }

            $normalized_value = trim((string) $value);
            if ($normalized_value === '') {
                continue;
            }

            $normalized[$normalized_key] = $normalized_value;
        }

        uksort($normalized, function($a, $b) {
            return intval(substr($a, 1)) <=> intval(substr($b, 1));
        });

        return $normalized;
    }

    private static function extract_product_detail_specs($detail)
    {
        if (!is_array($detail)) {
            return array();
        }

        $specs = array();
        foreach ($detail as $key => $value) {
            if (is_string($key) && preg_match('/^v\d+$/', $key)) {
                $specs[$key] = trim((string) $value);
            }
        }

        if (empty($specs)) {
            return array();
        }

        uksort($specs, function($a, $b) {
            return intval(substr($a, 1)) <=> intval(substr($b, 1));
        });

        return $specs;
    }

    /**
     * 生成购物车项目键
     * 
     * @param int $product_id 商品ID
     * @param array $specs 规格信息
     * @return string
     */
    private static function generate_cart_key($product_id, $specs = array())
    {
        $key_data = array(
            'product_id' => $product_id,
            'specs' => $specs
        );

        return md5(json_encode($key_data));
    }

    /**
     * 更新购物车统计
     * 
     * @param array &$cart 购物车数据
     */
    private static function update_cart_totals(&$cart)
    {
        $count = 0;
        $total = 0;
        $original_total = 0;

        foreach ($cart['items'] as $item) {
            $price = floatval($item['price'] ?? ($item['current_price'] ?? ($item['regular_price'] ?? ($item['sale_price'] ?? 0))));
            $regular_price = floatval($item['regular_price'] ?? $price);
            if ($regular_price < $price) {
                $regular_price = $price;
            }
            $qty = intval($item['quantity'] ?? 0);
            $count += $qty;
            $total += $qty * $price;
            $original_total += $qty * $regular_price;
        }

        $wholesale_discount = max(0, $original_total - $total);

        $cart['count'] = $count;
        $cart['total'] = $total;
        $cart['original_total'] = $original_total;
        $cart['wholesale_discount'] = $wholesale_discount;
        $cart['subtotal_format'] = number_format($total, 2);
        $cart['original_subtotal_format'] = number_format($original_total, 2);
        $cart['wholesale_discount_format'] = number_format($wholesale_discount, 2);
        $cart['has_wholesale_discount'] = ($wholesale_discount > 0.001);
        $cart['updated_at'] = current_time('mysql');
    }

    /**
     * 保存购物车
     * 
     * @param array $cart 购物车数据
     */
    private static function save_cart($cart)
    {
        global $tododb;

        $user_id = function_exists('todo_get_current_user_id') ? intval(todo_get_current_user_id()) : 0;
        if (!is_array($cart)) {
            $cart = array();
        }
        $cart['user_id'] = $user_id > 0 ? $user_id : 0;
        if (!isset($cart['updated_at'])) {
            $cart['updated_at'] = current_time('mysql');
        }

        // 保存到SESSION
        $_SESSION[self::CART_SESSION_KEY] = $cart;

        if ($user_id > 0) {
            if (!headers_sent()) {
                setcookie(self::CART_COOKIE_KEY, '', time() - 3600, '/', '', false, true);
            }
        } else {
            $payload = [
                'items' => []
            ];
            if (isset($cart['items']) && is_array($cart['items'])) {
                foreach ($cart['items'] as $cart_key => $item) {
                    if (!is_string($cart_key) || $cart_key === '') continue;
                    $payload['items'][$cart_key] = [
                        'product_id' => intval($item['product_id'] ?? 0),
                        'quantity' => intval($item['quantity'] ?? 0),
                        'specs' => $item['specs'] ?? []
                    ];
                }
            }
            $encoded = base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE));
            if (!headers_sent()) {
                setcookie(self::CART_COOKIE_KEY, $encoded, time() + (30 * 24 * 60 * 60), '/', '', false, true);
            }
        }

        // 同时保存到数据库（支持用户登录后的持久化购物车）
        if ($user_id) {
            // 如果购物车持久化表不存在，则仅保存到SESSION，避免PDO异常
            if ($tododb->table_exists('cart')) {
                $table_name = $tododb->prefix . 'cart';

                // 清空用户现有购物车
                $tododb->delete($table_name, array('user_id' => $user_id));

                // 插入新的购物车数据
                foreach ($cart['items'] as $cart_key => $item) {
                    $tododb->insert($table_name, array(
                        'user_id' => $user_id,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'specs' => is_array($item['specs']) ? json_encode($item['specs']) : $item['specs'],
                        'price' => $item['price'],
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql')
                    ));
                }
            } else {
                if (defined('TODO_DEBUG') && TODO_DEBUG) {
                    error_log('购物车表不存在(cart)，已仅保存到SESSION');
                }
            }
        }
    }

    private static function get_cart_from_database($user_id)
    {
        self::ensure_initialized();
        $user_id = intval($user_id);
        $cart = array(
            'items' => array(),
            'count' => 0,
            'total' => 0,
            'updated_at' => current_time('mysql'),
            'user_id' => $user_id > 0 ? $user_id : 0
        );

        if ($user_id <= 0) {
            return $cart;
        }

        global $tododb;
        if (!$tododb || !method_exists($tododb, 'table_exists') || !$tododb->table_exists('cart')) {
            return $cart;
        }

        $table_name = $tododb->prefix . 'cart';
        try {
            $rows = $tododb->get_results(
                "SELECT product_id, quantity, specs, created_at FROM {$table_name} WHERE user_id = ? ORDER BY id ASC",
                array($user_id)
            );
        } catch (Throwable $e) {
            return $cart;
        }

        if (!is_array($rows) || empty($rows)) {
            return $cart;
        }

        foreach ($rows as $row) {
            $product_id = intval($row->product_id ?? 0);
            $quantity = intval($row->quantity ?? 0);
            if ($product_id <= 0 || $quantity <= 0) {
                continue;
            }

            $specs_raw = $row->specs ?? array();
            $specs = array();
            if (is_string($specs_raw)) {
                $decoded = json_decode($specs_raw, true);
                $specs = is_array($decoded) ? $decoded : array();
            } elseif (is_array($specs_raw)) {
                $specs = $specs_raw;
            }

            $product_info = self::get_product_info($product_id, $specs);
            if (!$product_info) {
                continue;
            }

            $cart_key = self::generate_cart_key($product_id, $specs);
            if (isset($cart['items'][$cart_key])) {
                $cart['items'][$cart_key]['quantity'] += $quantity;
            } else {
                $cart['items'][$cart_key] = array(
                    'product_id' => $product_id,
                    'quantity' => $quantity,
                    'specs' => $specs,
                    'price' => $product_info['price'],
                    'regular_price' => $product_info['regular_price'],
                    'sale_price' => $product_info['sale_price'],
                    'title' => $product_info['title'],
                    'image' => $product_info['image'],
                    'added_at' => $row->created_at ?? current_time('mysql')
                );
            }
        }

        self::update_cart_totals($cart);
        return $cart;
    }

    private static function merge_cart($base_cart, $incoming_cart)
    {
        if (!is_array($base_cart)) {
            $base_cart = array();
        }
        if (!isset($base_cart['items']) || !is_array($base_cart['items'])) {
            $base_cart['items'] = array();
        }
        if (!isset($base_cart['count'])) {
            $base_cart['count'] = 0;
        }
        if (!isset($base_cart['total'])) {
            $base_cart['total'] = 0;
        }
        if (!isset($base_cart['updated_at'])) {
            $base_cart['updated_at'] = current_time('mysql');
        }

        $incoming_items = array();
        if (is_array($incoming_cart) && isset($incoming_cart['items']) && is_array($incoming_cart['items'])) {
            $incoming_items = $incoming_cart['items'];
        }

        foreach ($incoming_items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $product_id = intval($item['product_id'] ?? 0);
            $quantity = intval($item['quantity'] ?? 0);
            if ($product_id <= 0 || $quantity <= 0) {
                continue;
            }

            $specs = $item['specs'] ?? array();
            if (is_string($specs)) {
                $decoded = json_decode($specs, true);
                $specs = is_array($decoded) ? $decoded : array();
            }
            if (!is_array($specs)) {
                $specs = array();
            }

            $cart_key = self::generate_cart_key($product_id, $specs);
            if (isset($base_cart['items'][$cart_key])) {
                $base_cart['items'][$cart_key]['quantity'] += $quantity;
                continue;
            }

            $price = isset($item['price']) ? floatval($item['price']) : 0.0;
            $regular_price = isset($item['regular_price']) ? floatval($item['regular_price']) : 0.0;
            $sale_price = isset($item['sale_price']) ? floatval($item['sale_price']) : 0.0;
            $title = isset($item['title']) ? (string) $item['title'] : '';
            $image = isset($item['image']) ? (string) $item['image'] : '';
            if ($image !== '' && function_exists('todo_media_url_to_runtime_absolute')) {
                $image = todo_media_url_to_runtime_absolute($image);
            }
            if ($price <= 0 || $title === '') {
                $product_info = self::get_product_info($product_id, $specs);
                if (!$product_info) {
                    continue;
                }
                $price = floatval($product_info['price']);
                $regular_price = floatval($product_info['regular_price']);
                $sale_price = floatval($product_info['sale_price']);
                $title = (string) $product_info['title'];
                $image = (string) ($product_info['image'] ?? '');
            }

            $base_cart['items'][$cart_key] = array(
                'product_id' => $product_id,
                'quantity' => $quantity,
                'specs' => $specs,
                'price' => $price,
                'regular_price' => $regular_price,
                'sale_price' => $sale_price,
                'title' => $title,
                'image' => $image,
                'added_at' => $item['added_at'] ?? current_time('mysql')
            );
        }

        return $base_cart;
    }

    public static function sync_after_login($user_id)
    {
        self::ensure_initialized();
        $user_id = intval($user_id);
        if ($user_id <= 0) {
            return false;
        }

        $session_cart = $_SESSION[self::CART_SESSION_KEY] ?? array();
        if (empty($session_cart) || !is_array($session_cart)) {
            $session_cart = array(
                'items' => array(),
                'count' => 0,
                'total' => 0,
                'updated_at' => current_time('mysql'),
                'user_id' => 0
            );
        }

        $db_cart = self::get_cart_from_database($user_id);
        if (!empty($session_cart['items']) && is_array($session_cart['items'])) {
            $db_cart = self::merge_cart($db_cart, $session_cart);
        }

        self::update_cart_totals($db_cart);
        self::save_cart($db_cart);
        return true;
    }

    /**
     * AJAX: 添加到购物车
     */
    public static function ajax_add_to_cart()
    {
        try {
            // 验证CSRF令牌
            if (!Todo_Security_Manager::verify_request_csrf('add_to_cart', $_POST['_todononce'] ?? null)) {
                throw new Exception(__('安全验证失败'));
            }

            $product_id = intval($_POST['product_id'] ?? 0);
            $quantity = intval($_POST['quantity'] ?? 1);
            $specs = $_POST['specs'] ?? array();

            if ($product_id <= 0) {
                throw new Exception(__('无效的商品ID'));
            }

            $result = self::add_to_cart($product_id, $quantity, $specs);

            todo_send_json($result);

        } catch (Exception $e) {
            todo_send_json(array(
                'success' => false,
                'message' => $e->getMessage()
            ));
        }
    }

    /**
     * AJAX: 更新购物车
     */
    public static function ajax_update_cart()
    {
        try {
            // 验证CSRF令牌
            if (!Todo_Security_Manager::verify_request_csrf('update_cart', $_POST['_todononce'] ?? null)) {
                throw new Exception(__('安全验证失败'));
            }

            $cart_key = $_POST['cart_key'] ?? '';
            $quantity = intval($_POST['quantity'] ?? 0);

            if (empty($cart_key)) {
                throw new Exception(__('无效的购物车项目'));
            }

            $result = self::update_cart_item($cart_key, $quantity);

            todo_send_json($result);

        } catch (Exception $e) {
            todo_send_json(array(
                'success' => false,
                'message' => $e->getMessage()
            ));
        }
    }

    /**
     * AJAX: 从购物车移除
     */
    public static function ajax_remove_from_cart()
    {
        try {
            // 验证CSRF令牌
            if (!Todo_Security_Manager::verify_request_csrf('remove_from_cart', $_POST['_todononce'] ?? null)) {
                throw new Exception(__('安全验证失败'));
            }

            $cart_key = $_POST['cart_key'] ?? '';

            if (empty($cart_key)) {
                throw new Exception(__('无效的购物车项目'));
            }

            $result = self::remove_from_cart($cart_key);

            todo_send_json($result);

        } catch (Exception $e) {
            todo_send_json(array(
                'success' => false,
                'message' => $e->getMessage()
            ));
        }
    }

    /**
     * AJAX: 获取购物车
     */
    public static function ajax_get_cart()
    {
        try {
            $cart = self::get_cart_details();

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
    public static function update_cart_item($cart_key, $quantity)
    {
        $cart = self::get_cart();

        if (!isset($cart['items'][$cart_key])) {
            return array(
                'success' => false,
                'message' => __('商品不存在')
            );
        }

        $quantity = max(1, intval($quantity));
        $item = &$cart['items'][$cart_key];

        // 检查库存
        $product = get_post($item['product_id']);
        if (!$product) {
            return array(
                'success' => false,
                'message' => __('商品不存在')
            );
        }

        $stock = intval(todo_get_post_meta($item['product_id'], 'product_stock', true));
        if ($stock > 0 && $quantity > $stock) {
            return array(
                'success' => false,
                'message' => sprintf(__('库存仅剩 %d'), $stock)
            );
        }

        // 更新数量和小计
        $price = floatval($item['price'] ?? ($item['current_price'] ?? ($item['regular_price'] ?? ($item['sale_price'] ?? 0))));
        $item['quantity'] = $quantity;
        $item['total'] = $price * $quantity;
        $cart['items'][$cart_key] = $item;

        // 重新计算购物车总计
        self::update_cart_totals($cart);
        self::save_cart($cart);

        return array(
            'success' => true,
            'message' => __('更新成功'),
            'data' => array(
                'cart_count' => $cart['count'],
                'cart_total' => $cart['total'],
                'item_total' => $item['total']
            )
        );
    }

    /**
     * 从购物车删除商品
     */
    public static function remove_from_cart($cart_key)
    {
        $cart = self::get_cart();

        if (!isset($cart['items'][$cart_key])) {
            return array(
                'success' => false,
                'message' => __('商品不存在')
            );
        }

        // 删除商品
        unset($cart['items'][$cart_key]);

        // 重新计算购物车总计
        self::update_cart_totals($cart);
        self::save_cart($cart);

        return array(
            'success' => true,
            'message' => __('删除成功'),
            'data' => array(
                'cart_count' => $cart['count'],
                'cart_total' => $cart['total']
            )
        );
    }

    /**
     * 获取单个购物车商品
     */
    public static function get_cart_item($item_id)
    {
        $cart = self::get_cart();

        foreach ($cart['items'] as $key => $item) {
            if ($item['id'] == $item_id || $key == $item_id) {
                return $item;
            }
        }

        return false;
    }

    /**
     * 清空购物车
     */
    public static function clear_cart()
    {
        self::ensure_initialized();
        $cart = array(
            'items' => array(),
            'count' => 0,
            'total' => 0
        );

        self::save_cart($cart);

        return array(
            'success' => true,
            'message' => __('购物车已清空'),
            'data' => array(
                'cart_count' => 0,
                'cart_total' => 0
            )
        );
    }

    /**
     * AJAX: 清空购物车
     */
    public static function ajax_clear_cart()
    {
        try {
            // 验证CSRF令牌
            if (!Todo_Security_Manager::verify_request_csrf('clear_cart', $_POST['_todononce'] ?? null)) {
                throw new Exception(__('安全验证失败'));
            }

            $result = self::clear_cart();

            todo_send_json($result);

        } catch (Exception $e) {
            todo_send_json(array(
                'success' => false,
                'message' => $e->getMessage()
            ));
        }
    }
}
