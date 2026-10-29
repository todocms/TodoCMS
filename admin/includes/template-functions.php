<?php
/**
 * TodoCMS Template Functions for TodoCMS
 *
 * @package TodoCMS
 * @subpackage Administration
 */

// Don't load directly
if (!defined('ABSPATH')) {
    die('-1');
}

/**
 * Get content templates
 */
function get_page_templates($post = null, $post_type = 'post') {
    return array(
        'default' => 'Default Template',
        'single-custom.php' => 'Custom Content Template',
        'single-full-width.php' => 'Full Width Template'
    );
}

/**
 * Get post type archive template
 */
function get_post_type_archive_template() {
    return get_query_template('archive');
}

/**
 * Get query template
 */
function get_query_template($type, $templates = array()) {
    $type = preg_replace('|[^a-z0-9-]+|', '', $type);
    
    if (empty($templates)) {
        $templates = array("{$type}.php");
    }
    
    /**
     * Filters the list of template filenames that are searched for when retrieving a template to use.
     *
     * The last element in the array should always be the fallback template for this query type.
     *
     * Possible values for `$type` include: 'index', '404', 'archive', 'author', 'category', 'tag', 'taxonomy', 'date',
     * 'home', 'frontpage', 'page', 'paged', 'search', 'single', 'singular', and 'attachment'.
     *
     * @since 1.5.0
     * @since 4.7.0 The `$type` parameter was added.
     *
     * @param array  $templates A list of template candidates, in descending order of priority.
     * @param string $type      The template type.
     */
    $templates = apply_filters("{$type}_template_hierarchy", $templates, $type);
    
    $template = locate_template($templates);
    
    /**
     * Filters the path of the queried template by type.
     *
     * The dynamic portion of the hook name, `$type`, refers to the filename -- minus the file
     * extension and any non-alphanumeric characters delimiting words -- of the file to load.
     * This hook also applies to the following `$type`s:
     *
     * - 404
     * - archive
     * - author
     * - category
     * - date
     * - home
     * - index
     * - page
     * - paged
     * - search
     * - single
     * - singular
     * - tag
     * - taxonomy
     *
     * @since 1.5.0
     * @since 4.8.0 The `$type` parameter was added.
     *
     * @param string $template Path to the template. See locate_template().
     * @param string $type     Filename without extension.
     */
    return apply_filters("{$type}_template", $template, $type);
}

/**
 * Locate template
 */
function locate_template($template_names, $load = false, $require_once = true) {
    $located = '';
    
    foreach ((array) $template_names as $template_name) {
        if (!$template_name) {
            continue;
        }
        
        if (file_exists(STYLESHEETPATH . '/' . $template_name)) {
            $located = STYLESHEETPATH . '/' . $template_name;
            break;
        } elseif (file_exists(TEMPLATEPATH . '/' . $template_name)) {
            $located = TEMPLATEPATH . '/' . $template_name;
            break;
        } elseif (file_exists(ABSPATH . TODOINC . '/theme-compat/' . $template_name)) {
            $located = ABSPATH . TODOINC . '/theme-compat/' . $template_name;
            break;
        }
    }
    
    if ($load && '' != $located) {
        load_template($located, $require_once);
    }
    
    return $located;
}

/**
 * Load template
 */
if (!function_exists('load_template')) {
    function load_template($_template_file, $require_once = true) {
        global $posts, $post, $todo_did_header, $todo_query, $todo_rewrite, $tododb, $todo_version, $todo, $id, $comment, $user_ID;
        
        // 检查 $todo_query 是否已初始化且有 query_vars 属性
        if (isset($todo_query) && is_object($todo_query) && isset($todo_query->query_vars) && is_array($todo_query->query_vars)) {
            /*
            * This use of extract() cannot be removed. There are many possible ways that
            * templates could depend on variables that it creates existing, and no way to
            * detect and deprecate it.
            *
            * Passing the EXTR_SKIP flag is the safest option, ensuring globals and
            * function arguments are not overwritten.
            */
            extract($todo_query->query_vars, EXTR_SKIP);
        }
        
        if ($require_once) {
            require_once($_template_file);
        } else {
            require($_template_file);
        }
    }
}

/**
 * Get template part
 */
