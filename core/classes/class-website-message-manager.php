<?php
/**
 * 网站留言管理器（兼容旧版 API）
 *
 * @package TodoCMS
 * @version 1.0.0
 */
class Todo_Website_Message_Manager {
    private static $instance = null;
    private $db;

    private function __construct() {
        global $tododb;
        $this->db = $tododb;
    }

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 获取留言列表
     */
    public function get_messages($args = []) {
        $defaults = [
            'search'  => '',
            'status'  => '',
            'orderby' => 'id',
            'order'   => 'DESC',
            'offset'  => 0,
            'number'  => 20,
        ];
        $args = array_merge($defaults, $args);

        $where = 'WHERE 1=1';
        $params = [];

        if ($args['search'] !== '') {
            $where .= ' AND (name LIKE :kw OR email LIKE :kw OR subject LIKE :kw OR message LIKE :kw)';
            $params[':kw'] = '%' . $args['search'] . '%';
        }
        if ($args['status'] !== '') {
            $where .= ' AND status = :status';
            $params[':status'] = $args['status'];
        }

        $allowed_orderby = ['id','name','email','created_at','status'];
        $orderby = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'id';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT * FROM {$this->db->prefix}website_messages {$where} ORDER BY {$orderby} {$order} LIMIT :offset, :number";
        $params[':offset'] = (int)$args['offset'];
        $params[':number'] = (int)$args['number'];

        $stmt = $this->db->pdo_prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 添加留言
     */
    public function add_message($data) {
        $insert_data = [
            ':name'    => isset($data['name']) ? $data['name'] : '',
            ':email'   => isset($data['user_email']) ? $data['user_email'] : (isset($data['email']) ? $data['email'] : ''),
            ':subject' => isset($data['subject']) ? $data['subject'] : '',
            ':message' => isset($data['message']) ? $data['message'] : '',
            ':status'  => isset($data['status']) ? $data['status'] : 'unread',
        ];

        $sql = "INSERT INTO {$this->db->prefix}website_messages (name, email, subject, message, status, created_at)
                VALUES (:name, :email, :subject, :message, :status, NOW())";

        $stmt = $this->db->pdo_prepare($sql);
        if ($stmt->execute($insert_data)) {
            return $this->db->lastInsertId();
        }
        return false;
    }

    /**
     * 获取单条留言
     */
    public function get_message($message_id) {
        $id = (int)$message_id;
        $sql = "SELECT * FROM {$this->db->prefix}website_messages WHERE id = :id";
        $stmt = $this->db->pdo_prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: false;
    }

    /**
     * 回复留言（兼容旧签名：($message_id, $reply, $admin_user_id)）
     */
    public function reply_message($message_id, $reply_content, $admin_user_id = null) {
        $id = (int)$message_id;

        // 插入回复记录（如果存在回复表）
        if ($this->db->table_exists('website_message_replies')) {
            $sql = "INSERT INTO {$this->db->prefix}website_message_replies (message_id, reply_content, created_at)
                    VALUES (:message_id, :reply_content, NOW())";
            $stmt = $this->db->pdo_prepare($sql);
            $stmt->execute([
                ':message_id'   => $id,
                ':reply_content'=> $reply_content,
            ]);
        }

        // 更新主消息状态与管理员信息
        $set_admin = '';
        $params = [':id' => $id];
        if ($admin_user_id !== null) {
            $set_admin = ', admin_user_id = :admin_user_id, reply_date = NOW()';
            $params[':admin_user_id'] = (int)$admin_user_id;
        }
        $sql = "UPDATE {$this->db->prefix}website_messages SET status = 'replied', updated_at = NOW(){$set_admin} WHERE id = :id";
        $stmt = $this->db->pdo_prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * 更新留言状态
     */
    public function update_status($message_id, $status) {
        $id = (int)$message_id;
        $allowed = ['unread','read','replied','archived','closed','spam'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }
        $sql = "UPDATE {$this->db->prefix}website_messages SET status = :status, updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->pdo_prepare($sql);
        return $stmt->execute([':status' => $status, ':id' => $id]);
    }

    /**
     * 批量操作（兼容旧签名 bulk_action($message_ids, $action, $value = null)）
     */
    public function bulk_action($message_ids, $action = 'delete', $value = null) {
        // 兼容新旧调用顺序
        if (is_string($message_ids) && is_array($action)) {
            $tmp = $message_ids; // action
            $message_ids = $action;
            $action = $tmp;
        }

        if (!is_array($message_ids) || empty($message_ids)) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($message_ids), '?'));
        $ids = array_map('intval', $message_ids);

        switch ($action) {
            case 'delete':
                $sql = "DELETE FROM {$this->db->prefix}website_messages WHERE id IN ($placeholders)";
                $stmt = $this->db->pdo_prepare($sql);
                return $stmt->execute($ids);
            case 'status':
                if (!$value) return false;
                $sql = "UPDATE {$this->db->prefix}website_messages SET status = ?, updated_at = NOW() WHERE id IN ($placeholders)";
                $stmt = $this->db->pdo_prepare($sql);
                array_unshift($ids, $value);
                return $stmt->execute($ids);
            case 'mark_read':
                $sql = "UPDATE {$this->db->prefix}website_messages SET status = 'read', updated_at = NOW() WHERE id IN ($placeholders)";
                $stmt = $this->db->pdo_prepare($sql);
                return $stmt->execute($ids);
            case 'mark_archived':
                $sql = "UPDATE {$this->db->prefix}website_messages SET status = 'archived', updated_at = NOW() WHERE id IN ($placeholders)";
                $stmt = $this->db->pdo_prepare($sql);
                return $stmt->execute($ids);
            case 'spam':
                $sql = "UPDATE {$this->db->prefix}website_messages SET status = 'spam', updated_at = NOW() WHERE id IN ($placeholders)";
                $stmt = $this->db->pdo_prepare($sql);
                return $stmt->execute($ids);
        }
        return false;
    }

    /**
     * 统计信息
     */
    public function get_statistics() {
        $stats = [];
        $stats['total'] = (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->db->prefix}website_messages");
        $stats['unread'] = (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->db->prefix}website_messages WHERE status = 'unread'");
        $stats['replied'] = (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->db->prefix}website_messages WHERE status = 'replied'");
        return $stats;
    }
}

// 兼容全局函数
if (!function_exists('todo_get_website_message_manager')) {
    function todo_get_website_message_manager() {
        return Todo_Website_Message_Manager::get_instance();
    }
}
