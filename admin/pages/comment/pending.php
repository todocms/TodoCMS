<?php

if (!defined('ADMIN_DIR') && !defined('TODO_LOADED')) {
    http_response_code(403);
    exit('Direct access denied');
}

if (!defined('TODO_ADMIN') || !TODO_ADMIN) {
    http_response_code(403);
    exit('Access denied: Admin area required');
}

if (!isset($tododb)) {
    global $tododb;
    if (!isset($tododb)) {
        $tododb = new CMS_DB();
    }
}

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

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

if (!current_user_can('moderate_comments')) {
    http_response_code(403);
    todo_die('您没有足够的权限访问此页面。', '系统错误', array('response' => 403));
}

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $comment_id = intval($_POST['comment_id'] ?? 0);
    $action = sanitize_text_field($_POST['action'] ?? '');

    if ($comment_id > 0 && in_array($action, ['approve', 'pending', 'unapprove', 'spam', 'trash', 'untrash', 'delete'], true)) {
        $nonce = (string) ($_POST['_todononce'] ?? '');
        $nonce_action = $action . '_comment_' . $comment_id;
        if (function_exists('todo_verify_nonce') && ($nonce === '' || !todo_verify_nonce($nonce, $nonce_action))) {
            if (function_exists('todo_add_admin_notice')) {
                todo_add_admin_notice('安全验证失败，请刷新页面后重试。', 'error');
            }
            todo_redirect(todo_admin_url('index.php?page=comment-pending'));
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

        $qs = $_GET;
        unset($qs['action'], $qs['comment_id'], $qs['_todononce']);
        $qs['page'] = 'comment-pending';
        if (function_exists('todo_add_admin_notice')) {
            if ($ok) {
                $map = [
                    'approve' => '已批准。',
                    'pending' => '已设为待审核。',
                    'unapprove' => '已设为待审核。',
                    'spam' => '已标记为垃圾评论。',
                    'trash' => '已移至回收站。',
                    'untrash' => '已从回收站恢复。',
                    'delete' => '已永久删除（包含所有回复）。',
                ];
                todo_add_admin_notice($map[$action] ?? '操作成功。', 'success');
            } else {
                todo_add_admin_notice('操作失败，请稍后重试。', 'error');
            }
        }
        todo_redirect(todo_admin_url('index.php?' . http_build_query($qs)));
        exit;
    }
}

if (isset($_POST['bulk_action']) && isset($_POST['comment_ids']) && is_array($_POST['comment_ids'])) {
    if (class_exists('Todo_Security_Manager') && !Todo_Security_Manager::verify_request_csrf('bulk_action_pending_comments')) {
        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice('安全验证失败，请刷新页面后重试。', 'error');
        }
        todo_redirect(todo_admin_url('index.php?page=comment-pending'));
        exit;
    }

    $bulk_action = sanitize_text_field($_POST['bulk_action']);
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
        todo_add_admin_notice("已成功{$action_text} {$processed} 条内容。", 'success');
    }
    $redirect_params = [
        'page' => 'comment-pending',
    ];
    if (isset($_POST['pt']) && $_POST['pt'] !== '') {
        $redirect_params['pt'] = sanitize_text_field($_POST['pt']);
    }
    if (isset($_POST['search']) && $_POST['search'] !== '') {
        $redirect_params['search'] = sanitize_text_field($_POST['search']);
    }
    todo_redirect(todo_admin_url('index.php?' . http_build_query($redirect_params)));
    exit;
}

$pt = isset($_GET['pt']) ? sanitize_text_field($_GET['pt']) : 'all';
$search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
$paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 20;
$offset = ($paged - 1) * $per_page;

$comments_table = $tododb->prefix . 'comments';
$posts_table = $tododb->prefix . 'posts';

$where = ["c.comment_approved = '0'", 'c.comment_parent = 0', "(c.comment_type IS NULL OR c.comment_type != 'review')"];
$params = [];

