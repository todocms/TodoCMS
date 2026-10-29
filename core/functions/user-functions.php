<?php
/**
 * 用户中心相关函数
 * 
 * @package TodoCMS
 * @subpackage Core\Functions
 */

// 防止直接访问（但允许AJAX请求）
if (!defined('ABSPATH') && !defined('DOING_AJAX')) {
    exit('Direct access denied');
}

/**
 * 格式化单位文本 (全局统一 Helper)
 * 
 * @param float|int|string $unit_qty 单位数量 (如 500)
 * @param string $unit 单位标识 (如 g)
 * @return string 格式化文本 (如 500g克)
 */
if (!function_exists('todo_format_unit_text')) {
    function todo_format_unit_text($unit_qty, $unit) {
        if (empty($unit)) return '';
        $uq = floatval($unit_qty > 0 ? $unit_qty : 1);
        
        static $unit_map = null;
        if ($unit_map === null) {
            $unit_map = array(
                'piece'=>__('件'),'item'=>__('个'),'set'=>__('套'),'pair'=>__('双'),'pack'=>__('包'),
                'bag'=>__('袋'),'box'=>__('盒'),'bottle'=>__('瓶'),'can'=>__('罐'),'roll'=>__('卷'),
                'strip'=>__('条'),'sheet'=>__('张'),'portion'=>__('份'),'bowl'=>__('碗'),'plate'=>__('盘'),
                'cup'=>__('杯'),'bunch'=>__('把'),'head'=>__('颗/头'),'kg'=>__('千克'),'g'=>__('克'),
                'jin'=>'斤','liang'=>'两','ml'=>__('毫升'),'l'=>__('升'),'other'=>__('其他')
            );
        }
        
        $unit_name = isset($unit_map[$unit]) ? $unit_map[$unit] : $unit;
        if (function_exists('todo_parse_inline_translations')) {
            $unit_name = todo_parse_inline_translations($unit_name);
        }
        if (function_exists('todo_translate')) {
            $unit_name = todo_translate($unit_name);
        }

        $uq_disp = (floor($uq) == $uq) ? number_format($uq, 0) : rtrim(rtrim(number_format($uq, 2), '0'), '.');
        return $uq_disp . $unit_name;
    }
}

/**
 * 批量预加载商品单位元数据 (内存缓存，彻底消除 N+1 查询)
 * 
 * @param array $product_ids 商品ID列表
 * @return array 以 product_id 为键的单位缓存字典
 */
if (!function_exists('todo_preload_product_units')) {
    function todo_preload_product_units($product_ids) {
        if (empty($product_ids) || !is_array($product_ids)) {
            return array();
        }
        
        static $unit_cache = array();
        
        $product_ids = array_unique(array_filter(array_map('intval', $product_ids)));
        $to_fetch = array();
        foreach ($product_ids as $pid) {
            if ($pid > 0 && !isset($unit_cache[$pid])) {
                $to_fetch[] = $pid;
            }
        }
        
        if (!empty($to_fetch)) {
            global $tododb;
            if (isset($tododb) && is_object($tododb)) {
                $in_sql = implode(',', $to_fetch);
                $mfp_t = (isset($tododb->prefix) ? $tododb->prefix : 'todo_') . 'model_fields_product';
                try {
                    $rows = $tododb->get_results("SELECT post_id, unit, unit_qty FROM {$mfp_t} WHERE post_id IN ({$in_sql})", ARRAY_A);
                    if (is_array($rows)) {
                        foreach ($rows as $r) {
                            $pid = intval($r['post_id']);
                            $u = $r['unit'] ?? '';
                            if (function_exists('todo_parse_inline_translations')) {
                                $u = todo_parse_inline_translations($u);
                            }
                            if (function_exists('todo_translate')) {
                                $u = todo_translate($u);
                            }
                            $uq = $r['unit_qty'] ?? 1;
                            $unit_cache[$pid] = array(
                                'unit' => $u,
                                'unit_qty' => $uq,
                                'unit_text' => todo_format_unit_text($uq, $u)
                            );
                        }
                    }
                } catch (\Exception $e) {}
            }
            foreach ($to_fetch as $pid) {
                if (!isset($unit_cache[$pid])) {
                    $unit_cache[$pid] = array('unit' => '', 'unit_qty' => 1, 'unit_text' => '');
                }
            }
        }
        
        return $unit_cache;
    }
}

/**
 * 获取用户订单统计
 * 
 * @param int $user_id 用户ID
 * @return array 订单统计数据
 */
function get_user_order_stats($user_id)
{
    global $tododb;

    $sql = "
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid,
            SUM(CASE WHEN status = 'shipped' THEN 1 ELSE 0 END) as shipping,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
        FROM {$tododb->prefix}orders 
        WHERE user_id = ?
    ";
    $stats = $tododb->get_row($sql, [$user_id], 'ARRAY_A');

    return $stats ?: [
        'total' => 0,
        'pending' => 0,
        'paid' => 0,
        'shipping' => 0,
        'completed' => 0
    ];
}

/**
 * 获取用户收藏数量
 * 
 * @param int $user_id 用户ID
 * @return int 收藏数量
 */
function get_user_wishlist_count($user_id)
{
    global $tododb;

    return $tododb->get_var(
        "SELECT COUNT(*) 
        FROM {$tododb->prefix}member_favorites 
        WHERE user_id = ? AND object_type = 'product'",
        [$user_id]
    ) ?: 0;
}

/**
 * 获取用户评价数量
 * 
 * @param int $user_id 用户ID
 * @return int 评价数量
 */
function get_user_review_count($user_id)
{
    global $tododb;

    return $tododb->get_var(
        "SELECT COUNT(*) 
        FROM {$tododb->prefix}product_reviews 
        WHERE user_id = ?",
        [$user_id]
    ) ?: 0;
}

/**
 * 获取用户等级信息
 * 
 * @param int $user_id 用户ID
 * @return array 用户等级信息
 */
function get_user_level($user_id)
{
    $points = get_user_meta($user_id, '_user_points', true) ?: 0;

    $levels = [
        ['name' => '新手会员', 'min_points' => 0, 'next_points' => 100],
        ['name' => '铜牌会员', 'min_points' => 100, 'next_points' => 500],
        ['name' => '银牌会员', 'min_points' => 500, 'next_points' => 1000],
        ['name' => '金牌会员', 'min_points' => 1000, 'next_points' => 2000],
        ['name' => '钻石会员', 'min_points' => 2000, 'next_points' => 5000],
        ['name' => '至尊会员', 'min_points' => 5000, 'next_points' => 999999]
    ];

    foreach ($levels as $level) {
        if ($points >= $level['min_points'] && $points < $level['next_points']) {
            return $level;
        }
    }

    return end($levels);
}

/**
 * 获取用户最近订单
 * 
 * @param int $user_id 用户ID
 * @param int $limit 限制数量
 * @return array 订单列表
 */
function get_user_recent_orders($user_id, $limit = 5)
{
    global $tododb;

    $sql = "
        SELECT * 
        FROM {$tododb->prefix}orders 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT ?
    ";
    return $tododb->get_results($sql, [$user_id, $limit], 'ARRAY_A');
}

/**
 * 获取用户收藏商品
 * 
 * @param int $user_id 用户ID
 * @param int $limit 限制数量
 * @return array 收藏商品列表
 */
