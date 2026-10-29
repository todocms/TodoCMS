<?php
/**
 * 评论类
 *
 * 处理评论的创建、更新、查询和删除等功能
 *
 * @package TodoCMS
 * @version 1.0.0
 */

class CMS_Comment {
    /**
     * 评论ID
     *
     * @var int
     */
    private $id = 0;
    
    /**
     * 评论数据
     *
     * @var array
     */
    private $data = array();

    /**
     * 评论数量缓存
     * @var array
     */
    private static $comment_count_cache = [];

    /**
     * 魔术方法：允许访问私有数据
     */
    public function __get($name) {
        $mapping = [
            'id' => 'comment_ID',
            'post_id' => 'comment_post_ID',
            'author' => 'comment_author',
            'author_name' => 'comment_author',
            'email' => 'comment_author_email',
            'author_email' => 'comment_author_email',
            'url' => 'comment_author_url',
            'author_url' => 'comment_author_url',
            'ip' => 'comment_author_IP',
            'date' => 'comment_date',
            'content' => 'comment_content',
            'status' => 'comment_approved',
            'agent' => 'comment_agent',
            'type' => 'comment_type',
            'parent_id' => 'comment_parent',
            'user_id' => 'user_id',
            'rating' => 'comment_rating',
            'admin_reply' => 'admin_reply',
            'replied_at' => 'replied_at'
        ];
        
        $key = $mapping[$name] ?? $name;
        
        if ($name === 'id') return $this->id;
        
        return $this->data[$key] ?? null;
    }

    const META_IS_PRIVATE = 'todo_private_comment';
    const META_PRIVATE_CODE = 'todo_private_code';
    const LEGACY_META_IS_PRIVATE = 'is_private';
    const LEGACY_META_PRIVATE_CODE = 'private_comment_hash';

    private static $last_private_code = '';

    /**
     * 批量预加载文章评论数量
     *
     * @param array $post_ids 文章ID数组
     * @return void
     */
    public static function prime_comment_counts($post_ids) {
        global $tododb;
        
        if (empty($post_ids)) {
            return;
        }
        
        $post_ids = array_unique(array_filter(array_map('intval', $post_ids)));
        if (empty($post_ids)) {
            return;
        }
        
        // 找出未缓存的ID
        $ids_to_query = [];
        foreach ($post_ids as $id) {
            if (!isset(self::$comment_count_cache[$id])) {
                $ids_to_query[] = $id;
                self::$comment_count_cache[$id] = 0; // 默认0
            }
        }
        
        if (empty($ids_to_query)) {
            return;
        }
        
        $placeholders = implode(',', array_fill(0, count($ids_to_query), '?'));
        $query = "SELECT comment_post_ID, COUNT(*) as count 
                  FROM " . TABLE_PREFIX . "comments 
                  WHERE comment_post_ID IN ($placeholders) AND comment_approved = '1'
                  GROUP BY comment_post_ID";
        
        $results = $tododb->get_results($query, $ids_to_query);
        
        if ($results) {
            foreach ($results as $row) {
                $row_array = is_object($row) ? (array) $row : $row;
                self::$comment_count_cache[intval($row_array['comment_post_ID'])] = intval($row_array['count']);
            }
        }
    }

    public static function get_last_private_code() {
        return self::$last_private_code;
    }

    private static function normalize_private_code($code) {
        if (!is_string($code)) {
            return '';
        }
        $code = strtolower(trim($code));
        if ($code === '') {
            return '';
        }
        if (!preg_match('/^[a-f0-9]{16}$/', $code)) {
            return '';
        }
        return $code;
    }