if ($pt === 'article') {
    $where[] = "p.post_type = 'article'";
} elseif ($pt === 'product') {
    $where[] = "p.post_type = 'product'";
} elseif ($pt === 'message') {
    $where[] = "(p.post_type = 'guestbook' OR p.post_type = 'message')";
} elseif ($pt === 'other') {
    $where[] = "(p.post_type IS NULL OR (p.post_type NOT IN ('article','product','guestbook','message')))";
}

if ($search !== '') {
    $where[] = "(c.comment_content LIKE ? OR c.comment_author LIKE ? OR c.comment_author_email LIKE ?)";
    $kw = '%' . $search . '%';
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
}

$where_sql = implode(' AND ', $where);

$total_items = (int) $tododb->get_var(
    "SELECT COUNT(*) FROM {$comments_table} c LEFT JOIN {$posts_table} p ON c.comment_post_ID = p.ID WHERE {$where_sql}",
    $params
);
$total_pages = (int) ceil(($total_items > 0 ? $total_items : 0) / $per_page);

$items_params = $params;
$items_params[] = $per_page;
$items_params[] = $offset;
$items = $tododb->get_results(
    "SELECT c.*, p.post_title, p.post_type
     FROM {$comments_table} c
     LEFT JOIN {$posts_table} p ON c.comment_post_ID = p.ID
     WHERE {$where_sql}
     ORDER BY c.comment_date DESC
     LIMIT ? OFFSET ?",
    $items_params
);
if (!$items || !is_array($items)) {
    $items = [];
}

$counts = [
    'all' => 0,
    'article' => 0,
    'product' => 0,
    'message' => 0,
    'other' => 0,
];

$counts['all'] = (int) $tododb->get_var(
    "SELECT COUNT(*) FROM {$comments_table} c LEFT JOIN {$posts_table} p ON c.comment_post_ID = p.ID WHERE c.comment_approved = '0' AND c.comment_parent = 0 AND (c.comment_type IS NULL OR c.comment_type != 'review')"
);
$counts['article'] = (int) $tododb->get_var(
    "SELECT COUNT(*) FROM {$comments_table} c LEFT JOIN {$posts_table} p ON c.comment_post_ID = p.ID WHERE c.comment_approved = '0' AND c.comment_parent = 0 AND p.post_type = 'article' AND (c.comment_type IS NULL OR c.comment_type != 'review')"
);
$counts['product'] = (int) $tododb->get_var(
    "SELECT COUNT(*) FROM {$comments_table} c LEFT JOIN {$posts_table} p ON c.comment_post_ID = p.ID WHERE c.comment_approved = '0' AND c.comment_parent = 0 AND p.post_type = 'product' AND (c.comment_type IS NULL OR c.comment_type != 'review')"
);
$counts['message'] = (int) $tododb->get_var(
    "SELECT COUNT(*) FROM {$comments_table} c LEFT JOIN {$posts_table} p ON c.comment_post_ID = p.ID WHERE c.comment_approved = '0' AND c.comment_parent = 0 AND (p.post_type = 'guestbook' OR p.post_type = 'message') AND (c.comment_type IS NULL OR c.comment_type != 'review')"
);
$counts['other'] = (int) $tododb->get_var(
    "SELECT COUNT(*) FROM {$comments_table} c LEFT JOIN {$posts_table} p ON c.comment_post_ID = p.ID WHERE c.comment_approved = '0' AND c.comment_parent = 0 AND (p.post_type IS NULL OR (p.post_type NOT IN ('article','product','guestbook','message'))) AND (c.comment_type IS NULL OR c.comment_type != 'review')"
);

$tab_labels = [
    'all' => '全部',
    'article' => '文章评论',
    'product' => '产品询盘',
    'message' => '网站留言',
    'other' => '其他',
];

?>

