<?php
/**
 * 非媒体库文件AJAX处理
 * 重构版本：仅查询未被媒体库记录的文件
 *
 * @package TodoCMS
 * @version 1.1.0
 */

// 禁用错误显示，防止HTML格式错误输出
ini_set('display_errors', 0);
error_reporting(0);

// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

// 加载 Composer autoloader

// 定义 AJAX 请求常量
if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}
if (!defined('IS_ADMIN')) {
    define('IS_ADMIN', true);
}

// 检查是否为AJAX请求（但允许被其他文件require）
$is_required = (count(get_included_files()) > 1);
if (
    !$is_required && (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest')
) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(array('error' => 'Direct access not permitted'));
    exit;
}

// 启动会话
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 加载配置文件
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
}
require_once ABSPATH . 'config.php';
require_once ABSPATH . 'core/bootstrap.php';

// 初始化数据库连接
global $cms_db, $tododb;
if (!isset($tododb) && class_exists('CMS_DB')) {
    $tododb = CMS_DB::get_instance();
    $cms_db = $tododb;
}

// 加载核心函数
require_once ABSPATH . 'core/functions/load_functions.php';

// 设置时区与目录内容一致
if (function_exists('date_default_timezone_set')) {
    // 优先使用站点设置的时区，其次使用 Asia/Shanghai
    $timezone = function_exists('get_option') ? get_option('timezone_string', 'Asia/Shanghai') : 'Asia/Shanghai';
    if ($timezone) {
        date_default_timezone_set($timezone);
    }
}

// 加载admin函数文件
require_once ABSPATH . 'admin/includes/todo-functions.php';

// 初始化安全管理器
Todo_Security_Manager::init();

// 检查用户权限
if (!admin_logged_in()) {
    if (!$is_required) {
        http_response_code(403);
    }
    echo json_encode(array('success' => false, 'message' => '权限不足'));
    exit;
}

// 设置JSON响应头
if (!$is_required) {
    header('Content-Type: application/json');
}

// 检查请求方法，只接受POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (!$is_required) {
        http_response_code(405);
    }
    echo json_encode(array('success' => false, 'message' => '只允许POST请求'));
    exit;
}

// 验证CSRF令牌
if (!Todo_Security_Manager::verify_request_csrf('get_unlinked_files', '_csrf_token')) {
    if (!$is_required) {
        http_response_code(403);
    }
    echo json_encode(array('success' => false, 'message' => 'CSRF验证失败'));
    exit;
}
// 获取参数
$directory = isset($_POST['directory']) ? sanitize_text_field($_POST['directory']) : '';
$search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
$page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
$per_page = isset($_POST['per_page']) ? max(1, min(500, intval($_POST['per_page']))) : 20;

// 获取上传URL基础（支持子目录安装，不自动创建目录）
$upload_info = todo_upload_dir(false);
$baseurl = rtrim($upload_info['baseurl'], '/');

$uploads_root = rtrim(ABSPATH, '/') . '/content/uploads/';
$requested_site_id = (isset($_GET['site']) && is_numeric($_GET['site']) && (int) $_GET['site'] > 0) ? (int) $_GET['site'] : 0;
$current_user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
$is_platform_admin = false;
if ($current_user_id > 0 && class_exists('Todo_Session_Manager') && method_exists('Todo_Session_Manager', 'can_access_admin')) {
    $is_platform_admin = (bool) Todo_Session_Manager::can_access_admin($current_user_id);
}
if ($requested_site_id <= 0 && !$is_platform_admin && function_exists('todo_current_site_id') && todo_current_site_id() > 0) {
    $requested_site_id = (int) todo_current_site_id();
}

$scan_base_dir = $uploads_root;
if ($requested_site_id > 0) {
    $scan_base_dir = $uploads_root . 'sites' . $requested_site_id . '/';
    if (!is_dir($scan_base_dir)) {
        @mkdir($scan_base_dir, 0755, true);
    }
}

$target_dir = $scan_base_dir;
if ($directory) {
    $directory = ltrim($directory, '/');
    // 基础安全检查：禁止 .. 遍历
    if (strpos($directory, '..') !== false) {
        echo json_encode(array('success' => false, 'message' => '无效的目录路径'));
        exit;
    }

    $site_prefix = 'sites' . $requested_site_id . '/';
    // 平台管理员拥有全量访问权限，直接基于 uploads_root 拼接
    if ($is_platform_admin) {
        $target_dir = rtrim($uploads_root, '/') . '/' . $directory;
    } 
    // 普通管理员，但路径已经包含了站点前缀，说明是完整的相对路径
    elseif ($requested_site_id > 0 && strpos($directory, $site_prefix) === 0) {
        $target_dir = rtrim($uploads_root, '/') . '/' . $directory;
    } 
    // 普通管理员的常规路径，基于站点根目录拼接
    else {
        $target_dir = rtrim($scan_base_dir, '/') . '/' . $directory;
    }
}

// 安全检查：确保目录在允许范围内。平台管理员允许访问整个 uploads 目录，普通管理员仅限站点目录
$security_base_dir = $is_platform_admin ? $uploads_root : $scan_base_dir;
$real_security_base = realpath($security_base_dir);

// 如果物理目录不存在，直接返回空列表 (这是正常情况，比如选了一个还没有物理目录的日期)
if (!is_dir($target_dir)) {
    // 基础安全检查：禁止 .. 遍历
    if (strpos($directory, '..') !== false) {
        echo json_encode(array('success' => false, 'message' => '无效的目录路径'));
        exit;
    }
    echo json_encode(array(
        'success' => true,
        'files' => array(),
        'pagination' => array(
            'current_page' => $page,
            'per_page' => $per_page,
            'total' => 0,
            'total_pages' => 0
        )
    ));
    exit;
}

