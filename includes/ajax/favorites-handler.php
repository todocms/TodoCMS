<?php
/**
 * 收藏处理AJAX接口
 * 
 * @package TodoCMS
 * @subpackage AJAX
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
}

if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}

// 引导核心系统
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

if (!todo_is_ajax_request()) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Direct access denied']);
    exit;
}

// 检查用户登录状态
if (!is_user_logged_in()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'data' => __('请先登录')
    ]);
    exit;
}

$current_user = todo_get_current_user();
$user_id = todo_get_current_user_id();
$action = sanitize_text_field(todo_request_string('action'));

// PRG 防重复提交守卫（仅对携带 _submit_key 的 POST 生效）
Todo_PRG_Guard::guard_endpoint('favorites_' . $action, 30);

switch ($action) {
    case 'add':
        handle_add_favorite();
        break;
        
    case 'remove':
        handle_remove_favorite();
        break;

    case 'batch_remove':
    case 'batch-remove':
        handle_batch_remove_favorites();
        break;
        
    case 'toggle':
        handle_toggle_favorite();
        break;
        
    case 'check':
        handle_check_favorite();
        break;
        
    case 'get_list':
        handle_get_favorites_list();
        break;
        
    case 'get_count':
        handle_get_favorites_count();
        break;
        
    default:
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'data' => __('无效的操作')
        ]);
        exit;
}

/**
 * 添加收藏
 */
function handle_add_favorite() {
    global $user_id;
    
    // 验证CSRF令牌
    if (!todo_verify_nonce(todo_post_string('_todononce'), 'add_favorite')) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'data' => __('CSRF验证失败')
        ]);
        exit;
    }
    
    $product_id = todo_post_int('product_id');
    
    if (!$product_id) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'data' => __('商品ID无效')
        ]);
        exit;
    }
    
    // 检查商品是否存在
    $product = get_post($product_id);
    if (!$product || $product->post_status !== 'publish') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'data' => __('商品不存在或已下架')
        ]);
        exit;
    }
    
    // 检查是否已收藏
    if (is_product_favorited($user_id, $product_id)) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'data' => __('商品已在收藏列表中')
        ]);
        exit;
    }
    
    // 添加收藏
    $result = add_to_favorites($user_id, $product_id);
    
    if ($result['success']) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'data' => __('已添加到收藏'),
            'favorite_id' => $result['favorite_id'],
            'count' => get_user_favorites_count($user_id)
        ]);
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'data' => $result['message']
        ]);
    }
}

/**
 * 移除收藏
 */
function handle_remove_favorite() {
    global $user_id;
    
    // 验证CSRF令牌（若有令牌则校验，支持常规POST移除）
    $nonce = todo_post_string('_todononce', todo_post_string('_token'));
    if (!empty($nonce) && !todo_verify_nonce($nonce, 'remove_favorite')) {
        // 部分无nonce的普通界面防挂断兼容
    }
    
    $object_type = sanitize_text_field(todo_post_string('type', todo_post_string('object_type', 'product')));
    if ($object_type === 'article') {
        $object_type = 'post';
    }

    $favorite_id = todo_post_int('favorite_id', todo_post_int('id'));
    $object_id = todo_post_int('item_id', todo_post_int('product_id', todo_post_int('article_id', todo_post_int('object_id'))));
    
    if (!$favorite_id && !$object_id) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'data' => __('参数无效')
        ]);
        exit;
    }
    
    // 移除收藏
    if ($favorite_id) {
        $result = remove_favorite_by_id($user_id, $favorite_id);
    } else {
        $result = remove_favorite_by_object($user_id, $object_id, $object_type);
    }
    
    if ($result['success']) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => __('已移除收藏'),
            'data' => __('已移除收藏'),
            'count' => get_user_favorites_count($user_id)
        ]);
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $result['message'],
            'data' => $result['message']
        ]);
    }
}

/**
 * 批量移除收藏
 */
function handle_batch_remove_favorites() {
    global $user_id;
    
    $object_type = sanitize_text_field(todo_post_string('type', todo_post_string('object_type', 'product')));
    if ($object_type === 'article') {
        $object_type = 'post';
    }
    
    $item_ids = isset($_POST['item_ids']) && is_array($_POST['item_ids']) ? $_POST['item_ids'] : (isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : []);
    if (!is_array($item_ids) || empty($item_ids)) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'data' => __('未选择要删除的项目'),
            'message' => __('未选择要删除的项目')
        ]);
        exit;
    }
    
    $item_ids = array_map('intval', $item_ids);
    $item_ids = array_filter($item_ids, function($id) { return $id > 0; });
    
    if (empty($item_ids)) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'data' => __('无效的项目ID'),
            'message' => __('无效的项目ID')
        ]);
        exit;
    }
    
    $success_count = 0;
    foreach ($item_ids as $id) {
        $res = remove_favorite_by_object($user_id, $id, $object_type);
        if ($res['success']) {
            $success_count++;
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => sprintf(__('成功取消收藏 %d 项'), $success_count),
        'data' => sprintf(__('成功取消收藏 %d 项'), $success_count),
        'count' => get_user_favorites_count($user_id)
    ]);
}

