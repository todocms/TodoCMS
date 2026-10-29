<?php
/**
 * 管理后台头部模板
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access denied');
}

if (!function_exists('todo_admin_url')) {
    require_once ABSPATH . 'core/functions/utility-functions.php';
}

// 确保获取站点URL的函数可用
if (!function_exists('todo_home_url')) {
    require_once ABSPATH . 'core/functions/todo-core-functions.php';
}
if (!function_exists('site_url')) {
    require_once ABSPATH . 'core/functions/core.php';
}

// 强制设置为 HTML 响应，避免被错误地当作纯文本输出
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
}

// 获取当前用户（todocms风格函数）
$current_user = todo_get_current_user();

// 如果$current_user不是对象，尝试手动初始化
if (!is_object($current_user) && is_user_logged_in()) {
    $user_id = get_current_user_id();
    if ($user_id > 0) {
        $current_user = new CMS_User();
        $current_user->init($user_id);
    }
}

// 获取站点标题
$site_title = get_option('site_title', 'TodoCMS');
// 处理行内多语言
if (!function_exists('todo_parse_inline_translations') && file_exists(ABSPATH . 'core/functions/multilang-fields.php')) {
    require_once ABSPATH . 'core/functions/multilang-fields.php';
}
if (function_exists('todo_parse_inline_translations')) {
    $site_title = todo_parse_inline_translations($site_title);
}

// 获取当前页面
$current_page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

// 获取管理菜单
$admin_menu_default = array(
    'posts' => array(
        'title' => '文章',
        'icon' => 'article',
        'url' => 'index.php?page=posts',
        'capability' => 'edit_posts',
        'submenu' => array(
            'posts' => array(
                'title' => '所有文章',
                'url' => 'index.php?page=posts',
                'capability' => 'edit_posts'
            ),
            'post-new' => array(
                'title' => '写文章',
                'url' => 'index.php?page=post-new',
                'capability' => 'publish_posts'
            ),
            'categories' => array(
                'title' => '栏目管理',
                'url' => 'index.php?page=categories',
                'capability' => 'manage_categories'
            ),
            'tags' => array(
                'title' => 'Tags管理',
                'url' => 'index.php?page=tags',
                'capability' => 'manage_categories'
            ),
            'doc-properties' => array(
                'title' => '文档属性',
                'url' => 'index.php?page=doc-properties',
                'capability' => 'manage_categories'
            )
        )
    ),
    'ads' => array(
        'title' => '广告',
        'icon' => 'ad',
        'url' => 'index.php?page=ads',
        'capability' => 'manage_ads',
        'submenu' => array(
            'ads' => array(
                'title' => '广告管理',
                'url' => 'index.php?page=ads',
                'capability' => 'manage_ads'
            ),
            'ad-groups' => array(
                'title' => '广告分组',
                'url' => 'index.php?page=ad-groups',
                'capability' => 'manage_ads'
            ),

            'links' => array(
                'title' => '友情链接',
                'url' => 'index.php?page=links',
                'capability' => 'manage_ads'
            )
        )
    ),
    'media' => array(
        'title' => '媒体',
        'icon' => 'media',
        'url' => 'index.php?page=media',
        'capability' => 'upload_files'
    ),
    'comments' => array(
        'title' => '评论',
        'icon' => 'comments',
        'url' => 'index.php?page=comment-dashboard',
        'capability' => 'moderate_comments',
        'submenu' => array(
            'comment-dashboard' => array(
                'title' => '评论概览',
                'url' => 'index.php?page=comment-dashboard',
                'capability' => 'moderate_comments'
            ),
            'comments' => array(
                'title' => '文章评论',
                'url' => 'index.php?page=comments',
                'capability' => 'moderate_comments'
            ),
            'product-inquiries' => array(
                'title' => '产品询盘',
                'url' => 'index.php?page=product-inquiries',
                'capability' => 'moderate_comments'
            ),
            'website-messages' => array(
                'title' => '网站留言',
                'url' => 'index.php?page=website-messages',
                'capability' => 'moderate_comments'
            ),
            'email-subscriptions' => array(
                'title' => '邮件订阅',
                'url' => 'index.php?page=email-subscriptions',
                'capability' => 'moderate_comments'
            )
        )
    ),
    'users' => array(
        'title' => '用户',
        'icon' => 'users',
        'url' => 'index.php?page=users',
        'capability' => 'list_users',
        'submenu' => array(
            'users' => array(
                'title' => '所有用户',
                'url' => 'index.php?page=users',
                'capability' => 'list_users'
            ),
            'user-new' => array(
                'title' => '添加用户',
                'url' => 'index.php?page=user-new',
                'capability' => 'create_users'
            ),
            'user-levels' => array(
                'title' => '用户等级',
                'url' => 'index.php?page=user-levels',
                'capability' => 'manage_user_levels'
            ),
            'upgrade-packages' => array(
                'title' => '升级套餐',
                'url' => 'index.php?page=upgrade-packages',
                'capability' => 'manage_upgrade_packages'
            ),
            'points-system' => array(
                'title' => '积分系统',
                'url' => 'index.php?page=points-system',
                'capability' => 'manage_points'
            )
        )
    ),
    'models' => array(
        'title' => '模型',
        'icon' => 'category',
        'url' => 'index.php?page=models',
        'capability' => 'manage_models'
    ),
    'tools' => array(
        'title' => '工具',
        'icon' => 'tools',
        'url' => 'index.php?page=menus',
        'capability' => '',
        'submenu' => array(
            'menus' => array(
                'title' => '菜单管理',
                'url' => 'index.php?page=menus',
                'capability' => 'manage_menus'
            ),
            'mail' => array(
                'title' => '邮件设置',
                'url' => 'index.php?page=mail',
                'capability' => 'manage_mail'
            ),
            'sites' => array(
                'title' => '站点',
                'url' => 'index.php?page=sites',
                'capability' => 'manage_sites'
            ),
            'language' => array(
                'title' => '语言',
                'url' => 'index.php?page=language',
                'capability' => 'manage_languages'
            ),
            'plugins' => array(
                'title' => '插件',
                'icon' => 'admin-plugins',
                'url' => 'index.php?page=plugins',
                'capability' => 'activate_plugins'
            ),
            'trash' => array(
                'title' => '回收站',
                'url' => 'index.php?page=trash',
                'capability' => 'manage_trash'
            )
        )
    ),
    'settings' => array(
        'title' => '设置',
        'icon' => 'settings',
        'url' => 'index.php?page=settings',
        'capability' => '',
        'submenu' => array(
            'site-info' => array(
                'title' => '网站信息',
                'url' => 'index.php?page=settings&tab=general',
                'capability' => 'manage_options'
            ),
            'seo-settings' => array(
                'title' => 'SEO设置',
                'url' => 'index.php?page=seo-settings',
                'capability' => 'manage_seo'
            ),
            'global-vars' => array(
                'title' => '全局变量',
                'url' => 'index.php?page=global-vars',
                'capability' => 'manage_global_vars'
            ),
            'updates' => array(
                'title' => '系统升级',
                'url' => 'index.php?page=updates',
                'capability' => 'update_core'
            ),
            'api-manager' => array(
                'title' => 'API管理',
                'url' => 'index.php?page=api-manager',
                'capability' => 'manage_api'
            )
        )
    )
);

if (function_exists('todo_is_demo_admin_session') && todo_is_demo_admin_session()) {
    unset($admin_menu_default['settings']['submenu']['api-manager']);
}

// 应用过滤器并确保返回值是数组
$admin_menu = apply_filters('admin_menu', $admin_menu_default);
if (!is_array($admin_menu)) {
    $admin_menu = $admin_menu_default;
}

// 先从菜单中查找标题
foreach ($admin_menu as $menu_slug => $menu_item) {
    if ($current_page === $menu_slug) {
        $admin_title = $menu_item['title'];
        break;
    }

    if (isset($menu_item['submenu'])) {
        foreach ($menu_item['submenu'] as $submenu_slug => $submenu_item) {
            if ($current_page === $submenu_slug) {
                $admin_title = $submenu_item['title'];
                break 2;
            }
        }
    }
}

// 如果在菜单中没有找到标题，检查是否有页面级的标题设置
if (empty($admin_title)) {
    $admin_title = isset($GLOBALS['admin_title']) ? $GLOBALS['admin_title'] : '';
}

// 页面标题：格式为 "{admin_title} - {site_title} 管理后台"
$page_title = $admin_title . ' - ' . $site_title . ' 管理后台';

// 输出HTML头部
?><!DOCTYPE html>
<html lang="<?php echo esc_attr(strtolower(function_exists('current_locale') ? current_locale() : 'zh-CN')); ?>">

<head>
    <meta charset="UTF-8">
    <meta name="generator" content="TodoCMS">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <meta name="site-url" content="<?php echo esc_attr(function_exists('todo_get_site_url') ? todo_get_site_url() : ''); ?>">
    <title><?php echo $page_title; ?></title>
    <?php
    $site_favicon = function_exists('todo_get_site_favicon_url') ? todo_get_site_favicon_url() : (function_exists('get_option') ? get_option('site_favicon') : (function_exists('todo_get_option') ? todo_get_option('site_favicon') : ''));
    if (!empty($site_favicon)) {
        $favicon_url = function_exists('todo_media_url_to_absolute') ? todo_media_url_to_absolute($site_favicon) : $site_favicon;
        echo '<link rel="shortcut icon" href="' . esc_url($favicon_url) . '" type="image/x-icon">' . "\n";
    }
    ?>
    <?php
    $asset_ver = function_exists('todo_get_cms_version') ? todo_get_cms_version() : (defined('TODO_VERSION') ? TODO_VERSION : '1.0.0');
    // 注册基础样式
    todo_enqueue_style('todo-iconfont', todo_get_site_url('public/static/common/css/iconfont.css'), array(), $asset_ver);
    todo_enqueue_style('todo-admin', todo_admin_url('assets/css/admin.css'), array(), $asset_ver);
    todo_enqueue_style('todo-pagination', todo_admin_url('assets/css/pagination.css'), array(), $asset_ver);
    todo_enqueue_style('todo-modal', todo_admin_url('assets/css/modal.css'), array(), $asset_ver);
    todo_enqueue_style('todo-custom', todo_admin_url('assets/css/custom.css'), array(), $asset_ver);
    ?>
    <?php if (isset($extra_css) && !empty($extra_css)): ?>
        <?php foreach ($extra_css as $css): ?>
            <link rel="stylesheet" href="<?php echo $css; ?>">
        <?php endforeach; ?>
    <?php endif; ?>

    <?php
    // 输出通过todo_enqueue_style注册的样式
    if (function_exists('todo_print_styles')) {
        todo_print_styles();
    }
    ?>

    <?php
    // 注册核心脚本
    todo_enqueue_script('jquery', todo_admin_url('assets/js/jquery-3.6.0.min.js'), array(), '3.6.0');
    todo_enqueue_script('csrf-manager', todo_admin_url('assets/js/csrf-manager.js'), array('jquery'), $asset_ver);
    todo_enqueue_script('todo-modal', todo_admin_url('assets/js/modal.js'), array('jquery'), $asset_ver);
    todo_enqueue_script('utils', todo_admin_url('assets/js/todo-common.js'), array('jquery', 'todo-modal'), $asset_ver);
    todo_enqueue_script('todo-request', todo_admin_url('assets/js/todo-request-handler.js'), array('jquery', 'utils'), $asset_ver);
    todo_enqueue_script('todo-ajax', todo_admin_url('assets/js/todo-ajax.js'), array('jquery'), $asset_ver);
    ?>

    <!-- 输出已注册的脚本 -->
    <?php todo_print_scripts(false); ?>

    <!-- TodoCMS AJAX配置 -->
    <script type="text/javascript">
        var todoAjax = {
            ajaxurl: '<?php echo todo_admin_url('admin-ajax.php'); ?>',
            nonce: '<?php echo todo_create_nonce("todo_ajax_nonce"); ?>'
        };
    </script>
    <!-- TODO全局路径（Admin专用） -->
    <script type="text/javascript">
        (function () {
            function join(base, path) {
                if (!base) base = '';
                if (!path) path = '';
                base = String(base);
                path = String(path);
                base = base.replace(/\/+$/, '');
                path = path.replace(/^\/+/, '');
                return base + '/' + path;
            }
            // 使用相对路径或基于当前页面的路径，避免依赖可能错误的配置
            var currentPath = window.location.pathname;
            var adminBase = '';
            if (currentPath.indexOf('/admin') !== -1) {
                // 如果在admin目录中，提取admin基础路径
                var match = currentPath.match(/^(.+\/admin)/);
                adminBase = match ? match[1] : '/admin';
            } else {
                // 如果不在admin目录中，使用默认路径
                adminBase = '/admin';
            }
            var ADMIN = adminBase.replace(/\/+$/, '');
            var SITE = (document.querySelector('meta[name="site-url"]')?.getAttribute('content')) || window.location.origin;
            window.TODO = window.TODO || {};
            window.TODO.join = window.TODO.join || join;
            window.TODO.SITE_URL = window.TODO.SITE_URL || SITE;
            window.TODO.ADMIN_BASE = window.TODO.ADMIN_BASE || ADMIN;
            window.TODO.ADMIN_AJAX = window.TODO.ADMIN_AJAX || join(ADMIN, 'admin-ajax.php');
            window.TODO.AJAX_DIR = window.TODO.AJAX_DIR || join(ADMIN, 'ajax');
            (function () {
                try {
                    var base = window.TODO && window.TODO.ADMIN_BASE ? window.TODO.ADMIN_BASE : '/admin';
                    var j = window.TODO && typeof window.TODO.join === 'function' ? window.TODO.join : function (b, p) { b = String(b).replace(/\/+$/, ''); p = String(p || '').replace(/^\/+/, ''); return b + '/' + p; };
                    window.UEDITOR_HOME_URL = j(base, 'assets/js/ueditor/');
                } catch (e) { }
            })();

            // 移动端适配：为 admin-table 添加滚动容器并调整边距
            (function () {
                var isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Opera Mobi|Silk|Kindle|Mobile/i.test(navigator.userAgent) || window.innerWidth < 768;
                if (!isMobile) return;

                function adaptTables() {
                    var tables = document.querySelectorAll('.admin-table');
                    tables.forEach(function (table) {
                        if (table.parentElement.classList.contains('mobile-table-wrapper')) return;
                        var wrapper = document.createElement('div');
                        wrapper.className = 'mobile-table-wrapper';
                        if (table.parentNode) {
                            table.parentNode.insertBefore(wrapper, table);
                            wrapper.appendChild(table);
                        }
                    });
                }
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', adaptTables);
                } else {
                    adaptTables();
                }
                window.addEventListener('load', adaptTables);
            })();
        })();
    </script>

    <?php if (defined('TODO_ENABLE_PERF_MONITOR') && TODO_ENABLE_PERF_MONITOR && defined('TODO_DEBUG') && TODO_DEBUG): ?>
        <!-- Server Timing Observer -->
        <script type="text/javascript">
            try {
                const po = new PerformanceObserver((list) => {
                    for (const entry of list.getEntries()) {
                        console.groupCollapsed('Server Timing: ' + entry.name);
                        if (entry.serverTiming) {
                            for (const timing of entry.serverTiming) {
                                console.log(`${timing.name}: ${timing.duration}ms (${timing.description || ''})`);
                            }
                        }
                        console.groupEnd();
                    }
                });
                po.observe({ type: 'navigation', buffered: true });
                po.observe({ type: 'resource', buffered: true });
            } catch (e) {
                console.warn('Server Timing API not supported or error initializing observer', e);
            }
        </script>
    <?php endif; ?>

    <?php
    // 需要jQuery UI的页面列表（包括特色图片组件使用的页面）
    $jquery_ui_pages = array('post-new', 'post-edit', 'menus', 'media-library', 'categories', 'tags', 'ad-new', 'ad-edit');
    if (in_array($current_page, $jquery_ui_pages)):
        ?>
        <!-- jQuery UI (用于特色图像组件的sortable功能和菜单管理) -->
        <script src="<?php echo todo_admin_url('assets/js/jquery-ui.min.js'); ?>?ver=<?php echo $asset_ver; ?>" type="text/javascript"></script>
        <script src="<?php echo todo_admin_url('assets/js/jquery.ui.touch-punch.min.js'); ?>?ver=<?php echo $asset_ver; ?>" type="text/javascript"></script>
        <link rel="stylesheet" href="<?php echo todo_admin_url('assets/css/jquery-ui.css'); ?>?ver=<?php echo $asset_ver; ?>">
    <?php endif; ?>

    <?php if (in_array($current_page, array('post-new', 'post-edit', 'mail', 'global-vars'))): ?>
        <!-- 编辑器相关文件 -->
        <script src="<?php echo todo_admin_url('assets/js/editor.js'); ?>?ver=<?php echo $asset_ver; ?>" type="text/javascript"></script>
        <link rel="stylesheet" href="<?php echo todo_admin_url('assets/css/editor.css'); ?>?ver=<?php echo $asset_ver; ?>">
    <?php endif; ?>

    <?php if ($current_page === 'menus'): ?>
        <!-- 菜单管理相关文件 -->
        <link rel="stylesheet" href="<?php echo todo_admin_url('assets/css/menus.css'); ?>?ver=<?php echo $asset_ver; ?>">
    <?php endif; ?>

    <?php
    // 触发admin enqueue_scripts钩子
    if (function_exists('todo_do_admin_action')) {
        todo_do_admin_action('enqueue_scripts');
    }
    ?>
</head>

<body<?php echo (function_exists('todo_is_mobile') && todo_is_mobile()) ? ' class="mbody"' : ''; ?>>
    <?php
    $is_iframe_mode = isset($_GET['format']) && $_GET['format'] === 'iframe';

    // 全局 Iframe 模式检测与修正
    // 如果页面在 Iframe 中加载但缺少 format=iframe 参数，自动重载以修复布局
    if (!$is_iframe_mode) {
        echo '<script type="text/javascript">
            if (window.self !== window.top) {
                var url = new URL(window.location.href);
                if (url.searchParams.get("format") !== "iframe") {
                    url.searchParams.set("format", "iframe");
                    // 隐藏 body 防止内容闪烁
                    document.write("<style>body{display:none!important}</style>");
                    window.location.replace(url.toString());
                }
            }
        </script>';
    }

    if ($is_iframe_mode) {
        echo '<style>html,body{background:#f9fafd;padding:0;margin:0;overflow-x:hidden;} .admin-main{padding: 20px;}</style>';
    }
    ?>
    <?php if (!$is_iframe_mode): ?>
        <div class="admin-container">
            <div class="admin-sidebar">
                <div class="sidebar-con">
                    <div class="admin-logo">
                        <?php 
                        $current_site_id = (isset($_GET['site']) && is_numeric($_GET['site'])) ? (int)$_GET['site'] : 0;
                        $site_suffix = $current_site_id > 0 ? '&site=' . $current_site_id : '';
                        ?>
                        <a href="index.php<?php echo $current_site_id > 0 ? '?site=' . $current_site_id : ''; ?>"><?php echo esc_html($site_title); ?></a>
                    </div>
                    <ul class="admin-menu">
                        <?php
                        $current_user_for_menu = function_exists('todo_get_current_user') ? todo_get_current_user() : null;
                        $user_role_for_menu = null;
                        $user_roles_for_menu = [];
                        if ($current_user_for_menu) {
                            if (is_array($current_user_for_menu)) {
                                $user_role_for_menu = $current_user_for_menu['role'] ?? null;
                                $user_roles_for_menu = $current_user_for_menu['roles'] ?? ($current_user_for_menu['meta']['roles'] ?? []);
                            } elseif (is_object($current_user_for_menu)) {
                                $user_role_for_menu = $current_user_for_menu->role ?? null;
                                $user_roles_for_menu = $current_user_for_menu->roles ?? (isset($current_user_for_menu->meta) && is_array($current_user_for_menu->meta) ? ($current_user_for_menu->meta['roles'] ?? []) : []);
                            }
                        }
                        if (is_string($user_roles_for_menu) && strpos($user_roles_for_menu, 'a:') === 0) {
                            $user_roles_for_menu = @unserialize($user_roles_for_menu) ?: [];
                        }
                        if (!is_array($user_roles_for_menu) && !empty($user_roles_for_menu)) {
                            $user_roles_for_menu = [$user_roles_for_menu];
                        }
                        if ($user_role_for_menu && !in_array($user_role_for_menu, $user_roles_for_menu, true)) {
                            $user_roles_for_menu[] = $user_role_for_menu;
                        }
                        $is_platform_admin_for_menu = in_array('admin', $user_roles_for_menu, true) || in_array('administrator', $user_roles_for_menu, true) || $user_role_for_menu === 'admin' || $user_role_for_menu === 'administrator';
                        $is_global_for_menu = (function_exists('todo_is_super_admin') && todo_is_super_admin()) || $is_platform_admin_for_menu;

                        foreach ($admin_menu as $menu_slug => $menu_item):
                            $submenu_items = (isset($menu_item['submenu']) && is_array($menu_item['submenu'])) ? $menu_item['submenu'] : array();
                            $visible_submenu_items = array();

                            if ($is_global_for_menu) {
                                $visible_submenu_items = $submenu_items;
                            } else {
                                foreach ($submenu_items as $submenu_slug => $submenu_item) {
                                    if (isset($submenu_item['url']) && is_string($submenu_item['url'])) {
                                        $submenu_url_parts = parse_url($submenu_item['url']);
                                        if (isset($submenu_url_parts['query'])) {
                                            parse_str($submenu_url_parts['query'], $submenu_params);
                                            if (isset($submenu_params['page']) && $submenu_params['page'] === 'trash') {
                                                continue;
                                            }
                                        }
                                    }
                                    $sub_cap = isset($submenu_item['capability']) ? (string) $submenu_item['capability'] : '';
                                    if ($sub_cap === '' || current_user_can($sub_cap)) {
                                        $visible_submenu_items[$submenu_slug] = $submenu_item;
                                    }
                                }
                            }

                            $has_submenus = !empty($submenu_items);
                            if ($has_submenus) {
                                // 如果定义了子菜单，则必须存在至少一个对当前用户可见的子级菜单才进行渲染
                                $can_show = !empty($visible_submenu_items);
                                if ($can_show && !$is_global_for_menu) {
                                    $cap = isset($menu_item['capability']) ? (string) $menu_item['capability'] : '';
                                    if ($cap !== '' && !current_user_can($cap)) {
                                        $can_show = false;
                                    }
                                }
                            } else {
                                // 单级菜单（没有子菜单）
                                $can_show = $is_global_for_menu;
                                if (!$can_show) {
                                    $cap = isset($menu_item['capability']) ? (string) $menu_item['capability'] : '';
                                    $can_show = ($cap === '' || current_user_can($cap));
                                }
                            }

                            if ($can_show): ?>
                                <?php
                                $is_active = false;
                                $active_submenu = '';

                                // 检查主菜单是否匹配
                                if ($current_page === $menu_slug) {
                                    $is_active = true;
                                }
                                if (!empty($visible_submenu_items)) {
                                    // 检查子菜单是否匹配
                                    foreach ($visible_submenu_items as $submenu_slug => $submenu_item) {
                                        $submenu_matches = false;

                                        // 解析子菜单URL的参数
                                        $submenu_url_parts = parse_url($submenu_item['url']);
                                        if (isset($submenu_url_parts['query'])) {
                                            parse_str($submenu_url_parts['query'], $submenu_params);

                                            // 检查page参数是否匹配
                                            if (isset($submenu_params['page']) && $submenu_params['page'] === $current_page) {
                                                $submenu_matches = true;

                                                // 检查其他参数是否也匹配
                                                foreach ($submenu_params as $param_key => $param_value) {
                                                    if ($param_key !== 'page') {
                                                        if (!isset($_GET[$param_key]) || $_GET[$param_key] !== $param_value) {
                                                            $submenu_matches = false;
                                                            break;
                                                        }
                                                    }
                                                }
                                            }
                                        }

                                        // 特殊处理：直接匹配submenu_slug
                                        if (!$submenu_matches && ($current_page === $submenu_slug || ($menu_slug === 'users' && $current_page === 'user-batch'))) {
                                            $submenu_matches = true;
                                        }

                                        if ($submenu_matches) {
                                            $is_active = true;
                                            $active_submenu = $submenu_slug;
                                            break;
                                        }
                                    }
                                }
                                // Fallback：如果主菜单已激活但没有匹配到子菜单，尝试选中默认子菜单
                                if ($is_active && empty($active_submenu) && !empty($visible_submenu_items)) {
                                    // 优先选择与当前page一致的子菜单（忽略其他参数）
                                    foreach ($visible_submenu_items as $slug => $item) {
                                        $parts = parse_url($item['url']);
                                        if (isset($parts['query'])) {
                                            parse_str($parts['query'], $params);
                                            if (isset($params['page']) && $params['page'] === $current_page) {
                                                $active_submenu = $slug;
                                                break;
                                            }
                                        }
                                    }
                                    // 若仍为空，默认选中第一个子菜单
                                    if (empty($active_submenu)) {
                                        $keys = array_keys($visible_submenu_items);
                                        $active_submenu = reset($keys);
                                    }
                                }
                                ?>
                                <li class="admin-menu-item <?php echo $is_active ? 'active' : ''; ?>">
                                    <a
                                        href="<?php echo (!empty($visible_submenu_items)) ? 'javascript:void(0)' : $menu_item['url'] . $site_suffix; ?>">
                                        <span class="admin-menu-title"><?php echo $menu_item['title']; ?></span>
                                    </a>

                                    <?php if (!empty($visible_submenu_items)): ?>
                                        <ul class="admin-submenu">
                                            <?php foreach ($visible_submenu_items as $submenu_slug => $submenu_item): ?>
                                                    <li
                                                        class="admin-submenu-item <?php echo ($active_submenu === $submenu_slug || ($submenu_slug === 'user-new' && $current_page === 'user-batch')) ? 'active' : ''; ?>">
                                                        <a href="<?php echo $submenu_item['url'] . $site_suffix; ?>">
                                                            <?php echo $submenu_item['title']; ?>
                                                        </a>
                                                    </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="sidebar-mask"<?php echo (function_exists('todo_is_mobile') && todo_is_mobile()) ? ' onclick="hideSidebarM()"' : ''; ?>></div>
            </div>
            <div class="admin-content">
                <div class="admin-topbar<?php echo ($current_page == 'dashboard' ? ' wel-top' : ''); ?>">
                    <div class="admin-topbar-loading"></div>
                    <div class="admin-title">
                        <div class="hide-sidebar" onclick="<?php echo (function_exists('todo_is_mobile') && todo_is_mobile()) ? 'changeSidebarM()' : 'changeSidebar()'; ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h8m-8 6h16"/></svg>
                        </div>
                        <h1><?php echo $admin_title; ?></h1>
                    </div>

                    <div class="admin-user-info">
                        <div class="admin-user-avatar">
                            <?php
                            $cu_id = 0;
                            $cu_name = 'Guest';
                            if (is_object($current_user)) {
                                if (method_exists($current_user, 'get_id')) {
                                    $cu_id = $current_user->get_id();
                                } elseif (isset($current_user->ID)) {
                                    $cu_id = $current_user->ID;
                                } elseif (isset($current_user->id)) {
                                    $cu_id = $current_user->id;
                                }
                                if (method_exists($current_user, 'get_display_name')) {
                                    $cu_name = $current_user->get_display_name();
                                } elseif (isset($current_user->display_name)) {
                                    $cu_name = $current_user->display_name;
                                } elseif (isset($current_user->user_login)) {
                                    $cu_name = $current_user->user_login;
                                }
                            } elseif (is_array($current_user)) {
                                $cu_id = isset($current_user['ID']) ? $current_user['ID'] : (isset($current_user['id']) ? $current_user['id'] : 0);
                                $cu_name = isset($current_user['display_name']) ? $current_user['display_name'] : (isset($current_user['user_login']) ? $current_user['user_login'] : 'Guest');
                            }
                            ?>
                            <a href="index.php?page=user-edit&id=<?php echo $cu_id . $site_suffix; ?>">
                                <?php
                                if ($cu_name !== 'Guest' && $cu_name !== '') {
                                    $firstChar = mb_substr($cu_name, 0, 1, 'UTF-8');
                                    echo htmlspecialchars(mb_strtoupper($firstChar, 'UTF-8'), ENT_QUOTES, 'UTF-8');
                                } else {
                                    echo '?';
                                }
                                ?>
                            </a>
                        </div>
                        <div class="admin-user-name">
                            <a href="index.php?page=user-edit&id=<?php echo $cu_id . $site_suffix; ?>">
                                <?php echo htmlspecialchars($cu_name, ENT_QUOTES, 'UTF-8'); ?>
                            </a><?php echo ($current_page == 'dashboard' ? '' : ' |'); ?>
                        </div>
                        <div class="admin-user-actions">
                            <?php if (current_user_can('manage_options')): ?>
                                <a href="index.php?page=cache-management<?php echo $site_suffix; ?>">清除缓存</a> |
                            <?php endif; ?>
                            <?php if (current_user_can('manage_themes') || current_user_can('switch_themes') || current_user_can('edit_theme_options') || current_user_can('customize')): ?>
                                <a href="index.php?page=themes<?php echo $site_suffix; ?>">☘ 主题</a> |
                            <?php endif; ?>
                            <a href="<?php echo esc_url(function_exists('todo_home_url') ? todo_home_url() : (function_exists('site_url') ? site_url() : '/')); ?>" target="_blank">Home</a> |
                            <?php 
                            $user_site_ids = function_exists('todo_get_user_site_ids') ? todo_get_user_site_ids() : [];
                            $is_multisite_instance = false;
                            if (function_exists('todo_sites_table_exists') && todo_sites_table_exists() && isset($GLOBALS['tododb']) && is_object($GLOBALS['tododb'])) {
                                $db = $GLOBALS['tododb'];
                                $sites_table = $db->prefix . 'sites';
                                try {
                                    $active_site_count = (int) $db->get_var("SELECT COUNT(*) FROM {$sites_table} WHERE status = 'active'");
                                    $is_multisite_instance = ($active_site_count > 1);
                                } catch (Throwable $e) {
                                    $is_multisite_instance = false;
                                }
                            }
                            $uid_for_site_switch = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
                            $is_admin_for_site_switch = false;
                            if ($uid_for_site_switch > 0 && class_exists('Todo_Session_Manager') && method_exists('Todo_Session_Manager', 'can_access_admin')) {
                                $is_admin_for_site_switch = (bool) Todo_Session_Manager::can_access_admin($uid_for_site_switch);
                            }
                            if (!$is_admin_for_site_switch && function_exists('current_user_can') && current_user_can('manage_options')) {
                                $is_admin_for_site_switch = true;
                            }
                            $show_site_switcher = ($is_multisite_instance && $is_admin_for_site_switch);
                            if ($show_site_switcher):
                                $is_global_scope = !(isset($_GET['site']) && is_numeric($_GET['site']) && (int) $_GET['site'] > 0);
                                $current_sid = $is_global_scope ? 0 : (int) $_GET['site'];
                            ?>
                            <select onchange="(function(sel){var v=sel.value;var href=window.location.href;if(v==='__global__'){document.cookie='todo_site_id=;path=/;max-age=0';href=href.replace(/([?&])site=[^&]*/,'$1');href=href.replace(/\?&/,'?');href=href.replace(/[?&]$/,'');window.location.href=href;return;}var _href=href.replace(/([?&])site=[^&]*/,'$1site='+v);if(_href===href){_href+=(_href.indexOf('?')!==-1?'&':'?')+'site='+v;}window.location.href=_href;})(this);"
                                style="min-width: auto;border: none;padding: 0;font-size: 16px;color: #006dbf;font-weight: 500;margin-left: -5px;cursor: pointer;">
                                <option value="__global__" <?php echo $is_global_scope ? 'selected' : ''; ?>>全局数据site0</option>
                                <?php foreach (array_values(array_unique(array_map('intval', (array) $user_site_ids))) as $sid):
                                    if ($sid <= 0) continue;
                                    $s_obj = function_exists('todo_get_site') ? todo_get_site($sid) : null;
                                ?>
                                <option value="<?php echo $sid; ?>" <?php selected($sid, $current_sid); ?>>
                                    <?php echo esc_html($s_obj->name) . ('site' . $sid); ?>
                                </option>
                                <?php endforeach; ?>
                            </select> |
                            <?php endif; ?>
                            <a href="index.php?action=logout">退出</a>
                        </div>
                    </div>
                </div>
                <div class="admin-main" id="todo-admin-main"
                    style="margin: -20px -20px 0; min-height: calc(100vh - 173px);">
                    <iframe src="<?php echo esc_url(add_query_arg('format', 'iframe')); ?>" id="todo-admin-iframe"
                        name="todo-admin-iframe" style="width:100%; min-height:100%; vertical-align: top; border:none;"
                        frameborder="0" scrolling="no"></iframe>
                </div>
                <script type="text/javascript">
                    (function () {
                        // 动态计算admin-main高度，适应footer变化
                        function resizeAdminMain() {
                            var main = document.getElementById('todo-admin-main');
                            var footer = document.querySelector('.admin-footer');
                            var topbar = document.querySelector('.admin-topbar');

                            if (main && footer) {
                                // 计算footer总高度（含边距）
                                var footerStyle = window.getComputedStyle(footer);
                                var footerHeight = footer.offsetHeight +
                                    (parseFloat(footerStyle.marginTop) || 0) +
                                    (parseFloat(footerStyle.marginBottom) || 0);

                                // 计算topbar高度（如果有）
                                var topHeight = 0;
                                if (topbar) {
                                    var topStyle = window.getComputedStyle(topbar);
                                    topHeight = topbar.offsetHeight +
                                        (parseFloat(topStyle.marginTop) || 0) +
                                        (parseFloat(topStyle.marginBottom) || 0);
                                }

                                var reservedHeight = footerHeight + topHeight + 10;
                                main.style.minHeight = 'calc(100vh - ' + reservedHeight + 'px)';
                            }
                        }

                        window.addEventListener('load', resizeAdminMain);
                        window.addEventListener('resize', resizeAdminMain);
                        document.addEventListener('DOMContentLoaded', resizeAdminMain);
                        // 监听DOM变化，以防footer内容变化
                        if (window.MutationObserver) {
                            var observer = new MutationObserver(resizeAdminMain);
                            var footer = document.querySelector('.admin-footer');
                            if (footer) observer.observe(footer, { childList: true, subtree: true, attributes: true });
                        }

                        // 暴露给全局以便调用
                        window.TodoCMS = window.TodoCMS || {};

                        // 监听iframe加载，处理404错误
                        var iframe = document.getElementById('todo-admin-iframe');
                        if (iframe) {
                            iframe.addEventListener('load', function() {
                                try {
                                    var doc = iframe.contentDocument || iframe.contentWindow.document;
                                    // 浏览器通常会将纯文本包裹在 pre 标签中，或者直接作为 body 内容
                                    var content = doc.body ? (doc.body.textContent || doc.body.innerText) : '';
                                    if (content && content.trim() === '404 Not Found') {
                                        window.location.reload();
                                    }
                                } catch (e) {
                                    // 忽略跨域错误
                                }
                            });
                        }
                        window.TodoCMS.resizeAdminMain = resizeAdminMain;

                        // 客户端全链路导航耗时追踪器
                        window.TodoPerfTracker = window.TodoPerfTracker || {
                            currentNav: null,
                            lastNav: null,
                            startNav: function(url, source) {
                                this.currentNav = {
                                    url: url || window.location.href,
                                    source: source || 'nav',
                                    startTime: (window.performance && window.performance.now) ? window.performance.now() : Date.now(),
                                    startTimestamp: new Date().toLocaleTimeString(),
                                    endTime: null,
                                    durationMs: null
                                };
                            },
                            endNav: function() {
                                if (this.currentNav && !this.currentNav.endTime) {
                                    var now = (window.performance && window.performance.now) ? window.performance.now() : Date.now();
                                    this.currentNav.endTime = now;
                                    this.currentNav.durationMs = Math.round(now - this.currentNav.startTime);
                                    this.lastNav = this.currentNav;
                                    if (typeof window.dispatchEvent === 'function') {
                                        window.dispatchEvent(new CustomEvent('todo-perf-nav-complete', { detail: this.lastNav }));
                                    }
                                }
                                return this.lastNav;
                            }
                        };

                        // 加载进度条逻辑与性能计时绑定
                        window.TodoCMS.startLoading = function(url) {
                            var $loading = jQuery('.admin-topbar-loading');
                            if ($loading.length) {
                                $loading.stop().show();
                            }
                            if (window.TodoPerfTracker) {
                                window.TodoPerfTracker.startNav(url, 'startLoading');
                            }
                        };

                        window.TodoCMS.stopLoading = function() {
                            var $loading = jQuery('.admin-topbar-loading');
                            if ($loading.length) {
                                $loading.fadeOut(300);
                            }
                            if (window.TodoPerfTracker) {
                                window.TodoPerfTracker.endNav();
                            }
                        };
                    })();
                </script>
                <script type="text/javascript">
                    // 动态计算logo与首个菜单标题左对齐
                    function alignLogoToMenuTitle() {
                        var logoLink = document.querySelector('.admin-logo > a');
                        var firstMenuTitle = document.querySelector('.admin-menu .admin-menu-title');
                        if (!logoLink || !firstMenuTitle) return;

                        // 重置 transform 以获取不受之前偏移影响的原始屏幕位置
                        logoLink.style.transform = '';

                        var logoRect = logoLink.getBoundingClientRect();
                        var titleRect = firstMenuTitle.getBoundingClientRect();

                        // 元素可见且位置有效时进行计算
                        if (logoRect.width > 0 && titleRect.width > 0 && logoRect.left > titleRect.left) {
                            var offset = titleRect.left - logoRect.left;
                            logoLink.style.transform = 'translateX(' + Math.round(offset) + 'px)';
                        }
                    }

                    // 切换左侧菜单
                    if (localStorage.getItem('todo_sidebar_hidden') === '1') {
                        document.querySelector('.admin-container').classList.add('sidebar-fixed');
                        document.querySelector('.admin-sidebar').style.display = 'none';
                    }

                    function changeSidebar() {
                        var isHidden = localStorage.getItem('todo_sidebar_hidden') === '1';
                        localStorage.setItem('todo_sidebar_hidden', isHidden ? '0' : '1');
                        window.location.reload();
                    }

                    function changeSidebarM() {
                        var isHidden = localStorage.getItem('todo_sidebar_hidden');
                        if(isHidden && isHidden === '1') {
                            localStorage.setItem('todo_sidebar_hidden', '0');
                            document.querySelector('.admin-sidebar').removeAttribute('style');
                            requestAnimationFrame(function () {
                                alignLogoToMenuTitle();
                            });
                        } else {
                            localStorage.setItem('todo_sidebar_hidden', '1');
                            window.location.reload();
                        }
                    }

                    function hideSidebarM() {
                        localStorage.setItem('todo_sidebar_hidden', '1');
                        document.querySelector('.admin-sidebar').style.display = 'none';
                    }

                    // 外部定义的专属函数：控制iframe高度
                    window.TodoCMS = window.TodoCMS || {};
                    window.TodoCMS.updateIframeHeight = function (height) {
                        var iframe = document.getElementById('todo-admin-iframe');
                        if (iframe) {
                            iframe.style.height = height + 'px';
                            // 触发父容器高度重新计算
                            if (window.TodoCMS.resizeAdminMain) {
                                // 使用requestAnimationFrame延迟执行，减少事件触发频率
                                if (window.TodoCMS._resizeTimer) {
                                    clearTimeout(window.TodoCMS._resizeTimer);
                                }
                                window.TodoCMS._resizeTimer = setTimeout(function() {
                                    window.TodoCMS.resizeAdminMain();
                                }, 100);
                            }
                        }
                    };

                    jQuery(document).ready(function ($) {
                        var $iframe = $('#todo-admin-iframe');
                        if ($iframe.length && $iframe.attr('src')) {
                            window.TodoCMS.startLoading();
                        }
                        var currentIframeSrc = normalizeUrl($iframe.attr('src') || ''); // 记录当前 iframe 的 src（规范化后）
                        
                        // 从 PHP 菜单配置动态生成标题映射
                        var titleMap = <?php
                        // 构建标题映射表（page => title）
                        $title_map = array();
                        
                        // 遍历主菜单（使用 $admin_menu 而非不存在的 $admin_menu_rendered）
                        if (isset($admin_menu) && is_array($admin_menu)) {
                            foreach ($admin_menu as $menu_key => $menu_item) {
                                // 提取 page 参数
                                if (isset($menu_item['url'])) {
                                    if (preg_match('/[?&]page=([^&]+)/', $menu_item['url'], $matches)) {
                                        $title_map[$matches[1]] = $menu_item['title'];
                                    }
                                }
                                
                                // 遍历子菜单
                                if (isset($menu_item['submenu']) && is_array($menu_item['submenu'])) {
                                    foreach ($menu_item['submenu'] as $submenu_key => $submenu_item) {
                                        if (isset($submenu_item['url'])) {
                                            if (preg_match('/[?&]page=([^&]+)/', $submenu_item['url'], $matches)) {
                                                $title_map[$matches[1]] = $submenu_item['title'];
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        
                        // 补充无路由页面的标题
                        $title_map['theme-customize'] = '主题自定义';
                        $title_map['user-edit'] = '个人资料';
                        $title_map['user-batch'] = '批量添加';
                        $title_map['post-edit'] = '编辑文章';
                        $title_map['cache-management'] = '缓存管理';
                        $title_map['plugin-settings'] = '插件设置';
                        $title_map['plugin-manage'] = '插件管理';
                        $title_map['todo-service-settings'] = '服务设置';
                        $title_map['themes'] = '主题管理';
                        $title_map['theme-studio'] = '主题工作室';
                        $title_map['models-edit'] = '模型编辑';
                        $title_map['model-fields'] = '模型字段';
                        $title_map['models-edit-field'] = '字段编辑';
                        $title_map['language-terms'] = '词汇';
                        $title_map['language-translations'] = '翻译';
                        $title_map['template-tags'] = '模板标签手册';
                        $title_map['todo-gift-cards'] = '礼品卡';
                        $title_map['language-template-detail'] = '模板语言';
                        $title_map['search-logs'] = '搜索词';
                        
                        echo json_encode($title_map, JSON_UNESCAPED_UNICODE);
                        ?>;
                        
                        // 将 titleMap 存储到 sessionStorage 中，避免重复查找
                        try {
                            sessionStorage.setItem('todo_title_map', JSON.stringify(titleMap));
                        } catch (e) {
                            // 如果 sessionStorage 不可用，继续使用内存中的 titleMap
                        }
                        
                        // 辅助函数：从 URL 中提取 page 参数
                        function getPageFromUrl(url) {
                            if (!url) return null;
                            
                            // 方法1：使用 URLSearchParams（现代浏览器）
                            try {
                                var urlObj = new URL(url, window.location.href);
                                var from = urlObj.searchParams.get('from');
                                if (from) return from;
                                var page = urlObj.searchParams.get('page');
                                if (page) return page;
                            } catch (e) {
                                // 旧浏览器不支持 URL API，继续使用正则
                            }
                            
                            // 方法2：使用正则表达式（兼容旧浏览器）
                            var matchFrom = url.match(/[?&]from=([^&#]+)/);
                            if (matchFrom) return decodeURIComponent(matchFrom[1]);
                            var matchPage = url.match(/[?&]page=([^&#]+)/);
                            return matchPage ? decodeURIComponent(matchPage[1]) : null;
                        }
                        
                        // 辅助函数：规范化 URL（确保格式一致，用于缓存比较）
                        function normalizeUrl(url) {
                            if (!url) return '';
                            try {
                                // 使用 URL API 规范化
                                var urlObj = new URL(url, window.location.href);
                                // 统一移除 format=iframe 参数进行比较，确保内部跳转后也能正确识别
                                urlObj.searchParams.delete('format');
                                // 只保留 pathname + search，去掉 origin 部分
                                return urlObj.pathname + (urlObj.search || '');
                            } catch (e) {
                                // 降级处理：移除开头的域名和协议，并清理 format 参数
                                var normalized = url.replace(/^https?:\/\/[^\/]+/, '')
                                                   .replace(/[&?]format=iframe(&|$)/, '$1')
                                                   .replace(/\?$/, '');
                                return normalized;
                            }
                        }
                        
                        // 辅助函数：构建 iframe 专用的 URL
                        function getIframeSrc(url) {
                            if (!url) return '';
                            if (url.indexOf('format=iframe') !== -1) return url;
                            var separator = url.indexOf('?') !== -1 ? '&' : '?';
                            return url + separator + 'format=iframe';
                        }
                        
                        // 辅助函数：同步菜单高亮
                        function syncMenuHighlight(url) {
                            var pageName = getPageFromUrl(url);
                            if (!pageName) return;

                            var $menuItems = $('.admin-menu-item, .admin-submenu-item');
                            $menuItems.removeClass('active');

                            var $links = $('.admin-menu a, .admin-submenu a').filter(function () {
                                var href = $(this).attr('href');
                                return href && href !== '#' && href.indexOf('javascript:') !== 0;
                            });

                            var hasExactMatch = false;
                            $links.each(function () {
                                var href = $(this).attr('href');
                                var itemPage = getPageFromUrl(href);
                                if (itemPage === pageName) {
                                    hasExactMatch = true;
                                    return false;
                                }
                            });

                            $links.each(function() {
                                var href = $(this).attr('href');
                                var itemText = $(this).text().trim();
                                var itemPage = getPageFromUrl(href);
                                var isMatch = (itemPage === pageName);
                                
                                if (!isMatch && !hasExactMatch) {
                                    if (itemPage === 'users' && pageName === 'user-edit') isMatch = true;
                                    if (itemPage === 'user-new' && pageName === 'user-batch') isMatch = true;
                                    if (itemPage === 'posts' && (pageName === 'post-new' || pageName === 'post-edit')) isMatch = true;
                                    if (itemPage === 'ad-groups' && pageName === 'ad-group-edit') isMatch = true;
                                    if (itemPage === 'ads' && (pageName === 'ad-new' || pageName === 'ad-edit')) isMatch = true;
                                    if (itemPage === 'language' && (pageName === 'language-terms' || pageName === 'language-translations')) isMatch = true;
                                    if (itemPage === 'tags' && pageName === 'search-logs') isMatch = true;
                                }

                                if (isMatch) {
                                    var $li = $(this).closest('li');
                                    $li.addClass('active');
                                    
                                    // 高亮其父菜单项（如果是二级菜单）
                                    var $parentLi = $li.closest('.admin-menu-item');
                                    if ($parentLi.length) {
                                        $parentLi.addClass('active');
                                    }
                                }
                            });
                        }

                        // 监听 Iframe 消息
                        window.addEventListener('message', function (e) {
                            var data = e.data;
                            if (data && data.type === 'url_change') {
                                // 更新浏览器地址栏
                                if (data.url) {
                                    var currentUrl = window.location.href;
                                    // 避免重复 pushState
                                    if (currentUrl !== data.url) {
                                        history.pushState(null, data.title, data.url);
                                    }
                                    
                                    // 优化：从 sessionStorage 中的 titleMap 获取标题，避免使用 iframe 传来的标题
                                    var pageName = getPageFromUrl(data.url);
                                    if (pageName) {
                                        var cachedTitleMap = null;
                                        try {
                                            cachedTitleMap = JSON.parse(sessionStorage.getItem('todo_title_map'));
                                        } catch (e) {
                                            cachedTitleMap = titleMap;
                                        }
                                        
                                        var newTitle = (cachedTitleMap && cachedTitleMap[pageName]) || pageName;
                                        var fullTitle = newTitle + ' - <?php echo esc_js($site_title); ?>';
                                        
                                        if (fullTitle !== document.title) {
                                            document.title = fullTitle;
                                        }
                                        
                                        // 更新页面顶部标题 (Route Name)
                                        var adminTitleH1 = document.querySelector('.admin-title h1');
                                        if (adminTitleH1 && adminTitleH1.textContent !== newTitle) {
                                            adminTitleH1.textContent = newTitle;
                                        }

                                        // 动态更新首页样式（wel-top class 和用户名分隔符）
                                        var isDashboard = (pageName === 'dashboard');
                                        var userInfoEl = document.querySelector('.admin-topbar');
                                        if (userInfoEl) {
                                            userInfoEl.classList.toggle('wel-top', isDashboard);
                                        }
                                        var userNameEl = userInfoEl && userInfoEl.querySelector('.admin-user-name a');
                                        if (userNameEl) {
                                            var nextSibling = userNameEl.nextSibling;
                                            if (nextSibling && nextSibling.nodeType === 3) {
                                                nextSibling.textContent = isDashboard ? '' : ' |';
                                            }
                                        }

                                        // 显示加载进度条
                                        window.TodoCMS.stopLoading();
                                    }

                                    // 重置主页面滚动条
                                    window.scrollTo(0, 0);
                                    
                                    // 核心修复：同步当前 iframe 的 URL 记录（规范化后）
                                    // 解决 iframe 内部跳转后，点击菜单无法刷新的问题
                                    currentIframeSrc = normalizeUrl(data.url);
                                    
                                    // 同步菜单高亮
                                    syncMenuHighlight(data.url);
                                }
                            }
                        });

                        // 监听浏览器后退/前进
                        window.addEventListener('popstate', function (e) {
                            var url = window.location.href;
                            var normalizedNewSrc = normalizeUrl(url); // 规范化 URL
                            
                            // 性能优化：只在 URL 真正改变时才加载 iframe
                            if (currentIframeSrc !== normalizedNewSrc) {
                                window.TodoCMS.startLoading();
                                $iframe.css('height', '');
                                var newSrc = getIframeSrc(url);
                                $iframe.attr('src', newSrc);
                                currentIframeSrc = normalizedNewSrc;
                                
                                // 同步菜单高亮
                                syncMenuHighlight(url);

                                // 动态更新首页样式
                                var pageName = getPageFromUrl(url);
                                var isDashboard = (pageName === 'dashboard');
                                var userInfoEl = document.querySelector('.admin-topbar');
                                if (userInfoEl) {
                                    userInfoEl.classList.toggle('wel-top', isDashboard);
                                }
                            }
                        });

                        // Iframe 加载完成回调
                        $iframe.on('load', function() {
                            window.TodoCMS.stopLoading();
                        });

                        // 菜单点击接管
                        $('.admin-menu a, .admin-submenu a').not('[target="_blank"]').off('click').on('click', function (e) {
                            var href = $(this).attr('href');
                            if (href && href !== '#' && href.indexOf('javascript:') === -1) {
                                // 自动补全 site 参数
                                var currentUrlObj = new URL(window.location.href);
                                var siteId = currentUrlObj.searchParams.get('site');
                                if (siteId && href.indexOf('site=') === -1) {
                                    var separator = href.indexOf('?') !== -1 ? '&' : '?';
                                    href = href + separator + 'site=' + siteId;
                                }

                                e.preventDefault();
                                e.stopPropagation();
                                
                                // 第 1 步：同步菜单高亮
                                syncMenuHighlight(href);
                                
                                // 第 2 步：立即更新标题（从 URL 提取 page 参数）
                                var pageName = getPageFromUrl(href);
                                if (pageName) {
                                    var newTitle = titleMap[pageName] || pageName;
                                    document.title = newTitle + ' - <?php echo esc_js($site_title); ?>';

                                    var adminTitleH1 = document.querySelector('.admin-title h1');
                                    if (adminTitleH1) {
                                        adminTitleH1.textContent = newTitle;
                                    }
                                }

                                 // 第 3 步：智能加载 iframe（仅当 URL 变化时）
                                var normalizedNewSrc = normalizeUrl(href); // 规范化 URL

                                // 性能优化：只在 URL 真正改变时才加载 iframe
                                if (currentIframeSrc !== normalizedNewSrc) {
                                    // 显示加载进度条
                                    window.TodoCMS.startLoading();
                                    // 立即更新 URL
                                    history.pushState(null, '', href);
                                    
                                    currentIframeSrc = normalizedNewSrc; // 立即更新记录，防止快速点击时的竞态条件
                                    var newSrc = getIframeSrc(href);
                                    setTimeout(function() {
                                        $iframe.attr('src', newSrc);
                                    }, 0);
                                }
                            }
                        });
                        
                        // 顶部操作链接点击接管（包括个人资料和其他顶部链接）
                        $('.admin-user-avatar a, .admin-user-name a, .admin-user-actions a').not('[target="_blank"]').not('[href*="action=logout"]').on('click', function (e) {
                            var href = $(this).attr('href');
                            if (href && href !== '#' && href.indexOf('javascript:') === -1) {
                                // 自动补全 site 参数
                                var currentUrlObj = new URL(window.location.href);
                                var siteId = currentUrlObj.searchParams.get('site');
                                if (siteId && href.indexOf('site=') === -1) {
                                    var separator = href.indexOf('?') !== -1 ? '&' : '?';
                                    href = href + separator + 'site=' + siteId;
                                }

                                e.preventDefault();
                                e.stopPropagation();
                                
                                // 第 1 步：立即更新标题（从 URL 提取 page 参数）
                                var pageName = getPageFromUrl(href);
                                if (pageName) {
                                    var newTitle = titleMap[pageName] || pageName;
                                    document.title = newTitle + ' - <?php echo esc_js($site_title); ?>';
                                    
                                    var adminTitleH1 = document.querySelector('.admin-title h1');
                                    if (adminTitleH1) {
                                        adminTitleH1.textContent = newTitle;
                                    }
                                }
                                // 第 2 步：智能加载 iframe（仅当 URL 变化时）
                                var normalizedNewSrc = normalizeUrl(href); // 规范化 URL
                                
                                // 性能优化：只在 URL 真正改变时才加载 iframe
                                if (currentIframeSrc !== normalizedNewSrc) {
                                    // 显示加载进度条
                                    window.TodoCMS.startLoading();
                                    // 立即更新 URL
                                    history.pushState(null, '', href);
                                    
                                    currentIframeSrc = normalizedNewSrc; // 立即更新记录，防止快速点击时的竞态条件
                                    var newSrc = getIframeSrc(href);
                                    setTimeout(function() {
                                        $iframe.attr('src', newSrc);
                                    }, 0);
                                }
                            }
                        });

                        // 初始化：执行一次菜单高亮同步
                        syncMenuHighlight(window.location.href);

                        // 页面初始化及窗口改变时计算 Logo 对齐
                        alignLogoToMenuTitle();
                        window.addEventListener('resize', alignLogoToMenuTitle);
                    });
                </script>
                <div id="todo-global-components"></div>
                <?php
                require dirname(__FILE__) . '/footer.php';
                exit;
                ?>
            </div><!-- .admin-content不渲染 -->
        </div><!-- .admin-container不渲染 -->
    <?php endif; ?>

    <!-- Iframe Mode Content Wrapper -->
    <div class="admin-main" id="todo-admin-main">
        <?php if ($is_iframe_mode): ?>
            <?php if (function_exists('todo_admin_notices')) { todo_admin_notices(); } ?>
            <script type="text/javascript">
                (function () {
                    // 自动高度计算逻辑：当iframe内部页面具有动态元素，自动计算并通知外部更新
                    function initAutoHeight() {
                        if (!window.parent || window.parent === window) return;

                        // 强制重置 html 和 body 高度，打破无限增长循环
                        var style = document.createElement('style');
                        style.innerHTML = 'html, body { height: auto !important; min-height: 0 !important; overflow-y: hidden !important; }';
                        document.head.appendChild(style);

                        var lastHeight = 0;
                        var updateTimer = null;

                        function updateHeight() {
                            var body = document.body;
                            var html = document.documentElement;

                            if (!body || !html) return;

                            // 计算页面实际内容高度
                            // 仅使用 body.scrollHeight 并加上边距，避免 html.scrollHeight 可能包含视口高度导致无法收缩的问题
                            var style = window.getComputedStyle(body);
                            var marginTop = parseFloat(style.marginTop) || 0;
                            var marginBottom = parseFloat(style.marginBottom) || 0;

                            var height = body.scrollHeight + marginTop + marginBottom;

                            // 只有当高度变化超过 1px 时才更新，防止计算抖动
                            if (Math.abs(height - lastHeight) > 1) {
                                lastHeight = height;
                                // 通知外部专属函数
                                if (window.parent.TodoCMS && window.parent.TodoCMS.updateIframeHeight) {
                                    window.parent.TodoCMS.updateIframeHeight(height);
                                }
                            }
                        }

                        // 监听DOM变化（动态元素）
                        var observer = new MutationObserver(function (mutations) {
                            if (updateTimer) clearTimeout(updateTimer);
                            updateTimer = setTimeout(updateHeight, 50);
                        });

                        if (document.body) {
                            observer.observe(document.body, {
                                attributes: true,
                                childList: true,
                                subtree: true,
                                characterData: true
                            });
                        }

                        // 监听窗口事件
                        window.addEventListener('resize', updateHeight);
                        window.addEventListener('load', updateHeight);

                        // 初始执行
                        setTimeout(updateHeight, 100);
                        // 监听点击事件，处理交互导致的布局变化（如折叠菜单、Tab切换等）
                        document.addEventListener('click', function () {
                            // 点击后立即检查
                            if (updateTimer) clearTimeout(updateTimer);
                            updateTimer = setTimeout(updateHeight, 50);
                            // 延迟再次检查，等待可能的CSS动画结束
                            setTimeout(updateHeight, 300);
                        });

                        // 监听过渡效果结束
                        document.addEventListener('transitionend', function (e) {
                            if (e.target !== document && e.target !== window) {
                                updateHeight();
                            }
                        });
                    }

                    // 启动自动高度
                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', initAutoHeight);
                    } else {
                        initAutoHeight();
                    }

                    function notifyParent() {
                        if (window.parent && window.parent !== window) {
                            // 移除 format=iframe 参数，获取干净的 URL 发送给父级
                            var currentUrl = window.location.href;
                            try {
                                var urlObj = new URL(currentUrl);
                                urlObj.searchParams.delete('format');
                                currentUrl = urlObj.pathname + urlObj.search;
                            } catch (e) {
                                currentUrl = currentUrl.replace(/[&?]format=iframe(&|$)/, '$1').replace(/\?$/, '');
                            }
                            
                            window.parent.postMessage({
                                type: 'url_change',
                                url: currentUrl,
                                title: document.title
                            }, '*');
                        }
                    }

                    // 监听内部跳转，触发父级进度条
                    window.addEventListener('beforeunload', function () {
                        if (window.parent && window.parent.TodoCMS && window.parent.TodoCMS.startLoading) {
                            window.parent.TodoCMS.startLoading();
                            // 重置主页面滚动条
                            window.parent.scrollTo(0, 0);
                        }
                    });

                    function moveGlobalComponents() {
                        if (window.parent && window.parent !== window) {
                            var parentBody = window.parent.document.body;
                            // 移动需要在全局遮罩的元素到父级body
                            var idsToMove = [];
                            var classesToMove = [
                                'todo-modal-overlay',
                                'todo-captcha-modal',
                                'todo-global-loading-overlay',
                                'todo-toast-container',
                                'todo-toast',
                                'todo-message',
                                'media-modal-overlay',
                                'media-message',
                                'media-modal'
                            ];

                            try {
                                var selectorsToClear = [];
                                idsToMove.forEach(function (id) {
                                    selectorsToClear.push('#' + id);
                                });
                                classesToMove.forEach(function (className) {
                                    selectorsToClear.push('.' + className);
                                });

                                selectorsToClear.forEach(function (selector) {
                                    var nodes = parentBody.querySelectorAll(selector);
                                    if (!nodes || !nodes.length) return;
                                    Array.from(nodes).forEach(function (n) {
                                        if (n && n.parentNode) {
                                            n.parentNode.removeChild(n);
                                        }
                                    });
                                });
                            } catch (e) {
                            }

                            var moveElement = function (el) {
                                if (el && el.parentNode !== parentBody) {
                                    // 检查父级是否已有同ID元素，若有则移除旧的，防止堆积
                                    if (el.id) {
                                        var existing = parentBody.querySelector('#' + el.id);
                                        if (existing && existing !== el) {
                                            existing.remove();
                                        }
                                    }
                                    // 保持事件绑定，使用 appendChild (它会将节点移动而不是复制)
                                    parentBody.appendChild(el);
                                    // 如果是隐藏的，可能需要调整 display
                                    // 但通常保持原样即可
                                }
                            };

                            var move = function () {
                                // Move by ID
                                idsToMove.forEach(function (id) {
                                    var el = document.getElementById(id);
                                    moveElement(el);
                                });

                                // Move by Class
                                classesToMove.forEach(function (className) {
                                    var elements = document.getElementsByClassName(className);
                                    // Convert to array to avoid live collection issues during iteration
                                    Array.from(elements).forEach(function (el) {
                                        moveElement(el);
                                    });
                                });
                            };

                            // Initial move
                            move();

                            // 使用 MutationObserver 监听动态添加的元素 (如 UEditor 创建的弹窗)
                            var observer = new MutationObserver(function (mutations) {
                                var shouldMove = false;
                                mutations.forEach(function (mutation) {
                                    if (mutation.addedNodes.length) {
                                        mutation.addedNodes.forEach(function (node) {
                                            if (node.nodeType === 1) { // Element node
                                                // Check IDs
                                                if (idsToMove.includes(node.id)) {
                                                    moveElement(node);
                                                }
                                                // Check Classes
                                                classesToMove.forEach(function (className) {
                                                    if (node.classList && node.classList.contains(className)) {
                                                        moveElement(node);
                                                    }
                                                });
                                                // Check children for classes (optional, but good for nested adds)
                                                classesToMove.forEach(function (className) {
                                                    if (node.getElementsByClassName) {
                                                        var children = node.getElementsByClassName(className);
                                                        Array.from(children).forEach(function (child) {
                                                            moveElement(child);
                                                        });
                                                    }
                                                });
                                            }
                                        });
                                    }
                                });
                            });

                            observer.observe(document.body, { childList: true, subtree: true });

                            // 监听DOM变化，处理动态添加的元素
                            var observer = new MutationObserver(function (mutations) {
                                move();
                            });
                            observer.observe(document.body, { childList: true, subtree: true });
                        }
                    }

                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', function () {
                            notifyParent();
                            moveGlobalComponents();
                        });
                    } else {
                        notifyParent();
                        moveGlobalComponents();
                    }
                })();
            </script>
        <?php endif; ?>
