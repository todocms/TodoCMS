<?php
/**
 * TodoCMS 优惠券管理页面
 * 
 * 管理优惠券的创建、编辑、删除等功能
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('TODO_ADMIN')) {
    exit('Direct access denied');
}

// 检查用户权限
if (!current_user_can('manage_options')) {
    todo_die(__('You do not have sufficient permissions to access this page.'));
}

global $tododb;
$coupons_table = $tododb->prefix . 'coupons';

$todo_coupons_form_action = todo_admin_url('index.php?page=todo-coupons');
$todo_coupons_iframe_mode = function_exists('todo_is_iframe_mode') && todo_is_iframe_mode();
if ($todo_coupons_iframe_mode) {
    $todo_coupons_form_action = add_query_arg('format', 'iframe', $todo_coupons_form_action);
}
$todo_coupons_form_target = $todo_coupons_iframe_mode ? 'todo-admin-iframe' : '';

function todo_coupon_maybe_run_auto_birthday_issue()
{
    if (!function_exists('get_option') || !function_exists('update_option')) {
        return;
    }

    $today = gmdate('Y-m-d');
    $last = (string) get_option('coupon_auto_birthday_last_run', '');
    if ($last === $today) {
        return;
    }

    global $tododb;
    $coupons_table = $tododb->prefix . 'coupons';
    $user_coupons_table = $tododb->prefix . 'user_coupons';
    $coupon_issues_table = $tododb->prefix . 'coupon_issues';
    $users_table = $tododb->prefix . 'users';
    $level_records_table = $tododb->prefix . 'user_level_records';

    $now_ts = (int) current_time('timestamp');

    $coupons = $tododb->get_results(
        "SELECT * FROM {$coupons_table} WHERE status = 1 AND issue_mode = 'auto_birthday' AND auto_issue_type = 'birthday'",
        [],
        'ARRAY_A'
    );

    $has_birthday_col = $tododb->get_var("SHOW COLUMNS FROM {$users_table} LIKE 'birthday'");
    $cache_key = 'table_exists_' . md5($level_records_table);
    $has_level_records = false;
    if (class_exists('Todo_Cache_Manager')) {
        $has_level_records = Todo_Cache_Manager::get($cache_key, 'database', 'global');
    }
    if ($has_level_records === false) {
        $has_level_records = $tododb->get_var("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1", [$level_records_table]);
        $has_level_records = !empty($has_level_records);
        if (class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::set($cache_key, $has_level_records, 86400, 'database', 'global');
        }
    }

    foreach ($coupons as $coupon) {
        $coupon_id = isset($coupon['id']) ? (int) $coupon['id'] : 0;
        if ($coupon_id <= 0) {
            continue;
        }

        $auto_days = isset($coupon['auto_issue_days']) ? max(0, (int) $coupon['auto_issue_days']) : 3;
        $target_level_id = isset($coupon['target_level_id']) ? (int) $coupon['target_level_id'] : 0;
        $target_ts = $now_ts + ($auto_days * 86400);
        $target_md = gmdate('m-d', $target_ts);

        $usage_limit = isset($coupon['usage_limit']) ? (int) $coupon['usage_limit'] : 0;
        $used_count = isset($coupon['used_count']) ? (int) $coupon['used_count'] : 0;
        if ($usage_limit > 0 && $used_count >= $usage_limit) {
            continue;
        }

        $user_params = [];
        $user_sql = "SELECT u.ID AS user_id FROM {$users_table} u";
        if ($has_level_records && $target_level_id > 0) {
            $user_sql .= " INNER JOIN {$level_records_table} ulr ON ulr.user_id = u.ID AND ulr.level_id = ?";
            $user_params[] = $target_level_id;
        }
        if ($has_birthday_col) {
            $user_sql .= " WHERE u.birthday IS NOT NULL AND u.birthday <> '' AND DATE_FORMAT(u.birthday, '%m-%d') = ?";
            $user_params[] = $target_md;
        } else {
            $meta_table = $tododb->prefix . 'user_meta';
            $user_sql .= " INNER JOIN {$meta_table} um ON um.user_id = u.ID AND um.meta_key = 'birthday'
                           WHERE um.meta_value IS NOT NULL AND um.meta_value <> '' AND DATE_FORMAT(STR_TO_DATE(um.meta_value, '%Y-%m-%d'), '%m-%d') = ?";
            $user_params[] = $target_md;
        }

        $users = $tododb->get_results($user_sql, $user_params, 'ARRAY_A');
        foreach ($users as $u) {
            $uid = isset($u['user_id']) ? (int) $u['user_id'] : 0;
            if ($uid <= 0) {
                continue;
            }

            $issued = $tododb->insert($coupon_issues_table, [
                'coupon_id' => $coupon_id,
                'user_id' => $uid,
                'issue_type' => 'birthday',
                'issue_key' => 'once',
                'created_at' => current_time('mysql'),
            ]);
            if (!$issued) {
                continue;
            }

            $ok = $tododb->insert($user_coupons_table, [
                'coupon_id' => $coupon_id,
                'user_id' => $uid,
                'status' => 'unused',
                'source' => 'auto_birthday',
                'from_user_id' => null,
                'assigned_at' => current_time('mysql'),
            ]);
            if ($ok) {
                $tododb->query("UPDATE {$coupons_table} SET used_count = used_count + 1 WHERE id = ?", [$coupon_id]);
                $used_count++;
                if ($usage_limit > 0 && $used_count >= $usage_limit) {
                    break;
                }
            }
        }
    }

    update_option('coupon_auto_birthday_last_run', $today);
}

todo_coupon_maybe_run_auto_birthday_issue();

// 处理表单提交
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF验证
    $nonce = isset($_POST['_todononce']) ? sanitize_text_field((string) $_POST['_todononce']) : '';
    if ($nonce === '' || !todo_verify_nonce($nonce, 'todo_coupon_action')) {
        $error = '安全验证失败，请重试。';
    } else {
        $action = sanitize_text_field($_POST['action']);

        switch ($action) {
            case 'add_coupon':
                $result = todo_add_coupon($_POST);
                if ($result['success']) {
                    $message = $result['message'];
                } else {
                    $error = $result['message'];
                }
                break;

            case 'edit_coupon':
                $result = todo_update_coupon($_POST);
                if ($result['success']) {
                    $message = $result['message'];
                } else {
                    $error = $result['message'];
                }
                break;

            case 'delete_coupon':
                $coupon_id = intval($_POST['coupon_id']);
                if (todo_delete_coupon($coupon_id)) {
                    $message = '优惠券删除成功！';
                } else {
                    $error = '优惠券删除失败！';
                }
                break;

            case 'bulk_action':
                $result = todo_handle_coupon_bulk_action($_POST);
                if ($result['success']) {
                    $message = $result['message'];
                } else {
                    $error = $result['message'];
                }
                break;

            case 'grant_coupon':
                $result = todo_grant_coupon_to_user($_POST);
                if ($result['success']) {
                    $message = $result['message'];
                } else {
                    $error = $result['message'];
                }
                break;
        }
    }
}

// 获取筛选参数
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
$search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';

// 分页参数
$per_page = 20;
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$offset = ($current_page - 1) * $per_page;

// 构建查询条件
$where_conditions = ['1=1'];
$where_values = [];

if ($status_filter !== '') {
    $where_conditions[] = 'status = ?';
    $where_values[] = intval($status_filter);
}

if ($search) {
    $where_conditions[] = '(code LIKE ? OR name LIKE ?)';
    $where_values[] = '%' . $tododb->esc_like($search) . '%';
    $where_values[] = '%' . $tododb->esc_like($search) . '%';
}

$where_clause = implode(' AND ', $where_conditions);

// 获取总数
$total_query = "SELECT COUNT(*) FROM $coupons_table WHERE $where_clause";
if ($where_values) {
    $total_coupons = $tododb->get_var($total_query, $where_values);
} else {
    $total_coupons = $tododb->get_var($total_query);
}

// 获取优惠券列表
$coupons_query = "SELECT * FROM $coupons_table WHERE $where_clause ORDER BY created_at DESC LIMIT $offset, $per_page";
$coupons = $tododb->get_results($coupons_query, $where_values);

// 计算分页
$total_pages = ceil($total_coupons / $per_page);

function todo_normalize_coupon_datetime($value)
{
    if ($value === null) {
        return null;
    }

    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $normalized = str_replace('T', ' ', $value);
    $timestamp = strtotime($normalized);
    if ($timestamp === false || $timestamp <= 0) {
        return null;
    }

    return date('Y-m-d H:i:s', $timestamp);
}

/**
 * 添加优惠券
 */
