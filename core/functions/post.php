<?php
/**
 * 文章相关函数
 *
 * @package TodoCMS
 * @subpackage Core
 * @version 1.0.0
 */

/**
 * 统计评论数量
 *
 * @param array $args 查询参数
 * @return int 评论数量
 */
function count_comments($args = array())
{
    // 使用CMS_Comment类的静态方法获取评论数量
    return CMS_Comment::get_comment_count($args);
}

/**
 * 获取评论列表
 *
 * @param array $args 查询参数
 * @return array 评论列表
 */
function get_comments($args = array())
{
    // 使用CMS_Comment类的静态方法获取评论列表
    return CMS_Comment::get_comments($args);
}

/**
 * 统计指定分类法的术语数量
 *
 * @param string $taxonomy 分类法名称
 * @return int 术语数量
 */
function count_terms($taxonomy)
{
    global $tododb;

    $count = $tododb->get_var("SELECT COUNT(*) FROM {$tododb->prefix}term_taxonomy WHERE taxonomy = ?", [$taxonomy]);

    return intval($count);
}

/**
 * 统计用户发布的文章数量
 *
 * @param int $user_id 用户ID
 * @param string $post_type 文章类型，默认为'post'
 * @param string $post_status 文章状态，默认为'publish'
 * @return int 文章数量
 */
function count_user_posts($user_id, $post_type = 'post', $post_status = 'publish')
{
    global $tododb;

    // 构建查询
    $query = "SELECT COUNT(*) FROM {$tododb->prefix}posts WHERE post_author = ?";
    $params = [$user_id];

    // 处理文章类型
    if (is_array($post_type)) {
        $post_types = implode("', '", $post_type);
        $query .= " AND post_type IN ('{$post_types}')";
    } else {
        $query .= " AND post_type = ?";
        $params[] = $post_type;
    }

    // 处理文章状态
    if (is_array($post_status)) {
        $post_statuses = implode("', '", $post_status);
        $query .= " AND post_status IN ('{$post_statuses}')";
    } else {
        $query .= " AND post_status = ?";
        $params[] = $post_status;
    }

    // 执行查询
    $count = $tododb->get_var($query, $params);

    return intval($count);
}

/**
 * 获取单个文章
 *
 * @param int|string|array $post 文章ID、文章名称或查询条件数组
 * @param string $output 输出类型，可选值：OBJECT、ARRAY_A、ARRAY_N
 * @return object|array|null 文章对象、数组或null
 */
