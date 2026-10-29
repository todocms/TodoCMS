<?php
/**
 * 内容管理函数
 *
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 获取文章
 *
 * @param int $post_id 文章ID
 * @return object|null
 */
if (!function_exists('get_post')) {
    function get_post($post_id)
    {
        global $tododb;
        $post_id = intval($post_id);
        return $tododb->get_row("SELECT * FROM {$tododb->prefix}posts WHERE ID = ?", array($post_id));
    }
}

/**
 * 获取文章列表
 *
 * @param array $args 查询参数
 * @return array
 */
if (!function_exists('get_posts')) {
    function get_posts($args = array())
    {
        global $tododb;

        $defaults = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'numberposts' => 5,
            'offset' => 0,
            'orderby' => 'post_date',
            'order' => 'DESC'
        );

        $args = array_merge($defaults, $args);

        $sql = "SELECT * FROM " . TABLE_PREFIX . "posts WHERE 1=1";
        $params = [];

        if ($args['post_type']) {
            $sql .= " AND post_type = ?";
            $params[] = $args['post_type'];
        }

        if ($args['post_status']) {
            $sql .= " AND post_status = ?";
            $params[] = $args['post_status'];
        }

        $valid_orderby = [
            'ID' => 'ID',
            'id' => 'ID',
            'post_date' => 'post_date',
            'date' => 'post_date',
            'post_title' => 'post_title',
            'title' => 'post_title'
        ];
        $orderby_field = isset($valid_orderby[$args['orderby']]) ? $valid_orderby[$args['orderby']] : 'post_date';
        $order_dir = in_array(strtoupper($args['order']), ['ASC', 'DESC']) ? strtoupper($args['order']) : 'DESC';
        $sql .= " ORDER BY {$orderby_field} {$order_dir}";

        if ($args['numberposts'] > 0) {
            $sql .= " LIMIT " . intval($args['offset']) . ", " . intval($args['numberposts']);
        }

        return $tododb->get_results($sql, $params);
    }
}

/**
 * 插入或更新文章
 *
 * @param array $post_data 文章数据
 * @return int|false 文章ID或false
 */
if (!function_exists('todo_insert_post')) {
    function todo_insert_post($post_data)
    {
        global $tododb;

        $defaults = array(
            'post_title' => '',
            'post_content' => '',
            'post_excerpt' => '',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => get_current_user_id(),
            'post_date' => current_time('mysql'),
            'post_modified' => current_time('mysql')
        );

        $post_data = array_merge($defaults, $post_data);

        if (isset($post_data['ID']) && $post_data['ID'] > 0) {
            // 更新文章
            $result = $tododb->update(
                TABLE_PREFIX . 'posts',
                $post_data,
                array('ID' => $post_data['ID'])
            );
            return $result ? $post_data['ID'] : false;
        } else {
            // 插入新文章
            unset($post_data['ID']);
            $result = $tododb->insert(TABLE_PREFIX . 'posts', $post_data);
            return $result ? $tododb->insert_id() : false;
        }
    }
}

/**
 * 删除文章
 *
 * @param int $post_id 文章ID
 * @return bool
 */
