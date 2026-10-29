<?php
/**
 * 定时发布任务 HTTP 端点
 * 这个端点用于在 exec() 被禁用时触发定时发布任务
 * 
 * @package TodoCMS
 */

// 防止直接访问
if (!defined('TODO_ROOT')) {
    define('TODO_ROOT', dirname(__DIR__));
}

// 加载核心文件
require_once TODO_ROOT . '/core/bootstrap.php';

// 验证请求令牌
function verify_scheduled_posts_token($token)
{
    $secret_key = defined('TODO_SECRET_KEY') ? TODO_SECRET_KEY : 'todo-default-key';
    $expected_token = hash_hmac('sha256', 'scheduled_posts_' . date('Y-m-d-H'), $secret_key);
    
    return hash_equals($expected_token, $token);
}

// 只接受 POST 请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

// 验证令牌
$token = isset($_POST['token']) ? $_POST['token'] : '';
if (!verify_scheduled_posts_token($token)) {
    http_response_code(403);
    exit(json_encode(['error' => 'Invalid token']));
}

// 立即返回响应，避免超时
header('Content-Type: application/json');
header('Connection: close');
header('Content-Length: ' . ob_get_length());
ob_end_flush();
flush();

// 如果支持，关闭连接后继续执行
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// 执行定时发布任务
try {
    if (function_exists('todo_run_scheduled_posts')) {
        todo_run_scheduled_posts();
        
        // 记录任务执行
        if (function_exists('todo_log_info')) {
            todo_log_info('Scheduled posts task executed via HTTP endpoint');
        }
    }
} catch (Exception $e) {
    if (function_exists('todo_log_error')) {
        todo_log_error('Scheduled posts task failed: ' . $e->getMessage());
    }
}

exit(json_encode(['success' => true]));