if (!function_exists('todo_is_post_visible')) {
    function todo_is_post_visible($post_id) {
        $post_id = (int)$post_id;
        if ($post_id <= 0) return false;

        $is_admin = (defined('IS_ADMIN') && IS_ADMIN);
        if ($is_admin) {
            $current_site_id = (isset($_GET['site']) && is_numeric($_GET['site']) && (int) $_GET['site'] > 0) ? (int) $_GET['site'] : 0;
        } else {
            $current_site_id = function_exists('todo_current_site_id') ? (int) todo_current_site_id(true) : 0;
        }

        if ($current_site_id > 0) {
            global $tododb;
            $sys_def_site = function_exists('todo_get_default_site_id') ? (int)todo_get_default_site_id() : 1;
            $has_sp = function_exists('todo_site_posts_has_bindings') ? todo_site_posts_has_bindings() : (function_exists('todo_site_posts_table_exists') && todo_site_posts_table_exists());
            $has_st = function_exists('todo_site_terms_table_exists') && todo_site_terms_table_exists();
            $site_posts_table = $tododb->prefix . 'site_posts';

            // 优先级 1：文章自身 site_posts 绑定
            if ($has_sp) {
                $sp = $tododb->get_row("SELECT is_visible FROM {$site_posts_table} WHERE post_id = {$post_id} AND site_id = {$current_site_id}");

                if ($sp) {
                    if (empty($sp->is_visible)) {
                        return false;
                    }
                    // 显式绑定且可见 → 直接返回
                    return true;
                }

                // 当前站点无绑定，但有其他站点绑定 → 不在此站点可见
                $has_sp_other = $tododb->get_var("SELECT 1 FROM {$site_posts_table} WHERE post_id = {$post_id} AND site_id <> 0 LIMIT 1");
                if ($has_sp_other) {
                    return false;
                }
            }

            if ($has_st && function_exists('todo_get_site_term_visibility')) {
                $term_vis = todo_get_site_term_visibility($current_site_id, $sys_def_site);
                $visible_ids = empty($term_vis['visible']) ? '0' : implode(',', $term_vis['visible']);
                $any_ids = empty($term_vis['any']) ? '0' : implode(',', $term_vis['any']);

                // 2) 任意分类（或其继承的父分类）绑定了当前站点且可见
                $cat_visible = $tododb->get_var("
                    SELECT 1 FROM {$tododb->prefix}term_relationships tr
                    JOIN {$tododb->prefix}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category'
                    WHERE tr.object_id = {$post_id} AND tt.term_id IN ({$visible_ids})
                    UNION
                    SELECT 1 FROM {$tododb->prefix}posts p
                    WHERE p.ID = {$post_id} AND p.category_id IN ({$visible_ids})
                    LIMIT 1
                ");
                if ($cat_visible) {
                    return true;
                }
                
                // 3) 分类（或其继承的父分类）有记录但都不匹配当前站点 → 不可见
                $has_any_cat_site = $tododb->get_var("
                    SELECT 1 FROM {$tododb->prefix}term_relationships tr
                    JOIN {$tododb->prefix}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category'
                    WHERE tr.object_id = {$post_id} AND tt.term_id IN ({$any_ids})
                    UNION
                    SELECT 1 FROM {$tododb->prefix}posts p
                    WHERE p.ID = {$post_id} AND p.category_id IN ({$any_ids})
                    LIMIT 1
                ");
                if ($has_any_cat_site) {
                    return false;
                }
            }
        }
        return true;
    }
}

if (!function_exists('get_post')) {
    function get_post($post, $output = 'OBJECT')
    {
        global $tododb;

        // Initialize global cache if not set
        if (!isset($GLOBALS['todo_internal_post_cache'])) {
            $GLOBALS['todo_internal_post_cache'] = [];
        }

        $post_id = null;
        $post_data = null;

        // 尝试从缓存获取
        if (is_numeric($post)) {
            $post_id = intval($post);
            if (isset($GLOBALS['todo_internal_post_cache'][$post_id])) {
                $post_data = $GLOBALS['todo_internal_post_cache'][$post_id];
            }
        } elseif (is_object($post) && isset($post->ID)) {
            $post_id = $post->ID;
            if (isset($GLOBALS['todo_internal_post_cache'][$post_id])) {
                $post_data = $GLOBALS['todo_internal_post_cache'][$post_id];
            } else {
                $post_data = $post;
                $GLOBALS['todo_internal_post_cache'][$post_id] = $post_data;
            }
        }

        if (!$post_data) {
            if (is_numeric($post)) {
                // 通过ID获取文章
                $post_id = intval($post);
                $post_data = $tododb->get_row("SELECT * FROM {$tododb->prefix}posts WHERE ID = ?", [$post_id]);
            } elseif (is_string($post)) {
                // 通过文章名称获取文章
                $post_data = $tododb->get_row("SELECT * FROM {$tododb->prefix}posts WHERE post_name = ?", [$post]);
            } elseif (is_array($post)) {
                // 通过条件数组获取文章
                $where = array();
                $values = array();

                foreach ($post as $key => $value) {
                    $where[] = "{$key} = ?";
                    $values[] = $value;
                }

                $where_clause = implode(' AND ', $where);
                $post_data = $tododb->get_row("SELECT * FROM {$tododb->prefix}posts WHERE {$where_clause}", $values);
            } else {
                return null;
            }

            if (!$post_data) {
                return null;
            }

            // 缓存原始数据库结果（绝对不能在此对象上直接修改多语言属性，否则会污染全局缓存）
            $GLOBALS['todo_internal_post_cache'][$post_data->ID] = clone $post_data;
        }

        // 隔离多语言修改：对要返回给调用方的 post_data 进行深克隆，防止修改污染缓存中原生的 post 属性
        $post_data = clone $post_data;

        // 多语言覆盖（新方案）：通过 todo_get_post_multilang 聚合器统一处理（后台环境不覆盖，保持基础语言）
        // 已优化：todo_get_post_multilang 不再调用 get_post_featured_image/categories/tags，避免递归
        $language_code = (function_exists('get_current_language') ? get_current_language() : (function_exists('todo_get_current_language') ? todo_get_current_language() : null));
        if ($language_code) {
            $language_code = str_replace('_', '-', $language_code);
        }
        $apply_i18n = (!defined('IS_ADMIN') || !IS_ADMIN);

        // 语言可见性检查
        if ($apply_i18n && $language_code) {
            // 检查是否为主语言（主语言可见所有文章）
            $default_lang = function_exists('get_default_language') ? get_default_language() : 'zh-CN';
            $default_lang = str_replace('_', '-', $default_lang);
            $is_main_lang = ($language_code === $default_lang);

            if (!$is_main_lang) {
                $enabled_langs = get_post_meta($post_data->ID, '_enabled_languages', true);
                if (!empty($enabled_langs)) {
                    $enabled_langs = is_array($enabled_langs) ? $enabled_langs : json_decode($enabled_langs, true);
                    if (is_array($enabled_langs) && !in_array($language_code, $enabled_langs)) {
                        return null;
                    }
                }
            }
        }

        if ($apply_i18n && $language_code && function_exists('todo_get_post_multilang')) {
            try {
                $agg = todo_get_post_multilang($post_data->ID, $language_code, [
                    'include_seo' => true,
                    'fallback' => true,
                    'status_allowed' => ['approved', 'draft'],
                    'prefer_custom_url' => true,
                    'post_object' => $post_data
                ]);
                if (is_array($agg)) {
                    if (isset($agg['title'])) {
                        $post_data->post_title = $agg['title'];
                    }
                    if (isset($agg['content'])) {
                        $post_data->post_content = $agg['content'];
                    }
                    if (isset($agg['excerpt'])) {
                        $post_data->post_excerpt = $agg['excerpt'];
                    }
                    if (isset($agg['slug'])) {
                        $post_data->post_name = $agg['slug'];
                    }
                    if (isset($agg['seo_title'])) {
                        $post_data->seo_title = $agg['seo_title'];
                    }
                    if (isset($agg['seo_keywords'])) {
                        $post_data->seo_keywords = $agg['seo_keywords'];
                    }
                    if (isset($agg['seo_description'])) {
                        $post_data->seo_description = $agg['seo_description'];
                    }
                    if (isset($agg['url'])) {
                        $post_data->custom_url = $agg['url'];
                    }
                    $post_data->i18n_applied = $language_code;
                }
            } catch (Exception $e) {
                // 忽略聚合器异常，保持原文
                error_log('todo_get_post_multilang error: ' . $e->getMessage());
            }
        }

        // 根据输出类型返回结果
        if ($output === 'ARRAY_A') {
            return (array) $post_data;
        } elseif ($output === 'ARRAY_N') {
            return array_values((array) $post_data);
        }

        return $post_data;
    }
}

/**
 * 获取文章列表
 *
 * @param array $args 查询参数
 * @return array 文章列表
 */
if (!function_exists('get_posts')) {
    function get_posts($args = array())
    {
        global $tododb;

        // 默认参数
        $defaults = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 10,
            'paged' => 1,
            'orderby' => 'post_date',
            'order' => 'DESC',
            's' => '',
            'cat' => 0,
            'tag' => '',
            'tag_id' => 0,
            'author' => 0,
            'fields' => '*', // 允许指定要获取的字段，减少内存使用
            'load_meta' => true,  // 是否加载元数据
            'load_terms' => true, // 是否加载分类和标签
            'memory_limit' => '256M' // 临时内存限制
        );

        // 合并参数
        $args = array_merge($defaults, $args);

        // 设置内存限制
        $original_memory_limit = ini_get('memory_limit');
        if ($args['memory_limit']) {
            ini_set('memory_limit', $args['memory_limit']);
        }

        try {
            // 限制每页文章数量，防止内存溢出
            if ($args['posts_per_page'] > 50) {
                $args['posts_per_page'] = 50;
            }

            // 构建查询
            if ($args['fields'] === '*') {
                $fields = 'p.*';
            } elseif ($args['fields'] === 'ids') {
                $fields = 'p.ID';
            } else {
                // 如果指定了字段，需要确保每个字段都有表别名前缀
                $field_list = explode(',', $args['fields']);
                $field_list = array_map('trim', $field_list);
                $field_list = array_map(function ($field) {
                    // 如果字段已经有表别名前缀，直接返回；否则添加 p. 前缀
                    return (strpos($field, '.') !== false) ? $field : 'p.' . $field;
                }, $field_list);
                $fields = implode(', ', $field_list);
            }
            $where = array();
            $values = array();

            // 处理多语言启用过滤
            $language_code = (function_exists('get_current_language') ? get_current_language() : (function_exists('todo_get_current_language') ? todo_get_current_language() : null));

            if ($language_code) {
                $language_code = str_replace('_', '-', $language_code);
            }
            $apply_i18n = (!defined('IS_ADMIN') || !IS_ADMIN);

            if ($apply_i18n && $language_code) {
                // 检查是否为主语言（主语言可见所有文章）
                $default_lang = function_exists('get_default_language') ? get_default_language() : 'zh-CN';
                $default_lang = str_replace('_', '-', $default_lang);
                $is_main_lang = ($language_code === $default_lang);

                if (!$is_main_lang) {
                    // 方案：使用 JSON_CONTAINS 过滤。非主语言下，必须显式启用该语言才显示。
                    $where[] = "EXISTS (SELECT 1 FROM {$tododb->prefix}postmeta WHERE post_id = p.ID AND meta_key = '_enabled_languages' AND JSON_CONTAINS(meta_value, ?))";
                    $values[] = '"' . $language_code . '"';
                }
            }

            $select_fields = $fields;
            // 根据需求决定是否连接产品模型字段表（用于发售状态warehouse筛选/排除）
            // 优化：只有以下情况才 JOIN product 表，避免非产品查询的无效 JOIN（约 70-125ms/次）：
            //   1. post_status 包含 'warehouse'（仓库态）
            //   2. post_type 明确包含 'product'（产品模型）
            //   3. 显式传入了 sale_status 参数
            $need_product_join = false;
            $ps = $args['post_status'];
            $pt = $args['post_type'];
            $is_product_type = ($pt === 'product' || (is_array($pt) && in_array('product', $pt, true)));
            $has_warehouse_status = ($ps === 'warehouse' || (is_array($ps) && in_array('warehouse', $ps, true)));
            if ($has_warehouse_status || ($is_product_type && ($ps === 'publish' || (is_array($ps) && in_array('publish', $ps, true)))) || !empty($args['sale_status'])) {
                $need_product_join = true;
            }

            if ($need_product_join) {
                if ($fields === 'p.*') {
                    $select_fields .= ', mfp.sale_status';
                }
            }

            $query = "SELECT {$select_fields} FROM {$tododb->prefix}posts p";
            if ($need_product_join) {
                $query .= " LEFT JOIN {$tododb->prefix}model_fields_product mfp ON mfp.post_id = p.ID";
            }

            $is_admin_context = (defined('IS_ADMIN') && IS_ADMIN);
            $site_id_arg_provided = (array_key_exists('site_id', $args) && $args['site_id'] !== null);
            if ($site_id_arg_provided) {
                $current_site_id = (int) $args['site_id'];
            } elseif ($is_admin_context) {
                $current_site_id = (isset($_GET['site']) && is_numeric($_GET['site']) && (int) $_GET['site'] > 0) ? (int) $_GET['site'] : 0;
                // 缓存预热上下文：管理员触发的预热流程内嵌前台渲染，
                // $_GET['site'] 未设置时需走前台站点解析链路而非直接归零
                if ($current_site_id <= 0 && !empty($GLOBALS['todo_doing_cache_warmup'])) {
                    $current_site_id = function_exists('todo_current_site_id') ? (int) todo_current_site_id(true) : 0;
                }
            } else {
                $current_site_id = function_exists('todo_current_site_id') ? (int) todo_current_site_id(true) : 0;
            }
            $apply_site_filter = ($current_site_id > 0);
            $status_col = 'p.post_status';
            if ($apply_site_filter && function_exists('todo_get_post_site_visibility_sql')) {
                $site_posts_table = $tododb->prefix . 'site_posts';
                $sys_def_site = function_exists('todo_get_default_site_id') ? (int)todo_get_default_site_id() : 1;

                $has_sp = function_exists('todo_site_posts_has_bindings') ? todo_site_posts_has_bindings() : (function_exists('todo_site_posts_table_exists') && todo_site_posts_table_exists());
                if ($has_sp) {
                    $query .= " LEFT JOIN {$site_posts_table} sp ON (p.ID = sp.post_id AND sp.site_id = {$current_site_id})";
                    $status_col = "COALESCE(sp.status, p.post_status)";
                    $where[] = todo_get_post_site_visibility_sql('sp', $current_site_id, 0, 'p.ID');
                }
            }

            // 处理可见性过滤：若未显式指定 visibility，且非后台，则默认只筛选 public 文章
            static $has_vis_meta = null;
            if ($has_vis_meta === null) {
                $check_meta = $tododb->get_var("SELECT meta_id FROM {$tododb->prefix}postmeta WHERE meta_key = '_visibility' LIMIT 1");
                $has_vis_meta = !empty($check_meta);
            }

            if (isset($args['visibility']) && $args['visibility'] !== 'any') {
                if ($has_vis_meta) {
                    $vis_meta_sub = "(SELECT m_vis.meta_value FROM {$tododb->prefix}postmeta m_vis WHERE m_vis.post_id = p.ID AND m_vis.meta_key = '_visibility' LIMIT 1)";
                    if (is_array($args['visibility'])) {
                        $placeholders_vis = str_repeat('?,', count($args['visibility']) - 1) . '?';
                        $where[] = "COALESCE({$vis_meta_sub}, p.visibility) IN ({$placeholders_vis})";
                        foreach ($args['visibility'] as $v_item) {
                            $values[] = $v_item;
                        }
                    } else {
                        $where[] = "COALESCE({$vis_meta_sub}, p.visibility) = ?";
                        $values[] = $args['visibility'];
                    }
                } else {
                    if (is_array($args['visibility'])) {
                        $placeholders_vis = str_repeat('?,', count($args['visibility']) - 1) . '?';
                        $where[] = "p.visibility IN ({$placeholders_vis})";
                        foreach ($args['visibility'] as $v_item) {
                            $values[] = $v_item;
                        }
                    } else {
                        $where[] = "p.visibility = ?";
                        $values[] = $args['visibility'];
                    }
                }
            } elseif (!isset($args['visibility']) && (!defined('IS_ADMIN') || !IS_ADMIN)) {
                if ($has_vis_meta) {
                    $where[] = "(p.visibility = 'public' AND NOT EXISTS (SELECT 1 FROM {$tododb->prefix}postmeta m_vis WHERE m_vis.post_id = p.ID AND m_vis.meta_key = '_visibility' AND m_vis.meta_value <> 'public'))";
                } else {
                    $where[] = "p.visibility = 'public'";
                }
            }

            // 处理文章类型
            $post_type = $args['post_type'];
            if ($post_type !== 'any') {
                if (is_array($post_type)) {
                    $placeholders = str_repeat('?,', count($post_type) - 1) . '?';
                    $where[] = "p.post_type IN ({$placeholders})";
                    foreach ($post_type as $type) {
                        $values[] = $type;
                    }
                } else {
                    $where[] = "p.post_type = ?";
                    $values[] = $post_type;
                }
            }

            // 处理文章状态（扩展：支持产品模型的仓库态）
            $post_status = $args['post_status'];
            if ($post_status === 'scheduled') {
                // 定时文章：包含两种存储形态
                // 1) 未来发布时间的已发布文章（publish 且 post_date_gmt > now）
                // 2) 处于 future 状态的文章（保存时明确标记）
                $now_gmt = current_time('mysql', true);
                $where[] = "({$status_col} = 'future' OR ({$status_col} = 'publish' AND p.post_date_gmt > ?))";
                $values[] = $now_gmt;
            } elseif (is_array($post_status)) {
                // 处理数组状态，需排除定时发布，同时可包含仓库态
                $now_gmt = current_time('mysql', true);
                $status_conditions = array();

                foreach ($post_status as $status) {
                    if ($status === 'publish') {
                        // 已发布且非定时，且排除产品处于仓库态
                        $status_conditions[] = "({$status_col} = 'publish' AND p.post_date_gmt <= ?" . ($need_product_join ? " AND (mfp.sale_status IS NULL OR mfp.sale_status <> 'warehouse')" : "") . ")";
                        $values[] = $now_gmt;
                    } elseif ($status === 'future') {
                        // 在组合状态中包含定时文章：future 状态或 publish 且发布时间在未来
                        $status_conditions[] = "({$status_col} = 'future' OR ({$status_col} = 'publish' AND p.post_date_gmt > ?))";
                        $values[] = $now_gmt;
                    } elseif ($status === 'warehouse') {
                        // 产品仓库态：仅统计已到发布时间且发售状态为仓库
                        $status_conditions[] = "({$status_col} = 'publish' AND p.post_date_gmt <= ? AND mfp.sale_status = 'warehouse')";
                        $values[] = $now_gmt;
                    } else {
                        // 其他状态正常处理
                        $status_conditions[] = "{$status_col} = ?";
                        $values[] = $status;
                    }
                }

                if (!empty($status_conditions)) {
                    $where[] = "(" . implode(' OR ', $status_conditions) . ")";
                }
            } else {
                // 单个状态处理
                if ($post_status === 'publish') {
                    // 对于publish状态，只包含已发布且不是定时发布的文章；并排除产品仓库态
                    $now_gmt = current_time('mysql', true);
                    $cond = "{$status_col} = 'publish' AND p.post_date_gmt <= ?";
                    if ($need_product_join) {
                        $cond .= " AND (mfp.sale_status IS NULL OR mfp.sale_status <> 'warehouse')";
                    }
                    $where[] = $cond;
                    $values[] = $now_gmt;
                } elseif ($post_status === 'future') {
                    // 兼容：如有直接按 future 状态筛选的需求
                    $where[] = "{$status_col} = 'future'";
                } elseif ($post_status === 'warehouse') {
                    // 仓库态：仅统计已到发布时间且发售状态为仓库
                    $now_gmt = current_time('mysql', true);
                    if (!$need_product_join) {
                        $query .= " LEFT JOIN {$tododb->prefix}model_fields_product mfp ON mfp.post_id = p.ID";
                        $need_product_join = true;
                    }
                    $where[] = "({$status_col} = 'publish' AND p.post_date_gmt <= ? AND mfp.sale_status = 'warehouse')";
                    $values[] = $now_gmt;
                } else {
                    $where[] = "{$status_col} = ?";
                    $values[] = $post_status;
                }
            }

            // 处理售卖状态过滤 (交叉筛选)
            if (!empty($args['sale_status'])) {
                if (!$need_product_join) {
                    $query = str_replace("FROM {$tododb->prefix}posts p", "FROM {$tododb->prefix}posts p LEFT JOIN {$tododb->prefix}model_fields_product mfp ON mfp.post_id = p.ID", $query);
                    $need_product_join = true;
                }
                if ($args['sale_status'] === 'warehouse') {
                    $where[] = "mfp.sale_status = 'warehouse'";
                } elseif ($args['sale_status'] === 'on_sale') {
                    $where[] = "(mfp.sale_status IS NULL OR mfp.sale_status <> 'warehouse')";
                }
            }

            // 处理搜索（优先匹配 ID，搜索ID、标题、作者、标签，不搜索内容）
            if (!empty($args['s'])) {
                // 如果搜索内容是纯数字，优先匹配 ID
                if (is_numeric($args['s'])) {
                    // 添加作者和标签的JOIN（如果还没有）
                    if (!strpos($query, 'todo_users')) {
                        $query .= " LEFT JOIN {$tododb->prefix}users u ON p.post_author = u.ID";
                    }
                    if (!strpos($query, 'term_relationships tr_search')) {
                        $query .= " LEFT JOIN {$tododb->prefix}term_relationships tr_search ON p.ID = tr_search.object_id";
                        $query .= " LEFT JOIN {$tododb->prefix}term_taxonomy tt_search ON tr_search.term_taxonomy_id = tt_search.term_taxonomy_id";
                        $query .= " LEFT JOIN {$tododb->prefix}terms t_search ON tt_search.term_id = t_search.term_id";
                    }
                    
                    $where[] = "(p.ID = ? OR p.post_title LIKE ? OR u.user_login LIKE ? OR u.display_name LIKE ? OR (tt_search.taxonomy = 'post_tag' AND t_search.name LIKE ?))";
                    $values[] = intval($args['s']); // ID 精确匹配
                    $search_term = '%' . $tododb->esc_like($args['s']) . '%';
                    $values[] = $search_term; // 标题
                    $values[] = $search_term; // 用户名
                    $values[] = $search_term; // 显示名
                    $values[] = $search_term; // 标签名
                } else {
                    // 添加作者和标签的JOIN（如果还没有）
                    if (!strpos($query, 'todo_users')) {
                        $query .= " LEFT JOIN {$tododb->prefix}users u ON p.post_author = u.ID";
                    }
                    if (!strpos($query, 'term_relationships tr_search')) {
                        $query .= " LEFT JOIN {$tododb->prefix}term_relationships tr_search ON p.ID = tr_search.object_id";
                        $query .= " LEFT JOIN {$tododb->prefix}term_taxonomy tt_search ON tr_search.term_taxonomy_id = tt_search.term_taxonomy_id";
                        $query .= " LEFT JOIN {$tododb->prefix}terms t_search ON tt_search.term_id = t_search.term_id";
                    }
                    
                    $where[] = "(p.post_title LIKE ? OR u.user_login LIKE ? OR u.display_name LIKE ? OR (tt_search.taxonomy = 'post_tag' AND t_search.name LIKE ?))";
                    $search_term = '%' . $tododb->esc_like($args['s']) . '%';
                    $values[] = $search_term; // 标题
                    $values[] = $search_term; // 用户名
                    $values[] = $search_term; // 显示名
                    $values[] = $search_term; // 标签名
                }
            }

            // 处理分类（同时支持 posts.category_id 直连列和 term_relationships 关系表，支持多ID如 "7,16" 或数组）
            if (!empty($args['cat'])) {
                $cat_raw = is_array($args['cat']) ? $args['cat'] : explode(',', (string)$args['cat']);
                $cat_ids = array_values(array_filter(array_map('intval', $cat_raw), function($v) { return $v > 0; }));
                if (!empty($cat_ids)) {
                    $cat_in = implode(',', $cat_ids);
                    $where[] = "( (p.category_id IS NOT NULL AND p.category_id IN ({$cat_in})) OR EXISTS (SELECT 1 FROM {$tododb->prefix}term_relationships tr_cat JOIN {$tododb->prefix}term_taxonomy tt_cat ON tr_cat.term_taxonomy_id = tt_cat.term_taxonomy_id WHERE tr_cat.object_id = p.ID AND tt_cat.taxonomy = 'category' AND tt_cat.term_id IN ({$cat_in})) )";
                }
            }

            // 处理 noid 参数 (排除指定的分类 ID)
            if (!empty($args['noid'])) {
                $noid_raw = is_array($args['noid']) ? $args['noid'] : explode(',', (string)$args['noid']);
                $noid_ids = array_values(array_filter(array_map('intval', $noid_raw), function($v) { return $v > 0; }));
                if (!empty($noid_ids)) {
                    $noid_in = implode(',', $noid_ids);
                    $where[] = "( (p.category_id IS NULL OR p.category_id NOT IN ({$noid_in})) AND NOT EXISTS (SELECT 1 FROM {$tododb->prefix}term_relationships tr_noid JOIN {$tododb->prefix}term_taxonomy tt_noid ON tr_noid.term_taxonomy_id = tt_noid.term_taxonomy_id WHERE tr_noid.object_id = p.ID AND tt_noid.taxonomy = 'category' AND tt_noid.term_id IN ({$noid_in})) )";
                }
            }

            // 处理 doc_properties 参数
            if (!empty($args['doc_properties'])) {
                $dp_raw = is_array($args['doc_properties']) ? $args['doc_properties'] : explode(',', (string)$args['doc_properties']);
                $dp_ids = array_values(array_filter(array_map('intval', $dp_raw), function($v) { return $v > 0; }));
                if (!empty($dp_ids)) {
                    $dp_wheres = [];
                    foreach ($dp_ids as $dp_id) {
                        $dp_wheres[] = "FIND_IN_SET({$dp_id}, p.doc_properties) > 0";
                    }
                    $where[] = "(" . implode(" OR ", $dp_wheres) . ")";
                }
            }

            // 处理新版标签过滤 (tag_id)
            if (!empty($args['tag_id'])) {
                $query .= " INNER JOIN {$tododb->prefix}post_tags pt ON p.ID = pt.post_id";
                $where[] = "pt.tag_id = ?";
                $values[] = intval($args['tag_id']);
            }
            // 处理新版和旧版标签过滤 (tag slug 或 tag_id 数字形式)
            elseif (!empty($args['tag'])) {
                if (is_numeric($args['tag'])) {
                    $query .= " INNER JOIN {$tododb->prefix}post_tags pt ON p.ID = pt.post_id";
                    $where[] = "pt.tag_id = ?";
                    $values[] = intval($args['tag']);
                } else {
                    $query .= " INNER JOIN {$tododb->prefix}post_tags pt ON p.ID = pt.post_id";
                    $query .= " INNER JOIN {$tododb->prefix}tags t ON pt.tag_id = t.tag_id";
                    $where[] = "t.tag_slug = ?";
                    $values[] = $args['tag'];
                }
            }

            // 处理作者
            if (!empty($args['author'])) {
                $where[] = "p.post_author = ?";
                $values[] = $args['author'];
            }

            // 添加WHERE子句
            if (!empty($where)) {
                $query .= " WHERE " . implode(' AND ', $where);
            }

            // 添加GROUP BY子句（仅当 FROM 段存在 JOIN；WHERE 子查询中的 JOIN 不需要分组）
            $from_segment = strstr($query, ' WHERE ', true);
            if ($from_segment === false) {
                $from_segment = $query;
            }
            if (strpos($from_segment, 'JOIN')) {
                $query .= " GROUP BY p.ID";
            }

            // 添加ORDER BY子句
            // 处理orderby参数，确保使用正确的列名
            $orderby_column = $args['orderby'];
            if ($orderby_column === 'date') {
                $orderby_column = 'post_date';
            }
            // 验证列名是否有效，防止SQL注入和无效列名
            $valid_columns = ['ID', 'post_author', 'post_date', 'post_date_gmt', 'post_title', 'post_status', 'post_name', 'post_modified', 'post_modified_gmt', 'post_type', 'comment_count', 'view_count', 'sort_order'];
            if (!in_array($orderby_column, $valid_columns)) {
                $orderby_column = 'post_date'; // 默认使用post_date
            }

            // 验证order参数
            $order = strtoupper($args['order']);
            if (!in_array($order, ['ASC', 'DESC'])) {
                $order = 'DESC'; // 默认使用DESC
            }

            // 如果搜索的是数字（ID），让精确匹配的结果排在最前面
            if (!empty($args['s']) && is_numeric($args['s'])) {
                $search_id = intval($args['s']);
                $query .= " ORDER BY (p.ID = {$search_id}) DESC, p.{$orderby_column} {$order}, p.ID DESC";
            } else {
                $query .= " ORDER BY p.{$orderby_column} {$order}, p.ID DESC";
            }

            // 添加LIMIT子句
            $offset = ($args['paged'] - 1) * $args['posts_per_page'];
            $query .= " LIMIT ?, ?";
            $values[] = $offset;
            $values[] = $args['posts_per_page'];

            // 执行查询
            $posts = $tododb->get_results($query, $values);

            // 如果只请求ID，直接返回ID数组
            if ($args['fields'] === 'ids') {
                $ids = array();
                foreach ($posts as $post) {
                    $ids[] = (int) (is_object($post) ? $post->ID : $post['ID']);
                }
                return $ids;
            }

            // 批量预加载优化：优先使用ORM批量加载（性能优化）
            if (!empty($posts)) {
                $post_ids = [];
                foreach ($posts as $p) {
                    if (is_object($p) && isset($p->ID)) {
                        $post_ids[] = (int) $p->ID;
                    } elseif (is_array($p) && isset($p['ID'])) {
                        $post_ids[] = (int) $p['ID'];
                    }
                }

                if (!empty($post_ids)) {
                    // 优先使用ORM批量加载（减少查询次数）
                    if (class_exists('Todo_Post_ORM')) {
                        Todo_Post_ORM::eager_load_all($post_ids);
                    } else {
                        // 回退到原有方法
                        update_post_meta_cache($post_ids);

                        // 批量预加载分类缓存
                        if ($args['load_terms']) {
                            update_post_term_cache($post_ids, 'category');

                            // 批量预加载标签缓存
                            if (class_exists('CMS_Tags_Manager')) {
                                CMS_Tags_Manager::update_post_tags_cache($post_ids);
                            }
                        }
                    }
                }
            }

            // 处理结果
            $result = array();
            $language_code = (function_exists('get_current_language') ? get_current_language() : (function_exists('todo_get_current_language') ? todo_get_current_language() : null));
            $apply_i18n = (!function_exists('is_admin') || !is_admin());
            // 安全提取ID（兼容对象和数组）
            $post_ids = [];
            foreach ($posts as $p) {
                if (is_object($p) && isset($p->ID)) {
                    $post_ids[] = $p->ID;
                } elseif (is_array($p) && isset($p['ID'])) {
                    $post_ids[] = $p['ID'];
                }
            }

            $translations = [];
            if (!empty($post_ids)) {
                // 批量预加载分类、标签及元数据，彻底消除 N+1 查询
                if (class_exists('Todo_Post_ORM')) {
                    Todo_Post_ORM::eager_load_all($post_ids, [
                        'meta'       => !empty($args['load_meta']),
                        'terms'      => !empty($args['load_terms']),
                        'tags'       => !empty($args['load_terms']),
                        'categories' => !empty($args['load_terms']),
                        'multilang'  => false // 下文已有独立处理
                    ]);
                }

                // 批量预加载多语言数据（新方案：批量模式，避免N+1查询）
                if ($apply_i18n && $language_code && function_exists('todo_get_posts_multilang_batch')) {
                    $translations = todo_get_posts_multilang_batch($post_ids, $language_code, [
                        'include_seo' => true,
                        'fallback' => true,
                        'status_allowed' => ['approved', 'draft']
                    ]);
                }
            }

            foreach ($posts as $post) {
                // 缓存到全局缓存，避免后续重复查询（仅当获取完整字段时，防止后续调用由于字段缺失报错）
                if (!isset($GLOBALS['todo_internal_post_cache'])) {
                    $GLOBALS['todo_internal_post_cache'] = [];
                }
                if ($args['fields'] === '*' || $args['fields'] === 'p.*') {
                    $GLOBALS['todo_internal_post_cache'][$post->ID] = $post;
                }

                // 应用预加载的多语言数据
                if (isset($translations[$post->ID])) {
                    $agg = $translations[$post->ID];
                    if (isset($agg['title'])) {
                        $post->post_title = $agg['title'];
                    }
                    if (isset($agg['content'])) {
                        $post->post_content = $agg['content'];
                    }
                    if (isset($agg['excerpt'])) {
                        $post->post_excerpt = $agg['excerpt'];
                    }
                    if (isset($agg['slug'])) {
                        $post->post_name = $agg['slug'];
                    }
                    if (isset($agg['seo_title'])) {
                        $post->seo_title = $agg['seo_title'];
                    }
                    if (isset($agg['seo_keywords'])) {
                        $post->seo_keywords = $agg['seo_keywords'];
                    }
                    if (isset($agg['seo_description'])) {
                        $post->seo_description = $agg['seo_description'];
                    }
                    if (isset($agg['url'])) {
                        $post->custom_url = $agg['url'];
                    }
                    $post->i18n_applied = $language_code;
                }

                // 获取文章元数据（如果需要）
                if ($args['load_meta']) {
                    $post->meta = get_post_meta($post->ID);
                }

                // 获取文章分类和标签（如果需要）
                if ($args['load_terms']) {
                    $post->categories = todo_get_post_categories($post->ID);
                    $post->tags = todo_get_post_tags($post->ID);
                }

                $result[] = $post;
            }

            return $result;
        } catch (Exception $e) {
            error_log('获取文章时出错: ' . $e->getMessage());
            return array();
        } finally {
            // 恢复原始内存限制
            if ($args['memory_limit']) {
                ini_set('memory_limit', $original_memory_limit);
            }
        }
    }
}

/**
 * 获取单个术语（分类或标签）
 *
 * @param int $term_id 术语ID
 * @param string $taxonomy 分类法名称（category或tag）
 * @return object|null 术语对象或null
 */
if (!function_exists('get_term')) {
    function get_term($term_id, $taxonomy)
    {
        static $term_cache = [];
        $cache_key = $term_id . '_' . $taxonomy;
        if (isset($term_cache[$cache_key])) {
            return $term_cache[$cache_key];
        }

        global $tododb;

        // 验证参数
        $term_id = intval($term_id);
        if ($term_id <= 0) {
            return null;
        }

        // 查询数据库获取术语
        $term = $tododb->get_row(
            "SELECT t.*, tt.* 
        FROM {$tododb->prefix}terms AS t 
        INNER JOIN {$tododb->prefix}term_taxonomy AS tt ON t.term_id = tt.term_id 
        WHERE t.term_id = ? AND tt.taxonomy = ? 
        LIMIT 1",
            array($term_id, $taxonomy)
        );

        if (!$term) {
            $term_cache[$cache_key] = null;
            return null;
        }

        // 返回对象格式（保持与其他函数的兼容性）
        $term_cache[$cache_key] = $term;
        return $term;
    }
}

/**
 * 获取术语（分类或标签）的链接
 *
 * @param int|object $term 术语ID或对象
 * @param string $taxonomy 分类法名称（category或tag）
 * @return string 术语链接
 */
function get_term_link($term, $taxonomy = '')
{
    $term_id = 0;
    if (is_object($term)) {
        $term_id = isset($term->term_id) ? (int)$term->term_id : 0;
        if (empty($taxonomy) && isset($term->taxonomy)) {
            $taxonomy = $term->taxonomy;
        }
    } else {
        $term_id = (int)$term;
    }

    if ($term_id <= 0) {
        return '';
    }

    // 如果没有指定分类法，尝试获取术语信息
    if (empty($taxonomy)) {
        $t = get_term($term_id, 'category');
        if ($t) {
            $taxonomy = 'category';
        } else {
            $t = get_term($term_id, 'post_tag');
            if ($t) {
                $taxonomy = 'post_tag';
            } else {
                return '';
            }
        }
    }

    // 根据分类法生成链接
    if ($taxonomy == 'category') {
        return todo_url() . '/category/' . $term_id;
    } elseif ($taxonomy == 'post_tag' || $taxonomy == 'tag') {
        return todo_url() . '/tag/' . $term_id;
    }

    return '';
}

/**
 * 通过特定字段获取文章
 *
 * @param string $field 字段名称
 * @param mixed $value 字段值
 * @return object|null 文章对象或null
 */
function get_post_by($field, $value)
{
    global $tododb;

    if ($field === 'id') {
        $field = 'ID';
    }

    $post = $tododb->get_row("SELECT * FROM {$tododb->prefix}posts WHERE {$field} = ?", [$value]);

    return $post;
}

/**
 * 插入新文章
 *
 * @param array $postarr 文章数据
 * @return int|TODO_Error 文章ID或错误对象
 */
if (!function_exists('todo_insert_post')) {
    function todo_insert_post($postarr)
    {
        global $tododb;

        // 默认值
        $defaults = array(
            'post_author' => get_current_user_id(),
            'post_content' => '',
            'post_title' => '',
            'post_excerpt' => '',
            'post_status' => 'draft',
            'post_type' => 'post',
            'comment_status' => 'open',

            'post_name' => '',
            'post_date' => current_time('mysql'),
            'post_date_gmt' => current_time('mysql', true),
            'post_modified' => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', true),
            'seo_description' => ''
        );

        // 合并参数
        $postarr = array_merge($defaults, $postarr);

        // 验证必填字段
        if (empty($postarr['post_title'])) {
            return new TODO_Error('empty_title', '文章标题不能为空');
        }

        // 如果没有指定别名，根据标题生成
        if (empty($postarr['post_name'])) {
            $postarr['post_name'] = sanitize_title($postarr['post_title']);
        }

        // 开始事务
        $tododb->begin_transaction();

        try {
            // 插入文章（第一层：基础数据）
            $insert_data = array(
                'post_author' => $postarr['post_author'],
                'post_date' => $postarr['post_date'],
                'post_date_gmt' => $postarr['post_date_gmt'],
                'post_content' => $postarr['post_content'],
                'post_title' => $postarr['post_title'],
                'post_excerpt' => $postarr['post_excerpt'],
                'post_status' => $postarr['post_status'],
                'comment_status' => $postarr['comment_status'],
                'post_name' => $postarr['post_name'],
                'post_modified' => $postarr['post_modified'],
                'post_modified_gmt' => $postarr['post_modified_gmt'],
                'post_type' => $postarr['post_type']
            );

            // 文章密码（仅当可见性为 password 时生效）
            if (isset($postarr['post_password'])) {
                $insert_data['post_password'] = $postarr['post_password'];
            }

            // 添加模型ID（三层架构关键字段）
            if (isset($postarr['model_id'])) {
                $insert_data['model_id'] = $postarr['model_id'];
            }

            // 添加第一层存储
            if (isset($postarr['seo_title'])) {
                $insert_data['seo_title'] = $postarr['seo_title'];
            }
            if (isset($postarr['seo_keywords'])) {
                $insert_data['seo_keywords'] = $postarr['seo_keywords'];
            }
            if (isset($postarr['seo_description'])) {
                $insert_data['seo_description'] = $postarr['seo_description'];
            }

            // 添加基础字段（第一层存储）
            if (isset($postarr['view_count'])) {
                $insert_data['view_count'] = intval($postarr['view_count']);
            }
            if (isset($postarr['read_permission'])) {
                $insert_data['read_permission'] = $postarr['read_permission'];
            }
            if (isset($postarr['visibility'])) {
                $insert_data['visibility'] = $postarr['visibility'];
            }
            if (isset($postarr['view_template'])) {
                $insert_data['view_template'] = $postarr['view_template'];
            }
            if (isset($postarr['doc_properties'])) {
                $insert_data['doc_properties'] = $postarr['doc_properties'];
            }
            // 添加特色图片字段（第一层存储）
            if (isset($postarr['featured_image'])) {
                $insert_data['featured_image'] = $postarr['featured_image'];
            }

            $result = $tododb->insert("{$tododb->prefix}posts", $insert_data);

            if (!$result) {
                throw new Exception('插入文章失败');
            }
            $post_id = $tododb->insert_id();

            // 回补 post_name：追加文章ID防止重复（如 de-guo-mai-mai-ban-kuai-76）
            if (empty($postarr['post_name']) || $postarr['post_name'] === sanitize_title($postarr['post_title'])) {
                $slug_with_id = todo_generate_slug($postarr['post_title'], $post_id);
                $tododb->update("{$tododb->prefix}posts", array('post_name' => $slug_with_id), array('ID' => $post_id));
            }

            // 处理分类
            if (isset($postarr['post_category']) && is_array($postarr['post_category'])) {
                todo_set_post_categories($post_id, $postarr['post_category']);
            }

            // 处理标签
            if (isset($postarr['tags_input']) && !empty($postarr['tags_input'])) {
                todo_set_post_tags($post_id, $postarr['tags_input']);
            }

            // 提交事务
            $tododb->commit();

            // Phase 3: Site Content Isolation - 同步站点映射 (门店内容隔离)
            if (function_exists('todo_ensure_post_site_mapping')) {
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
                    // 全局视图（无显式 ?site= 参数）→ 不绑定任何站点，文章全局可见
                    // 只有在特定站点管理视图（?site=N）下才绑定该站点
                    if (!empty($_GET['site']) && is_numeric($_GET['site']) && (int)$_GET['site'] > 0) {
                        $mapping_site_id = (int) $_GET['site'];
                    }
                    // 也允许通过 postarr 显式指定（非 IS_ADMIN 场景或程序内部调用）
                } elseif (isset($postarr['site_id']) && is_numeric($postarr['site_id'])) {
                    $mapping_site_id = (int) $postarr['site_id'];
                }

                if ($mapping_site_id !== null && $mapping_site_id > 0) {
                    $mapping_args = ['site_id' => $mapping_site_id];
                    if (isset($postarr['post_status'])) $mapping_args['status'] = $postarr['post_status'];
                    if (isset($postarr['is_visible'])) $mapping_args['is_visible'] = $postarr['is_visible'];
                    if (isset($postarr['site_order'])) $mapping_args['site_order'] = $postarr['site_order'];

                    todo_ensure_post_site_mapping($post_id, $mapping_args);
                }
                // $mapping_site_id 为 null（全局视图）→ 不写入映射 → 文章对所有站点可见
            }

            // 清理缓存
            if (class_exists('Todo_Unified_Cache_Manager')) {
                Todo_Unified_Cache_Manager::clean_post_cache($post_id);
            }

            // 触发插入钩子，通知其他系统（如邮件通知）
            todo_do_action('todo_post_inserted', $post_id, $postarr);

            // 触发更新钩子，通知统一缓存管理器
            todo_do_action('todo_post_updated', $post_id);

            return $post_id;
        } catch (Exception $e) {
            // 回滚事务
            $tododb->rollback();
            return new TODO_Error('db_insert_error', $e->getMessage());
        }
    }
}

