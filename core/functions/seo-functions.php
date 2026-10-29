<?php
/**
 * AI & SEO Enhancement Functions
 *
 * @package TodoCMS
 * @subpackage Core
 * @version 2.0.0
 */

// 0. Canonical 规范网址处理（清洗营销追踪与广告点击参数）
if (!function_exists('todo_get_current_canonical')) {
    function todo_get_current_canonical() {
        $url = function_exists('todo_get_current_url') ? todo_get_current_url() : '';
        if (empty($url)) {
            return '';
        }
        $parts = parse_url($url);
        if (empty($parts['query'])) {
            return $url;
        }
        parse_str($parts['query'], $query);
        $strip_keys = [
            'gclid', 'gbraid', 'wbraid', 'gad_source', 'fbclid',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
            '_ga', '_gl', 'msclkid', 'dclid'
        ];
        foreach ($strip_keys as $key) {
            unset($query[$key]);
        }
        $scheme    = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host      = isset($parts['host']) ? $parts['host'] : '';
        $port      = isset($parts['port']) ? ':' . $parts['port'] : '';
        $user      = isset($parts['user']) ? $parts['user'] : '';
        $pass      = isset($parts['pass']) ? ':' . $parts['pass']  : '';
        $pass      = ($user || $pass) ? "$pass@" : '';
        $path      = isset($parts['path']) ? $parts['path'] : '';
        $query_str = !empty($query) ? '?' . http_build_query($query) : '';
        $fragment  = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return "$scheme$user$pass$host$port$path$query_str$fragment";
    }
}

// 1. 主入口：todo_head 钩子
function todo_output_ai_seo_tags() {
    todo_output_robots_meta();
    todo_output_json_ld();
    todo_output_og_tags();
    
    // Canonical 链接
    $canonical = function_exists('todo_get_current_canonical') ? todo_get_current_canonical() : todo_get_current_url();
    if ($canonical) {
        echo '<link rel="canonical" href="' . esc_url($canonical) . '">' . PHP_EOL;
    }

    todo_output_hreflang();
    // 分页链接占位符：请求结束时由 todo_seo_output_filter 替换
    echo '<!--TODO_PAGINATION_PLACEHOLDER-->' . PHP_EOL;

    $site_url = rtrim(todo_get_site_url(), '/');

    // AI Index 声明
    echo '<link rel="index" type="text/plain" href="' . $site_url . '/llms.txt" title="AI Model Index">' . PHP_EOL;

    // RSS Feed
    $site_title = get_option('site_title', 'TodoCMS');
    if (function_exists('todo_parse_inline_translations')) {
        $site_title = todo_parse_inline_translations($site_title);
    }
    if (function_exists('todo_translate')) {
        $site_title = todo_translate($site_title);
    }

    echo '<link rel="alternate" type="application/rss+xml" title="' . esc_attr($site_title) . ' &raquo; Feed" href="' . $site_url . '/rss">' . PHP_EOL;

    if (is_category()) {
        $cat = get_queried_object();
        if ($cat && isset($cat->name) && isset($cat->term_id)) {
            $cat_name = $cat->name;
            if (function_exists('todo_parse_inline_translations')) {
                $cat_name = todo_parse_inline_translations($cat_name);
            }
            if (function_exists('todo_translate')) {
                $cat_name = todo_translate($cat_name);
            }
            echo '<link rel="alternate" type="application/rss+xml" title="' . esc_attr($site_title) . ' &raquo; ' . esc_attr($cat_name) . ' Feed" href="' . $site_url . '/rss?category=' . $cat->term_id . '">' . PHP_EOL;
        }
    }
}
add_action('todo_head', 'todo_output_ai_seo_tags');

// 1b. Robots Meta Tag (Noindex 控制)
function todo_output_robots_meta() {
    $robots = [];

    // 0a. 用户中心防护：/users/、?user=*、/user 路由严禁搜索引擎收录
    $req_uri = $_SERVER['REQUEST_URI'] ?? '';
    if (
        strpos($req_uri, '/users') !== false ||
        strpos($req_uri, '/user') !== false ||
        isset($_GET['user']) ||
        (function_exists('get_current_page_type') && get_current_page_type() === 'user_center')
    ) {
        if (!in_array('noindex', $robots, true)) $robots[] = 'noindex';
        if (!in_array('nofollow', $robots, true)) $robots[] = 'nofollow';
        if (!in_array('noarchive', $robots, true)) $robots[] = 'noarchive';
        if (!headers_sent()) {
            @header('X-Robots-Tag: noindex, nofollow, noarchive', true);
        }
    }

    // 0b. 单页可见性控制：readable（可读）、password（密码）、private（私密）针对爬虫/搜索引擎隐身
    $post_obj = function_exists('get_current_post') ? get_current_post() : null;
    if ($post_obj && isset($post_obj->ID)) {
        $visibility = get_post_meta($post_obj->ID, '_visibility', true) ?: ($post_obj->visibility ?? 'public');
        if (in_array($visibility, ['readable', 'password', 'private'], true)) {
            $robots[] = 'noindex';
            $robots[] = 'noarchive';
        }
    }

    // 1. 全局拦截（最高优先级）
    if (get_option('seo_noindex_site', 0)) {
        if (!in_array('noindex', $robots, true)) $robots[] = 'noindex';
        if (!in_array('nofollow', $robots, true)) $robots[] = 'nofollow';
    } else {
        // 2. 根据页面类型细分拦截
        $current = function_exists('get_current_page') ? get_current_page() : '';
        
        if ((function_exists('is_404') && is_404()) || (function_exists('todo_is_404') && todo_is_404()) || $current === '404') {
            if (!in_array('noindex', $robots, true)) $robots[] = 'noindex';
            if (!in_array('follow', $robots, true)) $robots[] = 'follow';
        } elseif (is_search() || $current === 'search') {
            if (get_option('seo_noindex_search_results', 1) && !in_array('noindex', $robots, true)) $robots[] = 'noindex';
        } elseif (is_category() || $current === 'category') {
            if (get_option('seo_noindex_categories', 0) && !in_array('noindex', $robots, true)) $robots[] = 'noindex';
        } elseif (is_tag() || $current === 'tag') {
            if (get_option('seo_noindex_tags', 0) && !in_array('noindex', $robots, true)) $robots[] = 'noindex';
        } elseif ((function_exists('is_author') && is_author()) || $current === 'author') {
            if (get_option('seo_noindex_author_archives', 0) && !in_array('noindex', $robots, true)) $robots[] = 'noindex';
        } elseif (is_archive() || $current === 'archive') {
            if (get_option('seo_noindex_archives', 0) && !in_array('noindex', $robots, true)) $robots[] = 'noindex';
        }
    }

    if (!empty($robots)) {
        echo '<meta name="robots" content="' . esc_attr(implode(', ', array_unique($robots))) . '">' . PHP_EOL;
    }
}

