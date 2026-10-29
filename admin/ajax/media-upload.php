<?php
// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
/**
 * 媒体上传AJAX处理
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 定义必要常量
if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}
if (!defined('IS_ADMIN')) {
    define('IS_ADMIN', true);
}

if (
    !isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'
) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(array('error' => '禁止直接访问'));
    exit;
}

// 禁用错误显示，确保AJAX响应为纯JSON
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

// 设置错误处理函数，确保错误不会破坏JSON响应
set_error_handler(function ($severity, $message, $file, $line) {
    // 记录错误到日志而不是输出
    error_log("Media Upload Error: $message in $file on line $line");
    return true; // 阻止PHP默认错误处理
});

// 设置异常处理函数
set_exception_handler(function ($exception) {
    error_log("Media Upload Exception: " . $exception->getMessage());
    if (ob_get_level())
        ob_clean();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(array('error' => '服务器内部错误'));
    exit;
});

// 加载核心文件
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
}
require_once ABSPATH . 'core/bootstrap.php';

// 确保使用系统配置的时区（AJAX 请求中显式设置）
if (function_exists('get_option')) {
    $timezone = get_option('timezone_string', 'Asia/Shanghai');
    if (!empty($timezone)) {
        @date_default_timezone_set($timezone);
    }
}

// 定义TODO_URL常量（如果未定义）
if (!defined('TODO_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // 优先基于 DOCUMENT_ROOT 与 ABSPATH 计算安装前缀
    $doc_root = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'], '/')) : '';
    $abs_path = defined('ABSPATH') ? str_replace('\\', '/', rtrim(ABSPATH, '/')) : '';
    $prefix = '';
    if ($doc_root && $abs_path && strpos($abs_path, $doc_root) === 0) {
        $relative = substr($abs_path, strlen($doc_root));
        $relative = trim($relative, '/');
        if ($relative !== '') {
            $prefix = '/' . $relative;
        }
    } else {
        // 回退：从脚本路径剥离 /admin 及其子路径
        $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
        $base_path = rtrim(dirname($script_name), '/');
        $base_path = preg_replace('#/admin(/.*)?$#', '', $base_path);
        if ($base_path === '/' || $base_path === '\\') {
            $base_path = '';
        }
        $prefix = $base_path;
    }
    define('TODO_URL', $protocol . $host . $prefix);
}

if (!Todo_Security_Manager::verify_request_csrf('upload_media', '_todononce')) {
    if (ob_get_level())
        ob_clean();
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(array('error' => '安全验证失败，请重新提交'));
    exit;
}

// 检查请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (ob_get_level())
        ob_clean();
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(array('error' => '方法不允许'));
    exit;
}

// 检查action参数
if (!isset($_POST['action']) || $_POST['action'] !== 'upload_media') {
    if (ob_get_level())
        ob_clean();
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(array('error' => '无效的操作'));
    exit;
}

// 检查上传功能是否启用
$enable_upload = get_option('enable_upload', true);
if (!$enable_upload) {
    if (ob_get_level())
        ob_clean();
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(array('error' => '文件上传功能已被管理员禁用'));
    exit;
}

// 检查是否有文件上传
if (!isset($_FILES['files']) || empty($_FILES['files']['name'][0])) {
    if (ob_get_level())
        ob_clean();
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(array('error' => '没有文件上传'));
    exit;
}

$results = array();
$files = $_FILES['files'];

// 处理多文件上传
$file_count = (is_array($files['name']) && count($files['name']) > 0) ? count($files['name']) : 1;
for ($i = 0; $i < $file_count; $i++) {
    $file = array(
        'name' => $files['name'][$i],
        'type' => $files['type'][$i],
        'tmp_name' => $files['tmp_name'][$i],
        'error' => $files['error'][$i],
        'size' => $files['size'][$i]
    );

    $result = process_single_upload($file);
    $results[] = $result;
}

// 清理输出缓冲区，确保没有意外输出
if (ob_get_level()) {
    ob_clean();
}

// 返回结果
header('Content-Type: application/json');
echo json_encode($results);
exit;

/**
 * 处理单个文件上传
 *
 * @param array $file 文件信息
 * @return array 处理结果
 */