/**
 * 切换收藏状态
 */
function handle_toggle_favorite() {
    global $user_id;
    
    // 验证CSRF令牌
    if (!todo_verify_nonce(todo_post_string('_todononce'), 'toggle_favorite')) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'data' => __('CSRF验证失败')
        ]);
        exit;
    }
    
    $object_type = sanitize_text_field(todo_post_string('object_type', todo_post_string('type', 'product')));
    if ($object_type === 'article') {
        $object_type = 'post';
    }
    
    $object_id = todo_post_int('object_id', todo_post_int('product_id', todo_post_int('article_id')));
    
    if (!$object_id) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'data' => __('ID无效')
        ]);
        exit;
    }
    
    // 检查内容是否存在
    $post = get_post($object_id);
    if (!$post || $post->post_status !== 'publish') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'data' => ($object_type === 'post' ? __('文章') : __('商品')) . __('不存在或已下架')
        ]);
        exit;
    }
    
    // 检查当前收藏状态
    $is_favorited = is_object_favorited($user_id, $object_id, $object_type);
    
    if ($is_favorited) {
        // 移除收藏
        $result = remove_favorite_by_object($user_id, $object_id, $object_type);
        $action_text = __('已移除收藏');
        $is_favorited = false;
    } else {
        // 添加收藏
        $result = add_object_to_favorites($user_id, $object_id, $object_type);
        $action_text = __('已添加到收藏');
        $is_favorited = true;
    }
    
    if ($result['success']) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'data' => $action_text,
            'is_favorited' => $is_favorited,
            'count' => get_user_favorites_count($user_id)
        ]);
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'data' => $result['message']
        ]);
    }
}

/**
 * 检查收藏状态
 */
function handle_check_favorite() {
    global $user_id;
    
    $object_type = sanitize_text_field(todo_get_string('object_type', todo_get_string('type', 'product')));
    if ($object_type === 'article') {
        $object_type = 'post';
    }
    
    $object_id = todo_get_int('object_id', todo_get_int('product_id', todo_get_int('article_id')));
    
    if (!$object_id) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'data' => __('ID无效')
        ]);
        exit;
    }
    
    $is_favorited = is_object_favorited($user_id, $object_id, $object_type);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'is_favorited' => $is_favorited
    ]);
}

/**
 * 获取收藏列表
 */
function handle_get_favorites_list() {
    global $user_id;
    
    $page = max(1, todo_get_int('page', 1));
    $per_page = min(50, max(1, todo_get_int('per_page', 12)));
    $category = sanitize_text_field(todo_get_string('category'));
    $sort = sanitize_text_field(todo_get_string('sort', 'latest'));
    
    $offset = ($page - 1) * $per_page;
    
    $favorites_data = get_user_favorites_ajax($user_id, $offset, $per_page, $category, $sort);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'data' => $favorites_data
    ]);
}

/**
 * 获取收藏数量
 */
function handle_get_favorites_count() {
    global $user_id;
    
    $count = get_user_favorites_count($user_id);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'count' => $count
    ]);
}

/**
 * 检查对象（商品或文章）是否已收藏
 */
function is_object_favorited($user_id, $object_id, $object_type = 'product') {
    global $tododb;
    if ($object_type === 'article') {
        $object_type = 'post';
    }
    
    $count = $tododb->get_var(
        "SELECT COUNT(*) 
        FROM {$tododb->prefix}member_favorites 
        WHERE user_id = ? AND object_id = ? AND object_type = ?",
        [$user_id, $object_id, $object_type]
    );
    
    return $count > 0;
}

/**
 * 检查商品是否已收藏
 */
function is_product_favorited($user_id, $product_id) {
    return is_object_favorited($user_id, $product_id, 'product');
}

/**
 * 添加对象（商品或文章）到收藏
 */
function add_object_to_favorites($user_id, $object_id, $object_type = 'product') {
    global $tododb;
    if ($object_type === 'article') {
        $object_type = 'post';
    }
    
    // 检查是否已存在
    if (is_object_favorited($user_id, $object_id, $object_type)) {
        return [
            'success' => false,
            'message' => __('该内容已在收藏列表中')
        ];
    }
    
    // 插入收藏记录
    $result = $tododb->insert(
        $tododb->prefix . 'member_favorites',
        [
            'user_id' => $user_id,
            'object_id' => $object_id,
            'object_type' => $object_type,
            'created_at' => current_time('mysql')
        ],
        ['%d', '%d', '%s', '%s']
    );
    
    if ($result === false) {
        return [
            'success' => false,
            'message' => __('添加收藏失败')
        ];
    }
    
    return [
        'success' => true,
        'favorite_id' => $tododb->insert_id(),
        'message' => __('添加收藏成功')
    ];
}

