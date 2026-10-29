<?php
if (!defined('ABSPATH')) {
    define('ABSPATH', rtrim(dirname(dirname(dirname(__FILE__))), '/') . '/');
}

require_once ABSPATH . 'vendor/autoload.php';
require_once ABSPATH . 'config.php';

if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}

require_once ABSPATH . 'core/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    if (class_exists('Todo_Session_Manager')) {
        Todo_Session_Manager::configure_php_session('frontend');
    }
    @session_start();
}

if (
    empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'
) {
    todo_send_json_error('Direct access denied', 403);
}

$token_aliases = [
    '_ajax_nonce' => '_todononce',
    '_csrf_token' => '_todononce',
];
foreach ($token_aliases as $from => $to) {
    if (!isset($_POST[$to]) && isset($_POST[$from]) && $_POST[$from] !== '') {
        $_POST[$to] = $_POST[$from];
    }
    if (!isset($_GET[$to]) && isset($_GET[$from]) && $_GET[$from] !== '') {
        $_GET[$to] = $_GET[$from];
    }
}

$action = sanitize_text_field($_REQUEST['action'] ?? '');
if ($action === '') {
    todo_send_json_error(__('缺少 action 参数'), 400);
}

if (!class_exists('Todo_Security_Manager') || !Todo_Security_Manager::verify_request_csrf('address_action', '_todononce')) {
    todo_send_json_error(__('安全验证失败'), 403);
}

if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
    todo_send_json_error(__('请先登录'), 401);
}

// PRG 防重复提交守卫（仅对携带 _submit_key 的 POST 生效）
Todo_PRG_Guard::guard_endpoint('address_' . $action, 30);

switch ($action) {
    case 'get_address':
        todo_handle_get_address();
        break;
    case 'save_address':
        todo_handle_save_address();
        break;
    case 'delete_address':
        todo_handle_delete_address();
        break;
    case 'set_default_address':
        todo_handle_set_default_address();
        break;
    default:
        todo_send_json_error(__('无效的操作'), 400);
}

function todo_addresses_get_current_user_id()
{
    if (function_exists('todo_get_current_user_id')) {
        return (int) todo_get_current_user_id();
    }
    if (function_exists('get_current_user_id')) {
        return (int) get_current_user_id();
    }
    return 0;
}

function todo_format_member_address_row($row)
{
    $province = $row['province'] ?? '';
    $city = $row['city'] ?? '';
    $district = $row['district'] ?? '';

    $has_delimiter = (strpos($province, ' | ') !== false);
    $intl_country = '';
    $intl_state = '';

    if ($has_delimiter) {
        $p_parts = explode(' | ', $province, 2);
        $intl_country = trim($p_parts[0]);
        $intl_state = trim($p_parts[1] ?? '');
        $region_type = 'intl';
    } else {
        $region_type = 'cn';
    }

    $data = [
        'address_id' => isset($row['id']) ? (int) $row['id'] : 0,
        'recipient_name' => $row['name'] ?? '',
        'recipient_phone' => $row['phone'] ?? '',
        'province_id' => $province,
        'city_id' => $city,
        'district_id' => $district,
        'detailed_address' => $row['address'] ?? '',
        'postal_code' => $row['zipcode'] ?? '',
        'address_label' => $row['tag'] ?? '',
        'is_default' => !empty($row['is_default']) ? 1 : 0,
        'region_type' => $region_type,
        'intl_country' => $intl_country,
        'intl_state' => $intl_state,
    ];

    if (function_exists('todo_regions_find_name')) {
        $p_name = todo_regions_find_name($province);
        $c_name = todo_regions_find_name($city);
        $d_name = todo_regions_find_name($district);
        $data['province_name'] = !empty($p_name) ? $p_name : $province;
        $data['city_name'] = !empty($c_name) ? $c_name : $city;
        $data['district_name'] = !empty($d_name) ? $d_name : $district;
    } else {
        $data['province_name'] = $province;
        $data['city_name'] = $city;
        $data['district_name'] = $district;
    }

    return $data;
}

