<?php
/**
 * TodoCMS 评论管理器
 * 
 * 管理评论系统功能，包括评论的创建、审核、回复和管理
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    exit('Direct access not permitted');
}

if (!class_exists('Todo_Comment_Manager')) {
    class Todo_Comment_Manager {
        
        /**
         * 数据库连接
         * @var CMS_DB
         */
        private static $tododb;
        
        /**
         * 评论状态常量
         */
        const STATUS_PENDING = 0;       // 待审核
        const STATUS_APPROVED = 1;      // 已通过
        const STATUS_SPAM = -1;         // 垃圾评论
        const STATUS_TRASH = -2;        // 已删除
        
        /**
         * 评论类型常量
         */
        const TYPE_COMMENT = 'comment';     // 普通评论
        const TYPE_REVIEW = 'review';       // 商品评价
        const TYPE_INQUIRY = 'inquiry';     // 产品咨询
        const TYPE_MESSAGE = 'message';     // 留言 (Guestbook)
        const TYPE_REPLY = 'reply';         // 回复
        const TYPE_PINGBACK = 'pingback';   // 引用通告
        const TYPE_TRACKBACK = 'trackback'; // 引用
        
        /**
         * 评论缓存
         * @var array
         */
        private static $comments_cache = array();
        
        /**
         * 初始化评论管理器
         */
        public static function init() {
            global $tododb;
            self::$tododb = $tododb;
        }

        public function __construct() {
            self::init();
        }

        private static function get_db() {
            if (self::$tododb) {
                return self::$tododb;
            }

            if (isset($GLOBALS['tododb']) && $GLOBALS['tododb']) {
                self::$tododb = $GLOBALS['tododb'];
                return self::$tododb;
            }

            if (class_exists('CMS_DB')) {
                try {
                    $db = new CMS_DB();
                    self::$tododb = $db;
                    $GLOBALS['tododb'] = $db;
                    return $db;
                } catch (Throwable $e) {
                    return null;
                }
            }

            return null;
        }

        /**
         * 将评论结果转换为可安全扩展的输出节点，避免对自定义对象追加动态属性。
         *
         * @param mixed $comment
         * @return mixed
         */
        private static function normalize_comment_node($comment) {
            if (is_array($comment)) {
                return $comment;
            }
            if ($comment instanceof stdClass) {
                return clone $comment;
            }
            if (is_object($comment)) {
                return (object) get_object_vars($comment);
            }
            return $comment;
        }

        /**
         * 向评论输出节点写入扩展字段。
         *
         * @param mixed $comment
         * @param string $field
         * @param mixed $value
         * @return mixed
         */
        private static function with_comment_field($comment, $field, $value) {
            if (is_array($comment)) {
                $comment[$field] = $value;
                return $comment;
            }
            if (is_object($comment)) {
                $comment->$field = $value;
            }
            return $comment;
        }
        
        /**
         * 创建评论
         * 
         * @param array $comment_data 评论数据
         * @return bool|int 成功返回评论ID，失败返回false
         */
        public static function create_comment($comment_data) {
            $db = self::get_db();
            if (!$db) {
                return false;
            }
            if (empty($comment_data['content']) || empty($comment_data['post_id'])) {
                return false;
            }
            
            $moderation_enabled = function_exists('get_option') ? ((int) get_option('comment_moderation', 0) === 1) : false;

            if (!isset($comment_data['status']) || $comment_data['status'] === null || (isset($comment_data['type']) && $comment_data['type'] === self::TYPE_INQUIRY)) {
                $comment_data['status'] = self::STATUS_APPROVED;
            }
            $defaults = array(
                'post_id' => 0,
                'parent_id' => 0,
                'user_id' => 0,
                'author_name' => '',
                'author_email' => '',
                'author_url' => '',
                'author_ip' => todo_get_client_ip(),
                'content' => '',
                'type' => self::TYPE_COMMENT,
                'status' => $moderation_enabled ? self::STATUS_PENDING : self::STATUS_APPROVED,
                'rating' => 0,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
            );
            
            $comment_data = array_merge($defaults, $comment_data);
            
            // 内容过滤
            $comment_data['content'] = todo_sanitize_text($comment_data['content']);
            $comment_data['author_name'] = todo_sanitize_text($comment_data['author_name']);
            $comment_data['author_email'] = todo_sanitize_email($comment_data['author_email']);
            $comment_data['author_url'] = todo_esc_url($comment_data['author_url']);
            
            // 垃圾评论检测
            if (self::is_spam($comment_data)) {
                $comment_data['status'] = self::STATUS_SPAM;
            }
            
            // 自动审核检测
            if ($comment_data['status'] == self::STATUS_PENDING && self::should_auto_approve($comment_data)) {
                $comment_data['status'] = self::STATUS_APPROVED;
            }
            
            $query = "INSERT INTO " . DB_PREFIX . "comments 
                    (comment_post_ID, comment_parent, user_id, comment_author, comment_author_email, comment_author_url, 
                    comment_author_IP, comment_content, comment_type, comment_approved, comment_rating, comment_agent, comment_date) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = self::$tododb->query($query, [
                $comment_data['post_id'],
                $comment_data['parent_id'],
                $comment_data['user_id'],
                $comment_data['author_name'],
                $comment_data['author_email'],
                $comment_data['author_url'],
                $comment_data['author_ip'],
                $comment_data['content'],
                $comment_data['type'],
                $comment_data['status'],
                $comment_data['rating'],
                $comment_data['user_agent']
            ]);
            $executed = $stmt && $stmt->rowCount() > 0;
            
            if ($executed) {
                $comment_id = self::$tododb->insert_id();
                
                // 保存自定义字段
                if (!empty($comment_data['meta'])) {
                    foreach ($comment_data['meta'] as $meta_key => $meta_value) {
                        self::save_comment_meta($comment_id, $meta_key, $meta_value);
                    }
                }
                
                // 更新文章评论数
                self::update_post_comment_count($comment_data['post_id']);
                
                // 发送管理员通知（无论是否需审核均即时通知）
                self::send_comment_notifications($comment_id);
                
                return $comment_id;
            }
            
            return false;
        }
        
        /**
         * 更新评论
         * 
         * @param int $comment_id 评论ID
         * @param array $comment_data 评论数据
         * @return bool
         */
        public static function update_comment($comment_id, $comment_data) {
            $set_clauses = array();
            $params = array();
            $types = '';
            
            // 可更新的字段
            $updatable_fields = array(
                'author_name' => 's',
                'author_email' => 's',
                'author_url' => 's',
                'content' => 's',
                'status' => 'i',
                'rating' => 'i'
            );
            
            // 字段映射：内部字段名 => 数据库字段名
            $field_mapping = array(
                'author_name' => 'comment_author',
                'author_email' => 'comment_author_email',
                'author_url' => 'comment_author_url',
                'content' => 'comment_content',
                'status' => 'comment_approved',
                'rating' => 'comment_rating'
            );
            
            foreach ($updatable_fields as $field => $type) {
                if (isset($comment_data[$field])) {
                    // 内容过滤
                    if ($field === 'content') {
                        $comment_data[$field] = todo_sanitize_text($comment_data[$field]);
                    } elseif ($field === 'author_name') {
                        $comment_data[$field] = todo_sanitize_text($comment_data[$field]);
                    } elseif ($field === 'author_email') {
                        $comment_data[$field] = todo_sanitize_email($comment_data[$field]);
                    } elseif ($field === 'author_url') {
                        $comment_data[$field] = todo_esc_url($comment_data[$field]);
                    }
                    
                    $db_field = isset($field_mapping[$field]) ? $field_mapping[$field] : $field;
                    $set_clauses[] = "{$db_field} = ?";
                    $params[] = $comment_data[$field];
                    $types .= $type;
                }
            }
            
            if (empty($set_clauses)) {
                return false;
            }
            
            $set_clauses[] = 'updated_at = NOW()';
            $params[] = $comment_id;
            $types .= 'i';
            
            $query = "UPDATE " . DB_PREFIX . "comments SET " . implode(', ', $set_clauses) . " WHERE comment_ID = ?";
            $stmt = self::$tododb->query($query, $params);
            $executed = $stmt && $stmt->rowCount() > 0;
            
            if ($executed) {
                // 更新自定义字段
                if (!empty($comment_data['meta'])) {
                    foreach ($comment_data['meta'] as $meta_key => $meta_value) {
                        self::save_comment_meta($comment_id, $meta_key, $meta_value);
                    }
                }
                
                // 清除缓存
                unset(self::$comments_cache[$comment_id]);
                
                // 如果状态改变，发送通知
                if (isset($comment_data['status']) && $comment_data['status'] == self::STATUS_APPROVED) {
                    self::send_comment_notifications($comment_id);
                }
                
                return true;
            }
            
            return false;
        }
        
        /**
         * 删除评论
         * 
         * @param int $comment_id 评论ID
         * @param bool $force_delete 是否强制删除
         * @return bool
         */
        public static function delete_comment($comment_id, $force_delete = false) {
            if ($force_delete) {
                // 永久删除
                $comment = self::get_comment($comment_id);
                if (!$comment) {
                    return false;
                }
                
                // 开始事务
                self::$tododb->begin_transaction();
                
                try {
                    $all_ids = [intval($comment_id)];
                    $queue = [intval($comment_id)];

                    while (!empty($queue)) {
                        $parent_id = array_shift($queue);
                        $child_ids = self::$tododb->get_col(
                            "SELECT comment_ID FROM " . DB_PREFIX . "comments WHERE comment_parent = ?",
                            [$parent_id]
                        );
                        if (!empty($child_ids)) {
                            foreach ($child_ids as $cid) {
                                $cid = intval($cid);
                                if ($cid <= 0) {
                                    continue;
                                }
                                if (in_array($cid, $all_ids, true)) {
                                    continue;
                                }
                                $all_ids[] = $cid;
                                $queue[] = $cid;
                            }
                        }
                    }

                    // 关联清理：检查是否包含电商评价 (review)，清理对应的 order_reviews、product_reviews 与订单 reviewed 标记
                    foreach ($all_ids as $cid) {
                        $order_id = intval(self::get_comment_meta($cid, 'order_id'));
                        if ($order_id <= 0) {
                            $order_id = intval(self::$tododb->get_var(
                                "SELECT meta_value FROM " . DB_PREFIX . "commentmeta WHERE comment_id = ? AND meta_key = 'order_id'",
                                [$cid]
                            ));
                        }
                        if ($order_id > 0) {
                            // 删除 todo_order_reviews 中的对应记录
                            self::$tododb->query(
                                "DELETE FROM " . DB_PREFIX . "order_reviews WHERE order_id = ?",
                                [$order_id]
                            );
                            // 删除 todo_product_reviews 中的对应记录
                            self::$tododb->query(
                                "DELETE FROM " . DB_PREFIX . "product_reviews WHERE order_id = ?",
                                [$order_id]
                            );
                            // 恢复 todo_orders 表中的 reviewed 为 0
                            self::$tododb->query(
                                "UPDATE " . DB_PREFIX . "orders SET reviewed = 0 WHERE id = ?",
                                [$order_id]
                            );
                        }
                    }

                    $placeholders = str_repeat('?,', count($all_ids) - 1) . '?';

                    // 删除评论元数据（包含所有子评论）
                    self::$tododb->query(
                        "DELETE FROM " . DB_PREFIX . "commentmeta WHERE comment_id IN ({$placeholders})",
                        $all_ids
                    );

                    // 删除评论（先删子后删父，避免潜在外键约束）
                    $ids_desc = array_reverse($all_ids);
                    $placeholders_desc = str_repeat('?,', count($ids_desc) - 1) . '?';
                    self::$tododb->query(
                        "DELETE FROM " . DB_PREFIX . "comments WHERE comment_ID IN ({$placeholders_desc})",
                        $ids_desc
                    );
                    
                    $executed = true;
                    
                    if (!$executed) {
                        throw new Exception('Failed to delete comment');
                    }
                    
                    // 提交事务
                    self::$tododb->commit();
                    
                    // 更新文章评论数
                    $post_id = 0;
                    if (isset($comment['post_id'])) {
                        $post_id = intval($comment['post_id']);
                    } elseif (isset($comment['comment_post_ID'])) {
                        $post_id = intval($comment['comment_post_ID']);
                    } elseif (isset($comment['comment_post_id'])) {
                        $post_id = intval($comment['comment_post_id']);
                    }
                    if ($post_id > 0) {
                        self::update_post_comment_count($post_id);
                    }
                    
                    // 清除缓存
                    unset(self::$comments_cache[$comment_id]);
                    
                    return true;
                    
                } catch (Exception $e) {
                    // 回滚事务
                    self::$tododb->rollback();
                    todo_log_error('Comment deletion failed: ' . $e->getMessage());
                    return false;
                }
            } else {
                // 移到回收站
                return self::update_comment($comment_id, array('status' => self::STATUS_TRASH));
            }
        }
        
        /**
         * 获取评论信息
         * 
         * @param int $comment_id 评论ID
         * @return object|null
         */
        public static function get_comment($comment_id) {
            $comment_id = intval($comment_id);
            if ($comment_id <= 0) {
                return null;
            }

            if (isset(self::$comments_cache[$comment_id])) {
                return self::$comments_cache[$comment_id];
            }

            $db = self::get_db();
            if (!$db || !method_exists($db, 'get_row')) {
                return null;
            }

            $row = null;
            try {
                $query = "SELECT * FROM " . DB_PREFIX . "comments WHERE comment_ID = ?";
                $row = $db->get_row($query, [$comment_id], 'ARRAY_A');
            } catch (Throwable $e) {
                $row = null;
            }
            
            if ($row) {
                // 获取评论元数据
                try {
                    $row['meta'] = self::get_comment_meta($comment_id);
                } catch (Throwable $e) {
                    $row['meta'] = array();
                }
                
                // 获取作者信息
                if (isset($row['user_id']) && intval($row['user_id']) > 0) {
                    $row['author'] = todo_get_user($row['user_id']);
                }
                
                self::$comments_cache[$comment_id] = $row;
                return $row;
            }
            
            return null;
        }
        
        /**
         * 获取评论列表
         * 
         * @param array $args 查询参数
         * @return array|int
         */
        public static function get_comments($args = array()) {
            // 默认参数
            $defaults = array(
                'post_id' => null,
                'parent_id' => null,
                'parent' => null,
                'user_id' => null,
                'type' => null,
                'status' => null,
                'author_email' => null,
                'keyword' => null,
                'date_from' => null,
                'date_to' => null,
                'has_children' => null,
                'order_by' => 'created_at',
                'order' => 'DESC',
                'limit' => 20,
                'offset' => 0,
                'include_meta' => false,
                'include_children' => false
            );
            
            $args = array_merge($defaults, $args);

            // 如果设置了 count 参数，则返回总数
            if (isset($args['count']) && $args['count']) {
                return self::get_comments_count($args);
            }
            
            $where_clauses = array();
            $params = array();
            $types = '';
            
            // 构建WHERE条件
            if ($args['post_id'] !== null) {
                if (is_array($args['post_id'])) {
                    $placeholders = str_repeat('?,', count($args['post_id']) - 1) . '?';
                    $where_clauses[] = "c.comment_post_ID IN ({$placeholders})";
                    $params = array_merge($params, $args['post_id']);
                } else {
                    $where_clauses[] = 'c.comment_post_ID = ?';
                    $params[] = intval($args['post_id']);
                }
            }
            
            if ($args['parent_id'] !== null) {
                $where_clauses[] = 'c.comment_parent = ?';
                $params[] = intval($args['parent_id']);
            } elseif (isset($args['parent']) && $args['parent'] !== null) { // 兼容 parent 参数
                $where_clauses[] = 'c.comment_parent = ?';
                $params[] = intval($args['parent']);
            }
            
            if ($args['user_id'] !== null) {
                $where_clauses[] = 'c.user_id = ?';
                $params[] = intval($args['user_id']);
            }
            
            if (!empty($args['type'])) {
                if (is_array($args['type'])) {
                    $type_arr = (array)$args['type'];
                    $placeholders = str_repeat('?,', count($type_arr) - 1) . '?';
                    $where_clauses[] = "c.comment_type IN ({$placeholders})";
                    $params = array_merge($params, $type_arr);
                } else {
                    $where_clauses[] = 'c.comment_type = ?';
                    $params[] = $args['type'];
                }
            }
            
            if ($args['status'] !== null && $args['status'] !== 'all' && $args['status'] !== '') {
                $status_val = $args['status'];
                if (is_string($status_val)) {
                    $status_mapping = array(
                        'approve' => '1',
                        'approved' => '1',
                        'hold' => '0',
                        'pending' => '0',
                        'spam' => array('spam', '-1'),
                        'trash' => array('trash', '-2')
                    );
                    $status_val = isset($status_mapping[$status_val]) ? $status_mapping[$status_val] : $status_val;
                }
                
                if (is_array($status_val)) {
                    $status_arr = $status_val;
                    $placeholders = str_repeat("?,", count($status_arr) - 1) . "?";
                    $where_clauses[] = "c.comment_approved IN ({$placeholders})";
                    $params = array_merge($params, $status_arr);
                } else {
                    $where_clauses[] = 'c.comment_approved = ?';
                    $params[] = (string)$status_val;
                }
            }
            
            if (!empty($args['author_email'])) {
                $where_clauses[] = 'c.comment_author_email = ?';
                $params[] = $args['author_email'];
            }
            
            if (!empty($args['rating'])) {
                $where_clauses[] = 'c.comment_rating = ?';
                $params[] = $args['rating'];
            }
            
            if (!empty($args['keyword'])) {
                $where_clauses[] = '(c.comment_content LIKE ? OR c.comment_author LIKE ?)';
                $keyword = '%' . $args['keyword'] . '%';
                $params[] = $keyword;
                $params[] = $keyword;
            }
            
            if (!empty($args['date_from'])) {
                $where_clauses[] = 'c.comment_date >= ?';
                $params[] = $args['date_from'];
            }
            
            if (!empty($args['date_to'])) {
                $where_clauses[] = 'c.comment_date <= ?';
                $params[] = $args['date_to'] . ' 23:59:59';
            }

            if ($args['has_children'] === true) {
                $where_clauses[] = 'EXISTS (SELECT 1 FROM ' . DB_PREFIX . 'comments cc WHERE cc.comment_parent = c.comment_ID)';
            } elseif ($args['has_children'] === false) {
                $where_clauses[] = 'NOT EXISTS (SELECT 1 FROM ' . DB_PREFIX . 'comments cc WHERE cc.comment_parent = c.comment_ID)';
            }
            
            $where_sql = empty($where_clauses) ? '' : 'WHERE ' . implode(' AND ', $where_clauses);
            
            // 构建ORDER BY
            $order_mapping = [
                'id' => 'comment_ID',
                'post_id' => 'comment_post_ID',
                'author_name' => 'comment_author',
                'created_at' => 'comment_date',
                'status' => 'comment_approved',
                'rating' => 'comment_rating'
            ];
            $order_by = isset($order_mapping[$args['order_by']]) ? $order_mapping[$args['order_by']] : 'comment_date';
            $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
            $order_sql = "ORDER BY c.{$order_by} {$order}";
            
            // 构建LIMIT
            $limit = intval($args['limit']);
            $offset = intval($args['offset']);
            $limit_sql = "LIMIT {$offset}, {$limit}";
            
            $query = "SELECT c.*, p.post_title as target_title 
                    FROM " . DB_PREFIX . "comments c
                    LEFT JOIN " . DB_PREFIX . "posts p ON c.comment_post_ID = p.ID 
                    {$where_sql} {$order_sql} {$limit_sql}";
            
            $db = self::get_db();
            if (!$db) return array();

            // 执行查询并返回对象列表 (保持与 CMS_Comment 兼容)
            $rows = $db->get_results($query, $params, 'OBJECT');
            
            $comments = array();
            if (!empty($rows)) {
                foreach ($rows as $row) {
                    $row = self::normalize_comment_node($row);
                    $comment_id = intval(is_object($row) ? $row->comment_ID : ($row['comment_ID'] ?? 0));
                    
                    // 获取评论元数据
                    if ($args['include_meta']) {
                        $row = self::with_comment_field($row, 'meta', self::get_comment_meta($comment_id));
                    }
                    
                    // 获取作者信息
                    $row_user_id = is_object($row) ? ($row->user_id ?? 0) : ($row['user_id'] ?? 0);
                    if ($row_user_id > 0) {
                        $row = self::with_comment_field($row, 'author', function_exists('todo_get_user') ? todo_get_user($row_user_id) : null);
                    }
                    
                    // 获取子评论 (多级支持)
                    if ($args['include_children']) {
                        $children_args = $args;
                        $children_args['parent_id'] = $comment_id;
                        $row = self::with_comment_field($row, 'children', self::get_comments($children_args));
                    }
                    
                    $comments[] = $row;
                }
            }
            
            return $comments;
        }
        
        /**
         * 获取评论数量
         * 
         * @param array $args 查询参数
         * @return int
         */
        public static function get_comments_count($args = array()) {
            $db = self::get_db();
            if (!$db) return 0;

            $where_clauses = array();
            $params = array();
            
            // 构建WHERE条件
            if (isset($args['post_id']) && $args['post_id'] !== null) {
                if (is_array($args['post_id'])) {
                    $placeholders = str_repeat('?,', count($args['post_id']) - 1) . '?';
                    $where_clauses[] = "comment_post_ID IN ({$placeholders})";
                    $params = array_merge($params, $args['post_id']);
                } else {
                    $where_clauses[] = 'comment_post_ID = ?';
                    $params[] = intval($args['post_id']);
                }
            }

            if (isset($args['parent_id']) && $args['parent_id'] !== null) {
                $where_clauses[] = 'comment_parent = ?';
                $params[] = intval($args['parent_id']);
            } elseif (isset($args['parent']) && $args['parent'] !== null) {
                $where_clauses[] = 'comment_parent = ?';
                $params[] = intval($args['parent']);
            }

            if (isset($args['user_id']) && $args['user_id'] !== null) {
                $where_clauses[] = 'user_id = ?';
                $params[] = intval($args['user_id']);
            }

            if (isset($args['author_email']) && !empty($args['author_email'])) {
                $where_clauses[] = 'comment_author_email = ?';
                $params[] = (string) $args['author_email'];
            }

            if (isset($args['rating']) && $args['rating'] !== null && $args['rating'] !== '') {
                $where_clauses[] = 'comment_rating = ?';
                $params[] = intval($args['rating']);
            }

            if (isset($args['keyword']) && $args['keyword'] !== null && $args['keyword'] !== '') {
                $where_clauses[] = '(comment_content LIKE ? OR comment_author LIKE ?)';
                $keyword = '%' . $args['keyword'] . '%';
                $params[] = $keyword;
                $params[] = $keyword;
            }

            if (isset($args['date_from']) && $args['date_from'] !== null && $args['date_from'] !== '') {
                $where_clauses[] = 'comment_date >= ?';
                $params[] = $args['date_from'];
            }

            if (isset($args['date_to']) && $args['date_to'] !== null && $args['date_to'] !== '') {
                $where_clauses[] = 'comment_date <= ?';
                $params[] = $args['date_to'] . ' 23:59:59';
            }

            if (isset($args['status']) && $args['status'] !== null && $args['status'] !== 'all' && $args['status'] !== '') {
                $status_val = $args['status'];
                if (is_string($status_val)) {
                    $status_mapping = array(
                        'approve' => '1',
                        'approved' => '1',
                        'hold' => '0',
                        'pending' => '0',
                        'spam' => array('spam', '-1'),
                        'trash' => array('trash', '-2')
                    );
                    $status_val = isset($status_mapping[$status_val]) ? $status_mapping[$status_val] : $status_val;
                }

                if (is_array($status_val)) {
                    $placeholders = str_repeat('?,', count($status_val) - 1) . '?';
                    $where_clauses[] = "comment_approved IN ({$placeholders})";
                    $params = array_merge($params, $status_val);
                } else {
                    $where_clauses[] = 'comment_approved = ?';
                    $params[] = (string) $status_val;
                }
            }

            if (isset($args['type']) && !empty($args['type'])) {
                if (is_array($args['type'])) {
                    $placeholders = str_repeat('?,', count($args['type']) - 1) . '?';
                    $where_clauses[] = "comment_type IN ({$placeholders})";
                    $params = array_merge($params, $args['type']);
                } else {
                    $where_clauses[] = 'comment_type = ?';
                    $params[] = $args['type'];
                }
            }

            if (isset($args['has_children']) && $args['has_children'] === true) {
                $where_clauses[] = 'EXISTS (SELECT 1 FROM ' . DB_PREFIX . 'comments cc WHERE cc.comment_parent = c.comment_ID)';
            } elseif (isset($args['has_children']) && $args['has_children'] === false) {
                $where_clauses[] = 'NOT EXISTS (SELECT 1 FROM ' . DB_PREFIX . 'comments cc WHERE cc.comment_parent = c.comment_ID)';
            }
            
            $where_sql = empty($where_clauses) ? '' : 'WHERE ' . implode(' AND ', $where_clauses);
            
            $query = "SELECT COUNT(*) as count FROM " . DB_PREFIX . "comments c {$where_sql}";
            
            // 执行查询
            $count = $db->get_var($query, $params);
            
            return $count !== null ? intval($count) : 0;
        }
        
        /**
         * 审核评论
         * 
         * @param int $comment_id 评论ID
         * @param int $status 状态
         * @return bool
         */
        public static function moderate_comment($comment_id, $status) {
            $old_comment = self::get_comment($comment_id);
            if (!$old_comment) {
                return false;
            }
            
            $result = self::update_comment($comment_id, array('status' => $status));
            
            if ($result) {
                // 更新文章评论数
                self::update_post_comment_count($old_comment['post_id']);
                
                // 如果是批准评论，发送通知
                if ($status == self::STATUS_APPROVED && $old_comment['status'] != self::STATUS_APPROVED) {
                    self::send_comment_notifications($comment_id);
                }
            }
            
            return $result;
        }
        
        /**
         * 批量审核评论
         * 
         * @param array $comment_ids 评论ID数组
         * @param int $status 状态
         * @return int 成功处理的数量
         */
        public static function bulk_moderate_comments($comment_ids, $status) {
            $success_count = 0;
            
            foreach ($comment_ids as $comment_id) {
                if (self::moderate_comment($comment_id, $status)) {
                    $success_count++;
                }
            }
            
            return $success_count;
        }
        
        /**
         * 回复评论
         * 
         * @param int $parent_id 父评论ID
         * @param array $reply_data 回复数据
         * @return bool|int
         */
        public static function reply_comment($parent_id, $reply_data) {
            $parent_comment = self::get_comment($parent_id);
            if (!$parent_comment) {
                return false;
            }
            
            $reply_data['parent_id'] = $parent_id;
            $reply_data['post_id'] = $parent_comment['post_id'];
            $reply_data['type'] = self::TYPE_REPLY;
            
            return self::create_comment($reply_data);
        }
        
        /**
         * 保存评论元数据
         * 
         * @param int $comment_id 评论ID
         * @param string $meta_key 元数据键
         * @param mixed $meta_value 元数据值
         * @return bool
         */
        public static function save_comment_meta($comment_id, $meta_key, $meta_value) {
            $db = self::get_db();
            if (!$db || !method_exists($db, 'query')) {
                return false;
            }

            $query = "INSERT INTO " . DB_PREFIX . "commentmeta 
                    (comment_id, meta_key, meta_value) 
                    VALUES (?, ?, ?) 
                    ON DUPLICATE KEY UPDATE 
                    meta_value = VALUES(meta_value)";
            
            $meta_value_json = is_array($meta_value) ? json_encode($meta_value) : $meta_value;
            try {
                return $db->query($query, [$comment_id, $meta_key, $meta_value_json]);
            } catch (Throwable $e) {
                return false;
            }
        }
        
        /**
         * 获取评论元数据
         * 
         * @param int $comment_id 评论ID
         * @param string $meta_key 元数据键（可选）
         * @return array|string|null
         */
        public static function get_comment_meta($comment_id, $meta_key = null) {
            $db = self::get_db();
            if (!$db) {
                return $meta_key ? null : array();
            }

            if ($meta_key) {
                $query = "SELECT meta_value FROM " . DB_PREFIX . "commentmeta 
                        WHERE comment_id = ? AND meta_key = ?";
                try {
                    $value = $db->get_var($query, [$comment_id, $meta_key]);
                } catch (Throwable $e) {
                    $value = null;
                }
                if ($value !== null) {
                    $decoded = json_decode($value, true);
                    return $decoded !== null ? $decoded : $value;
                }
                return null;
            } else {
                $query = "SELECT meta_key, meta_value FROM " . DB_PREFIX . "commentmeta WHERE comment_id = ?";
                try {
                    $rows = $db->get_results($query, [$comment_id], 'ARRAY_A');
                } catch (Throwable $e) {
                    $rows = array();
                }
                $meta = array();
                if (!empty($rows)) {
                    foreach ($rows as $row) {
                        $value = $row['meta_value'];
                        $decoded = json_decode($value, true);
                        $meta[$row['meta_key']] = $decoded !== null ? $decoded : $value;
                    }
                }
                return $meta;
            }
        }
        
        /**
         * 删除评论元数据
         * 
         * @param int $comment_id 评论ID
         * @param string $meta_key 元数据键（可选）
         * @return bool
         */
        public static function delete_comment_meta($comment_id, $meta_key = null) {
            $db = self::get_db();
            if (!$db || !method_exists($db, 'query')) {
                return false;
            }

            if ($meta_key) {
                $query = "DELETE FROM " . DB_PREFIX . "commentmeta WHERE comment_id = ? AND meta_key = ?";
                try {
                    return $db->query($query, [$comment_id, $meta_key]);
                } catch (Throwable $e) {
                    return false;
                }
            } else {
                $query = "DELETE FROM " . DB_PREFIX . "commentmeta WHERE comment_id = ?";
                try {
                    return $db->query($query, [$comment_id]);
                } catch (Throwable $e) {
                    return false;
                }
            }
        }
        
        /**
         * 更新文章评论数
         * 
         * @param int $post_id 文章ID
         */
        private static function update_post_comment_count($post_id) {
            $post_id = intval($post_id);
            if ($post_id <= 0) return;
            
            $db = self::get_db();
            if (!$db) return;
            
            $count = self::get_comments_count(array(
                'post_id' => $post_id,
                'status' => self::STATUS_APPROVED
            ));
            
            $query = "UPDATE " . $db->prefix . "posts SET comment_count = ? WHERE ID = ?";
            $db->query($query, [$count, $post_id]);
            
            // 清除文章缓存
            if (class_exists('Todo_Unified_Cache_Manager')) {
                Todo_Unified_Cache_Manager::clean_post_cache($post_id);
            }
            if (function_exists('todo_do_action')) {
                todo_do_action('todo_post_updated', $post_id);
            }
        }
        
        /**
         * 垃圾评论检测
         * 
         * @param array $comment_data 评论数据
         * @return bool
         */
        private static function is_spam($comment_data) {
            // 简单的垃圾评论检测规则
            $spam_keywords = array('viagra', 'casino', 'poker', 'loan', 'mortgage');
            $content_lower = strtolower($comment_data['content']);
            
            foreach ($spam_keywords as $keyword) {
                if (strpos($content_lower, $keyword) !== false) {
                    return true;
                }
            }
            
            // 检查链接数量
            $link_count = substr_count($comment_data['content'], 'http');
            if ($link_count > 3) {
                return true;
            }
            
            // 检查重复字符
            if (preg_match('/([a-zA-Z])\1{4,}/', $comment_data['content'])) {
                return true;
            }
            
            return false;
        }
        
        /**
         * 自动审核检测
         * 
         * @param array $comment_data 评论数据
         * @return bool
         */
        private static function should_auto_approve($comment_data) {
            $moderation_enabled = function_exists('get_option') ? ((int) get_option('comment_moderation', 0) === 1) : false;
            if (!$moderation_enabled) {
                return true;
            }

            // 已登录用户自动通过
            if ($comment_data['user_id'] > 0) {
                $user = todo_get_user($comment_data['user_id']);
                if ($user && in_array($user['role'], array('admin', 'editor', 'author'))) {
                    return true;
                }
            }
            
            // 之前有通过评论的用户自动通过
            if (!empty($comment_data['author_email'])) {
                $approved_count = self::get_comments_count(array(
                    'author_email' => $comment_data['author_email'],
                    'status' => self::STATUS_APPROVED
                ));
                
                if ($approved_count > 0) {
                    return true;
                }
            }
            
            return false;
        }
        
        /**
         * 发送评论通知
         * 
         * @param int $comment_id 评论ID
         */
        private static function send_comment_notifications($comment_id) {
            $comment = self::get_comment($comment_id);
            if (!$comment) {
                return;
            }
            
            // 通知管理员
            if (function_exists('todo_send_admin_notification')) {
                $post_id = intval($comment['comment_post_ID'] ?? $comment['post_id'] ?? 0);
                $author_name = (string) ($comment['comment_author'] ?? $comment['author_name'] ?? '');
                $author_email = (string) ($comment['comment_author_email'] ?? $comment['author_email'] ?? '');
                $content = (string) ($comment['comment_content'] ?? $comment['content'] ?? '');
                $created_at = (string) ($comment['comment_date'] ?? $comment['created_at'] ?? date('Y-m-d H:i:s'));

                $post = function_exists('get_post') ? get_post($post_id) : null;
                $post_title = $post ? ($post->post_title ?? '未知产品') : '未知产品';
                $post_url = function_exists('todo_get_permalink') ? todo_get_permalink($post_id) : '';

                $comment_type = $comment['comment_type'] ?? 'comment';
                $parent_id = intval($comment['comment_parent'] ?? $comment['parent_id'] ?? 0);
                if ($comment_type === 'inquiry') {
                    // 仅根询盘(parent_id == 0)通知管理员，管理员发表的子回复无需重复发送通知邮件给自己
                    if ($parent_id <= 0) {
                        // 仅根询盘(parent_id == 0)通知管理员，管理员发表的子回复无需重复发送通知邮件给自己
	                    if ($parent_id <= 0) {
	                        todo_send_admin_notification('product_inquiry', 'product_inquiry', [
	                            'name' => $author_name,
	                            'email' => $author_email,
	                            'content' => $content,
	                            'product_title' => $post_title,
	                            'product_id' => $post_id,
	                            'product_url' => $post_url,
	                            'created_at' => $created_at
	                        ]);
	                    }
                    }
                } else {
                    todo_send_admin_notification('comment', 'comment', [
                        'post_title' => $post_title,
                        'author_name' => $author_name,
                        'author_email' => $author_email,
                        'content' => $content,
                        'created_at' => $created_at,
                        'post_url' => $post_url
                    ]);
                }
            }
            
            todo_log_info("New comment notification sent for comment ID: {$comment_id}");
        }
        
        /**
         * 获取评论统计
         * 
         * @return array
         */
        /**
         * 获取评论统计
         * 
         * @param string $type 评论类型 (可选)
         * @return array
         */
        public static function get_comment_stats($type = 'all') {
            $db = self::get_db();
            if (!$db) return [];

            $stats = array(
                'total' => 0,
                'approved' => 0,
                'pending' => 0,
                'spam' => 0,
                'trash' => 0
            );
            
            $where = "";
            $params = [];
            if ($type !== 'all' && !empty($type)) {
                $where = " WHERE comment_type = ?";
                $params[] = $type;
            }
            
            $query = "SELECT comment_approved as status, COUNT(*) as count FROM " . $db->prefix . "comments $where GROUP BY comment_approved";
            $results = $db->get_results($query, $params);
            
            if ($results) {
                foreach ($results as $row) {
                    $count = (int)$row->count;
                    $status = (string)$row->status;
                    $stats['total'] += $count;
                    
                    switch ($status) {
                        case '1':
                            $stats['approved'] = $count;
                            break;
                        case '0':
                            $stats['pending'] = $count;
                            break;
                        case 'spam':
                            $stats['spam'] = $count;
                            break;
                        case 'trash':
                            $stats['trash'] = $count;
                            break;
                    }
                }
            }
            
            return $stats;
        }
        
        /**
         * 获取状态选项
         * 
         * @return array
         */
        public static function get_status_options() {
            return array(
                self::STATUS_APPROVED => '已通过',
                self::STATUS_PENDING => '待审核',
                self::STATUS_SPAM => '垃圾评论',
                self::STATUS_TRASH => '已删除'
            );
        }
        
        /**
         * 获取类型选项
         * 
         * @return array
         */
        public static function get_type_options() {
            return array(
                self::TYPE_COMMENT => '普通评论',
                self::TYPE_REVIEW => '商品评价',
                self::TYPE_REPLY => '回复',
                self::TYPE_PINGBACK => '引用通告',
                self::TYPE_TRACKBACK => '引用'
            );
        }
        
        /**
         * 获取评论深度
         * 
         * @param int $comment_id 评论ID
         * @return int 深度 (0为顶级评论)
         */
        public static function get_comment_depth($comment_id) {
            $comment_id = intval($comment_id);
            if ($comment_id <= 0) return 0;
            
            $depth = 0;
            $current_id = $comment_id;
            while ($depth < 10) {
                $parent = self::$tododb->get_var("SELECT comment_parent FROM " . TABLE_PREFIX . "comments WHERE comment_ID = ?", [$current_id]);
                if (!$parent || $parent <= 0) break;
                $current_id = intval($parent);
                $depth++;
            }
            return $depth;
        }
    }
}

