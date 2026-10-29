<?php
/**
 * Sitemap Service
 * Handles generation of XML (split by type + date-chunked), TXT, HTML, llms.txt and robots.txt
 *
 * Features:
 * - Content-type splitting: posts (date-chunked), pages, categories, tags
 * - Sitemap Index file for unified management
 * - Incremental update: only regenerate changed date chunks
 * - Supports 10k+ articles efficiently
 *
 * @package TodoCMS
 * @subpackage Service
 * @version 3.0.0
 */

class Todo_Sitemap_Service {

    const CHUNK_SIZE = 1000; // Max URLs per single sitemap file

    // ═══════════════════════════════════════════
    // PUBLIC API
    // ═══════════════════════════════════════════

    /**
     * Full generation — rebuild everything from scratch
     * Called on manual trigger or first run
     */
    public static function generate_all() {
        $results = [];

        // Global noindex: wipe everything, write blocking robots.txt only
        if (get_option('seo_noindex_site', 0)) {
            self::clean_all_sitemap_files();
            $results['robots'] = self::generate_robots();
            return $results;
        }

        // Clean old-style files first (smooth migration)
        self::clean_legacy_files();

        // Generate each content type
        $results['posts']        = self::generate_posts();
        $results['pages']        = self::generate_pages();
        $results['categories']   = self::generate_categories();
        $results['tags']         = self::generate_tags();

        // Sitemap Index (collects all sub-sitemaps)
        $results['index']        = self::generate_index();

        // Non-XML formats — always generated on manual full regeneration
        // (admin SEO settings panel calls generate_all() directly)
        // seo_enable_sitemap_html only gates incremental/cron generation
        $results['txt']          = self::generate_txt();
        $results['html']         = self::generate_html();
        $results['llms.txt']     = self::generate_llms_txt();

        // Custom sitemap files
        // Reserved filenames are already handled by the standard generators above.
        // Exclude them to prevent generate_custom() (which writes plain text)
        // from overwriting the proper XML files.
        $reserved_sitemaps = [
            'sitemap-pages.xml', 'sitemap-categories.xml', 'sitemap-tags.xml',
            'sitemap-index.xml', 'sitemap.xml', 'sitemap.txt', 'sitemap.html', 'llms.txt',
        ];
        $custom = get_option('seo_custom_sitemaps', '');
        if (!empty($custom)) {
            foreach (array_map('trim', explode(',', $custom)) as $file) {
                if ($file === '') continue;
                // Skip reserved filenames and sitemap-posts-*.xml patterns
                if (in_array($file, $reserved_sitemaps, true)) continue;
                if (preg_match('/^sitemap-posts-/i', $file)) continue;
                $results[$file] = self::generate_custom($file);
            }
        }

        $results['robots']       = self::generate_robots();

        // Record generation timestamp
        update_option('sitemap_last_generation', time());

        return $results;
    }

    /**
     * Lightweight single-post sitemap update — only regenerate the affected month + index
     * Designed for post save/update hooks on large sites (10k+ articles)
     *
     * @param int    $post_id
     * @param string $post_date  Optional: post_date for delete case where post no longer exists
     * @return array Results
     */
    public static function regenerate_post_sitemap($post_id, $post_date = null) {
        global $tododb;
        $results = ['posts' => 'no_change', 'index' => 'no_change'];

        if (get_option('seo_noindex_site', 0)) {
            return $results;
        }

        $month = null;

        // If post_date provided (delete case), use it directly
        if ($post_date !== null) {
            $month = date('Y-m', strtotime($post_date));
        } else {
            // Get post's publish date month
            $post = $tododb->get_row(
                "SELECT post_date, post_status FROM {$tododb->prefix}posts WHERE ID = ?",
                [$post_id]
            );
            if ($post) {
                $month = date('Y-m', strtotime($post->post_date));
            }
        }

        if ($month === null) {
            return $results;
        }

        // Regenerate that month's sitemap only
        $results['posts'] = self::generate_posts_month($month);
        $results['index'] = self::generate_index();

        update_option('sitemap_last_generation', time());

        return $results;
    }

