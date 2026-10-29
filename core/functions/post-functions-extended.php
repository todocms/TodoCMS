<?php
/**
 * TodoCMS 扩展文章相关函数
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 获取文章自定义字段（仅返回真正的自定义字段）
 */
function get_post_custom_fields($post_id)
{
    global $tododb;

    if (!$post_id) {
        return array();
    }

    // 使用 Post Fields Manager 进行智能判断
    $fields_manager = Todo_Post_Fields_Manager::get_instance();

    // 获取文章的模型ID进行智能判断
    $model_id = $fields_manager->get_post_model_id($post_id);

    // 优化：使用 get_post_meta 利用 ORM 缓存，避免直接查询数据库
    // get_post_meta($post_id) 返回所有元数据
    $all_meta = get_post_meta($post_id);

    $custom_fields = array();
    if ($all_meta && is_array($all_meta)) {
        foreach ($all_meta as $key => $value) {
            // 使用智能判断过滤自定义字段
            if ($fields_manager->is_custom_field($key, $model_id)) {
                $custom_fields[$key] = $value;
            }
        }
    }

    return $custom_fields;
}

/**
 * 获取单个文章自定义字段
 */
function get_post_custom_field($post_id, $field_name, $default = '')
{
    $custom_fields = get_post_custom_fields($post_id);
    return isset($custom_fields[$field_name]) ? $custom_fields[$field_name] : $default;
}

/**
 * 更新文章自定义字段
 */
function update_post_custom_field($post_id, $field_name, $value)
{
    global $tododb;

    if (!$post_id || !$field_name) {
        return false;
    }

    // 使用 Post Fields Manager 进行智能判断
    $fields_manager = Todo_Post_Fields_Manager::get_instance();

    // 获取文章的模型ID进行智能判断
    $model_id = $fields_manager->get_post_model_id($post_id);

    // 检查是否为自定义字段，如果不是则不保存
    if (!$fields_manager->is_custom_field($field_name, $model_id)) {
        error_log("TodoCMS: Attempted to save system field '{$field_name}' as custom field for post {$post_id}");
        return false;
    }

    $value = maybe_serialize($value);

    // 检查字段是否存在
    $existing = $tododb->get_var(
        "SELECT id FROM " . $tododb->prefix . "post_custom_fields WHERE post_id = ? AND field_key = ?",
        [$post_id, $field_name]
    );

    $result = false;
    if ($existing) {
        // 更新现有字段
        $result = $tododb->update(
            $tododb->prefix . 'post_custom_fields',
            array('field_value' => $value),
            array('post_id' => $post_id, 'field_key' => $field_name),
            array('%s'),
            array('%d', '%s')
        );
    } else {
        // 插入新字段
        $result = $tododb->insert(
            $tododb->prefix . 'post_custom_fields',
            array(
                'post_id' => $post_id,
                'field_key' => $field_name,
                'field_value' => $value
            ),
            array('%d', '%s', '%s')
        );
    }

    if ($result !== false) {
        // 清理缓存
        if (class_exists('Todo_Unified_Cache_Manager')) {
            Todo_Unified_Cache_Manager::clean_post_cache($post_id);
        }
        // 触发更新钩子
        if (function_exists('todo_do_action')) {
            todo_do_action('todo_post_updated', $post_id);
        }
    }

    return $result;
}

/**
 * 删除文章自定义字段
 */
function delete_post_custom_field($post_id, $field_name)
{
    global $tododb;

    $result = $tododb->delete(
        $tododb->prefix . 'post_custom_fields',
        array('post_id' => $post_id, 'field_key' => $field_name),
        array('%d', '%s')
    );

    if ($result !== false) {
        if (class_exists('Todo_Unified_Cache_Manager')) {
            Todo_Unified_Cache_Manager::clean_post_cache($post_id);
        }
        if (function_exists('todo_do_action')) {
            todo_do_action('todo_post_updated', $post_id);
        }
    }

    return $result;
}

/**
 * 获取文章永久链接
 */
