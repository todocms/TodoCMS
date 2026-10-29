<?php
/**
 * TodoCMS 商城看板页面
 * 
 * 商城管理首页，显示关键数据和快捷操作
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('TODO_ADMIN')) {
    exit('Direct access denied');
}

// 权限检查
if (!current_user_can('view_stats') && !current_user_can('manage_options')) {
    todo_die(__('You do not have sufficient permissions to access this page.'));
}

global $tododb;

// 获取今日数据
$today_stats = todo_get_today_stats();

// 获取本月数据
$month_stats = todo_get_month_stats();

// 获取待处理数据
$pending_stats = todo_get_pending_stats();

// 获取最近订单
$recent_orders = todo_get_recent_orders(10);

// 获取热销商品
$hot_products = todo_get_hot_products(5);

// 获取最新评价
$recent_reviews = todo_get_recent_reviews(5);

/**
 * 获取今日统计数据
 */
function todo_get_today_stats() {
    global $tododb;
    
    $orders_table = $tododb->prefix . 'orders';
    $users_table = $tododb->prefix . 'users';
    $today = date('Y-m-d');
    
    // 今日订单数
    $sql = "SELECT COUNT(*) FROM $orders_table WHERE DATE(created_at) = ?";
    $today_orders = $tododb->get_var($sql, [$today]);
    
    // 今日销售额
    $sql = "SELECT COALESCE(SUM(total_amount), 0) FROM $orders_table WHERE DATE(created_at) = ? AND status IN ('paid', 'shipped', 'completed')";
    $today_sales = $tododb->get_var($sql, [$today]);
    
    // 今日新用户 (隐身隔离)
    $all_users_from = "FROM $users_table ";
    $where_users = "WHERE DATE(user_registered) = ?";
    if (!todo_is_super_admin()) {
        $all_users_from .= " LEFT JOIN {$tododb->prefix}user_meta um_dev ON {$users_table}.ID = um_dev.user_id AND um_dev.meta_key = 'is_developer' ";
        $where_users .= " AND (um_dev.meta_value IS NULL OR um_dev.meta_value != '1')";
    }
    $sql = "SELECT COUNT(*) $all_users_from $where_users";
    $today_users = $tododb->get_var($sql, [$today]);
    
    // 今日支付订单数
    $sql = "SELECT COUNT(*) FROM $orders_table WHERE DATE(created_at) = ? AND status IN ('paid', 'shipped', 'completed')";
    $today_paid_orders = $tododb->get_var($sql, [$today]);
    
    return [
        'orders' => intval($today_orders),
        'sales' => floatval($today_sales),
        'users' => intval($today_users),
        'paid_orders' => intval($today_paid_orders)
    ];
}

/**
 * 获取本月统计数据
 */
function todo_get_month_stats() {
    global $tododb;
    
    $orders_table = $tododb->prefix . 'orders';
    $users_table = $tododb->prefix . 'users';
    $month_start = date('Y-m-01');
    $month_end = date('Y-m-t');
    
    // 本月订单数
    $sql = "SELECT COUNT(*) FROM $orders_table WHERE DATE(created_at) BETWEEN ? AND ?";
    $month_orders = $tododb->get_var($sql, [$month_start, $month_end]);
    
    // 本月销售额
    $sql = "SELECT SUM(total_amount) FROM $orders_table WHERE DATE(created_at) BETWEEN ? AND ? AND status = ?";
    $month_sales = $tododb->get_var($sql, [$month_start, $month_end, 'completed']);
    
    // 本月新用户 (隐身隔离)
    $all_users_from = "FROM $users_table ";
    $where_users = "WHERE DATE(user_registered) BETWEEN ? AND ?";
    if (!todo_is_super_admin()) {
        $all_users_from .= " LEFT JOIN {$tododb->prefix}user_meta um_dev ON {$users_table}.ID = um_dev.user_id AND um_dev.meta_key = 'is_developer' ";
        $where_users .= " AND (um_dev.meta_value IS NULL OR um_dev.meta_value != '1')";
    }
    $sql = "SELECT COUNT(*) $all_users_from $where_users";
    $month_users = $tododb->get_var($sql, [$month_start, $month_end]);
    
    // 本月支付订单数
    $sql = "SELECT COUNT(*) FROM $orders_table WHERE DATE(created_at) BETWEEN ? AND ? AND status IN ('paid', 'shipped', 'completed')";
    $month_paid_orders = $tododb->get_var($sql, [$month_start, $month_end]);
    
    // 本月访问量
    $sql = "SELECT SUM(config_value) FROM {$tododb->prefix}system_config WHERE config_key = ?";
    $month_views = $tododb->get_var($sql, ['page_views_month']);
    
    return array(
        'orders' => (int) $month_orders,
        'sales' => (float) $month_sales,
        'users' => (int) $month_users,
        'paid_orders' => (int) $month_paid_orders,
        'views' => (int) $month_views
    );
}

