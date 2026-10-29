<?php
/**
 * 邮件订阅管理器类
 * 
 * 处理邮件订阅、退订和群发功能
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

class Todo_Email_Subscription_Manager {
    
    /**
     * 单例实例
     * 
     * @var Todo_Email_Subscription_Manager
     */
    private static $instance = null;
    
    /**
     * 数据库连接
     * 
     * @var PDO
     */
    private $db;
    
    /**
     * 订阅表名
     * 
     * @var string
     */
    private $subscribers_table;
    
    /**
     * 邮件记录表名
     * 
     * @var string
     */
    private $emails_table;
    
    /**
     * 构造函数
     */
    private function __construct() {
        global $tododb;
        $this->db = $tododb;
        $this->subscribers_table = 'todo_email_subscribers';
        $this->emails_table = 'todo_email_campaigns';
        
        // 初始化钩子
        $this->init_hooks();
    }
    
    /**
     * 获取单例实例
     * 
     * @return Todo_Email_Subscription_Manager
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
     * 添加订阅者
     * 
     * @param array $data 订阅者数据
     * @return int|false 订阅者ID或false
     */
    public function add_subscriber($data) {
        try {
            // 验证邮箱
            if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                throw new Exception('邮箱格式不正确');
            }
            
            // 检查是否已存在
            if ($this->subscriber_exists($data['email'])) {
                // 如果已存在但状态为未订阅，则重新激活
                return $this->reactivate_subscriber($data['email']);
            }
            
            // 准备数据
            $insert_data = [
                'email' => sanitize_email($data['email']),
                'name' => isset($data['name']) ? sanitize_text_field($data['name']) : null,
                'subscription_type' => isset($data['subscription_type']) ? sanitize_text_field($data['subscription_type']) : 'newsletter',
                'source' => isset($data['source']) ? sanitize_text_field($data['source']) : 'website',
                'ip_address' => $this->get_client_ip(),
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null,
                'confirmation_token' => $this->generate_token(),
                'tags' => isset($data['tags']) ? sanitize_text_field($data['tags']) : null,
                'custom_fields' => isset($data['custom_fields']) ? json_encode($data['custom_fields']) : null,
                'preferences' => isset($data['preferences']) ? json_encode($data['preferences']) : null
            ];
            
            // 如果不需要确认，直接设为已确认
            if (isset($data['auto_confirm']) && $data['auto_confirm']) {
                $insert_data['confirmed_at'] = date('Y-m-d H:i:s');
                $insert_data['status'] = 'active';
            }
            
            // 构建SQL
            $fields = implode(', ', array_keys($insert_data));
            $placeholders = ':' . implode(', :', array_keys($insert_data));
            
            $sql = "INSERT INTO {$this->subscribers_table} ({$fields}) VALUES ({$placeholders})";
            
            if ($this->db->query($sql, $insert_data)) {
                $subscriber_id = $this->db->lastInsertId();
                
                // 触发钩子
                todo_do_action('email_subscriber_added', $subscriber_id, $insert_data);
                
                // 如果需要确认邮件，发送确认邮件
                if (!isset($data['auto_confirm']) || !$data['auto_confirm']) {
                    $this->send_confirmation_email($subscriber_id);
                }
                
                return $subscriber_id;
            }
            
            return false;
        } catch (Exception $e) {
            error_log('添加邮件订阅者失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 检查订阅者是否存在
     * 
     * @param string $email 邮箱地址
     * @return bool
     */
    public function subscriber_exists($email) {
        try {
            $sql = "SELECT COUNT(*) FROM {$this->subscribers_table} WHERE email = :email";
            $count = $this->db->get_var($sql, ['email' => $email]);
            
            return $count > 0;
        } catch (PDOException $e) {
            error_log('检查订阅者存在性失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 重新激活订阅者
     * 
     * @param string $email 邮箱地址
     * @return int|false
     */
    public function reactivate_subscriber($email) {
        try {
            $sql = "UPDATE {$this->subscribers_table} SET 
                        status = 'active', 
                        confirmed_at = NOW(), 
                        unsubscribed_at = NULL,
                        updated_at = NOW()
                    WHERE email = :email";
            
            $stmt = $this->db->prepare($sql);
            if ($stmt->execute(['email' => $email])) {
                // 获取订阅者ID
                $sql = "SELECT id FROM {$this->subscribers_table} WHERE email = :email";
                $stmt = $this->db->prepare($sql);
                $stmt->execute(['email' => $email]);
                
                return $stmt->fetchColumn();
            }
            
            return false;
        } catch (PDOException $e) {
            error_log('重新激活订阅者失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 确认订阅
     * 
     * @param string $token 确认令牌
     * @return bool
     */
    public function confirm_subscription($token) {
        try {
            $sql = "UPDATE {$this->subscribers_table} SET 
                        status = 'active', 
                        confirmed_at = NOW(), 
                        confirmation_token = NULL,
                        updated_at = NOW()
                    WHERE confirmation_token = :token AND status = 'inactive'";
            
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute(['token' => $token]);
            
            if ($result && $stmt->rowCount() > 0) {
                // 触发钩子
                todo_do_action('email_subscription_confirmed', $token);
                return true;
            }
            
            return false;
        } catch (PDOException $e) {
            error_log('确认订阅失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 取消订阅
     * 
     * @param string $email_or_token 邮箱地址或取消订阅令牌
     * @return bool
     */
    public function unsubscribe($email_or_token) {
        try {
            // 判断是邮箱还是令牌
            if (filter_var($email_or_token, FILTER_VALIDATE_EMAIL)) {
                $where_clause = 'email = :identifier';
            } else {
                $where_clause = 'unsubscribe_token = :identifier';
            }
            
            $sql = "UPDATE {$this->subscribers_table} SET 
                        status = 'unsubscribed', 
                        unsubscribed_at = NOW(),
                        updated_at = NOW()
                    WHERE {$where_clause}";
            
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute(['identifier' => $email_or_token]);
            
            if ($result && $stmt->rowCount() > 0) {
                // 触发钩子
                todo_do_action('email_subscription_cancelled', $email_or_token);
                return true;
            }
            
            return false;
        } catch (PDOException $e) {
            error_log('取消订阅失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取订阅者列表
     * 
     * @param array $args 查询参数
     * @return array
     */
    public function get_subscribers($args = []) {
        try {
            $defaults = [
                'status' => null,
                'subscription_type' => null,
                'tags' => null,
                'search' => null,
                'date_from' => null,
                'date_to' => null,
                'limit' => 50,
                'offset' => 0,
                'orderby' => 'created_at',
                'order' => 'DESC'
            ];
            
            $args = array_merge($defaults, $args);
            
            // 构建WHERE条件
            $where_conditions = [];
            $params = [];
            
            if ($args['status']) {
                $where_conditions[] = 'status = :status';
                $params['status'] = $args['status'];
            }
            
            if ($args['subscription_type']) {
                $where_conditions[] = 'subscription_type = :subscription_type';
                $params['subscription_type'] = $args['subscription_type'];
            }
            
            if ($args['tags']) {
                $where_conditions[] = 'tags LIKE :tags';
                $params['tags'] = '%' . $args['tags'] . '%';
            }
            
            if ($args['search']) {
                $where_conditions[] = '(email LIKE :search OR name LIKE :search)';
                $params['search'] = '%' . $args['search'] . '%';
            }
            
            if ($args['date_from']) {
                $where_conditions[] = 'created_at >= :date_from';
                $params['date_from'] = $args['date_from'];
            }
            
            if ($args['date_to']) {
                $where_conditions[] = 'created_at <= :date_to';
                $params['date_to'] = $args['date_to'];
            }
            
            $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
            
            // 构建ORDER BY
            $order_clause = "ORDER BY {$args['orderby']} {$args['order']}";
            
            // 构建LIMIT
            $limit_clause = "LIMIT {$args['limit']} OFFSET {$args['offset']}";
            
            $sql = "SELECT * FROM {$this->subscribers_table} {$where_clause} {$order_clause} {$limit_clause}";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('获取订阅者列表失败: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 创建邮件活动
     * 
     * @param array $data 活动数据
     * @return int|false 活动ID或false
     */
    public function create_campaign($data) {
        try {
            // 验证必需字段
            $required_fields = ['campaign_name', 'subject', 'content'];
            foreach ($required_fields as $field) {
                if (empty($data[$field])) {
                    throw new Exception("缺少必需字段: {$field}");
                }
            }
            
            // 准备数据
            $insert_data = [
                'campaign_name' => sanitize_text_field($data['campaign_name']),
                'subject' => sanitize_text_field($data['subject']),
                'content' => $data['content'], // 保持HTML格式
                'content_type' => isset($data['content_type']) ? $data['content_type'] : 'html',
                'sender_name' => isset($data['sender_name']) ? sanitize_text_field($data['sender_name']) : null,
                'sender_email' => isset($data['sender_email']) ? sanitize_email($data['sender_email']) : null,
                'reply_to' => isset($data['reply_to']) ? sanitize_email($data['reply_to']) : null,
                'target_type' => isset($data['target_type']) ? $data['target_type'] : 'active',
                'target_criteria' => isset($data['target_criteria']) ? json_encode($data['target_criteria']) : null,
                'scheduled_at' => isset($data['scheduled_at']) ? $data['scheduled_at'] : null,
                'created_by' => isset($data['created_by']) ? intval($data['created_by']) : null,
                'template_id' => isset($data['template_id']) ? intval($data['template_id']) : null,
                'tracking_enabled' => isset($data['tracking_enabled']) ? intval($data['tracking_enabled']) : 1
            ];
            
            // 构建SQL
            $fields = implode(', ', array_keys($insert_data));
            $placeholders = ':' . implode(', :', array_keys($insert_data));
            
            $sql = "INSERT INTO {$this->emails_table} ({$fields}) VALUES ({$placeholders})";
            $stmt = $this->db->prepare($sql);
            
            if ($stmt->execute($insert_data)) {
                $campaign_id = $this->db->lastInsertId();
                
                // 计算目标收件人数量
                $this->update_campaign_recipients_count($campaign_id);
                
                // 触发钩子
                todo_do_action('email_campaign_created', $campaign_id, $insert_data);
                
                return $campaign_id;
            }
            
            return false;
        } catch (Exception $e) {
            error_log('创建邮件活动失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 发送邮件活动
     * 
     * @param int $campaign_id 活动ID
     * @return bool
     */
    public function send_campaign($campaign_id) {
        try {
            // 获取活动信息
            $campaign = $this->get_campaign($campaign_id);
            if (!$campaign) {
                throw new Exception('活动不存在');
            }
            
            if ($campaign['status'] !== 'draft' && $campaign['status'] !== 'scheduled') {
                throw new Exception('活动状态不允许发送');
            }
            
            // 获取目标收件人
            $recipients = $this->get_campaign_recipients($campaign_id);
            if (empty($recipients)) {
                throw new Exception('没有找到目标收件人');
            }
            
            // 更新活动状态为发送中
            $this->update_campaign_status($campaign_id, 'sending', ['started_at' => date('Y-m-d H:i:s')]);
            
            $sent_count = 0;
            $failed_count = 0;
            
            foreach ($recipients as $recipient) {
                try {
                    // 个性化内容
                    $personalized_content = $this->personalize_content($campaign['content'], $recipient);
                    
                    // 发送邮件
                    $result = $this->send_email(
                        $recipient['email'],
                        $campaign['subject'],
                        $personalized_content,
                        $campaign['sender_email'],
                        $campaign['sender_name']
                    );
                    
                    if ($result) {
                        $sent_count++;
                        // 更新订阅者最后发送时间
                        $this->update_subscriber_last_email($recipient['id']);
                    } else {
                        $failed_count++;
                    }
                } catch (Exception $e) {
                    $failed_count++;
                    error_log('发送邮件失败: ' . $e->getMessage());
                }
            }
            
            // 更新活动统计和状态
            $this->update_campaign_status($campaign_id, 'sent', [
                'sent_count' => $sent_count,
                'completed_at' => date('Y-m-d H:i:s')
            ]);
            
            // 触发钩子
            todo_do_action('email_campaign_sent', $campaign_id, $sent_count, $failed_count);
            
            return true;
        } catch (Exception $e) {
            error_log('发送邮件活动失败: ' . $e->getMessage());
            // 更新活动状态为失败
            $this->update_campaign_status($campaign_id, 'cancelled');
            return false;
        }
    }
    
    /**
     * 获取活动信息
     * 
     * @param int $campaign_id 活动ID
     * @return array|false
     */
    public function get_campaign($campaign_id) {
        try {
            $sql = "SELECT * FROM {$this->emails_table} WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => intval($campaign_id)]);
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('获取邮件活动失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取活动收件人
     * 
     * @param int $campaign_id 活动ID
     * @return array
     */
    private function get_campaign_recipients($campaign_id) {
        $campaign = $this->get_campaign($campaign_id);
        if (!$campaign) {
            return [];
        }
        
        $args = ['status' => 'active'];
        
        // 根据目标类型筛选
        switch ($campaign['target_type']) {
            case 'all':
                // 所有订阅者
                break;
            case 'tags':
                if ($campaign['target_criteria']) {
                    $criteria = json_decode($campaign['target_criteria'], true);
                    if (isset($criteria['tags'])) {
                        $args['tags'] = $criteria['tags'];
                    }
                }
                break;
            case 'custom':
                // 自定义条件
                if ($campaign['target_criteria']) {
                    $criteria = json_decode($campaign['target_criteria'], true);
                    $args = array_merge($args, $criteria);
                }
                break;
        }
        
        return $this->get_subscribers($args);
    }
    
    /**
     * 更新活动收件人数量
     * 
     * @param int $campaign_id 活动ID
     * @return bool
     */
    private function update_campaign_recipients_count($campaign_id) {
        try {
            $recipients = $this->get_campaign_recipients($campaign_id);
            $count = count($recipients);
            
            $sql = "UPDATE {$this->emails_table} SET total_recipients = :count WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            
            return $stmt->execute([
                'count' => $count,
                'id' => $campaign_id
            ]);
        } catch (PDOException $e) {
            error_log('更新活动收件人数量失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 更新活动状态
     * 
     * @param int $campaign_id 活动ID
     * @param string $status 新状态
     * @param array $extra_data 额外数据
     * @return bool
     */
    private function update_campaign_status($campaign_id, $status, $extra_data = []) {
        try {
            $update_fields = ['status = :status', 'updated_at = NOW()'];
            $params = ['status' => $status, 'id' => $campaign_id];
            
            foreach ($extra_data as $field => $value) {
                $update_fields[] = "{$field} = :{$field}";
                $params[$field] = $value;
            }
            
            $sql = "UPDATE {$this->emails_table} SET " . implode(', ', $update_fields) . " WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log('更新活动状态失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 个性化邮件内容
     * 
     * @param string $content 邮件内容
     * @param array $subscriber 订阅者信息
     * @return string
     */
    private function personalize_content($content, $subscriber) {
        $replacements = [
            '{{name}}' => $subscriber['name'] ?: '订阅者',
            '{{email}}' => $subscriber['email'],
            '{{unsubscribe_url}}' => $this->get_unsubscribe_url($subscriber['email'])
        ];
        
        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }
    
    /**
     * 发送邮件
     * 
     * @param string $to 收件人
     * @param string $subject 主题
     * @param string $content 内容
     * @param string $from_email 发件人邮箱
     * @param string $from_name 发件人姓名
     * @return bool
     */
    private function send_email($to, $subject, $content, $from_email = null, $from_name = null) {
        // 这里应该集成实际的邮件发送服务
        // 比如 PHPMailer、SendGrid、阿里云邮件推送等
        
        // 临时使用PHP的mail函数作为示例
        $headers = [];
        
        if ($from_email) {
            $from = $from_name ? "{$from_name} <{$from_email}>" : $from_email;
            $headers[] = "From: {$from}";
        }
        
        $headers[] = "Content-Type: text/html; charset=UTF-8";
        $headers[] = "MIME-Version: 1.0";
        
        return mail($to, $subject, $content, implode("\r\n", $headers));
    }
    
    /**
     * 发送确认邮件
     * 
     * @param int $subscriber_id 订阅者ID
     * @return bool
     */
    private function send_confirmation_email($subscriber_id) {
        try {
            $subscriber = $this->get_subscriber($subscriber_id);
            if (!$subscriber) {
                return false;
            }
            
            $confirmation_url = $this->get_confirmation_url($subscriber['confirmation_token']);
            
            $subject = '请确认您的邮件订阅';
            $content = "
                <h2>确认邮件订阅</h2>
                <p>感谢您订阅我们的邮件！</p>
                <p>请点击下面的链接确认您的订阅：</p>
                <p><a href='{$confirmation_url}'>确认订阅</a></p>
                <p>如果您没有订阅我们的邮件，请忽略此邮件。</p>
            ";
            
            return $this->send_email($subscriber['email'], $subject, $content);
        } catch (Exception $e) {
            error_log('发送确认邮件失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取订阅者信息
     * 
     * @param int $subscriber_id 订阅者ID
     * @return array|false
     */
    public function get_subscriber($subscriber_id) {
        try {
            $sql = "SELECT * FROM {$this->subscribers_table} WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => intval($subscriber_id)]);
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('获取订阅者信息失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 更新订阅者最后发送邮件时间
     * 
     * @param int $subscriber_id 订阅者ID
     * @return bool
     */
    private function update_subscriber_last_email($subscriber_id) {
        try {
            $sql = "UPDATE {$this->subscribers_table} SET last_email_sent = NOW() WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            
            return $stmt->execute(['id' => $subscriber_id]);
        } catch (PDOException $e) {
            error_log('更新订阅者最后发送时间失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取确认链接
     * 
     * @param string $token 确认令牌
     * @return string
     */
    private function get_confirmation_url($token) {
        $path = "/email/confirm/{$token}";
        $base = function_exists('todo_home_url') ? todo_home_url($path) : (function_exists('home_url') ? home_url($path) : $path);
        $locale = function_exists('current_locale') ? current_locale() : '';
        return (function_exists('get_language_url') && !empty($locale)) ? get_language_url($locale, $base) : $base;
    }
    
    /**
     * 获取取消订阅链接
     * 
     * @param string $email 邮箱地址
     * @return string
     */
    private function get_unsubscribe_url($email) {
        $token = base64_encode($email);
        $path = "/index.php?todo_action=unsubscribe&token=" . urlencode($token);
        $base = function_exists('todo_get_site_url') ? todo_get_site_url($path) : (function_exists('todo_home_url') ? todo_home_url($path) : $path);
        return $base;
    }
    
    /**
     * 生成令牌
     * 
     * @return string
     */
    private function generate_token() {
        return bin2hex(random_bytes(32));
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
    
    /**
     * 获取订阅统计
     * 
     * @return array
     */
    public function get_statistics() {
        try {
            $stats = [];
            
            // 总订阅者数
            $sql = "SELECT COUNT(*) as total FROM {$this->subscribers_table}";
            $stmt = $this->db->query($sql);
            $stats['total_subscribers'] = $stmt->fetchColumn();
            
            // 按状态统计
            $sql = "SELECT status, COUNT(*) as count FROM {$this->subscribers_table} GROUP BY status";
            $stmt = $this->db->query($sql);
            $status_stats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            $stats['active'] = isset($status_stats['active']) ? $status_stats['active'] : 0;
            $stats['inactive'] = isset($status_stats['inactive']) ? $status_stats['inactive'] : 0;
            $stats['unsubscribed'] = isset($status_stats['unsubscribed']) ? $status_stats['unsubscribed'] : 0;
            $stats['bounced'] = isset($status_stats['bounced']) ? $status_stats['bounced'] : 0;
            
            // 今日新增订阅者
            $sql = "SELECT COUNT(*) as today FROM {$this->subscribers_table} WHERE DATE(created_at) = CURDATE()";
            $stmt = $this->db->query($sql);
            $stats['today_new'] = $stmt->fetchColumn();
            
            // 邮件活动统计
            $sql = "SELECT COUNT(*) as total_campaigns FROM {$this->emails_table}";
            $stmt = $this->db->query($sql);
            $stats['total_campaigns'] = $stmt->fetchColumn();
            
            return $stats;
        } catch (PDOException $e) {
            error_log('获取订阅统计失败: ' . $e->getMessage());
            return [];
        }
    }
}

/**
 * 获取邮件订阅管理器实例
 * 
 * @return Todo_Email_Subscription_Manager
 */
function todo_get_email_subscription_manager() {
    return Todo_Email_Subscription_Manager::get_instance();
}

// 初始化邮件订阅管理器
Todo_Email_Subscription_Manager::get_instance();