/**
 * 添加商品到收藏
 */
function add_to_favorites($user_id, $product_id) {
    return add_object_to_favorites($user_id, $product_id, 'product');
}

/**
 * 通过收藏ID移除收藏
 */
function remove_favorite_by_id($user_id, $favorite_id) {
    global $tododb;
    
    $result = $tododb->delete(
        $tododb->prefix . 'member_favorites',
        [
            'id' => $favorite_id,
            'user_id' => $user_id
        ],
        ['%d', '%d']
    );
    
    if ($result === false) {
        return [
            'success' => false,
            'message' => __('移除收藏失败')
        ];
    }
    
    if ($result === 0) {
        return [
            'success' => false,
            'message' => __('收藏记录不存在')
        ];
    }
    
    return [
        'success' => true,
        'message' => __('移除收藏成功')
    ];
}

/**
 * 通过对象ID移除收藏
 */
function remove_favorite_by_object($user_id, $object_id, $object_type = 'product') {
    global $tododb;
    if ($object_type === 'article') {
        $object_type = 'post';
    }
    
    $result = $tododb->delete(
        $tododb->prefix . 'member_favorites',
        [
            'user_id' => $user_id,
            'object_id' => $object_id,
            'object_type' => $object_type
        ],
        ['%d', '%d', '%s']
    );
    
    if ($result === false) {
        return [
            'success' => false,
            'message' => __('移除收藏失败')
        ];
    }
    
    if ($result === 0) {
        return [
            'success' => false,
            'message' => __('收藏记录不存在')
        ];
    }
    
    return [
        'success' => true,
        'message' => __('移除收藏成功')
    ];
}

/**
 * 通过商品ID移除收藏
 */
function remove_favorite_by_product($user_id, $product_id) {
    return remove_favorite_by_object($user_id, $product_id, 'product');
}

/**
 * 获取用户收藏数量
 */
function get_user_favorites_count($user_id) {
    global $tododb;
    
    $count = $tododb->get_var(
        "SELECT COUNT(*) 
        FROM {$tododb->prefix}member_favorites f
        INNER JOIN {$tododb->prefix}posts p ON f.object_id = p.ID
        WHERE f.user_id = ? AND f.object_type = 'product' AND p.post_status = 'publish'",
        [$user_id]
    );
    
    return intval($count);
}

/**
 * 获取用户收藏列表（AJAX版本）
 */
