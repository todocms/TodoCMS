<?php
if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

function api_get_addresses($params) {
    global $tododb;

    $user_id = get_current_user_id();
    if (!$user_id) {
        send_api_error(401, '未登录');
    }

    $page = isset($params['page']) ? max(1, intval($params['page'])) : 1;
    $per_page = isset($params['per_page']) ? max(1, min(100, intval($params['per_page']))) : 20;
    $offset = ($page - 1) * $per_page;

    $table = TABLE_PREFIX . 'member_addresses';

    $rows = $tododb->get_results(
        "SELECT * FROM {$table} WHERE user_id = ? ORDER BY is_default DESC, updated_at DESC, id DESC LIMIT {$per_page} OFFSET {$offset}",
        [$user_id],
        'ARRAY_A'
    );

    $total = (int) ($tododb->get_var("SELECT COUNT(*) FROM {$table} WHERE user_id = ?", [$user_id]) ?: 0);

    $addresses = [];
    foreach (($rows ?: []) as $row) {
        $addresses[] = api_format_address_row($row);
    }

    return [
        'addresses' => $addresses,
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => $per_page > 0 ? (int) ceil($total / $per_page) : 0
    ];
}

function api_get_default_address($params) {
    global $tododb;

    $user_id = get_current_user_id();
    if (!$user_id) {
        send_api_error(401, '未登录');
    }

    api_addresses_ensure_user_has_default((int) $user_id);

    $table = TABLE_PREFIX . 'member_addresses';
    $row = $tododb->get_row(
        "SELECT * FROM {$table} WHERE user_id = ? AND is_default = 1 ORDER BY created_at ASC, id ASC LIMIT 1",
        [$user_id],
        'ARRAY_A'
    );

    return [
        'address' => $row ? api_format_address_row($row) : null
    ];
}

function api_addresses_ensure_user_has_default($user_id) {
    global $tododb;

    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return 0;
    }

    $table = TABLE_PREFIX . 'member_addresses';

    $defaults = $tododb->get_results(
        "SELECT id FROM {$table} WHERE user_id = ? AND is_default = 1 ORDER BY created_at ASC, id ASC",
        [$user_id],
        'ARRAY_A'
    );

    if (!empty($defaults)) {
        $keep_id = (int) ($defaults[0]['id'] ?? 0);
        if ($keep_id > 0 && count($defaults) > 1) {
            $tododb->query("UPDATE {$table} SET is_default = 0 WHERE user_id = ? AND id <> ?", [$user_id, $keep_id]);
            $tododb->update($table, ['is_default' => 1, 'updated_at' => current_time('mysql')], ['user_id' => $user_id, 'id' => $keep_id]);
        }
        return $keep_id;
    }

    $first_id = (int) ($tododb->get_var(
        "SELECT id FROM {$table} WHERE user_id = ? ORDER BY created_at ASC, id ASC LIMIT 1",
        [$user_id]
    ) ?: 0);

    if ($first_id <= 0) {
        return 0;
    }

    $tododb->query("UPDATE {$table} SET is_default = 0 WHERE user_id = ?", [$user_id]);
    $tododb->update($table, ['is_default' => 1, 'updated_at' => current_time('mysql')], ['user_id' => $user_id, 'id' => $first_id]);

    return $first_id;
}

function api_get_address($params) {
    global $tododb;

    $user_id = get_current_user_id();
    if (!$user_id) {
        send_api_error(401, '未登录');
    }

    if (!isset($params['id'])) {
        send_api_error(400, '缺少地址ID');
    }

    $address_id = intval($params['id']);
    $table = TABLE_PREFIX . 'member_addresses';

    $row = $tododb->get_row(
        "SELECT * FROM {$table} WHERE id = ? AND user_id = ? LIMIT 1",
        [$address_id, $user_id],
        'ARRAY_A'
    );

    if (!$row) {
        send_api_error(404, '地址不存在');
    }

    return [
        'address' => api_format_address_row($row)
    ];
}