/**
 * 更新文章
 *
 * @param array $postarr 文章数据
 * @return int|TODO_Error 文章ID或错误对象
 */
if (!function_exists('todo_update_post')) {
    function todo_update_post($postarr)
    {
        global $tododb;

        // 检查文章ID
        if (empty($postarr['ID'])) {
            return new TODO_Error('invalid_post', '无效的文章ID');
        }

        // DEBUG: Log update attempt
        error_log('todo_update_post called for ID ' . $postarr['ID']);
        if (isset($postarr['post_status'])) {
            error_log('todo_update_post status: ' . $postarr['post_status']);
        } else {
            error_log('todo_update_post status: NOT SET');
        }

        $post_id = $postarr['ID'];
        $post = get_post($post_id);

        if (!$post) {
            return new TODO_Error('invalid_post', '文章不存在');
        }

        // 本次保存若修改文档属性（推荐/加推/头条等），在 DB 写入前登记旧值，
        // 供缓存管理器按"旧值∪新值"清理 post:doc_prop:{id} 依赖页面
        // （覆盖"取消勾选推荐"后推荐块残留旧文章的场景）
        if (isset($postarr['doc_properties'])
            && class_exists('Todo_Unified_Cache_Manager')
            && method_exists('Todo_Unified_Cache_Manager', 'remember_post_previous_doc_properties')) {
            Todo_Unified_Cache_Manager::remember_post_previous_doc_properties($post_id, $post->doc_properties ?? '');
        }

        // 设置修改时间
        $postarr['post_modified'] = current_time('mysql');
        $postarr['post_modified_gmt'] = current_time('mysql', true);

        // 如果没有指定别名，根据标题生成（追加文章ID防止重复）
        if (isset($postarr['post_title']) && empty($postarr['post_name'])) {
            $postarr['post_name'] = todo_generate_slug($postarr['post_title'], $post_id);
        }

        // 开始事务
        $tododb->begin_transaction();

        try {
            // 更新文章（第一层：基础数据）
            $data = array();
            $fields = array(
                'post_author',
                'post_date',
                'post_date_gmt',
                'post_content',
                'post_title',
                'post_excerpt',
                'post_status',
                'comment_status',
                'ping_status',
                'post_password',
                'post_name',
                'post_modified',
                'post_modified_gmt',
                'menu_order',
                'post_type',
                'model_id',
                'custom_url'
            );

            // 添加第一层存储
            $seo_fields = array('seo_title', 'seo_keywords', 'seo_description');
            $fields = array_merge($fields, $seo_fields);

            // 添加基础字段（第一层存储）
            $basic_fields = array('view_count', 'read_permission', 'visibility', 'view_template', 'doc_properties', 'featured_image');
            $fields = array_merge($fields, $basic_fields);

            foreach ($fields as $field) {
                if (isset($postarr[$field])) {
                    if ($field === 'post_password') {
                        $data['post_password'] = $postarr[$field];
                    } else {
                        $data[$field] = $postarr[$field];
                    }
                }
            }

            $result = $tododb->update(
                "{$tododb->prefix}posts",
                $data,
                array('ID' => $post_id)
            );

            if ($result === false) {
                throw new Exception('更新文章失败');
            }

            // 处理分类
            if (isset($postarr['post_category'])) {
                todo_set_post_categories($post_id, $postarr['post_category']);
            }

            // 处理标签
            if (isset($postarr['tags_input'])) {
                todo_set_post_tags($post_id, $postarr['tags_input']);
            }

            // 提交事务
            $tododb->commit();

            // Phase 3: Site Content Isolation - 同步站点映射 (门店内容隔离)
            if (function_exists('todo_ensure_post_site_mapping')) {
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
                if (isset($postarr['site_id']) && is_numeric($postarr['site_id']) && (int)$postarr['site_id'] > 0) {
                    $mapping_site_id = (int) $postarr['site_id'];
                } elseif (defined('IS_ADMIN') && IS_ADMIN && function_exists('todo_current_site_id')) {
                    $mapping_site_id = (int) todo_current_site_id(false);
                }

                if ($mapping_site_id !== null && $mapping_site_id > 0) {
                    $mapping_args = ['site_id' => $mapping_site_id];
                    if (isset($postarr['post_status'])) $mapping_args['status'] = $postarr['post_status'];
                    if (isset($postarr['is_visible'])) $mapping_args['is_visible'] = $postarr['is_visible'];
                    if (isset($postarr['site_order'])) $mapping_args['site_order'] = $postarr['site_order'];

                    todo_ensure_post_site_mapping($post_id, $mapping_args);
                } else {
                    // 全局模式：更新该文章所有已存在的站点映射，保持原有站点归属
                    $existing_sites = $tododb->get_col("SELECT site_id FROM {$tododb->prefix}site_posts WHERE post_id = ?", [$post_id]);
                    if (!empty($existing_sites)) {
                        foreach ($existing_sites as $s_id) {
                            $mapping_args = ['site_id' => (int)$s_id];
                            if (isset($postarr['post_status'])) $mapping_args['status'] = $postarr['post_status'];
                            if (isset($postarr['is_visible'])) $mapping_args['is_visible'] = $postarr['is_visible'];
                            if (isset($postarr['site_order'])) $mapping_args['site_order'] = $postarr['site_order'];
                            todo_ensure_post_site_mapping($post_id, $mapping_args);
                        }
                    }
                }
            }

            // 清理缓存
            if (class_exists('Todo_Unified_Cache_Manager')) {
                Todo_Unified_Cache_Manager::clean_post_cache($post_id);
            }

            // 触发更新钩子
            todo_do_action('todo_post_updated', $post_id, $postarr);

            return $post_id;
        } catch (Exception $e) {
            // 回滚事务
            $tododb->rollback();
            return new TODO_Error('db_update_error', $e->getMessage());
        }
    }
}

