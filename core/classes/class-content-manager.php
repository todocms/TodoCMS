<?php
/**
 * TodoCMS 内容管理器
 * 
 * 处理不同数据模型的内容创建、编辑和管理，支持动态字段和模型关联
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    exit('Direct access not permitted');
}

class Todo_Content_Manager {
    
    /**
     * 数据库连接
     * @var CMS_DB
     */
    private static $tododb;
    
    /**
     * 内容缓存
     * @var array
     */
    private static $content_cache = array();
    
    /**
     * 初始化内容管理器
     */
    public static function init() {
        global $tododb;
        self::$tododb = $tododb;
    }
    
    /**
     * 创建内容
     * 
     * @param string $model_key 模型标识
     * @param array $content_data 内容数据
     * @return bool|int 成功返回内容ID，失败返回false
     */
    public static function create_content($model_key, $content_data) {
        $model = Todo_Model_Manager::get_model($model_key);
        if (!$model) {
            return false;
        }
        
        $table_name = DB_PREFIX . $model['table_suffix'];
        
        // 验证必填字段
        if (empty($content_data['title'])) {
            return false;
        }
        
        // 分离系统字段和自定义字段
        $system_fields = self::extract_system_fields($content_data);
        $custom_fields = self::extract_custom_fields($content_data, $model_key);
        
        // 设置默认值
        $system_fields = array_merge(array(
            'status' => 1,
            'sort_order' => 0,
            'view_count' => 0,
            'category_id' => 0,
            'user_id' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'published_at' => null
        ), $system_fields);
        
        // 如果状态为发布，设置发布时间
        if ($system_fields['status'] == 1 && empty($system_fields['published_at'])) {
            $system_fields['published_at'] = $system_fields['created_at'];
        }
        
        // 构建插入语句
        $fields = array_keys($system_fields);
        $placeholders = array_fill(0, count($fields), '?');
        $values = array_values($system_fields);
        
        $query = "INSERT INTO `{$table_name}` (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        
        $executed = self::$tododb->query($query, $values);
        
        if ($executed) {
            $content_id = self::$tododb->insert_id();
            
            // 保存自定义字段 - 委托给post-fields-manager
            if (!empty($custom_fields)) {
                $post_fields_manager = Todo_Post_Fields_Manager::get_instance();
                $post_fields_manager->save_custom_fields($content_id, $model_key, $custom_fields, 'content');
            }
            
            // 处理标签
            if (!empty($content_data['tags'])) {
                self::save_content_tags($content_id, $model_key, $content_data['tags']);
            }
            
            return $content_id;
        }
        
        return false;
    }
    
    /**
     * 更新内容
     * 
     * @param string $model_key 模型标识
     * @param int $content_id 内容ID
     * @param array $content_data 内容数据
     * @return bool
     */
    public static function update_content($model_key, $content_id, $content_data) {
        $model = Todo_Model_Manager::get_model($model_key);
        if (!$model) {
            return false;
        }
        
        $table_name = DB_PREFIX . $model['table_suffix'];
        
        // 分离系统字段和自定义字段
        $system_fields = self::extract_system_fields($content_data);
        $custom_fields = self::extract_custom_fields($content_data, $model_key);
        
        // 更新系统字段
        if (!empty($system_fields)) {
            $set_clauses = array();
            $values = array();
            
            foreach ($system_fields as $field => $value) {
                $set_clauses[] = "{$field} = ?";
                $values[] = $value;
            }
            
            // 添加更新时间
            $set_clauses[] = 'updated_at = NOW()';
            
            // 如果状态改为发布且之前未发布，设置发布时间
            if (isset($system_fields['status']) && $system_fields['status'] == 1) {
                $current_content = self::get_content($model_key, $content_id);
                if ($current_content && empty($current_content['published_at'])) {
                    $set_clauses[] = 'published_at = NOW()';
                }
            }
            
            $values[] = $content_id;
            
            $query = "UPDATE `{$table_name}` SET " . implode(', ', $set_clauses) . " WHERE id = ?";
            self::$tododb->query($query, $values);
        }
        
        // 更新自定义字段
        if (!empty($custom_fields)) {
            self::save_custom_fields($content_id, $model_key, $custom_fields);
        }
        
        // 更新标签
        if (isset($content_data['tags'])) {
            self::save_content_tags($content_id, $model_key, $content_data['tags']);
        }
        
        // 清除缓存
        unset(self::$content_cache[$model_key][$content_id]);
        
        return true;
    }
    
    /**
     * 删除内容
     * 
     * @param string $model_key 模型标识
     * @param int $content_id 内容ID
     * @return bool
     */
    public static function delete_content($model_key, $content_id) {
        $model = Todo_Model_Manager::get_model($model_key);
        if (!$model) {
            return false;
        }
        
        $table_name = DB_PREFIX . $model['table_suffix'];
        
        // 删除主内容
        $query = "DELETE FROM `{$table_name}` WHERE id = ?";
        $executed = self::$tododb->query($query, [$content_id]);
        
        if ($executed) {
            // 删除自定义字段值
            self::delete_custom_field_values($content_id, $model_key);
            
            // 删除标签关联
            self::delete_content_tags($content_id, $model_key);
            
            // 清除缓存
            unset(self::$content_cache[$model_key][$content_id]);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * 获取内容
     * 
     * @param string $model_key 模型标识
     * @param int $content_id 内容ID
     * @param bool $include_custom_fields 是否包含自定义字段
     * @return array|null
     */
    public static function get_content($model_key, $content_id, $include_custom_fields = true) {
        if (isset(self::$content_cache[$model_key][$content_id])) {
            return self::$content_cache[$model_key][$content_id];
        }
        
        $model = Todo_Model_Manager::get_model($model_key);
        if (!$model) {
            return null;
        }
        
        $table_name = DB_PREFIX . $model['table_suffix'];
        
        $query = "SELECT * FROM `{$table_name}` WHERE id = ?";
        $row = self::$tododb->get_row($query, [$content_id], 'ARRAY_A');
        
        if ($row) {
            // 获取自定义字段
            if ($include_custom_fields) {
                $custom_fields = self::get_custom_field_values($content_id, $model_key);
                $row = array_merge($row, $custom_fields);
            }
            
            // 获取标签
            $row['tags'] = self::get_content_tags($content_id, $model_key);
            
            // 获取栏目信息
            if ($row['category_id'] > 0) {
                $row['category'] = Todo_Category_Manager::get_category($row['category_id']);
            }
            
            self::$content_cache[$model_key][$content_id] = $row;
            return $row;
        }
        
        return null;
    }
    
    /**
     * 获取内容列表
     * 
     * @param string $model_key 模型标识
     * @param array $args 查询参数
     * @return array
     */
    public static function get_contents($model_key, $args = array()) {
        $model = Todo_Model_Manager::get_model($model_key);
        if (!$model) {
            return array();
        }
        
        $table_name = DB_PREFIX . $model['table_suffix'];
        
        // 默认参数
        $defaults = array(
            'status' => null,
            'category_id' => null,
            'user_id' => null,
            'keyword' => null,
            'tag' => null,
            'date_from' => null,
            'date_to' => null,
            'order_by' => 'created_at',
            'order' => 'DESC',
            'limit' => 20,
            'offset' => 0,
            'include_custom_fields' => false
        );
        
        $args = array_merge($defaults, $args);
        
        $where_clauses = array();
        $params = array();
        $types = '';
        
        // 构建WHERE条件
        if ($args['status'] !== null) {
            $where_clauses[] = 'status = ?';
            $params[] = intval($args['status']);
            $types .= 'i';
        }
        
        if ($args['category_id'] !== null) {
            $where_clauses[] = 'category_id = ?';
            $params[] = intval($args['category_id']);
            $types .= 'i';
        }
        
        if ($args['user_id'] !== null) {
            $where_clauses[] = 'user_id = ?';
            $params[] = intval($args['user_id']);
            $types .= 'i';
        }
        
        if (!empty($args['keyword'])) {
            $where_clauses[] = '(title LIKE ? OR content LIKE ?)';
            $keyword = '%' . $args['keyword'] . '%';
            $params[] = $keyword;
            $params[] = $keyword;
            $types .= 'ss';
        }
        
        if (!empty($args['date_from'])) {
            $where_clauses[] = 'created_at >= ?';
            $params[] = $args['date_from'];
            $types .= 's';
        }
        
        if (!empty($args['date_to'])) {
            $where_clauses[] = 'created_at <= ?';
            $params[] = $args['date_to'] . ' 23:59:59';
            $types .= 's';
        }
        
        // 标签查询
        $join_sql = '';
        if (!empty($args['tag'])) {
            $join_sql = "INNER JOIN " . DB_PREFIX . "content_tags ct ON {$table_name}.id = ct.content_id 
                         INNER JOIN " . DB_PREFIX . "tags t ON ct.tag_id = t.id";
            $where_clauses[] = 't.name = ? AND ct.model_key = ?';
            $params[] = $args['tag'];
            $params[] = $model_key;
            $types .= 'ss';
        }
        
        $where_sql = empty($where_clauses) ? '' : 'WHERE ' . implode(' AND ', $where_clauses);
        
        // 构建ORDER BY
        $allowed_order_fields = array('id', 'title', 'created_at', 'updated_at', 'published_at', 'view_count', 'sort_order');
        $order_by = in_array($args['order_by'], $allowed_order_fields) ? $args['order_by'] : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        $order_sql = "ORDER BY {$order_by} {$order}";
        
        // 构建LIMIT
        $limit_sql = "LIMIT " . intval($args['offset']) . ", " . intval($args['limit']);
        
        $query = "SELECT {$table_name}.* FROM `{$table_name}` {$join_sql} {$where_sql} {$order_sql} {$limit_sql}";
        
        // 执行查询并返回数组
        $rows = !empty($params)
            ? self::$tododb->get_results($query, $params, 'ARRAY_A')
            : self::$tododb->get_results($query, [], 'ARRAY_A');
        
        $contents = array();
        if (!empty($rows)) {
            foreach ($rows as $row) {
                // 获取自定义字段
                if ($args['include_custom_fields']) {
                    $custom_fields = self::get_custom_field_values($row['id'], $model_key);
                    $row = array_merge($row, $custom_fields);
                }
                
                // 获取标签
                $row['tags'] = self::get_content_tags($row['id'], $model_key);
                
                // 获取栏目信息
                if ($row['category_id'] > 0) {
                    $row['category'] = Todo_Category_Manager::get_category($row['category_id']);
                }
                
                $contents[] = $row;
            }
        }
        
        return $contents;
    }
    
    /**
     * 获取内容总数
     * 
     * @param string $model_key 模型标识
     * @param array $args 查询参数
     * @return int
     */
    public static function get_contents_count($model_key, $args = array()) {
        $model = Todo_Model_Manager::get_model($model_key);
        if (!$model) {
            return 0;
        }
        
        $table_name = DB_PREFIX . $model['table_suffix'];
        
        $where_clauses = array();
        $params = array();
        $types = '';
        
        // 构建WHERE条件（复用get_contents的逻辑）
        if (isset($args['status']) && $args['status'] !== null) {
            $where_clauses[] = 'status = ?';
            $params[] = intval($args['status']);
            $types .= 'i';
        }
        
        if (isset($args['category_id']) && $args['category_id'] !== null) {
            $where_clauses[] = 'category_id = ?';
            $params[] = intval($args['category_id']);
            $types .= 'i';
        }
        
        if (isset($args['user_id']) && $args['user_id'] !== null) {
            $where_clauses[] = 'user_id = ?';
            $params[] = intval($args['user_id']);
            $types .= 'i';
        }
        
        if (!empty($args['keyword'])) {
            $where_clauses[] = '(title LIKE ? OR content LIKE ?)';
            $keyword = '%' . $args['keyword'] . '%';
            $params[] = $keyword;
            $params[] = $keyword;
            $types .= 'ss';
        }
        
        $join_sql = '';
        if (!empty($args['tag'])) {
            $join_sql = "INNER JOIN " . DB_PREFIX . "content_tags ct ON {$table_name}.id = ct.content_id 
                         INNER JOIN " . DB_PREFIX . "tags t ON ct.tag_id = t.id";
            $where_clauses[] = 't.name = ? AND ct.model_key = ?';
            $params[] = $args['tag'];
            $params[] = $model_key;
            $types .= 'ss';
        }
        
        $where_sql = empty($where_clauses) ? '' : 'WHERE ' . implode(' AND ', $where_clauses);
        
        $query = "SELECT COUNT(DISTINCT {$table_name}.id) as total FROM `{$table_name}` {$join_sql} {$where_sql}";
        
        $count = !empty($params)
            ? self::$tododb->get_var($query, $params)
            : self::$tododb->get_var($query, []);
        
        return $count !== null ? intval($count) : 0;
    }
    
    /**
     * 增加浏览次数
     * 
     * @param string $model_key 模型标识
     * @param int $content_id 内容ID
     * @return bool
     */
    public static function increment_view_count($model_key, $content_id) {
        $model = Todo_Model_Manager::get_model($model_key);
        if (!$model) {
            return false;
        }
        
        $table_name = DB_PREFIX . $model['table_suffix'];
        
        $query = "UPDATE `{$table_name}` SET view_count = view_count + 1 WHERE id = ?";
        return self::$tododb->query($query, [$content_id]);
    }
    
    /**
     * 提取系统字段
     * 
     * @param array $data 数据数组
     * @return array
     */
    private static function extract_system_fields($data) {
        $system_fields = array(
            'title', 'content', 'excerpt', 'thumbnail', 'status', 'sort_order',
            'view_count', 'category_id', 'user_id', 'created_at', 'updated_at', 'published_at'
        );
        
        $result = array();
        foreach ($system_fields as $field) {
            if (isset($data[$field])) {
                $result[$field] = $data[$field];
            }
        }
        
        return $result;
    }
    
    /**
     * 提取自定义字段
     * 
     * @param array $data 数据数组
     * @param string $model_key 模型标识
     * @return array
     */
    private static function extract_custom_fields($data, $model_key) {
        $system_fields = array(
            'title', 'content', 'excerpt', 'thumbnail', 'status', 'sort_order',
            'view_count', 'category_id', 'user_id', 'created_at', 'updated_at', 'published_at', 'tags'
        );
        
        $custom_fields = array();
        foreach ($data as $key => $value) {
            if (!in_array($key, $system_fields)) {
                $custom_fields[$key] = $value;
            }
        }
        
        return $custom_fields;
    }
    
    /**
     * 获取自定义字段值
     * 
     * @param int $content_id 内容ID
     * @param string $model_key 模型标识
     * @return array
     */
    private static function get_custom_field_values($content_id, $model_key) {
        $query = "SELECT field_name, field_value FROM " . DB_PREFIX . "custom_field_values 
                  WHERE content_id = ? AND model_key = ?";
        
        $rows = self::$tododb->get_results($query, [$content_id, $model_key], 'ARRAY_A');
        
        $custom_fields = array();
        foreach ($rows as $row) {
            $value = $row['field_value'];
            // 尝试解析JSON
            $json_value = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $json_value;
            }
            $custom_fields[$row['field_name']] = $value;
        }
        
        return $custom_fields;
    }
    
    /**
     * 删除自定义字段值
     * 
     * @param int $content_id 内容ID
     * @param string $model_key 模型标识
     */
    private static function delete_custom_field_values($content_id, $model_key) {
        $query = "DELETE FROM " . DB_PREFIX . "custom_field_values WHERE content_id = ? AND model_key = ?";
        self::$tododb->query($query, [$content_id, $model_key]);
    }
    
    /**
     * 保存内容标签
     * 
     * @param int $content_id 内容ID
     * @param string $model_key 模型标识
     * @param array|string $tags 标签
     */
    private static function save_content_tags($content_id, $model_key, $tags) {
        // 先删除现有标签关联
        self::delete_content_tags($content_id, $model_key);
        
        if (empty($tags)) {
            return;
        }
        
        // 处理标签格式
        if (is_string($tags)) {
            $tags = array_filter(array_map('trim', explode(',', $tags)));
        }
        
        foreach ($tags as $tag_name) {
            if (empty($tag_name)) continue;
            
            // 获取或创建标签
            $tag_id = self::get_or_create_tag($tag_name);
            if ($tag_id) {
                $query = "INSERT INTO " . DB_PREFIX . "content_tags (content_id, tag_id, model_key, created_at) VALUES (?, ?, ?, NOW())";
                self::$tododb->query($query, [$content_id, $tag_id, $model_key]);
            }
        }
    }
    
    /**
     * 获取内容标签
     * 
     * @param int $content_id 内容ID
     * @param string $model_key 模型标识
     * @return array
     */
    private static function get_content_tags($content_id, $model_key) {
        $query = "SELECT t.* FROM " . DB_PREFIX . "tags t 
                  INNER JOIN " . DB_PREFIX . "content_tags ct ON t.id = ct.tag_id 
                  WHERE ct.content_id = ? AND ct.model_key = ? 
                  ORDER BY t.name ASC";
        
        $tags = self::$tododb->get_results($query, [$content_id, $model_key], 'ARRAY_A');
        return $tags ?: array();
    }
    
    /**
     * 删除内容标签关联
     * 
     * @param int $content_id 内容ID
     * @param string $model_key 模型标识
     */
    private static function delete_content_tags($content_id, $model_key) {
        $query = "DELETE FROM " . DB_PREFIX . "content_tags WHERE content_id = ? AND model_key = ?";
        self::$tododb->query($query, [$content_id, $model_key]);
    }
    
    /**
     * 获取或创建标签
     * 
     * @param string $tag_name 标签名称
     * @return int|null 标签ID
     */
    private static function get_or_create_tag($tag_name) {
        // 先查找是否存在
        $query = "SELECT id FROM " . DB_PREFIX . "tags WHERE name = ?";
        $tag_id = self::$tododb->get_var($query, [$tag_name]);
        if ($tag_id !== null) {
            return intval($tag_id);
        }
        
        // 创建新标签（统一使用 tododb_insert，避免旧用法错误；created_at 使用当前时间）
        $slug = self::generate_tag_slug($tag_name);
        $insert_id = tododb_insert(DB_PREFIX . 'tags', [
            'name'       => $tag_name,
            'slug'       => $slug,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        if ($insert_id !== false) {
            return intval($insert_id);
        }
        
        return null;
    }
    
    /**
     * 生成标签slug
     * 
     * @param string $tag_name 标签名称
     * @return string
     */
    private static function generate_tag_slug($tag_name) {
        $slug = strtolower(trim($tag_name));
        $slug = preg_replace('/[^a-z0-9\-_]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        
        return $slug ?: 'tag-' . time();
    }
}

// 便捷函数
if (!function_exists('todo_create_content')) {
    /**
     * 创建内容
     * 
     * @param string $model_key 模型标识
     * @param array $content_data 内容数据
     * @return bool|int
     */
    function todo_create_content($model_key, $content_data) {
        return Todo_Content_Manager::create_content($model_key, $content_data);
    }
}

if (!function_exists('todo_get_content')) {
    /**
     * 获取内容
     * 
     * @param string $model_key 模型标识
     * @param int $content_id 内容ID
     * @param bool $include_custom_fields 是否包含自定义字段
     * @return array|null
     */
    function todo_get_content($model_key, $content_id, $include_custom_fields = true) {
        return Todo_Content_Manager::get_content($model_key, $content_id, $include_custom_fields);
    }
}

if (!function_exists('todo_get_contents')) {
    /**
     * 获取内容列表
     * 
     * @param string $model_key 模型标识
     * @param array $args 查询参数
     * @return array
     */
    function todo_get_contents($model_key, $args = array()) {
        return Todo_Content_Manager::get_contents($model_key, $args);
    }
}

if (!function_exists('todo_update_content')) {
    /**
     * 更新内容
     * 
     * @param string $model_key 模型标识
     * @param int $content_id 内容ID
     * @param array $content_data 内容数据
     * @return bool
     */
    function todo_update_content($model_key, $content_id, $content_data) {
        return Todo_Content_Manager::update_content($model_key, $content_id, $content_data);
    }
}

if (!function_exists('todo_delete_content')) {
    /**
     * 删除内容
     * 
     * @param string $model_key 模型标识
     * @param int $content_id 内容ID
     * @return bool
     */
    function todo_delete_content($model_key, $content_id) {
        return Todo_Content_Manager::delete_content($model_key, $content_id);
    }
}

?>