<?php
/**
 * 用户函数
 *
 * 提供用户管理相关的函数
 *
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 获取用户列表
 *
 * @param array $args 查询参数
 * @return array 用户对象数组
 */
function get_users($args = array())
{
    global $tododb;

    // 默认参数
    $defaults = array(
        'number' => 20,
        'offset' => 0,
        'search' => '',
        'role' => '',
        'orderby' => 'registered',
        'order' => 'DESC'
    );

    $args = array_merge($defaults, $args);

    // 构建查询
    $query = "SELECT " . TABLE_PREFIX . "users.ID FROM " . TABLE_PREFIX . "users";
    $where = array();
    $params = array();

    // 搜索条件
    if (!empty($args['search'])) {
        $search_input = (string) $args['search'];
        $search = '%' . $tododb->escape_like($search_input) . '%';
        $search_digits = preg_replace('/\\D+/', '', $search_input);
        $phone_like_sql = "um_phone.meta_value LIKE ?";
        $phone_params = [$search];
        if ($search_digits !== '' && $search_digits !== $search_input) {
            $digits_like = '%' . $tododb->escape_like($search_digits) . '%';
            $phone_like_sql .= " OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(um_phone.meta_value,' ',''),'-',''),'(',''),')',''),'.',''),'+','') LIKE ?";
            $phone_params[] = $digits_like;
        }

        $where[] = "(user_login LIKE ? OR user_email LIKE ? OR display_name LIKE ? OR EXISTS (SELECT 1 FROM " . TABLE_PREFIX . "user_meta um_phone WHERE um_phone.user_id = " . TABLE_PREFIX . "users.ID AND um_phone.meta_key IN ('mobile','phone') AND ({$phone_like_sql})))";
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
        foreach ($phone_params as $p) {
            $params[] = $p;
        }
    }

    if (todo_is_demo_admin_session()) {
        $demo_uid = isset($_SESSION['todo_demo_admin_uid']) ? (int) $_SESSION['todo_demo_admin_uid'] : 0;
        if ($demo_uid > 0) {
            $where[] = TABLE_PREFIX . "users.ID = ?";
            $params[] = $demo_uid;
        } else {
             // 如果没拿到 UID（理论不应该），则强制全空结果
            $where[] = "1 = 0";
        }
    }

    if (!current_user_can('manage_options') || !todo_is_super_admin()) {
        $where[] = "NOT EXISTS (SELECT 1 FROM " . TABLE_PREFIX . "user_meta um_dev WHERE um_dev.user_id = " . TABLE_PREFIX . "users.ID AND um_dev.meta_key = 'is_developer' AND um_dev.meta_value = '1')";
    }

    // Role filter logic
    if (!empty($args['role'])) {
        $role_target = (string) $args['role'];
        $default_role = function_exists('get_option') ? get_option('default_role', 'subscriber') : 'subscriber';
        if ($role_target === 'subscriber' || $role_target === $default_role) {
            $where[] = "(EXISTS (SELECT 1 FROM " . TABLE_PREFIX . "user_meta um_role WHERE um_role.user_id = " . TABLE_PREFIX . "users.ID AND um_role.meta_key = 'roles' AND (um_role.meta_value LIKE ? OR um_role.meta_value = ? OR um_role.meta_value = '' OR um_role.meta_value = 'a:0:{}' OR um_role.meta_value = '[]' OR um_role.meta_value IS NULL)) OR NOT EXISTS (SELECT 1 FROM " . TABLE_PREFIX . "user_meta um_norole WHERE um_norole.user_id = " . TABLE_PREFIX . "users.ID AND um_norole.meta_key = 'roles'))";
            $params[] = '%"' . $tododb->escape_like($role_target) . '"%';
            $params[] = $role_target;
        } else {
            $where[] = "EXISTS (SELECT 1 FROM " . TABLE_PREFIX . "user_meta um_role WHERE um_role.user_id = " . TABLE_PREFIX . "users.ID AND um_role.meta_key = 'roles' AND (um_role.meta_value LIKE ? OR um_role.meta_value = ?))";
            $params[] = '%"' . $tododb->escape_like($role_target) . '"%';
            $params[] = $role_target;
        }
    }

    // 添加WHERE子句
    if (!empty($where)) {
        $query .= " WHERE " . implode(' AND ', $where);
    }

    // 排序
    $orderby = TABLE_PREFIX . 'users.user_registered';
    if ($args['orderby'] === 'login') {
        $orderby = TABLE_PREFIX . 'users.user_login';
    } elseif ($args['orderby'] === 'email') {
        $orderby = TABLE_PREFIX . 'users.user_email';
    } elseif ($args['orderby'] === 'name') {
        $orderby = TABLE_PREFIX . 'users.display_name';
    }

    $order = $args['order'] === 'ASC' ? 'ASC' : 'DESC';
    $query .= " ORDER BY {$orderby} {$order}";

    // 分页
    if ($args['number'] > 0) {
        $query .= " LIMIT " . intval($args['offset']) . ", " . intval($args['number']);
    }

    // 执行查询
    $results = $tododb->get_results($query, $params, 'ARRAY_A');

    // 创建用户对象数组
    $users = array();

    if ($results) {
        // 收集所有用户ID
        $user_ids = array();
        foreach ($results as $user_data) {
            $user_ids[] = (int) $user_data['ID'];
        }

        // 批量预加载用户缓存（包括元数据）
        CMS_User::prime_cache($user_ids);

        // 现在创建用户对象将使用缓存，不会触发额外查询
        foreach ($results as $user_data) {
            $users[] = new CMS_User($user_data['ID']);
        }
    }

    return $users;
}

/**
 * 计算用户数量
 *
 * @param array $args 查询参数
 * @return int 用户数量
 */
