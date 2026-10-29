<?php
/**
 * 缓存管理核心函数
 * TodoCMS Cache Management Functions
 *
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 递归创建目录
 */
if (!function_exists('todo_mkdir_p')) {
    function todo_mkdir_p($target)
    {
        $wrapper = null;

        // Strip the protocol
        if (todo_is_stream($target)) {
            list($wrapper, $target) = explode('://', $target, 2);
        }

        // From php.net/mkdir user contributed notes
        $target = str_replace('//', '/', $target);

        // Put the wrapper back on the target
        if ($wrapper !== null) {
            $target = $wrapper . '://' . $target;
        }

        /*
         * Safe mode fails with a trailing slash under certain PHP versions.
         * Use rtrim() instead of untrailingslashit to avoid formatting.php dependency.
         */
        $target = rtrim($target, '/');
        if (empty($target)) {
            $target = '/';
        }

        if (file_exists($target)) {
            return @is_dir($target);
        }

        // We need to find the permissions of the parent folder that exists and inherit that.
        $target_parent = dirname($target);
        while ('.' != $target_parent && !is_dir($target_parent) && dirname($target_parent) !== $target_parent) {
            $target_parent = dirname($target_parent);
        }

        // Get the permission bits.
        if ($stat = @stat($target_parent)) {
            $dir_perms = $stat['mode'] & 0007777;
        } else {
            $dir_perms = 0755;
        }

        if (@mkdir($target, $dir_perms, true)) {
            /*
             * If a umask is set that modifies $dir_perms, we'll have to re-set
             * the $dir_perms correctly with chmod()
             */
            if (function_exists('chmod') && ($dir_perms & ~umask()) != $dir_perms) {
                $folder_parts = explode('/', substr($target, strlen($target_parent) + 1));
                for ($i = 1, $c = count($folder_parts); $i <= $c; $i++) {
                    @chmod($target_parent . '/' . implode('/', array_slice($folder_parts, 0, $i)), $dir_perms);
                }
            }

            return true;
        }

        return false;
    }
}

/**
 * 检查是否为流URL
 */
if (!function_exists('todo_is_stream')) {
    function todo_is_stream($path)
    {
        $scheme_separator = strpos($path, '://');

        if (false === $scheme_separator) {
            // $path isn't a stream
            return false;
        }

        $stream = substr($path, 0, $scheme_separator);

        return in_array($stream, stream_get_wrappers(), true);
    }
}

/**
 * 获取缓存目录路径
 */
