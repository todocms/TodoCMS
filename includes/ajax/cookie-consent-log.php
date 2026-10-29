<?php
/**
 * GDPR Cookie 同意审计日志记录 AJAX 接口
 */
if (!defined('ABSPATH')) {
    define('ABSPATH', rtrim(dirname(dirname(dirname(__FILE__))), '/') . '/');
}

require_once ABSPATH . 'vendor/autoload.php';
require_once ABSPATH . 'config.php';

if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}

require_once ABSPATH . 'core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// 检查 GDPR 同意日志存证功能是否开启
$cookie_consent_enabled = function_exists('get_option') ? get_option('cookie_consent_enabled', '0') : '0';
$cookie_consent_audit_log = function_exists('get_option') ? get_option('cookie_consent_audit_log', '1') : '1';

if ($cookie_consent_enabled !== '1' || $cookie_consent_audit_log !== '1') {
    echo json_encode(['success' => false, 'message' => 'Audit log is disabled']);
    exit;
}

// IP 匿名化处理 (符合 GDPR Article 4 & EDPB 规定)
function todo_anonymize_ip_address($ip)
{
    if (empty($ip)) return '';
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return preg_replace('/[0-9]+$/', '0', $ip);
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $packed = @inet_pton($ip);
        if ($packed === false) return $ip;
        return @inet_ntop($packed & pack('a16', "\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\x00\x00\x00\x00\x00\x00\x00\x00"));
    }
    return $ip;
}

$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true);

if (empty($data) && !empty($_POST)) {
    $data = $_POST;
}

$choice = isset($data['choice']) ? $data['choice'] : null;
if (!$choice || !is_array($choice)) {
    echo json_encode(['success' => false, 'message' => 'Invalid choice payload']);
    exit;
}

global $tododb;
if (!$tododb) {
    echo json_encode(['success' => false, 'message' => 'Database connection unavailable']);
    exit;
}

$user_id = 0;
if (function_exists('get_current_user_id')) {
    $user_id = intval(get_current_user_id());
}

$raw_ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
$anonymized_ip = todo_anonymize_ip_address($raw_ip);
$user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr(trim($_SERVER['HTTP_USER_AGENT']), 0, 255) : '';
$consent_json = json_encode([
    'necessary' => true,
    'analytics' => !empty($choice['analytics']),
    'marketing' => !empty($choice['marketing']),
    'timestamp' => isset($choice['timestamp']) ? intval($choice['timestamp']) : time() * 1000
], JSON_UNESCAPED_UNICODE);

$table = TABLE_PREFIX . 'cookie_consent_logs';
$inserted = $tododb->insert(
    $table,
    [
        'user_id' => $user_id,
        'ip_address' => $anonymized_ip,
        'user_agent' => $user_agent,
        'consent_choice' => $consent_json,
        'created_at' => date('Y-m-d H:i:s')
    ],
    ['%d', '%s', '%s', '%s', '%s']
);

if ($inserted !== false) {
    echo json_encode(['success' => true, 'log_id' => $tododb->insert_id]);
} else {
    echo json_encode(['success' => false, 'message' => 'Insert log failed']);
}
exit;
