<?php
/**
 * 用户名清理函数
 *
 * 清理用户名，移除特殊字符和HTML标签
 *
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 清理用户名
 *
 * @param string $username 需要清理的用户名
 * @param bool $strict 是否严格模式
 * @return string 清理后的用户名
 */
function sanitize_user($username, $strict = false) {
    // 移除HTML标签
    $username = strip_tags($username);
    
    // 移除空白字符
    $username = trim($username);
    
    // 移除特殊字符
    $username = preg_replace('/[^a-zA-Z0-9\-_@.]+/', '', $username);
    
    // 应用钩子
    if (function_exists('apply_filters')) {
        $username = apply_filters('sanitize_user', $username, $strict);
    }
    
    return $username;
}