function get_cache_dir($type = '')
{
    $base_cache_dir = ABSPATH . 'content/cache/';

    if (empty($type)) {
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
 * 确保缓存目录存在
 */
function ensure_cache_directories()
{
    $cache_dirs = array(
        'templates',
        'objects',
        'php',
        'tables',
    );

    foreach ($cache_dirs as $dir) {
        $cache_dir = get_cache_dir($dir);
        if (!file_exists($cache_dir)) {
            todo_mkdir_p($cache_dir);

            // 创建 .htaccess 文件保护缓存目录
            $htaccess_content = "# TodoCMS Cache Directory Protection\nDeny from all\n";
            file_put_contents($cache_dir . '.htaccess', $htaccess_content);

            // 创建 index.php 文件
            file_put_contents($cache_dir . 'index.php', '<?php // Silence is golden');
        }
    }
}

/**
 * 清理数据缓存（对象缓存：数据库查询、options、posts 等）
 */
function clear_data_cache()
{
    if (class_exists('Todo_Cache_Manager')) {
        return Todo_Cache_Manager::flush_all();
    }
    // 回退：直接清理 objects 目录
    $cache_dir = get_cache_dir('objects');
    return clear_cache_directory($cache_dir);
}

/**
 * 清理PHP编译缓存
 */
function clear_php_compile_cache()
{
    $cache_dir = get_cache_dir('php');
    return clear_cache_directory($cache_dir);
}

function clear_template_html_cache()
{
    if (class_exists('CMS_Template_Compiler_V2')) {
        if (method_exists('CMS_Template_Compiler_V2', 'clear_template_html_cache')) {
            return CMS_Template_Compiler_V2::clear_template_html_cache();
        }
        return CMS_Template_Compiler_V2::clear_cache();
    }
    $cache_dir = get_cache_dir('templates');
    return clear_cache_directory($cache_dir);
}

/**
 * 清理数据表缓存
 */
function clear_table_cache()
{
    $cache_dir = get_cache_dir('tables');
    return clear_cache_directory($cache_dir);
}

/**
 * 清理对象缓存
 */
function clear_object_cache()
{
    // 使用Todo_Cache_Manager清理对象缓存
    if (class_exists('Todo_Cache_Manager')) {
        return Todo_Cache_Manager::flush_all();
    }

    // 回退方案：直接清理objects目录
    $cache_dir = get_cache_dir() . 'objects/';
    return clear_cache_directory($cache_dir);
}

/**
 * 清理指定目录的缓存文件
 */
function clear_cache_directory($dir)
{
    if (!file_exists($dir) || !is_dir($dir)) {
        return true; // 目录不存在视为清理成功
    }

    $files = glob($dir . '*');
    $success = true;

    foreach ($files as $file) {
        if (is_file($file)) {
            // 跳过保护文件
            $filename = basename($file);
            if (in_array($filename, array('.htaccess', 'index.php', '.gitkeep'))) {
                continue;
            }

            if (!unlink($file)) {
                $success = false;
            }
        } elseif (is_dir($file)) {
            // 递归清理子目录
            if (!clear_cache_directory($file . '/')) {
                $success = false;
            }
        }
    }

    return $success;
}

/**
 * 清理所有缓存（兼容性包装函数）
 * 实际功能已整合到 Todo_Unified_Cache_Manager::clear_all_caches()
 *
 * @return array|bool 结果数组或是否成功
 * @deprecated 请直接使用 Todo_Unified_Cache_Manager::clear_all_caches()
 */
function clear_all_cache()
{
    // 优先使用统一缓存管理器
    if (class_exists('Todo_Unified_Cache_Manager')) {
        $result = Todo_Unified_Cache_Manager::clear_all_caches();
        // 如果成功数组为空，返回 true；否则返回结果数组
        return (empty($result['failed'])) ? true : $result;
    }

    // 回退方案：使用旧的函数调用方式
    error_log("DEBUG: clear_all_cache called from " . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3), true));
    $cache_types = array('home', 'list', 'content', 'tag', 'data', 'php', 'tables', 'object', 'template');
    $success = true;

    foreach ($cache_types as $type) {
        $function_name = 'clear_' . $type . '_cache';
        if (function_exists($function_name)) {
            if (!call_user_func($function_name)) {
                $success = false;
            }
        }
    }

    return $success;
}

/**
 * 获取缓存统计信息
 */
function get_cache_statistics()
{
    $cache_types = array(
        'home' => '首页缓存',
        'list' => '列表页缓存',
        'content' => '内容页缓存',
        'tag' => '标签页缓存',
        'data' => '数据缓存',
        'php' => 'PHP编译缓存',
        'tables' => '数据表缓存'
    );

    $stats = array();

    foreach ($cache_types as $type => $name) {
        $cache_dir = get_cache_dir($type);
        $file_count = count_cache_files($cache_dir);
        $dir_size = get_cache_directory_size($cache_dir);
        $last_modified = get_cache_last_modified($cache_dir);

        $stats[$type] = array(
            'name' => $name,
            'count' => $file_count,
            'size' => $dir_size,
            'last_modified' => $last_modified
        );
    }

    // 对象缓存统计
    $stats['object'] = array(
        'name' => '对象缓存',
        'count' => count_cache_files(get_cache_dir() . 'objects/'),
        'size' => get_cache_directory_size(get_cache_dir() . 'objects/'),
        'last_modified' => get_cache_last_modified(get_cache_dir() . 'objects/')
    );

    // 模板缓存统计
    $stats['template'] = array(
        'name' => '模板缓存',
        'count' => count_cache_files(get_cache_dir() . 'templates/'),
        'size' => get_cache_directory_size(get_cache_dir() . 'templates/'),
        'last_modified' => get_cache_last_modified(get_cache_dir() . 'templates/')
    );

    return $stats;
}