function get_post_permalink($post_id)
{
    global $tododb;

    if (!$post_id) {
        return '';
    }

    $post = $tododb->get_row(
        "SELECT * FROM " . $tododb->prefix . "posts WHERE id = ?",
        [$post_id]
    );

    if (!$post) {
        return '';
    }

    // 检查是否有自定义URL
    $custom_url = get_post_custom_field($post_id, 'custom_url');
    if ($custom_url) {
        return todo_get_site_url() . '/' . ltrim($custom_url, '/');
    }

    // 获取分类信息
    $category = $tododb->get_row(
        "SELECT t.term_id as id, t.name, t.slug 
         FROM " . $tododb->prefix . "terms t 
         INNER JOIN " . $tododb->prefix . "term_taxonomy tt ON t.term_id = tt.term_id 
         WHERE t.term_id = ? AND tt.taxonomy = 'category'",
        [$post->category_id]
    );

    if ($category) {
        return todo_get_site_url() . '/' . $category->slug . '/' . $post->slug . '.html';
    }

    return todo_get_site_url() . '/post/' . $post->slug . '.html';
}

/**
 * 获取文章摘要
 */
function get_post_excerpt($post_id, $length = 150)
{
    global $tododb;

    $post = $tododb->get_row(
        "SELECT excerpt, content FROM " . $tododb->prefix . "posts WHERE id = ?",
        [$post_id]
    );

    if (!$post) {
        return '';
    }

    // 如果有手动摘要，优先使用
    if (!empty($post->excerpt)) {
        return $post->excerpt;
    }

    // 从内容中提取摘要
    $content = strip_tags($post->content);
    return truncate_string($content, $length);
}

/**
 * 获取文章缩略图
 */
function get_post_thumbnail_extended($post_id, $size = 'medium')
{
    global $tododb;

    $post = $tododb->get_row("SELECT featured_image FROM {$tododb->prefix}posts WHERE ID = ?", [$post_id]);

    if (!$post || empty($post->featured_image)) {
        return '';
    }

    return $post->featured_image;
}

/**
 * 设置文章缩略图（扩展版本）
 */
function set_post_thumbnail_extended($post_id, $thumbnail_id)
{
    global $tododb;

    // 获取附件URL
    $attachment_url = todo_get_attachment_url($thumbnail_id);
    if (!$attachment_url) {
        return false;
    }

    $now_local = function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s');
    $now_gmt = function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s');

    // 更新posts表的featured_image字段（同时更新 post_modified，确保缓存可被正确失效）
    $sql = "UPDATE {$tododb->prefix}posts SET featured_image = ?, post_modified = ?, post_modified_gmt = ? WHERE ID = ?";
    $result = $tododb->query($sql, [$attachment_url, $now_local, $now_gmt, $post_id]);

    return $result !== false;
}

/**
 * 获取文章标签
 */
function get_post_tags($post_id)
{
    global $tododb;

    // 性能优化：优先使用新的标签管理器（带缓存）
    if (function_exists('todo_get_post_tags')) {
        $tags = todo_get_post_tags($post_id);
        // 确保返回格式包含 id 属性（兼容旧代码）
        foreach ($tags as $index => $tag) {
            if (is_object($tag) && !isset($tag->id) && isset($tag->term_id)) {
                if ($tag instanceof stdClass) {
                    $tag = clone $tag;
                    $tag->id = $tag->term_id;
                } else {
                    $tag_data = get_object_vars($tag);
                    $tag_data['id'] = $tag->term_id;
                    $tag = (object) $tag_data;
                }
                $tags[$index] = $tag;
            }
        }
        return $tags;
    }

    $sql = "SELECT t.* FROM " . $tododb->prefix . "tags t 
            INNER JOIN " . $tododb->prefix . "post_tags pt ON t.id = pt.tag_id 
            WHERE pt.post_id = ? ORDER BY t.name";

    return $tododb->get_results($sql, [$post_id]);
}

/**
 * 为文章添加标签
 */