/**
 * 将文章移至回收站
 *
 * @param int $post_id 文章ID
 * @return bool 是否成功
 */
if (!function_exists('todo_trash_post')) {
    function todo_trash_post($post_id)
    {
        global $tododb;

        $post = get_post($post_id);

        if (!$post) {
            return false;
        }

        // 保存原始状态
        update_post_meta($post_id, '_todo_trash_meta_status', $post->post_status);

        // 更新状态为回收站
        $result = $tododb->update(
            "{$tododb->prefix}posts",
            array('post_status' => 'trash'),
            array('ID' => $post_id)
        );

        if ($result !== false) {
            if (class_exists('Todo_Unified_Cache_Manager')) {
                Todo_Unified_Cache_Manager::clean_post_cache($post_id);
            }

            // 触发删除钩子
            todo_do_action('todo_post_deleted', $post_id);
        }

        return $result !== false;
    }
}

/**
 * 从回收站恢复文章
 *
 * @param int $post_id 文章ID
 * @return bool 是否成功
 */
if (!function_exists('todo_untrash_post')) {
    function todo_untrash_post($post_id)
    {
        global $tododb;

        $post = get_post($post_id);

        if (!$post || $post->post_status !== 'trash') {
            return false;
        }

        // 获取原始状态
        $status = get_post_meta($post_id, '_todo_trash_meta_status', true);

        if (empty($status)) {
            $status = 'draft';
        }

        // 删除元数据
        global $tododb;
        $tododb->query(
            "DELETE FROM {$tododb->prefix}post_custom_fields WHERE post_id = {$post_id} AND field_key = '_todo_trash_meta_status'"
        );

        // 恢复原始状态
        $result = $tododb->update(
            "{$tododb->prefix}posts",
            array('post_status' => $status),
            array('ID' => $post_id)
        );

        if ($result !== false) {
            if (class_exists('Todo_Unified_Cache_Manager')) {
                Todo_Unified_Cache_Manager::clean_post_cache($post_id);
            }

            // 触发更新钩子
            todo_do_action('todo_post_updated', $post_id);
        }

        return $result !== false;
    }
}

/**
 * 发布文章
 *
 * @param int $post_id 文章ID
 * @return bool 是否成功
 */
if (!function_exists('todo_publish_post')) {
    function todo_publish_post($post_id)
    {
        global $tododb;

        $post = get_post($post_id);

        if (!$post) {
            return false;
        }

        // 更新状态为已发布
        $result = $tododb->update(
            "{$tododb->prefix}posts",
            array(
                'post_status' => 'publish',
                'post_date' => current_time('mysql'),
                'post_date_gmt' => current_time('mysql', true)
            ),
            array('ID' => $post_id)
        );

        if ($result !== false) {
            if (class_exists('Todo_Unified_Cache_Manager')) {
                Todo_Unified_Cache_Manager::clean_post_cache($post_id);
            }

            // 触发更新钩子
            todo_do_action('todo_post_updated', $post_id);
        }

        return $result !== false;
    }
}

/**
 * 运行待到期的定时文章发布（动态触发）
 *
 * 说明：本方法在页面访问时触发，扫描所有处于 future 状态且发布时间已到达（或已过）的文章，
 * 将其状态更新为 publish，并保留原定发布时间（不覆盖 post_date/post_date_gmt）。
 */