// 2. Open Graph & Twitter Card
function todo_output_og_tags() {
    $site_name  = get_option('site_title', 'TodoCMS');
    if (function_exists('todo_parse_inline_translations')) {
        $site_name = todo_parse_inline_translations($site_name);
    }
    if (function_exists('todo_translate')) {
        $site_name = todo_translate($site_name);
    }

    $site_url   = rtrim(todo_get_site_url(), '/');
    $title      = get_seo_title() ?: (function_exists('get_full_page_title') ? get_full_page_title() : '') ?: get_option('site_title', 'TodoCMS');
    if (function_exists('todo_parse_inline_translations')) {
        $title = todo_parse_inline_translations($title);
    }
    if (function_exists('todo_translate')) {
        $title = todo_translate($title);
    }

    $desc       = get_seo_description();
    if (!is_string($desc)) {
        $desc = '';
    }
    if ($desc === '') {
        $desc = (string) get_option('site_description', '');
    }
    if (function_exists('todo_parse_inline_translations')) {
        $desc = todo_parse_inline_translations($desc);
    }
    $canonical  = function_exists('todo_get_current_canonical') ? todo_get_current_canonical() : todo_get_current_url();
    $image      = '';
    $og_type    = 'website';

    $normalize_media_value_to_url = function ($value) {
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
    };

    // 当前语言 locale
    $locale = 'zh_CN';
    if (class_exists('LocaleContext')) {
        $lc = LocaleContext::get_instance()->get_locale();
        if ($lc) $locale = str_replace('-', '_', $lc);
    }

    if (is_single()) {
        global $post;
        $og_type = 'article';
        if ($post) {
            if (isset($post->post_type) && $post->post_type === 'product') {
                $og_type = 'product';
            }
            if (!$title) $title = $post->post_title;
            if (function_exists('todo_match_og_image') && !empty($post->featured_image)) {
                $image = todo_match_og_image($post->featured_image);
            }
            if (!$image && function_exists('get_the_post_thumbnail_url')) {
                $image = (string) get_the_post_thumbnail_url($post, 'full');
            }
        }
    } elseif (is_category() || is_tag()) {
        $og_type = 'website';
        $obj = get_queried_object();
        if ($obj && !$title) $title = $obj->name;
        // 读取栏目图片
        $term_image_raw = null;
        if ($obj && isset($obj->term_id) && $obj->term_id > 0) {
            $taxonomy = is_tag() ? 'post_tag' : 'category';
            if (function_exists('todo_get_term_attributes_multilang')) {
                $map = todo_get_term_attributes_multilang($obj->term_id, $taxonomy, null, array('status_allowed' => array('approved', 'pending')));
                if (is_array($map) && !empty($map['category_image'])) {
                    $term_image_raw = $map['category_image'];
                }
            }
            if (!$term_image_raw && function_exists('get_term_meta')) {
                $term_image_raw = get_term_meta($obj->term_id, 'category_image', true);
            }
        }
        if ($term_image_raw) {
            if (function_exists('todo_match_og_image')) {
                $image = todo_match_og_image($term_image_raw);
            } else {
                $image = $normalize_media_value_to_url($term_image_raw);
            }
        }
    }

    if (!$image) {
        $site_og = get_option('site_og_image', '');
        if ($site_og && function_exists('todo_match_og_image')) {
            $image = todo_match_og_image($site_og);
        }
    }
    if (!$image) {
        $site_logo = get_option('site_logo', '');
        if ($site_logo && function_exists('todo_match_og_image')) {
            $image = todo_match_og_image($site_logo);
        }
    }
    if (!$image) {
        $image = get_option('site_og_image', get_option('site_logo', ''));
        $image = $normalize_media_value_to_url($image);
    }
    // 转为前端运行时绝对 URL（支持 Scheme + Host + 子目录前缀 / CDN）
    if ($image !== '' && strpos($image, 'http://') !== 0 && strpos($image, 'https://') !== 0) {
        $image = function_exists('todo_media_url_to_runtime_absolute') ? todo_media_url_to_runtime_absolute($image) : todo_media_url_to_absolute($image);
    }

    echo PHP_EOL . '<!-- Open Graph -->' . PHP_EOL;
    echo '<meta property="og:type" content="' . esc_attr($og_type) . '">' . PHP_EOL;
    echo '<meta property="og:title" content="' . esc_attr($title) . '">' . PHP_EOL;
    if ($desc) echo '<meta property="og:description" content="' . esc_attr($desc) . '">' . PHP_EOL;
    echo '<meta property="og:url" content="' . esc_url($canonical) . '">' . PHP_EOL;
    echo '<meta property="og:site_name" content="' . esc_attr($site_name) . '">' . PHP_EOL;
    echo '<meta property="og:locale" content="' . esc_attr($locale) . '">' . PHP_EOL;
    if ($image) {
        echo '<meta property="og:image" content="' . esc_url($image) . '">' . PHP_EOL;
        if ($desc) {
            echo '<meta property="og:image:alt" content="' . esc_attr($desc) . '">' . PHP_EOL;
        }
        echo '<meta property="og:image:width" content="1200">' . PHP_EOL;
        echo '<meta property="og:image:height" content="630">' . PHP_EOL;
    }
    if ($og_type === 'product' && isset($post->ID)) {
        $price = get_post_meta($post->ID, '_current_price', true) ?: (get_post_meta($post->ID, 'price', true) ?: '');
        $currency = function_exists('todo_get_currency_iso') ? todo_get_currency_iso() : get_option('currency_code', 'USD');
        if ($price !== '') {
            echo '<meta property="product:price:amount" content="' . esc_attr($price) . '">' . PHP_EOL;
            echo '<meta property="product:price:currency" content="' . esc_attr($currency) . '">' . PHP_EOL;
        }
    }

    echo '<!-- Twitter Card -->' . PHP_EOL;
    echo '<meta name="twitter:card" content="summary_large_image">' . PHP_EOL;
    echo '<meta name="twitter:title" content="' . esc_attr($title) . '">' . PHP_EOL;
    if ($desc) echo '<meta name="twitter:description" content="' . esc_attr($desc) . '">' . PHP_EOL;
    if ($image) echo '<meta name="twitter:image" content="' . esc_url($image) . '">' . PHP_EOL;
    echo PHP_EOL;
}

