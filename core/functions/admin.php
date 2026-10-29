<?php
/**
 * 管理后台函数
 *
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 获取当前用户ID
 *
 * @return int
 */
if (!function_exists('get_current_user_id')) {
    function get_current_user_id()
    {
        if (function_exists('todo_get_current_user_id')) {
            return todo_get_current_user_id();
        }
        return 0;
    }
}

/**
 * 检查用户是否已登录
 *
 * @return bool
 */
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in()
    {
        // 在后台环境中，检查后台登录状态
        if (defined('IS_ADMIN') && IS_ADMIN) {
            // 确保类已加载
            if (!class_exists('Todo_Session_Manager')) {
                // 如果类不存在，可能是常量未定义，尝试定义后再检查
                if (!defined('TODO_LOADED')) {
                    define('TODO_LOADED', true);
                }
                // 再次检查类是否存在（Composer 会自动加载）
                if (!class_exists('Todo_Session_Manager')) {
                    throw new Exception('Todo_Session_Manager 类无法加载，请检查 Composer 自动加载配置');
                }
            }
            Todo_Session_Manager::init();
            return Todo_Session_Manager::is_admin_logged_in();
        }

        return get_current_user_id() > 0;
    }
}

/**
 * 用户认证
 *
 * @param string $username 用户名
 * @param string $password 密码
 * @return int|false 用户ID或false
 */
if (!function_exists('authenticate_user')) {
    function authenticate_user($username, $password)
    {
        // 统一使用核心认证函数
        $user = todo_authenticate($username, $password);
        if (is_object($user) && !is_a($user, 'TODO_Error')) {
            return $user->get_id();
        }
        return false;
    }
}

/**
 * 设置认证Cookie
 */
if (!function_exists('set_auth_cookie')) {
    function set_auth_cookie($user_id, $remember = false)
    {
        if ($remember) {
            $expire = time() + (30 * 24 * 60 * 60); // 30天
            setcookie('todo_user_id', $user_id, $expire, '/');
        }
    }
}

/**
 * 获取管理后台URL
 */
if (!function_exists('todo_admin_url')) {
    function todo_admin_url($path = '', $clear_cache = false)
    {
        $base = todo_get_site_url();
        $url = rtrim($base, '/') . '/admin/' . ltrim((string) $path, '/');

        if ((string) $path === '') {
            return $url;
        }

        if (!empty($clear_cache) && function_exists('add_query_arg')) {
            $url = add_query_arg('todocache', 'refresh', $url);
        }

        if (function_exists('todo_is_iframe_mode') && todo_is_iframe_mode()) {
            $path_str = (string) $path;
            $ext = strtolower(pathinfo($path_str, PATHINFO_EXTENSION));
            $static_exts = array('js', 'css', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'woff', 'woff2', 'ttf', 'eot');
            $path_norm = ltrim($path_str, '/');
            $is_dir = ($path_str !== '' && substr($path_str, -1) === '/');
            $is_ajax_dir = ($path_norm === 'ajax' || strpos($path_norm, 'ajax/') === 0);
            $is_ajax_file = ($path_norm === 'admin-ajax.php');
            if (!in_array($ext, $static_exts) && !$is_dir && !$is_ajax_dir && !$is_ajax_file && function_exists('add_query_arg')) {
                $url = add_query_arg('format', 'iframe', $url);
            }
        }

        // Phase 3 & 4: Append site parameter if present
        // 后台环境：仅当 $_GET['site'] 显式存在时追加，避免 Cookie/fallback 值
        // 污染全局数据视图（无 site 参数 = 全局视图，不应追加任何 site）
        // 前台环境：保持原有行为，通过 todo_current_site_id() 解析
        if (function_exists('add_query_arg')) {
            if (defined('IS_ADMIN') && IS_ADMIN) {
                // 后台：只认显式的 GET 参数
                if (!empty($_GET['site']) && is_numeric($_GET['site']) && (int)$_GET['site'] > 0) {
                    $url = add_query_arg('site', (int)$_GET['site'], $url);
                }
            } elseif (function_exists('todo_current_site_id')) {
                // 前台：通过 todo_current_site_id() 解析（含 Cookie/Host 回落）
                $current_site_id = todo_current_site_id();
                if ($current_site_id > 0) {
                    $url = add_query_arg('site', $current_site_id, $url);
                }
            }
        }

        return $url;
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '')
    {
        // 调用核心 URL 函数
        return todo_admin_url($path);
    }
}

/**
 * 获取站点URL
 */
if (!function_exists('site_url')) {
    function site_url($path = '')
    {
        return todo_get_site_url($path);
    }
}

/**
 * 获取内容URL
 */
if (!function_exists('content_url')) {
    function content_url($path = '')
    {
        return todo_get_site_url('content/' . ltrim($path, '/'));
    }
}

