<?php
/**
 * TodoCMS 商城菜单配置
 * 
 * 定义商城管理模块的菜单结构
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问（但允许AJAX请求）
if (!defined('ABSPATH') || (!defined('TODO_ADMIN') && !defined('DOING_AJAX'))) {
    exit('Direct access denied');
}

/**
 * 添加商城管理菜单
 */
function todo_add_shop_menu()
{
    // 主菜单 - 商城管理
    add_menu_page(
        '商城管理',
        '商城管理',
        'manage_options',
        'todo-shop',
        'todo_shop_dashboard_page',
        30
    );

    // 子菜单 - 商城看板
    add_submenu_page(
        'todo-shop',
        '商城看板',
        '商城看板',
        'manage_options',
        'todo-shop',
        'todo_shop_dashboard_page'
    );

    // 子菜单 - 订单管理
    add_submenu_page(
        'todo-shop',
        '订单管理',
        '订单管理',
        'manage_options',
        'todo-orders',
        'todo_orders_page'
    );

    // 隐藏的订单编辑页面
    add_submenu_page(
        null, // 不显示在菜单中
        '编辑订单',
        '编辑订单',
        'manage_options',
        'todo-order-edit',
        'todo_order_edit_page'
    );

    // 隐藏的订单统计页面
    add_submenu_page(
        null, // 不显示在菜单中
        '订单统计',
        '订单统计',
        'manage_options',
        'todo-order-stats',
        'todo_order_stats_page'
    );

    // 子菜单 - 商品管理
    add_submenu_page(
        'todo-shop',
        '商品管理',
        '商品管理',
        'manage_options',
        'todo-products',
        'todo_products_page'
    );

    // 子菜单 - 评价管理
    add_submenu_page(
        'todo-shop',
        '评价管理',
        '评价管理',
        'manage_options',
        'todo-reviews',
        'todo_reviews_page'
    );

    // 子菜单 - 优惠券管理
    add_submenu_page(
        'todo-shop',
        '优惠券管理',
        '优惠券',
        'manage_options',
        'todo-coupons',
        'todo_coupons_page'
    );

    // 子菜单 - 用户余额
    add_submenu_page(
        'todo-shop',
        '用户余额',
        '用户余额',
        'manage_options',
        'todo-balance',
        'todo_balance_page'
    );

    // 隐藏的礼品卡管理页面
    add_submenu_page(
        null,
        '礼品卡管理',
        '礼品卡',
        'manage_options',
        'todo-gift-cards',
        'todo_gift_cards_page'
    );

    // 子菜单 - 商城统计
    add_submenu_page(
        'todo-shop',
        '商城统计',
        '统计报表',
        'manage_options',
        'todo-shop-stats',
        'todo_shop_stats_page'
    );

    // 子菜单 - 快递公司
    add_submenu_page(
        'todo-shop',
        '快递公司管理',
        '快递公司',
        'manage_options',
        'todo-express',
        'todo_express_page'
    );

    // 子菜单 - 支付方式
    add_submenu_page(
        'todo-shop',
        '支付方式管理',
        '支付方式',
        'manage_options',
        'todo-payments',
        'todo_payments_page'
    );

    // 子菜单 - 服务设置
    add_submenu_page(
        'todo-shop',
        '服务设置',
        '服务设置',
        'manage_options',
        'todo-service-settings',
        'todo_service_settings_page'
    );
}

/**
 * 商城看板页面
 */
function todo_shop_dashboard_page()
{
    // 商城看板：展示今日/本月概览、待处理事项、最新订单等
    include ADMIN_DIR . '/pages/shop/dashboard.php';
}

/**
 * 订单管理页面
 */
function todo_orders_page()
{
    include ADMIN_DIR . '/pages/shop/orders.php';
}

/**
 * 订单编辑页面
 */
function todo_order_edit_page()
{
    include ADMIN_DIR . '/pages/shop/order-edit.php';
}

/**
 * 订单统计页面
 */
function todo_order_stats_page()
{
    include ADMIN_DIR . '/pages/order/order-stats.php';
}