function count_users($args = array())
{
    global $tododb;

    // 默认参数
    $defaults = array(
        'search' => '',
        'role' => ''
    );

    $args = array_merge($defaults, $args);

    // 构建查询
    $query = "SELECT COUNT(DISTINCT " . TABLE_PREFIX . "users.ID) FROM " . TABLE_PREFIX . "users";
    $where = array();
    $params = array();

    // 搜索条件
    if (!empty($args['search'])) {
        $search_input = (string) $args['search'];
        $search = '%' . $tododb->escape_like($search_input) . '%';
        $search_digits = preg_replace('/\\D+/', '', $search_input);
        $phone_like_sql = "um_phone.meta_value LIKE ?";
        $phone_params = [$search];
        if ($search_digits !== '' && $search_digits !== $search_input) {
            $digits_like = '%' . $tododb->escape_like($search_digits) . '%';
            $phone_like_sql .= " OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(um_phone.meta_value,' ',''),'-',''),'(',''),')',''),'.',''),'+','') LIKE ?";
            $phone_params[] = $digits_like;
        }

        $where[] = "(" . TABLE_PREFIX . "users.user_login LIKE ? OR " . TABLE_PREFIX . "users.user_email LIKE ? OR " . TABLE_PREFIX . "users.display_name LIKE ? OR EXISTS (SELECT 1 FROM " . TABLE_PREFIX . "user_meta um_phone WHERE um_phone.user_id = " . TABLE_PREFIX . "users.ID AND um_phone.meta_key IN ('mobile','phone') AND ({$phone_like_sql})))";
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
        foreach ($phone_params as $p) {
            $params[] = $p;
        }
    }

    if (todo_is_demo_admin_session()) {
        $demo_uid = isset($_SESSION['todo_demo_admin_uid']) ? (int) $_SESSION['todo_demo_admin_uid'] : 0;
        if ($demo_uid > 0) {
            $where[] = TABLE_PREFIX . "users.ID = ?";
            $params[] = $demo_uid;
        } else {
            $where[] = "1 = 0";
        }
    }

    // Role filter logic
    if (!empty($args['role'])) {
        $role_target = (string) $args['role'];
        $default_role = function_exists('get_option') ? get_option('default_role', 'subscriber') : 'subscriber';
        if ($role_target === 'subscriber' || $role_target === $default_role) {
            $where[] = "(EXISTS (SELECT 1 FROM " . TABLE_PREFIX . "user_meta um_role WHERE um_role.user_id = " . TABLE_PREFIX . "users.ID AND um_role.meta_key = 'roles' AND (um_role.meta_value LIKE ? OR um_role.meta_value = ? OR um_role.meta_value = '' OR um_role.meta_value = 'a:0:{}' OR um_role.meta_value = '[]' OR um_role.meta_value IS NULL)) OR NOT EXISTS (SELECT 1 FROM " . TABLE_PREFIX . "user_meta um_norole WHERE um_norole.user_id = " . TABLE_PREFIX . "users.ID AND um_norole.meta_key = 'roles'))";
            $params[] = '%"' . $tododb->escape_like($role_target) . '"%';
            $params[] = $role_target;
        } else {
            $where[] = "EXISTS (SELECT 1 FROM " . TABLE_PREFIX . "user_meta um_role WHERE um_role.user_id = " . TABLE_PREFIX . "users.ID AND um_role.meta_key = 'roles' AND (um_role.meta_value LIKE ? OR um_role.meta_value = ?))";
            $params[] = '%"' . $tododb->escape_like($role_target) . '"%';
            $params[] = $role_target;
        }
    }

    if (!current_user_can('manage_options') || !todo_is_super_admin()) {
        $where[] = "NOT EXISTS (SELECT 1 FROM " . TABLE_PREFIX . "user_meta um_dev WHERE um_dev.user_id = " . TABLE_PREFIX . "users.ID AND um_dev.meta_key = 'is_developer' AND um_dev.meta_value = '1')";
    }

    // 添加WHERE子句
    if (!empty($where)) {
        $query .= " WHERE " . implode(' AND ', $where);
    }

    // 执行查询
    $count = $tododb->get_var($query, $params);

    return intval($count);
}

/**
 * 根据条件获取单个用户
 *
 * @param string $field 字段名
 * @param mixed $value 字段值
 * @return CMS_User|false 用户对象或false
 */
function get_user_by($field, $value)
{
    global $tododb;
    if (!$tododb) {
        if (!function_exists('get_database_connection')) {
            require_once ABSPATH . 'core/functions/core-system-functions.php';
        }
        $tododb = get_database_connection();
    }

    // 在安装过程中，如果数据库连接为 null，返回 false
    if (!$tododb) {
        return false;
    }

    $allowed_fields = array('id', 'login', 'email', 'mobile', 'phone');

    if (!in_array(strtolower($field), $allowed_fields)) {
        return false;
    }

    if (in_array(strtolower($field), ['mobile', 'phone'])) {
        $clean_val = is_array($value) ? (isset($value[0]) ? $value[0] : '') : $value;
        return function_exists('get_user_by_mobile') ? get_user_by_mobile($clean_val) : false;
    }

    // 如果是按ID查询，先检查缓存
    if (strtolower($field) === 'id') {
        $user_id = is_array($value) ? (isset($value[0]) ? $value[0] : 0) : $value;
        $cached_user = CMS_User::get_cached_user((int) $user_id);
        if ($cached_user) {
            return $cached_user;
        }
    }

    $tododb_field = 'ID';
    if (strtolower($field) === 'login') {
        $tododb_field = 'user_login';
    } elseif (strtolower($field) === 'email') {
        $tododb_field = 'user_email';
    }

    $query = "SELECT * FROM " . TABLE_PREFIX . "users WHERE {$tododb_field} = ? LIMIT 1";
    // 确保值是标量，而不是数组
    if (is_array($value)) {
        $value = isset($value[0]) ? $value[0] : '';
    }
    $row = $tododb->get_row($query, [$value], 'ARRAY_A');

    if ($row && is_array($row) && !empty($row['ID'])) {
        $found_id = intval($row['ID']);
        // 再次检查缓存
        $cached_user = CMS_User::get_cached_user($found_id);
        if ($cached_user) {
            return $cached_user;
        }
        return new CMS_User($row);
    }

    return false;
}