if (!function_exists('get_template_part')) {
    function get_template_part($slug, $name = null, $args = array()) {
        /**
         * Fires before the specified template part file is loaded.
         *
         * The dynamic portion of the hook name, `$slug`, refers to the slug name
         * for the generic template part.
         *
         * @since 3.0.0
         * @since 5.5.0 The `$args` parameter was added.
         *
         * @param string      $slug The slug name for the generic template.
         * @param string|null $name The name of the specialized template.
         * @param array       $args Additional arguments passed to the template.
         */
        do_action("get_template_part_{$slug}", $slug, $name, $args);
        
        $templates = array();
        $name = (string) $name;
        if ('' !== $name) {
            $templates[] = "{$slug}-{$name}.php";
        }
        
        $templates[] = "{$slug}.php";
        
        /**
         * Fires before an attempt is made to locate and load a template part.
         *
         * @since 5.2.0
         * @since 5.5.0 The `$args` parameter was added.
         *
         * @param string   $slug      The slug name for the generic template.
         * @param string   $name      The name of the specialized template.
         * @param string[] $templates Array of template files to search for, in order.
         * @param array    $args      Additional arguments passed to the template.
         */
        do_action('get_template_part', $slug, $name, $templates, $args);
        
        if (!empty($args) && is_array($args) && isset($args['load']) && false === $args['load']) {
            return locate_template($templates, false, false, $args);
        }
        
        return locate_template($templates, true, false, $args);
    }
}

/**
 * Get header
 */
if (!function_exists('get_header')) {
    function get_header($name = null, $args = array()) {
        /**
         * Fires before the header template file is loaded.
         *
         * @since 2.1.0
         * @since 2.8.0 The `$name` parameter was added.
         * @since 5.5.0 The `$args` parameter was added.
         *
         * @param string|null $name Name of the specific header file to use. null for the default header.
         * @param array       $args Additional arguments passed to the header template.
         */
        do_action('get_header', $name, $args);
        
        $templates = array();
        $name = (string) $name;
        if ('' !== $name) {
            $templates[] = "header-{$name}.php";
        }
        
        $templates[] = 'header.php';
        
        locate_template($templates, true, true, $args);
    }
}

/**
 * Get footer
 */
if (!function_exists('get_footer')) {
    function get_footer($name = null, $args = array()) {
        /**
         * Fires before the footer template file is loaded.
         *
         * @since 2.1.0
         * @since 2.8.0 The `$name` parameter was added.
         * @since 5.5.0 The `$args` parameter was added.
         *
         * @param string|null $name Name of the specific footer file to use. null for the default footer.
         * @param array       $args Additional arguments passed to the footer template.
         */
        do_action('get_footer', $name, $args);
        
        $templates = array();
        $name = (string) $name;
        if ('' !== $name) {
            $templates[] = "footer-{$name}.php";
        }
        
        $templates[] = 'footer.php';
        
        locate_template($templates, true, true, $args);
    }
}

/**
 * Get sidebar
 */
if (!function_exists('get_sidebar')) {
    function get_sidebar($name = null, $args = array()) {
        /**
         * Fires before the sidebar template file is loaded.
         *
         * @since 2.2.0
         * @since 2.8.0 The `$name` parameter was added.
         * @since 5.5.0 The `$args` parameter was added.
         *
         * @param string|null $name Name of the specific sidebar file to use. null for the default sidebar.
         * @param array       $args Additional arguments passed to the sidebar template.
         */
        do_action('get_sidebar', $name, $args);
        
        $templates = array();
        $name = (string) $name;
        if ('' !== $name) {
            $templates[] = "sidebar-{$name}.php";
        }
        
        $templates[] = 'sidebar.php';
        
        locate_template($templates, true, true, $args);
    }
}

/**
 * Get search form
 */
