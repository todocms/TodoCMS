<?php
/**
 * TodoCMS QQ登录发起跳转处理
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
}

require_once(ABSPATH . 'config.php');
require_once(ABSPATH . 'core/bootstrap.php');
require_once(ABSPATH . 'core/functions/function-loader.php');

Todo_Function_Loader::load_core_functions();

$qq_login_enabled = get_option('qq_login_enabled', '0');
$qq_app_id = trim((string) get_option('qq_app_id', ''));
$qq_redirect_uri = trim((string) get_option('qq_redirect_uri', ''));

if ($qq_redirect_uri === '') {
    $qq_redirect_uri = todo_get_site_url('/api/user/qq-callback.php');
}

if (!$qq_login_enabled || empty($qq_app_id)) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<script>alert("QQ登录未开启或尚未配置APP ID！"); history.back();</script>';
    exit;
}

if (class_exists('Todo_Session_Manager')) {
    Todo_Session_Manager::init();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 生成 CSRF 防御 state 状态码
$state = md5(uniqid(mt_rand(), true));
$_SESSION['qq_oauth_state'] = $state;

// 记录来源 redirect 地址（过滤掉登录页面本身，避免登录成功后又循环跳转回登录页）
$user_center_url = todo_get_user_center_url();
if (isset($_GET['redirect_to']) && !empty($_GET['redirect_to'])) {
    $_SESSION['qq_redirect_to'] = $_GET['redirect_to'];
} elseif (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
    $referer = $_SERVER['HTTP_REFERER'];
    if (strpos($referer, 'user=login') !== false || strpos($referer, '/login') !== false) {
        $_SESSION['qq_redirect_to'] = $user_center_url;
    } else {
        $_SESSION['qq_redirect_to'] = $referer;
    }
} else {
    $_SESSION['qq_redirect_to'] = $user_center_url;
}

$authorize_url = 'https://graph.qq.com/oauth2.0/authorize?' . http_build_query([
    'response_type' => 'code',
    'client_id'     => $qq_app_id,
    'redirect_uri'  => $qq_redirect_uri,
    'state'         => $state,
    'scope'         => 'get_user_info'
]);

if (class_exists('Todo_Error_Handler')) {
    Todo_Error_Handler::log(
        '[QQ Login Debug] Initiating QQ OAuth Redirect',
        Todo_Error_Handler::LEVEL_INFO,
        [
            'state' => $state,
            'redirect_to' => $_SESSION['qq_redirect_to'] ?? '',
            'referer' => $_SERVER['HTTP_REFERER'] ?? '',
            'session_name' => session_name(),
            'session_id' => session_id()
        ]
    );
}

header('Location: ' . $authorize_url);
exit;
