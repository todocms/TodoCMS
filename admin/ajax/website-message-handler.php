<?php
/**
 * 网站留言AJAX处理器
 * TodoCMS - 网站留言管理
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 安全检查：仅允许系统内部调度与验证通过的 AJAX 请求访问
if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}

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
if (!class_exists('Todo_Session_Manager')) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

Todo_Session_Manager::init();

if (!Todo_Session_Manager::is_admin_logged_in()) {
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

// 获取数据库连接
try {
    $tododb = get_database_connection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

if (!function_exists('todo_send_message_reply_notification') || !function_exists('todo_log_email')) {
    if (!defined('ABSPATH')) {
        define('ABSPATH', dirname(__FILE__) . '/../../');
    }
    $mail_extend_path = rtrim(ABSPATH, '/') . '/core/functions/mail-extend.php';
    if (is_file($mail_extend_path)) {
        require_once $mail_extend_path;
    }
}

function todo_ajax_db_pdo($db)
{
    if ($db instanceof PDO) {
        return $db;
    }
    if (is_object($db) && method_exists($db, 'get_connection')) {
        $pdo = $db->get_connection();
        if ($pdo instanceof PDO) {
            return $pdo;
        }
    }
    return null;
}

function todo_ajax_db_prefix($db)
{
    $prefix = '';
    if (defined('DB_PREFIX')) {
        $prefix = (string) DB_PREFIX;
    }
    if ($prefix === '' && is_object($db) && isset($db->prefix)) {
        $prefix = (string) $db->prefix;
    }
    if ($prefix === '' && defined('TABLE_PREFIX')) {
        $prefix = (string) TABLE_PREFIX;
    }
    if ($prefix === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
        $prefix = 'todo_';
    }
    return $prefix;
}

function todo_ajax_db_table_exists($pdo, $table_name)
{
    if (!$pdo instanceof PDO) {
        return false;
    }
    $table_name = trim((string) $table_name);
    if ($table_name === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) {
        return false;
    }
    try {
        $q = $pdo->quote($table_name);
        if ($q === false || $q === '') {
            return false;
        }
        $stmt = $pdo->query("SHOW TABLES LIKE {$q}");
        if (!$stmt) {
            return false;
        }
        $row = $stmt->fetch(PDO::FETCH_NUM);
        return !empty($row);
    } catch (Throwable $e) {
        return false;
    }
}

function todo_ajax_db_table_columns($pdo, $table_name)
{
    if (!$pdo instanceof PDO) {
        return [];
    }
    $table_name = trim((string) $table_name);
    if ($table_name === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) {
        return [];
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table_name}`");
        if (!$stmt) {
            return [];
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $cols = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $f = isset($r['Field']) ? trim((string) $r['Field']) : '';
                if ($f !== '') {
                    $cols[] = $f;
                }
            }
        }
        return $cols;
    } catch (Throwable $e) {
        return [];
    }
}

function todo_ajax_db_get_row($db, $query, $params = [], $output_type = 'OBJECT')
{
    if (is_object($db) && method_exists($db, 'get_row')) {
        return $db->get_row($query, $params, $output_type);
    }

    $pdo = todo_ajax_db_pdo($db);
    if (!$pdo) {
        return null;
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute(is_array($params) ? $params : []);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    if ($output_type === 'ARRAY_A') {
        return $row;
    }
    return (object) $row;
}

function todo_ajax_db_get_results($db, $query, $params = [], $output_type = 'OBJECT')
{
    if (is_object($db) && method_exists($db, 'get_results')) {
        return $db->get_results($query, $params, $output_type);
    }

    $pdo = todo_ajax_db_pdo($db);
    if (!$pdo) {
        return [];
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute(is_array($params) ? $params : []);
    if ($output_type === 'ARRAY_A') {
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_map(static fn($r) => (object) $r, $rows);
}

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // CSRF验证
    $csrf_actions = ['reply_message', 'add_message', 'update_status', 'delete_message', 'export_messages'];
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
        case 'reply_message':
            handle_reply_message($tododb);
            break;

        case 'add_message':
            handle_add_message($tododb);
            break;

        case 'update_status':
            handle_update_message_status($tododb);
            break;

        case 'delete_message':
            handle_delete_message($tododb);
            break;

        case 'get_message':
            handle_get_message($tododb);
            break;

        case 'export_messages':
            handle_export_messages($tododb);
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
 * 处理回复留言
 */
