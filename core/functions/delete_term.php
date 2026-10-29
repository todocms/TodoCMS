<?php
/**
 * 删除术语（分类或标签）函数
 *
 * 提供删除分类和标签的功能
 *
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 删除术语（分类或标签）
 *
 * @param int $term_id 术语ID
 * @param string $taxonomy 分类法名称（category或tag）
 * @return bool|TODO_Error 成功返回true，失败返回错误对象
 */
function delete_term($term_id, $taxonomy) {
    global $tododb;
    
    // 验证参数
    $term_id = intval($term_id);
    if ($term_id <= 0) {
        return new TODO_Error('invalid_term_id', '无效的术语ID');
    }
    
    // 检查分类法是否有效
    $valid_taxonomies = array('category', 'tag');
    if (!in_array($taxonomy, $valid_taxonomies)) {
        return new TODO_Error('invalid_taxonomy', '无效的分类法');
    }
    
    // 获取现有术语
    $term = get_term($term_id, $taxonomy);
    if (!$term) {
        return new TODO_Error('term_not_found', '术语不存在');
    }
    
    // 获取术语分类法ID
    $term_taxonomy_id = $tododb->get_var(
        "SELECT term_taxonomy_id FROM {$tododb->prefix}term_taxonomy WHERE term_id = ? AND taxonomy = ?",
        [$term_id, $taxonomy]
    );
    
    if (!$term_taxonomy_id) {
        return new TODO_Error('term_taxonomy_not_found', '术语分类法不存在');
    }
    
    // 开始事务
    $tododb->begin_transaction();
    
    try {
        // 删除术语关联的模型
        delete_term_model($term_id);
        
        // 删除术语字段可见性设置
        $tododb->query("DELETE FROM {$tododb->prefix}term_field_visibility WHERE term_id = ?", [$term_id]);
        
        // 删除术语关系
        $tododb->query("DELETE FROM {$tododb->prefix}term_relationships WHERE term_taxonomy_id = ?", [$term_taxonomy_id]);
        
        // 删除术语分类法
        $tododb->query("DELETE FROM {$tododb->prefix}term_taxonomy WHERE term_taxonomy_id = ?", [$term_taxonomy_id]);
        
        // 删除术语
        $tododb->query("DELETE FROM {$tododb->prefix}terms WHERE term_id = ?", [$term_id]);
        
        // 提交事务
        $tododb->commit();
        
        return true;
    } catch (Exception $e) {
        // 回滚事务
        $tododb->rollback();
        return new TODO_Error('db_error', '数据库错误：' . $e->getMessage());
    }
}

/**
 * TodoCMS兼容函数 - 删除术语
 *
 * @param int $term_id 术语ID
 * @param string $taxonomy 分类法名称
 * @return bool|TODO_Error 成功返回true，失败返回错误对象
 */
function todo_delete_term($term_id, $taxonomy) {
    return delete_term($term_id, $taxonomy);
}

/**
 * 将术语移至回收站（软删除）
 *
 * @param int $term_id 术语ID
 * @param string $taxonomy 分类法名称（category或tag）
 * @return bool|TODO_Error 成功返回true，失败返回错误对象
 */
function trash_term($term_id, $taxonomy) {
    // 验证参数
    $term_id = intval($term_id);
    if ($term_id <= 0) {
        return new TODO_Error('invalid_term_id', '无效的术语ID');
    }
    
    $valid_taxonomies = array('category', 'tag');
    if (!in_array($taxonomy, $valid_taxonomies)) {
        return new TODO_Error('invalid_taxonomy', '无效的分类法');
    }
    
    // 确认术语存在
    $term = get_term($term_id, $taxonomy);
    if (!$term) {
        return new TODO_Error('term_not_found', '术语不存在');
    }
    
    // 标记为已移至回收站
    $now = date('Y-m-d H:i:s');
    update_term_meta($term_id, 'trashed', 1);
    update_term_meta($term_id, 'trashed_at', $now);
    update_term_meta($term_id, 'trashed_taxonomy', $taxonomy);
    
    return true;
}

/**
 * 从回收站恢复术语
 *
 * @param int $term_id 术语ID
 * @param string $taxonomy 分类法名称（category或tag）
 * @return bool|TODO_Error 成功返回true，失败返回错误对象
 */
function untrash_term($term_id, $taxonomy) {
    // 验证参数
    $term_id = intval($term_id);
    if ($term_id <= 0) {
        return new TODO_Error('invalid_term_id', '无效的术语ID');
    }
    
    $valid_taxonomies = array('category', 'tag');
    if (!in_array($taxonomy, $valid_taxonomies)) {
        return new TODO_Error('invalid_taxonomy', '无效的分类法');
    }
    
    // 确认术语存在
    $term = get_term($term_id, $taxonomy);
    if (!$term) {
        return new TODO_Error('term_not_found', '术语不存在');
    }
    
    // 取消回收站标记
    delete_term_meta($term_id, 'trashed');
    delete_term_meta($term_id, 'trashed_at');
    delete_term_meta($term_id, 'trashed_taxonomy');
    
    return true;
}

/** 兼容函数 */
function todo_trash_term($term_id, $taxonomy) {
    return trash_term($term_id, $taxonomy);
}
function todo_untrash_term($term_id, $taxonomy) {
    return untrash_term($term_id, $taxonomy);
}