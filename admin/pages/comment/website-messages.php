<?php
/**
 * 网站留言管理页面
 * 
 * @package TodoCMS
 * @subpackage Admin/Comment
 */

// 防止直接访问
if (!defined('ADMIN_DIR') && !defined('TODO_LOADED')) {
    http_response_code(403);
    exit('Direct access denied');
}

// 检查是否在管理后台环境
if (!defined('TODO_ADMIN') || !TODO_ADMIN) {
    http_response_code(403);
    exit('Access denied: Admin area required');
}

// 确保数据库连接已初始化（在会话检查之前）
if (!isset($tododb)) {
    global $tododb;
    if (!isset($tododb)) {
        $tododb = new CMS_DB();
    }
}

// 检查用户登录状态
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 使用 Todo_Session_Manager 检查后台登录状态
if (!class_exists('Todo_Session_Manager')) {
    if (!defined('TODO_LOADED')) {
        define('TODO_LOADED', true);
    }
}
if (class_exists('Todo_Session_Manager')) {
    Todo_Session_Manager::init();
}

if (!class_exists('Todo_Session_Manager') || !Todo_Session_Manager::is_admin_logged_in()) {
    http_response_code(403);
    todo_die('You must be logged in to access this page.', '系统错误', array('response' => 403));
}

// 检查用户权限
if (!current_user_can('manage_comments')) {
    http_response_code(403);
    todo_die('You do not have sufficient permissions to access this page.', '系统错误', array('response' => 403));
}

// 获取留言模型的栏目（用于筛选：显示“分类名称”而不是文章）
$gb_model = $tododb->get_row("SELECT model_id FROM {$tododb->prefix}models WHERE model_key = 'guestbook'");
$guestbook_model_id = $gb_model ? intval($gb_model->model_id) : 0;
$guestbook_categories = $guestbook_model_id > 0 ? get_terms_by_model($guestbook_model_id) : [];

/**
 * 解析 model_fields.field_options 为 value => label 映射
 */
function parse_field_options_to_label_map($raw) {
    $map = [];
    if (empty($raw) || !is_string($raw)) return $map;
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        if (isset($decoded['options']) && is_string($decoded['options'])) {
            $raw = $decoded['options'];
        } elseif (isset($decoded['options']) && is_array($decoded['options'])) {
            return $decoded['options'];
        } else {
            return $decoded;
        }
    }
    $unser = function_exists('maybe_unserialize') ? maybe_unserialize($raw) : @unserialize($raw);
    if (is_array($unser)) {
        if (isset($unser['options']) && is_string($unser['options'])) {
            $raw = $unser['options'];
        } elseif (isset($unser['options']) && is_array($unser['options'])) {
            return $unser['options'];
        }
    }
    $lines = preg_split('/\r\n|\r|\n/', trim($raw));
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (strpos($line, '|') !== false) {
            list($v, $l) = explode('|', $line, 2);
            $map[trim($v)] = trim($l);
        }
    }
    return $map;
}

$guestbook_field_label_map = [];
$guestbook_field_type_map  = [];
$guestbook_field_option_labels = []; // field_key => [option_value => option_label]
if ($guestbook_model_id > 0) {
    try {
        $rows = $tododb->get_results(
            "SELECT field_key, field_label, field_name, field_type, field_options FROM {$tododb->prefix}model_fields WHERE model_id = ?",
            [$guestbook_model_id]
        );
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (!is_object($r)) continue;
                $k = (string) ($r->field_key ?? '');
                if ($k === '') continue;
                $label = (string) ($r->field_label ?? '');
                if ($label === '') $label = (string) ($r->field_name ?? '');
                if ($label === '') $label = $k;
                $guestbook_field_label_map[$k] = $label;
                $guestbook_field_type_map[$k]  = (string) ($r->field_type ?? 'text');

                // 解析选项数据，构建 value -> label 映射
                $ft  = $guestbook_field_type_map[$k];
                $raw = $r->field_options ?? '';
                if (in_array($ft, ['select', 'radio', 'checkbox'], true) && $raw !== '') {
                    $guestbook_field_option_labels[$k] = parse_field_options_to_label_map($raw);
                }
            }
        }
    } catch (Throwable $e) {
        $guestbook_field_label_map = [];
        $guestbook_field_type_map  = [];
        $guestbook_field_option_labels = [];
    }
}

$guestbook_category_ids = [];
$guestbook_category_name_map = [];
foreach ($guestbook_categories as $cat) {
    $term_id = is_array($cat) ? intval($cat['term_id'] ?? 0) : intval($cat->term_id ?? 0);
    if ($term_id <= 0) continue;
    $name = is_array($cat) ? (string) ($cat['name'] ?? '') : (string) ($cat->name ?? '');
    if (function_exists('todo_parse_inline_translations')) {
        $name = todo_parse_inline_translations($name);
    }
    $guestbook_category_ids[] = $term_id;
    $guestbook_category_name_map[$term_id] = $name;
}
$guestbook_category_ids = array_values(array_unique(array_filter($guestbook_category_ids, function ($v) {
    return intval($v) > 0;
})));

// 将“分类 term_id”映射到实际承载留言的文章 ID（comment_post_ID 指向 post_id）
$guestbook_post_ids = [];
$guestbook_post_ids_by_category = [];
$guestbook_post_category_map = [];
try {
    if (!empty($guestbook_category_ids)) {
        $posts_table = $tododb->prefix . 'posts';
        $placeholders = implode(',', array_fill(0, count($guestbook_category_ids), '?'));
        $rows = $tododb->get_results(
            "SELECT ID, category_id 
             FROM {$posts_table}
             WHERE category_id IN ({$placeholders})
               AND post_status <> 'trash'
               AND (post_type = 'guestbook' OR model_id = ?)",
            array_merge($guestbook_category_ids, [$guestbook_model_id])
        );
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (!is_object($r) || empty($r->ID)) continue;
                $pid = (int) $r->ID;
                $cid = (int) ($r->category_id ?? 0);
                if ($pid <= 0 || $cid <= 0) continue;
                $guestbook_post_ids[] = $pid;
                $guestbook_post_category_map[$pid] = $cid;
                if (!isset($guestbook_post_ids_by_category[$cid])) {
                    $guestbook_post_ids_by_category[$cid] = [];
                }
                $guestbook_post_ids_by_category[$cid][] = $pid;
            }
        }
    }
} catch (Throwable $e) {
    $guestbook_post_ids = [];
    $guestbook_post_ids_by_category = [];
    $guestbook_post_category_map = [];
}
$guestbook_post_ids = array_values(array_unique($guestbook_post_ids));

$message = $message ?? null;
$message_type = $message_type ?? 'success';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = sanitize_text_field($_POST['action'] ?? '');
    $message_id = intval($_POST['message_id'] ?? 0);

    if ($message_id > 0 && in_array($action, ['delete'], true)) {
        $nonce = (string) ($_POST['_todononce'] ?? '');
        $nonce_action = $action . '_message_' . $message_id;
        if (function_exists('todo_verify_nonce') && ($nonce === '' || !todo_verify_nonce($nonce, $nonce_action))) {
            if (function_exists('todo_add_admin_notice')) {
                todo_add_admin_notice('安全验证失败，请刷新页面后重试。', 'error');
            }
            todo_redirect(todo_admin_url('index.php?page=website-messages'));
            exit;
        }

        $ok = false;
        $maybe_comment = function_exists('todo_get_comment') ? todo_get_comment($message_id) : null;
        if ($maybe_comment) {
            $ok = function_exists('todo_delete_comment') ? todo_delete_comment($message_id, true) : false;
        } else {
            $ok = function_exists('todo_delete_post') ? todo_delete_post($message_id, true) : false;
        }
        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice($ok ? '留言已永久删除（包含所有回复）。' : '操作失败，请稍后重试。', $ok ? 'success' : 'error');
        }
        todo_redirect(todo_admin_url('index.php?page=website-messages'));
        exit;
    }
}