/**
 * 根据手机号获取用户
 *
 * @param string $mobile 手机号码
 * @return CMS_User|false 用户对象或 false
 */
if (!function_exists('get_user_by_mobile')) {
    function get_user_by_mobile($mobile)
    {
        $mobile = trim((string) $mobile);
        if ($mobile === '') {
            return false;
        }

        global $tododb;
        if (!$tododb && function_exists('get_database_connection')) {
            $tododb = get_database_connection();
        }

        if ($tododb) {
            // 1. 优先从 user_meta 查 mobile，且排除已注销账号 (u.user_status != -1)
            $user_id = (int) $tododb->get_var(
                "SELECT m.user_id FROM " . TABLE_PREFIX . "user_meta m INNER JOIN " . TABLE_PREFIX . "users u ON m.user_id = u.ID WHERE m.meta_key = 'mobile' AND m.meta_value = ? AND u.user_status != -1 LIMIT 1",
                [$mobile]
            );
            if ($user_id > 0) {
                return get_user_by('id', $user_id);
            }

            // 2. 检查 user_login 是否直接为手机号（排除已注销账号）
            $user = get_user_by('login', $mobile);
            if ($user) {
                $status = method_exists($user, 'get_status') ? $user->get_status() : null;
                $raw_status = isset($user->user_status) ? (int)$user->user_status : 0;
                if ($status !== 'deleted' && $raw_status !== -1) {
                    return $user;
                }
            }
        } elseif (function_exists('get_users')) {
            $users = get_users([
                'meta_key' => 'mobile',
                'meta_value' => $mobile,
                'number' => 1
            ]);
            if (!empty($users)) {
                $u = $users[0];
                $raw_status = isset($u->user_status) ? (int)$u->user_status : 0;
                if ($raw_status !== -1) {
                    return $u;
                }
            }
        }

        return false;
    }
}

/**
 * 创建新用户
 *
 * @param array $user_data 用户数据
 * @return int|TODO_Error 成功返回用户ID，失败返回错误对象
 */
function todo_insert_user($user_data)
{
    if (function_exists('todo_is_demo_admin_session') && todo_is_demo_admin_session()) {
        return new TODO_Error('demo_mode', '演示模式！');
    }

    global $tododb;

    // 检查必填字段
    if (empty($user_data['user_login'])) {
        return new TODO_Error('empty_user_login', '用户名不能为空');
    }

    if (empty($user_data['user_email'])) {
        return new TODO_Error('empty_user_email', '电子邮件不能为空');
    }

    if (empty($user_data['user_pass'])) {
        return new TODO_Error('empty_user_pass', '密码不能为空');
    }

    // 检查用户名是否已存在
    $existing_user = get_user_by('login', $user_data['user_login']);
    if ($existing_user) {
        return new TODO_Error('existing_user_login', '该用户名已被使用');
    }

    // 检查邮箱是否已存在
    $existing_email = get_user_by('email', $user_data['user_email']);
    if ($existing_email) {
        return new TODO_Error('existing_user_email', '该电子邮件已被使用');
    }

    // 执行用户创建钩子
    global $hooks;
    if ($hooks !== null) {
        $hooks->do_action('pre_user_create', $user_data);
    } else {
        // 如果$hooks未初始化，直接使用静态方法执行钩子
        do_action('pre_user_create', $user_data);
    }

    // 创建用户
    $user_id = CMS_User::create($user_data);

    if ($user_id) {
        if (!empty($user_data['avatar'])) {
            update_user_meta($user_id, 'avatar', $user_data['avatar']);
        } else {
            $existing_avatar = get_user_meta($user_id, 'avatar', true);
            if (empty($existing_avatar)) {
                todo_user_avatar_get_or_create($user_id);
            }
        }
        // 执行用户创建后钩子
        if ($hooks !== null) {
            $hooks->do_action('user_created', $user_id, $user_data);
        } else {
            do_action('user_created', $user_id, $user_data);
        }
        return $user_id;
    }

    return new TODO_Error('user_create_failed', '创建用户失败');
}

/**
 * 更新用户信息
 *
 * @param array $user_data 用户数据
 * @return int|TODO_Error 成功返回用户ID，失败返回错误对象
 */
function todo_update_user($user_data)
{
    if (function_exists('todo_is_demo_admin_session') && todo_is_demo_admin_session()) {
        return new TODO_Error('demo_mode', '演示模式！');
    }

    global $tododb;

    // 检查用户ID
    if (empty($user_data['ID'])) {
        return new TODO_Error('empty_user_id', '用户ID不能为空');
    }

    $user_id = (int) $user_data['ID'];

    // 检查用户是否存在
    $user = new CMS_User($user_id);
    if (!$user->get_id()) {
        return new TODO_Error('invalid_user_id', '无效的用户ID');
    }

    // 检查邮箱是否已被其他用户使用
    if (!empty($user_data['user_email']) && $user_data['user_email'] !== $user->get_email()) {
        $existing_email = get_user_by('email', $user_data['user_email']);
        if ($existing_email && $existing_email->get_id() !== $user_id) {
            return new TODO_Error('existing_user_email', '该电子邮件已被其他用户使用');
        }
    }

    // 执行用户更新前钩子
    global $hooks;
    if ($hooks !== null) {
        $hooks->do_action('pre_user_update', $user_id, $user_data);
    } else {
        do_action('pre_user_update', $user_id, $user_data);
    }

    // 更新用户
    error_log('Updating user with data: ' . print_r($user_data, true));
    $result = $user->update($user_data);
    error_log('Update result: ' . ($result ? 'success' : 'failed'));

    if ($result) {
        // 执行用户更新后钩子
        if ($hooks !== null) {
            $hooks->do_action('profile_update', $user_id, $user_data);
        } else {
            do_action('profile_update', $user_id, $user_data);
        }
        return $user_id;
    }

    return new TODO_Error('user_update_failed', '更新用户失败');
}

