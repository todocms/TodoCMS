<?php
/**
 * 升级套餐数据库结构
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access denied.');
}

/**
 * 创建升级套餐相关数据表
 */
function create_upgrade_package_tables() {
    global $tododb;
    
    try {
        $charset_collate = $tododb->get_charset_collate();
    
        // 升级套餐表
        $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}upgrade_packages (
            id int(11) NOT NULL AUTO_INCREMENT,
            package_name varchar(100) NOT NULL COMMENT '套餐名称',
            package_slug varchar(100) NOT NULL COMMENT '套餐标识符',
            package_type varchar(20) NOT NULL DEFAULT 'level' COMMENT '套餐类型:level等级升级,points积分充值,balance余额充值',
            target_level_id int(11) DEFAULT NULL COMMENT '目标用户等级ID',
            price decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '套餐价格',
            original_price decimal(10,2) DEFAULT NULL COMMENT '原价',
            points_reward int(11) DEFAULT 0 COMMENT '赠送积分',
            balance_reward decimal(10,2) DEFAULT 0.00 COMMENT '赠送余额',
            validity_days int(11) DEFAULT 0 COMMENT '有效期天数(0为永久)',
            description text COMMENT '套餐描述',
            features text COMMENT '套餐特性(JSON格式)',
            package_icon varchar(100) DEFAULT '' COMMENT '套餐图标',
            package_color varchar(20) DEFAULT '#007cba' COMMENT '套餐颜色',
            is_active tinyint(1) DEFAULT 1 COMMENT '是否启用',
            is_recommended tinyint(1) DEFAULT 0 COMMENT '是否推荐',
            sort_order int(11) DEFAULT 0 COMMENT '排序',
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY package_slug (package_slug),
            KEY package_type (package_type),
            KEY target_level_id (target_level_id),
            KEY is_active (is_active),
            KEY sort_order (sort_order)
        ) $charset_collate COMMENT='升级套餐表';";
        
        if ($tododb->query($sql) === false) {
            error_log('创建升级套餐表失败: ' . $tododb->last_error());
            return false;
        }
        
        // 升级套餐购买记录表
        $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}upgrade_package_orders (
            id int(11) NOT NULL AUTO_INCREMENT,
            user_id int(11) NOT NULL COMMENT '用户ID',
            package_id int(11) NOT NULL COMMENT '套餐ID',
            order_no varchar(50) NOT NULL COMMENT '订单号',
            package_name varchar(100) NOT NULL COMMENT '套餐名称',
            price decimal(10,2) NOT NULL COMMENT '支付金额',
            payment_method varchar(20) DEFAULT '' COMMENT '支付方式',
            payment_status varchar(20) DEFAULT 'pending' COMMENT '支付状态:pending待支付,paid已支付,failed失败,refunded已退款',
            old_level_id int(11) DEFAULT NULL COMMENT '升级前等级ID',
            new_level_id int(11) DEFAULT NULL COMMENT '升级后等级ID',
            points_reward int(11) DEFAULT 0 COMMENT '赠送积分',
            balance_reward decimal(10,2) DEFAULT 0.00 COMMENT '赠送余额',
            expire_at datetime DEFAULT NULL COMMENT '过期时间',
            paid_at datetime DEFAULT NULL COMMENT '支付时间',
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY order_no (order_no),
            KEY user_id (user_id),
            KEY package_id (package_id),
            KEY payment_status (payment_status),
            KEY paid_at (paid_at)
        ) $charset_collate COMMENT='升级套餐购买记录表';";
        
        if ($tododb->query($sql) === false) {
            error_log('创建升级套餐购买记录表失败: ' . $tododb->last_error());
            return false;
        }
        
        return true;
    } catch (Exception $e) {
        error_log('创建升级套餐表异常: ' . $e->getMessage());
        return false;
    }
}

// 注意：函数定义完成，由管理后台或安装器调用执行
