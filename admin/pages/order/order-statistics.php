<?php
/**
 * TodoCMS 后台订单统计页面
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('TODO_LOADED')) {
    exit('Direct access denied');
}

// 检查用户权限
if (!todo_current_user_can('manage_orders')) {
    todo_die('权限不足');
}

// 获取统计数据
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

$filters = array(
    'date_from' => $date_from,
    'date_to' => $date_to
);

// 获取概览统计
$overview_stats = Todo_Order_Statistics::get_overview_stats($filters);

// 获取销售趋势
$sales_trend = Todo_Order_Statistics::get_sales_trend('day', 30);

// 获取热销商品
$top_products = Todo_Order_Statistics::get_top_products($filters, 10);

// 获取优质客户
$top_customers = Todo_Order_Statistics::get_top_customers($filters, 10);

// 获取支付方式统计
$payment_stats = Todo_Order_Statistics::get_payment_method_stats($filters);

// 获取转化率统计
$conversion_stats = Todo_Order_Statistics::get_conversion_stats($filters);

// 页面标题
$page_title = '订单统计';
?>

<div class="wrap">
    <h1><?php echo $page_title; ?></h1>
    
    <!-- 筛选条件 -->
    <div class="todo-stats-filters">
        <form method="get" class="stats-filter-form">
            <input type="hidden" name="page" value="<?php echo $_GET['page']; ?>">
            
            <div class="filter-group">
                <label for="date_from">开始日期：</label>
                <input type="date" id="date_from" name="date_from" value="<?php echo esc_attr($date_from); ?>">
            </div>
            
            <div class="filter-group">
                <label for="date_to">结束日期：</label>
                <input type="date" id="date_to" name="date_to" value="<?php echo esc_attr($date_to); ?>">
            </div>
            
            <div class="filter-group">
                <button type="submit" class="button button-primary">筛选</button>
                <button type="button" class="button" onclick="exportReport('overview')">导出概览</button>
            </div>
        </form>
    </div>
    
    <!-- 概览统计卡片 -->
    <div class="todo-stats-overview">
        <div class="stats-cards">
            <div class="stats-card">
                <div class="card-icon">📊</div>
                <div class="card-content">
                    <h3>总订单数</h3>
                    <div class="card-value"><?php echo number_format($overview_stats['total_orders']); ?></div>
                </div>
            </div>
            
            <div class="stats-card">
                <div class="card-icon">💰</div>
                <div class="card-content">
                    <h3>已支付金额</h3>
                    <div class="card-value"><?php echo todo_format_admin_price($overview_stats['amount_stats']['paid_amount']); ?></div>
                </div>
            </div>
            
            <div class="stats-card">
                <div class="card-icon">⏳</div>
                <div class="card-content">
                    <h3>待支付金额</h3>
                    <div class="card-value"><?php echo todo_format_admin_price($overview_stats['amount_stats']['pending_amount']); ?></div>
                </div>
            </div>
            
            <div class="stats-card">
                <div class="card-icon">📈</div>
                <div class="card-content">
                    <h3>支付转化率</h3>
                    <div class="card-value"><?php echo $conversion_stats['payment_rate']; ?>%</div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 图表区域 -->
    <div class="todo-stats-charts">
        <div class="chart-row">
            <!-- 销售趋势图 -->
            <div class="chart-container">
                <h3>销售趋势</h3>
                <canvas id="salesTrendChart" width="400" height="200"></canvas>
                <button type="button" class="button" onclick="exportReport('sales_trend')">导出趋势数据</button>
            </div>
            
            <!-- 订单状态分布 -->
            <div class="chart-container">
                <h3>订单状态分布</h3>
                <div style="width: 30%; margin: 0 auto;">
                    <canvas id="orderStatusChart"></canvas>
                </div>
            </div>
        </div>
        
        <div class="chart-row">
            <!-- 支付方式分布 -->
            <div class="chart-container">
                <h3>支付方式分布</h3>
                <canvas id="paymentMethodChart" width="400" height="200"></canvas>
            </div>
            
            <!-- 转化率统计 -->
            <div class="chart-container">
                <h3>转化率统计</h3>
                <div class="conversion-stats">
                    <div class="conversion-item">
                        <span class="label">支付转化率：</span>
                        <span class="value"><?php echo $conversion_stats['payment_rate']; ?>%</span>
                        <div class="progress-bar">
                            <div class="progress" style="width: <?php echo $conversion_stats['payment_rate']; ?>%"></div>
                        </div>
                    </div>
                    <div class="conversion-item">
                        <span class="label">完成转化率：</span>
                        <span class="value"><?php echo $conversion_stats['completion_rate']; ?>%</span>
                        <div class="progress-bar">
                            <div class="progress" style="width: <?php echo $conversion_stats['completion_rate']; ?>%"></div>
                        </div>
                    </div>
                    <div class="conversion-item">
                        <span class="label">取消率：</span>
                        <span class="value"><?php echo $conversion_stats['cancellation_rate']; ?>%</span>
                        <div class="progress-bar">
                            <div class="progress cancel" style="width: <?php echo $conversion_stats['cancellation_rate']; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 数据表格区域 -->
    <div class="todo-stats-tables">
        <div class="table-row">
            <!-- 热销商品 -->
            <div class="table-container">
                <h3>热销商品 TOP 10</h3>
                <div class="table-actions">
                    <button type="button" class="button" onclick="exportReport('top_products')">导出数据</button>
                </div>
                <table class="todo-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>商品名称</th>
                            <th>销售数量</th>
                            <th>销售金额</th>
                            <th>订单数</th>
                            <th>平均价格</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($top_products)): ?>
                            <?php foreach ($top_products as $product): ?>
                                <tr>
                                    <td><?php echo esc_html($product['product_name']); ?></td>
                                    <td><?php echo number_format($product['total_quantity']); ?></td>
                                    <td><?php echo todo_format_admin_price($product['total_sales']); ?></td>
                                    <td><?php echo number_format($product['order_count']); ?></td>
                                    <td><?php echo todo_format_admin_price($product['avg_price']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr class="empty">
                                <td colspan="5">
                                    <div class="no-items">
                                        <p>暂无数据。</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- 优质客户 -->
            <div class="table-container">
                <h3>优质客户 TOP 10</h3>
                <div class="table-actions">
                    <button type="button" class="button" onclick="exportReport('top_customers')">导出数据</button>
                </div>
                <table class="todo-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>用户名</th>
                            <th>订单数</th>
                            <th>消费金额</th>
                            <th>平均消费</th>
                            <th>最后下单</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($top_customers)): ?>
                            <?php foreach ($top_customers as $customer): ?>
                                <tr>
                                    <td><?php echo esc_html($customer['user_name']); ?></td>
                                    <td><?php echo number_format($customer['order_count']); ?></td>
                                    <td><?php echo todo_format_admin_price($customer['total_amount']); ?></td>
                                    <td><?php echo todo_format_admin_price($customer['avg_amount']); ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($customer['last_order_date'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr class="empty">
                                <td colspan="5">
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
</div>

<!-- 样式 -->
<style>
.todo-stats-filters {
    background: #fff;
    padding: 20px;
    margin-bottom: 20px;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
}

.stats-filter-form {
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.filter-group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.filter-group label {
    font-weight: 500;
    white-space: nowrap;
}

.todo-stats-overview {
    margin-bottom: 30px;
}

.card-icon {
    font-size: 2.5em;
    opacity: 0.8;
}

.card-content h3 {
    margin: 0 0 8px 0;
    font-size: 14px;
    color: #666;
    font-weight: 500;
}

.card-value {
    font-size: 24px;
    font-weight: 500;
    color: #333;
}

.todo-stats-charts {
    margin-bottom: 30px;
}

.chart-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.chart-container {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 8px;
    padding: 20px;
}

.chart-container h3 {
    margin: 0 0 20px 0;
    font-size: 16px;
    color: #333;
}

.conversion-stats {
    padding: 20px 0;
}

.conversion-item {
    display: flex;
    align-items: center;
    margin-bottom: 15px;
    gap: 10px;
}

.conversion-item .label {
    min-width: 100px;
    font-weight: 500;
}

.conversion-item .value {
    min-width: 60px;
    font-weight: 500;
    color: #0073aa;
}

.progress-bar {
    flex: 1;
    height: 8px;
    background: #f0f0f1;
    border-radius: 4px;
    overflow: hidden;
}

.progress {
    height: 100%;
    background: #0073aa;
    transition: width 0.3s ease;
}

.progress.cancel {
    background: #d63638;
}

.todo-stats-tables {
    margin-bottom: 30px;
}

.table-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.table-container {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 8px;
    padding: 20px;
}

.table-container h3 {
    margin: 0 0 15px 0;
    font-size: 16px;
    color: #333;
}

.table-actions {
    margin-bottom: 15px;
    text-align: right;
}

.table-container table {
    margin: 0;
}

@media (max-width: 768px) {
    .stats-filter-form {
        flex-direction: column;
        align-items: stretch;
    }
    
    .chart-row,
    .table-row {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/chart.js" type="text/javascript"></script>
<script type="text/javascript">
// 销售趋势图
const salesTrendData = <?php echo json_encode($sales_trend); ?>;
const salesTrendCtx = document.getElementById('salesTrendChart').getContext('2d');
new Chart(salesTrendCtx, {
    type: 'line',
    data: {
        labels: salesTrendData.map(item => item.period),
        datasets: [{
            label: '销售金额',
            data: salesTrendData.map(item => parseFloat(item.sales_amount)),
            borderColor: '#0073aa',
            backgroundColor: 'rgba(0, 115, 170, 0.1)',
            tension: 0.4,
            yAxisID: 'y'
        }, {
            label: '订单数量',
            data: salesTrendData.map(item => parseInt(item.order_count)),
            borderColor: '#00a32a',
            backgroundColor: 'rgba(0, 163, 42, 0.1)',
            tension: 0.4,
            yAxisID: 'y1'
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: {
                type: 'linear',
                display: true,
                position: 'left',
                title: {
                    display: true,
                    text: '销售金额 (' + <?php echo json_encode(todo_get_default_currency_config()['symbol']); ?> + ')'
                }
            },
            y1: {
                type: 'linear',
                display: true,
                position: 'right',
                title: {
                    display: true,
                    text: '订单数量'
                },
                grid: {
                    drawOnChartArea: false,
                }
            }
        }
    }
});

// 订单状态分布图
const orderStatusData = <?php echo json_encode($overview_stats['status_stats']); ?>;
const orderStatusCtx = document.getElementById('orderStatusChart').getContext('2d');
new Chart(orderStatusCtx, {
    type: 'doughnut',
    data: {
        labels: Object.values(orderStatusData).map(item => item.name),
        datasets: [{
            data: Object.values(orderStatusData).map(item => item.count),
            backgroundColor: [
                '#0073aa',
                '#00a32a',
                '#ff6900',
                '#826eb4',
                '#d63638',
                '#ffb900'
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

// 支付方式分布图
const paymentMethodData = <?php echo json_encode($payment_stats); ?>;
const paymentMethodCtx = document.getElementById('paymentMethodChart').getContext('2d');
new Chart(paymentMethodCtx, {
    type: 'pie',
    data: {
        labels: paymentMethodData.map(item => item.method_name),
        datasets: [{
            data: paymentMethodData.map(item => parseFloat(item.total_amount)),
            backgroundColor: [
                '#0073aa',
                '#00a32a',
                '#ff6900',
                '#826eb4',
                '#d63638'
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

// 导出报表
function exportReport(type) {
    const dateFrom = document.getElementById('date_from').value;
    const dateTo = document.getElementById('date_to').value;
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?php echo todo_admin_url('admin-ajax.php'); ?>';
    
    const fields = {
        action: 'export_order_report',
        report_type: type,
        date_from: dateFrom,
        date_to: dateTo,
        _nonce: '<?php echo todo_create_nonce('export_order_report'); ?>'
    };
    
    for (const key in fields) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = fields[key];
        form.appendChild(input);
    }
    
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}
</script>