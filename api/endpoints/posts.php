<?php
/**
 * 文章API端点
 *
 * 提供文章相关的API端点
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

if (!function_exists('get_post_comments')) {
    $pf = rtrim(ABSPATH, '/') . '/core/functions/post-functions-extended.php';
    if (is_file($pf)) {
        require_once $pf;
    }
}

if (!function_exists('api_resolve_tag_info')) {
    function api_resolve_tag_info($tag)
    {
        global $tododb;
        $raw = trim((string) $tag);
        if ($raw === '') {
            return null;
        }

        $get = function ($row, $key) {
            if (is_array($row)) {
                return array_key_exists($key, $row) ? $row[$key] : null;
            }
            if (is_object($row)) {
                return isset($row->{$key}) ? $row->{$key} : null;
            }
            return null;
        };

        $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : (defined('DB_PREFIX') ? DB_PREFIX : 'todo_');

        try {
            if (ctype_digit($raw)) {
                $id = intval($raw);
                $tag_row = null;
                try {
                    $tag_row = $tododb->get_row("SELECT tag_id, tag_name, tag_slug FROM {$prefix}tags WHERE tag_id = ? LIMIT 1", [$id]);
                } catch (Exception $e) {
                    $tag_row = null;
                }
                if (!$tag_row) {
                    try {
                        $tag_row = $tododb->get_row("SELECT id, name, slug FROM {$prefix}tags WHERE id = ? LIMIT 1", [$id]);
                    } catch (Exception $e) {
                        $tag_row = null;
                    }
                }
                if ($tag_row) {
                    $tid = $get($tag_row, 'tag_id');
                    if (!$tid) {
                        $tid = $get($tag_row, 'id');
                    }
                    $name = $get($tag_row, 'tag_name');
                    if ($name === null || $name === '') {
                        $name = $get($tag_row, 'name');
                    }
                    $slug = $get($tag_row, 'tag_slug');
                    if ($slug === null || $slug === '') {
                        $slug = $get($tag_row, 'slug');
                    }
                    $name = trim((string) $name);
                    $slug = trim((string) $slug);
                    return [
                        'id' => $tid ? intval($tid) : $id,
                        'name' => $name,
                        'slug' => $slug,
                        'source' => 'tags'
                    ];
                }

                $term_row = $tododb->get_row("
                    SELECT t.term_id as id, t.name, t.slug
                    FROM {$prefix}terms t
                    INNER JOIN {$prefix}term_taxonomy tt ON t.term_id = tt.term_id
                    WHERE tt.taxonomy = 'post_tag' AND t.term_id = ?
                    LIMIT 1
                ", [$id]);
                if ($term_row) {
                    $name = trim((string) $get($term_row, 'name'));
                    $slug = trim((string) $get($term_row, 'slug'));
                    return [
                        'id' => intval($get($term_row, 'id')),
                        'name' => $name,
                        'slug' => $slug,
                        'source' => 'terms'
                    ];
                }

                return null;
            }

            $slug = $raw;
            $tag_row = null;
            try {
                $tag_row = $tododb->get_row("SELECT tag_id, tag_name, tag_slug FROM {$prefix}tags WHERE tag_slug = ? LIMIT 1", [$slug]);
            } catch (Exception $e) {
                $tag_row = null;
            }
            if (!$tag_row) {
                try {
                    $tag_row = $tododb->get_row("SELECT id, name, slug FROM {$prefix}tags WHERE slug = ? LIMIT 1", [$slug]);
                } catch (Exception $e) {
                    $tag_row = null;
                }
            }
            if ($tag_row) {
                $tid = $get($tag_row, 'tag_id');
                if (!$tid) {
                    $tid = $get($tag_row, 'id');
                }
                $name = $get($tag_row, 'tag_name');
                if ($name === null || $name === '') {
                    $name = $get($tag_row, 'name');
                }
                $slug2 = $get($tag_row, 'tag_slug');
                if ($slug2 === null || $slug2 === '') {
                    $slug2 = $get($tag_row, 'slug');
                }
                $name = trim((string) $name);
                $slug2 = trim((string) $slug2);
                return [
                    'id' => $tid ? intval($tid) : null,
                    'name' => $name,
                    'slug' => $slug2 ?: $slug,
                    'source' => 'tags'
                ];
            }

            $term_row = $tododb->get_row("
                SELECT t.term_id as id, t.name, t.slug
                FROM {$prefix}terms t
                INNER JOIN {$prefix}term_taxonomy tt ON t.term_id = tt.term_id
                WHERE tt.taxonomy = 'post_tag' AND t.slug = ?
                LIMIT 1
            ", [$slug]);
            if ($term_row) {
                $name = trim((string) $get($term_row, 'name'));
                $slug2 = trim((string) $get($term_row, 'slug'));
                return [
                    'id' => intval($get($term_row, 'id')),
                    'name' => $name,
                    'slug' => $slug2 ?: $slug,
                    'source' => 'terms'
                ];
            }
        } catch (Exception $e) {
            return null;
        }

        return null;
    }
}

/**
 * 获取文章列表
 *
 * @param array $params 请求参数
 * @return array 文章列表
 */