if (!function_exists('todo_delete_post')) {
    function todo_delete_post($post_id, $force_delete = false)
    {
        global $tododb;

        if ($force_delete) {
            // 永久删除文章时，需要清理所有相关数据

            // 在删除前获取文章的发布日期，供 sitemap 钩子使用
            $post_before_delete = $tododb->get_row(
                "SELECT post_date, post_status FROM " . TABLE_PREFIX . "posts WHERE ID = ?",
                [$post_id]
            );
            $post_date_before_delete = $post_before_delete ? $post_before_delete->post_date : null;

            // 1. 删除文章自定义字段
            $tododb->delete(TABLE_PREFIX . 'post_custom_fields', array('post_id' => $post_id));
            $tododb->delete(TABLE_PREFIX . 'post_custom_field_translations', array('post_id' => $post_id));
            $tododb->delete(TABLE_PREFIX . 'postmeta', array('post_id' => $post_id));
            $tododb->delete(TABLE_PREFIX . 'post_translations', array('post_id' => $post_id));

            // 2. 删除各个模型字段表中的数据
            $model_tables = array(
                'model_fields_article',
                'model_fields_product',
                'model_fields_images',
                'model_fields_download',
                'model_fields_media',
                'model_fields_single',
                'model_fields_special',
                'model_fields_guestbook',
                'model_fields_recruit'
            );

            foreach ($model_tables as $table) {
                $tododb->delete(TABLE_PREFIX . $table, array('post_id' => $post_id));
            }

            // 3. 删除文章分类关联
            $term_taxonomy_rows = $tododb->get_results(
                "SELECT term_taxonomy_id FROM " . TABLE_PREFIX . "term_relationships WHERE object_id = ?",
                [$post_id]
            );
            $term_taxonomy_ids = array();
            if (!empty($term_taxonomy_rows)) {
                foreach ($term_taxonomy_rows as $row) {
                    $term_taxonomy_ids[] = is_object($row) ? $row->term_taxonomy_id : $row['term_taxonomy_id'];
                }
            }
            if (!empty($term_taxonomy_ids)) {
                $placeholders = implode(',', array_fill(0, count($term_taxonomy_ids), '?'));
                $delete_params = array_merge([$post_id], $term_taxonomy_ids);
                $tododb->query(
                    "DELETE FROM " . TABLE_PREFIX . "term_relationships WHERE object_id = ? AND term_taxonomy_id IN ($placeholders)",
                    $delete_params
                );
                $tododb->query(
                    "UPDATE " . TABLE_PREFIX . "term_taxonomy SET count = CASE WHEN count > 0 THEN count - 1 ELSE 0 END WHERE term_taxonomy_id IN ($placeholders)",
                    $term_taxonomy_ids
                );
            } else {
                $tododb->delete(TABLE_PREFIX . 'term_relationships', array('object_id' => $post_id));
            }
            $tododb->delete(TABLE_PREFIX . 'post_tags', array('post_id' => $post_id));

            // 4. 删除文章评论
            $tododb->query(
                "DELETE FROM " . TABLE_PREFIX . "commentmeta WHERE comment_id IN (SELECT comment_ID FROM " . TABLE_PREFIX . "comments WHERE comment_post_ID = ?)",
                [$post_id]
            );
            $tododb->delete(TABLE_PREFIX . 'comments', array('comment_post_ID' => $post_id));

            // 5. 最后删除文章主记录
            $result = $tododb->delete(TABLE_PREFIX . 'posts', array('ID' => $post_id));

            if ($result && class_exists('Todo_Unified_Cache_Manager')) {
                Todo_Unified_Cache_Manager::clean_post_cache($post_id);
            }
            if ($result && function_exists('todo_do_action')) {
                todo_do_action('todo_post_deleted', $post_id, $post_date_before_delete);
            }

            return $result;
        } else {
            // 移至回收站
            $result = $tododb->update(TABLE_PREFIX . 'posts', array('post_status' => 'trash'), array('ID' => $post_id));

            if ($result && class_exists('Todo_Unified_Cache_Manager')) {
                Todo_Unified_Cache_Manager::clean_post_cache($post_id);
            }
            if ($result && function_exists('todo_do_action')) {
                todo_do_action('todo_post_updated', $post_id);
            }

            return $result;
        }
    }
}

/**
 * 获取分类列表
 *
 * @param array $args 查询参数
 * @return array
 */