/**
 * 获取插件页面钩子名
 *
 * @param string $plugin_page 插件页面
 * @param string $parent_page 父页面
 * @return string
 */
if (!function_exists('get_plugin_page_hookname')) {
    function get_plugin_page_hookname($plugin_page, $parent_page)
    {
        global $admin_page_hooks;

        $parent_page = empty($parent_page) ? 'admin' : $parent_page;
        $page_type = 'admin';

        if (!empty($parent_page)) {
            $parent_slug = preg_replace('|\.php$|', '', $parent_page);
            if (isset($admin_page_hooks[$parent_slug])) {
                $page_type = $admin_page_hooks[$parent_slug];
            } else {
                $page_type = $parent_slug;
            }
        }

        $plugin_name = preg_replace('|\.php$|', '', $plugin_page);

        // 如果插件名为空，使用默认名称
        if (empty($plugin_name)) {
            $plugin_name = 'index';
        }

        if ($page_type == 'admin') {
            $page_type = '';
        } else {
            $page_type .= '_page_';
        }

        return $page_type . $plugin_name;
    }
}

/**
 * 添加管理后台菜单
 *
 * @param string $page_title 页面标题
 * @param string $menu_title 菜单标题
 * @param string $capability 权限
 * @param string $menu_slug 菜单标识
 * @param callable $function 回调函数
 * @param string $icon_url 图标URL
 * @param int $position 位置
 */
if (!function_exists('add_menu_page')) {
    function add_menu_page($page_title, $menu_title, $capability, $menu_slug, $function = '', $icon_url = '', $position = null)
    {
        global $admin_page_hooks, $menu;

        if (!isset($admin_page_hooks)) {
            $admin_page_hooks = array();
        }

        if (!isset($menu)) {
            $menu = array();
        }

        // 生成页面钩子名
        $menu_slug = $menu_slug ? preg_replace('|^.*/|', '', $menu_slug) : '';
        $hookname = get_plugin_page_hookname($menu_slug, '');

        // 注册页面钩子（确保钩子名不为空）
        if (!empty($hookname)) {
            $admin_page_hooks[$menu_slug] = $hookname;
        }

        // 注册回调函数
        if (!empty($function) && is_callable($function)) {
            add_action($hookname, $function);
        }

        // 添加到主菜单数组
        $position = $position !== null ? $position : 25;
        $menu[$position] = array($menu_title, $capability, $menu_slug, $page_title, 'menu-top', $hookname, $icon_url);

        return $hookname;
    }
}

/**
 * 添加子菜单
 *
 * @param string $parent_slug 父菜单标识
 * @param string $page_title 页面标题
 * @param string $menu_title 菜单标题
 * @param string $capability 权限
 * @param string $menu_slug 菜单标识
 * @param callable $function 回调函数
 */
if (!function_exists('add_submenu_page')) {
    function add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, $function = '')
    {
        global $admin_page_hooks, $submenu, $menu, $pagenow;

        if (!isset($admin_page_hooks)) {
            $admin_page_hooks = array();
        }

        if (!isset($submenu)) {
            $submenu = array();
        }

        // 注册页面钩子
        $parent_slug = $parent_slug ? preg_replace('|^.*/|', '', $parent_slug) : '';

        if (isset($admin_page_hooks[$parent_slug])) {
            $page_hook = $admin_page_hooks[$parent_slug];
        } else {
            $page_hook = $parent_slug;
            $admin_page_hooks[$parent_slug] = $parent_slug;
        }

        $plugin_name = preg_replace('|\.php$|', '', $menu_slug);
        $hookname = $page_hook . '_page_' . $plugin_name;

        // 注册回调函数
        if (!empty($function) && is_callable($function)) {
            add_action($hookname, $function);
        }

        // 添加到子菜单数组
        if (!isset($submenu[$parent_slug])) {
            $submenu[$parent_slug] = array();
        }

        $submenu[$parent_slug][] = array($menu_title, $capability, $menu_slug, $page_title);

        return $hookname;
    }
}

/**
 * 添加选项页面到设置菜单
 * 
 * @param string $page_title 页面标题
 * @param string $menu_title 菜单标题
 * @param string $capability 权限
 * @param string $menu_slug 菜单标识
 * @param callable $function 回调函数
 * @return string 页面钩子名
 */
if (!function_exists('add_options_page')) {
    function add_options_page($page_title, $menu_title, $capability, $menu_slug, $function = '')
    {
        return add_submenu_page('settings-general.php', $page_title, $menu_title, $capability, $menu_slug, $function);
    }
}

/**
 * 兼容逻辑：处理 page=sites 的路由
 */
add_action('admin_init', function() {

    $page = isset($_GET['page']) ? $_GET['page'] : '';
    if ($page === 'sites') {
        // 如果 header.php 加载页面时没有找到对应的实现，此钩子可作为回退
        // 实际上 header.php 会根据 $current_page 包含 admin/pages/{$page}.php
    }
});


