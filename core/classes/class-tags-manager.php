<?php
/**
 * TodoCMS 独立标签管理器
 * 
 * 管理独立的标签系统，与分类系统分离
 * 支持按栏目和数据模型筛选标签，保留栏目层级
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

class CMS_Tags_Manager {
    
    /**
     * 数据库实例
     */
    private $db;
    
    /**
     * 静态缓存
     * @var array
     */
    private static $cache = [];

    /**
     * 批量预加载文章标签缓存
     *
     * @param array $post_ids 文章ID数组
     * @return void
     */
    public static function update_post_tags_cache($post_ids) {
        global $tododb;
        
        if (empty($post_ids)) {
            return;
        }
        
        // 过滤无效ID并去重
        $post_ids = array_unique(array_filter(array_map('intval', $post_ids)));
        if (empty($post_ids)) {
            return;
        }
        
        // 检查缓存中是否已存在（部分存在也重新查，简化逻辑，或者只查缺失的）
        // 为简单起见，查缺失的
        $ids_to_query = [];
        foreach ($post_ids as $id) {
            if (!isset(self::$cache[$id])) {
                $ids_to_query[] = $id;
            }
        }
        
        if (empty($ids_to_query)) {
            return;
        }
        
        // 初始化这些ID的缓存为空数组，防止无标签时重复查询
        foreach ($ids_to_query as $id) {
            self::$cache[$id] = [];
        }
        
        $placeholders = implode(',', array_fill(0, count($ids_to_query), '?'));
        $query = "SELECT t.*, pt.post_id FROM {$tododb->prefix}tags AS t
                 INNER JOIN {$tododb->prefix}post_tags AS pt ON t.tag_id = pt.tag_id
                 WHERE pt.post_id IN ($placeholders) AND t.tag_status = 'active'
                 ORDER BY t.tag_order ASC, t.tag_title ASC";
        
        $results = $tododb->get_results($query, $ids_to_query, 'ARRAY_A');
        
        if ($results) {
            foreach ($results as $row) {
                $post_id = $row['post_id'];
                // 移除post_id字段，保持与get_post_tags返回格式一致
                unset($row['post_id']);
                self::$cache[$post_id][] = $row;
            }
        }
    }

    /**
     * 清除文章标签缓存
     * 
     * @param int $post_id 文章ID
     */
    public static function clear_post_tags_cache($post_id) {
        if (isset(self::$cache[$post_id])) {
            unset(self::$cache[$post_id]);
        }
    }
    
    /**
     * 构造函数
     */
    public function __construct() {
        global $tododb;
        if ((!isset($tododb) || !is_object($tododb)) && class_exists('CMS_DB')) {
            try {
                $tododb = CMS_DB::get_instance();
                $GLOBALS['tododb'] = $tododb;
            } catch (Exception $e) {}
        }
        $this->db = $tododb;
    }
    
    private function rollback_tx() {
        if (is_object($this->db)) {
            if (method_exists($this->db, 'rollback')) {
                $this->db->rollback();
            } elseif (method_exists($this->db, 'rollBack')) {
                $this->db->rollBack();
            }
        }
    }
    
    private function begin_tx() {
        if ($this->db instanceof CMS_DB) {
            return $this->db->begin_transaction();
        }
        if ($this->db instanceof PDO) {
            return $this->db->beginTransaction();
        }
        return false;
    }
    
    private function exec_query($sql, $params = []) {
        if ($this->db instanceof CMS_DB) {
            return $this->db->query($sql, $params);
        }
        if ($this->db instanceof PDO) {
            $stmt = $this->db->prepare($sql);
            $stmt->execute(is_array($params) ? $params : []);
            if (stripos(trim($sql), 'SELECT') === 0) {
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            return $stmt->rowCount();
        }
        if (method_exists($this->db, 'prepare')) {
            $stmt = $this->db->prepare($sql);
            $stmt->execute(is_array($params) ? $params : []);
            if (stripos(trim($sql), 'SELECT') === 0) {
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            return $stmt->rowCount();
        }
        return false;
    }
    
    /**
     * 规范化媒体目录路径
     *
     * 仅保留相对路径（字母、数字、中文、斜杠、连字符、下划线、点），
     * 去除首尾斜杠与空白，防止绝对路径或路径穿越。
     *
     * @param string $directory 目录相对路径
     * @return string 规范化后的相对路径
     */
    private function sanitize_media_directory($directory) {
        $directory = is_string($directory) ? trim($directory) : '';
        if ($directory === '') {
            return '';
        }
        // 拒绝绝对路径与路径穿越
        $directory = str_replace('\\', '/', $directory);
        $directory = preg_replace('#/+#', '/', $directory);
        // 仅保留安全字符
        $directory = preg_replace('/[^\p{L}\p{N}\-_.\/]/u', '', $directory);
        $directory = trim($directory, '/');
        // 限制长度
        if (function_exists('mb_strlen') && mb_strlen($directory) > 255) {
            $directory = mb_substr($directory, 0, 255);
        } elseif (strlen($directory) > 255) {
            $directory = substr($directory, 0, 255);
        }
        return $directory;
    }
    
    /**
     * 生成标签slug
     *
     * @param string $text 文本
     * @return string slug
     */
    private function generate_tag_slug($text) {
        // 转换为小写
        $slug = strtolower($text);
        
        // 移除特殊字符，保留字母、数字、中文和连字符
        $slug = preg_replace('/[^\p{L}\p{N}\-_]/u', '-', $slug);
        
        // 移除多余的连字符
        $slug = preg_replace('/-+/', '-', $slug);
        
        // 移除首尾连字符
        $slug = trim($slug, '-');
        
        return $slug;
    }
    
    /**
     * 创建标签
     *
     * @param string $tag_name 标签名称
     * @param string $tag_description 标签描述
     * @param string $tag_color 标签颜色
     * @param string $tag_icon 标签图标
     * @param int $tag_order 标签排序
     * @param string $tag_media_directory 绑定的媒体目录相对路径
     * @param int $auto_inlink 自动内链标签最大数量（0关闭）
     * @param int $auto_image 自动插图数量（受auto_inlink限制，0关闭）
     * @return int|false 标签ID或false
     */
    public function create_tag($tag_name, $tag_description = '', $tag_color = '', $tag_icon = '', $tag_order = 0, $tag_media_directory = '', $auto_inlink = 0, $auto_image = 0) {
        try {
            $tag_name = trim(strip_tags($tag_name));
            $tag_slug = $this->generate_tag_slug($tag_name);
            $tag_description = trim(strip_tags($tag_description));
            $tag_color = trim($tag_color);
            $tag_icon = trim(strip_tags($tag_icon));
            $tag_order = intval($tag_order);
            $tag_media_directory = $this->sanitize_media_directory($tag_media_directory);
            $auto_inlink = max(0, intval($auto_inlink));
            $auto_image = max(0, intval($auto_image));
            
            if (empty($tag_name)) {
                return false;
            }
            
            // 检查标签名称是否已存在
            if ($this->tag_exists($tag_name)) {
                return false;
            }
            
            // 确保 slug 唯一
            $tag_slug = $this->get_unique_slug($tag_slug);
            
            $query = "INSERT INTO {$this->db->prefix}tags 
                     (tag_title, tag_slug, tag_desc, tag_color, tag_icon, tag_media_directory, auto_inlink, auto_image, tag_order) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $this->exec_query($query, [
                $tag_name,
                $tag_slug,
                $tag_description,
                $tag_color,
                $tag_icon,
                $tag_media_directory,
                $auto_inlink,
                $auto_image,
                $tag_order
            ]);
            
            $tag_id = $this->db->insert_id();
            
            // 初始化标签统计
            $this->init_tag_stats($tag_id);
            
            return $tag_id;
        } catch (Exception $e) {
            error_log('创建标签失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 更新标签
     *
     * @param int $tag_id 标签ID
     * @param array $data 更新数据
     * @return bool 是否成功
     */
    public function update_tag($tag_id, $data) {
        try {
            $tag_id = intval($tag_id);
            if ($tag_id <= 0) {
                return false;
            }
            
            $update_fields = [];
            $params = [];
            
            if (isset($data['tag_name'])) {
                $tag_name = trim(strip_tags($data['tag_name']));
                if (!empty($tag_name)) {
                    $update_fields[] = 'tag_title = ?';
                    $params[] = $tag_name;
                }
            }
            
            if (isset($data['tag_slug'])) {
                $tag_slug = $this->generate_tag_slug($data['tag_slug']);
                if (!empty($tag_slug)) {
                    $tag_slug = $this->get_unique_slug($tag_slug, $tag_id);
                    $update_fields[] = 'tag_slug = ?';
                    $params[] = $tag_slug;
                }
            }
            
            if (isset($data['tag_description'])) {
                $update_fields[] = 'tag_desc = ?';
                $params[] = trim(strip_tags($data['tag_description']));
            }
            
            if (isset($data['tag_color'])) {
                $update_fields[] = 'tag_color = ?';
                $params[] = trim($data['tag_color']);
            }
            
            if (isset($data['tag_icon'])) {
                $update_fields[] = 'tag_icon = ?';
                $params[] = trim(strip_tags($data['tag_icon']));
            }
            
            if (array_key_exists('tag_media_directory', $data)) {
                $update_fields[] = 'tag_media_directory = ?';
                $params[] = $this->sanitize_media_directory($data['tag_media_directory']);
            }

            if (array_key_exists('auto_inlink', $data)) {
                $update_fields[] = 'auto_inlink = ?';
                $params[] = max(0, intval($data['auto_inlink']));
            }
            
            if (array_key_exists('auto_image', $data)) {
                $update_fields[] = 'auto_image = ?';
                $params[] = max(0, intval($data['auto_image']));
            }
            
            if (isset($data['tag_order'])) {
                $update_fields[] = 'tag_order = ?';
                $params[] = intval($data['tag_order']);
            }
            
            if (isset($data['tag_status'])) {
                $status = in_array($data['tag_status'], ['active', 'inactive']) ? $data['tag_status'] : 'active';
                $update_fields[] = 'tag_status = ?';
                $params[] = $status;
            }
            
            if (empty($update_fields)) {
                return false;
            }
            
            $params[] = $tag_id;
            $query = "UPDATE {$this->db->prefix}tags SET " . implode(', ', $update_fields) . " WHERE tag_id = ?";
            
            $ok = $this->exec_query($query, $params);
            if ($ok !== false && function_exists('todo_do_action')) {
                todo_do_action('todo_tag_updated', $tag_id, $data);
            }
            return $ok !== false;
        } catch (Exception $e) {
            error_log('更新标签失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 删除标签
     *
     * @param int $tag_id 标签ID
     * @return bool 是否成功
     */
    public function delete_tag($tag_id) {
        try {
            $tag_id = intval($tag_id);
            if ($tag_id <= 0) {
                return false;
            }
            
            $this->begin_tx();
            
            // 删除标签关联关系（外键约束会自动处理）
            $query = "DELETE FROM {$this->db->prefix}tags WHERE tag_id = ?";
            $this->exec_query($query, [$tag_id]);
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            error_log('删除标签失败: ' . $e->getMessage());
            $this->rollback_tx();
            return false;
        }
    }
    
    /**
     * 获取标签
     *
     * @param int $tag_id 标签ID
     * @return array|null 标签数据
     */
    public function get_tag($tag_id) {
        try {
            $tag_id = intval($tag_id);
            if ($tag_id <= 0) {
                return null;
            }
            
            $query = "SELECT t.*, ts.view_count, ts.last_updated,
                     (SELECT COUNT(*) FROM {$this->db->prefix}post_tags AS pt 
                      INNER JOIN {$this->db->prefix}posts AS p ON pt.post_id = p.ID 
                      WHERE pt.tag_id = t.tag_id AND p.post_status != 'trash') as post_count
                     FROM {$this->db->prefix}tags AS t
                     LEFT JOIN {$this->db->prefix}tag_stats AS ts ON t.tag_id = ts.tag_id
                     WHERE t.tag_id = ?";
            
            $result = $this->db->get_results($query, [$tag_id], 'ARRAY_A');
            return !empty($result) ? $result[0] : null;
        } catch (Exception $e) {
            error_log('获取标签失败: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 获取标签列表
     *
     * @param array $args 查询参数
     * @return array 标签列表
     */
    public function get_tags($args = []) {
        try {
            $defaults = [
                'status' => 'active',
                'orderby' => 'tag_order',
                'order' => 'ASC',
                'limit' => 0,
                'offset' => 0,
                'search' => '',
                'category_id' => 0,
                'model_id' => 0,
                'group_id' => 0
            ];
            
            $args = array_merge($defaults, $args);
            
            $where_conditions = [];
            $params = [];
            
            // 状态筛选
            if (!empty($args['status'])) {
                $where_conditions[] = 't.tag_status = ?';
                $params[] = $args['status'];
            }
            
            // 搜索
            if (!empty($args['search'])) {
                $where_conditions[] = '(t.tag_title LIKE ? OR t.tag_desc LIKE ?)';
                $search_term = '%' . $args['search'] . '%';
                $params[] = $search_term;
                $params[] = $search_term;
            }
            
            // 按栏目筛选
            if (!empty($args['category_id'])) {
                $cat_id = intval($args['category_id']);
                $where_conditions[] = "(
                    EXISTS (SELECT 1 FROM {$this->db->prefix}tag_category_relationships AS tcr WHERE tcr.tag_id = t.tag_id AND tcr.term_id = ?)
                    OR 
                    EXISTS (SELECT 1 FROM {$this->db->prefix}post_tags AS pt_cat 
                            INNER JOIN {$this->db->prefix}posts AS p_cat ON pt_cat.post_id = p_cat.ID 
                            WHERE pt_cat.tag_id = t.tag_id AND p_cat.category_id = ?)
                )";
                $params[] = $cat_id;
                $params[] = $cat_id;
            }
            
            // 按数据模型筛选
            if (!empty($args['model_id'])) {
                $mod_id = intval($args['model_id']);
                $where_conditions[] = "(
                    EXISTS (SELECT 1 FROM {$this->db->prefix}tag_model_relationships AS tmr WHERE tmr.tag_id = t.tag_id AND tmr.model_id = ?)
                    OR 
                    EXISTS (SELECT 1 FROM {$this->db->prefix}post_tags AS pt_mod 
                            INNER JOIN {$this->db->prefix}posts AS p_mod ON pt_mod.post_id = p_mod.ID 
                            WHERE pt_mod.tag_id = t.tag_id AND p_mod.model_id = ?)
                )";
                $params[] = $mod_id;
                $params[] = $mod_id;
            }
            
            // 按分组筛选
            $join_group = '';
            if (!empty($args['group_id'])) {
                $join_group = "INNER JOIN {$this->db->prefix}tag_group_relationships AS tgr ON t.tag_id = tgr.tag_id";
                $where_conditions[] = 'tgr.group_id = ?';
                $params[] = intval($args['group_id']);
            }
            
            $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
            
            // 排序
            $orderby = in_array($args['orderby'], ['tag_title', 'tag_order', 'created_at', 'post_count']) ? $args['orderby'] : 'tag_order';
            $order = strtoupper($args['order']) === 'DESC' ? 'DESC' : 'ASC';
            
            if ($orderby === 'post_count') {
                $orderby = 'ts.post_count';
            } else {
                $orderby = 't.' . $orderby;
            }
            
            // 分页
            $limit_clause = '';
            if ($args['limit'] > 0) {
                $limit_clause = 'LIMIT ' . intval($args['offset']) . ', ' . intval($args['limit']);
            }
            
            $query = "SELECT t.*, ts.view_count, ts.last_updated,
                     (SELECT COUNT(*) FROM {$this->db->prefix}post_tags AS pt 
                      INNER JOIN {$this->db->prefix}posts AS p ON pt.post_id = p.ID 
                      WHERE pt.tag_id = t.tag_id AND p.post_status != 'trash') as post_count
                     FROM {$this->db->prefix}tags AS t
                     LEFT JOIN {$this->db->prefix}tag_stats AS ts ON t.tag_id = ts.tag_id
                     {$join_group}
                     {$where_clause}
                     ORDER BY {$orderby} {$order}
                     {$limit_clause}";
            
            return $this->db->get_results($query, $params, 'ARRAY_A') ?: [];
        } catch (Exception $e) {
            error_log('获取标签列表失败: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 获取热门标签（按 post_count 排序）
     *
     * @param int $limit 限制数量（0 为不限）
     * @return array 标签列表
     */
    public function get_popular_tags($limit = 10) {
        try {
            $limit = intval($limit);
            $limit_clause = $limit > 0 ? ('LIMIT ' . $limit) : '';
            $query = "SELECT t.*, ts.view_count, ts.last_updated,
                     (SELECT COUNT(*) FROM {$this->db->prefix}post_tags AS pt 
                      INNER JOIN {$this->db->prefix}posts AS p ON pt.post_id = p.ID 
                      WHERE pt.tag_id = t.tag_id AND p.post_status != 'trash') as post_count
                     FROM {$this->db->prefix}tags AS t 
                     LEFT JOIN {$this->db->prefix}tag_stats AS ts ON t.tag_id = ts.tag_id 
                     WHERE t.tag_status = 'active' 
                     ORDER BY post_count DESC, t.tag_order ASC, t.tag_title ASC 
                     {$limit_clause}";
            return $this->db->get_results($query, [], 'ARRAY_A') ?: [];
        } catch (Exception $e) {
            error_log('获取热门标签失败: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取标签总数
     *
     * @param array $args 查询参数
     * @return int 标签总数
     */
    public function get_tags_count($args = []) {
        try {
            $defaults = [
                'status' => 'active',
                'search' => '',
                'category_id' => 0,
                'model_id' => 0,
                'group_id' => 0
            ];
            
            $args = array_merge($defaults, $args);
            
            $where_conditions = [];
            $params = [];
            
            // 状态筛选
            if (!empty($args['status'])) {
                $where_conditions[] = 't.tag_status = ?';
                $params[] = $args['status'];
            }
            
            // 搜索
            if (!empty($args['search'])) {
                $where_conditions[] = '(t.tag_title LIKE ? OR t.tag_desc LIKE ?)';
                $search_term = '%' . $args['search'] . '%';
                $params[] = $search_term;
                $params[] = $search_term;
            }
            
            // 按栏目筛选
            if (!empty($args['category_id'])) {
                $cat_id = intval($args['category_id']);
                $where_conditions[] = "(
                    EXISTS (SELECT 1 FROM {$this->db->prefix}tag_category_relationships AS tcr WHERE tcr.tag_id = t.tag_id AND tcr.term_id = ?)
                    OR 
                    EXISTS (SELECT 1 FROM {$this->db->prefix}post_tags AS pt_cat 
                            INNER JOIN {$this->db->prefix}posts AS p_cat ON pt_cat.post_id = p_cat.ID 
                            WHERE pt_cat.tag_id = t.tag_id AND p_cat.category_id = ?)
                )";
                $params[] = $cat_id;
                $params[] = $cat_id;
            }
            
            // 按数据模型筛选
            if (!empty($args['model_id'])) {
                $mod_id = intval($args['model_id']);
                $where_conditions[] = "(
                    EXISTS (SELECT 1 FROM {$this->db->prefix}tag_model_relationships AS tmr WHERE tmr.tag_id = t.tag_id AND tmr.model_id = ?)
                    OR 
                    EXISTS (SELECT 1 FROM {$this->db->prefix}post_tags AS pt_mod 
                            INNER JOIN {$this->db->prefix}posts AS p_mod ON pt_mod.post_id = p_mod.ID 
                            WHERE pt_mod.tag_id = t.tag_id AND p_mod.model_id = ?)
                )";
                $params[] = $mod_id;
                $params[] = $mod_id;
            }
            
            // 按分组筛选
            $join_group = '';
            if (!empty($args['group_id'])) {
                $join_group = "INNER JOIN {$this->db->prefix}tag_group_relationships AS tgr ON t.tag_id = tgr.tag_id";
                $where_conditions[] = 'tgr.group_id = ?';
                $params[] = intval($args['group_id']);
            }
            
            $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
            
            $query = "SELECT COUNT(DISTINCT t.tag_id) as total 
                     FROM {$this->db->prefix}tags AS t
                     {$join_group}
                     {$where_clause}";
            
            $total = $this->db->get_var($query, $params);
            return $total ? intval($total) : 0;
        } catch (Exception $e) {
            error_log('获取标签总数失败: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * 为文章添加标签
     *
     * @param int $post_id 文章ID
     * @param array $tag_ids 标签ID数组
     * @return bool 是否成功
     */
    public function add_post_tags($post_id, $tag_ids) {
        $own_transaction = false;
        try {
            $post_id = intval($post_id);
            if ($post_id <= 0) {
                return false;
            }

            // 仅在当前没有外层事务时自建事务，避免打断调用方（如 todo_insert_post）的事务
            if (!$this->db->in_transaction()) {
                $own_transaction = (bool) $this->db->begin_transaction();
            }
            
            // 获取原有的标签ID，用于更新统计
            $old_tags_query = "SELECT tag_id FROM {$this->db->prefix}post_tags WHERE post_id = ?";
            $old_tags_result = $this->exec_query($old_tags_query, [$post_id]);
            $old_tag_ids = [];
            if (!empty($old_tags_result)) {
                foreach ($old_tags_result as $row) {
                    $old_tag_ids[] = $row['tag_id'];
                }
            }
            
            // 删除现有关联
            $delete_query = "DELETE FROM {$this->db->prefix}post_tags WHERE post_id = ?";
            $this->exec_query($delete_query, [$post_id]);
            
            // 如果有标签ID，则添加新关联
            if (!empty($tag_ids)) {
                foreach ($tag_ids as $tag_id) {
                    $tag_id = intval($tag_id);
                    if ($tag_id > 0) {
                        $insert_query = "INSERT IGNORE INTO {$this->db->prefix}post_tags (post_id, tag_id) VALUES (?, ?)";
                        $this->exec_query($insert_query, [$post_id, $tag_id]);
                    }
                }
            }
            
            // 更新所有相关标签的统计（包括原有的和新的）
            $all_affected_tag_ids = array_unique(array_merge($old_tag_ids, $tag_ids ?: []));
            if (!empty($all_affected_tag_ids)) {
                $this->update_tags_stats($all_affected_tag_ids);
            }
            
            // 清除该文章的标签缓存
            self::clear_post_tags_cache($post_id);
            
            if ($own_transaction) {
                $this->db->commit();
            }

            // 触发标签更新钩子，通知其他系统（如自动特色图像）
            if (function_exists('todo_do_action')) {
                todo_do_action('todo_post_tags_updated', $post_id);
            }

            return true;
        } catch (Exception $e) {
            error_log('添加文章标签失败: ' . $e->getMessage());
            if ($own_transaction) {
                $this->rollback_tx();
            }
            return false;
        }
    }
    
    /**
     * 获取文章的标签
     *
     * @param int $post_id 文章ID
     * @return array 标签列表
     */
    public function get_post_tags($post_id) {
        try {
            $post_id = intval($post_id);
            if ($post_id <= 0) {
                return [];
            }
            
            // 检查静态缓存
            if (isset(self::$cache[$post_id])) {
                return self::$cache[$post_id];
            }
            
            $query = "SELECT t.* FROM {$this->db->prefix}tags AS t
                     INNER JOIN {$this->db->prefix}post_tags AS pt ON t.tag_id = pt.tag_id
                     WHERE pt.post_id = ? AND t.tag_status = 'active'
                     ORDER BY t.tag_order ASC, t.tag_title ASC";
            
            $result = $this->db->get_results($query, [$post_id], 'ARRAY_A') ?: [];
            
            // 写入缓存
            self::$cache[$post_id] = $result;
            
            return $result;
        } catch (Exception $e) {
            error_log('获取文章标签失败: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 检查标签是否存在
     *
     * @param string $tag_name 标签名称
     * @param int $exclude_id 排除的标签ID
     * @return bool 是否存在
     */
    private function tag_exists($tag_name, $exclude_id = 0) {
        $query = "SELECT tag_id FROM {$this->db->prefix}tags WHERE tag_title = ?";
        $params = [$tag_name];
        
        if ($exclude_id > 0) {
            $query .= " AND tag_id != ?";
            $params[] = $exclude_id;
        }
        
        $tag_id = $this->db->get_var($query . " LIMIT 1", $params);
        return !empty($tag_id);
    }
    
    /**
     * 获取唯一的slug
     *
     * @param string $slug 原始slug
     * @param int $exclude_id 排除的标签ID
     * @return string 唯一slug
     */
    private function get_unique_slug($slug, $exclude_id = 0) {
        $original_slug = $slug;
        $counter = 1;
        
        while ($this->slug_exists($slug, $exclude_id)) {
            $slug = $original_slug . '-' . $counter;
            $counter++;
        }
        
        return $slug;
    }
    
    /**
     * 检查slug是否存在
     *
     * @param string $slug slug
     * @param int $exclude_id 排除的标签ID
     * @return bool 是否存在
     */
    private function slug_exists($slug, $exclude_id = 0) {
        $query = "SELECT tag_id FROM {$this->db->prefix}tags WHERE tag_slug = ?";
        $params = [$slug];
        
        if ($exclude_id > 0) {
            $query .= " AND tag_id != ?";
            $params[] = $exclude_id;
        }
        
        $tag_id = $this->db->get_var($query . " LIMIT 1", $params);
        return !empty($tag_id);
    }
    
    /**
     * 初始化标签统计
     *
     * @param int $tag_id 标签ID
     */
    private function init_tag_stats($tag_id) {
        try {
            $query = "INSERT INTO {$this->db->prefix}tag_stats (tag_id, post_count) VALUES (?, 0)";
            $this->exec_query($query, [$tag_id]);
        } catch (Exception $e) {
            error_log('初始化标签统计失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 更新标签统计
     *
     * @param array $tag_ids 标签ID数组
     */
    private function update_tags_stats($tag_ids) {
        try {
            foreach ($tag_ids as $tag_id) {
                $tag_id = intval($tag_id);
                if ($tag_id > 0) {
                    $count_query = "SELECT COUNT(*) as count FROM {$this->db->prefix}post_tags AS pt 
                                     INNER JOIN {$this->db->prefix}posts AS p ON pt.post_id = p.ID 
                                     WHERE pt.tag_id = ? AND p.post_status != 'trash'";
                    $post_count = intval($this->db->get_var($count_query, [$tag_id]));
                    
                    $update_query = "UPDATE {$this->db->prefix}tag_stats SET post_count = ?, last_updated = NOW() WHERE tag_id = ?";
                    $this->exec_query($update_query, [$post_count, $tag_id]);
                }
            }
        } catch (Exception $e) {
            error_log('更新标签统计失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 从标签字符串或数组获取或创建标签ID
     *
     * @param string|array $tags 标签名称字符串或数组
     * @return array 标签ID数组
     */
    public function get_or_create_tag_ids($tags) {
        if (empty($tags)) {
            return [];
        }
        
        // 处理标签字符串
        if (is_string($tags)) {
            // 支持英文逗号、中文逗号以及全角/半角空格分隔
            $tags = preg_split('/[,，\s]+/u', $tags);
            $tags = array_map('trim', $tags);
            $tags = array_filter($tags, 'strlen'); // 移除空值
        }
        
        $tag_ids = [];
        
        // 为每个标签获取或创建ID
        foreach ($tags as $tag_name) {
            if (empty($tag_name)) {
                continue;
            }
            
            // 查找标签是否存在
            $tag_id = $this->db->get_var("SELECT tag_id FROM {$this->db->prefix}tags WHERE tag_title = ? LIMIT 1", [$tag_name]);
            
            if (!empty($tag_id)) {
                $tag_ids[] = intval($tag_id);
            } else {
                // 创建新标签
                $tag_id = $this->create_tag($tag_name);
                
                if ($tag_id) {
                    $tag_ids[] = $tag_id;
                }
            }
        }
        
        return $tag_ids;
    }
    
    /**
     * 设置文章标签（便捷方法）
     *
     * @param int $post_id 文章ID
     * @param string|array $tags 标签名称字符串或数组
     * @return bool 是否成功
     */
    public function set_post_tags($post_id, $tags) {
        $tag_ids = $this->get_or_create_tag_ids($tags);
        return $this->add_post_tags($post_id, $tag_ids);
    }
    
    /**
     * 获取最近使用的标签
     *
     * @param int $limit 限制数量，默认10
     * @return array 最近使用的标签列表
     */
    public function get_recent_used_tags($limit = 10) {
        try {
            $query = "SELECT t.*, ts.post_count, ts.last_updated 
                     FROM {$this->db->prefix}tags AS t
                     INNER JOIN {$this->db->prefix}tag_stats AS ts ON t.tag_id = ts.tag_id
                     WHERE t.tag_status = 'active' AND ts.last_updated IS NOT NULL
                     ORDER BY ts.last_updated DESC
                     LIMIT ?";
            
            return $this->db->get_results($query, [intval($limit)], 'ARRAY_A') ?: [];
        } catch (Exception $e) {
            error_log('获取最近使用标签失败: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取最近添加的标签
     *
     * @param int $limit 限制数量，默认10
     * @return array 最近添加的标签列表
     */
    public function get_recent_added_tags($limit = 10) {
        try {
            $query = "SELECT t.*, ts.post_count, ts.last_updated 
                     FROM {$this->db->prefix}tags AS t
                     LEFT JOIN {$this->db->prefix}tag_stats AS ts ON t.tag_id = ts.tag_id
                     WHERE t.tag_status = 'active'
                     ORDER BY t.created_at DESC
                     LIMIT ?";
            
            return $this->db->get_results($query, [intval($limit)], 'ARRAY_A') ?: [];
        } catch (Exception $e) {
            error_log('获取最近添加标签失败: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取推荐标签（最近使用或最近添加，去重）
     *
     * @param int $limit 限制数量，默认10
     * @return array 推荐标签列表
     */
    public function get_suggested_tags($limit = 10) {
        try {
            // 先获取最近使用的标签
            $recent_used = $this->get_recent_used_tags($limit);
            
            // 如果最近使用的标签不足，补充最近添加的标签
            if (count($recent_used) < $limit) {
                $recent_added = $this->get_recent_added_tags($limit * 2); // 获取更多以便去重
                
                // 去重：排除已经在最近使用中的标签
                $used_ids = array_column($recent_used, 'tag_id');
                $additional_tags = [];
                
                foreach ($recent_added as $tag) {
                    if (!in_array($tag['tag_id'], $used_ids) && count($recent_used) + count($additional_tags) < $limit) {
                        $additional_tags[] = $tag;
                    }
                }
                
                // 合并结果
                $recent_used = array_merge($recent_used, $additional_tags);
            }
            
            return array_slice($recent_used, 0, $limit);
        } catch (Exception $e) {
            error_log('获取推荐标签失败: ' . $e->getMessage());
            return [];
        }
    }
}
