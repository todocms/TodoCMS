<?php
/**
 * 用户搜索AJAX处理器
 * 用于余额管理页面的用户搜索功能
 */

// 临时错误抑制，防止PHP Notice破坏JSON响应
error_reporting(0);

// 定义AJAX环境
define('DOING_AJAX', true);
if (!defined('IS_ADMIN')) {
    define('IS_ADMIN', true);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

// 检查是否为AJAX请求
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Direct access denied']);
    exit;
}

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
}
require_once ABSPATH . 'config.php';
require_once ABSPATH . 'core/bootstrap.php';

// 权限检查
if (!function_exists('todo_current_user_can') || !todo_current_user_can('manage_options')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => '权限不足']);
    exit;
}

// CSRF验证（兼容新版安全管理器与旧版函数）
$__nonce = $_POST['_todononce'] ?? $_POST['_todononce'] ?? '';
$__valid = false;
if (!empty($__nonce)) {
    if (class_exists('Todo_Security_Manager')) {
        $__valid = Todo_Security_Manager::verify_request_csrf('user_search', '_todononce');
        if (!$__valid) {
            $__valid = Todo_Security_Manager::verify_request_csrf('user_search', '_todononce');
        }
    }
}
if (empty($__nonce) || !$__valid) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'CSRF验证失败']);
    exit;
}

// 获取并清理参数
$search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
$page = isset($_POST['page']) ? intval($_POST['page']) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

