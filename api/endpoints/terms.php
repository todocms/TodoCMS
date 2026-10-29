<?php
/**
 * 分类和标签API端点
 *
 * 提供分类和标签相关的API端点
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

/**
 * 统一规范分类模型类型，避免空值被误判为商品分类。
 *
 * @param string $model_type
 * @return string
 */
function api_normalize_category_model_type($model_type) {
    $model_type = strtolower(trim((string) $model_type));

    if ($model_type === '' || $model_type === 'post') {
        return 'article';
    }

    return $model_type;
}

/**
 * 获取分类绑定的模型信息；若未显式绑定，则尝试按已发布内容类型推断。
 *
 * @param int $term_id
 * @return array
 */
function api_get_category_model_context($term_id) {
    global $tododb;

    $term_id = intval($term_id);
    $context = [
        'model_id' => 0,
        'model_type' => 'article',
        'model_key' => 'article',
        'is_inferred' => false,
    ];

    if ($term_id <= 0) {
        return $context;
    }

    if (function_exists('get_term_model')) {
        $model = get_term_model($term_id);
        if (!empty($model)) {
            $model_id = 0;
            $model_type = '';
            $model_key = '';

            if (is_array($model)) {
                $model_id = intval($model['model_id'] ?? 0);
                $model_type = $model['model_type'] ?? '';
                $model_key = $model['model_key'] ?? '';
            } elseif (is_object($model)) {
                $model_id = intval(isset($model->model_id) ? $model->model_id : 0);
                $model_type = isset($model->model_type) ? $model->model_type : '';
                $model_key = isset($model->model_key) ? $model->model_key : '';
            }

            $context['model_id'] = $model_id;
            $context['model_type'] = api_normalize_category_model_type($model_type ?: $model_key);
            $context['model_key'] = trim((string) ($model_key ?: $context['model_type']));

            if ($context['model_id'] > 0 || $context['model_key'] !== '' || $context['model_type'] !== 'article') {
                return $context;
            }
        }
    }

    $row = $tododb->get_row(
        "SELECT p.post_type, COUNT(DISTINCT p.ID) AS content_count
         FROM " . TABLE_PREFIX . "term_relationships AS tr
         INNER JOIN " . TABLE_PREFIX . "term_taxonomy AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
         INNER JOIN " . TABLE_PREFIX . "posts AS p ON tr.object_id = p.ID
         WHERE tt.term_id = ? AND tt.taxonomy = 'category' AND p.post_status = 'publish'
         GROUP BY p.post_type
         ORDER BY content_count DESC, p.post_type ASC
         LIMIT 1",
        [$term_id]
    );

    if ($row && !empty($row->post_type)) {
        $inferred_type = api_normalize_category_model_type($row->post_type);
        $context['model_type'] = $inferred_type;
        $context['model_key'] = $inferred_type;
        $context['is_inferred'] = true;

        if (class_exists('Todo_Model_Manager') && method_exists('Todo_Model_Manager', 'get_model_id_by_key')) {
            $context['model_id'] = intval(Todo_Model_Manager::get_model_id_by_key($inferred_type));
        }
    }

    return $context;
}

/**
 * 获取分类列表
 *
 * @param array $params 请求参数
 * @return array 分类列表
 */
