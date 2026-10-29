<?php
/**
 * 发送邮箱验证码接口
 *
 * @package TodoCMS
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

    // 检查是否为POST请求
    if (todo_server_string('REQUEST_METHOD') !== 'POST') {
        throw new Exception(__('只允许POST请求'));
    }

    // 检查AJAX请求
    if (!todo_is_ajax_request()) {
        throw new Exception(__('非法访问'));
    }

    // 验证CSRF令牌
    if (!function_exists('todo_verify_csrf_token')) {
        throw new Exception('CSRF验证函数未加载');
    }

    if (!todo_verify_csrf_token()) {
        throw new Exception(__('安全验证失败，请刷新页面重试'));
    }

    // 获取参数
    $email = trim(todo_post_string('email'));
    $type = trim(todo_post_string('type', 'register'));
    // PRG 防重复提交守卫（仅对携带 _submit_key 的 POST 生效）
    Todo_PRG_Guard::guard_endpoint('user_send_email_code', 30);

    $captcha = trim(todo_post_string('captcha'));

    // 验证参数
    if (empty($email)) {
        throw new Exception(__('请输入邮箱地址'));
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception(__('请输入正确的邮箱地址'));
    }

    if (empty($captcha)) {
        throw new Exception(__('请输入图形验证码'));
    }

    // 验证图形验证码
    if (!todo_verify_captcha($captcha)) {
        throw new Exception(__('图形验证码错误'));
    }

    // 根据类型检查邮箱
    if ($type === 'register') {
        if (function_exists('get_option') && !get_option('users_can_register', false)) {
            throw new Exception(__('网站目前已暂停新用户注册'));
        }
        if (email_exists($email)) {
            throw new Exception(__('该邮箱已被注册'));
        }
    } elseif ($type === 'forgot_password') {
        if (!email_exists($email)) {
            throw new Exception(__('该邮箱未注册'));
        }
    }

    // 生成验证码
    $code = (string) rand(100000, 999999);
    $expires_at = date('Y-m-d H:i:s', time() + 15 * 60); // 15分钟有效期

    global $tododb;

    // 保存验证码到数据库
    $table = $tododb->prefix . 'email_codes';
    $result = $tododb->insert($table, [
        'email' => $email,
        'code' => $code,
        'type' => $type,
        'expires_at' => $expires_at,
        'ip_address' => todo_server_string('REMOTE_ADDR')
    ]);

    if ($result === false) {
        throw new Exception(__('系统错误：无法保存验证码'));
    }

    // 发送邮件
    if (!todo_send_verification_code_email($email, $code, $type)) {
        throw new Exception(__('邮件发送失败'));
    }

    echo json_encode([
        'success' => true,
        'message' => __('验证码已发送到您的邮箱，请查收')
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