// 3. hreflang（多语言备用链接）
function todo_output_hreflang() {
    if (!class_exists('LanguageRegistry')) return;

    $registry = LanguageRegistry::get_instance();
    if (!$registry || !method_exists($registry, 'get_routing_mode')) return;

    $mode = $registry->get_routing_mode();
    if ($mode !== 'path_prefix' && $mode !== 'domain') return;

    if (function_exists('get_language_url')) {
        $langs = $registry->get_languages(true);
        if (empty($langs)) return;

        $current_url = function_exists('get_current_url') 
            ? get_current_url() 
            : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/'));

        echo PHP_EOL . '<!-- hreflang -->' . PHP_EOL;

        $default_locale = $registry->get_default_locale();
        $default_outputted = false;

        foreach ($langs as $loc => $info) {
            $href = get_language_url($loc, $current_url);
            echo '<link rel="alternate" hreflang="' . esc_attr($loc) . '" href="' . esc_url($href) . '">' . PHP_EOL;
            if ($loc === $default_locale) {
                echo '<link rel="alternate" hreflang="x-default" href="' . esc_url($href) . '">' . PHP_EOL;
                $default_outputted = true;
            }
        }

        if (!$default_outputted && $default_locale) {
            $hrefDefault = get_language_url($default_locale, $current_url);
            echo '<link rel="alternate" hreflang="x-default" href="' . esc_url($hrefDefault) . '">' . PHP_EOL;
        }

        echo PHP_EOL;
        return;
    }

    $map = $registry->path_prefix_map();
    $default_locale = $registry->get_default_locale();

    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    $current_path = parse_url($request_uri, PHP_URL_PATH);
    $current_path = trim($current_path, '/');
    $query_string = parse_url($request_uri, PHP_URL_QUERY) ?? '';

    $base_path = $current_path;

    // 1. 优先剥离安装子目录前缀（如 danxcms），因为安装子目录在 URL 路径的最前端
    $site_url = todo_get_site_url();
    $site_path = rtrim((string) parse_url($site_url, PHP_URL_PATH), '/');
    if ($site_path && $site_path !== '/' && $site_path !== '') {
        $site_prefix = trim($site_path, '/');
        if ($site_prefix !== '') {
            if ($base_path === $site_prefix) {
                $base_path = '';
            } elseif (strpos($base_path . '/', $site_prefix . '/') === 0) {
                $base_path = trim(substr($base_path, strlen($site_prefix)), '/');
            }
        }
    }

    // 2. 然后剥离当前路径中已存在的语言前缀（如 zh, ar），得到纯路由路径（base_path）
    foreach ($map as $path_prefix => $locale) {
        $pp = trim((string)$path_prefix, '/');
        if ($pp !== '') {
            if ($base_path === $pp) {
                $base_path = '';
                break;
            } elseif (strpos($base_path . '/', $pp . '/') === 0) {
                $base_path = trim(substr($base_path, strlen($pp)), '/');
                break;
            }
        }
    }

    echo PHP_EOL . '<!-- hreflang -->' . PHP_EOL;
    $default_output = false;

    // 默认语言（pathPrefixMap 不含空前缀，需单独输出）
    if ($default_locale && method_exists($registry, 'language_by_locale')) {
        $dl_info = $registry->language_by_locale($default_locale);
        if ($dl_info && empty($dl_info['path_prefix'])) {
            $href = todo_media_url_to_runtime_absolute('/' . $base_path);
            $href = rtrim($href, '/');
            if ($query_string !== '') {
                $href .= '?' . $query_string;
            }
            echo '<link rel="alternate" hreflang="' . esc_attr($default_locale) . '" href="' . esc_url($href) . '">' . PHP_EOL;
            echo '<link rel="alternate" hreflang="x-default" href="' . esc_url($href) . '">' . PHP_EOL;
            $default_output = true;
        }
    }

    foreach ($map as $path_prefix => $locale) {
        $pp = trim((string)$path_prefix, '/');
        if ($pp === '') {
            $href = todo_media_url_to_runtime_absolute('/' . $base_path);
        } else {
            $href = todo_media_url_to_runtime_absolute('/' . $pp . '/' . $base_path);
        }
        $href = rtrim($href, '/');
        if ($query_string !== '') {
            $href .= '?' . $query_string;
        }
        echo '<link rel="alternate" hreflang="' . esc_attr($locale) . '" href="' . esc_url($href) . '">' . PHP_EOL;
        if (!$default_output) {
            echo '<link rel="alternate" hreflang="x-default" href="' . esc_url($href) . '">' . PHP_EOL;
            $default_output = true;
        }
    }
    echo PHP_EOL;
}

