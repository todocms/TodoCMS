<?php
/**
 * 菜单相关函数
 *
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 获取所有菜单
 *
 * @return array 菜单列表
 */
function get_menus() {
    // 检查缓存（处理后的结果，包含 items）
    $ttl = function_exists('todo_get_fragment_cache_ttl') ? (int) todo_get_fragment_cache_ttl() : 120;
    $lang = function_exists('todo_get_current_language') ? todo_get_current_language() : (function_exists('get_current_language') ? get_current_language() : null);
    
    if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
        // 使用固定的缓存键，因为 get_menus() 没有参数
        $cache_key = 'menus_with_items';
        $cached = Todo_Cache_Manager::get($cache_key, 'menus', $lang);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }
    }
    
    // 直接使用数据库菜单结构
    $rows = Todo_Menu_Manager::get_menus();
    if (!is_array($rows)) {
        $rows = [];
    }
    
    // 为每个菜单添加 items
    foreach ($rows as &$row) {
        $menu_id = isset($row['id']) ? (int)$row['id'] : 0;
        if ($menu_id <= 0) {
            $row['items'] = [];
            continue;
        }
        $row['items'] = Todo_Menu_Manager::get_menu_items($menu_id, true);
    }
    
    // 写入缓存
    if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
        Todo_Cache_Manager::set('menus_with_items', $rows, $ttl, 'menus', $lang);
    }
    
    return $rows;
}

/**
 * 获取指定菜单
 *
 * @param int $menu_id 菜单ID
 * @return array|false 菜单信息，如果不存在则返回false
 */
function get_menu($menu_id) {
    $menu = Todo_Menu_Manager::get_menu($menu_id);
    if (!$menu) {
        return false;
    }
    // 使用扁平结构，便于在管理端进行排序与编辑（传入 resolve_url = false 保持数据库原始存储的相对路径，如 '/'）
    $items = Todo_Menu_Manager::get_menu_items($menu_id, false, false);
    if (!is_array($items)) {
        $items = [];
    }
    foreach ($items as &$it) {
        if (!is_array($it)) {
            continue;
        }
        if (!isset($it['attr_title']) && isset($it['description'])) {
            $it['attr_title'] = $it['description'];
        }
        if (!isset($it['new_window']) && isset($it['target'])) {
            $it['new_window'] = ($it['target'] === '_blank') ? 1 : 0;
        }
    }
    unset($it);
    $menu['items'] = $items;
    return $menu;
}

/**
 * 保存菜单
 *
 * @param array $menu_data 菜单数据
 * @return int|false 菜单ID，如果保存失败则返回false
 */
function save_menu($menu_data) {
    // 使用数据库创建菜单
    $menu_id = Todo_Menu_Manager::create_menu([
        'name' => $menu_data['name'] ?? '',
        'location' => $menu_data['location'] ?? '',
        'description' => $menu_data['description'] ?? '',
        'status' => Todo_Menu_Manager::STATUS_ACTIVE,
    ]);
    
    if (!$menu_id) {
        return false;
    }
    
    // 同步创建菜单项（如果传入了 items）
    if (!empty($menu_data['items']) && is_array($menu_data['items'])) {
        $order = 1;
        foreach ($menu_data['items'] as $item) {
            Todo_Menu_Manager::add_menu_item([
                'menu_id' => $menu_id,
                'parent_id' => isset($item['parent']) ? (int)$item['parent'] : 0,
                'title' => $item['title'] ?? '',
                'url' => $item['url'] ?? '',
                'type' => $item['type'] ?? 'custom',
                'object_id' => $item['object_id'] ?? 0,
                'icon' => $item['icon'] ?? '',
                'css_class' => $item['css_class'] ?? '',
                'target' => (!empty($item['new_window']) ? '_blank' : ''),
                'description' => $item['attr_title'] ?? '',
                'sort_order' => $order++,
                'status' => Todo_Menu_Manager::STATUS_ACTIVE,
            ]);
        }
    }
    
    return $menu_id;
}

/**
 * 更新菜单
 *
 * @param int $menu_id 菜单ID
 * @param array $menu_data 菜单数据
 * @return bool 是否更新成功
 */
