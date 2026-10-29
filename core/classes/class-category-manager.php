<?php
/**
 * TodoCMS 栏目管理器
 * 
 * 管理栏目与数据模型的关联关系，处理栏目的创建、配置和内容管理
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    exit('Direct access not permitted');
}

class Todo_Category_Manager {
    
    /**
     * 数据库连接
     * @var CMS_DB
     */
    private static $tododb;
    
    /**
     * 栏目缓存
     * @var array
     */
    private static $categories_cache = array();
    
    /**
     * 栏目树缓存
     * @var array
     */
    private static $category_tree_cache = null;
    
    /**
     * 初始化栏目管理器
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
                error_log('Todo_Category_Manager: 无法初始化数据库连接 - ' . $e->getMessage());
                throw new Exception('数据库连接不可用');
            }
        } else {
            throw new Exception('CMS_DB 类不存在，无法初始化数据库连接');
        }
    }
    
    /**
     * 创建栏目
     * 
     * @param array $category_data 栏目数据
     * @return bool|int 成功返回栏目ID，失败返回false
     */
    public static function create_category($category_data) {
        self::ensure_db_connection();
        
        $required_fields = array('name', 'model_key');
        
        foreach ($required_fields as $field) {
            if (empty($category_data[$field])) {
                return false;
            }
        }
        
        // 验证模型是否存在
        if (!Todo_Model_Manager::model_exists($category_data['model_key'])) {
            return false;
        }
        
        // 生成URL别名
        if (empty($category_data['slug'])) {
            $category_data['slug'] = self::generate_slug($category_data['name']);
        }
        
        // 确保URL别名唯一
        $category_data['slug'] = self::ensure_unique_slug($category_data['slug']);
        
        $parent_id = isset($category_data['parent_id']) ? intval($category_data['parent_id']) : 0;
        $sort_order = isset($category_data['sort_order']) ? intval($category_data['sort_order']) : 0;
        $status = isset($category_data['status']) ? intval($category_data['status']) : 1;
        $show_in_nav = isset($category_data['show_in_nav']) ? intval($category_data['show_in_nav']) : 1;
        
        // 计算层级和路径
        $level = 0;
        $path = '';
        if ($parent_id > 0) {
            $parent = self::get_category($parent_id);
            if ($parent) {
                $level = $parent['level'] + 1;
                $path = $parent['path'] . $parent_id . ',';
            }
        }
        
        $data = array(
            'name' => $category_data['name'],
            'slug' => $category_data['slug'],
            'description' => $category_data['description'] ?? '',
            'model_key' => $category_data['model_key'],
            'parent_id' => $parent_id,
            'level' => $level,
            'path' => $path,
            'sort_order' => $sort_order,
            'status' => $status,
            'show_in_nav' => $show_in_nav,
            'seo_title' => $category_data['seo_title'] ?? '',
            'seo_keywords' => $category_data['seo_keywords'] ?? '',
            'seo_description' => $category_data['seo_description'] ?? '',
            'template_list' => $category_data['template_list'] ?? '',
            'template_view' => $category_data['template_view'] ?? '',
            'created_at' => date('Y-m-d H:i:s')
        );
        
        // 先插入到 terms 表
        $term_data = array(
            'name' => $data['name'],
            'slug' => $data['slug'],
            'term_group' => 0
        );
        $term_id = self::$tododb->insert(DB_PREFIX . 'terms', $term_data);
        
        if (!$term_id) {
            return false;
        }
        
        // 再插入到 term_taxonomy 表
        $taxonomy_data = array(
            'term_id' => $term_id,
            'taxonomy' => 'category',
            'description' => $data['description'],
            'parent' => $data['parent_id'],
            'count' => 0,
            'term_order' => $data['sort_order']
        );
        $taxonomy_id = self::$tododb->insert(DB_PREFIX . 'term_taxonomy', $taxonomy_data);
        
        if (!$taxonomy_id) {
            // 回滚 terms 表插入
            self::$tododb->delete(DB_PREFIX . 'terms', array('term_id' => $term_id));
            return false;
        }
        
        // 插入到 term_models 表关联模型
        if (!empty($data['model_key'])) {
            $model_data = array(
                'term_id' => $term_id,
                'model_key' => $data['model_key']
            );
            $model_result = self::$tododb->insert(DB_PREFIX . 'term_models', $model_data);
            if (!$model_result) {
                // 回滚之前的插入
                self::$tododb->delete(DB_PREFIX . 'term_taxonomy', array('term_taxonomy_id' => $taxonomy_id));
                self::$tododb->delete(DB_PREFIX . 'terms', array('term_id' => $term_id));
                return false;
            }
        }
        
        $category_id = $term_id;
        
        if ($category_id) {
            // 清除缓存
            self::clear_cache();
            
            return $category_id;
        }
        
        return false;
    }
    
    /**
     * 更新栏目
     * 
     * @param int $category_id 栏目ID
     * @param array $category_data 栏目数据
     * @return bool
     */
    public static function update_category($category_id, $category_data) {
        self::ensure_db_connection();
        
        $set_clauses = array();
        $params = array();
        $types = '';
        
        // 可更新的字段
        $updatable_fields = array(
            'name' => 's',
            'slug' => 's',
            'description' => 's',
            'model_key' => 's',
            'parent_id' => 'i',
            'sort_order' => 'i',
            'status' => 'i',
            'show_in_nav' => 'i',
            'seo_title' => 's',
            'seo_keywords' => 's',
            'seo_description' => 's',
            'template_list' => 's',
            'template_view' => 's'
        );
        
        foreach ($updatable_fields as $field => $type) {
            if (isset($category_data[$field])) {
                $set_clauses[] = "{$field} = ?";
                $params[] = $category_data[$field];
                $types .= $type;
            }
        }
        
        if (empty($set_clauses)) {
            return false;
        }
        
        // 如果更新了slug，确保唯一性
        if (isset($category_data['slug'])) {
            $category_data['slug'] = self::ensure_unique_slug($category_data['slug'], $category_id);
        }
        
        // 构建更新数据数组
        $update_data = array();
        foreach ($updatable_fields as $field => $type) {
            if (isset($category_data[$field])) {
                $update_data[$field] = $category_data[$field];
            }
        }
        
        // 如果更新了parent_id，重新计算层级和路径
        if (isset($category_data['parent_id'])) {
            $parent_id = intval($category_data['parent_id']);
            $level = 0;
            $path = '';
            
            if ($parent_id > 0) {
                $parent = self::get_category($parent_id);
                if ($parent) {
                    $level = $parent['level'] + 1;
                    $path = $parent['path'] . $parent_id . ',';
                }
            }
            
            $update_data['level'] = $level;
            $update_data['path'] = $path;
        }
        
        $update_data['updated_at'] = date('Y-m-d H:i:s');
        
        // 更新 terms 表
        $term_update = array();
        if (isset($update_data['name'])) $term_update['name'] = $update_data['name'];
        if (isset($update_data['slug'])) $term_update['slug'] = $update_data['slug'];
        
        if (!empty($term_update)) {
            $result = self::$tododb->update(DB_PREFIX . 'terms', $term_update, array('term_id' => $category_id));
        }
        
        // 更新 term_taxonomy 表
        $taxonomy_update = array();
        if (isset($update_data['description'])) $taxonomy_update['description'] = $update_data['description'];
        if (isset($update_data['parent_id'])) $taxonomy_update['parent'] = $update_data['parent_id'];
        if (isset($update_data['sort_order'])) $taxonomy_update['term_order'] = $update_data['sort_order'];
        
        if (!empty($taxonomy_update)) {
            $result = self::$tododb->update(DB_PREFIX . 'term_taxonomy', $taxonomy_update, array('term_id' => $category_id, 'taxonomy' => 'category'));
        }
        
        // 更新模型关联
        if (isset($update_data['model_key'])) {
            // 先删除旧关联
            $result = self::$tododb->delete(DB_PREFIX . 'term_models', array('term_id' => $category_id));
            // 插入新关联
            if (!empty($update_data['model_key'])) {
                $model_data = array(
                    'term_id' => $category_id,
                    'model_key' => $update_data['model_key']
                );
                $result = self::$tododb->insert(DB_PREFIX . 'term_models', $model_data);
            }
        }
        
        $result = true;
        
        if ($result !== false) {
            // 如果更新了parent_id，需要更新所有子栏目的层级和路径
            if (isset($category_data['parent_id'])) {
                self::update_children_hierarchy($category_id);
            }
            
            // 清除缓存
            self::clear_cache();
            return true;
        }
        
        return false;
    }
    
    /**
     * 删除栏目
     * 
     * @param int $category_id 栏目ID
     * @param bool $delete_content 是否删除栏目下的内容
     * @return bool
     */
    public static function delete_category($category_id, $delete_content = false) {
        self::ensure_db_connection();
        
        // 检查是否有子栏目
        $children = self::get_children($category_id);
        if (!empty($children)) {
            return false; // 有子栏目时不允许删除
        }
        
        // 获取栏目信息
        $category = self::get_category($category_id);
        if (!$category) {
            return false;
        }
        
        // 如果需要删除内容
        if ($delete_content) {
            $model = Todo_Model_Manager::get_model($category['model_key']);
            if ($model) {
                $table_name = DB_PREFIX . $model['table_suffix'];
                self::$tododb->delete($table_name, array('category_id' => $category_id));
            }
        } else {
            // 将内容移动到未分类
            $model = Todo_Model_Manager::get_model($category['model_key']);
            if ($model) {
                $table_name = DB_PREFIX . $model['table_suffix'];
                self::$tododb->update($table_name, array('category_id' => 0), array('category_id' => $category_id));
            }
        }
        
        // 删除栏目 - 先删除关联表，再删除主表
        $result = self::$tododb->delete(DB_PREFIX . 'term_models', array('term_id' => $category_id));
        $result = self::$tododb->delete(DB_PREFIX . 'term_taxonomy', array('term_id' => $category_id, 'taxonomy' => 'category'));
        $result = self::$tododb->delete(DB_PREFIX . 'terms', array('term_id' => $category_id));
        
        if ($result !== false) {
            // 清除缓存
            self::clear_cache();
            return true;
        }
        
        return false;
    }
    
    /**
     * 获取栏目信息
     * 
     * @param int $category_id 栏目ID
     * @return array|null
     */
    public static function get_category($category_id) {
        self::ensure_db_connection();
        
        if (isset(self::$categories_cache[$category_id])) {
            return self::$categories_cache[$category_id];
        }
        $ttl = function_exists('todo_get_fragment_cache_ttl') ? (int) todo_get_fragment_cache_ttl() : 120;
        $lang = function_exists('todo_get_current_language') ? todo_get_current_language() : (function_exists('get_current_language') ? get_current_language() : null);
        if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
            $cached = Todo_Cache_Manager::get('category_' . $category_id, 'categories', $lang);
            if ($cached !== false && is_array($cached)) {
                self::$categories_cache[$category_id] = $cached;
                return $cached;
            }
        }
        $query = "SELECT t.*, tt.* FROM " . DB_PREFIX . "terms t 
                  LEFT JOIN " . DB_PREFIX . "term_taxonomy tt ON t.term_id = tt.term_id 
                  WHERE t.term_id = ? AND tt.taxonomy = 'category'";
        
        $row = self::$tododb->get_row($query, [$category_id]);
        
        if ($row) {
            // 转换为数组格式
            $row = (array) $row;
            // 统一字段名
            $row['id'] = $row['term_id'];
            $row['name'] = $row['name'];
            $row['slug'] = $row['slug'];
            $row['parent_id'] = $row['parent'];
            self::$categories_cache[$category_id] = $row;
            if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set('category_' . $category_id, $row, $ttl, 'categories', $lang);
            }
            return $row;
        }
        
        return null;
    }
    
    /**
     * 根据slug获取栏目
     * 
     * @param string $slug URL别名
     * @return array|null
     */
    public static function get_category_by_slug($slug) {
        self::ensure_db_connection();
        
        $ttl = function_exists('todo_get_fragment_cache_ttl') ? (int) todo_get_fragment_cache_ttl() : 120;
        $lang = function_exists('todo_get_current_language') ? todo_get_current_language() : (function_exists('get_current_language') ? get_current_language() : null);
        if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
            $cached = Todo_Cache_Manager::get('category_by_slug_' . md5($slug), 'categories', $lang);
            if ($cached !== false && is_array($cached)) {
                return $cached;
            }
        }
        $query = "SELECT t.*, tt.* FROM " . DB_PREFIX . "terms t 
                  LEFT JOIN " . DB_PREFIX . "term_taxonomy tt ON t.term_id = tt.term_id 
                  WHERE t.slug = ? AND tt.taxonomy = 'category'";
        
        $row = self::$tododb->get_row($query, [$slug]);
        
        if ($row) {
            // 转换为数组格式
            $row = (array) $row;
            // 统一字段名
            $row['id'] = $row['term_id'];
            $row['name'] = $row['name'];
            $row['slug'] = $row['slug'];
            $row['parent_id'] = $row['parent'];
            self::$categories_cache[$row['id']] = $row;
            if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set('category_by_slug_' . md5($slug), $row, $ttl, 'categories', $lang);
            }
            return $row;
        }
        
        return null;
    }
    
    /**
     * 获取所有栏目
     * 
     * @param array $args 查询参数
     * @return array
     */
    public static function get_categories($args = array()) {
        self::ensure_db_connection();
        
        $ttl = function_exists('todo_get_fragment_cache_ttl') ? (int) todo_get_fragment_cache_ttl() : 120;
        $lang = function_exists('todo_get_current_language') ? todo_get_current_language() : (function_exists('get_current_language') ? get_current_language() : null);
        $is_admin = (defined('IS_ADMIN') && IS_ADMIN);
        if (isset($args['site_id']) && $args['site_id'] !== null) {
            $current_site_id = (int) $args['site_id'];
        } elseif ($is_admin) {
            $current_site_id = (isset($_GET['site']) && is_numeric($_GET['site']) && (int) $_GET['site'] > 0) ? (int) $_GET['site'] : 0;
        } else {
            $current_site_id = function_exists('todo_current_site_id') ? (int) todo_current_site_id(true) : 0;
        }
        $apply_site_filter = ($current_site_id > 0);

        if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
            $k = 'categories_list_' . md5(json_encode($args) . '_site_' . $current_site_id);
            $cached = Todo_Cache_Manager::get($k, 'categories', $lang);
            if ($cached !== false && is_array($cached)) {
                return $cached;
            }
        }
        $where_clauses = array();
        $params = array();
        $types = '';
        
        // 默认参数
        $defaults = array(
            'status' => null,
            'model_key' => null,
            'parent_id' => null,
            'level' => null,
            'show_in_nav' => null,
            'order_by' => 'sort_order',
            'order' => 'ASC',
            'limit' => null,
            'offset' => 0,
            'site_id' => null
        );
        
        $args = array_merge($defaults, $args);
        
        // 构建WHERE条件
        if ($args['status'] !== null) {
            $where_clauses[] = 'status = ?';
            $params[] = intval($args['status']);
            $types .= 'i';
        }
        
        if ($args['model_key'] !== null) {
            $where_clauses[] = 'model_key = ?';
            $params[] = $args['model_key'];
            $types .= 's';
        }
        
        if ($args['parent_id'] !== null) {
            $where_clauses[] = 'parent_id = ?';
            $params[] = intval($args['parent_id']);
            $types .= 'i';
        }
        
        if ($args['level'] !== null) {
            $where_clauses[] = 'level = ?';
            $params[] = intval($args['level']);
            $types .= 'i';
        }
        
        if ($args['show_in_nav'] !== null) {
            $where_clauses[] = 'show_in_nav = ?';
            $params[] = intval($args['show_in_nav']);
            $types .= 'i';
        }
        
        $where_sql = empty($where_clauses) ? '' : 'AND ' . implode(' AND ', $where_clauses);
        
        // 构建ORDER BY
        $order_by = in_array($args['order_by'], array('id', 'name', 'sort_order', 'created_at', 'level')) ? $args['order_by'] : 'sort_order';
        $order = strtoupper($args['order']) === 'DESC' ? 'DESC' : 'ASC';
        
        $site_join = '';
        $order_sql = "ORDER BY {$order_by} {$order}";

        if ($apply_site_filter && function_exists('todo_site_terms_table_exists') && todo_site_terms_table_exists()) {
            $site_table = DB_PREFIX . 'site_terms';
            $site_join = " LEFT JOIN $site_table st ON (t.term_id = st.term_id AND st.site_id = $current_site_id)";
            $where_sql .= " AND ((st.term_id IS NOT NULL AND st.is_visible = 1) OR NOT EXISTS (SELECT 1 FROM $site_table stx WHERE stx.term_id = t.term_id AND stx.site_id <> 0))";
            if ($order_by === 'sort_order') {
                $order_sql = "ORDER BY COALESCE(st.sort_order, tt.term_order) {$order}, t.term_id {$order}";
            }
        }
        
        // 构建LIMIT
        $limit_sql = '';
        if ($args['limit'] !== null) {
            $limit_sql = "LIMIT " . intval($args['offset']) . ", " . intval($args['limit']);
        }
        
        $query = "SELECT t.term_id as id, t.name, t.slug, tt.description, tt.parent as parent_id, 
                         tt.term_order as sort_order, m.model_key
                  FROM " . DB_PREFIX . "terms t 
                  $site_join
                  LEFT JOIN " . DB_PREFIX . "term_taxonomy tt ON t.term_id = tt.term_id 
                  LEFT JOIN " . DB_PREFIX . "term_models tm ON t.term_id = tm.term_id
                  LEFT JOIN " . DB_PREFIX . "models m ON tm.model_id = m.model_id
                  WHERE tt.taxonomy = 'category' {$where_sql} {$order_sql} {$limit_sql}";
        
        // 使用 PDO 风格查询
        $categories = self::$tododb->get_results($query, $params);
        
        // 转换为数组格式并缓存
        $result_categories = array();
        foreach ($categories as $category) {
            $cat_array = (array) $category;
            $result_categories[] = $cat_array;
            self::$categories_cache[$cat_array['id']] = $cat_array;
        }
        if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::set('categories_list_' . md5(json_encode($args) . '_site_' . $current_site_id), $result_categories, $ttl, 'categories', $lang);
        }
        return $result_categories;
    }
    
    /**
     * 获取栏目树
     * 
     * @param int $parent_id 父栏目ID
     * @param array $args 查询参数
     * @return array
     */
    public static function get_category_tree($parent_id = 0, $args = array()) {
        if (self::$category_tree_cache !== null && $parent_id === 0 && empty($args)) {
            return self::$category_tree_cache;
        }
        $ttl = function_exists('todo_get_fragment_cache_ttl') ? (int) todo_get_fragment_cache_ttl() : 120;
        $lang = function_exists('todo_get_current_language') ? todo_get_current_language() : (function_exists('get_current_language') ? get_current_language() : null);
        if ($ttl !== 0 && class_exists('Todo_Cache_Manager') && $parent_id === 0 && empty($args)) {
            $cached = Todo_Cache_Manager::get('category_tree_root', 'categories', $lang);
            if ($cached !== false && is_array($cached)) {
                self::$category_tree_cache = $cached;
                return $cached;
            }
        }
        $args['parent_id'] = $parent_id;
        $categories = self::get_categories($args);
        
        $tree = array();
        foreach ($categories as $category) {
            $category['children'] = self::get_category_tree($category['id'], $args);
            $tree[] = $category;
        }
        if ($parent_id === 0 && empty($args)) {
            self::$category_tree_cache = $tree;
            if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set('category_tree_root', $tree, $ttl, 'categories', $lang);
            }
        }
        
        return $tree;
    }
    
    /**
     * 获取子栏目
     * 
     * @param int $parent_id 父栏目ID
     * @param bool $recursive 是否递归获取所有子栏目
     * @return array
     */
    public static function get_children($parent_id, $recursive = false, $site_id = null) {
        self::ensure_db_connection();
        
        // 计算站点过滤 ID
        $is_admin = (defined('IS_ADMIN') && IS_ADMIN);
        if ($site_id !== null) {
            $current_site_id = (int) $site_id;
        } elseif ($is_admin) {
            $current_site_id = (isset($_GET['site']) && is_numeric($_GET['site']) && (int) $_GET['site'] > 0) ? (int) $_GET['site'] : 0;
        } else {
            $current_site_id = function_exists('todo_current_site_id') ? (int) todo_current_site_id(true) : 0;
        }
        $apply_site_filter = ($current_site_id > 0);

        $site_join = '';
        $site_where = '';
        if ($apply_site_filter && function_exists('todo_site_terms_table_exists') && todo_site_terms_table_exists()) {
            $site_table = DB_PREFIX . 'site_terms';
            $site_join = " LEFT JOIN {$site_table} st ON (t.term_id = st.term_id AND st.site_id = {$current_site_id})";
            $site_where = " AND ((st.term_id IS NOT NULL AND st.is_visible = 1) OR NOT EXISTS (SELECT 1 FROM {$site_table} stx WHERE stx.term_id = t.term_id AND stx.site_id <> 0))";
        }

        $query = "SELECT t.*, tt.* FROM " . DB_PREFIX . "terms t 
                  {$site_join}
                  LEFT JOIN " . DB_PREFIX . "term_taxonomy tt ON t.term_id = tt.term_id 
                  WHERE tt.taxonomy = 'category' AND tt.parent = ? {$site_where} ORDER BY tt.term_order ASC";
        
        $results = self::$tododb->get_results($query, [$parent_id]);
        
        $children = array();
        foreach ($results as $row) {
            // 转换为数组格式
            $row = (array) $row;
            // 统一字段名
            $row['id'] = $row['term_id'];
            $row['name'] = $row['name'];
            $row['slug'] = $row['slug'];
            $row['parent_id'] = $row['parent'];
            $children[] = $row;
            self::$categories_cache[$row['id']] = $row;
            
            // 如果需要递归获取子栏目
            if ($recursive) {
                $sub_children = self::get_children($row['id'], true);
                $children = array_merge($children, $sub_children);
            }
        }
        
        return $children;
    }
    
    /**
     * 获取栏目路径（面包屑）
     * 
     * @param int $category_id 栏目ID
     * @return array
     */
    public static function get_breadcrumb($category_id) {
        $category = self::get_category($category_id);
        if (!$category) {
            return array();
        }
        
        $breadcrumb = array($category);
        
        if (!empty($category['path'])) {
            $parent_ids = array_filter(explode(',', trim($category['path'], ',')));
            foreach ($parent_ids as $parent_id) {
                $parent = self::get_category($parent_id);
                if ($parent) {
                    array_unshift($breadcrumb, $parent);
                }
            }
        }
        
        return $breadcrumb;
    }
    
    /**
     * 生成URL别名
     * 
     * @param string $name 栏目名称
     * @return string
     */
    private static function generate_slug($name) {
        // 简单的拼音转换（实际项目中可能需要更复杂的转换）
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9\-_]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        
        return $slug ?: 'category-' . time();
    }
    
    /**
     * 确保URL别名唯一
     * 
     * @param string $slug URL别名
     * @param int $exclude_id 排除的栏目ID
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
     * @param int $exclude_id 排除的栏目ID
     * @return bool
     */
    private static function slug_exists($slug, $exclude_id = 0) {
        self::ensure_db_connection();
        
        $query = "SELECT t.term_id as id FROM " . DB_PREFIX . "terms t 
                  LEFT JOIN " . DB_PREFIX . "term_taxonomy tt ON t.term_id = tt.term_id 
                  WHERE t.slug = ? AND tt.taxonomy = 'category'";
        $params = array($slug);
        
        if ($exclude_id > 0) {
            $query .= " AND t.term_id != ?";
            $params[] = $exclude_id;
        }
        
        $result = self::$tododb->get_var($query, $params);
        
        return !empty($result);
    }
    
    /**
     * 更新子栏目的层级和路径
     * 
     * @param int $parent_id 父栏目ID
     */
    private static function update_children_hierarchy($parent_id) {
        $parent = self::get_category($parent_id);
        if (!$parent) {
            return;
        }
        
        $children = self::get_children($parent_id);
        foreach ($children as $child) {
            $new_level = $parent['level'] + 1;
            $new_path = $parent['path'] . $parent_id . ',';
            
            // 更新 term_taxonomy 表中的层级信息（如果有相关字段的话）
            // 注意：标准的 term_taxonomy 表没有 level 和 path 字段
            // 这里可能需要根据实际需求调整或添加自定义字段
            
            // 递归更新子栏目的子栏目
            self::update_children_hierarchy($child['id']);
        }
    }
    
    /**
     * 清除缓存
     */
    private static function clear_cache() {
        self::$categories_cache = array();
        self::$category_tree_cache = null;
        if (class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::flush_group('categories');
        }
    }
    
    /**
     * 获取栏目统计信息
     * 
     * @param int $category_id 栏目ID
     * @return array
     */
    public static function get_category_stats($category_id) {
        self::ensure_db_connection();
        
        $category = self::get_category($category_id);
        if (!$category) {
            return array();
        }
        
        // 使用 term_relationships 表统计内容数
        $table_name = DB_PREFIX . 'posts';
        
        // 总内容数
        $total_query = "SELECT COUNT(DISTINCT p.ID) as total 
                       FROM `{$table_name}` p 
                       INNER JOIN " . DB_PREFIX . "term_relationships tr ON p.ID = tr.object_id 
                       INNER JOIN " . DB_PREFIX . "term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id 
                       WHERE tt.term_id = ? AND tt.taxonomy = 'category'";
        $total = self::$tododb->get_var($total_query, [$category_id]) ?: 0;
        
        // 已发布内容数
        $published_query = "SELECT COUNT(DISTINCT p.ID) as published 
                           FROM `{$table_name}` p 
                           INNER JOIN " . DB_PREFIX . "term_relationships tr ON p.ID = tr.object_id 
                           INNER JOIN " . DB_PREFIX . "term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id 
                           WHERE tt.term_id = ? AND tt.taxonomy = 'category' AND p.post_status = 'publish'";
        $published = self::$tododb->get_var($published_query, [$category_id]) ?: 0;
        
        // 草稿数
        $draft_query = "SELECT COUNT(DISTINCT p.ID) as draft 
                       FROM `{$table_name}` p 
                       INNER JOIN " . DB_PREFIX . "term_relationships tr ON p.ID = tr.object_id 
                       INNER JOIN " . DB_PREFIX . "term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id 
                       WHERE tt.term_id = ? AND tt.taxonomy = 'category' AND p.post_status = 'draft'";
        $draft = self::$tododb->get_var($draft_query, [$category_id]) ?: 0;
        
        // 子栏目数
        $children_count = count(self::get_children($category_id));
        
        return array(
            'total_content' => $total,
            'published_content' => $published,
            'draft_content' => $draft,
            'children_count' => $children_count
        );
    }
}

// 便捷函数
if (!function_exists('todo_get_categories')) {
    /**
     * 获取栏目列表
     * 
     * @param array $args 查询参数
     * @return array
     */
    function todo_get_categories($args = array()) {
        return Todo_Category_Manager::get_categories($args);
    }
}

if (!function_exists('todo_get_category')) {
    /**
     * 获取栏目信息
     * 
     * @param int $category_id 栏目ID
     * @return array|null
     */
    function todo_get_category($category_id) {
        return Todo_Category_Manager::get_category($category_id);
    }
}

if (!function_exists('todo_get_category_tree')) {
    /**
     * 获取栏目树
     * 
     * @param int $parent_id 父栏目ID
     * @param array $args 查询参数
     * @return array
     */
    function todo_get_category_tree($parent_id = 0, $args = array()) {
        return Todo_Category_Manager::get_category_tree($parent_id, $args);
    }
}

if (!function_exists('todo_get_breadcrumb')) {
    /**
     * 获取栏目面包屑
     * 
     * @param int $category_id 栏目ID
     * @return array
     */
    function todo_get_breadcrumb($category_id) {
        return Todo_Category_Manager::get_breadcrumb($category_id);
    }
}

?>