function api_get_posts($params)
{
    global $tododb;

    // 默认参数
    $defaults = [
        'page' => 1,
        'per_page' => 10,
        'category' => '',
        'tag' => '',
        'search' => '',
        'orderby' => 'post_date',
        'order' => 'DESC',
        'lang' => ''
    ];

    // 合并参数
    $args = array_merge($defaults, $params);

    // 验证参数
    $args['page'] = max(1, intval($args['page']));
    $args['per_page'] = max(1, min(100, intval($args['per_page'])));
    $args['order'] = in_array(strtoupper($args['order']), ['ASC', 'DESC']) ? strtoupper($args['order']) : 'DESC';
    $lang = !empty($args['lang']) ? sanitize_text_field($args['lang']) : (function_exists('get_current_language') ? get_current_language() : '');

    $posts_table = TABLE_PREFIX . "posts";
    $joins = [];
    $where = ["p.post_status = 'publish'"];
    $params = [];

    // 添加搜索条件
    if (!empty($args['search'])) {
        $search_term = '%' . $args['search'] . '%';
        $curr_lang = !empty($lang) ? $lang : (function_exists('get_current_language') ? get_current_language() : 'zh-CN');
        $canonical_lang = function_exists('canonical_locale') ? canonical_locale($curr_lang) : str_replace('_', '-', $curr_lang);
        $alt_lang = str_replace('-', '_', $canonical_lang);

        $has_trans_table = method_exists($tododb, 'table_exists') ? $tododb->table_exists('post_translations') : true;
        if ($has_trans_table) {
            $esc_lang1 = $tododb->esc_like($canonical_lang);
            $esc_lang2 = $tododb->esc_like($alt_lang);
            $joins[] = " LEFT JOIN " . TABLE_PREFIX . "post_translations ptr ON (ptr.post_id = p.ID AND (ptr.language_code = '{$esc_lang1}' OR ptr.language_code = '{$esc_lang2}'))";
            $where[] = "(p.post_title LIKE ? OR p.post_content LIKE ? OR ptr.title LIKE ? OR ptr.content LIKE ? OR ptr.excerpt LIKE ? OR ptr.seo_title LIKE ? OR ptr.seo_keywords LIKE ? OR ptr.seo_description LIKE ?)";
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
        } else {
            $where[] = "(p.post_title LIKE ? OR p.post_content LIKE ?)";
            $params[] = $search_term;
            $params[] = $search_term;
        }
    }

    // 添加分类条件
    if (!empty($args['category'])) {
        $joins[] = " INNER JOIN " . TABLE_PREFIX . "term_relationships tr_cat ON p.ID = tr_cat.object_id";
        $joins[] = " INNER JOIN " . TABLE_PREFIX . "term_taxonomy tt_cat ON tr_cat.term_taxonomy_id = tt_cat.term_taxonomy_id";
        $where[] = "tt_cat.taxonomy = 'category' AND tt_cat.term_id = ?";
        $params[] = intval($args['category']);
    }

    // 添加标签条件，兼容独立标签系统（tags/post_tags）和旧 taxonomy(post_tag)
    if (!empty($args['tag'])) {
        $tag = trim((string) $args['tag']);
        $joins[] = " LEFT JOIN " . TABLE_PREFIX . "post_tags pt_tag ON p.ID = pt_tag.post_id";
        $joins[] = " LEFT JOIN " . TABLE_PREFIX . "tags tg ON pt_tag.tag_id = tg.tag_id";
        $joins[] = " LEFT JOIN " . TABLE_PREFIX . "term_relationships tr_tag ON p.ID = tr_tag.object_id";
        $joins[] = " LEFT JOIN " . TABLE_PREFIX . "term_taxonomy tt_tag ON tr_tag.term_taxonomy_id = tt_tag.term_taxonomy_id";
        $joins[] = " LEFT JOIN " . TABLE_PREFIX . "terms t_tag ON tt_tag.term_id = t_tag.term_id";

        if (ctype_digit($tag)) {
            $where[] = "((tg.tag_id = ?) OR (tt_tag.taxonomy = 'post_tag' AND tt_tag.term_id = ?))";
            $params[] = intval($tag);
            $params[] = intval($tag);
        } else {
            $where[] = "((tg.tag_slug = ?) OR (tt_tag.taxonomy = 'post_tag' AND t_tag.slug = ?))";
            $params[] = $tag;
            $params[] = $tag;
        }
    }

    // 构建查询
    $query = "SELECT DISTINCT p.* FROM {$posts_table} p";
    if (!empty($joins)) {
        $query .= implode('', array_unique($joins));
    }
    $query .= " WHERE " . implode(' AND ', $where);

    // 添加排序
    $valid_orderby = [
        'post_date' => 'post_date',
        'date' => 'post_date',
        'ID' => 'ID',
        'id' => 'ID',
        'title' => 'post_title',
        'post_title' => 'post_title',
        'modified' => 'post_modified',
        'post_modified' => 'post_modified'
    ];
    $orderby_field = isset($valid_orderby[$args['orderby']]) ? $valid_orderby[$args['orderby']] : 'post_date';
    $order_dir = in_array($args['order'], ['ASC', 'DESC']) ? $args['order'] : 'DESC';
    $query .= " ORDER BY {$orderby_field} {$order_dir}";

    // 添加分页
    $offset = ($args['page'] - 1) * $args['per_page'];
    $query .= " LIMIT " . intval($args['per_page']) . " OFFSET " . intval($offset);

    // 执行查询
    $posts = $tododb->query($query, $params);

    // 获取总数
    $count_query = "SELECT COUNT(DISTINCT p.ID) as total FROM {$posts_table} p";
    if (!empty($joins)) {
        $count_query .= implode('', array_unique($joins));
    }
    $count_query .= " WHERE " . implode(' AND ', $where);
    $count_params = $params;

    $count_result = $tododb->query($count_query, $count_params);
    $total = isset($count_result[0]['total']) ? intval($count_result[0]['total']) : 0;

    // 批量预加载元数据和分类，避免N+1查询
    if (!empty($posts) && is_array($posts)) {
        // 提取文章ID（兼容对象和数组格式）
        $post_ids = [];
        foreach ($posts as $post) {
            if (is_array($post) && isset($post['ID'])) {
                $post_ids[] = intval($post['ID']);
            } elseif (is_object($post) && isset($post->ID)) {
                $post_ids[] = intval($post->ID);
            }
        }

        // 仅在有有效ID时才预加载
        if (!empty($post_ids)) {
            // 预加载文章元数据（SEO字段等）
            if (function_exists('update_post_meta_cache')) {
                update_post_meta_cache($post_ids);
            }

            // 预加载分类和标签
            if (function_exists('update_post_term_cache')) {
                update_post_term_cache($post_ids, 'category');
            }
        }
    }

    // 批量预加载多语言数据（使用批量模式，避免N+1查询）
    $translations = [];
    if (!empty($posts) && function_exists('todo_get_posts_multilang_batch')) {
        $post_ids = [];
        foreach ($posts as $post) {
            if (is_array($post) && isset($post['ID'])) {
                $post_ids[] = intval($post['ID']);
            }
        }

        if (!empty($post_ids)) {
            $translations = todo_get_posts_multilang_batch($post_ids, $lang, [
                'include_seo' => true,
                'prefer_custom_url' => true,
                'fallback' => true
            ]);
        }
    }

    // 处理结果：优先使用预加载的多语言数据
    $result = [];
    foreach ($posts as $post) {
        $post_id = intval($post['ID']);

        // 如果有翻译数据，使用翻译
        if (isset($translations[$post_id])) {
            $item = $translations[$post_id];
            if (!is_array($item)) {
                $item = [];
            }
            $item['id'] = $post_id;
            if (!isset($item['featured_image']) || empty($item['featured_image'])) {
                $item['featured_image'] = api_get_featured_image($post_id);
            }
            if (!isset($item['categories']) || !is_array($item['categories']) || empty($item['categories'])) {
                $item['categories'] = get_post_categories($post_id);
            }
            if (!isset($item['tags']) || !is_array($item['tags']) || empty($item['tags'])) {
                $item['tags'] = get_post_tags($post_id);
            }
            if (!isset($item['url']) || $item['url'] === '' || $item['url'] === null) {
                $item['url'] = get_permalink($post_id);
            }
            $result[] = $item;
        } else {
            // 否则使用原始数据
            $result[] = [
                'id' => $post_id,
                'title' => $post['post_title'],
                'content' => $post['post_content'],
                'excerpt' => $post['post_excerpt'],
                'date' => $post['post_date'],
                'modified' => $post['post_modified'],
                'slug' => $post['post_name'],
                'author' => intval($post['post_author']),
                'featured_image' => api_get_featured_image($post_id),
                'categories' => get_post_categories($post_id),
                'tags' => get_post_tags($post_id),
                'url' => get_permalink($post_id),
                'seo_title' => get_post_meta($post_id, 'seo_title', true),
                'seo_description' => get_post_meta($post_id, 'seo_description', true),
                'seo_keywords' => get_post_meta($post_id, 'seo_keywords', true),
            ];
        }
    }

    $tag_info = null;
    if (!empty($args['tag']) && function_exists('api_resolve_tag_info')) {
        $tag_info = api_resolve_tag_info($args['tag']);
    }

    // 返回结果
    return [
        'posts' => $result,
        'tag_info' => $tag_info,
        'total' => $total,
        'page' => $args['page'],
        'per_page' => $args['per_page'],
        'total_pages' => ceil($total / $args['per_page'])
    ];
}

