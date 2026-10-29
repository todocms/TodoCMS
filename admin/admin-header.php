<?php

/**
 * TodoCMS Administration Template Header
 *
 * @package TodoCMS
 * @subpackage Administration
 */

@header('Content-Type: ' . get_option('html_type') . '; charset=' . get_option('blog_charset'));
if (!defined('TODO_ADMIN')) {
    require_once(dirname(__FILE__) . '/admin.php');
}

/**
 * In case admin-header.php is included in a function.
 *
 * @global string    $title
 * @global string    $hook_suffix
 * @global TODO_Screen $current_screen
 * @global CMS_User    $current_user
 * @global string    $parent_file
 * @global string    $submenu_file
 * @global string    $pagenow
 * @global string    $typenow
 * @global string    $plugin_page
 */
global $title, $hook_suffix, $current_screen, $current_user, $parent_file, $submenu_file, $pagenow, $typenow, $plugin_page, $todo_version, $todo_locale;

// 初始化版本变量
if (!isset($todo_version)) {
    $todo_version = defined('TODO_VERSION') ? TODO_VERSION : '1.0.0';
}

// 初始化本地化变量
if (!isset($todo_locale)) {
    $todo_locale = (object) array(
        'number_format' => array(
            'thousands_sep' => ',',
            'decimal_point' => '.'
        )
    );
}

// Catch plugins that include admin-header.php before admin.php completes.
if (empty($current_screen)) {
    set_current_screen();
}

get_current_screen()->set_parentage($parent_file);

$title = esc_html(strip_tags($title ?? ''));

if (is_network_admin()) {
    /* translators: Network admin screen title. 1: Network title, 2: Current admin page title. */
    $admin_title = sprintf(__('Network Admin: %1$s — %2$s'), get_network()->site_name, $title);
} elseif (is_user_admin()) {
    /* translators: User dashboard screen title. 1: Network title, 2: Current admin page title. */
    $admin_title = sprintf(__('User Dashboard: %1$s — %2$s'), get_network()->site_name, $title);
} else {
    /* translators: Admin screen title. 1: Admin page title, 2: Network or site title. */
    $admin_title = sprintf(__('%1$s ‹ %2$s — TodoCMS'), $title, get_option('site_title', 'TodoCMS'));
}

/**
 * Filters the title tag content for an admin page.
 *
 * @since 3.1.0
 *
 * @param string $admin_title The page title, with extra context added.
 * @param string $title       The original page title.
 */
$admin_title = apply_filters('admin_title', $admin_title, $title);

todo_user_settings();

todo_admin_html_begin();
?>
<title><?php echo $admin_title; ?></title>
<?php
todo_enqueue_style('colors');
todo_enqueue_style('ie');
todo_enqueue_script('todo-modal');
todo_enqueue_script('utils');
todo_enqueue_script('todo-request');
todo_enqueue_script('svg-painter');

$hook_suffix_safe = is_string($hook_suffix) ? $hook_suffix : '';
$admin_body_class = preg_replace('/[^a-z0-9_-]+/i', '-', $hook_suffix_safe);
?>
<script type="text/javascript">
addLoadEvent = function(func){if(typeof jQuery!="undefined")jQuery(document).ready(func);else if(typeof todoOnload!='function'){todoOnload=func;}else{var oldonload=todoOnload;todoOnload=function(){oldonload();func();}}};
var ajaxurl = '<?php echo todo_admin_url('admin-ajax.php'); ?>',
    pagenow = '<?php echo $current_screen->id; ?>',
    typenow = '<?php echo $current_screen->post_type; ?>',
    adminpage = '<?php echo $admin_body_class; ?>',
    thousandsSeparator = '<?php echo addslashes($todo_locale->number_format['thousands_sep']); ?>',
    decimalPoint = '<?php echo addslashes($todo_locale->number_format['decimal_point']); ?>',
    isRtl = <?php echo (int) is_rtl(); ?>;
</script>
<?php
/**
 * Enqueue scripts for all admin pages.
 *
 * @since 2.8.0
 *
 * @param string $hook_suffix The current admin page.
 */
do_action('admin_enqueue_scripts', $hook_suffix);

/**
 * Fires when styles are printed for a specific admin page based on $hook_suffix.
 *
 * @since 2.6.0
 */
do_action("admin_print_styles-{$hook_suffix}");

/**
 * Fires when styles are printed for all admin pages.
 *
 * @since 2.6.0
 */
do_action('admin_print_styles');

/**
 * Fires when scripts are printed for a specific admin page based on $hook_suffix.
 *
 * @since 2.1.0
 */
do_action("admin_print_scripts-{$hook_suffix}");

/**
 * Fires when scripts are printed for all admin pages.
 *
 * @since 2.1.0
 */
do_action('admin_print_scripts');

/**
 * Fires in head section for a specific admin page.
 *
 * The dynamic portion of the hook, `$hook_suffix`, refers to the hook suffix
 * for the admin page.
 *
 * @since 2.1.0
 */
do_action("admin_head-{$hook_suffix}");

/**
 * Fires in head section for all admin pages.
 *
 * @since 2.1.0
 */
do_action('admin_head');

if (get_user_setting('mfold') == 'f') {
    $admin_body_class .= ' folded';
}

if (!get_user_setting('unfold')) {
    $admin_body_class .= ' auto-fold';
}

if (is_admin_bar_showing()) {
    $admin_body_class .= ' admin-bar';
}

if (is_rtl()) {
    $admin_body_class .= ' rtl';
}

if ($current_screen->post_type) {
    $admin_body_class .= ' post-type-' . $current_screen->post_type;
}

