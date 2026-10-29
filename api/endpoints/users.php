<?php
/**
 * 用户API端点
 *
 * 提供用户相关的API端点
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

/**
 * 获取用户列表
 *
 * @param array $params 请求参数
 * @return array 用户列表
 */
function api_get_users($params) {
    global $tododb;

    // ── 权限校验：仅管理员可查看用户列表 ──
    $current_uid = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    if (!$current_uid || !current_user_can('manage_users')) {
        send_api_error(403, '权限不足：需要管理员权限');
    }

    // 默认参数
    $defaults = [
        'page' => 1,
        'per_page' => 10,
        'role' => '',
        'search' => '',
        'orderby' => 'user_registered',
        'order' => 'DESC'
    ];
    
    // 合并参数
    $args = array_merge($defaults, $params);
    
    // 验证参数
    $args['page'] = max(1, intval($args['page']));
    $args['per_page'] = max(1, min(100, intval($args['per_page'])));
    $args['order'] = in_array(strtoupper($args['order']), ['ASC', 'DESC']) ? strtoupper($args['order']) : 'DESC';
    
    // 构建查询
    $query = "SELECT * FROM " . TABLE_PREFIX . "users WHERE 1=1";
    $params = [];
    
    // 添加搜索条件
    if (!empty($args['search'])) {
        $query .= " AND (user_login LIKE ? OR user_email LIKE ? OR display_name LIKE ?)";
        $search_term = '%' . $args['search'] . '%';
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    // 添加角色条件
    if (!empty($args['role'])) {
        $query .= " AND ID IN (SELECT user_id FROM " . TABLE_PREFIX . "user_meta WHERE meta_key = 'user_role' AND meta_value = ?)";
        $params[] = $args['role'];
    }
    
    // 添加排序
    $valid_orderby = [
        'user_registered' => 'user_registered',
        'registered' => 'user_registered',
        'ID' => 'ID',
        'id' => 'ID',
        'username' => 'user_login',
        'user_login' => 'user_login',
        'email' => 'user_email',
        'user_email' => 'user_email',
        'display_name' => 'display_name'
    ];
    $orderby_field = isset($valid_orderby[$args['orderby']]) ? $valid_orderby[$args['orderby']] : 'user_registered';
    $order_dir = in_array($args['order'], ['ASC', 'DESC']) ? $args['order'] : 'DESC';
    $query .= " ORDER BY {$orderby_field} {$order_dir}";
    
    // 添加分页
    $offset = ($args['page'] - 1) * $args['per_page'];
    $query .= " LIMIT " . intval($args['per_page']) . " OFFSET " . intval($offset);
    
    // 执行查询
    $users = $tododb->query($query, $params);
    
    // 获取总数
    $count_query = "SELECT COUNT(*) as total FROM " . TABLE_PREFIX . "users WHERE 1=1";
    $count_params = [];
    
    // 添加搜索条件
    if (!empty($args['search'])) {
        $count_query .= " AND (user_login LIKE ? OR user_email LIKE ? OR display_name LIKE ?)";
        $search_term = '%' . $args['search'] . '%';
        $count_params[] = $search_term;
        $count_params[] = $search_term;
        $count_params[] = $search_term;
    }
    
    // 添加角色条件
    if (!empty($args['role'])) {
        $count_query .= " AND ID IN (SELECT user_id FROM " . TABLE_PREFIX . "user_meta WHERE meta_key = 'user_role' AND meta_value = ?)";
        $count_params[] = $args['role'];
    }
    
    $count_result = $tododb->query($count_query, $count_params);
    $total = isset($count_result[0]['total']) ? intval($count_result[0]['total']) : 0;
    
    // 处理结果
    $result = [];
    foreach ($users as $user) {
        $result[] = [
            'id' => intval($user['ID']),
            'username' => $user['user_login'],
            'email' => $user['user_email'],
            'display_name' => $user['display_name'],
            'registered' => $user['user_registered'],
            'role' => get_user_role($user['ID']),
            'avatar' => get_user_avatar_url($user['ID'])
        ];
    }
    
    // 返回结果
    return [
        'users' => $result,
        'total' => $total,
        'page' => $args['page'],
        'per_page' => $args['per_page'],
        'total_pages' => ceil($total / $args['per_page'])
    ];
}

/**
 * 获取单个用户
 *
 * @param array $params 请求参数
 * @return array 用户数据
 */
function api_get_user($params) {
    global $tododb;

    // 验证参数
    if (!isset($params['id'])) {
        send_api_error(400, '缺少ID参数');
    }

    $user_id = intval($params['id']);

    // ── 权限校验：只能查看自己，或需要 manage_users 权限 ──
    $current_uid = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    if (!$current_uid || ($current_uid !== $user_id && !current_user_can('manage_users'))) {
        send_api_error(403, '权限不足：无法查看其他用户信息');
    }
    
    // 查询用户
    $query = "SELECT * FROM " . TABLE_PREFIX . "users WHERE ID = ? LIMIT 1";
    $user = $tododb->query($query, [$user_id]);
    
    // 检查用户是否存在
    if (empty($user)) {
        send_api_error(404, '用户不存在');
    }
    
    $user = $user[0];
    
    // 获取用户元数据
    $meta_query = "SELECT meta_key, meta_value FROM " . TABLE_PREFIX . "user_meta WHERE user_id = ?";
    $meta_data = $tododb->query($meta_query, [$user_id]);
    
    $meta = [];
    foreach ($meta_data as $meta_item) {
        $meta[$meta_item['meta_key']] = $meta_item['meta_value'];
    }
    
    // 返回结果
    return [
        'id' => intval($user['ID']),
        'username' => $user['user_login'],
        'email' => $user['user_email'],
        'display_name' => $user['display_name'],
        'registered' => $user['user_registered'],
        'url' => $user['user_url'],
        'role' => get_user_role($user['ID']),
        'avatar' => get_user_avatar_url($user['ID']),
        'first_name' => isset($meta['first_name']) ? $meta['first_name'] : '',
        'phone' => isset($meta['phone']) ? $meta['phone'] : '',
        'description' => isset($meta['description']) ? $meta['description'] : '',
        'balance' => function_exists('todo_get_user_balance') ? todo_get_user_balance($user_id) : 0
    ];
}

/**
 * 创建用户
 *
 * @param array $params 请求参数
 * @return array 创建的用户数据
 */
function api_create_user($params) {
    global $tododb;

    // ── 权限校验：仅管理员可创建用户 ──
    $current_uid = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    if (!$current_uid || !current_user_can('manage_users')) {
        send_api_error(403, '权限不足：需要管理员权限');
    }

    // 验证必要参数
    if (!isset($params['username']) || empty($params['username'])) {
        send_api_error(400, '用户名不能为空');
    }
    
    if (!isset($params['email']) || empty($params['email'])) {
        send_api_error(400, '邮箱不能为空');
    }
    
    if (!isset($params['password']) || empty($params['password'])) {
        send_api_error(400, '密码不能为空');
    }
    
    // 验证用户名是否已存在
    $check_query = "SELECT ID FROM " . TABLE_PREFIX . "users WHERE user_login = ? LIMIT 1";
    $existing_user = $tododb->query($check_query, [$params['username']]);
    
    if (!empty($existing_user)) {
        send_api_error(400, '用户名已存在');
    }
    
    // 验证邮箱是否已存在
    $check_query = "SELECT ID FROM " . TABLE_PREFIX . "users WHERE user_email = ? LIMIT 1";
    $existing_email = $tododb->query($check_query, [$params['email']]);
    
    if (!empty($existing_email)) {
        send_api_error(400, '邮箱已存在');
    }
    
    // 准备数据
    $user_data = [
        'user_login' => sanitize_user($params['username']),
        'user_email' => sanitize_email($params['email']),
        'user_pass' => password_hash($params['password'], PASSWORD_DEFAULT),
        'display_name' => isset($params['display_name']) ? sanitize_text_field($params['display_name']) : $params['username'],
        'user_registered' => function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s'),
        'user_url' => isset($params['url']) ? esc_url($params['url']) : ''
    ];
    
    // 插入用户
    $query = "INSERT INTO " . TABLE_PREFIX . "users (user_login, user_email, user_pass, display_name, user_registered, user_url) VALUES (?, ?, ?, ?, ?, ?)";
    $result = $tododb->query($query, [
        $user_data['user_login'],
        $user_data['user_email'],
        $user_data['user_pass'],
        $user_data['display_name'],
        $user_data['user_registered'],
        $user_data['user_url']
    ]);
    
    if (!$result) {
        send_api_error(500, '创建用户失败');
    }
    
    $user_id = $tododb->insert_id();
    
    // 设置用户角色
    $role = isset($params['role']) ? sanitize_text_field($params['role']) : 'subscriber';
    add_user_meta($user_id, 'user_role', $role);
    
    // 设置用户元数据
    if (isset($params['first_name'])) {
        add_user_meta($user_id, 'first_name', sanitize_text_field($params['first_name']));
    }
    
    if (isset($params['phone'])) {
        add_user_meta($user_id, 'phone', sanitize_text_field($params['phone']));
    }
    
    if (isset($params['description'])) {
        add_user_meta($user_id, 'description', sanitize_textarea_field($params['description']));
    }
    
    // 返回创建的用户
    return api_get_user(['id' => $user_id]);
}

/**
 * 更新用户
 *
 * @param array $params 请求参数
 * @return array 更新后的用户数据
 */
function api_update_user($params) {
    global $tododb;

    // 验证参数
    if (!isset($params['id'])) {
        send_api_error(400, '缺少ID参数');
    }

    $user_id = intval($params['id']);

    // ── 权限校验：只能修改自己，或需要 manage_users 权限 ──
    $current_uid = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    $is_admin    = $current_uid && current_user_can('manage_users');
    if (!$current_uid || ($current_uid !== $user_id && !$is_admin)) {
        send_api_error(403, '权限不足：无法修改其他用户信息');
    }
    // 角色修改仅限管理员
    if (isset($params['role']) && !$is_admin) {
        send_api_error(403, '权限不足：无法修改用户角色');
    }

    // 检查用户是否存在
    $query = "SELECT * FROM " . TABLE_PREFIX . "users WHERE ID = ? LIMIT 1";
    $user = $tododb->query($query, [$user_id]);

    if (empty($user)) {
        send_api_error(404, '用户不存在');
    }
    
    // 准备更新数据
    $update_data = [];
    $update_params = [];
    
    if (isset($params['email'])) {
        // 验证邮箱是否已存在
        $check_query = "SELECT ID FROM " . TABLE_PREFIX . "users WHERE user_email = ? AND ID != ? LIMIT 1";
        $existing_email = $tododb->query($check_query, [$params['email'], $user_id]);
        
        if (!empty($existing_email)) {
            send_api_error(400, '邮箱已存在');
        }
        
        $update_data[] = 'user_email = ?';
        $update_params[] = sanitize_email($params['email']);
    }
    
    if (isset($params['display_name'])) {
        $update_data[] = 'display_name = ?';
        $update_params[] = sanitize_text_field($params['display_name']);
    }
    
    if (isset($params['url'])) {
        $update_data[] = 'user_url = ?';
        $update_params[] = esc_url($params['url']);
    }
    
    if (isset($params['password']) && !empty($params['password'])) {
        $update_data[] = 'user_pass = ?';
        $update_params[] = password_hash($params['password'], PASSWORD_DEFAULT);
    }
    
    // 添加用户ID
    $update_params[] = $user_id;
    
    // 执行更新
    if (!empty($update_data)) {
        $query = "UPDATE " . TABLE_PREFIX . "users SET " . implode(', ', $update_data) . " WHERE ID = ?";
        $result = $tododb->query($query, $update_params);
        
        if (!$result) {
            send_api_error(500, '更新用户失败');
        }
    }
    
    // 更新用户元数据
    if (isset($params['role'])) {
        update_user_meta($user_id, 'user_role', sanitize_text_field($params['role']));
    }
    
    if (isset($params['first_name'])) {
        update_user_meta($user_id, 'first_name', sanitize_text_field($params['first_name']));
    }
    
    if (isset($params['phone'])) {
        update_user_meta($user_id, 'phone', sanitize_text_field($params['phone']));
    }
    
    if (isset($params['description'])) {
        update_user_meta($user_id, 'description', sanitize_textarea_field($params['description']));
    }
    
    // 返回更新后的用户
    return api_get_user(['id' => $user_id]);
}

/**
 * 删除用户
 *
 * @param array $params 请求参数
 * @return array 操作结果
 */
function api_delete_user($params) {
    global $tododb;

    // 验证参数
    if (!isset($params['id'])) {
        send_api_error(400, '缺少ID参数');
    }

    $user_id = intval($params['id']);

    // ── 权限校验：仅管理员可删除用户，且不能删除自身 ──
    $current_uid = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    if (!$current_uid || !current_user_can('manage_users')) {
        send_api_error(403, '权限不足：需要管理员权限');
    }
    if ($current_uid === $user_id) {
        send_api_error(403, '不允许删除当前登录账户');
    }

    // 检查用户是否存在
    $query = "SELECT * FROM " . TABLE_PREFIX . "users WHERE ID = ? LIMIT 1";
    $user = $tododb->query($query, [$user_id]);

    if (empty($user)) {
        send_api_error(404, '用户不存在');
    }
    
    // 删除用户
    $query = "DELETE FROM " . TABLE_PREFIX . "users WHERE ID = ?";
    $result = $tododb->query($query, [$user_id]);
    
    if (!$result) {
        send_api_error(500, '删除用户失败');
    }
    
    // 删除用户元数据
    $query = "DELETE FROM " . TABLE_PREFIX . "user_meta WHERE user_id = ?";
    $tododb->query($query, [$user_id]);
    
    // 返回结果
    return [
        'id' => $user_id,
        'message' => '用户已成功删除'
    ];
}

/**
 * 用户登录
 *
 * @param array $params 请求参数
 * @return array 登录结果
 */
function api_login($params) {
    global $tododb;

    // 验证必要参数
    if (!isset($params['username']) || empty($params['username'])) {
        send_api_error(400, '用户名不能为空');
    }
    if (!isset($params['password']) || empty($params['password'])) {
        send_api_error(400, '密码不能为空');
    }

    // ── 暴力破解防护（与后台登录保持一致）──
    $username_input = (string) $params['username'];
    $client_ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
    if (function_exists('todo_check_login_lockout')) {
        $lockout = todo_check_login_lockout($username_input, $client_ip);
        if ($lockout) {
            send_api_error(429, "登录尝试次数过多，请在 {$lockout['wait_minutes']} 分钟后再试");
        }
    }

    // 查询用户
    $query = "SELECT * FROM " . TABLE_PREFIX . "users WHERE user_login = ? OR user_email = ? LIMIT 1";
    $user = $tododb->query($query, [$username_input, $username_input]);

    // 检查用户是否存在（统一错误信息，防止用户名枚举）
    $user_exists = !empty($user);
    $password_ok = $user_exists && password_verify($params['password'], $user[0]['user_pass']);

    // 记录登录尝试
    if (function_exists('todo_register_login_attempt')) {
        todo_register_login_attempt($username_input, $client_ip, $password_ok);
    }

    if (!$user_exists || !$password_ok) {
        send_api_error(401, '用户名或密码错误');
    }

    $user = $user[0];
    
    // 生成JWT令牌
    $payload = [
        'sub' => $user['ID'],
        'name' => $user['display_name'],
        'email' => $user['user_email'],
        'role' => get_user_role($user['ID']),
        'iat' => time(),
        'exp' => time() + (60 * 60 * 24) // 24小时过期
    ];
    
    $jwt = generate_jwt($payload);
    
    // 返回结果
    return [
        'token' => $jwt,
        'user' => [
            'id' => intval($user['ID']),
            'username' => $user['user_login'],
            'email' => $user['user_email'],
            'display_name' => $user['display_name'],
            'role' => get_user_role($user['ID']),
            'avatar' => get_user_avatar_url($user['ID'])
        ]
    ];
}

/**
 * 获取当前用户信息
 *
 * @param array $params 请求参数
 * @return array 用户信息
 */
function api_me($params) {
    // 获取当前用户ID
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        send_api_error(401, '未授权');
    }
    
    // 返回用户信息
    return api_get_user(['id' => $user_id]);
}

// 注册API路由
register_api_route('users', 'GET', 'api_get_users');
register_api_route('users/([0-9]+)', 'GET', 'api_get_user');
register_api_route('users', 'POST', 'api_create_user');
register_api_route('users/([0-9]+)', 'PUT', 'api_update_user');
register_api_route('users/([0-9]+)', 'DELETE', 'api_delete_user');
register_api_route('login', 'POST', 'api_login');
register_api_route('me', 'GET', 'api_me');

/**
 * 获取当前用户余额
 */
function api_get_my_balance($params) {
    $user_id = get_current_user_id();
    if (!$user_id) {
        send_api_error(401, '未授权');
    }
    
    return [
        'balance' => function_exists('todo_get_user_balance') ? todo_get_user_balance($user_id) : 0
    ];
}
register_api_route('me/balance', 'GET', 'api_get_my_balance');