/**
 * 获取单个文章
 *
 * @param array $params 请求参数
 * @return array 文章数据
 */
function api_get_post($params)
{
    global $tododb;

    // 验证参数
    if (!isset($params['id'])) {
        send_api_error(400, '缺少ID参数');
    }

    $post_id = intval($params['id']);
    $lang = isset($params['lang']) ? sanitize_text_field($params['lang']) : (function_exists('get_current_language') ? get_current_language() : '');

    // 查询文章
    $query = "SELECT * FROM " . TABLE_PREFIX . "posts WHERE ID = ? AND post_status = 'publish' LIMIT 1";
    $post = $tododb->query($query, [$post_id]);

    // 检查文章是否存在
    if (empty($post)) {
        send_api_error(404, '文章不存在');
    }

    $post = $post[0];
    // 可见性优先（私密/密码保护），其后判定阅读权限（等级/管理员/公开）
    if (function_exists('todo_can_read_post')) {
        $access = todo_can_read_post((object) $post);
        if (!$access['allowed']) {
            if (!empty($access['requires_password'])) {
                $client_ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
                if (session_status() === PHP_SESSION_NONE) {
                    @session_start();
                }

                // 读取当前 Session 中的连续输错密码次数
                $sess_key = 'post_pw_fails_' . $post['ID'];
                $fail_count = (int) ($_SESSION[$sess_key] ?? 0);

                // 如果输错达到 5 次以上，直接 IP 锁 1 分钟
                if ($fail_count >= 5) {
                    send_api_error(429, '密码错误过多，请 1 分钟后再试');
                }

                // 如果输错达到 3 次以上且未通过滑块验证，弹出图形验证码
                if ($fail_count >= 3) {
                    if (empty($_SESSION['todo_captcha_verified'])) {
                        send_api_error(403, '密码错误过多，请完成滑动验证', ['require_captcha' => true]);
                    } else {
                        // 消费验证码，允许进行本次解密尝试
                        unset($_SESSION['todo_captcha_verified']);
                    }
                }

                // 记录本次密码错误
                $_SESSION[$sess_key] = $fail_count + 1;
                send_api_error(401, '该内容为密码保护，密码错误');
            } else {
                send_api_error(403, '您暂无权限阅读该内容');
            }
        } else {
            // 校验成功，清空失败计数
            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }
            $sess_key = 'post_pw_fails_' . $post['ID'];
            unset($_SESSION[$sess_key], $_SESSION['todo_captcha_verified']);
        }
    }

    // 优先返回多语言聚合（含SEO、URL、slug），否则回退原始数据
    if (function_exists('todo_get_post_multilang')) {
        $ml = todo_get_post_multilang($post['ID'], $lang, ['include_seo' => true, 'prefer_custom_url' => true, 'fallback' => true]);
        if (is_array($ml)) {
            if (isset($ml['content']) && is_string($ml['content'])) {
                $ml['content'] = html_entity_decode($ml['content'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            // 附带评论
            $ml['comments'] = get_post_comments($post['ID']);
            return $ml;
        }
    }

    // 回退原始
    return [
        'id' => intval($post['ID']),
        'title' => $post['post_title'],
        'content' => $post['post_content'],
        'excerpt' => $post['post_excerpt'],
        'date' => $post['post_date'],
        'modified' => $post['post_modified'],
        'slug' => $post['post_name'],
        'author' => intval($post['post_author']),
        'featured_image' => api_get_featured_image($post['ID']),
        'categories' => get_post_categories($post['ID']),
        'tags' => get_post_tags($post['ID']),
        'url' => get_permalink($post['ID']),
        'comments' => get_post_comments($post['ID']),
        'seo_title' => get_post_meta($post['ID'], 'seo_title', true),
        'seo_description' => get_post_meta($post['ID'], 'seo_description', true),
        'seo_keywords' => get_post_meta($post['ID'], 'seo_keywords', true)
    ];
}

/**
 * 创建文章
 *
 * @param array $params 请求参数
 * @return array 创建的文章数据
 */
function api_create_post($params)
{
    global $tododb;

    // 验证必要参数
    if (!isset($params['title']) || empty($params['title'])) {
        send_api_error(400, '标题不能为空');
    }

    if (!isset($params['content']) || empty($params['content'])) {
        send_api_error(400, '内容不能为空');
    }

    // 准备数据
    $post_data = [
        'post_title' => sanitize_text_field($params['title']),
        'post_content' => todo_kses_post($params['content']),
        'post_excerpt' => isset($params['excerpt']) ? sanitize_text_field($params['excerpt']) : '',
        'post_status' => 'publish',
        'post_author' => get_current_user_id(),
        'post_date' => current_time('mysql'),
        'post_modified' => current_time('mysql'),
        'post_name' => isset($params['slug']) ? sanitize_title($params['slug']) : sanitize_title($params['title'])
    ];

    // 插入文章
    $query = "INSERT INTO " . TABLE_PREFIX . "posts (post_title, post_content, post_excerpt, post_status, post_author, post_date, post_modified, post_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $result = $tododb->query($query, [
        $post_data['post_title'],
        $post_data['post_content'],
        $post_data['post_excerpt'],
        $post_data['post_status'],
        $post_data['post_author'],
        $post_data['post_date'],
        $post_data['post_modified'],
        $post_data['post_name']
    ]);

    if (!$result) {
        send_api_error(500, '创建文章失败');
    }

    $post_id = $tododb->insert_id();

    // 处理分类
    if (isset($params['categories']) && is_array($params['categories'])) {
        foreach ($params['categories'] as $category_id) {
            add_post_term($post_id, intval($category_id), 'category');
        }
    }

    // 处理标签
    if (isset($params['tags']) && is_array($params['tags'])) {
        foreach ($params['tags'] as $tag_id) {
            add_post_term($post_id, intval($tag_id), 'tag');
        }
    }

    // 处理特色图片
    if (isset($params['featured_image']) && !empty($params['featured_image'])) {
        set_post_featured_image($post_id, intval($params['featured_image']));
    }

    // 返回创建的文章
    return api_get_post(['id' => $post_id]);
}

