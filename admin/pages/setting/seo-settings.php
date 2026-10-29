<?php
// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
/**
 * SEO设置页面
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 确保兼容性函数可用
if (!function_exists('str_starts_with')) {
    if (!defined('ABSPATH')) {
        define('ABSPATH', dirname(__FILE__) . '/../../../');
    }
    require_once ABSPATH . 'core/functions/missing-core-functions.php';
}

// 检查用户权限
if (!current_user_can('manage_seo') && !current_user_can('manage_options')) {
    todo_die('您没有足够的权限访问此页面。');
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_seo_settings') {
    // 验证CSRF
    if (!Todo_Security_Manager::verify_request_csrf('update_seo_settings', '_todononce')) {
        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice('安全验证失败，请重试。', 'error');
        }
        todo_redirect(todo_admin_url('index.php?page=seo-settings'));
    }
    
    // 获取表单数据
    $title_separator = isset($_POST['title_separator']) ? trim($_POST['title_separator']) : '';
    $title_separator = html_entity_decode($title_separator, ENT_QUOTES, 'UTF-8');
    $home_title_format = isset($_POST['home_title_format']) ? trim($_POST['home_title_format']) : '';
    $post_title_format = isset($_POST['post_title_format']) ? trim($_POST['post_title_format']) : '';
    $page_title_format = isset($_POST['page_title_format']) ? trim($_POST['page_title_format']) : '';
    $category_title_format = isset($_POST['category_title_format']) ? trim($_POST['category_title_format']) : '';
    $tag_title_format = isset($_POST['tag_title_format']) ? trim($_POST['tag_title_format']) : '';
    $archive_title_format = isset($_POST['archive_title_format']) ? trim($_POST['archive_title_format']) : '';
    $author_title_format = isset($_POST['author_title_format']) ? trim($_POST['author_title_format']) : '';
    $search_title_format = isset($_POST['search_title_format']) ? trim($_POST['search_title_format']) : '';
    $error_404_title_format = isset($_POST['error_404_title_format']) ? trim($_POST['error_404_title_format']) : '';

    $home_meta_description = isset($_POST['home_meta_description']) ? trim($_POST['home_meta_description']) : '';
    $post_meta_description = isset($_POST['post_meta_description']) ? trim($_POST['post_meta_description']) : '';
    $page_meta_description = isset($_POST['page_meta_description']) ? trim($_POST['page_meta_description']) : '';
    $category_meta_description = isset($_POST['category_meta_description']) ? trim($_POST['category_meta_description']) : '';
    $tag_meta_description = isset($_POST['tag_meta_description']) ? trim($_POST['tag_meta_description']) : '';

    $noindex_site = isset($_POST['noindex_site']) ? 1 : 0;
    $noindex_archives = isset($_POST['noindex_archives']) ? 1 : 0;
    $noindex_categories = isset($_POST['noindex_categories']) ? 1 : 0;
    $noindex_tags = isset($_POST['noindex_tags']) ? 1 : 0;
    $noindex_author_archives = isset($_POST['noindex_author_archives']) ? 1 : 0;
    $noindex_search_results = isset($_POST['noindex_search_results']) ? 1 : 0;

    $sitemap_enable_html = isset($_POST['sitemap_enable_html']) ? 1 : 0;
    $sitemap_exclude_ids = isset($_POST['sitemap_exclude_ids']) ? trim($_POST['sitemap_exclude_ids']) : '';
    $seo_entity_knows_about = isset($_POST['seo_entity_knows_about']) ? trim($_POST['seo_entity_knows_about']) : '';
    // 文本域换行符替换为逗号处理
    $seo_entity_same_as = isset($_POST['seo_entity_same_as']) ? implode(',', array_filter(array_map('trim', explode("\n", $_POST['seo_entity_same_as'])))) : '';

    $baidu_push = isset($_POST['baidu_push']) ? 1 : 0;
    $baidu_push_token = isset($_POST['baidu_push_token']) ? trim($_POST['baidu_push_token']) : '';

    $indexnow_push = isset($_POST['indexnow_push']) ? 1 : 0;
    $indexnow_key = isset($_POST['indexnow_key']) ? trim($_POST['indexnow_key']) : '';
    if ($indexnow_push && empty($indexnow_key)) {
        $indexnow_key = md5(todo_get_site_url() . time());
    }

    $canonical_url = isset($_POST['canonical_url']) ? 1 : 0;
    $robots_txt = isset($_POST['robots_txt']) ? trim($_POST['robots_txt']) : '';

    // 验证数据
    $errors = array();

    // 如果没有错误，更新设置
    if (empty($errors)) {
        // 更新SEO设置
        update_option('seo_title_separator', $title_separator);
        update_option('seo_home_title_format', $home_title_format);
        update_option('seo_post_title_format', $post_title_format);
        update_option('seo_page_title_format', $page_title_format);
        update_option('seo_category_title_format', $category_title_format);
        update_option('seo_tag_title_format', $tag_title_format);
        update_option('seo_archive_title_format', $archive_title_format);
        update_option('seo_author_title_format', $author_title_format);
        update_option('seo_search_title_format', $search_title_format);
        update_option('seo_404_title_format', $error_404_title_format);

        update_option('seo_home_meta_description', $home_meta_description);
        update_option('seo_post_meta_description', $post_meta_description);
        update_option('seo_page_meta_description', $page_meta_description);
        update_option('seo_category_meta_description', $category_meta_description);
        update_option('seo_tag_meta_description', $tag_meta_description);

        update_option('seo_noindex_site', $noindex_site);
        update_option('seo_noindex_archives', $noindex_archives);
        update_option('seo_noindex_categories', $noindex_categories);
        update_option('seo_noindex_tags', $noindex_tags);
        update_option('seo_noindex_author_archives', $noindex_author_archives);
        update_option('seo_noindex_search_results', $noindex_search_results);

        update_option('seo_enable_sitemap_html', $sitemap_enable_html);
        update_option('seo_sitemap_exclude_ids', $sitemap_exclude_ids);
        update_option('seo_entity_knows_about', $seo_entity_knows_about);
        update_option('seo_entity_same_as', $seo_entity_same_as);

        update_option('seo_baidu_push', $baidu_push);
        update_option('seo_baidu_push_token', $baidu_push_token);

        update_option('seo_indexnow_push', $indexnow_push);
        update_option('seo_indexnow_key', $indexnow_key);
        
        // 自动生成 IndexNow 的验证文件
        if ($indexnow_push && $indexnow_key) {
            @file_put_contents(ABSPATH . $indexnow_key . '.txt', $indexnow_key);
        }

        update_option('seo_canonical_url', $canonical_url);
        update_option('seo_robots_txt', $robots_txt);
        
        // Regenerate all SEO files (sitemaps, robots.txt, etc.)
        if (!class_exists('Todo_Sitemap_Service')) {
            if (class_exists('Todo_Function_Loader')) {
                Todo_Function_Loader::load_function('sitemap-service');
            } else {
                $service_file = ABSPATH . 'core/services/sitemap-service.php';
                if (file_exists($service_file)) {
                    require_once $service_file;
                }
            }
        }
        if (class_exists('Todo_Sitemap_Service')) {
            Todo_Sitemap_Service::generate_all();
        }

        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice('SEO设置已保存。', 'success');
        }
        todo_redirect(todo_admin_url('index.php?page=seo-settings'));
    }
} else {
    // 获取当前设置
    $title_separator = get_option('seo_title_separator', '-');
    $title_separator = is_string($title_separator) ? html_entity_decode($title_separator, ENT_QUOTES, 'UTF-8') : $title_separator;
    $home_title_format = get_option('seo_home_title_format', '%site_title% %separator% %site_description%');
    $post_title_format = get_option('seo_post_title_format', '%post_title% %separator% %site_title%');
    $page_title_format = get_option('seo_page_title_format', '%page_title% %separator% %site_title%');
    $category_title_format = get_option('seo_category_title_format', '%category_name% %separator% %site_title%');
    $tag_title_format = get_option('seo_tag_title_format', '%tag_name% %separator% %site_title%');
    $archive_title_format = get_option('seo_archive_title_format', '%date% %separator% %site_title%');
    $author_title_format = get_option('seo_author_title_format', '%author_name% %separator% %site_title%');
    $search_title_format = get_option('seo_search_title_format', '搜索: %search_term% %separator% %site_title%');
    $error_404_title_format = get_option('seo_404_title_format', '页面未找到 %separator% %site_title%');

    $home_meta_description = get_option('seo_home_meta_description', '');
    $post_meta_description = get_option('seo_post_meta_description', '%post_excerpt%');
    $page_meta_description = get_option('seo_page_meta_description', '%page_excerpt%');
    $category_meta_description = get_option('seo_category_meta_description', '%category_description%');
    $tag_meta_description = get_option('seo_tag_meta_description', '%tag_description%');

    $noindex_site = get_option('seo_noindex_site', 0);
    $noindex_archives = get_option('seo_noindex_archives', 0);
    $noindex_categories = get_option('seo_noindex_categories', 0);
    $noindex_tags = get_option('seo_noindex_tags', 0);
    $noindex_author_archives = get_option('seo_noindex_author_archives', 0);
    $noindex_search_results = get_option('seo_noindex_search_results', 1);

    $sitemap_enable_html = get_option('seo_enable_sitemap_html', 0);
    $sitemap_exclude_ids = get_option('seo_sitemap_exclude_ids', '');
    $seo_entity_knows_about = get_option('seo_entity_knows_about', '');
    $seo_entity_same_as_raw = get_option('seo_entity_same_as', '');
    $seo_entity_same_as = str_replace(',', "\n", $seo_entity_same_as_raw);

    $baidu_push = get_option('seo_baidu_push', 0);
    $baidu_push_token = get_option('seo_baidu_push_token', '');

    $indexnow_push = get_option('seo_indexnow_push', 0);
    $indexnow_key = get_option('seo_indexnow_key', '');

    $canonical_url = get_option('seo_canonical_url', 1);
    $robots_txt = get_option('seo_robots_txt', "User-agent: *\nDisallow: /admin/\nDisallow: /includes/\nDisallow: /core/\nDisallow: /users/\nDisallow: /user*\nAllow: /\n\nSitemap: " . todo_url() . "/sitemap-index.xml");
}
?>

<div class="admin-settings-page">
    <?php if (!empty($errors)): ?>
        <div class="notice notice-error is-dismissible">
            <?php foreach ($errors as $error): ?>
                <p><?php echo $error; ?></p>
            <?php endforeach; ?>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo todo_admin_url('index.php?page=seo-settings'); ?>" class="admin-settings-form todo-request">
        <input type="hidden" name="action" value="update_seo_settings">
        <?php echo Todo_Security_Manager::csrf_field('update_seo_settings', '_todononce'); ?>

        <div class="admin-card">
            <div class="admin-card-header">
                <h3 class="admin-card-title">标题设置</h3>
            </div>
            <div class="admin-card-content">
                <div class="form-group">
                    <label for="title-separator">标题分隔符</label>
                    <select name="title_separator" id="title-separator" class="regular-select">
                        <option value="-" <?php selected($title_separator, '-'); ?>>- (连字符)</option>
                        <option value="|" <?php selected($title_separator, '|'); ?>>| (竖线)</option>
                        <option value="»" <?php selected($title_separator, '»'); ?>>» (双右箭头)</option>
                        <option value="«" <?php selected($title_separator, '«'); ?>>« (双左箭头)</option>
                        <option value="·" <?php selected($title_separator, '·'); ?>>· (中点)</option>
                        <option value="•" <?php selected($title_separator, '•'); ?>>• (圆点)</option>
                        <option value="·" <?php selected($title_separator, '·'); ?>>· (间隔号)</option>
                        <option value=":" <?php selected($title_separator, ':'); ?>>: (冒号)</option>
                        <option value="," <?php selected($title_separator, ','); ?>>, (逗号)</option>
                        <option value="/" <?php selected($title_separator, '/'); ?>>/</option>
                    </select>
                    <p class="description">选择在浏览器标题栏中分隔标题部分的符号，下方设置使用的%separator%。</p>
                </div>

                <div class="form-group">
                    <label for="home-title-format">首页标题格式</label>
                    <input type="text" name="home_title_format" id="home-title-format" style="min-width: 50%;"
                        value="<?php echo esc_attr($home_title_format); ?>" class="regular-text">
                    <p class="description">可用变量: %site_title%, %site_description%, %separator%</p>
                </div>

                <div class="form-group">
                    <label for="post-title-format">文章页标题格式</label>
                    <input type="text" name="post_title_format" id="post-title-format" style="min-width: 50%;"
                        value="<?php echo esc_attr($post_title_format); ?>" class="regular-text">
                    <p class="description">可用变量: %post_title%, %category%, %site_title%, %separator%</p>
                </div>

                <div class="form-group">
                    <label for="page-title-format">页面标题格式</label>
                    <input type="text" name="page_title_format" id="page-title-format" style="min-width: 50%;"
                        value="<?php echo esc_attr($page_title_format); ?>" class="regular-text">
                    <p class="description">可用变量: %page_title%, %site_title%, %separator%</p>
                </div>

                <div class="form-group">
                    <label for="category-title-format">分类页标题格式</label>
                    <input type="text" name="category_title_format" id="category-title-format" style="min-width: 50%;"
                        value="<?php echo esc_attr($category_title_format); ?>" class="regular-text">
                    <p class="description">可用变量: %category_name%, %site_title%, %separator%</p>
                </div>

                <div class="form-group">
                    <label for="tag-title-format">标签页标题格式</label>
                    <input type="text" name="tag_title_format" id="tag-title-format" style="min-width: 50%;"
                        value="<?php echo esc_attr($tag_title_format); ?>" class="regular-text">
                    <p class="description">可用变量: %tag_name%, %site_title%, %separator%</p>
                </div>

                <div class="form-group">
                    <label for="archive-title-format">归档页标题格式</label>
                    <input type="text" name="archive_title_format" id="archive-title-format" style="min-width: 50%;"
                        value="<?php echo esc_attr($archive_title_format); ?>" class="regular-text">
                    <p class="description">可用变量: %date%, %site_title%, %separator%</p>
                </div>

                <div class="form-group">
                    <label for="author-title-format">作者页标题格式</label>
                    <input type="text" name="author_title_format" id="author-title-format" style="min-width: 50%;"
                        value="<?php echo esc_attr($author_title_format); ?>" class="regular-text">
                    <p class="description">可用变量: %author_name%, %site_title%, %separator%</p>
                </div>

                <div class="form-group">
                    <label for="search-title-format">搜索结果页标题格式</label>
                    <input type="text" name="search_title_format" id="search-title-format" style="min-width: 50%;"
                        value="<?php echo esc_attr($search_title_format); ?>" class="regular-text">
                    <p class="description">可用变量: %search_term%, %site_title%, %separator%</p>
                </div>

                <div class="form-group">
                    <label for="404-title-format">404页面标题格式</label>
                    <input type="text" name="error_404_title_format" id="404-title-format" style="min-width: 50%;"
                        value="<?php echo esc_attr($error_404_title_format); ?>" class="regular-text">
                    <p class="description">可用变量: %site_title%, %separator%</p>
                </div>
            </div>
        </div>

        <div class="admin-card">
            <div class="admin-card-header">
                <h3 class="admin-card-title">Meta描述设置</h3>
            </div>
            <div class="admin-card-content">
                <div class="form-group">
                    <label for="home-meta-description">首页Meta描述</label>
                    <textarea name="home_meta_description" id="home-meta-description" rows="3"
                        class="large-text"><?php echo esc_textarea($home_meta_description); ?></textarea>
                    <p class="description">如果留空，将使用站点描述。</p>
                </div>

                <div class="form-group">
                    <label for="post-meta-description">文章页Meta描述格式</label>
                    <textarea name="post_meta_description" id="post-meta-description" rows="3"
                        class="large-text"><?php echo esc_textarea($post_meta_description); ?></textarea>
                    <p class="description">可用变量: %post_excerpt%, %post_content%, %post_title%</p>
                </div>

                <div class="form-group">
                    <label for="page-meta-description">页面Meta描述格式</label>
                    <textarea name="page_meta_description" id="page-meta-description" rows="3"
                        class="large-text"><?php echo esc_textarea($page_meta_description); ?></textarea>
                    <p class="description">可用变量: %page_excerpt%, %page_content%, %page_title%</p>
                </div>

                <div class="form-group">
                    <label for="category-meta-description">分类页Meta描述格式</label>
                    <textarea name="category_meta_description" id="category-meta-description" rows="3"
                        class="large-text"><?php echo esc_textarea($category_meta_description); ?></textarea>
                    <p class="description">可用变量: %category_description%, %category_name%</p>
                </div>

                <div class="form-group">
                    <label for="tag-meta-description">标签页Meta描述格式</label>
                    <textarea name="tag_meta_description" id="tag-meta-description" rows="3"
                        class="large-text"><?php echo esc_textarea($tag_meta_description); ?></textarea>
                    <p class="description">可用变量: %tag_description%, %tag_name%</p>
                </div>
            </div>
        </div>

        <div class="admin-card">
            <div class="admin-card-header">
                <h3 class="admin-card-title">索引设置</h3>
            </div>
            <div class="admin-card-content">
                <div class="form-group">
                    <label class="checkbox-label"<?php echo $noindex_site ? ' style="color: #d63638;' : ''; ?>">
                        <input type="checkbox" name="noindex_site" value="1" <?php checked($noindex_site, 1); ?>>
                        禁止索引本站
                    </label>
                    <p class="description">强烈建议：仅当这是一个内部测试或私密站点时开启。开启后将为全站页面添加 <code>noindex</code> 标记，robots.txt 将拦截爬虫，并清空/停止生成所有站点地图。</p>
                </div>
                <hr style="margin: 15px 0; border: 0; border-top: 1px solid #eee;">

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="noindex_archives" value="1" <?php checked($noindex_archives, 1); ?>>
                        禁止索引归档页面
                    </label>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="noindex_categories" value="1" <?php checked($noindex_categories, 1); ?>>
                        禁止索引分类页面
                    </label>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="noindex_tags" value="1" <?php checked($noindex_tags, 1); ?>>
                        禁止索引标签页面
                    </label>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="noindex_author_archives" value="1" <?php checked($noindex_author_archives, 1); ?>>
                        禁止索引作者页面
                    </label>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="noindex_search_results" value="1" <?php checked($noindex_search_results, 1); ?>>
                        禁止索引搜索结果页面
                    </label>
                </div>
            </div>
        </div>

        <div class="admin-card">
            <div class="admin-card-header">
                <h3 class="admin-card-title">站点地图 Sitemap & AI Index</h3>
            </div>
            <div class="admin-card-content">
                <p class="description" style="margin-bottom: 15px;">系统已默认启用 XML/TXT/llms.txt（AI 模型抓取专用）站点地图，并包含全站文章、分类、标签等所有索引项。如需过滤，请使用下方排除设置。</p>
                
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="sitemap_enable_html" value="1" <?php checked($sitemap_enable_html, 1); ?>>
                        启用 sitemap.html
                    </label>
                </div>

                <div class="form-group">
                    <label for="sitemap-exclude-ids">排除的内容 ID</label>
                    <input type="text" name="sitemap_exclude_ids" id="sitemap-exclude-ids" style="width: 50%;"
                        value="<?php echo esc_attr($sitemap_exclude_ids); ?>" class="regular-text mw-100">
                    <p class="description">输入要从站点地图中排除的文章、页面 ID，多个 ID 之间用英文逗号分隔。</p>
                </div>

                <div class="form-group">
                    <button type="button" id="generate-sitemaps-btn" class="button button-secondary">一键手动更新站点地图</button>
                    <button type="button" id="generate-sitemaps-incr-btn" class="button button-secondary" style="margin-left:8px">增量更新站点地图</button>
                    <span id="sitemap-status-msg" style="margin-left: 10px;"></span>
                    <p class="description">全量生成会重建所有站点地图文件；增量更新仅重新生成自上次生成后有变更的内容（推荐日常使用）。</p>
                </div>
            </div>
        </div>

        <div class="admin-card">
            <div class="admin-card-header">
                <h3 class="admin-card-title">知识图谱 AI / GEO</h3>
            </div>
            <div class="admin-card-content">
                <div class="form-group">
                    <label for="seo-entity-knows-about">专业领域 (knowsAbout)</label>
                    <input type="text" name="seo_entity_knows_about" id="seo-entity-knows-about" style="width: 50%;"
                        value="<?php echo esc_attr($seo_entity_knows_about); ?>" class="regular-text mw-100" placeholder="例如: SEO, CMS, Web Development">
                    <p class="description">用逗号分隔该站点专注的专业领域关键词。这些信号将提供给 Perplexity/Google 等 AI 模型。</p>
                </div>

                <div class="form-group">
                    <label for="seo-entity-same-as">社交网络关联 (sameAs)</label>
                    <textarea name="seo_entity_same_as" id="seo-entity-same-as" rows="3" style="width: 50%;" class="large-text mw-100" placeholder="https://twitter.com/example&#10;https://github.com/example"><?php echo esc_textarea($seo_entity_same_as); ?></textarea>
                    <p class="description">输入与该站点相关联的社交媒体、维基百科等外部权威链接，每行一个（系统会自动处理为列表）。</p>
                </div>
            </div>
        </div>

        <div class="admin-card">
            <div class="admin-card-header">
                <h3 class="admin-card-title">AI SEO状态</h3>
            </div>
            <div class="admin-card-content">
                <div class="form-group">
                    <label>AI 搜索引擎优化状态</label>
                    <div class="notice notice-sm">
                        <ul style="padding-left: 10px;list-style: none;color: #6d6e75;">
                            <li style="margin-bottom: 10px;">系统已建立底层的 AI SEO 核心架构，无需手动开启：</li>
                            <li><strong>LLM 索引支持：</strong> <code>llms.txt</code> 已自动生成并关联至 <code>robots.txt</code>。</li>
                            <li><strong>语义化主题集群：</strong> 自动通过分类、标签构建归档枢纽页，并建立语义化的 URL 结构。</li>
                            <li><strong>智能内链网络：</strong> 系统会自动在文章中通过关键词建立指向枢纽页的语义链接，强化主题权重。</li>
                        </ul>
                    </div>
                </div>

                <div class="form-group">
                    <label>AI 友好性建议</label>
                    <div class="notice notice-warning notice-sm">
                        <ul style="padding-left: 10px;list-style: none;color: #696765;">
                            <li><strong>语义化URL：</strong>确保固定链接结构包含关键词，如 <code>/%category%/%postname%/</code>。</li>
                            <li><strong>结构化数据：</strong>系统会自动为文章添加 Schema.org JSON-LD 标记，建议保持内容结构清晰。</li>
                            <li><strong>内链网络：</strong>AI 搜索引擎高度依赖链接爬取，请确保每个分类和标签都有丰富的相关内容。</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="admin-card">
            <div class="admin-card-header">
                <h3 class="admin-card-title">搜索引擎 API 推送</h3>
            </div>
            <div class="admin-card-content">
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="baidu_push" value="1" <?php checked($baidu_push, 1); ?>>
                        启用百度主动推送 (Baidu)
                    </label>
                    <p class="description">启用后，新发布的内容将自动推送给百度搜索引擎，加快收录速度。</p>
                </div>

                <div class="form-group baidu-push-options" <?php echo $baidu_push ? '' : 'style="display:none;"'; ?>>
                    <label for="baidu-push-token">百度推送 Token</label>
                    <input type="text" name="baidu_push_token" id="baidu-push-token" style="width: 50%;"
                        value="<?php echo esc_attr($baidu_push_token); ?>" class="regular-text">
                    <p class="description">在百度搜索资源平台获取的 API 提交 Token。</p>
                </div>

                <hr style="margin: 15px 0; border: 0; border-top: 1px solid #eee;">

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="indexnow_push" value="1" <?php checked($indexnow_push, 1); ?>>
                        启用 IndexNow 推送 (Bing/Yandex/Seznam)
                    </label>
                    <p class="description">IndexNow 是微软 Bing 发起的实时推送协议。开启后支持将新增/更新的内容瞬间推送到全球支持 IndexNow 的引擎。</p>
                </div>

                <div class="form-group indexnow-push-options" <?php echo $indexnow_push ? '' : 'style="display:none;"'; ?>>
                    <label for="indexnow-key">IndexNow Key (API 密钥)</label>
                    <input type="text" name="indexnow_key" id="indexnow-key" style="width: 50%;"
                        value="<?php echo esc_attr($indexnow_key); ?>" class="regular-text" placeholder="留空则自动生成">
                    <p class="description">保存后系统会自动在网站根目录生成对应的验证文件 (如：key.txt)。如果留空，系统会自动生成一个安全的 32 位密钥。</p>
                </div>
            </div>
        </div>

        <div class="admin-card">
            <div class="admin-card-header">
                <h3 class="admin-card-title">高级设置</h3>
            </div>
            <div class="admin-card-content">
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="canonical_url" value="1" <?php checked($canonical_url, 1); ?>>
                        启用规范链接 canonical URL
                    </label>
                    <p class="description">规范链接有助于防止重复内容问题，建议启用。</p>
                </div>

                <div class="form-group">
                    <label for="robots-txt">robots.txt内容</label>
                    <textarea name="robots_txt" id="robots-txt" rows="8" style="width: 50%;"
                        class="large-text mw-100 code"><?php echo esc_textarea($robots_txt); ?></textarea>
                    <p class="description">自定义robots.txt文件内容，用于指导搜索引擎爬虫如何爬取您的网站。</p>
                </div>
            </div>
        </div>

        <div class="admin-form-submit">
            <button type="submit" class="button button-primary">保存更改</button>
        </div>
    </form>
</div>

<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function () {
        // 百度推送选项显示/隐藏
        const baiduPushCheckbox = document.querySelector('input[name="baidu_push"]');
        const baiduPushOptions = document.querySelectorAll('.baidu-push-options');

        if (baiduPushCheckbox) {
            baiduPushCheckbox.addEventListener('change', function () {
                baiduPushOptions.forEach(option => {
                    option.style.display = this.checked ? 'block' : 'none';
                });
            });
        }

        // IndexNow 推送选项显示/隐藏
        const indexnowPushCheckbox = document.querySelector('input[name="indexnow_push"]');
        const indexnowPushOptions = document.querySelectorAll('.indexnow-push-options');

        if (indexnowPushCheckbox) {
            indexnowPushCheckbox.addEventListener('change', function () {
                indexnowPushOptions.forEach(option => {
                    option.style.display = this.checked ? 'block' : 'none';
                });
            });
        }

        // 手动更新站点地图（全量 + 增量）
        const sitemapStatusMsg = document.getElementById('sitemap-status-msg');
        const nonce = document.querySelector('input[name="_todononce"]').value;

        function triggerSitemapGeneration(btn, mode, label) {
            btn.disabled = true;
            btn.innerHTML = '正在生成 <i class="myload">.</i>';
            sitemapStatusMsg.textContent = '';
            sitemapStatusMsg.style.color = 'inherit';

            const formData = new FormData();
            formData.append('action', 'generate_sitemaps');
            formData.append('_todononce', nonce);
            formData.append('mode', mode);

            fetch('admin-ajax.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    sitemapStatusMsg.textContent = '\u2713 ' + data.message;
                    sitemapStatusMsg.style.color = 'green';
                } else {
                    sitemapStatusMsg.textContent = '\u2715 ' + (data.error || data.message || '生成失败');
                    sitemapStatusMsg.style.color = '#d43838';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                sitemapStatusMsg.textContent = '\u2715 请求出错，请重试。';
                sitemapStatusMsg.style.color = '#d43838';
            })
            .finally(() => {
                btn.disabled = false;
                btn.textContent = label;
            });
        }

        const fullBtn = document.getElementById('generate-sitemaps-btn');
        if (fullBtn) {
            fullBtn.addEventListener('click', function () {
                triggerSitemapGeneration(this, 'full', '一键手动更新站点地图');
            });
        }

        const incrBtn = document.getElementById('generate-sitemaps-incr-btn');
        if (incrBtn) {
            incrBtn.addEventListener('click', function () {
                triggerSitemapGeneration(this, 'incremental', '增量更新站点地图');
            });
        }
    });
</script>
