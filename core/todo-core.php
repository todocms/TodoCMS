<?php
/**
 * TodoCMS 核心文件
 * 加载所有必要的核心组件和功能
 * 
 * 注意：此文件已废弃，功能已迁移到index.php和bootstrap.php
 * 通过Composer自动加载机制加载
 * 
 * @package TodoCMS
 * @version 1.0.0
 * @deprecated 此文件已废弃，不应直接使用
 */

// 加载引导文件
require_once ABSPATH . 'core/bootstrap.php';

// 加载核心函数
require_once ABSPATH . 'core/functions/todo-core-functions.php';
require_once ABSPATH . 'core/functions/user-functions.php';
require_once ABSPATH . 'core/functions/security-functions.php';
require_once ABSPATH . 'core/functions/utility-functions.php';
require_once ABSPATH . 'core/functions/template.php';
require_once ABSPATH . 'core/functions/missing-core-functions.php';

// 初始化数据库连接
if (!isset($GLOBALS['tododb'])) {
    $GLOBALS['tododb'] = new TodoDatabase();
}

// 初始化模板解析器
if (!isset($GLOBALS['todo_template_parser'])) {
    $GLOBALS['todo_template_parser'] = new TodoTemplateParser();
}

// 初始化HTML标签处理器
if (!isset($GLOBALS['todo_html_tags'])) {
    $GLOBALS['todo_html_tags'] = new TodoHtmlTemplateTags();
}

// 启动会话（如果尚未启动）
if (!session_id()) {
    session_start();
}

// 设置错误处理
if (defined('TODO_DEBUG') && TODO_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// 设置时区
if (function_exists('date_default_timezone_set')) {
    $timezone = get_option('timezone_string', 'Asia/Shanghai');
    date_default_timezone_set($timezone);
}

// 加载已激活的插件
todo_load_active_plugins();

// 触发核心加载完成钩子
do_action('todo_core_loaded');
