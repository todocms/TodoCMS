<?php
/**
 * 术语（分类、标签）插入函数
 *
 * 提供插入分类和标签的功能
 *
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 插入术语（分类或标签）
 *
 * @param string $name 术语名称
 * @param string $taxonomy 分类法（category或tag）
 * @param array $args 参数数组
 * @return array|TODO_Error 成功时返回术语ID和分类法ID，失败时返回错误对象
 */
function insert_term($name, $taxonomy, $args = array())
{
    global $tododb;

    // 检查参数
    if (empty($name)) {
        return new TODO_Error('empty_term_name', '术语名称不能为空');
    }

    if (empty($taxonomy)) {
        return new TODO_Error('empty_taxonomy', '分类法不能为空');
    }

    // 检查分类法是否有效
    $valid_taxonomies = array('category', 'tag');
    if (!in_array($taxonomy, $valid_taxonomies)) {
        return new TODO_Error('invalid_taxonomy', '无效的分类法');
    }

    // 默认参数
    $defaults = array(
        'slug' => '',
        'description' => '',
        'parent' => 0
    );

    $args = array_merge($defaults, $args);

    // 如果没有提供别名，根据名称生成
    if (empty($args['slug'])) {
        $args['slug'] = sanitize_title($name);
    }

    try {
        // 开始事务
        $tododb->begin_transaction();

        // 检查同名术语是否存在
        $check_query = "SELECT t.term_id FROM {$tododb->prefix}terms AS t "
            . "INNER JOIN {$tododb->prefix}term_taxonomy AS tt ON t.term_id = tt.term_id "
            . "WHERE t.name = ? AND tt.taxonomy = ? LIMIT 1";
        $existing_term = $tododb->get_row($check_query, [$name, $taxonomy]);

        if (!empty($existing_term)) {
            return new TODO_Error('term_exists', '同名术语已存在');
        }

        // 检查同别名术语是否存在
        $check_slug_query = "SELECT t.term_id FROM {$tododb->prefix}terms AS t "
            . "INNER JOIN {$tododb->prefix}term_taxonomy AS tt ON t.term_id = tt.term_id "
            . "WHERE t.slug = ? AND tt.taxonomy = ? LIMIT 1";
        $existing_slug = $tododb->get_row($check_slug_query, [$args['slug'], $taxonomy]);

        if (!empty($existing_slug)) {
            // 生成唯一别名
            $args['slug'] = $args['slug'] . '-' . time();
        }

        // 插入terms表
        $insert_term_query = "INSERT INTO {$tododb->prefix}terms (name, slug) VALUES (?, ?)";
        $tododb->query($insert_term_query, [$name, $args['slug']]);

        $term_id = $tododb->insert_id();

        if (!$term_id) {
            throw new Exception('插入术语失败');
        }

        // 插入term_taxonomy表
        $insert_taxonomy_query = "INSERT INTO {$tododb->prefix}term_taxonomy (term_id, taxonomy, description, parent, count) VALUES (?, ?, ?, ?, 0)";
        $tododb->query($insert_taxonomy_query, [$term_id, $taxonomy, $args['description'], $args['parent']]);

        $term_taxonomy_id = $tododb->insert_id();

        if (!$term_taxonomy_id) {
            throw new Exception('插入分类法失败');
        }

        // 提交事务
        $tododb->commit();

        // Phase 3: Site Content Isolation - 关联当前站点
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
            if (defined('IS_ADMIN') && IS_ADMIN) {
                // 全局视图（无显式 ?site= 参数）→ 不绑定任何站点，分类全局可见
                // 只有在特定站点管理视图（?site=N）下才绑定该站点
                if (!empty($_GET['site']) && is_numeric($_GET['site']) && (int)$_GET['site'] > 0) {
                    $mapping_site_id = (int) $_GET['site'];
                }
            } elseif (isset($args['site_id']) && is_numeric($args['site_id'])) {
                $mapping_site_id = (int) $args['site_id'];
            }

            if ($mapping_site_id !== null && $mapping_site_id > 0) {
                $mapping_args = ['site_id' => $mapping_site_id];
                if (isset($args['is_visible'])) $mapping_args['is_visible'] = $args['is_visible'];
                if (isset($args['site_order'])) $mapping_args['site_order'] = $args['site_order'];

                todo_ensure_term_site_mapping($term_id, $mapping_args);
            }
            // $mapping_site_id 为 null（全局视图）→ 不写入映射 → 分类对所有站点可见
        }

        // 返回结果
        return array(
            'term_id' => $term_id,
            'term_taxonomy_id' => $term_taxonomy_id
        );
    } catch (Exception $e) {
        // 回滚事务
        $tododb->rollback();

        return new TODO_Error('db_error', $e->getMessage());
    }
}

/**
 * 插入术语（分类或标签）的别名
 */
if (!function_exists('todo_insert_term')) {
    function todo_insert_term($name, $taxonomy, $args = array())
    {
        return insert_term($name, $taxonomy, $args);
    }
}
