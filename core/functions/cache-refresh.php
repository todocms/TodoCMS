<?php
/**
 * TodoCMS 缓存刷新处理器
 * 
 * 处理 ?todocache=refresh 参数，清理所有相关缓存
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 处理缓存刷新请求
 * 
 * 当检测到 ?todocache=refresh 参数时，清理所有缓存
 * 仅管理员可以执行此操作
 */
function todo_handle_cache_refresh()
{
    // 检查是否有刷新缓存的请求
    if (!isset($_GET['todocache']) || $_GET['todocache'] !== 'refresh') {
        return;
    }

    // 权限检查：仅管理员可以刷新缓存
    $has_permission = false;

    if (function_exists('todo_current_user_can')) {
        $has_permission = todo_current_user_can('manage_options');
    } elseif (function_exists('current_user_can')) {
        $has_permission = current_user_can('manage_options');
    } elseif (function_exists('is_admin') && is_admin()) {
        $has_permission = true;
    }

    // 开发环境特殊处理：如果是 localhost 且开启了 DEBUG，允许刷新
    if (!$has_permission && defined('TODO_DEBUG') && TODO_DEBUG) {
        $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($remote_addr === '127.0.0.1' || $remote_addr === '::1' || strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false) {
            $has_permission = true;
            error_log('[Cache Refresh] Allowed refresh on localhost via TODO_DEBUG');
        }
    }

    if (!$has_permission) {
        error_log('[Cache Refresh] Permission denied for cache refresh request');
        return;
    }

    error_log('[Cache Refresh] Executing full cache clear...');
    // 执行缓存刷新
    todo_clear_all_caches();

    // 设置全局标志，让其他组件知道缓存已刷新
    if (!defined('TODO_CACHE_REFRESHED')) {
        define('TODO_CACHE_REFRESHED', true);
    }

    // 在动态模式下，添加一个提示消息
    if (function_exists('todo_add_notice')) {
        todo_add_notice('缓存已刷新', 'success');
    }
}

/**
 * 清理所有缓存
 * 
 * 包括：
 * 1. Todo_Cache_Manager 持久化缓存
 * 2. 编译模板缓存
 * 3. 模板结果缓存
 * 4. 对象缓存
 */
function todo_clear_all_caches()
{
    $cleared = [];

    // 1. 清理 Todo_Cache_Manager 的所有缓存
    if (class_exists('Todo_Cache_Manager')) {
        try {
            // 首先尝试清理所有缓存
            Todo_Cache_Manager::flush_all();
            $cleared[] = "Todo_Cache_Manager:all";
        } catch (Exception $e) {
            error_log("Failed to flush all caches: " . $e->getMessage());
        }

        $cache_groups = ['theme', 'menus', 'fragments', 'post_meta', 'post_fields', 'models', 'terms', 'users', 'posts', 'categories', 'queries', 'template_results'];
        foreach ($cache_groups as $group) {
            try {
                // 使用 flush_group 方法（双重保险）
                Todo_Cache_Manager::flush_group($group);
                $cleared[] = "Todo_Cache_Manager:{$group}";
            } catch (Exception $e) {
                error_log("Failed to clear cache group {$group}: " . $e->getMessage());
            }
        }
    }

    // 2. 清理编译模板缓存
    if (class_exists('CMS_Template_Compiler_V2')) {
        try {
            CMS_Template_Compiler_V2::clear_all_cache();
            $cleared[] = 'CMS_Template_Compiler_V2';
        } catch (Exception $e) {
            error_log("Failed to clear compiled template cache: " . $e->getMessage());
        }
    }

    // 3. 清理模板结果缓存（如果存在）
    if (class_exists('Todo_Template_Result_Cache') && method_exists('Todo_Template_Result_Cache', 'clear_all')) {
        try {
            Todo_Template_Result_Cache::clear_all();
            $cleared[] = 'Todo_Template_Result_Cache';
        } catch (Exception $e) {
            error_log("Failed to clear template result cache: " . $e->getMessage());
        }
    }

    // 4. 清理数据库结果池（当前请求的内存缓存）
    if (class_exists('Todo_DB_Result_Pool') && method_exists('Todo_DB_Result_Pool', 'clear')) {
        try {
            Todo_DB_Result_Pool::clear();
            $cleared[] = 'Todo_DB_Result_Pool';
        } catch (Exception $e) {
            error_log("Failed to clear DB result pool: " . $e->getMessage());
        }
    }

    // 5. 清理文件缓存目录
    $cache_dirs = [
        ABSPATH . 'content/cache/templates',
        ABSPATH . 'content/cache/compiled',
        ABSPATH . 'content/cache/fragments',
        ABSPATH . 'content/cache/objects',
        ABSPATH . 'content/cache/updates',
    ];

    foreach ($cache_dirs as $dir) {
        if (is_dir($dir)) {
            try {
                todo_clear_directory_cache($dir);
                $cleared[] = basename($dir);
            } catch (Exception $e) {
                error_log("Failed to clear directory {$dir}: " . $e->getMessage());
            }
        }
    }

    // 6. 触发缓存清理钩子，让其他组件也能清理自己的缓存
    if (function_exists('do_action')) {
        do_action('todo_cache_cleared');
    }

    // 记录日志
    if (defined('TODO_DEBUG') && TODO_DEBUG) {
        error_log('[Cache Refresh] Cleared caches: ' . implode(', ', $cleared));
    }

    return $cleared;
}