// 4. 分页 rel="prev/next"
function todo_get_pagination_links_html() {
    $paged = todo_get_paged_var();
    $total = todo_get_pagination_total();

    if ($total <= 1) {
        return '';
    }

    $base = function_exists('todo_get_current_url') ? rtrim(todo_get_current_url(), '/') : '';
    // 剥离已有的 /page/N/ 片段，得到基础 URL
    $base = preg_replace('#/page/\d+/?#', '', $base);
    $links = '';

    // 第1页：仅输出 next
    if ($paged <= 1 && $total > 1) {
        $next = todo_get_paginated_url(2, $base);
        $links .= "\t" . '<link rel="next" href="' . esc_url($next) . '">' . PHP_EOL;
        return $links;
    }

    // 第2页起：输出 prev，若有下一页则也输出 next
    if ($paged > 1) {
        $prev = todo_get_paginated_url($paged - 1, $base);
        $links .= "\t" . '<link rel="prev" href="' . esc_url($prev) . '">' . PHP_EOL;
    }
    if ($paged < $total) {
        $next = todo_get_paginated_url($paged + 1, $base);
        $links .= "\t" . '<link rel="next" href="' . esc_url($next) . '">' . PHP_EOL . "\t";
    }

    return $links;
}

/**
 * 输出缓冲回调：在请求最末尾替换分页占位符
 * 此时模板已完整渲染，{{todo:loop}} 已执行完毕，分页数据可用
 */
function todo_seo_output_filter($buffer) {
    if (defined('DOING_AJAX') && DOING_AJAX) {
        return preg_replace('/^[\t ]*<!--TODO_PAGINATION_PLACEHOLDER-->\s*\n?/m', '', $buffer);
    }

    if (function_exists('todo_get_pagination_links_html')) {
        $links = todo_get_pagination_links_html();
        if ($links !== '') {
            return preg_replace(
                '/^[\t ]*<!--TODO_PAGINATION_PLACEHOLDER-->\s*\n?/m',
                $links,
                $buffer
            );
        }
    }

    return preg_replace('/^[\t ]*<!--TODO_PAGINATION_PLACEHOLDER-->\s*\n?/m', '', $buffer);
}

if (!function_exists('todo_get_paged_var')) {
    function todo_get_paged_var() {
        return function_exists('todo_get_paged_context') ? todo_get_paged_context('paged', 1) : 1;
    }
}

if (!function_exists('todo_get_pagination_total')) {
    function todo_get_pagination_total() {
        global $template_data;
        if (isset($template_data['pagination_total_pages']) && (int) $template_data['pagination_total_pages'] > 0) {
            return (int) $template_data['pagination_total_pages'];
        }
        // 回退：从模板编译器静态属性读取（loop 执行后持久保留）
        if (class_exists('CMS_Template_Compiler_V2')) {
            $lp = CMS_Template_Compiler_V2::$last_loop_pagination;
            if (is_array($lp) && isset($lp['pagination_total_pages']) && (int) $lp['pagination_total_pages'] > 0) {
                return (int) $lp['pagination_total_pages'];
            }
        }
        return 1;
    }
}

// 5. JSON-LD（@graph 模式）
function todo_output_json_ld() {
    if (class_exists('Todo_Core_Kernel')) {
        $context = [
            'is_home' => (is_home() || is_front_page()),
            'is_single' => is_single(),
            'is_archive' => (is_category() || is_tag())
        ];
        if (is_single()) {
            global $post;
            $context['post'] = $post;
        }
        $graph = Todo_Core_Kernel::generate_seo_graph($context);

        // 递归修复相对路径 URL 为绝对路径（使用已有智能去重函数），并修正 SearchAction target 为 /search?q= 格式
        $fix_urls = function(&$arr) use (&$fix_urls) {
            if (!is_array($arr)) return;
            foreach ($arr as $key => &$value) {
                if ($key === 'url' && is_string($value) && $value !== '' && strpos($value, '/') === 0 && strpos($value, 'http://') !== 0 && strpos($value, 'https://') !== 0) {
                    $value = function_exists('todo_media_url_to_runtime_absolute') ? todo_media_url_to_runtime_absolute($value) : todo_media_url_to_absolute($value);
                } elseif ($key === 'target' && is_string($value)) {
                    // 将 /?s={search_term_string} 修正为 /search?q={search_term_string}
                    $value = preg_replace('#/\?s=\{search_term_string\}#', '/search?q={search_term_string}', $value);
                } elseif (is_array($value)) {
                    $fix_urls($value);
                }
            }
        };
        if (isset($graph['@graph']) && is_array($graph['@graph'])) {
            // 实体消歧：产品页面严禁声明为 Article，避免与主题 Product Schema 冲突受到 Google 算法降权
            if (isset($post->post_type) && $post->post_type === 'product') {
                $graph['@graph'] = array_values(array_filter($graph['@graph'], function($node) {
                    return !isset($node['@type']) || $node['@type'] !== 'Article';
                }));
            }
            $fix_urls($graph['@graph']);
        }

        // 面包屑 BreadcrumbList 合并到 @graph
        $breadcrumb = todo_build_breadcrumb_list_item();
        if ($breadcrumb !== null) {
            $graph['@graph'][] = $breadcrumb;
        }

        echo '<script type="application/ld+json">' . json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . '</script>' . PHP_EOL;
    }
}

// 6. 辅助函数

