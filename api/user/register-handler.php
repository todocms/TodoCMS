<?php
/**
 * TodoCMS 前端用户注册处理器
 * 支持用户名+密码注册和手机号+验证码注册
 */

// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', 0);

// 设置JSON响应头
header('Content-Type: application/json; charset=utf-8');

// 定义 ABSPATH 常量（如果尚未定义）
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(__DIR__)) . '/');
}

try {
    // 加载核心文件
    require_once(ABSPATH . 'config.php');
    require_once(ABSPATH . 'core/bootstrap.php');
    require_once(ABSPATH . 'core/functions/function-loader.php');

    // 使用函数加载器加载核心函数
    Todo_Function_Loader::load_core_functions();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Core loading error: ' . $e->getMessage()
    ]);
    exit;
}

// 检查是否为POST请求
if (todo_server_string('REQUEST_METHOD') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => __('只允许POST请求')
    ]);
    exit;
}

// 检查AJAX请求
if (!todo_is_ajax_request()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => __('非法访问')
    ]);
    exit;
}

// PRG 防重复提交守卫（仅对携带 _submit_key 的 POST 生效）
Todo_PRG_Guard::guard_endpoint('user_register', 30);

try {
    // 验证CSRF令牌
    if (!function_exists('todo_verify_csrf_token')) {
        throw new Exception('CSRF验证函数未加载');
    }

    if (!todo_verify_csrf_token()) {
        throw new Exception(__('安全验证失败，请刷新页面重试'));
    }

    // 检查系统是否开启允许任何人注册
    if (function_exists('get_option') && !get_option('users_can_register', false)) {
        throw new Exception(__('网站目前已暂停新用户注册'));
    }

    // 获取注册类型（仅保留 email 和 mobile）
    $register_type = trim(todo_post_string('register_type', 'email'));

    if (!in_array($register_type, ['email', 'mobile'])) {
        throw new Exception(__('无效的注册类型'));
    }

    // 根据注册类型处理
    if ($register_type === 'email') {
        handle_email_register();
    } else {
        handle_mobile_register();
    }

} catch (Exception $e) {
    // 记录错误日志
    if (function_exists('todo_log_error')) {
        todo_log_error('User Register Error', $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'register_type' => $register_type ?? '',
            'ip' => todo_server_string('REMOTE_ADDR')
        ]);
    }

    // 返回错误响应
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * 处理邮箱注册
 */
