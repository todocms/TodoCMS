<?php
/**
 * TodoCMS 循环函数
 *
 * 提供 TodoCMS 的循环函数
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 全局变量，用于存储当前查询的文章
global $todo_query, $post;

/**
 * 初始化查询
 *
 * @param array $args 查询参数
 * @return array 查询结果
 */
function query_posts($args = array())
{
    // 深度优化：检查结果池
    if (class_exists('Todo_DB_Result_Pool')) {
        $current_lang = function_exists('current_locale') ? current_locale() : (function_exists('todo_get_current_language') ? todo_get_current_language() : 'zh-CN');
        $pool_key = 'query_posts_' . md5(serialize($args) . $current_lang);
        $cached = Todo_DB_Result_Pool::get($pool_key);
        if ($cached !== null) {
            global $todo_query;
            $GLOBALS['todo_query'] = $cached;
            $GLOBALS['todo_post'] = null;
            return $cached;
        }
    }
    global $todo_query, $post;

    // 如果未指定参数，自动检测当前页面上下文并填充查询参数
    if (empty($args)) {
        $args = array();
    }

    // 自动检测分页
    if (!isset($args['paged'])) {
        $args['paged'] = function_exists('get_query_var') ? intval(get_query_var('paged')) : 1;
        if ($args['paged'] <= 0) {
            $args['paged'] = 1;
        }
    }

    // 自动检测标签页面上下文
    if (function_exists('is_tag') && is_tag()) {
        if (!isset($args['tag']) && !isset($args['tag_id'])) {
            $current_tag = function_exists('get_current_tag') ? get_current_tag() : null;
            if ($current_tag) {
                if (isset($current_tag->tag_id)) {
                    $args['tag_id'] = intval($current_tag->tag_id);
                } elseif (isset($current_tag->id)) {
                    $args['tag_id'] = intval($current_tag->id);
                }
            }
        }
    }

    // 自动检测分类页面上下文
    if (function_exists('is_category') && is_category()) {
        if (!isset($args['cat']) || $args['cat'] < 0) {
            global $current_category;
            if (isset($current_category)) {
                if (is_object($current_category)) {
                    $args['cat'] = isset($current_category->term_id) ? intval($current_category->term_id) : (isset($current_category->id) ? intval($current_category->id) : 0);
                } elseif (is_array($current_category)) {
                    $args['cat'] = isset($current_category['term_id']) ? intval($current_category['term_id']) : (isset($current_category['id']) ? intval($current_category['id']) : 0);
                }
            }
        }
    }

    // 自动检测搜索页面上下文
    if (function_exists('is_search') && is_search()) {
        if (!isset($args['s'])) {
            $search_term = function_exists('todo_get_search_context') ? todo_get_search_context() : '';
            if ($search_term !== '') {
                $args['s'] = sanitize_text_field($search_term);
            }
        }
    }

    // 设置默认的每页文章数
    if (!isset($args['posts_per_page'])) {
        $args['posts_per_page'] = 10;
    }

    // 限制每页文章数，防止内存溢出
    if ($args['posts_per_page'] > 50) {
        $args['posts_per_page'] = 50;
    }

    // 如果只需要计数，则只获取ID字段
    if (isset($args['count_only']) && $args['count_only']) {
        $args['fields'] = 'ID';
    }

    // 获取文章列表
    $posts = get_posts($args);

    // 批量预加载优化：在循环开始前预加载所有需要的数据，避免N+1查询
    if (!empty($posts) && is_array($posts)) {
        // 提取文章ID
        $post_ids = [];
        foreach ($posts as $post_item) {
            if (is_object($post_item) && isset($post_item->ID)) {
                $post_ids[] = intval($post_item->ID);
            } elseif (is_array($post_item) && isset($post_item['ID'])) {
                $post_ids[] = intval($post_item['ID']);
            }
        }

        if (!empty($post_ids)) {
            // 统一使用 ORM 批量预加载（性能优化：减少查询次数）
            Todo_Post_ORM::eager_load_all($post_ids);

            // 批量预加载标签缓存
            if (class_exists('CMS_Tags_Manager') && method_exists('CMS_Tags_Manager', 'update_post_tags_cache')) {
                CMS_Tags_Manager::update_post_tags_cache($post_ids);
            }

            // 批量预加载多语言数据（避免 todo_get_post_translation 的 N+1 查询）
            $language_code = get_current_language();
            if ($language_code) {
                todo_get_posts_multilang_batch($post_ids, $language_code, [
                    'include_seo' => true,
                    'prefer_custom_url' => true,
                    'fallback' => true
                ]);
            }
        }
    }

    // 初始化查询数组
    $todo_query = array();

    // 将文章添加到查询数组
    if (is_array($posts)) {
        foreach ($posts as $index => $post_item) {
            $todo_query[$index] = $post_item;
        }
    }

    // 设置查询元数据
    $todo_query['current_post'] = -1;
    $todo_query['post_count'] = is_array($posts) ? count($posts) : 0;
    $todo_query['posts_per_page'] = $args['posts_per_page'];

    // 重置当前文章
    $post = null;

    // 深度优化：保存结果到结果池
    if (class_exists('Todo_DB_Result_Pool')) {
        $pool_key = 'query_posts_' . md5(serialize($args));
        Todo_DB_Result_Pool::set($pool_key, $todo_query);
    }

    return $todo_query;
}