function todo_run_scheduled_posts()
{
    global $tododb;

    // 防抖：一次请求内仅执行一次
    static $todo_scheduled_ran = false;
    if ($todo_scheduled_ran) {
        return;
    }
    $todo_scheduled_ran = true;

    // 性能优化：非CLI/后台/AJAX环境下，增加频率限制（例如1分钟一次），避免高并发下的数据库压力
    $is_maintenance_mode = (php_sapi_name() === 'cli') || is_admin() || (defined('DOING_AJAX') && DOING_AJAX);
    if (!$is_maintenance_mode) {
        $last_run = get_option('todo_scheduled_last_run_time', 0);
        if (time() - $last_run < 60) {
            return;
        }
        update_option('todo_scheduled_last_run_time', time());
    }

    // 安装/表存在性防护
    try {
        if (!isset($tododb) || !is_object($tododb)) {
            return;
        }
        // 优化：移除SHOW TABLES查询，直接依赖try-catch或缓存
        // 如果表不存在，下面的查询会报错，但在生产环境通常表是存在的
    } catch (Exception $e) {
        return;
    }

    // 当前时间（本地与GMT）
    $now_local = current_time('mysql');
    $now_gmt = current_time('mysql', true);

    // 优化：合并查询，减少查询次数
    // 优先检查是否有任何需要发布的文章（快速检查）
    $check_sql = "SELECT ID FROM {$tododb->prefix}posts 
                  WHERE post_status = 'future' 
                  AND (
                      (post_date IS NOT NULL AND post_date <= ?) 
                      OR (post_date_gmt IS NOT NULL AND post_date_gmt <= ?)
                  ) 
                  LIMIT 1";

    // 使用get_var代替get_results以减少内存
    // 注意：SQL使用?占位符（PDO风格），参数应直接传递给get_var，不需要调用prepare()
    $has_due = $tododb->get_var($check_sql, [$now_local, $now_gmt]);

    if (!$has_due) {
        // 如果没有常规到期文章，检查元数据（但这也应该被优化，避免每次都查）
        // 只有在真的没有常规future文章时才考虑meta，或者我们可以假设meta方式较少使用
        // 暂时保留meta检查但限制频率？或者整合逻辑
        // 为保持兼容性，仅当没找到时继续，但通常这就够了
        return;
    }

    // 查询到期的定时文章（一次最多处理50条）
    $sql = "SELECT ID FROM {$tododb->prefix}posts
            WHERE post_status = 'future'
              AND (
                (post_date IS NOT NULL AND post_date <= ?)
                OR
                (post_date_gmt IS NOT NULL AND post_date_gmt <= ?)
              )
            ORDER BY post_date ASC
            LIMIT 50";

    $due_posts = $tododb->get_results($sql, [$now_local, $now_gmt]);
    $cli = (php_sapi_name() === 'cli');
    if (function_exists('error_log')) {
        error_log('TodoCMS scheduled scan due=' . (is_array($due_posts) ? count($due_posts) : 0) . ' now_local=' . $now_local . ' now_gmt=' . $now_gmt);
    }
    if ($cli) {
        echo 'due=' . (is_array($due_posts) ? count($due_posts) : 0) . "\n";
    }

    if (!empty($due_posts)) {
        // 设置全局标志，表示本次请求中有定时文章被发布
        $GLOBALS['todo_scheduled_published'] = true;

        foreach ($due_posts as $row) {
            // 将状态更新为发布，保留原定发布时间，只更新时间戳
            $tododb->update(
                "{$tododb->prefix}posts",
                array(
                    'post_status' => 'publish',
                    'post_modified' => $now_local,
                    'post_modified_gmt' => $now_gmt
                ),
                array('ID' => intval($row->ID))
            );

            // 触发钩子，方便插件/主题扩展
            if (function_exists('todo_do_action')) {
                todo_do_action('todo_scheduled_post_published', intval($row->ID));
            }

            // 在静态缓存模式下，清理相关页面缓存
            if (function_exists('Todo_Unified_Cache_Manager') && class_exists('Todo_Unified_Cache_Manager')) {
                Todo_Unified_Cache_Manager::on_scheduled_post_published(intval($row->ID));
            }
            if ($cli) {
                echo 'published=' . intval($row->ID) . "\n";
            }
        }
    } else {
        $sql_meta = "SELECT p.ID, pm.meta_value AS scheduled_gmt
                      FROM {$tododb->prefix}posts p
                      JOIN {$tododb->prefix}postmeta pm
                        ON pm.post_id = p.ID AND pm.meta_key = ?
                      WHERE p.post_status = 'future'
                        AND pm.meta_value <= ?
                      ORDER BY pm.meta_value ASC
                      LIMIT 50";
        $due_meta = $tododb->get_results($sql_meta, ['_scheduled_time_gmt', $now_gmt]);
        if (function_exists('error_log')) {
            error_log('TodoCMS scheduled meta due=' . (is_array($due_meta) ? count($due_meta) : 0));
        }
        if ($cli) {
            echo 'meta_due=' . (is_array($due_meta) ? count($due_meta) : 0) . "\n";
        }
        if (!empty($due_meta)) {
            foreach ($due_meta as $row) {
                $set = array(
                    'post_status' => 'publish',
                    'post_modified' => $now_local,
                    'post_modified_gmt' => $now_gmt
                );
                if (!empty($row->scheduled_gmt)) {
                    $set['post_date_gmt'] = $row->scheduled_gmt;
                }
                $tododb->update(
                    "{$tododb->prefix}posts",
                    $set,
                    array('ID' => intval($row->ID))
                );
                if (function_exists('todo_do_action')) {
                    todo_do_action('todo_scheduled_post_published', intval($row->ID));
                }
                if ($cli) {
                    echo 'published_meta=' . intval($row->ID) . "\n";
                }
            }
        } else {
            $sql_local = "SELECT p.ID, pm.meta_value AS scheduled_local
                           FROM {$tododb->prefix}posts p
                           JOIN {$tododb->prefix}postmeta pm
                             ON pm.post_id = p.ID AND pm.meta_key = ?
                           WHERE p.post_status = 'future'
                           ORDER BY pm.meta_value ASC
                           LIMIT 200";
            $due_local = $tododb->get_results($sql_local, ['_scheduled_time']);
            if (function_exists('error_log')) {
                error_log('TodoCMS scheduled local due=' . (is_array($due_local) ? count($due_local) : 0));
            }
            if ($cli) {
                echo 'local_due=' . (is_array($due_local) ? count($due_local) : 0) . "\n";
            }
            if (!empty($due_local)) {
                $tzid = function_exists('get_option') ? get_option('timezone_string', '') : '';
                if (!$tzid) {
                    $tzid = 'Asia/Shanghai';
                }
                $tz = new DateTimeZone($tzid);
                $now_dt = new DateTime('now', $tz);
                foreach ($due_local as $row) {
                    $dt = new DateTime($row->scheduled_local, $tz);
                    if ($dt <= $now_dt) {
                        $dt_gmt = clone $dt;
                        $dt_gmt->setTimezone(new DateTimeZone('UTC'));
                        $post_date_gmt = $dt_gmt->format('Y-m-d H:i:s');
                        $tododb->update(
                            "{$tododb->prefix}posts",
                            array(
                                'post_status' => 'publish',
                                'post_date' => $dt->format('Y-m-d H:i:s'),
                                'post_date_gmt' => $post_date_gmt,
                                'post_modified' => $now_local,
                                'post_modified_gmt' => $now_gmt
                            ),
                            array('ID' => intval($row->ID))
                        );
                        if (function_exists('todo_do_action')) {
                            todo_do_action('todo_scheduled_post_published', intval($row->ID));
                        }
                        if ($cli) {
                            echo 'published_local=' . intval($row->ID) . "\n";
                        }
                    }
                }
            }

            if (empty($due_local)) {
                $sql_gmt_as_local = "SELECT p.ID, pm.meta_value AS scheduled_local
                                       FROM {$tododb->prefix}posts p
                                       JOIN {$tododb->prefix}postmeta pm
                                         ON pm.post_id = p.ID AND pm.meta_key = ?
                                       WHERE p.post_status = 'future'
                                       ORDER BY pm.meta_value ASC
                                       LIMIT 200";
                $due_gmt_as_local = $tododb->get_results($sql_gmt_as_local, ['_scheduled_time_gmt']);
                if ($cli) {
                    echo 'gmt_as_local_due=' . (is_array($due_gmt_as_local) ? count($due_gmt_as_local) : 0) . "\n";
                }
                if (!empty($due_gmt_as_local)) {
                    $tzid = function_exists('get_option') ? get_option('timezone_string', '') : '';
                    if (!$tzid) {
                        $tzid = 'Asia/Shanghai';
                    }
                    $tz = new DateTimeZone($tzid);
                    $now_dt = new DateTime('now', $tz);
                    foreach ($due_gmt_as_local as $row) {
                        $dt = new DateTime($row->scheduled_local, $tz);
                        if ($dt <= $now_dt) {
                            $dt_gmt = clone $dt;
                            $dt_gmt->setTimezone(new DateTimeZone('UTC'));
                            $tododb->update(
                                "{$tododb->prefix}posts",
                                array(
                                    'post_status' => 'publish',
                                    'post_date' => $dt->format('Y-m-d H:i:s'),
                                    'post_date_gmt' => $dt_gmt->format('Y-m-d H:i:s'),
                                    'post_modified' => $now_local,
                                    'post_modified_gmt' => $now_gmt
                                ),
                                array('ID' => intval($row->ID))
                            );
                            if ($cli) {
                                echo 'published_gmt_as_local=' . intval($row->ID) . "\n";
                            }
                            if (function_exists('todo_do_action')) {
                                todo_do_action('todo_scheduled_post_published', intval($row->ID));
                            }
                        }
                    }
                }
            }
        }
    }
}

// 优化定时任务触发机制：避免在前台页面访问时执行，防止阻塞用户体验
if (function_exists('todo_add_action')) {
    // 仅在后台管理界面或AJAX请求时执行定时发布检查
    if (
        is_admin() || (defined('DOING_AJAX') && DOING_AJAX) ||
        (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/admin/') !== false)
    ) {
        todo_add_action('todo_init', 'todo_run_scheduled_posts', 1);
    }

    // 为前台提供轻量级的定时检查（每10分钟最多执行一次）
    // 避免在CLI、安装过程中或显式禁用时添加该钩子，防止递归触发与资源消耗
    $running_cli = (php_sapi_name() === 'cli');
    if (!$running_cli && (!defined('INSTALLING') || !INSTALLING) && !defined('TODO_DISABLE_SCHEDULED_TASKS')) {
        todo_add_action('todo_init', 'todo_maybe_run_scheduled_posts_frontend', 999);
    }
}

/**
 * 前台轻量级定时发布检查
 * 使用时间间隔控制，避免频繁执行影响前台性能
 */
function todo_maybe_run_scheduled_posts_frontend()
{
    // 避免在CLI、安装模式或显式禁用时执行，防止递归和资源占用
    if (php_sapi_name() === 'cli' || (defined('INSTALLING') && INSTALLING) || defined('TODO_DISABLE_SCHEDULED_TASKS')) {
        return;
    }
    // 仅在前台执行
    if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
        return;
    }

    // 检查上次执行时间，10分钟内不重复执行
    $last_check = get_option('todo_frontend_scheduled_check', 0);
    if ($last_check && (time() - $last_check) < 600) { // 600秒 = 10分钟
        return;
    }

    // 延迟执行，避免阻塞页面渲染
    register_shutdown_function(function() {
        // 如果是 PHP-FPM 环境，提前结束请求，加速前端响应
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        
        // 设置执行标记
        update_option('todo_frontend_scheduled_check', time()); // 使用选项存储

        // 异步执行定时发布检查，避免阻塞前台
        if (function_exists('todo_schedule_background_task')) {
            todo_schedule_background_task('todo_run_scheduled_posts');
        } else {
            // 回退方案：使用后台进程执行
            $php_binary = '/opt/homebrew/bin/php';
            if (!file_exists($php_binary)) {
                $php_binary = '/usr/bin/php';
            }

            $script_path = TODO_ROOT . '/core/scripts/scheduled-posts.php';
            if (!file_exists($script_path)) {
                todo_create_scheduled_posts_script($script_path);
            }

            $command = sprintf(
                '%s %s > /dev/null 2>&1 &',
                escapeshellarg($php_binary),
                escapeshellarg($script_path)
            );

            exec($command);
        }
    });
}

/**
 * 创建定时发布后台脚本
 * 
 * @param string $script_path 脚本路径
 */
function todo_create_scheduled_posts_script($script_path)
{
    $script_dir = dirname($script_path);
    if (!is_dir($script_dir)) {
        mkdir($script_dir, 0755, true);
    }

    $script_content = "<?php
/**
 * TodoCMS 定时发布后台脚本
 * 独立运行，避免阻塞前台请求
 */

if (php_sapi_name() !== 'cli') {
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo 'Direct access denied: CLI only.';
    exit;
}

// 加载Composer autoloader

if (function_exists('todo_run_scheduled_posts')) {
    todo_run_scheduled_posts();
} else {
    error_log('TodoCMS: Scheduled posts function not found in background script');
}
";

    file_put_contents($script_path, $script_content);
    chmod($script_path, 0755);
}

/**
 * 批量预加载文章分类缓存
 *
 * @param array|int $post_ids 文章ID或ID数组
 * @param string $taxonomy 分类法名称
 * @return array 成功加载的分类
 */
function update_post_term_cache($post_ids, $taxonomy = 'category')
{
    global $tododb;

    if (empty($post_ids)) {
        return [];
    }

    if (!is_array($post_ids)) {
        $post_ids = preg_split('/[\s,]+/', $post_ids, -1, PREG_SPLIT_NO_EMPTY);
    }

    $post_ids = array_map('intval', $post_ids);
    $post_ids = array_unique(array_filter($post_ids));

    if (empty($post_ids)) {
        return [];
    }

    // 检查哪些尚未缓存
    $ids_to_query = [];
    foreach ($post_ids as $id) {
        if (!_todo_post_term_cache_manager('has', $id, $taxonomy)) {
            $ids_to_query[] = $id;
        }
    }

    if (empty($ids_to_query)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids_to_query), '?'));

    // 获取文章关联的分类完整信息
    $query = "
        SELECT tr.object_id, t.term_id, t.name, t.slug, tt.description, tt.parent, tt.count
        FROM {$tododb->prefix}term_relationships tr
        JOIN {$tododb->prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
        JOIN {$tododb->prefix}terms t ON tt.term_id = t.term_id
        WHERE tr.object_id IN ($placeholders) AND tt.taxonomy = ?
    ";

    $params = $ids_to_query;
    $params[] = $taxonomy;

    $results = $tododb->get_results($query, $params);

    // 初始化缓存防止重复查询
    foreach ($ids_to_query as $id) {
        _todo_post_term_cache_manager('set', $id, $taxonomy, []);
    }

    if ($results) {
        $all_term_ids = [];
        foreach ($results as $row) {
            $post_id = $row->object_id;
            // 移除 object_id 字段，保持与原函数返回结构一致
            unset($row->object_id);

            $current_terms = _todo_post_term_cache_manager('get', $post_id, $taxonomy);
            if (!is_array($current_terms)) {
                $current_terms = [];
            }
            $current_terms[] = $row;
            _todo_post_term_cache_manager('set', $post_id, $taxonomy, $current_terms);
            $all_term_ids[] = $row->term_id;
        }
        
        $all_term_ids = array_unique($all_term_ids);
        if (!empty($all_term_ids)) {
            if (function_exists('update_term_meta_cache')) {
                update_term_meta_cache($all_term_ids);
            }
            if (class_exists('Todo_Model_Manager') && method_exists('Todo_Model_Manager', 'get_category_models_batch')) {
                Todo_Model_Manager::get_category_models_batch($all_term_ids);
            }
        }
    }

    return $results;
}

/**
 * 内部函数：管理文章术语缓存
 *
 * @param string $action 操作类型 (get, set, delete, has, clear)
 * @param int $post_id 文章ID
 * @param string $taxonomy 分类法
 * @param mixed $data 数据
 * @return mixed
 */
function _todo_post_term_cache_manager($action, $post_id = null, $taxonomy = 'category', $data = null)
{
    static $cache = [];

    switch ($action) {
        case 'get':
            return isset($cache[$post_id][$taxonomy]) ? $cache[$post_id][$taxonomy] : false;
        case 'set':
            $cache[$post_id][$taxonomy] = $data;
            break;
        case 'delete':
            if ($post_id === null) {
                unset($cache); // 清空所有
            } elseif ($taxonomy === null) {
                unset($cache[$post_id]); // 清空该文章所有分类法
            } else {
                unset($cache[$post_id][$taxonomy]);
            }
            break;
        case 'has':
            return isset($cache[$post_id][$taxonomy]);
        case 'clear':
            $cache = [];
            break;
    }
}

/**
 * 获取文章分类
 *
 * @param int $post_id 文章ID
 * @return array 分类ID数组
 */
function todo_get_post_categories($post_id)
{
    global $tododb;

    // 性能优化：优先使用ORM缓存
    if (class_exists('Todo_Post_ORM')) {
        $terms = Todo_Post_ORM::get_terms_for_post($post_id, 'category');

        if ($terms !== null) {
            // 转换为数组格式以保持兼容性
            $categories = [];
            foreach ($terms as $t) {
                // 确保转换为对象格式，兼容数组和对象输入
                $term_data = is_array($t) ? $t : (array) $t;
                $categories[] = (object) [
                    'term_id' => isset($term_data['term_id']) ? intval($term_data['term_id']) : 0,
                    'name' => isset($term_data['name']) ? $term_data['name'] : '',
                    'slug' => isset($term_data['slug']) ? $term_data['slug'] : '',
                    'description' => isset($term_data['description']) ? $term_data['description'] : ''
                ];
            }
            return $categories;
        }
    }

    // 检查缓存
    if (_todo_post_term_cache_manager('has', $post_id, 'category')) {
        return _todo_post_term_cache_manager('get', $post_id, 'category');
    }

    // 如果缓存未命中，加载单个（或触发批量加载逻辑，这里简单加载单个）
    // 为了利用缓存机制，我们调用 update_post_term_cache
    update_post_term_cache([$post_id], 'category');

    $categories = _todo_post_term_cache_manager('get', $post_id, 'category');

    return $categories ?: array();
}

/**
 * 设置文章分类
 *
 * @param int $post_id 文章ID
 * @param array $categories 分类ID数组
 * @return bool 是否成功
 */
