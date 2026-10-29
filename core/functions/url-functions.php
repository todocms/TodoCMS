<?php
/**
 * TodoCMS URL和链接相关函数
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止重复加载
if (defined('TODO_URL_FUNCTIONS_LOADED')) {
    return;
}
define('TODO_URL_FUNCTIONS_LOADED', true);

/**
 * 获取分类URL
 */
if (!function_exists('todo_get_http_host')) {
    /**
     * 安全获取HTTP_HOST
     * @return string
     */
    function todo_get_http_host()
    {
        return isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    }
}

if (!function_exists('todo_get_site_url')) {
    /**
     * 获取站点URL
     * @param string $path
     * @return string
     */
    function todo_get_site_url($path = '')
    {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = todo_get_http_host();
        $default_port = ($protocol === 'https') ? 443 : 80;
        if (strpos($host, ':') === false && isset($_SERVER['SERVER_PORT'])) {
            $sp = (int) $_SERVER['SERVER_PORT'];
            if ($sp !== $default_port) {
                $host .= ':' . $sp;
            }
        }

        // 计算安装子目录前缀（保证二级目录/多级目录均可用）
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
            // 回退：根据脚本路径推断
            $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME']) : '';
            if ($script) {
                $dir = rtrim(dirname($script), '/');
                $dir = preg_replace('#/admin(/.*)?$#', '', $dir);
                if ($dir === '/' || $dir === '\\' || $dir === '.') {
                    $dir = '';
                }
                $prefix = $dir ? $dir : '';
            }
        }

        $base_url = $protocol . '://' . $host . $prefix;

        if ($path) {
            $norm = ltrim($path, '/');
            if ($norm !== '') {
                // 如果是查询字符串（以 ? 开头），直接拼接，不添加 index.php
                if (substr($norm, 0, 1) === '?') {
                    $base_url .= $path;
                } else {
                    $base_url .= '/' . $norm;
                }
            }
        }

        return $base_url;
    }
}

if (!function_exists('todo_get_login_url')) {
    /**
     * 获取登录页面URL
     * @param string $redirect_to 登录后重定向目标地址
     * @return string
     */
    function todo_get_login_url($redirect_to = '')
    {
        if (function_exists('todo_get_user_page_url')) {
            $url = todo_get_user_page_url('login');
        } else {
            $url = todo_get_site_url('/?user=login');
        }

        if (!empty($redirect_to)) {
            $url = todo_add_query_arg('redirect_to', urlencode($redirect_to), $url);
        }

        if (function_exists('get_current_language') && function_exists('get_language_url')) {
            $current_lang = get_current_language();
            if (!empty($current_lang)) {
                $url = get_language_url($current_lang, $url);
            }
        }

        return $url;
    }
}

if (!function_exists('todo_logout_url')) {
    /**
     * 获取退出登录页面URL
     * @param string $redirect_to 退出后重定向目标地址
     * @return string
     */
    function todo_logout_url($redirect_to = '')
    {
        $base = function_exists('todo_get_site_url') ? todo_get_site_url('/api/user/logout-handler.php') : '/api/user/logout-handler.php';
        if (empty($redirect_to)) {
            $redirect_to = function_exists('todo_home_url') ? todo_home_url() : '/';
        }
        return $base . '?redirect_to=' . urlencode(rtrim($redirect_to, '/'));
    }
}

function todo_get_category_url($category_id)
{
    $category_id = intval($category_id);
    if ($category_id > 0 && function_exists('todo_get_category_link')) {
        return todo_get_category_link($category_id);
    }

    global $tododb;

    $query = "SELECT t.term_id as id, t.name, t.slug FROM " . $tododb->prefix . "terms t 
             INNER JOIN " . $tododb->prefix . "term_taxonomy tt ON t.term_id = tt.term_id 
             WHERE t.term_id = ? AND tt.taxonomy = 'category'";
    $category = $tododb->get_row($query, array($category_id));

    if (!$category) {
        return '';
    }

    $url = todo_get_site_url() . '/' . $category->slug . '/';
    return get_language_url(current_locale(), $url);
}

/**
 * 获取用户中心URL
 */
function todo_get_user_center_url($section = '')
{
    $base = todo_home_url('/');
    if ($section !== '') {
        $url = $base . '/?user=' . urlencode($section);
    } else {
        $url = $base . '/?user=profile';
    }
    $lang = get_current_language();
    if (!empty($lang)) {
        $url = get_language_url($lang, $url);
    }
    return $url;
}

/**
 * 获取标签URL
 */
function todo_get_tag_url($tag_id)
{
    global $tododb;

    $query = "SELECT * FROM " . $tododb->prefix . "tags WHERE id = ?";
    $tag = $tododb->get_row($query, array($tag_id));

    if (!$tag) {
        return '';
    }

    $url = todo_get_site_url() . '/tag/' . $tag->slug . '/';
    return get_language_url(current_locale(), $url);
}

/**
 * 获取用户URL
 */
function todo_get_user_url($user_id)
{
    global $tododb;

    $user = $tododb->get_row(
        "SELECT * FROM " . $tododb->prefix . "users WHERE id = ?",
        [$user_id]
    );

    if (!$user) {
        return '';
    }

    $url = todo_get_site_url() . '/author/' . intval($user_id) . '/';
    return get_language_url(current_locale(), $url);
}

/**
 * 获取搜索URL
 */
function todo_get_search_url($query = '')
{
    $base = todo_home_url('/');
    $url = rtrim($base, '/') . '/search';
    if ($query) {
        $url .= '?q=' . rawurlencode($query);
    }
    return get_language_url(current_locale(), $url);
}

/**
 * 获取用户资料URL
 */
function todo_get_profile_url($user_id = null)
{
    if (!$user_id) {
        $user_id = get_current_user_id();
    }
    return todo_get_site_url() . '/profile.php?user_id=' . $user_id;
}

/**
 * 获取RSS订阅URL
 */
function todo_get_rss_url($category_id = null)
{
    $url = todo_get_site_url() . '/rss';
    if ($category_id) {
        $url .= '?category=' . $category_id;
    }
    return $url;
}

/**
 * 获取站点地图URL
 */
function todo_get_sitemap_url()
{
    return todo_get_site_url() . '/sitemap-index.xml';
}

/**
 * 获取API端点URL
 */
function todo_get_api_url($endpoint = '')
{
    $url = todo_get_site_url() . '/api/';
    if ($endpoint) {
        $url .= ltrim($endpoint, '/');
    }
    return $url;
}

/**
 * 获取AJAX URL
 */
function todo_get_ajax_url()
{
    return todo_admin_url('admin-ajax.php');
}

/**
 * 获取媒体URL
 */
function todo_get_media_url($media_id)
{
    // 如果存在媒体管理器，优先使用管理器获取（因为它已经集成了云存储逻辑）
    if (class_exists('Todo_Media_Manager')) {
        $media = Todo_Media_Manager::get_media($media_id);
        return $media ? ($media['url'] ?? '') : '';
    }

    global $tododb;

    $media = $tododb->get_row(
        "SELECT * FROM " . $tododb->prefix . "media WHERE id = ?",
        [$media_id]
    );

    if (!$media) {
        return '';
    }

    return todo_media_url_to_absolute($media->file_path);
}

/**
 * 生成分页URL
 */
function todo_get_pagination_url($page, $base_url = '')
{
    if (!$base_url) {
        $base_url = $_SERVER['REQUEST_URI'];
        // 移除现有的页码参数
        $base_url = preg_replace('/[?&]page=\d+/', '', $base_url);
    }

    $separator = (strpos($base_url, '?') !== false) ? '&' : '?';
    return $base_url . $separator . 'page=' . $page;
}

/**
 * 生成分页URL（兼容固定链接结构）
 * 当开启固定链接时生成 /page/N/ 形式，否则使用 ?paged=N
 */