function todo_addresses_ensure_user_has_default($user_id)
{
    global $tododb;

    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return 0;
    }

    $table = $tododb->prefix . 'member_addresses';

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

function todo_handle_get_address()
{
    global $tododb;

    $user_id = todo_addresses_get_current_user_id();
    if ($user_id <= 0) {
        todo_send_json_error(__('请先登录'), 401);
    }

    $address_id = (int) ($_GET['id'] ?? ($_GET['address_id'] ?? 0));
    if ($address_id <= 0) {
        todo_send_json_error(__('地址ID不能为空'), 400);
    }

    $table = $tododb->prefix . 'member_addresses';
    $row = $tododb->get_row(
        "SELECT * FROM {$table} WHERE id = ? AND user_id = ? LIMIT 1",
        [$address_id, $user_id],
        'ARRAY_A'
    );
    if (!$row) {
        todo_send_json_error(__('地址不存在或无权限访问'), 404);
    }

    todo_send_json_success(todo_format_member_address_row($row));
}

function todo_handle_save_address()
{
    global $tododb;

    $user_id = todo_addresses_get_current_user_id();
    if ($user_id <= 0) {
        todo_send_json_error(__('请先登录'), 401);
    }

    $address_id = (int) ($_POST['address_id'] ?? 0);
    $recipient_name = sanitize_text_field($_POST['recipient_name'] ?? '');
    $recipient_phone = sanitize_text_field($_POST['recipient_phone'] ?? '');
    $province = sanitize_text_field($_POST['province_id'] ?? ($_POST['province'] ?? ''));
    $city = sanitize_text_field($_POST['city_id'] ?? ($_POST['city'] ?? ''));
    $district = sanitize_text_field($_POST['district_id'] ?? ($_POST['district'] ?? ''));
    $detailed_address = sanitize_textarea_field($_POST['detailed_address'] ?? ($_POST['address'] ?? ''));
    $postal_code = sanitize_text_field($_POST['postal_code'] ?? ($_POST['zipcode'] ?? ''));
    $address_label = sanitize_text_field($_POST['address_label'] ?? ($_POST['tag'] ?? ''));
    $is_default = !empty($_POST['is_default']) ? 1 : 0;

    $region_type = sanitize_text_field($_POST['address_region_type'] ?? ($_POST['region_type'] ?? 'cn'));

    if ($region_type === 'intl') {
        $country = sanitize_text_field($_POST['intl_country'] ?? '');
        $state = sanitize_text_field($_POST['intl_state'] ?? '');
        $city = sanitize_text_field($_POST['intl_city'] ?? '');
        $detailed_address = sanitize_textarea_field($_POST['intl_address'] ?? '');
        $postal_code = sanitize_text_field($_POST['intl_zipcode'] ?? '');
        $province = $country ? ($state ? ($country . ' | ' . $state) : $country) : $state;
        $district = '';

        if ($recipient_name === '' || $recipient_phone === '' || $country === '' || $city === '' || $detailed_address === '') {
            todo_send_json_error(__('请填写完整的国际收货地址'), 400);
        }
    } else {
        if ($recipient_name === '' || $recipient_phone === '' || $province === '' || $city === '' || $district === '' || $detailed_address === '') {
            todo_send_json_error(__('请填写完整的收货地址'), 400);
        }
    }

    $table = $tododb->prefix . 'member_addresses';
    if ($address_id <= 0) {
        $existing_any = (int) ($tododb->get_var("SELECT id FROM {$table} WHERE user_id = ? LIMIT 1", [$user_id]) ?: 0);
        if ($existing_any <= 0) {
            $is_default = 1;
        }
    } else {
        if ($is_default !== 1) {
            $was_default = (int) ($tododb->get_var("SELECT is_default FROM {$table} WHERE id = ? AND user_id = ? LIMIT 1", [$address_id, $user_id]) ?: 0);
            if ($was_default === 1) {
                $is_default = 1;
            }
        }
    }

    if ($is_default) {
        $tododb->query("UPDATE {$table} SET is_default = 0 WHERE user_id = ?", [$user_id]);
    }

    if ($address_id > 0) {
        $existing = $tododb->get_var("SELECT id FROM {$table} WHERE id = ? AND user_id = ? LIMIT 1", [$address_id, $user_id]);
        if (!$existing) {
            todo_send_json_error(__('地址不存在或无权限访问'), 404);
        }

        $tododb->update(
            $table,
            [
                'name' => $recipient_name,
                'phone' => $recipient_phone,
                'province' => $province,
                'city' => $city,
                'district' => $district,
                'address' => $detailed_address,
                'zipcode' => $postal_code,
                'tag' => $address_label,
                'is_default' => $is_default,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $address_id, 'user_id' => $user_id]
        );

        todo_addresses_ensure_user_has_default($user_id);

        $row = $tododb->get_row(
            "SELECT * FROM {$table} WHERE id = ? AND user_id = ? LIMIT 1",
            [$address_id, $user_id],
            'ARRAY_A'
        );
        todo_send_json_success(['id' => $address_id, 'address' => $row ? todo_format_member_address_row($row) : null]);
    }

    $tododb->insert(
        $table,
        [
            'user_id' => $user_id,
            'name' => $recipient_name,
            'phone' => $recipient_phone,
            'province' => $province,
            'city' => $city,
            'district' => $district,
            'address' => $detailed_address,
            'zipcode' => $postal_code,
            'tag' => $address_label,
            'is_default' => $is_default,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]
    );
    $new_id = (int) $tododb->insert_id();

    todo_addresses_ensure_user_has_default($user_id);

    $row = $tododb->get_row(
        "SELECT * FROM {$table} WHERE id = ? AND user_id = ? LIMIT 1",
        [$new_id, $user_id],
        'ARRAY_A'
    );
    todo_send_json_success(['id' => $new_id, 'address' => $row ? todo_format_member_address_row($row) : null]);
}

