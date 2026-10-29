<?php
/**
 * 模板辅助函数
 *
 * 为HTML模板标签提供支持函数
 *
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 获取导航菜单项
 *
 * @param string $menu_name 菜单名称
 * @return array 菜单项数组
 */
function todo_get_nav_menu_items($menu_name = 'primary')
{
    global $tododb;

    try {
        // 首先根据 location 查找菜单 ID
        $menu_sql = "SELECT id FROM {$tododb->prefix}menus WHERE location = ? AND status = 1";
        $menu_result = $tododb->query($menu_sql, [$menu_name]);

        if (!$menu_result || empty($menu_result)) {
            return [];
        }

        $menu = $menu_result[0]; // query 方法返回数组，取第一个元素
        if (!$menu || !isset($menu['id'])) {
            return [];
        }

        // 查询菜单项
        $sql = "SELECT * FROM {$tododb->prefix}menu_items WHERE menu_id = ? AND status = 1 ORDER BY sort_order ASC";
        $items = $tododb->query($sql, [$menu['id']]);

        // 将关联数组转换为对象数组以保持兼容性
        $result = [];
        if ($items && is_array($items)) {
            foreach ($items as $item) {
                $result[] = (object) $item;
            }
        }

        return $result;
    } catch (Exception $e) {
        error_log('Error getting nav menu items: ' . $e->getMessage());
        return [];
    }
}

/**
 * 获取侧边栏小工具
 *
 * @param string $sidebar_id 侧边栏ID
 * @return array 小工具数组
 */
function todo_get_sidebar_widgets($sidebar_id = 'sidebar-1')
{
    // 暂时返回空数组，后续可以实现小工具系统
    return [];
}

/**
 * 获取文章归档
 *
 * @return array 归档数组
 */
function todo_get_archives()
{
    global $tododb;

    try {
        $sql = "SELECT 
                    YEAR(post_date) as year,
                    MONTH(post_date) as month,
                    MONTHNAME(post_date) as month_name,
                    COUNT(*) as count
                FROM {$tododb->prefix}posts 
                WHERE post_status = 'publish' 
                GROUP BY YEAR(post_date), MONTH(post_date), MONTHNAME(post_date) 
                ORDER BY year DESC, month DESC";

        $results = $tododb->query($sql);
        $results = $results ? $results : [];

        $archives = [];
        foreach ($results as $result) {
            $year = isset($result['year']) ? intval($result['year']) : 0;
            $month = isset($result['month']) ? intval($result['month']) : 0;

            // 归档链接始终语义化（路由已支持任意模式）
            if ($year > 0) {
                if ($month > 0) {
                    $url = todo_home_url(sprintf('/archive/%04d/%02d/', $year, $month));
                } else {
                    $url = todo_home_url(sprintf('/archive/%04d/', $year));
                }
            } else {
                $url = todo_home_url('/?page=archive');
            }
            $locale = function_exists('current_locale') ? current_locale() : '';
            if (function_exists('get_language_url') && !empty($locale)) {
                $url = get_language_url($locale, $url);
            }
            $archives[] = [
                'year' => $year,
                'month' => $month,
                'title' => $year . '/' . $month,
                'count' => isset($result['count']) ? intval($result['count']) : 0,
                'url' => $url
            ];
        }

        return $archives;
    } catch (Exception $e) {
        error_log('Error getting archives: ' . $e->getMessage());
        return [];
    }
}

/**
 * 获取友情链接
 *
 * @return array 链接数组
 */
function todo_get_bookmarks()
{
    global $tododb;

    try {
        $sql = "SELECT * FROM {$tododb->prefix}links WHERE status = 1 ORDER BY sort_order ASC, id DESC";
        $result = $tododb->query($sql);

        // 统一返回数组风格：$tododb->query返回的是关联数组列表，这里转换为对象数组以兼容前端模板
        $links = [];
        if ($result && is_array($result)) {
            foreach ($result as $row) {
                $links[] = (object) $row;
            }
        }

        return $links;
    } catch (Exception $e) {
        error_log('Error getting bookmarks: ' . $e->getMessage());
        return [];
    }
}

/**
 * 获取文章的评论数量
 *
 * @param int $post_id 文章ID
 * @return int 评论数量
 */
