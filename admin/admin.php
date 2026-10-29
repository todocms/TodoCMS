<?php
/**
 * TodoCMS Administration Bootstrap
 *
 * @package TodoCMS
 * @subpackage Administration
 */

// 直接访问隐藏：禁止通过 /admin/admin.php 作为入口访问，返回 404（允许被其他入口包含）
if (php_sapi_name() !== 'cli') {
    $executed = isset($_SERVER['SCRIPT_FILENAME']) ? basename($_SERVER['SCRIPT_FILENAME']) : '';
    if ($executed === basename(__FILE__)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow', true);
        exit('404 Not Found');
    }
}

// 引入配置文件
require_once dirname(__DIR__) . '/config.php';

// 定义根目录常量
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

// 定义URL常量（确保在admin环境中也能正确使用）
if (!defined('TODO_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = todo_get_http_host();
    // 基于 DOCUMENT_ROOT 与 ABSPATH 计算安装前缀，避免误包含 /admin
    $doc_root = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'], '/')) : '';
    $abs_path = defined('ABSPATH') ? str_replace('\\', '/', rtrim(ABSPATH, '/')) : '';
    $prefix = '';
    if ($doc_root && $abs_path && strpos($abs_path, $doc_root) === 0) {
        $relative = substr($abs_path, strlen($doc_root));
        $relative = trim($relative, '/');
        if ($relative !== '') {
            $prefix = '/' . $relative;
        }
    } else {
        // 回退：从脚本路径剥离 /admin 及其子路径
        $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
        $base_path = rtrim(dirname($script_name), '/');
        $base_path = preg_replace('#/admin(/.*)?$#', '', $base_path);
        if ($base_path === '/' || $base_path === '\\') {
            $base_path = '';
        }
        $prefix = $base_path;
    }
    define('TODO_URL', $protocol . $host . $prefix);
}

if (!defined('ADMIN_URL')) {
    define('ADMIN_URL', TODO_URL . '/admin');
}

// 加载核心函数
require_once dirname(__DIR__) . '/core/functions/load_functions.php';

// 加载核心引导文件（包含所有核心类）
require_once dirname(__DIR__) . '/core/bootstrap.php';

/**
 * We are in the TodoCMS admin, set this constant.
 *
 * @since 1.0.0
 */
if (!defined('TODO_ADMIN')) {
    define('TODO_ADMIN', true);
}

/**
 * Legacy admin constant for backward compatibility
 *
 * @since 1.0.0
 */
if (!defined('IS_ADMIN')) {
    define('IS_ADMIN', true);
}

if (!defined('TODO_NETWORK_ADMIN')) {
    define('TODO_NETWORK_ADMIN', false);
}

if (!defined('TODO_USER_ADMIN')) {
    define('TODO_USER_ADMIN', false);
}

if (!defined('TODO_BLOG_ADMIN')) {
    define('TODO_BLOG_ADMIN', true);
}

if (isset($_GET['import']) && !defined('TODO_LOAD_IMPORTERS')) {
    define('TODO_LOAD_IMPORTERS', true);
}

// 加载后台函数库
if (file_exists(__DIR__ . '/includes/functions.php')) {
    require_once(__DIR__ . '/includes/functions.php');
}

// Load TodoCMS functions
require_once(ADMIN_DIR . '/includes/todo-functions.php');
require_once(ADMIN_DIR . '/includes/post-functions.php');
require_once(ADMIN_DIR . '/includes/meta-boxes.php');
require_once(ADMIN_DIR . '/includes/taxonomy-functions.php');
require_once(ADMIN_DIR . '/includes/template-functions.php');
require_once(ABSPATH . 'core/classes/class-comment-manager.php');

require_once(dirname(__FILE__) . '/admin-header.php');

/**
 * TodoCMS Administration Template Header
 *
 * @package TodoCMS
 * @subpackage Administration
 */

// Make sure the user is logged in
if (!is_user_logged_in()) {
    auth_redirect();
}

// Load TodoCMS dashboard API
require_once(ADMIN_DIR . '/pages/dashboard/dashboard.php');

// Load post functions
require_once(ADMIN_DIR . '/includes/post-functions.php');

// Load template functions
require_once(ADMIN_DIR . '/includes/template-functions.php');

// Load taxonomy functions
require_once(ADMIN_DIR . '/includes/taxonomy-functions.php');

// Load file functions
require_once(ADMIN_DIR . '/includes/file.php');

// Load AJAX functions
require_once(ADMIN_DIR . '/includes/ajax-functions.php');

// Load rewrite functions
require_once(dirname(__DIR__) . '/core/functions/rewrite-functions.php');

// Load meta boxes functions
require_once(ADMIN_DIR . '/includes/meta-boxes.php');

// Load shop menu
require_once(ADMIN_DIR . '/includes/shop-menu.php');

// Load AJAX functions
require_once(ADMIN_DIR . '/includes/ajax-actions.php');

// Load admin bar functions
require_once(ADMIN_DIR . '/includes/admin-bar.php');

// Load continents and cities functions
require_once(ADMIN_DIR . '/includes/continents-cities.php');

// Load credits functions
require_once(ADMIN_DIR . '/includes/credits.php');

// Load freedoms functions
require_once(ADMIN_DIR . '/includes/freedoms.php');

// Load privacy functions
require_once(ADMIN_DIR . '/includes/privacy.php');

// Load network functions
if (is_multisite()) {
    require_once(ADMIN_DIR . '/includes/ms.php');
    require_once(ADMIN_DIR . '/includes/ms.php');
}

$date_format = __('F j, Y');
$time_format = __('g:i a');

/**
 * Filters the display of the date and time format dropdown in the admin.
 *
 * @since 2.9.0
 *
 * @param array $default_date_format_choices Array of default date format choices.
 */
$date_formats = array_unique(apply_filters('date_formats', array(
    __('F j, Y'),
    'Y-m-d',
    'm/d/Y',
    'd/m/Y',
)));

/**
 * Filters the display of the time format dropdown in the admin.
 *
 * @since 2.9.0
 *
 * @param array $default_time_format_choices Array of default time format choices.
 */
$time_formats = array_unique(apply_filters('time_formats', array(
    __('g:i a'),
    'g:i A',
    'H:i',
)));

/**
 * $pagenow is set in vars.php
 * $todo_importers is sometimes set in todo-admin/includes/import.php
 * The remaining variables are imported as globals elsewhere, declared as globals here
 *
 * @global string $pagenow
 * @global array  $todo_importers
 * @global string $hook_suffix
 * @global string $plugin_page
 * @global string $typenow
 * @global string $taxnow
 */
global $pagenow, $todo_importers, $hook_suffix, $plugin_page, $typenow, $taxnow;

$page_hook = null;

set_current_screen();

// Handle plugin admin pages.
if (isset($_GET['page']) && $_GET['page'] === 'mail') {
    include(ADMIN_DIR . '/pages/mail/settings.php');
    exit;
}

if (isset($plugin_page)) {
    if (!empty($typenow)) {
        $the_parent = $pagenow . '?post_type=' . $typenow;
    } else {
        $the_parent = $pagenow;
    }
    
    if (!$page_hook = get_plugin_page_hook($plugin_page, $the_parent)) {
        $page_hook = get_plugin_page_hook($plugin_page, $plugin_page);
        
        // Back-compat for plugins using add_management_page().
        if (empty($page_hook) && 'edit.php' == $pagenow && '' != get_plugin_page_hook($plugin_page, 'tools.php')) {
            // There could be plugin specific params on the URL, so we need the whole query string
            if (!empty($_SERVER['QUERY_STRING'])) {
                $query_string = $_SERVER['QUERY_STRING'];
            } else {
                $query_string = 'page=' . $plugin_page;
            }
            todo_redirect(todo_admin_url('tools.php?' . $query_string));
            exit;
        }
    }
    unset($the_parent);
}

$hook_suffix = '';
if (isset($page_hook)) {
    $hook_suffix = $page_hook;
} elseif (isset($plugin_page)) {
    $hook_suffix = $plugin_page;
} elseif (isset($pagenow)) {
    $hook_suffix = $pagenow;
}

set_current_screen();

// Handle meta box screen columns.
if (isset($_GET['meta-box-loader'])) {
    require_once(ADMIN_DIR . '/includes/ajax-actions.php');
    todo_ajax_meta_box_loader();
    die();
}

// Handle heartbeat requests.
if (isset($_REQUEST['_todononce']) && Todo_Security_Manager::verify_request_csrf('heartbeat-nonce', '_todononce')) {
    require_once(ADMIN_DIR . '/includes/ajax-actions.php');
    todo_ajax_heartbeat();
    die();
}

// Handle AJAX requests.
if (defined('DOING_AJAX') && DOING_AJAX) {
    require_once(ADMIN_DIR . '/includes/ajax-actions.php');
    
    if (!empty($_REQUEST['action'])) {
        $action = $_REQUEST['action'];
        
        if (is_user_logged_in()) {
            // If no action is registered, return a Bad Request response.
            if (!has_action("todo_ajax_{$action}")) {
                todo_die('0', 400);
            }
            
            /**
             * Fires authenticated AJAX actions for logged-in users.
             *
             * The dynamic portion of the hook name, `$action`, refers to the name
             * of the AJAX action callback being fired.
             *
             * @since 2.1.0
             */
            do_action("todo_ajax_{$action}");
        } else {
            // If no action is registered, return a Bad Request response.
            if (!has_action("todo_ajax_nopriv_{$action}")) {
                todo_die('0', 400);
            }
            
            /**
             * Fires non-authenticated AJAX actions for logged-out users.
             *
             * The dynamic portion of the hook name, `$action`, refers to the name
             * of the AJAX action callback being fired.
             *
             * @since 2.8.0
             */
            do_action("todo_ajax_nopriv_{$action}");
        }
        
        todo_die('0');
    }
}

/**
 * Fires as an admin screen or script is being initialized.
 *
 * Note, this does not just run on user-facing admin screens.
 * It runs on admin-ajax.php and admin-post.php as well.
 *
 * This is roughly analogous to the more general {@see 'init'} hook, which fires earlier.
 *
 * @since 2.5.0
 */
do_action('admin_init');

if (isset($plugin_page)) {
    if (!empty($typenow)) {
        $the_parent = $pagenow . '?post_type=' . $typenow;
    } else {
        $the_parent = $pagenow;
    }
    
    $page_hook = get_plugin_page_hook($plugin_page, $the_parent);
    if (!$page_hook) {
        $page_hook = get_plugin_page_hook($plugin_page, $plugin_page);
        
        // Back-compat for plugins using add_management_page().
        if (empty($page_hook) && 'edit.php' == $pagenow && '' != get_plugin_page_hook($plugin_page, 'tools.php')) {
            // There could be plugin specific params on the URL, so we need the whole query string
            if (!empty($_SERVER['QUERY_STRING'])) {
                $query_string = $_SERVER['QUERY_STRING'];
            } else {
                $query_string = 'page=' . $plugin_page;
            }
            todo_redirect(todo_admin_url('tools.php?' . $query_string));
            exit;
        }
    }
    unset($the_parent);
}

$hook_suffix = '';
if (isset($page_hook)) {
    $hook_suffix = $page_hook;
} elseif (isset($plugin_page)) {
    $hook_suffix = $plugin_page;
} elseif (isset($pagenow)) {
    $hook_suffix = $pagenow;
}

set_current_screen();

// Handle plugin admin pages.
if (isset($plugin_page)) {
    if ($page_hook) {
        /**
         * Fires before a particular screen is loaded.
         *
         * The load-* hook fires in a number of contexts. This hook is for plugin screens
         * where a callback is provided when the screen is registered.
         *
         * The dynamic portion of the hook name, `$page_hook`, refers to a mixture of plugin
         * page information including:
         * 1. The page type. If the plugin page is registered as a submenu page, such as for
         *    Settings, the page type would be 'settings'. Otherwise the type is 'toplevel'.
         * 2. A separator of '_page_'.
         * 3. The plugin basename minus the file extension.
         *
         * Together, the three parts form the `$page_hook`. Citing the example above,
         * the hook name used would be 'load-settings_page_pluginbasename'.
         *
         * @see get_plugin_page_hook()
         *
         * @since 2.1.0
         */
        do_action("load-{$page_hook}");
        if (!isset($_GET['noheader'])) {
            require_once(ADMIN_DIR . '/admin-header.php');
        }
        
        /**
         * Used to call the registered callback for a plugin screen.
         */
        do_action($page_hook);
    } else {
        if (validate_file($plugin_page)) {
            todo_die(__('Invalid plugin page.'));
        }
        
        if (!(file_exists(TODO_PLUGIN_DIR . "/$plugin_page") && is_file(TODO_PLUGIN_DIR . "/$plugin_page")) && !(file_exists(TODOMU_PLUGIN_DIR . "/$plugin_page") && is_file(TODOMU_PLUGIN_DIR . "/$plugin_page"))) {
            todo_die(sprintf(__('Cannot load %s.'), htmlentities($plugin_page)));
        }
        
        /**
         * Fires before a particular screen is loaded.
         *
         * The load-* hook fires in a number of contexts. This hook is for plugin screens
         * where the file to load is directly included, rather than the use of a function.
         *
         * The dynamic portion of the hook name, `$plugin_page`, refers to the plugin basename.
         *
         * @see plugin_basename()
         *
         * @since 1.5.0
         */
        do_action("load-{$plugin_page}");
        
        if (!isset($_GET['noheader'])) {
            require_once(ADMIN_DIR . '/admin-header.php');
        }
        
        if (file_exists(TODOMU_PLUGIN_DIR . "/$plugin_page")) {
            include(TODOMU_PLUGIN_DIR . "/$plugin_page");
        } else {
            include(TODO_PLUGIN_DIR . "/$plugin_page");
        }
    }
    
    include(ADMIN_DIR . '/admin-footer.php');
    
    exit();
} elseif (isset($_GET['import'])) {
    $importer = $_GET['import'];
    
    if (!current_user_can('import')) {
        todo_die(__('Sorry, you are not allowed to import content into this site.'));
    }
    
    if (validate_file($importer)) {
        todo_redirect(todo_admin_url('import.php'));
        exit;
    }
    
    if (!isset($todo_importers[$importer]) || !is_callable($todo_importers[$importer][2])) {
        todo_redirect(todo_admin_url('import.php'));
        exit;
    }
    
    /**
     * Fires before an importer screen is loaded.
     *
     * The dynamic portion of the hook name, `$importer`, refers to the importer slug.
     *
     * @since 3.5.0
     */
    do_action("load-importer-{$importer}");
    
    $parent_file = 'tools.php';
    $submenu_file = 'import.php';
    $title = __('Import');
    
    if (!isset($_GET['noheader'])) {
        require_once(ADMIN_DIR . '/admin-header.php');
    }
    
    $profiling = false;
    if (TODO_DEBUG && TODO_DEBUG_LOG) {
        $profiling = true;
        $fp = fopen(ABSPATH . '/logs/debug.log', 'a');
    }
    
    if ($profiling) {
        fwrite($fp, current_time('mysql') . " $importer Import\n");
    }
    
    call_user_func($todo_importers[$importer][2]);
    
    if ($profiling) {
        fwrite($fp, current_time('mysql') . " $importer Import Done\n");
        fclose($fp);
    }
    
    include(ADMIN_DIR . '/admin-footer.php');
    
    // Make sure rules are flushed
    flush_rewrite_rules(false);
    
    exit();
} else {
    /**
     * Fires before a particular screen is loaded.
     *
     * The load-* hook fires in a number of contexts. This hook is for core screens.
     *
     * The dynamic portion of the hook name, `$pagenow`, is a global variable
     * referring to the filename of the current screen, such as 'admin.php',
     * 'post-new.php' and so on. A complete hook for the latter would be
     * 'load-post-new.php'.
     *
     * @since 2.1.0
     */
    do_action("load-{$pagenow}");
    
    /*
     * The following hooks are fired to ensure backward compatibility.
     * In all other cases, 'load-' . $pagenow should be used instead.
     */
    if ($pagenow == 'edit-tags.php') {
        if ($taxnow == 'category') {
            do_action('load-categories.php');
        } elseif ($taxnow == 'link_category') {
            do_action('load-edit-link-categories.php');
        }
    } elseif ($pagenow == 'term.php') {
        do_action('load-edit-tags.php');
    }
}

if (!empty($_REQUEST['action'])) {
    $action = $_REQUEST['action'];
    
    /**
     * Fires when an 'action' request variable is sent.
     *
     * The dynamic portion of the hook name, `$action`, refers to
     * the action derived from the `GET` or `POST` request.
     *
     * @since 2.6.0
     */
    do_action("admin_action_{$action}");
}
