<?php
/**
 * TodoCMS 商品管理页面
 * 
 * 管理产品模型栏目下的商品
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('TODO_ADMIN')) {
    exit('Direct access denied');
}

// 权限检查
if (!current_user_can('manage_options')) {
    todo_die(__('You do not have sufficient permissions to access this page.'));
}

global $tododb;

// 获取当前站点上下文
$filter_site_id = (isset($_GET['site']) && is_numeric($_GET['site'])) ? (int)$_GET['site'] : 0;

// 增强版管理员检测逻辑
$is_super_admin = false;
if (function_exists('todo_is_super_admin') && todo_is_super_admin()) {
    $is_super_admin = true;
} else if (function_exists('todo_get_current_user')) {
    $u = todo_get_current_user();
    $roles_raw = [];
    if (is_array($u)) {
        $roles_raw = $u['roles'] ?? $u['meta']['roles'] ?? $u['role'] ?? [];
    } elseif (is_object($u)) {
        $roles_raw = $u->roles ?? $u->meta['roles'] ?? $u->role ?? [];
    }
    $roles = is_array($roles_raw) ? $roles_raw : ($roles_raw ? [$roles_raw] : []);
    $roles = array_map(function ($r) { return strtolower(trim((string) $r)); }, $roles);
    $is_super_admin = !empty(array_intersect(['super_admin', 'superadmin', 'admin', 'administrator'], $roles));
}

if (!$is_super_admin && function_exists('current_user_can') && current_user_can('manage_options')) {
    $is_super_admin = true;
}

$site_scope_id = $is_super_admin ? $filter_site_id : (function_exists('todo_current_site_id') ? (int)todo_current_site_id(false) : 0);

// 获取产品模型的栏目 (包含站点隔离逻辑)
$site_join_cat = "";
$site_where_cat = "";
if ($site_scope_id > 0) {
    $site_terms_table = $tododb->prefix . 'site_terms';
    // 同步 core/functions/post.php 中的 get_categories 逻辑：
    // 包含该站点的栏目，或者完全没有站点绑定的全局栏目
    $site_join_cat = " LEFT JOIN {$site_terms_table} st ON (t.term_id = st.term_id AND st.site_id = " . (int)$site_scope_id . ") ";
    $site_where_cat = " AND ((st.term_id IS NOT NULL AND st.is_visible = 1) OR NOT EXISTS (SELECT 1 FROM {$site_terms_table} stx WHERE stx.term_id = t.term_id AND stx.site_id <> 0)) ";
}

$product_categories_raw = $tododb->get_results(
    "SELECT t.term_id as id, t.name, t.slug, tt.description, tt.parent, tt.count 
     FROM {$tododb->prefix}terms t 
     INNER JOIN {$tododb->prefix}term_taxonomy tt ON t.term_id = tt.term_id 
     INNER JOIN {$tododb->prefix}term_models tm ON t.term_id = tm.term_id 
     INNER JOIN {$tododb->prefix}models m ON tm.model_id = m.model_id 
     {$site_join_cat}
     WHERE tt.taxonomy = 'category' AND m.model_type = 'product' {$site_where_cat}
       AND NOT EXISTS (
           SELECT 1 FROM {$tododb->prefix}term_attributes
           WHERE term_id = t.term_id AND JSON_UNQUOTE(JSON_EXTRACT(attributes, '$.trashed')) = '1'
       )
     ORDER BY tt.parent ASC, t.name ASC"
);

// 构建层级结构
function todo_products_build_cat_tree($cats, $parent = 0, $depth = 0) {
    $tree = [];
    foreach ($cats as $cat) {
        if ($cat->parent == $parent) {
            $cat->depth = $depth;
            $tree[] = $cat;
            $children = todo_products_build_cat_tree($cats, $cat->id, $depth + 1);
            if (!empty($children)) {
                $tree = array_merge($tree, $children);
            }
        }
    }
    return $tree;
}
$product_categories = todo_products_build_cat_tree($product_categories_raw);

if (empty($product_categories)) {
    echo '<div class="admin-page">';
    echo '<div class="admin-page-header">';
    echo '<h2 class="admin-page-title">商品管理</h2>';
    echo '</div>';
    echo '<div class="notice notice-warning is-dismissible">';
    echo '<p>还没有创建产品模型的栏目。请先在 <a href="' . todo_admin_url('index.php?page=categories') . '">栏目管理</a> 中创建使用产品模型的栏目。</p>';
    echo '<button type="button" class="notice-dismiss">✕</button>';
    echo '</div>';
    echo '</div>';
    return;
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF验证
    if (!isset($_POST['_todononce']) || !todo_verify_nonce($_POST['_todononce'], 'todo_products_action')) {
        todo_die('Security check failed');
    }

    $action = sanitize_text_field($_POST['action']);
    $message = '';
    $message_type = 'success';

    switch ($action) {
        case 'update_product_status':
            $product_id = intval($_POST['product_id']);
            $status = sanitize_text_field($_POST['status']);

            $result = todo_update_post([
                'ID' => $product_id,
                'post_status' => 'publish'
            ]);

            if ($result) {
                global $tododb;
                update_post_meta($product_id, 'sale_status', $status);
                $tododb->update(
                    $tododb->prefix . 'model_fields_product',
                    ['sale_status' => $status],
                    ['post_id' => $product_id]
                );
                
                // Clear post cache to reflect changes immediately
                if (function_exists('clean_post_cache')) {
                    clean_post_cache($product_id);
                } elseif (class_exists('Todo_Unified_Cache_Manager')) {
                    Todo_Unified_Cache_Manager::clean_post_cache($product_id);
                }

                $message = '商品状态更新成功';
            } else {
                $message = '商品状态更新失败';
                $message_type = 'error';
            }
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => (bool)$result, 'message' => $message]);
                exit;
            }
            break;

        case 'bulk_action':
            $product_ids = array_map('intval', $_POST['product_ids'] ?? []);
            $bulk_action = sanitize_text_field($_POST['bulk_action']);

            if (!empty($product_ids) && !empty($bulk_action)) {
                $success_count = 0;
                foreach ($product_ids as $product_id) {
                    $result = false;

                    switch ($bulk_action) {
                        case 'publish':
                        case 'draft':
                        case 'pending':
                        case 'trash':
                            $result = todo_update_post([
                                'ID' => $product_id,
                                'post_status' => $bulk_action
                            ]);
                            break;
                    }
                    if ($result) {
                        $success_count++;
                    }
                }
                $message = "成功处理 {$success_count} 个商品";
            } else {
                $message = '请选择商品和操作';
                $message_type = 'error';
            }
            break;

        case 'bulk_change_category':
            $product_ids = array_filter(array_map('intval', explode(',', sanitize_text_field($_POST['product_ids'] ?? ''))));
            $category_ids = array_map('intval', $_POST['category_ids'] ?? []);

            if (!empty($product_ids)) {
                $success_count = 0;
                foreach ($product_ids as $product_id) {
                    if (function_exists('todo_set_post_categories')) {
                        todo_set_post_categories($product_id, $category_ids);
                        $success_count++;
                    }
                }
                $message = "成功更新 {$success_count} 个商品的分类归属";
                $result = true;
            } else {
                $message = '请选择要操作的商品';
                $message_type = 'error';
                $result = false;
            }
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => $result, 'message' => $message]);
                exit;
            }
            break;
    }
}

// 获取筛选参数
$category_filter = intval($_GET['category'] ?? 0);
$status_filter = sanitize_text_field($_GET['status'] ?? '');
$sale_status_filter = sanitize_text_field($_GET['sale_status'] ?? '');
$search = sanitize_text_field($_GET['search'] ?? '');
$per_page = 20;
$current_page = max(1, intval($_GET['paged'] ?? 1));

// 执行查询
global $tododb;
$posts_table = $tododb->prefix . 'posts';
$term_rel_table = $tododb->prefix . 'term_relationships';
$term_tax_table = $tododb->prefix . 'term_taxonomy';

// 构建WHERE条件
$where_conditions = ["p.post_type = 'product'"];
$query_params = [];

// 站点筛选同步 posts.php
if ($site_scope_id > 0) {
    $site_posts_table = $tododb->prefix . 'site_posts';
    $need_site_join = true;
    $where_conditions[] = "sp.site_id = ?";
    $query_params[] = $site_scope_id;
    // 通常只看可见的
    $where_conditions[] = "sp.is_visible = 1";
} else {
    $need_site_join = false;
}

// 栏目筛选
if ($category_filter) {
    $where_conditions[] = "EXISTS (
        SELECT 1
        FROM {$term_rel_table} tr
        INNER JOIN {$term_tax_table} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category'
        WHERE tr.object_id = p.ID AND tt.term_id = ?
    )";
    $query_params[] = intval($category_filter);
}

// 状态筛选逻辑同步 posts.php
$now_gmt = current_time('mysql', true);
$need_product_join = false;

if (!empty($status_filter)) {
    if ($status_filter === 'scheduled') {
        $where_conditions[] = "(p.post_status = 'future' OR (p.post_status = 'publish' AND p.post_date_gmt > ?))";
        $query_params[] = $now_gmt;
    } elseif ($status_filter === 'warehouse') {
        $need_product_join = true;
        $where_conditions[] = "(p.post_status = 'publish' AND p.post_date_gmt <= ? AND mfp.sale_status = 'warehouse')";
        $query_params[] = $now_gmt;
    } elseif ($status_filter === 'publish') {
        $need_product_join = true;
        $where_conditions[] = "(p.post_status = 'publish' AND p.post_date_gmt <= ? AND (mfp.sale_status IS NULL OR mfp.sale_status <> 'warehouse'))";
        $query_params[] = $now_gmt;
    } else {
        $where_conditions[] = "p.post_status = ?";
        $query_params[] = $status_filter;
    }
} else {
    // 全部视图逻辑
    $need_product_join = true;
    $where_conditions[] = "p.post_status IN ('publish', 'draft', 'pending', 'future')";
}

// 叠加独立售卖状态过滤
if (!empty($sale_status_filter)) {
    if (!$need_product_join) {
        $need_product_join = true;
    }
    if ($sale_status_filter === 'warehouse') {
        $where_conditions[] = "mfp.sale_status = 'warehouse'";
    } elseif ($sale_status_filter === 'on_sale') {
        $where_conditions[] = "(mfp.sale_status IS NULL OR mfp.sale_status <> 'warehouse')";
    }
}

// 搜索
if (!empty($search)) {
    $where_conditions[] = "(p.post_title LIKE ? OR p.post_content LIKE ?)";
    $search_term = '%' . $search . '%';
    $query_params[] = $search_term;
    $query_params[] = $search_term;
}

$join_clause = "";
if ($need_product_join) {
    $join_clause .= " LEFT JOIN {$tododb->prefix}model_fields_product mfp ON mfp.post_id = p.ID ";
}
if ($need_site_join) {
    $site_posts_table = $tododb->prefix . 'site_posts';
    $join_clause .= " INNER JOIN {$site_posts_table} sp ON p.ID = sp.post_id ";
}
$where_clause = implode(' AND ', $where_conditions);

// 计算总数
$count_query = "
    SELECT COUNT(DISTINCT p.ID)
    FROM {$posts_table} p
    {$join_clause}
    WHERE {$where_clause}
";
$total_products = $tododb->get_var($count_query, $query_params);
$total_pages = ceil($total_products / $per_page);

// 获取商品列表
$offset = ($current_page - 1) * $per_page;
$products_query = "
    SELECT DISTINCT p.*, " . ($need_product_join ? "mfp.sale_status" : "NULL as sale_status") . "
    FROM {$posts_table} p
    {$join_clause}
    WHERE {$where_clause}
    ORDER BY p.post_date DESC
    LIMIT {$offset}, {$per_page}
";
$products = $tododb->get_results($products_query, $query_params);

// 批量预加载商品元数据缓存，避免N+1查询
if (!empty($products)) {
    $product_ids = array_map(function ($p) {
        return $p->ID;
    }, $products);
    update_post_meta_cache($product_ids);
    update_post_term_cache($product_ids, 'category');

    // 批量查询商品销量
    $products_sales = todo_get_products_sales_batch($product_ids);

    // 批量查询商品模型字段
    $products_model_fields = todo_get_product_model_fields_batch($product_ids);
}

// 获取商品统计
$product_stats = todo_get_product_stats($site_scope_id);

/**
 * 获取商品统计
 */
