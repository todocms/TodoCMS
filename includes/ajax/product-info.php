<?php
/**
 * TodoCMS 商品信息获取AJAX处理器
 * 
 * 用于获取单个商品的详细信息，支持直接购买模式
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 加载 Composer autoloader

// 定义AJAX环境
if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}

// 直接访问保护
if (!defined('PREVENT_AJAX_AUTO_RUN')) {
    if (
        !isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'
    ) {
        http_response_code(403);
        echo json_encode(['error' => 'Direct access denied']);
        exit;
    }
}

// 加载核心文件
if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    if (!defined('ABSPATH')) {
        define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
    }

    require_once ABSPATH . 'core/bootstrap.php';
    require_once ABSPATH . 'core/functions/function-loader.php';
    if (class_exists('Todo_Function_Loader')) {
        Todo_Function_Loader::load_core_functions();
        if (method_exists('Todo_Function_Loader', 'load_function')) {
            Todo_Function_Loader::load_function('product-functions');
        }
    }
    if (file_exists(ABSPATH . 'core/functions/product-functions.php')) {
        require_once ABSPATH . 'core/functions/product-functions.php';
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

/**
 * 获取商品信息用于直接购买
 */
function todo_ajax_get_product_for_checkout()
{
    try {
        // CSRF验证
        $security = Todo_Security_Manager::getInstance();
        if (!$security->verify_request_csrf('get_product_info', '_todononce')) {
            $req_token = isset($_POST['_todononce']) ? substr($_POST['_todononce'], 0, 8) . '...' : 'none';
            $sess_tokens = isset($_SESSION['todo_csrf_tokens']['get_product_info']) ? count($_SESSION['todo_csrf_tokens']['get_product_info']) : 0;
            $has_session = session_id() ? 'yes' : 'no';
            throw new Exception(__('安全验证失败') . " (Token: {$req_token}, SessCount: {$sess_tokens}, HasSess: {$has_session})");
        }

        $product_id = intval($_POST['product_id'] ?? 0);
        $quantity = intval($_POST['quantity'] ?? 1);
        // 修正：支持JSON字符串并确保$specs为数组
        $specs_raw = $_POST['specs'] ?? array();
        if (is_string($specs_raw)) {
            $decoded = json_decode($specs_raw, true);
            $specs = is_array($decoded) ? $decoded : array();
        } else {
            $specs = is_array($specs_raw) ? $specs_raw : array();
        }

        if ($product_id <= 0) {
            throw new Exception(__('无效的商品ID'));
        }

        // 验证商品存在性与售卖状态（发布状态且非仓库下架）
        $product = get_post($product_id);
        if (!$product) {
            throw new Exception(__('商品不存在'));
        }
        if (function_exists('todo_is_product_on_sale')) {
            if (!todo_is_product_on_sale($product)) {
                throw new Exception(__('商品已下架'));
            }
        } else {
            if ($product->post_status !== 'publish') {
                throw new Exception(__('商品不存在或已下架'));
            }
        }

        // 获取商品详细信息
        $product_info = todo_get_detailed_product_info($product_id, $quantity, $specs);

        if (!$product_info) {
            throw new Exception(__('获取商品信息失败'));
        }

        // 检查库存
        if ($product_info['stock'] > 0 && $quantity > $product_info['stock']) {
            throw new Exception(sprintf(__('库存不足，当前库存：%d'), $product_info['stock']));
        }

        // 价格与合计
        $unit_price = floatval($product_info['price']);
        $raw_unit_price = floatval($product_info['raw_price'] ?? $unit_price);
        $original_unit_price = floatval($product_info['original_price'] ?? 0);
        if ($original_unit_price < $raw_unit_price) {
            $original_unit_price = $raw_unit_price;
        }
        $is_wholesale = ($raw_unit_price > $unit_price + 0.001);

        if (class_exists('Todo_Error_Handler')) {
            $curr_uid = function_exists('todo_get_current_user_id') ? todo_get_current_user_id() : 0;
            Todo_Error_Handler::log(
                '[Checkout AJAX Debug] get_product_for_checkout Prepared',
                Todo_Error_Handler::LEVEL_INFO,
                [
                    'current_user_id' => $curr_uid,
                    'product_id' => $product_id,
                    'unit_price' => $unit_price,
                    'original_unit_price' => $original_unit_price,
                    'is_wholesale' => $is_wholesale,
                    'discount_label' => $is_wholesale ? (function_exists('todo_get_user_discount_label') ? todo_get_user_discount_label() : __('高级批发价')) : ''
                ]
            );
        }

        $line_total = $unit_price * $quantity;
        $line_original_total = $original_unit_price * $quantity;
        $wholesale_discount = max(0, $line_original_total - $line_total);

        $currency_symbol = function_exists('todo_get_currency_config') 
            ? ((todo_get_currency_config()['display_symbol'] ?? '') ?: (todo_get_currency_config()['symbol'] ?? '')) 
            : '';

        $exchanged_unit = function_exists('todo_apply_currency_exchange') ? todo_apply_currency_exchange($unit_price) : $unit_price;
        $exchanged_orig_unit = function_exists('todo_apply_currency_exchange') ? todo_apply_currency_exchange($original_unit_price) : $original_unit_price;
        $exchanged_line_total = $exchanged_unit * $quantity;
        $exchanged_line_orig_total = $exchanged_orig_unit * $quantity;
        $exchanged_wholesale_discount = max(0, $exchanged_line_orig_total - $exchanged_line_total);

        $fmt_unit = function_exists('todo_format_price') ? todo_format_price($unit_price, 'number') : number_format($exchanged_unit, 2);
        $fmt_orig_unit = function_exists('todo_format_price') ? todo_format_price($original_unit_price, 'number') : number_format($exchanged_orig_unit, 2);
        $fmt_line_total = function_exists('todo_format_price') ? todo_format_price($line_total, 'number') : number_format($exchanged_line_total, 2);
        $fmt_line_orig_total = function_exists('todo_format_price') ? todo_format_price($line_original_total, 'number') : number_format($exchanged_line_orig_total, 2);
        $fmt_wholesale_discount = function_exists('todo_format_price') ? todo_format_price($wholesale_discount, 'number') : number_format($exchanged_wholesale_discount, 2);

        // 构建返回数据，模拟购物车格式
        $checkout_data = array(
            'items' => array(
                array(
                    'id' => $product_id,
                    'title' => $product_info['title'],
                    'image' => $product_info['image'],
                    'price' => $exchanged_unit,
                    'price_format' => $fmt_unit,
                    'original_price' => $exchanged_orig_unit,
                    'original_price_format' => $fmt_orig_unit,
                    'original_total_price_format' => $fmt_line_orig_total,
                    'saved_amount_format' => $fmt_wholesale_discount,
                    'is_wholesale' => $is_wholesale,
                    'discount_label' => $is_wholesale ? (function_exists('todo_get_user_discount_label') ? todo_get_user_discount_label() : __('高级批发价')) : '',
                    'quantity' => $quantity,
                    'stock' => intval($product_info['stock']),
                    'specs' => $specs,
                    'specs_text' => todo_format_specs_text($specs, $product_id),
                    'subtotal' => $exchanged_line_total,
                    'subtotal_format' => $fmt_line_total,
                    'total_price_format' => $fmt_line_total,
                    'unit' => $product_info['unit'],
                    'unit_qty' => $product_info['unit_qty'],
                    'unit_text' => $product_info['unit_text'],
                    'product_type' => $product_info['product_type'] ?? 'physical',
                    'category_id' => (function_exists('get_the_category') && ($cats = get_the_category($product_id)) && !empty($cats)) ? (is_object($cats[0]) ? intval($cats[0]->term_id ?? 0) : intval($cats[0]['term_id'] ?? 0)) : 0,
                    'category_ids' => (function_exists('get_the_category') && ($cats = get_the_category($product_id)) && !empty($cats)) ? implode(',', array_filter(array_map(function($c) { return is_object($c) ? intval($c->term_id ?? 0) : intval($c['term_id'] ?? 0); }, $cats))) : ''
                )
            ),
            'subtotal' => $exchanged_line_total,
            'original_subtotal' => $exchanged_line_orig_total,
            'wholesale_discount' => $exchanged_wholesale_discount,
            'has_wholesale_discount' => $is_wholesale,
            'original_subtotal_format' => $fmt_line_orig_total,
            'wholesale_discount_format' => $fmt_wholesale_discount,
            'shipping_fee' => 0,
            'discount' => 0,
            'total' => $exchanged_line_total,
            'subtotal_format' => $fmt_line_total,
            'shipping_fee_format' => function_exists('todo_format_price') ? todo_format_price(0, 'number') : number_format(0, 2),
            'total_format' => $fmt_line_total,
            'item_count' => 1,
            'mode' => 'buy_now', // 标识为直接购买模式
            'is_virtual' => (($product_info['product_type'] ?? 'physical') === 'virtual'),
            'currency' => $currency_symbol
        );

        todo_send_json(array(
            'success' => true,
            'product' => $checkout_data,
            'totals' => array(
                'subtotal' => $checkout_data['subtotal_format'],
                'original_subtotal' => $checkout_data['original_subtotal_format'],
                'wholesale_discount' => $checkout_data['wholesale_discount_format'],
                'has_wholesale_discount' => $is_wholesale,
                'shipping' => $checkout_data['shipping_fee_format'],
                'total' => $checkout_data['total_format']
            )
        ));

    } catch (Exception $e) {
        todo_send_json(array(
            'success' => false,
            'message' => $e->getMessage()
        ));
    }
}