function get_user_wishlist_products($user_id, $limit = 4)
{
    global $tododb;

    $sql = "
        SELECT p.ID as id, p.post_title as title,
               pm1.field_value as sale_price,
               pm2.field_value as regular_price,
               pm3.field_value as product_image
        FROM {$tododb->prefix}member_favorites w
        LEFT JOIN {$tododb->prefix}posts p ON w.object_id = p.ID
        LEFT JOIN {$tododb->prefix}post_custom_fields pm3 ON p.ID = pm3.post_id AND pm3.field_key = '_product_image'
        WHERE w.user_id = ? AND w.object_type = 'product' AND p.post_status = 'publish'
        ORDER BY w.created_at DESC
        LIMIT ?
    ";
    return $tododb->get_results($sql, [$user_id, $limit], 'ARRAY_A');
}

/**
 * 获取用户收藏商品列表
 */
function todo_get_user_favorite_products($user_id, $limit = 50)
{
    global $tododb;

    $sql = "
        SELECT f.id as favorite_id, f.created_at as favorite_time,
               p.ID as product_id, p.post_title as product_name, p.post_excerpt as product_excerpt,
               pm1.field_value as regular_price,
               pm2.field_value as sale_price,
               pm3.field_value as stock_status
        FROM {$tododb->prefix}member_favorites f
        LEFT JOIN {$tododb->prefix}posts p ON f.object_id = p.ID
        LEFT JOIN {$tododb->prefix}post_custom_fields pm1 ON p.ID = pm1.post_id AND pm1.field_key = '_regular_price'
        LEFT JOIN {$tododb->prefix}post_custom_fields pm2 ON p.ID = pm2.post_id AND pm2.field_key = '_sale_price'
        LEFT JOIN {$tododb->prefix}post_custom_fields pm3 ON p.ID = pm3.post_id AND pm3.field_key = '_stock_status'
        WHERE f.user_id = ? AND f.object_type = 'product' AND p.post_status = 'publish'
        ORDER BY f.created_at DESC
        LIMIT ?
    ";
    $results = $tododb->get_results($sql, [$user_id, $limit], 'ARRAY_A');
    if (!$results) return [];

    foreach ($results as &$item) {
        $pid = intval($item['product_id']);
        if (function_exists('todo_get_the_title')) {
            $item['product_name'] = todo_get_the_title($pid) ?: $item['product_name'];
        }
        $product_img = function_exists('get_the_post_thumbnail_url') ? (string)get_the_post_thumbnail_url($pid, 'full') : '';
        if (empty($product_img) && function_exists('get_post_featured_image')) {
            $product_img = (string)get_post_featured_image($pid);
        }
        $item['product_image'] = $product_img;
        $item['product_url'] = function_exists('todo_get_permalink') ? todo_get_permalink($pid) : get_permalink($pid);
        $p_cats = function_exists('get_the_terms') ? (get_the_terms($pid, 'product_category') ?: get_the_terms($pid, 'category')) : [];
        if (!empty($p_cats) && is_array($p_cats)) {
            $first_cat = reset($p_cats);
            $cat_name = is_object($first_cat) ? ($first_cat->name ?? '未分类') : (is_array($first_cat) ? ($first_cat['name'] ?? '未分类') : '未分类');
            if (function_exists('todo_parse_inline_translations')) {
                $cat_name = todo_parse_inline_translations($cat_name);
            }
            $item['product_category'] = $cat_name;
        } else {
            $item['product_category'] = '未分类';
        }
        $reg = floatval($item['regular_price'] ?: 0);
        $sale = floatval($item['sale_price'] ?: 0);
        $price = $sale > 0 ? $sale : $reg;

        if ($price <= 0) {
            // 1. 尝试 candidate_keys
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
                            if ($reg <= 0) $reg = $rv > 0 ? $rv : $price;
                            if ($price > 0) break;
                        }
                    }
                    $v_clean = preg_replace('/[^\d.]/', '', (string)$v);
                    if (floatval($v_clean) > 0) {
                        $price = floatval($v_clean);
                        if ($reg <= 0) $reg = $price;
                        break;
                    }
                }
            }
        }

        if ($price <= 0) {
            // 2. 查询 model_fields_product 表 (TodoCMS 产品模型数据)
            $mfp = $tododb->prefix . 'model_fields_product';
            try {
                $model = $tododb->get_row("SELECT price, original_price, spec_details FROM {$mfp} WHERE post_id = ? LIMIT 1", [$pid]);
                if ($model) {
                    if ($model->price && floatval($model->price) > 0) {
                        $price = floatval($model->price);
                        if ($reg <= 0) $reg = floatval($model->original_price ?: $price);
                    }
                    if ($price <= 0 && !empty($model->spec_details)) {
                        $details = json_decode($model->spec_details, true);
                        if (is_array($details)) {
                            $spec_prices = [];
                            foreach ($details as $d) {
                                $sp = isset($d['users_price']) ? floatval($d['users_price']) : (isset($d['price']) ? floatval($d['price']) : 0);
                                if ($sp > 0) $spec_prices[] = $sp;
                            }
                            if (!empty($spec_prices)) {
                                $price = min($spec_prices);
                                if ($reg <= 0) $reg = $price;
                            }
                        }
                    }
                }
            } catch (\Exception $e) {}
        }

        if ($price <= 0) {
            $sd = get_post_meta($pid, 'spec_details', true);
            if ($sd) {
                $details = json_decode($sd, true);
                if (is_array($details)) {
                    $spec_prices = [];
                    foreach ($details as $d) {
                        $sp = isset($d['users_price']) ? floatval($d['users_price']) : (isset($d['price']) ? floatval($d['price']) : 0);
                        if ($sp > 0) $spec_prices[] = $sp;
                    }
                    if (!empty($spec_prices)) {
                        $price = min($spec_prices);
                        if ($reg <= 0) $reg = $price;
                    }
                }
            }
        }

        $item['product_price'] = number_format($price, 2, '.', '');
        $item['product_original_price'] = number_format($reg > 0 ? $reg : $price, 2, '.', '');
        $item['product_stock'] = 99; // 默认库存数
        $item['product_sales'] = 0;
        $item['favorite_time'] = date('Y-m-d H:i', strtotime($item['favorite_time']));
    }
    return $results;
}

/**
 * 获取用户收藏文章列表
 */
function todo_get_user_favorite_articles($user_id, $limit = 50)
{
    global $tododb;

    $sql = "
        SELECT f.id as favorite_id, f.created_at as favorite_time,
               p.ID as article_id, p.post_title as article_title, p.post_excerpt as article_summary, p.post_date as article_time
        FROM {$tododb->prefix}member_favorites f
        LEFT JOIN {$tododb->prefix}posts p ON f.object_id = p.ID
        WHERE f.user_id = ? AND f.object_type = 'post' AND p.post_status = 'publish'
        ORDER BY f.created_at DESC
        LIMIT ?
    ";
    $results = $tododb->get_results($sql, [$user_id, $limit], 'ARRAY_A');
    if (!$results) return [];

    foreach ($results as &$item) {
        $aid = intval($item['article_id']);
        if (function_exists('todo_get_the_title')) {
            $item['article_title'] = todo_get_the_title($aid) ?: $item['article_title'];
        }
        if (function_exists('todo_get_the_excerpt')) {
            $excerpt = todo_get_the_excerpt(150, $aid);
            if ($excerpt !== '') {
                $item['article_summary'] = $excerpt;
            }
        }
        if (function_exists('todo_parse_inline_translations')) {
            $item['article_summary'] = todo_parse_inline_translations($item['article_summary']);
        }
        $article_img = function_exists('get_the_post_thumbnail_url') ? (string)get_the_post_thumbnail_url($aid, 'full') : '';
        if (empty($article_img) && function_exists('get_post_featured_image')) {
            $article_img = (string)get_post_featured_image($aid);
        }
        $item['article_image'] = $article_img;
        $item['article_url'] = function_exists('todo_get_permalink') ? todo_get_permalink($aid) : get_permalink($aid);
        $categories = function_exists('get_the_terms') ? get_the_terms($aid, 'category') : [];
        if (!empty($categories) && is_array($categories)) {
            $first_cat = reset($categories);
            $cat_name = is_object($first_cat) ? ($first_cat->name ?? '未分类') : (is_array($first_cat) ? ($first_cat['name'] ?? '未分类') : '未分类');
            if (function_exists('todo_parse_inline_translations')) {
                $cat_name = todo_parse_inline_translations($cat_name);
            }
            $item['article_category'] = $cat_name;
        } else {
            $item['article_category'] = '未分类';
        }
        $item['article_views'] = intval(get_post_meta($aid, 'views', true) ?: 0);
        $item['article_time'] = date('Y-m-d', strtotime($item['article_time']));
        $item['favorite_time'] = date('Y-m-d H:i', strtotime($item['favorite_time']));
    }
    return $results;
}