    /**
     * Incremental generation — only regenerate changed content since last run
     * Ideal for cron/scheduled tasks on large sites (10k+ articles)
     *
     * @return array Results per type
     */
    public static function generate_incremental() {
        global $tododb;
        $results = [];

        if (get_option('seo_noindex_site', 0)) {
            self::clean_all_sitemap_files();
            $results['robots'] = self::generate_robots();
            return $results;
        }

        $last_gen = (int) get_option('sitemap_last_generation', 0);

        // If never generated, fall back to full generation
        if ($last_gen === 0) {
            return self::generate_all();
        }

        $since = date('Y-m-d H:i:s', $last_gen);
        $changed = false;

        // ── Posts: only regenerate months that have changes ──
        $changed_months = $tododb->get_results(
            "SELECT DISTINCT DATE_FORMAT(post_date, '%Y-%m') AS month
             FROM {$tododb->prefix}posts
             WHERE post_status = 'publish'
               AND post_type = 'article'
               AND post_modified >= ?
             ORDER BY month",
            [$since]
        );

        if (!empty($changed_months)) {
            foreach ($changed_months as $row) {
                self::generate_posts_month($row->month);
            }
            $changed = true;
            $results['posts'] = true;
        } else {
            $results['posts'] = 'no_changes';
        }

        // ── Pages: regenerate if any page changed ──
        $pages_changed = (bool) $tododb->get_var(
            "SELECT COUNT(*) FROM {$tododb->prefix}posts
             WHERE post_status = 'publish'
               AND post_type = 'page'
               AND post_modified >= ?",
            [$since]
        );
        if ($pages_changed) {
            $results['pages'] = self::generate_pages();
            $changed = true;
        } else {
            $results['pages'] = 'no_changes';
        }

        // ── Categories: regenerate if terms/relationships changed ──
        $cats_changed = (bool) $tododb->get_var(
            "SELECT COUNT(*) FROM {$tododb->prefix}terms t
             INNER JOIN {$tododb->prefix}term_taxonomy tt ON t.term_id = tt.term_id
             WHERE tt.taxonomy = 'category'"
        );
        if ($cats_changed) {
            // Simple check: if any post changed that has categories, regen categories too
            if (!empty($changed_months)) {
                $results['categories'] = self::generate_categories();
                $changed = true;
            }
        }
        if (!isset($results['categories'])) $results['categories'] = 'no_changes';

        // ── Tags: same logic ──
        if (!empty($changed_months)) {
            $results['tags'] = self::generate_tags();
            $changed = true;
        } else {
            $results['tags'] = 'no_changes';
        }

        // Rebuild index if anything changed
        if ($changed) {
            $results['index'] = self::generate_index();
        } else {
            $results['index'] = 'no_changes';
        }

        // Skip heavy non-XML formats in incremental mode
        // sitemap.txt/llms.txt/html query ALL posts and kill performance on 10k+ articles
        $results['txt']      = 'skipped_incremental';
        $results['html']     = 'skipped_incremental';
        $results['llms.txt'] = 'skipped_incremental';
        $results['robots']   = self::generate_robots();

        update_option('sitemap_last_generation', time());

        return $results;
    }

    // ═══════════════════════════════════════════
    // XML: SITEMAP INDEX
    // ═══════════════════════════════════════════

    /**
     * Generate sitemap-index.xml that references all sub-sitemaps
     */
    private static function generate_index() {
        $site_url = rtrim(todo_get_site_url(), '/');
        $entries  = [];

        // Scan for all sitemap files
        $patterns = [
            'sitemap-posts-*.xml',
            'sitemap-pages.xml',
            'sitemap-categories.xml',
            'sitemap-tags.xml',
        ];

        $seen = [];
        foreach ($patterns as $pattern) {
            $files = glob(ABSPATH . $pattern);
            if ($files === false) continue;
            sort($files); // consistent ordering
            foreach ($files as $file) {
                $basename = basename($file);
                if (isset($seen[$basename])) continue;
                $seen[$basename] = true;
                $entries[] = [
                    'loc'     => $site_url . '/' . $basename,
                    'lastmod' => date('Y-m-d', filemtime($file)),
                ];
            }
        }

        // Build index XML
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;
        foreach ($entries as $entry) {
            $xml .= '  <sitemap>' . PHP_EOL;
            $xml .= '    <loc>' . htmlspecialchars($entry['loc']) . '</loc>' . PHP_EOL;
            $xml .= '    <lastmod>' . $entry['lastmod'] . '</lastmod>' . PHP_EOL;
            $xml .= '  </sitemap>' . PHP_EOL;
        }
        $xml .= '</sitemapindex>';

        $ok = file_put_contents(ABSPATH . 'sitemap-index.xml', $xml) !== false;
        return $ok && !empty($entries);
    }

    // ═══════════════════════════════════════════
    // XML: POSTS — Date-chunked
    // ═══════════════════════════════════════════

    /**
     * Generate all posts sitemaps (full regeneration)
     * Groups posts by YYYY-MM, each month gets a separate file
     * If a month exceeds CHUNK_SIZE, it's further split with a numeric suffix
     */
    private static function generate_posts() {
        global $tododb;
        $site_url = rtrim(todo_get_site_url(), '/');
        $exclude_ids = self::get_exclude_ids();

        $where = "post_status = 'publish' AND visibility = 'public' AND post_type = 'article'";
        if (!empty($exclude_ids)) {
            $where .= ' AND ID NOT IN (' . implode(',', $exclude_ids) . ')';
        }

        $posts = $tododb->get_results(
            "SELECT ID, post_title, post_name, post_date, post_modified, category_id, custom_url
             FROM {$tododb->prefix}posts
             WHERE {$where}
             ORDER BY post_date DESC"
        );

        if (empty($posts)) {
            // Remove any existing posts sitemap files
            array_map('unlink', glob(ABSPATH . 'sitemap-posts-*.xml') ?: []);
            return true;
        }

        // Group posts by month
        $by_month = [];
        foreach ($posts as $p) {
            $month = date('Y-m', strtotime($p->post_date));
            $by_month[$month][] = $p;
        }

        // Clean old posts sitemap files not in current months
        $current_months = array_keys($by_month);
        $existing_files = glob(ABSPATH . 'sitemap-posts-*.xml') ?: [];
        foreach ($existing_files as $file) {
            $basename = basename($file, '.xml'); // sitemap-posts-2026-06 or sitemap-posts-2026-06-2
            // Extract month part
            if (preg_match('/^sitemap-posts-(\d{4}-\d{2})/', $basename, $m)) {
                if (!in_array($m[1], $current_months)) {
                    @unlink($file);
                }
            } else {
                @unlink($file); // remove unrecognized patterns from old versions
            }
        }

        // Generate each month's sitemap
        $all_ok = true;
        foreach ($by_month as $month => $month_posts) {
            $all_ok = self::generate_posts_month($month, $month_posts) && $all_ok;
        }

        return $all_ok;
    }

