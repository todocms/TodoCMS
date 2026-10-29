<?php
/**
 * TodoCMS 支付方式管理函数
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    exit('Direct access not permitted');
}

/**
 * 获取所有自定义支付方式
 * 
 * @return array 支付方式列表
 */
function todo_get_custom_payment_methods() {
    global $tododb;
    
    $sql = "SELECT * FROM {$tododb->prefix}payment_method_configs ORDER BY sort_order ASC, id ASC";
    $results = $tododb->get_results($sql);
    
    $methods = array();
    if ($results) {
        foreach ($results as $row) {
            $methods[] = array(
                'id' => $row->id,
                'method_id' => $row->method_id,
                'name' => $row->name,
                'description' => $row->description,
                'icon' => $row->icon,
                'status' => $row->enabled,
                'sort_order' => $row->sort_order,
                'min_amount' => $row->min_amount,
                'max_amount' => $row->max_amount,
                'settings' => $row->settings ? json_decode($row->settings, true) : array(),
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at
            );
        }
    }
    
    return $methods;
}

/**
 * 获取单个支付方式信息
 * 
 * @param int $id 支付方式ID
 * @return array|null 支付方式信息
 */
function todo_get_payment_method($id) {
    global $tododb;
    
    $id = intval($id);
    if ($id <= 0) {
        return null;
    }
    
    $row = $tododb->get_row(
        "SELECT * FROM {$tododb->prefix}payment_method_configs WHERE id = ?",
        [$id]
    );
    
    if (!$row) {
        return null;
    }
    
    return array(
        'id' => $row->id,
        'method_id' => $row->method_id,
        'name' => $row->name,
        'description' => $row->description,
        'icon' => $row->icon,
        'status' => $row->enabled,
        'sort_order' => $row->sort_order,
        'min_amount' => $row->min_amount,
        'max_amount' => $row->max_amount,
        'settings' => $row->settings ? json_decode($row->settings, true) : array(),
        'created_at' => $row->created_at,
        'updated_at' => $row->updated_at
    );
}

/**
 * 添加支付方式
 * 
 * @param string $name 支付方式名称
 * @param string $code 支付方式代码
 * @param string $description 描述
 * @param int $status 状态 (1=启用, 0=禁用)
 * @param string $icon 图标
 * @param array $settings 配置信息
 * @return bool 是否成功
 */
function todo_add_payment_method($name, $code, $description = '', $status = 1, $icon = '', $settings = array()) {
    global $tododb;
    
    // 验证参数
    $name = trim($name);
    $code = trim($code);
    $description = trim($description);
    $status = intval($status);
    $icon = trim($icon);
    
    if (empty($name) || empty($code)) {
        return false;
    }
    
    // 检查代码是否已存在
    $existing = $tododb->get_var(
        "SELECT COUNT(*) FROM {$tododb->prefix}payment_method_configs WHERE method_id = ?",
        [$code]
    );
    
    if ($existing > 0) {
        return false;
    }
    
    // 获取下一个排序号
    $sort_order = $tododb->get_var("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM {$tododb->prefix}payment_method_configs");
    
    // 插入数据
    $result = $tododb->insert(
        $tododb->prefix . 'payment_method_configs',
        array(
            'method_id' => $code,
            'name' => $name,
            'description' => $description,
            'icon' => $icon,
            'enabled' => $status,
            'sort_order' => $sort_order,
            'settings' => !empty($settings) ? json_encode($settings) : null
        ),
        array('%s', '%s', '%s', '%s', '%d', '%d', '%s')
    );
    
    return $result !== false;
}

/**
 * 更新支付方式
 * 
 * @param int $id 支付方式ID
 * @param string $name 支付方式名称
 * @param string $code 支付方式代码
 * @param string $description 描述
 * @param int $status 状态
 * @param string $icon 图标
 * @param array $settings 配置信息
 * @return bool 是否成功
 */
function todo_update_payment_method($id, $name, $code, $description = '', $status = 1, $icon = '', $settings = array()) {
    global $tododb;
    
    $id = intval($id);
    if ($id <= 0) {
        return false;
    }
    
    // 验证参数
    $name = trim($name);
    $code = trim($code);
    $description = trim($description);
    $status = intval($status);
    $icon = trim($icon);
    
    if (empty($name) || empty($code)) {
        return false;
    }
    
    // 检查代码是否已被其他记录使用
    $existing = $tododb->get_var(
        "SELECT COUNT(*) FROM {$tododb->prefix}payment_method_configs WHERE method_id = ? AND id != ?",
        [$code, $id]
    );
    
    if ($existing > 0) {
        return false;
    }
    
    // 更新数据
    $result = $tododb->update(
        $tododb->prefix . 'payment_method_configs',
        array(
            'method_id' => $code,
            'name' => $name,
            'description' => $description,
            'icon' => $icon,
            'enabled' => $status,
            'settings' => !empty($settings) ? json_encode($settings) : null
        ),
        array('id' => $id),
        array('%s', '%s', '%s', '%s', '%d', '%s'),
        array('%d')
    );
    
    return $result !== false;
}