/**
 * 获取用户最近浏览商品
 * 
 * @param int $user_id 用户ID
 * @param int $limit 限制数量
 * @return array 浏览商品列表
 */
function get_user_recent_viewed($user_id, $limit = 4)
{
    global $tododb;

    $sql = "
        SELECT p.ID as id, p.post_title as title,
               pm1.field_value as sale_price,
               pm2.field_value as regular_price,
               pm3.field_value as product_image,
               v.viewed_at
        FROM {$tododb->prefix}user_viewed_products v
        LEFT JOIN {$tododb->prefix}posts p ON v.product_id = p.ID
        LEFT JOIN {$tododb->prefix}post_custom_fields pm1 ON p.ID = pm1.post_id AND pm1.field_key = '_sale_price'
        LEFT JOIN {$tododb->prefix}post_custom_fields pm2 ON p.ID = pm2.post_id AND pm2.field_key = '_regular_price'
        LEFT JOIN {$tododb->prefix}post_custom_fields pm3 ON p.ID = pm3.post_id AND pm3.field_key = '_product_image'
        WHERE v.user_id = ? AND p.post_status = 'publish'
        ORDER BY v.viewed_at DESC
        LIMIT ?
    ";
    return $tododb->get_results($sql, [$user_id, $limit], 'ARRAY_A');
}

/**
 * 添加商品到用户收藏
 * 
 * @param int $user_id 用户ID
 * @param int $product_id 商品ID
 * @return bool 是否成功
 */
function add_user_wishlist($user_id, $product_id)
{
    global $tododb;

    // 检查是否已收藏
    $sql = "
        SELECT COUNT(*) 
        FROM {$tododb->prefix}member_favorites 
        WHERE user_id = ? AND object_id = ? AND object_type = 'product'
    ";
    $exists = $tododb->get_var($sql, [$user_id, $product_id]);

    if ($exists) {
        return false;
    }

    return $tododb->insert(
        $tododb->prefix . 'member_favorites',
        [
            'user_id' => $user_id,
            'object_id' => $product_id,
            'object_type' => 'product'
        ],
        ['%d', '%d', '%s']
    ) !== false;
}

/**
 * 从用户收藏中移除商品
 * 
 * @param int $user_id 用户ID
 * @param int $product_id 商品ID
 * @return bool 是否成功
 */
function remove_user_wishlist($user_id, $product_id)
{
    global $tododb;

    return $tododb->delete(
        $tododb->prefix . 'member_favorites',
        [
            'user_id' => $user_id,
            'object_id' => $product_id,
            'object_type' => 'product'
        ],
        ['%d', '%d', '%s']
    ) !== false;
}

/**
 * 记录用户浏览商品
 * 
 * @param int $user_id 用户ID
 * @param int $product_id 商品ID
 * @return bool 是否成功
 */
function record_user_viewed_product($user_id, $product_id)
{
    global $tododb;

    // 删除旧记录
    $tododb->delete(
        $tododb->prefix . 'user_viewed_products',
        [
            'user_id' => $user_id,
            'product_id' => $product_id
        ],
        ['%d', '%d']
    );

    // 插入新记录
    return $tododb->insert(
        $tododb->prefix . 'user_viewed_products',
        [
            'user_id' => $user_id,
            'product_id' => $product_id,
            'viewed_at' => current_time('mysql')
        ],
        ['%d', '%d', '%s']
    ) !== false;
}

/**
 * 获取用户地址列表
 * 
 * @param int $user_id 用户ID
 * @return array 地址列表
 */
function get_user_addresses($user_id)
{
    global $tododb;

    $sql = "
        SELECT * 
        FROM {$tododb->prefix}member_addresses 
        WHERE user_id = ? 
        ORDER BY is_default DESC, created_at DESC
    ";
    return $tododb->get_results($sql, [$user_id], 'ARRAY_A');
}

/**
 * 获取用户默认地址
 * 
 * @param int $user_id 用户ID
 * @return array|null 默认地址
 */
function get_user_default_address($user_id)
{
    global $tododb;
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return null;
    }

    $table = $tododb->prefix . 'member_addresses';

    $defaults = $tododb->get_results(
        "SELECT id FROM {$table} WHERE user_id = ? AND is_default = 1 ORDER BY created_at ASC, id ASC",
        [$user_id],
        'ARRAY_A'
    );

    if (!empty($defaults)) {
        $keep_id = (int) ($defaults[0]['id'] ?? 0);
        if ($keep_id > 0 && count($defaults) > 1) {
            $tododb->query("UPDATE {$table} SET is_default = 0 WHERE user_id = ? AND id <> ?", [$user_id, $keep_id]);
            $tododb->update($table, ['is_default' => 1], ['user_id' => $user_id, 'id' => $keep_id]);
        }

        if ($keep_id > 0) {
            return $tododb->get_row(
                "SELECT * FROM {$table} WHERE id = ? AND user_id = ? LIMIT 1",
                [$keep_id, $user_id],
                'ARRAY_A'
            );
        }
    }

    $first = $tododb->get_row(
        "SELECT * FROM {$table} WHERE user_id = ? ORDER BY created_at ASC, id ASC LIMIT 1",
        [$user_id],
        'ARRAY_A'
    );
    if (!$first) {
        return null;
    }

    $keep_id = (int) ($first['id'] ?? 0);
    if ($keep_id <= 0) {
        return null;
    }

    $tododb->query("UPDATE {$table} SET is_default = 0 WHERE user_id = ?", [$user_id]);
    $tododb->update($table, ['is_default' => 1], ['user_id' => $user_id, 'id' => $keep_id]);
    $first['is_default'] = 1;

    return $first;
}

/**
 * 设置用户默认地址
 * 
 * @param int $user_id 用户ID
 * @param int $address_id 地址ID
 * @return bool 是否成功
 */
function set_user_default_address($user_id, $address_id)
{
    global $tododb;

    // 先清除所有默认地址
    $tododb->update(
        $tododb->prefix . 'member_addresses',
        ['is_default' => 0],
        ['user_id' => $user_id],
        ['%d'],
        ['%d']
    );

    // 设置新的默认地址
    return $tododb->update(
        $tododb->prefix . 'member_addresses',
        ['is_default' => 1],
        [
            'user_id' => $user_id,
            'id' => $address_id
        ],
        ['%d'],
        ['%d', '%d']
    ) !== false;
}

