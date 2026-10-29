<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}

/**
 * 缓存管理页面
 * 
 * @package TodoCMS
 * @subpackage Admin
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 检查用户权限
if (!defined('TODO_ADMIN')) {
    exit('Access Denied');
}
if (!current_user_can('manage_cache') && !current_user_can('manage_options')) {
    todo_die(__('你没有权限访问此页面'));
}

// 引入统一缓存管理器和日志系统
require_once ABSPATH . 'core/functions/cache-functions.php';

// 初始化缓存管理器
Todo_Unified_Cache_Manager::init();

$admin_entry_file = 'index.php';
if (!todo_is_demo_admin_session() && function_exists('todo_get_admin_entry_filename')) {
    $resolved_admin_entry = todo_get_admin_entry_filename();
    if (!empty($resolved_admin_entry)) {
        $admin_entry_file = $resolved_admin_entry . '.php';
    }
}
$page_url = todo_admin_url($admin_entry_file . '?page=cache-management');

if (!function_exists('todo_cache_warmup_get_languages')) {
    function todo_cache_warmup_get_languages()
    {
        $languages = array();

        if (class_exists('LanguageRegistry')) {
            $registry = LanguageRegistry::get_instance();
            $languages = array_keys($registry->get_languages(true));
        }

        if (empty($languages) && class_exists('TodoLanguageManager')) {
            $languages = array_keys(TodoLanguageManager::get_instance()->get_supported_languages());
        }

        if (empty($languages)) {
            $default_lang = function_exists('get_default_language') ? get_default_language() : 'zh-CN';
            $languages = array($default_lang);
        }

        $normalized = array();
        foreach ($languages as $language) {
            $language = str_replace('_', '-', (string) $language);
            if (function_exists('canonical_locale')) {
                $language = canonical_locale($language);
            }
            if ($language !== '') {
                $normalized[$language] = $language;
            }
        }

        return array_values($normalized);
    }
}

if (!function_exists('todo_cache_warmup_set_locale_context')) {
    function todo_cache_warmup_set_locale_context($locale)
    {
        $locale = str_replace('_', '-', (string) $locale);
        if (function_exists('canonical_locale')) {
            $locale = canonical_locale($locale);
        }

        if ($locale === '') {
            return '';
        }

        $GLOBALS['_todo_current_locale'] = $locale;
        $_GET['lang'] = $locale;
        $_REQUEST['lang'] = $locale;

        if (class_exists('LocaleContext')) {
            try {
                LocaleContext::get_instance()->set_locale($locale);
            } catch (Throwable $e) {
                // 忽略上下文切换异常，继续使用其余回退链路。
            }
        }

        if (class_exists('TodoLanguageManager')) {
            try {
                TodoLanguageManager::get_instance()->switch_language($locale);
            } catch (Throwable $e) {
                // 兼容旧管理器，避免其异常中断预热流程。
            }
        }

        if (class_exists('CMS_Theme')) {
            CMS_Theme::reset_active_theme_cache();
        }

        return $locale;
    }
}

if (!function_exists('todo_cache_warmup_collect_template_files')) {
    function todo_cache_warmup_collect_template_files($template_dir)
    {
        $template_files = array();
        if (!$template_dir || !is_dir($template_dir)) {
            return $template_files;
        }

        $template_extensions = array('html', 'htm');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($template_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (in_array($ext, $template_extensions, true)) {
                $template_files[] = $file->getPathname();
            }
        }

        return $template_files;
    }
}

// 处理 AJAX 请求 (必须在任何可能被URL参数触发的通用逻辑之前处理)
if (isset($_POST['action'])) {
    // 处理计算缓存URL请求 (AJAX)
    if ($_POST['action'] === 'calculate_cache_urls') {
        global $tododb; // 确保全局数据库对象可用

        // 标记处于预热上下文，确保主题过滤器 (active_theme) 按语言映射解析，
        // 而非返回 admin 默认主题，避免后续 clear_page_cache_entry 与
        // generate_page_cache 使用不同的模板路径导致缓存键不匹配。
        $GLOBALS['todo_doing_cache_warmup'] = true;

        if (!Todo_Security_Manager::verify_request_csrf('cache_warmup')) {
            while (ob_get_level())
                ob_end_clean();
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode(array('success' => false, 'data' => '安全验证失败'));
            exit;
        }

        $generate_options = isset($_POST['warmup_options']) ? $_POST['warmup_options'] : array();
        // 鲁棒性处理：如果前端传过来的是逗号分隔的字符串，转换为数组
        if (is_string($generate_options) && !empty($generate_options)) {
            $generate_options = explode(',', $generate_options);
        }
        
        $custom_urls_raw = isset($_POST['custom_urls']) ? $_POST['custom_urls'] : '';
        $max_posts = isset($_POST['max_posts']) ? intval($_POST['max_posts']) : 5000;
        if ($max_posts <= 0)
            $max_posts = 5000;

        // 仅在拿到最终预热 URL 后，按页面精确删除对应 HTML 缓存文件。
        // 这样“只预热分类”不会影响已生成的首页缓存，也不会误清空整个 templates 目录。

        // 确保必要的函数已加载
        if (!function_exists('todo_home_url'))
            require_once ABSPATH . 'core/functions/core.php';
        if (!function_exists('todo_get_category_link'))
            require_once ABSPATH . 'core/functions/core.php';
        if (!function_exists('todo_get_tag_link'))
            require_once ABSPATH . 'core/functions/core.php';
        if (!function_exists('todo_get_permalink'))
            require_once ABSPATH . 'core/functions/core.php';

        // 获取所有支持的语言
        $languages = todo_cache_warmup_get_languages();

        $urls_by_lang = array();
        $stats_by_lang = array();
        $total_count = 0;

        // 基础URL获取函数
        $get_base_url = function () {
            return function_exists('todo_home_url') ? todo_home_url() : (function_exists('home_url') ? home_url() : '');
        };

        // 获取语言的站点列表用于预热：
        // 有配置真实站点 → 只用真实站点ID（不含0，因为访问必有站点上下文）
        // 无配置站点 → 用 0 作为无站点维度兜底
        $get_site_ids = function ($lang) {
            $site_ids = array();
            if (function_exists('todo_get_language_sites')) {
                $sites = todo_get_language_sites($lang);
                if (!empty($sites)) {
                    foreach ($sites as $site) {
                        $sid = isset($site['id']) ? (int) $site['id'] : 0;
                        if ($sid > 0 && !in_array($sid, $site_ids, true)) {
                            $site_ids[] = $sid;
                        }
                    }
                }
            }
            // 没有真实站点时，使用 0 作为默认维度
            if (empty($site_ids)) {
                $site_ids[] = 0;
            }
            return $site_ids;
        };

        // 遍历所有语言生成URL
        foreach ($languages as $lang) {
            // 在生成当前语言的分类/文章/首页 URL 之前，显式设置语言上下文
            todo_cache_warmup_set_locale_context($lang);

            $urls_by_lang[$lang] = array();
            $stats_by_lang[$lang] = array(
                'homepage' => 0,
                'categories' => 0,
                'all_posts' => 0,
                'custom' => 0
            );

            // 首页（按站点维度生成缓存）
            if (in_array('homepage', $generate_options)) {
                $home_url = function_exists('todo_home_url') ? todo_home_url() : $get_base_url();
                if ($home_url) {
                    $site_ids = $get_site_ids($lang);
                    foreach ($site_ids as $sid) {
                        $url = function_exists('get_language_url') ? get_language_url($lang, $home_url) : $home_url;
                        $urls_by_lang[$lang][] = array(
                            'url' => $url,
                            'type' => 'home',
                            'id' => 0,
                            'site_id' => $sid
                        );
                        $stats_by_lang[$lang]['homepage']++;
                    }
                }
            }

            // 分类页 (只缓存分类列表第一页，按站点维度生成)
            if (in_array('categories', $generate_options)) {
                if (!isset($tododb) && class_exists('CMS_DB')) {
                    $tododb = CMS_DB::get_instance();
                }
                if (isset($tododb)) {
                    $categories = $tododb->get_results("SELECT t.term_id, t.slug FROM " . $tododb->prefix . "terms t INNER JOIN " . $tododb->prefix . "term_taxonomy tt ON t.term_id = tt.term_id WHERE tt.taxonomy = 'category'");
                    if ($categories) {
                        $site_ids = $get_site_ids($lang);
                        foreach ($categories as $cat) {
                            // 排除单页模型分类（model_key === 'single' 或 cat_slug === 'single'），此类模型本身不存在列表页，直接命中详情页
                            $cat_model = (class_exists('Todo_Model_Manager') && method_exists('Todo_Model_Manager', 'get_category_model'))
                                ? Todo_Model_Manager::get_category_model($cat->term_id)
                                : '';
                            $cat_slug = isset($cat->slug) ? (string) $cat->slug : '';
                            if ($cat_model === 'single' || $cat_slug === 'single') {
                                continue;
                            }

                            $cat_url = '';
                            if (function_exists('todo_get_category_link'))
                                $cat_url = todo_get_category_link($cat->term_id);
                            elseif (function_exists('get_category_link'))
                                $cat_url = get_category_link($cat->term_id);

                            if ($cat_url) {
                                $url = function_exists('get_language_url') ? get_language_url($lang, $cat_url) : $cat_url;
                                foreach ($site_ids as $sid) {
                                    $urls_by_lang[$lang][] = array(
                                        'url' => $url,
                                        'type' => 'category',
                                        'id' => $cat->term_id,
                                        'site_id' => $sid
                                    );
                                    $stats_by_lang[$lang]['categories']++;
                                }
                            }
                        }
                    }
                }
            }

            // 所有文章 (所有分类内的文章)
            if (in_array('all_posts', $generate_options)) {
                // 确保使用全局数据库对象，即使 $tododb 在此作用域未定义也能尝试获取
                if (!isset($tododb) && class_exists('CMS_DB')) {
                    $tododb = CMS_DB::get_instance();
                }

                if (isset($tododb)) {
                    // 获取所有内容性模型的文章，包括 article, product, download 等
                    $post_types = array('post', 'article', 'product', 'download', 'images', 'media', 'special');
                    $post_types_in = "'" . implode("','", $post_types) . "'";
                    $posts = $tododb->get_results("SELECT ID FROM " . $tododb->prefix . "posts WHERE post_status = 'publish' AND post_type IN ($post_types_in) LIMIT $max_posts");
                    if ($posts) {
                        foreach ($posts as $post) {
                            $post_url = '';
                            if (function_exists('todo_get_permalink'))
                                $post_url = todo_get_permalink($post->ID);
                            elseif (function_exists('get_permalink'))
                                $post_url = get_permalink($post->ID);

                            if ($post_url) {
                                $url = function_exists('get_language_url') ? get_language_url($lang, $post_url) : $post_url;
                                $urls_by_lang[$lang][] = array(
                                    'url' => $url,
                                    'type' => 'single',
                                    'id' => $post->ID
                                );
                                $stats_by_lang[$lang]['all_posts']++;
                            }
                        }
                    }
                }
            }

            // 去重
            $temp_keys = array();
            $unique_items = array();
            foreach ($urls_by_lang[$lang] as $item) {
                if (is_array($item) && isset($item['url'])) {
                    $key = $item['url']
                        . '|type=' . (isset($item['type']) ? (string) $item['type'] : '')
                        . '|id=' . (isset($item['id']) ? (string) $item['id'] : '')
                        . '|site_id=' . (isset($item['site_id']) ? (string) $item['site_id'] : '');
                    if (!in_array($key, $temp_keys, true)) {
                        $temp_keys[] = $key;
                        $unique_items[] = $item;
                    }
                }
            }
            $urls_by_lang[$lang] = $unique_items;
            $total_count += count($urls_by_lang[$lang]);
        }

        // 处理自定义URL：为每种语言生成自定义URL的缓存
        if (!empty($custom_urls_raw)) {
            $custom_urls = explode("\n", $custom_urls_raw);
            $clean_custom_urls = array();
            foreach ($custom_urls as $url) {
                $url = trim($url);
                if (!empty($url)) {
                    $clean_custom_urls[] = $url;
                }
            }

            if (!empty($clean_custom_urls)) {
                // 为每种语言添加自定义URL
                foreach ($languages as $lang) {
                    if (!isset($urls_by_lang[$lang])) {
                        $urls_by_lang[$lang] = array();
                    }
                    if (!isset($stats_by_lang[$lang])) {
                        $stats_by_lang[$lang] = array('homepage' => 0, 'categories' => 0, 'all_posts' => 0, 'custom' => 0);
                    }
                    // 为每个自定义URL添加语言前缀（如果URL中没有语言信息）
                    foreach ($clean_custom_urls as $url) {
                        // 如果URL已经包含语言信息，直接使用；否则添加语言前缀
                        $lang_url = $url;
                        if (function_exists('get_language_url')) {
                            $lang_url = get_language_url($lang, $url);
                        }
                        $urls_by_lang[$lang][] = array(
                            'url' => $lang_url,
                            'type' => 'custom'
                        );
                        $stats_by_lang[$lang]['custom']++;
                    }
                }
                $total_count += count($clean_custom_urls) * count($languages);
            }
        }

        $clearable_types = array('home', 'category', 'single');
        $cleared_targets = 0;
        foreach ($urls_by_lang as $lang => $items) {
            foreach ($items as $item) {
                $item_type = isset($item['type']) ? (string) $item['type'] : '';
                if (!in_array($item_type, $clearable_types, true)) {
                    continue;
                }
                $item_url = isset($item['url']) ? (string) $item['url'] : '';
                $item_id = isset($item['id']) ? intval($item['id']) : 0;
                $item_site_id = isset($item['site_id']) ? intval($item['site_id']) : 0;
                if ($item_url === '') {
                    continue;
                }
                if (Todo_Unified_Cache_Manager::clear_page_cache_entry($item_url, $item_type, $item_id, $lang, $item_site_id)) {
                    $cleared_targets++;
                }
            }
        }

        while (ob_get_level())
            ob_end_clean();
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(array(
            'success' => true,
            'data' => array(
                'urls' => $urls_by_lang,
                'stats' => $stats_by_lang,
                'total' => $total_count,
                'debug' => array(
                    'options' => $generate_options,
                    'languages' => $languages,
                    'home_url' => $get_base_url()
                )
            )
        ));
        exit;
    }

    // 处理单个URL缓存生成请求 (AJAX)
    if ($_POST['action'] === 'process_cache_url') {
        // 确保整个预热处理流程中主题过滤器按语言映射解析
        $GLOBALS['todo_doing_cache_warmup'] = true;
        // 全量清缓存后的首次渲染/回环预热可能较慢，放宽时限，
        // 避免超过 max_execution_time 触发 Fatal 导致浏览器收到空响应（ERR_EMPTY_RESPONSE）
        @set_time_limit(120);
        if (!Todo_Security_Manager::verify_request_csrf('cache_warmup')) {
            while (ob_get_level())
                ob_end_clean();
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode(array('success' => false, 'data' => '安全验证失败'));
            exit;
        }

        $url = isset($_POST['url']) ? $_POST['url'] : '';
        $lang = isset($_POST['lang']) ? $_POST['lang'] : '';
        $type = isset($_POST['type']) ? (string) $_POST['type'] : '';
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $site_id = isset($_POST['site_id']) ? intval($_POST['site_id']) : 0;
        $viewer_role = 'guest';

        if (empty($url)) {
            while (ob_get_level())
                ob_end_clean();
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode(array('success' => false, 'data' => 'URL为空'));
            exit;
        }

        // 尽早设置语言上下文并重置主题缓存，以确保 get_template_file_path 获取到的是当前语言映射的主题
        if (!empty($lang)) {
            $lang = todo_cache_warmup_set_locale_context($lang);
        }

        // 使用统一管理器生成页面缓存
        // 注意：generate_page_cache 会通过 parse_html_template -> render_template -> compile 自动生成编译缓存
        // 但是，为了确保首次访问体验良好，我们需要在生成页面缓存之前，预编译相关模板
        // 这样可以避免在首次访问时触发模板编译，提升性能
        
        // 预编译相关模板（在生成页面缓存之前）
        if (class_exists('CMS_Template_Compiler_V2') && function_exists('get_template_file_path')) {
            // 确保编译引擎已初始化
            if (method_exists('CMS_Template_Compiler_V2', 'init')) {
                CMS_Template_Compiler_V2::init();
            }
            
            // 根据页面类型，预编译相关模板
            $templates_to_compile = array();
            
            // 根据类型添加需要编译的模板
            switch ($type) {
                case 'home':
                    $templates_to_compile[] = get_template_file_path('index');
                    break;
                case 'category':
                    $templates_to_compile[] = get_template_file_path('category');
                    $templates_to_compile[] = get_template_file_path('archive');
                    break;
                case 'single':
                    // 单篇文章页可能需要多个模板（根据文章类型）
                    if ($id > 0 && function_exists('get_post')) {
                        $post = get_post($id);
                        if ($post && isset($post->post_type)) {
                            $templates_to_compile[] = get_template_file_path('view_' . $post->post_type);
                            $templates_to_compile[] = get_template_file_path('single_' . $post->post_type);
                        }
                    }
                    $templates_to_compile[] = get_template_file_path('single');
                    $templates_to_compile[] = get_template_file_path('post');
                    break;
                case 'custom':
                    // 自定义URL，尝试使用首页模板
                    $templates_to_compile[] = get_template_file_path('index');
                    break;
            }
            
            // 添加通用模板（通常会被包含在大多数页面中）
            // 这些模板需要在生成任何页面缓存之前编译，以提升首次访问性能
            $common_templates = array(
                'header', 'footer', 'sidebar', 'nav', 'menu',
                'post-item', 'post-list', 'pagination', 'comment', 'comment-form'
            );
            foreach ($common_templates as $tpl_name) {
                $tpl_path = get_template_file_path($tpl_name);
                if ($tpl_path && file_exists($tpl_path)) {
                    $templates_to_compile[] = $tpl_path;
                }
            }
            
            // 编译所有模板（去除重复和空值）
            $templates_to_compile = array_unique(array_filter($templates_to_compile));
            foreach ($templates_to_compile as $tpl_path) {
                if ($tpl_path && file_exists($tpl_path)) {
                    try {
                        // 编译模板（如果缓存无效，会重新编译）
                        $compiled_file = CMS_Template_Compiler_V2::compile($tpl_path);
                        if ($compiled_file && defined('TODO_DEBUG') && TODO_DEBUG) {
                            error_log("[Cache Warmup] Pre-compiled template: {$tpl_path} -> {$compiled_file}");
                        }
                    } catch (Exception $e) {
                        // 编译失败不影响整体流程，记录日志即可
                        error_log("[Cache Warmup] Failed to pre-compile template {$tpl_path}: " . $e->getMessage());
                    } catch (Throwable $e) {
                        // PHP 7+ 兼容性
                        error_log("[Cache Warmup] Failed to pre-compile template {$tpl_path}: " . $e->getMessage());
                    }
                }
            }
        }
        
        // 生成页面缓存（这会触发 parse_html_template，进而触发 render_template 和 compile）
        // 由于我们已经预编译了相关模板，这里主要是生成页面HTML缓存
        $result = Todo_Unified_Cache_Manager::generate_page_cache($url, $lang, $type, $id, $viewer_role, $site_id);
        $err_reason = $result ? '' : Todo_Unified_Cache_Manager::get_last_warmup_error();

        if (!$result && class_exists('Todo_Error_Handler')) {
            $msg_detail = !empty($err_reason) ? $err_reason : '缓存生成失败';
            Todo_Error_Handler::log(
                "缓存生成失败条目: URL={$url} (语言={$lang}, 类型={$type}, ID={$id}) - 原因: {$msg_detail}",
                Todo_Error_Handler::LEVEL_WARNING,
                array('url' => $url, 'lang' => $lang, 'type' => $type, 'id' => $id, 'error' => $msg_detail)
            );
        }

        while (ob_get_level())
            ob_end_clean();
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(array('success' => $result, 'data' => array(
            'url' => $url,
            'lang' => $lang,
            'error' => $err_reason
        )));
        exit;
    }

    // 记录预热失败汇总及具体条目日志 (AJAX)
    if ($_POST['action'] === 'log_cache_warmup_failures') {
        if (Todo_Security_Manager::verify_request_csrf('cache_warmup')) {
            $total_failed = isset($_POST['total_failed']) ? intval($_POST['total_failed']) : 0;
            $failed_items_raw = isset($_POST['failed_items']) ? $_POST['failed_items'] : '[]';
            $failed_items = json_decode($failed_items_raw, true);
            if (!is_array($failed_items)) {
                $failed_items = array();
            }

            if (class_exists('Todo_Error_Handler')) {
                Todo_Error_Handler::log(
                    "缓存生成完成，但有 {$total_failed} 项失败。",
                    Todo_Error_Handler::LEVEL_WARNING,
                    array(
                        'total_failed' => $total_failed,
                        'failed_items' => $failed_items
                    )
                );

                foreach ($failed_items as $index => $item) {
                    $item_url = isset($item['url']) ? $item['url'] : '';
                    $item_lang = isset($item['lang']) ? $item['lang'] : '';
                    $item_type = isset($item['type']) ? $item['type'] : '';
                    $item_err = isset($item['error']) ? $item['error'] : '未知错误';
                    $seq = $index + 1;
                    Todo_Error_Handler::log(
                        "缓存生成失败条目 [{$seq}/{$total_failed}]: URL={$item_url} (语言={$item_lang}, 类型={$item_type}) - 原因: {$item_err}",
                        Todo_Error_Handler::LEVEL_WARNING,
                        $item
                    );
                }
            }
        }
        while (ob_get_level())
            ob_end_clean();
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(array('success' => true));
        exit;
    }
}

// 处理操作
$message = '';
$message_type = 'info';

// PRG 提示处理
if (isset($_GET['notice'])) {
    $message_type = ($_GET['notice'] === 'success') ? 'success' : (($_GET['notice'] === 'warning') ? 'warning' : 'error');
    $message = isset($_GET['msg']) ? urldecode((string) $_GET['msg']) : '';
}

// 一键清理功能
if (isset($_GET['auto_clear']) && $_GET['auto_clear'] === '1' && empty($_POST)) {
    todo_guard_demo_mode_write('cache-management');
    if (!Todo_Security_Manager::verify_request_csrf('auto_cache_clear', 'GET')) {
        $message = '安全验证失败，请重新操作。';
        $message_type = 'error';
    } else {
        $all_cache_types = array_keys(Todo_Unified_Cache_Manager::get_cache_types());
        $results = Todo_Unified_Cache_Manager::clear_multiple_caches($all_cache_types);

        todo_log_info('一键清理缓存执行', array('results' => $results));

        $redir_type = 'error';
        if (isset($results['success']) && !empty($results['success'])) {
            $cache_config = Todo_Unified_Cache_Manager::get_cache_types();
            $cleared_names = array();
            foreach ($results['success'] as $type) {
                if (isset($cache_config[$type]['name'])) {
                    $cleared_names[] = $cache_config[$type]['name'];
                }
            }

            $message = '清理完成: ' . implode(', ', $cleared_names);
            $redir_type = 'success';

            if (isset($results['failed']) && !empty($results['failed'])) {
                $failed_names = array();
                foreach ($results['failed'] as $type) {
                    if (isset($cache_config[$type]['name'])) {
                        $failed_names[] = $cache_config[$type]['name'];
                    }
                }
                if (!empty($failed_names)) {
                    $message .= ' | 未找到: ' . implode(', ', $failed_names);
                    $redir_type = 'warning';
                }
            }
        } else {
            $message = '一键清理失败';
        }

        // PRG 重定向防止刷新重复提交
        $redirect_url = $page_url;
        $redirect_url = add_query_arg(array(
            'notice' => $redir_type,
            'msg' => urlencode($message)
        ), $redirect_url);

        header('Location: ' . $redirect_url);
        exit;
    }
}

// 处理缓存清理请求
if (isset($_POST['clear_cache'])) {
    todo_guard_demo_mode_write('cache-management');
    if (!Todo_Security_Manager::verify_request_csrf('cache_clear')) {
        todo_die('安全验证失败，请重新提交。');
    }

    $cache_types = isset($_POST['cache_types']) ? $_POST['cache_types'] : array();
    $results = array();
    $redir_type = 'error';

    if (empty($cache_types)) {
        $message = '请选择要清理的缓存类型';
        $message_type = 'error';
    } else {
        todo_log_info('手动清理缓存开始', array('types' => $cache_types));

        $results = Todo_Unified_Cache_Manager::clear_multiple_caches($cache_types);

        if (isset($results['success']) && !empty($results['success'])) {
            $cache_config = Todo_Unified_Cache_Manager::get_cache_types();
            $cleared_names = array();
            foreach ($results['success'] as $type) {
                if (isset($cache_config[$type]['name'])) {
                    $cleared_names[] = $cache_config[$type]['name'];
                }
            }

            $message = '成功清理: ' . implode(', ', $cleared_names);
            $redir_type = 'success';

            if (isset($results['failed']) && !empty($results['failed'])) {
                $failed_names = array();
                foreach ($results['failed'] as $type) {
                    if (isset($cache_config[$type]['name'])) {
                        $failed_names[] = $cache_config[$type]['name'];
                    }
                }
                if (!empty($failed_names)) {
                    $message .= ' | 清理失败: ' . implode(', ', $failed_names);
                    $redir_type = 'warning';
                }
            }
        } else {
            $message = '缓存清理失败';
            if (isset($results['errors']) && !empty($results['errors'])) {
                $message .= ': ' . implode(', ', $results['errors']);
            }
        }

        todo_log_info('手动清理缓存完成', array('message' => $message, 'results' => $results));
    }

    $redirect_url = add_query_arg(array(
        'notice' => $redir_type,
        'msg' => urlencode($message)
    ), $page_url);
    header('Location: ' . $redirect_url);
    exit;
}

// 处理自动清理配置保存
if (isset($_POST['save_auto_cleanup'])) {
    todo_guard_demo_mode_write('cache-management');
    if (!Todo_Security_Manager::verify_request_csrf('auto_cleanup_save')) {
        todo_die('安全验证失败，请重新提交。');
    }
    $config = array(
        'enabled' => isset($_POST['auto_cleanup_enabled']),
        'schedule' => sanitize_text_field($_POST['auto_cleanup_schedule']),
        'max_age' => intval($_POST['auto_cleanup_max_age']) * 86400, // 转换为秒
        'max_size' => intval($_POST['auto_cleanup_max_size']) * 1024 * 1024 // 转换为字节
    );

    // 保存配置到数据库
    update_option('todo_auto_cleanup_config', $config);

    $message = '自动清理配置已保存';
    $message_type = 'success';

    todo_log_info('自动清理配置更新', array('config' => $config));

    $redirect_url = add_query_arg(array(
        'notice' => 'success',
        'msg' => urlencode($message)
    ), $page_url);
    header('Location: ' . $redirect_url);
    exit;
}

// 处理清理日志请求
if (isset($_POST['clear_logs'])) {
    todo_guard_demo_mode_write('cache-management');
    if (!Todo_Security_Manager::verify_request_csrf('clear_logs')) {
        todo_die('安全验证失败，请重新提交。');
    }

    Todo_Logger::clearLogs();
    $message = '缓存操作日志已清空';
    $message_type = 'success';

    $redirect_url = add_query_arg(array(
        'notice' => 'success',
        'msg' => urlencode($message)
    ), $page_url);
    header('Location: ' . $redirect_url);
    exit;
}

// 处理文章翻译清理请求
if (isset($_POST['cleanup_post_translations'])) {
    todo_guard_demo_mode_write('cache-management');
    if (!Todo_Security_Manager::verify_request_csrf('post_translation_cleanup')) {
        todo_die('安全验证失败，请重新提交。');
    }

    require_once ABSPATH . 'core/services/post-translation.php';

    $deleted_post = null;
    $deleted_range = null;
    $post_expr = isset($_POST['cleanup_post_id']) ? trim((string) $_POST['cleanup_post_id']) : '';
    $limit = isset($_POST['cleanup_limit']) ? intval($_POST['cleanup_limit']) : 20000;
    $limit = $limit > 0 ? $limit : 20000;

    if ($post_expr !== '') {
        if (preg_match('/^\s*(\d+)\s*-\s*(\d+)\s*$/', $post_expr, $m)) {
            $deleted_range = todo_delete_post_translations_range((int) $m[1], (int) $m[2], $limit);
        } elseif (preg_match('/^\s*\d+(\s*,\s*\d+)*\s*$/', $post_expr)) {
            $ids = array_map('intval', preg_split('/\s*,\s*/', $post_expr));
            if (count($ids) > $limit) {
                $ids = array_slice($ids, 0, $limit);
            }
            $deleted_post = todo_delete_post_translations_bulk($ids);
        } elseif (preg_match('/^\s*(\d+)\s*$/', $post_expr, $m)) {
            $post_id = (int) $m[1];
            if ($post_id > 0) {
                $deleted_post = todo_delete_post_translations($post_id);
            }
        }
    }

    $stats = todo_cleanup_post_translations([
        'delete_orphans' => !empty($_POST['cleanup_orphans']),
        'delete_default_language' => !empty($_POST['cleanup_default_lang']),
        'limit' => $limit,
    ]);

    $parts = [];
    if ($deleted_range && isset($deleted_range['posts'])) {
        if (intval($deleted_range['posts']) > 0) {
            $msg = '已删除文章ID区间 ' . intval($deleted_range['start']) . '-' . intval($deleted_range['end']) . ' 的翻译记录：文章 ' . intval($deleted_range['posts']) . ' 篇，记录 ' . intval($deleted_range['deleted_rows']) . ' 条';
            if (!empty($deleted_range['truncated'])) {
                $msg .= '（已按限制截断）';
            }
            $parts[] = $msg;
        } else {
            $msg = '文章ID区间 ' . intval($deleted_range['start']) . '-' . intval($deleted_range['end']) . ' 未找到翻译记录';
            global $tododb;
            if (isset($tododb) && $tododb) {
                $table = $tododb->prefix . 'post_translations';
                $min_pid = $tododb->get_var("SELECT MIN(post_id) FROM {$table}");
                $max_pid = $tododb->get_var("SELECT MAX(post_id) FROM {$table}");
                if ($min_pid !== null && $max_pid !== null) {
                    $msg .= '（当前翻译表post_id范围：' . intval($min_pid) . '-' . intval($max_pid) . '）';
                }
            }
            $parts[] = $msg;
        }
    } elseif ($deleted_post && isset($deleted_post['deleted_rows'])) {
        if (isset($deleted_post['posts'])) {
            $parts[] = '已删除翻译记录：文章 ' . intval($deleted_post['posts']) . ' 篇，记录 ' . intval($deleted_post['deleted_rows']) . ' 条';
        } else {
            $parts[] = '已删除文章 ' . (int) ($post_id ?? 0) . ' 的翻译记录：' . intval($deleted_post['deleted_rows']) . ' 条';
        }
    }
    if (!empty($stats['deleted_orphans'])) {
        $parts[] = '已清理孤儿记录：' . intval($stats['deleted_orphans']) . ' 条';
    }
    if (!empty($stats['deleted_default_language'])) {
        $parts[] = '已清理默认语言(' . esc_html((string) $stats['default_language']) . ')记录：' . intval($stats['deleted_default_language']) . ' 条';
    }
    if (empty($parts)) {
        $parts[] = '未发现需要清理的翻译记录';
    }

    $message = implode('；', $parts);
    if (function_exists('todo_log_info')) {
        todo_log_info('文章翻译清理执行', array(
            'post_expr' => $post_expr,
            'limit' => $limit,
            'delete_orphans' => !empty($_POST['cleanup_orphans']),
            'delete_default_language' => !empty($_POST['cleanup_default_lang']),
            'deleted_range' => $deleted_range,
            'deleted_post' => $deleted_post,
            'stats' => $stats,
        ));
    }
    $redirect_url = add_query_arg(array(
        'notice' => 'success',
        'msg' => urlencode($message)
    ), $page_url);
    header('Location: ' . $redirect_url);
    exit;
}