    private static function extract_private_code_from_request() {
        $candidates = [];
        if (isset($_REQUEST['comment_key'])) {
            $candidates[] = $_REQUEST['comment_key'];
        }
        if (isset($_REQUEST['private_code'])) {
            $candidates[] = $_REQUEST['private_code'];
        }
        if (isset($_REQUEST['todo_comment_key'])) {
            $candidates[] = $_REQUEST['todo_comment_key'];
        }

        foreach ($candidates as $candidate) {
            $normalized = self::normalize_private_code(is_array($candidate) ? '' : (string) $candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    public static function is_private_comment($comment_id) {
        $comment_id = intval($comment_id);
        if ($comment_id <= 0) {
            return false;
        }
        $code = self::get_private_code($comment_id);
        return $code !== '';
    }

    public static function get_private_code($comment_id) {
        $comment_id = intval($comment_id);
        if ($comment_id <= 0) {
            return '';
        }

        $comment = new self($comment_id);
        $is_private = $comment->get_meta(self::META_IS_PRIVATE, true);
        $legacy_is_private = $comment->get_meta(self::LEGACY_META_IS_PRIVATE, true);

        $flagged = !($is_private === '' || $is_private === '0' || $is_private === 0);
        $legacy_flagged = !($legacy_is_private === '' || $legacy_is_private === '0' || $legacy_is_private === 0);

        $code = $comment->get_meta(self::META_PRIVATE_CODE, true);
        $code = self::normalize_private_code((string) $code);
        if ($code === '') {
            $legacy_code = $comment->get_meta(self::LEGACY_META_PRIVATE_CODE, true);
            $code = self::normalize_private_code((string) $legacy_code);
        }

        if ($flagged || $legacy_flagged) {
            return $code;
        }

        return $code;
    }

    public static function get_effective_private_code($comment_id, $max_depth = 20) {
        global $tododb;

        $comment_id = intval($comment_id);
        if ($comment_id <= 0) {
            return '';
        }

        $depth = 0;
        $current_id = $comment_id;
        while ($current_id > 0 && $depth < $max_depth) {
            $code = self::get_private_code($current_id);
            if ($code !== '') {
                return $code;
            }

            $parent_id = $tododb->get_var(
                "SELECT comment_parent FROM " . TABLE_PREFIX . "comments WHERE comment_ID = ?",
                [$current_id]
            );
            $current_id = intval($parent_id);
            $depth++;
        }

        return '';
    }

    public static function verify_private_code($comment_id, $code) {
        $stored = self::get_effective_private_code($comment_id);
        if ($stored === '') {
            return true;
        }
        $provided = self::normalize_private_code($code);
        if ($provided === '') {
            return false;
        }
        return hash_equals($stored, $provided);
    }

    private static function private_flag_from_create_data($data) {
        $truthy_keys = ['private_comment', 'is_private', 'todo_private_comment', 'private'];
        foreach ($truthy_keys as $key) {
            if (!isset($data[$key])) {
                continue;
            }
            $val = $data[$key];
            if ($val === true || $val === 1 || $val === '1' || $val === 'on' || $val === 'yes') {
                return true;
            }
        }
        return false;
    }

    private static function generate_unique_private_code($max_attempts = 10) {
        global $tododb;

        for ($i = 0; $i < $max_attempts; $i++) {
            try {
                $entropy = bin2hex(random_bytes(16));
            } catch (Throwable $e) {
                $entropy = uniqid('', true) . mt_rand();
            }
            $candidate = substr(md5($entropy), 0, 16);
            $candidate = self::normalize_private_code($candidate);
            if ($candidate === '') {
                continue;
            }

            $exists = $tododb->get_var(
                "SELECT comment_id FROM " . TABLE_PREFIX . "commentmeta WHERE meta_key = ? AND meta_value = ? LIMIT 1",
                [self::META_PRIVATE_CODE, $candidate]
            );
            if (empty($exists)) {
                return $candidate;
            }
        }

        return substr(md5(uniqid('', true) . mt_rand()), 0, 16);
    }

    private static function fetch_private_meta_map($comment_ids) {
        global $tododb;

        $comment_ids = array_values(array_unique(array_filter(array_map('intval', $comment_ids))));
        if (empty($comment_ids)) {
            return [];
        }

        $id_placeholders = implode(',', array_fill(0, count($comment_ids), '?'));
        $key_placeholders = implode(',', array_fill(0, 4, '?'));

        $query = "SELECT comment_id, meta_key, meta_value
                  FROM " . TABLE_PREFIX . "commentmeta
                  WHERE comment_id IN ($id_placeholders)
                    AND meta_key IN ($key_placeholders)";

        $params = array_merge($comment_ids, [
            self::META_IS_PRIVATE,
            self::META_PRIVATE_CODE,
            self::LEGACY_META_IS_PRIVATE,
            self::LEGACY_META_PRIVATE_CODE,
        ]);
        $rows = $tododb->get_results($query, $params);

        $map = [];
        if ($rows) {
            foreach ($rows as $row) {
                $comment_id = intval($row->comment_id);
                if (!isset($map[$comment_id])) {
                    $map[$comment_id] = ['is_private' => false, 'code' => ''];
                }
                if ($row->meta_key === self::META_IS_PRIVATE) {
                    $map[$comment_id]['is_private'] = ($row->meta_value === '1' || $row->meta_value === 1);
                } elseif ($row->meta_key === self::LEGACY_META_IS_PRIVATE) {
                    if (!$map[$comment_id]['is_private']) {
                        $map[$comment_id]['is_private'] = ($row->meta_value === '1' || $row->meta_value === 1);
                    }
                } elseif ($row->meta_key === self::META_PRIVATE_CODE) {
                    $map[$comment_id]['code'] = self::normalize_private_code((string) $row->meta_value);
                } elseif ($row->meta_key === self::LEGACY_META_PRIVATE_CODE) {
                    if ($map[$comment_id]['code'] === '') {
                        $map[$comment_id]['code'] = self::normalize_private_code((string) $row->meta_value);
                    }
                }
            }
        }

        return $map;
    }
    
    /**
     * 构造函数
     *
     * @param int|object $comment 评论ID或评论对象
     */
    public function __construct($comment = 0) {
        if (is_object($comment)) {
            $this->id = $comment->comment_ID;
            $this->data = $comment;
        } elseif (is_numeric($comment) && $comment > 0) {
            $this->id = $comment;
            $this->load();
        }
    }
    
    /**
     * 加载评论数据
     *
     * @return bool 是否成功加载数据
     */
    private function load() {
        global $tododb;
        
        if (!$this->id) {
            return false;
        }
        
        $comment = $tododb->get_row(
            "SELECT * FROM " . TABLE_PREFIX . "comments WHERE comment_ID = ?",
            [$this->id]
        );
        
        if (!$comment) {
            return false;
        }
        
        $this->data = $comment;
        return true;
    }
    
    /**
     * 获取评论ID
     *
     * @return int 评论ID
     */
    public function get_id() {
        return $this->id;
    }
    
    /**
     * 获取评论数据
     *
     * @param string $key 数据键名
     * @return mixed 评论数据或指定键名的数据
     */
    public function get_data($key = '') {
        if (empty($key)) {
            return $this->data;
        }

        if (is_object($this->data)) {
            return isset($this->data->$key) ? $this->data->$key : null;
        }

        if (is_array($this->data)) {
            return array_key_exists($key, $this->data) ? $this->data[$key] : null;
        }

        return null;
    }
    
    /**
     * 获取评论作者
     *
     * @return string 评论作者
     */
    public function get_author() {
        return $this->get_data('comment_author');
    }
    
    /**
     * 获取评论作者邮箱
     *
     * @return string 评论作者邮箱
     */
    public function get_author_email() {
        return $this->get_data('comment_author_email');
    }
    
    /**
     * 获取评论作者网址
     *
     * @return string 评论作者网址
     */
    public function get_author_url() {
        return $this->get_data('comment_author_url');
    }
    
    /**
     * 获取评论内容
     *
     * @return string 评论内容
     */
    public function get_content() {
        return $this->get_data('comment_content');
    }
    
    /**
     * 获取评论日期
     *
     * @param string $format 日期格式
     * @return string 格式化的评论日期
     */
    public function get_date($format = '') {
        $date = $this->get_data('comment_date');
        
        if (empty($format)) {
            $format = get_option('date_format') . ' ' . get_option('time_format');
        }
        
        return date($format, strtotime($date));
    }
    
    /**
     * 获取评论状态
     *
     * @return string 评论状态
     */
    public function get_status() {
        return $this->get_data('comment_approved');
    }
    
    /**
     * 获取评论所属文章ID
     *
     * @return int 文章ID
     */
    public function get_post_id() {
        return $this->get_data('comment_post_ID');
    }
    
    /**
     * 获取评论父级ID
     *
     * @return int 父级评论ID
     */
    public function get_parent() {
        return $this->get_data('comment_parent');
    }
    
    /**
     * 获取评论用户ID
     *
     * @return int 用户ID
     */
    public function get_user_id() {
        return $this->get_data('user_id');
    }
    
    /**
     * 更新评论
     *
     * @param array $data 评论数据
     * @return bool 是否成功更新
     */
    public function update($data) {
        global $tododb;
        
        if (!$this->id) {
            return false;
        }
        
        $fields = array();
        $params = array();
        $allowed_fields = array(
            'comment_author', 'comment_author_email', 'comment_author_url',
            'comment_content', 'comment_approved', 'comment_parent', 'user_id',
            'comment_type', 'comment_rating', 'admin_reply', 'replied_at', 'comment_karma', 'comment_agent'
        );
        
        foreach ($data as $key => $value) {
            if (in_array($key, $allowed_fields)) {
                $fields[] = "`$key` = ?";
                $params[] = $value;
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $params[] = $this->id;
        $result = $tododb->query("UPDATE " . TABLE_PREFIX . "comments SET " . implode(', ', $fields) . " WHERE comment_ID = ?", $params);
        
        if ($result) {
            $this->load();
            return true;
        }
        
        return false;
    }
    
    /**
     * 删除评论
     *
     * @param bool $force_delete 是否强制删除
     * @return bool 是否成功删除
     */
    public function delete($force_delete = false) {
        global $tododb;
        
        if (!$this->id) {
            return false;
        }
        
        if (!$force_delete) {
            // 移到回收站
            return $this->update(array('comment_approved' => 'trash'));
        }
        
        // 删除评论元数据
        $tododb->query("DELETE FROM " . TABLE_PREFIX . "commentmeta WHERE comment_id = ?", [$this->id]);
        
        // 删除评论
        $result = $tododb->query("DELETE FROM " . TABLE_PREFIX . "comments WHERE comment_ID = ?", [$this->id]);
        
        if ($result) {
            $this->id = 0;
            $this->data = array();
            return true;
        }
        
        return false;
    }
    
    /**
     * 创建新评论
     *
     * @param array $data 评论数据
     * @return int|bool 成功返回评论ID，失败返回false
     */
    public static function create($data) {
        global $tododb;

        self::$last_private_code = '';

        $comment_approved_provided = is_array($data) && array_key_exists('comment_approved', $data);
        
        $required_fields = array('comment_post_ID', 'comment_author', 'comment_content');
        
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || ($field !== 'comment_post_ID' && empty($data[$field]))) {
                return false;
            }
        }
        
        // 设置默认值
        $defaults = array(
            'comment_author_email' => '',
            'comment_author_url' => '',
            'comment_author_IP' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'comment_date' => current_time('mysql'),
            'comment_date_gmt' => current_time('mysql', true),
            'comment_karma' => 0,
            'comment_approved' => 1,
            'comment_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'CLI',
            'comment_type' => 'comment',
            'comment_rating' => 0,
            'admin_reply' => '',
            'replied_at' => null,
            'comment_parent' => 0,
            'user_id' => 0
        );
        
        $data = array_merge($defaults, $data);

        if (!$comment_approved_provided) {
            $type = isset($data['comment_type']) ? (string) $data['comment_type'] : 'comment';
            $moderation_types = array('comment', 'inquiry', 'message', 'guestbook');

            if ($type === 'review' || (isset($data['comment_rating']) && (int)$data['comment_rating'] > 0)) {
                $service_settings = function_exists('todo_get_option') ? todo_get_option('todo_service_settings', null) : (function_exists('get_option') ? get_option('todo_service_settings', null) : null);
                $review_approval_enabled = false;
                if (is_array($service_settings) && array_key_exists('review_approval', $service_settings)) {
                    $review_approval_enabled = ((int)$service_settings['review_approval'] === 1);
                } else {
                    $review_mod = function_exists('todo_get_option') ? todo_get_option('review_moderation', 'auto_approve') : (function_exists('get_option') ? get_option('review_moderation', 'auto_approve') : 'auto_approve');
                    $review_approval_enabled = ($review_mod === 'manual_approve');
                }
                $data['comment_approved'] = $review_approval_enabled ? 0 : 1;
            } elseif (in_array($type, $moderation_types, true)) {
                $is_reply = isset($data['comment_parent']) && (int)$data['comment_parent'] > 0;
                $enabled = function_exists('get_option') ? ((int) get_option('comment_moderation', 0) === 1) : false;
                if ($is_reply) {
                    $data['comment_approved'] = 1;
                } elseif ($enabled) {
                    $uid = isset($data['user_id']) ? (int) $data['user_id'] : 0;
                    $bypass = false;
                    if ($uid > 0 && function_exists('todo_get_user')) {
                        $u = todo_get_user($uid);
                        $role = '';
                        if (is_object($u)) {
                            $role = (string) ($u->role ?? $u->user_role ?? '');
                        } elseif (is_array($u)) {
                            $role = (string) ($u['role'] ?? $u['user_role'] ?? '');
                        }
                        if ($role !== '' && in_array($role, array('admin', 'editor', 'author'), true)) {
                            $bypass = true;
                        }
                    }
                    if (!$bypass && function_exists('todo_get_current_user_id') && function_exists('todo_current_user_can')) {
                        if (todo_current_user_can('moderate_comments')) {
                            $bypass = true;
                        }
                    }
                    $data['comment_approved'] = $bypass ? 1 : 0;
                } else {
                    $data['comment_approved'] = 1;
                }
            }
        }
        
        // 统一使用 tododb_insert，避免旧用法错误并提升安全性
        $insert_id = tododb_insert(TABLE_PREFIX . 'comments', $data);
        
        if ($insert_id !== false) {
            $comment_id = intval($insert_id);

            $parent_id = isset($data['comment_parent']) ? intval($data['comment_parent']) : 0;
            $inherit_code = '';
            if ($parent_id > 0) {
                $inherit_code = self::get_effective_private_code($parent_id);
            }

            $make_private = $inherit_code !== '' ? true : self::private_flag_from_create_data($data);
            if ($make_private) {
                $code = $inherit_code !== '' ? $inherit_code : self::generate_unique_private_code();
                $comment = new self($comment_id);
                $comment->update_meta(self::META_IS_PRIVATE, '1');
                $comment->update_meta(self::META_PRIVATE_CODE, $code);
                self::$last_private_code = $code;
            }

            return $comment_id;
        }
        
        return false;
    }
    
    /**
     * 获取评论的所有元数据
     * 
     * @return array
     */
    public function get_all_meta() {
        global $tododb;
        if (!$this->id) return array();
        
        $results = $tododb->get_results("SELECT meta_key, meta_value FROM " . TABLE_PREFIX . "commentmeta WHERE comment_id = ?", [$this->id], 'ARRAY_A');
        
        $meta = array();
        if ($results) {
            foreach ($results as $row) {
                $meta[$row['meta_key']] = $row['meta_value'];
            }
        }
        return $meta;
    }

    /**
     * 获取评论列表
     *
     * @param array $args 查询参数
     * @return array 评论列表
     */
    public static function get_comments($args = array()) {
        global $tododb;
        
        $defaults = array(
            'number' => 10,
            'offset' => 0,
            'status' => 'approve',
            'post_id' => 0,
            'user_id' => 0,
            'parent' => null,
            'search' => '',
            'orderby' => 'comment_date',
            'order' => 'DESC',
            'count' => false
        );
        
        $args = array_merge($defaults, $args);
        
        $where = array();
        $where_params = array();
        
        $status = $args['status'];
        if ($status === '' || $status === null) {
            $status = 'all';
        }
        if (is_string($status)) {
            $status_mapping = array(
                'approve' => '1',
                'approved' => '1',
                'hold' => '0',
                'pending' => '0',
                'spam' => 'spam',
                'trash' => 'trash'
            );
            $status = isset($status_mapping[$status]) ? $status_mapping[$status] : $status;
        }

        if ($status !== 'all') {
            $where[] = "comment_approved = ?";
            $where_params[] = (string) $status;
        }

        if (isset($args['type']) && $args['type'] !== 'all') {
            $where[] = "comment_type = ?";
            $where_params[] = (string) $args['type'];
        }
        
        if ($args['post_id'] > 0) {
            $where[] = "comment_post_ID = ?";
            $where_params[] = intval($args['post_id']);
        }
        
        if ($args['user_id'] > 0) {
            $where[] = "user_id = ?";
            $where_params[] = intval($args['user_id']);
        }
        
        if ($args['parent'] !== null) {
            $where[] = "comment_parent = ?";
            $where_params[] = intval($args['parent']);
        }
        
        if (!empty($args['search'])) {
            $search = '%' . $tododb->escape_like($args['search']) . '%';
            $where[] = "(comment_author LIKE ? OR comment_author_email LIKE ? OR comment_content LIKE ?)";
            $where_params[] = $search;
            $where_params[] = $search;
            $where_params[] = $search;
        }
        
        $where_clause = !empty($where) ? "WHERE " . implode(' AND ', $where) : '';
        
        if ($args['count']) {
            $query = "SELECT COUNT(*) FROM " . TABLE_PREFIX . "comments $where_clause";
            return $tododb->get_var($query, $where_params);
        }
        
        $orderby = $args['orderby'];
        $order = $args['order'];
        
        // 验证orderby列名是否有效，防止SQL注入和无效列名
        $valid_columns = ['comment_ID', 'comment_post_ID', 'comment_author', 'comment_author_email', 'comment_author_url', 'comment_author_IP', 'comment_date', 'comment_content', 'comment_approved', 'comment_parent', 'user_id'];
        if (!in_array($orderby, $valid_columns)) {
            $orderby = 'comment_date'; // 默认使用comment_date
        }
        
        // 验证order参数
        $order = strtoupper($order);
        if (!in_array($order, ['ASC', 'DESC'])) {
            $order = 'DESC'; // 默认使用DESC
        }

        // LIMIT使用占位符
        $limit_sql = '';
        $limit_params = [];
        if ($args['number'] > 0) {
            $limit_sql = "LIMIT ?, ?";
            $limit_params = [intval($args['offset']), intval($args['number'])];
        }

        $query = "SELECT * FROM " . TABLE_PREFIX . "comments $where_clause ORDER BY $orderby $order " . $limit_sql;
        
        // 合并where参数和limit参数
        $all_params = array_merge($where_params, $limit_params);
        
        $comments = $tododb->get_results($query, $all_params);
        if (empty($comments) || !is_array($comments)) {
            return $comments;
        }

        $comment_ids = [];
        $parent_map = [];
        foreach ($comments as $comment) {
            $id = isset($comment->comment_ID) ? intval($comment->comment_ID) : 0;
            if ($id <= 0) {
                continue;
            }
            $comment_ids[] = $id;
            $parent_map[$id] = isset($comment->comment_parent) ? intval($comment->comment_parent) : 0;
        }

        if (empty($comment_ids)) {
            return $comments;
        }

        $meta_map = self::fetch_private_meta_map($comment_ids);

        $provided_code = '';
        if (isset($args['comment_key'])) {
            $provided_code = self::normalize_private_code((string) $args['comment_key']);
        } elseif (isset($args['private_code'])) {
            $provided_code = self::normalize_private_code((string) $args['private_code']);
        }
        if ($provided_code === '') {
            $provided_code = self::extract_private_code_from_request();
        }

        $effective_cache = [];
        $compute_effective = function ($comment_id) use (&$compute_effective, $parent_map, $meta_map, &$effective_cache) {
            if (isset($effective_cache[$comment_id])) {
                return $effective_cache[$comment_id];
            }

            $meta = isset($meta_map[$comment_id]) ? $meta_map[$comment_id] : ['is_private' => false, 'code' => ''];
            if (!empty($meta['is_private']) && !empty($meta['code'])) {
                $effective_cache[$comment_id] = $meta['code'];
                return $effective_cache[$comment_id];
            }

            $parent_id = isset($parent_map[$comment_id]) ? intval($parent_map[$comment_id]) : 0;
            if ($parent_id > 0 && isset($parent_map[$parent_id])) {
                $effective_cache[$comment_id] = $compute_effective($parent_id);
                return $effective_cache[$comment_id];
            }

            $effective_cache[$comment_id] = '';
            return '';
        };

        foreach ($comments as $comment) {
            $id = isset($comment->comment_ID) ? intval($comment->comment_ID) : 0;
            if ($id <= 0) {
                continue;
            }

            $effective_code = $compute_effective($id);
            $is_private = $effective_code !== '';
            if ($is_private) {
                $comment->todo_is_private = true;
                $allowed = ($provided_code !== '' && hash_equals($effective_code, $provided_code));
                if (!$allowed) {
                    $comment->comment_content = '（私密评论，需反馈码查看）';
                    $comment->todo_private_masked = true;
                } else {
                    $comment->todo_private_masked = false;
                }
            } else {
                $comment->todo_is_private = false;
            }
        }

        return $comments;
    }
    
    /**
     * 获取评论数量
     *
     * @param array $args 查询参数
     * @return int 评论数量
     */
    public static function get_comment_count($args = array()) {
        // 性能优化：如果只是查询单个文章的已审核评论数，优先使用缓存
        if (isset($args['post_id']) && $args['post_id'] > 0 && 
            (!isset($args['status']) || $args['status'] === 'approve' || $args['status'] === '1') &&
            empty($args['search']) && !isset($args['user_id']) && !isset($args['parent'])) {
            
            $post_id = intval($args['post_id']);
            if (isset(self::$comment_count_cache[$post_id])) {
                return self::$comment_count_cache[$post_id];
            }
        }

        $args['count'] = true;
        return (int) self::get_comments($args);
    }
    
    /**
     * 获取评论元数据
     *
     * @param string $key 元数据键名
     * @param bool $single 是否返回单个值
     * @return mixed 元数据值
     */
    public function get_meta($key = '', $single = true) {
        global $tododb;
        
        if (!$this->id) {
            return false;
        }
        
        $where = "comment_id = " . $this->id;
        
        if (!empty($key)) {
            $where .= " AND meta_key = '$key'";
        }
        
        $query = "SELECT meta_key, meta_value FROM " . TABLE_PREFIX . "commentmeta WHERE $where";
        
        $results = $tododb->get_results($query);
        
        if (empty($results)) {
            return $single ? '' : array();
        }
        
        if (!empty($key) && $single) {
            return $results[0]->meta_value;
        }
        
        $meta = array();
        
        foreach ($results as $result) {
            if ($single) {
                $meta[$result->meta_key] = $result->meta_value;
            } else {
                $meta[$result->meta_key][] = $result->meta_value;
            }
        }
        
        return $meta;
    }
    
    /**
     * 更新评论元数据
     *
     * @param string $key 元数据键名
     * @param mixed $value 元数据值
     * @param mixed $prev_value 前一个值
     * @return bool 是否成功更新
     */
    public function update_meta($key, $value, $prev_value = '') {
        global $tododb;
        
        if (!$this->id || empty($key)) {
            return false;
        }
        
        $meta_id = $tododb->get_var("SELECT meta_id FROM " . TABLE_PREFIX . "commentmeta WHERE comment_id = ? AND meta_key = ?", [$this->id, $key]);
        
        if ($meta_id) {
            if (empty($value)) {
                return $this->delete_meta($key, $prev_value);
            }
            
            $where = "meta_id = $meta_id";
            
            if (!empty($prev_value)) {
                $where .= " AND meta_value = '$prev_value'";
            }
            
            $result = $tododb->query("UPDATE " . TABLE_PREFIX . "commentmeta SET meta_value = '$value' WHERE $where");
        } else {
            // 使用 tododb_insert 进行插入，避免旧用法
            $result = tododb_insert(TABLE_PREFIX . 'commentmeta', [
                'comment_id' => $this->id,
                'meta_key'   => $key,
                'meta_value' => $value
            ]) !== false;
        }
        
        return $result ? true : false;
    }
    
    /**
     * 删除评论元数据
     *
     * @param string $key 元数据键名
     * @param mixed $value 元数据值
     * @return bool 是否成功删除
     */
    public function delete_meta($key, $value = '') {
        global $tododb;
        
        if (!$this->id || empty($key)) {
            return false;
        }
        
        $where = "comment_id = " . $this->id . " AND meta_key = '$key'";
        
        if (!empty($value)) {
            $where .= " AND meta_value = '$value'";
        }
        
        $result = $tododb->query("DELETE FROM " . TABLE_PREFIX . "commentmeta WHERE $where");
        
        return $result ? true : false;
    }
}