function todo_save_guest_order_id($order_id, $order_no = '') {
    $order_id = (int) $order_id;
    if ($order_id <= 0) {
        return;
    }
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }
    if (!isset($_SESSION['todo_guest_orders']) || !is_array($_SESSION['todo_guest_orders'])) {
        $_SESSION['todo_guest_orders'] = [];
    }
    if (!in_array($order_id, $_SESSION['todo_guest_orders'], true)) {
        $_SESSION['todo_guest_orders'][] = $order_id;
    }
    
    $cookie_ids = [];
    if (!empty($_COOKIE['todo_guest_orders'])) {
        $raw = $_COOKIE['todo_guest_orders'];
        $decoded = json_decode(stripslashes($raw), true);
        if (is_array($decoded)) {
            $cookie_ids = array_map('intval', $decoded);
        } else {
            $cookie_ids = array_map('intval', explode(',', $raw));
        }
    }
    if (!in_array($order_id, $cookie_ids, true)) {
        $cookie_ids[] = $order_id;
    }
    $cookie_ids = array_values(array_unique(array_filter($cookie_ids)));
    if (!headers_sent()) {
        @setcookie('todo_guest_orders', json_encode($cookie_ids), time() + 86400 * 365, '/');
    }
}

/**
 * 国际标准：注册/登录时平滑继承与划转游客历史订单至当前用户账号
 *
 * @param int $user_id 正式登录/注册的用户ID
 * @return int 成功合并划转的订单数量
 */
function todo_claim_and_bind_guest_orders($user_id) {
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return 0;
    }

    global $tododb;
    if (!$tododb || !isset($tododb->prefix)) {
        return 0;
    }

    $orders_table = $tododb->prefix . 'orders';
    $user_coupons_table = $tododb->prefix . 'user_coupons';

    // 1. 获取用户基本信息 (user_email / phone)
    $user_email = '';
    $user_phone = '';

    if (function_exists('get_userdata')) {
        $u_info = get_userdata($user_id);
        if ($u_info && isset($u_info->user_email)) {
            $user_email = trim($u_info->user_email);
        }
    } else {
        $u_row = $tododb->get_row($tododb->prepare("SELECT user_email FROM {$tododb->prefix}users WHERE ID = %d LIMIT 1", $user_id), ARRAY_A);
        if ($u_row && !empty($u_row['user_email'])) {
            $user_email = trim($u_row['user_email']);
        }
    }

    if (function_exists('get_user_meta')) {
        $user_phone = trim((string) get_user_meta($user_id, 'phone', true));
    }

    // 2. 从本地 Session / Cookie 获取暂存的游客订单 ID 列表
    $guest_order_ids = todo_get_guest_order_ids();

    // 3. 构建多途径匹配查询（Cookie/Session 匹配 + 邮箱匹配 + 收件手机号匹配）
    $where_clauses = [];
    $params = [];

    // 条件 A: 本地 Cookie / Session 暂存凭证
    if (!empty($guest_order_ids)) {
        $in_placeholders = implode(',', array_fill(0, count($guest_order_ids), '%d'));
        $where_clauses[] = "id IN ({$in_placeholders})";
        $params = array_merge($params, $guest_order_ids);
    }

    // 条件 B: 游客结算留下的邮箱匹配
    if ($user_email !== '' && strpos($user_email, '@guest.local') === false) {
        $where_clauses[] = "shipping_address LIKE %s";
        $params[] = '%' . $tododb->esc_like($user_email) . '%';
    }

    // 条件 C: 收件地址信息中的手机号匹配
    if ($user_phone !== '') {
        $where_clauses[] = "shipping_address LIKE %s";
        $params[] = '%' . $tododb->esc_like($user_phone) . '%';
    }

    if (empty($where_clauses)) {
        return 0;
    }

    $where_sql = "(user_id = 0 OR user_id IS NULL) AND (" . implode(' OR ', $where_clauses) . ")";
    $sql_find = $tododb->prepare("SELECT id FROM {$orders_table} WHERE {$where_sql}", ...$params);
    $target_orders = $tododb->get_results($sql_find, ARRAY_A);

    if (empty($target_orders) || !is_array($target_orders)) {
        return 0;
    }

    $claimed_count = 0;
    $claimed_order_ids = [];

    foreach ($target_orders as $ord) {
        $oid = (int) ($ord['id'] ?? 0);

        if ($oid <= 0) continue;

        // 统一更新订单所属 user_id
        $ok = $tododb->update($orders_table, ['user_id' => $user_id], ['id' => $oid]);
        if ($ok !== false) {
            $claimed_count++;
            $claimed_order_ids[] = $oid;

            // 若订单绑定了优惠券，同步把 user_coupons 所属关联划转给该用户
            $tododb->update($user_coupons_table, ['user_id' => $user_id], ['order_id' => $oid]);
        }
    }

    // 4. 清理本地 Cookie / Session
    if ($claimed_count > 0) {
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['todo_guest_orders'])) {
            $_SESSION['todo_guest_orders'] = array_values(array_diff($_SESSION['todo_guest_orders'], $claimed_order_ids));
        }
        if (!empty($_COOKIE['todo_guest_orders'])) {
            $raw = $_COOKIE['todo_guest_orders'];
            $decoded = json_decode(stripslashes($raw), true);
            if (is_array($decoded)) {
                $rem = array_values(array_diff($decoded, $claimed_order_ids));
                if (!headers_sent()) {
                    @setcookie('todo_guest_orders', json_encode($rem), time() + 86400 * 365, '/');
                }
            }
        }
    }

    return $claimed_count;
}

function todo_get_guest_order_ids() {
    $ids = [];

    $debug = [
        'session_ids' => [],
        'cookie_ids' => [],
        'final_ids' => []
    ];
    
    // 1. 从 Session 读取
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }
    if (isset($_SESSION['todo_guest_orders']) && is_array($_SESSION['todo_guest_orders'])) {
        foreach ($_SESSION['todo_guest_orders'] as $id) {
            $ids[] = (int) $id;
            $debug['session_ids'][] = (int) $id;
        }
    }
    
    // 2. 从 Cookie 读取
    if (!empty($_COOKIE['todo_guest_orders'])) {
        $raw = $_COOKIE['todo_guest_orders'];
        $decoded = json_decode(stripslashes($raw), true);
        if (is_array($decoded)) {
            foreach ($decoded as $id) {
                $ids[] = (int) $id;
                $debug['cookie_ids'][] = (int) $id;
            }
        } else {
            $parts = explode(',', $raw);
            foreach ($parts as $p) {
                $ids[] = (int) trim($p);
                $debug['cookie_ids'][] = (int) trim($p);
            }
        }
    }
    
    $final = array_values(array_unique(array_filter($ids)));
    $debug['final_ids'] = $final;
    $GLOBALS['todo_order_search_debug'] = $debug;
    return $final;
}

function todo_get_user_order_count($user_id, $status = '')
{
    $user_id = (int) $user_id;
    $status = is_string($status) ? trim($status) : '';
    $search = '';
    if (!empty($_GET['search_order'])) {
        $search = sanitize_text_field((string) $_GET['search_order']);
    } elseif (!empty($_GET['order_no'])) {
        $search = sanitize_text_field((string) $_GET['order_no']);
    } elseif (!empty($_GET['guest_query'])) {
        $search = sanitize_text_field((string) $_GET['guest_query']);
    }

    global $tododb;
    if (!$tododb) {
        return '0';
    }

    $table = $tododb->prefix . 'orders';
    try {
        if ($user_id > 0) {
            $where = ["user_id = ?"];
            $params = [$user_id];
            if ($status !== '') {
                if ($status === 'completed') {
                    $where[] = "status = ? AND (reviewed IS NULL OR reviewed = 0)";
                    $params[] = $status;
                } elseif ($status === 'refund') {
                    $where[] = "(status IN ('refund', 'refunded') OR payment_status IN ('refunding', 'refunded'))";
                } else {
                    $where[] = "status = ?";
                    $params[] = $status;
                }
            }
            if ($search !== '') {
                $like = '%' . $tododb->esc_like($search) . '%';
                $where[] = "(order_no = ? OR order_no LIKE ? OR shipping_address LIKE ? OR remark LIKE ?)";
                array_push($params, $search, $like, $like, $like);
            }
            $sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode(' AND ', $where);
            return (string) ((int) ($tododb->get_var($sql, $params) ?: 0));
        } else {
            // 游客模式
            $guest_ids = todo_get_guest_order_ids();
            if (empty($guest_ids)) {
                return '0';
            }
            $in = implode(',', array_fill(0, count($guest_ids), '?'));
            $where = ["id IN ({$in})"];
            $params = $guest_ids;
            if ($status !== '') {
                if ($status === 'completed') {
                    $where[] = "status = ? AND (reviewed IS NULL OR reviewed = 0)";
                    $params[] = $status;
                } elseif ($status === 'refund') {
                    $where[] = "(status IN ('refund', 'refunded') OR payment_status IN ('refunding', 'refunded'))";
                } else {
                    $where[] = "status = ?";
                    $params[] = $status;
                }
            }
            if ($search !== '') {
                $like = '%' . $tododb->esc_like($search) . '%';
                $where[] = "(order_no = ? OR order_no LIKE ? OR shipping_address LIKE ? OR remark LIKE ?)";
                array_push($params, $search, $like, $like, $like);
            }
            $sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode(' AND ', $where);
            return (string) ((int) ($tododb->get_var($sql, $params) ?: 0));
        }
    } catch (Throwable $e) {
        return '0';
    }
}