/**
 * 检查是否有更多文章
 *
 * @return bool 是否有更多文章
 */
function have_posts()
{
    global $todo_query;

    // 检查 $todo_query 是否为数组
    if (!isset($todo_query) || !is_array($todo_query)) {
        // 如果查询未初始化，初始化查询
        $todo_query = query_posts();
    }

    // 确保 current_post 已设置
    if (!isset($todo_query['current_post'])) {
        $todo_query['current_post'] = -1;
    }

    // 确保 post_count 已设置
    if (!isset($todo_query['post_count'])) {
        $todo_query['post_count'] = is_array($todo_query) ? count($todo_query) - 1 : 0; // 减去非文章键
    }

    // 检查是否还有更多文章
    return ($todo_query['current_post'] + 1 < $todo_query['post_count'] && $todo_query['post_count'] > 0);
}

/**
 * 设置下一篇文章为当前文章
 *
 * @return void
 */
function the_post()
{
    global $todo_query, $post;

    // 检查 $todo_query 是否为数组且包含必要的键
    if (!is_array($todo_query) || !isset($todo_query['current_post']) || !isset($todo_query['post_count'])) {
        // 初始化查询
        query_posts();
    }

    // 增加当前文章索引
    $todo_query['current_post']++;

    // 检查是否超出范围
    if ($todo_query['current_post'] >= $todo_query['post_count']) {
        $post = null;
        $GLOBALS['todo_post'] = null;
        return;
    }

    // 设置当前文章
    if (isset($todo_query[$todo_query['current_post']])) {
        $post = $todo_query[$todo_query['current_post']];
        $GLOBALS['todo_post'] = $post;

        // 设置文章 ID
        setup_postdata($post);

        return;
    }

    $post = null;
    $GLOBALS['todo_post'] = null;
}

/**
 * 获取文章 ID
 *
 * @return int 文章 ID
 */
function get_the_ID()
{
    global $post;
    return $post->ID;
}

/**
 * 输出文章 ID
 */
function the_ID()
{
    echo get_the_ID();
}

/**
 * 获取文章标题
 *
 * @param int|object $post 文章 ID 或文章对象
 * @return string 文章标题
 */
if (!function_exists('get_the_title')) {
    function get_the_title($post = 0)
    {
        if (!$post) {
            global $post;
        } elseif (is_numeric($post)) {
            $post = get_post($post);
        }

        return $post->post_title;
    }
}

/**
 * 输出文章标题
 *
 * @param string $before 标题前的内容
 * @param string $after 标题后的内容
 * @param bool $echo 是否输出，默认为 true
 * @return string 如果 $echo 为 false，返回标题
 */
function the_title($before = '', $after = '', $echo = true)
{
    $title = get_the_title();

    if (strlen($title) == 0) {
        return $echo ? '' : '';
    }

    $title = $before . $title . $after;

    if ($echo) {
        echo $title;
    }

    return $title;
}

/**
 * 获取文章内容
 *
 * @param string $more_link_text 更多链接文本
 * @param bool $strip_teaser 是否去除摘要
 * @return string 文章内容
 */