function api_get_categories($params) {
    global $tododb;
    
    // 默认参数
    $defaults = [
        'page' => 1,
        'per_page' => 20,
        'search' => '',
        'orderby' => 'name',
        'order' => 'ASC',
        'parent' => null,
        'include_empty' => 0,
        'model_id' => null,
        'model_type' => ''
    ];
    
    // 合并参数
    $args = array_merge($defaults, $params);
    
    // 验证参数
    $args['page'] = max(1, intval($args['page']));
    $args['per_page'] = max(1, min(100, intval($args['per_page'])));
    $args['order'] = in_array(strtoupper($args['order']), ['ASC', 'DESC']) ? strtoupper($args['order']) : 'ASC';
    
    $include_empty = !empty($args['include_empty']);

    $model_id = isset($args['model_id']) ? intval($args['model_id']) : 0;
    $model_type = isset($args['model_type']) ? sanitize_text_field($args['model_type']) : '';
    $model_type = strtolower(trim((string) $model_type));

    $now_gmt = current_time('mysql', true);
    $query = "SELECT
                t.term_id,
                t.name,
                t.slug,
                tt.description,
                tt.parent,
                tm.model_id,
                m.model_type,
                COUNT(DISTINCT p.ID) AS content_count
              FROM " . TABLE_PREFIX . "terms AS t
              INNER JOIN " . TABLE_PREFIX . "term_taxonomy AS tt ON t.term_id = tt.term_id AND tt.taxonomy = 'category'
              LEFT JOIN " . TABLE_PREFIX . "term_models AS tm ON tm.term_id = t.term_id
              LEFT JOIN " . TABLE_PREFIX . "models AS m ON m.model_id = tm.model_id
              LEFT JOIN " . TABLE_PREFIX . "term_relationships AS tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
              LEFT JOIN " . TABLE_PREFIX . "posts AS p ON tr.object_id = p.ID AND p.post_status = 'publish' AND p.post_date_gmt <= ?
              WHERE 1=1";
    $params = [$now_gmt];
    
    // 添加搜索条件
    if (!empty($args['search'])) {
        $query .= " AND (t.name LIKE ? OR t.slug LIKE ? OR tt.description LIKE ?)";
        $search_term = '%' . $args['search'] . '%';
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    // 添加父级条件
    if ($args['parent'] !== null) {
        $query .= " AND tt.parent = ?";
        $params[] = intval($args['parent']);
    }

    if ($model_id > 0) {
        $query .= " AND tm.model_id = ?";
        $params[] = $model_id;
    } elseif ($model_type !== '') {
        $query .= " AND m.model_type = ?";
        $params[] = $model_type;
    }

    if (!$include_empty) {
        $query .= " AND p.ID IS NOT NULL";
    }

    $query .= " GROUP BY t.term_id, t.name, t.slug, tt.description, tt.parent, tm.model_id, m.model_type";
    
    // 添加排序
    $valid_orderby = [
        'name' => 't.name',
        'slug' => 't.slug',
        'term_id' => 't.term_id',
        'id' => 't.term_id',
        'count' => 'content_count'
    ];
    $orderby_field = isset($valid_orderby[$args['orderby']]) ? $valid_orderby[$args['orderby']] : 't.name';
    $order_dir = in_array($args['order'], ['ASC', 'DESC']) ? $args['order'] : 'ASC';
    $query .= " ORDER BY {$orderby_field} {$order_dir}";
    
    // 添加分页
    $offset = ($args['page'] - 1) * $args['per_page'];
    $query .= " LIMIT " . intval($args['per_page']) . " OFFSET " . intval($offset);
    
    // 执行查询
    $categories = $tododb->query($query, $params);
    
    // 获取总数
    $count_query = "SELECT COUNT(DISTINCT t.term_id) as total
                    FROM " . TABLE_PREFIX . "terms AS t
                    INNER JOIN " . TABLE_PREFIX . "term_taxonomy AS tt ON t.term_id = tt.term_id AND tt.taxonomy = 'category'
                    " . (($model_id > 0 || $model_type !== '') ? ("INNER JOIN " . TABLE_PREFIX . "term_models AS tm ON tm.term_id = t.term_id
                    INNER JOIN " . TABLE_PREFIX . "models AS m ON m.model_id = tm.model_id") : "") . "
                    LEFT JOIN " . TABLE_PREFIX . "term_relationships AS tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
                    LEFT JOIN " . TABLE_PREFIX . "posts AS p ON tr.object_id = p.ID AND p.post_status = 'publish' AND p.post_date_gmt <= ?
                    WHERE 1=1";
    $count_params = [$now_gmt];
    
    // 添加搜索条件
    if (!empty($args['search'])) {
        $count_query .= " AND (t.name LIKE ? OR t.slug LIKE ? OR tt.description LIKE ?)";
        $search_term = '%' . $args['search'] . '%';
        $count_params[] = $search_term;
        $count_params[] = $search_term;
        $count_params[] = $search_term;
    }
    
    // 添加父级条件
    if ($args['parent'] !== null) {
        $count_query .= " AND tt.parent = ?";
        $count_params[] = intval($args['parent']);
    }

    if ($model_id > 0) {
        $count_query .= " AND tm.model_id = ?";
        $count_params[] = $model_id;
    } elseif ($model_type !== '') {
        $count_query .= " AND m.model_type = ?";
        $count_params[] = $model_type;
    }

    if (!$include_empty) {
        $count_query .= " AND p.ID IS NOT NULL";
    }
    
    $count_result = $tododb->query($count_query, $count_params);
    $total = isset($count_result[0]['total']) ? intval($count_result[0]['total']) : 0;
    
    // 处理结果
    $result = [];
    foreach ($categories as $category) {
        $model_context = api_get_category_model_context(intval($category['term_id']));
        $resolved_model_type = !empty($category['model_type'])
            ? api_normalize_category_model_type($category['model_type'])
            : $model_context['model_type'];
        $meta = get_term_meta(intval($category['term_id']));
        $result[] = [
            'id' => intval($category['term_id']),
            'name' => $category['name'],
            'slug' => $category['slug'],
            'description' => $category['description'],
            'model_id' => intval($category['model_id'] ?? 0) ?: intval($model_context['model_id']),
            'model_type' => $resolved_model_type,
            'parent' => intval($category['parent']),
            'count' => intval($category['content_count']),
            'url' => get_term_link($category['term_id'], 'category'),
            'is_hidden' => isset($meta['is_hidden']) ? intval($meta['is_hidden']) : 0,
            'hidden_start' => isset($meta['hidden_start']) ? $meta['hidden_start'] : '',
            'hidden_end' => isset($meta['hidden_end']) ? $meta['hidden_end'] : '',
            'cat_image' => isset($meta['category_image']) ? $meta['category_image'] : '',
        ];
    }
    
    // 返回结果
    return [
        'categories' => $result,
        'total' => $total,
        'page' => $args['page'],
        'per_page' => $args['per_page'],
        'total_pages' => ceil($total / $args['per_page'])
    ];
}

/**
 * 获取单个分类
 *
 * @param array $params 请求参数
 * @return array 分类数据
 */
function api_get_category($params) {
    global $tododb;
    
    // 验证参数
    if (!isset($params['id'])) {
        send_api_error(400, '缺少ID参数');
    }
    
    $term_id = intval($params['id']);
    
    $query = "SELECT
                t.term_id,
                t.name,
                t.slug,
                tt.description,
                tt.parent,
                tm.model_id,
                m.model_type,
                COUNT(DISTINCT p.ID) AS content_count
              FROM " . TABLE_PREFIX . "terms AS t
              INNER JOIN " . TABLE_PREFIX . "term_taxonomy AS tt ON t.term_id = tt.term_id AND tt.taxonomy = 'category'
              LEFT JOIN " . TABLE_PREFIX . "term_models AS tm ON tm.term_id = t.term_id
              LEFT JOIN " . TABLE_PREFIX . "models AS m ON m.model_id = tm.model_id
              LEFT JOIN " . TABLE_PREFIX . "term_relationships AS tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
              LEFT JOIN " . TABLE_PREFIX . "posts AS p ON tr.object_id = p.ID AND p.post_status = 'publish'
              WHERE t.term_id = ?
              GROUP BY t.term_id, t.name, t.slug, tt.description, tt.parent, tm.model_id, m.model_type
              LIMIT 1";
    $category = $tododb->query($query, [$term_id]);
    
    // 检查分类是否存在
    if (empty($category)) {
        send_api_error(404, '分类不存在');
    }
    
    $category = $category[0];
    $model_context = api_get_category_model_context($term_id);
    $resolved_model_type = !empty($category['model_type'])
        ? api_normalize_category_model_type($category['model_type'])
        : $model_context['model_type'];
    
    $children_query = "SELECT
                        t.term_id,
                        t.name,
                        t.slug,
                        tt.parent,
                        COUNT(DISTINCT p.ID) AS content_count
                      FROM " . TABLE_PREFIX . "terms AS t
                      INNER JOIN " . TABLE_PREFIX . "term_taxonomy AS tt ON t.term_id = tt.term_id AND tt.taxonomy = 'category'
                      LEFT JOIN " . TABLE_PREFIX . "term_relationships AS tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
                      LEFT JOIN " . TABLE_PREFIX . "posts AS p ON tr.object_id = p.ID AND p.post_status = 'publish'
                      WHERE tt.parent = ?
                      GROUP BY t.term_id, t.name, t.slug, tt.parent";
    $children = $tododb->query($children_query, [$term_id]);
    
    $child_categories = [];
    foreach ($children as $child) {
        $child_categories[] = [
            'id' => intval($child['term_id']),
            'name' => $child['name'],
            'slug' => $child['slug'],
            'count' => intval($child['content_count'])
        ];
    }
    $meta = get_term_meta(intval($category['term_id']));
    
    // 返回结果
    return [
        'id' => intval($category['term_id']),
        'name' => $category['name'],
        'slug' => $category['slug'],
        'description' => $category['description'],
        'model_id' => intval($category['model_id'] ?? 0) ?: intval($model_context['model_id']),
        'model_type' => $resolved_model_type,
        'parent' => intval($category['parent']),
        'count' => intval($category['content_count']),
        'url' => get_term_link($category['term_id'], 'category'),
        'children' => $child_categories,
        'is_hidden' => isset($meta['is_hidden']) ? intval($meta['is_hidden']) : 0,
        'hidden_start' => isset($meta['hidden_start']) ? $meta['hidden_start'] : '',
        'hidden_end' => isset($meta['hidden_end']) ? $meta['hidden_end'] : '',
        'cat_image' => isset($meta['category_image']) ? $meta['category_image'] : '',
    ];
}

/**
 * 创建分类
 *
 * @param array $params 请求参数
 * @return array 创建的分类数据
 */
function api_create_category($params) {
    global $tododb;
    
    // 验证必要参数
    if (!isset($params['name']) || empty($params['name'])) {
        send_api_error(400, '分类名称不能为空');
    }
    
    // 准备数据
    $name = sanitize_text_field($params['name']);
    $slug = isset($params['slug']) ? sanitize_title($params['slug']) : sanitize_title($name);
    $description = isset($params['description']) ? sanitize_textarea_field($params['description']) : '';
    $parent = isset($params['parent']) ? intval($params['parent']) : 0;
    
    // 验证父级分类是否存在
    if ($parent > 0) {
        $parent_query = "SELECT term_id FROM " . TABLE_PREFIX . "term_taxonomy WHERE term_id = ? AND taxonomy = 'category' LIMIT 1";
        $parent_exists = $tododb->query($parent_query, [$parent]);
        
        if (empty($parent_exists)) {
            send_api_error(400, '父级分类不存在');
        }
    }
    
    // 检查分类名称是否已存在
    $check_query = "SELECT t.term_id FROM " . TABLE_PREFIX . "terms AS t "
                . "INNER JOIN " . TABLE_PREFIX . "term_taxonomy AS tt ON t.term_id = tt.term_id "
                . "WHERE t.name = ? AND tt.taxonomy = 'category' LIMIT 1";
    $existing_term = $tododb->query($check_query, [$name]);
    
    if (!empty($existing_term)) {
        send_api_error(400, '分类名称已存在');
    }
    
    // 检查分类别名是否已存在
    $check_query = "SELECT t.term_id FROM " . TABLE_PREFIX . "terms AS t "
                . "INNER JOIN " . TABLE_PREFIX . "term_taxonomy AS tt ON t.term_id = tt.term_id "
                . "WHERE t.slug = ? AND tt.taxonomy = 'category' LIMIT 1";
    $existing_slug = $tododb->query($check_query, [$slug]);
    
    if (!empty($existing_slug)) {
        // 生成唯一别名
        $slug = $slug . '-' . time();
    }
    
    // 开始事务
    $tododb->begin_transaction();
    
    try {
        // 插入terms表
        $terms_query = "INSERT INTO " . TABLE_PREFIX . "terms (name, slug) VALUES (?, ?)";
        $tododb->query($terms_query, [$name, $slug]);
        $term_id = $tododb->insert_id();
        
        // 插入term_taxonomy表
        $taxonomy_query = "INSERT INTO " . TABLE_PREFIX . "term_taxonomy (term_id, taxonomy, description, parent) VALUES (?, ?, ?, ?)";
        $tododb->query($taxonomy_query, [$term_id, 'category', $description, $parent]);
        
        // 提交事务
        $tododb->commit();
    } catch (Exception $e) {
        // 回滚事务
        $tododb->rollback();
        send_api_error(500, '创建分类失败: ' . $e->getMessage());
    }
    
    // 返回创建的分类
    return api_get_category(['id' => $term_id]);
}

/**
 * 更新分类
 *
 * @param array $params 请求参数
 * @return array 更新后的分类数据
 */
function api_update_category($params) {
    global $tododb;
    
    // 验证参数
    if (!isset($params['id'])) {
        send_api_error(400, '缺少ID参数');
    }
    
    $term_id = intval($params['id']);
    
    // 检查分类是否存在
    $query = "SELECT t.*, tt.* FROM " . TABLE_PREFIX . "terms AS t "
          . "INNER JOIN " . TABLE_PREFIX . "term_taxonomy AS tt ON t.term_id = tt.term_id "
          . "WHERE t.term_id = ? AND tt.taxonomy = 'category' LIMIT 1";
    $category = $tododb->query($query, [$term_id]);
    
    if (empty($category)) {
        send_api_error(404, '分类不存在');
    }
    
    $category = $category[0];
    
    // 准备更新数据
    $update_terms = false;
    $update_taxonomy = false;
    $terms_data = [];
    $terms_params = [];
    $taxonomy_data = [];
    $taxonomy_params = [];
    
    // 更新名称
    if (isset($params['name'])) {
        $name = sanitize_text_field($params['name']);
        
        // 检查名称是否已存在
        if ($name !== $category['name']) {
            $check_query = "SELECT t.term_id FROM " . TABLE_PREFIX . "terms AS t "
                        . "INNER JOIN " . TABLE_PREFIX . "term_taxonomy AS tt ON t.term_id = tt.term_id "
                        . "WHERE t.name = ? AND tt.taxonomy = 'category' AND t.term_id != ? LIMIT 1";
            $existing_term = $tododb->query($check_query, [$name, $term_id]);
            
            if (!empty($existing_term)) {
                send_api_error(400, '分类名称已存在');
            }
            
            $update_terms = true;
            $terms_data[] = 'name = ?';
            $terms_params[] = $name;
        }
    }
    
    // 更新别名
    if (isset($params['slug'])) {
        $slug = sanitize_title($params['slug']);
        
        // 检查别名是否已存在
        if ($slug !== $category['slug']) {
            $check_query = "SELECT t.term_id FROM " . TABLE_PREFIX . "terms AS t "
                        . "INNER JOIN " . TABLE_PREFIX . "term_taxonomy AS tt ON t.term_id = tt.term_id "
                        . "WHERE t.slug = ? AND tt.taxonomy = 'category' AND t.term_id != ? LIMIT 1";
            $existing_slug = $tododb->query($check_query, [$slug, $term_id]);
            
            if (!empty($existing_slug)) {
                // 生成唯一别名
                $slug = $slug . '-' . time();
            }
            
            $update_terms = true;
            $terms_data[] = 'slug = ?';
            $terms_params[] = $slug;
        }
    }
    
    // 更新描述
    if (isset($params['description'])) {
        $description = sanitize_textarea_field($params['description']);
        
        if ($description !== $category['description']) {
            $update_taxonomy = true;
            $taxonomy_data[] = 'description = ?';
            $taxonomy_params[] = $description;
        }
    }
    
    // 更新父级
    if (isset($params['parent'])) {
        $parent = intval($params['parent']);
        
        // 验证父级分类是否存在
        if ($parent > 0 && $parent !== $term_id) {
            $parent_query = "SELECT term_id FROM " . TABLE_PREFIX . "term_taxonomy WHERE term_id = ? AND taxonomy = 'category' LIMIT 1";
            $parent_exists = $tododb->query($parent_query, [$parent]);
            
            if (empty($parent_exists)) {
                send_api_error(400, '父级分类不存在');
            }
        }
        
        // 防止循环引用
        if ($parent === $term_id) {
            $parent = 0;
        }
        
        if ($parent !== intval($category['parent'])) {
            $update_taxonomy = true;
            $taxonomy_data[] = 'parent = ?';
            $taxonomy_params[] = $parent;
        }
    }
    
    // 开始事务
    $tododb->begin_transaction();
    
    try {
        // 更新terms表
        if ($update_terms && !empty($terms_data)) {
            $terms_params[] = $term_id;
            $terms_query = "UPDATE " . TABLE_PREFIX . "terms SET " . implode(', ', $terms_data) . " WHERE term_id = ?";
            $tododb->query($terms_query, $terms_params);
        }
        
        // 更新term_taxonomy表
        if ($update_taxonomy && !empty($taxonomy_data)) {
            $taxonomy_params[] = $term_id;
            $taxonomy_query = "UPDATE " . TABLE_PREFIX . "term_taxonomy SET " . implode(', ', $taxonomy_data) . " WHERE term_id = ?";
            $tododb->query($taxonomy_query, $taxonomy_params);
        }
        
        // 提交事务
        $tododb->commit();
    } catch (Exception $e) {
        // 回滚事务
        $tododb->rollback();
        send_api_error(500, '更新分类失败: ' . $e->getMessage());
    }
    
    // 返回更新后的分类
    return api_get_category(['id' => $term_id]);
}

/**
 * 删除分类
 *
 * @param array $params 请求参数
 * @return array 操作结果
 */
function api_delete_category($params) {
    global $tododb;
    
    // 验证参数
    if (!isset($params['id'])) {
        send_api_error(400, '缺少ID参数');
    }
    
    $term_id = intval($params['id']);
    
    // 检查分类是否存在
    $query = "SELECT t.*, tt.* FROM " . TABLE_PREFIX . "terms AS t "
          . "INNER JOIN " . TABLE_PREFIX . "term_taxonomy AS tt ON t.term_id = tt.term_id "
          . "WHERE t.term_id = ? AND tt.taxonomy = 'category' LIMIT 1";
    $category = $tododb->get_row($query, [$term_id], 'ARRAY_A');
    
    if (empty($category)) {
        send_api_error(404, '分类不存在');
    }
    
    // 检查是否有子分类
    $children_query = "SELECT COUNT(*) as count FROM " . TABLE_PREFIX . "term_taxonomy WHERE parent = ? AND taxonomy = 'category'";
    $children_count = intval($tododb->get_var($children_query, [$term_id]));
    
    if ($children_count > 0) {
        send_api_error(400, '请先删除子分类');
    }
    
    // 开始事务
    $tododb->begin_transaction();
    
    try {
        // 删除term_relationships表中的关联
        $relationships_query = "DELETE FROM " . TABLE_PREFIX . "term_relationships WHERE term_id = ?";
        $tododb->query($relationships_query, [$term_id]);
        
        // 删除term_taxonomy表中的记录
        $taxonomy_query = "DELETE FROM " . TABLE_PREFIX . "term_taxonomy WHERE term_id = ?";
        $tododb->query($taxonomy_query, [$term_id]);
        
        // 删除terms表中的记录
        $terms_query = "DELETE FROM " . TABLE_PREFIX . "terms WHERE term_id = ?";
        $tododb->query($terms_query, [$term_id]);
        
        // 提交事务
        $tododb->commit();
    } catch (Exception $e) {
        // 回滚事务
        $tododb->rollback();
        send_api_error(500, '删除分类失败: ' . $e->getMessage());
    }
    
    // 返回结果
    return [
        'id' => $term_id,
        'message' => '分类已成功删除'
    ];
}

/**
 * 获取标签列表
 *
 * @param array $params 请求参数
 * @return array 标签列表
 */
function api_get_tags($params) {
    global $tododb;
    
    // 默认参数
    $defaults = [
        'page' => 1,
        'per_page' => 20,
        'search' => '',
        'orderby' => 'name',
        'order' => 'ASC'
    ];
    
    // 合并参数
    $args = array_merge($defaults, $params);
    
    // 验证参数
    $args['page'] = max(1, intval($args['page']));
    $args['per_page'] = max(1, min(100, intval($args['per_page'])));
    $args['order'] = in_array(strtoupper($args['order']), ['ASC', 'DESC']) ? strtoupper($args['order']) : 'ASC';
    
    // 构建查询
    $query = "SELECT * FROM " . TABLE_PREFIX . "terms AS t "
          . "INNER JOIN " . TABLE_PREFIX . "term_taxonomy AS tt ON t.term_id = tt.term_id "
          . "WHERE tt.taxonomy = 'tag'";
    $params = [];
    
    // 添加搜索条件
    if (!empty($args['search'])) {
        $query .= " AND (t.name LIKE ? OR t.slug LIKE ? OR tt.description LIKE ?)";
        $search_term = '%' . $args['search'] . '%';
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    // 添加排序
    $valid_orderby = [
        'name' => 't.name',
        'slug' => 't.slug',
        'term_id' => 't.term_id',
        'id' => 't.term_id',
        'count' => 'tt.count'
    ];
    $orderby_field = isset($valid_orderby[$args['orderby']]) ? $valid_orderby[$args['orderby']] : 't.name';
    $order_dir = in_array($args['order'], ['ASC', 'DESC']) ? $args['order'] : 'ASC';
    $query .= " ORDER BY {$orderby_field} {$order_dir}";
    
    // 添加分页
    $offset = ($args['page'] - 1) * $args['per_page'];
    $query .= " LIMIT " . intval($args['per_page']) . " OFFSET " . intval($offset);
    
    // 执行查询
    $tags = $tododb->query($query, $params);
    
    // 获取总数
    $count_query = "SELECT COUNT(*) as total FROM " . TABLE_PREFIX . "terms AS t "
                . "INNER JOIN " . TABLE_PREFIX . "term_taxonomy AS tt ON t.term_id = tt.term_id "
                . "WHERE tt.taxonomy = 'tag'";
    $count_params = [];
    
    // 添加搜索条件
    if (!empty($args['search'])) {
        $count_query .= " AND (t.name LIKE ? OR t.slug LIKE ? OR tt.description LIKE ?)";
        $search_term = '%' . $args['search'] . '%';
        $count_params[] = $search_term;
        $count_params[] = $search_term;
        $count_params[] = $search_term;
    }
    
    $count_result = $tododb->query($count_query, $count_params);
    $total = isset($count_result[0]['total']) ? intval($count_result[0]['total']) : 0;
    
    // 处理结果
    $result = [];
    foreach ($tags as $tag) {
        $result[] = [
            'id' => intval($tag['term_id']),
            'name' => $tag['name'],
            'slug' => $tag['slug'],
            'description' => $tag['description'],
            'count' => intval($tag['count']),
            'url' => get_term_link($tag['term_id'], 'tag')
        ];
    }
    
    // 返回结果
    return [
        'tags' => $result,
        'total' => $total,
        'page' => $args['page'],
        'per_page' => $args['per_page'],
        'total_pages' => ceil($total / $args['per_page'])
    ];
}

/**
 * 获取单个标签
 *
 * @param array $params 请求参数
 * @return array 标签数据
 */
function api_get_tag($params) {
    global $tododb;
    
    // 验证参数
    if (!isset($params['id'])) {
        send_api_error(400, '缺少ID参数');
    }
    
    $term_id = intval($params['id']);
    
    // 查询标签
    $query = "SELECT t.*, tt.* FROM " . TABLE_PREFIX . "terms AS t "
          . "INNER JOIN " . TABLE_PREFIX . "term_taxonomy AS tt ON t.term_id = tt.term_id "
          . "WHERE t.term_id = ? AND tt.taxonomy = 'tag' LIMIT 1";
    $tag = $tododb->query($query, [$term_id]);
    
    // 检查标签是否存在
    if (empty($tag)) {
        send_api_error(404, '标签不存在');
    }
    
    $tag = $tag[0];
    
    // 返回结果
    return [
        'id' => intval($tag['term_id']),
        'name' => $tag['name'],
        'slug' => $tag['slug'],
        'description' => $tag['description'],
        'count' => intval($tag['count']),
        'url' => get_term_link($tag['term_id'], 'tag')
    ];
}

/**
 * 创建标签
 *
 * @param array $params 请求参数
 * @return array 创建的标签数据
 */
function api_create_tag($params) {
    global $tododb;
    
    // 验证必要参数
    if (!isset($params['name']) || empty($params['name'])) {
        send_api_error(400, '标签名称不能为空');
    }
    
    // 准备数据
    $name = sanitize_text_field($params['name']);
    $slug = isset($params['slug']) ? sanitize_title($params['slug']) : sanitize_title($name);
    $description = isset($params['description']) ? sanitize_textarea_field($params['description']) : '';
    
    // 检查标签名称是否已存在
    $check_query = "SELECT t.term_id FROM " . TABLE_PREFIX . "terms AS t "
                . "INNER JOIN " . TABLE_PREFIX . "term_taxonomy AS tt ON t.term_id = tt.term_id "
                . "WHERE t.name = ? AND tt.taxonomy = 'tag' LIMIT 1";
    $existing_term = $tododb->query($check_query, [$name]);
    
    if (!empty($existing_term)) {
        send_api_error(400, '标签名称已存在');
    }
    
    // 检查标签别名是否已存在
    $check_query = "SELECT t.term_id FROM " . TABLE_PREFIX . "terms AS t "
                . "INNER JOIN " . TABLE_PREFIX . "term_taxonomy AS tt ON t.term_id = tt.term_id "
                . "WHERE t.slug = ? AND tt.taxonomy = 'tag' LIMIT 1";
    $existing_slug = $tododb->query($check_query, [$slug]);
    
    if (!empty($existing_slug)) {
        // 生成唯一别名
        $slug = $slug . '-' . time();
    }
    
    // 开始事务
    $tododb->begin_transaction();
    
    try {
        // 插入terms表
        $terms_query = "INSERT INTO " . TABLE_PREFIX . "terms (name, slug) VALUES (?, ?)";
        $tododb->query($terms_query, [$name, $slug]);
        $term_id = $tododb->insert_id();
        
        // 插入term_taxonomy表
        $taxonomy_query = "INSERT INTO " . TABLE_PREFIX . "term_taxonomy (term_id, taxonomy, description, parent) VALUES (?, ?, ?, ?)";
        $tododb->query($taxonomy_query, [$term_id, 'tag', $description, 0]);
        
        // 提交事务
        $tododb->commit();
    } catch (Exception $e) {
        // 回滚事务
        $tododb->rollback();
        send_api_error(500, '创建标签失败: ' . $e->getMessage());
    }
    
    // 返回创建的标签
    return api_get_tag(['id' => $term_id]);
}

/**
 * 更新标签
 *
 * @param array $params 请求参数
 * @return array 更新后的标签数据
 */
function api_update_tag($params) {
    global $tododb;
    
    // 验证参数
    if (!isset($params['id'])) {
        send_api_error(400, '缺少ID参数');
    }
    
    $term_id = intval($params['id']);
    
    // 检查标签是否存在
    $query = "SELECT t.*, tt.* FROM " . TABLE_PREFIX . "terms AS t "
          . "INNER JOIN " . TABLE_PREFIX . "term_taxonomy AS tt ON t.term_id = tt.term_id "
          . "WHERE t.term_id = ? AND tt.taxonomy = 'tag' LIMIT 1";
    $tag = $tododb->query($query, [$term_id]);
    
    if (empty($tag)) {
        send_api_error(404, '标签不存在');
    }
    
    $tag = $tag[0];
    
    // 准备更新数据
    $update_terms = false;
    $update_taxonomy = false;
    $terms_data = [];
    $terms_params = [];
    $taxonomy_data = [];
    $taxonomy_params = [];
    
    // 更新名称
    if (isset($params['name'])) {
        $name = sanitize_text_field($params['name']);
        
        // 检查名称是否已存在
        if ($name !== $tag['name']) {
            $check_query = "SELECT t.term_id FROM " . TABLE_PREFIX . "terms AS t "
                        . "INNER JOIN " . TABLE_PREFIX . "term_taxonomy AS tt ON t.term_id = tt.term_id "
                        . "WHERE t.name = ? AND tt.taxonomy = 'tag' AND t.term_id != ? LIMIT 1";
            $existing_term = $tododb->query($check_query, [$name, $term_id]);
            
            if (!empty($existing_term)) {
                send_api_error(400, '标签名称已存在');
            }
            
            $update_terms = true;
            $terms_data[] = 'name = ?';
            $terms_params[] = $name;
        }
    }
    
    // 更新别名
    if (isset($params['slug'])) {
        $slug = sanitize_title($params['slug']);
        
        // 检查别名是否已存在
        if ($slug !== $tag['slug']) {
            $check_query = "SELECT t.term_id FROM " . TABLE_PREFIX . "terms AS t "
                        . "INNER JOIN " . TABLE_PREFIX . "term_taxonomy AS tt ON t.term_id = tt.term_id "
                        . "WHERE t.slug = ? AND tt.taxonomy = 'tag' AND t.term_id != ? LIMIT 1";
            $existing_slug = $tododb->query($check_query, [$slug, $term_id]);
            
            if (!empty($existing_slug)) {
                // 生成唯一别名
                $slug = $slug . '-' . time();
            }
            
            $update_terms = true;
            $terms_data[] = 'slug = ?';
            $terms_params[] = $slug;
        }
    }
    
    // 更新描述
    if (isset($params['description'])) {
        $description = sanitize_textarea_field($params['description']);
        
        if ($description !== $tag['description']) {
            $update_taxonomy = true;
            $taxonomy_data[] = 'description = ?';
            $taxonomy_params[] = $description;
        }
    }
    
    // 开始事务
    $tododb->begin_transaction();
    
    try {
        // 更新terms表
        if ($update_terms && !empty($terms_data)) {
            $terms_params[] = $term_id;
            $terms_query = "UPDATE " . TABLE_PREFIX . "terms SET " . implode(', ', $terms_data) . " WHERE term_id = ?";
            $tododb->query($terms_query, $terms_params);
        }
        
        // 更新term_taxonomy表
        if ($update_taxonomy && !empty($taxonomy_data)) {
            $taxonomy_params[] = $term_id;
            $taxonomy_query = "UPDATE " . TABLE_PREFIX . "term_taxonomy SET " . implode(', ', $taxonomy_data) . " WHERE term_id = ?";
            $tododb->query($taxonomy_query, $taxonomy_params);
        }
        
        // 提交事务
        $tododb->commit();
    } catch (Exception $e) {
        // 回滚事务
        $tododb->rollback();
        send_api_error(500, '更新标签失败: ' . $e->getMessage());
    }
    
    // 返回更新后的标签
    return api_get_tag(['id' => $term_id]);
}

/**
 * 删除标签
 *
 * @param array $params 请求参数
 * @return array 操作结果
 */
function api_delete_tag($params) {
    global $tododb;
    
    // 验证参数
    if (!isset($params['id'])) {
        send_api_error(400, '缺少ID参数');
    }
    
    $term_id = intval($params['id']);
    
    // 检查标签是否存在
    $query = "SELECT t.*, tt.* FROM " . TABLE_PREFIX . "terms AS t "
          . "INNER JOIN " . TABLE_PREFIX . "term_taxonomy AS tt ON t.term_id = tt.term_id "
          . "WHERE t.term_id = ? AND tt.taxonomy = 'tag' LIMIT 1";
    $tag = $tododb->query($query, [$term_id]);
    
    if (empty($tag)) {
        send_api_error(404, '标签不存在');
    }
    
    // 开始事务
    $tododb->begin_transaction();
    
    try {
        // 删除term_relationships表中的关联
        $relationships_query = "DELETE FROM " . TABLE_PREFIX . "term_relationships WHERE term_id = ?";
        $tododb->query($relationships_query, [$term_id]);
        
        // 删除term_taxonomy表中的记录
        $taxonomy_query = "DELETE FROM " . TABLE_PREFIX . "term_taxonomy WHERE term_id = ?";
        $tododb->query($taxonomy_query, [$term_id]);
        
        // 删除terms表中的记录
        $terms_query = "DELETE FROM " . TABLE_PREFIX . "terms WHERE term_id = ?";
        $tododb->query($terms_query, [$term_id]);
        
        // 提交事务
        $tododb->commit();
    } catch (Exception $e) {
        // 回滚事务
        $tododb->rollback();
        send_api_error(500, '删除标签失败: ' . $e->getMessage());
    }
    
    // 返回结果
    return [
        'id' => $term_id,
        'message' => '标签已成功删除'
    ];
}

// 注册API路由
register_api_route('categories', 'GET', 'api_get_categories');
register_api_route('categories/([0-9]+)', 'GET', 'api_get_category');
register_api_route('categories', 'POST', 'api_create_category');
register_api_route('categories/([0-9]+)', 'PUT', 'api_update_category');
register_api_route('categories/([0-9]+)', 'DELETE', 'api_delete_category');

register_api_route('tags', 'GET', 'api_get_tags');
register_api_route('tags/([0-9]+)', 'GET', 'api_get_tag');
register_api_route('tags', 'POST', 'api_create_tag');
register_api_route('tags/([0-9]+)', 'PUT', 'api_update_tag');
register_api_route('tags/([0-9]+)', 'DELETE', 'api_delete_tag');