/**
 * 更新文章
 *
 * @param array $params 请求参数
 * @return array 更新后的文章数据
 */
function api_update_post($params)
{
    global $tododb;

    // 验证参数
    if (!isset($params['id'])) {
        send_api_error(400, '缺少ID参数');
    }

    $post_id = intval($params['id']);

    // 检查文章是否存在
    $query = "SELECT * FROM " . TABLE_PREFIX . "posts WHERE ID = ? LIMIT 1";
    $post = $tododb->query($query, [$post_id]);

    if (empty($post)) {
        send_api_error(404, '文章不存在');
    }

    // 准备更新数据
    $update_data = [];
    $update_params = [];

    if (isset($params['title'])) {
        $update_data[] = 'post_title = ?';
        $update_params[] = sanitize_text_field($params['title']);
    }

    if (isset($params['content'])) {
        $update_data[] = 'post_content = ?';
        $update_params[] = todo_kses_post($params['content']);
    }

    if (isset($params['excerpt'])) {
        $update_data[] = 'post_excerpt = ?';
        $update_params[] = sanitize_text_field($params['excerpt']);
    }

    if (isset($params['slug'])) {
        $update_data[] = 'post_name = ?';
        $update_params[] = sanitize_title($params['slug']);
    }

    if (isset($params['status'])) {
        $update_data[] = 'post_status = ?';
        $update_params[] = sanitize_text_field($params['status']);
    }

    // 添加修改时间
    $update_data[] = 'post_modified = ?';
    $update_params[] = current_time('mysql');

    // 添加文章ID
    $update_params[] = $post_id;

    // 执行更新
    if (!empty($update_data)) {
        $query = "UPDATE " . TABLE_PREFIX . "posts SET " . implode(', ', $update_data) . " WHERE ID = ?";
        $result = $tododb->query($query, $update_params);

        if (!$result) {
            send_api_error(500, '更新文章失败');
        }
    }

    // 处理分类
    if (isset($params['categories']) && is_array($params['categories'])) {
        // 删除现有分类
        delete_post_terms($post_id, 'category');

        // 添加新分类
        foreach ($params['categories'] as $category_id) {
            add_post_term($post_id, intval($category_id), 'category');
        }
    }

    // 处理标签
    if (isset($params['tags']) && is_array($params['tags'])) {
        // 删除现有标签
        delete_post_terms($post_id, 'tag');

        // 添加新标签
        foreach ($params['tags'] as $tag_id) {
            add_post_term($post_id, intval($tag_id), 'tag');
        }
    }

    // 处理特色图片
    if (isset($params['featured_image'])) {
        if (empty($params['featured_image'])) {
            delete_post_featured_image($post_id);
        } else {
            set_post_featured_image($post_id, intval($params['featured_image']));
        }
    }

    // 返回更新后的文章
    return api_get_post(['id' => $post_id]);
}

