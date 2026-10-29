<?php
/**
 * TodoCMS Administration Template Footer
 *
 * @package TodoCMS
 * @subpackage Administration
 */

// Don't load directly
if (!defined('ABSPATH')) {
    die('-1');
}

/**
 * @global string $hook_suffix
 */
global $hook_suffix;
?>
<div class="clear"></div>
</div><!-- todobody -->
<div class="clear"></div>
</div><!-- todocontent -->

<div id="todofooter" role="contentinfo">
<?php
    /**
     * Fires after the opening tag for the admin footer.
     *
     * @since 2.5.0
     */
    do_action('in_admin_footer');
    ?>
    <p id="footer-left" class="alignleft">
        <?php
        $text = sprintf(
            /* translators: 1: TodoCMS version number. */
            __('Version %s'),
            function_exists('get_version') ? get_version() : (defined('TODO_VERSION') ? TODO_VERSION : '1.0.0')
        );
        
        /**
         * Filters the "Thank you" text displayed in the admin footer.
         *
         * @since 2.8.0
         *
         * @param string $text The content that will be printed.
         */
        echo apply_filters('admin_footer_text', '<span id="footer-thankyou">' . __('Thank you for creating with <a href="http://todocms.com/">TodoCMS</a>.') . '</span>');
        ?>
    </p>
    <p id="footer-upgrade" class="alignright">
        <?php
        /**
         * Filters the version/update text displayed in the admin footer.
         *
         * TodoCMS prints the current version and update information,
         * using core_update_footer() at priority 10.
         *
         * @since 2.3.0
         *
         * @see core_update_footer()
         *
         * @param string $content The content that will be printed.
         */
        echo apply_filters('update_footer', '');
        ?>
    </p>
    <div class="clear"></div>
</div>
<?php
/**
 * Prints scripts or data before the closing body tag on the admin.
 *
 * @since 2.1.0
 *
 * @param string $hook_suffix The current admin page.
 */
do_action('admin_print_footer_scripts-' . $hook_suffix);

/**
 * Prints scripts or data before the closing body tag on the admin.
 *
 * @since 2.1.0
 */
do_action('admin_print_footer_scripts');

/**
 * Prints scripts or data before the closing body tag on the admin.
 *
 * @since 2.8.0
 */
do_action('admin_footer-' . $hook_suffix);

/**
 * Fires in the admin footer.
 *
 * @since 2.1.0
 */
do_action('admin_footer');

/**
 * TodoCMS admin footer hooks
 * 执行TodoCMS自定义的footer钩子
 */
if (function_exists('todo_do_admin_action')) {
    todo_do_admin_action('footer');
}

// 输出footer脚本
if (function_exists('todo_print_scripts')) {
    todo_print_scripts(true);
}
?>

</body>
</html>