function handle_reply_message($pdo)
{
    try {
        $db = $pdo;
        $pdo = todo_ajax_db_pdo($db);
        if (!$pdo) {
            throw new Exception('Database connection failed');
        }
        $prefix = todo_ajax_db_prefix($db);

        $message_id = intval($_POST['message_id'] ?? 0);
        $reply_content = trim($_POST['reply_content'] ?? '');
        $mail_subject = trim($_POST['subject'] ?? '');
        $notify_user = isset($_POST['notify_user']) ? 1 : 0;

        $need_notify = ($notify_user || (isset($_POST['send_email']) && $_POST['send_email'] == '1')) ? 1 : 0;
        $template_slug = function_exists('sanitize_text_field') ? sanitize_text_field($_POST['template_slug'] ?? '') : (string) ($_POST['template_slug'] ?? '');
        $reply_content_for_email = $reply_content;
        $reply_content_for_store = $reply_content;
        if ($reply_content_for_store === '' && $need_notify && $template_slug !== '' && function_exists('todo_get_email_template')) {
            $tpl = todo_get_email_template($template_slug);
            if ($tpl) {
                $reply_content_for_store = '（邮件模板回复）';
            }
        }
        if (!$message_id || $reply_content_for_store === '') {
            throw new Exception('留言ID和回复内容不能为空');
        }
        $token_key = 'reply_message|' . intval($message_id) . '|' . (string) $reply_content_for_store . '|' . (string) $mail_subject . '|' . intval($need_notify) . '|' . (string) $template_slug;
        if (function_exists('todo_claim_submit_token') && !todo_claim_submit_token($token_key, 120)) {
            throw new Exception('正在发送中，请勿重复提交');
        }

        // 检查留言文章是否存在；如果不存在，则检查是否是对已存在回复（comment_ID）的二级回复
        $post = todo_ajax_db_get_row($db, "SELECT * FROM {$prefix}posts WHERE ID = ?", [$message_id], 'ARRAY_A');
        $target_post_id = 0;
        $parent_comment_id = 0;

        if ($post) {
            $target_post_id = (int) $post['ID'];
        } else {
            $comment = todo_ajax_db_get_row($db, "SELECT * FROM {$prefix}comments WHERE comment_ID = ?", [$message_id], 'ARRAY_A');
            if ($comment) {
                $target_post_id = (int) $comment['comment_post_ID'];
                $parent_comment_id = (int) $comment['comment_ID'];
            }
        }

        if (!$target_post_id) {
            throw new Exception('留言不存在');
        }

        // 插入回复到评论表（默认自动批准为 1）
        $stmt = $pdo->prepare("
            INSERT INTO {$prefix}comments 
            (comment_post_ID, comment_parent, comment_author, comment_author_email, comment_content, comment_approved, comment_date, user_id) 
            VALUES (?, ?, '管理员', ?, ?, '1', NOW(), ?)
        ");
        
        $admin_user_id = Todo_Session_Manager::get_admin_user_id();
        $admin_email = get_option('admin_email') ?: 'admin@' . $_SERVER['HTTP_HOST'];
        
        $stmt->execute([
            $target_post_id,
            $parent_comment_id,
            $admin_email,
            $reply_content_for_store,
            $admin_user_id
        ]);
        $new_reply_id = (int) $pdo->lastInsertId();

        if ($new_reply_id > 0 && $parent_comment_id > 0) {
            $parent_c = todo_ajax_db_get_row($db, "SELECT comment_author FROM {$prefix}comments WHERE comment_ID = ?", [$parent_comment_id], 'ARRAY_A');
            if ($parent_c && !empty($parent_c['comment_author'])) {
                $cm_stmt = $pdo->prepare("
                    INSERT INTO {$prefix}commentmeta (comment_id, meta_key, meta_value) 
                    VALUES (?, 'reply_to_name', ?)
                ");
                $cm_stmt->execute([$new_reply_id, $parent_c['comment_author']]);
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO {$prefix}post_custom_fields (post_id, field_key, field_value, updated_at) 
            VALUES (?, 'message_status', 'pending', NOW())
            ON DUPLICATE KEY UPDATE field_value = 'pending', updated_at = NOW()
        ");
        $stmt->execute([$target_post_id]);

        // 如果需要通知用户，发送邮件
        $user_email = '';
        $pick_valid_email = function ($v) {
            $v = trim((string) $v);
            if ($v === '') return '';
            if (function_exists('is_email') && !is_email($v)) return '';
            return $v;
        };
        try {
            $cf_stmt = $pdo->prepare("SELECT field_key, field_value FROM {$prefix}post_custom_fields WHERE post_id = ?");
            $cf_stmt->execute([$target_post_id]);
            $cf_rows = $cf_stmt->fetchAll(PDO::FETCH_ASSOC);
            $cf_map = [];
            if (is_array($cf_rows)) {
                foreach ($cf_rows as $r) {
                    $k = isset($r['field_key']) ? trim((string) $r['field_key']) : '';
                    if ($k === '') continue;
                    $cf_map[$k] = isset($r['field_value']) ? (string) $r['field_value'] : '';
                }
            }
            foreach (['contact_email', 'email', 'user_email', 'mail', 'contactEmail'] as $k) {
                if (!isset($cf_map[$k])) continue;
                $user_email = $pick_valid_email($cf_map[$k]);
                if ($user_email !== '') break;
            }
        } catch (Throwable $e) {
            $user_email = '';
        }

        if ($user_email === '') {
            try {
                $mf_row = null;
                $guestbook_table = $prefix . 'model_fields_guestbook';
                if (todo_ajax_db_table_exists($pdo, $guestbook_table)) {
                    $cols = todo_ajax_db_table_columns($pdo, $guestbook_table);
                    $select_cols = [];
                    if (in_array('contact_email', $cols, true)) $select_cols[] = 'contact_email';
                    if (in_array('email', $cols, true)) $select_cols[] = 'email';
                    if (!empty($select_cols)) {
                        $mf_stmt = $pdo->prepare("SELECT " . implode(', ', $select_cols) . " FROM {$guestbook_table} WHERE post_id = ? LIMIT 1");
                        $mf_stmt->execute([$message_id]);
                        $mf_row = $mf_stmt->fetch(PDO::FETCH_ASSOC);
                    }
                }
                if ($mf_row) {
                    if (!empty($mf_row['contact_email'])) {
                        $user_email = $pick_valid_email($mf_row['contact_email']);
                    }
                    if ($user_email === '' && !empty($mf_row['email'])) {
                        $user_email = $pick_valid_email($mf_row['email']);
                    }
                }
            } catch (Throwable $e) {
                // ignore when table not exists
            }
        }

        $mail_sent = null;
        $mail_error = '';

        if ($need_notify) {
            if (empty($user_email)) {
                $mail_sent = false;
                $mail_error = '未找到收件人邮箱（contact_email 为空）';
                if (function_exists('todo_log_email')) {
                    todo_log_email('', $mail_subject !== '' ? $mail_subject : 'message_reply', 'failed', $mail_error);
                }
            } elseif (function_exists('todo_send_message_reply_notification')) {
                $mail_sent = todo_send_message_reply_notification($target_post_id, $reply_content_for_email, $template_slug, $mail_subject);
                if (!$mail_sent) {
                    $mail_error = '邮件发送失败（请到 邮件日志 查看失败原因）';
                }
            } else {
                $mail_sent = false;
                $mail_error = '邮件发送函数未加载';
            }
        }

        echo json_encode([
            'success' => true,
            'message' => '回复发送成功',
            'mail_sent' => $mail_sent,
            'mail_error' => $mail_error
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
 * 处理添加留言 (现在已通过 post-edit 页面，但保留此 API 以防万一，并更新为 posts 表)
 */
function handle_add_message($pdo)
{
    try {
        $db = $pdo;
        $pdo = todo_ajax_db_pdo($db);
        if (!$pdo) {
            throw new Exception('Database connection failed');
        }

        $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_';
        $posts_table = $prefix . 'posts';
        $custom_fields_table = $prefix . 'post_custom_fields';
        $models_table = $prefix . 'models';
        $model_fields_table = $prefix . 'model_fields';
        $term_models_table = $prefix . 'term_models';

        $category_id = intval($_POST['form_type'] ?? ($_POST['category_id'] ?? 0));
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? ($_POST['subject'] ?? '')));
        $content = trim((string) ($_POST['content'] ?? ($_POST['message'] ?? '')));
        $status = (string) ($_POST['status'] ?? 'pending');

        $guestbook_model_id = 0;
        $stmt = $pdo->prepare("SELECT model_id FROM {$models_table} WHERE model_key = 'guestbook' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['model_id'])) {
            $guestbook_model_id = intval($row['model_id']);
        }
        if ($guestbook_model_id <= 0) {
            throw new Exception('留言模型未配置');
        }
        
        $post_status = ($status === 'approved' || $status === 'publish') ? 'publish' : 'pending';

        if ($category_id <= 0) {
            throw new Exception('分类不能为空');
        }
        if ($content === '') {
            throw new Exception('内容不能为空');
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM {$term_models_table} WHERE term_id = ? AND model_id = ?");
        $stmt->execute([$category_id, $guestbook_model_id]);
        $cnt_row = $stmt->fetch(PDO::FETCH_ASSOC);
        $cnt = $cnt_row ? intval($cnt_row['cnt'] ?? 0) : 0;
        if ($cnt <= 0) {
            throw new Exception('所选分类未绑定留言模型');
        }

        // 插入到 posts 表
        $stmt = $pdo->prepare("
            INSERT INTO {$posts_table}
            (post_title, post_content, post_status, post_type, model_id, category_id, post_author, created_at, updated_at) 
            VALUES (?, ?, ?, 'guestbook', ?, ?, 0, NOW(), NOW())
        ");
        $stmt->execute([$title !== '' ? $title : '无标题', $content, $post_status, $guestbook_model_id, $category_id]);
        $new_post_id = $pdo->lastInsertId();

        $allowed_field_type_map = [];
        $stmt = $pdo->prepare("SELECT field_key, field_type FROM {$model_fields_table} WHERE model_id = ?");
        $stmt->execute([$guestbook_model_id]);
        $field_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($field_rows)) {
            foreach ($field_rows as $r) {
                $k = trim((string) ($r['field_key'] ?? ''));
                if ($k === '') continue;
                $allowed_field_type_map[$k] = (string) ($r['field_type'] ?? 'text');
            }
        }

        $custom_fields = [];
        if ($name !== '') $custom_fields['contact_name'] = $name;
        if ($email !== '') $custom_fields['contact_email'] = $email;
        if ($phone !== '') $custom_fields['contact_phone'] = $phone;
        $custom_fields['message_status'] = 'pending';

        foreach ($_POST as $key => $value) {
            if (!is_string($key) || strpos($key, 'custom_') !== 0) continue;
            $field_key = trim(substr($key, 7));
            if ($field_key === '' || strpos($field_key, '_') === 0) continue;
            if (in_array($field_key, ['contact_name', 'contact_email', 'contact_phone', 'message_status'], true)) {
                continue;
            }
            if (!isset($allowed_field_type_map[$field_key])) {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $custom_fields[$field_key] = trim((string) $value);
        }

        foreach ($custom_fields as $key => $value) {
            $field_type = $allowed_field_type_map[$key] ?? 'text';
            $stmt = $pdo->prepare("
                INSERT INTO {$custom_fields_table} (post_id, model_id, field_key, field_value, field_type, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE field_value = VALUES(field_value), field_type = VALUES(field_type), updated_at = NOW()
            ");
            $stmt->execute([$new_post_id, $guestbook_model_id, $key, $value, $field_type]);
        }

        echo json_encode([
            'success' => true,
            'message' => '留言添加成功'
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
 * 处理状态更新
 */
function handle_update_message_status($pdo)
{
    try {
        $db = $pdo;
        $pdo = todo_ajax_db_pdo($db);
        if (!$pdo) {
            throw new Exception('Database connection failed');
        }
        $prefix = todo_ajax_db_prefix($db);

        $message_id = intval($_POST['message_id'] ?? 0);
        $status = $_POST['status'] ?? '';

        if (!$message_id || !$status) {
            throw new Exception('留言ID和状态不能为空');
        }

        $status_map = [
            'approved' => 'publish',
            'pending'  => 'pending',
            'spam'     => 'spam',
            'trash'    => 'trash'
        ];
        $target_status = isset($status_map[$status]) ? $status_map[$status] : $status;

        $stmt = $pdo->prepare("UPDATE {$prefix}posts SET post_status = ?, updated_at = NOW() WHERE ID = ?");
        $stmt->execute([$target_status, $message_id]);

        echo json_encode([
            'success' => true,
            'message' => '状态更新成功'
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
 * 处理删除留言
 */
function handle_delete_message($pdo)
{
    try {
        $db = $pdo;
        $pdo = todo_ajax_db_pdo($db);
        if (!$pdo) {
            throw new Exception('Database connection failed');
        }
        $prefix = todo_ajax_db_prefix($db);

        $message_id = intval($_POST['message_id'] ?? 0);

        if (!$message_id) {
            throw new Exception('留言ID不能为空');
        }

        $pdo->beginTransaction();

        $pdo->prepare("DELETE FROM {$prefix}comments WHERE comment_post_ID = ?")->execute([$message_id]);
        $pdo->prepare("DELETE FROM {$prefix}post_custom_fields WHERE post_id = ?")->execute([$message_id]);
        $pdo->prepare("DELETE FROM {$prefix}posts WHERE ID = ?")->execute([$message_id]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => '留言删除成功'
        ]);

    } catch (Exception $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * 获取留言详情
 */
function handle_get_message($pdo)
{
    try {
        $db = $pdo;
        $prefix = todo_ajax_db_prefix($db);
        $message_id = intval($_POST['message_id'] ?? 0);

        if (!$message_id) {
            throw new Exception('留言ID不能为空');
        }

        // 获取文章信息
        $post = todo_ajax_db_get_row($db, "SELECT * FROM {$prefix}posts WHERE ID = ?", [$message_id], 'ARRAY_A');

        if (!$post) {
            throw new Exception('留言不存在');
        }

        // 获取并映射为 message 对象，后端严格使用 todo_media_url_to_absolute 进行图片处理与回填显示
        $cf_rows = todo_ajax_db_get_results($db, "SELECT field_key, field_value FROM {$prefix}post_custom_fields WHERE post_id = ?", [$message_id], 'ARRAY_A');
        $custom_fields = [];
        foreach ($cf_rows as $row) {
            $fv = $row['field_value'];
            if (is_string($fv) && $fv !== '' && function_exists('todo_media_url_to_absolute')) {
                if (strpos($fv, '[') === 0 || strpos($fv, '{') === 0) {
                    $decoded = json_decode($fv, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        array_walk_recursive($decoded, function (&$v, $k) {
                            if (($k === 'url' || $k === 'thumb' || $k === 'file') && is_string($v) && $v !== '') {
                                $v = todo_media_url_to_absolute($v);
                            }
                        });
                        $fv = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                } else {
                    $fv = todo_media_url_to_absolute($fv);
                }
            }
            $custom_fields[$row['field_key']] = $fv;
        }

        $message = [
            'id' => $post['ID'],
            'subject' => $post['post_title'],
            'content' => $post['post_content'],
            'status' => $post['post_status'],
            'created_at' => $post['created_at'],
            'name' => $custom_fields['contact_name'] ?? '未知用户',
            'email' => $custom_fields['contact_email'] ?? '',
            'phone' => $custom_fields['contact_phone'] ?? '',
            'form_type' => $custom_fields['form_type'] ?? ($post['category_id'] ?? ''),
            'custom_fields' => $custom_fields
        ];

        // 获取回复列表 (从评论表)
        $replies = todo_ajax_db_get_results($db, "
            SELECT c.comment_ID as id, c.comment_content as content, c.comment_date as created_at, u.username as admin_name 
            FROM {$prefix}comments c 
            LEFT JOIN {$prefix}users u ON c.user_id = u.ID 
            WHERE c.comment_post_ID = ? 
            ORDER BY c.comment_date ASC
        ", [$message_id], 'ARRAY_A');

        echo json_encode([
            'success' => true,
            'data' => [
                'message' => $message,
                'replies' => $replies
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
 * 处理导出留言
 */
function handle_export_messages($pdo)
{
    try {
        $db = $pdo;
        $prefix = todo_ajax_db_prefix($db);
        $format = $_POST['format'] ?? 'csv';
        $status = $_POST['status'] ?? '';
        $form_type = $_POST['form_type'] ?? '';
        $date_from = $_POST['date_from'] ?? '';
        $date_to = $_POST['date_to'] ?? '';

        // 构建查询条件
        $where_conditions = [];
        $params = [];

        if ($status) {
            $status_map = [
                'approved' => 'publish',
                'pending'  => 'pending',
                'spam'     => 'spam',
                'trash'    => 'trash'
            ];
            $target_status = isset($status_map[$status]) ? $status_map[$status] : $status;
            $where_conditions[] = "p.post_status = ?";
            $params[] = $target_status;
        }

        if ($form_type) {
            $where_conditions[] = "p.category_id = ?";
            $params[] = $form_type;
        }

        if ($date_from) {
            $where_conditions[] = "p.created_at >= ?";
            $params[] = $date_from . ' 00:00:00';
        }

        if ($date_to) {
            $where_conditions[] = "p.created_at <= ?";
            $params[] = $date_to . ' 23:59:59';
        }

        $where_conditions[] = "p.post_type = 'guestbook'";
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

        // 查询留言数据
        $messages = todo_ajax_db_get_results($db, "
            SELECT
                p.ID as id,
                p.category_id as form_type,
                cf_name.field_value as name,
                cf_email.field_value as email,
                cf_phone.field_value as phone,
                p.post_title as subject,
                p.post_content as content,
                p.post_status as status,
                p.created_at as created_at
            FROM {$prefix}posts p
            LEFT JOIN {$prefix}post_custom_fields cf_name ON cf_name.post_id = p.ID AND cf_name.field_key = 'contact_name'
            LEFT JOIN {$prefix}post_custom_fields cf_email ON cf_email.post_id = p.ID AND cf_email.field_key = 'contact_email'
            LEFT JOIN {$prefix}post_custom_fields cf_phone ON cf_phone.post_id = p.ID AND cf_phone.field_key = 'contact_phone'
            $where_clause 
            ORDER BY p.created_at DESC
        ", $params, 'ARRAY_A');

        if ($format === 'csv') {
            export_to_csv($messages);
        } else {
            export_to_excel($messages);
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
 * 导出为CSV格式
 */
function export_to_csv($messages)
{
    $filename = 'website_messages_' . date('Y-m-d_H-i-s') . '.csv';

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
        '表单类型',
        '姓名',
        '邮箱',
        '电话',
        '主题',
        '内容',
        '状态',
        '提交时间'
    ]);

    // 写入数据
    foreach ($messages as $message) {
        fputcsv($output, [
            $message['id'],
            $message['form_type'],
            $message['name'],
            $message['email'],
            $message['phone'],
            $message['subject'],
            $message['content'],
            $message['status'],
            $message['created_at']
        ]);
    }

    fclose($output);
    exit;
}

/**
 * 导出为Excel格式（简化版）
 */
function export_to_excel($messages)
{
    $filename = 'website_messages_' . date('Y-m-d_H-i-s') . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');

    echo "\xEF\xBB\xBF"; // BOM
    echo "<table border='1'>";
    echo "<tr>";
    echo "<th>ID</th><th>表单类型</th><th>姓名</th><th>邮箱</th><th>电话</th><th>主题</th><th>内容</th><th>状态</th><th>提交时间</th>";
    echo "</tr>";

    foreach ($messages as $message) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($message['id']) . "</td>";
        echo "<td>" . htmlspecialchars($message['form_type']) . "</td>";
        echo "<td>" . htmlspecialchars($message['name']) . "</td>";
        echo "<td>" . htmlspecialchars($message['email']) . "</td>";
        echo "<td>" . htmlspecialchars($message['phone']) . "</td>";
        echo "<td>" . htmlspecialchars($message['subject']) . "</td>";
        echo "<td>" . htmlspecialchars($message['content']) . "</td>";
        echo "<td>" . htmlspecialchars($message['status']) . "</td>";
        echo "<td>" . htmlspecialchars($message['created_at']) . "</td>";
        echo "</tr>";
    }

    echo "</table>";
    exit;
}

/**
 * 发送回复通知邮件
 */
function send_reply_notification($message, $reply_content)
{
    try {
        $to = $message['email'];
        $subject = '您的留言已收到回复';

        $email_content = "
        <html>
        <head>
            <title>留言回复</title>
        </head>
        <body>
            <h2>您的留言已收到回复</h2>
            <p>尊敬的 {$message['name']}，</p>
            <p>您的留言已收到我们的回复：</p>
            <div style='background-color: #f9f9f9; padding: 15px; border-left: 4px solid #0073aa; margin: 20px 0;'>
                <strong>您的留言：</strong><br>
                " . nl2br(htmlspecialchars($message['content'])) . "
            </div>
            <div style='background-color: #e8f5e8; padding: 15px; border-left: 4px solid #46b450; margin: 20px 0;'>
                <strong>我们的回复：</strong><br>
                " . nl2br(htmlspecialchars($reply_content)) . "
            </div>
            <p>如果您还有其他问题，请随时联系我们。</p>
            <p>感谢您的关注！</p>
        </body>
        </html>
        ";

        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . (get_option('admin_email') ?: 'noreply@' . $_SERVER['HTTP_HOST']),
            'Reply-To: ' . (get_option('admin_email') ?: 'noreply@' . $_SERVER['HTTP_HOST']),
            'X-Mailer: TodoCMS'
        ];

        $result = function_exists('todo_mail') ? todo_mail($to, $subject, $email_content, $headers) : false;
        if (function_exists('todo_log_email')) {
            if ($result) {
                todo_log_email($to, $subject, 'sent');
            } else {
                todo_log_email($to, $subject, 'failed', 'Mail function returned false');
            }
        }

    } catch (Exception $e) {
        if (function_exists('todo_log_email')) {
            todo_log_email((string) ($message['email'] ?? ''), '您的留言已收到回复', 'failed', $e->getMessage());
        }
    }
}

?>