function add_post_tag($post_id, $tag_name)
{
    global $tododb;

    // 查找或创建标签
    $sql = "SELECT * FROM " . $tododb->prefix . "tags WHERE name = ?";
    $tag = $tododb->get_row($sql, [$tag_name]);

    if (!$tag) {
        // 创建新标签
        $tododb->insert(
            $tododb->prefix . 'tags',
            array('name' => $tag_name, 'slug' => sanitize_title($tag_name)),
            array('%s', '%s')
        );
        $tag_id = $tododb->insert_id;
    } else {
        $tag_id = $tag->id;
    }

    // 检查关联是否已存在
    $sql = "SELECT COUNT(*) FROM " . $tododb->prefix . "post_tags WHERE post_id = ? AND tag_id = ?";
    $exists = $tododb->get_var($sql, [$post_id, $tag_id]);

    $result = true;
    if (!$exists) {
        $result = $tododb->insert(
            $tododb->prefix . 'post_tags',
            array('post_id' => $post_id, 'tag_id' => $tag_id),
            array('%d', '%d')
        );

        if ($result !== false) {
            if (class_exists('Todo_Unified_Cache_Manager')) {
                Todo_Unified_Cache_Manager::clean_post_cache($post_id);
            }
            if (function_exists('todo_do_action')) {
                todo_do_action('todo_post_updated', $post_id);
            }
        }
    }

    return $result;
}

/**
 * 移除文章标签
 */
function remove_post_tag($post_id, $tag_id)
{
    global $tododb;

    $result = $tododb->delete(
        $tododb->prefix . 'post_tags',
        array('post_id' => $post_id, 'tag_id' => $tag_id),
        array('%d', '%d')
    );

    if ($result !== false) {
        if (class_exists('Todo_Unified_Cache_Manager')) {
            Todo_Unified_Cache_Manager::clean_post_cache($post_id);
        }
        if (function_exists('todo_do_action')) {
            todo_do_action('todo_post_updated', $post_id);
        }
    }

    return $result;
}

/**
 * 获取相关文章
 */
function get_related_posts($post_id, $limit = 5)
{
    global $tododb;

    // 获取当前文章的标签
    $tags = get_post_tags($post_id);
    if (empty($tags)) {
        return array();
    }

    $tag_ids = array_map(function ($tag) {
        return $tag->id;
    }, $tags);
    $tag_ids_str = implode(',', array_map('intval', $tag_ids));

    $sql = "SELECT DISTINCT p.*, COUNT(pt.tag_id) as tag_count 
            FROM " . $tododb->prefix . "posts p
            INNER JOIN " . $tododb->prefix . "post_tags pt ON p.id = pt.post_id
            WHERE pt.tag_id IN ($tag_ids_str) 
            AND p.id != ? 
            AND p.status = 'published'
            GROUP BY p.id
            ORDER BY tag_count DESC, p.created_at DESC
            LIMIT ?";

    return $tododb->get_results($sql, [$post_id, $limit]);
}

/**
 * 获取文章浏览次数
 * 从 posts.view_count 字段读取（已从自定义字段迁移）
 */
function get_post_views($post_id)
{
    global $tododb;

    if (!$post_id) {
        return 0;
    }

    // 性能优化：优先从文章对象获取（如果已缓存）
    $post = get_post($post_id);
    if ($post && isset($post->view_count)) {
        return (int) $post->view_count;
    }

    try {
        $view_count = $tododb->get_var(
            "SELECT view_count FROM {$tododb->prefix}posts WHERE ID = ?",
            [$post_id]
        );
        return $view_count !== null ? (int) $view_count : 0;
    } catch (Exception $e) {
        error_log("获取文章浏览次数失败: " . $e->getMessage());
        return 0;
    }
}

/**
 * 增加文章浏览次数
 * 更新 posts.view_count 字段（已从自定义字段迁移）
 */
function increment_post_views($post_id)
{
    global $tododb;

    if (!$post_id) {
        return false;
    }

    try {
        $result = $tododb->query(
            "UPDATE {$tododb->prefix}posts SET view_count = view_count + 1, updated_at = updated_at, post_modified = post_modified, post_modified_gmt = post_modified_gmt WHERE ID = ?",
            [$post_id]
        );
        return $result !== false;
    } catch (Exception $e) {
        error_log("增加文章浏览次数失败: " . $e->getMessage());
        return false;
    }
}

/**
 * 检查文章是否置顶
 */
function is_post_sticky($post_id)
{
    return (bool) get_post_custom_field($post_id, 'is_sticky', false);
}

/**
 * 设置文章置顶状态
 */
function set_post_sticky($post_id, $sticky = true)
{
    return update_post_custom_field($post_id, 'is_sticky', $sticky);
}

/**
 * 获取文章状态
 */