if (!function_exists('get_terms')) {
    function get_terms($args = array())
    {
        global $tododb;

        // 深度优化：检查结果池
        if (class_exists('Todo_DB_Result_Pool')) {
            $pool_key = 'get_terms_' . md5(serialize($args));
            $cached = Todo_DB_Result_Pool::get($pool_key);
            if ($cached !== null) {
                return $cached;
            }
        }

        $defaults = array(
            'taxonomy' => 'category',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'
        );

        $args = array_merge($defaults, $args);

        $sql = "SELECT t.term_id, t.name, t.slug, tt.description, tt.parent, tt.count FROM " . TABLE_PREFIX . "terms t "
            . "INNER JOIN " . TABLE_PREFIX . "term_taxonomy tt ON t.term_id = tt.term_id "
            . "WHERE 1=1";
        $params = [];

        if (!empty($args['taxonomy'])) {
            $sql .= " AND tt.taxonomy = ?";
            $params[] = $args['taxonomy'];
        }

        if (!empty($args['hide_empty'])) {
            $sql .= " AND tt.count > 0";
        }

        if (isset($args['parent'])) {
            $parent = intval($args['parent']);
            $sql .= " AND tt.parent = ?";
            $params[] = $parent;
        }

        if (isset($args['include']) && is_array($args['include']) && !empty($args['include'])) {
            $ids = array_map('intval', $args['include']);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql .= " AND t.term_id IN ({$placeholders})";
            $params = array_merge($params, $ids);
        }

        $orderby_map = [
            'name' => 't.name',
            'slug' => 't.slug',
            'term_id' => 't.term_id',
            'count' => 'tt.count'
        ];
        $orderby = isset($orderby_map[$args['orderby']]) ? $orderby_map[$args['orderby']] : 't.name';
        $order = strtoupper($args['order']) === 'DESC' ? 'DESC' : 'ASC';
        $sql .= " ORDER BY {$orderby} {$order}";

        $results = $tododb->get_results($sql, $params);

        // 深度优化：保存结果到结果池
        if (class_exists('Todo_DB_Result_Pool')) {
            $pool_key = 'get_terms_' . md5(serialize($args));
            Todo_DB_Result_Pool::set($pool_key, $results);
        }

        return $results;
    }
}

/**
 * 获取文章的分类
 *
 * @param int $post_id 文章ID
 * @param string $taxonomy 分类法
 * @return array
 */
if (!function_exists('get_the_terms')) {
    function get_the_terms($post_id, $taxonomy = 'category')
    {
        // 优先从 ORM 缓存中读取
        if (class_exists('Todo_Post_ORM')) {
            $orm_terms = Todo_Post_ORM::get_terms_for_post($post_id, $taxonomy);
            if ($orm_terms !== null) {
                $result = [];
                foreach ($orm_terms as $term) {
                    $result[] = (object) $term;
                }
                return $result;
            }
        }

        // 优先从批量加载的缓存中读取（如果存在）
        if (function_exists('_todo_post_term_cache_manager')) {
            $cached_terms = _todo_post_term_cache_manager('get', $post_id, $taxonomy);
            if ($cached_terms !== false && is_array($cached_terms) && !empty($cached_terms)) {
                // 转换为与原始查询相同的格式（确保包含所有字段）
                $result = [];
                foreach ($cached_terms as $term) {
                    if (is_object($term)) {
                        $result[] = $term;
                    } elseif (is_array($term)) {
                        $result[] = (object) $term;
                    }
                }
                return $result;
            }
        }

        // 如果缓存未命中，尝试触发批量加载（如果可能）
        if (function_exists('update_post_term_cache')) {
            update_post_term_cache([$post_id], $taxonomy);
            // 再次尝试从缓存读取
            if (function_exists('_todo_post_term_cache_manager')) {
                $cached_terms = _todo_post_term_cache_manager('get', $post_id, $taxonomy);
                if ($cached_terms !== false && is_array($cached_terms) && !empty($cached_terms)) {
                    $result = [];
                    foreach ($cached_terms as $term) {
                        if (is_object($term)) {
                            $result[] = $term;
                        } elseif (is_array($term)) {
                            $result[] = (object) $term;
                        }
                    }
                    return $result;
                }
            }
        }

        // 如果缓存仍然未命中，直接查询数据库（作为后备）
        global $tododb;

        $sql = "SELECT t.* FROM " . TABLE_PREFIX . "terms t "
            . "INNER JOIN " . TABLE_PREFIX . "term_taxonomy tt ON t.term_id = tt.term_id "
            . "INNER JOIN " . TABLE_PREFIX . "term_relationships tr ON tt.term_taxonomy_id = tr.term_taxonomy_id "
            . "WHERE tr.object_id = ? AND tt.taxonomy = ?";

        $results = $tododb->get_results($sql, [$post_id, $taxonomy]);

        // 将查询结果存入缓存，以便后续使用
        if (!empty($results) && function_exists('_todo_post_term_cache_manager')) {
            _todo_post_term_cache_manager('set', $post_id, $taxonomy, $results);
        }

        return $results;
    }
}