/**
 * 删除用户
 *
 * @param int $user_id 用户ID
 * @param int $reassign 重新分配内容的用户ID
 * @return bool 是否成功删除
 */
function delete_user($user_id, $reassign = 0)
{
    $user_id = intval($user_id);

    // 检查用户是否存在
    $user = new CMS_User($user_id);
    if (!$user->get_id()) {
        return false;
    }

    // 开启了“隐身模式”或超级管理员身份的账号不允许删除，以确保系统至少保留一个最高权限入口
    if (todo_is_super_admin($user_id)) {
        return false;
    }

    // 执行用户删除前钩子
    global $hooks;
    if ($hooks !== null) {
        $hooks->do_action('delete_user', $user_id, $reassign);
    } else {
        do_action('delete_user', $user_id, $reassign);
    }

    // 删除用户
    $result = $user->delete($reassign > 0, $reassign);

    // 执行用户删除后钩子
    if ($result) {
        if ($hooks !== null) {
            $hooks->do_action('deleted_user', $user_id, $reassign);
        } else {
            do_action('deleted_user', $user_id, $reassign);
        }
    }

    return $result;
}

/**
 * 用户认证
 *
 * @param string $username 用户名
 * @param string $password 密码
 * @return CMS_User|TODO_Error 成功返回用户对象，失败返回错误对象
 */
function todo_authenticate($username, $password)
{
    $demo_enabled = defined('TODO_DEMO_ADMIN_ENABLED') && TODO_DEMO_ADMIN_ENABLED;
    $demo_username = defined('TODO_DEMO_ADMIN_USERNAME') ? (string) TODO_DEMO_ADMIN_USERNAME : '';
    $demo_password = defined('TODO_DEMO_ADMIN_PASSWORD') ? (string) TODO_DEMO_ADMIN_PASSWORD : '';

    if ($demo_enabled && $demo_username !== '' && hash_equals($demo_username, (string) $username)) {
        $is_demo_proxy_login = defined('TODO_DEMO_LOGIN_PROXY') && TODO_DEMO_LOGIN_PROXY;
        if ($is_demo_proxy_login) {
            if ($demo_password === '' || !hash_equals($demo_password, (string) $password)) {
                return new TODO_Error('incorrect_password', '密码不正确');
            }
        }

        $user = get_user_by('login', $demo_username);
        if (!$user) {
            $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : 'invalid.local';
            $host = strtolower($host);
            $host = preg_replace('/[^a-z0-9.-]+/i', '', $host);
            if ($host === '' || $host === 'localhost') {
                $host = 'invalid.local';
            }

            $email = $demo_username . '+demo@' . $host;
            if (function_exists('get_user_by') && get_user_by('email', $email)) {
                $salt = defined('ABSPATH') ? (string) ABSPATH : __FILE__;
                $email = $demo_username . '+demo-' . substr(md5($salt), 0, 10) . '@' . $host;
            }

            $new_user_id = CMS_User::create([
                'user_login' => $demo_username,
                'user_email' => $email,
                'user_pass' => $demo_password,
                'display_name' => $demo_username,
                'role' => 'demo',
                'meta' => [
                    'todo_demo_admin' => '1',
                ],
            ]);

            if (!$new_user_id) {
                return new TODO_Error('invalid_username', '无效的用户名');
            }

            $user = new CMS_User($new_user_id);
        } else {
            if (is_callable([$user, 'update_meta'])) {
                $user->update_meta('todo_demo_admin', '1');
            } elseif (function_exists('update_user_meta') && is_callable([$user, 'get_id'])) {
                update_user_meta($user->get_id(), 'todo_demo_admin', '1');
            }

            if (is_callable([$user, 'get_roles']) && is_callable([$user, 'set_role'])) {
                $roles = $user->get_roles();
                if (!is_array($roles) || !in_array('demo', $roles, true)) {
                    $user->set_role('demo');
                }
            }

            if (is_callable([$user, 'check_password']) && !$user->check_password($demo_password)) {
                $user->update(['user_pass' => $demo_password]);
            }
        }

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['todo_demo_admin'] = 1;
        if (is_callable([$user, 'get_id'])) {
            $_SESSION['todo_demo_admin_uid'] = (int) $user->get_id();
        }

        return $user;
    }

    // 尝试按用户名、邮箱或手机号查询用户
    $user = get_user_by('login', $username);
    if (!$user && filter_var($username, FILTER_VALIDATE_EMAIL)) {
        $user = get_user_by('email', $username);
    }
    if (!$user) {
        $user = get_user_by('email', $username);
    }
    if (!$user && function_exists('get_user_by_mobile')) {
        $user = get_user_by_mobile($username);
    }

    if (!$user) {
        return new TODO_Error('invalid_username', '无效的用户名、邮箱或手机号');
    }

    // 检查密码
    if (!$user->check_password($password)) {
        return new TODO_Error('incorrect_password', '密码不正确');
    }

    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    unset($_SESSION['todo_demo_admin'], $_SESSION['todo_demo_admin_uid']);

    return $user;
}

/**
 * 生成认证令牌
 *
 * @param int $user_id 用户ID
 * @return string 认证令牌
 */
function generate_auth_token($user_id)
{
    $token = todo_generate_password(64, false);
    $expiration = time() + 14 * DAY_IN_SECONDS;

    // 保存令牌到用户元数据
    update_user_meta($user_id, 'auth_token', array(
        'token' => $token,
        'expiration' => $expiration
    ));

    return $token;
}