$cache_types = Todo_Unified_Cache_Manager::get_cache_types();

// 获取自动清理配置
$auto_cleanup_config = get_option('todo_auto_cleanup_config', array(
    'enabled' => false,
    'schedule' => 'daily',
    'max_age' => 86400 * 7,
    'max_size' => 1024 * 1024 * 100
));

// 获取最近的缓存操作日志
$recent_logs = Todo_Logger::getLogContent(300);

// 生成一键清理URL
$auto_clear_url = add_query_arg(array(
    'page' => 'cache-management',
    'auto_clear' => '1',
    '_todononce' => Todo_Security_Manager::generate_csrf_token('auto_cache_clear')
), todo_admin_url($admin_entry_file));
?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title verse">“本来无一物，何处惹尘埃。”<span>——惠能《坛经》</span></h2>
        <div class="admin-page-actions">
            <button type="button" id="cache-warmup-btn" class="button button-secondary">生成缓存</button>
            <a href="<?php echo esc_url($auto_clear_url); ?>" class="button button-primary"
                id="auto-clear-cache-btn">一键清理缓存</a>
            <form method="post" action="<?php echo esc_url($page_url); ?>" id="cache-warmup-form" class="todo-request" style="display: none;">
                <?php echo Todo_Security_Manager::generate_csrf_field('cache_warmup'); ?>
                <input type="hidden" name="generate_cache" value="1">
            </form>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="notice notice-<?php echo $message_type; ?> is-dismissible">
            <p><?php echo esc_html($message); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <ul class="admin-tabs">
        <li>
            <a href="#cache-clear" class="active">手动清理缓存</a>
        </li>
        <li>
            <a href="#auto-cleanup">自动清理缓存</a>
        </li>
        <li>
            <a href="#post-translation-cleanup">翻译清理</a>
        </li>
        <li>
            <a href="#cache-logs">操作日志</a>
        </li>
    </ul>

    <!-- 手动清理缓存 -->
    <div class="tab-panel" id="cache-clear">
        <form method="post" action="<?php echo esc_url($page_url); ?>">
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">选择缓存项</h3>
                    <span class="description m-0">单个页面清除：地址后加参数 ?todocache=refresh 或 &todocache=refresh</span>
                </div>
                <div class="admin-card-content">
                    <?php echo Todo_Security_Manager::generate_csrf_field('cache_clear'); ?>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="select_all_cache"> 全选
                        </label>
                    </div>
                    <?php foreach ($cache_types as $type => $config): ?>
                        <label class="cache-type-label">
                            <input class="cache-type-checkbox" type="checkbox" name="cache_types[]" value="<?php echo esc_attr($type); ?>">
                            <?php echo esc_html($config['name']); ?>
                            <span class="description"><?php echo esc_html($config['description'] ?? ''); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="admin-form-submit">
                <button type="submit" name="clear_cache" class="button button-primary">清理选中的缓存</button>
            </div>
        </form>
    </div>

    <!-- 自动清理配置 -->
    <div class="tab-panel" id="auto-cleanup" style="display: none;">
        <form method="post" action="<?php echo esc_url($page_url); ?>">
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">自动清理配置</h3>
                </div>
                <div class="admin-card-content">
                    <?php echo Todo_Security_Manager::generate_csrf_field('auto_cleanup_save'); ?>

                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="auto_cleanup_enabled" value="1" <?php checked($auto_cleanup_config['enabled']); ?> />
                            启用自动清理
                        </label>
                        <p class="description">开启缓存自动清理功能，通常是 /content/cache/ 下的所有内容。</p>
                    </div>

                    <div class="form-group">
                        <label for="auto_cleanup_schedule">清理频率</label>
                        <select name="auto_cleanup_schedule" id="auto_cleanup_schedule" class="regular-text">
                            <option value="hourly" <?php selected($auto_cleanup_config['schedule'], 'hourly'); ?>>每小时
                            </option>
                            <option value="daily" <?php selected($auto_cleanup_config['schedule'], 'daily'); ?>>每天</option>
                            <option value="weekly" <?php selected($auto_cleanup_config['schedule'], 'weekly'); ?>>每周
                            </option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="auto_cleanup_max_age">最大缓存年龄</label>
                        <input type="number" name="auto_cleanup_max_age" id="auto_cleanup_max_age"
                            value="<?php echo esc_attr(intval($auto_cleanup_config['max_age'] / 86400)); ?>" min="1" max="30"
                            class="small-text" />
                        <span> 天</span>
                        <p class="description">超过此天数的缓存文件将被自动清理</p>
                    </div>

                    <div class="form-group">
                        <label for="auto_cleanup_max_size">最大缓存大小</label>
                        <input type="number" name="auto_cleanup_max_size" id="auto_cleanup_max_size"
                            value="<?php echo esc_attr(intval($auto_cleanup_config['max_size'] / (1024 * 1024))); ?>" min="10"
                            max="1000" class="small-text" />
                        <span> MB</span>
                        <p class="description">当缓存总大小超过此值时将清理最旧的文件</p>
                    </div>  
                </div>
            </div>
            <div class="admin-form-submit">
                <button type="submit" name="save_auto_cleanup" class="button button-primary">保存配置</button>
            </div>
        </form>
    </div>

    <!-- 文章翻译清理 -->
    <div class="tab-panel" id="post-translation-cleanup" style="display: none;">
        <form method="post" action="<?php echo esc_url($page_url); ?>">
            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-title-section">
                        <h3 class="admin-card-title">文章翻译清理</h3>
                        <div class="admin-card-subtitle" style="color: #d43838;">
                            专门清理所有posts的多语言“内容信息”，它不同于 工具 》语言 》词汇/翻译（不涉及内容信息），所以这里需谨慎清理。
                        </div>
                    </div>
                </div>
                <div class="admin-card-content">
                    <?php echo Todo_Security_Manager::generate_csrf_field('post_translation_cleanup'); ?>
                    <div class="form-group">
                        <label for="cleanup_post_id">按文章ID删除翻译（可选）</label>
                        <input type="text" name="cleanup_post_id" id="cleanup_post_id" value="" class="regular-text" placeholder="例如：624 或 100-300 或 1,2,3" />
                        <p class="description">支持单个ID、区间(100-300)、多ID(1,2,3)。会删除这些文章在 post_translations 表的所有语言版本翻译。</p>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="cleanup_orphans" value="1" />
                            清理孤儿记录（文章已删除但翻译仍存在）
                        </label>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="cleanup_default_lang" value="1" />
                            清理默认语言翻译记录（避免默认语言命中翻译表旧数据）
                        </label>
                    </div>
                    <div class="form-group">
                        <label for="cleanup_limit">本次最多清理条数</label>
                        <input type="number" name="cleanup_limit" id="cleanup_limit" value="20000" min="1" class="small-text" />
                    </div>
                </div>
            </div>
            <div class="admin-form-submit">
                <button type="submit" name="cleanup_post_translations" class="button button-primary">执行清理</button>
            </div>
        </form>
    </div>

    <!-- 缓存操作日志 -->
    <div class="tab-panel" id="cache-logs" style="display: none;">
        <div class="admin-card">
            <div class="admin-card-header no-border">
                <h3 class="admin-card-title">缓存操作日志</h3>
                <div class="admin-card-actions">
                    <form method="post" action="<?php echo esc_url($page_url); ?>" style="display: inline;">
                        <?php echo Todo_Security_Manager::generate_csrf_field('clear_logs'); ?>
                        <button type="submit" name="clear_logs" id="clear-logs-btn"
                            class="button button-secondary">清空日志</button>
                    </form>
                    <button type="button" id="refresh-logs" class="button button-secondary">刷新日志</button>
                </div>
            </div>
            <div class="admin-card-content">
                <textarea readonly class="logs-display" rows="22" cols="100"><?php
                if (!empty($recent_logs)) {
                    $logs_array = explode("\n", $recent_logs);
                    $logs_array = array_values(array_filter(array_map('trim', $logs_array), function ($line) {
                        return $line !== '';
                    }));
                    $collapsed = [];
                    $prev = null;
                    $cnt = 0;
                    foreach ($logs_array as $line) {
                        if ($prev === null) {
                            $prev = $line;
                            $cnt = 1;
                            continue;
                        }
                        if ($line === $prev) {
                            $cnt++;
                            continue;
                        }
                        $collapsed[] = $cnt > 1 ? ($prev . '（重复' . $cnt . '次）') : $prev;
                        $prev = $line;
                        $cnt = 1;
                    }
                    if ($prev !== null) {
                        $collapsed[] = $cnt > 1 ? ($prev . '（重复' . $cnt . '次）') : $prev;
                    }
                    $collapsed = array_slice($collapsed, -80);
                    echo esc_textarea(implode("\n", $collapsed));
                } else {
                    echo '暂无缓存操作日志';
                }
                ?></textarea>
            </div>
        </div>
    </div>
