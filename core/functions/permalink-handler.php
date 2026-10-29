<?php
/**
 * TodoCMS Permalink Handler
 *
 * @package TodoCMS
 * @subpackage Core
 */

if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    die('Direct access denied');
}

/**
 * 初始化固定链接重写规则
 */
function todo_init_permalink_rules() {
    $permalink_structure = get_option('permalink_structure', '');
    
    if (empty($permalink_structure)) {
        $permalink_structure = '/%postname%/'; // 统一采用最新方案，默认启用友好链接
    }

    // 1. 生成基于结构的博文重写规则
    $rules = todo_generate_permalink_rules($permalink_structure);
    
    foreach ($rules as $regex => $query) {
        add_rewrite_rule($regex, $query, 'bottom');
    }

    // 2. 添加基础归档规则 (Topic Clusters Support)
    add_rewrite_rule('^search/([^/]+)/?$', 'index.php?s=$matches[1]', 'top');
    add_rewrite_rule('^author/([0-9]+)/?$', 'index.php?author=$matches[1]', 'top');
    add_rewrite_rule('^tag/([^/]+)/?$', 'index.php?tag_name=$matches[1]', 'top');
    add_rewrite_rule('^category/([^/]+)/?$', 'index.php?category_name=$matches[1]', 'top');

    // 2b. 归档分页规则 (SEO: rel="prev/next" support)
    add_rewrite_rule('^author/([0-9]+)/page/([0-9]+)/?$', 'index.php?author=$matches[1]&paged=$matches[2]', 'top');
    add_rewrite_rule('^tag/([^/]+)/page/([0-9]+)/?$', 'index.php?tag_name=$matches[1]&paged=$matches[2]', 'top');
    add_rewrite_rule('^category/([^/]+)/page/([0-9]+)/?$', 'index.php?category_name=$matches[1]&paged=$matches[2]', 'top');
    add_rewrite_rule('^page/([0-9]+)/?$', 'index.php?paged=$matches[1]', 'top');

    // 2c. 日期归档规则
    add_rewrite_rule('^([0-9]{4})/([0-9]{1,2})/([0-9]{1,2})/?$', 'index.php?year=$matches[1]&monthnum=$matches[2]&day=$matches[3]', 'top');
    add_rewrite_rule('^([0-9]{4})/([0-9]{1,2})/?$', 'index.php?year=$matches[1]&monthnum=$matches[2]', 'top');
    // 2d. API 支付路由规则
    add_rewrite_rule('^api/payment/(.*)$', 'index.php?page=api&path=payment/$matches[1]', 'top');
}

/**
 * 根据固定链接结构生成重写规则
 *
 * @param string $structure 固定链接结构
 * @return array 重写规则数组
 */
function todo_generate_permalink_rules($structure) {
    $rules = array();

    // 扩展可选段（目前支持作者与breadcrumb可缺省）
    $structures = todo_expand_optional_structures($structure, array('%author%', '%breadcrumb%'));

    foreach ($structures as $st) {
        // 编译结构为正则与键映射（保证捕获组顺序与结构中标签顺序一致）
        $compiled = todo_compile_permalink_structure($st);
        $anchored = $compiled['regex'];
        $query    = todo_build_query_from_keys($compiled['query_keys']);

        // 基础规则（无语言前缀）
        $rules[$anchored] = $query;

        // 严格模式：不自动扩展“日”段，始终仅匹配当前所选结构

        // 语言路径前缀支持（仅在 path_prefix 路由模式时加入）
        if (class_exists('LanguageRegistry')) {
            $registry = LanguageRegistry::get_instance();
            if ($registry && method_exists($registry, 'get_routing_mode') && $registry->get_routing_mode() === 'path_prefix') {
                $map = $registry->path_prefix_map();
                if (is_array($map)) {
                    foreach ($map as $locale => $prefix) {
                        $prefix = trim((string)$prefix, '/');
                        if ($prefix === '') {
                            continue; // 默认语言无前缀或空前缀不重复生成
                        }
                        $langRegex = '^' . preg_quote($prefix, '/') . '\/' . ltrim($anchored, '^');
                        $rules[$langRegex] = $query;
                    }
                }
            }
        }
    }

    return $rules;
}

/**
 * 可选段扩展：给定固定链接结构，生成包含去除可选标签段的所有变体
 * 目前将 %author% 与 %breadcrumb% 视为可选段，当其在URL中缺失时也可匹配解析
 */
function todo_expand_optional_structures($structure, $optional_tokens = array('%author%', '%breadcrumb%')) {
    $variants = array();
    $queue = array($structure);
    $seen = array();

    while (!empty($queue)) {
        $curr = array_shift($queue);
        if (isset($seen[$curr])) {
            continue;
        }
        $seen[$curr] = true;
        $variants[] = $curr;

        foreach ($optional_tokens as $token) {
            if (strpos($curr, $token) !== false) {
                $without = $curr;
                // 情形1：/token/ -> /
                $without = preg_replace('#/' . preg_quote($token, '#') . '/#', '/', $without);
                // 情形2：/token (末尾)
                $without = preg_replace('#/' . preg_quote($token, '#') . '$#', '', $without);
                // 情形3：token/ (开头)
                $without = preg_replace('#^' . preg_quote($token, '#') . '/#', '', $without);
                // 情形4：孤立 token
                $without = str_replace($token, '', $without);
                // 规范化斜杠
                $without = preg_replace('#/{2,}#', '/', $without);
                // 确保以斜杠开头
                if ($without === '' || $without[0] !== '/') {
                    $without = '/' . ltrim($without, '/');
                }
                if (!isset($seen[$without])) {
                    $queue[] = $without;
                }
            }
        }
    }

    // 去重返回
    return array_values(array_unique($variants));
}

