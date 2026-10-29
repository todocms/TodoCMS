<?php
/**
 * 主题相关函数
 *
 * 包含主题管理和使用的函数
 *
 * @package TodoCMS
 * @version 1.0.0
 */

if (!function_exists('get_bloginfo')) {
    /**
     * 获取博客信息
     *
     * @param string $show 要显示的信息类型
     * @param string $filter 过滤器
     * @return string 博客信息
     */
    function get_bloginfo($show = '', $filter = 'display')
    {
        $output = '';

        switch ($show) {
            case 'name':
            case 'title':
                $output = todo_get_option('site_title', 'TodoCMS');
                break;
            case 'description':
                $output = todo_get_option('site_description', '');
                break;
            case 'url':
                $output = todo_url();
                break;
            case 'charset':
                $output = 'UTF-8';
                break;
            case 'html_type':
                $output = 'text/html';
                break;
            case 'language':
                $output = get_current_language();
                break;
            case 'version':
                $output = get_version();
                break;
            default:
                $output = '';
        }

        return apply_filters('bloginfo', $output, $show);
    }
}

/**
 * 输出博客信息
 *
 * @param string $show 要显示的信息类型
 * @param string $filter 过滤器
 * @return string|void 如果$filter为'raw'则返回信息，否则直接输出
 */
function bloginfo($show = '', $filter = 'display')
{
    $output = get_bloginfo($show, $filter);

    if ($filter === 'raw') {
        return $output;
    }

    echo $output;
}

/**
 * 获取版本信息
 *
 * @return string 版本号
 */
function get_version()
{
    if (function_exists('todo_get_cms_version')) {
        return todo_get_cms_version();
    }
    return defined('TODO_VERSION') ? TODO_VERSION : '1.0.0';
}

/**
 * 输出body标签的class属性
 *
 * @param array $class 额外的class数组
 */
function body_class($class = array())
{
    // 确保$class是数组
    if (!is_array($class)) {
        $class = explode(' ', $class);
    }

    // 添加默认class
    $classes = array('cms-site');

    // 根据当前页面类型添加class
    $page_type = get_current_page_type();
    $classes[] = 'page-' . $page_type;

    // 合并自定义class
    $classes = array_merge($classes, $class);

    // 应用过滤器
    $classes = apply_filters('body_class', $classes);

    // 输出class属性
    echo 'class="' . esc_attr(implode(' ', $classes)) . '"';
}

/**
 * 在<body>标签后执行操作的钩子
 */
function todo_body_open()
{
    do_action('todo_body_open');
}

/**
 * 在</head>标签前执行操作的钩子
 */
function todo_head()
{
    do_action('todo_head');
}

/**
 * 获取结构化网站Logo（支持多语言过滤）
 *
 * @param string|null $lang 语言代码
 * @return array
 */
function todo_get_site_logos($lang = null)
{
    $raw = function_exists('todo_get_option_ml') ? todo_get_option_ml('site_logo', todo_get_option('site_logo', '')) : todo_get_option('site_logo', '');
    if (empty($raw) && function_exists('get_option')) {
        $raw = get_option('site_logo', '');
    }

    $images = array();
    if (function_exists('todo_filter_featured_images_by_lang') && !empty($raw)) {
        $images = todo_filter_featured_images_by_lang($raw, $lang);
    } else {
        if (is_array($raw)) {
            $images = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            if (strpos($raw, '[{') === 0 || strpos($raw, '{') === 0) {
                $decoded = json_decode($raw, true);
                $images = is_array($decoded) ? $decoded : array(array('url' => $raw));
            } else {
                $images = array(array('url' => $raw));
            }
        }
    }

    $result = array(
        'primary' => isset($images[0]) ? $images[0] : null,
        'secondary' => isset($images[1]) ? $images[1] : null,
        'others' => count($images) > 2 ? array_slice($images, 2) : array()
    );

    return $result;
}

/**
 * 显示侧边栏内容
 *
 * @param string $sidebar_id 侧边栏ID
 * @return void
 */
function dynamic_sidebar($sidebar_id)
{
    if (has_action('dynamic_sidebar_' . $sidebar_id)) {
        do_action('dynamic_sidebar_' . $sidebar_id);
        return;
    }
    // 规范化输出真实侧边栏部件，避免输出 javascript:void(0) 死链与多余 H2
    $site_url = function_exists('todo_get_site_url') ? rtrim(todo_get_site_url(), '/') : '';
    echo '<div class="widget widget-recent-posts">';
    echo '<h3 class="widget-title">' . (function_exists('todo_translate') ? todo_translate('最新内容') : '最新内容') . '</h3>';
    if (function_exists('get_posts')) {
        $recent_posts = get_posts(['numberposts' => 5, 'post_status' => 'publish']);
        if (!empty($recent_posts)) {
            echo '<ul class="recent-posts-list">';
            foreach ($recent_posts as $rp) {
                $p_url = function_exists('get_permalink') ? get_permalink($rp->ID) : ($site_url . '/?p=' . $rp->ID);
                $p_title = esc_html($rp->post_title);
                echo '<li><a href="' . esc_url($p_url) . '">' . $p_title . '</a></li>';
            }
            echo '</ul>';
        }
    }
    echo '</div>';
}

/**
 * 输出页脚内容
 *
 * @return void
 */
function todo_footer()
{
    // 简化实现，输出一些页脚脚本或内容
    echo '<!-- 页脚脚本 -->';
    do_action('todo_footer');
}

/**
 * 检查是否在自定义预览模式
 *
 * @return bool 是否在自定义预览模式
 */
function is_customize_preview()
{
    // 简化实现，始终返回false
    return false;
}

/**
 * 显示导航菜单
 *
 * @param array $args 菜单参数
 * @return string|void
 */