/**
 * 构建面包屑 BreadcrumbList 结构化数据，供 @graph 合并使用
 *
 * @return array|null
 */
function todo_build_breadcrumb_list_item() {
    if (!function_exists('todo_get_breadcrumb_data')) {
        return null;
    }

    $breadcrumbs = todo_get_breadcrumb_data();
    if (empty($breadcrumbs)) {
        return null;
    }

    $items = [];
    $pos   = 1;

    foreach ($breadcrumbs as $crumb) {
        $item = [
            '@type'    => 'ListItem',
            'position' => $pos,
            'name'     => $crumb['title'],
        ];

        if (!(isset($crumb['is_current']) && $crumb['is_current'])) {
            $item['item'] = $crumb['url'];
        }

        $items[] = $item;
        $pos++;
    }

    return [
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $items,
    ];
}

/**
 * 从正文提取 FAQ Schema（检测 H2/H3 问句：以？或? 结尾）
 */
function todo_extract_faq_jsonld($content) {
    if (empty($content)) return null;

    preg_match_all('/<h[23][^>]*>(.*?)<\/h[23]>\s*(<p[^>]*>.*?<\/p>)/si', $content, $matches, PREG_SET_ORDER);
    if (empty($matches)) return null;

    $entities = [];
    foreach ($matches as $m) {
        $heading = strip_tags($m[1]);
        // 只处理以问号结尾的标题
        if (!preg_match('/[？?]\s*$/', $heading)) continue;
        $answer = strip_tags($m[2]);
        if (strlen($answer) < 20) continue;
        $entities[] = [
            '@type'          => 'Question',
            'name'           => trim($heading),
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => trim(mb_substr($answer, 0, 500))],
        ];
        if (count($entities) >= 5) break; // 最多 5 条
    }

    if (empty($entities)) return null;

    return ['@type' => 'FAQPage', 'mainEntity' => $entities];
}

/**
 * 统计文章字数（中英文兼容）
 */
function todo_get_post_word_count($post_id) {
    $post = get_post($post_id);
    if (!$post || empty($post->post_content)) return 0;
    $text = strip_tags($post->post_content);
    // 中文字符按字计，英文按词计
    $zh = preg_match_all('/[\x{4e00}-\x{9fa5}]/u', $text, $_);
    $en = str_word_count(preg_replace('/[\x{4e00}-\x{9fa5}]/u', '', $text));
    return $zh + $en;
}

// 7. 自动更新 Sitemap（内容变更钩子）
function todo_auto_update_sitemaps_on_save($post_id, $extra = null) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    $post = get_post($post_id);
    $should_update = false;
    $post_date = null;

    if ($post && ($post->post_status === 'publish' || $post->post_status === 'future')) {
        $should_update = true;
    }

    if (!$post && is_string($extra) && $extra !== '') {
        $should_update = true;
        $post_date = $extra;
    }

    if ($should_update) {
        if (!class_exists('Todo_Sitemap_Service')) {
            if (class_exists('Todo_Function_Loader')) {
                Todo_Function_Loader::load_function('sitemap-service');
            } else {
                $service_file = ABSPATH . 'core/services/sitemap-service.php';
                if (file_exists($service_file)) require_once $service_file;
            }
        }
        if (class_exists('Todo_Sitemap_Service')) {
            Todo_Sitemap_Service::regenerate_post_sitemap($post_id, $post_date);
        } else {
            error_log('TodoCMS Sitemap Error: Todo_Sitemap_Service class not found during auto-update.');
        }
    }
}
add_action('todo_post_inserted', 'todo_auto_update_sitemaps_on_save');
add_action('todo_post_updated', 'todo_auto_update_sitemaps_on_save');
add_action('todo_post_deleted', 'todo_auto_update_sitemaps_on_save');
add_action('todo_scheduled_post_published', 'todo_auto_update_sitemaps_on_save');

// 7b. 定时增量 Sitemap 更新（Cron）
function todo_scheduled_sitemap_update() {
    if (!class_exists('Todo_Sitemap_Service')) {
        if (class_exists('Todo_Function_Loader')) {
            Todo_Function_Loader::load_function('sitemap-service');
        } else {
            $service_file = ABSPATH . 'core/services/sitemap-service.php';
            if (file_exists($service_file)) require_once $service_file;
        }
    }
    if (class_exists('Todo_Sitemap_Service')) {
        Todo_Sitemap_Service::generate_incremental();
    }
}
// 注册定时任务钩子
add_action('todo_cron_sitemap_incremental', 'todo_scheduled_sitemap_update');

// 在系统初始化时注册定时事件（仅首次或恢复时）
function todo_register_sitemap_cron() {
    if (!class_exists('Todo_Scheduler')) return;
    if (Todo_Scheduler::nextScheduled('todo_cron_sitemap_incremental') <= 0) {
        Todo_Scheduler::scheduleEvent(time() + 3600, 'daily', 'todo_cron_sitemap_incremental');
    }
}
add_action('init', 'todo_register_sitemap_cron', 99);

// 8. DOM-aware 内链网络
function todo_seo_internal_linking($content) {
    if (class_exists('Todo_Core_Kernel')) {
        return Todo_Core_Kernel::apply_internal_linking($content);
    }
    return $content;
}
add_filter('the_content', 'todo_seo_internal_linking', 20);

// X. 搜索引擎主动推送 (Baidu / IndexNow)
function todo_seo_push_to_search_engines($post_id) {
    if (class_exists('Todo_Core_Kernel')) {
        return Todo_Core_Kernel::notify_search_engines($post_id);
    }
}
// 挂载到文章发布/更新生命周期钩子
add_action('todo_post_inserted', 'todo_seo_push_to_search_engines');
add_action('todo_post_updated', 'todo_seo_push_to_search_engines');
add_action('todo_scheduled_post_published', 'todo_seo_push_to_search_engines');