function todo_get_paginated_url($page, $base_url)
{
    $final = '';
    if ($page <= 1) {
        $u = rtrim((string) $base_url, '/');
        $final = $u === '' ? '/' : $u;
    } else {
        $structure = function_exists('get_option') ? get_option('permalink_structure', '') : '';
        $hasRoutingQueryArgs = false;
        if (!empty($structure)) {
            $parts = @parse_url($base_url);
            if (isset($parts['query']) && $parts['query'] !== '') {
                $queryArgs = [];
                parse_str($parts['query'], $queryArgs);
                unset($queryArgs['paged'], $queryArgs['page']);
                $hasRoutingQueryArgs = !empty($queryArgs);
            }
        }
        if (!empty($structure) && !$hasRoutingQueryArgs) {
            $final = rtrim($base_url, '/') . '/page/' . intval($page) . '/';
        } else {
            $final = todo_add_query_arg('paged', $page, $base_url);
        }
    }

    if (function_exists('get_language_url') && function_exists('current_locale')) {
        $final = get_language_url(current_locale(), $final);
    }
    
    return $final;
}

/**
 * 获取当前页面URL
 */
function todo_get_current_url()
{
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = todo_get_http_host();
    $uri = function_exists('todo_get_request_uri') ? todo_get_request_uri() : (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/');

    // 过滤掉不正确的路径、静态资源，避免作为“当前页面”返回
    // 这能防止在资源 404 响应中生成的语言切换链接指向资源本身
    if (preg_match('/\.(ico|png|jpg|jpeg|gif|css|js|xml|txt)$/i', parse_url($uri, PHP_URL_PATH))) {
        return get_language_url(current_locale(), todo_get_site_url());
    }

    $url = $protocol . '://' . $host . $uri;
    return get_language_url(current_locale(), $url);
}

/**
 * 获取上一页URL
 */
function todo_get_previous_url()
{
    return isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : todo_get_site_url();
}

/**
 * 构建查询字符串
 */
function todo_build_query($params)
{
    return http_build_query($params);
}

/**
 * 解析URL参数
 */
function todo_parse_url_params($url)
{
    $parsed = parse_url($url);
    $params = array();

    if (isset($parsed['query'])) {
        parse_str($parsed['query'], $params);
    }

    return $params;
}

/**
 * 添加URL参数
 */
function todo_add_url_param($url, $key, $value)
{
    $params = todo_parse_url_params($url);
    $params[$key] = $value;

    $base_url = strtok($url, '?');
    return $base_url . '?' . todo_build_query($params);
}

/**
 * 移除URL参数
 */
function todo_remove_url_param($url, $key)
{
    $params = todo_parse_url_params($url);
    unset($params[$key]);

    $base_url = strtok($url, '?');
    if (empty($params)) {
        return $base_url;
    }

    return $base_url . '?' . todo_build_query($params);
}

/**
 * 获取面包屑导航数据
 * 优先使用主导航菜单结构，如果当前栏目不在菜单中则使用默认分类层级
 */
function todo_get_breadcrumb_data()
{
    global $tododb;

    $ttl = function_exists('todo_get_fragment_cache_ttl') ? todo_get_fragment_cache_ttl() : 120;
    $lang = current_locale();
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $cache_key = 'breadcrumb|' . md5($uri);
    if ($ttl !== 0) {
        $cached = Todo_Cache_Manager::get($cache_key, 'fragments', $lang);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }
    }

    $breadcrumbs = array();
    $breadcrumbs[] = array(
        'title' => __('首页'),
        'url' => todo_get_site_url(),
        'is_current' => false
    );

    // 用户中心页面：基于 ?user=xxx 或 /user/xxx 重写路径构建面包屑
    $user_page = isset($_GET['user']) ? sanitize_text_field((string) $_GET['user']) : '';
    if ($user_page === '') {
        $path_uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
        if (preg_match('#/user/([a-zA-Z0-9_\-]+)#', $path_uri, $m)) {
            $user_page = $m[1];
        }
    }

    if ($user_page !== '') {
        $user_title_map = array(
            'center' => __('会员中心'),
            'orders' => __('我的订单'),
            'order-detail' => __('订单详情'),
            'order-success' => __('提交成功'),
            'payment' => __('订单支付'),
            'order-review' => __('订单评价'),
            'addresses' => __('地址管理'),
            'profile' => __('编辑资料'),
            'favorites' => __('收藏夹'),
            'cart' => __('购物车'),
            'checkout' => __('结算'),
            'wallet' => __('我的钱包'),
            'coupons' => __('我的卡券'),
            'messages' => __('消息中心'),
            'settings' => __('账户设置'),
            'login' => __('登录'),
            'register' => __('注册'),
            'forgot_password' => __('找回密码'),
        );

        $center_title = $user_title_map['center'];
        $center_url = function_exists('todo_get_user_page_url') ? todo_get_user_page_url('center') : todo_get_site_url() . '/?user=profile';

        if ($user_page === 'center') {
            $breadcrumbs[] = array(
                'title' => $center_title,
                'url' => $center_url,
                'is_current' => true,
                'type' => 'user'
            );
        } else {
            $page_title = isset($user_title_map[$user_page]) ? $user_title_map[$user_page] : $user_page;
            $page_url = function_exists('todo_get_user_page_url') ? todo_get_user_page_url($user_page) : (todo_get_site_url() . '/?user=' . urlencode($user_page));

            $breadcrumbs[] = array(
                'title' => $center_title,
                'url' => $center_url,
                'is_current' => false,
                'type' => 'user'
            );
            $breadcrumbs[] = array(
                'title' => $page_title,
                'url' => $page_url,
                'is_current' => true,
                'type' => 'user'
            );
        }

        if ($ttl !== 0) {
            Todo_Cache_Manager::set($cache_key, $breadcrumbs, $ttl, 'fragments', $lang);
        }
        return $breadcrumbs;
    }

    // 标签页面：基于 ?page=tag&id=xxx 或固定链接 /tag/{slug}/ 构建面包屑
    $tag_page = isset($_GET['page']) ? sanitize_text_field((string) $_GET['page']) : '';
    if ($tag_page === 'tag') {
        $tag_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        // 支持固定链接 /tag/{slug}/ 模式：从 tag_name 解析 tag_id
        if (!$tag_id && isset($_GET['tag_name'])) {
            $tag_slug = rawurldecode(trim((string) $_GET['tag_name']));
            if ($tag_slug !== '' && function_exists('get_tag_by_slug')) {
                $tag_data = get_tag_by_slug($tag_slug);
                if ($tag_data) {
                    $tag_id = is_array($tag_data)
                        ? (intval($tag_data['term_id'] ?? $tag_data['id'] ?? 0))
                        : (intval($tag_data->term_id ?? $tag_data->id ?? 0));
                }
            }
        }
        $tag_index_url = todo_home_url('/tag/');
        if ($tag_id) {
            $tag = function_exists('todo_get_tag') ? todo_get_tag($tag_id) : null;
            if ($tag) {
                $tag_name = is_array($tag) ? ($tag['name'] ?? '') : ($tag->name ?? '');
                $breadcrumbs[] = array(
                    'title' => __('标签'),
                    'url' => $tag_index_url,
                    'is_current' => false,
                    'type' => 'tag_index'
                );
                $tag_url = function_exists('todo_get_tag_link') ? todo_get_tag_link($tag_id) : (todo_get_site_url() . '/?page=tag&id=' . $tag_id);
                $breadcrumbs[] = array(
                    'title' => $tag_name ?: __('标签'),
                    'url' => $tag_url,
                    'is_current' => true,
                    'type' => 'tag'
                );
            }
        } else {
            $breadcrumbs[] = array(
                'title' => __('标签'),
                'url' => $tag_index_url,
                'is_current' => true,
                'type' => 'tag_index'
            );
        }
        if ($ttl !== 0) {
            Todo_Cache_Manager::set($cache_key, $breadcrumbs, $ttl, 'fragments', $lang);
        }
        return $breadcrumbs;
    }

    // 归档页面：基于 ?page=archive&archive_year=... 或固定链接 /YYYY/MM/ 构建面包屑
    $page = isset($_GET['page']) ? sanitize_text_field((string) $_GET['page']) : '';
    $archive_year = 0;
    $archive_month = 0;
    $archive_day = 0;
    if (isset($_GET['archive_year'])) $archive_year = intval($_GET['archive_year']);
    if (isset($_GET['archive_month'])) $archive_month = intval($_GET['archive_month']);
    if (isset($_GET['archive_day'])) $archive_day = intval($_GET['archive_day']);
    if ($archive_year <= 0 && isset($_GET['year'])) $archive_year = intval($_GET['year']);
    if ($archive_month <= 0 && isset($_GET['month'])) $archive_month = intval($_GET['month']);
    if ($archive_month <= 0 && isset($_GET['monthnum'])) $archive_month = intval($_GET['monthnum']);
    if ($archive_day <= 0 && isset($_GET['day'])) $archive_day = intval($_GET['day']);

    if ($page === 'archive' || $archive_year > 0) {
        $archive_index_url = todo_home_url('/archive');
        $breadcrumbs[] = array(
            'title' => __('归档'),
            'url' => $archive_index_url,
            'is_current' => ($archive_year <= 0),
            'type' => 'archive_index'
        );

        if ($archive_year > 0) {
            $date_title = $archive_year;
            if ($archive_month > 0) {
                $date_title .= '/' . $archive_month;
            }
            if ($archive_day > 0) {
                $date_title .= '/' . $archive_day;
            }

            if ($archive_month > 0) {
                $archive_url = todo_home_url(sprintf('/%04d/%02d/', $archive_year, $archive_month));
            } else {
                $archive_url = todo_home_url(sprintf('/%04d/', $archive_year));
            }

            $breadcrumbs[] = array(
                'title' => $date_title,
                'url' => $archive_url,
                'is_current' => true,
                'type' => 'archive'
            );
        }

        if ($ttl !== 0) {
            Todo_Cache_Manager::set($cache_key, $breadcrumbs, $ttl, 'fragments', $lang);
        }
        return $breadcrumbs;
    }

    // 获取当前页面信息
    $current_category_id = null;
    $current_post = null;
    $is_single_page = is_single() || is_page();
    $is_category_page = is_category();
    $has_category_param = isset($_GET['category_id']) || isset($_GET['cat']) || isset($_GET['category']);
    $has_post_param = isset($_GET['post_id']) || isset($_GET['p']) || isset($_GET['id']);
    // 优先响应分类参数，忽略可能产生歧义的 id 参数
    if ($has_category_param && !isset($_GET['post_id']) && !isset($_GET['p'])) {
        $is_category_page = true;
    }

    // 检测当前页面类型（支持 category_id、post_id、p、id 以及全局 $post/$todo_post）
    // 优先从URL参数获取分类ID（如果存在）
    // 获取当前分类ID
    $current_category_id = 0;
    if (isset($_GET['category'])) {
        $current_category_id = intval($_GET['category']);
    } elseif (isset($_GET['cat'])) {
        $current_category_id = intval($_GET['cat']);
    } elseif (isset($_GET['category_id'])) {
        $current_category_id = intval($_GET['category_id']);
    } elseif (isset($GLOBALS['current_category_id']) && $GLOBALS['current_category_id'] > 0) {
        $current_category_id = intval($GLOBALS['current_category_id']);
    }

    // 如果URL中没有分类参数，尝试从查询对象获取
    if (!$current_category_id && $is_category_page) {
        $current_category_id = intval(get_queried_object_id());
    }

    // 识别文章ID（详情页/单页），优先显式URL参数，其次仅在详情/单页上下文读取全局
    $post_id = null;
    if (isset($_GET['post_id'])) {
        $post_id = intval($_GET['post_id']);
    } elseif (isset($_GET['p'])) {
        $post_id = intval($_GET['p']);
    } elseif (isset($_GET['id']) && !$has_category_param) {
        // 只有当没有分类参数时，才将 id 视为文章ID
        $post_id = intval($_GET['id']);
    } elseif ($is_single_page && !$has_category_param) {
        if (isset($GLOBALS['todo_post']) && is_object($GLOBALS['todo_post']) && !empty($GLOBALS['todo_post']->ID)) {
            $post_id = intval($GLOBALS['todo_post']->ID);
        } elseif (isset($GLOBALS['post']) && is_object($GLOBALS['post']) && !empty($GLOBALS['post']->ID)) {
            $post_id = intval($GLOBALS['post']->ID);
        } elseif (isset($GLOBALS['current_post']) && is_object($GLOBALS['current_post']) && !empty($GLOBALS['current_post']->ID)) {
            $post_id = intval($GLOBALS['current_post']->ID);
        } elseif (function_exists('get_current_post')) {
            $cp = get_current_post();
            if (is_object($cp) && !empty($cp->ID)) {
                $post_id = intval($cp->ID);
            }
        }
    }

    // 如果识别到文章ID，查询文章信息
    if ($post_id) {
        $current_post = $tododb->get_row(
            "SELECT p.ID AS id, p.post_title, p.category_id 
                 FROM " . $tododb->prefix . "posts p 
                 WHERE p.ID = ?",
            [$post_id]
        );

        // 如果URL中没有指定分类参数，则从文章数据中获取分类ID
        if (!$current_category_id && $current_post) {
            if (!empty($current_post->category_id)) {
                $current_category_id = intval($current_post->category_id);
            } else {
                $primary_cat_id = todo_get_post_primary_category_id($current_post->id);
                if ($primary_cat_id) {
                    $current_category_id = intval($primary_cat_id);
                }
            }
        }
    }

    // 如果有当前分类，尝试从主导航菜单获取面包屑
    if ($current_category_id) {
        $menu_breadcrumbs = todo_get_menu_breadcrumb($current_category_id);
        if (!empty($menu_breadcrumbs)) {
            // 使用菜单结构
            $breadcrumbs = array_merge($breadcrumbs, $menu_breadcrumbs);
        } else {
            // 使用默认分类层级
            $category_breadcrumbs = todo_get_category_breadcrumb($current_category_id);
            $breadcrumbs = array_merge($breadcrumbs, $category_breadcrumbs);
        }
    }

    // 非分类页且识别到文章时添加文章标题
    // 如果同时有分类和文章参数，优先显示文章标题（详情页场景）
    if ($current_post && ($is_single_page || $has_post_param)) {
        // 在详情页场景下，确保前置的分类面包屑项不被标记为当前
        foreach ($breadcrumbs as &$crumb) {
            if (isset($crumb['is_current']) && $crumb['is_current']) {
                $crumb['is_current'] = false;
            }
        }
        unset($crumb);

        // 获取数据模型信息
        $model_info = todo_get_post_model_info($current_post->id);
        $content_type = '详情';

        if ($model_info && isset($model_info['model_key'])) {
            switch ($model_info['model_key']) {
                case 'product':
                    $content_type = '产品详情';
                    break;
                case 'article':
                    $content_type = '文章详情';
                    break;
                case 'images':
                    $content_type = '图集详情';
                    break;
                case 'download':
                    $content_type = '下载详情';
                    break;
                case 'media':
                    $content_type = '视频详情';
                    break;
                case 'single':
                    $content_type = '单页';
                    break;
                case 'special':
                    $content_type = '专题详情';
                    break;
                case 'guestbook':
                    $content_type = '留言详情';
                    break;
                case 'recruit':
                    $content_type = '招聘详情';
                    break;
                default:
                    $content_type = '详情';
            }
        }

        // 翻译内容类型（面包屑类型标注）
        $ct_tmp = __($content_type);
        $content_type = $ct_tmp;

        $ml = todo_get_post_multilang($current_post->id, null, ['fallback' => true, 'prefer_custom_url' => true]);
        $title = $current_post->post_title;
        if (is_array($ml) && !empty($ml['title'])) {
            $title = (string) $ml['title'];
        }
        $url = todo_get_permalink($current_post->id);
        if (is_array($ml) && !empty($ml['url'])) {
            $url = (string) $ml['url'];
        }
        $breadcrumbs[] = array(
            'title' => $title,
            'url' => $url,
            'is_current' => true,
            'type' => $content_type
        );
    }

    if ($ttl !== 0) {
        Todo_Cache_Manager::set($cache_key, $breadcrumbs, $ttl, 'fragments', $lang);
    }
    return $breadcrumbs;
}