</div>

<style>
    .cache-type-label {
        display: block;
        margin: 0;
        padding: 10px 10px;
        border-top: 1px solid #eceef1;
    }

    .cache-type-label:hover {
        background: #f9fafd;
    }

    .logs-actions {
        margin-bottom: 10px;
    }

    .logs-actions form {
        margin-right: 10px;
    }

    .logs-display {
        width: 100%;
        font-family: Consolas, Monaco, monospace;
        font-size: 12px;
        background: #f9fafd;
        border: 1px solid #eceef1;
        padding: 10px;
        white-space: pre-wrap;
    }
</style>

<script type="text/javascript">
    jQuery(document).ready(function ($) {
        // 全选/取消全选功能
        $('#select_all_cache').change(function () {
            $('.cache-type-checkbox').prop('checked', this.checked);
        });

        // 监听单个复选框变化
        $('.cache-type-checkbox').change(function () {
            var total = $('.cache-type-checkbox').length;
            var checked = $('.cache-type-checkbox:checked').length;
            $('#select_all_cache').prop('checked', total === checked);
        });

        // 刷新日志功能
        $('#refresh-logs').click(function () {
            window.location.reload();
        });

        // 一键清理确认（只针对一键清理缓存按钮）
        $('#auto-clear-cache-btn').click(function (e) {
            e.preventDefault();
            var button = this;
            TodoModal.confirm({
                title: '清理缓存',
                message: '确定要清理所有缓存吗？',
                type: 'warning',
                onConfirm: function () {
                    if (button.form) {
                        button.form.submit();
                    } else {
                        TodoRequest.redirect(button.href || window.location.href);
                    }
                }
            });
        });

        // 清空日志确认
        $('#clear-logs-btn').click(function (e) {
            e.preventDefault();
            var button = this;
            TodoModal.confirm({
                title: '清空日志',
                message: '确定要清空所有缓存操作日志吗？',
                type: 'warning',
                onConfirm: function () {
                    // Create a hidden input to simulate the button click for form submission
                    var form = button.form;
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = button.name;
                    input.value = button.value;
                    form.appendChild(input);
                    form.submit();
                }
            });
        });

        // Tab切换功能
        $('.admin-tabs a').click(function (e) {
            e.preventDefault();
            var target = $(this).attr('href');

            // 更新tab状态
            $('.admin-tabs li a').removeClass('active');
            $(this).addClass('active');

            // 显示对应的卡片
            $('.tab-panel[id]').hide();
            $(target).show();
        });

        // 缓存预热设置弹窗
        var warmupModal = null;
        var warmupFormStorageKey = 'cache_warmup_settings';
        var warmupCustomUrlsKey = 'cache_warmup_custom_urls_history';

        // 保存设置到localStorage
        function saveWarmupSettings() {
            if (!warmupModal) return;

            var settings = {
                options: [],
                maxPosts: $(TodoDOM.get('max-posts')).val()
            };

            // 收集checkbox选项
            $(TodoDOM.get('cache-warmup-settings-form')).find('input[type="checkbox"]:checked').each(function () {
                settings.options.push($(this).val());
            });

            // 保存到localStorage
            try {
                localStorage.setItem(warmupFormStorageKey, JSON.stringify(settings));
            } catch (e) {
                console.warn('无法保存设置到localStorage:', e);
            }
        }

        // 从localStorage恢复设置
        function restoreWarmupSettings() {
            if (!warmupModal) return;

            try {
                var saved = localStorage.getItem(warmupFormStorageKey);
                if (saved) {
                    var settings = JSON.parse(saved);

                    // 恢复checkbox选项
                    if (settings.options && Array.isArray(settings.options)) {
                        settings.options.forEach(function (option) {
                            $(TodoDOM.get('cache-warmup-settings-form')).find('input[type="checkbox"][value="' + option + '"]').prop('checked', true);
                        });
                    }

                    // 恢复最大文章数
                    if (settings.maxPosts) {
                        $(TodoDOM.get('max-posts')).val(settings.maxPosts);
                    }
                }
            } catch (e) {
                console.warn('无法从localStorage恢复设置:', e);
            }
        }

        var isGenerating = false;

        // 打开设置弹窗
        $('#cache-warmup-btn').click(function (e) {
            e.preventDefault();
            if (typeof TodoModal === 'undefined') return;

            var modalContent = '<form id="cache-warmup-settings-form">' +
                '<p class="m-b-10"><strong style="margin-bottom: 8px;">选择要生成缓存的页面</strong><small class="description"> (请先在 设置>网站信息>阅读设置 中开启静态缓存/微缓存)</small></p>' +
                '<label><input type="checkbox" name="warmup_options[]" value="homepage" id="warmup-homepage"> 首页</label>' +

                '<label><input type="checkbox" name="warmup_options[]" value="categories" id="warmup-categories"> 所有分类页<small class="description m-0"> (每个分类列表第一页)</small></label>' +
                '<label class="flex items-center gap-2"><input type="checkbox" name="warmup_options[]" value="all_posts" id="warmup-all-posts"> 所有文章详情<small class="description m-0" style="margin-right: auto !important;"> (已发布文章详情页)</small>' +
                '小于<input type="text" value="5000" name="max_posts" id="max-posts" style="width: 80px;min-width: auto;">篇' +
                '</label>' +
                '<button type="button" class="btn button-secondary m-r-10" id="history-urls-btn">URL</button><strong style="display: inline-block;margin-bottom: 8px;">自定义URL缓存（每行一个）</strong>' +
                '<textarea name="custom_urls" id="warmup-custom-urls" rows="3" class="large-text code" style="width: 100%;" placeholder="https://example.com/custom-page"></textarea>' +
                '</form>' +
                '<div id="warmup-progress-area" style="display:none;">' +
                '   <div id="warmup-status-text" style="margin-top: 10px; font-weight: 500;">准备中...</div>' +
                '   <div id="warmup-bars-container"></div>' +
                '</div>';

            var warmupModal = new TodoModal({
                size: 'md',
                closable: true,
                backdrop: 'static', // 禁止点击背景关闭
                keyboard: false     // 禁止ESC关闭
            });

            warmupModal.setTitle('生成缓存')
                .setContent(modalContent)
                .setFooter([
                    {
                        text: '取消',
                        class: 'button button-secondary',
                        id: 'btn-cancel-warmup',
                        handler: function () {
                            if (isGenerating) {
                                TodoModal.confirm({
                                    title: '确认停止',
                                    message: '正在生成缓存，确定要停止吗？',
                                    onConfirm: function () {
                                        isGenerating = false;
                                        if (warmupModal) warmupModal.close();
                                    }
                                });
                                return false;
                            }
                            return true;
                        }
                    },
                    {
                        text: '立即缓存',
                        class: 'button button-primary',
                        id: 'btn-start-warmup',
                        handler: function () {
                            startWarmupProcess();
                            return false; // 不关闭弹窗
                        }
                    },
                    {
                        text: '关闭',
                        class: 'button button-primary',
                        id: 'btn-close-warmup',
                        style: 'display: none;',
                        handler: function () {
                            return true;
                        }
                    }
                ])
                .open();

            setTimeout(function () { restoreWarmupSettings(); }, 100);
            setTimeout(function () {
                var $form = $(TodoDOM.get('cache-warmup-settings-form'));
                if ($form.length) {
                    $form.find('input, textarea').on('change', function () {
                        saveWarmupSettings();
                    });
                }
                // 在弹窗渲染后直接绑定 #history-urls-btn 按钮点击事件
                $(TodoDOM.get('cache-warmup-settings-form')).find('#history-urls-btn').off('click').on('click', function (e) {
                    e.preventDefault();
                    try {
                        var historyUrls = localStorage.getItem(warmupCustomUrlsKey);
                        if (historyUrls && historyUrls.trim()) {
                            $(TodoDOM.get('warmup-custom-urls')).val(historyUrls);
                            if (typeof TodoModal !== 'undefined' && typeof TodoModal.toast === 'function') {
                                TodoModal.toast('已成功回填历史自定义URL');
                            }
                        } else {
                            TodoModal.warning('暂无历史自定义URL');
                        }
                    } catch (err) {
                        console.warn('获取历史URL失败:', err);
                    }
                });
            }, 100);
        });

        function startWarmupProcess() {
            if (isGenerating) return;

            var requestUrl = buildWarmupRequestUrl();

            // 收集数据
            var selectedOptions = [];
            var $form = $(TodoDOM.get('cache-warmup-settings-form'));
            $form.find('input[type="checkbox"]:checked').each(function () {
                selectedOptions.push($(this).val());
            });
            var customUrls = $(TodoDOM.get('warmup-custom-urls')).val();
            var maxPosts = $(TodoDOM.get('max-posts')).val();

            if (selectedOptions.length === 0 && (!customUrls || customUrls.trim() === '')) {
                TodoModal.warning('请至少选择一个选项或输入自定义URL');
                return;
            }

            // 点击“立即缓存”且自定义URL不为空时，存入浏览器缓存（队列历史记录）
            if (customUrls && customUrls.trim() !== '') {
                try {
                    localStorage.setItem(warmupCustomUrlsKey, customUrls.trim());
                } catch (e) {
                    console.warn('无法保存自定义URL历史:', e);
                }
            }

            // UI 切换
            isGenerating = true;
            $(TodoDOM.get('warmup-progress-area')).show();
            $(TodoDOM.get('btn-start-warmup')).hide();
            $(TodoDOM.get('btn-cancel-warmup')).text('停止');

            $(TodoDOM.get('warmup-status-text')).text('正在计算需要缓存的URL...');
            $(TodoDOM.get('warmup-bars-container')).empty();

            // Step 1: Calculate URLs
            TodoRequest.ajax({
                url: requestUrl,
                data: {
                    action: 'calculate_cache_urls',
                    warmup_options: selectedOptions,
                    custom_urls: customUrls,
                    max_posts: maxPosts,
                    _todononce: '<?php echo Todo_Security_Manager::generate_csrf_token("cache_warmup"); ?>'
                },
                onSuccess: function (response) {
                    if (response.success) {
                        processUrls(response.data.urls, response.data.total, response.data.stats);
                    } else {
                        handleError('计算URL失败: ' + (response.data || '未知错误'));
                    }
                },
                onError: function (xhr, status, error) {
                    handleError('请求失败: ' + (error && error.message || '未知错误'));
                }
            });
        }

        function processUrls(urlsByLang, total, statsByLang) {
            if (total === 0) {
                finishWarmup('没有找到需要生成的页面。');
                return;
            }

            $(TodoDOM.get('warmup-status-text')).html('正在生成缓存<i class="myload">.</i>');

            // 初始化进度条
            var progressData = {};
            var queue = [];

            $.each(urlsByLang, function (lang, urls) {
                if (urls.length > 0) {
                    // 构建条目详情描述
                    var details = [];
                    if (statsByLang && statsByLang[lang]) {
                        var s = statsByLang[lang];
                        if (s.homepage > 0) details.push('首页' + s.homepage + '条');

                        if (s.categories > 0) details.push('分类页' + s.categories + '条');
                        if (s.all_posts > 0) details.push('文章详情页' + s.all_posts + '条');
                        if (s.custom > 0) details.push('自定义' + s.custom + '条');
                    }
                    var detailsText = details.length > 0 ? ' (' + details.join('，') + ')' : '';

                    // 实际任务总数与当前队列一致（当前只生成 guest 缓存）
                    var actualTaskCount = urls.length;

                    progressData[lang] = {
                        total: actualTaskCount,
                        processed: 0,
                        success: 0,
                        failed: 0,
                        detailsText: detailsText
                    };

                    // 创建进度条HTML
                    var barHtml =
                        '<div class="progress-group" id="progress-' + lang.replace(/[^a-zA-Z0-9]/g, '_') + '" style="margin: 3px 0;">' +
                        '   <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:3px;">' +
                        '       <span>' + lang + '<small class="description">' + detailsText + '</small></span>' +
                        '       <span class="progress-text">0/' + actualTaskCount + '</span>' +
                        '   </div>' +
                        '   <div style="background:#f0f0f1; height:10px; border-radius:5px; overflow:hidden;">' +
                        '       <div class="progress-bar progresscolor" style="height:100%; width:0%; transition:width 0.2s;"></div>' +
                        '   </div>' +
                        '</div>';
                    $(TodoDOM.get('warmup-bars-container')).append(barHtml);

                    // 添加到队列
                    $.each(urls, function (i, item) {
                        // 兼容字符串格式和对象格式的URL
                        var urlObj = typeof item === 'string' ? { url: item, type: '', id: 0 } : item;
                        var itemType = urlObj.type || '';

                        queue.push({
                            lang: lang,
                            url: urlObj.url || item,
                            type: itemType,
                            id: urlObj.id || 0,
                            site_id: urlObj.site_id || 0,
                            viewer_role: 'guest'
                        });
                    });
                }
            });

            // 预热过程中服务端会临时切换到游客上下文，串行执行可避免并发请求互相污染后台登录态。
            var concurrency = 1;
            var active = 0;
            var failedItems = [];

            function next() {
                if (!isGenerating) return; // 停止
                if (queue.length === 0 && active === 0) {
                    var totalFailed = 0;
                    $.each(progressData, function (_, item) {
                        totalFailed += item.failed || 0;
                    });

                    if (totalFailed > 0 && failedItems.length > 0) {
                        logWarmupFailures(totalFailed, failedItems, function () {
                            finishWarmup('缓存生成完成，但有 ' + totalFailed + ' 项失败。<span style="font-size: 12px;font-weight: normal;font-style: italic;color: #000;display: block;margin-bottom: 10px;">失败可能来自未启用语言版本的文章</span>');
                        });
                    } else {
                        finishWarmup(totalFailed > 0 ? ('缓存生成完成，但有 ' + totalFailed + ' 项失败。<span style="font-size: 12px;font-weight: normal;font-style: italic;color: #000;display: block;margin-bottom: 10px;">失败可能来自未启用语言版本的文章</span>') : '缓存生成完成！');
                    }
                    return;
                }

                while (active < concurrency && queue.length > 0) {
                    var item = queue.shift();
                    active++;
                    processSingleUrl(item, function () {
                        active--;
                        // Use setTimeout to prevent stack overflow and allow UI updates
                        setTimeout(next, 0);
                    });
                }
            }

            function logWarmupFailures(totalFailed, items, callback) {
                var requestUrl = buildWarmupRequestUrl();
                TodoRequest.ajax({
                    url: requestUrl,
                    data: {
                        action: 'log_cache_warmup_failures',
                        total_failed: totalFailed,
                        failed_items: JSON.stringify(items),
                        _todononce: '<?php echo Todo_Security_Manager::generate_csrf_token("cache_warmup"); ?>'
                    },
                    onComplete: function () {
                        if (typeof callback === 'function') {
                            callback();
                        }
                    }
                });
            }

            function processSingleUrl(item, callback) {
                var requestUrl = buildWarmupRequestUrl();
                TodoRequest.ajax({
                    url: requestUrl,
                    data: {
                        action: 'process_cache_url',
                        url: item.url,
                        lang: item.lang,
                        type: item.type,
                        id: item.id,
                        site_id: item.site_id || 0,
                        viewer_role: item.viewer_role || 'guest',
                        _todononce: '<?php echo Todo_Security_Manager::generate_csrf_token("cache_warmup"); ?>'
                    },
                    onSuccess: function (res) {
                        if (!res.success) {
                            var errStr = res.error || (res.data && res.data.error) || '未知错误';
                            console.warn('[Warmup] 预热失败: ' + item.url + ' - ' + errStr);
                            failedItems.push({
                                url: item.url,
                                lang: item.lang,
                                type: item.type,
                                id: item.id,
                                error: errStr
                            });
                        }
                        updateProgress(item.lang, res.success);
                    },
                    onError: function (xhr, status, error) {
                        var errStr = error || status || '网络/请求错误';
                        failedItems.push({
                            url: item.url,
                            lang: item.lang,
                            type: item.type,
                            id: item.id,
                            error: errStr
                        });
                        updateProgress(item.lang, false);
                    },
                    onComplete: function () {
                        callback();
                    }
                });
            }

            function updateProgress(lang, success) {
                var data = progressData[lang];
                if (!data) return;
                data.processed++;
                if (success) data.success++; else data.failed++;

                var percent = (data.processed / data.total) * 100;
                var langId = lang.replace(/[^a-zA-Z0-9]/g, '_');
                var $el = $(TodoDOM.get('progress-' + langId));
                $el.find('.progress-bar').css('width', percent + '%');
                $el.find('.progress-text').text(data.processed + '/' + data.total);
                // 更新标题显示，保留详情信息
                $el.find('span:first').html(lang + '<small class="description">' + data.detailsText + '</small>');
            }

            next();
        }

        function buildWarmupRequestUrl() {
            var requestUrl = <?php echo json_encode($page_url); ?>;
            return requestUrl;
        }

        function handleError(msg) {
            $(TodoDOM.get('warmup-status-text')).html('<span style="color: #d43838;">' + msg + '</span>');
            isGenerating = false;
            $(TodoDOM.get('btn-cancel-warmup')).hide();
            $(TodoDOM.get('btn-close-warmup')).show();
        }

        function finishWarmup(msg) {
            $(TodoDOM.get('warmup-status-text')).html('<span>' + msg + '</span>');
            isGenerating = false;
            saveWarmupSettings();
            $(TodoDOM.get('btn-cancel-warmup')).hide();
            $(TodoDOM.get('btn-close-warmup')).show();
        }

    });