// 处理批量操作（仅作用于主留言）
if (isset($_POST['action']) && isset($_POST['message_ids']) && is_array($_POST['message_ids'])) {
    if (!class_exists('Todo_Security_Manager') || !Todo_Security_Manager::verify_request_csrf('bulk_action_messages')) {
        $message = '安全验证失败，请刷新页面后重试。';
        $message_type = 'error';
    } else {
        $action = sanitize_text_field($_POST['action']);
        $message_ids = array_map('intval', $_POST['message_ids']);
        $message_ids = array_values(array_filter($message_ids, function ($id) {
            return $id > 0;
        }));

        $processed = 0;
        switch ($action) {
            case 'approve':
                foreach ($message_ids as $id) {
                    if (function_exists('todo_publish_post') && todo_publish_post($id)) $processed++;
                }
                $message = "已批准 {$processed} 条留言";
                break;
            case 'pending':
                foreach ($message_ids as $id) {
                    $ok = false;
                    try {
                        $ok = $tododb->update("{$tododb->prefix}posts", ['post_status' => 'draft'], ['ID' => $id]) !== false;
                    } catch (Throwable $e) {
                        $ok = false;
                    }
                    if ($ok) $processed++;
                }
                $message = "已设置 {$processed} 条留言为待审核";
                break;
            case 'spam':
                foreach ($message_ids as $id) {
                    if (function_exists('todo_trash_post') && todo_trash_post($id)) $processed++;
                }
                $message = "已标记 {$processed} 条留言为垃圾信息";
                break;
            case 'trash':
                foreach ($message_ids as $id) {
                    if (function_exists('todo_trash_post') && todo_trash_post($id)) $processed++;
                }
                $message = "已将 {$processed} 条留言移至回收站";
                break;
            case 'delete':
                foreach ($message_ids as $id) {
                    if (function_exists('todo_delete_post') && todo_delete_post($id, true)) $processed++;
                }
                $message = "已永久删除 {$processed} 条留言（包含所有回复）";
                break;
            case 'export':
                export_messages($message_ids);
                exit;
        }
    }
}

// 获取筛选参数
$status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
$form_type = isset($_GET['form_type']) ? sanitize_text_field($_GET['form_type']) : 'all';
$search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
$paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 20;
$offset = ($paged - 1) * $per_page;

// 获取留言文章（留言模型的文章：标题+内容作为留言）
$messages = [];
$total_items = 0;
$total_pages = 0;
$status_counts = ['all' => 0, 'pending' => 0, 'approved' => 0, 'spam' => 0, 'trash' => 0, 'replied' => 0];