if (!class_exists('CMS_Comment_Manager') && class_exists('Todo_Comment_Manager')) {
    class CMS_Comment_Manager extends Todo_Comment_Manager {}
}

// 兼容别名类，适配历史代码引用 CMS_Comment_Manager
if (!class_exists('CMS_Comment_Manager')) {
    class CMS_Comment_Manager {
        public function get_comment($comment_id) {
            return Todo_Comment_Manager::get_comment($comment_id);
        }
    }
}
// 便捷函数
if (!function_exists('todo_create_comment')) {
    /**
     * 创建评论
     * 
     * @param array $comment_data 评论数据
     * @return bool|int
     */
    function todo_create_comment($comment_data) {
        if (!is_array($comment_data)) {
            return false;
        }

        $mapped = $comment_data;
        if (isset($mapped['post_id']) && !isset($mapped['comment_post_ID'])) {
            $mapped['comment_post_ID'] = $mapped['post_id'];
        }
        if (!isset($mapped['post_id']) && isset($mapped['comment_post_ID'])) {
            $mapped['post_id'] = $mapped['comment_post_ID'];
        }

        if (isset($mapped['parent_id']) && !isset($mapped['comment_parent'])) {
            $mapped['comment_parent'] = $mapped['parent_id'];
        }
        if (!isset($mapped['parent_id']) && isset($mapped['comment_parent'])) {
            $mapped['parent_id'] = $mapped['comment_parent'];
        }

        if (isset($mapped['author_name']) && !isset($mapped['comment_author'])) {
            $mapped['comment_author'] = $mapped['author_name'];
        }
        if (!isset($mapped['author_name']) && isset($mapped['comment_author'])) {
            $mapped['author_name'] = $mapped['comment_author'];
        }

        if (isset($mapped['author_email']) && !isset($mapped['comment_author_email'])) {
            $mapped['comment_author_email'] = $mapped['author_email'];
        }
        if (!isset($mapped['author_email']) && isset($mapped['comment_author_email'])) {
            $mapped['author_email'] = $mapped['comment_author_email'];
        }

        if (isset($mapped['author_url']) && !isset($mapped['comment_author_url'])) {
            $mapped['comment_author_url'] = $mapped['author_url'];
        }
        if (!isset($mapped['author_url']) && isset($mapped['comment_author_url'])) {
            $mapped['author_url'] = $mapped['comment_author_url'];
        }

        if (isset($mapped['author_ip']) && !isset($mapped['comment_author_IP'])) {
            $mapped['comment_author_IP'] = $mapped['author_ip'];
        }

        if (isset($mapped['content']) && !isset($mapped['comment_content'])) {
            $mapped['comment_content'] = $mapped['content'];
        }
        if (!isset($mapped['content']) && isset($mapped['comment_content'])) {
            $mapped['content'] = $mapped['comment_content'];
        }

        $comment_id = CMS_Comment::create($mapped);
        if ($comment_id && class_exists('Todo_Comment_Manager')) {
            $reflector = new ReflectionMethod('Todo_Comment_Manager', 'send_comment_notifications');
            $reflector->setAccessible(true);
            $reflector->invoke(null, $comment_id);
        }
        return $comment_id;
    }
}

