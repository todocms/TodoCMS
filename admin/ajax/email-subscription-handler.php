<?php
// 加载 Composer autoloader

/**
 * 邮件订阅AJAX处理器
 * TodoCMS - 邮件订阅管理
 * 
 * @package TodoCMS
 * @subpackage Admin/Ajax
 */

if (!defined('DOING_AJAX')) {
    if (!defined('DOING_AJAX')) {
        define('DOING_AJAX', true);
    }
}

// 多重安全检查
if (!defined('TODO_LOADED') && !defined('ADMIN_DIR')) {
    http_response_code(403);
    echo json_encode(['error' => 'Direct access denied']);
    exit;
}

// 检查AJAX请求
if (
    !isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'
) {
    http_response_code(403);
    echo json_encode(['error' => 'Direct access denied']);
    exit;
}

// 检查请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$action = $_POST['action'] ?? '';

if (!function_exists('todo_get_site_name')) {
    function todo_get_site_name($default = 'TodoCMS')
    {
        $name = trim((string) get_option('site_title', ''));
        return $name !== '' ? $name : $default;
    }
}

// 公开动作（如前台邮件订阅）允许无后台 Session 访问
$public_actions = ['subscribe', 'add_subscription_public'];
if (!in_array($action, $public_actions, true)) {
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
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized access']);
        exit;
    }

    // 检查管理员权限（使用 Todo_Session_Manager）
    $admin_user_id = Todo_Session_Manager::get_admin_user_id();
    if (!$admin_user_id) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid admin session']);
        exit;
    }
}

