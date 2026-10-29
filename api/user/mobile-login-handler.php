<?php
/**
 * TodoCMS 前端用户手机登录处理器
 * 处理手机号+短信验证码登录
 */

// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', 0);

// 设置JSON响应头
header('Content-Type: application/json; charset=utf-8');

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
Todo_PRG_Guard::guard_endpoint('user_mobile_login', 30);

try {
    // 验证CSRF令牌
    $nonce_field = '_todononce';
    $token = todo_post_string($nonce_field);
    if ($token === '') {
        $token = todo_post_string('token');
    }
    if ($token === '') {
        $token = todo_post_string('_token');
    }

    $csrf_valid = false;
    if (function_exists('todo_verify_csrf_token')) {
        $csrf_valid = (!empty($token) && todo_verify_csrf_token($token, 'mobile_login'))
            || (!empty($token) && todo_verify_csrf_token($token, 'login'))
            || (!empty($token) && todo_verify_csrf_token($token, 'default'))
            || todo_verify_csrf_token($token)
            || todo_verify_csrf_token();
    } elseif (class_exists('Todo_Security_Manager')) {
        $csrf_valid = (!empty($token) && Todo_Security_Manager::verify_csrf_token($token, 'mobile_login'))
            || Todo_Security_Manager::verify_csrf_token($token, 'default');
    }

    if (!$csrf_valid) {
        throw new Exception(__('安全验证失败，请刷新页面重试'));
    }

    // 获取登录数据
    $mobile = trim(todo_post_string('mobile'));
    $captcha = trim(todo_post_string('captcha'));
    $sms_code = trim(todo_post_string('sms_code'));
    $remember = todo_post_string('remember') === '1';

    // 基础验证
    if (empty($mobile)) {
        throw new Exception(__('请输入手机号码'));
    }

    if (!preg_match('/^1[3-9]\d{9}$/', $mobile)) {
        throw new Exception(__('请输入正确的手机号码'));
    }

    if (empty($captcha)) {
        $captcha = 'slider';
    }

    if (empty($sms_code)) {
        throw new Exception(__('请输入短信验证码'));
    }

    if (!preg_match('/^\d{6}$/', $sms_code)) {
        throw new Exception(__('请输入短信验证码'));
    }

    // 验证图形验证码（占位符slider自动放行）
    if ($captcha !== 'slider' && $captcha !== 'placeholder') {
        if (!function_exists('todo_verify_captcha')) {
            throw new Exception(__('验证码验证函数未加载'));
        }

        if (!todo_verify_captcha($captcha, 'users_mobile_login') && !todo_verify_captcha($captcha)) {
            throw new Exception(__('图形验证码错误'));
        }
    }

    // 验证短信验证码
    if (!todo_verify_sms_code($mobile, $sms_code, 'login')) {
        throw new Exception(__('短信验证码错误或已过期'));
    }

    // 根据手机号查找用户
    if (!function_exists('get_user_by_mobile')) {
        throw new Exception(__('用户查询函数未加载'));
    }

    $user = get_user_by_mobile($mobile);

    if (!$user) {
        throw new Exception(__('该手机号未注册'));
    }

    // 检查用户状态
    if (method_exists($user, 'get_status') && $user->get_status() !== 'active') {
        throw new Exception(__('账户已被禁用或注销，请联系管理员'));
    }

    $user_id = method_exists($user, 'get_id') ? $user->get_id() : (isset($user->ID) ? $user->ID : 0);

    // 解析用户角色
    $sess_role = 'subscriber';
    if (is_callable([$user, 'get_roles'])) {
        $roles = $user->get_roles();
        if (is_array($roles) && !empty($roles)) {
            $sess_role = reset($roles);
        }
    } elseif (is_callable([$user, 'get_meta'])) {
        $roles_meta = $user->get_meta('roles', []);
        if (is_array($roles_meta) && !empty($roles_meta)) {
            $sess_role = reset($roles_meta);
        }
    }

    // 设置用户会话
    if (class_exists('Todo_Session_Manager')) {
        Todo_Session_Manager::init();
        Todo_Session_Manager::set_frontend_session($user_id, $sess_role, $remember);
    } elseif (function_exists('todo_set_user_session')) {
        todo_set_user_session($user, $remember);
    } else {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['user_id'] = $user_id;
        $_SESSION['user_role'] = $sess_role;
    }

    // 同步购物车
    if (class_exists('Todo_Cart_Manager') && method_exists('Todo_Cart_Manager', 'sync_after_login')) {
        Todo_Cart_Manager::sync_after_login($user_id);
    }

    // 更新最后登录时间
    if (is_callable([$user, 'update_last_login'])) {
        $user->update_last_login();
    } elseif (function_exists('update_user_meta')) {
        $now = date('Y-m-d H:i:s');
        update_user_meta($user_id, 'last_login', $now);
        update_user_meta($user_id, '_last_login', $now);
    }

    // 记录登录日志
    if (function_exists('todo_log_user_action')) {
        todo_log_user_action($user->get_id(), 'login', [
            'ip' => todo_server_string('REMOTE_ADDR'),
            'user_agent' => todo_server_string('HTTP_USER_AGENT'),
            'login_type' => 'mobile'
        ]);
    }

    // 获取重定向URL
    $redirect_url = todo_get_user_center_url();
    if (todo_post_string('redirect') !== '') {
        $redirect_url = sanitize_url(todo_post_string('redirect'));
    }

    // 返回成功响应
    echo json_encode([
        'success' => true,
        'message' => __('登录成功'),
        'data' => [
            'user_id' => $user->get_id(),
            'username' => $user->get_username(),
            'display_name' => $user->get_display_name(),
            'redirect_url' => $redirect_url
        ]
    ]);

} catch (Exception $e) {
    // 记录错误日志
    if (function_exists('todo_log_error')) {
        todo_log_error('Mobile Login Error', $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'mobile' => $mobile ?? '',
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
