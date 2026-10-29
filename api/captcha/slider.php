<?php
/**
 * 前台图形滑块验证码接口
 *
 * @package TodoCMS
 */

// 开启输出缓冲
ob_start();

// 加载核心引导文件
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/core/bootstrap.php';
require_once CORE_DIR . '/functions/function-loader.php';
Todo_Function_Loader::load_core_functions();

// 确保安全扩展和会话
if (file_exists(CORE_DIR . '/functions/security-extend.php')) {
    require_once CORE_DIR . '/functions/security-extend.php';
}

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

header('Content-Type: application/json; charset=utf-8');

$action = todo_post_string('action');
$client_ip = function_exists('get_client_ip') ? get_client_ip() : todo_server_string('REMOTE_ADDR');

if ($action === 'get_captcha') {
    // 检查是否被锁定
    if (function_exists('todo_check_captcha_lockout')) {
        $lockout = todo_check_captcha_lockout($client_ip);
        if ($lockout) {
            echo json_encode(['success' => false, 'message' => sprintf(__('操作频繁，%s 分钟后再试'), $lockout['wait_minutes'])]);
            exit;
        }
    }

    // 检查是否刷新过于频繁
    if (function_exists('todo_check_captcha_refresh_lockout')) {
        $refresh_lockout = todo_check_captcha_refresh_lockout($client_ip);
        if ($refresh_lockout) {
            echo json_encode(['success' => false, 'message' => $refresh_lockout['message']]);
            exit;
        }
    }

    // 记录刷新/生成尝试
    if (function_exists('todo_register_captcha_attempt')) {
        todo_register_captcha_attempt($client_ip, true, 'refresh');
    }

    // 生成滑动验证码
    require_once CORE_DIR . '/classes/class-sliding-captcha.php';
    $captcha = new Todo_Sliding_Captcha();
    ob_end_clean();
    echo json_encode($captcha->create());
    exit;
} elseif ($action === 'verify_captcha') {
    // 检查锁定
    if (function_exists('todo_check_captcha_lockout')) {
        $lockout = todo_check_captcha_lockout($client_ip);
        if ($lockout) {
            echo json_encode(['success' => false, 'message' => sprintf(__('操作频繁，%s 分钟后再试'), $lockout['wait_minutes'])]);
            exit;
        }
    }

    require_once CORE_DIR . '/classes/class-sliding-captcha.php';
    $captcha = new Todo_Sliding_Captcha();

    // 1. 算力 (PoW) 校验
    $pow_nonce = todo_post_string('pow_nonce');
    if (!$captcha->verify_pow($pow_nonce)) {
        echo json_encode(['success' => false, 'message' => __('前置校验失败')]);
        exit;
    }

    // 2. 坐标与拖拽轨迹校验
    $x = (float) todo_post_string('x', '0');
    $raw_track = todo_post_string('track', '[]');
    $track = is_string($raw_track) ? json_decode($raw_track, true) : (is_array($raw_track) ? $raw_track : []);

    if ($captcha->verify($x, $track)) {
        $_SESSION['todo_captcha_verified'] = true;
        if (function_exists('todo_register_captcha_attempt')) {
            todo_register_captcha_attempt($client_ip, true, 'verify');
        }
        ob_end_clean();
        echo json_encode(['success' => true]);
    } else {
        $_SESSION['todo_captcha_verified'] = false;
        if (function_exists('todo_register_captcha_attempt')) {
            todo_register_captcha_attempt($client_ip, false, 'verify');
        }
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => __('验证失败，请重试')]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => __('非法请求')]);
exit;
