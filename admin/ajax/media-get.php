<?php
// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

// 禁用错误显示，确保AJAX响应为纯JSON
ini_set('display_errors', 0);
error_reporting(0);

/**
 * 获取媒体信息的AJAX处理
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

// 加载核心文件
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
}
require_once ABSPATH . 'config.php';
require_once ABSPATH . 'core/bootstrap.php';
require_once ABSPATH . 'core/functions/formatting.php';
require_once ABSPATH . 'core/functions/media-functions-extended.php';
require_once ABSPATH . 'core/functions/url-functions.php';

// 设置响应头
header('Content-Type: application/json');

// 检查请求方法 - 支持 GET 和 POST
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'])) {
    http_response_code(405);
    echo json_encode(array('error' => '不支持的请求方法'));
    exit;
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

// CSRF验证（仅对POST请求）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Todo_Security_Manager::verify_request_csrf('get_media', '_get_nonce')) {
        http_response_code(403);
        echo json_encode(array('error' => '安全验证失败，请重新提交'));
        exit;
    }
}

// 根据请求方法获取参数
$request_data = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

// 检查必要参数 - GET 请求可以直接通过 media_id 参数
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($request_data['action']) || $request_data['action'] !== 'get_media') {
        http_response_code(400);
        echo json_encode(array('error' => '无效的操作'));
        exit;
    }
}

if (!isset($request_data['media_id']) || !is_numeric($request_data['media_id'])) {
    http_response_code(400);
    echo json_encode(array('error' => '无效的媒体ID'));
    exit;
}

$media_id = intval($request_data['media_id']);

try {
    // 获取媒体信息
    $media = new CMS_Media($media_id);

    if (!$media->exists()) {
        http_response_code(404);
        echo json_encode(array('error' => '媒体文件不存在'));
        exit;
    }

    // 获取媒体详细信息
    $metadata = $media->get_metadata();
    $file_path = $media->get_file_path();
    $file_url = $media->get_url();
    $mime_type = $media->get_mime_type();

    // 获取附加信息
    $post_data = $media->get_data();
    $alt_text = isset($post_data->media_alt) ? $post_data->media_alt : '';
    $caption = isset($post_data->media_caption) ? $post_data->media_caption : '';

    // 如果没有从数据库获取到alt_text，尝试从metadata获取
    if (empty($alt_text) && isset($metadata['_todo_attachment_image_alt']) && is_array($metadata['_todo_attachment_image_alt']) && !empty($metadata['_todo_attachment_image_alt'])) {
        $alt_text = $metadata['_todo_attachment_image_alt'][0];
    }
    $upload_date = isset($post_data->upload_time) ? $post_data->upload_time : '';

    // 获取云端存储提供商标记
    $provider = null;
    if (class_exists('Todo_Media_Manager')) {
        $provider = Todo_Media_Manager::get_media_meta($media_id, 'storage_provider');
    }
    $is_cloud = ($provider && $provider !== 'local');

    // 获取文件大小与缺失状态
    $db_size = (int) $media->get_data('media_size');
    $file_size = 'N/A';
    $file_missing = false;
    $storage_info = '本地存储';

    if ($db_size > 0) {
        $file_size = todo_format_file_size($db_size);
    }

    if (file_exists($file_path)) {
        if ($db_size <= 0) {
            $file_size = todo_format_file_size(filesize($file_path));
        }
        $file_missing = false;
        if ($is_cloud) {
            $storage_info = '已云端同步';
        }
    } elseif ($is_cloud) {
        $file_missing = false;
        $storage_info = '云端存储';
    } else {
        // 本地存储模式：尝试搜索可能位置
        $file_missing = true;
        $upload_dir = todo_upload_dir(false);
        $filename = basename($file_path);
        $possible_paths = array();

        $current_year = date('Y');
        $current_month = date('m');
        $possible_paths[] = $upload_dir['basedir'] . '/' . $current_year . '/' . $current_month . '/' . $filename;

        for ($year = $current_year - 1; $year <= $current_year; $year++) {
            for ($month = 1; $month <= 12; $month++) {
                $month_str = sprintf('%02d', $month);
                $possible_paths[] = $upload_dir['basedir'] . '/' . $year . '/' . $month_str . '/' . $filename;
            }
        }

        foreach ($possible_paths as $possible_path) {
            if (file_exists($possible_path)) {
                $file_size = todo_format_file_size(filesize($possible_path));
                $file_missing = false;
                break;
            }
        }

        if ($file_missing) {
            $file_size = '文件丢失';
            $storage_info = '本地物理文件已丢失';
        }
    }

    // 获取图片尺寸
    $width = null;
    $height = null;
    if (isset($metadata['_todo_attachment_metadata']) && is_array($metadata['_todo_attachment_metadata']) && !empty($metadata['_todo_attachment_metadata']) && isset($metadata['_todo_attachment_metadata'][0])) {
        $attachment_metadata = maybe_unserialize($metadata['_todo_attachment_metadata'][0]);
        if (is_array($attachment_metadata)) {
            if (isset($attachment_metadata['width'])) {
                $width = $attachment_metadata['width'];
            }
            if (isset($attachment_metadata['height'])) {
                $height = $attachment_metadata['height'];
            }
        }
    }
    if ($width === null || $height === null) {
        $w_db = intval($media->get_data('media_width'));
        $h_db = intval($media->get_data('media_height'));
        if ($w_db > 0) $width = $w_db;
        if ($h_db > 0) $height = $h_db;
    }

    // 获取内容引用信息 (使用 CMS_Media 统一的引用查询逻辑)
    $details = CMS_Media::get_all_references($media);
    $content_references = $details['references'];

    // 准备响应数据
    $response = array(
        'success' => true,
        'media' => array(
            'id' => $media->get_id(),
            'title' => $media->get_title(),
            'description' => $media->get_description(),
            'alt' => $alt_text,
            'caption' => $caption,
            'file_name' => basename($file_path),
            'file_path' => $file_path,
            'file_url' => $file_url,
            'mime_type' => $mime_type,
            'file_size' => $file_size,
            'file_missing' => $file_missing,
            'storage_info' => $storage_info,
            'storage_provider' => $provider ?: 'local',
            'upload_date' => $upload_date,
            'dimensions' => array(
                'width' => $width,
                'height' => $height
            ),
            'is_image' => strpos($mime_type, 'image/') === 0,
            'copyright' => '',
            'license' => '',
            'featured' => false,
            'seo_title' => $media->get_data('seo_title') ?: '',
            'seo_description' => $media->get_data('seo_description') ?: '',
            'seo_keywords' => $media->get_data('seo_keywords') ?: '',
            'content_references' => $content_references
        )
    );

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('error' => '获取媒体信息失败: ' . $e->getMessage()));
}