function todo_get_product_stats($site_id = 0)
{
    global $tododb;

    $now_gmt = current_time('mysql', true);
    
    $site_join = "";
    $site_where = "";
    if ($site_id > 0) {
        $site_posts_table = $tododb->prefix . 'site_posts';
        $site_join = " INNER JOIN {$site_posts_table} sp ON p.ID = sp.post_id ";
        $site_where = " AND sp.site_id = " . (int)$site_id . " AND sp.is_visible = 1 ";
    }

    // 统计物理状态基础分布（初步区分 publish/future 等）
    $query = "SELECT 
        CASE 
            WHEN post_status = 'publish' AND post_date_gmt <= ? THEN 'publish'
            WHEN post_status = 'publish' AND post_date_gmt > ? THEN 'scheduled'
            WHEN post_status = 'future' THEN 'scheduled'
            ELSE post_status 
        END as status_type,
        COUNT(*) as count 
        FROM {$tododb->prefix}posts p
        {$site_join}
        WHERE p.post_type = 'product' {$site_where}
        GROUP BY status_type";
    
    $counts = $tododb->get_results($query, [$now_gmt, $now_gmt]);

    $result = [
        'total' => 0,
        'publish' => 0,
        'warehouse' => 0,
        'draft' => 0,
        'pending' => 0,
        'scheduled' => 0,
        'trash' => 0
    ];

    if ($counts) {
        foreach ($counts as $count) {
            $status = $count->status_type;
            if (isset($result[$status])) {
                $result[$status] = intval($count->count);
            }
            $result['total'] += intval($count->count);
        }
    }

    // 从已发布中区分出“仓库”态
    $warehouse_sql = "SELECT COUNT(*) FROM {$tododb->prefix}posts p
        LEFT JOIN {$tododb->prefix}model_fields_product mfp ON mfp.post_id = p.ID
        {$site_join}
        WHERE p.post_type = 'product' AND p.post_status = 'publish' AND p.post_date_gmt <= ? AND mfp.sale_status = 'warehouse' {$site_where}";
    
    $warehouse_count = intval($tododb->get_var($warehouse_sql, [$now_gmt]));

    if ($result['publish'] > 0) {
        $result['publish'] = max(0, $result['publish'] - $warehouse_count);
    }
    $result['warehouse'] = $warehouse_count;

    return $result;
}

