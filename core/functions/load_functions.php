<?php
/**
 * 加载函数文件
 *
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH') && !defined('DOING_AJAX') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 统一补定义 DB_PREFIX（兼容未经过 bootstrap 的入口，例如 UEditor 控制器）
if (!defined('DB_PREFIX')) {
    if (defined('TABLE_PREFIX')) {
        define('DB_PREFIX', TABLE_PREFIX);
    } else {
        define('DB_PREFIX', 'todo_');
    }
}

// 初始化钩子系统
if (class_exists('CMS_Hooks')) {
    CMS_Hooks::init();
}
if (class_exists('Todo_Scheduler')) {
    Todo_Scheduler::init();
}

// 加载插入术语函数
require_once ABSPATH . 'core/functions/insert_term.php';

// 加载更新术语函数
require_once ABSPATH . 'core/functions/update_term.php';

// 加载删除术语函数
require_once ABSPATH . 'core/functions/delete_term.php';

// 加载分页函数
require_once ABSPATH . 'core/functions/pagination.php';

// 加载栏目模型关联函数
require_once ABSPATH . 'core/functions/term-model-functions.php';

// 更新与授权服务（在 core.php 之前加载，供更新检查使用）
require_once ABSPATH . 'core/functions/update-service.php';
require_once ABSPATH . 'core/functions/template.php';
require_once ABSPATH . 'core/functions/html-template.php';
require_once ABSPATH . 'core/functions/global-vars.php';
// content.php 中的函数已在 post.php 等文件中定义，无需重复加载
// require_once ABSPATH . 'core/functions/content.php';
require_once ABSPATH . 'core/functions/cache-functions.php';
require_once ABSPATH . 'core/functions/transient-functions.php';
require_once ABSPATH . 'core/functions/menu.php';
require_once ABSPATH . 'core/functions/link-functions.php';
require_once ABSPATH . 'core/functions/mail-extend.php';
require_once ABSPATH . 'core/functions/seo-functions.php';
