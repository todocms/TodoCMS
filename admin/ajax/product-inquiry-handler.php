<?php
/**
 * 产品咨询AJAX处理器
 * TodoCMS - 产品咨询管理
 * 
 * @package TodoCMS
 * @subpackage Admin/Ajax
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
if (!class_exists('Todo_Session_Manager') || !Todo_Session_Manager::init() || !Todo_Session_Manager::is_admin_logged_in()) {
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
    $pdo = get_database_connection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // CSRF验证
    $csrf_actions = ['reply_inquiry', 'add_inquiry', 'update_status', 'delete_inquiry'];
    if (in_array($action, $csrf_actions)) {
        $csrf_token = $_POST['_csrf_token'] ?? '';
        if (!Todo_Security_Manager::verify_csrf_token($action, $csrf_token)) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF token verification failed']);
            exit;
        }
    }

    switch ($action) {
        case 'reply_inquiry':
            handle_reply_inquiry($pdo);
            break;

        case 'add_inquiry':
            handle_add_inquiry($pdo);
            break;

        case 'update_status':
            handle_update_inquiry_status($pdo);
            break;

        case 'delete_inquiry':
            handle_delete_inquiry($pdo);
            break;

        case 'get_inquiry':
            handle_get_inquiry($pdo);
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
 * 处理回复咨询
 */