/**
 * 获取商品价格信息
 */
function todo_get_product_price($product_id)
{
    global $tododb;
    $table_name = $tododb->prefix . 'model_fields_product';
    $row = $tododb->get_row("SELECT price, original_price, spec_type, spec_details FROM {$table_name} WHERE post_id = ?", [$product_id]);

    if ($row) {
        $price = floatval($row->price);
        $original_price = floatval($row->original_price);
        if ($price <= 0 && !empty($row->spec_details)) {
            $details = json_decode($row->spec_details, true);
            if (is_array($details)) {
                $prices = [];
                $crossed_prices = [];
                foreach ($details as $d) {
                    $p = isset($d['users_price']) ? floatval($d['users_price']) : (isset($d['price']) ? floatval($d['price']) : 0);
                    if ($p > 0) $prices[] = $p;
                    $cp = isset($d['crossed_price']) ? floatval($d['crossed_price']) : (isset($d['original_price']) ? floatval($d['original_price']) : 0);
                    if ($cp > 0) $crossed_prices[] = $cp;
                }
                if (!empty($prices)) $price = min($prices);
                if ($original_price <= 0 && !empty($crossed_prices)) $original_price = min($crossed_prices);
            }
        }
        return [
            'price' => $price,
            'original_price' => $original_price
        ];
    }

    $price = get_post_meta($product_id, 'todo_product_price', true);
    $original_price = get_post_meta($product_id, 'todo_product_original_price', true);

    return [
        'price' => floatval($price),
        'original_price' => floatval($original_price)
    ];
}

/**
 * 获取商品库存
 */
function todo_get_product_stock($product_id)
{
    global $tododb;
    $table_name = $tododb->prefix . 'model_fields_product';
    $row = $tododb->get_row("SELECT stock, virtual_sales, spec_details FROM {$table_name} WHERE post_id = ?", [$product_id]);

    if ($row) {
        $stock = intval($row->stock);
        if ($stock <= 0 && !empty($row->spec_details)) {
            $details = json_decode($row->spec_details, true);
            if (is_array($details)) {
                $total_spec_stock = 0;
                foreach ($details as $d) {
                    $stk = isset($d['stock_count']) ? intval($d['stock_count']) : (isset($d['stock']) ? intval($d['stock']) : 0);
                    if ($stk > 0) $total_spec_stock += $stk;
                }
                if ($total_spec_stock > 0) $stock = $total_spec_stock;
            }
        }
        return [
            'stock' => $stock,
            'virtual_sales' => intval($row->virtual_sales)
        ];
    }

    $stock = get_post_meta($product_id, 'todo_product_stock', true);
    $virtual_sales = get_post_meta($product_id, 'todo_product_virtual_sales', true);

    return [
        'stock' => intval($stock),
        'virtual_sales' => intval($virtual_sales)
    ];
}

