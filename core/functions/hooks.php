<?php
/**
 * 钩子函数文件
 *
 * 提供TodoCMS的钩子函数
 *
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

// 注意：钩子函数现在由 todo-hooks.php 提供
// 这个文件保留用于向后兼容，但实际的函数定义在 todo-hooks.php 中

// 所有钩子函数现在统一由 todo-hooks.php 提供
// 包括：add_action, do_action, add_filter, apply_filters, remove_filter, remove_action
// has_filter, has_action, doing_action, current_action, current_filter 等