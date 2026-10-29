<?php
/**
 * HTML模板标签处理器
 *
 * 处理HTML静态模板中的所有自定义标签
 *
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // 禁止直接访问
}

class CMS_HTML_Template_Tags
{
    private static $instance = null;

    private static $media_info_cache = [];

    private static $product_model_cache = [];

    private static $model_row_cache = [];

    private $global_data = [];

    private $field_cache = [];

    private $last_single_ads = null;

    private $todo_adjacent_post = null;

    private $last_group_ads_ads = null;

    private $include_depth = 0;

    private $max_include_depth = 10;

    private function normalize_media_value_to_url($value)
    {
        if (is_string($value)) {
            $s = trim($value);
            if ($s === '' || $s === '[]') {
                return '';
            }
            if (strpos($s, 'http://') === 0 || strpos($s, 'https://') === 0 || strpos($s, '/') === 0) {
                return $s;
            }
            if ($s[0] === '{' || $s[0] === '[') {
                $decoded = json_decode($s, true);
                if (is_array($decoded)) {
                    $value = $decoded;
                } else {
                    return '';
                }
            } else {
                return '';
            }
        }

        if (is_array($value)) {
            if (isset($value['url']) && is_string($value['url']) && $value['url'] !== '') {
                return $value['url'];
            }
            $first = reset($value);
            if (is_array($first) && isset($first['url']) && is_string($first['url']) && $first['url'] !== '') {
                return $first['url'];
            }
            if (is_string($first) && $first !== '') {
                return $first;
            }
        }

        return '';
    }

    /**
     * 获取单例实例
     *
     * @return CMS_HTML_Template_Tags
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 解析国家与地区下拉框选项 <option> ({{todo:country_options}})
     */
    public function parse_country_options($attrs = [], $data = [])
    {
        if (!function_exists('todo_render_country_options')) {
            $f = (defined('ABSPATH') ? ABSPATH : dirname(__DIR__, 2) . '/') . 'core/functions/user-functions.php';
            if (file_exists($f)) {
                require_once $f;
            }
        }
        $selected = isset($attrs['selected']) ? $attrs['selected'] : '';
        if (empty($selected) && is_array($data)) {
            if (isset($data['address']['country_code'])) {
                $selected = $data['address']['country_code'];
            } elseif (isset($data['country_code'])) {
                $selected = $data['country_code'];
            } elseif (isset($data['country'])) {
                $selected = $data['country'];
            }
        }
        if (function_exists('todo_render_country_options')) {
            return todo_render_country_options($selected);
        }
        return '';
    }

    /**
     * 构造函数
     */
    private function __construct()
    {
        $this->init_global_data();
    }

    /**
     * 初始化全局数据
     */
    private function init_global_data()
    {
        global $post, $todo_query;

        // 只设置基础数据，其他数据延迟加载
        $this->global_data = [
            'post' => $post,
            'query' => $todo_query,
        ];
    }

    /**
     * 获取延迟加载的全局数据
     */
    private function get_lazy_global_data($key)
    {
        if (!isset($this->global_data[$key])) {
            switch ($key) {
                case 'is_home':
                    $this->global_data[$key] = function_exists('is_home') ? is_home() : false;
                    break;
                case 'is_single':
                    $this->global_data[$key] = function_exists('is_single') ? is_single() : false;
                    break;
                case 'is_page':
                    $this->global_data[$key] = function_exists('is_page') ? is_page() : false;
                    break;
                case 'is_category':
                    $this->global_data[$key] = function_exists('is_category') ? is_category() : false;
                    break;
                case 'is_tag':
                    $this->global_data[$key] = function_exists('is_tag') ? is_tag() : false;
                    break;
                case 'is_archive':
                    $this->global_data[$key] = function_exists('is_archive') ? is_archive() : false;
                    break;
                case 'is_search':
                    $this->global_data[$key] = function_exists('is_search') ? is_search() : false;
                    break;
                case 'is_paged':
                    $this->global_data[$key] = function_exists('todo_is_paged') ? todo_is_paged() : false;
                    break;
                case 'is_author':
                    $this->global_data[$key] = function_exists('todo_is_author') ? todo_is_author() : false;
                    break;
                case 'is_404':
                    $this->global_data[$key] = function_exists('is_404') ? is_404() : false;
                    break;
                case 'is_admin':
                    $this->global_data[$key] = function_exists('is_admin') ? is_admin() : false;
                    break;
                case 'is_user_logged_in':
                    $this->global_data[$key] = function_exists('is_user_logged_in') ? is_user_logged_in() : false;
                    break;
                default:
                    $this->global_data[$key] = null;
            }
        }
        return $this->global_data[$key];
    }

    /**
     * 设置全局数据
     *
     * @param array $data 数据数组
     */
    public function set_global_data($data)
    {
        $this->global_data = array_merge($this->global_data, $data);
    }

    // ==================== 站点信息标签 ====================

    /**
     * 解析站点名称标签 (别名)
     */
    public function parse_site_name($attrs, $content, $data)
    {
        $name = function_exists('todo_get_option_ml')
            ? (string) todo_get_option_ml('site_title', todo_get_option('site_title', 'TodoCMS'))
            : (string) todo_get_option('site_title', 'TodoCMS');
        return $name !== '' ? $name : 'TodoCMS';
    }

    public function parse_site_title($attrs, $content, $data)
    {
        $title = function_exists('todo_get_option_ml')
            ? (string) todo_get_option_ml('site_title', todo_get_option('site_title', 'TodoCMS'))
            : (string) todo_get_option('site_title', 'TodoCMS');

        if (function_exists('todo_parse_inline_translations')) {
            $title = todo_parse_inline_translations($title);
        }

        $res = $title !== '' ? $title : 'TodoCMS';
        if (isset($attrs['format']) && $attrs['format'] === 'json') {
            return trim(json_encode((string)$res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '"');
        }
        return $res;
    }

    public function parse_site_copyright($attrs, $content, $data)
    {
        $default = isset($attrs['default']) ? (string) $attrs['default'] : '';
        $val = '';
        if (function_exists('todo_get_option_ml')) {
            $val = todo_get_option_ml('site_copyright', function_exists('todo_get_option') ? todo_get_option('site_copyright', $default) : $default);
        } elseif (function_exists('todo_get_option')) {
            $val = todo_get_option('site_copyright', $default);
        } else {
            $val = $default;
        }
        if (!is_string($val)) {
            $val = is_scalar($val) ? (string) $val : '';
        }
        if ($val === '' && $default !== '') {
            $val = $default;
        }
        if ($val !== '' && strpos($val, '{{todo:') !== false && class_exists('CMS_Template_Compiler_V2')) {
            static $depth = 0;
            if ($depth < 2) {
                $depth++;
                try {
                    $val = CMS_Template_Compiler_V2::render_string($val, is_array($data) ? $data : []);
                } catch (\Throwable $e) {
                }
                $depth--;
            }
        }
        return $val . "\r\n";
    }

    public function parse_site_icp($attrs, $content, $data)
    {
        $default = isset($attrs['default']) ? (string) $attrs['default'] : '';
        if (function_exists('todo_get_option_ml')) {
            $val = todo_get_option_ml('site_icp', function_exists('todo_get_option') ? todo_get_option('site_icp', $default) : $default);
        } elseif (function_exists('todo_get_option')) {
            $val = todo_get_option('site_icp', $default);
        } else {
            $val = $default;
        }
        return is_string($val) ? $val : (is_scalar($val) ? (string) $val : '');
    }

    public function parse_site_analytics($attrs, $content, $data)
    {
        $default = isset($attrs['default']) ? (string) $attrs['default'] : '';
        if (function_exists('todo_get_option_ml')) {
            $val = todo_get_option_ml('site_analytics', function_exists('todo_get_option') ? todo_get_option('site_analytics', $default) : $default);
        } elseif (function_exists('todo_get_option')) {
            $val = todo_get_option('site_analytics', $default);
        } else {
            $val = $default;
        }
        return is_string($val) ? $val : (is_scalar($val) ? (string) $val : '');
    }

    public function parse_current_year($attrs, $content, $data)
    {
        $format = isset($attrs['format']) && is_string($attrs['format']) && $attrs['format'] !== '' ? $attrs['format'] : 'Y';
        $timestamp = function_exists('todo_current_time') ? todo_current_time('timestamp') : (function_exists('current_time') ? current_time('timestamp') : time());
        if (!is_int($timestamp) && !is_numeric($timestamp)) {
            $timestamp = time();
        }
        return date($format, (int) $timestamp);
    }

    /**
     * 解析站点关键词标签
     */
    public function parse_site_keywords($attrs, $content, $data)
    {
        $site_keywords = '';
        if (function_exists('get_option')) {
            $site_keywords = (string) get_option('site_keywords', '');
        }
        if ($site_keywords === '' && function_exists('todo_get_option')) {
            $site_keywords = (string) todo_get_option('site_keywords', '');
        }
        if (!empty($site_keywords)) {
            return function_exists('esc_attr') ? esc_attr($site_keywords) : $site_keywords;
        }
        return '';
    }

    /**
     * 解析站点描述标签
     */
    public function parse_site_description($attrs, $content, $data)
    {
        $desc = '';
        try {
            if (function_exists('todo_get_option_ml')) {
                $fallback = '';
                if (function_exists('get_option')) {
                    $fallback = (string) get_option('site_description', '');
                } elseif (function_exists('todo_get_option')) {
                    $fallback = (string) todo_get_option('site_description', '');
                }
                $desc = (string) todo_get_option_ml('site_description', $fallback);
            }
        } catch (Throwable $e) {
            $desc = '';
        }

        if ($desc === '') {
            try {
                if (function_exists('get_option')) {
                    $desc = (string) get_option('site_description', '');
                }
            } catch (Throwable $e) {
                $desc = '';
            }
        }

        if ($desc === '' && function_exists('todo_get_option')) {
            $desc = (string) todo_get_option('site_description', '');
        }

        if ($desc !== '' && function_exists('todo_parse_inline_translations')) {
            $desc = todo_parse_inline_translations($desc);
        }

        return $desc;
    }

    /**
     * 解析主题URL标签
     */
    public function parse_theme_url($attrs, $content, $data)
    {
        $url = get_template_directory_uri();
        return function_exists('todo_media_url_to_runtime_absolute') ? todo_media_url_to_runtime_absolute($url) : $url;
    }

    /**
     * 解析站点Logo标签
     */
    public function parse_site_logo($attrs, $content, $data)
    {
        $logo_url = function_exists('todo_get_site_logo_url') ? todo_get_site_logo_url() : '';
        if (!empty($logo_url)) {
            return function_exists('todo_media_url_to_runtime_absolute') ? todo_media_url_to_runtime_absolute($logo_url) : $logo_url;
        }

        // 默认logo路径
        $default_logo = get_template_directory_uri() . '/assets/images/logo.png';
        return function_exists('todo_media_url_to_runtime_absolute') ? todo_media_url_to_runtime_absolute($default_logo) : $default_logo;
    }

    public function parse_site_favicon($attrs, $content, $data)
    {
        $favicon_url = function_exists('todo_get_site_favicon_url') ? todo_get_site_favicon_url() : '';
        if (!empty($favicon_url)) {
            return function_exists('todo_media_url_to_runtime_absolute') ? todo_media_url_to_runtime_absolute($favicon_url) : $favicon_url;
        }

        $theme_url = function_exists('get_template_directory_uri') ? get_template_directory_uri() : '';
        $theme_url = is_string($theme_url) ? rtrim($theme_url, '/') : '';
        $theme_dir = function_exists('get_template_directory') ? get_template_directory() : '';
        if (is_string($theme_dir) && $theme_dir !== '' && file_exists(rtrim($theme_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'favicon.ico')) {
            $fav = $theme_url . '/favicon.ico';
            return function_exists('todo_media_url_to_runtime_absolute') ? todo_media_url_to_runtime_absolute($fav) : $fav;
        }
        return '';
    }

    /**
     * 解析站点URL标签
     */
    public function parse_site_url($attrs, $content, $data)
    {
        $raw_base = function_exists('todo_get_site_url') ? rtrim(todo_get_site_url(), '/') : (function_exists('todo_home_url') ? rtrim(todo_home_url(), '/') : '');

        if (is_array($attrs) && (isset($attrs['default']) || !empty($attrs['raw']))) {
            return $raw_base;
        }

        if (function_exists('current_locale') && function_exists('get_language_url')) {
            return rtrim(get_language_url(current_locale(), $raw_base), '/');
        }
        return $raw_base;
    }

    /**
     * 解析Meta标题标签
     */
    public function parse_meta_title($attrs, $content, $data)
    {
        $post_id = isset($attrs['post_id']) ? intval($attrs['post_id']) : 0;
        if ($post_id <= 0 && is_array($attrs) && isset($attrs['id']) && is_numeric($attrs['id'])) {
            $post_id = (int) $attrs['id'];
        }
        if ($post_id <= 0 && is_array($data) && isset($data['post_id']) && is_numeric($data['post_id'])) {
            $post_id = (int) $data['post_id'];
        }
        if ($post_id <= 0 && isset($GLOBALS['todo_post']) && is_object($GLOBALS['todo_post'])) {
            if (isset($GLOBALS['todo_post']->ID) && is_numeric($GLOBALS['todo_post']->ID)) {
                $post_id = (int) $GLOBALS['todo_post']->ID;
            } elseif (isset($GLOBALS['todo_post']->id) && is_numeric($GLOBALS['todo_post']->id)) {
                $post_id = (int) $GLOBALS['todo_post']->id;
            }
        }
        if ($post_id <= 0 && isset($_GET['p']) && is_numeric($_GET['p'])) {
            $post_id = (int) $_GET['p'];
        }
        if ($post_id <= 0 && isset($_GET['post_id']) && is_numeric($_GET['post_id'])) {
            $post_id = (int) $_GET['post_id'];
        }
        if ($post_id > 0) {
            if (function_exists('get_seo_title')) {
                $seo_title = get_seo_title($post_id);
                if (!empty($seo_title)) {
                    return $seo_title;
                }
            }
            if (function_exists('get_post_meta')) {
                $meta = get_post_meta($post_id, 'seo_title', true);
                if (!empty($meta)) {
                    return $meta;
                }
            }
            $p = function_exists('get_post') ? get_post($post_id) : null;
            if ($p && isset($p->seo_title) && $p->seo_title !== '') {
                return (string) $p->seo_title;
            }
            if ($p && isset($p->post_title) && $p->post_title !== '') {
                return (string) $p->post_title;
            }
        }

        // 文章/页面上下文
        if ((function_exists('todo_is_single') && todo_is_single()) || (function_exists('todo_is_page') && todo_is_page())) {
            $pid = $post_id;
            if ($pid > 0) {
                if (function_exists('get_seo_title')) {
                    $seo_title = get_seo_title($pid);
                    if (!empty($seo_title)) {
                        return $seo_title;
                    }
                }
                if (function_exists('get_post_meta')) {
                    $meta = get_post_meta($pid, 'seo_title', true);
                    if (!empty($meta)) {
                        return $meta;
                    }
                }
                $p = function_exists('get_post') ? get_post($pid) : null;
                if ($p && isset($p->seo_title) && $p->seo_title !== '') {
                    return (string) $p->seo_title;
                }
                if ($p && isset($p->post_title) && $p->post_title !== '') {
                    return (string) $p->post_title;
                }
            }
        } elseif ((function_exists('todo_is_category') && todo_is_category()) || (function_exists('todo_is_tag') && todo_is_tag())) {
            $term_id = function_exists('todo_get_queried_object_id') ? todo_get_queried_object_id() : 0;
            if ($term_id) {
                $taxonomy = (function_exists('todo_is_tag') && todo_is_tag()) ? 'post_tag' : 'category';
                if (!function_exists('todo_get_term_seo_multilang')) {
                    @require_once ABSPATH . 'core/functions/multilang-fields.php';
                }
                if (function_exists('todo_get_term_seo_multilang')) {
                    $seo = todo_get_term_seo_multilang($term_id, $taxonomy, null, array('status_allowed' => array('approved', 'pending')));
                    if (is_array($seo) && !empty($seo['seo_title'])) {
                        return (string) $seo['seo_title'];
                    }
                }
                if (function_exists('get_term_meta')) {
                    $meta = get_term_meta($term_id, 'seo_title', true);
                    if (!empty($meta)) {
                        return $meta;
                    }
                }
                if ($term_id && function_exists('todo_get_term')) {
                    $term = call_user_func('todo_get_term', $term_id);
                    if ($term && isset($term->name)) {
                        return $term->name;
                    }
                }
            }
        }

        // 默认返回站点标题
        return function_exists('get_site_name') ? get_site_name() : (function_exists('todo_get_option') ? todo_get_option('site_title', '') : '');
    }

    /**
     * 解析Meta关键词标签
     */
    public function parse_meta_keywords($attrs, $content, $data)
    {
        if ((empty($attrs) || !isset($attrs['post_id'])) && function_exists('get_seo_data')) {
            $seo = get_seo_data();
            if (is_array($seo) && isset($seo['keywords']) && is_string($seo['keywords']) && $seo['keywords'] !== '') {
                return $seo['keywords'];
            }
        }

        $post_id = isset($attrs['post_id']) ? intval($attrs['post_id']) : 0;
        if ($post_id <= 0 && is_array($attrs) && isset($attrs['id']) && is_numeric($attrs['id'])) {
            $post_id = (int) $attrs['id'];
        }
        if ($post_id <= 0 && is_array($data) && isset($data['post_id']) && is_numeric($data['post_id'])) {
            $post_id = (int) $data['post_id'];
        }
        if ($post_id <= 0 && isset($GLOBALS['todo_post']) && is_object($GLOBALS['todo_post'])) {
            if (isset($GLOBALS['todo_post']->ID) && is_numeric($GLOBALS['todo_post']->ID)) {
                $post_id = (int) $GLOBALS['todo_post']->ID;
            } elseif (isset($GLOBALS['todo_post']->id) && is_numeric($GLOBALS['todo_post']->id)) {
                $post_id = (int) $GLOBALS['todo_post']->id;
            }
        }
        if ($post_id <= 0 && isset($_GET['p']) && is_numeric($_GET['p'])) {
            $post_id = (int) $_GET['p'];
        }
        if ($post_id <= 0 && isset($_GET['post_id']) && is_numeric($_GET['post_id'])) {
            $post_id = (int) $_GET['post_id'];
        }
        // 仅在明确处于文章/单页详情上下文时，才将 $_GET['id'] 作为 $post_id 处理，避免分类/标签页中把 term_id 误判为 post_id
        $is_single_context = (function_exists('todo_is_single') && todo_is_single()) || (function_exists('todo_is_page') && todo_is_page()) || (isset($_GET['page']) && in_array($_GET['page'], array('post', 'view_article', 'view_single', 'product', 'view_product'), true));
        if ($post_id <= 0 && $is_single_context && isset($_GET['id']) && is_numeric($_GET['id'])) {
            $post_id = (int) $_GET['id'];
        }
        if ($post_id <= 0 && isset($_GET['page_id']) && is_numeric($_GET['page_id'])) {
            $post_id = (int) $_GET['page_id'];
        }
        if ($post_id > 0) {
            if (function_exists('get_post_meta')) {
                $keywords = get_post_meta($post_id, 'seo_keywords', true);
                if (!empty($keywords)) {
                    return function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($keywords) : $keywords;
                }
            }
            $p = function_exists('get_post') ? get_post($post_id) : null;
            if ($p && isset($p->seo_keywords) && $p->seo_keywords !== '') {
                $sk = (string) $p->seo_keywords;
                return function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($sk) : $sk;
            }
            if (function_exists('get_the_tags')) {
                $tags = get_the_tags($post_id);
                if ($tags) {
                    $tag_names = [];
                    foreach ($tags as $tag) {
                        $tname = isset($tag->name) ? (string) $tag->name : '';
                        if (function_exists('todo_parse_inline_translations')) {
                            $tname = todo_parse_inline_translations($tname);
                        }
                        if ($tname !== '') {
                            $tag_names[] = $tname;
                        }
                    }
                    if (!empty($tag_names)) {
                        return implode(',', $tag_names);
                    }
                }
            }
        }

        // 优先使用文章/页面的关键词
        if ((function_exists('todo_is_single') && todo_is_single()) || (function_exists('todo_is_page') && todo_is_page())) {
            $pid = $post_id;
            if ($pid > 0) {
                if (function_exists('get_post_meta')) {
                    $keywords = get_post_meta($pid, 'seo_keywords', true);
                    if (!empty($keywords)) {
                        return function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($keywords) : $keywords;
                    }
                }
                $p = function_exists('get_post') ? get_post($pid) : null;
                if ($p && isset($p->seo_keywords) && $p->seo_keywords !== '') {
                    $sk = (string) $p->seo_keywords;
                    return function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($sk) : $sk;
                }
                if (function_exists('get_the_tags')) {
                    $tags = get_the_tags($pid);
                    if ($tags) {
                        $tag_names = [];
                        foreach ($tags as $tag) {
                            $tname = isset($tag->name) ? (string) $tag->name : '';
                            if (function_exists('todo_parse_inline_translations')) {
                                $tname = todo_parse_inline_translations($tname);
                            }
                            if ($tname !== '') {
                                $tag_names[] = $tname;
                            }
                        }
                        if (!empty($tag_names)) {
                            return implode(',', $tag_names);
                        }
                    }
                }
            }
        } elseif ((function_exists('todo_is_category') && todo_is_category()) || (function_exists('todo_is_tag') && todo_is_tag()) || (function_exists('get_current_category_id') && get_current_category_id() > 0)) {
            // 分类/标签页关键词
            $term_id = function_exists('get_current_category_id') ? get_current_category_id() : (function_exists('todo_get_queried_object_id') ? todo_get_queried_object_id() : 0);
            if ($term_id) {
                $taxonomy = (function_exists('todo_is_tag') && todo_is_tag()) ? 'post_tag' : 'category';
                if (!function_exists('todo_get_term_seo_multilang')) {
                    @require_once ABSPATH . 'core/functions/multilang-fields.php';
                }
                if (function_exists('todo_get_term_seo_multilang')) {
                    $seo = todo_get_term_seo_multilang($term_id, $taxonomy, null, array('status_allowed' => array('approved', 'pending')));
                    if (is_array($seo) && !empty($seo['seo_keywords'])) {
                        $kw = (string) $seo['seo_keywords'];
                        return function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($kw) : $kw;
                    }
                }
                if (function_exists('get_term_meta')) {
                    $keywords = get_term_meta($term_id, 'seo_keywords', true);
                    if (!empty($keywords)) {
                        return function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($keywords) : $keywords;
                    }
                }
            }
            if ($term_id && function_exists('todo_get_term')) {
                $term = call_user_func('todo_get_term', $term_id);
                if ($term) {
                    $tname = $term->name ?? '';
                    return function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($tname) : $tname;
                }
            }
        }

        // 默认返回站点关键词
        $keywords = '';
        if (function_exists('get_option')) {
            $keywords = (string) get_option('site_keywords', '');
        }
        if ($keywords === '' && function_exists('todo_get_option')) {
            $keywords = (string) todo_get_option('site_keywords', '');
        }

        // 解析行内多语言
        if (function_exists('todo_parse_inline_translations')) {
            $keywords = todo_parse_inline_translations($keywords);
        }

        return $keywords;
    }

    public function parse_cat_seo_keywords($attrs, $content, $data)
    {
        $term_id = 0;
        if (is_array($attrs) && isset($attrs['cat_id']) && is_numeric($attrs['cat_id'])) {
            $term_id = (int) $attrs['cat_id'];
        } elseif (is_array($attrs) && isset($attrs['id']) && is_numeric($attrs['id'])) {
            $term_id = (int) $attrs['id'];
        } elseif (is_array($data) && isset($data['category_id']) && is_numeric($data['category_id'])) {
            $term_id = (int) $data['category_id'];
        } elseif (is_array($data) && isset($data['term_id']) && is_numeric($data['term_id'])) {
            $term_id = (int) $data['term_id'];
        } elseif (function_exists('get_current_category_id')) {
            $term_id = get_current_category_id();
        }
        if ($term_id <= 0 && function_exists('todo_get_queried_object_id')) {
            $term_id = todo_get_queried_object_id();
        }

        if ($term_id > 0) {
            $taxonomy = (function_exists('todo_is_tag') && todo_is_tag()) ? 'post_tag' : 'category';
            if (!function_exists('todo_get_term_seo_multilang')) {
                @require_once ABSPATH . 'core/functions/multilang-fields.php';
            }
            if (function_exists('todo_get_term_seo_multilang')) {
                $seo = todo_get_term_seo_multilang($term_id, $taxonomy, null, array('status_allowed' => array('approved', 'pending')));
                if (is_array($seo) && !empty($seo['seo_keywords'])) {
                    $kw = (string) $seo['seo_keywords'];
                    return function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($kw) : $kw;
                }
            }
            if (function_exists('get_term_meta')) {
                $keywords = get_term_meta($term_id, 'seo_keywords', true);
                if (!empty($keywords)) {
                    return function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($keywords) : $keywords;
                }
            }
            if (function_exists('todo_get_term')) {
                $term = call_user_func('todo_get_term', $term_id);
                if ($term && isset($term->name)) {
                    $tname = (string) $term->name;
                    return function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($tname) : $tname;
                }
            }
        }

        return $this->parse_meta_keywords($attrs, $content, $data);
    }

    public function parse_category_keywords($attrs, $content, $data)
    {
        return $this->parse_cat_seo_keywords($attrs, $content, $data);
    }

    public function parse_cat_seo_title($attrs, $content, $data)
    {
        $term_id = 0;
        if (is_array($attrs) && isset($attrs['cat_id']) && is_numeric($attrs['cat_id'])) {
            $term_id = (int) $attrs['cat_id'];
        } elseif (is_array($attrs) && isset($attrs['id']) && is_numeric($attrs['id'])) {
            $term_id = (int) $attrs['id'];
        } elseif (is_array($data) && isset($data['category_id']) && is_numeric($data['category_id'])) {
            $term_id = (int) $data['category_id'];
        } elseif (is_array($data) && isset($data['term_id']) && is_numeric($data['term_id'])) {
            $term_id = (int) $data['term_id'];
        } elseif (function_exists('get_current_category_id')) {
            $term_id = get_current_category_id();
        }
        if ($term_id <= 0 && function_exists('todo_get_queried_object_id')) {
            $term_id = todo_get_queried_object_id();
        }

        if ($term_id > 0) {
            $taxonomy = (function_exists('todo_is_tag') && todo_is_tag()) ? 'post_tag' : 'category';
            if (!function_exists('todo_get_term_seo_multilang')) {
                @require_once ABSPATH . 'core/functions/multilang-fields.php';
            }
            if (function_exists('todo_get_term_seo_multilang')) {
                $seo = todo_get_term_seo_multilang($term_id, $taxonomy, null, array('status_allowed' => array('approved', 'pending')));
                if (is_array($seo) && !empty($seo['seo_title'])) {
                    $st = (string) $seo['seo_title'];
                    return function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($st) : $st;
                }
            }
            if (function_exists('get_term_meta')) {
                $meta = get_term_meta($term_id, 'seo_title', true);
                if (!empty($meta)) {
                    return function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($meta) : $meta;
                }
            }
            if (function_exists('todo_get_term')) {
                $term = call_user_func('todo_get_term', $term_id);
                if ($term && isset($term->name)) {
                    $tname = (string) $term->name;
                    return function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($tname) : $tname;
                }
            }
        }

        return $this->parse_meta_title($attrs, $content, $data);
    }

    public function parse_cat_seo_description($attrs, $content, $data)
    {
        $term_id = 0;
        if (is_array($attrs) && isset($attrs['cat_id']) && is_numeric($attrs['cat_id'])) {
            $term_id = (int) $attrs['cat_id'];
        } elseif (is_array($attrs) && isset($attrs['id']) && is_numeric($attrs['id'])) {
            $term_id = (int) $attrs['id'];
        } elseif (is_array($data) && isset($data['category_id']) && is_numeric($data['category_id'])) {
            $term_id = (int) $data['category_id'];
        } elseif (is_array($data) && isset($data['term_id']) && is_numeric($data['term_id'])) {
            $term_id = (int) $data['term_id'];
        } elseif (function_exists('get_current_category_id')) {
            $term_id = get_current_category_id();
        }
        if ($term_id <= 0 && function_exists('todo_get_queried_object_id')) {
            $term_id = todo_get_queried_object_id();
        }

        if ($term_id > 0) {
            $taxonomy = (function_exists('todo_is_tag') && todo_is_tag()) ? 'post_tag' : 'category';
            if (!function_exists('todo_get_term_seo_multilang')) {
                @require_once ABSPATH . 'core/functions/multilang-fields.php';
            }
            if (function_exists('todo_get_term_seo_multilang')) {
                $seo = todo_get_term_seo_multilang($term_id, $taxonomy, null, array('status_allowed' => array('approved', 'pending')));
                if (is_array($seo) && !empty($seo['seo_description'])) {
                    $sd = (string) $seo['seo_description'];
                    return function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($sd) : $sd;
                }
            }
            if (function_exists('get_term_meta')) {
                $meta = get_term_meta($term_id, 'seo_description', true);
                if (!empty($meta)) {
                    return function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($meta) : $meta;
                }
            }
            if (function_exists('todo_get_term')) {
                $term = call_user_func('todo_get_term', $term_id);
                if ($term && isset($term->description)) {
                    $tdesc = (string) $term->description;
                    return function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($tdesc) : $tdesc;
                }
            }
        }

        return $this->parse_meta_description($attrs, $content, $data);
    }

    public function parse_cat_is_hidden($attrs, $content, $data)
    {
        $term_id = function_exists('get_current_category_id') ? get_current_category_id() : (function_exists('todo_get_queried_object_id') ? todo_get_queried_object_id() : 0);
        if ($term_id > 0 && function_exists('get_term_meta')) {
            return get_term_meta($term_id, 'is_hidden', true) ? 'true' : 'false';
        }
        return 'false';
    }

    public function parse_category_featured_image($attrs, $content, $data)
    {
        $cat_id = is_array($data) ? intval($data['category_id'] ?? ($data['cat_id'] ?? ($data['term_id'] ?? ($data['cat'] ?? 0)))) : 0;
        if ($cat_id <= 0 && function_exists('get_current_category_id')) {
            $cat_id = get_current_category_id();
        }

        if ($cat_id <= 0) {
            return '';
        }

        $term_attrs = function_exists('todo_get_term_attributes_multilang')
            ? todo_get_term_attributes_multilang($cat_id)
            : (function_exists('get_term_meta') ? get_term_meta($cat_id) : []);

        $val = '';
        if (is_array($term_attrs)) {
            $val = $term_attrs['category_image'] ?? ($term_attrs['cat_image'] ?? ($term_attrs['category_featured_image'] ?? ($term_attrs['featured_image'] ?? '')));
        }
        if (empty($val) && function_exists('get_term_meta')) {
            $val = get_term_meta($cat_id, 'category_image', true)
                ?: (get_term_meta($cat_id, 'cat_image', true)
                ?: get_term_meta($cat_id, 'category_featured_image', true));
        }

        if (empty($val)) {
            return '';
        }

        $lang_req = is_array($attrs) && !empty($attrs['keywords']) ? trim((string)$attrs['keywords']) : null;

        if (function_exists('todo_match_featured_image_by_lang')) {
            $matched = todo_match_featured_image_by_lang($val, $lang_req);
            if (!empty($matched)) {
                return function_exists('todo_media_url_to_runtime_absolute') ? todo_media_url_to_runtime_absolute($matched) : $matched;
            }
            if ($lang_req !== null) {
                return '';
            }
        }

        return self::resolve_category_image_src($val);
    }

    public function parse_category_image($attrs, $content, $data)
    {
        return $this->parse_category_featured_image($attrs, $content, $data);
    }

    public function parse_cat_image($attrs, $content, $data)
    {
        return $this->parse_category_featured_image($attrs, $content, $data);
    }

    /**
     * 解析Meta描述标签
     */
    public function parse_meta_description($attrs, $content, $data)
    {
        $safe_format = function($val) use ($attrs) {
            $s = (string)$val;
            if (isset($attrs['format']) && $attrs['format'] === 'json') {
                return trim(json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '"');
            }
            return $s;
        };

        if ((empty($attrs) || !isset($attrs['post_id'])) && function_exists('get_seo_description')) {
            $d = (string) get_seo_description();
            if ($d !== '') {
                return $safe_format($d);
            }
        }

        $post_id = isset($attrs['post_id']) ? intval($attrs['post_id']) : 0;
        if ($post_id <= 0 && is_array($attrs) && isset($attrs['id']) && is_numeric($attrs['id'])) {
            $post_id = (int) $attrs['id'];
        }
        if ($post_id <= 0 && is_array($data) && isset($data['post_id']) && is_numeric($data['post_id'])) {
            $post_id = (int) $data['post_id'];
        }
        if ($post_id <= 0 && isset($GLOBALS['todo_post']) && is_object($GLOBALS['todo_post'])) {
            if (isset($GLOBALS['todo_post']->ID) && is_numeric($GLOBALS['todo_post']->ID)) {
                $post_id = (int) $GLOBALS['todo_post']->ID;
            } elseif (isset($GLOBALS['todo_post']->id) && is_numeric($GLOBALS['todo_post']->id)) {
                $post_id = (int) $GLOBALS['todo_post']->id;
            }
        }
        if ($post_id <= 0 && isset($_GET['p']) && is_numeric($_GET['p'])) {
            $post_id = (int) $_GET['p'];
        }
        if ($post_id <= 0 && isset($_GET['post_id']) && is_numeric($_GET['post_id'])) {
            $post_id = (int) $_GET['post_id'];
        }
        // 兼容 page=post&id=XX 路由（文章/产品详情页实际使用的参数）
        if ($post_id <= 0 && isset($_GET['id']) && is_numeric($_GET['id'])) {
            $post_id = (int) $_GET['id'];
        }
        if ($post_id <= 0 && isset($_GET['page_id']) && is_numeric($_GET['page_id'])) {
            $post_id = (int) $_GET['page_id'];
        }
        if ($post_id > 0) {
            if (function_exists('get_post_meta')) {
                $description = get_post_meta($post_id, 'seo_description', true);
                if (!empty($description)) {
                    return $safe_format($description);
                }
            }
            $p = function_exists('get_post') ? get_post($post_id) : null;
            if ($p && isset($p->seo_description) && $p->seo_description !== '') {
                return $safe_format($p->seo_description);
            }
            if (function_exists('get_post')) {
                $p = get_post($post_id);
                if ($p && !empty($p->post_excerpt)) {
                    return $safe_format($p->post_excerpt);
                }
            }
        }

        // 优先使用文章/页面的描述
        if ((function_exists('todo_is_single') && todo_is_single()) || (function_exists('todo_is_page') && todo_is_page())) {
            $pid = $post_id;
            if ($pid > 0) {
                if (function_exists('get_post_meta')) {
                    $description = get_post_meta($pid, 'seo_description', true);
                    if (!empty($description)) {
                        return $safe_format($description);
                    }
                }
                $p = function_exists('get_post') ? get_post($pid) : null;
                if ($p && isset($p->seo_description) && $p->seo_description !== '') {
                    return $safe_format($p->seo_description);
                }
                if ($p && !empty($p->post_excerpt)) {
                    return $safe_format($p->post_excerpt);
                }
            }
        } elseif ((function_exists('todo_is_category') && todo_is_category()) || (function_exists('todo_is_tag') && todo_is_tag())) {
            // 分类/标签页描述
            $term_id = function_exists('todo_get_queried_object_id') ? todo_get_queried_object_id() : 0;
            if ($term_id) {
                $taxonomy = (function_exists('todo_is_tag') && todo_is_tag()) ? 'post_tag' : 'category';
                if (!function_exists('todo_get_term_seo_multilang')) {
                    @require_once ABSPATH . 'core/functions/multilang-fields.php';
                }
                if (function_exists('todo_get_term_seo_multilang')) {
                    $seo = todo_get_term_seo_multilang($term_id, $taxonomy, null, array('status_allowed' => array('approved', 'pending')));
                    if (is_array($seo) && !empty($seo['seo_description'])) {
                        return $safe_format($seo['seo_description']);
                    }
                }
                if (function_exists('get_term_meta')) {
                    $description = get_term_meta($term_id, 'seo_description', true);
                    if (!empty($description)) {
                        return $safe_format($description);
                    }
                }
            }
            if ($term_id && function_exists('todo_get_term')) {
                $term = call_user_func('todo_get_term', $term_id);
                if ($term && !empty($term->description)) {
                    return $safe_format($term->description);
                }
            }
        }

        // 默认返回站点描述
        $description = '';
        if (function_exists('get_option')) {
            $description = (string) get_option('site_description', '');
        }
        if ($description === '' && function_exists('todo_get_option')) {
            $description = (string) todo_get_option('site_description', '');
        }

        // 解析行内多语言
        if (function_exists('todo_map_inline_translations_deep')) {
            $description = todo_map_inline_translations_deep($description);
        }

        return $safe_format($description);
    }

    /**
     * 解析公共资源URL标签
     */
    public function parse_public_url($attrs, $content, $data)
    {
        $url = site_url('public');
        return function_exists('todo_media_url_to_runtime_absolute') ? todo_media_url_to_runtime_absolute($url) : $url;
    }

    /**
     * 解析主题样式表引入 {{todo:theme_css file="path/to.css"}}
     */
    public function parse_theme_css($attrs, $content, $data)
    {
        if (!isset($attrs['file']) || empty($attrs['file'])) {
            return '';
        }
        $file = trim($attrs['file']);

        // 计算URL
        $href = '';
        if (preg_match('#^https?://#i', $file)) {
            // 绝对URL
            $href = $file;
        } elseif (strpos($file, '/') === 0) {
            // 以站点根为基准的路径
            $href = rtrim(todo_url(), '/') . $file;
        } else {
            // 以主题目录为基准的相对路径
            $href = rtrim(get_template_directory_uri(), '/') . '/' . ltrim($file, '/');
        }

        // 可选：添加版本号，基于物理文件mtime
        $version = '';
        $maybe_path = '';
        if (preg_match('#^https?://#i', $href)) {
            // 远程资源不尝试加版本
        } elseif (strpos($file, '/') === 0) {
            // 站点根路径 → ABSPATH 映射
            $maybe_path = rtrim(ABSPATH, '/') . $file;
        } else {
            $maybe_path = rtrim(get_template_directory(), '/') . '/' . ltrim($file, '/');
        }
        if (!empty($maybe_path) && file_exists($maybe_path)) {
            $version = (string) @filemtime($maybe_path);
        }
        if (!empty($version)) {
            $href .= (strpos($href, '?') === false ? '?' : '&') . 'ver=' . rawurlencode($version);
        }

        // 可选媒体属性
        $media = isset($attrs['media']) && $attrs['media'] !== '' ? ' media="' . esc_attr($attrs['media']) . '"' : '';
        return '<link rel="stylesheet" href="' . esc_url($href) . '"' . $media . ' />';
    }

    /**
     * 解析主题脚本引入 {{todo:theme_js file="path/to.js"}}
     */
    public function parse_theme_js($attrs, $content, $data)
    {
        if (!isset($attrs['file']) || empty($attrs['file'])) {
            return '';
        }
        $file = trim($attrs['file']);

        // 计算URL
        $src = '';
        if (preg_match('#^https?://#i', $file)) {
            $src = $file;
        } elseif (strpos($file, '/') === 0) {
            $src = rtrim(todo_url(), '/') . $file;
        } else {
            $src = rtrim(get_template_directory_uri(), '/') . '/' . ltrim($file, '/');
        }

        // 可选：版本号
        $version = '';
        $maybe_path = '';
        if (preg_match('#^https?://#i', $src)) {
            // 远程资源不尝试加版本
        } elseif (strpos($file, '/') === 0) {
            $maybe_path = rtrim(ABSPATH, '/') . $file;
        } else {
            $maybe_path = rtrim(get_template_directory(), '/') . '/' . ltrim($file, '/');
        }
        if (!empty($maybe_path) && file_exists($maybe_path)) {
            $version = (string) @filemtime($maybe_path);
        }
        if (!empty($version)) {
            $src .= (strpos($src, '?') === false ? '?' : '&') . 'ver=' . rawurlencode($version);
        }

        // 可选的async / defer
        $async = isset($attrs['async']) && in_array(strtolower($attrs['async']), ['1', 'true', 'async'], true) ? ' async' : '';
        $defer = isset($attrs['defer']) && in_array(strtolower($attrs['defer']), ['1', 'true', 'defer'], true) ? ' defer' : '';
        return '<script src="' . esc_url($src) . '"' . $async . $defer . ' type="text/javascript"></script>' . "\r\n";
    }

    /**
     * 解析管理员URL标签
     */
    public function parse_admin_url($attrs, $content, $data)
    {
        // 使用 TodoCMS 自有URL构造，兼容子目录安装与端口
        $path = '';
        if (is_array($attrs)) {
            if (!isset($attrs['path']) && isset($attrs['p'])) {
                $attrs['path'] = $attrs['p'];
            }
            if (isset($attrs['path'])) {
                $path = trim((string) $attrs['path']);
            }
        }
        if ($path !== '') {
            return todo_admin_url($path);
        }
        // 返回后台根URL（去除结尾多余斜杠）
        return rtrim(todo_admin_url('/'), '/');
    }

    /**
     * 解析字符编码标签
     */
    public function parse_charset($attrs, $content, $data)
    {
        return get_option('blog_charset', 'UTF-8');
    }

    /**
     * 解析HTML属性标签
     */
    public function parse_html_attributes($attrs, $content, $data)
    {
        // 单一来源：使用 language_attributes() 并捕获输出，保持与系统函数一致且避免直接在页面上回显
        if (function_exists('language_attributes')) {
            ob_start();
            language_attributes();
            $attrs = ob_get_clean();
            return $attrs;
        }
        // Fallback：规范化当前语言并输出 BCP47 + 文本方向
        $locale = function_exists('current_locale') ? current_locale() : (function_exists('get_bloginfo') ? get_bloginfo('language') : 'zh-CN');
        if (!$locale) {
            $locale = 'zh-CN';
        }
        if (function_exists('canonical_locale')) {
            $locale = canonical_locale($locale);
        } else {
            $locale = str_replace('_', '-', $locale);
            if (strpos($locale, '-') !== false) {
                list($l, $r) = explode('-', $locale, 2);
                $locale = strtolower($l) . '-' . strtoupper($r);
            } else {
                $locale = strtolower($locale);
            }
        }
        $rtl_langs = array('ar', 'he', 'fa', 'ur');
        $dir = in_array(substr($locale, 0, 2), $rtl_langs, true) ? 'rtl' : 'ltr';
        return 'lang="' . esc_attr($locale) . '" dir="' . $dir . '"';
    }

    // ==================== 面包屑导航标签 ====================

    /**
     * 解析面包屑导航标签
     * 支持格式：{{todo:breadcrumb}} 或 {{todo:breadcrumb separator=" > "}}
     */
    public function parse_breadcrumb($attrs, $content, $data)
    {
        $breadcrumbs = todo_get_breadcrumb_data();

        if (empty($breadcrumbs)) {
            return '';
        }

        $separator = isset($attrs['separator']) ? $attrs['separator'] : ' > ';
        $show_current = isset($attrs['show_current']) ? $attrs['show_current'] !== 'false' : true;
        $format = isset($attrs['format']) ? $attrs['format'] : 'html';

        if ($format === 'json') {
            return json_encode($breadcrumbs, JSON_UNESCAPED_UNICODE);
        }

        $output = '<ul class="breadcrumb-list">';
        $total = count($breadcrumbs);

        foreach ($breadcrumbs as $index => $crumb) {
            $is_last = ($index === $total - 1);
            $is_current = isset($crumb['is_current']) && $crumb['is_current'];

            // 如果是最后一项且设置不显示当前页
            if ($is_last && !$show_current && $is_current) {
                break;
            }

            // 多语言路径前缀支持
            if (!$is_current && !$is_last && function_exists('get_language_url') && function_exists('current_locale')) {
                $crumb['url'] = get_language_url(current_locale(), $crumb['url']);
            }

            $output .= '<li>';
            if ($is_current || $is_last) {
                $output .= '<span class="breadcrumb-current">' . esc_html($crumb['title']) . '</span>';
            } elseif ($index === 0) {
                $output .= '<a href="' . esc_url($crumb['url']) . '">' . esc_html($crumb['title']) . '</a>';
            } else {
                $output .= '<a href="' . esc_url($crumb['url']) . '">' . esc_html($crumb['title']) . '</a>';
            }

            // 添加分隔符（除了最后一项）
            if (!$is_last) {
                $output .= '<span class="breadcrumb-separator">' . esc_html($separator) . '</span>';
            }
            $output .= '</li>';
        }

        $output .= '</ul>';

        return $output;
    }

    /**
     * 解析面包屑列表标签
     * 返回面包屑数组供模板循环使用
     */
    public function parse_breadcrumb_list($attrs, $content, $data)
    {
        return todo_get_breadcrumb_data();
    }

    /**
     * 解析面包屑JSON标签
     * 返回JSON格式的面包屑数据
     */
    public function parse_breadcrumb_json($attrs, $content, $data)
    {
        $breadcrumbs = todo_get_breadcrumb_data();
        return json_encode($breadcrumbs, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 解析当前页面标题标签
     * 返回面包屑中的当前页面标题
     */
    public function parse_current_page_title($attrs, $content, $data)
    {
        $breadcrumbs = todo_get_breadcrumb_data();

        if (empty($breadcrumbs)) {
            return '';
        }

        // 查找当前页面（最后一个标记为current的项目）
        for ($i = count($breadcrumbs) - 1; $i >= 0; $i--) {
            if (isset($breadcrumbs[$i]['is_current']) && $breadcrumbs[$i]['is_current']) {
                return $breadcrumbs[$i]['title'];
            }
        }

        // 如果没有找到current标记，返回最后一项
        return end($breadcrumbs)['title'];
    }

    /**
     * 解析当前页面类型标签
     * 返回当前页面的内容类型（如：产品详情、文章详情等）
     */
    public function parse_current_page_type($attrs, $content, $data)
    {
        $breadcrumbs = todo_get_breadcrumb_data();

        if (empty($breadcrumbs)) {
            return '';
        }

        // 查找最后一个包含type信息的项目
        for ($i = count($breadcrumbs) - 1; $i >= 0; $i--) {
            if (isset($breadcrumbs[$i]['type'])) {
                return $breadcrumbs[$i]['type'];
            }
        }

        return '';
    }

    // ==================== SEO元数据标签 ====================

    /**
     * 解析规范链接标签
     */
    public function parse_canonical_url($attrs, $content, $data)
    {
        if (todo_is_single() || todo_is_page()) {
            return todo_get_permalink();
        } elseif (todo_is_category()) {
            return todo_get_category_link(todo_get_queried_object_id());
        } elseif (todo_is_tag()) {
            return todo_get_tag_link(todo_get_queried_object_id());
        }

        return todo_home_url(todo_add_query_arg(null, null));
    }

    public function parse_current_url($attrs, $content, $data)
    {
        if (function_exists('todo_get_current_canonical')) {
            $u = (string) todo_get_current_canonical();
            if ($u !== '') {
                return $u;
            }
        }
        if (function_exists('todo_get_current_url')) {
            $u = (string) todo_get_current_url();
            if ($u !== '') {
                return $u;
            }
        }
        if (function_exists('get_current_url')) {
            return (string) get_current_url();
        }
        return '';
    }

    public function parse_og_title($attrs, $content, $data)
    {
        if (function_exists('get_seo_title')) {
            $t = (string) get_seo_title();
            if ($t !== '') {
                return $t;
            }
        }
        if (function_exists('get_full_page_title')) {
            $t = (string) get_full_page_title();
            if ($t !== '') {
                return $t;
            }
        }
        if (function_exists('get_option')) {
            return (string) get_option('site_title', 'TodoCMS');
        }
        return 'TodoCMS';
    }

    public function parse_og_description($attrs, $content, $data)
    {
        if (function_exists('get_seo_description')) {
            $d = (string) get_seo_description();
            if ($d !== '') {
                return $d;
            }
        }
        return $this->parse_meta_description($attrs, $content, $data);
    }

    public function parse_og_image($attrs, $content, $data)
    {
        $image = '';

        if (function_exists('todo_is_single') && todo_is_single()) {
            $pid = 0;
            if (isset($GLOBALS['todo_post']) && is_object($GLOBALS['todo_post']) && isset($GLOBALS['todo_post']->ID)) {
                $pid = (int) $GLOBALS['todo_post']->ID;
            } elseif (isset($_GET['p']) && is_numeric($_GET['p'])) {
                $pid = (int) $_GET['p'];
            }
            if (isset($GLOBALS['todo_post']->featured_image) && !empty($GLOBALS['todo_post']->featured_image) && function_exists('todo_match_og_image')) {
                $image = todo_match_og_image($GLOBALS['todo_post']->featured_image);
            }
            if (!$image && $pid > 0 && function_exists('get_the_post_thumbnail_url')) {
                $u = (string) get_the_post_thumbnail_url($pid, 'full');
                if ($u !== '') {
                    $image = $u;
                }
            }
        }

        // 分类/标签页：优先使用栏目图片
        if ($image === '' && ((function_exists('todo_is_category') && todo_is_category()) || (function_exists('todo_is_tag') && todo_is_tag()))) {
            $term_id = 0;
            if (function_exists('todo_get_queried_object_id')) {
                $term_id = (int) todo_get_queried_object_id();
            }
            $taxonomy = (function_exists('todo_is_tag') && todo_is_tag()) ? 'post_tag' : 'category';
            $val = null;
            if ($term_id > 0) {
                if (function_exists('todo_get_term_attributes_multilang')) {
                    $map = todo_get_term_attributes_multilang($term_id, $taxonomy, null, array('status_allowed' => array('approved', 'pending')));
                    if (is_array($map) && !empty($map['category_image'])) {
                        $val = $map['category_image'];
                    }
                }
                if (($val === null || $val === '') && function_exists('get_term_meta')) {
                    $val = get_term_meta($term_id, 'category_image', true);
                }
            }
            if ($val !== null && $val !== '') {
                if (function_exists('todo_match_og_image')) {
                    $image = todo_match_og_image($val);
                }
                if (!$image) {
                    $url = $this->normalize_image_value_to_url($val, ['size' => 'full']);
                    if ($url !== '') {
                        $image = $url;
                    }
                }
            }
        }

        if ($image === '') {
            $img = function_exists('get_option') ? get_option('site_og_image', '') : '';
            if ($img !== '' && function_exists('todo_match_og_image')) {
                $image = todo_match_og_image($img);
            }
            if (!$image) {
                $image = $this->normalize_media_value_to_url($img);
            }
        }

        if ($image === '') {
            $logo = function_exists('get_option') ? get_option('site_logo', '') : (function_exists('todo_get_option') ? todo_get_option('site_logo', '') : '');
            if ($logo !== '' && function_exists('todo_match_og_image')) {
                $image = todo_match_og_image($logo);
            }
            if (!$image) {
                $image = $this->normalize_media_value_to_url($logo);
            }
        }

        // 确保图片为绝对 URL（OG/Twitter 爬虫需要）
        if ($image !== '' && strpos($image, 'http://') !== 0 && strpos($image, 'https://') !== 0) {
            $site_url = function_exists('todo_get_site_url') ? rtrim(todo_get_site_url(), '/') : '';
            if ($site_url !== '') {
                $image = $site_url . (strpos($image, '/') === 0 ? $image : '/' . $image);
            }
        }

        return $image;
    }

    // ==================== 内容标签 ====================

    // 纯文本规范化与裁剪工具（移除脚本/媒体、剥离标签、压缩空白、按字数或词数裁剪）
    private function normalize_and_trim_text($htmlOrText, $length, $unit = 'chars')
    {
        if (!is_string($htmlOrText)) {
            return '';
        }
        $s = $htmlOrText;

        // 先进行实体解码，避免 &lt;p&gt; 等被当成普通文本
        if (function_exists('html_entity_decode')) {
            $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
        }

        // 清理不可见或非正文的标签
        $s = preg_replace([
            '/<script\b[^>]*>.*?<\/script>/is',
            '/<style\b[^>]*>.*?<\/style>/is',
            '/<noscript\b[^>]*>.*?<\/noscript>/is',
            '/<img[^>]*>/i',
            '/<picture\b[^>]*>.*?<\/picture>/is',
            '/<video\b[^>]*>.*?<\/video>/is',
            '/<audio\b[^>]*>.*?<\/audio>/is',
            '/<iframe\b[^>]*>.*?<\/iframe>/is',
            '/<object\b[^>]*>.*?<\/object>/is',
            '/<embed\b[^>]*>.*?<\/embed>/is'
        ], '', $s);

        // 去掉所有 HTML 标签（包括非自闭合和自闭合）
        $s = strip_tags($s);

        // 为词数和字数两种模式分别处理
        if ($unit === 'words') {
            // 词数：先将空白压缩为单空格，按非字母数字拆词，裁剪后移除空格满足纯文本要求
            $w = preg_replace('/\s+/u', ' ', $s);
            $tokens = preg_split('/[^\p{L}\p{N}]+/u', $w, -1, PREG_SPLIT_NO_EMPTY);
            if (empty($tokens)) {
                return '';
            }
            $tokens = array_slice($tokens, 0, $length);
            return implode('', $tokens); // 最终去除空格，输出纯文本
        } else {
            // 字数：移除所有空白字符后按多字节安全方式截取
            $c = preg_replace('/\s+/u', '', $s);
            if ($c === '') {
                return '';
            }
            if (function_exists('mb_substr')) {
                return mb_substr($c, 0, $length, 'UTF-8');
            }
            return substr($c, 0, $length);
        }
    }

    /**
     * 解析文章标题标签
     */
    private function get_post_context($data)
    {
        global $post;
        $obj = null;

        if ($this->todo_adjacent_post !== null) {
            $obj = $this->todo_adjacent_post;
        } elseif (is_array($data)) {
            if (isset($data['post'])) {
                $obj = $data['post'];
            } elseif (isset($data['item'])) {
                $obj = $data['item'];
            } elseif (isset($data['ID']) || isset($data['id'])) {
                $obj = $data;
            }
        }
        if ($obj === null) {
            if (isset($GLOBALS['todo_post']) && is_object($GLOBALS['todo_post'])) {
                $obj = $GLOBALS['todo_post'];
            } else {
                $obj = $post;
            }
        }

        $id = 0;
        if (is_object($obj)) {
            if (isset($obj->ID)) {
                $id = (int) $obj->ID;
            } elseif (isset($obj->id)) {
                $id = (int) $obj->id;
            } elseif (isset($obj->post_id)) {
                $id = (int) $obj->post_id;
            }
        } elseif (is_array($obj)) {
            if (isset($obj['ID'])) {
                $id = (int) $obj['ID'];
            } elseif (isset($obj['id'])) {
                $id = (int) $obj['id'];
            } elseif (isset($obj['post_id'])) {
                $id = (int) $obj['post_id'];
            }
        }
        if ($id <= 0 && is_array($data) && isset($data['post_id'])) {
            $id = (int) $data['post_id'];
        }
        if ($id <= 0 && is_array($data) && isset($data['id'])) {
            $id = (int) $data['id'];
        }

        return ['obj' => $obj, 'id' => $id];
    }

    private function resolve_post_featured_image_url($post_obj)
    {
        $raw = '';
        if (is_object($post_obj) && isset($post_obj->featured_image)) {
            $raw = $post_obj->featured_image;
        } elseif (is_array($post_obj) && isset($post_obj['featured_image'])) {
            $raw = $post_obj['featured_image'];
        }

        if (function_exists('todo_match_featured_image_by_lang')) {
            $matched_lang_url = todo_match_featured_image_by_lang($raw);
            if (!empty($matched_lang_url)) {
                return (string) $matched_lang_url;
            }
        }

        if (is_string($raw)) {
            $trim = trim($raw);
            if ($trim === '' || $trim === '[]') {
                return '';
            }


            if (($trim[0] === '{' || $trim[0] === '[')) {
                $decoded = json_decode($trim, true);
                if (is_array($decoded)) {
                    if (isset($decoded['url']) && $decoded['url'] !== '') {
                        return (string) $decoded['url'];
                    }
                    if (isset($decoded['images']) && is_array($decoded['images']) && isset($decoded['images'][0]['url']) && $decoded['images'][0]['url'] !== '') {
                        return (string) $decoded['images'][0]['url'];
                    }
                    if (isset($decoded[0])) {
                        $first = $decoded[0];
                        if (is_array($first) && isset($first['url']) && $first['url'] !== '') {
                            return (string) $first['url'];
                        }
                        if (is_string($first) && trim($first) !== '') {
                            return trim($first);
                        }
                    }
                }
            }

            $val = function_exists('maybe_unserialize') ? maybe_unserialize($trim) : @unserialize($trim);
            if (is_array($val) && !empty($val)) {
                $first = reset($val);
                if (is_array($first) && isset($first['url']) && $first['url'] !== '') {
                    return (string) $first['url'];
                }
                if (is_string($first) && trim($first) !== '') {
                    return trim($first);
                }
            }
            if (is_string($val) && $val !== $trim && trim($val) !== '') {
                return trim($val);
            }

            return $trim;
        }

        if (is_array($raw)) {
            if (isset($raw['url']) && $raw['url'] !== '') {
                return (string) $raw['url'];
            }
            $first = reset($raw);
            if (is_array($first) && isset($first['url']) && $first['url'] !== '') {
                return (string) $first['url'];
            }
            if (is_string($first) && trim($first) !== '') {
                return trim($first);
            }
        }

        return '';
    }

    public function parse_post_title($attrs, $content, $data)
    {
        $safe_json = function($str) use ($attrs) {
            if (isset($attrs['format']) && $attrs['format'] === 'json') {
                return trim(json_encode((string)$str, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '"');
            }
            return (string)$str;
        };

        // 快捷路径：如果 data 中已有非空 title/post_title/article_title，直接返回
        // 避免回退到 todo_get_the_title → todo_get_post_multilang 的多语言/递归进程锁
        if (is_array($data)) {
            $title_val = $data['title'] ?? ($data['post_title'] ?? ($data['article_title'] ?? ''));
            if (!empty($title_val)) {
                return $safe_json($title_val);
            }
            // 有值但是空字符串的，也返回（避免 fallthrough 浪费 DB 查询）
            if (isset($data['title'])) {
                return $safe_json($data['title']);
            }
        }

        $ctx = $this->get_post_context($data);
        $post_id = $ctx['id'];

        if ($post_id <= 0 && is_array($attrs) && isset($attrs['id']) && is_numeric($attrs['id'])) {
            $post_id = (int) $attrs['id'];
        }
        if ($post_id <= 0) {
            // 回退：从对象中直接获取 ID（与 parse_post_url 保持一致）
            $obj = $ctx['obj'];
            if (is_object($obj) && isset($obj->ID)) {
                $post_id = (int) $obj->ID;
            }
        }
        if ($post_id <= 0) {
            return '';
        }

        $title = '';
        if (function_exists('todo_get_the_title')) {
            $title = todo_get_the_title($post_id);
        } else {
            $title = function_exists('get_the_title') ? get_the_title($post_id) : '';
        }
        return $safe_json($title);
    }

    /**
     * 自动处理文章正文 (post_content) 富文本中的多图 ThumbHash 渐进式占位与懒加载（仅当图片 > 1MB 时触发）
     */
    public static function process_content_images_thumbhash($html)
    {
        if (empty($html) || !is_string($html) || strpos($html, '<img') === false) {
            return $html;
        }

        // 1. 扫描所有 img 标签提取 src 属性，进行单 SQL 批量预加载（避免 N+1）
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
            $urls = array_filter(array_unique($matches[1]), function ($u) {
                return !empty($u) && strpos($u, 'data:') !== 0;
            });
            if (!empty($urls)) {
                self::prefetch_media_info($urls);
            }
        }

        // 2. 正则替换处理每一个 img 标签（仅检测是否已直接被 image-wrapper 包裹）
        return preg_replace_callback('#(?<wrapper><div[^>]+class=["\'][^"\']*image-wrapper[^"\']*["\'][^>]*>)?\s*(?<img_tag><img[^>]+>)\s*(?(wrapper)(?:<div[^>]+class=["\'][^"\']*image-caption[^"\']*["\'][^>]*>.*?</div>\s*)*|</div>)?#is', function ($match) {
            $img_tag = $match['img_tag'];
            $has_wrapper = !empty($match['wrapper']);

            // 提取 src
            if (!preg_match('/src=["\']([^"\']+)["\']/i', $img_tag, $m_src)) {
                return $match[0];
            }

            $src = trim($m_src[1]);
            if ($src === '' || strpos($src, 'data:') === 0) {
                return $match[0];
            }

            $rel_src = function_exists('todo_media_url_to_relative') ? todo_media_url_to_relative($src) : $src;

            // 获取媒体信息（走请求级内存 + Todo_Cache_Manager 双层缓存）
            $info = self::get_media_info($rel_src);

            if ($info) {
                // 替换或关联来自媒体库的 title 与 alt
                $db_title = isset($info->media_title) ? trim($info->media_title) : '';
                $db_alt = isset($info->media_alt) ? trim($info->media_alt) : '';
                $db_caption = isset($info->media_caption) ? trim($info->media_caption) : '';

                if ($db_title !== '') {
                    if (preg_match('/title=["\'][^"\']*["\']/i', $img_tag)) {
                        $img_tag = preg_replace('/title=["\'][^"\']*["\']/i', 'title="' . esc_attr($db_title) . '"', $img_tag);
                    } else {
                        $img_tag = preg_replace('/<img\s+/i', '<img title="' . esc_attr($db_title) . '" ', $img_tag);
                    }
                }

                if ($db_alt !== '') {
                    if (preg_match('/alt=["\'][^"\']*["\']/i', $img_tag)) {
                        $img_tag = preg_replace('/alt=["\'][^"\']*["\']/i', 'alt="' . esc_attr($db_alt) . '"', $img_tag);
                    } else {
                        $img_tag = preg_replace('/<img\s+/i', '<img alt="' . esc_attr($db_alt) . '" ', $img_tag);
                    }
                }
            }

            $display_src = function_exists('todo_media_url_to_runtime_absolute') ? todo_media_url_to_runtime_absolute($rel_src) : $rel_src;

            if ($info && !empty($info->thumbhash) && isset($info->media_size) && (int)$info->media_size > 1048576 && class_exists('ThumbHash')) {
                $placeholder = ThumbHash::toDataURL($info->thumbhash);

                // 替换或添加 src 属性为占位图
                if (preg_match('/src=["\'][^"\']+["\']/i', $img_tag)) {
                    $img_tag = preg_replace('/src=["\'][^"\']+["\']/i', 'src="' . esc_url($placeholder) . '"', $img_tag);
                }

                // 替换或添加 data-src 属性为原图 URL
                if (preg_match('/data-src=["\'][^"\']+["\']/i', $img_tag)) {
                    $img_tag = preg_replace('/data-src=["\'][^"\']+["\']/i', 'data-src="' . esc_url($display_src) . '"', $img_tag);
                } else {
                    $img_tag = preg_replace('/<img\s+/i', '<img data-src="' . esc_url($display_src) . '" ', $img_tag);
                }

                // 处理 class 属性，追加 lazy-load 与 thumbhash-lazy
                if (preg_match('/class=["\']([^"\']*)["\']/i', $img_tag, $m_class)) {
                    $classes = array_filter(array_unique(array_merge(explode(' ', $m_class[1]), ['lazy-load', 'thumbhash-lazy'])));
                    $img_tag = preg_replace('/class=["\'][^"\']*["\']/i', 'class="' . esc_attr(implode(' ', $classes)) . '"', $img_tag);
                } else {
                    $img_tag = preg_replace('/<img\s+/i', '<img class="lazy-load thumbhash-lazy" ', $img_tag);
                }
            } else {
                // 普通图片：将 src 更新为最终 URL（含 CDN 规则）
                if (preg_match('/src=["\'][^"\']+["\']/i', $img_tag)) {
                    $img_tag = preg_replace('/src=["\'][^"\']+["\']/i', 'src="' . esc_url($display_src) . '"', $img_tag);
                }
            }

            if ($has_wrapper) {
                // 已被 image-wrapper 直接包裹，不做任何外层包裹或 caption 追加，仅更新 <img> 基础属性
                $full_match = $match[0];
                return preg_replace('/<img[^>]+>/i', $img_tag, $full_match, 1);
            }

            if (!empty($db_caption)) {
                return '<div class="image-wrapper">' . $img_tag . '<div class="image-caption">' . esc_html($db_caption) . '</div></div>';
            }

            return $img_tag;
        }, $html);
    }

    /**
     * 根据 URL 获取媒体库中的媒体信息（支持基础参数 title, alt, caption, size, thumbhash）
     */
    public static function get_media_info($url)
    {
        if (empty($url) || !is_string($url)) {
            return null;
        }

        $url = trim($url);

        // 1. 静态内存缓存（当前 PHP 请求生命周期内完全防重复 SQL 查询）
        if (array_key_exists($url, self::$media_info_cache)) {
            return self::$media_info_cache[$url];
        }

        // 2. Todo_Cache_Manager 二级缓存（跨请求 Redis / 文件级缓存）
        $cache_key = 'media_info_' . md5($url);
        if (class_exists('Todo_Cache_Manager')) {
            $cached = Todo_Cache_Manager::get($cache_key, 'media', 'global');
            if ($cached !== false && $cached !== null) {
                $res = ($cached === 'none') ? null : (object) $cached;
                self::$media_info_cache[$url] = $res;
                return $res;
            }
        }

        global $tododb;
        if (!$tododb && class_exists('CMS_DB')) {
            $tododb = new CMS_DB();
        }

        if (!$tododb) {
            self::$media_info_cache[$url] = null;
            return null;
        }

        $norm_path = ltrim($url, '/');
        $rel_path = preg_replace('#^https?://[^/]+#i', '', $url);
        $rel_path = preg_replace('#^/?content/uploads/#', '', $rel_path);
        $rel_path = ltrim($rel_path, '/');
        $prefix = isset($tododb->prefix) ? $tododb->prefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_');

        $row = $tododb->get_row("SELECT * FROM " . $prefix . "media WHERE media_url = ? OR media_path = ? OR media_path = ? OR media_path = ? OR media_url LIKE ? OR media_path LIKE ? LIMIT 1", [$url, $norm_path, $rel_path, '/' . $rel_path, '%' . $rel_path, '%' . $rel_path]);

        $res = $row ? $row : null;
        self::$media_info_cache[$url] = $res;

        if (class_exists('Todo_Cache_Manager')) {
            $save_data = $res ? (array) $res : 'none';
            Todo_Cache_Manager::set($cache_key, $save_data, 86400, 'media', 'global');
        }

        return $res;
    }

    /**
     * 批量预加载媒体信息，在已知多图列表场景下提前缓存
     */
    public static function prefetch_media_info(array $urls)
    {
        $uncached = [];
        foreach ($urls as $url) {
            if (is_string($url) && $url !== '') {
                $url = trim($url);
                if (!array_key_exists($url, self::$media_info_cache)) {
                    $cache_key = 'media_info_' . md5($url);
                    if (class_exists('Todo_Cache_Manager')) {
                        $cached = Todo_Cache_Manager::get($cache_key, 'media', 'global');
                        if ($cached !== false && $cached !== null) {
                            self::$media_info_cache[$url] = ($cached === 'none') ? null : (object) $cached;
                            continue;
                        }
                    }
                    $uncached[$url] = true;
                }
            }
        }

        if (empty($uncached)) {
            return;
        }

        global $tododb;
        if (!$tododb && class_exists('CMS_DB')) {
            $tododb = new CMS_DB();
        }
        if (!$tododb) {
            return;
        }

        $prefix = isset($tododb->prefix) ? $tododb->prefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_');
        $targets = array_keys($uncached);

        $where_clauses = [];
        $params = [];
        foreach ($targets as $url) {
            $norm_path = ltrim($url, '/');
            $rel_path = preg_replace('#^https?://[^/]+#i', '', $url);
            $rel_path = preg_replace('#^/?content/uploads/#', '', $rel_path);
            $rel_path = ltrim($rel_path, '/');
            $where_clauses[] = "(media_url = ? OR media_path = ? OR media_path = ? OR media_path = ? OR media_url LIKE ? OR media_path LIKE ?)";
            $params[] = $url;
            $params[] = $norm_path;
            $params[] = $rel_path;
            $params[] = '/' . $rel_path;
            $params[] = '%' . $rel_path;
            $params[] = '%' . $rel_path;
        }

        $sql = "SELECT * FROM " . $prefix . "media WHERE " . implode(' OR ', $where_clauses);
        $rows = $tododb->get_results($sql, $params);

        $found_map = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!empty($row->media_url)) {
                    $found_map[$row->media_url] = $row;
                }
                if (!empty($row->media_path)) {
                    $found_map[$row->media_path] = $row;
                    $found_map[ltrim($row->media_path, '/')] = $row;
                }
            }
        }

        foreach ($targets as $url) {
            $norm_path = ltrim($url, '/');
            $rel_path = preg_replace('#^https?://[^/]+#i', '', $url);
            $rel_path = preg_replace('#^/?content/uploads/#', '', $rel_path);
            $rel_path = ltrim($rel_path, '/');

            $matched_row = null;
            if (isset($found_map[$url])) {
                $matched_row = $found_map[$url];
            } elseif (isset($found_map[$norm_path])) {
                $matched_row = $found_map[$norm_path];
            } elseif (isset($found_map[$rel_path])) {
                $matched_row = $found_map[$rel_path];
            } elseif (isset($found_map['/' . $rel_path])) {
                $matched_row = $found_map['/' . $rel_path];
            } else {
                foreach ($found_map as $map_key => $row_obj) {
                    if ($rel_path !== '' && (strpos($map_key, $rel_path) !== false || (isset($row_obj->media_path) && strpos($row_obj->media_path, $rel_path) !== false))) {
                        $matched_row = $row_obj;
                        break;
                    }
                }
            }

            self::$media_info_cache[$url] = $matched_row;
            if (class_exists('Todo_Cache_Manager')) {
                $cache_key = 'media_info_' . md5($url);
                Todo_Cache_Manager::set($cache_key, $matched_row === null ? 'none' : (array) $matched_row, 86400, 'media', 'global');
            }
        }
    }

    /**
     * 解析文章内容标签
     */
    public function parse_post_content($attrs, $content, $data)
    {
        $ctx = $this->get_post_context($data);
        $post_obj = $ctx['obj'];
        $post_id = $ctx['id'];
        if ($post_id <= 0 && is_array($attrs) && isset($attrs['id']) && is_numeric($attrs['id'])) {
            $post_id = (int) $attrs['id'];
        }

        $html = '';
        if ($post_id > 0 && function_exists('todo_get_the_content')) {
            $html = todo_get_the_content('', false, $post_id);
        } elseif (is_object($post_obj) && isset($post_obj->post_content)) {
            $html = function_exists('apply_filters') ? apply_filters('the_content', $post_obj->post_content) : $post_obj->post_content;
        }

        if ($html === '' || strpos($html, '<img') === false) {
            return $html;
        }

        return self::process_content_images_thumbhash($html);
    }

    /**
     * 解析任意位置文章标签（通过文章 ID 直接获取标题、内容或 JSON）
     * 示例：{{todo:anywhere_post id="123"}} 或 {{todo:anywhere_post id="123" field="title"}}
     */
    public function parse_anywhere_post($attrs, $content, $data)
    {
        $id = isset($attrs['id']) ? intval($attrs['id']) : (isset($attrs['post_id']) ? intval($attrs['post_id']) : 0);
        if ($id <= 0) return '';
        $field = isset($attrs['field']) ? trim(strtolower($attrs['field'])) : 'content';

        if ($field === 'title') {
            return function_exists('todo_get_the_title') ? todo_get_the_title($id) : (function_exists('get_the_title') ? get_the_title($id) : '');
        }

        if ($field === 'excerpt' || $field === 'summary') {
            $length = isset($attrs['length']) ? intval($attrs['length']) : 150;
            $post_obj = function_exists('get_post') ? get_post($id) : null;
            if (function_exists('todo_get_the_excerpt')) {
                return todo_get_the_excerpt($length, $post_obj);
            }
            if (is_object($post_obj)) {
                $raw = !empty($post_obj->post_excerpt) ? $post_obj->post_excerpt : $post_obj->post_content;
                $txt = strip_tags($raw);
                if (function_exists('todo_parse_inline_translations')) {
                    $txt = todo_parse_inline_translations($txt);
                }
                return mb_strlen($txt) > $length ? mb_substr($txt, 0, $length) : $txt;
            }
            return '';
        }

        if ($field === 'link' || $field === 'url' || $field === 'permalink') {
            return function_exists('todo_get_permalink') ? todo_get_permalink($id) : (function_exists('get_permalink') ? get_permalink($id) : '');
        }

        if ($field === 'thumbnail' || $field === 'image' || $field === 'featured_image') {
            return $this->parse_post_thumbnail(array_merge($attrs, ['id' => $id]), $content, $data);
        }

        if ($field === 'thumbnail_url' || $field === 'image_url') {
            return $this->parse_post_thumbnail_url(array_merge($attrs, ['id' => $id]), $content, $data);
        }

        if ($field === 'date') {
            $format = isset($attrs['format']) ? $attrs['format'] : (function_exists('todo_get_option') ? todo_get_option('date_format') : 'Y-m-d');
            return function_exists('get_the_date') ? get_the_date($format, $id) : date($format);
        }

        if ($field === 'json') {
            $t = function_exists('todo_get_the_title') ? todo_get_the_title($id) : '';
            $c = function_exists('todo_get_the_content') ? todo_get_the_content('', false, $id) : '';
            $u = $this->parse_post_thumbnail_url(array_merge($attrs, ['id' => $id]), $content, $data);
            $l = function_exists('todo_get_permalink') ? todo_get_permalink($id) : '';
            return json_encode(['id' => $id, 'title' => $t, 'content' => $c, 'thumbnail' => $u, 'link' => $l], JSON_UNESCAPED_UNICODE);
        }

        return function_exists('todo_get_the_content') ? todo_get_the_content('', false, $id) : '';
    }

    /**
     * 解析文章摘要标签
     */
    public function parse_post_excerpt($attrs, $content, $data)
    {
        $length = isset($attrs['length']) ? intval($attrs['length']) : 150;

        // 快捷路径：如果 data 中已有非空 excerpt/post_excerpt/summary，直接处理并返回
        if (is_array($data)) {
            $excerpt_val = $data['excerpt'] ?? ($data['post_excerpt'] ?? ($data['summary'] ?? ''));
            if (!empty($excerpt_val)) {
                $txt = html_entity_decode((string) $excerpt_val, ENT_QUOTES, 'UTF-8');
                $txt = strip_tags($txt);
                if (function_exists('todo_parse_inline_translations')) {
                    $txt = todo_parse_inline_translations($txt);
                }
                if (mb_strlen($txt) > $length) {
                    $txt = mb_substr($txt, 0, $length);
                }
                return function_exists('apply_filters') ? apply_filters('get_the_excerpt', $txt, null) : $txt;
            }
        }

        $ctx = $this->get_post_context($data);
        $post_obj = $ctx['obj'];
        $post_id = $ctx['id'];

        if ($post_id <= 0) {
            $post_id = (is_object($post_obj) && isset($post_obj->ID)) ? (int) $post_obj->ID : 0;
        }
        if ($post_id <= 0) {
            return '';
        }

        if (function_exists('todo_get_the_excerpt')) {
            $excerpt = todo_get_the_excerpt($length, is_object($post_obj) ? $post_obj : null);
        } else {
            $excerpt = '';
            if (is_object($post_obj) && isset($post_obj->post_excerpt)) {
                $excerpt = (string) $post_obj->post_excerpt;
            }
        }

        if ($excerpt === '' && is_object($post_obj) && isset($post_obj->post_content)) {
            $excerpt = function_exists('todo_trim_words') ? todo_trim_words(strip_tags($post_obj->post_content), $length) : strip_tags($post_obj->post_content);
        }

        return function_exists('apply_filters') ? apply_filters('get_the_excerpt', $excerpt, is_object($post_obj) ? $post_obj : null) : $excerpt;
    }

    /**
     * 解析文章日期标签
     */
    public function parse_post_date($attrs, $content, $data)
    {
        $format = isset($attrs['format']) ? $attrs['format'] : (function_exists('todo_get_option') ? todo_get_option('date_format') : 'Y-m-d');
        $ctx = $this->get_post_context($data);
        $post_id = $ctx['id'];

        if ($post_id <= 0) {
            $obj = $ctx['obj'];
            $post_id = (is_object($obj) && isset($obj->ID)) ? (int) $obj->ID : 0;
        }
        if ($post_id <= 0) {
            return '';
        }

        return function_exists('get_the_date') ? get_the_date($format, $post_id) : date($format);
    }

    /**
     * 解析文章URL标签
     */
    public function parse_post_url($attrs, $content, $data)
    {
        $ctx = $this->get_post_context($data);
        $post_id = $ctx['id'];
        if ($post_id > 0) {
            return todo_get_permalink($post_id);
        }
        $obj = $ctx['obj'];
        return (is_object($obj) && isset($obj->ID)) ? todo_get_permalink($obj->ID) : '';
    }





    /**
     * 获取商品模型扩展表完整数据行，支持请求级内存与 Todo_Cache_Manager 级缓存（消灭单页面多字段及多商品 N+1 查询）
     */
    public static function get_product_model_row($post_id)
    {
        $post_id = (int)$post_id;
        if ($post_id <= 0) {
            return null;
        }

        if (array_key_exists($post_id, self::$product_model_cache)) {
            return self::$product_model_cache[$post_id];
        }

        $cache_key = 'product_model_row_' . $post_id;
        if (class_exists('Todo_Cache_Manager')) {
            $cached = Todo_Cache_Manager::get($cache_key, 'product', 'global');
            if ($cached !== false && $cached !== null) {
                $res = ($cached === 'none') ? null : (object)$cached;
                self::$product_model_cache[$post_id] = $res;
                return $res;
            }
        }

        global $tododb;
        if (!$tododb && class_exists('CMS_DB')) {
            $tododb = new CMS_DB();
        }

        if (!$tododb) {
            self::$product_model_cache[$post_id] = null;
            return null;
        }

        $table_name = (isset($tododb->prefix) ? $tododb->prefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_')) . 'model_fields_product';
        $row = null;
        try {
            $row = $tododb->get_row("SELECT * FROM {$table_name} WHERE post_id = ? LIMIT 1", [$post_id]);
        } catch (Exception $e) {
        }

        $res = $row ? $row : null;
        self::$product_model_cache[$post_id] = $res;

        if (class_exists('Todo_Cache_Manager')) {
            $save = $res ? (array)$res : 'none';
            Todo_Cache_Manager::set($cache_key, $save, 86400, 'product', 'global');
        }

        return $res;
    }

    /**
     * 获取通用模型扩展表数据行，支持请求级内存与 Todo_Cache_Manager 级缓存
     */
    public static function get_model_table_row($table, $post_id)
    {
        $post_id = (int)$post_id;
        if (empty($table) || $post_id <= 0) {
            return null;
        }

        $cache_id = $table . '_' . $post_id;
        if (array_key_exists($cache_id, self::$model_row_cache)) {
            return self::$model_row_cache[$cache_id];
        }

        $cache_key = 'model_row_' . md5($cache_id);
        if (class_exists('Todo_Cache_Manager')) {
            $cached = Todo_Cache_Manager::get($cache_key, 'model_fields', 'global');
            if ($cached !== false && $cached !== null) {
                $res = ($cached === 'none') ? null : (array)$cached;
                self::$model_row_cache[$cache_id] = $res;
                return $res;
            }
        }

        global $tododb;
        if (!$tododb && class_exists('CMS_DB')) {
            $tododb = new CMS_DB();
        }

        if (!$tododb) {
            self::$model_row_cache[$cache_id] = null;
            return null;
        }

        $row = null;
        try {
            $row = $tododb->get_row("SELECT * FROM `{$table}` WHERE post_id = ? LIMIT 1", [$post_id], 'ARRAY_A');
        } catch (Throwable $e) {
        }

        $res = is_array($row) ? $row : (is_object($row) ? get_object_vars($row) : null);
        self::$model_row_cache[$cache_id] = $res;

        if (class_exists('Todo_Cache_Manager')) {
            $save = $res ? $res : 'none';
            Todo_Cache_Manager::set($cache_key, $save, 86400, 'model_fields', 'global');
        }

        return $res;
    }

    /**
     * 解析文章特色图片标签
     */
    public function parse_post_thumbnail($attrs, $content, $data)
    {
        $ctx = $this->get_post_context($data);
        $post_obj = $ctx['obj'];
        $post_id = $ctx['id'];

        $override_id = isset($attrs['id']) ? intval($attrs['id']) : (isset($attrs['post_id']) ? intval($attrs['post_id']) : 0);
        if ($override_id > 0) {
            $post_id = $override_id;
            if (function_exists('get_post')) {
                $post_obj = get_post($override_id);
            }
        }

        $class = isset($attrs['class']) ? (string) $attrs['class'] : '';
        $loading = isset($attrs['loading']) ? (string) $attrs['loading'] : '';

        $target_lang = null;
        if (isset($attrs['keywords']) && trim((string)$attrs['keywords']) !== '') {
            $target_lang = trim((string)$attrs['keywords']);
        }

        $url = '';
        if (function_exists('todo_match_featured_image_by_lang')) {
            $raw_fi = '';
            if ($override_id <= 0 && is_array($data) && !empty($data['featured_image'])) {
                $raw_fi = $data['featured_image'];
            } elseif (is_object($post_obj) && !empty($post_obj->featured_image)) {
                $raw_fi = $post_obj->featured_image;
            } elseif (is_array($post_obj) && !empty($post_obj['featured_image'])) {
                $raw_fi = $post_obj['featured_image'];
            } elseif ($override_id <= 0 && is_array($data) && !empty($data['post_featured_image'])) {
                $raw_fi = $data['post_featured_image'];
            } elseif ($post_id > 0 && function_exists('get_post_meta')) {
                $raw_fi = get_post_meta($post_id, 'featured_image', true);
            }
            if (!empty($raw_fi)) {
                $matched_url = todo_match_featured_image_by_lang($raw_fi, $target_lang);
                if (!empty($matched_url)) {
                    $url = $matched_url;
                }
            }
        }

        if ($url === '' && $target_lang !== null) {
            return '';
        }

        if ($url === '' && $post_id > 0 && function_exists('get_post_featured_image')) {
            $fi = get_post_featured_image($post_id);
            if (!empty($fi)) {
                $url = (string) $fi;
            }
        }

        if ($override_id <= 0 && $url === '' && is_array($data)) {
            if (!empty($data['post_thumbnail']) && is_string($data['post_thumbnail'])) {
                $url = $data['post_thumbnail'];
            } elseif (!empty($data['article_featured_image']) && is_string($data['article_featured_image'])) {
                $url = $data['article_featured_image'];
            }
        }
        if ($url === '' && $post_id > 0 && function_exists('get_the_post_thumbnail_url')) {
            $url = (string) get_the_post_thumbnail_url($post_id, 'full');
        }
        if ($url === '') {
            $url = (string) $this->resolve_post_featured_image_url($post_obj);
        }
        if ($url === '') {
            return '';
        }
        if (function_exists('todo_media_url_to_runtime_absolute')) {
            $url = todo_media_url_to_runtime_absolute($url);
        }
        $title = '';
        if (is_object($post_obj) && isset($post_obj->post_title)) {
            $title = (string) $post_obj->post_title;
        } elseif (is_array($post_obj) && isset($post_obj['title'])) {
            $title = (string) $post_obj['title'];
        } elseif (function_exists('todo_get_the_title')) {
            if ($post_id > 0) {
                $title = (string) todo_get_the_title($post_id);
            }
        }
        $placeholder = '';
        $use_thumbhash = false;
        if (class_exists('ThumbHash') && $url !== '') {
            $row = self::get_media_info($url);
            if ($row && !empty($row->thumbhash) && isset($row->media_size) && (int)$row->media_size > 1048576) {
                $placeholder = ThumbHash::toDataURL($row->thumbhash);
                $use_thumbhash = true;
            }
        }

        $loadingAttr = $loading !== '' ? ' loading="' . esc_attr($loading) . '"' : '';

        if ($use_thumbhash) {
            $classes = array_filter(array_unique(array_merge(explode(' ', $class), ['lazy-load', 'thumbhash-lazy'])));
            $classAttr = ' class="' . esc_attr(implode(' ', $classes)) . '"';
            return '<img src="' . esc_url($placeholder) . '" data-src="' . esc_url($url) . '"' . $classAttr . ' alt="' . esc_attr($title) . '"' . $loadingAttr . ' />';
        }

        $classes = array_filter(array_unique(explode(' ', $class)));
        $classAttr = !empty($classes) ? ' class="' . esc_attr(implode(' ', $classes)) . '"' : '';
        return '<img src="' . esc_url($url) . '"' . $classAttr . ' alt="' . esc_attr($title) . '"' . $loadingAttr . ' />';
    }

    /**
     * 解析通用图片标签 {{todo:img_tag src="image" alt="name" width="30"}}
     */
    public function parse_img_tag($attrs, $content, $data)
    {
        $src_input = isset($attrs['src']) ? trim((string)$attrs['src']) : '';
        if ($src_input === '') {
            return '';
        }

        $src = '';
        $is_thumbnail_alias = in_array($src_input, [
            'post_featured_image',
            'article_featured_image',
            'featured_image',
            'post_thumbnail',
            'content_thumbnail',
            'thumbnail'
        ], true);

        $is_category_thumbnail_alias = in_array($src_input, [
            'category_featured_image',
            'category_image',
            'cat_image'
        ], true);

        $target_lang = null;
        if (isset($attrs['keywords']) && trim((string)$attrs['keywords']) !== '') {
            $target_lang = trim((string)$attrs['keywords']);
        }

        if (function_exists('todo_match_featured_image_by_lang')) {
            $raw_fi = '';
            $ctx = $this->get_post_context($data);
            if ($is_thumbnail_alias) {
                if (!empty($ctx['obj']) && is_object($ctx['obj']) && !empty($ctx['obj']->featured_image)) {
                    $raw_fi = $ctx['obj']->featured_image;
                } elseif (is_array($data) && !empty($data['featured_image'])) {
                    $raw_fi = $data['featured_image'];
                } elseif (is_array($data) && !empty($data['post_featured_image'])) {
                    $raw_fi = $data['post_featured_image'];
                } elseif (!empty($ctx['obj']) && is_array($ctx['obj']) && !empty($ctx['obj']['featured_image'])) {
                    $raw_fi = $ctx['obj']['featured_image'];
                }
            } elseif ($is_category_thumbnail_alias) {
                if (is_array($data) && !empty($data['raw_category_featured_image'])) {
                    $raw_fi = $data['raw_category_featured_image'];
                } elseif (is_array($data) && !empty($data['raw_category_image'])) {
                    $raw_fi = $data['raw_category_image'];
                } else {
                    $cat_id = is_array($data) ? intval($data['category_id'] ?? ($data['cat_id'] ?? ($data['term_id'] ?? ($data['cat'] ?? 0)))) : 0;
                    if ($cat_id <= 0 && function_exists('get_current_category_id')) {
                        $cat_id = get_current_category_id();
                    }
                    if ($cat_id > 0) {
                        $term_attrs = function_exists('todo_get_term_attributes_multilang')
                            ? todo_get_term_attributes_multilang($cat_id)
                            : (function_exists('get_term_meta') ? get_term_meta($cat_id) : []);
                        if (is_array($term_attrs)) {
                            $raw_fi = $term_attrs['category_image'] ?? ($term_attrs['cat_image'] ?? ($term_attrs['category_featured_image'] ?? ($term_attrs['featured_image'] ?? '')));
                        }
                    }
                }
            } elseif (is_array($data) && !empty($data[$src_input])) {
                $raw_fi = $data[$src_input];
            } elseif (!empty($src_input) && (strpos($src_input, '[') === 0 || strpos($src_input, '{') === 0)) {
                $raw_fi = $src_input;
            }

            if (!empty($raw_fi)) {
                $matched_url = todo_match_featured_image_by_lang($raw_fi, $target_lang);
                if (!empty($matched_url)) {
                    $src = $matched_url;
                }
            }
        }

        if ($src === '' && $target_lang !== null) {
            return '';
        }

        if ($src === '' && $is_thumbnail_alias) {
            $ctx = $this->get_post_context($data);
            $src = (string) $this->resolve_post_featured_image_url($ctx['obj']);
        } elseif ($src === '' && $is_category_thumbnail_alias) {
            $src = (string) $this->parse_category_featured_image($attrs, '', $data);
        }

        if ($src === '' && is_array($data)) {
            if (isset($data[$src_input]) && is_string($data[$src_input]) && $data[$src_input] !== '') {
                $src = $data[$src_input];
            }
        }

        if ($src === '') {
            if ($is_thumbnail_alias) {
                $ctx = $this->get_post_context($data);
                $src = (string) $this->resolve_post_featured_image_url($ctx['obj']);
            } else {
                $src = $src_input;
            }
        }

        if (is_string($src) && $src !== '') {
            $trim_src = trim($src);
            if (strpos($trim_src, '[') === 0 || strpos($trim_src, '{') === 0) {
                $decoded = json_decode($trim_src, true);
                if (is_array($decoded)) {
                    if (isset($decoded['url']) && is_string($decoded['url']) && $decoded['url'] !== '') {
                        $src = $decoded['url'];
                    } elseif (isset($decoded['images']) && is_array($decoded['images']) && isset($decoded['images'][0]['url']) && $decoded['images'][0]['url'] !== '') {
                        $src = $decoded['images'][0]['url'];
                    } elseif (isset($decoded[0])) {
                        $first = $decoded[0];
                        if (is_array($first) && isset($first['url']) && is_string($first['url']) && $first['url'] !== '') {
                            $src = $first['url'];
                        } elseif (is_string($first) && trim($first) !== '') {
                            $src = trim($first);
                        }
                    }
                }
            } elseif (strpos($trim_src, 'a:') === 0) {
                $val = function_exists('maybe_unserialize') ? maybe_unserialize($trim_src) : @unserialize($trim_src);
                if (is_array($val) && !empty($val)) {
                    $first = reset($val);
                    if (is_array($first) && isset($first['url']) && is_string($first['url']) && $first['url'] !== '') {
                        $src = $first['url'];
                    } elseif (is_string($first) && trim($first) !== '') {
                        $src = trim($first);
                    }
                }
            }
        }

        if ($src === '') {
            return '';
        }

        if (function_exists('todo_media_url_to_runtime_absolute')) {
            $src = todo_media_url_to_runtime_absolute($src);
        }

        $alt_input = isset($attrs['alt']) ? (string)$attrs['alt'] : '';
        $alt = '';
        if (is_array($data) && isset($data[$alt_input]) && is_string($data[$alt_input])) {
            $alt = $data[$alt_input];
        }
        if ($alt === '') {
            if ($alt_input === 'post_title' || $alt_input === 'title') {
                $ctx = $this->get_post_context($data);
                if (is_object($ctx['obj']) && isset($ctx['obj']->post_title)) {
                    $alt = (string) $ctx['obj']->post_title;
                } elseif (is_array($ctx['obj']) && isset($ctx['obj']['title'])) {
                    $alt = (string) $ctx['obj']['title'];
                }
            } else {
                $alt = $alt_input;
            }
        }

        if ($alt !== '') {
            if (function_exists('todo_parse_inline_translations')) {
                $alt = todo_parse_inline_translations($alt);
            }
            if (function_exists('todo_translate')) {
                $alt = todo_translate($alt);
            }
        }

        $class = isset($attrs['class']) ? (string)$attrs['class'] : '';
        $loading = isset($attrs['loading']) ? (string)$attrs['loading'] : 'lazy';

        // 判断文件扩展名
        $url_path = parse_url($src, PHP_URL_PATH);
        $ext = strtolower(pathinfo($url_path ?: '', PATHINFO_EXTENSION));

        $video_exts = ['mp4', 'webm', 'ogg', 'mov', 'm4v'];
        $audio_exts = ['mp3', 'wav', 'ogg', 'aac', 'flac', 'm4a'];
        $html_exts  = ['html', 'htm'];
        $image_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'avif', 'tiff'];

        $classes = array_filter(array_unique(explode(' ', $class)));
        $classAttr = !empty($classes) ? ' class="' . esc_attr(implode(' ', $classes)) . '"' : '';

        // 视频
        if (in_array($ext, $video_exts, true)) {
            $extraAttrs = '';
            foreach (['width', 'height', 'style', 'id', 'title'] as $k) {
                if (isset($attrs[$k]) && $attrs[$k] !== '') {
                    $extraAttrs .= ' ' . $k . '="' . esc_attr($attrs[$k]) . '"';
                }
            }
            return '<video src="' . esc_url($src) . '" controls playsinline preload="metadata"' . $classAttr . $extraAttrs . '></video>';
        }

        // 音频
        if (in_array($ext, $audio_exts, true)) {
            $extraAttrs = '';
            foreach (['style', 'id', 'title'] as $k) {
                if (isset($attrs[$k]) && $attrs[$k] !== '') {
                    $extraAttrs .= ' ' . $k . '="' . esc_attr($attrs[$k]) . '"';
                }
            }
            return '<audio src="' . esc_url($src) . '" controls preload="metadata"' . $classAttr . $extraAttrs . '></audio>';
        }

        // HTML
        if (in_array($ext, $html_exts, true)) {
            $extraAttrs = '';
            foreach (['width', 'height', 'style', 'id', 'title'] as $k) {
                if (isset($attrs[$k]) && $attrs[$k] !== '') {
                    $extraAttrs .= ' ' . $k . '="' . esc_attr($attrs[$k]) . '"';
                }
            }
            return '<iframe src="' . esc_url($src) . '" frameborder="0"' . $classAttr . $extraAttrs . '></iframe>';
        }

        // 非图片、非视频/音频/HTML的其他文件：显示 <a> 标签
        if ($ext !== '' && !in_array($ext, $image_exts, true)) {
            $label = $alt !== '' ? $alt : basename($url_path ?: $src);
            $extraAttrs = '';
            foreach (['style', 'id', 'title'] as $k) {
                if (isset($attrs[$k]) && $attrs[$k] !== '') {
                    $extraAttrs .= ' ' . $k . '="' . esc_attr($attrs[$k]) . '"';
                }
            }
            return '<a href="' . esc_url($src) . '" target="_blank" rel="nofollow noopener noreferrer"' . $classAttr . $extraAttrs . '>' . esc_html($label) . '</a>';
        }

        $placeholder = '';
        $use_thumbhash = false;
        if (class_exists('ThumbHash') && $src !== '') {
            $row = self::get_media_info($src);
            if ($row && !empty($row->thumbhash) && isset($row->media_size) && (int)$row->media_size > 1048576) {
                $placeholder = ThumbHash::toDataURL($row->thumbhash);
                $use_thumbhash = true;
            }
        }

        $loadingAttr = $loading !== '' ? ' loading="' . esc_attr($loading) . '"' : '';
        $altAttr = ' alt="' . esc_attr($alt) . '"';

        $extraAttrs = '';
        foreach (['width', 'height', 'style', 'id', 'title'] as $k) {
            if (isset($attrs[$k]) && $attrs[$k] !== '') {
                $extraAttrs .= ' ' . $k . '="' . esc_attr($attrs[$k]) . '"';
            }
        }

        if ($use_thumbhash) {
            $classes = array_filter(array_unique(array_merge(explode(' ', $class), ['lazy-load', 'thumbhash-lazy'])));
            $classAttr = ' class="' . esc_attr(implode(' ', $classes)) . '"';
            return '<img src="' . esc_url($placeholder) . '" data-src="' . esc_url($src) . '"' . $classAttr . $altAttr . $loadingAttr . $extraAttrs . ' />';
        }

        return '<img src="' . esc_url($src) . '"' . $classAttr . $altAttr . $loadingAttr . $extraAttrs . ' />';
    }

    /**
     * 解析文章评论数标签
     */
    public function parse_post_comment_count($attrs, $content, $data)
    {
        global $post;
        return $post ? get_comments_number($post->ID) : '0';
    }

    /**
     * 解析文章浏览量标签
     */
    public function parse_post_views($attrs, $content, $data)
    {
        global $post;
        if (!$post)
            return '0';
        $real_views = 0;
        if (function_exists('get_post_views')) {
            $real_views = (int) get_post_views($post->ID);
        } elseif (isset($post->view_count)) {
            $real_views = (int) $post->view_count;
        }

        $boost = 0;
        if (function_exists('get_post_meta')) {
            $boost = (int) get_post_meta($post->ID, '_views_boost', true);
        }

        return (string) max(0, $real_views + $boost);
    }

    public function parse_views($attrs, $content, $data)
    {
        $post_id = 0;
        if (is_array($attrs)) {
            if (isset($attrs['post_id']) && is_numeric($attrs['post_id'])) {
                $post_id = (int) $attrs['post_id'];
            } elseif (isset($attrs['id']) && is_numeric($attrs['id'])) {
                $post_id = (int) $attrs['id'];
            }
        }
        if ($post_id <= 0 && is_array($data)) {
            if (isset($data['post_id']) && is_numeric($data['post_id'])) {
                $post_id = (int) $data['post_id'];
            } elseif (isset($data['ID']) && is_numeric($data['ID'])) {
                $post_id = (int) $data['ID'];
            } elseif (isset($data['id']) && is_numeric($data['id'])) {
                $post_id = (int) $data['id'];
            }
        }
        if ($post_id <= 0) {
            // 回退到 todo_post 全局上下文（todocms 约定）
            $todo_post = isset($GLOBALS['todo_post']) && is_object($GLOBALS['todo_post']) ? $GLOBALS['todo_post'] : null;
            if ($todo_post && isset($todo_post->ID)) {
                $post_id = (int) $todo_post->ID;
            }
        }
        if ($post_id <= 0 && isset($_GET['p']) && is_numeric($_GET['p'])) {
            $post_id = (int) $_GET['p'];
        }
        if ($post_id <= 0) {
            return '0';
        }

        $real_views = 0;
        if (function_exists('get_post_views')) {
            $real_views = (int) get_post_views($post_id);
        } else {
            $p = function_exists('get_post') ? get_post($post_id) : null;
            if ($p && isset($p->view_count)) {
                $real_views = (int) $p->view_count;
            }
        }

        $boost = 0;
        if (function_exists('get_post_meta')) {
            $boost = (int) get_post_meta($post_id, '_views_boost', true);
        }

        return (string) max(0, $real_views + $boost);
    }

    /**
     * 解析文章分类名称标签
     */
    public function parse_post_category($attrs, $content, $data)
    {
        $res = '';
        if (is_array($data)) {
            if (!empty($data['category'])) {
                $res = (string) $data['category'];
            } elseif (!empty($data['category_name'])) {
                $res = (string) $data['category_name'];
            } elseif (!empty($data['post_category'])) {
                $res = (string) $data['post_category'];
            } elseif (!empty($data['article_category_name'])) {
                $res = (string) $data['article_category_name'];
            }
        }
        if ($res === '') {
            $ctx = $this->get_post_context($data);
            $post = $ctx['obj'];
            if ($post) {
                $pid = is_object($post) ? ($post->ID ?? 0) : (is_array($post) ? ($post['ID'] ?? $post['post_id'] ?? 0) : 0);
                if ($pid > 0) {
                    $categories = function_exists('todo_get_the_category') ? todo_get_the_category($pid) : [];
                    if (!empty($categories)) {
                        $first = $categories[0];
                        $res = is_object($first) ? ($first->name ?? '') : (is_array($first) ? ($first['name'] ?? '') : '');
                    }
                }
            }
        }
        if ($res !== '') {
            if (function_exists('todo_parse_inline_translations')) {
                $res = todo_parse_inline_translations($res);
            }
            if (function_exists('todo_translate')) {
                $res = todo_translate($res);
            }
        }
        return $res;
    }

    /**
     * 解析文章分类URL标签
     */
    public function parse_post_category_url($attrs, $content, $data)
    {
        if (is_array($attrs)) {
            if (isset($attrs['id']) && is_numeric($attrs['id'])) {
                $tid = (int) $attrs['id'];
                return function_exists('todo_get_category_link') ? todo_get_category_link($tid) : '';
            }
            if (isset($attrs['slug']) && is_string($attrs['slug']) && $attrs['slug'] !== '') {
                $slug = sanitize_title($attrs['slug']);
                if ($slug !== '' && function_exists('get_category_by_slug')) {
                    $cat = get_category_by_slug($slug);
                    $tid = 0;
                    if (is_object($cat) && isset($cat->term_id)) {
                        $tid = (int) $cat->term_id;
                    } elseif (is_array($cat) && isset($cat['term_id'])) {
                        $tid = (int) $cat['term_id'];
                    }
                    if ($tid > 0) {
                        return function_exists('todo_get_category_link') ? todo_get_category_link($tid) : '';
                    }
                }
                return '';
            }
        }

        if (is_array($data)) {
            if (!empty($data['category_url'])) {
                return (string) $data['category_url'];
            }
            if (!empty($data['article_category_url'])) {
                return (string) $data['article_category_url'];
            }
        }

        // 如果没有指定 id/slug 且不在文章页，返回 null 让调用方回退到数据数组查找
        $ctx = $this->get_post_context($data);
        $post = $ctx['obj'];
        if (!$post) {
            return null;
        }
        $pid = is_object($post) ? ($post->ID ?? 0) : (is_array($post) ? ($post['ID'] ?? $post['post_id'] ?? 0) : 0);
        if ($pid > 0) {
            $categories = function_exists('todo_get_the_category') ? todo_get_the_category($pid) : [];
            if (!empty($categories)) {
                return function_exists('todo_get_category_link') ? todo_get_category_link($categories[0]->term_id) : '';
            }
        }
        return '';
    }

    /**
     * 解析作者URL标签
     */
    public function parse_author_url($attrs, $content, $data)
    {
        if (is_array($data)) {
            if (!empty($data['author_url'])) {
                return (string) $data['author_url'];
            }
            if (!empty($data['article_author_url'])) {
                return (string) $data['article_author_url'];
            }
        }

        $ctx = $this->get_post_context($data);
        $post = $ctx['obj'];
        if (!$post) {
            return '';
        }

        $author_id = is_object($post) ? ($post->post_author ?? 0) : (is_array($post) ? ($post['post_author'] ?? 0) : 0);
        if ($author_id > 0) {
            if (function_exists('todo_get_user_url')) {
                return todo_get_user_url($author_id);
            }
            return get_author_posts_url($author_id);
        }

        return '';
    }

    /**
     * 解析文章修改日期标签
     */
    public function parse_post_modified($attrs, $content, $data)
    {
        global $post;

        if (!$post) {
            return '';
        }

        $format = isset($attrs['format']) ? $attrs['format'] : todo_get_option('date_format');
        return get_the_modified_date($format, $post->ID);
    }

    /**
     * 解析文章ID标签
     */
    public function parse_post_id($attrs, $content, $data)
    {
        global $post, $todo_post;
        
        $p = null;
        if (isset($data['post'])) {
            $p = $data['post'];
        } elseif (isset($data['todo_post'])) {
            $p = $data['todo_post'];
        } elseif ($post) {
            $p = $post;
        } elseif ($todo_post) {
            $p = $todo_post;
        }
        
        if (is_object($p)) {
            return isset($p->ID) ? (string)$p->ID : (isset($p->id) ? (string)$p->id : '');
        } elseif (is_array($p)) {
            return isset($p['ID']) ? (string)$p['ID'] : (isset($p['id']) ? (string)$p['id'] : '');
        }
        
        return '';
    }

    /**
     * 解析文章别名标签
     */
    public function parse_post_slug($attrs, $content, $data)
    {
        global $post;

        return $post ? $post->post_name : '';
    }

    /**
     * 解析文章特色图片URL标签
     */
    public function parse_post_thumbnail_url($attrs, $content, $data)
    {
        $ctx = $this->get_post_context($data);
        $post_obj = $ctx['obj'];
        $post_id = $ctx['id'];

        $override_id = isset($attrs['id']) ? intval($attrs['id']) : (isset($attrs['post_id']) ? intval($attrs['post_id']) : 0);
        if ($override_id > 0) {
            $post_id = $override_id;
            if (function_exists('get_post')) {
                $post_obj = get_post($override_id);
            }
        }
        $u = '';
        if ($post_id > 0 && function_exists('get_post_featured_image')) {
            $fi = get_post_featured_image($post_id);
            if (!empty($fi)) {
                $u = (string) $fi;
            }
        }
        if ($u === '' && function_exists('todo_match_featured_image_by_lang')) {
            $raw_fi = '';
            if (is_object($post_obj) && !empty($post_obj->featured_image)) {
                $raw_fi = $post_obj->featured_image;
            } elseif ($override_id <= 0 && is_array($data) && !empty($data['featured_image'])) {
                $raw_fi = $data['featured_image'];
            } elseif (is_array($post_obj) && !empty($post_obj['featured_image'])) {
                $raw_fi = $post_obj['featured_image'];
            }
            if (!empty($raw_fi)) {
                $matched_url = todo_match_featured_image_by_lang($raw_fi);
                if (!empty($matched_url)) {
                    $u = $matched_url;
                }
            }
        }
        if ($u === '' && $post_id > 0 && function_exists('get_the_post_thumbnail_url')) {
            $u = (string) get_the_post_thumbnail_url($post_id, 'full');
        } elseif ($u === '' && !empty($post_obj) && function_exists('get_the_post_thumbnail_url')) {
            if (is_object($post_obj)) {
                $u = (string) get_the_post_thumbnail_url($post_obj, 'full');
            } elseif (is_array($post_obj)) {
                $u = (string) get_the_post_thumbnail_url((object) $post_obj, 'full');
            }
        }
        if ($u === '') {
            $u = (string) $this->resolve_post_featured_image_url($post_obj);
        }
        if ($u !== '' && function_exists('todo_media_url_to_runtime_absolute')) {
            $u = todo_media_url_to_runtime_absolute($u);
        }
        return $u;
    }

    // ==================== 作者标签 ====================

    public function parse_author_name($attrs, $content, $data)
    {
        if (isset($data['author_name']) && $data['author_name'] !== '') {
            return esc_html($data['author_name']);
        }
        if (isset($data['comment_author']) && $data['comment_author'] !== '') {
            return esc_html($data['comment_author']);
        }

        global $post;

        if (!$post) {
            return '';
        }

        $display_name = get_the_author_meta('display_name', $post->post_author);
        if (!empty($display_name)) {
            return esc_html($display_name);
        }

        return esc_html(get_the_author_meta('login', $post->post_author));
    }

    /**
     * 解析作者显示名称标签
     */
    public function parse_author_display_name($attrs, $content, $data)
    {
        global $post;

        if (!$post) {
            return '';
        }

        return get_the_author_meta('display_name', $post->post_author);
    }

    /**
     * 解析作者邮箱标签
     */
    public function parse_author_email($attrs, $content, $data)
    {
        global $post;

        if (!$post) {
            return '';
        }

        return get_the_author_meta('email', $post->post_author);
    }

    /**
     * 解析作者简介标签
     */
    public function parse_author_description($attrs, $content, $data)
    {
        global $post;

        if (!$post) {
            return '';
        }

        return get_the_author_meta('description', $post->post_author);
    }

    /**
     * 解析作者头像标签
     */
    public function parse_author_avatar($attrs, $content, $data)
    {
        global $post;

        if (!$post) {
            return '';
        }

        $size = isset($attrs['size']) ? intval($attrs['size']) : '';
        return get_avatar($post->post_author, $size);
    }

    // ==================== 分类和标签 ====================

    /**
     * 解析文章分类循环标签
     */
    public function parse_post_categories($attrs, $content, $data)
    {
        global $post;

        if ((!$post || !isset($post->ID)) && isset($GLOBALS['todo_post']) && is_object($GLOBALS['todo_post'])) {
            $post = $GLOBALS['todo_post'];
        }

        if (!$post || !isset($post->ID)) {
            return '';
        }

        $categories = todo_get_the_category($post->ID);
        if (empty($categories)) {
            return '';
        }

        $output = '';

        // 优化：批量预加载多语言翻译，避免N+1查询
        $translations_map = [];
        if (function_exists('todo_get_term_text_multilang')) {
            $tids = [];
            foreach ($categories as $c) {
                if (isset($c->term_id))
                    $tids[] = $c->term_id;
            }
            if (!empty($tids)) {
                $translations_map = $this->batch_fetch_term_translations($tids, 'category');
            }
        }

        $index = 0;
        foreach ($categories as $category) {
            $index++;
            // 设置分类数据
            $parent_id = 0;
            $parent_category_name = '';
            $parent_category_slug = '';
            $parent_category_url = '';
            $top_category_id = 0;
            $top_category_name = $category->name;
            $top_category_slug = '';
            $top_category_url = '';
            // 通过 Todo_Category_Manager 获取完整信息（含 parent_id）
            if (class_exists('Todo_Category_Manager')) {
                $full_cat = Todo_Category_Manager::get_category($category->term_id);
                if (is_array($full_cat)) {
                    $parent_id = isset($full_cat['parent_id']) ? intval($full_cat['parent_id']) : (isset($full_cat['parent']) ? intval($full_cat['parent']) : 0);
                } elseif (is_object($full_cat)) {
                    $parent_id = isset($full_cat->parent_id) ? intval($full_cat->parent_id) : (isset($full_cat->parent) ? intval($full_cat->parent) : 0);
                }
                if ($parent_id > 0) {
                    $parent_cat = Todo_Category_Manager::get_category($parent_id);
                    if (is_array($parent_cat)) {
                        $parent_category_name = isset($parent_cat['name']) ? (string) $parent_cat['name'] : '';
                        $parent_category_slug = isset($parent_cat['slug']) ? (string) $parent_cat['slug'] : '';
                    } elseif (is_object($parent_cat)) {
                        $parent_category_name = isset($parent_cat->name) ? (string) $parent_cat->name : '';
                        $parent_category_slug = isset($parent_cat->slug) ? (string) $parent_cat->slug : '';
                    }
                    $parent_category_url = function_exists('todo_get_category_link') ? todo_get_category_link($parent_id) : '';
                }
                // 寻找顶级分类（depth=0）
                $ancestor_id = $category->term_id;
                $max_depth = 10;
                $depth_counter = 0;
                while ($ancestor_id > 0 && $depth_counter < $max_depth) {
                    $ancestor_cat = Todo_Category_Manager::get_category($ancestor_id);
                    $ancestor_parent = 0;
                    if (is_array($ancestor_cat)) {
                        $ancestor_parent = isset($ancestor_cat['parent_id']) ? intval($ancestor_cat['parent_id']) : (isset($ancestor_cat['parent']) ? intval($ancestor_cat['parent']) : 0);
                    } elseif (is_object($ancestor_cat)) {
                        $ancestor_parent = isset($ancestor_cat->parent_id) ? intval($ancestor_cat->parent_id) : (isset($ancestor_cat->parent) ? intval($ancestor_cat->parent) : 0);
                    }
                    if ($ancestor_parent === 0) {
                        $top_category_id = $ancestor_id;
                        if (is_array($ancestor_cat)) {
                            $top_category_name = isset($ancestor_cat['name']) ? (string) $ancestor_cat['name'] : '';
                            $top_category_slug = isset($ancestor_cat['slug']) ? (string) $ancestor_cat['slug'] : '';
                        } elseif (is_object($ancestor_cat)) {
                            $top_category_name = isset($ancestor_cat->name) ? (string) $ancestor_cat->name : '';
                            $top_category_slug = isset($ancestor_cat->slug) ? (string) $ancestor_cat->slug : '';
                        }
                        break;
                    }
                    $ancestor_id = $ancestor_parent;
                    $depth_counter++;
                }
                $top_category_url = function_exists('todo_get_category_link') ? todo_get_category_link($top_category_id) : '';
            }

            $category_data = [
                'category_id' => $category->term_id,
                'category_name' => $category->name,
                'category_slug' => $category->slug,
                'category_description' => $category->description,
                'category_url' => function_exists('todo_get_category_link') ? todo_get_category_link($category->term_id) : $this->get_term_link_optimized($category->slug, 'category', $category->term_id),
                'category_count' => $category->count,
                'index' => $index,
                'parent_category_id' => $parent_id,
                'parent_category_name' => $parent_category_name,
                'parent_category_slug' => $parent_category_slug,
                'parent_category_url' => $parent_category_url,
                'top_category_id' => $top_category_id,
                'top_category_name' => $top_category_name,
                'top_category_slug' => $top_category_slug,
                'top_category_url' => $top_category_url,
            ];
            // 多语言覆盖：名称与描述
            if (isset($translations_map[$category->term_id])) {
                $txt = $translations_map[$category->term_id];
                if (!empty($txt['name'])) {
                    $category_data['category_name'] = $txt['name'];
                }
                if (!empty($txt['description'])) {
                    $category_data['category_description'] = $txt['description'];
                }
            }

            // 解析内部内容
            // parser instance
            $parsed_content = CMS_Template_Compiler_V2::render_string($content, $category_data);
            $output .= $parsed_content;
        }

        return $output;
    }

    /**
     * 解析文章标签循环标签
     */
    public function parse_post_tags($attrs, $content, $data)
    {
        // 优先从 merged $data 获取 post_id
        $post_id = 0;
        if (is_array($data)) {
            if (isset($data['post_id']) && is_numeric($data['post_id'])) $post_id = (int) $data['post_id'];
            elseif (isset($data['ID']) && is_numeric($data['ID'])) $post_id = (int) $data['ID'];
            elseif (isset($data['id']) && is_numeric($data['id'])) $post_id = (int) $data['id'];
        }
        if ($post_id <= 0) {
            // 回退到 todo_post 全局上下文（todocms 约定）
            $todo_post = isset($GLOBALS['todo_post']) && is_object($GLOBALS['todo_post']) ? $GLOBALS['todo_post'] : null;
            if ($todo_post && isset($todo_post->ID)) {
                $post_id = (int) $todo_post->ID;
            }
        }

        if ($post_id <= 0) {
            return '';
        }

        $tags = todo_get_the_tags($post_id);
        if (empty($tags)) {
            return '';
        }

        $limit = 0;
        if (is_array($attrs) && isset($attrs['limit'])) {
            $limit = max(0, intval($attrs['limit']));
        }
        if ($limit > 0 && count($tags) > $limit) {
            $tags = array_slice($tags, 0, $limit);
        }

        // 优化：批量预加载多语言翻译，避免N+1查询
        $translations_map = [];
        if (function_exists('todo_get_term_text_multilang')) {
            $tids = [];
            foreach ($tags as $t) {
                $tid = isset($t->term_id) ? intval($t->term_id) : (isset($t->tag_id) ? intval($t->tag_id) : 0);
                if ($tid)
                    $tids[] = $tid;
            }
            if (!empty($tids)) {
                $translations_map = $this->batch_fetch_term_translations($tids, 'tag');
            }
        }

        $output = '';
        $index = 0;
        foreach ($tags as $tag) {
            $index++;
            $tid = isset($tag->term_id) ? intval($tag->term_id) : (isset($tag->tag_id) ? intval($tag->tag_id) : 0);
            $name = '';
            if (isset($tag->name) && $tag->name !== '') {
                $name = (string) $tag->name;
            } elseif (isset($tag->tag_title) && $tag->tag_title !== '') {
                $name = (string) $tag->tag_title;
            }

            // 多语言覆盖
            if (isset($translations_map[$tid])) {
                $txt = $translations_map[$tid];
                if (!empty($txt['name'])) {
                    $name = $txt['name'];
                }
            }

            if (function_exists('todo_parse_inline_translations')) {
                $name = todo_parse_inline_translations($name);
            }
            if (function_exists('todo_translate')) {
                $name = todo_translate($name);
            }

            $slug = isset($tag->slug) ? (string) $tag->slug : (isset($tag->tag_slug) ? (string) $tag->tag_slug : '');

            $href = '';
            if ($tid > 0 && function_exists('todo_get_tag_link')) {
                $href = (string) todo_get_tag_link($tid);
            }
            if ($href === '') {
                $base = function_exists('todo_get_site_url') ? todo_get_site_url() : '';
                if ($base === '' && function_exists('todo_home_url')) {
                    $base = rtrim((string) todo_home_url('/'), '/');
                }
                if ($base === '') {
                    $base = '/';
                }

                if ($slug !== '') {
                    $href = rtrim($base, '/') . '/tag/' . rawurlencode($slug) . '/';
                } elseif ($tid > 0) {
                    $href = rtrim($base, '/') . '/tag/' . $tid . '/';
                }

                if ($href !== '' && function_exists('current_locale') && function_exists('get_language_url')) {
                    $href = (string) get_language_url(current_locale(), $href);
                }
            }
            if ($href === '' && $tid > 0 && function_exists('todo_home_url')) {
                $href = (string) todo_home_url('?page=tag&id=' . $tid);
            }

            $tag_data = [
                'tag_id' => $tid,
                'tag_name' => $name,
                'tag_slug' => $slug,
                'tag_description' => isset($tag->description) ? (string) $tag->description : (isset($tag->tag_desc) ? (string) $tag->tag_desc : ''),
                'tag_url' => $href,
                'count' => isset($tag->count) ? intval($tag->count) : (isset($tag->post_count) ? intval($tag->post_count) : 0),
            ];

            if (isset($translations_map[$tid]) && !empty($translations_map[$tid]['description'])) {
                $tag_data['tag_description'] = $translations_map[$tid]['description'];
            }

            $tag_data['index'] = $index;

            // parser instance
            $output .= CMS_Template_Compiler_V2::render_string($content, $tag_data);
        }

        return $output;
    }

    private function parse_doc_property_ids_value($raw)
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map('intval', $raw), function ($v) {
                return $v > 0;
            }));
        }

        if (!is_string($raw)) {
            if (is_scalar($raw)) {
                $raw = (string) $raw;
            } else {
                return [];
            }
        }

        $raw = trim($raw);
        if ($raw === '' || $raw === '[]') {
            return [];
        }

        if (strpos($raw, 'a:') === 0) {
            $un = @unserialize($raw);
            if (is_array($un)) {
                return array_values(array_filter(array_map('intval', $un), function ($v) {
                    return $v > 0;
                }));
            }
        }

        if ($raw[0] === '[' || $raw[0] === '{') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_values(array_filter(array_map('intval', $decoded), function ($v) {
                    return $v > 0;
                }));
            }
        }

        $parts = array_map('trim', explode(',', $raw));
        return array_values(array_filter(array_map('intval', $parts), function ($v) {
            return $v > 0;
        }));
    }

    public function parse_post_property($attrs, $content, $data)
    {
        global $post, $tododb;
        if ((!$post || !isset($post->ID)) && isset($GLOBALS['todo_post']) && is_object($GLOBALS['todo_post'])) {
            $post = $GLOBALS['todo_post'];
        }

        if (!$post || !isset($post->ID)) {
            return '';
        }

        $raw = null;
        if (isset($post->doc_properties)) {
            $raw = $post->doc_properties;
        } elseif (is_array($data) && array_key_exists('doc_properties', $data)) {
            $raw = $data['doc_properties'];
        } elseif (is_array($data) && array_key_exists('post_doc_properties', $data)) {
            $raw = $data['post_doc_properties'];
        }

        $ids = $this->parse_doc_property_ids_value($raw);
        if (empty($ids)) {
            return '';
        }

        if (!isset($tododb)) {
            return '';
        }

        $limit = 0;
        if (is_array($attrs) && isset($attrs['limit'])) {
            $limit = max(0, intval($attrs['limit']));
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = $tododb->get_results(
            "SELECT id, name, value, display_name, description, is_system, is_active, sort_order FROM {$tododb->prefix}doc_properties WHERE is_active = 1 AND id IN ({$in}) ORDER BY sort_order ASC, id ASC",
            $ids
        );

        if (!is_array($rows) || empty($rows)) {
            return '';
        }

        if ($limit > 0 && count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
        }

        if ($content === null || trim((string) $content) === '') {
            return !empty($rows) ? '1' : '';
        }

        $content = (string) $content;

        $output = '';
        $index = 0;
        foreach ($rows as $row) {
            $index++;
            $rid = is_object($row) ? intval($row->id ?? 0) : intval($row['id'] ?? 0);
            if ($rid <= 0) {
                continue;
            }

            $name = is_object($row) ? (string) ($row->name ?? '') : (string) ($row['name'] ?? '');
            $display_name = is_object($row) ? (string) ($row->display_name ?? '') : (string) ($row['display_name'] ?? '');
            $value = is_object($row) ? (string) ($row->value ?? '') : (string) ($row['value'] ?? '');

            $property_data = [
                'property_id' => $rid,
                'property_name' => $display_name !== '' ? $display_name : $name,
                'property_value' => $value,
                'property_code' => $value,
                'property_desc' => is_object($row) ? (string) ($row->description ?? '') : (string) ($row['description'] ?? ''),
                'property_sort_order' => is_object($row) ? intval($row->sort_order ?? 0) : intval($row['sort_order'] ?? 0),
                'property_is_system' => is_object($row) ? intval($row->is_system ?? 0) : intval($row['is_system'] ?? 0),
                'index' => $index,
            ];

            $output .= CMS_Template_Compiler_V2::render_string($content, $property_data);
        }

        return $output;
    }

    public function parse_post_properties($attrs, $content, $data)
    {
        return $this->parse_post_property($attrs, $content, $data);
    }

    /**
     * 解析文章标签字符串标签
     */
    public function parse_post_tags_string($attrs, $content, $data)
    {
        global $post;

        if (!$post) {
            return '';
        }

        $tags = todo_get_the_tags($post->ID);
        if (empty($tags)) {
            return '';
        }

        // 支持选择字段：name 或 slug（默认 name）
        $field = isset($attrs['field']) ? strtolower(trim($attrs['field'])) : 'name';
        if ($field !== 'slug') {
            $field = 'name';
        }

        $values = [];

        // 优化：批量预加载多语言
        $translations_map = [];
        if ($field !== 'slug' && function_exists('todo_get_term_text_multilang')) {
            $tids = [];
            foreach ($tags as $t) {
                if (isset($t->term_id))
                    $tids[] = $t->term_id;
            }
            if (!empty($tids)) {
                $translations_map = $this->batch_fetch_term_translations($tids, 'tag');
            }
        }

        foreach ($tags as $tag) {
            if ($field === 'slug') {
                $values[] = $tag->slug;
            } else {
                $name = $tag->name;
                if (isset($translations_map[$tag->term_id]) && !empty($translations_map[$tag->term_id]['name'])) {
                    $name = $translations_map[$tag->term_id]['name'];
                }
                $values[] = $name;
            }
        }

        $separator = isset($attrs['separator']) ? $attrs['separator'] : ', ';
        return implode($separator, $values);
    }

    /**
     * 通用统一字段扩展与自动变体解析映射（适配所有页面场景与自定义字段类型）
     *
     * @param array $context 需补充/注入的上下文引用
     * @param string $key 字段名（如 se_zi_duan）
     * @param mixed $value 字段对应的值
     * @param string $prefix 主前缀名（默认为 'post_'）
     */
    public static function enrich_field_variants(&$context, $key, $value, $prefix = 'post_', $overwrite = false)
    {
        if (!is_string($key) || $key === '' || $key === 'post') {
            return;
        }

        if (is_string($value) && function_exists('todo_parse_inline_translations')) {
            $value = todo_parse_inline_translations($value);
        }

        // 1. 原始字段名与带前缀字段名绑定（如 se_zi_duan 和 post_se_zi_duan）
        if ($overwrite || !isset($context[$key])) {
            $context[$key] = $value;
        }
        if ($prefix !== '' && strpos($key, $prefix) !== 0) {
            $pref_key = $prefix . $key;
            if ($overwrite || !isset($context[$pref_key])) {
                $context[$pref_key] = $value;
            }
        }

        // 2. 多图/多文件/图集/媒体 JSON 字段，解析为 _images 变体数组、_count 与 _first 变体
        if (is_string($value) && $value !== '') {
            $resolved = self::resolve_category_images_array($value);
            if (!empty($resolved) && is_array($resolved)) {
                $img_key = $key . '_images';
                if (!isset($context[$img_key])) {
                    $context[$img_key] = $resolved;
                }
                if ($prefix !== '' && strpos($img_key, $prefix) !== 0) {
                    $pref_img_key = $prefix . $img_key;
                    if (!isset($context[$pref_img_key])) {
                        $context[$pref_img_key] = $resolved;
                    }
                }

                $cnt_key = $key . '_count';
                $cnt_val = count($resolved);
                if (!isset($context[$cnt_key])) {
                    $context[$cnt_key] = $cnt_val;
                }
                if ($prefix !== '' && strpos($cnt_key, $prefix) !== 0) {
                    $pref_cnt_key = $prefix . $cnt_key;
                    if (!isset($context[$pref_cnt_key])) {
                        $context[$pref_cnt_key] = $cnt_val;
                    }
                }

                if (isset($resolved[0]['url']) && $resolved[0]['url'] !== '') {
                    $first_key = $key . '_first';
                    $first_val = $resolved[0]['url'];
                    if (!isset($context[$first_key])) {
                        $context[$first_key] = $first_val;
                    }
                    if ($prefix !== '' && strpos($first_key, $prefix) !== 0) {
                        $pref_first_key = $prefix . $first_key;
                        if (!isset($context[$pref_first_key])) {
                            $context[$pref_first_key] = $first_val;
                        }
                    }
                }
            }
        } elseif (is_array($value) && !empty($value)) {
            $first = reset($value);
            if (is_array($first) && isset($first['url'])) {
                $img_key = $key . '_images';
                if (!isset($context[$img_key])) {
                    $context[$img_key] = $value;
                }
                if ($prefix !== '' && strpos($img_key, $prefix) !== 0) {
                    $pref_img_key = $prefix . $img_key;
                    if (!isset($context[$pref_img_key])) {
                        $context[$pref_img_key] = $value;
                    }
                }
            }
        }

        // 3. 地区选择器字段（管道分隔地区代码），解析为 _display 人类可读地址变体
        if (is_string($value) && $value !== '') {
            $region_display = self::resolve_region_display($value);
            if ($region_display !== '') {
                $disp_key = $key . '_display';
                if (!isset($context[$disp_key])) {
                    $context[$disp_key] = $region_display;
                }
                if ($prefix !== '' && strpos($disp_key, $prefix) !== 0) {
                    $pref_disp_key = $prefix . $disp_key;
                    if (!isset($context[$pref_disp_key])) {
                        $context[$pref_disp_key] = $region_display;
                    }
                }
            }
        }
    }

    // ==================== 循环标签 / 分类信息标签 ====================

    /**
     * 解析地区选择器字段为可读地址
     *
     * 原始值格式如：110000000000|110100000000|110102000000|110102001000|详细地址
     * 解析后如：北京市 北京市 西城区 西长安街街道 详细地址
     *
     * @param string $raw 管道分隔的地区代码
     * @return string 可读地址，解析失败返回空字符串
     */
    public static function resolve_region_display($raw)
    {
        if (empty($raw) || !is_string($raw)) {
            return '';
        }
        $parts = explode('|', $raw);
        $names = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            // 判断是否为12位数字代码
            if (preg_match('/^\d{12}$/', $part) && function_exists('todo_regions_find_name')) {
                $name = todo_regions_find_name($part);
                if ($name !== '') {
                    $names[] = $name;
                    continue;
                }
            }
            // 非标准代码，直接作为文本保留（如详细地址）
            $names[] = $part;
        }
        return implode(' ', $names);
    }

    /**
     * 解析栏目图片数据为可用的 src URL（单图模式取第一张）
     *
     * 栏目图片在 term_meta 中可能是：
     * 1. JSON 数组: [{"url":"https://..."}, ...]
     * 2. 纯 URL 字符串: "https://..."
     * 本方法始终返回一个可用于 <img src> 的 URL 字符串。
     *
     * @param mixed $raw 原始栏目图片数据
     * @return string 图片 URL，解析失败返回空字符串
     */
    private static function resolve_category_image_src($raw)
    {
        if (empty($raw)) {
            return '';
        }

        // 规范化 URL：优先从云存储解析，若非云存储则自动匹配本地/自建 CDN
        $normalize = function ($url) {
            $u = trim((string) $url);
            if ($u !== '' && function_exists('todo_media_url_to_runtime_absolute')) {
                $u = todo_media_url_to_runtime_absolute($u);
            }
            return $u;
        };

        if (is_array($raw)) {
            foreach ($raw as $item) {
                if (is_array($item) && isset($item['url']) && $item['url'] !== '') {
                    return $normalize($item['url']);
                }
                if (is_object($item) && isset($item->url) && $item->url !== '') {
                    return $normalize($item->url);
                }
            }
            return '';
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return self::resolve_category_image_src($decoded);
            }
            return $normalize($raw);
        }

        return '';
    }

    /**
     * 解析栏目图片数据为统一格式的图片数组（多图模式）
     *
     * 每个图片项结构：
     *   ['url' => string, 'thumb' => string, 'alt' => string, 'title' => string]
     *
     * @param mixed $raw 原始栏目图片数据
     * @return array 图片数组，解析失败返回空数组
     */
    public static function resolve_category_images_array($raw)
    {
        if (empty($raw)) {
            return [];
        }

        // 规范化 URL：优先从云存储解析，若非云存储则自动匹配本地/自建 CDN
        $normalize = function ($url) {
            $u = trim((string) $url);
            if ($u !== '' && function_exists('todo_media_url_to_runtime_absolute')) {
                $u = todo_media_url_to_runtime_absolute($u);
            }
            return $u;
        };

        $get_type_and_ext = function ($url) {
            $url_path   = parse_url($url, PHP_URL_PATH) ?: '';
            $ext        = strtolower(pathinfo($url_path, PATHINFO_EXTENSION));

            $video_exts = ['mp4', 'webm', 'mov', 'avi', 'mkv', 'flv', 'wmv', 'm4v', 'ogv'];
            $audio_exts = ['mp3', 'wav', 'ogg', 'aac', 'flac', 'm4a'];
            $html_exts  = ['html', 'htm'];
            $image_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'avif', 'tiff'];

            if (in_array($ext, $video_exts, true)) {
                $type = 'video';
            } elseif (in_array($ext, $audio_exts, true)) {
                $type = 'audio';
            } elseif (in_array($ext, $html_exts, true)) {
                $type = 'html';
            } elseif (in_array($ext, $image_exts, true)) {
                $type = 'image';
            } else {
                $type = $ext !== '' ? 'file' : 'image';
            }
            return [$type, $ext];
        };

        $list = [];

        // 字符串：先尝试 JSON 解析
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $raw = $decoded;
            } else {
                // 纯 URL 字符串 → 包装为单元素数组
                $trimmed = trim($raw);
                if ($trimmed === '' || strpos($trimmed, '<') !== false) {
                    return [];
                }
                $u = $normalize($trimmed);
                list($type, $ext) = $get_type_and_ext($u);
                $filename = pathinfo(parse_url($u, PHP_URL_PATH) ?: '', PATHINFO_FILENAME);
                $list[] = ['url' => $u, 'thumb' => $u, 'alt' => $filename, 'title' => $filename, 'type' => $type, 'ext' => $ext];
                return $list;
            }
        }

        if (!is_array($raw)) {
            return [];
        }

        foreach ($raw as $item) {
            $url   = '';
            $thumb = '';
            $alt   = '';
            $title = '';

            if (is_array($item)) {
                $url   = isset($item['url'])   ? $normalize($item['url'])   : '';
                $thumb = isset($item['thumb']) ? $normalize($item['thumb']) : (isset($item['url']) ? $normalize($item['url']) : '');
                $alt   = isset($item['alt'])   ? (string) $item['alt']   : (isset($item['title']) ? (string) $item['title'] : '');
                $title = isset($item['title']) ? (string) $item['title'] : $alt;
            } elseif (is_object($item)) {
                $url   = isset($item->url)   ? $normalize($item->url)   : '';
                $thumb = isset($item->thumb) ? $normalize($item->thumb) : $url;
                $alt   = isset($item->alt)   ? (string) $item->alt   : (isset($item->title) ? (string) $item->title : '');
                $title = isset($item->title) ? (string) $item->title : $alt;
            }

            if ($url !== '') {
                list($type, $ext) = $get_type_and_ext($url);
                if ($alt === '') {
                    $alt = $title !== '' ? $title : pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_FILENAME);
                }
                $list[] = compact('url', 'thumb', 'alt', 'title', 'type', 'ext');
            }
        }

        return $list;
    }

    /**
     * 渲染分类信息（非循环模式）
     *
     * 当模块标签使用 id=XX 参数时调用，获取指定分类/栏目的基础属性和自定义字段，
     * 并以 {module}_fieldname 格式命名输出到模板中，与循环模式使用不同命名空间。
     *
     * 使用示例：
     *   {{todo:products|id=2}}
     *     {{todo:products_name}}          // 产品栏目名
     *     {{todo:products_featured_image}} // 特色图像（自定义字段）
     *     {{todo:products_slug}}           // URL 别名
     *     {{todo:products_description}}    // 栏目描述
     *     {{todo:products_url}}            // 栏目链接
     *   {{/todo:products}}
     *
     * @param string $module_name 模块名（如 products, specials, downloads）
     * @param array  $attrs       标签属性
     * @param string $content     块内容
     * @param array  $data        当前数据上下文
     * @return string 渲染后的 HTML
     */
    private function render_category_info($module_name, $attrs, $content, $data)
    {
        $category_id = isset($attrs['id']) ? intval($attrs['id']) : 0;
        if ($category_id <= 0) {
            return '';
        }

        $this->capture_cache_dependency_token('term_visibility:' . $category_id);

        // 获取栏目/分类数据
        $category = null;
        if (class_exists('Todo_Category_Manager')) {
            $category = Todo_Category_Manager::get_category($category_id);
        }
        if (!$category && function_exists('todo_get_category')) {
            $cat_obj = todo_get_category($category_id);
            if ($cat_obj) {
                $category = is_object($cat_obj) ? (array) $cat_obj : $cat_obj;
            }
        }
        if (!$category && function_exists('get_category')) {
            $cat_obj = get_category($category_id);
            if ($cat_obj) {
                $category = is_object($cat_obj) ? (array) $cat_obj : $cat_obj;
            }
        }
        if (!$category || !is_array($category)) {
            return '';
        }

        // 构建前缀命名空间
        $prefix = $module_name . '_';
        $context = is_array($data) ? $data : [];

        // --- 基础字段 ---
        $context[$prefix . 'id']          = intval($category['term_id'] ?? $category['id'] ?? $category_id);
        $context[$prefix . 'name']        = isset($category['name']) ? (string) $category['name'] : '';
        $context[$prefix . 'slug']        = isset($category['slug']) ? (string) $category['slug'] : '';
        $context[$prefix . 'description'] = isset($category['description']) ? (string) $category['description'] : '';
        $context[$prefix . 'parent_id']   = intval($category['parent'] ?? $category['parent_id'] ?? 0);
        $context[$prefix . 'count']       = intval($category['count'] ?? 0);

        // --- URL ---
        $context[$prefix . 'url'] = '';
        if (function_exists('todo_get_category_url')) {
            $context[$prefix . 'url'] = todo_get_category_url($category_id);
        } elseif (function_exists('get_category_link')) {
            $context[$prefix . 'url'] = get_category_link($category_id);
        }

        // --- 父级关联 ---
        $parent_id = $context[$prefix . 'parent_id'];
        if ($parent_id > 0) {
            $parent_cat = null;
            if (class_exists('Todo_Category_Manager')) {
                $parent_cat = Todo_Category_Manager::get_category($parent_id);
            }
            if (!$parent_cat && function_exists('get_category')) {
                $p_obj = get_category($parent_id);
                if ($p_obj) {
                    $parent_cat = is_object($p_obj) ? (array) $p_obj : $p_obj;
                }
            }
            if ($parent_cat && is_array($parent_cat)) {
                $context[$prefix . 'parent_name'] = isset($parent_cat['name']) ? (string) $parent_cat['name'] : '';
                $context[$prefix . 'parent_slug'] = isset($parent_cat['slug']) ? (string) $parent_cat['slug'] : '';
            }
        }

        // --- term meta 自定义字段 ---
        $term_meta = function_exists('todo_get_term_attributes_multilang')
            ? todo_get_term_attributes_multilang($category_id)
            : (function_exists('get_term_meta') ? get_term_meta($category_id) : []);
        if (is_array($term_meta)) {
            // 内部时间控制字段
            $skip_keys = ['hidden_start', 'hidden_end', 'is_hidden'];
            foreach ($term_meta as $key => $value) {
                if (in_array($key, $skip_keys, true)) {
                    continue;
                }
                self::enrich_field_variants($context, $key, $value, $prefix);
            }

                $category_img = $term_meta['category_image'] ?? ($term_meta['cat_image'] ?? ($term_meta['category_featured_image'] ?? ($term_meta['featured_image'] ?? '')));
                if ($category_img !== '') {
                    $context['raw_category_featured_image'] = $category_img;
                    $context[$prefix . 'raw_category_featured_image'] = $category_img;
                    $resolved_src = self::resolve_category_image_src($category_img);
                    // 单图模式：第一张图的 URL
                    if (!isset($context[$prefix . 'featured_image'])) {
                        $context[$prefix . 'featured_image'] = $resolved_src;
                    }
                    if (!isset($context[$prefix . 'category_featured_image'])) {
                        $context[$prefix . 'category_featured_image'] = $resolved_src;
                    }
                    if (!isset($context['category_featured_image'])) {
                        $context['category_featured_image'] = $resolved_src;
                    }
                    // 多图模式：完整图片数组
                    if (!isset($context[$prefix . 'images'])) {
                        $context[$prefix . 'images'] = self::resolve_category_images_array($category_img);
                    }
                }

                // image 别名（如果直接用 image 作为字段名）
                $image_val = isset($term_meta['image']) ? $term_meta['image'] : '';
                if ($image_val !== '') {
                    $resolved_img_src = self::resolve_category_image_src($image_val);
                    if (!isset($context[$prefix . 'featured_image'])) {
                        $context[$prefix . 'featured_image'] = $resolved_img_src;
                    }
                    if (!isset($context[$prefix . 'category_featured_image'])) {
                        $context[$prefix . 'category_featured_image'] = $resolved_img_src;
                    }
                    if (!isset($context[$prefix . 'image'])) {
                        $context[$prefix . 'image'] = $resolved_img_src;
                    }
                    if (!isset($context[$prefix . 'images'])) {
                        $context[$prefix . 'images'] = self::resolve_category_images_array($image_val);
                    }
                }

                // banner 别名
                if (
                    isset($term_meta['banner']) && $term_meta['banner'] !== '' &&
                    !isset($context[$prefix . 'banner'])
                ) {
                    $context[$prefix . 'banner'] = $term_meta['banner'];
                }
            }

        // --- SEO / 扩展字段（仅在未由 term_meta 提供时使用默认值）---
        if (!isset($context[$prefix . 'title'])) {
            $context[$prefix . 'title'] = $context[$prefix . 'name'];
        }
        if (!isset($context[$prefix . 'seo_title'])) {
            $context[$prefix . 'seo_title'] = $context[$prefix . 'name'];
        }
        if (!isset($context[$prefix . 'seo_keywords'])) {
            $context[$prefix . 'seo_keywords'] = '';
        }
        if (!isset($context[$prefix . 'seo_description'])) {
            $context[$prefix . 'seo_description'] = $context[$prefix . 'description'];
        }

        // --- 渲染块内容 ---
        return CMS_Template_Compiler_V2::render_string($content, $context);
    }

    /**
     * 解析产品循环标签 {{todo:products}}
     * 支持 id=XX 参数获取分类信息（非循环模式）
     */
    public function parse_products($attrs, $content, $data)
    {
        if (isset($attrs['id'])) {
            return $this->render_category_info('products', $attrs, $content, $data);
        }
        return $this->render_loop_helper('products', $attrs, $content, $data);
    }

    /**
     * 解析职位循环标签 {{todo:jobs}}
     */
    public function parse_jobs($attrs, $content, $data)
    {
        if (isset($attrs['id'])) {
            return $this->render_category_info('jobs', $attrs, $content, $data);
        }
        return $this->render_loop_helper('jobs', $attrs, $content, $data);
    }

    /**
     * 解析专题循环标签 {{todo:specials}}
     */
    public function parse_specials($attrs, $content, $data)
    {
        if (isset($attrs['id'])) {
            return $this->render_category_info('specials', $attrs, $content, $data);
        }
        return $this->render_loop_helper('special_list', $attrs, $content, $data);
    }

    /**
     * 解析专题列表循环标签 {{todo:special_list}}
     */
    public function parse_special_list($attrs, $content, $data)
    {
        return $this->render_loop_helper('special_list', $attrs, $content, $data);
    }

    /**
     * 解析热门专题循环标签 {{todo:hot_specials}}
     */
    public function parse_hot_specials($attrs, $content, $data)
    {
        $attrs['model'] = 'special';
        $attrs['limit'] = isset($attrs['limit']) ? $attrs['limit'] : (isset($attrs['count']) ? $attrs['count'] : 4);
        $attrs['orderby'] = isset($attrs['orderby']) ? $attrs['orderby'] : 'views';
        $attrs['order'] = isset($attrs['order']) ? $attrs['order'] : 'DESC';
        return $this->render_loop_helper('special_list', $attrs, $content, $data);
    }

    /**
     * 解析留言板循环标签 {{todo:guestbooks}}
     */
    public function parse_guestbooks($attrs, $content, $data)
    {
        if (isset($attrs['id'])) {
            return $this->render_category_info('guestbooks', $attrs, $content, $data);
        }
        return $this->render_loop_helper('guestbooks', $attrs, $content, $data);
    }

    /**
     * 解析单页循环标签 {{todo:single_pages}}
     */
    public function parse_single_pages($attrs, $content, $data)
    {
        if (isset($attrs['id'])) {
            return $this->render_category_info('single_pages', $attrs, $content, $data);
        }
        return $this->render_loop_helper('single_pages', $attrs, $content, $data);
    }

    /**
     * 解析搜索结果循环标签 {{todo:search_results}}
     */
    public function parse_search_results($attrs, $content, $data)
    {
        if (isset($attrs['id'])) {
            return $this->render_category_info('search_results', $attrs, $content, $data);
        }
        return $this->render_loop_helper('search_results', $attrs, $content, $data);
    }

    /**
     * 解析图片循环标签 {{todo:images}}
     */
    public function parse_images($attrs, $content, $data)
    {
        if (isset($attrs['id'])) {
            return $this->render_category_info('images', $attrs, $content, $data);
        }
        return $this->render_loop_helper('images', $attrs, $content, $data);
    }

    /**
     * 解析下载循环标签 {{todo:downloads}}
     */
    public function parse_downloads($attrs, $content, $data)
    {
        if (isset($attrs['id'])) {
            return $this->render_category_info('downloads', $attrs, $content, $data);
        }
        return $this->render_loop_helper('downloads', $attrs, $content, $data);
    }

    /**
     * 解析媒体循环标签 {{todo:media}}
     */
    public function parse_media($attrs, $content, $data)
    {
        if (isset($attrs['id'])) {
            return $this->render_category_info('media', $attrs, $content, $data);
        }
        return $this->render_loop_helper('media', $attrs, $content, $data);
    }

    /**
     * 批量预加载文章自定义字段缓存
     */
    private function batch_preload_post_fields($posts)
    {
        if (empty($posts) || !is_array($posts)) {
            return;
        }

        $ids = [];
        $ids_by_type = [];

        foreach ($posts as $p) {
            $pid = 0;
            $ptype = '';

            if (is_object($p)) {
                $pid = isset($p->ID) ? (int) $p->ID : 0;
                $ptype = isset($p->post_type) ? $p->post_type : '';
            } elseif (is_array($p)) {
                $pid = isset($p['ID']) ? (int) $p['ID'] : 0;
                $ptype = isset($p['post_type']) ? $p['post_type'] : '';
            }

            if ($pid > 0) {
                $ids[] = $pid;
                if ($ptype) {
                    $ids_by_type[$ptype][] = $pid;
                }
            }
        }

        $ids = array_unique($ids);
        if (empty($ids)) {
            return;
        }

        // 优先使用 ORM 批量预加载（已在内部打通系统原生全局缓存池）
        if (class_exists('Todo_Post_ORM')) {
            Todo_Post_ORM::eager_load_all($ids);
        } else {
            // 回退到单独函数预加载
            if (function_exists('update_post_term_cache')) {
                update_post_term_cache($ids, 'category');
                update_post_term_cache($ids, 'post_tag');
            }

            if (function_exists('update_post_meta_cache')) {
                update_post_meta_cache($ids);
            }

            if (function_exists('todo_prime_post_translations')) {
                $cur_lang = function_exists('todo_get_current_language') ? todo_get_current_language() : 'zh-CN';
                todo_prime_post_translations($ids, $cur_lang);
            }
        }

        global $tododb;
        if (!isset($tododb)) {
            return;
        }

        // 1. 批量查询 post_custom_fields（使用预处理语句避免SQL注入）
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT post_id, field_key, field_value FROM {$tododb->prefix}post_custom_fields WHERE post_id IN ($placeholders)";
        $results = $tododb->get_results($sql, $ids);

        $grouped = [];
        if ($results) {
            foreach ($results as $r) {
                $pid = is_object($r) ? $r->post_id : $r['post_id'];
                if (!isset($grouped[$pid])) {
                    $grouped[$pid] = [];
                }
                $grouped[$pid][] = $r;
            }
        }

        // 1.5. 动态批量查询模型表 (model_fields_*)
        // 获取所有可能的模型类型（从post_type或model_id推断）
        $model_types_to_query = [];
        foreach ($ids_by_type as $type => $type_ids) {
            if (!empty($type_ids)) {
                $model_types_to_query[$type] = array_unique($type_ids);
            }
        }

        // 如果某些文章没有明确的post_type，尝试通过model_id获取
        if (class_exists('Todo_Model_Manager') && class_exists('Todo_Post_Fields_Manager')) {
            $model_ids_map = [];
            $fields_manager = Todo_Post_Fields_Manager::get_instance();
            foreach ($ids as $pid) {
                $model_id = $fields_manager->get_post_model_id($pid);
                if ($model_id > 0) {
                    $model_key = Todo_Model_Manager::get_model_key_by_id($model_id);
                    if ($model_key && !isset($model_types_to_query[$model_key])) {
                        if (!isset($model_ids_map[$model_key])) {
                            $model_ids_map[$model_key] = [];
                        }
                        $model_ids_map[$model_key][] = $pid;
                    }
                }
            }
            $model_types_to_query = array_merge_recursive($model_types_to_query, $model_ids_map);
        }

        // 批量查询所有模型表
        foreach ($model_types_to_query as $model_key => $model_ids) {
            if (empty($model_ids))
                continue;

            // 验证模型键名安全性（只允许字母数字下划线）
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $model_key)) {
                continue;
            }

            $table_name = $tododb->prefix . 'model_fields_' . $model_key;
            $unique_ids = array_unique($model_ids);
            $placeholders = implode(',', array_fill(0, count($unique_ids), '?'));

            try {
                $sql_model = "SELECT * FROM {$table_name} WHERE post_id IN ($placeholders)";
                $model_results = $tododb->get_results($sql_model, $unique_ids, ARRAY_A);

                if ($model_results) {
                    foreach ($model_results as $row) {
                        $pid = isset($row['post_id']) ? (int) $row['post_id'] : 0;
                        if ($pid > 0) {
                            if (!isset($grouped[$pid])) {
                                $grouped[$pid] = [];
                            }
                            foreach ($row as $k => $v) {
                                if ($k === 'post_id' || $k === 'id')
                                    continue;
                                $grouped[$pid][] = (object) [
                                    'field_key' => $k,
                                    'field_value' => $v
                                ];
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                // 忽略表不存在等错误，避免影响性能
            }
        }

        // 2. 写入缓存 (需模拟 Todo_Post_Fields_Manager 的过滤逻辑)
        if (!class_exists('Todo_Cache_Manager')) {
            return;
        }

        $ttl = function_exists('todo_get_fragment_cache_ttl') ? (int) todo_get_fragment_cache_ttl() : 120;
        $lang = function_exists('todo_get_current_language') ? todo_get_current_language() : (function_exists('get_current_language') ? get_current_language() : null);

        // 使用 Post Fields Manager
        $fields_manager = class_exists('Todo_Post_Fields_Manager') ? Todo_Post_Fields_Manager::get_instance() : null;

        foreach ($ids as $pid) {
            $cache_key = 'post_fields_values_' . (int) $pid;

            $model_id = $fields_manager ? $fields_manager->get_post_model_id($pid) : 0;

            $field_values = [];
            if (isset($grouped[$pid])) {
                foreach ($grouped[$pid] as $meta) {
                    $key = $meta->field_key;
                    $val = $meta->field_value;

                    if ($fields_manager && $fields_manager->is_custom_field($key, $model_id)) {
                        $field_values[$key] = maybe_unserialize($val);
                    } elseif (!$fields_manager) {
                        // 如果没有管理器，回退到直接返回所有字段
                        $field_values[$key] = maybe_unserialize($val);
                    }
                }
            }

            Todo_Cache_Manager::set($cache_key, $field_values, $ttl, 'post_fields', $lang);
        }
    }

    /**
     * 批量预加载模型固定字段（model_fields_* 表）
     * 
     * 直接从 model_fields_{key} 表批量查询所有 post_id 的字段数据，
     * 不经过 is_custom_field 过滤，确保固定字段（如 marketing_feature）也能被加载。
     * 
     * @param array $items 文章列表（对象或数组）
     * @param string $items_name 数据源名称（products/jobs/guestbooks 等）
     * @return array [post_id => [field_key => field_value], ...]
     */
    private function batch_preload_model_fields($items, $items_name)
    {
        if (empty($items)) return [];

        // 提取所有 post_id
        $post_ids = [];
        foreach ($items as $item) {
            $pid = 0;
            if (is_object($item)) {
                $pid = isset($item->ID) ? (int) $item->ID : (isset($item->article_id) ? (int) $item->article_id : 0);
            } elseif (is_array($item)) {
                $pid = isset($item['ID']) ? (int) $item['ID'] : (isset($item['article_id']) ? (int) $item['article_id'] : 0);
            }
            if ($pid > 0) $post_ids[] = $pid;
        }
        $post_ids = array_unique($post_ids);
        if (empty($post_ids)) return [];

        // 将 items_name 转换为 model key（去掉末尾的 's'）
        // products -> product, jobs -> job, guestbooks -> guestbook, etc.
        $model_key = $items_name;
        if (substr($model_key, -1) === 's') {
            $model_key = substr($model_key, 0, -1);
        }
        // 特殊映射
        $special_map = [
            'single_page' => 'single',
            'search_result' => 'search',
            'image' => 'image',
            'download' => 'download',
            'special_list' => 'special',
        ];
        if (isset($special_map[$model_key])) {
            $model_key = $special_map[$model_key];
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $model_key)) return [];

        global $tododb;
        if (!isset($tododb)) return [];

        $table_name = TABLE_PREFIX . 'model_fields_' . $model_key;

        // 如果表不存在，静默跳过
        if (!$tododb->table_exists($table_name)) return [];

        $placeholders = implode(',', array_fill(0, count($post_ids), '?'));
        $sql = "SELECT * FROM {$table_name} WHERE post_id IN ($placeholders)";
        $rows = $tododb->get_results($sql, $post_ids, ARRAY_A);

        $result = [];
        if ($rows) {
            foreach ($rows as $row) {
                $pid = isset($row['post_id']) ? (int) $row['post_id'] : 0;
                if ($pid <= 0) continue;
                if (!isset($result[$pid])) $result[$pid] = [];
                foreach ($row as $k => $v) {
                    if ($k === 'post_id' || $k === 'id') continue;
                    $result[$pid][$k] = $v;
                }
            }
        }

        return $result;
    }

    /**
     * 通用循环渲染辅助方法
     */
    private function render_loop_helper($items_name, $attrs, $content, $data)
    {
        // 分离分页/查询参数，传递给 get_loop_data 的第三个参数
        // $data 保持为父级上下文，$attrs 中的控制参数通过 $params 传入
        $paging_keys = ['per_page','limit','pager','page','offset','orderby','order','page_param','mid_size','end_size','doc_properties','doc_properties_strict','post_type','category','categories','cat','model','tag','tags','tag_id','category_id','properties','properties_id'];
        $paging_params = [];
        foreach ($paging_keys as $k) {
            if (isset($attrs[$k])) {
                $paging_params[$k] = $attrs[$k];
                unset($attrs[$k]);
            }
        }
        $loop_data = array_merge($data, $attrs);

        // 获取循环数据
        $items = CMS_Template_Compiler_V2::get_loop_data($items_name, $loop_data, $paging_params);
        if (!empty($items) && is_array($items)) {
            $this->batch_preload_post_fields($items);
        }

        // 当此标签作为块标签（TplTagNode）而非 TplLoopNode 渲染时，
        // get_loop_data 计算的分页数据存在 $loop_pagination 中但不会被 compileLoop 处理。
        // 手动将其处理并存入 $last_loop_pagination，供块外部的 {{todo:if pagination}} 使用。
        if (!empty(CMS_Template_Compiler_V2::$loop_pagination)) {
            $__rlh_pag = CMS_Template_Compiler_V2::$loop_pagination;
            $__rlh_base_url = function_exists('todo_get_current_url') ? todo_get_current_url() : (function_exists('todo_home_url') ? todo_home_url('/') : '/');
            $__rlh_page_param = isset($__rlh_pag['pagination_page_param']) ? $__rlh_pag['pagination_page_param'] : 'paged';
            $__rlh_mid_size   = isset($__rlh_pag['pagination_mid_size']) ? max(0, intval($__rlh_pag['pagination_mid_size'])) : 2;
            $__rlh_end_size   = isset($__rlh_pag['pagination_end_size']) ? max(0, intval($__rlh_pag['pagination_end_size'])) : 1;
            $__rlh_pag['pagination_prev_url'] = ($__rlh_pag['pagination_prev_page'] > 0)
                ? CMS_Template_Compiler_V2::build_paginated_url($__rlh_pag['pagination_prev_page'], $__rlh_base_url, $__rlh_page_param)
                : '';
            $__rlh_pag['pagination_next_url'] = ($__rlh_pag['pagination_next_page'] > 0)
                ? CMS_Template_Compiler_V2::build_paginated_url($__rlh_pag['pagination_next_page'], $__rlh_base_url, $__rlh_page_param)
                : '';
            $__rlh_pag['pagination_pages'] = CMS_Template_Compiler_V2::build_pagination_pages(
                $__rlh_pag['pagination_current_page'],
                $__rlh_pag['pagination_total_pages'],
                $__rlh_base_url,
                $__rlh_page_param,
                $__rlh_mid_size,
                $__rlh_end_size
            );
            CMS_Template_Compiler_V2::$last_loop_pagination = $__rlh_pag;
            CMS_Template_Compiler_V2::$loop_pagination = [];
        }

        if (empty($items)) {
            return '';
        }

        // 优化：批量预加载文章字段缓存
        $this->batch_preload_post_fields($items);

        // 批量预加载模型固定字段（model_fields_* 表），避免 per-item N+1 查询
        $batch_model_fields = $this->batch_preload_model_fields($items, $items_name);

        $output = '';
        $fields_manager = class_exists('Todo_Post_Fields_Manager') ? Todo_Post_Fields_Manager::get_instance() : null;
        $lang = function_exists('todo_get_current_language') ? todo_get_current_language() : (function_exists('get_current_language') ? get_current_language() : null);

        $index = 0;
        foreach ($items as $item) {
            $index++;
            // Ensure $item is an array to avoid "Cannot use object of type stdClass as array" errors and compatibility with array_merge/loop parsing.
            if (is_object($item)) {
                $item = (array) $item;
            }

            $pid = 0;
            if (isset($item['article_id'])) {
                $pid = intval($item['article_id']);
            } elseif (isset($item['ID'])) {
                $pid = intval($item['ID']);
            }

            if ($pid > 0) {
                if (!isset($item['id'])) {
                    $item['id'] = $pid;
                }
                if (!isset($item['title']) && isset($item['article_title'])) {
                    $item['title'] = $item['article_title'];
                }
                if (!isset($item['post_title']) && isset($item['title'])) {
                    $item['post_title'] = $item['title'];
                }
                if (!isset($item['summary']) && isset($item['article_excerpt'])) {
                    $item['summary'] = $item['article_excerpt'];
                }
                if (!isset($item['excerpt']) && isset($item['summary'])) {
                    $item['excerpt'] = $item['summary'];
                }
                if (!isset($item['url'])) {
                    if (isset($item['article_url'])) {
                        $item['url'] = $item['article_url'];
                    } elseif (function_exists('get_permalink')) {
                        $item['url'] = get_permalink($pid);
                    }
                }
                if (!isset($item['permalink']) && isset($item['url'])) {
                    $item['permalink'] = $item['url'];
                }
                if (!isset($item['featured_image']) && isset($item['article_featured_image'])) {
                    $item['featured_image'] = $item['article_featured_image'];
                }
            }

            $fields = [];
            if ($pid > 0) {
                if (class_exists('Todo_Cache_Manager')) {
                    $cache_key = 'post_fields_values_' . $pid;
                    $fields = Todo_Cache_Manager::get($cache_key, 'post_fields', $lang);
                }
                if (empty($fields) && $fields_manager) {
                    $fields = $fields_manager->get_post_fields_values($pid);
                }
            }

            $fields = is_array($fields) ? $fields : [];

            // 合并批量预加载的模型固定字段（避免 per-item 单条查询）
            if ($pid > 0 && isset($batch_model_fields[$pid])) {
                foreach ($batch_model_fields[$pid] as $bk => $bv) {
                    if (!isset($fields[$bk]) || $fields[$bk] === '' || $fields[$bk] === null) {
                        $fields[$bk] = $bv;
                    }
                }
            }

            if ($items_name === 'guestbooks' && $pid > 0) {
                // 如果 $fields 中没有非空的 contact_name，从 model_fields_guestbook / post_custom_fields 表查询
                if ((!isset($fields['contact_name']) || $fields['contact_name'] === '') && empty($item['guest_name'])) {
                    global $tododb;
                    $gb_row = $tododb->get_row(
                        "SELECT contact_name, contact_email, contact_phone, message_status FROM " . TABLE_PREFIX . "model_fields_guestbook WHERE post_id = ?",
                        [$pid],
                        'ARRAY_A'
                    );
                    if (is_array($gb_row)) {
                        $fields = array_merge($fields, $gb_row);
                    }
                    // 回退查 post_custom_fields
                    if ((!isset($fields['contact_name']) || $fields['contact_name'] === '') && empty($item['guest_name'])) {
                        $cf_rows = $tododb->get_results(
                            "SELECT field_key, field_value FROM " . TABLE_PREFIX . "post_custom_fields WHERE post_id = ? AND field_key IN ('contact_name','contact_email','contact_phone')",
                            [$pid]
                        );
                        if (is_array($cf_rows)) {
                            foreach ($cf_rows as $r) {
                                $r = is_object($r) ? (array) $r : $r;
                                $fk = $r['field_key'] ?? '';
                                $fv = (string) ($r['field_value'] ?? '');
                                if ($fk !== '' && $fv !== '') {
                                    $fields[$fk] = $fv;
                                }
                            }
                        }
                    }
                }
                if (!isset($item['guest_name']) || $item['guest_name'] === '') {
                    $item['guest_name'] = (!empty($fields['contact_name'])) ? (string) $fields['contact_name'] : '匿名用户';
                }
                $item['guest_avatar'] = mb_substr($item['guest_name'], 0, 1);
                if (!isset($item['guest_subject'])) {
                    $item['guest_subject'] = isset($item['post_title']) ? $item['post_title'] : (isset($item['title']) ? $item['title'] : '');
                }
                if (!isset($item['guest_message'])) {
                    $raw = isset($item['post_content']) ? $item['post_content'] : (isset($item['content']) ? $item['content'] : '');
                    $item['guest_message'] = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                if (!isset($item['guest_date'])) {
                    $item['guest_date'] = isset($item['post_date']) ? $item['post_date'] : '';
                }
                if (!isset($item['guest_email']) || $item['guest_email'] === '') {
                    $item['guest_email'] = (!empty($fields['contact_email'])) ? (string) $fields['contact_email'] : '';
                }
                if (!isset($item['guest_phone']) || $item['guest_phone'] === '') {
                    $item['guest_phone'] = (!empty($fields['contact_phone'])) ? (string) $fields['contact_phone'] : '';
                }
                if (!isset($item['guest_status'])) {
                    $item['guest_status'] = isset($fields['message_status']) ? $fields['message_status'] : 'pending';
                }

                // 查询该文章下所有评论（用于精确查询父节点作者名字）
                global $tododb;
                $all_comments_map_rows = $tododb->get_results(
                    "SELECT comment_ID, comment_author FROM " . TABLE_PREFIX . "comments WHERE comment_post_ID = ?",
                    [$pid],
                    'ARRAY_A'
                );
                $id_author_map = [];
                if (is_array($all_comments_map_rows)) {
                    foreach ($all_comments_map_rows as $cmr) {
                        $id_author_map[intval($cmr['comment_ID'])] = $cmr['comment_author'] ?? '';
                    }
                }

                $likes_join = "LEFT JOIN " . TABLE_PREFIX . "commentmeta m_likes ON (c.comment_ID = m_likes.comment_id AND m_likes.meta_key = 'likes_count')";
                $all_replies_raw = $tododb->get_results(
                    "SELECT c.*, COALESCE(m_likes.meta_value, 0) as likes_count, m_reply.meta_value as reply_to_name
                     FROM " . TABLE_PREFIX . "comments c
                     {$likes_join}
                     LEFT JOIN " . TABLE_PREFIX . "commentmeta m_reply ON (c.comment_ID = m_reply.comment_id AND m_reply.meta_key = 'reply_to_name')
                     WHERE c.comment_post_ID = ? AND c.comment_approved = '1'
                     ORDER BY c.comment_date ASC",
                    [$pid],
                    'ARRAY_A'
                );

                $formatted_replies = [];
                if (!empty($all_replies_raw) && is_array($all_replies_raw)) {
                    $top_guest_name = $item['guest_name'] ?? '';

                    foreach ($all_replies_raw as $r) {
                        $parent_id = intval($r['comment_parent'] ?? 0);
                        $target_name = '';
                        if (!empty($r['reply_to_name'])) {
                            $target_name = $r['reply_to_name'];
                        } elseif ($parent_id > 0 && !empty($id_author_map[$parent_id])) {
                            $target_name = $id_author_map[$parent_id];
                        } elseif ($parent_id > 0) {
                            $target_name = $top_guest_name;
                        }

                        $formatted_replies[] = [
                            'comment_id' => intval($r['comment_ID']),
                            'author_name' => htmlspecialchars($r['comment_author'] ?? ''),
                            'author_avatar' => mb_strtoupper(mb_substr($r['comment_author'] ?? '管', 0, 1, 'UTF-8')),
                            'comment_date' => $r['comment_date'] ?? '',
                            'comment_content' => nl2br(htmlspecialchars(html_entity_decode($r['comment_content'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'))),
                            'reply_to_name' => $target_name,
                            'likes_count' => intval($r['likes_count'] ?? 0),
                        ];
                    }
                }

                $sub_total = count($formatted_replies);
                $initial_replies = array_slice($formatted_replies, 0, 2);
                $has_more_replies = $sub_total > 2;

                $item['replies'] = $initial_replies;
                $item['replies_json'] = base64_encode(json_encode($formatted_replies, JSON_UNESCAPED_UNICODE));
                $item['sub_total'] = $sub_total;
                $item['has_more_replies'] = $has_more_replies;
                $item['more_replies_count'] = max(0, $sub_total - 2);

                if (!empty($formatted_replies)) {
                    $first_r = $formatted_replies[0];
                    $item['admin_reply'] = $first_r['comment_content'];
                    $item['admin_reply_time'] = $first_r['comment_date'];
                    $item['admin_reply_user'] = $first_r['author_name'];
                    $item['has_admin_reply'] = true;
                } else {
                    $item['admin_reply'] = '';
                    $item['admin_reply_time'] = '';
                    $item['admin_reply_user'] = '';
                    $item['has_admin_reply'] = false;
                }
            }

            if ($items_name === 'products' && $pid > 0) {
                $spec_type = isset($fields['spec_type']) && !empty($fields['spec_type']) ? $fields['spec_type'] : 'single';
                $fields['spec_type'] = $spec_type;
                
                // 检查是否有规格明细数据（JSON 字符串）
                $details_raw = isset($fields['spec_details']) && !empty($fields['spec_details']) ? $fields['spec_details'] : '';
                $details = [];
                if ($details_raw !== '') {
                    $parsed = json_decode($details_raw, true);
                    if (!is_array($parsed)) $parsed = maybe_unserialize($details_raw);
                    if (is_array($parsed)) {
                        $details = $parsed;
                    }
                }
                $has_spec_details = !empty($details);
                $fields['has_multiple_specs'] = $has_spec_details || ($spec_type === 'multi');

                $min_price = isset($fields['price']) ? floatval($fields['price']) : 0.0;
                $max_price = $min_price;
                $best_in_stock = null;

                if ($has_spec_details) {
                    $prices = [];
                    $best_in_stock_price = PHP_FLOAT_MAX;
                    foreach ($details as $d) {
                        // 兼容 price 和 users_price 两种键名
                        $p = isset($d['users_price']) ? floatval($d['users_price']) : (isset($d['price']) ? floatval($d['price']) : 0);
                        if ($p > 0) {
                            $prices[] = $p;
                            // 有库存的才纳入最低价候选
                            $s = isset($d['stock_count']) ? intval($d['stock_count']) : (isset($d['stock']) ? intval($d['stock']) : 0);
                            if ($s > 0 && $p < $best_in_stock_price) {
                                $best_in_stock_price = $p;
                                $best_in_stock = $d;
                            }
                        }
                    }
                    if (!empty($prices)) {
                        $min_price = min($prices);
                        $max_price = max($prices);
                    }
                    // 最低有库存组合的价格优先
                    if ($best_in_stock !== null) {
                        $min_price = $best_in_stock_price;
                    }
                }

                $fields['min_price'] = $min_price;
                $fields['max_price'] = $max_price;
                
                // original_price：优先取最低有库存组合的 crossed_price
                // 多规格时，如果没有 crossed_price 则保持 0（与详情页一致），不回退产品级别价格
                $orig_price = 0.0;
                if ($best_in_stock !== null && isset($best_in_stock['crossed_price']) && $best_in_stock['crossed_price'] !== '') {
                    $orig_price = floatval($best_in_stock['crossed_price']);
                }
                if ($orig_price <= 0 && !$has_spec_details) {
                    $orig_price = isset($fields['original_price']) ? floatval($fields['original_price']) : 0.0;
                }

                $actual_sales = isset($fields['actual_sales']) ? intval($fields['actual_sales']) : 0;
                $virtual_sales = isset($fields['virtual_sales']) ? intval($fields['virtual_sales']) : 0;
                $sales = $actual_sales + $virtual_sales;
                // 多规格：加上所有规格的 spec_sales_num 总和
                if ($has_spec_details) {
                    foreach ($details as $d) {
                        $sn = isset($d['spec_sales_num']) ? intval($d['spec_sales_num']) : 0;
                        $sales += $sn;
                    }
                }
                $fields['sales'] = $sales;
                $fields['total_sales'] = $sales;

                $fields['is_favorited'] = false;
                if (class_exists('Todo_Favorites_Manager')) {
                    $fields['is_favorited'] = Todo_Favorites_Manager::is_favorited($pid);
                }

                $fields['discount'] = 0;
                if ($orig_price > $min_price && $orig_price > 0) {
                    $fields['discount'] = round(($min_price / $orig_price) * 10, 1);
                }

                $fields['current_price'] = function_exists('todo_format_price') ? todo_format_price($min_price) : $min_price;
                $fields['original_price'] = ($orig_price > 0 && function_exists('todo_format_price')) ? todo_format_price($orig_price) : ($orig_price > 0 ? (string)$orig_price : '');



                // 先解析内联多语言，避免截断破坏 [lang|text] 语法
                $mf = isset($fields['marketing_feature']) ? (string) $fields['marketing_feature'] : '';
                if (function_exists('todo_parse_inline_translations')) {
                    $mf = todo_parse_inline_translations($mf);
                }

                // marketing_feature 截取至第一个分隔符前（英文逗号/中文逗号）
                if ($mf !== '') {
                    $separators = [',', '，'];
                    $firstPos = false;
                    foreach ($separators as $sep) {
                        $pos = mb_strpos($mf, $sep, 0, 'UTF-8');
                        if ($pos !== false && ($firstPos === false || $pos < $firstPos)) {
                            $firstPos = $pos;
                        }
                    }
                    if ($firstPos !== false) {
                        $mf = mb_substr($mf, 0, $firstPos, 'UTF-8');
                    }
                }
                $fields['marketing_feature'] = $mf;

                // is_available: 上架状态 + 库存 > 0（兼容单规格与多规格明细累计库存，完全同步 view_product.html 逻辑）
                $sale_status = isset($fields['sale_status']) ? $fields['sale_status'] : 'online';
                $is_on_sale  = ($sale_status !== 'warehouse');

                $total_stock = isset($fields['stock']) ? intval($fields['stock']) : 0;
                if (!empty($details) && is_array($details)) {
                    $sum_stock = 0;
                    foreach ($details as $d) {
                        $sum_stock += isset($d['stock_count']) ? intval($d['stock_count']) : (isset($d['stock']) ? intval($d['stock']) : 0);
                    }
                    $total_stock = $sum_stock;
                }

                $fields['is_available'] = ($is_on_sale && $total_stock > 0);
                $fields['is_on_sale']   = $is_on_sale;
                $fields['is_in_stock']  = ($total_stock > 0);
                $fields['stock']        = $total_stock;

                // unit_text: 规格数量 + 单位中文（与小程序 formatUnitText 一致）
                $unit_qty = isset($fields['unit_qty']) ? floatval($fields['unit_qty']) : 1.0;
                $unit     = isset($fields['unit']) && $fields['unit'] !== '' ? (string) $fields['unit'] : 'piece';
                $u_map    = ['piece'=>__('件'),'item'=>__('个'),'set'=>__('套'),'pair'=>__('双'),'pack'=>__('包'),'bag'=>__('袋'),'box'=>__('盒'),'bottle'=>__('瓶'),'can'=>__('罐'),'roll'=>__('卷'),'strip'=>__('条'),'sheet'=>__('张'),'portion'=>__('份'),'bowl'=>__('碗'),'plate'=>__('盘'),'cup'=>__('杯'),'bunch'=>__('把'),'head'=>__('颗/头'),'kg'=>__('千克'),'g'=>__('克'),'jin'=>'斤','liang'=>'两','ml'=>__('毫升'),'l'=>__('升'),'other'=>__('其他')];
                $u_text   = isset($u_map[$unit]) ? $u_map[$unit] : ($unit ?: __('件'));
                if ($unit_qty !== 1.0 && $unit_qty > 0) {
                    $u_text = rtrim(rtrim(number_format($unit_qty, 2), '0'), '.') . $u_text;
                }
                $fields['unit_text'] = $u_text;

                // activities: 活动分类（与小程序 API 一致）
                $fields['activities'] = [];
                if ($pid > 0 && function_exists('get_the_terms')) {
                    $terms = get_the_terms($pid, 'category');
                    if ($terms && is_array($terms)) {
                        $now_ts = function_exists('current_time') ? current_time('timestamp') : time();
                        foreach ($terms as $term) {
                            $tid = intval($term->term_id);
                            $this->capture_cache_dependency_token('term_visibility:' . $tid);
                            if (function_exists('get_term_meta')) {
                                $is_hidden = get_term_meta($tid, 'is_hidden', true);
                                if ($is_hidden) continue;
                                $start = get_term_meta($tid, 'hidden_start', true);
                                $end   = get_term_meta($tid, 'hidden_end', true);
                                $has_start = ($start && trim($start) !== '');
                                $has_end   = ($end && trim($end) !== '');
                                if (!$has_start && !$has_end) continue;
                                $is_active = true;
                                if ($has_start) {
                                    $start_ts = strtotime($start);
                                    if ($start_ts && $start_ts > $now_ts) $is_active = false;
                                }
                                if ($is_active && $has_end) {
                                    $end_ts = strtotime($end);
                                    if ($end_ts && $end_ts < $now_ts) $is_active = false;
                                }
                                if ($is_active) {
                                    $fields['activities'][] = [
                                        'id'   => $tid,
                                        'name' => (string) $term->name,
                                    ];
                                }
                            }
                        }
                    }
                }
            }

            if (($items_name === 'specials' || $items_name === 'special_list') && $pid > 0) {
                // 特殊字段与别名转换
                $item['special_id'] = $pid;
                $item['special_url'] = isset($item['url']) ? $item['url'] : (function_exists('get_permalink') ? get_permalink($pid) : '');
                
                // 封面图: 复用已有 parse_featured_images 处理多图/JSON/序列化/直接URL
                $thumb_raw = isset($fields['thumb']) ? $fields['thumb'] : (isset($item['thumb']) ? $item['thumb'] : '');
                $images = CMS_Template_Compiler_V2::parse_featured_images($thumb_raw);
                $cover_url = !empty($images) ? $images[0]['url'] : '';
                if ($cover_url === '' && isset($item['featured_image'])) {
                    $cover_url = $item['featured_image'];
                }
                $item['cover_image'] = $cover_url;
                $item['thumb'] = $cover_url;

                // 运营开关
                $item['is_hot'] = isset($fields['is_hot']) ? (bool)$fields['is_hot'] : false;
                $item['is_recommend'] = isset($fields['is_recommend']) ? (bool)$fields['is_recommend'] : false;
                $item['is_featured'] = $item['is_recommend'];

                // 统计该专题包含的文章内容数量
                $related_posts = isset($fields['related_posts']) ? $fields['related_posts'] : '';
                $content_count = 0;
                $pids = [];
                if (!empty($related_posts)) {
                    if (is_array($related_posts)) {
                        $pids = array_map('intval', $related_posts);
                    } else {
                        $t = trim((string)$related_posts);
                        if ($t !== '') {
                            if ($t[0] === '[' && substr($t, -1) === ']') {
                                $decoded = json_decode($t, true);
                                if (is_array($decoded)) $pids = array_map('intval', $decoded);
                            } else {
                                $pids = array_map('intval', preg_split('/[\r\n,]+/', $t));
                            }
                        }
                    }
                    $pids = array_filter($pids, function($v) { return $v > 0; });
                    $content_count = count($pids);
                }
                $item['content_count'] = $content_count;
                $item['total_contents'] = $content_count;

                // 内容预览 (preview_contents)
                if (!empty($pids)) {
                    $preview_ids = array_slice($pids, 0, 3);
                    $preview_items = [];
                    foreach ($preview_ids as $ppid) {
                        if (function_exists('get_post')) {
                            $pobj = get_post($ppid);
                            if ($pobj) {
                                $preview_items[] = [
                                    'content_id' => $pobj->ID,
                                    'title' => $pobj->post_title,
                                    'content_url' => function_exists('get_permalink') ? get_permalink($pobj->ID) : '',
                                    'thumbnail' => function_exists('get_the_post_thumbnail_url') ? get_the_post_thumbnail_url($pobj->ID) : '',
                                    'content_type_name' => '文章',
                                ];
                            }
                        }
                    }
                    $item['preview_contents'] = $preview_items;
                    $item['has_more_contents'] = $content_count > 3;
                } else {
                    $item['preview_contents'] = [];
                    $item['has_more_contents'] = false;
                }
            }

            $item['attr'] = [
                'is_featured' => isset($fields['is_featured']) ? (bool)$fields['is_featured'] : (isset($item['is_featured']) ? (bool)$item['is_featured'] : false),
                'is_new' => isset($fields['is_new']) ? (bool)$fields['is_new'] : false,
                'is_hot' => isset($fields['is_hot']) ? (bool)$fields['is_hot'] : (isset($item['is_hot']) ? (bool)$item['is_hot'] : false),
            ];

            foreach ($fields as $fk => $fv) {
                self::enrich_field_variants($item, $fk, $fv, 'post_', true);
            }

            // 将当前项数据合并到上下文
            $context = array_merge($data, $item);
            // 添加 item 引用和索引
            $context['item'] = $item;
            $context['index'] = $index;

            // 也同步设置 global $post
            global $post, $todo_post;
            $original_global_post = $post;
            $original_global_todo_post = $todo_post ?? null;
            if ($pid > 0 && function_exists('get_post')) {
                $post = get_post($pid);
                if ($post) {
                    $todo_post = $post;
                }
            }

            // 解析内部内容
            $output .= CMS_Template_Compiler_V2::render_string($content, $context);

            // 还原 global $post
            $post = $original_global_post;
            if ($original_global_todo_post !== null) {
                $todo_post = $original_global_todo_post;
            } else {
                unset($todo_post);
            }
        }

        return $output;
    }

    public function parse_articles($attrs, $content, $data)
    {
        // 分类信息模式：仅在无块内容体 (content 为空) 且未带列表查询参数时，id="7" 才被当做获取单分类信息的单标签使用
        $has_list_params = isset($attrs['limit']) || isset($attrs['per_page']) || isset($attrs['orderby']) || isset($attrs['noid']) || isset($attrs['doc_properties']);
        $has_block_content = !empty(trim((string)$content));
        if (isset($attrs['id']) && !$has_list_params && !$has_block_content) {
            $raw_id = trim((string)$attrs['id']);
            if (is_numeric($raw_id)) {
                return $this->render_category_info('articles', $attrs, $content, $data);
            }
        }

        // 重置相邻文章上下文，防止上一页/下一页标签污染侧边栏等独立的文章循环
        $this->todo_adjacent_post = null;

        // 分类筛选
        // 自动检测仅用于"无显式查询参数"的被动场景（如分类/详情页主内容区）。
        // 当标签已显式指定 limit/orderby/order 等参数但无 category/cat/tag/id 时，
        // 说明这是一个独立的查询组件（如侧边栏小部件），不应继承父级分类上下文。
        $has_explicit_query = isset($attrs['limit']) || isset($attrs['orderby']) || isset($attrs['order'])
            || isset($attrs['per_page']);
        $has_explicit_filter = isset($attrs['category']) || isset($attrs['cat']) || isset($attrs['id']) || isset($attrs['tag'])
            || isset($attrs['tag_id']);
        $skip_auto_detect = $has_explicit_query && !$has_explicit_filter;

        // 独立查询组件（侧边栏等）：不限定 post_type，与主页面 loop_posts 行为一致，
        // 确保能显示所有类型的文章（article/product 等），避免遗漏非 article 类型内容
        $default_post_type = ($skip_auto_detect && !isset($attrs['post_type'])) ? 'any' : 'article';

        $args = [
            'post_type' => isset($attrs['post_type']) ? $attrs['post_type'] : $default_post_type,
            'posts_per_page' => isset($attrs['limit']) ? intval($attrs['limit']) : 10,
            'post_status' => 'publish',
            'paged' => 1, // 侧边栏/小部件查询始终取第1页，不受全局分页影响
        ];

        // 可见性筛选（支持 public/readable/private/password/any，以及逗号分隔如 visibility="public,readable"）
        $visibility = $attrs['visibility'] ?? ($attrs['post_visibility'] ?? null);
        if ($visibility !== null && $visibility !== '') {
            $vis_trimmed = trim((string)$visibility);
            if (strpos($vis_trimmed, ',') !== false) {
                $args['visibility'] = array_values(array_filter(array_map('trim', explode(',', $vis_trimmed))));
            } else {
                $args['visibility'] = $vis_trimmed;
            }
        }
        
        // 只有明确指定了 site_id 才传入，否则不传，让底层的 get_posts 自动获取当前环境 site_id
        if (isset($attrs['site_id'])) {
            $args['site_id'] = intval($attrs['site_id']);
        }

        // 分类筛选优先级：
        // 1. 显式传参（id / category / cat）最高优先级，不受页面自动检测影响
        // 2. 未显式传参且未跳过自动检测时，继承当前页面分类上下文
        $category = null;
        if (isset($attrs['category']) && $attrs['category'] !== '') {
            $category = $attrs['category'];
        } elseif (isset($attrs['cat']) && $attrs['cat'] !== '') {
            $category = $attrs['cat'];
        } elseif (isset($attrs['id']) && $attrs['id'] !== '') {
            $category = $attrs['id'];
        } elseif (!$skip_auto_detect && function_exists('get_current_category_id') && get_current_category_id() > 0) {
            $category = get_current_category_id();
        } elseif (!$skip_auto_detect && function_exists('get_the_category')) {
            $ctx = $this->get_post_context($data);
            if ($ctx['id'] > 0) {
                $cats = get_the_category($ctx['id']);
                if (is_array($cats) && !empty($cats)) {
                    $category = (int)$cats[0]->term_id;
                }
            }
        }

        if ($category !== null && $category !== '') {
            $cat_str = trim((string) $category);
            // 检查是否包含数字或逗号分隔的多数字ID（例如 "7,16" 或 7）
            $cat_raw = is_array($category) ? $category : explode(',', $cat_str);
            $cat_ids = array_values(array_filter(array_map('intval', (array)$cat_raw), function ($v) { return $v > 0; }));
            if (!empty($cat_ids) && count($cat_ids) === count($cat_raw)) {
                $args['cat'] = implode(',', $cat_ids);
            } else {
                $args['category_name'] = $cat_str;
            }
        }

        // 标签筛选
        if (isset($attrs['tag'])) {
            $args['tag'] = $attrs['tag'];
        } elseif (isset($attrs['tag_id'])) {
            $args['tag_id'] = intval($attrs['tag_id']);
        } elseif (!$skip_auto_detect) {
            // 未显式指定标签时，自动检测当前标签页上下文
            if (function_exists('is_tag') && is_tag() && function_exists('get_current_tag')) {
                $ctx_tag = get_current_tag();
                if ($ctx_tag) {
                    if (isset($ctx_tag->tag_id) && $ctx_tag->tag_id > 0) {
                        $args['tag_id'] = intval($ctx_tag->tag_id);
                    } elseif (isset($ctx_tag->id) && $ctx_tag->id > 0) {
                        $args['tag_id'] = intval($ctx_tag->id);
                    }
                }
            }
        } else {
            // 独立查询组件（如侧边栏小部件）：不在本层强制禁用标签上下文，
            // 由 query_posts() 自然检测当前页上下文。
            // 若结果为空，后续回退逻辑将移除上下文重新查询。
        }

        // 排序
        $custom_orderby = null;
        if (isset($attrs['orderby'])) {
            $raw_ob = trim((string)$attrs['orderby']);
            if ($raw_ob === 'recommend') {
                $custom_orderby = 'recommend';
            } else {
                $orderby_map = [
                    'views' => 'view_count',
                    'hits' => 'view_count',
                    'view_count' => 'view_count',
                    'date' => 'post_date',
                    'id' => 'ID',
                ];
                $args['orderby'] = $orderby_map[$raw_ob] ?? $raw_ob;
            }
        }

        if (isset($attrs['order'])) {
            $args['order'] = $attrs['order'];
        }

        // 1. 获取 URL GET 中的属性过滤参数（严格使用规范名称 doc_properties，容错兼容 property）
        $url_doc_prop_ids = [];
        $url_prop_val = $_GET['doc_properties'] ?? ($_GET['property'] ?? '');
        if (!empty($url_prop_val)) {
            $raw = trim((string)$url_prop_val);
            $raw = str_replace(',', ' ', $raw);
            $parts = preg_split('/\s+/', $raw);
            foreach ($parts as $p) {
                $p = trim($p);
                if (is_numeric($p) && intval($p) > 0) {
                    $url_doc_prop_ids[] = intval($p);
                }
            }
            $url_doc_prop_ids = array_values(array_unique($url_doc_prop_ids));
        }

        // 2. 获取标签属性中的 doc_properties（如 doc_properties="1" 表示头条文章置顶）
        $tag_doc_prop_ids = [];
        $tag_prop_val = $attrs['doc_properties'] ?? '';
        if ($tag_prop_val !== '') {
            $raw = trim((string)$tag_prop_val);
            $raw = str_replace(',', ' ', $raw);
            $parts = preg_split('/\s+/', $raw);
            foreach ($parts as $p) {
                $p = trim($p);
                if (is_numeric($p) && intval($p) > 0) {
                    $tag_doc_prop_ids[] = intval($p);
                }
            }
            $tag_doc_prop_ids = array_values(array_unique($tag_doc_prop_ids));
        }

        // URL 参数优先作为严格硬性过滤
        $is_url_strict_filter = !empty($url_doc_prop_ids);
        $filter_doc_prop_ids = $is_url_strict_filter ? $url_doc_prop_ids : $tag_doc_prop_ids;

        // orderby="recommend" 等价于 doc_properties="1,2,3" 置顶排序
        if (empty($filter_doc_prop_ids) && $custom_orderby === 'recommend') {
            $filter_doc_prop_ids = [1, 2, 3];
        }

        // 若该查询块按文档属性过滤（如侧边栏"推荐文章"块 doc_properties="2,3"），
        // 按属性登记跨分类依赖 token，使任何引用页面（如 ?cat=13 的 sidebar）
        // 在对应属性文章发布/变更时能被精准失效
        if (!empty($filter_doc_prop_ids)) {
            foreach ($filter_doc_prop_ids as $dp_id) {
                $this->capture_cache_dependency_token('post:doc_prop:' . intval($dp_id));
            }
        }

        // 有文档属性过滤时，多查一些保证过滤/排序后数量足够
        $requested_limit = isset($attrs['limit']) ? max(1, intval($attrs['limit'])) : (isset($attrs['per_page']) ? max(1, intval($attrs['per_page'])) : 10);
        if (!empty($filter_doc_prop_ids)) {
            $args['orderby'] = 'post_date';
            $args['order'] = 'DESC';
            $args['posts_per_page'] = $requested_limit * 3;
            $args['numberposts'] = 0; // 禁用 numberposts，让 posts_per_page 生效
        } else {
            if (!isset($args['posts_per_page'])) {
                $args['posts_per_page'] = $requested_limit;
            }
        }

        // 保存原始查询
        global $todo_query;
        $original_query = $todo_query;

        // noid 排除参数：排除指定 category_id 的文章
        if (isset($attrs['noid']) && $attrs['noid'] !== '') {
            $noid_raw = is_array($attrs['noid']) ? $attrs['noid'] : explode(',', (string) $attrs['noid']);
            $noid = array_values(array_filter(array_map('intval', (array) $noid_raw), function ($v) { return $v > 0; }));
            if (!empty($noid)) {
                $args['noid'] = $noid;
            }
        }

        // 仅在 URL 显式指定属性过滤或标签显式要求 strict 过滤时，才给底层 SQL 增加硬性 WHERE 约束
        $is_strict = $is_url_strict_filter || !empty($attrs['doc_properties_strict']);
        if (!empty($filter_doc_prop_ids)) {
            if ($is_strict) {
                $args['doc_properties'] = $filter_doc_prop_ids;
            } else {
                $args['orderby_doc_properties'] = $filter_doc_prop_ids;
            }
        }

        // 执行新查询
        query_posts($args);

        // 文档属性过滤与置顶排序：
        // 如果是 strict 过滤，仅保留匹配项；
        // 如果是置顶排序（如 doc_properties="1"），匹配项排最前面（头条优先），非匹配项随其后，保证总条数符合 limit。
        $apply_doc_properties_filter = function() use (&$filter_doc_prop_ids, $attrs, $is_strict, $requested_limit) {
            global $todo_query;
            if (empty($filter_doc_prop_ids)) return;
            $filtered = [];
            $meta_keys = [];
            foreach ($todo_query as $key => $value) {
                if (is_int($key) && (is_object($value) || is_array($value))) {
                    $doc_raw = is_object($value) ? ($value->doc_properties ?? '') : ($value['doc_properties'] ?? '');
                    $prop_ids = $this->parse_doc_property_ids_value($doc_raw);
                    // 统计匹配的属性数量和最高属性优先级
                    $match_count = 0;
                    $max_attr_priority = 0; // 头条(1)最高 → 推荐(2) → 加推(3)
                    foreach ($filter_doc_prop_ids as $fid) {
                        if (in_array($fid, $prop_ids, true)) {
                            $match_count++;
                            // 单个属性优先级：头条=1(最高), 推荐=2, 加推=3
                            $attr_p = ($fid == 1) ? 1 : (($fid == 2) ? 2 : 3);
                            if ($max_attr_priority == 0 || $attr_p < $max_attr_priority) {
                                $max_attr_priority = $attr_p;
                            }
                        }
                    }
                    if ($is_strict) {
                        if ($match_count > 0) {
                            $post_date = is_object($value) ? ($value->post_date ?? '') : ($value['post_date'] ?? '');
                            $sort_key = sprintf('%d_%d', $match_count, (4 - $max_attr_priority));
                            $filtered[] = ['value' => $value, 'sort_key' => $sort_key, 'date' => $post_date];
                        }
                    } else {
                        $post_date = is_object($value) ? ($value->post_date ?? '') : ($value['post_date'] ?? '');
                        if ($match_count > 0) {
                            $sort_key = sprintf('%d_%d', $match_count, (4 - $max_attr_priority));
                        } else {
                            $sort_key = '0_0';
                        }
                        $filtered[] = ['value' => $value, 'sort_key' => $sort_key, 'date' => $post_date];
                    }
                } else {
                    $meta_keys[$key] = $value;
                }
            }

            // 按匹配数量降序 → 属性优先级降序 → 日期降序
            usort($filtered, function($a, $b) {
                if ($a['sort_key'] !== $b['sort_key']) return strcmp($b['sort_key'], $a['sort_key']);
                return strcmp($b['date'], $a['date']);
            });

            // 取 requested_limit 条
            $filtered = array_slice($filtered, 0, $requested_limit);

            // 重建 todo_query
            $todo_query = [];
            foreach ($meta_keys as $mk => $mv) {
                $todo_query[$mk] = $mv;
            }
            foreach ($filtered as $i => $fi) {
                $todo_query[$i] = $fi['value'];
            }
            $todo_query['current_post'] = -1;
            $todo_query['post_count'] = count($filtered);
            $todo_query['posts_per_page'] = $requested_limit;
        };

        $apply_doc_properties_filter();

        $post_count = isset($todo_query['post_count']) ? $todo_query['post_count'] : 0;

        // 回退逻辑：无显式过滤参数的独立查询组件（如侧边栏小部件），
        // 若当前页上下文（标签/分类）过滤后无结果，则回退到不带上下文的查询。
        // 注意：此检查位于 doc_properties 过滤之后，确保"优先上下文内推荐文章，
        // 无数据时才回退"的逻辑正确。
        if ($post_count === 0 && $skip_auto_detect) {
            $fallback_args = $args;
            $needs_fallback = false;

            if (function_exists('is_tag') && is_tag()) {
                $fallback_args['tag_id'] = 0;
                unset($fallback_args['tag']);
                $needs_fallback = true;
            }

            if (function_exists('is_category') && is_category()) {
                $fallback_args['cat'] = 0;
                unset($fallback_args['category_name']);
                $needs_fallback = true;
            }

            if ($needs_fallback) {
                query_posts($fallback_args);
                $apply_doc_properties_filter();
                $post_count = isset($todo_query['post_count']) ? $todo_query['post_count'] : 0;
            }
        }

        // 优化：批量预加载
        global $todo_query;
        if (isset($todo_query) && isset($todo_query->posts)) {
            $this->batch_preload_post_fields($todo_query->posts);
        }

        $output = '';
        $index = 0;
        while (have_posts()) {
            the_post();
            $index++;

            // 同步更新 todo_post，确保 get_post_context 能正确获取循环内的文章上下文
            global $todo_post;
            $todo_post = $GLOBALS['todo_post'];

            // 构建富数据上下文，避免内部模板标签回退到不稳定的全局 post 状态
            // 使用 V2 编译器标准格式化确保 post_title→title 等别名正确设置
            $post_raw = $todo_post;
            $post_data = CMS_Template_Compiler_V2::format_post_item($post_raw);
            $render_data = CMS_Template_Compiler_V2::merge_loop_context([], $post_data, $index - 1, $index);
            $render_data['index'] = $index;

            // 关键修复：将原始 post 对象注入到 item 键，
            // 确保 legacy 处理器（parse_post_title 等）的 get_post_context() 
            // 能通过 $data['item'] 直接定位到当前文章，不依赖全局状态
            $render_data['item'] = $post_raw;

            // 解析内部内容
            $parsed_content = CMS_Template_Compiler_V2::render_string($content, $render_data);
            $output .= $parsed_content;
        }

        todo_reset_postdata();

        return $output;
    }

    /**
     * 按文档属性优先级排序文章：头条(headline) > 推荐(recommend) > 加推(featured)
     * doc_properties 是逗号分隔的属性ID，headline=1, recommend=2, featured=3
     */
    private function sort_posts_by_recommend(&$todo_query)
    {
        if (!is_array($todo_query) || empty($todo_query)) {
            return;
        }

        $posts = [];
        $meta = [];
        foreach ($todo_query as $key => $value) {
            if (is_int($key) && (is_object($value) || is_array($value))) {
                $doc_props = is_object($value) ? ($value->doc_properties ?? '') : ($value['doc_properties'] ?? '');
                $prop_ids = $this->parse_doc_property_ids_value($doc_props);
                $priority = 0;
                if (in_array(1, $prop_ids, true)) $priority = 3;       // headline (A)
                elseif (in_array(2, $prop_ids, true)) $priority = 2;  // recommend (B)
                elseif (in_array(3, $prop_ids, true)) $priority = 1;  // featured (C)
                $post_date = is_object($value) ? ($value->post_date ?? '') : ($value['post_date'] ?? '');
                $posts[] = ['key' => $key, 'value' => $value, 'priority' => $priority, 'date' => $post_date];
            } elseif (is_string($key) && !is_numeric($key)) {
                $meta[$key] = $value;
            }
        }

        // 按优先级降序、发布时间降序排列
        usort($posts, function($a, $b) {
            if ($a['priority'] !== $b['priority']) {
                return $b['priority'] - $a['priority'];
            }
            return strcmp($b['date'], $a['date']);
        });

        // 重建 todo_query
        $todo_query = [];
        foreach ($meta as $k => $v) {
            $todo_query[$k] = $v;
        }
        $idx = 0;
        foreach ($posts as $p) {
            $todo_query[$idx++] = $p['value'];
        }
        $todo_query['current_post'] = -1;
        $todo_query['post_count'] = $idx;
    }

    // ==================== 条件标签 ====================

    /**
     * 解析分类条件标签
     */
    public function parse_if_category($attrs, $content, $data)
    {
        if (todo_is_category()) {
            return $this->parse_conditional_content($content, $data);
        }
        return '';
    }

    /**
     * 解析标签条件标签
     */
    public function parse_if_tag($attrs, $content, $data)
    {
        if (todo_is_tag()) {
            return $this->parse_conditional_content($content, $data);
        }
        return '';
    }

    /**
     * 解析归档条件标签
     */
    public function parse_if_archive($attrs, $content, $data)
    {
        if (todo_is_archive()) {
            return $this->parse_conditional_content($content, $data);
        }
        return '';
    }

    /**
     * 解析搜索条件标签
     */
    public function parse_if_search($attrs, $content, $data)
    {
        if (todo_is_search()) {
            return $this->parse_conditional_content($content, $data);
        }
        return '';
    }

    /**
     * 解析404条件标签
     */
    public function parse_if_404($attrs, $content, $data)
    {
        if (todo_is_404()) {
            return $this->parse_conditional_content($content, $data);
        }
        return '';
    }

    /**
     * 解析用户登录条件标签
     */
    public function parse_if_user_logged_in($attrs, $content, $data)
    {
        if (is_user_logged_in()) {
            return $this->parse_conditional_content($content, $data);
        }
        return '';
    }

    /**
     * 解析是否开启允许任何人注册条件标签
     */
    public function parse_if_users_can_register($attrs, $content, $data)
    {
        $can = function_exists('get_option') ? (bool) get_option('users_can_register', false) : false;
        return $this->parse_conditional_content($content, $data, $can);
    }

    /**
     * 解析是否开启QQ登录条件标签
     */
    public function parse_if_qq_login_enabled($attrs, $content, $data)
    {
        $enabled = function_exists('get_option') ? ((string) get_option('qq_login_enabled', '0') === '1') : false;
        $app_id = function_exists('get_option') ? trim((string) get_option('qq_app_id', '')) : '';
        $valid = ($enabled && $app_id !== '');
        return $this->parse_conditional_content($content, $data, $valid);
    }

    /**
     * 解析是否开启手机号登录注册条件标签
     */
    public function parse_if_mobile_login_enabled($attrs, $content, $data)
    {
        $enabled = function_exists('get_option') ? ((string) get_option('mobile_login_enabled', '0') === '1') : false;
        return $this->parse_conditional_content($content, $data, $enabled);
    }




    /**
     * 解析特色图片条件标签
     */
    public function parse_if_has_post_thumbnail($attrs, $content, $data)
    {
        $ctx = $this->get_post_context($data);
        $u = $this->resolve_post_featured_image_url($ctx['obj']);
        if ($u !== '') {
            return $this->parse_conditional_content($content, $data);
        }
        return '';
    }

    public function parse_if_option($attrs, $content, $data)
    {
        if (is_array($attrs) && !isset($attrs['name']) && isset($attrs['option'])) {
            $attrs['name'] = $attrs['option'];
        }
        $name = isset($attrs['name']) ? (string) $attrs['name'] : '';
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        $val = '';
        if (function_exists('todo_get_option_ml')) {
            $val = todo_get_option_ml($name, function_exists('todo_get_option') ? todo_get_option($name, '') : '');
        } elseif (function_exists('todo_get_option')) {
            $val = todo_get_option($name, '');
        }
        if (!is_string($val)) {
            $val = is_scalar($val) ? (string) $val : '';
        }
        $s = trim($val);
        $is_true = ($s !== '' && $s !== '0' && strtolower($s) !== 'false');
        if ($is_true) {
            if (class_exists('CMS_Template_Compiler_V2') && strpos($content, '{{todo:') !== false) {
                try {
                    return CMS_Template_Compiler_V2::render_string($content, is_array($data) ? $data : []) . "\r\n";
                } catch (\Throwable $e) {
                }
            }
            if (class_exists('CMS_Template_Compiler_V2')) {
                return $this->parse_conditional_content($content, $data);
            }
            return $content;
        }
        return '';
    }

    /**
     * 解析条件内容（严格模式：只支持 {{todo:else}}）
     */
    private function parse_conditional_content($content, $data = [], $condition_result = true)
    {
        $out = '';
        $p_todo = strpos($content, '{{todo:else}}');
        $has_else = ($p_todo !== false);

        if ($has_else) {
            $else_pos = $p_todo;
            $else_tag_len = strlen('{{todo:else}}');

            if ($condition_result) {
                $out = substr($content, 0, $else_pos);
            } else {
                $out = substr($content, $else_pos + $else_tag_len);
            }
            $out = trim($out);
        } else {
            $out = $condition_result ? $content : '';
        }

        if ($out !== '' && strpos($out, '{{todo:') !== false && class_exists('CMS_Template_Compiler_V2')) {
            try {
                $out = CMS_Template_Compiler_V2::render_string($out, is_array($data) ? $data : []);
            } catch (\Throwable $e) {
            }
        }

        return $out;
    }


    // ==================== 自定义字段标签 ====================
    public function parse_has_custom_field($attrs, $content, $data)
    {
        global $post, $tododb;
        $ctx = $this->get_post_context($data);
        $post_id = $ctx['id'];
        if ($post_id <= 0) {
            return 'false';
        }

        $field_key = '';
        if (!isset($attrs['name']) && isset($attrs['key'])) {
            $attrs['name'] = $attrs['key'];
        }
        if (isset($attrs['name'])) {
            $field_key = (string) $attrs['name'];
        }
        $field_id = isset($attrs['id']) ? intval($attrs['id']) : 0;

        if ($field_key === '' && $field_id > 0) {
            $fields_manager = class_exists('Todo_Post_Fields_Manager') ? Todo_Post_Fields_Manager::get_instance() : null;
            $model_id = $fields_manager ? intval($fields_manager->get_post_model_id($post_id)) : 0;
            if ($model_id > 0 && isset($tododb)) {
                $field_key = (string) $tododb->get_var("SELECT field_key FROM {$tododb->prefix}model_fields WHERE field_id = ? AND model_id = ?", [$field_id, $model_id]);
            } elseif (isset($tododb)) {
                $field_key = (string) $tododb->get_var("SELECT field_key FROM {$tododb->prefix}model_fields WHERE field_id = ?", [$field_id]);
            }
        }
        if ($field_key === '') {
            return 'false';
        }

        if (!function_exists('todo_get_post_custom_field_translation')) {
            @require_once ABSPATH . 'core/functions/multilang-fields.php';
        }
        $val = todo_get_post_custom_field_translation($post_id, $field_key, null, array('status_allowed' => array('approved')));
        if ($val === null || $val === '' || $val === false || (is_array($val) && empty($val))) {
            if (!class_exists('Todo_Post_Fields_Manager')) {
                @require_once ABSPATH . 'core/classes/class-post-fields-manager.php';
            }
            if (class_exists('Todo_Post_Fields_Manager')) {
                $mgr = Todo_Post_Fields_Manager::get_instance();
                $all = $mgr->get_post_fields_values($post_id);
                if (is_array($all) && array_key_exists($field_key, $all)) {
                    $val = $all[$field_key];
                }
            }
        }
        if ($val === null || $val === '' || $val === false || (is_array($val) && empty($val))) {
            if (function_exists('todo_get_post_meta')) {
                $val = todo_get_post_meta($post_id, $field_key, true);
            }
        }
        if ($val === null || $val === '' || $val === false || (is_array($val) && empty($val))) {
            $fields_manager = class_exists('Todo_Post_Fields_Manager') ? Todo_Post_Fields_Manager::get_instance() : null;
            $model_id_for_table = $fields_manager ? intval($fields_manager->get_post_model_id($post_id)) : 0;
            if ($model_id_for_table > 0 && class_exists('Todo_Model_Manager')) {
                $model_key = Todo_Model_Manager::get_model_key_by_id($model_id_for_table);
                if ($model_key && isset($tododb) && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field_key)) {
                    $table = $tododb->prefix . 'model_fields_' . $model_key;
                    $columns = array();
                    if (function_exists('get_table_columns')) {
                        $columns = get_table_columns($table);
                    }
                    if (is_array($columns) && in_array($field_key, $columns, true)) {
                        $row = self::get_model_table_row($table, $post_id);
                        if (is_array($row) && array_key_exists($field_key, $row)) {
                            $val = maybe_unserialize($row[$field_key]);
                        }
                    }
                }
            }
        }

        if (is_string($val)) {
            $decoded = json_decode($val, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $val = $decoded;
            }
        }
        if (is_array($val)) {
            return empty($val) ? 'false' : 'true';
        }
        if (is_object($val)) {
            return (count(get_object_vars($val)) > 0) ? 'true' : 'false';
        }
        $s = is_scalar($val) ? (string) $val : '';
        return trim($s) !== '' ? 'true' : 'false';
    }

    private function normalize_image_value_to_url($val, $attrs = [])
    {
        if (empty($val)) {
            return '';
        }

        $items = [];
        if (is_array($val)) {
            if (isset($val['id']) || isset($val['url']) || isset($val['src'])) {
                $items[] = $val;
            } else {
                $first = reset($val);
                if (is_array($first) || is_numeric($first) || is_string($first)) {
                    $items = $val;
                }
            }
        } elseif (is_string($val)) {
            $decoded = json_decode($val, true);
            if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_numeric($decoded))) {
                if (is_array($decoded)) {
                    if (isset($decoded['id']) || isset($decoded['url']) || isset($decoded['src'])) {
                        $items[] = $decoded;
                    } else {
                        $items = $decoded;
                    }
                } else {
                    $items[] = ['id' => $decoded];
                }
            } elseif (is_numeric($val)) {
                $items[] = ['id' => $val];
            } else {
                $items[] = ['url' => $val];
            }
        } elseif (is_numeric($val)) {
            $items[] = ['id' => $val];
        }

        if (empty($items)) {
            return '';
        }

        $items = array_values($items);
        $idx = 0;
        if (is_array($attrs)) {
            if (isset($attrs['key']) && is_numeric($attrs['key'])) {
                $idx = (int) $attrs['key'];
            } elseif (isset($attrs['index']) && is_numeric($attrs['index'])) {
                $idx = (int) $attrs['index'];
            }
        }
        if ($idx < 0) {
            $idx = 0;
        }
        if (!isset($items[$idx])) {
            return '';
        }

        $first = $items[$idx];
        $id = 0;
        $url = '';
        if (is_array($first)) {
            if (isset($first['url']) && is_string($first['url']) && $first['url'] !== '') {
                $url = $first['url'];
            } elseif (isset($first['src']) && is_string($first['src']) && $first['src'] !== '') {
                $url = $first['src'];
            }
            if ($url === '' && isset($first['id']) && is_numeric($first['id'])) {
                $id = (int) $first['id'];
            }
        } elseif (is_string($first)) {
            $url = $first;
        } elseif (is_numeric($first)) {
            $id = (int) $first;
        }

        if ($url === '' && $id > 0) {
            $size = isset($attrs['size']) ? $attrs['size'] : 'full';
            if (function_exists('todo_get_attachment_image_src')) {
                $src = todo_get_attachment_image_src($id, $size);
                if (is_array($src) && !empty($src[0])) {
                    $url = (string) $src[0];
                }
            }
            if ($url === '' && function_exists('todo_get_attachment_url')) {
                $u = todo_get_attachment_url($id);
                if (is_string($u) && $u !== '') {
                    $url = $u;
                }
            }
        }

        if ($url === '') {
            return '';
        }

        if (function_exists('todo_media_url_to_runtime_absolute')) {
            $url = todo_media_url_to_runtime_absolute($url);
        } elseif (function_exists('todo_get_site_url')) {
            if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0 && strpos($url, '//') !== 0) {
                $url = todo_get_site_url(ltrim($url, '/'));
            }
        }

        return (string) $url;
    }

    public function parse_category_img($attrs, $content, $data)
    {
        $default = (is_array($attrs) && isset($attrs['default'])) ? (string) $attrs['default'] : '';
        $default = trim($default);
        if ($default !== '') {
            $base = function_exists('get_template_directory_uri') ? (string) get_template_directory_uri() : (function_exists('todo_get_template_var') ? (string) todo_get_template_var('theme_url', '') : '');
            if (stripos($default, 'theme:') === 0) {
                $path = ltrim(substr($default, 6), '/');
                if ($base !== '') {
                    $default = rtrim($base, '/') . '/' . $path;
                } else {
                    $default = '/' . $path;
                }
            } elseif ($base !== '' && (strpos($default, '/assets/') === 0 || strpos($default, 'assets/') === 0)) {
                $default = rtrim($base, '/') . '/' . ltrim($default, '/');
            }
        }
        $term_id = isset($attrs['id']) ? (int) $attrs['id'] : 0;
        if ($term_id <= 0 && is_array($data)) {
            if (isset($data['category_id'])) {
                $term_id = (int) $data['category_id'];
            } elseif (isset($data['term_id'])) {
                $term_id = (int) $data['term_id'];
            }
        }
        if ($term_id <= 0 && function_exists('todo_get_queried_object_id')) {
            $term_id = (int) todo_get_queried_object_id();
        }
        if ($term_id <= 0) {
            return $default;
        }

        $taxonomy = isset($attrs['taxonomy']) ? (string) $attrs['taxonomy'] : (function_exists('todo_is_tag') && todo_is_tag() ? 'post_tag' : 'category');

        $val = null;
        if (function_exists('todo_get_term_attributes_multilang')) {
            $map = todo_get_term_attributes_multilang($term_id, $taxonomy, null, array('status_allowed' => array('approved', 'pending')));
            if (is_array($map) && array_key_exists('category_image', $map)) {
                $val = $map['category_image'];
            }
        }
        if (($val === null || $val === '') && function_exists('get_term_meta')) {
            $val = get_term_meta($term_id, 'category_image', true);
        }

        $url = $this->normalize_image_value_to_url($val, is_array($attrs) ? $attrs : []);
        if ($url === '' && $default !== '') {
            return $default;
        }
        return $url;
    }

    // ==================== 术语属性标签（分类/标签自定义字段，多语言） ====================
    /**
     * 解析术语自定义属性标签
     * 用法：{{todo:term_attribute key="banner" taxonomy="category" id="123" format="html" default=""}}
     * - key/name: 属性键（必填）
     * - taxonomy: 分类法（category|post_tag），默认根据当前上下文推断 category/tag
     * - id: 术语ID，默认读取当前查询对象
     * - format: html|raw|default（默认转义html）
     * - default: 默认值
     */
    public function parse_term_attribute($attrs, $content, $data)
    {
        $key = isset($attrs['key']) ? (string) $attrs['key'] : (isset($attrs['name']) ? (string) $attrs['name'] : '');
        if ($key === '') {
            return isset($attrs['default']) ? (string) $attrs['default'] : '';
        }
        $taxonomy = isset($attrs['taxonomy']) ? (string) $attrs['taxonomy'] : (function_exists('todo_is_tag') && todo_is_tag() ? 'post_tag' : 'category');
        $term_id = isset($attrs['id']) ? (int) $attrs['id'] : 0;
        // 如果未显式提供id，尝试从上下文数据获取（分类/标签循环中）
        if ($term_id <= 0 && is_array($data)) {
            if (isset($data['category_id'])) {
                $term_id = (int) $data['category_id'];
                $taxonomy = 'category';
            } elseif (isset($data['tag_id'])) {
                $term_id = (int) $data['tag_id'];
                $taxonomy = 'post_tag';
            }
        }
        // 仍为空则读取当前查询对象
        if ($term_id <= 0 && function_exists('todo_get_queried_object_id')) {
            $term_id = (int) todo_get_queried_object_id();
        }
        if ($term_id <= 0) {
            return isset($attrs['default']) ? (string) $attrs['default'] : '';
        }

        $attr_map = function_exists('todo_get_term_attributes_multilang') ? (array) todo_get_term_attributes_multilang($term_id, $taxonomy) : [];
        $val = array_key_exists($key, $attr_map) ? $attr_map[$key] : (isset($attrs['default']) ? $attrs['default'] : '');

        // 处理 type="image"
        $type = isset($attrs['type']) ? strtolower($attrs['type']) : '';
        if ($type === 'image') {
            return $this->render_image_output($val, $attrs);
        }

        $format = isset($attrs['format']) ? (string) $attrs['format'] : 'html';
        switch (strtolower($format)) {
            case 'raw':
                if (is_string($val)) {
                    return $val;
                }
                if (is_scalar($val)) {
                    return (string) $val;
                }
                return json_encode($val, JSON_UNESCAPED_UNICODE);
            case 'html':
            default:
                if (is_string($val)) {
                    return function_exists('todo_esc_html') ? todo_esc_html($val) : $val;
                }
                if (is_scalar($val)) {
                    return function_exists('todo_esc_html') ? todo_esc_html((string) $val) : (string) $val;
                }
                $json = json_encode($val, JSON_UNESCAPED_UNICODE);
                return function_exists('todo_esc_html') ? todo_esc_html($json) : $json;
        }
    }

    // ==================== 分页标签 ====================

    /**
     * 解析分页标签
     */
    public function parse_pagination($attrs, $content, $data)
    {
        global $todo_query;

        // 检查 $todo_query 是否已初始化
        if (!isset($todo_query) || !is_object($todo_query) || !property_exists($todo_query, 'max_num_pages')) {
            return '';
        }

        if ($todo_query->max_num_pages <= 1) {
            return '';
        }

        $paged = get_query_var('paged') ? get_query_var('paged') : 1;
        $max_pages = $todo_query->max_num_pages;

        $pagination_html = '<div class="pagination">';

        // 上一页
        if ($paged > 1) {
            $prev_url = get_pagenum_link($paged - 1);
            $pagination_html .= '<a href="' . esc_url($prev_url) . '" class="prev-page">上一页</a>';
        }

        // 页码
        for ($i = 1; $i <= $max_pages; $i++) {
            if ($i == $paged) {
                $pagination_html .= '<span class="current-page">' . $i . '</span>';
            } else {
                $page_url = get_pagenum_link($i);
                $pagination_html .= '<a href="' . esc_url($page_url) . '" class="page-number">' . $i . '</a>';
            }
        }

        // 下一页
        if ($paged < $max_pages) {
            $next_url = get_pagenum_link($paged + 1);
            $pagination_html .= '<a href="' . esc_url($next_url) . '" class="next-page">下一页</a>';
        }

        $pagination_html .= '</div>';

        return $pagination_html;
    }

    /**
     * 解析分页上一页URL
     */
    public function parse_pagination_prev_url($attrs, $content, $data)
    {
        if (is_array($data) && array_key_exists('pagination_prev_url', $data)) {
            $v = $data['pagination_prev_url'];
            return is_string($v) ? $v : (is_scalar($v) ? (string) $v : '');
        }

        $base = function_exists('todo_home_url') ? todo_home_url('/') : (function_exists('home_url') ? home_url('/') : '/');
        if (is_array($data) && array_key_exists('pagination_prev_page', $data)) {
            $p = intval($data['pagination_prev_page']);
            if ($p > 0 && function_exists('todo_get_paginated_url')) return todo_get_paginated_url($p, $base);
            if ($p > 0 && function_exists('get_pagenum_link')) return get_pagenum_link($p);
            return '';
        }

        $paged = 0;
        if (isset($_GET['paged']) && is_numeric($_GET['paged'])) $paged = intval($_GET['paged']);
        if ($paged <= 0 && isset($_GET['page']) && is_numeric($_GET['page'])) $paged = intval($_GET['page']);
        if ($paged <= 0 && isset($_SERVER['REQUEST_URI']) && preg_match('#/page/([0-9]+)/?#', (string) $_SERVER['REQUEST_URI'], $m)) {
            $paged = intval($m[1]);
        }
        if ($paged <= 0 && function_exists('get_query_var')) $paged = get_query_var('paged') ? intval(get_query_var('paged')) : 0;
        if ($paged <= 0) $paged = 1;

        if ($paged > 1) {
            $p = $paged - 1;
            if (function_exists('todo_get_paginated_url')) return todo_get_paginated_url($p, $base);
            if (function_exists('get_pagenum_link')) return get_pagenum_link($p);
        }
        return '';
    }

    /**
     * 解析分页下一页URL
     */
    public function parse_pagination_next_url($attrs, $content, $data)
    {
        if (is_array($data) && array_key_exists('pagination_next_url', $data)) {
            $v = $data['pagination_next_url'];
            return is_string($v) ? $v : (is_scalar($v) ? (string) $v : '');
        }

        $base = function_exists('todo_home_url') ? todo_home_url('/') : (function_exists('home_url') ? home_url('/') : '/');
        if (is_array($data) && array_key_exists('pagination_next_page', $data)) {
            $p = intval($data['pagination_next_page']);
            if ($p > 0 && function_exists('todo_get_paginated_url')) return todo_get_paginated_url($p, $base);
            if ($p > 0 && function_exists('get_pagenum_link')) return get_pagenum_link($p);
            return '';
        }

        global $todo_query;
        if (!isset($todo_query) || !is_object($todo_query) || !property_exists($todo_query, 'max_num_pages')) {
            return '';
        }

        $paged = 0;
        if (isset($_GET['paged']) && is_numeric($_GET['paged'])) $paged = intval($_GET['paged']);
        if ($paged <= 0 && isset($_GET['page']) && is_numeric($_GET['page'])) $paged = intval($_GET['page']);
        if ($paged <= 0 && isset($_SERVER['REQUEST_URI']) && preg_match('#/page/([0-9]+)/?#', (string) $_SERVER['REQUEST_URI'], $m)) {
            $paged = intval($m[1]);
        }
        if ($paged <= 0 && function_exists('get_query_var')) $paged = get_query_var('paged') ? intval(get_query_var('paged')) : 0;
        if ($paged <= 0) $paged = 1;

        if ($paged < intval($todo_query->max_num_pages)) {
            $p = $paged + 1;
            if (function_exists('todo_get_paginated_url')) return todo_get_paginated_url($p, $base);
            if (function_exists('get_pagenum_link')) return get_pagenum_link($p);
        }
        return '';
    }

    /**
     * 解析分页第一页URL
     */
    public function parse_pagination_first_url($attrs, $content, $data)
    {
        if (is_array($data) && array_key_exists('pagination_first_url', $data)) {
            $v = $data['pagination_first_url'];
            return is_string($v) ? $v : (is_scalar($v) ? (string) $v : '');
        }

        $base = function_exists('todo_home_url') ? todo_home_url('/') : (function_exists('home_url') ? home_url('/') : '/');
        if (function_exists('todo_get_paginated_url')) return todo_get_paginated_url(1, $base);
        if (function_exists('get_pagenum_link')) {
            $result = get_pagenum_link(1);
            return is_string($result) ? $result : (is_array($result) ? json_encode($result) : (string) $result);
        }
        return rtrim((string) $base, '/');
    }

    /**
     * 解析分页最后一页URL
     */
    public function parse_pagination_last_url($attrs, $content, $data)
    {
        if (is_array($data) && array_key_exists('pagination_last_url', $data)) {
            $v = $data['pagination_last_url'];
            return is_string($v) ? $v : (is_scalar($v) ? (string) $v : '');
        }

        $base = function_exists('todo_home_url') ? todo_home_url('/') : (function_exists('home_url') ? home_url('/') : '/');
        if (is_array($data) && array_key_exists('pagination_total_pages', $data)) {
            $tp = intval($data['pagination_total_pages']);
            if ($tp > 0 && function_exists('todo_get_paginated_url')) return todo_get_paginated_url($tp, $base);
            if ($tp > 0 && function_exists('get_pagenum_link')) return get_pagenum_link($tp);
        }

        global $todo_query;
        if (!isset($todo_query) || !is_object($todo_query) || !property_exists($todo_query, 'max_num_pages')) {
            return '';
        }
        $tp = intval($todo_query->max_num_pages);
        if ($tp <= 0) return '';
        if (function_exists('todo_get_paginated_url')) return todo_get_paginated_url($tp, $base);
        if (function_exists('get_pagenum_link')) return get_pagenum_link($tp);
        return '';
    }

    /**
     * 解析分页总页数
     */
    public function parse_pagination_total_pages($attrs, $content, $data)
    {
        if (is_array($data) && array_key_exists('pagination_total_pages', $data)) {
            return intval($data['pagination_total_pages']);
        }

        global $todo_query;
        if (!isset($todo_query) || !is_object($todo_query) || !property_exists($todo_query, 'max_num_pages')) {
            return 0;
        }
        return intval($todo_query->max_num_pages);
    }

    // ==================== 搜索标签 ====================

    /**
     * 解析搜索查询标签
     */
    public function parse_search_query($attrs, $content, $data)
    {
        return todo_get_search_query();
    }

    /**
     * 解析搜索结果数量标签
     */
    public function parse_found_posts($attrs, $content, $data)
    {
        global $todo_query;

        if (!isset($todo_query) || !is_object($todo_query) || !property_exists($todo_query, 'found_posts')) {
            return 0;
        }

        return $todo_query->found_posts;
    }

    /**
     * 解析搜索表单标签
     */
    public function parse_search_form($attrs, $content, $data)
    {
        $base = function_exists('todo_home_url') ? todo_home_url('/') : (function_exists('home_url') ? home_url('/') : '/');
        $locale = function_exists('current_locale') ? current_locale() : '';
        $action = (function_exists('get_language_url') && !empty($locale)) ? get_language_url($locale, $base) : $base;
        $form_html = '<form class="search-form" method="get" action="' . esc_url($action) . '">';
        $form_html .= '<input type="search" name="s" value="' . todo_esc_attr(todo_get_search_query()) . '" placeholder="搜索...">';
        $form_html .= '<button type="submit">搜索</button>';
        $form_html .= '</form>';

        return $form_html;
    }

    // ==================== 用户标签 ====================

    /**
     * 解析当前用户名标签
     */
    public function parse_current_user_name($attrs, $content, $data)
    {
        $current_user = todo_get_current_user();
        if (is_array($current_user)) {
            return isset($current_user['display_name']) ? $current_user['display_name'] : '';
        } elseif (is_object($current_user)) {
            return isset($current_user->display_name) ? $current_user->display_name : '';
        }
        return '';
    }

    /**
     * 解析登录链接标签
     */
    public function parse_login_url($attrs, $content, $data)
    {
        return get_login_url();
    }

    /**
     * 解析QQ登录授权跳转URL标签
     */
    public function parse_qq_login_url($attrs, $content, $data)
    {
        return function_exists('todo_get_site_url') ? todo_get_site_url('/api/user/qq-login.php') : (function_exists('todo_home_url') ? todo_home_url('/api/user/qq-login.php') : '/api/user/qq-login.php');
    }

    /**
     * 账号登录API地址标签
     */
    public function parse_login_api_url($attrs, $content, $data)
    {
        return function_exists('todo_get_site_url') ? todo_get_site_url('/api/user/login-handler.php') : (function_exists('todo_home_url') ? todo_home_url('/api/user/login-handler.php') : '/api/user/login-handler.php');
    }

    /**
     * 解析二维码URL标签（公共函数，用于微信登录等场景返回动态二维码接口）
     */
    public function parse_qr_code_url($attrs, $content, $data)
    {
        return function_exists('todo_get_site_url') ? todo_get_site_url('/api/user/login-qrcode.php') : (function_exists('todo_home_url') ? todo_home_url('/api/user/login-qrcode.php') : '/api/user/login-qrcode.php');
    }

    /**
     * 手机登录API地址标签
     */
    public function parse_mobile_login_url($attrs, $content, $data)
    {
        return function_exists('todo_get_site_url') ? todo_get_site_url('/api/user/mobile-login-handler.php') : (function_exists('todo_home_url') ? todo_home_url('/api/user/mobile-login-handler.php') : '/api/user/mobile-login-handler.php');
    }

    /**
     * 充值API地址标签
     */
    public function parse_recharge_api_url($attrs, $content, $data)
    {
        return function_exists('todo_get_site_url') ? todo_get_site_url('/api/user/recharge-handler.php') : (function_exists('todo_home_url') ? todo_home_url('/api/user/recharge-handler.php') : '/api/user/recharge-handler.php');
    }

    /**
     * 找回密码API地址标签
     */
    public function parse_forgot_password_api_url($attrs, $content, $data)
    {
        return function_exists('todo_get_site_url') ? todo_get_site_url('/api/user/forgot-password-handler.php') : (function_exists('todo_home_url') ? todo_home_url('/api/user/forgot-password-handler.php') : '/api/user/forgot-password-handler.php');
    }

    /**
     * 解析退出链接标签
     */
    public function parse_logout_url($attrs, $content, $data)
    {
        $base = function_exists('todo_get_site_url') ? todo_get_site_url('/api/user/logout-handler.php') : (function_exists('todo_home_url') ? todo_home_url('/api/user/logout-handler.php') : '/api/user/logout-handler.php');
        // 获取重定向URL，默认为站点首页（语言感知）
        $default_redirect = function_exists('todo_home_url') ? todo_home_url() : (function_exists('home_url') ? home_url() : '/');
        $redirect = isset($attrs['redirect']) ? $attrs['redirect'] : $default_redirect;

        // 当前语言处理重定向地址
        $locale = function_exists('current_locale') ? current_locale() : '';
        if (function_exists('get_language_url') && !empty($locale)) {
            $redirect = get_language_url($locale, $redirect);
        }

        // 拼接重定向参数
        $logout_url = $base;
        if (!empty($redirect)) {
            $sep = (strpos($logout_url, '?') !== false) ? '&' : '?';
            $logout_url .= $sep . 'redirect_to=' . urlencode(rtrim($redirect, '/'));
        }
        return $logout_url;
    }

    /**
     * 解析收藏数量标签 favorites_count
     */
    public function parse_favorites_count($attrs = [], $content = '', $data = [])
    {
        if (isset($data['favorites_count'])) {
            return (string) $data['favorites_count'];
        }
        return function_exists('todo_get_favorites_count') ? (string) todo_get_favorites_count() : '0';
    }

    /**
     * 解析用户收藏数量标签 user_favorites_count
     */
    public function parse_user_favorites_count($attrs = [], $content = '', $data = [])
    {
        return $this->parse_favorites_count($attrs, $content, $data);
    }

    /**
     * 解析商品收藏数量标签 favorite_products_count
     */
    public function parse_favorite_products_count($attrs = [], $content = '', $data = [])
    {
        if (isset($data['favorite_products_count'])) {
            return (string) $data['favorite_products_count'];
        }
        return function_exists('todo_get_favorites_count') ? (string) todo_get_favorites_count(null, 'product') : '0';
    }

    /**
     * 解析文章收藏数量标签 favorite_articles_count
     */
    public function parse_favorite_articles_count($attrs = [], $content = '', $data = [])
    {
        if (isset($data['favorite_articles_count'])) {
            return (string) $data['favorite_articles_count'];
        }
        return function_exists('todo_get_favorites_count') ? (string) todo_get_favorites_count(null, 'post') : '0';
    }

    /**
     * 解析购物车数量标签 cart_count
     */
    public function parse_cart_count($attrs = [], $content = '', $data = [])
    {
        if (isset($data['cart_count'])) {
            return (string) $data['cart_count'];
        }
        return function_exists('todo_get_cart_count') ? (string) todo_get_cart_count() : '0';
    }

    /**
     * 解析购物车数量标签 user_cart_count
     */
    public function parse_user_cart_count($attrs = [], $content = '', $data = [])
    {
        return $this->parse_cart_count($attrs, $content, $data);
    }

    /**
     * 解析注册链接标签
     */
    public function parse_register_url($attrs, $content, $data)
    {
        $url = function_exists('get_register_url') ? get_register_url() : (function_exists('todo_get_user_center_url') ? todo_get_user_center_url('register') : (function_exists('todo_home_url') ? todo_home_url('/users/register') : home_url('/users/register')));
        $locale = function_exists('current_locale') ? current_locale() : '';
        if (function_exists('get_language_url') && !empty($locale)) {
            $url = get_language_url($locale, $url);
        }
        return $url;
    }

    /**
     * 解析忘记密码URL标签
     */
    public function parse_forgot_password_url($attrs, $content, $data)
    {
        if (function_exists('todo_get_user_page_url')) {
            return todo_get_user_page_url('forgot_password');
        }
        return function_exists('todo_get_site_url') ? todo_get_site_url('/?user=forgot_password') : '/?user=forgot_password';
    }

    /**
     * 解析用户中心链接标签
     */
    public function parse_user_center_url($attrs, $content, $data)
    {
        $url = function_exists('todo_get_user_center_url') ? todo_get_user_center_url() : (function_exists('todo_home_url') ? todo_home_url('/users/') : home_url('/users/'));
        $locale = function_exists('current_locale') ? current_locale() : '';
        if (function_exists('get_language_url') && !empty($locale)) {
            $url = get_language_url($locale, $url);
        }
        return $url;
    }

    /**
     * 解析用户显示名称标签
     */
    public function parse_user_display_name($attrs, $content, $data)
    {
        $current_user = todo_get_current_user();
        if (is_array($current_user)) {
            return isset($current_user['display_name']) ? $current_user['display_name'] : '';
        } elseif (is_object($current_user)) {
            return isset($current_user->display_name) ? $current_user->display_name : '';
        }
        return '';
    }

    public function parse_user_address_count($attrs, $content, $data)
    {
        if (class_exists('CMS_User_Center_Template_Tags')) {
            $handler = CMS_User_Center_Template_Tags::get_instance();
            if (is_callable([$handler, 'parse_user_address_count'])) {
                return call_user_func([$handler, 'parse_user_address_count'], $attrs, $content, $data);
            }
        }

        $uid = 0;
        if (function_exists('todo_get_current_user')) {
            $u = todo_get_current_user();
            if (is_array($u)) {
                if (isset($u['ID'])) {
                    $uid = (int) $u['ID'];
                } elseif (isset($u['id'])) {
                    $uid = (int) $u['id'];
                }
            } elseif (is_object($u)) {
                if (isset($u->ID)) {
                    $uid = (int) $u->ID;
                } elseif (isset($u->id)) {
                    $uid = (int) $u->id;
                }
            }
        }
        if ($uid <= 0 && function_exists('get_current_user_id')) {
            $uid = (int) get_current_user_id();
        }
        if ($uid <= 0) {
            return '0';
        }

        if (function_exists('todo_get_user_address_count')) {
            return (string) todo_get_user_address_count($uid);
        }

        global $tododb;
        if (!$tododb) {
            return '0';
        }
        try {
            return (string) ((int) ($tododb->get_var("SELECT COUNT(*) FROM {$tododb->prefix}member_addresses WHERE user_id = ?", [$uid]) ?: 0));
        } catch (Throwable $e) {
            return '0';
        }
    }

    public function parse_user_addresses($attrs, $content, $data)
    {
        if (class_exists('CMS_User_Center_Template_Tags')) {
            $handler = CMS_User_Center_Template_Tags::get_instance();
            if (is_callable([$handler, 'parse_user_addresses'])) {
                return call_user_func([$handler, 'parse_user_addresses'], $attrs, $content, $data);
            }
        }

        $uid = 0;
        if (function_exists('todo_get_current_user')) {
            $u = todo_get_current_user();
            if (is_array($u)) {
                if (isset($u['ID'])) {
                    $uid = (int) $u['ID'];
                } elseif (isset($u['id'])) {
                    $uid = (int) $u['id'];
                }
            } elseif (is_object($u)) {
                if (isset($u->ID)) {
                    $uid = (int) $u->ID;
                } elseif (isset($u->id)) {
                    $uid = (int) $u->id;
                }
            }
        }
        if ($uid <= 0 && function_exists('get_current_user_id')) {
            $uid = (int) get_current_user_id();
        }
        if ($uid <= 0) {
            return '';
        }

        $limit = isset($attrs['limit']) ? (int) $attrs['limit'] : 0;
        $page = isset($attrs['page']) ? (int) $attrs['page'] : 1;
        if ($page <= 0) {
            $page = 1;
        }

        $addresses = [];
        if (function_exists('todo_get_user_addresses')) {
            $addresses = todo_get_user_addresses($uid, $limit, $page);
        } elseif (function_exists('get_user_addresses')) {
            $addresses = get_user_addresses($uid);
        }
        if (empty($addresses)) {
            return '';
        }

        $output = '';
        if (class_exists('CMS_Template_Compiler_V2')) {
            // parser instance
            $index = 0;
            foreach ($addresses as $address) {
                $index++;
                $loop_data = array_merge($data, (array) $address);
                $loop_data['address'] = (array) $address;
                $loop_data['index'] = $index;
                $output .= CMS_Template_Compiler_V2::render_string($content, $loop_data);
            }
        }
        return $output;
    }

    /**
     * 解析用户未登录条件标签
     */
    public function parse_if_user_not_logged_in($attrs, $content, $data)
    {
        if (!is_user_logged_in()) {
            return $this->parse_conditional_content($content, $data);
        }
        return '';
    }

    // ==================== 调试标签 ====================

    /**
     * 解析调试条件标签
     */
    public function parse_if_debug($attrs, $content, $data)
    {
        if (defined('TODO_DEBUG') && TODO_DEBUG) {
            return $this->parse_conditional_content($content, $data);
        }
        return '';
    }

    /**
     * 解析当前模板标签
     */
    public function parse_current_template($attrs, $content, $data)
    {
        global $template;
        return basename($template);
    }

    /**
     * 解析查询次数标签
     */
    public function parse_query_count($attrs, $content, $data)
    {
        $queries = 0;
        if (isset($GLOBALS['tododb']) && $GLOBALS['tododb'] && method_exists($GLOBALS['tododb'], 'query_count')) {
            try {
                $queries = $GLOBALS['tododb']->query_count();
            } catch (Exception $e) {
                $queries = 0;
            }
        }
        return $queries;
    }

    /**
     * 解析变量转储标签
     */
    public function parse_dump($attrs, $content, $data)
    {
        if (!defined('TODO_DEBUG') || !TODO_DEBUG) {
            return '';
        }

        if (isset($attrs['var'])) {
            $var_name = $attrs['var'];
            $val = null;
            if (isset($data[$var_name])) {
                $val = $data[$var_name];
            } else {
                $global_options = function_exists('get_option') ? get_option('global_vars', []) : [];
                if (is_array($global_options) && isset($global_options[$var_name])) {
                    $val = $global_options[$var_name];
                } elseif (isset($GLOBALS[$var_name])) {
                    $val = $GLOBALS[$var_name];
                }
            }
            if ($val === null && substr($var_name, -7) === '_images') {
                $raw_field_key = substr($var_name, 0, -7);
                global $post, $current_post;
                $cur_p = $current_post ?: ($post ?: (function_exists('get_current_post') ? get_current_post() : null));
                if ($cur_p && isset($cur_p->ID)) {
                    $pid = intval($cur_p->ID);
                    global $tododb;
                    $raw_val = null;
                    if (isset($tododb)) {
                        $raw_val = $tododb->get_var($tododb->prepare("SELECT field_value FROM " . TABLE_PREFIX . "post_custom_fields WHERE post_id = %d AND (field_key = %s OR field_key = %s) LIMIT 1", $pid, $raw_field_key, 'model_field_' . $raw_field_key));
                    }
                    if (empty($raw_val) && function_exists('get_post_meta')) {
                        $raw_val = get_post_meta($pid, $raw_field_key, true);
                    }
                    if (!empty($raw_val)) {
                        $val = self::resolve_category_images_array($raw_val);
                    }
                }
            }

            if (isset($attrs['target']) && $attrs['target'] === 'console') {
                $label = 'dump ' . $var_name . ':';
                return '<script>console.log(' . json_encode($label, JSON_UNESCAPED_UNICODE) . ', ' . json_encode($val, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . ');</script>';
            }

            return '<pre>' . todo_esc_html(print_r($val, true)) . '</pre>';
        }

        return '';
    }

    /**
     * 解析文章标签条件标签
     */
    public function parse_if_post_tags($attrs, $content, $data)
    {
        global $post;

        if (!$post) {
            return '';
        }

        $tags = todo_get_the_tags($post->ID);

        if (!empty($tags)) {
            // 解析内部内容
            // parser instance
            return CMS_Template_Compiler_V2::render_string($content, $data);
        }

        return '';
    }

    /**
     * 解析标签名称
     */
    public function parse_tag_name($attrs, $content, $data)
    {
        $name = '';
        if (isset($data['tag_name']) && (string)$data['tag_name'] !== '') {
            $name = (string) $data['tag_name'];
        } elseif (isset($data['name']) && (string)$data['name'] !== '') {
            $name = (string) $data['name'];
        } elseif (isset($data['tag_title']) && (string)$data['tag_title'] !== '') {
            $name = (string) $data['tag_title'];
        } elseif (isset($data['tag_id'])) {
            try {
                global $tododb;
                $row = $tododb->get_row("SELECT tag_title FROM {$tododb->prefix}tags WHERE tag_id = ? LIMIT 1", [intval($data['tag_id'])]);
                if ($row && isset($row->tag_title)) {
                    $name = (string) $row->tag_title;
                }
            } catch (Exception $e) {
            }
        }
        if ($name !== '') {
            if (function_exists('todo_parse_inline_translations')) {
                $name = todo_parse_inline_translations($name);
            }
            if (function_exists('todo_translate')) {
                $name = todo_translate($name);
            }
            return esc_html($name);
        }
        return '';
    }

    /**
     * 解析标签链接
     */
    public function parse_tag_url($attrs, $content, $data)
    {
        if (isset($data['tag_url'])) {
            return esc_url($data['tag_url']);
        }
        if (isset($data['tag_id'])) {
            $tid = intval($data['tag_id']);
            if (function_exists('todo_get_tag_link')) {
                return esc_url(todo_get_tag_link($tid));
            }
        }
        return '';
    }

    // ==================== 热搜标签 ====================

    /**
     * 获取热搜关键词数据（编译器级循环）
     * 用法: {{todo:loop hot_searches}}...{{/todo:loop}}
     * 循环内可用: {{keyword}}, {{search_count}}, {{is_hot}}, {{search_url}}
     */
    public function parse_hot_searches($attrs, $content, $data)
    {
        $count = isset($attrs['count']) ? (int)$attrs['count'] : 10;
        $searches = function_exists('todo_get_hot_searches') ? todo_get_hot_searches($count) : [];

        if (empty($searches)) return [];

        // 为每项附加 search_url
        $result = [];
        foreach ($searches as $item) {
            $item = is_object($item) ? (array)$item : (array)$item;
            if (!isset($item['search_url'])) {
                $base = function_exists('todo_home_url') ? todo_home_url('/') : (function_exists('home_url') ? home_url('/') : '/');
                // 多语言路径前缀支持
                $langPrefix = '';
                if (class_exists('LanguageRegistry') && class_exists('LocaleContext')) {
                    $locale = LocaleContext::get_instance()->get_locale();
                    $langInfo = LanguageRegistry::get_instance()->language_by_locale($locale);
                    if ($langInfo && !empty($langInfo['path_prefix'])) {
                        $langPrefix = '/' . $langInfo['path_prefix'];
                    }
                }
                $item['search_url'] = rtrim($base, '/') . $langPrefix . '/search?q=' . rawurlencode($item['keyword']);
            }
            $result[] = $item;
        }
        return $result;
    }

    public function parse_show_ads($attrs, $content, $data)
    {
        $limit = 0;
        $id = 0;
        if (is_array($attrs)) {
            $limit = isset($attrs['limit']) ? intval($attrs['limit']) : 0;
            $id = isset($attrs['id']) ? intval($attrs['id']) : 0;
        }
        if ($limit < 0) {
            $limit = 0;
        }

        if ($id <= 0 && is_array($this->last_group_ads_ads) && !empty($this->last_group_ads_ads)) {
            $group_ads = $this->last_group_ads_ads;
            $this->last_group_ads_ads = null;
            $items = [];
            foreach ($group_ads as $gad) {
                $ad_id = is_array($gad) && isset($gad['id']) ? intval($gad['id']) : 0;
                if ($ad_id <= 0) {
                    continue;
                }
                $sub_items = $this->parse_show_ads(['id' => $ad_id], '', is_array($data) ? $data : []);
                if (!is_array($sub_items) || empty($sub_items)) {
                    continue;
                }
                foreach ($sub_items as $row) {
                    if ($limit > 0 && count($items) >= $limit) {
                        break 2;
                    }
                    $items[] = $row;
                }
            }
            $count = count($items);
            for ($i = 0; $i < $count; $i++) {
                $items[$i]['index'] = $i;
            }
            return $items;
        }

        $ad = null;
        if ($id > 0) {
            $ad = $this->parse_single_ads(['id' => $id], '', is_array($data) ? $data : []);
        } elseif (is_array($this->last_single_ads) && !empty($this->last_single_ads)) {
            $ad = $this->last_single_ads;
        } else {
            $ad = $this->parse_single_ads([], '', is_array($data) ? $data : []);
        }

        if (!is_array($ad) || empty($ad)) {
            return [];
        }

        $items = [];
        $normalize_url = function ($url) {
            $u = trim((string) $url);
            $u = trim($u, " \t\n\r\0\x0B`");
            if ($u !== '' && function_exists('todo_media_url_to_runtime_absolute')) {
                $u = todo_media_url_to_runtime_absolute($u);
            }
            return $u;
        };
        $get_ext = function ($url) use ($normalize_url) {
            $u = $normalize_url($url);
            $ext = '';
            if (preg_match('/\.([a-z0-9]+)(?:$|[?#])/i', $u, $m)) {
                $ext = strtolower((string) $m[1]);
            } else {
                $path = parse_url($u, PHP_URL_PATH);
                if (is_string($path)) {
                    $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
                }
            }
            return $ext;
        };
        $push = function ($row) use (&$items, $limit) {
            if ($limit > 0 && count($items) >= $limit) {
                return;
            }
            if (is_array($row)) {
                foreach (['title', 'content', 'description'] as $tk) {
                    if (isset($row[$tk]) && is_string($row[$tk]) && $row[$tk] !== '') {
                        if (function_exists('todo_parse_inline_translations')) {
                            $row[$tk] = todo_parse_inline_translations($row[$tk]);
                        }
                        if (function_exists('todo_translate')) {
                            $row[$tk] = todo_translate($row[$tk]);
                        }
                    }
                }
            }
            $items[] = $row;
        };

        if (isset($ad['codes']) && is_array($ad['codes'])) {
            foreach ($ad['codes'] as $code) {
                if ($limit > 0 && count($items) >= $limit) {
                    break;
                }
                $html = is_string($code) ? $code : '';
                $html = trim($html);
                if ($html === '') {
                    continue;
                }
                $row = [
                    'type' => 'code',
                    'html' => $html,
                ];
                $row['index'] = count($items);
                $push($row);
            }
        } elseif (isset($ad['groups']) && is_array($ad['groups'])) {
            $groups = [];
            foreach ($ad['groups'] as $g) {
                if (!is_array($g)) {
                    continue;
                }
                $media_items = [];
                if (isset($g['images']) && is_array($g['images'])) {
                    $media_items = $g['images'];
                } elseif (isset($g['medias']) && is_array($g['medias'])) {
                    $media_items = $g['medias'];
                }
                if (empty($media_items)) {
                    continue;
                }
                if (function_exists('todo_filter_featured_images_by_lang')) {
                    $media_items = todo_filter_featured_images_by_lang($media_items);
                }
                if (empty($media_items)) {
                    continue;
                }
                $groups[] = [
                    'title' => isset($g['title']) ? (string) $g['title'] : '',
                    'link' => isset($g['link']) ? (string) $g['link'] : '',
                    'target' => isset($g['target']) ? (string) $g['target'] : '',
                    'white_text' => isset($g['white_text']) ? intval($g['white_text']) : (isset($g['is_white_text']) ? intval($g['is_white_text']) : 0),
                    'content' => isset($g['content']) ? (string) $g['content'] : '',
                    'media_items' => array_values($media_items),
                ];
            }

            $max_len = 0;
            foreach ($groups as $gi) {
                $c = isset($gi['media_items']) && is_array($gi['media_items']) ? count($gi['media_items']) : 0;
                if ($c > $max_len) {
                    $max_len = $c;
                }
            }

            for ($i = 0; $i < $max_len; $i++) {
                foreach ($groups as $g) {
                    if ($limit > 0 && count($items) >= $limit) {
                        break 2;
                    }
                    $media = isset($g['media_items'][$i]) ? $g['media_items'][$i] : null;
                    if (!is_array($media)) {
                        continue;
                    }
                    $url = isset($media['url']) ? (string) $media['url'] : (isset($media['image_url']) ? (string) $media['image_url'] : '');
                    $url = $normalize_url($url);
                    if ($url === '') {
                        continue;
                    }
                    $ext = $get_ext($url);
                    $title = isset($g['title']) ? (string) $g['title'] : '';
                    $row = [
                        'type' => 'media',
                        'title' => $title !== '' ? $title : (isset($media['title']) ? (string) $media['title'] : ''),
                        'content' => isset($g['content']) ? (string) $g['content'] : '',
                        'link' => isset($g['link']) ? (string) $g['link'] : '',
                        'target' => (isset($g['target']) && (string) $g['target'] !== '') ? (string) $g['target'] : '_self',
                        'white_text' => isset($g['white_text']) ? intval($g['white_text']) : 0,
                        'image' => $url,
                        'media_ext' => $ext,
                    ];
                    $row['index'] = count($items);
                    $push($row);
                }
            }
        } elseif (isset($ad['images']) && is_array($ad['images'])) {
            foreach ($ad['images'] as $img) {
                if (!is_array($img)) {
                    continue;
                }
                $url = isset($img['url']) ? (string) $img['url'] : (isset($img['image_url']) ? (string) $img['image_url'] : '');
                $url = $normalize_url($url);
                if ($url === '') {
                    continue;
                }
                $ext = $get_ext($url);
                $row = [
                    'type' => 'media',
                    'title' => isset($img['title']) ? (string) $img['title'] : (isset($ad['title']) ? (string) $ad['title'] : ''),
                    'content' => isset($ad['content']) ? (string) $ad['content'] : '',
                    'link' => isset($ad['link']) ? (string) $ad['link'] : '',
                    'target' => isset($ad['target']) && (string) $ad['target'] !== '' ? (string) $ad['target'] : '_self',
                    'white_text' => isset($ad['white_text']) ? intval($ad['white_text']) : 0,
                    'image' => $url,
                    'media_ext' => $ext,
                ];
                $row['index'] = count($items);
                $push($row);
                if ($limit > 0 && count($items) >= $limit) {
                    break;
                }
            }
        } elseif (isset($ad['image_url']) && (string) $ad['image_url'] !== '') {
            $img_url = $normalize_url((string) $ad['image_url']);
            $ext = $get_ext($img_url);
            $row = [
                'type' => 'media',
                'title' => isset($ad['title']) ? (string) $ad['title'] : '',
                'content' => isset($ad['content']) ? (string) $ad['content'] : '',
                'link' => isset($ad['link']) ? (string) $ad['link'] : '',
                'target' => isset($ad['target']) && (string) $ad['target'] !== '' ? (string) $ad['target'] : '_self',
                'white_text' => isset($ad['white_text']) ? intval($ad['white_text']) : 0,
                'image' => $img_url,
                'media_ext' => $ext,
            ];
            $row['index'] = 0;
            $push($row);
        }

        return $items;
    }

    // ==================== 文章导航标签 ====================

    /**
     * 解析上一篇文章标签
     */
    public function parse_prev_post($attrs, $content, $data)
    {
        global $post;

        if (!$post) {
            return '';
        }

        // 获取上一篇文章
        $prev_post = $this->get_adjacent_post(false, '', true);

        if (!$prev_post) {
            return '';
        }

        // 准备上一篇文章的数据
        $prev_data = array_merge($data, [
            'post_id' => $prev_post->ID,
            'post_title' => $prev_post->post_title,
            'post_url' => todo_get_permalink($prev_post->ID),
            'post_excerpt' => $prev_post->post_excerpt,
            'post_date' => $prev_post->post_date,
            'post_author' => $prev_post->post_author
        ]);

        // 解析内部内容
        // parser instance
        return CMS_Template_Compiler_V2::render_string($content, $prev_data);
    }

    /**
     * 解析下一篇文章标签
     */
    public function parse_next_post($attrs, $content, $data)
    {
        global $post;

        if (!$post) {
            return '';
        }

        // 获取下一篇文章
        $next_post = $this->get_adjacent_post(false, '', false);

        if (!$next_post) {
            return '';
        }

        // 准备下一篇文章的数据
        $next_data = array_merge($data, [
            'post_id' => $next_post->ID,
            'post_title' => $next_post->post_title,
            'post_url' => todo_get_permalink($next_post->ID),
            'post_excerpt' => $next_post->post_excerpt,
            'post_date' => $next_post->post_date,
            'post_author' => $next_post->post_author
        ]);

        // 解析内部内容
        // parser instance
        return CMS_Template_Compiler_V2::render_string($content, $next_data);
    }

    /**
     * 获取相邻文章
     * 
     * @param bool $in_same_term 是否在同一分类中
     * @param string $excluded_terms 排除的分类
     * @param bool $previous 是否获取上一篇（true）还是下一篇（false）
     * @return object|null
     */
    private function get_adjacent_post($in_same_term = false, $excluded_terms = '', $previous = true)
    {
        global $post, $tododb;

        if (!$post) {
            return null;
        }

        $current_post_date = $post->post_date;
        $join = '';
        $posts_in_ex_terms_sql = '';
        $op = $previous ? '<' : '>';
        $order = $previous ? 'DESC' : 'ASC';

        // 如果需要在同一分类中查找
        if ($in_same_term) {
            $join = " INNER JOIN {$tododb->prefix}term_relationships AS tr ON p.ID = tr.object_id INNER JOIN {$tododb->prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id";

            // 获取当前文章的分类
            $cat_array = todo_get_the_category($post->ID);
            if (!empty($cat_array)) {
                $cat_list = [];
                foreach ($cat_array as $cat) {
                    $cat_list[] = $cat->term_id;
                }
                $join .= " AND tt.taxonomy = 'category' AND tt.term_id IN (" . implode(',', $cat_list) . ")";
            }
        }

        // 排除指定分类
        if (!empty($excluded_terms)) {
            $excluded_terms = array_map('intval', explode(',', $excluded_terms));
            $posts_in_ex_terms_sql = " AND p.ID NOT IN ( SELECT tr.object_id FROM {$tododb->prefix}term_relationships tr INNER JOIN {$tododb->prefix}term_taxonomy tt ON (tr.term_taxonomy_id = tt.term_taxonomy_id) WHERE tt.term_id IN (" . implode(',', $excluded_terms) . ") )";
        }

        $lang_filter = '';
        if (!defined('IS_ADMIN') || !IS_ADMIN) {
            $current_lang = current_locale();
            // 检查是否为主语言（主语言可见所有文章）
            $default_lang = function_exists('get_default_language') ? get_default_language() : 'zh-CN';
            $default_lang = str_replace('_', '-', $default_lang);
            $is_main_lang = ($current_lang === $default_lang);

            if (!$is_main_lang) {
                // 非主语言下，仅显示明确绑定了该语言的文章
                $lang_filter = " AND p.ID IN (SELECT post_id FROM {$tododb->prefix}postmeta WHERE meta_key = '_enabled_languages' AND meta_value LIKE '%\"{$current_lang}\"%')";
            }
        }

        $adjacent_post_sql = "SELECT p.* FROM {$tododb->prefix}posts AS p {$join} WHERE p.post_date {$op} ? AND p.post_type = ? AND p.post_status = 'publish' {$posts_in_ex_terms_sql} {$lang_filter} ORDER BY p.post_date {$order} LIMIT 1";

        return $tododb->get_row($adjacent_post_sql, [$current_post_date, $post->post_type]);
    }

    /**
     * 解析上一篇文章URL标签
     */
    public function parse_prev_post_url($attrs, $content, $data)
    {
        global $post;

        if (!$post) {
            return '';
        }

        $prev_post = $this->get_adjacent_post(false, '', true);

        if (!$prev_post) {
            $this->todo_adjacent_post = null;
            return '';
        }

        // 存储相邻文章供上下文内标签（如 post_thumbnail）使用
        $this->todo_adjacent_post = $prev_post;

        // 多语言：优先使用聚合的URL（支持自定义别名）
        if (function_exists('todo_get_post_multilang')) {
            $ml = todo_get_post_multilang($prev_post->ID, null, ['fallback' => true, 'prefer_custom_url' => true]);
            if (is_array($ml) && !empty($ml['url'])) {
                return $ml['url'];
            }
        }
        return todo_get_permalink($prev_post->ID);
    }

    /**
     * 解析上一篇文章标题标签
     */
    public function parse_prev_post_title($attrs, $content, $data)
    {
        global $post;

        if (!$post) {
            return '';
        }

        $prev_post = $this->get_adjacent_post(false, '', true);

        if (!$prev_post) {
            return '';
        }

        // 多语言：优先聚合翻译标题
        if (function_exists('todo_get_post_multilang')) {
            $ml = todo_get_post_multilang($prev_post->ID, null, ['fallback' => true]);
            if (is_array($ml) && !empty($ml['title'])) {
                return todo_esc_html($ml['title']);
            }
        }
        return todo_esc_html($prev_post->post_title);
    }

    /**
     * 解析下一篇文章URL标签
     */
    public function parse_next_post_url($attrs, $content, $data)
    {
        global $post;

        if (!$post) {
            return '';
        }

        $next_post = $this->get_adjacent_post(false, '', false);

        if (!$next_post) {
            $this->todo_adjacent_post = null;
            return '';
        }

        // 存储相邻文章供上下文内标签（如 post_thumbnail）使用
        $this->todo_adjacent_post = $next_post;

        // 多语言：优先使用聚合的URL（支持自定义别名）
        if (function_exists('todo_get_post_multilang')) {
            $ml = todo_get_post_multilang($next_post->ID, null, ['fallback' => true, 'prefer_custom_url' => true]);
            if (is_array($ml) && !empty($ml['url'])) {
                return $ml['url'];
            }
        }
        return todo_get_permalink($next_post->ID);
    }

    /**
     * 解析下一篇文章标题标签
     */
    public function parse_next_post_title($attrs, $content, $data)
    {
        global $post;

        if (!$post) {
            return '';
        }

        $next_post = $this->get_adjacent_post(false, '', false);

        if (!$next_post) {
            return '';
        }

        // 多语言：优先聚合翻译标题
        if (function_exists('todo_get_post_multilang')) {
            $ml = todo_get_post_multilang($next_post->ID, null, ['fallback' => true]);
            if (is_array($ml) && !empty($ml['title'])) {
                return todo_esc_html($ml['title']);
            }
        }
        return todo_esc_html($next_post->post_title);
    }

    // ==================== 缺失的模板标签方法 ====================

    /**
     * 解析通用if条件标签
     */
    public function parse_if($attrs, $content, $data)
    {
        // 这是一个通用的if处理方法，实际的条件判断应该由具体的if_xxx方法处理
        return '';
    }

    /**
     * 解析else标签
     */
    public function parse_else($attrs, $content, $data)
    {
        // else标签通常在条件语句中处理，这里返回空
        return '';
    }

    /**
     * 解析导航菜单标签
     * 支持属性：
     * - theme_location / name
     * - menu_id
     * - menu_class / class
     * - container
     * - container_class
     */
    public function parse_nav_menu($attrs, $content, $data)
    {
        // 兼容两种写法
        $location = isset($attrs['theme_location']) ? $attrs['theme_location'] : (isset($attrs['name']) ? $attrs['name'] : 'primary');
        $menu_id = isset($attrs['menu_id']) ? intval($attrs['menu_id']) : 0;

        $args = array(
            'theme_location' => $location,
            'menu_id' => isset($attrs['menu_id']) ? $attrs['menu_id'] : '',
            'container' => isset($attrs['container']) ? $attrs['container'] : 'nav',
            'container_class' => isset($attrs['container_class']) ? $attrs['container_class'] : '',
            'container_id' => isset($attrs['container_id']) ? $attrs['container_id'] : '',
            'menu_class' => isset($attrs['menu_class']) ? $attrs['menu_class'] : (isset($attrs['class']) ? $attrs['class'] : 'nav-menu'),
            'fallback_cb' => 'todo_nav_menu_fallback',
            'echo' => isset($attrs['echo']) ? $attrs['echo'] : false,
        );

        if (is_string($content) && trim($content) !== '') {
            $menu = null;
            $items = [];

            if (class_exists('Todo_Menu_Manager') && method_exists('Todo_Menu_Manager', 'get_menu_items')) {
                if ($menu_id > 0 && method_exists('Todo_Menu_Manager', 'get_menu')) {
                    $menu = Todo_Menu_Manager::get_menu($menu_id);
                    $items = Todo_Menu_Manager::get_menu_items($menu_id, true);
                } elseif (method_exists('Todo_Menu_Manager', 'get_menu_by_location')) {
                    $menu = Todo_Menu_Manager::get_menu_by_location($location, $args['menu_id']);
                    if (is_array($menu) && !empty($menu['id'])) {
                        $items = Todo_Menu_Manager::get_menu_items((int) $menu['id'], true);
                    }
                }
            }

            if (empty($items)) {
                $flat = function_exists('todo_get_nav_menu_items') ? todo_get_nav_menu_items($location) : [];
                $flat_items = [];
                foreach ((array) $flat as $it) {
                    $flat_items[] = is_object($it) ? (array) $it : (array) $it;
                }

                $by_parent = [];
                foreach ($flat_items as $it) {
                    $pid = isset($it['parent_id']) ? (int) $it['parent_id'] : 0;
                    if (!isset($by_parent[$pid])) {
                        $by_parent[$pid] = [];
                    }
                    $by_parent[$pid][] = $it;
                }

                $build = function ($parent_id) use (&$build, &$by_parent) {
                    $out = [];
                    if (!isset($by_parent[$parent_id])) {
                        return $out;
                    }
                    foreach ($by_parent[$parent_id] as $it) {
                        $id = isset($it['id']) ? (int) $it['id'] : 0;
                        $children = $id > 0 ? $build($id) : [];
                        if (!empty($children)) {
                            $it['children'] = $children;
                        }
                        $out[] = $it;
                    }
                    return $out;
                };

                $items = $build(0);
            }

            if (!empty($items)) {
                $this->capture_menu_category_visibility_dependencies($items);
                $items = $this->filter_visible_menu_items($items);
            }

            $normalize = function ($items, $depth = 0) use (&$normalize) {
                $out = [];
                $request_path = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
                $request_path = $request_path !== '' ? (string) parse_url($request_path, PHP_URL_PATH) : '';
                $request_query = isset($_SERVER['REQUEST_URI']) ? (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_QUERY) : '';
                $http_host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
                $current_host = $http_host !== '' ? (string) parse_url('http://' . $http_host, PHP_URL_HOST) : '';
                $reg = class_exists('LanguageRegistry') ? LanguageRegistry::get_instance() : null;

                $install_segments = (function () {
                    $segments = array();
                    $doc = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/')) : '';
                    $abs = defined('ABSPATH') ? str_replace('\\', '/', rtrim((string) ABSPATH, '/')) : '';
                    if ($doc && $abs && strpos($abs, $doc) === 0) {
                        $rel = trim(substr($abs, strlen($doc)), '/');
                        if ($rel !== '') {
                            $segments = array_values(array_filter(explode('/', $rel), function ($s) {
                                return $s !== '';
                            }));
                        }
                    } else {
                        $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']) : '';
                        if ($script) {
                            $dir = trim((string) dirname($script), '/');
                            if ($dir !== '') {
                                $segments = array_values(array_filter(explode('/', $dir), function ($s) {
                                    return $s !== '' && strtolower($s) !== 'admin';
                                }));
                            }
                        }
                    }
                    return $segments;
                })();

                $strip_lang_prefix = function ($p) use ($reg, $install_segments) {
                    $p = (string) $p;
                    if ($p === '') {
                        return '/';
                    }
                    $segments = array_values(array_filter(explode('/', $p), function ($seg) {
                        return $seg !== '';
                    }));
                    $offset = 0;
                    if (!empty($install_segments)) {
                        $len = count($install_segments);
                        if (count($segments) >= $len && array_slice($segments, 0, $len) === $install_segments) {
                            $offset = $len;
                        }
                    }
                    if ($reg) {
                        $known = array_keys($reg->path_prefix_map());
                        if (isset($segments[$offset]) && in_array($segments[$offset], $known, true)) {
                            array_splice($segments, $offset, 1);
                        }
                    }
                    $np = '/' . implode('/', $segments);
                    if ($np === '/') {
                        return '/';
                    }
                    if (substr($p, -1) === '/' && substr($np, -1) !== '/') {
                        $np .= '/';
                    }
                    return $np;
                };

                $normalize_path = function ($p) use ($strip_lang_prefix) {
                    $p = $p !== '' ? (string) $p : '/';
                    $p = $strip_lang_prefix($p);
                    $p = rtrim($p, '/');
                    return $p === '' ? '/' : $p;
                };

                $normalize_query = function ($q) {
                    $q = (string) $q;
                    if ($q === '') {
                        return '';
                    }
                    $arr = [];
                    parse_str($q, $arr);
                    if (is_array($arr) && !empty($arr)) {
                        ksort($arr);
                        return http_build_query($arr);
                    }
                    return '';
                };

                $rp_norm = $normalize_path($request_path !== '' ? $request_path : '/');
                $rq_norm = $normalize_query($request_query);

                foreach ((array) $items as $item) {
                    $it = is_object($item) ? (array) $item : (array) $item;

                    $title_raw = isset($it['title']) ? (string) $it['title'] : '';
                    if (function_exists('todo_t')) {
                        $title_raw = todo_t($title_raw, 'menu');
                    }
                    $title = function_exists('todo_esc_html') ? todo_esc_html($title_raw) : htmlspecialchars($title_raw, ENT_QUOTES, 'UTF-8');
                    $title_attr = function_exists('todo_esc_attr') ? todo_esc_attr($title_raw) : htmlspecialchars($title_raw, ENT_QUOTES, 'UTF-8');

                    $url_raw = isset($it['url']) ? (string) $it['url'] : '';
                    if ($url_raw !== '' && strpos($url_raw, '/') === 0 && parse_url($url_raw, PHP_URL_HOST) === null) {
                        if (function_exists('todo_home_url')) {
                            $url_raw = rtrim(todo_home_url('/'), '/') . '/' . ltrim($url_raw, '/');
                            $url_raw = rtrim($url_raw, '/');
                            if ($url_raw === '') {
                                $url_raw = '/';
                            }
                        }
                    }
                    if ($url_raw !== '' && function_exists('get_current_language') && function_exists('get_language_url')) {
                        $item_host = (string) parse_url($url_raw, PHP_URL_HOST);
                        if ($item_host === '') {
                            $url_raw = get_language_url(get_current_language(), $url_raw);
                        } else {
                            $ih = (string) $item_host;
                            if ($current_host !== '' && strtolower($ih) === strtolower($current_host)) {
                                $url_raw = get_language_url(get_current_language(), $url_raw);
                            }
                        }
                    }
                    $url = function_exists('esc_url') ? esc_url($url_raw) : (function_exists('todo_esc_attr') ? todo_esc_attr($url_raw) : htmlspecialchars($url_raw, ENT_QUOTES, 'UTF-8'));

                    $target_raw = '';
                    if (isset($it['target'])) {
                        $target_raw = (string) $it['target'];
                    } elseif (!empty($it['new_window'])) {
                        $target_raw = '_blank';
                    }
                    if ($target_raw === '') {
                        $target_raw = '_self';
                    }
                    $target = function_exists('todo_esc_attr') ? todo_esc_attr($target_raw) : htmlspecialchars($target_raw, ENT_QUOTES, 'UTF-8');

                    $desc_raw = '';
                    if (isset($it['description'])) {
                        $desc_raw = (string) $it['description'];
                    } elseif (isset($it['attr_title'])) {
                        $desc_raw = (string) $it['attr_title'];
                    }
                    $desc_attr = function_exists('todo_esc_attr') ? todo_esc_attr($desc_raw) : htmlspecialchars($desc_raw, ENT_QUOTES, 'UTF-8');

                    $children_in = [];
                    if (isset($it['children']) && is_array($it['children'])) {
                        $children_in = $it['children'];
                    }
                    $children = !empty($children_in) ? $normalize($children_in, $depth + 1) : [];
                    $has_children = !empty($children);

                    $is_current = false;
                    if ($url_raw !== '') {
                        $item_host = (string) parse_url($url_raw, PHP_URL_HOST);
                        if ($item_host !== '' && $current_host !== '' && strtolower($item_host) !== strtolower($current_host)) {
                            $is_current = false;
                        } else {
                            $item_path = (string) parse_url($url_raw, PHP_URL_PATH);
                            $item_query = (string) parse_url($url_raw, PHP_URL_QUERY);
                            $ip_norm = $normalize_path($item_path !== '' ? $item_path : '/');
                            $iq_norm = $normalize_query($item_query);
                            if ($ip_norm === $rp_norm && $iq_norm === $rq_norm) {
                                $is_current = true;
                            }
                        }
                    }

                    $is_active = $is_current;
                    if (!$is_active && !empty($children)) {
                        foreach ($children as $child) {
                            if ((is_array($child) && !empty($child['nav_is_active'])) || (is_object($child) && !empty($child->nav_is_active))) {
                                $is_active = true;
                                break;
                            }
                        }
                    }

                    $out[] = [
                        'nav_id' => isset($it['id']) ? (int) $it['id'] : 0,
                        'nav_parent_id' => isset($it['parent_id']) ? (int) $it['parent_id'] : 0,
                        'nav_title' => $title,
                        'nav_title_raw' => $title_raw,
                        'nav_title_attr' => $title_attr,
                        'nav_url' => $url,
                        'nav_url_raw' => $url_raw,
                        'nav_target' => $target,
                        'nav_target_raw' => $target_raw,
                        'nav_description' => $desc_attr,
                        'nav_description_raw' => $desc_raw,
                        'nav_css_class' => isset($it['css_class']) ? (string) $it['css_class'] : '',
                        'nav_has_children' => $has_children,
                        'nav_is_current' => $is_current,
                        'nav_is_active' => $is_active,
                        'nav_depth' => (int) $depth,
                        'nav_children' => $children,
                    ];
                }

                return $out;
            };

            $menu_items = $normalize($items, 0);

            // parser instance
            $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
            $request_path_now = $request_uri !== '' ? (string) parse_url($request_uri, PHP_URL_PATH) : '';
            $request_query_now = $request_uri !== '' ? (string) parse_url($request_uri, PHP_URL_QUERY) : '';
            $rp = $request_path_now !== '' ? $request_path_now : '/';
            $reg = class_exists('LanguageRegistry') ? LanguageRegistry::get_instance() : null;
            $prefixes = $reg ? array_keys($reg->path_prefix_map()) : array();
            $strip = function ($p) use ($prefixes) {
                $p = rtrim((string) $p, '/');
                if ($p === '') {
                    $p = '/';
                }
                if (!empty($prefixes)) {
                    foreach ($prefixes as $prefix) {
                        $prefix = trim((string) $prefix, '/');
                        if ($prefix !== '' && ($p === '/' . $prefix || strpos($p, '/' . $prefix . '/') === 0)) {
                            $p = substr($p, strlen('/' . $prefix));
                            if ($p === '') {
                                $p = '/';
                            }
                            break;
                        }
                    }
                }
                return $p;
            };
            $rp_norm = rtrim($strip($rp), '/');
            if ($rp_norm === '') {
                $rp_norm = '/';
            }
            $nav_home_is_current = ($rp_norm === '/' && trim((string) $request_query_now) === '');

            return CMS_Template_Compiler_V2::render_string($content, array_merge($data, [
                'nav_menu_location' => $location,
                'nav_menu_id' => $args['menu_id'],
                'nav_menu_class' => $args['menu_class'],
                'nav_menu_container' => $args['container'],
                'nav_menu_container_class' => $args['container_class'],
                'nav_menu_container_id' => $args['container_id'],
                'nav_home_is_current' => $nav_home_is_current,
                'menu_items' => $menu_items,
            ]));
        }

        // 优先使用菜单管理器的渲染逻辑
        if (function_exists('todo_nav_menu')) {
            $html = todo_nav_menu($args);
            return is_string($html) ? $html : '';
        }

        // 回退到最简实现
        $items = function_exists('todo_get_nav_menu_items') ? todo_get_nav_menu_items($location) : array();
        if (empty($items)) {
            return '';
        }

        $this->capture_menu_category_visibility_dependencies($items);
        $items = $this->filter_visible_menu_items($items);

        $output = '<ul class="' . esc_attr($args['menu_class']) . '">';
        foreach ($items as $item) {
            $target = !empty($item->new_window) ? ' target="_blank" rel="noopener noreferrer"' : '';
            $titleAttr = !empty($item->attr_title) ? ' title="' . esc_attr($item->attr_title) . '"' : '';
            $cssClass = !empty($item->css_class) ? ' class="' . esc_attr($item->css_class) . '"' : '';
            $ttl = isset($item->title) ? $item->title : '';
            if (function_exists('todo_t')) {
                $ttl = todo_t($ttl, 'menu');
            }
            $output .= '<li><a href="' . esc_url($item->url) . '"' . $target . $titleAttr . '>' . esc_html($ttl) . '</a></li>';
        }
        $output .= '</ul>';
        return $output;
    }

    public function parse_nav_url($attrs, $content, $data)
    {
        return (is_array($data) && isset($data['nav_url'])) ? (string) $data['nav_url'] : '';
    }

    public function parse_nav_target($attrs, $content, $data)
    {
        return (is_array($data) && isset($data['nav_target'])) ? (string) $data['nav_target'] : '';
    }

    public function parse_nav_title($attrs, $content, $data)
    {
        return (is_array($data) && isset($data['nav_title'])) ? (string) $data['nav_title'] : '';
    }

    public function parse_nav_title_attr($attrs, $content, $data)
    {
        return (is_array($data) && isset($data['nav_title_attr'])) ? (string) $data['nav_title_attr'] : '';
    }

    /**
     * 解析首页URL标签
     */
    public function parse_home_url($attrs, $content, $data)
    {
        // 基础首页URL（优先使用 todo_home_url 以避免加载顺序导致的别名问题）
        $base = function_exists('todo_home_url') ? todo_home_url() : (function_exists('home_url') ? home_url() : '/');
        // 当前语言
        $locale = function_exists('current_locale') ? current_locale() : '';
        // 生成带语言的URL
        if (function_exists('get_language_url') && !empty($locale)) {
            return rtrim(get_language_url($locale, $base), '/');
        }
        return rtrim($base, '/');
    }

    /**
     * 解析body class标签
     */
    public function parse_body_class($attrs, $content, $data)
    {
        $classes = [];
        $mode = 'attr';
        if (is_array($attrs) && isset($attrs['mode'])) {
            $mode = (string) $attrs['mode'];
        }

        if (todo_is_home()) {
            $classes[] = 'home';
        }
        if (todo_is_single()) {
            $classes[] = 'single';
        }
        if (todo_is_page()) {
            $classes[] = 'page';
        }
        if (todo_is_category()) {
            $classes[] = 'category';
        }
        if (todo_is_tag()) {
            $classes[] = 'tag';
        }
        if (todo_is_search()) {
            $classes[] = 'search';
        }
        if (todo_is_404()) {
            $classes[] = 'error404';
        }

        $extra = '';
        if (is_array($attrs)) {
            if (isset($attrs['class'])) {
                $extra = (string) $attrs['class'];
            } elseif (isset($attrs[0])) {
                $extra = (string) $attrs[0];
            }
        }
        if ($extra !== '') {
            $extra_parts = preg_split('/\s+/', trim($extra)) ?: [];
            foreach ($extra_parts as $c) {
                if ($c !== '') {
                    $classes[] = $c;
                }
            }
        }

        $classes = array_values(array_unique(array_filter(array_map('trim', $classes), function ($c) {
            return $c !== '';
        })));

        if (empty($classes)) {
            return '';
        }

        $class_value = implode(' ', $classes);
        $class_value = function_exists('esc_attr') ? esc_attr($class_value) : htmlspecialchars($class_value, ENT_QUOTES, 'UTF-8');
        if ($mode === 'names') {
            return $class_value;
        }
        return 'class="' . $class_value . '"';
    }

    /**
     * 解析 {{todo:cookie_table}} 标签，自动输出合规 Cookie 明细表
     */
    public function parse_cookie_table($attrs, $content, $data)
    {
        if (!function_exists('todo_get_cookie_declaration_table_html')) {
            $f = defined('ABSPATH') ? ABSPATH . 'core/functions/theme.php' : dirname(__DIR__) . '/functions/theme.php';
            if (file_exists($f)) {
                require_once $f;
            }
        }
        if (function_exists('todo_get_cookie_declaration_table_html')) {
            return todo_get_cookie_declaration_table_html();
        }
        return '';
    }

    /**
     * 解析todo_head标签（新方案）
     * 触发头部钩子与统一资源输出
     */
    public function parse_todo_head($attrs, $content, $data)
    {
        ob_start();

        // 先触发资源排队钩子，确保各模块完成入队
        if (function_exists('do_action')) {
            do_action('todo_enqueue_scripts');
        }

        // 触发头部钩子，输出由模块注册的内容（如语言meta）
        if (function_exists('do_action')) {
            do_action('todo_head');
        }

        // 统一输出已排队的样式与头部脚本
        if (function_exists('todo_print_styles')) {
            todo_print_styles();
        }
        if (function_exists('todo_print_scripts')) {
            todo_print_scripts(false);
        }

        $out = ob_get_clean();
        $indent = '    ';
        if (is_array($attrs)) {
            if (isset($attrs['indent'])) {
                $v = $attrs['indent'];
                if (is_numeric($v)) {
                    $indent = str_repeat(' ', max(0, (int) $v));
                } elseif (is_string($v)) {
                    $indent = $v;
                }
            }
        }
        $res = $this->indent_multiline_output($out, $indent);

        // --- Early Flush (Chunked Streaming) ---
        // 仅在真实的前台请求中触发 Early Flush。如果是在后台（如生成缓存）、AJAX 或 CLI 请求，均跳过
        $is_frontend = (!defined('IS_ADMIN') || !IS_ADMIN) && (!defined('DOING_AJAX') || !DOING_AJAX) && (php_sapi_name() !== 'cli');
        
        if ($is_frontend && empty($GLOBALS['todo_doing_cache_warmup'])) {
            // 直接输出头部内容
            echo $res;

            // 清空（Flush）所有现有的输出缓冲区并推送到外层缓冲区
            $levels = ob_get_level();
            for ($i = 0; $i < $levels; $i++) {
                @ob_flush();
            }
            @flush();

            // 已刷出，返回空字符串，避免该内容在当前 ob 层级中重复存在
            return '';
        }

        return $res;
    }

    private function indent_multiline_output($s, $indent)
    {
        if (!is_string($s) || $s === '') {
            return '';
        }
        $s = str_replace("\r\n", "\n", $s);
        $has_trailing_newline = (substr($s, -1) === "\n");
        if ($has_trailing_newline) {
            $s = rtrim($s, "\n");
        }
        $lines = explode("\n", $s);
        $indented = [];
        $indented[] = $lines[0];
        $indent = is_string($indent) ? $indent : '';
        $n = count($lines);
        for ($i = 1; $i < $n; $i++) {
            $line = $lines[$i];
            if ($line === '') {
                $indented[] = '';
            } else {
                $indented[] = $indent . $line;
            }
        }
        $out = implode("\n", $indented);
        if ($has_trailing_newline) {
            $out .= "\n";
        }
        return $out;
    }

    /**
     * 解析todo_body_open标签
     */
    public function parse_todo_body_open($attrs, $content, $data)
    {
        // 在body开始标签后输出的内容
        return '';
    }

    /**
     * 解析动态侧边栏标签
     */
    public function parse_dynamic_sidebar($attrs, $content, $data)
    {
        $sidebar_id = isset($attrs['id']) ? $attrs['id'] : 'sidebar-1';

        // 获取侧边栏小工具
        $widgets = todo_get_sidebar_widgets($sidebar_id);

        if (empty($widgets)) {
            return '';
        }

        $output = '<div class="sidebar-widgets">';
        foreach ($widgets as $widget) {
            $output .= '<div class="widget">' . $widget . '</div>';
        }
        $output .= '</div>';

        return $output;
    }

    /**
     * 解析搜索URL标签
     * 支持可选属性：keyword 或 q，用于直接生成带关键词的搜索链接；
     * 若未传入，则仅返回带当前语言前缀的基础搜索URL（/search）。
     */
    public function parse_search_url($attrs, $content, $data)
    {
        // 搜索基础URL（从首页构建）
        $base = function_exists('todo_home_url') ? todo_home_url('/') : (function_exists('home_url') ? home_url('/') : '/');
        $url = rtrim($base, '/') . '/search';

        // 提取关键词：优先 attrs，其次内容块，再次上下文数据
        $kw = '';
        if (is_array($attrs) && !isset($attrs['keyword']) && isset($attrs['q'])) {
            $attrs['keyword'] = $attrs['q'];
        }
        if (isset($attrs['keyword']) && $attrs['keyword'] !== '') {
            $kw = $attrs['keyword'];
        } elseif (is_string($content) && trim($content) !== '') {
            $kw = trim($content);
        } else {
            if (isset($data['keyword']) && $data['keyword'] !== '') {
                $kw = $data['keyword'];
            } elseif (isset($data['search_term']) && $data['search_term'] !== '') {
                $kw = $data['search_term'];
            }
        }

        if ($kw !== '') {
            $url .= '?q=' . rawurlencode($kw);
        }

        // 当前语言
        $locale = function_exists('current_locale') ? current_locale() : '';
        // 生成带语言的URL
        if (function_exists('get_language_url') && !empty($locale)) {
            return get_language_url($locale, $url);
        }
        return $url;
    }

    /**
     * 解析搜索关键词标签
     */
    public function parse_search_keyword($attrs, $content, $data)
    {
        return get_search_query();
    }

    /**
     * 解析搜索是否被频率限制锁定标签
     */
    public function parse_search_locked($attrs, $content, $data)
    {
        return (defined('SEARCH_LOCKED') && SEARCH_LOCKED) ? '1' : '';
    }

    public function parse_url($attrs, $content, $data)
    {
        global $post;

        if (is_array($data)) {
            if (isset($data['url']) && $data['url'] !== '') {
                return esc_url((string)$data['url']);
            }
            if (isset($data['tag_url']) && $data['tag_url'] !== '') {
                return esc_url((string)$data['tag_url']);
            }
            if (isset($data['href']) && $data['href'] !== '') {
                return esc_url((string)$data['href']);
            }
        }

        if ($post && isset($post->ID)) {
            return todo_get_permalink($post->ID);
        }

        return '';
    }

    public function parse_link($attrs, $content, $data)
    {
        if (isset($data['link_url']) && !isset($data['link'])) {
            $data['link'] = $data['link_url'];
        }
        if (isset($data['link']) && $data['link'] !== '') {
            return esc_url($data['link']);
        }
        return '';
    }

    public function parse_target($attrs, $content, $data)
    {
        if (isset($data['link_target']) && !isset($data['target'])) {
            $data['target'] = $data['link_target'];
        }
        if (isset($data['target']) && $data['target'] !== '') {
            return esc_attr($data['target']);
        }
        return '_self';
    }

    /**
     * 解析索引标签
     */
    public function parse_index($attrs, $content, $data)
    {
        return isset($data['index']) ? $data['index'] : '';
    }

    /**
     * 解析评论ID
     */
    public function parse_comment_id($attrs, $content, $data)
    {
        if (isset($data['comment_id']) && $data['comment_id'] !== '') {
            return (string) $data['comment_id'];
        }
        if (isset($data['id']) && $data['id'] !== '') {
            return (string) $data['id'];
        }
        if (isset($data['comment_ID']) && $data['comment_ID'] !== '') {
            return (string) $data['comment_ID'];
        }
        return '';
    }

    /**
     * 解析评论作者
     */
    public function parse_comment_author($attrs, $content, $data)
    {
        if (isset($data['author_name']) && $data['author_name'] !== '') {
            return esc_html($data['author_name']);
        }
        if (isset($data['comment_author']) && $data['comment_author'] !== '') {
            return esc_html($data['comment_author']);
        }
        return '';
    }

    /**
     * 解析评论日期
     */
    public function parse_comment_date($attrs, $content, $data)
    {
        $date = isset($data['comment_date']) ? $data['comment_date'] : '';
        if (!$date) return '';
        $format = isset($attrs['format']) ? $attrs['format'] : 'Y-m-d H:i';
        return date($format, strtotime($date));
    }

    /**
     * 解析评论内容
     */
    public function parse_comment_content($attrs, $content, $data)
    {
        $content = isset($data['comment_content']) ? $data['comment_content'] : '';
        if (function_exists('autop')) {
            return autop($content);
        }
        return nl2br(esc_html($content));
    }

    /**
     * 解析评论头像
     */
    public function parse_comment_avatar($attrs, $content, $data)
    {
        $email = isset($data['comment_author_email']) ? $data['comment_author_email'] : '';
        $size = isset($attrs['size']) ? intval($attrs['size']) : 64;
        if (function_exists('get_avatar')) {
            return get_avatar($email, $size);
        }
        return '';
    }

    /**
     * 解析回复链接
     */
    public function parse_comment_reply_link($attrs, $content, $data)
    {
        $id = isset($data['comment_ID']) ? intval($data['comment_ID']) : 0;
        if (!$id) return '';
        
        $text = isset($attrs['text']) ? $attrs['text'] : '回复';
        $class = isset($attrs['class']) ? $attrs['class'] : 'comment-reply-link';
        
        return sprintf(
            '<a href="#comment-form" class="%s" data-id="%d" data-author="%s">%s</a>',
            esc_attr($class),
            $id,
            isset($data['comment_author']) ? esc_attr($data['comment_author']) : '',
            esc_html($text)
        );
    }

    /**
     * 解析评论数量标签
     */
    public function parse_comments_count($attrs, $content, $data)
    {
        global $post;

        $post_id = $post ? (int)($post->ID ?? 0) : (int)($data['post_id'] ?? 0);
        if ($post_id <= 0) {
            return '0';
        }

        $type = isset($attrs['type']) ? (string)$attrs['type'] : null;
        return (string) todo_get_comments_number($post_id, $type);
    }

    public function parse_comment_count($attrs, $content, $data)
    {
        return $this->parse_comments_count($attrs, $content, $data);
    }

    /**
     * 是否开启评论
     */
    public function parse_comments_enabled($attrs, $content, $data)
    {
        if (isset($data['comments_enabled'])) {
            return !empty($data['comments_enabled']);
        }
        global $post;
        if ($post && isset($post->comment_status)) {
            if ($post->comment_status === 'closed') {
                return false;
            }
        }
        $status = function_exists('get_option') ? get_option('default_comment_status', 'open') : 'open';
        return $status === 'open';
    }

    /**
     * 用户是否能够评论
     */
    public function parse_user_can_comment($attrs, $content, $data)
    {
        if (isset($data['user_can_comment'])) {
            return !empty($data['user_can_comment']);
        }
        $req_login = function_exists('get_option') ? get_option('comment_registration', 0) : 0;
        if ($req_login && !is_user_logged_in()) {
            return false;
        }
        return true;
    }

    /**
     * 解析评论列表数据（用于 {{todo:loop comments}} 或条件判断）
     */
    public function parse_comments($attrs, $content, $data)
    {
        if (isset($data['comments']) && is_array($data['comments'])) {
            return $data['comments'];
        }
        global $post;
        $post_id = $post ? (int)($post->ID ?? 0) : (int)($data['post_id'] ?? 0);
        if ($post_id <= 0) {
            return [];
        }

        if (class_exists('Todo_Comment_Manager') && method_exists('Todo_Comment_Manager', 'get_comment_tree')) {
            $tree = Todo_Comment_Manager::get_comment_tree($post_id, 'comment', 'approved');
        } elseif (function_exists('todo_get_comment_tree')) {
            $tree = todo_get_comment_tree($post_id, 'comment', 'approved');
        } else {
            $tree = [];
        }

        $formatted = [];
        if (is_array($tree)) {
            foreach ($tree as $raw_item) {
                $item = (array) $raw_item;
                $email = $item['comment_author_email'] ?? ($item['email'] ?? '');
                $author_name = $item['comment_author'] ?? ($item['author_name'] ?? '');
                $initial_avatar = mb_substr($author_name, 0, 1);

                $replies = [];
                if (!empty($item['children']) && (is_array($item['children']) || is_object($item['children']))) {
                    foreach ((array)$item['children'] as $raw_child) {
                        $child = (array) $raw_child;
                        $c_author_name = $child['comment_author'] ?? ($child['author_name'] ?? '');
                        $c_initial_avatar = mb_substr($c_author_name, 0, 1);
                        $c_id = $child['comment_ID'] ?? ($child['id'] ?? ($child['comment_id'] ?? 0));
                        $replies[] = [
                            'comment_id' => $c_id,
                            'comment_ID' => $c_id,
                            'id' => $c_id,
                            'author_name' => $c_author_name,
                            'reply_to_name' => $author_name,
                            'comment_date' => $child['comment_date'] ?? ($child['created_at'] ?? ''),
                            'comment_content' => $child['comment_content'] ?? ($child['content'] ?? ''),
                            'author_avatar' => $c_initial_avatar,
                        ];
                    }
                }

                $m_id = $item['comment_ID'] ?? ($item['id'] ?? ($item['comment_id'] ?? 0));
                $formatted[] = [
                    'comment_id' => $m_id,
                    'comment_ID' => $m_id,
                    'id' => $m_id,
                    'author_name' => $author_name,
                    'author_email' => $email,
                    'author_website' => $item['comment_author_url'] ?? '',
                    'author_avatar' => $initial_avatar,
                    'comment_date' => $item['comment_date'] ?? ($item['created_at'] ?? ''),
                    'is_author' => !empty($item['user_id']) && $post && ($item['user_id'] == ($post->post_author ?? 0)),
                    'comment_content' => $item['comment_content'] ?? ($item['content'] ?? ''),
                    'comment_rating' => intval($item['comment_rating'] ?? ($item['rating'] ?? 0)),
                    'rating' => intval($item['comment_rating'] ?? ($item['rating'] ?? 0)),
                    'likes_count' => intval($item['comment_karma'] ?? ($item['likes'] ?? 0)),
                    'user_can_delete' => is_user_logged_in() && todo_current_user_can('manage_comments'),
                    'replies' => $replies,
                ];
            }
        }
        return $formatted;
    }

    /**
     * 解析分类列表标签
     */
    public function parse_post_categories_links($attrs, $content, $data)
    {
        global $post;

        if ($post && isset($post->ID)) {
            $categories = todo_get_the_category($post->ID);
            $output = [];

            foreach ($categories as $category) {
                $output[] = '<a href="' . todo_get_category_link($category->term_id) . '">' . esc_html($category->name) . '</a>';
            }

            return implode(', ', $output);
        }

        return '';
    }

    public function parse_name($attrs, $content, $data)
    {
        global $post;

        $name = '';
        if (is_array($data)) {
            if (isset($data['name']) && (string)$data['name'] !== '') {
                $name = (string) $data['name'];
            } elseif (isset($data['tag_name']) && (string)$data['tag_name'] !== '') {
                $name = (string) $data['tag_name'];
            } elseif (isset($data['category_name']) && (string)$data['category_name'] !== '') {
                $name = (string) $data['category_name'];
            } elseif (isset($data['title']) && (string)$data['title'] !== '') {
                $name = (string) $data['title'];
            }
        }

        if ($name === '' && $post && isset($post->post_title)) {
            $name = (string) $post->post_title;
        }

        if ($name !== '') {
            if (function_exists('todo_parse_inline_translations')) {
                $name = todo_parse_inline_translations($name);
            }
            if (function_exists('todo_translate')) {
                $name = todo_translate($name);
            }
            return esc_html($name);
        }

        return '';
    }

    /**
     * 解析文章数量标签
     */
    public function parse_articles_count($attrs, $content, $data)
    {
        if (isset($data['articles_count'])) {
            return $data['articles_count'];
        }

        return '0';
    }

    /**
     * 解析年份标签
     */
    public function parse_year($attrs, $content, $data)
    {
        if (isset($data['year'])) {
            return $data['year'];
        }

        return date('Y');
    }

    /**
     * 解析月份标签
     */
    public function parse_month($attrs, $content, $data)
    {
        if (isset($data['month'])) {
            return $data['month'];
        }

        return date('m');
    }

    /**
     * 解析归档URL标签
     */
    public function parse_archives_url($attrs, $content, $data)
    {
        if (isset($data['archives_url'])) {
            return esc_url((string)$data['archives_url']);
        }
        return '';
    }

    /**
     * 解析归档标题标签
     */
    public function parse_archives_title($attrs, $content, $data)
    {
        if (isset($data['archives_title'])) {
            return esc_html((string)$data['archives_title']);
        }
        return '';
    }

    public function parse_archive_url($attrs, $content, $data)
    {
        if (isset($data['archive_url'])) return esc_url((string)$data['archive_url']);
        return '';
    }

    public function parse_archive_title($attrs, $content, $data)
    {
        if (isset($data['archive_title'])) return esc_html((string)$data['archive_title']);
        return '';
    }

    public function parse_archive_count($attrs, $content, $data)
    {
        if (isset($data['archive_count'])) return $data['archive_count'];
        return '0';
    }

    public function parse_archive_year($attrs, $content, $data)
    {
        if (isset($data['archive_year'])) return $data['archive_year'];
        return '';
    }

    public function parse_archive_month($attrs, $content, $data)
    {
        if (isset($data['archive_month'])) return $data['archive_month'];
        return '';
    }


    /**
     * 解析归档标签
     */
    public function parse_archives_list($attrs, $content, $data)
    {
        // 获取文章归档
        $archives = todo_get_archives();

        if (empty($archives)) {
            return '';
        }

        $output = '<ul class="archives">';
        foreach ($archives as $archive) {
            $output .= '<li><a href="' . esc_url($archive['url']) . '">' . esc_html($archive['title']) . ' (' . $archive['count'] . ')</a></li>';
        }
        $output .= '</ul>';

        return $output;
    }

    /**
     * 解析链接标签
     */
    public function parse_links_list($attrs, $content, $data)
    {
        // 获取友情链接
        $links = todo_get_bookmarks();

        if (empty($links)) {
            return '';
        }

        $output = '<ul class="links">';
        foreach ($links as $link) {
            // 兼容TodoCMS友情链接字段，包含：name, url, description, logo, target
            $name = isset($link->name) ? $link->name : '';
            $url = isset($link->url) ? $link->url : '';
            $desc = isset($link->description) ? $link->description : '';
            $logo = isset($link->logo) ? $link->logo : '';
            $target = isset($link->target) ? $link->target : '_blank';

            $rel = ($target === '_blank') ? ' rel="noopener noreferrer"' : '';

            $output .= '<li><a href="' . esc_url($url) . '" target="' . esc_attr($target) . '"' . $rel . ($desc ? ' title="' . esc_attr($desc) . '"' : '') . '>';
            if (!empty($logo)) {
                $output .= '<img src="' . esc_url($logo) . '" alt="' . esc_attr($name) . '">';
            }
            $output .= esc_html($name);
            if (!empty($desc)) {
                $output .= '<span>' . esc_html($desc) . '</span>';
            }
            $output .= '</a></li>';
        }
        $output .= '</ul>';

        return $output;
    }

    /**
     * 解析归档循环标签
     */
    public function parse_archives($attrs, $content, $data)
    {
        // 分类信息模式：当使用 id=XX 时获取归档栏目元数据
        if (isset($attrs['id'])) {
            return $this->render_category_info('archives', $attrs, $content, $data);
        }

        $archives = todo_get_archives();

        if (empty($archives)) {
            return '';
        }

        $per_page = isset($attrs['per_page']) ? intval($attrs['per_page']) : 0;
        $limit = isset($attrs['limit']) ? intval($attrs['limit']) : 0;

        // 分页处理（per_page 优先级高于 limit）
        if ($per_page > 0) {
            $total_items = count($archives);
            $total_pages = max(1, (int) ceil($total_items / $per_page));
            $current_page = max(1, isset($_GET['paged']) ? intval($_GET['paged']) : 1);
            $current_page = min($current_page, $total_pages);
            $offset = ($current_page - 1) * $per_page;

            $archives = array_slice($archives, $offset, $per_page);

            // 设置分页数据
            $base_url = function_exists('todo_get_current_url') ? todo_get_current_url() : (function_exists('todo_home_url') ? todo_home_url('/') : '/');
            $pagination = array();
            $pagination['pagination'] = ($total_pages > 1);
            $pagination['pagination_current_page'] = $current_page;
            $pagination['pagination_total_pages'] = $total_pages;
            $pagination['pagination_total_items'] = $total_items;
            $pagination['pagination_per_page'] = $per_page;
            $pagination['pagination_prev_page'] = ($current_page > 1) ? $current_page - 1 : 0;
            $pagination['pagination_next_page'] = ($current_page < $total_pages) ? $current_page + 1 : 0;
            $pagination['pagination_prev_url'] = ($pagination['pagination_prev_page'] > 0)
                ? CMS_Template_Compiler_V2::build_paginated_url($pagination['pagination_prev_page'], $base_url, 'paged')
                : '';
            $pagination['pagination_next_url'] = ($pagination['pagination_next_page'] > 0)
                ? CMS_Template_Compiler_V2::build_paginated_url($pagination['pagination_next_page'], $base_url, 'paged')
                : '';
            $pagination['pagination_pages'] = CMS_Template_Compiler_V2::build_pagination_pages(
                $current_page,
                $total_pages,
                $base_url,
                'paged',
                2,
                1
            );
            CMS_Template_Compiler_V2::$last_loop_pagination = $pagination;
        } elseif ($limit > 0) {
            $archives = array_slice($archives, 0, $limit);
        }

        $output = '';
        $index = 0;
        foreach ($archives as $archive) {
            $index++;
            $item_data = array_merge($data, [
                'archive_url' => $archive['url'] ?? '',
                'archive_title' => $archive['title'] ?? '',
                'archive_count' => $archive['count'] ?? 0,
                'archive_year' => $archive['year'] ?? '',
                'archive_month' => $archive['month'] ?? '',

                'archives_url' => $archive['url'] ?? '',
                'archives_title' => $archive['title'] ?? '',

                'url' => $archive['url'] ?? '',
                'label' => $archive['title'] ?? '',
                'count' => $archive['count'] ?? 0,

                'year' => $archive['year'] ?? '',
                'month' => $archive['month'] ?? '',
                'articles_count' => $archive['count'] ?? 0,
                'index' => $index,
            ]);

            // parser instance
            $output .= CMS_Template_Compiler_V2::render_string($content, $item_data);
        }

        return $output;
    }

    /**
     * 解析链接循环标签
     */
    public function parse_links($attrs, $content, $data)
    {
        // 分类信息模式：当使用 id=XX 时获取链接栏目元数据
        if (isset($attrs['id'])) {
            return $this->render_category_info('links', $attrs, $content, $data);
        }

        $query_args = array(
            'status' => 1,
            'orderby' => 'sort_order',
            'order' => 'ASC'
        );

        // 支持 group 参数按分类筛选
        if (isset($attrs['group']) && !empty($attrs['group'])) {
            $query_args['category'] = $attrs['group'];
        }

        if (isset($attrs['limit'])) {
            $query_args['limit'] = intval($attrs['limit']);
        }

        $links = todo_get_links($query_args);

        if (empty($links)) {
            return '';
        }

        $output = '';
        $index = 0;
        foreach ($links as $link) {
            $index++;
            $name = isset($link->name) ? $link->name : '';
            $url = isset($link->url) ? $link->url : '';
            $description = isset($link->description) ? $link->description : '';
            $target = isset($link->target) ? $link->target : '_self';
            $image = isset($link->logo) ? $link->logo : '';

            // 多语言内联与字典翻译自动支持
            if (function_exists('todo_parse_inline_translations')) {
                $name = todo_parse_inline_translations($name);
                $description = todo_parse_inline_translations($description);
            }
            if (function_exists('todo_translate')) {
                $name = todo_translate($name);
                $description = todo_translate($description);
            }

            // 规范化图片 URL：优先从云存储解析，若非云存储则自动匹配本地/自建 CDN
            if ($image !== '' && function_exists('todo_media_url_to_runtime_absolute')) {
                $image = todo_media_url_to_runtime_absolute($image);
            }

            $item_data = array_merge($data, [
                'url'         => $url,
                'name'        => $name,
                'description' => $description,
                'target'      => $target,
                'image'       => $image,
                'index'       => $index,
            ]);

            // parser instance
            $output .= CMS_Template_Compiler_V2::render_string($content, $item_data);
        }

        return $output;
    }

    /**
     * 解析描述标签
     */
    public function parse_description($attrs, $content, $data)
    {
        if (isset($data['description'])) {
            return esc_html($data['description']);
        }

        global $post;
        if ($post && isset($post->post_excerpt)) {
            return esc_html($post->post_excerpt);
        }

        return '';
    }

    /**
     * 解析图片标签
     */
    public function parse_image($attrs, $content, $data)
    {
        if (isset($data['image'])) {
            $src = (string) $data['image'];
            $as_tag = false;
            if (isset($attrs['tag'])) {
                $as_tag = ($attrs['tag'] === true || $attrs['tag'] === 1 || $attrs['tag'] === '1' || $attrs['tag'] === 'true');
            }
            if ($as_tag) {
                $alt = '';
                if (isset($attrs['alt'])) {
                    $alt = (string) $attrs['alt'];
                } elseif (isset($data['alt'])) {
                    $alt = (string) $data['alt'];
                }
                return '<img src="' . esc_url($src) . '" alt="' . esc_attr($alt) . '">';
            }
            return esc_url($src);
        }

        return '';
    }

    /**
     * 解析统计代码标签
     */
    public function parse_analytics_code($attrs, $content, $data)
    {
        $analytics_code = get_option('site_analytics', '');
        if (empty($analytics_code)) {
            return '';
        }
        return $analytics_code;
    }

    /**
     * 解析todo_footer标签（新方案）
     * 触发页脚钩子并输出已排队的页脚脚本
     */
    public function parse_todo_footer($attrs, $content, $data)
    {
        ob_start();

        // 触发页脚钩子（供模块输出额外内容）
        if (function_exists('do_action')) {
            do_action('todo_footer');
        }

        // 输出已排队的页脚脚本
        if (function_exists('todo_print_scripts')) {
            todo_print_scripts(true);
        }

        // 新模板方案不会经过 todo_footer_scripts()，这里补上异步缓存检测脚本输出。
        if (function_exists('todo_print_async_cache_check_script')) {
            todo_print_async_cache_check_script();
        }

        // 自动输出全站图片 ThumbHash 异步加载与平滑过渡脚本
        echo '<script>
(function() {
    function initLazyLoad() {
        var lazyImages = [].slice.call(document.querySelectorAll("img.lazy-load[data-src], img.thumbhash-lazy[data-src]"));
        if (!lazyImages.length) return;

        if ("IntersectionObserver" in window) {
            var imageObserver = new IntersectionObserver(function(entries, observer) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        var image = entry.target;
                        var realSrc = image.getAttribute("data-src");
                        if (realSrc && realSrc !== "undefined" && !image.classList.contains("loading-started")) {
                            image.classList.add("loading-started");
                            var imgLoader = new Image();
                            imgLoader.src = realSrc;
                            imgLoader.onload = function() {
                                image.src = realSrc;
                                image.classList.add("loaded");
                                image.dispatchEvent(new CustomEvent("thumbhash-loaded", { bubbles: true, detail: { src: realSrc } }));
                            };
                            imgLoader.onerror = function() {
                                image.src = realSrc;
                                image.classList.add("loaded");
                            };
                        }
                        imageObserver.unobserve(image);
                    }
                });
            }, { rootMargin: "50px 0px" });
            lazyImages.forEach(function(image) {
                imageObserver.observe(image);
            });
        } else {
            lazyImages.forEach(function(image) {
                var realSrc = image.getAttribute("data-src");
                if (realSrc) {
                    image.src = realSrc;
                    image.classList.add("loaded");
                }
            });
        }
    }
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initLazyLoad);
    } else {
        initLazyLoad();
    }
})();
</script>
<style>
img.lazy-load[data-src], img.thumbhash-lazy[data-src] {
    transition: all 0.3s ease;
    opacity: 0.8;
    will-change: opacity;
}
img.lazy-load.loading-started, img.thumbhash-lazy.loading-started {
    min-width: 100%;
}
img.lazy-load.loaded, img.thumbhash-lazy.loaded {
    min-width: auto;
    opacity: 1;
}
</style>';

        $out = ob_get_clean();
        $indent = '    ';
        if (is_array($attrs)) {
            if (isset($attrs['indent'])) {
                $v = $attrs['indent'];
                if (is_numeric($v)) {
                    $indent = str_repeat(' ', max(0, (int) $v));
                } elseif (is_string($v)) {
                    $indent = $v;
                }
            }
        }
        return $this->indent_multiline_output($out, $indent);
    }

    // ==================== 用户相关标签 ====================

    /**
     * 解析用户页面URL标签
     */
    public function parse_user_page_url($attrs, $content, $data)
    {
        $page = isset($attrs['page']) ? $attrs['page'] : 'login';

        // 基础URL（优先使用 todo_get_user_center_url，保持统一规范）
        if (function_exists('todo_get_user_center_url')) {
            $url = rtrim(todo_get_user_center_url($page), '/');
        } elseif (function_exists('todo_get_user_page_url')) {
            $url = rtrim(todo_get_user_page_url($page), '/');
        } else {
            $url = rtrim(home_url('/users/' . $page), '/');
        }

        // 语言前缀处理
        if (function_exists('get_current_language') && function_exists('get_language_url')) {
            $current_lang = get_current_language();
            if (!empty($current_lang)) {
                $url = get_language_url($current_lang, $url);
            }
        }

        return $url;
    }

    /**
     * 解析验证码URL标签
     */
    public function parse_captcha_url($attrs, $content, $data)
    {
        $type = isset($attrs['type']) ? $attrs['type'] : 'default';
        $timestamp = time();
        $path = 'api/captcha/generate.php?type=' . rawurlencode($type) . '&t=' . $timestamp;
        return function_exists('todo_get_site_url') ? todo_get_site_url($path) : (function_exists('home_url') ? home_url('/' . $path) : '/' . $path);
    }

    /**
     * 解析请求参数标签
     */
    public function parse_request_param($attrs, $content, $data)
    {
        // 声明全局变量以消除Linter误报
        // 显式声明全局变量，避免 linter 误报
        if (!isset($_POST)) {
            $_POST = [];
        }
        if (!isset($_GET)) {
            $_GET = [];
        }

        $name = isset($attrs['name']) ? $attrs['name'] : '';
        if (empty($name)) {
            return '';
        }

        // 优先从POST获取，然后从GET获取
        if (isset($_POST[$name])) {
            return esc_html($_POST[$name]);
        } elseif (isset($_GET[$name])) {
            return esc_html($_GET[$name]);
        }

        return '';
    }

    /**
     * 解析第三方登录URL标签
     */
    public function parse_third_party_login_url($attrs, $content, $data)
    {
        $provider = isset($attrs['provider']) ? $attrs['provider'] : '';
        if (empty($provider)) {
            return '';
        }

        $base = function_exists('todo_home_url') ? todo_home_url('/users/oauth/' . $provider) : home_url('/users/oauth/' . $provider);
        $locale = function_exists('current_locale') ? current_locale() : '';
        if (function_exists('get_language_url') && !empty($locale)) {
            return get_language_url($locale, $base);
        }
        return $base;
    }

    // ==================== 条件判断标签 ====================

    /**
     * 解析验证码启用条件
     */
    public function parse_captcha_enabled($attrs, $content, $data)
    {
        if (isset($data['captcha_enabled'])) {
            return !empty($data['captcha_enabled']);
        }
        return (bool) (get_option('comment_captcha_enabled', 0) || get_option('captcha_enabled', false));
    }

    /**
     * 解析第三方登录启用条件
     */
    public function parse_third_party_login_enabled($attrs, $content, $data)
    {
        return get_option('third_party_login_enabled', false) ? 'true' : 'false';
    }

    /**
     * 解析QQ登录启用条件
     */
    public function parse_qq_login_enabled($attrs, $content, $data)
    {
        return get_option('qq_login_enabled', false) ? 'true' : 'false';
    }

    /**
     * 解析微信登录启用条件
     */
    public function parse_wechat_login_enabled($attrs, $content, $data)
    {
        return get_option('wechat_login_enabled', false) ? 'true' : 'false';
    }

    /**
     * 解析微博登录启用条件
     */
    public function parse_weibo_login_enabled($attrs, $content, $data)
    {
        return get_option('weibo_login_enabled', false) ? 'true' : 'false';
    }

    /**
     * 解析CSRF令牌标签
     */
    public function parse_csrf_token($attrs, $content, $data)
    {
        // 从属性获取动作名称，兼容 name 或 action 属性
        $action = isset($attrs['name']) ? $attrs['name'] : (isset($attrs['action']) ? $attrs['action'] : 'default');

        // 优先使用系统提供的令牌生成函数
        if (function_exists('todo_generate_csrf_token')) {
            return todo_generate_csrf_token($action);
        }

        // 回退：在会话中为不同动作维持独立令牌
        if (!session_id()) {
            @session_start();
        }
        if (!isset($_SESSION['_csrf_tokens'])) {
            $_SESSION['_csrf_tokens'] = [];
        }
        if (!isset($_SESSION['_csrf_tokens'][$action])) {
            $_SESSION['_csrf_tokens'][$action] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_tokens'][$action];
    }

    /**
     * 解析CSRF隐藏字段标签
     * 用于在表单中输出<input type="hidden" ...>，默认字段名为 _todononce
     */
    public function parse_csrf_field($attrs, $content, $data)
    {
        // 获取动作名称与字段名
        $action = isset($attrs['name']) ? $attrs['name'] : (isset($attrs['action']) ? $attrs['action'] : 'default');
        $field_name = isset($attrs['field']) ? $attrs['field'] : '_todononce';

        // 优先使用统一的字段生成函数
        if (function_exists('todo_csrf_field')) {
            return todo_csrf_field($action, $field_name);
        }
        if (class_exists('Todo_Security_Manager')) {
            return Todo_Security_Manager::csrf_field($action, $field_name);
        }

        // 回退：使用令牌生成函数并输出隐藏字段
        $token = $this->parse_csrf_token(['name' => $action], '', $data);
        $fn_esc = function_exists('esc_attr') ? 'esc_attr' : null;
        $name_attr = $fn_esc ? esc_attr($field_name) : $field_name;
        $value_attr = $fn_esc ? esc_attr($token) : $token;

        return '<input type="hidden" name="' . $name_attr . '" value="' . $value_attr . '" />';
    }

    /**
     * 解析语言包 JSON 导出标签
     * 用于前端 JS 自动多语言：{{todo:lang_pack_json}}
     */
    public function parse_lang_pack_json($attrs = [], $content = '', $data = [])
    {
        $domain = isset($attrs['domain']) ? $attrs['domain'] : 'default';
        $pack = class_exists('CMS_Translation') ? CMS_Translation::get_all_translations($domain) : [];
        return json_encode($pack, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 解析是否存在自定义Logo的条件标签
     * 用于 {{todo:if has_custom_logo}} ... {{/todo:if}}
     */
    public function parse_has_custom_logo($attrs, $content, $data)
    {
        $logo_url = function_exists('todo_get_site_logo_url') ? todo_get_site_logo_url() : '';
        return !empty($logo_url) ? '1' : '';
    }

    /**
     * 解析输出自定义Logo的标签
     * 用于 {{todo:the_custom_logo}}
     */
    public function parse_the_custom_logo($attrs, $content, $data)
    {
        $logo_url = function_exists('todo_get_site_logo_url') ? todo_get_site_logo_url() : '';

        if ($logo_url === '') {
            return '';
        }

        if (function_exists('todo_media_url_to_runtime_absolute')) {
            $logo_url = todo_media_url_to_runtime_absolute($logo_url);
        }

        $base = function_exists('todo_home_url') ? todo_home_url('/') : (function_exists('home_url') ? home_url('/') : '/');
        $locale = function_exists('current_locale') ? current_locale() : '';
        $home = (function_exists('get_language_url') && !empty($locale)) ? get_language_url($locale, $base) : $base;

        $alt = function_exists('todo_get_option_ml') ? todo_get_option_ml('site_title', (function_exists('todo_get_option') ? todo_get_option('site_title', 'Home') : 'Home')) : (function_exists('todo_get_option') ? todo_get_option('site_title', 'Home') : 'Home');
        if (function_exists('todo_parse_inline_translations')) {
            $alt = todo_parse_inline_translations((string) $alt, $locale ?: null);
        }

        $home_esc = function_exists('esc_url') ? esc_url($home) : $home;
        $logo_esc = function_exists('esc_url') ? esc_url($logo_url) : $logo_url;
        $alt_esc = function_exists('esc_attr') ? esc_attr($alt) : $alt;

        return '<a href="' . $home_esc . '" class="custom-logo-link" rel="home">'
            . '<img src="' . $logo_esc . '" class="custom-logo" alt="' . $alt_esc . '"></a>';
    }

    // ==================== 产品相关标签 ====================

    /**
     * 解析货币代码（作为全站价格前缀通用标签）
     * 遵循后台语言货币设置中的“符号特征”：
     *   - B2C 模式：输出符号
     *   - B2B 模式：输出代码
     * 亦可通过属性指定显式格式：format="iso" 或 format="symbol"
     */
    public function parse_currency_code($attrs, $content, $data) {
        if (function_exists('todo_get_currency_config')) {
            $currency_config = todo_get_currency_config();
            if (isset($attrs['format'])) {
                if ($attrs['format'] === 'iso') {
                    return $this->parse_currency_iso($attrs, $content, $data);
                }
                if ($attrs['format'] === 'symbol') {
                    return $this->parse_currency_symbol($attrs, $content, $data);
                }
            }
            if (!empty($currency_config['display_symbol'])) {
                return $currency_config['display_symbol'];
            }
            return !empty($currency_config['symbol']) ? $currency_config['symbol'] : '$';
        }
        return '$';
    }

    /**
     * 解析货币符号（强制返回符号如 $、¥、€）
     */
    public function parse_currency_symbol($attrs, $content, $data) {
        if (function_exists('todo_get_currency_config')) {
            $currency_config = todo_get_currency_config();
            if (!empty($currency_config['symbol'])) {
                return $currency_config['symbol'];
            }
            if (!empty($currency_config['code']) && function_exists('todo_get_currency_symbol')) {
                return todo_get_currency_symbol($currency_config['code']);
            }
        }
        return '$';
    }

    /**
     * 解析 ISO 4217 纯三位代码（专供 Schema.org、Google Ads、GMC 数据源使用，不受 B2C/B2B 视觉影响）
     */
    public function parse_currency_iso($attrs, $content, $data) {
        if (function_exists('todo_get_currency_config')) {
            $currency_config = todo_get_currency_config();
            if (!empty($currency_config['code'])) {
                return strtoupper($currency_config['code']);
            }
            if (!empty($currency_config['symbol']) && function_exists('todo_get_currencies_list')) {
                foreach (todo_get_currencies_list() as $c_code => $c_info) {
                    if (isset($c_info['symbol']) && $c_info['symbol'] === $currency_config['symbol']) {
                        return $c_code;
                    }
                }
            }
        }
        return 'USD';
    }

    /**
     * 解析产品真实平均评分（用于 Schema.org aggregateRating 杜绝虚假评分作弊处罚）
     */
    public function parse_post_average_rating($attrs, $content, $data) {
        global $post;
        if (!$post) return '0';
        if (function_exists('get_product_average_rating')) {
            $rating = get_product_average_rating($post->ID);
            return $rating > 0 ? (string)$rating : '0';
        }
        return '0';
    }

    /**
     * 解析当前语言环境相对基准货币的汇率比值
     */
    public function parse_currency_rate($attrs, $content, $data) {
        if (function_exists('todo_get_currency_config') && function_exists('todo_get_default_currency_config')) {
            $config = todo_get_currency_config();
            $default_config = todo_get_default_currency_config();
            $current_rate = ($config['status'] == 1 && $config['rate'] > 0) ? $config['rate'] : 1.0;
            $base_rate = ($default_config['rate'] > 0) ? $default_config['rate'] : 1.0;
            return (string) round($current_rate / $base_rate, 4);
        }
        return '1.0';
    }

    /**
     * 解析 Schema.org 价格有效期（当前时间顺延动态生成，杜绝跨年过期报错）
     */
    public function parse_price_valid_until($attrs, $content, $data) {
        $days = isset($attrs['days']) ? (int)$attrs['days'] : 365;
        return date('Y-m-d', time() + ($days * 86400));
    }

    /**
     * 解析产品真实SKU标识（优先提取 post_meta 中的 _sku/sku，回退至产品ID）
     */
    public function parse_post_sku($attrs, $content, $data) {
        global $post;
        if (!$post) return '';
        $id = $post->ID ?? ($post->id ?? 0);
        if ($id && function_exists('get_post_meta')) {
            $sku = get_post_meta($id, '_sku', true);
            if (empty($sku)) {
                $sku = get_post_meta($id, 'sku', true);
            }
            if (!empty($sku) && is_string($sku)) {
                return esc_html(trim($sku));
            }
        }
        if (isset($post->sku) && !empty($post->sku)) {
            return esc_html(trim($post->sku));
        }
        return (string)$id;
    }

    /**
     * 多规格产品：取默认选中（每组第一个值）对应 variant 的指定字段值
     * @param object $product_data 产品模型行
     * @param string|array $fields 字段名或字段名数组（按优先级依次尝试）
     */
    private static function get_default_variant_field($product_data, $fields)
    {
        if (!$product_data) return null;
        
        $spec_details_raw = !empty($product_data->spec_details) ? $product_data->spec_details : '';
        $spec_config_raw   = !empty($product_data->spec_config) ? $product_data->spec_config : '';
        
        if ($spec_details_raw === '' || $spec_config_raw === '') return null;
        
        $details = json_decode($spec_details_raw, true);
        $config  = json_decode($spec_config_raw, true);
        
        if (!is_array($details) || !is_array($config)) return null;
        
        $defaults = [];
        $v_keys = ['v0', 'v1', 'v2'];
        foreach ($config as $idx => $group) {
            if ($idx >= 3) break;
            if (isset($group['values']) && is_array($group['values']) && !empty($group['values'])) {
                $defaults[$v_keys[$idx]] = (string)$group['values'][0];
            }
        }
        
        if (empty($defaults)) return null;
        
        $fields_arr = (array) $fields;
        
        foreach ($details as $variant) {
            if (!is_array($variant)) continue;
            $match = true;
            foreach ($defaults as $k => $v) {
                if (!isset($variant[$k]) || (string)$variant[$k] !== $v) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                foreach ($fields_arr as $f) {
                    if (isset($variant[$f]) && $variant[$f] !== '') {
                        return $variant[$f];
                    }
                }
                break;
            }
        }
        
        return null;
    }

    public function parse_post_current_price($attrs, $content, $data)
    {
        global $post, $tododb;

        if (!$post) {
            return '';
        }

        $price = '';
        $product_data = null;

        // 优化：尝试从字段管理器缓存获取 (batch_preload_post_fields 可能已预加载)
        if (class_exists('Todo_Post_Fields_Manager')) {
            $fields = Todo_Post_Fields_Manager::get_instance()->get_post_fields_values($post->ID);
            // 注意：batch_preload_post_fields 会将 model 字段注入到缓存中
            if (isset($fields['price'])) {
                $price = $fields['price'];
            }
        }

        // 如果缓存未命中或未包含price，回退到数据库查询
        if ($price === '') {
            $product_data = self::get_product_model_row($post->ID);
            if ($product_data && isset($product_data->price)) {
                $price = $product_data->price;
            }
        }

        if ($price === '') {
            return '';
        }

        // 检查是否有多规格：优先取规格组合中的最低有库存价格
        if ($product_data === null) {
            $product_data = self::get_product_model_row($post->ID);
        }
        $spec_type = ($product_data && isset($product_data->spec_type)) ? $product_data->spec_type : 'single';
        if ($spec_type === 'multiple' && $product_data && !empty($product_data->spec_details)) {
            $details = json_decode($product_data->spec_details, true);
            if (!is_array($details)) $details = maybe_unserialize($product_data->spec_details);
            if (is_array($details) && !empty($details)) {
                $best_price = PHP_FLOAT_MAX;
                foreach ($details as $d) {
                    $p = isset($d['users_price']) ? floatval($d['users_price']) : (isset($d['price']) ? floatval($d['price']) : 0);
                    $s = isset($d['stock_count']) ? intval($d['stock_count']) : (isset($d['stock']) ? intval($d['stock']) : 0);
                    if ($p > 0 && $s > 0 && $p < $best_price) {
                        $best_price = $p;
                    }
                }
                if ($best_price < PHP_FLOAT_MAX) {
                    $price = $best_price;
                }
            }
        }

        $price = (float) $price;

        $format = isset($attrs['format']) ? $attrs['format'] : 'currency';
        if (function_exists('todo_format_price')) {
            return todo_format_price($price, $format);
        }

        if (function_exists('todo_apply_currency_exchange')) {
            $price = todo_apply_currency_exchange($price);
        }

        $currency_code = '';
        if (function_exists('todo_get_currency_config')) {
            $currency_config = todo_get_currency_config();
            $currency_code = !empty($currency_config['display_symbol']) ? $currency_config['display_symbol'] : (!empty($currency_config['symbol']) ? $currency_config['symbol'] : '');
        }

        switch ($format) {
            case 'currency':
                return $currency_code . number_format($price, 2);
            case 'number':
                return number_format((float)$price, 2, '.', '');
            case 'raw':
                return (string) $price;
            default:
                return $currency_code . number_format($price, 2);
        }
    }

    /**
     * 解析产品原价标签
     */
    public function parse_post_original_price($attrs, $content, $data)
    {
        global $post, $tododb;

        if (!$post) {
            return '';
        }

        $original_price = '';
        $product_data = null;

        // 优化：尝试从字段管理器缓存获取
        if (class_exists('Todo_Post_Fields_Manager')) {
            $fields = Todo_Post_Fields_Manager::get_instance()->get_post_fields_values($post->ID);
            if (isset($fields['original_price'])) {
                $original_price = $fields['original_price'];
            }
        }

        // 回退查询（走静态/持久化行缓存）
        if ($original_price === '') {
            $product_data = self::get_product_model_row($post->ID);
            if ($product_data && isset($product_data->original_price)) {
                $original_price = $product_data->original_price;
            }
        }

        // 检查是否有多规格：优先取规格组合中最低有库存价格的 crossed_price
        if ($product_data === null) {
            $product_data = self::get_product_model_row($post->ID);
        }
        $spec_type = ($product_data && isset($product_data->spec_type)) ? $product_data->spec_type : 'single';
        if ($spec_type === 'multiple' && $product_data && !empty($product_data->spec_details)) {
            $details = json_decode($product_data->spec_details, true);
            if (!is_array($details)) $details = maybe_unserialize($product_data->spec_details);
            if (is_array($details) && !empty($details)) {
                // 取最低有库存规格的 crossed_price
                $best_price = PHP_FLOAT_MAX;
                $best_crossed = '';
                foreach ($details as $d) {
                    $p = isset($d['users_price']) ? floatval($d['users_price']) : (isset($d['price']) ? floatval($d['price']) : 0);
                    $s = isset($d['stock_count']) ? intval($d['stock_count']) : (isset($d['stock']) ? intval($d['stock']) : 0);
                    if ($p > 0 && $s > 0 && $p < $best_price) {
                        $best_price = $p;
                        $best_crossed = isset($d['crossed_price']) ? (string)$d['crossed_price'] : '';
                    }
                }
                if ($best_crossed !== '') {
                    $original_price = $best_crossed;
                } else {
                    // 多规格时最优库存项没有 crossed_price，清空原价（不回退到产品级 original_price）
                    $original_price = '';
                }
            }
        }

        if ($original_price === '') {
            return '';
        }

        $original_price = (float) $original_price;

        $format = isset($attrs['format']) ? $attrs['format'] : 'currency';
        if (function_exists('todo_format_price')) {
            return todo_format_price($original_price, $format);
        }

        if (function_exists('todo_apply_currency_exchange')) {
            $original_price = todo_apply_currency_exchange($original_price);
        }

        // 格式化价格显示
        $currency_code = '';
        if (function_exists('todo_get_currency_config')) {
            $currency_config = todo_get_currency_config();
            $currency_code = !empty($currency_config['display_symbol']) ? $currency_config['display_symbol'] : (!empty($currency_config['symbol']) ? $currency_config['symbol'] : '');
        }

        switch ($format) {
            case 'currency':
                return $currency_code . number_format($original_price, 2);
            case 'number':
                return number_format($original_price, 2);
            case 'raw':
                return (string) $original_price;
            default:
                return $currency_code . number_format($original_price, 2);
        }
    }

    /**
     * 解析通用格式化价格标签 {{todo:format_price price="100"}}
     */
    public function parse_format_price($attrs, $content, $data)
    {
        $price = isset($attrs['price']) ? $attrs['price'] : (isset($attrs['val']) ? $attrs['val'] : 0);
        $format = isset($attrs['format']) ? $attrs['format'] : 'currency';

        if (function_exists('todo_format_price')) {
            return todo_format_price($price, $format);
        }

        $price = (float)$price;
        if (function_exists('todo_apply_currency_exchange')) {
            $price = todo_apply_currency_exchange($price);
        }
        $currency_code = '';
        if (function_exists('todo_get_currency_config')) {
            $cfg = todo_get_currency_config();
            if (!empty($cfg['display_symbol'])) {
                $currency_code = $cfg['display_symbol'];
            } elseif (!empty($cfg['symbol'])) {
                $currency_code = $cfg['symbol'];
            }
        }
        if ($format === 'number') {
            return number_format($price, 2);
        }
        if ($format === 'raw') {
            return (string)$price;
        }
        return $currency_code . number_format($price, 2);
    }


    /**
     * 解析产品库存标签
     */
    public function parse_post_stock($attrs, $content, $data)
    {
        global $post, $tododb;

        if (!$post) {
            return '';
        }

        $stock = null;

        // 优化：尝试从字段管理器缓存获取
        if (class_exists('Todo_Post_Fields_Manager')) {
            $fields = Todo_Post_Fields_Manager::get_instance()->get_post_fields_values($post->ID);
            if (isset($fields['stock'])) {
                $stock = $fields['stock'];
            }
        }

        if ($stock === null) {
            $product_data = self::get_product_model_row($post->ID);
            if ($product_data && isset($product_data->stock)) {
                $stock = $product_data->stock;
            }
        }

        if ($stock === null) {
            return '0';
        }

        return (string) intval($stock);
    }

    /**
     * 解析产品规格类型标签
     */
    public function parse_post_spec_type($attrs, $content, $data)
    {
        global $post, $tododb;

        if (!$post) {
            return 'single';
        }

        // 从产品模型字段表获取规格类型数据（走静态/持久化行缓存）
        $product_data = self::get_product_model_row($post->ID);

        if (!$product_data) {
            return 'single';
        }

        return (isset($product_data->spec_type) && $product_data->spec_type === 'multiple') ? 'multiple' : 'single';
    }

    /**
     * 解析产品规格配置标签（返回 JSON）
     */
    public function parse_post_specs($attrs, $content, $data)
    {
        global $post, $tododb;
        if (!$post) return '[]';
        $product_data = self::get_product_model_row($post->ID);
        if (!$product_data || empty($product_data->spec_config)) return '[]';
        if (isset($product_data->spec_type) && $product_data->spec_type !== 'multiple') return '[]';
        $decoded = json_decode($product_data->spec_config, true);
        if (!is_array($decoded)) $decoded = maybe_unserialize($product_data->spec_config);
        if (is_array($decoded)) {
            foreach ($decoded as &$group) {
                if (is_array($group)) {
                    foreach (['title', 'name'] as $title_key) {
                        if (isset($group[$title_key]) && is_string($group[$title_key])) {
                            if (function_exists('todo_parse_inline_translations')) {
                                $group[$title_key] = todo_parse_inline_translations($group[$title_key]);
                            }
                            if (function_exists('todo_translate')) {
                                $group[$title_key] = todo_translate($group[$title_key]);
                            }
                        }
                    }
                    if (!empty($group['values']) && is_array($group['values'])) {
                        foreach ($group['values'] as $vk => $raw_val_str) {
                            if (is_string($raw_val_str) && $raw_val_str !== '') {
                                $val_str = $raw_val_str;
                                if (function_exists('todo_parse_inline_translations')) {
                                    $val_str = todo_parse_inline_translations($val_str);
                                }
                                if (function_exists('todo_translate')) {
                                    $val_str = todo_translate($val_str);
                                }
                                $group['values'][$vk] = $val_str;

                                // 映射多语言/原始值对应的 colors/image_urls key
                                $raw_key   = function_exists('todo_spec_stable_key') ? todo_spec_stable_key([$raw_val_str]) : '';
                                $trans_key = function_exists('todo_spec_stable_key') ? todo_spec_stable_key([$val_str]) : '';

                                if (!empty($group['colors']) && is_array($group['colors'])) {
                                    if ($raw_key && $trans_key && isset($group['colors'][$raw_key]) && !isset($group['colors'][$trans_key])) {
                                        $group['colors'][$trans_key] = $group['colors'][$raw_key];
                                    }
                                    if (isset($group['colors'][$raw_val_str]) && !isset($group['colors'][$val_str])) {
                                        $group['colors'][$val_str] = $group['colors'][$raw_val_str];
                                    }
                                }

                                if (!empty($group['image_urls']) && is_array($group['image_urls'])) {
                                    if ($raw_key && $trans_key && isset($group['image_urls'][$raw_key]) && !isset($group['image_urls'][$trans_key])) {
                                        $group['image_urls'][$trans_key] = $group['image_urls'][$raw_key];
                                    }
                                    if (isset($group['image_urls'][$raw_val_str]) && !isset($group['image_urls'][$val_str])) {
                                        $group['image_urls'][$val_str] = $group['image_urls'][$raw_val_str];
                                    }
                                }

                                if (!empty($group['image_sm_urls']) && is_array($group['image_sm_urls'])) {
                                    if ($raw_key && $trans_key && isset($group['image_sm_urls'][$raw_key]) && !isset($group['image_sm_urls'][$trans_key])) {
                                        $group['image_sm_urls'][$trans_key] = $group['image_sm_urls'][$raw_key];
                                    }
                                    if (isset($group['image_sm_urls'][$raw_val_str]) && !isset($group['image_sm_urls'][$val_str])) {
                                        $group['image_sm_urls'][$val_str] = $group['image_sm_urls'][$raw_val_str];
                                    }
                                }

                                if (!empty($group['images_sm']) && is_array($group['images_sm'])) {
                                    if ($raw_key && $trans_key && isset($group['images_sm'][$raw_key]) && !isset($group['images_sm'][$trans_key])) {
                                        $group['images_sm'][$trans_key] = $group['images_sm'][$raw_key];
                                    }
                                    if (isset($group['images_sm'][$raw_val_str]) && !isset($group['images_sm'][$val_str])) {
                                        $group['images_sm'][$val_str] = $group['images_sm'][$raw_val_str];
                                    }
                                }

                                if (!empty($group['images']) && is_array($group['images'])) {
                                    if ($raw_key && $trans_key && isset($group['images'][$raw_key]) && !isset($group['images'][$trans_key])) {
                                        $group['images'][$trans_key] = $group['images'][$raw_key];
                                    }
                                    if (isset($group['images'][$raw_val_str]) && !isset($group['images'][$val_str])) {
                                        $group['images'][$val_str] = $group['images'][$raw_val_str];
                                    }
                                }
                            }
                        }
                    }
                    if (!empty($group['image_urls']) && is_array($group['image_urls']) && function_exists('todo_media_url_to_runtime_absolute')) {
                        foreach ($group['image_urls'] as $k => $u) {
                            if (is_string($u) && $u !== '') {
                                $group['image_urls'][$k] = todo_media_url_to_runtime_absolute($u);
                            }
                        }
                    }
                    if (!empty($group['image_sm_urls']) && is_array($group['image_sm_urls']) && function_exists('todo_media_url_to_runtime_absolute')) {
                        foreach ($group['image_sm_urls'] as $k => $u) {
                            if (is_string($u) && $u !== '') {
                                $group['image_sm_urls'][$k] = todo_media_url_to_runtime_absolute($u);
                            }
                        }
                    }
                }
            }
            unset($group);
        }
        return is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_UNICODE) : '[]';
    }

    /**
     * 解析产品规格明细标签（返回 JSON）
     */
    public function parse_post_spec_details($attrs, $content, $data)
    {
        global $post, $tododb;
        if (!$post) return '[]';
        $product_data = self::get_product_model_row($post->ID);
        if (!$product_data || empty($product_data->spec_details)) return '[]';
        if (isset($product_data->spec_type) && $product_data->spec_type !== 'multiple') return '[]';
        $decoded = json_decode($product_data->spec_details, true);
        if (!is_array($decoded)) $decoded = maybe_unserialize($product_data->spec_details);
        if (is_array($decoded)) {
            foreach ($decoded as &$detail) {
                if (is_array($detail)) {
                    foreach (['v0', 'v1', 'v2', 'spec_value', 'value'] as $vk) {
                        if (isset($detail[$vk]) && is_string($detail[$vk]) && $detail[$vk] !== '') {
                            if (function_exists('todo_parse_inline_translations')) {
                                $detail[$vk] = todo_parse_inline_translations($detail[$vk]);
                            }
                            if (function_exists('todo_translate')) {
                                $detail[$vk] = todo_translate($detail[$vk]);
                            }
                        }
                    }
                    if (function_exists('todo_media_url_to_runtime_absolute')) {
                        foreach (['image', 'image_url', 'url'] as $img_key) {
                            if (!empty($detail[$img_key]) && is_string($detail[$img_key])) {
                                $detail[$img_key] = todo_media_url_to_runtime_absolute($detail[$img_key]);
                            }
                        }
                    }
                    if (function_exists('todo_apply_currency_exchange')) {
                        foreach (['users_price', 'price', 'crossed_price', 'original_price', 'wholesale_price', 'wholesale_vip_price'] as $pk) {
                            if (isset($detail[$pk]) && is_numeric($detail[$pk])) {
                                $detail[$pk] = todo_apply_currency_exchange((float)$detail[$pk]);
                            }
                        }
                    }
                }
            }
            unset($detail);
        }
        return is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_UNICODE) : '[]';
    }

    /**
     * 解析产品自定义参数标签（返回 JSON）
     */
    public function parse_post_custom_params($attrs, $content, $data)
    {
        global $post, $tododb;
        if (!$post) return '[]';
        $product_data = self::get_product_model_row($post->ID);
        if (!$product_data || empty($product_data->custom_params)) return '[]';
        $decoded = json_decode($product_data->custom_params, true);
        if (!is_array($decoded)) $decoded = maybe_unserialize($product_data->custom_params);
        if (is_array($decoded)) {
            foreach ($decoded as &$param) {
                if (is_array($param)) {
                    foreach (['name', 'param_name', 'value', 'param_value'] as $pk) {
                        if (isset($param[$pk]) && is_string($param[$pk]) && $param[$pk] !== '') {
                            if (function_exists('todo_parse_inline_translations')) {
                                $param[$pk] = todo_parse_inline_translations($param[$pk]);
                            }
                            if (function_exists('todo_translate')) {
                                $param[$pk] = todo_translate($param[$pk]);
                            }
                        }
                    }
                }
            }
            unset($param);
        }
        if (isset($attrs['format']) && $attrs['format'] === 'html') {
            return $this->parse_post_custom_params_html($attrs, $content, $data);
        }
        return is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_UNICODE) : '[]';
    }

    /**
     * 解析产品自定义参数 HTML 静态直出（用于 Google SEO 爬虫捕获核心参数，杜绝纯客户端渲染被爬虫忽略）
     */
    public function parse_post_custom_params_html($attrs, $content, $data)
    {
        global $post;
        if (!$post) return '';
        $product_data = self::get_product_model_row($post->ID);
        if (!$product_data || empty($product_data->custom_params)) return '';
        $decoded = json_decode($product_data->custom_params, true);
        if (!is_array($decoded)) $decoded = maybe_unserialize($product_data->custom_params);
        if (!is_array($decoded) || empty($decoded)) return '';

        $html = '';
        foreach ($decoded as $idx => $row) {
            if (!is_array($row)) continue;
            $name = isset($row['name']) ? $row['name'] : (isset($row['param_name']) ? $row['param_name'] : '');
            $val  = isset($row['value']) ? $row['value'] : (isset($row['param_value']) ? $row['param_value'] : '');
            if ($name === '' && $val === '') continue;

            if (function_exists('todo_parse_inline_translations')) {
                $name = todo_parse_inline_translations($name);
                $val  = todo_parse_inline_translations($val);
            }
            if (function_exists('todo_translate')) {
                $name = todo_translate($name);
                $val  = todo_translate($val);
            }

            $html .= '<div class="param-item">';
            $html .= '<span class="param-label">' . esc_html($name) . '</span>';
            $html .= '<span class="param-val">' . esc_html($val) . '</span>';
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * 解析产品自定义参数为 Schema.org PropertyValue 数组 JSON (用于 Google SERP & Gemini AI GEO 事实提取)
     */
    public function parse_post_custom_params_schema($attrs, $content, $data)
    {
        global $post;
        if (!$post) return '';
        $product_data = self::get_product_model_row($post->ID);
        if (!$product_data || empty($product_data->custom_params)) return '';
        $decoded = json_decode($product_data->custom_params, true);
        if (!is_array($decoded)) $decoded = maybe_unserialize($product_data->custom_params);
        if (!is_array($decoded) || empty($decoded)) return '';

        $schema_props = [];
        foreach ($decoded as $idx => $row) {
            if (!is_array($row)) continue;
            $name = isset($row['name']) ? $row['name'] : (isset($row['param_name']) ? $row['param_name'] : '');
            $val  = isset($row['value']) ? $row['value'] : (isset($row['param_value']) ? $row['param_value'] : '');
            if ($name === '' && $val === '') continue;

            if (function_exists('todo_parse_inline_translations')) {
                $name = todo_parse_inline_translations($name);
                $val  = todo_parse_inline_translations($val);
            }
            if (function_exists('todo_translate')) {
                $name = todo_translate($name);
                $val  = todo_translate($val);
            }

            $schema_props[] = [
                '@type' => 'PropertyValue',
                'name'  => $name,
                'value' => $val,
            ];
        }

        return !empty($schema_props) ? json_encode($schema_props, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    }

    /**
     * 解析产品单位数量标签
     */
    public function parse_post_unit_qty($attrs, $content, $data)
    {
        global $post, $tododb;
        if (!$post) return '1';
        $product_data = self::get_product_model_row($post->ID);
        return ($product_data && !empty($product_data->unit_qty)) ? (string)$product_data->unit_qty : '1';
    }

    /**
     * 解析产品单位标签
     */
    public function parse_post_unit($attrs, $content, $data)
    {
        global $post, $tododb;
        if (!$post) return 'piece';
        $product_data = self::get_product_model_row($post->ID);
        if (!$product_data || empty($product_data->unit)) return '件';
        $unit_map = [
            'piece' => __('件'), 'item' => __('个'), 'set' => __('套'), 'pair' => __('双'),
            'pack' => __('包'), 'bag' => __('袋'), 'box' => __('盒'), 'bottle' => __('瓶'),
            'can' => __('罐'), 'roll' => __('卷'), 'strip' => __('条'), 'sheet' => __('张'),
            'portion' => __('份'), 'bowl' => __('碗'), 'plate' => __('盘'), 'cup' => __('杯'),
            'bunch' => __('把'), 'head' => __('颗/头'), 'kg' => __('千克'), 'g' => __('克'),
            'jin' => '斤', 'liang' => '两', 'ml' => __('毫升'), 'l' => __('升'),
            'other' => __('其他'),
        ];
        $u = $product_data->unit;
        $u_text = isset($unit_map[$u]) ? $unit_map[$u] : $u;
        if (function_exists('todo_parse_inline_translations')) {
            $u_text = todo_parse_inline_translations($u_text);
        }
        if (function_exists('todo_translate')) {
            $u_text = todo_translate($u_text);
        }
        return $u_text;
    }

    /**
     * 解析产品单位文本（组合单位数量和单位，如 "1件"、"2kg"）
     */
    public function parse_post_unit_text($attrs, $content, $data)
    {
        global $post, $tododb;
        if (!$post) return '件';
        $product_data = self::get_product_model_row($post->ID);
        if (!$product_data) return '件';
        $qty = !empty($product_data->unit_qty) ? (string)$product_data->unit_qty : '1';
        $unit_map = [
            'piece' => __('件'), 'item' => __('个'), 'set' => __('套'), 'pair' => __('双'),
            'pack' => __('包'), 'bag' => __('袋'), 'box' => __('盒'), 'bottle' => __('瓶'),
            'can' => __('罐'), 'roll' => __('卷'), 'strip' => __('条'), 'sheet' => __('张'),
            'portion' => __('份'), 'bowl' => __('碗'), 'plate' => __('盘'), 'cup' => __('杯'),
            'bunch' => __('把'), 'head' => __('颗/头'), 'kg' => __('千克'), 'g' => __('克'),
            'jin' => '斤', 'liang' => '两', 'ml' => __('毫升'), 'l' => __('升'),
            'other' => __('其他'),
        ];
        $u = isset($product_data->unit) ? $product_data->unit : '';
        $u_text = isset($unit_map[$u]) ? $unit_map[$u] : ($u ?: __('件'));
        if (function_exists('todo_parse_inline_translations')) {
            $u_text = todo_parse_inline_translations($u_text);
        }
        if (function_exists('todo_translate')) {
            $u_text = todo_translate($u_text);
        }
        // 当单位数量为 1 或空时，只显示单位
        if ($qty === '1' || $qty === '') return $u_text;
        return $qty . $u_text;
    }

    /**
     * 解析产品营销特色标签
     */
    public function parse_post_marketing_feature($attrs, $content, $data)
    {
        global $post, $tododb;

        if (!$post) {
            return '';
        }

        $marketing_feature = '';

        // 优化：尝试从字段管理器缓存获取
        if (class_exists('Todo_Post_Fields_Manager')) {
            $fields = Todo_Post_Fields_Manager::get_instance()->get_post_fields_values($post->ID);
            if (isset($fields['marketing_feature'])) {
                $marketing_feature = $fields['marketing_feature'];
            }
        }

        // 回退查询（走静态/持久化行缓存）
        if ($marketing_feature === '') {
            $product_data = self::get_product_model_row($post->ID);
            if ($product_data && isset($product_data->marketing_feature)) {
                $marketing_feature = $product_data->marketing_feature;
            }
        }

        // 解析内联多语言语法 [lang|text]
        if (function_exists('todo_parse_inline_translations')) {
            $marketing_feature = todo_parse_inline_translations($marketing_feature);
        }

        return (string) $marketing_feature;
    }

    /**
     * 解析产品服务标签（返回 JSON 数组）
     */
    public function parse_post_product_services($attrs, $content, $data)
    {
        global $post, $tododb;
        if (!$post) return '[]';
        $product_data = self::get_product_model_row($post->ID);
        if (!$product_data || empty($product_data->product_services)) return '[]';
        $decoded = json_decode($product_data->product_services, true);
        if (!is_array($decoded)) $decoded = maybe_unserialize($product_data->product_services);
        return is_array($decoded) ? json_encode(array_values($decoded), JSON_UNESCAPED_UNICODE) : '[]';
    }

    /**
     * 解析产品服务展示标签（返回 JSON，含 code/label/images）
     */
    public function parse_post_product_services_display($attrs, $content, $data)
    {
        global $post, $tododb;
        if (!$post) return '[]';

        // 读取产品服务代码列表
        $product_data = self::get_product_model_row($post->ID);
        $service_codes = [];
        if ($product_data && !empty($product_data->product_services)) {
            $decoded = json_decode($product_data->product_services, true);
            if (!is_array($decoded)) $decoded = maybe_unserialize($product_data->product_services);
            if (is_array($decoded)) {
                $service_codes = array_values(array_filter($decoded, function ($v) {
                    return is_string($v) && $v !== '';
                }));
            }
        }
        if (empty($service_codes)) return '[]';

        // 读取服务设置项（含 images）
        $service_items = [];
        if (function_exists('todo_get_option')) {
            $settings = todo_get_option('todo_service_settings', null);
            if (is_array($settings) && !empty($settings['product_service_items'])) {
                foreach ($settings['product_service_items'] as $it) {
                    if (!is_array($it) || empty($it['code'])) continue;
                    $code = trim($it['code']);
                    $label = isset($it['label']) ? trim($it['label']) : $code;
                    $images = isset($it['images']) && is_array($it['images']) ? array_values($it['images']) : [];
                    if (!empty($images)) {
                        foreach ($images as &$img) {
                            if (is_array($img)) {
                                $raw_url = isset($img['url']) && is_string($img['url']) ? trim($img['url']) : '';
                                if ($raw_url !== '') {
                                    $info = self::get_media_info($raw_url);
                                    if ($info) {
                                        if (empty($img['seo_keywords']) && !empty($info->seo_keywords)) {
                                            $img['seo_keywords'] = $info->seo_keywords;
                                        }
                                        if (empty($img['title']) && !empty($info->media_title)) {
                                            $img['title'] = $info->media_title;
                                        }
                                        if (empty($img['seo_title']) && !empty($info->seo_title)) {
                                            $img['seo_title'] = $info->seo_title;
                                        }
                                    }
                                    if (function_exists('todo_media_url_to_runtime_absolute')) {
                                        $img['url'] = todo_media_url_to_runtime_absolute($raw_url);
                                    }
                                }
                            }
                        }
                        unset($img);
                    }
                    if (function_exists('todo_parse_inline_translations')) {
                        $label = todo_parse_inline_translations($label);
                    }
                    if (function_exists('todo_translate')) {
                        $label = todo_translate($label);
                    }
                    $post_id = isset($it['post_id']) ? intval($it['post_id']) : (isset($it['anywhere_post_id']) ? intval($it['anywhere_post_id']) : 0);
                    $content = '';
                    if ($post_id > 0) {
                        if (function_exists('todo_get_the_content')) {
                            $content = todo_get_the_content('', false, $post_id);
                        } else {
                            $p_obj = function_exists('get_post') ? get_post($post_id) : null;
                            if (is_object($p_obj)) {
                                $content = $p_obj->post_content;
                            }
                        }
                    }
                    $service_items[$code] = [
                        'code' => $code,
                        'label' => $label,
                        'images' => $images,
                        'post_id' => $post_id,
                        'content' => $content,
                    ];
                }
            }
        }

        // 为产品已启用的服务组装数据
        $out = [];
        foreach ($service_codes as $code) {
            if (isset($service_items[$code])) {
                $out[] = $service_items[$code];
            }
        }

        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 解析是否显示库存标签
     */
    public function parse_post_show_stock($attrs, $content, $data)
    {
        global $post, $tododb;
        if (!$post) return '0';
        $product_data = self::get_product_model_row($post->ID);
        if (!$product_data) return '0';
        return !empty($product_data->show_stock) ? '1' : '0';
    }

    /**
     * 解析商品销售状态标签 (online / warehouse)
     */
    public function parse_post_sale_status($attrs, $content, $data)
    {
        global $post;
        if (!$post) return 'online';
        if (function_exists('todo_get_product_sale_status')) {
            return todo_get_product_sale_status($post->ID);
        }
        $product_data = self::get_product_model_row($post->ID);
        return (!empty($product_data->sale_status)) ? (string) $product_data->sale_status : 'online';
    }

    /**
     * 解析商品是否在架在售标签 ('1' / '0')
     */
    public function parse_post_is_on_sale($attrs, $content, $data)
    {
        global $post;
        if (!$post) return '0';
        if (function_exists('todo_is_product_on_sale')) {
            return todo_is_product_on_sale($post) ? '1' : '0';
        }
        $product_data = self::get_product_model_row($post->ID);
        $status = (!empty($product_data->sale_status)) ? $product_data->sale_status : 'online';
        $on_sale = (($post->post_status ?? '') === 'publish' && $status !== 'warehouse');
        return $on_sale ? '1' : '0';
    }


    /**
     * 解析用户操作URL标签
     */
    public function parse_user_action_url($attrs, $content, $data)
    {
        $action = isset($attrs['action']) ? $attrs['action'] : 'login';
        $base = function_exists('todo_home_url') ? todo_home_url('/users/actions/') : (function_exists('home_url') ? home_url('/users/actions/') : '/users/actions/');
        $locale = function_exists('current_locale') ? current_locale() : '';
        $base_url = (function_exists('get_language_url') && !empty($locale)) ? get_language_url($locale, $base) : $base;

        switch ($action) {
            case 'login':
                return $base_url . 'login.php';
            case 'register':
                return $base_url . 'register.php';
            case 'logout':
                return $base_url . 'logout.php';
            case 'forgot_password':
                return $base_url . 'forgot-password.php';
            case 'reset_password':
                return $base_url . 'reset-password.php';
            case 'profile_update':
                return $base_url . 'profile-update.php';
            case 'change_password':
                return $base_url . 'change-password.php';
            default:
                return $base_url . $action . '.php';
        }
    }

    /**
     * 解析统一提示标签
     *
     * @param array $attrs 标签属性
     * @param string $content 标签内容
     * @param array $data 页面数据
     * @return string
     */
    public function parse_unified_notice($attrs, $content, $data)
    {
        if (!function_exists('todo_shop_get_service_settings')) {
            $settings_file = ABSPATH . 'core/functions/shop-service-settings.php';
            if (file_exists($settings_file)) {
                require_once $settings_file;
            }
        }
        
        $settings = array();
        if (function_exists('todo_shop_get_service_settings')) {
            $settings = todo_shop_get_service_settings();
        } elseif (function_exists('todo_get_option')) {
            $settings = todo_get_option('todo_service_settings', array());
        }
        
        $notice = isset($settings['unified_notice']) ? trim((string)$settings['unified_notice']) : '';
        if ($notice === '') {
            return '';
        }

        return '<div class="checkout-unified-notice" style="background: #fef6f6;border: 1px solid #eaafaf;border-radius: 6px;padding: 12px 20px;margin-bottom: 20px;line-height: 1.5;color: #d93b0b;">' . $notice . '</div>';
    }

    /**
     * 解析支付方式标签
     */
    public function parse_payment_methods($attrs, $content, $data)
    {
        $payment_methods = [];
        
        // 确保包含支付方法获取函数
        if (!function_exists('todo_get_payment_methods')) {
            $pm_file = ABSPATH . 'includes/shop/payment-methods.php';
            if (file_exists($pm_file)) {
                require_once $pm_file;
            }
        }

        // 确保包含服务设置函数
        if (!function_exists('todo_shop_get_service_settings')) {
            $settings_file = ABSPATH . 'core/functions/shop-service-settings.php';
            if (file_exists($settings_file)) {
                require_once $settings_file;
            }
        }

        // 从数据库获取已有的支付方式定义
        $available_methods = [];
        if (function_exists('todo_get_payment_methods')) {
            $db_methods = todo_get_payment_methods();
            if (!empty($db_methods) && is_array($db_methods)) {
                foreach ($db_methods as $m) {
                    $available_methods[$m['code']] = $m;
                }
            }
        }

        $settings = array();
        if (function_exists('todo_shop_get_service_settings')) {
            $settings = todo_shop_get_service_settings();
        } elseif (function_exists('todo_get_option')) {
            $settings = todo_get_option('todo_service_settings', array());
        }

        $enabled_methods = isset($settings['payment_methods']) && is_array($settings['payment_methods']) ? $settings['payment_methods'] : array();

        $payment_opts = function_exists('todo_get_option') ? todo_get_option('todo_payment_settings', array()) : array();

        $alipay_enabled = !empty($payment_opts['alipay']['enabled']);
        $wechat_enabled = !empty($payment_opts['wechat']['enabled']);

        // 如果开启了第三方支付（PayJS / 虎皮椒 / PayPal）
        if (is_array($payment_opts) && isset($payment_opts['third_party']) && is_array($payment_opts['third_party'])) {
            $tp = $payment_opts['third_party'];
            if (!empty($tp['enabled'])) {
                $provider = $tp['provider'] ?? '';
                if ((empty($provider) || $provider === 'payjs') && !empty($tp['payjs']['enabled']) && !empty($tp['payjs']['mchid'])) {
                    $alipay_enabled = true;
                    $wechat_enabled = true;
                } elseif ((empty($provider) || $provider === 'hupijiao') && !empty($tp['hupijiao']['enabled'])) {
                    $alipay_enabled = true;
                    $wechat_enabled = true;
                } elseif (!empty($tp['payjs']['enabled']) && !empty($tp['payjs']['mchid'])) {
                    $alipay_enabled = true;
                    $wechat_enabled = true;
                } elseif (!empty($tp['hupijiao']['enabled'])) {
                    $alipay_enabled = true;
                    $wechat_enabled = true;
                }
            }
        }

        $def_currency = function_exists('todo_get_default_currency_config') ? (todo_get_default_currency_config()['code'] ?? '') : '';
        $paypal_enabled = false;
        $paypal_ok = false;
        $paypal_currency = $def_currency;
        if (is_array($payment_opts) && isset($payment_opts['third_party']) && is_array($payment_opts['third_party'])) {
            $tp = $payment_opts['third_party'];
            $pp = (isset($tp['paypal']) && is_array($tp['paypal'])) ? $tp['paypal'] : array();
            $paypal_enabled = !empty($tp['enabled']) && !empty($pp['enabled']);
            
            $paypal_ok = false;
            if (!empty($pp['accounts']) && is_array($pp['accounts'])) {
                foreach ($pp['accounts'] as $acc) {
                    if (!empty($acc['client_id']) && !empty($acc['client_secret'])) {
                        $paypal_ok = true;
                        break;
                    }
                }
            } else {
                $cid = isset($pp['client_id']) ? trim((string) $pp['client_id']) : '';
                $cs = isset($pp['client_secret']) ? trim((string) $pp['client_secret']) : '';
                $paypal_ok = ($cid !== '' && $cs !== '');
            }

            $paypal_currency = $def_currency;
        }

        // 解析包含与排除列表 (支持 only="alipay" 或通过 URL $_GET['pay_method'] 数据端直接过滤)
        $exclude_list = array();
        if (isset($attrs['exclude']) && !empty($attrs['exclude'])) {
            $exclude_list = array_map('trim', explode(',', strtolower((string)$attrs['exclude'])));
        }

        $only_list = array();
        if (isset($attrs['only']) && !empty($attrs['only'])) {
            $only_list = array_map('trim', explode(',', strtolower((string)$attrs['only'])));
        } elseif (isset($attrs['pay_method']) && !empty($attrs['pay_method'])) {
            $only_list = array_map('trim', explode(',', strtolower((string)$attrs['pay_method'])));
        } elseif (!empty($_GET['pay_method'])) {
            $only_list = array_map('trim', explode(',', strtolower((string)$_GET['pay_method'])));
        }

        // 动态构建前台展示的支付方式列表
        foreach ($enabled_methods as $code) {
            $code_lower = strtolower($code);
            if (!empty($only_list) && !in_array($code_lower, $only_list, true)) {
                continue;
            }
            if (in_array($code_lower, $exclude_list, true)) {
                continue;
            }

            // 严格检查后台配置是否开启
            if ($code_lower === 'alipay' && !$alipay_enabled && (!isset($available_methods[$code]) || empty($available_methods[$code]['status']))) {
                continue;
            }
            if ($code_lower === 'wechat' && !$wechat_enabled && (!isset($available_methods[$code]) || empty($available_methods[$code]['status']))) {
                continue;
            }
            if ($code_lower === 'paypal' && !($paypal_enabled && $paypal_ok)) {
                continue;
            }
            
            // 如果数据库里有定义，优先使用数据库定义
            if (isset($available_methods[$code])) {
                $method = $available_methods[$code];
            } else {
                // 否则动态生成基础结构，确保任何新增的第三方支付代码都不会崩溃
                $method = [
                    'id' => 0,
                    'code' => $code,
                    'method_id' => $code,
                    'name' => strtoupper($code),
                    'description' => '',
                    'enabled' => true,
                    'status' => 1
                ];
                
                // 对几个系统常见的核心支付方式提供友好的默认文案
                switch ($code) {
                    case 'alipay':
                        $method['name'] = __('支付宝');
                        $method['description'] = __('使用支付宝安全快捷支付');
                        break;
                    case 'wechat':
                        $method['name'] = __('微信支付');
                        $method['description'] = __('使用微信支付，支持扫码和H5支付');
                        break;
                    case 'offline':
                        $method['name'] = __('线下转账');
                        $method['description'] = __('通过银行卡或其他线下方式转账');
                        break;
                    case 'cod':
                        $method['name'] = __('货到付款');
                        $method['description'] = __('收到商品后支付');
                        break;
                    case 'balance':
                        $method['name'] = __('余额支付');
                        $method['description'] = __('使用账户余额支付');
                        break;
                    case 'paypal':
                        $method['name'] = 'PayPal';
                        $method['description'] = 'PayPal '. __('跨境支付') . ' (' . $paypal_currency . ')';
                        break;
                }
            }
            
            // 注入货到付款及线下支付（银行转账）说明
            if ($code_lower === 'cod') {
                $raw_cod = $payment_opts['cod']['instructions'] ?? '';
                if (function_exists('todo_parse_inline_translations')) {
                    $raw_cod = todo_parse_inline_translations($raw_cod);
                }
                $method['cod_instructions'] = $raw_cod;
                $method['instructions'] = $raw_cod;
            } elseif ($code_lower === 'offline' || $code_lower === 'bank_transfer') {
                $bank_inst = $payment_opts['bank_transfer']['instructions'] ?? ($payment_opts['offline']['instructions'] ?? '');
                if (function_exists('todo_parse_inline_translations')) {
                    $bank_inst = todo_parse_inline_translations($bank_inst);
                }
                $method['bank_instructions'] = $bank_inst;
                $method['offline_instructions'] = $bank_inst;
                $method['instructions'] = $bank_inst;
            }

            if (function_exists('todo_parse_inline_translations')) {
                if (isset($method['name']) && is_string($method['name'])) {
                    $method['name'] = todo_parse_inline_translations($method['name']);
                }
                if (isset($method['description']) && is_string($method['description'])) {
                    $method['description'] = todo_parse_inline_translations($method['description']);
                }
            }
            
            // 提供一个 hook，让外部三方插件（如虎皮椒、PayJS）可以在渲染前注入自定义名称和描述
            if (function_exists('apply_filters')) {
                $method = apply_filters('todo_payment_method_details', $method, $code);
            }
            
            // 附加 provider 用于 AB 支付策略等后续扩展
            if (isset($payment_providers[$code])) {
                $method['provider'] = $payment_providers[$code];
            }
            
            $payment_methods[] = $method;
        }

        if (empty($payment_methods)) {
            return '';
        }

        $result = '';
        $first_enabled = true;
        $index = 0;

        foreach ($payment_methods as $method) {
            // 检查支付方式是否启用
            $is_enabled = false;
            if (isset($method['enabled'])) {
                $is_enabled = $method['enabled'];
            } elseif (isset($method['status'])) {
                $is_enabled = (bool) $method['status'];
            } else {
                $is_enabled = true; // 默认启用
            }

            if ($is_enabled) {
                // 统一字段名称，确保兼容性
                if (isset($method['code']) && !isset($method['method_id'])) {
                    $method['method_id'] = $method['code'];
                }

                $name_str = isset($method['name']) ? trim((string)$method['name']) : '';
                $first_char = '';
                if ($name_str !== '') {
                    if (function_exists('mb_substr')) {
                        $first_char = mb_substr($name_str, 0, 1, 'UTF-8');
                    } else {
                        $first_char = substr($name_str, 0, 1);
                    }
                }
                $method['initial'] = $first_char;
                $method['name_initial'] = $first_char;
                $method['first_letter'] = $first_char;

                // 添加is_default字段，第一个启用的支付方式为默认
                $method['is_default'] = $first_enabled;
                $first_enabled = false;
                $index++;

                // 为每个支付方式设置数据
                $method_data = array_merge($data, $method, [
                    'field' => $method,
                    'item' => $method,
                    'index' => $index,
                ]);

                // 解析内容模板
                // parser instance
                $result .= CMS_Template_Compiler_V2::render_string($content, $method_data);
            }
        }

        return $result;
    }

    /**
     * 解析配送方式标签 {{todo:shipping_methods}}
     */
    public function parse_shipping_methods($attrs, $content, $data)
    {
        if (!function_exists('todo_get_shipping_methods')) {
            $settings_file = ABSPATH . 'core/functions/shop-service-settings.php';
            if (file_exists($settings_file)) {
                require_once $settings_file;
            }
        }

        $methods = function_exists('todo_get_shipping_methods') ? todo_get_shipping_methods(true) : array();
        if (empty($methods)) {
            return '';
        }

        $result = '';
        $first_enabled = true;
        $index = 0;

        foreach ($methods as $method) {
            $index++;
            $method['is_default'] = $first_enabled;
            $first_enabled = false;
            $fee_raw = floatval($method['fee'] ?? 0);
            $method['fee_format'] = function_exists('todo_format_price') ? todo_format_price($fee_raw, 'number') : sprintf('%.2f', $fee_raw);

            $method_data = array_merge($data, $method, [
                'field' => $method,
                'item' => $method,
                'index' => $index,
            ]);

            $result .= CMS_Template_Compiler_V2::render_string($content, $method_data);
        }

        return $result;
    }

    private function capture_ad_cache_dependency($ad_id = 0, $group_id = 0, $marker = '')
    {
        if (class_exists('Todo_Unified_Cache_Manager')
            && method_exists('Todo_Unified_Cache_Manager', 'capture_template_cache_ad_dependency')) {
            Todo_Unified_Cache_Manager::capture_template_cache_ad_dependency($ad_id, $group_id, $marker);
        }
    }

    private function capture_cache_dependency_token($token = '')
    {
        if (class_exists('Todo_Unified_Cache_Manager')
            && method_exists('Todo_Unified_Cache_Manager', 'capture_template_cache_dependency_token')) {
            Todo_Unified_Cache_Manager::capture_template_cache_dependency_token($token);
        }
    }

    private function capture_menu_category_visibility_dependencies($items)
    {
        foreach ((array) $items as $item) {
            $row = is_object($item) ? (array) $item : (array) $item;

            $type = isset($row['type']) ? (string) $row['type'] : '';
            $term_id = 0;
            if ($type === 'category' && !empty($row['object_id'])) {
                $term_id = (int) $row['object_id'];
            } elseif (!empty($row['term_id'])) {
                $term_id = (int) $row['term_id'];
            }

            if ($term_id > 0) {
                $this->capture_cache_dependency_token('term_visibility:' . $term_id);
            }

            if (!empty($row['children']) && is_array($row['children'])) {
                $this->capture_menu_category_visibility_dependencies($row['children']);
            }
        }
    }

    private function filter_visible_menu_items($items)
    {
        if (class_exists('Todo_Menu_Manager')
            && method_exists('Todo_Menu_Manager', 'filter_visible_menu_items')) {
            return Todo_Menu_Manager::filter_visible_menu_items((array) $items);
        }

        return (array) $items;
    }

    // ==================== 广告标签解析 ====================
    /**
     * 解析单广告数据：用于 {{todo:if single_ads|id=1}} 与 {{todo:loop show_ads|limit=5}}
     * 规则：
     * - 存在性判断：查找 id 对应广告是否存在、且激活状态为1、且在有效时间范围内
     * - 不传 id 时：默认命中 sort_order=0 的第一个有效广告（若无则取列表第一个）
     * 返回结构示例：
     * {
     *   id, title, type,
     *   groups: [ { title, link, target, white_text, content, images: [{url,title,alt}, ...] }, ... ]
     *   或兼容：images/image_url/link/target/white_text/content
     * }
     */
    public function parse_single_ads($attrs, $content, $data)
    {
        if (!class_exists('Todo_Ads_Manager')) {
            return [];
        }
        $id = 0;
        if (is_array($attrs)) {
            $id = isset($attrs['id']) ? intval($attrs['id']) : 0;
        }

        // 获取广告
        $ad = null;
        if ($id > 0) {
            $ad = Todo_Ads_Manager::get_ad($id);
        }
        if (!$ad) {
            // 默认取 sort_order=0 的第一个有效广告
            $ads = Todo_Ads_Manager::get_ads([
                'status' => 'active',
                'active_only' => true,
                'order_by' => 'sort_order ASC, id ASC',
                'limit' => 1
            ]);
            if (!empty($ads)) {
                $ad = $ads[0];
            }
        }
        if (!$ad) {
            if ($id <= 0) {
                $this->capture_ad_cache_dependency(0, 0, 'single_ads_default');
            }
            return [];
        }

        // 统一状态判断（兼容数值/字符串），并确保有效时间（active_only已筛选）
        $is_active = false;
        if (isset($ad['status'])) {
            if (is_numeric($ad['status'])) {
                $is_active = intval($ad['status']) === 1;
            } else {
                $is_active = (strtolower(trim((string) $ad['status'])) === 'active');
            }
        }

        // 当通过 ID 直接获取时，也需要做时间有效性判断
        // 优先级：日期/时间范围（start_date/end_date，支持到秒的 DATETIME）要高于每日时间段（start_time/end_time）。
        // 修复1：原逻辑仅按日期字符串（Y-m-d）比较，若数据库中保存了含时间的 DATETIME（例如 2025-10-17 13:30），
        // 在“按ID取广告”路径会误判为仍在有效期内。现统一转换为时间戳进行比较。
        // 修复2：每日时间比较统一为秒级，兼容 H:i 与 H:i:s。
        $now_ts = time();
        $now_time_sec = intval(date('H')) * 3600 + intval(date('i')) * 60 + intval(date('s'));
        $to_seconds = function ($t) {
            $t = trim((string) $t);
            if ($t === '') {
                return null;
            }
            $parts = explode(':', $t);
            // 兼容 H:i 与 H:i:s
            if (count($parts) === 2) {
                $parts[] = '0';
            }
            if (count($parts) < 2) {
                return null;
            }
            $h = isset($parts[0]) ? intval($parts[0]) : 0;
            $m = isset($parts[1]) ? intval($parts[1]) : 0;
            $s = isset($parts[2]) ? intval($parts[2]) : 0;
            if ($h < 0 || $h > 23) {
                $h = 0;
            }
            if ($m < 0 || $m > 59) {
                $m = 0;
            }
            if ($s < 0 || $s > 59) {
                $s = 0;
            }
            return $h * 3600 + $m * 60 + $s;
        };
        // 先做“日期/时间范围”的硬性判断（优先级更高）
        if (!empty($ad['start_date'])) {
            // 若仅提供日期（无时间），按当天00:00:00开始
            $sd = (string) $ad['start_date'];
            $start_ts = (strpos($sd, ':') === false && strpos($sd, ' ') === false)
                ? strtotime(trim($sd) . ' 00:00:00')
                : strtotime($sd);
            if ($start_ts !== false && $now_ts < $start_ts) {
                $this->capture_ad_cache_dependency(isset($ad['id']) ? intval($ad['id']) : 0, isset($ad['group_id']) ? intval($ad['group_id']) : 0, $id <= 0 ? 'single_ads_default' : '');
                return [];
            }
        }
        if (!empty($ad['end_date'])) {
            // 若仅提供日期（无时间），按当天23:59:59结束（避免误判在当天00:00:00就失效）
            $ed = (string) $ad['end_date'];
            $end_ts = (strpos($ed, ':') === false && strpos($ed, ' ') === false)
                ? strtotime(trim($ed) . ' 23:59:59')
                : strtotime($ed);
            if ($end_ts !== false && $now_ts > $end_ts) {
                $this->capture_ad_cache_dependency(isset($ad['id']) ? intval($ad['id']) : 0, isset($ad['group_id']) ? intval($ad['group_id']) : 0, $id <= 0 ? 'single_ads_default' : '');
                return [];
            }
        }
        // 再判断每日时间段（仅在日期范围内时生效）
        $start_sec = !empty($ad['start_time']) ? $to_seconds($ad['start_time']) : null;
        $end_sec = !empty($ad['end_time']) ? $to_seconds($ad['end_time']) : null;
        if ($start_sec !== null && $end_sec !== null) {
            $in_time_window = ($start_sec <= $end_sec)
                ? ($now_time_sec >= $start_sec && $now_time_sec <= $end_sec)
                : ($now_time_sec >= $start_sec || $now_time_sec <= $end_sec);
            if (!$in_time_window) {
                $this->capture_ad_cache_dependency(isset($ad['id']) ? intval($ad['id']) : 0, isset($ad['group_id']) ? intval($ad['group_id']) : 0, $id <= 0 ? 'single_ads_default' : '');
                return [];
            }
        } elseif ($start_sec !== null) {
            if ($now_time_sec < $start_sec) {
                $this->capture_ad_cache_dependency(isset($ad['id']) ? intval($ad['id']) : 0, isset($ad['group_id']) ? intval($ad['group_id']) : 0, $id <= 0 ? 'single_ads_default' : '');
                return [];
            }
        } elseif ($end_sec !== null) {
            if ($now_time_sec > $end_sec) {
                $this->capture_ad_cache_dependency(isset($ad['id']) ? intval($ad['id']) : 0, isset($ad['group_id']) ? intval($ad['group_id']) : 0, $id <= 0 ? 'single_ads_default' : '');
                return [];
            }
        }

        $this->capture_ad_cache_dependency(
            isset($ad['id']) ? intval($ad['id']) : 0,
            isset($ad['group_id']) ? intval($ad['group_id']) : 0,
            $id <= 0 ? 'single_ads_default' : ''
        );

        // 构造返回数据
        $result = [
            'id' => isset($ad['id']) ? intval($ad['id']) : 0,
            'title' => isset($ad['title']) ? $ad['title'] : '',
            'type' => isset($ad['type']) ? $ad['type'] : '',
        ];

        // 解析内容 JSON（优先 image_groups / media_groups 结构）
        $content_data = null;
        if (!empty($ad['content'])) {
            $maybe = json_decode($ad['content'], true);
            if (is_array($maybe)) {
                $content_data = $maybe;
            }
        }
        if (is_array($content_data) && isset($content_data['type']) && in_array($content_data['type'], ['image_groups', 'media_groups'], true)) {
            $groups = isset($content_data['groups']) && is_array($content_data['groups']) ? $content_data['groups'] : [];
            if (function_exists('todo_filter_featured_images_by_lang')) {
                foreach ($groups as $gk => $g) {
                    if (!is_array($g)) {
                        continue;
                    }
                    if (isset($g['images']) && is_array($g['images'])) {
                        $groups[$gk]['images'] = array_values(todo_filter_featured_images_by_lang($g['images']));
                    } elseif (isset($g['medias']) && is_array($g['medias'])) {
                        $groups[$gk]['medias'] = array_values(todo_filter_featured_images_by_lang($g['medias']));
                    }
                }
            }
            $result['groups'] = $groups;
        } elseif (is_array($content_data) && isset($content_data['type']) && $content_data['type'] === 'html_codes') {
            // HTML代码广告：结构化 items，每个元素含 {html}
            $items = isset($content_data['items']) && is_array($content_data['items']) ? $content_data['items'] : [];
            // 统一存储为 codes，元素为字符串或对象均兼容
            $codes = [];
            foreach ($items as $it) {
                if (is_array($it) && isset($it['html'])) {
                    $codes[] = $it['html'];
                } elseif (is_string($it)) {
                    $codes[] = $it;
                }
            }
            $result['codes'] = $codes;
        } else {
            // 兼容常规字段
            if (!empty($ad['images'])) {
                $images = is_array($ad['images']) ? $ad['images'] : json_decode($ad['images'], true);
                if (is_array($images)) {
                    if (function_exists('todo_filter_featured_images_by_lang')) {
                        $images = todo_filter_featured_images_by_lang($images);
                    }
                    $result['images'] = array_values($images);
                }
            }
            if (!empty($ad['image_url'])) {
                $result['image_url'] = $ad['image_url'];
            }
            if (!empty($ad['link_url'])) {
                $result['link'] = $ad['link_url'];
            }
            if (!empty($ad['target'])) {
                $result['target'] = $ad['target'];
            }
            if (isset($ad['is_white_text'])) {
                $result['white_text'] = intval($ad['is_white_text']);
            }
            if (!empty($ad['content']) && !is_array($content_data)) {
                $result['content'] = $ad['content'];
            }
            $img_count = isset($result['images']) && is_array($result['images']) ? count($result['images']) : 0;
        }
        $this->last_single_ads = $result;
        return $result;
    }

    /**
     * 解析广告分组：用于 {{todo:if group_ads|id=1}} 存在性判断
     * 规则：
     * - 存在性判断：分组存在、且激活状态为1
     * - 不传 id/code 时：默认命中 sort_order=0 的第一个分组（若无则取列表第一个）
     */
    public function parse_group_ads($attrs, $content, $data)
    {
        if (!class_exists('Todo_Ads_Manager')) {
            return [];
        }
        $this->last_group_ads_ads = null;
        $id = 0;
        $code = '';
        if (is_array($attrs)) {
            $id = isset($attrs['id']) ? intval($attrs['id']) : 0;
            $code = isset($attrs['code']) ? trim((string) $attrs['code']) : '';
        }

        $group = null;
        if ($id > 0) {
            $group = Todo_Ads_Manager::get_ad_group($id);
        } elseif ($code !== '' && method_exists('Todo_Ads_Manager', 'get_ad_group_by_code')) {
            $group = Todo_Ads_Manager::get_ad_group_by_code($code);
        }
        if (!$group) {
            $groups = Todo_Ads_Manager::get_ad_groups([
                'status' => 'active',
                'order_by' => 'sort_order ASC, id ASC'
            ]);
            if (!empty($groups)) {
                $firstZero = null;
                foreach ($groups as $g) {
                    if (isset($g['sort_order']) && intval($g['sort_order']) === 0) {
                        $firstZero = $g;
                        break;
                    }
                }
                $group = $firstZero ? $firstZero : $groups[0];
            }
        }
        if (!$group)
        {
            if ($id <= 0 && $code === '') {
                $this->capture_ad_cache_dependency(0, 0, 'group_ads_default');
            }
            return [];
        }

        $is_active = false;
        $status = is_object($group) ? (isset($group->status) ? $group->status : null) : (isset($group['status']) ? $group['status'] : null);

        if ($status !== null) {
            if (is_numeric($status)) {
                $is_active = intval($status) === 1;
            } else {
                $is_active = (strtolower(trim((string) $status)) === 'active');
            }
        }
        $gid = is_object($group) ? (isset($group->id) ? intval($group->id) : 0) : (isset($group['id']) ? intval($group['id']) : 0);
        if (!$is_active)
        {
            $this->capture_ad_cache_dependency(0, $gid, $id <= 0 && $code === '' ? 'group_ads_default' : '');
            return [];
        }

        if ($gid <= 0) {
            return [];
        }

        $this->capture_ad_cache_dependency(0, $gid, $id <= 0 && $code === '' ? 'group_ads_default' : '');

        $ads = Todo_Ads_Manager::get_ads([
            'group_id' => $gid,
            'status' => 'active',
            'active_only' => true,
            'order_by' => 'sort_order ASC, id ASC',
        ]);

        if (!is_array($ads) || empty($ads)) {
            return [];
        }

        $prev_last_single_ads = $this->last_single_ads;
        $parsed_ads = [];
        foreach ($ads as $ad_row) {
            $ad_row = is_object($ad_row) ? (array) $ad_row : (array) $ad_row;
            $ad_id = isset($ad_row['id']) ? intval($ad_row['id']) : 0;
            if ($ad_id <= 0) {
                continue;
            }
            $parsed = $this->parse_single_ads(['id' => $ad_id], '', is_array($data) ? $data : []);
            if (is_array($parsed) && !empty($parsed)) {
                $parsed_ads[] = $parsed;
            }
        }
        $this->last_single_ads = $prev_last_single_ads;

        if (empty($parsed_ads)) {
            return [];
        }
        $this->last_group_ads_ads = $parsed_ads;

        return [
            'id' => $gid,
            'name' => is_object($group) ? (isset($group->name) ? $group->name : '') : (isset($group['name']) ? $group['name'] : ''),
            'code' => is_object($group) ? (isset($group->code) ? $group->code : '') : (isset($group['code']) ? $group['code'] : ''),
            'description' => is_object($group) ? (isset($group->description) ? $group->description : '') : (isset($group['description']) ? $group['description'] : ''),
        ];
    }

    // ==================== 内联条件辅助标签 ====================
    /**
     * 解析内联类名条件标签：{{todo:class_if item.white_text|whitecolor|darkcolor}}
     * 用于在属性值中安全地按条件追加类名，避免成对 {{todo:if}} 嵌套导致同行其它标签解析受影响。
     * 语法：
     * - {{todo:class_if 变量|为真时类名|为假时类名(可选)}}
     * - 也支持属性写法：{{todo:class_if var=item.white_text, true=whitecolor, false=darkcolor}}
     * 返回：前置一个空格的类名或空字符串。
     */
    public function parse_class_if($attrs, $content, $data)
    {
        // 提取参数：支持管道顺序参数或键值对
        $varKey = '';
        $trueClass = '';
        $falseClass = '';

        if (is_array($attrs)) {
            // 键值对优先
            if (isset($attrs['var'])) {
                $varKey = trim((string) $attrs['var']);
            }
            if (isset($attrs['true'])) {
                $trueClass = trim((string) $attrs['true']);
            }
            if (isset($attrs['false'])) {
                $falseClass = trim((string) $attrs['false']);
            }

            // 管道/顺序参数回退
            if ($varKey === '' && isset($attrs[0])) {
                $varKey = trim((string) $attrs[0]);
            }
            if ($trueClass === '' && isset($attrs[1])) {
                $trueClass = trim((string) $attrs[1]);
            }
            if ($falseClass === '' && isset($attrs[2])) {
                $falseClass = trim((string) $attrs[2]);
            }
        }

        if ($varKey === '') {
            return '';
        }

        // 解析点号路径变量值（支持 item.white_text 等）
        $val = $data;
        $parts = explode('.', $varKey);
        foreach ($parts as $part) {
            if (is_array($val) && array_key_exists($part, $val)) {
                $val = $val[$part];
            } elseif (is_object($val) && isset($val->$part)) {
                $val = $val->$part;
            } else {
                $val = null;
                break;
            }
        }

        // 计算真值：true/1/'1'/'true' 为真；非空且不为 '0'/'false' 的字符串亦为真
        $isTrue = false;
        if ($val !== null) {
            if (is_bool($val)) {
                $isTrue = $val;
            } else {
                $isTrue = ($val === true || $val === 1 || $val === '1' || $val === 'true' || (!empty($val) && $val !== '0' && strtolower((string) $val) !== 'false'));
            }
        }

        if ($isTrue && $trueClass !== '') {
            return ' ' . $trueClass;
        }
        if (!$isTrue && $falseClass !== '') {
            return ' ' . $falseClass;
        }
        return '';
    }

    /**
     * 通用内联值条件标签：{{todo:value_if item.white_text|YES|NO}}
     * 返回为真/为假的两个值之一（不自动添加空格），适合用于属性值、文本内容等。
     * 也支持属性写法：{{todo:value_if var=item.white_text, true=YES, false=NO}}
     */
    public function parse_value_if($attrs, $content, $data)
    {
        $varKey = '';
        $trueVal = '';
        $falseVal = '';

        if (is_array($attrs)) {
            if (isset($attrs['var'])) {
                $varKey = trim((string) $attrs['var']);
            }
            if (isset($attrs['true'])) {
                $trueVal = (string) $attrs['true'];
            }
            if (isset($attrs['false'])) {
                $falseVal = (string) $attrs['false'];
            }

            if ($varKey === '' && isset($attrs[0])) {
                $varKey = trim((string) $attrs[0]);
            }
            if ($trueVal === '' && isset($attrs[1])) {
                $trueVal = (string) $attrs[1];
            }
            if ($falseVal === '' && isset($attrs[2])) {
                $falseVal = (string) $attrs[2];
            }
        }

        if ($varKey === '') {
            return $falseVal;
        }

        $val = $data;
        foreach (explode('.', $varKey) as $part) {
            if (is_array($val) && array_key_exists($part, $val)) {
                $val = $val[$part];
            } elseif (is_object($val) && isset($val->$part)) {
                $val = $val->$part;
            } else {
                $val = null;
                break;
            }
        }

        $isTrue = false;
        if ($val !== null) {
            if (is_bool($val)) {
                $isTrue = $val;
            } else {
                $isTrue = ($val === true || $val === 1 || $val === '1' || $val === 'true' || (!empty($val) && $val !== '0' && strtolower((string) $val) !== 'false'));
            }
        }

        return $isTrue ? $trueVal : $falseVal;
    }

    /**
     * 解析当前语言标签
     * 用法：{{todo:current_language}}
     */
    public function parse_current_language($attrs, $content, $data)
    {
        if (function_exists('get_current_language')) {
            return get_current_language();
        }
        if (class_exists('LocaleContext')) {
            return LocaleContext::get_instance()->get_locale();
        }
        return '';
    }

    /**
     * 解析语言切换器标签
     * 用法：{{todo:language_switcher type="dropdown" show_flags="1" show_names="1" show_native_names="0" class="custom-class"}}
     */
    public function parse_language_switcher($attrs, $content, $data)
    {
        // 载入前端渲染类
        require_once ABSPATH . 'includes/language/class-language-switcher.php';

        $type = 'dropdown';
        if (isset($attrs['type']) && in_array($attrs['type'], ['dropdown', 'list', 'flags'], true)) {
            $type = $attrs['type'];
        }

        // 布尔型属性辅助解析
        $bool = function ($v, $default) {
            if ($v === null) {
                return $default;
            }
            $sv = strtolower(trim((string) $v));
            return in_array($sv, ['1', 'true', 'yes', 'on'], true);
        };

        $options = [
            'show_flags' => $bool($attrs['show_flags'] ?? null, true),
            'show_names' => $bool($attrs['show_names'] ?? null, true),
            'show_native_names' => $bool($attrs['show_native_names'] ?? null, false),
            'class' => isset($attrs['class']) ? (string) $attrs['class'] : 'todo-language-switcher'
        ];

        return todo_language_switcher($type, $options);
    }

    /**
     * 解析站点切换器标签
     * 用法：{{todo:site_switcher type="dropdown"}}
     */
    public function parse_site_switcher($attrs, $content, $data)
    {
        require_once ABSPATH . 'includes/language/class-site-switcher.php';

        $type = 'dropdown';
        if (isset($attrs['type']) && in_array($attrs['type'], ['dropdown', 'list'], true)) {
            $type = $attrs['type'];
        }

        $options = array(
            'class' => isset($attrs['class']) ? (string) $attrs['class'] : 'todo-site-switcher'
        );

        return todo_site_switcher($type, $options);
    }

    public function parse_t($attrs, $content, $data)
    {
        return $this->parse_translate_text($attrs, $content, $data);
    }

    /**
     * 解析简短文本翻译标签
     *  支持 escape：attr|html|none
     */
    public function parse_translate_text($attrs, $content, $data)
    {
        $text = '';
        // 优先 text 属性，其次位置参数，再次包裹内容
        if (is_array($attrs)) {
            if (isset($attrs['text'])) {
                $text = (string) $attrs['text'];
            } elseif (isset($attrs[0])) {
                $text = (string) $attrs[0];
            }
        }
        if ($text === '' && is_string($content) && trim($content) !== '') {
            $text = trim($content);
        }
        if ($text === '') {
            return '';
        }

        // 解析上下文与语言
        $context = '';
        if (is_array($attrs)) {
            if (!isset($attrs['context']) && isset($attrs['ctx'])) {
                $attrs['context'] = $attrs['ctx'];
            }
            if (isset($attrs['context'])) {
                $context = (string) $attrs['context'];
            }
        }
        $lang = null;
        if (is_array($attrs)) {
            if (!isset($attrs['lang']) && isset($attrs['language'])) {
                $attrs['lang'] = $attrs['language'];
            }
            if (isset($attrs['lang'])) {
                $lang = (string) $attrs['lang'];
            }
        }

        // 若文本中仍包含未解析的 todo 标签，尝试解析一次
        if (strpos($text, '{{todo:') !== false && class_exists('CMS_Template_Compiler_V2')) {
            try {
                $text = CMS_Template_Compiler_V2::render_string($text, $data);
            } catch (\Throwable $e) {
                // 忽略解析异常
            }
        }

        // 翻译
        $translated = function_exists('todo_translate') ? todo_translate($text, $context, $lang) : $text;

        // 可选转义
        $escape = '';
        if (is_array($attrs) && isset($attrs['escape'])) {
            $escape = strtolower((string) $attrs['escape']);
        }
        if ($escape === 'attr') {
            return htmlspecialchars($translated, ENT_QUOTES, 'UTF-8');
        } elseif ($escape === 'html') {
            return htmlspecialchars($translated, ENT_QUOTES, 'UTF-8');
        }
        return $translated;
    }

    /**
     * 通用后台变量获取（多语言感知）
     * 用法：{{todo:option name="site_title" default="TodoCMS"}}
     * 支持：name、default、locale
     */
    public function parse_option($attrs, $content, $data)
    {
        $name = isset($attrs['name']) ? (string) $attrs['name'] : '';
        if ($name === '') {
            return isset($attrs['default']) ? (string) $attrs['default'] : '';
        }
        $default = isset($attrs['default']) ? (string) $attrs['default'] : '';
        $locale = isset($attrs['locale']) ? (string) $attrs['locale'] : null;
        // 统一走多语言选项读取，内置回退
        if (function_exists('todo_get_option_ml')) {
            // 若提供 locale，当前实现仍以请求上下文语言为准；后续在 LocaleContext 注入后增强
            $val = todo_get_option_ml($name, $default);
            return is_string($val) ? $val : (is_scalar($val) ? (string) $val : json_encode($val, JSON_UNESCAPED_UNICODE));
        }
        if (function_exists('todo_get_option')) {
            $val = todo_get_option($name, $default);
            return is_string($val) ? $val : (is_scalar($val) ? (string) $val : json_encode($val, JSON_UNESCAPED_UNICODE));
        }
        return $default;
    }

    /**
     * 优化版获取Term链接（纯计算，避免数据库查询）
     */
    private function get_term_link_optimized($slug, $taxonomy, $term_id = 0)
    {
        $base = '';
        if ($taxonomy === 'category') {
            if ($term_id > 0 && function_exists('todo_get_category_link')) {
                return todo_get_category_link($term_id);
            }
            $base = function_exists('todo_get_site_url') ? todo_get_site_url() : '';
            $url = $base . '/' . $slug . '/';
        } elseif ($taxonomy === 'post_tag' || $taxonomy === 'tag') {
            $base = function_exists('todo_get_site_url') ? todo_get_site_url() : '';
            $url = $base . '/tag/' . $slug . '/';
        } else {
            return '';
        }

        if (function_exists('current_locale') && function_exists('get_language_url')) {
            $url = get_language_url(current_locale(), $url);
        }
        return $url;
    }

    /**
     * 批量获取Term翻译（辅助函数）
     */
    private function batch_fetch_term_translations($term_ids, $taxonomy)
    {
        global $tododb;
        if (empty($term_ids) || !$tododb)
            return [];

        $locale = function_exists('todo_current_locale') ? todo_current_locale(true) : '';
        if (!$locale)
            return [];

        $allowed = ['approved', 'pending'];

        $term_ids = array_map('intval', $term_ids);
        $term_ids = array_unique(array_filter($term_ids));
        if (empty($term_ids))
            return [];

        $term_ids_str = implode(',', $term_ids);
        $allowed_str = "'" . implode("','", $allowed) . "'";
        $table = $tododb->prefix . 'term_translations';

        $sql = "SELECT term_id, name, description, status FROM {$table} 
                WHERE term_id IN ({$term_ids_str}) 
                AND taxonomy = ? 
                AND language_code = ? 
                AND status IN ({$allowed_str})
                ORDER BY (status='approved') DESC, updated_at DESC, id DESC";

        // Assuming get_results supports params array like get_row/get_var in this codebase
        // If not, we might need to verify. But consistent usage suggests it does.
        $rows = $tododb->get_results($sql, [$taxonomy, $locale]);

        $map = [];
        if ($rows) {
            foreach ($rows as $row) {
                if (!isset($map[$row->term_id])) {
                    $map[$row->term_id] = [
                        'name' => $row->name,
                        'description' => $row->description
                    ];
                }
            }
        }

        return $map;
    }

    /**
     * 统一渲染图片类型的字段输出
     * 支持：单ID、单URL、JSON数组（多图）、JSON对象（单图）
     * 功能：自动获取元数据（Alt/Title），支持手动属性覆盖
     */
    /**
     * 统一渲染图片类型的字段输出
     * 支持：单ID、单URL、JSON数组（多图）、JSON对象（单图）
     * 功能：自动获取元数据（Alt/Title），支持手动属性覆盖
     * 优化：批量查询数据库，避免N+1问题
     */
    private function render_image_output($val, $attrs)
    {
        if (empty($val)) {
            return '';
        }

        $items = [];
        // 1. 规范化数据为列表
        if (is_array($val)) {
            if (isset($val['id']) || isset($val['url'])) {
                $items[] = $val;
            } else {
                // 可能是多图数组，或者是个空数组
                // 检查第一个元素是否是数组或标量
                $first = reset($val);
                if (is_array($first) || is_numeric($first) || is_string($first)) {
                    $items = $val;
                }
            }
        } elseif (is_string($val)) {
            // 尝试JSON
            $decoded = json_decode($val, true);
            if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_numeric($decoded))) {
                if (is_array($decoded)) {
                    if (isset($decoded['id']) || isset($decoded['url'])) {
                        $items[] = $decoded;
                    } else {
                        $items = $decoded;
                    }
                } else {
                    $items[] = ['id' => $decoded];
                }
            } elseif (is_numeric($val)) {
                $items[] = ['id' => $val];
            } else {
                $items[] = ['url' => $val];
            }
        } elseif (is_numeric($val)) {
            $items[] = ['id' => $val];
        }

        if (empty($items)) {
            return '';
        }

        $html_parts = [];
        $size = isset($attrs['size']) ? $attrs['size'] : 'full';

        global $tododb;

        // 收集所有ID进行批量查询
        $ids_to_fetch = [];
        foreach ($items as $item) {
            $id = null;
            if (is_array($item)) {
                if (isset($item['id']) && is_numeric($item['id']))
                    $id = $item['id'];
            } elseif (is_numeric($item)) {
                $id = $item;
            }
            if ($id && (int) $id > 0) {
                $ids_to_fetch[] = (int) $id;
            }
        }
        $ids_to_fetch = array_unique($ids_to_fetch);

        $posts_map = [];
        $meta_map = [];

        if (!empty($ids_to_fetch) && function_exists('todo_get_attachment_image') && $tododb) {
            $post_table = $tododb->prefix . 'posts';
            $meta_table = $tododb->prefix . 'postmeta';

            $ids_str = implode(',', $ids_to_fetch);

            // 批量获取Post数据
            $posts_sql = "SELECT ID, post_title, post_excerpt, post_content FROM {$post_table} WHERE ID IN ({$ids_str})";
            $posts_results = $tododb->get_results($posts_sql);
            if ($posts_results) {
                foreach ($posts_results as $p) {
                    $posts_map[$p->ID] = $p;
                }
            }

            // 批量获取Meta数据
            $meta_keys = ["'_todo_attachment_image_alt'", "'_todo_attached_file'"];
            $meta_keys_str = implode(',', $meta_keys);
            $meta_sql = "SELECT post_id, meta_key, meta_value FROM {$meta_table} WHERE post_id IN ({$ids_str}) AND meta_key IN ({$meta_keys_str})";
            $meta_results = $tododb->get_results($meta_sql);
            if ($meta_results) {
                foreach ($meta_results as $m) {
                    $meta_map[$m->post_id][$m->meta_key] = $m->meta_value;
                }
            }
        }

        foreach ($items as $index => $item) {
            $id = null;
            $url = '';
            $json_title = '';

            // 解析单个Item
            if (is_array($item)) {
                if (isset($item['id']) && is_numeric($item['id']))
                    $id = $item['id'];
                if (isset($item['url']) && is_string($item['url']))
                    $url = $item['url'];
                if (isset($item['title']))
                    $json_title = $item['title'];
            } elseif (is_numeric($item)) {
                $id = $item;
            } elseif (is_string($item)) {
                $url = $item;
            }

            $img_html = '';

            // 策略1: ID存在，查库 (使用预加载的数据)
            if ($id && (int) $id > 0 && function_exists('todo_get_attachment_image')) {

                // 获取Post主表数据
                $post_row = isset($posts_map[$id]) ? $posts_map[$id] : null;

                // 构建类似get_post的对象结构
                $attach_post = null;
                if ($post_row) {
                    $attach_post = new stdClass();
                    $attach_post->post_title = $post_row->post_title;
                    $attach_post->post_excerpt = $post_row->post_excerpt;
                    $attach_post->post_content = $post_row->post_content;
                }

                $db_title = ($attach_post && isset($attach_post->post_title)) ? $attach_post->post_title : '';

                // 获取Alt元数据
                $db_alt = null;
                if (isset($meta_map[$id]['_todo_attachment_image_alt'])) {
                    $db_alt = $meta_map[$id]['_todo_attachment_image_alt'];
                }

                $img_attrs = [];
                // Class (Append index)
                if (isset($attrs['class']) && $attrs['class'] !== '') {
                    $img_attrs['class'] = $attrs['class'] . ' item-' . $index;
                } else {
                    $img_attrs['class'] = "attachment-$size size-$size item-$index";
                }

                // 获取文件路径以提取文件名
                $file_path = null;
                if (isset($meta_map[$id]['_todo_attached_file'])) {
                    $file_path = $meta_map[$id]['_todo_attached_file'];
                }

                $filename = $file_path ? pathinfo($file_path, PATHINFO_FILENAME) : '';

                // 准备元数据候选池
                $meta_alt = !empty($db_alt) ? $db_alt : '';
                $meta_title = !empty($db_title) ? $db_title : '';
                $meta_caption = ($attach_post && !empty($attach_post->post_excerpt)) ? $attach_post->post_excerpt : '';
                $meta_description = ($attach_post && !empty($attach_post->post_content)) ? $attach_post->post_content : '';

                // 辅助函数：查找第一个非空值
                $find_first_valid = function (...$candidates) {
                    foreach ($candidates as $c) {
                        if (!empty($c) || $c === '0')
                            return $c;
                    }
                    return '';
                };

                // Alt: 手动 > Meta Alt > Meta Title > Meta Caption > Meta Description > Filename
                $final_alt = $find_first_valid(
                    isset($attrs['alt']) ? $attrs['alt'] : '',
                    $meta_alt,
                    $meta_title,
                    $meta_caption,
                    $meta_description,
                    $filename
                );

                // Title: 手动 > Meta Title > Meta Alt > Meta Caption > Meta Description > Filename
                $final_title = $find_first_valid(
                    isset($attrs['title']) ? $attrs['title'] : '',
                    $meta_title,
                    $meta_alt,
                    $meta_caption,
                    $meta_description,
                    $filename
                );

                if ($final_alt !== '') {
                    $img_attrs['alt'] = $final_alt;
                }

                if ($final_title !== '') {
                    $img_attrs['title'] = $final_title;
                }

                // Width/Height
                if (isset($attrs['width']))
                    $img_attrs['width'] = $attrs['width'];
                if (isset($attrs['height']))
                    $img_attrs['height'] = $attrs['height'];

                $img_html = todo_get_attachment_image((int) $id, $size, false, $img_attrs);
            }

            // 策略2: URL回退
            if (empty($img_html) && !empty($url)) {
                if (filter_var($url, FILTER_VALIDATE_URL) || strpos($url, '/') === 0) {
                    $img_tag = '<img src="' . htmlspecialchars($url) . '"';

                    // Attributes
                    $cls = (isset($attrs['class']) && $attrs['class'] !== '') ? $attrs['class'] : '';
                    $cls = trim($cls . ' item-' . $index);
                    $img_tag .= ' class="' . htmlspecialchars($cls) . '"';

                    foreach (['width', 'height'] as $k) {
                        if (isset($attrs[$k]))
                            $img_tag .= ' ' . $k . '="' . htmlspecialchars($attrs[$k]) . '"';
                    }

                    // Derive filename from URL for fallback
                    $url_path = parse_url($url, PHP_URL_PATH);
                    $url_filename = $url_path ? pathinfo($url_path, PATHINFO_FILENAME) : '';

                    // Alt: Manual > JSON Title > Filename
                    $alt_val = '';
                    if (isset($attrs['alt'])) {
                        $alt_val = $attrs['alt'];
                    } elseif (!empty($json_title)) {
                        $alt_val = $json_title;
                    } else {
                        $alt_val = $url_filename;
                    }
                    $img_tag .= ' alt="' . htmlspecialchars($alt_val) . '"';

                    // Title: Manual > JSON Title > Filename
                    $title_val = '';
                    if (isset($attrs['title'])) {
                        $title_val = $attrs['title'];
                    } elseif (!empty($json_title)) {
                        $title_val = $json_title;
                    } else {
                        $title_val = $url_filename;
                    }

                    if ($title_val !== '') {
                        $img_tag .= ' title="' . htmlspecialchars($title_val) . '"';
                    }

                    $img_tag .= ' />';
                    $img_html = $img_tag;
                }
            }

            if (!empty($img_html)) {
                $html_parts[] = $img_html;
            }
        }

        return implode("\n", $html_parts);
    }

    // ==================== 条件判断标签 ====================

    /**
     * 解析是否为首页标签
     */
    public function parse_if_home($attrs, $content, $data)
    {
        $is_true = (function_exists('todo_is_home') && todo_is_home()) || (function_exists('is_front_page') && is_front_page());
        $block = $content;
        if (strpos($content, '{else}') !== false) {
            $parts = explode('{else}', $content, 2);
            $block = $is_true ? $parts[0] : $parts[1];
        } elseif (!$is_true) {
            return '';
        }
        // parser instance
        return CMS_Template_Compiler_V2::render_string($block, $data);
    }

    /**
     * 解析是否为文章详情页标签
     */
    public function parse_if_single($attrs, $content, $data)
    {
        $is_true = (function_exists('todo_is_single') && todo_is_single()) || (function_exists('is_single') && is_single());
        $block = $content;
        if (strpos($content, '{else}') !== false) {
            $parts = explode('{else}', $content, 2);
            $block = $is_true ? $parts[0] : $parts[1];
        } elseif (!$is_true) {
            return '';
        }
        // parser instance
        return CMS_Template_Compiler_V2::render_string($block, $data);
    }

    /**
     * 解析是否为页面详情页标签
     */
    public function parse_if_page($attrs, $content, $data)
    {
        $is_true = (function_exists('todo_is_page') && todo_is_page()) || (function_exists('is_page') && is_page());
        $block = $content;
        if (strpos($content, '{else}') !== false) {
            $parts = explode('{else}', $content, 2);
            $block = $is_true ? $parts[0] : $parts[1];
        } elseif (!$is_true) {
            return '';
        }
        // parser instance
        return CMS_Template_Compiler_V2::render_string($block, $data);
    }

    // ==================== 包含标签 ====================

    /**
     * 解析包含标签
     */
    public function parse_include($attrs, $content, $data)
    {
        $file = isset($attrs['file']) ? trim((string) $attrs['file']) : '';
        if ($file === '') {
            return '';
        }

        if ($this->include_depth >= $this->max_include_depth) {
            return "<!-- Maximum include depth reached for {$file} -->";
        }

        $this->include_depth++;
        try {
            $theme_dir = get_template_directory();
            $maybe_path = $theme_dir . '/' . ltrim($file, '/');

            if (preg_match('/\\.(html|php)$/i', $file) && file_exists($maybe_path)) {
                $real_theme = realpath($theme_dir);
                $real_file = realpath($maybe_path);
                if ($real_theme && $real_file && strpos($real_file, $real_theme) === 0) {
                    if (preg_match('/\\.html$/i', $real_file)) {
                        // parser instance
                        return CMS_Template_Compiler_V2::parse_template_with_cache($real_file, $data);
                    }
                    if (preg_match('/\\.php$/i', $real_file)) {
                        ob_start();
                        if (is_array($data) && !empty($data)) {
                            extract($data, EXTR_SKIP);
                        }
                        include $real_file;
                        return ob_get_clean();
                    }
                }
            }

            if (function_exists('get_html_template_part')) {
                ob_start();
                get_html_template_part($file, is_array($data) ? $data : []);
                return ob_get_clean();
            }

            ob_start();
            get_template_part($file);
            return ob_get_clean();
        } finally {
            $this->include_depth--;
        }
    }

    public function parse_duration($attrs, $content, $data)
    {
        $seconds = 0;
        $raw_seconds = null;
        if (is_array($data)) {
            if (isset($data['media_duration_seconds']) && $data['media_duration_seconds'] !== '') {
                $raw_seconds = $data['media_duration_seconds'];
            } elseif (isset($data['duration_seconds']) && $data['duration_seconds'] !== '') {
                $raw_seconds = $data['duration_seconds'];
            }
        }
        if ($raw_seconds !== null && is_numeric($raw_seconds)) {
            $seconds = max(0, (int) $raw_seconds);
        }

        $dur = '';
        if ($seconds <= 0) {
            if (is_array($data) && isset($data['media_duration']) && is_string($data['media_duration'])) {
                $dur = trim($data['media_duration']);
            } elseif (is_array($data) && isset($data['duration']) && is_string($data['duration'])) {
                $dur = trim($data['duration']);
            }
            if ($dur !== '') {
                $parts = array_values(array_filter(explode(':', $dur), function ($v) {
                    return $v !== '';
                }));
                if (count($parts) === 2) {
                    $seconds = max(0, (int) $parts[0]) * 60 + max(0, (int) $parts[1]);
                } elseif (count($parts) === 3) {
                    $seconds = max(0, (int) $parts[0]) * 3600 + max(0, (int) $parts[1]) * 60 + max(0, (int) $parts[2]);
                }
            }
        }

        if ($seconds <= 0 && $dur !== '') {
            return $dur;
        }

        if ($seconds >= 3600) {
            $h = (int) floor($seconds / 3600);
            $m = (int) floor(($seconds % 3600) / 60);
            $s = (int) ($seconds % 60);
            return sprintf('%02d:%02d:%02d', $h, $m, $s);
        }
        $m = (int) floor($seconds / 60);
        $s = (int) ($seconds % 60);
        return sprintf('%02d:%02d', $m, $s);
    }

    public function parse_days_left($attrs, $content, $data)
    {
        $deadline = '';
        if (is_array($data) && isset($data['deadline'])) {
            $deadline = is_scalar($data['deadline']) ? (string) $data['deadline'] : '';
        }
        $deadline = trim($deadline);
        if ($deadline === '') {
            return '0';
        }
        $ts = strtotime($deadline);
        if (!$ts) {
            return '0';
        }
        $now = time();
        $diff = $ts - $now;
        if ($diff <= 0) {
            return '0';
        }
        return (string) (int) ceil($diff / 86400);
    }

    public function parse_content_count($attrs, $content, $data)
    {
        $raw = '';
        if (is_array($data) && isset($data['related_posts'])) {
            if (is_array($data['related_posts'])) {
                $ids = $data['related_posts'];
            } else {
                $raw = is_scalar($data['related_posts']) ? (string) $data['related_posts'] : '';
                $ids = [];
                $t = trim($raw);
                if ($t !== '') {
                    $decoded = json_decode($t, true);
                    if (is_array($decoded)) {
                        $ids = $decoded;
                    } else {
                        $parts = preg_split('/[\r\n,]+/', $t);
                        $ids = $parts;
                    }
                }
            }
        } else {
            $ids = [];
        }
        $post_ids = [];
        foreach ((array) $ids as $v) {
            if (is_numeric($v)) {
                $post_ids[] = (int) $v;
            }
        }
        $post_ids = array_values(array_unique(array_filter($post_ids, function ($v) {
            return $v > 0;
        })));
        return (string) count($post_ids);
    }

    public function parse_media_type_name($attrs, $content, $data)
    {
        $t = '';
        if (is_array($data) && isset($data['media_type'])) {
            $t = is_scalar($data['media_type']) ? (string) $data['media_type'] : '';
        }
        $t = trim($t);
        if ($t === 'audio') {
            return '音频';
        }
        if ($t === 'video') {
            return '视频';
        }
        return $t;
    }

    public function parse_apply_count($attrs, $content, $data)
    {
        global $post;
        $post_id = 0;
        if (is_array($data) && isset($data['post_id'])) {
            $post_id = intval($data['post_id']);
        } elseif ($post && isset($post->ID)) {
            $post_id = intval($post->ID);
        }
        if ($post_id <= 0) {
            return '0';
        }

        static $cache = [];
        if (isset($cache[$post_id])) {
            return (string) $cache[$post_id];
        }

        try {
            if (!isset($GLOBALS['tododb'])) {
                $cache[$post_id] = 0;
                return '0';
            }
            $db = $GLOBALS['tododb'];
            $table = $db->prefix . 'recruit_applications';
            $exists = false;
            try {
                if (function_exists('table_exists')) {
                    $exists = table_exists($table);
                } elseif (preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                    $exists = (bool) $db->get_var("SHOW TABLES LIKE '{$table}'");
                }
            } catch (Throwable $e) {
                $exists = false;
            }
            if (!$exists) {
                $cache[$post_id] = 0;
                return '0';
            }

            $count = $db->get_var("SELECT COUNT(*) FROM {$table} WHERE post_id = ? AND status <> 'deleted'", [$post_id]);
            $count = $count ? intval($count) : 0;
            $cache[$post_id] = $count;
            return (string) $count;
        } catch (Throwable $e) {
            $cache[$post_id] = 0;
            return '0';
        }
    }

    /**
     * 解析头部标签
     */
    public function parse_header($attrs, $content, $data)
    {
        ob_start();
        get_header(isset($attrs['name']) ? $attrs['name'] : null);
        return ob_get_clean();
    }

    /**
     * 解析底部标签
     */
    public function parse_footer($attrs, $content, $data)
    {
        ob_start();
        get_footer(isset($attrs['name']) ? $attrs['name'] : null);
        return ob_get_clean();
    }

    /**
     * 解析侧边栏标签
     */
    public function parse_sidebar($attrs, $content, $data)
    {
        ob_start();
        get_sidebar(isset($attrs['name']) ? $attrs['name'] : null);
        return ob_get_clean();
    }

    // ==================== 自定义字段 ====================

    /**
     * 解析自定义字段标签
     */
    public function parse_custom_field($attrs, $content, $data)
    {
        global $post;
        $field_name = isset($attrs['name']) ? (string) $attrs['name'] : '';
        $default = isset($attrs['default']) ? (string) $attrs['default'] : '';

        if ($field_name === '') {
            return $default;
        }

        if (is_array($data) && isset($data['post']) && is_array($data['post']) && isset($data['post']['custom_fields']) && is_array($data['post']['custom_fields']) && array_key_exists($field_name, $data['post']['custom_fields'])) {
            $v = $data['post']['custom_fields'][$field_name];
            if (is_scalar($v) || $v === null) {
                return (string) $v;
            }
            return $v;
        }

        if ($post && isset($post->ID)) {
            $value = get_post_meta($post->ID, $field_name, true);
            if ($value !== '' && $value !== null) {
                return $value;
            }

            try {
                $post_id = intval($post->ID);
                $model_key = isset($post->post_type) ? (string) $post->post_type : (function_exists('get_post_type') ? (string) get_post_type($post_id) : '');
                if ($post_id > 0 && $model_key !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $model_key) && preg_match('/^[a-zA-Z0-9_]+$/', $field_name)) {
                    static $cache = [];
                    if (!isset($cache[$post_id])) {
                        $cache[$post_id] = [];
                    }

                    if (!isset($cache[$post_id]['model_fields'])) {
                        $cache[$post_id]['model_fields'] = [];
                        if (isset($GLOBALS['tododb'])) {
                            $db = $GLOBALS['tododb'];
                            $table = $db->prefix . 'model_fields_' . $model_key;
                            $exists = false;
                            if (function_exists('table_exists')) {
                                $exists = table_exists($table);
                            } elseif (preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                                $exists = (bool) $db->get_var("SHOW TABLES LIKE '{$table}'");
                            }
                            if ($exists) {
                                $row = $db->get_row("SELECT * FROM `{$table}` WHERE post_id = ? LIMIT 1", [$post_id], 'ARRAY_A');
                                if (is_object($row)) {
                                    $row = (array) $row;
                                }
                                if (is_array($row)) {
                                    foreach ($row as $k => $v) {
                                        if (in_array($k, ['field_id', 'post_id', 'created_at', 'updated_at'], true)) {
                                            continue;
                                        }
                                        $cache[$post_id]['model_fields'][$k] = maybe_unserialize($v);
                                    }
                                }
                            }
                        }
                    }
                    if (array_key_exists($field_name, $cache[$post_id]['model_fields'])) {
                        $v = $cache[$post_id]['model_fields'][$field_name];
                        if (is_scalar($v) || $v === null) {
                            return (string) $v;
                        }
                    }

                    if (!isset($cache[$post_id]['custom_fields'])) {
                        $cache[$post_id]['custom_fields'] = [];
                        $model_id = (class_exists('Todo_Model_Manager') && method_exists('Todo_Model_Manager', 'get_model_id_by_key')) ? (int) Todo_Model_Manager::get_model_id_by_key($model_key) : 0;
                        if ($model_id > 0 && isset($GLOBALS['tododb'])) {
                            $db = $GLOBALS['tododb'];
                            $rows = $db->get_results("SELECT field_key, field_value FROM {$db->prefix}post_custom_fields WHERE post_id = ? AND model_id = ?", [$post_id, $model_id], 'ARRAY_A');
                            if (is_array($rows)) {
                                foreach ($rows as $r) {
                                    if (is_array($r) && isset($r['field_key'])) {
                                        $cache[$post_id]['custom_fields'][(string) $r['field_key']] = maybe_unserialize($r['field_value'] ?? '');
                                    }
                                }
                            }
                        }
                    }
                    if (array_key_exists($field_name, $cache[$post_id]['custom_fields'])) {
                        $v = $cache[$post_id]['custom_fields'][$field_name];
                        if (is_scalar($v) || $v === null) {
                            return (string) $v;
                        }
                    }
                }
            } catch (Throwable $e) {
            }

            return $default;
        }

        return $default;
    }

    /**
     * 解析全局变量标签 {{todo:global_var name="lei_zi"}}
     *
     * @param array  $attrs   属性（name / key / default 等）
     * @param string $content 内容体（未使用）
     * @param array  $data    模板数据
     * @return string
     */
    public function parse_global_var($attrs, $content, $data)
    {
        $name = '';
        if (!empty($attrs['name'])) {
            $name = (string) $attrs['name'];
        } elseif (!empty($attrs['key'])) {
            $name = (string) $attrs['key'];
        }

        if ($name === '') {
            return isset($attrs['default']) ? (string) $attrs['default'] : '';
        }

        // 支持点语法，例如 tu_chuan.0.url
        $sub_keys = array();
        if (strpos($name, '.') !== false) {
            $parts = explode('.', $name);
            $name = array_shift($parts);
            $sub_keys = $parts;
        }

        $global_vars = get_option('global_vars', array());
        if (!is_array($global_vars)) {
            $global_vars = array();
        }

        if (array_key_exists($name, $global_vars)) {
            $value = $global_vars[$name];

            // 处理 index 属性（如 index=0）与 field 属性（如 field="url"）
            if (isset($attrs['index']) && is_numeric($attrs['index'])) {
                $sub_keys[] = (int) $attrs['index'];
            }
            if (isset($attrs['field']) && is_string($attrs['field']) && $attrs['field'] !== '') {
                $sub_keys[] = $attrs['field'];
            }

            // 仅在显式提取深层子字段或属于 JSON 图集/地区代码时才从 enrich 变体解包
            if (!empty($sub_keys) || (is_string($value) && (strpos($value, '[') === 0 || strpos($value, '{') === 0))) {
                $temp_ctx = [];
                self::enrich_field_variants($temp_ctx, $name, $value, 'global_');
                if (isset($temp_ctx[$name . '_images'])) {
                    $value = $temp_ctx[$name . '_images'];
                } elseif (isset($temp_ctx[$name . '_display'])) {
                    $value = $temp_ctx[$name . '_display'];
                } elseif (is_string($value) && (strpos($value, '[') === 0 || strpos($value, '{') === 0)) {
                    $decoded = json_decode($value, true);
                    if (is_array($decoded)) {
                        $value = $decoded;
                    }
                }
            }

            // 穿透点语法 / 字段深入提取
            if (!empty($sub_keys) && (is_array($value) || is_object($value))) {
                foreach ($sub_keys as $sk) {
                    if (is_array($value) && array_key_exists($sk, $value)) {
                        $value = $value[$sk];
                    } elseif (is_object($value) && isset($value->$sk)) {
                        $value = $value->$sk;
                    } else {
                        $value = null;
                        break;
                    }
                }
            }

            // 处理最终结果
            if (is_array($value)) {
                // 如果是块标签模式（$content非空），循环渲染内容块
                if (!empty($content)) {
                    $out = '';
                    $index = 0;
                    foreach ($value as $item) {
                        $item_ctx = is_array($item) ? $item : array('value' => $item);
                        if (isset($item_ctx['url']) && function_exists('todo_media_url_to_runtime_absolute')) {
                            $item_ctx['url'] = todo_media_url_to_runtime_absolute($item_ctx['url']);
                        }
                        $item_ctx['index'] = $index;
                        $item_ctx['index_1'] = $index + 1;
                        $merged_data = array_merge(is_array($data) ? $data : array(), $item_ctx);
                        $out .= class_exists('CMS_Template_Compiler_V2') ? CMS_Template_Compiler_V2::render($content, $merged_data) : $content;
                        $index++;
                    }
                    return $out;
                }
                if (!empty($attrs['raw']) || (!empty($attrs['format']) && $attrs['format'] === 'json')) {
                    return json_encode($value, JSON_UNESCAPED_UNICODE);
                }
                return json_encode($value, JSON_UNESCAPED_UNICODE);
            }

            if (is_string($value) && $value !== '') {
                if (strpos($value, '<') !== false && (strpos($value, '<img') !== false || strpos($value, '<a') !== false || strpos($value, '<p') !== false || strpos($value, '<div') !== false)) {
                    if (function_exists('todo_normalize_content_root_relative_urls')) {
                        return todo_normalize_content_root_relative_urls($value);
                    }
                }
                if (strpos($value, '[') === 0 || strpos($value, '{') === 0) {
                    return (string) $value;
                }
                if (function_exists('todo_media_url_to_runtime_absolute') && (strpos($value, '/content/') !== false || strpos($value, 'content/') !== false)) {
                    return todo_media_url_to_runtime_absolute($value);
                }
                return (string) $value;
            }

            if (is_scalar($value) || $value === null) {
                return (string) $value;
            }
            return (string) $value;
        }

        return isset($attrs['default']) ? (string) $attrs['default'] : '';
    }

    public function parse_posts($attrs, $content, $data)
    {
        return $this->parse_articles($attrs, $content, $data);
    }

    /**
     * 解析文章标签列表 {{todo:tags}}
     *
     * 无内容体时：返回数组（用于 {{todo:if tags}} / {{todo:loop tags}}）。
     * 有内容体 + 文章上下文：渲染当前文章的标签。
     * 有内容体 + limit/order 参数：全局标签云，渲染所有标签。
     */
    public function parse_tags($attrs, $content, $data)
    {
        if (isset($attrs['id'])) {
            return $this->render_category_info('tags', $attrs, $content, $data);
        }

        // 全局标签云模式（有 limit/order 参数 or 无文章上下文）
        $is_cloud = isset($attrs['limit']) || isset($attrs['order']);
        $ctx = $this->get_post_context($data);
        $post_id = $ctx['id'];

        // 支持 per_page 分页参数
        $per_page = isset($attrs['per_page']) ? intval($attrs['per_page']) : 0;

        // 获取标签数据
        if ($is_cloud || $post_id <= 0) {
            if ($per_page > 0 && !isset($attrs['limit'])) {
                // 分页时不设 LIMIT，获取全量数据后在 PHP 层分页
                $attrs['limit'] = 99999;
            }
            $terms = $this->get_all_tags($attrs);
        } else {
            $terms = $this->get_post_tags_terms($post_id);
        }

        if (empty($terms)) {
            return $content !== null ? '' : [];
        }

        $result = [];
        foreach ($terms as $i => $term) {
            $term_id   = is_object($term) ? (int) $term->term_id : (int) ($term['tag_id'] ?? $term['term_id'] ?? 0);
            $term_name = is_object($term) ? (string) $term->name  : (string) ($term['tag_title'] ?? $term['tag_name'] ?? $term['name'] ?? '');
            $term_url  = '';
            if (function_exists('todo_get_tag_link')) {
                $term_url = (string) todo_get_tag_link($term_id);
            } elseif (function_exists('get_term_link')) {
                $link = get_term_link($term_id, 'post_tag');
                $term_url = is_string($link) ? $link : '';
            }
            $result[] = [
                'id'    => $term_id,
                'name'      => $term_name,
                'url'       => $term_url,
                'articles_count' => is_object($term) ? (int) ($term->count ?? $term->articles_count ?? 0) : (int) ($term['count'] ?? $term['articles_count'] ?? 0),
                'tag_color'  => is_object($term) ? (string) ($term->tag_color ?? '') : (string) ($term['tag_color'] ?? ''),
                'tag_slug'   => is_object($term) ? (string) ($term->tag_slug ?? '') : (string) ($term['tag_slug'] ?? ''),
                'description' => is_object($term) ? (string) ($term->description ?? '') : (string) ($term['description'] ?? ''),
            ];
        }

        // 分页处理
        if ($per_page > 0) {
            $total_items = count($result);
            $total_pages = max(1, (int) ceil($total_items / $per_page));
            $current_page = max(1, isset($_GET['paged']) ? intval($_GET['paged']) : 1);
            $current_page = min($current_page, $total_pages);
            $offset = ($current_page - 1) * $per_page;

            $result = array_slice($result, $offset, $per_page);

            // 设置分页数据，供模板中 {{todo:if pagination}} / {{todo:pagination_*}} 使用
            $base_url = function_exists('todo_get_current_url') ? todo_get_current_url() : (function_exists('todo_home_url') ? todo_home_url('/') : '/');
            $pagination = array();
            $pagination['pagination'] = ($total_pages > 1);
            $pagination['pagination_current_page'] = $current_page;
            $pagination['pagination_total_pages'] = $total_pages;
            $pagination['pagination_total_items'] = $total_items;
            $pagination['pagination_per_page'] = $per_page;
            $pagination['pagination_prev_page'] = ($current_page > 1) ? $current_page - 1 : 0;
            $pagination['pagination_next_page'] = ($current_page < $total_pages) ? $current_page + 1 : 0;
            $pagination['pagination_prev_url'] = ($pagination['pagination_prev_page'] > 0)
                ? CMS_Template_Compiler_V2::build_paginated_url($pagination['pagination_prev_page'], $base_url, 'paged')
                : '';
            $pagination['pagination_next_url'] = ($pagination['pagination_next_page'] > 0)
                ? CMS_Template_Compiler_V2::build_paginated_url($pagination['pagination_next_page'], $base_url, 'paged')
                : '';
            $pagination['pagination_pages'] = CMS_Template_Compiler_V2::build_pagination_pages(
                $current_page,
                $total_pages,
                $base_url,
                'paged',
                2,
                1
            );
            CMS_Template_Compiler_V2::$last_loop_pagination = $pagination;
        }

        // 无内容体：返回数组供 {{todo:loop tags}} / {{todo:if tags}} 使用
        if ($content === null) {
            return $result;
        }

        // 有内容体：渲染每个标签的 HTML
        $out = '';
        $index = 0;
        foreach ($result as $item) {
            $index++;
            $item_data = array_merge(isset($data) && is_array($data) ? $data : [], $item);
            $item_data['index'] = $index;
            $out .= $this->parse_conditional_content($content, $item_data);
        }
        return $out;
    }

    /**
     * 获取文章关联的标签
     */
    private function get_post_tags_terms($post_id)
    {
        $terms = [];
        if (function_exists('get_the_tags')) {
            $raw = get_the_tags($post_id);
            if (is_array($raw)) {
                $terms = $raw;
            }
        } elseif (function_exists('get_the_terms')) {
            $raw = get_the_terms($post_id, 'post_tag');
            if (is_array($raw)) {
                $terms = $raw;
            }
        }
        return $terms;
    }

    /**
     * 获取全局标签列表（用于标签云）
     */
    private function get_all_tags($attrs)
    {
        global $tododb;
        if (!is_object($tododb)) return [];

        $limit = isset($attrs['limit']) ? intval($attrs['limit']) : 20;
        $order = isset($attrs['order']) ? (string) $attrs['order'] : 'tag_title ASC';

        // 只允许安全的排序字段
        $allowed = ['tag_title', 'tag_order', 'articles_count', 'tag_id'];
        $order_parts = explode(' ', trim($order));
        $order_field = strtolower($order_parts[0]);
        $order_dir = isset($order_parts[1]) && strtoupper($order_parts[1]) === 'ASC' ? 'ASC' : 'DESC';
        if (!in_array($order_field, $allowed, true)) {
            $order_field = 'tag_title';
        }

        $sql = "SELECT t.tag_id, t.tag_title AS name, t.tag_title AS tag_title,
                       t.tag_color, t.tag_slug, t.tag_desc AS description,
                       COALESCE(pt.cnt, 0) AS articles_count, COALESCE(pt.cnt, 0) AS `count`
                FROM {$tododb->prefix}tags t
                LEFT JOIN (
                    SELECT tag_id, COUNT(*) AS cnt
                    FROM {$tododb->prefix}post_tags
                    GROUP BY tag_id
                ) pt ON t.tag_id = pt.tag_id
                WHERE t.tag_status = 'active'
                ORDER BY {$order_field} {$order_dir}
                LIMIT " . intval($limit);

        return $tododb->get_results($sql, [], 'ARRAY_A') ?: [];
    }

    /**
     * 解析摘要标签 {{todo:excerpt}}
     *
     * 从当前文章上下文中提取摘要。
     * 先解码 HTML 实体（数据库存储的内容可能为实体编码），
     * 再剥离所有 HTML 标签，最终输出纯文字。
     */
    public function parse_excerpt($attrs, $content, $data)
    {
        $length = isset($attrs['length']) ? intval($attrs['length']) : 150;

        if (is_array($data)) {
            $direct_excerpt = $data['excerpt'] ?? ($data['summary'] ?? ($data['post_excerpt'] ?? null));
            if ($direct_excerpt !== null && $direct_excerpt !== '') {
                $txt = html_entity_decode((string)$direct_excerpt, ENT_QUOTES, 'UTF-8');
                $txt = strip_tags($txt);
                $txt = trim($txt);
                if ($length > 0 && mb_strlen($txt) > $length) {
                    $txt = mb_substr($txt, 0, $length) . '…';
                }
                return $txt;
            }
        }

        $ctx    = $this->get_post_context($data);
        $post_obj = $ctx['obj'];
        $post_id  = $ctx['id'];

        if ($post_id <= 0) {
            return '';
        }

        // 优先使用统一的摘要获取函数（已包含 entity_decode + strip_tags）
        if (function_exists('todo_get_the_excerpt')) {
            return todo_get_the_excerpt($length, $post_obj ?: $post_id);
        }

        // 降级：手动处理
        $raw = '';
        if (is_object($post_obj)) {
            if (!empty($post_obj->post_excerpt)) {
                $raw = (string) $post_obj->post_excerpt;
            } elseif (!empty($post_obj->excerpt)) {
                $raw = (string) $post_obj->excerpt;
            } elseif (!empty($post_obj->post_content)) {
                $raw = (string) $post_obj->post_content;
            }
        }

        // 解码实体 → 剥离 HTML → 截断
        $txt = html_entity_decode($raw, ENT_QUOTES, 'UTF-8');
        $txt = strip_tags($txt);
        $txt = trim($txt);
        if (mb_strlen($txt) > $length) {
            $txt = mb_substr($txt, 0, $length) . '…';
        }
        return $txt;
    }

    /**
     * 获取留言模型表单字段定义（用于前端动态渲染表单）
     * @param array $attrs 属性数组
     * @param string $content 标签内容
     * @param array $data 数据上下文
     * @return array 字段定义数组
     */
    public function parse_guestbook_form_fields($attrs, $content, $data)
    {
        $fields = [];
        if (class_exists('Todo_Model_Manager') && isset($GLOBALS['tododb'])) {
            $db = $GLOBALS['tododb'];
            $model_id = Todo_Model_Manager::get_model_id_by_key('guestbook');
            if ($model_id) {
                try {
                    // 获取当前分类 ID
                    $cat_id = 0;
                    if (!empty($attrs['cat_id']) && is_numeric($attrs['cat_id'])) {
                        $cat_id = (int)$attrs['cat_id'];
                    } elseif (function_exists('get_current_category_id')) {
                        $cat_id = (int)get_current_category_id();
                    }

                    if ($cat_id > 0) {
                        $sql = "SELECT DISTINCT mf.* FROM " . $db->prefix . "model_fields mf
                                INNER JOIN " . $db->prefix . "term_field_visibility tfv ON mf.field_id = tfv.field_id
                                WHERE mf.model_id = ? AND mf.field_status = 'active'
                                  AND tfv.term_id = ? AND tfv.is_visible = 1
                                ORDER BY mf.field_order ASC";
                        $custom_rows = $db->get_results($sql, [$model_id, $cat_id]);
                    } else {
                        // 无分类 ID 时只取非分类专属且没有绑定专门分类记录的公共字段
                        $sql = "SELECT mf.* FROM " . $db->prefix . "model_fields mf
                                LEFT JOIN " . $db->prefix . "term_field_visibility tfv ON mf.field_id = tfv.field_id
                                WHERE mf.model_id = ? AND mf.field_status = 'active' AND mf.category_only = 0 AND tfv.field_id IS NULL
                                ORDER BY mf.field_order ASC";
                        $custom_rows = $db->get_results($sql, [$model_id]);
                    }
                    if (is_array($custom_rows)) {
                        foreach ($custom_rows as $row) {
                            $row = is_object($row) ? (array)$row : $row;
                            $field_options = [];
                            if (!empty($row['field_options'])) {
                                $raw = $row['field_options'];
                                // 1. 先尝试 JSON
                                $decoded = json_decode($raw, true);
                                if (is_array($decoded)) {
                                    $field_options = $decoded;
                                }
                                // 2. 再尝试 PHP 序列化（后台 select/radio/checkbox 常用格式）
                                if (empty($field_options)) {
                                    $unser = function_exists('maybe_unserialize') ? maybe_unserialize($raw) : @unserialize($raw);
                                    if (is_array($unser)) {
                                        $field_options = $unser;
                                    }
                                }
                                // 3. 兜底：纯文本换行格式 "1|好\n2|很好"
                                if (empty($field_options)) {
                                    $field_options['options'] = $raw;
                                }
                                // 4. 如果 options 键的值仍是换行文本，解析为数组
                                if (isset($field_options['options']) && is_string($field_options['options'])) {
                                    $field_options['options'] = self::parse_options_text($field_options['options']);
                                }
                            }

                            // 动态获取后端允许的上传扩展名及MIME映射，全部由后端配置统一管理
                            $backend_allowed_exts = function_exists('todo_get_allowed_extensions')
                                ? todo_get_allowed_extensions()
                                : (class_exists('TodoUploadConfig') ? TodoUploadConfig::get_allowed_extensions() : []);
                            
                            $target_ft = $field_options['file_type'] ?? 'any';
                            if ($target_ft === 'image') {
                                $image_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico'];
                                $field_allowed = array_values(array_intersect($backend_allowed_exts, $image_exts));
                            } else {
                                $field_allowed = $backend_allowed_exts;
                            }

                            $mime_map = function_exists('todo_supported_mime_types_flat') ? todo_supported_mime_types_flat() : [];
                            $accept_mimes = [];
                            foreach ($field_allowed as $e) {
                                if (isset($mime_map[$e])) {
                                    $accept_mimes[] = $mime_map[$e];
                                }
                            }

                            $fields[] = [
                                'field_name'         => $row['field_key'] ?? '',
                                'field_title'        => $row['field_name'] ?? $row['field_label'] ?? '',
                                'field_type'         => $row['field_type'] ?? 'text',
                                'field_required'     => !empty($row['is_required']),
                                'field_placeholder'  => $field_options['placeholder'] ?? '',
                                'field_description'  => $row['field_description'] ?? '',
                                'field_options'      => self::normalize_field_options($field_options['options'] ?? []),
                                'field_max_files'    => (int)($field_options['max_files'] ?? 10),
                                'field_file_type'    => $target_ft,
                                'field_allowed_exts' => implode(',', $field_allowed),
                                'field_accept'       => implode(',', array_unique($accept_mimes)),
                                'field_order'        => (int)($row['field_order'] ?? 100),
                            ];
                        }
                    }
                } catch (Throwable $e) {
                    // 静默失败
                }
            }
        }

        // 按 field_order 排序
        usort($fields, function ($a, $b) {
            return $a['field_order'] - $b['field_order'];
        });

        return $fields;
    }

    /**
     * 解析换行文本格式的选项为 ['key' => 'label'] 数组
     * 输入: "1|好\n2|很好\n3|非常好"
     * 输出: ['1' => '好', '2' => '很好', '3' => '非常好']
     *
     * @param string $text 原始选项文本
     * @return array
     */
    private static function parse_options_text($text)
    {
        $options = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($text));
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (strpos($line, '|') !== false) {
                list($val, $label) = explode('|', $line, 2);
                $options[trim($val)] = trim($label);
            } else {
                $options[$line] = $line;
            }
        }
        return $options;
    }

    /**
     * 规范化字段选项数组为模板可用的格式
     * 将 ['key' => 'label'] 格式转为 [['option_value' => 'key', 'option_label' => 'label']]
     *
     * @param array $options 原始选项数组
     * @return array 规范化后的选项数组
     */
    private static function normalize_field_options($options)
    {
        if (empty($options) || !is_array($options)) {
            return [];
        }

        // 已经是规范格式则直接返回
        if (isset($options[0]) && is_array($options[0]) && isset($options[0]['option_value'])) {
            return $options;
        }

        $normalized = [];
        foreach ($options as $key => $label) {
            $normalized[] = [
                'option_value' => $key,
                'option_label' => $label,
            ];
        }
        return $normalized;
    }

    // ==================== 超级聚合筛选导航 (super_nav) ====================

    /**
     * 超级聚合筛选导航标签 {{todo:super_nav}}
     * 
     * 渲染全展开的行内标签筛选 UI，级联检索：
     *   大类（分类）→ 小类（标签）→ 属性（doc-properties）
     * 
     * 点击任意标签切换 URL 参数（?category_id=X&tag=Y&property=Z），
     * 配合聚合筛选上下文自动驱动循环体数据。
     * 选中项高亮，大类联动小类。
     * 
     * 支持属性：
     *   model           - 限定模型（如 products），只显示该模型下的分类/标签
     *   show_properties - 是否显示属性行（默认 1）
     *   show_tags       - 是否显示小类/标签行（默认 1）
     *   no_tags         - 排除的标签 ID，逗号或空格分隔（如 "3,8" 或 "3 8"）
     *   no_properties   - 排除的属性 ID，逗号或空格分隔（如 "1,4" 或 "1 4"）
     *   no_cats         - 排除的分类 ID，逗号或空格分隔（如 "11" 或 "10,12"）
     *   all_label       - "全部"标签文字（默认"全部"）
     */
    public function parse_super_nav($attrs, $content, $data)
    {
        global $tododb;
        if (!$tododb) return '';

        // 0. 解析当前站点 ID (site_id)
        $current_site_id = 0;
        if (isset($attrs['site_id']) && is_numeric($attrs['site_id']) && intval($attrs['site_id']) > 0) {
            $current_site_id = intval($attrs['site_id']);
        } elseif (is_array($data) && !empty($data['site_id'])) {
            $current_site_id = intval($data['site_id']);
        } elseif (function_exists('todo_current_site_id')) {
            $current_site_id = (int) todo_current_site_id(true);
        }

        // 分类站点隔离 SQL 片段 (site_terms)
        $cat_site_join = '';
        $cat_site_where = '';
        if ($current_site_id > 0 && function_exists('todo_site_terms_table_exists') && todo_site_terms_table_exists()) {
            $st_table = $tododb->prefix . 'site_terms';
            $cat_site_join = " LEFT JOIN {$st_table} st_cat ON (tt.term_id = st_cat.term_id AND st_cat.site_id = {$current_site_id})";
            $cat_site_where = " AND ((st_cat.term_id IS NOT NULL AND st_cat.is_visible = 1) OR NOT EXISTS (SELECT 1 FROM {$st_table} stx WHERE stx.term_id = tt.term_id AND stx.site_id <> 0))";
        }

        // 1. 确定传入的基准分类 ID (base_cat_id)：显式参数 id / category_id / cat / cat_id
        $base_cat_id = 0;
        $base_cat_val = $attrs['id'] ?? ($attrs['category_id'] ?? ($attrs['cat'] ?? ($attrs['cat_id'] ?? 0)));
        if (is_numeric($base_cat_val) && intval($base_cat_val) > 0) {
            $base_cat_id = intval($base_cat_val);
        }

        // 2. 获取当前 URL 选中的各个参数
        $url_cat_id = 0;
        $url_cat_param_key = 'category_id';
        if (!empty($_GET['category_id']) && is_numeric($_GET['category_id']) && intval($_GET['category_id']) > 0) {
            $url_cat_id = intval($_GET['category_id']);
            $url_cat_param_key = 'category_id';
        } elseif (!empty($_GET['cat']) && is_numeric($_GET['cat']) && intval($_GET['cat']) > 0) {
            $url_cat_id = intval($_GET['cat']);
            $url_cat_param_key = 'cat';
        } elseif (!empty($_GET['cat_id']) && is_numeric($_GET['cat_id']) && intval($_GET['cat_id']) > 0) {
            $url_cat_id = intval($_GET['cat_id']);
            $url_cat_param_key = 'cat_id';
        }
        if ($url_cat_id <= 0 && !empty($_GET['category']) && is_string($_GET['category']) && function_exists('get_category_by_slug')) {
            $c = get_category_by_slug(sanitize_title($_GET['category']));
            if (is_object($c) && isset($c->term_id)) {
                $url_cat_id = intval($c->term_id);
                $url_cat_param_key = 'category';
            }
        }

        // 确定上下文分类上下文
        $context_cat_id = $base_cat_id > 0 ? $base_cat_id : $url_cat_id;
        if ($context_cat_id <= 0 && is_array($data) && !empty($data['category_id'])) {
            $context_cat_id = intval($data['category_id']);
        }
        if ($context_cat_id <= 0 && function_exists('get_current_category_id')) {
            $context_cat_id = intval(get_current_category_id());
        }

        // 当前激活的分类 ID (用于高亮大类菜单)
        $active_cat_id = $url_cat_id > 0 ? $url_cat_id : 0;

        $current_tag = '';
        if (!empty($_GET['tag']) && is_string($_GET['tag'])) $current_tag = sanitize_text_field($_GET['tag']);
        elseif (!empty($_GET['tag_id']) && is_numeric($_GET['tag_id'])) $current_tag = intval($_GET['tag_id']);

        $current_prop = '';
        if (!empty($_GET['doc_properties']) && is_string($_GET['doc_properties'])) $current_prop = sanitize_text_field($_GET['doc_properties']);
        elseif (!empty($_GET['property']) && is_string($_GET['property'])) $current_prop = sanitize_text_field($_GET['property']);

        $show_tags       = !isset($attrs['show_tags']) || $attrs['show_tags'];
        $show_properties = !isset($attrs['show_properties']) || $attrs['show_properties'];
        $model_key       = isset($attrs['model']) ? sanitize_key($attrs['model']) : '';
        $all_label       = isset($attrs['all_label']) ? $attrs['all_label'] : __('全部');
        
        // 多语言翻译辅助闭包（带 $O(1)$ 内存缓存，支持内联标记解析及字典匹配）
        $trans_cache = [];
        $t = function ($str) use (&$trans_cache) {
            if ($str === '' || $str === null) return '';
            if (isset($trans_cache[$str])) return $trans_cache[$str];
            $res = $str;
            if (function_exists('todo_parse_inline_translations')) {
                $res = todo_parse_inline_translations($res);
            }
            if (function_exists('todo_translate')) {
                $res = todo_translate($res);
            } elseif (function_exists('__')) {
                $res = __($res, 'todocms');
            }
            return $trans_cache[$str] = $res;
        };

        // 排除参数：no_tags / no_properties 支持逗号或空格分隔
        $no_tags = [];
        if (!empty($attrs['no_tags'])) {
            $raw = str_replace(',', ' ', (string) $attrs['no_tags']);
            foreach (preg_split('/\s+/', $raw) as $p) { if (is_numeric($p) && intval($p) > 0) $no_tags[] = intval($p); }
        }
        $no_props = [];
        if (!empty($attrs['no_properties'])) {
            $raw = str_replace(',', ' ', (string) $attrs['no_properties']);
            foreach (preg_split('/\s+/', $raw) as $p) { if (is_numeric($p) && intval($p) > 0) $no_props[] = intval($p); }
        }
        $no_cats = [];
        if (!empty($attrs['no_cats'])) {
            $raw = str_replace(',', ' ', (string) $attrs['no_cats']);
            foreach (preg_split('/\s+/', $raw) as $p) { if (is_numeric($p) && intval($p) > 0) $no_cats[] = intval($p); }
        }

        // 分类排除 SQL 片段
        $cat_exclude_sql = !empty($no_cats) ? " AND tt.term_id NOT IN (" . implode(',', $no_cats) . ")" : '';

        // 3. 模型 ID
        $mid = 0;
        if ($model_key !== '' && class_exists('Todo_Model_Manager')) {
            $mid = Todo_Model_Manager::get_model_id_by_key($model_key) ?: 0;
        }
        if ($mid <= 0 && is_array($data) && !empty($data['model_id'])) $mid = intval($data['model_id']);

        // 4. URL 构建函数
        $buildUrl = function ($cat_id = -1, $tag = '', $prop = '') use ($base_cat_id, $context_cat_id, &$parent_cat_id, $url_cat_param_key) {
            $params = [];
            foreach (['per_page', 'sort', 'view', 'order'] as $k) {
                if (!empty($_GET[$k])) $params[$k] = $_GET[$k];
            }

            // 分类参数确定：
            // $cat_id > 0: 选中某个具体子/同级分类
            // $cat_id === 0: 点击大类中的“全部”，回到父级分类 parent_cat_id
            // $cat_id === -1: 保持当前选中的分类
            $target_cat_id = 0;
            if ($cat_id > 0) {
                $target_cat_id = $cat_id;
            } elseif ($cat_id === 0) {
                $target_cat_id = isset($parent_cat_id) && $parent_cat_id > 0 ? $parent_cat_id : ($base_cat_id > 0 ? $base_cat_id : $context_cat_id);
            } else {
                $target_cat_id = $context_cat_id;
            }

            if ($target_cat_id > 0) {
                $params[$url_cat_param_key] = $target_cat_id;
            }

            if ($tag !== '') $params['tag'] = $tag;
            if ($prop !== '') $params['doc_properties'] = $prop;

            $base = function_exists('todo_home_url') ? todo_home_url('/') : '/';
            
            // 多语言路径前缀支持 (例如 /en/)
            $langPrefix = '';
            if (class_exists('LanguageRegistry') && class_exists('LocaleContext')) {
                $locale = LocaleContext::get_instance()->get_locale();
                $langInfo = LanguageRegistry::get_instance()->language_by_locale($locale);
                if ($langInfo && !empty($langInfo['path_prefix'])) {
                    $langPrefix = '/' . trim($langInfo['path_prefix'], '/');
                }
            } elseif (function_exists('get_current_language') && function_exists('get_language_info')) {
                $lang_code = get_current_language();
                $info = get_language_info($lang_code);
                if ($info && !empty($info['path_prefix'])) {
                    $langPrefix = '/' . trim($info['path_prefix'], '/');
                }
            }

            $url = rtrim($base, '/') . $langPrefix . '/' . (empty($params) ? '' : '?' . http_build_query($params));
            return $url;
        };

        // 5. 模型过滤：仅显示在 term_models 中显式绑定了该模型的分类
        $model_filter = '';
        $model_params = [];
        if ($mid > 0) {
            $model_filter = " AND EXISTS (SELECT 1 FROM {$tododb->prefix}term_models tm_m WHERE tm_m.term_id = tt.term_id AND tm_m.model_id = ?)";
            $model_params = [$mid];
        }

        // 6. 获取当前上下文所需的分类树及后代分类
        $cats_raw = [];
        $parent_cat_id = $base_cat_id;
        $all_descendants = []; // 平铺列表：[ ['id'=>..., 'name'=>..., 'depth'=>2+], ... ]
        $all_cat_ids_in_context = []; // 包含 context_cat_id 及其所有子孙分类 ID

        // 一次性获取系统中符合条件的分类，在内存中进行树形及子孙计算，避免 SQL N+1 递归
        $all_terms_sql = "SELECT tt.term_id, tt.parent, t.name FROM {$tododb->prefix}term_taxonomy tt 
                          INNER JOIN {$tododb->prefix}terms t ON t.term_id = tt.term_id{$cat_site_join} 
                          WHERE tt.taxonomy = 'category'{$model_filter}{$cat_exclude_sql}{$cat_site_where}
                          ORDER BY t.name ASC";
        $terms_rows = $tododb->get_results($all_terms_sql, $model_params);

        // 构造分类节点与 Parent-Children 内存映射表
        $term_map = [];
        $children_map = [];
        if (!empty($terms_rows)) {
            foreach ($terms_rows as $r) {
                $tid = intval($r->term_id ?? 0);
                if ($tid <= 0) continue;
                if (function_exists('get_term_meta') && get_term_meta($tid, 'is_hidden', true)) continue;
                $p = intval($r->parent ?? 0);
                $term_map[$tid] = [
                    'id' => $tid,
                    'parent' => $p,
                    'name' => $r->name ?? ''
                ];
                $children_map[$p][] = $tid;
            }
        }

        if ($context_cat_id > 0 && isset($term_map[$context_cat_id])) {
            // 向上追溯根分类
            $root_id = $context_cat_id;
            while (isset($term_map[$root_id]) && $term_map[$root_id]['parent'] > 0 && isset($term_map[$term_map[$root_id]['parent']])) {
                $root_id = $term_map[$root_id]['parent'];
            }
            $parent_cat_id = $root_id;

            // 根分类的直接子分类 (depth=1)
            $direct_child_ids = $children_map[$root_id] ?? [];
            foreach ($direct_child_ids as $cid) {
                $cats_raw[] = (object)$term_map[$cid];
            }

            // 内存中递归收集所有更深层子孙分类 (depth >= 2)
            $collectMemoryDescendants = function ($parent_ids, $depth) use (&$collectMemoryDescendants, $children_map, $term_map) {
                if (empty($parent_ids)) return [];
                $result = [];
                $next_parents = [];
                foreach ($parent_ids as $pid) {
                    $cids = $children_map[$pid] ?? [];
                    foreach ($cids as $cid) {
                        if (isset($term_map[$cid])) {
                            $result[] = ['id' => $cid, 'name' => $term_map[$cid]['name'], 'depth' => $depth];
                            $next_parents[] = $cid;
                        }
                    }
                }
                if (!empty($next_parents)) {
                    $result = array_merge($result, $collectMemoryDescendants($next_parents, $depth + 1));
                }
                return $result;
            };

            if (!empty($direct_child_ids)) {
                $all_descendants = $collectMemoryDescendants($direct_child_ids, 2);
            }

            // 计算包含 $context_cat_id 及其所有子孙分类 ID
            $collectAllSubIds = function ($pid) use (&$collectAllSubIds, $children_map) {
                $sub = [$pid];
                $cids = $children_map[$pid] ?? [];
                foreach ($cids as $cid) {
                    $sub = array_merge($sub, $collectAllSubIds($cid));
                }
                return $sub;
            };
            $all_cat_ids_in_context = array_unique($collectAllSubIds($context_cat_id));
        } else {
            $parent_cat_id = 0;
            $root_child_ids = $children_map[0] ?? [];
            foreach ($root_child_ids as $cid) {
                $cats_raw[] = (object)$term_map[$cid];
            }
        }

        $parent_cats = []; // depth=1 直接子分类
        if (!empty($cats_raw)) {
            foreach ($cats_raw as $cat) {
                $cid = isset($cat->id) ? intval($cat->id) : (isset($cat->term_id) ? intval($cat->term_id) : 0);
                if ($cid <= 0) continue;
                $parent_cats[] = ['id' => $cid, 'name' => $cat->name ?? '', 'depth' => 1];
            }
        }

        // 辅助函数：判断某个分类 ID 是否是 active_cat_id 或其祖先 (纯内存判定)
        $isOnActivePath = function ($cat_id) use ($active_cat_id, $term_map) {
            if ($active_cat_id <= 0) return false;
            if ($cat_id == $active_cat_id) return true;
            $check = $active_cat_id;
            while ($check > 0 && isset($term_map[$check])) {
                $p = $term_map[$check]['parent'];
                if ($p == $cat_id) return true;
                $check = $p;
            }
            return false;
        };

        // 7. 小类（标签级联，利用内存计算的 $all_cat_ids_in_context 避免额外数据库递归）
        $tags = [];
        if ($show_tags) {
            $tag_cat = $context_cat_id > 0 ? $context_cat_id : 0;
            $tag_params = [];
            $tag_sql = "SELECT DISTINCT tg.tag_id, tg.tag_title, tg.tag_slug, tg.tag_order FROM {$tododb->prefix}tags tg 
                        INNER JOIN {$tododb->prefix}post_tags pt ON pt.tag_id = tg.tag_id 
                        INNER JOIN {$tododb->prefix}posts p ON p.ID = pt.post_id AND p.post_status = 'publish'";

            // 文章站点隔离 (site_posts)
            if ($current_site_id > 0 && function_exists('todo_get_post_site_visibility_sql')) {
                $site_posts_table = $tododb->prefix . 'site_posts';
                $tag_sql .= " LEFT JOIN {$site_posts_table} sp ON (p.ID = sp.post_id AND sp.site_id = {$current_site_id})";
                $tag_sql .= ' AND ' . todo_get_post_site_visibility_sql('sp', $current_site_id, 0, 'p.ID');
            }

            if ($tag_cat > 0 && !empty($all_cat_ids_in_context)) {
                $cat_placeholders = implode(',', array_fill(0, count($all_cat_ids_in_context), '?'));
                $tag_sql .= " INNER JOIN {$tododb->prefix}term_relationships tr_t ON tr_t.object_id = p.ID 
                              INNER JOIN {$tododb->prefix}term_taxonomy tt_t ON tt_t.term_taxonomy_id = tr_t.term_taxonomy_id 
                              AND tt_t.taxonomy = 'category' AND tt_t.term_id IN ({$cat_placeholders})";
                $tag_params = array_merge($tag_params, $all_cat_ids_in_context);
            }
            if ($mid > 0) { $tag_sql .= " AND p.model_id = ?"; $tag_params[] = $mid; }
            if (!empty($no_tags)) { $tag_sql .= " AND tg.tag_id NOT IN (" . implode(',', $no_tags) . ")"; }
            $tag_sql .= " ORDER BY tg.tag_order ASC, tg.tag_title ASC LIMIT 80";
            $tags = $tododb->get_results($tag_sql, $tag_params);
        }

        // 8. 文档属性
        $doc_props = [];
        if ($show_properties) {
            $prop_sql = "SELECT id, name, value, display_name FROM {$tododb->prefix}doc_properties WHERE is_active = 1";
            if (!empty($no_props)) { $prop_sql .= " AND id NOT IN (" . implode(',', $no_props) . ")"; }
            $prop_sql .= " ORDER BY sort_order ASC, id ASC";
            $doc_props = $tododb->get_results($prop_sql);
        }

        // 10. 渲染辅助
        $renderRow = function ($label, $items, $active_val, $urlFn, $all_url) use ($t, $all_label) {
            $row = '<div class="super-nav-row">';
            $row .= '<span class="super-nav-row-label">' . esc_html($t($label)) . ':&nbsp;</span>';
            $row .= '<span class="super-nav-tags">';
            // "全部" 按钮（多维筛选清洗链接，使用 nofollow 保护抓取预算）
            $active_class = ($active_val === '' || $active_val === 0 || $active_val === '0') ? ' super-nav-tag--active' : '';
            $row .= '<a href="' . esc_attr($all_url) . '" rel="nofollow" class="super-nav-tag' . $active_class . '">' . esc_html($t($all_label)) . '</a>';
            foreach ($items as $it) {
                $val = $it['value'];
                $name = $it['name'];
                $url  = call_user_func($urlFn, $val);
                $is_active = ((string)$active_val === (string)$val);
                $cls = $is_active ? ' super-nav-tag--active' : '';
                // 标签与属性多维衍生筛选加 rel="nofollow"，根除 Google 爬虫陷阱并节约独立站 Crawl Budget
                $row .= '<a href="' . esc_attr($url) . '" rel="nofollow" class="super-nav-tag' . $cls . '">' . esc_html($t($name)) . '</a>';
            }
            $row .= '</span>';
            $row .= '</div>';
            return $row;
        };

        // 11. HTML 拼接
        $out = '<div class="super-nav">';

        // 大类行（平铺所有子、孙分类）
        $cat_label = isset($attrs['cat_label']) ? $attrs['cat_label'] : __('大类');
        $out .= '<div class="super-nav-row">';
        $out .= '<span class="super-nav-row-label">' . esc_html($t($cat_label)) . ':&nbsp;</span>';
        $out .= '<span class="super-nav-tags">';

        $cat_active_val = 0;
        foreach ($parent_cats as $pc) {
            if ($active_cat_id == $pc['id']) { $cat_active_val = $pc['id']; break; }
        }
        foreach ($all_descendants as $ad) {
            if ($active_cat_id == $ad['id']) { $cat_active_val = $ad['id']; break; }
        }
        $all_cls = ($cat_active_val === 0) ? ' super-nav-tag--active' : '';
        $all_url = $buildUrl(0, $current_tag, $current_prop);
        $all_cat_rel = (!empty($current_tag) || !empty($current_prop)) ? ' rel="nofollow"' : '';
        $out .= '<a href="' . esc_attr($all_url) . '"' . $all_cat_rel . ' class="super-nav-tag' . $all_cls . '">' . esc_html($t($all_label)) . '</a>';

        foreach ($parent_cats as $pc) {
            $url  = $buildUrl($pc['id'], $current_tag, $current_prop);
            $cls = ($active_cat_id == $pc['id']) ? ' super-nav-tag--active' : '';
            $cat_rel = (!empty($current_tag) || !empty($current_prop)) ? ' rel="nofollow"' : '';
            $out .= '<a href="' . esc_attr($url) . '"' . $cat_rel . ' class="super-nav-tag' . $cls . '">' . esc_html($t($pc['name'])) . '</a>';
        }

        foreach ($all_descendants as $ad) {
            $url  = $buildUrl($ad['id'], $current_tag, $current_prop);
            $cls = ($active_cat_id == $ad['id']) ? ' super-nav-tag--active' : '';
            $desc_rel = (!empty($current_tag) || !empty($current_prop)) ? ' rel="nofollow"' : '';
            $out .= '<a href="' . esc_attr($url) . '"' . $desc_rel . ' class="super-nav-tag' . $cls . '">' . esc_html($t($ad['name'])) . '</a>';
        }

        $out .= '</span>';
        $out .= '</div>';

        // 小类行
        if ($show_tags) {
            $tag_items = [];
            foreach ($tags as $tag_row) {
                $tid   = isset($tag_row->tag_id) ? intval($tag_row->tag_id) : 0;
                $tname = isset($tag_row->tag_title) ? $tag_row->tag_title : '';
                if ($tid <= 0) continue;
                $tag_items[] = ['value' => $tid, 'name' => $tname];
            }
            if (!empty($tag_items)) {
                $tag_label = isset($attrs['tag_label']) ? $attrs['tag_label'] : __('小类');
                $out .= $renderRow($tag_label, $tag_items, $current_tag,
                    function ($val) use ($buildUrl, $current_prop) {
                        return $buildUrl(-1, $val, $current_prop);
                    },
                    $buildUrl(-1, '', $current_prop)
                );
            }
        }

        // 属性行
        if ($show_properties && !empty($doc_props)) {
            $prop_items = [];
            foreach ($doc_props as $dp) {
                $d_id = isset($dp->id) ? intval($dp->id) : 0;
                $dn   = !empty($dp->display_name) ? $dp->display_name : (!empty($dp->name) ? $dp->name : '');
                if ($d_id <= 0 || empty($dn)) continue;
                $prop_items[] = ['value' => $d_id, 'name' => $dn];
            }
            $prop_label = isset($attrs['prop_label']) ? $attrs['prop_label'] : __('属性');
            $out .= $renderRow($prop_label, $prop_items, $current_prop,
                function ($val) use ($buildUrl, $current_tag) {
                    return $buildUrl(-1, $current_tag, $val);
                },
                $buildUrl(-1, $current_tag, '')
            );
        }

        $out .= '</div>';
        return $out;
    }

}

// 初始化模板标签处理器
CMS_HTML_Template_Tags::get_instance();
