<?php
/**
 * TodoCMS 友情链接相关函数
 * 
 * 提供友情链接的前端显示和管理功能
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 获取友情链接列表
 * 
 * @param array $args 查询参数
 * @return array 友情链接数组
 */
function todo_get_links($args = array()) {
    global $tododb;
    
    $defaults = array(
        'category' => '',
        'status' => 1,
        'limit' => -1,
        'offset' => 0,
        'orderby' => 'sort_order',
        'order' => 'ASC'
    );
    
    $args = todo_parse_args($args, $defaults);
    
    $where_conditions = array();
    $where_values = array();
    
    // 状态筛选
    if (!empty($args['status'])) {
        $where_conditions[] = "status = ?";
        $where_values[] = $args['status'];
    }
    
    // 分类筛选
    if (!empty($args['category'])) {
        $where_conditions[] = "category = ?";
        $where_values[] = $args['category'];
    }
    
    // 构建WHERE子句
    $where_clause = '';
    if (!empty($where_conditions)) {
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    }
    
    // 构建ORDER BY子句
    $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);
    if (!$orderby) {
        $orderby = 'sort_order ASC';
    }
    
    // 构建LIMIT子句
    $limit_clause = '';
    if ($args['limit'] > 0) {
        if ($args['offset'] > 0) {
            $limit_clause = "LIMIT " . intval($args['offset']) . ", " . intval($args['limit']);
        } else {
            $limit_clause = "LIMIT " . intval($args['limit']);
        }
    }
    
    // 构建完整查询
    $sql = "SELECT * FROM {$tododb->prefix}links {$where_clause} ORDER BY {$orderby} {$limit_clause}";
    
    if (!empty($where_values)) {
        return $tododb->get_results($sql, $where_values);
    }
    
    return $tododb->get_results($sql);
}

/**
 * 获取单个友情链接
 * 
 * @param int $link_id 链接ID
 * @return object|null 友情链接对象
 */
function todo_get_link($link_id) {
    global $tododb;
    
    $link_id = intval($link_id);
    if ($link_id <= 0) {
        return null;
    }
    
    return $tododb->get_row(
        "SELECT * FROM {$tododb->prefix}links WHERE id = ?",
        [$link_id]
    );
}

/**
 * 显示友情链接列表
 * 
 * @param array $args 显示参数
 * @return string HTML输出
 */
function todo_display_links($args = array()) {
    $defaults = array(
        'category' => '',
        'limit' => -1,
        'show_logo' => true,
        'show_description' => false,
        'class' => 'todo-links',
        'item_class' => 'todo-link-item',
        'before' => '<div class="todo-links-wrapper">',
        'after' => '</div>',
        'before_item' => '<div class="todo-link-item">',
        'after_item' => '</div>',
        'target' => '_blank'
    );
    
    $args = todo_parse_args($args, $defaults);
    
    $links = todo_get_links(array(
        'category' => $args['category'],
        'limit' => $args['limit'],
        'status' => 1
    ));
    
    if (empty($links)) {
        return '';
    }
    
    $output = $args['before'];
    $output .= '<ul class="' . esc_attr($args['class']) . '">';
    
    foreach ($links as $link) {
        $target = !empty($link->target) ? $link->target : $args['target'];
        
        $output .= $args['before_item'];
        $output .= '<li class="' . esc_attr($args['item_class']) . '">';
        $output .= '<a href="' . esc_url($link->url) . '" target="' . esc_attr($target) . '"';
        
        if (!empty($link->description)) {
            $output .= ' title="' . esc_attr($link->description) . '"';
        }
        
        $output .= '>';
        
        // 显示LOGO
        if ($args['show_logo'] && !empty($link->logo)) {
            $output .= '<img src="' . esc_url($link->logo) . '" alt="' . esc_attr($link->name) . '" class="link-logo" />';
        }
        
        $output .= '<span class="link-name">' . esc_html($link->name) . '</span>';
        
        // 显示描述
        if ($args['show_description'] && !empty($link->description)) {
            $output .= '<span class="link-description">' . esc_html($link->description) . '</span>';
        }
        
        $output .= '</a>';
        $output .= '</li>';
        $output .= $args['after_item'];
    }
    
    $output .= '</ul>';
    $output .= $args['after'];
    
    return $output;
}

/**
 * 输出友情链接列表
 * 
 * @param array $args 显示参数
 */
function todo_links($args = array()) {
    echo todo_display_links($args);
}

/**
 * 获取友情链接分类列表
 * 
 * @return array 分类数组
 */
function todo_get_link_categories() {
    global $tododb;
    
    $categories = $tododb->get_col(
        "SELECT DISTINCT category FROM {$tododb->prefix}links WHERE status = 1 ORDER BY category"
    );
    
    return array_filter($categories);
}

/**
 * 获取友情链接数量
 * 
 * @param string $category 分类
 * @param string $status 状态
 * @return int 数量
 */