if ($current_screen->taxonomy) {
    $admin_body_class .= ' taxonomy-' . $current_screen->taxonomy;
}

$admin_body_class .= ' branch-' . str_replace(array('.', ','), '-', floatval($todo_version));
$admin_body_class .= ' version-' . str_replace('.', '-', preg_replace('/^([.0-9]+).*/', '\1', $todo_version));
$admin_body_class .= ' admin-color-' . sanitize_html_class(get_user_option('admin_color'), 'fresh');
$admin_body_class .= ' locale-' . sanitize_html_class(strtolower(str_replace('_', '-', get_user_locale())));

if (todo_is_mobile()) {
    $admin_body_class .= ' mobile';
}

if (is_multisite()) {
    $admin_body_class .= ' multisite';
}

if (is_network_admin()) {
    $admin_body_class .= ' network-admin';
}

$admin_body_class .= ' no-customize-support';

if ($current_screen->is_block_editor()) {
    $admin_body_class .= ' block-editor-page';
}

/**
 * Filters the CSS classes for the body tag in the admin.
 *
 * This filter differs from the {@see 'post_class'} and {@see 'body_class'} filters
 * in that it only fires on admin pages, and the parameter and return value are strings
 * rather than arrays.
 *
 * @since 2.3.0
 *
 * @param string $classes Space-separated list of CSS classes.
 */
$admin_body_classes = apply_filters('admin_body_class', '');
$admin_body_classes = ltrim($admin_body_classes . ' ' . $admin_body_class);

?>
</head>
<?php
/**
 * @global string $body_id
 */
if (isset($GLOBALS['body_id'])) {
    echo '<body id="' . $GLOBALS['body_id'] . '" class="todo-admin todo-core-ui ' . $admin_body_classes . '">';
} else {
    echo '<body class="todo-admin todo-core-ui ' . $admin_body_classes . '">';
}

/**
 * Fires early before the admin menu loads.
 *
 * This hook fires before any admin page is loaded and before the admin menu loads,
 * so it can be used to add items to the admin menu.
 *
 *
 * @since 2.3.0
 */
do_action('_admin_menu');

/**
 * Fires before the administration menu loads in the admin.
 *
 * The hook fires before the menu and submenu items are created.
 *
 * @since 2.3.0
 */
do_action('admin_menu');

// Handle plugin admin pages.
if (isset($plugin_page)) {
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
    do_action("load-{$plugin_page}");
}

/**
 * Fires when the admin header is loaded.
 *
 * @since 2.3.0
 */
do_action('admin_header');

unset($title_class, $blog_name, $total_update_count, $update_title);

$current_screen->set_parentage($parent_file);

?>

<div id="todocontent">

<?php
if (function_exists('todo_sites_table_exists') && todo_sites_table_exists() && function_exists('is_user_logged_in') && is_user_logged_in()) {
    $site_ids = function_exists('todo_get_user_site_ids') ? todo_get_user_site_ids() : array();
    $current_site_id = function_exists('todo_current_site_id') ? todo_current_site_id(true) : 0;
    if (is_array($site_ids) && count($site_ids) > 0) {
        global $tododb;
        $sites_map = array();
        if ($tododb) {
            $table = $tododb->prefix . 'sites';
            $placeholders = implode(',', array_fill(0, count($site_ids), '?'));
            $rows = $tododb->get_results("SELECT id, name, slug FROM {$table} WHERE id IN ({$placeholders}) ORDER BY is_default DESC, id ASC", $site_ids);
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    if (is_object($r) && isset($r->id)) {
                        $sid = (int) $r->id;
                        $label = isset($r->name) && $r->name !== '' ? (string) $r->name : ('Site #' . $sid);
                        if (isset($r->slug) && $r->slug !== '') {
                            $label .= ' (' . (string) $r->slug . ')';
                        }
                        $sites_map[$sid] = $label;
                    }
                }
            }
        }
        if (!empty($sites_map) && count($sites_map) > 1) {
            $base_path = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
            $base_path = explode('?', $base_path, 2)[0];
            $params = $_GET;
            if (is_array($params)) {
                unset($params['site']);
            } else {
                $params = array();
            }
            ?>
            <form method="get" action="<?php echo esc_attr($base_path); ?>" style="padding:8px 12px;border-bottom:1px solid #e5e5e5;background:#fff;display:flex;align-items:center;gap:8px;">
                <span><?php echo esc_html__('门店', 'todocms'); ?></span>
                <select name="site" onchange="this.form.submit()" style="min-width:220px;">
                    <?php foreach ($sites_map as $sid => $label) : ?>
                        <option value="<?php echo esc_attr((string) $sid); ?>" <?php selected((int) $sid, (int) $current_site_id); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php
                foreach ($params as $k => $v) {
                    if (!is_string($k) || $k === '') {
                        continue;
                    }
                    if (is_array($v)) {
                        continue;
                    }
                    echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr((string) $v) . '">';
                }
                ?>
                <noscript><button type="submit" class="button"><?php echo esc_html__('切换', 'todocms'); ?></button></noscript>
            </form>
            <?php
        }
    }
}
?>

<?php
/**
 * Fires at the beginning of the content section in an admin page.
 *
 * @since 3.0.0
 */
do_action('in_admin_header');
?>

<div id="todobody" role="main">
<?php
unset($title_class, $blog_name, $total_update_count, $update_title);

/**
 * Fires near the top of the admin page, before the screen reader text.
 *
 * @since 2.5.0
 */
do_action('admin_notices');

/**
 * Prints admin screen notices.
 *
 * @since 3.1.0
 */
do_action('all_admin_notices');

if ($parent_file == 'options-general.php') {
    require(ADMIN_DIR . '/options-head.php');
}