if (!function_exists('get_search_form')) {
    function get_search_form($echo = true) {
        /**
         * Fires before the search form is retrieved, at the start of get_search_form().
         *
         * @since 2.7.0 as 'get_search_form' action.
         * @since 3.6.0
         * @since 5.5.0 The `$args` parameter was added.
         *
         * @link http://core.trac.todocms.com/ticket/19321
         *
         * @param array $args The array of arguments for building the search form.
         *                    Default empty array.
         */
        do_action('pre_get_search_form', array());
        
        $format = theme_supports('html5', 'search-form') ? 'html5' : 'xhtml';
        
        /**
         * Filters the HTML format of the search form.
         *
         * @since 3.6.0
         * @since 5.5.0 The `$args` parameter was added.
         *
         * @param string $format The type of markup to use in the search form.
         *                       Accepts 'html5', 'xhtml'.
         * @param array  $args   The array of arguments for building the search form.
         *                       Default empty array.
         */
        $format = apply_filters('search_form_format', $format, array());
        
        $search_form_template = locate_template('searchform.php');
        if ('' != $search_form_template) {
            require($search_form_template);
            $form = ob_get_clean();
        } else {
            // Build a basic search form
            if ('html5' == $format) {
                $form = '<form role="search" method="get" class="search-form" action="' . esc_url(home_url('/')) . '">
    ';
                $form .= '<label>
    ';
                $form .= '<span class="screen-reader-text">' . _x('Search for:', 'label') . '</span>
    ';
                $form .= '<input type="search" class="search-field" placeholder="' . esc_attr_x('Search &hellip;', 'placeholder') . '" value="' . get_search_query() . '" name="s" />
    ';
                $form .= '</label>
    ';
                $form .= '<input type="submit" class="search-submit" value="' . esc_attr_x('Search', 'submit button') . '" />
    ';
                $form .= '</form>';
            } else {
                $form = '<form role="search" method="get" id="searchform" class="searchform" action="' . esc_url(home_url('/')) . '">
    ';
                $form .= '<div>
    ';
                $form .= '<label class="screen-reader-text" for="s">' . _x('Search for:', 'label') . '</label>
    ';
                $form .= '<input type="text" value="' . get_search_query() . '" name="s" id="s" />
    ';
                $form .= '<input type="submit" id="searchsubmit" value="' . esc_attr_x('Search', 'submit button') . '" />
    ';
                $form .= '</div>
    ';
                $form .= '</form>';
            }
        }
        
        /**
         * Filters the HTML output of the search form.
         *
         * @since 2.7.0
         * @since 5.5.0 The `$args` parameter was added.
         *
         * @param string $form The search form HTML output.
         * @param array  $args The array of arguments for building the search form.
         *                     Default empty array.
         */
        $result = apply_filters('get_search_form', $form, array());
        
        if (null === $result) {
            $result = $form;
        }
        
        if ($echo) {
            echo $result;
        } else {
            return $result;
        }
    }
}

/**
 * Get search query
 */
if (!function_exists('get_search_query')) {
    function get_search_query($escaped = true) {
        /**
         * Filters the contents of the search query variable.
         *
         * @since 2.3.0
         *
         * @param mixed $search Contents of the search query variable.
         */
        $query = apply_filters('get_search_query', get_query_var('s'));
        
        if ($escaped) {
            $query = esc_attr($query);
        }
        
        return $query;
    }
}

/**
 * Home URL
 */
if (!function_exists('home_url')) {
    function home_url($path = '', $scheme = null) {
        return get_home_url(null, $path, $scheme);
    }
}

/**
 * Get home URL
 */
function get_home_url($blog_id = null, $path = '', $scheme = null) {
    $orig_scheme = $scheme;
    
    if (empty($blog_id) || !is_multisite()) {
        $url = get_option('home');
    } else {
        switch_to_blog($blog_id);
        $url = get_option('home');
        restore_current_blog();
    }
    
    if (!in_array($scheme, array('http', 'https', 'relative'))) {
        if (is_ssl() && !is_admin() && 'index.php' != $GLOBALS['pagenow']) {
            $scheme = 'https';
        } else {
            $scheme = parse_url($url, PHP_URL_SCHEME);
        }
    }
    
    $url = set_url_scheme($url, $scheme);
    
    if ($path && is_string($path)) {
        $url .= '/' . ltrim($path, '/');
    }
    
    /**
     * Filters the home URL.
     *
     * @since 3.0.0
     *
     * @param string      $url         The complete home URL including scheme and path.
     * @param string      $path        Path relative to the home URL. Blank string if no path is specified.
     * @param string|null $orig_scheme Scheme to give the home URL context. Accepts 'http', 'https',
     *                                  'relative', 'rest', or null.
     * @param int|null    $blog_id     Site ID, or null for the current site.
     */
    return apply_filters('home_url', $url, $path, $orig_scheme, $blog_id);
}

/**
 * Set URL scheme
 */