/**
 * 商品管理页面
 */
function todo_products_page()
{
    // 检查是否有产品模型的栏目
    global $tododb;

    // 查询使用产品模型的栏目
    $product_categories = $tododb->get_results(
        "SELECT t.term_id, t.name, t.slug, tt.description, tt.parent, tt.count 
         FROM {$tododb->prefix}terms t 
         INNER JOIN {$tododb->prefix}term_taxonomy tt ON t.term_id = tt.term_id 
         INNER JOIN {$tododb->prefix}term_models tm ON t.term_id = tm.term_id 
         INNER JOIN {$tododb->prefix}models m ON tm.model_id = m.model_id 
         WHERE tt.taxonomy = 'category' AND m.model_type = 'product' 
         ORDER BY tt.parent ASC, t.name ASC"
    );

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

    // 如果有产品栏目，显示商品列表
    include ADMIN_DIR . '/pages/shop/products.php';
}

/**
 * 评价管理页面
 */
function todo_reviews_page()
{
    include ADMIN_DIR . '/pages/shop/reviews.php';
}

/**
 * 优惠券管理页面
 */
function todo_coupons_page()
{
    include ADMIN_DIR . '/pages/shop/coupons.php';
}

/**
 * 用户余额页面
 */
function todo_balance_page()
{
    include ADMIN_DIR . '/pages/shop/balance.php';
}

/**
 * 礼品卡管理页面
 */
function todo_gift_cards_page()
{
    include ADMIN_DIR . '/pages/shop/gift-cards.php';
}

/**
 * 商城统计页面
 */
function todo_shop_stats_page()
{
    include ADMIN_DIR . '/pages/shop/stats.php';
}

/**
 * 快递公司管理页面
 */
function todo_express_page()
{
    include ADMIN_DIR . '/pages/shop/express.php';
}

/**
 * 支付方式管理页面
 */
function todo_payments_page()
{
    include ADMIN_DIR . '/pages/shop/payments.php';
}

/**
 * 获取商城菜单高亮状态
 */
function todo_shop_menu_highlight()
{
    global $parent_file, $submenu_file;

    $shop_pages = [
        'todo-shop',
        'todo-orders',
        'todo-products',
        'todo-reviews',
        'todo-coupons',
        'todo-balance',
        'todo-shop-stats',
        'todo-express',
        'todo-payments'
    ];

    if (isset($_GET['page']) && in_array($_GET['page'], $shop_pages)) {
        $parent_file = 'todo-shop';
    }
}

/**
 * 添加商城菜单样式
 */
function todo_shop_menu_styles()
{
    ?>
    <style>
        .todo-shop-badge {
            background: #d63638;
            color: white;
            border-radius: 10px;
            padding: 2px 6px;
            font-size: 11px;
            margin-left: 5px;
        }

        .todo-shop-submenu-badge {
            background: #f56e28;
            color: white;
            border-radius: 8px;
            padding: 1px 4px;
            font-size: 10px;
            margin-left: 5px;
        }
    </style>
    <?php
}

/**
 * 获取待处理订单数量
 */
function todo_get_pending_orders_count()
{
    global $tododb;
    $orders_table = $tododb->prefix . 'orders';

    $count = $tododb->get_var(
        "SELECT COUNT(*) FROM $orders_table WHERE status IN ('pending', 'paid')"
    );

    return intval($count);
}

/**
 * 获取待审核评价数量
 */
function todo_get_pending_reviews_count()
{
    global $tododb;
    // 表不存在时安全返回0，避免未安装数据表导致的异常
    if (!isset($tododb) || !method_exists($tododb, 'table_exists') || !$tododb->table_exists('order_reviews')) {
        return 0;
    }
    $reviews_table = $tododb->prefix . 'order_reviews';

    $count = $tododb->get_var(
        "SELECT COUNT(*) FROM $reviews_table WHERE status = 'pending'"
    );

    return intval($count);
}

/**
 * 在菜单标题中添加数量徽章
 */