if (!function_exists('todo_get_comment')) {
    /**
     * 获取评论信息
     * 
     * @param int $comment_id 评论ID
     * @return object|null
     */
    function todo_get_comment($comment_id) {
        $comment_id = intval($comment_id);
        if ($comment_id <= 0) {
            return null;
        }

        $comment = new CMS_Comment($comment_id);
        $data = $comment->get_data();
        if (!$data || !is_object($data)) {
            return null;
        }

        // Ensure it's a clone or fresh object to avoid modifying cached data
        $data = clone $data;

        $effective_code = CMS_Comment::get_effective_private_code($comment_id);
        $is_private = $effective_code !== '';
        $data->todo_is_private = $is_private;

        if ($is_private) {
            $provided = $_REQUEST['comment_key'] ?? ($_REQUEST['private_code'] ?? ($_REQUEST['todo_comment_key'] ?? ''));
            $allowed = CMS_Comment::verify_private_code($comment_id, is_array($provided) ? '' : (string) $provided);
            if (!$allowed) {
                $data->comment_content = '（私密评论，需反馈码查看）';
                $data->todo_private_masked = true;
            } else {
                $data->todo_private_masked = false;
            }
        }

        return $data;
    }
}

if (!function_exists('todo_get_comments')) {
    /**
     * 获取评论列表
     * 
     * @param array $args 查询参数
     * @return array
     */
    function todo_get_comments($args = array()) {
        return CMS_Comment::get_comments($args);
    }
}

