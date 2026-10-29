<?php
/**
 * 升级套餐AJAX处理器
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
if (!current_user_can('manage_upgrade_packages') && !current_user_can('manage_users')) {
    http_response_code(403);
    echo json_encode(array('error' => '权限不足'));
    exit;
}

if (!function_exists('todo_get_upgrade_package_manager')) {
    if (defined('ABSPATH')) {
        $__mgr_path = ABSPATH . 'core/classes/class-upgrade-package-manager.php';
        if (file_exists($__mgr_path)) {
            require_once $__mgr_path;
        }
    }
}

$__csrf_ok = false;
 if (class_exists('Todo_Security_Manager')) {
     $token = $_POST['_csrf_token'] ?? $_POST['_todononce'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
     // 先尝试按“字段名”验证（核心类风格）
     if (!$__csrf_ok) { $__csrf_ok = Todo_Security_Manager::verify_request_csrf('upgrade_packages_manage', '_csrf_token'); }
     if (!$__csrf_ok) { $__csrf_ok = Todo_Security_Manager::verify_request_csrf('upgrade_package_action', '_csrf_token'); }
     if (!$__csrf_ok) { $__csrf_ok = Todo_Security_Manager::verify_request_csrf('upgrade_packages_manage'); }
     // 兼容管理员版安全管理器（参数为token值）
     if (!$__csrf_ok && !empty($token)) { $__csrf_ok = Todo_Security_Manager::verify_request_csrf('upgrade_packages_manage', $token); }
     if (!$__csrf_ok && !empty($token)) { $__csrf_ok = Todo_Security_Manager::verify_request_csrf('upgrade_package_action', $token); }
     // 直接调用令牌验证（确保兜底成功）
     if (!$__csrf_ok && !empty($token)) { $__csrf_ok = Todo_Security_Manager::verify_csrf_token($token, 'upgrade_package_action'); }
     if (!$__csrf_ok && !empty($token)) { $__csrf_ok = Todo_Security_Manager::verify_csrf_token($token, 'upgrade_packages_manage'); }
 }
if (!$__csrf_ok) {
    http_response_code(403);
    echo json_encode(array('error' => 'CSRF验证失败'));
    exit;
}

$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : (isset($_POST['sub_action']) ? $_POST['sub_action'] : ''));
// 如果是统一入口传入的动作 upgrade_package，则使用 sub_action 进行路由
if ($action === 'upgrade_package') {
    $action = isset($_POST['sub_action']) ? $_POST['sub_action'] : (isset($_GET['sub_action']) ? $_GET['sub_action'] : '');
}

try {
    switch ($action) {
        case 'get_package':
            $package_id = intval($_POST['package_id']);
            $package = todo_get_upgrade_package($package_id);
            
            if ($package) {
                echo json_encode(array(
                    'success' => true,
                    'data' => $package
                ));
            } else {
                echo json_encode(array('error' => '升级套餐不存在'));
            }
            break;
            
        case 'update_package':
            $package_id = intval($_POST['package_id']);
            $package_name = sanitize_text_field($_POST['package_name']);
            $package_type = sanitize_text_field($_POST['package_type']);
            $target_level = intval($_POST['target_level']);
            $points_required = intval($_POST['points_required']);
            $price = floatval($_POST['price']);
            $duration_days = intval($_POST['duration_days']);
            $description = sanitize_textarea_field($_POST['description']);
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            // 验证数据
            if (empty($package_name)) {
                echo json_encode(array('error' => '套餐名称不能为空'));
                exit;
            }
            
            if (!in_array($package_type, array('points', 'direct', 'duration'))) {
                echo json_encode(array('error' => '无效的套餐类型'));
                exit;
            }
            
            if ($package_type === 'direct' && $target_level <= 0) {
                echo json_encode(array('error' => '直升套餐必须指定目标等级'));
                exit;
            }
            
            if ($package_type === 'points' && $points_required <= 0) {
                echo json_encode(array('error' => '积分套餐必须指定积分数量'));
                exit;
            }
            
            if ($package_type === 'duration' && $duration_days <= 0) {
                echo json_encode(array('error' => '时长套餐必须指定有效天数'));
                exit;
            }
            
            if ($price < 0) {
                echo json_encode(array('error' => '价格不能为负数'));
                exit;
            }
            
            $result = todo_update_upgrade_package($package_id, array(
                'package_name' => $package_name,
                'package_type' => $package_type,
                'target_level' => $target_level,
                'points_required' => $points_required,
                'price' => $price,
                'duration_days' => $duration_days,
                'description' => $description,
                'is_active' => $is_active
            ));
            
            if ($result) {
                echo json_encode(array(
                    'success' => true,
                    'message' => '升级套餐更新成功'
                ));
            } else {
                echo json_encode(array('error' => '更新失败'));
            }
            break;
            
        case 'delete_package':
            $package_id = intval($_POST['package_id']);
            
            // 检查是否有未完成的订单
            global $tododb;
            $orders_count = intval($tododb->get_var("
                SELECT COUNT(*) as count 
                FROM " . TABLE_PREFIX . "package_purchase_records 
                WHERE package_id = ? AND status IN ('pending', 'processing')
            ", array($package_id)));
            
            if ($orders_count > 0) {
                echo json_encode(array('error' => '该套餐还有未完成的订单，无法删除'));
                exit;
            }
            
            $result = todo_delete_upgrade_package($package_id);
            
            if ($result) {
                echo json_encode(array(
                    'success' => true,
                    'message' => '升级套餐删除成功'
                ));
            } else {
                echo json_encode(array('error' => '删除失败'));
            }
            break;
            
        case 'toggle_status':
            $package_id = intval($_POST['package_id']);
            $is_active = intval($_POST['is_active']);
            
            $result = todo_update_upgrade_package($package_id, array(
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
            $package_ids = isset($_POST['package_ids']) ? $_POST['package_ids'] : array();
            
            if (empty($package_ids) || !is_array($package_ids)) {
                echo json_encode(array('error' => '请选择要删除的套餐'));
                exit;
            }
            
            $deleted_count = 0;
            $errors = array();
            
            foreach ($package_ids as $package_id) {
                $package_id = intval($package_id);
                
                // 检查是否有未完成的订单
                $orders_count = intval($tododb->get_var("
                    SELECT COUNT(*) as count 
                    FROM " . TABLE_PREFIX . "package_purchase_records 
                    WHERE package_id = ? AND status IN ('pending', 'processing')
                ", array($package_id)));
                
                if ($orders_count > 0) {
                    $package = todo_get_upgrade_package($package_id);
                    $errors[] = "套餐 '{$package['package_name']}' 还有未完成的订单，无法删除";
                    continue;
                }
                
                if (todo_delete_upgrade_package($package_id)) {
                    $deleted_count++;
                } else {
                    $package = todo_get_upgrade_package($package_id);
                    $errors[] = "删除套餐 '{$package['package_name']}' 失败";
                }
            }
            
            $message = "成功删除 {$deleted_count} 个套餐";
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
            
        case 'process_order':
            $order_id = intval($_POST['order_id']);
            $action_type = sanitize_text_field($_POST['action_type']); // approve, reject
            $admin_note = sanitize_textarea_field($_POST['admin_note']);
            
            if (!in_array($action_type, array('approve', 'reject'))) {
                echo json_encode(array('error' => '无效的操作类型'));
                exit;
            }
            
            $result = todo_process_package_order($order_id, $action_type, $admin_note);
            
            if ($result) {
                $message = $action_type === 'approve' ? '订单已批准' : '订单已拒绝';
                echo json_encode(array(
                    'success' => true,
                    'message' => $message
                ));
            } else {
                echo json_encode(array('error' => '处理失败'));
            }
            break;
            
        case 'generate_slug':
            $package_name = sanitize_text_field($_POST['package_name'] ?? '');
            $package_id = isset($_POST['package_id']) ? intval($_POST['package_id']) : 0;

            if ($package_name === '') {
                echo json_encode(array('error' => '缺少套餐名称'));
                break;
            }

            // 使用通用函数生成标识符（拼音 + ID）
            $slug = todo_generate_slug($package_name, $package_id, 'package');

            // 确保唯一
            $manager = todo_get_upgrade_package_manager();
            $exclude_id = $package_id > 0 ? $package_id : null;
            if ($manager && method_exists($manager, 'package_slug_exists')) {
                if ($manager->package_slug_exists($slug, $exclude_id)) {
                    $counter = 1;
                    $candidate = $slug . '-' . $counter;
                    while ($manager->package_slug_exists($candidate, $exclude_id)) {
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
    error_log('升级套餐AJAX错误: ' . $e->getMessage());
    echo json_encode(array('error' => '操作失败：' . $e->getMessage()));
}

exit;