/**
 * 统计缓存文件数量
 */
function count_cache_files($dir)
{
    if (!file_exists($dir) || !is_dir($dir)) {
        return 0;
    }

    $count = 0;
    $files = glob($dir . '*');

    foreach ($files as $file) {
        if (is_file($file)) {
            $filename = basename($file);
            // 跳过保护文件
            if (!in_array($filename, array('.htaccess', 'index.php', '.gitkeep'))) {
                $count++;
            }
        } elseif (is_dir($file)) {
            $count += count_cache_files($file . '/');
        }
    }

    return $count;
}

/**
 * 获取缓存目录大小
 */
function get_cache_directory_size($dir)
{
    if (!file_exists($dir) || !is_dir($dir)) {
        return 0;
    }

    $size = 0;
    $files = glob($dir . '*');

    foreach ($files as $file) {
        if (is_file($file)) {
            $filename = basename($file);
            // 跳过保护文件
            if (!in_array($filename, array('.htaccess', 'index.php', '.gitkeep'))) {
                $size += filesize($file);
            }
        } elseif (is_dir($file)) {
            $size += get_cache_directory_size($file . '/');
        }
    }

    return $size;
}

/**
 * 获取缓存最后修改时间
 */
function get_cache_last_modified($dir)
{
    if (!file_exists($dir) || !is_dir($dir)) {
        return 0;
    }

    $last_modified = 0;
    $files = glob($dir . '*');

    foreach ($files as $file) {
        if (is_file($file)) {
            $filename = basename($file);
            // 跳过保护文件
            if (!in_array($filename, array('.htaccess', 'index.php', '.gitkeep'))) {
                $mtime = filemtime($file);
                if ($mtime > $last_modified) {
                    $last_modified = $mtime;
                }
            }
        } elseif (is_dir($file)) {
            $dir_mtime = get_cache_last_modified($file . '/');
            if ($dir_mtime > $last_modified) {
                $last_modified = $dir_mtime;
            }
        }
    }

    return $last_modified;
}

/**
 * 生成缓存文件名
 */
function generate_cache_filename($type, $key, $extension = '.cache', $lang = null)
{
    if ($lang === null && function_exists('current_locale')) {
        $lang = current_locale();
    }
    if (function_exists('canonical_locale')) {
        $lang = canonical_locale($lang ?: 'zh-CN');
    } else {
        $lang = ($lang ?: 'zh-CN');
        $lang = str_replace('_', '-', $lang);
    }
    $hash = md5($key);
    return $type . '_' . $lang . '_' . $hash . $extension;
}

/**
 * 写入缓存文件
 */
function write_cache_file($type, $key, $data, $expire = 3600, $lang = null)
{
    ensure_cache_directories();

    // 针对页面级缓存，如果开启了静态模式，强制设置较长的过期时间
    $page_types = array('home', 'list', 'content', 'tag');
    if (in_array($type, $page_types, true)) {
        $page_mode = 'dynamic';
        if (function_exists('todo_get_option')) {
            $page_mode = todo_get_option('page_mode', 'dynamic');
        } elseif (function_exists('get_option')) {
            $page_mode = get_option('page_mode', 'dynamic');
        }

        if ($page_mode === 'static') {
            $expire = 315360000; // 10年，相当于永久有效
        }
    }

    $cache_dir = get_cache_dir($type);
    $filename = generate_cache_filename($type, $key, '.cache', $lang);
    $filepath = $cache_dir . $filename;

    $cache_data = array(
        'data' => $data,
        'expire' => time() + $expire,
        'created' => time()
    );

    return file_put_contents($filepath, serialize($cache_data)) !== false;
}