/**
 * 删除文章
 *
 * @param array $params 请求参数
 * @return array 操作结果
 */
function api_delete_post($params)
{
    global $tododb;

    // 安全防线：校验 API Key 是否具备文章删除权限 (posts:delete 或 admin)
    if (class_exists('Todo_API')) {
        $api_instance = Todo_API::init();
        if (method_exists($api_instance, 'current_key_can') && !$api_instance->current_key_can('posts:delete')) {
            send_api_error(403, '权限不足：当前使用的 API Key 未被授予文章删除权限 (posts:delete)');
        }
    }

    // 验证参数
    if (!isset($params['id'])) {
        send_api_error(400, '缺少ID参数');
    }

    $post_id = intval($params['id']);

    // 检查文章是否存在
    $query = "SELECT * FROM " . TABLE_PREFIX . "posts WHERE ID = ? LIMIT 1";
    $post = $tododb->query($query, [$post_id]);

    if (empty($post)) {
        send_api_error(404, '文章不存在');
    }

    // 第一道防线：坚决执行软删除（移至回收站，post_status = trash），禁止物理抹除数据
    // 即使 Agent 遭遇 Prompt 注入或产生幻觉，文章也可随时从后台回收站一键完整还原
    $result = todo_delete_post($post_id, false);

    if (!$result) {
        send_api_error(500, '删除文章失败');
    }

    // 返回友好的安全状态反馈
    return [
        'id' => $post_id,
        'status' => 'trash',
        'safe_mode' => true,
        'message' => '文章已安全移至回收站（未物理抹除，支持在后台随时一键撤销与还原）'
    ];
}