function process_single_upload($file)
{
    // 检查上传错误
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return array(
            'success' => false,
            'name' => $file['name'],
            'error' => get_upload_error_message($file['error'])
        );
    }

    // 检查文件大小
    $max_size = get_max_upload_size();
    if ((int) $max_size <= 0) {
        return array(
            'success' => false,
            'name' => $file['name'],
            'error' => '文件上传功能已被管理员禁用'
        );
    }
    if ($file['size'] > $max_size) {
        return array(
            'success' => false,
            'name' => $file['name'],
            'error' => '文件大小超过限制 (' . format_bytes($max_size) . ')'
        );
    }

    // 检查文件类型
    $allowed_types = get_allowed_file_types();
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($file_ext, $allowed_types)) {
        return array(
            'success' => false,
            'name' => $file['name'],
            'error' => '不支持的文件类型: ' . $file_ext
        );
    }

    // 检查MIME类型
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!is_allowed_mime_type($mime_type)) {
        return array(
            'success' => false,
            'name' => $file['name'],
            'error' => '不支持的MIME类型: ' . $mime_type
        );
    }

    try {
        // 获取分类ID（如果提供）
        $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : null;
        $upload_args = array(
            'generate_thumbnails' => false  // 默认只上传原图，不生成缩略图
        );
        if ($category_id) {
            $upload_args['category_id'] = $category_id;
        }

        // 使用CMS_Media类上传文件
        $media_id = CMS_Media::upload($file, $upload_args);

        if ($media_id) {
            $media = new CMS_Media($media_id);

            // 获取实际保存的文件名
            $saved_filename = $media->get_filename();
            $display_name = $file['name']; // 原始文件名用于显示

            return array(
                'success' => true,
                'id' => $media_id,
                'name' => $display_name,
                'filename' => $saved_filename,
                'original_name' => $file['name'],
                'url' => $media->get_url(),
                'type' => $mime_type,
                'size' => format_bytes($file['size']),
                'thumbnail' => $media->get_url()
            );
        } else {
            return array(
                'success' => false,
                'name' => $file['name'],
                'error' => '上传失败'
            );
        }
    } catch (Exception $e) {
        return array(
            'success' => false,
            'name' => $file['name'],
            'error' => $e->getMessage()
        );
    }
}

/**
 * 获取上传错误信息
 *
 * @param int $error_code 错误代码
 * @return string 错误信息
 */
function get_upload_error_message($error_code)
{
    switch ($error_code) {
        case UPLOAD_ERR_INI_SIZE:
            return '文件大小超过php.ini中upload_max_filesize的限制';
        case UPLOAD_ERR_FORM_SIZE:
            return '文件大小超过表单中MAX_FILE_SIZE的限制';
        case UPLOAD_ERR_PARTIAL:
            return '文件只有部分被上传';
        case UPLOAD_ERR_NO_FILE:
            return '没有文件被上传';
        case UPLOAD_ERR_NO_TMP_DIR:
            return '找不到临时文件夹';
        case UPLOAD_ERR_CANT_WRITE:
            return '文件写入失败';
        case UPLOAD_ERR_EXTENSION:
            return 'PHP扩展停止了文件上传';
        default:
            return '未知上传错误';
    }
}

/**
 * 获取最大上传文件大小
 *
 * @return int 字节数
 */
function get_max_upload_size()
{
    if (class_exists('TodoUploadConfig')) {
        return TodoUploadConfig::get_max_upload_size();
    }
    $app_limit = null;
    if (function_exists('get_option')) {
        $mb = get_option('max_upload_size', null);
        if ($mb !== null && is_numeric($mb) && (int) $mb > 0) {
            $app_limit = (int) $mb * 1024 * 1024;
        }
    }
    $max_upload = parse_size(ini_get('upload_max_filesize'));
    $max_post = parse_size(ini_get('post_max_size'));
    $server_limit = min($max_upload, $max_post);
    if ($app_limit !== null) {
        return min($server_limit, $app_limit);
    }
    return $server_limit;
}

/**
 * 解析大小字符串为字节数
 *
 * @param string $size 大小字符串 (如 "2M", "1G")
 * @return int 字节数
 */
function parse_size($size)
{
    $unit = preg_replace('/[^bkmgtpezy]/i', '', $size ?? '');
    $size = preg_replace('/[^0-9\.]/', '', $size ?? '');

    if ($unit) {
        return round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
    } else {
        return round($size);
    }
}

/**
 * 格式化字节数为可读格式
 *
 * @param int $bytes 字节数
 * @return string 格式化后的大小
 */
function format_bytes($bytes)
{
    return TodoUploadConfig::format_file_size($bytes);
}

/**
 * 获取允许的文件类型
 *
 * @return array 允许的文件扩展名
 */
function get_allowed_file_types()
{
    // 从设置中获取允许的文件类型
    $allowed_types_setting = get_option('allowed_file_types', 'jpg,jpeg,png,gif,webp,svg,bmp,ico,mp4,avi,mov,wmv,flv,webm,mkv,mp3,wav,ogg,aac,flac,m4a,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,rtf,zip,rar,7z,tar,gz,csv,xml,json');

    // 将逗号分隔的字符串转换为数组
    $allowed_types = array_map('trim', explode(',', $allowed_types_setting ?? ''));

    // 过滤空值并转换为小写
    $allowed_types = array_filter(array_map('strtolower', $allowed_types));

    return $allowed_types;
}

/**
 * 检查MIME类型是否允许
 *
 * @param string $mime_type MIME类型
 * @return bool 是否允许
 */
function is_allowed_mime_type($mime_type, $extension = '')
{
    return TodoUploadConfig::is_type_allowed($mime_type, $extension);
}
