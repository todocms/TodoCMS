<?php
/**
 * TodoCMS 图形验证码统一生成入口（类型化生成器）
 *
 * 说明：
 * - 统一将请求重定向到核心类型化生成器 /core/class/captcha.php
 * - 保留对 type 参数的支持，默认值为 default
 * - 通过时间戳参数避免浏览器缓存
 */

// 启动session（如需要）
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 读取类型参数
$type = trim(function_exists('todo_get_string') ? todo_get_string('type', 'default') : ((isset($_GET['type']) && !is_array($_GET['type'])) ? (string) $_GET['type'] : 'default'));
if ($type === '') {
    $type = 'default';
}

// 构建目标URL（核心类型化生成器）
$timestamp = time();
$target = '/core/class/captcha.php?type=' . rawurlencode($type) . '&t=' . $timestamp;

// 设置无缓存响应头并进行重定向到统一生成器
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Location: ' . $target, true, 302);
exit;
