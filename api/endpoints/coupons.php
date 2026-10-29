<?php
/**
 * 优惠券 API 端点
 *
 * @package TodoCMS
 * @version 1.0.0
 */
if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

function api_coupon_now_ts() {
    return (int) current_time('timestamp');
}

function api_coupon_is_started($coupon, $now_ts) {
    // 1. 检查活动限制（如果绑定了活动）
    if (isset($coupon['activity_id']) && (int)$coupon['activity_id'] > 0) {
        $activity_id = (int)$coupon['activity_id'];
        $is_hidden = get_term_meta($activity_id, 'is_hidden', true);
        if ($is_hidden) {
            return false;
        }
        $act_start = get_term_meta($activity_id, 'hidden_start', true);
        if ($act_start && trim($act_start) !== '') {
            $ts = strtotime($act_start);
            if ($ts !== false && $ts > $now_ts) {
                return false;
            }
        }
    }

    // 2. 检查优惠券自身的开始时间
    if (!isset($coupon['start_date']) || $coupon['start_date'] === null || trim((string) $coupon['start_date']) === '') {
        return true;
    }
    $ts = strtotime((string) $coupon['start_date']);
    if ($ts === false) {
        return true;
    }
    return $ts <= $now_ts;
}

function api_coupon_is_expired($coupon, $now_ts) {
    // 1. 检查活动限制（如果绑定了活动）
    if (isset($coupon['activity_id']) && (int)$coupon['activity_id'] > 0) {
        $activity_id = (int)$coupon['activity_id'];
        $is_hidden = get_term_meta($activity_id, 'is_hidden', true);
        if ($is_hidden) {
            return true;
        }
        $act_end = get_term_meta($activity_id, 'hidden_end', true);
        if ($act_end && trim($act_end) !== '') {
            $ts = strtotime($act_end);
            if ($ts !== false && $ts < $now_ts) {
                return true;
            }
        }
    }

    // 2. 检查优惠券自身的结束时间
    if (!isset($coupon['end_date']) || $coupon['end_date'] === null || trim((string) $coupon['end_date']) === '') {
        return false;
    }
    $ts = strtotime((string) $coupon['end_date']);
    if ($ts === false) {
        return false;
    }
    return $ts < $now_ts;
}