function handle_email_register()
{
    // 获取注册数据
    $email = trim(todo_post_string('email'));
    $password = trim(todo_post_string('password'));
    $confirm_password = trim(todo_post_string('confirm_password'));
    $email_code = trim(todo_post_string('email_code'));
    $captcha = trim(todo_post_string('captcha'));
    $agree_terms = todo_post_string('agreement') === '1';

    // 基础验证
    if (empty($email)) {
        throw new Exception(__('请输入邮箱地址'));
    }

    if (empty($password)) {
        throw new Exception(__('请输入密码'));
    }

    if (empty($confirm_password)) {
        throw new Exception(__('请确认密码'));
    }

    if (empty($email_code)) {
        throw new Exception(__('请输入邮箱验证码'));
    }

    if (empty($captcha)) {
        throw new Exception(__('请输入验证码'));
    }

    if (!$agree_terms) {
        throw new Exception(__('请同意用户协议和隐私政策'));
    }

    // 详细验证
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception(__('请输入正确的邮箱地址'));
    }

    if (strlen($password) < 6) {
        throw new Exception(__('密码长度不能少于6位'));
    }

    if ($password !== $confirm_password) {
        throw new Exception(__('两次输入的密码不一致'));
    }

    // 验证图形验证码
    if (!todo_verify_captcha($captcha)) {
        throw new Exception(__('验证码错误'));
    }

    // 验证邮箱验证码
    if (!todo_verify_email_code($email, $email_code, 'register')) {
        throw new Exception(__('邮箱验证码错误或已过期'));
    }

    // 检查邮箱是否已存在
    if (email_exists($email)) {
        throw new Exception(__('邮箱已存在'));
    }

    // 自动生成唯一用户名
    $email_prefix = strstr($email, '@', true) ?: 'user';
    $email_prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $email_prefix);
    if (strlen($email_prefix) < 3) {
        $email_prefix = 'user_' . $email_prefix;
    }
    $username = substr($email_prefix, 0, 14) . rand(100, 999);
    while (username_exists($username)) {
        $username = substr($email_prefix, 0, 12) . rand(1000, 9999);
    }

    // 创建用户
    $rand_suffix = substr(md5(uniqid('', true)), 0, 6);
    $user_data = [
        'user_login' => $username,
        'user_email' => $email,
        'user_pass' => $password,
        'user_nicename' => 'user_' . $rand_suffix,
        'display_name' => __('用户') . '_' . $rand_suffix,
        'user_registered' => function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s'),
        'user_status' => 0
    ];

    $user_id = todo_insert_user($user_data);

    if (is_todo_error($user_id)) {
        throw new Exception(__('注册失败') . ': ' . $user_id->get_error_message());
    }

    // 设置用户角色
    if (class_exists('CMS_User')) {
        $user = new CMS_User($user_id);
        $user->set_role('subscriber');
    } elseif (class_exists('Todo_User')) {
        $user = new Todo_User($user_id);
        $user->set_role('subscriber');
    }

    // 记录注册日志
    if (function_exists('todo_log_user_action')) {
        todo_log_user_action($user_id, 'register', [
            'type' => 'email',
            'ip' => todo_server_string('REMOTE_ADDR'),
            'user_agent' => todo_server_string('HTTP_USER_AGENT')
        ]);
    }

    // 自动登录新注册用户
    if (!class_exists('Todo_Session_Manager')) {
        if (!defined('TODO_LOADED')) {
            define('TODO_LOADED', true);
        }
    }
    if (class_exists('Todo_Session_Manager')) {
        Todo_Session_Manager::init();
        Todo_Session_Manager::set_frontend_session($user_id, 'subscriber', true);
    }
    if (class_exists('Todo_Cart_Manager') && method_exists('Todo_Cart_Manager', 'sync_after_login')) {
        Todo_Cart_Manager::sync_after_login($user_id);
    }

    $redirect_url = function_exists('todo_get_user_center_url') ? todo_get_user_center_url() : site_url('?user=index');
    if (todo_post_string('redirect') !== '') {
        $redirect_url = sanitize_url(todo_post_string('redirect'));
    }

    // 返回成功响应
    echo json_encode([
        'success' => true,
        'message' => __('注册成功，已为您自动登录'),
        'data' => [
            'user_id' => $user_id,
            'email' => $email,
            'redirect_url' => $redirect_url
        ]
    ]);
}

/**
 * 处理手机号注册
 */
