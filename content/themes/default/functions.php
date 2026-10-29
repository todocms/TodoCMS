<?php
/**
 * Default Theme Functions
 *
 * 【系统默认主题】由TodoCMS官方维护，更新会被覆盖；仅供主题开发时的参考，请勿用于正式环境。
 *
 * @package TodoCMS
 */

// 防止直接访问（但允许AJAX请求）
if (!defined('ABSPATH') && !defined('DOING_AJAX')) {
    exit('Direct access denied');
}

if (!function_exists('todo_theme_enqueue_assets')) {
    /**
     * 将主题的样式与脚本通过统一队列输出
     */
    function todo_theme_enqueue_assets()
    {
        if (!function_exists('todo_enqueue_style') || !function_exists('todo_enqueue_script')) {
            return;
        }

        $theme_url = get_template_directory_uri();
        $public_url = rtrim(todo_get_site_url('public'), '/');

        // 样式（头部输出）
        todo_enqueue_style('bootstrap', $public_url . '/static/common/css/bootstrap.min.css', [], '3.6.0', 'all');
        todo_enqueue_style('todo-iconfont', $public_url . '/static/common/css/iconfont.css', [], '4.7.0', 'all');
        todo_enqueue_style('theme-style', $theme_url . '/assets/css/style.css', [], '1.0.0', 'all');
        todo_enqueue_style('hero-slider', $theme_url . '/assets/css/hero-slider.css', [], '1.0.0', 'all');

        // 脚本（统一页脚输出，避免阻塞头部关键渲染路径，提升 FCP / LCP 指标）
        todo_enqueue_script('todo-csrf-manager', $theme_url . '/assets/js/csrf-manager.js', [], '1.0.0', true);
        todo_enqueue_script('todo-submit-guard', $theme_url . '/assets/js/submit-guard.js', [], '1.0.0', true);
        todo_enqueue_script('jquery', $theme_url . '/assets/js/jquery-3.6.0.min.js', [], '3.6.0', true);
        todo_enqueue_script('motion', $theme_url . '/assets/js/motion.min.js', [], '10.18.0', true);
        todo_enqueue_script('motion-helper', $theme_url . '/assets/js/motion-helper.js', ['motion'], '1.0.0', true);
        todo_enqueue_script('layer', $public_url . '/plugins/layer-v3.5.1/layer.js', ['jquery'], '3.5.1', true);
        todo_enqueue_script('hero-slider', $theme_url . '/assets/js/hero-slider.js', ['jquery'], '1.0.0', true);
        todo_enqueue_script('theme-js', $theme_url . '/assets/js/theme.js', ['jquery', 'motion'], '1.0.0', true);
        todo_enqueue_script('sidebar-js', $theme_url . '/assets/js/sidebar.js', ['jquery'], '1.0.0', true);
        todo_enqueue_script('ajax-pager', $theme_url . '/assets/js/ajax-pager.js', [], '1.0.0', true);
    }
}
// 注册钩子：在模板 {{todo:todo_head}} 触发的队列阶段入队
add_action('todo_enqueue_scripts', 'todo_theme_enqueue_assets');

if (!function_exists('todo_theme_preload_assets')) {
    // 在 <head> 中预加载关键字体图标资源，避免被页面内阻塞的图片延迟
    function todo_theme_preload_assets()
    {
        $public_url = rtrim(todo_get_site_url('public'), '/');
        $font_url = $public_url . '/static/common/css/iconfont.woff2?t=1783316542005';
        echo '<link rel="preload" href="' . esc_url($font_url) . '" as="font" type="font/woff2" crossorigin="anonymous">' . "\n";
    }
}
add_action('todo_head', 'todo_theme_preload_assets');