/**
 * 获取商品销量
 */
function todo_get_product_sales($product_id)
{
    global $tododb;

    $order_items_table = $tododb->prefix . 'order_items';
    $orders_table = $tododb->prefix . 'orders';

    $sales = $tododb->get_var("
        SELECT COALESCE(SUM(oi.quantity), 0)
        FROM $order_items_table oi
        INNER JOIN $orders_table o ON oi.order_id = o.id
        WHERE oi.product_id = ? AND o.status IN ('paid', 'shipped', 'completed')
    ", [$product_id]);

    return intval($sales);
}

/**
 * 批量获取商品销量
 */
function todo_get_products_sales_batch($product_ids)
{
    global $tododb;

    if (empty($product_ids)) {
        return array();
    }

    $order_items_table = $tododb->prefix . 'order_items';
    $orders_table = $tododb->prefix . 'orders';

    $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
    $query = "
        SELECT oi.product_id, COALESCE(SUM(oi.quantity), 0) as total_sales
        FROM $order_items_table oi
        INNER JOIN $orders_table o ON oi.order_id = o.id
        WHERE oi.product_id IN ($placeholders) AND o.status IN ('paid', 'shipped', 'completed')
        GROUP BY oi.product_id
    ";

    $results = $tododb->get_results($query, $product_ids);

    $sales_map = array();
    foreach ($results as $row) {
        $sales_map[intval($row->product_id)] = intval($row->total_sales);
    }

    return $sales_map;
}

/**
 * 批量获取商品模型字段数据
 */
function todo_get_product_model_fields_batch($product_ids)
{
    global $tododb;

    if (empty($product_ids)) {
        return array();
    }

    $table_name = $tododb->prefix . 'model_fields_product';
    $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
    
    // Check if table exists
    $query = "SELECT post_id, price, original_price, stock, virtual_sales, spec_type, spec_details FROM {$table_name} WHERE post_id IN ($placeholders)";
    
    $results = $tododb->get_results($query, $product_ids);
    
    $data_map = array();
    if ($results) {
        foreach ($results as $row) {
            $price = floatval($row->price);
            $original_price = floatval($row->original_price);
            $stock = intval($row->stock);

            if (!empty($row->spec_details)) {
                $details = json_decode($row->spec_details, true);
                if (is_array($details)) {
                    if ($price <= 0) {
                        $prices = [];
                        $crossed_prices = [];
                        foreach ($details as $d) {
                            $p = isset($d['users_price']) ? floatval($d['users_price']) : (isset($d['price']) ? floatval($d['price']) : 0);
                            if ($p > 0) $prices[] = $p;
                            $cp = isset($d['crossed_price']) ? floatval($d['crossed_price']) : (isset($d['original_price']) ? floatval($d['original_price']) : 0);
                            if ($cp > 0) $crossed_prices[] = $cp;
                        }
                        if (!empty($prices)) $price = min($prices);
                        if ($original_price <= 0 && !empty($crossed_prices)) $original_price = min($crossed_prices);
                    }
                    if ($stock <= 0) {
                        $total_spec_stock = 0;
                        foreach ($details as $d) {
                            $stk = isset($d['stock_count']) ? intval($d['stock_count']) : (isset($d['stock']) ? intval($d['stock']) : 0);
                            if ($stk > 0) $total_spec_stock += $stk;
                        }
                        if ($total_spec_stock > 0) $stock = $total_spec_stock;
                    }
                }
            }

            $data_map[intval($row->post_id)] = [
                'price' => $price,
                'original_price' => $original_price,
                'stock' => $stock,
                'virtual_sales' => intval($row->virtual_sales)
            ];
        }
    }
    
    return $data_map;
}

/**
 * 批量获取栏目名称
 */
function todo_get_category_names_batch($category_ids)
{
    global $tododb;

    if (empty($category_ids)) {
        return array();
    }

    $placeholders = implode(',', array_fill(0, count($category_ids), '?'));
    $query = "SELECT term_id, name FROM {$tododb->prefix}terms WHERE term_id IN ($placeholders)";

    $results = $tododb->get_results($query, $category_ids);

    $names_map = array();
    foreach ($results as $row) {
        $names_map[intval($row->term_id)] = function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($row->name) : $row->name;
    }

    return $names_map;
}

/**
 * 格式化商品状态
 */
function todo_format_product_status($status)
{
    $statuses = [
        'publish' => ['label' => '已发布', 'class' => 'status-approved'],
        'draft' => ['label' => '草稿', 'class' => 'status-pending'],
        'pending' => ['label' => '待审', 'class' => 'status-pending'],
        'future' => ['label' => '定时发布', 'class' => 'status-active'],
        'trash' => ['label' => '回收站', 'class' => 'status-reject']
    ];

    if ($status === 'publish' && isset($GLOBALS['current_post_sale_status']) && $GLOBALS['current_post_sale_status'] === 'warehouse') {
        return ['label' => '仓库', 'class' => 'status-inactive'];
    }

    return $statuses[$status] ?? ['label' => $status, 'class' => 'status-inactive'];
}

/**
 * 获取栏目名称
 */
function todo_get_category_name($category_id)
{
    global $tododb;

    $category = $tododb->get_row("
        SELECT name FROM {$tododb->prefix}terms WHERE term_id = ?
    ", [$category_id]);

    return $category ? (function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($category->name) : $category->name) : '未分类';
}
?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">商品管理</h2>
        <div class="admin-page-actions">
            <a href="<?php echo todo_admin_url('index.php?page=post-new&model=product&from=todo-products'); ?>"
                class="button button-primary">添加商品</a>
        </div>
    </div>

    <?php if (isset($message)): ?>
        <div class="notice notice-<?php echo esc_attr($message_type); ?> is-dismissible">
            <p><?php echo esc_html($message); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <div class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-title-section">
                <h3 class="admin-card-title">商品列表</h3>
            </div>
            <div class="admin-card-actions">
                <form method="get" class="search-form" action="<?php echo todo_admin_url('index.php'); ?>">
                    <input type="hidden" name="page" value="todo-products">
                    <?php if (function_exists('todo_is_iframe_mode') && todo_is_iframe_mode()): ?>
                        <input type="hidden" name="format" value="iframe">
                    <?php endif; ?>
                    <input type="text" name="search" value="<?php echo esc_attr($search); ?>" placeholder="搜索商品名称...">
                    <button type="submit" class="button button-secondary">搜索</button>
                </form>
            </div>
        </div>

        <div class="admin-card-content">
            <div class="admin-filter-bar">
                <ul class="admin-filters">
                    <li>
                        <a href="<?php echo todo_admin_url('index.php?page=todo-products'); ?>"
                            class="<?php echo empty($status_filter) ? 'current' : ''; ?>">
                            全部 <span class="count">(<?php echo number_format($product_stats['total']); ?>)</span>
                        </a>
                    </li>
                    <li>
                        <a href="<?php echo todo_admin_url('index.php?page=todo-products&status=publish'); ?>"
                            class="<?php echo esc_attr($status_filter === 'publish' ? 'current' : ''); ?>">
                            已发布 <span
                                class="count">(<?php echo esc_html(number_format($product_stats['publish'])); ?>)</span>
                        </a>
                    </li>
                    <li>
                        <a href="<?php echo todo_admin_url('index.php?page=todo-products&status=pending'); ?>"
                            class="<?php echo esc_attr($status_filter === 'pending' ? 'current' : ''); ?>">
                            待审 <span
                                class="count">(<?php echo esc_html(number_format($product_stats['pending'])); ?>)</span>
                        </a>
                    </li>
                    <li>
                        <a href="<?php echo todo_admin_url('index.php?page=todo-products&status=scheduled'); ?>"
                            class="<?php echo esc_attr($status_filter === 'scheduled' ? 'current' : ''); ?>">
                            定时发布 <span
                                class="count">(<?php echo esc_html(number_format($product_stats['scheduled'])); ?>)</span>
                        </a>
                    </li>
                    <?php if ($product_stats['warehouse'] > 0): ?>
                    <li>
                        <a href="<?php echo todo_admin_url('index.php?page=todo-products&status=warehouse'); ?>"
                            class="<?php echo esc_attr($status_filter === 'warehouse' ? 'current' : ''); ?>">
                            仓库 <span
                                class="count">(<?php echo esc_html(number_format($product_stats['warehouse'])); ?>)</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <li>
                        <a href="<?php echo todo_admin_url('index.php?page=todo-products&status=draft'); ?>"
                            class="<?php echo esc_attr($status_filter === 'draft' ? 'current' : ''); ?>">
                            草稿 <span
                                class="count">(<?php echo esc_html(number_format($product_stats['draft'])); ?>)</span>
                        </a>
                    </li>
                    <li>
                        <a href="<?php echo todo_admin_url('index.php?page=todo-products&status=trash'); ?>"
                            class="<?php echo esc_attr($status_filter === 'trash' ? 'current' : ''); ?>">
                            回收站 <span
                                class="count">(<?php echo esc_html(number_format($product_stats['trash'])); ?>)</span>
                        </a>
                    </li>
                </ul>
            </div>

            <form class="products-sec" method="get" action="<?php echo todo_admin_url('index.php'); ?>">
                <input type="hidden" name="page" value="todo-products">
                <?php if (function_exists('todo_is_iframe_mode') && todo_is_iframe_mode()): ?>
                    <input type="hidden" name="format" value="iframe">
                <?php endif; ?>
                <?php if ($status_filter): ?>
                    <input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>">
                <?php endif; ?>
                <select name="sale_status" style="min-width: auto;">
                    <option value="">所有售卖</option>
                    <option value="on_sale" <?php selected($sale_status_filter, 'on_sale'); ?>>已上架</option>
                    <option value="warehouse" <?php selected($sale_status_filter, 'warehouse'); ?>>已下架/仓库</option>
                </select>
                <select name="category">
                    <option value="">全部栏目</option>
                    <?php foreach ($product_categories as $category): ?>
                        <option value="<?php echo esc_attr($category->id); ?>" <?php selected($category_filter, $category->id); ?>>
                            <?php echo str_repeat('— ', $category->depth); ?><?php echo esc_html(function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($category->name) : $category->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button button-secondary">筛选</button>
                <?php if (!empty($category_filter) || $search !== '' || !empty($sale_status_filter)): ?>
                    <a href="<?php echo todo_admin_url('index.php?page=todo-products' . ($status_filter ? '&status=' . $status_filter : '') . ((function_exists('todo_is_iframe_mode') && todo_is_iframe_mode()) ? '&format=iframe' : '')); ?>"
                        class="btn-normal m-l-05">重置</a>
                <?php endif; ?>
            </form>

            <form method="post" id="products-form" action="<?php echo todo_admin_url('index.php?page=todo-products'); ?>">
                <?php todo_nonce_field('todo_products_action'); ?>
                <input type="hidden" name="action" value="bulk_action">

                <div class="bulk-actions-bar">
                    <div class="bulk-actions">
                        <select name="bulk_action">
                            <option value="">批量操作</option>
                            <option value="publish"><?php echo $status_filter !== 'trash' ? '发布' : '恢复至发布'; ?></option>
                            <option value="draft">转为草稿</option>
                            <option value="pending">提交审核</option>
                            <option value="change_category">更换分类</option>
                            <?php if ($status_filter !== 'trash'): ?>
                                <option value="trash">移至回收站</option>
                            <?php endif; ?>
                        </select>
                        <button type="submit" class="button button-secondary" id="do-bulk-action">应用</button>
                    </div>
                </div>

                <!-- 商品列表 -->
                <table class="admin-table">
                    <thead>
                        <tr>
                            <td class="check-column">
                                <input type="checkbox" id="cb-select-all">
                            </td>
                            <th style="min-width: 300px;">商品信息</th>
                            <th style="min-width: 200px;">摘要</th>
                            <th style="min-width: 100px;">栏目</th>
                            <th style="min-width: 110px; text-align: right;">价格</th>
                            <th style="min-width: 90px;">库存</th>
                            <th style="min-width: 100px;">销量</th>
                            <th style="min-width: 82px;">状态</th>
                            <th style="width: 132px; min-width: 132px;">发布时间</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($products)): ?>
                            <tr class="empty">
                                <td colspan="9">
                                    <div class="no-items">
                                        <p>没有找到商品。</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($products as $product): ?>
                                <?php
                                // 使用批量查询的模型字段数据，避免N+1查询
                                if (isset($products_model_fields[$product->ID])) {
                                    $model_fields = $products_model_fields[$product->ID];
                                    $price_info = [
                                        'price' => $model_fields['price'],
                                        'original_price' => $model_fields['original_price']
                                    ];
                                    $stock_info = [
                                        'stock' => $model_fields['stock'],
                                        'virtual_sales' => $model_fields['virtual_sales']
                                    ];
                                } else {
                                    // 降级：如果没有找到模型数据，尝试使用元数据
                                    $price_info = todo_get_product_price($product->ID);
                                    $stock_info = todo_get_product_stock($product->ID);
                                }
                                // 使用批量查询的销量数据，避免N+1查询
                                $sales = isset($products_sales[$product->ID]) ? $products_sales[$product->ID] : 0;
                                $post_categories = array();
                                if (function_exists('todo_get_post_categories')) {
                                    $post_categories = todo_get_post_categories($product->ID);
                                } elseif (function_exists('get_the_category')) {
                                    $post_categories = get_the_category($product->ID);
                                }
                                $category_name = '未分类';
                                $current_cat_ids = [];
                                if (!empty($post_categories)) {
                                    $cat_names = array();
                                    foreach ($post_categories as $c) {
                                        if (isset($c->name)) {
                                            $cat_names[] = function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($c->name) : $c->name;
                                        }
                                        if (isset($c->term_id)) {
                                            $current_cat_ids[] = $c->term_id;
                                        }
                                    }
                                    if (!empty($cat_names)) {
                                        $category_name = implode(', ', $cat_names);
                                    }
                                }
                                $thumb_url = function_exists('get_the_post_thumbnail_url') ? get_the_post_thumbnail_url($product->ID, 'full') : '';
                                if (!empty($thumb_url) && function_exists('todo_media_url_to_absolute')) {
                                    $thumb_url = todo_media_url_to_absolute($thumb_url);
                                }
                                ?>
                                <tr>
                                    <th>
                                        <input type="checkbox" name="product_ids[]"
                                            value="<?php echo esc_attr($product->ID); ?>">
                                    </th>
                                    <td>
                                        <div class="product-info">
                                            <?php if (!empty($thumb_url)): ?>
                                                <div class="product-thumbnail">
                                                    <img src="<?php echo esc_url($thumb_url); ?>" alt="<?php echo esc_attr($product->post_title); ?>" style="width:50px;height:50px;object-fit:cover;border-radius:4px;">
                                                </div>
                                            <?php endif; ?>
                                            <div class="product-details">
                                                <strong>
                                                    <a href="<?php echo todo_admin_url('index.php?page=post-edit&id=' . $product->ID . '&from=todo-products'); ?>">
                                                        <?php echo esc_html($product->post_title); ?>
                                                    </a>
                                                </strong>
                                                <div class="row-actions">
                                                    <a href="javascript:void(0);" class="quick-move" data-product-id="<?php echo esc_attr($product->ID); ?>" data-category-ids="<?php echo esc_attr(implode(',', $current_cat_ids)); ?>">移动</a> |
                                                    <a href="<?php echo todo_admin_url('index.php?page=post-edit&id=' . $product->ID . '&from=todo-products'); ?>">编辑</a> |<a href="<?php echo get_permalink($product->ID); ?>" target="_blank">查看</a> 
                                                    <?php if ($product->post_status !== 'trash'): ?>
                                                        | <a href="javascript:void(0);" class="quick-status"
                                                            data-product-id="<?php echo esc_attr($product->ID); ?>"
                                                            data-current-status="<?php echo esc_attr(($product->sale_status ?? '') === 'warehouse' ? 'warehouse' : 'online'); ?>">上/下架</a>
                                                    <?php endif; ?>
                                                    ID: <?php echo esc_html($product->ID); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <small class="tb-description">
                                        <?php if (!empty($product->post_excerpt)): ?>
                                            <?php echo esc_html(truncate_string($product->post_excerpt, 50, '...')); ?>
                                        <?php endif; ?>
                                        </small>
                                    </td>
                                    <td><span class="tb-meta"><?php echo esc_html($category_name); ?></span></td>
                                    <td style="text-align: right;">
                                        <strong><?php echo todo_format_admin_price($price_info['price']); ?></strong>
                                        <?php if ($price_info['original_price'] > $price_info['price']): ?>
                                            <span class="tb-meta original-price" style="opacity: 0.7;display: block;"><?php echo todo_format_admin_price($price_info['original_price']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($stock_info['stock'] > 0): ?>
                                            <?php echo esc_html($stock_info['stock']); ?>
                                        <?php else: ?>
                                            <span class="stock-out">缺货</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="tb-meta"><?php echo esc_html($sales); ?></span>
                                        <?php if ($stock_info['virtual_sales'] > 0): ?>
                                            <small style="opacity: 0.7; font-size: 12px; display: block;">虚拟+<?php echo esc_html($stock_info['virtual_sales']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="product-status-stack" style="display: flex; flex-direction: column; gap: 4px;">
                                            <?php 
                                            $post_states = [];
                                            if ($product->post_status === 'draft') $post_states[] = ['label' => '草稿', 'class' => 'status-inactive'];
                                            if ($product->post_status === 'pending') $post_states[] = ['label' => '待审核', 'class' => 'status-pending'];
                                            if ($product->post_status === 'future') $post_states[] = ['label' => '定时发布', 'class' => 'status-spam'];
                                            if (($product->sale_status ?? '') === 'warehouse') $post_states[] = ['label' => '仓库', 'class' => 'status-inactive'];
                                            if ($product->post_status === 'publish' && ($product->sale_status ?? '') !== 'warehouse') $post_states[] = ['label' => '已上架', 'class' => 'status-approved'];

                                            foreach ($post_states as $state): ?>
                                                <span class="<?php echo esc_attr($state['class']); ?>" style="display: inline-block; width: fit-content; margin: 0;">
                                                    <?php echo esc_html($state['label']); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="tb-meta"><?php echo date('Y-m-d H:i', strtotime($product->post_date)); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>

            <!-- 分页 -->
            <?php if ($total_pages > 1): ?>
                <?php
                $pagination_add_args = array();
                if ($category_filter) {
                    $pagination_add_args['category'] = $category_filter;
                }
                if ($status_filter !== '') {
                    $pagination_add_args['status'] = $status_filter;
                }
                if ($search !== '') {
                    $pagination_add_args['search'] = $search;
                }

                echo todo_render_pagination(array(
                    'total_items' => $total_products,
                    'per_page' => $per_page,
                    'current_page' => $current_page,
                    'base_url' => todo_admin_url('index.php?page=todo-products'),
                    'add_args' => $pagination_add_args,
                    'show_count' => true,
                ));
                ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<template id="quick-status-modal-template" data-size="sm">
    <div data-slot="title">快速状态管理</div>
    <div data-slot="body">
        <form id="quick-status-form" method="post" action="<?php echo todo_admin_url('index.php?page=todo-products'); ?>">
            <?php todo_nonce_field('todo_products_action'); ?>
            <?php if (function_exists('todo_is_iframe_mode') && todo_is_iframe_mode()): ?>
                <input type="hidden" name="format" value="iframe">
            <?php endif; ?>
            <input type="hidden" name="action" value="update_product_status">
            <input type="hidden" name="product_id" id="quick-product-id">

            <table class="form-table">
                <tr>
                    <th><label for="quick-status">商品状态</label></th>
                    <td>
                        <select name="status" id="quick-status" required>
                            <option value="online">上架发售</option>
                            <option value="warehouse">下架(移至仓库)</option>
                        </select>
                        <p class="description">0库存商品建议下架处理。</p>
                    </td>
                </tr>
            </table>
        </form>
    </div>
    <div data-slot="footer">
        <button type="button" class="button button-secondary todo-modal-cancel">取消</button>
        <button type="submit" form="quick-status-form" class="button button-primary">确认更新</button>
    </div>
</template>

<template id="change-category-modal-template" data-size="md">
    <div data-slot="title">更换分类</div>
    <div data-slot="body">
        <form id="change-category-form" method="post" action="<?php echo todo_admin_url('index.php?page=todo-products'); ?>">
            <?php todo_nonce_field('todo_products_action'); ?>
            <?php if (function_exists('todo_is_iframe_mode') && todo_is_iframe_mode()): ?>
                <input type="hidden" name="format" value="iframe">
            <?php endif; ?>
            <input type="hidden" name="action" value="bulk_change_category">
            <input type="hidden" name="product_ids" id="change-category-product-ids">
            
            <p>已选取 <strong id="change-category-count" style="color: #2271b1; font-size: 16px;">0</strong> 个商品，请选择它们的新归属分类：</p>
            <div class="hierarchical-categories-checklist" style="margin-top: 5px;">
                <?php
                $render_tree = function($categories, $parent_id = 0, $level = 0) use (&$render_tree) {
                    $has_children = false;
                    foreach ($categories as $cat) {
                        // 由于 $categories 已经是打平后的树结构且包含 depth，这里我们可以直接遍历或者按原逻辑递归
                        // 为了保持 checklist 的 DOM 层级（ul/li），我们依然按 parent_id 过滤
                        if ($cat->parent == $parent_id) {
                            if (!$has_children) {
                                echo '<ul class="category-tree' . ($level == 0 ? ' root' : '') . '" style="' . ($level > 0 ? 'margin-left: 20px;' : 'list-style: none; margin: 0; padding: 0;') . '">';
                                $has_children = true;
                            }
                            echo '<li>';
                            $display_cat_name = function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($cat->name) : $cat->name;
                            echo '<label style="display: flex; align-items: center;"><input type="checkbox" name="category_ids[]" value="' . esc_attr($cat->id) . '" style="margin-right: 6px;"> ' . esc_html($display_cat_name) . '</label>';
                            $render_tree($categories, $cat->id, $level + 1);
                            echo '</li>';
                        }
                    }
                    if ($has_children) {
                        echo '</ul>';
                    }
                };
                $render_tree($product_categories);
                ?>
            </div>
            <p class="description">提示：如不勾选任何分类，将清空所选商品的分类归属。</p>
        </form>
    </div>
    <div data-slot="footer">
        <button type="button" class="button button-secondary todo-modal-cancel">取消</button>
        <button type="submit" form="change-category-form" class="button button-primary">确认更换</button>
    </div>
</template>

<style>
    .products-sec {
        float: right;
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: center;
    }

    .mbody .products-sec {
        float: unset !important;
        margin-bottom: 10px;
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
        vertical-align: middle;
        font-size: 12px;
        overflow: hidden;
        display: block;
    }

    .product-details {
        flex: 1;
    }

    .original-price {
        text-decoration: line-through;
    }

    .stock-out {
        color: #dc3232;
        font-size: 14px;
    }
</style>

<script type="text/javascript">
    jQuery(document).ready(function ($) {
        // 全选/取消全选
        $('#cb-select-all').on('change', function () {
            $('input[name="product_ids[]"]').prop('checked', this.checked);
        });

        function bindModalAjaxSubmit(modalInstance) {
            var $ctx = $(modalInstance.element);
            $ctx.find('form').on('submit', function(e) {
                e.preventDefault();
                var $form = $(this);
                var $btn = $form.find('button[type="submit"]');
                var btnText = $btn.text();
                
                if ($form.data('submitting')) return;
                $form.data('submitting', true);
                $btn.prop('disabled', true).text('处理中...');
                
                $.ajax({
                    url: $form.attr('action') || window.location.href,
                    type: 'POST',
                    data: $form.serialize(),
                    dataType: 'json',
                    success: function(response) {
                        if (response && response.success) {
                            TodoCMS.Utils.showMessage(response.message || '操作成功', 'success');
                            modalInstance.close();
                            window.location.reload();
                        } else {
                            var msg = (response && response.message) ? response.message : '操作失败';
                            TodoModal.error(msg);
                            $form.data('submitting', false);
                            $btn.prop('disabled', false).text(btnText);
                        }
                    },
                    error: function() {
                        TodoModal.error('网络错误');
                        $form.data('submitting', false);
                        $btn.prop('disabled', false).text(btnText);
                    }
                });
            });
        }

        // 快速状态更新
        $('.quick-status').on('click', function (e) {
            e.preventDefault();
            var productId = $(this).data('product-id');
            var currentStatus = $(this).data('current-status');
            const modal = new TodoModal({
                templateId: 'quick-status-modal-template',
                destroyOnClose: true,
                onOpen: function(modalInstance) {
                    var $ctx = $(modalInstance.element);
                    $ctx.find('#quick-product-id').val(productId);
                    $ctx.find('#quick-status').val(currentStatus);
                    bindModalAjaxSubmit(modalInstance);
                }
            });
            modal.open();
        });

        // 单个商品快速移动
        $('.quick-move').on('click', function (e) {
            e.preventDefault();
            var productId = $(this).data('product-id');
            var catIds = String($(this).data('category-ids')).split(',').filter(Boolean);
            
            const modal = new TodoModal({
                templateId: 'change-category-modal-template',
                destroyOnClose: true,
                onOpen: function(modalInstance) {
                    var $ctx = $(modalInstance.element);
                    $ctx.find('#change-category-product-ids').val(productId);
                    $ctx.find('#change-category-count').text('1');
                    
                    // 预选当前分类
                    catIds.forEach(function(catId) {
                        $ctx.find('input[name="category_ids[]"][value="' + catId + '"]').prop('checked', true);
                    });
                    bindModalAjaxSubmit(modalInstance);
                }
            });
            
            modal.open();
        });

        // 批量操作确认
        $('#products-form').on('submit', function (e) {
            var action = $('select[name="bulk_action"]').val();
            var checkedItems = $('input[name="product_ids[]"]:checked').length;

            // Check if this submission is triggered by the bulk action button
            // If the submit event was triggered programmatically (e.g. form.submit()), e.originalEvent will be undefined in some browsers, 
            // but we need to be careful not to create an infinite loop.
            // However, the logic below prevents default, shows modal, then calls form.submit().
            // If form.submit() triggers this handler again, we need a way to bypass.
            // Usually form.submit() called on the DOM element bypasses jQuery's on('submit') handler.
            // Let's verify if 'this' is the raw DOM element. Yes.

            if (!action) {
                e.preventDefault();
                TodoCMS.Utils.showMessage('请选择批量操作', 'warning');
                return false;
            }

            if (checkedItems === 0) {
                e.preventDefault();
                TodoCMS.Utils.showMessage('请选择要操作的商品', 'warning');
                return false;
            }

            if (action === 'change_category') {
                e.preventDefault();
                var productIds = [];
                $('input[name="product_ids[]"]:checked').each(function() {
                    productIds.push($(this).val());
                });

                const modal = new TodoModal({
                    templateId: 'change-category-modal-template',
                    destroyOnClose: true,
                    onOpen: function(modalInstance) {
                        var $ctx = $(modalInstance.element);
                        $ctx.find('#change-category-product-ids').val(productIds.join(','));
                        $ctx.find('#change-category-count').text(checkedItems);
                        bindModalAjaxSubmit(modalInstance);
                    }
                });
                
                modal.open();
                return false;
            }

            var actionText = $('select[name="bulk_action"] option:selected').text();
            var form = this;

            e.preventDefault();

            TodoModal.confirm({
                title: '确认操作',
                message: '确定要对 ' + checkedItems + ' 个商品执行"' + actionText + '"操作吗？',
                onConfirm: function () {
                    form.submit();
                }
            });
        });
    });
</script>
