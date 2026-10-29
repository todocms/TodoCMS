<?php
/**
 * GDPR 同意日志后台管理 API：导出 CSV & 清理历史日志 (Retention Pruning)
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

// 检查管理员权限
if (function_exists('is_admin_logged_in') && !is_admin_logged_in()) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Admin authentication required']);
    exit;
}

global $tododb;
$action = isset($_REQUEST['action']) ? trim($_REQUEST['action']) : '';
$log_table = TABLE_PREFIX . 'cookie_consent_logs';

// 1. 导出 CSV 同意日志 (供监管审计提交)
if ($action === 'export_csv') {
    $logs = $tododb->get_results("SELECT id, user_id, ip_address, user_agent, consent_choice, created_at FROM {$log_table} ORDER BY id DESC", ARRAY_A);

    $filename = 'gdpr-cookie-consent-audit-logs-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    // UTF-8 BOM
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, ['Log ID', 'User ID', 'Anonymized IP', 'User Agent', 'Consent Choice JSON', 'Created At']);

    if (!empty($logs)) {
        foreach ($logs as $row) {
            fputcsv($output, [
                $row['id'],
                $row['user_id'],
                $row['ip_address'],
                $row['user_agent'],
                $row['consent_choice'],
                $row['created_at']
            ]);
        }
    }
    fclose($output);
    exit;
}

// 2. 清理过期的同意日志（根据 GDPR 最小化原则，清除 1 年以上的历史日志）
if ($action === 'prune_logs') {
    header('Content-Type: application/json; charset=utf-8');
    $days = isset($_POST['days']) ? intval($_POST['days']) : 365;
    if ($days < 30) $days = 30; // 至少保留 30 天

    $sql = $tododb->prepare("DELETE FROM {$log_table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", $days);
    $deleted = $tododb->query($sql);

    if ($deleted !== false) {
        echo json_encode([
            'success' => true,
            'message' => sprintf('已成功清理 %d 条超过 %d 天的过期的 GDPR 审计日志。', $deleted, $days),
            'deleted_count' => $deleted
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => '清理日志失败']);
    }
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => false, 'message' => 'Invalid action']);
exit;