// 获取数据库连接
try {
    $pdo = get_database_connection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

if (!function_exists('todo_get_email_template') || !function_exists('todo_send_template_mail')) {
    if (!defined('ABSPATH')) {
        define('ABSPATH', dirname(__FILE__) . '/../../');
    }
    $mail_extend_path = rtrim(ABSPATH, '/') . '/core/functions/mail-extend.php';
    if (is_file($mail_extend_path)) {
        require_once $mail_extend_path;
    }
}

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // CSRF验证
    $csrf_actions = ['add_subscription', 'update_status', 'delete_subscription', 'send_bulk_email', 'send_single_email', 'export_subscriptions'];
    if (in_array($action, $csrf_actions)) {
        $token_candidates = [];
        if (!empty($_POST['_todononce'])) {
            $token_candidates[] = $_POST['_todononce'];
        }
        if (!empty($_POST['_csrf_token'])) {
            $token_candidates[] = $_POST['_csrf_token'];
        }

        $csrf_ok = false;
        foreach ($token_candidates as $candidate) {
            if (Todo_Security_Manager::verify_csrf_token($candidate, $action)) {
                $csrf_ok = true;
                break;
            }
        }

        if (!$csrf_ok) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF token verification failed']);
            exit;
        }
    }

    switch ($action) {
        case 'subscribe':
        case 'add_subscription_public':
            handle_public_subscription($pdo);
            break;

        case 'add_subscription':
            handle_add_subscription($pdo);
            break;

        case 'update_status':
            handle_update_subscription_status($pdo);
            break;

        case 'delete_subscription':
            handle_delete_subscription($pdo);
            break;

        case 'send_bulk_email':
            handle_send_bulk_email($pdo);
            break;

        case 'send_single_email':
            handle_send_single_email($pdo);
            break;

        case 'export_subscriptions':
            handle_export_subscriptions($pdo);
            break;

        case 'get_subscription':
            handle_get_subscription($pdo);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

/**
 * 处理前台邮件订阅提交
 */
function handle_public_subscription($pdo)
{
    try {
        $table = $pdo->prefix . 'email_subscribers';
        $table_columns = todo_db_get_table_columns($pdo, $table);
        $email = trim($_POST['email'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $source = trim($_POST['source'] ?? '前台订阅');
        $status = 'confirmed'; // 前台快速订阅设为确认/已激活状态

        if (!$email) {
            throw new Exception(__('邮箱地址不能为空'));
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception(__('邮箱格式不正确'));
        }

        if ($name === '') {
            $name = explode('@', $email)[0];
        }

        // 检查邮箱是否已存在
        $existing = $pdo->get_var("SELECT id, status FROM {$table} WHERE email = ?", [$email]);
        if ($existing) {
            $existing_id = is_object($existing) ? $existing->id : (is_array($existing) ? $existing['id'] : $existing);
            // 如果已取消订阅，重新激活
            $pdo->update($table, [
                'status' => 'confirmed',
                'unsubscribed_at' => null,
                'updated_at' => current_time('mysql')
            ], ['id' => $existing_id]);

            echo json_encode([
                'success' => true,
                'message' => __('感谢您的关注，您已重新订阅')
            ]);
            exit;
        }

        $now = current_time('mysql');
        $insert_data = [
            'email' => $email,
            'name' => $name,
            'status' => $status,
            'source' => $source,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'created_at' => $now,
            'confirmed_at' => $now
        ];

        $insert_data = todo_db_filter_data_by_columns($insert_data, $table_columns);

        $subscription_id = $pdo->insert($table, $insert_data);

        if (!$subscription_id) {
            throw new Exception(__('订阅失败'));
        }

        // 触发邮件通知：向管理员发送 mail_notification_options (new_subscription) 通知邮件
        if (function_exists('todo_send_admin_notification')) {
            todo_send_admin_notification('new_subscription', 'new_subscription', [
                'email' => $email,
                'name' => $name ?: '订阅用户',
                'source' => $source,
                'status' => $status,
                'created_at' => $now
            ]);
        }

        echo json_encode([
            'success' => true,
            'message' => __('订阅成功'),
            'data' => ['subscription_id' => $subscription_id]
        ]);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * 处理添加订阅
 */
function handle_add_subscription($pdo)
{
    try {
        $table = $pdo->prefix . 'email_subscribers';
        $table_columns = todo_db_get_table_columns($pdo, $table);
        $email = trim($_POST['email'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $status = $_POST['status'] ?? 'pending';
        $source = trim($_POST['source'] ?? '手动添加');
        $send_welcome = isset($_POST['send_welcome']) ? 1 : 0;
        $welcome_template = trim($_POST['welcome_template'] ?? '');

        if (!$email) {
            throw new Exception(__('邮箱地址不能为空'));
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception(__('邮箱格式不正确'));
        }

        if ($name === '') {
            $name = explode('@', $email)[0];
        }

        // 检查邮箱是否已存在
        $existing = $pdo->get_var("SELECT id FROM {$table} WHERE email = ?", [$email]);
        if ($existing) {
            throw new Exception(__('该邮箱已经订阅过了'));
        }

        $confirmation_token = '';
        if (in_array('confirmation_token', $table_columns, true)) {
            $confirmation_token = bin2hex(random_bytes(32));
        }

        $now = current_time('mysql');
        $insert_data = [
            'email' => $email,
            'name' => $name,
            'status' => $status,
            'source' => $source,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'created_at' => $now
        ];

        if ($status === 'confirmed') {
            $insert_data['confirmed_at'] = $now;
        }

        if ($confirmation_token !== '') {
            $insert_data['confirmation_token'] = $confirmation_token;
        }

        $insert_data = todo_db_filter_data_by_columns($insert_data, $table_columns);

        $subscription_id = $pdo->insert($table, $insert_data);

        if (!$subscription_id) {
            throw new Exception(__('订阅添加失败'));
        }

        // 发送管理员通知
        if (function_exists('todo_send_admin_notification')) {
            todo_send_admin_notification('new_subscription', 'new_subscription', [
                'email' => $email,
                'name' => $name,
                'source' => $source,
                'status' => $status,
                'created_at' => $now
            ]);
        }

        // 如果需要发送欢迎邮件
        if ($send_welcome) {
            send_welcome_email($email, $name, $confirmation_token, $status, $welcome_template);
        }

        echo json_encode([
            'success' => true,
            'message' => __('订阅添加成功'),
            'data' => ['subscription_id' => $subscription_id]
        ]);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * 处理邮件订阅状态更新
 */
function handle_update_subscription_status($pdo)
{
    try {
        $table = $pdo->prefix . 'email_subscribers';
        $table_columns = todo_db_get_table_columns($pdo, $table);
        $subscription_id = intval($_POST['subscription_id'] ?? 0);
        $status = $_POST['status'] ?? '';

        if (!$subscription_id || !$status) {
            throw new Exception(__('订阅ID和状态不能为空'));
        }

        $allowed_statuses = ['pending', 'confirmed', 'unsubscribed'];
        if (!in_array($status, $allowed_statuses)) {
            throw new Exception(__('无效的状态值'));
        }

        $now = current_time('mysql');
        $update_data = [
            'status' => $status,
            'updated_at' => $now
        ];
        if ($status === 'confirmed') {
            $update_data['confirmed_at'] = $now;
        }
        if ($status === 'unsubscribed') {
            $update_data['unsubscribed_at'] = $now;
        }

        $update_data = todo_db_filter_data_by_columns($update_data, $table_columns);

        $affected_rows = $pdo->update($table, $update_data, ['id' => $subscription_id]);

        if ($affected_rows === 0) {
            throw new Exception(__('订阅不存在或状态未改变'));
        }

        echo json_encode([
            'success' => true,
            'message' => __('状态更新成功')
        ]);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * 处理删除订阅
 */
function handle_delete_subscription($pdo)
{
    try {
        $table = $pdo->prefix . 'email_subscribers';
        $subscription_id = intval($_POST['subscription_id'] ?? 0);

        if (!$subscription_id) {
            throw new Exception(__('订阅ID不能为空'));
        }

        $affected_rows = $pdo->delete($table, ['id' => $subscription_id]);

        if ($affected_rows === 0) {
            throw new Exception(__('订阅不存在'));
        }

        echo json_encode([
            'success' => true,
            'message' => __('订阅删除成功')
        ]);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * 处理群发邮件
 */
function handle_send_bulk_email($pdo)
{
    try {
        $table = $pdo->prefix . 'email_subscribers';
        $subject = trim($_POST['subject'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $template = trim((string) ($_POST['template'] ?? ''));
        $only_confirmed = isset($_POST['only_confirmed']) ? 1 : 0;
        $selected_ids = $_POST['selected_ids'] ?? '';
        $recipients_raw = trim($_POST['recipients'] ?? '');

        if ($subject === '' && $template === '') {
            throw new Exception(__('邮件主题不能为空'));
        }
        $token_key = 'send_bulk_email|' . (string) $subject . '|' . (string) $content . '|' . (string) $template . '|' . (string) $selected_ids . '|' . (string) $recipients_raw . '|' . (string) $only_confirmed;
        if (function_exists('todo_claim_submit_token') && !todo_claim_submit_token($token_key, 120)) {
            throw new Exception(__('正在发送中，请勿重复提交'));
        }

        // 构建查询条件
        $where_conditions = [];
        $params = [];

        if ($only_confirmed) {
            $where_conditions[] = "status = 'confirmed'";
        } else {
            $where_conditions[] = "status IN ('confirmed', 'pending')";
        }

        $manual_recipients = [];
        if ($recipients_raw !== '' && strpos($recipients_raw, '@') !== false) {
            $parts = preg_split('/[\s,;]+/', $recipients_raw);
            if (is_array($parts)) {
                foreach ($parts as $p) {
                    $email = trim((string) $p);
                    if ($email === '') continue;
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
                    $manual_recipients[] = strtolower($email);
                }
            }
            $manual_recipients = array_values(array_unique($manual_recipients));
        }

        if (!empty($manual_recipients)) {
            $placeholders = str_repeat('?,', count($manual_recipients) - 1) . '?';
            $where_conditions[] = "LOWER(email) IN ($placeholders)";
            $params = array_merge($params, $manual_recipients);
        } else {
            // 如果指定了选中的订阅者
            if ($selected_ids) {
                $ids = array_map('intval', explode(',', $selected_ids ?? ''));
                $ids = array_values(array_filter($ids, function ($id) { return $id > 0; }));
                if (!empty($ids)) {
                    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
                    $where_conditions[] = "id IN ($placeholders)";
                    $params = array_merge($params, $ids);
                }
            }
        }

        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

        // 获取订阅者列表
        $subscribers = $pdo->get_results("SELECT id, email, name FROM {$table} $where_clause", $params, 'ARRAY_A');

        if (empty($subscribers)) {
            throw new Exception(__('没有找到符合条件的订阅者'));
        }

        $sent_count = 0;
        $failed_count = 0;

        $base_subject = $subject;
        $base_content = $content;
        $reply_plain = '';
        $reply_for_html = '';
        $template_is_html = false;

        if ($template !== '') {
            if (!function_exists('todo_get_email_template')) {
                throw new Exception(__('邮件模板功能未加载'));
            }
            $tpl = todo_get_email_template($template);
            if (!$tpl) {
                throw new Exception(__('邮件模板不存在'));
            }
            if (isset($tpl->category) && (string) $tpl->category !== '' && (string) $tpl->category !== 'subscribe') {
                throw new Exception(__('邮件模板分类不正确'));
            }

            $tpl_subject = trim((string) ($tpl->subject ?? ''));
            $tpl_content = trim((string) ($tpl->content ?? ''));
            if ($tpl_subject === '') {
                throw new Exception(__('邮件模板主题为空'));
            }
            if ($tpl_content === '') {
                throw new Exception(__('邮件模板内容为空'));
            }

            $base_subject = $subject !== '' ? $subject : $tpl_subject;
            $base_content = $tpl_content;

            $reply_plain = $content;
            $template_is_html = preg_match('/<\s*[a-z][a-z0-9]*\b[^>]*>/i', $tpl_content) ? true : false;
            if ($template_is_html && $reply_plain !== '') {
                $reply_has_html = preg_match('/<\s*[a-z][a-z0-9]*\b[^>]*>/i', $reply_plain) ? true : false;
                $reply_for_html = $reply_has_html ? $reply_plain : nl2br(htmlspecialchars($reply_plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            } else {
                $reply_for_html = $reply_plain;
            }
        }

        foreach ($subscribers as $subscriber) {
            try {
                $subscriber_for_subject = is_array($subscriber) ? $subscriber : [];
                $subscriber_for_body = is_array($subscriber) ? $subscriber : [];
                if ($template !== '') {
                    $subscriber_for_subject['reply_content'] = $reply_plain;
                    $subscriber_for_body['reply_content'] = $reply_for_html;
                }

                $personalized_subject = personalize_email_content($base_subject, $subscriber_for_subject);
                $personalized_content = personalize_email_content($base_content, $subscriber_for_body);
                $mid = '';
                $err = '';
                $result = send_email($subscriber['email'], $personalized_subject, $personalized_content, $mid, $err);

                if ($result) {
                    $sent_count++;
                    // 记录发送日志
                    log_email_sent($pdo, $subscriber['email'], $subscriber['name'] ?? '', $personalized_subject, $personalized_content, 'bulk', 'sent', null, $mid);
                } else {
                    $failed_count++;
                    $fail_reason = todo_email_subscription_normalize_mail_error($err);
                    log_email_sent($pdo, $subscriber['email'], $subscriber['name'] ?? '', $personalized_subject, $personalized_content, 'bulk', 'failed', $fail_reason !== '' ? $fail_reason : 'send_email returned false', $mid);
                }
            } catch (Exception $e) {
                $failed_count++;
                error_log('邮件发送失败: ' . $e->getMessage());
                log_email_sent($pdo, $subscriber['email'] ?? '', $subscriber['name'] ?? '', $subject, $content, 'bulk', 'failed', $e->getMessage());
            }
        }

        echo json_encode([
            'success' => true,
            'message' => sprintf(__('邮件发送完成！成功：%d，失败：%d'), $sent_count, $failed_count),
            'data' => [
                'sent_count' => $sent_count,
                'failed_count' => $failed_count,
                'total_count' => count($subscribers)
            ]
        ]);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * 处理单个邮件发送
 */
function handle_send_single_email($pdo)
{
    try {
        $table = $pdo->prefix . 'email_subscribers';
        $subscription_id = intval($_POST['subscription_id'] ?? 0);
        $to = trim($_POST['to'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $template = trim((string) ($_POST['template'] ?? ''));

        if ($subject === '' && $template === '') {
            throw new Exception('邮件主题不能为空');
        }
        $token_key = 'send_single_email|' . (string) $to . '|' . (string) $subject . '|' . (string) $content . '|' . (string) $subscription_id . '|' . (string) $template;
        if (function_exists('todo_claim_submit_token') && !todo_claim_submit_token($token_key, 120)) {
            throw new Exception('正在发送中，请勿重复提交');
        }

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('邮箱格式不正确');
        }

        // 获取订阅者信息
        $subscriber = null;
        if ($subscription_id) {
            $subscriber = $pdo->get_row("SELECT * FROM {$table} WHERE id = ?", [$subscription_id], 'ARRAY_A');
        }

        $subscriber_data = is_array($subscriber) ? $subscriber : ['name' => '', 'email' => $to];
        $base_subject = $subject;
        $base_content = $content;
        $subscriber_for_subject = $subscriber_data;
        $subscriber_for_body = $subscriber_data;

        if ($template !== '') {
            if (!function_exists('todo_get_email_template')) {
                throw new Exception('邮件模板功能未加载');
            }
            $tpl = todo_get_email_template($template);
            if (!$tpl) {
                throw new Exception('邮件模板不存在');
            }
            if (isset($tpl->category) && (string) $tpl->category !== '' && (string) $tpl->category !== 'subscribe') {
                throw new Exception('邮件模板分类不正确');
            }
            $tpl_subject = trim((string) ($tpl->subject ?? ''));
            $tpl_content = trim((string) ($tpl->content ?? ''));
            if ($tpl_subject === '') {
                throw new Exception('邮件模板主题为空');
            }
            if ($tpl_content === '') {
                throw new Exception('邮件模板内容为空');
            }

            $base_subject = $subject !== '' ? $subject : $tpl_subject;
            $base_content = $tpl_content;

            $reply_plain = $content;
            $template_is_html = preg_match('/<\s*[a-z][a-z0-9]*\b[^>]*>/i', $tpl_content) ? true : false;
            $reply_for_html = $reply_plain;
            if ($template_is_html && $reply_plain !== '') {
                $reply_has_html = preg_match('/<\s*[a-z][a-z0-9]*\b[^>]*>/i', $reply_plain) ? true : false;
                $reply_for_html = $reply_has_html ? $reply_plain : nl2br(htmlspecialchars($reply_plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            }
            $subscriber_for_subject['reply_content'] = $reply_plain;
            $subscriber_for_body['reply_content'] = $reply_for_html;
        }

        $subject = personalize_email_content($base_subject, $subscriber_for_subject);
        $content = personalize_email_content($base_content, $subscriber_for_body);

        $mid = '';
        $err = '';
        $result = send_email($to, $subject, $content, $mid, $err);

        if ($result) {
            // 记录发送日志
            $to_name = '';
            if ($subscriber && isset($subscriber['name'])) {
                $to_name = (string) $subscriber['name'];
            }
            log_email_sent($pdo, $to, $to_name, $subject, $content, 'single', 'sent', null, $mid);

            echo json_encode([
                'success' => true,
                'message' => '邮件发送成功'
            ]);
        } else {
            $fail_reason = todo_email_subscription_normalize_mail_error($err);
            log_email_sent($pdo, $to, ($subscriber['name'] ?? ''), $subject, $content, 'single', 'failed', $fail_reason !== '' ? $fail_reason : 'send_email returned false', $mid);
            throw new Exception($fail_reason !== '' ? ('邮件发送失败：' . $fail_reason) : '邮件发送失败');
        }

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * 处理导出订阅
 */
function handle_export_subscriptions($pdo)
{
    try {
        $table = $pdo->prefix . 'email_subscribers';
        $format = $_POST['format'] ?? 'csv';
        $status = $_POST['status'] ?? '';
        $date_from = $_POST['date_from'] ?? '';
        $date_to = $_POST['date_to'] ?? '';

        // 构建查询条件
        $where_conditions = [];
        $params = [];

        if ($status) {
            $where_conditions[] = "status = ?";
            $params[] = $status;
        }

        if ($date_from) {
            $where_conditions[] = "created_at >= ?";
            $params[] = $date_from . ' 00:00:00';
        }

        if ($date_to) {
            $where_conditions[] = "created_at <= ?";
            $params[] = $date_to . ' 23:59:59';
        }

        $where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

        // 查询订阅数据
        $subscriptions = $pdo->get_results("
            SELECT id, email, name, status, source, created_at, confirmed_at 
            FROM {$table} 
            $where_clause 
            ORDER BY created_at DESC
        ", $params, 'ARRAY_A');

        if ($format === 'csv') {
            export_subscriptions_to_csv($subscriptions);
        } else {
            export_subscriptions_to_excel($subscriptions);
        }

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * 获取订阅详情
 */
function handle_get_subscription($pdo)
{
    try {
        $table = $pdo->prefix . 'email_subscribers';
        $subscription_id = intval($_POST['subscription_id'] ?? 0);

        if (!$subscription_id) {
            throw new Exception('订阅ID不能为空');
        }

        $subscription = $pdo->get_row("SELECT * FROM {$table} WHERE id = ?", [$subscription_id], 'ARRAY_A');

        if (!$subscription) {
            throw new Exception('订阅不存在');
        }

        echo json_encode([
            'success' => true,
            'data' => $subscription
        ]);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * 个性化邮件内容
 */
function personalize_email_content($content, $subscriber)
{
    $locale = '';
    if (function_exists('get_current_language')) {
        $locale = (string) get_current_language();
    }
    if ($locale === '') {
        $locale = (string) get_option('locale', '');
    }
    $locale = strtolower(str_replace('_', '-', trim($locale)));
    $is_zh = $locale !== '' && strpos($locale, 'zh') === 0;
    $default_name = $is_zh ? '订阅者' : 'Subscriber';
    $default_site_name = $is_zh ? '网站' : 'Website';
    $site_url = function_exists('todo_get_site_url') ? (string) todo_get_site_url() : (string) get_option('site_url', '');
    $site_url = trim($site_url);
    if ($site_url === '' && !empty($_SERVER['HTTP_HOST'])) {
        $scheme = (function_exists('is_ssl') && is_ssl()) ? 'https://' : 'http://';
        $site_url = $scheme . $_SERVER['HTTP_HOST'];
    }
    $site_url = rtrim($site_url, '/');
    $sub_token = '';
    if (!empty($subscriber['unsubscribe_token'])) {
        $sub_token = (string) $subscriber['unsubscribe_token'];
    } elseif (!empty($subscriber['email'])) {
        $sub_token = base64_encode($subscriber['email']);
    }
    $unsubscribe_url = $site_url !== '' ? ($site_url . '/unsubscribe' . ($sub_token !== '' ? '?token=' . urlencode($sub_token) : '')) : '';

    $replacements = [
        '{name}' => $subscriber['name'] ?: $default_name,
        '{email}' => $subscriber['email'],
        '{date}' => date('Y-m-d'),
        '{time}' => date('H:i:s'),
        '{site_name}' => todo_get_site_name($default_site_name),
        '{site_url}' => $site_url,
        '{unsubscribe_url}' => $unsubscribe_url,
        '{reply_content}' => (string) ($subscriber['reply_content'] ?? '')
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $content);
}

function todo_email_subscription_normalize_mail_error($s)
{
    $s = trim((string) $s);
    if ($s === '') return '';
    $s = preg_replace('/^(邮件发送失败[:：]\s*)+/u', '', $s);
    $s = trim((string) $s);
    return $s;
}

/**
 * 发送邮件
 */
function send_email($to, $subject, $content, &$message_id = '', &$error_message = '')
{
    try {
        $message_id = '';
        $error_message = '';
        if (function_exists('todo_generate_message_id')) {
            $message_id = (string) todo_generate_message_id();
        }
        $smtp_user = '';
        if (class_exists('CMS_Option')) {
            $smtp_user = trim((string) CMS_Option::get('mail_smtp_user', ''));
        }
        $site_name = todo_get_site_name('TodoCMS');
        $from_header = '';
        if ($smtp_user !== '') {
            $encoded_name = '=?UTF-8?B?' . base64_encode($site_name) . '?=';
            $from_header = "From: {$encoded_name} <{$smtp_user}>";
        }
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            $from_header !== '' ? $from_header : ('From: ' . (get_option('admin_email') ?: 'noreply@' . $_SERVER['HTTP_HOST'])),
            $smtp_user !== '' ? ('Reply-To: ' . $smtp_user) : ('Reply-To: ' . (get_option('admin_email') ?: 'noreply@' . $_SERVER['HTTP_HOST'])),
            'X-Mailer: TodoCMS'
        ];
        if ($message_id !== '') {
            $headers[] = 'Message-ID: ' . $message_id;
        }

        // 包装邮件内容
        $raw_content = (string) $content;
        if (function_exists('todo_mail_strip_reply_noise')) {
            $raw_content = todo_mail_strip_reply_noise($raw_content);
        }
        $raw_content = trim($raw_content);

        $site_url = function_exists('todo_get_site_url') ? (string) todo_get_site_url() : (string) get_option('site_url', '');
        $site_url = trim($site_url);
        if ($site_url === '' && !empty($_SERVER['HTTP_HOST'])) {
            $scheme = (function_exists('is_ssl') && is_ssl()) ? 'https://' : 'http://';
            $site_url = $scheme . $_SERVER['HTTP_HOST'];
        }
        $site_url = rtrim($site_url, '/');
        $host = '';
        try {
            $host = (string) parse_url($site_url, PHP_URL_HOST);
        } catch (Throwable $e) {
            $host = '';
        }
        $is_local_host = $host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || strpos($host, '.') === false;
        $is_full_html = false;
        if ($raw_content !== '') {
            $is_full_html = (bool) (
                preg_match('/<!doctype\s+html/i', $raw_content) ||
                preg_match('/<\s*html\b/i', $raw_content) ||
                preg_match('/<\s*body\b/i', $raw_content)
            );
        }

        $is_html_fragment = !$is_full_html && $raw_content !== '' && preg_match('/<\s*[a-z][a-z0-9]*\b[^>]*>/i', $raw_content);

        if ($is_full_html || $is_html_fragment) {
            $html_content = $raw_content;
        } else {
            $locale = '';
            if (function_exists('get_current_language')) {
                $locale = (string) get_current_language();
            }
            if ($locale === '') {
                $locale = (string) get_option('locale', '');
            }
            $locale = strtolower(str_replace('_', '-', trim($locale)));
            $is_zh = $locale !== '' && strpos($locale, 'zh') === 0;

            $safe_title = htmlspecialchars((string) $subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safe_site_name = htmlspecialchars(todo_get_site_name($is_zh ? '网站' : 'Website'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $sub_token = '';
            if (!empty($subscriber['unsubscribe_token'])) {
                $sub_token = (string) $subscriber['unsubscribe_token'];
            } elseif (!empty($subscriber['email'])) {
                $sub_token = base64_encode($subscriber['email']);
            } elseif (!empty($to) && filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $sub_token = base64_encode($to);
            }
            $unsubscribe_url = $site_url !== '' ? ($site_url . '/unsubscribe' . ($sub_token !== '' ? '?token=' . urlencode($sub_token) : '')) : '';
            $safe_unsubscribe_url = htmlspecialchars($unsubscribe_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $sent_by_line = $is_zh ? ("此邮件由 {$safe_site_name} 发送") : ("This email was sent by {$safe_site_name}");
            $unsubscribe_html = '';
            if (!$is_local_host && $unsubscribe_url !== '') {
                $unsubscribe_html = $is_zh
                    ? ("如不想再收到此类邮件，请 <a href='{$safe_unsubscribe_url}'>取消订阅</a>")
                    : ("To unsubscribe, <a href='{$safe_unsubscribe_url}'>click here</a>");
            }

            $inner = nl2br(htmlspecialchars($raw_content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

            $html_content = "
            <html>
            <head>
                <title>{$safe_title}</title>
                <meta charset='UTF-8'>
            </head>
            <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                    {$inner}
                    <hr style='margin: 30px 0; border: none; border-top: 1px solid #eee;'>
                    <p style='font-size: 12px; color: #666; text-align: center;'>
                        {$sent_by_line}<br>
                        {$unsubscribe_html}
                    </p>
                </div>
            </body>
            </html>
            ";
        }

        if (function_exists('todo_mail')) {
            $ok = todo_mail($to, $subject, $html_content, $headers);
            if (!$ok && function_exists('todo_get_last_mail_error')) {
                $error_message = (string) todo_get_last_mail_error();
                $error_message = todo_email_subscription_normalize_mail_error($error_message);
                if ($error_message !== '' && stripos($error_message, '550') !== false && stripos($error_message, 'inappropriate') !== false) {
                    $error_message .= '（内容被 SMTP 服务商拦截：请检查主题/正文是否包含敏感词或可疑链接）';
                }
            }
            return $ok;
        }
        $ok = mail($to, $subject, $html_content, implode("\r\n", $headers));
        if (!$ok) {
            $error_message = 'PHP mail() 返回 false';
        }
        return $ok;

    } catch (Exception $e) {
        $error_message = $e->getMessage();
        error_log('邮件发送失败: ' . $e->getMessage());
        return false;
    }
}

/**
 * 发送欢迎邮件
 */
function send_welcome_email($email, $name, $confirmation_token, $status, $template_slug = '')
{
    try {
        $subject = '欢迎订阅我们的邮件！';
        $confirm_url = '';
        if ($status === 'pending' && !empty($confirmation_token)) {
            $confirm_url = get_option('site_url', '') . "/confirm-subscription?token=" . $confirmation_token;
        }

        if ($template_slug !== '' && function_exists('todo_get_email_template') && function_exists('todo_send_template_mail')) {
            $template = todo_get_email_template($template_slug);
            if ($template && isset($template->category) && $template->category === 'subscribe') {
                $sent = todo_send_template_mail($email, $template_slug, [
                    'name' => $name ?: '订阅者',
                    'email' => $email,
                    'site_name' => todo_get_site_name('网站'),
                    'site_url' => get_option('site_url', ''),
                    'confirm_url' => $confirm_url,
                    'status' => $status
                ]);
                if ($sent) {
                    return;
                }
            }
        }

        if ($status === 'pending' && $confirm_url !== '') {
            $content = "
                亲爱的 " . ($name ?: '订阅者') . "，
                
                感谢您订阅我们的邮件！
                
                为了确保您能正常接收我们的邮件，请点击下面的链接确认您的订阅：
                
                {$confirm_url}
                
                如果您没有订阅我们的邮件，请忽略此邮件。
                
                感谢您的关注！
            ";
        } else {
            $content = "
                亲爱的 " . ($name ?: '订阅者') . "，
                
                感谢您订阅我们的邮件！
                
                我们会定期向您发送最新的资讯和优惠信息。
                
                如果您有任何问题，请随时联系我们。
                
                感谢您的关注！
            ";
        }

        send_email($email, $subject, $content);

    } catch (Exception $e) {
        error_log('欢迎邮件发送失败: ' . $e->getMessage());
    }
}

/**
 * 记录邮件发送日志
 */
function log_email_sent($pdo, $to_email, $to_name, $subject, $content, $type, $status, $error_message, $message_id = '')
{
    try {
        $table = $pdo->prefix . 'email_logs';
        $table_columns = todo_db_get_table_columns($pdo, $table);
        $now = current_time('mysql');
        $insert_data = [
            'to_email' => $to_email,
            'to_name' => $to_name,
            'subject' => $subject,
            'content' => $content,
            'type' => $type,
            'status' => $status,
            'error_message' => $error_message,
            'sent_at' => $now,
            'created_at' => $now
        ];
        if (is_string($message_id) && $message_id !== '') {
            $insert_data['message_id'] = $message_id;
        }
        if (in_array('direction', $table_columns, true)) {
            $insert_data['direction'] = 'out';
        }
        if (in_array('from_email', $table_columns, true)) {
            $insert_data['from_email'] = (string) (get_option('admin_email') ?: '');
        }
        $insert_data = todo_db_filter_data_by_columns($insert_data, $table_columns);
        if (!empty($insert_data['to_email']) && !empty($insert_data['subject']) && !empty($insert_data['content'])) {
            $pdo->insert($table, $insert_data);
        }
    } catch (Exception $e) {
        error_log('邮件日志记录失败: ' . $e->getMessage());
    }
}

function todo_db_get_table_columns($db, $table)
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $columns = [];
    try {
        $rows = $db->get_results('SHOW COLUMNS FROM `' . $table . '`', [], 'ARRAY_A');
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (isset($row['Field'])) {
                    $columns[] = (string) $row['Field'];
                }
            }
        }
    } catch (Exception $e) {
        $columns = [];
    }

    $cache[$table] = $columns;
    return $columns;
}

function todo_db_filter_data_by_columns($data, $columns)
{
    if (!is_array($data) || empty($data) || !is_array($columns) || empty($columns)) {
        return [];
    }
    $filtered = [];
    foreach ($data as $key => $value) {
        if (in_array($key, $columns, true)) {
            $filtered[$key] = $value;
        }
    }
    return $filtered;
}

/**
 * 导出订阅为CSV格式
 */
function export_subscriptions_to_csv($subscriptions)
{
    $filename = 'email_subscriptions_' . date('Y-m-d_H-i-s') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    // 添加BOM以支持中文
    fwrite($output, "\xEF\xBB\xBF");

    // 写入表头
    fputcsv($output, [
        'ID',
        '邮箱',
        '姓名',
        '状态',
        '来源',
        '订阅时间',
        '确认时间'
    ]);

    // 写入数据
    foreach ($subscriptions as $subscription) {
        fputcsv($output, [
            $subscription['id'],
            $subscription['email'],
            $subscription['name'],
            $subscription['status'],
            $subscription['source'],
            $subscription['created_at'],
            $subscription['confirmed_at']
        ]);
    }

    fclose($output);
    exit;
}

/**
 * 导出订阅为Excel格式（简化版）
 */
function export_subscriptions_to_excel($subscriptions)
{
    $filename = 'email_subscriptions_' . date('Y-m-d_H-i-s') . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');

    echo "\xEF\xBB\xBF"; // BOM
    echo "<table border='1'>";
    echo "<tr>";
    echo "<th>ID</th><th>邮箱</th><th>姓名</th><th>状态</th><th>来源</th><th>订阅时间</th><th>确认时间</th>";
    echo "</tr>";

    foreach ($subscriptions as $subscription) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($subscription['id']) . "</td>";
        echo "<td>" . htmlspecialchars($subscription['email']) . "</td>";
        echo "<td>" . htmlspecialchars($subscription['name']) . "</td>";
        echo "<td>" . htmlspecialchars($subscription['status']) . "</td>";
        echo "<td>" . htmlspecialchars($subscription['source']) . "</td>";
        echo "<td>" . htmlspecialchars($subscription['created_at']) . "</td>";
        echo "<td>" . htmlspecialchars($subscription['confirmed_at']) . "</td>";
        echo "</tr>";
    }

    echo "</table>";
    exit;
}

?>