function todo_add_coupon($data)
{
    global $tododb;

    $code = '';
    $name = sanitize_text_field($data['name']);
    $type_raw = sanitize_text_field($data['type']);
    // 转换type字符串为数字：fixed=1, percent=2, threshold=3
    if ($type_raw === 'percent') {
        $type = 2;
    } elseif ($type_raw === 'threshold') {
        $type = 3;
    } else {
        $type = 1;
    }
    $value = floatval($data['value']);
    $min_amount = floatval($data['min_amount']);
    $max_discount = floatval($data['max_discount']);
    $usage_limit = intval($data['usage_limit']);
    $user_limit = intval($data['user_limit']);
    $claim_limit = isset($data['claim_limit']) ? intval($data['claim_limit']) : 1;
    if ($claim_limit <= 0) $claim_limit = 1;
    if ($claim_limit > 10) $claim_limit = 10;
    $start_date = todo_normalize_coupon_datetime($data['start_date'] ?? null);
    $end_date = todo_normalize_coupon_datetime($data['end_date'] ?? null);
    $is_transferable = !empty($data['is_transferable']) ? 1 : 0;
    $issue_mode = isset($data['issue_mode']) ? sanitize_text_field($data['issue_mode']) : 'redeem';
    $auto_issue_days = isset($data['auto_issue_days']) ? intval($data['auto_issue_days']) : 0;
    $target_level_id = isset($data['target_level_id']) ? intval($data['target_level_id']) : 0;
    $activity_id = isset($data['activity_id']) ? intval($data['activity_id']) : 0;
    $auto_issue_type = '';
    if ($issue_mode === 'platform_claim') {
        $auto_issue_days = 0;
        $target_level_id = 0;
    } elseif ($issue_mode === 'redeem') {
        $auto_issue_days = 0;
        $target_level_id = 0;
    } elseif ($issue_mode === 'auto_grant') {
        $auto_issue_days = 0;
        $auto_issue_type = 'grant';
        $target_level_id = max(0, $target_level_id);
    } elseif ($issue_mode === 'auto_birthday') {
        $auto_issue_type = 'birthday';
        $auto_issue_days = max(0, $auto_issue_days);
        $target_level_id = max(0, $target_level_id);
    } else {
        $issue_mode = 'redeem';
        $auto_issue_days = 0;
        $target_level_id = 0;
    }

    if ($activity_id > 0) {
        $activity_start = get_term_meta($activity_id, 'hidden_start', true);
        $activity_end = get_term_meta($activity_id, 'hidden_end', true);
        $start_date = todo_normalize_coupon_datetime($activity_start);
        $end_date = todo_normalize_coupon_datetime($activity_end);
    }

    // 验证必填字段
    if (empty($name) || $value <= 0) {
        return ['success' => false, 'message' => '请填写完整的优惠券信息！'];
    }
    if ($type === 3 && $min_amount <= 0) {
        return ['success' => false, 'message' => '满减优惠券必须设置最小使用金额（满多少可用）。'];
    }

    $table_name = $tododb->prefix . 'coupons';
    $code = todo_generate_coupon_code();
    $attempts = 0;
    while ($attempts < 10) {
        $existing = $tododb->get_var("SELECT id FROM $table_name WHERE code = ? LIMIT 1", [$code]);
        if (!$existing) {
            break;
        }
        $code = todo_generate_coupon_code();
        $attempts++;
    }
    if ($existing) {
        return ['success' => false, 'message' => '优惠券代码生成失败，请重试。'];
    }

    // 插入数据
    $result = $tododb->insert($table_name, [
        'code' => $code,
        'name' => $name,
        'type' => $type,
        'value' => $value,
        'min_amount' => $min_amount,
        'max_discount' => $max_discount,
        'usage_limit' => $usage_limit,
        'user_limit' => $user_limit,
        'claim_limit' => $claim_limit,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'is_transferable' => $is_transferable,
        'issue_mode' => $issue_mode,
        'auto_issue_type' => $auto_issue_type,
        'auto_issue_days' => $auto_issue_days,
        'target_level_id' => $target_level_id,
        'activity_id' => $activity_id,
        'status' => 1,
        'created_at' => current_time('mysql')
    ]);

    if ($result) {
        $coupon_id = (int) $tododb->insert_id();
        if ($issue_mode === 'auto_grant') {
            todo_issue_auto_grant_coupon($coupon_id);
        }
        return ['success' => true, 'message' => '优惠券添加成功！'];
    } else {
        return ['success' => false, 'message' => '优惠券添加失败！'];
    }
}

/**
 * 更新优惠券
 */