function handle_mobile_register()
{
    // 获取注册数据
    $mobile = trim(todo_post_string('mobile'));
    $sms_code = trim(todo_post_string('sms_code'));
    $password = trim(todo_post_string('password'));
    $confirm_password = trim(todo_post_string('confirm_password'));
    $captcha = trim(todo_post_string('captcha'));
    $agree_terms = todo_post_string('agreement') === '1';

    // 基础验证
    if (empty($mobile)) {
        throw new Exception(__('请输入手机号码'));
    }

    if (empty($sms_code)) {
        throw new Exception(__('请输入短信验证码'));
    }

    if (empty($password)) {
        throw new Exception(__('请输入密码'));
    }

    if (empty($confirm_password)) {
        throw new Exception(__('请确认密码'));
    }

    if (empty($captcha)) {
        throw new Exception(__('请输入图形验证码'));
    }

    if (!$agree_terms) {
        throw new Exception(__('请同意用户协议和隐私政策'));
    }

    // 详细验证
    if (!preg_match('/^1[3-9]\d{9}$/', $mobile)) {
        throw new Exception(__('请输入正确的手机号码'));
    }

    if (strlen($password) < 6) {
        throw new Exception(__('密码长度不能少于6位'));
    }

    if ($password !== $confirm_password) {
        throw new Exception(__('两次输入的密码不一致'));
    }

    // 验证图形验证码
    if (!todo_verify_captcha($captcha)) {
        throw new Exception(__('图形验证码错误'));
    }

    // 验证短信验证码
    if (!todo_verify_sms_code($mobile, $sms_code, 'register')) {
        throw new Exception(__('短信验证码错误或已过期'));
    }

    // 检查手机号是否已存在
    if (todo_mobile_exists($mobile)) {
        throw new Exception(__('手机号已被注册'));
    }

    // 生成用户名（使用手机号）
    $username = 'user_' . $mobile;
    $counter = 1;
    while (username_exists($username)) {
        $username = 'user_' . $mobile . '_' . $counter;
        $counter++;
    }

    $host = todo_server_string('HTTP_HOST', 'invalid.local');
    $host = strtolower($host);
    $host = preg_replace('/[^a-z0-9.-]+/i', '', $host);
    if ($host === '' || $host === 'localhost') {
        $host = 'invalid.local';
    }

    $generated_email = 'tel_' . $mobile . '@' . $host;
    if (function_exists('email_exists')) {
        $suffix = 1;
        while (email_exists($generated_email)) {
            $generated_email = 'tel_' . $mobile . '_' . $suffix . '@' . $host;
            $suffix++;
            if ($suffix > 50) {
                $generated_email = 'tel_' . $mobile . '_' . substr(md5(uniqid('', true)), 0, 8) . '@' . $host;
                break;
            }
        }
    }

    // 创建用户
    $user_data = [
        'user_login' => $username,
        'user_email' => $generated_email,
        'user_pass' => $password,
        'user_nicename' => $username,
        'display_name' => $username,
        'user_registered' => function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s'),
        'user_status' => 0
    ];

    $user_id = todo_insert_user($user_data);

    if (is_todo_error($user_id)) {
        throw new Exception(__('注册失败：') . $user_id->get_error_message());
    }

    // 保存手机号到用户元数据
    update_user_meta($user_id, 'mobile', $mobile);
    update_user_meta($user_id, 'phone', $mobile);

    // 设置用户角色
    if (class_exists('CMS_User')) {
        $user = new CMS_User($user_id);
        $user->set_role('subscriber');
    } elseif (class_exists('Todo_User')) {
        $user = new Todo_User($user_id);
        $user->set_role('subscriber');
    }

    // 记录注册日志
    if (function_exists('todo_log_user_action')) {
        todo_log_user_action($user_id, 'register', [
            'type' => 'mobile',
            'mobile' => $mobile,
            'ip' => todo_server_string('REMOTE_ADDR'),
            'user_agent' => todo_server_string('HTTP_USER_AGENT')
        ]);
    }

    // 自动登录新注册用户
    if (!class_exists('Todo_Session_Manager')) {
        if (!defined('TODO_LOADED')) {
            define('TODO_LOADED', true);
        }
    }
    if (class_exists('Todo_Session_Manager')) {
        Todo_Session_Manager::init();
        Todo_Session_Manager::set_frontend_session($user_id, 'subscriber', true);
    }
    if (class_exists('Todo_Cart_Manager') && method_exists('Todo_Cart_Manager', 'sync_after_login')) {
        Todo_Cart_Manager::sync_after_login($user_id);
    }

    $redirect_url = function_exists('todo_get_user_center_url') ? todo_get_user_center_url() : site_url('?user=index');
    if (todo_post_string('redirect') !== '') {
        $redirect_url = sanitize_url(todo_post_string('redirect'));
    }

    // 返回成功响应
    echo json_encode([
        'success' => true,
        'message' => __('注册成功，已为您自动登录'),
        'data' => [
            'user_id' => $user_id,
            'username' => $username,
            'mobile' => $mobile,
            'redirect_url' => $redirect_url
        ]
    ]);
}

function todo_mobile_exists($mobile)
{
    $mobile = trim((string) $mobile);
    if ($mobile === '') {
        return false;
    }

    $digits = preg_replace('/\\D+/', '', $mobile);

    global $tododb;
    if (!$tododb && function_exists('get_database_connection')) {
        $tododb = get_database_connection();
    }
    if (!$tododb) {
        return false;
    }

    $user_id = (int) $tododb->get_var(
        "SELECT ID FROM {$tododb->prefix}users WHERE (user_login = ? OR user_login = ?) AND user_status != -1 LIMIT 1",
        [$mobile, 'user_' . $mobile]
    );
    if ($user_id > 0) {
        return true;
    }

    $meta_user_id = (int) $tododb->get_var(
        "SELECT um.user_id FROM {$tododb->prefix}user_meta um
         INNER JOIN {$tododb->prefix}users u ON u.ID = um.user_id
         WHERE u.user_status != -1 
           AND um.meta_key IN ('mobile','phone') 
           AND (um.meta_value = ? OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(um.meta_value,' ',''),'-',''),'(',''),')',''),'.',''),'+','') = ?) 
         LIMIT 1",
        [$mobile, $digits]
    );

    return $meta_user_id > 0;
}

/**
 * 获取登录页面URL
 * 标准化使用 ?user=login 格式
 */
if (!function_exists('todo_get_login_url')) {
    function todo_get_login_url()
    {
        if (function_exists('get_login_url')) {
            return get_login_url();
        }
        // 回退方案：使用标准化格式
        return site_url('?user=login');
    }
}
