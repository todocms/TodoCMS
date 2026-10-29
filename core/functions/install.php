<?php
/**
 * 安装相关函数
 *
 * @package TodoCMS
 * @subpackage Core
 * @version 1.0.0
 */

if (!defined('ABSPATH') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

// 确保加载schema.php文件，其中包含了is_cms_installed函数的定义
if (!function_exists('is_cms_installed')) {
    require_once(ABSPATH . 'core/db/schema.php');
}