</script>

<?php
/**
 * 格式化字节大小
 */
if (!function_exists('todo_format_bytes')) {
    function todo_format_bytes($size, $precision = 2)
    {
        if ($size === 0)
            return '0 B';

        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $base = log($size, 1024);
        $unit = $units[(int) floor($base)];
        $formatted_size = round(pow(1024, $base - floor($base)), $precision);

        return $formatted_size . ' ' . $unit;
    }
}

/**
 * 添加home_url函数（如果不存在）
 */
if (!function_exists('home_url')) {
    function home_url($path = '')
    {
        // 优先使用核心函数
        if (function_exists('todo_home_url')) {
            return todo_home_url($path);
        }

        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        $script_dir = isset($_SERVER['SCRIPT_NAME']) ? dirname($_SERVER['SCRIPT_NAME']) : '';

        $base_url = $protocol . '://' . $host . rtrim($script_dir, '/\\');
        return $base_url . '/' . ltrim($path, '/');
    }
}

/**
 * 添加add_query_arg函数（如果不存在）
 */
if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $url)
    {
        $parsed_url = parse_url($url);
        $query = isset($parsed_url['query']) ? $parsed_url['query'] : '';
        parse_str($query, $query_args);

        $query_args = array_merge($query_args, $args);
        $new_query = http_build_query($query_args);

        $base_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
        if (isset($parsed_url['port'])) {
            $base_url .= ':' . $parsed_url['port'];
        }
        $base_url .= $parsed_url['path'] ?? '';

        return $base_url . ($new_query ? '?' . $new_query : '');
    }
}

/**
 * 添加checked函数（如果不存在）
 */
if (!function_exists('checked')) {
    function checked($checked, $current = true, $echo = true)
    {
        $result = '';
        if ((string) $checked === (string) $current) {
            $result = ' checked="checked"';
        }

        if ($echo) {
            echo $result;
        }
        return $result;
    }
}

/**
 * 添加selected函数（如果不存在）
 */
if (!function_exists('selected')) {
    function selected($selected, $current = true, $echo = true)
    {
        $result = '';
        if ((string) $selected === (string) $current) {
            $result = ' selected="selected"';
        }

        if ($echo) {
            echo $result;
        }
        return $result;
    }
}
?>
