<?php
/**
 * 升级套餐管理类
 *
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

class TodoUpgradePackageManager {
    
    /**
     * 数据库实例
     *
     * @var Database
     */
    private $db;
    
    /**
     * 表前缀
     *
     * @var string
     */
    private $prefix;
    
    /**
     * 构造函数
     */
    public function __construct() {
        global $tododb;
        if (!$tododb) {
            // 如果全局变量不存在，尝试创建新的数据库实例
            $this->db = new CMS_DB();
        } else {
            $this->db = $tododb;
        }
        $this->prefix = $this->db->prefix;
    }
    
    /**
     * 获取所有升级套餐
     *
     * @param array $args 查询参数
     * @return array 升级套餐列表
     */
    public function get_upgrade_packages($args = array()) {
        $defaults = array(
            'orderby' => 'sort_order',
            'order' => 'ASC',
            'is_active' => 1
        );
        
        $args = array_merge($defaults, $args);
        $orderby_map = array(
            'package_name' => 'up.package_name',
            'package_type' => 'up.package_type',
            'target_level_name' => 'ul.level_name',
            'price' => 'up.price',
            'sort_order' => 'up.sort_order',
            'is_active' => 'up.is_active'
        );
        $orderby_key = isset($args['orderby']) ? (string) $args['orderby'] : 'sort_order';
        $orderby_sql = isset($orderby_map[$orderby_key]) ? $orderby_map[$orderby_key] : $orderby_map['sort_order'];
        $order_sql = strtoupper((string) ($args['order'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        
        $sql = "SELECT up.*, up.points_reward AS points_amount, ul.level_name as target_level_name, ul.level_color as target_level_color
                FROM {$this->prefix}upgrade_packages up
                LEFT JOIN {$this->prefix}user_levels ul ON up.target_level_id = ul.id";
        
        $where_conditions = array();
        $params = array();
        
        if (isset($args['is_active'])) {
            $where_conditions[] = "up.is_active = ?";
            $params[] = $args['is_active'];
        }
        
        if (isset($args['package_type'])) {
            $where_conditions[] = "up.package_type = ?";
            $params[] = $args['package_type'];
        }
        
        if (isset($args['target_level_id'])) {
            $where_conditions[] = "up.target_level_id = ?";
            $params[] = $args['target_level_id'];
        }
        
        if (!empty($where_conditions)) {
            $sql .= " WHERE " . implode(' AND ', $where_conditions);
        }
        
        $sql .= " ORDER BY {$orderby_sql} {$order_sql}";
        
        return $this->db->get_results($sql, $params);
    }
    
    /**
     * 根据ID获取升级套餐
     *
     * @param int $package_id 套餐ID
     * @return object|null 升级套餐对象
     */
    public function get_upgrade_package($package_id) {
        $sql = "SELECT up.*, up.points_reward AS points_amount, ul.level_name as target_level_name, ul.level_color as target_level_color
                FROM {$this->prefix}upgrade_packages up
                LEFT JOIN {$this->prefix}user_levels ul ON up.target_level_id = ul.id
                WHERE up.id = ?";
        return $this->db->get_row($sql, array($package_id));
    }
    
    /**
     * 根据标识获取升级套餐
     *
     * @param string $package_slug 套餐标识
     * @return object|null 升级套餐对象
     */
    public function get_package_by_slug($package_slug) {
        $sql = "SELECT up.*, ul.level_name as target_level_name, ul.level_color as target_level_color
                FROM {$this->prefix}upgrade_packages up
                LEFT JOIN {$this->prefix}user_levels ul ON up.target_level_id = ul.id
                WHERE up.package_slug = ? AND up.is_active = 1";
        return $this->db->get_row($sql, array($package_slug));
    }
    
    /**
     * 创建升级套餐
     *
     * @param array $data 套餐数据
     * @return int|false 套餐ID或false
     */
    public function create_upgrade_package($data) {
        // 校验必填字段（允许价格为0）
        if (!isset($data['package_name']) || $data['package_name'] === '') {
            return false;
        }
        if (!isset($data['package_slug']) || $data['package_slug'] === '') {
            return false;
        }
        if (!isset($data['package_type']) || $data['package_type'] === '') {
            return false;
        }
        // price 允许 0，需为有效数值
        if (!isset($data['price']) || !is_numeric($data['price'])) {
            return false;
        }
        // 目标等级校验：仅当套餐类型为 level 才必填，其他类型允许为空
        $target_level_id = isset($data['target_level_id']) ? intval($data['target_level_id']) : null;
        if ($data['package_type'] === 'level') {
            if ($target_level_id === null || $target_level_id <= 0) {
                return false;
            }
        } else {
            // 非 level 类型不要求目标等级，写入 NULL
            $data['target_level_id'] = null;
        }
        
        // 检查slug是否唯一
        if ($this->package_slug_exists($data['package_slug'])) {
            return false;
        }
        
        // 规范化与数据表字段的映射
        $points_reward = isset($data['points_amount']) ? intval($data['points_amount']) : 0;
        $features_list = isset($data['package_features']) ? (is_array($data['package_features']) ? $data['package_features'] : array()) : array();
        $features_icon = isset($data['package_icon']) ? $data['package_icon'] : '';
        $features_color = isset($data['package_color']) ? $data['package_color'] : '';
        // 过滤空特性
        $features_list = array_values(array_filter($features_list, function($v){ return is_string($v) && trim($v) !== ''; }));
        $features = json_encode(array(
            'list' => $features_list,
            'icon' => $features_icon,
            'color' => $features_color
        ));
        // original_price 允许空串或<=0：回填为 price，保持与 update 逻辑一致
        $op = isset($data['original_price']) ? $data['original_price'] : '';
        $original_price = ($op === '' || floatval($op) <= 0) ? $data['price'] : $op;
        
        $sql = "INSERT INTO {$this->prefix}upgrade_packages 
               (package_name, package_slug, package_type, target_level_id, price, original_price, points_reward, validity_days, description, features, is_active, is_recommended, sort_order) 
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $params = array(
            $data['package_name'],
            $data['package_slug'],
            $data['package_type'],
            $data['target_level_id'],
            $data['price'],
            $original_price,
            $points_reward,
            $data['validity_days'] ?? 0,
            $data['description'] ?? '',
            $features,
            $data['is_active'] ?? 1,
            $data['is_recommended'] ?? 0,
            $data['sort_order'] ?? 0
        );
        
        // 兼容 Extended_PDO 与 CMS_DB 的执行方式
        $ok = false;
        if (is_object($this->db) && method_exists($this->db, 'pdo_prepare')) {
            $stmt = $this->db->pdo_prepare($sql);
            $ok = $stmt->execute($params);
        } else {
            $ok = ($this->db->query($sql, $params) !== false);
        }
        
        if ($ok) {
            return $this->db->insert_id();
        }
        
        return false;
    }
    
    /**
     * 更新升级套餐
     *
     * @param int $package_id 套餐ID
     * @param array $data 更新数据
     * @return bool 是否成功
     */
    public function update_package($package_id, $data) {
        // 兼容表单字段与数据库字段的命名差异
        if (isset($data['points_amount'])) {
            $data['points_reward'] = intval($data['points_amount']);
            unset($data['points_amount']);
        }
        // 统一将自定义特性、图标与颜色写入 features(JSON)
        if (isset($data['package_features']) || isset($data['package_icon']) || isset($data['package_color'])) {
            $features_list = isset($data['package_features']) ? (is_array($data['package_features']) ? $data['package_features'] : array()) : array();
            // 过滤空白特性
            $features_list = array_values(array_filter($features_list, function($v){ return is_string($v) && trim($v) !== ''; }));
            $features_icon = isset($data['package_icon']) ? $data['package_icon'] : '';
            $features_color = isset($data['package_color']) ? $data['package_color'] : '';
            $data['features'] = json_encode(array(
                'list' => $features_list,
                'icon' => $features_icon,
                'color' => $features_color
            ));
            unset($data['package_features'], $data['package_icon'], $data['package_color']);
        }
        // 移除不存在的字段（防御性）
        unset($data['non_exist_field_example']);
        // original_price 允许空串或<=0：用 price 回填
        if (array_key_exists('original_price', $data)) {
            $op = $data['original_price'];
            if (($op === '' || floatval($op) <= 0) && isset($data['price'])) {
                $data['original_price'] = $data['price'];
            }
        }
        
        $allowed_fields = array('package_name','package_slug','package_type','target_level_id','price','original_price','points_reward','validity_days','description','features','is_active','is_recommended','sort_order');
        
        $update_fields = array();
        $params = array();
        
        foreach ($data as $field => $value) {
            if (in_array($field, $allowed_fields, true)) {
                $update_fields[] = "{$field} = ?";
                $params[] = $value;
            }
        }
        
        if (empty($update_fields)) {
            return false;
        }
        
        // 如果更新slug，检查是否唯一
        if (isset($data['package_slug'])) {
            $existing = $this->db->get_var("SELECT id FROM {$this->prefix}upgrade_packages WHERE package_slug = ? AND id != ?", array($data['package_slug'], $package_id));
            if ($existing) {
                return false;
            }
        }
        
        $params[] = $package_id;
        $sql = "UPDATE {$this->prefix}upgrade_packages SET " . implode(', ', $update_fields) . " WHERE id = ?";
        
        // 兼容 Extended_PDO 与 CMS_DB 的执行方式
        if (is_object($this->db) && method_exists($this->db, 'pdo_prepare')) {
            $stmt = $this->db->pdo_prepare($sql);
            return $stmt->execute($params) !== false;
        } else {
            return $this->db->query($sql, $params) !== false;
        }
    }
    
    /**
     * 删除升级套餐
     *
     * @param int $package_id 套餐ID
     * @return bool 是否成功
     */
    public function delete_package($package_id) {
        // 检查是否有订单使用此套餐
        $orders_count = $this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}upgrade_package_orders WHERE package_id = ?", array($package_id));
        
        if ($orders_count > 0) {
            return false; // 有订单使用此套餐，不能删除
        }
        
        $sql = "DELETE FROM {$this->prefix}upgrade_packages WHERE id = ?";
        // 兼容 Extended_PDO 与 CMS_DB 的执行方式
        $params = array($package_id);
        if (is_object($this->db) && method_exists($this->db, 'pdo_prepare')) {
            $stmt = $this->db->pdo_prepare($sql);
            return $stmt->execute($params) !== false;
        } else {
            return $this->db->query($sql, $params) !== false;
        }
    }
    
    /**
     * 检查套餐标识是否存在
     *
     * @param string $slug 套餐标识
     * @param int $exclude_id 排除的ID
     * @return bool 是否存在
     */
    public function package_slug_exists($slug, $exclude_id = null) {
        // 规范化并快速失败
        $slug = is_string($slug) ? trim($slug) : '';
        if ($slug === '') {
            return false;
        }

        $sql = "SELECT id FROM {$this->prefix}upgrade_packages WHERE package_slug = ?";
        $params = array($slug);
        
        if ($exclude_id) {
            $sql .= " AND id != ?";
            $params[] = $exclude_id;
        }
        
        $val = $this->db->get_var($sql, $params);
        // 注意：get_var 查询失败时返回 false，无结果返回 null。
        // 旧逻辑用 !== null 会把 false（查询失败）当做“存在”，导致误判。
        if ($val === false || $val === null) {
            return false;
        }
        
        return intval($val) > 0;
    }
    
    /**
     * 创建套餐订单
     *
     * @param int $user_id 用户ID
     * @param int $package_id 套餐ID
     * @param array $order_data 订单数据
     * @return string|false 订单号或false
     */
    public function create_package_order($user_id, $package_id, $order_data = array()) {
        $package = $this->get_upgrade_package($package_id);
        if (!$package) {
            return false;
        }
        
        // 生成订单号（使用统一生成器，保证唯一性与长度）
        $order_no = todo_generate_order_no('PKG');
        
        $defaults = array(
            'payment_method' => null,
            'payment_status' => 'pending',
            'order_status' => 'pending',
            'notes' => ''
        );
        
        $order_data = array_merge($defaults, $order_data);
        
        $sql = "INSERT INTO {$this->prefix}upgrade_package_orders 
               (order_no, user_id, package_id, package_name, price, payment_method, payment_status, order_status, notes) 
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $params = array(
            $order_no,
            $user_id,
            $package_id,
            $package->package_name,
            $package->price,
            $order_data['payment_method'],
            $order_data['payment_status'],
            $order_data['order_status'],
            $order_data['notes']
        );
        
        // 兼容 Extended_PDO 与 CMS_DB 的执行方式
        $ok = false;
        if (is_object($this->db) && method_exists($this->db, 'pdo_prepare')) {
            $stmt = $this->db->pdo_prepare($sql);
            $ok = $stmt->execute($params);
        } else {
            $ok = ($this->db->query($sql, $params) !== false);
        }
        
        if ($ok) {
            return $order_no;
        }
        
        return false;
    }
    
    /**
     * 更新订单状态
     *
     * @param string $order_no 订单号
     * @param string $status 状态
     * @param string $type 状态类型 (payment|order)
     * @return bool 是否成功
     */
    public function update_order_status($order_no, $status, $type = 'order') {
        $field = $type === 'payment' ? 'payment_status' : 'order_status';
        $time_field = null;
        
        if ($type === 'payment' && $status === 'paid') {
            $time_field = 'paid_at';
        } elseif ($type === 'order' && $status === 'completed') {
            $time_field = 'completed_at';
        }
        
        $sql = "UPDATE {$this->prefix}upgrade_package_orders SET {$field} = ?";
        $params = array($status);
        
        if ($time_field) {
            $sql .= ", {$time_field} = NOW()";
        }
        
        $sql .= " WHERE order_no = ?";
        $params[] = $order_no;
        
        // 兼容 Extended_PDO 与 CMS_DB 的执行方式
        if (is_object($this->db) && method_exists($this->db, 'pdo_prepare')) {
            $stmt = $this->db->pdo_prepare($sql);
            return $stmt->execute($params) !== false;
        } else {
            return $this->db->query($sql, $params) !== false;
        }
    }
    
    /**
     * 通过订单号获取订单信息
     *
     * @param string $order_no 订单号
     * @return array|null 订单信息
     */
    public function get_order_by_trade_no($order_no) {
        $sql = "SELECT * FROM {$this->prefix}upgrade_package_orders WHERE order_no = ?";
        $result = $this->db->get_row($sql, array($order_no));
        return $result ? (array)$result : null;
    }
    
    /**
     * 处理套餐购买完成
     *
     * @param string $order_no 订单号
     * @return bool 是否成功
     */
    public function process_package_purchase($order_no) {
        try {
            $this->db->begin_transaction();
            
            // 获取订单信息
            $order = $this->db->get_row("SELECT * FROM {$this->prefix}upgrade_package_orders WHERE order_no = ?", array($order_no));
            if (!$order || $order->order_status === 'completed') {
                $this->db->rollback();
                return false;
            }
            
            // 获取套餐信息
            $package = $this->get_upgrade_package($order->package_id);
            if (!$package) {
                $this->db->rollback();
                return false;
            }
            
            // 获取用户等级管理器
            $level_manager = todo_get_user_level_manager();
            
            // 根据套餐类型处理
            switch ($package->package_type) {
                case 'points':
                    // 积分套餐：增加积分
                    $level_manager->update_user_points(
                        $order->user_id,
                        $package->points_amount,
                        'package_purchase',
                        "购买套餐：{$package->package_name}",
                        $order->id
                    );
                    break;
                    
                case 'direct':
                    // 直升套餐：直接升级到目标等级
                    $expires_at = null;
                    if ($package->validity_days > 0) {
                        $expires_at = date('Y-m-d H:i:s', strtotime("+{$package->validity_days} days"));
                    }
                    
                    $level_manager->update_user_level(
                        $order->user_id,
                        $package->target_level_id,
                        $package->points_amount,
                        'package',
                        $package->id,
                        $expires_at
                    );
                    break;
                    
                case 'time':
                    // 时长套餐：延长当前等级有效期
                    $current_level = $level_manager->get_user_current_level($order->user_id);
                    if ($current_level) {
                        $current_expires = $current_level->expires_at ? strtotime($current_level->expires_at) : time();
                        $new_expires = date('Y-m-d H:i:s', $current_expires + ($package->validity_days * 24 * 60 * 60));
                        
                        $level_manager->update_user_level(
                            $order->user_id,
                            $current_level->level_id,
                            $current_level->points,
                            'package',
                            $package->id,
                            $new_expires
                        );
                    }
                    break;
            }
            
            // 更新订单状态为已完成
            $this->update_order_status($order_no, 'completed', 'order');
            
            $this->db->commit();
            return true;
            
        } catch (Exception $e) {
            $this->db->rollback();
            return false;
        }
    }
    
    /**
     * 获取用户订单列表
     *
     * @param int $user_id 用户ID
     * @param array $args 查询参数
     * @return array 订单列表
     */
    public function get_user_orders($user_id, $args = array()) {
        $defaults = array(
            'limit' => 20,
            'offset' => 0,
            'orderby' => 'created_at',
            'order' => 'DESC'
        );
        
        $args = array_merge($defaults, $args);
        
        $sql = "SELECT po.*, up.package_name, up.package_type, ul.level_name as target_level_name
                FROM {$this->prefix}upgrade_package_orders po
                LEFT JOIN {$this->prefix}upgrade_packages up ON po.package_id = up.id
                LEFT JOIN {$this->prefix}user_levels ul ON up.target_level_id = ul.id
                WHERE po.user_id = ?
                ORDER BY po.{$args['orderby']} {$args['order']}";
        
        if ($args['limit'] > 0) {
            $sql .= " LIMIT {$args['limit']}";
            if ($args['offset'] > 0) {
                $sql .= " OFFSET {$args['offset']}";
            }
        }
        
        return $this->db->get_results($sql, array($user_id));
    }
    
    /**
     * 获取订单详情
     *
     * @param string $order_no 订单号
     * @return object|null 订单对象
     */
    public function get_order($order_no) {
        $sql = "SELECT po.*, up.package_name, up.package_type, up.package_features, ul.level_name as target_level_name
                FROM {$this->prefix}upgrade_package_orders po
                LEFT JOIN {$this->prefix}upgrade_packages up ON po.package_id = up.id
                LEFT JOIN {$this->prefix}user_levels ul ON up.target_level_id = ul.id
                WHERE po.order_no = ?";
        return $this->db->get_row($sql, array($order_no));
    }
    
    /**
     * 获取推荐套餐
     *
     * @param int $limit 限制数量
     * @return array 推荐套餐列表
     */
    public function get_recommended_packages($limit = 3) {
        $sql = "SELECT up.*, ul.level_name as target_level_name, ul.level_color as target_level_color
                FROM {$this->prefix}upgrade_packages up
                LEFT JOIN {$this->prefix}user_levels ul ON up.target_level_id = ul.id
                WHERE up.is_active = 1 AND up.is_recommended = 1
                ORDER BY up.sort_order ASC
                LIMIT ?";
        return $this->db->get_results($sql, array($limit));
    }
    
    /**
     * 计算套餐折扣
     *
     * @param object $package 套餐对象
     * @return array 折扣信息
     */
    public function calculate_package_discount($package) {
        $discount_amount = $package->original_price - $package->price;
        $discount_rate = $package->original_price > 0 ? ($discount_amount / $package->original_price) * 100 : 0;
        
        return array(
            'discount_amount' => $discount_amount,
            'discount_rate' => round($discount_rate, 1),
            'has_discount' => $discount_amount > 0
        );
    }
}

// 全局实例
if (!isset($todo_upgrade_package_manager)) {
    $todo_upgrade_package_manager = new TodoUpgradePackageManager();
}

/**
 * 获取升级套餐管理器实例
 *
 * @return TodoUpgradePackageManager
 */
function todo_get_upgrade_package_manager() {
    global $todo_upgrade_package_manager;
    if (!is_object($todo_upgrade_package_manager)) {
        $todo_upgrade_package_manager = new TodoUpgradePackageManager();
    }
    return $todo_upgrade_package_manager;
}

/**
 * 获取升级套餐列表
 *
 * @param array $args 查询参数
 * @return array
 */
function todo_get_upgrade_packages($args = array()) {
    return todo_get_upgrade_package_manager()->get_upgrade_packages($args);
}

/**
 * 获取升级套餐
 *
 * @param int $package_id 套餐ID
 * @return object|null
 */
function todo_get_upgrade_package($package_id) {
    return todo_get_upgrade_package_manager()->get_upgrade_package($package_id);
}

/**
 * 创建套餐订单
 *
 * @param int $user_id 用户ID
 * @param int $package_id 套餐ID
 * @param array $order_data 订单数据
 * @return string|false
 */
function todo_create_package_order($user_id, $package_id, $order_data = array()) {
    return todo_get_upgrade_package_manager()->create_package_order($user_id, $package_id, $order_data);
}

/**
 * 处理套餐购买完成
 *
 * @param string $order_no 订单号
 * @return bool
 */
function todo_process_package_purchase($order_no) {
    return todo_get_upgrade_package_manager()->process_package_purchase($order_no);
}
