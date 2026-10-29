<?php
/**
 * 模板与主题相关函数
 *
 * @package TodoCMS
 * @version 1.0.0
 */

if (!function_exists('get_template_directory')) {
    function get_template_directory()
    {
        // 获取设备类型（使用CMS_Theme的检测机制，支持Cookie和URL参数）
        $theme = CMS_Theme::get_active_theme();
        $device_style = $theme ? $theme->get_device_style() : 'pc';

        return $theme ? $theme->get_template_directory($device_style) : THEMES_DIR . '/default';
    }
}

if (!function_exists('get_template_directory_uri')) {
    function get_template_directory_uri()
    {
        // 获取设备类型（使用CMS_Theme的检测机制，支持Cookie和URL参数）
        $theme = CMS_Theme::get_active_theme();
        $device_style = $theme ? $theme->get_device_style() : 'pc';

        // 使用 CMS_Theme 的 get_template_directory_uri() 以返回当前主题的URL
        return $theme ? $theme->get_template_directory_uri($device_style) : THEMES_URL . '/default';
    }
}

if (!function_exists('get_theme_name')) {
    function get_theme_name()
    {
        // 强制预览主题优先级最高
        if (isset($GLOBALS['todo_forced_theme']) && !empty($GLOBALS['todo_forced_theme'])) {
            return (string) $GLOBALS['todo_forced_theme'];
        }

        // 兼容旧的全局变量
        if (isset($GLOBALS['preview_theme_name']) && !empty($GLOBALS['preview_theme_name'])) {
            return (string) $GLOBALS['preview_theme_name'];
        }

        // 依赖 CMS_Theme 正常加载
        // CMS_Theme::get_active_theme() 内部已经处理了静态缓存和 active_theme 过滤器
        $theme = CMS_Theme::get_active_theme();
        return $theme ? $theme->get_stylesheet() : 'default';
    }

}

if (!function_exists('theme_load_template')) {
    function theme_load_template($template_name, $args = array(), $require_once = true)
    {
        $template_path = get_template_path($template_name);
        if (!$template_path || !file_exists($template_path)) {
            return false;
        }

        if (!empty($args) && is_array($args)) {
            extract($args);
        }

        if ($require_once) {
            require_once $template_path;
        } else {
            require $template_path;
        }
        return true;
    }
}

if (!function_exists('get_template_path')) {
    function get_template_path($template_name)
    {
        $theme = CMS_Theme::get_active_theme();
        if (!$theme) {
            return false;
        }
        // 使用 CMS_Theme::get_template_path()
        return $theme->get_template_path($template_name);
    }

}

if (!function_exists('load_specific_template')) {
    function load_specific_template($template_name)
    {
        return theme_load_template($template_name, array(), true);
    }
}

if (!function_exists('get_template_part')) {
    function get_template_part($slug, $name = '')
    {
        $theme = CMS_Theme::get_active_theme();
        if (!$theme) {
            return false;
        }
        // 优先加载 slug-name.php，否则 slug.php
        $part = $name ? ($slug . '-' . $name) : $slug;
        if ($theme->get_template_part($part) === false && $name) {
            return $theme->get_template_part($slug);
        }
        return true;
    }
}

if (!function_exists('locate_template')) {
    /**
     * 查找模板文件
     *
     * @param string|array $template_names 模板名称列表
     * @param bool $load 是否加载
     * @param bool $require_once 是否使用 require_once
     * @return string 找到的模板路径
     */
    function locate_template($template_names, $load = false, $require_once = true)
    {
        $located = '';
        $theme_dir = get_template_directory();

        foreach ((array) $template_names as $template_name) {
            if (!$template_name) {
                continue;
            }
            if (file_exists($theme_dir . '/' . $template_name)) {
                $located = $theme_dir . '/' . $template_name;
                break;
            }
        }

        if ($load && $located != '') {
            cms_load_template_file($located, $require_once);
        }

        return $located;
    }
}

if (!function_exists('cms_load_template_file')) {
    /**
     * 加载模板文件（带全局变量支持）
     *
     * @param string $_template_file 文件路径
     * @param bool $require_once 是否使用 require_once
     */
    function cms_load_template_file($_template_file, $require_once = true)
    {
        global $posts, $post, $tododb, $current_user, $user;

        if ($require_once) {
            require_once $_template_file;
        } else {
            require $_template_file;
        }
    }
}

if (!function_exists('get_header')) {
    /**
     * 加载头部模板
     *
     * @param string $name 模板名称
     */
    function get_header($name = null)
    {
        todo_do_action('get_header', $name);

        $templates = array();
        if (isset($name)) {
            $templates[] = "header-{$name}.php";
        }
        $templates[] = 'header.php';

        // 优先尝试加载 HTML 模板
        $html_name = isset($name) ? "header-{$name}" : "header";
        $html_path = get_html_template_path($html_name);
        if ($html_path) {
            echo parse_html_template_part($html_path);
            return;
        }

        locate_template($templates, true);
    }
}

if (!function_exists('get_footer')) {
    /**
     * 加载底部模板
     *
     * @param string $name 模板名称
     */
    function get_footer($name = null)
    {
        todo_do_action('get_footer', $name);

        $templates = array();
        if (isset($name)) {
            $templates[] = "footer-{$name}.php";
        }
        $templates[] = 'footer.php';

        // 优先尝试加载 HTML 模板
        $html_name = isset($name) ? "footer-{$name}" : "footer";
        $html_path = get_html_template_path($html_name);
        if ($html_path) {
            echo parse_html_template_part($html_path);
            return;
        }

        locate_template($templates, true);
    }
}

if (!function_exists('get_sidebar')) {
    /**
     * 加载侧边栏模板
     *
     * @param string $name 模板名称
     */
    function get_sidebar($name = null)
    {
        todo_do_action('get_sidebar', $name);

        $templates = array();
        if (isset($name)) {
            $templates[] = "sidebar-{$name}.php";
        }
        $templates[] = 'sidebar.php';

        // 优先尝试加载 HTML 模板
        $html_name = isset($name) ? "sidebar-{$name}" : "sidebar";
        $html_path = get_html_template_path($html_name);
        if ($html_path) {
            echo parse_html_template_part($html_path);
            return;
        }

        locate_template($templates, true);
    }
}

