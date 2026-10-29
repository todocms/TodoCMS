<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}

/**
 * Plugin Name: 标对导入
 * Plugin URI: http://todocms.com
 * Description: 支持SQL文件导入和数据库字段映射的数据迁移工具；由 TodoCMS 官方维护，更新会被覆盖。
 * Version: 1.0.0
 * Author: TodoCMS Team
 * Author URI: http://todocms.com
 * License: Proprietary
 * Text Domain: data-mapping-import
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Network: false
 */

// 防止直接访问
if (!defined('TODO_VERSION')) {
    exit('Direct access denied');
}

// 定义插件常量
if (!defined('DMI_VERSION')) {
    define('DMI_VERSION', '1.0.0');
}
if (!defined('DMI_PLUGIN_DIR')) {
    define('DMI_PLUGIN_DIR', __DIR__ . '/');
}
if (!defined('DMI_PLUGIN_URL')) {
    if (function_exists('todo_get_plugin_url')) {
        define('DMI_PLUGIN_URL', todo_get_plugin_url('data-mapping-import'));
    } else {
        define('DMI_PLUGIN_URL', '');
    }
}

// 注册激活/停用钩子
require_once dirname(__FILE__) . '/includes/class-data-mapping-import-plugin.php';
register_activation_hook(__FILE__, array('DataMappingImportPlugin', 'activate'));
register_deactivation_hook(__FILE__, array('DataMappingImportPlugin', 'deactivate'));

// 初始化插件
DataMappingImportPlugin::get_instance();
