<?php
/**
 * 数据库表结构定义
 *
 * 定义CMS系统的所有数据库表结构，包括：
 * - 核心系统表（用户、文章、评论等）
 * - 数据模型表
 * - 商城系统表
 * - 会员系统表
 * - 扩展功能表（广告、菜单等）
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

/**
 * 创建所有数据库表
 *
 * @return bool 是否成功创建表
 */
function create_all_tables()
{
    global $tododb;

    // 开始事务
    if (!$tododb->begin_transaction()) {
        error_log('无法开始事务');
        return false;
    }

    try {
        // 创建核心表
        if (!create_core_tables()) {
            throw new Exception('创建核心表失败');
        }

        // 创建数据模型表
        if (!create_model_tables()) {
            throw new Exception('创建数据模型表失败');
        }

        // 创建term meta表
        if (!create_term_meta_tables()) {
            throw new Exception('创建term meta表失败');
        }

        // 创建商城表
        if (!create_shop_tables()) {
            throw new Exception('创建商城表失败');
        }

        // 创建会员表
        if (!create_member_tables()) {
            throw new Exception('创建会员表失败');
        }

        // 创建扩展功能表
        if (!create_extend_tables()) {
            throw new Exception('创建扩展功能表失败');
        }

        // 提交事务
        if (!$tododb->commit()) {
            throw new Exception('提交事务失败');
        }

        return true;
    } catch (Exception $e) {
        error_log('创建数据库表失败: ' . $e->getMessage());
        $tododb->rollback();
        return false;
    }
}

/**
 * 创建核心系统表
 *
 * @return bool 是否成功创建表
 */
