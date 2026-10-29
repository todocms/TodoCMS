<?php
/**
 * TodoCMS 菜单管理器
 * 
 * 管理导航菜单的创建、编辑、删除和渲染
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    exit('Direct access not permitted');
}

class Todo_Menu_Manager {
    
    /**
     * 数据库连接
     * @var CMS_DB|PDO
     */
    private static $tododb;
    
    /**
     * 菜单缓存
     * @var array
     */
    private static $menus_cache = array();
    
    /**
     * 菜单项缓存
     * @var array
     */
    private static $menu_items_cache = array();

    /**
     * 获取数据库连接（防御式）
     * 统一兼容 CMS_DB 与原生 PDO/Extended_PDO
     */
    private static function get_db()
    {
        if (self::$tododb) {
            return self::$tododb;
        }
        // 回退到全局
        if (isset($GLOBALS['tododb'])) {
            self::$tododb = $GLOBALS['tododb'];
            return self::$tododb;
        }
        throw new Exception('MenuManager: database instance not initialized');
    }

    /**
     * 执行非SELECT语句（INSERT/UPDATE/DELETE），兼容不同连接类型
     */
    private static function db_exec($query, $params = [])
    {
        $db = self::get_db();
        
        // CMS_DB 风格（优先）
        if (is_object($db) && method_exists($db, 'query') && !($db instanceof PDO)) {
            return $db->query($query, $params);
        }
        
        // 原生 PDO / Extended_PDO
        if ($db instanceof PDO) {
            try {
                $stmt = $db->prepare($query);
                if (!is_array($params)) {
                    $params = empty($params) ? [] : [$params];
                }
                $stmt->execute($params);
                $rowCount = $stmt->rowCount();
                try { $stmt->closeCursor(); } catch (Throwable $e) {}
                return $rowCount;
            } catch (PDOException $e) {
                // 与 CMS_DB 的返回风格保持一致：失败返回 false
                return false;
            }
        }
        
        throw new Exception('MenuManager: unsupported database driver');
    }

    /**
     * 获取最后插入ID，兼容不同连接类型
     */
    private static function get_insert_id()
    {
        $db = self::get_db();
        if (is_object($db) && method_exists($db, 'insert_id') && !($db instanceof PDO)) {
            return $db->insert_id();
        }
        if ($db instanceof PDO) {
            return (int)$db->lastInsertId();
        }
        return 0;
    }
    
    /**
     * 菜单项类型常量
     */
    const TYPE_CATEGORY = 'category';
    const TYPE_CONTENT = 'content';
    const TYPE_CUSTOM = 'custom';
    const TYPE_EXTERNAL = 'external';
    
    /**
     * 菜单状态常量
     */
    const STATUS_ACTIVE = 1;
    const STATUS_INACTIVE = 0;
    
    /**
     * 初始化菜单管理器
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
        if (self::$tododb !== null && (self::$tododb instanceof CMS_DB || self::$tododb instanceof PDO)) {
            return;
        }
        
        // 尝试从全局变量获取
        if (isset($tododb) && ($tododb instanceof CMS_DB || $tododb instanceof PDO)) {
            self::$tododb = $tododb;
            return;
        }
        
        // 如果全局变量不存在或不是有效的数据库实例，尝试创建新实例
        if (class_exists('CMS_DB')) {
            try {
                self::$tododb = CMS_DB::get_instance();
                // 同时更新全局变量
                $GLOBALS['tododb'] = self::$tododb;
                $tododb = self::$tododb;
            } catch (Exception $e) {
                error_log('Todo_Menu_Manager: 无法初始化数据库连接 - ' . $e->getMessage());
                throw new Exception('数据库连接不可用');
            }
        } else {
            throw new Exception('CMS_DB 类不存在，无法初始化数据库连接');
        }
    }
    
    /**
     * 创建菜单
     * 
     * @param array $menu_data 菜单数据
     * @return int|false 成功返回菜单ID，失败返回false
     */
    public static function create_menu($menu_data) {
        self::ensure_db_connection();
        
        // 仅要求名称必填，位置可为空（未分配）
        $required_fields = array('name');
        foreach ($required_fields as $field) {
            if (empty($menu_data[$field])) {
                return false;
            }
        }
        
        $db = self::get_db();
        $query = "INSERT INTO " . $db->prefix . "menus 
                  (name, location, description, status, created_at) 
                  VALUES (?, ?, ?, ?, NOW())";
        
        $status = isset($menu_data['status']) ? $menu_data['status'] : self::STATUS_ACTIVE;
        $description = isset($menu_data['description']) ? $menu_data['description'] : '';
        
        $params = array(
            $menu_data['name'],
            $menu_data['location'],
            $description,
            $status
        );
        
        $result = self::db_exec($query, $params);
        if ($result !== false) {
            $menu_id = self::get_insert_id();
            
            // 清除缓存
            self::clear_menu_cache();
            
            return $menu_id;
        }
        
        return false;
    }
    
    /**
     * 更新菜单
     * 
     * @param int $menu_id 菜单ID
     * @param array $menu_data 菜单数据
     * @return bool
     */
    public static function update_menu($menu_id, $menu_data) {
        $menu = self::get_menu($menu_id);
        if (!$menu) {
            return false;
        }
        
        $update_fields = array();
        $params = array();
        $types = '';
        
        $allowed_fields = array('name', 'location', 'description', 'status');
        
        foreach ($allowed_fields as $field) {
            if (isset($menu_data[$field])) {
                $update_fields[] = "{$field} = ?";
                $params[] = $menu_data[$field];
                $types .= 's';
            }
        }
        
        if (empty($update_fields)) {
            return false;
        }
        
        $update_fields[] = "updated_at = NOW()";
        
        $db = self::get_db();
        $query = "UPDATE " . $db->prefix . "menus SET " . implode(', ', $update_fields) . " WHERE id = ?";
        $params[] = $menu_id;
        $types .= 'i';
        
        // 使用CMS_DB的query方法执行更新
        $result = self::db_exec($query, $params);
        if ($result !== false) {
            // 清除缓存
            self::clear_menu_cache($menu_id);
            return true;
        }
        
        return false;
    }
    
    /**
     * 删除菜单
     * 
     * @param int $menu_id 菜单ID
     * @return bool
     */
    public static function delete_menu($menu_id) {
        self::ensure_db_connection();
        
        $menu = self::get_menu($menu_id);
        if (!$menu) {
            return false;
        }
        
        // 开始事务
        self::$tododb->begin_transaction();
        
        try {
            $db = self::get_db();
            // 删除菜单项
            $delete_items_query = "DELETE FROM " . $db->prefix . "menu_items WHERE menu_id = ?";
            $res1 = self::db_exec($delete_items_query, array($menu_id));
            
            // 删除菜单
            $delete_menu_query = "DELETE FROM " . $db->prefix . "menus WHERE id = ?";
            $res2 = self::db_exec($delete_menu_query, array($menu_id));
            
            if ($res1 === false || $res2 === false) {
                throw new Exception('Failed to delete menu');
            }
            
            // 提交事务
            self::$tododb->commit();
            
            // 清除缓存
            self::clear_menu_cache();
            
            return true;
            
        } catch (Exception $e) {
            // 回滚事务
            self::$tododb->rollback();
            todo_log_error('Menu deletion failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取菜单
     * 
     * @param int $menu_id 菜单ID
     * @return array|null
     */
    public static function get_menu($menu_id) {
        self::ensure_db_connection();
        
        if (isset(self::$menus_cache[$menu_id])) {
            return self::$menus_cache[$menu_id];
        }
        $ttl = function_exists('todo_get_fragment_cache_ttl') ? (int) todo_get_fragment_cache_ttl() : 120;
        $lang = function_exists('todo_get_current_language') ? todo_get_current_language() : (function_exists('get_current_language') ? get_current_language() : null);
        if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
            $cached = Todo_Cache_Manager::get('menu_' . $menu_id, 'menus', $lang);
            if ($cached !== false && is_array($cached)) {
                self::$menus_cache[$menu_id] = $cached;
                return $cached;
            }
        }
        $db = self::get_db();
        $query = "SELECT * FROM " . $db->prefix . "menus WHERE id = ?";
        $row = self::$tododb->get_row($query, array($menu_id), 'ARRAY_A');
        if ($row) {
            // 确保返回的是数组格式
            if (is_object($row)) {
                $row = (array) $row;
            }
            self::$menus_cache[$menu_id] = $row;
            if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set('menu_' . $menu_id, $row, $ttl, 'menus', $lang);
            }
            return $row;
        }
        return null;
    }
    
    /**
     * 根据位置获取菜单
     * 
     * @param string $location 菜单位置
     * @param string $menu_id 可选的菜单ID，用于精确定位
     * @return array|null
     */
    public static function get_menu_by_location($location, $menu_id = '') {
        self::ensure_db_connection();
        
        $ttl = function_exists('todo_get_fragment_cache_ttl') ? (int) todo_get_fragment_cache_ttl() : 120;
        $lang = function_exists('todo_get_current_language') ? todo_get_current_language() : (function_exists('get_current_language') ? get_current_language() : null);
        if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
            $k = 'menu_by_location_' . md5($location . '|' . (string)$menu_id);
            $cached = Todo_Cache_Manager::get($k, 'menus', $lang);
            if ($cached !== false && is_array($cached)) {
                return $cached;
            }
        }
        $db = self::get_db();
        if (!empty($menu_id)) {
            $query = "SELECT * FROM " . $db->prefix . "menus WHERE id = ? AND location = ? AND status = 1 LIMIT 1";
            $row = $db->get_row($query, array($menu_id, $location), 'ARRAY_A');
        } else {
            $query = "SELECT * FROM " . $db->prefix . "menus WHERE location = ? AND status = 1 LIMIT 1";
            $row = $db->get_row($query, array($location), 'ARRAY_A');
        }
        if ($row) {
            // 确保返回的是数组格式
            if (is_object($row)) {
                $row = (array) $row;
            }
            if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set('menu_by_location_' . md5($location . '|' . (string)$menu_id), $row, $ttl, 'menus', $lang);
            }
            return $row;
        }
        return null;
    }
    
    /**
     * 获取所有菜单
     * 
     * @param array $args 查询参数
     * @return array
     */
    public static function get_menus($args = array()) {
        self::ensure_db_connection();
        
        $defaults = array(
            'status' => null,
            'location' => null,
            'orderby' => 'name',
            'order' => 'ASC',
            'limit' => null,
            'offset' => 0
        );
        $args = array_merge($defaults, $args);
        $ttl = function_exists('todo_get_fragment_cache_ttl') ? (int) todo_get_fragment_cache_ttl() : 120;
        $lang = function_exists('todo_get_current_language') ? todo_get_current_language() : (function_exists('get_current_language') ? get_current_language() : null);
        if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
            $k = 'menus_list_' . md5(json_encode($args));
            $cached = Todo_Cache_Manager::get($k, 'menus', $lang);
            if ($cached !== false && is_array($cached)) {
                return $cached;
            }
        }
        $where_conditions = array();
        $params = array();
        $types = '';
        if ($args['status'] !== null) {
            $where_conditions[] = "status = ?";
            $params[] = $args['status'];
            $types .= 'i';
        }
        if ($args['location']) {
            $where_conditions[] = "location = ?";
            $params[] = $args['location'];
            $types .= 's';
        }
        $where_sql = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
        $order_sql = "ORDER BY {$args['orderby']} {$args['order']}";
        $limit_sql = '';
        if ($args['limit']) {
            $limit_sql = "LIMIT {$args['offset']}, {$args['limit']}";
        }
        $db = self::get_db();
        $query = "SELECT * FROM " . $db->prefix . "menus {$where_sql} {$order_sql} {$limit_sql}";
        if (!empty($params)) {
            $results = $db->get_results($query, $params);
        } else {
            $results = $db->get_results($query);
        }
        $menus = array();
        if ($results) {
            foreach ($results as $row) {
                if (is_object($row)) {
                    $row = (array) $row;
                }
                $menus[] = $row;
            }
        }
        if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::set('menus_list_' . md5(json_encode($args)), $menus, $ttl, 'menus', $lang);
        }
        return $menus;
    }
    
    /**
     * 添加菜单项
     * 
     * @param array $item_data 菜单项数据
     * @return int|false 成功返回菜单项ID，失败返回false
     */
    public static function add_menu_item($item_data) {
        self::ensure_db_connection();
        
        $required_fields = array('menu_id', 'title', 'type');
        foreach ($required_fields as $field) {
            if (empty($item_data[$field])) {
                return false;
            }
        }
        
        // 获取下一个排序值
        $sort_order = self::get_next_sort_order($item_data['menu_id'], $item_data['parent_id'] ?? 0);
        
        $db = self::get_db();
        $query = "INSERT INTO " . $db->prefix . "menu_items 
                  (menu_id, parent_id, title, url, type, object_id, icon, css_class, target, description, sort_order, status, created_at) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $parent_id = isset($item_data['parent_id']) ? $item_data['parent_id'] : 0;
        $url = isset($item_data['url']) ? $item_data['url'] : '';
        $object_id = isset($item_data['object_id']) ? $item_data['object_id'] : 0;
        $icon = isset($item_data['icon']) ? $item_data['icon'] : '';
        $css_class = isset($item_data['css_class']) ? $item_data['css_class'] : '';
        $target = isset($item_data['target']) ? $item_data['target'] : '_self';
        $description = isset($item_data['description']) ? $item_data['description'] : '';
        $status = isset($item_data['status']) ? $item_data['status'] : self::STATUS_ACTIVE;
        
        $params = array(
            $item_data['menu_id'],
            $parent_id,
            $item_data['title'],
            $url,
            $item_data['type'],
            $object_id,
            $icon,
            $css_class,
            $target,
            $description,
            $sort_order,
            $status
        );
        
        $res = self::db_exec($query, $params);
        if ($res !== false) {
            $item_id = self::get_insert_id();
            
            // 清除缓存
            self::clear_menu_items_cache($item_data['menu_id']);
            
            return $item_id;
        }
        
        return false;
    }
    
    /**
     * 更新菜单项
     * 
     * @param int $item_id 菜单项ID
     * @param array $item_data 菜单项数据
     * @return bool
     */
    public static function update_menu_item($item_id, $item_data) {
        $item = self::get_menu_item($item_id);
        if (!$item) {
            return false;
        }
        
        $update_fields = array();
        $params = array();
        $types = '';
        
        $allowed_fields = array(
            'parent_id' => 'i',
            'title' => 's',
            'url' => 's',
            'type' => 's',
            'object_id' => 'i',
            'icon' => 's',
            'css_class' => 's',
            'target' => 's',
            'description' => 's',
            'sort_order' => 'i',
            'status' => 'i'
        );
        
        foreach ($allowed_fields as $field => $type) {
            if (isset($item_data[$field])) {
                $update_fields[] = "{$field} = ?";
                $params[] = $item_data[$field];
                $types .= $type;
            }
        }
        
        if (empty($update_fields)) {
            return false;
        }
        
        $update_fields[] = "updated_at = NOW()";
        
        $db = self::get_db();
        $query = "UPDATE " . $db->prefix . "menu_items SET " . implode(', ', $update_fields) . " WHERE id = ?";
        $params[] = $item_id;
        $types .= 'i';
        
        // 使用CMS_DB的query执行更新
        $result = self::db_exec($query, $params);
        if ($result !== false) {
            // 清除缓存
            self::clear_menu_items_cache($item['menu_id']);
            return true;
        }
        
        return false;
    }
    
    /**
     * 删除菜单项
     * 
     * @param int $item_id 菜单项ID
     * @return bool
     */
    public static function delete_menu_item($item_id) {
        self::ensure_db_connection();
        
        $item = self::get_menu_item($item_id);
        if (!$item) {
            return false;
        }
        
        // 开始事务
        self::$tododb->begin_transaction();
        
        try {
            // 删除子菜单项
            self::delete_child_menu_items($item_id);
            
            $db = self::get_db();
            // 删除菜单项
            $delete_query = "DELETE FROM " . $db->prefix . "menu_items WHERE id = ?";
            $ok = self::db_exec($delete_query, array($item_id));
            if ($ok === false) {
                throw new Exception('Failed to delete menu item');
            }
            
            // 提交事务
            self::$tododb->commit();
            
            // 清除缓存
            self::clear_menu_items_cache($item['menu_id']);
            
            return true;
            
        } catch (Exception $e) {
            // 回滚事务
            self::$tododb->rollback();
            todo_log_error('Menu item deletion failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 删除指定菜单的所有菜单项
     * 
     * @param int $menu_id 菜单ID
     * @return bool
     */
    public static function delete_menu_items_by_menu_id($menu_id) {
        self::ensure_db_connection();
        
        // 开始事务
        self::$tododb->begin_transaction();
        
        $db = self::get_db();
        try {
            // 删除该菜单的所有菜单项
            $delete_query = "DELETE FROM " . $db->prefix . "menu_items WHERE menu_id = ?";
            $ok = self::db_exec($delete_query, array($menu_id));
            if ($ok === false) {
                throw new Exception('Failed to delete menu items');
            }
            
            // 提交事务
            self::$tododb->commit();
            
            // 清除缓存
            self::clear_menu_items_cache($menu_id);
            
            return true;
            
        } catch (Exception $e) {
            // 回滚事务
            self::$tododb->rollback();
            todo_log_error('Menu items deletion failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取菜单项
     * 
     * @param int $item_id 菜单项ID
     * @return array|null
     */
    public static function get_menu_item($item_id) {
        self::ensure_db_connection();
        
        $db = self::get_db();
        $query = "SELECT * FROM " . $db->prefix . "menu_items WHERE id = ?";
        $row = $db->get_row($query, array($item_id), 'ARRAY_A');
        // 确保返回的是数组格式
        if ($row && is_object($row)) {
            $row = (array) $row;
        }

        if ($row) {
            return $row;
        }

        return null;
    }
    
    /**
     * 获取菜单的所有项目
     * 
     * @param int $menu_id 菜单ID
     * @param bool $hierarchical 是否返回层级结构
     * @return array
     */
    public static function get_menu_items($menu_id, $hierarchical = false, $resolve_url = true) {
        self::ensure_db_connection();
        $cache_key = $menu_id . '_' . ($hierarchical ? 'hierarchical' : 'flat') . '_' . ($resolve_url ? 'r1' : 'r0');
        if (isset(self::$menu_items_cache[$cache_key])) {
            return self::$menu_items_cache[$cache_key];
        }
        $ttl = function_exists('todo_get_fragment_cache_ttl') ? (int) todo_get_fragment_cache_ttl() : 120;
        $lang = function_exists('todo_get_current_language') ? todo_get_current_language() : (function_exists('get_current_language') ? get_current_language() : null);
        if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
            $k = 'menu_items_' . $menu_id . '_' . ($hierarchical ? 'h' : 'f') . '_' . ($resolve_url ? 'r1' : 'r0');
            $cached = Todo_Cache_Manager::get($k, 'menus', $lang);
            if ($cached !== false && is_array($cached)) {
                self::$menu_items_cache[$cache_key] = $cached;
                return $cached;
            }
        }
        $db = self::get_db();
        $query = "SELECT * FROM " . $db->prefix . "menu_items 
                  WHERE menu_id = ? AND status = 1 
                  ORDER BY parent_id, sort_order, title";
        $results = $db->get_results($query, array($menu_id), 'ARRAY_A');
        $items = array();
        if ($results) {
            foreach ($results as $row) {
                if (is_object($row)) {
                    $row = (array) $row;
                }
                if ($resolve_url) {
                    $row['url'] = self::generate_menu_item_url($row);
                }
                
                // 注入分类相关的时间和隐藏参数
                if ($row['type'] === 'category' && !empty($row['object_id'])) {
                    $term_id = intval($row['object_id']);
                    if (function_exists('get_term_meta')) {
                        $meta = get_term_meta($term_id);
                        if (is_array($meta)) {
                            $row['is_hidden'] = isset($meta['is_hidden']) ? intval($meta['is_hidden']) : 0;
                            $row['hidden_start'] = isset($meta['hidden_start']) ? $meta['hidden_start'] : '';
                            $row['hidden_end'] = isset($meta['hidden_end']) ? $meta['hidden_end'] : '';
                        }
                    }
                }
                
                $items[] = $row;
            }
        }
        if ($hierarchical) {
            $items = self::build_menu_hierarchy($items);
        }
        self::$menu_items_cache[$cache_key] = $items;
        if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::set('menu_items_' . $menu_id . '_' . ($hierarchical ? 'h' : 'f') . '_' . ($resolve_url ? 'r1' : 'r0'), $items, $ttl, 'menus', $lang);
        }
        return $items;
    }
    
    /**
     * 渲染菜单
     * 
     * @param string $location 菜单位置
     * @param array $args 渲染参数
     * @return string
     */
    public static function render_menu($location, $args = array()) {
        $defaults = array(
            'container' => 'nav',
            'container_class' => 'menu-' . $location,
            'container_id' => '',
            'menu_class' => 'menu',
            'menu_id' => '',
            'echo' => true,
            'fallback_cb' => null,
            'before' => '',
            'after' => '',
            'link_before' => '',
            'link_after' => '',
            'depth' => 0,
            'walker' => null
        );
        
        $args = array_merge($defaults, $args);
        
        $menu = self::get_menu_by_location($location, $args['menu_id']);
        if (!$menu) {
            $fallback = $args['fallback_cb'];
            if ($fallback && is_callable($fallback)) {
                $output = call_user_func($fallback, $args);
            } else {
                $output = '';
            }
            
            if ($args['echo']) {
                echo $output;
                return '';
            }
            return (string)$output;
        }
        
        $items = self::get_menu_items($menu['id'], true);
        $items = self::filter_visible_menu_items($items);
        
        if (empty($items)) {
            $fallback = $args['fallback_cb'];
            if ($fallback && is_callable($fallback)) {
                $output = call_user_func($fallback, $args);
            } else {
                $output = '';
            }
            
            if ($args['echo']) {
                echo $output;
                return '';
            }
            return (string)$output;
        }
        
        $walker = $args['walker'];
        // 使用自定义walker或默认walker
        if ($walker && is_callable($walker)) {
            $output = call_user_func($walker, $items, $args);
        } else {
            $output = self::default_menu_walker($items, $args);
        }
        
        if ($args['echo']) {
            echo $output;
            return '';
        }
        
        return (string)$output;
    }
    
    /**
     * 默认菜单渲染器
     * 
     * @param array $items 菜单项
     * @param array $args 渲染参数
     * @return string
     */
    private static function default_menu_walker($items, $args) {
        $output = '';
        
        // 容器开始标签
        if ($args['container']) {
            $output .= '<' . $args['container'];
            if ($args['container_class']) {
                $output .= ' class="' . esc_attr($args['container_class']) . '"';
            }
            if ($args['container_id']) {
                $output .= ' id="' . esc_attr($args['container_id']) . '"';
            }
            $output .= '>';
        }
        
        // 菜单列表开始标签
        $output .= '<ul';
        if ($args['menu_class']) {
            $output .= ' class="' . esc_attr($args['menu_class']) . '"';
        }
        if ($args['menu_id']) {
            $output .= ' id="' . esc_attr($args['menu_id']) . '"';
        }
        $output .= '>';
        
        // 渲染菜单项
        $output .= self::render_menu_items($items, $args, 0);
        
        // 菜单列表结束标签
        $output .= '</ul>';
        
        // 容器结束标签
        if ($args['container']) {
            $output .= '</' . $args['container'] . '>';
        }
        
        return $output;
    }
    
    /**
     * 渲染菜单项
     * 
     * @param array $items 菜单项
     * @param array $args 渲染参数
     * @param int $depth 当前深度
     * @return string
     */
    private static function render_menu_items($items, $args, $depth = 0) {
        if ($args['depth'] > 0 && $depth >= $args['depth']) {
            return '';
        }
        
        $output = '';
        
        foreach ($items as $item) {
            $classes = array('menu-item', 'menu-item-' . $item['id']);
            
            if (!empty($item['css_class'])) {
                $classes[] = $item['css_class'];
            }
            
            if (!empty($item['children'])) {
                $classes[] = 'has-children';
            }
            
            // 检查是否为当前页面；顶级菜单同时检查子级是否包含当前页面
            if (self::is_current_menu_item($item)) {
                $classes[] = 'current-menu-item';
            } elseif ($depth === 0 && self::has_current_descendant($item)) {
                $classes[] = 'current-menu-item';
            }
            
            $output .= '<li class="' . implode(' ', $classes) . '">';
            
            // 链接
            $output .= $args['before'];
            $output .= '<a href="' . esc_attr($item['url']) . '"';
            
            // 子菜单缩进
            if ($depth > 0) {
                $output .= ' style="padding-left:' . (20 * $depth) . 'px"';
            }
            
            if ($item['target'] !== '_self') {
                $output .= ' target="' . esc_attr($item['target']) . '"';
            }
            
            if (!empty($item['description'])) {
                $output .= ' title="' . esc_attr($item['description']) . '"';
            }
            
            $output .= '>';
            $icon_html = !empty($item['icon']) ? $item['icon'] : '';
            $title_label = isset($item['title']) ? $item['title'] : '';
            if (function_exists('todo_t')) { $title_label = todo_t($title_label, 'menu'); }
            $output .= $args['link_before'] . $icon_html . esc_html($title_label) . $args['link_after'];
            if ($depth === 0 && !empty($item['children'])) {
                $output .= '<i class="icon todofont todo-Right ro90"></i>';
            }
            $output .= '</a>';
            $output .= $args['after'];
            
            // 子菜单
            if (!empty($item['children'])) {
                $output .= '<ul class="sub-menu">';
                $output .= self::render_menu_items($item['children'], $args, $depth + 1);
                $output .= '</ul>';
            }
            
            $output .= '</li>';
        }
        
        return $output;
    }

    public static function filter_visible_menu_items($items)
    {
        $filtered = array();

        foreach ((array) $items as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (!self::is_menu_item_visible($item)) {
                continue;
            }

            if (!empty($item['children']) && is_array($item['children'])) {
                $item['children'] = self::filter_visible_menu_items($item['children']);
            }

            $filtered[] = $item;
        }

        return $filtered;
    }

    private static function is_menu_item_visible($item)
    {
        $type = isset($item['type']) ? (string) $item['type'] : '';
        $object_id = !empty($item['object_id']) ? (int) $item['object_id'] : 0;

        if ($type === self::TYPE_CATEGORY && $object_id > 0 && function_exists('get_term_meta')) {
            $is_hidden = get_term_meta($object_id, 'is_hidden', true);
            if (!empty($is_hidden)) {
                return false;
            }
        }

        return true;
    }
    
    /**
     * 构建菜单层级结构
     * 
     * @param array $items 菜单项
     * @param int $parent_id 父级ID
     * @return array
     */
    private static function build_menu_hierarchy($items, $parent_id = 0) {
        $hierarchy = array();
        
        foreach ($items as $item) {
            if ($item['parent_id'] == $parent_id) {
                $children = self::build_menu_hierarchy($items, $item['id']);
                if (!empty($children)) {
                    $item['children'] = $children;
                }
                $hierarchy[] = $item;
            }
        }
        
        return $hierarchy;
    }
    
    /**
     * 生成菜单项URL
     * 
     * @param array $item 菜单项
     * @return string
     */
    private static function generate_menu_item_url($item) {
        $url = '#';
        switch ($item['type']) {
            case self::TYPE_CATEGORY:
                if ($item['object_id']) {
                    $url = todo_get_category_link($item['object_id']);
                }
                break;

            case 'tag':
                if (!empty($item['object_id'])) {
                    $tag_path = self::build_tag_menu_path($item['object_id']);
                    if ($tag_path !== '') {
                        $url = $tag_path;
                    }
                }
                break;
                
            case self::TYPE_CONTENT:
                if ($item['object_id']) {
                    $url = todo_get_content_url($item['object_id']);
                }
                break;
                
            case self::TYPE_CUSTOM:
            case self::TYPE_EXTERNAL:
                $url = $item['url'];
                break;
                
            default:
                $url = isset($item['url']) ? $item['url'] : '#';
        }

        // Apply language prefix to custom URLs if they are relative or match current domain
        if (in_array($item['type'], [self::TYPE_CUSTOM, self::TYPE_EXTERNAL]) || !in_array($item['type'], [self::TYPE_CATEGORY, 'tag', self::TYPE_CONTENT])) {
            if ($url !== '#' && $url !== '') {
                // Prepend base URL to relative paths before passing to get_language_url
                if (strpos($url, '/') === 0 && parse_url($url, PHP_URL_HOST) === null) {
                    if (function_exists('todo_home_url')) {
                        $url = rtrim(todo_home_url('/'), '/') . '/' . ltrim($url, '/');
                        $url = rtrim($url, '/');
                        if ($url === '') {
                            $url = '/';
                        }
                    }
                }

                if (function_exists('get_current_language') && function_exists('get_language_url')) {
                    $host = (string) @parse_url($url, PHP_URL_HOST);
                    $current_host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
                    if ($current_host !== '') {
                        $current_host = (string) @parse_url('http://' . $current_host, PHP_URL_HOST);
                    }
                    if ($host === '' || ($current_host !== '' && strtolower($host) === strtolower($current_host))) {
                        $url = get_language_url(get_current_language(), $url);
                    }
                }
            }
        }
        
        return $url;
    }

    /**
     * 构建标签菜单路径，优先使用 ID 路径，便于统一与避免 slug 缺失导致的空路径。
     *
     * @param int|string $tag_id
     * @return string
     */
    private static function build_tag_menu_path($tag_id) {
        $tag_id = intval($tag_id);
        if ($tag_id <= 0) {
            return '';
        }

        return '/tag/' . $tag_id . '/';
    }
    
    /**
     * 检查是否为当前菜单项
     * 
     * @param array $item 菜单项
     * @return bool
     */
    private static function is_current_menu_item($item) {
        $current_url = $_SERVER['REQUEST_URI'];
        
        // 同时比较 path 和 query string，确保 ?cat=1 这类 URL 也能正确匹配
        $item_path = parse_url($item['url'], PHP_URL_PATH) ?: '/';
        $item_query = parse_url($item['url'], PHP_URL_QUERY) ?: '';
        
        $current_path = parse_url($current_url, PHP_URL_PATH) ?: '/';
        $current_query = parse_url($current_url, PHP_URL_QUERY) ?: '';
        
        return $current_path === $item_path && $current_query === $item_query;
    }
    
    /**
     * 检查菜单项的子级中是否包含当前页面
     * 
     * @param array $item 菜单项
     * @return bool
     */
    private static function has_current_descendant($item) {
        if (empty($item['children'])) {
            return false;
        }
        foreach ($item['children'] as $child) {
            if (self::is_current_menu_item($child) || self::has_current_descendant($child)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * 获取下一个排序值
     * 
     * @param int $menu_id 菜单ID
     * @param int $parent_id 父级ID
     * @return int
     */
    private static function get_next_sort_order($menu_id, $parent_id = 0) {
        self::ensure_db_connection();
        
        $db = self::get_db();
        $query = "SELECT MAX(sort_order) as max_order FROM " . $db->prefix . "menu_items 
                  WHERE menu_id = ? AND parent_id = ?";
        
        $row = $db->get_row($query, array($menu_id, $parent_id), 'ARRAY_A');
        // 确保返回的是数组格式
        if ($row && is_object($row)) {
            $row = (array) $row;
        }

        if ($row) {
            return ((int)($row['max_order'] ?? 0)) + 1;
        }

        return 1;
    }
    
    /**
     * 删除子菜单项
     * 
     * @param int $parent_id 父级ID
     */
    private static function delete_child_menu_items($parent_id) {
        self::ensure_db_connection();
        
        $query = "SELECT id FROM " . DB_PREFIX . "menu_items WHERE parent_id = ?";
        $results = self::$tododb->get_results($query, array($parent_id), 'ARRAY_A');

        if ($results) {
            foreach ($results as $row) {
                if (is_object($row)) { $row = (array) $row; }
                // 递归删除子项
                self::delete_child_menu_items($row['id']);
                
                // 删除当前项
                $delete_query = "DELETE FROM " . DB_PREFIX . "menu_items WHERE id = ?";
                self::db_exec($delete_query, array($row['id']));
            }
        }
    }
    
    /**
     * 更新菜单项排序
     * 
     * @param int $menu_id 菜单ID
     * @param array $items_order 菜单项排序数组
     * @return bool
     */
    public static function update_menu_items_order($menu_id, $items_order) {
        self::ensure_db_connection();
        
        if (empty($items_order) || !is_array($items_order)) {
            return false;
        }
        
        // 开始事务
        self::$tododb->begin_transaction();
        
        try {
            foreach ($items_order as $item_id => $order_data) {
                $query = "UPDATE " . DB_PREFIX . "menu_items 
                          SET sort_order = ?, parent_id = ? 
                          WHERE id = ?";
                
                // 改为使用统一的参数化执行，避免适配器 prepare 返回字符串
                $sort_order = isset($order_data['sort_order']) ? (int)$order_data['sort_order'] : 0;
                $parent_id  = isset($order_data['parent_id'])  ? (int)$order_data['parent_id']  : 0;
                $item_id_int = (int)$item_id;
                $ok = self::db_exec($query, array($sort_order, $parent_id, $item_id_int));
                if ($ok === false) {
                    throw new Exception('Failed to update menu item order');
                }
            }
            
            // 提交事务
            self::$tododb->commit();
            
            // 清除缓存
            self::clear_menu_items_cache();
            
            return true;
            
        } catch (Exception $e) {
            // 回滚事务
            self::$tododb->rollback();
            todo_log_error('Menu items order update failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取菜单位置列表
     * 
     * @return array
     */
    public static function get_menu_locations() {
        return array(
            'primary' => '主导航',
            'secondary' => '次导航',
            'footer' => '页脚导航',
            'sidebar' => '侧边栏导航',
            'mobile' => '移动端导航'
        );
    }
    
    /**
     * 清除菜单缓存
     * 
     * @param int $menu_id 菜单ID（可选）
     */
    private static function clear_menu_cache($menu_id = null) {
        if ($menu_id) {
            unset(self::$menus_cache[$menu_id]);
        } else {
            self::$menus_cache = array();
        }
        
        // 多语言环境下应整组清除菜单缓存，确保所有语言和键都被清除
        if (class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::flush_group('menus');
        }
    }
    
    /**
     * 清除菜单项缓存
     * 
     * @param int $menu_id 菜单ID（可选）
     */
    private static function clear_menu_items_cache($menu_id = null) {
        if ($menu_id) {
            $keys_to_remove = array();
            foreach (self::$menu_items_cache as $key => $value) {
                if (strpos($key, $menu_id . '_') === 0) {
                    $keys_to_remove[] = $key;
                }
            }
            foreach ($keys_to_remove as $key) {
                unset(self::$menu_items_cache[$key]);
            }
        } else {
            self::$menu_items_cache = array();
        }
        
        // 多语言环境下应整组清除菜单缓存，确保所有语言和键都被清除
        if (class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::flush_group('menus');
        }
    }
    
    /**
     * 获取菜单统计
     * 
     * @return array
     */
    public static function get_menu_stats() {
        $stats = array(
            'total_menus' => 0,
            'active_menus' => 0,
            'total_items' => 0
        );
        
        // 菜单统计
        $menu_query = "SELECT status, COUNT(*) as count FROM " . DB_PREFIX . "menus GROUP BY status";
        $result = self::db_exec($menu_query);
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $stats['total_menus'] += $row['count'];
                
                if ($row['status'] == self::STATUS_ACTIVE) {
                    $stats['active_menus'] = $row['count'];
                }
            }
        }
        
        // 菜单项统计
        $items_query = "SELECT COUNT(*) as count FROM " . DB_PREFIX . "menu_items";
        $result = self::db_exec($items_query);
        
        if ($result && $row = $result->fetch_assoc()) {
            $stats['total_items'] = $row['count'];
        }
        
        return $stats;
    }
}

// 便捷函数
if (!function_exists('todo_get_menu')) {
    /**
     * 获取菜单
     * 
     * @param int $menu_id 菜单ID
     * @return array|null
     */
    function todo_get_menu($menu_id) {
        return Todo_Menu_Manager::get_menu($menu_id);
    }
}

if (!function_exists('todo_get_menu_by_location')) {
    /**
     * 根据位置获取菜单
     * 
     * @param string $location 菜单位置
     * @return array|null
     */
    function todo_get_menu_by_location($location) {
        return Todo_Menu_Manager::get_menu_by_location($location);
    }
}

if (!function_exists('todo_render_menu')) {
    /**
     * 渲染菜单
     * 
     * @param string $location 菜单位置
     * @param array $args 渲染参数
     * @return string
     */
    function todo_render_menu($location, $args = array()) {
        return Todo_Menu_Manager::render_menu($location, $args);
    }
}

if (!function_exists('todo_get_menu_items')) {
    /**
     * 获取菜单项
     * 
     * @param int $menu_id 菜单ID
     * @param bool $hierarchical 是否返回层级结构
     * @return array
     */
    function todo_get_menu_items($menu_id, $hierarchical = false, $resolve_url = true) {
        return Todo_Menu_Manager::get_menu_items($menu_id, $hierarchical, $resolve_url);
    }
}

if (!function_exists('todo_create_menu')) {
    /**
     * 创建菜单
     * 
     * @param array $menu_data 菜单数据
     * @return int|false
     */
    function todo_create_menu($menu_data) {
        return Todo_Menu_Manager::create_menu($menu_data);
    }
}

if (!function_exists('todo_add_menu_item')) {
    /**
     * 添加菜单项
     * 
     * @param array $item_data 菜单项数据
     * @return int|false
     */
    function todo_add_menu_item($item_data) {
        return Todo_Menu_Manager::add_menu_item($item_data);
    }
}

if (!function_exists('todo_get_menu_locations')) {
    /**
     * 获取菜单位置列表
     * 
     * @return array
     */
    function todo_get_menu_locations() {
        return Todo_Menu_Manager::get_menu_locations();
    }
}

// HTML转义函数（如果不存在）
if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
    }
}

?>