function api_create_address($params) {
    global $tododb;

    $user_id = get_current_user_id();
    if (!$user_id) {
        send_api_error(401, '未登录');
    }

    $data = api_normalize_address_payload($params);
    api_validate_address_payload($data);

    $table = TABLE_PREFIX . 'member_addresses';

    $is_default = !empty($data['is_default']) ? 1 : 0;
    $existing_any = (int) ($tododb->get_var("SELECT id FROM {$table} WHERE user_id = ? LIMIT 1", [$user_id]) ?: 0);
    if ($existing_any <= 0) {
        $is_default = 1;
    }
    if ($is_default) {
        $tododb->query("UPDATE {$table} SET is_default = 0 WHERE user_id = ?", [$user_id]);
    }

    $insert = [
        'user_id' => (int) $user_id,
        'name' => $data['recipient_name'],
        'phone' => $data['recipient_phone'],
        'province' => $data['province'],
        'city' => $data['city'],
        'district' => $data['district'],
        'address' => $data['detailed_address'],
        'zipcode' => $data['postal_code'],
        'tag' => $data['address_label'],
        'is_default' => $is_default,
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql')
    ];

    $tododb->insert($table, $insert);
    $address_id = (int) $tododb->insert_id();

    api_addresses_ensure_user_has_default((int) $user_id);

    return [
        'id' => $address_id,
        'message' => '已创建'
    ];
}

function api_update_address($params) {
    global $tododb;

    $user_id = get_current_user_id();
    if (!$user_id) {
        send_api_error(401, '未登录');
    }

    if (!isset($params['id'])) {
        send_api_error(400, '缺少地址ID');
    }
    $address_id = intval($params['id']);

    $table = TABLE_PREFIX . 'member_addresses';
    $existing = $tododb->get_row(
        "SELECT id, is_default FROM {$table} WHERE id = ? AND user_id = ? LIMIT 1",
        [$address_id, $user_id]
    );
    if (!$existing) {
        send_api_error(404, '地址不存在');
    }

    $data = api_normalize_address_payload($params);
    api_validate_address_payload($data);

    $is_default = !empty($data['is_default']) ? 1 : 0;
    if ($is_default !== 1 && !empty($existing->is_default)) {
        $is_default = 1;
    }
    if ($is_default) {
        $tododb->query("UPDATE {$table} SET is_default = 0 WHERE user_id = ?", [$user_id]);
    }

    $update = [
        'name' => $data['recipient_name'],
        'phone' => $data['recipient_phone'],
        'province' => $data['province'],
        'city' => $data['city'],
        'district' => $data['district'],
        'address' => $data['detailed_address'],
        'zipcode' => $data['postal_code'],
        'tag' => $data['address_label'],
        'is_default' => $is_default,
        'updated_at' => current_time('mysql')
    ];

    $tododb->update($table, $update, ['id' => $address_id, 'user_id' => (int) $user_id]);

    api_addresses_ensure_user_has_default((int) $user_id);

    return [
        'id' => $address_id,
        'message' => '已更新'
    ];
}

function api_delete_address($params) {
    global $tododb;

    $user_id = get_current_user_id();
    if (!$user_id) {
        send_api_error(401, '未登录');
    }

    if (!isset($params['id'])) {
        send_api_error(400, '缺少地址ID');
    }
    $address_id = intval($params['id']);

    $table = TABLE_PREFIX . 'member_addresses';
    $row = $tododb->get_row(
        "SELECT id, is_default FROM {$table} WHERE id = ? AND user_id = ? LIMIT 1",
        [$address_id, $user_id],
        'ARRAY_A'
    );
    if (!$row) {
        send_api_error(404, '地址不存在');
    }
    if (!empty($row['is_default'])) {
        send_api_error(400, '默认地址不可删除，请先设置其他地址为默认');
    }
    $tododb->query("DELETE FROM {$table} WHERE id = ? AND user_id = ?", [$address_id, $user_id]);
    api_addresses_ensure_user_has_default((int) $user_id);

    return [
        'message' => '已删除'
    ];
}

