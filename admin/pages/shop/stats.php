<?php
/**
 * TodoCMS 商城统计页面
 * 
 * 显示商城运营数据统计和图表
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('TODO_ADMIN')) {
    exit('Direct access denied');
}

// 检查权限
if (!todo_current_user_can('view_stats') && !todo_current_user_can('manage_options')) {
    todo_redirect(todo_admin_url('index.php?page=dashboard'));
    exit;
}

// 获取统计数据
$stats_manager = new Todo_Order_Statistics();

// 时间范围参数
$period = $_GET['period'] ?? 'week';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// 根据时间范围获取数据
switch ($period) {
    case 'today':
        $start_date = date('Y-m-d');
        $end_date = date('Y-m-d');
        break;
    case 'week':
        $start_date = date('Y-m-d', strtotime('-7 days'));
        $end_date = date('Y-m-d');
        break;
    case 'month':
        $start_date = date('Y-m-d', strtotime('-30 days'));
        $end_date = date('Y-m-d');
        break;
    case 'year':
        $start_date = date('Y-m-d', strtotime('-365 days'));
        $end_date = date('Y-m-d');
        break;
    case 'custom':
        if (empty($start_date)) $start_date = date('Y-m-d', strtotime('-7 days'));
        if (empty($end_date)) $end_date = date('Y-m-d');
        break;
}

// 获取基础统计
$basic_stats = $stats_manager->get_basic_stats($start_date, $end_date);

// 获取销售趋势
$sales_trend_raw = $stats_manager->get_sales_trend($start_date, $end_date);
$sales_trend = array();

if (!empty($sales_trend_raw) && is_array($sales_trend_raw)) {
    foreach ($sales_trend_raw as $row) {
        $date = $row['date'] ?? ($row['period'] ?? '');
        $orders = intval($row['orders'] ?? ($row['order_count'] ?? 0));
        $amount = floatval($row['amount'] ?? ($row['sales_amount'] ?? 0));
        $paid_orders = intval($row['paid_orders'] ?? 0);
        $conversion_rate = $orders > 0 ? round(($paid_orders / $orders) * 100, 2) : 0;

        $sales_trend[] = array(
            'date' => $date,
            'orders' => $orders,
            'amount' => $amount,
            'amount_formatted' => function_exists('todo_format_admin_price') ? todo_format_admin_price($amount) : number_format($amount, 2),
            'quantity' => intval($row['quantity'] ?? 0),
            'new_users' => intval($row['new_users'] ?? 0),
            'conversion_rate' => floatval($row['conversion_rate'] ?? $conversion_rate),
        );
    }
}

// 获取商品销售排行
$product_ranking_raw = $stats_manager->get_product_ranking($start_date, $end_date, 10);
$product_ranking = [];
if (!empty($product_ranking_raw) && is_array($product_ranking_raw)) {
    foreach ($product_ranking_raw as $row) {
        if (!is_array($row)) {
            continue;
        }
        $product_id = intval($row['product_id'] ?? ($row['id'] ?? 0));
        $title = (string) ($row['title'] ?? ($row['product_name'] ?? ($row['product_title'] ?? '')));
        $qty = intval($row['quantity'] ?? ($row['total_quantity'] ?? 0));
        $amt = floatval($row['amount'] ?? ($row['total_sales'] ?? ($row['total_amount'] ?? 0)));
        $product_ranking[] = [
            'product_id' => $product_id,
            'title' => $title !== '' ? $title : ('商品 #' . $product_id),
            'quantity' => $qty,
            'amount' => $amt,
        ];
    }
}

// 获取用户统计
$user_stats = $stats_manager->get_user_stats($start_date, $end_date);

// 页面标题
$page_title = '商城统计';
?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title"><?php echo $page_title; ?></h2>
    </div>
    
    <div class="admin-card">
        <div class="admin-card-header">
            <h3 class="admin-card-title">时间范围</h3>
        </div>
        <div class="admin-card-content">
            <form method="GET" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;" action="<?php echo todo_admin_url('index.php'); ?>">
                <input type="hidden" name="page" value="todo-shop-stats">
                <?php if (function_exists('todo_is_iframe_mode') && todo_is_iframe_mode()): ?>
                    <input type="hidden" name="format" value="iframe">
                <?php endif; ?>
                
                <label for="period">时间范围：</label>
                <select id="period" name="period" onchange="toggleCustomDate()">
                    <option value="today" <?php selected($period, 'today'); ?>>今天</option>
                    <option value="week" <?php selected($period, 'week'); ?>>最近7天</option>
                    <option value="month" <?php selected($period, 'month'); ?>>最近30天</option>
                    <option value="year" <?php selected($period, 'year'); ?>>最近一年</option>
                    <option value="custom" <?php selected($period, 'custom'); ?>>自定义</option>
                </select>
                
                <div id="customDateRange" style="display: <?php echo $period === 'custom' ? 'flex' : 'none'; ?>; gap: 10px; align-items: center;">
                    <input type="date" name="start_date" value="<?php echo esc_attr($start_date); ?>">
                    <span>至</span>
                    <input type="date" name="end_date" value="<?php echo esc_attr($end_date); ?>">
                </div>
                
                <button type="submit" class="button button-secondary">查询</button>
            </form>
        </div>
    </div>
    
    <!-- 基础统计卡片 -->
    <div class="admin-card" style="margin-top: 20px;">
        <div class="admin-card-header">
            <h3 class="admin-card-title">基础统计</h3>
        </div>
        <div class="admin-card-content">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                <div style="background: #0073aa; color: white; padding: 20px; border-radius: 4px;">
                    <h4 style="margin: 0 0 10px 0; font-size: 24px;"><?php echo number_format($basic_stats['total_orders']); ?></h4>
                    <p style="margin: 0;">总订单数</p>
                </div>
                
                <div style="background: #46b450; color: white; padding: 20px; border-radius: 4px;">
                    <h4 style="margin: 0 0 10px 0; font-size: 24px;"><?php echo todo_format_admin_price($basic_stats['total_amount']); ?></h4>
                    <p style="margin: 0;">总销售额</p>
                </div>
                
                <div style="background: #00a0d2; color: white; padding: 20px; border-radius: 4px;">
                    <h4 style="margin: 0 0 10px 0; font-size: 24px;"><?php echo number_format($basic_stats['total_products']); ?></h4>
                    <p style="margin: 0;">售出商品数</p>
                </div>
                
                <div style="background: #f56e28; color: white; padding: 20px; border-radius: 4px;">
                    <h4 style="margin: 0 0 10px 0; font-size: 24px;"><?php echo number_format($basic_stats['new_users']); ?></h4>
                    <p style="margin: 0;">新增用户</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 销售趋势图表 -->
    <div class="admin-card" style="margin-top: 20px;">
        <div class="admin-card-header">
            <h3 class="admin-card-title">销售趋势</h3>
        </div>
        <div class="admin-card-content">
            <canvas id="salesTrendChart" height="100"></canvas>
        </div>
    </div>
    
    <div class="admin-card" style="margin-top: 20px;">
        <div class="admin-card-header">
            <h3 class="admin-card-title">订单状态分布</h3>
        </div>
        <div class="admin-card-content">
            <div style="width: 30%; margin: 0 auto;">
                <canvas id="orderStatusChart"></canvas>
            </div>
        </div>
    </div>
    
    <!-- 商品销售排行和用户统计 -->
    <div class="admin-card" style="margin-top: 20px;">
        <div class="admin-card-header">
            <h3 class="admin-card-title">商品销售排行 TOP 10</h3>
        </div>
        <div class="admin-card-content">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>排名</th>
                        <th style="min-width: 200px;">商品名称</th>
                        <th>销量</th>
                        <th>销售额</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($product_ranking)): ?>
                        <?php foreach ($product_ranking as $index => $product): ?>
                            <tr>
                                <td><?php echo esc_html($index + 1); ?></td>
                                <td>
                                    <a href="<?php echo todo_admin_url('index.php?page=post-edit&id=' . $product['product_id']); ?>">
                                        <?php echo esc_html($product['title']); ?>
                                    </a>
                                </td>
                                <td><?php echo number_format($product['quantity']); ?></td>
                                <td><?php echo todo_format_admin_price($product['amount']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr class="empty">
                            <td colspan="4">
                                <div class="no-items">
                                    <p>暂无数据。</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="admin-card" style="margin-top: 20px;">
        <div class="admin-card-header">
            <h3 class="admin-card-title">用户统计</h3>
        </div>
        <div class="admin-card-content">
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
                <div style="text-align: center;">
                    <h3 style="color: #0073aa; margin: 0;"><?php echo number_format($user_stats['total_users']); ?></h3>
                    <p style="margin: 5px 0 0 0;">总用户数</p>
                </div>
                <div style="text-align: center;">
                    <h3 style="color: #46b450; margin: 0;"><?php echo number_format($user_stats['active_users']); ?></h3>
                    <p style="margin: 5px 0 0 0;">活跃用户</p>
                </div>
                <div style="text-align: center;">
                    <h3 style="color: #00a0d2; margin: 0;"><?php echo number_format($user_stats['new_users']); ?></h3>
                    <p style="margin: 5px 0 0 0;">新增用户</p>
                </div>
                <div style="text-align: center;">
                    <h3 style="color: #f56e28; margin: 0;"><?php echo todo_format_admin_price($user_stats['avg_order_value'] ?? 0); ?></h3>
                    <p style="margin: 5px 0 0 0;">客单价</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 详细数据表格 -->
    <div class="admin-card" style="margin-top: 20px;">
        <div class="admin-card-header">
            <h3 class="admin-card-title">详细数据</h3>
        </div>
        <div class="admin-card-content">
            <table class="admin-table">
                    <thead>
                        <tr>
                            <th style="min-width: 120px;">日期</th>
                            <th>订单数</th>
                            <th>销售额</th>
                            <th>商品数量</th>
                            <th>新增用户</th>
                            <th>转化率</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($sales_trend)): ?>
                            <?php foreach ($sales_trend as $data): ?>
                                <tr>
                                    <td><?php echo esc_html($data['date']); ?></td>
                                    <td><?php echo number_format($data['orders']); ?></td>
                                    <td><?php echo todo_format_admin_price($data['amount']); ?></td>
                                    <td><?php echo number_format($data['quantity']); ?></td>
                                    <td><?php echo number_format($data['new_users']); ?></td>
                                    <td><?php echo number_format($data['conversion_rate'], 2); ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr class="empty">
                                <td colspan="6">
                                    <div class="no-items">
                                        <p>暂无数据。</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js" type="text/javascript"></script>
<script type="text/javascript">
/**
 * 切换自定义日期范围显示
 */