function get_post_status($post_id)
{
    global $tododb;

    $status = $tododb->get_var(
        "SELECT status FROM " . $tododb->prefix . "posts WHERE id = ?",
        [$post_id]
    );

    return $status ?: 'draft';
}

/**
 * 更新文章状态
 */
function update_post_status($post_id, $status)
{
    global $tododb;

    $allowed_statuses = array('draft', 'published', 'private', 'trash');
    if (!in_array($status, $allowed_statuses)) {
        return false;
    }

    $result = $tododb->update(
        $tododb->prefix . 'posts',
        array('status' => $status),
        array('id' => $post_id),
        array('%s'),
        array('%d')
    );

    if ($result !== false) {
        if (class_exists('Todo_Unified_Cache_Manager')) {
            Todo_Unified_Cache_Manager::clean_post_cache($post_id);
        }
        if (function_exists('todo_do_action')) {
            todo_do_action('todo_post_updated', $post_id);
        }
    }

    return $result;
}

/**
 * 获取文章特色图片
 * 从 posts.featured_image 字段读取（已从 post_meta 迁移）
 * @param int $post_id 文章ID
 * @return string|false 图片URL或false
 */
function get_post_featured_image($post_id)
{
    global $tododb;

    if (!$post_id) {
        return false;
    }

    try {
        $post = $tododb->get_row(
            "SELECT featured_image FROM {$tododb->prefix}posts WHERE ID = ?",
            [$post_id],
            ARRAY_A
        );

        if (!$post || empty($post['featured_image'])) {
            return false;
        }

        $featured_image = $post['featured_image'];

        // 优先根据当前语言环境尝试匹配带有对应 seo_keywords 的图片
        if (function_exists('todo_match_featured_image_by_lang')) {
            $matched_lang_url = todo_match_featured_image_by_lang($featured_image);
            if (!empty($matched_lang_url)) {
                return $matched_lang_url;
            }
        }

        // 处理JSON格式（支持单图和多图）

        if (is_string($featured_image) && (strpos($featured_image, '[') === 0 || strpos($featured_image, '{') === 0)) {
            $decoded = json_decode($featured_image, true);
            if (is_array($decoded)) {
                // 如果是数组，取第一个元素的URL
                if (isset($decoded[0])) {
                    $first = $decoded[0];
                    if (is_array($first) && isset($first['url'])) {
                        return $first['url'];
                    } elseif (is_string($first)) {
                        return $first;
                    }
                } elseif (isset($decoded['url'])) {
                    return $decoded['url'];
                }
            }
        }

        // 如果是普通URL字符串，直接返回
        if (is_string($featured_image) && (strpos($featured_image, 'http') === 0 || strpos($featured_image, '/') === 0)) {
            return $featured_image;
        }

        return false;
    } catch (Exception $e) {
        error_log("获取文章特色图片失败: " . $e->getMessage());
        return false;
    }
}

/**
 * 获取文章分类
 * @param int $post_id 文章ID
 * @return array 分类数组
 */