function todo_get_comments_number($post_id, $type = null)
{
    $post_id = (int) $post_id;
    if ($post_id <= 0) return 0;

    global $tododb;
    try {
        if ($type === 'review') {
            // 针对商品评价类型，统计 todo_product_reviews 表中已审核 (status = 1) 的记录
            $sql = "SELECT COUNT(*) FROM {$tododb->prefix}product_reviews WHERE product_id = ? AND status = 1";
            $count = (int) $tododb->get_var($sql, [$post_id]);
        } elseif ($type !== null && $type !== '' && $type !== 'all') {
            $sql = "SELECT COUNT(*) FROM {$tododb->prefix}comments WHERE comment_post_ID = ? AND comment_parent = 0 AND comment_type = ? AND comment_approved = '1'";
            $count = (int) $tododb->get_var($sql, [$post_id, (string)$type]);
        } else {
            $sql = "SELECT COUNT(*) FROM {$tododb->prefix}comments WHERE comment_post_ID = ? AND comment_parent = 0 AND comment_approved = '1'";
            $count = (int) $tododb->get_var($sql, [$post_id]);
        }

        return $count;
    } catch (Exception $e) {
        error_log('Error getting comments number: ' . $e->getMessage());
        return 0;
    }
}

/**
 * 检查是否为页面
 *
 * @return bool
 */
function todo_is_page()
{
    if (function_exists('get_current_page_type') && get_current_page_type() === 'page') {
        return true;
    }
    return isset($_GET['page_id']) && is_numeric($_GET['page_id']);
}

// 兼容旧版模板标签：提供 todo_* 判定函数包装到核心 is_*
function todo_is_home()
{
    if (function_exists('get_current_page_type') && get_current_page_type() === 'home') {
        return true;
    }
    if (function_exists('is_home') && is_home()) {
        return true;
    }
    // 排除搜索、标签、作者、归档、404等明确页面类型
    if (function_exists('todo_is_search') && todo_is_search()) {
        return false;
    }
    if (function_exists('todo_is_author') && todo_is_author()) {
        return false;
    }
    if (function_exists('todo_is_archive') && todo_is_archive()) {
        return false;
    }
    if (function_exists('todo_is_404') && todo_is_404()) {
        return false;
    }
    // 回退：当没有明确的单篇、页面、分类、标签上下文时视为首页
    $is_single = function_exists('todo_is_single') ? todo_is_single() : (function_exists('is_single') ? is_single() : (isset($_GET['single']) || (isset($_GET['route']) && $_GET['route'] === 'single')));
    $is_page = function_exists('is_page') ? is_page() : (isset($_GET['page']) || (isset($_GET['route']) && $_GET['route'] === 'page'));
    $is_cat = function_exists('is_category') ? is_category() : (isset($_GET['cat']) || isset($_GET['category']) || (isset($_GET['route']) && $_GET['route'] === 'category'));
    $is_tag = function_exists('is_tag') ? is_tag() : (isset($_GET['tag']) || (isset($_GET['route']) && $_GET['route'] === 'tag'));
    return !($is_single || $is_page || $is_cat || $is_tag);
}

function todo_is_front_page()
{
    if (function_exists('is_front_page') && is_front_page()) {
        return true;
    }
    return todo_is_home();
}

function todo_is_single()
{
    if (function_exists('get_current_page_type') && get_current_page_type() === 'single') {
        return true;
    }
    if (function_exists('is_single') && is_single()) {
        return true;
    }
    // 排除明确标识为非文章详情页的 page 参数
    if (isset($_GET['page']) && in_array($_GET['page'], ['category', 'tag', 'archive', 'search', 'shop', 'user', 'author', '404', 'login', 'register', 'checkout', 'cart'], true)) {
        return false;
    }
    if (isset($_GET['single']) || (isset($_GET['route']) && $_GET['route'] === 'single')) {
        return true;
    }
    if (isset($_GET['p']) && is_numeric($_GET['p'])) {
        return true;
    }
    if (isset($_GET['post_id']) && is_numeric($_GET['post_id'])) {
        return true;
    }
    if (isset($_GET['id']) && is_numeric($_GET['id'])) {
        if (isset($_GET['cat']) || isset($_GET['category']) || (isset($_GET['route']) && $_GET['route'] === 'category')) {
            return false;
        }
        return true;
    }
    return false;
}

