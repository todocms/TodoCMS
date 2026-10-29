<?php
/**
 * 目录内容AJAX处理
 * 获取指定目录下的文件列表
 */

// 开启输出缓冲并禁用错误显示，确保AJAX响应为纯JSON
if (ob_get_level() === 0) {
    ob_start();
}
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

// 引入必要文件
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
}
require_once ABSPATH . 'config.php';
require_once ABSPATH . 'core/bootstrap.php';
require_once ABSPATH . 'admin/includes/todo-functions.php';
require_once ABSPATH . 'core/functions/formatting.php';
require_once ABSPATH . 'core/functions/media-functions-extended.php';
require_once ABSPATH . 'core/functions/url-functions.php';

// 确保使用系统配置的时区（AJAX 请求中显式设置）
if (function_exists('get_option')) {
    $timezone = get_option('timezone_string', 'Asia/Shanghai');
    if (!empty($timezone)) {
        @date_default_timezone_set($timezone);
    }
}

// 启动会话
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 检查管理员权限
if (!admin_logged_in()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '权限不足']);
    exit;
}

// CSRF验证 - 从POST数据中获取令牌
$csrf_token = $_POST['_todononce'] ?? '';

if (empty($csrf_token) || !Todo_Security_Manager::verify_csrf_token($csrf_token, 'directory_content')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
    exit;
}



/**
 * 获取文件类型（简单分类）
 */
if (!function_exists('get_file_type')) {
    function get_file_type($filename)
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $image_types = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico', 'cur', 'avif', 'heic', 'tif', 'tiff'];
        $video_types = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'webm'];
        $audio_types = ['mp3', 'wav', 'ogg', 'aac', 'flac'];
        $document_types = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf'];

        if (in_array($ext, $image_types)) {
            return 'image';
        } elseif (in_array($ext, $video_types)) {
            return 'video';
        } elseif (in_array($ext, $audio_types)) {
            return 'audio';
        } elseif (in_array($ext, $document_types)) {
            return 'document';
        } else {
            return 'application';
        }
    }
}

/**
 * 获取文件的MIME类型
 * 优先使用文件内容检测，其次使用扩展名推断
 */
if (!function_exists('get_file_mime_type')) {
    function get_file_mime_type($file_path)
    {
        // 首先尝试使用finfo检测文件内容
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime_type = @finfo_file($finfo, $file_path);
                finfo_close($finfo);
                if ($mime_type && $mime_type !== 'application/octet-stream') {
                    return $mime_type;
                }
            }
        }

        // 如果finfo检测失败或返回通用类型，使用扩展名推断
        $filename = basename($file_path);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // 使用统一的MIME类型清单
        $mime_types = function_exists('todo_supported_mime_types_flat') ? todo_supported_mime_types_flat() : array();

        if (isset($mime_types[$ext])) {
            return $mime_types[$ext];
        }

        // 对于没有扩展名的文件，尝试检测是否为图片
        if (empty($ext) && function_exists('getimagesize')) {
            $image_info = @getimagesize($file_path);
            if ($image_info && isset($image_info['mime'])) {
                return $image_info['mime'];
            }
        }

        // 默认返回通用二进制类型
        return 'application/octet-stream';
    }
}

/**
 * 获取目录下的文件列表，支持搜索和过滤
 * 
 * @param string $relative_path 相对路径
 * @param string $search 搜索关键词
 * @param string $type_filter 文件类型过滤
 * @param int $page 页码
 * @param int $per_page 每页数量
 * @return array 文件列表和分页信息
 */
