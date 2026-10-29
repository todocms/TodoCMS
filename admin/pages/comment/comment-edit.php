<?php
/**
 * 管理后台评论编辑页面
 *
 * @package TodoCMS
 * @subpackage Admin/Comment
 * @version 1.0.0
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

// 获取评论ID
$comment_id = isset($_GET['comment_id']) ? intval($_GET['comment_id']) : 0;

if (!$comment_id) {
    todo_die('未指定评论ID。');
}

// 获取评论数据
$comment = todo_get_comment($comment_id);

if (!$comment) {
    todo_die('评论不存在。');
}

// Ensure comment is an object for backward compatibility and linter happiness
if (is_array($comment)) {
    $comment = (object) $comment;
}

$private_code = CMS_Comment::get_effective_private_code($comment_id);
$is_private_comment = $private_code !== '';
$provided_comment_key = '';
if (isset($_POST['comment_key'])) {
    $provided_comment_key = sanitize_text_field($_POST['comment_key']);
} elseif (isset($_GET['comment_key'])) {
    $provided_comment_key = sanitize_text_field($_GET['comment_key']);
}
$has_private_access = !$is_private_comment || CMS_Comment::verify_private_code($comment_id, $provided_comment_key);
$comment_content_for_edit = $comment->comment_content;
$disable_content_edit = false;
if ($is_private_comment && !$has_private_access) {
    $comment_content_for_edit = '（私密评论，需反馈码查看）';
    $disable_content_edit = true;
}

$from = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : '';
$from_pt = isset($_GET['pt']) ? sanitize_text_field($_GET['pt']) : '';
$from_search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
$from_paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$from_query = [];
if ($from !== '') $from_query['from'] = $from;
if ($from_pt !== '') $from_query['pt'] = $from_pt;
if ($from_search !== '') $from_query['search'] = $from_search;
if ($from_paged > 1) $from_query['paged'] = $from_paged;

// 处理表单提交
$message = '';
$error = '';

if (isset($_POST['update_comment'])) {
    // 检查nonce
    if (!Todo_Security_Manager::verify_request_csrf('update-comment_' . $comment_id, '_todononce')) {
        $error = '安全验证失败。请重试。';
    } else {
        // 获取表单数据
        $comment_content = isset($_POST['comment_content']) ? $_POST['comment_content'] : '';
        $comment_author = isset($_POST['comment_author']) ? $_POST['comment_author'] : '';
        $comment_author_email = isset($_POST['comment_author_email']) ? $_POST['comment_author_email'] : '';
        $comment_author_url = isset($_POST['comment_author_url']) ? $_POST['comment_author_url'] : '';
        $comment_status = isset($_POST['comment_status']) ? $_POST['comment_status'] : '';

        // 验证数据
        if (!$disable_content_edit && empty($comment_content)) {
            $error = '评论内容不能为空。';
        } elseif (empty($comment_author)) {
            $error = '评论作者不能为空。';
        } elseif (!empty($comment_author_email) && !is_email($comment_author_email)) {
            $error = '请输入有效的电子邮件地址。';
        } else {
            // 更新评论数据
            $comment_data = array(
                'comment_ID' => $comment_id,
                'comment_author' => $comment_author,
                'comment_author_email' => $comment_author_email,
                'comment_author_url' => $comment_author_url,
            );
            if (!$disable_content_edit) {
                $comment_data['comment_content'] = $comment_content;
            }

            $result = todo_update_comment($comment_data);

            if ($result) {
                // 更新评论状态
                if (!empty($comment_status)) {
                    todo_set_comment_status($comment_id, $comment_status);
                }

                $qs = array_merge(['page' => 'comment-edit', 'comment_id' => $comment_id, 'message' => 1], $from_query);
                todo_redirect(todo_admin_url('index.php?' . http_build_query($qs)));
                exit;
            } else {
                $error = '更新评论时出错。请重试。';
            }
        }
    }
}

// 获取评论状态
$comment_status = todo_get_comment_status($comment_id);

// 获取关联的文章
$post = get_post($comment->comment_post_ID);
if (is_array($post)) {
    $post = (object) $post;
}
$post_title = $post ? $post->post_title : '（文章已删除）';
$from = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : '';
$post_link = $post ? todo_admin_url('index.php?page=post-edit&post=' . $post->ID . ($from !== '' ? '&from=' . urlencode($from) : '')) : '';

// 获取评论者头像
$avatar_email = (string) ($comment->comment_author_email ?? '');
if ($avatar_email === '') {
    $comment_user_id = intval($comment->user_id ?? 0);
    if ($comment_user_id > 0) {
        if (function_exists('todo_get_user')) {
            $u = todo_get_user($comment_user_id);
            if ($u && !empty($u->user_email)) {
                $avatar_email = (string) $u->user_email;
            }
        } elseif (function_exists('get_user_by')) {
            $u = get_user_by('id', $comment_user_id);
            if ($u && !empty($u->user_email)) {
                $avatar_email = (string) $u->user_email;
            }
        }
    }
}
$avatar = get_avatar($avatar_email, 64);

$comment_type = '';
if (is_object($comment) && isset($comment->comment_type)) {
    $comment_type = (string) $comment->comment_type;
} elseif (is_array($comment) && isset($comment['comment_type'])) {
    $comment_type = (string) $comment['comment_type'];
}
$comment_type = $comment_type !== '' ? $comment_type : 'comment';

$back_url = todo_admin_url('index.php?page=comment-dashboard');
if ($from !== '') {
    $back_url = todo_admin_url('index.php?page=' . urlencode($from));
}
?>

<div class="admin-comment-edit-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">编辑反馈</h2>
        <div class="admin-page-actions">
            <a href="<?php echo esc_url($back_url); ?>" class="button button-secondary">返回列表</a>
        </div>
    </div>

    <?php 
    if (isset($_GET['message']) && $_GET['message'] == '1') {
        echo '<div class="notice notice-success is-dismissible">';
        echo '<p>评论已更新。</p>';
        echo '<button type="button" class="notice-dismiss">✕</button>';
        echo '</div>';
    }

    if (!empty($error)): ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html($error); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <form method="post" id="edit-comment-form" class="todo-request">
        <div class="admin-card">

            <div class="admin-card-content">
                <div class="comment-info">
                    <div class="comment-author-info">
                        <?php echo $avatar; ?>
                        <div class="author-meta">
                            <div class="author-name"><?php echo $comment->comment_author; ?></div>
                            <div class="comment-date">
                                <?php echo date_i18n('Y-m-d H:i:s', strtotime($comment->comment_date)); ?></div>
                            <div class="comment-ip"><?php echo $comment->comment_author_IP; ?></div>
                        </div>
                    </div>

                    <div class="comment-post-info">
                        <em class="description"><?php echo ((isset($comment->comment_parent) && intval($comment->comment_parent) > 0) || (isset($comment_type) && $comment_type === 'reply' || $comment_type === 'guestbook')) ? '↳ 回复于:' : '发表于:'; ?></em>
                        <?php if ($post): ?>
                            <a href="<?php echo $post_link; ?>"><?php echo $post_title; ?></a>
                        <?php else: ?>
                            <?php echo $post_title; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php Todo_Security_Manager::csrf_field('update-comment_' . $comment_id, '_todononce'); ?>

                <div class="form-group">
                    <label for="comment_author">作者</label>
                    <input type="text" name="comment_author" id="comment_author"
                        value="<?php echo esc_attr($comment->comment_author); ?>" required>
                </div>

                <div class="form-group">
                    <label for="comment_author_email">电子邮件</label>
                    <input type="email" name="comment_author_email" id="comment_author_email"
                        value="<?php echo esc_attr($comment->comment_author_email); ?>">
                </div>

                <div class="form-group">
                    <label for="comment_author_url">网站</label>
                    <input type="url" name="comment_author_url" id="comment_author_url"
                        value="<?php echo esc_attr($comment->comment_author_url); ?>">
                </div>

                <?php if ($is_private_comment): ?>
                    <div class="form-group">
                        <label for="comment_private_code">私密反馈码</label>
                        <input type="text" id="comment_private_code" value="<?php echo esc_attr($private_code); ?>" readonly>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="comment_content">内容</label>
                    <textarea name="comment_content" id="comment_content" rows="10" style="width: 100%;"
                        <?php echo $disable_content_edit ? 'disabled' : 'required'; ?>><?php echo esc_textarea($comment_content_for_edit); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="comment_status">状态</label>
                    <select name="comment_status" id="comment_status">
                        <option value="approve" <?php selected($comment_status, 'approve'); ?>>已批准</option>
                        <option value="hold" <?php selected($comment_status, 'hold'); ?>>待审核</option>
                        <option value="spam" <?php selected($comment_status, 'spam'); ?>>垃圾评论</option>
                        <option value="trash" <?php selected($comment_status, 'trash'); ?>>回收站</option>
                    </select>
                </div>  
            </div>
        </div>
        <div class="admin-form-submit">
            <button type="submit" name="update_comment" class="button button-primary">更新反馈</button>
        </div>
    </form>
</div>

<style>
    /* 评论编辑页面特定样式 */
    .comment-info {
        margin-bottom: 20px;
        padding-bottom: 20px;
        border-bottom: 1px solid #eceef1;
    }

    .comment-author-info {
        display: flex;
        align-items: center;
    }

    .comment-author-info img {
        margin-right: 15px;
        border-radius: 50%;
    }

    .author-meta {
        font-size: 14px;
        color: #666;
    }

    .author-name {
        font-size: 16px;
        font-weight: 500;
        color: #000;
        margin-bottom: 5px;
    }

    .comment-date,
    .comment-ip {
        font-size: 12px;
        color: #777;
    }

    .comment-post-info {
        font-size: 14px;
        margin-left: 78px;
    }
</style>

<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function () {
        // 表单提交验证
        var editForm = document.getElementById('edit-comment-form');

        if (editForm) {
            editForm.addEventListener('submit', function (e) {
                var commentContent = document.getElementById('comment_content').value.trim();
                var commentAuthor = document.getElementById('comment_author').value.trim();
                var commentEmail = document.getElementById('comment_author_email').value.trim();

                if (commentContent === '') {
                    e.preventDefault();
                    TodoCMS.Utils.showMessage('评论内容不能为空。', 'warning');
                    return;
                }

                if (commentAuthor === '') {
                    e.preventDefault();
                    TodoCMS.Utils.showMessage('评论作者不能为空。', 'warning');
                    return;
                }

                if (commentEmail !== '' && !isValidEmail(commentEmail)) {
                    e.preventDefault();
                    TodoCMS.Utils.showMessage('请输入有效的电子邮件地址。', 'warning');
                    return;
                }
            });
        }

        // 验证电子邮件地址
        function isValidEmail(email) {
            var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        }
    });
</script>
