<?php
/**
 * Core Constants Definition
 * 
 * Defines constants that must be available before any class loading.
 * This file is loaded via Composer's "files" autoload mechanism.
 * 
 * @package TodoCMS
 */

// ==================== 基础路径常量 ====================

// Define ABSPATH - 项目根目录
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(__FILE__)) . '/');
}

// Define CORE_DIR - 核心目录
if (!defined('CORE_DIR')) {
    define('CORE_DIR', ABSPATH . 'core');
}

// Define ADMIN_DIR - 后台目录
if (!defined('ADMIN_DIR')) {
    define('ADMIN_DIR', ABSPATH . 'admin');
}

// Define INCLUDES_DIR - 包含文件目录
if (!defined('INCLUDES_DIR')) {
    define('INCLUDES_DIR', ABSPATH . 'includes');
}

// Define TODO_ROOT - 项目根目录(无尾部斜杠)
if (!defined('TODO_ROOT')) {
    define('TODO_ROOT', rtrim(ABSPATH, '/'));
}

// ==================== 内容目录常量 ====================

// Define CONTENT_DIR - 内容目录(相对路径)
if (!defined('CONTENT_DIR')) {
    define('CONTENT_DIR', 'content');
}

// Define TODO_CONTENT_DIR - 内容目录(绝对路径)
if (!defined('TODO_CONTENT_DIR')) {
    define('TODO_CONTENT_DIR', ABSPATH . 'content');
}

// Define UPLOAD_DIR - 上传目录(相对路径)
if (!defined('UPLOAD_DIR')) {
    define('UPLOAD_DIR', CONTENT_DIR . '/uploads');
}

// Define PLUGIN_DIR - 插件目录(相对路径)
if (!defined('PLUGIN_DIR')) {
    define('PLUGIN_DIR', CONTENT_DIR . '/plugins');
}

// Define TODO_PLUGIN_DIR - 插件目录(绝对路径)
if (!defined('TODO_PLUGIN_DIR')) {
    define('TODO_PLUGIN_DIR', TODO_CONTENT_DIR . '/plugins');
}

// Define THEMES_DIR - 主题目录(相对路径)
if (!defined('THEMES_DIR')) {
    define('THEMES_DIR', CONTENT_DIR . '/themes');
}

// Define THEME_STYLE_PC - PC端主题风格标识
if (!defined('THEME_STYLE_PC')) {
    define('THEME_STYLE_PC', 'pc');
}

// Define THEME_STYLE_MOBILE - 移动端主题风格标识
if (!defined('THEME_STYLE_MOBILE')) {
    define('THEME_STYLE_MOBILE', 'mobile');
}

// Define TODO_LOG_DIR - 日志目录
if (!defined('TODO_LOG_DIR')) {
    define('TODO_LOG_DIR', ABSPATH . 'logs');
}

// ==================== 系统信息常量 ====================

// Define TODO_VERSION - 系统版本
if (!defined('TODO_VERSION')) {
    define('TODO_VERSION', '1.1.25');
}

if (!defined('TODO_DB_VERSION')) {
    define('TODO_DB_VERSION', TODO_VERSION);
}

// Define TODO_LOADED - 系统加载标识
if (!defined('TODO_LOADED')) {
    define('TODO_LOADED', true);
}

// ==================== 数据库常量 ====================

// 定义数据库前缀常量（兼容性）
if (!defined('DB_PREFIX')) {
    if (defined('TABLE_PREFIX')) {
        define('DB_PREFIX', TABLE_PREFIX);
    }
}