function create_core_tables()
{
    global $tododb;

    $charset_collate = $tododb->get_charset_collate();

    try {
        // 用户表 - 存储系统用户基本信息
        $tododb->query("CREATE TABLE IF NOT EXISTS {$tododb->prefix}users (
            ID bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '用户唯一标识ID',
            user_login varchar(60) NOT NULL DEFAULT '' COMMENT '用户登录名/用户名',
            user_pass varchar(255) NOT NULL DEFAULT '' COMMENT '用户密码（加密存储）',
            user_nicename varchar(50) NOT NULL DEFAULT '' COMMENT '用户昵称（URL友好格式）',
            user_email varchar(100) NOT NULL DEFAULT '' COMMENT '用户邮箱地址',
            balance decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '账户余额',
            user_registered datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '用户注册时间',
            user_activation_key varchar(255) NOT NULL DEFAULT '' COMMENT '用户激活密钥（用于邮箱验证等）',
            user_status int(11) NOT NULL DEFAULT 0 COMMENT '用户状态：0正常/1禁用/2待激活',
            display_name varchar(250) NOT NULL DEFAULT '' COMMENT '用户显示名称',
            PRIMARY KEY (ID),
            KEY user_login_key (user_login),
            KEY user_nicename (user_nicename),
            KEY user_email (user_email)
        ) $charset_collate COMMENT='用户表';");

        // 用户元数据表 - 存储用户扩展信息和自定义字段
        $tododb->query("CREATE TABLE IF NOT EXISTS {$tododb->prefix}user_meta (
            umeta_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '用户元数据唯一标识ID',
            user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的用户ID',
            meta_key varchar(255) DEFAULT NULL COMMENT '元数据键名（如：phone、avatar、level等）',
            meta_value longtext COMMENT '元数据值（支持长文本和序列化数据）',
            PRIMARY KEY (umeta_id),
            KEY user_id (user_id),
            KEY meta_key (meta_key(191))
        ) $charset_collate COMMENT='用户元数据表';");

        // 用户会话表 - 管理用户登录会话和安全状态
        $tododb->query("CREATE TABLE IF NOT EXISTS {$tododb->prefix}user_sessions (
            session_id varchar(128) NOT NULL COMMENT '会话唯一标识符',
            user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的用户ID',
            session_data longtext COMMENT '会话数据（序列化存储）',
            ip_address varchar(45) NOT NULL DEFAULT '' COMMENT '用户IP地址（支持IPv4和IPv6）',
            user_agent text COMMENT '用户浏览器User-Agent信息',
            last_activity datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最后活动时间',
            expires_at datetime NOT NULL COMMENT '会话过期时间',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '会话创建时间',
            PRIMARY KEY (session_id),
            KEY user_id (user_id),
            KEY last_activity (last_activity),
            KEY expires_at (expires_at)
        ) $charset_collate COMMENT='用户会话表';");

        // 记住登录令牌表 - 管理持久化登录令牌
        $tododb->query("CREATE TABLE IF NOT EXISTS {$tododb->prefix}remember_tokens (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '令牌记录唯一标识ID',
            user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的用户ID',
            token varchar(128) NOT NULL COMMENT '记住登录令牌',
            purpose varchar(32) NOT NULL DEFAULT '' COMMENT '用途标记：remember正常登录/impersonate模拟登录等',
            expires_at datetime NOT NULL COMMENT '令牌过期时间',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '令牌创建时间',
            PRIMARY KEY (id),
            UNIQUE KEY token (token),
            KEY user_id (user_id),
            KEY expires_at (expires_at)
        ) $charset_collate COMMENT='记住登录令牌表';");

        // 文章表 - 专门存储文章模型数据
        // 第一层：todo_posts - 所有内容发布的基础表，通过model_id区分类型
        $tododb->query("CREATE TABLE IF NOT EXISTS {$tododb->prefix}posts (
            ID bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '文章唯一标识ID',
            post_title text COMMENT '文章标题',
            post_content longtext NOT NULL COMMENT '文章正文内容',
            post_excerpt text COMMENT '文章摘要/简介',
            post_status varchar(20) NOT NULL DEFAULT 'publish' COMMENT '文章状态：publish发布/draft草稿/private私有/trash回收站',
            post_author bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '文章作者用户ID',
            category_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '文章所属栏目ID',
            featured_image text COMMENT '特色图片JSON数据（支持单图和多图）',
            view_count bigint(20) NOT NULL DEFAULT 0 COMMENT '文章浏览次数',
            sort_order int(11) NOT NULL DEFAULT 0 COMMENT '排序权重',
            
            -- 关联模型字段
            model_id bigint(20) UNSIGNED NOT NULL DEFAULT 1 COMMENT '关联的数据模型ID（通过此字段区分内容类型）',
            post_type varchar(50) NOT NULL DEFAULT 'article' COMMENT '内容类型：article文章/product产品/images图集/download下载/media视频/single单页/special专题/guestbook留言/recruit招聘',
            
            -- SEO相关字段
            seo_title varchar(255) NOT NULL DEFAULT '' COMMENT 'SEO标题',
            seo_keywords varchar(500) NOT NULL DEFAULT '' COMMENT 'SEO关键词',
            seo_description text NOT NULL COMMENT 'SEO描述',
            
            -- 扩展字段
            like_count bigint(20) NOT NULL DEFAULT 0 COMMENT '文章点赞次数',
            comment_status varchar(20) NOT NULL DEFAULT 'open' COMMENT '评论状态：open开启/closed关闭',
            comment_count bigint(20) NOT NULL DEFAULT 0 COMMENT '评论数量统计',
            doc_properties text COMMENT '文档属性ID数组（序列化存储）',
            custom_url varchar(255) NOT NULL DEFAULT '' COMMENT '自定义URL地址',
            post_name varchar(191) NOT NULL DEFAULT '' COMMENT '文章别名/URL slug',
            read_permission varchar(50) NOT NULL DEFAULT 'public' COMMENT '阅读权限：public公开/自定义等级/admin管理员',
            visibility varchar(20) NOT NULL DEFAULT 'public' COMMENT '可见性：public公开/readable可读/password密码/private私有',
            post_password varchar(255) NOT NULL DEFAULT '' COMMENT '文章密码（仅当可见性为 password 时生效）',
            view_template varchar(100) NOT NULL DEFAULT '' COMMENT '视图模版文件名（可覆盖模型默认模版）',
            
            -- 时间字段
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '文章创建时间',
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '文章更新时间',
            post_date datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '文章发布时间（本地时间）',
            post_date_gmt datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '文章发布时间（GMT时间）',
            post_modified datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '文章最后修改时间（本地时间）',
            post_modified_gmt datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '文章最后修改时间（GMT时间）',
            PRIMARY KEY (ID),
            KEY post_name (post_name),
            KEY status_date (post_status,post_date,ID),
            KEY idx_type_status_vis_date (post_type,post_status,visibility,post_date,ID),
            KEY idx_model_status_vis_date (model_id,post_status,visibility,post_date,ID),
            KEY idx_status_vis_date (post_status,visibility,post_date,ID),
            KEY idx_status_vis_views (post_status,visibility,view_count),
            KEY idx_type_status_vis_views (post_type,post_status,visibility,view_count),
            KEY idx_type_status_vis_sort (post_type,post_status,visibility,sort_order,post_date,post_date_gmt),
            KEY post_author (post_author),
            KEY model_id (model_id),
            KEY post_type (post_type),
            KEY category_id (category_id),
            KEY sort_order (sort_order),
            KEY read_permission (read_permission),
            KEY visibility (visibility),
            KEY view_template (view_template)
        ) $charset_collate COMMENT='文章基础表-第一层：存储所有内容，通过model_id区分类型';");

        // 文章元数据表 - 存储文章的扩展信息和自定义字段
        $tododb->query("CREATE TABLE IF NOT EXISTS {$tododb->prefix}postmeta (
            meta_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '文章元数据唯一标识ID',
            post_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的文章ID',
            meta_key varchar(255) DEFAULT NULL COMMENT '元数据键名',
            meta_value longtext COMMENT '元数据值',
            PRIMARY KEY (meta_id),
            KEY post_id (post_id),
            KEY idx_post_key (post_id, meta_key(191)),
            KEY meta_key (meta_key(191))
        ) $charset_collate COMMENT='文章元数据表';");

        // 评论表 - 存储用户评论、留言和产品咨询
        $tododb->query("CREATE TABLE IF NOT EXISTS {$tododb->prefix}comments (
            comment_ID bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '评论唯一标识ID',
            comment_post_ID bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的文章/产品ID',
            comment_author tinytext NOT NULL COMMENT '评论者姓名',
            comment_author_email varchar(100) NOT NULL DEFAULT '' COMMENT '评论者邮箱地址',
            comment_author_url varchar(200) NOT NULL DEFAULT '' COMMENT '评论者网站URL',
            comment_author_IP varchar(100) NOT NULL DEFAULT '' COMMENT '评论者IP地址',
            comment_date datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '评论发表时间',
            comment_content text NOT NULL COMMENT '评论内容',
            comment_type varchar(20) NOT NULL DEFAULT '' COMMENT '评论类型：comment/review/inquiry/message/reply等',
            comment_approved varchar(20) NOT NULL DEFAULT '1' COMMENT '评论审核状态：1已审核/0待审核/spam垃圾评论',
            comment_rating tinyint(2) NOT NULL DEFAULT 0 COMMENT '评分（用于评价/星级）',
            comment_karma int(11) NOT NULL DEFAULT 0 COMMENT '评论权重/karma',
            comment_agent varchar(255) NOT NULL DEFAULT '' COMMENT 'User-Agent',
            admin_reply text DEFAULT NULL COMMENT '管理员回复内容',
            replied_at datetime DEFAULT NULL COMMENT '管理员回复时间',
            comment_parent bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '父评论ID（用于回复评论）',
            user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '评论者用户ID（注册用户）',
            PRIMARY KEY (comment_ID),
            KEY comment_post_ID (comment_post_ID),
            KEY comment_approved_date (comment_approved,comment_date),
            KEY comment_parent (comment_parent),
            KEY comment_author_email (comment_author_email(10)),
            KEY comment_type (comment_type),
            KEY comment_rating (comment_rating)
        ) $charset_collate COMMENT='评论表';");

        // 评论元数据表 - 存储评论的扩展信息和自定义字段
        $tododb->query("CREATE TABLE IF NOT EXISTS {$tododb->prefix}commentmeta (
            meta_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '评论元数据唯一标识ID',
            comment_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的评论ID',
            meta_key varchar(255) DEFAULT NULL COMMENT '元数据键名（如：rating、helpful_count等）',
            meta_value longtext COMMENT '元数据值（支持长文本和序列化数据）',
            PRIMARY KEY (meta_id),
            KEY comment_id (comment_id),
            KEY meta_key (meta_key(191))
        ) $charset_collate COMMENT='评论元数据表';");

        // 定时任务表 - 管理系统定时任务和计划事件
        $tododb->query("CREATE TABLE IF NOT EXISTS {$tododb->prefix}scheduled_events (
            event_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '定时任务唯一标识ID',
            hook_name varchar(255) NOT NULL COMMENT '任务钩子名称',
            next_run datetime NOT NULL COMMENT '下次执行时间',
            recurrence varchar(50) NOT NULL DEFAULT 'once' COMMENT '重复频率：once单次/hourly每小时/daily每日/weekly每周',
            interval_seconds int(11) NOT NULL DEFAULT 0 COMMENT '执行间隔秒数',
            args longtext COMMENT '任务参数（序列化数据）',
            status varchar(20) NOT NULL DEFAULT 'active' COMMENT '任务状态：active活跃/paused暂停/completed完成',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '任务创建时间',
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '任务更新时间',
            PRIMARY KEY (event_id),
            KEY hook_name (hook_name(191)),
            KEY next_run (next_run),
            KEY status (status)
        ) $charset_collate COMMENT='定时任务表';");

        // 活动日志表 - 记录用户操作和系统活动日志
        $tododb->query("CREATE TABLE IF NOT EXISTS {$tododb->prefix}activity_log (
            log_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '日志唯一标识ID',
            user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作用户ID（0表示系统操作）',
            action varchar(100) NOT NULL COMMENT '操作动作：login登录/create创建/update更新/delete删除等',
            object_type varchar(50) NOT NULL COMMENT '操作对象类型：post文章/user用户/setting设置等',
            object_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作对象ID',
            result varchar(20) NOT NULL DEFAULT 'success' COMMENT '操作结果：success成功/failed失败/error错误',
            ip_address varchar(45) NOT NULL DEFAULT '' COMMENT '操作者IP地址',
            user_agent text COMMENT '操作者浏览器User-Agent信息',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '日志记录时间',
            PRIMARY KEY (log_id),
            KEY user_id (user_id),
            KEY action (action),
            KEY object_type (object_type),
            KEY object_id (object_id),
            KEY created_at (created_at)
        ) $charset_collate COMMENT='活动日志表';");

        // 标签表 - 存储内容标签，支持按栏目和模型分类管理
        $tododb->query("CREATE TABLE IF NOT EXISTS {$tododb->prefix}tags (
            tag_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '标签唯一标识ID',
            tag_title varchar(191) NOT NULL DEFAULT '' COMMENT '标签名称/标题',
            tag_slug varchar(191) NOT NULL DEFAULT '' COMMENT '标签别名/URL slug（唯一）',
            tag_desc text NOT NULL COMMENT '标签描述',
            tag_color varchar(7) NOT NULL DEFAULT '#007cba' COMMENT '标签颜色（十六进制色值）',
            tag_icon varchar(100) NOT NULL DEFAULT '' COMMENT '标签图标类名或图片路径',
            tag_media_directory varchar(255) NOT NULL DEFAULT '' COMMENT '绑定的媒体目录相对路径',
            auto_inlink int(11) NOT NULL DEFAULT 0 COMMENT '自动内链标签最大数量（0关闭）',
            auto_image int(11) NOT NULL DEFAULT 0 COMMENT '自动插图数量（受auto_inlink限制，0关闭）',
            tag_count bigint(20) NOT NULL DEFAULT 0 COMMENT '标签使用次数统计',
            tag_order int(11) NOT NULL DEFAULT 0 COMMENT '标签排序权重',
            tag_status varchar(20) NOT NULL DEFAULT 'active' COMMENT '标签状态：active活跃/inactive非活跃',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '标签创建时间',
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '标签更新时间',
            PRIMARY KEY (tag_id),
            UNIQUE KEY tag_slug (tag_slug),
            KEY tag_title (tag_title),
            KEY tag_status (tag_status),
            KEY tag_order (tag_order)
        ) $charset_collate COMMENT='标签表';");

        // 文章标签关联表 - 建立文章与标签的多对多关联关系
        $tododb->query("CREATE TABLE IF NOT EXISTS {$tododb->prefix}post_tags (
            relation_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '关联关系唯一标识ID',
            post_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的文章ID',
            tag_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的标签ID',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '关联关系创建时间',
            PRIMARY KEY (relation_id),
            UNIQUE KEY post_tag (post_id, tag_id),
            KEY post_id (post_id),
            KEY tag_id (tag_id)
        ) $charset_collate COMMENT='文章标签关联表';");

        // 内容标签关联表 - 建立通用内容（支持多模型）与标签的多对多关联关系
        $tododb->query("CREATE TABLE IF NOT EXISTS {$tododb->prefix}content_tags (
            relation_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '关联关系唯一标识ID',
            content_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的内容ID',
            tag_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的标签ID',
            model_key varchar(100) NOT NULL DEFAULT '' COMMENT '内容所属的模型标识',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '关联关系创建时间',
            PRIMARY KEY (relation_id),
            UNIQUE KEY content_tag_model (content_id, tag_id, model_key),
            KEY content_id (content_id),
            KEY tag_id (tag_id),
            KEY model_key (model_key)
        ) $charset_collate COMMENT='内容标签关联表';");

        // 标签统计表 - 记录标签在不同栏目和模型下的统计数据
        $tododb->query("CREATE TABLE IF NOT EXISTS {$tododb->prefix}tag_stats (
            stat_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '统计记录唯一标识ID',
            tag_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的标签ID',
            term_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的栏目ID（0表示全站统计）',
            model_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的数据模型ID（0表示全模型统计）',
            post_count bigint(20) NOT NULL DEFAULT 0 COMMENT '该标签下的文章数量',
            view_count bigint(20) NOT NULL DEFAULT 0 COMMENT '该标签的查看次数',
            last_updated datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '统计数据最后更新时间',
            PRIMARY KEY (stat_id),
            UNIQUE KEY tag_term_model (tag_id, term_id, model_id),
            KEY tag_id (tag_id),
            KEY term_id (term_id),
            KEY model_id (model_id)
        ) $charset_collate COMMENT='标签统计表';");

        // 标签分组表 - 管理标签的分组分类
        $tododb->query("CREATE TABLE IF NOT EXISTS {$tododb->prefix}tag_groups (
            group_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '标签分组唯一标识ID',
            group_name varchar(100) NOT NULL DEFAULT '' COMMENT '分组名称',
            group_slug varchar(100) NOT NULL DEFAULT '' COMMENT '分组别名/URL slug（唯一）',
            group_desc text NOT NULL COMMENT '分组描述',
            group_color varchar(7) NOT NULL DEFAULT '#666666' COMMENT '分组颜色（十六进制色值）',
            group_order int(11) NOT NULL DEFAULT 0 COMMENT '分组排序权重',
            group_status varchar(20) NOT NULL DEFAULT 'active' COMMENT '分组状态：active活跃/inactive非活跃',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '分组创建时间',
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '分组更新时间',
            PRIMARY KEY (group_id),
            UNIQUE KEY group_slug (group_slug),
            KEY group_name (group_name),
            KEY group_status (group_status)
        ) $charset_collate COMMENT='标签分组表';");

        // 标签分组关联表 - 建立标签与分组的多对多关联关系
        $tododb->query("CREATE TABLE IF NOT EXISTS {$tododb->prefix}tag_group_relationships (
            relationship_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '关联关系唯一标识ID',
            tag_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的标签ID',
            group_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的分组ID',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '关联关系创建时间',
            PRIMARY KEY (relationship_id),
            UNIQUE KEY tag_group (tag_id, group_id),
            KEY tag_id (tag_id),
            KEY group_id (group_id)
        ) $charset_collate COMMENT='标签分组关联表';");

        // 标签栏目关联表 - 建立标签与栏目的多对多关联关系
        $tododb->query("CREATE TABLE IF NOT EXISTS {$tododb->prefix}tag_category_relationships (
            relationship_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '关联关系唯一标识ID',
            tag_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的标签ID',
            term_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的栏目ID',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '关联关系创建时间',
            PRIMARY KEY (relationship_id),
            UNIQUE KEY tag_category (tag_id, term_id),
            KEY tag_id (tag_id),
            KEY term_id (term_id)
        ) $charset_collate COMMENT='标签栏目关联表';");

        // 标签数据模型关联表 - 建立标签与数据模型的多对多关联关系
        $tododb->query("CREATE TABLE IF NOT EXISTS {$tododb->prefix}tag_model_relationships (
            relationship_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '关联关系唯一标识ID',
            tag_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的标签ID',
            model_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的数据模型ID',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '关联关系创建时间',
            PRIMARY KEY (relationship_id),
            UNIQUE KEY tag_model (tag_id, model_id),
            KEY tag_id (tag_id),
            KEY model_id (model_id)
        ) $charset_collate COMMENT='标签数据模型关联表';");

        // 媒体文件表 - 专门管理上传的媒体文件
        $tododb->query("CREATE TABLE IF NOT EXISTS {$tododb->prefix}media (
            media_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '媒体文件唯一标识ID',
            site_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '站点ID（0=全局）',
            media_title varchar(255) NOT NULL DEFAULT '' COMMENT '媒体文件标题',
            media_filename varchar(255) NOT NULL DEFAULT '' COMMENT '原始文件名',
            media_path varchar(191) NOT NULL DEFAULT '' COMMENT '文件相对路径',
            media_url varchar(500) NOT NULL DEFAULT '' COMMENT '文件访问URL',
            media_type varchar(100) NOT NULL DEFAULT '' COMMENT '文件MIME类型',
            media_size bigint(20) NOT NULL DEFAULT 0 COMMENT '文件大小（字节）',
            media_width int(11) DEFAULT NULL COMMENT '图片宽度（像素）',
            media_height int(11) DEFAULT NULL COMMENT '图片高度（像素）',
            media_alt varchar(255) NOT NULL DEFAULT '' COMMENT '图片替代文本',
            media_caption text COMMENT '媒体说明文字',
            media_description text COMMENT '媒体文件描述',
            seo_title varchar(255) NOT NULL DEFAULT '' COMMENT 'SEO标题',
            seo_description text COMMENT 'SEO描述',
            seo_keywords varchar(500) NOT NULL DEFAULT '' COMMENT 'SEO关键字',
            upload_user bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '上传用户ID',
            upload_time datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '上传时间',
            usage_count int(11) NOT NULL DEFAULT 0 COMMENT '使用次数',
            download_count int(11) NOT NULL DEFAULT 0 COMMENT '下载次数',
            is_attached tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否被引用（1是/0否）',
            media_status varchar(20) NOT NULL DEFAULT 'active' COMMENT '媒体状态：active/inactive/deleted',
            thumbhash text COMMENT 'ThumbHash/MicroBlur占位符编码',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
            PRIMARY KEY (media_id),
            KEY site_id (site_id),
            UNIQUE KEY media_path (media_path),
            KEY media_type (media_type),
            KEY upload_user (upload_user),
            KEY upload_time (upload_time),
            KEY media_status (media_status),
            KEY is_attached (is_attached)
        ) $charset_collate COMMENT='媒体文件表';");

        // 媒体元数据表 - 存储媒体文件的扩展元数据 (如存储提供商、额外参数)
        $tododb->query("CREATE TABLE IF NOT EXISTS {$tododb->prefix}media_meta (
            meta_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '元数据唯一标识ID',
            media_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '对应媒体文件ID',
            meta_key varchar(191) NOT NULL DEFAULT '' COMMENT '元数据键名',
            meta_value longtext COMMENT '元数据内容',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
            PRIMARY KEY (meta_id),
            UNIQUE KEY media_meta_key (media_id, meta_key),
            KEY media_id (media_id),
            KEY meta_key (meta_key)
        ) $charset_collate COMMENT='媒体元数据表';");

        // 媒体未引用缓存表
        $tododb->query("CREATE TABLE IF NOT EXISTS {$tododb->prefix}media_unrecited_cache (
            media_id bigint(20) UNSIGNED NOT NULL COMMENT '媒体文件ID',
            computed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '计算时间',
            PRIMARY KEY (media_id),
            KEY idx_computed_at (computed_at)
        ) $charset_collate COMMENT='媒体未引用缓存表';");

        // 上传日志表
        $tododb->query("CREATE TABLE IF NOT EXISTS {$tododb->prefix}upload_logs (
            id int(11) NOT NULL AUTO_INCREMENT COMMENT '日志ID',
            log_type varchar(20) NOT NULL DEFAULT 'success' COMMENT '日志类型',
            user_id int(11) NOT NULL DEFAULT 0 COMMENT '用户ID',
            user_ip varchar(45) NOT NULL DEFAULT '' COMMENT '用户IP',
            user_agent text COMMENT 'User Agent',
            file_name varchar(255) NOT NULL DEFAULT '' COMMENT '文件名',
            file_path varchar(500) NOT NULL DEFAULT '' COMMENT '文件路径',
            file_size bigint(20) NOT NULL DEFAULT 0 COMMENT '文件大小',
            file_type varchar(100) NOT NULL DEFAULT '' COMMENT '文件类型',
            context varchar(100) NOT NULL DEFAULT '' COMMENT '上下文',
            field_name varchar(100) NOT NULL DEFAULT '' COMMENT '字段名称',
            media_id int(11) NOT NULL DEFAULT 0 COMMENT '媒体ID',
            message text COMMENT '消息记录',
            extra_data longtext COMMENT '额外扩展数据',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
            PRIMARY KEY (id),
            KEY idx_log_type (log_type),
            KEY idx_user_id (user_id),
            KEY idx_user_ip (user_ip),
            KEY idx_created_at (created_at),
            KEY idx_media_id (media_id)
        ) $charset_collate COMMENT='上传日志表';");

        // 系统配置表 - 存储系统核心配置和扩展参数设置
        $tododb->query("CREATE TABLE IF NOT EXISTS {$tododb->prefix}system_config (
            config_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '配置项唯一标识ID',
            config_key varchar(191) NOT NULL COMMENT '配置项键名（唯一标识）',
            config_value longtext COMMENT '配置项值（支持长文本）',
            config_type varchar(50) NOT NULL DEFAULT 'string' COMMENT '配置项数据类型：string/int/bool/json等',
            group_name varchar(100) NOT NULL DEFAULT 'general' COMMENT '配置分组名称',
            description text COMMENT '配置项描述说明',
            sort_order int(11) DEFAULT 0 COMMENT '配置项排序权重',
            is_system tinyint(1) DEFAULT 0 COMMENT '是否系统核心配置（1是/0否）',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '配置项创建时间',
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '配置项更新时间',
            PRIMARY KEY (config_id),
            UNIQUE KEY config_key (config_key),
            KEY group_name (group_name),
            KEY is_system (is_system)
        ) $charset_collate COMMENT='系统配置表';");

        // 插入默认系统配置项
        $default_configs = [
            [
                'config_key' => 'media_modal_notice_rules',
                'config_value' => "[选择图片|mail-media-modal|<span style=\"color:#f60;\">邮件内容中的文件/图片一定要使用绝对路径</span>]\r\n[选择插入图片|mail-media-modal|<span style=\"color:#f60;\">请使用绝对路径，才能在邮件中显示</span>]",
                'config_type' => 'string',
                'group_name' => 'media',
                'description' => '媒体模态框提示集',
                'is_system' => 1,
            ],
            [
                'config_key' => 'site_copyright',
                'config_value' => '&copy; {{todo:current_year}} by {{todo:site_title}}. {{todo:t text="保留所有权利"}}',
                'config_type' => 'string',
                'group_name' => 'general',
                'description' => '版权声明',
                'is_system' => 0,
            ],
            [
                'config_key' => 'unified_notice',
                'config_value' => '<b style="color: #be0000;">自定义注意事项。</b>' . "\r\n" . '[en|<b style="color: #be0000;">Customization Notes.</b>]',
                'config_type' => 'string',
                'group_name' => 'shop',
                'description' => '统一提示',
                'is_system' => 0,
            ],
        ];

        foreach ($default_configs as $cfg) {
            $cfg_exists = $tododb->get_var("SELECT config_id FROM {$tododb->prefix}system_config WHERE config_key = ?", [$cfg['config_key']]);
            if (!$cfg_exists) {
                $tododb->insert("{$tododb->prefix}system_config", array_merge($cfg, [
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ]));
            }
        }

        // 文档属性表 - 存储文档的预设属性和自定义属性
        $tododb->query("CREATE TABLE IF NOT EXISTS {$tododb->prefix}doc_properties (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '属性唯一标识ID',
            name varchar(100) NOT NULL DEFAULT '' COMMENT '属性名称（如：头条、推荐、加推等）',
            value varchar(100) NOT NULL DEFAULT '' COMMENT '属性值（如：1、0或其他自定义值）',
            display_name varchar(100) NOT NULL DEFAULT '' COMMENT '属性显示名称（前台显示用）',
            description text COMMENT '属性描述说明',
            is_system tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否系统预设属性（1是/0否）',
            is_active tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否启用（1启用/0禁用）',
            sort_order int(11) NOT NULL DEFAULT 0 COMMENT '排序权重',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '属性创建时间',
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '属性更新时间',
            PRIMARY KEY (id),
            UNIQUE KEY name (name),
            KEY is_system (is_system),
            KEY is_active (is_active),
            KEY sort_order (sort_order)
        ) $charset_collate COMMENT='文档属性表';");
        
        // 插入默认文档属性
        $default_properties = [
            ['name' => '头条', 'value' => 'headline', 'is_system' => 1, 'sort_order' => 1],
            ['name' => '推荐', 'value' => 'recommend', 'is_system' => 1, 'sort_order' => 2],
            ['name' => '加推', 'value' => 'featured', 'is_system' => 1, 'sort_order' => 3],
            ['name' => '标粗', 'value' => 'bold', 'is_system' => 1, 'sort_order' => 4],
            ['name' => '有图', 'value' => 'with_image', 'is_system' => 1, 'sort_order' => 5],
            ['name' => '外链', 'value' => 'external_link', 'is_system' => 1, 'sort_order' => 6],
            ['name' => '轮播', 'value' => 'carousel', 'is_system' => 1, 'sort_order' => 7],
            ['name' => '滚动', 'value' => 'scroll', 'is_system' => 1, 'sort_order' => 8],
            ['name' => 'AI内容', 'value' => 'ai_content', 'is_system' => 1, 'sort_order' => 9],
        ];

        foreach ($default_properties as $prop) {
            $prop_exists = $tododb->get_var("SELECT id FROM {$tododb->prefix}doc_properties WHERE value = ?", [$prop['value']]);
            if (!$prop_exists) {
                $tododb->insert("{$tododb->prefix}doc_properties", array_merge($prop, [
                    'display_name' => $prop['name'],
                    'is_active' => 1,
                    'created_at' => current_time('mysql')
                ]));
            }
        }

        // 第三层：文章自定义字段表 - 仅存储用户通过字段生成器创建的自定义字段
        // 注意：此表不应存储系统预定义字段（如featured_image、post_tags等）
        // 系统字段应直接存储在posts表中，临时数据不应存储在数据库中
        $tododb->query("CREATE TABLE IF NOT EXISTS {$tododb->prefix}post_custom_fields (
            field_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '字段唯一标识ID',
            post_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联文章ID',
            model_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的数据模型ID（通过此字段调用）',
            field_key varchar(191) NOT NULL DEFAULT '' COMMENT '字段键名（仅限用户自定义字段，最长191字符以兼容utf8mb4索引）',
            field_value longtext COMMENT '字段值（支持长文本和序列化数据）',
            field_type varchar(50) NOT NULL DEFAULT 'text' COMMENT '字段类型：text/number/json/array等',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '字段创建时间',
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '字段更新时间',
            PRIMARY KEY (field_id),
            KEY post_id (post_id),
            KEY model_id (model_id),
            KEY field_key (field_key),
            KEY post_key (post_id, field_key),
            KEY model_key (model_id, field_key),
            UNIQUE KEY unique_post_field (post_id, field_key) COMMENT '确保同一文章的字段键唯一'
        ) $charset_collate COMMENT='文章自定义字段表-第三层：仅存储用户自定义字段，不存储系统字段';");

        // 登录尝试记录表 - 用于防止暴力破解
        $tododb->query("CREATE TABLE IF NOT EXISTS {$tododb->prefix}login_attempts (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录唯一标识ID',
            login_name varchar(100) NOT NULL DEFAULT '' COMMENT '尝试登录的用户名',
            ip_address varchar(45) NOT NULL DEFAULT '' COMMENT '尝试登录的IP地址',
            attempt_time datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '尝试登录的时间',
            success tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否登录成功：1成功/0失败',
            PRIMARY KEY (id),
            KEY login_name (login_name),
            KEY ip_address (ip_address),
            KEY attempt_time (attempt_time),
            KEY combo_idx (login_name, ip_address, success, attempt_time)
        ) $charset_collate COMMENT='登录尝试记录表';");

        return true;
    } catch (Exception $e) {
        error_log('创建核心表失败: ' . $e->getMessage());
        return false;
    }
}

// 引入其他模块的表结构定义
require_once __DIR__ . '/model-schema.php';
require_once __DIR__ . '/shop-schema.php';
require_once __DIR__ . '/member-schema.php';
require_once __DIR__ . '/extend-schema.php';