if (!empty($guestbook_category_ids)) {
    $posts_table = $tododb->prefix . 'posts';
    $comments_table = $tododb->prefix . 'comments';

    $selected_category_ids = $guestbook_category_ids;
    if ($form_type !== 'all') {
        $ft = intval($form_type);
        $selected_category_ids = in_array($ft, $guestbook_category_ids, true) ? [$ft] : [];
    }

    $base_where = [];
    $base_params = [];
    if (!empty($selected_category_ids)) {
        $placeholders = implode(',', array_fill(0, count($selected_category_ids), '?'));
        $base_where[] = "p.category_id IN ({$placeholders})";
        $base_params = array_merge($base_params, $selected_category_ids);
    } else {
        $base_where[] = "1 = 0";
    }

    $where = $base_where;
    $params = $base_params;

    switch ($status) {
        case 'trash':
            $where[] = "p.post_status = 'trash'";
            break;
        case 'approved':
            $where[] = "p.post_status = 'publish'";
            break;
        case 'pending':
            $where[] = "p.post_status IN ('draft','pending')";
            break;
        case 'replied':
            $where[] = "p.post_status <> 'trash'";
            $where[] = "EXISTS (SELECT 1 FROM {$comments_table} c WHERE c.comment_post_ID = p.ID AND c.comment_approved NOT IN ('trash','spam'))";
            break;
        case 'spam':
            $where[] = "1 = 0";
            break;
        default:
            $where[] = "p.post_status <> 'trash'";
            break;
    }

    if ($search !== '') {
        $kw = '%' . $search . '%';
        $where[] = "(p.post_title LIKE ? OR p.post_content LIKE ?)";
        $params[] = $kw;
        $params[] = $kw;
    }

    $where_sql = 'WHERE ' . implode(' AND ', $where);

    $total_items = (int) $tododb->get_var("SELECT COUNT(*) FROM {$posts_table} p {$where_sql}", $params);
    $total_pages = $total_items > 0 ? (int) ceil($total_items / $per_page) : 0;

    $posts = $tododb->get_results(
        "SELECT p.* FROM {$posts_table} p {$where_sql} ORDER BY p.post_date DESC, p.ID DESC LIMIT {$offset}, {$per_page}",
        $params
    );
    $posts = is_array($posts) ? $posts : [];

    $post_ids = [];
    foreach ($posts as $p) {
        if (is_object($p) && !empty($p->ID)) $post_ids[] = (int) $p->ID;
    }

    $cf_map = [];
    if (!empty($post_ids)) {
        $ph = implode(',', array_fill(0, count($post_ids), '?'));
        $rows = $tododb->get_results(
            "SELECT post_id, field_key, field_value FROM {$tododb->prefix}post_custom_fields WHERE post_id IN ({$ph})",
            $post_ids
        );
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (!is_object($r)) continue;
                $pid = (int) ($r->post_id ?? 0);
                $k = (string) ($r->field_key ?? '');
                if ($pid <= 0 || $k === '') continue;
                if (!isset($cf_map[$pid])) $cf_map[$pid] = [];
                $val = $r->field_value ?? '';
                if (function_exists('maybe_unserialize')) {
                    $val = maybe_unserialize($val);
                }
                $cf_map[$pid][$k] = $val;
            }
        }

        $mf_rows = $tododb->get_results(
            "SELECT * FROM {$tododb->prefix}model_fields_guestbook WHERE post_id IN ({$ph})",
            $post_ids
        );
        if (is_array($mf_rows)) {
            foreach ($mf_rows as $r) {
                if (!is_object($r)) continue;
                $pid = (int) ($r->post_id ?? 0);
                if ($pid <= 0) continue;
                if (!isset($cf_map[$pid])) $cf_map[$pid] = [];
                $arr = (array) $r;
                foreach ($arr as $k => $v) {
                    if (!is_string($k) || $k === '' || in_array($k, ['field_id', 'post_id', 'created_at', 'updated_at'], true)) {
                        continue;
                    }
                    $cf_map[$pid][$k] = is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
                }
            }
        }
    }

        $reply_count_map = [];
    $comments_by_post = [];
    $all_user_ids = [];

    foreach ($posts as $p) {
        if (is_object($p) && !empty($p->post_author)) {
            $uid = intval($p->post_author);
            if ($uid > 0) {
                $all_user_ids[$uid] = $uid;
            }
        }
    }

    if (!empty($post_ids)) {
        $ph = implode(',', array_fill(0, count($post_ids), '?'));
        $rows = $tododb->get_results(
            "SELECT comment_post_ID AS post_id, COUNT(*) AS cnt FROM {$comments_table} WHERE comment_post_ID IN ({$ph}) AND comment_approved = '1' GROUP BY comment_post_ID",
            $post_ids
        );
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (!is_object($r)) continue;
                $pid = (int) ($r->post_id ?? 0);
                $reply_count_map[$pid] = (int) ($r->cnt ?? 0);
            }
        }

        // Batch fetch comments for all posts on current page in 1 query
        $raw_comments = $tododb->get_results(
            "SELECT c.*, p.post_title as target_title 
             FROM {$comments_table} c 
             LEFT JOIN {$posts_table} p ON c.comment_post_ID = p.ID 
             WHERE c.comment_post_ID IN ({$ph}) 
             ORDER BY c.comment_date ASC",
            $post_ids
        );
        if (is_array($raw_comments)) {
            foreach ($raw_comments as $c) {
                if (!is_object($c)) continue;
                $pid = (int) ($c->comment_post_ID ?? 0);
                if (!isset($comments_by_post[$pid])) {
                    $comments_by_post[$pid] = [];
                }
                $comments_by_post[$pid][] = $c;

                $uid = intval($c->user_id ?? 0);
                if ($uid > 0) {
                    $all_user_ids[$uid] = $uid;
                }
            }
        }
    }

    // Preload user cache in batch to eliminate N+1 queries
    if (!empty($all_user_ids) && class_exists('CMS_User')) {
        CMS_User::prime_cache(array_values($all_user_ids));
    }

    foreach ($posts as $p) {
        if (!is_object($p) || empty($p->ID)) continue;
        $pid = (int) $p->ID;
        $cf = $cf_map[$pid] ?? [];

        $author_name = '管理员';
        $author_email = '';
        if (function_exists('get_user_by')) {
            $u = get_user_by('id', intval($p->post_author ?? 0));
            if ($u) {
                $author_name = (string) ($u->display_name ?? $u->user_login ?? $author_name);
                $author_email = (string) ($u->user_email ?? '');
            }
        }

        $msg = new stdClass();
        $msg->id = $pid;
        $msg->name = (string) ($cf['contact_name'] ?? $author_name);
        $msg->email = (string) ($cf['contact_email'] ?? $author_email);
        $msg->phone = (string) ($cf['contact_phone'] ?? '');
        $msg->subject = (string) ($p->post_title ?? '');
        $msg->content = (string) ($p->post_content ?? '');

        $cid = intval($p->category_id ?? 0);
        $msg->form_type = ($cid > 0 && isset($guestbook_category_name_map[$cid])) ? (string) $guestbook_category_name_map[$cid] : '默认';

        $has_replies = !empty($reply_count_map[$pid]);
        $p_status = (string) ($p->post_status ?? '');
        if ($p_status === 'trash') {
            $msg->status = 'trash';
        } elseif ($has_replies) {
            $msg->status = 'replied';
        } elseif ($p_status === 'publish') {
            $msg->status = 'approved';
        } else {
            $msg->status = 'pending';
        }
        $msg->approval_status = $msg->status;
        $msg->created_at = (string) ($p->post_date ?? $p->post_modified ?? '');

        $extra_cf = $cf;
        foreach (['contact_name', 'contact_email', 'contact_phone', 'subject', 'message_status'] as $rm_key) {
            unset($extra_cf[$rm_key]);
        }
        foreach ($extra_cf as $k => $v) {
            if (is_string($k) && strpos($k, '_') === 0) {
                unset($extra_cf[$k]);
            }
        }
        $msg->custom_fields = json_encode($extra_cf);

        $replies = [];
        $raw_list = $comments_by_post[$pid] ?? [];
        if (!empty($raw_list)) {
            $by_id = [];
            $children_map = [];
            foreach ($raw_list as $c_item) {
                $cid_val = (int) $c_item->comment_ID;
                $c_copy = clone $c_item;
                $c_copy->children = [];
                $by_id[$cid_val] = $c_copy;
                $parent_id = (int) ($c_item->comment_parent ?? 0);
                $children_map[$parent_id][] = $cid_val;
            }
            foreach ($children_map as $parent_id => $child_ids) {
                if ($parent_id > 0 && isset($by_id[$parent_id])) {
                    foreach ($child_ids as $cid_val) {
                        $by_id[$parent_id]->children[] = $by_id[$cid_val];
                    }
                }
            }
            $reply_roots = [];
            if (isset($children_map[0])) {
                foreach ($children_map[0] as $cid_val) {
                    $reply_roots[] = $by_id[$cid_val];
                }
            }

            $stack = [];
            $push = function ($items, $depth, $parentAuthor = '') use (&$stack) {
                if (!is_array($items)) return;
                foreach (array_reverse($items) as $it) {
                    $stack[] = ['c' => $it, 'd' => $depth, 'pa' => $parentAuthor];
                }
            };
            $push($reply_roots, 1, '');
            while (!empty($stack)) {
                $node = array_pop($stack);
                $c = $node['c'];
                $d = (int) $node['d'];
                $pa = (string) ($node['pa'] ?? '');
                if (!is_object($c) || empty($c->comment_ID)) continue;
                $r_status_raw = (string) ($c->comment_approved ?? '');
                $r_approval_status = ($r_status_raw === '1') ? 'approved' : (($r_status_raw === '0') ? 'pending' : $r_status_raw);
                $r_has_approved_descendant = function ($node) use (&$r_has_approved_descendant) {
                    if (!is_object($node) || empty($node->children) || !is_array($node->children)) {
                        return false;
                    }
                    foreach ($node->children as $child) {
                        if (!is_object($child)) {
                            continue;
                        }
                        $st = (string) ($child->comment_approved ?? '');
                        if ($st === '1') {
                            return true;
                        }
                        if ($r_has_approved_descendant($child)) {
                            return true;
                        }
                    }
                    return false;
                };
                $r_display_status = ($r_has_approved_descendant($c) && !in_array($r_approval_status, ['spam', 'trash'], true)) ? 'replied' : $r_approval_status;
                $reply_email = (string) ($c->comment_author_email ?? '');
                if ($reply_email === '') {
                    $reply_user_id = intval($c->user_id ?? 0);
                    if ($reply_user_id > 0) {
                        if (function_exists('todo_get_user')) {
                            $u = todo_get_user($reply_user_id);
                            if ($u && !empty($u->user_email)) {
                                $reply_email = (string) $u->user_email;
                            }
                        } elseif (function_exists('get_user_by')) {
                            $u = get_user_by('id', $reply_user_id);
                            if ($u && !empty($u->user_email)) {
                                $reply_email = (string) $u->user_email;
                            }
                        }
                    }
                }
                $c_author = (string) ($c->comment_author ?? '回复');
                $replies[] = (object) [
                    'id' => (int) $c->comment_ID,
                    'author' => $c_author,
                    'parent_author' => $pa,
                    'email' => $reply_email,
                    'content' => (string) ($c->comment_content ?? ''),
                    'created_at' => (string) ($c->comment_date ?? ''),
                    'depth' => $d,
                    'approval_status' => $r_approval_status,
                    'status' => $r_display_status,
                ];
                if (!empty($c->children) && is_array($c->children)) {
                    $push($c->children, $d + 1, $c_author);
                }
            }
        }
        $msg->replies = $replies;

        $messages[] = $msg;
    }

    $status_counts = ['all' => 0, 'approved' => 0, 'pending' => 0, 'trash' => 0, 'replied' => 0];
    if (!empty($guestbook_category_ids)) {
        $all_placeholders = implode(',', array_fill(0, count($guestbook_category_ids), '?'));
        $all_params = $guestbook_category_ids;

        $grouped_status = $tododb->get_results(
            "SELECT post_status, COUNT(*) as cnt FROM {$posts_table} WHERE category_id IN ({$all_placeholders}) GROUP BY post_status",
            $all_params
        );
        if (is_array($grouped_status)) {
            foreach ($grouped_status as $st_row) {
                if (!is_object($st_row)) continue;
                $st = (string) ($st_row->post_status ?? '');
                $cnt = (int) ($st_row->cnt ?? 0);
                if ($st === 'publish') {
                    $status_counts['approved'] += $cnt;
                    $status_counts['all'] += $cnt;
                } elseif (in_array($st, ['draft', 'pending'], true)) {
                    $status_counts['pending'] += $cnt;
                    $status_counts['all'] += $cnt;
                } elseif ($st === 'trash') {
                    $status_counts['trash'] += $cnt;
                } else {
                    $status_counts['all'] += $cnt;
                }
            }
        }

        $status_counts['replied'] = (int) $tododb->get_var(
            "SELECT COUNT(*) FROM {$posts_table} p WHERE p.category_id IN ({$all_placeholders}) AND p.post_status <> 'trash' AND EXISTS (SELECT 1 FROM {$comments_table} c WHERE c.comment_post_ID = p.ID AND c.comment_approved = '1')",
            $all_params
        );
    }
}
 