function get_directory_files($relative_path = '', $search = '', $type_filter = '', $page = 1, $per_page = 20)
{
    $base_path = ABSPATH . 'content/uploads';
    $base_url = function_exists('todo_home_url') ? rtrim(todo_home_url('content/uploads'), '/') : '/content/uploads';
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
        $base_path .= '/sites' . $site_id;
        $base_url .= '/sites' . $site_id;
        if (!is_dir($base_path)) {
            @mkdir($base_path, 0755, true);
        }
    }

    if ($relative_path) {
        $full_path = $base_path . '/' . ltrim($relative_path, '/');
    } else {
        $full_path = $base_path;
    }

    // 安全检查：确保路径在上传目录内
    $real_base = realpath($base_path);
    $real_path = realpath($full_path);

    if (!$real_path || strpos($real_path, $real_base) !== 0) {
        throw new Exception('无效的路径');
    }

    if (!is_dir($real_path)) {
        throw new Exception('目录不存在');
    }

    $all_files = [];
    $items = scandir($real_path);

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $item_utf8 = function_exists('todo_ensure_utf8') ? todo_ensure_utf8($item) : $item;

        // 过滤系统文件和隐藏文件
        if (
            $item_utf8 === '.DS_Store' || $item_utf8 === 'Thumbs.db' || $item_utf8 === '.htaccess' ||
            $item_utf8 === '.gitignore' || $item_utf8 === '.git' || $item_utf8 === '.svn' || $item_utf8 === '.idea' ||
            substr($item_utf8, 0, 1) === '.' || substr($item_utf8, -1) === '~' ||
            substr($item_utf8, -4) === '.tmp' || substr($item_utf8, -5) === '.temp'
        ) {
            continue;
        }

        $item_path = $real_path . '/' . $item;

        // 只处理文件，不处理子目录
        if (is_file($item_path)) {
            // 过滤后端脚本文件，避免在目录标签页中显示
            $ext = strtolower(pathinfo($item_utf8, PATHINFO_EXTENSION));
            if ($ext === 'php') {
                continue;
            }
            $sub_file_path = $relative_path ? $relative_path . '/' . $item_utf8 : $item_utf8;
            $site_rel_path = '/content/uploads/' . ltrim($sub_file_path, '/');
            $file_path = function_exists('todo_media_url_to_relative') ? todo_media_url_to_relative($site_rel_path) : $site_rel_path;
            $file_url = function_exists('todo_media_url_to_absolute') ? todo_media_url_to_absolute($site_rel_path) : $site_rel_path;
            $file_type = get_file_type($item_utf8);

            // 应用搜索过滤
            if ($search && stripos($item_utf8, $search) === false) {
                continue;
            }

            // 应用类型过滤
            if ($type_filter) {
                if ($type_filter === 'document' || $type_filter === 'application') {
                    if ($file_type !== 'document' && $file_type !== 'application') {
                        continue;
                    }
                } elseif ($file_type !== $type_filter) {
                    continue;
                }
            }

            // 获取实际的MIME类型
            $mime_type = get_file_mime_type($item_path);
            $fsize = @filesize($item_path) ?: 0;
            $fmtime = @filemtime($item_path) ?: time();

            $all_files[] = [
                'id' => md5($site_rel_path), // 生成唯一ID
                'name' => $item_utf8,
                'path' => $file_path,
                'url' => $file_url,
                'size' => todo_format_file_size($fsize),
                'type' => $mime_type,
                'date' => date('Y-m-d H:i:s', $fmtime)
            ];
        }
    }

    // 按文件名排序
    usort($all_files, function ($a, $b) {
        return strcmp($a['name'], $b['name']);
    });

    // 计算分页
    $total_files = count($all_files);
    $total_pages = ceil($total_files / $per_page);
    $offset = ($page - 1) * $per_page;

    // 获取当前页的文件
    $paged_files = array_slice($all_files, $offset, $per_page);

    // 返回文件列表和分页信息
    return [
        'files' => $paged_files,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $total_pages,
            'total_files' => $total_files,
            'per_page' => $per_page
        ]
    ];
}

// 处理AJAX请求
if (isset($_POST['action']) && $_POST['action'] === 'get_directory_content') {
    $path = isset($_POST['path']) ? sanitize_text_field($_POST['path']) : '';
    $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    $type_filter = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
    $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
    $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 20;

    try {
        $result = get_directory_files($path, $search, $type_filter, $page, $per_page);
        todo_send_json_success($result);
    } catch (Exception $e) {
        todo_send_json_error($e->getMessage());
    }
}
