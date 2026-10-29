<?php
/**
 * 统一缓存管理器
 * 整合对象缓存、页面缓存、模板缓存等多种缓存机制
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 确保钩子函数已加载
if (!function_exists('todo_do_action')) {
    require_once ABSPATH . 'core/functions/todo-hooks.php';
}

// 确保日志函数已加载
if (!function_exists('todo_log_info') && !class_exists('Todo_Logger')) {
    $logger_file = ABSPATH . 'core/classes/class-logger.php';
    if (file_exists($logger_file)) {
        require_once $logger_file;
    }
}

if (!function_exists('get_cache_dir')) {
    $cache_functions_file = ABSPATH . 'core/functions/cache-functions.php';
    if (file_exists($cache_functions_file)) {
        require_once $cache_functions_file;
    }
}

class Todo_Unified_Cache_Manager
{

    /**
     * 缓存类型配置
     * @var array
     */
    private static $cache_types = array(
        'object' => array(
            'name' => '对象缓存',
            'description' => '系统对象和数据的缓存',
            'manager' => 'Todo_Cache_Manager',
            'method' => 'flush_all',
            'directory' => 'objects'
        ),
        'compiled_template' => array(
            'name' => '编译模板缓存',
            'description' => '编译引擎生成的PHP文件缓存',
            'manager' => 'CMS_Template_Compiler_V2',
            'method' => 'clear_all_cache',
            'directory' => 'compiled'
        ),
        'template_html' => array(
            'name' => '静态模板缓存',
            'description' => 'HTML模板解析生成的静态缓存',
            'function' => 'clear_template_html_cache',
            'directory' => 'templates'
        ),
        'data' => array(
            'name' => '数据缓存',
            'description' => '数据库查询结果缓存（对象缓存）',
            'function' => 'clear_data_cache',
            'directory' => 'objects'
        ),
        'php' => array(
            'name' => 'PHP编译缓存',
            'description' => 'PHP OPcache和编译缓存',
            'function' => 'clear_php_compile_cache',
            'directory' => 'php'
        ),
        'table' => array(
            'name' => '数据表缓存',
            'description' => '数据库表结构缓存',
            'function' => 'clear_table_cache',
            'directory' => 'tables'
        ),
        // 新增：翻译缓存（内存 + 数据库 language_cache）
        'translation' => array(
            'name' => '翻译缓存',
            'description' => '语言翻译缓存（内存 + 数据库 language_cache）',
            'function' => 'clear_translation_caches',
            'directory' => 'language'
        )
    );

    /**
     * 缓存预热配置
     * @var array
     */
    private static $warmup_config = array(
        'enabled' => false,
        'urls' => array(),
        'concurrent' => 3,
        'timeout' => 30
    );

    /**
     * 最近一次预热失败的原因（用于AJAX响应回传给管理界面）
     * @var string
     */
    private static $last_warmup_error = '';

    /**
     * 模板缓存依赖捕获栈
     * @var array
     */
    private static $template_cache_dependency_capture_stack = array();

    /**
     * 广告依赖索引缓存
     * @var array|null
     */
    private static $template_cache_ad_dependency_index = null;

    /**
     * 广告变更上下文，供 update_option_todo_ads_last_modified 钩子消费
     * @var array|null
     */
    private static $pending_ad_change_context = null;

    /**
     * 本次请求内已完成页面级失效的文章ID集合（请求级去重）
     * 防止 todo_update_post → clean_post_cache 与 todo_post_updated → on_content_updated
     * 等钩子链路对同一文章重复执行"清除→重建→再清除"
     * @var array
     */
    private static $request_invalidated_post_ids = array();

    /**
     * 文章更新前的旧文档属性快照（post_id => 属性ID数组）
     * 用于"取消勾选推荐"等场景：DB 写入后已读不到旧值，
     * 由 todo_update_post 在写入前登记，失效时取旧值∪新值的并集
     * @var array
     */
    private static $post_previous_doc_properties = array();

    private static function get_template_cache_ad_dependency_index_path()
    {
        $dir = ABSPATH . 'content/cache/updates/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return rtrim(str_replace('\\', '/', $dir), '/') . '/template-ad-cache-deps.json';
    }

    private static function normalize_dependency_tokens($items)
    {
        $normalized = array();
        foreach ((array) $items as $item) {
            if (is_int($item) || is_float($item) || (is_string($item) && is_numeric($item))) {
                $token = (string) intval($item);
                if ($token !== '0') {
                    $normalized[$token] = true;
                }
                continue;
            }

            $token = trim((string) $item);
            if ($token !== '') {
                $normalized[$token] = true;
            }
        }

        return array_keys($normalized);
    }

    private static function normalize_template_cache_ad_dependency_index($index)
    {
        $normalized = array(
            'files' => array(),
            'ads' => array(),
            'groups' => array(),
            'markers' => array(),
            'tokens' => array(),
        );

        if (!is_array($index)) {
            return $normalized;
        }

        foreach (array('ads', 'groups', 'markers', 'tokens') as $bucket) {
            if (empty($index[$bucket]) || !is_array($index[$bucket])) {
                continue;
            }

            foreach ($index[$bucket] as $token => $files) {
                $token = trim((string) $token);
                if ($token === '' || !is_array($files)) {
                    continue;
                }

                $normalized[$bucket][$token] = array();
                foreach ($files as $file => $flag) {
                    $file = trim((string) $file);
                    if ($file !== '') {
                        $normalized[$bucket][$token][$file] = 1;
                    }
                }
                if (empty($normalized[$bucket][$token])) {
                    unset($normalized[$bucket][$token]);
                }
            }
        }

        if (!empty($index['files']) && is_array($index['files'])) {
            foreach ($index['files'] as $file => $deps) {
                $file = trim((string) $file);
                if ($file === '') {
                    continue;
                }

                $normalized['files'][$file] = array(
                    'ads' => self::normalize_dependency_tokens($deps['ads'] ?? array()),
                    'groups' => self::normalize_dependency_tokens($deps['groups'] ?? array()),
                    'markers' => self::normalize_dependency_tokens($deps['markers'] ?? array()),
                    'tokens' => self::normalize_dependency_tokens($deps['tokens'] ?? array()),
                );
            }
        }

        return $normalized;
    }

    private static function load_template_cache_ad_dependency_index()
    {
        if (self::$template_cache_ad_dependency_index !== null) {
            return self::$template_cache_ad_dependency_index;
        }

        $path = self::get_template_cache_ad_dependency_index_path();
        $index = array();
        if (file_exists($path)) {
            $raw = @file_get_contents($path);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $index = $decoded;
                }
            }
        }

        $index = self::normalize_template_cache_ad_dependency_index($index);
        $changed = self::cleanup_template_cache_ad_dependency_index($index);
        self::$template_cache_ad_dependency_index = $index;

        if ($changed) {
            self::save_template_cache_ad_dependency_index($index);
        }

        return self::$template_cache_ad_dependency_index;
    }

    public static function is_template_cache_file_ad_dependent($cache_file)
    {
        $cache_file = trim((string) $cache_file);
        if ($cache_file === '') {
            return false;
        }

        $index = self::load_template_cache_ad_dependency_index();
        $deps = $index['files'][$cache_file] ?? null;
        if (!is_array($deps)) {
            return false;
        }

        return !empty($deps['ads']) || !empty($deps['groups']) || !empty($deps['markers']);
    }

    private static function save_template_cache_ad_dependency_index($index)
    {
        $index = self::normalize_template_cache_ad_dependency_index($index);
        self::$template_cache_ad_dependency_index = $index;

        $path = self::get_template_cache_ad_dependency_index_path();
        @file_put_contents($path, json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function reset_template_cache_ad_dependency_index()
    {
        self::$template_cache_ad_dependency_index = array(
            'files' => array(),
            'ads' => array(),
            'groups' => array(),
            'markers' => array(),
            'tokens' => array(),
        );

        $path = self::get_template_cache_ad_dependency_index_path();
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    private static function cleanup_template_cache_ad_dependency_index(&$index)
    {
        $changed = false;
        if (empty($index['files']) || !is_array($index['files'])) {
            return false;
        }

        foreach (array_keys($index['files']) as $file) {
            if (is_string($file) && $file !== '' && file_exists($file)) {
                continue;
            }

            self::remove_template_cache_ad_dependency_record($index, $file);
            $changed = true;
        }

        return $changed;
    }

    private static function remove_template_cache_ad_dependency_record(&$index, $cache_file)
    {
        $cache_file = trim((string) $cache_file);
        if ($cache_file === '' || empty($index['files'][$cache_file])) {
            return;
        }

        $existing = $index['files'][$cache_file];
        foreach (array('ads', 'groups', 'markers', 'tokens') as $bucket) {
            foreach ((array) ($existing[$bucket] ?? array()) as $token) {
                $token = trim((string) $token);
                if ($token === '' || empty($index[$bucket][$token][$cache_file])) {
                    continue;
                }
                unset($index[$bucket][$token][$cache_file]);
                if (empty($index[$bucket][$token])) {
                    unset($index[$bucket][$token]);
                }
            }
        }

        unset($index['files'][$cache_file]);
    }

    private static function store_template_cache_ad_dependencies($cache_file, $deps)
    {
        $cache_file = trim((string) $cache_file);
        if ($cache_file === '') {
            return;
        }

        $index = self::load_template_cache_ad_dependency_index();
        self::remove_template_cache_ad_dependency_record($index, $cache_file);

        $ads = self::normalize_dependency_tokens($deps['ads'] ?? array());
        $groups = self::normalize_dependency_tokens($deps['groups'] ?? array());
        $markers = self::normalize_dependency_tokens($deps['markers'] ?? array());
        $tokens = self::normalize_dependency_tokens($deps['tokens'] ?? array());

        if (empty($ads) && empty($groups) && empty($markers) && empty($tokens)) {
            self::save_template_cache_ad_dependency_index($index);
            return;
        }

        $index['files'][$cache_file] = array(
            'ads' => $ads,
            'groups' => $groups,
            'markers' => $markers,
            'tokens' => $tokens,
        );

        foreach ($ads as $token) {
            if (!isset($index['ads'][$token])) {
                $index['ads'][$token] = array();
            }
            $index['ads'][$token][$cache_file] = 1;
        }

        foreach ($groups as $token) {
            if (!isset($index['groups'][$token])) {
                $index['groups'][$token] = array();
            }
            $index['groups'][$token][$cache_file] = 1;
        }

        foreach ($markers as $token) {
            if (!isset($index['markers'][$token])) {
                $index['markers'][$token] = array();
            }
            $index['markers'][$token][$cache_file] = 1;
        }

        foreach ($tokens as $token) {
            if (!isset($index['tokens'][$token])) {
                $index['tokens'][$token] = array();
            }
            $index['tokens'][$token][$cache_file] = 1;
        }

        self::save_template_cache_ad_dependency_index($index);
    }

    public static function begin_template_cache_dependency_capture($cache_file)
    {
        $cache_file = trim((string) $cache_file);
        if ($cache_file === '') {
            return;
        }

        self::$template_cache_dependency_capture_stack[] = array(
            'cache_file' => $cache_file,
            'ads' => array(),
            'groups' => array(),
            'markers' => array(),
            'tokens' => array(),
        );
    }

    public static function capture_template_cache_ad_dependency($ad_id = 0, $group_id = 0, $marker = '')
    {
        if (empty(self::$template_cache_dependency_capture_stack)) {
            return;
        }

        $last_index = count(self::$template_cache_dependency_capture_stack) - 1;
        if ($ad_id) {
            self::$template_cache_dependency_capture_stack[$last_index]['ads'][(string) intval($ad_id)] = true;
        }
        if ($group_id) {
            self::$template_cache_dependency_capture_stack[$last_index]['groups'][(string) intval($group_id)] = true;
        }

        $marker = trim((string) $marker);
        if ($marker !== '') {
            self::$template_cache_dependency_capture_stack[$last_index]['markers'][$marker] = true;
        }
    }

    public static function capture_template_cache_dependency_token($token = '')
    {
        if (empty(self::$template_cache_dependency_capture_stack)) {
            return;
        }

        $token = trim((string) $token);
        if ($token === '') {
            return;
        }

        $last_index = count(self::$template_cache_dependency_capture_stack) - 1;
        self::$template_cache_dependency_capture_stack[$last_index]['tokens'][$token] = true;
    }

    public static function finish_template_cache_dependency_capture($cache_file, $persist = true)
    {
        $deps = array(
            'ads' => array(),
            'groups' => array(),
            'markers' => array(),
            'tokens' => array(),
        );

        if (empty(self::$template_cache_dependency_capture_stack)) {
            return $deps;
        }

        $context = array_pop(self::$template_cache_dependency_capture_stack);
        $deps['ads'] = array_keys((array) ($context['ads'] ?? array()));
        $deps['groups'] = array_keys((array) ($context['groups'] ?? array()));
        $deps['markers'] = array_keys((array) ($context['markers'] ?? array()));
        $deps['tokens'] = array_keys((array) ($context['tokens'] ?? array()));

        if ($persist) {
            self::store_template_cache_ad_dependencies($cache_file, $deps);
        }

        return $deps;
    }

    public static function set_pending_ad_change_context($context)
    {
        self::$pending_ad_change_context = array(
            'ad_ids' => self::normalize_dependency_tokens($context['ad_ids'] ?? array()),
            'group_ids' => self::normalize_dependency_tokens($context['group_ids'] ?? array()),
            'markers' => self::normalize_dependency_tokens($context['markers'] ?? array()),
        );
    }

    private static function consume_pending_ad_change_context()
    {
        $context = self::$pending_ad_change_context;
        self::$pending_ad_change_context = null;
        return is_array($context) ? $context : null;
    }

    public static function clear_template_html_cache_by_ad_dependencies($ad_ids = array(), $group_ids = array(), $markers = array())
    {
        $scope = self::expand_template_cache_ad_dependency_scope($ad_ids, $group_ids, $markers);
        $ad_ids = $scope['ad_ids'];
        $group_ids = $scope['group_ids'];
        $markers = $scope['markers'];

        $index = self::load_template_cache_ad_dependency_index();
        $indexed_files = !empty($index['files']) && is_array($index['files']) ? count($index['files']) : 0;
        $cache_files = array();

        foreach ($ad_ids as $token) {
            foreach ((array) ($index['ads'][$token] ?? array()) as $file => $flag) {
                $cache_files[$file] = true;
            }
        }

        foreach ($group_ids as $token) {
            foreach ((array) ($index['groups'][$token] ?? array()) as $file => $flag) {
                $cache_files[$file] = true;
            }
        }

        foreach ($markers as $token) {
            foreach ((array) ($index['markers'][$token] ?? array()) as $file => $flag) {
                $cache_files[$file] = true;
            }
        }

        $removed = 0;
        foreach (array_keys($cache_files) as $cache_file) {
            if (file_exists($cache_file) && @unlink($cache_file)) {
                $removed++;
            }
            self::remove_template_cache_ad_dependency_record($index, $cache_file);
        }

        self::save_template_cache_ad_dependency_index($index);

        if ($removed > 0 && class_exists('Todo_Template_Result_Cache')) {
            Todo_Template_Result_Cache::clear();
        }

        return array(
            'indexed_files' => $indexed_files,
            'matched_files' => count($cache_files),
            'removed_files' => $removed,
            'ad_ids' => $ad_ids,
            'group_ids' => $group_ids,
            'markers' => $markers,
        );
    }

    public static function clear_template_html_cache_by_dependency_tokens($tokens = array())
    {
        $tokens = self::normalize_dependency_tokens($tokens);
        $index = self::load_template_cache_ad_dependency_index();
        $indexed_files = !empty($index['files']) && is_array($index['files']) ? count($index['files']) : 0;
        $cache_files = array();

        foreach ($tokens as $token) {
            foreach ((array) ($index['tokens'][$token] ?? array()) as $file => $flag) {
                $cache_files[$file] = true;
            }
        }

        $removed = 0;
        foreach (array_keys($cache_files) as $cache_file) {
            if (file_exists($cache_file) && @unlink($cache_file)) {
                $removed++;
            }
            self::remove_template_cache_ad_dependency_record($index, $cache_file);
        }

        self::save_template_cache_ad_dependency_index($index);

        if ($removed > 0 && class_exists('Todo_Template_Result_Cache')) {
            Todo_Template_Result_Cache::clear();
        }

        return array(
            'indexed_files' => $indexed_files,
            'matched_files' => count($cache_files),
            'removed_files' => $removed,
            'tokens' => $tokens,
        );
    }

    private static function get_current_default_single_ad_id($exclude_ad_ids = array())
    {
        $exclude_lookup = array_fill_keys(self::normalize_dependency_tokens($exclude_ad_ids), true);
        if (!class_exists('Todo_Ads_Manager') || !method_exists('Todo_Ads_Manager', 'get_ads')) {
            return 0;
        }

        $ads = Todo_Ads_Manager::get_ads(array(
            'status' => 'active',
            'active_only' => true,
            'order_by' => 'sort_order ASC, id ASC',
        ));

        foreach ((array) $ads as $ad) {
            $ad = is_object($ad) ? (array) $ad : (array) $ad;
            $ad_id = !empty($ad['id']) ? (string) intval($ad['id']) : '';
            if ($ad_id !== '' && !isset($exclude_lookup[$ad_id])) {
                return intval($ad_id);
            }
        }

        return 0;
    }

    private static function get_current_default_group_id($exclude_group_ids = array())
    {
        $exclude_lookup = array_fill_keys(self::normalize_dependency_tokens($exclude_group_ids), true);
        if (!class_exists('Todo_Ads_Manager') || !method_exists('Todo_Ads_Manager', 'get_ad_groups')) {
            return 0;
        }

        $groups = Todo_Ads_Manager::get_ad_groups(array(
            'status' => 'active',
            'order_by' => 'sort_order ASC, id ASC',
        ));

        foreach ((array) $groups as $group) {
            $group = is_object($group) ? (array) $group : (array) $group;
            $group_id = !empty($group['id']) ? (string) intval($group['id']) : '';
            if ($group_id !== '' && !isset($exclude_lookup[$group_id])) {
                return intval($group_id);
            }
        }

        return 0;
    }

    private static function expand_template_cache_ad_dependency_scope($ad_ids = array(), $group_ids = array(), $markers = array())
    {
        $ad_ids = self::normalize_dependency_tokens($ad_ids);
        $group_ids = self::normalize_dependency_tokens($group_ids);
        $markers = self::normalize_dependency_tokens($markers);

        $resolved_markers = array();
        $marker_lookup = array_fill_keys($markers, true);

        if (isset($marker_lookup['single_ads_default'])) {
            $current_default_ad_id = self::get_current_default_single_ad_id();
            $fallback_default_ad_id = self::get_current_default_single_ad_id($ad_ids);

            if ($current_default_ad_id > 0) {
                $ad_ids[] = (string) $current_default_ad_id;
            }
            if ($fallback_default_ad_id > 0) {
                $ad_ids[] = (string) $fallback_default_ad_id;
            }

            // 仅在“默认位为空”的一侧存在时保留 marker，避免把所有默认位页面都清掉。
            if ($current_default_ad_id <= 0 || $fallback_default_ad_id <= 0) {
                $resolved_markers[] = 'single_ads_default';
            }

            unset($marker_lookup['single_ads_default']);
        }

        if (isset($marker_lookup['group_ads_default'])) {
            $current_default_group_id = self::get_current_default_group_id();
            $fallback_default_group_id = self::get_current_default_group_id($group_ids);

            if ($current_default_group_id > 0) {
                $group_ids[] = (string) $current_default_group_id;
            }
            if ($fallback_default_group_id > 0) {
                $group_ids[] = (string) $fallback_default_group_id;
            }

            // 默认分组从“有”变“无”或从“无”变“有”时，才需要命中空默认位缓存。
            if ($current_default_group_id <= 0 || $fallback_default_group_id <= 0) {
                $resolved_markers[] = 'group_ads_default';
            }

            unset($marker_lookup['group_ads_default']);
        }

        foreach (array_keys($marker_lookup) as $marker) {
            $resolved_markers[] = $marker;
        }

        return array(
            'ad_ids' => self::normalize_dependency_tokens($ad_ids),
            'group_ids' => self::normalize_dependency_tokens($group_ids),
            'markers' => self::normalize_dependency_tokens($resolved_markers),
        );
    }

    public static function find_changed_ad_dependencies($since_ts, $until_ts = null)
    {
        global $tododb;

        $result = array(
            'ad_ids' => array(),
            'group_ids' => array(),
            'markers' => array(),
        );

        if (!$tododb) {
            return $result;
        }

        $since_ts = intval($since_ts);
        $until_ts = $until_ts === null ? time() : intval($until_ts);
        if ($since_ts <= 0 || $until_ts <= $since_ts) {
            return $result;
        }

        $ads_table = (defined('TABLE_PREFIX') ? TABLE_PREFIX : $tododb->prefix) . 'ads';
        $groups_table = (defined('TABLE_PREFIX') ? TABLE_PREFIX : $tododb->prefix) . 'ad_groups';

        $since_sql = date('Y-m-d H:i:s', $since_ts);
        $until_sql = date('Y-m-d H:i:s', $until_ts);

        try {
            $group_selection_changed = false;
            $ads = $tododb->get_results(
                "SELECT id, group_id FROM {$ads_table}
                 WHERE (updated_at IS NOT NULL AND updated_at > ? AND updated_at <= ?)
                    OR (created_at IS NOT NULL AND created_at > ? AND created_at <= ?)
                    OR (start_date IS NOT NULL AND start_date > ? AND start_date <= ?)
                    OR (end_date IS NOT NULL AND end_date > ? AND end_date <= ?)
                    OR (
                        status = 1
                        AND start_time IS NOT NULL
                        AND TIMESTAMP(CURDATE(), start_time) > ?
                        AND TIMESTAMP(CURDATE(), start_time) <= ?
                        AND (start_date IS NULL OR start_date <= CURDATE())
                        AND (end_date IS NULL OR end_date >= CURDATE())
                    )
                    OR (
                        status = 1
                        AND end_time IS NOT NULL
                        AND TIMESTAMP(CURDATE(), end_time) > ?
                        AND TIMESTAMP(CURDATE(), end_time) <= ?
                        AND (start_date IS NULL OR start_date <= CURDATE())
                        AND (end_date IS NULL OR end_date >= CURDATE())
                    )",
                array(
                    $since_sql, $until_sql,
                    $since_sql, $until_sql,
                    $since_sql, $until_sql,
                    $since_sql, $until_sql,
                    $since_sql, $until_sql,
                    $since_sql, $until_sql,
                ),
                'ARRAY_A'
            );

            foreach ((array) $ads as $ad) {
                if (!empty($ad['id'])) {
                    $result['ad_ids'][] = (string) intval($ad['id']);
                }
                if (!empty($ad['group_id'])) {
                    $result['group_ids'][] = (string) intval($ad['group_id']);
                }
            }

            $groups = $tododb->get_results(
                "SELECT id FROM {$groups_table} WHERE updated_at IS NOT NULL AND updated_at > ? AND updated_at <= ?",
                array($since_sql, $until_sql),
                'ARRAY_A'
            );
            foreach ((array) $groups as $group) {
                if (!empty($group['id'])) {
                    $result['group_ids'][] = (string) intval($group['id']);
                    $group_selection_changed = true;
                }
            }
        } catch (Throwable $e) {
            self::log_warning('Failed to diff ad cache dependencies', array('error' => $e->getMessage()));
            return $result;
        }

        if (!empty($result['ad_ids'])) {
            $result['markers'][] = 'single_ads_default';
        }
        if ($group_selection_changed) {
            $result['markers'][] = 'group_ads_default';
        }

        $result['ad_ids'] = self::normalize_dependency_tokens($result['ad_ids']);
        $result['group_ids'] = self::normalize_dependency_tokens($result['group_ids']);
        $result['markers'] = self::normalize_dependency_tokens($result['markers']);

        return $result;
    }

    private static function did_timed_boundary_cross_window($boundary_ts, $since_ts, $until_ts)
    {
        $boundary_ts = intval($boundary_ts);
        $since_ts = intval($since_ts);
        $until_ts = intval($until_ts);

        return $boundary_ts > 0 && $boundary_ts > $since_ts && $boundary_ts <= $until_ts;
    }

    /** 单次窗口内变更文章数超过该值时，不再逐篇清缓存，改为整体失效 */
    const CHANGED_POSTS_BULK_THRESHOLD = 500;

    public static function find_changed_post_ids($since_ts, $until_ts = null)
    {
        global $tododb;

        $result = array();
        if (!$tododb) {
            return $result;
        }

        $since_ts = intval($since_ts);
        $until_ts = $until_ts === null ? time() : intval($until_ts);
        if ($since_ts <= 0 || $until_ts <= $since_ts) {
            return $result;
        }

        $posts_table = (defined('TABLE_PREFIX') ? TABLE_PREFIX : $tododb->prefix) . 'posts';
        $since_sql = date('Y-m-d H:i:s', $since_ts);
        $until_sql = date('Y-m-d H:i:s', $until_ts);

        try {
            // UNION 让两个时间条件各自走索引；LIMIT 封顶，超出阈值由调用方走整体失效
            $rows = $tododb->get_results(
                "(SELECT ID FROM {$posts_table}
                   WHERE post_status = 'publish' AND post_modified > ? AND post_modified <= ?)
                 UNION
                 (SELECT ID FROM {$posts_table}
                   WHERE post_status = 'publish' AND post_date > ? AND post_date <= ?)
                 LIMIT " . (self::CHANGED_POSTS_BULK_THRESHOLD + 1),
                array($since_sql, $until_sql, $since_sql, $until_sql),
                'ARRAY_A'
            );

            foreach ((array) $rows as $row) {
                if (!empty($row['ID'])) {
                    $result[] = (int) $row['ID'];
                }
            }
        } catch (Throwable $e) {
            self::log_warning('Failed to diff changed posts for template cache invalidation', array('error' => $e->getMessage()));
            return array();
        }

        $result = array_values(array_unique(array_filter(array_map('intval', $result))));
        sort($result, SORT_NUMERIC);

        return $result;
    }

    public static function find_changed_category_visibility_ids($since_ts, $until_ts = null)
    {
        global $tododb;

        $result = array();
        if (!$tododb) {
            return $result;
        }

        $since_ts = intval($since_ts);
        $until_ts = $until_ts === null ? time() : intval($until_ts);
        if ($since_ts <= 0 || $until_ts <= $since_ts) {
            return $result;
        }

        $term_attributes_table = (defined('TABLE_PREFIX') ? TABLE_PREFIX : $tododb->prefix) . 'term_attributes';

        try {
            $rows = $tododb->get_results(
                "SELECT term_id, attributes FROM {$term_attributes_table}
                 WHERE attributes LIKE ? OR attributes LIKE ?",
                array('%hidden_start%', '%hidden_end%'),
                'ARRAY_A'
            );

            foreach ((array) $rows as $row) {
                $term_id = !empty($row['term_id']) ? (int) $row['term_id'] : 0;
                if ($term_id <= 0 || empty($row['attributes'])) {
                    continue;
                }

                $attributes = json_decode((string) $row['attributes'], true);
                if (!is_array($attributes)) {
                    continue;
                }

                $start_ts = !empty($attributes['hidden_start']) ? strtotime((string) $attributes['hidden_start']) : 0;
                $end_ts = !empty($attributes['hidden_end']) ? strtotime((string) $attributes['hidden_end']) : 0;

                if (self::did_timed_boundary_cross_window($start_ts, $since_ts, $until_ts)
                    || self::did_timed_boundary_cross_window($end_ts, $since_ts, $until_ts)) {
                    $result[] = $term_id;
                }
            }
        } catch (Throwable $e) {
            self::log_warning('Failed to diff category visibility time windows for template cache invalidation', array('error' => $e->getMessage()));
            return array();
        }

        $result = array_values(array_unique(array_filter(array_map('intval', $result))));
        sort($result, SORT_NUMERIC);

        return $result;
    }

    public static function find_changed_coupon_ids($since_ts, $until_ts = null)
    {
        global $tododb;

        $result = array();
        if (!$tododb) {
            return $result;
        }

        $since_ts = intval($since_ts);
        $until_ts = $until_ts === null ? time() : intval($until_ts);
        if ($since_ts <= 0 || $until_ts <= $since_ts) {
            return $result;
        }

        $coupons_table = (defined('TABLE_PREFIX') ? TABLE_PREFIX : $tododb->prefix) . 'coupons';
        $since_sql = date('Y-m-d H:i:s', $since_ts);
        $until_sql = date('Y-m-d H:i:s', $until_ts);

        try {
            $rows = $tododb->get_results(
                "SELECT id, start_date, end_date, status
                 FROM {$coupons_table}
                 WHERE (updated_at IS NOT NULL AND updated_at > ? AND updated_at <= ?)
                    OR (created_at IS NOT NULL AND created_at > ? AND created_at <= ?)
                    OR (
                        status = 1
                        AND NULLIF(TRIM(CAST(start_date AS CHAR)), '') IS NOT NULL
                        AND CAST(start_date AS CHAR) > ?
                        AND CAST(start_date AS CHAR) <= ?
                    )
                    OR (
                        status = 1
                        AND NULLIF(TRIM(CAST(end_date AS CHAR)), '') IS NOT NULL
                        AND CAST(end_date AS CHAR) > ?
                        AND CAST(end_date AS CHAR) <= ?
                    )",
                array($since_sql, $until_sql, $since_sql, $until_sql, $since_sql, $until_sql, $since_sql, $until_sql),
                'ARRAY_A'
            );

            foreach ((array) $rows as $row) {
                if (!empty($row['id'])) {
                    $result[] = (int) $row['id'];
                }
            }
        } catch (Throwable $e) {
            self::log_warning('Failed to diff coupon time windows for cache invalidation', array('error' => $e->getMessage()));
            return array();
        }

        $result = array_values(array_unique(array_filter(array_map('intval', $result))));
        sort($result, SORT_NUMERIC);

        return $result;
    }

    private static function invalidate_category_visibility_cache($category_id, $regenerate = false)
    {
        $category_id = intval($category_id);
        if ($category_id <= 0) {
            return false;
        }

        $is_admin_ctx = (defined('IS_ADMIN') && IS_ADMIN);
        $effective_regenerate = $regenerate && !$is_admin_ctx;

        self::precise_clear_home_cache();
        self::precise_clear_category_cache($category_id);

        if ($effective_regenerate) {
            self::precise_regenerate_home_cache();
            self::precise_regenerate_category_cache($category_id);
        }

        return true;
    }

    private static function get_timed_invalidation_provider_definitions()
    {
        return array(
            'post' => array(
                'scope' => 'public_content',
                'description' => 'Public posts and their derived listing pages.',
            ),
            'category_visibility' => array(
                'scope' => 'public_content',
                'description' => 'Public category visibility windows and dependent listing pages.',
            ),
            'coupon' => array(
                'scope' => 'public_content',
                'description' => 'Public coupon availability and shared coupon data responses.',
            ),
            'ad' => array(
                'scope' => 'public_content',
                'description' => 'Public ad scheduling and template ad dependencies.',
            ),
            'member_level_expiry' => array(
                'scope' => 'private_user_state',
                'description' => 'User-specific membership expiry; must not be invalidated via shared async-check.',
            ),
            'gift_card_expiry' => array(
                'scope' => 'private_user_state',
                'description' => 'User-specific gift card expiry; must not be invalidated via shared async-check.',
            ),
            'session_expiry' => array(
                'scope' => 'private_user_state',
                'description' => 'Per-user/session state; handled by auth/session lifecycle instead of page cache invalidation.',
            ),
        );
    }

    public static function get_public_timed_invalidation_provider_names()
    {
        $providers = array();
        foreach (self::get_timed_invalidation_provider_definitions() as $name => $definition) {
            if (($definition['scope'] ?? '') === 'public_content') {
                $providers[] = $name;
            }
        }
        return $providers;
    }

    public static function get_private_timed_invalidation_provider_names()
    {
        $providers = array();
        foreach (self::get_timed_invalidation_provider_definitions() as $name => $definition) {
            if (($definition['scope'] ?? '') === 'private_user_state') {
                $providers[] = $name;
            }
        }
        return $providers;
    }

    private static function mark_public_timed_invalidation_result(&$result, $provider_name)
    {
        $provider_name = trim((string) $provider_name);
        if ($provider_name === '') {
            return;
        }

        if (!isset($result['providers']) || !is_array($result['providers'])) {
            $result['providers'] = array();
        }
        if (!in_array($provider_name, $result['providers'], true)) {
            $result['providers'][] = $provider_name;
        }
    }

    public static function process_due_public_template_cache_invalidations($since_ts, $until_ts = null)
    {
        $result = array(
            'changed' => false,
            'cleared' => false,
            'full_clear' => false,
            'post_ids' => array(),
            'category_ids' => array(),
            'coupon_ids' => array(),
            'ad_ids' => array(),
            'group_ids' => array(),
            'markers' => array(),
            'matched_files' => 0,
            'removed_files' => 0,
            'reasons' => array(),
            'providers' => array(),
            'private_providers' => self::get_private_timed_invalidation_provider_names(),
        );

        $since_ts = intval($since_ts);
        $until_ts = $until_ts === null ? time() : intval($until_ts);
        if ($since_ts <= 0 || $until_ts <= $since_ts) {
            return $result;
        }

        $post_ids = self::find_changed_post_ids($since_ts, $until_ts);
        if (count($post_ids) > self::CHANGED_POSTS_BULK_THRESHOLD) {
            // 大批量变更（批量导入/造数据等）：逐篇清缓存会超时，直接整体失效模板缓存
            self::clear_cache('template_html');
            if (class_exists('Todo_Template_Result_Cache')) {
                Todo_Template_Result_Cache::clear();
            }
            $result['changed'] = true;
            $result['cleared'] = true;
            $result['full_clear'] = true;
            $result['reasons'][] = 'post_changed_bulk';
            self::mark_public_timed_invalidation_result($result, 'post');
            return $result;
        }
        if (!empty($post_ids)) {
            foreach ($post_ids as $post_id) {
                self::clean_post_cache((int) $post_id, false);
            }
            $result['changed'] = true;
            $result['cleared'] = true;
            $result['post_ids'] = $post_ids;
            $result['reasons'][] = 'post_changed';
            self::mark_public_timed_invalidation_result($result, 'post');
        }

        $category_ids = self::find_changed_category_visibility_ids($since_ts, $until_ts);
        if (!empty($category_ids)) {
            $category_tokens = array();
            foreach ($category_ids as $category_id) {
                $category_tokens[] = 'term_visibility:' . intval($category_id);
            }

            $token_result = self::clear_template_html_cache_by_dependency_tokens($category_tokens);
            foreach ($category_ids as $category_id) {
                self::invalidate_category_visibility_cache((int) $category_id, false);
            }

            if (function_exists('clear_data_cache')) {
                clear_data_cache();
            } else {
                self::clear_cache('data');
            }

            if (class_exists('Todo_Template_Result_Cache')) {
                Todo_Template_Result_Cache::clear();
            }

            $result['changed'] = true;
            $result['cleared'] = true;
            $result['category_ids'] = $category_ids;
            $result['matched_files'] += (int) ($token_result['matched_files'] ?? 0);
            $result['removed_files'] += (int) ($token_result['removed_files'] ?? 0);
            $result['reasons'][] = 'category_visibility_changed';
            self::mark_public_timed_invalidation_result($result, 'category_visibility');
        }

        $coupon_ids = self::find_changed_coupon_ids($since_ts, $until_ts);
        if (!empty($coupon_ids)) {
            if (function_exists('clear_data_cache')) {
                clear_data_cache();
            } else {
                self::clear_cache('data');
            }

            $result['changed'] = true;
            $result['cleared'] = true;
            $result['coupon_ids'] = $coupon_ids;
            $result['reasons'][] = 'coupon_changed';
            self::mark_public_timed_invalidation_result($result, 'coupon');
        }

        $deps = self::find_changed_ad_dependencies($since_ts, $until_ts);
        if (!empty($deps['ad_ids']) || !empty($deps['group_ids']) || !empty($deps['markers'])) {
            $ad_result = self::clear_template_html_cache_by_ad_dependencies(
                $deps['ad_ids'] ?? array(),
                $deps['group_ids'] ?? array(),
                $deps['markers'] ?? array()
            );

            if (empty($ad_result) || ((($ad_result['matched_files'] ?? 0) === 0) && (($ad_result['indexed_files'] ?? 0) === 0))) {
                self::clear_cache('template_html');
                if (class_exists('Todo_Template_Result_Cache')) {
                    Todo_Template_Result_Cache::clear();
                }

                $result['changed'] = true;
                $result['cleared'] = true;
                $result['full_clear'] = true;
                $result['ad_ids'] = $deps['ad_ids'] ?? array();
                $result['group_ids'] = $deps['group_ids'] ?? array();
                $result['markers'] = $deps['markers'] ?? array();
                $result['reasons'][] = 'ad_changed_fallback_full';
                self::mark_public_timed_invalidation_result($result, 'ad');

                return $result;
            }

            $result['changed'] = true;
            $result['cleared'] = true;
            $result['ad_ids'] = $ad_result['ad_ids'] ?? ($deps['ad_ids'] ?? array());
            $result['group_ids'] = $ad_result['group_ids'] ?? ($deps['group_ids'] ?? array());
            $result['markers'] = $ad_result['markers'] ?? ($deps['markers'] ?? array());
            $result['matched_files'] += (int) ($ad_result['matched_files'] ?? 0);
            $result['removed_files'] += (int) ($ad_result['removed_files'] ?? 0);
            $result['reasons'][] = 'ad_changed';
            self::mark_public_timed_invalidation_result($result, 'ad');
        }

        return $result;
    }

    public static function process_due_template_cache_invalidations($since_ts, $until_ts = null)
    {
        // Backward-compatible wrapper. Shared async-check must only consume public-content providers.
        return self::process_due_public_template_cache_invalidations($since_ts, $until_ts);
    }

    private static function resolve_category_template_path($category_id = 0)
    {
        $template_path = '';
        $theme_path = function_exists('get_template_directory') ? get_template_directory() : '';

        if ($category_id > 0 && $theme_path !== '' && class_exists('Todo_Model_Manager')) {
            $model_key = Todo_Model_Manager::get_category_model($category_id);
            $model_class = Todo_Model_Manager::get_model_class($model_key);
            if ($model_class && property_exists($model_class, 'list_template')) {
                $list_template = $model_class::$list_template;
                $candidates = array($list_template);
                if ($list_template && !preg_match('/\.(html?|php)$/i', $list_template)) {
                    $candidates[] = $list_template . '.html';
                }
                foreach ($candidates as $candidate) {
                    if (!$candidate) {
                        continue;
                    }
                    $full_path = $theme_path . '/' . $candidate;
                    if (file_exists($full_path)) {
                        $template_path = $full_path;
                        break;
                    }
                }
            }
        }

        if ($template_path === '' && $category_id > 0 && $theme_path !== '' && class_exists('Todo_Model_Manager')) {
            global $tododb;
            $model_key = Todo_Model_Manager::get_category_model($category_id);
            if ($model_key && isset($tododb)) {
                $list_template = $tododb->get_var(
                    "SELECT list_template FROM " . TABLE_PREFIX . "models WHERE model_key = ?",
                    array($model_key)
                );
                if ($list_template) {
                    $candidates = array($list_template);
                    if (!preg_match('/\.(html?|php)$/i', $list_template)) {
                        $candidates[] = $list_template . '.html';
                    }
                    foreach ($candidates as $candidate) {
                        if (!$candidate) {
                            continue;
                        }
                        $full_path = $theme_path . '/' . $candidate;
                        if (file_exists($full_path)) {
                            $template_path = $full_path;
                            break;
                        }
                    }
                }
            }
        }

        if ($template_path === '' && function_exists('get_template_file_path')) {
            $template_path = get_template_file_path('category') ?: get_template_file_path('archive');
        }

        return $template_path;
    }

    /**
     * 识别 single 模型分类对应的真实单篇内容。
     * 这类分类前台会直接落到 single 页面，缓存预热也必须保持一致。
     *
     * @param int $category_id
     * @return int
     */
    private static function resolve_single_category_post_id($category_id = 0)
    {
        $category_id = (int) $category_id;
        if ($category_id <= 0 || !class_exists('Todo_Model_Manager')) {
            return 0;
        }

        $model_key = Todo_Model_Manager::get_category_model($category_id);
        if ($model_key !== 'single' || !function_exists('todo_get_single_category_post_id')) {
            return 0;
        }

        return (int) todo_get_single_category_post_id($category_id, 'frontend');
    }

    /**
     * 自动清理配置
     * @var array
     */
    private static $auto_cleanup_config = array(
        'enabled' => false,
        'schedule' => 'daily',
        'max_age' => 86400 * 7, // 7天
        'max_size' => 100 * 1024 * 1024 // 100MB
    );

    /**
     * 初始化统一缓存管理器
     * 
     * @param array $config 配置参数
     */
    public static function init($config = array())
    {
        // 合并配置
        if (isset($config['warmup'])) {
            self::$warmup_config = array_merge(self::$warmup_config, $config['warmup']);
        }

        if (isset($config['auto_cleanup'])) {
            self::$auto_cleanup_config = array_merge(self::$auto_cleanup_config, $config['auto_cleanup']);
        }

        // 注册自动清理任务
        if (self::$auto_cleanup_config['enabled']) {
            self::schedule_auto_cleanup();
        }

        // 注册钩子
        self::register_hooks();

        if (defined('TODO_DEBUG') && TODO_DEBUG) {
            self::log_info('Unified cache manager initialized');
        }
    }

    /**
     * 安全地记录信息日志
     * 
     * @param string $message 日志消息
     * @param array $context 上下文信息
     */
    private static function log_info($message, $context = [])
    {
        if (function_exists('todo_log_info')) {
            todo_log_info($message, $context);
        } elseif (function_exists('todo_log')) {
            todo_log($message, 'info', $context);
        }
    }

    /**
     * 安全地记录警告日志
     * 
     * @param string $message 日志消息
     * @param array $context 上下文信息
     */
    private static function log_warning($message, $context = [])
    {
        if (function_exists('todo_log_warning')) {
            todo_log_warning($message, $context);
        } elseif (function_exists('todo_log')) {
            todo_log($message, 'warning', $context);
        }
    }

    /**
     * 安全地记录错误日志
     * 
     * @param string $message 日志消息
     * @param array $context 上下文信息
     */
    private static function log_error($message, $context = [])
    {
        if (function_exists('todo_log_error')) {
            todo_log_error($message, $context);
        } elseif (function_exists('todo_log')) {
            todo_log($message, 'error', $context);
        }
    }

    /**
     * 清理指定类型的缓存
     * 
     * @param string $type 缓存类型
     * @return bool 是否成功
     */
    public static function clear_cache($type)
    {
        if (!isset(self::$cache_types[$type])) {
            todo_log_warning('Unknown cache type: ' . $type);
            return false;
        }

        $config = self::$cache_types[$type];
        $success = false;
        $started_at = microtime(true);
        $stats_before = null;
        $stats_after = null;
        $should_detail_log = defined('IS_ADMIN') && IS_ADMIN;
        if ($should_detail_log) {
            $stats_before = self::get_cache_statistics($type);
        }

        try {
            // 使用管理器类方法
            if (isset($config['manager']) && isset($config['method'])) {
                if (class_exists($config['manager'])) {
                    $manager = $config['manager'];
                    $method = $config['method'];

                    $success = $manager::$method();
                }
            }
            // 使用函数
            elseif (isset($config['function'])) {
                $function = $config['function'];
                if (function_exists($function)) {
                    $success = call_user_func($function);
                }
            }

            if ($success) {
                if ($type === 'template_html') {
                    self::reset_template_cache_ad_dependency_index();
                }
                if ($should_detail_log) {
                    $stats_after = self::get_cache_statistics($type);
                }
                $duration_ms = (int) round((microtime(true) - $started_at) * 1000);
                $context = array(
                    'type' => $type,
                    'name' => isset($config['name']) ? $config['name'] : $type,
                    'duration_ms' => $duration_ms,
                );
                if (is_array($stats_before)) {
                    $context['before'] = array(
                        'count' => $stats_before['count'] ?? null,
                        'size' => $stats_before['size'] ?? null,
                        'last_modified' => $stats_before['last_modified'] ?? null,
                    );
                }
                if (is_array($stats_after)) {
                    $context['after'] = array(
                        'count' => $stats_after['count'] ?? null,
                        'size' => $stats_after['size'] ?? null,
                        'last_modified' => $stats_after['last_modified'] ?? null,
                    );
                }
                $is_api_request = false;
                if (isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME']) && strpos($_SERVER['SCRIPT_NAME'], '/api/') !== false) {
                    $is_api_request = true;
                } elseif (isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/api/') === 0) {
                    $is_api_request = true;
                }
                if ($should_detail_log || (defined('TODO_DEBUG') && TODO_DEBUG) || !$is_api_request) {
                    todo_log_info('缓存清理成功', $context);
                }

                // 触发钩子
                todo_do_action('todo_cache_cleared', $type);
            } else {
                if ($should_detail_log) {
                    $stats_after = self::get_cache_statistics($type);
                }
                $duration_ms = (int) round((microtime(true) - $started_at) * 1000);
                $context = array(
                    'type' => $type,
                    'name' => isset($config['name']) ? $config['name'] : $type,
                    'duration_ms' => $duration_ms,
                );
                if (is_array($stats_before)) {
                    $context['before'] = array(
                        'count' => $stats_before['count'] ?? null,
                        'size' => $stats_before['size'] ?? null,
                        'last_modified' => $stats_before['last_modified'] ?? null,
                    );
                }
                if (is_array($stats_after)) {
                    $context['after'] = array(
                        'count' => $stats_after['count'] ?? null,
                        'size' => $stats_after['size'] ?? null,
                        'last_modified' => $stats_after['last_modified'] ?? null,
                    );
                }
                todo_log_warning('缓存清理失败', $context);
            }

        } catch (Exception $e) {
            todo_log_error('Cache clear exception: ' . $e->getMessage(), array('type' => $type));
            $success = false;
        }

        return $success;
    }

    /**
     * 清理多种类型的缓存
     * 
     * @param array $types 缓存类型数组
     * @return array 结果数组
     */
    public static function clear_multiple_caches($types)
    {
        $results = array(
            'success' => array(),
            'failed' => array()
        );

        foreach ($types as $type) {
            if (self::clear_cache($type)) {
                $results['success'][] = $type;
            } else {
                $results['failed'][] = $type;
            }
        }

        return $results;
    }

    /**
     * 清理所有缓存
     * 
     * @return array 结果数组
     */
    public static function clear_all_caches()
    {
        $types = array_keys(self::$cache_types);
        return self::clear_multiple_caches($types);
    }

    /**
     * 清除管理员缓存
     * 安全措施：管理员退出登录时，清除所有管理员令牌的缓存文件
     * 
     * @return int 清除的缓存文件数量
     */
    public static function clear_admin_cache()
    {
        $cleared_count = 0;

        // 获取模板缓存目录
        if (class_exists('CMS_Template_Compiler_V2')) {
            $cache_dir = ABSPATH . 'content/cache/compiled/';

            if (is_dir($cache_dir)) {
                // 遍历所有语言目录
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );

                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getExtension() === 'html') {
                        $filename = $file->getFilename();
                        // 检查文件名是否包含 'viewer:admin'
                        if (strpos($filename, 'viewer:admin') !== false) {
                            if (@unlink($file->getPathname())) {
                                $cleared_count++;
                            }
                        }
                    }
                }
            }
        }

        if ($cleared_count > 0) {
            todo_log_info('Admin cache cleared', array('count' => $cleared_count));
            todo_do_action('todo_admin_cache_cleared', $cleared_count);
        }

        return $cleared_count;
    }

    /**
     * 获取缓存统计信息
     * 
     * @param string $type 可选的特定类型
     * @return array 统计信息
     */
    public static function get_cache_statistics($type = null)
    {
        if ($type && isset(self::$cache_types[$type])) {
            return self::get_single_cache_stats($type);
        }

        $stats = array();
        foreach (self::$cache_types as $cache_type => $config) {
            $stats[$cache_type] = self::get_single_cache_stats($cache_type);
        }

        return $stats;
    }

    /**
     * 缓存预热
     * 
     * @param array $urls 可选的URL列表
     * @return array 预热结果
     */
    public static function warmup_cache($urls = null)
    {
        // 如果手动传入了URLs，即使配置中未启用也允许执行（手动触发）
        $is_manual_trigger = !empty($urls);

        if (!self::$warmup_config['enabled'] && !$is_manual_trigger) {
            return array('error' => 'Cache warmup is disabled');
        }

        $urls = $urls ?: self::$warmup_config['urls'];
        if (empty($urls)) {
            return array('error' => 'No URLs configured for warmup');
        }

        $results = array(
            'success' => 0,
            'failed' => 0,
            'urls' => array()
        );

        foreach ($urls as $url) {
            $start_time = microtime(true);
            $response = self::fetch_url($url);
            $duration = microtime(true) - $start_time;

            if ($response['success']) {
                $results['success']++;
                $results['urls'][$url] = array(
                    'status' => 'success',
                    'duration' => round($duration, 3),
                    'size' => strlen($response['content'])
                );
            } else {
                $results['failed']++;
                $results['urls'][$url] = array(
                    'status' => 'failed',
                    'error' => $response['error']
                );
            }
        }

        todo_log_info('Cache warmup completed', $results);
        return $results;
    }

    // Public wrapper to trigger asynchronous cache warmup without blocking the admin request
    public static function warmup_cache_async($urls = null)
    {
        self::async_warmup_cache($urls);
    }

    /**
     * 获取缓存类型配置
     * 
     * @return array 缓存类型配置
     */
    public static function get_cache_types()
    {
        return self::$cache_types;
    }

    private static function get_cache_dir_safe($type = '')
    {
        if (function_exists('get_cache_dir')) {
            return get_cache_dir($type);
        }

        $base_cache_dir = (defined('ABSPATH') ? ABSPATH : '') . 'content/cache/';
        if ($type === '' || $type === null) {
            return $base_cache_dir;
        }

        $cache_dirs = array(
            'templates' => $base_cache_dir . 'templates/',
            'objects' => $base_cache_dir . 'objects/',
            'php' => $base_cache_dir . 'php/',
            'tables' => $base_cache_dir . 'tables/',
        );

        return isset($cache_dirs[$type]) ? $cache_dirs[$type] : $base_cache_dir;
    }

    /**
     * 添加缓存类型
     * 
     * @param string $type 类型名称
     * @param array $config 配置
     */
    public static function add_cache_type($type, $config)
    {
        self::$cache_types[$type] = $config;
    }

    /**
     * 获取单个缓存类型的统计信息
     * 
     * @param string $type 缓存类型
     * @return array 统计信息
     */
    private static function get_single_cache_stats($type)
    {
        $config = self::$cache_types[$type];
        $cache_dir = self::get_cache_dir_safe();

        if (isset($config['directory'])) {
            $dir_path = $cache_dir . $config['directory'] . '/';
        } else {
            $dir_path = $cache_dir;
        }

        return array(
            'name' => $config['name'],
            'description' => $config['description'] ?? '',
            'count' => self::count_files_recursive($dir_path),
            'size' => self::get_directory_size($dir_path),
            'last_modified' => self::get_last_modified($dir_path)
        );
    }

    /**
     * 递归计算目录中的文件数量
     * 
     * @param string $dir 目录路径
     * @return int 文件数量
     */
    private static function count_files_recursive($dir)
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $exclude = array('.htaccess', 'index.php', '.gitkeep');
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $name = $file->getFilename();
                if (in_array($name, $exclude, true)) {
                    continue;
                }
                $count++;
            }
        }

        return $count;
    }

    /**
     * 获取目录大小
     * 
     * @param string $dir 目录路径
     * @return int 大小（字节）
     */
    private static function get_directory_size($dir)
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $size = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $exclude = array('.htaccess', 'index.php', '.gitkeep');
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $name = $file->getFilename();
                if (in_array($name, $exclude, true)) {
                    continue;
                }
                $size += $file->getSize();
            }
        }

        return $size;
    }

    /**
     * 获取目录最后修改时间
     * 
     * @param string $dir 目录路径
     * @return int 时间戳
     */
    private static function get_last_modified($dir)
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $latest = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $exclude = array('.htaccess', 'index.php', '.gitkeep');
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $name = $file->getFilename();
                if (in_array($name, $exclude, true)) {
                    continue;
                }
                $mtime = $file->getMTime();
                if ($mtime > $latest) {
                    $latest = $mtime;
                }
            }
        }

        return $latest;
    }

    /**
     * 获取URL内容（带本地端口注入与本地主机回退）
     * 
     * @param string $url URL地址
     * @return array 响应结果
     */
    private static function fetch_url($url, $device = 'pc')
    {
        $timeout = isset(self::$warmup_config['timeout']) ? (int) self::$warmup_config['timeout'] : 30;
        $ua = ($device === 'mobile')
            ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1 TodoCMS Cache Warmup Bot/1.0'
            : (isset(self::$warmup_config['user_agent']) && self::$warmup_config['user_agent']
                ? self::$warmup_config['user_agent']
                : 'TodoCMS Cache Warmup Bot/1.0');

        // 解析当前运行端口（用于端口注入）
        $runtime_port = null;
        $runtime_host = null;
        if (function_exists('todo_home_url')) {
            $site = todo_home_url();
            $sp = @parse_url($site);
            if ($sp) {
                $runtime_host = $sp['host'] ?? null;
                $runtime_scheme = isset($sp['scheme']) ? strtolower($sp['scheme']) : null;
                if (!empty($sp['port'])) {
                    $runtime_port = (int) $sp['port'];
                }
                if (!$runtime_port && !empty($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], ':') !== false) {
                    $parts = explode(':', $_SERVER['HTTP_HOST']);
                    $runtime_port = isset($parts[1]) ? (int) $parts[1] : null;
                }
                if (!$runtime_port && !empty($_SERVER['SERVER_PORT'])) {
                    $runtime_port = (int) $_SERVER['SERVER_PORT'];
                }
            }
        } else {
            if (!empty($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], ':') !== false) {
                $parts = explode(':', $_SERVER['HTTP_HOST']);
                $runtime_host = $parts[0];
                $runtime_port = isset($parts[1]) ? (int) $parts[1] : null;
            }
            if (!$runtime_port && !empty($_SERVER['SERVER_PORT'])) {
                $runtime_port = (int) $_SERVER['SERVER_PORT'];
            }
        }

        // 规范化URL：当主机为 localhost 且无端口，注入实际监听端口（非常用80/443时）
        $u = @parse_url($url);
        if ($u) {
            $orig_host = $u['host'] ?? '';
            $scheme = isset($u['scheme']) ? strtolower($u['scheme']) : 'http';
            $default_port = ($scheme === 'https') ? 443 : 80;
            if (!isset($u['port']) && $orig_host === 'localhost' && $runtime_port && $runtime_port !== $default_port) {
                $u['port'] = $runtime_port;
            }
            // 若主机为 localhost，优先使用与当前请求相同地址族的回环地址连接，但 Host 头保留原值
            // （php -S 通常只监听 ::1 或 127.0.0.1 其中之一，选错会浪费一次超时等待）
            if ($orig_host === 'localhost') {
                $prefer_v6 = !empty($_SERVER['REMOTE_ADDR']) && strpos($_SERVER['REMOTE_ADDR'], ':') !== false;
                $u['host'] = $prefer_v6 ? '::1' : '127.0.0.1';
                $url = self::rebuild_url_from_parts($u);
            } elseif ($orig_host === '::1' || $orig_host === '[::1]') {
                // IPv6 回环主机：保留为 ::1，若未显式端口也注入端口
                if (!isset($u['port']) && $runtime_port && $runtime_port !== $default_port) {
                    $u['port'] = $runtime_port;
                }
                $url = self::rebuild_url_from_parts($u);
            } elseif (!isset($u['port']) && $orig_host === '127.0.0.1' && $runtime_port && $runtime_port !== $default_port) {
                // 若为 127.0.0.1 且未显式端口，也注入端口
                $u['port'] = $runtime_port;
                $url = self::rebuild_url_from_parts($u);
            }
        }

        // 组装 Host 头（用于本地多虚拟主机路由）
        $hostHeader = '';
        if ($u) {
            $hh = $orig_host ?? ($u['host'] ?? '');
            $hp = isset($u['port']) ? (int) $u['port'] : null;
            if ($hh && preg_match('/^[A-Za-z0-9\.-]+$/', $hh)) {
                $hostHeader = $hh . ($hp ? (':' . $hp) : '');
            }
            // 如果连接目标是本地回环地址，则优先使用当前请求的 Host 头（保持与浏览器一致的虚拟主机路由）
            $connectHost = isset($u['host']) ? $u['host'] : '';
            if (in_array($connectHost, array('127.0.0.1', 'localhost', '::1', '[::1]'), true)) {
                if (!empty($_SERVER['HTTP_HOST'])) {
                    $hostHeader = $_SERVER['HTTP_HOST']; // 保持原样，可能已包含端口或IPv6方括号
                } else if (function_exists('todo_home_url')) {
                    $home = todo_home_url();
                    $hp2 = @parse_url($home);
                    if ($hp2 && !empty($hp2['host'])) {
                        $hostHeader = $hp2['host'] . (isset($hp2['port']) ? (':' . (int) $hp2['port']) : '');
                    }
                }
            }
        }
        // 回环自请求使用较短超时：目标是本机，长时间无响应通常意味着 worker 被占满/死锁，
        // 继续等待只会令本请求超过 max_execution_time 触发 Fatal（浏览器表现为 ERR_EMPTY_RESPONSE）
        $connect_host_final = isset($u['host']) ? $u['host'] : '';
        $is_loopback_target = in_array($connect_host_final, array('127.0.0.1', 'localhost', '::1', '[::1]'), true);
        if ($is_loopback_target && $timeout > 10) {
            $timeout = 10;
        }
        // 放宽脚本时限，确保覆盖 首次请求 + 换址重试 + cURL 回退，失败时能正常返回 JSON 而非被强杀
        @set_time_limit($timeout * 4 + 30);

        $headers = "X-TODO-WARMUP: 1\r\nConnection: close\r\n";
        if ($hostHeader) {
            $headers .= 'Host: ' . $hostHeader . "\r\n";
        }

        // 为预热请求构造上下文（附带内部标识头，允许重定向并收集响应头）
        $context = stream_context_create(array(
            'http' => array(
                'timeout' => $timeout,
                'user_agent' => $ua,
                'follow_location' => 1,
                'max_redirects' => 5,
                'ignore_errors' => true,
                'protocol_version' => 1.1,
                'method' => 'GET',
                'header' => $headers
            ),
            'ssl' => array(
                // 开发环境下可能没有证书，关闭验证避免预热失败（生产可按需开启）
                'verify_peer' => false,
                'verify_peer_name' => false
            )
        ));

        // 执行第一次请求（捕获并抑制告警，避免在输出端出现 Warning）
        $last_warning = null;
        $prev_handler = set_error_handler(function ($errno, $errstr) use (&$last_warning) {
            $last_warning = $errstr;
            return true; // 阻止默认处理，避免输出
        });
        $content = @file_get_contents($url, false, $context);
        if ($prev_handler !== null) {
            restore_error_handler();
        }
        $status = null;
        $hdr_var = 'http_response_header';
        $resp_headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : (isset($$hdr_var) && is_array($$hdr_var) ? $$hdr_var : null);
        if (isset($resp_headers[0]) && preg_match('#HTTP/\\S+\\s+(\\d{3})#', $resp_headers[0], $m)) {
            $status = (int) $m[1];
        }

        // 若失败原因是"超时"（服务器可达但无响应，如 php -S worker 被占满），
        // 换回环地址重试/cURL 回退只会在同一服务器上重复挂起，直接放弃
        $timed_out = ($content === false && $last_warning && stripos($last_warning, 'timed out') !== false);

        // 请求失败且是本地回环主机，尝试在 localhost、127.0.0.1、[::1] 之间切换并重试一次
        $need_retry = !$timed_out && ($content === false || !($status === null || ($status >= 200 && $status < 400)));
        if ($need_retry && $u) {
            $host = $u['host'] ?? '';
            $orig = $host;
            $candidates = array();
            if ($host === 'localhost') {
                $candidates = array('127.0.0.1', '::1');
            } elseif ($host === '127.0.0.1') {
                $candidates = array('localhost', '::1');
            } elseif ($host === '::1' || $host === '[::1]') {
                $candidates = array('127.0.0.1', 'localhost');
            }
            foreach ($candidates as $h2) {
                $u['host'] = $h2;
                $retry_url = self::rebuild_url_from_parts($u);
                // 重试同样捕获并抑制warning
                $last_warning_retry = null;
                $prev_handler2 = set_error_handler(function ($errno, $errstr) use (&$last_warning_retry) {
                    $last_warning_retry = $errstr;
                    return true;
                });
                $content = @file_get_contents($retry_url, false, $context);
                if ($prev_handler2 !== null) {
                    restore_error_handler();
                }
                $status = null;
                $resp_headers_retry = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : (isset($$hdr_var) && is_array($$hdr_var) ? $$hdr_var : null);
                if (isset($resp_headers_retry[0]) && preg_match('#HTTP/\\S+\\s+(\\d{3})#', $resp_headers_retry[0], $m)) {
                    $status = (int) $m[1];
                }
                if ($content !== false && ($status === null || ($status >= 200 && $status < 400))) {
                    return array(
                        'success' => true,
                        'content' => $content,
                        'status' => $status
                    );
                }
                // 记录最后一次重试的URL，供后续cURL回退时参考
                $last_retry_url = $retry_url;
                // 重试也超时则不再尝试其他地址（同一服务器挂起，重试只会叠加等待）
                if ($content === false && $last_warning_retry && stripos($last_warning_retry, 'timed out') !== false) {
                    $timed_out = true;
                    break;
                }
            }
        }

        // 2xx/3xx 视为成功
        if ($content !== false && ($status === null || ($status >= 200 && $status < 400))) {
            return array(
                'success' => true,
                'content' => $content,
                'status' => $status
            );
        }

        // 若失败且可用 cURL，进行回退重试（兼容 allow_url_fopen 关闭场景）；超时挂起场景无需再试
        if (!$timed_out && function_exists('curl_init')) {
            $ch = curl_init($url);
            $connectTimeout = ($timeout > 5) ? 5 : $timeout;
            $curlHeaders = array('X-TODO-WARMUP: 1', 'Connection: close');
            if (!empty($hostHeader)) {
                $curlHeaders[] = 'Host: ' . $hostHeader;
            }
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_USERAGENT => $ua,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER => $curlHeaders
            ));
            $body = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_err = curl_error($ch);
            curl_close($ch);

            if ($body !== false && $http_code >= 200 && $http_code < 400) {
                return array(
                    'success' => true,
                    'content' => $body,
                    'status' => $http_code
                );
            }

            return array(
                'success' => false,
                'error' => 'Failed to fetch URL via cURL: ' . $url . ' (HTTP ' . (int) $http_code . ') ' . ($curl_err ?: ''),
                'status' => $http_code
            );
        }

        return array(
            'success' => false,
            'error' => 'Failed to fetch URL: ' . $url . ($status ? (' (HTTP ' . $status . ')') : ''),
            'status' => $status
        );
    }

    /**
     * 根据 parse_url 的结果重建 URL
     * @param array $p
     * @return string
     */
    private static function rebuild_url_from_parts(array $p)
    {
        $scheme = isset($p['scheme']) ? $p['scheme'] . '://' : '';
        $user = $p['user'] ?? '';
        $pass = isset($p['pass']) ? ':' . $p['pass'] : '';
        $auth = ($user || $pass) ? $user . $pass . '@' : '';
        $host = $p['host'] ?? '';
        // 对IPv6字面量进行方括号包装（URL中带端口时必须是 [::1]:8080 形式）
        $hostOut = $host;
        if ($host && strpos($host, ':') !== false) {
            if ($host[0] !== '[') {
                $hostOut = '[' . $host . ']';
            }
        }
        $port = isset($p['port']) ? ':' . $p['port'] : '';
        $path = $p['path'] ?? '';
        $query = isset($p['query']) ? '?' . $p['query'] : '';
        $fragment = isset($p['fragment']) ? '#' . $p['fragment'] : '';
        return $scheme . $auth . $hostOut . $port . $path . $query . $fragment;
    }

    /**
     * 在 CLI 子进程内渲染指定 URL 并写入页面缓存（不经过 HTTP）
     *
     * 子进程通过 core/scripts/warmup-render.php 模拟一次游客 GET 请求，
     * 走真实前台路由渲染，缓存由前台正常流程写入。
     *
     * @param string $url 完整页面URL
     * @param int $timeout 最长等待秒数
     * @return array {attempted: bool, success: bool, error: string}
     */
    private static function warmup_render_via_cli($url, $timeout = 55, $device = 'pc')
    {
        $result = array('attempted' => false, 'success' => false, 'error' => '');

        if (!function_exists('proc_open')) {
            return $result;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('proc_open', $disabled, true)) {
            return $result;
        }
        // 解析可用的 PHP CLI 可执行文件（php-fpm/php-cgi 进程内 PHP_BINARY 并非 CLI，不能直接用）
        $php = '';
        $candidates = array();
        if (defined('PHP_BINARY') && PHP_BINARY) {
            $bn = basename(PHP_BINARY);
            if (strpos($bn, 'php-fpm') === false && strpos($bn, 'php-cgi') === false) {
                $candidates[] = PHP_BINARY;
            }
        }
        if (defined('PHP_BINDIR') && PHP_BINDIR) {
            $candidates[] = PHP_BINDIR . '/php';
        }
        foreach ($candidates as $cand) {
            if ($cand && @is_file($cand) && @is_executable($cand)) {
                $php = $cand;
                break;
            }
        }
        if (!$php) {
            return $result;
        }
        $script = ABSPATH . 'core/scripts/warmup-render.php';
        if (!is_file($script)) {
            return $result;
        }

        $payload = base64_encode(json_encode(array('url' => $url, 'device' => $device)));
        // xdebug.mode=off：避免子进程反复尝试连接调试端口拖慢渲染
        $cmd = escapeshellarg($php)
            . ' -d xdebug.mode=off -d display_errors=0'
            . ' ' . escapeshellarg($script)
            . ' ' . escapeshellarg($payload);

        $pipes = array();
        $proc = @proc_open($cmd, array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        ), $pipes, ABSPATH);
        if (!is_resource($proc)) {
            return $result;
        }

        $result['attempted'] = true;
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stderr = '';
        $deadline = microtime(true) + max(5, (int) $timeout);
        for (;;) {
            $status = proc_get_status($proc);
            // 持续排空管道，防止子进程因输出缓冲写满而阻塞
            while (($chunk = fread($pipes[1], 8192)) !== false && $chunk !== '') {
                // 丢弃页面HTML输出（缓存已由子进程写盘）
            }
            while (($chunk = fread($pipes[2], 8192)) !== false && $chunk !== '') {
                if (strlen($stderr) < 65536) {
                    $stderr .= $chunk;
                }
            }
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                @proc_terminate($proc, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                @proc_close($proc);
                $result['error'] = 'render timeout after ' . $timeout . 's';
                return $result;
            }
            usleep(50000);
        }

        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        if (preg_match('/__WARMUP_RENDER_COMPLETE__ len=(\d+)/', $stderr, $m)) {
            if ((int) $m[1] > 0) {
                $result['success'] = true;
            } else {
                $result['error'] = 'empty output (URL可能触发了重定向，请填写规范的最终URL)';
            }
            return $result;
        }
        if (preg_match('/__WARMUP_RENDER_FATAL__ (.+)/', $stderr, $m)) {
            $result['error'] = trim($m[1]);
            return $result;
        }
        $result['error'] = 'render process failed: ' . substr(trim($stderr), -300);
        return $result;
    }

    /**
     * 获取最近一次预热失败的原因
     * @return string
     */
    public static function get_last_warmup_error()
    {
        return self::$last_warmup_error;
    }

    /**
     * 规范化用于模板缓存键的绝对URL（需与模板解析器一致）
     * - 移除跟踪类参数（utm_/fbclid/gclid等）
     * - 对查询参数进行排序
     * - 统一去除结尾斜杠
     */
    private static function normalize_url_for_cache($url)
    {
        if (empty($url) || !is_string($url)) {
            return '';
        }
        $url = trim($url);
        $parts = @parse_url($url);
        if ($parts === false || !isset($parts['scheme']) || !isset($parts['host'])) {
            return rtrim($url, '/');
        }
        $path = $parts['path'] ?? '';
        $query = $parts['query'] ?? '';

        if ($query !== '') {
            $query_array = array();
            parse_str($query, $query_array);

            $ignored_keys = array(
                'fbclid',
                'gclid',
                'msclkid',
                'mc_cid',
                'mc_eid',
                'mkt_tok',
                'igshid',
                'ref',
                'ref_src',
                'spm',
                'ved'
            );
            $ignored_prefixes = array('utm_', 'pk_', 'ga_');

            if (function_exists('todo_apply_filters')) {
                $ignored_keys = todo_apply_filters('template_cache_ignored_query_keys', $ignored_keys);
                $ignored_prefixes = todo_apply_filters('template_cache_ignored_query_prefixes', $ignored_prefixes);
            }

            foreach ($query_array as $k => $v) {
                $lower_k = strtolower($k);
                $remove = in_array($lower_k, $ignored_keys, true);
                if (!$remove) {
                    foreach ($ignored_prefixes as $pf) {
                        if (strpos($lower_k, $pf) === 0) {
                            $remove = true;
                            break;
                        }
                    }
                }
                if ($remove) {
                    unset($query_array[$k]);
                }
            }

            if (!empty($query_array)) {
                ksort($query_array);
                $parts['query'] = http_build_query($query_array);
            } else {
                unset($parts['query']);
            }
        }

        $rebuilt = self::rebuild_url_from_parts($parts);
        return rtrim($rebuilt, '/');
    }

    /**
     * 注册钩子
     */
    private static function register_hooks()
    {
        // 内容更新时清理相关缓存
        todo_add_action('todo_post_updated', array(__CLASS__, 'on_content_updated'));
        todo_add_action('todo_post_deleted', array(__CLASS__, 'on_content_deleted'));

        // 定时发布完成时清理缓存（并可选预热）
        todo_add_action('todo_scheduled_post_published', array(__CLASS__, 'on_scheduled_post_published'));

        // 翻译更新时清理缓存（与内容更新逻辑一致）
        todo_add_action('todo_post_translation_updated', array(__CLASS__, 'on_translation_updated'));

        // 主题切换时清理模板缓存
        todo_add_action('todo_theme_switched', array(__CLASS__, 'on_theme_switched'));

        // 系统设置更新时清理缓存
        todo_add_action('update_option', array(__CLASS__, 'on_option_updated'), 10, 3);

        // 广告数据变更时主动清理模板HTML缓存（避免过期广告缓存残留在磁盘上）
        // parse_template_with_cache 运行时检查只会在访问时绕过过期缓存，不会删除文件
        todo_add_action('update_option_todo_ads_last_modified', array(__CLASS__, 'on_ads_changed'), 10, 2);
    }

    /**
     * 内容更新时的钩子处理
     * 支持精准清理和生成（page_mode=dynamic 且 ttl=0 时）
     * 
     * @param int $post_id 文章ID
     */
    public static function on_content_updated($post_id)
    {
        if (!$post_id) {
            return;
        }

        // 所有模式统一走精准失效：内容页 + 文档属性依赖页（推荐/头条等挂件） + 首页 + 相关分类列表页
        // 不再全清 template_html；admin 环境下仅清除不重建，前台首次访问时自动重建
        $is_admin_ctx = (defined('IS_ADMIN') && IS_ADMIN);
        self::invalidate_post_related_page_caches($post_id, !$is_admin_ctx);
    }

    /**
     * 文章相关页面缓存的统一精准失效入口（请求级去重）
     *
     * 覆盖范围：
     * 1. 文章自身页面缓存
     * 2. 文档属性依赖页面（通过 post:doc_prop:{id} token 索引，如跨分类 sidebar 的"推荐文章"块）
     * 3. 首页（仅当首页实际包含该文章时）
     * 4. 文章所属分类的列表页
     *
     * 完成后记录"已同步处理"标记，供 async-check 跳过，
     * 避免异步检查窗口对同一事件重复删除刚重建的缓存。
     *
     * @param int  $post_id    文章ID
     * @param bool $regenerate 是否同步重建被清除的首页/列表页缓存
     */
    private static function invalidate_post_related_page_caches($post_id, $regenerate = false)
    {
        $post_id = intval($post_id);
        if ($post_id <= 0) {
            return;
        }

        // 请求级去重：同一文章在一次请求内只做一轮"清除(+重建)"
        if (isset(self::$request_invalidated_post_ids[$post_id])) {
            return;
        }
        self::$request_invalidated_post_ids[$post_id] = true;

        // 1) 文章自身页面缓存
        self::clear_post_specific_cache($post_id);

        // 2) 文档属性依赖页面（先于重建执行，确保重建出的缓存重新登记依赖）
        self::maybe_clear_doc_property_dependency_caches($post_id);

        // 3) 首页
        if (self::is_post_in_home_page($post_id)) {
            self::precise_clear_home_cache();
            if ($regenerate) {
                self::precise_regenerate_home_cache();
            }
        }

        // 4) 相关分类列表页
        $category_ids = self::get_post_category_ids($post_id);
        if (!empty($category_ids)) {
            $affected_cats = self::get_category_ids_containing_post($post_id, $category_ids);
            foreach ($affected_cats as $cat_id) {
                self::precise_clear_category_cache($cat_id);
                if ($regenerate) {
                    self::precise_regenerate_category_cache($cat_id);
                }
            }
        }

        // 5) 标记已同步处理，供 async-check 窗口去重
        self::mark_post_timed_invalidation_handled($post_id);
    }

    /**
     * 登记文章更新前的旧文档属性（由 todo_update_post 在 DB 写入前调用）
     *
     * 场景：文章原本勾选"推荐"，本次保存取消勾选。写入后 DB 里已无该属性，
     * 若只按新值清理，登记了 post:doc_prop:2 的页面（如侧边栏推荐块）不会失效，
     * 导致已取消推荐的文章残留展示。
     *
     * @param int          $post_id 文章ID
     * @param string|mixed $raw     旧的 doc_properties 原始值（CSV/JSON等格式均可）
     */
    public static function remember_post_previous_doc_properties($post_id, $raw)
    {
        $post_id = intval($post_id);
        if ($post_id <= 0) {
            return;
        }

        $prop_ids = array();
        if ($raw !== null && $raw !== '' && preg_match_all('/\d+/', (string) $raw, $m)) {
            $prop_ids = array_values(array_unique(array_filter(array_map('intval', $m[0]))));
        }

        self::$post_previous_doc_properties[$post_id] = $prop_ids;
    }

    /**
     * 若文章带有文档属性（推荐/加推/头条等），清理登记了对应
     * post:doc_prop:{id} 依赖 token 的模板缓存页面。
     *
     * 用于跨分类失效：例如 ?cat=13 的 sidebar 引用了 doc_properties="2,3" 的
     * "推荐文章"块，当带"推荐"属性的新文章（哪怕不属于分类13）发布时，
     * 该页面缓存也必须失效。
     *
     * 属性集合 = 更新前旧属性 ∪ 当前DB属性，
     * 保证"新勾选"（新值有）与"取消勾选"（仅旧值有）两个方向都能失效。
     *
     * @param int $post_id 文章ID
     */
    private static function maybe_clear_doc_property_dependency_caches($post_id)
    {
        global $tododb;

        $post_id = intval($post_id);
        if ($post_id <= 0 || !$tododb) {
            return;
        }

        try {
            // 更新前登记的旧属性（消费后移除，避免影响同请求后续操作）
            $prop_ids = array();
            if (isset(self::$post_previous_doc_properties[$post_id])) {
                $prop_ids = self::$post_previous_doc_properties[$post_id];
                unset(self::$post_previous_doc_properties[$post_id]);
            }

            $row = $tododb->get_row(
                "SELECT ID, doc_properties FROM {$tododb->prefix}posts WHERE ID = ?",
                array($post_id)
            );

            if (!$row) {
                // 文章已被物理删除（删除钩子在 DB 删除之后触发），无法得知其原有属性。
                // 保守清理所有属性依赖页面，避免推荐/头条等挂件残留已删除文章。
                $all_ids = $tododb->get_col(
                    "SELECT id FROM {$tododb->prefix}doc_properties WHERE is_active = 1"
                );
                $prop_ids = array_merge($prop_ids, array_map('intval', (array) $all_ids));
            } elseif (isset($row->doc_properties) && $row->doc_properties !== '' && $row->doc_properties !== null) {
                // 兼容 CSV / JSON / 序列化等历史存储格式，提取全部数字ID
                if (preg_match_all('/\d+/', (string) $row->doc_properties, $m)) {
                    $prop_ids = array_merge($prop_ids, array_map('intval', $m[0]));
                }
            }

            $prop_ids = array_values(array_unique(array_filter($prop_ids)));
            if (empty($prop_ids)) {
                return;
            }

            $tokens = array();
            foreach ($prop_ids as $pid) {
                $tokens[] = 'post:doc_prop:' . $pid;
            }

            self::clear_template_html_cache_by_dependency_tokens($tokens);
        } catch (Throwable $e) {
            self::log_warning('Failed to clear doc-property dependent template caches', array(
                'post_id' => $post_id,
                'error' => $e->getMessage(),
            ));
        }
    }

    /**
     * 记录文章的时间驱动失效已被同步钩子处理
     * async-check 在处理变更窗口时会跳过这些文章，避免重复删除刚重建的缓存
     *
     * 注意：状态必须存独立文件而非对象缓存（content 组），
     * 因为 clear_data_cache()/on_option_updated 会 flush_all 把对象缓存整体清空，
     * 导致标记丢失后 async-check 把同一事件再清一遍（删→生→删循环）。
     *
     * @param int $post_id 文章ID
     */
    private static function mark_post_timed_invalidation_handled($post_id)
    {
        $post_id = intval($post_id);
        if ($post_id <= 0) {
            return;
        }

        $state = self::load_timed_invalidation_state();
        $state['handled_posts'][(string) $post_id] = time();
        self::save_timed_invalidation_state($state);
    }

    /**
     * 过滤掉已由同步钩子处理过的变更文章ID
     *
     * @param array $post_ids 变更窗口内的文章ID
     * @param int   $since_ts 窗口起点
     * @return array 仍需异步处理的文章ID
     */
    private static function filter_unhandled_changed_post_ids($post_ids, $since_ts)
    {
        $state = self::load_timed_invalidation_state();
        $handled = (array) ($state['handled_posts'] ?? array());

        $remaining = array();
        foreach ((array) $post_ids as $post_id) {
            $handled_at = intval($handled[(string) intval($post_id)] ?? 0);
            if ($handled_at > 0 && $handled_at >= intval($since_ts)) {
                // 该文章的变更已在本窗口内被同步钩子精准失效（如页面访问触发的定时发布），
                // 异步检查无需重复清理
                continue;
            }
            $remaining[] = intval($post_id);
        }

        return $remaining;
    }

    /**
     * async-check 运维状态文件路径
     * 与依赖索引同目录，独立于对象缓存，不受 flush_all 影响
     */
    private static function get_timed_invalidation_state_path()
    {
        $dir = ABSPATH . 'content/cache/updates/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return rtrim(str_replace('\\', '/', $dir), '/') . '/timed-invalidation-state.json';
    }

    /**
     * 读取 async-check 运维状态（水位线、节流、已处理文章标记）
     * 顺带修剪过期的 handled 标记（1小时）
     */
    private static function load_timed_invalidation_state()
    {
        $defaults = array(
            'last_check' => 0,
            'throttle_until' => 0,
            'handled_posts' => array(),
        );

        $path = self::get_timed_invalidation_state_path();
        if (!file_exists($path)) {
            return $defaults;
        }

        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data)) {
            return $defaults;
        }

        $state = array(
            'last_check' => intval($data['last_check'] ?? 0),
            'throttle_until' => intval($data['throttle_until'] ?? 0),
            'handled_posts' => array(),
        );

        $cutoff = time() - 3600;
        foreach ((array) ($data['handled_posts'] ?? array()) as $pid => $ts) {
            if (intval($ts) >= $cutoff) {
                $state['handled_posts'][(string) intval($pid)] = intval($ts);
            }
        }

        return $state;
    }

    private static function save_timed_invalidation_state($state)
    {
        $written = @file_put_contents(
            self::get_timed_invalidation_state_path(),
            json_encode($state, JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        if ($written === false) {
            // 写失败会退化为"async-check 可能重复清理一次"，不影响正确性但需可观测
            // （常见原因：content/cache/updates/ 目录属主与当前进程用户不一致）
            self::log_warning('Failed to persist timed invalidation state file', array(
                'path' => self::get_timed_invalidation_state_path(),
            ));
        }
    }

    /**
     * async-check 节流：尝试获取执行资格（默认30秒一次）
     *
     * @param int $ttl 节流窗口秒数
     * @return bool true=允许执行本次检查；false=被节流
     */
    public static function acquire_async_cache_check_slot($ttl = 30)
    {
        $state = self::load_timed_invalidation_state();
        $now = time();
        if (intval($state['throttle_until']) > $now) {
            return false;
        }
        $state['throttle_until'] = $now + max(1, intval($ttl));
        self::save_timed_invalidation_state($state);
        return true;
    }

    /**
     * 读取 async-check 上次检查水位线
     */
    public static function get_timed_invalidation_last_check()
    {
        $state = self::load_timed_invalidation_state();
        return intval($state['last_check']);
    }

    /**
     * 推进 async-check 检查水位线
     */
    public static function set_timed_invalidation_last_check($ts)
    {
        $state = self::load_timed_invalidation_state();
        $state['last_check'] = intval($ts);
        self::save_timed_invalidation_state($state);
    }

    /**
     * 内容删除时的钩子处理
     * 
     * @param int $post_id 文章ID
     */
    public static function on_content_deleted($post_id)
    {
        // 与 on_content_updated 一致的精准清理策略，不再全清 template_html
        self::on_content_updated($post_id);
    }

    /**
     * 翻译更新时的钩子处理
     * 保持与内容更新一致的清理策略，确保前端立即看到翻译变更
     *
     * @param int $post_id 文章ID
     * @param string|null $lang 更新的语言代码
     */
    public static function on_translation_updated($post_id, $lang = null)
    {
        // 复用内容更新清理逻辑（页面缓存）
        self::on_content_updated($post_id);

        // 清理对象缓存，避免命中旧的文章/多语言聚合数据
        self::clean_post_cache($post_id);
    }

    /**
     * 定时发布完成时的钩子处理
     * 复用内容更新清理逻辑，并可选触发预热
     *
     * @param int $post_id 文章ID
     */
    public static function on_scheduled_post_published($post_id)
    {
        // clean_post_cache 已包含对象缓存清理 + 页面级精准失效
        // （内容页/首页/列表页/属性依赖页，且请求内去重），
        // 不再叠加调用 on_content_updated，避免同一事件重复"清除→重建→再清除"
        self::clean_post_cache($post_id);
    }

    /**
     * 广告数据变更时的钩子处理（新增/编辑/删除广告或分组）
     * 
     * 由于任何页面都可能包含广告，无法精确定位，因此清除全部模板HTML缓存。
     * 这确保广告到期（end_date/end_time 已过）后，对应的磁盘缓存文件不会一直残留。
     * 
     * @param mixed  $value      时间戳
     * @param string $group_name 分组名称
     */
    public static function on_ads_changed($value, $group_name)
    {
        $context = self::consume_pending_ad_change_context();
        if (is_array($context)) {
            $result = self::clear_template_html_cache_by_ad_dependencies(
                $context['ad_ids'] ?? array(),
                $context['group_ids'] ?? array(),
                $context['markers'] ?? array()
            );

            if (($result['matched_files'] ?? 0) === 0 && ($result['indexed_files'] ?? 0) === 0) {
                self::clear_cache('template_html');
                if (class_exists('Todo_Template_Result_Cache')) {
                    Todo_Template_Result_Cache::clear();
                }
                todo_log_info('Template cache cleared due to ad changes (index bootstrap fallback)');
                return;
            }

            todo_log_info('Template cache cleared due to ad changes', $result);
            return;
        }

        self::clear_cache('template_html');

        if (class_exists('Todo_Template_Result_Cache')) {
            Todo_Template_Result_Cache::clear();
        }

        todo_log_info('Template cache cleared due to ad changes (fallback full clear)');
    }

    /**
     * 主题切换时的钩子处理
     */
    public static function on_theme_switched()
    {
        self::clear_cache('compiled_template');
        self::clear_cache('template_html');
    }

    /**
     * 系统设置更新时的钩子处理
     *
     * @param string $option 选项名称
     * @param mixed $value 新值
     * @param string $group_name 分组名称
     */
    public static function on_option_updated($option, $value, $group_name)
    {
        // 排除无需清理缓存的选项（如临时标记、计数器等）
        $exclude_options = array(
            'cron',
            'rewrite_rules',
            'recently_edited',
            'todo_ads_last_modified', // 广告更新有专门的清理逻辑
            'todo_scheduled_last_run_time', // 定时发布检查时间戳
            'todo_frontend_scheduled_check', // 前端定时发布检查
            'todo_install_data_signed', // 安装数据签名/内部状态，不影响前端渲染结果
            'db_version',
            'active_plugins_version'
        );

        // 排除临时数据和系统内部状态
        if (
            strpos($option, '_transient_') === 0 ||
            strpos($option, '_site_transient_') === 0 ||
            strpos($option, 'lock_') === 0 ||
            strpos($option, 'cron_') === 0
        ) {
            return;
        }

        if (in_array($option, $exclude_options)) {
            return;
        }

        $is_admin_request = (defined('IS_ADMIN') && IS_ADMIN) || (function_exists('is_admin') && is_admin());
        $is_post_request = isset($_SERVER['REQUEST_METHOD']) && strtoupper((string) $_SERVER['REQUEST_METHOD']) === 'POST';
        $is_admin_post = $is_admin_request && $is_post_request;

        $is_static_mode = false;
        if (function_exists('todo_get_option')) {
            $is_static_mode = (todo_get_option('page_mode', 'dynamic') === 'static');
        } elseif (function_exists('get_option')) {
            $is_static_mode = (get_option('page_mode', 'dynamic') === 'static');
        }

        self::clear_cache('compiled_template');

        // 清理数据查询缓存，避免依赖系统设置的查询返回旧结果
        self::clear_cache('data');

        // 不再全清 template_html，模板缓存走 async-check 异步失效 + 依赖索引定向清理
        // 避免修改一个系统设置就把 1 万篇页面缓存全部清掉

        if (class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::flush_group('options');
        }

        if ($is_admin_post) {
            self::clear_cache('object');
        }

        // 记录日志
        todo_log_info('Cache cleared due to option update', array('option' => $option, 'static_mode' => $is_static_mode));
    }

    /**
     * 生成单个页面的缓存（直接调用解析器，不经过HTTP请求）
     * 
     * @param string $url 页面URL
     * @param string $lang 语言代码
     * @return bool 是否成功
     */
    public static function generate_page_cache($url, $lang = '', $forced_type = '', $forced_id = 0, $viewer_role = 'guest', $site_id = 0)
    {
        // 标记当前正在执行预热，允许其他模块（如语言映射器）跳过 is_admin() 限制
        $GLOBALS['todo_doing_cache_warmup'] = true;
        $viewer_role = 'guest';

        self::$last_warmup_error = '';
        $devices = array('pc', 'mobile');
        $all_ok = true;

        foreach ($devices as $dev) {
            $cli = self::warmup_render_via_cli($url, 55, $dev);
            if (!empty($cli['attempted'])) {
                if (empty($cli['success'])) {
                    self::$last_warmup_error = !empty($cli['error']) ? $cli['error'] : "CLI render failed ({$dev})";
                    error_log('[Warmup] URL CLI render failed (' . $dev . '): ' . $url . ' error=' . self::$last_warmup_error);
                    $all_ok = false;
                }
                continue;
            }

            if (php_sapi_name() === 'cli-server' && (int) getenv('PHP_CLI_SERVER_WORKERS') <= 1) {
                self::$last_warmup_error = '单worker内置服务器不支持回环预热，请以 PHP_CLI_SERVER_WORKERS=4 启动';
                error_log('[Warmup] Skip loopback warmup under single-worker built-in server: ' . $url);
                return false;
            }

            $resp = self::fetch_url($url, $dev);
            $ok = !empty($resp['success']);
            if (!$ok) {
                self::$last_warmup_error = isset($resp['error']) ? $resp['error'] : "loopback request failed ({$dev})";
                error_log('[Warmup Debug] Loopback warmup failed (' . $dev . '): ' . $url . ' error=' . self::$last_warmup_error);
                $all_ok = false;
            }
        }

        return $all_ok;

        $is_target_debug_url = (strpos((string) $url, '/recruit/') !== false || stripos((string) $url, '/design.html') !== false);
        
        // 确保必要的函数和类已加载
        if (!function_exists('parse_html_template')) {
            require_once ABSPATH . 'core/classes/class-template-compiler-v2.php';
        }
        if (!function_exists('set_current_page_type')) {
            require_once ABSPATH . 'core/functions/core.php';
        }
        if (!function_exists('get_template_directory')) {
            require_once ABSPATH . 'core/functions/template.php';
        }
        if (!function_exists('get_html_template_path')) {
            require_once ABSPATH . 'core/functions/html-template.php';
        }
        if (!function_exists('get_template_file_path')) {
            require_once ABSPATH . 'core/functions/html-template.php';
        }
        if (!function_exists('get_post')) {
            require_once ABSPATH . 'core/functions/post.php';
        }
        if (!function_exists('todo_get_fragment_cache_ttl')) {
            require_once ABSPATH . 'core/functions/system-functions-extended.php';
        }
        if (!function_exists('query_posts')) {
            // 模板 loop/query 标签依赖，缺失会导致渲染抛异常返回空、缓存无法写入
            require_once ABSPATH . 'core/functions/loop.php';
        }

        // 设置语言环境
        $original_locale = null;
        $original_global_locale = null;
        $original_manager_locale = null;

        if (!empty($lang)) {
            $lang = str_replace('_', '-', $lang);
            if (function_exists('canonical_locale')) {
                $lang = canonical_locale($lang);
            }

            if (function_exists('current_locale')) {
                $original_locale = current_locale();
            }
            if (isset($GLOBALS['_todo_current_locale'])) {
                $original_global_locale = $GLOBALS['_todo_current_locale'];
            }
            if (class_exists('TodoLanguageManager')) {
                try {
                    $original_manager_locale = TodoLanguageManager::get_instance()->get_current_language();
                } catch (Throwable $e) {
                    $original_manager_locale = null;
                }
            }

            $GLOBALS['_todo_current_locale'] = $lang;

            if (class_exists('LocaleContext')) {
                try {
                    $locale_context = LocaleContext::get_instance();
                    $locale_context->set_locale($lang);
                } catch (Exception $e) {
                }
            }
            if (class_exists('TodoLanguageManager')) {
                try {
                    TodoLanguageManager::get_instance()->switch_language($lang);
                } catch (Throwable $e) {
                }
            }

            if (class_exists('CMS_Theme')) {
                CMS_Theme::reset_active_theme_cache();
            }

            // 模拟刷新请求
            $_GET['todocache'] = 'refresh';
        }

        // 设置站点上下文（首页和分类页需要区分不同站点的缓存）
        $original_site_cookie = isset($_COOKIE['todo_site_id']) ? $_COOKIE['todo_site_id'] : null;
        $original_global_site_id = isset($GLOBALS['todo_current_site_id']) ? $GLOBALS['todo_current_site_id'] : null;
        if ($site_id > 0) {
            $_COOKIE['todo_site_id'] = $site_id;
            $GLOBALS['todo_current_site_id'] = $site_id;
        }

        // 解析URL获取路径和查询参数
        $url_parts = parse_url($url);
        $path = isset($url_parts['path']) ? $url_parts['path'] : '/';
        $query = isset($url_parts['query']) ? $url_parts['query'] : '';

        // 重置 $_GET 和 $_REQUEST，防止污染
        $original_get = $_GET;
        $_GET = array();
        $_REQUEST = array();
        if (!empty($query)) {
            parse_str($query, $query_params);
            $_GET = $query_params;
            $_REQUEST = $query_params;
        }
        if (!empty($lang)) {
            $_GET['lang'] = $lang;
            $_REQUEST['lang'] = $lang;
        }
        $_SERVER['QUERY_STRING'] = $query;

        // 设置REQUEST_URI（与 parse_template_with_cache 缓存键公式一致）
        $original_request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $_SERVER['REQUEST_URI'] = $path . ($query ? '?' . $query : '');

        // 设置HTTP_HOST（与 parse_template_with_cache 缓存键公式一致）
        // 修复：之前缺失此项导致管理员预热生成的缓存文件与实际访客访问时文件名不同，预热完全失效
        $original_http_host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        if (isset($url_parts['host'])) {
            $_SERVER['HTTP_HOST'] = $url_parts['host'];
            // 仅非标准端口才附加（与 compute_template_cache_key_for_page 及浏览器行为一致）
            if (isset($url_parts['port']) && $url_parts['port'] != 80 && $url_parts['port'] != 443) {
                $_SERVER['HTTP_HOST'] .= ':' . $url_parts['port'];
            }
        }

        // 模拟GET请求
        $original_request_method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        // 0. 清空资源队列，确保每次生成缓存时都是干净的状态
        global $todo_scripts, $todo_styles, $todo_localized_scripts;
        $original_todo_scripts = isset($todo_scripts) ? $todo_scripts : array();
        $original_todo_styles = isset($todo_styles) ? $todo_styles : array();
        $original_todo_localized_scripts = isset($todo_localized_scripts) ? $todo_localized_scripts : array();
        $todo_scripts = array();
        $todo_styles = array();
        $todo_localized_scripts = array();
        
        // 1. 先保存原始会话状态（用于生成完成后恢复，避免管理员需要重新登录）
        $original_admin_user_id = null;
        $original_frontend_user_id = null;
        $original_admin_role = null;
        $original_frontend_role = null;
        if (class_exists('Todo_Session_Manager')) {
            Todo_Session_Manager::init();
            $original_admin_user_id = Todo_Session_Manager::get_admin_user_id();
            $original_frontend_user_id = Todo_Session_Manager::get_frontend_user_id();
            // 从会话中获取角色信息（用于恢复）
            if ($original_admin_user_id && isset($_SESSION['admin_user_role'])) {
                $original_admin_role = $_SESSION['admin_user_role'];
            }
            if ($original_frontend_user_id && isset($_SESSION['frontend_user_role'])) {
                $original_frontend_role = $_SESSION['frontend_user_role'];
            }
        }
        
        // 保存原始用户状态（用于恢复）
        $original_todo_user_manager_current_user = null;
        global $current_user, $user;
        $original_current_user = isset($current_user) ? $current_user : null;
        $original_user = isset($user) ? $user : null;
        
        // 保存原始会话变量（用于恢复）
        $original_session = array();
        if (session_status() === PHP_SESSION_ACTIVE) {
            $original_session = $_SESSION;
        }
        
        // 保存原始Cookie（内存中的值，用于恢复）
        $original_cookies = array();
        $admin_cookies = array(
            'todo_admin_auth',
            'todo_admin_token',
            'admin_auth',
            'admin_token',
            'todo_frontend_auth',
            'todo_frontend_token',
            'frontend_auth',
            'frontend_token',
            'todo_remember_token',
            'remember_token'
        );
        if (defined('COOKIEHASH') && isset($_COOKIE['cms_post_password_' . COOKIEHASH])) {
            $admin_cookies[] = 'cms_post_password_' . COOKIEHASH;
        }
        foreach ($_COOKIE as $ck => $cv) {
            if (strpos($ck, 'cms_post_password_') === 0 && !in_array($ck, $admin_cookies, true)) {
                $admin_cookies[] = $ck;
            }
        }
        foreach ($admin_cookies as $cookie_name) {
            if (isset($_COOKIE[$cookie_name])) {
                $original_cookies[$cookie_name] = $_COOKIE[$cookie_name];
            }
        }

        // 2. 临时清除所有用户会话信息（用于生成游客模式的缓存）
        if (class_exists('Todo_Session_Manager')) {
            Todo_Session_Manager::init();
            Todo_Session_Manager::clear_admin_session();
            Todo_Session_Manager::clear_frontend_session();
        }

        // 清除全局用户变量
        $current_user = null;
        $user = null;

        // 临时禁用用户管理器的用户检测（如果存在）
        // 直接清除 Todo_User_Manager 的静态 $current_user 属性
        if (class_exists('Todo_User_Manager')) {
            try {
                $reflection = new ReflectionClass('Todo_User_Manager');
                // 清除 $current_user 静态属性
                if ($reflection->hasProperty('current_user')) {
                    $property = $reflection->getProperty('current_user');
                    $property->setAccessible(true);
                    $original_todo_user_manager_current_user = $property->getValue(null);
                    $property->setValue(null, null);
                }
            } catch (Exception $e) {
                // 忽略反射错误
            }
        }
        
        // 清除会话变量中的敏感信息（但保留会话结构，用于CSRF令牌生成等）
        if (session_status() === PHP_SESSION_ACTIVE) {
            // 只清除管理员相关的会话键，保留其他会话数据（如CSRF令牌）
            $admin_session_keys = array(
                'admin_user_id',
                'admin_auth_token',
                'admin_user_role',
                'admin_login_time',
                'frontend_user_id',
                'frontend_auth_token',
                'frontend_user_role',
                'frontend_login_time'
            );
            foreach ($admin_session_keys as $key) {
                if (isset($_SESSION[$key])) {
                    unset($_SESSION[$key]);
                }
            }
        }
        
        // 清除内存中的Cookie值（确保生成的HTML中不包含这些值）
        foreach ($admin_cookies as $cookie_name) {
            if (isset($_COOKIE[$cookie_name])) {
                unset($_COOKIE[$cookie_name]);
            }
        }

        // 确保前台主题的 functions.php 已加载，否则预热请求中会丢失主题注册的 CSS/JS (Hook: todo_enqueue_scripts)
        if (class_exists('CMS_Theme')) {
            $active_theme = CMS_Theme::get_active_theme();
            if ($active_theme) {
                $active_theme->load_functions();
            }
        }

        $result = false;

        try {
            $template_path = null;

            // 提取核心路由参数（即使在路径模式下，有些系统也会带上这些参数作为后备）
            $post_id = isset($_GET['p']) ? intval($_GET['p']) : (isset($_GET['page_id']) ? intval($_GET['page_id']) : 0);
            $cat_id = isset($_GET['cat']) ? intval($_GET['cat']) : 0;
            $tag_id = isset($_GET['tag_id']) ? intval($_GET['tag_id']) : 0;

            error_log("[Warmup Debug] URL: $url, Lang: $lang, ForcedType: $forced_type, ForcedID: $forced_id, PostID: $post_id, CatID: $cat_id, Path: $path, Query: $query");

            // 同步预热环境下的 URL 上下文，以确保编译器生成的 cache_key 匹配真实访客的请求
            $_SERVER['REQUEST_URI'] = $path . ($query ? '?' . $query : '');
            if (!empty($host)) {
                $_SERVER['HTTP_HOST'] = $host;
            }

            // 0. 最优先：如果提供了 forced_type，直接使用它
            if (!empty($forced_type)) {
                error_log("[Warmup Debug] Using forced_type: $forced_type, forced_id: $forced_id");
                if ($forced_type === 'home') {
                    set_current_page_type('home');
                    $template_path = get_template_file_path('index');
                    file_put_contents(ABSPATH . 'logs/cache_debug.txt', "Forced home: url=$url, lang=$lang, template_path=" . var_export($template_path, true) . "\n", FILE_APPEND);
                    if ($template_path && file_exists($template_path)) {
                        $result = parse_html_template($template_path, array('page_type' => 'home'));
                    }
                } elseif ($forced_type === 'tag_index') {
                    set_current_page_type('tag_index');
                    $template_path = get_template_file_path('tag');
                    if ($template_path && file_exists($template_path)) {
                        $result = parse_html_template($template_path, array('page_type' => 'tag_index'));
                    }
                } elseif ($forced_type === 'tag_lists') {
                    set_current_page_type('tag_lists');
                    $template_path = get_template_file_path('lists_tags');
                    if ($template_path && file_exists($template_path)) {
                        $result = parse_html_template($template_path, array('page_type' => 'tag_lists'));
                    }
                } elseif ($forced_type === 'category' && $forced_id > 0) {
                    // 如果是单页模型分类，只生成单页缓存，不生成分类缓存
                    $single_post_id = self::resolve_single_category_post_id($forced_id);
                    if ($single_post_id > 0 && function_exists('get_post') && function_exists('set_current_post')) {
                        $post_obj = get_post($single_post_id);
                        if ($post_obj) {
                            // 标记为单页分类，使缓存键能正确包含站点维度
                            $GLOBALS['_todo_is_single_page_category'] = true;
                            set_current_page_type('single');
                            set_current_post($single_post_id);
                            $template_path = '';
                            $selected_template = '';
                            if (isset($post_obj->view_template) && $post_obj->view_template !== '') {
                                $selected_template = trim($post_obj->view_template);
                            } elseif (function_exists('get_post_meta')) {
                                $selected_template = trim(get_post_meta($single_post_id, 'view_template', true));
                            }
                            if ($selected_template !== '') {
                                $template_path = get_template_file_path($selected_template);
                            }
                            if (!$template_path) {
                                $template_path = get_template_file_path('view_' . $post_obj->post_type) ?: get_template_file_path('single_' . $post_obj->post_type);
                            }
                            if (!$template_path) {
                                $template_path = get_template_file_path('single') ?: get_template_file_path('post');
                            }
                            if ($template_path && file_exists($template_path)) {
                                $result = parse_html_template($template_path, array('page_type' => 'single', 'post_id' => $single_post_id, 'post' => $post_obj));
                            }
                            $html = is_string($result) ? trim($result) : '';
                            if ($html !== '') {
                                $result = $html;
                            } else {
                                $result = false;
                            }
                        }
                    } else {
                        // 检查是否为单页模型分类（model_key === 'single' 或 cat_slug === 'single'）
                        $is_single_cat = false;
                        if (class_exists('Todo_Model_Manager')) {
                            $cat_model_key = Todo_Model_Manager::get_category_model($forced_id);
                            if ($cat_model_key === 'single') {
                                $is_single_cat = true;
                            }
                        }
                        if (!$is_single_cat && function_exists('get_term_by')) {
                            $term_obj = get_term_by('id', $forced_id, 'category');
                            if ($term_obj && isset($term_obj->slug) && strtolower($term_obj->slug) === 'single') {
                                $is_single_cat = true;
                            }
                        }

                        if ($is_single_cat) {
                            // 单页模型分类本身不存在列表页，避免生成分类页缓存并跳过报错
                            $result = true;
                        } else {
                            // 普通分类，生成分类页缓存
                            set_current_page_type('category');
                            global $current_category_id;
                            $current_category_id = $forced_id;
                            $template_path = self::resolve_category_template_path($forced_id);
                            if ($template_path && file_exists($template_path)) {
                                $result = parse_html_template($template_path, array('page_type' => 'category', 'category_id' => $forced_id));
                            }
                        }
                    }
                } elseif ($forced_type === 'single' && $forced_id > 0) {
                    set_current_page_type('single');
                    if (function_exists('set_current_post'))
                        set_current_post($forced_id);
                    global $current_post, $post;
                    $post_obj = get_post($forced_id);
                    $current_post = $post_obj;
                    $post = $post_obj;
                    $GLOBALS['todo_post'] = $post_obj;

                    $template_path = '';
                    if ($post_obj && isset($post_obj->post_type)) {
                        $template_path = get_template_file_path('view_' . $post_obj->post_type) ?: get_template_file_path('single_' . $post_obj->post_type);
                    }
                    if (!$template_path) {
                        $template_path = get_template_file_path('single') ?: get_template_file_path('post');
                    }

                    if ($template_path && file_exists($template_path)) {
                        $result = parse_html_template($template_path, array('page_type' => 'single', 'post_id' => $forced_id, 'post' => $post_obj));
                    }
                }

                // 如果通过 forced_type 成功处理，记录日志
                if ($result !== false) {
                    error_log("[Warmup Debug] Successfully processed via forced_type");
                } else {
                    error_log("[Warmup Debug] Failed to process via forced_type, falling back to normal routing");
                }
            }

            // 如果还没有通过 forced_type 处理成功，继续正常的路由逻辑
            if ($result === false) {
                // 获取基础路径（移除可能的 index.php 和多语言前缀）
                $clean_path = $path;
                if (basename($clean_path) === 'index.php') {
                    $clean_path = dirname($clean_path);
                }
                $clean_path = rtrim($clean_path, '/');

                // 尝试剥离语言前缀（例如 /zh-CN/ -> /）
                if (!empty($lang)) {
                    $reg = class_exists('LanguageRegistry') ? LanguageRegistry::get_instance() : null;
                    if ($reg) {
                        $prefixes = array_keys($reg->path_prefix_map());
                        foreach ($prefixes as $prefix) {
                            $prefix = trim($prefix, '/');
                            if ($prefix !== '' && (preg_match("#^/{$prefix}(/|$)#", $clean_path) || $clean_path === "/{$prefix}")) {
                                error_log("[Warmup Debug] Stripping prefix: $prefix from $clean_path");
                                $clean_path = preg_replace("#^/{$prefix}#", '', $clean_path);
                                break;
                            }
                        }
                    }
                }
                $clean_path = rtrim($clean_path, '/') ?: '/';
                error_log("[Warmup Debug] Clean path: $clean_path");

                // 1. 优先根据明确的查询参数识别 (适用于 ?p=123 等)
                if ($post_id > 0) {
                    error_log("[Warmup Debug] Identified as Post by ID: $post_id");
                    set_current_page_type('single');
                    if (function_exists('set_current_post'))
                        set_current_post($post_id);
                    global $current_post, $post;
                    $post_obj = get_post($post_id);
                    $current_post = $post_obj;
                    $post = $post_obj;

                    $template_path = '';
                    if ($post_obj && isset($post_obj->post_type)) {
                        $selected_template = '';
                        if (isset($post_obj->view_template) && $post_obj->view_template !== '') {
                            $selected_template = trim($post_obj->view_template);
                        } elseif (function_exists('get_post_meta')) {
                            $selected_template = trim(get_post_meta($post_id, 'view_template', true));
                        }
                        if ($selected_template !== '') {
                            $template_path = get_template_file_path($selected_template);
                        }
                        if (!$template_path) {
                            $template_path = get_template_file_path('view_' . $post_obj->post_type) ?: get_template_file_path('single_' . $post_obj->post_type);
                        }
                    }
                    if (!$template_path) {
                        $template_path = get_template_file_path('single') ?: get_template_file_path('post');
                    }

                    if ($template_path && file_exists($template_path)) {
                        error_log("[Warmup Debug] Using template: $template_path");
                        $result = parse_html_template($template_path, array('page_type' => 'single', 'post_id' => $post_id, 'post' => $post_obj));
                    } else {
                        error_log("[Warmup Debug] Post template not found!");
                    }
                } elseif ($cat_id > 0) {
                    error_log("[Warmup Debug] Identified as Category by ID: $cat_id");
                    set_current_page_type('category');
                    global $current_category_id;
                    $current_category_id = $cat_id;
                    $template_path = self::resolve_category_template_path($cat_id);
                    if ($template_path && file_exists($template_path)) {
                        $result = parse_html_template($template_path, array('page_type' => 'category', 'category_id' => $cat_id));
                    }
                } elseif ($tag_id > 0) {
                    error_log("[Warmup Debug] Identified as Tag by ID: $tag_id");
                    set_current_page_type('tag');
                    global $current_tag_id;
                    $current_tag_id = $tag_id;
                    $template_path = get_template_file_path('tag') ?: get_template_file_path('archive');
                    if ($template_path && file_exists($template_path)) {
                        $result = parse_html_template($template_path, array('page_type' => 'tag', 'tag_id' => $tag_id));
                    }
                }
                // 2. 其次根据 URL 路径识别 (Slug 模式)
                elseif ($clean_path !== '/' && $clean_path !== '') {
                    error_log("[Warmup Debug] Checking path: $clean_path");
                    if ($clean_path === '/tags' || $clean_path === '/tags/index') {
                        set_current_page_type('tag_index');
                        $template_path = get_template_file_path('tag') ?: get_template_file_path('lists_tags');
                        if ($template_path && file_exists($template_path)) {
                            $result = parse_html_template($template_path, array('page_type' => 'tag_index'));
                        }
                    } else {
                        global $tododb;
                        if (isset($tododb)) {
                            $slug = basename($clean_path);
                            $slug = preg_replace('/\.(html|htm|php)$/i', '', $slug);
                            error_log("[Warmup Debug] Slug: $slug");

                            // 检查分类
                            $category = $tododb->get_row("SELECT t.term_id FROM {$tododb->prefix}terms t INNER JOIN {$tododb->prefix}term_taxonomy tt ON t.term_id = tt.term_id WHERE t.slug = ? AND tt.taxonomy = 'category'", array($slug));
                            if ($category) {
                                error_log("[Warmup Debug] Identified as Category by Slug: $slug");
                                set_current_page_type('category');
                                global $current_category_id;
                                $current_category_id = $category->term_id;
                                $template_path = self::resolve_category_template_path($category->term_id);
                                if ($template_path && file_exists($template_path)) {
                                    $result = parse_html_template($template_path, array('page_type' => 'category', 'category_id' => $category->term_id));
                                }
                            } else {
                                // 检查标签
                                $tag = $tododb->get_row("SELECT t.term_id FROM {$tododb->prefix}terms t INNER JOIN {$tododb->prefix}term_taxonomy tt ON t.term_id = tt.term_id WHERE t.slug = ? AND tt.taxonomy = 'post_tag'", array($slug));
                                if ($tag) {
                                    error_log("[Warmup Debug] Identified as Tag by Slug: $slug");
                                    set_current_page_type('tag');
                                    global $current_tag_id;
                                    $current_tag_id = $tag->term_id;
                                    $template_path = get_template_file_path('tag') ?: get_template_file_path('archive');
                                    if ($template_path && file_exists($template_path)) {
                                        $result = parse_html_template($template_path, array('page_type' => 'tag', 'tag_id' => $tag->term_id));
                                    }
                                } else {
                                    // 检查文章（同时匹配 post_name 和 custom_url）
                                    $post_types = array('post', 'article', 'product', 'download', 'images', 'media', 'special');
                                    $post_types_in = "'" . implode("','", $post_types) . "'";
                                    $post_row = $tododb->get_row("SELECT ID, post_type FROM {$tododb->prefix}posts WHERE (post_name = ? OR custom_url = ?) AND post_status = 'publish' AND post_type IN ($post_types_in) LIMIT 1", array($slug, $slug));
                                    if ($post_row) {
                                        error_log("[Warmup Debug] Identified as Post by Slug: $slug");
                                        set_current_page_type('single');
                                        if (function_exists('set_current_post'))
                                            set_current_post($post_row->ID);
                                        global $current_post, $post;
                                        $post_obj = get_post($post_row->ID);
                                        $current_post = $post_obj;
                                        $post = $post_obj;

                                        $template_path = '';
                                        $selected_template = '';
                                        if (isset($post_obj->view_template) && $post_obj->view_template !== '') {
                                            $selected_template = trim($post_obj->view_template);
                                        } elseif (function_exists('get_post_meta')) {
                                            $selected_template = trim(get_post_meta($post_row->ID, 'view_template', true));
                                        }
                                        if ($selected_template !== '') {
                                            $template_path = get_template_file_path($selected_template);
                                        }
                                        if (!$template_path) {
                                            $template_path = get_template_file_path('view_' . $post_row->post_type) ?: get_template_file_path('single_' . $post_row->post_type);
                                        }
                                        if (!$template_path) {
                                            $template_path = get_template_file_path('single') ?: get_template_file_path('post');
                                        }

                                        if ($template_path && file_exists($template_path)) {
                                            $result = parse_html_template($template_path, array('page_type' => 'single', 'post_id' => $post_row->ID, 'post' => $post_obj));
                                        }
                                    } else {
                                        error_log("[Warmup Debug] Unidentified Slug: $slug, fallback to custom/index");
                                        // 兜底：作为自定义页面
                                        $template_path = get_template_file_path('index');
                                        if ($template_path && file_exists($template_path)) {
                                            $result = parse_html_template($template_path, array('page_type' => 'custom'));
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                // 3. 兜底识别为首页
                else {
                    error_log("[Warmup Debug] Identified as Home");
                    set_current_page_type('home');
                    $template_path = get_template_file_path('index');
                    if ($template_path && file_exists($template_path)) {
                        $result = parse_html_template($template_path, array('page_type' => 'home'));
                    }
                }
            } // 结束 if ($result === false) 块
        } catch (Exception $e) {
            error_log("Generate cache error for URL $url: " . $e->getMessage());
        }

        // 恢复站点上下文
        if ($site_id > 0) {
            if ($original_site_cookie !== null) {
                $_COOKIE['todo_site_id'] = $original_site_cookie;
            } else {
                unset($_COOKIE['todo_site_id']);
            }
            if ($original_global_site_id !== null) {
                $GLOBALS['todo_current_site_id'] = $original_global_site_id;
            } else {
                unset($GLOBALS['todo_current_site_id']);
            }
        }

        // 恢复环境
        if (class_exists('CMS_Template_Compiler_V2')) {
            CMS_Template_Compiler_V2::set_forced_viewer_role(null);
        }
        $_SERVER['REQUEST_URI'] = $original_request_uri;
        $_SERVER['REQUEST_METHOD'] = $original_request_method;
        $_SERVER['HTTP_HOST'] = $original_http_host;
        $_GET = $original_get;

        // 恢复原始会话状态（使用 Todo_Session_Manager）
        if (class_exists('Todo_Session_Manager')) {
            Todo_Session_Manager::init();
            
            // 恢复原始管理员会话
            if ($original_admin_user_id && $original_admin_role) {
                Todo_Session_Manager::set_admin_session($original_admin_user_id, $original_admin_role, false);
            }
            
            // 恢复原始前台会话
            if ($original_frontend_user_id && $original_frontend_role) {
                Todo_Session_Manager::set_frontend_session($original_frontend_user_id, $original_frontend_role, false);
            }
        }
        
        // 恢复原始会话变量
        if (session_status() === PHP_SESSION_ACTIVE && !empty($original_session)) {
            // 恢复原始会话数据
            foreach ($original_session as $key => $value) {
                $_SESSION[$key] = $value;
            }
        }
        
        // 恢复原始Cookie（内存中的值）
        foreach ($original_cookies as $cookie_name => $cookie_value) {
            $_COOKIE[$cookie_name] = $cookie_value;
        }
        
        // 恢复原始资源队列
        global $todo_scripts, $todo_styles, $todo_localized_scripts;
        $todo_scripts = $original_todo_scripts;
        $todo_styles = $original_todo_styles;
        $todo_localized_scripts = $original_todo_localized_scripts;

        // 恢复全局用户变量
        global $current_user, $user;
        if ($original_current_user !== null)
            $current_user = $original_current_user;
        if ($original_user !== null)
            $user = $original_user;

        // 恢复用户管理器的当前用户（如果存在）
        if ($original_todo_user_manager_current_user !== null && class_exists('Todo_User_Manager')) {
            try {
                $reflection = new ReflectionClass('Todo_User_Manager');
                if ($reflection->hasProperty('current_user')) {
                    $property = $reflection->getProperty('current_user');
                    $property->setAccessible(true);
                    $property->setValue(null, $original_todo_user_manager_current_user);
                }
            } catch (Exception $e) {
                // 忽略反射错误
            }
        }

        if ($original_global_locale !== null)
            $GLOBALS['_todo_current_locale'] = $original_global_locale;
        else
            unset($GLOBALS['_todo_current_locale']);
        if ($original_locale !== null && class_exists('LocaleContext')) {
            try {
                LocaleContext::get_instance()->set_locale($original_locale);
            } catch (Exception $e) {
            }
        }
        if ($original_manager_locale !== null && class_exists('TodoLanguageManager')) {
            try {
                TodoLanguageManager::get_instance()->switch_language($original_manager_locale);
            } catch (Throwable $e) {
            }
        }

        unset($GLOBALS['todo_doing_cache_warmup']);

        $is_success = ($result !== false);
        if (is_string($result)) {
            $is_success = (trim($result) !== '');
        }
        return $is_success;
    }

    /**
     * 安排自动清理任务
     */
    private static function schedule_auto_cleanup()
    {
        // 注册定时任务动作（保持兼容性）
        todo_add_action('todo_auto_cache_cleanup', array(__CLASS__, 'auto_cleanup'));

        // 清理旧系统的 cron 任务，避免冲突
        global $tododb;
        if (isset($tododb) && method_exists($tododb, 'query')) {
            $table = defined('TABLE_PREFIX') ? TABLE_PREFIX . 'cron_jobs' : ($tododb->prefix . 'cron_jobs');
            $tododb->query($tododb->prepare("DELETE FROM $table WHERE hook = %s", 'todo_auto_cache_cleanup'));
        }

        // 采用前台触发机制，不再单纯依赖 todo_schedule_event
        // 挂载到 todo_init 钩子，由前端访问时触发
        todo_add_action('todo_init', array(__CLASS__, 'maybe_trigger_auto_cleanup'), 999);
    }

    /**
     * 前台触发自动清理检查
     */
    public static function maybe_trigger_auto_cleanup()
    {
        // 仅在前台执行，避免后台操作被阻断
        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX) || (defined('INSTALLING') && INSTALLING)) {
            return;
        }

        if (!self::$auto_cleanup_config['enabled']) {
            return;
        }

        $schedule = self::$auto_cleanup_config['schedule'];
        
        // 获取上次执行时间
        if (function_exists('todo_get_option')) {
            $last_run = (int) todo_get_option('todo_auto_cache_cleanup_last_run', 0);
        } elseif (function_exists('get_option')) {
            $last_run = (int) get_option('todo_auto_cache_cleanup_last_run', 0);
        } else {
            return;
        }
        
        $now = time();

        $interval = 86400; // 默认每天
        if ($schedule === 'hourly') {
            $interval = 3600;
        } elseif ($schedule === 'weekly') {
            $interval = 86400 * 7;
        }

        // 检查是否到达下一个执行周期
        if ($now - $last_run >= $interval) {
            // 立即更新时间戳，防止并发多次触发
            if (function_exists('update_option')) {
                update_option('todo_auto_cache_cleanup_last_run', $now);
            }
            
            // 执行清理逻辑
            self::auto_cleanup();
        }
    }

    /**
     * 自动清理过期缓存
     */
    public static function auto_cleanup()
    {
        $max_age = self::$auto_cleanup_config['max_age'];
        $max_size = self::$auto_cleanup_config['max_size'];

        $cache_dir = self::get_cache_dir_safe();
        $total_size = self::get_directory_size($cache_dir);

        $cleaned = 0;

        // 如果总大小超过限制，清理最旧的文件
        if ($total_size > $max_size) {
            $cleaned += self::cleanup_by_size($cache_dir, $max_size);
        }

        // 清理过期文件
        $cleaned += self::cleanup_by_age($cache_dir, $max_age);

        todo_log_info('Auto cache cleanup completed', array(
            'cleaned_files' => $cleaned,
            'total_size_before' => $total_size,
            'total_size_after' => self::get_directory_size($cache_dir)
        ));
    }

    /**
     * 按大小清理缓存
     * 
     * @param string $dir 目录
     * @param int $max_size 最大大小
     * @return int 清理的文件数
     */
    private static function cleanup_by_size($dir, $max_size)
    {
        if (!is_dir($dir)) {
            return 0;
        }

        // 收集所有文件，按修改时间排序（最旧优先）
        $files = array();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $basename = $file->getBasename();
                // 跳过保护文件
                if (in_array($basename, array('.htaccess', 'index.php', '.gitkeep'))) {
                    continue;
                }
                $files[] = array(
                    'path' => $file->getPathname(),
                    'mtime' => $file->getMTime(),
                    'size' => $file->getSize()
                );
            }
        }

        // 当前总大小
        $total_size = self::get_directory_size($dir);
        if ($total_size <= $max_size) {
            return 0;
        }

        // 按mtime升序（最旧 -> 最新）
        usort($files, function ($a, $b) {
            return $a['mtime'] <=> $b['mtime'];
        });

        $cleaned = 0;
        foreach ($files as $f) {
            if ($total_size <= $max_size) {
                break;
            }
            if (@unlink($f['path'])) {
                $total_size -= $f['size'];
                $cleaned++;
            }
        }

        return $cleaned;
    }

    /**
     * 按年龄清理缓存
     * 
     * @param string $dir 目录
     * @param int $max_age 最大年龄（秒）
     * @return int 清理的文件数
     */
    private static function cleanup_by_age($dir, $max_age)
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $cleaned = 0;
        $cutoff_time = time() - $max_age;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getMTime() < $cutoff_time) {
                if (@unlink($file->getPathname())) {
                    $cleaned++;
                }
            }
        }

        return $cleaned;
    }

    /**
     * 清理文章相关的所有缓存（对象缓存、页面缓存等）
     * 整合了原 todo_clean_post_cache 函数的所有功能
     * 
     * @param int $post_id 文章ID
     * @param bool $regenerate 是否重新生成缓存（在AJAX请求中应设为false）
     * @return bool 是否成功
     */
    public static function clean_post_cache($post_id, $regenerate = true)
    {
        if (!$post_id) {
            return false;
        }

        // 检测是否在AJAX请求中
        $is_ajax = defined('DOING_AJAX') && DOING_AJAX;
        if (!$is_ajax && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            $is_ajax = true;
        }

        // 在AJAX请求中，不重新生成缓存，只清理
        if ($is_ajax) {
            $regenerate = false;
        }

        $success = true;

        // 清理 post_meta 内存缓存
        if (function_exists('_todo_post_meta_cache_manager')) {
            _todo_post_meta_cache_manager('delete', $post_id);
        }

        // 清理全局内存缓存
        if (isset($GLOBALS['todo_internal_post_cache']) && isset($GLOBALS['todo_internal_post_cache'][$post_id])) {
            unset($GLOBALS['todo_internal_post_cache'][$post_id]);
        }

        // 清理文章对象缓存
        $cache_keys = array(
            'post_' . $post_id,
            'post_meta_' . $post_id,
            'post_categories_' . $post_id,
            'post_tags_' . $post_id,
            'post_permalink_' . $post_id,
            'post_translation_' . $post_id,
            'post_multilang_' . $post_id
        );

        foreach ($cache_keys as $key) {
            if (class_exists('Todo_Cache_Manager')) {
                // 清理 object 组（基本字段、meta等）
                Todo_Cache_Manager::delete($key, 'object');
                
                // 清理 posts 组（针对翻译和聚合数据）
                // 由于翻译缓存键包含语言代码，且 multilang 包含多种标志位，
                // 我们在 AJAX 请求中无法确定所有语言，因此尝试清理当前及全局语言，
                // 或者对于支持模式匹配的后端，直接匹配前缀。
                Todo_Cache_Manager::delete($key, 'posts');
                
                // 尝试清理所有语言下的该文章缓存（如果后端支持模式删除）
                // 模式：[prefix][group]:*:md5([key]) -> 这里我们无法直接构造 md5 匹配
                // 但我们可以针对常见前缀进行清理
                if (strpos($key, 'post_translation_') === 0 || strpos($key, 'post_multilang_') === 0) {
                     // 翻译相关缓存，尝试删除所有语言变体
                     // 注意：deleteByPattern 在不同后端的实现差异，目前主要支持 prefix:group:*
                     // 如果需要精准删除特定文章的所有语言缓存，可能需要特殊逻辑
                }
            }
        }

        // 额外的精准清理：针对 multilang 和 translation 的所有语言变体
        if (class_exists('Todo_Cache_Manager')) {
            // 清理 posts 组中所有语言的该文章相关记录
            // 注意：这取决于 deleteByPattern 是否能处理 MD5 后的部分。
            // 鉴于目前后端的实现，我们至少确保当前请求环境的缓存被清理。
            // 如果是在后台（zh-CN），我们会清理 zh-CN 下的缓存。
        }

        // 清理内存中的静态翻译缓存
        if (isset($GLOBALS['todo_post_translations_cache']) && isset($GLOBALS['todo_post_translations_cache'][$post_id])) {
            unset($GLOBALS['todo_post_translations_cache'][$post_id]);
        }

        // 清理 count_posts 缓存
        // 由于无法确定文章类型，清理所有常见类型的 count_posts 缓存
        if (class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::delete('count_posts_post', 'posts', 'global');
            Todo_Cache_Manager::delete('count_posts_any', 'posts', 'global');
        }

        // 清理所有自定义文章类型的 count_posts 缓存
        if (function_exists('get_post_types')) {
            $post_types = get_post_types();
            foreach ($post_types as $type) {
                if (is_array($type) && isset($type['slug']) && class_exists('Todo_Cache_Manager')) {
                    Todo_Cache_Manager::delete('count_posts_' . $type['slug'], 'posts', 'global');
                } elseif (is_string($type) && class_exists('Todo_Cache_Manager')) {
                    Todo_Cache_Manager::delete('count_posts_' . $type, 'posts', 'global');
                }
            }
        }

        // 清理全局查询缓存（如首页文章列表、分类文章列表等）
        if (function_exists('clear_data_cache')) {
            clear_data_cache();
        } else {
            // 如果函数不存在，通过统一缓存管理器清理数据缓存
            self::clear_cache('data');
        }

        // 页面级精准失效：内容页 + 属性依赖页 + 首页 + 相关分类列表页（请求内去重）
        // 所有模式统一，不再全清 template_html
        $is_admin_ctx = (defined('IS_ADMIN') && IS_ADMIN);
        $effective_regenerate = $regenerate && !$is_admin_ctx;
        self::invalidate_post_related_page_caches($post_id, $effective_regenerate);

        // 触发清理钩子（兼容旧钩子）
        if (function_exists('do_action')) {
            do_action('clean_post_cache', $post_id);
        }

        // 触发统一缓存管理器的钩子
        if (function_exists('todo_do_action')) {
            todo_do_action('todo_post_cache_cleared', $post_id);
        }

        // 记录日志
        if ($success) {
            self::log_info('Post cache cleaned successfully', array('post_id' => $post_id));
        } else {
            self::log_warning('Post cache clean partially failed', array('post_id' => $post_id));
        }

        return $success;
    }


    /**
     * 获取指定语言关联的所有站点ID列表
     * 
     * 用于缓存清理时遍历所有站点维度，确保旧缓存被完整清理。
     * 返回数组始终包含 0（无站点过滤的默认维度）。
     * 
     * @param string $lang 语言代码
     * @return int[] 站点ID数组
     */
    private static function get_site_ids_for_language($lang)
    {
        $site_ids = array(0); // 0 表示无站点维度（向后兼容）
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
        return $site_ids;
    }


    /**
     * 计算与 parse_template_with_cache 一致的模板缓存键
     * 
     * 缓存键公式：md5(模板路径 . REQUEST_URI . HTTP_HOST)
     * 必须与 parse_template_with_cache() 中的公式完全一致，否则精准清理将找不到文件
     * 
     * @param string      $url       完整URL（如 https://example.com/cat/slug/）
     * @param string      $page_type 页面类型：home, category, single
     * @param int         $id        post_id 或 category_id（用于获取模板路径）
     * @param string|null $lang      语言代码（可选，用于多语言路由）
     * @param int         $site_id   站点ID（0=无站点维度，仅 home/category 类型生效）
     * @return string 缓存键（md5 哈希），失败返回空字符串
     */
    private static function compute_template_cache_key_for_page($url, $page_type, $id = 0, $lang = null, $site_id = 0)
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        $port = $parts['port'] ?? '';

        if (!$host) {
            // 回退到站点URL的主机名
            if (function_exists('todo_home_url')) {
                $site_parts = parse_url(todo_home_url());
                if ($site_parts && isset($site_parts['host'])) {
                    $host = $site_parts['host'];
                    if (isset($site_parts['port'])) {
                        $port = $site_parts['port'];
                    }
                }
            }
        }

        // 构造 REQUEST_URI
        $path = $parts['path'] ?? '/';
        $query = $parts['query'] ?? '';
        $request_uri = $path . ($query ? '?' . $query : '');

        // 确定模板文件路径（与 generate_page_cache 中的逻辑一致）
        $template_path = '';
        switch ($page_type) {
            case 'home':
                if (function_exists('get_template_file_path')) {
                    $template_path = get_template_file_path('index');
                }
                break;

            case 'category':
                $single_post_id = self::resolve_single_category_post_id($id);
                if ($single_post_id > 0 && function_exists('get_post') && function_exists('get_template_file_path')) {
                    $post_obj = get_post($single_post_id);
                    if ($post_obj && isset($post_obj->post_type)) {
                        $selected_template = '';
                        if (isset($post_obj->view_template) && $post_obj->view_template !== '') {
                            $selected_template = trim($post_obj->view_template);
                        } elseif (function_exists('get_post_meta')) {
                            $selected_template = trim(get_post_meta($single_post_id, 'view_template', true));
                        }
                        if ($selected_template !== '') {
                            $template_path = get_template_file_path($selected_template);
                        }
                        if (!$template_path) {
                            $post_type = $post_obj->post_type;
                            $template_path = get_template_file_path('view_' . $post_type) ?: get_template_file_path('single_' . $post_type);
                        }
                        if (!$template_path) {
                            $template_path = get_template_file_path('single') ?: get_template_file_path('post');
                        }
                    }
                }
                if ($template_path === '') {
                    $template_path = self::resolve_category_template_path($id);
                }
                break;

            case 'tag_index':
            case 'tag':
            case 'tag_lists':
                if (function_exists('get_template_file_path')) {
                    $template_path = get_template_file_path('tag') ?: get_template_file_path('lists_tags') ?: get_template_file_path('archive');
                }
                break;

            case 'single':
                if ($id > 0 && function_exists('get_post') && function_exists('get_template_file_path')) {
                    $post_obj = get_post($id);
                    if ($post_obj && isset($post_obj->post_type)) {
                        $selected_template = '';
                        if (isset($post_obj->view_template) && $post_obj->view_template !== '') {
                            $selected_template = trim($post_obj->view_template);
                        } elseif (function_exists('get_post_meta')) {
                            $selected_template = trim(get_post_meta($id, 'view_template', true));
                        }
                        if ($selected_template !== '') {
                            $template_path = get_template_file_path($selected_template);
                        }
                        if (!$template_path) {
                            $template_path = get_template_file_path('view_' . $post_obj->post_type)
                                ?: get_template_file_path('single_' . $post_obj->post_type);
                        }
                    }
                }
                if (!$template_path && function_exists('get_template_file_path')) {
                    $template_path = get_template_file_path('single') ?: get_template_file_path('post');
                }
                break;

            default:
                return '';
        }

        if (!$template_path || !file_exists($template_path)) {
            return '';
        }

        // REQUEST_URI 中的语言前缀会被路由剥离，但缓存键生成时使用原始REQUEST_URI
        // 此处我们直接用 URL 的 path + query 部分

        // 构造 http_host（匹配 parse_template_with_cache 中 $_SERVER['HTTP_HOST'] 的格式）
        $http_host = $host;
        if ($port && $port != 80 && $port != 443) {
            $http_host .= ':' . $port;
        }

        // 站点维度：首页和分类页需要区分不同站点的缓存
        // 文章详情页不区分站点（数量过多，翻倍缓存不划算）
        $site_suffix = '';
        if ($site_id > 0 && in_array($page_type, array('home', 'category'), true)) {
            $site_suffix = '_site' . $site_id;
        }

        if ($request_uri !== '' && strpos($request_uri, 'todocache=') !== false) {
            if (function_exists('remove_query_arg')) {
                $request_uri = remove_query_arg('todocache', $request_uri);
            } else {
                $request_uri = preg_replace('/([?&])todocache=[^&]*&?/', '$1', $request_uri);
                $request_uri = rtrim($request_uri, '?&');
            }
        }

        return md5($template_path . $request_uri . $http_host . $site_suffix);
    }

    /**
     * 精准清理单个页面的模板缓存文件。
     *
     * @param string $url 完整URL
     * @param string $page_type 页面类型
     * @param int $id 关联ID
     * @param string $lang 语言代码
     * @return bool
     */
    public static function clear_page_cache_entry($url, $page_type, $id = 0, $lang = '', $site_id = 0)
    {
        if (!$url || !$page_type) {
            return false;
        }

        $lang = str_replace('_', '-', (string) $lang);
        if ($lang !== '' && function_exists('canonical_locale')) {
            $lang = canonical_locale($lang);
        }

        $original_locale = null;
        $original_global_locale = isset($GLOBALS['_todo_current_locale']) ? $GLOBALS['_todo_current_locale'] : null;
        $original_manager_locale = null;

        if ($lang !== '') {
            if (function_exists('current_locale')) {
                $original_locale = current_locale();
            }
            if (class_exists('TodoLanguageManager')) {
                try {
                    $original_manager_locale = TodoLanguageManager::get_instance()->get_current_language();
                } catch (Throwable $e) {
                    $original_manager_locale = null;
                }
            }

            $GLOBALS['_todo_current_locale'] = $lang;
            $_GET['lang'] = $lang;
            $_REQUEST['lang'] = $lang;

            if (class_exists('LocaleContext')) {
                try {
                    LocaleContext::get_instance()->set_locale($lang);
                } catch (Throwable $e) {
                }
            }
            if (class_exists('TodoLanguageManager')) {
                try {
                    TodoLanguageManager::get_instance()->switch_language($lang);
                } catch (Throwable $e) {
                }
            }
            if (class_exists('CMS_Theme')) {
                CMS_Theme::reset_active_theme_cache();
            }
        }

        $cache_key = self::compute_template_cache_key_for_page($url, $page_type, $id, $lang, $site_id);
        if ($cache_key === '') {
            $result = false;
        } else {
            $effective_locale = $lang;
            if ($effective_locale === '') {
                if (function_exists('current_locale')) {
                    $effective_locale = (string) current_locale();
                } elseif ($original_global_locale !== null) {
                    $effective_locale = (string) $original_global_locale;
                } else {
                    $effective_locale = 'zh-CN';
                }
            }
            if (function_exists('canonical_locale')) {
                $effective_locale = canonical_locale($effective_locale);
            }
            $locale_safe = preg_replace('/[^a-zA-Z0-9\-]/', '_', (string) $effective_locale);

            $base_cache_dir = '';
            if (function_exists('get_cache_dir')) {
                $base_cache_dir = (string) get_cache_dir('templates');
            }
            if ($base_cache_dir === '') {
                $base_cache_dir = ABSPATH . 'content/cache/templates/';
            }
            $base_cache_dir = rtrim(str_replace('\\', '/', $base_cache_dir), '/') . '/';
            $cache_file = $base_cache_dir . ($locale_safe ?: 'default') . '/' . $cache_key . '.html';

            $result = true;
            if (file_exists($cache_file)) {
                $result = @unlink($cache_file);
            }
        }

        if ($lang !== '') {
            if ($original_global_locale !== null) {
                $GLOBALS['_todo_current_locale'] = $original_global_locale;
            } else {
                unset($GLOBALS['_todo_current_locale']);
            }

            if ($original_locale !== null && class_exists('LocaleContext')) {
                try {
                    LocaleContext::get_instance()->set_locale($original_locale);
                } catch (Throwable $e) {
                }
            }
            if ($original_manager_locale !== null && class_exists('TodoLanguageManager')) {
                try {
                    TodoLanguageManager::get_instance()->switch_language($original_manager_locale);
                } catch (Throwable $e) {
                }
            }
            if (class_exists('CMS_Theme')) {
                CMS_Theme::reset_active_theme_cache();
            }
        }

        return $result;
    }

    /**
     * 安全地保存并切换全套多语言上下文（包含 Global / LocaleContext / TodoLanguageManager）
     * 避免因单侧切换导致的语言渲染与缓存保存目录不一致（如英文 HTML 误存入 zh-CN 目录）
     * 
     * @param string $lang 目标语言代码
     * @return array 恢复前需保存的语言状态数组
     */
    private static function switch_language_context($lang)
    {
        $state = array(
            'locale' => null,
            'global_locale' => null,
            'manager_locale' => null,
            'switched' => false,
        );

        if (empty($lang)) {
            return $state;
        }

        $lang = str_replace('_', '-', $lang);
        if (function_exists('canonical_locale')) {
            $lang = canonical_locale($lang);
        }

        if (function_exists('current_locale')) {
            $state['locale'] = current_locale();
        }
        if (isset($GLOBALS['_todo_current_locale'])) {
            $state['global_locale'] = $GLOBALS['_todo_current_locale'];
        }
        if (class_exists('TodoLanguageManager')) {
            try {
                $state['manager_locale'] = TodoLanguageManager::get_instance()->get_current_language();
            } catch (Throwable $e) {
                $state['manager_locale'] = null;
            }
        }

        $GLOBALS['_todo_current_locale'] = $lang;
        $_GET['lang'] = $lang;
        $_REQUEST['lang'] = $lang;

        if (class_exists('LocaleContext')) {
            try {
                LocaleContext::get_instance()->set_locale($lang);
            } catch (Throwable $e) {
            }
        }
        if (class_exists('TodoLanguageManager')) {
            try {
                TodoLanguageManager::get_instance()->switch_language($lang);
            } catch (Throwable $e) {
            }
        }
        if (class_exists('CMS_Theme')) {
            CMS_Theme::reset_active_theme_cache();
        }

        // 清理内存中的文章对象与翻译缓存，防止跨语言切换残留
        if (isset($GLOBALS['todo_internal_post_cache'])) {
            unset($GLOBALS['todo_internal_post_cache']);
        }
        if (isset($GLOBALS['todo_post_translations_cache'])) {
            unset($GLOBALS['todo_post_translations_cache']);
        }
        if (class_exists('CMS_Translation')) {
            CMS_Translation::clear_cache();
        }
        if (class_exists('Todo_Post_ORM')) {
            Todo_Post_ORM::clear_cache();
        }

        $state['switched'] = true;
        return $state;
    }

    /**
     * 恢复 switch_language_context 保存的原始语言上下文
     * 
     * @param array $state switch_language_context 返回的状态数组
     */
    private static function restore_language_context($state)
    {
        if (empty($state['switched'])) {
            return;
        }

        if ($state['global_locale'] !== null) {
            $GLOBALS['_todo_current_locale'] = $state['global_locale'];
        } else {
            unset($GLOBALS['_todo_current_locale']);
        }

        if ($state['locale'] !== null && class_exists('LocaleContext')) {
            try {
                LocaleContext::get_instance()->set_locale($state['locale']);
            } catch (Throwable $e) {
            }
        }
        if ($state['manager_locale'] !== null && class_exists('TodoLanguageManager')) {
            try {
                TodoLanguageManager::get_instance()->switch_language($state['manager_locale']);
            } catch (Throwable $e) {
            }
        }
        if (class_exists('CMS_Theme')) {
            CMS_Theme::reset_active_theme_cache();
        }

        // 清理内存中的文章对象与翻译缓存，防止跨语言切换残留
        if (isset($GLOBALS['todo_internal_post_cache'])) {
            unset($GLOBALS['todo_internal_post_cache']);
        }
        if (isset($GLOBALS['todo_post_translations_cache'])) {
            unset($GLOBALS['todo_post_translations_cache']);
        }
        if (class_exists('CMS_Translation')) {
            CMS_Translation::clear_cache();
        }
        if (class_exists('Todo_Post_ORM')) {
            Todo_Post_ORM::clear_cache();
        }
    }

    /**
     * 精准清理特定文章的缓存（仅清理文章本身的缓存，不清理首页和列表页）
     * 用于动态模式且 ttl=0 时的精准缓存更新
     * 
     * @param int $post_id 文章ID
     */
    private static function clear_post_specific_cache($post_id)
    {
        $cache_dir = ABSPATH . 'content/cache/templates/';

        if (!is_dir($cache_dir)) {
            return;
        }

        if (!function_exists('todo_get_permalink') && !function_exists('get_permalink')) {
            return;
        }

        $patterns = array();

        // 获取所有支持的语言
        $languages = array('zh-CN');
        if (class_exists('TodoLanguageManager')) {
            $languages = array_keys(TodoLanguageManager::get_instance()->get_supported_languages());
        }

        foreach ($languages as $lang) {
            // 切换全套语言环境
            $lang_state = self::switch_language_context($lang);

            // 1) 文章页面缓存键（single 类型）
            $permalink = function_exists('todo_get_permalink') ? todo_get_permalink($post_id) : get_permalink($post_id);
            if ($permalink) {
                if (function_exists('get_language_url')) {
                    $permalink = get_language_url($lang, $permalink);
                }
                $key = self::compute_template_cache_key_for_page($permalink, 'single', $post_id, $lang);
                if ($key) $patterns[] = $key;

                // 也检查带查询参数 p= 的变体
                $key_with_p = self::compute_template_cache_key_for_page($permalink . '?p=' . $post_id, 'single', $post_id, $lang);
                if ($key_with_p && $key_with_p !== $key) $patterns[] = $key_with_p;
            }

            // 2) 分类页面缓存（文章所属的分类页面），需要按站点维度清理
            if (function_exists('get_the_category')) {
                $categories = get_the_category($post_id);
                if (!empty($categories)) {
                    // 获取该语言的所有站点ID
                    $site_ids = self::get_site_ids_for_language($lang);
                    foreach ($categories as $cat) {
                        if (isset($cat->term_id)) {
                            $cat_url = function_exists('todo_get_category_link')
                                ? todo_get_category_link($cat->term_id)
                                : (function_exists('get_category_link') ? get_category_link($cat->term_id) : '');
                            if ($cat_url) {
                                if (function_exists('get_language_url')) {
                                    $cat_url = get_language_url($lang, $cat_url);
                                }
                                foreach ($site_ids as $sid) {
                                    $key = self::compute_template_cache_key_for_page($cat_url, 'category', $cat->term_id, $lang, $sid);
                                    if ($key) $patterns[] = $key;
                                }
                            }
                        }
                    }
                }
            }

            self::restore_language_context($lang_state);
        }

        // 删除匹配的缓存文件（支持按语言分目录的模板缓存）
        $deleted = 0;
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iter as $fileInfo) {
            if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'html') {
                $filename = basename($fileInfo->getFilename(), '.html');
                if (in_array($filename, $patterns, true)) {
                    if (@unlink($fileInfo->getPathname())) {
                        $deleted++;
                    }
                }
            }
        }
    }

    /**
     * 精准清理首页缓存
     */
    private static function precise_clear_home_cache()
    {
        $cache_dir = ABSPATH . 'content/cache/templates/';

        if (!is_dir($cache_dir)) {
            return;
        }

        // 获取所有支持的语言
        $languages = array('zh-CN');
        if (class_exists('TodoLanguageManager')) {
            $languages = array_keys(TodoLanguageManager::get_instance()->get_supported_languages());
        }

        $patterns = array();
        foreach ($languages as $lang) {
            // 切换全套语言环境
            $lang_state = self::switch_language_context($lang);

            // 获取该语言的所有站点ID
            $site_ids = self::get_site_ids_for_language($lang);

            // 获取首页URL并使用一致的缓存键计算公式
            if (function_exists('todo_home_url')) {
                $home_url = todo_home_url();
                if ($home_url) {
                    if (function_exists('get_language_url')) {
                        $home_url = get_language_url($lang, $home_url);
                    }
                    foreach ($site_ids as $sid) {
                        // 匹配带/不带结尾斜杠的变体
                        $key1 = self::compute_template_cache_key_for_page($home_url, 'home', 0, $lang, $sid);
                        if ($key1) $patterns[] = $key1;

                        $key2 = self::compute_template_cache_key_for_page(rtrim($home_url, '/') . '/', 'home', 0, $lang, $sid);
                        if ($key2 && $key2 !== $key1) $patterns[] = $key2;
                    }
                }
            }

            self::restore_language_context($lang_state);
        }

        // 删除匹配的缓存文件
        $deleted = 0;
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iter as $fileInfo) {
            if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'html') {
                $filename = basename($fileInfo->getFilename(), '.html');
                if (in_array($filename, $patterns, true)) {
                    if (@unlink($fileInfo->getPathname())) {
                        $deleted++;
                    }
                }
            }
        }

        if ($deleted > 0) {
            todo_log_info('Home page cache cleared precisely', array('deleted_files' => $deleted));
        }
    }

    /**
     * 精准清理分类列表页缓存
     * 
     * @param int $category_id 分类ID
     */
    private static function precise_clear_category_cache($category_id)
    {
        $cache_dir = ABSPATH . 'content/cache/templates/';

        if (!is_dir($cache_dir)) {
            return;
        }

        // 获取所有支持的语言
        $languages = array('zh-CN');
        if (class_exists('TodoLanguageManager')) {
            $languages = array_keys(TodoLanguageManager::get_instance()->get_supported_languages());
        }

        $patterns = array();
        foreach ($languages as $lang) {
            // 切换全套语言环境
            $lang_state = self::switch_language_context($lang);

            // 获取该语言的所有站点ID
            $site_ids = self::get_site_ids_for_language($lang);

            // 获取分类页URL并使用一致的缓存键计算公式
            if (function_exists('todo_get_category_link') || function_exists('get_category_link')) {
                $cat_url = function_exists('todo_get_category_link')
                    ? todo_get_category_link($category_id)
                    : get_category_link($category_id);
                if ($cat_url) {
                    if (function_exists('get_language_url')) {
                        $cat_url = get_language_url($lang, $cat_url);
                    }
                    foreach ($site_ids as $sid) {
                        $key = self::compute_template_cache_key_for_page($cat_url, 'category', $category_id, $lang, $sid);
                        if ($key) $patterns[] = $key;
                    }
                }
            }

            self::restore_language_context($lang_state);
        }

        // 删除匹配的缓存文件
        $deleted = 0;
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iter as $fileInfo) {
            if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'html') {
                $filename = basename($fileInfo->getFilename(), '.html');
                if (in_array($filename, $patterns, true)) {
                    if (@unlink($fileInfo->getPathname())) {
                        $deleted++;
                    }
                }
            }
        }

        if ($deleted > 0) {
            todo_log_info('Category page cache cleared precisely', array('category_id' => $category_id, 'deleted_files' => $deleted));
        }
    }

    /**
     * 精准生成首页缓存
     */
    private static function precise_regenerate_home_cache()
    {
        // 获取所有支持的语言
        $languages = array('zh-CN');
        if (class_exists('TodoLanguageManager')) {
            $languages = array_keys(TodoLanguageManager::get_instance()->get_supported_languages());
        }

        foreach ($languages as $lang) {
            // 切换全套语言环境
            $lang_state = self::switch_language_context($lang);

            // 获取该语言的所有站点ID
            $site_ids = self::get_site_ids_for_language($lang);

            // 获取首页URL
            if (function_exists('todo_home_url')) {
                $home_url = todo_home_url();
                if ($home_url) {
                    if (function_exists('get_language_url')) {
                        $home_url = get_language_url($lang, $home_url);
                    }
                    foreach ($site_ids as $sid) {
                        // 生成首页缓存（游客角色），按站点维度区分
                        self::generate_page_cache($home_url, $lang, 'home', 0, 'guest', $sid);
                    }
                }
            }

            self::restore_language_context($lang_state);
        }
    }

    /**
     * 精准生成分类列表页缓存
     * 
     * @param int $category_id 分类ID
     */
    private static function precise_regenerate_category_cache($category_id)
    {
        // 获取所有支持的语言
        $languages = array('zh-CN');
        if (class_exists('TodoLanguageManager')) {
            $languages = array_keys(TodoLanguageManager::get_instance()->get_supported_languages());
        }

        foreach ($languages as $lang) {
            // 切换全套语言环境
            $lang_state = self::switch_language_context($lang);

            // 获取该语言的所有站点ID
            $site_ids = self::get_site_ids_for_language($lang);

            // 获取分类页URL
            if (function_exists('todo_get_category_link') || function_exists('get_category_link')) {
                $cat_url = function_exists('todo_get_category_link')
                    ? todo_get_category_link($category_id)
                    : get_category_link($category_id);
                if ($cat_url) {
                    if (function_exists('get_language_url')) {
                        $cat_url = get_language_url($lang, $cat_url);
                    }
                    foreach ($site_ids as $sid) {
                        // 生成分类列表页缓存（游客角色），按站点维度区分
                        self::generate_page_cache($cat_url, $lang, 'category', $category_id, 'guest', $sid);
                    }
                }
            }

            self::restore_language_context($lang_state);
        }
    }

    /**
     * 异步缓存预热（使用后台进程）
     * 
     * @param array $urls 可选的URL列表
     */
    private static function async_warmup_cache($urls = null)
    {
        // 如果手动传入了URLs，即使配置中未启用也允许执行（手动触发）
        $is_manual_trigger = !empty($urls);

        if (!self::$warmup_config['enabled'] && !$is_manual_trigger) {
            return;
        }

        // 构建预热脚本路径
        $script_path = TODO_ROOT . '/core/scripts/cache-warmup.php';

        // 如果脚本不存在，创建它
        if (!file_exists($script_path)) {
            self::create_warmup_script($script_path);
        }

        // 检查是否可以使用 shell 函数
        if (!self::can_use_shell_functions()) {
            // 如果无法使用异步执行，记录日志但不执行
            todo_log_warning('Async cache warmup skipped: shell functions disabled');
            return;
        }

        // 准备参数
        $php_binary = self::get_php_binary();
        $urls_param = $urls ? base64_encode(json_encode($urls)) : '';

        // 构建命令
        $command = sprintf(
            '%s %s %s > /dev/null 2>&1 &',
            escapeshellarg($php_binary),
            escapeshellarg($script_path),
            escapeshellarg($urls_param)
        );

        // 执行后台命令
        @exec($command);

        todo_log_info('Async cache warmup initiated');
    }

    /**
     * 获取PHP二进制文件路径
     * 
     * @return string PHP二进制文件路径
     */
    private static function get_php_binary()
    {
        // 检查 open_basedir 限制
        $open_basedir = ini_get('open_basedir');
        $allowed_paths = $open_basedir ? explode(PATH_SEPARATOR, $open_basedir) : [];

        // 优先使用PHP_BINARY（如果可用）
        if (defined('PHP_BINARY') && PHP_BINARY) {
            // 检查 PHP_BINARY 是否在允许的路径中
            if (empty($allowed_paths)) {
                return PHP_BINARY;
            }
            foreach ($allowed_paths as $allowed_path) {
                if (strpos(PHP_BINARY, rtrim($allowed_path, '/')) === 0) {
                    return PHP_BINARY;
                }
            }
        }

        // 如果 open_basedir 未设置，尝试查找常见路径
        if (empty($allowed_paths)) {
            $brew_php_paths = array(
                '/opt/homebrew/bin/php',  // Apple Silicon Mac
                '/usr/local/bin/php',     // Intel Mac
            );

            foreach ($brew_php_paths as $path) {
                if (@file_exists($path) && @is_executable($path)) {
                    return $path;
                }
            }
        }

        // 回退到 'php'（使用系统PATH）
        return 'php';
    }

    /**
     * 检查是否可以使用 shell 函数
     * 
     * @return bool
     */
    private static function can_use_shell_functions()
    {
        // 检查必要的函数是否可用
        if (!function_exists('escapeshellarg') || !function_exists('exec')) {
            return false;
        }

        // 检查是否在禁用列表中
        $disabled_functions = ini_get('disable_functions');
        if ($disabled_functions) {
            $disabled = array_map('trim', explode(',', $disabled_functions));
            if (in_array('exec', $disabled) || in_array('escapeshellarg', $disabled)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 创建缓存预热脚本
     * 
     * @param string $script_path 脚本路径
     */
    private static function create_warmup_script($script_path)
    {
        $script_dir = dirname($script_path);
        if (!is_dir($script_dir)) {
            mkdir($script_dir, 0755, true);
        }

        $script_content = '<?php
/**
 * TodoCMS 缓存预热脚本
 * 独立运行，避免阻塞主请求
 */

// 设置基本路径
define("TODO_ROOT", dirname(dirname(__DIR__)));

// 加载核心文件
require_once TODO_ROOT . "/core/bootstrap.php";

// 获取URL参数
$urls_param = isset($argv[1]) ? $argv[1] : "";
$urls = null;

if (!empty($urls_param)) {
    $decoded = base64_decode($urls_param);
    if ($decoded) {
        $urls = json_decode($decoded, true);
    }
}

// 清除定时发布标志
if (isset($GLOBALS["todo_scheduled_published"])) {
    unset($GLOBALS["todo_scheduled_published"]);
}

// 执行缓存预热
if (class_exists("Todo_Unified_Cache_Manager")) {
    $result = Todo_Unified_Cache_Manager::warmup_cache($urls);
    
    // 记录结果
    if (function_exists("todo_log_info")) {
        todo_log_info("Background cache warmup completed", $result);
    }
} else {
    error_log("TodoCMS: Cache manager class not found in warmup script");
}
';

        file_put_contents($script_path, $script_content);
        chmod($script_path, 0755);
    }

    /**
     * 批量判断文章出现在哪些分类列表页的第一页（替代逐一调用 is_post_in_category_page）
     * 通过子查询 + UNION 一次性完成 N 个分类的判断，大幅减少数据库往返
     *
     * @param int   $post_id      文章ID
     * @param array $category_ids 分类ID数组
     * @return array 包含该文章的分类ID数组
     */
    private static function get_category_ids_containing_post($post_id, array $category_ids)
    {
        if (empty($category_ids)) {
            return [];
        }

        global $tododb;
        if (!isset($tododb)) {
            return [];
        }

        $posts_per_page = 10;
        if (function_exists('todo_get_option')) {
            $posts_per_page = (int) todo_get_option('posts_per_page', 10);
        } elseif (function_exists('get_option')) {
            $posts_per_page = (int) get_option('posts_per_page', 10);
        }
        $posts_per_page = max(1, $posts_per_page);

        $now_gmt  = current_time('mysql', true);
        $post_id  = intval($post_id);
        $affected = [];

        // 对每个分类执行一次子查询判断（仍是单独查询，但不再依赖 get_posts 的重量级路径）
        // 用 UNION ALL 把所有分类合并为一条 SQL，彻底消除 N 次往返
        $union_parts  = [];
        $params       = [];

        foreach ($category_ids as $cat_id) {
            $cat_id = intval($cat_id);
            // 子查询：该分类第一页的 post_id 列表
            $union_parts[] = "SELECT ? AS cat_id, EXISTS (
                SELECT 1 FROM (
                    SELECT p.ID
                    FROM {$tododb->prefix}posts p
                    WHERE p.post_status = 'publish'
                      AND p.post_date_gmt <= ?
                      AND (p.category_id = ? OR EXISTS (
                          SELECT 1 FROM {$tododb->prefix}term_relationships tr
                          JOIN {$tododb->prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                          WHERE tr.object_id = p.ID AND tt.taxonomy = 'category' AND tt.term_id = ?
                      ))
                    ORDER BY p.post_date DESC, p.ID DESC
                    LIMIT ?
                ) AS page_posts
                WHERE page_posts.ID = ?
            ) AS in_page";

            $params[] = $cat_id;   // SELECT ? AS cat_id
            $params[] = $now_gmt;  // post_date_gmt <= ?
            $params[] = $cat_id;   // p.category_id = ?
            $params[] = $cat_id;   // tt.term_id = ?
            $params[] = $posts_per_page; // LIMIT ?
            $params[] = $post_id;  // page_posts.ID = ?
        }

        $sql     = implode(' UNION ALL ', $union_parts);
        $results = $tododb->get_results($sql, $params);

        if (!empty($results)) {
            foreach ($results as $row) {
                if ((int)($row->in_page ?? 0) === 1) {
                    $affected[] = (int)$row->cat_id;
                }
            }
        }

        return $affected;
    }

    /**
     * 判断文章是否在首页显示
     * 通过查询首页显示的最新N篇文章来判断
     * 
     * @param int $post_id 文章ID
     * @return bool 是否在首页显示
     */
    private static function is_post_in_home_page($post_id)
    {
        // 获取首页显示的文章数量（通常从模板配置或系统设置获取）
        $posts_per_page = 10;
        if (function_exists('todo_get_option')) {
            $posts_per_page = (int) todo_get_option('posts_per_page', 10);
        } elseif (function_exists('get_option')) {
            $posts_per_page = (int) get_option('posts_per_page', 10);
        }

        // 直接 SQL 查询，避免通过 get_posts() 触发无用的 model_fields_product LEFT JOIN
        global $tododb;
        if (!isset($tododb)) {
            return false;
        }

        $now_gmt = current_time('mysql', true);
        $sql = "SELECT ID FROM {$tododb->prefix}posts WHERE post_status = 'publish' AND post_date_gmt <= ?
             ORDER BY post_date DESC, ID DESC LIMIT ?";
        $ids = $tododb->get_col($sql, [$now_gmt, $posts_per_page]);

        return !empty($ids) && in_array($post_id, array_map('intval', $ids));
    }

    /**
     * 判断文章是否在分类列表页显示
     * 通过查询分类页显示的最新N篇文章来判断
     * 
     * @param int $post_id 文章ID
     * @param int $category_id 分类ID
     * @return bool 是否在分类页显示
     */
    private static function is_post_in_category_page($post_id, $category_id)
    {
        // 获取列表页显示的文章数量
        $posts_per_page = 10;
        if (function_exists('todo_get_option')) {
            $posts_per_page = (int) todo_get_option('posts_per_page', 10);
        } elseif (function_exists('get_option')) {
            $posts_per_page = (int) get_option('posts_per_page', 10);
        }

        // 直接 SQL 查询，避免通过 get_posts() 触发无用的 model_fields_product LEFT JOIN
        global $tododb;
        if (!isset($tododb)) {
            return false;
        }

        $now_gmt = current_time('mysql', true);
        $cat_id = intval($category_id);
        $sql = "SELECT p.ID FROM {$tododb->prefix}posts p
             LEFT JOIN {$tododb->prefix}term_relationships tr ON p.ID = tr.object_id
             LEFT JOIN {$tododb->prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'category' AND tt.term_id = ?
             WHERE p.post_status = 'publish' AND p.post_date_gmt <= ?
               AND (p.category_id = ? OR tt.term_id IS NOT NULL)
             GROUP BY p.ID
             ORDER BY p.post_date DESC, p.ID DESC LIMIT ?";
        $ids = $tododb->get_col($sql, [$cat_id, $now_gmt, $cat_id, $posts_per_page]);

        return !empty($ids) && in_array($post_id, array_map('intval', $ids));
    }

    /**
     * 获取文章所属的分类ID列表
     * 
     * @param int $post_id 文章ID
     * @return array 分类ID数组
     */
    private static function get_post_category_ids($post_id)
    {
        $category_ids = array();

        if (function_exists('get_the_category')) {
            $categories = get_the_category($post_id);
            if (!empty($categories)) {
                foreach ($categories as $cat) {
                    if (isset($cat->term_id)) {
                        $category_ids[] = (int) $cat->term_id;
                    }
                }
            }
        } else {
            // 回退到直接查询数据库
            global $tododb;
            if (isset($tododb)) {
                $results = $tododb->get_results(
                    "SELECT tt.term_id FROM {$tododb->prefix}term_relationships tr 
                     INNER JOIN {$tododb->prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id 
                     WHERE tr.object_id = ? AND tt.taxonomy = 'category'",
                    array($post_id)
                );
                if (!empty($results)) {
                    foreach ($results as $row) {
                        $category_ids[] = (int) $row->term_id;
                    }
                }
            }
        }

        return $category_ids;
    }



}

// 便捷函数
if (!function_exists('todo_clear_cache')) {
    /**
     * 便捷函数：清理缓存
     * 
     * @param string|array $type 缓存类型
     */
    function todo_clear_cache($type = null)
    {
        // 兼容：不传参时清理所有缓存
        if ($type === null) {
            return Todo_Unified_Cache_Manager::clear_all_caches();
        }
        if (is_array($type)) {
            return Todo_Unified_Cache_Manager::clear_multiple_caches($type);
        } else {
            return Todo_Unified_Cache_Manager::clear_cache($type);
        }
    }
}

if (!function_exists('todo_warmup_cache')) {
    /**
     * 便捷函数：缓存预热
     * 
     * @param array $urls URL列表
     * @return array 结果
     */
    function todo_warmup_cache($urls = null)
    {
        return Todo_Unified_Cache_Manager::warmup_cache($urls);
    }
}

if (!function_exists('todo_get_cache_stats')) {
    /**
     * 便捷函数：获取缓存统计
     * 
     * @param string $type 可选的类型
     * @return array 统计信息
     */
    function todo_get_cache_stats($type = null)
    {
        return Todo_Unified_Cache_Manager::get_cache_statistics($type);
    }
}