/**
 * 读取缓存文件
 */
function read_cache_file($type, $key, $lang = null)
{
    $cache_dir = get_cache_dir($type);
    $filename = generate_cache_filename($type, $key, '.cache', $lang);
    $filepath = $cache_dir . $filename;

    if (!file_exists($filepath)) {
        return false;
    }

    $cache_data = unserialize(file_get_contents($filepath));

    if (!$cache_data || !isset($cache_data['expire'])) {
        return false;
    }

    // 检查是否过期
    if (time() > $cache_data['expire']) {
        unlink($filepath);
        return false;
    }

    return $cache_data['data'];
}

/**
 * 删除特定缓存文件
 */
function delete_cache_file($type, $key, $lang = null)
{
    $cache_dir = get_cache_dir($type);
    $filename = generate_cache_filename($type, $key, '.cache', $lang);
    $filepath = $cache_dir . $filename;

    if (file_exists($filepath)) {
        return unlink($filepath);
    }

    return true;
}

/**
 * 检查缓存是否存在且有效
 */
function is_cache_valid($type, $key, $lang = null)
{
    $cache_dir = get_cache_dir($type);
    $filename = generate_cache_filename($type, $key, '.cache', $lang);
    $filepath = $cache_dir . $filename;

    if (!file_exists($filepath)) {
        return false;
    }

    // 读取缓存数据
    $cache_data = @unserialize(@file_get_contents($filepath));
    if (!$cache_data || !isset($cache_data['expire'])) {
        return false;
    }

    // 计算缓存文件的实际生成时间，用于与内容变化时间进行比较
    $cache_mtime = @filemtime($filepath);
    if ($cache_mtime === false) {
        $cache_mtime = 0;
    }

    // 针对页面级缓存（首页/列表/内容/标签），引入"内容与广告变化时间"的失效判断
    // 广告时间相关检查在静态和动态模式下都需要执行，因为广告有定时上线/下线需求
    // 文章内容更新仅在动态模式下检查
    $page_types = array('home', 'list', 'content', 'tag');
    if (in_array($type, $page_types, true)) {
        // 广告状态/分组更新，及有效期的日期/时间边界（start_date/end_date/start_time/end_time）触发缓存失效
        // 静态与动态模式均需此检查
        if (function_exists('todo_get_latest_ad_change_time')) {
            $latest_ad_time = todo_get_latest_ad_change_time();
            if ($latest_ad_time && $latest_ad_time > $cache_mtime) {
                return false;
            }
        }
    }

    $page_mode = 'dynamic';
    if (function_exists('todo_get_option')) {
        $page_mode = todo_get_option('page_mode', 'dynamic');
    } elseif (function_exists('get_option')) {
        $page_mode = get_option('page_mode', 'dynamic');
    }

    // 静态模式下，不检查文章内容更新（仅依赖TTL和上述广告时效检查）
    if ($page_mode === 'static') {
        return time() <= $cache_data['expire'];
    }

    // 动态模式：额外检查文章内容更新
    if (in_array($type, $page_types, true)) {
        if (function_exists('todo_get_latest_post_time')) {
            $latest_post_time = todo_get_latest_post_time();
            if ($latest_post_time && $latest_post_time > $cache_mtime) {
                return false;
            }
        }
    }

    // 最后检查缓存是否过期（TTL）
    return time() <= $cache_data['expire'];
}

/**
 * 清理文章相关的对象缓存
 *
 * @param int $post_id 文章ID
 * @return bool 清理是否成功
 */