/**
 * 点赞/取消点赞文章
 *
 * @param array $params 请求参数
 * @return array 操作结果
 */
function api_like_post($params)
{
    global $tododb;

    // 验证参数
    if (!isset($params['id'])) {
        send_api_error(400, '缺少文章ID参数');
    }

    $post_id = intval($params['id']);

    // 检查文章是否存在
    $query = "SELECT ID, like_count FROM " . TABLE_PREFIX . "posts WHERE ID = ? AND post_status = 'publish' LIMIT 1";
    $post = $tododb->get_row($query, [$post_id]);

    if (!$post) {
        send_api_error(404, '文章不存在');
    }

    // 确定用户标识
    $user_id = function_exists('get_current_user_id') ? get_current_user_id() : 0;
    if ($user_id > 0) {
        $like_key = 'user_' . $user_id;
    } else {
        // 未登录用户使用 IP + User-Agent 作为标识
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        $like_key = 'anon_' . md5($ip . $ua);
    }

    // 获取已点赞用户列表
    $liked_users = get_post_meta($post_id, '_post_liked_users', true);
    if (!is_array($liked_users)) {
        $liked_users = [];
    }

    $is_liked = in_array($like_key, $liked_users);

    if ($is_liked) {
        // 取消点赞
        $liked_users = array_values(array_diff($liked_users, [$like_key]));
        $new_count = max(0, intval($post->like_count) - 1);
    } else {
        // 点赞
        $liked_users[] = $like_key;
        $new_count = intval($post->like_count) + 1;
    }

    // 更新点赞用户列表缓存（直接更新 postmeta 避免触发 todo_post_updated 钩子清空列表页缓存）
    $meta_key = '_post_liked_users';
    $meta_val_serialized = maybe_serialize($liked_users);
    $existing_meta_id = $tododb->get_var($tododb->prepare("SELECT meta_id FROM {$tododb->prefix}postmeta WHERE post_id = %d AND meta_key = %s LIMIT 1", $post_id, $meta_key));
    if ($existing_meta_id) {
        $tododb->update("{$tododb->prefix}postmeta", ['meta_value' => $meta_val_serialized], ['meta_id' => $existing_meta_id]);
    } else {
        $tododb->insert("{$tododb->prefix}postmeta", ['post_id' => $post_id, 'meta_key' => $meta_key, 'meta_value' => $meta_val_serialized]);
    }

    // 更新文章点赞计数
    $tododb->query(
        "UPDATE " . TABLE_PREFIX . "posts SET like_count = ?, updated_at = updated_at, post_modified = post_modified, post_modified_gmt = post_modified_gmt WHERE ID = ?",
        [$new_count, $post_id]
    );

    return [
        'liked' => !$is_liked,
        'likes_count' => $new_count
    ];
}