function todo_nav_menu($args = array())
{
    // 设置默认参数
    $defaults = array(
        'theme_location' => '',
        'menu_id' => '',
        'container' => 'nav',
        'container_class' => '',
        'menu_class' => 'menu',
        'echo' => true,
        'fallback_cb' => 'todo_nav_menu_fallback'
    );

    $args = array_merge($defaults, $args);

    // 如果指定了theme_location，优先使用菜单管理器渲染
    if (!empty($args['theme_location'])) {
        // 1) 优先尝试数据库版菜单（class-menu-manager）但不使用其fallback，便于后续兼容旧存储
        if (class_exists('Todo_Menu_Manager')) {
            $args_no_fallback = $args;
            $args_no_fallback['fallback_cb'] = null; // 禁用内部fallback，避免挡住旧版选项式菜单
            $html = Todo_Menu_Manager::render_menu($args['theme_location'], $args_no_fallback);
            if (is_string($html) && $html !== '') {
                return $html;
            }
        }
    }

    // 3) 最终回退到默认菜单
    return todo_nav_menu_fallback($args);
}

/**
 * 默认菜单回退函数
 * 
 * @param array $args 菜单参数
 * @return string|void
 */
function todo_nav_menu_fallback($args = array())
{
    $output = '';

    // 容器开始标签
    if ($args['container']) {
        $output .= '<' . $args['container'];
        if (!empty($args['container_class'])) {
            $output .= ' class="' . esc_attr($args['container_class']) . '"';
        }
        $output .= '>';
    }

    // 菜单列表
    $output .= '<ul';
    if (!empty($args['menu_class'])) {
        $output .= ' class="' . esc_attr($args['menu_class']) . '"';
    }
    if (!empty($args['menu_id'])) {
        $output .= ' id="' . esc_attr($args['menu_id']) . '"';
    }
    $output .= '>';

    // 默认菜单项（国际化 + 语言前缀适配）
    $homeUrl = get_language_url(current_locale(), todo_home_url(''));
    $output .= '<li class="menu-item"><a href="' . esc_url($homeUrl) . '">' . esc_html(__('首页')) . '</a></li>';

    $output .= '</ul>';

    // 容器结束标签
    if ($args['container']) {
        $output .= '</' . $args['container'] . '>';
    }

    if (isset($args['echo']) && $args['echo']) {
        echo $output;
        return;
    }

    return $output;
}

/**
 * 获取所有主题
 *
 * @return array 主题列表
 */
function get_themes()
{
    return CMS_Theme::get_themes();
}

/**
 * 获取当前主题
 *
 * @return CMS_Theme 当前主题对象
 */
function get_current_theme()
{
    return CMS_Theme::get_active_theme();
}

/**
 * 激活主题
 *
 * @param string $theme_name 主题名称
 * @return bool|TODO_Error 成功返回true，失败返回错误对象
 */
function activate_theme($theme_name)
{
    if (empty($theme_name)) {
        return new TODO_Error('invalid_theme', '无效的主题名称');
    }

    // 检查主题是否存在
    $theme_dir = ABSPATH . THEMES_DIR . '/' . $theme_name;

    if (!is_dir($theme_dir)) {
        return new TODO_Error('theme_not_found', '主题不存在: ' . $theme_dir);
    }

    // 检查主题信息文件是否存在（支持根目录和pc目录）
    $theme_info_file = null;
    $possible_paths = array(
        $theme_dir . '/theme.json',           // 主题根目录
        $theme_dir . '/pc/theme.json',        // PC目录（支持PC/移动端分离结构）
        $theme_dir . '/mobile/theme.json'      // 移动端目录（备用）
    );
    
    foreach ($possible_paths as $path) {
        if (file_exists($path)) {
            $theme_info_file = $path;
            break;
        }
    }
    
    if (!$theme_info_file) {
        return new TODO_Error('invalid_theme', '无效的主题格式: 未找到 theme.json 文件');
    }

    // 不再写入 system_config 或 options 表中的 active_theme，统一以 themes 表为准
    // 激活主题状态由数据库更新保证，一切读取请使用 CMS_Theme::get_active_theme() 或 get_theme_name()

    // 确保数据库中的主题状态一致
    global $tododb;
    if ($tododb) {
        // 重置所有主题状态
        $tododb->update(DB_PREFIX . 'themes', ['status' => 'inactive'], ['status' => 'active']);

        // 检查主题是否已存在于数据库中
        $existing_theme = $tododb->get_row(
            "SELECT * FROM " . DB_PREFIX . "themes WHERE directory = ?",
            array($theme_name)
        );

        if ($existing_theme) {
            // 如果主题已存在，更新状态为激活
            $tododb->update(DB_PREFIX . 'themes', ['status' => 'active'], ['directory' => $theme_name]);
        } else {
            // 如果主题不存在，读取主题信息并插入
            $theme_info = json_decode(file_get_contents($theme_info_file), true);
            if ($theme_info) {
                $theme_data = [
                    'name' => isset($theme_info['title']) ? $theme_info['title'] : $theme_name,
                    'directory' => $theme_name,
                    'description' => isset($theme_info['description']) ? $theme_info['description'] : '',
                    'version' => isset($theme_info['version']) ? $theme_info['version'] : '1.0.0',
                    'author' => isset($theme_info['author']) ? $theme_info['author'] : '',
                    'template_dir' => $theme_name,
                    'status' => 'active',
                    'is_default' => 0,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ];
                $tododb->insert(DB_PREFIX . 'themes', $theme_data);
            } else {
                // 如果无法读取主题信息，使用默认值插入
                $theme_data = [
                    'name' => $theme_name,
                    'directory' => $theme_name,
                    'description' => '主题描述',
                    'version' => '1.0.0',
                    'author' => 'TodoCMS',
                    'template_dir' => $theme_name,
                    'status' => 'active',
                    'is_default' => 0,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ];
                $tododb->insert(DB_PREFIX . 'themes', $theme_data);
            }
        }
    }

    // 重置主题缓存，确保后续调用获取到最新激活的主题
    if (class_exists('CMS_Theme') && method_exists('CMS_Theme', 'reset_active_theme_cache')) {
        CMS_Theme::reset_active_theme_cache();
    }

    // 切换主题后自动清理所有前端缓存（页面缓存、模板缓存、对象缓存等）
    // 避免旧主题的缓存内容继续被访客看到
    if (class_exists('Todo_Unified_Cache_Manager')) {
        Todo_Unified_Cache_Manager::clear_all_caches();
    }

    // 执行主题激活钩子
    do_action('activate_theme', $theme_name);

    return true;
}

