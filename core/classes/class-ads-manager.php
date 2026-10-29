<?php
/**
 * 广告管理类
 * TodoCMS 广告系统核心类
 *
 * @package TodoCMS
 * @version 1.0.0
 */

class Todo_Ads_Manager {
    private static $instance = null;
    private static $tododb = null;
    private static $cache = array(); // 静态内存缓存

    /**
     * 初始化广告管理器
     */
    public static function init() {
        global $tododb;
        self::$tododb = $tododb;
        
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * 获取实例
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::init();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // 私有构造函数，防止直接实例化
    }
    
    /**
     * 获取所有广告
     * @param array $filters 筛选条件
     * @return array
     */
    public static function get_ads($filters = array()) {
        global $tododb;
        if (!$tododb) return array();

        // 检查内存缓存
        $cache_key = 'get_ads_' . md5(serialize($filters));
        if (isset(self::$cache[$cache_key])) {
            return self::$cache[$cache_key];
        }
        
        $where_conditions = array('1=1');
        $params = array();
        
        // 状态筛选
        if (isset($filters['status']) && $filters['status'] !== '') {
            $statusFilter = $filters['status'];
            if (is_numeric($statusFilter)) {
                $where_conditions[] = 'status = ?';
                $params[] = intval($statusFilter);
            } else {
                $statusStr = strtolower(trim((string)$statusFilter));
                if ($statusStr === 'active') {
                    $where_conditions[] = 'status = ?';
                    $params[] = 1;
                } elseif ($statusStr === 'inactive') {
                    $where_conditions[] = 'status = ?';
                    $params[] = 0;
                } else {
                    $where_conditions[] = 'status = ?';
                    $params[] = $filters['status'];
                }
            }
        }
        
        // 类型筛选
        if (!empty($filters['type'])) {
            $where_conditions[] = 'type = ?';
            $params[] = $filters['type'];
        }
        
        // 分组筛选
        if (!empty($filters['group_id'])) {
            $where_conditions[] = 'group_id = ?';
            $params[] = intval($filters['group_id']);
        }

        if (isset($filters['sort_order']) && $filters['sort_order'] !== '') {
            $where_conditions[] = 'sort_order = ?';
            $params[] = intval($filters['sort_order']);
        }
        
        // 时间范围筛选（有效期内的广告）
        if (!empty($filters['active_only'])) {
            $where_conditions[] = '(start_date IS NULL OR start_date <= NOW())';
            $where_conditions[] = '(end_date IS NULL OR end_date >= NOW())';
            
            // 时间段筛选（针对弹窗广告）
            $current_time = date('H:i:s');
            $where_conditions[] = '(
                (start_time IS NULL AND end_time IS NULL)
                OR (start_time IS NULL AND end_time IS NOT NULL AND end_time >= "' . $current_time . '")
                OR (start_time IS NOT NULL AND end_time IS NULL AND start_time <= "' . $current_time . '")
                OR (
                    start_time IS NOT NULL AND end_time IS NOT NULL AND (
                        (start_time <= end_time AND start_time <= "' . $current_time . '" AND end_time >= "' . $current_time . '")
                        OR
                        (start_time > end_time AND (start_time <= "' . $current_time . '" OR end_time >= "' . $current_time . '"))
                    )
                )
            )';
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        $order_by = isset($filters['order_by']) ? $filters['order_by'] : 'sort_order ASC, id DESC';
        
        // Limit & Offset 支持
        $limit_clause = '';
        if (isset($filters['limit']) && intval($filters['limit']) > 0) {
            $limit = intval($filters['limit']);
            $offset = isset($filters['offset']) ? intval($filters['offset']) : 0;
            $limit_clause = " LIMIT {$offset}, {$limit}";
        }
        
        $sql = "SELECT * FROM " . TABLE_PREFIX . "ads WHERE {$where_clause} ORDER BY {$order_by}" . $limit_clause;
        
        // 统一使用 ? 占位符与参数数组传递
        $results = $tododb->get_results($sql, !empty($params) ? $params : [], 'ARRAY_A');
        
        // 写入缓存
        self::$cache[$cache_key] = $results;
        
        return $results;
    }    
    /**
     * 获取单个广告
     * @param int $id 广告ID
     * @return array|null
     */
    public static function get_ad($id) {
        global $tododb;
        if (!$tododb) return null;
        
        $cache_key = 'get_ad_' . $id;
        if (isset(self::$cache[$cache_key])) {
            return self::$cache[$cache_key];
        }
        
        $sql = "SELECT * FROM " . TABLE_PREFIX . "ads WHERE id = ?";
        $result = $tododb->get_row($sql, [$id], 'ARRAY_A');
        
        $result = $result ?: null;
        self::$cache[$cache_key] = $result;
        
        return $result;
    }
    
