<?php
/**
 * TodoCMS 图形验证码验证API
 * 验证用户输入的验证码是否正确
 */

// 启动session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 设置JSON响应头
header('Content-Type: application/json; charset=utf-8');

// 检查是否为POST请求
if (todo_server_string('REQUEST_METHOD') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => '只允许POST请求'
    ]);
    exit;
}

// 检查AJAX请求
if (!todo_is_ajax_request()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => '非法访问'
    ]);
    exit;
}

try {
    $client_ip = function_exists('get_client_ip') ? get_client_ip() : todo_server_string('REMOTE_ADDR');

    // 检查锁定
    if (function_exists('todo_check_captcha_lockout')) {
        $lockout = todo_check_captcha_lockout($client_ip);
        if ($lockout) {
            $msg = $lockout['message'] ?? "操作频繁，请稍后再试";
            throw new Exception($msg);
        }
    }

    // 获取用户输入的验证码
    $user_code = trim(todo_post_string('captcha'));

    if (empty($user_code)) {
        throw new Exception('请输入验证码');
    }

    // 检查session中是否有验证码
    if (!isset($_SESSION['captcha_code']) || !isset($_SESSION['captcha_time'])) {
        throw new Exception('验证码已过期，请刷新');
    }

    // 检查验证码是否过期（5分钟有效期）
    if (time() - $_SESSION['captcha_time'] > 300) {
        // 清除过期的验证码
        unset($_SESSION['captcha_code'], $_SESSION['captcha_time']);
        throw new Exception('验证码已过期，请刷新');
    }

    // 验证码比较（不区分大小写）
    $session_code = $_SESSION['captcha_code'];
    $is_valid = strtolower($user_code) === strtolower($session_code);

    if (function_exists('todo_register_captcha_attempt')) {
        todo_register_captcha_attempt($client_ip, $is_valid, 'verify');
    }

    if ($is_valid) {
        // 验证成功，清除验证码（防止重复使用）
        unset($_SESSION['captcha_code'], $_SESSION['captcha_time']);

        echo json_encode([
            'success' => true,
            'message' => '验证码正确'
        ]);
    } else {
        // 验证失败，保留验证码但记录失败次数
        if (!isset($_SESSION['captcha_fail_count'])) {
            $_SESSION['captcha_fail_count'] = 0;
        }
        $_SESSION['captcha_fail_count']++;

        // 失败3次后清除验证码，强制刷新
        if ($_SESSION['captcha_fail_count'] >= 3) {
            unset($_SESSION['captcha_code'], $_SESSION['captcha_time'], $_SESSION['captcha_fail_count']);
            throw new Exception('验证码错误过多，请刷新');
        }

        throw new Exception('验证码错误');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
