<?php
/**
 * TodoCMS Post Functions for TodoCMS
 *
 * @package TodoCMS
 * @subpackage Administration
 */

// Don't load directly
if (!defined('ABSPATH')) {
    die('-1');
}

/**
 * Escape textarea content
 */
if (!function_exists('esc_textarea')) {
    function esc_textarea($text) {
        $safe_text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        return $safe_text;
    }
}

/**
 * Check if post type supports feature
 */
if (!function_exists('post_type_supports')) {
    function post_type_supports($post_type, $feature) {
        $features = array(
            'post' => array('title', 'editor', 'author', 'thumbnail', 'excerpt', 'trackbacks', 'custom-fields', 'comments', 'revisions', 'post-formats'),

        );
        
        if (!isset($features[$post_type])) {
            return false;
        }
        
        return in_array($feature, $features[$post_type]);
    }
}

/**
 * Check if theme supports feature
 */
if (!function_exists('theme_supports')) {
    function theme_supports($feature, $post_type = null) {
        if ($feature == 'post-thumbnails') {
            return true;
        }
        return false;
    }
}

/**
 * Get post status
 */
if (!function_exists('get_post_status')) {
    function get_post_status($post = null) {
        if (is_object($post)) {
            return $post->post_status;
        }
        return 'draft';
    }
}

/**
 * Check if post type is viewable
 */
if (!function_exists('is_post_type_viewable')) {
    function is_post_type_viewable($post_type_object) {
        if (is_object($post_type_object)) {
            return !empty($post_type_object->public) || !empty($post_type_object->publicly_queryable);
        }
        return true;
    }
}

/**
 * Get sample permalink HTML
 */
if (!function_exists('get_sample_permalink_html')) {
    function get_sample_permalink_html($id, $new_title = null, $new_slug = null) {
        $post = get_post($id);
        if (!$post) {
            return '';
        }
        
        $permalink = get_permalink($post);
        $view_link = get_permalink($post);
        
        if (false === strpos($permalink, '%postname%') && false === strpos($permalink, '%pagename%')) {
            $return = '<strong>' . __('Permalink:') . '</strong>\n';
            $return .= '<span id="sample-permalink" tabindex="-1">' . esc_html(urldecode($permalink)) . '</span>\n';
            $return .= '&lrm; <span id="edit-slug-buttons"><button type="button" class="edit-slug button hide-if-no-js" aria-label="' . __('Edit permalink') . '">' . __('Edit') . '</button></span>\n';
            $return .= '<span id="editable-post-name-full">' . esc_html($post->post_name) . '</span>\n';
        } else {
            if (null === $new_title) {
                $new_title = $post->post_title;
            }
            
            if (null === $new_slug) {
                $new_slug = $post->post_name;
            }
            
            $new_slug = sanitize_title($new_slug);
            
            $permalink = str_replace(array('%postname%', '%pagename%'), $new_slug, $permalink);
            
            $return = '<strong>' . __('Permalink:') . '</strong>\n';
            $return .= '<span id="sample-permalink" tabindex="-1">' . esc_html(urldecode($permalink)) . '</span>\n';
            $return .= '&lrm; <span id="edit-slug-buttons"><button type="button" class="edit-slug button hide-if-no-js" aria-label="' . __('Edit permalink') . '">' . __('Edit') . '</button></span>\n';
            $return .= '<span id="editable-post-name-full">' . esc_html($new_slug) . '</span>\n';
        }
        
        return $return;
    }
}

/**
 * Get permalink
 */
