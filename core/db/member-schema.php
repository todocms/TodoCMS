<?php
/**
 * 会员模块数据表结构 definition
 * 
 * @package TodoCMS
 * @subpackage Database
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access denied');
}

/**
 * 创建会员相关数据表
 */
function create_member_tables() {
    global $tododb;
    
    try {
        $charset_collate = $tododb->get_charset_collate();
    
    // 会员等级表
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}member_levels (
        id int(11) NOT NULL AUTO_INCREMENT COMMENT '等级ID',
        name varchar(100) NOT NULL COMMENT '等级名称',
        level_value int(11) NOT NULL COMMENT '等级值',
        discount decimal(3,2) DEFAULT 1.00 COMMENT '折扣率',
        min_points int(11) DEFAULT 0 COMMENT '最低积分要求',
        min_amount decimal(10,2) DEFAULT 0.00 COMMENT '最低消费金额要求',
        privileges text COMMENT '会员特权',
        description text COMMENT '等级描述',
        status tinyint(1) DEFAULT 1 COMMENT '状态',
        sort_order int(11) DEFAULT 0 COMMENT '排序',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        PRIMARY KEY (id),
        UNIQUE KEY level_value (level_value),
        KEY status (status)
    ) $charset_collate COMMENT='会员等级表';";
    
    if ($tododb->query($sql) === false) {
        error_log('创建会员等级表失败: ' . $tododb->last_error());
        return false;
    }
    
    // 会员积分记录表
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}member_points (
        id int(11) NOT NULL AUTO_INCREMENT,
        user_id int(11) NOT NULL COMMENT '用户ID',
        points int(11) NOT NULL COMMENT '积分变动数量',
        type varchar(20) NOT NULL COMMENT '积分类型:earn获得,spend消费,expire过期',
        source varchar(50) DEFAULT '' COMMENT '积分来源',
        order_id int(11) DEFAULT 0 COMMENT '关联订单ID',
        description varchar(255) DEFAULT '' COMMENT '积分说明',
        expire_date date NULL COMMENT '过期日期',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY type (type),
        KEY order_id (order_id),
        KEY expire_date (expire_date)
    ) $charset_collate COMMENT='会员积分记录表';";
    
    if ($tododb->query($sql) === false) {
        error_log('创建会员积分记录表失败: ' . $tododb->last_error());
        return false;
    }
    
    // 会员余额记录表
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}member_balance (
        id int(11) NOT NULL AUTO_INCREMENT,
        user_id int(11) NOT NULL COMMENT '用户ID',
        amount decimal(10,2) NOT NULL COMMENT '金额变动',
        balance_after decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '变动后余额',
        type varchar(20) NOT NULL COMMENT '类型:recharge充值,consume消费,refund退款,withdraw提现',
        source varchar(50) DEFAULT '' COMMENT '来源',
        order_id int(11) DEFAULT 0 COMMENT '关联订单ID',
        description varchar(255) DEFAULT '' COMMENT '说明',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY type (type),
        KEY order_id (order_id)
    ) $charset_collate COMMENT='会员余额记录表';";
    
    if ($tododb->query($sql) === false) {
        error_log('创建会员余额记录表失败: ' . $tododb->last_error());
        return false;
    }
    
    // 会员收货地址表
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}member_addresses (
        id int(11) NOT NULL AUTO_INCREMENT,
        user_id int(11) NOT NULL COMMENT '用户ID',
        name varchar(100) NOT NULL COMMENT '收货人姓名',
        phone varchar(20) NOT NULL COMMENT '联系电话',
        province varchar(50) NOT NULL COMMENT '省份',
        city varchar(50) NOT NULL COMMENT '城市',
        district varchar(50) NOT NULL COMMENT '区县',
        address varchar(255) NOT NULL COMMENT '详细地址',
        zipcode varchar(10) DEFAULT '' COMMENT '邮政编码',
        tag varchar(50) DEFAULT '' COMMENT '地址标签:如家、公司等',
        is_default tinyint(1) DEFAULT 0 COMMENT '是否默认地址',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY is_default (is_default)
    ) $charset_collate COMMENT='会员收货地址表';";
    
    if ($tododb->query($sql) === false) {
        error_log('创建会员收货地址表失败: ' . $tododb->last_error());
        return false;
    }


    
    // 会员优惠券表
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}member_coupons (
        id int(11) NOT NULL AUTO_INCREMENT,
        name varchar(100) NOT NULL COMMENT '优惠券名称',
        code varchar(50) NOT NULL COMMENT '优惠券代码',
        type varchar(20) NOT NULL COMMENT '类型:fixed固定金额,percent百分比',
        value decimal(10,2) NOT NULL COMMENT '优惠值',
        min_amount decimal(10,2) DEFAULT 0.00 COMMENT '最低消费金额',
        max_discount decimal(10,2) DEFAULT 0.00 COMMENT '最大优惠金额',
        total_quantity int(11) DEFAULT 0 COMMENT '发放总数量',
        used_quantity int(11) DEFAULT 0 COMMENT '已使用数量',
        start_date date NOT NULL COMMENT '开始日期',
        end_date date NOT NULL COMMENT '结束日期',
        status tinyint(1) DEFAULT 1 COMMENT '状态',
        description text COMMENT '使用说明',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY code (code),
        KEY status (status),
        KEY start_date (start_date),
        KEY end_date (end_date)
    ) $charset_collate COMMENT='会员优惠券表';";
    
    if ($tododb->query($sql) === false) {
        error_log('创建会员优惠券表失败: ' . $tododb->last_error());
        return false;
    }
    
    // 会员优惠券使用记录表
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}member_coupon_usage (
        id int(11) NOT NULL AUTO_INCREMENT,
        coupon_id int(11) NOT NULL COMMENT '优惠券ID',
        user_id int(11) NOT NULL COMMENT '用户ID',
        order_id int(11) NOT NULL COMMENT '订单ID',
        discount_amount decimal(10,2) NOT NULL COMMENT '优惠金额',
        used_at timestamp DEFAULT CURRENT_TIMESTAMP COMMENT '使用时间',
        PRIMARY KEY (id),
        KEY coupon_id (coupon_id),
        KEY user_id (user_id),
        KEY order_id (order_id)
    ) $charset_collate COMMENT='会员优惠券使用记录表';";
    
    if ($tododb->query($sql) === false) {
        error_log('创建会员优惠券使用记录表失败: ' . $tododb->last_error());
        return false;
    }
    
    // 会员签到记录表
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}member_checkin (
        id int(11) NOT NULL AUTO_INCREMENT,
        user_id int(11) NOT NULL COMMENT '用户ID',
        checkin_date date NOT NULL COMMENT '签到日期',
        points int(11) DEFAULT 0 COMMENT '获得积分',
        continuous_days int(11) DEFAULT 1 COMMENT '连续签到天数',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY user_date (user_id, checkin_date),
        KEY checkin_date (checkin_date)
    ) $charset_collate COMMENT='会员签到记录表';";
    
    if ($tododb->query($sql) === false) {
        error_log('创建会员签到记录表失败: ' . $tododb->last_error());
        return false;
    }
    
    // 会员消息表
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}member_messages (
        id int(11) NOT NULL AUTO_INCREMENT,
        user_id int(11) NOT NULL COMMENT '用户ID',
        title varchar(255) NOT NULL COMMENT '消息标题',
        content text NOT NULL COMMENT '消息内容',
        type varchar(20) DEFAULT 'system' COMMENT '消息类型:system系统,order订单,promotion促销',
        is_read tinyint(1) DEFAULT 0 COMMENT '是否已读',
        read_at timestamp NULL COMMENT '阅读时间',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY type (type),
        KEY is_read (is_read)
    ) $charset_collate COMMENT='会员消息表';";
    
    if ($tododb->query($sql) === false) {
        error_log('创建会员消息表失败: ' . $tododb->last_error());
        return false;
    }
    
    // 会员收藏表
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}member_favorites (
        id int(11) NOT NULL AUTO_INCREMENT,
        user_id int(11) NOT NULL COMMENT '用户ID',
        object_id int(11) NOT NULL COMMENT '对象ID',
        object_type varchar(20) NOT NULL COMMENT '对象类型:post文章,product商品',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY user_object (user_id, object_id, object_type),
        KEY object_type (object_type)
    ) $charset_collate COMMENT='会员收藏表';";
    
    if ($tododb->query($sql) === false) {
        error_log('创建会员收藏表失败: ' . $tododb->last_error());
        return false;
    }
    
    // 用户浏览记录表
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}user_viewed_products (
        id int(11) NOT NULL AUTO_INCREMENT,
        user_id int(11) NOT NULL COMMENT '用户ID',
        product_id int(11) NOT NULL COMMENT '商品ID',
        viewed_at timestamp DEFAULT CURRENT_TIMESTAMP COMMENT '浏览时间',
        PRIMARY KEY (id),
        UNIQUE KEY user_product (user_id, product_id),
        KEY user_id (user_id),
        KEY product_id (product_id),
        KEY viewed_at (viewed_at)
    ) $charset_collate COMMENT='用户浏览记录表';";
    
    if ($tododb->query($sql) === false) {
        error_log('创建用户浏览记录表失败: ' . $tododb->last_error());
        return false;
    }
    
    // 短信验证码表
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}sms_codes (
        id int(11) NOT NULL AUTO_INCREMENT,
        mobile varchar(20) NOT NULL COMMENT '手机号',
        code varchar(10) NOT NULL COMMENT '验证码',
        type varchar(20) NOT NULL DEFAULT 'register' COMMENT '类型:register注册,login登录,reset重置密码',
        expires_at datetime NOT NULL COMMENT '过期时间',
        used tinyint(1) DEFAULT 0 COMMENT '是否已使用',
        used_at datetime NULL COMMENT '使用时间',
        ip_address varchar(45) DEFAULT NULL COMMENT 'IP地址',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY mobile (mobile),
        KEY type (type),
        KEY expires_at (expires_at),
        KEY used (used)
    ) $charset_collate COMMENT='短信验证码表';";
    
    if ($tododb->query($sql) === false) {
        error_log('创建短信验证码表失败: ' . $tododb->last_error());
        return false;
    }
    
    // 密码重置码表
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}password_reset_codes (
        id int(11) NOT NULL AUTO_INCREMENT,
        user_id int(11) NOT NULL COMMENT '用户ID',
        email varchar(100) DEFAULT NULL COMMENT '邮箱',
        mobile varchar(20) DEFAULT NULL COMMENT '手机号',
        reset_code varchar(64) NOT NULL COMMENT '重置码',
        expires_at datetime NOT NULL COMMENT '过期时间',
        used tinyint(1) DEFAULT 0 COMMENT '是否已使用',
        used_at datetime NULL COMMENT '使用时间',
        ip_address varchar(45) DEFAULT NULL COMMENT 'IP地址',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY email (email),
        KEY mobile (mobile),
        KEY reset_code (reset_code),
        KEY expires_at (expires_at),
        KEY used (used)
    ) $charset_collate COMMENT='密码重置码表';";
    
    if ($tododb->query($sql) === false) {
        error_log('创建密码重置码表失败: ' . $tododb->last_error());
        return false;
    }
    
    // 用户登录日志表
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}user_login_logs (
        id int(11) NOT NULL AUTO_INCREMENT,
        user_id int(11) NOT NULL COMMENT '用户ID',
        login_type varchar(20) NOT NULL DEFAULT 'password' COMMENT '登录类型:password密码,mobile手机,social社交',
        login_method varchar(50) DEFAULT NULL COMMENT '登录方式详情',
        ip_address varchar(45) DEFAULT NULL COMMENT 'IP地址',
        user_agent text COMMENT '用户代理',
        login_status varchar(20) NOT NULL DEFAULT 'success' COMMENT '登录状态:success成功,failed失败',
        failure_reason varchar(100) DEFAULT NULL COMMENT '失败原因',
        session_id varchar(128) DEFAULT NULL COMMENT '会话ID',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY login_type (login_type),
        KEY login_status (login_status),
        KEY ip_address (ip_address),
        KEY created_at (created_at)
    ) $charset_collate COMMENT='用户登录日志表';";
    
    if ($tododb->query($sql) === false) {
        error_log('创建用户登录日志表失败: ' . $tododb->last_error());
        return false;
    }
    
    // 用户操作日志表
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}user_operation_logs (
        id int(11) NOT NULL AUTO_INCREMENT,
        user_id int(11) NOT NULL COMMENT '用户ID',
        operation_type varchar(50) NOT NULL COMMENT '操作类型',
        operation_desc varchar(255) DEFAULT NULL COMMENT '操作描述',
        target_type varchar(50) DEFAULT NULL COMMENT '目标类型',
        target_id int(11) DEFAULT NULL COMMENT '目标ID',
        old_data text COMMENT '原始数据JSON',
        new_data text COMMENT '新数据JSON',
        ip_address varchar(45) DEFAULT NULL COMMENT 'IP地址',
        user_agent text COMMENT '用户代理',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY operation_type (operation_type),
        KEY target_type (target_type),
        KEY target_id (target_id),
        KEY created_at (created_at)
    ) $charset_collate COMMENT='用户操作日志表';";
    
    if ($tododb->query($sql) === false) {
        error_log('创建用户操作日志表失败: ' . $tododb->last_error());
        return false;
    }
    
    return true;
    } catch (Exception $e) {
        error_log('创建会员表异常: ' . $e->getMessage());
        return false;
    }
}

