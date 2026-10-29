<?php
/**
 * TodoCMS 订单统计管理器
 * 
 * 提供订单统计和报表功能
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    exit('Direct access not permitted');
}

class Todo_Order_Statistics {
    
    /**
     * 获取基础统计数据
     * 
     * @param string $start_date 开始日期
     * @param string $end_date 结束日期
     * @return array
     */
    public static function get_basic_stats($start_date, $end_date) {
        global $tododb;
        
        $table_orders = $tododb->prefix . 'orders';
        $table_order_items = $tododb->prefix . 'order_items';
        $table_users = $tododb->prefix . 'users';
        
        $stats = array();
        
        // 构建日期条件
        $date_params = array($start_date . ' 00:00:00', $end_date . ' 23:59:59');
        
        // 总订单数
        $sql = "SELECT COUNT(*) FROM {$table_orders} WHERE created_at BETWEEN ? AND ?";
        $stats['total_orders'] = intval($tododb->get_var($sql, $date_params));
        
        // 总销售额（已支付订单）
        $sql = "SELECT COALESCE(SUM(total_amount), 0) FROM {$table_orders} WHERE created_at BETWEEN ? AND ? AND payment_status = ?";
        $params = array_merge($date_params, array(Todo_Order_Manager::PAYMENT_PAID));
        $stats['total_amount'] = floatval($tododb->get_var($sql, $params));
        
        // 售出商品数
        $sql = "SELECT COALESCE(SUM(oi.quantity), 0) FROM {$table_order_items} oi 
                INNER JOIN {$table_orders} o ON oi.order_id = o.id 
                WHERE o.created_at BETWEEN ? AND ? AND o.payment_status = ?";
        $stats['total_products'] = intval($tododb->get_var($sql, $params));
        
        // 新增用户数
        $sql = "SELECT COUNT(*) FROM {$table_users} WHERE user_registered BETWEEN ? AND ?";
        $stats['new_users'] = intval($tododb->get_var($sql, $date_params));

        $stats['pending_payment'] = 0;
        $stats['pending_shipping'] = 0;
        $stats['shipped'] = 0;
        $stats['completed'] = 0;
        $stats['cancelled'] = 0;

        $sql = "SELECT
                    COALESCE(SUM(CASE WHEN payment_status = ? AND status <> ? THEN 1 ELSE 0 END), 0) AS pending_payment,
                    COALESCE(SUM(CASE WHEN payment_status = ? AND shipping_status = ? AND status NOT IN (?, ?) THEN 1 ELSE 0 END), 0) AS pending_shipping,
                    COALESCE(SUM(CASE WHEN (status IN (?, ?) OR shipping_status IN (?, ?)) AND status <> ? THEN 1 ELSE 0 END), 0) AS shipped,
                    COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS completed,
                    COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS cancelled
                FROM {$table_orders}
                WHERE created_at BETWEEN ? AND ?";

        $params2 = [
            Todo_Order_Manager::PAYMENT_PENDING,
            Todo_Order_Manager::STATUS_CANCELLED,
            Todo_Order_Manager::PAYMENT_PAID,
            'unshipped',
            Todo_Order_Manager::STATUS_CANCELLED,
            Todo_Order_Manager::STATUS_COMPLETED,
            Todo_Order_Manager::STATUS_SHIPPED,
            Todo_Order_Manager::STATUS_DELIVERED,
            'shipped',
            'delivered',
            Todo_Order_Manager::STATUS_CANCELLED,
            Todo_Order_Manager::STATUS_COMPLETED,
            Todo_Order_Manager::STATUS_CANCELLED,
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59',
        ];
        $row = $tododb->get_row($sql, $params2, ARRAY_A);
        if (is_array($row)) {
            $stats['pending_payment'] = intval($row['pending_payment'] ?? 0);
            $stats['pending_shipping'] = intval($row['pending_shipping'] ?? 0);
            $stats['shipped'] = intval($row['shipped'] ?? 0);
            $stats['completed'] = intval($row['completed'] ?? 0);
            $stats['cancelled'] = intval($row['cancelled'] ?? 0);
        }
        
        return $stats;
    }

    /**
     * 获取订单概览统计
     * 
     * @param array $filters 过滤条件
     * @return array
     */
    public static function get_overview_stats($filters = array()) {
        global $tododb;
        
        $table_orders = $tododb->prefix . 'orders';
        
        // 构建WHERE条件
        $where_conditions = array('1=1');
        $where_values = array();
        
        // 日期范围过滤
        if (!empty($filters['date_from'])) {
            $where_conditions[] = 'created_at >= ?';
            $where_values[] = $filters['date_from'] . ' 00:00:00';
        }
        
        if (!empty($filters['date_to'])) {
            $where_conditions[] = 'created_at <= ?';
            $where_values[] = $filters['date_to'] . ' 23:59:59';
        }
        
        // 用户过滤
        if (!empty($filters['user_id'])) {
            $where_conditions[] = 'user_id = ?';
            $where_values[] = intval($filters['user_id']);
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        // 获取基础统计
        $stats = array();
        
        // 总订单数
        $sql = "SELECT COUNT(*) FROM {$table_orders} WHERE {$where_clause}";
        $stats['total_orders'] = intval($tododb->get_var($sql, $where_values));
        
        // 各状态订单数
        $status_stats = array();
        $statuses = array(
            Todo_Order_Manager::STATUS_PENDING => '待付款',
            Todo_Order_Manager::STATUS_PAID => '已付款',
            Todo_Order_Manager::STATUS_SHIPPED => '已发货',
            Todo_Order_Manager::STATUS_COMPLETED => '已完成',
            Todo_Order_Manager::STATUS_CANCELLED => '已取消',
            Todo_Order_Manager::STATUS_REFUNDED => '已退款'
        );
        
        foreach ($statuses as $status => $name) {
            $sql = "SELECT COUNT(*) FROM {$table_orders} WHERE {$where_clause} AND status = ?";
            $params = array_merge($where_values, array($status));
            $count = intval($tododb->get_var($sql, $params));
            $status_stats[$status] = array(
                'name' => $name,
                'count' => $count
            );
        }
        $stats['status_stats'] = $status_stats;
        
        // 支付状态统计
        $payment_stats = array();
        $payment_statuses = array(
            Todo_Order_Manager::PAYMENT_PENDING => '待支付',
            Todo_Order_Manager::PAYMENT_PAID => '已支付',
            Todo_Order_Manager::PAYMENT_FAILED => '支付失败',
            Todo_Order_Manager::PAYMENT_CANCELLED => '已取消',
            Todo_Order_Manager::PAYMENT_REFUNDED => '已退款'
        );
        
        foreach ($payment_statuses as $status => $name) {
            $sql = "SELECT COUNT(*) FROM {$table_orders} WHERE {$where_clause} AND payment_status = ?";
            $params = array_merge($where_values, array($status));
            $count = intval($tododb->get_var($sql, $params));
            $payment_stats[$status] = array(
                'name' => $name,
                'count' => $count
            );
        }
        $stats['payment_stats'] = $payment_stats;
        
        // 销售金额统计
        $sql = "SELECT 
                    SUM(CASE WHEN payment_status = ? THEN total_amount ELSE 0 END) as paid_amount,
                    SUM(CASE WHEN payment_status = ? THEN total_amount ELSE 0 END) as pending_amount,
                    SUM(total_amount) as total_amount
                FROM {$table_orders} WHERE {$where_clause}";
        
        $params = array_merge($where_values, array(
            Todo_Order_Manager::PAYMENT_PAID,
            Todo_Order_Manager::PAYMENT_PENDING
        ));
        $amount_stats = $tododb->get_row($sql, $params, ARRAY_A);
        
        $stats['amount_stats'] = array(
            'paid_amount' => floatval($amount_stats['paid_amount'] ?? 0),
            'pending_amount' => floatval($amount_stats['pending_amount'] ?? 0),
            'total_amount' => floatval($amount_stats['total_amount'] ?? 0)
        );
        
        return $stats;
    }
    
    /**
     * 获取商品销售排行
     * 
     * @param string $start_date 开始日期
     * @param string $end_date 结束日期
     * @param int $limit 返回数据条数
     * @return array
     */
    public static function get_product_ranking($start_date, $end_date, $limit = 10) {
        $filters = array(
            'date_from' => $start_date,
            'date_to' => $end_date
        );
        return self::get_top_products($filters, $limit);
    }

    /**
     * 获取销售趋势数据（按日期范围）
     * 
     * @param string $start_date 开始日期
     * @param string $end_date 结束日期
     * @param string $period 时间周期：day, week, month, year
     * @return array
     */
    public static function get_sales_trend($start_date, $end_date = null, $period = 'day') {
        global $tododb;
        
        // 如果第二个参数不是日期而是周期，则使用原来的逻辑
        if ($end_date === null || is_numeric($start_date)) {
            return self::get_sales_trend_by_period($start_date, $end_date);
        }
        
        $table_orders = $tododb->prefix . 'orders';
        
        // 根据周期确定日期格式和分组
        switch ($period) {
            case 'hour':
                $date_format = '%Y-%m-? %H:00:00';
                $group_format = 'DATE_FORMAT(created_at, "%Y-%m-? %H:00:00")';
                break;
            case 'day':
                $date_format = '%Y-%m-?';
                $group_format = 'DATE(created_at)';
                break;
            case 'week':
                $date_format = '%Y-%u';
                $group_format = 'YEARWEEK(created_at)';
                break;
            case 'month':
                $date_format = '%Y-%m';
                $group_format = 'DATE_FORMAT(created_at, "%Y-%m")';
                break;
            case 'year':
                $date_format = '%Y';
                $group_format = 'YEAR(created_at)';
                break;
            default:
                $date_format = '%Y-%m-?';
                $group_format = 'DATE(created_at)';
        }
        
        $sql = "SELECT 
                    {$group_format} as period,
                    COUNT(*) as order_count,
                    SUM(CASE WHEN payment_status = ? THEN total_amount ELSE 0 END) as sales_amount,
                    COUNT(CASE WHEN payment_status = ? THEN 1 END) as paid_orders
                FROM {$table_orders} 
                WHERE created_at BETWEEN ? AND ?
                GROUP BY {$group_format}
                ORDER BY period ASC";
        
        $params = array(
            Todo_Order_Manager::PAYMENT_PAID,
            Todo_Order_Manager::PAYMENT_PAID,
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        );
        $results = $tododb->get_results($sql, $params, ARRAY_A);
        
        return $results;
    }

    /**
     * 获取销售趋势数据（原方法）
     * 
     * @param string $period 时间周期：day, week, month, year
     * @param int $limit 返回数据条数
     * @return array
     */
    public static function get_sales_trend_by_period($period = 'day', $limit = 30) {
        global $tododb;
        
        $table_orders = $tododb->prefix . 'orders';
        
        // 根据周期确定日期格式和分组
        switch ($period) {
            case 'hour':
                $date_format = '%Y-%m-? %H:00:00';
                $group_format = 'DATE_FORMAT(created_at, "%Y-%m-? %H:00:00")';
                $interval = 'HOUR';
                break;
            case 'day':
                $date_format = '%Y-%m-?';
                $group_format = 'DATE(created_at)';
                $interval = 'DAY';
                break;
            case 'week':
                $date_format = '%Y-%u';
                $group_format = 'YEARWEEK(created_at)';
                $interval = 'WEEK';
                break;
            case 'month':
                $date_format = '%Y-%m';
                $group_format = 'DATE_FORMAT(created_at, "%Y-%m")';
                $interval = 'MONTH';
                break;
            case 'year':
                $date_format = '%Y';
                $group_format = 'YEAR(created_at)';
                $interval = 'YEAR';
                break;
            default:
                $date_format = '%Y-%m-?';
                $group_format = 'DATE(created_at)';
                $interval = 'DAY';
        }
        
        $sql = "SELECT 
                    {$group_format} as period,
                    COUNT(*) as order_count,
                    SUM(CASE WHEN payment_status = ? THEN total_amount ELSE 0 END) as sales_amount,
                    COUNT(CASE WHEN payment_status = ? THEN 1 END) as paid_orders
                FROM {$table_orders} 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? {$interval})
                GROUP BY {$group_format}
                ORDER BY period DESC
                LIMIT ?";
        
        $params = array(
            Todo_Order_Manager::PAYMENT_PAID,
            Todo_Order_Manager::PAYMENT_PAID,
            $limit,
            $limit
        );
        $results = $tododb->get_results($sql, $params, ARRAY_A);
        
        return array_reverse($results);
    }
    
    /**
     * 获取用户统计数据
     * 
     * @param string $start_date 开始日期
     * @param string $end_date 结束日期
     * @return array
     */
    public static function get_user_stats($start_date, $end_date) {
        global $tododb;
        
        $table_orders = $tododb->prefix . 'orders';
        $table_users = $tododb->prefix . 'users';
        
        $stats = array();
        
        // 构建日期参数
        $date_params = array($start_date . ' 00:00:00', $end_date . ' 23:59:59');
        
        // 总用户数
        $sql = "SELECT COUNT(*) FROM {$table_users}";
        $stats['total_users'] = intval($tododb->get_var($sql));
        
        // 新增用户数
        $sql = "SELECT COUNT(*) FROM {$table_users} WHERE user_registered BETWEEN ? AND ?";
        $stats['new_users'] = intval($tododb->get_var($sql, $date_params));
        
        // 活跃用户数（有下单的用户）
        $sql = "SELECT COUNT(DISTINCT user_id) FROM {$table_orders} WHERE created_at BETWEEN ? AND ?";
        $stats['active_users'] = intval($tododb->get_var($sql, $date_params));
        
        // 付费用户数（有支付成功订单的用户）
        $sql = "SELECT COUNT(DISTINCT user_id) FROM {$table_orders} WHERE created_at BETWEEN ? AND ? AND payment_status = ?";
        $params = array_merge($date_params, array(Todo_Order_Manager::PAYMENT_PAID));
        $stats['paying_users'] = intval($tododb->get_var($sql, $params));
        
        // 用户平均消费
        $sql = "SELECT AVG(total_amount) FROM {$table_orders} WHERE created_at BETWEEN ? AND ? AND payment_status = ?";
        $stats['avg_spending'] = floatval($tododb->get_var($sql, $params));
        
        // 客单价（平均订单金额）
        $sql = "SELECT AVG(total_amount) FROM {$table_orders} WHERE created_at BETWEEN ? AND ? AND payment_status = ?";
        $stats['avg_order_value'] = floatval($tododb->get_var($sql, $params));
        
        // 获取用户排行榜
        $filters = array(
            'date_from' => $start_date,
            'date_to' => $end_date
        );
        $stats['top_customers'] = self::get_top_customers($filters, 5);
        
        return $stats;
    }

    /**
     * 获取热销商品统计
     * 
     * @param array $filters 过滤条件
     * @param int $limit 返回数据条数
     * @return array
     */
    public static function get_top_products($filters = array(), $limit = 10) {
        global $tododb;
        
        $table_orders = $tododb->prefix . 'orders';
        $table_order_items = $tododb->prefix . 'order_items';
        $table_posts = $tododb->prefix . 'posts';
        
        // 构建WHERE条件
        $where_conditions = array('o.payment_status = ?');
        $where_values = array(Todo_Order_Manager::PAYMENT_PAID);
        
        // 日期范围过滤
        if (!empty($filters['date_from'])) {
            $where_conditions[] = 'o.created_at >= ?';
            $where_values[] = $filters['date_from'] . ' 00:00:00';
        }
        
        if (!empty($filters['date_to'])) {
            $where_conditions[] = 'o.created_at <= ?';
            $where_values[] = $filters['date_to'] . ' 23:59:59';
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        $sql = "SELECT 
                    oi.product_id,
                    p.post_title as product_name,
                    SUM(oi.quantity) as total_quantity,
                    SUM(oi.quantity * oi.price) as total_sales,
                    COUNT(DISTINCT o.id) as order_count,
                    AVG(oi.price) as avg_price
                FROM {$table_order_items} oi
                LEFT JOIN {$table_orders} o ON oi.order_id = o.id
                LEFT JOIN {$table_posts} p ON oi.product_id = p.ID
                WHERE {$where_clause}
                GROUP BY oi.product_id
                ORDER BY total_quantity DESC
                LIMIT ?";
        
        $where_values[] = $limit;
return $tododb->get_results($sql, $where_values, ARRAY_A);
    }
    
    /**
     * 获取用户购买统计
     * 
     * @param array $filters 过滤条件
     * @param int $limit 返回数据条数
     * @return array
     */
    public static function get_top_customers($filters = array(), $limit = 10) {
        global $tododb;
        
        $table_orders = $tododb->prefix . 'orders';
        $table_users = $tododb->prefix . 'users';
        
        // 构建WHERE条件
        $where_conditions = array('o.payment_status = ?');
        $where_values = array(Todo_Order_Manager::PAYMENT_PAID);
        
        // 日期范围过滤
        if (!empty($filters['date_from'])) {
            $where_conditions[] = 'o.created_at >= ?';
            $where_values[] = $filters['date_from'] . ' 00:00:00';
        }
        
        if (!empty($filters['date_to'])) {
            $where_conditions[] = 'o.created_at <= ?';
            $where_values[] = $filters['date_to'] . ' 23:59:59';
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        $sql = "SELECT 
                    o.user_id,
                    u.display_name as user_name,
                    u.user_email,
                    COUNT(*) as order_count,
                    SUM(o.total_amount) as total_amount,
                    AVG(o.total_amount) as avg_amount,
                    MAX(o.created_at) as last_order_date
                FROM {$table_orders} o
                LEFT JOIN {$table_users} u ON o.user_id = u.ID
                WHERE {$where_clause}
                GROUP BY o.user_id
                ORDER BY total_amount DESC
                LIMIT ?";
        
        $where_values[] = $limit;
return $tododb->get_results($sql, $where_values, ARRAY_A);
    }
    
    /**
     * 获取支付方式统计
     * 
     * @param array $filters 过滤条件
     * @return array
     */
    public static function get_payment_method_stats($filters = array()) {
        global $tododb;
        
        $table_orders = $tododb->prefix . 'orders';
        
        // 构建WHERE条件
        $where_conditions = array('payment_status = ?');
        $where_values = array(Todo_Order_Manager::PAYMENT_PAID);
        
        // 日期范围过滤
        if (!empty($filters['date_from'])) {
            $where_conditions[] = 'created_at >= ?';
            $where_values[] = $filters['date_from'] . ' 00:00:00';
        }
        
        if (!empty($filters['date_to'])) {
            $where_conditions[] = 'created_at <= ?';
            $where_values[] = $filters['date_to'] . ' 23:59:59';
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        $sql = "SELECT 
                    payment_method,
                    COUNT(*) as order_count,
                    SUM(total_amount) as total_amount,
                    AVG(total_amount) as avg_amount
                FROM {$table_orders}
                WHERE {$where_clause}
                GROUP BY payment_method
                ORDER BY total_amount DESC";
$results = $tododb->get_results($sql, $where_values, ARRAY_A);
        
        // 添加支付方式名称
        foreach ($results as &$result) {
            $result['method_name'] = Todo_Payment_Manager::get_method_name($result['payment_method']);
        }
        
        return $results;
    }
    
    /**
     * 获取地区销售统计
     * 
     * @param array $filters 过滤条件
     * @param int $limit 返回数据条数
     * @return array
     */
    public static function get_region_stats($filters = array(), $limit = 20) {
        global $tododb;
        
        $table_orders = $tododb->prefix . 'orders';
        
        // 构建WHERE条件
        $where_conditions = array('payment_status = ?', 'shipping_province IS NOT NULL', 'shipping_province != ""');
        $where_values = array(Todo_Order_Manager::PAYMENT_PAID);
        
        // 日期范围过滤
        if (!empty($filters['date_from'])) {
            $where_conditions[] = 'created_at >= ?';
            $where_values[] = $filters['date_from'] . ' 00:00:00';
        }
        
        if (!empty($filters['date_to'])) {
            $where_conditions[] = 'created_at <= ?';
            $where_values[] = $filters['date_to'] . ' 23:59:59';
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        $sql = "SELECT 
                    shipping_province as province,
                    COUNT(*) as order_count,
                    SUM(total_amount) as total_amount,
                    AVG(total_amount) as avg_amount
                FROM {$table_orders}
                WHERE {$where_clause}
                GROUP BY shipping_province
                ORDER BY total_amount DESC
                LIMIT ?";
        
        $where_values[] = $limit;
return $tododb->get_results($sql, $where_values, ARRAY_A);
    }
    
    /**
     * 获取转化率统计
     * 
     * @param array $filters 过滤条件
     * @return array
     */
    public static function get_conversion_stats($filters = array()) {
        global $tododb;
        
        $table_orders = $tododb->prefix . 'orders';
        
        // 构建WHERE条件
        $where_conditions = array('1=1');
        $where_values = array();
        
        // 日期范围过滤
        if (!empty($filters['date_from'])) {
            $where_conditions[] = 'created_at >= ?';
            $where_values[] = $filters['date_from'] . ' 00:00:00';
        }
        
        if (!empty($filters['date_to'])) {
            $where_conditions[] = 'created_at <= ?';
            $where_values[] = $filters['date_to'] . ' 23:59:59';
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        $sql = "SELECT 
                    COUNT(*) as total_orders,
                    COUNT(CASE WHEN payment_status = ? THEN 1 END) as paid_orders,
                    COUNT(CASE WHEN status = ? THEN 1 END) as completed_orders,
                    COUNT(CASE WHEN status = ? THEN 1 END) as cancelled_orders
                FROM {$table_orders}
                WHERE {$where_clause}";
        
        $params = array_merge($where_values, array(
            Todo_Order_Manager::PAYMENT_PAID,
            Todo_Order_Manager::STATUS_COMPLETED,
            Todo_Order_Manager::STATUS_CANCELLED
        ));
        $stats = $tododb->get_row($sql, $params, ARRAY_A);
        
        $total_orders = intval($stats['total_orders']);
        $paid_orders = intval($stats['paid_orders']);
        $completed_orders = intval($stats['completed_orders']);
        $cancelled_orders = intval($stats['cancelled_orders']);
        
        return array(
            'total_orders' => $total_orders,
            'paid_orders' => $paid_orders,
            'completed_orders' => $completed_orders,
            'cancelled_orders' => $cancelled_orders,
            'payment_rate' => $total_orders > 0 ? round(($paid_orders / $total_orders) * 100, 2) : 0,
            'completion_rate' => $paid_orders > 0 ? round(($completed_orders / $paid_orders) * 100, 2) : 0,
            'cancellation_rate' => $total_orders > 0 ? round(($cancelled_orders / $total_orders) * 100, 2) : 0
        );
    }
    
    /**
     * 导出统计报表
     * 
     * @param string $type 报表类型
     * @param array $filters 过滤条件
     * @return array
     */
    public static function export_report($type, $filters = array()) {
        $data = array();
        $filename = '';
        
        switch ($type) {
            case 'overview':
                $data = self::get_overview_stats($filters);
                $filename = 'order_overview_' . date('Y-m-d');
                break;
                
            case 'sales_trend':
                $period = $filters['period'] ?? 'day';
                $limit = $filters['limit'] ?? 30;
                $data = self::get_sales_trend($period, $limit);
                $filename = 'sales_trend_' . $period . '_' . date('Y-m-d');
                break;
                
            case 'top_products':
                $data = self::get_top_products($filters, $filters['limit'] ?? 50);
                $filename = 'top_products_' . date('Y-m-d');
                break;
                
            case 'top_customers':
                $data = self::get_top_customers($filters, $filters['limit'] ?? 50);
                $filename = 'top_customers_' . date('Y-m-d');
                break;
                
            default:
                throw new Exception('未知的报表类型');
        }
        
        // 生成CSV文件
        $filepath = todo_get_upload_dir() . '/reports/' . $filename . '.csv';
        
        // 确保目录存在
        $report_dir = dirname($filepath);
        if (!file_exists($report_dir)) {
            todo_mkdir_p($report_dir);
        }
        
        // 创建CSV文件
        $fp = fopen($filepath, 'w');
        if (!$fp) {
            throw new Exception('无法创建报表文件');
        }
        
        // 写入BOM头，解决中文乱码
        fwrite($fp, "\xEF\xBB\xBF");
        
        // 根据报表类型写入数据
        self::write_report_data($fp, $type, $data);
        
        fclose($fp);
        
        // 返回下载链接
        $download_url = todo_get_upload_url() . '/reports/' . $filename . '.csv';
        
        return array(
            'success' => true,
            'download_url' => $download_url,
            'filename' => $filename . '.csv'
        );
    }
    
    /**
     * 写入报表数据到CSV文件
     * 
     * @param resource $fp 文件句柄
     * @param string $type 报表类型
     * @param array $data 数据
     */
    private static function write_report_data($fp, $type, $data) {
        switch ($type) {
            case 'sales_trend':
                fputcsv($fp, array('时间周期', '订单数量', '销售金额', '支付订单数'));
                foreach ($data as $row) {
                    fputcsv($fp, array(
                        $row['period'],
                        $row['order_count'],
                        $row['sales_amount'],
                        $row['paid_orders']
                    ));
                }
                break;
                
            case 'top_products':
                fputcsv($fp, array('商品ID', '商品名称', '销售数量', '销售金额', '订单数量', '平均价格'));
                foreach ($data as $row) {
                    fputcsv($fp, array(
                        $row['product_id'],
                        $row['product_name'],
                        $row['total_quantity'],
                        $row['total_sales'],
                        $row['order_count'],
                        $row['avg_price']
                    ));
                }
                break;
                
            case 'top_customers':
                fputcsv($fp, array('用户ID', '用户名', '邮箱', '订单数量', '消费金额', '平均消费', '最后下单时间'));
                foreach ($data as $row) {
                    fputcsv($fp, array(
                        $row['user_id'],
                        $row['user_name'],
                        $row['user_email'],
                        $row['order_count'],
                        $row['total_amount'],
                        $row['avg_amount'],
                        $row['last_order_date']
                    ));
                }
                break;
        }
    }
}
