<?php
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
}

ini_set('display_errors', 0);
error_reporting(0);

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
    echo json_encode(array('error' => 'Direct access not permitted'));
    exit;
}

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
}

require_once ABSPATH . 'config.php';
require_once ABSPATH . 'core/bootstrap.php';
require_once ABSPATH . 'core/functions/security.php';

header('Content-Type: application/json');

if (class_exists('Todo_Session_Manager')) {
    Todo_Session_Manager::init();
}
if (!class_exists('Todo_Session_Manager') || !Todo_Session_Manager::is_admin_logged_in()) {
    http_response_code(403);
    echo json_encode(array('error' => '权限不足'));
    exit;
}

if (!Todo_Security_Manager::verify_request_csrf('recalc_media_usage', '_recalc_nonce')) {
    http_response_code(403);
    echo json_encode(array('error' => '安全验证失败，请重新提交'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('error' => '方法不允许'));
    exit;
}

$action = isset($_POST['action']) ? (string) $_POST['action'] : '';
if ($action !== 'recalc_media_usage') {
    http_response_code(400);
    echo json_encode(array('error' => '无效的操作'));
    exit;
}

$media_id = isset($_POST['media_id']) ? (int) $_POST['media_id'] : 0;
if ($media_id <= 0) {
    http_response_code(400);
    echo json_encode(array('error' => '无效的媒体ID'));
    exit;
}

try {
    $r = CMS_Media::recalculate_usage_count($media_id);
    echo json_encode(array(
        'success' => true,
        'media_id' => $media_id,
        'usage_count' => isset($r['usage_count']) ? (int) $r['usage_count'] : 0,
        'is_attached' => isset($r['is_attached']) ? (int) $r['is_attached'] : 0
    ));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(array('error' => '服务器错误'));
}

