<?php
/**
 * TodoCMS 后台友情链接处理AJAX接口
 * 
 * 处理友情链接管理相关的AJAX请求
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
        'message' => '权限不足'
    ));
    exit;
}

// 获取操作类型
$action = $_POST['action'] ?? '';

// 验证CSRF令牌
if (!Todo_Security_Manager::verify_request_csrf()) {
    http_response_code(403);
    echo json_encode(array(
        'success' => false,
        'message' => 'CSRF验证失败'
    ));
    exit;
}

try {
    global $tododb;
    
    switch ($action) {
        case 'delete_link':
            $link_id = intval($_POST['link_id'] ?? 0);
            
            if ($link_id <= 0) {
                throw new Exception('无效的链接ID');
            }
            
            // 删除友情链接
            $result = $tododb->delete(
                $tododb->prefix . 'links',
                array('id' => $link_id),
                array('%d')
            );
            
            if ($result === false) {
                throw new Exception('删除失败');
            }
            
            echo json_encode(array(
                'success' => true,
                'message' => '友情链接删除成功'
            ));
            break;
            
        case 'toggle_status':
            $link_id = intval($_POST['link_id'] ?? 0);
            $status = $_POST['status'] ?? '';
            
            if ($link_id <= 0) {
                throw new Exception('无效的链接ID');
            }
            
            if (!in_array($status, array('active', 'inactive'))) {
                throw new Exception('无效的状态值');
            }
            
            // 更新状态
            $result = $tododb->update(
                $tododb->prefix . 'links',
                array(
                    'status' => $status,
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $link_id),
                array('%s', '%s'),
                array('%d')
            );
            
            if ($result === false) {
                throw new Exception('状态更新失败');
            }
            
            echo json_encode(array(
                'success' => true,
                'message' => '状态更新成功',
                'new_status' => $status
            ));
            break;
            
        case 'batch_delete':
            $link_ids = $_POST['link_ids'] ?? array();
            
            if (empty($link_ids) || !is_array($link_ids)) {
                throw new Exception('请选择要删除的友情链接');
            }
            
            // 验证所有ID都是数字
            $link_ids = array_map('intval', $link_ids);
            $link_ids = array_filter($link_ids, function($id) {
                return $id > 0;
            });
            
            if (empty($link_ids)) {
                throw new Exception('无效的链接ID');
            }
            
            // 批量删除
            $placeholders = implode(',', array_fill(0, count($link_ids), '?'));
            $sql = "DELETE FROM {$tododb->prefix}links WHERE id IN ($placeholders)";
            $stmt = $tododb->prepare($sql);
            $stmt->execute($link_ids);
            $result = $stmt->rowCount();
            
            if ($result === false) {
                throw new Exception('批量删除失败');
            }
            
            echo json_encode(array(
                'success' => true,
                'message' => "成功删除 {$result} 个友情链接"
            ));
            break;
            
        case 'batch_status':
            $link_ids = $_POST['link_ids'] ?? array();
            $status = $_POST['status'] ?? '';
            
            if (empty($link_ids) || !is_array($link_ids)) {
                throw new Exception('请选择要操作的友情链接');
            }
            
            if (!in_array($status, array('active', 'inactive'))) {
                throw new Exception('无效的状态值');
            }
            
            // 验证所有ID都是数字
            $link_ids = array_map('intval', $link_ids);
            $link_ids = array_filter($link_ids, function($id) {
                return $id > 0;
            });
            
            if (empty($link_ids)) {
                throw new Exception('无效的链接ID');
            }
            
            // 批量更新状态
            $placeholders = implode(',', array_fill(0, count($link_ids), '?'));
            $sql = "UPDATE {$tododb->prefix}links SET status = ?, updated_at = ? WHERE id IN ($placeholders)";
            $params = array_merge(array($status, current_time('mysql')), $link_ids);
            $stmt = $tododb->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->rowCount();
            
            if ($result === false) {
                throw new Exception('批量状态更新失败');
            }
            
            $status_text = $status === 'active' ? '启用' : '禁用';
            echo json_encode(array(
                'success' => true,
                'message' => "成功{$status_text} {$result} 个友情链接"
            ));
            break;
            
        default:
            throw new Exception('未知的操作类型');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(array(
        'success' => false,
        'message' => $e->getMessage()
    ));
}