if (!function_exists('todo_clean_post_cache')) {
    /**
     * 清理文章相关缓存（兼容性包装函数）
     * 实际功能已整合到 Todo_Unified_Cache_Manager::clean_post_cache()
     *
     * @param int $post_id 文章ID
     * @return bool 是否成功
     * @deprecated 请直接使用 Todo_Unified_Cache_Manager::clean_post_cache()
     */
    function todo_clean_post_cache($post_id)
    {
        if (!$post_id) {
            return false;
        }

        // 统一使用缓存管理器
        if (class_exists('Todo_Unified_Cache_Manager')) {
            return Todo_Unified_Cache_Manager::clean_post_cache($post_id);
        }

        // 如果统一缓存管理器不存在，记录警告并返回 false
        if (function_exists('todo_log_warning')) {
            todo_log_warning('Todo_Unified_Cache_Manager not available for cache cleaning', array('post_id' => $post_id));
        }
        return false;
    }
}

/**
 * 获取全站或指定类型文章的最近更新时间
 * 用于缓存验证等场景
 *
 * @param string|array $post_type 文章类型，默认为 'any'
 * @return int|false 最近更新的时间戳，无数据返回 false
 */
if (!function_exists('todo_get_latest_post_time')) {
    function todo_get_latest_post_time($post_type = 'any', $force_sync = false)
{
        static $cached_times = array();
    static $cache_expires = array();
    
    $cache_key = 'post_type_' . (is_array($post_type) ? implode('_', $post_type) : $post_type);

    if (!$force_sync && isset($cached_times[$cache_key]) && isset($cache_expires[$cache_key]) && time() < $cache_expires[$cache_key]) {
        return $cached_times[$cache_key];
    }
    
    $needs_db_check = true;
    
    // 跨请求持久化缓存 (30秒锁定机制)
    if (!$force_sync && class_exists('Todo_Cache_Manager')) {
        $lock = Todo_Cache_Manager::get('latest_post_time_lock_' . $cache_key, 'content');
        if ($lock !== false) {
            $needs_db_check = false;
        }
    }

    $is_spider = false;
    if (isset($_SERVER['HTTP_USER_AGENT'])) {
        $is_spider = preg_match('/Baiduspider|Googlebot|bingbot|Sogou|Yisou|360Spider/i', $_SERVER['HTTP_USER_AGENT']);
    }

    if (!$force_sync && !$is_spider && $needs_db_check) {
        // 如果是真人访客且锁已过期，则队列一次异步AJAX检测
        if (!isset($GLOBALS['todo_needs_async_cache_check'])) {
            $GLOBALS['todo_needs_async_cache_check'] = true;
        }
        
        // 返回上次已知的值，不阻塞页面渲染
        if (class_exists('Todo_Cache_Manager')) {
            $persistent = Todo_Cache_Manager::get('latest_post_time_persistent_' . $cache_key, 'content');
            if ($persistent !== false) {
                return $persistent;
            }
        }
    } elseif (!$force_sync && !$needs_db_check) {
        // 锁未过期，直接返回上次已知的值
        if (class_exists('Todo_Cache_Manager')) {
            $persistent = Todo_Cache_Manager::get('latest_post_time_persistent_' . $cache_key, 'content');
            if ($persistent !== false) {
                return $persistent;
            }
        }
    }

    global $tododb;

    if (!$tododb) {
        return false;
    }

    try {
        // 同时考虑发布时间与最后修改时间，确保定时发布（只更新状态与修改时间）也能使缓存失效
        $sql = "SELECT GREATEST(MAX(post_date), IFNULL(MAX(post_modified), '0000-00-00 00:00:00')) as latest_time FROM {$tododb->prefix}posts WHERE post_status = 'publish'";
        $params = array();
        
        if ($post_type !== 'any') {
            if (is_array($post_type)) {
                $placeholders = implode(',', array_fill(0, count($post_type), '?'));
                $sql .= " AND post_type IN ($placeholders)";
                $params = array_values($post_type);
            } else {
                $sql .= " AND post_type = ?";
                $params[] = $post_type;
            }
        }
        
        $latest = $tododb->get_var($sql, $params);
        
        if ($latest) {
            $latest_ts = strtotime($latest);
            if ($latest_ts > 0) {
                $cached_times[$cache_key] = $latest_ts;
                $cache_expires[$cache_key] = time() + 30; // 30秒本地缓存
                if (class_exists('Todo_Cache_Manager')) {
                    Todo_Cache_Manager::set('latest_post_time_lock_' . $cache_key, 1, 30, 'content');
                    Todo_Cache_Manager::set('latest_post_time_persistent_' . $cache_key, $latest_ts, 31536000, 'content'); // 1年持久化
                }
                return $latest_ts;
            }
        }
    } catch (Exception $e) {
        error_log('Error getting latest post time: ' . $e->getMessage());
    }

    $cached_times[$cache_key] = false;
    $cache_expires[$cache_key] = time() + 30;
    if (class_exists('Todo_Cache_Manager')) {
        Todo_Cache_Manager::set('latest_post_time_lock_' . $cache_key, 1, 30, 'content');
        Todo_Cache_Manager::set('latest_post_time_persistent_' . $cache_key, false, 31536000, 'content');
    }
    return false;
    }
}