function set_url_scheme($url, $scheme = null) {
    $orig_scheme = $scheme;
    
    if (!$scheme) {
        $scheme = is_ssl() ? 'https' : 'http';
    } elseif ($scheme === 'admin' || $scheme === 'login' || $scheme === 'login_post' || $scheme === 'rpc') {
        $scheme = is_ssl() || force_ssl_admin() ? 'https' : 'http';
    } elseif ($scheme !== 'http' && $scheme !== 'https' && $scheme !== 'relative') {
        $scheme = is_ssl() ? 'https' : 'http';
    }
    
    $url = trim($url);
    if (substr($url, 0, 2) === '//') {
        $url = 'http:' . $url;
    }
    
    if ('relative' == $scheme) {
        $url = ltrim(preg_replace('#^\w+://[^/]*#', '', $url));
        if ($url !== '' && $url[0] === '/') {
            $url = '/' . ltrim($url, '/');
        }
    } else {
        $url = preg_replace('#^\w+://#', $scheme . '://', $url);
    }
    
    /**
     * Filters the resulting URL after setting the scheme.
     *
     * @since 3.4.0
     *
     * @param string      $url         The complete URL including scheme and path.
     * @param string      $scheme      Scheme applied to the URL. One of 'http', 'https', or 'relative'.
     * @param string|null $orig_scheme Scheme requested for the URL. One of 'http', 'https', 'relative' or null.
     */
    return apply_filters('set_url_scheme', $url, $scheme, $orig_scheme);
}

/**
 * Is SSL
 */
function is_ssl() {
    if (isset($_SERVER['HTTPS'])) {
        if ('on' == strtolower($_SERVER['HTTPS'])) {
            return true;
        }
        
        if ('1' == $_SERVER['HTTPS']) {
            return true;
        }
    } elseif (isset($_SERVER['SERVER_PORT']) && ('443' == $_SERVER['SERVER_PORT'])) {
        return true;
    }
    
    return false;
}

/**
 * Force SSL admin
 */
function force_ssl_admin() {
    return defined('FORCE_SSL_ADMIN') && FORCE_SSL_ADMIN;
}

/**
 * Is multisite
 */
if (!function_exists('is_multisite')) {
    function is_multisite() {
        if (defined('MULTISITE')) {
            return MULTISITE;
        }
        
        if (defined('SUBDOMAIN_INSTALL') || defined('VHOST') || defined('SUNRISE')) {
            return true;
        }
        
        return false;
    }
}

/**
 * Switch to blog
 */
function switch_to_blog($new_blog_id) {
    // Simplified for single site
    return true;
}

/**
 * Restore current blog
 */
function restore_current_blog() {
    // Simplified for single site
    return true;
}

/**
 * Get template directory
 */
function get_template_directory() {
    $template = get_template();
    $theme_root = get_theme_root($template);
    
    return "$theme_root/$template";
}

/**
 * Get template directory URI
 */
function get_template_directory_uri() {
    $template = get_template();
    $theme_root_uri = get_theme_root_uri($template);
    
    return "$theme_root_uri/$template";
}

/**
 * Get stylesheet directory
 */
function get_stylesheet_directory() {
    $stylesheet = get_stylesheet();
    $theme_root = get_theme_root($stylesheet);
    
    return "$theme_root/$stylesheet";
}

/**
 * Get stylesheet directory URI
 */
function get_stylesheet_directory_uri() {
    $stylesheet = get_stylesheet();
    $theme_root_uri = get_theme_root_uri($stylesheet);
    
    return "$theme_root_uri/$stylesheet";
}

/**
 * Get template
 */
function get_template() {
    /**
     * Filters the name of the current theme.
     *
     * @since 1.5.0
     *
     * @param string $template Current theme's directory name.
     */
    // 不再从 options 读取，统一从主题系统获取（带 active_theme 过滤器）
    return apply_filters('template', get_theme_name());
}

/**
 * Get stylesheet
 */
function get_stylesheet() {
    /**
     * Filters the name of the current theme.
     *
     * @since 1.5.0
     *
     * @param string $stylesheet Name of the current theme.
     */
    // 不再从 options 读取，统一从主题系统获取（带 active_theme 过滤器）
    return apply_filters('stylesheet', get_theme_name());
}

/**
 * Get theme root
 */
function get_theme_root($stylesheet_or_template = null) {
    global $todo_theme_directories;
    
    if ($stylesheet_or_template && $theme_root = get_raw_theme_root($stylesheet_or_template)) {
        // Always prepend TODO_CONTENT_DIR unless the root currently registered as a theme directory.
        // This gives relative theme roots the benefit of the doubt when things go haywire.
        if (!in_array($theme_root, (array) $todo_theme_directories)) {
            $theme_root = TODO_CONTENT_DIR . $theme_root;
        }
    } else {
        $theme_root = TODO_CONTENT_DIR . '/themes';
    }
    
    /**
     * Filters the absolute path to the themes directory.
     *
     * @since 1.5.0
     *
     * @param string $theme_root Absolute path to themes directory.
     */
    return apply_filters('theme_root', $theme_root);
}