/**
 * 清理目录中的缓存文件
 * 
 * @param string $dir 目录路径
 * @param bool $recursive 是否递归清理子目录
 * @param bool $keep_protected 是否保留根目录下的保护文件 (index.php, .htaccess等)
 */
function todo_clear_directory_cache($dir, $recursive = true, $keep_protected = true)
{
    if (!is_dir($dir)) {
        return;
    }

    $files = scandir($dir);
    if (!$files) {
        return;
    }

    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $full_path = $dir . '/' . $file;

        if (is_file($full_path)) {
            // 仅在根目录且指定保留时，跳过保护文件
            if ($keep_protected && in_array($file, ['.gitkeep', 'index.php', '.htaccess'])) {
                continue;
            }

            // 删除缓存文件（包括隐藏文件如 .DS_Store）
            if (@unlink($full_path)) {
                if (defined('TODO_DEBUG') && TODO_DEBUG) {
                    error_log("[Cache Refresh] Deleted file: " . $full_path);
                }
            }
        } elseif ($recursive && is_dir($full_path)) {
            // 递归清理子目录时，不保留其中的保护文件，确保目录可以被 rmdir
            todo_clear_directory_cache($full_path, true, false);

            // 清理完子目录文件后，尝试删除空目录
            if (@rmdir($full_path)) {
                if (defined('TODO_DEBUG') && TODO_DEBUG) {
                    error_log("[Cache Refresh] Deleted directory: " . $full_path);
                }
            }
        }
    }
}

/**
 * 获取缓存 TTL
 * 
 * 根据页面模式和配置返回合适的 TTL
 * 
 * @param string $cache_type 缓存类型 (theme|template|fragment|query)
 * @return int TTL（秒），0 表示不使用持久化缓存
 */
function todo_get_cache_ttl($cache_type = 'default')
{
    static $recursion_guard = false;
    static $options_cache = null;

    if ($recursion_guard) {
        return 3600; // 递归时返回默认值
    }

    // 强制刷新检查：管理员通过 ?todocache=refresh 访问时，TTL 强制为 0
    if (isset($_GET['todocache']) && $_GET['todocache'] === 'refresh') {
        if (function_exists('todo_current_user_can') && todo_current_user_can('manage_options')) {
            return 0;
        }
        if (function_exists('current_user_can') && current_user_can('manage_options')) {
            return 0;
        }
    }

    if ($options_cache === null) {
        $recursion_guard = true;

        $page_mode = 'dynamic';
        $dynamic_ttl = 0;

        if (class_exists('CMS_Option')) {
            // 直接从 CMS_Option 获取，避免触发过滤器导致的递归
            $page_mode = CMS_Option::get('page_mode', 'dynamic');
            $dynamic_ttl = (int) CMS_Option::get('dynamic_template_cache_ttl', 0);
        } elseif (function_exists('get_option')) {
            $page_mode = get_option('page_mode', 'dynamic');
            $dynamic_ttl = (int) get_option('dynamic_template_cache_ttl', 0);
        }

        $options_cache = [
            'page_mode' => $page_mode,
            'dynamic_template_cache_ttl' => $dynamic_ttl
        ];

        $recursion_guard = false;
    }

    $page_mode = $options_cache['page_mode'];

    // 动态模式：优先检查 TTL 配置
    if ($page_mode === 'dynamic') {
        $dynamic_ttl = $options_cache['dynamic_template_cache_ttl'];

        // TTL=0：完全不使用持久化缓存
        if ($dynamic_ttl === 0) {
            return 0;
        }

        // 使用配置的 TTL（限制在 30-3600 秒之间）
        $dynamic_ttl = max(30, min(3600, $dynamic_ttl));

        switch ($cache_type) {
            case 'theme':
                return $dynamic_ttl;
            case 'template':
                return 0; // 编译模板永久缓存（直到文件修改）
            case 'fragment':
                return $dynamic_ttl;
            case 'query':
                return min($dynamic_ttl, 300); // 查询缓存最多 5 分钟
            default:
                return $dynamic_ttl;
        }
    }

    // 静态模式：使用长期缓存
    switch ($cache_type) {
        case 'theme':
            return 86400; // 24 小时
        case 'template':
            return 0; // 编译模板永久缓存（直到文件修改）
        case 'fragment':
            return 31536000; // 1 年（静态模式）
        case 'query':
            return 3600; // 1 小时
        default:
            return 3600;
    }
}

// 在请求早期注册缓存刷新处理器
// 使用 pre_request 钩子，因为它在 cms_handle_request 中被调用，覆盖了主站请求
add_action('pre_request', 'todo_handle_cache_refresh', 1);

// 兼容性：同时注册到 cms_init
add_action('cms_init', 'todo_handle_cache_refresh', 1);

// 如果是 AJAX 请求，可能需要 init 钩子（如果系统支持）
add_action('init', 'todo_handle_cache_refresh', 1);

// ADMIN 支持：确保在后台页面也能通过 URL 参数刷新缓存
add_action('admin_init', 'todo_handle_cache_refresh', 1);

// 核心加载完成：兜底钩子
add_action('todo_bootstrap_loaded', 'todo_handle_cache_refresh', 1);