/**
 * 验证认证令牌
 *
 * @param int $user_id 用户ID
 * @param string $token 认证令牌
 * @return bool 是否有效
 */
function verify_auth_token($user_id, $token)
{
    $auth_token = get_user_meta($user_id, 'auth_token', true);

    if (!$auth_token || !is_array($auth_token)) {
        return false;
    }

    if ($auth_token['token'] !== $token) {
        return false;
    }

    if ($auth_token['expiration'] < time()) {
        delete_user_meta($user_id, 'auth_token');
        return false;
    }

    return true;
}

/**
 * 生成随机密码
 *
 * @param int $length 密码长度
 * @param bool $special_chars 是否包含特殊字符
 * @return string 随机密码
 */
function todo_generate_password($length = 12, $special_chars = true)
{
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    if ($special_chars) {
        $chars .= '!@#$%^&*()-_ []{}<>~`+=,.;:/?|';
    }

    $password = '';
    $chars_length = strlen($chars);

    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $chars_length - 1)];
    }

    return $password;
}

/**
 * 获取用户角色
 *
 * @param int $user_id 用户ID
 * @return array 角色列表
 */
function get_user_role($user_id)
{
    $user = new CMS_User($user_id);
    return $user->get_roles();
}

function todo_user_avatar_normalize_url($url)
{
    if (!is_string($url)) {
        return '';
    }
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (strpos($url, '//') === 0) {
        $scheme = 'http';
        if (function_exists('todo_get_site_url')) {
            $base = todo_get_site_url('/');
            $parts = @parse_url($base);
            if (is_array($parts) && !empty($parts['scheme'])) {
                $scheme = strtolower((string) $parts['scheme']);
            }
        }
        return $scheme . ':' . $url;
    }

    if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
        return $url;
    }

    if (function_exists('todo_media_url_to_absolute')) {
        return todo_media_url_to_absolute($url);
    }

    if (function_exists('home_url')) {
        return home_url($url);
    }

    return $url;
}

function todo_user_avatar_build_identicon_svg($seed, $size = 256, $cells = 5, $padding = 16)
{
    $seed = is_string($seed) ? $seed : '';
    $hash = hash('sha256', $seed, true);
    $bytes = array_values(unpack('C*', $hash));

    $r = $bytes[0] ?? 120;
    $g = $bytes[1] ?? 120;
    $b = $bytes[2] ?? 120;
    $color = sprintf('#%02x%02x%02x', $r, $g, $b);
    $bg = sprintf('#%02x%02x%02x', (int) (($r + 255) / 2), (int) (($g + 255) / 2), (int) (($b + 255) / 2));

    $grid_size = max(1, (int) $cells);
    $svg_size = max(32, (int) $size);
    $pad = max(0, (int) $padding);
    $cell = (int) floor(($svg_size - $pad * 2) / $grid_size);
    if ($cell < 1) {
        $cell = 1;
        $pad = 0;
        $svg_size = $grid_size;
    }

    $usable = $cell * $grid_size;
    $offset = (int) floor(($svg_size - $usable) / 2);

    $rects = '';
    $bit_index = 3;
    $cols = (int) ceil($grid_size / 2);
    for ($y = 0; $y < $grid_size; $y++) {
        for ($x = 0; $x < $cols; $x++) {
            $byte = $bytes[(int) floor($bit_index / 8)] ?? 0;
            $bit = ($byte >> ($bit_index % 8)) & 1;
            $bit_index++;
            if ($bit === 1) {
                $x1 = $offset + $x * $cell;
                $y1 = $offset + $y * $cell;
                $rects .= '<rect x="' . $x1 . '" y="' . $y1 . '" width="' . $cell . '" height="' . $cell . '" fill="' . $color . '"/>';

                $mx = $grid_size - 1 - $x;
                if ($mx !== $x) {
                    $x2 = $offset + $mx * $cell;
                    $rects .= '<rect x="' . $x2 . '" y="' . $y1 . '" width="' . $cell . '" height="' . $cell . '" fill="' . $color . '"/>';
                }
            }
        }
    }

    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $svg_size . '" height="' . $svg_size . '" viewBox="0 0 ' . $svg_size . ' ' . $svg_size . '"><rect width="100%" height="100%" fill="' . $bg . '"/>' . $rects . '</svg>';
}