// 导出功能
function export_messages($message_ids)
{
    global $tododb;

    $ids = array_map('intval', $message_ids);
    if (empty($ids)) {
        return;
    }

    // 导出时必须清空已输出的后台HTML缓冲（本页面在后台头部之后被include）
    // 否则下载内容会混入 <!DOCTYPE html> 等无效内容，并导致编码错乱
    try {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    } catch (Throwable $e) {
    }
    if (function_exists('header_remove')) {
        header_remove();
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=website_messages_' . date('Y-m-d') . '.csv');
    header('X-Content-Type-Options: nosniff');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    $guestbook_model_id = 0;
    $label_map = [];
    try {
        $gb_model = $tododb->get_row("SELECT model_id FROM {$tododb->prefix}models WHERE model_key = 'guestbook'");
        $guestbook_model_id = $gb_model ? intval($gb_model->model_id) : 0;
        if ($guestbook_model_id > 0) {
            $rows = $tododb->get_results(
                "SELECT field_key, field_label, field_name FROM {$tododb->prefix}model_fields WHERE model_id = ?",
                [$guestbook_model_id]
            );
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    if (!is_object($r)) continue;
                    $k = (string) ($r->field_key ?? '');
                    if ($k === '') continue;
                    $label = (string) ($r->field_label ?? '');
                    if ($label === '') $label = (string) ($r->field_name ?? '');
                    if ($label === '') $label = $k;
                    $label_map[$k] = $label;
                }
            }
        }
    } catch (Throwable $e) {
        $guestbook_model_id = 0;
        $label_map = [];
    }

    $posts_table = $tododb->prefix . 'posts';
    $ph = implode(',', array_fill(0, count($ids), '?'));

    $posts = $tododb->get_results("SELECT * FROM {$posts_table} WHERE ID IN ({$ph}) ORDER BY ID DESC", $ids);
    $posts = is_array($posts) ? $posts : [];

    $cf_map = [];
    $all_keys = [];
    $cf_rows = $tododb->get_results("SELECT post_id, field_key, field_value FROM {$tododb->prefix}post_custom_fields WHERE post_id IN ({$ph})", $ids);
    if (is_array($cf_rows)) {
        foreach ($cf_rows as $r) {
            if (!is_object($r)) continue;
            $pid = (int) ($r->post_id ?? 0);
            $k = (string) ($r->field_key ?? '');
            if ($pid <= 0 || $k === '') continue;
            if (!isset($cf_map[$pid])) $cf_map[$pid] = [];
            if (is_string($k) && strpos($k, '_') === 0) continue;
            $val = $r->field_value ?? '';
            if (function_exists('maybe_unserialize')) {
                $val = maybe_unserialize($val);
            }
            $cf_map[$pid][$k] = $val;
            $all_keys[$k] = true;
        }
    }

    $dynamic_keys = array_keys($all_keys);
    $base_keys = ['contact_name', 'contact_email', 'contact_phone', 'subject', 'message_status'];
    $dynamic_keys = array_values(array_filter($dynamic_keys, function ($k) use ($base_keys) {
        return !in_array($k, $base_keys, true);
    }));
    sort($dynamic_keys);

    $header = ['ID', '标题', '留言内容', '分类', '状态', '提交时间', '联系人姓名', '联系人邮箱', '联系电话', '作者', '作者邮箱'];
    foreach ($dynamic_keys as $k) {
        $header[] = $label_map[$k] ?? $k;
    }
    fputcsv($output, $header);

    foreach ($posts as $p) {
        if (!is_object($p) || empty($p->ID)) continue;
        $pid = (int) $p->ID;
        $cf = $cf_map[$pid] ?? [];

        $author_name = '';
        $author_email = '';
        if (function_exists('get_user_by')) {
            $u = get_user_by('id', intval($p->post_author ?? 0));
            if ($u) {
                $author_name = (string) ($u->display_name ?? $u->user_login ?? '');
                $author_email = (string) ($u->user_email ?? '');
            }
        }

        $category_name = '';
        if (function_exists('get_term')) {
            $t = get_term(intval($p->category_id ?? 0), 'category');
            if ($t && !empty($t->name)) {
                $category_name = function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations((string) $t->name) : (string) $t->name;
            }
        }

        $row = [
            $pid,
            (string) ($p->post_title ?? ''),
            todo_html_to_plain_text((string) ($p->post_content ?? '')),
            $category_name,
            (string) ($p->post_status ?? ''),
            (string) ($p->post_date ?? ''),
            (string) ($cf['contact_name'] ?? ''),
            (string) ($cf['contact_email'] ?? ''),
            (string) ($cf['contact_phone'] ?? ''),
            $author_name,
            $author_email
        ];
        foreach ($dynamic_keys as $k) {
            $v = $cf[$k] ?? '';
            if (is_array($v) || is_object($v)) {
                $row[] = json_encode($v, JSON_UNESCAPED_UNICODE);
            } else {
                $row[] = (string) $v;
            }
        }
        fputcsv($output, $row);
    }

    fclose($output);
}