/**
 * Get theme root URI
 */
function get_theme_root_uri($stylesheet_or_template = null, $theme_root = null) {
    global $todo_theme_directories;
    
    if ($stylesheet_or_template && $theme_root = get_raw_theme_root($stylesheet_or_template)) {
        if (in_array($theme_root, (array) $todo_theme_directories)) {
            // Absolute path. Make an educated guess. YMMV -- but note the filter below.
            if (0 === strpos($theme_root, TODO_CONTENT_DIR)) {
                $theme_root_uri = content_url(str_replace(TODO_CONTENT_DIR, '', $theme_root));
            } elseif (0 === strpos($theme_root, ABSPATH)) {
                $theme_root_uri = todo_url(str_replace(ABSPATH, '', $theme_root));
            } elseif (0 === strpos($theme_root, TODO_PLUGIN_DIR) || 0 === strpos($theme_root, TODOMU_PLUGIN_DIR)) {
                $theme_root_uri = plugins_url(basename($theme_root), $theme_root);
            } else {
                $theme_root_uri = $theme_root;
            }
        } else {
            $theme_root_uri = content_url($theme_root);
        }
    } else {
        $theme_root_uri = content_url('themes');
    }
    
    /**
     * Filters the URI for themes directory.
     *
     * @since 1.5.0
     *
     * @param string $theme_root_uri         The URI for themes directory.
     * @param string $siteurl                TodoCMS web address which is set in General Options.
     * @param string $stylesheet_or_template The stylesheet or template name of the theme.
     */
    return apply_filters('theme_root_uri', $theme_root_uri, get_option('siteurl'), $stylesheet_or_template);
}

/**
 * Get raw theme root
 */
function get_raw_theme_root($stylesheet_or_template, $skip_cache = false) {
    global $todo_theme_directories;

    // Prefer unified $todo_theme_directories; remove legacy option dependency.
    if (is_array($todo_theme_directories) && count($todo_theme_directories) > 0) {
        foreach ((array) $todo_theme_directories as $dir) {
            $dir = todo_normalize_path($dir);

            // Build a path to check if the theme resides under this directory.
            if (0 === strpos($dir, TODO_CONTENT_DIR) || 0 === strpos($dir, ABSPATH) || 0 === strpos($dir, TODO_PLUGIN_DIR) || 0 === strpos($dir, TODOMU_PLUGIN_DIR)) {
                $test = rtrim($dir, '/') . '/' . $stylesheet_or_template;
            } else {
                $test = TODO_CONTENT_DIR . rtrim($dir, '/') . '/' . $stylesheet_or_template;
            }

            if (is_dir($test)) {
                // Return the raw directory (absolute or relative as registered).
                return $dir;
            }
        }
    }

    // Fallback: default themes directory under content.
    return '/themes';
}

/**
 * Content URL
 */
function content_url($path = '') {
    $url = set_url_scheme(TODO_CONTENT_URL);
    
    if ($path && is_string($path)) {
        $url .= '/' . ltrim($path, '/');
    }
    
    /**
     * Filters the URL to the content directory.
     *
     * @since 2.8.0
     *
     * @param string $url  The complete URL to the content directory including scheme and path.
     * @param string $path Path relative to the URL to the content directory. Blank string
     *                     if no path is specified.
     */
    return apply_filters('content_url', $url, $path);
}

/**
 * Plugins URL
 */
function plugins_url($path = '', $plugin = '') {
    $path = todo_normalize_path($path);
    $plugin = todo_normalize_path($plugin);
    $mu_plugin_dir = todo_normalize_path(TODOMU_PLUGIN_DIR);
    
    if (!empty($plugin) && 0 === strpos($plugin, $mu_plugin_dir)) {
        $url = TODOMU_PLUGIN_URL;
    } else {
        $url = TODO_PLUGIN_URL;
    }
    
    $url = set_url_scheme($url);
    
    if (!empty($plugin) && is_string($plugin)) {
        $folder = dirname(plugin_basename($plugin));
        if ('.' != $folder) {
            $url .= '/' . ltrim($folder, '/');
        }
    }
    
    if ($path && is_string($path)) {
        $url .= '/' . ltrim($path, '/');
    }
    
    /**
     * Filters the URL to the plugins directory.
     *
     * @since 2.8.0
     *
     * @param string $url    The complete URL to the plugins directory including scheme and path.
     * @param string $path   Path relative to the URL to the plugins directory. Blank string
     *                       if no path is specified.
     * @param string $plugin The plugin file path to be relative to. Blank string if no plugin
     *                       is specified.
     */
    return apply_filters('plugins_url', $url, $path, $plugin);
}

