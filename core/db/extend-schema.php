<?php
/**
 * 扩展模块数据表结构定义
 * 
 * @package TodoCMS
 * @subpackage Database
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access denied');
}

// 引入插件元数据表结构定义
require_once __DIR__ . '/plugin-meta-schema.php';

/**
 * 创建扩展功能相关数据表
 */
function create_extend_tables()
{
    global $tododb;
    $charset_collate = $tododb->get_charset_collate();

    try {
        // 广告表
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "ads (
        id int(11) NOT NULL AUTO_INCREMENT,
        group_id int(11) DEFAULT 0 COMMENT '广告分组ID',
        title varchar(255) NOT NULL COMMENT '广告标题',
        type varchar(20) DEFAULT 'image' COMMENT '广告类型:image图片,text文字,code代码,video视频,gallery轮播',
        content longtext COMMENT '广告内容',
        image_url varchar(500) DEFAULT '' COMMENT '图片地址',
        images text COMMENT '多图片地址JSON格式',
        link_url varchar(500) DEFAULT '' COMMENT '链接地址',
        target varchar(10) DEFAULT '_blank' COMMENT '打开方式',
        is_white_text tinyint(1) DEFAULT 0 COMMENT '是否白色文字(用于轮播图文字颜色)',
        start_date datetime NULL COMMENT '开始日期',
        end_date datetime NULL COMMENT '结束日期',
        start_time time NULL COMMENT '开始时间',
        end_time time NULL COMMENT '结束时间',
        sort_order int(11) DEFAULT 0 COMMENT '排序',
        status tinyint(1) DEFAULT 1 COMMENT '状态',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY group_id (group_id),
        KEY type (type),
        KEY status (status),
        KEY start_date (start_date),
        KEY end_date (end_date)
    ) $charset_collate COMMENT='广告表';";

        if ($tododb->query($sql) === false) {
            error_log('创建广告表失败: ' . $tododb->last_error());
            return false;
        }
        

        // 广告分组表
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "ad_groups (
        id int(11) NOT NULL AUTO_INCREMENT,
        name varchar(100) NOT NULL COMMENT '分组名称',
        code varchar(50) NOT NULL COMMENT '分组代码',
        description text COMMENT '分组描述',
        sort_order int(11) DEFAULT 0 COMMENT '排序',
        status tinyint(1) DEFAULT 1 COMMENT '状态',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY code (code),
        KEY status (status)
    ) $charset_collate COMMENT='广告分组表';";

        if ($tododb->query($sql) === false) {
            error_log('创建广告分组表失败: ' . $tododb->last_error());
            return false;
        }

        // 产品咨询表
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "product_inquiries (
        id int(11) NOT NULL AUTO_INCREMENT,
        product_id int(11) NOT NULL COMMENT '产品ID',
        user_id int(11) DEFAULT 0 COMMENT '用户ID',
        name varchar(100) NOT NULL COMMENT '咨询者姓名',
        email varchar(255) NOT NULL COMMENT '邮箱地址',
        phone varchar(20) DEFAULT '' COMMENT '联系电话',
        subject varchar(255) NOT NULL COMMENT '咨询主题',
        content text NOT NULL COMMENT '咨询内容',
        status varchar(20) DEFAULT 'pending' COMMENT '状态:pending待处理,approved已批准,spam垃圾,trash回收站',
        reply_content text COMMENT '回复内容',
        reply_user_id int(11) DEFAULT 0 COMMENT '回复用户ID',
        reply_time timestamp NULL COMMENT '回复时间',
        ip_address varchar(45) DEFAULT '' COMMENT 'IP地址',
        user_agent text COMMENT '用户代理',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY product_id (product_id),
        KEY user_id (user_id),
        KEY status (status),
        KEY email (email(191)),
        KEY created_at (created_at)
    ) $charset_collate COMMENT='产品咨询表';";

        if ($tododb->query($sql) === false) {
            error_log('创建产品咨询表失败: ' . $tododb->last_error());
            return false;
        }

        // 招聘投递表
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "recruit_applications (
        id int(11) NOT NULL AUTO_INCREMENT,
        post_id int(11) NOT NULL COMMENT '招聘内容ID',
        user_id int(11) NOT NULL DEFAULT 0 COMMENT '用户ID',
        name varchar(100) NOT NULL DEFAULT '' COMMENT '姓名',
        email varchar(255) NOT NULL DEFAULT '' COMMENT '邮箱',
        phone varchar(20) NOT NULL DEFAULT '' COMMENT '手机号',
        expected_salary varchar(100) NOT NULL DEFAULT '' COMMENT '期望薪资',
        introduction text COMMENT '自我介绍',
        resume_url varchar(500) NOT NULL DEFAULT '' COMMENT '简历文件URL',
        status varchar(20) NOT NULL DEFAULT 'new' COMMENT '状态:new新的,reviewed已读,contacted已联系,rejected已拒绝,hired已录用,deleted已删除',
        ip_address varchar(45) NOT NULL DEFAULT '' COMMENT 'IP地址',
        user_agent text COMMENT '用户代理',
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        PRIMARY KEY (id),
        KEY idx_post_id (post_id),
        KEY idx_user_id (user_id),
        KEY idx_status (status),
        KEY idx_created_at (created_at),
        KEY idx_email (email(191))
    ) $charset_collate COMMENT='招聘投递表';";

        if ($tododb->query($sql) === false) {
            error_log('创建招聘投递表失败: ' . $tododb->last_error());
            return false;
        }

        // 网站留言表
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "website_messages (
        id int(11) NOT NULL AUTO_INCREMENT,
        form_type varchar(50) DEFAULT 'contact' COMMENT '表单类型:contact联系表单,survey问卷调查,feedback意见反馈',
        user_id int(11) DEFAULT 0 COMMENT '用户ID',
        name varchar(100) NOT NULL COMMENT '留言者姓名',
        email varchar(255) NOT NULL COMMENT '邮箱地址',
        phone varchar(20) DEFAULT '' COMMENT '联系电话',
        subject varchar(255) DEFAULT '' COMMENT '留言主题',
        content text NOT NULL COMMENT '留言内容',
        custom_fields longtext COMMENT '自定义字段数据(JSON格式)',
        status varchar(20) DEFAULT 'unread' COMMENT '状态:unread未读,read已读,important重要,trash回收站',
        reply_content text COMMENT '回复内容',
        reply_user_id int(11) DEFAULT 0 COMMENT '回复用户ID',
        reply_time timestamp NULL COMMENT '回复时间',
        ip_address varchar(45) DEFAULT '' COMMENT 'IP地址',
        user_agent text COMMENT '用户代理',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY form_type (form_type),
        KEY user_id (user_id),
        KEY status (status),
        KEY email (email(191)),
        KEY created_at (created_at)
    ) $charset_collate COMMENT='网站留言表';";

        if ($tododb->query($sql) === false) {
            error_log('创建网站留言表失败: ' . $tododb->last_error());
            return false;
        }

        // 邮件订阅表
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "email_subscribers (
        id int(11) NOT NULL AUTO_INCREMENT,
        email varchar(191) NOT NULL COMMENT '邮箱地址',
        name varchar(100) NOT NULL DEFAULT '' COMMENT '订阅者姓名',
        status varchar(20) NOT NULL DEFAULT 'active' COMMENT '状态：active活跃，inactive非活跃，unsubscribed已取消',
        subscription_type varchar(50) NOT NULL DEFAULT 'newsletter' COMMENT '订阅类型',
        source varchar(100) NOT NULL DEFAULT 'website' COMMENT '订阅来源',
        ip_address varchar(45) NOT NULL DEFAULT '' COMMENT 'IP地址',
        confirmed_at datetime DEFAULT NULL COMMENT '确认时间',
        unsubscribed_at datetime DEFAULT NULL COMMENT '取消订阅时间',
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        PRIMARY KEY (id),
        UNIQUE KEY uk_email (email),
        KEY idx_status (status),
        KEY idx_subscription_type (subscription_type),
        KEY idx_created_at (created_at)
    ) $charset_collate COMMENT='邮件订阅表';";

        if ($tododb->query($sql) === false) {
            error_log('创建邮件订阅表失败: ' . $tododb->last_error());
            return false;
        }

        // 联系表单提交记录表
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "contact_submissions (
        id int(11) NOT NULL AUTO_INCREMENT,
        form_type varchar(50) NOT NULL DEFAULT 'contact' COMMENT '表单类型',
        user_id int(11) NOT NULL DEFAULT 0 COMMENT '用户ID',
        name varchar(100) NOT NULL DEFAULT '' COMMENT '姓名',
        email varchar(255) NOT NULL DEFAULT '' COMMENT '邮箱',
        phone varchar(20) NOT NULL DEFAULT '' COMMENT '电话',
        company varchar(255) NOT NULL DEFAULT '' COMMENT '公司',
        subject varchar(255) NOT NULL DEFAULT '' COMMENT '主题',
        message text COMMENT '消息内容',
        form_data text COMMENT '表单数据JSON',
        status varchar(20) NOT NULL DEFAULT 'new' COMMENT '状态：new新的，read已读，replied已回复，closed已关闭',
        priority varchar(20) NOT NULL DEFAULT 'normal' COMMENT '优先级：low低，normal正常，high高，urgent紧急',
        assigned_to int(11) NOT NULL DEFAULT 0 COMMENT '分配给用户ID',
        ip_address varchar(45) NOT NULL DEFAULT '' COMMENT 'IP地址',
        user_agent text COMMENT '用户代理',
        referrer varchar(500) NOT NULL DEFAULT '' COMMENT '来源页面',
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        PRIMARY KEY (id),
        KEY idx_form_type (form_type),
        KEY idx_user_id (user_id),
        KEY idx_status (status),
        KEY idx_priority (priority),
        KEY idx_assigned_to (assigned_to),
        KEY idx_created_at (created_at)
    ) $charset_collate COMMENT='联系表单提交记录表';";

        if ($tododb->query($sql) === false) {
            error_log('创建联系表单提交记录表失败: ' . $tododb->last_error());
            return false;
        }

        // 邮件发送记录表
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "email_logs (
        id int(11) NOT NULL AUTO_INCREMENT,
        direction varchar(10) NOT NULL DEFAULT 'out' COMMENT '方向:out发出,in收到',
        from_email varchar(255) NOT NULL DEFAULT '' COMMENT '发件人邮箱（收到邮件时为对方邮箱）',
        from_name varchar(100) DEFAULT '' COMMENT '发件人名称',
        to_email varchar(255) NOT NULL COMMENT '收件人邮箱',
        to_name varchar(100) DEFAULT '' COMMENT '收件人姓名',
        subject varchar(255) NOT NULL COMMENT '邮件主题',
        content longtext NOT NULL COMMENT '邮件内容',
        type varchar(50) DEFAULT 'newsletter' COMMENT '邮件类型:newsletter新闻通讯,notification通知,reply回复',
        status varchar(20) DEFAULT 'pending' COMMENT '状态:pending待发送,sent已发送,failed发送失败',
        error_message text COMMENT '错误信息',
        message_id varchar(255) DEFAULT NULL COMMENT 'Message-ID',
        in_reply_to varchar(255) DEFAULT NULL COMMENT 'In-Reply-To',
        `references` text DEFAULT NULL COMMENT 'References',
        related_type varchar(50) NOT NULL DEFAULT '' COMMENT '关联类型（如 product_inquiry）',
        related_id int(11) NOT NULL DEFAULT 0 COMMENT '关联ID',
        thread_key varchar(100) NOT NULL DEFAULT '' COMMENT '线程Key',
        imap_uid int(11) NOT NULL DEFAULT 0 COMMENT 'IMAP UID',
        imap_mailbox varchar(100) NOT NULL DEFAULT '' COMMENT 'IMAP Mailbox',
        received_at datetime NULL COMMENT '收到时间',
        synced_at datetime NULL COMMENT '同步时间',
        is_read tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否已读（系统内）',
        has_reply tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否已回复（对出站邮件）',
        replied_at datetime NULL COMMENT '回复时间',
        sent_at timestamp NULL COMMENT '发送时间',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY to_email (to_email(191)),
        KEY type (type),
        KEY status (status),
        KEY sent_at (sent_at),
        KEY idx_message_id (message_id(191)),
        KEY idx_thread_key (thread_key(50)),
        KEY idx_related (related_type(50), related_id),
        KEY idx_imap (imap_mailbox(50), imap_uid)
    ) $charset_collate COMMENT='邮件发送记录表';";

        if ($tododb->query($sql) === false) {
            error_log('创建邮件发送记录表失败: ' . $tododb->last_error());
            return false;
        }

        // 邮件模板表
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "email_templates (
            id int(11) NOT NULL AUTO_INCREMENT,
            slug varchar(50) NOT NULL COMMENT '标识',
            name varchar(100) NOT NULL COMMENT '名称',
            category varchar(50) DEFAULT '' COMMENT '分类',
            subject varchar(255) NOT NULL COMMENT '主题',
            sender_name varchar(100) DEFAULT NULL COMMENT '发件人',
            content longtext COMMENT '内容',
            variables text COMMENT '变量说明',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY category (category)
        ) $charset_collate COMMENT='邮件模板表';";

        if ($tododb->query($sql) === false) {
            error_log('创建邮件模板表失败: ' . $tododb->last_error());
            return false;
        }

        // 预置默认邮件模板数据
        $default_email_templates = [
            [
                'slug' => 'product_inquiry',
                'name' => '新询盘通知',
                'category' => 'product_inquiry',
                'subject' => '管理员，您有新的询盘',
                'sender_name' => 'TodoCMS',
                'content' => '<p>{email} 咨询 【{product_title}】，ID：{product_id}，URL：{product_url}，SITE：{site_name}</p><p>{name}: {content}<br/></p><p style="border-top: 1px solid #dcdcdc;"><br/>请协调并及时回复客户需求。</p>',
                'variables' => '{name}: {content} -- ',
            ],
            [
                'slug' => 'reply_product_inquiry',
                'name' => '产品询盘回复',
                'category' => 'inquiry_reply',
                'subject' => '回复您的关注',
                'sender_name' => 'TodoCMS',
                'content' => '<p>我们最新的产品预览：</p><p>。。。<br/></p><p style="border-top: 1px solid #dcdcdc;"><br/><a href="http://todocms.com" target="_self">www.todocms.com</a></p>',
                'variables' => '{reply_content}',
            ],
            [
                'slug' => 'guestbook',
                'name' => '新留言通知',
                'category' => 'guestbook',
                'subject' => '管理员，收到新的留言',
                'sender_name' => 'TodoCMS',
                'content' => '<p>{email} 主题：{subject} 内容：{message_content}，TIME：{created_at}，SITE：{site_name}<br/></p><p style="border-top: 1px solid #dcdcdc;"><br/>请理性回应。</p>',
                'variables' => '{name}: {message_content} -- ',
            ],
            [
                'slug' => 'comment',
                'name' => '新评论通知',
                'category' => 'comment',
                'subject' => '管理员，收到新的文章评论',
                'sender_name' => 'TodoCMS',
                'content' => '<p>{author_name} ({author_email}) 评论了文章 【{post_title}】：</p><p>{content}<br/></p><p style="border-top: 1px solid #dcdcdc;"><br/>文章链接：{post_url} | 时间：{created_at} | 站点：{site_name}</p>',
                'variables' => '{author_name}: {content} -- ',
            ],
            [
                'slug' => 'new_subscription',
                'name' => '新订阅通知',
                'category' => 'new_subscription',
                'subject' => '管理员，有新用户订阅',
                'sender_name' => 'TodoCMS',
                'content' => '<p>用户 {email} 通过 【{source}】 成功订阅。<br/></p><p style="border-top: 1px solid #dcdcdc;"><br/>订阅状态：{status} | 时间：{created_at}</p>',
                'variables' => '{email}: {created_at} -- ',
            ],
            [
                'slug' => 'admin_login_notify',
                'name' => '后台安全通知',
                'category' => 'admin_login_notify',
                'subject' => '【安全提醒】账号成功登录后台',
                'sender_name' => 'TodoCMS',
                'content' => '<p>管理员，账号 <strong>{username}</strong> 已成功登录系统后台。</p><p>登录时间：{login_time}<br/>IP地址：{ip}<br/>设备信息：{user_agent}<br/></p><p style="border-top: 1px solid #dcdcdc;"><br/>若非本人操作，请立即检查账号安全密码。</p>',
                'variables' => '{username}: {login_time}, {site_name} -- ',
            ],
            [
                'slug' => 'order_payment',
                'name' => '订单付款通知',
                'category' => 'order_payment',
                'subject' => '管理员，有新的订单付款',
                'sender_name' => 'TodoCMS',
                'content' => '<p>订单号 <strong>{order_no}</strong> 已完成付款！</p><p>支付方式：{payment_method}<br/>配送方式：{shipping_method}<br/>支付金额：{total_amount}<br/>买家账号：{buyer_name} ({buyer_email})<br/></p><p style="border-top: 1px solid #dcdcdc;"><br/>请及时前往后台处理订单发货。</p>',
                'variables' => '{order_no}, {total_amount}, {buyer_name}, {buyer_email}, {payment_method}, {shipping_method}, {created_at}, {site_name} -- ',
            ],
            [
                'slug' => 'order_shipping',
                'name' => '订单发货通知',
                'category' => 'order_shipping',
                'subject' => '管理员，订单发货通知',
                'sender_name' => 'TodoCMS',
                'content' => '<p>订单号 <strong>{order_no}</strong> 已完成发货出库。</p><p>快递公司：{express_company}<br/>快递单号：{express_no}<br/></p><p style="border-top: 1px solid #dcdcdc;"><br/>发货时间：{shipping_time} | 站点：{site_name}</p>',
                'variables' => '{order_no}, {express_company}, {shipping_time} -- ',
            ],
        ];

        foreach ($default_email_templates as $tpl) {
            $tpl_row = $tododb->get_row("SELECT id, content FROM " . TABLE_PREFIX . "email_templates WHERE slug = ?", [$tpl['slug']]);
            if (!$tpl_row) {
                $tododb->insert(TABLE_PREFIX . 'email_templates', array_merge($tpl, [
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]));
            } elseif ($tpl['slug'] === 'order_payment' && (strpos($tpl_row->content, '{payment_method}') === false || strpos($tpl_row->content, '{shipping_method}') === false)) {
                $tododb->update(TABLE_PREFIX . 'email_templates', [
                    'content' => $tpl['content'],
                    'variables' => $tpl['variables'],
                    'updated_at' => date('Y-m-d H:i:s'),
                ], ['id' => $tpl_row->id]);
            }
        }

        // 邮件草稿表
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "email_drafts (
            id int(11) NOT NULL AUTO_INCREMENT,
            recipient varchar(255) NOT NULL COMMENT '收件人',
            subject varchar(255) NOT NULL COMMENT '主题',
            preview_text text COMMENT '预览文本',
            content longtext COMMENT '内容',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate COMMENT='邮件草稿表';";

        if ($tododb->query($sql) === false) {
            error_log('创建邮件草稿表失败: ' . $tododb->last_error());
            return false;
        }

        // 邮件验证码表
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "email_codes (
            id int(11) NOT NULL AUTO_INCREMENT,
            email varchar(191) NOT NULL COMMENT '邮箱',
            code varchar(10) NOT NULL COMMENT '验证码',
            type varchar(20) NOT NULL DEFAULT 'register' COMMENT '类型:register注册,login登录,reset重置密码',
            expires_at datetime NOT NULL COMMENT '过期时间',
            used tinyint(1) DEFAULT 0 COMMENT '是否已使用',
            used_at datetime NULL COMMENT '使用时间',
            ip_address varchar(45) DEFAULT NULL COMMENT 'IP地址',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY email (email),
            KEY type (type),
            KEY expires_at (expires_at),
            KEY used (used)
        ) $charset_collate COMMENT='邮件验证码表';";

        if ($tododb->query($sql) === false) {
            error_log('创建邮件验证码表失败: ' . $tododb->last_error());
            return false;
        }

        // 访问统计表
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "visit_stats (
        id int(11) NOT NULL AUTO_INCREMENT,
        date date NOT NULL COMMENT '日期',
        page_url varchar(500) NOT NULL COMMENT '页面URL',
        page_title varchar(255) DEFAULT '' COMMENT '页面标题',
        ip_address varchar(45) NOT NULL COMMENT 'IP地址',
        user_agent text COMMENT '用户代理',
        referer varchar(500) DEFAULT '' COMMENT '来源页面',
        user_id int(11) DEFAULT 0 COMMENT '用户ID',
        session_id varchar(100) DEFAULT '' COMMENT '会话ID',
        visit_time timestamp DEFAULT CURRENT_TIMESTAMP COMMENT '访问时间',
        PRIMARY KEY (id),
        KEY date (date),
        KEY page_url (page_url(191)),
        KEY ip_address (ip_address),
        KEY user_id (user_id),
        KEY visit_time (visit_time)
    ) $charset_collate COMMENT='访问统计表';";

        if ($tododb->query($sql) === false) {
            error_log('创建访问统计表失败: ' . $tododb->last_error());
            return false;
        }

        // 搜索记录表
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "search_logs (
        id int(11) NOT NULL AUTO_INCREMENT,
        keyword varchar(255) NOT NULL COMMENT '搜索关键词',
        results_count int(11) DEFAULT 0 COMMENT '搜索结果数量',
        user_id int(11) DEFAULT 0 COMMENT '用户ID',
        ip_address varchar(45) DEFAULT '' COMMENT 'IP地址',
        user_agent text COMMENT '用户代理',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY keyword (keyword(191)),
        KEY user_id (user_id),
        KEY created_at (created_at)
    ) $charset_collate COMMENT='搜索记录表';";

        if ($tododb->query($sql) === false) {
            error_log('创建搜索记录表失败: ' . $tododb->last_error());
            return false;
        }

        // 搜索词聚合表（热搜/搜索次数）
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "search_hot (
        id int(11) NOT NULL AUTO_INCREMENT,
        keyword varchar(255) NOT NULL COMMENT '搜索关键词',
        search_count int(11) DEFAULT 1 COMMENT '搜索次数',
        results_count int(11) DEFAULT 0 COMMENT '最近搜索结果数',
        is_hot tinyint(1) DEFAULT 0 COMMENT '是否手动设为热搜',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY keyword (keyword(191))
    ) $charset_collate COMMENT='搜索词聚合表';";

        if ($tododb->query($sql) === false) {
            error_log('创建搜索词聚合表失败: ' . $tododb->last_error());
            return false;
        }

        // 搜索配置选项（使用 option 表存储，确保默认值存在）
        $search_config = function_exists('todo_get_option') ? todo_get_option('search_config', '') : '';
        if (empty($search_config)) {
            $defaults = [
                'search_mode'      => 'smart',   // smart | exact | segment
                'segment_match'    => 'any',      // any | all
                'max_results'      => 0,          // 最大搜索结果（0为不限制）
                'rate_limit'       => 60,         // 频率窗口（秒）
                'rate_max'         => 5,           // 窗口内最大搜索次数
                'lock_duration'    => 120,         // 超限锁定秒数
                'blocked_cats'     => [],          // 屏蔽栏目ID数组
                'sensitive_words'  => ['eval', 'call_user_func_array', 'file_put_contents', 'phpinfo', 'shell_exec'],
            ];
            if (function_exists('todo_set_option')) {
                todo_set_option('search_config', json_encode($defaults));
            }
        }

        // 友情链接表
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "links (
        id int(11) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL COMMENT '链接名称',
        url varchar(500) NOT NULL COMMENT '链接地址',
        description text COMMENT '链接描述',
        logo varchar(500) DEFAULT '' COMMENT 'LOGO地址',
        category varchar(50) DEFAULT 'general' COMMENT '链接分类',
        target varchar(10) DEFAULT '_blank' COMMENT '打开方式',
        sort_order int(11) DEFAULT 0 COMMENT '排序',
        status tinyint(1) DEFAULT 1 COMMENT '状态',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY category (category),
        KEY status (status)
    ) $charset_collate COMMENT='友情链接表';";

        if ($tododb->query($sql) === false) {
            error_log('创建友情链接表失败: ' . $tododb->last_error());
            return false;
        }

        // 菜单表
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "menus (
        id int(11) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL COMMENT '菜单名称',
        location varchar(100) DEFAULT '' COMMENT '菜单位置',
        description text COMMENT '菜单描述',
        status tinyint(1) DEFAULT 1 COMMENT '状态',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY location (location),
        KEY status (status)
    ) $charset_collate COMMENT='菜单表';";

        if ($tododb->query($sql) === false) {
            error_log('创建菜单表失败: ' . $tododb->last_error());
            return false;
        }

        // 菜单项表
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "menu_items (
        id int(11) NOT NULL AUTO_INCREMENT,
        menu_id int(11) NOT NULL COMMENT '菜单ID',
        parent_id int(11) DEFAULT 0 COMMENT '父级菜单项ID',
        title varchar(255) NOT NULL COMMENT '菜单项标题',
        url varchar(500) DEFAULT '' COMMENT '链接地址',
        type varchar(50) DEFAULT 'custom' COMMENT '菜单项类型',
        object_id int(11) DEFAULT 0 COMMENT '关联对象ID',
        css_class varchar(255) DEFAULT '' COMMENT 'CSS类名',
        target varchar(20) DEFAULT '_self' COMMENT '打开方式',
        icon text COMMENT '图标 (HTML/SVG)',
        description text COMMENT '描述',
        sort_order int(11) DEFAULT 0 COMMENT '排序',
        status tinyint(1) DEFAULT 1 COMMENT '状态',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY menu_id (menu_id),
        KEY parent_id (parent_id),
        KEY sort_order (sort_order),
        KEY status (status),
        FOREIGN KEY (menu_id) REFERENCES " . TABLE_PREFIX . "menus(id) ON DELETE CASCADE
    ) $charset_collate COMMENT='菜单项表';";

        if ($tododb->query($sql) === false) {
            error_log('创建菜单项表失败: ' . $tododb->last_error());
            return false;
        }

        // 预置默认主导航菜单数据
        $menu_exists = $tododb->get_var("SELECT id FROM " . TABLE_PREFIX . "menus WHERE location = 'primary' LIMIT 1");
        if (!$menu_exists) {
            $menu_id = $tododb->insert(TABLE_PREFIX . 'menus', [
                'name' => '主导航菜单',
                'location' => 'primary',
                'description' => '系统默认主导航菜单',
                'status' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            if ($menu_id) {
                $default_items = [
                    ['title' => '首页', 'url' => '/', 'type' => 'custom', 'object_id' => 0, 'sort_order' => 0],
                    ['title' => '文章', 'url' => '/articles/', 'type' => 'category', 'object_id' => 1, 'sort_order' => 1],
                    ['title' => '产品', 'url' => '/products/', 'type' => 'category', 'object_id' => 2, 'sort_order' => 2],
                    ['title' => '图集', 'url' => '/images/', 'type' => 'category', 'object_id' => 3, 'sort_order' => 3],
                    ['title' => '下载', 'url' => '/downloads/', 'type' => 'category', 'object_id' => 4, 'sort_order' => 4],
                    ['title' => '视频', 'url' => '/media/', 'type' => 'category', 'object_id' => 5, 'sort_order' => 5],
                    ['title' => '专题', 'url' => '/special/', 'type' => 'category', 'object_id' => 7, 'sort_order' => 6],
                    ['title' => '留言', 'url' => '/guestbook/', 'type' => 'category', 'object_id' => 8, 'sort_order' => 7],
                    ['title' => '招聘', 'url' => '/recruit/', 'type' => 'category', 'object_id' => 9, 'sort_order' => 8],
                ];

                foreach ($default_items as $item) {
                    $tododb->insert(TABLE_PREFIX . 'menu_items', array_merge($item, [
                        'menu_id' => $menu_id,
                        'parent_id' => 0,
                        'status' => 1,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]));
                }
            }
        }

        // 字段可见性表
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "field_visibility (
        id int(11) NOT NULL AUTO_INCREMENT,
        model_key varchar(50) NOT NULL COMMENT '模型标识',
        category_id int(11) NOT NULL COMMENT '栏目ID',
        field_key varchar(50) NOT NULL COMMENT '字段标识',
        is_visible tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否可见',
        created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY model_category_field (model_key, category_id, field_key),
        KEY model_key (model_key),
        KEY category_id (category_id)
    ) $charset_collate COMMENT='模型字段可见性设置';";

        if ($tododb->query($sql) === false) {
            error_log('创建字段可见性表失败: ' . $tododb->last_error());
            return false;
        }

        // 主题表
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "themes (
        id int(11) NOT NULL AUTO_INCREMENT,
        name varchar(100) NOT NULL DEFAULT '' COMMENT '主题名称',
        directory varchar(100) NOT NULL DEFAULT '' COMMENT '主题目录',
        description text COMMENT '主题描述',
        version varchar(20) DEFAULT '1.0.0' COMMENT '版本号',
        author varchar(100) DEFAULT '' COMMENT '作者',
        template_dir varchar(100) NOT NULL COMMENT '模板目录',
        screenshot varchar(255) DEFAULT '' COMMENT '预览图',
        status enum('active','inactive') DEFAULT 'inactive' COMMENT '状态',
        is_default tinyint(1) DEFAULT 0 COMMENT '是否默认主题',
        settings longtext COMMENT '主题设置',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY name (name),
        UNIQUE KEY directory (directory),
        KEY status (status)
    ) $charset_collate COMMENT='主题表';";

        if ($tododb->query($sql) === false) {
            error_log('创建主题表失败: ' . $tododb->last_error());
            return false;
        }

        // 插件表
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "plugins (
        id int(11) NOT NULL AUTO_INCREMENT,
        name varchar(100) NOT NULL DEFAULT '' COMMENT '插件名称',
        directory varchar(100) NOT NULL DEFAULT '' COMMENT '插件目录',
        description text COMMENT '插件描述',
        version varchar(20) DEFAULT '1.0.0' COMMENT '版本号',
        author varchar(100) DEFAULT '' COMMENT '作者',
        plugin_file varchar(255) NOT NULL COMMENT '插件文件',
        status enum('active','inactive') DEFAULT 'inactive' COMMENT '状态',
        settings longtext COMMENT '插件设置',
        activation_time timestamp NULL DEFAULT NULL COMMENT '激活时间',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY name (name),
        UNIQUE KEY directory (directory),
        KEY status (status)
    ) $charset_collate COMMENT='插件表';";

        if ($tododb->query($sql) === false) {
            error_log('创建插件表失败: ' . $tododb->last_error());
            return false;
        }

        // 定时任务表
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "cron_jobs (
        id int(11) NOT NULL AUTO_INCREMENT,
        name varchar(100) NOT NULL DEFAULT '' COMMENT '任务名称',
        hook varchar(100) NOT NULL COMMENT '钩子名称',
        schedule varchar(50) NOT NULL COMMENT '执行计划',
        args longtext COMMENT '参数',
        next_run datetime NOT NULL COMMENT '下次执行时间',
        last_run datetime NULL DEFAULT NULL COMMENT '上次执行时间',
        status enum('active','inactive','paused') DEFAULT 'active' COMMENT '状态',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY hook (hook),
        KEY status (status),
        KEY next_run (next_run)
    ) $charset_collate COMMENT='定时任务表';";

        if ($tododb->query($sql) === false) {
            error_log('创建定时任务表失败: ' . $tododb->last_error());
            return false;
        }

        // 主题元数据表
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "theme_meta (
        meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        theme_id bigint(20) unsigned NOT NULL,
        meta_key varchar(255) NOT NULL,
        meta_value longtext,
        PRIMARY KEY (meta_id),
        KEY theme_id (theme_id),
        KEY meta_key (meta_key(191))
    ) $charset_collate COMMENT='主题元数据表';";

        if ($tododb->query($sql) === false) {
            error_log('创建主题元数据表失败: ' . $tododb->last_error());
            return false;
        }

        // 创建字段生成器表
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "field_generator (
        id int(11) NOT NULL AUTO_INCREMENT,
        field_title varchar(255) NOT NULL COMMENT '字段标题',
        field_name varchar(100) NOT NULL COMMENT '字段名',
        data_type varchar(50) NOT NULL COMMENT '数据类型',
        default_value text COMMENT '默认值',
        field_note text COMMENT '字段说明',
        model_id int(11) NOT NULL COMMENT '关联数据模型ID',
        category_ids text COMMENT '指定栏目ID列表(JSON)',
        sort_order int(11) DEFAULT 0 COMMENT '排序',
        is_required tinyint(1) DEFAULT 0 COMMENT '是否必填',
        is_visible tinyint(1) DEFAULT 1 COMMENT '是否可见',
        field_options text COMMENT '字段选项配置(JSON)',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY field_name_model (field_name, model_id),
        KEY model_id (model_id),
        KEY sort_order (sort_order)
    ) $charset_collate COMMENT='字段生成器表';";

        if ($tododb->query($sql) === false) {
            error_log('创建字段生成器表失败: ' . $tododb->last_error());
            return false;
        }

        // 系统缓存表
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "cache (
        cache_key varchar(32) NOT NULL,
        cache_value longtext NOT NULL,
        expiration int(10) unsigned NOT NULL DEFAULT 0,
        created_at int(10) unsigned NOT NULL,
        PRIMARY KEY (cache_key),
        KEY idx_expiration (expiration)
    ) $charset_collate COMMENT='系统缓存表';";

        if ($tododb->query($sql) === false) {
            error_log('创建系统缓存表失败: ' . $tododb->last_error());
            return false;
        }

        // 邮件活动表
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "emails (
        id int(11) NOT NULL AUTO_INCREMENT,
        campaign_name varchar(255) NOT NULL COMMENT '活动名称',
        subject varchar(255) NOT NULL COMMENT '邮件主题',
        content text NOT NULL COMMENT '邮件内容',
        content_type varchar(20) DEFAULT 'html' COMMENT '内容格式:html/text',
        sender_name varchar(100) DEFAULT NULL COMMENT '发件人名称',
        sender_email varchar(255) DEFAULT NULL COMMENT '发件人邮箱',
        reply_to varchar(255) DEFAULT NULL COMMENT '回复邮箱',
        status varchar(20) DEFAULT 'draft' COMMENT '状态',
        target_type varchar(20) DEFAULT 'active' COMMENT '目标人群类型',
        target_criteria text DEFAULT NULL COMMENT '目标筛选条件JSON',
        scheduled_at datetime DEFAULT NULL COMMENT '计划发送时间',
        sent_at datetime DEFAULT NULL COMMENT '发送时间',
        total_recipients int(11) DEFAULT 0 COMMENT '总收件人数',
        successful_sent int(11) DEFAULT 0 COMMENT '成功人数',
        failed_sent int(11) DEFAULT 0 COMMENT '失败人数',
        open_count int(11) DEFAULT 0 COMMENT '打开数',
        click_count int(11) DEFAULT 0 COMMENT '点击数',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_status (status),
        KEY idx_scheduled_at (scheduled_at),
        KEY idx_created_at (created_at)
    ) $charset_collate COMMENT='邮件活动表';";

        if ($tododb->query($sql) === false) {
            error_log('创建邮件活动表失败: ' . $tododb->last_error());
            return false;
        }

        // GDPR Cookie 同意审计日志表
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "cookie_consent_logs (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id int(11) DEFAULT 0 COMMENT '用户ID（若已登录）',
        ip_address varchar(45) NOT NULL DEFAULT '' COMMENT '匿名化IP',
        user_agent varchar(255) DEFAULT '' COMMENT '浏览器UserAgent',
        consent_choice text NOT NULL COMMENT '同意选项JSON',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_user_id (user_id),
        KEY idx_created_at (created_at)
    ) $charset_collate COMMENT='GDPR Cookie 同意审计日志表';";

        if ($tododb->query($sql) === false) {
            error_log('创建 GDPR Cookie 同意审计日志表失败: ' . $tododb->last_error());
            return false;
        }

        // 创建插件元数据表
        if (!create_plugin_meta_table()) {
            error_log('创建插件元数据表失败');
            return false;
        }

        // 确保插件元数据表结构完整（唯一索引等）
        if (function_exists('update_plugin_meta_table_structure')) {
            update_plugin_meta_table_structure();
        }

        // 插入插件默认元数据（针对已激活插件，兼容升级场景）
        if (function_exists('insert_plugin_meta_default_data')) {
            insert_plugin_meta_default_data();
        }

        return true;

    } catch (Exception $e) {
        error_log('创建扩展功能表时发生异常: ' . $e->getMessage());
        return false;
    }
}

// 注意：函数定义完成，由系统安装器调用执行