/**
 * 设置文章分类
 *
 * @param int $post_id 文章ID
 * @param array $terms 分类ID数组
 * @param string $taxonomy 分类法
 * @return bool
 */
if (!function_exists('todo_set_object_terms')) {
    function todo_set_object_terms($post_id, $terms, $taxonomy = 'category')
    {
        global $tododb;

        // 删除现有关系
        $tododb->delete(TABLE_PREFIX . 'term_relationships', array('object_id' => $post_id));

        // 添加新关系
        if (!empty($terms)) {
            foreach ($terms as $term_id) {
                $tododb->insert(TABLE_PREFIX . 'term_relationships', array(
                    'object_id' => $post_id,
                    'term_taxonomy_id' => $term_id
                ));
            }
        }

        if (class_exists('Todo_Unified_Cache_Manager')) {
            Todo_Unified_Cache_Manager::clean_post_cache($post_id);
        }
        if (function_exists('todo_do_action')) {
            todo_do_action('todo_post_updated', $post_id);
        }

        return true;
    }
}

/**
 * 获取当前时间
 *
 * @param string $type 时间格式类型
 * @return string
 */
if (!function_exists('current_time')) {
    function current_time($type = 'mysql')
    {
        switch ($type) {
            case 'mysql':
                return date('Y-m-d H:i:s');
            case 'timestamp':
                return time();
            default:
                return date($type);
        }
    }
}

/**
 * 获取文章永久链接
 *
 * @param int $post_id 文章ID
 * @return string
 */
