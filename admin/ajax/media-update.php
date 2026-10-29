<?php
// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

// 禁用错误显示，确保AJAX响应为纯JSON
ini_set('display_errors', 0);
error_reporting(0);

/**
 * 更新媒体信息的AJAX处理
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

// 启动会话（在加载核心文件之前，确保会话可用）
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 加载核心文件
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
}
require_once ABSPATH . 'config.php';
require_once ABSPATH . 'core/bootstrap.php';

// 初始化全局数据库连接
if (!isset($tododb)) {
    $tododb = new CMS_DB();
}

// 设置响应头
header('Content-Type: application/json');

// 检查请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

// CSRF验证
if (!Todo_Security_Manager::verify_request_csrf('update_media', '_update_nonce')) {
    http_response_code(403);
    echo json_encode(array('error' => '安全验证失败，请重新提交'));
    exit;
}

// 检查必要参数
if (!isset($_POST['action']) || $_POST['action'] !== 'update_media') {
    http_response_code(400);
    echo json_encode(array('error' => '无效的操作'));
    exit;
}

if (!isset($_POST['media_id']) || !is_numeric($_POST['media_id'])) {
    http_response_code(400);
    echo json_encode(array('error' => '无效的媒体ID'));
    exit;
}

$media_id = intval($_POST['media_id']);

try {
    // 获取媒体对象
    $media = new CMS_Media($media_id);

    if (!$media->exists()) {
        http_response_code(404);
        echo json_encode(array('error' => '媒体文件不存在'));
        exit;
    }

    // 清理和验证输入数据
    $title = isset($_POST['title']) ? trim(strip_tags($_POST['title'] ?? '')) : '';
    $description = isset($_POST['description']) ? trim($_POST['description'] ?? '') : '';
    $alt_text = isset($_POST['alt_text']) ? trim(strip_tags($_POST['alt_text'] ?? '')) : '';
    $caption = isset($_POST['caption']) ? trim($_POST['caption'] ?? '') : '';
    $seo_title = isset($_POST['seo_title']) ? trim(strip_tags($_POST['seo_title'] ?? '')) : '';
    $seo_description = isset($_POST['seo_description']) ? trim($_POST['seo_description'] ?? '') : '';
    $seo_keywords = isset($_POST['seo_keywords']) ? trim(strip_tags($_POST['seo_keywords'] ?? '')) : '';

    // 验证必填字段
    if (empty($title)) {
        http_response_code(400);
        echo json_encode(array('error' => '标题不能为空'));
        exit;
    }

    // 更新媒体信息
    $update_data = array(
        'media_title' => $title,
        'media_description' => $description,
        'media_alt' => $alt_text,
        'media_caption' => $caption,
        'seo_title' => $seo_title,
        'seo_description' => $seo_description,
        'seo_keywords' => $seo_keywords
    );

    // 更新主要信息
    $result = $media->update($update_data);

    if (!$result) {
        throw new Exception('更新媒体信息失败');
    }

    // 更新alt文本（如果是图像）
    if (!empty($alt_text)) {
        $media->update_meta('_todo_attachment_image_alt', $alt_text);
    }

    // 返回成功响应
    echo json_encode(array(
        'success' => true,
        'message' => '媒体信息更新成功',
        'data' => array(
            'id' => $media->get_id(),
            'title' => $title,
            'description' => $description,
            'alt_text' => $alt_text,
            'caption' => $caption
        )
    ));

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('error' => '更新失败: ' . $e->getMessage()));
}