function todo_get_user_orders($user_id, $status = '', $limit = 10, $page = 1)
{
    $user_id = (int) $user_id;
    $status = is_string($status) ? trim($status) : '';
    $search = '';
    if (!empty($_GET['search_order'])) {
        $search = sanitize_text_field((string) $_GET['search_order']);
    } elseif (!empty($_GET['order_no'])) {
        $search = sanitize_text_field((string) $_GET['order_no']);
    } elseif (!empty($_GET['guest_query'])) {
        $search = sanitize_text_field((string) $_GET['guest_query']);
    }
    $limit = (int) $limit;
    if ($limit <= 0) {
        $limit = 10;
    }
    $page = (int) $page;
    if ($page <= 0) {
        $page = 1;
    }
    $offset = ($page - 1) * $limit;

    global $tododb;
    if (!$tododb) {
        return [];
    }

    $orders_table = $tododb->prefix . 'orders';
    $items_table = $tododb->prefix . 'order_items';

    if ($user_id > 0) {
        $params = [$user_id];
        $where_parts = ["user_id = ?"];
        if ($status !== '') {
            if ($status === 'refund') {
                $where_parts[] = "(status IN ('refund', 'refunded') OR payment_status IN ('refunding', 'refunded'))";
            } else {
                $where_parts[] = "status = ?";
                $params[] = $status;
            }
        }
        if ($search !== '') {
            $like = '%' . $tododb->esc_like($search) . '%';
            $where_parts[] = "(order_no = ? OR order_no LIKE ? OR shipping_address LIKE ? OR remark LIKE ?)";
            array_push($params, $search, $like, $like, $like);
        }
        $where = implode(' AND ', $where_parts);
    } else {
        $guest_ids = todo_get_guest_order_ids();
        if (empty($guest_ids)) {
            return [];
        }
        $in = implode(',', array_fill(0, count($guest_ids), '?'));
        $params = $guest_ids;
        $where = "id IN ({$in})";
        if ($status !== '') {
            if ($status === 'refund') {
                $where .= " AND (status IN ('refund', 'refunded') OR payment_status IN ('refunding', 'refunded'))";
            } else {
                $where .= " AND status = ?";
                $params[] = $status;
            }
        }
        if ($search !== '') {
            $like = '%' . $tododb->esc_like($search) . '%';
            $where .= " AND (order_no = ? OR order_no LIKE ? OR shipping_address LIKE ? OR remark LIKE ?)";
            array_push($params, $search, $like, $like, $like);
        }
    }

    try {
        $rows = $tododb->get_results(
            "SELECT id, order_no, total_amount, payment_amount, shipping_fee, discount_amount, status, payment_status, shipping_status, reviewed, created_at
             FROM {$orders_table}
             WHERE {$where}
             ORDER BY created_at DESC, id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params,
            'ARRAY_A'
        );
    } catch (Throwable $e) {
        return [];
    }

    if (empty($rows)) {
        return [];
    }

    $order_ids = [];
    foreach ($rows as $r) {
        if (isset($r['id'])) {
            $order_ids[] = (int) $r['id'];
        }
    }
    $order_ids = array_values(array_filter($order_ids));

    $items_by_order = [];
    if (!empty($order_ids)) {
        $in = implode(',', array_fill(0, count($order_ids), '?'));
        try {
            $item_rows = $tododb->get_results(
                "SELECT order_id, product_id, product_name, product_image, spec_name, price, quantity
                 FROM {$items_table}
                 WHERE order_id IN ({$in})
                 ORDER BY id ASC",
                $order_ids,
                'ARRAY_A'
            );
            foreach (($item_rows ?: []) as $it) {
                $oid = isset($it['order_id']) ? (int) $it['order_id'] : 0;
                if ($oid <= 0) {
                    continue;
                }
                if (!isset($items_by_order[$oid])) {
                    $items_by_order[$oid] = [];
                }
                $items_by_order[$oid][] = $it;
            }
        } catch (Throwable $e) {
            $items_by_order = [];
        }
    }

    $status_text_map = [
        'pending' => '待付款',
        'processing' => '待发货',
        'shipped' => '待收货',
        'delivered' => '已送达',
        'completed' => '已完成',
        'cancelled' => '已取消',
        'refund' => '售后',
        'refunded' => '已退款',
    ];

    $pids_to_preload = [];
    foreach ($rows as $r) {
        $id = isset($r['id']) ? (int) $r['id'] : 0;
        if ($id > 0 && isset($items_by_order[$id][0]['product_id'])) {
            $pid = (int) $items_by_order[$id][0]['product_id'];
            if ($pid > 0) $pids_to_preload[] = $pid;
        }
    }
    $units_cache = function_exists('todo_preload_product_units') ? todo_preload_product_units($pids_to_preload) : [];

    $out = [];
    foreach ($rows as $r) {
        $id = isset($r['id']) ? (int) $r['id'] : 0;
        if ($id <= 0) {
            continue;
        }

        $created_at = isset($r['created_at']) ? (string) $r['created_at'] : '';
        $created_ts = $created_at !== '' ? strtotime($created_at) : 0;
        $created_at_format = $created_ts > 0 ? date('Y-m-d H:i', $created_ts) : $created_at;

        $pay_amount = 0.0;
        $payment_amount = isset($r['payment_amount']) ? (float) $r['payment_amount'] : 0.0;
        $total_amount = isset($r['total_amount']) ? (float) $r['total_amount'] : 0.0;
        $shipping_fee = isset($r['shipping_fee']) ? (float) $r['shipping_fee'] : 0.0;
        $discount_amount = isset($r['discount_amount']) ? (float) $r['discount_amount'] : 0.0;

        if ($payment_amount > 0) {
            $pay_amount = $payment_amount;
        } else {
            $pay_amount = max(0.0, $total_amount);
        }

        $status_val = isset($r['status']) ? (string) $r['status'] : '';
        $reviewed_val = isset($r['reviewed']) ? (int) $r['reviewed'] : 0;
        $is_reviewed = ($reviewed_val === 1);
        $items = $items_by_order[$id] ?? [];
        $items_count = is_array($items) ? count($items) : 0;
        $first = ($items_count > 0) ? (array) $items[0] : [];

        $spec_text = '';
        $sn = isset($first['spec_name']) ? (string) $first['spec_name'] : '';
        if ($sn !== '' && function_exists('todo_normalize_specs_text_from_spec_name')) {
            $spec_text = todo_normalize_specs_text_from_spec_name($sn, intval($first['product_id'] ?? 0));
        } elseif ($sn !== '') {
            $spec_text = trim($sn);
        } else {
            $specs_arr = [];
            $raw = isset($first['product_specs']) ? $first['product_specs'] : '';
            if (is_string($raw) && $raw !== '' && ($raw[0] === '{' || $raw[0] === '[')) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $specs_arr = $decoded;
                }
            }
            if (!empty($specs_arr) && function_exists('todo_format_specs_text')) {
                $spec_text = todo_format_specs_text($specs_arr, intval($first['product_id'] ?? 0));
            }
        }
        $p_title = isset($first['product_title']) && $first['product_title'] !== '' ? (string) $first['product_title'] : (isset($first['product_name']) ? (string) $first['product_name'] : '');
        $pid = intval($first['product_id'] ?? 0);
        if ($pid > 0 && function_exists('todo_get_the_title')) {
            $trans_t = todo_get_the_title($pid);
            if (!empty($trans_t)) {
                $p_title = $trans_t;
            }
        }
        $u_price = (float) ($first['price'] ?? 0);
        $u_raw = isset($first['unit']) ? (string)$first['unit'] : '';
        $uq_raw = isset($first['unit_qty']) ? $first['unit_qty'] : null;

        if (($u_raw === '' || $uq_raw === null) && $pid > 0) {
            $cached_u = $units_cache[$pid] ?? null;
            if ($cached_u) {
                if ($u_raw === '' && isset($cached_u['unit'])) $u_raw = (string)$cached_u['unit'];
                if ($uq_raw === null && isset($cached_u['unit_qty'])) $uq_raw = $cached_u['unit_qty'];
            }
        }

        $p_unit_txt = function_exists('todo_format_unit_text') ? todo_format_unit_text($uq_raw, $u_raw) : '';

        $p_url = '';
        if ($pid > 0) {
            if (function_exists('todo_get_permalink')) {
                $p_url = todo_get_permalink($pid);
            } elseif (function_exists('get_permalink')) {
                $p_url = get_permalink($pid);
            }
        }

        $first_item = [
            'product_id'    => $pid,
            'product_url'   => $p_url,
            'url'           => $p_url,
            'product_title' => $p_title,
            'product_name'  => $p_title,
            'product_image' => isset($first['product_image']) ? (string) $first['product_image'] : '',
            'quantity'      => isset($first['quantity']) ? (int) $first['quantity'] : 0,
            'unit_price_format' => function_exists('todo_format_price') ? todo_format_price($u_price, 'number') : number_format($u_price, 2),
            'spec_name'     => $spec_text,
            'specs_text'    => $spec_text,
            'product_specs' => $spec_text,
            'unit_text'     => $p_unit_txt
        ];

        $pstatus_val = isset($r['payment_status']) ? (string) $r['payment_status'] : '';
        $is_refunded = ($pstatus_val === 'refunded' || $status_val === 'refunded');
        $is_refunding = !$is_refunded && ($pstatus_val === 'refunding' || $status_val === 'refund');
        $can_apply_refund = false;
        
        static $cached_allow_refund = null;
        static $cached_return_days = null;
        if ($cached_allow_refund === null) {
            $srv_settings = function_exists('todo_shop_get_service_settings') ? todo_shop_get_service_settings() : [];
            $cached_allow_refund = !empty($srv_settings['allow_refund']);
            $cached_return_days = isset($srv_settings['return_window_days']) ? intval($srv_settings['return_window_days']) : 14;
        }

        if ($cached_allow_refund && ($pstatus_val === 'paid' || in_array($status_val, ['processing', 'shipped', 'delivered', 'completed'], true)) && !$is_refunding && !$is_refunded && $status_val !== 'cancelled') {
            $can_apply_refund = true;
            if ($cached_return_days > 0) {
                $base_ts = !empty($r['completed_at']) ? strtotime($r['completed_at']) : (!empty($r['shipped_at']) ? strtotime($r['shipped_at']) : 0);
                if ($base_ts > 0 && time() > ($base_ts + ($cached_return_days * 86400))) {
                    $can_apply_refund = false;
                }
            }
        }

        $out[] = [
            'id' => $id,
            'order_id' => $id,
            'order_no' => isset($r['order_no']) ? (string) $r['order_no'] : '',
            'status' => $status_val,
            'status_text' => $status_text_map[$status_val] ?? $status_val,
            'payment_status' => $pstatus_val,
            'shipping_status' => isset($r['shipping_status']) ? (string) $r['shipping_status'] : '',
            'reviewed' => $reviewed_val,
            'is_reviewed' => $is_reviewed,
            'is_refunding' => $is_refunding,
            'is_refunded' => $is_refunded,
            'can_apply_refund' => $can_apply_refund,
            'created_at' => $created_at,
            'created_at_format' => $created_at_format,
            'pay_amount' => $pay_amount,
            'pay_amount_format' => function_exists('todo_format_price') ? todo_format_price($pay_amount, 'number') : number_format($pay_amount, 2),
            'total_amount' => $total_amount,
            'total_amount_format' => function_exists('todo_format_price') ? todo_format_price($total_amount, 'number') : number_format($total_amount, 2),
            'payment_amount' => $payment_amount,
            'payment_amount_format' => function_exists('todo_format_price') ? todo_format_price($payment_amount, 'number') : number_format($payment_amount, 2),
            'items_count' => $items_count,
            'first_item' => $first_item,
        ];
    }

    return $out;
}

