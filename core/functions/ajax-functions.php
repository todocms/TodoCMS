<?php
/**
 * TodoCMS AJAX 函数库
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问（但允许AJAX请求）
if (!defined('ABSPATH') && !defined('DOING_AJAX')) {
    exit('Direct access denied');
}

/**
 * AJAX错误响应
 *
 * @param string $message 错误消息
 * @param int $code 错误代码
 */
if (!function_exists('todo_ajax_error')) {
    function todo_ajax_error($message = '', $code = 400)
    {
        header('Content-Type: application/json');
        http_response_code($code);
        echo json_encode(array(
            'success' => false,
            'error' => $message
        ));
        exit;
    }
}

/**
 * 添加管理员通知
 *
 * @param string $message 通知消息
 * @param string $type 通知类型
 */
if (!function_exists('todo_add_admin_notice')) {
    function todo_add_admin_notice($message, $type = 'info')
    {
        if (!session_id()) {
            session_start();
        }
        if (!isset($_SESSION['admin_notices'])) {
            $_SESSION['admin_notices'] = array();
        }
        $_SESSION['admin_notices'][] = array(
            'message' => $message,
            'type' => $type
        );
    }
}

/**
 * 显示管理员通知
 *
 * @param string $message 通知消息
 * @param string $type 通知类型
 */
if (!function_exists('todo_admin_notice')) {
    function todo_admin_notice($message, $type = 'info')
    {
        echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html($message) . '</p></div>';
    }
}

/**
 * 管理员终止执行
 *
 * @param string $message 错误消息
 */
if (!function_exists('todo_admin_die')) {
    function todo_admin_die($message = '')
    {
        if (!empty($message)) {
            echo '<div class="error"><p>' . esc_html($message) . '</p></div>';
        }
        exit;
    }
}