function todo_is_category()
{
    if (function_exists('get_current_page_type') && get_current_page_type() === 'category') {
        return true;
    }
    // 文章/页面详情页不视为分类归档
    if (isset($_GET['page']) && in_array($_GET['page'], ['post', 'single', 'page'], true)) {
        return false;
    }
    if (function_exists('is_category') && is_category()) {
        return true;
    }
    if (isset($_GET['route']) && $_GET['route'] === 'category') {
        return true;
    }
    if (isset($_GET['cat']) || isset($_GET['category'])) {
        return true;
    }
    return false;
}

function todo_is_tag()
{
    if (function_exists('get_current_page_type') && in_array(get_current_page_type(), ['tag', 'tag_index', 'tag_lists'])) {
        return true;
    }
    // 文章/页面详情页不视为标签归档
    if (isset($_GET['page']) && in_array($_GET['page'], ['post', 'single', 'page'], true)) {
        return false;
    }
    if (function_exists('is_tag') && is_tag()) {
        return true;
    }
    if (isset($_GET['route']) && $_GET['route'] === 'tag') {
        return true;
    }
    if (isset($_GET['page']) && $_GET['page'] === 'tag') {
        return true;
    }
    if (isset($_GET['tag'])) {
        return true;
    }
    return false;
}

function todo_is_search()
{
    if (function_exists('get_current_page_type') && get_current_page_type() === 'search') {
        return true;
    }
    if (function_exists('is_search') && is_search()) {
        return true;
    }
    if (isset($_GET['route']) && $_GET['route'] === 'search') {
        return true;
    }
    if (isset($_GET['page']) && $_GET['page'] === 'search') {
        return true;
    }
    if (!empty($_GET['s']) || !empty($_GET['search']) || !empty($_GET['q'])) {
        return true;
    }
    return false;
}

function todo_is_paged()
{
    // 文章/页面详情页不视为分页
    if (isset($_GET['page']) && in_array($_GET['page'], ['post', 'single', 'page'], true)) {
        return false;
    }
    $current_page = 1;
    if (isset($_GET['paged'])) {
        $current_page = max(1, intval($_GET['paged']));
    } elseif (isset($_GET['page']) && is_numeric($_GET['page'])) {
        $current_page = max(1, intval($_GET['page']));
    } elseif (isset($_SERVER['REQUEST_URI']) && preg_match('#/page/([0-9]+)/?#', (string) $_SERVER['REQUEST_URI'], $_pgm)) {
        $current_page = max(1, intval($_pgm[1]));
    }
    return $current_page > 1;
}

function todo_is_author()
{
    // 文章/页面详情页不视为作者归档
    if (isset($_GET['page']) && in_array($_GET['page'], ['post', 'single', 'page'], true)) {
        return false;
    }
    if (isset($_GET['route']) && $_GET['route'] === 'author') {
        return true;
    }
    if (isset($_GET['page']) && $_GET['page'] === 'author') {
        return true;
    }
    if (isset($_GET['author']) || isset($_GET['author_name']) || isset($_GET['author_id'])) {
        return true;
    }
    return false;
}

function todo_is_archive()
{
    if (function_exists('get_current_page_type') && get_current_page_type() === 'archive') {
        return true;
    }
    // 文章/页面详情页不视为归档
    if (isset($_GET['page']) && in_array($_GET['page'], ['post', 'single', 'page'], true)) {
        return false;
    }
    if (function_exists('is_archive') && is_archive()) {
        return true;
    }
    if (isset($_GET['route']) && $_GET['route'] === 'archive') {
        return true;
    }
    if (isset($_GET['page']) && $_GET['page'] === 'archive') {
        return true;
    }
    if (isset($_GET['archive_year']) || isset($_GET['archive_month']) || isset($_GET['archive_day'])) {
        return true;
    }
    if (isset($_GET['year']) || isset($_GET['month']) || isset($_GET['monthnum']) || isset($_GET['day'])) {
        return true;
    }
    return false;
}

function todo_is_404()
{
    if (function_exists('get_current_page_type') && get_current_page_type() === '404') {
        return true;
    }
    if (function_exists('is_404') && is_404()) {
        return true;
    }
    if (isset($_GET['route']) && $_GET['route'] === '404') {
        return true;
    }
    if (isset($GLOBALS['http_status']) && intval($GLOBALS['http_status']) === 404) {
        return true;
    }
    return false;
}
