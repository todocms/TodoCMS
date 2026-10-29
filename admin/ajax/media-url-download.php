<?php
// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

// 禁用错误显示，确保AJAX响应为纯JSON
ini_set('display_errors', 0);
error_reporting(0);

/**
 * 媒体URL下载AJAX处理
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 定义AJAX常量
if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}
if (!defined('IS_ADMIN')) {
    define('IS_ADMIN', true);
}

if (!defined('DOING_AJAX') || !isset($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(array('error' => '禁止直接访问'));
    exit;
}

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
require_once ABSPATH . 'admin/includes/file.php';

// 确保使用系统配置的时区（AJAX 请求中显式设置）
if (function_exists('get_option')) {
    $timezone = get_option('timezone_string', 'Asia/Shanghai');
    if (!empty($timezone)) {
        @date_default_timezone_set($timezone);
    }
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
    echo json_encode(array('error' => '权限不足'));
    exit;
}

// CSRF验证
if (!Todo_Security_Manager::verify_request_csrf('url_download', '_todononce')) {
    http_response_code(403);
    echo json_encode(array('error' => 'CSRF验证失败'));
    exit;
}

// 检查请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('error' => '方法不允许'));
    exit;
}

// 检查action参数
if (!isset($_POST['action']) || $_POST['action'] !== 'download_url_image') {
    http_response_code(400);
    echo json_encode(array('error' => '无效的操作'));
    exit;
}

// 检查上传功能是否启用（与文件上传开关一致）
$enable_upload = get_option('enable_upload', true);
if (!$enable_upload) {
    http_response_code(403);
    echo json_encode(array('error' => '文件上传功能已被管理员禁用'));
    exit;
}

// 检查URL参数
if (trim(todo_post_string('url')) === '') {
    http_response_code(400);
    echo json_encode(array('error' => '缺少URL参数'));
    exit;
}

$url = trim(todo_post_string('url'));
$title = trim(todo_post_string('title'));

// 解析本地/云端存储相对路径逻辑：识别 /content/uploads/*** 相对路径、本站域名 或 配置的云存储域名 + /content/uploads/***
$is_recognized_storage = false;
$storage_relative_path = '';

// 获取本站域名和根 URL 信息
$site_url = function_exists('todo_get_site_url') ? todo_get_site_url() : home_url();
$site_host = parse_url($site_url, PHP_URL_HOST);
$input_path = parse_url($url, PHP_URL_PATH);
$input_host = parse_url($url, PHP_URL_HOST);

// 动态从系统配置中提取所有存储服务商配置的域名 (storage_%_domain)
$cloud_hosts = array();
if (class_exists('CMS_Option')) {
    $storage_options = CMS_Option::get_by_prefix('storage_');
    foreach ($storage_options as $opt_key => $opt_val) {
        if (substr($opt_key, -7) === '_domain' && !empty($opt_val) && is_string($opt_val)) {
            $chost = parse_url(strpos($opt_val, 'http') === 0 ? $opt_val : 'http://' . $opt_val, PHP_URL_HOST);
            if (!empty($chost)) {
                $cloud_hosts[] = strtolower($chost);
            }
        }
    }
}

// 1. 检查输入是否包含 content/uploads/ 存储路径（支持子目录部署如 /todocms/content/uploads/、主域名或云存储域名）
$uploads_pos = strpos($url, 'content/uploads/');
if ($uploads_pos !== false) {
    if (!empty($input_host)) {
        $input_host_lower = strtolower($input_host);
        if ((!empty($site_host) && strcasecmp($input_host, $site_host) === 0) || in_array($input_host_lower, $cloud_hosts, true) || $input_host_lower === 'localhost' || $input_host_lower === '127.0.0.1') {
            $is_recognized_storage = true;
            $storage_relative_path = substr($url, $uploads_pos + strlen('content/uploads/'));
        }
    } else {
        // 本地相对路径 (如 /todocms/content/uploads/... 或 /content/uploads/...)
        $is_recognized_storage = true;
        $storage_relative_path = substr($url, $uploads_pos + strlen('content/uploads/'));
    }
}


if ($is_recognized_storage) {
    // 清理 URL 参数和多余斜杠
    $storage_relative_path = ltrim(parse_url($storage_relative_path, PHP_URL_PATH), '/');
    $upload_dir = todo_upload_dir(false);
    $full_path = rtrim($upload_dir['basedir'], '/') . '/' . $storage_relative_path;
    $local_file_exists = file_exists($full_path);

    global $tododb;
    if (!isset($tododb)) {
        $tododb = new CMS_DB();
    }

    // 规范化相对路径变体
    $clean_rel_path = ltrim($storage_relative_path, '/');
    $slash_rel_path = '/' . $clean_rel_path;
    $content_rel_path = 'content/uploads/' . $clean_rel_path;
    $slash_content_rel_path = '/content/uploads/' . $clean_rel_path;

    // 检查数据库媒体表中是否已记录该文件 (匹配各种存储格式的 media_path 及 media_url)
    $existing_media = $tododb->get_row(
        "SELECT * FROM " . TABLE_PREFIX . "media WHERE media_path IN (?, ?, ?, ?) OR media_url LIKE ? LIMIT 1",
        [$clean_rel_path, $slash_rel_path, $content_rel_path, $slash_content_rel_path, '%' . $clean_rel_path]
    );

    if ($existing_media) {
        $media_id = $existing_media->media_id;
        $media = new CMS_Media($media_id);
        $file_size = $existing_media->media_size ?: ($local_file_exists ? filesize($full_path) : 0);
        $mime_type = $existing_media->media_type;
        if (empty($mime_type)) {
            if ($local_file_exists && function_exists('get_mime_type')) {
                $mime_type = get_mime_type($full_path);
            } else {
                $ext = strtolower(pathinfo($storage_relative_path, PATHINFO_EXTENSION));
                $mime_type = function_exists('todo_supported_mime_types_flat') ? (todo_supported_mime_types_flat()[$ext] ?? 'image/jpeg') : 'image/jpeg';
            }
        }
        $actual_filename = basename($storage_relative_path);

        echo json_encode(array(
            'success' => true,
            'id' => $media_id,
            'title' => $media->get_title(),
            'url' => $media->get_url() ?: $url,
            'type' => $mime_type,
            'size' => TodoUploadConfig::format_file_size($file_size),
            'thumbnail' => $media->get_url() ?: $url,
            'upload_date' => $existing_media->upload_time ?: current_time('mysql'),
            'filename' => $actual_filename,
            'is_existing' => true
        ));
        exit;
    }

    // 若数据库未记录且本地文件也不存在，则无法获取文件元数据（除非能访问该 URL 确认或当作现存对象导入）
    // 判断如果是云端对象或本地有文件，进行登记
    $file_info = pathinfo($storage_relative_path);
    $extension = isset($file_info['extension']) ? strtolower($file_info['extension']) : '';
    if ($local_file_exists && function_exists('get_mime_type')) {
        $mime_type = get_mime_type($full_path);
    } else {
        $mime_map = function_exists('todo_supported_mime_types_flat') ? todo_supported_mime_types_flat() : [];
        $mime_type = $mime_map[$extension] ?? 'image/jpeg';
    }
    $file_size = $local_file_exists ? filesize($full_path) : 0;

    $thumbhash_code = '';
    if ($local_file_exists && strpos($mime_type, 'image/') === 0 && class_exists('ThumbHash')) {
        $thumbhash_code = ThumbHash::fromImageFile($full_path);
    }

    // 默认媒体 URL
    $media_url_val = function_exists('todo_media_url_to_relative') ? todo_media_url_to_relative(rtrim($upload_dir['baseurl'], '/') . '/' . $storage_relative_path) : (rtrim($upload_dir['baseurl'], '/') . '/' . $storage_relative_path);
    // 如果 URL 来自第三方云存储域名，直接保存或解析
    if (!empty($input_host) && in_array(strtolower($input_host), $cloud_hosts, true)) {
        $media_url_val = $url;
    }

    $media_title = (!empty($title) && $title !== '未命名图片') ? $title : ($file_info['filename'] ?: '未命名图片');

    $media_data = array(
        'media_title' => $media_title,
        'media_filename' => basename($storage_relative_path),
        'media_path' => $storage_relative_path,
        'media_url' => $media_url_val,
        'media_type' => $mime_type,
        'media_size' => $file_size,
        'upload_user' => get_current_user_id(),
        'upload_time' => current_time('mysql'),
        'media_status' => 'active',
        'thumbhash' => $thumbhash_code,
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql')
    );

    if ($local_file_exists && strpos($mime_type, 'image/') === 0) {
        $image_info = getimagesize($full_path);
        if ($image_info) {
            $media_data['media_width'] = $image_info[0];
            $media_data['media_height'] = $image_info[1];
        }
    }

    $result = $tododb->insert(TABLE_PREFIX . 'media', $media_data);
    if ($result !== false) {
        $media_id = $tododb->insert_id();
        $media = new CMS_Media($media_id);

        // 只有当输入的域名明确属于第三方云存储域名时，才记录对应的云端 provider；本地路径/本地域名显式登记为 local
        if (class_exists('Todo_Media_Manager')) {
            $is_cloud_input = (!empty($input_host) && in_array(strtolower($input_host), $cloud_hosts, true));
            if ($is_cloud_input && class_exists('Todo_Storage_Manager')) {
                $storage_mgr = Todo_Storage_Manager::get_instance();
                if (!$storage_mgr->is_local()) {
                    Todo_Media_Manager::save_media_meta($media_id, 'storage_provider', $storage_mgr->get_provider_type());
                }
            } else {
                Todo_Media_Manager::save_media_meta($media_id, 'storage_provider', 'local');
            }
        }

        $actual_filename = basename($storage_relative_path);

        echo json_encode(array(
            'success' => true,
            'id' => $media_id,
            'title' => $media->get_title(),
            'url' => $media->get_url() ?: $url,
            'type' => $mime_type,
            'size' => TodoUploadConfig::format_file_size($file_size),
            'thumbnail' => $media->get_url() ?: $url,
            'upload_date' => current_time('mysql'),
            'filename' => $actual_filename,
            'is_existing' => true
        ));
        exit;
    } else {
        echo json_encode(array(
            'success' => false,
            'error' => '登记存储文件到媒体库失败'
        ));
        exit;
    }
}

// 验证URL格式 (针对远程网络URL)
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    // 如果是相对路径 (如 /todocms/... 或 ./... ) 且为图片/媒体后缀，当作本地资源路径
    $is_relative_path = (strpos($url, '/') === 0 || strpos($url, './') === 0);
    $path_ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    $valid_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico', 'mp4', 'mp3', 'avif'];

    if (!($is_relative_path && in_array($path_ext, $valid_exts, true))) {
        echo json_encode(array(
            'success' => false,
            'error' => '无效的URL格式'
        ));
        exit;
    }
}


// SSRF 安全检查：禁止访问内网IP和本地地址
if (!todo_is_public_url($url)) {
    echo json_encode(array(
        'success' => false,
        'error' => '受限的请求地址：禁止访问私有网络或本地资源。'
    ));
    exit;
}

// 检查是否为图片URL
if (!is_image_url($url)) {
    echo json_encode(array(
        'success' => false,
        'error' => 'URL不是有效的图片地址'
    ));
    exit;
}

try {
    // 下载图片到临时文件
    $temp_file = download_url($url, 30);

    if (is_todo_error($temp_file)) {
        echo json_encode(array(
            'success' => false,
            'error' => $temp_file->get_error_message()
        ));
        exit;
    }

    // 获取文件信息
    $file_info = pathinfo(parse_url($url, PHP_URL_PATH));
    $extension = isset($file_info['extension']) ? strtolower($file_info['extension']) : 'jpg';

    // 校验扩展名是否允许（使用设置中的 allowed_file_types）
    $allowed_exts_option = get_option('allowed_file_types', 'jpg,jpeg,png,gif,webp,bmp,svg');
    $allowed_exts = array_filter(array_map('trim', explode(',', strtolower($allowed_exts_option))));
    if ($extension && !in_array($extension, $allowed_exts, true)) {
        // 清理临时文件
        if (file_exists($temp_file)) {
            unlink($temp_file);
        }
        echo json_encode(array(
            'success' => false,
            'error' => '该URL文件类型不被允许: ' . $extension
        ));
        exit;
    }

    // 如果有自定义标题，使用标题作为文件名
    if (!empty($title)) {
        // 清理标题，移除扩展名后缀
        $clean_title = sanitize_file_name($title);
        // 移除标题中的扩展名
        $title_info = pathinfo($clean_title);
        if (isset($title_info['extension'])) {
            // 如果标题包含扩展名，移除它，只保留文件名部分
            $filename_without_ext = $title_info['filename'];
        } else {
            // 如果标题没有扩展名，直接使用
            $filename_without_ext = $clean_title;
        }
        // 重新添加正确的扩展名用于文件保存
        $filename = $filename_without_ext . '.' . $extension;
    } else {
        // 没有自定义标题时，从URL获取文件名
        $filename = !empty($file_info['basename']) ? $file_info['basename'] : 'downloaded_image.' . $extension;
    }

    // 检查文件类型
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $temp_file);
    finfo_close($finfo);

    // 使用统一上传配置进行MIME校验（支持别名与前缀匹配）
    if (!TodoUploadConfig::is_type_allowed($mime_type)) {
        unlink($temp_file);
        echo json_encode(array(
            'success' => false,
            'error' => '不支持的文件类型: ' . $mime_type
        ));
        exit;
    }

    // 检查文件大小（统一用上传配置最大值）
    $file_size = filesize($temp_file);
    $max_size = TodoUploadConfig::get_max_upload_size();
    if ($file_size > $max_size) {
        unlink($temp_file);
        echo json_encode(array(
            'success' => false,
            'error' => '文件大小超过限制 (最大' . TodoUploadConfig::format_file_size($max_size) . ')，当前文件大小: ' . TodoUploadConfig::format_file_size($file_size)
        ));
        exit;
    }

    // 构造文件数组
    $file = array(
        'name' => $filename,
        'type' => $mime_type,
        'tmp_name' => $temp_file,
        'error' => UPLOAD_ERR_OK,
        'size' => $file_size
    );

    // 使用CMS_Media类上传文件
    $upload_args = array(
        'generate_thumbnails' => false  // 默认只上传原图，不生成缩略图
    );
    $media_id = CMS_Media::upload($file, $upload_args);

    // 清理临时文件
    if (file_exists($temp_file)) {
        unlink($temp_file);
    }

    if ($media_id) {
        $media = new CMS_Media($media_id);

        // 如果有自定义标题，更新媒体标题（不包含扩展名）
        if (!empty($title)) {
            $media->update(array('media_title' => $filename_without_ext));
        }

        // 从URL中提取实际文件名（包含扩展名）
        $actual_filename = basename($media->get_url());

        echo json_encode(array(
            'success' => true,
            'id' => $media_id,
            'title' => $media->get_title(),
            'url' => $media->get_url(),
            'type' => $mime_type,
            'size' => TodoUploadConfig::format_file_size($file_size),
            'thumbnail' => $media->get_url(),
            'upload_date' => $media->get_data('upload_time'),
            'filename' => $actual_filename
        ));
    } else {
        echo json_encode(array(
            'success' => false,
            'error' => '保存文件失败'
        ));
    }

} catch (Exception $e) {
    // 清理临时文件
    if (isset($temp_file) && file_exists($temp_file)) {
        unlink($temp_file);
    }

    echo json_encode(array(
        'success' => false,
        'error' => '处理失败: ' . $e->getMessage()
    ));
}

/**
 * 检查URL是否为图片
 *
 * @param string $url URL地址
 * @return bool
 */
function is_image_url($url)
{
    // 检查URL扩展名
    $path = parse_url($url, PHP_URL_PATH);
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $image_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg');

    if (in_array($extension, $image_extensions)) {
        return true;
    }

    // 如果没有扩展名，尝试获取Content-Type
    // 设置 User-Agent 以避免被某些服务器拒绝
    $context = stream_context_create(array(
        'http' => array(
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36\r\n"
        )
    ));
    
    $headers = @get_headers($url, 1, $context);
    if ($headers && isset($headers['Content-Type'])) {
        $content_type = is_array($headers['Content-Type']) ? $headers['Content-Type'][0] : $headers['Content-Type'];
        return strpos($content_type, 'image/') === 0;
    }

    return false;
}

/**
 * 格式化字节大小
 *
 * @param int $bytes 字节数
 * @return string 格式化后的大小
 */
function format_bytes($bytes)
{
    return TodoUploadConfig::format_file_size($bytes);
}
