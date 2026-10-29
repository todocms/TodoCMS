<?php
/**
 * 产品咨询管理页面
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

$message = $message ?? null;
$message_type = $message_type ?? 'success';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $single_action = sanitize_text_field($_POST['action'] ?? '');
    $inquiry_id = intval($_POST['inquiry_id'] ?? 0);

    if ($single_action !== '' && $inquiry_id > 0 && in_array($single_action, ['approve', 'spam', 'trash', 'pending', 'delete'], true)) {
        $nonce = (string) ($_POST['_todononce'] ?? '');
        $nonce_action = $single_action . '_inquiry_' . $inquiry_id;
        if ($nonce === '' || !function_exists('todo_verify_nonce') || !todo_verify_nonce($nonce, $nonce_action)) {
            $message = '安全验证失败，请刷新页面后重试。';
            $message_type = 'error';
        } else {
            if ($single_action === 'delete') {
                $ok = function_exists('todo_delete_comment') ? todo_delete_comment($inquiry_id, true) : false;
                if ($ok) {
                    if (function_exists('todo_add_admin_notice')) {
                        todo_add_admin_notice('已永久删除（包含所有回复）。', 'success');
                    }
                    todo_redirect(todo_admin_url('index.php?page=product-inquiries'));
                    exit;
                }
                $message = '操作失败，请稍后重试。';
                $message_type = 'error';
                return;
            }

            $status_map = [
                'approve' => 'approved',
                'pending' => 'pending',
                'spam' => 'spam',
                'trash' => 'trash',
            ];
            $status = $status_map[$single_action] ?? '';
            $ok = $status !== '' ? (function_exists('todo_set_comment_status') ? todo_set_comment_status($inquiry_id, $status) : false) : false;
            if ($ok) {
                $msg_map = [
                    'approve' => '已批准该咨询。',
                    'pending' => '已设置为待审核。',
                    'spam' => '已标记为垃圾信息。',
                    'trash' => '已移至回收站。',
                ];
                if (function_exists('todo_add_admin_notice')) {
                    todo_add_admin_notice($msg_map[$single_action] ?? '操作成功。', 'success');
                }
                todo_redirect(todo_admin_url('index.php?page=product-inquiries'));
                exit;
            }

            $message = '操作失败，请稍后重试。';
            $message_type = 'error';
        }
    }
}

if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' &&
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) {
    $send_json = function (array $payload, int $status = 200) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    };

    $ajax_action = isset($_POST['action']) ? sanitize_text_field($_POST['action']) : '';

    if ($ajax_action === 'reply_product_inquiry') {
        if (!class_exists('Todo_Security_Manager') || !Todo_Security_Manager::verify_request_csrf('reply_inquiry')) {
            $send_json(['success' => false, 'message' => '安全验证失败，请重试'], 403);
        }

        $inquiry_id = intval($_POST['inquiry_id'] ?? 0);
        $reply_content_raw = $_POST['reply_content'] ?? '';
        $reply_content = function_exists('sanitize_textarea_field')
            ? sanitize_textarea_field($reply_content_raw)
            : trim((string) $reply_content_raw);

        $inquiry_lang_code = sanitize_text_field($_POST['lang_code'] ?? '');
        if (empty($inquiry_lang_code) && class_exists('Todo_Comment_Manager') && $inquiry_id > 0) {
            $inquiry_lang_code = Todo_Comment_Manager::get_comment_meta($inquiry_id, 'lang_code');
        }
        if (empty($inquiry_lang_code) && function_exists('get_current_language')) {
            $inquiry_lang_code = get_current_language();
        }
        $inquiry_lang_code = !empty($inquiry_lang_code) ? str_replace('_', '-', (string) $inquiry_lang_code) : '';

        $send_email = isset($_POST['send_email']) && ($_POST['send_email'] === '1' || $_POST['send_email'] === 1 || $_POST['send_email'] === 'on');
        $template_slug = isset($_POST['template_slug']) ? sanitize_text_field($_POST['template_slug']) : '';

        $reply_content_for_email = $reply_content;
        $reply_content_for_store = $reply_content;
        if ($reply_content_for_store === '' && $send_email && $template_slug !== '' && function_exists('todo_get_email_template')) {
            $tpl = todo_get_email_template($template_slug);
            if ($tpl) {
                $reply_content_for_store = '（邮件模板回复）';
            }
        }

        if ($inquiry_id <= 0 || $reply_content_for_store === '') {
            $send_json(['success' => false, 'message' => '参数错误'], 400);
        }
        $token_key = 'reply_product_inquiry|' . intval($inquiry_id) . '|' . (string) $reply_content_for_store . '|' . intval($send_email ? 1 : 0) . '|' . (string) $template_slug;
        if (function_exists('todo_claim_submit_token') && !todo_claim_submit_token($token_key, 120)) {
            $send_json(['success' => false, 'message' => '正在发送中，请勿重复提交'], 429);
        }

        $parent_comment = function_exists('todo_get_comment') ? todo_get_comment($inquiry_id) : null;
        if (!$parent_comment || (is_object($parent_comment) && empty($parent_comment->comment_ID)) || (is_array($parent_comment) && empty($parent_comment['comment_ID']))) {
            $send_json(['success' => false, 'message' => '咨询不存在'], 404);
        }

        $root_inquiry_id = $inquiry_id;
        try {
            $p = $parent_comment;
            $guard = 0;
            while ($guard < 10) {
                $guard++;
                $pid = is_object($p) ? intval($p->comment_parent ?? 0) : (is_array($p) ? intval($p['comment_parent'] ?? 0) : 0);
                if ($pid <= 0) {
                    $cid = is_object($p) ? intval($p->comment_ID ?? 0) : (is_array($p) ? intval($p['comment_ID'] ?? 0) : 0);
                    if ($cid > 0) {
                        $root_inquiry_id = $cid;
                    }
                    break;
                }
                $root_inquiry_id = $pid;
                $p = function_exists('todo_get_comment') ? todo_get_comment($pid) : null;
                if (!$p) {
                    break;
                }
            }
        } catch (Throwable $e) {
            $root_inquiry_id = $inquiry_id;
        }

        $admin_name = '管理员';
        $admin_email = '';
        try {
            if (class_exists('Todo_Session_Manager')) {
                Todo_Session_Manager::init();
                $admin_user_id = intval(Todo_Session_Manager::get_admin_user_id());
                if ($admin_user_id > 0) {
                    if (function_exists('get_user_by')) {
                        $admin_user = get_user_by('id', $admin_user_id);
                        if ($admin_user) {
                            $admin_name = (string) ($admin_user->display_name ?? $admin_user->user_login ?? $admin_name);
                            $admin_email = (string) ($admin_user->user_email ?? '');
                        }
                    } elseif (function_exists('todo_get_user')) {
                        $admin_user = todo_get_user($admin_user_id);
                        if (is_object($admin_user)) {
                            $admin_name = (string) ($admin_user->display_name ?? $admin_user->user_login ?? $admin_name);
                            $admin_email = (string) ($admin_user->user_email ?? '');
                        } elseif (is_array($admin_user)) {
                            $admin_name = (string) ($admin_user['display_name'] ?? $admin_user['user_login'] ?? $admin_name);
                            $admin_email = (string) ($admin_user['user_email'] ?? '');
                        }
                    }
                }
            }
        } catch (Throwable $e) {
        }

        $parent_type = 'inquiry';
        if (is_object($parent_comment) && !empty($parent_comment->comment_type)) {
            $parent_type = (string) $parent_comment->comment_type;
        } elseif (is_array($parent_comment) && !empty($parent_comment['comment_type'])) {
            $parent_type = (string) $parent_comment['comment_type'];
        }

        $reply_id = function_exists('todo_reply_comment')
            ? todo_reply_comment($inquiry_id, [
                'comment_author' => $admin_name,
                'comment_author_email' => $admin_email,
                'comment_content' => $reply_content_for_store,
                'comment_approved' => 1,
                'comment_type' => $parent_type,
            ])
            : false;

        if (!$reply_id) {
            $send_json(['success' => false, 'message' => '回复失败，请稍后重试'], 500);
        }

        if ($send_email && $template_slug !== '' && function_exists('todo_send_template_mail')) {
            $to = '';
            $author_name = '';
            $inquiry_content = '';
            $product_title = '';
            $product_id = 0;
            if (is_object($parent_comment)) {
                $to = (string) ($parent_comment->comment_author_email ?? '');
                $author_name = (string) ($parent_comment->comment_author ?? '');
                $inquiry_content = (string) ($parent_comment->comment_content ?? '');
                $product_id = intval($parent_comment->comment_post_ID ?? 0);
            } elseif (is_array($parent_comment)) {
                $to = (string) ($parent_comment['comment_author_email'] ?? '');
                $author_name = (string) ($parent_comment['comment_author'] ?? '');
                $inquiry_content = (string) ($parent_comment['comment_content'] ?? '');
                $product_id = intval($parent_comment['comment_post_ID'] ?? 0);
            }

            if ($product_id > 0 && function_exists('get_post')) {
                $p = get_post($product_id);
                if ($p) {
                    $product_title = (string) ($p->post_title ?? '');
                }
            }

            if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $site_name = function_exists('get_option') ? (string) get_option('site_title', 'TodoCMS') : 'TodoCMS';
                $site_url = function_exists('site_url') ? (string) site_url('/') : '';
                $thread_key = 'inquiry:' . $root_inquiry_id;
                $reply_to_message_id = '';
                $references = '';
                try {
                    global $tododb;
                    if (isset($tododb) && is_object($tododb) && isset($tododb->prefix)) {
                        $log_table = $tododb->prefix . 'email_logs';
                        $last = $tododb->get_row("SELECT message_id, `references`, in_reply_to FROM `{$log_table}` WHERE thread_key = ? ORDER BY id DESC LIMIT 1", [$thread_key]);
                        if ($last) {
                            $reply_to_message_id = is_object($last) ? (string) ($last->message_id ?? '') : (string) ($last['message_id'] ?? '');
                            $references = is_object($last) ? (string) ($last->references ?? '') : (string) ($last['references'] ?? '');
                            $reply_to_message_id = trim($reply_to_message_id);
                            $references = trim($references);
                        }
                    }
                } catch (Throwable $e) {
                }
                todo_send_template_mail($to, $template_slug, [
                    'username' => $author_name,
                    'email' => $to,
                    'reply_content' => $reply_content_for_email,
                    'inquiry_content' => $inquiry_content,
                    'product_title' => $product_title,
                    'site_name' => $site_name,
                    'site_url' => $site_url,
                    'admin_name' => $admin_name,
                    'lang' => $inquiry_lang_code,
                    'order_lang' => $inquiry_lang_code,
                ], false, [
                    'type' => 'reply',
                    'thread_key' => $thread_key,
                    'related_type' => 'product_inquiry',
                    'related_id' => $root_inquiry_id,
                    'in_reply_to' => $reply_to_message_id,
                    'references' => $references,
                    'lang' => $inquiry_lang_code,
                    'order_lang' => $inquiry_lang_code,
                ]);
            }
        }

        $send_json(['success' => true, 'reply_id' => intval($reply_id)]);
    }

    if ($ajax_action === 'add_product_inquiry') {
        if (!class_exists('Todo_Security_Manager') || !Todo_Security_Manager::verify_request_csrf('add_inquiry')) {
            $send_json(['success' => false, 'message' => '安全验证失败，请重试'], 403);
        }

        $author_raw = $_POST['author'] ?? '';
        $email_raw = $_POST['email'] ?? '';
        $content_raw = $_POST['content'] ?? '';
        $product_id = intval($_POST['product_id'] ?? 0);
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'pending';

        $author = function_exists('sanitize_text_field') ? sanitize_text_field($author_raw) : trim((string) $author_raw);
        $email = function_exists('sanitize_email') ? sanitize_email($email_raw) : trim((string) $email_raw);
        $content = function_exists('sanitize_textarea_field') ? sanitize_textarea_field($content_raw) : trim((string) $content_raw);

        if ($product_id <= 0 || $author === '' || $email === '' || $content === '') {
            $send_json(['success' => false, 'message' => '请填写完整信息'], 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $send_json(['success' => false, 'message' => '邮箱格式不正确'], 400);
        }

        $approved = ($status === 'approved') ? 1 : 0;
        $comment_id = function_exists('todo_create_comment') ? todo_create_comment([
            'comment_post_ID' => $product_id,
            'comment_author' => $author,
            'comment_author_email' => $email,
            'comment_content' => $content,
            'comment_approved' => $approved,
            'comment_type' => 'inquiry',
        ]) : (class_exists('CMS_Comment') ? CMS_Comment::create([
            'comment_post_ID' => $product_id,
            'comment_author' => $author,
            'comment_author_email' => $email,
            'comment_content' => $content,
            'comment_approved' => $approved,
            'comment_type' => 'inquiry',
        ]) : false);

        if (!$comment_id) {
            $send_json(['success' => false, 'message' => '添加失败，请稍后重试'], 500);
        }

        $send_json(['success' => true, 'inquiry_id' => intval($comment_id)]);
    }
}

// 处理批量操作
if (isset($_POST['action']) && isset($_POST['inquiry_ids']) && !empty($_POST['inquiry_ids'])) {
    // 验证CSRF令牌
    if (!Todo_Security_Manager::verify_request_csrf('bulk_action_inquiries')) {
        todo_die('Security check failed');
    }

    $action = sanitize_text_field($_POST['action']);
    $inquiry_ids = array_map('intval', $_POST['inquiry_ids']);

    switch ($action) {
        case 'approve':
            foreach ($inquiry_ids as $id) {
                todo_update_comment_status($id, 'approved');
            }
            $message = '已批准选中的咨询';
            break;
        case 'pending':
            foreach ($inquiry_ids as $id) {
                todo_update_comment_status($id, 'pending');
            }
            $message = '已设置为待审核';
            break;
        case 'spam':
            foreach ($inquiry_ids as $id) {
                todo_update_comment_status($id, 'spam');
            }
            $message = '已标记为垃圾信息';
            break;
        case 'trash':
            foreach ($inquiry_ids as $id) {
                todo_update_comment_status($id, 'trash');
            }
            $message = '已移至回收站';
            break;
        case 'delete':
            foreach ($inquiry_ids as $id) {
                todo_delete_comment($id, true);
            }
            $message = '已永久删除（包含所有回复）';
            break;
    }

    if (isset($message)) {
        // 消息将在页面中显示
    }
}

// 获取筛选参数
$status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
$search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
$paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 20;
$offset = ($paged - 1) * $per_page;

// 构建查询条件
$args = [
    'type' => ['inquiry'],
    'parent_id' => 0,
    'offset' => $offset,
    'limit' => $per_page,
    'order_by' => 'created_at',
    'order' => 'DESC',
    'include_children' => false,
    'include_meta' => false,
    'has_children' => null,
];

$status_map = [
    'approved' => '1',
    'approve' => '1',
    'pending' => '0',
    'hold' => '0',
    'spam' => 'spam',
    'trash' => 'trash',
];

if ($status === 'all') {
    $args['status'] = ['0', '1'];
} elseif ($status === 'replied') {
    $args['status'] = ['0', '1'];
    $args['has_children'] = true;
} else {
    $args['status'] = $status_map[$status] ?? $status;
}

if ($search !== '') {
    $args['keyword'] = $search;
}

$root_inquiries = class_exists('Todo_Comment_Manager') ? Todo_Comment_Manager::get_comments($args) : [];

$args['count'] = true;
$total_items = (int) (class_exists('Todo_Comment_Manager') ? Todo_Comment_Manager::get_comments($args) : 0);

function todo_flatten_comment_tree($comment, $depth, &$flat) {
    if (!is_object($comment)) {
        return;
    }
    $comment->todo_depth = (int) $depth;
    $flat[] = $comment;
    if (!empty($comment->children) && is_array($comment->children)) {
        foreach ($comment->children as $child) {
            todo_flatten_comment_tree($child, $depth + 1, $flat);
        }
    }
}

$flat_inquiries = [];
if ($root_inquiries) {
    foreach ($root_inquiries as $root) {
        if (!is_object($root) || empty($root->comment_ID)) {
            continue;
        }

        $child_args = [
            'type' => $args['type'],
            'parent_id' => (int) $root->comment_ID,
            'status' => 'all',
            'offset' => 0,
            'limit' => 9999,
            'order_by' => 'created_at',
            'order' => 'ASC',
            'include_children' => true,
            'include_meta' => false,
        ];
        $root->children = class_exists('Todo_Comment_Manager') ? Todo_Comment_Manager::get_comments($child_args) : [];
        todo_flatten_comment_tree($root, 0, $flat_inquiries);
    }
}

$inquiries = $flat_inquiries;

$root_inquiry_ids = [];
foreach ($inquiries as $inq) {
    if (!is_object($inq) || empty($inq->comment_ID)) {
        continue;
    }
    if (empty($inq->comment_parent)) {
        $root_inquiry_ids[] = (int) $inq->comment_ID;
    }
}
$root_inquiry_ids = array_values(array_unique(array_filter($root_inquiry_ids)));

$unread_inquiry_map = [];
$has_mail_thread_map = [];
try {
    global $tododb;
    if (!empty($root_inquiry_ids) && isset($tododb) && is_object($tododb) && isset($tododb->prefix)) {
        $log_table = $tododb->prefix . 'email_logs';
        if (function_exists('todo_ensure_email_logs_schema')) {
            todo_ensure_email_logs_schema($log_table, $tododb);
        }
        $placeholders = implode(',', array_fill(0, count($root_inquiry_ids), '?'));
        $params = array_merge(['product_inquiry'], $root_inquiry_ids);
        $rows = $tododb->get_results(
            "SELECT related_id, COUNT(*) AS c FROM `{$log_table}` WHERE related_type = ? AND direction = 'in' AND is_read = 0 AND related_id IN ({$placeholders}) GROUP BY related_id",
            $params
        );
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $rid = is_object($r) ? intval($r->related_id ?? 0) : intval($r['related_id'] ?? 0);
                $c = is_object($r) ? intval($r->c ?? 0) : intval($r['c'] ?? 0);
                if ($rid > 0 && $c > 0) {
                    $unread_inquiry_map[$rid] = $c;
                }
            }
        }

        $all_rows = $tododb->get_results(
            "SELECT related_id, COUNT(*) AS c FROM `{$log_table}` WHERE related_type = ? AND related_id IN ({$placeholders}) GROUP BY related_id",
            $params
        );
        if (is_array($all_rows)) {
            foreach ($all_rows as $ar) {
                $rid = is_object($ar) ? intval($ar->related_id ?? 0) : intval($ar['related_id'] ?? 0);
                $c = is_object($ar) ? intval($ar->c ?? 0) : intval($ar['c'] ?? 0);
                if ($rid > 0 && $c > 0) {
                    $has_mail_thread_map[$rid] = true;
                }
            }
        }
    }
} catch (Throwable $e) {
}

// 计算分页
$total_pages = ceil($total_items / $per_page);

// 获取状态统计（仅统计根询盘，避免把管理员回复计入总数）
$status_counts = [];
$statuses_to_count = ['all', 'pending', 'approved', 'spam', 'trash', 'replied'];
foreach ($statuses_to_count as $s) {
    $stats_args = [
        'type' => ['inquiry'],
        'parent_id' => 0,
        'count' => true,
    ];
    if ($s === 'all') {
        $stats_args['status'] = ['0', '1'];
    } elseif ($s === 'replied') {
        $stats_args['status'] = ['0', '1'];
        $stats_args['has_children'] = true;
    } else {
        $stats_args['status'] = $status_map[$s] ?? $s;
    }
    $status_counts[$s] = (int) (class_exists('Todo_Comment_Manager') ? Todo_Comment_Manager::get_comments($stats_args) : 0);
}

?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">产品询盘</h2>
    </div>

    <?php if (isset($message)): ?>
        <div class="notice notice-<?php echo esc_attr($message_type); ?> is-dismissible">
            <p><?php echo esc_html($message); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <div class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-title-section">
                <h3 class="admin-card-title">咨询列表</h3>
                <div class="admin-card-subtitle">
                    <?php echo $total_items; ?> 条咨询
                </div>
            </div>

            <div class="admin-card-actions">
                <form method="get" class="search-form">
                    <input type="hidden" name="page" value="product-inquiries">
                    <?php if ($status !== 'all'): ?>
                        <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>">
                    <?php endif; ?>
                    <input type="search" name="search" value="<?php echo esc_attr($search); ?>"
                        placeholder="搜索咨询内容、用户名或邮箱...">
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
                        'approved' => '已通过',
                        'pending' => '待审核',
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

            <form method="post" id="inquiries-form">
                <?php echo Todo_Security_Manager::csrf_field('bulk_action_inquiries'); ?>

                <div class="bulk-actions-bar">
                    <div class="bulk-actions">
                        <select name="action" id="bulk-action-selector-top">
                            <option value="">批量操作</option>
                            <option value="approve">批准</option>
                            <option value="pending">设为待审核</option>
                            <option value="spam">标记为垃圾信息</option>
                            <option value="trash">移至回收站</option>
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
                            <th>咨询者</th>
                            <th style="min-width: 360px;">咨询内容</th>
                            <th style="min-width: 160px;">产品</th>
                            <th style="min-width: 160px; width: 160px;">提交时间</th>
                            <th style="min-width: 80px;">状态</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($inquiries)): ?>
                            <?php foreach ($inquiries as $inquiry): ?>
                                <?php
                                $rev_status_map = [
                                    '1' => 'approved',
                                    '0' => 'pending',
                                    'spam' => 'spam',
                                    'trash' => 'trash',
                                ];
                                $approval_status = isset($rev_status_map[$inquiry->comment_approved]) ? $rev_status_map[$inquiry->comment_approved] : $inquiry->comment_approved;
                                $has_reply_descendant = function ($node) use (&$has_reply_descendant) {
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
                                        if ($has_reply_descendant($child)) {
                                            return true;
                                        }
                                    }
                                    return false;
                                };
                                $inquiry_status = ((int) ($inquiry->comment_parent ?? 0) === 0 && $has_reply_descendant($inquiry) && !in_array($approval_status, ['spam', 'trash'], true)) ? 'replied' : $approval_status;
                                $post = get_post($inquiry->comment_post_ID);
                                $post_title = $post ? $post->post_title : '';
                                $inquiry_lang_code = class_exists('Todo_Comment_Manager') ? Todo_Comment_Manager::get_comment_meta($inquiry->comment_ID, 'lang_code') : null;
                                if (empty($inquiry_lang_code) && function_exists('get_current_language')) {
                                    $inquiry_lang_code = get_current_language();
                                }
                                $inquiry_lang_code = !empty($inquiry_lang_code) ? str_replace('_', '-', (string) $inquiry_lang_code) : '';
                                ?>
                                <tr id="comment-<?php echo $inquiry->comment_ID; ?>"
                                    class="<?php echo esc_attr($approval_status); ?>">
                                    <td>
                                        <?php if (empty($inquiry->comment_parent)): ?>
                                            <input type="checkbox" name="inquiry_ids[]"
                                                value="<?php echo $inquiry->comment_ID; ?>">
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="comment-author" style="margin-left: <?php echo intval($inquiry->todo_depth ?? 0) * 10; ?>px;">
                                            <?php echo get_avatar((string)$inquiry->comment_author_email, 40); ?>
                                            <div class="author-info">
                                                <strong><?php echo esc_html($inquiry->comment_author); ?></strong>
                                                <?php echo esc_html($inquiry->comment_author_email); ?>
                                                <?php if (!empty($inquiry_lang_code)): ?>
                                                    <span style="font-size: 11px; display: block;">[<?php echo esc_html($inquiry_lang_code); ?>]</span>
                                                <?php endif; ?>
                                                <?php if ($inquiry->comment_author_url): ?>
                                                    <a href="<?php echo esc_url($inquiry->comment_author_url); ?>" target="_blank">
                                                        <?php echo esc_html($inquiry->comment_author_url); ?>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <?php if (empty($inquiry->comment_parent)): ?>
                                    <td style="padding-left: <?php echo intval($inquiry->todo_depth ?? 0) * 10; ?>px;">
                                    <?php else: ?>
                                    <td colspan="2" style="padding-left: <?php echo intval($inquiry->todo_depth ?? 0) * 10; ?>px;">
                                    <?php endif; ?>
                                        <div class="comment-content flex items-center gap-1">
                                            <?php if (!empty($inquiry->comment_parent)): ?>
                                                <span class="reply-indicator" style="font-size: 12px; opacity: 0.5; flex-shrink: 0;">↳ 回复:</span>
                                            <?php endif; ?>
                                            <div style="overflow: hidden; max-height: 40px; line-height: 20px; font-size: 14px; display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2;">
                                            <?php echo autop(esc_html(todo_html_to_plain_text($inquiry->comment_content))); ?>
                                            </div>
                                        </div>
                                        <div class="row-actions">
                                            <?php if ($approval_status !== 'approved'): ?>
                                                <span class="approve">
                                                    <a href="<?php echo todo_nonce_url(todo_admin_url('index.php?page=product-inquiries&action=approve&inquiry_id=' . $inquiry->comment_ID), 'approve_inquiry_' . $inquiry->comment_ID); ?>" class="js-todo-post-action" style="color:#0073aa;">批准</a> |
                                                </span>
                                            <?php else: ?>
                                                <span class="unapprove">
                                                    <a href="<?php echo todo_nonce_url(todo_admin_url('index.php?page=product-inquiries&action=pending&inquiry_id=' . $inquiry->comment_ID), 'pending_inquiry_' . $inquiry->comment_ID); ?>" class="js-todo-post-action" style="color:#0073aa;">设为待审核</a> |
                                                </span>
                                            <?php endif; ?>
                                            <?php if (empty($inquiry->comment_parent)): ?>
                                                <span class="reply">
                                                    <a href="javascript:void(0);" class="reply-inquiry"
                                                        data-inquiry-id="<?php echo $inquiry->comment_ID; ?>"
                                                        data-lang-code="<?php echo esc_attr($inquiry_lang_code); ?>">回复</a> |
                                                </span>
                                                <?php
                                                $cid = intval($inquiry->comment_ID);
                                                $unread_c = isset($unread_inquiry_map[$cid]) ? intval($unread_inquiry_map[$cid]) : 0;
                                                $has_thread = !empty($has_mail_thread_map[$cid]);
                                                $label = $unread_c > 0 ? (' 对话(' . $unread_c . ')') : ' 对话';
                                                ?>
                                                <?php if ($has_thread): ?>
                                                    <span class="view-thread">
                                                        <a href="<?php echo todo_admin_url('index.php?page=mail&tab=conversation&inquiry_id=' . $cid); ?>"><i class="icon todofont todo-CommentOne" style="margin-top: -2px;display: inline-table;"></i><?php echo esc_html($label); ?></a> |
                                                    </span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <span class="edit">
                                                <a href="<?php echo todo_admin_url('index.php?page=comment-edit&from=product-inquiries&comment_id=' . $inquiry->comment_ID); ?>">编辑</a> |
                                            </span>
                                            <?php
                                            $delete_url = todo_nonce_url(
                                                todo_admin_url('index.php?page=product-inquiries&action=delete&inquiry_id=' . $inquiry->comment_ID),
                                                'delete_inquiry_' . $inquiry->comment_ID
                                            );
                                            ?>
                                            <span class="delete">
                                                <a href="<?php echo $delete_url; ?>" class="delete-inquiry js-todo-post-action" style="color:#a00;" data-confirm="您确定要永久删除此咨询吗？">删除</a>
                                            </span>
                                            <?php if (empty($inquiry->comment_parent)): ?>
                                                |
                                                <span class="spam">
                                                    <a
                                                        href="<?php echo todo_nonce_url(todo_admin_url('index.php?page=product-inquiries&action=spam&inquiry_id=' . $inquiry->comment_ID), 'spam_inquiry_' . $inquiry->comment_ID); ?>" class="js-todo-post-action" data-confirm="确定标记为垃圾信息吗？">垃圾信息</a>
                                                    |
                                                </span>
                                                <span class="trash">
                                                    <a
                                                        href="<?php echo todo_nonce_url(todo_admin_url('index.php?page=product-inquiries&action=trash&inquiry_id=' . $inquiry->comment_ID), 'trash_inquiry_' . $inquiry->comment_ID); ?>" class="js-todo-post-action" data-confirm="确定移至回收站吗？">回收站</a>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <?php if (empty($inquiry->comment_parent)): ?>
                                    <td style="font-size: 14px;">
                                         <?php if ($post_title): ?>
                                             <?php 
                                             $product_permalink = get_permalink($inquiry->comment_post_ID);
                                             if (function_exists('get_language_url') && !empty($inquiry_lang_code)) {
                                                 $product_permalink = get_language_url($inquiry_lang_code, $product_permalink);
                                             }
                                             ?>
                                             <a href="<?php echo esc_url($product_permalink); ?>" target="_blank">
                                                 <?php echo esc_html($post_title); ?>
                                             </a>
                                        <?php else: ?>
                                            <span class="description">产品已删除</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                    <td>
                                        <span class="tb-meta"><?php echo date('Y-m-d H:i', strtotime($inquiry->comment_date)); ?></span>
                                    </td>
                                    <td>
                                        <span class="status-<?php echo $inquiry_status; ?>">
                                            <?php
                                            switch ($inquiry_status) {
                                                case 'replied':
                                                    echo '✓ 回复';
                                                    break;
                                                case 'approved':
                                                case 'approve':
                                                    echo '已通过';
                                                    break;
                                                case 'pending':
                                                case 'hold':
                                                    echo '待审核';
                                                    break;
                                                case 'spam':
                                                    echo '垃圾信息';
                                                    break;
                                                case 'trash':
                                                    echo '回收站';
                                                    break;
                                                default:
                                                    echo $inquiry_status;
                                                    break;
                                            }
                                            ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr class="empty">
                                <td colspan="6">
                                    <div class="no-items">
                                        <p>没有找到产品咨询。</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php if ($total_pages > 1): ?>
                    <?php
                    $pagination_add_args = array();
                    if (!empty($status) && $status !== 'all') {
                        $pagination_add_args['status'] = $status;
                    }
                    if (!empty($search)) {
                        $pagination_add_args['search'] = $search;
                    }

                    echo todo_render_pagination(array(
                        'total_items' => $total_items,
                        'per_page' => $per_page,
                        'current_page' => $paged,
                        'base_url' => todo_admin_url('index.php?page=product-inquiries'),
                        'add_args' => $pagination_add_args,
                        'show_count' => true,
                    ));
                    ?>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>

<template id="reply-inquiry-modal-template">
    <div data-slot="title">回复产品询盘</div>
    <div data-slot="body">
        <form id="reply-inquiry-form">
            <?php echo Todo_Security_Manager::csrf_field('reply_inquiry'); ?>
            <input type="hidden" id="reply-inquiry-id" name="inquiry_id">
            <input type="hidden" id="reply-inquiry-lang" name="lang_code">

            <div class="form-group">
                <label for="reply-content">回复内容：</label>
                <textarea id="reply-content" name="reply_content" style="width: 100%;" rows="6" required></textarea>
            </div>

            <div class="form-group">
                <label>
                    <input type="checkbox" name="send_email" value="1">
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
    <div data-slot="footer" class="todo-modal-footer admin-form-submit">
        <button type="button" class="button button-secondary todo-modal-cancel">取消</button>
        <button type="submit" form="reply-inquiry-form" class="button button-primary">发送回复</button>
    </div>
</template>

<template id="add-inquiry-modal-template" data-size="lg">
    <div data-slot="title">添加产品询盘</div>
    <div data-slot="body">
        <form id="add-inquiry-form">
            <?php echo Todo_Security_Manager::csrf_field('add_inquiry'); ?>

            <div class="form-group">
                <label for="inquiry-author">咨询者姓名：</label>
                <input type="text" id="inquiry-author" name="author" required>
            </div>

            <div class="form-group">
                <label for="inquiry-email">邮箱地址：</label>
                <input type="email" id="inquiry-email" name="email" required>
            </div>

            <div class="form-group">
                <label for="inquiry-product">相关产品：</label>
                <select id="inquiry-product" name="product_id" required>
                    <option value="">选择产品</option>
                    <?php
                    $products = get_posts(array(
                        'post_type' => 'product',
                        'post_status' => 'publish',
                        'numberposts' => -1
                    ));
                    foreach ($products as $product) {
                        echo '<option value="' . $product->ID . '">' . esc_html($product->post_title) . '</option>';
                    }
                    ?>
                </select>
            </div>

            <div class="form-group">
                <label for="inquiry-content">咨询内容：</label>
                <textarea id="inquiry-content" name="content" rows="6" required></textarea>
            </div>

            <div class="form-group">
                <label for="inquiry-status">状态：</label>
                <select id="inquiry-status" name="status">
                    <option value="pending">待审核</option>
                    <option value="approved">已通过</option>
                </select>
            </div>
        </form>
    </div>
    <div data-slot="footer" class="todo-modal-footer admin-form-submit">
        <button type="button" class="button button-secondary todo-modal-cancel">取消</button>
        <button type="submit" form="add-inquiry-form" class="button button-primary">添加咨询</button>
    </div>
</template>

<style>
    /* 产品咨询管理页面特定样式 */
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

    .search-box {
        float: right;
        margin: 0;
    }

    .search-box input[type="search"] {
        width: 280px;
        margin-right: 5px;
    }