if (!function_exists('todo_get_comment_details')) {
    /**
     * 获取评论详情及其关联信息
     * 
     * @param int $comment_id 评论ID
     * @return object|null
     */
    function todo_get_comment_details($comment_id) {
        $comment = todo_get_comment($comment_id);
        if (!$comment) {
            return null;
        }
        
        $data = (array)$comment;
        
        // 获取所有元数据
        $c_obj = new CMS_Comment($comment_id);
        $data['meta'] = $c_obj->get_all_meta();
        
        // 获取用户信息
        if (!empty($data['user_id'])) {
            if (function_exists('todo_get_user')) {
                $user = todo_get_user($data['user_id']);
                if ($user) $data['user'] = (array)$user;
            } elseif (function_exists('get_user_by')) {
                $user = get_user_by('id', $data['user_id']);
                if ($user) $data['user'] = (array)$user;
            }
        }
        
        // 获取关联的文章/产品信息
        if (!empty($data['comment_post_ID'])) {
            if (function_exists('get_post')) {
                $post = get_post($data['comment_post_ID']);
                if ($post) $data['post'] = (array)$post;
            } elseif (function_exists('todo_get_post')) {
                $post = call_user_func('todo_get_post', $data['comment_post_ID']);
                if ($post) $data['post'] = (array)$post;
            }
        }
        
        return (object)$data;
    }
}

