<?php
/**
 * TodoCMS 多语言扩展数据表（自定义字段 / 术语 / 选项）
 */

if ((!defined('ABSPATH') || !defined('TODO_VERSION')) && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access denied.');
}

function todo_create_multilang_extra_tables() {
    global $tododb;
    $charset_collate = $tododb->get_charset_collate();

    // 文章自定义字段翻译表
    $sql_post_cf_trans = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}post_custom_field_translations (
        id int(11) NOT NULL AUTO_INCREMENT,
        post_id bigint(20) UNSIGNED NOT NULL COMMENT '文章ID',
        model_id int(11) DEFAULT NULL COMMENT '模型ID（可选）',
        field_key varchar(191) NOT NULL COMMENT '字段键',
        language_code varchar(10) NOT NULL COMMENT '语言代码',
        field_value longtext COMMENT '字段值（序列化）',
        status enum('pending','approved','draft','rejected') DEFAULT 'pending' COMMENT '状态',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY post_field_lang (post_id, field_key, language_code),
        KEY post_id (post_id),
        KEY language_code (language_code),
        FOREIGN KEY (language_code) REFERENCES {$tododb->prefix}languages(code) ON UPDATE CASCADE,
        FOREIGN KEY (post_id) REFERENCES {$tododb->prefix}posts(ID) ON DELETE CASCADE
    ) $charset_collate COMMENT='文章自定义字段翻译表';";

    // 术语翻译表（含 SEO 与属性JSON）
    $sql_term_trans = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}term_translations (
        id int(11) NOT NULL AUTO_INCREMENT,
        term_id bigint(20) UNSIGNED NOT NULL COMMENT '术语ID',
        taxonomy varchar(50) NOT NULL DEFAULT 'category' COMMENT '分类法',
        language_code varchar(10) NOT NULL COMMENT '语言代码',
        name varchar(255) DEFAULT NULL COMMENT '名称翻译',
        description text DEFAULT NULL COMMENT '描述翻译',
        seo_title varchar(255) DEFAULT NULL COMMENT 'SEO标题翻译',
        seo_keywords varchar(255) DEFAULT NULL COMMENT 'SEO关键词翻译',
        seo_description text DEFAULT NULL COMMENT 'SEO描述翻译',
        attributes longtext DEFAULT NULL COMMENT '自定义属性（JSON）翻译',
        status enum('pending','approved','draft','rejected') DEFAULT 'pending' COMMENT '状态',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY term_lang (term_id, taxonomy, language_code),
        KEY term_id (term_id),
        KEY language_code (language_code),
        FOREIGN KEY (language_code) REFERENCES {$tododb->prefix}languages(code) ON UPDATE CASCADE,
        FOREIGN KEY (term_id) REFERENCES {$tododb->prefix}terms(term_id) ON DELETE CASCADE
    ) $charset_collate COMMENT='术语多语言翻译表';";

    // 选项翻译表（后台自定义变量）
    $sql_option_trans = "CREATE TABLE IF NOT EXISTS {$tododb->prefix}option_translations (
        id int(11) NOT NULL AUTO_INCREMENT,
        option_key varchar(191) NOT NULL COMMENT '选项键',
        language_code varchar(10) NOT NULL COMMENT '语言代码',
        option_value longtext DEFAULT NULL COMMENT '选项值（序列化）',
        status enum('pending','approved','draft','rejected') DEFAULT 'approved' COMMENT '状态',
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY opt_lang (option_key, language_code),
        KEY language_code (language_code),
        FOREIGN KEY (language_code) REFERENCES {$tododb->prefix}languages(code) ON UPDATE CASCADE
    ) $charset_collate COMMENT='选项多语言翻译表';";

    require_once(ABSPATH . 'admin/includes/upgrade.php');
    dbDelta($sql_post_cf_trans);
    dbDelta($sql_term_trans);
    dbDelta($sql_option_trans);
}

// 注册到安装钩子
if (function_exists('add_action')) {
    add_action('todo_create_tables', 'todo_create_multilang_extra_tables');
}

?>