/**
 * 获取最近一次广告相关的变化时间（含：新增/编辑/分组更新、以及已发生的有效期起止）
 * 用于静态缓存模式下，当页面包含广告标签时判断缓存是否需要失效
 *
 * 返回规则：
 * - ads.updated_at 与 ads.created_at 的最大值（覆盖新增/编辑/启停用）
 * - ad_groups.updated_at 的最大值（覆盖分组启停用与编辑）
 * - 所有 start_date <= NOW() 的最大值（如果在缓存生成后有广告刚开始生效）
 * - 所有 end_date   <= NOW() 的最大值（如果在缓存生成后有广告刚结束失效）
 *
 * 注意：此函数只返回一个时间戳，模板解析器会与缓存文件的 mtime 比较，
 * 若该时间戳晚于缓存生成时间则认为需要刷新。
 *
 * @return int|false 最近变化的 Unix 时间戳；失败返回 false
 */
function todo_get_latest_ad_change_time($force_sync = false)
{
    static $cached_time = null;
    static $cache_expire = 0;

    // 简单的本地静态缓存（同一次请求内）
    if (!$force_sync && $cached_time !== null && time() < $cache_expire) {
        return $cached_time;
    }

    $needs_db_check = true;

    // 跨请求持久化缓存锁定 (30秒)
    if (!$force_sync && class_exists('Todo_Cache_Manager')) {
        $lock = Todo_Cache_Manager::get('latest_ad_change_time_lock', 'content');
        if ($lock !== false) {
            $needs_db_check = false;
        }
    }

    $is_spider = false;
    if (isset($_SERVER['HTTP_USER_AGENT'])) {
        $is_spider = preg_match('/Baiduspider|Googlebot|bingbot|Sogou|Yisou|360Spider/i', $_SERVER['HTTP_USER_AGENT']);
    }

    if (!$force_sync && !$is_spider && $needs_db_check) {
        if (!isset($GLOBALS['todo_needs_async_cache_check'])) {
            $GLOBALS['todo_needs_async_cache_check'] = true;
        }
        if (class_exists('Todo_Cache_Manager')) {
            $persistent = Todo_Cache_Manager::get('latest_ad_change_time_persistent', 'content');
            if ($persistent !== false) {
                return $persistent;
            }
        }
    } elseif (!$force_sync && !$needs_db_check) {
        if (class_exists('Todo_Cache_Manager')) {
            $persistent = Todo_Cache_Manager::get('latest_ad_change_time_persistent', 'content');
            if ($persistent !== false) {
                return $persistent;
            }
        }
    }

    global $tododb;

    if (!$tododb) {
        return false;
    }

    // 兼容不同前缀常量/属性
    $ads_table = (defined('TABLE_PREFIX') ? TABLE_PREFIX : $tododb->prefix) . 'ads';
    $ad_groups_table = (defined('TABLE_PREFIX') ? TABLE_PREFIX : $tododb->prefix) . 'ad_groups';

    try {
        // 1) 广告的新增/编辑（含启停用）时间
        $latest_ad_updated = $tododb->get_var("SELECT MAX(updated_at) FROM {$ads_table}");
        $latest_ad_created = $tododb->get_var("SELECT MAX(created_at) FROM {$ads_table}");

        // 2) 广告分组的编辑/状态变更时间
        $latest_group_updated = $tododb->get_var("SELECT MAX(updated_at) FROM {$ad_groups_table}");

        // 3) 已发生的日期边界（按秒级比较，支持 DATETIME 的具体时刻）
        // 仅考虑启用中的广告，避免无效广告引发不必要失效
        // 使用 NOW() 而不是 CURDATE()，确保如 2025-10-17 13:30 这样的边界可以即时触发缓存失效
        $latest_start_date = $tododb->get_var("SELECT MAX(start_date) FROM {$ads_table} WHERE status = 1 AND start_date IS NOT NULL AND start_date <= NOW()");
        $latest_end_date = $tododb->get_var("SELECT MAX(end_date)   FROM {$ads_table} WHERE status = 1 AND end_date   IS NOT NULL AND end_date   <= NOW()");

        // 4) 已发生的当日时间边界（支持仅配置时间的每日启用/停用场景）
        // 仅在广告的日期范围包含今天（或未设置日期范围）时，计算当日边界
        $latest_start_time_today = $tododb->get_var(
            "SELECT MAX(TIMESTAMP(CURDATE(), start_time))
             FROM {$ads_table}
             WHERE status = 1
               AND start_time IS NOT NULL
               AND TIMESTAMP(CURDATE(), start_time) <= NOW()
               AND (start_date IS NULL OR start_date <= CURDATE())
               AND (end_date   IS NULL OR end_date   >= CURDATE())"
        );

        $latest_end_time_today = $tododb->get_var(
            "SELECT MAX(TIMESTAMP(CURDATE(), end_time))
             FROM {$ads_table}
             WHERE status = 1
               AND end_time IS NOT NULL
               AND TIMESTAMP(CURDATE(), end_time) <= NOW()
               AND (start_date IS NULL OR start_date <= CURDATE())
               AND (end_date   IS NULL OR end_date   >= CURDATE())"
        );

        // 额外：纳入删除广告后更新的全局时间戳（todo_ads_last_modified）
        // 该值由删除操作显式更新，用于触发缓存失效
        $ads_last_modified_option = 0;
        if (function_exists('get_option')) {
            $opt_val = get_option('todo_ads_last_modified', 0);
            if (!empty($opt_val)) {
                $ads_last_modified_option = is_numeric($opt_val) ? intval($opt_val) : strtotime($opt_val);
            }
        }

        // 转换为时间戳
        $ts = [];
        $ts[] = $latest_ad_updated ? strtotime($latest_ad_updated) : 0;
        $ts[] = $latest_ad_created ? strtotime($latest_ad_created) : 0;
        $ts[] = $latest_group_updated ? strtotime($latest_group_updated) : 0;
        $ts[] = $latest_start_date ? strtotime($latest_start_date) : 0;
        $ts[] = $latest_end_date ? strtotime($latest_end_date) : 0;
        $ts[] = $latest_start_time_today ? strtotime($latest_start_time_today) : 0;
        $ts[] = $latest_end_time_today ? strtotime($latest_end_time_today) : 0;
        $ts[] = $ads_last_modified_option ? intval($ads_last_modified_option) : 0;

        $latest_ts = max($ts);
        if ($latest_ts > 0) {
            $cached_time = $latest_ts;
            $cache_expire = time() + 30; // 30秒本地缓存，兼顾性能与即时性
            if (class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set('latest_ad_change_time_lock', 1, 30, 'content');
                Todo_Cache_Manager::set('latest_ad_change_time_persistent', $cached_time, 31536000, 'content');
            }
            return $cached_time;
        }
    } catch (Exception $e) {
        error_log('Error getting latest ad change time: ' . $e->getMessage());
    }

    $cached_time = false;
    $cache_expire = time() + 30; // 失败也短暂缓存，减轻负载
    if (class_exists('Todo_Cache_Manager')) {
        Todo_Cache_Manager::set('latest_ad_change_time_lock', 1, 30, 'content');
        Todo_Cache_Manager::set('latest_ad_change_time_persistent', false, 31536000, 'content');
    }
    return false;
}

