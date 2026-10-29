<?php
/**
 * 分类系统数据库表结构
 *
 * 定义分类、标签等功能的数据库表结构
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
 * 创建分类系统数据库表
 *
 * @return bool 是否成功创建表
 */
function create_taxonomy_tables()
{
    global $tododb;

    try {
        // 分类表 - 存储栏目分类的基本信息
        $tododb->query("CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "terms (
            term_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '分类唯一标识ID',
            name varchar(191) NOT NULL DEFAULT '' COMMENT '分类名称',
            slug varchar(191) NOT NULL DEFAULT '' COMMENT '分类标识/别名',
            term_group bigint(10) NOT NULL DEFAULT 0 COMMENT '分类分组ID',
            PRIMARY KEY (term_id),
            KEY slug (slug),
            KEY name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='分类表';");

        // 分类法表 - 定义分类的层级结构和类型
        $tododb->query("CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "term_taxonomy (
            term_taxonomy_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '分类法唯一标识ID',
            term_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的分类ID',
            taxonomy varchar(32) NOT NULL DEFAULT '' COMMENT '分类法类型（如：category, post_tag）',
            description longtext NOT NULL COMMENT '分类描述',
            parent bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '父级分类ID',
            count bigint(20) NOT NULL DEFAULT 0 COMMENT '内容计数统计',
            term_order int(11) NOT NULL DEFAULT 0 COMMENT '排序权重',
            PRIMARY KEY (term_taxonomy_id),
            UNIQUE KEY term_id_taxonomy (term_id, taxonomy),
            KEY taxonomy (taxonomy)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='分类法表';");

        // 对象与分类关系表 - 建立内容与栏目分类的关联关系
        $tododb->query("CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "term_relationships (
            object_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '对象ID（如：文章ID）',
            term_taxonomy_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '分类法ID',
            term_order int(11) NOT NULL DEFAULT 0 COMMENT '排序权重',
            PRIMARY KEY (object_id, term_taxonomy_id),
            KEY term_taxonomy_id (term_taxonomy_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='对象与分类关系表';");

        return true;
    } catch (Exception $e) {
        error_log('创建分类系统数据库表异常: ' . $e->getMessage());
        return false;
    }
}

/**
 * 创建分类元数据表
 *
 * @return bool 是否成功创建表
 */
function create_term_meta_tables()
{
    global $tododb;

    try {
        // 分类属性表 - 存储分类的扩展属性 (使用JSON字段)
        $tododb->query("CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "term_attributes (
            term_id bigint(20) UNSIGNED NOT NULL,
            attributes JSON NOT NULL,
            is_hidden tinyint(1) DEFAULT 0,
            hidden_start datetime DEFAULT NULL,
            hidden_end datetime DEFAULT NULL,
            access_level varchar(50) DEFAULT 'public',
            list_template varchar(191) DEFAULT NULL,
            view_template varchar(191) DEFAULT NULL,
            category_image varchar(500) DEFAULT NULL,
            seo_title varchar(255) DEFAULT NULL,
            seo_keywords text,
            seo_description text,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (term_id),
            KEY is_hidden (is_hidden),
            KEY access_level (access_level),
            KEY list_template (list_template),
            KEY view_template (view_template)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='分类属性表';");

        return true;
    } catch (Exception $e) {
        error_log('创建分类属性表异常: ' . $e->getMessage());
        return false;
    }
}

/**
 * 检查分类属性表是否存在
 *
 * @return bool 表是否存在
 */
function term_meta_table_exists()
{
    global $tododb;

    try {
        $table_name = TABLE_PREFIX . 'term_attributes';
        $result = $tododb->query("SHOW TABLES LIKE '{$table_name}'");
        return !empty($result);
    } catch (Exception $e) {
        error_log('检查分类属性表存在性异常: ' . $e->getMessage());
        return false;
    }
}