if (!function_exists('get_the_content')) {
    function get_the_content($more_link_text = null, $strip_teaser = false)
    {
        global $post, $page, $more, $pages;

        if (null === $more_link_text) {
            $more_link_text = '(更多...)';
        }

        // 可见性优先（私密/密码保护），其后判定阅读权限（等级/管理员/公开）
        if (function_exists('todo_can_read_post')) {
            $access = todo_can_read_post($post);
            if (!$access['allowed']) {
                if (!empty($access['requires_password'])) {
                    return function_exists('todo_get_password_form_html') ? todo_get_password_form_html() : '<p>此内容受密码保护，请输入密码查看。</p>';
                }
                return function_exists('todo_get_permission_block_html') ? todo_get_permission_block_html($access['reason']) : '<p>您暂无权限查看该内容。</p>';
            }
        }

        $content = '';

        // 确保$page是有效的数字
        if (!is_numeric($page) || $page < 1) {
            $page = 1;
        }

        // 确保$pages是数组
        if (!is_array($pages) || empty($pages)) {
            $pages = array($post->post_content);
        }

        // 如果有分页，获取当前页的内容
        if ($page > count($pages)) {
            $page = count($pages);
        }

        $content = $pages[$page - 1];

        // 处理更多链接
        if ($more) {
            $content = str_replace('<!--more-->', '', $content);
        } else {
            if (preg_match('/<!--more(.*?)?-->/', $content, $matches)) {
                $content = explode($matches[0], $content, 2);
                $content = $content[0];
                $content .= '<p><a href="' . get_permalink() . '#more-' . $post->ID . '" class="more-link">' . $more_link_text . '</a></p>';
            }
        }

        return $content;
    }
}

/**
 * 输出文章内容
 *
 * @param string $more_link_text 更多链接文本
 * @param bool $strip_teaser 是否去除摘要
 */
function the_content($more_link_text = null, $strip_teaser = false)
{
    $content = get_the_content($more_link_text, $strip_teaser);

    // 限制内容大小，防止内存溢出
    if (strlen($content) > 1000000) { // 限制为约 1MB
        $content = substr($content, 0, 1000000) . '... [内容过长，已截断]';
    }

    echo $content;
}

/**
 * 获取文章摘要
 *
 * @param int|object $post 文章 ID 或文章对象
 * @return string 文章摘要
 */
function get_the_excerpt($post = null)
{
    return todo_get_the_excerpt(150, $post);
}

/**
 * 获取文章类型
 *
 * @param int|object $post 文章 ID 或文章对象
 * @return string 文章类型
 */
function get_post_type($post = null)
{
    if (!$post) {
        global $post;
    } elseif (is_numeric($post)) {
        $post = get_post($post);
    }

    return $post->post_type;
}

/**
 * 获取文章永久链接
 *
 * @param int|object $post 文章 ID 或文章对象
 * @return string 文章永久链接
 */