if (!function_exists('get_permalink')) {
    function get_permalink($post = null)
    {
        static $permalink_cache = array();

        // 统一获取文章对象
        $obj = null;
        if (is_object($post) && isset($post->ID)) {
            $obj = $post;
        } elseif (is_numeric($post) && $post > 0) {
            // 优化：优先检查静态缓存
            if (isset($permalink_cache[$post])) {
                return $permalink_cache[$post];
            }
            // 优化：使用 get_post 代替直接查询，利用其内部对象缓存
            $obj = get_post($post);
        } elseif ($post !== null) {
            $obj = get_post($post);
        } else {
            $obj = isset($GLOBALS['todo_post']) ? $GLOBALS['todo_post'] : null;
        }

        if (!$obj) {
            return '';
        }

        // 再次检查静态缓存（针对传入对象但已算过的情况）
        if (isset($permalink_cache[$obj->ID])) {
            return $permalink_cache[$obj->ID];
        }

        // 判断是否为 single 模型，如果是，直接返回根目录 .html 链接
        $primary_cat_id = 0;
        if (isset($obj->category_id) && intval($obj->category_id) > 0) {
            $primary_cat_id = intval($obj->category_id);
        } elseif (function_exists('todo_get_post_primary_category_id')) {
            $primary_cat_id = intval(todo_get_post_primary_category_id($obj->ID));
        }

        if ($primary_cat_id > 0 && class_exists('Todo_Model_Manager') && method_exists('Todo_Model_Manager', 'get_category_model')) {
            if (Todo_Model_Manager::get_category_model($primary_cat_id) === 'single') {
                $custom = isset($obj->custom_url) ? trim($obj->custom_url) : '';
                if ($custom !== '' && preg_match('/^(https?:)?\/\//i', $custom)) {
                    $final_url = $custom;
                } else {
                    if ($custom !== '') {
                        $path = parse_url($custom, PHP_URL_PATH) ?: $custom;
                        $slug = pathinfo(trim($path, '/'), PATHINFO_FILENAME);
                    } elseif (!empty($obj->post_name)) {
                        $slug = $obj->post_name;
                    } else {
                        $slug = $obj->ID;
                    }
                    $final_url = todo_url($slug . '.html');
                }
                $permalink_cache[$obj->ID] = $final_url;
                return $final_url;
            }
        }

        // 固定链接结构
        $structure = get_option('permalink_structure', '');
        if (empty($structure)) {
            $base = rtrim(todo_url(), '/');
            $cat = 0;
            if (isset($obj->category_id) && intval($obj->category_id) > 0) {
                $cat = intval($obj->category_id);
            } elseif (function_exists('todo_get_post_primary_category_id')) {
                $cat = intval(todo_get_post_primary_category_id($obj->ID));
            }
            $final_url = ($cat > 0) ? ($base . '/?cat=' . $cat . '&p=' . intval($obj->ID)) : ($base . '/?p=' . intval($obj->ID));
            $permalink_cache[$obj->ID] = $final_url;
            return $final_url;
        }

        // 自定义 URL 作为别名（仅当为相对路径时），否则当作外链直接返回
        $custom = isset($obj->custom_url) ? trim($obj->custom_url) : '';
        if ($custom !== '' && preg_match('/^(https?:)?\/\//i', $custom)) {
            return $custom;
        }

        // 时间标签
        $post_date_ts = strtotime($obj->post_date ?: date('Y-m-d H:i:s'));
        $permalink = $structure;
        $permalink = str_replace('%year%', date('Y', $post_date_ts), $permalink);
        $permalink = str_replace('%monthnum%', date('m', $post_date_ts), $permalink);
        $permalink = str_replace('%day%', date('d', $post_date_ts), $permalink);
        $permalink = str_replace('%hour%', date('H', $post_date_ts), $permalink);
        $permalink = str_replace('%minute%', date('i', $post_date_ts), $permalink);
        $permalink = str_replace('%second%', date('s', $post_date_ts), $permalink);

        // 文章别名（优先使用 custom_url 作为路径部分，仍然保留结构前缀）
        if ($custom !== '') {
            $permalink = str_replace('%post_id%', $obj->ID, $permalink);
            $permalink = str_replace('%postname%', ltrim($custom, '/'), $permalink);
        } else {
            $slug = $obj->post_name ? $obj->post_name : todo_generate_slug($obj->post_title, $obj->ID);
            $permalink = str_replace('%post_id%', $obj->ID, $permalink);
            $permalink = str_replace('%postname%', $slug, $permalink);
        }

        // 分类标签
        if (strpos($permalink, '%category%') !== false) {
            $category_slug = 'uncategorized';
            if (function_exists('get_the_terms')) {
                $terms = get_the_terms($obj->ID, 'category');
                if ($terms && is_array($terms)) {
                    $first = reset($terms);
                    if (is_object($first) && isset($first->slug) && $first->slug) {
                        $category_slug = $first->slug;
                    }
                }
            } elseif (function_exists('get_post_category')) {
                $category = get_post_category($obj->ID);
                if ($category && isset($category->slug) && $category->slug) {
                    $category_slug = $category->slug;
                }
            }
            $permalink = str_replace('%category%', $category_slug, $permalink);
        }

        // 面包屑标签（多级分类路径）
        if (strpos($permalink, '%breadcrumb%') !== false) {
            $breadcrumb_path = '';
            if (function_exists('todo_get_category_breadcrumb_path')) {
                $breadcrumb_path = todo_get_category_breadcrumb_path($obj->ID);
            }
            $permalink = str_replace('%breadcrumb%', $breadcrumb_path, $permalink);
        }

        // 作者标签（缺失作者时移除该段）
        if (strpos($permalink, '%author%') !== false) {
            $author_slug = '';
            if (function_exists('get_user_by')) {
                $author = get_user_by('id', $obj->post_author);
                if ($author && !empty($author->user_login)) {
                    $author_slug = $author->user_login;
                }
            }
            $permalink = str_replace('%author%', $author_slug, $permalink);
        }

        // 规范化路径，移除重复斜杠
        $permalink = preg_replace('#/{2,}#', '/', $permalink);

        // 确保以 / 开头
        if (substr($permalink, 0, 1) !== '/') {
            $permalink = '/' . $permalink;
        }
        // 统一补齐 .html 后缀（前台解析要求）
        if (!preg_match('/\\.html$/i', $permalink)) {
            $permalink = rtrim($permalink, '/') . '.html';
        }

        $url = todo_url(ltrim($permalink, '/'));
        
        // 写入缓存
        $permalink_cache[$obj->ID] = $url;
        
        // 语言前缀由调用方（todo_get_permalink 或多语言聚合器）统一注入，避免重复前缀与递归
        return $url;
    }
}

