<?php
/**
 * 分页函数
 *
 * 提供分页功能的实现
 *
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 生成分页HTML
 *
 * @param int $total 总记录数
 * @param int $per_page 每页显示数量
 * @param int $current_page 当前页码
 * @param string $base_url 基础URL
 * @param string $page_param 页码参数名称
 * @return string 分页HTML
 */
function paginate_links($args = array()) {
    // 默认参数
    $defaults = array(
        'total' => 0,
        'per_page' => 20,
        'current_page' => 1,
        'base_url' => '',
        'page_param' => 'paged',
        'prev_text' => '❮',
        'next_text' => '❯',
        'mid_size' => 2,
        'end_size' => 1,
        'add_fragment' => '',
    );
    
    // 合并参数
    $args = array_merge($defaults, $args);

    // 支持 paginate_links 参数（base/current/total/type），返回 array 或 string
    if (isset($args['base']) || isset($args['current'])) {
        $total_pages = isset($args['total']) ? (int)$args['total'] : 0;
        if ($total_pages <= 1) {
            return (isset($args['type']) && $args['type'] === 'array') ? array() : '';
        }

        $current = isset($args['current']) ? (int)$args['current'] : 1;
        $prev_text = isset($args['prev_text']) ? $args['prev_text'] : '❮';
        $next_text = isset($args['next_text']) ? $args['next_text'] : '❯';
        $mid_size = isset($args['mid_size']) ? (int)$args['mid_size'] : 2;
        $end_size = isset($args['end_size']) ? (int)$args['end_size'] : 1;
        $base = isset($args['base']) ? $args['base'] : add_query_arg('paged', '%#%');
        
        // 兼容 add_query_arg 对占位符 %#% 的 URL 编码（%25%23%25）
        $replaceHref = function($page) use ($base) {
            if (strpos($base, '%#%') !== false) {
                return str_replace('%#%', $page, $base);
            }
            if (strpos($base, '%25%23%25') !== false) {
                return str_replace('%25%23%25', $page, $base);
            }
            // 回退：直接追加/替换 paged 参数
            return add_query_arg('paged', $page, $base);
        };

        $links = array();

        $dots_before = false;
        $dots_after = false;

        for ($i = 1; $i <= $total_pages; $i++) {
            $is_begin_range = $i <= $end_size;
            $is_middle_range = $i >= $current - $mid_size && $i <= $current + $mid_size;
            $is_end_range = $i > $total_pages - $end_size;

            if ($is_begin_range || $is_middle_range || $is_end_range) {
                if ($i == $current) {
                    $links[] = '<span class="page-numbers current">' . $i . '</span>';
                } else {
                    $links[] = '<a class="page-numbers" href="' . $replaceHref($i) . '">' . $i . '</a>';
                }
                if ($i < $current) { $dots_before = false; }
                if ($i > $current) { $dots_after = false; }
            } else {
                if ($i < $current && !$dots_before) {
                    $links[] = '<span class="page-numbers dots">⋯</span>';
                    // 上一页
                    if ($current > 1) {
                        $links[] = '<a class="prev-page" href="' . $replaceHref($current - 1) . '">' . $prev_text . '</a>';
                    }
                    $dots_before = true;
                }
                if ($i > $current && !$dots_after) {
                    $links[] = '<span class="page-numbers dots">⋯</span>';
                    // 下一页
                    if ($current < $total_pages) {
                        $links[] = '<a class="next-page" href="' . $replaceHref($current + 1) . '">' . $next_text . '</a>';
                    }
                    $dots_after = true;
                }
            }
        }

        return (isset($args['type']) && $args['type'] === 'array') ? $links : implode('', $links);
    }
    
    // 验证参数
    if ($args['per_page'] <= 0) {
        $args['per_page'] = 20; // 默认值
    }
    
    if ($args['total'] <= 0) {
        return '';
    }
    
    // 计算总页数
    $total_pages = ceil($args['total'] / $args['per_page']);
    
    // 如果只有一页，不显示分页
    if ($total_pages <= 1) {
        return '';
    }
    
    // 确保当前页码有效
    $args['current_page'] = max(1, min($args['current_page'], $total_pages));
    
    // 构建URL
    $base_url = $args['base_url'];
    $page_param = $args['page_param'];
    
    // 添加问号或&符号
    if (strpos($base_url, '?') !== false) {
        $url_joiner = '&';
    } else {
        $url_joiner = '?';
    }
    
    // 构建分页HTML
    $output = '<div class="pagination">';
    
    // 页码
    $dots_before = false;
    $dots_after = false;
    
    for ($i = 1; $i <= $total_pages; $i++) {
        // 显示首尾页码
        $is_begin_range = $i <= $args['end_size'];
        // 显示当前页码周围的页码
        $is_middle_range = $i >= $args['current_page'] - $args['mid_size'] && $i <= $args['current_page'] + $args['mid_size'];
        // 显示末尾页码
        $is_end_range = $i > $total_pages - $args['end_size'];
        
        // 如果在显示范围内
        if ($is_begin_range || $is_middle_range || $is_end_range) {
            if ($i == $args['current_page']) {
                $output .= '<span class="page-numbers current">' . $i . '</span>';
            } else {
                $output .= '<a href="' . $base_url . $url_joiner . $page_param . '=' . $i . $args['add_fragment'] . '" class="page-numbers">' . $i . '</a>';
            }
            
            // 重置省略号标记
            if ($i < $args['current_page']) {
                $dots_before = false;
            }
            if ($i > $args['current_page']) {
                $dots_after = false;
            }
        } else {
            // 显示省略号
            if ($i < $args['current_page'] && !$dots_before) {
                // 上一页
                $prev_page = $args['current_page'] - 1;
                $output .= '<a href="' . $base_url . $url_joiner . $page_param . '=' . $prev_page . $args['add_fragment'] . '" class="prev-page">' . $args['prev_text'] . '</a>';
                
                $output .= '<span class="page-numbers dots">⋯</span>';
                $dots_before = true;
            }
            if ($i > $args['current_page'] && !$dots_after) {
                $output .= '<span class="page-numbers dots">⋯</span>';
                
                // 下一页
                $next_page = $args['current_page'] + 1;
                $output .= '<a href="' . $base_url . $url_joiner . $page_param . '=' . $next_page . $args['add_fragment'] . '" class="next-page">' . $args['next_text'] . '</a>';
                $dots_after = true;
            }
        }
    }
    
    $output .= '</div>';
    
    return $output;
}