// X+1. Tag 自动功能：特色图像、内链、插图

/**
 * 获取指定目录中的随机图片 URL
 *
 * @param string $directory 相对目录路径
 * @return string|null 图片URL，无可用图片时返回null
 */
function todo_tag_get_random_image($directory) {
    $filename = todo_tag_pick_seeded_file($directory, mt_rand());
    if ($filename === null) return null;

    $site_url = function_exists('todo_get_site_url') ? rtrim(todo_get_site_url(), '/') : '';
    return $site_url . '/' . ltrim($directory, '/') . '/' . rawurlencode($filename);
}

/**
 * 文章保存后：自动设置特色图像
 * 取文章第一个绑定 tag_media_directory 的 tag，随机选图作为 featured_image
 *
 * @param int   $post_id 文章ID
 * @param array $postarr 文章数据
 */
function todo_tag_auto_featured_image($post_id, $postarr) {
    // 已有特色图片则跳过
    if (!empty($postarr['featured_image']) && $postarr['featured_image'] !== '[]') {
        return;
    }

    $tags = (new CMS_Tags_Manager())->get_post_tags($post_id);
    if (empty($tags)) return;

    $img_data = null;
    foreach ($tags as $tag) {
        $dir = $tag['tag_media_directory'] ?? '';
        if (!empty($dir)) {
            $img_data = todo_tag_get_seeded_image_data($dir, intval($post_id));
            if ($img_data !== null) break;
        }
    }

    if ($img_data === null) return;

    $fi = json_encode([$img_data], JSON_UNESCAPED_UNICODE);
    todo_update_post_featured_image($post_id, $fi);
}
add_action('todo_post_inserted', 'todo_tag_auto_featured_image', 15, 2);
add_action('todo_post_tags_updated', 'todo_tag_auto_featured_image_simple', 15, 1);

/**
 * 标签更新后：自动设置特色图像（简化版，仅需post_id）
 *
 * @param int $post_id 文章ID
 */
function todo_tag_auto_featured_image_simple($post_id) {
    global $tododb;
    if (!is_object($tododb)) return;

    // 检查是否已有特色图片
    $existing = $tododb->get_var(
        "SELECT featured_image FROM {$tododb->prefix}posts WHERE ID = ?",
        [intval($post_id)]
    );
    if (!empty($existing) && $existing !== '[]') return;

    $tags = (new CMS_Tags_Manager())->get_post_tags($post_id);
    if (empty($tags)) return;

    $img_data = null;
    foreach ($tags as $tag) {
        $dir = $tag['tag_media_directory'] ?? '';
        if (!empty($dir)) {
            $img_data = todo_tag_get_seeded_image_data($dir, intval($post_id));
            if ($img_data !== null) break;
        }
    }

    if ($img_data === null) return;

    $fi = json_encode([$img_data], JSON_UNESCAPED_UNICODE);
    todo_update_post_featured_image($post_id, $fi);
}

/**
 * 更新文章 featured_image 字段
 *
 * @param int    $post_id
 * @param string $json   JSON格式特色图片数据
 */
function todo_update_post_featured_image($post_id, $json) {
    global $tododb;
    if (!is_object($tododb)) return;
    $tododb->query(
        $tododb->prepare("UPDATE {$tododb->prefix}posts SET featured_image = %s WHERE ID = %d", $json, intval($post_id))
    );
}

/**
 * 根据种子值从目录中获取确定性的图片数据（用于 featured_image 入库）
 *
 * @param string $directory 相对目录路径
 * @param int    $seed      确定性种子值
 * @return array|null ['id' => ..., 'url' => ..., 'title' => ..., 'index' => 0]
 */
function todo_tag_get_seeded_image_data($directory, $seed) {
    $filename = todo_tag_pick_seeded_file($directory, $seed);
    if ($filename === null) return null;

    $dir = ltrim($directory, '/');
    $rel_path = '/' . $dir . '/' . rawurlencode($filename);

    return [
        'id'    => md5($rel_path),
        'url'   => $rel_path,
        'title' => pathinfo($filename, PATHINFO_FILENAME),
        'index' => 0,
    ];
}

/**
 * 根据种子值从目录中获取确定性的图片 URL（用于前端直接渲染）
 *
 * @param string $directory 相对目录路径
 * @param int    $seed      确定性种子值
 * @return string|null
 */
function todo_tag_get_seeded_image($directory, $seed) {
    $filename = todo_tag_pick_seeded_file($directory, $seed);
    if ($filename === null) return null;

    $site_url = function_exists('todo_get_site_url') ? rtrim(todo_get_site_url(), '/') : '';
    return $site_url . '/' . ltrim($directory, '/') . '/' . rawurlencode($filename);
}

/**
 * 从目录中按种子选取文件名（内部工具函数）
 *
 * @param string $directory
 * @param int    $seed
 * @return string|null
 */
function todo_tag_pick_seeded_file($directory, $seed) {
    if (empty($directory)) return null;

    $base = ABSPATH . ltrim($directory, '/');
    if (!is_dir($base)) return null;

    $exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'];
    $files = [];
    $dh = opendir($base);
    if (!$dh) return null;
    while (($f = readdir($dh)) !== false) {
        if ($f === '.' || $f === '..') continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, $exts, true)) {
            $files[] = $f;
        }
    }
    closedir($dh);

    if (empty($files)) return null;

    sort($files);
    $idx = abs($seed) % count($files);
    return $files[$idx];
}

/**
 * 批量更新：为拥有指定 tag 但无 featured_image 的文章设置特色图片
 * 在 tag 保存（新增/编辑 tag_media_directory）时调用
 *
 * @param int $tag_id
 */
