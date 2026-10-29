<?php
/**
 * 删除未记录文件处理器
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}

// 检查是否为AJAX请求
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Direct access denied']);
    exit;
}

// 启动会话
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 加载核心文件（bootstrap 会加载配置和必要的类）
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
}
require_once ABSPATH . 'config.php';
require_once ABSPATH . 'core/bootstrap.php';

// 初始化全局数据库连接
if (!isset($tododb)) {
    $tododb = new CMS_DB();
}

// 设置JSON响应头
header('Content-Type: application/json');

try {
    // 验证用户权限
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
        throw new Exception('权限不足');
    }

    $admin_user_id = Todo_Session_Manager::get_admin_user_id();
    if (!$admin_user_id && function_exists('get_current_user_id')) {
        $admin_user_id = (int) get_current_user_id();
    }
    $admin_user = ($admin_user_id && class_exists('CMS_User')) ? new CMS_User((int) $admin_user_id) : null;
    $is_platform_admin = false;
    if ($admin_user_id && function_exists('todo_is_super_admin') && todo_is_super_admin((int) $admin_user_id)) {
        $is_platform_admin = true;
    } elseif (is_object($admin_user) && method_exists($admin_user, 'has_role')) {
        $is_platform_admin = $admin_user->has_role('administrator') || $admin_user->has_role('admin');
    }
    if (!$is_platform_admin) {
        throw new Exception('权限不足');
    }
    
    // 验证请求方法
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('无效的请求方法');
    }
    
    // 验证CSRF令牌（统一核心CSRF验证）
    if (!Todo_Security_Manager::verify_request_csrf('default', '_todononce')) {
        throw new Exception('CSRF验证失败');
    }
    
    // 获取要删除的文件列表
    $files_json = $_POST['files'] ?? '';
    if (empty($files_json)) {
        throw new Exception('未指定要删除的文件');
    }
    
    $files = json_decode($files_json, true);
    if (!is_array($files) || empty($files)) {
        throw new Exception('文件列表格式错误');
    }
    
    $deleted_count = 0;
    $errors = [];

    // 获取上传目录根路径（与未记录文件列表保持一致）
    $upload_info = todo_upload_dir(false);
    $upload_dir = rtrim($upload_info['basedir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR; // 如 /path/to/site/content/uploads/
    $real_upload_dir = rtrim(realpath($upload_dir), DIRECTORY_SEPARATOR);

    foreach ($files as $file) {
        if (!isset($file['path']) || empty($file['path'])) {
            $errors[] = '文件路径无效: ' . ($file['name'] ?? '未知文件');
            continue;
        }

        $raw_path = str_replace('\\', '/', $file['path']);
        // 如果路径中包含 content/uploads/，剥离 content/uploads/ 及其前面所有部分
        if (($pos = strpos($raw_path, 'content/uploads/')) !== false) {
            $relative_path = substr($raw_path, $pos + strlen('content/uploads/'));
        } else {
            $relative_path = ltrim($raw_path, '/');
        }

        // 标准化相对路径并做基本穿越防护
        if (strpos($relative_path, '..') !== false) {
            $errors[] = '文件路径不安全: ' . ($file['name'] ?? basename($relative_path));
            continue;
        }

        // 组合为绝对路径
        $abs_path = $upload_dir . str_replace('/', DIRECTORY_SEPARATOR, $relative_path);

        // 安全检查：确保目标目录在上传目录内
        $real_target_dir = realpath(dirname($abs_path));
        if (!$real_upload_dir || !$real_target_dir || strpos($real_target_dir, $real_upload_dir) !== 0) {
            $errors[] = '文件路径不安全: ' . ($file['name'] ?? basename($relative_path));
            continue;
        }

        // 检查文件是否存在
        if (!file_exists($abs_path)) {
            $errors[] = '文件不存在: ' . ($file['name'] ?? basename($relative_path));
            continue;
        }

        // 检查是否为文件（不是目录）
        if (!is_file($abs_path)) {
            $errors[] = '不是有效文件: ' . ($file['name'] ?? basename($relative_path));
            continue;
        }

        // 删除文件
        if (@unlink($abs_path)) {
            $deleted_count++;
        } else {
            $errors[] = '删除失败: ' . ($file['name'] ?? basename($relative_path));
        }
    }
    
    // 返回结果（若无任何文件被删除，则视为失败，便于前端提示）
    $success = ($deleted_count > 0);
    $response = [
        'success' => $success,
        'deleted_count' => $deleted_count,
        'total_count' => count($files)
    ];

    if (!empty($errors)) {
        $response['errors'] = $errors;
    }

    if ($deleted_count > 0 && empty($errors)) {
        $response['message'] = "成功删除了 {$deleted_count} 个文件";
    } elseif ($deleted_count > 0 && !empty($errors)) {
        $response['message'] = "已删除 {$deleted_count} 个文件，另有 " . count($errors) . " 个文件删除失败";
    } else {
        // 未删除任何文件
        $response['message'] = '未删除任何文件，可能文件不存在或权限不足';
    }

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
