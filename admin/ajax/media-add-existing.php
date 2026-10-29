<?php
/**
 * 添加现有文件到媒体库
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}

// 检查直接访问保护
if (
    !defined('DOING_AJAX') || !DOING_AJAX ||
    !isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'
) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Direct access denied']);
    exit;
}

// 加载 Composer autoloader

// 启动会话
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 加载核心文件
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
    echo json_encode(['error' => 'Insufficient permissions']);
    exit;
}

// 验证CSRF令牌（从请求读取 _csrf_token，校验动作 media-library）
if (!todo_verify_csrf_token($_POST['_csrf_token'] ?? '', 'media-library')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

// 处理添加现有文件请求
if ($_POST['action'] === 'add_existing_file') {
    handle_add_existing_file();
}

/**
 * 处理添加现有文件到媒体库
 */
function handle_add_existing_file()
{
    global $tododb;

    $file_path = sanitize_text_field($_POST['file_path'] ?? '');

    if (empty($file_path)) {
        todo_send_json_error('文件路径不能为空');
        return;
    }

    // 规范化路径到上传目录相对路径
    $relative_path = ltrim($file_path, '/');
    $uploads_prefix = 'content/uploads/';
    if (strpos($relative_path, $uploads_prefix) === 0) {
        $relative_path = substr($relative_path, strlen($uploads_prefix));
    }
    $upload_dir = todo_upload_dir(false);

    // 检查文件是否存在
    // 构造完整磁盘路径（保证在上传目录内）
    $full_path = rtrim($upload_dir['basedir'], '/') . '/' . $relative_path;
    if (!file_exists($full_path)) {
        todo_send_json_error('文件不存在');
        return;
    }

    // 检查文件是否已在媒体库中
    $existing_media = $tododb->get_row(
        "SELECT media_id FROM " . TABLE_PREFIX . "media WHERE media_path = ?",
        [$relative_path],
        'ARRAY_A'
    );

    if ($existing_media) {
        todo_send_json_error('文件已存在于媒体库中');
        return;
    }

    // 获取文件信息
    $file_info = pathinfo($full_path);
    $file_size = filesize($full_path);
    $mime_type = todo_get_mime_type($full_path);

    $thumbhash_code = '';
    if (strpos($mime_type, 'image/') === 0 && class_exists('ThumbHash')) {
        $thumbhash_code = ThumbHash::fromImageFile($full_path);
    }

    // 准备插入数据
    $media_data = [
        'media_title' => $file_info['filename'],
        'media_filename' => basename($relative_path),
        'media_path' => $relative_path,
        'media_url' => function_exists('todo_media_url_to_relative') ? todo_media_url_to_relative(rtrim($upload_dir['baseurl'], '/') . '/' . $relative_path) : (rtrim($upload_dir['baseurl'], '/') . '/' . $relative_path),
        'media_type' => $mime_type,
        'media_size' => $file_size,
        'upload_user' => get_current_user_id(),
        'upload_time' => current_time('mysql'),
        'media_status' => 'active',
        'thumbhash' => $thumbhash_code,
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql')
    ];

    // 插入媒体记录
    $result = $tododb->insert(TABLE_PREFIX . 'media', $media_data);

    if ($result === false) {
        todo_send_json_error('添加文件到媒体库失败');
        return;
    }

    $media_id = $tododb->insert_id();

    // 添加媒体元数据
    $metadata = [
        'file' => $file_path,
        'filesize' => $file_size,
        'width' => 0,
        'height' => 0
    ];

    // 如果是图片，获取尺寸信息
    if (strpos($mime_type, 'image/') === 0) {
        $image_info = getimagesize($full_path);
        if ($image_info) {
            $metadata['width'] = $image_info[0];
            $metadata['height'] = $image_info[1];
        }
    }

    // 更新媒体元数据
    if (!empty($metadata)) {
        $update_data = [];
        if (isset($metadata['width']))
            $update_data['media_width'] = $metadata['width'];
        if (isset($metadata['height']))
            $update_data['media_height'] = $metadata['height'];

        if (!empty($update_data)) {
            $tododb->update(TABLE_PREFIX . 'media', $update_data, ['media_id' => $media_id]);
        }
    }

    todo_send_json_success([
        'message' => '文件已成功添加到媒体库',
        'media_id' => $media_id,
        'file_path' => $relative_path
    ]);
}

/**
 * 获取文件MIME类型
 */
function todo_get_mime_type($file_path)
{
    // 优先按统一清单基于扩展名判断
    $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    $mime_map = function_exists('todo_supported_mime_types_flat') ? todo_supported_mime_types_flat() : [];
    if ($extension && isset($mime_map[$extension])) {
        return $mime_map[$extension];
    }
    // 其次使用 finfo 检测真实MIME
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $file_path);
            finfo_close($finfo);
            if ($mime)
                return $mime;
        }
    }
    // 图片文件尝试使用 getimagesize
    if (function_exists('getimagesize')) {
        $info = @getimagesize($file_path);
        if ($info && isset($info['mime'])) {
            return $info['mime'];
        }
    }
    return 'application/octet-stream';
}

/**
 * 发送JSON成功响应
 */
function todo_send_json_success($data = null)
{
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'data' => $data
    ]);
    exit;
}

/**
 * 发送JSON错误响应
 */
function todo_send_json_error($message = 'Unknown error')
{
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $message
    ]);
    exit;
}
?>
