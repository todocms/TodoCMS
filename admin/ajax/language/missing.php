<?php
/**
 * 缺失翻译 AJAX 处理器
 * 
 * @package TodoCMS
 * @subpackage Language
 */

if (!defined('DOING_AJAX') || !DOING_AJAX) {
    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Direct access denied']);
        exit;
    }
}

// 权限检查
if (!todo_current_user_can('manage_languages')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

// CSRF
if (!Todo_Security_Manager::verify_request_csrf('manage_missing')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid nonce']);
    exit;
}

header('Content-Type: application/json');

$registry = new LanguageRegistry();
$manager  = todo_language_manager();

global $tododb;
$action = sanitize_text_field($_POST['sub_action'] ?? '');

try {
    switch ($action) {
        case 'list':
            $lang   = sanitize_text_field($_POST['language_code'] ?? '');
            $status = sanitize_text_field($_POST['status'] ?? 'pending');
            $search = sanitize_text_field($_POST['s'] ?? '');
            $limit  = max(1, min(100, intval($_POST['limit'] ?? 20)));
            $offset = max(0, intval($_POST['offset'] ?? 0));
            
            $where  = "WHERE t.status = ?";
            $params = [$status];
            if ($lang !== '') { $where .= " AND t.language_code = ?"; $params[] = $lang; }
            if ($search !== '') {
                $where .= " AND (terms.original_text LIKE ? OR terms.term_key LIKE ?)";
                $like = '%' . $search . '%';
                $params[] = $like; $params[] = $like;
            }
            $total = intval($tododb->get_var(
                "SELECT COUNT(*) FROM {$tododb->prefix}language_translations t
                 LEFT JOIN {$tododb->prefix}language_terms terms ON t.term_id = terms.id
                 {$where}",
                $params
            ));
            $rows = $tododb->get_results(
                "SELECT t.id, t.term_id, t.language_code, t.status, t.translated_text, terms.original_text, terms.term_key
                 FROM {$tododb->prefix}language_translations t
                 LEFT JOIN {$tododb->prefix}language_terms terms ON t.term_id = terms.id
                 {$where}
                 ORDER BY t.id DESC
                 LIMIT {$limit} OFFSET {$offset}",
                $params
            );
            echo json_encode(['success' => true, 'data' => ['total' => $total, 'items' => $rows]]);
            break;
        
        case 'mark_status':
            $ids    = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
            $status = sanitize_text_field($_POST['status'] ?? 'pending');
            $count = 0;
            foreach ($ids as $id) {
                $row = $tododb->get_row("SELECT term_id, language_code FROM {$tododb->prefix}language_translations WHERE id = ?", [$id]);
                if (!$row) { continue; }
                $tododb->update($tododb->prefix . 'language_translations', [
                    'status' => $status,
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id' => $id]);
                if ($manager && method_exists($manager, 'clear_translation_cache')) {
                    $manager->clear_translation_cache(intval($row->term_id), sanitize_text_field($row->language_code));
                }
                $count++;
            }
            echo json_encode(['success' => true, 'data' => ['updated' => $count]]);
            break;
        
        case 'delete':
            $ids  = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
            $count = 0;
            foreach ($ids as $id) {
                $row = $tododb->get_row("SELECT term_id, language_code FROM {$tododb->prefix}language_translations WHERE id = ?", [$id]);
                if (!$row) { continue; }
                $tododb->delete($tododb->prefix . 'language_translations', ['id' => $id]);
                $tododb->delete($tododb->prefix . 'language_cache', ['translation_id' => $id]);
                if ($manager && method_exists($manager, 'clear_translation_cache')) {
                    $manager->clear_translation_cache(intval($row->term_id), sanitize_text_field($row->language_code));
                }
                $count++;
            }
            echo json_encode(['success' => true, 'data' => ['deleted' => $count]]);
            break;
        
        default:
            echo json_encode(['success' => false, 'data' => '无效的操作']);
    }
} catch (Exception $e) {
    error_log('Missing translations AJAX error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'data' => '操作失败']);
}
?>
