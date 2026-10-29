<?php
/**
 * 目录树 AJAX 处理
 */

// 禁用错误显示，确保AJAX响应为纯JSON
ini_set('display_errors', 0);
error_reporting(0);

// 加载 Composer autoloader

// 定义必要常量
if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}
if (!defined('IS_ADMIN')) {
    define('IS_ADMIN', true);
}

// 直接访问保护
if (
    !isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'
) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Direct access not permitted']);
    exit;
}

// 启动会话
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 包含必要文件
try {
    if (!defined('ABSPATH')) {
        define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
    }
    require_once ABSPATH . 'config.php';
    require_once ABSPATH . 'core/bootstrap.php';
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '系统文件加载失败']);
    exit;
}

// 权限检查
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
    echo json_encode(['success' => false, 'message' => '未授权访问']);
    exit;
}

// CSRF验证 - 从JSON请求体中获取令牌
$input = json_decode(file_get_contents('php://input'), true);
$csrf_token = $input['_todononce'] ?? '';

if (empty($csrf_token) || !Todo_Security_Manager::verify_csrf_token($csrf_token, 'directory_tree')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
    exit;
}

// 获取请求数据
$action = $input['action'] ?? '';

header('Content-Type: application/json');

try {
    switch ($action) {
        case 'get_directory_tree':
            $path = $input['path'] ?? '';
            $count_mode = $input['count_mode'] ?? 'shallow';
            $result = get_directory_tree($path, $count_mode);
            echo json_encode($result);
            break;

        default:
            echo json_encode(['success' => false, 'message' => '无效的操作']);
            break;
    }
} catch (Exception $e) {
    error_log('Directory tree error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '服务器错误']);
}

/**
 * 获取目录树
 *
 * @param string $path 相对于uploads目录的路径
 * @param string $count_mode 文件数量统计模式：shallow|recursive|none
 * @return array
 */
function get_directory_tree($path = '', $count_mode = 'shallow')
{
    $uploads_dir = ABSPATH . 'content/uploads';
    $site_id = (isset($_GET['site']) && is_numeric($_GET['site']) && (int) $_GET['site'] > 0) ? (int) $_GET['site'] : 0;
    $current_user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    $is_platform_admin = false;
    if ($current_user_id > 0 && class_exists('Todo_Session_Manager') && method_exists('Todo_Session_Manager', 'can_access_admin')) {
        $is_platform_admin = (bool) Todo_Session_Manager::can_access_admin($current_user_id);
    }
    if ($current_user_id > 0 && function_exists('todo_is_super_admin') && todo_is_super_admin($current_user_id)) {
        $is_platform_admin = true;
    } elseif ($current_user_id > 0 && class_exists('CMS_User')) {
        $u = new CMS_User($current_user_id);
        if (is_object($u) && method_exists($u, 'has_role')) {
            $is_platform_admin = $u->has_role('administrator') || $u->has_role('admin');
        }
    }
    if ($site_id <= 0 && !$is_platform_admin && function_exists('todo_current_site_id') && todo_current_site_id() > 0) {
        $site_id = (int) todo_current_site_id();
    }
    $is_super = function_exists('todo_is_super_admin') ? (bool) todo_is_super_admin() : false;
    if (!$is_super && $site_id > 0) {
        $uploads_dir .= '/sites' . $site_id;
        if (!is_dir($uploads_dir)) {
            @mkdir($uploads_dir, 0755, true);
        }
    }
    $path = ltrim((string) $path, '/');
    $path = preg_replace('#^(?:[^\/]+\/)?content\/uploads\/?#i', '', $path);

    if ($path !== '') {
        $target_dir = $uploads_dir . '/' . $path;
    } else {
        $target_dir = $uploads_dir;
    }

    // 安全检查：确保路径在uploads目录内
    $real_uploads_raw = realpath($uploads_dir);
    $real_target_raw = realpath($target_dir);

    if (!$real_uploads_raw || !$real_target_raw) {
        return ['success' => false, 'message' => '目录不存在或无法读取'];
    }

    $real_uploads = rtrim(str_replace('\\', '/', $real_uploads_raw), '/');
    $real_target = rtrim(str_replace('\\', '/', $real_target_raw), '/');

    if ($real_target !== $real_uploads && strpos($real_target, $real_uploads . '/') !== 0) {
        return ['success' => false, 'message' => '无效的路径'];
    }

    if (!is_dir($target_dir)) {
        return ['success' => false, 'message' => '目录不存在'];
    }

    $directories = [];

    try {
        $items = scandir($target_dir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === '.htaccess') {
                continue;
            }

            // 过滤系统文件和隐藏文件
            if (
                $item === '.DS_Store' || $item === 'Thumbs.db' || $item === '.gitignore' ||
                $item === '.git' || $item === '.svn' || $item === '.idea' ||
                substr($item, 0, 1) === '.' || substr($item, -1) === '~' ||
                substr($item, -4) === '.tmp' || substr($item, -5) === '.temp'
            ) {
                continue;
            }

            $item_path = $target_dir . '/' . $item;

            if (is_dir($item_path)) {
                $file_count = 0;
                if ($count_mode === 'recursive') {
                    $file_count = count_files_in_directory_recursive($item_path);
                } elseif ($count_mode === 'shallow') {
                    $file_count = count_files_in_directory_shallow($item_path);
                }

                // 检查是否有子目录
                $has_children = false;
                $sub_items = @scandir($item_path);
                if ($sub_items) {
                    foreach ($sub_items as $sub_item) {
                        if ($sub_item === '.' || $sub_item === '..' || $sub_item === '.htaccess') continue;
                        if ($sub_item === '.DS_Store' || $sub_item === 'Thumbs.db' || $sub_item === '.gitignore') continue;
                        if ($sub_item === '.git' || $sub_item === '.svn' || $sub_item === '.idea') continue;
                        if (substr($sub_item, 0, 1) === '.' || substr($sub_item, -1) === '~') continue;
                        if (substr($sub_item, -4) === '.tmp' || substr($sub_item, -5) === '.temp') continue;
                        if (is_dir($item_path . '/' . $sub_item)) {
                            $has_children = true;
                            break;
                        }
                    }
                }

                $directories[] = [
                    'name' => $item,
                    'path' => $path ? $path . '/' . $item : $item,
                    'file_count' => $file_count,
                    'has_children' => $has_children,
                    'type' => 'directory'
                ];
            }
        }

        // 按名称排序
        usort($directories, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        return [
            'success' => true,
            'directories' => $directories,
            'current_path' => $path
        ];

    } catch (Exception $e) {
        return ['success' => false, 'message' => '读取目录失败: ' . $e->getMessage()];
    }
}

/**
 * 统计目录中的文件数量
 *
 * @param string $dir_path 目录路径
 * @return int
 */
function count_files_in_directory_shallow($dir_path)
{
    $count = 0;

    try {
        $media_extensions = [
            'jpg' => true, 'jpeg' => true, 'png' => true, 'gif' => true, 'webp' => true, 'bmp' => true, 'svg' => true, 'ico' => true,
            'mp4' => true, 'avi' => true, 'mov' => true, 'wmv' => true, 'flv' => true, 'webm' => true,
            'mp3' => true, 'wav' => true, 'ogg' => true, 'aac' => true, 'flac' => true,
            'pdf' => true, 'doc' => true, 'docx' => true, 'xls' => true, 'xlsx' => true, 'ppt' => true, 'pptx' => true, 'txt' => true, 'rtf' => true,
            'zip' => true, 'rar' => true, 'tar' => true, 'gz' => true, '7z' => true, 'sql' => true
        ];

        $iterator = new FilesystemIterator($dir_path, FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $name = $fileInfo->getFilename();
            if ($name === '.htaccess' || $name === '.DS_Store' || $name === 'Thumbs.db' || $name === '.gitignore') {
                continue;
            }
            if (substr($name, 0, 1) === '.' || substr($name, -1) === '~' || substr($name, -4) === '.tmp' || substr($name, -5) === '.temp') {
                continue;
            }

            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($extension !== '' && isset($media_extensions[$extension])) {
                $count++;
            }
        }
    } catch (Exception $e) {
        // 如果无法读取目录，返回0
        return 0;
    }

    return $count;
}

function count_files_in_directory_recursive($dir_path)
{
    $count = 0;

    try {
        $media_extensions = [
            'jpg' => true, 'jpeg' => true, 'png' => true, 'gif' => true, 'webp' => true, 'bmp' => true, 'svg' => true, 'ico' => true,
            'mp4' => true, 'avi' => true, 'mov' => true, 'wmv' => true, 'flv' => true, 'webm' => true,
            'mp3' => true, 'wav' => true, 'ogg' => true, 'aac' => true, 'flac' => true,
            'pdf' => true, 'doc' => true, 'docx' => true, 'xls' => true, 'xlsx' => true, 'ppt' => true, 'pptx' => true, 'txt' => true, 'rtf' => true,
            'zip' => true, 'rar' => true, 'tar' => true, 'gz' => true, '7z' => true, 'sql' => true
        ];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $name = $fileInfo->getFilename();
            if ($name === '.htaccess' || $name === '.DS_Store' || $name === 'Thumbs.db' || $name === '.gitignore') {
                continue;
            }
            if (substr($name, 0, 1) === '.' || substr($name, -1) === '~' || substr($name, -4) === '.tmp' || substr($name, -5) === '.temp') {
                continue;
            }

            $extension = strtolower($fileInfo->getExtension());
            if ($extension !== '' && isset($media_extensions[$extension])) {
                $count++;
            }
        }
    } catch (Exception $e) {
        return 0;
    }

    return $count;
}