/**
 * 从主导航菜单获取面包屑路径
 * 
 * @param int $category_id 分类ID
 * @return array 菜单面包屑数组
 */
function todo_get_menu_breadcrumb($category_id)
{
    global $tododb;

    $breadcrumbs = array();

    // 获取primary菜单
    $menu = $tododb->get_row(
        "SELECT * FROM " . $tododb->prefix . "menus WHERE location = ? AND status = 1",
        ['primary']
    );

    if (!$menu) {
        return $breadcrumbs;
    }

    // 查找当前分类在菜单中的项目
    $menu_item = $tododb->get_row(
        "SELECT * FROM " . $tododb->prefix . "menu_items 
         WHERE menu_id = ? AND type = 'category' AND object_id = ?",
        [$menu->id, $category_id]
    );

    if (!$menu_item) {
        return $breadcrumbs;
    }

    // 构建菜单路径
    $menu_path = array();
    $current_item = $menu_item;

    // 向上查找父级菜单项
    while ($current_item) {
        array_unshift($menu_path, $current_item);

        if ($current_item->parent_id > 0) {
            $current_item = $tododb->get_row(
                "SELECT * FROM " . $tododb->prefix . "menu_items WHERE id = ?",
                [$current_item->parent_id]
            );
        } else {
            break;
        }
    }

    // 转换为面包屑格式
    foreach ($menu_path as $item) {
        $url = '';
        switch ($item->type) {
            case 'category':
                $url = todo_get_category_link($item->object_id);
                break;
            case 'content':
                $url = todo_get_content_url($item->object_id);
                break;
            case 'custom':
            case 'external':
                $url = $item->url;
                break;
        }

        // 翻译菜单项标题
        $menu_title = __($item->title);

        $breadcrumbs[] = array(
            'title' => $menu_title,
            'url' => $url,
            'is_current' => ($item->id == $menu_item->id)
        );
    }

    return $breadcrumbs;
}

