<?php
/**
 * 管理后台文章列表页面
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 检查用户权限
if (!current_user_can('edit_posts')) {
    todo_die('您没有足够的权限访问此页面。');
}

// 检查权限
$is_super_admin = function_exists('todo_is_super_admin') ? todo_is_super_admin() : false;

// 加载文档属性函数
require_once ABSPATH . 'admin/includes/doc-properties-functions.php';

// 处理批量操作
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['post'])) {
    // 验证CSRF令牌
    if (!Todo_Security_Manager::verify_request_csrf('bulk-posts', '_todononce')) {
        todo_admin_notice('安全验证失败，请重试。', 'error');
    } else {
        $action = $_POST['action'];
        $post_ids = $_POST['post'];

        if ($action !== '-1' && !empty($post_ids) && is_array($post_ids)) {
            $success_count = 0;
            switch ($action) {
                case 'trash':
                    if (current_user_can('delete_posts')) {
                        foreach ($post_ids as $post_id) {
                            if (current_user_can('delete_post', $post_id)) {
                                todo_trash_post($post_id);
                                $success_count++;
                            }
                        }
                        if ($success_count > 0) todo_admin_notice(sprintf('已将 %d 篇文章移至回收站。', $success_count), 'success');
                    }
                    break;

                case 'publish':
                    if (current_user_can('publish_posts')) {
                        foreach ($post_ids as $post_id) {
                            if (current_user_can('publish_post', $post_id)) {
                                todo_publish_post($post_id);
                                $success_count++;
                            }
                        }
                        if ($success_count > 0) todo_admin_notice(sprintf('已发布 %d 篇文章。', $success_count), 'success');
                    }
                    break;

                case 'draft':
                    if (current_user_can('edit_posts')) {
                        foreach ($post_ids as $post_id) {
                            if (current_user_can('edit_post', $post_id)) {
                                $post_data = array(
                                    'ID' => $post_id,
                                    'post_status' => 'draft'
                                );
                                todo_update_post($post_data);
                                $success_count++;
                            }
                        }
                        if ($success_count > 0) todo_admin_notice(sprintf('已将 %d 篇文章设为草稿。', $success_count), 'success');
                    }
                    break;

                case 'change_site':
                    if ($is_super_admin) {
                        global $tododb;
                        // 支持多选：target_site_id[] 为数组；若未提交或为空则视为「全局可见」
                        $raw_site_ids = isset($_POST['target_site_id']) ? (array) $_POST['target_site_id'] : [];
                        $target_site_ids = array_values(array_unique(array_filter(array_map('intval', $raw_site_ids), function ($v) { return $v > 0; })));

                        foreach ($post_ids as $post_id) {
                            // 先清除旧映射
                            $tododb->delete($tododb->prefix . 'site_posts', ['post_id' => $post_id]);
                            if (!empty($target_site_ids)) {
                                // 绑定选中的各站点
                                foreach ($target_site_ids as $sid) {
                                    todo_ensure_post_site_mapping($post_id, ['site_id' => $sid]);
                                }
                            }
                            // 若 $target_site_ids 为空，不写入任何映射 → 文章对所有站点可见（全局）
                            $success_count++;
                        }
                        if ($success_count > 0) {
                            if (empty($target_site_ids)) {
                                todo_admin_notice(sprintf('已将 %d 篇文章设为全局可见（不绑定任何站点）。', $success_count), 'success');
                            } else {
                                todo_admin_notice(sprintf('已将 %d 篇文章归属变更至站点：%s。', $success_count, implode(', ', array_map(function($s){ return 'site'.$s; }, $target_site_ids))), 'success');
                            }
                        }
                    }
                    break;
            }

            // 批量操作完成后重定向，避免重复操作
            $redirect_url = 'index.php?page=posts';
            if (isset($_GET['post_status'])) {
                $redirect_url .= '&post_status=' . urlencode($_GET['post_status']);
            }
            if (isset($_GET['cat']) && $_GET['cat'] > 0) {
                $redirect_url .= '&cat=' . intval($_GET['cat']);
            }
            todo_redirect(todo_admin_url($redirect_url));
            exit;
        }
    }
}

// 处理批量更换分类（AJAX请求）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_change_category') {
    // 验证CSRF令牌
    if (!Todo_Security_Manager::verify_request_csrf('bulk-posts', '_todononce')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '安全验证失败，请重试。']);
        exit;
    }

    $post_ids = array_filter(array_map('intval', explode(',', sanitize_text_field($_POST['post_ids'] ?? ''))));
    $category_ids = array_map('intval', $_POST['category_ids'] ?? []);

    if (empty($post_ids)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '请选择要操作的文章。']);
        exit;
    }

    $success_count = 0;
    $errors = [];

    if (class_exists('Todo_Model_Fields_Manager')) {
        foreach ($post_ids as $post_id) {
            try {
                // 1. 获取当前分类和旧模型
                $old_categories = [];
                if (function_exists('todo_get_post_categories')) {
                    $old_categories = todo_get_post_categories($post_id);
                }
                $old_category_ids = array_map(function($c) { return (int) $c->term_id; }, $old_categories);

                $old_models = [];
                if (class_exists('Todo_Model_Manager')) {
                    foreach ($old_category_ids as $cat_id) {
                        $model = Todo_Model_Manager::get_category_model($cat_id);
                        if ($model && !in_array($model, $old_models, true)) {
                            $old_models[] = $model;
                        }
                    }
                }

                // 2. 设置新分类
                if (function_exists('todo_set_post_categories')) {
                    todo_set_post_categories($post_id, $category_ids);
                }

                // 3. 获取新模型（取第一个新分类对应的模型作为主模型）
                $new_models = [];
                $main_model_key = 'article';
                $main_model_id = 0;
                if (class_exists('Todo_Model_Manager')) {
                    if (!empty($category_ids)) {
                        $main_model_key = Todo_Model_Manager::get_category_model($category_ids[0]) ?: 'article';
                        $main_model_id = Todo_Model_Manager::get_model_id_by_key($main_model_key) ?: 0;
                        foreach ($category_ids as $cat_id) {
                            $model = Todo_Model_Manager::get_category_model($cat_id);
                            if ($model && !in_array($model, $new_models, true)) {
                                $new_models[] = $model;
                            }
                        }
                    }
                }

                // 4. 更新 posts 表的 post_type 和 model_id（与 post-edit 保存逻辑一致）
                global $tododb;
                $tododb->update(
                    $tododb->prefix . 'posts',
                    array(
                        'post_type' => $main_model_key,
                        'model_id' => $main_model_id,
                    ),
                    array('ID' => $post_id)
                );

                // 5. 执行模型字段迁移
                if (!empty($old_models) && !empty($new_models)) {
                    $model_fields_manager = new Todo_Model_Fields_Manager();
                    foreach ($old_models as $from_model) {
                        foreach ($new_models as $to_model) {
                            if ($from_model !== $to_model) {
                                $model_fields_manager->migrate_model_fields($from_model, $to_model, $post_id, []);
                            }
                        }
                    }
                }

                $success_count++;
            } catch (Exception $e) {
                $errors[] = "文章 ID {$post_id}: " . $e->getMessage();
            }
        }
    } else {
        // 降级：只更新分类，不迁移模型字段
        foreach ($post_ids as $post_id) {
            if (function_exists('todo_set_post_categories')) {
                todo_set_post_categories($post_id, $category_ids);
            }
            $success_count++;
        }
    }

    $message = "成功更新 {$success_count} 篇文章的分类归属。";
    if (!empty($errors)) {
        $message .= ' 部分文章处理出错：' . implode('; ', $errors);
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => $success_count > 0, 'message' => $message]);
    exit;
}

// 处理单个文章操作（GET请求，带Nonce验证）
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $post_id = intval($_POST['post'] ?? 0);
    $nonce = (string) ($_POST['_todononce'] ?? '');

    if ($action !== '' && $post_id > 0 && $nonce !== '' && todo_verify_nonce($nonce, $action . '-post-' . $post_id)) {
        switch ($action) {
            case 'trash':
                if (current_user_can('delete_posts') && current_user_can('delete_post', $post_id)) {
                    todo_trash_post($post_id);
                    todo_admin_notice('文章已移至回收站。', 'success');
                    todo_redirect(todo_admin_url('index.php?page=posts'));
                    exit;
                }
                break;
        }
    }
}

// 获取当前视图
$post_status = isset($_GET['post_status']) ? $_GET['post_status'] : 'all';
if ($post_status === 'all') {
    // ALL 视图需包含定时发布（future 或 publish+未来时间）以及产品模型的仓库态
    $post_status = array('publish', 'draft', 'pending', 'future', 'warehouse');
} elseif ($post_status === 'scheduled') {
    // 定时文章：状态为 publish 且发布时间在未来，或明确标记为 future
    $post_status = 'scheduled';
} elseif ($post_status === 'trash') {
    // 强制跳转到回收站页面
    todo_redirect(todo_admin_url('index.php?page=trash'));
    exit;
}

// 获取分页参数
$paged = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
$per_page = 20;

// 获取搜索参数
$s = isset($_GET['s']) ? $_GET['s'] : '';

// 获取排序参数
$orderby = isset($_GET['orderby']) ? $_GET['orderby'] : 'date';
$order = isset($_GET['order']) ? $_GET['order'] : 'DESC';

// 获取分类筛选
$cat = isset($_GET['cat']) ? intval($_GET['cat']) : 0;

// 获取标签筛选
$tag_id = isset($_GET['tag_id']) ? intval($_GET['tag_id']) : 0;

// 获取文档属性筛选
$doc_properties_filter = isset($_GET['doc_properties']) ? trim((string)$_GET['doc_properties']) : '';

// 获取可见性筛选（公开/读/密/私）
$filter_visibility = isset($_GET['visibility']) ? trim($_GET['visibility']) : '';
if (!in_array($filter_visibility, ['public', 'readable', 'password', 'private', 'non_public'], true)) {
    $filter_visibility = '';
}

// 获取站点筛选（仅限超级管理员）
$filter_site_id = isset($_GET['site_id']) ? intval($_GET['site_id']) : 0;
// 如果不是超管，强制清空
if (!$is_super_admin) {
    $filter_site_id = 0;
}
$all_sites = [];
if ($is_super_admin) {
    global $tododb;
    if ($tododb->table_exists('sites')) {
        $all_sites = $tododb->get_results("SELECT id, name FROM {$tododb->prefix}sites ORDER BY id ASC");
    }
}

// 缺失翻译筛选参数
global $tododb;
$missing_lang = isset($_GET['missing_lang']) ? trim($_GET['missing_lang']) : '';
$missing_only = isset($_GET['missing']) ? intval($_GET['missing']) : 0;

// 获取可用语言（用于过滤下拉，包含层级结构）
$active_languages_hierarchical = [];
if (class_exists('LanguageRegistry')) {
    $active_languages_hierarchical = LanguageRegistry::get_instance()->get_languages_hierarchical(true);
}

// 获取文章
$args = array(
    'post_type' => 'any', // 显示所有类型的文章
    'post_status' => $post_status,
    'posts_per_page' => $per_page,
    'paged' => $paged,
    'orderby' => $orderby,
    'order' => $order,
    's' => $s
);

if ($cat > 0) {
    $args['cat'] = $cat;
}

if ($tag_id > 0) {
    $args['tag_id'] = $tag_id;
}

if (!empty($doc_properties_filter)) {
    $args['doc_properties'] = $doc_properties_filter;
}

if (!empty($filter_visibility)) {
    if ($filter_visibility === 'non_public') {
        $args['visibility'] = array('readable', 'password', 'private');
    } else {
        $args['visibility'] = $filter_visibility;
    }
}

if ($filter_site_id > 0) {
    $args['site_id'] = $filter_site_id;
}

// 获取文章总数（全局统计，用于状态切换栏）
$total_posts = count_posts('any');

// 统一处理语言筛选逻辑（包含“仅未翻译”与“语言启用”逻辑）
if (!empty($missing_lang) || !empty($missing_only)) {
    global $tododb;
    $now_gmt = current_time('mysql', true);

    // 基础表
    $posts_table = $tododb->prefix . 'posts';
    $translations_table = $tododb->prefix . 'post_translations';
    $postmeta_table = $tododb->prefix . 'postmeta';
    $term_rel_table = $tododb->prefix . 'term_relationships';
    $term_tax_table = $tododb->prefix . 'term_taxonomy';
    $model_fields_product = $tododb->prefix . 'model_fields_product';
    $users_table = $tododb->prefix . 'users';
    $terms_relationships_table = $tododb->prefix . 'term_relationships';
    $terms_taxonomy_table = $tododb->prefix . 'term_taxonomy';
    $terms_table = $tododb->prefix . 'terms';
    $joins = "";
    $join_params = array();
    $where_params = array();
    $wheres = array();

    $default_lang = function_exists('get_default_language') ? get_default_language() : 'zh-CN';
    $canonical_default = function_exists('canonical_locale') ? canonical_locale($default_lang) : str_replace('_', '-', $default_lang);

    if (!empty($missing_lang)) {
        $canonical_missing = function_exists('canonical_locale') ? canonical_locale($missing_lang) : str_replace('_', '-', $missing_lang);
        $is_default_lang = ($canonical_missing === $canonical_default);
        $loc_hyphen = str_replace('_', '-', $missing_lang);
        $loc_underscore = str_replace('-', '_', $missing_lang);
        $loc_variants = array_unique(array_filter([$missing_lang, $canonical_missing, $loc_hyphen, $loc_underscore]));

        if ($is_default_lang) {
            // 默认语言为主稿
            if (intval($missing_only) === 1) {
                // 默认语言为主稿本身（非外部翻译版本），若筛选“仅未翻译”则无未翻译版本
                $wheres[] = "1 = 0";
            } else {
                // 默认语言：所有文章默认均具有主语言版本（未单独设置或设置中包含主语言）
                $joins .= " LEFT JOIN " . $postmeta_table . " pm_lang ON pm_lang.post_id = p.ID AND pm_lang.meta_key = '_enabled_languages'";
                $lang_match_wheres = array();
                foreach ($loc_variants as $loc_var) {
                    $lang_match_wheres[] = "pm_lang.meta_value LIKE ?";
                    $where_params[] = '%' . $tododb->escape_like('"' . $loc_var . '"') . '%';
                }
                $wheres[] = "(pm_lang.meta_value IS NULL OR pm_lang.meta_value = '' OR " . implode(" OR ", $lang_match_wheres) . ")";
            }
        } else {
            // 非默认语言：必须在 _enabled_languages 中显式启用该语言，未启用的文章绝不显示
            $joins .= " INNER JOIN " . $postmeta_table . " pm_lang ON pm_lang.post_id = p.ID AND pm_lang.meta_key = '_enabled_languages'";
            $lang_match_wheres = array();
            foreach ($loc_variants as $loc_var) {
                $lang_match_wheres[] = "pm_lang.meta_value LIKE ?";
                $where_params[] = '%' . $tododb->escape_like('"' . $loc_var . '"') . '%';
            }
            $wheres[] = "(" . implode(" OR ", $lang_match_wheres) . ")";

            if (intval($missing_only) === 1) {
                // 在已启用该语言的基础上，查找该语言翻译尚未 approved 的文章
                $pt_conds = array();
                foreach ($loc_variants as $loc_var) {
                    $pt_conds[] = "pt.language_code = ?";
                    $join_params[] = $loc_var;
                }
                $joins .= " LEFT JOIN " . $translations_table . " pt ON pt.post_id = p.ID AND (" . implode(" OR ", $pt_conds) . ")";
                $wheres[] = "(pt.post_id IS NULL OR pt.status <> 'approved')";
            }
        }
    } else {
        // 未指定具体语言，但勾选了“仅未翻译”：查找已启用其他语言但存在未 approved 翻译的文章
        $active_other_langs = array();
        if (!empty($active_languages_hierarchical)) {
            foreach ($active_languages_hierarchical as $loc => $info) {
                $canon = function_exists('canonical_locale') ? canonical_locale($loc) : str_replace('_', '-', $loc);
                if ($canon !== $canonical_default) {
                    $active_other_langs[$canon] = $loc;
                }
            }
        }

        if (empty($active_other_langs)) {
            $wheres[] = "1 = 0";
        } else {
            $joins .= " INNER JOIN " . $postmeta_table . " pm_lang ON pm_lang.post_id = p.ID AND pm_lang.meta_key = '_enabled_languages'";
            $unapproved_lang_conditions = array();
            foreach ($active_other_langs as $canon_loc => $orig_loc) {
                $loc_h = str_replace('_', '-', $orig_loc);
                $loc_u = str_replace('-', '_', $orig_loc);
                $loc_vars = array_unique(array_filter([$orig_loc, $canon_loc, $loc_h, $loc_u]));

                $enabled_check = array();
                foreach ($loc_vars as $lv) {
                    $enabled_check[] = "pm_lang.meta_value LIKE ?";
                    $where_params[] = '%' . $tododb->escape_like('"' . $lv . '"') . '%';
                }
                $enabled_sql = "(" . implode(" OR ", $enabled_check) . ")";

                $pt_lang_matches = array();
                foreach ($loc_vars as $lv) {
                    $pt_lang_matches[] = "pt_chk.language_code = ?";
                    $where_params[] = $lv;
                }
                $pt_sql = "NOT EXISTS (SELECT 1 FROM {$translations_table} pt_chk WHERE pt_chk.post_id = p.ID AND (" . implode(" OR ", $pt_lang_matches) . ") AND pt_chk.status = 'approved')";

                $unapproved_lang_conditions[] = "({$enabled_sql} AND {$pt_sql})";
            }

            if (!empty($unapproved_lang_conditions)) {
                $wheres[] = "(" . implode(" OR ", $unapproved_lang_conditions) . ")";
            } else {
                $wheres[] = "1 = 0";
            }
        }
    }

    // 分类过滤
    if (!empty($cat) && intval($cat) > 0) {
        $joins .= " INNER JOIN {$term_rel_table} tr ON tr.object_id = p.ID";
        $joins .= " INNER JOIN {$term_tax_table} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category' AND tt.term_id = ?";
        $join_params[] = intval($cat);
    }

    // 标签过滤
    if ($tag_id > 0) {
        $joins .= " INNER JOIN {$tododb->prefix}post_tags pt_filter ON pt_filter.post_id = p.ID";
        $wheres[] = "pt_filter.tag_id = ?";
        $where_params[] = $tag_id;
    }

    // 文档属性过滤
    if (!empty($doc_properties_filter)) {
        $dp_raw = explode(',', $doc_properties_filter);
        $dp_ids = array_values(array_filter(array_map('intval', $dp_raw), function($v) { return $v > 0; }));
        if (!empty($dp_ids)) {
            $dp_sub_wheres = [];
            foreach ($dp_ids as $dpi) {
                $dp_sub_wheres[] = "FIND_IN_SET('{$dpi}', p.doc_properties) > 0";
            }
            $wheres[] = "(" . implode(" OR ", $dp_sub_wheres) . ")";
        }
    }

    // 可见性过滤
    if (!empty($filter_visibility)) {
        if ($filter_visibility === 'non_public') {
            $wheres[] = "p.visibility IN ('readable', 'password', 'private')";
        } else {
            $wheres[] = "p.visibility = ?";
            $where_params[] = $filter_visibility;
        }
    }

    $site_scope_id = 0;
    if ($is_super_admin) {
        $site_scope_id = $filter_site_id > 0 ? $filter_site_id : 0;
    } else {
        $site_scope_id = function_exists('todo_current_site_id') ? (int) todo_current_site_id(false) : 0;
    }

    $status_col = 'p.post_status';
    if ($site_scope_id > 0 && function_exists('todo_site_posts_table_exists') && todo_site_posts_table_exists()) {
        $site_posts_table = $tododb->prefix . 'site_posts';
        $joins .= " INNER JOIN {$site_posts_table} sp ON (p.ID = sp.post_id AND sp.site_id = ? AND sp.is_visible = 1)";
        $join_params[] = $site_scope_id;
        $status_col = 'sp.status';
    }

    // 搜索过滤（优先匹配ID，搜索ID、标题、作者、标签，不搜索内容）
    if (!empty($s)) {
        // 添加作者和标签的JOIN
        if (strpos($joins, 'todo_users') === false) {
            $joins .= " LEFT JOIN {$users_table} u ON p.post_author = u.ID";
        }
        if (strpos($joins, 'tr_search') === false) {
            $joins .= " LEFT JOIN {$terms_relationships_table} tr_search ON p.ID = tr_search.object_id";
            $joins .= " LEFT JOIN {$terms_taxonomy_table} tt_search ON tr_search.term_taxonomy_id = tt_search.term_taxonomy_id";
            $joins .= " LEFT JOIN {$terms_table} t_search ON tt_search.term_id = t_search.term_id";
        }

        // 如果搜索内容是纯数字，优先匹配 ID
        if (is_numeric($s)) {
            $wheres[] = "(p.ID = ? OR p.post_title LIKE ? OR u.user_login LIKE ? OR u.display_name LIKE ? OR (tt_search.taxonomy = 'post_tag' AND t_search.name LIKE ?))";
            $where_params[] = intval($s); // ID 精确匹配
            $like = '%' . $tododb->escape_like($s) . '%';
            $where_params[] = $like; // 标题
            $where_params[] = $like; // 用户名
            $where_params[] = $like; // 显示名
            $where_params[] = $like; // 标签名
        } else {
            $like = '%' . $tododb->escape_like($s) . '%';
            $wheres[] = "(p.post_title LIKE ? OR u.user_login LIKE ? OR u.display_name LIKE ? OR (tt_search.taxonomy = 'post_tag' AND t_search.name LIKE ?))";
            $where_params[] = $like; // 标题
            $where_params[] = $like; // 用户名
            $where_params[] = $like; // 显示名
            $where_params[] = $like; // 标签名
        }
    }

    // 状态过滤
    if (is_array($post_status)) {
        $status_wheres = array();
        foreach ($post_status as $status) {
            if ($status === 'warehouse') {
                if (strpos($joins, 'mfp') === false) {
                    $joins .= " LEFT JOIN {$model_fields_product} mfp ON mfp.post_id = p.ID";
                }
                $status_wheres[] = "({$status_col} = 'publish' AND p.post_date_gmt <= ? AND mfp.sale_status = 'warehouse')";
                $where_params[] = $now_gmt;
            } elseif ($status === 'publish') {
                if (strpos($joins, 'mfp') === false) {
                    $joins .= " LEFT JOIN {$model_fields_product} mfp ON mfp.post_id = p.ID";
                }
                // 已发布视图：排除仓库中的产品
                $status_wheres[] = "({$status_col} = 'publish' AND p.post_date_gmt <= ? AND (mfp.sale_status IS NULL OR mfp.sale_status <> 'warehouse'))";
                $where_params[] = $now_gmt;
            } elseif ($status === 'scheduled') {
                $status_wheres[] = "(({$status_col} = 'publish' AND p.post_date_gmt > ?) OR {$status_col} = 'future')";
                $where_params[] = $now_gmt;
            } else {
                $status_wheres[] = "{$status_col} = ?";
                $where_params[] = $status;
            }
        }
        if (!empty($status_wheres)) {
            $wheres[] = "(" . implode(' OR ', $status_wheres) . ")";
        }
    } elseif ($post_status === 'publish') {
        $wheres[] = "({$status_col} = 'publish' AND p.post_date_gmt <= ?)";
        $where_params[] = $now_gmt;
    } elseif ($post_status === 'draft') {
        $wheres[] = "{$status_col} = 'draft'";
    } elseif ($post_status === 'pending') {
        $wheres[] = "{$status_col} = 'pending'";
    } elseif ($post_status === 'scheduled') {
        $wheres[] = "(({$status_col} = 'publish' AND p.post_date_gmt > ?) OR {$status_col} = 'future')";
        $where_params[] = $now_gmt;
    } elseif ($post_status === 'warehouse') {
        $joins .= " LEFT JOIN {$model_fields_product} mfp ON mfp.post_id = p.ID";
        $wheres[] = "({$status_col} = 'publish' AND p.post_date_gmt <= ? AND mfp.sale_status = 'warehouse')";
        $where_params[] = $now_gmt;
    } else {
        $wheres[] = "{$status_col} = ?";
        $where_params[] = $post_status;
    }


    // 合并参数：JOIN 参数在前，WHERE 参数在后
    $params = array_merge($join_params, $where_params);

    // 排序
    $orderby_map = array(
        'date' => 'p.post_date',
        'title' => 'p.post_title',
        'author' => 'p.post_author',
        'comment_count' => 'p.comment_count'
    );
    $orderby_col = isset($orderby_map[$orderby]) ? $orderby_map[$orderby] : 'p.post_date';
    $order_dir = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

    // 拼装 SQL
    $where_sql = count($wheres) ? (' WHERE ' . implode(' AND ', $wheres)) : '';
    // 确保 SELECT 包含 sale_status 以便列表渲染时显示状态
    $select_cols = "p.*";
    if (strpos($joins, 'mfp') !== false) {
        $select_cols .= ", mfp.sale_status";
    }
    
    $count_sql = "SELECT COUNT(DISTINCT p.ID) FROM {$posts_table} p{$joins}{$where_sql}";
    $missing_total_count = intval($tododb->get_var($count_sql, $params));

    $offset = max(0, ($paged - 1) * $per_page);

    // 如果搜索的是数字（ID），让精确匹配的结果排在最前面
    if (!empty($s) && is_numeric($s)) {
        $search_id = intval($s);
        $list_sql = "SELECT DISTINCT {$select_cols} FROM {$posts_table} p{$joins}{$where_sql} ORDER BY (p.ID = {$search_id}) DESC, {$orderby_col} {$order_dir} LIMIT {$offset}, {$per_page}";
    } else {
        $list_sql = "SELECT DISTINCT {$select_cols} FROM {$posts_table} p{$joins}{$where_sql} ORDER BY {$orderby_col} {$order_dir} LIMIT {$offset}, {$per_page}";
    }

    $posts = $tododb->get_results($list_sql, $params);

    // 填充全局缓存，避免后续 get_post() 触发重复查询
    if (!empty($posts)) {
        if (!isset($GLOBALS['todo_internal_post_cache'])) {
            $GLOBALS['todo_internal_post_cache'] = [];
        }
        foreach ($posts as $post) {
            if (is_object($post) && isset($post->ID)) {
                $GLOBALS['todo_internal_post_cache'][$post->ID] = $post;
            }
        }
    }

    // 更新总数用于分页
    $current_total = intval($missing_total_count);

    // 性能优化：批量预加载文章meta和terms（避免后续N+1查询）
    if (!empty($posts) && is_array($posts)) {
        $post_ids = array();
        foreach ($posts as $post) {
            if (is_object($post) && isset($post->ID)) {
                $post_ids[] = (int) $post->ID;
            } elseif (is_array($post) && isset($post['ID'])) {
                $post_ids[] = (int) $post['ID'];
            }
        }
        if (!empty($post_ids) && class_exists('Todo_Post_ORM')) {
            // 使用 eager_load_all 确保数据已预加载（如果未加载则会加载，已加载则跳过）
            Todo_Post_ORM::eager_load_all($post_ids, [
                'meta' => true,
                'terms' => true,
                'multilang' => true,
                'tags' => true,
                'categories' => true,
                'authors' => true,
                'comments' => true
            ]);
        }
    }
} else {
    // 非语言筛选视图，沿用原有获取逻辑
    // 性能优化：禁用 get_posts 内部的 N+1 加载，改为后续使用 Todo_Post_ORM 批量加载
    $args['load_meta'] = false;
    $args['load_terms'] = false;
    $posts = get_posts($args);

    // 性能优化：确保批量预加载已执行（get_posts 内部可能未完全执行）
    if (!empty($posts) && is_array($posts)) {
        $post_ids = array();
        foreach ($posts as $post) {
            if (is_object($post) && isset($post->ID)) {
                $post_ids[] = (int) $post->ID;
            } elseif (is_array($post) && isset($post['ID'])) {
                $post_ids[] = (int) $post['ID'];
            }
        }
        if (!empty($post_ids) && class_exists('Todo_Post_ORM')) {
            // 使用 eager_load_all 确保数据已预加载（如果未加载则会加载，已加载则跳过）
            Todo_Post_ORM::eager_load_all($post_ids, [
                'meta' => true,
                'terms' => true,
                'multilang' => true,
                'tags' => true,
                'categories' => true,
                'authors' => true,
                'comments' => true
            ]);
        }
    }

    // 计算当前筛选条件下的总数（考虑搜索、分类、标签、文档属性、站点、可见性、状态）
    if (!empty($s) || $cat > 0 || $tag_id > 0 || !empty($doc_properties_filter) || $filter_site_id > 0 || !empty($filter_visibility)) {
        // 如果有搜索、分类、标签、文档属性、站点或可见性筛选，需要重新查询总数
        $count_args = array(
            'post_type' => 'any',
            'post_status' => $post_status,
            'posts_per_page' => -1, // 获取所有
            'fields' => 'ids', // 只获取ID，提高性能
            's' => $s
        );
        if ($cat > 0) {
            $count_args['cat'] = $cat;
        }
        if ($tag_id > 0) {
            $count_args['tag_id'] = $tag_id;
        }
        if (!empty($doc_properties_filter)) {
            $count_args['doc_properties'] = $doc_properties_filter;
        }
        if ($filter_site_id > 0) {
            $count_args['site_id'] = $filter_site_id;
        }
        if (!empty($filter_visibility)) {
            if ($filter_visibility === 'non_public') {
                $count_args['visibility'] = array('readable', 'password', 'private');
            } else {
                $count_args['visibility'] = $filter_visibility;
            }
        }
        $all_posts = get_posts($count_args);
        $current_total = is_array($all_posts) ? count($all_posts) : 0;
    } else {
        // 没有搜索和分类筛选，使用缓存的总计数
        $current_total = 0;
        if ($post_status === 'all' || (is_array($post_status) && in_array('publish', $post_status))) {
            $current_total = $total_posts->publish + $total_posts->draft + $total_posts->pending + $total_posts->scheduled + $total_posts->warehouse;
        } elseif ($post_status === 'publish') {
            $current_total = $total_posts->publish;
        } elseif ($post_status === 'draft') {
            $current_total = $total_posts->draft;
        } elseif ($post_status === 'pending') {
            $current_total = $total_posts->pending;
        } elseif ($post_status === 'scheduled') {
            $current_total = $total_posts->scheduled;
        } elseif ($post_status === 'warehouse') {
            $current_total = $total_posts->warehouse;
        }
    }
}

// 计算分页
$total_pages = ceil($current_total / $per_page);

$__todo_show_site_badge = false;
$__todo_post_site_badges = [];
if (isset($tododb) && is_object($tododb)) {
    $is_admin_for_site_badge = false;
    $uid_for_site_badge = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    if ($uid_for_site_badge > 0 && class_exists('Todo_Session_Manager') && method_exists('Todo_Session_Manager', 'can_access_admin')) {
        $is_admin_for_site_badge = (bool) Todo_Session_Manager::can_access_admin($uid_for_site_badge);
    }
    if (!$is_admin_for_site_badge && function_exists('todo_get_current_user')) {
        $u = todo_get_current_user();
        $roles_raw = [];
        if (is_array($u)) {
            if (isset($u['roles'])) {
                $roles_raw = $u['roles'];
            } elseif (isset($u['meta']) && is_array($u['meta']) && isset($u['meta']['roles'])) {
                $roles_raw = $u['meta']['roles'];
            } elseif (isset($u['role'])) {
                $roles_raw = $u['role'];
            }
        } elseif (is_object($u)) {
            if (isset($u->roles)) {
                $roles_raw = $u->roles;
            } elseif (isset($u->meta) && is_array($u->meta) && isset($u->meta['roles'])) {
                $roles_raw = $u->meta['roles'];
            } elseif (isset($u->role)) {
                $roles_raw = $u->role;
            }
        }
        $roles = is_array($roles_raw) ? $roles_raw : ($roles_raw ? [$roles_raw] : []);
        $roles = array_map(function ($r) {
            return strtolower(trim((string) $r));
        }, $roles);
        $is_admin_for_site_badge = !empty(array_intersect(['super_admin', 'superadmin', 'admin', 'administrator'], $roles));
    }
    if (!$is_admin_for_site_badge && function_exists('todo_is_super_admin') && todo_is_super_admin()) {
        $is_admin_for_site_badge = true;
    }
    if (!$is_admin_for_site_badge && function_exists('current_user_can') && current_user_can('manage_options')) {
        $is_admin_for_site_badge = true;
    }

    $is_multisite_instance = false;
    $site_names_map = [];
    if ($is_admin_for_site_badge && $tododb->table_exists('sites')) {
        $sites_table = $tododb->prefix . 'sites';
        $all_sites_rows = $tododb->get_results("SELECT id, name, slug FROM {$sites_table} ORDER BY id ASC");
        foreach ((array)$all_sites_rows as $s_row) {
            $site_names_map[(int)$s_row->id] = $s_row->name;
        }
        $is_multisite_instance = (count($site_names_map) > 1);
    }

    $__todo_show_site_badge = ($is_multisite_instance && $is_admin_for_site_badge);
    // 在全局数据作用域（site参数为空）下，只要表存在且是管理员，就强制显示标识以便区分
    if (!$__todo_show_site_badge && $is_admin_for_site_badge && empty($_GET['site']) && $tododb->table_exists('sites')) {
        $__todo_show_site_badge = true;
    }

    if ($__todo_show_site_badge && !empty($posts) && $tododb->table_exists('site_posts')) {
        $post_ids = [];
        foreach ($posts as $p) {
            if (is_numeric($p)) {
                $post_ids[] = (int) $p;
            } elseif (is_object($p) && isset($p->ID)) {
                $post_ids[] = (int) $p->ID;
            } elseif (is_array($p) && isset($p['ID'])) {
                $post_ids[] = (int) $p['ID'];
            }
        }
        $post_ids = array_values(array_unique(array_filter($post_ids, function ($v) {
            return (int) $v > 0;
        })));

        if (!empty($post_ids)) {
            $placeholders = implode(',', array_fill(0, count($post_ids), '?'));
            $site_posts_table = $tododb->prefix . 'site_posts';
            $rows = $tododb->get_results(
                "SELECT post_id, GROUP_CONCAT(DISTINCT site_id ORDER BY site_id ASC SEPARATOR ',') AS site_ids
                 FROM {$site_posts_table}
                 WHERE post_id IN ({$placeholders})
                 GROUP BY post_id",
                $post_ids
            );
            foreach ((array) $rows as $r) {
                if (!is_object($r) || !isset($r->post_id) || !isset($r->site_ids)) {
                    continue;
                }
                $ids = array_values(array_filter(array_map('intval', explode(',', (string) $r->site_ids)), function ($v) {
                    return (int) $v >= 0;
                }));
                if (empty($ids)) {
                    continue;
                }
                $labels = array_map(function ($sid, $index) {
                    if ($index === 0) {
                        return ($sid == 0) ? 'global' : 'site' . (int)$sid;
                    }
                    return (int)$sid;
                }, $ids, array_keys($ids));
                $__todo_post_site_badges[(int) $r->post_id] = implode(',', $labels);
            }
        }
    }
}

// 批量查询文章分类关联的站点（避免 N+1）
$__todo_category_site_badges = [];
if ($__todo_show_site_badge && !empty($posts) && isset($tododb) && is_object($tododb) && $tododb->table_exists('site_terms')) {
    $cat_post_ids = [];
    foreach ($posts as $p) {
        if (is_numeric($p)) {
            $cat_post_ids[] = (int) $p;
        } elseif (is_object($p) && isset($p->ID)) {
            $cat_post_ids[] = (int) $p->ID;
        } elseif (is_array($p) && isset($p['ID'])) {
            $cat_post_ids[] = (int) $p['ID'];
        }
    }
    $cat_post_ids = array_values(array_unique(array_filter($cat_post_ids, function ($v) {
        return (int) $v > 0;
    })));

    if (!empty($cat_post_ids)) {
        // 1. 批量获取所有文章的分类 term_id
        $placeholders_posts = implode(',', array_fill(0, count($cat_post_ids), '?'));
        $cat_rows = $tododb->get_results(
            "SELECT tr.object_id AS post_id, tt.term_id
             FROM {$tododb->prefix}term_relationships tr
             JOIN {$tododb->prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             WHERE tr.object_id IN ({$placeholders_posts}) AND tt.taxonomy = 'category'",
            $cat_post_ids
        );

        $post_cat_map = []; // post_id => [term_ids]
        $all_cat_term_ids = [];
        foreach ((array)$cat_rows as $cr) {
            if (!is_object($cr) || !isset($cr->post_id) || !isset($cr->term_id)) continue;
            $pid = (int)$cr->post_id;
            $tid = (int)$cr->term_id;
            $all_cat_term_ids[$tid] = $tid;
            if (!isset($post_cat_map[$pid])) {
                $post_cat_map[$pid] = [];
            }
            $post_cat_map[$pid][] = $tid;
        }
        $all_cat_term_ids = array_values($all_cat_term_ids);

        // 2. 批量查询分类关联的站点
        if (!empty($all_cat_term_ids)) {
            $placeholders_terms = implode(',', array_fill(0, count($all_cat_term_ids), '?'));
            $site_terms_table = $tododb->prefix . 'site_terms';
            $term_site_rows = $tododb->get_results(
                "SELECT term_id, GROUP_CONCAT(DISTINCT site_id ORDER BY site_id ASC SEPARATOR ',') AS site_ids
                 FROM {$site_terms_table}
                 WHERE term_id IN ({$placeholders_terms})
                 GROUP BY term_id",
                $all_cat_term_ids
            );

            $term_site_map = []; // term_id => badge label
            foreach ((array)$term_site_rows as $tsr) {
                if (!is_object($tsr) || !isset($tsr->term_id) || !isset($tsr->site_ids)) continue;
                $ids = array_values(array_filter(array_map('intval', explode(',', (string)$tsr->site_ids)), function ($v) {
                    return (int)$v >= 0;
                }));
                if (empty($ids)) continue;
                $labels = array_map(function ($sid, $index) {
                    if ($index === 0) {
                        return ($sid == 0) ? 'global' : 'site' . (int)$sid;
                    }
                    return (int)$sid;
                }, $ids, array_keys($ids));
                $term_site_map[(int)$tsr->term_id] = implode(',', $labels);
            }

            // 3. 为每篇文章汇总其所有分类的站点标识（去重）
            foreach ($post_cat_map as $pid => $term_ids) {
                $badges = [];
                foreach ($term_ids as $tid) {
                    if (isset($term_site_map[$tid])) {
                        $badges[] = $term_site_map[$tid];
                    }
                }
                if (!empty($badges)) {
                    $__todo_category_site_badges[$pid] = implode(' | ', array_unique($badges));
                }
            }
        }
    }
}

// 计算站点过滤ID（供分类列表和查询使用）
$site_scope_id = 0;
if ($is_super_admin) {
    $site_scope_id = $filter_site_id > 0 ? $filter_site_id : 0;
} else {
    $site_scope_id = function_exists('todo_current_site_id') ? (int) todo_current_site_id(false) : 0;
}

// 获取所有分类
$categories = get_categories(array(
    'hide_empty' => false,
    'orderby' => 'term_id',
    'order' => 'ASC',
    'site_id' => $site_scope_id
));
$category_select_items = [];
if (!empty($categories) && is_array($categories)) {
    $by_parent = [];
    foreach ($categories as $c) {
        if (!is_object($c)) {
            continue;
        }
        $tid = isset($c->term_id) ? (int) $c->term_id : 0;
        if ($tid <= 0) {
            continue;
        }
        $pid = isset($c->parent) ? (int) $c->parent : 0;
        if (!isset($by_parent[$pid])) {
            $by_parent[$pid] = [];
        }
        $by_parent[$pid][] = $c;
    }

    $walker = null;
    $walker = function ($parent_id, $level) use (&$walker, &$by_parent, &$category_select_items) {
        if (!isset($by_parent[$parent_id])) {
            return;
        }
        foreach ($by_parent[$parent_id] as $c) {
            $cat_item_name = (string) ($c->name ?? '');
            if (function_exists('todo_parse_inline_translations')) {
                $cat_item_name = todo_parse_inline_translations($cat_item_name);
            }
            $category_select_items[] = [
                'term_id' => (int) $c->term_id,
                'name' => $cat_item_name,
                'level' => (int) $level
            ];
            $walker((int) $c->term_id, $level + 1);
        }
    };
    $walker(0, 0);
}

// 获取文章状态统计
$post_counts = array(
    'all' => $total_posts->publish + $total_posts->draft + $total_posts->pending + $total_posts->scheduled + $total_posts->warehouse,
    'publish' => $total_posts->publish,
    'draft' => $total_posts->draft,
    'pending' => $total_posts->pending,
    'scheduled' => $total_posts->scheduled,
    'warehouse' => $total_posts->warehouse
);

// 构建状态链接（保留现有筛选参数：分类/搜索/排序）
$preserve_params = array();
if ($cat > 0) {
    $preserve_params['cat'] = $cat;
}
if ($tag_id > 0) {
    $preserve_params['tag_id'] = $tag_id;
}
if (!empty($doc_properties_filter)) {
    $preserve_params['doc_properties'] = $doc_properties_filter;
}
if (!empty($s)) {
    $preserve_params['s'] = $s;
}
if (!empty($orderby)) {
    $preserve_params['orderby'] = $orderby;
}
if (!empty($order)) {
    $preserve_params['order'] = $order;
}
if (!empty($missing_lang)) {
    $preserve_params['missing_lang'] = $missing_lang;
}
if (!empty($missing_only)) {
    $preserve_params['missing'] = $missing_only;
}
if (!empty($filter_visibility)) {
    $preserve_params['visibility'] = $filter_visibility;
}
if ($filter_site_id > 0) {
    $preserve_params['site_id'] = $filter_site_id;
}
if (isset($_GET['site'])) {
    $preserve_params['site'] = $_GET['site'];
}
$qs = '';
if (!empty($preserve_params)) {
    $qs = '&' . http_build_query($preserve_params);
}


$status_links = array();
$status_links['all'] = sprintf(
    '<li><a href="%s"%s>全部 <span class="count">(%s)</span></a></li>',
    todo_admin_url('index.php?page=posts' . $qs),
    (!isset($_GET['post_status']) || $_GET['post_status'] === 'all') ? ' class="current"' : '',
    $post_counts['all']
);
$status_links['publish'] = sprintf(
    '<li><a href="%s"%s>已发布 <span class="count">(%s)</span></a></li>',
    todo_admin_url('index.php?page=posts&post_status=publish' . $qs),
    (isset($_GET['post_status']) && $_GET['post_status'] === 'publish') ? ' class="current"' : '',
    $post_counts['publish']
);
$status_links['pending'] = sprintf(
    '<li><a href="%s"%s>待审 <span class="count">(%s)</span></a></li>',
    todo_admin_url('index.php?page=posts&post_status=pending' . $qs),
    (isset($_GET['post_status']) && $_GET['post_status'] === 'pending') ? ' class="current"' : '',
    $post_counts['pending']
);
$status_links['scheduled'] = sprintf(
    '<li><a href="%s"%s>定时发布 <span class="count">(%s)</span></a></li>',
    todo_admin_url('index.php?page=posts&post_status=scheduled' . $qs),
    (isset($_GET['post_status']) && $_GET['post_status'] === 'scheduled') ? ' class="current"' : '',
    $post_counts['scheduled']
);
// 仓库筛选链接（仅当数量>0时显示）
if (!empty($post_counts['warehouse'])) {
    $status_links['warehouse'] = sprintf(
        '<li><a href="%s"%s>仓库 <span class="count">(%s)</span></a></li>',
        todo_admin_url('index.php?page=posts&post_status=warehouse' . $qs),
        (isset($_GET['post_status']) && $_GET['post_status'] === 'warehouse') ? ' class="current"' : '',
        $post_counts['warehouse']
    );
}
$status_links['draft'] = sprintf(
    '<li><a href="%s"%s>草稿 <span class="count">(%s)</span></a></li>',
    todo_admin_url('index.php?page=posts&post_status=draft' . $qs),
    (isset($_GET['post_status']) && $_GET['post_status'] === 'draft') ? ' class="current"' : '',
    $post_counts['draft']
);
?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">内容列表
            <?php if ($cat > 0): ?>
                <?php
                $category = get_term($cat, 'category');
                if ($category):
                    $hdr_cat_name = function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($category->name) : $category->name;
                    ?>
                    <em style="color: #282832;">- <?php echo esc_html($hdr_cat_name); ?></em>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($tag_id > 0): ?>
                <?php
                global $tododb;
                $tag_title = $tododb->get_var("SELECT tag_title FROM {$tododb->prefix}tags WHERE tag_id = " . intval($tag_id));
                if ($tag_title):
                    $hdr_tag_title = function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($tag_title) : $tag_title;
                    ?>
                    <em style="color: #282832;">- 标签: <?php echo esc_html($hdr_tag_title); ?></em>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($doc_properties_filter)): ?>
                <?php
                global $tododb;
                $dp_name = $tododb->get_var("SELECT name FROM {$tododb->prefix}doc_properties WHERE id = " . intval($doc_properties_filter));
                if ($dp_name):
                    ?>
                    <em style="color: #282832;">- 属性: <?php echo esc_html($dp_name); ?></em>
                <?php endif; ?>
            <?php endif; ?>
        </h2>
        <div class="admin-page-actions">
            <?php if ($cat > 0): ?>
                <a href="<?php echo todo_admin_url('index.php?page=categories'); ?>" class="button button-secondary">← 返回分类</a>
            <?php endif; ?>
            <?php if ($tag_id > 0): ?>
                <a href="<?php echo todo_admin_url('index.php?page=tags'); ?>" class="button button-secondary">← 返回标签</a>
            <?php endif; ?>
            <?php if (!empty($doc_properties_filter)): ?>
                <a href="<?php echo todo_admin_url('index.php?page=doc-properties'); ?>" class="button button-secondary">← 返回文档属性</a>
            <?php endif; ?>
            <a href="<?php echo todo_admin_url('index.php?page=post-new' . ($cat > 0 ? '&cat=' . esc_attr($cat) : '')); ?>"
                class="button">写文章</a>
        </div>
    </div>

    <?php
    // 显示通知消息
    if (isset($_GET['message'])) {
        $message_id = $_GET['message'];

        // 如果是数字ID，则从消息数组中获取对应消息
        if (is_numeric($message_id)) {
            $message_id = intval($message_id);
            $messages = array(
                1 => '文章已发布。',
                2 => '文章已更新。',
                4 => '文章已移至回收站。',
                6 => '草稿已保存。',
                7 => '草稿已更新。',
                8 => '数据模型变更成功，字段数据已迁移。',
                9 => '文章审核已通过。',
                10 => '文章审核被拒绝。',
                11 => '文章已提交审核。'
            );

            if (isset($messages[$message_id])) {
                todo_admin_notice($messages[$message_id], 'success');
            }
        } else {
            // 如果是文本消息，直接显示
            todo_admin_notice($message_id, 'success');
        }
    }
    ?>

    <div class="admin-card">
        <div class="admin-card-content">
            <div class="admin-filter-bar">
                <ul class="admin-filters">
                    <?php echo implode('', $status_links); ?>
                </ul>
                <form method="get" action="<?php echo todo_admin_url('index.php'); ?>" class="bulk-actions todo-request" style="height: fit-content;">
                    <input type="hidden" name="page" value="posts">
                    <?php if (isset($_GET['site'])): ?>
                        <input type="hidden" name="site" value="<?php echo esc_attr($_GET['site']); ?>">
                    <?php endif; ?>
                    <?php if (isset($_GET['post_status']) && $_GET['post_status'] !== 'all'): ?>
                        <input type="hidden" name="post_status" value="<?php echo esc_attr($_GET['post_status']); ?>">
                    <?php endif; ?>
                    <?php if ($tag_id > 0): ?>
                        <input type="hidden" name="tag_id" value="<?php echo esc_attr($tag_id); ?>">
                    <?php endif; ?>
                    <?php if (!empty($doc_properties_filter)): ?>
                        <input type="hidden" name="doc_properties" value="<?php echo esc_attr($doc_properties_filter); ?>">
                    <?php endif; ?>
                    <input type="search" name="s" value="<?php echo esc_attr($s); ?>" placeholder="搜索文章...">
                    <input type="submit" class="button button-secondary" value="搜索">
                </form>
            </div>

            <form class="admin-posts-filter todo-request" method="get" action="<?php echo todo_admin_url('index.php'); ?>">
                <input type="hidden" name="page" value="posts">
                <?php if (isset($_GET['site'])): ?>
                    <input type="hidden" name="site" value="<?php echo esc_attr($_GET['site']); ?>">
                <?php endif; ?>
                <?php if (isset($_GET['post_status']) && $_GET['post_status'] !== 'all'): ?>
                    <input type="hidden" name="post_status" value="<?php echo esc_attr($_GET['post_status']); ?>">
                <?php endif; ?>
                <?php if (!empty($s)): ?>
                    <input type="hidden" name="s" value="<?php echo esc_attr($s); ?>">
                <?php endif; ?>
                <?php if ($tag_id > 0): ?>
                    <input type="hidden" name="tag_id" value="<?php echo esc_attr($tag_id); ?>">
                <?php endif; ?>
                <?php if (!empty($doc_properties_filter)): ?>
                    <input type="hidden" name="doc_properties" value="<?php echo esc_attr($doc_properties_filter); ?>">
                <?php endif; ?>

                <div class="bulk-actions-bar">
                    <div class="bulk-actions" style="margin-right: auto;">
                        <?php if ($is_super_admin && count($all_sites) > 1): ?>
                            <select name="site_id" style="min-width: 120px;">
                                <option value="0">全部站点</option>
                                <?php foreach ($all_sites as $site_item): ?>
                                    <option value="<?php echo esc_attr($site_item->id); ?>" <?php selected($filter_site_id, $site_item->id); ?>>
                                        <?php echo esc_html($site_item->name); ?> (site<?php echo (int)$site_item->id; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>

                        <select name="cat" style="min-width: 140px;">
                            <option value="0">所有分类</option>
                            <?php foreach ($category_select_items as $item): ?>
                                <option value="<?php echo esc_attr($item['term_id']); ?>" <?php selected($cat, $item['term_id']); ?>>
                                    <?php echo esc_html(($item['level'] > 0 ? str_repeat('-', $item['level']) . ' ' : '') . $item['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select name="visibility" style="min-width: 85px;">
                            <option value="">可见性</option>
                            <option value="non_public" <?php selected($filter_visibility, 'non_public'); ?>>非公开</option>
                            <option value="readable" <?php selected($filter_visibility, 'readable'); ?>>可读</option>
                            <option value="password" <?php selected($filter_visibility, 'password'); ?>>密码</option>
                            <option value="private" <?php selected($filter_visibility, 'private'); ?>>私密</option>
                            <option value="public" <?php selected($filter_visibility, 'public'); ?>>公开</option>
                        </select>

                        <select name="orderby" style="min-width: 70px;">
                            <option value="date" <?php selected($orderby, 'date'); ?>>日期</option>
                            <option value="title" <?php selected($orderby, 'title'); ?>>标题</option>
                            <option value="author" <?php selected($orderby, 'author'); ?>>作者</option>
                            <option value="comment_count" <?php selected($orderby, 'comment_count'); ?>>评论</option>
                        </select>

                        <select name="order" style="min-width: 70px;">
                            <option value="DESC" <?php selected($order, 'DESC'); ?>>降序</option>
                            <option value="ASC" <?php selected($order, 'ASC'); ?>>升序</option>
                        </select>

                        <select name="missing_lang" style="min-width: 150px;">
                            <option value="">选择语言</option>
                            <?php foreach ($active_languages_hierarchical as $loc => $lang): ?>
                                <?php
                                $level = isset($lang['_level']) ? (int) $lang['_level'] : 0;
                                $prefix = $level > 0 ? str_repeat('-', $level) . ' ' : '';
                                $display_name = !empty($lang['native_name']) ? $lang['native_name'] : $lang['title'];
                                ?>
                                <option value="<?php echo esc_attr($lang['locale']); ?>" <?php selected($missing_lang, $lang['locale']); ?>>
                                    <?php echo esc_html($prefix . $display_name); ?>
                                    (<?php echo esc_html($lang['locale']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label style="margin: 0;font-size: 12px;">
                            <input type="checkbox" name="missing" value="1" <?php checked($missing_only, 1); ?>> 仅未翻译
                        </label>

                        <input type="submit" class="button button-secondary" value="筛选" style="padding: 7px 10px;">
                    </div>

                    <div class="admin-pagination">
                        <?php
                        // 构建分页基础URL，保留当前筛选条件（含定时文章筛选）
                        $pagination_params = array('page' => 'posts');
                        if (isset($_GET['post_status']) && $_GET['post_status'] !== 'all') {
                            $pagination_params['post_status'] = $_GET['post_status'];
                        }
                        if ($cat > 0) {
                            $pagination_params['cat'] = $cat;
                        }
                        if ($tag_id > 0) {
                            $pagination_params['tag_id'] = $tag_id;
                        }
                        if (!empty($doc_properties_filter)) {
                            $pagination_params['doc_properties'] = $doc_properties_filter;
                        }
                        if (!empty($s)) {
                            $pagination_params['s'] = $s;
                        }
                        // 只在非默认值时添加排序参数
                        if (!empty($orderby) && $orderby !== 'date') {
                            $pagination_params['orderby'] = $orderby;
                        }
                        if (!empty($order) && $order !== 'DESC') {
                            $pagination_params['order'] = $order;
                        }
                        // 保留"缺失翻译"过滤参数
                        if (!empty($missing_lang)) {
                            $pagination_params['missing_lang'] = $missing_lang;
                        }
                        if (!empty($missing_only)) {
                            $pagination_params['missing'] = $missing_only;
                        }
                        if (!empty($filter_visibility)) {
                            $pagination_params['visibility'] = $filter_visibility;
                        }
                        if ($filter_site_id > 0) {
                            $pagination_params['site_id'] = $filter_site_id;
                        }
                        if (isset($_GET['site'])) {
                            $pagination_params['site'] = $_GET['site'];
                        }

                        $base_url = 'index.php?' . http_build_query($pagination_params);

                        $page_links = paginate_links(array(
                            'total' => $current_total,
                            'per_page' => $per_page,
                            'current_page' => $paged,
                            'base_url' => $base_url,
                            'prev_text' => '❮',
                            'next_text' => '❯'
                        ));

                        if ($page_links) {
                            echo todo_kses_post($page_links);
                        }
                        ?>
                    </div>
                </div>

                <?php if (!empty($missing_lang) && intval($missing_only) === 1): ?>
                    <div class="notice notice-sm is-dismissible">
                        <p>筛选结果：已启用 <?php echo esc_html($missing_lang); ?> 语言版本，且未发布 approved 状态翻译的文章。</p>
                        <button type="button" class="notice-dismiss">✕</button>
                    </div>
                <?php elseif (!empty($missing_only)): ?>
                    <div class="notice notice-sm is-dismissible">
                        <p>筛选结果：已启用多语言版本，且存在未发布 approved 状态翻译的文章。</p>
                        <button type="button" class="notice-dismiss">✕</button>
                    </div>
                <?php elseif (!empty($missing_lang)): ?>
                    <div class="notice notice-sm is-dismissible">
                        <p>筛选结果：已启用 <?php echo esc_html($missing_lang); ?> 语言版本的文章。</p>
                        <button type="button" class="notice-dismiss">✕</button>
                    </div>
                <?php endif; ?>

            </form>

            <form method="post" id="posts-form" action="<?php echo todo_admin_url('index.php?page=posts' . (isset($_GET['post_status']) && $_GET['post_status'] !== 'all' ? '&post_status=' . urlencode($_GET['post_status']) : '') . $qs); ?>" class="todo-request">
                <?php echo Todo_Security_Manager::csrf_field('bulk-posts'); ?>

                <table class="admin-table">
                    <thead>
                        <tr>
                            <th class="check-column">
                                <input type="checkbox" id="cb-select-all-1">
                            </th>
                            <th style="min-width: 280px;">标题</th>
                            <th style="min-width: 130px;">作者</th>
                            <th style="min-width: 130px;">分类</th>
                            <th style="min-width: 140px;">标签</th>
                            <th style="min-width: 70px;">评论</th>
                            <th style="min-width: 150px;">日期</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!empty($posts)): ?>
                            <?php
                            // 缓存所有启用的文档属性，避免重复查询
                            $display_properties = get_display_doc_properties();

                            // 获取前3个启用的文档属性ID，用于映射不同CSS类
                            $prop_ids_ordered = array_column($display_properties, 'id');
                            $post_property_ids1 = isset($prop_ids_ordered[0]) ? $prop_ids_ordered[0] : 0;
                            $post_property_ids2 = isset($prop_ids_ordered[1]) ? $prop_ids_ordered[1] : 0;
                            $post_property_ids3 = isset($prop_ids_ordered[2]) ? $prop_ids_ordered[2] : 0;

                            // 预加载所有文章涉及的分类及模型缓存，避免 N+1 查询
                            $all_post_categories_map = array();
                            $all_category_term_ids = array();
                            foreach ($posts as $p_item) {
                                $p_obj = is_numeric($p_item) ? get_post($p_item) : $p_item;
                                if (is_object($p_obj)) {
                                    $cats = function_exists('todo_get_post_categories') ? todo_get_post_categories($p_obj->ID) : get_the_category($p_obj->ID);
                                    $all_post_categories_map[$p_obj->ID] = $cats;
                                    if (!empty($cats)) {
                                        foreach ($cats as $c_obj) {
                                            if (!empty($c_obj->term_id)) {
                                                $all_category_term_ids[] = (int) $c_obj->term_id;
                                            }
                                        }
                                    }
                                }
                            }
                            if (!empty($all_category_term_ids) && function_exists('update_term_model_cache')) {
                                update_term_model_cache($all_category_term_ids);
                            }
                            ?>
                            <?php foreach ($posts as $post): ?>
                                <?php
                                if (is_numeric($post)) {
                                    $post = get_post($post);
                                }
                                if (!is_object($post)) {
                                    continue;
                                }
                                $post_title = $post->post_title ? $post->post_title : '(无标题)';
                                $post_type_object = get_post_type_object($post->post_type);
                                $can_edit_post = current_user_can('edit_post', $post->ID);
                                $can_delete_post = current_user_can('delete_post', $post->ID);

                                // 获取当前文章的文档属性ID列表
                                $post_property_ids = get_post_doc_properties($post->ID);

                                // 获取作者信息
                                $author = get_user_by('id', $post->post_author);

                                // 获取分类及关联模型
                                $post_categories = isset($all_post_categories_map[$post->ID]) ? $all_post_categories_map[$post->ID] : (function_exists('todo_get_post_categories') ? todo_get_post_categories($post->ID) : get_the_category($post->ID));
                                $category_links = array();
                                $category_model_names = array();

                                if (!empty($post_categories)) {
                                    foreach ($post_categories as $category) {
                                        $cat_display_name = function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($category->name) : $category->name;
                                        $category_links[] = sprintf(
                                            '<a href="%s">%s</a>',
                                            todo_admin_url('index.php?page=posts&cat=' . $category->term_id),
                                            esc_html($cat_display_name)
                                        );
                                        if (function_exists('get_term_model')) {
                                            $term_model = get_term_model($category->term_id);
                                            if (!empty($term_model['model_name'])) {
                                                $category_model_names[] = $term_model['model_name'];
                                            }
                                        }
                                    }
                                }
                                $category_model_names = array_unique($category_model_names);

                                // 获取标签
                                $tags = get_the_tags($post->ID);
                                $tag_links = array();

                                if (!empty($tags)) {
                                    foreach ($tags as $tag) {
                                        $tag_display_name = function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($tag->name) : $tag->name;
                                        $tag_links[] = sprintf(
                                            '<a href="%s">%s</a>',
                                            todo_admin_url('index.php?page=posts&tag_id=' . $tag->term_id),
                                            esc_html($tag_display_name)
                                        );
                                    }
                                }

                                // 获取评论数
                                $comments_count = get_comments_number($post->ID);

                                // 行操作
                                $actions = array();

                                if ($can_edit_post) {
                                    $actions['edit'] = sprintf(
                                        '<a href="%s">编辑</a>',
                                        todo_admin_url('index.php?page=post-edit&id=' . $post->ID)
                                    );
                                }

                                if ($can_delete_post) {
                                    $actions['trash'] = sprintf(
                                        '<a href="%s" class="submitdelete js-todo-post-action" data-confirm="确定要将该篇内容移至回收站吗？">移至回收站</a>',
                                        todo_admin_url('index.php?page=posts&action=trash&post=' . $post->ID . '&_todononce=' . todo_create_nonce('trash-post-' . $post->ID))
                                    );
                                }

                                $view_url = get_permalink($post->ID);
                                if ($post->post_status === 'future') {
                                    // 为定时发布文章追加无缓存预览参数
                                    $view_url .= (strpos($view_url, '?') !== false ? '&' : '?') . 'todoview=future';
                                }
                                $actions['view'] = sprintf(
                                    '<a href="%s" target="_blank">查看</a>',
                                    $view_url
                                );

                                $row_actions = '';
                                foreach ($actions as $action => $link) {
                                    $row_actions .= '<span class="' . $action . '">' . $link . '</span> | ';
                                }
                                $row_actions = rtrim($row_actions, ' | ');
                                ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="post[]" value="<?php echo esc_attr($post->ID); ?>">
                                    </td>
                                    <td class="flex flex-col<?php echo (!empty($post_property_ids) && !empty($display_properties)) ? ' gap-y-1' : ''; ?>">
                                        <div>
                                        <?php if ($__todo_show_site_badge && isset($__todo_post_site_badges[$post->ID])): ?>
                                            <em style="display: inline-block;font-size: 12px;color: #999;padding: 0 5px;background-color: #f1f1f1;max-width: 100px;overflow: hidden;text-overflow: ellipsis;vertical-align: middle;"><?php echo esc_html($__todo_post_site_badges[$post->ID]); ?></em>
                                        <?php endif; ?>
                                        <?php if ($can_edit_post && $post->post_status !== 'trash'): ?>
                                            <a href="<?php echo todo_admin_url('index.php?page=post-edit&id=' . $post->ID); ?>"
                                                class="row-title">
                                                <?php echo esc_html($post_title); ?>
                                            </a>
                                        <?php else: ?>
                                            <strong><?php echo esc_html($post_title); ?></strong>
                                        <?php endif; ?>

                                        <?php 
                                        $post_states = [];
                                        if ($post->post_status === 'draft') $post_states[] = ['label' => '草稿', 'color' => ''];
                                        if ($post->post_status === 'pending') $post_states[] = ['label' => '待审', 'color' => '#FF9800'];
                                        if ($post->post_status === 'future') $post_states[] = ['label' => '定时发布', 'color' => ''];
                                        if (isset($post->sale_status) && $post->sale_status === 'warehouse') $post_states[] = ['label' => '仓库', 'color' => ''];
                                        
                                        foreach ($post_states as $state): ?>
                                            <span class="post-state" style="<?php echo !empty($state['color']) ? 'color: ' . $state['color'] . ';' : ''; ?>"> — <?php echo esc_html($state['label']); ?></span>
                                        <?php endforeach; ?>
                                        </div>

                                        <div class="flex items-center gap-x-4 gap-y-2 flex-wrap">
                                            <?php if (!empty($post_property_ids) && !empty($display_properties)): ?>
                                                <div class="flex items-center gap-1 flex-wrap">
                                                <?php foreach ($display_properties as $prop): ?>
                                                    <?php if (in_array($prop['id'], $post_property_ids)): ?>
                                                        <?php
                                                            $prop_class = 'status-inactive';
                                                            if ($prop['id'] == $post_property_ids1) {
                                                                $prop_class = 'status-spam';
                                                            } elseif ($prop['id'] == $post_property_ids2) {
                                                                $prop_class = 'status-reject';
                                                            } elseif ($prop['id'] == $post_property_ids3) {
                                                                $prop_class = 'status-pending';
                                                            }
                                                        ?>
                                                        <span class="<?php echo $prop_class; ?>" style="font-size:10px;"><?php echo esc_html($prop['name']); ?></span>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
	                                        <div class="row-actions">
	                                            <?php echo todo_kses_post($row_actions); ?>
	                                        </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($author): ?>
                                            <a
                                                href="<?php echo todo_admin_url('index.php?page=users&s=' . urlencode($author->user_login)); ?>">
                                                <?php echo esc_html($author->display_name); ?>
                                            </a>
                                        <?php else: ?>
                                            未知作者
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="tb-meta"><?php echo !empty($category_links) ? implode(', ', $category_links) : ''; ?></span>
                                        <?php if ($__todo_show_site_badge && isset($__todo_category_site_badges[$post->ID])): ?>
                                            <em style="display: block;width: fit-content;font-size: 12px;color: #999;padding: 0 5px;background-color: #f1f1f1;"><?php echo esc_html($__todo_category_site_badges[$post->ID]); ?></em>
                                        <?php endif; ?>
                                    </td>
                                    <td style="width: 140px; font-size: 13px;">
                                        <?php echo !empty($tag_links) ? implode(', ', $tag_links) : ''; ?>
                                    </td>
                                    <td>
                                        <?php if ($comments_count > 0): ?>
                                            <?php if (in_array($post->post_type, ['guestbook', 'message'], true)): ?>
                                                <a href="<?php echo todo_admin_url('index.php?page=website-messages'); ?>">
                                                    <?php echo esc_html($comments_count); ?>
                                                </a>
                                            <?php else: ?>
                                                <a href="<?php echo todo_admin_url('index.php?page=comments&p=' . $post->ID); ?>">
                                                    <?php echo esc_html($comments_count); ?>
                                                </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $visibility_badge = '';
                                        $p_visibility = !empty($post->visibility) ? $post->visibility : (!empty($post->post_visibility) ? $post->post_visibility : (function_exists('get_post_meta') ? get_post_meta($post->ID, '_visibility', true) : ''));
                                        $visibility_map = [
                                            'readable' => ['label' => '读', 'title' => '可读 (不收录)'],
                                            'password' => ['label' => '密', 'title' => '密码保护'],
                                            'private'  => ['label' => '私', 'title' => '私密'],
                                        ];
                                        if (!empty($p_visibility) && $p_visibility !== 'public' && isset($visibility_map[$p_visibility])) {
                                            $v_info = $visibility_map[$p_visibility];
                                            $visibility_badge = '<span class="post-visibility-badge" style="font-size: 11px;margin-left: 2px;" title="' . esc_attr($v_info['title']) . '">' . esc_html($v_info['label']) . '</span>';
                                        }

                                        if ($post->post_status === 'publish') {
                                            echo '<div class="tb-description">发布于' . (!empty($category_model_names) ? '<em style="font-size: 10px;color: #999;margin-left: 3px;">' . esc_html(implode(', ', $category_model_names)) . '</em>' : '') . '</div><div class="tb-meta">' . date('Y-m-d H:i', strtotime($post->post_date)) . $visibility_badge . '</div>';
                                        } elseif ($post->post_status === 'future') {
                                            echo '<div class="tb-description">定时发布于' . (!empty($category_model_names) ? '<em style="font-size: 10px;color: #999;margin-left: 3px;">' . esc_html(implode(', ', $category_model_names)) . '</em>' : '') . '</div><b class="tb-meta" style="color: #FF5722;">' . date('Y-m-d H:i', strtotime($post->post_date)) . $visibility_badge . '</b>';
                                        } else {
                                            echo '<div class="tb-description">最后修改于' . (!empty($category_model_names) ? '<em style="font-size: 10px;color: #999;margin-left: 3px;">' . esc_html(implode(', ', $category_model_names)) . '</em>' : '') . '</div><div class="tb-meta">' . date('Y-m-d H:i', strtotime($post->post_modified)) . $visibility_badge . '</div>';
                                        }
                                        ?>

                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7">
                                    <div class="no-items">
                                        <p>没有找到文章。</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>

                    <tfoot>
                        <tr>
                            <th>
                                <input type="checkbox" id="cb-select-all-2">
                            </th>
                            <th>标题</th>
                            <th>作者</th>
                            <th>分类</th>
                            <th>标签</th>
                            <th>评论</th>
                            <th>日期</th>
                        </tr>
                    </tfoot>
                </table>

                <div class="bulk-actions-bar" style="margin: 10px 0 0;">
                    <div class="bulk-actions">
                        <select name="action" style="min-width: 140px;" onchange="todoToggleChangeSitePanel(this, 'posts-change-site-panel')">
                            <option value="-1">批量操作</option>
                            <option value="publish">发布</option>
                            <option value="draft">设为草稿</option>
                            <option value="trash">移至回收站</option>
                            <option value="change_category">更换分类</option>
                            <?php if ($is_super_admin && count($all_sites) > 1): ?>
                                <option value="change_site">变更站点</option>
                            <?php endif; ?>
                        </select>
                        <?php if ($is_super_admin && count($all_sites) > 1): ?>
                            <span id="posts-change-site-panel" style="display:none; flex-direction: column; align-items:center; gap:6px;">
                                <select name="target_site_id[]" multiple style="min-width: 150px; height: 67px; padding: 2px 5px;" title="按住 Ctrl/Cmd 可多选；不选任何项则设为全局可见">
                                    <?php foreach ($all_sites as $site_item): ?>
                                        <option value="<?php echo esc_attr($site_item->id); ?>">site<?php echo (int)$site_item->id; ?> <?php echo esc_html($site_item->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span style="font-size:12px;color:#888;">不选全局可见 shift↑+多选</span>
                            </span>
                        <?php endif; ?>
                        <button type="submit" name="doaction" class="button button-secondary">应用</button>
                    </div>

                    <div class="admin-pagination">
                        <?php
                        if ($page_links) {
                            echo todo_kses_post($page_links);
                        }
                        ?>
                    </div>
                </div>
            </form>
            <form method="post" id="bulk-action-form" action="<?php echo todo_admin_url('index.php?page=posts'); ?>" class="todo-request" style="display:none;">
                <?php echo Todo_Security_Manager::csrf_field('bulk-posts'); ?>
                <input type="hidden" name="action" value="">
            </form>

            <template id="change-category-modal-template" data-size="md">
                <div data-slot="title">更换分类</div>
                <div data-slot="body">
                    <form id="change-category-form" method="post" action="<?php echo todo_admin_url('index.php?page=posts'); ?>">
                        <?php echo Todo_Security_Manager::csrf_field('bulk-posts'); ?>
                        <?php if (isset($_GET['post_status']) && $_GET['post_status'] !== 'all'): ?>
                            <input type="hidden" name="post_status" value="<?php echo esc_attr($_GET['post_status']); ?>">
                        <?php endif; ?>
                        <input type="hidden" name="action" value="bulk_change_category">
                        <input type="hidden" name="post_ids" id="change-category-post-ids">

                        <p>已选取 <strong id="change-category-count" style="color: #2271b1; font-size: 16px;">0</strong> 篇文章，请选择它们的新归属分类：</p>
                        <div style="margin-top: 5px; max-height: 300px; overflow-y: auto;">
                            <?php
                            render_hierarchical_categories_selector(array(
                                'name' => 'category_ids[]',
                                'id' => 'change-category-checklist',
                                'selected' => array(),
                                'multiple' => true,
                                'show_checkbox' => true,
                                'class' => 'change-category-selector',
                                'site_id' => $site_scope_id,
                                'description' => '提示：如不勾选任何分类，将清空所选文章的分类归属。',
                            ));
                            ?>
                        </div>
                    </form>
                </div>
                <div data-slot="footer">
                    <button type="button" class="button button-secondary todo-modal-cancel">取消</button>
                    <button type="submit" form="change-category-form" class="button button-primary">确认更换</button>
                </div>
            </template>
        </div>
    </div>
</div>

<style>
    /* 文章列表页面特定样式 */
    .admin-subsubsub {
        margin-bottom: 15px;
    }

    .admin-subsubsub ul {
        margin: 0;
        padding: 0;
        list-style: none;
        font-size: 13px;
    }

    .admin-subsubsub li {
        display: inline-block;
        margin: 0;
        padding: 0;
    }

    .admin-subsubsub a {
        padding: 0 5px;
        line-height: 2;
        text-decoration: none;
    }

    .admin-subsubsub a.current {
        font-weight: 500;
        color: #000;
    }

    .admin-subsubsub .count {
        color: #999;
    }

    .admin-tablenav {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin: 15px 0;
    }

    .admin-tablenav.top {
        margin-top: 0;
    }

    .admin-posts-table {
        width: 100%;
        border-collapse: collapse;
    }

    .admin-posts-table th {
        font-weight: 500;
        text-align: left;
    }

    .admin-posts-table .column-title {
        width: 30%;
    }

    .admin-posts-table .column-author,
    .admin-posts-table .column-categories,
    .admin-posts-table .column-tags {
        width: 15%;
    }

    .admin-posts-table .column-comments,
    .admin-posts-table .column-date {
        width: 10%;
    }

    .post-state {
        font-weight: normal;
        font-style: italic;
        color: #a0a0aa;
    }