function todo_set_post_categories($post_id, $categories)
{
    global $tododb;

    // 删除现有分类关系
    delete_post_terms($post_id, 'category');

    if (empty($categories)) {
        // 分类为空时，同步主分类字段为0
        $tododb->update(
            "{$tododb->prefix}posts",
            array('category_id' => 0),
            array('ID' => $post_id)
        );
        return true;
    }

    // 添加新分类关系
    foreach ($categories as $category_id) {
        $term_taxonomy_id = $tododb->get_var("
            SELECT tt.term_taxonomy_id
            FROM {$tododb->prefix}term_taxonomy tt
            WHERE tt.term_id = ? AND tt.taxonomy = 'category'
        ", [$category_id]);

        if ($term_taxonomy_id) {
            $tododb->insert(
                "{$tododb->prefix}term_relationships",
                array(
                    'object_id' => $post_id,
                    'term_taxonomy_id' => $term_taxonomy_id
                )
            );

            // 更新计数
            $tododb->query(
                "UPDATE {$tododb->prefix}term_taxonomy SET count = count + 1 WHERE term_taxonomy_id = ?",
                [$term_taxonomy_id]
            );
        }
    }

    // 同步posts表的主分类字段为首个选择的分类ID
    $primary_category_id = 0;
    if (!empty($categories)) {
        $first = reset($categories);
        $primary_category_id = intval($first);
    }
    $tododb->update(
        "{$tododb->prefix}posts",
        array('category_id' => $primary_category_id),
        array('ID' => $post_id)
    );

    // 清理缓存，确保分类更新即时生效
    if (class_exists('Todo_Unified_Cache_Manager')) {
        Todo_Unified_Cache_Manager::clean_post_cache($post_id);
    }

    return true;
}

/**
 * 获取文章标签
 *
 * @param int $post_id 文章ID
 * @return array 标签对象数组
 */
/**
 * 获取文章标签（新版本使用独立标签系统）
 *
 * @param int $post_id 文章ID
 * @return array 标签对象数组
 */
function todo_get_post_tags($post_id)
{
    // 加载新的标签管理器
    $tags_manager = new CMS_Tags_Manager();

    $tags = $tags_manager->get_post_tags($post_id);

    // 转换为标准对象格式供调用代码使用
    $formatted_tags = [];
    foreach ($tags as $tag) {
        $tag_obj = new stdClass();
        $tag_obj->term_id = $tag['tag_id'];
        $tag_obj->name = $tag['tag_title'];
        $tag_obj->slug = $tag['tag_slug'];
        $tag_obj->description = $tag['tag_desc'] ?? '';
        $formatted_tags[] = $tag_obj;
    }

    return $formatted_tags;
}

/**
 * 设置文章标签（新版本使用独立标签系统）
 *
 * @param int $post_id 文章ID
 * @param string|array $tags 标签名称字符串或数组
 * @return bool 是否成功
 */
function todo_set_post_tags($post_id, $tags)
{
    // 加载新的标签管理器
    $tags_manager = new CMS_Tags_Manager();

    // 使用便捷方法设置文章标签
    return $tags_manager->set_post_tags($post_id, $tags);
}

/**
 * 删除文章的分类或标签关系
 *
 * @param int $post_id 文章ID
 * @param string $taxonomy 分类法名称
 * @return bool 是否成功
 */
function delete_post_terms($post_id, $taxonomy)
{
    global $tododb;

    // 获取要删除的分类法ID
    $results = $tododb->get_results("
             SELECT tr.term_taxonomy_id
             FROM {$tododb->prefix}term_relationships tr
             JOIN {$tododb->prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             WHERE tr.object_id = ? AND tt.taxonomy = ?
         ", [$post_id, $taxonomy]);

    $term_taxonomy_ids = [];
    foreach ($results as $row) {
        $term_taxonomy_ids[] = $row->term_taxonomy_id;
    }

    if (empty($term_taxonomy_ids)) {
        return true;
    }

    // 删除关系
    $placeholders = implode(',', array_fill(0, count($term_taxonomy_ids), '?'));
    $query = "DELETE FROM {$tododb->prefix}term_relationships WHERE object_id = ? AND term_taxonomy_id IN ($placeholders)";
    $params = array_merge([$post_id], $term_taxonomy_ids);
    $tododb->query($query, $params);

    // 更新计数
    foreach ($term_taxonomy_ids as $term_taxonomy_id) {
        $tododb->query(
            "UPDATE {$tododb->prefix}term_taxonomy SET count = count - 1 WHERE term_taxonomy_id = ? AND count > 0",
            [$term_taxonomy_id]
        );
    }

    if (class_exists('Todo_Unified_Cache_Manager')) {
        Todo_Unified_Cache_Manager::clean_post_cache($post_id);
    }
    if (function_exists('todo_do_action')) {
        todo_do_action('todo_post_updated', $post_id);
    }

    return true;
}

/**
 * 获取分类列表
 *
 * @param array $args 查询参数
 * @return array 分类对象数组
 */
function get_categories($args = array())
{
    global $tododb;

    // 默认参数
    $defaults = array(
        'hide_empty' => true,
        'orderby' => 'name',
        'order' => 'ASC',
        'parent' => null,
        'site_id' => null,
        'exclude_trashed' => true
    );

    // 合并参数
    $args = array_merge($defaults, $args);

    $is_admin_context = (defined('IS_ADMIN') && IS_ADMIN);
    if ($args['site_id'] !== null) {
        $current_site_id = (int) $args['site_id'];
    } elseif ($is_admin_context) {
        $current_site_id = (isset($_GET['site']) && is_numeric($_GET['site']) && (int) $_GET['site'] > 0) ? (int) $_GET['site'] : 0;
    } else {
        $current_site_id = (function_exists('todo_current_site_id') ? (int) todo_current_site_id(true) : 0);
    }
    $apply_site_filter = ($current_site_id > 0);

    // 性能优化：添加缓存支持（对于常用查询）
    $cache_key = 'get_categories_' . md5(json_encode($args) . '_site_' . $current_site_id . '_site_filter_' . ($apply_site_filter ? '1' : '0'));
    $ttl = 300; // 5分钟缓存（分类数据变化不频繁）
    
    if (class_exists('Todo_Cache_Manager')) {
        $lang = function_exists('todo_get_current_language') ? todo_get_current_language() : (function_exists('get_current_language') ? get_current_language() : null);
        $cached = Todo_Cache_Manager::get($cache_key, 'categories', $lang);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }
    }

    // 性能优化：快速获取被标记为回收站(trashed)的分类ID
    $trashed_term_ids = [];
    if (!empty($args['exclude_trashed']) && function_exists('todo_db_table_exists') && todo_db_table_exists('term_attributes')) {
        $t_rows = $tododb->get_results(
            "SELECT term_id FROM {$tododb->prefix}term_attributes WHERE attributes LIKE '%\"trashed\":\"1\"%' OR attributes LIKE '%\"trashed\":1%'"
        );
        if ($t_rows && is_array($t_rows)) {
            foreach ($t_rows as $tr) {
                $tr_id = is_object($tr) ? $tr->term_id : $tr['term_id'];
                $trashed_term_ids[] = (int) $tr_id;
            }
        }
    }

    // 确定 active_post_count 的计算逻辑：只有在明确指定 include_active_post_count 时才执行全表关联计算
    $include_active_count = !empty($args['include_active_post_count']);
    $active_count_select = $include_active_count 
        ? "(SELECT COUNT(*) FROM {$tododb->prefix}posts p WHERE p.post_status != 'trash' AND (p.category_id = t.term_id OR EXISTS (SELECT 1 FROM {$tododb->prefix}term_relationships tr JOIN {$tododb->prefix}term_taxonomy tt_rel ON tr.term_taxonomy_id = tt_rel.term_taxonomy_id WHERE tr.object_id = p.ID AND tt_rel.term_id = t.term_id AND tt_rel.taxonomy = 'category'))) as active_post_count"
        : "tt.count as active_post_count";

    // 构建轻量级查询（免全表 JOIN models 与 JSON 全表解析）
    $query = "
        SELECT t.*, tt.*, tm.model_id as bound_model_id,
               {$active_count_select}
        FROM {$tododb->prefix}terms t
        %s
        JOIN {$tododb->prefix}term_taxonomy tt ON t.term_id = tt.term_id
        LEFT JOIN {$tododb->prefix}term_models tm ON t.term_id = tm.term_id
        WHERE tt.taxonomy = 'category'
    ";

    $site_join = '';
    $site_where = '';
    $group_by = '';
    if ($apply_site_filter && function_exists('todo_site_terms_table_exists') && todo_site_terms_table_exists()) {
        $site_terms_table = $tododb->prefix . 'site_terms';
        $sys_def_site = function_exists('todo_get_default_site_id') ? (int)todo_get_default_site_id() : 1;
        $site_join = "LEFT JOIN {$site_terms_table} st ON (t.term_id = st.term_id AND st.site_id = " . intval($current_site_id) . ")";
        $site_where = " AND ((st.term_id IS NOT NULL AND st.is_visible = 1) OR NOT EXISTS (SELECT 1 FROM {$site_terms_table} stx WHERE stx.term_id = t.term_id AND stx.site_id <> 0))";
        $group_by = " GROUP BY t.term_id";
    }
    $query = sprintf($query, $site_join);
    if ($site_where !== '') {
        $query .= $site_where;
    }

    // 处理父分类
    if ($args['parent'] !== null) {
        $query .= " AND tt.parent = " . intval($args['parent']);
    }

    // 处理空分类
    if ($args['hide_empty']) {
        $query .= " AND tt.count > 0";
    }

    // 处理回收站
    if (!empty($trashed_term_ids)) {
        $query .= " AND t.term_id NOT IN (" . implode(',', array_map('intval', $trashed_term_ids)) . ")";
    }

    if (!empty($group_by)) {
        $query .= $group_by;
    }

    // 排序处理
    $orderby_field = 't.name'; // 默认按名称排序
    if ($args['orderby'] === 'term_id') {
        $orderby_field = 't.term_id';
    } elseif ($args['orderby'] === 'count') {
        $orderby_field = 'tt.count';
    }

    if ($args['parent'] !== null) {
        $query .= " ORDER BY " . $orderby_field . " ASC";
    } else {
        $query .= " ORDER BY " . $orderby_field . " " . strtoupper($args['order']);
    }

    // 执行查询
    $categories = $tododb->get_results($query);

    // 内存处理模型名称映射
    if ($categories && is_array($categories)) {
        $models_map = [];
        if (class_exists('CMS_Model')) {
            $all_models = CMS_Model::get_models();
            if (is_array($all_models)) {
                foreach ($all_models as $m) {
                    $m_obj = (object) $m;
                    $models_map[(int) $m_obj->model_id] = $m_obj->model_name;
                }
            }
        }
        foreach ($categories as &$cat) {
            if (is_array($cat)) {
                $cat = (object) $cat;
            }
            $bm_id = (int) ($cat->bound_model_id ?? 0);
            $cat->bound_model_name = ($bm_id > 0 && isset($models_map[$bm_id])) ? $models_map[$bm_id] : '无模型';
        }
        unset($cat);
    }

    // 性能优化：缓存结果
    if (class_exists('Todo_Cache_Manager') && is_array($categories)) {
        $lang = function_exists('todo_get_current_language') ? todo_get_current_language() : (function_exists('get_current_language') ? get_current_language() : null);
        Todo_Cache_Manager::set($cache_key, $categories, $ttl, 'categories', $lang);
    }

    return $categories;
}

/**
 * 获取标签列表
 *
 * @param array $args 查询参数
 * @return array 标签对象数组
 */
function get_tags($args = array())
{
    global $tododb;

    // 默认参数
    $defaults = array(
        'hide_empty' => true,
        'orderby' => 'name',
        'order' => 'ASC',
        'taxonomy' => 'post_tag'
    );

    // 合并参数
    $args = array_merge($defaults, $args);

    // 构建查询
    $query = "
        SELECT t.*, tt.*
        FROM {$tododb->prefix}terms t
        JOIN {$tododb->prefix}term_taxonomy tt ON t.term_id = tt.term_id
        WHERE tt.taxonomy = ?
    ";

    // 处理空标签
    if ($args['hide_empty']) {
        $query .= " AND tt.count > 0";
    }

    // 排序
    $query .= " ORDER BY t.name ASC";

    // 执行查询
    $tags = $tododb->get_results($query, [$args['taxonomy']], 'ARRAY_A');

    return $tags;
}

/**
 * 统计文章数量
 *
 * @param string $type 文章类型
 * @return object 包含各状态文章数量的对象
 */
function count_posts($type = 'post')
{
    global $tododb;

    // 尝试从缓存获取
    $cache_key = 'count_posts_' . $type;
    $cached_counts = Todo_Cache_Manager::get($cache_key, 'posts', 'global');
    if ($cached_counts !== false) {
        return $cached_counts;
    }

    $now_gmt = current_time('mysql', true);

    if ($type === 'any') {
        // 统计各种状态的文章，但publish状态要排除定时发布的
        $query = "SELECT 
            CASE 
                WHEN post_status = 'publish' AND post_date_gmt <= ? THEN 'publish'
                WHEN post_status = 'publish' AND post_date_gmt > ? THEN 'scheduled'
                WHEN post_status = 'future' THEN 'scheduled'
                ELSE post_status 
            END as status_type,
            COUNT(*) as count 
            FROM {$tododb->prefix}posts 
            GROUP BY status_type";
        $params = [$now_gmt, $now_gmt];
    } else {
        $query = "SELECT 
            CASE 
                WHEN post_status = 'publish' AND post_date_gmt <= ? THEN 'publish'
                WHEN post_status = 'publish' AND post_date_gmt > ? THEN 'scheduled'
                WHEN post_status = 'future' THEN 'scheduled'
                ELSE post_status 
            END as status_type,
            COUNT(*) as count 
            FROM {$tododb->prefix}posts 
            WHERE post_type = ? 
            GROUP BY status_type";
        $params = [$now_gmt, $now_gmt, $type];
    }

    $counts = $tododb->get_results($query, $params);

    $result = new stdClass();
    $result->publish = 0;
    $result->draft = 0;
    $result->pending = 0;
    $result->trash = 0;
    $result->scheduled = 0;
    $result->warehouse = 0; // 产品模型“仓库”态

    if ($counts) {
        foreach ($counts as $count) {
            $result->{$count->status_type} = intval($count->count);
        }
    }

    // 追加计算：产品模型的仓库态（sale_status = 'warehouse'），并从publish中剔除
    $warehouse_sql = "SELECT COUNT(*) FROM {$tododb->prefix}posts p
        LEFT JOIN {$tododb->prefix}model_fields_product mfp ON mfp.post_id = p.ID
        WHERE p.post_status = 'publish' AND p.post_date_gmt <= ? AND mfp.sale_status = 'warehouse'";
    $params = array($now_gmt);
    if ($type !== 'any') {
        $warehouse_sql .= " AND p.post_type = ?";
        $params[] = $type;
    }
    $warehouse_count = intval($tododb->get_var($warehouse_sql, $params));

    // 从publish剔除，并设置warehouse数量
    if ($result->publish > 0) {
        $result->publish = max(0, $result->publish - $warehouse_count);
    }
    $result->warehouse = $warehouse_count;

    // 设置缓存，有效期5分钟（因为有定时发布，不能太久，或者在定时任务中清理）
    Todo_Cache_Manager::set($cache_key, $result, 300, 'posts', 'global');

    return $result;
}

/**
 * 清理文章相关缓存（兼容性包装函数）
 * 实际功能已整合到 Todo_Unified_Cache_Manager::clean_post_cache()
 *
 * @param int $post_id 文章ID
 * @return bool 是否成功
 * @deprecated 请直接使用 Todo_Unified_Cache_Manager::clean_post_cache()
 */
if (!function_exists('todo_clean_post_cache')) {
    function todo_clean_post_cache($post_id)
    {
        if (!$post_id) {
            return false;
        }

        // 统一使用缓存管理器
        if (class_exists('Todo_Unified_Cache_Manager')) {
            return Todo_Unified_Cache_Manager::clean_post_cache($post_id);
        }

        // 如果统一缓存管理器不存在，记录警告并返回 false
        if (function_exists('todo_log_warning')) {
            todo_log_warning('Todo_Unified_Cache_Manager not available for cache cleaning', array('post_id' => $post_id));
        }
        return false;
    }
}

/**
 * 调度后台任务
 * 
 * @param string $task_name 任务名称
 * @param array $args 任务参数
 */
if (!function_exists('todo_schedule_background_task')) {
    function todo_schedule_background_task($task_name, $args = [])
    {
        // 目前仅支持定时发布任务的简易实现
        if ($task_name === 'todo_run_scheduled_posts') {
            // 方式2: 使用 cURL 异步调用 HTTP 端点（推荐用于生产环境）
            todo_trigger_scheduled_posts_via_http();
        }
    }
}

/**
 * 获取文章特色图片URL
 *
 * @param int $post_id 文章ID
 * @return string 特色图片URL
 */
function get_post_thumbnail_id($post_id)
{
    global $tododb;
    $query = "SELECT featured_image FROM {$tododb->prefix}posts WHERE ID = ?";
    $post = $tododb->get_row($query, array($post_id));
    return $post ? $post->featured_image : '';
}

/**
 * 设置文章特色图片
 *
 * @param int $post_id 文章ID
 * @param int $thumbnail_id 特色图片ID
 * @return bool 是否成功
 */
function set_post_thumbnail($post_id, $thumbnail_id)
{
    global $tododb;

    // 获取附件URL
    $attachment_url = todo_get_attachment_url($thumbnail_id);
    if (!$attachment_url) {
        return false;
    }

    $now_local = function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s');
    $now_gmt = function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s');

    // 更新posts表的featured_image字段（同时更新 post_modified，确保首页等缓存可被正确失效）
    $sql = "UPDATE {$tododb->prefix}posts SET featured_image = ?, post_modified = ?, post_modified_gmt = ? WHERE ID = ?";
    $result = $tododb->query($sql, [$attachment_url, $now_local, $now_gmt, $post_id]);

    if ($result !== false) {
        if (class_exists('Todo_Unified_Cache_Manager')) {
            Todo_Unified_Cache_Manager::clean_post_cache($post_id);
        }
        todo_do_action('todo_post_updated', $post_id);
    }

    return $result !== false;
}

/**
 * 获取文章特色图片信息
 *
 * @param int $post_id 文章ID
 * @return string|null 特色图片URL或null
 */
function get_post_thumbnail($post_id)
{
    global $tododb;

    $query = "SELECT featured_image FROM {$tododb->prefix}posts WHERE ID = ?";
    $post = $tododb->get_row($query, array($post_id));

    if (!$post || empty($post->featured_image)) {
        return null;
    }

    return $post->featured_image;
}

/**
 * 删除文章特色图片
 *
 * @param int $post_id 文章ID
 * @return bool 是否成功
 */
function delete_post_thumbnail($post_id)
{
    global $tododb;

    $now_local = function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s');
    $now_gmt = function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s');

    $sql = "UPDATE {$tododb->prefix}posts SET featured_image = '', post_modified = ?, post_modified_gmt = ? WHERE ID = ?";
    $result = $tododb->query($sql, [$now_local, $now_gmt, $post_id]);

    if ($result !== false) {
        if (class_exists('Todo_Unified_Cache_Manager')) {
            Todo_Unified_Cache_Manager::clean_post_cache($post_id);
        }
        if (function_exists('todo_do_action')) {
            todo_do_action('todo_post_updated', $post_id);
        }
    }

    return $result !== false;
}

/**
 * 根据别名获取文章
 *
 * @param string $slug 文章别名
 * @param string $post_type 文章类型，默认为'post'
 * @return array|null 文章数据或null
 */
function get_post_by_slug($slug, $post_type = 'post')
{
    global $tododb;

    // 清理别名
    $slug = trim($slug, '/');

    // 准备候选别名（支持去除常见扩展名，如 .html/.htm/.php 等）
    $candidates = array($slug);
    if (preg_match('/\.(html?|php|asp|aspx|jsp)$/i', $slug)) {
        $withoutExt = preg_replace('/\.(html?|php|asp|aspx|jsp)$/i', '', $slug);
        if ($withoutExt !== '' && $withoutExt !== $slug) {
            $candidates[] = $withoutExt;
        }
    }

    $language_code = function_exists('current_locale') ? current_locale() : null;

    // 回退：使用主表 post_name（逐个候选尝试）
    $post = null;
    foreach ($candidates as $s) {
        $post = $tododb->get_row(
            "SELECT * FROM {$tododb->prefix}posts WHERE post_name = %s AND post_type = %s AND post_status = 'publish'",
            $s,
            $post_type
        );
        if ($post) {
            break;
        }
    }

    if (!$post) {
        return null;
    }

    // 回退路径也尝试做覆盖
    if ($language_code && function_exists('todo_get_post_translation')) {
        $translation = todo_get_post_translation($post->ID, $language_code);
        if (is_array($translation)) {
            $translation = (object)$translation;
        }
        if ($translation) {
            if (isset($translation->title) && $translation->title !== '') {
                $post->post_title = $translation->title;
            }
            if (isset($translation->content) && $translation->content !== '') {
                $post->post_content = $translation->content;
            }
            if (isset($translation->excerpt) && $translation->excerpt !== '') {
                $post->post_excerpt = $translation->excerpt;
            }
            if (isset($translation->seo_title) && $translation->seo_title !== '') {
                $post->seo_title = $translation->seo_title;
            }
            if (isset($translation->seo_keywords) && $translation->seo_keywords !== '') {
                $post->seo_keywords = $translation->seo_keywords;
            }
            if (isset($translation->seo_description) && $translation->seo_description !== '') {
                $post->seo_description = $translation->seo_description;
            }
            $post->i18n_applied = $language_code;
        }
    }

    return $post;
}

/**
 * 获取所有注册的文章类型
 *
 * @return array 文章类型数组，包含名称和别名
 */
function get_post_types()
{
    static $post_types_cache = null;
    if ($post_types_cache !== null) {
        return $post_types_cache;
    }

    // 默认文章类型
    $post_types = array(
        array(
            'name' => 'post',
            'slug' => 'post',
            'label' => '文章',
            'labels' => array(
                'name' => '文章',
                'singular_name' => '文章',
                'add_new' => '写文章',
                'add_new_item' => '写文章',
                'edit_item' => '编辑文章',
                'new_item' => '新文章',
                'view_item' => '查看文章',
                'search_items' => '搜索文章',
                'not_found' => '没有找到文章',
                'not_found_in_trash' => '回收站中没有文章',
                'all_items' => '所有文章'
            ),
            'public' => true,
            'has_archive' => true,
            'rewrite' => array('slug' => 'post'),
            'supports' => array('title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments')
        ),

    );

    // 获取自定义文章类型
    $custom_post_types = get_option('custom_post_types', array());

    // 合并默认和自定义文章类型
    $post_types_cache = array_merge($post_types, $custom_post_types);
    return $post_types_cache;
}

/**
 * 获取指定文章类型的对象
 *
 * @param string $post_type 文章类型名称
 * @return object|null 文章类型对象，如果不存在则返回null
 */
function get_post_type_object($post_type)
{
    if (empty($post_type)) {
        return null;
    }

    $post_types = get_post_types();

    foreach ($post_types as $type) {
        if ($type['name'] === $post_type || $type['slug'] === $post_type) {
            // 转换为对象格式
            $obj = new stdClass();
            $obj->name = $type['name'];
            $obj->label = $type['label'];
            $obj->labels = (object) $type['labels'];
            $obj->public = isset($type['public']) ? $type['public'] : true;
            $obj->has_archive = isset($type['has_archive']) ? $type['has_archive'] : false;
            $obj->rewrite = isset($type['rewrite']) ? (object) $type['rewrite'] : null;
            $obj->supports = isset($type['supports']) ? $type['supports'] : array();

            return $obj;
        }
    }

    return null;
}

/**
 * 获取文章的评论数量
 * 
 * @param int $post_id 文章ID，如果为0则使用当前文章
 * @return int 评论数量
 */
function get_comments_number($post_id = 0)
{
    if (empty($post_id)) {
        global $post;
        if (isset($post) && isset($post->ID)) {
            $post_id = $post->ID;
        } else {
            return 0;
        }
    }

    // 性能优化：优先使用 CMS_Comment 的批量缓存
    if (class_exists('CMS_Comment')) {
        return CMS_Comment::get_comment_count(['post_id' => $post_id, 'status' => 'approve']);
    }

    global $tododb;
    // 从posts表中获取comment_count字段
    $query = "SELECT comment_count FROM " . TABLE_PREFIX . "posts WHERE ID = ?";
    $comment_count = $tododb->get_var($query, array($post_id));

    return (int) $comment_count;
}

/**
 * 检查文章是否为置顶文章
 *
 * @param int $post_id 文章ID，如果为空则使用当前文章
 * @return bool 是否为置顶文章
 */
function is_sticky($post_id = null)
{
    global $post;

    if ($post_id === null) {
        if (isset($post) && isset($post->ID)) {
            $post_id = $post->ID;
        } else {
            return false;
        }
    }

    // 获取置顶文章列表
    $sticky_posts = get_option('sticky_posts', array());

    if (!is_array($sticky_posts)) {
        return false;
    }

    return in_array($post_id, $sticky_posts);
}

/**
 * 获取文章发布时间
 *
 * @param string $format 时间格式，默认为时间戳
 * @param int $post_id 文章ID，如果为空则使用当前文章
 * @return string 格式化的时间
 */
function get_the_time($format = 'U', $post_id = null)
{
    global $post;

    if ($post_id === null) {
        if (isset($post) && isset($post->post_date)) {
            $post_date = $post->post_date;
        } else {
            return '';
        }
    } else {
        global $tododb;
        $post_data = $tododb->get_row("SELECT post_date FROM " . TABLE_PREFIX . "posts WHERE ID = ?", array($post_id));
        if (!$post_data) {
            return '';
        }
        $post_date = $post_data->post_date;
    }

    if ($format === 'U') {
        return strtotime($post_date);
    }

    return mysql2date($format, $post_date);
}

/**
 * 获取文章元数据
 *
 * @param int $post_id 文章ID
 * @param string $key 元数据键名，如果为空则返回所有元数据
 * @param bool $single 是否只返回单个值
 * @return mixed 元数据值
 */
/**
 * 批量预加载文章元数据缓存
 *
 * @param array|int $post_ids 文章ID或ID数组
 * @return array 成功加载的元数据
 */
function update_post_meta_cache($post_ids)
{
    global $tododb;

    if (empty($post_ids)) {
        return [];
    }

    if (!is_array($post_ids)) {
        $post_ids = preg_split('/[\s,]+/', $post_ids, -1, PREG_SPLIT_NO_EMPTY);
    }

    $post_ids = array_map('intval', $post_ids);
    $post_ids = array_unique(array_filter($post_ids));

    if (empty($post_ids)) {
        return [];
    }

    // 检查哪些尚未缓存
    $ids_to_query = [];
    foreach ($post_ids as $id) {
        if (!_todo_post_meta_cache_manager('has', $id)) {
            $ids_to_query[] = $id;
        }
    }

    if (empty($ids_to_query)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids_to_query), '?'));
    $query = "SELECT post_id, meta_key, meta_value FROM {$tododb->prefix}postmeta WHERE post_id IN ($placeholders)";

    $results = $tododb->get_results($query, $ids_to_query);

    // 初始化缓存防止重复查询
    foreach ($ids_to_query as $id) {
        _todo_post_meta_cache_manager('set', $id, []);
    }

    $loaded = [];
    if ($results) {
        foreach ($results as $row) {
            $mp_key = $row->meta_key;
            $mp_val = maybe_unserialize($row->meta_value);

            // 获取当前缓存
            $current_meta = _todo_post_meta_cache_manager('get', $row->post_id);
            if (!is_array($current_meta)) {
                $current_meta = [];
            }

            if (isset($current_meta[$mp_key])) {
                $current_meta[$mp_key][] = $mp_val;
            } else {
                $current_meta[$mp_key] = array($mp_val);
            }

            _todo_post_meta_cache_manager('set', $row->post_id, $current_meta);
        }
    }

    return $loaded;
}