/**
 * 获取商品详细信息
 */
function todo_get_detailed_product_info($product_id, $quantity = 1, $specs = array())
{
    $product = get_post($product_id);
    if (!$product) {
        return false;
    }

    global $tododb;

    // 从产品模型字段表获取基础信息
    $table_name = $tododb->prefix . 'model_fields_product';
    $sql = "SELECT price, original_price, stock, discount_type, discount_config, show_stock, product_code, virtual_sales, unit, unit_qty, spec_config, sale_status FROM {$table_name} WHERE post_id = ?";
    $row = $tododb->get_row($sql, [$product_id], 'ARRAY_A');

    $sale_status = isset($row['sale_status']) && $row['sale_status'] !== '' ? $row['sale_status'] : 'online';
    $is_on_sale = ($product->post_status === 'publish' && $sale_status !== 'warehouse');
    if (function_exists('todo_is_product_on_sale')) {
        $is_on_sale = todo_is_product_on_sale($product);
    }

    $price = isset($row['price']) ? floatval($row['price']) : 0.0;
    $original_price = isset($row['original_price']) ? floatval($row['original_price']) : 0.0;
    $stock = isset($row['stock']) ? intval($row['stock']) : 0;
    $discount_type = isset($row['discount_type']) ? $row['discount_type'] : 'none';
    $discount_cfg_raw = isset($row['discount_config']) ? $row['discount_config'] : '';
    $show_stock = isset($row['show_stock']) ? intval($row['show_stock']) : 0;
    $product_code = isset($row['product_code']) ? $row['product_code'] : '';
    $virtual_sales = isset($row['virtual_sales']) ? intval($row['virtual_sales']) : 0;
    
    // 单位信息
    $unit = isset($row['unit']) ? $row['unit'] : 'piece';
    $unit_qty = isset($row['unit_qty']) ? floatval($row['unit_qty']) : 1;
    $u_map = ['piece'=>__('件'),'item'=>__('个'),'set'=>__('套'),'pair'=>__('双'),'pack'=>__('包'),'bag'=>__('袋'),'box'=>__('盒'),'bottle'=>__('瓶'),'can'=>__('罐'),'roll'=>__('卷'),'strip'=>__('条'),'sheet'=>__('张'),'portion'=>__('份'),'bowl'=>__('碗'),'plate'=>__('盘'),'cup'=>__('杯'),'bunch'=>__('把'),'head'=>__('颗/头'),'kg'=>__('千克'),'g'=>__('克'),'jin'=>__('斤'),'liang'=>__('两'),'ml'=>__('毫升'),'l'=>__('升'),'other'=>__('其他')];
    $unit_display = isset($u_map[$unit]) ? $u_map[$unit] : ($unit ?: __('件'));
    if (function_exists('todo_parse_inline_translations')) {
        $unit_display = todo_parse_inline_translations($unit_display);
    }
    if (function_exists('todo_translate')) {
        $unit_display = todo_translate($unit_display);
    }

    // 价格/原价/库存回退：兼容旧版/其他存储位置
    if ($price <= 0) {
        $price_meta_keys = array('price', 'todo_product_price', 'product_price', '_sale_price', '_regular_price');
        foreach ($price_meta_keys as $mk) {
            $mv = get_post_meta($product_id, $mk, true);
            if ($mv !== '' && floatval($mv) > 0) {
                $price = floatval($mv);
                break;
            }
        }
    }
    if ($original_price <= 0) {
        $op_meta_keys = array('original_price', 'todo_product_original_price', 'product_original_price', '_regular_price');
        foreach ($op_meta_keys as $mk) {
            $mv = get_post_meta($product_id, $mk, true);
            if ($mv !== '' && floatval($mv) > 0) {
                $original_price = floatval($mv);
                break;
            }
        }
        if ($original_price < $price && $price > 0) {
            $original_price = $price;
        }
    }
    if ($stock <= 0) {
        $stock_meta_keys = array('stock', 'todo_product_stock', 'product_stock', '_stock');
        foreach ($stock_meta_keys as $mk) {
            $mv = get_post_meta($product_id, $mk, true);
            if ($mv !== '' && intval($mv) >= 0) {
                $stock = intval($mv);
                break;
            }
        }
    }
    if ($discount_type === 'none') {
        $dt = get_post_meta($product_id, 'discount_type', true);
        if (!empty($dt)) {
            $discount_type = $dt;
        }
    }
    if (empty($discount_cfg_raw)) {
        $dc = get_post_meta($product_id, 'discount_config', true);
        if (!empty($dc)) {
            $discount_cfg_raw = $dc;
        }
    }

    // 解析折扣配置
    $discount_cfg = array();
    if (is_string($discount_cfg_raw) && $discount_cfg_raw !== '') {
        $tmp = json_decode($discount_cfg_raw, true);
        if (is_array($tmp)) {
            $discount_cfg = $tmp;
        }
    } elseif (is_array($discount_cfg_raw)) {
        $discount_cfg = $discount_cfg_raw;
    }

    // 产品主图
    $image = get_the_post_thumbnail_url($product_id, 'medium');
    if (!$image || $image === '') {
        // 兼容可能存在的旧图像字段
        $img_meta_keys = array('image_url', 'thumbnail', 'product_image');
        foreach ($img_meta_keys as $mk) {
            $mv = get_post_meta($product_id, $mk, true);
            if (!empty($mv)) {
                $image = $mv;
                break;
            }
        }
    }

    // 如果有规格，优先使用规格价格与库存
    if (!empty($specs)) {
        $spec_data = todo_get_product_spec_data($product_id, $specs);
        if ($spec_data) {
            $price = isset($spec_data['price']) ? floatval($spec_data['price']) : $price;
            $stock = isset($spec_data['stock']) ? intval($spec_data['stock']) : $stock;
            if (isset($spec_data['original_price']) && floatval($spec_data['original_price']) > 0) {
                $original_price = floatval($spec_data['original_price']);
            }
        }
    }

    // 折扣与会员价计算（统一调用 todo_get_product_member_price，支持固定价与专属折扣率）
    if (!function_exists('todo_get_product_member_price')) {
        $pf_file = (defined('ABSPATH') ? ABSPATH : dirname(dirname(dirname(__FILE__))) . '/') . 'core/functions/product-functions.php';
        if (file_exists($pf_file)) {
            require_once $pf_file;
        }
    }
    $uid = function_exists('todo_get_current_user_id') ? intval(todo_get_current_user_id()) : 0;
    $fn_exists = function_exists('todo_get_product_member_price');
    $effective_price = $fn_exists 
        ? todo_get_product_member_price($product_id, $price, $uid, $specs) 
        : $price;

    if (class_exists('Todo_Error_Handler')) {
        Todo_Error_Handler::log(
            '[Checkout Debug] todo_get_detailed_product_info',
            Todo_Error_Handler::LEVEL_INFO,
            [
                'product_id' => $product_id,
                'input_price' => $price,
                'uid' => $uid,
                'effective_price' => $effective_price,
                'fn_exists' => $fn_exists
            ]
        );
    }

    $raw_price = $price;
    $price = max(0, floatval($effective_price));

    $product_type = get_post_meta($product_id, 'product_type', true);
    if (empty($product_type)) {
        $product_type = isset($row['product_type']) ? $row['product_type'] : '';
    }
    if (empty($product_type)) {
        $product_type = 'physical';
    }

    return array(
        'id' => $product_id,
        'title' => $product->post_title,
        'price' => $price,
        'raw_price' => $raw_price,
        'original_price' => $original_price,
        'stock' => $stock,
        'show_stock' => $show_stock,
        'product_code' => $product_code,
        'virtual_sales' => $virtual_sales,
        'image' => ($image && function_exists('todo_media_url_to_runtime_absolute')) ? todo_media_url_to_runtime_absolute($image) : ($image ?: ''),
        'available' => ($is_on_sale && $stock > 0),
        'is_on_sale' => $is_on_sale,
        'sale_status' => $sale_status,
        'excerpt' => $product->post_excerpt,
        'content' => $product->post_content,
        'unit' => $unit_display,
        'unit_qty' => $unit_qty,
        'unit_text' => ($unit_qty != 1 && $unit_qty > 0) ? rtrim(rtrim(number_format($unit_qty, 2), '0'), '.') . $unit_display : $unit_display,
        'product_type' => $product_type
    );
}