if (!function_exists('get_permalink')) {
    function get_permalink($post = null) {
        // 统一文章对象
        $obj = null;
        if (is_object($post) && isset($post->ID)) {
            $obj = $post;
        } elseif ($post !== null) {
            $obj = get_post($post);
        } else {
            $obj = isset($GLOBALS['todo_post']) ? $GLOBALS['todo_post'] : null;
        }
        if (!$obj) {
            return todo_url();
        }

        $locale = current_locale();

        // 固定链接结构
        $structure = get_option('permalink_structure', '');
        if (empty($structure)) {
            // 默认结构：查询参数形式，不追加 .html
            $base = rtrim(todo_url(), '/');
            $url = $base . '/?p=' . intval($obj->ID);
            return $locale ? get_language_url($locale, $url) : $url;
        }

        // 自定义 URL 作为别名（仅当为相对路径时），否则当作外链直接返回
        $custom = isset($obj->custom_url) ? trim($obj->custom_url) : '';
        if ($custom !== '' && preg_match('/^(https?:)?\/\//i', $custom)) {
            return $custom;
        }

        // 时间标签
        $post_date_ts = strtotime($obj->post_date ?: date('Y-m-d H:i:s'));
        $permalink = $structure;
        $permalink = str_replace('%year%', date('Y', $post_date_ts), $permalink);
        $permalink = str_replace('%monthnum%', date('m', $post_date_ts), $permalink);
        $permalink = str_replace('%day%', date('d', $post_date_ts), $permalink);
        $permalink = str_replace('%hour%', date('H', $post_date_ts), $permalink);
        $permalink = str_replace('%minute%', date('i', $post_date_ts), $permalink);
        $permalink = str_replace('%second%', date('s', $post_date_ts), $permalink);

        // 文章别名（优先使用 custom_url 作为路径）
        if ($custom !== '') {
            // custom_url 直接作为路径，不走 sanitize_title 避免破坏路径分隔符
            $permalink = '/' . ltrim($custom, '/');
        } else {
            $slug = $obj->post_name ? $obj->post_name : sanitize_title($obj->post_title);
            $permalink = str_replace('%post_id%', $obj->ID, $permalink);
            $permalink = str_replace('%postname%', $slug, $permalink);
        }

        // 分类标签（admin 环境取首个分类 slug）
        if (strpos($permalink, '%category%') !== false) {
            $category_slug = 'uncategorized';
            if (function_exists('get_the_terms')) {
                $terms = get_the_terms($obj->ID, 'category');
                if ($terms && is_array($terms)) {
                    $first = reset($terms);
                    if (is_object($first) && isset($first->slug) && $first->slug) {
                        $category_slug = $first->slug;
                    }
                }
            }
            $permalink = str_replace('%category%', $category_slug, $permalink);
        }

        // 面包屑标签（多级分类路径）
        if (strpos($permalink, '%breadcrumb%') !== false) {
            $breadcrumb_path = '';
            if (function_exists('todo_get_category_breadcrumb_path')) {
                $breadcrumb_path = todo_get_category_breadcrumb_path($obj->ID);
            }
            $permalink = str_replace('%breadcrumb%', $breadcrumb_path, $permalink);
        }

        // 作者标签（缺失作者时移除该段）
        if (strpos($permalink, '%author%') !== false) {
            $author_slug = '';
            if (function_exists('get_user_by')) {
                $author = get_user_by('id', $obj->post_author);
                if ($author && !empty($author->user_login)) {
                    $author_slug = $author->user_login;
                }
            }
            $permalink = str_replace('%author%', $author_slug, $permalink);
        }

        // 规范化路径并追加 .html
        if (substr($permalink, 0, 1) !== '/') {
            $permalink = '/' . $permalink;
        }
        // 路径去重，修正重复斜杠
        $permalink = preg_replace('#/{2,}#', '/', $permalink);
        $permalink = rtrim($permalink, '/');
        if (!preg_match('/\.html$/i', $permalink)) {
            $permalink .= '.html';
        }

        $url = todo_url(ltrim($permalink, '/'));
        if ($locale) {
            $url = get_language_url($locale, $url);
        }
        return $url;
    }
}

/**
 * Get post
 */
if (!function_exists('get_post')) {
    function get_post($post = null, $output = OBJECT, $filter = 'raw') {
        if (empty($post) && isset($GLOBALS['todo_post'])) {
            $post = $GLOBALS['todo_post'];
        }
        
        if (is_numeric($post)) {
            // 从数据库获取文章
            return _get_post_from_db($post);
        }
        
        if (is_object($post)) {
            return $post;
        }
        
        return null;
    }
}

/**
 * Get post from database (simplified)
 */