if (!function_exists('todo_is_demo_admin_session')) {
    function todo_is_demo_admin_session()
    {
        if (!(defined('TODO_DEMO_ADMIN_ENABLED') && TODO_DEMO_ADMIN_ENABLED)) {
            return false;
        }
        if (php_sapi_name() === 'cli') {
            return false;
        }
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (isset($_SESSION['todo_demo_admin']) && (int) $_SESSION['todo_demo_admin'] === 1) {
            return true;
        }

        $uid = isset($_SESSION['admin_user_id']) ? (int)$_SESSION['admin_user_id'] : (isset($_SESSION['todo_admin_uid']) ? (int)$_SESSION['todo_admin_uid'] : 0);
        if ($uid > 0) {
            $user = function_exists('get_userdata') ? get_userdata($uid) : null;
            if (is_object($user) && isset($user->user_login) && in_array(strtolower((string)$user->user_login), ['demo', 'test'], true)) {
                $_SESSION['todo_demo_admin'] = 1;
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('todo_is_demo_write_request')) {
    /**
     * 判断当前请求是否包含写操作/变动意图
     *
     * @return bool
     */
    function todo_is_demo_write_request()
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            return true;
        }

        if (isset($_GET['action'])) {
            $act = strtolower((string) $_GET['action']);
            $write_actions = [
                'delete', 'activate', 'deactivate', 'trash', 'untrash',
                'clear', 'reset', 'install', 'update', 'save', 'toggle',
                'batch', 'bulk', 'add', 'edit', 'remove', 'purge', 'import', 'drop'
            ];
            if (in_array($act, $write_actions, true)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('todo_guard_demo_mode_write')) {
    /**
     * 物理切断入库、改库、删库、文件修改等所有写操作。
     *
     * @param string $redirect_page 重定向页面标识
     * @param string $custom_message 自定义错误提示
     * @return void
     */
    function todo_guard_demo_mode_write($redirect_page = '', $custom_message = '演示模式！')
    {
        if (!function_exists('todo_is_demo_admin_session') || !todo_is_demo_admin_session()) {
            return;
        }

        $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (defined('DOING_AJAX') && DOING_AJAX)
            || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
            || (isset($_GET['action']) && strpos((string) $_GET['action'], 'ajax') !== false);

        if ($is_ajax) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
            }
            echo json_encode([
                'success' => false,
                'error' => $custom_message,
                'message' => $custom_message
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice($custom_message, 'warning');
        }

        if ($redirect_page !== '') {
            todo_redirect(todo_admin_url('index.php?page=' . $redirect_page));
            exit;
        }

        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if ($referer !== '' && strpos($referer, 'index.php') !== false) {
            todo_redirect($referer);
            exit;
        }

        todo_die($custom_message);
    }
}

function todo_user_avatar_generate_and_store($user_id)
{
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return '';
    }

    if (function_exists('todo_is_demo_admin_session') && todo_is_demo_admin_session()) {
        return '';
    }

    $seed = get_user_meta($user_id, '_todo_avatar_seed', true);
    if (!is_string($seed) || $seed === '') {
        try {
            $seed = bin2hex(random_bytes(16));
        } catch (Exception $e) {
            $seed = md5(uniqid((string) $user_id, true));
        }
        update_user_meta($user_id, '_todo_avatar_seed', $seed);
    }

    if (!function_exists('todo_upload_dir_ueditor')) {
        $media_file = ABSPATH . 'core/functions/media.php';
        if (file_exists($media_file)) {
            require_once $media_file;
        }
    }
    if (!function_exists('todo_upload_dir_ueditor')) {
        return '';
    }

    $upload = todo_upload_dir_ueditor('avatar.svg');
    $full_path = isset($upload['full_path']) ? (string) $upload['full_path'] : '';
    $full_url = isset($upload['full_url']) ? (string) $upload['full_url'] : '';
    if ($full_path === '' || $full_url === '') {
        return '';
    }

    $svg = todo_user_avatar_build_identicon_svg($seed, 256, 5, 16);
    $written = @file_put_contents($full_path, $svg);
    if ($written === false) {
        return '';
    }

    $relative = function_exists('todo_media_url_to_relative') ? todo_media_url_to_relative($full_url) : ($full_url);
    if (!is_string($relative) || $relative === '') {
        $relative = $full_url;
    }

    update_user_meta($user_id, 'avatar', $relative);

    return $relative;
}

function todo_user_avatar_get_or_create($user_id)
{
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return '';
    }

    $avatar = get_user_meta($user_id, 'avatar', $single_or_all = true);
    if (is_string($avatar)) {
        $avatar = trim($avatar);
    } else {
        $avatar = '';
    }

    if ($avatar !== '' && stripos($avatar, 'gravatar.com') !== false) {
        delete_user_meta($user_id, 'avatar');
        $avatar = '';
    }

    // 如果用户已有有效头像（无论是否为本地下载的 jpg/png 或是之前生成的 svg），直接返回，不再自动覆盖生成
    if ($avatar !== '') {
        return $avatar;
    }

    if (function_exists('todo_is_demo_admin_session') && todo_is_demo_admin_session()) {
        if (function_exists('todo_admin_url')) {
            return todo_admin_url('assets/images/default-avatar.svg');
        }
        if (function_exists('home_url')) {
            return home_url('/admin/assets/images/default-avatar.svg');
        }
        return '/admin/assets/images/default-avatar.svg';
    }

    return todo_user_avatar_generate_and_store($user_id);
}

/**
 * 统一处理三方登录（QQ、微信等）用户的头像绑定与按需下载
 *
 * @param int $user_id 用户ID
 * @param string $remote_avatar_url 远程头像地址
 * @param string $provider 提供商名称 (qq, wechat 等)
 * @return string 最终绑定的头像路径/URL
 */
function todo_process_social_user_avatar($user_id, $remote_avatar_url, $provider = 'social')
{
    $user_id = (int) $user_id;
    $remote_avatar_url = is_string($remote_avatar_url) ? trim($remote_avatar_url) : '';
    if ($user_id <= 0) {
        return '';
    }

    $current_avatar = get_user_meta($user_id, 'avatar', true);
    if (is_string($current_avatar)) {
        $current_avatar = trim($current_avatar);
    } else {
        $current_avatar = '';
    }

    $meta_remote_key = '_' . $provider . '_remote_avatar';
    $stored_remote_url = get_user_meta($user_id, $meta_remote_key, true);

    $is_svg_or_default = ($current_avatar === '' ||
                          stripos($current_avatar, '.svg') !== false ||
                          stripos($current_avatar, 'default-avatar') !== false ||
                          stripos($current_avatar, 'gravatar.com') !== false);

    // 1. 如果用户已有有效本地头像（非默认SVG）
    if (!$is_svg_or_default) {
        // 若远程记录 Key 为空，补全元数据并返回已有头像，避免再次触发下载
        if (empty($stored_remote_url)) {
            if (!empty($remote_avatar_url)) {
                update_user_meta($user_id, $meta_remote_key, $remote_avatar_url);
            }
            return $current_avatar;
        }
        // 若远程 URL 未改变，直接复用现有头像
        if ($stored_remote_url === $remote_avatar_url) {
            return $current_avatar;
        }
    }

    // 2. 如果远程 URL 为空
    if (empty($remote_avatar_url)) {
        if ($is_svg_or_default && function_exists('todo_user_avatar_get_or_create')) {
            return todo_user_avatar_get_or_create($user_id);
        }
        return $current_avatar;
    }

    // 3. 需要下载或更新：下载远程图片到本地媒体库
    $local_avatar_url = '';
    if (function_exists('todo_download_remote_image')) {
        $avatar_filename = 'avatar_' . $provider . '_' . $user_id . '_' . substr(md5($remote_avatar_url), 0, 6) . '.jpg';
        $download_res = todo_download_remote_image($remote_avatar_url, 0, $avatar_filename);
        if (!is_todo_error($download_res) && !empty($download_res)) {
            $local_avatar_url = $download_res;
        }
    }

    $final_avatar = !empty($local_avatar_url) ? $local_avatar_url : $remote_avatar_url;

    // 更新用户 avatar 及 远程记录 key
    update_user_meta($user_id, 'avatar', $final_avatar);
    update_user_meta($user_id, $meta_remote_key, $remote_avatar_url);

    return $final_avatar;
}

/**
 * 获取用户头像URL
 *
 * @param int $user_id 用户ID
 * @param int $size 头像大小
 * @return string 头像URL
 */
function get_user_avatar_url($user_id, $size = 96)
{
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return '';
    }

    $avatar = todo_user_avatar_get_or_create($user_id);
    if ($avatar === '') {
        return '';
    }

    return todo_user_avatar_normalize_url($avatar);
}

/**
 * 获取用户显示名称
 *
 * @param int $user_id 用户ID
 * @return string 显示名称
 */
function get_user_display_name($user_id)
{
    $user = new CMS_User($user_id);
    return $user->get_display_name();
}

/**
 * 根据ID获取用户
 *
 * @param int $user_id 用户ID
 * @return CMS_User|false 用户对象或false
 */
function get_user_by_id($user_id)
{
    return get_user_by('id', $user_id);
}

/**
 * 获取当前用户
 *
 * @return CMS_User 当前用户对象
 */
/* todocms: removed wrapper to avoid conflict with PHP built-in get_current_user() */

/**
 * 获取用户数据
 *
 * @param int $user_id 用户 ID
 * @return object|false 用户对象或 false
 */
function get_userdata($user_id)
{
    if (!$user_id) {
        return false;
    }

    $user = get_user_by('id', $user_id);

    // get_user_by returns a CMS_User object. 
    // We need to ensure it behaves like the stdClass expected by some legacy code, 
    // but CMS_User::__get handles property access, so it should be fine for most cases.
    // If strict stdClass is required, we might need to return $user->data, but $user itself is better for method access.
    // Given the usage in get_the_author_meta ($user->ID, $user->user_login), CMS_User object works.

    return $user;
}

/**
 * 获取用户数据 (别名)
 */
function todo_get_user($user_id)
{
    return get_userdata($user_id);
}

/**
 * 获取用户头像HTML
 *
 * @param string $email 用户邮箱
 * @param int $size 头像大小
 * @param string $default 默认头像类型
 * @param string $alt 替代文本
 * @return string 头像HTML
 */
if (!function_exists('get_avatar')) {
    function get_avatar($email, $size = 96, $default = 'mp', $alt = '')
    {
        if (empty($email)) {
            return '';
        }

        $url = '';
        $email = strtolower(trim((string) $email));
        $user = get_user_by('email', $email);
        if ($user && is_object($user) && is_callable([$user, 'get_id'])) {
            $url = get_user_avatar_url($user->get_id(), $size);
        }
        if ($url === '') {
            if (function_exists('todo_admin_url')) {
                $url = todo_admin_url('assets/images/default-avatar.svg');
            } elseif (function_exists('home_url')) {
                $url = home_url('/admin/assets/images/default-avatar.svg');
            } else {
                $url = '/admin/assets/images/default-avatar.svg';
            }
        }

        // 生成alt文本
        if (empty($alt)) {
            $alt = '用户头像';
        }

        return '<img src="' . esc_url($url) . '" alt="' . esc_attr($alt) . '" width="' . $size . '" height="' . $size . '" class="avatar avatar-' . $size . '">';
    }
}

/**
 * 自动段落格式化
 *
 * @param string $text 要格式化的文本
 * @param bool $br 是否将换行符转换为<br>
 * @return string 格式化后的文本
 */
if (!function_exists('autop')) {
    function autop($text, $br = true)
    {
        if (trim($text) === '') {
            return '';
        }

        // 标准化换行符
        $text = str_replace(array("\r\n", "\r"), "\n", $text);

        // 移除多余的空白行
        $text = preg_replace('/\n\s*\n/', "\n\n", $text);

        // 在块级元素前后添加换行
        $block_elements = 'address|article|aside|blockquote|details|dialog|dd|div|dl|dt|fieldset|figcaption|figure|footer|form|h[1-6]|header|hgroup|hr|li|main|nav|ol|p|pre|section|table|ul';
        $text = preg_replace('/(<(?:' . $block_elements . ')[^>]*>)/i', "\n$1", $text);
        $text = preg_replace('/(<\/(?:' . $block_elements . ')>)/i', "$1\n", $text);

        // 将双换行符转换为段落
        $text = preg_replace('/\n\n+/', "</p>\n\n<p>", $text);

        // 包装在段落标签中
        $text = '<p>' . $text . '</p>';

        // 清理空段落
        $text = preg_replace('/<p>\s*<\/p>/', '', $text);

        // 处理单换行符
        if ($br) {
            $text = preg_replace('/(?<!<\/p>)\n(?!<p>)/', "<br>\n", $text);
        }

        // 清理块级元素内的段落标签
        $text = preg_replace('/<p>(<(?:' . $block_elements . ')[^>]*>)/i', '$1', $text);
        $text = preg_replace('/(<\/(?:' . $block_elements . ')>)<\/p>/i', '$1', $text);

        return trim($text);
    }
}

/**
 * 检查当前用户是否为管理员
 *
 * @return bool 是否为管理员
 */
if (!function_exists('is_admin')) {
    function is_admin()
    {
        if (function_exists('current_user_can')) {
            return current_user_can('manage_options');
        }

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        // 使用 Todo_Session_Manager 检查管理员状态
        if (class_exists('Todo_Session_Manager')) {
            Todo_Session_Manager::init();
            if (defined('IS_ADMIN') && IS_ADMIN) {
                return Todo_Session_Manager::is_admin_logged_in();
            }
        }

        return false;
    }
}

if (!function_exists('todo_is_super_admin')) {
    /**
     * 检查用户是否为超级管理员
     *
     * @param int $user_id 用户ID
     * @return bool 是否为超级管理员
     */
    function todo_is_super_admin($user_id = null)
    {
        if ($user_id === null) {
            // 移除过于宽泛的 manage_options 检查，统一走下方的 Root/Developer/Role 判定逻辑
            // 避免普通管理员被误认为超级管理员，导致“隐身模式”失效
            $user_id = get_current_user_id();
        }

        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return false;
        }

        // 超级管理员定义 (系统根权限/开发权限)
        // 1. ID为1固定为根管理员
        if ($user_id === 1) {
            return true;
        }

        // 2. 检查是否有特殊的开发标记 (is_developer)
        if (function_exists('get_user_meta')) {
            $is_developer = get_user_meta($user_id, 'is_developer', true);
            if ($is_developer === '1') {
                return true;
            }
        }

        // 3. 检查角色 (仅限 super_admin 显式角色)
        global $tododb;
        if (!$tododb) {
            return false;
        }
        $prefix = $tododb->prefix;
        try {
            // 先尝试从元数据中获取角色
            $role_meta = $tododb->get_var(
                "SELECT meta_value FROM {$prefix}user_meta WHERE user_id = ? AND meta_key = 'roles' LIMIT 1",
                [$user_id]
            );
            $role = '';
            if ($role_meta) {
                $roles = function_exists('maybe_unserialize') ? maybe_unserialize($role_meta) : $role_meta;
                if (is_array($roles) && !empty($roles)) {
                    $role = (string) reset($roles);
                } elseif (is_string($roles) && $roles !== '') {
                    $role = (string) $roles;
                }
            }
            // 备份方案：从用户表直接获取
            if ($role === '') {
                $role = (string) $tododb->get_var("SELECT role FROM {$prefix}users WHERE ID = ? LIMIT 1", [$user_id]);
            }
            $role = strtolower(trim($role));
            
            // 仅允许显式的 super_admin 角色；普通 admin/administrator 不再视为隐身账号可见者
            return in_array($role, ['super_admin', 'superadmin'], true);
        } catch (Throwable $e) {
            return false;
        }
    }
}