/**
 * 获取待处理统计数据
 */
function todo_get_pending_stats() {
    global $tododb;
    
    $orders_table = $tododb->prefix . 'orders';
    $reviews_table = $tododb->prefix . 'order_reviews';
    
    // 待付款订单
    $pending_payment = $tododb->get_var("
        SELECT COUNT(*) FROM $orders_table WHERE status = 'pending'
    ");
    
    // 待发货订单
    $pending_shipment = $tododb->get_var("
        SELECT COUNT(*) FROM $orders_table WHERE status = 'paid'
    ");
    
    // 待审核评价（表不存在时安全返回0，避免未安装数据表导致异常）
    if (!isset($tododb) || !method_exists($tododb, 'table_exists') || !$tododb->table_exists('order_reviews')) {
        $pending_reviews = 0;
    } else {
        $pending_reviews = $tododb->get_var("
            SELECT COUNT(*) FROM $reviews_table WHERE status = 'pending'
        ");
    }
    
    // 库存不足商品
    $posts_table = $tododb->prefix . 'posts';
    $custom_fields_table = $tododb->prefix . 'post_custom_fields';
    $low_stock_products = $tododb->get_var("
        SELECT COUNT(DISTINCT p.ID) 
        FROM $posts_table p
        INNER JOIN $custom_fields_table pm1 ON p.ID = pm1.post_id AND pm1.field_key = 'todo_model_type' AND pm1.field_value = 'product'
        INNER JOIN $custom_fields_table pm2 ON p.ID = pm2.post_id AND pm2.field_key = 'todo_product_stock'
        WHERE p.post_status = 'publish' AND CAST(pm2.field_value AS UNSIGNED) <= 10
    ");
    
    return [
        'pending_payment' => intval($pending_payment),
        'pending_shipment' => intval($pending_shipment),
        'pending_reviews' => intval($pending_reviews),
        'low_stock_products' => intval($low_stock_products)
    ];
}

/**
 * 获取最近订单
 */
function todo_get_recent_orders($limit = 10) {
    global $tododb;

    $orders_table = $tododb->prefix . 'orders';
    $users_table = $tododb->prefix . 'users';
    $limit = intval($limit);

    $sql = "
        SELECT o.*, u.display_name AS customer_name
        FROM $orders_table o
        LEFT JOIN $users_table u ON o.user_id = u.ID
        ORDER BY o.created_at DESC
        LIMIT $limit
    ";
    
    return $tododb->get_results($sql);
}

/**
 * 获取热销商品
 */
function todo_get_hot_products($limit = 5) {
    global $tododb;
    
    $order_items_table = $tododb->prefix . 'order_items';
    $orders_table = $tododb->prefix . 'orders';
    
    $sql = "
        SELECT 
            oi.product_id,
            p.post_title as product_title,
            SUM(oi.quantity) as total_sales,
            SUM(oi.quantity * oi.price) as total_revenue
        FROM $order_items_table oi
        INNER JOIN $orders_table o ON oi.order_id = o.id
        INNER JOIN {$tododb->prefix}posts p ON oi.product_id = p.ID
        WHERE o.status IN ('paid', 'shipped', 'completed')
        GROUP BY oi.product_id, p.post_title
        ORDER BY total_sales DESC
        LIMIT $limit
    ";
    
    return $tododb->get_results($sql);
}

/**
 * 获取最新评价
 */
function todo_get_recent_reviews($limit = 5) {
    global $tododb;

    if (class_exists('Todo_Comment_Manager')) {
        $rows = Todo_Comment_Manager::get_comments([
            'type' => ['review'],
            'parent_id' => 0,
            'status' => 'all',
            'offset' => 0,
            'limit' => (int) $limit,
            'order_by' => 'created_at',
            'order' => 'DESC',
            'include_children' => false,
            'include_meta' => false,
        ]);
        $rows = is_array($rows) ? $rows : [];
        $results = [];
        foreach ($rows as $c) {
            if (!is_object($c)) {
                continue;
            }
            $row = new stdClass();
            $row->id = (int) ($c->comment_ID ?? 0);
            $row->product_id = (int) ($c->comment_post_ID ?? 0);
            $row->product_title = (string) ($c->target_title ?? '');
            $row->user_name = (string) ($c->comment_author ?? '');
            $row->rating = isset($c->comment_rating) ? (int) $c->comment_rating : 0;
            $row->content = (string) ($c->comment_content ?? '');
            $row->created_at = (string) ($c->comment_date ?? '');
            $st = (string) ($c->comment_approved ?? '');
            $row->status = ($st === '1') ? 'approve' : (($st === '0') ? 'pending' : (($st === 'trash') ? 'reject' : $st));
            $results[] = $row;
        }
        return $results;
    }

    $fallback = function_exists('todo_get_comments') ? todo_get_comments([
        'type' => 'review',
        'status' => 'all',
        'parent' => 0,
        'orderby' => 'comment_date',
        'order' => 'DESC',
        'number' => (int) $limit,
        'offset' => 0,
    ]) : [];
    $fallback = is_array($fallback) ? $fallback : [];
    $results = [];
    foreach ($fallback as $c) {
        if (!is_object($c)) {
            continue;
        }
        $row = new stdClass();
        $row->id = (int) ($c->comment_ID ?? 0);
        $row->product_id = (int) ($c->comment_post_ID ?? 0);
        $post = $row->product_id > 0 ? get_post($row->product_id) : null;
        if (is_array($post)) $post = (object) $post;
        $row->product_title = $post ? (string) ($post->post_title ?? '') : '';
        $row->user_name = (string) ($c->comment_author ?? '');
        $row->rating = isset($c->comment_rating) ? (int) $c->comment_rating : 0;
        $row->content = (string) ($c->comment_content ?? '');
        $row->created_at = (string) ($c->comment_date ?? '');
        $st = (string) ($c->comment_approved ?? '');
        $row->status = ($st === '1') ? 'approve' : (($st === '0') ? 'pending' : (($st === 'trash') ? 'reject' : $st));
        $results[] = $row;
    }
    return $results;
}

/**
 * 格式化订单状态
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
 * 显示星级评分
 */
function todo_display_rating($rating) {
    $stars = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rating) {
            $stars .= '<span class="star filled">★</span>';
        } else {
            $stars .= '<span class="star">☆</span>';
        }
    }
    return $stars;
}
?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">商城看板</h2>
    </div>
    
    <!-- 今日数据概览 -->
    <div class="admin-card">
        <div class="admin-card-header no-border">
            <div class="admin-card-title-section">
                <h3 class="admin-card-title">今日数据</h3>
            </div>
        </div>
        <div class="admin-card-content">
            <div class="todo-stats-cards">
                <div class="stats-card">
                    <div class="stats-icon">📊</div>
                    <div class="stats-content">
                        <h3>今日订单</h3>
                        <div class="stats-number"><?php echo number_format($today_stats['orders']); ?></div>
                        <div class="stats-sub">支付: <?php echo number_format($today_stats['paid_orders']); ?></div>
                    </div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">💰</div>
                    <div class="stats-content">
                        <h3>今日销售额</h3>
                        <div class="stats-number"><?php echo todo_format_admin_price($today_stats['sales']); ?></div>
                        <div class="stats-sub">已支付订单</div>
                    </div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">👥</div>
                    <div class="stats-content">
                        <h3>今日新用户</h3>
                        <div class="stats-number"><?php echo number_format($today_stats['users']); ?></div>
                        <div class="stats-sub">新注册用户</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 本月数据概览 -->
    <div class="admin-card">
        <div class="admin-card-header no-border">
            <div class="admin-card-title-section">
                <h3 class="admin-card-title">本月数据</h3>
            </div>
        </div>
        <div class="admin-card-content">
            <div class="todo-stats-cards">
                <div class="stats-card">
                    <div class="stats-icon">📈</div>
                    <div class="stats-content">
                        <h3>本月订单</h3>
                        <div class="stats-number"><?php echo number_format($month_stats['orders']); ?></div>
                        <div class="stats-sub">支付: <?php echo number_format($month_stats['paid_orders']); ?></div>
                    </div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">💎</div>
                    <div class="stats-content">
                        <h3>本月销售额</h3>
                        <div class="stats-number"><?php echo todo_format_admin_price($month_stats['sales']); ?></div>
                        <div class="stats-sub">已支付订单</div>
                    </div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">🆕</div>
                    <div class="stats-content">
                        <h3>本月新用户</h3>
                        <div class="stats-number"><?php echo number_format($month_stats['users']); ?></div>
                        <div class="stats-sub">新注册用户</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 待处理事项 -->
    <div class="admin-card">
        <div class="admin-card-header no-border">
            <div class="admin-card-title-section">
                <h3 class="admin-card-title">待处理事项</h3>
            </div>
        </div>
        <div class="admin-card-content">
            <div class="todo-pending-cards">
                <div class="stats-card">
                    <div class="pending-icon">⏰</div>
                    <div class="pending-content">
                        <h3>待付款订单</h3>
                        <div class="pending-number"><?php echo number_format($pending_stats['pending_payment']); ?></div>
                        <a href="<?php echo todo_admin_url('index.php?page=todo-orders&status=pending'); ?>" class="pending-link">立即处理</a>
                    </div>
                </div>
                <div class="stats-card">
                    <div class="pending-icon">📦</div>
                    <div class="pending-content">
                        <h3>待发货订单</h3>
                        <div class="pending-number"><?php echo number_format($pending_stats['pending_shipment']); ?></div>
                        <a href="<?php echo todo_admin_url('index.php?page=todo-orders&status=paid'); ?>" class="pending-link">立即处理</a>
                    </div>
                </div>
                <div class="stats-card">
                    <div class="pending-icon">⭐</div>
                    <div class="pending-content">
                        <h3>待审核评价</h3>
                        <div class="pending-number"><?php echo number_format($pending_stats['pending_reviews']); ?></div>
                        <a href="<?php echo todo_admin_url('index.php?page=todo-reviews&status=pending'); ?>" class="pending-link">立即处理</a>
                    </div>
                </div>
                <div class="stats-card">
                    <div class="pending-icon">⚠️</div>
                    <div class="pending-content">
                        <h3>库存不足</h3>
                        <div class="pending-number"><?php echo number_format($pending_stats['low_stock_products']); ?></div>
                        <a href="<?php echo todo_admin_url('index.php?page=todo-products'); ?>" class="pending-link">查看商品</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="todo-dashboard-row">
        <!-- 最近订单 -->
        <div class="admin-card">
            <div class="admin-card-header">
                <div class="admin-card-title-section">
                    <h3 class="admin-card-title">最近订单</h3>
                </div>
                <div class="admin-card-title-section">
                    <a href="<?php echo todo_admin_url('index.php?page=todo-orders'); ?>" class="widget-more">查看全部</a>
                </div>
            </div>
            <div class="admin-card-content" style="overflow: auto;">
                <?php if (empty($recent_orders)): ?>
                    <div class="no-items">
                        <p>暂无订单数据。</p>
                    </div>
                <?php else: ?>
                    <table class="recent-orders-table">
                        <thead>
                            <tr>
                                <th>订单号</th>
                                <th>客户</th>
                                <th>金额</th>
                                <th style="min-width: 80px;">状态</th>
                                <th style="min-width: 120px;">时间</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_orders as $order): ?>
                                <?php $status_info = todo_format_order_status($order->status); ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo todo_admin_url('index.php?page=todo-orders&search=' . $order->order_no); ?>">
                                            <?php echo esc_html($order->order_no); ?>
                                        </a>
                                    </td>
                                    <td><?php echo esc_html($order->customer_name); ?></td>
                                    <td><?php echo todo_format_admin_price($order->total_amount); ?></td>
                                    <td>
                                        <span class="<?php echo $status_info['class']; ?>">
                                            <?php echo $status_info['label']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('m-d H:i', strtotime($order->created_at)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- 热销商品 -->
        <div class="admin-card flex-1">
            <div class="admin-card-header">
                <div class="admin-card-title-section">
                    <h3 class="admin-card-title">热销商品</h3>
                </div>
                <div class="admin-card-title-section">
                    <a href="<?php echo todo_admin_url('index.php?page=todo-products'); ?>" class="widget-more">查看全部</a>
                </div>
            </div>
            <div class="admin-card-content">
                <?php if (empty($hot_products)): ?>
                    <div class="no-items">
                        <p>暂无销售数据。</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($hot_products as $index => $product): ?>
                        <div class="hot-product-item">
                            <div class="product-rank key<?php echo esc_html($index + 1); ?>"><?php echo esc_html($index + 1); ?></div>
                            <div class="product-info">
                                <div class="product-title">
                                    <a href="<?php echo todo_admin_url('index.php?page=post-edit&from=todo-shop&id=' . $product->product_id); ?>" style="font-size: 14px; display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 3; overflow: hidden;">
                                        <?php echo esc_html($product->product_title); ?>
                                    </a>
                                </div>
                                <div class="product-stats">
                                    销量: <?php echo number_format($product->total_sales); ?>  
                                    收入: <?php echo todo_format_admin_price($product->total_revenue); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- 最新评价 -->
    <div class="admin-card full-width">
        <div class="admin-card-header">
            <div class="admin-card-title-section">
                <h3 class="admin-card-title">最新评价</h3>
            </div>
            <div class="admin-card-title-section">
                <a href="<?php echo todo_admin_url('index.php?page=todo-reviews'); ?>" class="widget-more">查看全部</a>
            </div>
        </div>
        <div class="admin-card-content">
            <?php if (empty($recent_reviews)): ?>
                <div class="no-items">
                    <p>暂无评价数据。</p>
                </div>
            <?php else: ?>
                <?php foreach ($recent_reviews as $review): ?>
                    <div class="review-item">
                        <div class="review-header">
                            <div class="review-user">
                                <strong><?php echo esc_html($review->user_name ?: '游客'); ?></strong>
                                <span class="review-time"><?php echo date('Y-m-d H:i', strtotime($review->created_at)); ?></span>
                            </div>
                            <div class="review-rating">
                                <?php echo todo_display_rating($review->rating); ?>
                            </div>
                        </div>
                        <em class="review-product">
                            商品: <a href="<?php echo todo_admin_url('index.php?page=post-edit&from=todo-shop&id=' . $review->product_id); ?>">
                                <?php echo esc_html($review->product_title ?: '商品已删除'); ?>
                            </a>
                        </em>
                        <div class="review-content">
                            <?php echo esc_html(todo_trim_words($review->content, 20)); ?>
                        </div>
                        <em class="review-status">
                            状态: 
                            <?php if ($review->status === 'approve'): ?>
                                <span class="status-approved">已通过</span>
                            <?php elseif ($review->status === 'pending'): ?>
                                <span class="status-pending">待审核</span>
                            <?php else: ?>
                                <span class="status-reject">已拒绝</span>
                            <?php endif; ?>
                        </em>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- 快捷操作 -->
    <div class="admin-card">
        <div class="admin-card-header no-border">
            <div class="admin-card-title-section">
                <h3 class="admin-card-title">快捷操作</h3>
            </div>
        </div>
        <div class="admin-card-content">
            <div class="todo-quick-actions">
                <a href="<?php echo todo_admin_url('index.php?page=post-new&model=product'); ?>" class="quick-action-btn primary">
                    <div class="action-icon">➕</div>
                    <div class="action-text">添加商品</div>
                </a>
                <a href="<?php echo todo_admin_url('index.php?page=todo-orders&status=pending'); ?>" class="quick-action-btn warning">
                    <div class="action-icon">💳</div>
                    <div class="action-text">处理订单</div>
                </a>
                <a href="<?php echo todo_admin_url('index.php?page=todo-reviews&status=pending'); ?>" class="quick-action-btn info">
                    <div class="action-icon">⭐</div>
                    <div class="action-text">审核评价</div>
                </a>
                <a href="<?php echo todo_admin_url('index.php?page=todo-shop-stats'); ?>" class="quick-action-btn success">
                    <div class="action-icon">📊</div>
                    <div class="action-text">数据统计</div>
                </a>
                <a href="<?php echo todo_admin_url('index.php?page=todo-coupons'); ?>" class="quick-action-btn purple">
                    <div class="action-icon">🎫</div>
                    <div class="action-text">优惠券</div>
                </a>
            </div>
        </div>
    </div>
</div>

<style>
.todo-stats-cards {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.stats-card {
    flex: 1;
    background: linear-gradient(180deg, #f4faff, transparent);
    box-shadow: none;
}

.stats-icon {
    font-size: 32px;
    opacity: 0.8;
}

.stats-content h3 {
    margin: 0 0 5px 0;
    font-size: 14px;
    color: #666;
}

.stats-number {
    font-size: 24px;
    font-weight: 500;
    color: #282832;
    margin-bottom: 5px;
}

.stats-sub {
    font-size: 12px;
    color: #999;
    margin-bottom: 10px;
}

.todo-pending-cards {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
}

.pending-icon {
    font-size: 24px;
    margin-bottom: 10px;
}

.pending-content h3 {
    margin: 0 0 10px 0;
    font-size: 14px;
    color: #666;
}

.pending-number {
    font-size: 28px;
    font-weight: 500;
    color: #282832;
    margin-bottom: 10px;
}

.pending-link {
    color: #0073aa;
    text-decoration: none;
    font-size: 12px;
    display: block;
    margin-bottom: 10px;
}

.pending-link:hover {
    text-decoration: underline;
}

.todo-dashboard-row {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
}

.mbody .todo-dashboard-row > .admin-card {
    width: 100%;
}

.mbody .todo-dashboard-row > .admin-card > .admin-card-content {
    overflow: auto;
}

.widget-more {
    font-size: 12px;
}

.widget-more:hover {
    text-decoration: underline;
}

.recent-orders-table {
    width: 100%;
    border-collapse: collapse;
}

.recent-orders-table th,
.recent-orders-table td {
    padding: 8px 12px;
    text-align: left;
    border-bottom: 1px solid #eceef1;
}

.recent-orders-table th {
    font-weight: 500;
    color: #282832;
    font-size: 12px;
}

.recent-orders-table td {
    font-size: 13px;
}

.hot-product-item {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 10px 0;
    border-bottom: 1px solid #eee;
}

.hot-product-item:last-child {
    border-bottom: none;
}

.product-rank {
    width: 30px;
    height: 30px;
    background: linear-gradient(180deg, #616d73, #b0bdbb, transparent);
    color: #fff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 500;
    font-size: 14px;
}

.product-rank.key1 { background: linear-gradient(180deg, #ff2f10, #edc36f, transparent); }
.product-rank.key2 { background: linear-gradient(180deg, #eef34a, #6feda7, transparent); }
.product-rank.key3 { background: linear-gradient(180deg, #4ab7f3, #6feddd, transparent); }

.product-info {
    flex: 1;
    display: flex;
    justify-content: space-between;
    gap: 20px;
}

.product-title {
    font-weight: 500;
}

.product-title a {
    color: #282832;
    text-decoration: none;
}

.product-title a:hover {
    color: #0073aa;
}

.product-stats {
    font-size: 12px;
    color: #666;
    flex-shrink: 0;
}

.review-item {
    padding: 15px;
    border-bottom: 1px solid #eceef1;
}

.review-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.review-user strong {
    color: #282832;
}

.review-time {
    color: #999;
    font-size: 12px;
    margin-left: 10px;
}

.review-rating .star {
    color: #ffc107;
    font-size: 14px;
}

.review-rating .star.filled {
    color: #ff9800;
}

.review-product {
    font-size: 13px;
    color: #a0a0aa;
    margin-bottom: 8px;
}

.review-content {
    color: #282832;
    line-height: 1.5;
    margin-bottom: 8px;
}

.review-status {
    font-size: 12px;
    color: #a0a0aa;
}

.todo-quick-actions {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
}

.quick-action-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 20px;
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    text-decoration: none;
    color: #282832;
    min-width: 120px;
    transition: all 0.2s;
    opacity: 0.5;
}

.quick-action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    text-decoration: none;
    opacity: 1;
}

.quick-action-btn.primary { border-color: #0073aa; }
.quick-action-btn.primary:hover { background: #0073aa; color: #fff; }
.quick-action-btn.primary:hover .action-icon { color: #fff; }

.quick-action-btn.warning { border-color: #ff8c00; }
.quick-action-btn.warning:hover { background: #ff8c00; color: #fff; }

.quick-action-btn.info { border-color: #17a2b8; }
.quick-action-btn.info:hover { background: #17a2b8; color: #fff; }

.quick-action-btn.secondary { border-color: #6c757d; }
.quick-action-btn.secondary:hover { background: #6c757d; color: #fff; }

.quick-action-btn.success { border-color: #28a745; }
.quick-action-btn.success:hover { background: #28a745; color: #fff; }

.quick-action-btn.purple { border-color: #6f42c1; }
.quick-action-btn.purple:hover { background: #6f42c1; color: #fff; }

.action-icon {
    font-size: 24px;
}

.action-text {
    font-size: 14px;
    font-weight: 500;
}
</style>