/**
 * 停用主题
 *
 * @param string $theme_name 主题名称
 * @return bool 是否成功停用
 */
function deactivate_theme($theme_name)
{
    // 不允许停用主题，必须有一个激活的主题
    return false;
}

/**
 * 删除主题
 *
 * @param string $theme_name 主题名称
 * @return bool|TODO_Error 成功返回true，失败返回错误对象
 */
function delete_theme($theme_name)
{
    if (empty($theme_name)) {
        return new TODO_Error('invalid_theme', '无效的主题名称');
    }

    // 检查主题是否为当前激活的主题
    $active_theme = get_theme_name();
    if ($active_theme === $theme_name) {
        return new TODO_Error('active_theme', '无法删除当前激活的主题');
    }

    // 检查主题是否存在
    $theme_dir = ABSPATH . THEMES_DIR . '/' . $theme_name;
    if (!is_dir($theme_dir)) {
        return new TODO_Error('theme_not_found', '主题不存在');
    }

    // 执行主题删除钩子
    do_action('delete_theme', $theme_name);

    // 删除主题目录
    return delete_directory($theme_dir);
}

/**
 * 检查主题是否存在
 *
 * @param string $theme_name 主题名称
 * @return bool 主题是否存在
 */
function theme_exists($theme_name)
{
    $theme_dir = ABSPATH . THEMES_DIR . '/' . $theme_name;
    return is_dir($theme_dir);
}

/**
 * 获取主题数据
 *
 * @param string $theme_name 主题名称
 * @return array|false 主题数据，失败返回false
 */
function get_theme_data($theme_name)
{
    $theme = new CMS_Theme($theme_name);
    return $theme->get_data();
}

/**
 * 获取主题截图URL
 *
 * @param string $theme_name 主题名称
 * @return string 截图URL
 */
function get_theme_screenshot_url($theme_name)
{
    $theme_dir = ABSPATH . THEMES_DIR . '/' . $theme_name;
    $theme_url = THEMES_URL . '/' . $theme_name;

    // 检查不同格式的截图文件
    $formats = array('png', 'jpg', 'jpeg', 'gif');

    foreach ($formats as $format) {
        $screenshot = $theme_dir . '/screenshot.' . $format;
        if (file_exists($screenshot)) {
            return $theme_url . '/screenshot.' . $format;
        }
    }

    // 返回默认截图
    return THEMES_URL . '/default/screenshot.png';
}

/**
 * 获取主题选项
 *
 * @param string $theme_name 主题名称
 * @param string $option 选项名称
 * @param mixed $default 默认值
 * @return mixed 选项值
 */
function get_theme_option($theme_name, $option, $default = null)
{
    $theme = new CMS_Theme($theme_name);
    return $theme->get_theme_option($option, $default);
}

/**
 * 更新主题选项
 *
 * @param string $theme_name 主题名称
 * @param string $option 选项名称
 * @param mixed $value 选项值
 * @return bool 是否成功更新
 */
function update_theme_option($theme_name, $option, $value)
{
    $theme = new CMS_Theme($theme_name);
    return $theme->update_theme_option($option, $value);
}

/**
 * 删除主题选项
 *
 * @param string $theme_name 主题名称
 * @param string $option 选项名称
 * @return bool 是否成功删除
 */
function delete_theme_option($theme_name, $option)
{
    $theme = new CMS_Theme($theme_name);
    return $theme->delete_theme_option($option);
}

/**
 * 获取主题模板文件列表
 *
 * @param string $theme_name 主题名称
 * @return array 模板文件列表
 */
function get_theme_templates($theme_name)
{
    $theme_dir = ABSPATH . THEMES_DIR . '/' . $theme_name . '/templates';
    $templates = array();

    if (is_dir($theme_dir)) {
        $files = scandir($theme_dir);

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (preg_match('/\.php$/', $file)) {
                $template_file = $theme_dir . '/' . $file;
                $template_data = get_file_data($template_file, array('Template Name' => 'Template Name'));

                if (!empty($template_data['Template Name'])) {
                    $templates[$file] = $template_data['Template Name'];
                }
            }
        }
    }

    return $templates;
}

/**
 * 从文件中获取数据
 *
 * @param string $file 文件路径
 * @param array $headers 头信息
 * @return array 文件数据
 */
function get_file_data($file, $headers)
{
    $file_data = array();

    if (file_exists($file)) {
        $content = file_get_contents($file);

        foreach ($headers as $field => $regex) {
            if (preg_match('/^[ \\t\\/*#@]*' . preg_quote($regex, '/') . ':(.*)$/mi', $content, $match) && $match[1]) {
                $file_data[$field] = trim(preg_replace('/\\s*(?:\\*\\/|\\?>).*/', '', $match[1]));
            } else {
                $file_data[$field] = '';
            }
        }
    }

    return $file_data;
}

/**
 * 递归删除目录
 *
 * @param string $dir 目录路径
 * @return bool 是否成功删除
 */
function delete_directory($dir)
{
    if (!is_dir($dir)) {
        return false;
    }

    $files = array_diff(scandir($dir), array('.', '..'));

    foreach ($files as $file) {
        $path = $dir . '/' . $file;

        if (is_dir($path)) {
            delete_directory($path);
        } else {
            unlink($path);
        }
    }

    return rmdir($dir);
}