function handle_reply_inquiry($pdo)
{
    try {
        $inquiry_id = intval($_POST['inquiry_id'] ?? 0);
        $reply_content = trim($_POST['reply_content'] ?? '');
        $notify_user = isset($_POST['notify_user']) ? 1 : 0;

        if (!$inquiry_id || !$reply_content) {
            throw new Exception('咨询ID和回复内容不能为空');
        }

        // 检查咨询是否存在
        $inquiry = $pdo->get_row("SELECT * FROM {$pdo->prefix}product_inquiries WHERE id = ?", [$inquiry_id]);

        if (!$inquiry) {
            throw new Exception('咨询不存在');
        }

        // 使用 Todo_Session_Manager 获取管理员用户ID
        $admin_user_id = Todo_Session_Manager::get_admin_user_id();
        if (!$admin_user_id) {
            throw new Exception('管理员会话无效');
        }

        // 插入回复记录
        $stmt = $pdo->prepare("INSERT INTO {$pdo->prefix}inquiry_replies (inquiry_id, admin_id, content, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$inquiry_id, $admin_user_id, $reply_content]);

        // 更新咨询状态
        $stmt = $pdo->prepare("UPDATE {$pdo->prefix}product_inquiries SET status = 'replied', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$inquiry_id]);

        // 发送通知邮件
        if ($notify_user || (isset($_POST['send_email']) && $_POST['send_email'] == '1')) {
            $template_slug = $_POST['template_slug'] ?? '';
            if (function_exists('todo_send_inquiry_reply_notification')) {
                todo_send_inquiry_reply_notification($inquiry_id, $reply_content, $template_slug);
            }
        }

        echo json_encode([
            'success' => true,
            'message' => '回复发送成功'
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
 * 处理添加咨询
 */
function handle_add_inquiry($pdo)
{
    try {
        $product_id = intval($_POST['product_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $status = $_POST['status'] ?? 'pending';

        if (!$name || !$email || !$content) {
            throw new Exception('姓名、邮箱和内容不能为空');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('邮箱格式不正确');
        }

        // 插入咨询
        $stmt = $pdo->prepare("
            INSERT INTO {$pdo->prefix}product_inquiries 
            (product_id, name, email, content, status, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $product_id,
            $name,
            $email,
            $content,
            $status,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);

        // 发送管理员通知
        if (function_exists('todo_send_admin_notification')) {
            $product_title = 'Unknown Product';
            if ($product_id > 0) {
                $product = $pdo->get_row("SELECT title FROM {$pdo->prefix}posts WHERE id = ?", [$product_id]);
                if ($product) {
                    $product_title = $product['title'] ?? 'Unknown Product';
                }
            }
            
            todo_send_admin_notification('product_inquiry', 'product_inquiry', [
                'name' => $name,
                'email' => $email,
                'content' => $content,
                'product_title' => $product_title,
                'product_id' => $product_id
            ]);
        }

        echo json_encode([
            'success' => true,
            'message' => '咨询添加成功'
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
 * 处理产品咨询状态更新
 */
function handle_update_inquiry_status($pdo)
{
    try {
        $inquiry_id = intval($_POST['inquiry_id'] ?? 0);
        $status = $_POST['status'] ?? '';

        if (!$inquiry_id || !$status) {
            throw new Exception('咨询ID和状态不能为空');
        }

        $allowed_statuses = ['pending', 'approved', 'replied', 'spam', 'trash'];
        if (!in_array($status, $allowed_statuses)) {
            throw new Exception('无效的状态值');
        }

        $stmt = $pdo->prepare("UPDATE {$pdo->prefix}product_inquiries SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $inquiry_id]);

        if ($stmt->rowCount() === 0) {
            throw new Exception('咨询不存在或状态未改变');
        }

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
 * 处理删除咨询
 */
function handle_delete_inquiry($pdo)
{
    try {
        $inquiry_id = intval($_POST['inquiry_id'] ?? 0);

        if (!$inquiry_id) {
            throw new Exception('咨询ID不能为空');
        }

        // 开始事务
        $pdo->beginTransaction();

        // 删除相关回复
        $stmt1 = $pdo->prepare("DELETE FROM {$pdo->prefix}inquiry_replies WHERE inquiry_id = ?");
        $stmt1->execute([$inquiry_id]);

        // 删除咨询
        $stmt = $pdo->prepare("DELETE FROM {$pdo->prefix}product_inquiries WHERE id = ?");
        $stmt->execute([$inquiry_id]);

        if ($stmt->rowCount() === 0) {
            throw new Exception('咨询不存在');
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => '咨询删除成功'
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * 获取咨询详情
 */
function handle_get_inquiry($pdo)
{
    try {
        $inquiry_id = intval($_POST['inquiry_id'] ?? 0);

        if (!$inquiry_id) {
            throw new Exception('咨询ID不能为空');
        }

        // 获取咨询信息
        $inquiry = $pdo->get_row("
            SELECT pi.*, p.title as product_title 
            FROM {$pdo->prefix}product_inquiries pi 
            LEFT JOIN {$pdo->prefix}posts p ON pi.product_id = p.id 
            WHERE pi.id = ?
        ", [$inquiry_id]);

        if (!$inquiry) {
            throw new Exception('咨询不存在');
        }

        // 获取回复列表
        $replies = $pdo->get_results("
            SELECT ir.*, COALESCE(NULLIF(a.display_name, ''), a.user_login) as admin_name 
            FROM {$pdo->prefix}inquiry_replies ir 
            LEFT JOIN {$pdo->prefix}users a ON ir.admin_id = a.ID 
            WHERE ir.inquiry_id = ? 
            ORDER BY ir.created_at ASC
        ", [$inquiry_id]);

        echo json_encode([
            'success' => true,
            'data' => [
                'inquiry' => $inquiry,
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
 * 发送回复通知邮件
 */
function send_reply_notification($inquiry, $reply_content)
{
    try {
        $to = $inquiry['email'];
        $subject = '您的产品咨询已收到回复';

        $message = "
        <html>
        <head>
            <title>产品咨询回复</title>
        </head>
        <body>
            <h2>您的产品咨询已收到回复</h2>
            <p>尊敬的 {$inquiry['name']}，</p>
            <p>您关于产品的咨询已收到我们的回复：</p>
            <div style='background-color: #f9f9f9; padding: 15px; border-left: 4px solid #0073aa; margin: 20px 0;'>
                <strong>您的咨询：</strong><br>
                " . nl2br(htmlspecialchars($inquiry['content'])) . "
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

        $result = function_exists('todo_mail') ? todo_mail($to, $subject, $message, $headers) : false;
        if (function_exists('todo_log_email')) {
            if ($result) {
                todo_log_email($to, $subject, 'sent');
            } else {
                todo_log_email($to, $subject, 'failed', 'Mail function returned false');
            }
        }

    } catch (Exception $e) {
        if (function_exists('todo_log_email')) {
            todo_log_email((string) ($inquiry['email'] ?? ''), '您的产品咨询已收到回复', 'failed', $e->getMessage());
        }
    }
}

?>