if (!function_exists('todo_get_comment_tree')) {
    /**
     * 获取评论树（层级结构）
     * 
     * @param int $post_id 文章ID
     * @param string $type 评论类型
     * @param string $status 状态
     * @return array
     */
    function todo_get_comment_tree($post_id = 0, $type = 'all', $status = 'approved') {
        $args = [
            'post_id' => $post_id,
            'status' => $status,
            'type' => $type,
            'order' => 'ASC',
            'orderby' => 'comment_date',
            'number' => 1000 // 调大以获取完整树，稍后可实现更高效的分页加载
        ];
        
        $comments = todo_get_comments($args);
        return todo_build_comment_tree($comments);
    }
}

if (!function_exists('todo_build_comment_tree')) {
    /**
     * 构建评论树递归函数
     */
    function todo_build_comment_tree($comments, $parent_id = 0) {
        $branch = array();
        foreach ($comments as $comment) {
            $c_parent_id = is_object($comment) ? $comment->comment_parent : $comment['comment_parent'];
            if ((int)$c_parent_id === (int)$parent_id) {
                $id = is_object($comment) ? $comment->comment_ID : $comment['comment_ID'];
                $children = todo_build_comment_tree($comments, $id);
                if ($children) {
                    if (is_array($comment)) {
                        $comment['children'] = $children;
                    } elseif ($comment instanceof stdClass) {
                        $comment = clone $comment;
                        $comment->children = $children;
                    } elseif (is_object($comment)) {
                        $comment_data = get_object_vars($comment);
                        $comment_data['children'] = $children;
                        $comment = (object) $comment_data;
                    }
                }
                $branch[] = $comment;
            }
        }
        return $branch;
    }
}

