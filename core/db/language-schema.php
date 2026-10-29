<?php
/**
 * 语言系统数据库表结构
 * 
 * @package TodoCMS
 * @subpackage Database
 */

// 防止直接访问（安装阶段允许绕过）
if ((!defined('ABSPATH') || !defined('TODO_VERSION')) && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access denied.');
}

/**
 * 创建语言系统相关数据表
 */
function todo_create_language_tables()
{
    global $tododb;

    $charset_collate = $tododb->get_charset_collate();

    // 语言表
    $sql_languages = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}languages (
        id int(11) NOT NULL AUTO_INCREMENT COMMENT '语言ID',
        code varchar(10) NOT NULL COMMENT '语言代码（如：zh_CN, en_US）',
        locale varchar(10) DEFAULT NULL COMMENT 'Locale（如：zh-CN, en-US）',
        name varchar(100) NOT NULL COMMENT '语言名称',
        native_name varchar(100) NOT NULL COMMENT '本地化显示名称',
        flag varchar(50) DEFAULT NULL COMMENT '图标或国旗标识',
        direction enum('ltr','rtl') DEFAULT 'ltr' COMMENT '文字方向(从左到右/从右到左)',
        status enum('active','inactive') DEFAULT 'active' COMMENT '启用状态',
        is_default tinyint(1) DEFAULT 0 COMMENT '是否默认语言',
        sort_order int(11) DEFAULT 0 COMMENT '排序权重',
        currency_code varchar(10) DEFAULT NULL COMMENT '货币代码',
        currency_exchange_rate decimal(10,4) DEFAULT 1.0000 COMMENT '汇率',
        currency_status tinyint(1) DEFAULT 1 COMMENT '货币状态',
        is_default_currency tinyint(1) DEFAULT 0 COMMENT '是否默认货币',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        PRIMARY KEY (id),
        UNIQUE KEY code (code),
        KEY status (status),
        KEY is_default (is_default)
    ) $charset_collate COMMENT='基本语言定义表';";

    // 语言术语表（新增 theme 列并使用 (file_path, term_key, theme) 组合唯一约束，实现硬隔离）
    $sql_language_terms = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}language_terms (
        id int(11) NOT NULL AUTO_INCREMENT,
        term_key varchar(80) NOT NULL COMMENT '术语唯一键',
        original_text text NOT NULL COMMENT '原始文本',
        description text COMMENT '描述',
        context varchar(191) DEFAULT NULL COMMENT '上下文',
        domain varchar(100) DEFAULT 'default' COMMENT '文本域（保留）',
        theme varchar(40) NOT NULL DEFAULT 'default' COMMENT '所属主题',
        auto_detected tinyint(1) DEFAULT 0 COMMENT '是否自动检测',
        file_path varchar(500) DEFAULT NULL COMMENT '文件路径',
        line_number int(11) DEFAULT NULL COMMENT '行号',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY term_file_theme (file_path(80), term_key, theme),
        KEY context (context),
        KEY domain (domain),
        KEY theme (theme),
        KEY auto_detected (auto_detected),
        FULLTEXT KEY original_text (original_text)
    ) $charset_collate COMMENT='语言术语表';";

    // 语言翻译表（初始化即包含 is_selected 字段与非唯一组合索引，支持版本选用）
    $sql_language_translations = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}language_translations (
        id int(11) NOT NULL AUTO_INCREMENT,
        term_id int(11) NOT NULL COMMENT '术语ID',
        language_code varchar(10) NOT NULL COMMENT '语言代码',
        translated_text text NOT NULL COMMENT '翻译文本',
        translator_notes text COMMENT '翻译备注',
        status enum('pending','approved','rejected') DEFAULT 'pending' COMMENT '翻译状态',
        is_selected tinyint(1) DEFAULT 0 COMMENT '是否当前选用版本',
        translator_id int(11) DEFAULT NULL COMMENT '翻译者ID',
        reviewer_id int(11) DEFAULT NULL COMMENT '审核者ID',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY term_language (term_id, language_code),
        KEY term_lang_selected (term_id, language_code, is_selected),
        KEY language_code (language_code),
        KEY status (status),
        KEY translator_id (translator_id),
        FOREIGN KEY (term_id) REFERENCES {$tododb->prefix}language_terms(id) ON DELETE CASCADE,
        FOREIGN KEY (language_code) REFERENCES {$tododb->prefix}languages(code) ON UPDATE CASCADE
    ) $charset_collate COMMENT='语言翻译表';";

    // 语言缓存表
    $sql_language_cache = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}language_cache (
        id int(11) NOT NULL AUTO_INCREMENT,
        language_code varchar(10) NOT NULL COMMENT '语言代码',
        cache_key varchar(191) NOT NULL COMMENT '缓存键',
        cache_data longtext NOT NULL COMMENT '缓存数据',
        expires_at timestamp NULL DEFAULT NULL COMMENT '过期时间',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY language_key (language_code, cache_key),
        KEY expires_at (expires_at)
    ) $charset_collate COMMENT='语言缓存表';";

    // 文章翻译表（用于文章内容与SEO字段的多语言）
    $sql_post_translations = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}post_translations (
        id int(11) NOT NULL AUTO_INCREMENT,
        post_id bigint(20) UNSIGNED NOT NULL COMMENT '文章ID',
        language_code varchar(10) NOT NULL COMMENT '语言代码',
        title text COMMENT '标题翻译',
        content longtext COMMENT '正文翻译',
        excerpt text COMMENT '摘要翻译',
        seo_title varchar(255) DEFAULT NULL COMMENT 'SEO标题',
        seo_keywords varchar(255) DEFAULT NULL COMMENT 'SEO关键词',
        seo_description text COMMENT 'SEO描述',
        status enum('pending','approved','draft','rejected') DEFAULT 'pending' COMMENT '翻译状态',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY post_lang (post_id, language_code),
        KEY language_code (language_code),
        FOREIGN KEY (language_code) REFERENCES {$tododb->prefix}languages(code) ON UPDATE CASCADE,
        FOREIGN KEY (post_id) REFERENCES {$tododb->prefix}posts(ID) ON DELETE CASCADE
    ) $charset_collate COMMENT='文章多语言翻译表';";

    $sql_sites = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}sites (
        id int(11) NOT NULL AUTO_INCREMENT,
        slug varchar(80) NOT NULL COMMENT '站点标识',
        name varchar(191) NOT NULL COMMENT '站点名称',
        site_level varchar(50) DEFAULT '' COMMENT '站点等级',
        status enum('active','inactive') DEFAULT 'active' COMMENT '状态',
        is_default tinyint(1) DEFAULT 0 COMMENT '是否默认站点',
        default_locale varchar(10) DEFAULT NULL COMMENT '默认语言',
        fallback_locale varchar(10) DEFAULT NULL COMMENT '回退语言',
        site_logo varchar(255) DEFAULT NULL COMMENT '站点Logo',
        service_slogan varchar(255) DEFAULT NULL COMMENT '服务宗旨',
        service_coords varchar(100) DEFAULT NULL COMMENT '服务坐标',
        service_address text DEFAULT NULL COMMENT '服务地址',
        config longtext DEFAULT NULL COMMENT '站点配置(JSON)',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY slug (slug),
        KEY status (status),
        KEY is_default (is_default),
        KEY default_locale (default_locale),
        KEY fallback_locale (fallback_locale),
        FOREIGN KEY (default_locale) REFERENCES {$tododb->prefix}languages(code) ON UPDATE CASCADE,
        FOREIGN KEY (fallback_locale) REFERENCES {$tododb->prefix}languages(code) ON UPDATE CASCADE
    ) $charset_collate COMMENT='站点/门店表';";

    $sql_user_site_roles = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}user_site_roles (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id bigint(20) UNSIGNED NOT NULL COMMENT '用户ID',
        site_id int(11) NOT NULL COMMENT '站点ID',
        role varchar(50) NOT NULL COMMENT '角色名称',
        status enum('active','inactive') DEFAULT 'active' COMMENT '状态',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY user_site (user_id, site_id),
        KEY user_id (user_id),
        KEY site_id (site_id)
    ) $charset_collate COMMENT='用户站点角色表';";

    $sql_site_posts = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}site_posts (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        site_id int(11) NOT NULL COMMENT '站点ID',
        post_id bigint(20) UNSIGNED NOT NULL COMMENT '文章ID',
        status varchar(20) DEFAULT 'publish' COMMENT '文章在此站点的状态',
        is_visible tinyint(1) DEFAULT 1 COMMENT '是否在此站点可见',
        site_order int(11) DEFAULT 0 COMMENT '站点内排序',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY site_post (site_id, post_id),
        KEY site_id (site_id),
        KEY post_id (post_id),
        KEY status (status),
        KEY is_visible (is_visible),
        FOREIGN KEY (site_id) REFERENCES {$tododb->prefix}sites(id) ON DELETE CASCADE
    ) $charset_collate COMMENT='站点-文章映射表';";
    $sql_site_terms = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}site_terms (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        site_id int(11) NOT NULL COMMENT '站点ID',
        term_id bigint(20) UNSIGNED NOT NULL COMMENT '术语/分类ID',
        is_visible tinyint(1) DEFAULT 1 COMMENT '是否在此站点可见',
        site_order int(11) DEFAULT 0 COMMENT '站点内排序',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY site_term (site_id, term_id),
        KEY site_id (site_id),
        KEY term_id (term_id),
        KEY is_visible (is_visible),
        FOREIGN KEY (site_id) REFERENCES {$tododb->prefix}sites(id) ON DELETE CASCADE
    ) $charset_collate COMMENT='站点-术语映射表';";
    if (file_exists(ABSPATH . 'admin/includes/upgrade.php')) {
        require_once ABSPATH . 'admin/includes/upgrade.php';
    }
    dbDelta($sql_languages);
    dbDelta($sql_language_terms);
    dbDelta($sql_language_translations);
    dbDelta($sql_language_cache);
    dbDelta($sql_post_translations);
    dbDelta($sql_sites);
    dbDelta($sql_user_site_roles);
    dbDelta($sql_site_posts);
    dbDelta($sql_site_terms);

    // 插入默认全局站点
    $site_exists = $tododb->get_var("SELECT id FROM {$tododb->prefix}sites WHERE id = 1");
    if (!$site_exists) {
        $tododb->insert("{$tododb->prefix}sites", [
            'id' => 1,
            'name' => '默认站点',
            'slug' => 'main',
            'status' => 'active',
            'is_default' => 1,
            'created_at' => current_time('mysql')
        ]);
    }

    // 插入基础语言
    // 插入基础语言 (zh-CN)
    $lang_exists = $tododb->get_var("SELECT id FROM {$tododb->prefix}languages WHERE code = 'zh-CN'");
    if (!$lang_exists) {
        $tododb->insert("{$tododb->prefix}languages", [
            'name' => '简体中文',
            'native_name' => '简体中文',
            'code' => 'zh-CN',
            'locale' => 'zh-CN',
            'is_default' => 1,
            'status' => 'active',
            'sort_order' => 1
        ]);
    }

    // 插入基础语言 (en-US)
    $en_exists = $tododb->get_var("SELECT id FROM {$tododb->prefix}languages WHERE code = 'en-US'");
    if (!$en_exists) {
        $tododb->insert("{$tododb->prefix}languages", [
            'name' => 'English',
            'native_name' => 'English',
            'code' => 'en-US',
            'locale' => 'en-US',
            'is_default' => 0,
            'status' => 'active',
            'sort_order' => 2
        ]);
    }
}

// 注册创建函数
add_action('todo_create_tables', 'todo_create_language_tables');