try {
    global $tododb;

    $users_table = $tododb->prefix . 'users';
    $balance_table = $tododb->prefix . 'user_balance';

    $where_conditions = '';
    $params = [];
    $order_by = "{$users_table}.user_registered DESC";

    if (!empty($search)) {
        $trimmed_search = trim($search);

        // 支持纯数字、带 # 前缀、id: / ID: / id= 前缀以及逗号/空格分隔的数字列表 (如 "1", "#1", "id: 5", "1,2,3")
        $cleaned_id_search = preg_replace('/^(?:id|ID)\s*[:=]\s*/', '', $trimmed_search);
        $is_id_search = false;
        $id_list = [];

        if (preg_match('/^[#\s\d,]+$/', $cleaned_id_search)) {
            preg_match_all('/\d+/', $cleaned_id_search, $matches);
            if (!empty($matches[0])) {
                $is_id_search = true;
                $id_list = array_values(array_unique(array_map('intval', $matches[0])));
            }
        }

        $escaped_search = method_exists($tododb, 'escape_like') ? $tododb->escape_like($trimmed_search) : $trimmed_search;
        $search_term = '%' . $escaped_search . '%';

        if ($is_id_search && !empty($id_list)) {
            // 如果搜索指定ID列表数量大于默认每页数量，动态调整每页数量以便一次性返回（上限100）
            if (count($id_list) > $per_page) {
                $per_page = min(100, count($id_list));
                $offset = ($page - 1) * $per_page;
            }

            $id_placeholders = implode(',', array_fill(0, count($id_list), '?'));
            $where_conditions = "WHERE ({$users_table}.ID IN ($id_placeholders) OR {$users_table}.user_login LIKE ? OR {$users_table}.user_email LIKE ? OR {$users_table}.display_name LIKE ?)";
            $params = array_merge($id_list, [$search_term, $search_term, $search_term]);
            
            // 命中的ID优先排在最前
            $order_by = "(CASE WHEN {$users_table}.ID IN (" . implode(',', $id_list) . ") THEN 0 ELSE 1 END), {$users_table}.user_registered DESC";
        } else {
            $where_conditions = "WHERE ({$users_table}.user_login LIKE ? OR {$users_table}.user_email LIKE ? OR {$users_table}.display_name LIKE ?)";
            $params = [$search_term, $search_term, $search_term];
        }
    }

    if (defined('TODO_DEMO_ADMIN_ENABLED') && TODO_DEMO_ADMIN_ENABLED && isset($_SESSION['todo_demo_admin']) && $_SESSION['todo_demo_admin'] == 1) {
        $demo_user_id = 0;
        if (isset($_SESSION['admin_user_id'])) {
            $demo_user_id = intval($_SESSION['admin_user_id']);
        } elseif (isset($_SESSION['todo_admin_uid'])) {
            $demo_user_id = intval($_SESSION['todo_admin_uid']);
        }

        if ($demo_user_id > 0) {
            if (empty($where_conditions)) {
                $where_conditions = "WHERE {$users_table}.ID = ?";
            } else {
                $where_conditions = "WHERE (" . substr($where_conditions, 6) . ") AND {$users_table}.ID = ?";
            }
            $params[] = $demo_user_id;
        } else {
            // 没有有效ID，搜不到结果
            if (empty($where_conditions)) {
                $where_conditions = "WHERE 1=0";
            } else {
                $where_conditions .= " AND 1=0";
            }
        }
    }

    $all_users_query_prefix = "FROM {$users_table} ";
    
    // 超级管理员/开发者账号隐身逻辑
    if (!todo_is_super_admin()) {
        $all_users_query_prefix .= " LEFT JOIN {$tododb->prefix}user_meta um_dev ON {$users_table}.ID = um_dev.user_id AND um_dev.meta_key = 'is_developer' ";
        $where_dev_condition = "(um_dev.meta_value IS NULL OR um_dev.meta_value != '1')";
        if (empty($where_conditions)) {
            $where_conditions = "WHERE " . $where_dev_condition;
        } else {
            $where_conditions .= " AND " . $where_dev_condition;
        }
    }

    // 获取总用户数
    $total_query = "SELECT COUNT(*) {$all_users_query_prefix} {$where_conditions}";
    $total_users = $tododb->get_var($total_query, $params);

    // 获取用户分页列表
    $users_query = "SELECT {$users_table}.ID as id, {$users_table}.user_login as username, {$users_table}.user_email as email, {$users_table}.display_name, {$users_table}.user_registered as created_at, {$users_table}.user_status as status 
                    {$all_users_query_prefix} {$where_conditions} 
                    ORDER BY {$order_by} 
                    LIMIT ?, ?";
    
    $paged_params = array_merge($params, [$offset, $per_page]);
    $users = $tododb->get_results($users_query, $paged_params);

    // 完善用户信息，补充余额
    if ($users) {
        foreach ($users as &$user) {
            // 获取用户实际余额（优先使用核心计算函数计算真实余额，并确保元数据同步）
            $user_id = intval($user->id);
            if (function_exists('todo_get_user_balance')) {
                $user->balance = floatval(todo_get_user_balance($user_id));
            } else {
                $balance_meta = $tododb->get_row(
                    "SELECT meta_value FROM {$tododb->prefix}user_meta WHERE user_id = ? AND meta_key = 'todo_balance'", 
                    [$user_id]
                );
                $user->balance = $balance_meta ? floatval($balance_meta->meta_value) : 0.00;
            }
            
            // 计算总充值和总消费（从余额记录表）
            $recharge_total = $tododb->get_var(
                "SELECT SUM(amount) FROM {$tododb->prefix}member_balance WHERE user_id = ? AND type = 'recharge'", 
                [$user_id]
            );
            $consumption_total = $tododb->get_var(
                "SELECT SUM(ABS(amount)) FROM {$tododb->prefix}member_balance WHERE user_id = ? AND type = 'consume'", 
                [$user_id]
            );
            
            $user->total_recharge = $recharge_total ? floatval($recharge_total) : 0.00;
            $user->total_consumption = $consumption_total ? floatval($consumption_total) : 0.00;
        }
    }

    // 准备响应数据
    $response = [
        'success' => true,
        'data' => [
            'users' => $users ? $users : [],
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $total_users > 0 ? ceil($total_users / $per_page) : 0,
                'total_users' => (int)$total_users,
                'per_page' => $per_page
            ]
        ]
    ];

    header('Content-Type: application/json');
    echo json_encode($response);

} catch (Exception $e) {
    error_log('用户搜索错误: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => '服务器内部错误，请联系管理员。'
    ]);
}

exit;
?>