function todo_get_links_count($category = '', $status = 1) {
    global $tododb;
    
    $where_conditions = array();
    $where_values = array();
    
    if (!empty($status)) {
        $where_conditions[] = "status = ?";
        $where_values[] = $status;
    }
    
    if (!empty($category)) {
        $where_conditions[] = "category = ?";
        $where_values[] = $category;
    }
    
    $where_clause = '';
    if (!empty($where_conditions)) {
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    }
    
    $sql = "SELECT COUNT(*) FROM {$tododb->prefix}links {$where_clause}";
    
    if (!empty($where_values)) {
        return intval($tododb->get_var($sql, $where_values));
    }
    
    return intval($tododb->get_var($sql));
}

/**
 * 检查友情链接是否存在
 * 
 * @param string $url 链接地址
 * @param int $exclude_id 排除的ID
 * @return bool 是否存在
 */
function todo_link_exists($url, $exclude_id = 0) {
    global $tododb;
    
    $where_clause = "url = ?";
    $where_values = array($url);
    
    if ($exclude_id > 0) {
        $where_clause .= " AND id != ?";
        $where_values[] = $exclude_id;
    }
    
    $sql = "SELECT COUNT(*) FROM {$tododb->prefix}links WHERE {$where_clause}";
    $count = $tododb->get_var($sql, $where_values);
    
    return intval($count) > 0;
}

/**
 * 添加友情链接
 * 
 * @param array $link_data 链接数据
 * @return int|false 链接ID或false
 */
function todo_insert_link($link_data) {
    global $tododb;
    
    $defaults = array(
        'name' => '',
        'url' => '',
        'description' => '',
        'logo' => '',
        'category' => 'general',
        'target' => '_blank',
        'sort_order' => 0,
        'status' => 1
    );
    
    $link_data = todo_parse_args($link_data, $defaults);
    
    // 验证必填字段
    if (empty($link_data['name']) || empty($link_data['url'])) {
        return false;
    }
    
    // 检查URL是否已存在
    if (todo_link_exists($link_data['url'])) {
        return false;
    }
    
    $result = $tododb->insert(
        $tododb->prefix . 'links',
        array(
            'name' => sanitize_text_field($link_data['name']),
            'url' => esc_url_raw($link_data['url']),
            'description' => sanitize_textarea_field($link_data['description']),
            'logo' => esc_url_raw($link_data['logo']),
            'category' => sanitize_text_field($link_data['category']),
            'target' => sanitize_text_field($link_data['target']),
            'sort_order' => intval($link_data['sort_order']),
            'status' => sanitize_text_field($link_data['status']),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ),
        array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
    );
    
    return $result ? $tododb->insert_id() : false;
}

/**
 * 更新友情链接
 * 
 * @param int $link_id 链接ID
 * @param array $link_data 链接数据
 * @return bool 是否成功
 */
function todo_update_link($link_id, $link_data) {
    global $tododb;
    
    $link_id = intval($link_id);
    if ($link_id <= 0) {
        return false;
    }
    
    // 检查链接是否存在
    $existing_link = todo_get_link($link_id);
    if (!$existing_link) {
        return false;
    }
    
    // 如果URL发生变化，检查新URL是否已存在
    if (!empty($link_data['url']) && $link_data['url'] !== $existing_link->url) {
        if (todo_link_exists($link_data['url'], $link_id)) {
            return false;
        }
    }
    
    $update_data = array();
    $update_format = array();
    
    // 只更新提供的字段
    $allowed_fields = array('name', 'url', 'description', 'logo', 'category', 'target', 'sort_order', 'status');
    
    foreach ($allowed_fields as $field) {
        if (array_key_exists($field, $link_data)) {
            switch ($field) {
                case 'name':
                case 'category':
                case 'target':
                case 'status':
                    $update_data[$field] = sanitize_text_field($link_data[$field]);
                    $update_format[] = '%s';
                    break;
                case 'url':
                case 'logo':
                    $update_data[$field] = esc_url_raw($link_data[$field]);
                    $update_format[] = '%s';
                    break;
                case 'description':
                    $update_data[$field] = sanitize_textarea_field($link_data[$field]);
                    $update_format[] = '%s';
                    break;
                case 'sort_order':
                    $update_data[$field] = intval($link_data[$field]);
                    $update_format[] = '%d';
                    break;
            }
        }
    }
    
    if (empty($update_data)) {
        return false;
    }
    
    $update_data['updated_at'] = current_time('mysql');
    $update_format[] = '%s';
    
    $result = $tododb->update(
        $tododb->prefix . 'links',
        $update_data,
        array('id' => $link_id),
        $update_format,
        array('%d')
    );
    
    return $result !== false;
}

/**
 * 删除友情链接
 * 
 * @param int $link_id 链接ID
 * @return bool 是否成功
 */
function todo_delete_link($link_id) {
    global $tododb;
    
    $link_id = intval($link_id);
    if ($link_id <= 0) {
        return false;
    }
    
    $result = $tododb->delete(
        $tododb->prefix . 'links',
        array('id' => $link_id),
        array('%d')
    );
    
    return $result !== false;
}