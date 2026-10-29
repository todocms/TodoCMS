<?php
/**
 * TodoCMS 友情链接完整CRUD处理器
 * 
 * 处理友情链接的增删改查操作
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('DOING_AJAX') || !DOING_AJAX) {
    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
        http_response_code(403);
        echo json_encode(array('error' => 'Direct access denied'));
        exit;
    }
}

// 定义AJAX环境
if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}

// 加载核心文件
require_once dirname(dirname(dirname(__FILE__))) . '/core/bootstrap.php';

// 检查用户权限
if (!is_user_logged_in() || !todo_current_user_can('edit_posts')) {
    http_response_code(403);
    echo json_encode(array(
        'success' => false,
        'error' => '权限不足'
    ));
    exit;
}

// 获取操作类型
$action = $_REQUEST['action'] ?? '';

// 对于非查询操作，验证CSRF令牌
if (!in_array($action, ['list', 'get']) && !Todo_Security_Manager::verify_request_csrf()) {
    http_response_code(403);
    echo json_encode(array(
        'success' => false,
        'error' => 'CSRF验证失败'
    ));
    exit;
}

try {
    global $tododb;
    $table_name = $tododb->prefix . 'links';
    
    switch ($action) {
        case 'add':
            // 添加友情链接
            $name = sanitize_text_field($_POST['name'] ?? '');
            $url = esc_url_raw($_POST['url'] ?? '');
            $description = sanitize_textarea_field($_POST['description'] ?? '');
            $logo = esc_url_raw($_POST['logo'] ?? '');
            $category = sanitize_text_field($_POST['category'] ?? '');
            $target = sanitize_text_field($_POST['target'] ?? '_blank');
            $sort_order = intval($_POST['sort_order'] ?? 0);
            $status = sanitize_text_field($_POST['status'] ?? 'active');
            
            // 验证必填字段
            if (empty($name)) {
                throw new Exception('链接名称不能为空');
            }
            if (empty($url)) {
                throw new Exception('链接地址不能为空');
            }
            
            // 验证URL格式
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                throw new Exception('链接地址格式不正确');
            }
            
            // 检查链接名称是否已存在
            $existing = $tododb->get_var(
                "SELECT id FROM $table_name WHERE name = ?",
                [$name]
            );
            
            if ($existing) {
                throw new Exception('链接名称已存在');
            }
            
            // 插入数据
            $result = $tododb->insert(
                $table_name,
                array(
                    'name' => $name,
                    'url' => $url,
                    'description' => $description,
                    'logo' => $logo,
                    'category' => $category,
                    'target' => $target,
                    'sort_order' => $sort_order,
                    'status' => $status,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
            );
            
            if ($result === false) {
                throw new Exception('添加失败: ' . $tododb->last_error);
            }
            
            $link_id = $tododb->insert_id();
            
            echo json_encode(array(
                'success' => true,
                'message' => '友情链接添加成功',
                'data' => array('id' => $link_id)
            ));
            break;
            
        case 'update':
            // 更新友情链接
            $id = intval($_POST['id'] ?? 0);
            $name = sanitize_text_field($_POST['name'] ?? '');
            $url = esc_url_raw($_POST['url'] ?? '');
            $description = sanitize_textarea_field($_POST['description'] ?? '');
            $logo = esc_url_raw($_POST['logo'] ?? '');
            $category = sanitize_text_field($_POST['category'] ?? '');
            $target = sanitize_text_field($_POST['target'] ?? '_blank');
            $sort_order = intval($_POST['sort_order'] ?? 0);
            $status = sanitize_text_field($_POST['status'] ?? 'active');
            
            if ($id <= 0) {
                throw new Exception('无效的链接ID');
            }
            
            // 验证必填字段
            if (empty($name)) {
                throw new Exception('链接名称不能为空');
            }
            if (empty($url)) {
                throw new Exception('链接地址不能为空');
            }
            
            // 验证URL格式
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                throw new Exception('链接地址格式不正确');
            }
            
            // 检查链接是否存在
            $existing = $tododb->get_var(
                "SELECT id FROM $table_name WHERE id = ?",
                [$id]
            );
            
            if (!$existing) {
                throw new Exception('友情链接不存在');
            }
            
            // 检查名称是否与其他链接重复
            $duplicate = $tododb->get_var(
                "SELECT id FROM $table_name WHERE name = ? AND id != ?",
                [$name, $id]
            );
            
            if ($duplicate) {
                throw new Exception('链接名称已存在');
            }
            
            // 更新数据
            $result = $tododb->update(
                $table_name,
                array(
                    'name' => $name,
                    'url' => $url,
                    'description' => $description,
                    'logo' => $logo,
                    'category' => $category,
                    'target' => $target,
                    'sort_order' => $sort_order,
                    'status' => $status,
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $id),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s'),
                array('%d')
            );
            
            if ($result === false) {
                throw new Exception('更新失败: ' . $tododb->last_error);
            }
            
            echo json_encode(array(
                'success' => true,
                'message' => '友情链接更新成功'
            ));
            break;
            
        case 'delete':
            // 删除友情链接
            $id = intval($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                throw new Exception('无效的链接ID');
            }
            
            // 检查链接是否存在
            $existing = $tododb->get_var(
                "SELECT id FROM $table_name WHERE id = ?",
                [$id]
            );
            
            if (!$existing) {
                throw new Exception('友情链接不存在');
            }
            
            // 删除链接
            $result = $tododb->delete(
                $table_name,
                array('id' => $id),
                array('%d')
            );
            
            if ($result === false) {
                throw new Exception('删除失败: ' . $tododb->last_error);
            }
            
            echo json_encode(array(
                'success' => true,
                'message' => '友情链接删除成功'
            ));
            break;
            
        case 'get':
            // 获取单个友情链接
            $id = intval($_REQUEST['id'] ?? 0);
            
            if ($id <= 0) {
                throw new Exception('无效的链接ID');
            }
            
            $link = $tododb->get_row(
                "SELECT * FROM $table_name WHERE id = ?",
                [$id],
                'ARRAY_A'
            );
            
            if (!$link) {
                throw new Exception('友情链接不存在');
            }
            
            echo json_encode(array(
                'success' => true,
                'data' => $link
            ));
            break;
            
        case 'list':
            // 获取友情链接列表
            $page = intval($_REQUEST['page'] ?? 1);
            $per_page = intval($_REQUEST['per_page'] ?? 20);
            $status = sanitize_text_field($_REQUEST['status'] ?? '');
            $category = sanitize_text_field($_REQUEST['category'] ?? '');
            $search = sanitize_text_field($_REQUEST['search'] ?? '');
            
            $page = max(1, $page);
            $per_page = max(1, min(100, $per_page));
            $offset = ($page - 1) * $per_page;
            
            // 构建查询条件
            $where_conditions = array();
            $where_params = array();
            
            if (!empty($status)) {
                $where_conditions[] = "status = ?";
                $where_params[] = $status;
            }
            
            if (!empty($category)) {
                $where_conditions[] = "category = ?";
                $where_params[] = $category;
            }
            
            if (!empty($search)) {
                $where_conditions[] = "(name LIKE ? OR description LIKE ? OR url LIKE ?)";
                $search_term = '%' . $tododb->esc_like($search) . '%';
                $where_params[] = $search_term;
                $where_params[] = $search_term;
                $where_params[] = $search_term;
            }
            
            $where_clause = '';
            if (!empty($where_conditions)) {
                $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
            }
            
            // 获取总数
            $count_sql = "SELECT COUNT(*) FROM $table_name $where_clause";
            if (!empty($where_params)) {
                $total = $tododb->get_var($count_sql, $where_params);
            } else {
                $total = $tododb->get_var($count_sql);
            }
            
            // 获取数据
            $data_sql = "SELECT * FROM $table_name $where_clause ORDER BY sort_order ASC, id DESC LIMIT ? OFFSET ?";
            $data_params = array_merge($where_params, array($per_page, $offset));
            $links = $tododb->get_results($data_sql, $data_params, 'ARRAY_A');
            
            echo json_encode(array(
                'success' => true,
                'data' => $links,
                'pagination' => array(
                    'total' => intval($total),
                    'page' => $page,
                    'per_page' => $per_page,
                    'total_pages' => ceil($total / $per_page)
                )
            ));
            break;
            
        case 'toggle_status':
            // 切换状态
            $id = intval($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                throw new Exception('无效的链接ID');
            }
            
            // 获取当前状态
            $current_status = $tododb->get_var(
                "SELECT status FROM $table_name WHERE id = ?",
                [$id]
            );
            
            if ($current_status === null) {
                throw new Exception('友情链接不存在');
            }
            
            // 切换状态
            $new_status = ($current_status === 'active') ? 'inactive' : 'active';
            
            $result = $tododb->update(
                $table_name,
                array(
                    'status' => $new_status,
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $id),
                array('%s', '%s'),
                array('%d')
            );
            
            if ($result === false) {
                throw new Exception('状态更新失败: ' . $tododb->last_error);
            }
            
            echo json_encode(array(
                'success' => true,
                'message' => '状态更新成功',
                'new_status' => $new_status
            ));
            break;
            
        case 'batch_delete':
            // 批量删除
            $ids = $_POST['ids'] ?? array();
            
            if (empty($ids) || !is_array($ids)) {
                throw new Exception('请选择要删除的友情链接');
            }
            
            // 验证所有ID都是数字
            $ids = array_map('intval', $ids);
            $ids = array_filter($ids, function($id) {
                return $id > 0;
            });
            
            if (empty($ids)) {
                throw new Exception('无效的链接ID');
            }
            
            // 批量删除
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "DELETE FROM $table_name WHERE id IN ($placeholders)";
            $stmt = $tododb->prepare($sql);
            $stmt->execute($ids);
            $result = $stmt->rowCount();
            
            if ($result === false) {
                throw new Exception('批量删除失败: ' . $tododb->last_error);
            }
            
            echo json_encode(array(
                'success' => true,
                'message' => "成功删除 {$result} 个友情链接"
            ));
            break;
            
        case 'batch_status':
            // 批量状态更新
            $ids = $_POST['ids'] ?? array();
            $status = sanitize_text_field($_POST['status'] ?? '');
            
            if (empty($ids) || !is_array($ids)) {
                throw new Exception('请选择要操作的友情链接');
            }
            
            if (!in_array($status, array('active', 'inactive'))) {
                throw new Exception('无效的状态值');
            }
            
            // 验证所有ID都是数字
            $ids = array_map('intval', $ids);
            $ids = array_filter($ids, function($id) {
                return $id > 0;
            });
            
            if (empty($ids)) {
                throw new Exception('无效的链接ID');
            }
            
            // 批量更新状态
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "UPDATE $table_name SET status = ?, updated_at = ? WHERE id IN ($placeholders)";
            $params = array_merge(array($status, current_time('mysql')), $ids);
            $stmt = $tododb->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->rowCount();
            
            if ($result === false) {
                throw new Exception('批量状态更新失败: ' . $tododb->last_error);
            }
            
            $status_text = $status === 'active' ? '启用' : '禁用';
            echo json_encode(array(
                'success' => true,
                'message' => "成功{$status_text} {$result} 个友情链接"
            ));
            break;
            
        default:
            throw new Exception('未知的操作类型: ' . $action);
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(array(
        'success' => false,
        'error' => $e->getMessage()
    ));
}