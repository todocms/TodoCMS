<?php
/**
 * TodoCMS 前端用户退出登录处理器
 * 处理用户退出登录请求
 */

// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', 0);

// 定义 ABSPATH 常量（如果尚未定义）
if (!defined('ABSPATH')) {
    // api/user/logout-handler.php 在 api/user/ 目录下，需要向上两级到项目根目录
    define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
}

try {
    // 加载核心文件
    require_once(ABSPATH . 'config.php');
    require_once(ABSPATH . 'core/bootstrap.php');
    require_once(ABSPATH . 'core/functions/function-loader.php');

    // 使用函数加载器加载核心函数
    Todo_Function_Loader::load_core_functions();

} catch (Exception $e) {
    // 如果加载失败，直接重定向到首页
    $redirect_to = isset($_GET['redirect_to']) ? $_GET['redirect_to'] : '/';
    header('Location: ' . $redirect_to);
    exit;
}

// 确保会话已启动
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// 确保类已加载
if (!class_exists('Todo_Session_Manager')) {
    // 如果类不存在，可能是常量未定义，尝试定义后再检查
    if (!defined('TODO_LOADED')) {
        define('TODO_LOADED', true);
    }
    // 再次检查类是否存在（Composer 会自动加载）
    if (!class_exists('Todo_Session_Manager')) {
        throw new Exception('Todo_Session_Manager 类无法加载，请检查 Composer 自动加载配置');
    }
}

// 初始化会话管理器并执行标准注销
if (function_exists('todo_logout')) {
    todo_logout();
} else {
    if (class_exists('Todo_Session_Manager')) {
        Todo_Session_Manager::init();
        Todo_Session_Manager::clear_frontend_session();
    }
}

// 获取重定向URL
$redirect_to = isset($_GET['redirect_to']) ? $_GET['redirect_to'] : '/';
$redirect_to = filter_var($redirect_to, FILTER_SANITIZE_URL);

// 验证重定向URL，防止开放重定向攻击
if (!empty($redirect_to)) {
    // 如果是相对路径，使用它
    if (strpos($redirect_to, '/') === 0) {
        // 相对路径，安全
    } elseif (filter_var($redirect_to, FILTER_VALIDATE_URL)) {
        // 绝对URL，检查是否是同域
        $site_url = function_exists('todo_get_site_url') ? todo_get_site_url() : (function_exists('home_url') ? home_url() : '/');
        $site_host = parse_url($site_url, PHP_URL_HOST);
        $redirect_host = parse_url($redirect_to, PHP_URL_HOST);
        
        // 如果不是同域，使用默认重定向
        if ($site_host !== $redirect_host) {
            $redirect_to = '/';
        }
    } else {
        // 无效URL，使用默认
        $redirect_to = '/';
    }
} else {
    $redirect_to = '/';
}

// 添加退出成功消息（如果支持）
if (function_exists('todo_add_notice')) {
    todo_add_notice('您已成功退出登录', 'success');
}

// 重定向
if (function_exists('redirect')) {
    redirect($redirect_to);
} else {
    header('Location: ' . $redirect_to);
    exit;
}

