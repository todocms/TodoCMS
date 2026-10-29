<?php
/**
 * 商城模块数据表结构定义
 * 
 * @package TodoCMS
 * @subpackage Database
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access denied');
}

/**
 * 创建商城相关数据表
 */
function create_shop_tables() {
    global $tododb;
    
    try {
        $charset_collate = $tododb->get_charset_collate();
    
    // 商品规格表
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}product_specs (
        id int(11) NOT NULL AUTO_INCREMENT COMMENT '商品规格唯一标识ID',
        product_id int(11) NOT NULL COMMENT '关联的商品ID',
        spec_name varchar(100) NOT NULL COMMENT '规格名称（如：颜色、尺寸）',
        spec_value varchar(255) NOT NULL COMMENT '规格值（如：红色、XL）',
        price decimal(10,2) DEFAULT 0.00 COMMENT '该规格的现售价',
        original_price decimal(10,2) DEFAULT 0.00 COMMENT '该规格的原价',
        stock int(11) DEFAULT 0 COMMENT '该规格的库存数量',
        virtual_sales int(11) DEFAULT 0 COMMENT '该规格的虚拟销量',
        sku varchar(100) DEFAULT '' COMMENT '该规格的商品编码/SKU',
        sort_order int(11) DEFAULT 0 COMMENT '规格排序权重',
        status tinyint(1) DEFAULT 1 COMMENT '规格状态（1启用/0禁用）',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP COMMENT '规格创建时间',
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '规格更新时间',
        PRIMARY KEY (id),
        KEY product_id (product_id),
        KEY status (status)
    ) $charset_collate COMMENT='商品规格表';";
    
    if ($tododb->query($sql) === false) {
        error_log('创建商品规格表失败: ' . $tododb->last_error());
        return false;
    }
    
    // 订单表
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}orders (
        id int(11) NOT NULL AUTO_INCREMENT COMMENT '订单唯一标识ID',
        order_no varchar(50) NOT NULL COMMENT '订单编号（唯一标识）',
        user_id int(11) NOT NULL COMMENT '下单用户ID',
        total_amount decimal(10,2) NOT NULL COMMENT '订单商品总金额',
        payment_amount decimal(10,2) DEFAULT 0.00 COMMENT '用户实际支付金额',
        shipping_fee decimal(10,2) DEFAULT 0.00 COMMENT '运费金额',
        discount_amount decimal(10,2) DEFAULT 0.00 COMMENT '优惠折扣金额',
        status varchar(20) DEFAULT 'pending' COMMENT '订单状态（pending待处理/processing处理中/completed已完成/cancelled已取消）',
        reviewed tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否已评价（0否/1是）',
        payment_status varchar(20) DEFAULT 'unpaid' COMMENT '支付状态（unpaid未支付/paid已支付/refunding退款中/refunded已退款）',
        shipping_status varchar(20) DEFAULT 'unshipped' COMMENT '发货状态（unshipped未发货/shipped已发货/delivered已送达）',
        payment_method varchar(50) DEFAULT '' COMMENT '支付方式（支付宝/微信/银行卡等）',
        shipping_method varchar(50) DEFAULT '' COMMENT '配送方式（快递/自提/同城配送等）',
        shipping_address text COMMENT '收货地址详细信息',
        shipping_info text COMMENT '物流信息和快递单号',
        remark text COMMENT '订单备注信息（客户备注）',
        admin_notes text COMMENT '管理员备注信息',
        ab_order_type varchar(20) DEFAULT 'normal' COMMENT 'AB站订单类型(normal/site_a/site_b)',
        ab_ref_order_no varchar(50) DEFAULT '' COMMENT '关联的A站或B站订单号',
        ab_b_site_url varchar(255) DEFAULT '' COMMENT '被分配的B站URL(A站订单专用)',
        transaction_id varchar(255) DEFAULT '' COMMENT '第三方交易流水号',
        is_download_order tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否为下载订单',
        download_count int(11) NOT NULL DEFAULT '0' COMMENT '已下载次数',
        download_limit int(11) NOT NULL DEFAULT '0' COMMENT '下载次数限制(0为无限制)',
        download_expire_at datetime DEFAULT NULL COMMENT '下载过期时间',
        paid_at timestamp NULL COMMENT '订单支付完成时间',
        shipped_at timestamp NULL COMMENT '订单发货时间',
        completed_at timestamp NULL COMMENT '订单完成时间',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP COMMENT '订单创建时间',
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '订单更新时间',
        PRIMARY KEY (id),
        UNIQUE KEY order_no (order_no),
        KEY user_id (user_id),
        KEY status (status),
        KEY payment_status (payment_status)
    ) $charset_collate COMMENT='订单表';";
    
    if ($tododb->query($sql) === false) {
        error_log('创建订单表失败: ' . $tododb->last_error());
        return false;
    }
    
    // 订单商品表
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}order_items (
        id int(11) NOT NULL AUTO_INCREMENT COMMENT '订单商品记录唯一标识ID',
        order_id int(11) NOT NULL COMMENT '关联的订单ID',
        product_id int(11) NOT NULL COMMENT '关联的商品ID',
        spec_id int(11) DEFAULT 0 COMMENT '商品规格ID（0表示无规格）',
        product_name varchar(255) NOT NULL COMMENT '商品名称（下单时快照）',
        spec_name varchar(255) DEFAULT '' COMMENT '规格名称（下单时快照）',
        price decimal(10,2) NOT NULL COMMENT '商品单价（下单时价格）',
        quantity int(11) NOT NULL COMMENT '购买数量',
        total_price decimal(10,2) NOT NULL COMMENT '该商品小计金额',
        product_image varchar(255) DEFAULT '' COMMENT '商品图片URL（下单时快照）',
        license_key varchar(100) DEFAULT '' COMMENT '自动生成的授权码/卡密KEY',
        license_pass varchar(100) DEFAULT '' COMMENT '自动生成的授权码对应密码',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间',
        PRIMARY KEY (id),
        KEY order_id (order_id),
        KEY product_id (product_id)
    ) $charset_collate COMMENT='订单商品表';";
    
    if ($tododb->query($sql) === false) {
        error_log('创建订单商品表失败: ' . $tododb->last_error());
        return false;
    }
    
    // 购物车表（与代码保持一致：cart，包含 specs 与 price 字段）
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}cart (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '购物车记录唯一标识ID',
        user_id bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
        session_id varchar(100) NOT NULL DEFAULT '' COMMENT '会话ID',
        product_id bigint(20) unsigned NOT NULL COMMENT '商品ID',
        quantity int(11) NOT NULL DEFAULT 1 COMMENT '数量',
        specs text COMMENT '规格数据(JSON)',
        price decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '商品单价',
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY session_id (session_id),
        KEY product_id (product_id)
    ) $charset_collate COMMENT='购物车表';";
    
    if ($tododb->query($sql) === false) {
        error_log('创建购物车表失败: ' . $tododb->last_error());
        return false;
    }
    
    // 商品评价表
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}product_reviews (
        id int(11) NOT NULL AUTO_INCREMENT COMMENT '评价记录唯一标识ID',
        product_id int(11) NOT NULL COMMENT '关联的商品ID',
        order_id int(11) NOT NULL COMMENT '关联的订单ID',
        user_id int(11) NOT NULL COMMENT '评价用户ID',
        rating tinyint(1) NOT NULL DEFAULT 5 COMMENT '评分等级（1-5星）',
        content text COMMENT '评价内容文字',
        spec_name varchar(255) DEFAULT '' COMMENT '商品规格名称',
        images longtext COMMENT '评价图片URL列表（JSON格式）',
        status tinyint(1) DEFAULT 0 COMMENT '审核状态（0待审核/1已审核/2已拒绝）',
        reply_content text COMMENT '商家回复内容',
        reply_time timestamp NULL COMMENT '商家回复时间',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP COMMENT '评价创建时间',
        PRIMARY KEY (id),
        KEY product_id (product_id),
        KEY user_id (user_id),
        KEY status (status)
    ) $charset_collate COMMENT='商品评价表';";
    
    if ($tododb->query($sql) === false) {
        error_log('创建商品评价表失败: ' . $tododb->last_error());
        return false;
    }

    // 订单评价表
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}order_reviews (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '评价ID',
        order_id bigint(20) unsigned NOT NULL COMMENT '订单ID',
        order_item_id bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT '订单商品ID',
        product_id bigint(20) unsigned NOT NULL COMMENT '商品ID',
        user_id bigint(20) unsigned NOT NULL COMMENT '用户ID',
        rating tinyint(1) NOT NULL DEFAULT 5 COMMENT '评分(1-5)',
        content text COMMENT '评价内容',
        images text COMMENT '评价图片(JSON)',
        status varchar(20) NOT NULL DEFAULT 'pending' COMMENT '审核状态(pending/approved/rejected)',
        admin_reply text COMMENT '管理员回复',
        replied_at timestamp NULL DEFAULT NULL COMMENT '回复时间',
        created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        PRIMARY KEY (id),
        KEY order_id (order_id),
        KEY product_id (product_id),
        KEY user_id (user_id),
        KEY status (status),
        KEY rating (rating)
    ) $charset_collate COMMENT='订单评价表';";

    if ($tododb->query($sql) === false) {
        error_log('创建订单评价表失败: ' . $tododb->last_error());
        return false;
    }
    
    // 支付方式表
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}payment_methods (
        id int(11) NOT NULL AUTO_INCREMENT COMMENT '支付方式唯一标识ID',
        name varchar(100) NOT NULL COMMENT '支付方式显示名称',
        code varchar(50) NOT NULL COMMENT '支付方式代码标识（唯一）',
        config text COMMENT '支付方式配置信息（JSON格式）',
        status tinyint(1) DEFAULT 1 COMMENT '启用状态（0禁用/1启用）',
        sort_order int(11) DEFAULT 0 COMMENT '显示排序权重',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        PRIMARY KEY (id),
        UNIQUE KEY code (code)
    ) $charset_collate COMMENT='支付方式表';";
    
    if ($tododb->query($sql) === false) {
        error_log('创建支付方式表失败: ' . $tododb->last_error());
        return false;
    }
    
    // 快递公司表
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}shipping_companies (
        id int(11) NOT NULL AUTO_INCREMENT COMMENT '快递公司唯一标识ID',
        name varchar(100) NOT NULL COMMENT '快递公司名称',
        code varchar(50) NOT NULL COMMENT '快递公司代码标识（唯一）',
        website varchar(255) DEFAULT '' COMMENT '快递公司官网地址',
        phone varchar(50) DEFAULT '' COMMENT '客服电话',
        status tinyint(1) DEFAULT 1 COMMENT '启用状态（0禁用/1启用）',
        sort_order int(11) DEFAULT 0 COMMENT '显示排序权重',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        PRIMARY KEY (id),
        UNIQUE KEY code (code)
    ) $charset_collate COMMENT='快递公司表';";
    
    if ($tododb->query($sql) === false) {
        error_log('创建快递公司表失败: ' . $tododb->last_error());
        return false;
    }
    
    // 优惠券表
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}coupons (
        id int(11) NOT NULL AUTO_INCREMENT COMMENT '优惠券唯一标识ID',
        code varchar(50) NOT NULL COMMENT '优惠券代码（唯一）',
        name varchar(255) NOT NULL COMMENT '优惠券名称',
        description text COMMENT '优惠券描述',
        type tinyint(1) NOT NULL DEFAULT 1 COMMENT '优惠券类型（1固定金额/2百分比折扣/3满减）',
        value decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '优惠券面值（固定金额或折扣百分比）',
        min_amount decimal(10,2) DEFAULT 0.00 COMMENT '最低消费金额限制',
        max_discount decimal(10,2) DEFAULT NULL COMMENT '最大折扣金额（仅百分比类型）',
        usage_limit int(11) DEFAULT NULL COMMENT '使用次数限制（NULL为无限制）',
        used_count int(11) DEFAULT 0 COMMENT '已使用次数',
        user_limit int(11) DEFAULT 1 COMMENT '每用户使用次数限制',
        claim_limit int(11) NOT NULL DEFAULT 1 COMMENT '每用户领取次数限制',
        is_transferable tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否支持转赠（0否/1是）',
        issue_mode varchar(20) NOT NULL DEFAULT 'redeem' COMMENT '发放方式（redeem/auto_birthday）',
        auto_issue_type varchar(20) NOT NULL DEFAULT '' COMMENT '自动发放类型（birthday）',
        auto_issue_days int(11) NOT NULL DEFAULT 0 COMMENT '自动发放提前天数',
        target_level_id int(11) NOT NULL DEFAULT 0 COMMENT '目标用户等级ID（0全部用户）',
        activity_id int(11) NOT NULL DEFAULT 0 COMMENT '关联活动分类ID',
        start_date timestamp NULL DEFAULT NULL COMMENT '有效期开始时间',
        end_date timestamp NULL DEFAULT NULL COMMENT '有效期结束时间',
        status tinyint(1) DEFAULT 1 COMMENT '状态（0禁用/1启用）',
        created_by int(11) DEFAULT NULL COMMENT '创建者用户ID',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        PRIMARY KEY (id),
        UNIQUE KEY code (code),
        KEY status (status),
        KEY start_date (start_date),
        KEY end_date (end_date),
        KEY activity_id (activity_id)
    ) $charset_collate COMMENT='优惠券表';";
    
    if ($tododb->query($sql) === false) {
        error_log('创建优惠券表失败: ' . $tododb->last_error());
        return false;
    }
    
    // 礼品卡表
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}gift_cards (
        id int(11) NOT NULL AUTO_INCREMENT COMMENT '礼品卡唯一标识ID',
        batch_no varchar(50) DEFAULT NULL COMMENT '批次号',
        code varchar(50) NOT NULL COMMENT '礼品卡卡号（唯一）',
        pin varchar(20) DEFAULT NULL COMMENT '激活码/PIN',
        amount decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '礼品卡面值',
        card_type tinyint(1) DEFAULT 0 COMMENT '卡类型（0电子卡/1实体卡）',
        status tinyint(1) DEFAULT 0 COMMENT '状态（0未使用/1已使用/2已作废）',
        activated_by bigint(20) unsigned DEFAULT NULL COMMENT '激活用户ID',
        activated_at datetime DEFAULT NULL COMMENT '激活时间',
        expiry_at datetime DEFAULT NULL COMMENT '有效期至',
        created_by bigint(20) unsigned DEFAULT NULL COMMENT '创建者用户ID',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        PRIMARY KEY (id),
        UNIQUE KEY code (code),
        KEY status (status),
        KEY batch_no (batch_no),
        KEY activated_by (activated_by)
    ) $charset_collate COMMENT='礼品卡表';";
    
    if ($tododb->query($sql) === false) {
        error_log('创建礼品卡表失败: ' . $tododb->last_error());
        return false;
    }
    
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}user_coupons (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '用户优惠券ID',
        coupon_id int(11) NOT NULL COMMENT '优惠券ID',
        user_id bigint(20) unsigned NOT NULL COMMENT '用户ID',
        status varchar(20) NOT NULL DEFAULT 'unused' COMMENT '状态（unused/used/expired/void）',
        source varchar(20) NOT NULL DEFAULT 'redeem' COMMENT '来源（redeem/auto_birthday/manual/transfer）',
        from_user_id bigint(20) unsigned DEFAULT NULL COMMENT '来源用户ID（转赠）',
        assigned_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '获得时间',
        used_at datetime DEFAULT NULL COMMENT '使用时间',
        order_id bigint(20) unsigned DEFAULT NULL COMMENT '关联订单ID',
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY coupon_id (coupon_id),
        KEY status (status),
        KEY assigned_at (assigned_at)
    ) $charset_collate COMMENT='用户优惠券表';";

    if ($tododb->query($sql) === false) {
        error_log('创建用户优惠券表失败: ' . $tododb->last_error());
        return false;
    }

    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}coupon_issues (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '发放记录ID',
        coupon_id int(11) NOT NULL COMMENT '优惠券ID',
        user_id bigint(20) unsigned NOT NULL COMMENT '用户ID',
        issue_type varchar(20) NOT NULL COMMENT '发放类型（birthday/grant）',
        issue_key varchar(32) NOT NULL DEFAULT '' COMMENT '发放幂等Key',
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        PRIMARY KEY (id),
        UNIQUE KEY uniq_issue (coupon_id, user_id, issue_type, issue_key),
        KEY coupon_id (coupon_id),
        KEY user_id (user_id),
        KEY issue_type (issue_type)
    ) $charset_collate COMMENT='优惠券发放记录表';";

    if ($tododb->query($sql) === false) {
        error_log('创建优惠券发放记录表失败: ' . $tododb->last_error());
        return false;
    }

    // 优惠券使用记录表
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}coupon_usage (
        id int(11) NOT NULL AUTO_INCREMENT COMMENT '使用记录唯一标识ID',
        coupon_id int(11) NOT NULL COMMENT '关联的优惠券ID',
        user_id int(11) NOT NULL COMMENT '使用用户ID',
        order_id int(11) DEFAULT NULL COMMENT '关联的订单ID',
        discount_amount decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '实际折扣金额',
        used_at timestamp DEFAULT CURRENT_TIMESTAMP COMMENT '使用时间',
        status tinyint(1) DEFAULT 1 COMMENT '记录状态（1有效/0已退款）',
        PRIMARY KEY (id),
        KEY coupon_id (coupon_id),
        KEY user_id (user_id),
        KEY order_id (order_id),
        KEY used_at (used_at)
    ) $charset_collate COMMENT='优惠券使用记录表';";
    
    if ($tododb->query($sql) === false) {
        error_log('创建优惠券使用记录表失败: ' . $tododb->last_error());
        return false;
    }
    
    // 套餐购买记录表
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}package_purchase_records (
        id int(11) NOT NULL AUTO_INCREMENT COMMENT '购买记录唯一标识ID',
        user_id int(11) NOT NULL COMMENT '购买用户ID',
        package_id int(11) NOT NULL COMMENT '关联的套餐ID',
        package_name varchar(255) NOT NULL COMMENT '套餐名称快照',
        package_type varchar(50) NOT NULL COMMENT '套餐类型（会员/服务等）',
        original_price decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '套餐原价',
        discount_price decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '折扣金额',
        final_price decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '实际支付金额',
        payment_method varchar(50) DEFAULT NULL COMMENT '支付方式代码',
        payment_status tinyint(1) NOT NULL DEFAULT 0 COMMENT '支付状态（0未支付/1已支付/2已退款）',
        order_no varchar(100) DEFAULT NULL COMMENT '关联订单号',
        transaction_id varchar(255) DEFAULT NULL COMMENT '第三方交易流水号',
        purchase_date timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '购买时间',
        expire_date timestamp NULL DEFAULT NULL COMMENT '套餐过期时间',
        status tinyint(1) NOT NULL DEFAULT 1 COMMENT '记录状态（1有效/0无效）',
        notes text COMMENT '购买备注信息',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间',
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '记录更新时间',
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY package_id (package_id),
        KEY payment_status (payment_status),
        KEY status (status),
        KEY order_no (order_no),
        KEY purchase_date (purchase_date)
    ) $charset_collate COMMENT='套餐购买记录表';";
    
    if ($tododb->query($sql) === false) {
        error_log('创建套餐购买记录表失败: ' . $tododb->last_error());
        return false;
    }
    
    // 订单日志表
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}order_logs (
        id int(11) NOT NULL AUTO_INCREMENT COMMENT '订单日志唯一标识ID',
        order_id int(11) NOT NULL COMMENT '关联的订单ID',
        action varchar(50) NOT NULL DEFAULT '' COMMENT '操作类型',
        message text COMMENT '日志消息',
        operator_id int(11) NOT NULL DEFAULT 0 COMMENT '操作者用户ID',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP COMMENT '日志创建时间',
        PRIMARY KEY (id),
        KEY order_id (order_id),
        KEY operator_id (operator_id),
        KEY created_at (created_at)
    ) $charset_collate COMMENT='订单日志表';";
    
    if ($tododb->query($sql) === false) {
        error_log('创建订单日志表失败: ' . $tododb->last_error());
        return false;
    }

    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}order_payments (
        id int(11) NOT NULL AUTO_INCREMENT COMMENT '支付记录唯一标识ID',
        order_id int(11) NOT NULL COMMENT '关联的订单ID',
        payment_method varchar(50) NOT NULL DEFAULT '' COMMENT '支付方式',
        amount decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '支付金额',
        transaction_id varchar(255) NOT NULL DEFAULT '' COMMENT '第三方交易流水号',
        payment_status varchar(20) NOT NULL DEFAULT 'pending' COMMENT '支付状态(pending/paid/refunded/failed)',
        notes text COMMENT '备注',
        payment_time datetime DEFAULT NULL COMMENT '支付时间',
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间',
        PRIMARY KEY (id),
        KEY order_id (order_id),
        KEY payment_status (payment_status),
        KEY payment_time (payment_time)
    ) $charset_collate COMMENT='订单支付记录表';";

    if ($tododb->query($sql) === false) {
        error_log('创建订单支付记录表失败: ' . $tododb->last_error());
        return false;
    }

    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}order_shipments (
        id int(11) NOT NULL AUTO_INCREMENT COMMENT '发货记录唯一标识ID',
        order_id int(11) NOT NULL COMMENT '关联的订单ID',
        express_company varchar(100) NOT NULL DEFAULT '' COMMENT '快递公司',
        tracking_no varchar(100) NOT NULL DEFAULT '' COMMENT '快递单号',
        shipping_address text COMMENT '收货地址快照',
        notes text COMMENT '备注',
        shipping_time datetime DEFAULT NULL COMMENT '发货时间',
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间',
        PRIMARY KEY (id),
        KEY order_id (order_id),
        KEY tracking_no (tracking_no),
        KEY shipping_time (shipping_time)
    ) $charset_collate COMMENT='订单发货记录表';";

    if ($tododb->query($sql) === false) {
        error_log('创建订单发货记录表失败: ' . $tododb->last_error());
        return false;
    }

    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}download_logs (
        id int(11) NOT NULL AUTO_INCREMENT COMMENT '下载记录唯一标识ID',
        order_id int(11) NOT NULL COMMENT '订单ID',
        product_id int(11) NOT NULL COMMENT '商品ID',
        file_path varchar(255) NOT NULL DEFAULT '' COMMENT '文件路径',
        file_name varchar(255) NOT NULL DEFAULT '' COMMENT '文件名称',
        download_time datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '下载时间',
        ip_address varchar(45) NOT NULL DEFAULT '' COMMENT '下载IP',
        PRIMARY KEY (id),
        KEY order_id (order_id),
        KEY product_id (product_id),
        KEY download_time (download_time)
    ) $charset_collate COMMENT='下载记录表';";

    if ($tododb->query($sql) === false) {
        error_log('创建下载记录表失败: ' . $tododb->last_error());
        return false;
    }

    // 订单物流表
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}order_logistics (
        id int(11) NOT NULL AUTO_INCREMENT COMMENT '订单物流记录唯一标识ID',
        order_id int(11) NOT NULL COMMENT '关联的订单ID',
        express_company varchar(100) NOT NULL DEFAULT '' COMMENT '快递公司名称',
        tracking_number varchar(100) NOT NULL DEFAULT '' COMMENT '快递单号',
        tracking_info text COMMENT '物流跟踪信息(文本或JSON)',
        status varchar(20) NOT NULL DEFAULT 'created' COMMENT '物流状态(created/shipping/delivered/exception)',
        created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间(发货时间)',
        updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '记录更新时间',
        PRIMARY KEY (id),
        KEY order_id (order_id),
        KEY tracking_number (tracking_number),
        KEY status (status)
    ) $charset_collate COMMENT='订单物流表';";

    if ($tododb->query($sql) === false) {
        error_log('创建订单物流表失败: ' . $tododb->last_error());
        return false;
    }

    // 订单自定义字段表
    $sql = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}order_custom_fields (
        id int(11) NOT NULL AUTO_INCREMENT COMMENT '自定义字段ID',
        order_id int(11) NOT NULL COMMENT '订单ID',
        field_name varchar(100) NOT NULL COMMENT '字段名',
        field_value text COMMENT '字段值',
        created_at timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        updated_at timestamp NULL DEFAULT NULL COMMENT '更新时间',
        PRIMARY KEY (id),
        UNIQUE KEY uniq_order_field (order_id, field_name),
        KEY order_id (order_id),
        KEY field_name (field_name)
    ) $charset_collate COMMENT='订单自定义字段表';";

    if ($tododb->query($sql) === false) {
        error_log('创建订单自定义字段表失败: ' . $tododb->last_error());
        return false;
    }
    
    return true;
    } catch (Exception $e) {
        error_log('创建商城表异常: ' . $e->getMessage());
        return false;
    }
}

// 注意：函数定义完成，由install.php调用执行