function update_menu($menu_id, $menu_data) {
    // 验证菜单是否存在
    $existing_menu = Todo_Menu_Manager::get_menu($menu_id);
    if (!$existing_menu) {
        error_log("update_menu: 菜单 ID {$menu_id} 不存在");
        return false;
    }
    
    // 更新菜单基本信息
    $ok = Todo_Menu_Manager::update_menu($menu_id, [
        'name' => $menu_data['name'] ?? '',
        'location' => $menu_data['location'] ?? '',
        'description' => $menu_data['description'] ?? '',
    ]);
    if (!$ok) {
        return false;
    }

    // 同步菜单项（完全重建策略：先删除所有旧菜单项，然后重新创建）
    if (isset($menu_data['items']) && is_array($menu_data['items'])) {
        // 先删除该菜单的所有现有菜单项，避免重复
        Todo_Menu_Manager::delete_menu_items_by_menu_id($menu_id);
        
        // 第一次遍历：创建所有菜单项为顶级项，并记录键到新ID的映射
        $keyIdMap = [];
        $orderList = [];
        $index = 1;
        foreach ($menu_data['items'] as $key => $item) {
            // 提交的 parent 字段是基于前端的 key（可能是旧ID或临时ID），不能直接写入数据库
            // 这里先以顶级方式创建，后续再统一更新 parent_id
            $payload = [
                'menu_id' => $menu_id,
                'parent_id' => 0,
                'title' => $item['title'] ?? '',
                'url' => $item['url'] ?? '',
                'type' => $item['type'] ?? 'custom',
                'object_id' => $item['object_id'] ?? 0,
                'icon' => $item['icon'] ?? '',
                'css_class' => $item['css_class'] ?? '',
                'target' => (!empty($item['new_window']) ? '_blank' : ''),
                'description' => $item['attr_title'] ?? '',
                'sort_order' => isset($item['order']) ? (int)$item['order'] : $index,
                'status' => Todo_Menu_Manager::STATUS_ACTIVE,
            ];
            
            // 重新创建菜单项
            $new_item_id = Todo_Menu_Manager::add_menu_item($payload);
            
            if ($new_item_id) {
                // 记录从提交键到新数据库ID的映射
                $keyIdMap[$key] = (int)$new_item_id;
                // 初始化排序（父级暂设为0，稍后修正）
                $orderList[$new_item_id] = [
                    'parent_id' => 0,
                    'sort_order' => isset($item['order']) ? (int)$item['order'] : $index,
                ];
            }
            $index++;
        }
        
        // 第二次遍历：根据映射修正每个项的 parent_id
        foreach ($menu_data['items'] as $key => $item) {
            $submittedParentKey = isset($item['parent']) ? $item['parent'] : 0;
            $newParentId = 0;
            if (!empty($submittedParentKey) && isset($keyIdMap[$submittedParentKey])) {
                $newParentId = (int)$keyIdMap[$submittedParentKey];
            }
            
            if (isset($keyIdMap[$key])) {
                $newItemId = (int)$keyIdMap[$key];
                if (isset($orderList[$newItemId])) {
                    $orderList[$newItemId]['parent_id'] = $newParentId;
                } else {
                    // 兜底：确保该项也能被更新到正确的父级与排序
                    $orderList[$newItemId] = [
                        'parent_id' => $newParentId,
                        'sort_order' => isset($item['order']) ? (int)$item['order'] : 0,
                    ];
                }
            }
        }
        
        // 批量更新父子关系与排序
        if (!empty($orderList)) {
            Todo_Menu_Manager::update_menu_items_order($menu_id, $orderList);
        }
    }

    return true;
}

/**
 * 删除菜单
 *
 * @param int $menu_id 菜单ID
 * @return bool 是否删除成功
 */
function delete_menu($menu_id) {
    // 删除数据库中的菜单（含其项）
    return Todo_Menu_Manager::delete_menu($menu_id);
}

/**
 * 复制菜单
 *
 * @param int $menu_id 菜单ID
 * @return int|false 新菜单ID，失败返回false
 */
