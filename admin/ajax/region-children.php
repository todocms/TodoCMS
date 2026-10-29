<?php
/**
 * 区域子级数据获取（AJAX）
 *
 * @package TodoCMS
 */

// 仅允许通过 admin-ajax.php 路由访问
if (!defined('DOING_AJAX') || !DOING_AJAX) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Direct access denied']);
    exit;
}

header('Content-Type: application/json');

// CSRF验证（POST请求需要CSRF验证）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Todo_Security_Manager::verify_request_csrf('todo_ajax_nonce', '_todononce')) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF verification failed']);
        exit;
    }
}

// 获取父级代码
$req = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$parent_code = isset($req['parent_code']) ? preg_replace('/[^0-9]/', '', $req['parent_code']) : '';
if ($parent_code === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'data' => [], 'message' => '缺少 parent_code 参数']);
    exit;
}

$parent_code = function_exists('todo_regions_normalize_code') ? todo_regions_normalize_code($parent_code) : $parent_code;
$data = function_exists('todo_regions_get_children') ? todo_regions_get_children($parent_code) : [];

echo json_encode(['success' => true, 'data' => $data]);