    /**
     * Generate a single month's posts sitemap
     * @param string $month  YYYY-MM format
     * @param array|null $posts  Pre-fetched posts for this month (null = query DB)
     * @return bool
     */
    private static function generate_posts_month($month, $posts = null) {
        global $tododb;
        $site_url    = rtrim(todo_get_site_url(), '/');
        $exclude_ids = self::get_exclude_ids();

        if ($posts === null) {
            $where = "post_status = 'publish' AND post_type = 'article' AND DATE_FORMAT(post_date, '%Y-%m') = ?";
            if (!empty($exclude_ids)) {
                $where .= ' AND ID NOT IN (' . implode(',', $exclude_ids) . ')';
            }
            $posts = $tododb->get_results(
                "SELECT ID, post_title, post_name, post_date, post_modified, category_id, custom_url
                 FROM {$tododb->prefix}posts
                 WHERE {$where}
                 ORDER BY post_date DESC",
                [$month]
            );
        }

        // Remove old files for this month
        $existing = glob(ABSPATH . "sitemap-posts-{$month}*.xml") ?: [];
        foreach ($existing as $f) @unlink($f);

        if (empty($posts)) return true;

        // Batch-fetch category model mappings for single-model detection
        $cat_ids = [];
        foreach ($posts as $p) {
            if (!empty($p->category_id)) {
                $cat_ids[] = (int)$p->category_id;
            }
        }
        $cat_models = !empty($cat_ids) && class_exists('Todo_Model_Manager')
            ? Todo_Model_Manager::get_category_models_batch(array_unique($cat_ids))
            : [];

        // Build URL entries — fast in-memory, with single-model awareness
        $structure = get_option('permalink_structure', '');
        $urls = [];
        foreach ($posts as $p) {
            $model = null;
            if (!empty($p->category_id) && isset($cat_models[(int)$p->category_id])) {
                $model = $cat_models[(int)$p->category_id];
            }
            $urls[] = [
                'loc'        => self::build_fast_permalink($p, $structure, $site_url, $model),
                'lastmod'    => date('Y-m-d', strtotime($p->post_modified ?: $p->post_date)),
                'changefreq' => 'weekly',
                'priority'   => '0.8',
            ];
        }

        // Chunk if needed
        $chunks = array_chunk($urls, self::CHUNK_SIZE);
        $all_ok = true;

        foreach ($chunks as $i => $chunk) {
            $filename = count($chunks) > 1
                ? "sitemap-posts-{$month}-" . ($i + 1) . '.xml'
                : "sitemap-posts-{$month}.xml";
            $all_ok = self::write_urlset(ABSPATH . $filename, $chunk) && $all_ok;
        }

        return $all_ok;
    }

    // ═══════════════════════════════════════════
    // XML: PAGES
    // ═══════════════════════════════════════════

    /**
     * Generate sitemap-pages.xml — standalone pages (post_type=page) + single-model category posts
     */
    private static function generate_pages() {
        global $tododb;
        $site_url    = rtrim(todo_get_site_url(), '/');
        $exclude_ids = self::get_exclude_ids();

        $urls = [];

        // ── 1. post_type = 'page' ──
        $where = "post_status = 'publish' AND visibility = 'public' AND post_type = 'page'";
        if (!empty($exclude_ids)) {
            $where .= ' AND ID NOT IN (' . implode(',', $exclude_ids) . ')';
        }
        $pages = $tododb->get_results(
            "SELECT ID, post_title, post_date, post_modified
             FROM {$tododb->prefix}posts
             WHERE {$where}
             ORDER BY post_date DESC"
        );
        foreach ($pages as $pg) {
            $urls[] = [
                'loc'        => function_exists('todo_get_permalink') ? todo_get_permalink($pg->ID) : $site_url . '/?p=' . $pg->ID,
                'lastmod'    => date('Y-m-d', strtotime($pg->post_modified ?: $pg->post_date)),
                'changefreq' => 'monthly',
                'priority'   => '0.6',
            ];
        }

        // ── 2. single-model categories → their actual post URL ──
        // These categories hold exactly one article that acts as a standalone page (e.g. design.html)
        $single_urls = self::build_single_category_urls();
        foreach ($single_urls as $u) {
            $urls[] = $u;
        }

        if (empty($urls)) {
            if (file_exists(ABSPATH . 'sitemap-pages.xml')) {
                @unlink(ABSPATH . 'sitemap-pages.xml');
            }
            return true;
        }

        return self::write_urlset(ABSPATH . 'sitemap-pages.xml', $urls);
    }