$real_target_dir = realpath($target_dir);

if (!$real_target_dir || !$real_security_base || strpos($real_target_dir, $real_security_base) !== 0) {
    echo json_encode(array('success_count' => 0, 'error_count' => 1, 'success' => false, 'message' => '无效的目录路径'));
    exit;
}

// === 性能优化：批量收集，避免逐文件DB查询和mime检测 ===

// 第一步：扫描文件系统，收集文件基本信息（无DB查询，无mime检测）
$file_entries = array(); // [real_path, relative_path, name, size, mtime]
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($target_dir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($iterator as $file) {
    if (!$file->isFile()) continue;
    if (strtolower($file->getExtension()) === 'php') continue;

    $relative_path = str_replace($uploads_root, '', $file->getPathname());
    $relative_path = str_replace('\\', '/', $relative_path);

    // 搜索过滤提前执行，减少后续处理量
    if ($search && stripos($file->getFilename(), $search) === false) {
        continue;
    }

    $file_entries[] = array(
        'real_path'    => $file->getPathname(),
        'relative_path'=> $relative_path,
        'name'         => $file->getFilename(),
        'size'         => $file->getSize(),
        'mtime'        => $file->getMTime(),
    );
}

// 第二步：批量构建媒体库路径查找表（1次DB查询替代 N 次查询）
$media_lookup = array();
if (!empty($file_entries)) {
    $site_id_for_lookup = ($requested_site_id > 0) ? $requested_site_id : 0;
    $media_rows = $tododb->get_results(
        "SELECT media_path, media_url FROM {$tododb->prefix}media WHERE site_id = ?",
        array($site_id_for_lookup)
    );
    if (!empty($media_rows)) {
        foreach ($media_rows as $row) {
            // 规范化 media_path
            $mp = ltrim((string)$row->media_path, '/');
            if ($mp !== '') {
                $media_lookup[$mp] = true;
                $media_lookup['/' . $mp] = true;
            }
            // 规范化 media_url
            $mu = ltrim((string)$row->media_url, '/');
            if ($mu !== '') {
                $media_lookup[$mu] = true;
                $media_lookup['/' . $mu] = true;
            }
        }
    }
}

// 规范化辅助：去掉站点前缀
$site_prefix = '';
$site_id_norm = ($requested_site_id > 0) ? $requested_site_id : 0;
if ($site_id_norm > 0) {
    $site_prefix = 'sites' . $site_id_norm . '/';
}

if (!function_exists('_unlink_normalize_path')) {
    function _unlink_normalize_path($path, $site_prefix) {
        $norm = ltrim($path, '/');
        if ($site_prefix !== '' && strpos($norm, $site_prefix) === 0) {
            $norm = substr($norm, strlen($site_prefix));
        }
        return $norm;
    }
}

// 第三步：过滤已入库文件（纯内存查找，无DB调用）
$unmatched_files = array();
foreach ($file_entries as $entry) {
    $norm = _unlink_normalize_path($entry['relative_path'], $site_prefix);
    
    // 检查路径和URL两种候选
    $in_library = isset($media_lookup[$norm]) || isset($media_lookup['/' . $norm]);
    
    // 额外检查：URL形式
    if (!$in_library && function_exists('todo_media_url_to_relative')) {
        $abs_url = $baseurl . '/' . ltrim($entry['relative_path'], '/');
        $rel_url = todo_media_url_to_relative($abs_url);
        if ($rel_url !== '') {
            $url_norm = _unlink_normalize_path($rel_url, $site_prefix);
            $in_library = isset($media_lookup[$url_norm]) || isset($media_lookup['/' . $url_norm]);
        }
    }
    
    if (!$in_library) {
        $site_rel_path = '/content/uploads/' . ltrim($entry['relative_path'], '/');
        $unmatched_files[] = array(
            'name'     => $entry['name'],
            'path'     => (function_exists('todo_media_url_to_relative') ? todo_media_url_to_relative($site_rel_path) : $site_rel_path),
            'url'      => (function_exists('todo_media_url_to_absolute') ? todo_media_url_to_absolute($site_rel_path) : $site_rel_path),
            'size'     => format_file_size($entry['size']),
            'modified' => date('Y-m-d H:i:s', $entry['mtime']),
            'date'     => date('Y-m-d H:i:s', $entry['mtime']),
            // mime type 延后到分页后再计算
            '_real_path' => $entry['real_path'],
        );
    }
}

// 第四步：排序（按文件名升序，与目录浏览一致）
usort($unmatched_files, function ($a, $b) {
    return strcmp($a['name'], $b['name']);
});

// 第五步：分页（先分页，再计算 mime type，最多只算20个）
$total = count($unmatched_files);
$offset = ($page - 1) * $per_page;
$files = array_slice($unmatched_files, $offset, $per_page);

// 第六步：仅为当前页文件计算 mime type（最多 per_page 次）
foreach ($files as &$f) {
    $f['type'] = get_file_mime_type($f['_real_path']);
    unset($f['_real_path']); // 清理临时字段
}
unset($f);

echo json_encode(array(
    'success' => true,
    'files' => $files,
    'pagination' => array(
        'current_page' => $page,
        'per_page' => $per_page,
        'total' => $total,
        'total_pages' => ceil($total / $per_page)
    )
));
