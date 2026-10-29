<?php
/**
 * 标对导入插件钩子注册文件
 * 
 * 集中管理插件的所有动作、过滤器和AJAX钩子
 * 
 * @package DataMappingImport
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('TODO_VERSION')) {
    exit('Direct access denied');
}

/**
 * 注册插件钩子
 * 
 * 这是插件的统一钩子注册入口，由插件系统自动调用
 * 
 * @return void
 */
function data_mapping_import_register_hooks() {
    // 加载AJAX处理器
    if (file_exists(dirname(__FILE__) . '/includes/ajax-handlers.php')) {
        require_once dirname(__FILE__) . '/includes/ajax-handlers.php';
        // 实例化AJAX处理器（构造函数中会自动注册所有AJAX钩子）
        new DataMappingAjaxHandlers();
    }
    
    // 注册其他动作和过滤器钩子
    // 例如：
    // add_action('some_action', 'some_callback');
    // add_filter('some_filter', 'some_filter_callback');
}