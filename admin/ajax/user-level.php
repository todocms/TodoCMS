<?php
/**
 * 用户等级AJAX处理器
 *
 * @package TodoCMS
 * @subpackage Administration
 */

if (!defined('DOING_AJAX') || !DOING_AJAX) {
    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
        http_response_code(403);
        echo json_encode(array('error' => 'Direct access denied'));
        exit;
    }
}

// 检查用户权限（兼容拥有用户管理权限的管理员）
if (!current_user_can('manage_user_levels') && !current_user_can('manage_users') && !current_user_can('manage_options')) {
    http_response_code(403);
    echo json_encode(array('error' => '权限不足'));
    exit;
}

// 验证CSRF令牌（与页面生成的令牌 action 保持一致，避免重复校验导致的缓存误判）
if (!Todo_Security_Manager::verify_request_csrf('user_levels_manage', '_csrf_token')) {
    http_response_code(403);
    echo json_encode(array('error' => 'CSRF验证失败'));
    exit;
}

$action = isset($_POST['sub_action']) ? $_POST['sub_action'] : '';

try {
    switch ($action) {
        case 'get_level':
            $level_id = intval($_POST['level_id']);
            $level = todo_get_user_level($level_id);
            
            if ($level) {
                echo json_encode(array(
                    'success' => true,
                    'data' => $level
                ));
            } else {
                echo json_encode(array('error' => '用户等级不存在'));
            }
            break;
            
        case 'update_level':
            $level_id = intval($_POST['level_id']);
            $level_name = sanitize_text_field($_POST['level_name']);
            $level_value = intval($_POST['level_value']);
            $min_points = intval($_POST['min_points']);
            $max_points = intval($_POST['max_points']);
            $discount_rate = floatval($_POST['discount_rate']);
            $permissions = isset($_POST['permissions']) ? $_POST['permissions'] : array();
            $description = sanitize_textarea_field($_POST['description']);
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            // 验证数据
            if (empty($level_name)) {
                echo json_encode(array('error' => '等级名称不能为空'));
                exit;
            }
            
            if ($level_value <= 0) {
                echo json_encode(array('error' => '等级值必须大于0'));
                exit;
            }
            
            if ($min_points < 0 || $max_points < 0) {
                echo json_encode(array('error' => '积分值不能为负数'));
                exit;
            }
            
            if ($min_points >= $max_points && $max_points > 0) {
                echo json_encode(array('error' => '最小积分必须小于最大积分'));
                exit;
            }
            
            if ($discount_rate < 0 || $discount_rate > 100) {
                echo json_encode(array('error' => '折扣率必须在0-100之间'));
                exit;
            }
            
            $result = todo_update_user_level($level_id, array(
                'level_name' => $level_name,
                'level_value' => $level_value,
                'min_points' => $min_points,
                'max_points' => $max_points,
                'discount_rate' => $discount_rate,
                'permissions' => json_encode($permissions),
                'description' => $description,
                'is_active' => $is_active
            ));
            
            if ($result) {
                echo json_encode(array(
                    'success' => true,
                    'message' => '用户等级更新成功'
                ));
            } else {
                echo json_encode(array('error' => '更新失败'));
            }
            break;
            
        case 'delete_level':
            $level_id = intval($_POST['level_id']);
            
            // 检查是否有用户使用此等级
            global $tododb;
            $users_table = TABLE_PREFIX . "users";
            $sql = "SELECT COUNT(*) FROM {$users_table} u";
            $where_sub = array("u.user_level = ?");
            if (!todo_is_super_admin()) {
                $sql .= " LEFT JOIN " . TABLE_PREFIX . "user_meta um_dev ON u.ID = um_dev.user_id AND um_dev.meta_key = 'is_developer'";
                $where_sub[] = "(um_dev.meta_value IS NULL OR um_dev.meta_value != '1')";
            }
            $sql .= " WHERE " . implode(' AND ', $where_sub);
            $users_count = intval($tododb->get_var($sql, array($level_id)));
            
            if (intval($users_count) > 0) {
                echo json_encode(array('error' => '该等级下还有用户，无法删除'));
                exit;
            }
            
            $result = todo_delete_user_level($level_id);
            
            if ($result) {
                echo json_encode(array(
                    'success' => true,
                    'message' => '用户等级删除成功'
                ));
            } else {
                echo json_encode(array('error' => '删除失败'));
            }
            break;
            
        case 'toggle_status':
            $level_id = intval($_POST['level_id']);
            $is_active = intval($_POST['is_active']);
            
            $result = todo_update_user_level($level_id, array(
                'is_active' => $is_active
            ));
            
            if ($result) {
                echo json_encode(array(
                    'success' => true,
                    'message' => '状态更新成功'
                ));
            } else {
                echo json_encode(array('error' => '状态更新失败'));
            }
            break;
            
        case 'batch_delete':
            $level_ids = isset($_POST['level_ids']) ? $_POST['level_ids'] : array();
            
            if (empty($level_ids) || !is_array($level_ids)) {
                echo json_encode(array('error' => '请选择要删除的等级'));
                exit;
            }
            
            $deleted_count = 0;
            $errors = array();
            
            foreach ($level_ids as $level_id) {
                $level_id = intval($level_id);
                
                $users_table = TABLE_PREFIX . "users";
                $sql = "SELECT COUNT(*) FROM {$users_table} u";
                $where_sub = array("u.user_level = ?");
                if (!todo_is_super_admin()) {
                    $sql .= " LEFT JOIN " . TABLE_PREFIX . "user_meta um_dev ON u.ID = um_dev.user_id AND um_dev.meta_key = 'is_developer'";
                    $where_sub[] = "(um_dev.meta_value IS NULL OR um_dev.meta_value != '1')";
                }
                $sql .= " WHERE " . implode(' AND ', $where_sub);
                $users_count = intval($tododb->get_var($sql, array($level_id)));
                
                if (intval($users_count) > 0) {
                    $level = todo_get_user_level($level_id);
                    $errors[] = "等级 '{$level['level_name']}' 下还有用户，无法删除";
                    continue;
                }
                
                if (todo_delete_user_level($level_id)) {
                    $deleted_count++;
                } else {
                    $level = todo_get_user_level($level_id);
                    $errors[] = "删除等级 '{$level['level_name']}' 失败";
                }
            }
            
            $message = "成功删除 {$deleted_count} 个等级";
            if (!empty($errors)) {
                $message .= "，但有以下错误：" . implode('；', $errors);
            }
            
            echo json_encode(array(
                'success' => true,
                'message' => $message,
                'deleted_count' => $deleted_count,
                'errors' => $errors
            ));
            break;
            
        case 'generate_slug':
            $level_name = sanitize_text_field($_POST['level_name'] ?? '');
            $level_id = isset($_POST['level_id']) ? intval($_POST['level_id']) : 0;

            if ($level_name === '') {
                echo json_encode(array('error' => '缺少等级名称'));
                break;
            }

            // 使用通用函数生成标识符（拼音 + ID）
            $slug = todo_generate_slug($level_name, $level_id, 'level');

            // 确保唯一
            $manager = todo_get_user_level_manager();
            $exclude_id = $level_id > 0 ? $level_id : null;
            if ($manager && method_exists($manager, 'level_slug_exists')) {
                if ($manager->level_slug_exists($slug, $exclude_id)) {
                    $counter = 1;
                    $candidate = $slug . '-' . $counter;
                    while ($manager->level_slug_exists($candidate, $exclude_id)) {
                        $counter++;
                        $candidate = $slug . '-' . $counter;
                    }
                    $slug = $candidate;
                }
            }

            echo json_encode(array('success' => true, 'slug' => $slug));
            break;

        default:
            echo json_encode(array('error' => '无效的操作'));
            break;
    }
    
} catch (Exception $e) {
    error_log('用户等级AJAX错误: ' . $e->getMessage());
    echo json_encode(array('error' => '操作失败：' . $e->getMessage()));
}

exit;