if (!function_exists('todo_render_pagination')) {
    function todo_render_pagination($args = array())
    {
        $defaults = array(
            'total_items' => 0,
            'per_page' => 20,
            'current_page' => 1,
            'base_url' => '',
            'page_param' => 'paged',
            'add_args' => array(),
            'show_count' => true,
            'prev_text' => '❮',
            'next_text' => '❯',
            'mid_size' => 2,
            'end_size' => 1,
        );

        $args = array_merge($defaults, is_array($args) ? $args : array());

        $total_items = (int) $args['total_items'];
        $per_page = (int) $args['per_page'];
        $current_page = (int) $args['current_page'];
        $base_url = (string) $args['base_url'];
        $page_param = (string) $args['page_param'];

        if ($per_page <= 0) {
            $per_page = 20;
        }

        if ($total_items <= 0) {
            return '';
        }

        $total_pages = (int) ceil($total_items / $per_page);
        if ($total_pages <= 1) {
            return '';
        }

        if ($current_page <= 0) {
            $current_page = 1;
        }
        if ($current_page > $total_pages) {
            $current_page = $total_pages;
        }

        if (is_array($args['add_args']) && !empty($args['add_args']) && function_exists('add_query_arg')) {
            $base_url = add_query_arg($args['add_args'], $base_url);
        }

        if (function_exists('remove_query_arg')) {
            $base_url = remove_query_arg($page_param, $base_url);
        }

        $base = $base_url;
        if (function_exists('add_query_arg')) {
            $base = add_query_arg($page_param, '%#%', $base_url);
        } else {
            $joiner = (strpos($base_url, '?') !== false) ? '&' : '?';
            $base = $base_url . $joiner . rawurlencode($page_param) . '=%#%';
        }

        $links = paginate_links(array(
            'base' => $base,
            'format' => '',
            'prev_text' => $args['prev_text'],
            'next_text' => $args['next_text'],
            'total' => $total_pages,
            'current' => $current_page,
            'mid_size' => (int) $args['mid_size'],
            'end_size' => (int) $args['end_size'],
            'type' => 'array',
        ));

        if (empty($links)) {
            return '';
        }

        $links_html = '';
        if (is_array($links)) {
            $links_html = implode('', $links);
        } else {
            $links_html = (string) $links;
        }

        if ($links_html === '') {
            return '';
        }

        $count_html = '';
        if (!empty($args['show_count'])) {
            $offset = ($current_page - 1) * $per_page;
            $start = $offset + 1;
            $end = min($offset + $per_page, $total_items);
            $count_html = '<div class="info">第 ' . esc_html($start) . ' - ' . esc_html($end) . ' 个，共 ' . esc_html($total_items) . ' 个</div>';
        }

        return '<div class="admin-pagination">' . $count_html . '<div class="pagination">' . $links_html . '</div></div>';
    }
}

/**
 * 获取分页链接URL
 *
 * @param int $pagenum 页码
 * @param bool $escape 是否转义URL
 * @return string 分页链接URL
 */