if (!function_exists('is_home')) {
    function is_home()
    {
        if (function_exists('get_current_page_type') && get_current_page_type() === 'home') {
            return true;
        }
        if (todo_get_query_context_value('home', todo_get_string('home')) !== '') {
            return true;
        }

        $route = (string) todo_get_query_context_value('route', todo_get_string('route'));
        if ($route !== '') {
            if ($route === 'home' || $route === 'index' || $route === '') {
                return true;
            }
            return false;
        }

        if (
            in_array((string) todo_get_query_context_value('page', todo_get_string('page')), ['post', 'page', 'category', 'tag', 'search', 'archive', 'author', '404'], true) ||
            todo_get_query_context_value('single', todo_get_string('single')) !== '' ||
            is_numeric(todo_get_query_context_value('p', todo_get_string('p'))) ||
            is_numeric(todo_get_query_context_value('post_id', todo_get_string('post_id'))) ||
            is_numeric(todo_get_query_context_value('page_id', todo_get_string('page_id'))) ||
            todo_get_query_context_value('cat', todo_get_string('cat')) !== '' ||
            todo_get_query_context_value('category', todo_get_string('category')) !== '' ||
            todo_get_query_context_value('tag', todo_get_string('tag')) !== '' ||
            todo_get_search_context() !== ''
        ) {
            return false;
        }

        return true;
    }
}

if (!function_exists('is_front_page')) {
    function is_front_page()
    {
        return is_home();
    }
}

if (!function_exists('is_single')) {
    function is_single()
    {
        if (function_exists('get_current_page_type') && get_current_page_type() === 'single') {
            return true;
        }
        if (todo_get_query_context_value('page', todo_get_string('page')) === 'post') {
            return true;
        }
        return todo_get_query_context_value('single', todo_get_string('single')) !== '' || (todo_get_query_context_value('route', todo_get_string('route')) === 'single');
    }
}

if (!function_exists('is_page')) {
    function is_page()
    {
        if (function_exists('get_current_page_type') && get_current_page_type() === 'page') {
            return true;
        }
        if (todo_get_query_context_value('page', todo_get_string('page')) === 'page') {
            return true;
        }
        return (todo_get_query_context_value('route', todo_get_string('route')) === 'page');
    }
}

if (!function_exists('is_category')) {
    function is_category()
    {
        if (function_exists('get_current_page_type') && get_current_page_type() === 'category') {
            return true;
        }
        $route = (string) todo_get_query_context_value('route', todo_get_string('route'));
        if ($route !== '') {
            if ($route === 'category') {
                return true;
            }
            if ($route === 'single' || $route === 'page') {
                return false;
            }
        }
        // 优先检查明确的分类参数
        if (todo_get_query_context_value('cat', todo_get_string('cat')) !== '' || todo_get_query_context_value('category', todo_get_string('category')) !== '') {
            return true;
        }
        if (todo_get_query_context_value('page', todo_get_string('page')) === 'category') {
            return true;
        }

        // 如果存在明确的文章ID参数，且没有明确的分类标识，则视为非分类页
        if (is_numeric(todo_get_query_context_value('p', todo_get_string('p'))) || is_numeric(todo_get_query_context_value('post_id', todo_get_string('post_id')))) {
            return false;
        }

        // 特殊处理 id 参数：只有在非分类上下文才视为文章ID
        if (is_numeric(todo_get_query_context_value('id', todo_get_string('id')))) {
            // 如果前面已经确认了 cat/category/page=category，上面已经返回 true 了
            // 所以这里只需要确认是否没有任何分类迹象
            return false;
        }

        if (isset($GLOBALS['current_category']) && !empty($GLOBALS['current_category'])) {
            return true;
        }
        if (function_exists('get_queried_object')) {
            $obj = get_queried_object();
            if (is_object($obj)) {
                if (property_exists($obj, 'taxonomy') && ($obj->taxonomy === 'category' || $obj->taxonomy === 'post_category')) {
                    return true;
                }
                if (property_exists($obj, 'type') && $obj->type === 'category') {
                    return true;
                }
            }
        }
        return false;
    }
}

if (!function_exists('is_tag')) {
    function is_tag()
    {
        if (function_exists('get_current_page_type') && in_array(get_current_page_type(), ['tag', 'tag_index', 'tag_lists'])) {
            return true;
        }
        if (todo_get_query_context_value('route', todo_get_string('route')) === 'tag') {
            return true;
        }
        if (todo_get_query_context_value('page', todo_get_string('page')) === 'tag') {
            return true;
        }
        if (todo_get_query_context_value('tag', todo_get_string('tag')) !== '') {
            return true;
        }
        if (isset($GLOBALS['current_tag']) && !empty($GLOBALS['current_tag'])) {
            return true;
        }
        if (function_exists('get_queried_object')) {
            $obj = get_queried_object();
            if (is_object($obj)) {
                if (property_exists($obj, 'taxonomy') && in_array($obj->taxonomy, ['tag', 'post_tag'], true)) {
                    return true;
                }
                if (property_exists($obj, 'type') && $obj->type === 'tag') {
                    return true;
                }
            }
        }
        return false;
    }
}

if (!function_exists('is_search')) {
    function is_search()
    {
        if (function_exists('get_current_page_type') && get_current_page_type() === 'search') {
            return true;
        }
        return (todo_get_query_context_value('route', todo_get_string('route')) === 'search') || (todo_get_search_context() !== '') || (todo_get_query_context_value('page', todo_get_string('page')) === 'search');
    }
}

if (!function_exists('is_archive')) {
    function is_archive()
    {
        if (function_exists('get_current_page_type') && get_current_page_type() === 'archive') {
            return true;
        }
        $route = function_exists('todo_get_query_context_value') ? (string) todo_get_query_context_value('route', (function_exists('todo_get_string') ? todo_get_string('route') : '')) : (function_exists('todo_get_string') ? todo_get_string('route') : '');
        return $route === 'archive';
    }
}