function todo_tag_batch_update_featured_image($tag_id) {
    global $tododb;
    if (!is_object($tododb)) return;

    $tag = $tododb->get_row(
        "SELECT tag_media_directory FROM {$tododb->prefix}tags WHERE tag_id = ?",
        [intval($tag_id)]
    );
    if (!$tag || empty($tag->tag_media_directory)) return;

    $dir = $tag->tag_media_directory;
    $base = ABSPATH . ltrim($dir, '/');
    if (!is_dir($base)) return;

    $post_ids = $tododb->get_col(
        "SELECT p.ID FROM {$tododb->prefix}posts p
         INNER JOIN {$tododb->prefix}post_tags pt ON p.ID = pt.post_id
         WHERE pt.tag_id = ? AND (p.featured_image IS NULL OR p.featured_image = '' OR p.featured_image = '[]')
         AND p.post_status = 'publish'",
        [intval($tag_id)]
    );
    if (empty($post_ids)) return;

    foreach ($post_ids as $post_id) {
        $img_data = todo_tag_get_seeded_image_data($dir, intval($post_id));
        if ($img_data === null) continue;
        $fi = json_encode([$img_data], JSON_UNESCAPED_UNICODE);
        todo_update_post_featured_image($post_id, $fi);
    }
}
add_action('todo_tag_updated', 'todo_tag_batch_update_featured_image', 10, 2);

/**
 * 前端渲染：自动 tag 内链 + 插图
 * 在文章内容渲染到前端前，根据第一个 auto_inlink > 0 的 tag 配置，
 * 将文章中出现的前 N 个 tag 名称包裹为内链，并可选的插入图片。
 *
 * @param string $content 文章内容
 * @return string 处理后的内容
 */