/**
 * 样式和脚本管理
 */

// 全局变量存储已注册的样式和脚本
global $todo_styles, $todo_scripts;
$todo_styles = array();
$todo_scripts = array();

if (!function_exists('todo_enqueue_style')) {
    function todo_enqueue_style($handle, $src = '', $deps = array(), $ver = false, $media = 'all')
    {
        global $todo_styles;
        if (!isset($todo_styles)) {
            $todo_styles = array();
        }

        if (!empty($src)) {
            $todo_styles[$handle] = array(
                'src' => $src,
                'deps' => $deps,
                'ver' => $ver,
                'media' => $media,
                'enqueued' => true
            );
        } elseif (isset($todo_styles[$handle])) {
            $todo_styles[$handle]['enqueued'] = true;
        } else {
            return false;
        }

        return true;
    }
}

if (!function_exists('todo_enqueue_script')) {
    function todo_enqueue_script($handle, $src = '', $deps = array(), $ver = false, $in_footer = false)
    {
        global $todo_scripts;
        if (!isset($todo_scripts)) {
            $todo_scripts = array();
        }

        if (!empty($src)) {
            $todo_scripts[$handle] = array(
                'src' => $src,
                'deps' => $deps,
                'ver' => $ver,
                'in_footer' => $in_footer,
                'enqueued' => true
            );
        } elseif (isset($todo_scripts[$handle])) {
            $todo_scripts[$handle]['enqueued'] = true;
        } else {
            return false;
        }

        return true;
    }
}

/**
 * 输出已注册的样式文件
 */
if (!function_exists('todo_print_styles')) {
    function todo_print_styles()
    {
        global $todo_styles;

        foreach ($todo_styles as $handle => $style) {
            $src = isset($style['src']) ? $style['src'] : '';
            if (empty($src)) {
                continue;
            }
            if (function_exists('todo_media_url_to_runtime_absolute')) {
                $src = todo_media_url_to_runtime_absolute($src);
            }
            $ver = isset($style['ver']) && $style['ver'] ? '?ver=' . $style['ver'] : '';
            $media = isset($style['media']) ? $style['media'] : 'all';

            echo '<link rel="stylesheet" id="' . esc_attr($handle) . '-css" href="' . esc_url($src . $ver) . '" type="text/css" media="' . esc_attr($media) . '" />' . "\n";
        }
    }
}

/**
 * 输出已注册的脚本文件
 *
 * @param bool $in_footer 是否输出页脚脚本
 */
if (!function_exists('todo_print_scripts')) {
    function todo_print_scripts($in_footer = false)
    {
        global $todo_scripts;

        if (!isset($todo_scripts) || empty($todo_scripts)) {
            todo_print_localized_scripts($in_footer);
            return;
        }

        $sorted_scripts = array();
        $processed = array();

        $process_deps = null;
        $process_deps = function ($handle) use (&$todo_scripts, &$sorted_scripts, &$processed, &$process_deps) {
            if (isset($processed[$handle])) {
                return;
            }
            if (!isset($todo_scripts[$handle])) {
                return;
            }

            $script = $todo_scripts[$handle];
            if (!empty($script['deps']) && is_array($script['deps'])) {
                foreach ($script['deps'] as $dep) {
                    if (!isset($processed[$dep])) {
                        $process_deps($dep);
                    }
                }
            }

            $sorted_scripts[] = $handle;
            $processed[$handle] = true;
        };

        foreach ($todo_scripts as $handle => $script) {
            if (($script['in_footer'] ?? false) == $in_footer && !empty($script['enqueued'])) {
                $process_deps($handle);
            }
        }

        foreach ($sorted_scripts as $handle) {
            if (!isset($todo_scripts[$handle])) {
                continue;
            }
            $script = $todo_scripts[$handle];
            if (($script['in_footer'] ?? false) != $in_footer) {
                continue;
            }
            if (isset($script['printed']) && $script['printed']) {
                continue;
            }

            $src = $script['src'] ?? '';
            if ($src === '') {
                continue;
            }
            if (function_exists('todo_media_url_to_runtime_absolute')) {
                $src = todo_media_url_to_runtime_absolute($src);
            }

            $ver = !empty($script['ver']) ? '?ver=' . $script['ver'] : '';
            $defer_attr = (!$in_footer) ? ' defer="defer"' : '';
            echo '<script id="' . esc_attr($handle) . '-js" src="' . esc_url($src . $ver) . '" type="text/javascript"' . $defer_attr . '></script>' . "\r\n";

            $todo_scripts[$handle]['printed'] = true;
            $todo_scripts[$handle]['enqueued'] = false;
        }

        // 输出本地化脚本数据
        todo_print_localized_scripts($in_footer);
    }
}

/**
 * 在todo_head钩子中输出样式
 */
function todo_head_styles()
{
    todo_print_styles();
    todo_print_scripts(false); // 输出头部脚本
}

/**
 * 确保 Google Consent Mode v2 默认拒绝配置在 <head> 最早时机输出
 * 符合 Google 官方规范：gtag('consent', 'default') 必须在任何 gtag/GTM/GA4 代码前执行
 */
function todo_output_google_consent_mode_head_default()
{
    $cookie_consent_enabled = function_exists('get_option') ? get_option('cookie_consent_enabled', '0') : '0';
    if ($cookie_consent_enabled === '1') {
        $cookie_consent_google_mode_v2 = function_exists('get_option') ? get_option('cookie_consent_google_mode_v2', '1') : '1';
        if ($cookie_consent_google_mode_v2 === '1') {
            echo '<script id="todo-google-consent-mode-v2">
window.dataLayer = window.dataLayer || [];
if (typeof window.gtag !== "function") {
    window.gtag = function(){window.dataLayer.push(arguments);};
}
(function() {
    var hasDefaultConsent = false;
    for (var i = 0; i < window.dataLayer.length; i++) {
        var item = window.dataLayer[i];
        if (item && item[0] === "consent" && item[1] === "default") {
            hasDefaultConsent = true;
            break;
        }
    }
    if (!hasDefaultConsent) {
        window.gtag("consent", "default", {
            "ad_storage": "denied",
            "analytics_storage": "denied",
            "ad_user_data": "denied",
            "ad_personalization": "denied",
            "wait_for_update": 500
        });
    }
})();
</script>' . "\n";
        }
    }
}
add_action('todo_head', 'todo_output_google_consent_mode_head_default', 1);