/**
 * 获取用户在指定站点的角色
 *
 * @param int $user_id 用户ID
 * @param int $site_id 站点ID
 * @return string|false 角色名称或 false
 */
if (!function_exists('todo_get_user_site_role')) {
    function todo_get_user_site_role($user_id, $site_id)
    {
        global $tododb;
        if (!$tododb) {
            return false;
        }

        $user_id = (int) $user_id;
        $site_id = (int) $site_id;
        if ($user_id <= 0 || $site_id <= 0) {
            return false;
        }

        // 超级管理员在所有站点都是管理员
        if (todo_is_super_admin($user_id)) {
            return 'admin';
        }

        $table = $tododb->prefix . 'user_site_roles';
        $status_col = 'status'; // 已手动添加

        return $tododb->get_var(
            "SELECT role FROM {$table} WHERE user_id = ? AND site_id = ? AND {$status_col} = 'active' LIMIT 1",
            [$user_id, $site_id]
        );
    }
}

/**
 * 获取用户有权限的所有站点ID
 *
 * @param int $user_id 用户ID
 * @return array 站点ID列表
 */
if (!function_exists('todo_get_user_sites')) {
    function todo_get_user_sites($user_id = null)
    {
        global $tododb;
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return [];
        }

        // 超级管理员可以看到所有站点
        if (todo_is_super_admin($user_id)) {
            $table = $tododb->prefix . 'sites';
            return (array) $tododb->get_col("SELECT id FROM {$table} WHERE status = 'active' ORDER BY id ASC");
        }

        $table = $tododb->prefix . 'user_site_roles';
        $sites = $tododb->get_col(
            "SELECT site_id FROM {$table} WHERE user_id = ? AND status = 'active'",
            [$user_id]
        );
        return array_map('intval', (array) $sites);
    }
}

/**
 * 检查用户是否有权限访问指定站点
 *
 * @param int $site_id 站点ID
 * @param int $user_id 用户ID
 * @return bool 是否有权限
 */
if (!function_exists('todo_user_can_access_site')) {
    function todo_user_can_access_site($site_id, $user_id = null)
    {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }
        
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return false;
        }

        if (todo_is_super_admin($user_id)) {
            return true;
        }

        // 拥有管理权限的管理员（如 administrator）也可以访问所有站点
        if ($user_id > 0) {
            global $tododb;
            $prefix = $tododb->prefix;
            $role_meta = $tododb->get_var("SELECT meta_value FROM {$prefix}user_meta WHERE user_id = ? AND meta_key = 'roles' LIMIT 1", [$user_id]);
            if ($role_meta) {
                $roles = function_exists('maybe_unserialize') ? maybe_unserialize($role_meta) : $role_meta;
                if (is_array($roles) && (in_array('administrator', $roles) || in_array('admin', $roles))) {
                    return true;
                }
            }
        }

        $role = todo_get_user_site_role($user_id, $site_id);
        return !empty($role);
    }
}
