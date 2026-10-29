<?php
/**
 * 分类属性表结构 Compatibility Wrapper
 * 统一归一化到 taxonomy-schema.php 集中维护
 *
 * @package TodoCMS
 * @subpackage Database
 */

// 防止直接访问
if (!defined('ABSPATH') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

require_once __DIR__ . '/taxonomy-schema.php';

/**
 * 创建分类属性表 (归一化调用 taxonomy-schema.php)
 *
 * @return bool 是否创建成功
 */
function create_term_attributes_table() {
    return create_term_meta_tables();
}

/**
 * 检查分类属性表是否存在 (归一化调用 taxonomy-schema.php)
 *
 * @return bool 表是否存在
 */
function term_attributes_table_exists() {
    return term_meta_table_exists();
}

/**
 * 初始化分类属性表 (归一化调用 taxonomy-schema.php)
 *
 * @return bool 是否初始化成功
 */
function init_term_attributes_tables() {
    if (!term_meta_table_exists()) {
        return create_term_meta_tables();
    }
    return true;
}