/**
 * 获取文章标题
 *
 * @param int $post_id 文章ID
 * @return string
 */
if (!function_exists('get_the_title')) {
    function get_the_title($post_id = 0)
    {
        if (!$post_id) {
            global $post;
            $post_id = $post ? $post->ID : 0;
        }

        $post = get_post($post_id);
        return $post ? $post->post_title : '';
    }
}

/**
 * 获取文章内容
 *
 * @param int $post_id 文章ID
 * @return string
 */
if (!function_exists('get_the_content')) {
    function get_the_content($post_id = 0)
    {
        if (!$post_id) {
            global $post;
            $post_id = $post ? $post->ID : 0;
        }

        $post = get_post($post_id);
        return $post ? $post->post_content : '';
    }
}
// Helper: 获取主菜单结构（带静态缓存）
if (!function_exists('todo_get_primary_menu_structure')) {
    function todo_get_primary_menu_structure()
    {
        static $menu_structure = null;
        if ($menu_structure !== null) {
            return $menu_structure;
        }

        global $tododb;
        $menu_structure = ['menu_id' => 0, 'items' => []];

        // 1. 获取主菜单ID
        $menu = $tododb->get_row("SELECT id FROM " . $tododb->prefix . "menus WHERE location = ? AND status = 1 LIMIT 1", ['primary']);
        if (!$menu) {
            return $menu_structure;
        }
        $menu_structure['menu_id'] = $menu->id;

        // 2. 获取所有菜单项（一次性查询）
        // 优化：只获取必要的字段
        $items = $tododb->get_results("SELECT id, parent_id, type, object_id, url FROM " . $tododb->prefix . "menu_items WHERE menu_id = ? ORDER BY menu_order ASC", [$menu->id]);

        if ($items) {
            // 构建 object_id => item 映射，方便快速查找
            foreach ($items as $item) {
                if ($item->type === 'category' && !empty($item->object_id)) {
                    // 注意：一个分类可能对应多个菜单项，这里只取第一个（通常是最深或最靠前的）
                    // 或者构建一个列表。为了面包屑，我们通常只需要找到一个匹配即可。
                    if (!isset($menu_structure['items'][$item->object_id])) {
                        $menu_structure['items'][$item->object_id] = $item;
                    }
                }
                // 同时保存 id => item 映射，用于回溯父级
                $menu_structure['by_id'][$item->id] = $item;
            }
        }

        return $menu_structure;
    }
}