/**
 * 获取分类层级面包屑
 * 
 * @param int $category_id 分类ID
 * @return array 分类面包屑数组
 */
function todo_get_category_breadcrumb($category_id)
{
    global $tododb;
    static $term_cache = [];

    $ttl = function_exists('todo_get_fragment_cache_ttl') ? (int) todo_get_fragment_cache_ttl() : 120;
    $lang = current_locale();
    $cache_key = 'category_breadcrumb|' . (int) $category_id;
    if ($ttl !== 0) {
        $cached = Todo_Cache_Manager::get($cache_key, 'fragments', $lang);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }
    }

    $breadcrumbs = array();

    // 内部获取分类函数（带静态缓存）
    $get_cached_category = function ($id) use ($tododb, &$term_cache) {
        if (isset($term_cache[$id])) {
            return $term_cache[$id];
        }
        $row = $tododb->get_row(
            "SELECT t.term_id as id, t.name, t.slug, tt.parent 
             FROM " . $tododb->prefix . "terms t 
             INNER JOIN " . $tododb->prefix . "term_taxonomy tt ON t.term_id = tt.term_id 
             WHERE t.term_id = ? AND tt.taxonomy = 'category'",
            [$id]
        );
        if ($row) {
            $term_cache[$id] = $row;
        }
        return $row;
    };

    // 获取分类信息
    $category = $get_cached_category($category_id);

    if (!$category) {
        return $breadcrumbs;
    }

    // 获取父级分类路径
    $parent_categories = array();
    $current_category = $category;

    // 防止死循环
    $loop_protection = 0;
    while ($current_category && $current_category->parent > 0 && $loop_protection < 20) {
        $parent = $get_cached_category($current_category->parent);

        if ($parent) {
            array_unshift($parent_categories, $parent);
            $current_category = $parent;
        } else {
            break;
        }
        $loop_protection++;
    }

    // 添加父级分类到面包屑
    foreach ($parent_categories as $parent_cat) {
        // 翻译分类名称
        $parent_title = __($parent_cat->name);

        $breadcrumbs[] = array(
            'title' => $parent_title,
            'url' => todo_get_category_link($parent_cat->id),
            'is_current' => false
        );
    }

    // 添加当前分类
    // 翻译分类名称
    $current_cat_title = __($category->name);

    $breadcrumbs[] = array(
        'title' => $current_cat_title,
        'url' => todo_get_category_link($category->id),
        'is_current' => true
    );

    if ($ttl !== 0) {
        Todo_Cache_Manager::set($cache_key, $breadcrumbs, $ttl, 'fragments', $lang);
    }
    return $breadcrumbs;
}

/**
 * 获取文章的数据模型信息
 * 
 * @param int $post_id 文章ID
 * @return array|null 模型信息
 */
function todo_get_post_model_info($post_id)
{
    global $tododb;
    static $cache = [];
    $post_id = intval($post_id);
    if ($post_id <= 0) {
        return null;
    }
    if (array_key_exists($post_id, $cache)) {
        return $cache[$post_id];
    }

    // 获取文章的分类信息（直接从posts表读取）
    $post_row = $tododb->get_row(
        "SELECT ID AS id, category_id FROM " . $tododb->prefix . "posts WHERE ID = ?",
        [$post_id]
    );
    if (!$post_row || empty($post_row->category_id)) {
        $cache[$post_id] = null;
        return null;
    }

    // 获取文章所属分类ID
    $category_id = intval($post_row->category_id);
    $model_info = $tododb->get_row(
        "SELECT m.model_id, m.model_name, m.model_key 
         FROM " . $tododb->prefix . "term_models tm 
         INNER JOIN " . $tododb->prefix . "models m ON tm.model_id = m.model_id 
         WHERE tm.term_id = ?",
        [$category_id]
    );

    $cache[$post_id] = $model_info ? (array) $model_info : null;
    return $cache[$post_id];
}

/**
 * 生成规范化URL
 */
function todo_get_canonical_url()
{
    $current_url = todo_get_current_url();

    // 移除不必要的参数
    $remove_params = array('utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term');
    foreach ($remove_params as $param) {
        $current_url = todo_remove_url_param($current_url, $param);
    }

    return $current_url;
}

/**
 * 检查URL是否为外部链接
 */
function todo_is_external_url($url)
{
    $site_url = todo_get_site_url();
    $site_domain = parse_url($site_url, PHP_URL_HOST);
    $url_domain = parse_url($url, PHP_URL_HOST);

    return ($url_domain && $url_domain !== $site_domain);
}

/**
 * 获取安全的重定向URL
 */
function todo_get_safe_redirect_url($url, $fallback = '')
{
    if (!$url) {
        return $fallback ?: todo_get_site_url();
    }

    // 只允许内部重定向
    if (todo_is_external_url($url)) {
        return $fallback ?: todo_get_site_url();
    }

    return $url;
}

/**
 * 生成短链接
 */
function todo_generate_short_url($original_url)
{
    global $tododb;

    // 检查是否已存在短链接
    $existing = $tododb->get_row(
        "SELECT * FROM " . $tododb->prefix . "short_urls WHERE original_url = ?",
        [$original_url]
    );

    if ($existing) {
        return todo_get_site_url() . '/s/' . $existing->short_code;
    }

    // 生成新的短代码
    $short_code = generate_random_string(6);

    // 确保短代码唯一
    while (
        $tododb->get_var(
            "SELECT COUNT(*) FROM " . $tododb->prefix . "short_urls WHERE short_code = ?",
            [$short_code]
        )
    ) {
        $short_code = generate_random_string(6);
    }

    // 保存短链接
    // 使用PDO插入
    $tododb->insert(
        $tododb->prefix . 'short_urls',
        array(
            'original_url' => $original_url,
            'short_code' => $short_code,
            'created_at' => current_time('mysql')
        )
    );

    return todo_get_site_url() . '/s/' . $short_code;
}

/**
 * 解析短链接
 */