?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">网站留言</h2>
        <div class="admin-page-actions">
            <a href="index.php?page=post-new&model=guestbook&from=website-messages" class="button button-primary">添加留言</a>
        </div>
    </div>

    <?php
    if (isset($message) && $message !== null && $message !== '') {
        echo '<div class="notice notice-' . esc_attr($message_type ?? 'success') . ' is-dismissible">';
        echo '<p>' . esc_html((string) $message) . '</p>';
        echo '<button type="button" class="notice-dismiss">✕</button>';
        echo '</div>';
    }
    ?>

    <div class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-title-section">
                <h3 class="admin-card-title">留言列表</h3>
                <div class="admin-card-subtitle">
                    <?php echo $total_items; ?> 条留言
                </div>
            </div>

            <div class="admin-card-actions">
                <form method="get" class="search-form">
                    <input type="hidden" name="page" value="website-messages">
                    <?php if ($status !== 'all'): ?>
                        <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>">
                    <?php endif; ?>
                    <?php if ($form_type !== 'all'): ?>
                        <input type="hidden" name="form_type" value="<?php echo esc_attr($form_type); ?>">
                    <?php endif; ?>
                    <select name="form_type" style="margin-right: 10px;">
                        <option value="all">全部类型</option>
                        <?php foreach ($guestbook_categories as $cat): ?>
                            <?php
                            $tid = is_array($cat) ? intval($cat['term_id'] ?? 0) : intval($cat->term_id ?? 0);
                            if ($tid <= 0) continue;
                            $tname = is_array($cat) ? (string) ($cat['name'] ?? '') : (string) ($cat->name ?? '');
                            if (function_exists('todo_parse_inline_translations')) {
                                $tname = todo_parse_inline_translations($tname);
                            }
                            ?>
                            <option value="<?php echo esc_attr($tid); ?>" <?php selected($form_type, (string) $tid); ?>>
                                <?php echo esc_html($tname !== '' ? $tname : ('#' . $tid)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="search" name="search" value="<?php echo esc_attr($search); ?>"
                        placeholder="搜索留言内容、用户名或邮箱...">
                    <button type="submit" class="button button-secondary">搜索</button>
                </form>
            </div>
        </div>

        <div class="admin-card-content">
            <div class="admin-filter-bar">
                <ul class="admin-filters">
                    <?php
                    $status_labels = array(
                        'all' => '全部',
                        'pending' => '待审核',
                        'approved' => '已通过',
                        'replied' => '已回复',
                        'spam' => '垃圾信息',
                        'trash' => '回收站'
                    );

                    foreach ($status_labels as $s => $label):
                        $count = isset($status_counts[$s]) ? $status_counts[$s] : 0;
                        $url = add_query_arg(array('status' => $s, 'paged' => false));
                        ?>
                        <li>
                            <a href="<?php echo $url; ?>" class="<?php echo $status === $s ? 'current' : ''; ?>">
                                <?php echo $label; ?> <span class="count">(<?php echo $count; ?>)</span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <form method="post" id="messages-form">
                <?php if (class_exists('Todo_Security_Manager')) echo Todo_Security_Manager::csrf_field('bulk_action_messages'); ?>
                <input type="hidden" id="todo-csrf-update-status" value="<?php echo esc_attr(Todo_Security_Manager::generate_csrf_token('update_message_status')); ?>">

                <div class="bulk-actions-bar">
                    <div class="bulk-actions">
                        <select name="action" id="bulk-action-selector-top">
                            <option value="">批量操作</option>
                            <option value="approve">批准</option>
                            <option value="pending">设为待审核</option>
                            <option value="spam">标记为垃圾信息</option>
                            <option value="trash">移至回收站</option>
                            <option value="export">导出选中</option>
                            <?php if ($status === 'trash'): ?>
                                <option value="delete">永久删除</option>
                            <?php endif; ?>
                        </select>
                        <button type="submit" class="button button-secondary">应用</button>
                    </div>
                </div>

                <table class="admin-table">
                    <thead>
                        <tr>
                            <th class="check-column">
                                <input type="checkbox" id="cb-select-all">
                            </th>
                            <th>留言者</th>
                            <th style="min-width: 320px;">留言内容</th>
                            <th style="min-width: 130px;">表单类型</th>
                            <th style="min-width: 130px;">提交时间</th>
                            <th>状态</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($messages)): ?>
                        <?php foreach ($messages as $message): ?>
                            <?php
                            $custom_fields = array();
                            if (!empty($message->custom_fields)) {
                                $decoded = json_decode($message->custom_fields, true);
                                if (is_array($decoded)) {
                                    $custom_fields = $decoded;
                                }
                            }
                            $form_type_value = !empty($message->form_type) ? $message->form_type : '';

                            $delete_url = '';
                            $base_delete = todo_admin_url('index.php?page=website-messages&action=delete&message_id=' . intval($message->id));
                            if (function_exists('todo_nonce_url')) {
                                $delete_url = todo_nonce_url($base_delete, 'delete_message_' . intval($message->id));
                            } else {
                                $delete_url = $base_delete;
                            }
                            ?>
                            <tr id="message-<?php echo intval($message->id); ?>"
                                class="<?php echo esc_attr($message->approval_status ?? $message->status); ?>">
                                <td>
                                    <input type="checkbox" name="message_ids[]" value="<?php echo intval($message->id); ?>">
                                </td>
                                <td>
                                    <div class="comment-author">
                                        <?php echo get_avatar($message->email, 40); ?>
                                        <div class="author-info">
                                            <strong><?php echo esc_html($message->name); ?></strong>
                                            <?php if (!empty($message->email)): ?>
                                                <?php echo esc_html($message->email); ?>
                                            <?php endif; ?>
                                            <?php if (!empty($message->phone)): ?>
                                                <span style="display: block;"><?php echo esc_html($message->phone); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($message->subject)): ?>
                                        <div class="message-subject">
                                            <strong><?php echo esc_html($message->subject); ?></strong>
                                        </div>
                                    <?php endif; ?>
                                    <div class="comment-content" style="<?php echo !empty($message->subject) ? 'margin-left: 15px; ' : ''; ?>overflow: hidden; max-height: 60px; line-height: 20px; font-size: 14px; display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2;">
                                        <?php echo esc_html(todo_trim_words(todo_decode_html_entities($message->content, 2), 60)); ?>
                                    </div>

                                    <?php if (!empty($custom_fields)): ?>
                                        <div class="message-meta">
                                            <?php foreach ($custom_fields as $key => $value): ?>
                                                <?php
                                                $display_value = is_scalar($value) ? strval($value) : json_encode($value, JSON_UNESCAPED_UNICODE);
                                                $ftype = $guestbook_field_type_map[(string) $key] ?? 'text';
                                                // 解析区域字段：代码|代码|... -> 名称 名称 ...
                                                if ($ftype === 'region' && function_exists('todo_regions_find_name') && is_scalar($value)) {
                                                    $parts = explode('|', (string) $value);
                                                    $names = [];
                                                    foreach ($parts as $part) {
                                                        $part = trim($part);
                                                        if ($part === '') continue;
                                                        $n = todo_regions_find_name($part);
                                                        $names[] = $n !== '' ? $n : $part;
                                                    }
                                                    $display_value = implode(' ', $names);
                                                }
                                                // 解析 select/radio/checkbox 值 -> 显示标签
                                                if (in_array($ftype, ['select', 'radio', 'checkbox'], true)
                                                    && is_scalar($value)
                                                    && isset($guestbook_field_option_labels[(string) $key])) {
                                                    $opt_map = $guestbook_field_option_labels[(string) $key];
                                                    $sv = (string) $value;
                                                    // 支持逗号分隔的多选值
                                                    if (strpos($sv, ',') !== false) {
                                                        $vals = array_map('trim', explode(',', $sv));
                                                        $labels = [];
                                                        foreach ($vals as $v) {
                                                            $labels[] = $opt_map[$v] ?? $v;
                                                        }
                                                        $display_value = implode(', ', $labels);
                                                    } else {
                                                        $display_value = $opt_map[$sv] ?? $sv;
                                                    }
                                                }
                                                // 解析 multi_file 类型：JSON 数组 -> 提取 url 列表
                                                if ($ftype === 'multi_file' && is_string($value) && $value !== '') {
                                                    $decoded = json_decode($value, true);
                                                    if (is_array($decoded)) {
                                                        $urls = [];
                                                        foreach ($decoded as $file) {
                                                            if (isset($file['url'])) {
                                                                $urls[] = $file['url'];
                                                            }
                                                        }
                                                        $display_value = implode(', ', $urls);
                                                    }
                                                }
                                                ?>
                                                <div class="meta-item">
                                                    <strong><?php echo esc_html($guestbook_field_label_map[(string) $key] ?? (string) $key); ?>:</strong>
                                                    <?php
                                                    $is_path_or_url = function($str) {
                                                        $str = trim($str);
                                                        if (filter_var($str, FILTER_VALIDATE_URL)) return true;
                                                        if (preg_match('#^https?://#i', $str) || preg_match('#^//#', $str)) return true;
                                                        if (preg_match('#^/(content|uploads|public|assets|storage)/#i', $str)) return true;
                                                        if (preg_match('#\.(png|jpe?g|gif|webp|pdf|zip|rar|docx?|xlsx?|txt)$#i', $str)) return true;
                                                        return false;
                                                    };

                                                    if (is_string($display_value) && $display_value !== '') {
                                                        $parts = explode(',', $display_value);
                                                        $formatted_parts = [];
                                                        foreach ($parts as $part) {
                                                            $trimmed = trim($part);
                                                            if ($is_path_or_url($trimmed)) {
                                                                $link_url = function_exists('todo_media_url_to_absolute') ? todo_media_url_to_absolute($trimmed) : $trimmed;
                                                                $formatted_parts[] = '<a href="' . esc_url($link_url) . '" target="_blank" rel="noopener noreferrer" style="color:#1890ff;text-decoration:underline;">' . esc_html($link_url) . '</a>';
                                                            } else {
                                                                $formatted_parts[] = esc_html($trimmed);
                                                            }
                                                        }
                                                        echo implode(', ', $formatted_parts);
                                                    } else {
                                                        echo esc_html($display_value);
                                                    }
                                                    ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="row-actions">
                                        <span class="view">
                                            <a href="javascript:void(0);" class="view-message"
                                                data-message-id="<?php echo intval($message->id); ?>">概览</a> |
                                        </span>
                                        <span class="reply">
                                            <a href="javascript:void(0);" class="reply-message"
                                                data-message-id="<?php echo intval($message->id); ?>">回复</a> |
                                        </span>
                                        <span class="edit">
                                            <a href="<?php echo todo_admin_url('index.php?page=post-edit&from=website-messages&post=' . intval($message->id) . '&from=website-messages'); ?>">编辑/详情</a> |
                                        </span>
                                        <?php if (($message->approval_status ?? $message->status) !== 'approved'): ?>
                                            <span class="approve">
                                                <a href="javascript:void(0);" class="update-message-status" data-message-id="<?php echo intval($message->id); ?>" data-status="approved">批准</a>
                                                |
                                            </span>
                                        <?php endif; ?>
                                        <span class="spam">
                                            <a href="javascript:void(0);" class="update-message-status" data-message-id="<?php echo intval($message->id); ?>" data-status="spam">垃圾信息</a>
                                            |
                                        </span>
                                        <?php if (($message->approval_status ?? $message->status) !== 'trash'): ?>
                                            <span class="trash">
                                                <a href="javascript:void(0);" class="update-message-status" data-message-id="<?php echo intval($message->id); ?>" data-status="trash">回收站</a>
                                            </span>
                                        <?php else: ?>
                                            <span class="untrash">
                                                <a href="javascript:void(0);" class="update-message-status" data-message-id="<?php echo intval($message->id); ?>" data-status="approved">恢复</a>
                                                |
                                            </span>
                                            <span class="delete">
                                                <a href="<?php echo $delete_url; ?>" class="delete-message" style="color:#a00;">删除</a>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="form-type-badge">
                                        <?php echo esc_html($form_type_value ?: '默认'); ?>
                                    </span>
                                </td>
                                <td style="width: 160px;">
                                    <span class="tb-meta"><?php echo date('Y-m-d H:i', strtotime($message->created_at)); ?></span>
                                </td>
                                <td style="min-width: 80px;">
                                    <span class="status-<?php echo esc_attr($message->status); ?>">
                                        <?php
                                        switch ($message->status) {
                                            case 'replied':
                                                echo '✓ 回复';
                                                break;
                                            case 'approved':
                                                echo '已通过';
                                                break;
                                            case 'pending':
                                                echo '待审核';
                                                break;
                                            case 'spam':
                                                echo '垃圾信息';
                                                break;
                                            case 'trash':
                                                echo '回收站';
                                                break;
                                            default:
                                                echo esc_html($message->status);
                                                break;
                                        }
                                        ?>
                                    </span>
                                </td>
                            </tr>

                            <?php if (!empty($message->replies) && is_array($message->replies)): ?>
                                <?php foreach ($message->replies as $r): ?>
                                    <?php
                                    $rid = intval($r->id ?? 0);
                                    if ($rid <= 0) {
                                        continue;
                                    }
                                    $r_author = (string) ($r->author ?? '回复');
                                    $r_email = (string) ($r->email ?? '');
                                    $r_content = (string) ($r->content ?? '');
                                    $r_created_at = (string) ($r->created_at ?? '');
                                    $r_depth = intval($r->depth ?? 1);
                                    $r_approval_status = (string) ($r->approval_status ?? '');
                                    $r_status = (string) ($r->status ?? ($r_approval_status !== '' ? $r_approval_status : 'approved'));
                                    $r_parent_author = (string) ($r->parent_author ?? '');

                                    $reply_delete_url = todo_admin_url('index.php?page=website-messages&action=delete&message_id=' . $rid);
                                    if (function_exists('todo_nonce_url')) {
                                        $reply_delete_url = todo_nonce_url($reply_delete_url, 'delete_message_' . $rid);
                                    }
                                    ?>
                                    <tr id="message-<?php echo $rid; ?>" class="reply-row <?php echo esc_attr($r_approval_status); ?>">
                                        <td></td>
                                        <td>
                                            <div class="comment-author" style="margin-left: <?php echo $r_depth * 10; ?>px;">
                                                <?php echo get_avatar($r_email, 40); ?>
                                                <div class="author-info">
                                                    <strong><?php echo esc_html($r_author); ?></strong>
                                                    <?php if ($r_email !== ''): ?>
                                                        <div class="author-email">
                                                            <?php echo esc_html($r_email); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td colspan="2" style="padding-left: <?php echo $r_depth * 10; ?>px;">
                                            <div class="comment-content flex items-center gap-1">
                                                <span class="reply-indicator" style="font-size: 12px; opacity: 0.5; flex-shrink: 0;">↳ <?php echo $r_parent_author !== '' ? '回复 @' . esc_html($r_parent_author) . ':' : '回复:'; ?></span>
                                                <div style="overflow: hidden; max-height: 40px; line-height: 20px; font-size: 14px; display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2;">
                                                    <?php echo esc_html(todo_trim_words(todo_decode_html_entities($r_content, 2), 80)); ?>
                                                </div>
                                            </div>
                                            <div class="row-actions">
                                                <?php if ($r_approval_status !== 'approved'): ?>
                                                    <span class="approve">
                                                        <a href="javascript:void(0);" class="update-message-status" data-message-id="<?php echo $rid; ?>" data-status="approved" style="color:#0073aa;">批准</a> |
                                                    </span>
                                                <?php else: ?>
                                                    <span class="unapprove">
                                                        <a href="javascript:void(0);" class="update-message-status" data-message-id="<?php echo $rid; ?>" data-status="pending" style="color:#0073aa;">设为待审核</a> |
                                                    </span>
                                                <?php endif; ?>
                                                <span class="reply">
                                                    <a href="javascript:void(0);" class="reply-message" data-message-id="<?php echo $rid; ?>">回复</a> |
                                                </span>
                                                <span class="edit">
                                                    <a href="<?php echo todo_admin_url('index.php?page=comment-edit&from=website-messages&comment_id=' . $rid); ?>">编辑</a> |
                                                </span>
                                                <span class="delete">
                                                    <a href="<?php echo $reply_delete_url; ?>" class="delete-message" style="color:#a00;">删除</a>
                                                </span>
                                            </div>
                                        </td>
                                        <td style="width: 160px;">
                                            <span class="tb-meta"><?php echo $r_created_at !== '' ? date('Y-m-d H:i', strtotime($r_created_at)) : ''; ?></span>
                                        </td>
                                        <td style="min-width: 80px;">
                                            <span class="status-<?php echo esc_attr($r_status); ?>">
                                                <?php
                                                switch ($r_status) {
                                                    case 'replied':
                                                        echo '✓ 回复';
                                                        break;
                                                    case 'approved':
                                                        echo '已通过';
                                                        break;
                                                    case 'pending':
                                                        echo '待审核';
                                                        break;
                                                    case 'spam':
                                                        echo '垃圾信息';
                                                        break;
                                                    case 'trash':
                                                        echo '回收站';
                                                        break;
                                                    default:
                                                        echo esc_html($r_status);
                                                        break;
                                                }
                                                ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr class="empty">
                            <td colspan="6">
                                <div class="no-items">
                                    <p>没有找到网站留言。</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </form>

            <?php if ($total_pages > 1): ?>
                <?php
                $pagination_add_args = array();
                if (!empty($status) && $status !== 'all') {
                    $pagination_add_args['status'] = $status;
                }
                if (!empty($form_type) && $form_type !== 'all') {
                    $pagination_add_args['form_type'] = $form_type;
                }
                if (!empty($search)) {
                    $pagination_add_args['search'] = $search;
                }

                echo todo_render_pagination(array(
                    'total_items' => $total_items,
                    'per_page' => $per_page,
                    'current_page' => $paged,
                    'base_url' => todo_admin_url('index.php?page=website-messages'),
                    'add_args' => $pagination_add_args,
                    'show_count' => true,
                ));
                ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<template id="view-message-modal-template" data-size="lg">
    <div data-slot="title">留言详情</div>
    <div data-slot="body">
        <div id="message-details"></div>
    </div>
    <div data-slot="footer">
        <button type="button" class="button button-secondary todo-modal-cancel">关闭</button>
    </div>
