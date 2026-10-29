<?php
/**
 * 清理空文件夹处理器
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 定义AJAX常量
if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}

if (
    !isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'
) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Direct access denied']);
    exit;
}

// 启动会话
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 加载 Composer autoloader

// 加载核心文件（bootstrap.php会处理常量定义）
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
}
require_once ABSPATH . 'config.php';
require_once ABSPATH . 'core/bootstrap.php';

// 初始化全局数据库连接
if (!isset($tododb)) {
    $tododb = new CMS_DB();
}

// 检查用户权限
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
    header('Content-Type: application/json');
    echo json_encode(['error' => '权限不足']);
    exit;
}

$admin_user_id = null;
if (class_exists('Todo_Session_Manager')) {
    $admin_user_id = Todo_Session_Manager::get_admin_user_id();
}
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
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => '权限不足']);
    exit;
}

// 验证CSRF令牌
if (!Todo_Security_Manager::verify_request_csrf('default', '_todononce')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'CSRF验证失败']);
    exit;
}

/**
 * 递归删除空文件夹
 * 
 * @param string $dir 目录路径
 * @return array 删除的文件夹列表
 */
function clean_empty_folders($dir)
{
    $deleted_folders = [];

    if (!is_dir($dir)) {
        return $deleted_folders;
    }

    $items = scandir($dir);
    if ($items === false) {
        return $deleted_folders;
    }

    // 过滤掉 . 和 .. 以及系统文件
    $items = array_diff($items, ['.', '..', '.DS_Store', 'Thumbs.db', '.gitkeep']);

    foreach ($items as $item) {
        $path = $dir . DIRECTORY_SEPARATOR . $item;

        if (is_dir($path)) {
            // 递归处理子目录
            $sub_deleted = clean_empty_folders($path);
            $deleted_folders = array_merge($deleted_folders, $sub_deleted);

            // 检查子目录是否为空
            $sub_items = scandir($path);
            if ($sub_items !== false) {
                $sub_items = array_diff($sub_items, ['.', '..', '.DS_Store', 'Thumbs.db', '.gitkeep']);
                if (empty($sub_items)) {
                    // 删除系统文件
                    $system_files = ['.DS_Store', 'Thumbs.db', '.gitkeep'];
                    foreach ($system_files as $sys_file) {
                        $sys_path = $path . DIRECTORY_SEPARATOR . $sys_file;
                        if (file_exists($sys_path)) {
                            @unlink($sys_path);
                        }
                    }

                    if (rmdir($path)) {
                        $deleted_folders[] = $path;
                    }
                }
            }
        }
    }

    return $deleted_folders;
}

try {
    // 获取上传目录基础路径
    $upload_info = todo_upload_dir(false);
    $upload_dir = $upload_info['basedir']; // 使用basedir获取uploads根目录

    if (!is_dir($upload_dir)) {
        throw new Exception('上传目录不存在');
    }

    // 清理空文件夹
    $deleted_folders = clean_empty_folders($upload_dir);

    // 记录操作日志
    if (!empty($deleted_folders)) {
        $log_data = [
            'folders_count' => count($deleted_folders),
            'folders' => $deleted_folders
        ];

        log_media_action('clean_folders', 'folder', 0, 'success', $log_data);
    }

    // 返回成功响应
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => '清理完成',
        'deleted_count' => count($deleted_folders),
        'deleted_folders' => $deleted_folders
    ]);

} catch (Exception $e) {
    // 记录错误日志
    log_media_action('clean_folders', 'folder', 0, 'error', ['error' => $e->getMessage()]);

    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => '清理失败: ' . $e->getMessage()
    ]);
}

/**
 * 记录媒体操作日志
 */
function log_media_action($action, $object_type, $object_id, $result, $extra_data = null)
{
    try {
        global $tododb;
        $db = $tododb;

        // 检查表是否存在
        $table_name = $db->prefix . 'activity_log';
        $table_exists = $db->query(
            "SHOW TABLES LIKE '{$table_name}'"
        );

        if (empty($table_exists)) {
            return; // 表不存在，跳过日志记录
        }

        $user_id = get_current_user_id();
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $log_data = [
            'user_id' => $user_id,
            'action' => $action,
            'object_type' => $object_type,
            'object_id' => $object_id,
            'result' => $result,
            'ip_address' => $ip_address,
            'user_agent' => $user_agent,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $db->insert($db->prefix . 'activity_log', $log_data);

    } catch (Exception $e) {
        // 静默处理日志错误，不影响主要功能
        error_log('Activity log error: ' . $e->getMessage());
    }
}
?>