if (!function_exists('todo_moderate_comment')) {
    /**
     * 审核评论
     * 
     * @param int $comment_id 评论ID
     * @param int $status 状态
     * @return bool
     */
    function todo_moderate_comment($comment_id, $status) {
        $comment_id = intval($comment_id);
        if ($comment_id <= 0) {
            return false;
        }

        $status_value = $status;
        if (is_numeric($status)) {
            $status_value = intval($status) === 1 ? '1' : (intval($status) === 0 ? '0' : (string) $status);
        } elseif (is_string($status)) {
            $status_mapping = array(
                'approve' => '1',
                'approved' => '1',
                'hold' => '0',
                'pending' => '0',
                'spam' => 'spam',
                'trash' => 'trash'
            );
            $status_value = isset($status_mapping[$status]) ? $status_mapping[$status] : $status;
        }

        $comment = new CMS_Comment($comment_id);
        return $comment->update(['comment_approved' => $status_value]);
    }
}

if (!function_exists('todo_update_comment')) {
    /**
     * 更新评论
     * 
     * @param array $comment_data 评论数据（必须包含comment_ID）
     * @return bool
     */
    function todo_update_comment($comment_data) {
        if (empty($comment_data['comment_ID'])) {
            return false;
        }
        
        $comment_id = $comment_data['comment_ID'];
        unset($comment_data['comment_ID']);

        $comment = new CMS_Comment($comment_id);
        return $comment->update($comment_data);
    }
}