</template>

<template id="reply-message-modal-template">
    <div data-slot="title">回复留言</div>
    <div data-slot="body">
        <form id="reply-message-form">
            <input type="hidden" id="reply-message-id" name="message_id">
            <?php echo Todo_Security_Manager::csrf_field('reply_message', '_csrf_token'); ?>

            <div class="form-group">
                <label for="reply-subject">邮件主题：</label>
                <input type="text" id="reply-subject" name="subject" style="width: 100%;" class="regular-text" required>
            </div>

            <div class="form-group">
                <label for="reply-content">回复内容：<span class="description m-0">{reply_content}</span></label>
                <textarea id="reply-content" name="reply_content" style="width: 100%;" rows="8" class="large-text" required></textarea>
            </div>

            <div class="form-group">
                <label>
                    <input type="checkbox" id="send-email" name="notify_user" value="1">
                    发送邮件通知用户
                </label>
            </div>

            <div class="form-group" id="template-select-group">
                <label for="reply-template">邮件通知模板：</label>
                <select id="reply-template" name="template_slug">
                    <option value="">-- 邮件模板(可选) --</option>
                </select>
                <p class="description">您可以在"工具 > 邮件设置"中 <a href="<?php echo todo_admin_url('index.php?page=mail&tab=templates'); ?>">管理模版</a></p>
            </div>
        </form>
    </div>
    <div data-slot="footer">
        <button type="button" class="button button-secondary todo-modal-cancel">取消</button>
        <button type="button" class="button button-primary" id="btn-submit-reply">发送回复</button>
    </div>
</template>

<style>
    /* 网站留言管理页面特定样式 */
    .comment-author {
        display: flex;
        align-items: flex-start;
    }

    .comment-author img {
        margin-right: 10px;
        border-radius: 3px;
        background-color: #f0f0f5;
        font-size: 12px;
    }

    .author-info {
        font-size: 12px;
        color: #666;
    }

    .author-info strong {
        font-size: 14px;
        display: block;
    }

    /* 留言详情样式 */
    .message-detail-item {
        margin-bottom: 15px;
        padding: 10px;
        background-color: #f9f9f9;
        border-radius: 4px;
    }

    .message-detail-item strong {
        display: block;
        margin-bottom: 5px;
        color: #333;
    }

    .message-content-detail {
        background-color: #fff;
        padding: 15px;
        border: 1px solid #ddd;
        border-radius: 4px;
        white-space: pre-wrap;
        word-wrap: break-word;
    }

    /* 自定义字段样式 */
    .custom-field-row {
        display: flex;
        gap: 10px;
        margin-bottom: 10px;
        align-items: center;
    }

    .custom-field-row input {
        flex: 1;
    }

    .custom-field-row .remove-field {
        flex-shrink: 0;
    }

    /* 表单类型徽章 */
    .form-type-badge {
        display: inline-block;
        padding: 2px 8px;
        background-color: #f1f1f1;
        border-radius: 3px;
        font-size: 12px;
    }

    /* 留言meta信息 */
    .message-meta {
        margin: 5px 0;
        padding: 0 0 0 12px;
        border-left: 3px solid #d7d7d7;
        font-size: 12px;
    }

    /* 筛选表单样式 */
    .filter-form {
        margin: 20px 0;
        padding: 15px;
        background-color: #f9f9f9;
        border-radius: 4px;
    }

    .filter-group {
        display: inline-block;
        margin-right: 20px;
        vertical-align: top;
    }

    .filter-group label {
        display: inline-block;
        margin-right: 5px;
        font-weight: 500;
    }

    .search-form {
        display: flex;
        align-items: center;
        gap: 15px;
        flex-wrap: wrap;
    }

    .search-box {
        margin: 0 !important;
    }

    .search-box input[type="search"] {
        width: 300px;
        margin-right: 10px;
    }

    /* 响应式设计 */
    @media (max-width: 768px) {
        .custom-field-row {
            flex-direction: column;
            align-items: stretch;
        }

        .filter-group {
            display: block;
            margin-bottom: 10px;
        }

        .search-box input[type="search"] {
            width: 100%;
            margin-bottom: 10px;
        }
    }