/**
 * 内部函数：管理文章元数据缓存
 *
 * @param string $action 操作类型 (get, set, delete, has, clear)
 * @param int $post_id 文章ID
 * @param mixed $data 数据
 * @return mixed
 */
function _todo_post_meta_cache_manager($action, $post_id = null, $data = null)
{
    static $cache = [];

    switch ($action) {
        case 'get':
            return isset($cache[$post_id]) ? $cache[$post_id] : false;
        case 'set':
            $cache[$post_id] = $data;
            break;
        case 'delete':
            unset($cache[$post_id]);
            break;
        case 'has':
            return isset($cache[$post_id]);
        case 'clear':
            $cache = [];
            break;
    }
}

function get_post_meta($post_id, $key = '', $single = false)
{
    global $tododb;

    $post_id = (int) $post_id;

    if (!$post_id) {
        return false;
    }

    // 性能优化：优先使用ORM缓存
    if (class_exists('Todo_Post_ORM')) {
        // 检查ORM是否已加载该Post的meta
        $orm_meta = Todo_Post_ORM::get_meta_for_post($post_id);
        if ($orm_meta !== null) {
            if (!empty($key)) {
                if (isset($orm_meta[$key])) {
                    return $single ? $orm_meta[$key] : $orm_meta[$key];
                }
            } else {
                return $orm_meta;
            }
        }
    }

    // 检查批量预加载器（如果启用收集模式）
    if (class_exists('Todo_Batch_Preloader')) {
        Todo_Batch_Preloader::add($post_id);
    }

    // 检查缓存，如果没有则加载
    if (!_todo_post_meta_cache_manager('has', $post_id)) {
        update_post_meta_cache([$post_id]);
    }

    $meta = _todo_post_meta_cache_manager('get', $post_id);
    if (!is_array($meta)) {
        $meta = [];
    }

    if (!empty($key)) {
        if (isset($meta[$key])) {
            if ($single) {
                return $meta[$key][0];
            } else {
                return $meta[$key];
            }
        } else {
            return $single ? '' : array();
        }
    } else {
        return $meta;
    }
}