function get_post_categories($post_id)
{
    global $tododb;

    if (!$post_id) {
        return [];
    }

    // 性能优化：优先使用ORM缓存
    if (class_exists('Todo_Post_ORM')) {
        $orm = new Todo_Post_ORM(['ID' => $post_id], true);
        $terms = $orm->get_terms('category');

        $result = [];
        if (!empty($terms)) {
            foreach ($terms as $t) {
                $obj = (object) $t;
                $result[] = (object) [
                    'id' => $obj->term_id,
                    'name' => $obj->name,
                    'slug' => $obj->slug,
                    'description' => isset($obj->description) ? $obj->description : ''
                ];
            }
        }
        return $result;
    }

    // 确保数据库连接已初始化
    if (!$tododb) {
        if (function_exists('get_database_connection')) {
            $tododb = get_database_connection();
        }
        if (!$tododb) {
            error_log("数据库连接未初始化");
            return [];
        }
    }

    try {
        return $tododb->get_results("
            SELECT t.term_id as id, t.name, t.slug, tt.description
            FROM " . $tododb->prefix . "terms t
            INNER JOIN " . $tododb->prefix . "term_taxonomy tt ON t.term_id = tt.term_id
            INNER JOIN " . $tododb->prefix . "term_relationships tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
            WHERE tr.object_id = ? AND tt.taxonomy = 'category'
            ORDER BY tr.term_order ASC, t.name ASC
        ", [$post_id]);
    } catch (Exception $e) {
        error_log("获取文章分类失败: " . $e->getMessage());
        return [];
    }
}

/**
 * 获取文章评论
 * @param int $post_id 文章ID
 * @param array $args 查询参数
 * @return array 评论数组
 */
function get_post_comments($post_id, $args = [])
{
    global $tododb;

    if (!$post_id) {
        return [];
    }

    $defaults = [
        'status' => '1',
        'order' => 'ASC',
        'limit' => 50,
        'offset' => 0
    ];
    $args = array_merge($defaults, $args);

    // 兼容 status 参数传入 'approved' 或 '1'
    $status_val = $args['status'];
    if ($status_val === 'approved') {
        $status_val = '1';
    }

    try {
        $where_conditions = ["comment_post_ID = ?"];
        $params = [$post_id];

        if ($status_val !== '' && $status_val !== null) {
            $where_conditions[] = "comment_approved = ?";
            $params[] = $status_val;
        }

        $order_clause = strtoupper($args['order']) === 'DESC' ? 'DESC' : 'ASC';
        $limit_clause = $args['limit'] > 0 ? "LIMIT " . intval($args['limit']) : "";
        $offset_clause = $args['offset'] > 0 ? "OFFSET " . intval($args['offset']) : "";

        $sql = "
            SELECT comment_ID AS id, comment_post_ID AS post_id, comment_author AS author_name, 
                   comment_author_email AS author_email, comment_author_url AS author_url, 
                   comment_content AS content, comment_approved AS status, 
                   comment_date AS created_at, comment_parent AS parent_id
            FROM " . $tododb->prefix . "comments 
            WHERE " . implode(' AND ', $where_conditions) . "
            ORDER BY comment_date {$order_clause}
            {$limit_clause} {$offset_clause}
        ";

        return $tododb->get_results($sql, $params);
    } catch (Exception $e) {
        error_log("获取文章评论失败: " . $e->getMessage());
        return [];
    }
}

/**
 * 为文章添加术语（分类/标签）
 * @param int $post_id 文章ID
 * @param int|array $term_ids 术语ID或ID数组
 * @param string $taxonomy 分类法类型 (category|tag)
 * @return bool 成功返回true
 */
function add_post_term($post_id, $term_ids, $taxonomy = 'category')
{
    global $tododb;

    if (!$post_id || empty($term_ids)) {
        return false;
    }

    if (!is_array($term_ids)) {
        $term_ids = [$term_ids];
    }

    try {
        $tododb->begin_transaction();

        $db_taxonomy = ($taxonomy === 'tag' || $taxonomy === 'post_tag') ? 'post_tag' : 'category';

        foreach ($term_ids as $term_id) {
            $term_id = intval($term_id);
            if ($term_id <= 0) {
                continue;
            }

            // 标签走独立标签系统（tags + post_tags），与分类体系分离
            if ($db_taxonomy === 'post_tag') {
                $exists = $tododb->get_var("
                    SELECT COUNT(*) FROM " . $tododb->prefix . "post_tags
                    WHERE post_id = ? AND tag_id = ?
                ", [$post_id, $term_id]);

                if ($exists == 0) {
                    $tododb->query("
                        INSERT INTO " . $tododb->prefix . "post_tags (post_id, tag_id)
                        VALUES (?, ?)
                    ", [$post_id, $term_id]);
                }
                continue;
            }

            // 解析术语对应的 term_taxonomy_id
            $term_taxonomy_id = $tododb->get_var("
                SELECT term_taxonomy_id FROM " . $tododb->prefix . "term_taxonomy
                WHERE term_id = ? AND taxonomy = ?
                LIMIT 1
            ", [$term_id, $db_taxonomy]);

            if (!$term_taxonomy_id) {
                continue;
            }

            // 检查关联是否已存在
            $exists = $tododb->get_var("
                SELECT COUNT(*) FROM " . $tododb->prefix . "term_relationships
                WHERE object_id = ? AND term_taxonomy_id = ?
            ", [$post_id, $term_taxonomy_id]);

            if ($exists == 0) {
                // 添加新关联
                $tododb->query("
                    INSERT INTO " . $tododb->prefix . "term_relationships (object_id, term_taxonomy_id, term_order)
                    VALUES (?, ?, 0)
                ", [$post_id, $term_taxonomy_id]);

                // 同步术语计数
                $tododb->query("
                    UPDATE " . $tododb->prefix . "term_taxonomy
                    SET count = count + 1
                    WHERE term_taxonomy_id = ?
                ", [$term_taxonomy_id]);
            }
        }

        $tododb->commit();

        if (class_exists('Todo_Unified_Cache_Manager')) {
            Todo_Unified_Cache_Manager::clean_post_cache($post_id);
        }
        if (function_exists('todo_do_action')) {
            todo_do_action('todo_post_updated', $post_id);
        }

        return true;
    } catch (Throwable $e) {
        $tododb->rollback();
        error_log("添加文章术语失败: " . $e->getMessage());
        return false;
    }
}

/**
 * 设置文章特色图片
 * @param int $post_id 文章ID
 * @param int $media_id 媒体ID
 * @return bool 成功返回true
 */
function set_post_featured_image($post_id, $media_id)
{
    global $tododb;

    if (!$post_id) {
        return false;
    }

    try {
        $result = $tododb->update(
            $tododb->prefix . 'posts',
            ['featured_image_id' => $media_id, 'updated_at' => 'NOW()'],
            ['id' => $post_id]
        );

        if ($result !== false) {
            if (class_exists('Todo_Unified_Cache_Manager')) {
                Todo_Unified_Cache_Manager::clean_post_cache($post_id);
            }
            if (function_exists('todo_do_action')) {
                todo_do_action('todo_post_updated', $post_id);
            }
        }

        return $result;
    } catch (Exception $e) {
        error_log("设置文章特色图片失败: " . $e->getMessage());
        return false;
    }
}

/**
 * 删除文章特色图片
 * @param int $post_id 文章ID
 * @return bool 成功返回true
 */
function delete_post_featured_image($post_id)
{
    global $tododb;

    if (!$post_id) {
        return false;
    }

    try {
        $result = $tododb->update(
            $tododb->prefix . 'posts',
            ['featured_image_id' => null, 'updated_at' => 'NOW()'],
            ['id' => $post_id]
        );

        if ($result !== false) {
            if (class_exists('Todo_Unified_Cache_Manager')) {
                Todo_Unified_Cache_Manager::clean_post_cache($post_id);
            }
            if (function_exists('todo_do_action')) {
                todo_do_action('todo_post_updated', $post_id);
            }
        }

        return $result;
    } catch (Exception $e) {
        error_log("删除文章特色图片失败: " . $e->getMessage());
        return false;
    }
}

/**
 * 删除文章评论
 * @param int $post_id 文章ID
 * @param array $args 删除参数
 * @return bool 成功返回true
 */
function delete_post_comments($post_id, $args = [])
{
    global $tododb;

    if (!$post_id) {
        return false;
    }

    $defaults = [
        'force_delete' => false, // true为物理删除，false为软删除
        'status' => null // 指定状态的评论
    ];
    $args = array_merge($defaults, $args);

    try {
        $where_conditions = ["post_id = ?"];
        $params = [$post_id];

        if ($args['status']) {
            $where_conditions[] = "status = ?";
            $params[] = $args['status'];
        }

        if ($args['force_delete']) {
            // 物理删除
            $sql = "DELETE FROM " . $tododb->prefix . "comments WHERE " . implode(' AND ', $where_conditions);
        } else {
            // 软删除
            $sql = "UPDATE " . $tododb->prefix . "comments SET status = 'deleted', updated_at = NOW() WHERE " . implode(' AND ', $where_conditions);
        }

        $result = $tododb->query($sql, $params);

        if ($result !== false) {
            if (class_exists('Todo_Unified_Cache_Manager')) {
                Todo_Unified_Cache_Manager::clean_post_cache($post_id);
            }
            if (function_exists('todo_do_action')) {
                todo_do_action('todo_post_updated', $post_id);
            }
        }

        return $result;
    } catch (Exception $e) {
        error_log("删除文章评论失败: " . $e->getMessage());
        return false;
    }
}