function todo_resolve_short_url($short_code)
{
    global $tododb;

    $short_url = $tododb->get_row(
        "SELECT * FROM " . $tododb->prefix . "short_urls WHERE short_code = ?",
        [$short_code]
    );

    if ($short_url) {
        // 增加点击次数
        // 使用PDO更新
        $tododb->update(
            $tododb->prefix . 'short_urls',
            array('clicks' => $short_url->clicks + 1),
            array('id' => $short_url->id)
        );

        return $short_url->original_url;
    }

    return false;
}

/**
 * 获取当前用户ID
 */
if (!function_exists('get_current_user_id')) {
    function get_current_user_id()
    {
        // 使用 Todo_Session_Manager 获取用户ID
        if (class_exists('Todo_Session_Manager')) {
            Todo_Session_Manager::init();
            if (defined('IS_ADMIN') && IS_ADMIN) {
                return Todo_Session_Manager::get_admin_user_id() ?: 0;
            } else {
                return Todo_Session_Manager::get_frontend_user_id() ?: 0;
            }
        }
        return 0;
    }
}

/**
 * 获取用户中心特定页面URL
 * 标准化使用 ?user= 查询参数格式
 * 
 * @param string $page 页面名称（login, register, profile, orders, favorites, cart等）
 * @return string URL
 */