function todo_get_user_addresses($user_id, $limit = 0, $page = 1)
{
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return [];
    }

    $page = (int) $page;
    if ($page <= 0) {
        $page = 1;
    }

    $rows = get_user_addresses($user_id);
    if (!$rows) {
        return [];
    }

    if ($limit > 0) {
        $offset = ($page - 1) * (int) $limit;
        $rows = array_slice($rows, $offset, (int) $limit);
    }

    $out = [];
    foreach ($rows as $row) {
        $province = $row['province'] ?? '';
        $city = $row['city'] ?? '';
        $district = $row['district'] ?? '';

        $has_delimiter = (strpos($province, ' | ') !== false);
        $intl_country = '';
        $intl_state = '';

        if ($has_delimiter) {
            $p_parts = explode(' | ', $province, 2);
            $intl_country = trim($p_parts[0]);
            $intl_state = trim($p_parts[1] ?? '');
            $region_type = 'intl';
        } else {
            $region_type = 'cn';
        }

        $item = [
            'address_id' => isset($row['id']) ? (int) $row['id'] : 0,
            'recipient_name' => $row['name'] ?? '',
            'recipient_phone' => $row['phone'] ?? '',
            'province_id' => $province,
            'city_id' => $city,
            'district_id' => $district,
            'province_name' => '',
            'city_name' => '',
            'district_name' => '',
            'detailed_address' => $row['address'] ?? '',
            'postal_code' => $row['zipcode'] ?? '',
            'address_label' => $row['tag'] ?? '',
            'is_default' => !empty($row['is_default']) ? 1 : 0,
            'region_type' => $region_type,
            'intl_country' => $intl_country,
            'intl_state' => $intl_state,
        ];

        if (function_exists('todo_regions_find_name')) {
            $p_name = todo_regions_find_name($province);
            $c_name = todo_regions_find_name($city);
            $d_name = todo_regions_find_name($district);
            $item['province_name'] = !empty($p_name) ? $p_name : $province;
            $item['city_name'] = !empty($c_name) ? $c_name : $city;
            $item['district_name'] = !empty($d_name) ? $d_name : $district;
        } else {
            $item['province_name'] = $province;
            $item['city_name'] = $city;
            $item['district_name'] = $district;
        }

        $out[] = $item;
    }

    return $out;
}