/**
 * 删除支付方式
 * 
 * @param int $id 支付方式ID
 * @return bool 是否成功
 */
function todo_delete_payment_method($id) {
    global $tododb;
    
    $id = intval($id);
    if ($id <= 0) {
        return false;
    }
    
    // 检查是否有相关的支付记录
    $payment_count = $tododb->get_var(
        "SELECT COUNT(*) FROM {$tododb->prefix}payments p 
         INNER JOIN {$tododb->prefix}payment_method_configs pmc ON p.method_id = pmc.method_id 
         WHERE pmc.id = ?",
        [$id]
    );
    
    if ($payment_count > 0) {
        // 如果有相关支付记录，只禁用而不删除
        return $tododb->update(
            $tododb->prefix . 'payment_method_configs',
            array('enabled' => 0),
            array('id' => $id),
            array('%d'),
            array('%d')
        ) !== false;
    }
    
    // 删除记录
    $result = $tododb->delete(
        $tododb->prefix . 'payment_method_configs',
        array('id' => $id),
        array('%d')
    );
    
    return $result !== false;
}

/**
 * 更新支付方式排序
 * 
 * @param array $order_data 排序数据 [id => sort_order]
 * @return bool 是否成功
 */
function todo_update_payment_method_order($order_data) {
    global $tododb;
    
    if (empty($order_data) || !is_array($order_data)) {
        return false;
    }
    
    $tododb->query('START TRANSACTION');
    
    try {
        foreach ($order_data as $id => $sort_order) {
            $id = intval($id);
            $sort_order = intval($sort_order);
            
            if ($id <= 0) {
                continue;
            }
            
            $result = $tododb->update(
                $tododb->prefix . 'payment_method_configs',
                array('sort_order' => $sort_order),
                array('id' => $id),
                array('%d'),
                array('%d')
            );
            
            if ($result === false) {
                throw new Exception('更新排序失败');
            }
        }
        
        $tododb->query('COMMIT');
        return true;
        
    } catch (Exception $e) {
        $tododb->query('ROLLBACK');
        return false;
    }
}

/**
 * 获取启用的支付方式列表
 * 
 * @return array 启用的支付方式
 */
function todo_get_enabled_payment_methods() {
    global $tododb;
    
    $sql = "SELECT * FROM {$tododb->prefix}payment_method_configs WHERE enabled = 1 ORDER BY sort_order ASC, id ASC";
    $results = $tododb->get_results($sql);
    
    $methods = array();
    if ($results) {
        foreach ($results as $row) {
            $methods[$row->method_id] = array(
                'id' => $row->id,
                'method_id' => $row->method_id,
                'name' => $row->name,
                'description' => $row->description,
                'icon' => $row->icon,
                'min_amount' => $row->min_amount,
                'max_amount' => $row->max_amount,
                'settings' => $row->settings ? json_decode($row->settings, true) : array()
            );
        }
    }
    
    return $methods;
}

/**
 * 检查支付方式是否可用
 * 
 * @param string $method_id 支付方式ID
 * @param float $amount 支付金额
 * @return bool 是否可用
 */
function todo_is_payment_method_available($method_id, $amount = 0) {
    global $tododb;
    
    $row = $tododb->get_row(
        "SELECT enabled, min_amount, max_amount FROM {$tododb->prefix}payment_method_configs WHERE method_id = ?",
        [$method_id]
    );
    
    if (!$row || !$row->enabled) {
        return false;
    }
    
    // 检查金额限制
    if ($amount > 0) {
        if ($row->min_amount && $amount < $row->min_amount) {
            return false;
        }
        if ($row->max_amount && $amount > $row->max_amount) {
            return false;
        }
    }
    
    return true;
}

/**
 * 获取所有支付方式（包括系统预设和自定义）
 * 
 * @return array 支付方式列表
 */
function todo_get_payment_methods() {
    global $tododb;
    
    $sql = "SELECT * FROM {$tododb->prefix}payment_methods WHERE status = 1 ORDER BY sort_order ASC, id ASC";
    $results = $tododb->get_results($sql);
    
    $methods = array();
    if ($results) {
        foreach ($results as $row) {
            // 解析配置JSON
            $config = $row->config ? json_decode($row->config, true) : array();
            
            $methods[] = array(
                'id' => $row->id,
                'code' => $row->code,
                'method_id' => $row->code, // 为了兼容性
                'name' => $row->name,
                'description' => isset($config['description']) ? $config['description'] : '',
                'enabled' => (bool)$row->status,
                'status' => $row->status,
                'sort_order' => $row->sort_order,
                'min_amount' => isset($config['min_amount']) ? $config['min_amount'] : 0,
                'max_amount' => isset($config['max_amount']) ? $config['max_amount'] : 999999,
                'config' => $config
            );
        }
    }
    
    return $methods;
}