/**
 * 获取商品规格数据
 */
function todo_get_product_spec_data($product_id, $specs)
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

    $product_table = $tododb->prefix . 'model_fields_product';
    $st_row = $tododb->get_row("SELECT spec_type FROM {$product_table} WHERE post_id = ? LIMIT 1", [$product_id], 'ARRAY_A');
    if (!$st_row || ($st_row['spec_type'] ?? 'single') !== 'multiple') {
        return false;
    }

    $table_specs = $tododb->prefix . 'product_specs';

    static $table_cols_cache = [];
    if (!isset($table_cols_cache[$table_specs])) {
        try {
            $cols = $tododb->get_col("SHOW COLUMNS FROM {$table_specs}", [], 0);
            $table_cols_cache[$table_specs] = is_array($cols) ? $cols : [];
        } catch (Throwable $e) {
            $table_cols_cache[$table_specs] = [];
        }
    }
    $cols = $table_cols_cache[$table_specs];

    if (in_array('spec_data', $cols, true)) {
        $spec_conditions = array();
        $spec_values = array($product_id);
        foreach ($specs as $key => $value) {
            $spec_conditions[] = "spec_data LIKE ?";
            $spec_values[] = '%"' . $key . '":"' . $value . '"%';
        }

        if (!empty($spec_conditions)) {
            $sql = "SELECT price, stock FROM {$table_specs} WHERE product_id = ? AND " . implode(' AND ', $spec_conditions) . " LIMIT 1";
            $result = $tododb->get_row($sql, $spec_values, 'ARRAY_A');
            if ($result) {
                return array(
                    'price' => floatval($result['price']),
                    'stock' => intval($result['stock'])
                );
            }
        }
    } elseif (in_array('spec_name', $cols, true) && in_array('spec_value', $cols, true)) {
        $pairs = [];
        foreach ($specs as $k => $v) {
            if (!is_scalar($v)) {
                continue;
            }
            $pairs[] = [sanitize_text_field((string) $k), sanitize_text_field((string) $v)];
        }
        if (count($pairs) === 1) {
            $sql = "SELECT price, stock FROM {$table_specs} WHERE product_id = ? AND spec_name = ? AND spec_value = ? LIMIT 1";
            $result = $tododb->get_row($sql, [$product_id, $pairs[0][0], $pairs[0][1]], 'ARRAY_A');
            if ($result) {
                return array(
                    'price' => floatval($result['price']),
                    'stock' => intval($result['stock'])
                );
            }
        }
    }

    // 回退：尝试从 model_fields_product.spec_details JSON 中解析匹配项
    $product_table = $tododb->prefix . 'model_fields_product';
    $base_row = $tododb->get_row(
        "SELECT spec_details, price, stock FROM {$product_table} WHERE post_id = ?",
        [$product_id],
        'ARRAY_A'
    );
    $base_price = isset($base_row['price']) ? floatval($base_row['price']) : 0.0;
    $base_stock = isset($base_row['stock']) ? intval($base_row['stock']) : 0;
    $spec_json = isset($base_row['spec_details']) ? $base_row['spec_details'] : '';
    if (is_string($spec_json) && $spec_json !== '') {
        $spec_list = json_decode($spec_json, true);
        if (is_array($spec_list)) {
            foreach ($spec_list as $row) {
                // 规格行可能包含各规格名对应的值，以及价格/库存字段
                $matches = true;
                foreach ($specs as $k => $v) {
                    if (!isset($row[$k])) {
                        $matches = false;
                        break;
                    }
                    if (function_exists('todo_multilang_strings_match')) {
                        if (!todo_multilang_strings_match($row[$k], $v)) {
                            $matches = false;
                            break;
                        }
                    } else {
                        if ((string) $row[$k] !== (string) $v) {
                            $matches = false;
                            break;
                        }
                    }
                }
                if ($matches) {
                    $matched_price = isset($row['users_price']) ? floatval($row['users_price']) : (isset($row['price']) ? floatval($row['price']) : $base_price);
                    $matched_stock = isset($row['stock_count']) ? intval($row['stock_count']) : (isset($row['stock']) ? intval($row['stock']) : $base_stock);
                    $matched_wholesale_price = isset($row['wholesale_price']) ? floatval($row['wholesale_price']) : 0.0;
                    $matched_wholesale_vip_price = isset($row['wholesale_vip_price']) ? floatval($row['wholesale_vip_price']) : 0.0;
                    $matched_original_price = isset($row['crossed_price']) ? floatval($row['crossed_price']) : (isset($row['original_price']) ? floatval($row['original_price']) : 0.0);
                    return array(
                        'price' => $matched_price, 
                        'stock' => $matched_stock,
                        'wholesale_price' => $matched_wholesale_price,
                        'wholesale_vip_price' => $matched_wholesale_vip_price,
                        'original_price' => $matched_original_price
                    );
                }
            }
        }
    }

    // 最终回退到产品基础价格与库存
    return array('price' => $base_price, 'stock' => $base_stock);
}