    /**
     * Collect URLs for all 'single'-model categories.
     * Each such category maps to exactly one article that serves as a standalone page.
     * Returns URL entries suitable for sitemap-pages.xml.
     */
    private static function build_single_category_urls() {
        global $tododb;
        $urls     = [];
        $site_url = rtrim(todo_get_site_url(), '/');

        if (!class_exists('Todo_Model_Manager')) {
            return $urls;
        }

        // Fetch all category term_ids that use the 'single' model
        $single_term_ids = $tododb->get_col(
            "SELECT tm.term_id
             FROM {$tododb->prefix}term_models tm
             JOIN {$tododb->prefix}models m ON tm.model_id = m.model_id
             WHERE m.model_key = 'single'"
        );

        if (empty($single_term_ids)) {
            return $urls;
        }

        $ids_str   = implode(',', array_map('intval', $single_term_ids));
        $structure = get_option('permalink_structure', '');

        // Query posts directly via posts.category_id.
        // TodoCMS stores the primary category as a direct FK on the posts table;
        // term_relationships may not be populated, so we avoid that join here.
        // We order by post_date DESC and deduplicate in PHP to avoid GROUP BY
        // incompatibility with MySQL ONLY_FULL_GROUP_BY mode.
        $raw_rows = $tododb->get_results(
            "SELECT p.ID, p.post_name, p.post_date, p.post_modified, p.custom_url, p.category_id
             FROM {$tododb->prefix}posts p
             WHERE p.post_status = 'publish' AND p.visibility = 'public'
               AND p.category_id IN ({$ids_str})
             ORDER BY p.post_date DESC"
        );

        // Keep only the first (latest) post per category_id
        $seen_cats = [];
        $rows = [];
        foreach ($raw_rows as $p) {
            $cid = (int) $p->category_id;
            if (!isset($seen_cats[$cid])) {
                $seen_cats[$cid] = true;
                $rows[] = $p;
            }
        }

        foreach ($rows as $p) {
            $loc = self::build_fast_permalink($p, $structure, $site_url, 'single');
            $urls[] = [
                'loc'        => $loc,
                'lastmod'    => date('Y-m-d', strtotime($p->post_modified ?: $p->post_date)),
                'changefreq' => 'monthly',
                'priority'   => '0.9',
            ];
        }

        return $urls;
    }

    // ═══════════════════════════════════════════
    // XML: CATEGORIES
    // ═══════════════════════════════════════════

    /**
     * Generate sitemap-categories.xml — all category archive pages
     */
    private static function generate_categories() {
        $urls = self::build_taxonomy_urls('category', '0.9');
        if (empty($urls)) {
            if (file_exists(ABSPATH . 'sitemap-categories.xml')) {
                @unlink(ABSPATH . 'sitemap-categories.xml');
            }
            return true;
        }
        return self::write_urlset(ABSPATH . 'sitemap-categories.xml', $urls);
    }

    // ═══════════════════════════════════════════
    // XML: TAGS
    // ═══════════════════════════════════════════

    /**
     * Generate sitemap-tags.xml — all tag archive pages
     * Tags are often noindex'd, but available for those who want them
     */
    private static function generate_tags() {
        $urls = self::build_taxonomy_urls('tag', '0.7');
        if (empty($urls)) {
            if (file_exists(ABSPATH . 'sitemap-tags.xml')) {
                @unlink(ABSPATH . 'sitemap-tags.xml');
            }
            return true;
        }
        return self::write_urlset(ABSPATH . 'sitemap-tags.xml', $urls);
    }

    // ═══════════════════════════════════════════
    // TXT SITEMAP
    // ═══════════════════════════════════════════

    /**
     * Generate sitemap.txt — plain URL list (AI crawler friendly)
     */
    public static function generate_txt() {
        $urls = self::collect_all_urls();
        $txt  = implode(PHP_EOL, array_unique(array_column($urls, 'loc')));
        return file_put_contents(ABSPATH . 'sitemap.txt', $txt) !== false;
    }

    // ═══════════════════════════════════════════
    // HTML SITEMAP
    // ═══════════════════════════════════════════