</style>

<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function () {
        // 全选/取消全选
        var selectAll = document.getElementById('cb-select-all');
        var checkboxes = document.querySelectorAll('input[name="inquiry_ids[]"]');

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                checkboxes.forEach(function (checkbox) {
                    checkbox.checked = selectAll.checked;
                });
            });
        }

        // 批量操作表单提交验证
        var inquiriesForm = document.getElementById('inquiries-form');

        if (inquiriesForm) {
            inquiriesForm.addEventListener('submit', function (e) {
                var bulkAction = document.querySelector('select[name="action"]').value;
                var checkedBoxes = document.querySelectorAll('input[name="inquiry_ids[]"]:checked');

                if (bulkAction === '') {
                    e.preventDefault();
                    TodoCMS.Utils.showMessage('请选择一个批量操作。', 'warning');
                    return;
                }

                if (checkedBoxes.length === 0) {
                    e.preventDefault();
                    TodoCMS.Utils.showMessage('请选择至少一条咨询。', 'warning');
                    return;
                }

                if (bulkAction === 'delete') {
                    e.preventDefault();
                    TodoModal.confirm({
                        message: '您确定要永久删除选定的咨询吗？',
                        onConfirm: function () {
                            inquiriesForm.submit();
                        }
                    });
                }
            });
        }

    });

    jQuery(document).ready(function ($) {
        // 回复咨询
        $('.reply-inquiry').on('click', function (e) {
            e.preventDefault();
            var inquiryId = $(this).data('inquiry-id');
            var langCode = $(this).data('lang-code') || '';
            openReplyInquiryModal(inquiryId, langCode);
        });

    });

    function openReplyInquiryModal(inquiryId, langCode) {
        const modal = new TodoModal({
            templateId: 'reply-inquiry-modal-template',
            destroyOnClose: true
        });
        const $ctx = jQuery(modal.element);
        $ctx.find('#reply-inquiry-id').val(inquiryId);
        if ($ctx.find('#reply-inquiry-lang').length) {
            $ctx.find('#reply-inquiry-lang').val(langCode || '');
        }
        var $replyInput = $ctx.find('#reply-content');
        var $sendEmail = $ctx.find('input[name="send_email"]');

        // 获取模板列表
        var templateSelect = $ctx.find('#reply-template');
        function syncReplyRequired() {
            var shouldSkip = $sendEmail.is(':checked') && String(templateSelect.val() || '').trim() !== '';
            if ($replyInput.length) {
                $replyInput.prop('required', !shouldSkip);
            }
        }
        if ($sendEmail.length) {
            $sendEmail.on('change', syncReplyRequired);
        }
        if (templateSelect.length) {
            fetch('admin-ajax.php?action=get_templates_by_category&category=inquiry_reply', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data) {
                        data.data.forEach(tpl => {
                            var opt = document.createElement('option');
                            opt.value = tpl.slug;
                            opt.textContent = tpl.name;
                            templateSelect.append(opt);
                        });
                    }
                    syncReplyRequired();
                });

            templateSelect.on('change', function() {
                var slug = this.value;
                syncReplyRequired();
                if (slug) {
                    var fetchUrl = 'admin-ajax.php?action=get_template_content&id=0&slug=' + slug;
                    if (langCode) {
                        fetchUrl += '&lang=' + encodeURIComponent(langCode);
                    }
                    fetch(fetchUrl, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success && data.data) {
                                //$ctx.find('#reply-content').val(data.data.content.replace(/<[^>]*>?/gm, ''));
                            }
                        });
                }
            });
        }
        syncReplyRequired();

        $ctx.find('#reply-inquiry-form').on('submit', function (e) {
            e.preventDefault();
            var $form = jQuery(this);
            if ($form.attr('data-submitting') === '1') {
                return;
            }
            $form.attr('data-submitting', '1');
            var $btn = $ctx.find('.todo-modal-footer .button-primary');
            var btnText = $btn.length ? $btn.text() : '';
            if ($btn.length) {
                $btn.prop('disabled', true);
                $btn.text('发送中...');
            }
            var formData = jQuery(this).serialize();
            jQuery.ajax({
                url: window.location.href,
                type: 'POST',
                data: formData + '&action=reply_product_inquiry',
                dataType: 'json',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                },
                success: function (response) {
                    if (response.success) {
                        TodoCMS.Utils.showMessage('回复发送成功！', 'success');
                        modal.close();
                        location.reload();
                    } else {
                        TodoCMS.Utils.showMessage('发送失败：' + (response.message || response.data || '未知错误'), 'error');
                        $form.attr('data-submitting', '0');
                        if ($btn.length) {
                            $btn.prop('disabled', false);
                            $btn.text(btnText);
                        }
                    }
                },
                error: function () {
                    TodoCMS.Utils.showMessage('网络错误', 'error');
                    $form.attr('data-submitting', '0');
                    if ($btn.length) {
                        $btn.prop('disabled', false);
                        $btn.text(btnText);
                    }
                }
            });
        });
        modal.open();
        return modal;
    }

    function openAddInquiryModal() {
        const modal = new TodoModal({
            templateId: 'add-inquiry-modal-template',
            destroyOnClose: true
        });
        const $ctx = jQuery(modal.element);
        $ctx.find('#add-inquiry-form').on('submit', function (e) {
            e.preventDefault();
            var formData = jQuery(this).serialize();
            jQuery.ajax({
                url: window.location.href,
                type: 'POST',
                data: formData + '&action=add_product_inquiry',
                dataType: 'json',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                },
                success: function (response) {
                    if (response.success) {
                        TodoCMS.Utils.showMessage('咨询添加成功！', 'success');
                        modal.close();
                        location.reload();
                    } else {
                        TodoCMS.Utils.showMessage('添加失败：' + (response.message || response.data || '未知错误'), 'error');
                    }
                },
                error: function () {
                    TodoCMS.Utils.showMessage('网络错误', 'error');
                }
            });
        });
        modal.open();
        return modal;
    }
</script>

<!-- 引入CSRF管理器 -->
<script src="<?php echo todo_admin_url('assets/js/csrf-manager.js'); ?>" type="text/javascript"></script>