/**
 * 获取文章元数据 (TodoCMS 风格别名)
 *
 * @param int $post_id 文章ID
 * @param string $key 元数据键名
 * @param bool $single 是否返回单个值
 * @return mixed 元数据值
 */
function todo_get_post_meta($post_id, $key = '', $single = false)
{
    return get_post_meta($post_id, $key, $single);
}

/**
 * 添加文章元数据
 *
 * @param int $post_id 文章ID
 * @param string $meta_key 元数据键名
 * @param mixed $meta_value 元数据值
 * @param bool $unique 是否唯一，如果为true且键名已存在则不添加
 * @return int|bool 成功返回meta_id，失败返回false
 */
function add_post_meta($post_id, $meta_key, $meta_value, $unique = false)
{
    global $tododb;

    $post_id = (int) $post_id;

    if (!$post_id || empty($meta_key)) {
        return false;
    }

    // 清除缓存
    _todo_post_meta_cache_manager('delete', $post_id);
    if (class_exists('Todo_Unified_Cache_Manager')) {
        Todo_Unified_Cache_Manager::clean_post_cache($post_id);
    }
    if (function_exists('todo_do_action')) {
        todo_do_action('todo_post_updated', $post_id);
    }

    if ($unique) {
        $exists = $tododb->get_var($tododb->prepare("SELECT meta_id FROM {$tododb->prefix}postmeta WHERE post_id = %d AND meta_key = %s", $post_id, $meta_key));
        if ($exists) {
            return false;
        }
    }

    $meta_value = maybe_serialize($meta_value);

    $result = $tododb->insert("{$tododb->prefix}postmeta", array(
        'post_id' => $post_id,
        'meta_key' => $meta_key,
        'meta_value' => $meta_value
    ));

    if ($result) {
        return $result;
    }

    return false;
}

/**
 * 更新文章元数据
 *
 * @param int $post_id 文章ID
 * @param string $meta_key 元数据键名
 * @param mixed $meta_value 元数据值
 * @param mixed $prev_value 旧值，用于区分相同键名的不同值
 * @return bool|int 成功返回true或meta_id，失败返回false
 */
function update_post_meta($post_id, $meta_key, $meta_value, $prev_value = '')
{
    global $tododb;

    $post_id = (int) $post_id;

    if (!$post_id || empty($meta_key)) {
        return false;
    }

    // 清除缓存
    _todo_post_meta_cache_manager('delete', $post_id);
    if (class_exists('Todo_Unified_Cache_Manager')) {
        Todo_Unified_Cache_Manager::clean_post_cache($post_id);
    }
    if (function_exists('todo_do_action')) {
        todo_do_action('todo_post_updated', $post_id);
    }

    // 检查是否已存在
    $meta_ids = $tododb->get_col($tododb->prepare("SELECT meta_id FROM {$tododb->prefix}postmeta WHERE post_id = %d AND meta_key = %s", $post_id, $meta_key));

    if (empty($meta_ids)) {
        return add_post_meta($post_id, $meta_key, $meta_value);
    }

    $meta_value = maybe_serialize($meta_value);
    $prev_value = maybe_serialize($prev_value);

    // 如果只有一个值，或者没有指定prev_value，则更新所有
    if (count($meta_ids) === 1 || empty($prev_value)) {
        $result = $tododb->update(
            "{$tododb->prefix}postmeta",
            array('meta_value' => $meta_value),
            array('post_id' => $post_id, 'meta_key' => $meta_key)
        );
        return $result !== false;
    }

    // 如果有多个值且指定了prev_value，则只更新匹配的
    $result = $tododb->update(
        "{$tododb->prefix}postmeta",
        array('meta_value' => $meta_value),
        array('post_id' => $post_id, 'meta_key' => $meta_key, 'meta_value' => $prev_value)
    );

    return $result !== false;
}

/**
 * 删除文章元数据
 *
 * @param int $post_id 文章ID
 * @param string $meta_key 元数据键名
 * @param mixed $meta_value 元数据值，如果指定则只删除匹配的值
 * @return bool 是否成功删除
 */
function delete_post_meta($post_id, $meta_key, $meta_value = '')
{
    global $tododb;

    $post_id = (int) $post_id;

    if (!$post_id || empty($meta_key)) {
        return false;
    }

    // 清除缓存
    _todo_post_meta_cache_manager('delete', $post_id);
    if (class_exists('Todo_Unified_Cache_Manager')) {
        Todo_Unified_Cache_Manager::clean_post_cache($post_id);
    }
    if (function_exists('todo_do_action')) {
        todo_do_action('todo_post_updated', $post_id);
    }

    $where = array('post_id' => $post_id, 'meta_key' => $meta_key);

    if (!empty($meta_value)) {
        $where['meta_value'] = maybe_serialize($meta_value);
    }

    $result = $tododb->delete("{$tododb->prefix}postmeta", $where);

    return $result !== false;
}

function todo_update_post_meta($post_id, $meta_key, $meta_value, $prev_value = '')
{
    return update_post_meta($post_id, $meta_key, $meta_value, $prev_value);
}

function todo_delete_post_meta($post_id, $meta_key, $meta_value = '')
{
    return delete_post_meta($post_id, $meta_key, $meta_value);
}

/**
 * 获取文章修改日期
 *
 * @param string $format 日期格式，默认为系统设置
 * @param int $post_id 文章ID，如果为空则使用当前文章
 * @return string 格式化的日期
 */
function get_the_modified_date($format = '', $post_id = null)
{
    global $post;

    if (empty($format)) {
        $format = get_option('date_format', 'Y-m-d');
    }

    if ($post_id === null) {
        if (isset($post) && isset($post->post_modified)) {
            $post_modified = $post->post_modified;
        } else {
            return '';
        }
    } else {
        global $tododb;
        $post_data = $tododb->get_row("SELECT post_modified FROM " . TABLE_PREFIX . "posts WHERE ID = ?", array($post_id));
        if (!$post_data) {
            return '';
        }
        $post_modified = $post_data->post_modified;
    }

    return mysql2date($format, $post_modified);
}

/**
 * 显示编辑文章链接
 *
 * @param string $text 链接文本，默认为"编辑"
 * @param string $before 链接前的HTML
 * @param string $after 链接后的HTML
 * @param int $post_id 文章ID，如果为空则使用当前文章
 * @param string $class CSS类名
 */
function edit_post_link($text = '编辑', $before = '', $after = '', $post_id = null, $class = 'post-edit-link')
{
    global $post;

    if ($post_id === null) {
        if (isset($post) && isset($post->ID)) {
            $post_id = $post->ID;
        } else {
            return;
        }
    }

    // 检查用户是否有编辑权限
    if (!current_user_can('edit_posts')) {
        return;
    }

    $edit_url = todo_admin_url('index.php?page=content&action=edit&id=' . $post_id);

    echo $before . '<a class="' . esc_attr($class) . '" href="' . esc_url($edit_url) . '">' . esc_html($text) . '</a>' . $after;
}

/**
 * 检查文章是否有摘要
 *
 * @param int $post_id 文章ID，如果为空则使用当前文章
 * @return bool 如果有摘要返回true，否则返回false
 */
function has_excerpt($post_id = null)
{
    global $post;

    if ($post_id === null) {
        if (isset($post) && isset($post->post_excerpt)) {
            $excerpt = $post->post_excerpt;
        } else {
            return false;
        }
    } else {
        global $tododb;
        $post_data = $tododb->get_row("SELECT post_excerpt FROM " . TABLE_PREFIX . "posts WHERE ID = ?", array($post_id));
        if (!$post_data) {
            return false;
        }
        $excerpt = $post_data->post_excerpt;
    }

    return !empty(trim($excerpt));
}

/**
 * 显示文章分页链接
 *
 * @param array $args 分页参数
 * @return string 分页链接HTML
 */
function todo_link_pages($args = array())
{
    global $post, $page, $numpages, $multipage;

    $defaults = array(
        'before' => '<p>' . __('页面:', 'default'),
        'after' => '</p>',
        'link_before' => '',
        'link_after' => '',
        'next_or_number' => 'number',
        'separator' => ' ',
        'nextpagelink' => __('下一页', 'default'),
        'previouspagelink' => __('上一页', 'default'),
        'pagelink' => '%',
        'echo' => 1
    );

    $args = array_merge($defaults, $args);

    // 如果文章没有分页，直接返回
    if (!isset($multipage) || !$multipage || $numpages < 2) {
        return '';
    }

    $output = $args['before'];

    if ($args['next_or_number'] == 'number') {
        for ($i = 1; $i <= $numpages; $i++) {
            $link = str_replace('%', $i, $args['pagelink']);
            if ($i != $page) {
                $output .= $args['link_before'] . '<a href="' . get_permalink() . ($i > 1 ? '?page=' . $i : '') . '">' . $link . '</a>' . $args['link_after'];
            } else {
                $output .= $args['link_before'] . '<span class="current">' . $link . '</span>' . $args['link_after'];
            }
            if ($i < $numpages) {
                $output .= $args['separator'];
            }
        }
    }

    $output .= $args['after'];

    if ($args['echo']) {
        echo $output;
    }

    return $output;
}

/**
 * 重置查询数据
 *
 * 重置全局查询变量
 */
function todo_reset_query()
{
    global $todo_query, $todo_the_query;

    if (isset($todo_the_query)) {
        $todo_query = $todo_the_query;
        todo_reset_postdata();
    }
}

/**
 * 设置文章数据
 *
 * @param object $post 文章对象
 */
function setup_postdata($post)
{
    global $id, $authordata, $currentday, $currentmonth, $page, $pages, $multipage, $more, $numpages;

    if (!is_object($post)) {
        return;
    }

    $id = isset($post->ID) ? (int) $post->ID : 0;

    $authordata = isset($post->post_author) ? get_userdata($post->post_author) : false;

    $currentday = isset($post->post_date) ? mysql2date('d.m.y', $post->post_date, false) : '';
    $currentmonth = isset($post->post_date) ? mysql2date('m', $post->post_date, false) : '';

    $numpages = 1;
    $multipage = 0;
    $page = get_query_var('page');
    if (!$page) {
        $page = 1;
    }

    // 检查文章是否有分页
    if (isset($post->post_content) && strpos($post->post_content, '<!--nextpage-->') !== false) {
        $content = $post->post_content;
        if ($page > 1) {
            $content = str_replace('\n<!--nextpage-->\n', '<!--nextpage-->', $content);
            $content = str_replace('\n<!--nextpage-->', '<!--nextpage-->', $content);
            $content = str_replace('<!--nextpage-->\n', '<!--nextpage-->', $content);
        }
        $pages = explode('<!--nextpage-->', $content);
        $numpages = count($pages);
        if ($numpages > 1) {
            $multipage = 1;
        }
    } else {
        $pages = array(isset($post->post_content) ? $post->post_content : '');
    }

    $more = 1;
}

/**
 * 获取文章修改时间
 *
 * @param string $format 时间格式，默认为时间戳
 * @param int $post_id 文章ID，如果为空则使用当前文章
 * @return string 格式化的时间
 */
function get_the_modified_time($format = 'U', $post_id = null)
{
    global $post;

    if ($post_id === null) {
        if (isset($post) && isset($post->post_modified)) {
            $post_modified = $post->post_modified;
        } else {
            return '';
        }
    } else {
        global $tododb;
        $post_data = $tododb->get_row("SELECT post_modified FROM " . TABLE_PREFIX . "posts WHERE ID = ?", array($post_id));
        if (!$post_data) {
            return '';
        }
        $post_modified = $post_data->post_modified;
    }

    if ($format === 'U') {
        return strtotime($post_modified);
    }

    return mysql2date($format, $post_modified);
}

/**
 * 获取文章发布日期
 *
 * @param string $format 日期格式，默认为系统设置
 * @param int $post_id 文章ID，如果为空则使用当前文章
 * @return string 格式化的日期
 */
function get_the_date($format = '', $post_id = null)
{
    global $post;

    if (empty($format)) {
        $format = get_option('date_format', 'Y-m-d');
    }

    if ($post_id === null) {
        if (isset($post) && isset($post->post_date)) {
            $post_date = $post->post_date;
        } else {
            return '';
        }
    } else {
        global $tododb;
        $post_data = $tododb->get_row("SELECT post_date FROM " . TABLE_PREFIX . "posts WHERE ID = ?", array($post_id));
        if (!$post_data) {
            return '';
        }
        $post_date = $post_data->post_date;
    }

    return mysql2date($format, $post_date);
}

/**
 * 通过 HTTP 请求触发定时发布任务
 * 这是在 exec() 被禁用时的替代方案
 * 
 * @return bool 是否成功触发
 */
if (!function_exists('todo_trigger_scheduled_posts_via_http')) {
    function todo_trigger_scheduled_posts_via_http()
    {
        // 获取当前站点 URL
        $site_url = defined('SITE_URL') ? SITE_URL : (
            (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') .
            '://' . $_SERVER['HTTP_HOST']
        );
        
        // 定时发布任务的 HTTP 端点
        $endpoint_url = $site_url . '/api/scheduled-posts-task.php';
        
        // 生成一个简单的验证令牌（基于站点密钥）
        $secret_key = defined('TODO_SECRET_KEY') ? TODO_SECRET_KEY : 'todo-default-key';
        $token = hash_hmac('sha256', 'scheduled_posts_' . date('Y-m-d-H'), $secret_key);
        
        // 使用 cURL 发送异步请求（设置极短超时，不等待响应）
        if (function_exists('curl_init')) {
            $ch = curl_init($endpoint_url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['token' => $token]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => 100, // 100ms 超时，不等待完成
                CURLOPT_NOSIGNAL => 1,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);
            @curl_exec($ch);
            curl_close($ch);
            return true;
        }
        
        // 如果 cURL 不可用，尝试使用 file_get_contents（不推荐用于异步）
        if (ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => http_build_query(['token' => $token]),
                    'timeout' => 0.1,
                    'ignore_errors' => true,
                ]
            ]);
            @file_get_contents($endpoint_url, false, $context);
            return true;
        }
        
        // 如果所有方法都不可用，记录错误
        if (function_exists('todo_log_error')) {
            todo_log_error('Unable to trigger scheduled posts: exec, curl, and file_get_contents are all unavailable');
        }
        
        return false;
    }
}