function todo_update_coupon($data)
{
    global $tododb;

    $coupon_id = intval($data['coupon_id']);
    $name = sanitize_text_field($data['name']);
    $type_raw = sanitize_text_field($data['type']);
    // 转换type字符串为数字：fixed=1, percent=2, threshold=3
    if ($type_raw === 'percent') {
        $type = 2;
    } elseif ($type_raw === 'threshold') {
        $type = 3;
    } else {
        $type = 1;
    }
    $value = floatval($data['value']);
    $min_amount = floatval($data['min_amount']);
    $max_discount = floatval($data['max_discount']);
    $usage_limit = intval($data['usage_limit']);
    $user_limit = intval($data['user_limit']);
    $claim_limit = isset($data['claim_limit']) ? intval($data['claim_limit']) : 1;
    if ($claim_limit <= 0) $claim_limit = 1;
    if ($claim_limit > 10) $claim_limit = 10;
    $start_date = todo_normalize_coupon_datetime($data['start_date'] ?? null);
    $end_date = todo_normalize_coupon_datetime($data['end_date'] ?? null);
    $status = intval($data['status']);
    $is_transferable = !empty($data['is_transferable']) ? 1 : 0;
    $issue_mode = isset($data['issue_mode']) ? sanitize_text_field($data['issue_mode']) : 'redeem';
    $auto_issue_days = isset($data['auto_issue_days']) ? intval($data['auto_issue_days']) : 0;
    $target_level_id = isset($data['target_level_id']) ? intval($data['target_level_id']) : 0;
    $activity_id = isset($data['activity_id']) ? intval($data['activity_id']) : 0;
    $auto_issue_type = '';
    if ($issue_mode === 'platform_claim') {
        $auto_issue_days = 0;
        $target_level_id = 0;
    } elseif ($issue_mode === 'redeem') {
        $auto_issue_days = 0;
        $target_level_id = 0;
    } elseif ($issue_mode === 'auto_grant') {
        $auto_issue_days = 0;
        $auto_issue_type = 'grant';
        $target_level_id = max(0, $target_level_id);
    } elseif ($issue_mode === 'auto_birthday') {
        $auto_issue_type = 'birthday';
        $auto_issue_days = max(0, $auto_issue_days);
        $target_level_id = max(0, $target_level_id);
    } else {
        $issue_mode = 'redeem';
        $auto_issue_days = 0;
        $target_level_id = 0;
    }

    if ($activity_id > 0) {
        $activity_start = get_term_meta($activity_id, 'hidden_start', true);
        $activity_end = get_term_meta($activity_id, 'hidden_end', true);
        $start_date = todo_normalize_coupon_datetime($activity_start);
        $end_date = todo_normalize_coupon_datetime($activity_end);
    }

    // 验证必填字段
    if (empty($name) || $value <= 0) {
        return ['success' => false, 'message' => '请填写完整的优惠券信息！'];
    }
    if ($type === 3 && $min_amount <= 0) {
        return ['success' => false, 'message' => '满减优惠券必须设置最小使用金额（满多少可用）。'];
    }

    $table_name = $tododb->prefix . 'coupons';
    // 更新数据
    $result = $tododb->update($table_name, [
        'name' => $name,
        'type' => $type,
        'value' => $value,
        'min_amount' => $min_amount,
        'max_discount' => $max_discount,
        'usage_limit' => $usage_limit,
        'user_limit' => $user_limit,
        'claim_limit' => $claim_limit,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'is_transferable' => $is_transferable,
        'issue_mode' => $issue_mode,
        'auto_issue_type' => $auto_issue_type,
        'auto_issue_days' => $auto_issue_days,
        'target_level_id' => $target_level_id,
        'activity_id' => $activity_id,
        'status' => $status,
        'updated_at' => current_time('mysql')
    ], ['id' => $coupon_id]);

    if ($result !== false) {
        if ($issue_mode === 'auto_grant' && $status === 1) {
            todo_issue_auto_grant_coupon($coupon_id);
        }
        return ['success' => true, 'message' => '优惠券更新成功！'];
    } else {
        return ['success' => false, 'message' => '优惠券更新失败！'];
    }
}

function todo_issue_auto_grant_coupon($coupon_id)
{
    global $tododb;
    $coupon_id = (int) $coupon_id;
    if ($coupon_id <= 0) {
        return;
    }

    $coupons_table = $tododb->prefix . 'coupons';
    $user_coupons_table = $tododb->prefix . 'user_coupons';
    $coupon_issues_table = $tododb->prefix . 'coupon_issues';
    $users_table = $tododb->prefix . 'users';
    $level_records_table = $tododb->prefix . 'user_level_records';

    $coupon = $tododb->get_row("SELECT * FROM {$coupons_table} WHERE id = ? LIMIT 1", [$coupon_id], 'ARRAY_A');
    if (!$coupon || empty($coupon['status']) || (string) ($coupon['issue_mode'] ?? '') !== 'auto_grant') {
        return;
    }

    $usage_limit = isset($coupon['usage_limit']) ? (int) $coupon['usage_limit'] : 0;
    $used_count = isset($coupon['used_count']) ? (int) $coupon['used_count'] : 0;
    if ($usage_limit > 0 && $used_count >= $usage_limit) {
        return;
    }

    $target_level_id = isset($coupon['target_level_id']) ? (int) $coupon['target_level_id'] : 0;
    $cache_key = 'table_exists_' . md5($level_records_table);
    $has_level_records = false;
    if (class_exists('Todo_Cache_Manager')) {
        $has_level_records = Todo_Cache_Manager::get($cache_key, 'database', 'global');
    }
    if ($has_level_records === false) {
        $has_level_records = $tododb->get_var("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1", [$level_records_table]);
        $has_level_records = !empty($has_level_records);
        if (class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::set($cache_key, $has_level_records, 86400, 'database', 'global');
        }
    }

    $user_params = [];
    $user_sql = "SELECT u.ID AS user_id FROM {$users_table} u";
    if ($target_level_id > 0) {
        if ($has_level_records) {
            $user_sql .= " LEFT JOIN {$level_records_table} ulr ON ulr.user_id = u.ID";
            $user_sql .= " WHERE (ulr.level_id = ? OR (ulr.level_id IS NULL AND ? = 1))";
            $user_params[] = $target_level_id;
            $user_params[] = $target_level_id;
        } else {
            // 没有等级记录表时，只有目标等级为默认等级(1)或不限等级(0)才发放
            if ($target_level_id != 1) {
                return;
            }
        }
    }
    $users = $tododb->get_results($user_sql, $user_params, 'ARRAY_A');

    foreach ($users as $u) {
        $uid = isset($u['user_id']) ? (int) $u['user_id'] : 0;
        if ($uid <= 0) {
            continue;
        }

        if ($usage_limit > 0 && $used_count >= $usage_limit) {
            break;
        }

        $issued = $tododb->insert($coupon_issues_table, [
            'coupon_id' => $coupon_id,
            'user_id' => $uid,
            'issue_type' => 'grant',
            'issue_key' => 'once',
            'created_at' => current_time('mysql'),
        ]);
        if (!$issued) {
            continue;
        }

        $ok = $tododb->insert($user_coupons_table, [
            'coupon_id' => $coupon_id,
            'user_id' => $uid,
            'status' => 'unused',
            'source' => 'auto_grant',
            'from_user_id' => null,
            'assigned_at' => current_time('mysql'),
        ]);
        if ($ok) {
            $tododb->query("UPDATE {$coupons_table} SET used_count = used_count + 1 WHERE id = ?", [$coupon_id]);
            $used_count++;
        }
    }
}

/**
 * 删除优惠券
 */
