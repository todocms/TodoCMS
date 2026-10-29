<?php
/**
 * TodoCMS 支付系统数据库表结构
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    exit('Direct access not permitted');
}

/**
 * 创建支付系统相关数据表
 */
function todo_create_payment_tables() {
    global $tododb;
    
    // 支付记录表
    $sql_payments = "CREATE TABLE IF NOT EXISTS `{$tododb->prefix}payments` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `order_id` int(11) NOT NULL COMMENT '订单ID',
        `method_id` varchar(50) NOT NULL COMMENT '支付方式ID',
        `amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '支付金额',
        `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT '支付状态',
        `gateway_order_id` varchar(100) DEFAULT NULL COMMENT '支付网关订单号',
        `gateway_data` text COMMENT '支付网关返回数据',
        `failure_reason` varchar(255) DEFAULT NULL COMMENT '失败原因',
        `paid_at` datetime DEFAULT NULL COMMENT '支付完成时间',
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        PRIMARY KEY (`id`),
        KEY `idx_order_id` (`order_id`),
        KEY `idx_method_id` (`method_id`),
        KEY `idx_status` (`status`),
        KEY `idx_gateway_order_id` (`gateway_order_id`),
        KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='支付记录表';";
    
    // 退款记录表
    $sql_refunds = "CREATE TABLE IF NOT EXISTS `{$tododb->prefix}refunds` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `order_id` int(11) NOT NULL COMMENT '订单ID',
        `payment_id` int(11) NOT NULL COMMENT '支付记录ID',
        `refund_amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '退款金额',
        `refund_reason` varchar(255) DEFAULT NULL COMMENT '退款原因',
        `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT '退款状态',
        `gateway_refund_id` varchar(100) DEFAULT NULL COMMENT '支付网关退款号',
        `gateway_data` text COMMENT '支付网关返回数据',
        `processed_at` datetime DEFAULT NULL COMMENT '处理完成时间',
        `created_by` int(11) NOT NULL COMMENT '申请人ID',
        `processed_by` int(11) DEFAULT NULL COMMENT '处理人ID',
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        PRIMARY KEY (`id`),
        KEY `idx_order_id` (`order_id`),
        KEY `idx_payment_id` (`payment_id`),
        KEY `idx_status` (`status`),
        KEY `idx_created_by` (`created_by`),
        KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='退款记录表';";
    
    // 支付方式配置表
    $sql_payment_methods = "CREATE TABLE IF NOT EXISTS `{$tododb->prefix}payment_method_configs` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `method_id` varchar(50) NOT NULL COMMENT '支付方式ID',
        `name` varchar(100) NOT NULL COMMENT '支付方式名称',
        `description` text COMMENT '支付方式描述',
        `icon` varchar(100) DEFAULT NULL COMMENT '图标',
        `enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否启用',
        `sort_order` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
        `min_amount` decimal(10,2) DEFAULT NULL COMMENT '最小支付金额',
        `max_amount` decimal(10,2) DEFAULT NULL COMMENT '最大支付金额',
        `settings` text COMMENT '支付方式配置(JSON)',
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_method_id` (`method_id`),
        KEY `idx_enabled` (`enabled`),
        KEY `idx_sort_order` (`sort_order`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='支付方式配置表';";
    
    // 用户余额变动记录表
    $sql_balance_logs = "CREATE TABLE IF NOT EXISTS `{$tododb->prefix}balance_logs` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL COMMENT '用户ID',
        `type` varchar(20) NOT NULL COMMENT '变动类型(recharge/consume/refund/admin)',
        `amount` decimal(10,2) NOT NULL COMMENT '变动金额(正数为增加，负数为减少)',
        `balance_before` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '变动前余额',
        `balance_after` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '变动后余额',
        `related_id` int(11) DEFAULT NULL COMMENT '关联ID(订单ID、充值记录ID等)',
        `related_type` varchar(20) DEFAULT NULL COMMENT '关联类型(order/recharge/refund)',
        `description` varchar(255) DEFAULT NULL COMMENT '变动说明',
        `created_by` int(11) DEFAULT NULL COMMENT '操作人ID',
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        PRIMARY KEY (`id`),
        KEY `idx_user_id` (`user_id`),
        KEY `idx_type` (`type`),
        KEY `idx_related` (`related_id`, `related_type`),
        KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户余额变动记录表';";
    
    // 充值记录表
    $sql_recharge_records = "CREATE TABLE IF NOT EXISTS `{$tododb->prefix}recharge_records` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL COMMENT '用户ID',
        `amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '充值金额',
        `bonus_amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '赠送金额',
        `total_amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '实际到账金额',
        `payment_method` varchar(50) NOT NULL COMMENT '支付方式',
        `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT '充值状态',
        `payment_id` int(11) DEFAULT NULL COMMENT '支付记录ID',
        `gateway_order_id` varchar(100) DEFAULT NULL COMMENT '支付网关订单号',
        `completed_at` datetime DEFAULT NULL COMMENT '完成时间',
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        PRIMARY KEY (`id`),
        KEY `idx_user_id` (`user_id`),
        KEY `idx_status` (`status`),
        KEY `idx_payment_id` (`payment_id`),
        KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='充值记录表';";
    
    // 执行SQL语句
    $tables = array(
        'payments' => $sql_payments,
        'refunds' => $sql_refunds,
        'payment_method_configs' => $sql_payment_methods,
        'balance_logs' => $sql_balance_logs,
        'recharge_records' => $sql_recharge_records
    );
    
    foreach ($tables as $table_name => $sql) {
        if (!$tododb->query($sql)) {
            todo_log_error("Failed to create table {$table_name}: " . $tododb->get_last_error());
            return false;
        }
    }
    
    // 插入默认支付方式配置
    todo_insert_default_payment_methods();
    
    return true;
}

/**
 * 插入默认支付方式配置
 */
function todo_insert_default_payment_methods() {
    global $tododb;
    
    $default_methods = array(
        array(
            'method_id' => 'payjs_wechat',
            'name' => '微信支付',
            'description' => '使用PAYJS微信支付',
            'icon' => 'wechat',
            'enabled' => 1,
            'sort_order' => 1,
            'settings' => json_encode(array(
                'mchid' => '',
                'key' => '',
                'notify_url' => '',
                'return_url' => ''
            ))
        ),
        array(
            'method_id' => 'payjs_alipay',
            'name' => '支付宝',
            'description' => '使用PAYJS支付宝',
            'icon' => 'alipay',
            'enabled' => 1,
            'sort_order' => 2,
            'settings' => json_encode(array(
                'mchid' => '',
                'key' => '',
                'notify_url' => '',
                'return_url' => ''
            ))
        ),
        array(
            'method_id' => 'balance',
            'name' => '余额支付',
            'description' => '使用账户余额支付',
            'icon' => 'wallet',
            'enabled' => 1,
            'sort_order' => 5,
            'settings' => json_encode(array())
        ),
        array(
            'method_id' => 'offline',
            'name' => '线下支付',
            'description' => '银行转账、现金等线下支付方式',
            'icon' => 'bank',
            'enabled' => 0,
            'sort_order' => 10,
            'settings' => json_encode(array(
                'instructions' => '请联系客服获取转账信息'
            ))
        )
    );
    
    foreach ($default_methods as $method) {
        // 检查是否已存在
        $check_query = "SELECT id FROM {$tododb->prefix}payment_method_configs WHERE method_id = ?";
        $result = $tododb->get_results($check_query, [$method['method_id']]);
        
        if (count($result) == 0) {
            // 插入新记录
            $insert_data = array(
                'method_id' => $method['method_id'],
                'name' => $method['name'],
                'description' => $method['description'],
                'icon' => $method['icon'],
                'enabled' => $method['enabled'],
                'sort_order' => $method['sort_order'],
                'settings' => $method['settings']
            );
            
            $tododb->insert("{$tododb->prefix}payment_method_configs", $insert_data);
        }
    }
}

/**
 * 添加用户余额字段到用户表 (已在 schema.php 主表结构定义)
 */
function todo_add_user_balance_field() {
    return true;
}

/**
 * 为订单表添加下载相关字段 (已在 shop-schema.php 主表结构定义)
 */
function todo_add_order_download_fields() {
    return true;
}

/**
 * 创建下载记录表
 */
function todo_create_download_logs_table() {
    global $tododb;
    
    $sql = "CREATE TABLE IF NOT EXISTS `{$tododb->prefix}download_logs` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `order_id` int(11) NOT NULL COMMENT '订单ID',
        `user_id` int(11) NOT NULL COMMENT '用户ID',
        `product_id` int(11) NOT NULL COMMENT '商品ID',
        `file_path` varchar(255) NOT NULL COMMENT '文件路径',
        `file_name` varchar(255) NOT NULL COMMENT '文件名称',
        `file_size` bigint(20) DEFAULT NULL COMMENT '文件大小(字节)',
        `download_ip` varchar(45) DEFAULT NULL COMMENT '下载IP',
        `user_agent` text COMMENT '用户代理',
        `download_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '下载时间',
        PRIMARY KEY (`id`),
        KEY `idx_order_id` (`order_id`),
        KEY `idx_user_id` (`user_id`),
        KEY `idx_product_id` (`product_id`),
        KEY `idx_download_time` (`download_time`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='下载记录表';";
    
    if (!$tododb->query($sql)) {
        todo_log_error("Failed to create download_logs table: " . $tododb->get_last_error());
        return false;
    }
    
    return true;
}