// 清理翻译缓存（数据库 language_cache + 文件型 CMS_Translation 内存缓存）
//
// - 数据库：调用 TodoLanguageManager::clear_translation_cache() 清空 language_cache 表（或按需）
// - 文件型/资源：调用 CMS_Translation::clear_cache() 重置内存缓存，确保下次请求重新加载 en_US.php 等语言文件
//
// 返回：任意一个子操作成功即返回 true
/**
 * 清理翻译缓存（数据库 language_cache + 文件型 CMS_Translation 内存缓存）
 *
 * - 数据库：调用 TodoLanguageManager::clear_translation_cache() 清空 language_cache 表（或按需）
 * - 文件型/资源：调用 CMS_Translation::clear_cache() 重置内存缓存，确保下次请求重新加载 en_US.php 等语言文件
 *
 * 返回：任意一个子操作成功即返回 true
 */
function clear_translation_caches()
{
    $dbCleared = false;
    $memCleared = false;

    // 尝试清理数据库驱动的语言缓存
    try {
        if (function_exists('todo_language_manager')) {
            $mgr = todo_language_manager();
            if (is_object($mgr) && method_exists($mgr, 'clear_translation_cache')) {
                // 不传参数：清空整个 language_cache 表
                $mgr->clear_translation_cache();
                $dbCleared = true;
            }
        }
    } catch (Throwable $e) {
        if (function_exists('todo_log_error')) {
            todo_log_error('清理语言数据库缓存失败: ' . $e->getMessage());
        }
    }

    // 尝试清理文件型翻译（资源）内存缓存
    try {
        if (class_exists('CMS_Translation') && method_exists('CMS_Translation', 'clear_cache')) {
            CMS_Translation::clear_cache();
            $memCleared = true;
        }
    } catch (Throwable $e) {
        if (function_exists('todo_log_error')) {
            todo_log_error('清理文件型翻译缓存失败: ' . $e->getMessage());
        }
    }

    return ($dbCleared || $memCleared);
}

/**
 * 清理指定路径的模板缓存
 *
 * @param string $template_path 模板文件路径
 * @param string $uri 页面URI
 * @param array $data 传递给模板的数据（用于生成缓存键）
 * @return bool 是否清理成功
 */
if (!function_exists('clear_template_cache_for_path')) {
    function clear_template_cache_for_path($template_path, $uri, $data = array())
    {
        if (empty($template_path)) {
            return false;
        }

        // CMS_Template_Compiler_V2 模式下，主要清理编译缓存
        // 编译缓存文件名仅依赖于模板路径
        if (class_exists('CMS_Template_Compiler_V2')) {
             $compile_dir = ABSPATH . 'content/cache/compiled/';
             $compiled_file = $compile_dir . md5($template_path) . '.php';
             if (file_exists($compiled_file)) {
                 return unlink($compiled_file);
             }
             return true; // 文件不存在也算清理成功
        }

        return false;
    }
}
