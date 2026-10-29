<?php
/**
 * TodoCMS 实时用户状态与登录信息 AJAX 接口
 * 专门用于静态页面全缓存架构下的客户端 DOM 动态渲染
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
}

// 确保响应类型为 JSON 且禁用浏览器 HTTP 强缓存
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

try {
    require_once ABSPATH . 'config.php';
    require_once ABSPATH . 'core/bootstrap.php';
    require_once ABSPATH . 'core/functions/function-loader.php';

    if (class_exists('Todo_Function_Loader')) {
        Todo_Function_Loader::load_core_functions();
    }

    if (class_exists('Todo_Session_Manager')) {
        Todo_Session_Manager::init();
    } elseif (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (function_exists('is_user_logged_in') && is_user_logged_in()) {
        $user_id = function_exists('get_current_user_id') ? get_current_user_id() : 0;
        $current_user = function_exists('get_user_by') ? get_user_by('id', $user_id) : null;
        
        $display_name = '用户';
        if ($current_user) {
            $display_name = !empty($current_user->display_name) ? $current_user->display_name : (!empty($current_user->user_login) ? $current_user->user_login : '用户');
        }

        $avatar_url = function_exists('get_user_avatar_url') ? get_user_avatar_url($user_id) : '';

        echo json_encode([
            'success' => true,
            'logged_in' => true,
            'user' => [
                'id' => $user_id,
                'display_name' => $display_name,
                'avatar' => $avatar_url
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        echo json_encode([
            'success' => true,
            'logged_in' => false
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (\Throwable $e) {
    echo json_encode([
        'success' => false,
        'logged_in' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