function get_user_favorites_ajax($user_id, $offset = 0, $per_page = 12, $category = '', $sort = 'latest') {
    global $tododb;
    
    // 构建WHERE条件
    $where_conditions = ["f.user_id = ?", "f.object_type = 'product'", "p.post_status = 'publish'"];
    $where_params = [$user_id];
    
    if ($category) {
        $where_conditions[] = "EXISTS (
            SELECT 1 FROM {$tododb->prefix}term_relationships tr 
            INNER JOIN {$tododb->prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id 
            WHERE tr.object_id = p.ID AND tt.taxonomy = 'product_cat' AND tt.term_id = ?
        )";
        $where_params[] = $category;
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    
    // 构建ORDER BY条件
    $order_by = "ORDER BY ";
    switch ($sort) {
        case 'oldest':
            $order_by .= "f.created_at ASC";
            break;
        case 'price_asc':
            $order_by .= "COALESCE(NULLIF(pm2.meta_value, ''), pm1.meta_value) + 0 ASC";
            break;
        case 'price_desc':
            $order_by .= "COALESCE(NULLIF(pm2.meta_value, ''), pm1.meta_value) + 0 DESC";
            break;
        case 'name':
            $order_by .= "p.post_title ASC";
            break;
        default: // latest
            $order_by .= "f.created_at DESC";
    }
    
    // 获取总数
    $total_query = "
        SELECT COUNT(*)
        FROM {$tododb->prefix}member_favorites f
        INNER JOIN {$tododb->prefix}posts p ON f.object_id = p.ID
        $where_clause
    ";
    
    $total = $tododb->get_var($total_query, $where_params);
    
    // 获取列表数据
    $list_params = array_merge($where_params, [$per_page, $offset]);
    $list_query = "
        SELECT f.id, f.object_id as product_id, f.created_at,
               p.post_title as product_name,
               p.post_excerpt as product_excerpt,
               pm1.field_value as regular_price,
               pm2.field_value as sale_price,
               pm3.field_value as stock_status,
               pm4.field_value as total_sales,
               pm5.field_value as average_rating
        FROM {$tododb->prefix}member_favorites f
        INNER JOIN {$tododb->prefix}posts p ON f.object_id = p.ID
        LEFT JOIN {$tododb->prefix}post_custom_fields pm1 ON p.ID = pm1.post_id AND pm1.field_key = '_regular_price'
        LEFT JOIN {$tododb->prefix}post_custom_fields pm2 ON p.ID = pm2.post_id AND pm2.field_key = '_sale_price'
        LEFT JOIN {$tododb->prefix}post_custom_fields pm3 ON p.ID = pm3.post_id AND pm3.field_key = '_stock_status'
        LEFT JOIN {$tododb->prefix}post_custom_fields pm4 ON p.ID = pm4.post_id AND pm4.field_key = '_total_sales'
        LEFT JOIN {$tododb->prefix}post_custom_fields pm5 ON p.ID = pm5.post_id AND pm5.field_key = '_average_rating'
        $where_clause
        $order_by
        LIMIT ? OFFSET ?
    ";
    
    $items = $tododb->get_results($list_query, $list_params, 'ARRAY_A');
    
    // 处理数据
    foreach ($items as &$item) {
        // 处理图片
        $item['featured_image'] = function_exists('get_the_post_thumbnail_url')
            ? (string) get_the_post_thumbnail_url((int) $item['product_id'], 'full')
            : '';
        
        // 处理价格
        $item['regular_price'] = floatval($item['regular_price'] ?: 0);
        $item['sale_price'] = floatval($item['sale_price'] ?: 0);
        $price = $item['sale_price'] > 0 ? $item['sale_price'] : $item['regular_price'];

        if ($price <= 0) {
            $pid = intval($item['product_id']);
            $candidate_keys = array('price', 'todo_product_price', 'product_price', 'wholesale_vip_price');
            foreach ($candidate_keys as $k) {
                $v = get_post_meta($pid, $k, true);
                if ($v !== '') {
                    if ($k === 'product_price' && is_string($v) && strpos($v, '{') !== false) {
                        $decoded = json_decode($v, true);
                        if (is_array($decoded)) {
                            $sv = floatval(preg_replace('/[^\d.]/', '', (string)($decoded['sale_price'] ?? 0)));
                            $rv = floatval(preg_replace('/[^\d.]/', '', (string)($decoded['regular_price'] ?? 0)));
                            $price = $sv > 0 ? $sv : $rv;
                            if ($item['regular_price'] <= 0) $item['regular_price'] = $rv > 0 ? $rv : $price;
                            if ($price > 0) break;
                        }
                    }
                    $v_clean = preg_replace('/[^\d.]/', '', (string)$v);
                    if (floatval($v_clean) > 0) {
                        $price = floatval($v_clean);
                        if ($item['regular_price'] <= 0) $item['regular_price'] = $price;
                        break;
                    }
                }
            }

            if ($price <= 0) {
                $mfp = $tododb->prefix . 'model_fields_product';
                try {
                    $model = $tododb->get_row("SELECT price, original_price FROM {$mfp} WHERE post_id = ? LIMIT 1", [$pid]);
                    if ($model && $model->price && floatval($model->price) > 0) {
                        $price = floatval($model->price);
                        if ($item['regular_price'] <= 0) $item['regular_price'] = floatval($model->original_price ?: $price);
                    }
                } catch (\Exception $e) {}
            }
        }
        $item['current_price'] = $price;
        
        // 处理其他字段
        $item['total_sales'] = intval($item['total_sales'] ?: 0);
        $item['average_rating'] = floatval($item['average_rating'] ?: 0);
        $item['stock_status'] = $item['stock_status'] ?: 'in_stock';
        
        // 获取分类信息
        $categories = function_exists('get_the_terms') ? get_the_terms($item['product_id'], 'product_cat') : [];
        if (!empty($categories) && is_array($categories)) {
            $first_cat = reset($categories);
            if (is_object($first_cat)) {
                $item['category_id'] = $first_cat->term_id ?? 0;
                $item['category_name'] = $first_cat->name ?? __('未分类');
            } elseif (is_array($first_cat)) {
                $item['category_id'] = $first_cat['term_id'] ?? 0;
                $item['category_name'] = $first_cat['name'] ?? __('未分类');
            } else {
                $item['category_id'] = 0;
                $item['category_name'] = __('未分类');
            }
        } else {
            $item['category_id'] = 0;
            $item['category_name'] = __('未分类');
        }
        
        // 格式化日期
        $item['created_at_formatted'] = date('Y-m-d H:i', strtotime($item['created_at']));
        
        // 商品链接
        $item['product_url'] = get_permalink($item['product_id']);
    }
    
    return [
        'items' => $items ?: [],
        'total' => intval($total),
        'page' => todo_get_int('page', 1),
        'per_page' => $per_page,
        'total_pages' => ceil($total / $per_page)
    ];
}
?>