/**
 * 创建用户等级相关表
 */
function create_user_level_tables() {
    global $tododb;
    
    $charset_collate = $tododb->get_charset_collate();
    
    try {
        // 用户等级表
        $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}user_levels (
            id int(11) NOT NULL AUTO_INCREMENT,
            level_name varchar(100) NOT NULL COMMENT '等级名称',
            level_slug varchar(50) NOT NULL COMMENT '等级标识',
            min_points int(11) NOT NULL DEFAULT 0 COMMENT '最低积分要求',
            max_points int(11) NOT NULL DEFAULT 0 COMMENT '最高积分要求',
            level_order int(11) NOT NULL DEFAULT 0 COMMENT '等级排序',
            discount_rate decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT '折扣率',
            privileges text COMMENT '等级特权JSON',
            level_icon varchar(255) DEFAULT NULL COMMENT '等级图标',
            level_color varchar(20) DEFAULT NULL COMMENT '等级颜色',
            description text COMMENT '等级描述',
            is_active tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否启用',
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY level_slug (level_slug),
            KEY is_active (is_active),
            KEY level_order (level_order)
        ) $charset_collate COMMENT='用户等级表';";
        
        if ($tododb->query($sql) === false) {
            error_log('创建用户等级表失败: ' . $tododb->last_error());
            return false;
        }
        
        // 用户等级记录表
        $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}user_level_records (
            id int(11) NOT NULL AUTO_INCREMENT,
            user_id int(11) NOT NULL COMMENT '用户ID',
            level_id int(11) NOT NULL COMMENT '等级ID',
            points int(11) NOT NULL DEFAULT 0 COMMENT '当前积分',
            upgrade_type varchar(20) DEFAULT 'auto' COMMENT '升级类型:auto自动,manual手动,package套餐',
            package_id int(11) DEFAULT NULL COMMENT '升级套餐ID',
            status varchar(20) DEFAULT 'active' COMMENT '状态:pending待处理,active生效,expired过期,cancelled取消',
            expires_at datetime DEFAULT NULL COMMENT '过期时间',
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id),
            KEY level_id (level_id),
            KEY upgrade_type (upgrade_type),
            KEY package_id (package_id),
            KEY status (status)
        ) $charset_collate COMMENT='用户等级记录表';";
        
        if ($tododb->query($sql) === false) {
            error_log('创建用户等级记录表失败: ' . $tododb->last_error());
            return false;
        }
        
        // 用户积分日志表
        $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}user_points_log (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL COMMENT '用户ID',
            amount DECIMAL(10,2) NOT NULL COMMENT '积分变动量',
            balance_after DECIMAL(10,2) NOT NULL COMMENT '变动后余额',
            type VARCHAR(20) NOT NULL COMMENT '类型:earn获得,spend消费',
            action VARCHAR(50) NOT NULL COMMENT '操作标识',
            description TEXT COMMENT '操作描述',
            related_id INT(11) DEFAULT NULL COMMENT '关联ID',
            admin_id INT(11) DEFAULT NULL COMMENT '管理员ID',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY type (type),
            KEY action (action),
            KEY created_at (created_at),
            KEY related_id (related_id),
            KEY admin_id (admin_id)
        ) $charset_collate COMMENT='用户积分日志表';";
        
        if ($tododb->query($sql) === false) {
            error_log('创建用户积分日志表失败: ' . $tododb->last_error());
            return false;
        }

        // 插入默认用户等级
        $levels_to_insert = [
            [
                'level_name' => '注册会员',
                'level_slug' => 'normal',
                'min_points' => 0,
                'max_points' => 0,
                'level_order' => 1,
                'discount_rate' => 100.00,
                'description' => '默认注册会员等级',
                'is_active' => 1
            ],
            [
                'level_name' => '黄金会员',
                'level_slug' => 'gold',
                'min_points' => 1000,
                'max_points' => 5000,
                'level_order' => 2,
                'discount_rate' => 95.00,
                'description' => '享有95折优惠的会员等级',
                'is_active' => 1
            ],
            [
                'level_name' => '钻石会员',
                'level_slug' => 'diamond',
                'min_points' => 5000,
                'max_points' => 0,
                'level_order' => 3,
                'discount_rate' => 90.00,
                'description' => '享有90折优惠的会员等级',
                'is_active' => 1
            ],
	        [
	            'level_name'   => '基础批发商',
	            'level_slug'   => 'wholesale',
	            'min_points'   => 0,
	            'max_points'   => 0,
	            'level_order'  => 4,
	            'discount_rate'=> 100.00,
	            'privileges'   => json_encode(['is_wholesale' => true], JSON_UNESCAPED_UNICODE),
	            'description'  => '普通批发商户，享受批发价',
	            'is_active'    => 1,
	        ],
	        [
	            'level_name'   => '高级批发商',
	            'level_slug'   => 'wholesale_vip',
	            'min_points'   => 0,
	            'max_points'   => 0,
	            'level_order'  => 5,
	            'discount_rate'=> 100.00,
	            'privileges'   => json_encode(['is_wholesale' => true, 'is_vip' => true], JSON_UNESCAPED_UNICODE),
	            'description'  => '高级批发商户，享受更优批发价',
	            'is_active'    => 1,
	        ]
        ];

        foreach ($levels_to_insert as $level) {
            $exists = $tododb->get_var("SELECT id FROM {$tododb->prefix}user_levels WHERE level_slug = ?", [$level['level_slug']]);
            if (!$exists) {
                $tododb->insert("{$tododb->prefix}user_levels", $level);
            }
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log('创建用户等级表异常: ' . $e->getMessage());
        return false;
    }
}

