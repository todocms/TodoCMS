<?php
/**
 * Plugin Name: 标对导入
 * Plugin URI: http://todocms.com
 * Description: 支持SQL文件导入和数据库字段映射的数据迁移工具；由 TodoCMS 官方维护，更新会被覆盖。
 * Version: 1.0.0
 * Author: TodoCMS Team
 * License: Proprietary
 */

// 防止直接访问
if (!defined('TODO_VERSION')) {
    exit('Direct access denied');
}

// 返回插件配置数组
return array(
    'name' => '标对导入',
    'slug' => 'data-mapping-import',
    'version' => '1.0.0',
    'description' => '支持SQL文件导入和数据库字段映射的数据迁移工具；由 TodoCMS 官方维护，更新会被覆盖。',
    'author' => 'TodoCMS Team',
    'author_uri' => 'http://todocms.com',
    'plugin_uri' => 'http://todocms.com',
    'text_domain' => 'data-mapping-import',
    'domain_path' => '/languages',
    'requires_php' => '7.4',
    'requires_todo' => '1.0.0',
    'network' => false,
    'license' => 'proprietary',
    
    // 插件主类
    'main_class' => 'DataMappingImportPlugin',
    'main_file' => 'data-mapping-import.php',
    'main' => 'data-mapping-import.php',
    
    // 激活/停用钩子
    'activation_hook' => array('DataMappingImportPlugin', 'activate'),
    'deactivation_hook' => array('DataMappingImportPlugin', 'deactivate'),
    'uninstall_hook' => array('DataMappingImportPlugin', 'uninstall'),
    
    // 依赖文件
    'dependencies' => array(
        // 如果有依赖的其他插件，在这里列出插件目录名
        // 例如: 'some-required-plugin'
    ),
    
    // 管理菜单
    'admin_menu' => array(
        'page_title' => '标对导入',
        'menu_title' => '标对导入',
        'capability' => 'manage_options',
        'menu_slug' => 'data-mapping-import',
        'callback' => array('DataMappingImportPlugin', 'admin_page'),
        'position' => 30
    ),
    
    // 脚本和样式
    'scripts' => array(
        'data-mapping-import-js' => array(
            'src' => 'assets/js/data-mapping-import.js',
            'deps' => array('jquery'),
            'version' => '1.0.0',
            'in_footer' => true
        )
    ),
    
    'styles' => array(
        'data-mapping-import-css' => array(
            'src' => 'assets/css/data-mapping-import.css',
            'deps' => array(),
            'version' => '1.0.0',
            'media' => 'all'
        )
    ),
    
    // 钩子注册配置（使用类方法）
    'hooks' => array(
        'register' => array('DataMappingImportPlugin', 'register')
    )
);