function todo_add_menu_badges($menu)
{
    // 获取待处理数量
    $pending_orders = todo_get_pending_orders_count();
    $pending_reviews = todo_get_pending_reviews_count();

    // 为菜单项添加徽章
    foreach ($menu as $key => $item) {
        if (isset($item[2])) {
            switch ($item[2]) {
                case 'todo-orders':
                    if ($pending_orders > 0) {
                        $menu[$key][0] .= ' <span class="todo-shop-submenu-badge">' . $pending_orders . '</span>';
                    }
                    break;

                case 'todo-reviews':
                    if ($pending_reviews > 0) {
                        $menu[$key][0] .= ' <span class="todo-shop-submenu-badge">' . $pending_reviews . '</span>';
                    }
                    break;
            }
        }
    }

    return $menu;
}

/**
 * 检查商城功能是否启用
 */
function todo_is_shop_enabled()
{
    return get_option('todo_shop_enabled', false);
}

/**
 * 获取商城状态信息
 */
function todo_get_shop_status()
{
    global $tododb;

    // 检查必要的数据表是否存在
    $tables_exist = todo_check_shop_tables_exist();

    // 检查是否有产品栏目
    $product_categories = $tododb->get_var(
        "SELECT COUNT(*) FROM {$tododb->prefix}terms t 
         INNER JOIN {$tododb->prefix}term_taxonomy tt ON t.term_id = tt.term_id 
         INNER JOIN {$tododb->prefix}term_models tm ON t.term_id = tm.term_id 
         INNER JOIN {$tododb->prefix}models m ON tm.model_id = m.model_id 
         WHERE tt.taxonomy = 'category' AND m.model_type = 'product'"
    );

    // 检查支付方式配置
    $payment_methods = get_option('todo_payment_methods', []);
    $payment_configured = !empty($payment_methods);

    return [
        'tables_exist' => $tables_exist,
        'has_product_categories' => $product_categories > 0,
        'payment_configured' => $payment_configured,
        'shop_enabled' => todo_is_shop_enabled()
    ];
}

/**
 * 检查商城数据表是否存在
 */
function todo_check_shop_tables_exist()
{
    global $tododb;

    $required_tables = [
        $tododb->prefix . 'orders',
        $tododb->prefix . 'order_items',
        $tododb->prefix . 'cart',
        $tododb->prefix . 'order_reviews',
        $tododb->prefix . 'express_companies',
        $tododb->prefix . 'order_logistics',
        $tododb->prefix . 'member_balance',
        $tododb->prefix . 'coupons',
        $tododb->prefix . 'coupon_usage'
    ];

    foreach ($required_tables as $table) {
        $table_exists = $tododb->get_var("SHOW TABLES LIKE '$table'");
        if (!$table_exists) {
            return false;
        }
    }

    return true;
}

/**
 * 显示商城状态通知
 */
function todo_shop_status_notice()
{
    $status = todo_get_shop_status();

    if (!$status['tables_exist']) {
        echo '<div class="notice notice-error">';
        echo '<p><strong>商城功能未完全安装：</strong>数据表缺失。请前往 <a href="' . todo_admin_url('index.php?page=todo-install') . '">系统安装</a> 页面完成安装。</p>';
        echo '</div>';
        return;
    }

    if (!$status['has_product_categories']) {
        echo '<div class="notice notice-warning">';
        echo '<p><strong>提示：</strong>还没有创建产品栏目。请在 <a href="' . todo_admin_url('index.php?page=categories') . '">栏目管理</a> 中创建使用产品模型的栏目。</p>';
        echo '</div>';
    }

    if (!$status['payment_configured']) {
        echo '<div class="notice notice-warning">';
        echo '<p><strong>提示：</strong>还没有配置支付方式。请在 <a href="' . todo_admin_url('index.php?page=todo-payments') . '">支付方式</a> 中进行配置。</p>';
        echo '</div>';
    }
}

// 注册钩子
add_action('admin_menu', 'todo_add_shop_menu');
add_action('admin_head', 'todo_shop_menu_styles');
add_action('parent_file', 'todo_shop_menu_highlight');