function todo_delete_coupon($coupon_id)
{
    global $tododb;
    $coupon_id = intval($coupon_id);
    if ($coupon_id <= 0) {
        return false;
    }

    $table_name = $tododb->prefix . 'coupons';
    $user_coupons_table = $tododb->prefix . 'user_coupons';
    $coupon_issues_table = $tododb->prefix . 'coupon_issues';
    $usage_table = $tododb->prefix . 'coupon_usage';

    // 级联清理相关关联记录
    $tododb->delete($user_coupons_table, ['coupon_id' => $coupon_id]);
    $tododb->delete($coupon_issues_table, ['coupon_id' => $coupon_id]);

    $has_usage = false;
    if (method_exists($tododb, 'table_exists') ? $tododb->table_exists('coupon_usage') : true) {
        $usage_count = (int) $tododb->get_var($tododb->prepare("SELECT COUNT(*) FROM {$usage_table} WHERE coupon_id = %d", $coupon_id));
        if ($usage_count > 0) {
            $has_usage = true;
        }
    }

    if ($has_usage) {
        // 若存在实际订单使用记录，安全软删除（仅禁用）
        return $tododb->update($table_name, ['status' => 0], ['id' => $coupon_id]) !== false;
    }

    // 彻底物理删除优惠券定义
    return $tododb->delete($table_name, ['id' => $coupon_id]) !== false;
}

/**
 * 处理批量操作
 */
function todo_handle_coupon_bulk_action($data)
{
    global $tododb;

    $bulk_action = sanitize_text_field($data['bulk_action']);
    $coupon_ids = isset($data['coupon_ids']) ? array_map('intval', $data['coupon_ids']) : [];

    if (empty($coupon_ids)) {
        return ['success' => false, 'message' => '请选择要操作的优惠券！'];
    }

    $table_name = $tododb->prefix . 'coupons';
    $ids_placeholder = implode(',', array_fill(0, count($coupon_ids), '?'));

    switch ($bulk_action) {
        case 'enable':
            $sql = "UPDATE $table_name SET status = 1 WHERE id IN ($ids_placeholder)";
            $result = $tododb->query($sql, $coupon_ids);
            return ['success' => true, 'message' => "成功启用 {$result} 个优惠券！"];

        case 'disable':
            $sql = "UPDATE $table_name SET status = 0 WHERE id IN ($ids_placeholder)";
            $result = $tododb->query($sql, $coupon_ids);
            return ['success' => true, 'message' => "成功禁用 {$result} 个优惠券！"];

        case 'delete':
            $deleted = 0;
            foreach ($coupon_ids as $id) {
                if (todo_delete_coupon($id)) {
                    $deleted++;
                }
            }
            return ['success' => true, 'message' => "成功处理 {$deleted} 个优惠券！"];

        default:
            return ['success' => false, 'message' => '无效的批量操作！'];
    }
}

function todo_generate_coupon_code()
{
    $raw = strtoupper(bin2hex(random_bytes(6)));
    return 'CP' . $raw;
}

function todo_get_user_id_by_login($login)
{
    $login = sanitize_text_field($login);
    if ($login === '') {
        return 0;
    }
    if (function_exists('get_user_by')) {
        $u = get_user_by('login', $login);
        if ($u && isset($u->ID)) {
            return (int) $u->ID;
        }
    }
    global $tododb;
    $row = $tododb->get_row("SELECT ID FROM {$tododb->prefix}users WHERE user_login = ? LIMIT 1", [$login], 'ARRAY_A');
    if ($row && isset($row['ID'])) {
        return (int) $row['ID'];
    }
    return 0;
}