if (!function_exists('is_404')) {
    function is_404()
    {
        if (function_exists('get_current_page_type') && get_current_page_type() === '404') {
            return true;
        }
        $route = function_exists('todo_get_query_context_value') ? (string) todo_get_query_context_value('route', (function_exists('todo_get_string') ? todo_get_string('route') : '')) : (function_exists('todo_get_string') ? todo_get_string('route') : '');
        return $route === '404';
    }
}

if (!function_exists('get_site_name')) {
    function get_site_name()
    {
        return get_option('site_name', 'My Website');
    }
}

if (!function_exists('get_site_description')) {
    function get_site_description()
    {
        return get_option('site_description', '');
    }
}

if (!function_exists('get_page_title')) {
    function get_page_title()
    {
        // 简化实现：优先使用当前路由/页面上下文，可根据需要扩展
        if (is_single()) {
            global $post;
            if ($post && isset($post->post_title)) {
                return $post->post_title;
            }
        }
        return get_site_name();
    }
}

if (!function_exists('get_seo_title')) {
    function get_seo_title($post_id = null)
    {
        $clean_title = function ($text) {
            if (function_exists('todo_map_inline_translations_deep')) {
                $text = todo_map_inline_translations_deep($text);
            }
            return trim(strip_tags(html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8')));
        };

        $resolve_title = function ($pid) use ($clean_title) {
            $ml = null;
            if (is_numeric($pid)) {
                $ml = todo_get_post_multilang((int) $pid, null, ['include_seo' => true, 'fallback' => true, 'prefer_custom_url' => true]);
            }
            // 1) 语言 SEO 标题
            if (is_array($ml) && !empty($ml['seo_title'])) {
                return $clean_title($ml['seo_title']);
            }
            // 2) 主稿 SEO 标题（优先于语言文章标题，符合“首选SEO标题”）
            $main_seo = '';
            $pobj = function_exists('get_post') ? get_post($pid) : null;
            if ($pobj && isset($pobj->seo_title) && $pobj->seo_title !== '') {
                $main_seo = (string) $pobj->seo_title;
            } elseif (function_exists('get_post_meta')) {
                $main_seo = (string) get_post_meta($pid, 'seo_title', true);
            }
            if ($main_seo !== '') {
                return $clean_title($main_seo);
            }
            // 3) 语言文章标题
            if (is_array($ml) && !empty($ml['title'])) {
                return $clean_title($ml['title']);
            }
            // 4) 主稿文章标题
            $p = function_exists('get_post') ? get_post($pid) : null;
            if ($p && !empty($p->post_title)) {
                return $clean_title($p->post_title);
            }
            return '';
        };

        if ($post_id !== null) {
            return $resolve_title($post_id);
        }

        if ((function_exists('todo_is_single') && todo_is_single()) || (function_exists('is_single') && is_single())) {
            global $post;
            if ($post && isset($post->ID)) {
                return $resolve_title($post->ID);
            }
            $raw_p = function_exists('todo_get_query_context_value') ? (int) todo_get_query_context_value('p', (int) todo_get_query_context_value('id', (function_exists('todo_get_int') ? todo_get_int('p', todo_get_int('id')) : 0))) : (function_exists('todo_get_int') ? todo_get_int('p', todo_get_int('id')) : 0);
            if ($raw_p > 0) {
                return $resolve_title($raw_p);
            }
        }
        if ((function_exists('todo_is_page') && todo_is_page()) || (function_exists('is_page') && is_page())) {
            global $post;
            if ($post && isset($post->ID)) {
                return $resolve_title($post->ID);
            }
        }

        return '';
    }
}

if (!function_exists('get_seo_keywords')) {
    function get_seo_keywords($post_id = null)
    {
        $clean_keywords = function ($text) {
            if (function_exists('todo_map_inline_translations_deep')) {
                $text = todo_map_inline_translations_deep($text);
            }
            return trim(strip_tags(html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8')));
        };

        if ($post_id === null) {
            if (function_exists('is_category') && is_category() && function_exists('get_queried_object')) {
                $cat = get_queried_object();
                $term_id = 0;
                if ($cat) {
                    if (isset($cat->term_id)) {
                        $term_id = (int) $cat->term_id;
                    } elseif (isset($cat->id)) {
                        $term_id = (int) $cat->id;
                    }
                }
                if ($term_id > 0) {
                    if (!function_exists('todo_get_term_seo_multilang')) {
                        @require_once ABSPATH . 'core/functions/multilang-fields.php';
                    }
                    if (function_exists('todo_get_term_seo_multilang')) {
                        $seo = todo_get_term_seo_multilang($term_id, 'category', null, array('status_allowed' => array('approved', 'pending')));
                        if (is_array($seo) && !empty($seo['seo_keywords'])) {
                            return $clean_keywords($seo['seo_keywords']);
                        }
                    }
                }
            } elseif (function_exists('is_tag') && is_tag() && function_exists('get_queried_object')) {
                $tag = get_queried_object();
                $term_id = 0;
                if ($tag) {
                    if (isset($tag->term_id)) {
                        $term_id = (int) $tag->term_id;
                    } elseif (isset($tag->id)) {
                        $term_id = (int) $tag->id;
                    }
                }
                if ($term_id > 0) {
                    if (!function_exists('todo_get_term_seo_multilang')) {
                        @require_once ABSPATH . 'core/functions/multilang-fields.php';
                    }
                    if (function_exists('todo_get_term_seo_multilang')) {
                        $seo = todo_get_term_seo_multilang($term_id, 'post_tag', null, array('status_allowed' => array('approved', 'pending')));
                        if (is_array($seo) && !empty($seo['seo_keywords'])) {
                            return $clean_keywords($seo['seo_keywords']);
                        }
                    }
                }
            }
            $seo = function_exists('get_seo_data') ? get_seo_data() : [];
            if (is_array($seo) && isset($seo['keywords']) && $seo['keywords'] !== '') {
                return $clean_keywords($seo['keywords']);
            }
            return $clean_keywords(function_exists('todo_get_option') ? todo_get_option('site_keywords', '') : get_option('site_keywords', ''));
        }
        $ml = null;
        if (is_numeric($post_id)) {
            $ml = todo_get_post_multilang((int) $post_id, null, ['include_seo' => true, 'fallback' => true, 'prefer_custom_url' => true]);
        }
        if (is_array($ml) && !empty($ml['seo_keywords'])) {
            return $clean_keywords($ml['seo_keywords']);
        }
        $pobj = function_exists('get_post') ? get_post($post_id) : null;
        if ($pobj && isset($pobj->seo_keywords) && $pobj->seo_keywords !== '') {
            return $clean_keywords($pobj->seo_keywords);
        }
        $kw = function_exists('get_post_meta') ? (string) get_post_meta($post_id, 'seo_keywords', true) : '';
        if ($kw !== '') {
            return $clean_keywords($kw);
        }
        $kw = function_exists('get_post_meta') ? (string) get_post_meta($post_id, 'meta_keywords', true) : '';
        if ($kw !== '') {
            return $clean_keywords($kw);
        }
        if ($pobj && isset($pobj->keywords) && $pobj->keywords !== '') {
            return $clean_keywords($pobj->keywords);
        }
        $kw = function_exists('get_post_meta') ? (string) get_post_meta($post_id, 'keywords', true) : '';
        if ($kw !== '') {
            return $clean_keywords($kw);
        }
        return $clean_keywords(function_exists('todo_get_option') ? todo_get_option('site_keywords', '') : get_option('site_keywords', ''));
    }
}

if (!function_exists('get_seo_description')) {
    function get_seo_description($post_id = null)
    {
        $site_description = function_exists('todo_get_option') ? todo_get_option('site_description', '') : (function_exists('get_option') ? get_option('site_description', '') : '');

        // 清理描述文本的辅助函数
        $clean_description = function ($text) {
            if (function_exists('todo_map_inline_translations_deep')) {
                $text = todo_map_inline_translations_deep($text);
            }
            $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
            $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)));
            return $text;
        };

        if ($post_id === null) {
            // 优先使用 get_seo_data() 返回的描述
            $seo = function_exists('get_seo_data') ? get_seo_data() : [];
            if (is_array($seo) && isset($seo['description']) && $seo['description'] !== '') {
                return $clean_description($seo['description']);
            }

            // 根据页面类型获取对应的描述格式
            $description_format = '';
            $vars = ['site_description' => $site_description];

            if ((function_exists('todo_is_home') && todo_is_home()) || (function_exists('is_home') && (is_home() || is_front_page()))) {
                // 首页
                $description_format = function_exists('get_option') ? get_option('seo_home_meta_description', '') : '';
                if (empty($description_format)) {
                    return $clean_description($site_description);
                }
            } elseif ((function_exists('todo_is_single') && todo_is_single()) || (function_exists('is_single') && is_single())) {
                // 文章页
                $description_format = function_exists('get_option') ? get_option('seo_post_meta_description', '%post_excerpt%') : '%post_excerpt%';
                $pid = 0;
                if (isset($GLOBALS['todo_post']) && is_object($GLOBALS['todo_post']) && isset($GLOBALS['todo_post']->ID)) {
                    $pid = (int) $GLOBALS['todo_post']->ID;
                } else {
                    $pid = function_exists('todo_get_query_context_value')
                        ? (int) todo_get_query_context_value('id', (int) todo_get_query_context_value('p', (function_exists('todo_get_int') ? todo_get_int('p', todo_get_int('id')) : 0)))
                        : (function_exists('todo_get_int') ? todo_get_int('p', todo_get_int('id')) : 0);
                }

                if ($pid > 0) {
                    $ml = todo_get_post_multilang((int) $pid, null, ['include_seo' => true, 'fallback' => true, 'prefer_custom_url' => true]);
                    if (is_array($ml) && isset($ml['seo_description']) && $ml['seo_description'] !== '') {
                        return $clean_description((string) $ml['seo_description']);
                    }
                    $pobj = function_exists('get_post') ? get_post($pid) : null;
                    if ($pobj && isset($pobj->seo_description) && $pobj->seo_description !== '') {
                        return $clean_description((string) $pobj->seo_description);
                    }
                    // 获取文章SEO描述
                    $desc = function_exists('get_post_meta') ? get_post_meta($pid, 'seo_description', true) : '';
                    if ($desc !== '') {
                        return $clean_description($desc);
                    }

                    // 获取文章摘要或内容
                    $p = $pobj ?: (function_exists('get_post') ? get_post($pid) : null);
                    if ($p) {
                        $excerpt = '';
                        if (isset($p->post_excerpt) && $p->post_excerpt !== '') {
                            $excerpt = $p->post_excerpt;
                        } elseif (isset($p->post_content) && $p->post_content !== '') {
                            $raw = html_entity_decode($p->post_content, ENT_QUOTES, 'UTF-8');
                            $raw = strip_tags($raw);
                            $raw = preg_replace('/\s+/u', ' ', $raw);
                            $excerpt = truncate_string(trim($raw), 100, '');
                        }
                        $vars['post_excerpt'] = $excerpt;
                        $vars['post_content'] = $p->post_content ? strip_tags(html_entity_decode($p->post_content, ENT_QUOTES, 'UTF-8')) : '';
                        $vars['post_title'] = isset($p->post_title) ? $p->post_title : '';
                    }
                }
            } elseif ((function_exists('todo_is_page') && todo_is_page()) || (function_exists('is_page') && is_page())) {
                // 页面
                $description_format = function_exists('get_option') ? get_option('seo_page_meta_description', '%page_excerpt%') : '%page_excerpt%';
                $pid = 0;
                if (isset($GLOBALS['todo_post']) && is_object($GLOBALS['todo_post']) && isset($GLOBALS['todo_post']->ID)) {
                    $pid = (int) $GLOBALS['todo_post']->ID;
                } else {
                    $pid = function_exists('todo_get_query_context_value')
                        ? (int) todo_get_query_context_value('id', (int) todo_get_query_context_value('p', (function_exists('todo_get_int') ? todo_get_int('p', todo_get_int('id')) : 0)))
                        : (function_exists('todo_get_int') ? todo_get_int('p', todo_get_int('id')) : 0);
                }

                if ($pid > 0) {
                    $ml = todo_get_post_multilang((int) $pid, null, ['include_seo' => true, 'fallback' => true, 'prefer_custom_url' => true]);
                    if (is_array($ml) && isset($ml['seo_description']) && $ml['seo_description'] !== '') {
                        return $clean_description((string) $ml['seo_description']);
                    }
                    $pobj = function_exists('get_post') ? get_post($pid) : null;
                    if ($pobj && isset($pobj->seo_description) && $pobj->seo_description !== '') {
                        return $clean_description((string) $pobj->seo_description);
                    }
                    // 获取页面SEO描述
                    $desc = function_exists('get_post_meta') ? get_post_meta($pid, 'seo_description', true) : '';
                    if ($desc !== '') {
                        return $clean_description($desc);
                    }

                    // 获取页面摘要或内容
                    $p = $pobj ?: (function_exists('get_post') ? get_post($pid) : null);
                    if ($p) {
                        $excerpt = '';
                        if (isset($p->post_excerpt) && $p->post_excerpt !== '') {
                            $excerpt = $p->post_excerpt;
                        } elseif (isset($p->post_content) && $p->post_content !== '') {
                            $raw = html_entity_decode($p->post_content, ENT_QUOTES, 'UTF-8');
                            $raw = strip_tags($raw);
                            $raw = preg_replace('/\s+/u', ' ', $raw);
                            $excerpt = truncate_string(trim($raw), 100, '');
                        }
                        $vars['page_excerpt'] = $excerpt;
                        $vars['page_content'] = $p->post_content ? strip_tags(html_entity_decode($p->post_content, ENT_QUOTES, 'UTF-8')) : '';
                        $vars['page_title'] = isset($p->post_title) ? $p->post_title : '';
                    }
                }
            } elseif ((function_exists('todo_is_category') && todo_is_category()) || (function_exists('is_category') && is_category())) {
                // 分类页
                $description_format = function_exists('get_option') ? get_option('seo_category_meta_description', '%category_description%') : '%category_description%';
                $category = function_exists('get_queried_object') ? get_queried_object() : null;
                $term_id = 0;
                if ($category) {
                    if (isset($category->term_id)) {
                        $term_id = (int) $category->term_id;
                    } elseif (isset($category->id)) {
                        $term_id = (int) $category->id;
                    }
                }
                if ($term_id > 0) {
                    if (!function_exists('todo_get_term_seo_multilang')) {
                        @require_once ABSPATH . 'core/functions/multilang-fields.php';
                    }
                    if (function_exists('todo_get_term_seo_multilang')) {
                        $seo = todo_get_term_seo_multilang($term_id, 'category', null, array('status_allowed' => array('approved', 'pending')));
                        if (is_array($seo) && !empty($seo['seo_description'])) {
                            return $clean_description((string) $seo['seo_description']);
                        }
                    }
                }
                if ($category && isset($category->description)) {
                    $vars['category_description'] = $category->description;
                } else {
                    $vars['category_description'] = '';
                }
                if ($category && isset($category->name)) {
                    $vars['category_name'] = $category->name;
                }
            } elseif ((function_exists('todo_is_tag') && todo_is_tag()) || (function_exists('is_tag') && is_tag())) {
                // 标签页
                $description_format = function_exists('get_option') ? get_option('seo_tag_meta_description', '%tag_description%') : '%tag_description%';
                $tag = function_exists('get_queried_object') ? get_queried_object() : null;
                $term_id = 0;
                if ($tag) {
                    if (isset($tag->term_id)) {
                        $term_id = (int) $tag->term_id;
                    } elseif (isset($tag->id)) {
                        $term_id = (int) $tag->id;
                    }
                }
                if ($term_id > 0) {
                    if (!function_exists('todo_get_term_seo_multilang')) {
                        @require_once ABSPATH . 'core/functions/multilang-fields.php';
                    }
                    if (function_exists('todo_get_term_seo_multilang')) {
                        $seo = todo_get_term_seo_multilang($term_id, 'post_tag', null, array('status_allowed' => array('approved', 'pending')));
                        if (is_array($seo) && !empty($seo['seo_description'])) {
                            return $clean_description((string) $seo['seo_description']);
                        }
                    }
                }
                if ($tag && isset($tag->description)) {
                    $vars['tag_description'] = $tag->description;
                } else {
                    $vars['tag_description'] = '';
                }
                if ($tag && isset($tag->name)) {
                    $vars['tag_name'] = $tag->name;
                }
            } else {
                // 其他页面类型，回退到原有逻辑
                $in_tax_archive = (
                    (function_exists('is_category') && is_category()) ||
                    (function_exists('is_tag') && is_tag()) ||
                    (function_exists('is_archive') && is_archive()) ||
                    (function_exists('todo_is_category') && todo_is_category()) ||
                    (function_exists('todo_is_tag') && todo_is_tag()) ||
                    (function_exists('todo_is_archive') && todo_is_archive())
                );
                if (!$in_tax_archive) {
                    $pid = 0;
                    if (isset($GLOBALS['todo_post']) && is_object($GLOBALS['todo_post']) && isset($GLOBALS['todo_post']->ID)) {
                        $pid = (int) $GLOBALS['todo_post']->ID;
                    } else {
                        $pid = function_exists('todo_get_query_context_value')
                            ? (int) todo_get_query_context_value('id', (int) todo_get_query_context_value('p', (function_exists('todo_get_int') ? todo_get_int('p', todo_get_int('id')) : 0)))
                            : (function_exists('todo_get_int') ? todo_get_int('p', todo_get_int('id')) : 0);
                    }
                    if ($pid > 0 && function_exists('get_post')) {
                        $p = get_post($pid);
                        if ($p && isset($p->post_content) && $p->post_content !== '') {
                            $raw = html_entity_decode($p->post_content, ENT_QUOTES, 'UTF-8');
                            $raw = strip_tags($raw);
                            $raw = preg_replace('/\s+/u', ' ', $raw);
                            return (string) truncate_string(trim($raw), 100, '');
                        }
                    }
                }
                return $clean_description($site_description);
            }

            // 处理格式字符串
            if (!empty($description_format)) {
                $result = todo_process_seo_format($description_format, $vars);
                if (!empty($result)) {
                    return $clean_description($result);
                }
            }

            return $clean_description($site_description);
        }

        // 指定文章ID的情况
        $ml = todo_get_post_multilang((int) $post_id, null, ['include_seo' => true, 'fallback' => true, 'prefer_custom_url' => true]);
        if (is_array($ml) && isset($ml['seo_description']) && $ml['seo_description'] !== '') {
            return $clean_description((string) $ml['seo_description']);
        }
        $pobj = function_exists('get_post') ? get_post($post_id) : null;
        if ($pobj && isset($pobj->seo_description) && $pobj->seo_description !== '') {
            return $clean_description((string) $pobj->seo_description);
        }
        $desc = function_exists('get_post_meta') ? get_post_meta($post_id, 'seo_description', true) : '';
        if ($desc !== '') {
            return $clean_description($desc);
        }

        if (function_exists('get_post')) {
            $p = $pobj ?: get_post($post_id);
            if ($p && isset($p->post_content) && $p->post_content !== '') {
                $raw = html_entity_decode($p->post_content, ENT_QUOTES, 'UTF-8');
                $raw = strip_tags($raw);
                $raw = preg_replace('/\s+/u', ' ', $raw);
                return (string) truncate_string(trim($raw), 100, '');
            }
        }

        return $clean_description($site_description);
    }
}

if (!function_exists('todo_process_seo_format')) {
    function todo_process_seo_format($format, $vars = [])
    {
        if (empty($format)) {
            return '';
        }

        // 默认变量
        $default_vars = [
            'site_title' => function_exists('todo_get_option') ? todo_get_option('site_title', '') : (function_exists('get_option') ? get_option('site_title', '') : ''),
            'site_description' => function_exists('todo_get_option') ? todo_get_option('site_description', '') : (function_exists('get_option') ? get_option('site_description', '') : ''),
            'separator' => function_exists('get_option') ? get_option('seo_title_separator', '-') : '-',
        ];

        // 合并变量
        $vars = array_merge($default_vars, $vars);

        // 替换变量
        $result = $format;

        // 解析行内多语言（在替换前对 format 和 vars 进行解析）
        if (function_exists('todo_map_inline_translations_deep')) {
            $result = todo_map_inline_translations_deep($result);
        }

        foreach ($vars as $key => $value) {
            // 解析变量值的行内多语言
            if (function_exists('todo_map_inline_translations_deep')) {
                $value = todo_map_inline_translations_deep($value);
            }

            // 如果值为空，替换为空字符串（而不是保留 %变量%）
            $replacement = ($value === '' || $value === null) ? '' : (string) $value;
            $result = str_replace('%' . $key . '%', $replacement, $result);
        }

        // 清理多余的空格和分隔符
        $separator = $vars['separator'];

        // 移除空值周围的分隔符模式：如 " - " 或 " -" 或 "- "（当前后都是空或空格时）
        // 先处理分隔符前后的空格
        $result = preg_replace('/\s*' . preg_quote($separator, '/') . '\s*/', ' ' . $separator . ' ', $result);

        // 移除连续的分隔符（如 " -  - "）
        $result = preg_replace('/(' . preg_quote($separator, '/') . '\s*){2,}/', $separator . ' ', $result);

        // 合并多个连续空格
        $result = preg_replace('/\s+/', ' ', $result);

        // 移除开头和结尾的分隔符及其周围空格
        $result = preg_replace('/^(\s*' . preg_quote($separator, '/') . '\s*)+/', '', $result);
        $result = preg_replace('/(\s*' . preg_quote($separator, '/') . '\s*)+$/', '', $result);

        // 最终清理
        $result = trim($result);

        return $result;
    }
}

if (!function_exists('get_full_page_title')) {
    function get_full_page_title()
    {
        $site_title = function_exists('todo_get_option') ? todo_get_option('site_title', '') : (function_exists('get_option') ? get_option('site_title', '') : '');
        if ($site_title === '') {
            $site_title = 'TodoCMS';
        }

        $pid = 0;
        if (isset($GLOBALS['todo_post']) && is_object($GLOBALS['todo_post']) && isset($GLOBALS['todo_post']->ID)) {
            $pid = (int) $GLOBALS['todo_post']->ID;
        } else {
            $pid = function_exists('todo_get_query_context_value')
                ? (int) todo_get_query_context_value('id', (int) todo_get_query_context_value('p', (function_exists('todo_get_int') ? todo_get_int('p', todo_get_int('id')) : 0)))
                : (function_exists('todo_get_int') ? todo_get_int('p', todo_get_int('id')) : 0);
        }

        // 获取SEO标题格式
        $title_format = '';
        $vars = ['site_title' => $site_title];

        // 根据页面类型获取对应的标题格式
        // 优先使用 todo_is_home() 进行首页检测（更可靠的回退逻辑）
        $is_home_page = false;
        if (function_exists('todo_is_home')) {
            $is_home_page = todo_is_home();
        } elseif (function_exists('is_home') && (is_home() || is_front_page())) {
            $is_home_page = true;
        }

        if ($is_home_page) {
            // 首页
            $title_format = function_exists('get_option') ? get_option('seo_home_title_format', '%site_title% %separator% %site_description%') : '%site_title% %separator% %site_description%';
            $vars['site_description'] = function_exists('todo_get_option') ? todo_get_option('site_description', '') : (function_exists('get_option') ? get_option('site_description', '') : '');
        } elseif ((function_exists('todo_is_single') && todo_is_single()) || (function_exists('is_single') && is_single())) {
            // 文章页
            $title_format = function_exists('get_option') ? get_option('seo_post_title_format', '%post_title% %separator% %site_title%') : '%post_title% %separator% %site_title%';
            $seo_title = '';
            if (function_exists('get_seo_title')) {
                $seo_title = $pid > 0 ? (string) get_seo_title($pid) : (string) get_seo_title();
            }
            if ($seo_title === '') {
                if (function_exists('todo_get_the_title')) {
                    $seo_title = (string) todo_get_the_title();
                }
                if ($seo_title === '' && $pid > 0) {
                    $p = function_exists('get_post') ? get_post($pid) : null;
                    if ($p && isset($p->post_title)) {
                        $seo_title = (string) $p->post_title;
                    }
                }
                if ($seo_title === '') {
                    global $post;
                    if ($post && isset($post->post_title)) {
                        $seo_title = (string) $post->post_title;
                    }
                }
            }
            $vars['post_title'] = $seo_title;
            // 获取分类信息
            if ($pid > 0 && function_exists('get_the_category')) {
                $categories = get_the_category($pid);
                if (!empty($categories) && isset($categories[0])) {
                    $vars['category'] = $categories[0]->name;
                }
            }
        } elseif ((function_exists('todo_is_page') && todo_is_page()) || (function_exists('is_page') && is_page())) {
            // 页面
            $title_format = function_exists('get_option') ? get_option('seo_page_title_format', '%page_title% %separator% %site_title%') : '%page_title% %separator% %site_title%';
            $seo_title = '';
            if (function_exists('get_seo_title')) {
                $seo_title = $pid > 0 ? (string) get_seo_title($pid) : (string) get_seo_title();
            }
            if ($seo_title === '') {
                if (function_exists('todo_get_the_title')) {
                    $seo_title = (string) todo_get_the_title();
                }
                if ($seo_title === '' && $pid > 0) {
                    $p = function_exists('get_post') ? get_post($pid) : null;
                    if ($p && isset($p->post_title)) {
                        $seo_title = (string) $p->post_title;
                    }
                }
                if ($seo_title === '') {
                    global $post;
                    if ($post && isset($post->post_title)) {
                        $seo_title = (string) $post->post_title;
                    }
                }
            }
            $vars['page_title'] = $seo_title;
        } elseif ((function_exists('todo_is_category') && todo_is_category()) || (function_exists('is_category') && is_category())) {
            // 分类页
            $title_format = function_exists('get_option') ? get_option('seo_category_title_format', '%category_name% %separator% %site_title%') : '%category_name% %separator% %site_title%';
            $category = function_exists('get_queried_object') ? get_queried_object() : null;
            $term_id = 0;
            if ($category) {
                if (isset($category->term_id)) {
                    $term_id = (int) $category->term_id;
                } elseif (isset($category->id)) {
                    $term_id = (int) $category->id;
                }
            }
            if ($category && isset($category->name)) {
                $vars['category_name'] = $category->name;
            } else {
                $vars['category_name'] = '';
            }
            if ($term_id > 0) {
                if (!empty($vars['category_name']) && function_exists('todo_translate')) {
                    $vars['category_name'] = todo_translate((string) $vars['category_name'], 'term.category.' . $term_id . '.name');
                }
                if (!function_exists('todo_get_term_seo_multilang')) {
                    @require_once ABSPATH . 'core/functions/multilang-fields.php';
                }
                if (function_exists('todo_get_term_seo_multilang')) {
                    $seo = todo_get_term_seo_multilang($term_id, 'category', null, array('status_allowed' => array('approved', 'pending')));
                    if (is_array($seo) && !empty($seo['seo_title'])) {
                        $vars['category_name'] = (string) $seo['seo_title'];
                    }
                }
            }
        } elseif ((function_exists('todo_is_tag') && todo_is_tag()) || (function_exists('is_tag') && is_tag())) {
            // 标签页
            $title_format = function_exists('get_option') ? get_option('seo_tag_title_format', '%tag_name% %separator% %site_title%') : '%tag_name% %separator% %site_title%';
            $tag = function_exists('get_queried_object') ? get_queried_object() : null;
            $term_id = 0;
            if ($tag) {
                if (isset($tag->term_id)) {
                    $term_id = (int) $tag->term_id;
                } elseif (isset($tag->id)) {
                    $term_id = (int) $tag->id;
                }
            }
            if ($tag && isset($tag->name)) {
                $vars['tag_name'] = $tag->name;
            } else {
                $vars['tag_name'] = '';
            }
            if ($term_id > 0) {
                if (!empty($vars['tag_name']) && function_exists('todo_translate')) {
                    $vars['tag_name'] = todo_translate((string) $vars['tag_name'], 'term.tag.' . $term_id . '.name');
                }
                if (!function_exists('todo_get_term_seo_multilang')) {
                    @require_once ABSPATH . 'core/functions/multilang-fields.php';
                }
                if (function_exists('todo_get_term_seo_multilang')) {
                    $seo = todo_get_term_seo_multilang($term_id, 'post_tag', null, array('status_allowed' => array('approved', 'pending')));
                    if (is_array($seo) && !empty($seo['seo_title'])) {
                        $vars['tag_name'] = (string) $seo['seo_title'];
                    }
                }
            }
        } elseif ((function_exists('todo_is_archive') && todo_is_archive()) || (function_exists('is_archive') && is_archive())) {
            // 归档页
            $title_format = function_exists('get_option') ? get_option('seo_archive_title_format', '%date% %separator% %site_title%') : '%date% %separator% %site_title%';
            $date = '';
            $y = 0;
            $m = 0;
            $d = 0;
            if (function_exists('todo_get_query_context_value')) {
                $y = (int) todo_get_query_context_value('archive_year', 0);
                $m = (int) todo_get_query_context_value('archive_month', 0);
                $d = (int) todo_get_query_context_value('archive_day', 0);
                if ($y <= 0) $y = (int) todo_get_query_context_value('year', 0);
                if ($m <= 0) $m = (int) todo_get_query_context_value('month', (int) todo_get_query_context_value('monthnum', 0));
                if ($d <= 0) $d = (int) todo_get_query_context_value('day', 0);
            }
            if ($y <= 0 && function_exists('todo_get_int')) {
                $y = todo_get_int('archive_year', todo_get_int('year', 0));
            }
            if ($m <= 0 && function_exists('todo_get_int')) {
                $m = todo_get_int('archive_month', todo_get_int('month', todo_get_int('monthnum', 0)));
            }
            if ($d <= 0 && function_exists('todo_get_int')) {
                $d = todo_get_int('archive_day', todo_get_int('day', 0));
            }

            if ($y > 0) {
                if ($m > 0) {
                    if ($d > 0) {
                        $date = $y . '/' . $m . '/' . $d;
                    } else {
                        $date = $y . '/' . $m;
                    }
                } else {
                    $date = $y;
                }
            }
            $vars['date'] = $date;
        } elseif ((function_exists('todo_is_search') && todo_is_search()) || (function_exists('is_search') && is_search())) {
            // 搜索结果页
            $title_format = function_exists('get_option') ? get_option('seo_search_title_format', '搜索: %search_term% %separator% %site_title%') : '搜索: %search_term% %separator% %site_title%';
            $search_term = '';
            if (function_exists('get_search_query') && get_search_query() !== '') {
                $search_term = (string) get_search_query();
            } elseif (function_exists('todo_get_search_context') && todo_get_search_context() !== '') {
                $search_term = (string) todo_get_search_context();
            } elseif (function_exists('todo_get_search_query')) {
                $search_term = (string) todo_get_search_query();
            }
            $vars['search_term'] = $search_term;
        } elseif ((function_exists('todo_is_404') && todo_is_404()) || (function_exists('is_404') && is_404())) {
            // 404页面
            $title_format = function_exists('get_option') ? get_option('seo_404_title_format', '页面未找到 %separator% %site_title%') : '页面未找到 %separator% %site_title%';
        } else {
            // 默认：视为首页（当没有其他页面类型匹配时）
            $title_format = function_exists('get_option') ? get_option('seo_home_title_format', '%site_title% %separator% %site_description%') : '%site_title% %separator% %site_description%';
            $vars['site_description'] = function_exists('todo_get_option') ? todo_get_option('site_description', '') : (function_exists('get_option') ? get_option('site_description', '') : '');
        }

        // 如果格式为空，使用默认格式
        if (empty($title_format)) {
            // 回退到旧的逻辑
            $seo_data = function_exists('get_seo_data') ? get_seo_data() : null;
            if (is_array($seo_data) && !empty($seo_data['title'])) {
                return (string) $seo_data['title'];
            }
            return $site_title;
        }

        // 处理格式字符串
        $result = todo_process_seo_format($title_format, $vars);

        // 如果结果为空，回退到站点标题
        if (empty($result)) {
            return $site_title;
        }

        return $result;
    }
}

if (!function_exists('todo_seo_meta_tags')) {
    function todo_seo_meta_tags()
    {
        // 统一：使用 get_seo_data() 提供的语言感知 SEO 数据
        $seo = function_exists('get_seo_data') ? get_seo_data() : array();
        $seo_title = function_exists('get_full_page_title') ? get_full_page_title() : (isset($seo['title']) ? (string) $seo['title'] : '');
        $seo_keywords = isset($seo['keywords']) ? (string) $seo['keywords'] : (function_exists('todo_get_option') ? (string) todo_get_option('site_keywords', '') : (string) get_option('site_keywords', ''));
        $seo_description = isset($seo['description']) ? (string) $seo['description'] : (function_exists('todo_get_option') ? (string) todo_get_option('site_description', '') : (function_exists('get_option') ? (string) get_option('site_description', '') : ''));

        if ($seo_keywords !== '') {
            echo '<meta name="keywords" content="' . esc_attr($seo_keywords) . '">' . "\n";
        }
        if ($seo_description !== '') {
            echo '<meta name="description" content="' . esc_attr($seo_description) . '">' . "\n";
        }

        // Open Graph：语言感知标题/描述 + 规范 URL
        if ((function_exists('todo_is_single') && todo_is_single()) || (function_exists('is_single') && is_single())) {
            global $post;
            echo '<meta property="og:title" content="' . esc_attr($seo_title) . '">' . "\n";
            echo '<meta property="og:description" content="' . esc_attr($seo_description) . '">' . "\n";
            echo '<meta property="og:type" content="article">' . "\n";
            $og_url = function_exists('todo_get_canonical_url') ? todo_get_canonical_url() : (function_exists('get_permalink') && isset($post->ID) ? get_permalink($post->ID) : '');
            if ($og_url !== '') {
                echo '<meta property="og:url" content="' . esc_url($og_url) . '">' . "\n";
            }
            $thumb = '';
            if (isset($post) && is_object($post)) {
                if (function_exists('todo_match_og_image') && !empty($post->featured_image)) {
                    $thumb = todo_match_og_image($post->featured_image);
                }
                if (!$thumb && isset($post->ID) && function_exists('has_post_thumbnail') && has_post_thumbnail($post->ID)) {
                    $thumb = function_exists('get_the_post_thumbnail_url') ? get_the_post_thumbnail_url($post->ID, 'large') : '';
                }
            }
            if ($thumb) {
                echo '<meta property="og:image" content="' . esc_url($thumb) . '">' . "\n";
                if ($seo_description !== '') {
                    echo '<meta property="og:image:alt" content="' . esc_attr($seo_description) . '">' . "\n";
                }
            }
        } elseif ((function_exists('todo_is_page') && todo_is_page()) || (function_exists('is_page') && is_page())) {
            $og_url = function_exists('todo_get_canonical_url') ? todo_get_canonical_url() : (function_exists('get_current_url') ? get_current_url() : '');
            echo '<meta property="og:title" content="' . esc_attr($seo_title) . '">' . "\n";
            echo '<meta property="og:description" content="' . esc_attr($seo_description) . '">' . "\n";
            echo '<meta property="og:type" content="website">' . "\n";
            if ($og_url !== '') {
                echo '<meta property="og:url" content="' . esc_url($og_url) . '">' . "\n";
            }
        }
    }
}