if (!function_exists('todo_delete_comment')) {
    /**
     * 删除评论
     * 
     * @param int $comment_id 评论ID
     * @param bool $force_delete 是否强制删除
     * @return bool
     */
    function todo_delete_comment($comment_id, $force_delete = false) {
        $comment_id = intval($comment_id);
        if ($comment_id <= 0) {
            return false;
        }
        if (class_exists('Todo_Comment_Manager') && method_exists('Todo_Comment_Manager', 'delete_comment')) {
            return Todo_Comment_Manager::delete_comment($comment_id, $force_delete);
        }
        $comment = new CMS_Comment($comment_id);
        return $comment->delete($force_delete);
    }
}

if (!function_exists('todo_set_comment_status')) {
    /**
     * 设置评论状态
     * 
     * @param int $comment_id 评论ID
     * @param string $status 状态
     * @return bool
     */
    function todo_set_comment_status($comment_id, $status) {
        $comment_id = intval($comment_id);
        if ($comment_id <= 0) {
            return false;
        }

        $status_mapping = array(
            'approve' => '1',
            'approved' => '1',
            'hold' => '0',
            'pending' => '0',
            'spam' => 'spam',
            'trash' => 'trash'
        );
        $value = isset($status_mapping[$status]) ? $status_mapping[$status] : (string) $status;

        $comment = new CMS_Comment($comment_id);
        return $comment->update(['comment_approved' => $value]);
    }
}

