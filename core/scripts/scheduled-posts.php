<?php
/**
 * TodoCMS 定时发布后台脚本
 * 独立运行，避免阻塞前台请求
 */

// 仅允许CLI环境运行，防止通过Web触发
if (php_sapi_name() !== 'cli') {
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo 'Direct access denied: CLI only.';
    exit;
}

// 禁用计划任务钩子注册，避免递归与多次触发
if (!defined('TODO_DISABLE_SCHEDULED_TASKS')) {
    define('TODO_DISABLE_SCHEDULED_TASKS', true);
}

// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../');
}

// 执行定时发布检查
if (function_exists("todo_run_scheduled_posts")) {
    todo_run_scheduled_posts();
} else {
    error_log("TodoCMS: Scheduled posts function not found in background script");
}