function get_pagenum_link($pagenum = 1, $escape = true) {
    global $todo_rewrite;
    
    $pagenum = (int) $pagenum;
    
    $request = remove_query_arg('paged');
    $request_parts = parse_url($request);
    $request_path = isset($request_parts['path']) ? $request_parts['path'] : '';
    $request_query = (isset($request_parts['query']) && $request_parts['query'] !== '') ? ('?' . $request_parts['query']) : '';
    
    $home_root = parse_url(home_url());
    $home_root = (isset($home_root['path'])) ? $home_root['path'] : '';
    $home_root = preg_quote($home_root, '|');
    
    $request_path = preg_replace('|^' . $home_root . '|i', '', $request_path);
    $request_path = preg_replace('|^/+|', '', $request_path);
    $request_path = preg_replace('~(?:^|/)page/\d+/?$~', '', $request_path);
    $request_path = trim($request_path, '/');
    
    // 过滤掉不正确的路径，如 .well-known 等
    if (strpos($request_path, '.well-known') !== false || 
        strpos($request_path, 'favicon') !== false ||
        preg_match('/\.(ico|png|jpg|jpeg|gif|css|js)$/i', $request_path)) {
        $request_path = '';
    }
    
    // 如果是首页或空请求，确保返回正确的首页URL
    if (!$pagenum || $pagenum == 1) {
        if (empty($request_path) || $request_path === 'index.php') {
            $result = home_url() . $request_query;
        } else {
            $result = home_url() . '/' . $request_path . '/';
            if ($request_query !== '') {
                $result .= $request_query;
            }
        }
    } else {
        if (get_option('permalink_structure')) {
            if (empty($request_path) || $request_path === 'index.php') {
                $result = home_url() . '/page/' . $pagenum . '/' . $request_query;
            } else {
                $result = home_url() . '/' . $request_path . '/page/' . $pagenum . '/' . $request_query;
            }
        } else {
            $base = (empty($request_path) || $request_path === 'index.php') ? home_url() : (home_url() . '/' . $request_path . '/');
            $base = rtrim($base, '/');
            if ($request_query !== '') {
                $base .= $request_query;
            }
            $result = add_query_arg('paged', $pagenum, $base);
        }
    }
    
    if ($escape) {
        return esc_url($result);
    } else {
        return $result;
    }
}

/**
 * 移除查询参数
 *
 * @param string $key 要移除的参数名
 * @param string $query 查询字符串，默认为当前请求
 * @return string 处理后的URL
 */
if (!function_exists('remove_query_arg')) {
    function remove_query_arg($key, $query = null) {
        if (is_null($query)) {
            $query = function_exists('todo_get_request_uri') ? todo_get_request_uri() : (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '');
        }
        
        $url_parts = parse_url($query);
        
        if (isset($url_parts['query'])) {
            parse_str($url_parts['query'], $params);
            unset($params[$key]);
            $url_parts['query'] = http_build_query($params);
        }
        
        $result = '';
        if (isset($url_parts['path'])) {
            $result .= $url_parts['path'];
        }
        if (isset($url_parts['query']) && !empty($url_parts['query'])) {
            $result .= '?' . $url_parts['query'];
        }
        if (isset($url_parts['fragment'])) {
            $result .= '#' . $url_parts['fragment'];
        }
        
        return $result;
    }
}

/**
 * 添加查询参数
 *
 * @param string|array $key 参数名或参数数组
 * @param string $value 参数值（当$key为字符串时使用）
 * @param string $url URL，默认为当前请求
 * @return string 处理后的URL
 */
if (!function_exists('add_query_arg')) {
    function add_query_arg($key, $value = null, $url = null) {
        // 如果第一个参数是数组，则支持批量添加参数
        if (is_array($key)) {
            $url = $value; // 第二个参数是URL
            $params_to_add = $key;
        } else {
            // 传统的单个参数模式
            $params_to_add = array($key => $value);
        }
        
        if (is_null($url)) {
            $url = function_exists('todo_get_request_uri') ? todo_get_request_uri() : (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '');
        }
        
        $url_parts = parse_url($url);
        $params = array();
        
        if (isset($url_parts['query'])) {
            parse_str($url_parts['query'], $params);
        }
        
        // 合并新参数
        foreach ($params_to_add as $param_key => $param_value) {
            if (is_string($param_key) || is_int($param_key)) {
                $params[$param_key] = $param_value;
            }
        }
        
        $url_parts['query'] = http_build_query($params);
        
        $result = '';
        if (isset($url_parts['scheme'])) {
            $result .= $url_parts['scheme'] . '://';
        }
        if (isset($url_parts['host'])) {
            $result .= $url_parts['host'];
        }
        if (isset($url_parts['port'])) {
            $result .= ':' . $url_parts['port'];
        }
        if (isset($url_parts['path'])) {
            $result .= $url_parts['path'];
        }
        if (isset($url_parts['query']) && !empty($url_parts['query'])) {
            $result .= '?' . $url_parts['query'];
        }
        if (isset($url_parts['fragment'])) {
            $result .= '#' . $url_parts['fragment'];
        }
        
        return $result;
    }
}
