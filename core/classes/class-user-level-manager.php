<?php
/**
 * 用户等级管理类
 *
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

class TodoUserLevelManager {
    
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
        $this->db = $tododb;
        if (!$this->db || !isset($this->db->prefix)) {
            todo_die('数据库未初始化，请检查数据库连接。', '系统错误', array('response' => 500));
        }
        $this->prefix = $this->db->prefix;
    }
    
    /**
     * 获取所有用户等级
     *
     * @param array $args 查询参数
     * @return array 用户等级列表
     */
    public function get_user_levels($args = array()) {
        $defaults = array(
            'orderby' => 'level_order',
            'order' => 'ASC',
            'search' => '',
            'number' => -1,
            'offset' => 0,
            'count' => false
        );
        
        $args = array_merge($defaults, $args);

        $select_sql = $args['count'] ? "SELECT COUNT(*)" : "SELECT *";
        $sql = "{$select_sql} FROM {$this->prefix}user_levels";
        
        $where_conditions = array();
        $params = array();
        
        if (isset($args['is_active'])) {
            $where_conditions[] = "is_active = ?";
            $params[] = $args['is_active'];
        }

        if (!empty($args['search'])) {
            $where_conditions[] = "(level_name LIKE ? OR level_slug LIKE ?)";
            $search_term = '%' . $this->db->esc_like($args['search']) . '%';
            $params[] = $search_term;
            $params[] = $search_term;
        }
        
        if (!empty($where_conditions)) {
            $sql .= " WHERE " . implode(' AND ', $where_conditions);
        }

        if ($args['count']) {
            return (int) $this->db->get_var($sql, $params);
        }
        
        $sql .= " ORDER BY {$args['orderby']} {$args['order']}";

        if ($args['number'] > 0) {
            $sql .= " LIMIT " . (int)$args['offset'] . ", " . (int)$args['number'];
        }
        
        return $this->db->get_results($sql, $params);
    }

    /**
     * 获取指定等级的用户数量
     *
     * @param int $level_id 等级ID
     * @return int 用户数量
     */
    public function get_level_user_count($level_id) {
        $sql = "SELECT COUNT(*) FROM {$this->prefix}user_level_records WHERE level_id = ?";
        $count = $this->db->get_var($sql, array($level_id));
        return intval($count);
    }

    /**
     * 根据ID获取用户等级
     *
     * @param int $level_id 等级ID
     * @return object|null 用户等级对象
     */
    public function get_user_level($level_id) {
        $sql = "SELECT * FROM {$this->prefix}user_levels WHERE id = ?";
        return $this->db->get_row($sql, array($level_id));
    }

    /**
     * 根据标识获取用户等级
     *
     * @param string $slug 等级标识（level_slug）
     * @return object|null 用户等级对象
     */
    public function get_level_by_slug($slug) {
        $sql = "SELECT * FROM {$this->prefix}user_levels WHERE level_slug = ? AND is_active = 1";
        return $this->db->get_row($sql, array($slug));
    }
    
    /**
     * 根据积分获取对应等级
     *
     * @param int $points 积分数
     * @return object|null 用户等级对象
     */
    public function get_level_by_points($points) {
        $sql = "SELECT * FROM {$this->prefix}user_levels 
                WHERE is_active = 1 
                AND min_points <= ? 
                AND (max_points >= ? OR max_points = 0)
                ORDER BY level_order DESC 
                LIMIT 1";
        return $this->db->get_row($sql, array($points, $points));
    }
    
    /**
     * 获取用户对应的批发/会员价格层级
     * 自动从 user_levels 表读取 active 状态等级并按 level_order 划分：
     * - 最高等级层级：wholesale_vip (高级/VIP2+)
     * - 中级等级层级：wholesale (普通批发/VIP1)
     * - 基础等级层级：normal (普通注册用户)
     *
     * @param int $user_id 用户ID
     * @return string 'wholesale_vip' | 'wholesale' | 'normal'
     */
    public function get_user_price_tier($user_id) {
        $user_id = intval($user_id);
        if ($user_id <= 0) {
            return 'normal';
        }

        $user_lvl = $this->get_user_current_level($user_id);
        if (!$user_lvl) {
            return 'normal';
        }

        // 全量读取当前启用的所有等级并按 level_order 升序排列
        $active_levels = $this->get_user_levels(array(
            'is_active' => 1,
            'orderby' => 'level_order',
            'order' => 'ASC'
        ));

        if (empty($active_levels) || count($active_levels) <= 1) {
            return 'normal';
        }

        $current_level_id = isset($user_lvl->level_id) ? intval($user_lvl->level_id) : (isset($user_lvl->id) ? intval($user_lvl->id) : 0);
        $total = count($active_levels);

        // 查找当前用户的 level 在已启用等级中的 index 位置 (0 表示最低级/注册用户)
        $user_index = -1;
        foreach ($active_levels as $idx => $lvl) {
            if (intval($lvl->id) === $current_level_id) {
                $user_index = $idx;
                break;
            }
        }

        if ($user_index <= 0) {
            return 'normal';
        }

        // 若总等级数大于2（如：普通用户, 批发会员, 高级批发会员）：
        // - 最高等级 (user_index == total - 1) 对应 wholesale_vip
        // - 中间等级 (0 < user_index < total - 1) 对应 wholesale
        // 若总等级数只有2个（如：普通用户, 批发会员）：
        // - 只要大于最低级即享有 wholesale 价格
        if ($total >= 3 && $user_index >= $total - 1) {
            return 'wholesale_vip';
        }

        return 'wholesale';
    }

    /**
     * 获取用户当前等级
     *
     * @param int $user_id 用户ID
     * @return object|null 用户等级记录
     */
    public function get_user_current_level($user_id) {
        $sql = "SELECT ulr.*, ul.level_name, ul.level_slug, ul.discount_rate, ul.privileges, ul.level_color, ul.level_icon
                FROM {$this->prefix}user_level_records ulr
                LEFT JOIN {$this->prefix}user_levels ul ON ulr.level_id = ul.id
                WHERE ulr.user_id = ?";
        return $this->db->get_row($sql, array($user_id));
    }
    
    /**
     * 获取用户积分
     *
     * @param int $user_id 用户ID
     * @return int 用户积分
     */
    public function get_user_points($user_id) {
        $record = $this->get_user_current_level($user_id);
        return $record ? $record->points : 0;
    }
    
    /**
     * 更新用户积分
     *
     * @param int $user_id 用户ID
     * @param int $points_change 积分变化
     * @param string $action_type 操作类型
     * @param string $action_desc 操作描述
     * @param int $related_id 关联ID
     * @param int $admin_id 管理员ID
     * @return bool 是否成功
     */
    public function update_user_points($user_id, $points_change, $action_type, $action_desc = '', $related_id = null, $admin_id = null) {
        try {
            $this->db->begin_transaction();
            
            // 获取当前积分
            $current_points = $this->get_user_points($user_id);
            $new_points = max(0, $current_points + $points_change); // 积分不能为负数
            
            // 记录积分变化日志
            $log_sql = "INSERT INTO {$this->prefix}user_points_log 
                       (user_id, points_change, points_before, points_after, action_type, action_desc, related_id, admin_id) 
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            // 使用安全的预处理或抽象层插入，避免 PDO::query 第二参数误用
            if (is_object($this->db) && method_exists($this->db, 'insert')) {
                $this->db->insert(
                    $this->prefix . 'user_points_log',
                    array(
                        'user_id'       => $user_id,
                        'points_change' => $points_change,
                        'points_before' => $current_points,
                        'points_after'  => $new_points,
                        'action_type'   => $action_type,
                        'action_desc'   => $action_desc,
                        'related_id'    => $related_id,
                        'admin_id'      => $admin_id,
                    )
                );
            } else if (is_object($this->db) && method_exists($this->db, 'pdo_prepare')) {
                $stmt = $this->db->pdo_prepare($log_sql);
                $stmt->execute(array($user_id, $points_change, $current_points, $new_points, $action_type, $action_desc, $related_id, $admin_id));
            } else {
                $this->db->query($log_sql, array($user_id, $points_change, $current_points, $new_points, $action_type, $action_desc, $related_id, $admin_id));
            }
            
            // 检查是否需要升级等级
            $new_level = $this->get_level_by_points($new_points);
            if ($new_level) {
                $this->update_user_level($user_id, $new_level->id, $new_points, 'auto');
            }
            
            $this->db->commit();
            return true;
            
        } catch (Exception $e) {
            // 安全回滚：兼容不同数据库抽象层
            if (is_object($this->db) && method_exists($this->db, 'rollback_transaction')) {
                $this->db->rollback_transaction();
            } else if (is_object($this->db) && method_exists($this->db, 'rollback')) {
                $this->db->rollback();
            } else if (is_object($this->db) && method_exists($this->db, 'rollBack')) {
                $this->db->rollBack();
            }
            return false;
        }
    }
    
    /**
     * 更新用户等级
     *
     * @param int $user_id 用户ID
     * @param int $level_id 等级ID
     * @param int $points 积分
     * @param string $upgrade_type 升级方式
     * @param int $package_id 套餐ID
     * @param string $expires_at 过期时间
     * @return bool 是否成功
     */
    public function update_user_level($user_id, $level_id, $points = null, $upgrade_type = 'auto', $package_id = null, $expires_at = null) {
        // 检查用户是否已有等级记录
        $existing = $this->get_user_current_level($user_id);
        
        if ($points === null) {
            $points = $existing ? $existing->points : 0;
        }
        
        if ($existing) {
            // 更新现有记录
            $sql = "UPDATE {$this->prefix}user_level_records 
                   SET level_id = ?, points = ?, upgrade_type = ?, package_id = ?, expires_at = ?, updated_at = NOW()
                   WHERE user_id = ?";
            $params = array($level_id, $points, $upgrade_type, $package_id, $expires_at, $user_id);
        } else {
            // 创建新记录
            $sql = "INSERT INTO {$this->prefix}user_level_records 
                   (user_id, level_id, points, upgrade_type, package_id, expires_at) 
                   VALUES (?, ?, ?, ?, ?, ?)";
            $params = array($user_id, $level_id, $points, $upgrade_type, $package_id, $expires_at);
        }
        
        // 优先使用 Extended_PDO 的预处理，避免 PDO::query 第二参数误用触发 "mode must be an integer"
        if (is_object($this->db) && method_exists($this->db, 'pdo_prepare')) {
            $stmt = $this->db->pdo_prepare($sql);
            return $stmt->execute($params) !== false;
        }
        
        // 兼容旧的 CMS_DB 连接
        return $this->db->query($sql, $params) !== false;
    }
    
    /**
     * 创建用户等级
     *
     * @param array $data 等级数据
     * @return int|false 等级ID或false
     */
    public function create_user_level($data) {
        // 兼容页面传入的不同命名字段
        if (isset($data['sort_order']) && !isset($data['level_order'])) {
            $data['level_order'] = (int)$data['sort_order'];
        }
        if (isset($data['level_benefits']) && !isset($data['description'])) {
            $data['description'] = $data['level_benefits'];
        }
        // max_points 未提供时，默认0表示无上限
        if (!isset($data['max_points']) || $data['max_points'] === '' || $data['max_points'] === null) {
            $data['max_points'] = 0;
        }

        $required_fields = array('level_name', 'level_slug', 'min_points');
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                return false;
            }
        }
        
        // 检查slug是否唯一
        if ($this->level_slug_exists($data['level_slug'])) {
            return false;
        }
        
        $defaults = array(
            'level_order' => 0,
            'discount_rate' => 0.00,
            'privileges' => '{}',
            'level_icon' => null,
            'level_color' => null,
            'description' => '',
            'is_active' => 1
        );
        
        $data = array_merge($defaults, $data);
        
        $sql = "INSERT INTO {$this->prefix}user_levels 
               (level_name, level_slug, level_order, min_points, max_points, discount_rate, privileges, level_icon, level_color, description, is_active) 
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $params = array(
            $data['level_name'],
            $data['level_slug'],
            $data['level_order'],
            $data['min_points'],
            $data['max_points'],
            $data['discount_rate'],
            $data['privileges'],
            $data['level_icon'],
            $data['level_color'],
            $data['description'],
            $data['is_active']
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
     * 更新用户等级
     *
     * @param int $level_id 等级ID
     * @param array $data 更新数据
     * @return bool 是否成功
     */
    public function update_level($level_id, $data) {
        $allowed_fields = array('level_name', 'level_slug', 'level_order', 'min_points', 'max_points', 'discount_rate', 'privileges', 'level_icon', 'level_color', 'description', 'is_active');
        
        $update_fields = array();
        $params = array();
        
        foreach ($data as $field => $value) {
            if (in_array($field, $allowed_fields)) {
                $update_fields[] = "{$field} = ?";
                $params[] = $value;
            }
        }
        
        if (empty($update_fields)) {
            return false;
        }
        
        // 如果更新slug，检查是否唯一
        if (isset($data['level_slug'])) {
            $existing = $this->db->get_var("SELECT id FROM {$this->prefix}user_levels WHERE level_slug = ? AND id != ?", array($data['level_slug'], $level_id));
            if ($existing) {
                return false;
            }
        }
        
        $params[] = $level_id;
        $sql = "UPDATE {$this->prefix}user_levels SET " . implode(', ', $update_fields) . " WHERE id = ?";
        
        // 兼容 Extended_PDO 与 CMS_DB 的执行方式
        if (is_object($this->db) && method_exists($this->db, 'pdo_prepare')) {
            $stmt = $this->db->pdo_prepare($sql);
            return $stmt->execute($params) !== false;
        } else {
            return $this->db->query($sql, $params) !== false;
        }
    }
    
    /**
     * 删除用户等级
     *
     * @param int $level_id 等级ID
     * @return bool 是否成功
     */
    public function delete_level($level_id) {
        // 检查是否有用户使用此等级
        $users_count = $this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}user_level_records WHERE level_id = ?", array($level_id));
        
        if ($users_count > 0) {
            return false; // 有用户使用此等级，不能删除
        }
        
        $sql = "DELETE FROM {$this->prefix}user_levels WHERE id = ?";
        // 兼容 Extended_PDO 与 CMS_DB 的执行方式
        $params = array($level_id);
        if (is_object($this->db) && method_exists($this->db, 'pdo_prepare')) {
            $stmt = $this->db->pdo_prepare($sql);
            return $stmt->execute($params) !== false;
        } else {
            return $this->db->query($sql, $params) !== false;
        }
    }
    
    /**
     * 检查等级标识是否存在
     *
     * @param string $slug 等级标识
     * @param int $exclude_id 排除的ID
     * @return bool 是否存在
     */
    public function level_slug_exists($slug, $exclude_id = null) {
        $sql = "SELECT id FROM {$this->prefix}user_levels WHERE level_slug = ?";
        $params = array($slug);

        if ($exclude_id) {
            $sql .= " AND id != ?";
            $params[] = $exclude_id;
        }

        $val = $this->db->get_var($sql, $params);
        // 注意：CMS_DB::get_var 在无结果时返回 false，而非 null，为避免误判，这里同时判断 !== false 和 !== null
        return $val !== false && $val !== null;
    }
    
    /**
     * 获取用户积分记录
     *
     * @param int $user_id 用户ID
     * @param array $args 查询参数
     * @return array 积分记录列表
     */
    public function get_user_points_log($user_id, $args = array()) {
        $defaults = array(
            'limit' => 20,
            'offset' => 0,
            'orderby' => 'created_at',
            'order' => 'DESC'
        );
        
        $args = array_merge($defaults, $args);
        
        $sql = "SELECT * FROM {$this->prefix}user_points_log 
               WHERE user_id = ? 
               ORDER BY {$args['orderby']} {$args['order']}";
        
        if ($args['limit'] > 0) {
            $sql .= " LIMIT {$args['limit']}";
            if ($args['offset'] > 0) {
                $sql .= " OFFSET {$args['offset']}";
            }
        }
        
        return $this->db->get_results($sql, array($user_id));
    }
    
    /**
     * 计算用户折扣率
     *
     * @param int $user_id 用户ID
     * @return float 折扣率(0-100)
     */
    public function get_user_discount_rate($user_id) {
        $level = $this->get_user_current_level($user_id);
        return $level ? floatval($level->discount_rate) : 0.00;
    }
    
    /**
     * 检查用户是否有特定权限
     *
     * @param int $user_id 用户ID
     * @param string $privilege 权限名称
     * @return bool 是否有权限
     */
    public function user_has_privilege($user_id, $privilege) {
        $level = $this->get_user_current_level($user_id);
        
        if (!$level || empty($level->privileges)) {
            return false;
        }
        
        $privileges = json_decode($level->privileges, true);
        return isset($privileges[$privilege]) && $privileges[$privilege];
    }
    
    /**
     * 初始化新用户等级
     *
     * @param int $user_id 用户ID
     * @return bool 是否成功
     */
    public function init_user_level($user_id) {
        // 获取最低等级
        $lowest_level = $this->db->get_row("SELECT * FROM {$this->prefix}user_levels WHERE is_active = 1 ORDER BY level_order ASC LIMIT 1");
        
        if ($lowest_level) {
            return $this->update_user_level($user_id, $lowest_level->id, 0, 'auto');
        }
        
        return false;
    }
}

