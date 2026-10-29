<?php
/**
 * TodoCMS 标签管理器
 * 
 * 管理强大的标签系统，支持按栏目和数据模型筛选标签，保留栏目层级
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    exit('Direct access not permitted');
}

class Todo_Tag_Manager {
    
    /**
     * 数据库连接
     * @var CMS_DB
     */
    private static $tododb;
    
    /**
     * 标签缓存
     * @var array
     */
    private static $tags_cache = array();
    
    /**
     * 标签统计缓存
     * @var array
     */
    private static $tag_stats_cache = array();
    
    /**
     * 初始化标签管理器
     */
    public static function init() {
        global $tododb;
        self::$tododb = $tododb;
    }
    
    /**
     * 确保数据库连接已初始化
     */
    private static function ensure_db_connection() {
        global $tododb;
        
        // 如果已经初始化，直接返回
        if (self::$tododb !== null && self::$tododb instanceof CMS_DB) {
            return;
        }
        
        // 尝试从全局变量获取
        if (isset($tododb) && $tododb instanceof CMS_DB) {
            self::$tododb = $tododb;
            return;
        }
        
        // 如果全局变量不存在或不是 CMS_DB 实例，尝试创建新实例
        if (class_exists('CMS_DB')) {
            try {
                self::$tododb = CMS_DB::get_instance();
                // 同时更新全局变量
                $GLOBALS['tododb'] = self::$tododb;
                $tododb = self::$tododb;
            } catch (Exception $e) {
                error_log('Todo_Tag_Manager: 无法初始化数据库连接 - ' . $e->getMessage());
                throw new Exception('数据库连接不可用');
            }
        } else {
            throw new Exception('CMS_DB 类不存在，无法初始化数据库连接');
        }
    }
    
    /**
     * 创建标签
     * 
     * @param array $tag_data 标签数据
     * @return bool|int 成功返回标签ID，失败返回false
     */
    public static function create_tag($tag_data) {
        self::ensure_db_connection();
        
        if (empty($tag_data['name'])) {
            return false;
        }
        
        // 检查标签是否已存在
        if (self::tag_exists($tag_data['name'])) {
            return false;
        }
        
        // 生成URL别名
        if (empty($tag_data['slug'])) {
            $tag_data['slug'] = self::generate_slug($tag_data['name']);
        }
        
        // 确保URL别名唯一
        $tag_data['slug'] = self::ensure_unique_slug($tag_data['slug']);
        
        $sort_order = isset($tag_data['sort_order']) ? intval($tag_data['sort_order']) : 0;
        $status = isset($tag_data['status']) ? (($tag_data['status'] == 1 || $tag_data['status'] === 'active') ? 'active' : 'inactive') : 'active';
        
        $query = "INSERT INTO " . DB_PREFIX . "tags 
                  (tag_title, tag_slug, tag_desc, tag_order, tag_status, created_at) 
                  VALUES (?, ?, ?, ?, ?, NOW())";
        
        $result = self::$tododb->query($query, array(
            $tag_data['name'],
            $tag_data['slug'],
            $tag_data['description'] ?? '',
            $sort_order,
            $status
        ));
        
        if ($result) {
            $tag_id = self::$tododb->insert_id();
            
            // 清除缓存
            self::clear_cache();
            
            return $tag_id;
        }
        
        return false;
    }
    
    /**
     * 更新标签
     * 
     * @param int $tag_id 标签ID
     * @param array $tag_data 标签数据
     * @return bool
     */
    public static function update_tag($tag_id, $tag_data) {
        self::ensure_db_connection();
        
        // 如果更新了name，检查是否重复
        if (isset($tag_data['name']) && self::tag_exists($tag_data['name'], $tag_id)) {
            return false;
        }
        
        // 如果更新了slug，确保唯一性
        if (isset($tag_data['slug'])) {
            $tag_data['slug'] = self::ensure_unique_slug($tag_data['slug'], $tag_id);
        }
        
        $set_clauses = array();
        $params = array();
        
        // 可更新的字段映射
        $updatable_fields = array(
            'name' => array('col' => 'tag_title', 'type' => 's'),
            'slug' => array('col' => 'tag_slug', 'type' => 's'),
            'description' => array('col' => 'tag_desc', 'type' => 's'),
            'sort_order' => array('col' => 'tag_order', 'type' => 'i'),
            'status' => array('col' => 'tag_status', 'type' => 's')
        );
        
        foreach ($updatable_fields as $field => $info) {
            if (isset($tag_data[$field])) {
                $set_clauses[] = "{$info['col']} = ?";
                if ($field === 'status') {
                    $val = ($tag_data[$field] == 1 || $tag_data[$field] === 'active') ? 'active' : 'inactive';
                } else {
                    $val = $tag_data[$field];
                }
                $params[] = $val;
            }
        }
        
        if (empty($set_clauses)) {
            return false;
        }
        
        $set_clauses[] = 'updated_at = NOW()';
        $params[] = $tag_id;
        
        $query = "UPDATE " . DB_PREFIX . "tags SET " . implode(', ', $set_clauses) . " WHERE tag_id = ?";
        
        $result = self::$tododb->query($query, $params);
        
        if ($result) {
            // 清除缓存
            self::clear_cache();
            return true;
        }
        
        return false;
    }
    
    /**
     * 删除标签
     * 
     * @param int $tag_id 标签ID
     * @param bool $delete_relations 是否删除关联关系
     * @return bool
     */
    public static function delete_tag($tag_id, $delete_relations = true) {
        self::ensure_db_connection();
        if ($delete_relations) {
            // 删除内容标签关联
            $delete_relations_query = "DELETE FROM " . DB_PREFIX . "content_tags WHERE tag_id = ?";
            self::$tododb->query($delete_relations_query, array($tag_id));
        }
        
        // 删除标签
        $query = "DELETE FROM " . DB_PREFIX . "tags WHERE tag_id = ?";
        
        $result = self::$tododb->query($query, array($tag_id));
        
        if ($result) {
            // 清除缓存
            self::clear_cache();
            return true;
        }
        
        return false;
    }
    
    /**
     * 规范化标签数据，支持 friendly 字段与 DB 字段双重兼容
     * 
     * @param array|object $tag 标签数据
     * @return array|object
     */
    private static function normalize_tag($tag) {
        if (!$tag) {
            return $tag;
        }
        if (is_object($tag)) {
            if (!($tag instanceof stdClass)) {
                $tag = (object) get_object_vars($tag);
            } else {
                $tag = clone $tag;
            }
            if (isset($tag->tag_id)) {
                $tag->id = intval($tag->tag_id);
            }
            if (isset($tag->tag_title)) {
                $tag->name = $tag->tag_title;
            }
            if (isset($tag->name)) {
                $t_name = (string) $tag->name;
                if (function_exists('todo_parse_inline_translations')) {
                    $t_name = todo_parse_inline_translations($t_name);
                }
                if (function_exists('todo_translate')) {
                    $t_name = todo_translate($t_name);
                }
                $tag->name = $t_name;
                $tag->tag_title = $t_name;
            }
            if (isset($tag->tag_slug)) {
                $tag->slug = $tag->tag_slug;
            }
            if (isset($tag->tag_desc)) {
                $tag->description = $tag->tag_desc;
            }
            if (isset($tag->tag_order)) {
                $tag->sort_order = intval($tag->tag_order);
            }
            if (isset($tag->tag_status)) {
                $tag->status = $tag->tag_status === 'active' ? 1 : 0;
            }
        } elseif (is_array($tag)) {
            if (isset($tag['tag_id'])) {
                $tag['id'] = intval($tag['tag_id']);
            }
            if (isset($tag['tag_title'])) {
                $tag['name'] = $tag['tag_title'];
            }
            if (isset($tag['name'])) {
                $t_name = (string) $tag['name'];
                if (function_exists('todo_parse_inline_translations')) {
                    $t_name = todo_parse_inline_translations($t_name);
                }
                if (function_exists('todo_translate')) {
                    $t_name = todo_translate($t_name);
                }
                $tag['name'] = $t_name;
                $tag['tag_title'] = $t_name;
            }
            if (isset($tag['tag_slug'])) {
                $tag['slug'] = $tag['tag_slug'];
            }
            if (isset($tag['tag_desc'])) {
                $tag['description'] = $tag['tag_desc'];
            }
            if (isset($tag['tag_order'])) {
                $tag['sort_order'] = intval($tag['tag_order']);
            }
            if (isset($tag['tag_status'])) {
                $tag['status'] = $tag['tag_status'] === 'active' ? 1 : 0;
            }
        }
        return $tag;
    }
    
    /**
     * 获取标签信息
     * 
     * @param int $tag_id 标签ID
     * @return array|null
     */
    public static function get_tag($tag_id) {
        self::ensure_db_connection();
        
        if (isset(self::$tags_cache[$tag_id])) {
            return self::$tags_cache[$tag_id];
        }
        $ttl = function_exists('todo_get_fragment_cache_ttl') ? (int) todo_get_fragment_cache_ttl() : 120;
        $lang = function_exists('todo_get_current_language') ? todo_get_current_language() : (function_exists('get_current_language') ? get_current_language() : null);
        if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
            $cached = Todo_Cache_Manager::get('tag_' . $tag_id, 'tags', $lang);
            if ($cached !== false) {
                $cached = self::normalize_tag($cached);
                self::$tags_cache[$tag_id] = $cached;
                return $cached;
            }
        }
        $query = "SELECT * FROM " . DB_PREFIX . "tags WHERE tag_id = ?";
        $tag = self::$tododb->get_row($query, array($tag_id));
        if ($tag) {
            $tag = self::normalize_tag($tag);
            self::$tags_cache[$tag_id] = $tag;
            if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set('tag_' . $tag_id, $tag, $ttl, 'tags', $lang);
            }
            return $tag;
        }
        return null;
    }
    
    /**
     * 根据名称获取标签
     * 
     * @param string $name 标签名称
     * @return array|null
     */
    public static function get_tag_by_name($name) {
        self::ensure_db_connection();
        
        $ttl = function_exists('todo_get_fragment_cache_ttl') ? (int) todo_get_fragment_cache_ttl() : 120;
        $lang = function_exists('todo_get_current_language') ? todo_get_current_language() : (function_exists('get_current_language') ? get_current_language() : null);
        if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
            $cached = Todo_Cache_Manager::get('tag_by_name_' . md5($name), 'tags', $lang);
            if ($cached !== false) {
                return self::normalize_tag($cached);
            }
        }
        $query = "SELECT * FROM " . DB_PREFIX . "tags WHERE tag_title = ? AND tag_status = 'active'";
        $tag = self::$tododb->get_row($query, array($name));
        if ($tag) {
            $tag = self::normalize_tag($tag);
            if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set('tag_by_name_' . md5($name), $tag, $ttl, 'tags', $lang);
            }
            return $tag;
        }
        return null;
    }
    
    /**
     * 根据slug获取标签
     * 
     * @param string $slug URL别名
     * @return array|null
     */
    public static function get_tag_by_slug($slug) {
        self::ensure_db_connection();
        
        $ttl = function_exists('todo_get_fragment_cache_ttl') ? (int) todo_get_fragment_cache_ttl() : 120;
        $lang = function_exists('todo_get_current_language') ? todo_get_current_language() : (function_exists('get_current_language') ? get_current_language() : null);
        if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
            $cached = Todo_Cache_Manager::get('tag_by_slug_' . md5($slug), 'tags', $lang);
            if ($cached !== false) {
                return self::normalize_tag($cached);
            }
        }
        $query = "SELECT * FROM " . DB_PREFIX . "tags WHERE tag_slug = ? AND tag_status = 'active'";
        $tag = self::$tododb->get_row($query, array($slug));
        if ($tag) {
            $tag = self::normalize_tag($tag);
            if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set('tag_by_slug_' . md5($slug), $tag, $ttl, 'tags', $lang);
            }
            return $tag;
        }
        return null;
    }
    
    /**
     * 获取所有标签
     * 
     * @param array $args 查询参数
     * @return array
     */
    public static function get_tags($args = array()) {
        self::ensure_db_connection();
        
        $ttl = function_exists('todo_get_fragment_cache_ttl') ? (int) todo_get_fragment_cache_ttl() : 120;
        $lang = function_exists('todo_get_current_language') ? todo_get_current_language() : (function_exists('get_current_language') ? get_current_language() : null);
        if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
            $k = 'tags_list_' . md5(json_encode($args));
            $cached = Todo_Cache_Manager::get($k, 'tags', $lang);
            if ($cached !== false && is_array($cached)) {
                return $cached;
            }
        }
        // 默认参数
        $defaults = array(
            'status' => 1,
            'model_key' => null,
            'category_id' => null,
            'category_ids' => null,
            'keyword' => null,
            'order_by' => 'name',
            'order' => 'ASC',
            'limit' => null,
            'offset' => 0,
            'include_count' => false
        );
        
        $args = array_merge($defaults, $args);
        
        $where_clauses = array();
        $params = array();
        $types = '';
        $join_sql = '';
        
        // 基础WHERE条件
        if ($args['status'] !== null) {
            $where_clauses[] = 't.tag_status = ?';
            $params[] = ($args['status'] == 1) ? 'active' : 'inactive';
            $types .= 's';
        }
        
        if (!empty($args['keyword'])) {
            $where_clauses[] = '(t.tag_title LIKE ? OR t.tag_desc LIKE ?)';
            $keyword = '%' . $args['keyword'] . '%';
            $params[] = $keyword;
            $params[] = $keyword;
            $types .= 'ss';
        }
        
        // 按模型筛选
        if (!empty($args['model_key'])) {
            $join_sql .= " INNER JOIN " . DB_PREFIX . "content_tags ct ON t.tag_id = ct.tag_id";
            $where_clauses[] = 'ct.model_key = ?';
            $params[] = $args['model_key'];
            $types .= 's';
        }
        
        // 按栏目筛选
        if (!empty($args['category_id']) || !empty($args['category_ids'])) {
            if (empty($join_sql)) {
                $join_sql .= " INNER JOIN " . DB_PREFIX . "content_tags ct ON t.tag_id = ct.tag_id";
            }
            
            // 获取模型信息以确定内容表
            $model_key = isset($args['model_key']) ? $args['model_key'] : '';
            if (empty($model_key) && !empty($args['category_id'])) {
                $category = Todo_Category_Manager::get_category(intval($args['category_id']));
                if ($category && isset($category['model_key'])) {
                    $model_key = $category['model_key'];
                }
            }
            
            if (!empty($model_key)) {
                $model = Todo_Model_Manager::get_model($model_key);
                if ($model) {
                    $content_table = DB_PREFIX . $model['table_suffix'];
                    $join_sql .= " INNER JOIN `{$content_table}` c ON ct.content_id = c.id";
                    
                    if (!empty($args['category_id'])) {
                        $where_clauses[] = 'c.category_id = ?';
                        $params[] = intval($args['category_id']);
                        $types .= 'i';
                    } elseif (!empty($args['category_ids'])) {
                        $category_ids_array = is_array($args['category_ids']) ? $args['category_ids'] : array();
                        if (!empty($category_ids_array)) {
                            $category_ids = array_map('intval', $category_ids_array);
                            $placeholders = str_repeat('?,', count($category_ids) - 1) . '?';
                            $where_clauses[] = "c.category_id IN ({$placeholders})";
                            $params = array_merge($params, $category_ids);
                            $types .= str_repeat('i', count($category_ids));
                        }
                    }
                }
            }
        }
        
        $where_sql = empty($where_clauses) ? '' : 'WHERE ' . implode(' AND ', $where_clauses);
        
        // 构建SELECT字段
        $select_fields = 't.*';
        if ($args['include_count']) {
            $select_fields .= ', COUNT(DISTINCT ct.content_id) as content_count';
        }
        
        // 构建ORDER BY
        $order_map = array(
            'id' => 'tag_id',
            'tag_id' => 'tag_id',
            'name' => 'tag_title',
            'tag_title' => 'tag_title',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
            'sort_order' => 'tag_order',
            'tag_order' => 'tag_order',
            'count' => 'tag_count',
            'tag_count' => 'tag_count',
            'content_count' => 'content_count'
        );
        
        $order_key = $args['order_by'];
        $order_by = isset($order_map[$order_key]) ? $order_map[$order_key] : 'tag_title';
        $order = strtoupper($args['order']) === 'DESC' ? 'DESC' : 'ASC';
        
        // content_count 是别名，不需要表前缀
        if ($order_by === 'content_count') {
            $order_sql = "ORDER BY {$order_by} {$order}";
        } else {
            $order_sql = "ORDER BY t.{$order_by} {$order}";
        }
        
        // GROUP BY（当包含统计或有JOIN时）
        $group_sql = '';
        if ($args['include_count'] || !empty($join_sql)) {
            $group_sql = 'GROUP BY t.tag_id';
        }
        
        // 构建LIMIT
        $limit_sql = '';
        if ($args['limit'] !== null) {
            $limit_sql = "LIMIT " . intval($args['offset']) . ", " . intval($args['limit']);
        }
        
        $query = "SELECT {$select_fields} FROM " . DB_PREFIX . "tags t {$join_sql} {$where_sql} {$group_sql} {$order_sql} {$limit_sql}";
        
        // 检查查询是否涉及 content_tags 表，如果涉及但表不存在，直接返回空数组
        if (strpos($query, DB_PREFIX . 'content_tags') !== false) {
            // 先尝试检查表是否存在（可选，如果性能要求高可以跳过）
            // 这里直接调用 get_results，如果表不存在，它会返回空数组而不是抛出异常
        }
        
        try {
            if (!empty($params)) {
                $tags = self::$tododb->get_results($query, $params);
            } else {
                $tags = self::$tododb->get_results($query);
            }
            // 确保返回的是数组
            if (!is_array($tags)) {
                $tags = array();
            }
        } catch (PDOException $e) {
            // 如果表不存在或其他数据库错误，记录日志并返回空数组
            $error_msg = $e->getMessage();
            if (strpos($error_msg, "doesn't exist") !== false || 
                strpos($error_msg, 'Base table or view not found') !== false) {
                error_log('Todo_Tag_Manager::get_tags() - Table not found: ' . $error_msg);
            } else {
                error_log('Todo_Tag_Manager::get_tags() error: ' . $error_msg);
            }
            $tags = array();
        } catch (Exception $e) {
            // 捕获所有其他类型的异常
            error_log('Todo_Tag_Manager::get_tags() exception: ' . $e->getMessage());
            $tags = array();
        }
        
        if ($tags) {
            foreach ($tags as $k => $tag) {
                $tags[$k] = self::normalize_tag($tag);
            }
            // 请求级缓存保留原逻辑，避免同请求重复查询
            foreach ($tags as $tag) {
                if (is_array($tag) && isset($tag['tag_id'])) {
                    self::$tags_cache[$tag['tag_id']] = $tag;
                } elseif (is_object($tag) && isset($tag->tag_id)) {
                    self::$tags_cache[$tag->tag_id] = $tag;
                }
            }
        }
        $result = $tags ?: array();
        if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::set('tags_list_' . md5(json_encode($args)), $result, $ttl, 'tags', $lang);
        }
        return $result;
    }
    
    /**
     * 获取标签云数据
     * 
     * @param array $args 查询参数
     * @return array
     */
    public static function get_tag_cloud($args = array()) {
        try {
            $args['include_count'] = true;
            $args['order_by'] = 'content_count';
            $args['order'] = 'DESC';
            
            $tags = self::get_tags($args);
            // 确保返回的是数组
            if (!is_array($tags)) {
                $tags = array();
            }
            
            // 计算权重
            if (!empty($tags)) {
                $max_count = max(array_column($tags, 'content_count'));
                $min_count = min(array_column($tags, 'content_count'));
                
                foreach ($tags as &$tag) {
                    if ($max_count > $min_count) {
                        $tag['weight'] = ($tag['content_count'] - $min_count) / ($max_count - $min_count);
                    } else {
                        $tag['weight'] = 1;
                    }
                    
                    // 计算字体大小等级（1-5）
                    $tag['size_level'] = ceil($tag['weight'] * 5);
                    if ($tag['size_level'] < 1) $tag['size_level'] = 1;
                }
            }
            
            return $tags;
        } catch (PDOException $e) {
            $error_msg = $e->getMessage();
            if (strpos($error_msg, "doesn't exist") !== false || 
                strpos($error_msg, 'Base table or view not found') !== false) {
                error_log('Todo_Tag_Manager::get_tag_cloud() - Table not found: ' . $error_msg);
            } else {
                error_log('Todo_Tag_Manager::get_tag_cloud() error: ' . $error_msg);
            }
            return array();
        } catch (Exception $e) {
            error_log('Todo_Tag_Manager::get_tag_cloud() exception: ' . $e->getMessage());
            return array();
        }
    }
    
    /**
     * 按栏目获取标签（保留栏目层级）
     * 
     * @param int $category_id 栏目ID
     * @param bool $include_children 是否包含子栏目
     * @param array $args 额外查询参数
     * @return array
     */
    public static function get_tags_by_category($category_id, $include_children = false, $args = array()) {
        try {
            $category_ids = array($category_id);
            
            if ($include_children) {
                $children = Todo_Category_Manager::get_children($category_id, true);
                if (is_array($children)) {
                    foreach ($children as $child) {
                        if (isset($child['id'])) {
                            $category_ids[] = $child['id'];
                        }
                    }
                }
            }
            
            $args['category_ids'] = $category_ids;
            
            $tags = self::get_tags($args);
            // 确保返回的是数组
            return is_array($tags) ? $tags : array();
        } catch (PDOException $e) {
            $error_msg = $e->getMessage();
            if (strpos($error_msg, "doesn't exist") !== false || 
                strpos($error_msg, 'Base table or view not found') !== false) {
                error_log('Todo_Tag_Manager::get_tags_by_category() - Table not found: ' . $error_msg);
            } else {
                error_log('Todo_Tag_Manager::get_tags_by_category() error: ' . $error_msg);
            }
            return array();
        } catch (Exception $e) {
            error_log('Todo_Tag_Manager::get_tags_by_category() exception: ' . $e->getMessage());
            return array();
        }
    }
    
    /**
     * 按数据模型获取标签（保留模型相关的栏目层级）
     * 
     * @param string $model_key 模型标识
     * @param array $args 额外查询参数
     * @return array
     */
    public static function get_tags_by_model($model_key, $args = array()) {
        try {
            $args['model_key'] = $model_key;
            
            $tags = self::get_tags($args);
            // 确保返回的是数组
            return is_array($tags) ? $tags : array();
        } catch (PDOException $e) {
            $error_msg = $e->getMessage();
            if (strpos($error_msg, "doesn't exist") !== false || 
                strpos($error_msg, 'Base table or view not found') !== false) {
                error_log('Todo_Tag_Manager::get_tags_by_model() - Table not found: ' . $error_msg);
            } else {
                error_log('Todo_Tag_Manager::get_tags_by_model() error: ' . $error_msg);
            }
            return array();
        } catch (Exception $e) {
            error_log('Todo_Tag_Manager::get_tags_by_model() exception: ' . $e->getMessage());
            return array();
        }
    }
    
    /**
     * 获取热门标签
     * 
     * @param int $limit 数量限制
     * @param array $args 额外查询参数
     * @return array
     */
    public static function get_popular_tags($limit = 10, $args = array()) {
        try {
            $args['include_count'] = true;
            $args['order_by'] = 'content_count';
            $args['order'] = 'DESC';
            $args['limit'] = $limit;
            
            $tags = self::get_tags($args);
            // 确保返回的是数组
            return is_array($tags) ? $tags : array();
        } catch (PDOException $e) {
            $error_msg = $e->getMessage();
            if (strpos($error_msg, "doesn't exist") !== false || 
                strpos($error_msg, 'Base table or view not found') !== false) {
                error_log('Todo_Tag_Manager::get_popular_tags() - Table not found: ' . $error_msg);
            } else {
                error_log('Todo_Tag_Manager::get_popular_tags() error: ' . $error_msg);
            }
            return array();
        } catch (Exception $e) {
            error_log('Todo_Tag_Manager::get_popular_tags() exception: ' . $e->getMessage());
            return array();
        }
    }
    
    /**
     * 获取相关标签
     * 
     * @param int $tag_id 标签ID
     * @param int $limit 数量限制
     * @return array
     */
    public static function get_related_tags($tag_id, $limit = 10) {
        self::ensure_db_connection();
        
        $ttl = function_exists('todo_get_fragment_cache_ttl') ? (int) todo_get_fragment_cache_ttl() : 120;
        $lang = function_exists('todo_get_current_language') ? todo_get_current_language() : (function_exists('get_current_language') ? get_current_language() : null);
        if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
            $k = 'related_tags_' . $tag_id . '_' . (int)$limit;
            $cached = Todo_Cache_Manager::get($k, 'tags', $lang);
            if ($cached !== false && is_array($cached)) {
                return $cached;
            }
        }
        $query = "SELECT t2.*, COUNT(*) as relation_count 
                  FROM " . DB_PREFIX . "content_tags ct1 
                  INNER JOIN " . DB_PREFIX . "content_tags ct2 ON ct1.content_id = ct2.content_id AND ct1.model_key = ct2.model_key 
                  INNER JOIN " . DB_PREFIX . "tags t2 ON ct2.tag_id = t2.tag_id 
                  WHERE ct1.tag_id = ? AND ct2.tag_id != ? AND t2.tag_status = 'active' 
                  GROUP BY t2.tag_id 
                  ORDER BY relation_count DESC, t2.tag_title ASC 
                  LIMIT ?";
        $related_tags = self::$tododb->get_results($query, array($tag_id, $tag_id, $limit));
        if ($related_tags) {
            foreach ($related_tags as $k => $tag) {
                $related_tags[$k] = self::normalize_tag($tag);
            }
        }
        if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::set('related_tags_' . $tag_id . '_' . (int)$limit, $related_tags ?: array(), $ttl, 'tags', $lang);
        }
        return $related_tags ?: array();
    }
    
    /**
     * 获取标签统计信息
     * 
     * @param int $tag_id 标签ID
     * @return array
     */
    public static function get_tag_stats($tag_id) {
        self::ensure_db_connection();
        
        if (isset(self::$tag_stats_cache[$tag_id])) {
            return self::$tag_stats_cache[$tag_id];
        }
        $ttl = function_exists('todo_get_fragment_cache_ttl') ? (int) todo_get_fragment_cache_ttl() : 120;
        $lang = function_exists('todo_get_current_language') ? todo_get_current_language() : (function_exists('get_current_language') ? get_current_language() : null);
        if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
            $cached = Todo_Cache_Manager::get('tag_stats_' . $tag_id, 'tags', $lang);
            if ($cached !== false && is_array($cached)) {
                self::$tag_stats_cache[$tag_id] = $cached;
                return $cached;
            }
        }
        $stats = array(
            'total_content' => 0,
            'models' => array(),
            'categories' => array()
        );
        
        // 总内容数
        $total_query = "SELECT COUNT(*) as total FROM " . DB_PREFIX . "content_tags WHERE tag_id = ?";
        $total_count = self::$tododb->get_var($total_query, array($tag_id));
        $stats['total_content'] = intval($total_count);
        
        // 按模型统计
        $model_query = "SELECT model_key, COUNT(*) as count 
                        FROM " . DB_PREFIX . "content_tags 
                        WHERE tag_id = ? 
                        GROUP BY model_key";
        $model_results = self::$tododb->get_results($model_query, array($tag_id));
        
        foreach ($model_results as $row) {
            $model = Todo_Model_Manager::get_model($row['model_key']);
            $stats['models'][] = array(
                'model_key' => $row['model_key'],
                'model_name' => $model ? $model['name'] : $row['model_key'],
                'count' => intval($row['count'])
            );
        }
        
        // 按栏目统计（需要关联内容表）
        foreach ($stats['models'] as $model_stat) {
            $model = Todo_Model_Manager::get_model($model_stat['model_key']);
            if ($model) {
                $content_table = DB_PREFIX . $model['table_suffix'];
                $category_query = "SELECT tr.term_taxonomy_id as category_id, t.name as category_name, COUNT(*) as count 
                                   FROM " . DB_PREFIX . "content_tags ct 
                                   INNER JOIN `{$content_table}` c ON ct.content_id = c.id 
                                   INNER JOIN " . DB_PREFIX . "term_relationships tr ON c.id = tr.object_id 
                                   INNER JOIN " . DB_PREFIX . "term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id 
                                   INNER JOIN " . DB_PREFIX . "terms t ON tt.term_id = t.term_id 
                                   WHERE ct.tag_id = ? AND ct.model_key = ? AND tt.taxonomy = 'category' 
                                   GROUP BY tr.term_taxonomy_id";
                
                $category_results = self::$tododb->get_results($category_query, array($tag_id, $model_stat['model_key']));
                
                foreach ($category_results as $row) {
                    $stats['categories'][] = array(
                        'category_id' => intval($row['category_id']),
                        'category_name' => $row['category_name'] ?: '未分类',
                        'model_key' => $model_stat['model_key'],
                        'count' => intval($row['count'])
                    );
                }
            }
        }
        
        self::$tag_stats_cache[$tag_id] = $stats;
        if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::set('tag_stats_' . $tag_id, $stats, $ttl, 'tags', $lang);
        }
        return $stats;
    }
    
    /**
     * 搜索标签
     * 
     * @param string $keyword 关键词
     * @param int $limit 数量限制
     * @return array
     */
    public static function search_tags($keyword, $limit = 20) {
        self::ensure_db_connection();
        
        $ttl = function_exists('todo_get_fragment_cache_ttl') ? (int) todo_get_fragment_cache_ttl() : 120;
        $lang = function_exists('todo_get_current_language') ? todo_get_current_language() : (function_exists('get_current_language') ? get_current_language() : null);
        if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
            $k = 'search_tags_' . md5($keyword) . '_' . (int)$limit;
            $cached = Todo_Cache_Manager::get($k, 'tags', $lang);
            if ($cached !== false && is_array($cached)) {
                return $cached;
            }
        }
        $query = "SELECT * FROM " . DB_PREFIX . "tags 
                  WHERE (tag_title LIKE ? OR tag_desc LIKE ?) AND tag_status = 'active' 
                  ORDER BY 
                    CASE WHEN tag_title = ? THEN 1 
                         WHEN tag_title LIKE ? THEN 2 
                         ELSE 3 END, 
                    tag_title ASC 
                  LIMIT ?";
        
        $like_keyword = '%' . $keyword . '%';
        $start_keyword = $keyword . '%';
        
        $tags = self::$tododb->get_results($query, array($like_keyword, $like_keyword, $keyword, $start_keyword, $limit));
        if ($tags) {
            foreach ($tags as $k => $tag) {
                $tags[$k] = self::normalize_tag($tag);
            }
        }
        if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::set('search_tags_' . md5($keyword) . '_' . (int)$limit, $tags ?: array(), $ttl, 'tags', $lang);
        }
        return $tags ?: array();
    }
    
    /**
     * 批量创建标签
     * 
     * @param array $tag_names 标签名称数组
     * @return array 创建的标签ID数组
     */
    public static function batch_create_tags($tag_names) {
        $tag_ids = array();
        
        foreach ($tag_names as $tag_name) {
            $tag_name = trim($tag_name);
            if (empty($tag_name)) continue;
            
            // 检查是否已存在
            $existing_tag = self::get_tag_by_name($tag_name);
            if ($existing_tag) {
                $tag_ids[] = $existing_tag['id'];
            } else {
                // 创建新标签
                $tag_id = self::create_tag(array('name' => $tag_name));
                if ($tag_id) {
                    $tag_ids[] = $tag_id;
                }
            }
        }
        
        return $tag_ids;
    }
    
    /**
     * 合并标签
     * 
     * @param int $source_tag_id 源标签ID
     * @param int $target_tag_id 目标标签ID
     * @return bool
     */
    public static function merge_tags($source_tag_id, $target_tag_id) {
        self::ensure_db_connection();
        
        if ($source_tag_id === $target_tag_id) {
            return false;
        }
        
        // 更新内容标签关联
        $update_query = "UPDATE " . DB_PREFIX . "content_tags 
                         SET tag_id = ? 
                         WHERE tag_id = ? 
                         AND NOT EXISTS (
                             SELECT 1 FROM " . DB_PREFIX . "content_tags ct2 
                             WHERE ct2.content_id = " . DB_PREFIX . "content_tags.content_id 
                             AND ct2.model_key = " . DB_PREFIX . "content_tags.model_key 
                             AND ct2.tag_id = ?
                         )";
        
        self::$tododb->query($update_query, array($target_tag_id, $source_tag_id, $target_tag_id));
        
        // 删除重复的关联
        $delete_duplicates_query = "DELETE FROM " . DB_PREFIX . "content_tags WHERE tag_id = ?";
        self::$tododb->query($delete_duplicates_query, array($source_tag_id));
        
        // 删除源标签
        return self::delete_tag($source_tag_id, false);
    }
    
    /**
     * 检查标签是否存在
     * 
     * @param string $name 标签名称
     * @param int $exclude_id 排除的标签ID
     * @return bool
     */
    private static function tag_exists($name, $exclude_id = 0) {
        self::ensure_db_connection();
        
        $query = "SELECT tag_id FROM " . DB_PREFIX . "tags WHERE tag_title = ?";
        $params = array($name);
        
        if ($exclude_id > 0) {
            $query .= " AND tag_id != ?";
            $params[] = $exclude_id;
        }
        
        $result = self::$tododb->get_var($query, $params);
        
        return !empty($result);
    }
    
    /**
     * 生成URL别名
     * 
     * @param string $name 标签名称
     * @return string
     */
    private static function generate_slug($name) {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9\-_]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        
        return $slug ?: 'tag-' . time();
    }
    
    /**
     * 确保URL别名唯一
     * 
     * @param string $slug URL别名
     * @param int $exclude_id 排除的标签ID
     * @return string
     */
    private static function ensure_unique_slug($slug, $exclude_id = 0) {
        $original_slug = $slug;
        $counter = 1;
        
        while (self::slug_exists($slug, $exclude_id)) {
            $slug = $original_slug . '-' . $counter;
            $counter++;
        }
        
        return $slug;
    }
    
    /**
     * 检查URL别名是否存在
     * 
     * @param string $slug URL别名
     * @param int $exclude_id 排除的标签ID
     * @return bool
     */
    private static function slug_exists($slug, $exclude_id = 0) {
        self::ensure_db_connection();
        
        $query = "SELECT tag_id FROM " . DB_PREFIX . "tags WHERE tag_slug = ?";
        $params = array($slug);
        
        if ($exclude_id > 0) {
            $query .= " AND tag_id != ?";
            $params[] = $exclude_id;
        }
        
        $result = self::$tododb->get_var($query, $params);
        
        return !empty($result);
    }
    
    /**
     * 清除缓存
     */
    private static function clear_cache() {
        self::$tags_cache = array();
        self::$tag_stats_cache = array();
        if (class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::flush_group('tags');
        }
    }
}
