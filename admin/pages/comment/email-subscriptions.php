<?php
/**
 * 邮件订阅管理页面
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

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $single_action = sanitize_text_field($_GET['action'] ?? '');
    $subscription_id = intval($_GET['subscription_id'] ?? 0);
    $nonce = sanitize_text_field($_GET['_todononce'] ?? '');

    if ($single_action !== '' && $subscription_id > 0 && in_array($single_action, ['confirm', 'unsubscribe', 'delete'], true)) {
        $nonce_action = $single_action . '_subscription_' . $subscription_id;
        if (!todo_verify_nonce($nonce, $nonce_action)) {
            if (function_exists('todo_add_admin_notice')) {
                todo_add_admin_notice('安全验证失败，请重试。', 'error');
            }
            todo_redirect(todo_admin_url('index.php?page=email-subscriptions'));
            exit;
        }

        $ok = false;
        switch ($single_action) {
            case 'confirm':
                $ok = (bool) todo_update_subscription_status($subscription_id, 'confirmed');
                $msg = '订阅已确认';
                break;
            case 'unsubscribe':
                $ok = (bool) todo_update_subscription_status($subscription_id, 'unsubscribed');
                $msg = '订阅已取消';
                break;
            case 'delete':
                $ok = (bool) todo_delete_subscription($subscription_id);
                $msg = '订阅已删除';
                break;
        }
        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice($ok ? $msg : '操作失败，请稍后重试。', $ok ? 'success' : 'error');
        }
        todo_redirect(todo_admin_url('index.php?page=email-subscriptions'));
        exit;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $single_action = sanitize_text_field($_POST['action'] ?? '');
    $subscription_id = intval($_POST['subscription_id'] ?? 0);
    $nonce = sanitize_text_field($_POST['_todononce'] ?? '');

    if ($subscription_id <= 0) {
        $error_message = '无效的订阅ID';
    } else {
        $nonce_action = $single_action . '_subscription_' . $subscription_id;
        if (!todo_verify_nonce($nonce, $nonce_action)) {
            $error_message = '安全验证失败，请重试。';
        } else {
            switch ($single_action) {
                case 'confirm':
                    todo_update_subscription_status($subscription_id, 'confirmed');
                    $message = '订阅已确认';
                    break;
                case 'unsubscribe':
                    todo_update_subscription_status($subscription_id, 'unsubscribed');
                    $message = '订阅已取消';
                    break;
                case 'delete':
                    todo_delete_subscription($subscription_id);
                    $message = '订阅已删除';
                    break;
                default:
                    $error_message = '无效的操作';
                    break;
            }
        }
    }
}

// 处理批量操作
if (isset($_POST['action']) && isset($_POST['subscription_ids']) && !empty($_POST['subscription_ids'])) {
    // 验证CSRF令牌
    if (!Todo_Security_Manager::verify_request_csrf('bulk_action_subscriptions')) {
        todo_die('Security check failed');
    }

    $action = sanitize_text_field($_POST['action']);
    $subscription_ids = array_map('intval', $_POST['subscription_ids']);

    switch ($action) {
        case 'confirm':
            foreach ($subscription_ids as $id) {
                todo_update_subscription_status($id, 'confirmed');
            }
            $message = '已确认选中的订阅';
            break;
        case 'unsubscribe':
            foreach ($subscription_ids as $id) {
                todo_update_subscription_status($id, 'unsubscribed');
            }
            $message = '已取消选中的订阅';
            break;
        case 'delete':
            foreach ($subscription_ids as $id) {
                todo_delete_subscription($id);
            }
            $message = '已删除选中的订阅';
            break;
        case 'export':
            // 导出选中的订阅
            export_subscriptions($subscription_ids);
            exit;
        case 'send_email':
            // 群发邮件
            if (isset($_POST['email_subject']) && isset($_POST['email_content'])) {
                $subject = sanitize_text_field($_POST['email_subject']);
                $content = todo_kses_post($_POST['email_content']);
                $sent_count = send_bulk_email($subscription_ids, $subject, $content);
                $message = "已向 {$sent_count} 个订阅者发送邮件";
            }
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
$where_conditions = array("1=1");
$where_params = array();

if ($status !== 'all') {
    $where_conditions[] = "status = ?";
    $where_params[] = $status;
}

if (!empty($search)) {
    $search_param = '%' . $tododb->esc_like($search) . '%';
    $where_conditions[] = "(email LIKE ? OR name LIKE ?)";
    $where_params[] = $search_param;
    $where_params[] = $search_param;
}

$where_clause = implode(' AND ', $where_conditions);

// 获取状态统计 (以 1 条 GROUP BY 查询代替 4 条独立 COUNT 查询)
$status_counts = array('all' => 0, 'confirmed' => 0, 'pending' => 0, 'unsubscribed' => 0);
$group_rows = $tododb->get_results("SELECT status, COUNT(*) as cnt FROM {$tododb->prefix}email_subscribers GROUP BY status");
if (is_array($group_rows)) {
    foreach ($group_rows as $gr) {
        if (!is_object($gr)) continue;
        $st = (string) ($gr->status ?? '');
        $cnt = (int) ($gr->cnt ?? 0);
        $status_counts['all'] += $cnt;
        if (array_key_exists($st, $status_counts)) {
            $status_counts[$st] = $cnt;
        }
    }
}

// 获取总数 (未使用搜索过滤且选中全部状态时直接复用已算好的 all 计数)
if ($status === 'all' && empty($search)) {
    $total_items = $status_counts['all'];
} else {
    $total_items = (int) $tododb->get_var(
        "
        SELECT COUNT(*) 
        FROM {$tododb->prefix}email_subscribers 
        WHERE {$where_clause}
    ",
        $where_params
    );
}

// 获取订阅列表
$subscriptions = $tododb->get_results(
    "
    SELECT * 
    FROM {$tododb->prefix}email_subscribers
    WHERE {$where_clause}
    ORDER BY created_at DESC
    LIMIT {$offset}, {$per_page}
",
    $where_params
);

// 计算分页
$total_pages = $total_items > 0 ? (int) ceil($total_items / $per_page) : 0;

// 获取订阅邮件模版
$subscribe_templates = $tododb->get_results("SELECT slug, name, subject, content FROM {$tododb->prefix}email_templates WHERE category = 'subscribe' ORDER BY id DESC");

// 导出功能
function export_subscriptions($subscription_ids)
{
    global $tododb;

    try {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    } catch (Throwable $e) {
    }
    if (function_exists('header_remove')) {
        header_remove();
    }

    $ids = implode(',', array_map('intval', $subscription_ids));
    $subscriptions = $tododb->get_results("
        SELECT * 
        FROM {$tododb->prefix}email_subscribers
        WHERE id IN ({$ids})
        ORDER BY created_at DESC
    ");

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=email_subscriptions_' . date('Y-m-d') . '.csv');
    header('X-Content-Type-Options: nosniff');

    $output = fopen('php://output', 'w');

    // 写入BOM以支持中文
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // 写入表头
    fputcsv($output, array('ID', '邮箱地址', '姓名', '状态', '订阅时间', '确认时间', '来源', '扩展信息'));

    // 写入数据
    foreach ($subscriptions as $subscription) {
        fputcsv($output, array(
            $subscription->id,
            $subscription->email,
            $subscription->name,
            $subscription->status,
            $subscription->created_at,
            $subscription->confirmed_at ?? '',
            $subscription->source ?? '',
            ''
        ));
    }

    fclose($output);
}

// 群发邮件功能
function send_bulk_email($subscription_ids, $subject, $content)
{
    global $tododb;

    $ids = implode(',', array_map('intval', $subscription_ids));
    $subscriptions = $tododb->get_results("
        SELECT email, name 
        FROM {$tododb->prefix}email_subscribers 
        WHERE id IN ({$ids}) AND status = 'confirmed'
    ");

    $sent_count = 0;
    foreach ($subscriptions as $subscription) {
        $personalized_content = str_replace(
            array('{name}', '{email}'),
            array($subscription->name, $subscription->email),
            $content
        );

        $headers = array('Content-Type: text/html; charset=UTF-8');
        if (todo_mail($subscription->email, $subject, $personalized_content, $headers)) {
            $sent_count++;
        }
    }

    return $sent_count;
}

// 更新订阅状态
function todo_update_subscription_status($subscription_id, $status)
{
    global $tododb;

    $update_data = array('status' => $status);

    // 如果是确认订阅，记录确认时间
    if ($status === 'confirmed') {
        $update_data['confirmed_at'] = current_time('mysql');
    }

    $result = $tododb->update(
        $tododb->prefix . 'email_subscribers',
        $update_data,
        array('id' => $subscription_id)
    );

    return $result;
}

// 删除订阅
function todo_delete_subscription($subscription_id)
{
    global $tododb;

    // 删除订阅记录
    return $tododb->delete(
        $tododb->prefix . 'email_subscribers',
        array('id' => $subscription_id)
    );
}
?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">邮件订阅</h2>
        <div class="admin-page-actions">
            <a href="javascript:void(0);" class="button button-primary" id="add-subscription-btn">添加订阅</a>
            <a href="javascript:void(0);" class="button button-secondary" id="send-bulk-email-btn">群发邮件</a>
        </div>
    </div>

    <?php if (isset($error_message)): ?>
    <div class="notice notice-error is-dismissible">
        <p><?php echo esc_html($error_message); ?></p>
        <button type="button" class="notice-dismiss">✕</button>
    </div>
    <?php endif; ?>

    <?php if (isset($message)): ?>
    <div class="notice notice-success is-dismissible">
        <p><?php echo esc_html($message); ?></p>
        <button type="button" class="notice-dismiss">✕</button>
    </div>
    <?php endif; ?>

    <div class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-title-section">
                <h3 class="admin-card-title">订阅列表</h3>
                <div class="admin-card-subtitle">
                    <?php echo $total_items; ?> 个订阅
                </div>
            </div>

            <div class="admin-card-actions">
                <form method="get" class="search-form">
                    <input type="hidden" name="page" value="email-subscriptions">
                    <?php if ($status !== 'all'): ?>
                        <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>">
                    <?php endif; ?>
                    <input type="search" name="search" value="<?php echo esc_attr($search); ?>"
                        placeholder="搜索邮箱地址或姓名...">
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
                        'confirmed' => '已确认',
                        'pending' => '待确认',
                        'unsubscribed' => '已取消'
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

            <form method="post" id="subscriptions-form">
                <?php echo Todo_Security_Manager::csrf_field('bulk_action_subscriptions'); ?>

                <div class="bulk-actions-bar">
                    <div class="bulk-actions">
                        <select name="action" id="bulk-action-selector-top">
                            <option value="">批量操作</option>
                            <option value="confirm">确认订阅</option>
                            <option value="unsubscribe">取消订阅</option>
                            <option value="delete">删除</option>
                            <option value="export">导出选中</option>
                            <option value="send_email">发送邮件</option>
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
                            <th>邮箱地址</th>
                            <th style="min-width: 100px;">姓名</th>
                            <th style="min-width: 100px;">来源</th>
                            <th style="min-width: 130px;">订阅时间</th>
                            <th style="min-width: 180px;">确认时间</th>
                            <th style="min-width: 100px;">状态</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($subscriptions)): ?>
                            <?php foreach ($subscriptions as $subscription): ?>
                                <?php
                                // 解析meta数据
                                $meta_data = array();
                                $raw_meta = $subscription->meta_data ?? '';
                                if ($raw_meta !== '') {
                                    $meta_pairs = explode('|', $raw_meta);
                                    foreach ($meta_pairs as $pair) {
                                        if (strpos($pair, ':') !== false) {
                                            list($key, $value) = explode(':', $pair ?? '', 2);
                                            $meta_data[$key] = $value;
                                        }
                                    }
                                }
                                ?>
                                <tr id="subscription-<?php echo $subscription->id; ?>">
                                    <td>
                                        <input type="checkbox" name="subscription_ids[]"
                                            value="<?php echo $subscription->id; ?>"
                                            data-email="<?php echo esc_attr($subscription->email); ?>">
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html($subscription->email); ?></strong>
                                        <div class="row-actions">
                                            <span class="send-email">
                                                <a href="javascript:void(0);" class="send-single-email"
                                                    data-subscription-id="<?php echo $subscription->id; ?>"
                                                    data-email="<?php echo esc_attr($subscription->email); ?>">发送邮件</a> |
                                            </span>
                                            <?php if ($subscription->status !== 'confirmed'): ?>
                                                <a
                                                    href="<?php echo todo_nonce_url(todo_admin_url('index.php?page=email-subscriptions&action=confirm&subscription_id=' . $subscription->id), 'confirm_subscription_' . $subscription->id); ?>">确认</a>
                                                |
                                            <?php endif; ?>
                                            <?php if ($subscription->status !== 'unsubscribed'): ?>
                                                <a
                                                    href="<?php echo todo_nonce_url(todo_admin_url('index.php?page=email-subscriptions&action=unsubscribe&subscription_id=' . $subscription->id), 'unsubscribe_subscription_' . $subscription->id); ?>">取消订阅</a>
                                                |
                                            <?php endif; ?>
                                            <a href="<?php echo todo_nonce_url(todo_admin_url('index.php?page=email-subscriptions&action=delete&subscription_id=' . $subscription->id), 'delete_subscription_' . $subscription->id); ?>"
                                                class="submitdelete" onclick="return TodoCMS.Utils.confirmAction(this, '确定要删除这个订阅吗？');">删除</a>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo esc_html($subscription->name ?: '未提供'); ?>
                                    </td>
                                    <td>
                                        <?php echo esc_html($subscription->source ?: '网站订阅'); ?>
                                    </td>
                                    <td style="width: 160px;">
                                        <span class="tb-meta"><?php echo date('Y-m-d H:i', strtotime($subscription->created_at)); ?></span>
                                    </td>
                                    <td>
                                        <?php
                                        if ($subscription->confirmed_at) {
                                            echo date('Y-m-d H:i', strtotime($subscription->confirmed_at));
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <span class="status-<?php echo $subscription->status; ?>">
                                            <?php
                                            switch ($subscription->status) {
                                                case 'confirmed':
                                                    echo '已确认';
                                                    break;
                                                case 'pending':
                                                    echo '待确认';
                                                    break;
                                                case 'unsubscribed':
                                                    echo '已取消';
                                                    break;
                                                default:
                                                    echo $subscription->status;
                                                    break;
                                            }
                                            ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr class="empty">
                                <td colspan="7">
                                    <div class="no-items">
                                        <p>没有找到邮件订阅。</p>
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
                        'base_url' => todo_admin_url('index.php?page=email-subscriptions'),
                        'add_args' => $pagination_add_args,
                        'show_count' => true,
                    ));
                    ?>
                <?php endif; ?>

                <!-- 隐藏的邮件内容字段（用于批量发送邮件） -->
                <div id="bulk-email-fields" style="display: none;">
                    <input type="text" name="email_subject" id="bulk-email-subject" placeholder="邮件主题">
                    <textarea name="email_content" id="bulk-email-content" placeholder="邮件内容"></textarea>
                </div>
            </form>
        </div>
    </div>
</div><!-- 群发邮件模态框模板 -->
<template id="todo-tpl-bulk-email-modal">
    <form id="bulk-email-form">
        <?php echo Todo_Security_Manager::csrf_field('send_bulk_email'); ?>

        <input type="hidden" id="bulk-selected-ids" name="selected_ids" value="">

        <div class="form-group">
            <label for="bulk-recipients">收件人：</label>
            <textarea id="bulk-recipients" name="recipients" class="large-text w-100" rows="2" placeholder="留空表示按条件群发；也可填写邮箱，用英文逗号/空格/换行分隔"></textarea>
            <div class="flex justify-end gap-2">
                <a href="javascript:void(0);" class="btn btn-normal" id="bulk-import-selected">导入选中</a>
                <a href="javascript:void(0);" class="btn btn-normal" id="bulk-clear-recipients">清空</a>
            </div>
        </div>

        <div class="form-group">
            <label for="bulk-subject">邮件主题：</label>
            <input type="text" id="bulk-subject" name="subject" class="regular-text" style="width: 100%;">
        </div>

        <div class="form-group">
            <label for="bulk-content">邮件内容：</label>
            <textarea id="bulk-content" name="content" rows="5" class="large-text" style="width: 100%;"></textarea>
            <p class="description">
                可使用变量：{name} - 订阅者姓名，{email} - 邮箱地址，{reply_content} - 当前输入内容
            </p>
        </div>

        <div class="form-group">
            <label>
                <input type="checkbox" id="only-confirmed" name="only_confirmed" checked>
                仅发送给已确认的订阅者
            </label>
        </div>

        <div class="form-group">
            <label for="email-template">邮件模板：</label>
            <select id="email-template" name="template" class="regular-text">
                <option value="">-- 邮件模板(可选) --</option>
                <?php if (!empty($subscribe_templates)): ?>
                    <?php foreach ($subscribe_templates as $tpl): ?>
                        <option value="<?php echo esc_attr($tpl->slug); ?>"><?php echo esc_html($tpl->name); ?></option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
            <p class="description">您可以在"工具 > 邮件设置"中 <a href="<?php echo todo_admin_url('index.php?page=mail&tab=templates'); ?>">管理模版</a>，请选择“订阅邮件”模版分类</p>
        </div>
    </form>
</template>

<!-- 单个邮件模态框模板 -->
<template id="todo-tpl-single-email-modal">
    <form id="single-email-form">
        <input type="hidden" id="single-subscription-id" name="subscription_id">
        <?php echo Todo_Security_Manager::csrf_field('send_single_email'); ?>

        <div class="form-group">
            <label for="single-email-to">收件人：</label>
            <input type="email" id="single-email-to" name="to" class="regular-text w-100" readonly>
        </div>

        <div class="form-group">
            <label for="single-subject">邮件主题：</label>
            <input type="text" id="single-subject" name="subject" class="regular-text w-100">
        </div>

        <div class="form-group">
            <label for="single-content">邮件内容：</label>
            <textarea id="single-content" name="content" rows="8" class="large-text w-100"></textarea>
        </div>

        <div class="form-group">
            <label for="single-email-template">邮件模板：</label>
            <select id="single-email-template" name="template" class="regular-text">
                <option value="">-- 邮件模板(可选) --</option>
                <?php if (!empty($subscribe_templates)): ?>
                    <?php foreach ($subscribe_templates as $tpl): ?>
                        <option value="<?php echo esc_attr($tpl->slug); ?>"><?php echo esc_html($tpl->name); ?></option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
            <p class="description">您可以在"工具 > 邮件设置"中 <a href="<?php echo todo_admin_url('index.php?page=mail&tab=templates'); ?>">管理模版</a>，请选择“订阅邮件”模版分类</p>
        </div>
    </form>
</template>

<!-- 添加订阅模态框 -->
<template id="todo-tpl-add-subscription-form">
    <form id="add-subscription-form">
        <?php echo Todo_Security_Manager::csrf_field('add_subscription'); ?>

        <div class="form-group">
            <label for="add-email">邮箱地址：</label>
            <input type="email" id="add-email" name="email" class="regular-text" required>
        </div>

        <div class="form-group">
            <label for="add-name">姓名：</label>
            <input type="text" id="add-name" name="name" class="regular-text">
        </div>

        <div class="form-group">
            <label for="add-status">状态：</label>
            <select id="add-status" name="status" class="regular-text">
                <option value="pending">待确认</option>
                <option value="confirmed">已确认</option>
            </select>
        </div>

        <div class="form-group">
            <label for="add-source">来源：</label>
            <input type="text" id="add-source" name="source" class="regular-text" value="手动添加">
        </div>

        <div class="form-group">
            <label>
                <input type="checkbox" id="send-welcome" name="send_welcome" checked>
                发送欢迎邮件
            </label>
        </div>

        <div class="form-group">
            <label for="add-welcome-template">欢迎邮件模板：</label>
            <select id="add-welcome-template" name="welcome_template" class="regular-text">
                <option value="">-- 邮件模板(可选) --</option>
                <?php if (!empty($subscribe_templates)): ?>
                    <?php foreach ($subscribe_templates as $tpl): ?>
                        <option value="<?php echo esc_attr($tpl->slug); ?>"><?php echo esc_html($tpl->name); ?></option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
            <p class="description">您可以在"工具 > 邮件设置"中 <a href="<?php echo todo_admin_url('index.php?page=mail&tab=templates'); ?>">管理模版</a>，请选择“订阅邮件”模版分类</p>
        </div>
    </form>
</template>

<style>
    /* 邮件订阅管理页面特定样式 */
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

    /* 筛选表单样式 */
    .filter-form {
        margin: 20px 0;
        padding: 15px;
        background-color: #f9f9f9;
        border-radius: 4px;
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

    /* 订阅统计卡片 */
    .subscription-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin: 20px 0;
    }

    .stat-card {
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 20px;
        text-align: center;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .stat-card h3 {
        margin: 0 0 10px 0;
        font-size: 24px;
        color: #0073aa;
    }

    .stat-card p {
        margin: 0;
        color: #666;
        font-size: 14px;
    }

    /* 邮件模板样式 */
    .email-template-preview {
        background-color: #f9f9f9;
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 15px;
        margin-top: 10px;
        display: none;
    }

    .email-template-preview h4 {
        margin-top: 0;
        color: #333;
    }

    /* 响应式设计 */
    @media (max-width: 768px) {
        .search-box input[type="search"] {
            width: 100%;
            margin-bottom: 10px;
        }

        .subscription-stats {
            grid-template-columns: 1fr;
        }
    }

    /* 加载动画 */
    .loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid #f3f3f3;
        border-top: 3px solid #0073aa;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        0% {
            transform: rotate(0deg);
        }

        100% {
            transform: rotate(360deg);
        }
    }

    /* 成功/错误消息样式 */
    .message-success {
        color: #46b450;
        font-weight: 500;
    }

    .message-error {
        color: #dc3232;
        font-weight: 500;
    }