</style>

<script type="text/javascript">
    let viewMessageModalInstance = null;
    let replyMessageModalInstance = null;
    let addMessageModalInstance = null;
    const websiteMessagesAjaxUrl = '<?php echo todo_admin_url('admin-ajax.php'); ?>';
    const websiteSitePath = '<?php echo rtrim((string) parse_url(function_exists("todo_get_site_url") ? todo_get_site_url("/") : "", PHP_URL_PATH), "/"); ?>';

    function todo_media_url_to_absolute(url) {
        if (!url || typeof url !== 'string') return url || '';
        url = url.trim();
        if (!url || url.indexOf('data:') === 0 || url.indexOf('http://') === 0 || url.indexOf('https://') === 0 || url.indexOf('//') === 0) {
            return url;
        }
        if (websiteSitePath && websiteSitePath !== '/' && url.indexOf(websiteSitePath + '/') !== 0) {
            return websiteSitePath + (url.indexOf('/') === 0 ? url : '/' + url);
        }
        return url;
    }

    function getCsrfTokenFromForm(form) {
        if (!form) return '';
        var input = form.querySelector('input[name="_csrf_token"]') || form.querySelector('input[name="_todononce"]');
        return input ? (input.value || '') : '';
    }

    function getUpdateStatusCsrfToken() {
        var el = document.getElementById('todo-csrf-update-status');
        return el ? (el.value || '') : '';
    }

    function closeModalInstance(modalInstance, afterClose) {
        if (!modalInstance) return;
        modalInstance.onCloseCallback(function () {
            if (typeof afterClose === 'function') afterClose();
        });
        modalInstance.close();
    }

    jQuery(document).ready(function ($) {
        // 全选/取消全选（仅主留言）
        $('#cb-select-all').on('change', function () {
            var isChecked = this.checked;
            $('input[name="message_ids[]"]').prop('checked', isChecked);
        });

        // 批量操作提交验证
        $('#messages-form').on('submit', function (e) {
            var action = $('select[name="action"]').val();
            var checkedItems = $('input[name="message_ids[]"]:checked').length;

            if (!action || action === '-1') {
                e.preventDefault();
                TodoCMS.Utils.showMessage('请选择要执行的操作', 'warning');
                return false;
            }

            if (checkedItems === 0) {
                e.preventDefault();
                TodoCMS.Utils.showMessage('请至少选择一项', 'warning');
                return false;
            }

            if (action === 'export') {
                var form = this;
                var frameId = 'todo-download-frame';
                var frame = document.getElementById(frameId);
                if (!frame) {
                    frame = document.createElement('iframe');
                    frame.id = frameId;
                    frame.name = frameId;
                    frame.style.display = 'none';
                    document.body.appendChild(frame);
                }
                form.setAttribute('target', frameId);
                setTimeout(function () {
                    try {
                        form.removeAttribute('target');
                    } catch (err) {}
                }, 1000);
                return true;
            }

            if (action === 'delete') {
                e.preventDefault();
                if (typeof TodoModal === 'undefined') {
                    if (confirm('确定要永久删除选中的留言及其所有回复吗？')) {
                        document.getElementById('messages-form').submit();
                    }
                    return false;
                }
                TodoModal.deleteConfirm({
                    title: '确认删除',
                    message: '确定要永久删除选中的留言及其所有回复吗？',
                    onConfirm: function () {
                        document.getElementById('messages-form').submit();
                    }
                });
                return false;
            }
        });

        $('.delete-message').on('click', function (e) {
            e.preventDefault();
            var href = $(this).attr('href');
            if (!href) return;
            var doPost = function () {
                if (window.TodoRequest && typeof window.TodoRequest.ajax === 'function') {
                    var fd = new FormData();
                    try {
                        var u = new URL(href, window.location.href);
                        u.searchParams.forEach(function (v, k) {
                            fd.append(k, v);
                        });
                    } catch (err) {}
                    window.TodoRequest.ajax({ url: href, method: 'POST', data: fd });
                    return;
                }
                window.location.href = href;
            };
            if (typeof TodoModal === 'undefined') {
                doPost();
                return;
            }
            TodoModal.deleteConfirm({
                title: '确认删除',
                message: '确定要永久删除该留言及其所有回复吗？',
                onConfirm: function () {
                    doPost();
                }
            });
        });

        // 查看留言详情
        $('.view-message').on('click', function (e) {
            e.preventDefault();
            var messageId = $(this).data('message-id');
            viewMessageDetails(messageId);
        });

        // 回复留言
        $('.reply-message').on('click', function (e) {
            e.preventDefault();
            var messageId = $(this).data('message-id');
            openReplyModal(messageId);
        });

    });

    // 查看留言详情
    function viewMessageDetails(messageId) {
        var csrfToken = window.TodoCSRFManager ? window.TodoCSRFManager.getToken('get_message') : '';
        var ajaxUrl = websiteMessagesAjaxUrl;

        jQuery.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'get_message',
                message_id: messageId,
                _csrf_token: csrfToken
            },
            dataType: 'json',
            success: function (response) {
                if (response.success) {
                    const modal = new TodoModal({
                        templateId: 'view-message-modal-template',
                        destroyOnClose: true
                    });
                    viewMessageModalInstance = modal;
                    modal.onCloseCallback(function () {
                        viewMessageModalInstance = null;
                    });
                    displayMessageDetails(response.data && response.data.message ? response.data.message : null, modal.element);
                    modal.open();
                } else {
                    TodoCMS.Utils.showMessage('获取留言详情失败：' + (response.message || '未知错误'), 'error');
                }
            },
            error: function (xhr, status, error) {
                console.error('AJAX Error:', error);
                TodoCMS.Utils.showMessage('获取留言详情失败，请重试', 'error');
            }
        });
    }

    // 显示留言详情
    function displayMessageDetails(message, root) {
        if (!message) return;
        var html = `
        <div class="message-detail-item m-b-10">
            <strong>留言者信息：</strong>
            <div style="margin-left: 17px;">姓名：${escapeHtml(message.name)}</div>
            <div style="margin-left: 17px;">邮箱：${escapeHtml(message.email)}</div>
            ${message.phone ? `<div style="margin-left: 17px;">电话：${escapeHtml(message.phone)}</div>` : ''}
        </div>
        
        <div class="message-detail-item m-b-10">
            <strong>留言内容：</strong>
            ${message.subject ? `<div style="margin-left: 17px;">主题：${escapeHtml(message.subject)}</div>` : ''}
            <div class="message-content-detail" style="margin: 5px 0;padding: 20px;color: #7e6759;background-color: #fdfaf1;border: 1px solid #efe7e0;border-radius: 3px;">${escapeHtml(message.content)}</div>
        </div>
        
        <div class="message-detail-item m-b-10">
            <strong>提交信息：</strong>
            <div style="margin-left: 17px;">提交时间：${escapeHtml(message.created_at)}</div>
            <div style="margin-left: 17px;">状态：${getStatusText(message.status)}</div>
            <div style="margin-left: 17px;">表单类型：${escapeHtml(message.form_type || '默认')}</div>
        </div>
    `;

        var isImageFile = function(str) {
            if (!str || typeof str !== 'string') return false;
            var s = str.trim().toLowerCase();
            return /\.(png|jpe?g|gif|webp|bmp|svg)($|\?|#)/i.test(s) || /^data:image\//i.test(s);
        };
        var renderFieldValue = function(val, ftype) {
            if (val === null || val === undefined) return '';
            var strVal = String(val);
            if (!strVal.trim()) return '';
            var items = strVal.split(',').map(function(s) { return s.trim(); }).filter(Boolean);
            if (items.length === 0) return '';

            var rendered = items.map(function(item) {
                if (ftype === 'file' || ftype === 'multi_file' || isImageFile(item)) {
                    if (isImageFile(item)) {
                        var absUrl = (typeof todo_media_url_to_absolute === 'function') ? todo_media_url_to_absolute(item) : item;
                        return `<a href="${escapeHtml(absUrl)}" target="_blank" rel="noopener noreferrer" style="display:inline-block;margin-right:8px;margin-bottom:8px;"><img src="${escapeHtml(absUrl)}" style="max-width:100px;max-height:100px;object-fit:cover;vertical-align:middle;" /></a>`;
                    } else {
                        var absUrl = (typeof todo_media_url_to_absolute === 'function') ? todo_media_url_to_absolute(item) : item;
                        return `<a href="${escapeHtml(absUrl)}" target="_blank" rel="noopener noreferrer" style="color:#1890ff;text-decoration:underline;margin-right:8px;">${escapeHtml(absUrl)}</a>`;
                    }
                }
                return escapeHtml(item);
            });
            return rendered.join(' ');
        };

        if (message.custom_fields_display && Array.isArray(message.custom_fields_display) && message.custom_fields_display.length > 0) {
            html += `
            <div class="message-detail-item m-b-10">
                <strong>扩展字段：</strong>
        `;
            for (var i = 0; i < message.custom_fields_display.length; i++) {
                var item = message.custom_fields_display[i] || {};
                var title = item.title || item.key || '';
                var v = item.value;
                if (item.field_type !== 'multi_file' && v !== null && typeof v === 'object') {
                    try { v = JSON.stringify(v); } catch (e) { v = String(v); }
                }
                var valHtml = renderFieldValue(v, item.field_type);
                html += `<div style="margin-left: 17px;margin-top:4px;">${escapeHtml(String(title))}：${valHtml}</div>`;
            }
            html += '</div>';
        } else if (message.custom_fields && Object.keys(message.custom_fields).length > 0) {
            html += `
            <div class="message-detail-item m-b-10">
                <strong>扩展字段：</strong>
        `;
            for (var key in message.custom_fields) {
                var v2 = message.custom_fields[key];
                if (typeof v2 === 'string' && v2.startsWith && v2.startsWith('[{')) {
                    try {
                        var parsed = JSON.parse(v2);
                        if (Array.isArray(parsed)) {
                            var urls = [];
                            for (var j = 0; j < parsed.length; j++) {
                                if (parsed[j] && parsed[j].url) urls.push(parsed[j].url);
                            }
                            v2 = urls.join(', ');
                        }
                    } catch (e) {}
                }
                if (v2 !== null && typeof v2 === 'object') {
                    try { v2 = JSON.stringify(v2); } catch (e) { v2 = String(v2); }
                }
                var valHtml = renderFieldValue(v2, '');
                html += `<div style="margin-left: 17px;margin-top:4px;">${escapeHtml(key)}：${valHtml}</div>`;
            }
            html += '</div>';
        }

        var target = root ? root.querySelector('#message-details') : document.getElementById('message-details');
        if (target) target.innerHTML = html;
    }

    // 打开回复模态框
    function openReplyModal(messageId) {
        const modal = new TodoModal({
            templateId: 'reply-message-modal-template',
            destroyOnClose: true
        });
        replyMessageModalInstance = modal;
        modal.onCloseCallback(function () {
            replyMessageModalInstance = null;
        });
        const root = modal.element;
        if (root) {
            const idEl = root.querySelector('#reply-message-id');
            const subjectEl = root.querySelector('#reply-subject');
            const contentEl = root.querySelector('#reply-content');
            const sendEmailEl = root.querySelector('#send-email');
            if (idEl) idEl.value = messageId;
            if (subjectEl) subjectEl.value = '';
            if (contentEl) contentEl.value = '';

            // 获取模板列表
            var templateSelect = root.querySelector('#reply-template');
            var syncReplyRequired = function () {
                var tplVal = templateSelect ? String(templateSelect.value || '').trim() : '';
                var shouldSkip = !!(sendEmailEl && sendEmailEl.checked && tplVal !== '');
                if (contentEl) {
                    contentEl.required = !shouldSkip;
                }
            };
            if (templateSelect) {
                fetch('admin-ajax.php?action=get_templates_by_category&category=message_reply', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.data) {
                            data.data.forEach(tpl => {
                                var opt = document.createElement('option');
                                opt.value = tpl.slug;
                                opt.textContent = tpl.name;
                                templateSelect.appendChild(opt);
                            });
                        }
                        syncReplyRequired();
                    });

                templateSelect.addEventListener('change', function() {
                    var slug = this.value;
                    syncReplyRequired();
                    if (slug) {
                        fetch('admin-ajax.php?action=get_template_content&id=0&slug=' + slug, {
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success && data.data) {
                                    if (subjectEl && data.data.subject) subjectEl.value = data.data.subject;
                                }
                            });
                    }
                });
            }
            if (sendEmailEl) {
                sendEmailEl.addEventListener('change', syncReplyRequired);
            }
            syncReplyRequired();

            const btn = root.querySelector('#btn-submit-reply');
            if (btn) btn.addEventListener('click', submitReply);
        }
        modal.open();
    }

    // 提交回复
    function submitReply() {
        var form = replyMessageModalInstance && replyMessageModalInstance.element ? replyMessageModalInstance.element.querySelector('#reply-message-form') : document.getElementById('reply-message-form');
        if (!form) return;
        if (form.getAttribute('data-submitting') === '1') {
            return;
        }
        form.setAttribute('data-submitting', '1');
        var submitBtn = replyMessageModalInstance && replyMessageModalInstance.element ? replyMessageModalInstance.element.querySelector('#btn-submit-reply') : document.getElementById('btn-submit-reply');
        var submitText = submitBtn ? submitBtn.textContent : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = '发送中...';
        }
        var formData = new FormData(form);
        formData.append('action', 'reply_message');
        var ajaxUrl = websiteMessagesAjaxUrl;

        var csrfToken = getCsrfTokenFromForm(form);
        if (csrfToken && !formData.has('_csrf_token')) {
            formData.append('_csrf_token', csrfToken);
        }

        jQuery.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            },
            success: function (response) {
                if (response.success) {
                    if (response.mail_sent === false) {
                        console.log(response.mail_error);
                        TodoCMS.Utils.showMessage('回复已保存，但邮件发送失败：' + (response.mail_error || '未知原因'), 'warning');
                    } else if (response.mail_sent === true) {
                        TodoCMS.Utils.showMessage('回复发送成功，邮件已发送！', 'success');
                    } else {
                        TodoCMS.Utils.showMessage('回复发送成功！', 'success');
                    }
                    if (replyMessageModalInstance) replyMessageModalInstance.close();
                    setTimeout(() => {
                        location.reload();
                    }, 6000);
                } else {
                    TodoCMS.Utils.showMessage('回复发送失败：' + (response.message || '未知错误'), 'error');
                    form.setAttribute('data-submitting', '0');
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = submitText;
                    }
                }
            },
            error: function (xhr, status, error) {
                var msg = '回复发送失败，请重试';
                try {
                    var data = xhr && (xhr.responseJSON || (xhr.responseText ? JSON.parse(xhr.responseText) : null));
                    if (data && (data.message || data.error)) {
                        msg = '回复发送失败：' + (data.message || data.error);
                    }
                } catch (e) {}
                console.error('AJAX Error:', error, xhr && xhr.responseText ? xhr.responseText : '');
                TodoCMS.Utils.showMessage(msg, 'error');
                form.setAttribute('data-submitting', '0');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = submitText;
                }
            }
        });
    }

    // HTML转义函数
    function escapeHtml(text) {
        if (text === null || typeof text === 'undefined') {
            return '';
        }
        if (typeof text !== 'string') {
            if (typeof text === 'object') {
                try {
                    text = JSON.stringify(text);
                } catch (e) {
                    text = String(text);
                }
            } else {
                text = String(text);
            }
        }
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function (m) { return map[m]; });
    }

    // 获取状态文本
    function getStatusText(status) {
        var statusMap = {
            'replied': '已回复',
            'approved': '已通过',
            'pending': '待审核',
            'spam': '垃圾信息',
            'trash': '回收站'
        };
        return statusMap[status] || status;
    }

    jQuery(document).on('click', '.update-message-status', function (e) {
        e.preventDefault();
        var messageId = jQuery(this).data('message-id');
        var status = jQuery(this).data('status');
        if (!messageId || !status) return;

        var csrfToken = getUpdateStatusCsrfToken();
        jQuery.ajax({
            url: websiteMessagesAjaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'update_message_status',
                message_id: messageId,
                status: status,
                _csrf_token: csrfToken
            },
            success: function (response) {
                if (response && response.success) {
                    location.reload();
                } else {
                    TodoCMS.Utils.showMessage('状态更新失败：' + ((response && response.message) ? response.message : '未知错误'), 'error');
                }
            },
            error: function () {
                TodoCMS.Utils.showMessage('状态更新失败，请重试', 'error');
            }
        });
    });

</script>