function api_normalize_address_payload($params) {
    $recipient_name = '';
    $recipient_phone = '';
    $province = '';
    $city = '';
    $district = '';
    $detailed_address = '';

    if (isset($params['recipient_name'])) {
        $recipient_name = sanitize_text_field($params['recipient_name']);
    } elseif (isset($params['name'])) {
        $recipient_name = sanitize_text_field($params['name']);
    }

    if (isset($params['recipient_phone'])) {
        $recipient_phone = sanitize_text_field($params['recipient_phone']);
    } elseif (isset($params['phone'])) {
        $recipient_phone = sanitize_text_field($params['phone']);
    }

    if (isset($params['province'])) {
        $province = sanitize_text_field($params['province']);
    }
    if (isset($params['city'])) {
        $city = sanitize_text_field($params['city']);
    }
    if (isset($params['district'])) {
        $district = sanitize_text_field($params['district']);
    }
    if (isset($params['detailed_address'])) {
        $detailed_address = sanitize_textarea_field($params['detailed_address']);
    } elseif (isset($params['detail'])) {
        $detailed_address = sanitize_textarea_field($params['detail']);
    } elseif (isset($params['address'])) {
        $detailed_address = sanitize_textarea_field($params['address']);
    }

    if (isset($params['region']) && is_array($params['region']) && count($params['region']) >= 3) {
        $province = $province ?: sanitize_text_field($params['region'][0]);
        $city = $city ?: sanitize_text_field($params['region'][1]);
        $district = $district ?: sanitize_text_field($params['region'][2]);
    }

    return [
        'recipient_name' => $recipient_name,
        'recipient_phone' => $recipient_phone,
        'province' => $province,
        'city' => $city,
        'district' => $district,
        'detailed_address' => $detailed_address,
        'postal_code' => isset($params['postal_code']) ? sanitize_text_field($params['postal_code']) : (isset($params['zipcode']) ? sanitize_text_field($params['zipcode']) : ''),
        'address_label' => isset($params['address_label']) ? sanitize_text_field($params['address_label']) : (isset($params['tag']) ? sanitize_text_field($params['tag']) : ''),
        'is_default' => !empty($params['is_default']) || (!empty($params['isDefault']))
    ];
}

function api_validate_address_payload($data) {
    $required = ['recipient_name', 'recipient_phone', 'province', 'city', 'district', 'detailed_address'];
    foreach ($required as $k) {
        if (!isset($data[$k]) || trim((string) $data[$k]) === '') {
            send_api_error(400, '请填写完整的收货地址');
        }
    }
}

function api_format_address_row($row) {
    $address = [
        'id' => isset($row['id']) ? (int) $row['id'] : 0,
        'recipient_name' => $row['name'] ?? '',
        'recipient_phone' => $row['phone'] ?? '',
        'province' => $row['province'] ?? '',
        'city' => $row['city'] ?? '',
        'district' => $row['district'] ?? '',
        'detailed_address' => $row['address'] ?? '',
        'postal_code' => $row['zipcode'] ?? '',
        'address_label' => $row['tag'] ?? '',
        'is_default' => !empty($row['is_default']) ? 1 : 0
    ];

    if (function_exists('todo_regions_find_name')) {
        $address['province_name'] = todo_regions_find_name($address['province']);
        $address['city_name'] = todo_regions_find_name($address['city']);
        $address['district_name'] = todo_regions_find_name($address['district']);
    }

    return $address;
}

register_api_route('addresses', 'GET', 'api_get_addresses');
register_api_route('addresses/default', 'GET', 'api_get_default_address');
register_api_route('addresses', 'POST', 'api_create_address');
register_api_route('addresses/([0-9]+)', 'GET', 'api_get_address');
register_api_route('addresses/([0-9]+)', 'PUT', 'api_update_address');
register_api_route('addresses/([0-9]+)', 'DELETE', 'api_delete_address');