// Helper: 获取文章所属首个分类的完整层级路径（slug）
// 增强：优先采用“主导航(primary)”菜单中的层级作为前缀；若菜单未覆盖，则回退到分类层级
if (!function_exists('todo_get_category_breadcrumb_path')) {
    function todo_get_category_breadcrumb_path($post_id)
    {
        // 1. 静态缓存检查
        static $static_cache = [];
        if (isset($static_cache[$post_id])) {
            return $static_cache[$post_id];
        }

        // 2. 持久化缓存检查
        $cache_key = 'post_breadcrumb_' . $post_id;
        if (class_exists('Todo_Cache_Manager')) {
            $cached = Todo_Cache_Manager::get($cache_key, 'post_meta');
            if ($cached !== false) {
                $static_cache[$post_id] = $cached;
                return $cached;
            }
        }

        global $tododb;
        try {
            // 1) 获取该文章关联的所有分类（使用优化的 get_the_terms，利用 ORM 或批量缓存）
            $rows = function_exists('get_the_terms') ? get_the_terms($post_id, 'category') : null;
            
            if (!$rows || count($rows) === 0) {
                // 如果 get_the_terms 不可用或返回空，回退到数据库查询（作为兜底）
                $sql = "SELECT tt.term_id, tt.parent, tr.term_order FROM " . TABLE_PREFIX . "term_relationships tr 
                        INNER JOIN " . TABLE_PREFIX . "term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id 
                        WHERE tr.object_id = ? AND tt.taxonomy = 'category' 
                        ORDER BY tr.term_order ASC";
                $rows = $tododb->get_results($sql, [$post_id]);
            }

            if (!$rows || count($rows) === 0) {
                $static_cache[$post_id] = '';
                if (class_exists('Todo_Cache_Manager')) {
                    Todo_Cache_Manager::set($cache_key, '', 300, 'post_meta');
                }
                return '';
            }

            // 2) 选择“主分类”：深度最深，若并列则 term_order 最靠前
            $deepest = null;
            $deepest_depth = -1;

            // 性能优化：批量加载所有分类层级关系，避免循环中的 N+1 查询
            static $category_hierarchy = null;
            if ($category_hierarchy === null) {
                $category_hierarchy = [];
                $all_tax = $tododb->get_results("SELECT term_id, parent FROM " . TABLE_PREFIX . "term_taxonomy WHERE taxonomy = 'category'");
                if ($all_tax) {
                    foreach ($all_tax as $tax) {
                        $category_hierarchy[(int)$tax->term_id] = (int)$tax->parent;
                    }
                }
            }

            foreach ($rows as $row) {
                $term_id = is_array($row) ? (int) $row['term_id'] : (int) $row->term_id;
                $parent = is_array($row) ? (int) $row['parent'] : (int) $row->parent;
                $order = is_array($row) ? (int) $row['term_order'] : (int) $row->term_order;

                $depth = 0;
                $pid = $parent;
                while ($pid && $pid > 0) {
                    if (isset($category_hierarchy[$pid])) {
                        $pid = $category_hierarchy[$pid];
                        $depth++;
                    } else {
                        break;
                    }
                }
                if ($depth > $deepest_depth) {
                    $deepest_depth = $depth;
                    $deepest = array('term_id' => $term_id, 'parent' => $parent, 'term_order' => $order);
                } elseif ($depth === $deepest_depth && $deepest !== null && $order < $deepest['term_order']) {
                    $deepest = array('term_id' => $term_id, 'parent' => $parent, 'term_order' => $order);
                }
            }
            if ($deepest === null) {
                $static_cache[$post_id] = '';
                if (class_exists('Todo_Cache_Manager')) {
                    Todo_Cache_Manager::set($cache_key, '', 3600, 'post_meta');
                }
                return '';
            }

            // 3) 构建分类链（ID与slug），顺序：父级→子级（根在前，最深在后）
            $ids_chain = array();
            $slugs_chain = array();
            $cur_term_id = (int) $deepest['term_id'];
            $parent_id = (int) $deepest['parent'];

            // 性能优化：批量加载所有分类的 slug
            static $category_slugs = null;
            if ($category_slugs === null) {
                $category_slugs = [];
                $all_terms = $tododb->get_results("SELECT t.term_id, t.slug FROM " . TABLE_PREFIX . "terms t INNER JOIN " . TABLE_PREFIX . "term_taxonomy tt ON t.term_id = tt.term_id WHERE tt.taxonomy = 'category'");
                if ($all_terms) {
                    foreach ($all_terms as $t) {
                        $category_slugs[(int)$t->term_id] = $t->slug;
                    }
                }
            }

            // 当前分类
            if (isset($category_slugs[$cur_term_id])) {
                $cur_slug = $category_slugs[$cur_term_id];
                $ids_chain[] = $cur_term_id;
                $slugs_chain[] = function_exists('sanitize_title') ? sanitize_title($cur_slug) : $cur_slug;
            }

            // 向上追溯父级并插入到链前端
            while ($parent_id && $parent_id > 0) {
                if (isset($category_slugs[$parent_id])) {
                    $pslug = $category_slugs[$parent_id];
                    array_unshift($ids_chain, $parent_id);
                    array_unshift($slugs_chain, function_exists('sanitize_title') ? sanitize_title($pslug) : $pslug);
                    $parent_id = isset($category_hierarchy[$parent_id]) ? $category_hierarchy[$parent_id] : 0;
                } else {
                    break;
                }
            }

            // 4) 尝试匹配“主导航(primary)”菜单，寻找覆盖前缀
            $menu_prefix_segments = array();
            $matched_category_id = null;
            $menu_chain_items = array();

            // 使用优化后的 Helper 获取菜单结构（避免 N+1 查询）
            $menu_structure = todo_get_primary_menu_structure();

            if (!empty($menu_structure['menu_id'])) {
                // 从最深开始，向上查找第一个出现在菜单中的分类项
                $candidate_ids = array_reverse($ids_chain); // 最深在前
                $matched_item = null;

                foreach ($candidate_ids as $cid) {
                    if (isset($menu_structure['items'][$cid])) {
                        $matched_item = $menu_structure['items'][$cid];
                        $matched_category_id = $cid;
                        break;
                    }
                }

                // 若找到，向上回溯菜单父链，得到完整菜单前缀（根在前）
                if ($matched_item) {
                    $cur_item = $matched_item;
                    while ($cur_item) {
                        array_unshift($menu_chain_items, $cur_item);
                        if (!empty($cur_item->parent_id) && (int) $cur_item->parent_id > 0 && isset($menu_structure['by_id'][$cur_item->parent_id])) {
                            $cur_item = $menu_structure['by_id'][$cur_item->parent_id];
                        } else {
                            $cur_item = null;
                        }
                    }
                    // 将菜单链转换为路径段
                    foreach ($menu_chain_items as $mi) {
                        // Ensure $mi is an object before accessing properties
                        if (!is_object($mi)) {
                            continue;
                        }

                        $type = isset($mi->type) ? $mi->type : '';
                        if ($type === 'category' && !empty($mi->object_id)) {
                            $seg = isset($category_slugs[$mi->object_id]) ? $category_slugs[$mi->object_id] : null;
                            if ($seg) {
                                $menu_prefix_segments[] = function_exists('sanitize_title') ? sanitize_title($seg) : $seg;
                            }
                        } elseif ($type === 'custom' || $type === 'external' || $type === 'content') {
                            // 解析URL的路径部分为段
                            $url = isset($mi->url) ? $mi->url : '';
                            if ($type === 'content' && empty($url) && !empty($mi->object_id) && function_exists('todo_get_permalink')) {
                                // 内容项尽量使用固定链接
                                $url = todo_get_permalink((int) $mi->object_id);
                            }
                            $path = $url ? parse_url($url, PHP_URL_PATH) : '';
                            if ($path) {
                                $path = trim($path, '/');
                                if ($path !== '') {
                                    $parts = explode('/', $path);
                                    foreach ($parts as $p) {
                                        if ($p !== '') {
                                            $menu_prefix_segments[] = function_exists('sanitize_title') ? sanitize_title($p) : $p;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                if (!empty($menu_prefix_segments)) {
                    $segments = $menu_prefix_segments;
                } else {
                    $segments = $slugs_chain;
                }
            } else {
                $segments = $slugs_chain; // 纯分类层级
            }

            $path = implode('/', $segments);

            // 写入缓存
            $static_cache[$post_id] = $path;
            if (class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set($cache_key, $path, 3600, 'post_meta');
            }

            return $path;
        } catch (Exception $e) {
            error_log('todo_get_category_breadcrumb_path error: ' . $e->getMessage());
            return '';
        }
    }
}