/**
 * Plugin basename
 */
function plugin_basename($file) {
    global $todo_plugin_paths;
    
    // $todo_plugin_paths contains normalized paths.
    $file = todo_normalize_path($file);
    
    arsort($todo_plugin_paths);
    
    foreach ($todo_plugin_paths as $dir => $realdir) {
        if (strpos($file, $realdir) === 0) {
            $file = $dir . substr($file, strlen($realdir));
        }
    }
    
    $plugin_dir = todo_normalize_path(TODO_PLUGIN_DIR);
    $mu_plugin_dir = todo_normalize_path(TODOMU_PLUGIN_DIR);
    
    // Get relative path from plugins directory.
    $file = preg_replace('#^' . preg_quote($plugin_dir, '#') . '/|^' . preg_quote($mu_plugin_dir, '#') . '/#', '', $file);
    $file = trim($file, '/');
    return $file;
}

/**
 * Normalize path
 */
function todo_normalize_path($path) {
    $wrapper = '';
    if (todo_is_stream($path)) {
        list($wrapper, $path) = explode('://', $path, 2);
        $wrapper .= '://';
    }
    
    // Standardise all paths to use /
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('|(?<=.)/+|', '/', $path);
    
    // Replace multiple slashes down to a singular, allowing for network shares having two slashes.
    $path = preg_replace('|^([^/]*)//+|', '\\1/', $path);
    
    return $wrapper . $path;
}

/**
 * Is stream
 */
function todo_is_stream($path) {
    $scheme_separator = strpos($path, '://');
    
    if (false === $scheme_separator) {
        // $path isn't a stream
        return false;
    }
    
    $stream = substr($path, 0, $scheme_separator);
    
    return in_array($stream, stream_get_wrappers(), true);
}

/**
 * Translate with context
 */
function _x($text, $context, $domain = 'default') {
    return translate_with_gettext_context($text, $context, $domain);
}

/**
 * Translate with gettext context
 */
function translate_with_gettext_context($text, $context, $domain = 'default') {
    $translations = get_translations_for_domain($domain);
    $translation = $translations->translate($text, $context);
    
    /**
     * Filters text with its translation based on context information.
     *
     * @since 2.8.0
     *
     * @param string $translation Translated text.
     * @param string $text        Text to translate.
     * @param string $context     Context information for the translators.
     * @param string $domain      Text domain. Unique identifier for retrieving translated strings.
     */
    return apply_filters('gettext_with_context', $translation, $text, $context, $domain);
}

/**
 * Get translations for domain
 */
function get_translations_for_domain($domain) {
    global $l10n;
    
    if (isset($l10n[$domain]) || (is_admin() && isset($l10n[$domain]))) {
        return $l10n[$domain];
    }
    
    return new NOOP_Translations;
}

/**
 * NOOP Translations class
 */
class NOOP_Translations {
    public function translate($text, $context = null) {
        return $text;
    }
    
    public function translate_plural($singular, $plural, $count, $context = null) {
        return 1 == $count ? $singular : $plural;
    }
}

// Define template path constants if not already defined
if (!defined('STYLESHEETPATH')) {
    define('STYLESHEETPATH', get_stylesheet_directory());
}

if (!defined('TEMPLATEPATH')) {
    define('TEMPLATEPATH', get_template_directory());
}

if (!defined('TODO_CONTENT_DIR')) {
    define('TODO_CONTENT_DIR', ABSPATH . 'content');
}

if (!defined('TODO_CONTENT_URL')) {
    define('TODO_CONTENT_URL', get_option('siteurl') . '/content');
}

if (!defined('TODO_PLUGIN_DIR')) {
    define('TODO_PLUGIN_DIR', TODO_CONTENT_DIR . '/plugins');
}

if (!defined('TODO_PLUGIN_URL')) {
    define('TODO_PLUGIN_URL', TODO_CONTENT_URL . '/plugins');
}

if (!defined('TODOMU_PLUGIN_DIR')) {
    define('TODOMU_PLUGIN_DIR', TODO_CONTENT_DIR . '/mu-plugins');
}

if (!defined('TODOMU_PLUGIN_URL')) {
    define('TODOMU_PLUGIN_URL', TODO_CONTENT_URL . '/mu-plugins');
}

if (!defined('TODOINC')) {
    define('TODOINC', 'todo-includes');
}