function duplicate_menu($menu_id) {
    // 基于数据库复制菜单及其项
    $menu = Todo_Menu_Manager::get_menu($menu_id);
    if (!$menu) return false;

    $new_id = Todo_Menu_Manager::create_menu([
        'name' =>  '副本_' . ($menu['name'] ?? '未命名'),
        'location' => '',
        'description' => $menu['description'] ?? '',
        'status' => Todo_Menu_Manager::STATUS_ACTIVE,
    ]);
    if (!$new_id) return false;

    // 复制菜单项
    $items = Todo_Menu_Manager::get_menu_items($menu_id, false);
    $idMap = [];
    // 先插入顶级项，再处理子项，保持排序
    usort($items, function($a, $b) {
        return (int)($a['sort_order'] ?? 0) - (int)($b['sort_order'] ?? 0);
    });
    foreach ($items as $it) {
        $parent_id = (int)($it['parent_id'] ?? 0);
        $new_parent = $parent_id ? ($idMap[$parent_id] ?? 0) : 0;
        $new_item_id = Todo_Menu_Manager::add_menu_item([
            'menu_id' => $new_id,
            'parent_id' => $new_parent,
            'title' => $it['title'] ?? '',
            'url' => $it['url'] ?? '',
            'type' => $it['type'] ?? 'custom',
            'object_id' => $it['object_id'] ?? 0,
            'css_class' => $it['css_class'] ?? '',
            'target' => $it['target'] ?? '',
            'description' => $it['description'] ?? '',
            'sort_order' => (int)($it['sort_order'] ?? 0),
            'status' => Todo_Menu_Manager::STATUS_ACTIVE,
        ]);
        if ($new_item_id) {
            $idMap[(int)$it['id']] = (int)$new_item_id;
        }
    }

    return $new_id;
}

/**
 * 获取指定位置的菜单
 *
 * @param string $location 菜单位置
 * @return array|false 菜单信息，如果不存在则返回false
 */
function get_menu_by_location($location) {
    $menu = Todo_Menu_Manager::get_menu_by_location($location);
    if (!$menu) {
        return false;
    }
    // 直接附加 items，不做旧结构转换
    $menu_id = isset($menu['id']) ? (int)$menu['id'] : 0;
    $menu['items'] = $menu_id > 0 ? Todo_Menu_Manager::get_menu_items($menu_id, true) : [];
    return $menu;
}

/**
 * 获取所有页面（用于菜单管理）
 *
 * @return array 页面列表
 */
function get_menu_pages() {
    global $tododb;
    
    $pages = [];
    
    // 查询所有页面
    // 获取单页模型的栏目内容
    $query = "SELECT p.ID, p.title FROM " . $tododb->prefix . "posts p 
              INNER JOIN " . $tododb->prefix . "term_relationships tr ON p.ID = tr.object_id 
              INNER JOIN " . $tododb->prefix . "term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id 
              INNER JOIN " . $tododb->prefix . "terms t ON tt.term_id = t.term_id 
              INNER JOIN " . $tododb->prefix . "term_models tm ON t.term_id = tm.term_id 
              INNER JOIN " . $tododb->prefix . "models m ON tm.model_id = m.model_id 
              WHERE p.post_status = 'publish' AND m.model_key = 'single' 
              ORDER BY p.title ASC";
    $results = $tododb->get_results($query);
    
    if ($results) {
        foreach ($results as $row) {
            $pages[] = [
                'id' => $row->id,
                'title' => $row->title
            ];
        }
    }
    
    return $pages;
}

/**
 * 获取所有栏目（用于菜单管理）
 *
 * @return array 栏目列表
 */
function get_menu_categories() {
    global $tododb;
    
    $categories = [];
    
    // 查询所有栏目
    $query = "SELECT t.term_id as id, t.name, t.slug FROM " . $tododb->prefix . "terms AS t "
          . "INNER JOIN " . $tododb->prefix . "term_taxonomy AS tt ON t.term_id = tt.term_id "
          . "WHERE tt.taxonomy = 'category' ORDER BY t.name ASC";
    $results = $tododb->get_results($query);
    
    if ($results) {
        foreach ($results as $row) {
            $categories[] = [
                'id' => $row->id,
                'name' => $row->name,
                'url' => get_category_link($row->id)
            ];
        }
    }
    
    return $categories;
}

/**
 * 获取所有标签（用于菜单管理）
 *
 * @return array 标签列表
 */
