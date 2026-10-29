<?php
/**
 * 媒体库AJAX处理
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 禁用错误显示，防止HTML格式错误输出
ini_set('display_errors', 0);
error_reporting(0);

// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

// 定义 AJAX 请求常量
if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}
if (!defined('IS_ADMIN')) {
    define('IS_ADMIN', true);
}

// 检查是否为AJAX请求
if (
    !isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'
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

// 确保使用系统配置的时区（AJAX 请求中显式设置）
if (function_exists('get_option')) {
    $timezone = get_option('timezone_string', 'Asia/Shanghai');
    if (!empty($timezone)) {
        @date_default_timezone_set($timezone);
    }
}

// 初始化数据库连接
global $cms_db, $tododb;
if (!$cms_db) {
    $cms_db = new CMS_DB();
}
$tododb = $cms_db;

require_once ABSPATH . 'admin/includes/todo-functions.php';
require_once ABSPATH . 'core/functions/formatting.php';
require_once ABSPATH . 'core/functions/media-functions-extended.php';
require_once ABSPATH . 'core/functions/url-functions.php';
require_once ABSPATH . 'core/functions/post.php';

// 检查用户权限
if (!admin_logged_in()) {
    http_response_code(403);
    echo json_encode(array('error' => '权限不足'));
    exit;
}

// 检查请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(array('error' => '方法不允许'));
    exit;
}

// 注意：GET请求通常不需要CSRF验证（幂等操作）
// 此接口已有登录验证（admin_logged_in()），足以保护敏感数据
// 如需额外安全，可在前端传递CSRF token并在后端验证

// 检查action参数
if (!isset($_GET['action']) || $_GET['action'] !== 'get_media') {
    http_response_code(400);
    echo json_encode(array('error' => '无效的操作'));
    exit;
}

// 获取参数
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = isset($_GET['per_page']) ? max(1, min(100, intval($_GET['per_page']))) : 20;
$search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
$type = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '';
$directory = isset($_GET['directory']) ? sanitize_text_field($_GET['directory']) : '';
$orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'date';
$order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'DESC';

// 映射orderby参数到数据库列名
if ($orderby === 'date') {
    $orderby = 'upload_time';
} elseif ($orderby === 'title') {
    $orderby = 'media_title';
} elseif ($orderby === 'name') {
    $orderby = 'media_filename';
}

// 构建查询参数
$site_scope_id = (isset($_GET['site']) && is_numeric($_GET['site']) && (int) $_GET['site'] > 0) ? (int) $_GET['site'] : null;
if ($site_scope_id === null) {
    $current_user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    $is_platform_admin = false;
    if ($current_user_id > 0 && function_exists('todo_is_super_admin') && todo_is_super_admin($current_user_id)) {
        $is_platform_admin = true;
    } elseif ($current_user_id > 0 && class_exists('CMS_User')) {
        $u = new CMS_User($current_user_id);
        if (is_object($u) && method_exists($u, 'has_role')) {
            $is_platform_admin = $u->has_role('administrator') || $u->has_role('admin');
        }
    }
    if (!$is_platform_admin && function_exists('todo_current_site_id') && todo_current_site_id() > 0) {
        $site_scope_id = (int) todo_current_site_id();
    }
}
$args = array(
    'number' => $per_page,
    'offset' => ($page - 1) * $per_page,
    'orderby' => $orderby,
    'order' => $order,
    'search' => $search,
    'site_id' => $site_scope_id
);


// 添加类型过滤
if (!empty($type)) {
    $args['mime_type'] = $type;
}

// 添加目录过滤
if (!empty($directory)) {
    $args['directory'] = $directory;
}

try {
    // 获取媒体列表
    $media_list = CMS_Media::get_media($args);
    $total_count = CMS_Media::get_media_count($args);

    // 处理媒体数据
    $media_data = array();
    foreach ($media_list as $media_item) {
        $media = new CMS_Media($media_item->media_id);
        $metadata = $media->get_metadata();

        $item = array(
            'id' => $media->get_id(),
            'title' => $media->get_title(),
            'description' => $media->get_description(),
            'url' => $media->get_url(),
            'thumbnail' => $media->get_url(),
            'type' => $media->get_mime_type(),
            'size' => todo_format_file_size($metadata['_filesize'] ?? 0),
            'date' => format_date($media_item->upload_time, 'Y-m-d H:i:s'),
            'dimensions' => todo_get_image_dimensions($metadata),
            'alt' => get_post_meta($media->get_id(), '_todo_attachment_image_alt', true),
            'seo_keywords' => $media->get_data('seo_keywords') ?: ''
        );

        $media_data[] = $item;
    }

    // 计算分页信息
    $total_pages = ceil($total_count / $per_page);
    $pagination = array(
        'current_page' => $page,
        'total_pages' => $total_pages,
        'total_items' => $total_count,
        'per_page' => $per_page,
        'has_prev' => $page > 1,
        'has_next' => $page < $total_pages
    );

    // 返回结果
    $response = array(
        'success' => true,
        'media' => $media_data,
        'pagination' => $pagination
    );

    header('Content-Type: application/json');
    echo json_encode($response);

} catch (Exception $e) {
    // 记录错误日志
    error_log('Media Library Error: ' . $e->getMessage());

    // 清除任何可能已输出的内容
    if (ob_get_length()) {
        ob_clean();
    }

    // 设置正确的Content-Type
    header('Content-Type: application/json');
    http_response_code(500);

    // 确保只输出JSON
    die(json_encode(array(
        'success' => false,
        'error' => '加载媒体库失败，请稍后再试'
    )));
}