    /**
     * Generate sitemap.html — human-readable sitemap page
     */
    public static function generate_html() {
        $urls       = self::collect_all_urls();
        $site_title = get_option('site_title', 'TodoCMS');

        $html  = "<!DOCTYPE html>\n<html lang=\"zh-CN\">\n<head>\n";
        $html .= "  <meta charset=\"UTF-8\">\n";
        $html .= "  <title>站点地图 - {$site_title}</title>\n";
        $html .= "  <style>body{font-family:system-ui,sans-serif;max-width:900px;margin:40px auto;padding:0 20px}ul{list-style:none;padding:0}li{margin-bottom:8px}a{color:#2563eb;text-decoration:none}a:hover{text-decoration:underline}</style>\n";
        $html .= "</head>\n<body>\n<h1>站点地图</h1>\n<ul>\n";
        foreach ($urls as $url) {
            $title = !empty($url['title']) ? htmlspecialchars($url['title']) : $url['loc'];
            $html .= "  <li><a href=\"{$url['loc']}\">{$title}</a></li>\n";
        }
        $html .= "</ul>\n<p style=\"color:#888;font-size:.85em\">Generated by TodoCMS on " . date('Y-m-d H:i:s') . "</p>\n</body>\n</html>";

        return file_put_contents(ABSPATH . 'sitemap.html', $html) !== false;
    }

    // ═══════════════════════════════════════════
    // llms.txt
    // ═══════════════════════════════════════════

