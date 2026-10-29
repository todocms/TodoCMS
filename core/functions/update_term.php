<?php
/**
 * 更新术语（分类或标签）函数
 *
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 更新术语（分类或标签）
 *
 * @param int $term_id 术语ID
 * @param string $taxonomy 分类法名称（category或tag）
 * @param array $args 更新参数
 * @return array|TODO_Error 更新后的术语数据或错误对象
 */
function update_term($term_id, $taxonomy, $args = array()) {
    global $tododb;
    
    // 验证参数
    $term_id = intval($term_id);
    if ($term_id <= 0) {
        return new TODO_Error('invalid_term_id', '无效的术语ID');
    }
    
    // 获取现有术语
    $term = get_term($term_id, $taxonomy);
    if (!$term) {
        return new TODO_Error('term_not_found', '术语不存在');
    }
    
    // 准备更新数据
    $update_terms = false;
    $update_taxonomy = false;
    $terms_data = [];
    $terms_params = [];
    $taxonomy_data = [];
    $taxonomy_params = [];
    
    // 更新名称
    if (isset($args['name']) && $args['name'] !== $term->name) {
        $name = sanitize_text_field($args['name']);
        
        // 检查名称是否已存在
        $check_query = "SELECT t.term_id FROM {$tododb->prefix}terms AS t "
                    . "INNER JOIN {$tododb->prefix}term_taxonomy AS tt ON t.term_id = tt.term_id "
                    . "WHERE t.name = ? AND tt.taxonomy = ? AND t.term_id != ? LIMIT 1";
        $existing_term = $tododb->get_row($check_query, [$name, $taxonomy, $term_id], 'ARRAY_A');
        
        if (!empty($existing_term)) {
            return new TODO_Error('term_exists', '同名术语已存在');
        }
        
        $update_terms = true;
        $terms_data[] = "name = ?";
        $terms_params[] = $name;
    }
    
    // 更新别名
    if (isset($args['slug'])) {
        $slug = sanitize_title($args['slug']);
        
        if ($slug !== $term->slug) {
            // 检查别名是否已存在
            $check_query = "SELECT t.term_id FROM {$tododb->prefix}terms AS t "
                        . "INNER JOIN {$tododb->prefix}term_taxonomy AS tt ON t.term_id = tt.term_id "
                        . "WHERE t.slug = ? AND tt.taxonomy = ? AND t.term_id != ? LIMIT 1";
            $existing_slug = $tododb->get_row($check_query, [$slug, $taxonomy, $term_id], 'ARRAY_A');
            
            if (!empty($existing_slug)) {
                // 生成唯一别名
                $slug = $slug . '-' . time();
            }
            
            $update_terms = true;
            $terms_data[] = "slug = ?";
            $terms_params[] = $slug;
        }
    }
    
    // 更新描述
    if (isset($args['description']) && $args['description'] !== $term->description) {
        $update_taxonomy = true;
        $taxonomy_data[] = "description = ?";
        $taxonomy_params[] = $args['description'];
    }
    
    // 更新父级
    if (isset($args['parent']) && intval($args['parent']) !== $term->parent) {
        $parent = intval($args['parent']);
        
        // 确保父级存在且不是自己
        if ($parent > 0 && $parent === $term_id) {
            return new TODO_Error('invalid_parent', '术语不能作为自己的父级');
        }
        
        // 如果父级不是0，确保父级存在
        if ($parent > 0) {
            $parent_term = get_term($parent, $taxonomy);
            if (!$parent_term) {
                return new TODO_Error('invalid_parent', '父级术语不存在');
            }
        }
        
        $update_taxonomy = true;
        $taxonomy_data[] = "parent = ?";
        $taxonomy_params[] = $parent;
    }
    
    // 如果没有要更新的内容，直接返回
    if (!$update_terms && !$update_taxonomy) {
        return $term;
    }
    
    // 开始事务
    $tododb->begin_transaction();
    
    try {
        // 更新terms表
        if ($update_terms && !empty($terms_data)) {
            $terms_query = "UPDATE {$tododb->prefix}terms SET " . implode(", ", $terms_data) . " WHERE term_id = ?";
            $terms_params[] = $term_id;
            $affected = $tododb->query($terms_query, $terms_params);
            if ($affected === false) {
                throw new Exception('Failed to update terms');
            }
        }
        
        // 更新term_taxonomy表
        if ($update_taxonomy && !empty($taxonomy_data)) {
            $taxonomy_query = "UPDATE {$tododb->prefix}term_taxonomy SET " . implode(", ", $taxonomy_data) . " WHERE term_id = ? AND taxonomy = ?";
            $taxonomy_params[] = $term_id;
            $taxonomy_params[] = $taxonomy;
            $affected2 = $tododb->query($taxonomy_query, $taxonomy_params);
            if ($affected2 === false) {
                throw new Exception('Failed to update term_taxonomy');
            }
        }
        
        // 提交事务
        $tododb->commit();

        // Phase 3: Site Content Isolation - 同步站点映射
        if (function_exists('todo_ensure_term_site_mapping')) {
            $uid_for_site_mapping = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
            $is_global_admin = false;
            if (function_exists('todo_is_super_admin') && todo_is_super_admin($uid_for_site_mapping ?: null)) {
                $is_global_admin = true;
            }
            if (!$is_global_admin && $uid_for_site_mapping > 0 && class_exists('Todo_Session_Manager') && method_exists('Todo_Session_Manager', 'can_access_admin')) {
                $is_global_admin = (bool) Todo_Session_Manager::can_access_admin($uid_for_site_mapping);
            }
            if (!$is_global_admin && function_exists('current_user_can') && current_user_can('manage_options')) {
                $is_global_admin = true;
            }
            $mapping_site_id = null;
            if (defined('IS_ADMIN') && IS_ADMIN && function_exists('todo_current_site_id')) {
                // 使用 fallback=false：global 数据作用域（无显式 ?site= 参数）下返回 0，
                // 避免错误回落到默认站点并追加站点映射，与 post.php 的处理保持一致。
                $mapping_site_id = (int) todo_current_site_id(false);
            } elseif (isset($args['site_id']) && is_numeric($args['site_id'])) {
                $mapping_site_id = (int) $args['site_id'];
            }

            if ($mapping_site_id !== null && $mapping_site_id > 0) {
                $mapping_args = ['site_id' => $mapping_site_id];
                if (isset($args['is_visible'])) $mapping_args['is_visible'] = $args['is_visible'];
                if (isset($args['site_order'])) $mapping_args['site_order'] = $args['site_order'];

                todo_ensure_term_site_mapping($term_id, $mapping_args);
            }
        }
        
        // 返回更新后的术语
        return get_term($term_id, $taxonomy);
    } catch (Exception $e) {
        // 回滚事务
        $tododb->rollback();
        return new TODO_Error('db_error', '数据库错误：' . $e->getMessage());
    }
}

/**
 * TodoCMS兼容函数 - 更新术语
 *
 * @param int $term_id 术语ID
 * @param string $taxonomy 分类法名称
 * @param array $args 更新参数
 * @return array|TODO_Error 更新后的术语数据或错误对象
 */
function todo_update_term($term_id, $taxonomy, $args = array()) {
    return update_term($term_id, $taxonomy, $args);
}
