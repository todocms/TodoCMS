<?php
/**
 * 获取语言信息 AJAX 处理器
 * 
 * @package TodoCMS
 * @subpackage Language
 */

if (!defined('DOING_AJAX') || !DOING_AJAX) {
    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
        http_response_code(403);
        echo json_encode(['error' => 'Direct access denied']);
        exit;
    }
}

// 检查用户权限
if (!todo_current_user_can('manage_languages')) {
    http_response_code(403);
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

// 验证nonce
if (!Todo_Security_Manager::verify_request_csrf('get_language_info')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid nonce']);
    exit;
}

// 获取参数
$language_id = intval($_POST['language_id']);

if (!$language_id) {
    echo json_encode(['success' => false, 'data' => '无效的语言ID']);
    exit;
}

global $tododb;

try {
    // 获取语言信息
    $sql = "SELECT * FROM {$tododb->prefix}languages WHERE id = ?";
    $language = $tododb->get_row($sql, [$language_id]);
    
    if (!$language) {
        echo json_encode(['success' => false, 'data' => '语言不存在']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'id' => $language->id,
            'code' => $language->code,
            'name' => $language->name,
            'native_name' => $language->native_name,
            'flag' => $language->flag,
            'direction' => $language->direction,
            'status' => $language->status,
            'sort_order' => $language->sort_order
        ]
    ]);
    
} catch (Exception $e) {
    error_log('Language get info error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'data' => '获取语言信息失败']);
}
?>