    /**
     * Generate llms.txt — structured Topic Cluster format for LLM crawlers
     */
    public static function generate_llms_txt() {
        global $tododb;
        $site_title = get_option('site_title', 'TodoCMS');
        $site_desc  = get_option('site_description', '');
        $site_url   = rtrim(todo_get_site_url(), '/');

        $langs = [];
        if (class_exists('LanguageRegistry')) {
            $reg = LanguageRegistry::get_instance();
            $map = method_exists($reg, 'path_prefix_map') ? $reg->path_prefix_map() : [];
            foreach ($map as $locale => $_) $langs[] = $locale;
        }
        $lang_str = !empty($langs) ? implode(', ', $langs) : 'zh-CN';

        $knows = get_option('seo_entity_knows_about', '');

        $content  = "# {$site_title}\n\n";
        if ($site_desc) $content .= "> {$site_desc}\n\n";

        $content .= "## Site Intent\n\n";
        if ($knows) $content .= "This site specializes in: {$knows}\n";
        $content .= "Content language: {$lang_str}\n";
        $content .= "Indexing permission: full\n\n";

        // Topic Clusters
        $categories = $tododb->get_results(
            "SELECT t.term_id, t.name, t.slug, tt.description, tt.count
             FROM {$tododb->prefix}terms t
             INNER JOIN {$tododb->prefix}term_taxonomy tt ON t.term_id = tt.term_id
             WHERE tt.taxonomy = 'category' AND tt.count > 0
             ORDER BY tt.count DESC"
        );

        if (!empty($categories)) {
            $content .= "## Topic Clusters\n\n";
            foreach ($categories as $cat) {
                $cat_url = function_exists('todo_get_category_url') ? todo_get_category_url($cat->term_id) : $site_url . '/category/' . $cat->slug . '/';
                $content .= "### {$cat->name}\n\n";
                if (!empty($cat->description)) {
                    $content .= "- Description: " . strip_tags($cat->description) . "\n";
                }
                $content .= "- Hub URL: {$cat_url}\n";
                $content .= "- Post count: {$cat->count}\n";

                $posts = $tododb->get_results(
                    "SELECT p.ID, p.post_title, p.post_excerpt, p.post_date
                     FROM {$tododb->prefix}posts p
                     INNER JOIN {$tododb->prefix}term_relationships tr ON p.ID = tr.object_id
                     INNER JOIN {$tododb->prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                     WHERE tt.term_id = ? AND tt.taxonomy = 'category' AND p.post_status = 'publish' AND p.visibility = 'public'
                     ORDER BY p.post_date DESC LIMIT 5",
                    [$cat->term_id]
                );

                if (!empty($posts)) {
                    $content .= "- Top articles:\n";
                    foreach ($posts as $p) {
                        $post_url = function_exists('todo_get_permalink') ? todo_get_permalink($p->ID) : $site_url . '/?p=' . $p->ID;
                        $excerpt  = $p->post_excerpt ? strip_tags($p->post_excerpt) : '';
                        $excerpt  = $excerpt ? ' — ' . mb_substr($excerpt, 0, 80) : '';
                        $content .= "  - [{$p->post_title}]({$post_url}){$excerpt}\n";
                    }
                }
                $content .= "\n";
            }
        }

        // Essential Pages
        $content .= "## Essential Pages\n\n";
        $content .= "- [首页]({$site_url}/)\n";

        $pages = $tododb->get_results(
            "SELECT ID, post_title FROM {$tododb->prefix}posts
             WHERE post_status = 'publish' AND post_type = 'page'
             ORDER BY post_date ASC LIMIT 10"
        );
        foreach ($pages as $pg) {
            $pg_url = function_exists('todo_get_permalink') ? todo_get_permalink($pg->ID) : $site_url . '/?p=' . $pg->ID;
            $content .= "- [{$pg->post_title}]({$pg_url})\n";
        }

        // All Content (up to 500 for llms.txt)
        $content .= "\n## All Content\n\n";
        $all_posts = $tododb->get_results(
            "SELECT p.ID, p.post_title, p.post_date, MAX(t.name) as cat_name
             FROM {$tododb->prefix}posts p
             LEFT JOIN {$tododb->prefix}term_relationships tr ON p.ID = tr.object_id
             LEFT JOIN {$tododb->prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'category'
             LEFT JOIN {$tododb->prefix}terms t ON tt.term_id = t.term_id
             WHERE p.post_status = 'publish' AND p.visibility = 'public' AND p.post_type = 'article'
             GROUP BY p.ID
             ORDER BY p.post_date DESC LIMIT 500"
        );
        foreach ($all_posts as $p) {
            $post_url = function_exists('todo_get_permalink') ? todo_get_permalink($p->ID) : $site_url . '/?p=' . $p->ID;
            $date     = date('Y-m-d', strtotime($p->post_date));
            $cat_str  = $p->cat_name ? " — {$p->cat_name}" : '';
            $content .= "- [{$p->post_title}]({$post_url}) — {$date}{$cat_str}\n";
        }

        $content .= "\n## Content Policy\n\n";
        $content .= "- Update frequency: daily\n";
        $content .= "- Content types: articles, pages\n";
        $content .= "- Indexing permission: full\n";

        return file_put_contents(ABSPATH . 'llms.txt', $content) !== false;
    }

    // ═══════════════════════════════════════════
    // CUSTOM SITEMAP FILES
    // ═══════════════════════════════════════════

    /**
     * Generate a custom sitemap file (non-llms.txt)
     */
    public static function generate_custom($filename) {
        $urls    = self::collect_all_urls();
        $content = implode(PHP_EOL, array_unique(array_column($urls, 'loc')));
        return file_put_contents(ABSPATH . ltrim($filename, '/'), $content) !== false;
    }

    // ═══════════════════════════════════════════
    // ROBOTS.TXT
    // ═══════════════════════════════════════════

    /**
     * Generate robots.txt with AI crawler allowlists
     */
    public static function generate_robots() {
        $site_url = rtrim(todo_url(), '/');

        if (get_option('seo_noindex_site', 0)) {
            $robots_txt = "User-agent: *\nDisallow: /\n";
            return file_put_contents(ABSPATH . 'robots.txt', $robots_txt) !== false;
        }

        $robots_txt = get_option('seo_robots_txt', '');

        if (empty($robots_txt)) {
            $robots_txt  = "User-agent: *\n";
            $robots_txt .= "Disallow: /admin/\n";
            $robots_txt .= "Disallow: /includes/\n";
            $robots_txt .= "Disallow: /core/\n";
            $robots_txt .= "Disallow: /users/\n";
            $robots_txt .= "Disallow: /user*\n";
            $robots_txt .= "Disallow: /trapdoor.html\n";
            $robots_txt .= "Allow: /\n\n";
        } else {
            if (strpos($robots_txt, '/trapdoor.html') === false) {
                $robots_txt = str_replace("User-agent: *\n", "User-agent: *\nDisallow: /trapdoor.html\n", $robots_txt);
            }
            if (strpos($robots_txt, '/users/') === false) {
                $robots_txt = str_replace("User-agent: *\n", "User-agent: *\nDisallow: /users/\nDisallow: /user*\n", $robots_txt);
            }
        }

        // AI crawler allowlists
        $ai_agents = [
            'GPTBot'         => "User-agent: GPTBot\nAllow: /\nDisallow: /admin/\n",
            'PerplexityBot'  => "User-agent: PerplexityBot\nAllow: /\n",
            'anthropic-ai'   => "User-agent: anthropic-ai\nAllow: /\n",
            'Google-Extended'=> "User-agent: Google-Extended\nAllow: /\n",
            'ClaudeBot'      => "User-agent: ClaudeBot\nAllow: /\n",
        ];

        foreach ($ai_agents as $agent => $block) {
            if (strpos($robots_txt, "User-agent: {$agent}") === false) {
                $robots_txt .= "\n" . $block;
            }
        }

        // Sitemap references
        if (strpos($robots_txt, 'Sitemap:') === false) {
            $robots_txt .= "\nSitemap: {$site_url}/sitemap-index.xml\n";
            $robots_txt .= "Sitemap: {$site_url}/llms.txt\n";
        } elseif (strpos($robots_txt, 'llms.txt') === false) {
            $robots_txt .= "\nSitemap: {$site_url}/llms.txt\n";
        }

        return file_put_contents(ABSPATH . 'robots.txt', $robots_txt) !== false;
    }

    // ═══════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════

    /**
     * Write a <urlset> XML file
     */
    private static function write_urlset($path, array $urls) {
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;
        foreach ($urls as $url) {
            $xml .= '  <url>' . PHP_EOL;
            $xml .= '    <loc>' . htmlspecialchars($url['loc']) . '</loc>' . PHP_EOL;
            $xml .= '    <lastmod>' . $url['lastmod'] . '</lastmod>' . PHP_EOL;
            $xml .= '    <changefreq>' . $url['changefreq'] . '</changefreq>' . PHP_EOL;
            $xml .= '    <priority>' . $url['priority'] . '</priority>' . PHP_EOL;
            $xml .= '  </url>' . PHP_EOL;
        }
        $xml .= '</urlset>';
        return file_put_contents($path, $xml) !== false;
    }

    /**
     * Build taxonomy (category/tag) URL entries.
     * For categories: excludes 'single'-model categories — those are standalone pages,
     * not list archives, and belong in sitemap-pages.xml instead.
     */
    private static function build_taxonomy_urls($taxonomy, $priority) {
        global $tododb;
        $urls     = [];
        $site_url = rtrim(todo_get_site_url(), '/');

        // Tags use legacy tables (todo_tags + todo_post_tags),
        // Categories use the term system (todo_terms + todo_term_taxonomy).
        if ($taxonomy === 'tag') {
            $rows = $tododb->get_results(
                "SELECT DISTINCT tg.tag_id, tg.tag_title AS name, tg.tag_slug AS slug
                 FROM {$tododb->prefix}tags tg
                 INNER JOIN {$tododb->prefix}post_tags pt ON tg.tag_id = pt.tag_id
                 INNER JOIN {$tododb->prefix}posts p ON pt.post_id = p.ID
                 WHERE tg.tag_status = 'active' AND p.post_status != 'trash'"
            );
        } else {
            $rows = $tododb->get_results(
                "SELECT t.term_id, t.name, t.slug
                 FROM {$tododb->prefix}terms t
                 INNER JOIN {$tododb->prefix}term_taxonomy tt ON t.term_id = tt.term_id
                 WHERE tt.taxonomy = ? AND tt.count > 0",
                [$taxonomy]
            );
        }

        // For categories: fetch which term_ids use 'single' model so we can skip them.
        // Single-model categories represent standalone pages, not list archives.
        $single_term_ids = [];
        if ($taxonomy === 'category' && class_exists('Todo_Model_Manager')) {
            $single_term_ids = (array) $tododb->get_col(
                "SELECT tm.term_id
                 FROM {$tododb->prefix}term_models tm
                 JOIN {$tododb->prefix}models m ON tm.model_id = m.model_id
                 WHERE m.model_key = 'single'"
            );
            $single_term_ids = array_map('intval', $single_term_ids);
        }

        foreach ($rows as $r) {
            // Skip single-model categories — they go to sitemap-pages.xml
            if ($taxonomy === 'category' && in_array((int)$r->term_id, $single_term_ids, true)) {
                continue;
            }

            if ($taxonomy === 'category' && function_exists('todo_get_category_link')) {
                $url = todo_get_category_link($r->term_id);
            } elseif ($taxonomy === 'tag' && function_exists('todo_get_tag_link')) {
                $url = todo_get_tag_link($r->tag_id);
            } else {
                $url = $site_url . '/' . $taxonomy . '/' . $r->slug . '/';
            }
            $urls[] = [
                'loc'        => $url,
                'lastmod'    => date('Y-m-d'),
                'changefreq' => 'weekly',
                'priority'   => $priority,
                'title'      => $r->name,
            ];
        }
        return $urls;
    }

    /**
     * Collect all URLs for TXT/HTML/Custom formats
     * Uses DB batch queries (not the XML files) for consistency
     */
    private static function collect_all_urls() {
        global $tododb;
        $urls     = [];
        $site_url = rtrim(todo_get_site_url(), '/');
        $exclude_ids = self::get_exclude_ids();

        // Homepage
        $urls[] = [
            'loc'        => $site_url . '/',
            'lastmod'    => date('Y-m-d'),
            'changefreq' => 'daily',
            'priority'   => '1.0',
            'title'      => get_option('site_title', 'Home'),
        ];

        // Articles
        $where = "post_status = 'publish' AND visibility = 'public' AND post_type = 'article'";
        if (!empty($exclude_ids)) $where .= ' AND ID NOT IN (' . implode(',', $exclude_ids) . ')';
        $posts = $tododb->get_results(
            "SELECT ID, post_title, post_date, post_modified
             FROM {$tododb->prefix}posts WHERE {$where}
             ORDER BY post_date DESC"
        );
        foreach ($posts as $p) {
            $urls[] = [
                'loc'        => function_exists('todo_get_permalink') ? todo_get_permalink($p->ID) : $site_url . '/?p=' . $p->ID,
                'lastmod'    => date('Y-m-d', strtotime($p->post_modified ?: $p->post_date)),
                'changefreq' => 'weekly',
                'priority'   => '0.8',
                'title'      => $p->post_title,
            ];
        }

        // Pages
        $where = "post_status = 'publish' AND visibility = 'public' AND post_type = 'page'";
        if (!empty($exclude_ids)) $where .= ' AND ID NOT IN (' . implode(',', $exclude_ids) . ')';
        $pages = $tododb->get_results(
            "SELECT ID, post_title, post_date, post_modified
             FROM {$tododb->prefix}posts WHERE {$where}"
        );
        foreach ($pages as $pg) {
            $urls[] = [
                'loc'        => function_exists('todo_get_permalink') ? todo_get_permalink($pg->ID) : $site_url . '/?p=' . $pg->ID,
                'lastmod'    => date('Y-m-d', strtotime($pg->post_modified ?: $pg->post_date)),
                'changefreq' => 'monthly',
                'priority'   => '0.6',
                'title'      => $pg->post_title,
            ];
        }

        // Categories & Tags
        foreach (self::build_taxonomy_urls('category', '0.9') as $u) $urls[] = $u;
        foreach (self::build_taxonomy_urls('tag', '0.7') as $u) $urls[] = $u;

        return $urls;
    }

    /**
     * Get excluded post/page IDs from settings
     */
    private static function get_exclude_ids() {
        return array_map('intval', array_filter(
            explode(',', get_option('seo_sitemap_exclude_ids', ''))
        ));
    }

    /**
     * Delete all sitemap files (noindex mode)
     */
    private static function clean_all_sitemap_files() {
        $patterns = [
            'sitemap*.xml',
            'sitemap*.txt',
            'sitemap*.html',
            'llms.txt',
        ];
        foreach ($patterns as $pattern) {
            $files = glob(ABSPATH . $pattern) ?: [];
            foreach ($files as $file) @unlink($file);
        }
    }

    /**
     * Remove old-style sitemap files from previous versions
     * Ensures smooth migration to the new structure
     */
    private static function clean_legacy_files() {
        // Old v2 files that are no longer used
        $legacy = glob(ABSPATH . 'sitemap-posts-[0-9]*.xml') ?: [];
        // Only remove numeric-only suffix files (e.g., sitemap-posts-1.xml),
        // keep date-based ones (e.g., sitemap-posts-2026-06.xml)
        foreach ($legacy as $file) {
            $basename = basename($file, '.xml');
            // Match old numeric pattern: sitemap-posts-N where N is just numbers, not a date
            if (preg_match('/^sitemap-posts-(\d+)$/', $basename, $m)) {
                // If it's clearly a year (2020-2099), keep it
                if ((int)$m[1] >= 2020 && (int)$m[1] <= 2099) continue;
                @unlink($file);
            }
        }
    }

    /**
     * Fast permalink builder — in-memory only, zero DB queries
     * Avoids calling todo_get_permalink() which can trigger N+1 queries
     * Supports single model (e.g., design.html) matching content.php get_permalink()
     *
     * @param object      $post      Post row (must have ID, post_name, post_date)
     * @param string      $structure Permalink structure from settings
     * @param string      $site_url  Site base URL
     * @param string|null $model     Category model key (e.g., 'single', 'article')
     * @return string
     */
    private static function build_fast_permalink($post, $structure, $site_url, $model = null) {
        // ── Single model: root-level .html page (same as content.php get_permalink) ──
        if ($model === 'single') {
            $custom = isset($post->custom_url) ? trim($post->custom_url) : '';
            if ($custom !== '' && preg_match('/^(https?:)?\/\//i', $custom)) {
                return $custom;
            }
            if ($custom !== '') {
                $path = parse_url($custom, PHP_URL_PATH) ?: $custom;
                $slug = pathinfo(trim($path, '/'), PATHINFO_FILENAME);
            } elseif (!empty($post->post_name)) {
                $slug = $post->post_name;
            } else {
                $slug = (int)$post->ID;
            }
            return $site_url . '/' . $slug . '.html';
        }

        // ── Regular article/page ──
        // Fast path: no permalink structure = query string
        if (empty($structure)) {
            return $site_url . '/?p=' . (int)$post->ID;
        }

        $ts = strtotime($post->post_date ?: date('Y-m-d H:i:s'));
        $slug = !empty($post->post_name) ? $post->post_name : ((int)$post->ID);

        $url = $structure;
        $url = str_replace('%year%', date('Y', $ts), $url);
        $url = str_replace('%monthnum%', date('m', $ts), $url);
        $url = str_replace('%day%', date('d', $ts), $url);
        $url = str_replace('%hour%', date('H', $ts), $url);
        $url = str_replace('%minute%', date('i', $ts), $url);
        $url = str_replace('%second%', date('s', $ts), $url);
        $url = str_replace('%post_id%', (int)$post->ID, $url);
        $url = str_replace('%postname%', $slug, $url);

        // Remove %category% / %breadcrumb% placeholders if unresolvable
        // (resolving category requires DB; skip for fast path)
        $url = preg_replace('#/%category%#', '', $url);
        $url = preg_replace('#/%breadcrumb%#', '', $url);

        // Normalize: remove duplicated slashes, ensure leading /
        $url = preg_replace('#/{2,}#', '/', $url);
        if (substr($url, 0, 1) !== '/') {
            $url = '/' . $url;
        }
        // Append .html suffix (matching content.php get_permalink)
        if (!preg_match('/\.html$/i', $url)) {
            $url = rtrim($url, '/') . '.html';
        }

        return $site_url . $url;
    }
}