function todo_get_async_cache_check_script_html($force = false)
{
    if (!$force && php_sapi_name() === 'cli') {
        return '';
    }

    $api_url = function_exists('todo_get_api_url')
        ? todo_get_api_url('cache/async-check')
        : '/api/cache/async-check';

    $html = '<script data-todo-async-cache-check="1">
(function() {
    var requestUrl = ' . json_encode($api_url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';
    var storageKey = "todo_async_cache_check_last_ts";
    var minInterval = 30000;
    var ua = (window.navigator && window.navigator.userAgent) ? window.navigator.userAgent : "";

    if (/Baiduspider|Googlebot|bingbot|Sogou|Yisou|360Spider|bot|crawler|spider/i.test(ua)) {
        return;
    }

    var sendCheck = function() {
        if (!window.fetch) {
            return;
        }

        try {
            var now = Date.now();
            var last = parseInt(window.localStorage ? (localStorage.getItem(storageKey) || "0") : "0", 10);
            if (last > 0 && (now - last) < minInterval) {
                return;
            }
            if (window.localStorage) {
                localStorage.setItem(storageKey, String(now));
            }
        } catch (e) {}

        fetch(requestUrl, {
            method: "POST",
            credentials: "same-origin",
            headers: { "X-Requested-With": "XMLHttpRequest" }
        }).catch(function() {});
    };

    if (window.requestIdleCallback) {
        requestIdleCallback(sendCheck);
    } else {
        setTimeout(sendCheck, 2000);
    }
})();
</script>' . "\n";

    // 欧盟 Cookie 合规 (GDPR / ePrivacy) 脚本同步注入（仅当后台开启时注入）
    $cookie_consent_enabled = function_exists('get_option') ? get_option('cookie_consent_enabled', '0') : '0';
    if ($cookie_consent_enabled === '1') {
        $cookie_consent_google_mode_v2 = function_exists('get_option') ? get_option('cookie_consent_google_mode_v2', '1') : '1';
        $cookie_consent_audit_log = function_exists('get_option') ? get_option('cookie_consent_audit_log', '1') : '1';

        $raw_btn_accept = get_option('cookie_consent_btn_accept');
        $raw_btn_reject = get_option('cookie_consent_btn_reject');

        $consent_btn_accept = function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($raw_btn_accept) : $raw_btn_accept;
        $consent_btn_reject = function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($raw_btn_reject) : $raw_btn_reject;

        $consent_btn_accept = $consent_btn_accept ?: 'Accept All';
        $consent_btn_reject = $consent_btn_reject ?: 'Reject Non-Essential';

        // 绑定文章 ID 获取标题与内容（自动继承 post 的多语言与 todo-language-switcher 切换）
        $consent_post_id = intval(get_option('cookie_consent_post_id', 0));
        $consent_title = 'We value your privacy';
        $consent_content = '<p>We use cookies to enhance your browsing experience, serve personalized ads or content, and analyze our traffic. By clicking "Accept All", you consent to our use of cookies.</p>';

        if ($consent_post_id > 0) {
            if (function_exists('todo_get_the_title')) {
                $custom_title = todo_get_the_title($consent_post_id);
                if (!empty($custom_title)) {
                    $consent_title = $custom_title;
                }
            }
            if (function_exists('todo_get_the_content')) {
                $custom_content = todo_get_the_content('', false, $consent_post_id);
                if (!empty($custom_content)) {
                    $consent_content = $custom_content;
                }
            } elseif (function_exists('get_post')) {
                $post = get_post($consent_post_id);
                if ($post && !empty($post->post_title)) {
                    $consent_title = $post->post_title;
                    $raw_content = htmlspecialchars_decode($post->post_content);
                    if (function_exists('todo_parse_post_special_tags')) {
                        $raw_content = todo_parse_post_special_tags($raw_content, $post);
                    }
                    $consent_content = $raw_content;
                }
            }
        }

        // 确保无论内容来源为何，若包含 {{todo: 则调用特殊标签解析
        if (is_string($consent_content) && strpos($consent_content, '{{todo:') !== false && function_exists('todo_parse_post_special_tags')) {
            $consent_content = todo_parse_post_special_tags($consent_content);
        }

        $consent_config = array(
            'title' => $consent_title,
            'content' => $consent_content,
            'btnAccept' => $consent_btn_accept,
            'btnReject' => $consent_btn_reject
        );

        // 如果开启了 Google Consent Mode v2，注入默认拒绝脚本（具有幂等防护，防止模板已手动写入导致重复插入）
        if ($cookie_consent_google_mode_v2 === '1') {
            $html .= '<script id="todo-google-consent-mode-v2">
window.dataLayer = window.dataLayer || [];
if (typeof window.gtag !== "function") {
    window.gtag = function(){window.dataLayer.push(arguments);};
}
(function() {
    var hasDefaultConsent = false;
    for (var i = 0; i < window.dataLayer.length; i++) {
        var item = window.dataLayer[i];
        if (item && item[0] === "consent" && item[1] === "default") {
            hasDefaultConsent = true;
            break;
        }
    }
    if (!hasDefaultConsent) {
        window.gtag("consent", "default", {
            "ad_storage": "denied",
            "analytics_storage": "denied",
            "ad_user_data": "denied",
            "ad_personalization": "denied",
            "wait_for_update": 500
        });
    }
})();
</script>' . "\n";
        }

        $html .= '<style id="todo-cookie-consent-styles">
.todo-cc-backdrop { position: fixed; bottom: 20px; inset-inline-start: 20px; max-width: 480px; width: calc(100% - 40px); background: #ffffff; color: #1e293b; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.05), 0 10px 10px -5px rgba(0, 0, 0, 0.08); border-radius: 12px; border: 1px solid #e9edf3; z-index: 999999; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; padding: 24px; box-sizing: border-box; display: none; }
.todo-cc-backdrop.todo-cc-show { height: 480px; display: flex; flex-direction: column; animation: todoCcFadeIn 0.3s ease-out; }
.todo-cc-backdrop.todo-cc-show.todo-cc-full { height: 92%; max-width: 100%; }
@keyframes todoCcFadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
.todo-cc-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.todo-cc-title { font-size: 18px; font-weight: 700; color: #0f172a; margin: 0; line-height: 1.3; }
.todo-cc-body { font-size: 13px; line-height: 1.6; color: #475569; margin-bottom: 10px; margin-inline-end: -15px; flex: 1; overflow-y: auto; }
.todo-cc-body p { margin: 0 0 8px 0; }
.todo-cc-options { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid #f1f5f9; }
.todo-cc-option-item { display: flex; align-items: center; justify-content: space-between; font-size: 13px; color: #334155; }
.todo-cc-option-item label { display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 500; }
.todo-cc-option-item input[disabled] { opacity: 0.7; cursor: not-allowed; }
.todo-cc-actions { display: flex; gap: 10px; flex-wrap: wrap; }
.todo-cc-btn { flex: 1; min-width: 120px; padding: 10px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; text-align: center; cursor: pointer; transition: all 0.2s ease; border: none; outline: none; }
.todo-cc-btn-accept { background: #2563eb; color: #ffffff; }
.todo-cc-btn-accept:hover { background: #1d4ed8; }
.todo-cc-btn-reject { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }
.todo-cc-btn-reject:hover { background: #e2e8f0; color: #0f172a; }
.todo-cc-badge { position: fixed; bottom: 20px; inset-inline-start: 20px; width: 40px; height: 40px; border-radius: 50%; background: #0f172a; color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 999998; transition: transform 0.2s ease; }
.todo-cc-badge:hover { transform: scale(1.1); }
.todo-cc-badge svg { width: 20px; height: 20px; fill: currentColor; }
</style>
<div id="todo-cookie-banner" class="todo-cc-backdrop" role="dialog" aria-modal="true" aria-labelledby="todo-cc-title-txt">
    <div class="todo-cc-header">
        <h3 class="todo-cc-title" id="todo-cc-title-txt">' . esc_html($consent_config['title']) . '</h3>
        <span id="todo-cc-full" title="Full Screen" style="cursor: pointer;opacity: 0.5;position: relative;top: -15px;inset-inline-end: -10px;"><i class="icon todofont todo-FullScreenTwo"></i></span>
    </div>
    <div class="todo-cc-body">
        ' . $consent_config['content'] . '
    </div>
    <div class="todo-cc-options">
        <div class="todo-cc-option-item">
            <label><input type="checkbox" checked disabled /> Essential Cookies (Strictly Necessary)</label>
            <span style="font-size: 11px; color: #94a3b8;">Always Active</span>
        </div>
        <div class="todo-cc-option-item">
            <label><input type="checkbox" id="todo-cc-opt-analytics" /> Analytics & Performance Cookies</label>
        </div>
        <div class="todo-cc-option-item">
            <label><input type="checkbox" id="todo-cc-opt-marketing" /> Marketing & Targeting Cookies</label>
        </div>
    </div>
    <div class="todo-cc-actions">
        <button type="button" class="todo-cc-btn todo-cc-btn-reject" id="todo-cc-btn-reject-nonessential">' . esc_html($consent_config['btnReject']) . '</button>
        <button type="button" class="todo-cc-btn todo-cc-btn-accept" id="todo-cc-btn-accept-all">' . esc_html($consent_config['btnAccept']) . '</button>
    </div>
</div>
<div id="todo-cookie-badge" class="todo-cc-badge" title="Cookie Preferences" style="display:none;">
    <svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 10 10 10 10 0 0 0-10-10zm-1.5 4a1.5 1.5 0 1 1-1.5 1.5A1.5 1.5 0 0 1 10.5 6zm-4 5a1.5 1.5 0 1 1-1.5 1.5A1.5 1.5 0 0 1 6.5 11zm3 7a1.5 1.5 0 1 1-1.5 1.5A1.5 1.5 0 0 1 9.5 18zm6.5-2a1.5 1.5 0 1 1-1.5 1.5 1.5 1.5 0 0 1 1.5-1.5zm1-5a1.5 1.5 0 1 1-1.5 1.5 1.5 1.5 0 0 1 1.5-1.5z"/></svg>
</div>
<script type="text/javascript">
(function() {
    var STORAGE_KEY = "todo_cookie_consent_choice";
    var ENABLE_AUDIT_LOG = ' . json_encode($cookie_consent_audit_log === '1') . ';
    var AUDIT_LOG_URL = ' . json_encode(function_exists('todo_get_site_url') ? todo_get_site_url('includes/ajax/cookie-consent-log.php') : '/includes/ajax/cookie-consent-log.php') . ';
    var banner = document.getElementById("todo-cookie-banner");
    var badge = document.getElementById("todo-cookie-badge");
    var btnFull = document.getElementById("todo-cc-full");
    var btnAcceptAll = document.getElementById("todo-cc-btn-accept-all");
    var btnReject = document.getElementById("todo-cc-btn-reject-nonessential");
    var optAnalytics = document.getElementById("todo-cc-opt-analytics");
    var optMarketing = document.getElementById("todo-cc-opt-marketing");

    if (btnFull && banner) {
        btnFull.addEventListener("click", function() {
            banner.classList.toggle("todo-cc-full");
            var icon = btnFull.querySelector("i");
            if (banner.classList.contains("todo-cc-full")) {
                icon.className = "icon todofont todo-OffScreenTwo";
                btnFull.title = "Exit Full Screen";
            } else {
                icon.className = "icon todofont todo-FullScreenTwo";
                btnFull.title = "Full Screen";
            }
        });
    }

    function getSavedConsent() {
        try {
            var saved = localStorage.getItem(STORAGE_KEY);
            return saved ? JSON.parse(saved) : null;
        } catch(e) { return null; }
    }

    function sendAuditLog(consentObj) {
        if (!ENABLE_AUDIT_LOG || !window.fetch) return;
        try {
            fetch(AUDIT_LOG_URL, {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest" },
                body: JSON.stringify({ choice: consentObj })
            }).catch(function() {});
        } catch(e) {}
    }

    function activateGatedScripts(consentObj) {
        if (!document.querySelectorAll) return;
        var scripts = document.querySelectorAll("script[type=\"text/plain\"][data-cookie-category]");
        for (var i = 0; i < scripts.length; i++) {
            var s = scripts[i];
            var category = s.getAttribute("data-cookie-category");
            if ((category === "analytics" && consentObj.analytics) || (category === "marketing" && consentObj.marketing)) {
                var newScript = document.createElement("script");
                newScript.type = "text/javascript";
                if (s.src) {
                    newScript.src = s.src;
                } else {
                    newScript.textContent = s.textContent;
                }
                for (var a = 0; a < s.attributes.length; a++) {
                    var attr = s.attributes[a];
                    if (attr.name !== "type" && attr.name !== "data-cookie-category") {
                        newScript.setAttribute(attr.name, attr.value);
                    }
                }
                s.parentNode.replaceChild(newScript, s);
            }
        }
    }

    function saveConsent(consentObj, isUserAction) {
        try {
            consentObj.timestamp = Date.now();
            localStorage.setItem(STORAGE_KEY, JSON.stringify(consentObj));
            document.cookie = "todo_cookie_consent=" + encodeURIComponent(JSON.stringify(consentObj)) + "; path=/; max-age=" + (365*86400) + "; SameSite=Lax";
            if (isUserAction) {
                sendAuditLog(consentObj);
            }
        } catch(e) {}
    }

    function applyConsent(consentObj) {
        if (!consentObj) return;
        window.TodoCookieConsent = {
            analytics: !!consentObj.analytics,
            marketing: !!consentObj.marketing,
            timestamp: consentObj.timestamp || Date.now(),
            hasConsent: function(type) { return !!this[type]; }
        };

        if (typeof window.gtag === "function") {
            try {
                window.gtag("consent", "update", {
                    "analytics_storage": consentObj.analytics ? "granted" : "denied",
                    "ad_storage": consentObj.marketing ? "granted" : "denied",
                    "ad_user_data": consentObj.marketing ? "granted" : "denied",
                    "ad_personalization": consentObj.marketing ? "granted" : "denied"
                });
            } catch(e) {}
        }

        activateGatedScripts(consentObj);

        try {
            var evt = new CustomEvent("todoCookieConsentUpdated", { detail: window.TodoCookieConsent });
            window.dispatchEvent(evt);
        } catch(e) {}
    }

    function hideBanner() {
        if (banner) banner.classList.remove("todo-cc-show");
        if (badge) badge.style.display = "flex";
    }

    function showBanner() {
        if (banner) banner.classList.add("todo-cc-show");
        if (badge) badge.style.display = "none";
    }

    var existing = getSavedConsent();
    if (!existing) {
        showBanner();
    } else {
        applyConsent(existing);
        if (badge) badge.style.display = "flex";
    }

    if (btnAcceptAll) {
        btnAcceptAll.addEventListener("click", function() {
            var choice = { necessary: true, analytics: true, marketing: true };
            if (optAnalytics) optAnalytics.checked = true;
            if (optMarketing) optMarketing.checked = true;
            saveConsent(choice, true);
            applyConsent(choice);
            hideBanner();
        });
    }

    if (btnReject) {
        btnReject.addEventListener("click", function() {
            var choice = { necessary: true, analytics: false, marketing: false };
            if (optAnalytics) optAnalytics.checked = false;
            if (optMarketing) optMarketing.checked = false;
            saveConsent(choice, true);
            applyConsent(choice);
            hideBanner();
        });
    }

    if (badge) {
        badge.addEventListener("click", function() {
            var current = getSavedConsent();
            if (current && optAnalytics && optMarketing) {
                optAnalytics.checked = !!current.analytics;
                optMarketing.checked = !!current.marketing;
            }
            showBanner();
        });
    }
})();
</script>' . "\n";
    }

    return $html;
}

/**
 * 在todo_footer钩子中输出脚本
 */
function todo_print_async_cache_check_script($force = false)
{
    echo todo_get_async_cache_check_script_html($force);
}

function todo_footer_scripts()
{
    todo_print_scripts(true); // 输出页脚脚本

    todo_print_async_cache_check_script();
}

/**
 * 初始化主题钩子
 */
function init_theme_hooks()
{
    // 新方案：由模板标签 {{todo:todo_head}} 与 {{todo:todo_footer}} 直接输出队列资源
    // 为避免重复输出，这里不再绑定打印相关钩子
    // 保留空实现，供核心初始化调用
}

/**
 * 执行todo_enqueue_scripts钩子
 */
function todo_enqueue_scripts_hook()
{
    do_action('todo_enqueue_scripts');
}

/**
 * 获取样式表URI
 */
function get_stylesheet_uri()
{
    return get_template_directory_uri() . '/style.css';
}

/**
 * 本地化脚本数据
 * 将PHP数据传递给JavaScript
 *
 * @param string $handle 脚本句柄
 * @param string $object_name JavaScript对象名称
 * @param array $data 要传递的数据
 */
function todo_localize_script($handle, $object_name, $data)
{
    global $todo_scripts, $todo_localized_scripts;

    // 初始化本地化脚本数组
    if (!isset($todo_localized_scripts)) {
        $todo_localized_scripts = array();
    }

    // 存储本地化数据
    $todo_localized_scripts[$handle] = array(
        'object_name' => $object_name,
        'data' => $data
    );
}

/**
 * 输出本地化脚本数据
 * 在脚本输出后调用
 */
function todo_print_localized_scripts($in_footer = false)
{
    global $todo_scripts, $todo_localized_scripts;

    if (empty($todo_localized_scripts)) {
        return;
    }

    foreach ($todo_localized_scripts as $handle => $localized) {
        // 检查脚本是否应该在当前位置输出
        if (isset($todo_scripts[$handle]) && $todo_scripts[$handle]['in_footer'] == $in_footer) {
            $object_name = $localized['object_name'];
            $data = $localized['data'];

            echo '<script type="text/javascript">' . "\n";
            echo '/* <![CDATA[ */' . "\n";
            echo 'var ' . esc_js($object_name) . ' = ' . json_encode($data) . ';' . "\n";
            echo '/* ]]> */' . "\n";
            echo '</script>' . "\n";
        }
    }
}

/**
 * 生成符合 GDPR 审计要求的 Cookie 声明明细表格 HTML
 */
function todo_get_cookie_declaration_table_html()
{
    $lang = function_exists('get_current_lang') ? get_current_lang() : 'en';
    $is_zh = (strpos($lang, 'zh') === 0);

    $html = '<div class="todo-cookie-table-wrapper" style="margin: 20px 20px 20px 0; overflow-x: auto;">';
    $html .= '<table class="todo-cookie-table" style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left; border: 1px solid #e2e8f0; font-family: sans-serif;">';
    $html .= '<thead><tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">';
    $html .= '<th style="padding: 10px 12px; max-width: 300px; min-width: 140px;">' . ($is_zh ? 'Cookie 名称' : 'Cookie Name') . '</th>';
    $html .= '<th style="padding: 10px 12px; width: 120px;">' . ($is_zh ? '类别' : 'Category') . '</th>';
    $html .= '<th style="padding: 10px 12px; width: 120px;">' . ($is_zh ? '提供方' : 'Provider') . '</th>';
    $html .= '<th style="padding: 10px 12px; width: 100px;">' . ($is_zh ? '有效期' : 'Expiration') . '</th>';
    $html .= '<th style="padding: 10px 12px; max-width: 300px; min-width: 140px;">' . ($is_zh ? '用途说明' : 'Purpose') . '</th>';
    $html .= '</tr></thead><tbody>';

    $cookies = [
        [
            'name' => 'todo_session / PHPSESSID',
            'cat' => $is_zh ? '必要 (Essential)' : 'Essential',
            'provider' => 'First Party',
            'exp' => $is_zh ? '会话期 (Session)' : 'Session',
            'desc' => $is_zh ? '保持用户登录态、维持购物车状态与核心功能运作。' : 'Maintains user session, shopping cart state, and core functionality.'
        ],
        [
            'name' => 'todo_cookie_consent_choice / todo_cookie_consent',
            'cat' => $is_zh ? '必要 (Essential)' : 'Essential',
            'provider' => 'First Party',
            'exp' => $is_zh ? '1 年' : '1 Year',
            'desc' => $is_zh ? '记录并记忆用户对 GDPR Cookie 弹窗的授权同意配置。' : 'Stores user cookie consent choices for GDPR compliance.'
        ],
        [
            'name' => 'todo_language',
            'cat' => $is_zh ? '必要 (Essential)' : 'Essential',
            'provider' => 'First Party',
            'exp' => $is_zh ? '30 天' : '30 Days',
            'desc' => $is_zh ? '记忆用户所选的多语言界面偏好。' : 'Remembers user interface language preference.'
        ],
        [
            'name' => '_ga, _gid, _ga_*',
            'cat' => $is_zh ? '分析 (Analytics)' : 'Analytics',
            'provider' => 'Google Analytics',
            'exp' => $is_zh ? '2 年' : '2 Years',
            'desc' => $is_zh ? '用于统计分析网站流量、页面访问深度与匿名化用户行为。' : 'Used to compile anonymous statistical traffic and performance reports.'
        ],
        [
            'name' => '_fbp, _fbc',
            'cat' => $is_zh ? '营销 (Marketing)' : 'Marketing',
            'provider' => 'Meta / Facebook',
            'exp' => $is_zh ? '90 天' : '90 Days',
            'desc' => $is_zh ? '用于广告效果追踪、再营销受众（Remarketing）广告精准归因。' : 'Used for ad tracking, attribution, and personalized retargeting.'
        ]
    ];

    foreach ($cookies as $c) {
        $html .= '<tr style="border-bottom: 1px solid #f1f5f9;">';
        $html .= '<td style="padding: 10px 12px;"><code style="line-height: normal;word-break: break-all;">' . esc_html($c['name']) . '</code></td>';
        $html .= '<td style="padding: 10px 12px;"><span style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: 11px;">' . esc_html($c['cat']) . '</span></td>';
        $html .= '<td style="padding: 10px 12px;">' . esc_html($c['provider']) . '</td>';
        $html .= '<td style="padding: 10px 12px;">' . esc_html($c['exp']) . '</td>';
        $html .= '<td style="padding: 10px 12px;color: #475569;line-height: normal;word-break: break-all;">' . esc_html($c['desc']) . '</td>';
        $html .= '</tr>';
    }

    $html .= '</tbody></table></div>';
    return $html;
}