/**
 * 创建清理过期数据的存储过程
 */
function create_user_cleanup_procedures() {
    global $tododb;
    
    try {
        // 创建清理过期数据的存储过程
        $sql = "DROP PROCEDURE IF EXISTS CleanupExpiredUserData;";
        $tododb->query($sql);
        
        $sql = "CREATE PROCEDURE CleanupExpiredUserData()
BEGIN
    -- 清理过期的短信验证码（保留7天）
    DELETE FROM {$tododb->prefix}sms_codes 
    WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
    
    -- 清理过期的密码重置码（保留7天）
    DELETE FROM {$tododb->prefix}password_reset_codes 
    WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
    
    -- 清理过期的用户登录日志（保留90天）
    DELETE FROM {$tododb->prefix}user_login_logs 
    WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
    
    -- 清理过期的短信发送日志（保留30天）
    DELETE FROM {$tododb->prefix}sms_send_logs 
    WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
    
    -- 清理过期的用户操作日志（保留180天）
    DELETE FROM {$tododb->prefix}user_operation_logs 
    WHERE created_at < DATE_SUB(NOW(), INTERVAL 180 DAY);
END;";
        
        if ($tododb->query($sql) === false) {
            error_log('创建清理存储过程失败: ' . $tododb->last_error());
            return false;
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log('创建清理存储过程异常: ' . $e->getMessage());
        return false;
    }
}

// 函数定义完成，由install.php调用执行
