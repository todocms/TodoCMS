<?php
/**
 * 产品咨询管理器类
 * 
 * 处理产品相关的用户咨询和问答功能
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

class Todo_Product_Inquiry_Manager {
    
    /**
     * 单例实例
     * 
     * @var Todo_Product_Inquiry_Manager
     */
    private static $instance = null;
    
    /**
     * 数据库连接
     * 
     * @var PDO
     */
    private $db;
    
    /**
     * 表名
     * 
     * @var string
     */
    private $table_name;
    
    /**
     * 构造函数
     */
    private function __construct() {
        global $tododb;
        $this->db = $tododb;
        $this->table_name = $tododb->prefix . 'product_inquiries';
        
        // 初始化钩子
        $this->init_hooks();
    }
    
    /**
     * 获取单例实例
     * 
     * @return Todo_Product_Inquiry_Manager
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 初始化钩子
     */
    private function init_hooks() {
        // 可以在这里添加相关的钩子
    }
    
    /**
     * 添加产品咨询
     * 
     * @param array $data 咨询数据
     * @return int|false 咨询ID或false
     */
    public function add_inquiry($data) {
        try {
            // 验证必需字段
            $required_fields = ['product_id', 'user_name', 'user_email', 'inquiry_title', 'inquiry_content'];
            foreach ($required_fields as $field) {
                if (empty($data[$field])) {
                    throw new Exception("缺少必需字段: {$field}");
                }
            }
            
            // 准备数据
            $insert_data = [
                'product_id' => intval($data['product_id']),
                'user_id' => isset($data['user_id']) ? intval($data['user_id']) : null,
                'user_name' => sanitize_text_field($data['user_name']),
                'user_email' => sanitize_email($data['user_email']),
                'user_phone' => isset($data['user_phone']) ? sanitize_text_field($data['user_phone']) : null,
                'inquiry_title' => sanitize_text_field($data['inquiry_title']),
                'inquiry_content' => sanitize_textarea_field($data['inquiry_content']),
                'inquiry_type' => isset($data['inquiry_type']) ? $data['inquiry_type'] : 'general',
                'priority' => isset($data['priority']) ? $data['priority'] : 'normal',
                'ip_address' => $this->get_client_ip(),
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null
            ];
            
            // 构建SQL
            $fields = implode(', ', array_keys($insert_data));
            $placeholders = ':' . implode(', :', array_keys($insert_data));
            
            $sql = "INSERT INTO {$this->table_name} ({$fields}) VALUES ({$placeholders})";
            $stmt = $this->db->prepare($sql);
            
            if ($stmt->execute($insert_data)) {
                $inquiry_id = $this->db->lastInsertId();
                
                // 触发钩子
                todo_do_action('product_inquiry_added', $inquiry_id, $insert_data);
                
                return $inquiry_id;
            }
            
            return false;
        } catch (Exception $e) {
            error_log('添加产品咨询失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取产品咨询列表
     * 
     * @param array $args 查询参数
     * @return array
     */
    public function get_inquiries($args = []) {
        try {
            $defaults = [
                'product_id' => null,
                'user_id' => null,
                'status' => null,
                'inquiry_type' => null,
                'is_public' => null,
                'limit' => 20,
                'offset' => 0,
                'orderby' => 'created_at',
                'order' => 'DESC'
            ];
            
            $args = array_merge($defaults, $args);
            
            // 构建WHERE条件
            $where_conditions = [];
            $params = [];
            
            if ($args['product_id']) {
                $where_conditions[] = 'product_id = :product_id';
                $params['product_id'] = intval($args['product_id']);
            }
            
            if ($args['user_id']) {
                $where_conditions[] = 'user_id = :user_id';
                $params['user_id'] = intval($args['user_id']);
            }
            
            if ($args['status']) {
                $where_conditions[] = 'status = :status';
                $params['status'] = $args['status'];
            }
            
            if ($args['inquiry_type']) {
                $where_conditions[] = 'inquiry_type = :inquiry_type';
                $params['inquiry_type'] = $args['inquiry_type'];
            }
            
            if ($args['is_public'] !== null) {
                $where_conditions[] = 'is_public = :is_public';
                $params['is_public'] = intval($args['is_public']);
            }
            
            $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
            
            // 构建ORDER BY
            $order_clause = "ORDER BY {$args['orderby']} {$args['order']}";
            
            // 构建LIMIT
            $limit_clause = "LIMIT {$args['limit']} OFFSET {$args['offset']}";
            
            $sql = "SELECT * FROM {$this->table_name} {$where_clause} {$order_clause} {$limit_clause}";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('获取产品咨询列表失败: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取单个咨询
     * 
     * @param int $inquiry_id 咨询ID
     * @return array|false
     */
    public function get_inquiry($inquiry_id) {
        try {
            $sql = "SELECT * FROM {$this->table_name} WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => intval($inquiry_id)]);
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('获取产品咨询失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 回复咨询
     * 
     * @param int $inquiry_id 咨询ID
     * @param string $reply 回复内容
     * @param int $admin_user_id 管理员用户ID
     * @return bool
     */
    public function reply_inquiry($inquiry_id, $reply, $admin_user_id) {
        try {
            $sql = "UPDATE {$this->table_name} SET 
                        admin_reply = :reply, 
                        admin_user_id = :admin_user_id, 
                        reply_date = NOW(), 
                        status = 'answered',
                        updated_at = NOW()
                    WHERE id = :id";
            
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([
                'reply' => sanitize_textarea_field($reply),
                'admin_user_id' => intval($admin_user_id),
                'id' => intval($inquiry_id)
            ]);
            
            if ($result) {
                // 触发钩子
                todo_do_action('product_inquiry_replied', $inquiry_id, $reply, $admin_user_id);
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log('回复产品咨询失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 更新咨询状态
     * 
     * @param int $inquiry_id 咨询ID
     * @param string $status 新状态
     * @return bool
     */
    public function update_status($inquiry_id, $status) {
        try {
            $allowed_statuses = ['pending', 'answered', 'closed'];
            if (!in_array($status, $allowed_statuses)) {
                throw new Exception('无效的状态值');
            }
            
            $sql = "UPDATE {$this->table_name} SET status = :status, updated_at = NOW() WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            
            return $stmt->execute([
                'status' => $status,
                'id' => intval($inquiry_id)
            ]);
        } catch (Exception $e) {
            error_log('更新咨询状态失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 删除咨询
     * 
     * @param int $inquiry_id 咨询ID
     * @return bool
     */
    public function delete_inquiry($inquiry_id) {
        try {
            $sql = "DELETE FROM {$this->table_name} WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            
            $result = $stmt->execute(['id' => intval($inquiry_id)]);
            
            if ($result) {
                // 触发钩子
                todo_do_action('product_inquiry_deleted', $inquiry_id);
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log('删除产品咨询失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取咨询统计
     * 
     * @param array $args 统计参数
     * @return array
     */
    public function get_statistics($args = []) {
        try {
            $stats = [];
            
            // 总咨询数
            $sql = "SELECT COUNT(*) as total FROM {$this->table_name}";
            $stmt = $this->db->query($sql);
            $stats['total'] = $stmt->fetchColumn();
            
            // 按状态统计
            $sql = "SELECT status, COUNT(*) as count FROM {$this->table_name} GROUP BY status";
            $stmt = $this->db->query($sql);
            $status_stats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            $stats['pending'] = isset($status_stats['pending']) ? $status_stats['pending'] : 0;
            $stats['answered'] = isset($status_stats['answered']) ? $status_stats['answered'] : 0;
            $stats['closed'] = isset($status_stats['closed']) ? $status_stats['closed'] : 0;
            
            // 今日新增
            $sql = "SELECT COUNT(*) as today FROM {$this->table_name} WHERE DATE(created_at) = CURDATE()";
            $stmt = $this->db->query($sql);
            $stats['today'] = $stmt->fetchColumn();
            
            return $stats;
        } catch (PDOException $e) {
            error_log('获取咨询统计失败: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取客户端IP地址
     * 
     * @return string
     */
    private function get_client_ip() {
        $ip_keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }
}

/**
 * 获取产品咨询管理器实例
 * 
 * @return Todo_Product_Inquiry_Manager
 */
function todo_get_product_inquiry_manager() {
    return Todo_Product_Inquiry_Manager::get_instance();
}

// 初始化产品咨询管理器
Todo_Product_Inquiry_Manager::get_instance();