function get_menu_tags() {
    global $tododb;
    
    $tags = [];
    
    $base = function_exists('todo_get_site_url')
        ? todo_get_site_url()
        : (function_exists('todo_url') ? todo_url() : '');
    $base = rtrim((string) $base, '/');

    // 优先使用 TodoCMS 独立标签表（tags.tag_id / tag_title / tag_slug）
    $results = $tododb->get_results(
        "SELECT tag_id as id, tag_title as name, tag_slug as slug
         FROM {$tododb->prefix}tags
         WHERE tag_status = 'active'
         ORDER BY tag_order ASC, tag_title ASC"
    );
    if ($results) {
        foreach ($results as $row) {
            $id = is_object($row) ? ($row->id ?? null) : ($row['id'] ?? null);
            $name = is_object($row) ? ($row->name ?? '') : ($row['name'] ?? '');
            $slug = is_object($row) ? ($row->slug ?? '') : ($row['slug'] ?? '');

            if ($slug === '') {
                continue;
            }

            $url = $base !== '' ? ($base . '/tag/' . rawurlencode($slug) . '/') : ('/tag/' . rawurlencode($slug) . '/');
            if (function_exists('current_locale') && function_exists('get_language_url')) {
                $url = get_language_url(current_locale(), $url);
            }

            $tags[] = [
                'id' => $id,
                'name' => $name,
                'url' => $url,
                'slug' => $slug
            ];
        }
    }

    // 兼容旧标签表（tags.id / name / slug）
    if (empty($tags)) {
        // 先检查是否存在 id 字段，避免 SQL 报错
        $has_old_schema = false;
        try {
            $check = $tododb->get_results("SHOW COLUMNS FROM {$tododb->prefix}tags LIKE 'id'");
            $has_old_schema = !empty($check);
        } catch (Exception $e) {
            $has_old_schema = false;
        }

        if ($has_old_schema) {
            $results = $tododb->get_results(
                "SELECT id as id, name as name, slug as slug
                 FROM {$tododb->prefix}tags
                 ORDER BY name ASC"
            );
            if ($results) {
                foreach ($results as $row) {
                    $id = is_object($row) ? ($row->id ?? null) : ($row['id'] ?? null);
                    $name = is_object($row) ? ($row->name ?? '') : ($row['name'] ?? '');
                    $slug = is_object($row) ? ($row->slug ?? '') : ($row['slug'] ?? '');

                    if ($slug === '') {
                        continue;
                    }

                    $url = $base !== '' ? ($base . '/tag/' . rawurlencode($slug) . '/') : ('/tag/' . rawurlencode($slug) . '/');
                    if (function_exists('current_locale') && function_exists('get_language_url')) {
                        $url = get_language_url(current_locale(), $url);
                    }

                    $tags[] = [
                        'id' => $id,
                        'name' => $name,
                        'url' => $url,
                        'slug' => $slug
                    ];
                }
            }
        }
    }

    // 回退：兼容 terms/term_taxonomy 的 post_tag
    if (empty($tags)) {
        $results = $tododb->get_results(
            "SELECT t.term_id as id, t.name as name, t.slug as slug
             FROM {$tododb->prefix}terms AS t
             INNER JOIN {$tododb->prefix}term_taxonomy AS tt ON t.term_id = tt.term_id
             WHERE tt.taxonomy = 'post_tag'
             ORDER BY t.name ASC"
        );
        if ($results) {
            foreach ($results as $row) {
                $id = is_object($row) ? ($row->id ?? null) : ($row['id'] ?? null);
                $name = is_object($row) ? ($row->name ?? '') : ($row['name'] ?? '');
                $slug = is_object($row) ? ($row->slug ?? '') : ($row['slug'] ?? '');

                if ($id === null || $id === 0) {
                    continue;
                }

                $tags[] = [
                    'id' => $id,
                    'name' => $name,
                    'url' => get_tag_link($id),
                    'slug' => $slug
                ];
            }
        }
    }
    
    return $tags;
}

/**
 * 显示菜单
 *
 * @param string $location 菜单位置
 * @param array $args 参数
 * @return string 菜单HTML
 */
