<?php
/**
 * ORM性能优化初始化
 * 
 * 确保ORM类被正确加载并初始化批量加载机制
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit('Direct access denied.');
}

// 确保ORM类被加载（通过Composer autoload）
if (!class_exists('Todo_Post_ORM')) {
    // 如果Composer autoload未加载ORM，手动加载
    $orm_files = [
        'core/classes/class-orm-base.php',
        'core/classes/class-query-builder.php',
        'core/classes/class-post-orm.php',
        'core/classes/class-batch-preloader.php', // 批量预加载器
    ];
    
    foreach ($orm_files as $file) {
        $file_path = ABSPATH . $file;
        if (file_exists($file_path)) {
            require_once $file_path;
        }
    }
}

// 注册批量预加载钩子（在查询文章后自动批量加载）
if (class_exists('Todo_Post_ORM') && function_exists('todo_add_action')) {
    // 在get_posts后自动批量加载
    todo_add_action('todo_posts_loaded', function($posts) {
        if (empty($posts) || !is_array($posts)) {
            return;
        }
        
        $post_ids = [];
        foreach ($posts as $p) {
            if (is_object($p) && isset($p->ID)) {
                $post_ids[] = (int)$p->ID;
            } elseif (is_array($p) && isset($p['ID'])) {
                $post_ids[] = (int)$p['ID'];
            }
        }
        
        if (!empty($post_ids)) {
            Todo_Post_ORM::eager_load_all($post_ids);
        }
    });
}