</style>

<script type="text/javascript">
    var todo_subscribe_templates = <?php 
        $templates_js = array();
        if (!empty($subscribe_templates)) {
            foreach ($subscribe_templates as $tpl) {
                $templates_js[$tpl->slug] = array(
                    'subject' => $tpl->subject,
                    'content' => $tpl->content
                );
            }
        }
        echo json_encode($templates_js, JSON_UNESCAPED_UNICODE);
    ?>;

    function getAjaxErrorMessage(xhr, fallback) {
        var msg = '';
        try {
            if (xhr && xhr.responseJSON) {
                msg = (xhr.responseJSON.message || xhr.responseJSON.error || '') + '';
            }
            if (!msg && xhr && typeof xhr.responseText === 'string') {
                var t = xhr.responseText.trim();
                if (t) {
                    try {
                        var j = JSON.parse(t);
                        msg = (j.message || j.error || '') + '';
                    } catch (e) {
                    }
                }
            }
        } catch (e) {
        }
        msg = (msg || '').trim();
        return msg ? (fallback + ': ' + msg) : fallback;
    }

    jQuery(document).ready(function ($) {

        // 全选/取消全选
        $('#cb-select-all').on('change', function () {
            var isChecked = this.checked;
            $('input[name="subscription_ids[]"]').prop('checked', isChecked);
            $('#cb-select-all').prop('checked', isChecked);
        });

        // 批量操作提交验证
        $('#subscriptions-form').on('submit', function (e) {
            var action = $('select[name="action"]').val() || $('select[name="action2"]').val();
            var checkedItems = $('input[name="subscription_ids[]"]:checked').length;

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

            // 删除确认
            if (action === 'delete') {
                e.preventDefault();
                TodoModal.confirm({
                    title: '确认删除',
                    message: '确定要删除选中的订阅吗？',
                    onConfirm: function () {
                        var form = document.getElementById('subscriptions-form');
                        if (form) form.submit();
                    }
                });
                return false;
            } else if (action === 'send_email') {
                e.preventDefault();
                openBulkEmailModalWithSelected();
                return false;
            }
        });

        // 群发邮件按钮
        $('#send-bulk-email-btn').on('click', function (e) {
            e.preventDefault();
            openBulkEmailModal();
        });

        // 单个邮件发送
        $(document).on('click', '.send-single-email', function (e) {
            e.preventDefault();
            var subscriptionId = $(this).data('subscription-id');
            var email = $(this).data('email');
            openSingleEmailModal(subscriptionId, email);
        });

        // 添加订阅按钮
        $('#add-subscription-btn').on('click', function (e) {
            e.preventDefault();
            openAddSubscriptionModal();
        });

        // 邮件模板选择 (使用事件委托)
        $(document).on('change', '#email-template', function () {
            var template = $(this).val();
            loadEmailTemplate(template, 'bulk');
        });

        $(document).on('change', '#single-email-template', function () {
            var template = $(this).val();
            loadEmailTemplate(template, 'single');
        });

        $(document).on('change', '#send-welcome', function () {
            var form = $(this).closest('form');
            var templateSelect = form.find('#add-welcome-template');
            if (templateSelect.length) {
                templateSelect.prop('disabled', !this.checked);
                if (!this.checked) {
                    templateSelect.val('');
                }
            }
        });
    });

    function todoGetAjaxUrl(fallbackUrl) {
        if (typeof window.ajaxurl === 'string' && window.ajaxurl) {
            return window.ajaxurl;
        }

        try {
            if (window.parent && window.parent !== window && typeof window.parent.ajaxurl === 'string' && window.parent.ajaxurl) {
                return window.parent.ajaxurl;
            }
        } catch (e) {
        }

        try {
            if (window.TODO && typeof window.TODO.ADMIN_AJAX === 'string' && window.TODO.ADMIN_AJAX) {
                return window.TODO.ADMIN_AJAX;
            }
        } catch (e) {
        }

        try {
            if (window.parent && window.parent !== window && window.parent.TODO && typeof window.parent.TODO.ADMIN_AJAX === 'string' && window.parent.TODO.ADMIN_AJAX) {
                return window.parent.TODO.ADMIN_AJAX;
            }
        } catch (e) {
        }

        return fallbackUrl;
    }

    function openAddSubscriptionModal() {
        var modal = TodoModal.form({
            templateId: 'todo-tpl-add-subscription-form',
            title: '添加订阅',
            submitText: '添加订阅',
            cancelText: '取消',
            onSubmit: function (formData, form) {
                if (form && typeof form.checkValidity === 'function' && !form.checkValidity()) {
                    if (typeof form.reportValidity === 'function') {
                        form.reportValidity();
                    }
                    return false;
                }

                var payload = form ? new FormData(form) : new FormData();
                payload.append('action', 'add_subscription');
                if (!payload.has('_csrf_token') && !payload.has('_todononce')) {
                    var csrfToken = window.TodoCSRFManager ? window.TodoCSRFManager.getToken('add_subscription') : '';
                    if (csrfToken) {
                        payload.append('_csrf_token', csrfToken);
                    }
                }

                jQuery.ajax({
                    url: todoGetAjaxUrl('admin-ajax.php'),
                    type: 'POST',
                    data: payload,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    beforeSend: function (xhr) {
                        try {
                            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                        } catch (e) {
                        }
                    },
                    success: function (response) {
                        if (response.success) {
                            TodoCMS.Utils.showMessage('订阅添加成功！', 'success');
                            modal.close();
                            location.reload();
                        } else {
                            TodoCMS.Utils.showMessage('订阅添加失败：' + (response.message || '未知错误'), 'error');
                        }
                    },
                    error: function (xhr, status, error) {
                        console.error('AJAX Error:', error);
                        TodoCMS.Utils.showMessage(getAjaxErrorMessage(xhr, '订阅添加失败，请重试'), 'error');
                    }
                });

                return false;
            }
        });

        setTimeout(function() {
            var form = modal.element.querySelector('form');
            if (form) {
                var templateSelect = form.querySelector('#add-welcome-template');
                var sendWelcome = form.querySelector('#send-welcome');
                if (templateSelect) {
                    templateSelect.value = '';
                    templateSelect.disabled = sendWelcome ? !sendWelcome.checked : false;
                }
            }
        }, 10);

        return modal;
    }

    // 打开单个邮件发送模态框
    function openSingleEmailModal(subscriptionId, email) {
        var modal = TodoModal.form({
            templateId: 'todo-tpl-single-email-modal',
            title: '发送邮件',
            submitText: '发送邮件',
            cancelText: '取消',
            onSubmit: function (formData, form) {
                if (modal && modal.element && modal.element.getAttribute('data-submitting') === '1') {
                    return false;
                }
                if (modal && modal.element) {
                    modal.element.setAttribute('data-submitting', '1');
                }
                if (form && typeof form.checkValidity === 'function' && !form.checkValidity()) {
                    if (typeof form.reportValidity === 'function') {
                        form.reportValidity();
                    }
                    if (modal && modal.element) {
                        modal.element.setAttribute('data-submitting', '0');
                    }
                    return false;
                }

                var subjectVal = $.trim($(form).find('#single-subject').val() || '');
                var templateVal = $.trim($(form).find('#single-email-template').val() || '');
                if (!subjectVal && !templateVal) {
                    TodoCMS.Utils.showMessage('请填写邮件主题或选择邮件模板', 'warning');
                    if (modal && modal.element) {
                        modal.element.setAttribute('data-submitting', '0');
                    }
                    return false;
                }

                var payload = new FormData(form);
                payload.append('action', 'send_single_email');

                if (!payload.has('_csrf_token') && !payload.has('_todononce')) {
                    var csrfToken = window.TodoCSRFManager ? window.TodoCSRFManager.getToken('send_single_email') : '';
                    if (csrfToken) {
                        payload.append('_csrf_token', csrfToken);
                    }
                }

                // 获取并禁用提交按钮
                var submitBtn = modal.element.querySelector('.todo-modal-footer .button-primary');
                var originalText = submitBtn.textContent;
                submitBtn.disabled = true;
                submitBtn.textContent = '发送中...';

                jQuery.ajax({
                    url: todoGetAjaxUrl('admin-ajax.php'),
                    type: 'POST',
                    data: payload,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    beforeSend: function (xhr) {
                        try {
                            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                        } catch (e) {
                        }
                    },
                    success: function (response) {
                        if (response.success) {
                            TodoCMS.Utils.showMessage('邮件发送成功！', 'success');
                            modal.close();
                        } else {
                            TodoCMS.Utils.showMessage('邮件发送失败：' + (response.message || '未知错误'), 'error');
                            submitBtn.disabled = false;
                            submitBtn.textContent = originalText;
                            if (modal && modal.element) {
                                modal.element.setAttribute('data-submitting', '0');
                            }
                        }
                    },
                    error: function (xhr, status, error) {
                        console.error('AJAX Error:', error);
                        TodoCMS.Utils.showMessage(getAjaxErrorMessage(xhr, '邮件发送失败，请重试'), 'error');
                        submitBtn.disabled = false;
                        submitBtn.textContent = originalText;
                        if (modal && modal.element) {
                            modal.element.setAttribute('data-submitting', '0');
                        }
                    }
                });
                
                return false; // 阻止默认关闭
            }
        });
        
        // 填充数据 (DOM 已经在 Modal open 时被 clone 到 body 中)
        setTimeout(function() {
            var form = modal.element.querySelector('form');
            if(form) {
                 form.querySelector('#single-subscription-id').value = subscriptionId;
                 form.querySelector('#single-email-to').value = email;
                 form.querySelector('#single-subject').value = '';
                 form.querySelector('#single-content').value = '';
                 var templateSelect = form.querySelector('#single-email-template');
                 if (templateSelect) {
                     templateSelect.value = '';
                 }
            }
        }, 10);
    }

    // 打开群发邮件模态框
    function openBulkEmailModal(selectedIds, selectedEmails) {
        var modal = TodoModal.form({
            templateId: 'todo-tpl-bulk-email-modal',
            title: '群发邮件',
            submitText: '发送邮件',
            cancelText: '取消',
            onSubmit: function (formData, form) {
                if (modal && modal.element && modal.element.getAttribute('data-submitting') === '1') {
                    return false;
                }
                if (modal && modal.element) {
                    modal.element.setAttribute('data-submitting', '1');
                }
                if (form && typeof form.checkValidity === 'function' && !form.checkValidity()) {
                    if (typeof form.reportValidity === 'function') {
                        form.reportValidity();
                    }
                    if (modal && modal.element) {
                        modal.element.setAttribute('data-submitting', '0');
                    }
                    return false;
                }

                var subjectVal = $.trim($(form).find('#bulk-subject').val() || '');
                var templateVal = $.trim($(form).find('#email-template').val() || '');
                if (!subjectVal && !templateVal) {
                    TodoCMS.Utils.showMessage('请填写邮件主题或选择邮件模板', 'warning');
                    if (modal && modal.element) {
                        modal.element.setAttribute('data-submitting', '0');
                    }
                    return false;
                }

                var payload = new FormData(form);
                payload.append('action', 'send_bulk_email');
                
                // 如果有选中的ID，添加到 payload
                if (selectedIds && selectedIds.length > 0) {
                    payload.append('selected_ids', selectedIds.join(','));
                }

                if (!payload.has('_csrf_token') && !payload.has('_todononce')) {
                    var csrfToken = window.TodoCSRFManager ? window.TodoCSRFManager.getToken('send_bulk_email') : '';
                    if (csrfToken) {
                        payload.append('_csrf_token', csrfToken);
                    }
                }

                // 获取并禁用提交按钮
                var submitBtn = modal.element.querySelector('.todo-modal-footer .button-primary');
                var originalText = submitBtn.textContent;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="loading"></span> 发送中...';

                jQuery.ajax({
                    url: todoGetAjaxUrl('admin-ajax.php'),
                    type: 'POST',
                    data: payload,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    beforeSend: function (xhr) {
                        try {
                            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                        } catch (e) {
                        }
                    },
                    success: function (response) {
                        if (response.success) {
                            TodoCMS.Utils.showMessage('邮件发送成功！发送数量：' + (response.data.sent_count || 0), 'success');
                            modal.close();
                            setTimeout(() => {
                                location.reload();
                            }, 1500);
                        } else {
                            TodoCMS.Utils.showMessage('邮件发送失败：' + (response.message || '未知错误'), 'error');
                            submitBtn.disabled = false;
                            submitBtn.textContent = originalText;
                            if (modal && modal.element) {
                                modal.element.setAttribute('data-submitting', '0');
                            }
                        }
                    },
                    error: function (xhr, status, error) {
                        console.error('AJAX Error:', error);
                        TodoCMS.Utils.showMessage(getAjaxErrorMessage(xhr, '邮件发送失败，请重试'), 'error');
                        submitBtn.disabled = false;
                        submitBtn.textContent = originalText;
                        if (modal && modal.element) {
                            modal.element.setAttribute('data-submitting', '0');
                        }
                    }
                });
                
                return false; // 阻止默认关闭
            }
        });

        setTimeout(function() {
            var form = modal.element.querySelector('form');
            if (form) {
                var recipientsEl = form.querySelector('#bulk-recipients');
                var selectedIdsEl = form.querySelector('#bulk-selected-ids');
                if (selectedIdsEl) {
                    selectedIdsEl.value = selectedIds && selectedIds.length > 0 ? selectedIds.join(',') : '';
                }
                if (recipientsEl) {
                    if (selectedEmails && selectedEmails.length > 0) {
                        recipientsEl.value = selectedEmails.join(', ');
                    } else {
                        recipientsEl.value = '';
                    }
                }

                var importBtn = form.querySelector('#bulk-import-selected');
                if (importBtn) {
                    importBtn.onclick = function () {
                        var checkedItems = jQuery('input[name="subscription_ids[]"]:checked');
                        if (!checkedItems.length) {
                            TodoCMS.Utils.showMessage('请先在列表中勾选订阅者', 'warning');
                            return false;
                        }
                        var ids = [];
                        var emails = [];
                        checkedItems.each(function () {
                            var id = String(jQuery(this).val() || '').trim();
                            if (id) ids.push(id);
                            var em = String(jQuery(this).data('email') || '').trim();
                            if (em) emails.push(em);
                        });

                        if (selectedIdsEl) {
                            var mergedIds = [];
                            var existingIds = String(selectedIdsEl.value || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
                            mergedIds = mergedIds.concat(existingIds, ids);
                            mergedIds = Array.from(new Set(mergedIds));
                            selectedIdsEl.value = mergedIds.join(',');
                        }

                        if (recipientsEl) {
                            var existing = String(recipientsEl.value || '');
                            var existingEmails = existing.split(/[\s,;]+/).map(function (s) { return s.trim(); }).filter(Boolean);
                            var merged = Array.from(new Set(existingEmails.concat(emails)));
                            recipientsEl.value = merged.join(', ');
                        }
                        return false;
                    };
                }

                var clearBtn = form.querySelector('#bulk-clear-recipients');
                if (clearBtn) {
                    clearBtn.onclick = function () {
                        if (recipientsEl) recipientsEl.value = '';
                        if (selectedIdsEl) selectedIdsEl.value = '';
                        return false;
                    };
                }
            }
        }, 10);
    }

    // 打开群发邮件模态框（选中项目）
    function openBulkEmailModalWithSelected() {
        var checkedItems = jQuery('input[name="subscription_ids[]"]:checked');
        if (checkedItems.length === 0) {
            TodoCMS.Utils.showMessage('请至少选择一个订阅者', 'warning');
            return;
        }

        // 设置选中的订阅者ID
        var selectedIds = [];
        var selectedEmails = [];
        checkedItems.each(function () {
            selectedIds.push($(this).val());
            var email = $(this).data('email');
            if (email) {
                selectedEmails.push(email);
            }
        });

        openBulkEmailModal(selectedIds, selectedEmails);
    }

    // 加载邮件模板
    function loadEmailTemplate(template, mode) {
        var templates = typeof todo_subscribe_templates !== 'undefined' ? todo_subscribe_templates : {};

        var contentId = mode === 'single' ? 'single-content' : 'bulk-content';
        var contentEl = document.getElementById(contentId);
        if (!template) {
            if (contentEl) {
                contentEl.placeholder = '';
            }
            return;
        }

        if (templates[template]) {
            var subjectId = mode === 'single' ? 'single-subject' : 'bulk-subject';
            var subjectEl = document.getElementById(subjectId);
            if (subjectEl) subjectEl.value = templates[template].subject;
            if (contentEl) {
                contentEl.placeholder = '这里输入的内容会替换模板中的 {reply_content}';
            }
        }
    }
</script>