<div class="admin-comments-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">待审核</h2>
        <div class="admin-page-actions">
            <a href="<?php echo todo_admin_url('index.php?page=comment-dashboard'); ?>" class="button button-secondary">返回评论概览</a>
        </div>
    </div>

    <div class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-title-section">
                <h3 class="admin-card-title">待审核列表</h3>
            </div>
            <div class="admin-card-actions">
                <form method="get" class="search-form todo-request">
                    <input type="hidden" name="page" value="comment-pending">
                    <?php if ($pt !== 'all'): ?>
                        <input type="hidden" name="pt" value="<?php echo esc_attr($pt); ?>">
                    <?php endif; ?>
                    <input type="search" name="search" value="<?php echo esc_attr($search); ?>" placeholder="搜索内容、用户名或邮箱...">
                    <button type="submit" class="button button-secondary">搜索</button>
                </form>
            </div>
        </div>

        <div class="admin-card-content">
            <div class="admin-filter-bar">
                <ul class="admin-filters">
                    <?php foreach ($tab_labels as $k => $label): ?>
                        <?php
                        $url = todo_admin_url('index.php?page=comment-pending&pt=' . rawurlencode($k));
                        if ($search !== '') {
                            $url = todo_admin_url('index.php?page=comment-pending&pt=' . rawurlencode($k) . '&search=' . rawurlencode($search));
                        }
                        ?>
                        <li>
                            <a href="<?php echo $url; ?>" class="<?php echo $pt === $k ? 'current' : ''; ?>">
                                <?php echo esc_html($label); ?> <span class="count">(<?php echo intval($counts[$k] ?? 0); ?>)</span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <?php if (empty($items)): ?>
                <div class="no-items">
                    <p>没有待审核内容。</p>
                </div>
            <?php else: ?>
                <form method="post" id="pending-form" class="todo-request">
                    <?php if (class_exists('Todo_Security_Manager')) echo Todo_Security_Manager::csrf_field('bulk_action_pending_comments'); ?>
                    <input type="hidden" name="pt" value="<?php echo esc_attr($pt); ?>">
                    <input type="hidden" name="search" value="<?php echo esc_attr($search); ?>">

                    <div class="bulk-actions-bar">
                        <div class="bulk-actions">
                            <select name="bulk_action">
                                <option value="">批量操作</option>
                                <option value="approve">批准</option>
                                <option value="spam">标记为垃圾评论</option>
                                <option value="trash">移至回收站</option>
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
                                <th>内容</th>
                                <th style="min-width: 200px;">目标</th>
                                <th style="min-width: 130px;">提交时间</th>
                                <th style="min-width: 180px;">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items as $row): ?>
                            <?php
                            $comment_id = intval($row->comment_ID ?? 0);
                            $author = (string) ($row->comment_author ?? '');
                            $email = (string) ($row->comment_author_email ?? '');
                            $content = (string) todo_decode_html_entities($row->comment_content ?? '', 2);
                            $post_id = intval($row->comment_post_ID ?? 0);
                            $post_title = (string) ($row->post_title ?? '');
                            $post_type = (string) ($row->post_type ?? '');

                            $type_label = '评论';
                            if ($post_type === 'article') $type_label = '文章评论';
                            elseif ($post_type === 'product') $type_label = '产品询盘';
                            elseif ($post_type === 'guestbook' || $post_type === 'message') $type_label = '网站留言';

                            $edit_url = todo_admin_url('index.php?page=comment-edit&comment_id=' . $comment_id . '&from=comment-pending&pt=' . rawurlencode($pt));
                            $approve_url = todo_admin_url('index.php?page=comment-pending&action=approve&comment_id=' . $comment_id . '&pt=' . rawurlencode($pt));
                            $spam_url = todo_admin_url('index.php?page=comment-pending&action=spam&comment_id=' . $comment_id . '&pt=' . rawurlencode($pt));
                            $trash_url = todo_admin_url('index.php?page=comment-pending&action=trash&comment_id=' . $comment_id . '&pt=' . rawurlencode($pt));
                            if ($search !== '') {
                                $edit_url .= '&search=' . rawurlencode($search);
                                $approve_url .= '&search=' . rawurlencode($search);
                                $spam_url .= '&search=' . rawurlencode($search);
                                $trash_url .= '&search=' . rawurlencode($search);
                            }
                            if ($paged > 1) {
                                $edit_url .= '&paged=' . intval($paged);
                            }
                            if (function_exists('todo_create_nonce')) {
                                $approve_url .= '&_todononce=' . rawurlencode(todo_create_nonce('approve_comment_' . $comment_id));
                                $spam_url .= '&_todononce=' . rawurlencode(todo_create_nonce('spam_comment_' . $comment_id));
                                $trash_url .= '&_todononce=' . rawurlencode(todo_create_nonce('trash_comment_' . $comment_id));
                            }

                            $post_edit_url = $post_id > 0 ? todo_admin_url('index.php?page=post-edit&id=' . $post_id . '&from=comment-pending') : '';
                            ?>
                            <tr>
                                <th>
                                    <input type="checkbox" name="comment_ids[]" value="<?php echo $comment_id; ?>">
                                </th>
                                <td>
                                    <div class="comment-author">
                                        <?php echo get_avatar($email, 40); ?>
                                        <div class="author-info">
                                            <strong><?php echo esc_html($author !== '' ? $author : '匿名'); ?></strong>
                                            <?php if ($email !== ''): ?>
                                                <?php echo esc_html($email); ?>
                                            <?php endif; ?>
                                            <p class="description m-0"><?php echo esc_html($type_label); ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td style="min-width: 160px;">
                                    <div class="comment-content" style="overflow:hidden;max-height:60px;line-height:20px;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;">
                                        <?php echo esc_html(todo_trim_words($content, 60)); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($post_edit_url !== ''): ?>
                                        <a href="<?php echo esc_url($post_edit_url); ?>"><?php echo esc_html($post_title !== '' ? $post_title : '内容已删除'); ?></a>
                                    <?php else: ?>
                                        <p class="description m-0"><?php echo esc_html($post_title !== '' ? $post_title : '内容已删除'); ?></p>
                                    <?php endif; ?>
                                    <?php if ($post_id > 0): ?>
                                        <p class="description m-0">ID: <?php echo esc_html($post_id); ?></p>
                                    <?php endif; ?>
                                </td>
                                <td style="width: 160px;">
                                    <span class="tb-meta"><?php echo esc_html(date_i18n('Y-m-d H:i', strtotime((string) ($row->comment_date ?? '')))); ?></span>
                                </td>
                                <td style="width: 180px;">
                                    <div class="row-actions">
                                        <a href="<?php echo esc_url($approve_url); ?>" class="js-todo-post-action">批准</a> |
                                        <a href="<?php echo esc_url($edit_url); ?>">编辑</a> |
                                        <a href="<?php echo esc_url($spam_url); ?>" class="js-todo-post-action" style="color:#b32d2e;" data-confirm="确定标记为垃圾吗？">垃圾</a> |
                                        <a href="<?php echo esc_url($trash_url); ?>" class="js-todo-post-action" style="color:#a00;" data-confirm="确定移至回收站吗？">回收站</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </form>

                <?php if ($total_pages > 1): ?>
                    <?php
                    $add_args = ['pt' => $pt];
                    if ($search !== '') $add_args['search'] = $search;
                    echo todo_render_pagination(array(
                        'total_items' => $total_items,
                        'per_page' => $per_page,
                        'current_page' => $paged,
                        'base_url' => todo_admin_url('index.php?page=comment-pending'),
                        'add_args' => $add_args,
                        'show_count' => true,
                    ));
                    ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

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
    jQuery(function ($) {
        $('#cb-select-all').on('change', function () {
            $('input[name="comment_ids[]"]').prop('checked', this.checked);
        });
    });
</script>