/**
 * 获取当前会员等级及商品批发价格信息 (支持单规格与多规格)
 */
function todo_ajax_get_wholesale_info()
{
    try {
        $product_id = intval($_POST['product_id'] ?? ($_GET['product_id'] ?? 0));
        if ($product_id <= 0) {
            throw new Exception(__('无效的商品ID'));
        }

        // 获取当前登录用户及会员等级
        $user_id = function_exists('todo_get_current_user_id') ? todo_get_current_user_id() : (function_exists('get_current_user_id') ? get_current_user_id() : 0);
        $user_level = 'retail';
        if ($user_id > 0) {
            $meta_level = get_user_meta($user_id, 'user_level', true);
            if (!empty($meta_level)) {
                $user_level = $meta_level;
            } elseif (function_exists('todo_get_user_level_manager')) {
                $lvl = todo_get_user_level_manager()->get_user_current_level($user_id);
                if ($lvl && !empty($lvl->level_slug)) {
                    $user_level = $lvl->level_slug;
                }
            }
        }

        // 查询商品模型字段
        global $tododb;
        $table_name = (isset($tododb->prefix) ? $tododb->prefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_')) . 'model_fields_product';
        $row = $tododb->get_row("SELECT * FROM {$table_name} WHERE post_id = ? LIMIT 1", [$product_id], 'ARRAY_A');
        
        if (!$row) {
            throw new Exception(__('商品模型数据未找到'));
        }

        $spec_type = $row['spec_type'] ?? 'single';
        $price = floatval($row['price'] ?? 0);
        $original_price = floatval($row['original_price'] ?? 0);
        $stock = intval($row['stock'] ?? 0);
        $wholesale_price = floatval($row['wholesale_price'] ?? 0);
        $wholesale_vip_price = floatval($row['wholesale_vip_price'] ?? 0);
        
        $spec_details = array();
        if ($spec_type === 'multiple' && !empty($row['spec_details'])) {
            $decoded = json_decode($row['spec_details'], true);
            if (!is_array($decoded)) $decoded = maybe_unserialize($row['spec_details']);
            if (is_array($decoded)) {
                foreach ($decoded as $detail) {
                    if (is_array($detail)) {
                        $detail_wholesale_price = floatval($detail['wholesale_price'] ?? 0);
                        $detail_wholesale_vip_price = floatval($detail['wholesale_vip_price'] ?? 0);
                        $detail_price = floatval($detail['users_price'] ?? ($detail['price'] ?? 0));
                        $detail_original_price = floatval($detail['crossed_price'] ?? ($detail['original_price'] ?? 0));
                        $detail_stock = isset($detail['stock']) ? intval($detail['stock']) : intval($detail['stock_count'] ?? 0);

                        if (function_exists('todo_apply_currency_exchange')) {
                            $detail_wholesale_price = todo_apply_currency_exchange($detail_wholesale_price);
                            $detail_wholesale_vip_price = todo_apply_currency_exchange($detail_wholesale_vip_price);
                            $detail_price = todo_apply_currency_exchange($detail_price);
                            $detail_original_price = todo_apply_currency_exchange($detail_original_price);
                        }

                        $detail['wholesale_price'] = $detail_wholesale_price;
                        $detail['wholesale_vip_price'] = $detail_wholesale_vip_price;
                        $detail['price'] = $detail_price;
                        $detail['users_price'] = $detail_price;
                        $detail['original_price'] = $detail_original_price;
                        $detail['crossed_price'] = $detail_original_price;
                        $detail['stock'] = $detail_stock;
                        $detail['stock_count'] = $detail_stock;
                        $spec_details[] = $detail;
                    }
                }
            }
        }

        if (function_exists('todo_apply_currency_exchange')) {
            $price = todo_apply_currency_exchange($price);
            $original_price = todo_apply_currency_exchange($original_price);
            $wholesale_price = todo_apply_currency_exchange($wholesale_price);
            $wholesale_vip_price = todo_apply_currency_exchange($wholesale_vip_price);
        }

        $is_wholesale_user = in_array($user_level, ['wholesale', 'wholesale_vip'], true);

        todo_send_json(array(
            'success' => true,
            'user_id' => $user_id,
            'user_level' => $user_level,
            'is_wholesale_user' => $is_wholesale_user,
            'spec_type' => $spec_type,
            'price' => $price,
            'original_price' => $original_price,
            'stock' => $stock,
            'wholesale_price' => $wholesale_price,
            'wholesale_vip_price' => $wholesale_vip_price,
            'spec_details' => $spec_details
        ));
    } catch (Exception $e) {
        todo_send_json(array(
            'success' => false,
            'message' => $e->getMessage()
        ));
    }
}

/**
 * 批量获取商品实时库存与可售状态（一次 SQL 批量查询，防 N+1 问题）
 */
function todo_ajax_batch_get_stock_info()
{
    try {
        $raw_ids = $_POST['product_ids'] ?? ($_GET['product_ids'] ?? '');
        $ids = array();
        if (is_array($raw_ids)) {
            $ids = array_filter(array_map('intval', $raw_ids));
        } elseif (is_string($raw_ids) && trim($raw_ids) !== '') {
            $ids = array_filter(array_map('intval', explode(',', $raw_ids)));
        }

        $ids = array_values(array_unique($ids));

        if (empty($ids)) {
            todo_send_json(array('success' => true, 'products' => (object)[]));
        }

        global $tododb;
        $table_name = (isset($tododb->prefix) ? $tododb->prefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_')) . 'model_fields_product';
        
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT post_id, stock, spec_type, spec_details, price, original_price, wholesale_price, wholesale_vip_price, sale_status FROM {$table_name} WHERE post_id IN ({$placeholders})";
        
        $rows = $tododb->get_results($sql, $ids, 'ARRAY_A');

        $products = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $pid = intval($row['post_id']);
                $spec_type = $row['spec_type'] ?? 'single';
                $stock = intval($row['stock'] ?? 0);
                $sale_status = isset($row['sale_status']) && $row['sale_status'] !== '' ? $row['sale_status'] : 'online';
                $is_on_sale = ($sale_status !== 'warehouse');
                $is_available = false;

                if ($spec_type === 'single') {
                    $is_available = ($is_on_sale && $stock > 0);
                } else {
                    $spec_json = $row['spec_details'] ?? '';
                    $decoded = json_decode($spec_json, true);
                    if (!is_array($decoded)) $decoded = maybe_unserialize($spec_json);
                    
                    if (is_array($decoded) && !empty($decoded)) {
                        $total_variant_stock = 0;
                        foreach ($decoded as $d) {
                            if (is_array($d)) {
                                $v_stk = isset($d['stock']) ? intval($d['stock']) : intval($d['stock_count'] ?? 0);
                                if ($v_stk > 0) {
                                    $total_variant_stock += $v_stk;
                                }
                            }
                        }
                        if ($total_variant_stock > 0) {
                            $stock = $total_variant_stock;
                        }
                        $is_available = ($is_on_sale && $total_variant_stock > 0);
                    } else {
                        $is_available = ($is_on_sale && $stock > 0);
                    }
                }

                $products[(string)$pid] = array(
                    'id' => $pid,
                    'stock' => $stock,
                    'spec_type' => $spec_type,
                    'sale_status' => $sale_status,
                    'is_on_sale' => $is_on_sale,
                    'is_available' => $is_available
                );
            }
        }

        // 补全所有传入 ID 的默认状态
        foreach ($ids as $id) {
            $sid = (string)$id;
            if (!isset($products[$sid])) {
                $products[$sid] = array(
                    'id' => $id,
                    'stock' => 0,
                    'spec_type' => 'single',
                    'is_available' => false
                );
            }
        }

        todo_send_json(array(
            'success' => true,
            'products' => $products
        ));
    } catch (Exception $e) {
        todo_send_json(array(
            'success' => false,
            'message' => $e->getMessage()
        ));
    }
}

// 处理AJAX请求
if (!defined('PREVENT_AJAX_AUTO_RUN')) {
    $action = sanitize_text_field($_POST['action'] ?? ($_GET['action'] ?? ''));

    switch ($action) {
        case 'get_product_for_checkout':
            todo_ajax_get_product_for_checkout();
            break;

        case 'get_wholesale_info':
            todo_ajax_get_wholesale_info();
            break;

        case 'batch_get_stock_info':
            todo_ajax_batch_get_stock_info();
            break;

        default:
            todo_send_json(array(
                'success' => false,
                'message' => __('未知的操作')
            ));
            break;
    }
}