function api_format_coupon_row($row, $now_ts) {
    $type = isset($row['type']) ? (int) $row['type'] : 1;
    $value = isset($row['value']) ? (float) $row['value'] : 0.0;
    $status = !empty($row['status']) ? 1 : 0;
    $started = api_coupon_is_started($row, $now_ts);
    $expired = api_coupon_is_expired($row, $now_ts);

    $activity_id = isset($row['activity_id']) ? (int) $row['activity_id'] : 0;
    $eligible_category_ids = [];
    if ($activity_id > 0) {
        $eligible_category_ids[] = $activity_id;
        if (class_exists('Todo_Category_Manager')) {
            Todo_Category_Manager::init();
            $children = Todo_Category_Manager::get_children($activity_id, true);
            if (!empty($children)) {
                foreach ($children as $child) {
                    $eligible_category_ids[] = (int) ($child['id'] ?? $child['term_id']);
                }
            }
        }
    }

    $usage_limit = isset($row['usage_limit']) ? (int) $row['usage_limit'] : 0;
    $used_count = isset($row['used_count']) ? (int) $row['used_count'] : 0;
    $sold_out = ($usage_limit > 0 && $used_count >= $usage_limit);

    return [
        'id' => isset($row['id']) ? (int) $row['id'] : 0,
        'code' => (string) ($row['code'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'type' => $type === 2 ? 'percent' : ($type === 3 ? 'threshold' : 'fixed'),
        'value' => $value,
        'min_amount' => isset($row['min_amount']) ? (float) $row['min_amount'] : 0.0,
        'max_discount' => isset($row['max_discount']) ? (float) $row['max_discount'] : null,
        'usage_limit' => $usage_limit,
        'used_count' => $used_count,
        'user_limit' => isset($row['user_limit']) ? (int) $row['user_limit'] : 1,
        'claim_limit' => isset($row['claim_limit']) ? (int) $row['claim_limit'] : 1,
        'start_date' => $row['start_date'] ?? null,
        'end_date' => $row['end_date'] ?? null,
        'is_transferable' => !empty($row['is_transferable']) ? 1 : 0,
        'issue_mode' => isset($row['issue_mode']) && $row['issue_mode'] !== '' ? (string) $row['issue_mode'] : 'redeem',
        'auto_issue_type' => isset($row['auto_issue_type']) ? (string) $row['auto_issue_type'] : '',
        'auto_issue_days' => isset($row['auto_issue_days']) ? (int) $row['auto_issue_days'] : 0,
        'target_level_id' => isset($row['target_level_id']) ? (int) $row['target_level_id'] : 0,
        'activity_id' => $activity_id,
        'eligible_category_ids' => $eligible_category_ids,
        'is_enabled' => $status === 1,
        'is_started' => $started,
        'is_expired' => $expired,
        'is_sold_out' => $sold_out,
        'is_active' => ($status === 1 && $started && !$expired && !$sold_out),
    ];
}

function api_coupon_get_user_id_by_login($login) {
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

function api_coupon_maybe_run_auto_issue() {
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

    $now_ts = api_coupon_now_ts();
    $rows = $tododb->get_results(
        "SELECT * FROM {$coupons_table}
         WHERE status = 1 AND issue_mode = 'auto_birthday' AND auto_issue_type = 'birthday'",
        [],
        'ARRAY_A'
    );

    $users_table = $tododb->prefix . 'users';
    $has_birthday_col = $tododb->get_var("SHOW COLUMNS FROM {$users_table} LIKE 'birthday'");
    $level_records_table = $tododb->prefix . 'user_level_records';
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

    foreach ($rows as $coupon) {
        $auto_days = isset($coupon['auto_issue_days']) ? max(0, (int) $coupon['auto_issue_days']) : 0;
        $target_level_id = isset($coupon['target_level_id']) ? (int) $coupon['target_level_id'] : 0;
        $target_ts = $now_ts + ($auto_days * 86400);
        $target_md = gmdate('m-d', $target_ts);

        $user_params = [];
        $user_sql = "SELECT u.ID AS user_id
                     FROM {$users_table} u";
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
        if (!$users) {
            continue;
        }

        $coupon_id = (int) ($coupon['id'] ?? 0);
        if ($coupon_id <= 0) {
            continue;
        }

        $usage_limit = isset($coupon['usage_limit']) ? (int) $coupon['usage_limit'] : 0;
        $used_count = isset($coupon['used_count']) ? (int) $coupon['used_count'] : 0;
        if ($usage_limit > 0 && $used_count >= $usage_limit) {
            continue;
        }

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

            $user_limit = isset($coupon['user_limit']) ? (int) $coupon['user_limit'] : 1;
            if ($user_limit > 0) {
                $user_total = (int) $tododb->get_var(
                    "SELECT COUNT(*) FROM {$user_coupons_table} WHERE coupon_id = ? AND user_id = ?",
                    [$coupon_id, $uid]
                );
                if ($user_total >= $user_limit) {
                    continue;
                }
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

function api_get_coupons($params) {
    global $tododb;

    $page = isset($params['page']) ? max(1, (int) $params['page']) : 1;
    $per_page = isset($params['per_page']) ? (int) $params['per_page'] : 20;
    $per_page = max(1, min(100, $per_page));
    $offset = ($page - 1) * $per_page;

    $now_ts = api_coupon_now_ts();
    $coupons_table = $tododb->prefix . 'coupons';

    $where = ['1=1'];
    $values = [];

    $only_active = !isset($params['only_active']) || !empty($params['only_active']);
    if ($only_active) {
        $where[] = 'status = 1';
    }
    if (!empty($params['search'])) {
        $search = '%' . $tododb->esc_like((string) $params['search']) . '%';
        $where[] = '(code LIKE ? OR name LIKE ?)';
        $values[] = $search;
        $values[] = $search;
    }
    if (!empty($params['issue_mode'])) {
        $where[] = 'issue_mode = ?';
        $values[] = sanitize_text_field($params['issue_mode']);
    }

    $where_sql = implode(' AND ', $where);

    $total = (int) $tododb->get_var(
        "SELECT COUNT(*) FROM {$coupons_table} WHERE {$where_sql}",
        $values
    );

    $rows = $tododb->get_results(
        "SELECT * FROM {$coupons_table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT {$per_page} OFFSET {$offset}",
        $values,
        'ARRAY_A'
    );

    $user_id = get_current_user_id();
    $list = [];
    foreach ($rows as $row) {
        $item = api_format_coupon_row($row, $now_ts);
        $list[] = $item;
    }

    return [
        'coupons' => $list,
        'page' => $page,
        'per_page' => $per_page,
        'total' => $total,
    ];
}

function api_get_claimable_coupons($params) {
    global $tododb;

    $user_id = get_current_user_id();
    if ($user_id <= 0) {
        send_api_error(401, '未登录');
    }

    $now_ts = api_coupon_now_ts();
    $coupons_table = $tododb->prefix . 'coupons';
    $user_coupons_table = $tododb->prefix . 'user_coupons';

    $rows = $tododb->get_results(
        "SELECT * FROM {$coupons_table} WHERE status = 1 AND issue_mode = 'platform_claim' ORDER BY created_at DESC",
        [],
        'ARRAY_A'
    );

    $counts = $tododb->get_results(
        "SELECT coupon_id, COUNT(*) AS cnt
         FROM {$user_coupons_table}
         WHERE user_id = ?
         GROUP BY coupon_id",
        [$user_id],
        'ARRAY_A'
    );
    $count_map = [];
    foreach ($counts as $c) {
        $cid = isset($c['coupon_id']) ? (int) $c['coupon_id'] : 0;
        if ($cid > 0) {
            $count_map[$cid] = isset($c['cnt']) ? (int) $c['cnt'] : 0;
        }
    }

    $list = [];
    foreach ($rows as $row) {
        $item = api_format_coupon_row($row, $now_ts);
        $cid = $item['id'];
        $my_count = isset($count_map[$cid]) ? (int) $count_map[$cid] : 0;
        $claim_limit = isset($row['claim_limit']) ? (int) $row['claim_limit'] : 1;
        if ($claim_limit <= 0) $claim_limit = 1;
        $can_claim = $item['is_active'] && ($my_count < $claim_limit);
        $item['my_count'] = $my_count;
        $item['can_claim'] = $can_claim ? 1 : 0;
        $list[] = $item;
    }

    return [
        'coupons' => $list,
    ];
}

function api_claim_coupon($params) {
    global $tododb;

    $user_id = get_current_user_id();
    if ($user_id <= 0) {
        send_api_error(401, '未登录');
    }

    $coupon_id = isset($params['coupon_id']) ? (int) $params['coupon_id'] : 0;
    if ($coupon_id <= 0) {
        send_api_error(400, '缺少优惠券ID');
    }

    $coupons_table = $tododb->prefix . 'coupons';
    $user_coupons_table = $tododb->prefix . 'user_coupons';

    $coupon = $tododb->get_row("SELECT * FROM {$coupons_table} WHERE id = ? LIMIT 1", [$coupon_id], 'ARRAY_A');
    if (!$coupon) {
        send_api_error(404, '优惠券不存在');
    }
    if (empty($coupon['status'])) {
        send_api_error(400, '优惠券已禁用');
    }
    if ((string) ($coupon['issue_mode'] ?? '') !== 'platform_claim') {
        send_api_error(400, '该优惠券不支持平台领取');
    }

    $now_ts = api_coupon_now_ts();
    if (!api_coupon_is_started($coupon, $now_ts)) {
        send_api_error(400, '优惠券未开始');
    }
    if (api_coupon_is_expired($coupon, $now_ts)) {
        send_api_error(400, '优惠券已过期');
    }

    $usage_limit = isset($coupon['usage_limit']) ? (int) $coupon['usage_limit'] : 0;
    $used_count = isset($coupon['used_count']) ? (int) $coupon['used_count'] : 0;
    if ($usage_limit > 0 && $used_count >= $usage_limit) {
        send_api_error(400, '优惠券已领完');
    }

    $claim_limit = isset($coupon['claim_limit']) ? (int) $coupon['claim_limit'] : 1;
    if ($claim_limit <= 0) $claim_limit = 1;
    $user_total = (int) $tododb->get_var(
        "SELECT COUNT(*) FROM {$user_coupons_table} WHERE coupon_id = ? AND user_id = ?",
        [$coupon_id, $user_id]
    );
    if ($user_total >= $claim_limit) {
        send_api_error(400, '已达到每用户领取限制');
    }

    $tododb->query('START TRANSACTION');
    try {
        $affected = $tododb->query(
            "UPDATE {$coupons_table}
             SET used_count = used_count + 1
             WHERE id = ?
               AND status = 1
               AND (usage_limit IS NULL OR usage_limit = 0 OR used_count < usage_limit)",
            [$coupon_id]
        );
        if (!$affected) {
            $tododb->query('ROLLBACK');
            send_api_error(400, '优惠券已领完');
        }

        $ok = $tododb->insert($user_coupons_table, [
            'coupon_id' => $coupon_id,
            'user_id' => $user_id,
            'status' => 'unused',
            'source' => 'platform_claim',
            'from_user_id' => null,
            'assigned_at' => current_time('mysql'),
        ]);
        if (!$ok) {
            $tododb->query('ROLLBACK');
            send_api_error(500, '领取失败');
        }

        $user_coupon_id = (int) $tododb->insert_id();
        $tododb->query('COMMIT');
        return [
            'user_coupon_id' => $user_coupon_id,
        ];
    } catch (Throwable $e) {
        $tododb->query('ROLLBACK');
        send_api_error(500, $e->getMessage());
    }
}

function api_redeem_coupon($params) {
    global $tododb;

    $user_id = get_current_user_id();
    if ($user_id <= 0) {
        send_api_error(401, '未登录');
    }

    $code = isset($params['code']) ? trim((string) $params['code']) : '';
    if ($code === '') {
        send_api_error(400, '缺少优惠券兑换码');
    }

    $coupons_table = $tododb->prefix . 'coupons';
    $user_coupons_table = $tododb->prefix . 'user_coupons';

    $coupon = $tododb->get_row("SELECT * FROM {$coupons_table} WHERE code = ? LIMIT 1", [$code], 'ARRAY_A');
    if (!$coupon) {
        send_api_error(404, '优惠券不存在');
    }
    if (empty($coupon['status'])) {
        send_api_error(400, '优惠券已禁用');
    }
    if ((string) ($coupon['issue_mode'] ?? 'redeem') !== 'redeem') {
        send_api_error(400, '该优惠券不支持兑换领取');
    }

    $now_ts = api_coupon_now_ts();
    if (!api_coupon_is_started($coupon, $now_ts)) {
        send_api_error(400, '优惠券未开始');
    }
    if (api_coupon_is_expired($coupon, $now_ts)) {
        send_api_error(400, '优惠券已过期');
    }

    $usage_limit = isset($coupon['usage_limit']) ? (int) $coupon['usage_limit'] : 0;
    $used_count = isset($coupon['used_count']) ? (int) $coupon['used_count'] : 0;
    if ($usage_limit > 0 && $used_count >= $usage_limit) {
        send_api_error(400, '优惠券已领完');
    }

    $claim_limit = isset($coupon['claim_limit']) ? (int) $coupon['claim_limit'] : 1;
    if ($claim_limit <= 0) $claim_limit = 1;
    $user_total = (int) $tododb->get_var(
        "SELECT COUNT(*) FROM {$user_coupons_table} WHERE coupon_id = ? AND user_id = ?",
        [(int) $coupon['id'], $user_id]
    );
    if ($user_total >= $claim_limit) {
        send_api_error(400, '已达到每用户领取限制');
    }

    $coupon_id = (int) $coupon['id'];
    $tododb->query('START TRANSACTION');
    try {
        $affected = $tododb->query(
            "UPDATE {$coupons_table}
             SET used_count = used_count + 1
             WHERE id = ?
               AND status = 1
               AND (usage_limit IS NULL OR usage_limit = 0 OR used_count < usage_limit)",
            [$coupon_id]
        );
        if (!$affected) {
            $tododb->query('ROLLBACK');
            send_api_error(400, '优惠券已领完');
        }

        $ok = $tododb->insert($user_coupons_table, [
            'coupon_id' => $coupon_id,
            'user_id' => $user_id,
            'status' => 'unused',
            'source' => 'redeem',
            'from_user_id' => null,
            'assigned_at' => current_time('mysql'),
        ]);
        if (!$ok) {
            $tododb->query('ROLLBACK');
            send_api_error(500, '领取失败');
        }

        $user_coupon_id = (int) $tododb->insert_id();
        $tododb->query('COMMIT');

        return [
            'user_coupon_id' => $user_coupon_id,
            'coupon' => api_format_coupon_row($coupon, api_coupon_now_ts()),
        ];
    } catch (Throwable $e) {
        $tododb->query('ROLLBACK');
        send_api_error(500, $e->getMessage());
    }
}

function api_transfer_coupon($params) {
    global $tododb;

    $user_id = get_current_user_id();
    if ($user_id <= 0) {
        send_api_error(401, '未登录');
    }

    $user_coupon_id = isset($params['user_coupon_id']) ? (int) $params['user_coupon_id'] : 0;
    $to_login = isset($params['to_user_login']) ? (string) $params['to_user_login'] : '';
    if ($user_coupon_id <= 0 || trim($to_login) === '') {
        send_api_error(400, '缺少转赠参数');
    }

    $to_user_id = api_coupon_get_user_id_by_login($to_login);
    if ($to_user_id <= 0) {
        send_api_error(404, '目标用户不存在');
    }
    if ($to_user_id === $user_id) {
        send_api_error(400, '不能转赠给自己');
    }

    $user_coupons_table = $tododb->prefix . 'user_coupons';
    $coupons_table = $tododb->prefix . 'coupons';

    $uc = $tododb->get_row(
        "SELECT * FROM {$user_coupons_table} WHERE id = ? AND user_id = ? LIMIT 1",
        [$user_coupon_id, $user_id],
        'ARRAY_A'
    );
    if (!$uc) {
        send_api_error(404, '优惠券不存在或不属于当前用户');
    }
    if ((string) ($uc['status'] ?? '') !== 'unused') {
        send_api_error(400, '该优惠券无法转赠');
    }

    $coupon = $tododb->get_row(
        "SELECT * FROM {$coupons_table} WHERE id = ? LIMIT 1",
        [(int) $uc['coupon_id']],
        'ARRAY_A'
    );
    if (!$coupon) {
        send_api_error(404, '优惠券已不存在');
    }
    if (empty($coupon['is_transferable'])) {
        send_api_error(400, '该优惠券不支持转赠');
    }

    $claim_limit = isset($coupon['claim_limit']) ? (int) $coupon['claim_limit'] : 1;
    if ($claim_limit <= 0) $claim_limit = 1;
    $recipient_total = (int) $tododb->get_var(
        "SELECT COUNT(*) FROM {$user_coupons_table} WHERE coupon_id = ? AND user_id = ?",
        [(int) $coupon['id'], $to_user_id]
    );
    if ($recipient_total >= $claim_limit) {
        send_api_error(400, '接收方已达到该优惠券的领取限制');
    }

    $ok = $tododb->update($user_coupons_table, [
        'user_id' => $to_user_id,
        'from_user_id' => $user_id,
        'source' => 'transfer',
        'updated_at' => current_time('mysql'),
    ], ['id' => $user_coupon_id]);

    if ($ok === false) {
        send_api_error(500, '转赠失败');
    }

    return [
        'message' => '转赠成功',
        'user_coupon_id' => $user_coupon_id,
        'to_user_id' => $to_user_id,
    ];
}

function api_get_my_coupons($params) {
    global $tododb;

    $user_id = get_current_user_id();
    if ($user_id <= 0) {
        send_api_error(401, '未登录');
    }

    $status = isset($params['status']) ? strtolower(trim((string) $params['status'])) : 'available';
    if ($status === '') {
        $status = 'available';
    }

    api_coupon_maybe_run_auto_issue();

    $now_ts = api_coupon_now_ts();
    $coupons_table = $tododb->prefix . 'coupons';
    $user_coupons_table = $tododb->prefix . 'user_coupons';

    $rows = $tododb->get_results(
        "SELECT c.*, uc.id AS uc_id, uc.status AS uc_status, uc.source AS uc_source, uc.from_user_id AS uc_from_user_id, uc.assigned_at AS uc_assigned_at, uc.used_at AS uc_used_at, uc.order_id AS uc_order_id
         FROM {$user_coupons_table} uc
         INNER JOIN {$coupons_table} c ON c.id = uc.coupon_id
         WHERE uc.user_id = ?
         ORDER BY uc.assigned_at DESC, uc.id DESC",
        [$user_id],
        'ARRAY_A'
    );

    $list = [];
    foreach ($rows as $row) {
        $coupon = api_format_coupon_row($row, $now_ts);
        $uc_status = isset($row['uc_status']) ? (string) $row['uc_status'] : 'unused';

        $my_status = 'available';
        if ($uc_status === 'used') {
            $my_status = 'used';
        } else {
            if (!$coupon['is_enabled'] || $coupon['is_expired'] || !$coupon['is_started']) {
                $my_status = 'expired';
            }
        }

        if ($status !== 'all' && $status !== $my_status) {
            continue;
        }

        $coupon['user_coupon'] = [
            'id' => isset($row['uc_id']) ? (int) $row['uc_id'] : 0,
            'status' => $uc_status,
            'source' => isset($row['uc_source']) ? (string) $row['uc_source'] : '',
            'from_user_id' => isset($row['uc_from_user_id']) ? (int) $row['uc_from_user_id'] : null,
            'assigned_at' => $row['uc_assigned_at'] ?? null,
            'used_at' => $row['uc_used_at'] ?? null,
            'order_id' => isset($row['uc_order_id']) ? (int) $row['uc_order_id'] : null,
            'my_status' => $my_status,
        ];
        $list[] = $coupon;
    }

    return [
        'coupons' => $list,
        'status' => $status,
    ];
}

register_api_route('coupons', 'GET', 'api_get_coupons');
register_api_route('me/coupons', 'GET', 'api_get_my_coupons');
register_api_route('coupons/redeem', 'POST', 'api_redeem_coupon');
register_api_route('coupons/transfer', 'POST', 'api_transfer_coupon');
register_api_route('coupons/claimable', 'GET', 'api_get_claimable_coupons');
register_api_route('coupons/claim', 'POST', 'api_claim_coupon');