function todo_tag_auto_inlink_and_image($content) {
    $post_id = 0;
    $todo_post = isset($GLOBALS['todo_post']) && is_object($GLOBALS['todo_post']) ? $GLOBALS['todo_post'] : (isset($GLOBALS['post']) && is_object($GLOBALS['post']) ? $GLOBALS['post'] : null);
    if ($todo_post && isset($todo_post->ID)) $post_id = intval($todo_post->ID);
    if ($post_id <= 0) return $content;

    // 获取文章所有tag（含完整tag行数据）
    static $tags_cache = [];
    $cache_key = 'auto_tag_' . $post_id;
    if (isset($tags_cache[$cache_key])) {
        $tags = $tags_cache[$cache_key];
    } else {
        $tags = (new CMS_Tags_Manager())->get_post_tags($post_id);
        $tags_cache[$cache_key] = $tags;
    }

    if (empty($tags)) return $content;

    // 找第一个 auto_inlink > 0 的tag
    $config = null;
    foreach ($tags as $tag) {
        $ai = intval($tag['auto_inlink'] ?? 0);
        if ($ai > 0) {
            $config = [
                'auto_inlink' => $ai,
                'auto_image'  => intval($tag['auto_image'] ?? 0),
                'media_dir'   => $tag['tag_media_directory'] ?? '',
                'tag_id'      => $tag['tag_id'],
            ];
            break;
        }
    }

    if ($config === null) return $content;
    if ($config['auto_inlink'] <= 0) return $content;

    // 图片插入数量：取 auto_image 和 auto_inlink 的最小值
    $img_count = $config['auto_image'] > 0 ? min($config['auto_image'], $config['auto_inlink']) : 0;
    $has_images = ($img_count > 0 && !empty($config['media_dir']));

    // 按 tag_title 长度降序排列，优先替换长tag名（避免短名误替换）
    $tag_items = [];
    foreach ($tags as $t) {
        $raw_name = $t['tag_title'] ?? '';
        if (empty($raw_name)) continue;
        $tid = intval($t['tag_id']);

        $names = [];

        // 1. 系统翻译与当前语言环境的转换名称（包含多语言字典/语言包 lookup）
        if (function_exists('todo_translate')) {
            $trans = todo_translate($raw_name, 'term.tag.' . $tid . '.name');
            if (!empty($trans)) {
                if (function_exists('todo_parse_inline_translations')) {
                    $trans = todo_parse_inline_translations($trans);
                }
                $trans = trim(preg_replace('/\[[a-z]{2}(?:-[a-z]{2})?\|.*?\]/is', '', $trans));
                $trans = trim(preg_replace('/\[:[a-z]{2}(?:-[a-z]{2})?\].*?\[:\\]/is', '', $trans));
                if (!empty($trans)) {
                    $names[] = $trans;
                }
            }
        }

        // 2. 解析内联多语言语法（如 [zh|热点][en|hotspot] 或 [:en]hotspot[:]）
        if (function_exists('todo_parse_inline_translations')) {
            $parsed_inline = todo_parse_inline_translations($raw_name);
            if (!empty($parsed_inline) && $parsed_inline !== $raw_name) {
                $names[] = $parsed_inline;
            }
        }

        // 提取 [zh|...][en|...] 与 [:en]...[:] 中所有语言代码变体
        if (strpos($raw_name, '[') !== false) {
            if (preg_match_all('/\[(?:[a-z]{2}(?:-[a-z]{2})?)\|([^\]]+)\]/iu', $raw_name, $m)) {
                foreach ($m[1] as $sub_name) {
                    $sub_name = trim($sub_name);
                    if ($sub_name !== '') {
                        $names[] = $sub_name;
                    }
                }
            }
            if (preg_match_all('/\[:[a-z]{2}(?:-[a-z]{2})?\](.*?)\[:\\]/isu', $raw_name, $m2)) {
                foreach ($m2[1] as $sub_name) {
                    $sub_name = trim($sub_name);
                    if ($sub_name !== '') {
                        $names[] = $sub_name;
                    }
                }
            }
        }

        // 3. 原始 tag_title 剥离语言标签后的纯文本名称
        $clean_raw = function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($raw_name) : $raw_name;
        $clean_raw = trim(preg_replace('/\[[a-z]{2}(?:-[a-z]{2})?\|.*?\]/is', '', $clean_raw));
        $clean_raw = trim(preg_replace('/\[:[a-z]{2}(?:-[a-z]{2})?\].*?\[:\\]/is', '', $clean_raw));
        if ($clean_raw !== '') {
            $names[] = $clean_raw;
        }

        // 去重并筛选有效长度
        $names = array_unique(array_filter(array_map('trim', $names)));
        foreach ($names as $name) {
            if (mb_strlen($name) < 2) continue;
            $tag_items[] = [
                'name'   => $name,
                'tag_id' => $tid,
                'len'    => mb_strlen($name),
            ];
        }
    }
    usort($tag_items, function ($a, $b) { return $b['len'] - $a['len']; });

    $linked = 0;
    $imaged = 0;

    if (!class_exists('DOMDocument')) {
        return $content;
    }

    $old_libxml = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = true;
    $loaded = @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $content);
    if (!$loaded) {
        $loaded = @$dom->loadHTML($content);
    }
    libxml_clear_errors();
    libxml_use_internal_errors($old_libxml);
    if (!$loaded) {
        return $content;
    }

    $body = $dom->getElementsByTagName('body')->item(0);
    $root = $body ?: $dom->documentElement;
    if (!$root) {
        return $content;
    }

    // 受保护元素：<a>、image-wrapper 以及脚本/样式等不应被替换的节点，直接跳过其整棵子树
    $is_protected = function ($node) {
        $tag = strtolower((string) $node->nodeName);
        if (in_array($tag, array('a', 'script', 'style', 'textarea', 'code', 'pre'), true)) {
            return true;
        }
        if ($node->hasAttribute('class')) {
            $classes = preg_split('/\s+/', (string) $node->getAttribute('class'));
            if (in_array('image-wrapper', $classes, true)) {
                return true;
            }
        }
        return false;
    };

    // 按文档顺序查找第一个可替换的纯文本节点（自动跳过标签属性，因为属性不是文本节点）
    $find_text = function ($node, $pattern, &$offset, &$length) use (&$find_text, $is_protected) {
        if ($node->nodeType === XML_ELEMENT_NODE) {
            if ($is_protected($node)) {
                return null;
            }
            if ($node->hasChildNodes()) {
                foreach ($node->childNodes as $child) {
                    $res = $find_text($child, $pattern, $offset, $length);
                    if ($res !== null) {
                        return $res;
                    }
                }
            }
            return null;
        }
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            if (preg_match($pattern, $node->nodeValue, $m, PREG_OFFSET_CAPTURE)) {
                $offset = $m[0][1];
                $length = strlen($m[0][0]);
                return $node;
            }
        }
        return null;
    };

    $used_tag_ids = [];
    foreach ($tag_items as $item) {
        if ($linked >= $config['auto_inlink']) break;
        $name = $item['name'];
        $tid  = $item['tag_id'];

        if (in_array($tid, $used_tag_ids, true)) {
            continue;
        }

        // 只匹配完整的 tag 名称，使用 /iu 不区分大小写及 Unicode 支持
        $pattern = '/(?<![a-zA-Z0-9_-])' . preg_quote($name, '/') . '(?![a-zA-Z0-9_-])/iu';

        // 快速跳过完全不存在于内容中的 tag
        if (!preg_match($pattern, $content)) continue;

        $offset = 0;
        $length = 0;
        $text_node = $find_text($root, $pattern, $offset, $length);
        if ($text_node === null) continue;

        // 获取 DOM 中实际匹配到的文本（保留原文大小写）
        $matched_text = substr($text_node->nodeValue, $offset, $length);
        if ($matched_text === false || $matched_text === '') {
            $matched_text = $name;
        }

        // 构建内链
        $href = function_exists('todo_get_tag_link')
            ? todo_get_tag_link($tid)
            : (function_exists('todo_get_site_url') ? rtrim(todo_get_site_url(), '/') . '/tag/' . $tid . '/' : '#');
        $href = esc_url($href);

        $a = $dom->createElement('a');
        $a->setAttribute('href', $href);
        $a->setAttribute('class', 'auto-tag-link');
        $a->setAttribute('title', $matched_text);
        $a->setAttribute('data-tag-id', (string) $tid);
        $a->appendChild($dom->createTextNode($matched_text));

        $figure = null;
        if ($has_images && $imaged < $img_count) {
            $img_url = todo_tag_get_seeded_image($config['media_dir'], intval($post_id) + $imaged);
            if ($img_url !== null) {
                $figure = $dom->createElement('figure');
                $figure->setAttribute('class', 'auto-tag-image');
                $img = $dom->createElement('img');
                $img->setAttribute('src', esc_url($img_url));
                $img->setAttribute('alt', $matched_text);
                $img->setAttribute('loading', 'lazy');
                $figure->appendChild($img);
                $imaged++;
            }
        }

        $parent = $text_node->parentNode;
        if (!$parent) continue;

        $before = substr($text_node->nodeValue, 0, $offset);
        $after  = substr($text_node->nodeValue, $offset + $length);

        $fragment = $dom->createDocumentFragment();
        if ($before !== '') $fragment->appendChild($dom->createTextNode($before));
        $fragment->appendChild($a);
        if ($figure !== null) $fragment->appendChild($figure);
        if ($after !== '') $fragment->appendChild($dom->createTextNode($after));

        $parent->replaceChild($fragment, $text_node);
        $used_tag_ids[] = $tid;
        $linked++;
    }

    // 未发生任何替换时返回原文，避免 DOM 序列化对内容造成无意义的格式调整
    if ($linked === 0) {
        return $content;
    }

    $html = '';
    foreach ($root->childNodes as $child) {
        $html .= $dom->saveHTML($child);
    }
    return $html;
}
add_filter('todo_the_content', 'todo_tag_auto_inlink_and_image', 15);