function toggleCustomDate() {
    const period = document.getElementById('period').value;
    const customRange = document.getElementById('customDateRange');
    customRange.style.display = period === 'custom' ? 'block' : 'none';
}

// 销售趋势图表
const salesTrendData = <?php echo json_encode($sales_trend); ?>;
const salesTrendChart = new Chart(document.getElementById('salesTrendChart'), {
    type: 'line',
    data: {
        labels: salesTrendData.map(item => item.date),
        datasets: [{
            label: '销售额',
            data: salesTrendData.map(item => item.amount),
            borderColor: 'rgb(75, 192, 192)',
            backgroundColor: 'rgba(75, 192, 192, 0.1)',
            tension: 0.1,
            yAxisID: 'y'
        }, {
            label: '订单数',
            data: salesTrendData.map(item => item.orders),
            borderColor: 'rgb(255, 99, 132)',
            backgroundColor: 'rgba(255, 99, 132, 0.1)',
            tension: 0.1,
            yAxisID: 'y1'
        }]
    },
    options: {
        responsive: true,
        interaction: {
            mode: 'index',
            intersect: false,
        },
        plugins: {
            tooltip: {
                callbacks: {
                    label: function(context) {
                        let label = context.dataset.label || '';
                        if (context.datasetIndex === 0) {
                            const item = salesTrendData[context.dataIndex];
                            return label + ': ' + (item && item.amount_formatted ? item.amount_formatted : context.raw);
                        }
                        return label + ': ' + context.raw;
                    }
                }
            }
        },
        scales: {
            x: {
                display: true,
                title: {
                    display: true,
                    text: '日期'
                }
            },
            y: {
                type: 'linear',
                display: true,
                position: 'left',
                title: {
                    display: true,
                    text: '销售额 <?php echo todo_get_default_currency_config()['code']; ?>'
                }
            },
            y1: {
                type: 'linear',
                display: true,
                position: 'right',
                title: {
                    display: true,
                    text: '订单数'
                },
                grid: {
                    drawOnChartArea: false,
                }
            }
        }
    }
});

// 订单状态分布图表
const orderStatusChart = new Chart(document.getElementById('orderStatusChart'), {
    type: 'doughnut',
    data: {
        labels: ['待付款', '待发货', '已发货', '已完成', '已取消'],
        datasets: [{
            data: [
                <?php echo $basic_stats['pending_payment'] ?? 0; ?>,
                <?php echo $basic_stats['pending_shipping'] ?? 0; ?>,
                <?php echo $basic_stats['shipped'] ?? 0; ?>,
                <?php echo $basic_stats['completed'] ?? 0; ?>,
                <?php echo $basic_stats['cancelled'] ?? 0; ?>
            ],
            backgroundColor: [
                '#ffc107',
                '#17a2b8',
                '#007bff',
                '#28a745',
                '#dc3545'
            ]
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});
</script>