// 在商城相关页面显示状态通知
add_action('admin_notices', function () {
    $screen = get_current_screen();
    if ($screen && strpos($screen->id, 'todo-shop') !== false) {
        todo_shop_status_notice();
    }
});

// 为子菜单添加徽章
add_filter('submenu_file', function ($submenu_file) {
    global $submenu;

    if (isset($submenu['todo-shop'])) {
        $submenu['todo-shop'] = todo_add_menu_badges($submenu['todo-shop']);
    }

    return $submenu_file;
});

/**
 * 将商城模块注入 header.php 左侧菜单
 * 说明：header.php 使用 apply_filters('admin_menu', $admin_menu_default) 渲染侧栏，本过滤器把商城菜单合并进去。
 */
function todo_extend_admin_menu_with_shop($admin_menu = null)
{
    if (!is_array($admin_menu)) {
        return $admin_menu;
    }

    // 定义商城子菜单（顺序即为渲染顺序）
    $shop_submenu = array(
        'todo-shop' => array(
            'title' => '商城看板',
            'url' => 'index.php?page=todo-shop',
            'capability' => 'view_stats'
        ),
        'todo-orders' => array(
            'title' => '订单管理',
            'url' => 'index.php?page=todo-orders',
            'capability' => 'manage_options'
        ),
        'todo-products' => array(
            'title' => '商品管理',
            'url' => 'index.php?page=todo-products',
            'capability' => 'manage_options'
        ),
        'todo-reviews' => array(
            'title' => '评价管理',
            'url' => 'index.php?page=todo-reviews',
            'capability' => 'manage_options'
        ),
        'todo-coupons' => array(
            'title' => '优惠券',
            'url' => 'index.php?page=todo-coupons',
            'capability' => 'manage_options'
        ),
        'todo-balance' => array(
            'title' => '用户余额',
            'url' => 'index.php?page=todo-balance',
            'capability' => 'manage_options'
        ),
        'todo-shop-stats' => array(
            'title' => '统计报表',
            'url' => 'index.php?page=todo-shop-stats',
            'capability' => 'view_stats'
        ),
        'todo-express' => array(
            'title' => '快递公司',
            'url' => 'index.php?page=todo-express',
            'capability' => 'manage_options'
        ),
        'todo-payments' => array(
            'title' => '支付方式',
            'url' => 'index.php?page=todo-payments',
            'capability' => 'manage_options'
        ),
        'todo-service-settings' => array(
            'title' => '服务设置',
            'url' => 'index.php?page=todo-service-settings',
            'capability' => 'manage_options'
        )
    );

    // 若已存在 'shop'，先移除以便重排
    if (isset($admin_menu['shop'])) {
        unset($admin_menu['shop']);
    }

    $new_menu = array();
    $inserted = false;
    $orig_menu = $admin_menu;

    foreach ($orig_menu as $slug => $item) {
        if ($slug === 'shop') {
            // 避免重复添加
            continue;
        }
        $new_menu[$slug] = $item;
    }

    // 在“评论”后插入；如无“评论”，则追加到末尾
    if (!$inserted) {
        $temp_menu = array();
        foreach ($new_menu as $slug => $item) {
            $temp_menu[$slug] = $item;
            if ($slug === 'comments' && !$inserted) {
                $temp_menu['shop'] = array(
                    'title' => '商城',
                    'icon' => 'order',
                    'url' => 'index.php?page=todo-shop',
                    'capability' => 'manage_options',
                    'submenu' => $shop_submenu
                );
                $inserted = true;
            }
        }
        if ($inserted) {
            $new_menu = $temp_menu;
        } else {
            // 仍未插入，则放到末尾
            $new_menu['shop'] = array(
                'title' => '商城',
                'icon' => 'order',
                'url' => 'index.php?page=todo-shop',
                'capability' => 'manage_options',
                'submenu' => $shop_submenu
            );
        }
    }

    return $new_menu;
}
// 使用高优先级，确保在其他过滤器之后运行
add_filter('admin_menu', 'todo_extend_admin_menu_with_shop', 999);