    /**
     * 创建新广告
     * @param array $data 广告数据
     * @return int|false 返回新广告ID或false
     */
    public static function create_ad($data) {
        global $tododb;
        if (!$tododb) return false;
        
        $required_fields = array('title', 'type'); // position 字段已删除
        
        // 验证必填字段
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                return false;
            }
        }
        
        // 准备数据
        $insert_data = array(
            'title' => sanitize_text_field($data['title']),
            'type' => sanitize_text_field($data['type']),
            'content' => isset($data['content']) ? $data['content'] : '',
            'image_url' => isset($data['image_url']) ? esc_url_raw($data['image_url']) : '',
            'images' => isset($data['images']) ? $data['images'] : '', // 直接使用已处理的JSON字符串
            'link_url' => isset($data['link_url']) ? esc_url_raw($data['link_url']) : '',
            'target' => isset($data['target']) ? sanitize_text_field($data['target']) : '_self',
            'group_id' => isset($data['group_id']) ? intval($data['group_id']) : null,
            'is_white_text' => isset($data['is_white_text']) ? intval($data['is_white_text']) : 0,
            'start_time' => !empty($data['start_time']) ? $data['start_time'] : null,
            'end_time' => !empty($data['end_time']) ? $data['end_time'] : null,
            'sort_order' => isset($data['sort_order']) ? intval($data['sort_order']) : 0,
            'status' => isset($data['status']) ? (is_numeric($data['status']) ? intval($data['status']) : (strtolower(trim($data['status'])) === 'active' ? 1 : 0)) : 1,
            'start_date' => !empty($data['start_date']) ? $data['start_date'] : null,
            'end_date' => !empty($data['end_date']) ? $data['end_date'] : null,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        );        
        $result = $tododb->insert(TABLE_PREFIX . 'ads', $insert_data);
        if ($result) {
            $new_ad_id = method_exists($tododb, 'insert_id') ? intval($tododb->insert_id()) : 0;
            self::$cache = array();
            if (class_exists('Todo_Unified_Cache_Manager') && method_exists('Todo_Unified_Cache_Manager', 'set_pending_ad_change_context')) {
                Todo_Unified_Cache_Manager::set_pending_ad_change_context(array(
                    'ad_ids' => $new_ad_id > 0 ? array($new_ad_id) : array(),
                    'group_ids' => !empty($insert_data['group_id']) ? array(intval($insert_data['group_id'])) : array(),
                    'markers' => array('single_ads_default'),
                ));
            }
            if (!function_exists('todo_update_option')) {
                require_once ABSPATH . 'core/functions/todo-core-functions.php';
            }
            todo_update_option('todo_ads_last_modified', time(), 'general');
        }
        return $result;
    }
    
    /**
     * 更新广告
     * @param int $id 广告ID
     * @param array $data 更新数据
     * @return bool
     */
    public static function update_ad($id, $data) {
        global $tododb;
        if (!$tododb) return false;
        
        if (empty($id) || !is_numeric($id)) {
            return false;
        }
        
        $existing_ad = self::get_ad($id);

        // 准备更新数据
        $update_data = array();
        
        $allowed_fields = array('title', 'type', 'content', 'image_url', 'images', 'link_url', 'target', 'group_id', 'is_white_text', 'start_time', 'end_time', 'sort_order', 'status', 'start_date', 'end_date'); // position 和弹窗字段已删除
        
        foreach ($allowed_fields as $field) {
            if (array_key_exists($field, $data)) {
                switch ($field) {
                    case 'title':
                    case 'type':
                    case 'target':
                        $update_data[$field] = sanitize_text_field($data[$field]);
                        break;
                    case 'status':
                        $val = $data[$field];
                        if (is_numeric($val)) {
                            $update_data[$field] = intval($val);
                        } else {
                            $statusStr = strtolower(trim((string)$val));
                            $update_data[$field] = ($statusStr === 'active') ? 1 : 0;
                        }
                        break;
                    case 'content':
                        $update_data[$field] = $data[$field];
                        break;
                    case 'image_url':
                    case 'link_url':
                        $update_data[$field] = esc_url_raw($data[$field]);
                        break;
                    case 'images':
                        $update_data[$field] = is_array($data[$field]) ? json_encode($data[$field]) : $data[$field];
                        break;
                    case 'group_id':
                    case 'is_white_text':
                    case 'sort_order':
                        $update_data[$field] = intval($data[$field]);
                        break;
                    case 'start_date':
                    case 'end_date':
                    case 'start_time':
                    case 'end_time':
                        $update_data[$field] = !empty($data[$field]) ? $data[$field] : null;
                        break;
                }
            }
        }
        
        $update_data['updated_at'] = current_time('mysql');
        
        $result = $tododb->update(TABLE_PREFIX . 'ads', $update_data, array('id' => $id));
        if ($result) {
            self::$cache = array();
            $group_ids = array();
            if (is_array($existing_ad) && !empty($existing_ad['group_id'])) {
                $group_ids[] = intval($existing_ad['group_id']);
            }
            if (!empty($update_data['group_id'])) {
                $group_ids[] = intval($update_data['group_id']);
            } elseif (is_array($existing_ad) && !empty($existing_ad['group_id'])) {
                $group_ids[] = intval($existing_ad['group_id']);
            }
            if (class_exists('Todo_Unified_Cache_Manager') && method_exists('Todo_Unified_Cache_Manager', 'set_pending_ad_change_context')) {
                Todo_Unified_Cache_Manager::set_pending_ad_change_context(array(
                    'ad_ids' => array(intval($id)),
                    'group_ids' => $group_ids,
                    'markers' => array('single_ads_default'),
                ));
            }
            if (!function_exists('todo_update_option')) {
                require_once ABSPATH . 'core/functions/todo-core-functions.php';
            }
            todo_update_option('todo_ads_last_modified', time(), 'general');
        }
        return $result;
    }    
    /**
     * 删除广告
     * @param int $id 广告ID
     * @return bool
     */
    public static function delete_ad($id) {
        global $tododb;
        if (!$tododb) return false;
        
        if (empty($id) || !is_numeric($id)) {
            return false;
        }
        
        $existing_ad = self::get_ad($id);

        // 删除广告
        $result = $tododb->delete(TABLE_PREFIX . 'ads', array('id' => $id));

        // 若删除成功，更新全局“广告最近变更时间”，用于触发静态缓存失效
        if ($result) {
            self::$cache = array();
            $group_ids = array();
            if (is_array($existing_ad) && !empty($existing_ad['group_id'])) {
                $group_ids[] = intval($existing_ad['group_id']);
            }
            if (class_exists('Todo_Unified_Cache_Manager') && method_exists('Todo_Unified_Cache_Manager', 'set_pending_ad_change_context')) {
                Todo_Unified_Cache_Manager::set_pending_ad_change_context(array(
                    'ad_ids' => array(intval($id)),
                    'group_ids' => $group_ids,
                    'markers' => array('single_ads_default'),
                ));
            }
            if (!function_exists('todo_update_option')) {
                require_once ABSPATH . 'core/functions/todo-core-functions.php';
            }
            // 使用统一选项系统记录一个时间戳
            todo_update_option('todo_ads_last_modified', time(), 'general');
        }

        return (bool)$result;
    }
    
    /**
     * 获取所有广告分组
     * @param array $filters 筛选条件
     * @return array
     */
    public static function get_ad_groups($filters = array()) {
        global $tododb;
        if (!$tododb) return array();
        
        $where_conditions = array('1=1');
        $params = array();
        
        // 状态筛选
        if (isset($filters['status']) && $filters['status'] !== '') {
            $statusFilter = $filters['status'];
            if (is_numeric($statusFilter)) {
                $where_conditions[] = 'status = ?';
                $params[] = intval($statusFilter);
            } else {
                $statusStr = strtolower(trim((string)$statusFilter));
                if ($statusStr === 'active') {
                    $where_conditions[] = 'status = ?';
                    $params[] = 1;
                } elseif ($statusStr === 'inactive') {
                    $where_conditions[] = 'status = ?';
                    $params[] = 0;
                } else {
                    $where_conditions[] = 'status = ?';
                    $params[] = $filters['status'];
                }
            }
        }
        
        // 类型筛选
        if (!empty($filters['type'])) {
            $where_conditions[] = 'type = ?';
            $params[] = $filters['type'];
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        $order_by = isset($filters['order_by']) ? $filters['order_by'] : 'sort_order ASC, id DESC';
        
        $sql = "SELECT * FROM " . TABLE_PREFIX . "ad_groups WHERE {$where_clause} ORDER BY {$order_by}";
        
        // 统一使用 ? 占位符与参数数组传递
        return $tododb->get_results($sql, !empty($params) ? $params : [], 'ARRAY_A');
    }
    
    /**
     * 获取单个广告分组
     * @param int $id 分组ID
     * @return array|null
     */
    public static function get_ad_group($id) {
        global $tododb;
        if (!$tododb) return null;
        
        $sql = "SELECT * FROM " . TABLE_PREFIX . "ad_groups WHERE id = ?";
        $result = $tododb->get_row($sql, [$id], 'ARRAY_A');
        return $result ?: null;
    }
    
    /**
     * 根据分组代码获取广告分组
     * @param string $code 分组代码
     * @return array|null
     */
    public static function get_ad_group_by_code($code) {
        global $tododb;
        if (!$tododb) return null;
        
        $sql = "SELECT * FROM " . TABLE_PREFIX . "ad_groups WHERE code = ?";
        $result = $tododb->get_row($sql, [$code], 'ARRAY_A');
        return $result ?: null;
    }
    
    /**
     * 创建新广告分组
     * @param array $data 分组数据
     * @return int|false 返回新分组ID或false
     */
    public static function create_ad_group($data) {
        global $tododb;
        if (!$tododb) return false;
        
        $required_fields = array('name', 'code');
        
        // 验证必填字段
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                return false;
            }
        }
        
        // 检查代码是否已存在
        if (self::get_ad_group_by_code($data['code'])) {
            return false;
        }
        
        // 准备数据
        $insert_data = array(
            'name' => sanitize_text_field($data['name']),
            'code' => sanitize_text_field($data['code']),
            'description' => isset($data['description']) ? $data['description'] : '',
            'sort_order' => isset($data['sort_order']) ? intval($data['sort_order']) : 0,
            'status' => isset($data['status']) ? (is_numeric($data['status']) ? intval($data['status']) : (strtolower(trim($data['status'])) === 'active' ? 1 : 0)) : 1,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        );
        
        $result = $tododb->insert(TABLE_PREFIX . 'ad_groups', $insert_data);
        
        if ($result) {
            $new_group_id = method_exists($tododb, 'insert_id') ? intval($tododb->insert_id()) : 0;
            self::$cache = array();
            if (class_exists('Todo_Unified_Cache_Manager') && method_exists('Todo_Unified_Cache_Manager', 'set_pending_ad_change_context')) {
                Todo_Unified_Cache_Manager::set_pending_ad_change_context(array(
                    'group_ids' => $new_group_id > 0 ? array($new_group_id) : array(),
                    'markers' => array('group_ads_default'),
                ));
            }
            if (!function_exists('todo_update_option')) {
                require_once ABSPATH . 'core/functions/todo-core-functions.php';
            }
            todo_update_option('todo_ads_last_modified', time(), 'general');
        }
        
        return $result;
    }
    
    /**
     * 更新广告分组
     * @param int $id 分组ID
     * @param array $data 更新数据
     * @return bool
     */
    public static function update_ad_group($id, $data) {
        global $tododb;
        if (!$tododb) return false;
        
        if (empty($id) || !is_numeric($id)) {
            return false;
        }
        
        // 如果更新代码，检查是否已存在
        if (isset($data['code'])) {
            $existing = self::get_ad_group_by_code($data['code']);
            if ($existing && $existing['id'] != $id) {
                return false;
            }
        }
        
        // 准备更新数据
        $update_data = array();
        
        $allowed_fields = array('name', 'code', 'description', 'sort_order', 'status');
        
        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                switch ($field) {
                    case 'name':
                    case 'code':
                        $update_data[$field] = sanitize_text_field($data[$field]);
                        break;
                    case 'status':
                        // status 存储为整型：1=active, 0=inactive
                        $val = $data[$field];
                        if (is_numeric($val)) {
                            $update_data[$field] = intval($val);
                        } else {
                            $statusStr = strtolower(trim((string)$val));
                            $update_data[$field] = ($statusStr === 'active') ? 1 : 0;
                        }
                        break;
                    case 'description':
                        $update_data[$field] = $data[$field];
                        break;
                    case 'sort_order':
                        $update_data[$field] = intval($data[$field]);
                        break;
                }
            }
        }
        
        $update_data['updated_at'] = current_time('mysql');
        
        $result = $tododb->update(TABLE_PREFIX . 'ad_groups', $update_data, array('id' => $id));
        
        if ($result) {
            self::$cache = array();
            if (class_exists('Todo_Unified_Cache_Manager') && method_exists('Todo_Unified_Cache_Manager', 'set_pending_ad_change_context')) {
                Todo_Unified_Cache_Manager::set_pending_ad_change_context(array(
                    'group_ids' => array(intval($id)),
                    'markers' => array('group_ads_default'),
                ));
            }
            if (!function_exists('todo_update_option')) {
                require_once ABSPATH . 'core/functions/todo-core-functions.php';
            }
            todo_update_option('todo_ads_last_modified', time(), 'general');
        }
        
        return $result;
    }
    
    /**
     * 删除广告分组
     * @param int $id 分组ID
     * @return bool
     */
    public static function delete_ad_group($id) {
        global $tododb;
        if (!$tododb) return false;
        
        if (empty($id) || !is_numeric($id)) {
            return false;
        }
        
        // 检查是否有广告使用此分组
        $sql = "SELECT COUNT(*) FROM " . TABLE_PREFIX . "ads WHERE group_id = ?";
        $ads_count = $tododb->get_var($sql, [$id]);
        
        if ($ads_count > 0) {
            return false; // 有广告使用此分组，不能删除
        }
        
        $result = $tododb->delete(TABLE_PREFIX . 'ad_groups', array('id' => $id));
        
        if ($result) {
            self::$cache = array();
            if (class_exists('Todo_Unified_Cache_Manager') && method_exists('Todo_Unified_Cache_Manager', 'set_pending_ad_change_context')) {
                Todo_Unified_Cache_Manager::set_pending_ad_change_context(array(
                    'group_ids' => array(intval($id)),
                    'markers' => array('group_ads_default'),
                ));
            }
            if (!function_exists('todo_update_option')) {
                require_once ABSPATH . 'core/functions/todo-core-functions.php';
            }
            todo_update_option('todo_ads_last_modified', time(), 'general');
        }
        
        return $result;
    }
    
    /**
     * 根据分组获取广告
     * @param int|string $group 分组ID或代码
     * @param int $limit 限制数量
     * @return array
     */
    public static function get_ads_by_group($group, $limit = null) {
        $group_id = is_numeric($group) ? $group : null;
        
        if (!$group_id) {
            $group_data = self::get_ad_group_by_code($group);
            if (!$group_data) {
                return array();
            }
            $group_id = $group_data['id'];
        }
        
        $filters = array(
            'group_id' => $group_id,
            'status' => 'active',
            'active_only' => true
        );
        
        $ads = self::get_ads($filters);
        
        if ($limit && is_numeric($limit)) {
            $ads = array_slice($ads, 0, $limit);
        }
        
        return $ads;
    }
    
    /**
     * 获取广告统计数据
     * @param int $ad_id 广告ID
     * @return array
     */
    public static function get_ad_stats($ad_id) {
        global $tododb;
        if (!$tododb) return array();
        
        $ad = self::get_ad($ad_id);
        if (!$ad) {
            return array();
        }
        
        // 统计功能已简化：不再记录展示/点击日志与累计值
        $today_views = 0;
        $today_clicks = 0;
        $total_views = 0;
        $total_clicks = 0;
        $ctr = 0;

        return array(
            'total_views' => $total_views,
            'total_clicks' => $total_clicks,
            'today_views' => $today_views,
            'today_clicks' => $today_clicks,
            'ctr' => $ctr
        );
    }
    
    /**
     * 获取总体统计数据
     * @return array
     */
    public static function get_overall_stats() {
        global $tododb;
        if (!$tododb) return array();
        
        // 获取总体统计
        $total_ads = $tododb->get_var("SELECT COUNT(*) FROM " . TABLE_PREFIX . "ads");
        $active_ads = $tododb->get_var("SELECT COUNT(*) FROM " . TABLE_PREFIX . "ads WHERE status = 'active'");
        
        // 获取总展示和点击
        $total_views = $tododb->get_var("SELECT SUM(view_count) FROM " . TABLE_PREFIX . "ads");
        $total_clicks = $tododb->get_var("SELECT SUM(click_count) FROM " . TABLE_PREFIX . "ads");
        
        // 统计功能已简化：不再统计展示/点击日志与累计值
        $today_views = 0;
        $today_clicks = 0;
        $month_views = 0;
        $month_clicks = 0;
        $total_views = 0;
        $total_clicks = 0;
        $ctr = 0;
        
        return array(
            'total_ads' => intval($total_ads),
            'active_ads' => intval($active_ads),
            'total_views' => $total_views,
            'total_clicks' => $total_clicks,
            'today_views' => $today_views,
            'today_clicks' => $today_clicks,
            'month_views' => $month_views,
            'month_clicks' => $month_clicks,
            'ctr' => $ctr
        );
    }
    
    /**
     * 获取客户端IP地址
     * @return string
     */
    private static function get_client_ip() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) {
        return filter_var($url, FILTER_SANITIZE_URL);
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url) {
        return htmlspecialchars(esc_url_raw($url), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('todo_kses_post')) {
    function todo_kses_post($data) {
        // 简单的HTML过滤，允许基本标签
        $allowed_tags = '<p><br><strong><em><u><a><img><div><span><h1><h2><h3><h4><h5><h6><ul><ol><li>';
        return strip_tags($data, $allowed_tags);
    }
}

if (!function_exists('current_time')) {
    function current_time($type = 'mysql') {
        return date('Y-m-d H:i:s');
    }
}
