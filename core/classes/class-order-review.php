<?php
/**
 * TodoCMS 订单评价管理器
 * 
 * 管理订单商品评价功能，支持评分、评论、图片上传等
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    exit('Direct access not permitted');
}

class Todo_Order_Review {
    
    /**
     * 评价状态常量
     */
    const STATUS_PENDING = 'pending';      // 待审核
    const STATUS_APPROVED = 'approved';    // 已通过
    const STATUS_REJECTED = 'rejected';    // 已拒绝
    
    /**
     * 评分等级
     */
    const RATING_EXCELLENT = 5;  // 非常满意
    const RATING_GOOD = 4;       // 满意
    const RATING_AVERAGE = 3;    // 一般
    const RATING_POOR = 2;       // 不满意
    const RATING_TERRIBLE = 1;   // 非常不满意
    
    /**
     * 数据库连接
     * @var CMS_DB
     */
    private static $tododb;
    
    /**
     * 初始化
     */
    public static function init() {
        global $tododb;
        self::$tododb = $tododb;
        
        // 注册钩子
        add_action('todo_order_completed', array(__CLASS__, 'on_order_completed'));
    }
    
    /**
     * 创建评价
     * 
     * @param array $review_data 评价数据
     * @return int|false 评价ID或false
     */
    public static function create_review($review_data) {
        // 验证必填字段
        $required_fields = array('order_id', 'user_id', 'product_id', 'rating', 'content');
        foreach ($required_fields as $field) {
            if (empty($review_data[$field])) {
                return false;
            }
        }
        
        // 检查是否已评价
        if (self::has_reviewed($review_data['order_id'], $review_data['product_id'], $review_data['user_id'])) {
            return false;
        }
        
        // 验证订单和商品
        $order = Todo_Order_Manager::get_order($review_data['order_id']);
        if (!$order || $order['user_id'] != $review_data['user_id']) {
            return false;
        }
        
        // 检查订单状态（只有已完成的订单才能评价）
        if ($order['status'] !== Todo_Order_Manager::STATUS_COMPLETED) {
            return false;
        }
        
        // 验证评分范围
        $rating = intval($review_data['rating']);
        if ($rating < 1 || $rating > 5) {
            return false;
        }
        
        // 准备数据
        $data = array(
            'order_id' => intval($review_data['order_id']),
            'user_id' => intval($review_data['user_id']),
            'product_id' => intval($review_data['product_id']),
            'rating' => $rating,
            'content' => trim($review_data['content']),
            'images' => !empty($review_data['images']) ? json_encode($review_data['images'], JSON_UNESCAPED_UNICODE) : '',
            'status' => self::get_default_status(),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        );
        
        // 插入数据库
        $table = DB_PREFIX . 'order_reviews';
        $fields = implode(', ', array_keys($data));
        $placeholders = str_repeat('?,', count($data) - 1) . '?';
        
        $query = "INSERT INTO {$table} ({$fields}) VALUES ({$placeholders})";
        
        $result = self::$tododb->query($query, array_values($data));
        
        if ($result) {
            $review_id = self::$tododb->insert_id();
            
            // 更新商品评价统计
            self::update_product_rating_stats($review_data['product_id']);
            
            // 触发钩子
            do_action('todo_review_created', $review_id, $review_data);
            
            return $review_id;
        }
        
        return false;
    }
    
    /**
     * 获取评价信息
     * 
     * @param int $review_id 评价ID
     * @return array|null
     */
    public static function get_review($review_id) {
        $table = DB_PREFIX . 'order_reviews';
        $query = "SELECT r.*, u.display_name as user_name, p.post_title as product_title 
                  FROM {$table} r 
                  LEFT JOIN " . DB_PREFIX . "users u ON r.user_id = u.ID 
                  LEFT JOIN " . DB_PREFIX . "posts p ON r.product_id = p.ID 
                  WHERE r.id = ?";
        
        $row = self::$tododb->get_row($query, array($review_id), ARRAY_A);
        
        if ($row) {
            // 解析图片数据
            if (!empty($row['images'])) {
                $row['images'] = json_decode($row['images'], true);
            } else {
                $row['images'] = array();
            }
            
            return $row;
        }
        
        return null;
    }
    
    /**
     * 获取订单的评价列表
     * 
     * @param int $order_id 订单ID
     * @param int $user_id 用户ID（可选，用于权限检查）
     * @return array
     */
    public static function get_order_reviews($order_id, $user_id = 0) {
        $table = DB_PREFIX . 'order_reviews';
        $where_clause = "r.order_id = ?";
        $params = array($order_id);
        $types = 'i';
        
        // 如果指定了用户ID，添加权限检查
        if ($user_id > 0) {
            $where_clause .= " AND r.user_id = ?";
            $params[] = $user_id;
            $types .= 'i';
        }
        
        $query = "SELECT r.*, u.display_name as user_name, p.post_title as product_title 
                  FROM {$table} r 
                  LEFT JOIN " . DB_PREFIX . "users u ON r.user_id = u.ID 
                  LEFT JOIN " . DB_PREFIX . "posts p ON r.product_id = p.ID 
                  WHERE {$where_clause} 
                  ORDER BY r.created_at DESC";
        
        $results = self::$tododb->get_results($query, $params, ARRAY_A);
        
        $reviews = array();
        foreach ($results as $row) {
            // 解析图片数据
            if (!empty($row['images'])) {
                $row['images'] = json_decode($row['images'], true);
            } else {
                $row['images'] = array();
            }
            
            $reviews[] = $row;
        }
        
        return $reviews;
    }
    
    /**
     * 获取商品的评价列表
     * 
     * @param int $product_id 商品ID
     * @param array $args 查询参数
     * @return array
     */
    public static function get_product_reviews($product_id, $args = array()) {
        $defaults = array(
            'status' => self::STATUS_APPROVED,
            'limit' => 20,
            'offset' => 0,
            'order_by' => 'created_at',
            'order' => 'DESC'
        );
        
        $args = array_merge($defaults, $args);
        
        $table = DB_PREFIX . 'order_reviews';
        $where_clause = "r.product_id = ? AND r.status = ?";
        $params = array($product_id, $args['status']);
        $types = 'is';
        
        $query = "SELECT r.*, u.display_name as user_name 
                  FROM {$table} r 
                  LEFT JOIN " . DB_PREFIX . "users u ON r.user_id = u.ID 
                  WHERE {$where_clause} 
                  ORDER BY r.{$args['order_by']} {$args['order']} 
                  LIMIT {$args['limit']} OFFSET {$args['offset']}";
        
        $results = self::$tododb->get_results($query, $params, ARRAY_A);
        
        $reviews = array();
        foreach ($results as $row) {
            // 解析图片数据
            if (!empty($row['images'])) {
                $row['images'] = json_decode($row['images'], true);
            } else {
                $row['images'] = array();
            }
            
            // 隐藏用户名部分字符
            if (!empty($row['user_name'])) {
                $row['user_name'] = self::mask_username($row['user_name']);
            }
            
            $reviews[] = $row;
        }
        
        return $reviews;
    }
    
    /**
     * 更新评价状态
     * 
     * @param int $review_id 评价ID
     * @param string $status 新状态
     * @param int $operator_id 操作者ID
     * @return bool
     */
    public static function update_review_status($review_id, $status, $operator_id = 0) {
        $valid_statuses = array(self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED);
        if (!in_array($status, $valid_statuses)) {
            return false;
        }
        
        $table = DB_PREFIX . 'order_reviews';
        $query = "UPDATE {$table} SET status = ?, updated_at = ? WHERE id = ?";
        
        $updated_at = date('Y-m-d H:i:s');
        $result = self::$tododb->query($query, array($status, $updated_at, $review_id));
        
        if ($result) {
            // 如果是通过审核，更新商品评价统计
            if ($status === self::STATUS_APPROVED) {
                $review = self::get_review($review_id);
                if ($review) {
                    self::update_product_rating_stats($review['product_id']);
                }
            }
            
            // 触发钩子
            do_action('todo_review_status_updated', $review_id, $status, $operator_id);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * 检查是否已评价
     * 
     * @param int $order_id 订单ID
     * @param int $product_id 商品ID
     * @param int $user_id 用户ID
     * @return bool
     */
    public static function has_reviewed($order_id, $product_id, $user_id) {
        $table = DB_PREFIX . 'order_reviews';
        $query = "SELECT COUNT(*) FROM {$table} WHERE order_id = ? AND product_id = ? AND user_id = ?";
        
        return self::$tododb->get_var($query, [$order_id, $product_id, $user_id]) > 0;
    }
    
    /**
     * 获取商品评价统计
     * 
     * @param int $product_id 商品ID
     * @return array
     */
    public static function get_product_rating_stats($product_id) {
        $table = DB_PREFIX . 'order_reviews';
        $query = "SELECT 
                    COUNT(*) as total_reviews,
                    AVG(rating) as average_rating,
                    SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as rating_5,
                    SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as rating_4,
                    SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as rating_3,
                    SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as rating_2,
                    SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as rating_1
                  FROM {$table} 
                  WHERE product_id = ? AND status = ?";
        
        $status = self::STATUS_APPROVED;
        $row = self::$tododb->get_row($query, array($product_id, $status), ARRAY_A);
        
        if ($row) {
            $row['average_rating'] = round(floatval($row['average_rating']), 1);
            return $row;
        }
        
        return array(
            'total_reviews' => 0,
            'average_rating' => 0,
            'rating_5' => 0,
            'rating_4' => 0,
            'rating_3' => 0,
            'rating_2' => 0,
            'rating_1' => 0
        );
    }
    
    /**
     * 更新商品评价统计
     * 
     * @param int $product_id 商品ID
     * @return bool
     */
    private static function update_product_rating_stats($product_id) {
        $stats = self::get_product_rating_stats($product_id);
        
        // 更新商品元数据
        $meta_data = array(
            '_review_count' => $stats['total_reviews'],
            '_average_rating' => $stats['average_rating'],
            '_rating_counts' => json_encode(array(
                '5' => $stats['rating_5'],
                '4' => $stats['rating_4'],
                '3' => $stats['rating_3'],
                '2' => $stats['rating_2'],
                '1' => $stats['rating_1']
            ))
        );
        
        foreach ($meta_data as $key => $value) {
            todo_update_post_meta($product_id, $key, $value);
        }
        
        return true;
    }
    
    /**
     * 获取默认评价状态
     * 
     * @return string
     */
    private static function get_default_status() {
        $service_settings = todo_get_option('todo_service_settings', null);
        if (is_array($service_settings) && array_key_exists('review_approval', $service_settings)) {
            $enabled = (int) $service_settings['review_approval'] === 1;
            return $enabled ? self::STATUS_PENDING : self::STATUS_APPROVED;
        }

        $review_moderation = todo_get_option('review_moderation', 'auto_approve');
        return $review_moderation === 'manual_approve' ? self::STATUS_PENDING : self::STATUS_APPROVED;
    }
    
    /**
     * 隐藏用户名部分字符
     * 
     * @param string $username 用户名
     * @return string
     */
    private static function mask_username($username) {
        $length = mb_strlen($username, 'UTF-8');
        
        if ($length <= 2) {
            return $username;
        } elseif ($length <= 4) {
            return mb_substr($username, 0, 1, 'UTF-8') . str_repeat('*', $length - 2) . mb_substr($username, -1, 1, 'UTF-8');
        } else {
            return mb_substr($username, 0, 2, 'UTF-8') . str_repeat('*', $length - 4) . mb_substr($username, -2, 2, 'UTF-8');
        }
    }
    
    /**
     * 获取评分文本
     * 
     * @param int $rating 评分
     * @return string
     */
    public static function get_rating_text($rating) {
        $rating_texts = array(
            5 => '非常满意',
            4 => '满意',
            3 => '一般',
            2 => '不满意',
            1 => '非常不满意'
        );
        
        return isset($rating_texts[$rating]) ? $rating_texts[$rating] : '未知';
    }
    
    /**
     * 获取状态文本
     * 
     * @param string $status 状态
     * @return string
     */
    public static function get_status_text($status) {
        $status_texts = array(
            self::STATUS_PENDING => '待审核',
            self::STATUS_APPROVED => '已通过',
            self::STATUS_REJECTED => '已拒绝'
        );
        
        return isset($status_texts[$status]) ? $status_texts[$status] : '未知';
    }
    
    /**
     * 订单完成时的处理
     * 
     * @param int $order_id 订单ID
     */
    public static function on_order_completed($order_id) {
        // 可以在这里发送评价邀请邮件等
        do_action('todo_review_invitation', $order_id);
    }
    
    /**
     * 获取评价数量统计
     * 
     * @param string $status 状态筛选（可选）
     * @param array $args 其他查询参数
     * @return int
     */
    public static function get_reviews_count($status = '', $args = array()) {
        $table = DB_PREFIX . 'order_reviews';
        $where_conditions = array();
        $params = array();
        $types = '';
        
        // 状态筛选
        if (!empty($status)) {
            $where_conditions[] = "status = ?";
            $params[] = $status;
            $types .= 's';
        }
        
        // 评分筛选
        if (!empty($args['rating'])) {
            $where_conditions[] = "rating = ?";
            $params[] = intval($args['rating']);
            $types .= 'i';
        }
        
        // 商品ID筛选
        if (!empty($args['product_id'])) {
            $where_conditions[] = "product_id = ?";
            $params[] = intval($args['product_id']);
            $types .= 'i';
        }
        
        // 用户ID筛选
        if (!empty($args['user_id'])) {
            $where_conditions[] = "user_id = ?";
            $params[] = intval($args['user_id']);
            $types .= 'i';
        }
        
        // 订单ID筛选
        if (!empty($args['order_id'])) {
            $where_conditions[] = "order_id = ?";
            $params[] = intval($args['order_id']);
            $types .= 'i';
        }
        
        // 日期范围筛选
        if (!empty($args['date_from'])) {
            $where_conditions[] = "created_at >= ?";
            $params[] = $args['date_from'];
            $types .= 's';
        }
        
        if (!empty($args['date_to'])) {
            $where_conditions[] = "created_at <= ?";
            $params[] = $args['date_to'] . ' 23:59:59';
            $types .= 's';
        }
        
        // 构建WHERE子句
        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }
        
        $query = "SELECT COUNT(*) FROM {$table} {$where_clause}";
        
        if (empty($params)) {
            // 没有参数的简单查询
            $result = self::$tododb->get_var($query);
            return intval($result);
        } else {
            // 有参数的预处理查询
            $result = self::$tododb->get_var($query, $params);
            return intval($result);
        }
        
        return 0;
    }

    /**
     * 删除评价
     * 
     * @param int $review_id 评价ID
     * @param int $operator_id 操作者ID
     * @return bool
     */
    public static function delete_review($review_id, $operator_id = 0) {
        // 获取评价信息
        $review = self::get_review($review_id);
        if (!$review) {
            return false;
        }
        
        $table = DB_PREFIX . 'order_reviews';
        $query = "DELETE FROM {$table} WHERE id = ?";
        
        $result = self::$tododb->query($query, array($review_id));
        
        if ($result) {
            // 更新商品评价统计
            self::update_product_rating_stats($review['product_id']);
            
            // 触发钩子
            do_action('todo_review_deleted', $review_id, $review, $operator_id);
            
            return true;
        }
        
        return false;
    }
}

// 初始化
Todo_Order_Review::init();