function todo_grant_coupon_to_user($data)
{
    global $tododb;
    $coupon_id = isset($data['coupon_id']) ? intval($data['coupon_id']) : 0;
    $to_login = isset($data['to_user_login']) ? sanitize_text_field($data['to_user_login']) : '';
    $quantity = isset($data['grant_quantity']) ? max(1, intval($data['grant_quantity'])) : 1;

    if ($coupon_id <= 0 || $to_login === '') {
        return ['success' => false, 'message' => '请填写要发放的用户和优惠券。'];
    }

    $to_user_id = todo_get_user_id_by_login($to_login);
    if ($to_user_id <= 0) {
        return ['success' => false, 'message' => '用户不存在。'];
    }

    $coupons_table = $tododb->prefix . 'coupons';
    $coupon = $tododb->get_row("SELECT * FROM {$coupons_table} WHERE id = ? LIMIT 1", [$coupon_id], 'ARRAY_A');
    if (!$coupon) {
        return ['success' => false, 'message' => '优惠券不存在。'];
    }
    if (empty($coupon['status'])) {
        return ['success' => false, 'message' => '优惠券已禁用。'];
    }

    $usage_limit = isset($coupon['usage_limit']) ? intval($coupon['usage_limit']) : 0;
    $used_count = isset($coupon['used_count']) ? intval($coupon['used_count']) : 0;
    if ($usage_limit > 0 && $used_count + $quantity > $usage_limit) {
        return ['success' => false, 'message' => '优惠券库存不足。'];
    }

    $user_limit = isset($coupon['user_limit']) ? intval($coupon['user_limit']) : 1;
    if ($user_limit > 0) {
        $user_coupons_table = $tododb->prefix . 'user_coupons';
        $existing = (int) $tododb->get_var(
            "SELECT COUNT(*) FROM {$user_coupons_table} WHERE coupon_id = ? AND user_id = ? AND status = 'unused'",
            [$coupon_id, $to_user_id]
        );
        if ($existing + $quantity > $user_limit) {
            return ['success' => false, 'message' => '超过每用户限制。'];
        }
    }

    $user_coupons_table = $tododb->prefix . 'user_coupons';
    $tododb->query('START TRANSACTION');
    try {
        for ($i = 0; $i < $quantity; $i++) {
            $ok = $tododb->insert($user_coupons_table, [
                'coupon_id' => $coupon_id,
                'user_id' => $to_user_id,
                'status' => 'unused',
                'source' => 'manual',
                'from_user_id' => null,
                'assigned_at' => current_time('mysql'),
            ]);
            if (!$ok) {
                throw new Exception('发放失败');
            }
        }

        $tododb->query(
            "UPDATE {$coupons_table} SET used_count = used_count + ? WHERE id = ?",
            [$quantity, $coupon_id]
        );

        $tododb->query('COMMIT');
        return ['success' => true, 'message' => '发放成功！'];
    } catch (Throwable $e) {
        $tododb->query('ROLLBACK');
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * 获取优惠券状态文本
 */
function todo_get_coupon_status_text($coupon)
{
    if (!$coupon->status) {
        return '<span class="status-inactive">已禁用</span>';
    }

    $now = current_time('timestamp');

    // 如果绑定了活动，优先检查活动状态
    if (isset($coupon->activity_id) && $coupon->activity_id > 0) {
        $activity_id = (int)$coupon->activity_id;
        $is_hidden = get_term_meta($activity_id, 'is_hidden', true);
        
        $start_date = get_term_meta($activity_id, 'hidden_start', true);
        $end_date = get_term_meta($activity_id, 'hidden_end', true);
        
        if ($is_hidden) {
            return '<span class="status-reject">活动已隐藏</span>';
        }
        
        if ($start_date && strtotime($start_date) > $now) {
            return '<span class="status-pending">活动未开始</span>';
        }
        
        if ($end_date && strtotime($end_date) < $now) {
            return '<span class="status-reject">活动已结束</span>';
        }
    } else {
        if ($coupon->start_date && strtotime($coupon->start_date) > $now) {
            return '<span class="status-pending">未开始</span>';
        }

        if ($coupon->end_date && strtotime($coupon->end_date) < $now) {
            return '<span class="status-reject">已过期</span>';
        }
    }

    if ($coupon->usage_limit > 0 && $coupon->used_count >= $coupon->usage_limit) {
        return '<span class="status-trash">已用完</span>';
    }

    return '<span class="status-active">有效</span>';
}

/**
 * 格式化优惠券值
 */
function todo_format_coupon_value($coupon)
{
    $symbol = todo_get_default_currency_config()['symbol'];
    if ($coupon->type == 2) { // 2表示百分比类型
        return $coupon->value . '%';
    } elseif ($coupon->type == 3) {
        $min = isset($coupon->min_amount) ? (float) $coupon->min_amount : 0.0;
        return '满' . todo_format_admin_price($min) . '减' . todo_format_admin_price((float) $coupon->value);
    } else {
        return todo_format_admin_price($coupon->value);
    }
}
?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">优惠券管理</h2>
        <div class="admin-page-actions">
            <button type="button" class="button button-primary" onclick="showAddCouponModal()">添加优惠券</button>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html($message); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html($error); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <div class="admin-card">
        <div class="admin-card-header">
            <h3 class="admin-card-title">优惠券列表</h3>
            <div class="admin-card-actions">
                <form method="get" class="search-form" action="<?php echo todo_admin_url('index.php'); ?>">
                    <input type="hidden" name="page" value="todo-coupons">
                    <?php if (function_exists('todo_is_iframe_mode') && todo_is_iframe_mode()): ?>
                        <input type="hidden" name="format" value="iframe">
                    <?php endif; ?>
                    <select name="status" style="min-width: 150px; margin-right: 10px;">
                        <option value="">所有状态</option>
                        <option value="1" <?php selected($status_filter, '1'); ?>>启用</option>
                        <option value="0" <?php selected($status_filter, '0'); ?>>禁用</option>
                    </select>
                    <input type="text" name="search" value="<?php echo esc_attr($search); ?>" placeholder="搜索代码或名称...">
                    <button type="submit" class="button button-secondary">搜索</button>
                    <?php if ($status_filter || $search): ?>
                        <a href="<?php echo esc_url($todo_coupons_form_action); ?>" class="button button-secondary">清除筛选</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <div class="admin-card-content">
            <form method="post" id="coupons-form" action="<?php echo esc_url($todo_coupons_form_action); ?>">
                <?php todo_nonce_field('todo_coupon_action'); ?>
                <input type="hidden" name="action" value="bulk_action">

                <div class="bulk-actions-bar">
                    <div class="bulk-actions">
                        <select name="bulk_action">
                            <option value="">批量操作</option>
                            <option value="enable">启用</option>
                            <option value="disable">禁用</option>
                            <option value="delete">删除</option>
                        </select>
                        <button type="submit" class="button button-secondary">应用</button>
                    </div>
                </div>

                <table class="admin-table">
                    <thead>
                        <tr>
                            <th class="check-column">
                                <input type="checkbox" id="cb-select-all">
                            </th>
                            <th>代码</th>
                            <th style="min-width: 100px;">名称</th>
                            <th style="min-width: 90px;">类型</th>
                            <th style="min-width: 80px;">优惠值</th>
                            <th style="min-width: 130px;">使用限制</th>
                            <th style="min-width: 160px;">使用情况</th>
                            <th style="min-width: 200px;">有效期</th>
                            <th style="min-width: 80px;">状态</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($coupons): ?>
                            <?php foreach ($coupons as $coupon): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="coupon_ids[]" value="<?php echo $coupon->id; ?>">
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html($coupon->code); ?></strong>
                                        <div class="row-actions">
                                            <a href="javascript:void(0);"
                                                onclick="editCoupon(<?php echo htmlspecialchars(json_encode($coupon)); ?>)">
                                                编辑
                                            </a>|
                                            <a href="javascript:void(0);"
                                                onclick="grantCoupon(<?php echo (int) $coupon->id; ?>)">
                                                发放
                                            </a>|
                                            <a href="javascript:void(0);" class="submitdelete"
                                                onclick="deleteCoupon(<?php echo $coupon->id; ?>)">
                                                删除
                                            </a>
                                        </div>
                                    </td>
                                    <td><?php echo esc_html(function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($coupon->name) : $coupon->name); ?></td>
                                    <td>
                                        <?php echo $coupon->type == 2 ? '百分比' : ($coupon->type == 3 ? '满减' : '固定金额'); ?>
                                    </td>
                                    <td>
                                        <strong><?php echo todo_format_coupon_value($coupon); ?></strong>
                                        <?php if ($coupon->min_amount > 0): ?>
                                            <br><small>满<?php echo todo_format_admin_price($coupon->min_amount); ?>可用</small>
                                        <?php endif; ?>
                                        <?php if ($coupon->type == 2 && $coupon->max_discount > 0): ?>
                                            <br><small>最高<?php echo todo_format_admin_price($coupon->max_discount); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($coupon->usage_limit > 0): ?>
                                            <span class="description">总限制:</span>
                                            <strong><?php echo number_format($coupon->usage_limit); ?></strong>
                                            <span class="description" style="margin-left: -5px;">次</span>
                                        <?php else: ?>
                                            <span class="description">总限制：无</span>
                                        <?php endif; ?>
                                        <p class="description m-0">每人限制：<?php echo number_format($coupon->user_limit); ?>次</p>
                                    </td>
                                    <td>
                                        <span class="description">已发放:</span>
                                        <strong><?php echo number_format($coupon->used_count); ?></strong>
                                        <span class="description" style="margin-left: -5px;">次</span>
                                        <?php if ($coupon->usage_limit > 0): ?>
                                            <span class="description" style="margin-left: -5px;">/</span>
                                            <?php echo number_format($coupon->usage_limit); ?>
                                            <span class="description" style="margin-left: -5px;">次</span>
                                            <p class="description m-0">剩余：<?php echo number_format($coupon->usage_limit - $coupon->used_count); ?>次</p>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="tb-description">
                                        <?php if ($coupon->start_date): ?>
                                            开始：<?php echo date('Y-m-d H:i', strtotime($coupon->start_date)); ?><br>
                                        <?php endif; ?>
                                        <?php if ($coupon->end_date): ?>
                                            结束：<?php echo date('Y-m-d H:i', strtotime($coupon->end_date)); ?>
                                        <?php else: ?>
                                            永久有效
                                        <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?php echo todo_get_coupon_status_text($coupon); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr class="empty">
                                <td colspan="9">
                                    <div class="no-items">
                                        <p>没有找到优惠券。</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php if ($total_pages > 1): ?>
                    <?php
                    $pagination_add_args = array();
                    if ($status_filter !== '') {
                        $pagination_add_args['status'] = $status_filter;
                    }
                    if (!empty($search)) {
                        $pagination_add_args['search'] = $search;
                    }

                    echo todo_render_pagination(array(
                        'total_items' => $total_coupons,
                        'per_page' => $per_page,
                        'current_page' => $current_page,
                        'base_url' => $todo_coupons_form_action,
                        'add_args' => $pagination_add_args,
                        'show_count' => true,
                    ));
                    ?>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>
<template id="coupon-modal-template" data-size="lg">
    <div data-slot="title">添加优惠券</div>
    <div data-slot="body">
        <form id="coupon-form" method="post" action="<?php echo esc_url($todo_coupons_form_action); ?>"<?php echo $todo_coupons_form_target ? ' target="' . esc_attr($todo_coupons_form_target) . '"' : ''; ?>>
            <?php todo_nonce_field('todo_coupon_action'); ?>
            <input type="hidden" name="action" value="add_coupon" id="form-action">
            <input type="hidden" name="coupon_id" value="" id="coupon-id">

            <table class="form-table">
                <tr>
                    <th>优惠券代码 <span class="required">*</span></th>
                    <td>
                        <input type="text" name="code" id="coupon-code" class="regular-text" readonly placeholder="系统生成">
                        <p class="description">系统生成的优惠券兑换码，用于用户兑换领取或后台发放识别</p>
                    </td>
                </tr>
                <tr>
                    <th>优惠券名称 <span class="required">*</span></th>
                    <td>
                        <input type="text" name="name" id="coupon-name" class="regular-text" required>
                        <p class="description">优惠券的显示名称</p>
                    </td>
                </tr>
                <tr>
                    <th>优惠类型 <span class="required">*</span></th>
                    <td>
                        <select name="type" id="coupon-type">
                            <option value="fixed">固定金额</option>
                            <option value="threshold">满减</option>
                            <option value="percent">百分比</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>优惠值 <span class="required">*</span></th>
                    <td>
                        <input type="number" name="value" id="coupon-value" step="0.01" min="0" required>
                        <span id="value-unit"></span>
                        <p class="description" id="value-desc">固定优惠金额</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="coupon-min-amount">最小使用金额</label></th>
                    <td>
                        <input type="number" name="min_amount" id="coupon-min-amount" step="0.01" min="0" value="0">
                        <?php echo todo_get_default_currency_config()['code']; ?>
                        <p class="description">订单金额达到此值才能使用优惠券，0表示无限制</p>
                    </td>
                </tr>
                <tr id="max-discount-row" style="display: none;">
                    <th><label for="coupon-max-discount">最大优惠金额</label></th>
                    <td>
                        <input type="number" name="max_discount" id="coupon-max-discount" step="0.01" min="0"
                            value="0">
                        <p class="description">百分比优惠的最大金额限制，0表示无限制</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="coupon-usage-limit">使用次数限制</label></th>
                    <td>
                        <input type="number" name="usage_limit" id="coupon-usage-limit" min="0" value="0">
                        <p class="description">优惠券总共可使用次数，0表示无限制</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="coupon-user-limit">每用户使用限制</label></th>
                    <td>
                        <input type="number" name="user_limit" id="coupon-user-limit" min="1" value="1">
                        <p class="description">每个用户最多可使用此优惠券的次数</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="coupon-claim-limit">每用户领取限制</label></th>
                    <td>
                        <input type="number" name="claim_limit" id="coupon-claim-limit" min="1" max="10" value="1">
                        <p class="description">每个用户最多可领取（含接受转赠）此优惠券的次数（1-10次）</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="coupon-is-transferable">转赠</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="is_transferable" id="coupon-is-transferable" value="1">
                            允许用户将已获得的优惠券转赠给指定用户名
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label for="coupon-issue-mode">发放方式</label></th>
                    <td>
                        <select name="issue_mode" id="coupon-issue-mode">
                            <option value="platform_claim">平台发放（活动页领取）</option>
                            <option value="redeem">用户兑换领取</option>
                            <option value="auto_grant">立即到账发放</option>
                            <option value="auto_birthday">生日自动发放</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>指定活动</th>
                    <td>
                        <div class="category-checklist activity-checklist" style="max-height: 200px; overflow-y: auto;">
                            <label class="category-item" style="display: block;">
                                <input type="radio" name="activity_id" value="0" checked>
                                <span>不绑定活动</span>
                            </label>
                            <?php
                            $site_scope_id = (function_exists('todo_current_site_id') ? (int)todo_current_site_id(false) : 0);
                            $site_join_cat = "";
                            $site_where_cat = "";
                            if ($site_scope_id > 0) {
                                $site_terms_table = $tododb->prefix . 'site_terms';
                                $site_join_cat = " LEFT JOIN {$site_terms_table} st ON (t.term_id = st.term_id AND st.site_id = " . (int)$site_scope_id . ") ";
                                $site_where_cat = " AND ((st.term_id IS NOT NULL AND st.is_visible = 1) OR NOT EXISTS (SELECT 1 FROM {$site_terms_table} stx WHERE stx.term_id = t.term_id AND stx.site_id <> 0)) ";
                            }

                            $product_categories_raw = $tododb->get_results(
                                "SELECT t.term_id as id, t.name, t.slug, tt.description, tt.parent, tt.count 
                                 FROM {$tododb->prefix}terms t 
                                 INNER JOIN {$tododb->prefix}term_taxonomy tt ON t.term_id = tt.term_id 
                                 INNER JOIN {$tododb->prefix}term_models tm ON t.term_id = tm.term_id 
                                 INNER JOIN {$tododb->prefix}models m ON tm.model_id = m.model_id 
                                 {$site_join_cat}
                                 WHERE tt.taxonomy = 'category' AND m.model_type = 'product' {$site_where_cat}
                                   AND NOT EXISTS (
                                       SELECT 1 FROM {$tododb->prefix}term_attributes
                                       WHERE term_id = t.term_id AND JSON_UNQUOTE(JSON_EXTRACT(attributes, '$.trashed')) = '1'
                                   )
                                 ORDER BY tt.parent ASC, t.name ASC"
                            );

                            if (!function_exists('todo_coupons_build_cat_tree')) {
                                function todo_coupons_build_cat_tree($cats, $parent = 0, $depth = 0) {
                                    $tree = [];
                                    foreach ($cats as $cat) {
                                        if ($cat->parent == $parent) {
                                            $cat->depth = $depth;
                                            $tree[] = $cat;
                                            $children = todo_coupons_build_cat_tree($cats, $cat->id, $depth + 1);
                                            if (!empty($children)) {
                                                $tree = array_merge($tree, $children);
                                            }
                                        }
                                    }
                                    return $tree;
                                }
                            }
                            $product_categories = todo_coupons_build_cat_tree($product_categories_raw);
                            foreach ($product_categories as $activity):
                            ?>
                            <label class="category-item" style="display: block; margin-left: <?php echo $activity->depth * 20; ?>px">
                                <input type="radio" name="activity_id" value="<?php echo $activity->id; ?>">
                                <span><?php echo esc_html(function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($activity->name) : $activity->name); ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="description">绑定活动后，优惠券有效期将自动取活动的有效期，且活动隐藏或过期后优惠券将不可用。</p>
                    </td>
                </tr>
                <tr id="auto-issue-days-row" style="display: none;">
                    <th><label for="coupon-auto-issue-days">提前天数</label></th>
                    <td>
                        <input type="number" name="auto_issue_days" id="coupon-auto-issue-days" min="0" value="3">
                        <p class="description">例如 3 表示自动发放给 3 天后过生日的会员</p>
                    </td>
                </tr>
                <tr id="target-level-row" style="display: none;">
                    <th><label for="coupon-target-level-id">用户等级</label></th>
                    <td>
                        <?php
                        $levels_table = $tododb->prefix . 'user_levels';
                        $cache_key = 'table_exists_' . md5($levels_table);
                        $levels_exists = false;
                        if (class_exists('Todo_Cache_Manager')) {
                            $levels_exists = Todo_Cache_Manager::get($cache_key, 'database', 'global');
                        }
                        if ($levels_exists === false) {
                            $levels_exists = $tododb->get_var("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1", [$levels_table]);
                            $levels_exists = !empty($levels_exists);
                            if (class_exists('Todo_Cache_Manager')) {
                                Todo_Cache_Manager::set($cache_key, $levels_exists, 86400, 'database', 'global');
                            }
                        }
                        $levels = [];
                        if ($levels_exists) {
                            $levels = $tododb->get_results("SELECT id, level_name FROM {$levels_table} WHERE is_active = 1 ORDER BY level_order ASC", [], 'ARRAY_A');
                        }
                        ?>
                        <select name="target_level_id" id="coupon-target-level-id">
                            <option value="0">全部用户</option>
                            <?php if ($levels): ?>
                                <?php foreach ($levels as $lv): ?>
                                    <option value="<?php echo (int) $lv['id']; ?>"><?php echo esc_html($lv['level_name']); ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </td>
                </tr>
                <tr class="time-row">
                    <th><label for="coupon-start-date">开始时间</label></th>
                    <td>
                        <input type="datetime-local" name="start_date" id="coupon-start-date">
                        <p class="description">优惠券生效时间，留空表示立即生效</p>
                    </td>
                </tr>
                <tr class="time-row">
                    <th><label for="coupon-end-date">结束时间</label></th>
                    <td>
                        <input type="datetime-local" name="end_date" id="coupon-end-date">
                        <p class="description">优惠券失效时间，留空表示永久有效</p>
                    </td>
                </tr>
                <tr id="status-row" style="display: none;">
                    <th><label for="coupon-status">状态</label></th>
                    <td>
                        <select name="status" id="coupon-status">
                            <option value="1">启用</option>
                            <option value="0">禁用</option>
                        </select>
                    </td>
                </tr>
            </table>
        </form>
    </div>
    <div data-slot="footer">
        <button type="button" class="button button-secondary todo-modal-cancel">取消</button>
        <button type="submit" form="coupon-form" class="button button-primary">保存</button>
    </div>
</template>

<template id="coupon-delete-modal-template" data-size="sm" data-type="danger">
    <div data-slot="title">确认删除</div>
    <div data-slot="body">
        <form method="post" id="delete-form" action="<?php echo esc_url($todo_coupons_form_action); ?>"<?php echo $todo_coupons_form_target ? ' target="' . esc_attr($todo_coupons_form_target) . '"' : ''; ?>>
            <p>确定要删除这个优惠券吗？</p>
            <?php todo_nonce_field('todo_coupon_action'); ?>
            <input type="hidden" name="action" value="delete_coupon">
            <input type="hidden" name="coupon_id" value="" id="delete-coupon-id">
        </form>
    </div>
    <div data-slot="footer">
        <button type="button" class="button button-secondary todo-modal-cancel">取消</button>
        <button type="submit" form="delete-form" class="button button-primary">确认删除</button>
    </div>
</template>

<template id="coupon-grant-modal-template" data-size="sm">
    <div data-slot="title">发放优惠券</div>
    <div data-slot="body">
        <form method="post" id="grant-form" action="<?php echo esc_url($todo_coupons_form_action); ?>"<?php echo $todo_coupons_form_target ? ' target="' . esc_attr($todo_coupons_form_target) . '"' : ''; ?>>
            <?php todo_nonce_field('todo_coupon_action'); ?>
            <input type="hidden" name="action" value="grant_coupon">
            <input type="hidden" name="coupon_id" value="" id="grant-coupon-id">
            <table class="form-table">
                <tr>
                    <th><label for="grant-user-login">用户名</label></th>
                    <td>
                        <input type="text" name="to_user_login" id="grant-user-login" class="regular-text" required>
                    </td>
                </tr>
                <tr>
                    <th><label for="grant-quantity">数量</label></th>
                    <td>
                        <input type="number" name="grant_quantity" id="grant-quantity" min="1" value="1" required>
                    </td>
                </tr>
            </table>
        </form>
    </div>
    <div data-slot="footer">
        <button type="button" class="button button-secondary todo-modal-cancel">取消</button>
        <button type="submit" form="grant-form" class="button button-primary">确认发放</button>
    </div>
</template>

<style>
    .form-table th {
        width: 150px;
    }
</style>

<script type="text/javascript">
    jQuery(document).ready(function ($) {
        // 全选功能
        $('#cb-select-all').on('change', function () {
            $('input[name="coupon_ids[]"]').prop('checked', this.checked);
        });

        $('#coupons-form').on('submit', function (e) {
            const bulkAction = String($('select[name="bulk_action"]').val() || '');
            if (!bulkAction) {
                e.preventDefault();
                TodoModal.error('请选择批量操作');
                return;
            }
            const checked = $('input[name="coupon_ids[]"]:checked').length;
            if (checked <= 0) {
                e.preventDefault();
                TodoModal.error('请选择要操作的优惠券');
                return;
            }
            if (!window.confirm('确定要执行批量操作吗？')) {
                e.preventDefault();
            }
        });
    });

    let couponModal = null;
    let couponDeleteModal = null;
    let couponGrantModal = null;

    function openCouponModal(title, onReady) {
        const modal = new TodoModal({
            templateId: 'coupon-modal-template',
            destroyOnClose: true
        });
        couponModal = modal;
        modal.onCloseCallback(function () {
            couponModal = null;
        });
        if (title) modal.setTitle(title);
        const $ctx = jQuery(modal.element);
        $ctx.find('#coupon-type').on('change', function () {
            toggleCouponType($ctx);
        });
        $ctx.find('#coupon-issue-mode').on('change', function () {
            toggleIssueMode($ctx);
        });
        $ctx.find('input[name="activity_id"]').on('change', function() {
            toggleActivityRows($ctx);
        });
        toggleCouponType($ctx);
        toggleIssueMode($ctx);
        toggleActivityRows($ctx);
        if (typeof onReady === 'function') onReady($ctx, modal);
        const formEl = $ctx.find('#coupon-form').get(0);
        if (formEl) {
            formEl.addEventListener('submit', function () {
                setTimeout(function () {
                    try { modal.close(); } catch (e) { }
                }, 0);
            }, { once: true });
        }
        modal.open();
        return modal;
    }

    function showAddCouponModal() {
        openCouponModal('添加优惠券', function ($ctx) {
            $ctx.find('#form-action').val('add_coupon');
            $ctx.find('#coupon-id').val('');
            $ctx.find('#status-row').hide();
            const form = $ctx.find('#coupon-form').get(0);
            if (form) form.reset();
            $ctx.find('#coupon-code').val('').prop('readonly', true).attr('placeholder', '系统生成');
            $ctx.find('#coupon-issue-mode').val('redeem');
            toggleIssueMode($ctx);
        });
    }

    function editCoupon(coupon) {
        openCouponModal('编辑优惠券', function ($ctx) {
            $ctx.find('#form-action').val('edit_coupon');
            $ctx.find('#coupon-id').val(coupon.id);
            $ctx.find('#status-row').show();

            $ctx.find('#coupon-code').val(coupon.code).prop('readonly', true);
            $ctx.find('#coupon-name').val(coupon.name);
            $ctx.find('#coupon-type').val((coupon.type == 2) ? 'percent' : ((coupon.type == 3) ? 'threshold' : 'fixed'));
            $ctx.find('#coupon-value').val(coupon.value);
            $ctx.find('#coupon-min-amount').val(coupon.min_amount);
            $ctx.find('#coupon-max-discount').val(coupon.max_discount);
            $ctx.find('#coupon-usage-limit').val(coupon.usage_limit);
            $ctx.find('#coupon-user-limit').val(coupon.user_limit);
            $ctx.find('#coupon-claim-limit').val(coupon.claim_limit || 1);
            $ctx.find('#coupon-status').val(coupon.status);
            $ctx.find('#coupon-is-transferable').prop('checked', coupon.is_transferable == 1);
            $ctx.find('#coupon-issue-mode').val(coupon.issue_mode || 'redeem');
            $ctx.find('#coupon-auto-issue-days').val(coupon.auto_issue_days || 3);
            $ctx.find('#coupon-target-level-id').val(coupon.target_level_id || 0);

            if (coupon.start_date) {
                const startDate = new Date(coupon.start_date);
                $ctx.find('#coupon-start-date').val(startDate.toISOString().slice(0, 16));
            }
            if (coupon.end_date) {
                const endDate = new Date(coupon.end_date);
                $ctx.find('#coupon-end-date').val(endDate.toISOString().slice(0, 16));
            }

            if (coupon.activity_id) {
                $ctx.find('input[name="activity_id"][value="' + coupon.activity_id + '"]').prop('checked', true);
            } else {
                $ctx.find('input[name="activity_id"][value="0"]').prop('checked', true);
            }

            toggleCouponType($ctx);
            toggleIssueMode($ctx);
            toggleActivityRows($ctx);
        });
    }

    function toggleActivityRows($ctx) {
        const activityId = $ctx.find('input[name="activity_id"]:checked').val();
        if (parseInt(activityId) > 0) {
            $ctx.find('.time-row').hide();
        } else {
            $ctx.find('.time-row').show();
        }
    }

    function deleteCoupon(couponId) {
        const modal = new TodoModal({
            templateId: 'coupon-delete-modal-template',
            destroyOnClose: true
        });
        couponDeleteModal = modal;
        modal.onCloseCallback(function () {
            couponDeleteModal = null;
        });
        const $ctx = jQuery(modal.element);
        $ctx.find('#delete-coupon-id').val(couponId);
        const formEl = $ctx.find('#delete-form').get(0);
        if (formEl) {
            formEl.addEventListener('submit', function () {
                setTimeout(function () {
                    try { modal.close(); } catch (e) { }
                }, 0);
            }, { once: true });
        }
        modal.open();
    }

    function grantCoupon(couponId) {
        const modal = new TodoModal({
            templateId: 'coupon-grant-modal-template',
            destroyOnClose: true
        });
        couponGrantModal = modal;
        modal.onCloseCallback(function () {
            couponGrantModal = null;
        });
        const $ctx = jQuery(modal.element);
        $ctx.find('#grant-coupon-id').val(couponId);
        const formEl = $ctx.find('#grant-form').get(0);
        if (formEl) {
            formEl.addEventListener('submit', function () {
                setTimeout(function () {
                    try { modal.close(); } catch (e) { }
                }, 0);
            }, { once: true });
        }
        modal.open();
    }

    function toggleCouponType($ctx) {
        const type = $ctx.find('#coupon-type').val();
        const valueUnit = $ctx.find('#value-unit').get(0);
        const valueDesc = $ctx.find('#value-desc').get(0);
        const maxDiscountRow = $ctx.find('#max-discount-row').get(0);
        const minAmountInput = $ctx.find('#coupon-min-amount');

        const currCode = <?php echo json_encode(todo_get_default_currency_config()['code']); ?>;

        if (type === 'percent') {
            if (valueUnit) valueUnit.textContent = '%';
            if (valueDesc) valueDesc.textContent = '百分比优惠（1-100）';
            if (maxDiscountRow) maxDiscountRow.style.display = 'block';
            $ctx.find('#coupon-value').attr('max', '100');
            if (minAmountInput && minAmountInput.length) {
                minAmountInput.removeAttr('required');
            }
        } else if (type === 'threshold') {
            if (valueUnit) valueUnit.textContent = currCode;
            if (valueDesc) valueDesc.textContent = '满减优惠（需设置最小使用金额）';
            if (maxDiscountRow) maxDiscountRow.style.display = 'none';
            $ctx.find('#coupon-value').removeAttr('max');
            if (minAmountInput && minAmountInput.length) {
                minAmountInput.attr('required', 'required');
            }
        } else {
            if (valueUnit) valueUnit.textContent = currCode;
            if (valueDesc) valueDesc.textContent = '固定优惠金额';
            if (maxDiscountRow) maxDiscountRow.style.display = 'none';
            $ctx.find('#coupon-value').removeAttr('max');
            if (minAmountInput && minAmountInput.length) {
                minAmountInput.removeAttr('required');
            }
        }
    }

    function toggleIssueMode($ctx) {
        const mode = $ctx.find('#coupon-issue-mode').val();
        const daysRow = $ctx.find('#auto-issue-days-row').get(0);
        const targetRow = $ctx.find('#target-level-row').get(0);
        if (mode === 'auto_birthday') {
            if (daysRow) daysRow.style.display = '';
            if (targetRow) targetRow.style.display = '';
        } else if (mode === 'auto_grant') {
            if (daysRow) daysRow.style.display = 'none';
            if (targetRow) targetRow.style.display = '';
        } else {
            if (daysRow) daysRow.style.display = 'none';
            if (targetRow) targetRow.style.display = 'none';
        }
    }
</script>