function todo_handle_delete_address()
{
    global $tododb;

    $user_id = todo_addresses_get_current_user_id();
    if ($user_id <= 0) {
        todo_send_json_error(__('请先登录'), 401);
    }

    $address_id = (int) ($_POST['address_id'] ?? 0);
    if ($address_id <= 0) {
        todo_send_json_error(__('地址ID不能为空'), 400);
    }

    $table = $tododb->prefix . 'member_addresses';
    $row = $tododb->get_row(
        "SELECT id, is_default FROM {$table} WHERE id = ? AND user_id = ? LIMIT 1",
        [$address_id, $user_id],
        'ARRAY_A'
    );
    if (!$row) {
        todo_send_json_error(__('地址不存在或无权限访问'), 404);
    }
    if (!empty($row['is_default'])) {
        todo_send_json_error(__('默认地址不可删除，请先设置其他地址为默认'), 400);
    }
    $tododb->query("DELETE FROM {$table} WHERE id = ? AND user_id = ?", [$address_id, $user_id]);
    todo_addresses_ensure_user_has_default($user_id);

    todo_send_json_success(['id' => $address_id]);
}

function todo_handle_set_default_address()
{
    global $tododb;

    $user_id = todo_addresses_get_current_user_id();
    if ($user_id <= 0) {
        todo_send_json_error(__('请先登录'), 401);
    }

    $address_id = (int) ($_POST['address_id'] ?? 0);
    if ($address_id <= 0) {
        todo_send_json_error(__('地址ID不能为空'), 400);
    }

    $table = $tododb->prefix . 'member_addresses';
    $existing = $tododb->get_var("SELECT id FROM {$table} WHERE id = ? AND user_id = ? LIMIT 1", [$address_id, $user_id]);
    if (!$existing) {
        todo_send_json_error(__('地址不存在或无权限访问'), 404);
    }

    $tododb->query("UPDATE {$table} SET is_default = 0 WHERE user_id = ?", [$user_id]);
    $tododb->update($table, ['is_default' => 1, 'updated_at' => current_time('mysql')], ['id' => $address_id, 'user_id' => $user_id]);
    todo_addresses_ensure_user_has_default($user_id);

    todo_send_json_success(['id' => $address_id]);
}