function todo_get_user_address_count($user_id)
{
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return 0;
    }

    global $tododb;
    if (!$tododb) {
        return 0;
    }

    try {
        return (int) ($tododb->get_var("SELECT COUNT(*) FROM {$tododb->prefix}member_addresses WHERE user_id = ?", [$user_id]) ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * 将规格键名(v0/v1/v2)解析为规格组标题(如 颜色/尺寸)，用于显示
 *
 * @param array|string $specs 规格数组或JSON字符串 (如 {"v0":"黄","v1":"小"})
 * @param int $product_id 产品ID
 * @return string 格式化后的规格文本 (如 "颜色: 黄, 尺寸: 小")
 */
function todo_format_specs_text($specs, $product_id = 0)
{
    if (empty($specs)) {
        return '';
    }

    static $memo = [];
    $memo_key = (int) $product_id . ':' . md5(is_string($specs) ? $specs : json_encode($specs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if (isset($memo[$memo_key])) {
        return $memo[$memo_key];
    }

    // 统一转为数组
    if (is_string($specs)) {
        $decoded = json_decode($specs, true);
        $specs = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($specs)) {
        return '';
    }

    // 支持数组列表形式：
    // - ['v0' => '蓝', 'v1' => '中']
    // - ['颜色' => '蓝', '尺寸' => '中']
    // - ['蓝', '中']（按 v0/v1... 处理）
    // - [['spec_name'=>'颜色','spec_value'=>'蓝'], ...]
    $is_list = array_keys($specs) === range(0, count($specs) - 1);
    if ($is_list) {
        $all_pair = true;
        foreach ($specs as $it) {
            if (!is_array($it) || (!array_key_exists('spec_name', $it) && !array_key_exists('spec_value', $it))) {
                $all_pair = false;
                break;
            }
        }
        if ($all_pair) {
            $tmp = [];
            $idx = 0;
            foreach ($specs as $it) {
                $name = isset($it['spec_name']) ? trim((string) $it['spec_name']) : '';
                $val = isset($it['spec_value']) ? (is_string($it['spec_value']) ? trim((string) $it['spec_value']) : (is_scalar($it['spec_value']) ? (string) $it['spec_value'] : '')) : '';
                if ($val === '') {
                    $idx++;
                    continue;
                }
                $key = $name !== '' ? $name : ('v' . $idx);
                $tmp[$key] = $val;
                $idx++;
            }
            $specs = $tmp;
        } else {
            $tmp = [];
            foreach ($specs as $i => $v) {
                $val = is_string($v) ? trim($v) : (is_scalar($v) ? (string) $v : '');
                if ($val === '') {
                    continue;
                }
                $tmp['v' . (int) $i] = $val;
            }
            $specs = $tmp;
        }
    }

    // 获取 spec_config 解析标题映射
    $title_map = [];
    $product_id = (int) $product_id;
    if ($product_id > 0) {
        // 优先使用 Todo_Html_Template_Tags 缓存方法
        $product_data = null;
        if (class_exists('CMS_HTML_Template_Tags')) {
            $product_data = CMS_HTML_Template_Tags::get_product_model_row($product_id);
        }
        // 回退：直接查询数据库
        if (!$product_data) {
            global $tododb;
            if ($tododb) {
                $table = (isset($tododb->prefix) ? $tododb->prefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_')) . 'model_fields_product';
                try {
                    $product_data = $tododb->get_row("SELECT spec_config FROM {$table} WHERE post_id = ? LIMIT 1", [$product_id]);
                } catch (\Exception $e) {}
            }
        }
        // 回退：通过 post_meta
        if (!$product_data && function_exists('get_post_meta')) {
            $sc = get_post_meta($product_id, 'spec_config', true);
            if (!empty($sc)) {
                $product_data = (object) ['spec_config' => $sc];
            }
        }
        if ($product_data && !empty($product_data->spec_config)) {
            $spec_config = json_decode($product_data->spec_config, true);
            if (is_array($spec_config)) {
                foreach ($spec_config as $i => $group) {
                    $title_map['v' . $i] = $group['title'] ?? ($group['name'] ?? ('规格' . ($i + 1)));
                }
            }
        }
    }

    $parts = [];
    foreach ($specs as $key => $value) {
        $label = $title_map[$key] ?? null;
        if ($label === null && !empty($title_map)) {
            foreach ($title_map as $t_k => $t_v) {
                if (function_exists('todo_multilang_strings_match') && (todo_multilang_strings_match($t_k, $key) || todo_multilang_strings_match($t_v, $key))) {
                    $label = $t_v;
                    break;
                }
            }
        }
        if ($label === null) {
            $label = $key;
        }
        $value = is_string($value) ? trim($value) : (is_scalar($value) ? (string) $value : '');
        if ($value === '') continue;

        if (function_exists('todo_parse_inline_translations')) {
            $label = todo_parse_inline_translations($label);
            $value = todo_parse_inline_translations($value);
        }

        $parts[] = $label . ': ' . $value;
    }

    $memo[$memo_key] = implode(', ', $parts);
    return $memo[$memo_key];
}

function todo_normalize_specs_text_from_spec_name($spec_name, $product_id = 0)
{
    $spec_name = is_string($spec_name) ? trim($spec_name) : '';
    if ($spec_name === '') {
        return '';
    }

    if (preg_match('/(^|\\s)v\\d+:/', $spec_name)) {
        $spec_name = preg_replace('/\\s+/', ' ', $spec_name);
        $parts = explode(' ', $spec_name);
        $arr = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            $pos = strpos($p, ':');
            if ($pos === false) {
                continue;
            }
            $k = substr($p, 0, $pos);
            $v = trim((string) substr($p, $pos + 1));
            if ($k === '' || $v === '') {
                continue;
            }
            $arr[$k] = $v;
        }
        if (!empty($arr) && function_exists('todo_format_specs_text')) {
            return todo_format_specs_text($arr, (int) $product_id);
        }
    }

    if (function_exists('todo_parse_inline_translations')) {
        $spec_name = todo_parse_inline_translations($spec_name);
    }

    return $spec_name;
}

if (!function_exists('todo_spec_stable_key')) {
    /**
     * 生成规格值稳定 Hash key（与前端 JS stableKey 保持一一致）
     */
    function todo_spec_stable_key($vals) {
        $parts = is_array($vals) ? $vals : [$vals];
        $clean_parts = [];
        foreach ($parts as $v) {
            $clean_parts[] = preg_replace('/[\x{0001}\x{0002}]/u', '', (string)$v);
        }
        $s = implode("\x02", $clean_parts);
        $utf16le = mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
        $units = unpack('v*', $utf16le);
        $h = 5381;
        if (is_array($units)) {
            foreach ($units as $c) {
                $h = (($h << 5) + $h + $c) & 0xffffffff;
            }
        }
        $u32 = sprintf('%u', $h & 0xffffffff);
        return 'k' . base_convert($u32, 10, 36);
    }
}

/**
 * 获取全局统一国家与地区字典 (ISO 2-Letter Code -> English Name)
 *
 * @return array
 */
if (!function_exists('todo_get_country_list')) {
    function todo_get_country_list() {
        static $countries = null;
        if ($countries === null) {
            $countries = [
                // 常用热门国家/地区 (Priority / Popular)
                'US' => 'United States',
                'GB' => 'United Kingdom',
                'CA' => 'Canada',
                'AU' => 'Australia',
                'DE' => 'Germany',
                'FR' => 'France',
                'JP' => 'Japan',
                'IT' => 'Italy',
                'ES' => 'Spain',
                'NL' => 'Netherlands',
                'SG' => 'Singapore',
                'HK' => 'Hong Kong SAR',
                'TW' => 'Taiwan',
                // 全球其他国家/地区 (Alphabetical)
                'AF' => 'Afghanistan',
                'AL' => 'Albania',
                'DZ' => 'Algeria',
                'AD' => 'Andorra',
                'AO' => 'Angola',
                'AR' => 'Argentina',
                'AM' => 'Armenia',
                'AT' => 'Austria',
                'AZ' => 'Azerbaijan',
                'BS' => 'Bahamas',
                'BH' => 'Bahrain',
                'BD' => 'Bangladesh',
                'BB' => 'Barbados',
                'BY' => 'Belarus',
                'BE' => 'Belgium',
                'BZ' => 'Belize',
                'BJ' => 'Benin',
                'BM' => 'Bermuda',
                'BT' => 'Bhutan',
                'BO' => 'Bolivia',
                'BA' => 'Bosnia & Herzegovina',
                'BW' => 'Botswana',
                'BR' => 'Brazil',
                'BN' => 'Brunei',
                'BG' => 'Bulgaria',
                'KH' => 'Cambodia',
                'CM' => 'Cameroon',
                'CL' => 'Chile',
                'CN' => 'China',
                'CO' => 'Colombia',
                'CR' => 'Costa Rica',
                'HR' => 'Croatia',
                'CY' => 'Cyprus',
                'CZ' => 'Czech Republic',
                'DK' => 'Denmark',
                'DO' => 'Dominican Republic',
                'EC' => 'Ecuador',
                'EG' => 'Egypt',
                'SV' => 'El Salvador',
                'EE' => 'Estonia',
                'ET' => 'Ethiopia',
                'FJ' => 'Fiji',
                'FI' => 'Finland',
                'GE' => 'Georgia',
                'GH' => 'Ghana',
                'GR' => 'Greece',
                'GT' => 'Guatemala',
                'HN' => 'Honduras',
                'HU' => 'Hungary',
                'IS' => 'Iceland',
                'IN' => 'India',
                'ID' => 'Indonesia',
                'IQ' => 'Iraq',
                'IE' => 'Ireland',
                'IL' => 'Israel',
                'JM' => 'Jamaica',
                'JO' => 'Jordan',
                'KZ' => 'Kazakhstan',
                'KE' => 'Kenya',
                'KW' => 'Kuwait',
                'KG' => 'Kyrgyzstan',
                'LA' => 'Laos',
                'LV' => 'Latvia',
                'LB' => 'Lebanon',
                'LY' => 'Libya',
                'LI' => 'Liechtenstein',
                'LT' => 'Lithuania',
                'LU' => 'Luxembourg',
                'MO' => 'Macao SAR',
                'MK' => 'North Macedonia',
                'MG' => 'Madagascar',
                'MY' => 'Malaysia',
                'MV' => 'Maldives',
                'MT' => 'Malta',
                'MX' => 'Mexico',
                'MD' => 'Moldova',
                'MC' => 'Monaco',
                'MN' => 'Mongolia',
                'ME' => 'Montenegro',
                'MA' => 'Morocco',
                'NP' => 'Nepal',
                'NZ' => 'New Zealand',
                'NI' => 'Nicaragua',
                'NG' => 'Nigeria',
                'NO' => 'Norway',
                'OM' => 'Oman',
                'PK' => 'Pakistan',
                'PA' => 'Panama',
                'PY' => 'Paraguay',
                'PE' => 'Peru',
                'PH' => 'Philippines',
                'PL' => 'Poland',
                'PT' => 'Portugal',
                'QA' => 'Qatar',
                'RO' => 'Romania',
                'RU' => 'Russia',
                'SA' => 'Saudi Arabia',
                'RS' => 'Serbia',
                'SK' => 'Slovakia',
                'SI' => 'Slovenia',
                'ZA' => 'South Africa',
                'KR' => 'South Korea',
                'LK' => 'Sri Lanka',
                'SE' => 'Sweden',
                'CH' => 'Switzerland',
                'TZ' => 'Tanzania',
                'TH' => 'Thailand',
                'TN' => 'Tunisia',
                'TR' => 'Turkey',
                'UG' => 'Uganda',
                'UA' => 'Ukraine',
                'AE' => 'United Arab Emirates',
                'UY' => 'Uruguay',
                'UZ' => 'Uzbekistan',
                'VE' => 'Venezuela',
                'VN' => 'Vietnam',
                'ZM' => 'Zambia',
                'ZW' => 'Zimbabwe',
            ];
        }
        return $countries;
    }
}

/**
 * 渲染国家下拉框 HTML <option> 集合 (全局单源渲染 Helper)
 *
 * @param string $selected_code 预选中的 ISO 2 位国家代码
 * @return string <option> 集合 HTML 文本
 */
if (!function_exists('todo_render_country_options')) {
    function todo_render_country_options($selected_code = '') {
        $countries = todo_get_country_list();
        $selected_code = strtoupper(trim((string) $selected_code));

        $html = '<option value="">-- Select --</option>';

        $priority_keys = ['US', 'GB', 'CA', 'AU', 'DE', 'FR', 'JP', 'IT', 'ES', 'NL', 'SG', 'HK', 'TW'];
        foreach ($priority_keys as $code) {
            if (isset($countries[$code])) {
                $sel = ($selected_code === $code) ? ' selected' : '';
                $html .= '<option value="' . esc_attr($code) . '"' . $sel . '>' . esc_html($countries[$code]) . '</option>';
            }
        }

        $html .= '<option disabled>──────────</option>';

        $remaining = array_diff_key($countries, array_flip($priority_keys));
        asort($remaining);

        foreach ($remaining as $code => $name) {
            $sel = ($selected_code === $code) ? ' selected' : '';
            $html .= '<option value="' . esc_attr($code) . '"' . $sel . '>' . esc_html($name) . '</option>';
        }

        return $html;
    }
}

/**
 * 获取用户收藏数量
 *
 * @param int|null $user_id 用户ID，默认当前登录用户
 * @param string $object_type 收藏类型：'product'（商品）或 'post'（文章），为空统计全部
 * @return int 收藏数量
 */
if (!function_exists('todo_get_favorites_count')) {
    function todo_get_favorites_count($user_id = null, $object_type = '') {
        if (!$user_id) {
            $user_id = function_exists('todo_get_current_user_id') ? todo_get_current_user_id() : (function_exists('get_current_user_id') ? get_current_user_id() : 0);
        }
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return 0;
        }

        global $tododb;
        if (!isset($tododb) || !$tododb) {
            if (class_exists('CMS_DB')) {
                $tododb = CMS_DB::get_instance();
            }
        }
        if (!$tododb) {
            return 0;
        }

        $table = $tododb->prefix . 'member_favorites';
        if (!empty($object_type)) {
            $count = $tododb->get_var(
                "SELECT COUNT(*) FROM {$table} WHERE user_id = ? AND object_type = ?",
                [$user_id, $object_type]
            );
        } else {
            $count = $tododb->get_var(
                "SELECT COUNT(*) FROM {$table} WHERE user_id = ?",
                [$user_id]
            );
        }
        return intval($count ?: 0);
    }
}

/**
 * 获取购物车商品数量
 *
 * @param int|null $user_id
 * @return int 购物车商品总数
 */
if (!function_exists('todo_get_cart_count')) {
    function todo_get_cart_count($user_id = null) {
        if (class_exists('Todo_Cart_Manager') && is_callable(['Todo_Cart_Manager', 'get_cart'])) {
            $cart = Todo_Cart_Manager::get_cart();
            return is_array($cart) && isset($cart['count']) ? (int) $cart['count'] : 0;
        }
        return 0;
    }
}