</style>

<script type="text/javascript">
    // 切换"变更站点"多选面板显示
    function todoToggleChangeSitePanel(selectEl, panelId) {
        var panel = document.getElementById(panelId);
        if (!panel) return;
        panel.style.display = (selectEl.value === 'change_site') ? 'flex' : 'none';
    }

    // 全选/取消全选
    document.addEventListener('DOMContentLoaded', function () {
        var selectAll1 = document.getElementById('cb-select-all-1');
        var selectAll2 = document.getElementById('cb-select-all-2');
        var checkboxes = document.querySelectorAll('input[name="post[]"]');

        function toggleAll(checked) {
            checkboxes.forEach(function (checkbox) {
                checkbox.checked = checked;
            });
        }

        if (selectAll1) {
            selectAll1.addEventListener('change', function () {
                toggleAll(this.checked);
                if (selectAll2) selectAll2.checked = this.checked;
            });
        }

        if (selectAll2) {
            selectAll2.addEventListener('change', function () {
                toggleAll(this.checked);
                if (selectAll1) selectAll1.checked = this.checked;
            });
        }

        // 批量更换分类
        var postsForm = document.getElementById('posts-form');
        if (postsForm) {
            postsForm.addEventListener('submit', function (e) {
                var actionSelect = postsForm.querySelector('select[name="action"]');
                if (!actionSelect) return;

                var action = actionSelect.value;
                if (action !== 'change_category') return;

                var checkedItems = postsForm.querySelectorAll('input[name="post[]"]:checked');
                if (checkedItems.length === 0) {
                    e.preventDefault();
                    TodoCMS.Utils.showMessage('请选择要操作的文章', 'warning');
                    return false;
                }

                e.preventDefault();

                var postIds = [];
                checkedItems.forEach(function (cb) {
                    postIds.push(cb.value);
                });

                var modal = new TodoModal({
                    templateId: 'change-category-modal-template',
                    destroyOnClose: true,
                    onOpen: function (modalInstance) {
                        var ctx = modalInstance.element;
                        var postIdsField = ctx.querySelector('#change-category-post-ids');
                        var countSpan = ctx.querySelector('#change-category-count');
                        if (postIdsField) postIdsField.value = postIds.join(',');
                        if (countSpan) countSpan.textContent = checkedItems.length;

                        // 绑定 AJAX 提交
                        var changeForm = ctx.querySelector('#change-category-form');
                        if (changeForm) {
                            changeForm.addEventListener('submit', function (ev) {
                                ev.preventDefault();
                                var thisForm = this;
                                var submitBtn = thisForm.querySelector('button[type="submit"]');
                                if (thisForm.dataset.submitting === 'true') return;
                                thisForm.dataset.submitting = 'true';
                                if (submitBtn) {
                                    submitBtn.disabled = true;
                                    submitBtn.textContent = '处理中...';
                                }

                                var formData = new FormData(thisForm);

                                var xhr = new XMLHttpRequest();
                                xhr.open('POST', thisForm.getAttribute('action') || window.location.href, true);
                                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                                xhr.onload = function () {
                                    try {
                                        var response = JSON.parse(xhr.responseText);
                                        if (response && response.success) {
                                            TodoCMS.Utils.showMessage(response.message || '操作成功', 'success');
                                            modalInstance.close();
                                            window.location.reload();
                                        } else {
                                            var msg = (response && response.message) ? response.message : '操作失败';
                                            TodoModal.error(msg);
                                            thisForm.dataset.submitting = 'false';
                                            if (submitBtn) {
                                                submitBtn.disabled = false;
                                                submitBtn.textContent = '确认更换';
                                            }
                                        }
                                    } catch (err) {
                                        TodoModal.error('服务器响应异常，请重试');
                                        thisForm.dataset.submitting = 'false';
                                        if (submitBtn) {
                                            submitBtn.disabled = false;
                                            submitBtn.textContent = '确认更换';
                                        }
                                    }
                                };
                                xhr.onerror = function () {
                                    TodoModal.error('网络错误');
                                    thisForm.dataset.submitting = 'false';
                                    if (submitBtn) {
                                        submitBtn.disabled = false;
                                        submitBtn.textContent = '确认更换';
                                    }
                                };
                                xhr.send(formData);
                            });
                        }
                    }
                });

                modal.open();
                return false;
            });
        }
    });
</script>
