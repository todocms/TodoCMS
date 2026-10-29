<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}

/**
 * 回收站页面
 *
 * 统一管理所有回收站内容
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 检查用户权限（仅平台管理员/超管可访问）
$current_user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
$current_user_obj = ($current_user_id > 0 && class_exists('CMS_User')) ? new CMS_User($current_user_id) : null;
$is_platform_admin = false;
if (function_exists('todo_is_super_admin') && todo_is_super_admin($current_user_id)) {
    $is_platform_admin = true;
} elseif (is_object($current_user_obj) && method_exists($current_user_obj, 'has_role')) {
    $is_platform_admin = $current_user_obj->has_role('administrator') || $current_user_obj->has_role('admin');
}

if (!$is_platform_admin && !current_user_can('manage_trash') && !current_user_can('manage_options')) {
    todo_die('您没有足够的权限访问此页面。');
}

// 统一处理批量操作（文章/分类） - PRG + CSRF
$action = null;
if (isset($_POST['action']) && $_POST['action'] !== '-1') {
    $action = $_POST['action'];
} elseif (isset($_POST['action2']) && $_POST['action2'] !== '-1') {
    $action = $_POST['action2'];
}

if ($action && isset($_POST['items']) && is_array($_POST['items'])) {
    $item_type = isset($_POST['item_type']) ? $_POST['item_type'] : 'post';

    if (!Todo_Security_Manager::verify_request_csrf('trash_action')) {
        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice('安全验证失败，请重新提交。', 'error');
        }
        todo_redirect(todo_admin_url('index.php?page=trash'));
        exit;
    }

    $raw_items = isset($_POST['items']) ? (array) $_POST['items'] : array();
    $count = 0;

    if ($item_type === 'mixed') {
        $post_ids = array();
        $term_ids = array();
        foreach ($raw_items as $raw) {
            $parts = explode('|', $raw, 2);
            if (count($parts) === 2) {
                $t = $parts[0];
                $id = intval($parts[1]);
                if ($t === 'post') {
                    $post_ids[] = $id;
                } elseif ($t === 'category') {
                    $term_ids[] = $id;
                }
            }
        }
        // 处理文章
        if (!empty($post_ids)) {
            if (!current_user_can('delete_posts')) {
                if (function_exists('todo_add_admin_notice')) {
                    todo_add_admin_notice('您没有权限管理文章。', 'error');
                }
                todo_redirect(todo_admin_url('index.php?page=trash'));
                exit;
            }
            foreach ($post_ids as $post_id) {
                if (current_user_can('delete_post', $post_id)) {
                    if ($action === 'untrash') {
                        todo_untrash_post($post_id);
                    } elseif ($action === 'delete') {
                        todo_delete_post($post_id, true);
                    }
                    $count++;
                }
            }
        }
        // 处理分类
        if (!empty($term_ids)) {
            if (!current_user_can('manage_categories')) {
                if (function_exists('todo_add_admin_notice')) {
                    todo_add_admin_notice('您没有权限管理分类。', 'error');
                }
                todo_redirect(todo_admin_url('index.php?page=trash'));
                exit;
            }
            foreach ($term_ids as $term_id) {
                if ($action === 'untrash') {
                    $res = todo_untrash_term($term_id, 'category');
                    if (!is_todo_error($res)) {
                        $count++;
                    }
                } elseif ($action === 'delete') {
                    $res = todo_delete_term($term_id, 'category');
                    if (!is_todo_error($res)) {
                        $count++;
                    }
                }
            }
        }
        $msg = ($action === 'untrash') ? ('已从回收站恢复 ' . $count . ' 个项目。') : ('已永久删除 ' . $count . ' 个项目。');
        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice($msg, 'success');
        }
        todo_redirect(todo_admin_url('index.php?page=trash'));
        exit;
    }

    $ids = array_map('intval', $raw_items);

    if ($item_type === 'post') {
        if (!current_user_can('delete_posts')) {
            if (function_exists('todo_add_admin_notice')) {
                todo_add_admin_notice('您没有权限管理文章。', 'error');
            }
            todo_redirect(todo_admin_url('index.php?page=trash&post_type=post'));
            exit;
        }
        foreach ($ids as $post_id) {
            if (current_user_can('delete_post', $post_id)) {
                if ($action === 'untrash') {
                    todo_untrash_post($post_id);
                } elseif ($action === 'delete') {
                    todo_delete_post($post_id, true);
                }
                $count++;
            }
        }
        $msg = ($action === 'untrash') ? ('已从回收站恢复 ' . $count . ' 篇文章。') : ('已永久删除 ' . $count . ' 篇文章。');
        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice($msg, 'success');
        }
        todo_redirect(todo_admin_url('index.php?page=trash&post_type=post'));
        exit;
    } elseif ($item_type === 'category') {
        if (!current_user_can('manage_categories')) {
            if (function_exists('todo_add_admin_notice')) {
                todo_add_admin_notice('您没有权限管理分类。', 'error');
            }
            todo_redirect(todo_admin_url('index.php?page=trash&post_type=category'));
            exit;
        }
        foreach ($ids as $term_id) {
            if ($action === 'untrash') {
                $res = todo_untrash_term($term_id, 'category');
                if (!is_todo_error($res)) {
                    $count++;
                }
            } elseif ($action === 'delete') {
                $res = todo_delete_term($term_id, 'category');
                if (!is_todo_error($res)) {
                    $count++;
                }
            }
        }
        $msg = ($action === 'untrash') ? ('已从回收站恢复 ' . $count . ' 个分类。') : ('已永久删除 ' . $count . ' 个分类。');
        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice($msg, 'success');
        }
        todo_redirect(todo_admin_url('index.php?page=trash&post_type=category'));
        exit;
    } elseif ($item_type === 'model_field') {
        if (!current_user_can('manage_models') && !current_user_can('manage_options')) {
            if (function_exists('todo_add_admin_notice')) {
                todo_add_admin_notice('您没有权限管理模型字段。', 'error');
            }
            todo_redirect(todo_admin_url('index.php?page=trash&post_type=model_field'));
            exit;
        }
        global $tododb;
        foreach ($ids as $field_id) {
            // 获取模型ID以进行清理
            $model_id = $tododb->get_var("SELECT model_id FROM " . TABLE_PREFIX . "model_fields WHERE field_id = ?", [$field_id]);
            if ($model_id) {
                $model = new CMS_Model($model_id);
                if ($action === 'untrash') {
                    CMS_Model_Field_Methods::untrash_field($model, $field_id);
                } elseif ($action === 'delete') {
                    CMS_Model_Field_Methods::delete_field($model, $field_id);
                }
                $count++;
            }
        }
        $msg = ($action === 'untrash') ? ('已从回收站恢复 ' . $count . ' 个字段。') : ('已永久删除 ' . $count . ' 个字段。');
        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice($msg, 'success');
        }
        todo_redirect(todo_admin_url('index.php?page=trash&post_type=model_field'));
        exit;
    } elseif ($item_type === 'global_var') {
        if (!current_user_can('manage_global_vars') && !current_user_can('manage_options')) {
            if (function_exists('todo_add_admin_notice')) {
                todo_add_admin_notice('您没有权限管理全局变量。', 'error');
            }
            todo_redirect(todo_admin_url('index.php?page=trash&post_type=global_var'));
            exit;
        }
        $existing_fields = get_option('global_vars_fields', array());
        $existing_values = get_option('global_vars', array());
        if (!is_array($existing_values)) {
            $existing_values = array();
        }
        $values_changed = false;
        $new_fields = [];
        foreach ($existing_fields as $idx => $field) {
            if (in_array($idx, $ids)) {
                if ($action === 'untrash') {
                    $field['field_status'] = 'active';
                    $new_fields[] = $field;
                    $count++;
                }
                // If action is delete, we just don't add it to $new_fields
                if ($action === 'delete') {
                    if (is_array($field) && isset($field['field_key']) && isset($existing_values[$field['field_key']])) {
                        unset($existing_values[$field['field_key']]);
                        $values_changed = true;
                    }
                    $count++;
                    // Optional: remove values too?
                }
            } else {
                $new_fields[] = $field;
            }
        }
        update_option('global_vars_fields', $new_fields);
        if ($values_changed) {
            update_option('global_vars', $existing_values);
        }
        $msg = ($action === 'untrash') ? ('已从回收站恢复 ' . $count . ' 个全局变量。') : ('已永久删除 ' . $count . ' 个全局变量。');
        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice($msg, 'success');
        }
        todo_redirect(todo_admin_url('index.php?page=trash&post_type=global_var'));
        exit;
    }
}

// 处理单个操作（采用PRG避免重复执行）
if (isset($_GET['action']) && isset($_GET['post'])) {
    $action = $_GET['action'];
    $post_id = intval($_GET['post']);
    $type = isset($_GET['type']) ? $_GET['type'] : 'post';

    if ($type === 'post') {
        switch ($action) {
            case 'untrash':
                if (current_user_can('delete_post', $post_id)) {
                    if (todo_untrash_post($post_id)) {
                        $message = '文章已从回收站恢复。';
                    } else {
                        $error = '恢复文章时出错。';
                    }
                }
                break;
            case 'delete':
                if (current_user_can('delete_post', $post_id)) {
                    if (todo_delete_post($post_id, true)) {
                        $message = '文章已永久删除。';
                    } else {
                        $error = '删除文章时出错。';
                    }
                }
                break;
        }
    } elseif ($type === 'category') {
        if (current_user_can('manage_categories')) {
            switch ($action) {
                case 'untrash':
                    $res = todo_untrash_term($post_id, 'category');
                    if (!is_todo_error($res)) {
                        $message = '栏目分类已从回收站恢复。';
                    } else {
                        $error = '无法恢复分类（可能父级分类在回收站中，请先恢复父级）。';
                    }
                    break;
                case 'delete':
                    $res = todo_delete_term($post_id, 'category');
                    if (!is_todo_error($res)) {
                        $message = '栏目分类已永久删除。';
                    } else {
                        $error = '删除栏目分类时出错。';
                    }
                    break;
            }
        }
    }

    if (isset($message) || isset($error)) {
        $msg_type = isset($error) ? 'error' : 'success';
        $msg = isset($error) ? $error : $message;
        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice($msg, $msg_type);
        }
        todo_redirect(todo_admin_url('index.php?page=trash&post_type=' . $type));
        exit;
    }
}

// 处理模型字段的单个操作
if (isset($_GET['field_action']) && isset($_GET['field'])) {
    $field_action = $_GET['field_action'];
    $field_id = intval($_GET['field']);
    global $tododb;
    
    $model_id = $tododb->get_var("SELECT model_id FROM " . TABLE_PREFIX . "model_fields WHERE field_id = ?", [$field_id]);
    if ($model_id) {
        $model = new CMS_Model($model_id);
        if ($field_action === 'untrash') {
            if (CMS_Model_Field_Methods::untrash_field($model, $field_id)) {
                $message = '模型字段已从回收站恢复。';
            } else {
                $error = '恢复模型字段时出错。';
            }
        } elseif ($field_action === 'delete') {
            if (CMS_Model_Field_Methods::delete_field($model, $field_id)) {
                $message = '模型字段已永久删除。';
            } else {
                $error = '删除模型字段时出错。';
            }
        }
    }

    if (isset($message) || isset($error)) {
        $type = isset($error) ? 'error' : 'success';
        $msg = isset($error) ? $error : $message;
        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice($msg, $type);
        }
        todo_redirect(todo_admin_url('index.php?page=trash&post_type=model_field'));
        exit;
    }
}

// 处理全局变量的单个操作
if (isset($_GET['gv_action']) && isset($_GET['gv_idx'])) {
    $gv_action = $_GET['gv_action'];
    $gv_idx = intval($_GET['gv_idx']);
    
    $existing_fields = get_option('global_vars_fields', array());
    if (isset($existing_fields[$gv_idx])) {
        if ($gv_action === 'untrash') {
            $existing_fields[$gv_idx]['field_status'] = 'active';
            update_option('global_vars_fields', $existing_fields);
            $message = '全局变量已从回收站恢复。';
        } elseif ($gv_action === 'delete') {
            $existing_values = get_option('global_vars', array());
            if (!is_array($existing_values)) {
                $existing_values = array();
            }
            $field = $existing_fields[$gv_idx];
            if (is_array($field) && isset($field['field_key']) && isset($existing_values[$field['field_key']])) {
                unset($existing_values[$field['field_key']]);
                update_option('global_vars', $existing_values);
            }
            unset($existing_fields[$gv_idx]);
            update_option('global_vars_fields', $existing_fields);
            $message = '全局变量已永久删除。';
        }
    }

    if (isset($message) || isset($error)) {
        $type = isset($error) ? 'error' : 'success';
        $msg = isset($error) ? $error : $message;
        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice($msg, $type);
        }
        todo_redirect(todo_admin_url('index.php?page=trash&post_type=global_var'));
        exit;
    }
}

// 默认排序参数
$orderby = isset($_GET['orderby']) ? $_GET['orderby'] : 'date';
$order = isset($_GET['order']) ? $_GET['order'] : 'DESC';

// 获取内容类型筛选参数
$post_type_filter = isset($_GET['post_type']) ? $_GET['post_type'] : (isset($_POST['post_type']) ? $_POST['post_type'] : 'post');

// 如果是 'all'，则重定向到默认的 'post'
if ($post_type_filter === 'all') {
    todo_redirect(todo_admin_url('index.php?page=trash&post_type=post'));
    exit;
}

// 处理清空回收站操作
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'empty_trash') {
    if (!Todo_Security_Manager::verify_request_csrf('empty_trash')) {
        todo_die('安全验证失败。');
    }

    $count = 0;
    if ($post_type_filter === 'post' && current_user_can('delete_posts')) {
        $trashed_posts = get_posts([
            'post_type' => 'any',
            'post_status' => 'trash',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ]);
        foreach ($trashed_posts as $p_id) {
            if (current_user_can('delete_post', $p_id)) {
                todo_delete_post($p_id, true);
                $count++;
            }
        }
        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice("已成功清理 $count 篇文章。", 'success');
        }
        todo_redirect(todo_admin_url('index.php?page=trash&post_type=post'));
        exit;
    } elseif ($post_type_filter === 'category' && current_user_can('manage_categories')) {
        global $tododb;
        $term_attributes_table = TABLE_PREFIX . 'term_attributes';
        $ids = $tododb->get_col("SELECT term_id FROM $term_attributes_table WHERE (JSON_UNQUOTE(JSON_EXTRACT(attributes, '$.trashed')) = '1') AND (JSON_UNQUOTE(JSON_EXTRACT(attributes, '$.trashed_taxonomy')) = 'category')");
        foreach ($ids as $term_id) {
            todo_delete_term($term_id, 'category');
            $count++;
        }
        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice("已成功清理 $count 个分类。", 'success');
        }
        todo_redirect(todo_admin_url('index.php?page=trash&post_type=category'));
        exit;
    } elseif ($post_type_filter === 'model_field' && (current_user_can('manage_models') || current_user_can('manage_options'))) {
        global $tododb;
        $trashed_fields = $tododb->get_results("SELECT field_id, model_id FROM " . TABLE_PREFIX . "model_fields WHERE field_status = 'trash'");
        foreach ($trashed_fields as $f) {
            $model = new CMS_Model($f->model_id);
            CMS_Model_Field_Methods::delete_field($model, $f->field_id);
            $count++;
        }
        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice("已成功清理 $count 个字段。", 'success');
        }
        todo_redirect(todo_admin_url('index.php?page=trash&post_type=model_field'));
        exit;
    } elseif ($post_type_filter === 'global_var' && (current_user_can('manage_global_vars') || current_user_can('manage_options'))) {
        $existing_fields = get_option('global_vars_fields', array());
        $existing_values = get_option('global_vars', array());
        if (!is_array($existing_values)) {
            $existing_values = array();
        }
        $values_changed = false;
        $new_fields = [];
        foreach ($existing_fields as $field) {
            if (isset($field['field_status']) && $field['field_status'] === 'trash') {
                if (is_array($field) && isset($field['field_key']) && isset($existing_values[$field['field_key']])) {
                    unset($existing_values[$field['field_key']]);
                    $values_changed = true;
                }
                $count++;
            } else {
                $new_fields[] = $field;
            }
        }
        update_option('global_vars_fields', $new_fields);
        if ($values_changed) {
            update_option('global_vars', $existing_values);
        }
        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice("已成功清理 $count 个全局变量。", 'success');
        }
        todo_redirect(todo_admin_url('index.php?page=trash&post_type=global_var'));
        exit;
    }
}

// 获取分页参数
$paged = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
$per_page = 20;

// 统一解析搜索词（支持 GET/POST）
$s = isset($_GET['s']) ? trim($_GET['s']) : (isset($_POST['s']) ? trim($_POST['s']) : '');

// 根据筛选条件设置post_type
$post_types = 'any';
// 在“文章”筛选下，展示所有文章类型（any），而不是仅限标准post类型
if ($post_type_filter !== 'all' && $post_type_filter !== 'post') {
    $post_types = array($post_type_filter);
}

// 获取回收站中的文章
$args = array(
    'post_type' => $post_types,
    'post_status' => 'trash',
    'posts_per_page' => $per_page,
    'paged' => $paged,
    'orderby' => $orderby,
    'order' => $order,
    's' => $s
);

$posts = get_posts($args);

// 批量预加载文章分类缓存，避免N+1查询
if (!empty($posts)) {
    $post_ids = array_map(function ($p) {
        return $p->ID;
    }, $posts);
    update_post_term_cache($post_ids, 'category');
}

// 获取回收站总数（所有类型）
$total_posts = count_posts('post');
$total_all = count_posts('any');
$trash_count = $total_all->trash;

// 计算文章（所有类型）在当前搜索条件下的回收站数量
$post_trash_filtered_total = $trash_count;
if (trim($s) !== '') {
    $count_args = array(
        'post_type' => 'any',
        'post_status' => 'trash',
        'posts_per_page' => -1,
        's' => $s
    );
    $post_trash_filtered_total = count(get_posts($count_args));
}

// 根据筛选条件计算数量（用于分页）
if ($post_type_filter === 'post') {
    $filtered_count = $post_trash_filtered_total;
} else {
    $filtered_count = $trash_count;
}

// 计算分页
$total_pages_num = ceil($filtered_count / $per_page);

// 分类回收站：参数与操作处理
$cat_paged = isset($_GET['cat_paged']) ? max(1, intval($_GET['cat_paged'])) : 1;
$cat_per_page = 20;

$cat_s = $s; // 统一使用 $s

// 分类回收站：处理批量操作（PRG + CSRF）
if (isset($_POST['cat_action']) && isset($_POST['category']) && is_array($_POST['category'])) {
    if (!current_user_can('manage_categories')) {
        $error = '您没有权限管理分类。';
    } else {
        if (!Todo_Security_Manager::verify_request_csrf('trash_category_action')) {
            if (function_exists('todo_add_admin_notice')) {
                todo_add_admin_notice('安全验证失败，请重新提交。', 'error');
            }
            todo_redirect(todo_admin_url('index.php?page=trash&post_type=category'));
            exit;
        }

        $ids = array_map('intval', $_POST['category']);
        $count = 0;
        if ($_POST['cat_action'] === 'untrash') {
            foreach ($ids as $term_id) {
                $res = todo_untrash_term($term_id, 'category');
                if (!is_todo_error($res)) {
                    $count++;
                }
            }
            if (function_exists('todo_add_admin_notice')) {
                todo_add_admin_notice('已从回收站恢复 ' . $count . ' 个分类。', 'success');
            }
            todo_redirect(todo_admin_url('index.php?page=trash&post_type=category'));
            exit;
        } elseif ($_POST['cat_action'] === 'delete') {
            foreach ($ids as $term_id) {
                $res = todo_delete_term($term_id, 'category');
                if (!is_todo_error($res)) {
                    $count++;
                }
            }
            if (function_exists('todo_add_admin_notice')) {
                todo_add_admin_notice('已永久删除 ' . $count . ' 个分类。', 'success');
            }
            todo_redirect(todo_admin_url('index.php?page=trash&post_type=category'));
            exit;
        }
    }
}

// 分类回收站：处理单个操作（PRG）
if (isset($_GET['cat_action']) && isset($_GET['term'])) {
    $term_id = intval($_GET['term']);
    if (!current_user_can('manage_categories')) {
        $error = '您没有权限管理分类。';
    } else {
        if ($_GET['cat_action'] === 'untrash') {
            if (todo_untrash_term($term_id, 'category')) {
                $message = '分类已从回收站恢复。';
            } else {
                $error = '恢复分类时出错。';
            }
        } elseif ($_GET['cat_action'] === 'delete') {
            if (todo_delete_term($term_id, 'category')) {
                $message = '分类已永久删除。';
            } else {
                $error = '删除分类时出错。';
            }
        }
        // 使用PRG避免重复执行
        if (isset($message) || isset($error)) {
            $type = isset($error) ? 'error' : 'success';
            $msg = isset($error) ? $error : $message;
            if (function_exists('todo_add_admin_notice')) {
                todo_add_admin_notice($msg, $type);
            }
            todo_redirect(todo_admin_url('index.php?page=trash&post_type=category'));
            exit;
        }
    }
}

// 分类回收站：直接查询回收站中的分类（避免N+1查询）
global $tododb;
$terms_table = $tododb->prefix . 'terms';
$term_taxonomy_table = $tododb->prefix . 'term_taxonomy';
$term_attributes_table = TABLE_PREFIX . 'term_attributes';

// 构建查询条件
// 使用标准 JSON_EXTRACT 以提高兼容性，处理 trashed=1 或 trashed="1"
$where_conditions = ["(JSON_UNQUOTE(JSON_EXTRACT(ta.attributes, '$.trashed')) = '1')", "(JSON_UNQUOTE(JSON_EXTRACT(ta.attributes, '$.trashed_taxonomy')) = 'category')"];
$query_params = [];

// 搜索过滤
if ($cat_s !== '') {
    $where_conditions[] = "(t.name LIKE ? OR t.slug LIKE ?)";
    $search_term = '%' . $cat_s . '%';
    $query_params[] = $search_term;
    $query_params[] = $search_term;
}

$where_clause = implode(' AND ', $where_conditions);

// 查询回收站中的分类总数
$count_query = "
    SELECT COUNT(DISTINCT t.term_id)
    FROM $terms_table t
    INNER JOIN $term_taxonomy_table tt ON t.term_id = tt.term_id
    INNER JOIN $term_attributes_table ta ON t.term_id = ta.term_id
    WHERE tt.taxonomy = 'category' AND $where_clause
";
$cat_total = intval($tododb->get_var($count_query, $query_params));

// 分类分页
$cat_total_pages = max(1, ceil($cat_total / $cat_per_page));
$cat_offset = ($cat_paged - 1) * $cat_per_page;

// 查询当前页的回收站分类
$trashed_query = "
    SELECT t.term_id, t.name, t.slug, tt.description, tt.parent, tt.count
    FROM $terms_table t
    INNER JOIN $term_taxonomy_table tt ON t.term_id = tt.term_id
    INNER JOIN $term_attributes_table ta ON t.term_id = ta.term_id
    WHERE tt.taxonomy = 'category' AND $where_clause
    ORDER BY t.term_id ASC
    LIMIT $cat_offset, $cat_per_page
";
$trashed_categories_paged = $tododb->get_results($trashed_query, $query_params);

// 预加载分类元数据缓存，避免N+1查询
if (!empty($trashed_categories_paged)) {
    $term_ids = array_map(function ($cat) {
        return is_object($cat) ? $cat->term_id : $cat['term_id'];
    }, $trashed_categories_paged);
    update_term_meta_cache($term_ids);
}

// 分类索引（用于显示父级名称） - 仅在需要时查询
$category_index = array();

$trash_items = array();
$list_total_pages = 1;
$page_param_key = ($post_type_filter === 'category') ? 'cat_paged' : 'paged';

// 模型字段回收站逻辑
$field_total = 0;
if (current_user_can('manage_models') || current_user_can('manage_options')) {
    global $tododb;
    $field_total = intval($tododb->get_var("SELECT COUNT(*) FROM " . TABLE_PREFIX . "model_fields WHERE field_status = 'trash'"));
}

if ($post_type_filter === 'model_field') {
    global $tododb;
    $field_paged = isset($_GET['field_paged']) ? max(1, intval($_GET['field_paged'])) : 1;
    $field_per_page = 20;
    $field_offset = ($field_paged - 1) * $field_per_page;
    
    $field_query = "
        SELECT f.*, m.model_name 
        FROM " . TABLE_PREFIX . "model_fields f
        LEFT JOIN " . TABLE_PREFIX . "models m ON f.model_id = m.model_id
        WHERE f.field_status = 'trash'
        ORDER BY f.updated_at DESC
        LIMIT $field_offset, $field_per_page
    ";
    $trashed_fields = $tododb->get_results($field_query);
    foreach ($trashed_fields as $f) {
        $trash_items[] = array(
            'type' => 'model_field',
            'id' => intval($f->field_id),
            'title' => $f->field_name,
            'extra_info' => $f->field_key,
            'alias' => $f->model_name ?: '未知模型',
            'date' => date('Y/m/d H:i:s', strtotime($f->updated_at)),
            'can_untrash' => true,
            'can_delete' => true,
            'untrash_url' => 'index.php?page=trash&field_action=untrash&field=' . intval($f->field_id),
            'delete_url' => 'index.php?page=trash&field_action=delete&field=' . intval($f->field_id),
        );
    }
    $list_total_pages = max(1, ceil($field_total / $field_per_page));
    $page_param_key = 'field_paged';
}

// 全局变量回收站逻辑
$gv_trash_total = 0;
if (current_user_can('manage_global_vars') || current_user_can('manage_options')) {
    $all_gv_fields = get_option('global_vars_fields', array());
    foreach ($all_gv_fields as $f) {
        if (isset($f['field_status']) && $f['field_status'] === 'trash') {
            $gv_trash_total++;
        }
    }
}

// 默认进入回收站时，如果“文章”为空，则自动切换到第一个有数据的类型
// 避免用户落在空列表页面（交互上更符合“回收站应展示可恢复内容”的预期）
if ($post_type_filter === 'post' && $post_trash_filtered_total <= 0) {
    $candidates = array('category');
    // 搜索模式下，仅分类支持同样的搜索过滤；其他类型不做自动跳转，避免“搜索词失效”的错觉
    if (trim($s) === '' && (current_user_can('manage_models') || current_user_can('manage_global_vars') || current_user_can('manage_options'))) {
        $candidates[] = 'model_field';
        $candidates[] = 'global_var';
    }

    $totals = array(
        'category' => intval($cat_total),
        'model_field' => intval($field_total),
        'global_var' => intval($gv_trash_total),
    );

    $target = null;
    foreach ($candidates as $t) {
        if (isset($totals[$t]) && $totals[$t] > 0) {
            $target = $t;
            break;
        }
    }

    if ($target) {
        $query = $_GET;
        unset($query['page']);
        $query['post_type'] = $target;
        unset($query['paged'], $query['cat_paged'], $query['field_paged'], $query['gv_paged']);
        todo_redirect(todo_admin_url('index.php?page=trash&' . http_build_query($query)));
        exit;
    }
}

if ($post_type_filter === 'global_var') {
    $gv_paged = isset($_GET['gv_paged']) ? max(1, intval($_GET['gv_paged'])) : 1;
    $gv_per_page = 20;
    
    $all_gv_fields = get_option('global_vars_fields', array());
    $trashed_gv = [];
    foreach ($all_gv_fields as $idx => $f) {
        if (isset($f['field_status']) && $f['field_status'] === 'trash') {
            $trashed_gv[] = array(
                'idx' => $idx,
                'data' => $f
            );
        }
    }
    
    // 分页
    $start = ($gv_paged - 1) * $gv_per_page;
    $paged_gv = array_slice($trashed_gv, $start, $gv_per_page);
    
    foreach ($paged_gv as $item) {
        $f = $item['data'];
        $trash_items[] = array(
            'type' => 'global_var',
            'id' => $item['idx'],
            'title' => $f['field_name'],
            'extra_info' => $f['field_key'],
            'alias' => isset($field_types[$f['field_type']]) ? $field_types[$f['field_type']]['name'] : $f['field_type'],
            'date' => isset($f['trashed_at']) ? date('Y/m/d H:i:s', strtotime($f['trashed_at'])) : '-', // 选项中记录的删除日期
            'can_untrash' => true,
            'can_delete' => true,
            'untrash_url' => 'index.php?page=trash&gv_action=untrash&gv_idx=' . $item['idx'],
            'delete_url' => 'index.php?page=trash&gv_action=delete&gv_idx=' . $item['idx'],
        );
    }
    $list_total_pages = max(1, ceil($gv_trash_total / $gv_per_page));
    $page_param_key = 'gv_paged';
}

if ($post_type_filter === 'post') {
    foreach ($posts as $post) {
        // 获取作者信息
        $author = get_user_by('id', $post->post_author);
        $author_name = $author ? $author->display_name : '未知';
        
        // 使用文章所属分类名称作为类型标签
        $post_categories = array();
        if (function_exists('todo_get_post_categories')) {
            $post_categories = todo_get_post_categories($post->ID);
        } elseif (function_exists('get_the_category')) {
            $post_categories = get_the_category($post->ID);
        }
        $category_label = '—';
        if (!empty($post_categories)) {
            $cat_names = array();
            foreach ($post_categories as $c) {
                if (isset($c->name)) $cat_names[] = function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($c->name) : $c->name;
            }
            $category_label = implode(', ', $cat_names);
        }

        $trash_items[] = array(
            'type' => 'post',
            'id' => $post->ID,
            'title' => $post->post_title ?: '(无标题)',
            'author' => $author_name,
            'extra_info' => $category_label, // 分类信息
            'date' => date('Y/m/d H:i:s', strtotime($post->post_modified)),
            'can_untrash' => current_user_can('delete_post', $post->ID),
            'can_delete' => current_user_can('delete_post', $post->ID),
            'untrash_url' => 'index.php?page=trash&action=untrash&post=' . $post->ID,
            'delete_url' => 'index.php?page=trash&action=delete&post=' . $post->ID,
        );
    }
    $list_total_pages = $total_pages_num;
} elseif ($post_type_filter === 'category') {
    foreach ($trashed_categories_paged as $cat) {
        $trashed_at = get_term_meta($cat->term_id, 'trashed_at', true);
        $date = $trashed_at ? date('Y/m/d H:i:s', is_numeric($trashed_at) ? intval($trashed_at) : strtotime($trashed_at)) : '-';
        
        // 获取栏目绑定的模型名称用于显示
        $model_key = Todo_Model_Manager::get_category_model(intval($cat->term_id));
        $model_name = $model_key;
        if (Todo_Model_Manager::is_custom_model($model_key)) {
            $custom_model = Todo_Model_Manager::get_custom_model_instance($model_key);
            if ($custom_model) {
                $model_name = $custom_model->get_model_name() ?: $model_key;
            }
        } else {
            $class_name = Todo_Model_Manager::get_model_class($model_key);
            if ($class_name && property_exists($class_name, 'model_name')) {
                $model_name = $class_name::$model_name ?: $model_key;
            }
        }

        $trash_title = isset($cat->name) ? (function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($cat->name) : $cat->name) : '(未命名分类)';
        $trash_desc = $cat->description ? (function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($cat->description) : $cat->description) : '—';

        $trash_items[] = array(
            'type' => 'category',
            'id' => intval($cat->term_id),
            'title' => $trash_title,
            'extra_info' => $trash_desc, // 描述信息
            'alias' => ($model_name ?: '-'), // 模型名称
            'date' => $date,
            'can_untrash' => current_user_can('manage_categories'),
            'can_delete' => current_user_can('manage_categories'),
            'untrash_url' => 'index.php?page=trash&cat_action=untrash&term=' . intval($cat->term_id),
            'delete_url' => 'index.php?page=trash&cat_action=delete&term=' . intval($cat->term_id),
        );
    }
    $list_total_pages = $cat_total_pages;
}

$has_items = !empty($trash_items);
?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title verse">“落红不是无情物，化作春泥更护花。”<span>——龚自珍《己亥杂诗》</span></h2>
        <?php 
        $can_empty = ($post_type_filter === 'post' && $post_trash_filtered_total > 0) || ($post_type_filter === 'category' && $cat_total > 0) || ($post_type_filter === 'model_field' && $field_total > 0) || ($post_type_filter === 'global_var' && $gv_trash_total > 0);
        if ($can_empty): ?>
            <div class="admin-page-actions">
                <a href="<?php echo todo_admin_url('index.php?page=trash&action=empty_trash&post_type=' . $post_type_filter . '&_todononce=' . todo_create_nonce('empty_trash')); ?>" 
                    class="button button-secondary delete-button js-todo-post-action" data-confirm="确定要清空当前的回收站吗？此操作不可逆！">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" style="vertical-align: middle; margin: -10px 0 -7px -5px;"><g fill="none" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m21 3l-8 8.5 m-3.554-.415c-2.48.952-4.463.789-6.446.003c.5 6.443 3.504 8.92 7.509 9.912c0 0 3.017-2.134 3.452-7.193 c.047-.548.07-.821-.043-1.13c-.114-.309-.338-.53-.785-.973 c-.736-.728-1.103-1.092-1.54-1.184c-.437-.09-1.007.128-2.147.565"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4.5 16.446S7 16.93 9.5 15"/><path stroke-width="1.5" d="M8.5 7.25a1.25 1.25 0 1 1-2.5 0a1.25 1.25 0 0 1 2.5 0Z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 4v.1"/></g></svg>清空回收站
                </a>
            </div>
        <?php endif; ?>
    </div>

    <div class="admin-card">
        <div class="admin-card-content">
            <div class="admin-filter-bar">
                <ul class="admin-filters">
                    <li>
                        <a href="<?php echo add_query_arg('post_type', 'post'); ?>"
                            class="<?php echo $post_type_filter === 'post' ? 'current' : ''; ?>">文章 <span
                                class="count">(<?php echo $post_trash_filtered_total; ?>)</span></a>
                    </li>
                    <li>
                        <a href="<?php echo add_query_arg('post_type', 'category'); ?>"
                            class="<?php echo $post_type_filter === 'category' ? 'current' : ''; ?>">栏目分类 <span
                                class="count">(<?php echo $cat_total; ?>)</span></a>
                    </li>
                    <?php if (current_user_can('manage_models') || current_user_can('manage_global_vars') || current_user_can('manage_options')): ?>
                    <li>
                        <a href="<?php echo add_query_arg('post_type', 'model_field'); ?>"
                            class="<?php echo $post_type_filter === 'model_field' ? 'current' : ''; ?>">模型字段 <span
                                class="count">(<?php echo $field_total; ?>)</span></a>
                    </li>
                    <li>
                        <a href="<?php echo add_query_arg('post_type', 'global_var'); ?>"
                            class="<?php echo $post_type_filter === 'global_var' ? 'current' : ''; ?>">全局变量 <span
                                class="count">(<?php echo $gv_trash_total; ?>)</span></a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>

            <?php if ($has_items): ?>
                <form class="admin-posts-filter" method="post" action="">
                    <div class="bulk-actions-bar">
                        <div class="bulk-actions">
                            <select name="action">
                                <option value="-1">批量操作</option>
                                <option value="untrash">从回收站恢复</option>
                                <option value="delete">永久删除</option>
                            </select>
                            <input type="hidden" name="item_type"
                                value="<?php echo esc_attr($post_type_filter); ?>">
                            <?php echo Todo_Security_Manager::get_csrf_field('trash_action'); ?>
                            <input type="submit" class="button button-secondary" value="应用">
                        </div>

                        <div class="bulk-actions">
                            <input type="search" name="s"
                                value="<?php echo esc_attr($s); ?>"
                                placeholder="搜索回收站内容...">
                            <input type="hidden" name="post_type" value="<?php echo esc_attr($post_type_filter); ?>">
                            <input type="submit" class="button button-secondary" value="搜索">
                        </div>
                    </div>

                    <table class="admin-table">
                        <thead>
                            <tr>
                                <td class="check-column">
                                    <input type="checkbox" id="cb-select-all">
                                </td>
                                <?php if ($post_type_filter === 'post'): ?>
                                    <th style="min-width: 260px;">标题</th>
                                    <th>作者</th>
                                    <th style="min-width: 100px;">分类</th>
                                <?php elseif ($post_type_filter === 'category'): ?>
                                    <th style="min-width: 160px;">名称</th>
                                    <th style="min-width: 200px;">描述</th>
                                    <th>数据模型</th>
                                <?php elseif ($post_type_filter === 'model_field'): ?>
                                    <th style="min-width: 160px;">字段名称</th>
                                    <th style="min-width: 200px;">标识(Key)</th>
                                    <th>所属模型</th>
                                <?php elseif ($post_type_filter === 'global_var'): ?>
                                    <th style="min-width: 200px;">变量名称</th>
                                    <th style="min-width: 200px;">标识(Key)</th>
                                    <th>变量类型</th>
                                <?php endif; ?>
                                <th style="width: 190px; min-width: 190px;">删除日期</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trash_items as $item): ?>
                                <?php
                                $actions = array();
                                if ($item['type'] === 'post') {
                                    if ($item['can_untrash']) {
                                        $actions['untrash'] = sprintf('<span><a href="%s">恢复</a></span>', $item['untrash_url']);
                                    }
                                    if ($item['can_delete']) {
                                        $actions['delete'] = sprintf('<span><a href="%s" class="submitdelete" onclick="return TodoCMS.Utils.confirmAction(this, \'确定要永久删除这篇文章吗？\');">永久删除</a></span>', $item['delete_url']);
                                    }
                                } elseif ($item['type'] === 'category') {
                                    if ($item['can_untrash']) {
                                        $actions['untrash'] = sprintf('<span><a href="%s">恢复</a></span>', $item['untrash_url']);
                                    }
                                    if ($item['can_delete']) {
                                        $actions['delete'] = sprintf('<span><a href="%s" class="submitdelete" onclick="return TodoCMS.Utils.confirmAction(this, \'确定要永久删除该分类吗？\');">永久删除</a></span>', $item['delete_url']);
                                    }
                                } elseif ($item['type'] === 'model_field') {
                                    if ($item['can_untrash']) {
                                        $actions['untrash'] = sprintf('<span><a href="%s">恢复</a></span>', $item['untrash_url']);
                                    }
                                    if ($item['can_delete']) {
                                        $actions['delete'] = sprintf('<span><a href="%s" class="submitdelete" onclick="return TodoCMS.Utils.confirmAction(this, \'确定要永久删除该字段吗？此操作将同时清理相关内容中的字段数据且不可恢复！\');">永久删除</a></span>', $item['delete_url']);
                                    }
                                } elseif ($item['type'] === 'global_var') {
                                    if ($item['can_untrash']) {
                                        $actions['untrash'] = sprintf('<span><a href="#" class="js-trash-gv-action" data-action="untrash" data-idx="%s">恢复</a></span>', intval($item['id']));
                                    }
                                    if ($item['can_delete']) {
                                        $actions['delete'] = sprintf('<span><a href="#" class="submitdelete js-trash-gv-action" data-action="delete" data-idx="%s">永久删除</a></span>', intval($item['id']));
                                    }
                                }
                                ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="items[]"
                                            value="<?php echo intval($item['id']); ?>"
                                            data-type="<?php echo esc_attr($item['type']); ?>">
                                    </td>
                                    <td>
                                        <strong class="row-title"><?php echo esc_html($item['title']); ?></strong>
                                        <div class="row-actions">
                                            <?php echo implode(' | ', $actions); ?>
                                        </div>
                                    </td>
                                    <?php if ($post_type_filter === 'post'): ?>
                                        <td><?php echo esc_html($item['author']); ?></td>
                                        <td><?php echo esc_html($item['extra_info']); ?></td>
                                    <?php elseif ($post_type_filter === 'category'): ?>
                                        <td><div class="tb-description"><?php echo $item['extra_info']; ?></div></td>
                                        <td><?php echo esc_html($item['alias']); ?></td>
                                    <?php elseif ($post_type_filter === 'model_field'): ?>
                                        <td><code><?php echo esc_html($item['extra_info']); ?></code></td>
                                        <td><?php echo esc_html($item['alias']); ?></td>
                                    <?php elseif ($post_type_filter === 'global_var'): ?>
                                        <td><code><?php echo esc_html($item['extra_info']); ?></code></td>
                                        <td><?php echo esc_html($item['alias']); ?></td>
                                    <?php endif; ?>
                                    <td><?php echo esc_html($item['date']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="bulk-actions-bar" style="margin: 20px 0 0;">
                        <div class="bulk-actions">
                            <select name="action2">
                                <option value="-1">批量操作</option>
                                <option value="untrash">从回收站恢复</option>
                                <option value="delete">永久删除</option>
                            </select>
                            <input type="hidden" name="item_type"
                                value="<?php echo esc_attr($post_type_filter); ?>">
                            <?php echo Todo_Security_Manager::get_csrf_field('trash_action'); ?>
                            <input type="submit" class="button button-secondary" value="应用">
                        </div>

                        <?php if ($list_total_pages > 1): ?>
                            <div class="admin-pagination">
                                <?php
                                echo paginate_links(array(
                                    'total' => ($post_type_filter === 'category') ? $cat_total : (($post_type_filter === 'model_field') ? $field_total : (($post_type_filter === 'global_var') ? $gv_trash_total : $filtered_count)),
                                    'per_page' => ($post_type_filter === 'category') ? $cat_per_page : (($post_type_filter === 'model_field') ? $field_per_page : (($post_type_filter === 'global_var') ? $gv_per_page : $per_page)),
                                    'current_page' => ($post_type_filter === 'category') ? $cat_paged : (($post_type_filter === 'model_field') ? $field_paged : (($post_type_filter === 'global_var') ? $gv_paged : $paged)),
                                    'base_url' => remove_query_arg($page_param_key),
                                    'page_param' => $page_param_key
                                ));
                                ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </form>
            <?php else: ?>
                <div class="no-items">
                    <svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" viewBox="0 0 80 80"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" d="m61 20l-4.626 44.414A4 4 0 0 1 52.395 68h-24.79a4 4 0 0 1-3.979-3.586L19 20m46 0H15m12.856-.001l6.07-7.612h12.149l6.07 7.612" stroke-width="1"/></svg>
                    <h3>回收站是空的</h3>
                    <p class="description">当前<?php 
                        if ($post_type_filter === 'category') echo ' 分类 回收站';
                        elseif ($post_type_filter === 'model_field') echo ' 字段 回收站';
                        elseif ($post_type_filter === 'global_var') echo ' 全局变量 回收站';
                        else echo ' 文章 回收站';
                    ?>中没有任何内容。</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script type="text/javascript">
    // 全选功能
    document.addEventListener('DOMContentLoaded', function () {
        // 文章列表全选
        var selectAllPosts = document.getElementById('cb-select-all');
        if (selectAllPosts) {
            selectAllPosts.addEventListener('change', function () {
                const form = selectAllPosts.closest('form');
                const checkboxes = form ? form.querySelectorAll('input[type="checkbox"][name="items[]"]') : document.querySelectorAll('input[type="checkbox"][name="items[]"]');
                checkboxes.forEach(checkbox => {
                    checkbox.checked = this.checked;
                });
            });
        }

        document.addEventListener('click', function (e) {
            var link = e.target && e.target.closest ? e.target.closest('a.js-trash-gv-action') : null;
            if (!link) return;

            e.preventDefault();
            e.stopPropagation();

            var action = link.getAttribute('data-action') || '';
            var idx = link.getAttribute('data-idx') || '';
            if (!action || idx === '') return;

            var formInPage = link.closest('form');
            var csrfInput = formInPage ? formInPage.querySelector('input[name="_todononce"]') : null;
            var csrfToken = csrfInput ? csrfInput.value : '';

            var submitSingle = function () {
                var f = document.createElement('form');
                f.method = 'POST';
                f.action = window.location.pathname + window.location.search;

                var actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = action;
                f.appendChild(actionInput);

                var itemTypeInput = document.createElement('input');
                itemTypeInput.type = 'hidden';
                itemTypeInput.name = 'item_type';
                itemTypeInput.value = 'global_var';
                f.appendChild(itemTypeInput);

                var itemInput = document.createElement('input');
                itemInput.type = 'hidden';
                itemInput.name = 'items[]';
                itemInput.value = idx;
                f.appendChild(itemInput);

                if (csrfToken) {
                    var csrf = document.createElement('input');
                    csrf.type = 'hidden';
                    csrf.name = '_todononce';
                    csrf.value = csrfToken;
                    f.appendChild(csrf);
                }

                document.body.appendChild(f);
                f.submit();
            };

            if (action === 'delete') {
                if (window.TodoModal && typeof window.TodoModal.deleteConfirm === 'function') {
                    window.TodoModal.deleteConfirm({
                        title: '确认删除',
                        message: '确定要永久删除该全局变量及其所有数据吗？',
                        confirmText: '永久删除',
                        onConfirm: submitSingle
                    });
                    return;
                }
                if (window.confirm('确定要永久删除该全局变量及其所有数据吗？')) {
                    submitSingle();
                }
                return;
            }

            submitSingle();
        }, true);
    });
</script>