if (!function_exists('get_permalink')) {
    function get_permalink($post = null)
    {
        // 统一获取文章对象
        $obj = null;
        if (is_object($post) && isset($post->ID)) {
            $obj = $post;
        } elseif ($post !== null) {
            $obj = get_post($post);
        } else {
            $obj = isset($GLOBALS['todo_post']) ? $GLOBALS['todo_post'] : null;
        }
        if (!$obj) {
            return '';
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

        // 分类标签
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
            } elseif (function_exists('get_post_category')) {
                $category = get_post_category($obj->ID);
                if ($category && isset($category->slug) && $category->slug) {
                    $category_slug = $category->slug;
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
 * 输出文章永久链接
 */
function the_permalink()
{
    echo esc_url(get_permalink());
}

/**
 * 获取文章分类列表
 *
 * @param int $post_id 文章 ID
 * @return array 分类列表
 */
function get_the_category($post_id = false)
{
    static $cache = [];

    if (!$post_id) {
        global $post;
        if (!isset($post->ID)) {
            return array();
        }
        $post_id = $post->ID;
    }

    // Check static cache first
    if (isset($cache[$post_id])) {
        return $cache[$post_id];
    }

    // 优先从批量加载的缓存中读取（如果存在）
    $cached_categories = _todo_post_term_cache_manager('get', $post_id, 'category');
    if ($cached_categories !== false && is_array($cached_categories) && !empty($cached_categories)) {
        // 转换为与原始查询相同的格式
        $result = [];
        foreach ($cached_categories as $cat) {
            if (is_object($cat)) {
                $result[] = $cat;
            } elseif (is_array($cat)) {
                $result[] = (object) $cat;
            }
        }
        $cache[$post_id] = $result;
        return $result;
    }

    // 尝试从Todo_Cache_Manager读取持久化缓存
    if (class_exists('Todo_Cache_Manager')) {
        $ttl = function_exists('todo_get_fragment_cache_ttl') ? (int) todo_get_fragment_cache_ttl() : 120;
        $lang = function_exists('todo_get_current_language') ? todo_get_current_language() : (function_exists('get_current_language') ? get_current_language() : null);
        $cache_key = 'post_categories_' . (int) $post_id;
        $cached = Todo_Cache_Manager::get($cache_key, 'post_terms', $lang);
        if ($cached !== false && is_array($cached)) {
            $cache[$post_id] = $cached;
            return $cached;
        }
    }

    global $tododb;

    $categories = $tododb->get_results("
        SELECT t.term_id, t.name, t.slug, tt.description
        FROM {$tododb->prefix}term_relationships tr
        JOIN {$tododb->prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
        JOIN {$tododb->prefix}terms t ON tt.term_id = t.term_id
        WHERE tr.object_id = ? AND tt.taxonomy = 'category'
    ", [$post_id]);

    $result = $categories ? $categories : array();
    $cache[$post_id] = $result;

    // 保存到持久化缓存
    if (class_exists('Todo_Cache_Manager') && !empty($result)) {
        $ttl = function_exists('todo_get_fragment_cache_ttl') ? (int) todo_get_fragment_cache_ttl() : 120;
        $lang = function_exists('todo_get_current_language') ? todo_get_current_language() : (function_exists('get_current_language') ? get_current_language() : null);
        $cache_key = 'post_categories_' . (int) $post_id;
        Todo_Cache_Manager::set($cache_key, $result, $ttl, 'post_terms', $lang);
    }

    return $result;
}

/**
 * 获取文章主分类（返回第一个分类）
 *
 * @param int $post_id 文章 ID
 * @return object|null 分类对象或null
 */
function get_post_category($post_id)
{
    $cats = get_the_category($post_id);
    return !empty($cats) ? $cats[0] : null;
}

/**
 * 获取分类列表的 HTML
 *
 * @param string $separator 分类之间的分隔符
 * @param string $parents 父分类的显示方式
 * @param int $post_id 文章 ID
 * @return string 分类列表的 HTML
 */
function get_the_category_list($separator = '', $parents = '', $post_id = false)
{
    $categories = get_the_category($post_id);

    if (empty($categories)) {
        return '';
    }

    $output = '';

    foreach ($categories as $category) {
        $output .= '<a href="' . get_category_link($category->term_id) . '">' . $category->name . '</a>' . $separator;
    }

    return rtrim($output, $separator);
}

/**
 * 获取分类链接
 *
 * @param int|object $category 分类 ID 或对象
 * @return string 分类链接
 */
function get_category_link($category)
{
    if (is_numeric($category)) {
        global $tododb;
        $category = $tododb->get_row("SELECT term_id, slug FROM {$tododb->prefix}terms WHERE term_id = ?", [$category]);
    }
    
    $base = rtrim(todo_url(), '/');
    $slug = (is_object($category) && isset($category->slug)) ? $category->slug : $category;
    
    if (using_permalinks() && !is_numeric($slug)) {
        $url = $base . '/category/' . $slug;
    } else {
        $url = $base . '/?cat=' . (is_object($category) ? $category->term_id : $category);
    }
    
    $locale = current_locale();
    return get_language_url($locale, $url);
}

/**
 * 获取文章标签列表
 *
 * @param int $post_id 文章 ID
 * @return array 标签列表
 */
function get_the_tags($post_id = false)
{
    if (!$post_id) {
        $todo_post = isset($GLOBALS['todo_post']) && is_object($GLOBALS['todo_post']) ? $GLOBALS['todo_post'] : null;
        if ($todo_post && isset($todo_post->ID)) {
            $post_id = $todo_post->ID;
        }
    }

    if (!$post_id) return [];
    return todo_get_post_tags($post_id);
}

/**
 * 获取标签列表的 HTML
 *
 * @param string $before 标签列表前的内容
 * @param string $separator 标签之间的分隔符
 * @param string $after 标签列表后的内容
 * @return string 标签列表的 HTML
 */
function get_the_tag_list($before = '', $separator = '', $after = '')
{
    $tags = get_the_tags();

    if (empty($tags)) {
        return '';
    }

    $output = $before;

    foreach ($tags as $tag) {
        // 处理对象格式的标签数据
        $tag_id = is_object($tag) ? $tag->term_id : $tag['id'];
        $tag_name = is_object($tag) ? $tag->name : $tag['name'];
        $output .= '<a href="' . get_tag_link($tag_id) . '">' . esc_html($tag_name) . '</a>' . $separator;
    }

    return rtrim($output, $separator) . $after;
}

/**
 * 获取标签链接
 *
 * @param int|object $tag 标签 ID 或对象
 * @return string 标签链接
 */
function get_tag_link($tag)
{
    if (is_numeric($tag)) {
        global $tododb;
        $tag = $tododb->get_row("SELECT term_id, slug FROM {$tododb->prefix}terms WHERE term_id = ?", [$tag]);
    }

    $base = rtrim(todo_url(), '/');
    $slug = (is_object($tag) && isset($tag->slug)) ? $tag->slug : $tag;
    
    if (using_permalinks() && !is_numeric($slug)) {
        $url = $base . '/tag/' . $slug;
    } else {
        $url = $base . '/?tag=' . (is_object($tag) ? $tag->term_id : $tag);
    }
    
    $locale = current_locale();
    return get_language_url($locale, $url);
}

/**
 * 获取文章类名
 *
 * @param string|array $class 额外的类名
 * @param int|object $post_id 文章 ID 或文章对象
 * @return string 文章类名
 */
function get_post_class($class = '', $post_id = null)
{
    if (!$post_id) {
        global $post;
        $post_id = $post->ID;
    } elseif (is_object($post_id)) {
        $post = $post_id;
        $post_id = $post->ID;
    }

    $classes = array();

    // 添加文章类型
    $post_type = get_post_type($post_id);
    $classes[] = $post_type;
    $classes[] = 'type-' . $post_type;

    // 添加文章状态
    $post_status = $post->post_status;
    $classes[] = 'status-' . $post_status;

    // 添加额外的类名
    if (!empty($class)) {
        if (!is_array($class)) {
            $class = preg_split('#\s+#', $class);
        }
        $classes = array_merge($classes, $class);
    }

    return implode(' ', $classes);
}

/**
 * 输出文章类名
 *
 * @param string|array $class 额外的类名
 * @param int|object $post_id 文章 ID 或文章对象
 */
function post_class($class = '', $post_id = null)
{
    echo 'class="' . get_post_class($class, $post_id) . '"';
}

/**
 * 分页函数
 */
function the_pagination()
{
    global $todo_query;

    // 如果只有一页，不显示分页
    if ($todo_query['post_count'] <= 10) {
        return;
    }

    // 获取当前页码
    $current_page = function_exists('todo_get_paged_context') ? todo_get_paged_context('paged', 1) : 1;
    $base_url = function_exists('remove_query_arg') ? remove_query_arg('paged') : '?';
    if ($base_url === '') {
        $base_url = '?';
    }

    // 计算总页数
    $total_pages = ceil($todo_query['post_count'] / 10);

    // 输出分页 HTML
    echo '<div class="pagination">';

    // 上一页链接
    if ($current_page > 1) {
        echo '<a href="' . esc_url(add_query_arg('paged', ($current_page - 1), $base_url)) . '" class="prev">上一页</a>';
    }

    // 页码链接
    for ($i = 1; $i <= $total_pages; $i++) {
        if ($i == $current_page) {
            echo '<span class="current">' . $i . '</span>';
        } else {
            echo '<a href="' . esc_url(add_query_arg('paged', $i, $base_url)) . '">' . $i . '</a>';
        }
    }

    // 下一页链接
    if ($current_page < $total_pages) {
        echo '<a href="' . esc_url(add_query_arg('paged', ($current_page + 1), $base_url)) . '" class="next">下一页</a>';
    }

    echo '</div>';
}

/**
 * TodoCMS 的分页导航
 *
 * @param array $args 分页参数
 */
function the_posts_pagination($args = array())
{
    global $todo_query;

    // 默认参数
    $defaults = array(
        'mid_size' => 2,
        'prev_text' => '❮ 上一页',
        'next_text' => '下一页 ❯',
        'screen_reader_text' => '文章导航',
        'aria_label' => '文章',
        'posts_per_page' => 10
    );

    // 合并参数
    $args = array_merge($defaults, $args);

    // 如果只有一页，不显示分页
    if (!isset($todo_query['post_count']) || $todo_query['post_count'] <= $args['posts_per_page']) {
        return;
    }

    // 获取当前页码
    $current_page = function_exists('todo_get_paged_context') ? todo_get_paged_context('paged', 1) : 1;
    $base_url = function_exists('remove_query_arg') ? remove_query_arg('paged') : '?';
    if ($base_url === '') {
        $base_url = '?';
    }

    // 计算总页数，限制最大页数为100，防止内存溢出
    $total_pages = min(100, ceil($todo_query['post_count'] / $args['posts_per_page']));

    // 输出分页 HTML
    echo '<nav class="navigation pagination" role="navigation">';
    echo '<h2 class="screen-reader-text">' . $args['screen_reader_text'] . '</h2>';
    echo '<div class="nav-links" aria-label="' . $args['aria_label'] . '">';

    // 上一页链接
    if ($current_page > 1) {
        echo '<a class="prev page-numbers" href="' . esc_url(add_query_arg('paged', ($current_page - 1), $base_url)) . '">' . $args['prev_text'] . '</a>';
    }

    // 计算显示的页码范围
    $start_page = max(1, $current_page - $args['mid_size']);
    $end_page = min($total_pages, $current_page + $args['mid_size']);

    // 如果起始页不是第一页，显示第一页和省略号
    if ($start_page > 1) {
        echo '<a class="page-numbers" href="' . esc_url(add_query_arg('paged', 1, $base_url)) . '">1</a>';
        if ($start_page > 2) {
            echo '<span class="page-numbers dots">&hellip;</span>';
        }
    }

    // 页码链接
    for ($i = $start_page; $i <= $end_page; $i++) {
        if ($i == $current_page) {
            echo '<span aria-current="page" class="page-numbers current">' . $i . '</span>';
        } else {
            echo '<a class="page-numbers" href="' . esc_url(add_query_arg('paged', $i, $base_url)) . '">' . $i . '</a>';
        }
    }

    // 如果结束页不是最后一页，显示省略号和最后一页
    if ($end_page < $total_pages) {
        if ($end_page < $total_pages - 1) {
            echo '<span class="page-numbers dots">&hellip;</span>';
        }
        echo '<a class="page-numbers" href="' . esc_url(add_query_arg('paged', $total_pages, $base_url)) . '">' . $total_pages . '</a>';
    }

    // 下一页链接
    if ($current_page < $total_pages) {
        echo '<a class="next page-numbers" href="' . esc_url(add_query_arg('paged', ($current_page + 1), $base_url)) . '">' . $args['next_text'] . '</a>';
    }

    echo '</div></nav>';
}

/**
 * 生成分页链接（循环页面专用）
 *
 * @param array $args 分页参数
 * @return string 分页链接HTML
 */
function loop_paginate_links($args = array())
{
    // 默认参数
    $defaults = array(
        'base' => '%_%', // 基础URL格式
        'format' => '?paged=%#%', // 页码格式
        'total' => 1, // 总页数
        'current' => 0, // 当前页
        'show_all' => false, // 是否显示所有页码
        'prev_next' => true, // 是否显示上一页和下一页链接
        'prev_text' => '❮ 上一页', // 上一页文本
        'next_text' => '下一页 ❯', // 下一页文本
        'end_size' => 1, // 开始和结束处显示的页码数
        'mid_size' => 2, // 当前页前后显示的页码数
        'type' => 'plain', // 分页类型
        'add_args' => array(), // 添加到URL的参数
        'add_fragment' => '', // 添加到URL的片段
        'before_page_number' => '', // 页码前的内容
        'after_page_number' => '' // 页码后的内容
    );

    $args = array_merge($defaults, $args);

    // 如果总页数小于2，不显示分页
    if ($args['total'] < 2) {
        return '';
    }

    // 确保当前页在有效范围内
    $args['current'] = max(1, min($args['current'], $args['total']));

    // 准备输出
    $output = '';

    // 上一页链接
    if ($args['prev_next'] && $args['current'] > 1) {
        $link = str_replace('%_%', str_replace('%#%', $args['current'] - 1, $args['format']), $args['base']);
        $output .= '<a class="prev page-numbers" href="' . $link . '">' . $args['prev_text'] . '</a>';
    }

    // 页码链接
    $dots = false;

    for ($n = 1; $n <= $args['total']; $n++) {
        if ($n == $args['current']) {
            // 当前页
            $output .= '<span aria-current="page" class="page-numbers current">' . $args['before_page_number'] . $n . $args['after_page_number'] . '</span>';
            $dots = true;
        } else {
            // 是否显示省略号
            if ($args['show_all'] || ($n <= $args['end_size'] || ($args['current'] && $n >= $args['current'] - $args['mid_size'] && $n <= $args['current'] + $args['mid_size']) || $n > $args['total'] - $args['end_size'])) {
                // 普通页码链接
                $link = str_replace('%_%', str_replace('%#%', $n, $args['format']), $args['base']);
                $output .= '<a class="page-numbers" href="' . $link . '">' . $args['before_page_number'] . $n . $args['after_page_number'] . '</a>';
                $dots = true;
            } elseif ($dots) {
                // 省略号
                $output .= '<span class="page-numbers dots">&hellip;</span>';
                $dots = false;
            }
        }
    }

    // 下一页链接
    if ($args['prev_next'] && $args['current'] < $args['total']) {
        $link = str_replace('%_%', str_replace('%#%', $args['current'] + 1, $args['format']), $args['base']);
        $output .= '<a class="next page-numbers" href="' . $link . '">' . $args['next_text'] . '</a>';
    }

    return $output;
}

/**
 * 检查文章是否有特色图片
 *
 * @param int|object $post 文章 ID 或文章对象
 * @return bool 是否有特色图片
 */
if (!function_exists('has_post_thumbnail')) {
    function has_post_thumbnail($post = null)
    {
        if (!$post) {
            global $post;
        } elseif (is_numeric($post)) {
            $post = get_post($post);
        }

        // 更严格的判断：解析featured_image并确认可用URL
        $url = get_the_post_thumbnail_url($post);
        return !empty($url);
    }
}

/**
 * 输出文章特色图片
 *
 * @param string $size 图片尺寸
 * @param array $attr 图片属性
 */
function the_post_thumbnail($size = 'post-thumbnail', $attr = array())
{
    echo get_the_post_thumbnail(null, $size, $attr);
}

/**
 * 获取文章特色图片
 *
 * @param int|object $post 文章 ID 或文章对象
 * @param string $size 图片尺寸
 * @param array $attr 图片属性
 * @return string 图片 HTML
 */
function get_the_post_thumbnail($post = null, $size = 'post-thumbnail', $attr = array())
{
    if (!$post) {
        global $post;
    } elseif (is_numeric($post)) {
        $post = get_post($post);
    }

    $src = get_the_post_thumbnail_url($post, $size);
    if (empty($src)) {
        return '';
    }
    // alt 优先使用当前语言的标题，其次回退到主稿标题
    $alt = $post->post_title;
    $ml = todo_get_post_multilang($post->ID, null, ['fallback' => true]);
    if (is_array($ml) && !empty($ml['title'])) {
        $alt = $ml['title'];
    }

    $attr_string = '';
    if (!empty($attr)) {
        foreach ($attr as $key => $value) {
            $attr_string .= ' ' . $key . '="' . esc_attr($value) . '"';
        }
    }

    return '<img src="' . esc_url($src) . '" alt="' . esc_attr($alt) . '"' . $attr_string . ' />';
}

/**
 * 获取文章特色图片URL
 *
 * @param int|object $post 文章 ID 或文章对象
 * @param string $size 图片尺寸
 * @return string|false 图片URL或false
 */
function get_the_post_thumbnail_url($post = null, $size = 'post-thumbnail')
{
    if (!$post) {
        global $post;
    } elseif (is_numeric($post)) {
        $post = get_post($post);
    }

    if (!$post) {
        return false;
    }

    $raw = isset($post->featured_image) ? $post->featured_image : '';
    if (empty($raw)) {
        return false;
    }

    $result = '';

    // 优先根据当前语言环境尝试匹配带有对应 seo_keywords 的图片
    if (function_exists('todo_match_featured_image_by_lang')) {
        $matched_lang_url = todo_match_featured_image_by_lang($raw);
        if (!empty($matched_lang_url)) {
            $result = $matched_lang_url;
        }
    }

    if ($result === '') {
        // 标准化 featured_image：支持直接URL、序列化数据、数组格式

    if (is_string($raw)) {
        $trim = trim($raw);
        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            $decoded = json_decode($trim, true);
            if (is_array($decoded)) {
                if (isset($decoded['url']) && $decoded['url'] !== '') {
                    $result = $decoded['url'];
                } elseif (isset($decoded['images']) && is_array($decoded['images']) && isset($decoded['images'][0]['url']) && $decoded['images'][0]['url'] !== '') {
                    $result = $decoded['images'][0]['url'];
                } elseif (isset($decoded[0])) {
                    $first = $decoded[0];
                    if (is_array($first) && isset($first['url']) && $first['url'] !== '') {
                        $result = $first['url'];
                    } elseif (is_string($first) && $first !== '') {
                        $result = $first;
                    }
                }
            }
        } else {
            // 尝试反序列化（如果不是序列化，maybe_unserialize会原样返回）
            $val = maybe_unserialize($trim);
            if (is_array($val) && !empty($val)) {
                $first = reset($val);
                if (is_array($first) && isset($first['url'])) {
                    $result = $first['url'];
                } elseif (is_string($first)) {
                    $result = $first;
                }
            } elseif (is_string($val) && $val !== $trim) {
                // 反序列化得到字符串URL
                $result = $val;
            } elseif (strpos($trim, 'http') === 0 || strpos($trim, '/') === 0) {
                // 非序列化字符串，判断是否为URL
                $result = $trim;
            }
        }
    } elseif (is_array($raw)) {
        if (isset($raw['url'])) {
            $result = $raw['url'];
        } else {
            $first = reset($raw);
            if (is_array($first) && isset($first['url'])) {
                $result = $first['url'];
            } elseif (is_string($first)) {
                $result = $first;
            }
        }
    }
    }

    if ($result === '') {
        return false;
    }

    // 确保 URL 转换符合 Rule 1 前端运行期绝对地址标准（支持 CDN + 子目录部署）
    if (function_exists('todo_media_url_to_runtime_absolute')) {
        $result = todo_media_url_to_runtime_absolute($result);
    } elseif (function_exists('todo_media_url_to_absolute')) {
        $result = todo_media_url_to_absolute($result);
    }

    return $result;
}

/**
 * 获取文章格式
 *
 * @param int|object $post 文章 ID 或文章对象
 * @return string|false 文章格式或 false
 */
function get_post_format($post = null)
{
    if (!$post) {
        global $post;
    } elseif (is_numeric($post)) {
        $post = get_post($post);
    }

    $format = get_post_meta($post->ID, '_post_format', true);
    return !empty($format) ? $format : 'standard';
}

/**
 * 检查是否是单篇文章页面
 *
 * @param string|array $post_types 文章类型
 * @return bool 是否是单篇文章页面
 */
function is_singular($post_types = '')
{
    global $current_page;

    if (!isset($current_page) || $current_page !== 'single') {
        return false;
    }

    if (empty($post_types)) {
        return true;
    }

    global $post;
    if (!$post) {
        return false;
    }

    if (is_array($post_types)) {
        return in_array($post->post_type, $post_types);
    }

    return $post->post_type === $post_types;
}
