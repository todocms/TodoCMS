<?php
/**
 * 管理后台评论管理页面
 *
 * @package TodoCMS
 * @subpackage Admin/Comment
 * @version 1.0.0
 */

/**
 * 用户函数声明 - 帮助IDE识别函数
 * 
 * @function get_avatar($email, $size = 96, $default = 'mp', $alt = '') 获取用户头像HTML
 * @function autop($text, $br = true) 自动段落格式化
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
if (!current_user_can('moderate_comments')) {
    http_response_code(403);
    todo_die('您没有足够的权限访问此页面。', '系统错误', array('response' => 403));
}

// 处理评论操作
$message = '';
$error = '';

// 处理单个评论操作（根评论删除将级联删除所有回复）
if (in_array(($_SERVER['REQUEST_METHOD'] ?? ''), ['POST', 'GET'], true)) {
    $is_get = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET');
    $comment_id = $is_get ? intval($_GET['comment_id'] ?? 0) : intval($_POST['comment_id'] ?? 0);
    $action = $is_get ? sanitize_text_field($_GET['action'] ?? '') : sanitize_text_field($_POST['action'] ?? '');

    if ($comment_id > 0 && in_array($action, ['approve', 'pending', 'unapprove', 'spam', 'trash', 'untrash', 'delete'], true)) {
        $nonce = (string) ($is_get ? ($_GET['_todononce'] ?? '') : ($_POST['_todononce'] ?? ''));
        $nonce_action = $action . '_comment_' . $comment_id;
        if (function_exists('todo_verify_nonce') && ($nonce === '' || !todo_verify_nonce($nonce, $nonce_action))) {
            if (function_exists('todo_add_admin_notice')) {
                todo_add_admin_notice('安全验证失败，请刷新页面后重试。', 'error');
            }
            todo_redirect(todo_admin_url('index.php?page=comments'));
            exit;
        }

        $ok = false;
        switch ($action) {
            case 'approve':
                $ok = todo_set_comment_status($comment_id, 'approve');
                break;
            case 'pending':
            case 'unapprove':
                $ok = todo_set_comment_status($comment_id, 'hold');
                break;
            case 'spam':
                $ok = todo_spam_comment($comment_id);
                break;
            case 'trash':
                $ok = todo_trash_comment($comment_id);
                break;
            case 'untrash':
                $ok = todo_untrash_comment($comment_id);
                break;
            case 'delete':
                $ok = todo_delete_comment($comment_id, true);
                break;
        }

        if (function_exists('todo_add_admin_notice')) {
            if ($ok) {
                $map = [
                    'approve' => '评论已批准。',
                    'pending' => '评论已设为待审核。',
                    'unapprove' => '评论已设为待审核。',
                    'spam' => '评论已标记为垃圾评论。',
                    'trash' => '评论已移至回收站。',
                    'untrash' => '评论已从回收站恢复。',
                    'delete' => '评论已永久删除（包含所有回复）。',
                ];
                todo_add_admin_notice($map[$action] ?? '操作成功。', 'success');
            } else {
                todo_add_admin_notice('操作失败，请稍后重试。', 'error');
            }
        }
        todo_redirect(todo_admin_url('index.php?page=comments'));
        exit;
    }
}

// 处理批量操作（仅作用于主评论）
if (isset($_POST['bulk_action']) && isset($_POST['comment_ids']) && is_array($_POST['comment_ids'])) {
    if (class_exists('Todo_Security_Manager') && !Todo_Security_Manager::verify_request_csrf('bulk_action_comments')) {
        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice('安全验证失败，请刷新页面后重试。', 'error');
        }
        todo_redirect(todo_admin_url('index.php?page=comments'));
        exit;
    }

    $bulk_action = sanitize_text_field((string) ($_POST['bulk_action'] ?? ''));
    $comment_ids = array_map('intval', $_POST['comment_ids']);
    $comment_ids = array_values(array_filter($comment_ids, function ($id) {
        return $id > 0;
    }));

    $processed = 0;
    foreach ($comment_ids as $comment_id) {
        $ok = false;
        switch ($bulk_action) {
            case 'approve':
                $ok = todo_set_comment_status($comment_id, 'approve');
                break;
            case 'pending':
                $ok = todo_set_comment_status($comment_id, 'hold');
                break;
            case 'spam':
                $ok = todo_spam_comment($comment_id);
                break;
            case 'trash':
                $ok = todo_trash_comment($comment_id);
                break;
            case 'untrash':
                $ok = todo_untrash_comment($comment_id);
                break;
            case 'delete':
                $ok = todo_delete_comment($comment_id, true);
                break;
        }
        if ($ok) {
            $processed++;
        }
    }

    if (function_exists('todo_add_admin_notice')) {
        $action_map = [
            'approve' => '批准',
            'pending' => '设为待审核',
            'spam' => '标记为垃圾评论',
            'trash' => '移至回收站',
            'untrash' => '恢复',
            'delete' => '永久删除',
        ];
        $action_text = $action_map[$bulk_action] ?? $bulk_action;
        todo_add_admin_notice("已成功{$action_text} {$processed} 条评论。", 'success');
    }
    $redirect_params = [
        'page' => 'comments',
    ];
    if (isset($_POST['status']) && $_POST['status'] !== '') {
        $redirect_params['status'] = sanitize_text_field($_POST['status']);
    }
    if (isset($_POST['search']) && $_POST['search'] !== '') {
        $redirect_params['search'] = sanitize_text_field($_POST['search']);
    }
    if (isset($_POST['author_id']) && $_POST['author_id'] !== '') {
        $redirect_params['author_id'] = intval($_POST['author_id']);
    }
    if (isset($_POST['post_id']) && $_POST['post_id'] !== '') {
        $redirect_params['post_id'] = intval($_POST['post_id']);
    }
    todo_redirect(todo_admin_url('index.php?' . http_build_query($redirect_params)));
    exit;
}

// 处理回复评论 Ajax 请求
if (isset($_POST['action']) && $_POST['action'] === 'reply_comment') {
    // 验证 CSRF Token
    if (!class_exists('Todo_Security_Manager') || !Todo_Security_Manager::verify_request_csrf('reply_comment')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '安全验证失败']);
        exit;
    }

    $comment_id = intval($_POST['comment_id'] ?? 0);
    $reply_content = trim((string) ($_POST['reply_content'] ?? ''));
    $notify_user = isset($_POST['send_email']) ? 1 : 0;

    if (!$comment_id || !$reply_content) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '回复内容不能为空']);
        exit;
    }

    // 获取父评论
    $parent_comment = $tododb->get_row("SELECT * FROM {$tododb->prefix}comments WHERE comment_ID = ?", [$comment_id]);
    if (!$parent_comment) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '原评论不存在']);
        exit;
    }

    // 获取当前用户信息
    $current_user = todo_get_current_user();
    
    $comment_data = [
        'comment_post_ID' => $parent_comment->comment_post_ID,
        'comment_parent' => $comment_id,
        'comment_content' => $reply_content,
        'user_id' => $current_user->ID,
        'comment_author' => $current_user->display_name,
        'comment_author_email' => $current_user->user_email,
        'comment_author_url' => $current_user->user_url,
        'comment_approved' => 1,
        'comment_type' => 'comment',
    ];

    // 尝试插入评论
    $new_comment_id = 0;
    if (function_exists('todo_create_comment')) {
        $new_comment_id = todo_create_comment($comment_data);
    } elseif (class_exists('CMS_Comment')) {
        $new_comment_id = CMS_Comment::create($comment_data);
    }

    if ($new_comment_id) {
        // 如果需要发送邮件通知
        if ($notify_user) {
            if (!function_exists('todo_send_comment_reply_notification') || !function_exists('todo_send_template_mail')) {
                if (!defined('ABSPATH')) {
                    define('ABSPATH', dirname(__FILE__) . '/../../../');
                }
                $mail_extend_path = rtrim(ABSPATH, '/') . '/core/functions/mail-extend.php';
                if (is_file($mail_extend_path)) {
                    require_once $mail_extend_path;
                }
            }
            if (function_exists('todo_send_comment_reply_notification')) {
                $template_slug = $_POST['template_slug'] ?? '';
                todo_send_comment_reply_notification($new_comment_id, $template_slug);
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '回复保存失败']);
    }
    exit;
}

// 获取筛选参数
$status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : (isset($_GET['comment_status']) ? sanitize_text_field($_GET['comment_status']) : 'all');
$search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : (isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '');
$paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 20;
$offset = ($paged - 1) * $per_page;

$status_map = [
    'approved' => '1',
    'approve' => '1',
    'pending' => '0',
    'hold' => '0',
    'spam' => 'spam',
    'trash' => 'trash',
];

$args = [
    'type' => ['comment'],
    'parent_id' => 0,
    'offset' => $offset,
    'limit' => $per_page,
    'order_by' => 'created_at',
    'order' => 'DESC',
    'include_children' => false,
    'include_meta' => false,
    'has_children' => null,
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

if (isset($_GET['author_id']) && $_GET['author_id'] !== '') {
    $args['user_id'] = intval($_GET['author_id']);
}

if (isset($_GET['post_id']) && $_GET['post_id'] !== '') {
    $args['post_id'] = intval($_GET['post_id']);
}

$root_comments = class_exists('Todo_Comment_Manager') ? Todo_Comment_Manager::get_comments($args) : [];

$args['count'] = true;
$total_comments = (int) (class_exists('Todo_Comment_Manager') ? Todo_Comment_Manager::get_comments($args) : 0);
$total_pages = ceil($total_comments / $per_page);

$comments = [];
if (!empty($root_comments)) {
    foreach ($root_comments as $root) {
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
        $comments[] = $root;
    }
}

// 获取各状态评论数量（仅统计根评论）
$status_counts = [];
$statuses_to_count = ['all', 'pending', 'approved', 'replied', 'spam', 'trash'];
foreach ($statuses_to_count as $s) {
    $stats_args = [
        'type' => ['comment'],
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

<div class="admin-comments-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">评论</h2>
    </div>
    
    <div class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-title-section">
                <h3 class="admin-card-title">评论列表</h3>
            </div>
            
            <div class="admin-card-actions">
                <form method="get" class="search-form todo-request">
                    <input type="hidden" name="page" value="comments">
                    <?php if ($status !== 'all'): ?>
                        <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>">
                    <?php endif; ?>
                    <input type="search" name="search" value="<?php echo esc_attr($search); ?>" placeholder="搜索评论...">
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
                        'spam' => '垃圾评论',
                        'trash' => '回收站'
                    );
                    foreach ($status_labels as $s => $label):
                        $count = isset($status_counts[$s]) ? $status_counts[$s] : 0;
                        $url = todo_admin_url('index.php?page=comments&status=' . rawurlencode($s));
                        ?>
                        <li>
                            <a href="<?php echo $url; ?>" class="<?php echo $status === $s ? 'current' : ''; ?>">
                                <?php echo $label; ?> <span class="count">(<?php echo $count; ?>)</span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <?php if (empty($comments)): ?>
                <div class="no-items">
                    <p>没有找到评论。</p>
                </div>
            <?php else: ?>
                <form method="post" id="comments-form" class="todo-request">
                    <?php if (class_exists('Todo_Security_Manager')) echo Todo_Security_Manager::csrf_field('bulk_action_comments'); ?>
                    <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>">
                    <input type="hidden" name="search" value="<?php echo esc_attr($search); ?>">
                    <input type="hidden" name="author_id" value="<?php echo isset($_GET['author_id']) ? esc_attr($_GET['author_id']) : ''; ?>">
                    <input type="hidden" name="post_id" value="<?php echo isset($_GET['post_id']) ? esc_attr($_GET['post_id']) : ''; ?>">

                    <div class="bulk-actions-bar">
                        <div class="bulk-actions">
                            <select name="bulk_action">
                                <option value="">批量操作</option>
                                <?php if ($status !== 'approved'): ?>
                                    <option value="approve">批准</option>
                                <?php endif; ?>
                                <?php if ($status !== 'pending'): ?>
                                    <option value="pending">设为待审核</option>
                                <?php endif; ?>
                                <?php if ($status !== 'spam'): ?>
                                    <option value="spam">标记为垃圾评论</option>
                                <?php endif; ?>
                                <?php if ($status !== 'trash'): ?>
                                    <option value="trash">移至回收站</option>
                                <?php else: ?>
                                    <option value="untrash">从回收站恢复</option>
                                    <option value="delete">永久删除</option>
                                <?php endif; ?>
                            </select>
                            <button type="submit" class="button button-secondary">应用</button>
                        </div>
                    </div>

                <table class="admin-table comments-table">
                    <thead>
                        <tr>
                            <th class="check-column">
                                <input type="checkbox" id="cb-select-all">
                            </th>
                            <th>作者</th>
                            <th style="min-width: 250px;">评论内容</th>
                            <th style="min-width: 200px;">文章</th>
                            <th style="min-width: 160px;">提交时间</th>
                            <th style="min-width: 80px;">状态</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($comments as $comment): ?>
                            <?php
                            $comment_id = (int) $comment->comment_ID;
                            $comment_author = (string) ($comment->comment_author ?? '');
                            $comment_author_email = (string) ($comment->comment_author_email ?? '');
                            $comment_author_url = (string) ($comment->comment_author_url ?? '');
                            $comment_content = (string) todo_decode_html_entities($comment->comment_content ?? '', 2);
                            $comment_date = date_i18n('Y-m-d H:i:s', strtotime($comment->comment_date ?? 'now'));

                            $raw_status = todo_get_comment_status($comment_id);
                            $approval_status = ($raw_status === 'approve') ? 'approved' : (($raw_status === 'hold') ? 'pending' : $raw_status);
                            $has_approved_descendant = function ($node) use (&$has_approved_descendant) {
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
                                    if ($has_approved_descendant($child)) {
                                        return true;
                                    }
                                }
                                return false;
                            };
                            $display_status = ($has_approved_descendant($comment) && !in_array($approval_status, ['spam', 'trash'], true)) ? 'replied' : $approval_status;

                            $post = get_post($comment->comment_post_ID ?? 0);
                            $post_title = $post ? $post->post_title : '';
                            $post_link = $post ? todo_admin_url('index.php?page=post-edit&post=' . $post->ID . '&from=comments') : '';

                            $avatar = get_avatar($comment_author_email, 40);

                            $build_action_url = function ($action) use ($comment_id) {
                                $url = todo_admin_url('index.php?page=comments&action=' . rawurlencode($action) . '&comment_id=' . $comment_id);
                                if (function_exists('todo_nonce_url')) {
                                    return todo_nonce_url($url, $action . '_comment_' . $comment_id);
                                }
                                return $url;
                            };
                            ?>
                            <tr class="comment-row <?php echo esc_attr($approval_status); ?>">
                                <td>
                                    <input type="checkbox" name="comment_ids[]" value="<?php echo esc_attr($comment_id); ?>">
                                </td>
                                <td>
                                    <div class="comment-author">
                                        <?php echo todo_kses_post($avatar); ?>
                                        <div class="author-info">
                                            <strong><?php echo esc_html($comment_author ?: '匿名'); ?></strong>
                                            <?php if ($comment_author_email !== ''): ?>
                                                <div class="author-email">
                                                    <?php echo esc_html($comment_author_email); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($comment_author_url !== ''): ?>
                                                <div class="author-url" style="opacity: 0.75;">
                                                    <?php echo esc_html($comment_author_url); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td style="width: 240px;">
                                    <?php if (!empty($comment->todo_is_private)): ?>
                                        <div><strong>私密评论</strong></div>
                                    <?php endif; ?>
                                    <div class="comment-content" style="overflow: hidden; max-height: 60px; line-height: 20px; font-size: 14px;">
                                        <?php echo todo_kses_post(autop(esc_html(todo_html_to_plain_text($comment_content)))); ?>
                                    </div>

                                    <div class="row-actions">
                                        <?php if ($approval_status !== 'approved'): ?>
                                            <span class="approve"><a href="<?php echo $build_action_url('approve'); ?>">批准</a> |</span>
                                        <?php endif; ?>
                                        <?php if ($approval_status !== 'pending'): ?>
                                            <span class="pending"><a href="<?php echo $build_action_url('pending'); ?>">待审核</a> |</span>
                                        <?php endif; ?>
                                        <span class="reply"><a href="javascript:void(0);" class="reply-comment-btn" data-comment-id="<?php echo $comment_id; ?>">回复</a> |</span>
                                        <span class="edit"><a href="<?php echo todo_admin_url('index.php?page=comment-edit&from=comments&comment_id=' . $comment_id); ?>">编辑</a> |</span>
                                        <?php if ($approval_status !== 'spam'): ?>
                                            <span class="spam"><a href="<?php echo $build_action_url('spam'); ?>">垃圾评论</a> |</span>
                                        <?php endif; ?>
                                        <?php if ($approval_status !== 'trash'): ?>
                                            <span class="trash"><a href="<?php echo $build_action_url('trash'); ?>">回收站</a></span>
                                        <?php else: ?>
                                            <span class="untrash"><a href="<?php echo $build_action_url('untrash'); ?>">恢复</a> |</span>
                                            <span class="delete"><a href="<?php echo $build_action_url('delete'); ?>" class="delete-comment submitdelete">删除</a></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td style="min-width: 160px;">
                                    <?php if ($post): ?>
                                        <a href="<?php echo esc_url($post_link); ?>"><?php echo esc_html($post_title); ?></a>
                                    <?php else: ?>
                                        <em>（文章已删除）</em>
                                    <?php endif; ?>
                                </td>
                                <td style="width: 160px;">
                                    <span class="tb-meta"><?php echo esc_html($comment_date); ?></span>
                                </td>
                                <td style="min-width: 80px;">
                                    <span class="status-<?php echo esc_attr($display_status); ?>">
                                        <?php
                                        switch ($display_status) {
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
                                                echo '垃圾评论';
                                                break;
                                            case 'trash':
                                                echo '回收站';
                                                break;
                                            default:
                                                echo esc_html($display_status);
                                                break;
                                        }
                                        ?>
                                    </span>
                                </td>
                            </tr>

                            <?php if (!empty($comment->children) && is_array($comment->children)): ?>
                                <?php
                                $stack = [];
                                $push = function ($items, $depth) use (&$stack) {
                                    if (!is_array($items)) return;
                                    foreach (array_reverse($items) as $it) {
                                        $stack[] = ['c' => $it, 'd' => $depth];
                                    }
                                };
                                $push($comment->children, 1);
                                while (!empty($stack)) {
                                    $node = array_pop($stack);
                                    $c = $node['c'];
                                    $d = (int) $node['d'];
                                    if (!is_object($c)) continue;
                                    $rid = (int) ($c->comment_ID ?? 0);
                                    if ($rid <= 0) continue;
                                    $r_author = (string) ($c->comment_author ?? '回复');
                                    $r_email = (string) ($c->comment_author_email ?? '');
                                    if ($r_email === '') {
                                        $r_user_id = intval($c->user_id ?? 0);
                                        if ($r_user_id > 0) {
                                            if (function_exists('todo_get_user')) {
                                                $u = todo_get_user($r_user_id);
                                                if ($u && !empty($u->user_email)) {
                                                    $r_email = (string) $u->user_email;
                                                }
                                            } elseif (function_exists('get_user_by')) {
                                                $u = get_user_by('id', $r_user_id);
                                                if ($u && !empty($u->user_email)) {
                                                    $r_email = (string) $u->user_email;
                                                }
                                            }
                                        }
                                    }
                                    $r_content = (string) todo_decode_html_entities($c->comment_content ?? '', 2);
                                    $r_date_raw = (string) ($c->comment_date ?? '');
                                    $r_date = $r_date_raw !== '' ? date_i18n('Y-m-d H:i:s', strtotime($r_date_raw)) : '';
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
                                    $r_row_class = $r_approval_status;
                                    $delete_url = todo_admin_url('index.php?page=comments&action=delete&comment_id=' . $rid);
                                    if (function_exists('todo_nonce_url')) {
                                        $delete_url = todo_nonce_url($delete_url, 'delete_comment_' . $rid);
                                    }
                                    $build_r_action_url = function ($action) use ($rid) {
                                        $url = todo_admin_url('index.php?page=comments&action=' . rawurlencode($action) . '&comment_id=' . $rid);
                                        if (function_exists('todo_nonce_url')) {
                                            return todo_nonce_url($url, $action . '_comment_' . $rid);
                                        }
                                        return $url;
                                    };
                                    $r_parent_id = intval($c->comment_parent ?? 0);
                                    global $tododb;
                                    $r_parent_author = class_exists('Todo_Comment_Manager') ? (string) Todo_Comment_Manager::get_comment_meta($rid, 'reply_to_name', true) : '';
                                    if ($r_parent_author === '' && $r_parent_id > 0) {
                                        $p_row = $tododb->get_row("SELECT comment_author FROM {$tododb->prefix}comments WHERE comment_ID = ?", [$r_parent_id]);
                                        if ($p_row) {
                                            $r_parent_author = (string) ($p_row->comment_author ?? '');
                                        }
                                    }
                                    if ($r_parent_author === '') {
                                        $r_parent_author = (string) ($comment->comment_author ?? '');
                                    }
                                    ?>
                                    <tr class="comment-row reply-row <?php echo esc_attr($r_row_class); ?>">
                                        <td></td>
                                        <td>
                                            <div class="comment-author" style="margin-left: <?php echo $d * 10; ?>px;">
                                                <?php echo todo_kses_post(get_avatar($r_email, 40)); ?>
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
                                        <td colspan="2" style="padding-left: <?php echo $d * 10; ?>px;">
                                             <div class="comment-content flex items-center gap-1">
                                                 <span class="reply-indicator" style="font-size: 12px; opacity: 0.5; flex-shrink: 0;">↳ 回复 @<?php echo esc_html($r_parent_author); ?>:</span>
                                                 <div style="overflow: hidden; max-height: 40px; line-height: 20px; font-size: 14px;">
                                                    <?php echo todo_kses_post(autop(esc_html(todo_html_to_plain_text($r_content)))); ?>
                                                </div>
                                            </div>
                                            <div class="row-actions">
                                                <?php if ($r_approval_status !== 'approved'): ?>
                                                    <span class="approve"><a href="<?php echo $build_r_action_url('approve'); ?>" style="color:#0073aa;">批准</a> |</span>
                                                <?php else: ?>
                                                    <span class="unapprove"><a href="<?php echo $build_r_action_url('unapprove'); ?>" style="color:#0073aa;">设为待审核</a> |</span>
                                                <?php endif; ?>
                                                <span class="reply"><a href="javascript:void(0);" class="reply-comment-btn" data-comment-id="<?php echo $rid; ?>">回复</a> |</span>
                                                <span class="edit"><a href="<?php echo todo_admin_url('index.php?page=comment-edit&from=comments&comment_id=' . $rid); ?>">编辑</a> |</span>
                                                <span class="delete"><a href="<?php echo $delete_url; ?>" class="delete-comment submitdelete" style="color:#a00;">删除</a></span>
                                            </div>
                                        </td>
                                        <td style="width: 160px;">
                                            <span class="tb-meta"><?php echo esc_html($r_date); ?></span>
                                        </td>
                                        <td style="min-width: 80px;">
                                            <span class="status-<?php echo esc_attr($r_display_status); ?>">
                                                <?php
                                                switch ($r_display_status) {
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
                                                        echo '垃圾评论';
                                                        break;
                                                    case 'trash':
                                                        echo '回收站';
                                                        break;
                                                    default:
                                                        echo esc_html($r_display_status);
                                                        break;
                                                }
                                                ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php
                                    if (!empty($c->children) && is_array($c->children)) {
                                        $push($c->children, $d + 1);
                                    }
                                }
                                ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </form>

                <?php if ($total_pages > 1): ?>
                    <?php
                    $pagination_add_args = array();
                    if (!empty($status) && $status !== 'all') {
                        $pagination_add_args['status'] = $status;
                    }
                    if ($search !== '') {
                        $pagination_add_args['search'] = $search;
                    }
                    if (isset($_GET['author_id']) && $_GET['author_id'] !== '') {
                        $pagination_add_args['author_id'] = intval($_GET['author_id']);
                    }
                    if (isset($_GET['post_id']) && $_GET['post_id'] !== '') {
                        $pagination_add_args['post_id'] = intval($_GET['post_id']);
                    }

                    echo todo_render_pagination(array(
                        'total_items' => $total_comments,
                        'per_page' => $per_page,
                        'current_page' => $paged,
                        'base_url' => todo_admin_url('index.php?page=comments'),
                        'add_args' => $pagination_add_args,
                        'show_count' => true,
                    ));
                    ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<template id="reply-comment-modal-template">
    <div data-slot="title">回复评论</div>
    <div data-slot="body">
        <form id="reply-comment-form">
            <?php if (class_exists('Todo_Security_Manager')) echo Todo_Security_Manager::csrf_field('reply_comment'); ?>
            <input type="hidden" id="reply-comment-id" name="comment_id">
            <input type="hidden" name="action" value="reply_comment">

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
        <button type="submit" form="reply-comment-form" class="button button-primary">发送回复</button>
    </div>
</template>

<style>
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
</style>

<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function() {
    // 注册回复评论弹窗逻辑
    document.body.addEventListener('click', function(e) {
        var target = e.target.closest('.reply-comment-btn');
        if (target) {
            e.preventDefault();
            var commentId = target.getAttribute('data-comment-id');
            
            if (typeof TodoModal !== 'undefined') {
                const modal = new TodoModal({
                    templateId: 'reply-comment-modal-template',
                    destroyOnClose: true,
                    onOpen: function(modalInstance) {
                        var modalEl = modalInstance.element;
                        var form = modalEl.querySelector('form');
                        var idInput = modalEl.querySelector('#reply-comment-id');
                        
                        if (idInput) idInput.value = commentId;
    
                        // 获取模板列表
                        var templateSelect = modalEl.querySelector('#reply-template');
                        var replyInput = modalEl.querySelector('#reply-content');
                        var sendEmail = modalEl.querySelector('input[name="send_email"]');
                        var syncReplyRequired = function () {
                            var tplVal = templateSelect ? String(templateSelect.value || '').trim() : '';
                            var shouldSkip = !!(sendEmail && sendEmail.checked && tplVal !== '');
                            if (replyInput) {
                                replyInput.required = !shouldSkip;
                            }
                        };
                        if (templateSelect) {
                            fetch('admin-ajax.php?action=get_templates_by_category&category=comment_reply', {
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
                                syncReplyRequired();
                                var slug = this.value;
                                if (slug) {
                                    fetch('admin-ajax.php?action=get_template_content&id=0&slug=' + slug, {
                                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                                    }) // My ajax handler should support slug or I use get_templates_by_category data
                                        .then(response => response.json())
                                        .then(data => {
                                            if (data.success && data.data) {
                                                //modalEl.querySelector('#reply-content').value = data.data.content.replace(/<[^>]*>?/gm, ''); // Simple strip tags for textarea
                                            }
                                        });
                                }
                            });
                        }
                        if (sendEmail) {
                            sendEmail.addEventListener('change', syncReplyRequired);
                        }
                        syncReplyRequired();
                        
                        if (form) {
                            form.addEventListener('submit', function(e) {
                                e.preventDefault();
                                if (form.getAttribute('data-submitting') === '1') {
                                    return;
                                }
                                form.setAttribute('data-submitting', '1');
                                var submitBtn = modalEl.querySelector('.todo-modal-footer .button-primary');
                                var submitText = submitBtn ? submitBtn.textContent : '';
                                if (submitBtn) {
                                    submitBtn.disabled = true;
                                    submitBtn.textContent = '发送中...';
                                }
                                var formData = new FormData(this);
                                if (!formData.has('action')) {
                                    formData.append('action', 'reply_comment');
                                }
                                
                                if (typeof jQuery !== 'undefined') {
                                    jQuery.ajax({
                                        url: 'admin-ajax.php',
                                        type: 'POST',
                                        data: formData,
                                        processData: false,
                                        contentType: false,
                                        dataType: 'json',
                                        success: function(response) {
                                            if (response && response.success) {
                                                TodoCMS.Utils.showMessage('回复发送成功！', 'success');
                                                modalInstance.close();
                                                window.location.reload();
                                            } else {
                                                var msg = (response && response.message) ? response.message : '回复失败';
                                                TodoModal.error(msg);
                                                form.setAttribute('data-submitting', '0');
                                                if (submitBtn) {
                                                    submitBtn.disabled = false;
                                                    submitBtn.textContent = submitText;
                                                }
                                            }
                                        },
                                        error: function() {
                                            TodoModal.error('网络错误');
                                            form.setAttribute('data-submitting', '0');
                                            if (submitBtn) {
                                                submitBtn.disabled = false;
                                                submitBtn.textContent = submitText;
                                            }
                                        }
                                    });
                                }
                            });
                        }
                    }
                });
                modal.open();
            }
        }
    });

    // 删除评论确认
    var deleteLinks = document.querySelectorAll('.delete-comment');
    
    deleteLinks.forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            TodoCMS.Utils.confirmAction(this, '您确定要永久删除此评论及其所有回复吗？');
        });
    });

    // 全选/取消全选（仅主评论）
    var selectAll = document.getElementById('cb-select-all');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            var isChecked = !!selectAll.checked;
            document.querySelectorAll('input[name="comment_ids[]"]').forEach(function(cb) {
                cb.checked = isChecked;
            });
        });
    }

    // 批量操作确认
    var bulkForm = document.getElementById('comments-form');
    if (bulkForm) {
        bulkForm.addEventListener('submit', function(e) {
            var actionEl = bulkForm.querySelector('select[name="bulk_action"]');
            var action = actionEl ? (actionEl.value || '') : '';
            var checkedItems = bulkForm.querySelectorAll('input[name="comment_ids[]"]:checked').length;

            if (!action) {
                e.preventDefault();
                TodoModal.warning('请选择批量操作');
                return false;
            }
            if (checkedItems === 0) {
                e.preventDefault();
                TodoModal.warning('请选择要操作的评论');
                return false;
            }

            if (action === 'delete') {
                e.preventDefault();
                if (typeof TodoModal === 'undefined') {
                    if (confirm('确定要永久删除选中的评论及其所有回复吗？')) bulkForm.submit();
                    return false;
                }
                TodoModal.deleteConfirm({
                    title: '确认删除',
                    message: '确定要永久删除选中的评论及其所有回复吗？',
                    onConfirm: function () {
                        bulkForm.submit();
                    }
                });
                return false;
            }
        });
    }
});
</script>