/**
 * 获取用户等级管理器实例
 *
 * @return TodoUserLevelManager
 */
function todo_get_user_level_manager() {
    global $todo_user_level_manager;
    
    if (!isset($todo_user_level_manager)) {
        $todo_user_level_manager = new TodoUserLevelManager();
    }
    
    return $todo_user_level_manager;
}

/**
 * 获取用户积分
 *
 * @param int $user_id 用户ID
 * @return int
 */
function todo_get_user_points($user_id) {
    return todo_get_user_level_manager()->get_user_points($user_id);
}

/**
 * 更新用户积分
 *
 * @param int $user_id 用户ID
 * @param int $points_change 积分变化
 * @param string $action_type 操作类型
 * @param string $action_desc 操作描述
 * @param int $related_id 关联ID
 * @return bool
 */
function todo_update_user_points($user_id, $points_change, $action_type, $action_desc = '', $related_id = null) {
    return todo_get_user_level_manager()->update_user_points($user_id, $points_change, $action_type, $action_desc, $related_id);
}

/**
 * 获取用户折扣率
 *
 * @param int $user_id 用户ID
 * @return float
 */
function todo_get_user_discount_rate($user_id) {
    return todo_get_user_level_manager()->get_user_discount_rate($user_id);
}

/**
 * 检查用户权限
 *
 * @param int $user_id 用户ID
 * @param string $privilege 权限名称
 * @return bool
 */
function todo_user_has_privilege($user_id, $privilege) {
    return todo_get_user_level_manager()->user_has_privilege($user_id, $privilege);
}