if (!function_exists('todo_get_comment_status')) {
    /**
     * 获取评论状态
     * 
     * @param int $comment_id 评论ID
     * @return string
     */
    function todo_get_comment_status($comment_id) {
        $comment_id = intval($comment_id);
        if ($comment_id <= 0) {
            return '';
        }

        $comment = new CMS_Comment($comment_id);
        $approved = (string) $comment->get_status();

        if ($approved === '1') {
            return 'approve';
        }
        if ($approved === '0') {
            return 'hold';
        }
        if ($approved === 'spam') {
            return 'spam';
        }
        if ($approved === 'trash') {
            return 'trash';
        }
        return 'hold';
    }
}

if (!function_exists('todo_reply_comment')) {
    /**
     * 回复评论
     * 
     * @param int $parent_id 父评论ID
     * @param array $reply_data 回复数据
     * @return bool|int
     */
    function todo_reply_comment($parent_id, $reply_data) {
        $parent_id = intval($parent_id);
        if ($parent_id <= 0 || !is_array($reply_data)) {
            return false;
        }

        $parent = todo_get_comment($parent_id);
        if (!$parent) {
            return false;
        }

        $reply_data['comment_post_ID'] = $reply_data['comment_post_ID'] ?? $parent->comment_post_ID;
        $reply_data['comment_parent'] = $parent_id;

        return todo_create_comment($reply_data);
    }
}

if (!function_exists('todo_spam_comment')) {
    /**
     * 标记评论为垃圾评论
     * 
     * @param int $comment_id 评论ID
     * @return bool
     */
    function todo_spam_comment($comment_id) {
        return todo_set_comment_status($comment_id, 'spam');
    }
}

if (!function_exists('todo_trash_comment')) {
    /**
     * 移动评论到回收站
     * 
     * @param int $comment_id 评论ID
     * @return bool
     */
    function todo_trash_comment($comment_id) {
        return todo_set_comment_status($comment_id, 'trash');
    }
}

if (!function_exists('todo_untrash_comment')) {
    /**
     * 从回收站恢复评论
     * 
     * @param int $comment_id 评论ID
     * @return bool
     */
    function todo_untrash_comment($comment_id) {
        return todo_set_comment_status($comment_id, '1');
    }
}

if (!function_exists('todo_update_comment_status')) {
    /**
     * 批量更新评论状态
     * 
     * @param array|int $comment_ids 评论ID或评论ID数组
     * @param string $status 状态
     * @return int 成功更新的数量
     */
    function todo_update_comment_status($comment_ids, $status) {
        if (!is_array($comment_ids)) {
            $comment_ids = array($comment_ids);
        }
        
        $success_count = 0;
        foreach ($comment_ids as $comment_id) {
            if (todo_set_comment_status($comment_id, $status)) {
                $success_count++;
            }
        }
        
        return $success_count;
    }
}

if (!function_exists('todo_get_comment_stats')) {
    /**
     * 获取评论统计
     * 
     * @param string $type 评论类型
     * @return array
     */
    function todo_get_comment_stats($type = 'all') {
        return Todo_Comment_Manager::get_comment_stats($type);
    }
}

if (!function_exists("todo_get_comment_depth")) {
    /**
     * 获取评论深度
     * 
     * @param int $comment_id 评论ID
     * @return int
     */
    function todo_get_comment_depth($comment_id) {
        return Todo_Comment_Manager::get_comment_depth($comment_id);
    }
}

?>