function todo_get_user_page_url($page = '')
{
    // 标准化使用 ?user= 查询参数格式
    if (!empty($page)) {
        $url = site_url('?user=' . urlencode($page));
    } else {
        // 如果没有指定页面，返回用户中心基础URL
        $url = todo_get_user_center_url();
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
 * 获取当前时间
 */
if (!function_exists('current_time')) {
    function current_time($type = 'mysql')
    {
        switch ($type) {
            case 'mysql':
                return date('Y-m-d H:i:s');
            case 'timestamp':
                return time();
            default:
                return date($type);
        }
    }
}
/**
 * 获取文章的主分类ID（回退方法）
 * 优先按 term_relationships.term_order 排序的第一个分类作为主分类
 *
 * @param int $post_id 文章ID
 * @return int 主分类ID，找不到返回 0
 */
function todo_get_post_primary_category_id($post_id)
{
    static $primary_cat_cache = array();

    $post_id = intval($post_id);
    if ($post_id <= 0) {
        return 0;
    }

    if (isset($primary_cat_cache[$post_id])) {
        return $primary_cat_cache[$post_id];
    }

    // 优先从缓存获取全部分类
    if (function_exists('get_the_terms')) {
        $terms = get_the_terms($post_id, 'category');
        if ($terms && is_array($terms)) {
            $first = reset($terms);
            $cat_id = isset($first->term_id) ? intval($first->term_id) : (isset($first->id) ? intval($first->id) : 0);
            if ($cat_id > 0) {
                $primary_cat_cache[$post_id] = $cat_id;
                return $cat_id;
            }
        }
    }

    global $tododb;
    $sql = "SELECT tt.term_id
            FROM " . $tododb->prefix . "term_relationships tr
            INNER JOIN " . $tododb->prefix . "term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE tr.object_id = ? AND tt.taxonomy = 'category'
            ORDER BY tr.term_order ASC
            LIMIT 1";

    $row = $tododb->get_row($sql, [$post_id]);
    $cat_id = $row && isset($row->term_id) ? intval($row->term_id) : 0;
    $primary_cat_cache[$post_id] = $cat_id;
    return $cat_id;
}

/**
 * 获取文章缩略图URL
 */
function todo_get_post_thumbnail_url($post_id, $size = 'large')
{
    global $tododb;
    $post_id = intval($post_id);
    if ($post_id <= 0) {
        return '';
    }

    $candidate = '';
    $row = $tododb->get_row("SELECT featured_image FROM " . $tododb->prefix . "posts WHERE ID = ?", [$post_id]);
    if ($row && isset($row->featured_image) && $row->featured_image !== '') {
        $raw = $row->featured_image;
        if (is_string($raw)) {
            $trim = trim($raw);
            if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
                $decoded = json_decode($trim, true);
                if (is_array($decoded)) {
                    if (isset($decoded['url']) && $decoded['url'] !== '') {
                        $candidate = $decoded['url'];
                    }
                    if ($candidate === '' && isset($decoded['images']) && is_array($decoded['images']) && isset($decoded['images'][0]['url'])) {
                        $candidate = $decoded['images'][0]['url'];
                    }
                    if ($candidate === '' && isset($decoded[0]) && is_array($decoded[0]) && isset($decoded[0]['url'])) {
                        $candidate = $decoded[0]['url'];
                    }
                }
            }
            if ($candidate === '' && $trim !== '') {
                $candidate = $trim;
            }
        }
    }

    if ($candidate === '') {
        $meta = $tododb->get_row(
            "SELECT meta_value FROM " . $tododb->prefix . "postmeta WHERE post_id = ? AND meta_key IN ('featured_image_url') ORDER BY meta_id DESC LIMIT 1",
            [$post_id]
        );
        if ($meta && isset($meta->meta_value) && $meta->meta_value !== '') {
            $candidate = $meta->meta_value;
        }
    }

    if ($candidate === '' && function_exists('get_the_post_thumbnail_url')) {
        $u = get_the_post_thumbnail_url($post_id, 'full');
        if (!empty($u)) {
            $candidate = $u;
        }
    }

    if ($candidate !== '' && function_exists('todo_media_url_to_absolute')) {
        $candidate = todo_media_url_to_absolute($candidate);
    }
    return $candidate;
}

if (!function_exists('todo_is_internal_url')) {
    function todo_is_internal_url($url)
    {
        if (!is_string($url)) {
            return false;
        }
        $url = trim($url);
        if ($url === '' || strpos($url, '/') === 0) {
            return true;
        }
        if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0 && strpos($url, '//') !== 0) {
            return true;
        }

        $base = function_exists('todo_get_site_url') ? todo_get_site_url('/') : '';
        $base_parts = @parse_url($base);
        $url_parts = @parse_url((strpos($url, '//') === 0) ? ('http:' . $url) : $url);
        if (!is_array($base_parts) || !is_array($url_parts)) {
            return false;
        }

        $bh = isset($base_parts['host']) ? strtolower((string) $base_parts['host']) : '';
        $uh = isset($url_parts['host']) ? strtolower((string) $url_parts['host']) : '';
        if ($bh === '' || $uh === '') {
            return false;
        }
        if ($bh === $uh) {
            return true;
        }
        if (preg_replace('/^www\./', '', $bh) === preg_replace('/^www\./', '', $uh)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('todo_parse_url_utf8')) {
    /**
     * UTF-8 安全的 URL 解析器，防止 PHP 原生 parse_url() 损坏中文等非 ASCII 路径字符
     *
     * @param string $url 待解析的 URL 或相对路径
     * @return array|false 解析后的数组
     */
    function todo_parse_url_utf8($url)
    {
        if (!is_string($url) || $url === '') {
            return false;
        }
        if (strpos($url, '://') === false && strpos($url, '//') !== 0) {
            $parts = array();
            $q_pos = strpos($url, '?');
            $f_pos = strpos($url, '#');

            $path_end = strlen($url);
            if ($q_pos !== false) {
                $path_end = min($path_end, $q_pos);
            }
            if ($f_pos !== false) {
                $path_end = min($path_end, $f_pos);
            }

            $parts['path'] = substr($url, 0, $path_end);

            if ($q_pos !== false) {
                $query_end = ($f_pos !== false && $f_pos > $q_pos) ? $f_pos : strlen($url);
                $parts['query'] = substr($url, $q_pos + 1, $query_end - ($q_pos + 1));
            }
            if ($f_pos !== false) {
                $parts['fragment'] = substr($url, $f_pos + 1);
            }
            return $parts;
        }

        return @parse_url($url);
    }
}

if (!function_exists('todo_media_url_to_relative')) {
    function todo_media_url_to_relative($url)
    {
        if (!is_string($url)) {
            return '';
        }
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (strpos($url, '{') === 0 || strpos($url, '[') === 0) {
            $decoded = json_decode($url, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                array_walk_recursive($decoded, function (&$v) {
                    if (is_string($v) && $v !== '') {
                        $v = todo_media_url_to_relative($v);
                    }
                });
                return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            return $url;
        }

        // 先拦截检查：无论域名是本地主站、云存储（七牛/阿里/腾讯）还是自建 CDN，
        // 只要包含 content/(uploads|assets|themes|plugins)/，一律规范化提取为干净的基准相对路径入库
        $u_parse = todo_parse_url_utf8($url);
        $u_path = (is_array($u_parse) && isset($u_parse['path'])) ? $u_parse['path'] : $url;
        $u_query = (is_array($u_parse) && isset($u_parse['query'])) ? ('?' . $u_parse['query']) : '';
        $u_frag  = (is_array($u_parse) && isset($u_parse['fragment'])) ? ('#' . $u_parse['fragment']) : '';

        if (preg_match('#/(content/(?:uploads|assets|themes|plugins)/.*)$#i', $u_path, $matches)) {
            return '/' . $matches[1] . $u_query . $u_frag;
        }

        $base = function_exists('todo_get_site_url') ? todo_get_site_url('/') : '';
        if (!$base) {
            return $url;
        }

        // 统一去除协议部分进行比较，以处理 http/https 混合情况
        $base_no_proto = preg_replace('#^https?:#', '', rtrim($base, '/'));
        $url_no_proto = preg_replace('#^https?:#', '', $url);

        if (strpos($url_no_proto, $base_no_proto) === 0) {
            $rel = substr($url_no_proto, strlen($base_no_proto));
            // 确保以 / 开头
            if ($rel === '' || $rel[0] !== '/') {
                $rel = '/' . $rel;
            }
            if (preg_match('#/(content/(?:uploads|assets|themes|plugins)/.*)$#i', $rel, $matches)) {
                return '/' . $matches[1];
            }
            $base_path = parse_url($base, PHP_URL_PATH);
            $prefix = ($base_path && $base_path !== '/') ? rtrim($base_path, '/') : '';
            if ($prefix !== '') {
                if (strpos($rel, $prefix) === 0) {
                    $rel = substr($rel, strlen($prefix));
                    if ($rel === '' || $rel[0] !== '/') {
                        $rel = '/' . $rel;
                    }
                }
            }
            return $rel;
        }

        // 如果不属于当前站点，且包含协议，则判断是否为内部 URL
        if (strpos($url, '://') !== false && function_exists('todo_is_internal_url') && !todo_is_internal_url($url)) {
            return $url;
        }

        // 最后的兜底：如果是内部 URL 但 base 匹配失败，尝试解析 path
        $u = todo_parse_url_utf8($url);
        if (is_array($u) && isset($u['path'])) {
            $path = $u['path'];
            $query = isset($u['query']) ? ('?' . $u['query']) : '';
            $fragment = isset($u['fragment']) ? ('#' . $u['fragment']) : '';

            // 识别基于 content/(uploads|assets|themes|plugins) 的基础相对路径，去除子目录前缀保持干净入库
            if (preg_match('#/(content/(?:uploads|assets|themes|plugins)/.*)$#i', $path, $matches)) {
                $content_rel = $matches[1];
                return '/' . $content_rel . $query . $fragment;
            }

            // 如果URL已经是根相对路径，剥离子目录前缀以规范化存储
            $base_path = parse_url($base, PHP_URL_PATH);
            if ($base_path && $base_path !== '/' && $base_path !== '') {
                $prefix = rtrim($base_path, '/');
                if (strpos($path, $prefix) === 0) {
                    $path = substr($path, strlen($prefix));
                    if ($path === '' || $path[0] !== '/') {
                        $path = '/' . $path;
                    }
                }
            }
            return $path . $query . $fragment;
        }

        return $url;
    }
}

if (!function_exists('todo_convert_content_urls_to_relative')) {
    /**
     * 将HTML内容中的所有内部媒体URL与带子目录前缀的相对路径转换为规范相对路径（/content/uploads/...）
     * 
     * @param string $content HTML内容
     * @return string 处理后的HTML内容
     */
    function todo_convert_content_urls_to_relative($content)
    {
        if (empty($content) || !is_string($content)) {
            return $content;
        }

        $was_slashed = (strpos($content, '\"') !== false || strpos($content, "\'") !== false);
        if ($was_slashed) {
            $content = stripslashes($content);
        }

        // 匹配 src/href/poster 等属性中的绝对URL及带子目录的相对路径（包含 /content/...）
        $pattern = '/(_?src|href|poster)=(["\']|&quot;|&#039;|&#39;)(https?:\/\/[^"\'&\s>]+\/content\/(?:uploads|assets|themes|plugins)\/[^"\'&\s>]*|\/[^"\'&\s>]*content\/(?:uploads|assets|themes|plugins)\/[^"\'&\s>]*)\2/i';

        $content = preg_replace_callback($pattern, function($matches) {
            $attr = $matches[1];
            $quote = $matches[2];
            $url = $matches[3];
            $relative_url = todo_media_url_to_relative($url);
            return $attr . '=' . $quote . $relative_url . $quote;
        }, $content);

        if ($was_slashed) {
            $content = addslashes($content);
        }

        return $content;
    }
}

if (!function_exists('todo_normalize_content_root_relative_urls')) {
    /**
     * 前端 HTML 内容媒体 URL 规范化转换器（Rule 1 + 3）
     * 负责将内容中的媒体路径（如 /content/uploads/... 或 /todocms/content/uploads/...）转换为前端运行期绝对 URL。
     * 支持 CDN 转换 + 子目录部署包装（Rule 1 调用 Rule 3）。
     *
     * @param string $content HTML内容
     * @return string 处理后的HTML内容
     */
    function todo_normalize_content_root_relative_urls($content)
    {
        if (empty($content) || !is_string($content)) {
            return $content;
        }

        // 匹配 src/href/poster 属性中包含 content/(uploads|assets|themes|plugins) 的路径，支持普通引号及实体转义
        $pattern = '/(src|href|poster)=(["\']|&quot;|&#039;|&#39;)((?:https?:\/\/[^"\'&\s>]*)?\/?(?:[^\/"\'&\s>]+\/)*content\/(?:uploads|assets|themes|plugins)\/[^"\'&\s>]*)\2/i';

        return preg_replace_callback(
            $pattern,
            function ($matches) {
                $attr = $matches[1];
                $quote = $matches[2];
                $path = $matches[3];
                $normalized = function_exists('todo_media_url_to_runtime_absolute') 
                    ? todo_media_url_to_runtime_absolute($path) 
                    : (function_exists('todo_media_url_to_absolute') ? todo_media_url_to_absolute($path) : $path);
                return $attr . '=' . $quote . $normalized . $quote;
            },
            $content
        );
    }
}

if (!function_exists('todo_normalize_content_backend_urls')) {
    /**
     * 后端 HTML 内容媒体 URL 规范化转换器（Rule 3）
     * 负责将数据库中的 /content 数据解析拼接成 /todocms/content... 供后端回填显示使用，支持子目录部署，不含 CDN 处理。
     *
     * @param string $content HTML内容
     * @return string 处理后的HTML内容
     */
    function todo_normalize_content_backend_urls($content)
    {
        if (empty($content) || !is_string($content)) {
            return $content;
        }

        $pattern = '/(src|href|poster)=(["\']|&quot;|&#039;|&#39;)((?:https?:\/\/[^"\'&\s>]*)?\/?(?:[^\/"\'&\s>]+\/)*content\/(?:uploads|assets|themes|plugins)\/[^"\'&\s>]*)\2/i';

        return preg_replace_callback(
            $pattern,
            function ($matches) {
                $attr = $matches[1];
                $quote = $matches[2];
                $path = $matches[3];
                $normalized = function_exists('todo_media_url_to_absolute') ? todo_media_url_to_absolute($path) : $path;
                return $attr . '=' . $quote . $normalized . $quote;
            },
            $content
        );
    }
}

if (!function_exists('todo_preload_media_storage_providers')) {
    /**
     * 批量预加载媒体存储服务商标识 (避免在内容解析时出现 N+1 SQL 查询)
     *
     * @param array $paths 媒体路径列表
     */
    function todo_preload_media_storage_providers($paths)
    {
        if (empty($paths) || !is_array($paths)) {
            return;
        }

        global $tododb, $todo_media_provider_cache;
        if (!isset($todo_media_provider_cache) || !is_array($todo_media_provider_cache)) {
            $todo_media_provider_cache = array();
        }

        $query_paths = array();
        foreach ($paths as $p) {
            if (!is_string($p)) continue;
            $clean = ltrim(trim($p), '/');
            $cloud = (strpos($clean, 'content/uploads') === 0) ? $clean : 'content/uploads/' . $clean;
            if (!array_key_exists($cloud, $todo_media_provider_cache)) {
                $query_paths[$cloud] = $cloud;
                $query_paths[$clean] = $clean;
            }
        }

        if (empty($query_paths) || !$tododb || !isset($tododb->prefix)) {
            return;
        }

        try {
            $params_list = array_values($query_paths);
            $sql = $tododb->prepare(
                "SELECT m.media_path, mm.meta_value AS storage_provider 
                 FROM {$tododb->prefix}media m 
                 LEFT JOIN {$tododb->prefix}media_meta mm ON m.media_id = mm.media_id AND mm.meta_key = 'storage_provider' 
                 WHERE m.media_path IN (" . implode(',', array_fill(0, count($query_paths), '%s')) . ")",
                ...$params_list
            );
            $rows = $tododb->get_results($sql, ARRAY_A);
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $mp = $row['media_path'];
                    $provider = !empty($row['storage_provider']) ? $row['storage_provider'] : 'local';
                    $todo_media_provider_cache[$mp] = $provider;
                    $clean_mp = ltrim($mp, '/');
                    $cloud_mp = (strpos($clean_mp, 'content/uploads') === 0) ? $clean_mp : 'content/uploads/' . $clean_mp;
                    $todo_media_provider_cache[$cloud_mp] = $provider;
                    $todo_media_provider_cache[$clean_mp] = $provider;
                }
            }
            foreach ($query_paths as $qp) {
                if (!array_key_exists($qp, $todo_media_provider_cache)) {
                    $todo_media_provider_cache[$qp] = null;
                }
            }
        } catch (Throwable $e) {
            // 忽略数据库查询异常
        }
    }
}

if (!function_exists('todo_convert_content_urls_to_runtime_absolute')) {
    /**
     * 1. 前端主题解析使用：将 HTML 内容中的媒体与资源相对路径转换为包含 scheme + host + 子目录前缀或 CDN 域名的完整 URL
     * 内部调用 todo_media_url_to_runtime_absolute()，完成 CDN 转换与前端全路径包装。
     * 
     * @param string $content HTML内容
     * @return string 处理后的HTML内容
     */
    function todo_convert_content_urls_to_runtime_absolute($content)
    {
        if (empty($content) || !is_string($content)) {
            return $content;
        }

        $pattern = '/(src|href|poster)=(["\']|&quot;|&#039;|&#39;)(\/(?:[^\'"&\s>]+\/)?content\/(?:uploads|assets|themes|plugins)\/[^\'"&\s>]+)\2/i';

        // 批量预加载所有图片的 storage_provider，单条 SQL 解决 N+1 查询
        if (preg_match_all($pattern, $content, $matches)) {
            if (!empty($matches[3]) && function_exists('todo_preload_media_storage_providers')) {
                todo_preload_media_storage_providers($matches[3]);
            }
        }

        return preg_replace_callback($pattern, function($matches) {
            $attr = $matches[1];
            $quote = $matches[2];
            $path = $matches[3];
            $runtime_url = todo_media_url_to_runtime_absolute($path);
            return $attr . '=' . $quote . $runtime_url . $quote;
        }, $content);
    }
}

if (!function_exists('todo_convert_content_urls_to_absolute')) {
    /**
     * 3. 后端 HTML 内容绝对地址转换器：将 HTML 内容中的媒体/资源相对路径（/content/...）解析拼接子目录前缀（/todocms/content/...）
     * 内部调用 todo_media_url_to_absolute()，供后端编辑器回填显示使用。
     * 
     * @param string $content HTML内容
     * @return string 处理后的HTML内容
     */
    function todo_convert_content_urls_to_absolute($content)
    {
        if (empty($content) || !is_string($content)) {
            return $content;
        }

        $pattern = '/(src|href|poster)=(["\']|&quot;|&#039;|&#39;)(\/(?:[^\'"&\s>]+\/)?content\/(?:uploads|assets|themes|plugins)\/[^\'"&\s>]+)\2/i';

        if (preg_match_all($pattern, $content, $matches)) {
            if (!empty($matches[3]) && function_exists('todo_preload_media_storage_providers')) {
                todo_preload_media_storage_providers($matches[3]);
            }
        }

        return preg_replace_callback($pattern, function($matches) {
            $attr = $matches[1];
            $quote = $matches[2];
            $path = $matches[3];
            $absolute_url = todo_media_url_to_absolute($path);
            return $attr . '=' . $quote . $absolute_url . $quote;
        }, $content);
    }
}

// 注册内容媒体路径规范化过滤器 (前端主题渲染使用 1+3)
if (function_exists('add_filter')) {
    add_filter('todo_the_content', 'todo_normalize_content_root_relative_urls', 20);
    add_filter('the_content', 'todo_normalize_content_root_relative_urls', 20);
    add_filter('todo_the_content', 'todo_convert_content_urls_to_runtime_absolute', 25);
    add_filter('the_content', 'todo_convert_content_urls_to_runtime_absolute', 25);
}

if (!function_exists('todo_media_url_to_absolute')) {
    /**
     * 3. 后端绝对地址解析器或子目录部署包装器。
     * 负责将数据库中的 /content 数据解析拼接成 /todocms/content... 供后端显示使用，支持子目录部署与云存储。
     * 后端不需要 CDN 域名，也不拼接全路径 host 域名，保持子目录相对路径。
     *
     * @param string $url_or_path 数据库相对路径（如 /content/uploads/2026/08/24/1.jpg）
     * @return string 解析后的子目录相对路径（如 /todocms/content/uploads/2026/08/24/1.jpg）或云存储 URL
     */
    function todo_media_url_to_absolute($url_or_path)
    {
        if (!is_string($url_or_path)) {
            return is_scalar($url_or_path) ? (string) $url_or_path : '';
        }
        $url_or_path = trim($url_or_path);
        if ($url_or_path === '' || strpos($url_or_path, 'data:') === 0) {
            return $url_or_path;
        }

        if (strpos($url_or_path, '{') === 0 || strpos($url_or_path, '[') === 0) {
            $decoded = json_decode($url_or_path, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                array_walk_recursive($decoded, function (&$v) {
                    if (is_string($v) && $v !== '' && (strpos($v, '/content/') !== false || strpos($v, '/todocms/content/') !== false || strpos($v, 'http') === 0)) {
                        $v = todo_media_url_to_absolute($v);
                    }
                });
                return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            return $url_or_path;
        }

        // 如果是外部完整 http(s)://，先判断是否为第三方云存储；非内部 URL 则直接返回
        if (preg_match('#^https?://#i', $url_or_path)) {
            if (function_exists('todo_is_internal_url') && !todo_is_internal_url($url_or_path)) {
                return $url_or_path;
            }
        }

        // 先转换为标准基础相对路径（/content/uploads/...）
        $rel_path = todo_media_url_to_relative($url_or_path);
        $clean_path = ltrim($rel_path, '/');

        // 第三方云存储判断与解析（如七牛云、阿里云 OSS 等）
        if (class_exists('Todo_Storage_Manager') && (strpos($clean_path, 'content/uploads/') === 0 || strpos($clean_path, 'uploads/') === 0)) {
            $storage_mgr = Todo_Storage_Manager::get_instance();
            $cloud_path = (strpos($clean_path, 'content/uploads/') === 0) ? $clean_path : 'content/uploads/' . ltrim($clean_path, '/');
            $short_path = '/' . ltrim(preg_replace('#^content/uploads/#i', '', $cloud_path), '/');

            // 优先从全局预加载/静态缓存中读取 storage_provider
            global $todo_media_provider_cache;
            if (!isset($todo_media_provider_cache) || !is_array($todo_media_provider_cache)) {
                $todo_media_provider_cache = array();
            }

            if (array_key_exists($cloud_path, $todo_media_provider_cache)) {
                $provider = $todo_media_provider_cache[$cloud_path];
            } else {
                $provider = null;
                // 如果当前存储管理器配置为本地存储(local)，无需对数据库发起的 N+1 查询
                if (method_exists($storage_mgr, 'get_provider_type') && $storage_mgr->get_provider_type() === 'local') {
                    $provider = 'local';
                } else {
                    global $tododb;
                    if ($tododb && isset($tododb->prefix)) {
                        try {
                            $sql = $tododb->prepare(
                                "SELECT mm.meta_value AS storage_provider 
                                 FROM {$tododb->prefix}media m 
                                 LEFT JOIN {$tododb->prefix}media_meta mm ON m.media_id = mm.media_id AND mm.meta_key = 'storage_provider' 
                                 WHERE m.media_path = %s OR m.media_path = %s OR m.media_url = %s OR m.media_url = %s LIMIT 1",
                                $cloud_path,
                                $short_path,
                                $cloud_path,
                                '/' . $cloud_path
                            );
                            $provider = $tododb->get_var($sql);
                        } catch (Throwable $e) {
                            $provider = null;
                        }
                    }
                }
                $todo_media_provider_cache[$cloud_path] = $provider;
            }

            if (!$storage_mgr->is_local()) {
                $use_cloud = true;
                if ($provider === 'local') {
                    $use_cloud = false;
                } elseif (!empty($provider) && $provider !== 'local') {
                    $use_cloud = true;
                } else {
                    $root_dir = defined('TODO_ROOT') ? TODO_ROOT : (defined('ABSPATH') ? ABSPATH : '');
                    $local_file = ($root_dir !== '') ? (rtrim($root_dir, '/') . '/' . $cloud_path) : '';
                    if ($local_file !== '' && file_exists($local_file)) {
                        $use_cloud = false;
                    }
                }

                if ($use_cloud) {
                    return $storage_mgr->get_url($cloud_path);
                }
            } else {
                if (!empty($provider) && $provider !== 'local') {
                    $storage = Todo_Storage_Manager::get_provider_by_type($provider);
                    if ($storage) {
                        return $storage->get_url($cloud_path);
                    }
                }
            }
        }

        // 本地存储：拼接安装子目录前缀（例如将 /content/uploads/... 拼接包装为 /todocms/content/uploads/...）
        $site_url = function_exists('todo_get_site_url') ? todo_get_site_url('/') : '';
        $site_path = rtrim((string) parse_url($site_url, PHP_URL_PATH), '/');

        if ($site_path !== '' && $site_path !== '/') {
            if (strpos($rel_path, $site_path . '/') !== 0) {
                $rel_path = $site_path . (strpos($rel_path, '/') === 0 ? $rel_path : ('/' . $rel_path));
            }
        }

        return $rel_path;
    }
}

if (!function_exists('todo_media_url_to_runtime_absolute')) {
    /**
     * 1. 前端主题解析使用：CDN转换器、前端绝对路径包装过滤器。
     * 调用 todo_media_url_to_absolute() 完成子目录与云存储解析后，进一步在前端拼接基域名或 CDN 域名。
     * 支持 CDN + 子目录部署包装（如 http://domain.com/todocms/content... 或 https://cdn.domain.com/todocms/content...）。
     *
     * @param string $url_or_path
     * @return string 前端完整 URL（包含 scheme + host + 子目录前缀或 CDN 域名）
     */
    function todo_media_url_to_runtime_absolute($url_or_path)
    {
        if (!is_string($url_or_path)) {
            $url_or_path = is_scalar($url_or_path) ? (string) $url_or_path : '';
        }
        $url_or_path = trim($url_or_path);
        if ($url_or_path === '' || strpos($url_or_path, 'data:') === 0 || strpos($url_or_path, '<') === 0) {
            return $url_or_path;
        }

        if (strpos($url_or_path, '{') === 0 || strpos($url_or_path, '[') === 0) {
            $decoded = json_decode($url_or_path, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                array_walk_recursive($decoded, function (&$v) {
                    if (is_string($v) && $v !== '' && (strpos($v, '/content/') !== false || strpos($v, '/todocms/content/') !== false || strpos($v, 'http') === 0)) {
                        $v = todo_media_url_to_runtime_absolute($v);
                    }
                });
                return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            return $url_or_path;
        }

        // 1. 内部先调用 todo_media_url_to_absolute() 完成子目录部署与云存储地址解析包装
        $abs_path_or_url = todo_media_url_to_absolute($url_or_path);

        // 如果已经是第三方云存储完整 URL (https?://)，进行 CDN 前端域名转换（若配置）或直接返回
        if (preg_match('#^https?://#i', $abs_path_or_url)) {
            if (function_exists('get_option') && get_option('cdn_enabled', '0') === '1') {
                $cdn_domain = rtrim((string) get_option('cdn_domain', ''), '/');
                if ($cdn_domain !== '') {
                    $u_parsed = @parse_url($abs_path_or_url);
                    if (is_array($u_parsed) && !empty($u_parsed['path'])) {
                        $full_path = $u_parsed['path'] . (isset($u_parsed['query']) ? ('?' . $u_parsed['query']) : '') . (isset($u_parsed['fragment']) ? ('#' . $u_parsed['fragment']) : '');
                        return $cdn_domain . (strpos($full_path, '/') === 0 ? $full_path : ('/' . $full_path));
                    }
                }
            }
            return $abs_path_or_url;
        }

        // 2. 本地子目录路径（如 /todocms/content/...），拼接 HTTP/HTTPS 完整站点基域名
        $site_url = function_exists('todo_get_site_url') ? todo_get_site_url('/') : '';
        if ($site_url === '') {
            return $abs_path_or_url;
        }

        $parsed_site = @parse_url($site_url);
        $scheme = (is_array($parsed_site) && !empty($parsed_site['scheme'])) ? $parsed_site['scheme'] : 'http';
        $host = (is_array($parsed_site) && !empty($parsed_site['host'])) ? $parsed_site['host'] : '';
        $port = (is_array($parsed_site) && !empty($parsed_site['port'])) ? (':' . $parsed_site['port']) : '';
        $origin = $scheme . '://' . $host . $port;

        $path = (strpos($abs_path_or_url, '/') === 0) ? $abs_path_or_url : ('/' . $abs_path_or_url);
        $result_url = $origin . $path;

        // 3. 前端主题 CDN 支持：若启用 CDN，替换基域名为 CDN 域名
        if (function_exists('get_option') && get_option('cdn_enabled', '0') === '1') {
            $cdn_domain = rtrim((string) get_option('cdn_domain', ''), '/');
            if ($cdn_domain !== '') {
                $scope_uploads = (get_option('cdn_scope_uploads', '1') === '1');
                $scope_themes = (get_option('cdn_scope_themes', '1') === '1');

                $path_check = ltrim($path, '/');
                $matched = false;
                if ($scope_uploads && (strpos($path_check, 'content/uploads/') !== false || strpos($path_check, 'uploads/') !== false)) {
                    $matched = true;
                }
                if ($scope_themes && (
                    strpos($path_check, 'content/themes/') !== false || 
                    strpos($path_check, 'content/assets/') !== false || 
                    strpos($path_check, 'content/plugins/') !== false || 
                    strpos($path_check, 'public/') !== false || 
                    preg_match('#(?:^|/)public(?:/|$)#i', $path_check)
                )) {
                    $matched = true;
                }

                if ($matched) {
                    $result_url = $cdn_domain . (strpos($path, '/') === 0 ? $path : ('/' . $path));
                }
            }
        }

        return $result_url;
    }
}