function display_menu($location, $args = []) {
    $defaults = [
        'container' => 'nav',
        'container_class' => 'site-menu',
        'menu_class' => 'menu',
        'menu_id' => '',
        'item_class' => 'menu-item',
        'link_class' => 'menu-link',
        'depth' => 0,
        'echo' => true
    ];
    
    $args = array_merge($defaults, $args);
    
    // 获取菜单
    $menu = get_menu_by_location($location);
    
    if (!$menu || empty($menu['items'])) {
        return '';
    }

    if (class_exists('Todo_Menu_Manager') && method_exists('Todo_Menu_Manager', 'filter_visible_menu_items')) {
        $menu['items'] = Todo_Menu_Manager::filter_visible_menu_items((array) $menu['items']);
    }
    if (empty($menu['items'])) {
        return '';
    }
    
    // 构建菜单HTML
    $output = '';
    
    if ($args['container']) {
        $output .= '<' . $args['container'];
        if ($args['container_class']) {
            $output .= ' class="' . esc_attr($args['container_class']) . '"';
        }
        $output .= '>';
    }
    
    $output .= '<ul class="' . esc_attr($args['menu_class']) . '"';
    if (!empty($args['menu_id'])) {
        $output .= ' id="' . esc_attr($args['menu_id']) . '"';
    }
    $output .= '>';
    
    // 构建菜单项
    $output .= build_menu_items($menu['items'], 0, $args);
    
    $output .= '</ul>';
    
    if ($args['container']) {
        $output .= '</' . $args['container'] . '>';
    }
    
    if ($args['echo']) {
        echo $output;
    }
    
    return $output;
}

/**
 * 构建菜单项
 *
 * @param array $items 菜单项
 * @param int $parent 父级ID
 * @param array $args 参数
 * @return string 菜单项HTML
 */
function build_menu_items($items, $parent = 0, $args = []) {
    $output = '';

    if (!is_array($items) || empty($items)) {
        return $output;
    }

    // 初始化循环安全参数
    if (!isset($args['_visited']) || !is_array($args['_visited'])) {
        $args['_visited'] = [];
    }
    if (!isset($args['_safe_depth'])) {
        $args['_safe_depth'] = 0;
    }
    // 绝对深度保护，防止异常数据导致无限递归
    if ($args['_safe_depth'] > 100) {
        return $output;
    }

    // 按顺序排序
    uasort($items, function($a, $b) {
        $ao = isset($a['order']) ? (int)$a['order'] : 0;
        $bo = isset($b['order']) ? (int)$b['order'] : 0;
        return $ao - $bo;
    });

    foreach ($items as $id => $item) {
        // 过滤不属于当前父级的项
        if ((int)$item['parent'] !== (int)$parent) {
            continue;
        }
        // 跳过自引用或已访问过的项，避免循环
        if ((int)$id === (int)$parent || in_array($id, $args['_visited'], true)) {
            continue;
        }

        $classes = [$args['item_class']];
        // 追加自定义CSS类
        if (!empty($item['css_class'])) {
            $classes[] = trim($item['css_class']);
        }

        // 检查是否有子菜单
        $has_children = false;
        foreach ($items as $child_id => $child) {
            if ((int)$child['parent'] === (int)$id && $child_id !== $id) {
                $has_children = true;
                break;
            }
        }

        if ($has_children) {
            $classes[] = $args['item_class'] . '-has-children';
        }

        $output .= '<li class="' . esc_attr(implode(' ', $classes)) . '">';

        // 菜单链接
        $output .= '<a href="' . esc_url($item['url']) . '" class="' . esc_attr($args['link_class']) . '"';
        if (isset($item['new_window']) && $item['new_window']) {
            $output .= ' target="_blank" rel="noopener noreferrer"';
        }
        if (!empty($item['attr_title'])) {
            $output .= ' title="' . esc_attr($item['attr_title']) . '"';
        }
        $output .= '>' . esc_html(isset($item['title']) ? $item['title'] : '') . '</a>';

        // 子菜单
        if ($has_children && ($args['depth'] === 0 || (int)$args['depth'] > 1)) {
            $childArgs = $args;
            $childArgs['depth'] = $args['depth'] ? ((int)$args['depth'] - 1) : 0;
            $childArgs['_safe_depth'] = (int)$args['_safe_depth'] + 1;
            $childArgs['_visited'] = array_merge($args['_visited'], [$id]);

            $output .= '<ul class="' . esc_attr($args['menu_class']) . '-sub">';
            $output .= build_menu_items($items, $id, $childArgs);
            $output .= '</ul>';
        }

        $output .= '</li>';
    }

    return $output;
}