/**
 * 编译固定链接结构为正则与查询键映射
 * 保证捕获组顺序与结构内标签出现顺序一致
 */
function todo_compile_permalink_structure($structure) {
    if (class_exists('Todo_Core_Kernel') && is_callable(['Todo_Core_Kernel', 'compile_permalink_structure'])) {
        return Todo_Core_Kernel::compile_permalink_structure($structure);
    }
    exit('Security Error: Core Routing Engine Missing');
}

/**
 * 由查询键映射生成 index.php 查询字符串（按捕获组顺序）
 */
function todo_build_query_from_keys($keys) {
    if (empty($keys)) {
        return 'index.php?';
    }
    $pairs = array();
    $i = 1;
    foreach ($keys as $key) {
        $pairs[] = $key . '=$matches[' . $i++ . ']';
    }
    return 'index.php?' . implode('&', $pairs);
}

/**
 * 根据固定链接结构生成查询字符串
 *
 * @param string $structure 固定链接结构
 * @return string 查询字符串
 */
function todo_generate_permalink_query($structure) {
    $query_vars = array();
    $match_index = 1;
    
    // 检查包含的标签并生成对应的查询变量
    if (strpos($structure, '%year%') !== false) {
        $query_vars[] = 'year=$matches[' . $match_index++ . ']';
    }
    if (strpos($structure, '%monthnum%') !== false) {
        $query_vars[] = 'monthnum=$matches[' . $match_index++ . ']';
    }
    if (strpos($structure, '%day%') !== false) {
        $query_vars[] = 'day=$matches[' . $match_index++ . ']';
    }
    if (strpos($structure, '%hour%') !== false) {
        $query_vars[] = 'hour=$matches[' . $match_index++ . ']';
    }
    if (strpos($structure, '%minute%') !== false) {
        $query_vars[] = 'minute=$matches[' . $match_index++ . ']';
    }
    if (strpos($structure, '%second%') !== false) {
        $query_vars[] = 'second=$matches[' . $match_index++ . ']';
    }
    if (strpos($structure, '%postname%') !== false) {
        $query_vars[] = 'name=$matches[' . $match_index++ . ']';
    }
    if (strpos($structure, '%post_id%') !== false) {
        $query_vars[] = 'p=$matches[' . $match_index++ . ']';
    }
    if (strpos($structure, '%category%') !== false) {
        $query_vars[] = 'category_name=$matches[' . $match_index++ . ']';
    }
    if (strpos($structure, '%breadcrumb%') !== false) {
        $query_vars[] = 'breadcrumb=$matches[' . $match_index++ . ']';
    }
    if (strpos($structure, '%author%') !== false) {
        $query_vars[] = 'author_name=$matches[' . $match_index++ . ']';
    }
    
    return 'index.php?' . implode('&', $query_vars);
}

/**
 * 解析固定链接请求
 *
 * @param string $request_uri 请求URI
 * @return array|false 解析结果或false
 */
if (!function_exists('todo_parse_permalink_request')) {
    function todo_parse_permalink_request($request_uri) {
        if (class_exists('Todo_Core_Kernel')) {
            return Todo_Core_Kernel::dispatch_routing($request_uri);
        }
        return false;
    }
}

/**
 * 根据查询变量获取文章ID
 *
 * @param array $query_vars 查询变量
 * @return int|false 文章ID或false
 */
if (!function_exists('todo_get_post_id_from_query_vars')) {
    function todo_get_post_id_from_query_vars($query_vars) {
        if (class_exists('Todo_Core_Kernel')) {
            return Todo_Core_Kernel::resolve_post_id($query_vars);
        }
        return false;
    }
}

// 注册钩子（仅在钩子系统已加载时）
if (function_exists('add_action')) {
    add_action('init', 'todo_init_permalink_rules');
    add_action('generate_rewrite_rules', 'todo_init_permalink_rules');
} elseif (class_exists('TodoHooks')) {
    // 如果钩子系统类存在但函数未定义，使用类方法
    TodoHooks::add_action('init', 'todo_init_permalink_rules');
    TodoHooks::add_action('generate_rewrite_rules', 'todo_init_permalink_rules');
} else {
    // 如果钩子系统未加载，延迟注册（通过注册关闭函数）
    register_shutdown_function(function() {
        if (function_exists('add_action')) {
            add_action('init', 'todo_init_permalink_rules');
            add_action('generate_rewrite_rules', 'todo_init_permalink_rules');
        }
    });
}