/**
 * 增加文章浏览量
 *
 * @param array $params 请求参数
 * @return array 操作结果
 */
function api_view_post($params)
{
    global $tododb;

    if (!isset($params['id'])) {
        send_api_error(400, '缺少文章ID参数');
    }

    $post_id = intval($params['id']);
    
    // 如果存在缓存，可以在此更新缓存
    if (function_exists('increment_post_views')) {
        increment_post_views($post_id);
    } else {
        $tododb->query(
            "UPDATE " . TABLE_PREFIX . "posts SET view_count = view_count + 1 WHERE ID = ?",
            [$post_id]
        );
    }
    
    // 获取最新浏览量
    $views = $tododb->get_var("SELECT view_count FROM " . TABLE_PREFIX . "posts WHERE ID = ?", [$post_id]);

    return [
        'id' => $post_id,
        'view_count' => intval($views)
    ];
}

// 注册API路由
register_api_route('posts', 'GET', 'api_get_posts');
register_api_route('posts', 'POST', 'api_create_post');
register_api_route('posts', 'PUT', 'api_update_post');
register_api_route('posts', 'DELETE', 'api_delete_post');
// 新增：注册获取单个文章的GET路由，支持路径参数ID
register_api_route('posts/([0-9]+)', 'GET', 'api_get_post');
// 点赞路由
register_api_route('posts/([0-9]+)/like', 'POST', 'api_like_post');
// 浏览量增加路由
register_api_route('posts/([0-9]+)/view', 'POST', 'api_view_post');