if (!function_exists('_get_post_from_db')) {
    function _get_post_from_db($post_id) {
        // 简化的数据库查询
        return (object) array(
            'ID' => $post_id,
            'post_title' => '',
            'post_content' => '',
            'post_status' => 'draft',
            'post_name' => '',
            'post_author' => 1,
            'post_date' => current_time('mysql'),
            'post_modified' => current_time('mysql'),
            'post_type' => 'post'
        );
    }
}

/**
 * Remove accents
 */
if (!function_exists('remove_accents')) {
    function remove_accents($string) {
        if (!preg_match('/[\x80-\xff]/', $string)) {
            return $string;
        }
        
        $chars = array(
            // Decompositions for Latin-1 Supplement
            'ª' => 'a', 'º' => 'o',
            'À' => 'A', 'Á' => 'A',
            'Â' => 'A', 'Ã' => 'A',
            'Ä' => 'A', 'Å' => 'A',
            'Æ' => 'AE','Ç' => 'C',
            'È' => 'E', 'É' => 'E',
            'Ê' => 'E', 'Ë' => 'E',
            'Ì' => 'I', 'Í' => 'I',
            'Î' => 'I', 'Ï' => 'I',
            'Ð' => 'D', 'Ñ' => 'N',
            'Ò' => 'O', 'Ó' => 'O',
            'Ô' => 'O', 'Õ' => 'O',
            'Ö' => 'O', 'Ù' => 'U',
            'Ú' => 'U', 'Û' => 'U',
            'Ü' => 'U', 'Ý' => 'Y',
            'Þ' => 'TH','ß' => 's',
            'à' => 'a', 'á' => 'a',
            'â' => 'a', 'ã' => 'a',
            'ä' => 'a', 'å' => 'a',
            'æ' => 'ae','ç' => 'c',
            'è' => 'e', 'é' => 'e',
            'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i',
            'î' => 'i', 'ï' => 'i',
            'ð' => 'd', 'ñ' => 'n',
            'ò' => 'o', 'ó' => 'o',
            'ô' => 'o', 'õ' => 'o',
            'ö' => 'o', 'ø' => 'o',
            'ù' => 'u', 'ú' => 'u',
            'û' => 'u', 'ü' => 'u',
            'ý' => 'y', 'þ' => 'th',
            'ÿ' => 'y'
        );
        
        $string = strtr($string, $chars);
        
        return $string;
    }
}

/**
 * Current time
 */
if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        switch ($type) {
            case 'mysql':
                return ($gmt) ? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');
            case 'timestamp':
                return time();
            default:
                return ($gmt) ? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format($type) : date($type);
        }
    }
}

/**
 * Get shortlink
 */
if (!function_exists('todo_get_shortlink')) {
    function todo_get_shortlink($id = 0, $context = 'post', $allow_slugs = true) {
        return '';
    }
}

/**
 * MySQL to date
 */
if (!function_exists('mysql2date')) {
    function mysql2date($format, $date, $translate = true) {
        if (empty($date)) {
            return false;
        }
        
        if ('G' == $format) {
            return strtotime($date . ' +0000');
        }
        
        $i = strtotime($date);
        
        if ('U' == $format) {
            return $i;
        }
        
        if ($translate) {
            return date_i18n($format, $i);
        } else {
            return date($format, $i);
        }
    }
}

/**
 * Date i18n
 */
if (!function_exists('date_i18n')) {
    function date_i18n($dateformatstring, $unixtimestamp = false, $gmt = false) {
        $i = $unixtimestamp;
        
        if (false === $i) {
            $i = current_time('timestamp', $gmt);
        }
        
        return date($dateformatstring, $i);
    }
}

/**
 * Get userdata
 */
if (!function_exists('get_userdata')) {
    function get_userdata($user_id) {
        if (empty($user_id)) {
            return false;
        }
        
        return (object) array(
            'ID' => $user_id,
            'display_name' => 'Admin User'
        );
    }
}

/**
 * Add meta box
 */
if (!function_exists('add_meta_box')) {
    function add_meta_box($id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default', $callback_args = null) {
        // 简化的meta box添加
    }
}

/**
 * Do meta boxes
 */
if (!function_exists('do_meta_boxes')) {
    function do_meta_boxes($screen, $context, $object) {
        // 简化的meta boxes渲染
    }
}

// Constants
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}
