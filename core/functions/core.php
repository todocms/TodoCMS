<?php
/**
 * 核心函数
 *
 * 提供系统核心功能的函数
 *
 * @package TodoCMS
 * @version 1.0.0
 */
// 防止重复加载
if (defined('TODO_CORE_FUNCTIONS_LOADED')) {
    return;
}
define('TODO_CORE_FUNCTIONS_LOADED', true);

if (!defined('TODO_DEMO_ADMIN_ENABLED')) {
    define('TODO_DEMO_ADMIN_ENABLED', false);
}
if (!defined('TODO_DEMO_ADMIN_USERNAME')) {
    define('TODO_DEMO_ADMIN_USERNAME', 'demo');
}
if (!defined('COOKIEHASH')) {
    define('COOKIEHASH', '');
}

// 选项函数定义（始终可用，使用Composer类加载）
if (!function_exists('get_option')) {
    function get_option($option, $default = false)
    {
        return CMS_Option::get($option, $default);
    }
}
if (!function_exists('update_option')) {
    function update_option($option, $value, $group_name = 'general')
    {
        return CMS_Option::update($option, $value, $group_name);
    }
}

if (!function_exists('todo_get_site_timezone_string')) {
    function todo_get_site_timezone_string($default = 'Asia/Shanghai')
    {
        static $cached_tz = null;
        if ($cached_tz !== null) {
            return $cached_tz;
        }

        $tz = '';
        if (function_exists('get_option')) {
            try {
                $tz = (string) get_option('timezone_string', '');
            } catch (Throwable $e) {
                $tz = '';
            }
            if ($tz === '') {
                try {
                    $tz = (string) get_option('timezone', '');
                } catch (Throwable $e) {
                    $tz = '';
                }
            }
        }

        if ($tz === '') {
            $tz = $default;
        }

        try {
            new DateTimeZone($tz);
        } catch (Throwable $e) {
            $tz = $default;
        }

        $cached_tz = $tz;
        return $cached_tz;
    }
}

if (!function_exists('todo_init_timezone')) {
    function todo_init_timezone($force = false)
    {
        static $done = false;
        if ($done && !$force) {
            return @date_default_timezone_get();
        }

        $tz = todo_get_site_timezone_string();
        $current = @date_default_timezone_get();
        if ($current !== $tz) {
            @date_default_timezone_set($tz);
        }

        $done = true;
        return $tz;
    }
}

if (!function_exists('todo_format_utc')) {
    function todo_format_utc($format = 'Y-m-d H:i:s', $timestamp = null)
    {
        try {
            if ($timestamp === null) {
                $dt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            } else {
                $dt = (new DateTimeImmutable('@' . (int) $timestamp))->setTimezone(new DateTimeZone('UTC'));
            }
            return $dt->format($format);
        } catch (Throwable $e) {
            if ($timestamp === null) {
                $timestamp = time();
            }
            return (new DateTimeImmutable('@' . (int) $timestamp))->setTimezone(new DateTimeZone('UTC'))->format($format);
        }
    }
}

if (!function_exists('todo_sites_table_exists')) {
    function todo_sites_table_exists()
    {
        global $tododb;
        if (!$tododb) {
            return false;
        }
        
        $table = $tododb->prefix . 'sites';
        $cache_key = 'table_exists_' . md5($table);
        if (class_exists('Todo_Cache_Manager')) {
            $cached = Todo_Cache_Manager::get($cache_key, 'database', 'global');
            if ($cached !== false) {
                return $cached;
            }
        }
        
        try {
            if (method_exists($tododb, 'table_exists')) {
                $exists = (bool) $tododb->table_exists('sites');
                if (class_exists('Todo_Cache_Manager')) {
                    Todo_Cache_Manager::set($cache_key, $exists, 86400, 'database', 'global');
                }
                return $exists;
            }
            $exists = $tododb->get_var(
                "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1",
                [$table]
            );
            $is_exists = !empty($exists);
            if (class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set($cache_key, $is_exists, 86400, 'database', 'global');
            }
            return $is_exists;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('todo_site_posts_table_exists')) {
    function todo_site_posts_table_exists()
    {
        global $tododb;
        if (!$tododb) return false;
        
        $table = $tododb->prefix . 'site_posts';
        $cache_key = 'table_exists_' . md5($table);
        if (class_exists('Todo_Cache_Manager')) {
            $cached = Todo_Cache_Manager::get($cache_key, 'database', 'global');
            if ($cached !== false) {
                return $cached;
            }
        }
        
        try {
            if (method_exists($tododb, 'table_exists')) {
                $exists = (bool) $tododb->table_exists('site_posts');
                if (class_exists('Todo_Cache_Manager')) {
                    Todo_Cache_Manager::set($cache_key, $exists, 86400, 'database', 'global');
                }
                return $exists;
            }
            $exists = $tododb->get_var(
                "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1",
                [$table]
            );
            $is_exists = !empty($exists);
            if (class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set($cache_key, $is_exists, 86400, 'database', 'global');
            }
            return $is_exists;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('todo_site_posts_has_bindings')) {
    function todo_site_posts_has_bindings()
    {
        static $has_bindings = null;
        if ($has_bindings !== null) {
            return $has_bindings;
        }
        global $tododb;
        if (!$tododb || !todo_site_posts_table_exists()) {
            $has_bindings = false;
            return false;
        }
        try {
            $row = $tododb->get_var("SELECT 1 FROM " . $tododb->prefix . "site_posts LIMIT 1");
            $has_bindings = !empty($row);
            return $has_bindings;
        } catch (Throwable $e) {
            $has_bindings = false;
            return false;
        }
    }
}

if (!function_exists('todo_site_terms_table_exists')) {
    function todo_site_terms_table_exists()
    {
        global $tododb;
        if (!$tododb) return false;
        
        $table = $tododb->prefix . 'site_terms';
        $cache_key = 'table_exists_' . md5($table);
        if (class_exists('Todo_Cache_Manager')) {
            $cached = Todo_Cache_Manager::get($cache_key, 'database', 'global');
            if ($cached !== false) {
                return $cached;
            }
        }
        
        try {
            if (method_exists($tododb, 'table_exists')) {
                $exists = (bool) $tododb->table_exists('site_terms');
                if (class_exists('Todo_Cache_Manager')) {
                    Todo_Cache_Manager::set($cache_key, $exists, 86400, 'database', 'global');
                }
                return $exists;
            }
            $exists = $tododb->get_var(
                "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1",
                [$table]
            );
            $is_exists = !empty($exists);
            if (class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set($cache_key, $is_exists, 86400, 'database', 'global');
            }
            return $is_exists;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('todo_get_default_site_id')) {
    function todo_get_default_site_id()
    {
        global $tododb;
        if (!todo_sites_table_exists()) {
            return 1;
        }
        $table = $tododb->prefix . 'sites';
        try {
            $id = (int) $tododb->get_var("SELECT id FROM {$table} WHERE is_default = 1 AND status = 'active' ORDER BY id ASC LIMIT 1");
            if ($id > 0) {
                return $id;
            }
            $id = (int) $tododb->get_var("SELECT id FROM {$table} WHERE status = 'active' ORDER BY id ASC LIMIT 1");
            return $id > 0 ? $id : 1;
        } catch (Throwable $e) {
            return 1;
        }
    }
}

if (!function_exists('todo_is_valid_site_id')) {
    function todo_is_valid_site_id($site_id, $allow_inactive = false)
    {
        global $tododb;
        $site_id = (int) $site_id;
        if ($site_id <= 0 || !todo_sites_table_exists()) {
            return false;
        }
        $table = $tododb->prefix . 'sites';
        try {
            if ($allow_inactive) {
                $count = (int) $tododb->get_var("SELECT COUNT(*) FROM {$table} WHERE id = ? LIMIT 1", [$site_id]);
            } else {
                $count = (int) $tododb->get_var("SELECT COUNT(*) FROM {$table} WHERE id = ? AND status = 'active' LIMIT 1", [$site_id]);
            }
            return $count > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('todo_normalize_http_host')) {
    function todo_normalize_http_host($host)
    {
        $host = is_string($host) ? trim(strtolower($host)) : '';
        if ($host === '') {
            return '';
        }
        $host = preg_replace('/:\d+$/', '', $host);
        $host = trim($host, '.');
        return $host;
    }
}

if (!function_exists('todo_parse_site_domains')) {
    function todo_parse_site_domains($config)
    {
        $domains = [];
        if (is_array($config)) {
            if (isset($config['domains'])) {
                $config = $config['domains'];
            } elseif (isset($config['domain'])) {
                $config = $config['domain'];
            } elseif (isset($config['hosts'])) {
                $config = $config['hosts'];
            }
        }
        if (is_string($config)) {
            $parts = preg_split('/[\r\n,]+/', $config);
            $config = $parts ?: [];
        }
        if (is_array($config)) {
            foreach ($config as $d) {
                $d = todo_normalize_http_host(is_string($d) ? $d : '');
                if ($d === '') continue;
                $domains[] = $d;
            }
        }
        return array_values(array_unique($domains));
    }
}

if (!function_exists('todo_resolve_site_id_by_host')) {
    function todo_resolve_site_id_by_host($host)
    {
        static $cache = [];
        $host = todo_normalize_http_host($host);
        if ($host === '') {
            return 0;
        }
        if (array_key_exists($host, $cache)) {
            return (int) $cache[$host];
        }

        global $tododb;
        if (!$tododb || !todo_sites_table_exists()) {
            $cache[$host] = 0;
            return 0;
        }

        $table = $tododb->prefix . 'sites';
        $rows = [];
        try {
            $rows = $tododb->get_results("SELECT id, slug, config FROM {$table} WHERE status = 'active' ORDER BY is_default DESC, id ASC");
        } catch (Throwable $e) {
            $cache[$host] = 0;
            return 0;
        }
        if (!is_array($rows) || empty($rows)) {
            $cache[$host] = 0;
            return 0;
        }

        $exact_id = 0;
        $wildcard_id = 0;
        $wildcard_len = -1;

        foreach ($rows as $r) {
            if (!is_object($r) || !isset($r->id)) continue;
            $site_id = (int) $r->id;
            if ($site_id <= 0) continue;

            $slug = isset($r->slug) ? todo_normalize_http_host((string) $r->slug) : '';
            if ($slug !== '') {
                if ($host === $slug || strpos($host, $slug . '.') === 0) {
                    $exact_id = $site_id;
                    break;
                }
            }

            $config = [];
            if (isset($r->config) && is_string($r->config) && $r->config !== '') {
                $decoded = json_decode($r->config, true);
                if (is_array($decoded)) {
                    $config = $decoded;
                }
            }
            $domains = todo_parse_site_domains($config);
            foreach ($domains as $d) {
                if ($d === '') continue;
                if ($d[0] === '*' && isset($d[1]) && $d[1] === '.') {
                    $suffix = substr($d, 1);
                    if ($suffix !== '' && substr($host, -strlen($suffix)) === $suffix) {
                        $len = strlen($suffix);
                        if ($len > $wildcard_len) {
                            $wildcard_len = $len;
                            $wildcard_id = $site_id;
                        }
                    }
                    continue;
                }
                if ($host === $d) {
                    $exact_id = $site_id;
                    break 2;
                }
            }
        }

        $resolved = $exact_id > 0 ? $exact_id : ($wildcard_id > 0 ? $wildcard_id : 0);
        $cache[$host] = $resolved;
        return (int) $resolved;
    }
}

if (!function_exists('todo_set_current_site_id')) {
    function todo_set_current_site_id($site_id, $persist = true)
    {
        $site_id = (int) $site_id;
        if ($site_id <= 0 || !todo_is_valid_site_id($site_id)) {
            $site_id = todo_get_default_site_id();
        }
        $GLOBALS['todo_current_site_id'] = $site_id;
        if ($persist && !headers_sent()) {
            $expire = time() + 15552000;
            @setcookie('todo_site_id', (string) $site_id, $expire, '/');
            // 清理旧版按语言隔离的 cookie（如果存在）
            if (!empty($_COOKIE)) {
                foreach ($_COOKIE as $k => $v) {
                    if (strpos($k, 'todo_site_id_') === 0 && $k !== 'todo_site_id') {
                        @setcookie($k, '', time() - 3600, '/');
                    }
                }
            }
        }
        return $site_id;
    }
}

if (!function_exists('todo_current_site_id')) {
    function todo_current_site_id($fallback = true)
    {
        if (isset($GLOBALS['todo_current_site_id']) && (int) $GLOBALS['todo_current_site_id'] > 0) {
            return (int) $GLOBALS['todo_current_site_id'];
        }

        $site_id = 0;
        $persist = false;

        $site_context_id = function_exists('todo_get_site_context_id') ? (int) todo_get_site_context_id(0) : 0;
        if ($site_context_id > 0) {
            $candidate = $site_context_id;
            if ($candidate > 0) {
                $site_id = $candidate;
                $persist = true;
            }
        }

        if ($site_id <= 0 && !empty($_POST['site_id']) && is_numeric($_POST['site_id'])) {
            $candidate = (int) $_POST['site_id'];
            if ($candidate > 0) {
                $site_id = $candidate;
                $persist = true;
            }
        }

        if ($site_id <= 0 && (!defined('IS_ADMIN') || !IS_ADMIN)) {
            $host = function_exists('todo_server_string') ? todo_server_string('HTTP_HOST', todo_server_string('SERVER_NAME', '')) : (isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : (isset($_SERVER['SERVER_NAME']) ? (string) $_SERVER['SERVER_NAME'] : ''));
            $by_host = todo_resolve_site_id_by_host($host);
            if ($by_host > 0) {
                $site_id = $by_host;
                $persist = true;
            }
        }

        // 当前语言的站点绑定情况
        $lang_site = 0;
        if (!(defined('IS_ADMIN') && IS_ADMIN) && function_exists('todo_get_language_default_site_id')) {
            $lang_site = (int) todo_get_language_default_site_id();
        }

        if ($lang_site > 0) {
            // 语言有站点绑定：Cookie 优先（用户可手动切换），无 cookie 时用语言默认
            if ($site_id <= 0 && !empty($_COOKIE['todo_site_id']) && is_numeric($_COOKIE['todo_site_id'])) {
                $candidate = (int) $_COOKIE['todo_site_id'];
                if ($candidate > 0 && todo_is_valid_site_id($candidate)) {
                    $site_id = $candidate;
                }
            }
            if ($site_id <= 0) {
                $site_id = $lang_site;
                $persist = true;
            }
        } else {
            // 语言无站点绑定 → 全局可见，忽略 cookie
            // 仅 GET/POST/host 显式指定时采用
        }

        if ($site_id > 0) {
            $site_id = todo_set_current_site_id($site_id, $persist);
        } elseif ($fallback) {
            $lang_site = 0;
            if (!(defined('IS_ADMIN') && IS_ADMIN) && function_exists('todo_get_language_default_site_id')) {
                $lang_site = (int) todo_get_language_default_site_id();
            }
            if ($lang_site > 0) {
                $site_id = todo_set_current_site_id($lang_site, true);
            } elseif (!(defined('IS_ADMIN') && IS_ADMIN)) {
                // 前台语言无站点绑定 → $site_id=0，全局可见
                $site_id = 0;
            } else {
                $site_id = todo_set_current_site_id(todo_get_default_site_id(), false);
            }
        } else {
            $site_id = 0;
        }

        // --- 安全增强：后台权限校验（对 GET/POST/Cookie/默认值 一视同仁） ---
        if ($site_id > 0 && defined('IS_ADMIN') && IS_ADMIN) {
            $is_super = function_exists('todo_is_super_admin') ? (bool) todo_is_super_admin() : false;
            if (!$is_super && function_exists('todo_user_can_access_site') && !todo_user_can_access_site($site_id)) {
                $allowed_ids = function_exists('todo_get_user_site_ids') ? todo_get_user_site_ids() : [];
                if (!empty($allowed_ids)) {
                    $site_id = (int) reset($allowed_ids);
                } else {
                    $site_id = todo_get_default_site_id();
                }
                todo_set_current_site_id($site_id, true);
            }
        }

        return (int) $site_id;
    }
}


if (!function_exists('todo_get_site')) {
    function todo_get_site($site_id)
    {
        global $tododb;
        $site_id = (int) $site_id;
        if ($site_id <= 0 || !todo_sites_table_exists()) {
            return null;
        }
        $table = $tododb->prefix . 'sites';
        try {
            return $tododb->get_row("SELECT * FROM {$table} WHERE id = ? LIMIT 1", [$site_id]);
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('todo_get_sites')) {
    function todo_get_sites($args = [])
    {
        global $tododb;
        if (!todo_sites_table_exists()) {
            return [];
        }
        $table = $tododb->prefix . 'sites';
        $where = " WHERE 1=1";
        if (isset($args['status'])) {
            $where .= $tododb->prepare(" AND status = %s", $args['status']);
        }
        $order = " ORDER BY is_default DESC, id ASC";
        try {
            return $tododb->get_results("SELECT * FROM {$table} {$where} {$order}");
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('todo_get_user_site_ids')) {
    function todo_get_user_site_ids($user_id = null)
    {
        $user_id = $user_id === null && function_exists('get_current_user_id') ? (int) get_current_user_id() : (int) $user_id;
        if ($user_id <= 0 || !todo_sites_table_exists()) {
            return [todo_get_default_site_id()];
        }
        // 超级管理员或拥有管理权限的管理员可以看到所有站点
        $is_all_sites = todo_is_super_admin($user_id);
        if (!$is_all_sites && $user_id > 0) {
            global $tododb;
            $prefix = $tododb->prefix;
            $role_meta = $tododb->get_var("SELECT meta_value FROM {$prefix}user_meta WHERE user_id = ? AND meta_key = 'roles' LIMIT 1", [$user_id]);
            if ($role_meta) {
                $roles = function_exists('maybe_unserialize') ? maybe_unserialize($role_meta) : $role_meta;
                if (is_array($roles) && (in_array('administrator', $roles) || in_array('admin', $roles))) {
                    $is_all_sites = true;
                }
            }
        }

        if ($is_all_sites) {
            global $tododb;
            $sites_table = $tododb->prefix . 'sites';
            $rows = $tododb->get_results("SELECT id FROM {$sites_table} WHERE status = 'active' ORDER BY is_default DESC, id ASC");
            $ids = [];
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    if (is_object($r) && isset($r->id)) {
                        $ids[] = (int) $r->id;
                    }
                }
            }
            $ids = array_values(array_unique(array_filter($ids, function ($v) {
                return (int) $v > 0;
            })));
            return !empty($ids) ? $ids : [todo_get_default_site_id()];
        }
        global $tododb;
        $roles_table = $tododb->prefix . 'user_site_roles';
        $sites_table = $tododb->prefix . 'sites';
        $sql = "SELECT r.site_id FROM {$roles_table} r INNER JOIN {$sites_table} s ON s.id = r.site_id WHERE r.user_id = ? AND r.status = 'active' AND s.status = 'active' ORDER BY s.is_default DESC, r.site_id ASC";
        $rows = $tododb->get_results($sql, [$user_id]);
        $ids = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (is_object($r) && isset($r->site_id)) {
                    $ids[] = (int) $r->site_id;
                }
            }
        }
        $ids = array_values(array_unique(array_filter($ids, function ($v) {
            return (int) $v > 0;
        })));
        return !empty($ids) ? $ids : [todo_get_default_site_id()];
    }
}

/**
 * 确保文章与站点有关联（隔离架构关键：同步 site_posts 映射表）
 * 
 * @param int $post_id 文章ID
 * @param array $args 可选参数 (site_id, status, is_visible, site_order)
 * @return bool 是否成功
 */
if (!function_exists('todo_ensure_post_site_mapping')) {
    function todo_ensure_post_site_mapping($post_id, $args = []) {
        global $tododb;
        $post_id = (int)$post_id;
        if ($post_id <= 0) return false;
        
        // 优先使用参数中的 site_id，否则使用当前站点 ID
        if (isset($args['site_id'])) {
            $site_id = (int)$args['site_id'];
        } else {
            $site_id = todo_current_site_id();
        }
        
        // 允许 site_id 为 0 (全局)
        if ($site_id < 0) return false;
        
        // 获取文章状态（如果未指定）
        $status = isset($args['status']) ? $args['status'] : '';
        if (empty($status)) {
            $post = $tododb->get_row("SELECT post_status FROM {$tododb->prefix}posts WHERE ID = ? LIMIT 1", [$post_id]);
            $status = $post ? $post->post_status : 'publish';
        }
        
        $is_visible = isset($args['is_visible']) ? (int)$args['is_visible'] : 1;
        $site_order = isset($args['site_order']) ? (int)$args['site_order'] : 0;
        
        $table = $tododb->prefix . 'site_posts';
        if (!todo_site_posts_table_exists()) return false;
        
        try {
            // 检查映射是否已存在
            $exists = $tododb->get_var("SELECT 1 FROM {$table} WHERE site_id = ? AND post_id = ? LIMIT 1", [$site_id, $post_id]);
            
            if ($exists) {
                return $tododb->update($table, [
                    'status' => $status,
                    'is_visible' => $is_visible,
                    'site_order' => $site_order
                ], ['site_id' => $site_id, 'post_id' => $post_id]);
            } else {
                return $tododb->insert($table, [
                    'site_id' => $site_id,
                    'post_id' => $post_id,
                    'status' => $status,
                    'is_visible' => $is_visible,
                    'site_order' => $site_order
                ]);
            }
        } catch (Throwable $e) {
            return false;
        }
    }
}

/**
 * 确保分类/标签与站点有关联（同步 site_terms 映射表）
 * 
 * @param int $term_id 术语ID
 * @param array $args 可选参数 (site_id, is_visible, site_order)
 * @return bool 是否成功
 */
if (!function_exists('todo_ensure_term_site_mapping')) {
    function todo_ensure_term_site_mapping($term_id, $args = []) {
        global $tododb;
        $term_id = (int)$term_id;
        if ($term_id <= 0) return false;
        
        $site_id = isset($args['site_id']) ? (int)$args['site_id'] : todo_current_site_id();
        if ($site_id < 0) return false;
        
        $is_visible = isset($args['is_visible']) ? (int)$args['is_visible'] : 1;
        $site_order = isset($args['site_order']) ? (int)$args['site_order'] : 0;
        
        $table = $tododb->prefix . 'site_terms';
        if (!todo_site_terms_table_exists()) return false;
        
        try {
            $exists = $tododb->get_var("SELECT 1 FROM {$table} WHERE site_id = ? AND term_id = ? LIMIT 1", [$site_id, $term_id]);
            if ($exists) {
                return $tododb->update($table, [
                    'is_visible' => $is_visible,
                    'site_order' => $site_order
                ], ['site_id' => $site_id, 'term_id' => $term_id]);
            } else {
                return $tododb->insert($table, [
                    'site_id' => $site_id,
                    'term_id' => $term_id,
                    'is_visible' => $is_visible,
                    'site_order' => $site_order
                ]);
            }
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('todo_get_site_term_visibility')) {
    function todo_get_site_term_visibility($current_site_id, $sys_def_site) {
        static $cache = [];
        $key = $current_site_id . '_' . $sys_def_site;
        if (isset($cache[$key])) return $cache[$key];
        
        global $tododb;
        if (!function_exists('todo_site_terms_table_exists') || !todo_site_terms_table_exists()) {
            return ['any' => [], 'visible' => []];
        }
        $st_table = $tododb->prefix . 'site_terms';
        
        try {
            // Get all term mappings
            $mappings = $tododb->get_results("SELECT term_id, site_id, is_visible FROM {$st_table} WHERE site_id <> 0");
            if (empty($mappings)) {
                $cache[$key] = ['any' => [], 'visible' => []];
                return $cache[$key];
            }
            
            // Get all taxonomy parent-child relationships
            $taxonomies = $tododb->get_results("SELECT term_id, parent FROM {$tododb->prefix}term_taxonomy WHERE taxonomy = 'category'");
            $children_map = [];
            if ($taxonomies) {
                foreach ($taxonomies as $t) {
                    $parent = (int)$t->parent;
                    $term_id = (int)$t->term_id;
                    if ($parent > 0) {
                        if (!isset($children_map[$parent])) $children_map[$parent] = [];
                        $children_map[$parent][] = $term_id;
                    }
                }
            }
            
            // Helper to get all descendants recursively
            $get_descendants = function($term_id) use (&$children_map, &$get_descendants) {
                $desc = [];
                if (isset($children_map[$term_id])) {
                    foreach ($children_map[$term_id] as $child) {
                        $desc[] = $child;
                        $desc = array_merge($desc, $get_descendants($child));
                    }
                }
                return $desc;
            };
            
            $any = [];
            $visible = [];
            
            foreach ($mappings as $m) {
                $tid = (int)$m->term_id;
                $sid = (int)$m->site_id;
                $is_vis = (int)$m->is_visible;
                
                $affected_terms = array_merge([$tid], $get_descendants($tid));
                
                foreach ($affected_terms as $at) {
                    $any[$at] = true;
                    if ($sid === (int) $current_site_id && $is_vis == 1) {
                        $visible[$at] = true;
                    }
                }
            }
            
            $cache[$key] = [
                'any' => array_keys($any),
                'visible' => array_keys($visible)
            ];
            return $cache[$key];
        } catch (Throwable $e) {
            return ['any' => [], 'visible' => []];
        }
    }
}

/**
 * 生成文章站点可见性 SQL WHERE 条件
 * 
 * 优先级：post 自身的 site_posts 记录 > 分类的 site_terms 记录 > 回退全局可见
 * 
 * @param string $sp_alias     site_posts 表 LEFT JOIN 的别名，如 'sp' / 'sp_archive'
 * @param int    $current_site_id 当前站点 ID
 * @param int    $sys_def_site     系统默认站点 ID
 * @param string $post_col         文章 ID 列引用，如 'p.ID'
 * @return string 完整 WHERE 条件（不含 AND 前缀），可直接拼接到 SQL
 */
if (!function_exists('todo_get_post_site_visibility_sql')) {
    function todo_get_post_site_visibility_sql($sp_alias, $current_site_id, $sys_def_site, $post_col = 'p.ID') {
        global $tododb;
        $has_sp = function_exists('todo_site_posts_has_bindings') ? todo_site_posts_has_bindings() : (function_exists('todo_site_posts_table_exists') && todo_site_posts_table_exists());
        $has_st = function_exists('todo_site_terms_table_exists') && todo_site_terms_table_exists();
        
        $current_site_id = (int)$current_site_id;
        $sys_def_site = (int)$sys_def_site;
        $term_vis = $has_st ? todo_get_site_term_visibility($current_site_id, $sys_def_site) : ['any' => [], 'visible' => []];

        if (!$has_sp && empty($term_vis['any'])) {
            return '1=1';
        }

        $parts = [];
        $sp_table = $tododb->prefix . 'site_posts';

        if ($has_sp) {
            $parts[] = "({$sp_alias}.post_id IS NOT NULL AND {$sp_alias}.is_visible = 1)";
        }

        $no_direct = $has_sp
            ? "NOT EXISTS (SELECT 1 FROM {$sp_table} spx WHERE spx.post_id = {$post_col})"
            : "1=1";

        if ($has_st && !empty($term_vis['any'])) {
            $alias = 'p';
            if (strpos($post_col, '.') !== false) {
                $alias = explode('.', $post_col)[0];
            }
            
            $visible_ids = empty($term_vis['visible']) ? '0' : implode(',', $term_vis['visible']);
            $any_ids = implode(',', $term_vis['any']);
            
            $cat_visible = "(EXISTS (
                SELECT 1 FROM {$tododb->prefix}term_relationships tr_cv
                JOIN {$tododb->prefix}term_taxonomy tt_cv ON tt_cv.term_taxonomy_id = tr_cv.term_taxonomy_id AND tt_cv.taxonomy = 'category'
                WHERE tr_cv.object_id = {$post_col} AND tt_cv.term_id IN ({$visible_ids})
            ) OR ({$alias}.category_id IN ({$visible_ids})))";
            
            $no_cat_site = "(NOT EXISTS (
                SELECT 1 FROM {$tododb->prefix}term_relationships tr_ns
                JOIN {$tododb->prefix}term_taxonomy tt_ns ON tt_ns.term_taxonomy_id = tr_ns.term_taxonomy_id AND tt_ns.taxonomy = 'category'
                WHERE tr_ns.object_id = {$post_col} AND tt_ns.term_id IN ({$any_ids})
            ) AND ({$alias}.category_id IS NULL OR {$alias}.category_id NOT IN ({$any_ids})))";
            
            $parts[] = "({$no_direct} AND ({$cat_visible} OR {$no_cat_site}))";
        } else {
            $parts[] = "({$no_direct})";
        }

        return '(' . implode(' OR ', $parts) . ')';
    }
}

if (!defined('TODO_UNIFIED_CACHE_INIT_DONE') && defined('ABSPATH') && class_exists('Todo_Unified_Cache_Manager')) {
    define('TODO_UNIFIED_CACHE_INIT_DONE', true);
    Todo_Unified_Cache_Manager::init();
}

if (!function_exists('get_template_parser')) {
    function get_template_parser()
    {
        static $parser = null;
        if ($parser !== null) {
            return $parser;
        }

        if (!class_exists('CMS_Template_Compiler_V2')) {
            $parser = null;
            return $parser;
        }

        $parser = new class {
            public function parse_template($template_path, $data = [])
            {
                return CMS_Template_Compiler_V2::render_template($template_path, $data);
            }

            public function parse_content($content, $data = [])
            {
                return CMS_Template_Compiler_V2::render_string($content, $data);
            }

            public function register_tag($tag_name, $callback)
            {
                return CMS_Template_Compiler_V2::register_tag($tag_name, $callback);
            }

            public function clear_cache($template_name = null)
            {
                return CMS_Template_Compiler_V2::clear_cache($template_name);
            }
        };

        return $parser;
    }
}

/**
 * 初始化CMS主流程
 * 
 * 此函数由 bootstrap.php 在所有核心文件加载完成后调用
 */
if (!function_exists('cms_init')) {
    function cms_init()
    {
        static $initialized = false;
        if ($initialized) {
            return;
        }
        $initialized = true;
        
        // 执行初始化动作
        do_action('cms_init');
    }
}

/**
 * 处理邮件退订 / 取消订阅请求
 */
if (!function_exists('todo_handle_unsubscribe_request')) {
    function todo_handle_unsubscribe_request()
    {
        $path = (string) parse_url(todo_get_request_uri(), PHP_URL_PATH);
        $path = str_replace('\\', '/', $path);
        
        $is_unsub_route = false;
        $token_raw = '';
        $token_candidate = todo_get_query_context_value('token', todo_get_string('token'));
        $email_candidate = todo_get_query_context_value('email', todo_get_string('email'));

        if (todo_get_query_context_value('todo_action', todo_get_string('todo_action')) === 'unsubscribe') {
            $is_unsub_route = true;
            $token_raw = trim((string) ($token_candidate !== '' ? $token_candidate : $email_candidate));
        } elseif (todo_get_current_action_context('') === 'unsubscribe' && todo_get_current_page_context('') === '') {
            $is_unsub_route = true;
            $token_raw = trim((string) ($token_candidate !== '' ? $token_candidate : $email_candidate));
        } elseif (preg_match('~/(?:email/)?unsubscribe(?:/([^/?#]+))?~i', $path, $matches)) {
            $is_unsub_route = true;
            if (!empty($matches[1])) {
                $token_raw = trim(urldecode($matches[1]));
            } else {
                $token_raw = trim((string) ($token_candidate !== '' ? $token_candidate : $email_candidate));
            }
        }

        if (!$is_unsub_route) {
            return;
        }

        $email_or_token = $token_raw;
        if ($email_or_token !== '') {
            $decoded = @base64_decode($email_or_token, true);
            if ($decoded !== false && filter_var($decoded, FILTER_VALIDATE_EMAIL)) {
                $email_or_token = $decoded;
            }
        }

        $success = false;
        $message = '';

        if ($email_or_token !== '') {
            global $tododb;
            if ($tododb) {
                $table = $tododb->prefix . 'email_subscribers';
                $candidates = array_unique(array_filter([$email_or_token, $decoded ?? '']));
                $placeholders = implode(',', array_fill(0, count($candidates), '%s'));
                $sql = $tododb->prepare("SELECT * FROM {$table} WHERE email IN ({$placeholders}) LIMIT 1", ...$candidates);
                $sub = $tododb->get_row($sql);

                if ($sub) {
                    $email_safe = htmlspecialchars($sub->email ?? '', ENT_QUOTES, 'UTF-8');
                    if ($sub->status === 'unsubscribed') {
                        $success = true;
                        $message = '[zh|您之前已经成功取消过订阅，无需重复操作。][en|You have already unsubscribed from our emails.]';
                    } else {
                        $upd = $tododb->update(
                            $table,
                            [
                                'status' => 'unsubscribed',
                                'unsubscribed_at' => date('Y-m-d H:i:s'),
                                'updated_at' => date('Y-m-d H:i:s')
                            ],
                            ['id' => $sub->id]
                        );
                        if ($upd !== false) {
                            $success = true;
                            $message = "[zh|退订成功！我们将不再向 {$email_safe} 发送任何推介与通知邮件。][en|Successfully unsubscribed! We will no longer send marketing or notification emails to {$email_safe}.]";
                        } else {
                            $message = '[zh|数据库更新失败，请稍后重试。][en|Database update failed. Please try again later.]';
                        }
                    }
                } else {
                    $message = '[zh|未找到对应的订阅记录或退订链接已失效。][en|Subscription record not found or the unsubscribe link has expired.]';
                }
            }
        } else {
            $message = '[zh|退订链接缺少有效的凭证参数。][en|Missing valid credentials in unsubscribe link.]';
        }

        todo_sync_query_context(array(
            'page' => 'unsubscribe',
            'token' => $token_candidate,
            'email' => $email_candidate,
        ));
        $GLOBALS['todo_unsubscribe_success'] = $success;
        $GLOBALS['todo_unsubscribe_message'] = $message;
        $GLOBALS['todo_unsubscribe_email'] = $email_or_token;
        $GLOBALS['todo_is_unsubscribe_page'] = true;

        if (function_exists('set_current_page_type')) {
            set_current_page_type('unsubscribe');
        }
    }
}

/**
 * 处理请求
 *
 * 根据URL参数处理不同的请求
 */
function cms_handle_request()
{

    // --------------------------------------------------------------------------------
    // 1. 处理主题/语言预览 (必须在 cms_init() 和路由解析之前执行，以影响所有钩子)
    // --------------------------------------------------------------------------------
    $is_preview_request = todo_get_query_context_value('preview', todo_get_string('preview')) == '1'
        && todo_get_query_context_value('theme', todo_get_string('theme')) !== '';
    if ($is_preview_request) {
        $is_admin_preview = false;
        $admin_preview_user_id = 0;
        
        // 优先检查 Session 中的管理员ID
        if (class_exists('Todo_Session_Manager')) {
            Todo_Session_Manager::init();
            $admin_preview_user_id = (int) (Todo_Session_Manager::get_admin_user_id_any_context() ?: 0);
            if ($admin_preview_user_id > 0) {
                $is_admin_preview = true;
            }
        } else {
            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }
            if (isset($_SESSION['admin_user_id']) && $_SESSION['admin_user_id'] > 0) {
                $admin_preview_user_id = (int) $_SESSION['admin_user_id'];
                $is_admin_preview = true;
            }
        }
        
        if (!$is_admin_preview && function_exists('current_user_can')) {
            $is_admin_preview = current_user_can('manage_options');
        }

        if ($is_admin_preview) {
            $preview_theme = sanitize_text_field((string) todo_get_query_context_value('theme', todo_get_string('theme')));
            $theme_path = rtrim(ABSPATH, '/') . '/' . trim(THEMES_DIR, '/') . '/' . $preview_theme;
            $has_theme_manifest = false;
            if (is_dir($theme_path)) {
                $has_theme_manifest = file_exists($theme_path . '/theme.json')
                    || file_exists($theme_path . '/pc/theme.json')
                    || file_exists($theme_path . '/mobile/theme.json');
            }
            if ($has_theme_manifest) {
                // 核心：设置全局变量，最高优先级供 get_theme_name 使用
                $GLOBALS['preview_theme_name'] = $preview_theme;
                $GLOBALS['todo_forced_theme'] = $preview_theme;
                
                // 核心：注册过滤器以拦截激活主题
                add_filter('active_theme', function ($theme) use ($preview_theme) {
                    return $preview_theme;
                }, 1);

                // 核心：重置 CMS_Theme 的内部静态缓存
                if (class_exists('CMS_Theme')) {
                    CMS_Theme::reset_active_theme_cache();
                }

                // 发送调试头
                if (!headers_sent()) {
                    header('X-Todo-Preview-Theme: ' . $preview_theme);
                    header('X-Todo-Preview-Status: Active');
                }

                // 可选：设置预览语言
                $preview_lang_raw = (string) todo_get_query_context_value('lang', todo_get_string('lang'));
                if ($preview_lang_raw !== '') {
                    $preview_lang = str_replace('_', '-', sanitize_text_field($preview_lang_raw));
                    if (class_exists('LocaleContext')) {
                        LocaleContext::get_instance()->set_locale($preview_lang);
                    }
                }
                
                // 记录日志
                if (function_exists('todo_log_action')) {
                    $uid = (int) ($admin_preview_user_id ?: 0);
                    todo_log_action('theme_preview', array(
                        'theme' => $preview_theme,
                        'user_id' => $uid,
                        'ip' => function_exists('todo_get_user_ip') ? todo_get_user_ip() : (function_exists('todo_server_string') ? todo_server_string('REMOTE_ADDR', '') : ($_SERVER['REMOTE_ADDR'] ?? ''))
                    ));
                }
            }
        }
    }

    // 初始化系统
    cms_init();

    // 处理邮件退订/取消订阅请求
    if (function_exists('todo_handle_unsubscribe_request')) {
        todo_handle_unsubscribe_request();
    }

    // 检查定时发布的文章
    if (function_exists('todo_run_scheduled_posts')) {
        todo_run_scheduled_posts();
    }

    // 执行请求前钩子
    do_action('pre_request');

    // 用户中心页面登录守卫（购物车、结算页、收银台、找回密码及订单明细/凭证页/评价页除外，允许游客免登录查看订单与评价）
    $user_context = (string) todo_get_query_context_value('user', todo_get_string('user'));
    if ($user_context !== '' && !in_array($user_context, array('cart', 'checkout', 'payment', 'login', 'register', 'logout', 'forgot_password', 'orders', 'order-detail', 'order-review', 'order-success'), true)) {
        if (!is_user_logged_in()) {
            $current_url = function_exists('todo_get_current_url') ? todo_get_current_url() : home_url('/?user=' . $user_context);
            if (function_exists('get_current_language') && function_exists('get_language_url')) {
                $lang = get_current_language();
                if (!empty($lang)) {
                    $current_url = get_language_url($lang, $current_url);
                }
            }
            $login_url = todo_login_url($current_url);
            header('Location: ' . $login_url);
            exit;
        }
    }

    // 管理员动态预览定时文章：?todoview=future
    if (todo_get_query_context_value('todoview', todo_get_string('todoview')) === 'future') {
        $is_admin = false;

        // 确保会话已启动
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // 多种方式检查管理员权限
        if (function_exists('todo_current_user_can')) {
            $is_admin = todo_current_user_can('manage_options');
        } elseif (function_exists('current_user_can')) {
            $is_admin = current_user_can('manage_options');
        } else {
            // 备用权限检查：使用 Todo_Session_Manager
            if (class_exists('Todo_Session_Manager')) {
                Todo_Session_Manager::init();
                if (Todo_Session_Manager::is_admin_logged_in()) {
                    $is_admin = true;
                }
            }
        }

        if (!$is_admin) {
            header('HTTP/1.1 403 Forbidden');
            header('Content-Type: text/plain; charset=UTF-8');
            header('X-Robots-Tag: noindex, nofollow', true);
            echo '403 Forbidden';
            exit;
        }

        if (!defined('TODO_VIEW_FUTURE')) {
            define('TODO_VIEW_FUTURE', true);
        }
    }

    // 加载permalink处理器
    if (class_exists('Todo_Function_Loader')) {
        Todo_Function_Loader::load_function('permalink-handler');
    }

    // 解析友好URL
    $request_uri = todo_get_request_uri();
    $raw_path = parse_url($request_uri, PHP_URL_PATH);
    $request_path = is_string($raw_path) ? trim($raw_path, '/') : '';

    // 移除脚本名称（如果存在，例如 index.php）
    $script_name = function_exists('todo_server_string') ? todo_server_string('SCRIPT_NAME', '') : (isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '');
    if ($script_name !== '' && strpos($request_uri, $script_name) === 0) {
        $sub_req = substr($request_uri, strlen($script_name));
        $sub_path = parse_url($sub_req, PHP_URL_PATH);
        $request_path = is_string($sub_path) ? trim($sub_path, '/') : '';
    }
    if ($script_name !== '' && $request_path === basename($script_name)) {
        $request_path = '';
    }

    // 归一化：移除子目录前缀，使 $request_path 成为相对于站点根的路径段
    $script_dir = $script_name !== '' ? rtrim(str_replace('\\', '/', dirname($script_name)), '/') : '';
    if ($script_dir !== '' && $script_dir !== '/') {
        $script_dir_trimmed = trim($script_dir, '/');
        if ($script_dir_trimmed !== '' && strpos($request_path, $script_dir_trimmed . '/') === 0) {
            $request_path = substr($request_path, strlen($script_dir_trimmed) + 1);
        } elseif ($request_path === $script_dir_trimmed) {
            $request_path = '';
        }
    }
    // 统一将归一化后的路径作为解析输入
    $request_uri = $request_path;

    // 语言前缀剥离
    if (class_exists('LanguageRegistry')) {
        $reg = LanguageRegistry::get_instance();
        $map = $reg->path_prefix_map();
        $segs = array_values(array_filter(explode('/', trim($request_path, '/')), function ($s) {
            return $s !== '';
        }));
        if (!empty($segs)) {
            $first = strtolower($segs[0]);
            foreach ($map as $prefix => $loc) {
                if ($first === strtolower($prefix)) {
                    array_shift($segs);
                    $request_path = implode('/', $segs);
                    $request_uri = $request_path;
                    break;
                }
            }
        }
    }

    // 语义化归档前缀剥离：/archive/2026/06/hello-world.html → /2026/06/hello-world.html
    if (preg_match('#^archive/([0-9]{4})/#', $request_path)) {
        $request_path = preg_replace('#^archive/#', '', $request_path);
        $request_uri = $request_path;
    }

    // 优先处理 ?p=ID 兜底（始终识别为文章详情ID，避免与分页混淆）
    $raw_p = todo_get_query_context_value('p', todo_get_int('p'));
    if ($raw_p > 0) {
        $post_id = intval($raw_p);
        todo_sync_query_context(array('page' => 'post', 'id' => $post_id));
    }

    // === 核心路由解析 (Internal Router) ===

    // 0. 优先识别 Query String 显式路由参数（?user= 或 ?page=）
    $raw_user_param = (string) todo_get_query_context_value('user', todo_get_string('user'));
    if ($raw_user_param !== '') {
        $u_param = sanitize_text_field($raw_user_param);
        $u_map = array(
            'login' => 'login', 'register' => 'register', 'cart' => 'cart', 'checkout' => 'checkout', 'payment' => 'payment'
        );
        if (isset($u_map[$u_param])) {
            todo_sync_query_context(array('page' => $u_map[$u_param]));
        } else {
            $subpage = ($u_param === 'center' || $u_param === 'index') ? 'profile' : $u_param;
            todo_sync_query_context(array('page' => 'user', 'subpage' => $subpage));
        }
    }
    // 1. API 请求
    elseif ((!empty($request_path) && strpos($request_path, 'api/') === 0) || (todo_get_current_page_context('') === 'api')) {
        $api_overrides = array();
        if (todo_get_current_page_context('') === '') {
            $api_overrides['page'] = 'api';
        }
        $api_path = todo_get_query_context_value('path', todo_get_string('path'));
        if ($api_path === '' && !empty($request_path)) {
            $api_path = substr($request_path, 4);
        }
        if ($api_path !== '') {
            $api_overrides['path'] = $api_path;
        }
        if (!empty($api_overrides)) {
            todo_sync_query_context($api_overrides);
        }
    }
    // 2. 用户中心路由
    elseif (preg_match('#^users?(/.*)?$#i', $request_path, $matches)) {
        $parts = explode('/', trim($request_path, '/'));
        $subpage = isset($parts[1]) ? $parts[1] : 'index';
        $route_overrides = array(
            'page' => 'user',
            'subpage' => $subpage,
        );
        if ($subpage === 'order-detail' && isset($parts[2])) {
            $route_overrides['id'] = intval($parts[2]);
        }
        todo_sync_query_context($route_overrides);
    }
    // 3. 商城路由
    elseif (preg_match('#^shop(/([^/]+))?/?$#i', $request_path, $matches)) {
        $route_overrides = array('page' => 'shop');
        if (!empty($matches[2])) {
            $route_overrides['action'] = $matches[2];
        }
        todo_sync_query_context($route_overrides);
    }
    // 3b. 分页路由（固定链接模式）：/page/2/ 作为首页分页，避免与旧的 /page/{id}/ 静态页面ID路由冲突
    elseif (function_exists('get_option') && get_option('permalink_structure') && preg_match('#^page/([0-9]+)/?$#i', $request_path, $matches)) {
        todo_sync_query_context(array('paged' => max(1, intval($matches[1]))));
    }
    // 3c. 归档分页路由（固定链接模式）— 聚合首页分页：/tag/page/2/
    elseif (function_exists('get_option') && get_option('permalink_structure') && preg_match('#^(category|tag)/page/([0-9]+)/?$#i', $request_path, $matches)) {
        todo_sync_query_context(array(
            'page' => strtolower($matches[1]),
            'paged' => max(1, intval($matches[2])),
        ));
    }
    // 3c. 归档分页路由（固定链接模式）— 具体标签/分类分页：/tag/{slug}/page/2/
    elseif (function_exists('get_option') && get_option('permalink_structure') && preg_match('#^(category|tag)/([^/]+)/page/([0-9]+)/?$#i', $request_path, $matches)) {
        $route_page = strtolower($matches[1]);
        $slug_or_id = $matches[2];
        $route_overrides = array(
            'page' => $route_page,
            'paged' => max(1, intval($matches[3])),
        );
        if (is_numeric($slug_or_id)) {
            $route_overrides['id'] = intval($slug_or_id);
        } else {
            $route_overrides[$matches[1] . '_name'] = $slug_or_id;
        }
        todo_sync_query_context($route_overrides);
    }
    elseif (function_exists('get_option') && get_option('permalink_structure') && preg_match('#^author/([0-9]+)/page/([0-9]+)/?$#i', $request_path, $matches)) {
        todo_sync_query_context(array(
            'page' => 'author',
            'id' => intval($matches[1]),
            'paged' => max(1, intval($matches[2])),
        ));
    }
    // 4. 系统固定路由 (post, page, category, tag, author) — 支持数字ID和slug（如 /post/wo-chan-pin-3.html 或 /post/3）
    elseif (preg_match('#^(post|page)/([^/]+)/?$#i', $request_path, $matches)) {
        $route_overrides = array('page' => strtolower($matches[1]));
        $raw_slug = preg_replace('#\.(html?|php)$#i', '', $matches[2]);
        if (is_numeric($raw_slug)) {
            $route_overrides['id'] = intval($raw_slug);
            set_current_post(intval($raw_slug));
        } else {
            $route_overrides['post_name'] = $raw_slug;
            $route_overrides['slug'] = $raw_slug;
            global $tododb;
            if (isset($tododb)) {
                $pid = $tododb->get_var($tododb->prepare("SELECT ID FROM " . TABLE_PREFIX . "posts WHERE post_name = %s OR custom_url = %s LIMIT 1", $raw_slug, $raw_slug));
                if ($pid) {
                    $route_overrides['id'] = (int) $pid;
                    set_current_post((int)$pid);
                }
            }
        }
        todo_sync_query_context($route_overrides);
    }
    elseif (preg_match('#^(category|tag)/?$#i', $request_path, $matches)) {
        // 分类/标签聚合首页（无具体slug）：/tag/ 或 /category/
        todo_sync_query_context(array('page' => strtolower($matches[1])));
    }
    elseif (preg_match('#^(category|tag)/([^/]+)/?$#i', $request_path, $matches)) {
        $route_page = strtolower($matches[1]);
        $slug_or_id = $matches[2];
        $route_overrides = array('page' => $route_page);
        if (is_numeric($slug_or_id)) {
            $route_overrides['id'] = intval($slug_or_id);
        } else {
            $route_overrides[$matches[1] . '_name'] = $slug_or_id;
        }
        todo_sync_query_context($route_overrides);
    }
    elseif (preg_match('#^author/([0-9]+)/?$#i', $request_path, $matches)) {
        todo_sync_query_context(array('page' => 'author', 'id' => intval($matches[1])));
    }
    // 5. 身份验证路由
    elseif (preg_match('#^(login|register|logout)/?$#i', $request_path, $matches)) {
        $act = strtolower($matches[1]);
        $route_overrides = array();
        if ($act === 'logout') {
            $route_overrides['page'] = 'login';
            $route_overrides['action'] = 'logout';
        } else {
            $route_overrides['page'] = $act;
        }
        todo_sync_query_context($route_overrides);
    }
    // 6. 搜索路由
    elseif (preg_match('#^search/?$#i', $request_path)) {
        todo_sync_query_context(array('page' => 'search'));
    }
    // 6b. RSS 订阅路由
    elseif (preg_match('#^rss$#i', $request_path)) {
        $route_overrides = array('page' => 'rss');
        $category_id = todo_get_int('category');
        if ($category_id > 0) {
            $route_overrides['category'] = $category_id;
        }
        todo_sync_query_context($route_overrides);
    }
    // 6c. API 支付接口直接路由
    elseif (preg_match('#^api/payment/(.*)$#i', $request_path, $matches)) {
        todo_sync_query_context(array(
            'page' => 'api',
            'path' => 'payment/' . $matches[1],
        ));
    }
    // 7. 结算与购物车路由
    elseif (preg_match('#^(checkout|cart|payment)/?$#i', $request_path, $matches)) {
        todo_sync_query_context(array('page' => strtolower($matches[1])));
    }
    // 7b. 日期归档路由 (固定链接格式: /2026/06/ 或 /2026/06/15/ 或 /2026/)
    elseif (preg_match('#^([0-9]{4})(?:/([0-9]{1,2}))?(?:/([0-9]{1,2}))?(?:/page/([0-9]+))?/?$#', $request_path, $matches)) {
        $route_overrides = array(
            'page' => 'archive',
            'archive_year' => intval($matches[1]),
        );
        if (!empty($matches[2])) {
            $route_overrides['archive_month'] = intval($matches[2]);
        }
        if (!empty($matches[3])) {
            $route_overrides['archive_day'] = intval($matches[3]);
        }
        if (!empty($matches[4])) {
            $route_overrides['paged'] = max(1, intval($matches[4]));
        }
        todo_sync_query_context($route_overrides);
    }
    // 7c. 语义化归档路由 (固定链接格式: /archive/2026/06/ 或 /archive/2026/06/15/ 或 /archive/2026/)
    elseif (preg_match('#^archive/([0-9]{4})(?:/([0-9]{1,2}))?(?:/([0-9]{1,2}))?(?:/page/([0-9]+))?/?$#i', $request_path, $matches)) {
        $route_overrides = array(
            'page' => 'archive',
            'archive_year' => intval($matches[1]),
        );
        if (!empty($matches[2])) {
            $route_overrides['archive_month'] = intval($matches[2]);
        }
        if (!empty($matches[3])) {
            $route_overrides['archive_day'] = intval($matches[3]);
        }
        if (!empty($matches[4])) {
            $route_overrides['paged'] = max(1, intval($matches[4]));
        }
        todo_sync_query_context($route_overrides);
    }
    // 7d. 归档聚合页路由 (固定链接格式: /archive 或 /archive/page/2)
    elseif (preg_match('#^archive(?:/page/([0-9]+))?/?$#i', $request_path, $matches)) {
        $route_overrides = array('page' => 'archive');
        if (!empty($matches[1])) {
            $route_overrides['paged'] = max(1, intval($matches[1]));
        }
        todo_sync_query_context($route_overrides);
    }
    // 8. 友好 URL 兜底 (Permalink Handler - 处理别名等)
    elseif (!empty($request_path) && todo_get_current_page_context('') === '' && function_exists('todo_parse_permalink_request')) {
        $__perm_uri = $request_uri;
        $__paged_stripped = 0;
        if (preg_match('#^(.+?)/page/([0-9]+)/?$#', $request_path, $__pgm)) {
            $__perm_uri = $__pgm[1] . '/';
            $__paged_stripped = max(1, intval($__pgm[2]));
            if (todo_get_query_context_value('paged', null) === null && !isset($_GET['paged'])) {
                todo_sync_query_context(array('paged' => $__paged_stripped));
            }
        }
        $query_vars = todo_parse_permalink_request($__perm_uri);
        if ($query_vars !== false && $__paged_stripped > 0 && !isset($query_vars['paged'])) {
            $query_vars['paged'] = $__paged_stripped;
        }
        if ($query_vars !== false) {
            // 当 URL 无明确路由前缀时，优先识别分类/标签 slug，再回退到文章匹配
            $name_slug = (!empty($query_vars['name']) && empty($query_vars['category_name']) && empty($query_vars['tag_name']) && empty($query_vars['author_name']))
                ? rawurldecode(trim($query_vars['name'])) : '';
            if ($name_slug !== '') {
                // 剥离 /page/N/ 分页后缀
                if (preg_match('#^(.+?)/page/(\d+)/?$#', $name_slug, $pm)) {
                    $name_slug = $pm[1];
                    todo_sync_query_context(array('paged' => max(1, intval($pm[2]))));
                }
                // 先尝试分类 slug 匹配
                if (function_exists('get_category_by_slug')) {
                    $cat = get_category_by_slug($name_slug);
                    $cat_id = 0;
                    if (is_array($cat) && isset($cat['term_id'])) {
                        $cat_id = intval($cat['term_id']);
                    } elseif (is_object($cat) && isset($cat->term_id)) {
                        $cat_id = intval($cat->term_id);
                    }
                    if ($cat_id > 0) {
                        todo_sync_query_context(array(
                            'page' => 'category',
                            'id' => $cat_id,
                            'cat' => $cat_id,
                        ));
                    }
                }
                // 分类未命中，再尝试标签 slug 匹配
                if (todo_get_current_page_context('') === '' && function_exists('get_tag_by_slug')) {
                    $tag = get_tag_by_slug($name_slug);
                    $tag_id = 0;
                    if (is_array($tag) && isset($tag['term_id'])) {
                        $tag_id = intval($tag['term_id']);
                    } elseif (is_object($tag) && isset($tag->term_id)) {
                        $tag_id = intval($tag->term_id);
                    }
                    if ($tag_id <= 0 && is_object($tag) && isset($tag->id) && $tag->id > 0) {
                        $tag_id = intval($tag->id);
                    }
                    if ($tag_id <= 0 && is_array($tag) && isset($tag['id']) && $tag['id'] > 0) {
                        $tag_id = intval($tag['id']);
                    }
                    if ($tag_id > 0) {
                        todo_sync_query_context(array(
                            'page' => 'tag',
                            'id' => $tag_id,
                            'tag' => $tag_id,
                        ));
                    }
                }
            }
            // 未匹配到分类/标签，回退到文章匹配
            if (todo_get_current_page_context('') === '') {
                $post_id = todo_get_post_id_from_query_vars($query_vars);
                if ($post_id) {
                    todo_sync_query_context(array('page' => 'post', 'id' => $post_id));
                }
            }
            // 内核未匹配到文章时，回退到直接 DB 查询（同时匹配 post_name 和 custom_url）
            if (todo_get_current_page_context('') === '' && !empty($name_slug)) {
                global $tododb;
                if ($tododb) {
                    $table_posts = $tododb->prefix . 'posts';
                    $slug_clean = preg_replace('/\.(html|htm|php)$/i', '', $name_slug);
                    $post_row = $tododb->get_row(
                        "SELECT ID FROM {$table_posts} WHERE (post_name = ? OR custom_url = ?) AND (post_status = 'publish' OR post_status = 'future') LIMIT 1",
                        array($slug_clean, $slug_clean)
                    );
                    if ($post_row && intval($post_row->ID) > 0) {
                        todo_sync_query_context(array('page' => 'post', 'id' => intval($post_row->ID)));
                    }
                }
            }
            // URL数字ID兜底：当 post_name/custom_url 为空时，尝试从URL末尾提取文章ID
            if (todo_get_current_page_context('') === '' && !empty($name_slug)) {
                global $tododb;
                if ($tododb) {
                    $slug_clean = preg_replace('/\.(html|htm|php)$/i', '', $name_slug);
                    // 提取末尾的数字串（至少2位，避免误匹配短数字）
                    if (preg_match('/(\d{2,})$/', $slug_clean, $id_match)) {
                        $possible_id = intval($id_match[1]);
                        if ($possible_id > 0) {
                            $table_posts = $tododb->prefix . 'posts';
                            $post_row = $tododb->get_row(
                                "SELECT ID FROM {$table_posts} WHERE ID = ? AND (post_status = 'publish' OR post_status = 'future') LIMIT 1",
                                array($possible_id)
                            );
                            if ($post_row && intval($post_row->ID) > 0) {
                                todo_sync_query_context(array('page' => 'post', 'id' => intval($post_row->ID)));
                            }
                        }
                    }
                }
            }
            // 归档路由分发：当解析到归档变量但未匹配到具体文章时，分发到对应归档页
            if (todo_get_current_page_context('') === '') {
                if (!empty($query_vars['category_name'])) {
                    // 分类归档：/category/{slug}/
                    $slug = rawurldecode(trim($query_vars['category_name']));
                    if (function_exists('get_category_by_slug')) {
                        $cat = get_category_by_slug($slug);
                        $cat_id = 0;
                        if (is_array($cat) && isset($cat['term_id'])) {
                            $cat_id = intval($cat['term_id']);
                        } elseif (is_object($cat) && isset($cat->term_id)) {
                            $cat_id = intval($cat->term_id);
                        }
                        if ($cat_id > 0) {
                            todo_sync_query_context(array('page' => 'category', 'id' => $cat_id));
                        }
                    }
                    if (todo_get_current_page_context('') === '') {
                        // slug 未命中分类，返回 404
                        header('HTTP/1.0 404 Not Found');
                        todo_sync_query_context(array('page' => '404'));
                        set_current_page('404');
                        return;
                    }
                } elseif (!empty($query_vars['tag_name'])) {
                    // 标签归档：/tag/{slug}/
                    $slug = rawurldecode(trim($query_vars['tag_name']));
                    if (function_exists('get_tag_by_slug')) {
                        $tag = get_tag_by_slug($slug);
                        $tag_id = 0;
                        if (is_array($tag) && isset($tag['term_id'])) {
                            $tag_id = intval($tag['term_id']);
                        } elseif (is_object($tag) && isset($tag->term_id)) {
                            $tag_id = intval($tag->term_id);
                        }
                        // normalize_tag 将 tag_id 映射为 id，兼容新标签系统
                        if ($tag_id <= 0 && is_object($tag) && isset($tag->id) && $tag->id > 0) {
                            $tag_id = intval($tag->id);
                        }
                        if ($tag_id <= 0 && is_array($tag) && isset($tag['id']) && $tag['id'] > 0) {
                            $tag_id = intval($tag['id']);
                        }
                        if ($tag_id > 0) {
                            todo_sync_query_context(array('page' => 'tag', 'id' => $tag_id));
                        }
                    }
                    if (todo_get_current_page_context('') === '') {
                        header('HTTP/1.0 404 Not Found');
                        todo_sync_query_context(array('page' => '404'));
                        set_current_page('404');
                        return;
                    }
                } elseif (!empty($query_vars['year'])) {
                    // 日期归档：/2025/ 或 /2025/05/ 等
                    $archive_overrides = array(
                        'page' => 'archive',
                        'archive_year' => intval($query_vars['year']),
                    );
                    if (!empty($query_vars['monthnum'])) {
                        $archive_overrides['archive_month'] = intval($query_vars['monthnum']);
                    }
                    if (!empty($query_vars['day'])) {
                        $archive_overrides['archive_day'] = intval($query_vars['day']);
                    }
                    todo_sync_query_context($archive_overrides);
                } elseif (!empty($query_vars['name'])) {
                    // 固定连接别名回退：当 /{slug}/ 未匹配到文章时，尝试作为分类 slug 匹配
                    $name_slug = rawurldecode(trim($query_vars['name']));
                    if (function_exists('get_category_by_slug')) {
                        $cat = get_category_by_slug($name_slug);
                        $cat_id = 0;
                        if (is_array($cat) && isset($cat['term_id'])) {
                            $cat_id = intval($cat['term_id']);
                        } elseif (is_object($cat) && isset($cat->term_id)) {
                            $cat_id = intval($cat->term_id);
                        }
                        if ($cat_id > 0) {
                            todo_sync_query_context(array('page' => 'category', 'id' => $cat_id));
                        }
                    }
                    if (todo_get_current_page_context('') === '') {
                        header('HTTP/1.0 404 Not Found');
                        todo_sync_query_context(array('page' => '404'));
                        set_current_page('404');
                        return;
                    }
                } elseif (todo_get_current_page_context('') === '') {
                    // 无法识别的路径，返回 404
                    header('HTTP/1.0 404 Not Found');
                    todo_sync_query_context(array('page' => '404'));
                    set_current_page('404');
                    return;
                }
            }
            $missing_query_overrides = array();
            foreach ($query_vars as $key => $value) {
                if (todo_get_query_context_value($key, null) === null && !isset($_GET[$key])) {
                    $missing_query_overrides[$key] = $value;
                }
            }
            if (!empty($missing_query_overrides)) {
                todo_sync_query_context($missing_query_overrides);
            }
        } elseif (!empty($request_path) && todo_get_current_page_context('') === '') {
            header('HTTP/1.0 404 Not Found');
            todo_sync_query_context(array('page' => '404'));
            set_current_page('404');
            return;
        }
    }

    // 处理主题/语言预览（仅管理员可用）
    if ($is_preview_request) {
        if (function_exists('current_user_can') && !current_user_can('manage_options')) {
            // 非管理员，忽略预览请求
        } else {
            $preview_theme = sanitize_text_field((string) todo_get_query_context_value('theme', todo_get_string('theme')));
            // 验证主题是否存在
            $theme_path = rtrim(ABSPATH, '/') . '/' . trim(THEMES_DIR, '/') . '/' . $preview_theme;
            $has_theme_manifest = false;
            if (is_dir($theme_path)) {
                $has_theme_manifest = file_exists($theme_path . '/theme.json')
                    || file_exists($theme_path . '/pc/theme.json')
                    || file_exists($theme_path . '/mobile/theme.json');
            }
            if ($has_theme_manifest) {
                // 临时设置预览主题
                global $preview_theme_name;
                $preview_theme_name = $preview_theme;
                add_filter('active_theme', function ($theme) use ($preview_theme) {
                    return $preview_theme;
                });

                // 可选：设置预览语言
                $preview_lang_raw = (string) todo_get_query_context_value('lang', todo_get_string('lang'));
                if ($preview_lang_raw !== '') {
                    $preview_lang = str_replace('_', '-', sanitize_text_field($preview_lang_raw));
                    if (class_exists('LocaleContext')) {
                        LocaleContext::get_instance()->set_locale($preview_lang);
                    }
                }

                // 记录日志
                if (function_exists('todo_log_action')) {
                    $uid = function_exists('get_current_user_id') ? get_current_user_id() : 0;
                    todo_log_action('theme_preview', array(
                        'theme' => $preview_theme,
                        'lang' => isset($preview_lang) ? $preview_lang : '',
                        'user_id' => $uid
                    ));
                }
            }
        }
    }

    // 检测搜索请求：?s= 或 ?q= 参数
    if (todo_get_search_context() !== '' && todo_get_current_page_context('') === '') {
        todo_sync_query_context(array('page' => 'search'));
    }

    $cat_query_id = (int) todo_get_query_context_value('cat', todo_get_int('cat'));
    if ($cat_query_id > 0 && todo_get_current_page_context('') === '') {
        todo_sync_query_context(array('page' => 'category', 'id' => $cat_query_id, 'cat' => $cat_query_id));
    } else {
        $cat_query_slug = sanitize_title((string) todo_get_query_context_value('category_name', todo_get_string('category_name')));
        if ($cat_query_slug !== '' && todo_get_current_page_context('') === '') {
            if (function_exists('get_category_by_slug')) {
                $c = get_category_by_slug($cat_query_slug);
                if (is_array($c) && isset($c['term_id'])) {
                    todo_sync_query_context(array('page' => 'category', 'id' => intval($c['term_id']), 'category_name' => $cat_query_slug));
                } elseif (is_object($c) && isset($c->term_id)) {
                    todo_sync_query_context(array('page' => 'category', 'id' => intval($c->term_id), 'category_name' => $cat_query_slug));
                }
            }
        }
    }

    // ?p=ID 已在"固定链接与路径解析"之前优先处理，避免语言前缀路径造成干扰
    // 获取请求参数
    // 优先处理 ?user= 参数（标准化用户中心路径）
    $raw_user_query = (string) todo_get_query_context_value('user', todo_get_string('user'));
    if ($raw_user_query !== '') {
        $user_query_value = $raw_user_query;
        if ($user_query_value === 'forgot-password') {
            $user_query_value = 'forgot_password';
            todo_sync_query_context(array('user' => $user_query_value));
        }
        $user_page = sanitize_text_field($user_query_value);
        // 将 user 参数映射到 page 参数，实现标准化路由
        $user_page_map = array(
            'login'    => 'login',
            'register' => 'register',
            'forgot_password' => 'forgot_password',
            'center'   => 'user',
            'profile'  => 'user',
            'logout'   => 'login', // logout 通过 action 处理
            'orders'   => 'user',
            'favorites' => 'user',
            'cart'     => 'cart',
            'checkout' => 'checkout',
            'payment'  => 'payment',
            'coupons'  => 'user',
            'security' => 'user',
            'balance'  => 'user',
            'order-detail' => 'user',
            'order-review' => 'user',
            'order-success' => 'user',
            'password' => 'user',
            'wallet'   => 'user',
            'address'  => 'user',
            'addresses' => 'user'
        );
        if (isset($user_page_map[$user_page])) {
            $mapped_page = $user_page_map[$user_page];
            $user_route_overrides = array('page' => $mapped_page);
            
            // 如果映射到 user 页面，自动设置 subpage
            if ($mapped_page === 'user') {
                $subpage = ($user_page === 'center' || $user_page === 'index' || $user_page === 'security') ? 'profile' : $user_page;
                if ($subpage === 'address') {
                    $subpage = 'addresses';
                } elseif ($subpage === 'balance') {
                    $subpage = 'finance';
                }
                $user_route_overrides['subpage'] = $subpage;
            }
            
            // 用户中心页面登录守卫：购物车、结算页、收银台、找回密码与订单明细/凭证页/评价页除外（允许游客携带 order_id 直接查看）
            if ($mapped_page === 'user' && !in_array($user_page, array('cart', 'checkout', 'payment', 'forgot_password', 'orders', 'order-detail', 'order-review', 'order-success'), true)) {
                if (!is_user_logged_in()) {
                    $current_qs = function_exists('todo_server_string') ? todo_server_string('QUERY_STRING', 'user=' . $user_page) : (!empty($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : ('user=' . $user_page));
                    $current_url = function_exists('todo_get_current_url') ? todo_get_current_url() : home_url('/?' . $current_qs);
                    if (function_exists('get_current_language') && function_exists('get_language_url')) {
                        $lang = get_current_language();
                        if (!empty($lang)) {
                            $current_url = get_language_url($lang, $current_url);
                        }
                    }
                    $login_url = todo_login_url($current_url);
                    header('Location: ' . $login_url);
                    exit;
                }
            }
            
            // 如果是 logout，设置 action
            if ($user_page === 'logout') {
                $user_route_overrides['action'] = 'logout';
            }
            todo_sync_query_context($user_route_overrides);
        }
    }

    $page = todo_get_current_page_context('home');
    $action = todo_get_current_action_context('');

    // 检查是否为管理后台页面请求
    $admin_pages = array(
        'dashboard',
        'posts',
        'post-new',
        'post-edit',
        'categories',
        'tags',
        'media',
        'users',
        'user-new',
        'user-edit',
        'plugins',
        'themes',
        'settings',
        'menus',
        'ads',
        'links',
        'models',
        'tools',
        'comments',
        'orders'
    );

    if ((defined('IS_ADMIN') && IS_ADMIN) && in_array($page, $admin_pages)) {
        // 重定向到管理后台
        $admin_url = todo_admin_url() . 'index.php?' . (function_exists('todo_server_string') ? todo_server_string('QUERY_STRING', '') : ($_SERVER['QUERY_STRING'] ?? ''));
        header('Location: ' . $admin_url);
        exit;
    }

    // 处理不同的页面请求
    switch ($page) {
        case 'post':
            $post_id = (int) get_query_var('id', 0);
            if ($post_id) {
                // 显示单篇文章
                set_current_post($post_id);

                // 如果是定时文章且未开启管理员动态预览，则返回404
                $allow_future_preview = (defined('TODO_VIEW_FUTURE') && TODO_VIEW_FUTURE);
                $post_obj = get_current_post();
                if (!$post_obj) {
                    header('HTTP/1.0 404 Not Found');
                    todo_sync_query_context(array('page' => '404'));
                    set_current_page('404');
                    return;
                }
                if ($post_obj && isset($post_obj->post_status)) {
                    $status = strtolower($post_obj->post_status);
                    if (in_array($status, ['future', 'scheduled'], true) && !$allow_future_preview) {
                        header('HTTP/1.0 404 Not Found');
                        todo_sync_query_context(array('page' => '404'));
                        set_current_page('404');
                    }
                }

                // 检查语言启用状态：如果当前语言未启用，则返回404（管理员预览除外）
                if ($post_obj && (!defined('IS_ADMIN') || !IS_ADMIN)) {
                    $language_code = (function_exists('get_current_language') ? get_current_language() : (function_exists('todo_get_current_language') ? todo_get_current_language() : null));
                    if ($language_code) {
                        $language_code = str_replace('_', '-', $language_code);
                        // 检查是否为主语言（主语言可见所有文章）
                        $default_lang = function_exists('get_default_language') ? get_default_language() : 'zh-CN';
                        $default_lang = str_replace('_', '-', $default_lang);
                        $is_main_lang = ($language_code === $default_lang);

                        if (!$is_main_lang) {
                            $enabled_langs_json = get_post_meta($post_obj->ID, '_enabled_languages', true);
                            if (!empty($enabled_langs_json)) {
                                $enabled_langs = json_decode($enabled_langs_json, true);
                                if (is_array($enabled_langs) && !in_array($language_code, $enabled_langs)) {
                                    header('HTTP/1.0 404 Not Found');
                                    todo_sync_query_context(array('page' => '404'));
                                    set_current_page('404');
                                }
                            }
                        }
                    }
                }

                $should_increment_views = true;
                if ((defined('IS_ADMIN') && IS_ADMIN) || (defined('TODO_VIEW_FUTURE') && TODO_VIEW_FUTURE)) {
                    $should_increment_views = false;
                }
                $request_method = strtoupper(function_exists('todo_server_string') ? todo_server_string('REQUEST_METHOD', 'GET') : ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
                if ($request_method !== 'GET') {
                    $should_increment_views = false;
                }
                if (todo_get_current_page_context('') !== 'post') {
                    $should_increment_views = false;
                }
                if (!$post_obj || !isset($post_obj->ID) || intval($post_obj->ID) <= 0) {
                    $should_increment_views = false;
                } else {
                    $status = isset($post_obj->post_status) ? strtolower((string) $post_obj->post_status) : '';
                    if ($status !== 'publish') {
                        $should_increment_views = false;
                    }
                }
                if ($should_increment_views && function_exists('increment_post_views')) {
                    // 先在内存中更新浏览量，以便当前页面显示最新数据
                    $new_count = (int) ($post_obj->view_count ?? 0) + 1;
                    $post_obj->view_count = $new_count;
                    if (isset($GLOBALS['todo_post']) && is_object($GLOBALS['todo_post'])) {
                        $GLOBALS['todo_post']->view_count = $new_count;
                    }
                    if (isset($GLOBALS['current_post']) && is_object($GLOBALS['current_post'])) {
                        $GLOBALS['current_post']->view_count = $new_count;
                    }
                    
                    // 移除后端的阻塞/延迟更新，改为完全交由前端 AJAX 异步更新
                    // register_shutdown_function 被移除以释放 PHP-FPM 进程
                    
                    // 动态注入一段 JS 到页脚，进行异步统计
                    $post_id_to_view = (int) $post_obj->ID;
                    todo_add_action('todo_footer', function() use ($post_id_to_view) {
                        $api_url = todo_get_api_url("posts/{$post_id_to_view}/view");
                        echo "<script>
                            (function() {
                                if (window.fetch) {
                                    setTimeout(function() {
                                        fetch('{$api_url}', {
                                            method: 'POST',
                                            headers: {'Content-Type': 'application/json'},
                                            body: JSON.stringify({})
                                        }).catch(function(e) {});
                                    }, 1500);
                                }
                            })();
                        </script>";
                    }, 99);
                }
            } else {
                // 显示文章列表
                set_current_page('blog');
            }
            break;

        case 'page':
            $page_id = (int) get_query_var('id', 0);
            if ($page_id) {
                // 显示单个页面
                set_current_page($page_id);

                // 检查语言启用状态
                $page_obj = get_current_page();
                if (!$page_obj || !is_object($page_obj)) {
                    header('HTTP/1.0 404 Not Found');
                    todo_sync_query_context(array('page' => '404'));
                    set_current_page('404');
                    return;
                }
                if ($page_obj && is_object($page_obj) && (!defined('IS_ADMIN') || !IS_ADMIN)) {
                    $language_code = (function_exists('get_current_language') ? get_current_language() : (function_exists('todo_get_current_language') ? todo_get_current_language() : null));
                    if ($language_code) {
                        $language_code = str_replace('_', '-', $language_code);
                        // 检查是否为主语言（主语言可见所有文章）
                        $default_lang = function_exists('get_default_language') ? get_default_language() : 'zh-CN';
                        $default_lang = str_replace('_', '-', $default_lang);
                        $is_main_lang = ($language_code === $default_lang);

                        if (!$is_main_lang) {
                            $enabled_langs_json = get_post_meta($page_obj->ID, '_enabled_languages', true);
                            if (!empty($enabled_langs_json)) {
                                $enabled_langs = json_decode($enabled_langs_json, true);
                                if (is_array($enabled_langs) && !in_array($language_code, $enabled_langs)) {
                                    header('HTTP/1.0 404 Not Found');
                                    todo_sync_query_context(array('page' => '404'));
                                    set_current_page('404');
                                }
                            }
                        }
                    }
                }
            } else {
                // 显示首页
                set_current_page('home');
            }
            break;

        case 'category':
            $cat_id = (int) get_query_var('id', 0);
            if ($cat_id) {
                set_current_category($cat_id);
            } else {
                $req_model = (string) get_query_var('model', '');
                if ($req_model !== '') {
                    set_current_page_type('category');
                } else {
                    set_current_page('categories');
                }
            }
            break;

        case 'tag':
            $tag_id = (int) get_query_var('id', 0);
            if (!$tag_id) {
                $slug = rawurldecode(trim((string) get_query_var('tag_name', '')));
                if ($slug !== '' && function_exists('get_tag_by_slug')) {
                    $t = get_tag_by_slug($slug);
                    if ($t) {
                        $tag_id = is_object($t) ? (isset($t->term_id) ? intval($t->term_id) : (isset($t->id) ? intval($t->id) : 0)) : (isset($t['term_id']) ? intval($t['term_id']) : (isset($t['id']) ? intval($t['id']) : 0));
                    }
                }
            }

            if ($tag_id) {
                // 显示标签文章列表
                set_current_tag($tag_id);
            } else {
                // 显示标签列表
                set_current_page('tags');
            }
            break;

        case 'author':
            $author_id = (int) get_query_var('id', 0);

            if ($author_id) {
                // 显示作者文章列表
                set_current_author($author_id);
            } else {
                // 显示作者列表
                set_current_page('authors');
            }
            break;

        case 'search':
            // 搜索结果：优先从 ?s= 获取，兼容 ?q=
            $query = todo_get_search_context();
            set_search_query($query);
            break;

        case 'rss':
            // RSS 订阅
            set_current_page_type('rss');
            break;

        case 'login':
            // 登录页面
            if ($action === 'logout') {
                // 处理登出
                if (is_user_logged_in()) {
                    // 执行退出登录
                    todo_logout();

                    // 获取重定向URL
                    $redirect_to = (string) get_query_var('redirect_to', home_url());

                    // 添加退出成功消息
                    if (function_exists('todo_add_notice')) {
                        todo_add_notice('您已成功退出登录', 'success');
                    }

                    // 重定向
                    redirect($redirect_to);
                } else {
                    // 用户未登录，直接重定向到首页
                    redirect(home_url());
                }
            } else {
                // 若已经通过 token/session 识别为已登录用户，自动重定向到目标页或用户中心
                if (function_exists('is_user_logged_in') && is_user_logged_in()) {
                    $redirect_to = (string) get_query_var('redirect_to', '');
                    $target_url = $redirect_to !== '' ? urldecode($redirect_to) : (function_exists('todo_get_user_center_url') ? todo_get_user_center_url() : home_url('/?user=profile'));
                    redirect($target_url);
                    exit;
                }
                set_current_page('login');
            }
            break;

        case 'register':
            // 注册页面
            set_current_page('register');
            break;

        case 'forgot_password':
            // 找回密码页面
            set_current_page('forgot_password');
            break;

        case 'profile':
            // 用户资料页面
            if (!is_user_logged_in()) {
                redirect(todo_login_url());
            }
            set_current_page('profile');
            break;

        case 'user':
            // 用户中心页面
            if (!class_exists('Todo_Session_Manager')) {
                // 如果类不存在，可能是常量未定义，尝试定义后再检查
                if (!defined('TODO_LOADED')) {
                    define('TODO_LOADED', true);
                }
                // 再次检查类是否存在（Composer 会自动加载）
                if (!class_exists('Todo_Session_Manager')) {
                    throw new Exception('Todo_Session_Manager 类无法加载，请检查 Composer 自动加载配置');
                }
            }
            Todo_Session_Manager::init();
            $frontend_logged_in = Todo_Session_Manager::is_frontend_logged_in();
            $admin_logged_in = Todo_Session_Manager::is_admin_logged_in();

            // 如果既没有前台登录也没有后台登录，重定向到登录页（订单详情与凭证页允许游客携带订单参数访问）
            $subpage = (string) get_query_var('subpage', 'index');
            $is_guest_allowed_subpage = in_array($subpage, array('orders', 'order-detail', 'order-review', 'order-success'), true);

            if (!$frontend_logged_in && !$admin_logged_in && !$is_guest_allowed_subpage) {
                $cur_qs = function_exists('todo_server_string') ? todo_server_string('QUERY_STRING', 'user=' . $subpage) : (!empty($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : ('user=' . $subpage));
                $cur_target = function_exists('todo_get_current_url') ? todo_get_current_url() : home_url('/?' . $cur_qs);
                if (function_exists('get_current_language') && function_exists('get_language_url')) {
                    $lang = get_current_language();
                    if (!empty($lang)) {
                        $cur_target = get_language_url($lang, $cur_target);
                    }
                }
                redirect(todo_login_url($cur_target));
                exit;
            }

            // 验证子页面是否存在
            $allowed_subpages = array(
                'index',
                'orders',
                'order-detail',
                'order-review',
                'order-success',
                'favorites',
                'addresses',
                'profile',
                'history',
                'points',
                'balance',
                'wallet',
                'security',
                'coupons'
            );

            if (!in_array($subpage, $allowed_subpages)) {
                $subpage = 'index';
            }

            // 设置当前页面
            set_current_page('user-' . $subpage);
            break;

        case 'checkout':
            // 结算页面
            set_current_page('checkout');
            break;

        case 'cart':
            // 购物车页面
            set_current_page('cart');
            break;

        case 'order-submit':
            // 订单提交页面
            $order_id = (int) get_query_var('order_id', 0);
            if ($order_id) {
                set_current_page('order-submit');
            } else {
                redirect(home_url());
                exit;
            }
            break;

        case 'payment':
            // 支付页面（支持商品订单 order_id 或 充值订单 recharge_id）
            $order_id = (int) get_query_var('order_id', 0);
            $recharge_id = (int) get_query_var('recharge_id', 0);
            if ($order_id || $recharge_id) {
                set_current_page('payment');
            } else {
                redirect(home_url());
                exit;
            }
            break;

        case 'archive':
            // 日期归档页：设置归档上下文供主题模板使用
            $GLOBALS['archive_year']  = (int) get_query_var('year', 0);
            $GLOBALS['archive_month'] = (int) get_query_var('monthnum', 0);
            $GLOBALS['archive_day']   = (int) get_query_var('day', 0);
            set_current_page('archive');
            break;

        case '404':
            set_current_page('404');
            break;

        case 'unsubscribe':
            set_current_page_type('unsubscribe');
            break;

        case 'api':
            // API接口处理
            $api_path = (string) get_query_var('path', '');
            if (strpos($api_path, 'payment/') === 0) {
                // 支付相关API
                define('TODO_API_REQUEST', true);
                include_once INCLUDES_DIR . '/api/payment-notify.php';
                exit;
            } else {
                // 其他API接口
                if (!defined('TODO_API_REQUEST')) {
                    define('TODO_API_REQUEST', true);
                }
                // 为 api/index.php 提供正确的 PATH_INFO
                $_SERVER['PATH_INFO'] = '/' . ltrim($api_path, '/');
                require_once ABSPATH . 'api/index.php';
                exit;
            }

        default:
            // 检查是否为用户中心路径（支持 /user、/users 以及其子路径）
            if (
                !empty($request_path) && (
                    $request_path === 'user' || $request_path === 'users' ||
                    strpos($request_path, 'user/') === 0 || strpos($request_path, 'users/') === 0
                )
            ) {
                // 用户中心路由处理
                if (!class_exists('Todo_Session_Manager')) {
                    // 如果类不存在，可能是常量未定义，尝试定义后再检查
                    if (!defined('TODO_LOADED')) {
                        define('TODO_LOADED', true);
                    }
                    // 再次检查类是否存在（Composer 会自动加载）
                    if (!class_exists('Todo_Session_Manager')) {
                        throw new Exception('Todo_Session_Manager 类无法加载，请检查 Composer 自动加载配置');
                    }
                }
                Todo_Session_Manager::init();
                $frontend_logged_in = Todo_Session_Manager::is_frontend_logged_in();

                $path_parts = explode('/', trim($request_path, '/'));
                // 兼容 user 或 users 前缀
                $subpage = isset($path_parts[1]) ? $path_parts[1] : (string) get_query_var('subpage', 'index');
                $is_guest_allowed_subpage = in_array($subpage, array('orders', 'order-detail', 'order-review', 'order-success'), true);

                if (Todo_Session_Manager::is_admin_logged_in() && !$frontend_logged_in && !$is_guest_allowed_subpage) {
                    redirect(todo_get_site_url());
                    exit;
                }

                if (!$frontend_logged_in && !$is_guest_allowed_subpage) {
                    $cur_qs = function_exists('todo_server_string') ? todo_server_string('QUERY_STRING', 'user=' . $subpage) : (!empty($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : ('user=' . $subpage));
                    $cur_target = function_exists('todo_get_current_url') ? todo_get_current_url() : home_url('/?' . $cur_qs);
                    if (function_exists('get_current_language') && function_exists('get_language_url')) {
                        $lang = get_current_language();
                        if (!empty($lang)) {
                            $cur_target = get_language_url($lang, $cur_target);
                        }
                    }
                    redirect(todo_login_url($cur_target));
                    exit;
                }

                // 验证子页面
                $allowed_subpages = array(
                    'index',
                    'orders',
                    'order-detail',
                    'order-review',
                    'order-success',
                    'favorites',
                    'addresses',
                    'profile',
                    'history',
                    'points',
                    'balance',
                    'security',
                    'coupons'
                );

                if (!in_array($subpage, $allowed_subpages)) {
                    $subpage = 'index';
                }

                todo_sync_query_context(array('page' => 'user', 'subpage' => $subpage));
                set_current_page('user-' . $subpage);
            }
            // 检查是否为购物相关路径
            elseif (!empty($request_path)) {
                $path_parts = explode('/', trim($request_path, '/'));
                $main_path = $path_parts[0];

                switch ($main_path) {
                    case 'checkout':
                        todo_sync_query_context(array('page' => 'checkout'));
                        set_current_page('checkout');
                        break;

                    case 'cart':
                        todo_sync_query_context(array('page' => 'cart'));
                        set_current_page('cart');
                        break;

                    case 'payment':
                        todo_sync_query_context(array('page' => 'payment'));
                        set_current_page('payment');
                        break;

                    default:
                        // 默认显示首页
                        set_current_page('home');
                        break;
                }
            } else {
                // 默认显示首页
                set_current_page('home');
            }
            break;
    }

    // 执行请求后钩子
    do_action('post_request');
}

/**
 * 加载主题
 *
 * 加载当前激活的主题
 */
function cms_load_theme()
{
    // 请求级静态缓存：同一请求内多次调用复用结果
    // 注意：不再使用跨请求持久化缓存，因为主题可能因 language_theme_map
    // 等动态配置而变化，持久化缓存会导致配置更新后主题不生效。
    static $cached_theme_name = null;
    static $cached_theme_path = null;

    if ($cached_theme_name === null) {
        $cached_theme_name = get_theme_name();
        $cached_theme_path = ABSPATH . THEMES_DIR . '/' . $cached_theme_name;
    }

    $active_theme = $cached_theme_name;
    $theme_path = $cached_theme_path;

    // 性能优化：使用静态缓存避免重复的文件存在性检查
    // 注意：静态缓存（请求级）即使在动态模式下也保留，因为同一请求中文件不会改变
    static $theme_exists_cache = [];
    if (!isset($theme_exists_cache[$theme_path])) {
        $theme_exists_cache[$theme_path] = file_exists($theme_path);
    }

    // 检查主题是否存在
    if (!$theme_exists_cache[$theme_path]) {
        if (function_exists('todo_log')) {
            todo_log('theme_missing', '激活的主题不存在: ' . $active_theme);
        }
        header((function_exists('todo_server_string') ? todo_server_string('SERVER_PROTOCOL', 'HTTP/1.1') : ($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1')) . ' 500 Internal Server Error');
        echo 'Theme not found: ' . htmlspecialchars($active_theme, ENT_QUOTES, 'UTF-8');
        exit;
    }

    // 性能优化：缓存主题函数文件加载状态（避免重复加载）
    static $theme_functions_loaded = [];
    if (!isset($theme_functions_loaded[$active_theme])) {
        $functions_file = $theme_path . '/functions.php';

        // 使用静态缓存避免重复的文件存在性检查
        static $functions_exists_cache = [];
        if (!isset($functions_exists_cache[$functions_file])) {
            $functions_exists_cache[$functions_file] = file_exists($functions_file);
        }

        if ($functions_exists_cache[$functions_file]) {
            require_once $functions_file;
        }
        $theme_functions_loaded[$active_theme] = true;
    }

    // 性能优化：在加载模板前刷新数据库查询合并队列
    if (class_exists('Todo_DB_Query_Interceptor')) {
        Todo_DB_Query_Interceptor::flush();
    }

    // 加载模板
    load_template();
}

if (!function_exists('todo_add_notice')) {
    function todo_add_notice($message, $type = 'info')
    {
        if (php_sapi_name() === 'cli') {
            return;
        }
        if (!session_id()) {
            @session_start();
        }
        if (!isset($_SESSION['todo_notices'])) {
            $_SESSION['todo_notices'] = array();
        }
        $_SESSION['todo_notices'][] = array('message' => (string) $message, 'type' => (string) $type, 'time' => time());
    }
}

function todo_get_post_read_permission($post_id)
{
    global $tododb;
    $val = $tododb->get_var("SELECT read_permission FROM {$tododb->prefix}posts WHERE ID = ?", [$post_id]);
    if (!$val) {
        $val = 'public';
    }
    return $val;
}

if (!defined('TODO_LICENSE_SERVER_PUBLIC_KEY')) {
    define('TODO_LICENSE_SERVER_PUBLIC_KEY', '');
}
if (!defined('TODO_LICENSE_ENDPOINT')) {
    define('TODO_LICENSE_ENDPOINT', '');
}

/**
 * 加载模板
 *
 * 根据当前页面加载相应的模板文件
 */
// 文件存在性缓存（请求级别）
static $_file_exists_cache = [];

function load_template()
{
    global $_file_exists_cache;

    // 获取当前主题（支持预览过滤器）
    $active_theme = get_theme_name();
    // 使用 get_template_directory() 支持PC/移动端分离
    $theme_path = get_template_directory();

    // 获取当前页面类型
    $page_type = get_current_page_type();

    // 用户中心页面登录守卫（购物车、结算页、收银台与订单详情页除外）
    if (strpos($page_type, 'user-') === 0 && !in_array($page_type, array('user-cart', 'user-checkout', 'user-payment', 'user-orders', 'user-order-detail', 'user-order-success', 'user-order-review'), true)) {
        if (!is_user_logged_in()) {
            $current_url = function_exists('todo_get_current_url') ? todo_get_current_url() : home_url('/?user=' . substr($page_type, 5));
            if (function_exists('get_current_language') && function_exists('get_language_url')) {
                $lang = get_current_language();
                if (!empty($lang)) {
                    $current_url = get_language_url($lang, $current_url);
                }
            }
            $redirect_url = todo_login_url($current_url);
            header('Location: ' . $redirect_url);
            exit;
        }
    }

    // 执行模板加载前钩子
    do_action('pre_template_load', $page_type);

    // 根据页面类型加载不同的模板
    switch ($page_type) {
        case 'single':
            // 单篇文章 - 优先使用文章自选视图模版，其次根据数据模型选择对应的模版
            // 更健壮地获取文章ID：优先当前文章对象，其次 URL 参数 p/id/post
            $post_id = 0;
            $post_obj = function_exists('get_current_post') ? get_current_post() : null;
            if ($post_obj && isset($post_obj->ID)) {
                $post_id = intval($post_obj->ID);
            } else {
                $ctx_id = todo_get_query_context_value('id', null);
                if ($ctx_id !== null && is_numeric($ctx_id) && (int) $ctx_id > 0) {
                    $post_id = intval($ctx_id);
                } else {
                    $p_val = todo_get_query_context_value('p', todo_get_int('p'));
                    if ($p_val > 0) {
                        $post_id = intval($p_val);
                    } else {
                        $id_val = todo_get_int('id');
                        if ($id_val > 0) {
                            $post_id = $id_val;
                        } else {
                            $post_val = todo_get_query_context_value('post', todo_get_int('post'));
                            if ($post_val > 0) {
                                $post_id = intval($post_val);
                            }
                        }
                    }
                }
            }
            if (!$post_obj && !$post_id) {
                $slug_param = (string) todo_get_query_context_value('post_name', todo_get_query_context_value('slug', todo_get_string('post_name', todo_get_string('slug'))));
                if ($slug_param !== '') {
                    $slug_clean = preg_replace('#\.(html?|php)$#i', '', $slug_param);
                    global $tododb;
                    if (isset($tododb)) {
                        $pid = $tododb->get_var($tododb->prepare("SELECT ID FROM " . TABLE_PREFIX . "posts WHERE post_name = %s OR custom_url = %s LIMIT 1", $slug_clean, $slug_clean));
                        if ($pid) {
                            $post_id = (int)$pid;
                        }
                    }
                }
            }

            // 如果未获取到文章对象但拿到了ID，则补充查询文章对象
            if (!$post_obj && $post_id && function_exists('get_post')) {
                $post_obj = get_post($post_id);
            }
            if ($post_obj) {
                $GLOBALS['current_post'] = $post_obj;
                $GLOBALS['post'] = $post_obj;
            }

            // 1) 文章自选视图模版（后台 post-edit.php 下拉框保存到 posts.view_template）
            $selected_template = '';
            if ($post_obj && isset($post_obj->view_template) && $post_obj->view_template !== '') {
                $selected_template = trim($post_obj->view_template);
            } elseif ($post_id && function_exists('get_post_meta')) {
                $selected_template = trim(get_post_meta($post_id, 'view_template', true));
            }

            if ($selected_template !== '') {
                $selected_found = false;
                $has_ext = (bool) preg_match('/\.(html?|php)$/i', $selected_template);
                $candidates = $has_ext ? array($selected_template) : array(
                    $selected_template . '.html'
                );
                foreach ($candidates as $candidate) {
                    $full_path = $theme_path . '/' . $candidate;
                    if (!isset($_file_exists_cache[$full_path])) {
                        $_file_exists_cache[$full_path] = file_exists($full_path);
                    }
                    if ($_file_exists_cache[$full_path]) {
                        $template = $candidate;
                        $selected_found = true;
                        break;
                    }
                }
                if ($selected_found) {
                    break;
                }
            }

            // 2) 根据数据模型类型的默认视图模版
            $model_type = null;
            if ($post_id) {
                if (class_exists('Todo_Post_Fields_Manager')) {
                    $fields_manager = Todo_Post_Fields_Manager::get_instance();
                    $model_type = $fields_manager->get_post_model_type($post_id);
                }
                if ($model_type) {
                    $candidate = "view_{$model_type}.html";
                    $full_path = $theme_path . '/' . $candidate;
                    if (!isset($_file_exists_cache[$full_path])) {
                        $_file_exists_cache[$full_path] = file_exists($full_path);
                    }
                    if ($_file_exists_cache[$full_path]) {
                        $template = $candidate;
                        break;
                    }
                }
            }

            // 2b) 自定义模型回退：从 models 表读取 view_template（当 view_{model_type}.html 不存在时）
            // 自定义模型的 model_key != model_type，所以上一步 view_{model_key}.html 找不到文件
            // 此时需要查 models 表中的 view_template 字段
            if (empty($template) && $model_type) {
                global $tododb;
                $view_template = $tododb->get_var(
                    "SELECT view_template FROM " . TABLE_PREFIX . "models WHERE model_key = ?",
                    [$model_type]
                );
                if ($view_template && $view_template !== '') {
                    $candidate = basename($view_template);
                    $full_path = $theme_path . '/' . $candidate;
                    if (!isset($_file_exists_cache[$full_path])) {
                        $_file_exists_cache[$full_path] = file_exists($full_path);
                    }
                    if ($_file_exists_cache[$full_path]) {
                        $template = $candidate;
                    }
                }
            }

            // 3) 如果未找到对应模板，记录错误并返回404
            if (empty($template)) {
                error_log('[TodoCMS] 警告：未找到文章ID ' . $post_id . ' 对应的视图模板（数据模型：' . ($model_type ? $model_type : '未知') . '，主题路径：' . $theme_path . '）');
                header('HTTP/1.0 404 Not Found');
                $template = '404.html';
            }
            break;

        case 'page':
            // 单个页面
            $page_slug = get_current_page_slug();
            $page_template_path = $theme_path . '/page-' . $page_slug . '.php';
            // 使用缓存避免重复的文件系统检查
            if (!isset($_file_exists_cache[$page_template_path])) {
                $_file_exists_cache[$page_template_path] = file_exists($page_template_path);
            }
            if ($_file_exists_cache[$page_template_path]) {
                $template = 'page-' . $page_slug . '.php';
            } else {
                $template = 'page.php';
            }
            break;

        case 'category':
            $cat_id = get_current_category_id();
            $cat_slug = get_category_slug();
            $template = '';
            // 1) 分类ID存在：按栏目绑定模型选择列表模板
            if ($cat_id && class_exists('Todo_Model_Manager')) {
                $model_key = Todo_Model_Manager::get_category_model($cat_id);
                $model_class = Todo_Model_Manager::get_model_class($model_key);
                if ($model_class && property_exists($model_class, 'list_template')) {
                    $list_template = $model_class::$list_template;
                    $candidates = array($list_template);
                    // 扩展名规范化：若无扩展名则追加 .html
                    if (!preg_match('/\.(html?|php)$/i', $list_template)) {
                        $candidates[] = $list_template . '.html';
                    }
                    foreach ($candidates as $cand) {
                        if ($cand) {
                            $full_path = $theme_path . '/' . $cand;
                            if (!isset($_file_exists_cache[$full_path])) {
                                $_file_exists_cache[$full_path] = file_exists($full_path);
                            }
                            if ($_file_exists_cache[$full_path]) {
                                $template = $cand;
                                break;
                            }
                        }
                    }
                }
            }
            // 1b) 自定义模型回退：从 models 表读取 list_template（当模型类没有静态 list_template 属性时）
            // 自定义模型的 CMS_Post_Type_Custom 类没有 $list_template 静态属性
            // 需要直接从 models 表查询 list_template 字段
            if ($template === '' && $cat_id && class_exists('Todo_Model_Manager')) {
                $cat_model_key = Todo_Model_Manager::get_category_model($cat_id);
                if ($cat_model_key) {
                    global $tododb;
                    $list_template = $tododb->get_var(
                        "SELECT list_template FROM " . TABLE_PREFIX . "models WHERE model_key = ?",
                        [$cat_model_key]
                    );
                    if ($list_template && $list_template !== '') {
                        $candidates = array($list_template);
                        if (!preg_match('/\.(html?|php)$/i', $list_template)) {
                            $candidates[] = $list_template . '.html';
                        }
                        foreach ($candidates as $cand) {
                            if ($cand) {
                                $full_path = $theme_path . '/' . $cand;
                                if (!isset($_file_exists_cache[$full_path])) {
                                    $_file_exists_cache[$full_path] = file_exists($full_path);
                                }
                                if ($_file_exists_cache[$full_path]) {
                                    $template = $cand;
                                    break;
                                }
                            }
                        }
                    }
                }
            }

            // 2) 模型列表：当没有分类ID但指定了模型，直接按模型默认列表模板
            if ($template === '' && class_exists('Todo_Model_Manager')) {
                $req_model = sanitize_key((string) todo_get_query_context_value('model', todo_get_string('model')));
                if ($req_model !== '') {
                    $model_class = Todo_Model_Manager::get_model_class($req_model);
                    if ($model_class && property_exists($model_class, 'list_template')) {
                        $list_template = $model_class::$list_template;
                        $candidates = array($list_template);
                        if (!preg_match('/\.(html?|php)$/i', $list_template)) {
                            $candidates[] = $list_template . '.html';
                        }
                        foreach ($candidates as $cand) {
                            if ($cand && file_exists($theme_path . '/' . $cand)) {
                                $template = $cand;
                                break;
                            }
                        }
                    }
                }
            }
            // 2b) 自定义模型回退（?model= 模式）：从 models 表读取 list_template
            if ($template === '' && class_exists('Todo_Model_Manager')) {
                $req_model2 = sanitize_key((string) todo_get_query_context_value('model', todo_get_string('model')));
                if ($req_model2 !== '') {
                    global $tododb;
                    $list_template = $tododb->get_var(
                        "SELECT list_template FROM " . TABLE_PREFIX . "models WHERE model_key = ?",
                        [$req_model2]
                    );
                    if ($list_template && $list_template !== '') {
                        $candidates = array($list_template);
                        if (!preg_match('/\.(html?|php)$/i', $list_template)) {
                            $candidates[] = $list_template . '.html';
                        }
                        foreach ($candidates as $cand) {
                            if ($cand && file_exists($theme_path . '/' . $cand)) {
                                $template = $cand;
                                break;
                            }
                        }
                    }
                }
            }
            // 3) 如果未找到对应模板，记录错误并返回404
            if (empty($template)) {
                error_log('[TodoCMS] 警告：未找到分类 ' . ($cat_id ? 'ID: ' . $cat_id : '') . ($cat_slug ? ' Slug: ' . $cat_slug : '') . ' 对应的列表模板（主题路径：' . $theme_path . '）');
                header('HTTP/1.0 404 Not Found');
                $template = '404.html';
            }
            break;

        case 'tag':
            // 标签文章列表页
            $template = 'lists_tags.html';
            break;
        case 'tags':
            // 标签首页（标签云/聚合），回退到列表页
            $tag_html = $theme_path . '/tag.html';
            if (!isset($_file_exists_cache[$tag_html])) {
                $_file_exists_cache[$tag_html] = file_exists($tag_html);
            }
            if ($_file_exists_cache[$tag_html]) {
                $template = 'tag.html';
            } else {
                $template = 'lists_tags.html';
            }
            break;

        case 'author':
            // 作者页面
            $template = 'author.html';
            break;

        case 'search':
            // 搜索结果页
            $template = 'lists_search.html';
            break;

        case 'rss':
            // RSS 订阅
            $GLOBALS['TODO_IS_RSS'] = true;
            header('Content-Type: application/rss+xml; charset=UTF-8');
            $template = 'rss.html';
            break;

        case 'archive':
            // 归档页面：无具体年月时用 archive.html（聚合页），有具体年月用 lists_archives.html（列表页）
            $archive_year_val = (int) todo_get_query_context_value('archive_year', todo_get_int('archive_year'));
            $year_val = (int) todo_get_query_context_value('year', todo_get_int('year'));
            $has_date = ($archive_year_val > 0 || $year_val > 0);

            if ($has_date) {
                $template = 'lists_archives.html';
            } else {
                $template = 'archive.html';
            }
            break;

        case 'login':
            // 登录页面
            $template = 'users/login.htm';
            break;

        case 'register':
            // 注册页面：未开启允许任何人注册时，拦截并重定向至登录页
            if (function_exists('get_option') && !get_option('users_can_register', false)) {
                $login_url = function_exists('todo_login_url') ? todo_login_url() : (function_exists('get_login_url') ? get_login_url() : site_url('?user=login'));
                header('Location: ' . $login_url);
                exit;
            }
            $template = 'users/register.htm';
            break;

        case 'forgot_password':
            // 找回密码页面
            $template = 'users/forgot-password.htm';
            break;

        case 'profile':
            // 用户资料页面
            $template = 'users/profile.htm';
            break;

        case 'cart':
            // 购物车页面
            $template = 'users/cart.htm';
            break;

        case 'checkout':
            // 结算页面
            $template = 'users/checkout.htm';
            break;

        case 'payment':
            // 支付页面
            $template = 'users/payment.htm';
            break;

        case 'user':
            // 用户中心主页面/子页面
            $subpage = sanitize_text_field((string) todo_get_query_context_value('subpage', todo_get_string('subpage', 'index')));
            if ($subpage === '') {
                $subpage = 'index';
            }
            $user_template_map = [
                'index' => 'index',
                'orders' => 'orders',
                'order-detail' => 'order-detail',
                'order-review' => 'order-review',
                'order-success' => 'order-success',
                'favorites' => 'favorites',
                'addresses' => 'addresses',
                'profile' => 'profile',
                'history' => 'history',
                'points' => 'points',
                'balance' => 'finance',
                'security' => 'settings',
                'cart' => 'cart',
                'finance' => 'finance',
                'coupons' => 'coupons',
                'wallet' => 'wallet'
            ];
            $tpl_name = isset($user_template_map[$subpage]) ? $user_template_map[$subpage] : $subpage;
            if (file_exists($theme_path . '/users/' . $tpl_name . '.htm')) {
                $template = 'users/' . $tpl_name . '.htm';
            } elseif (file_exists($theme_path . '/users/' . $tpl_name . '.html')) {
                $template = 'users/' . $tpl_name . '.html';
            } else {
                $template = 'users/users_welcome.htm';
            }
            break;

        case 'unsubscribe':
            if (file_exists($theme_path . '/unsubscribe.html')) {
                $template = 'unsubscribe.html';
            }
            break;

        case '404':
            // 404页面
            $template = '404.html';
            break;

        default:
            // 检查是否为用户中心页面
            if (strpos($page_type, 'user-') === 0) {
                $subpage = substr($page_type, 5); // 移除 'user-' 前缀

                // 用户中心模板映射
                $user_template_map = [
                    'index' => 'index',
                    'orders' => 'orders',
                    'order-detail' => 'order-detail',
                    'order-review' => 'order-review',
                    'order-success' => 'order-success',
                    'favorites' => 'favorites',
                    'addresses' => 'addresses',
                    'profile' => 'profile',
                    'history' => 'history',
                    'points' => 'points',
                    'balance' => 'finance',
                    'security' => 'settings',
                    'cart' => 'cart',
                    'finance' => 'finance',
                    'coupons' => 'coupons',
                    'wallet' => 'wallet'
                ];

                // 获取模板名称
                $template_name = isset($user_template_map[$subpage]) ? $user_template_map[$subpage] : $subpage;
                if (file_exists($theme_path . '/users/' . $template_name . '.htm')) {
                    $template = 'users/' . $template_name . '.htm';
                } elseif (file_exists($theme_path . '/users/' . $template_name . '.html')) {
                    $template = 'users/' . $template_name . '.html';
                } else {
                    $template = 'users/profile.htm';
                }
            } else {
                // 默认使用首页模板
                $template = 'index.html';
            }
            break;
    }

    // 构建模板路径，若模板不存在则回退到 .php
    $template_path = $theme_path . '/' . $template;
    if (!file_exists($template_path)) {
        $php_template = str_replace('.html', '.php', $template);
        if ($php_template === $template) {
            $php_template = str_replace('.htm', '.php', $template);
        }
        $php_path = $theme_path . '/' . $php_template;
        $template = file_exists($php_path) ? $php_template : 'index.php';
    }

    // 执行模板加载钩子
    do_action('template_load', $template);

    // 构建正确的模板路径
    $template_path = $theme_path . '/' . $template;

    // 检查是否已经加载过此模板，防止循环引用
    static $loaded_templates = array();
    if (in_array($template_path, $loaded_templates)) {
        error_log('警告：检测到模板循环引用: ' . $template_path);
        return;
    }

    // 记录已加载的模板
    $loaded_templates[] = $template_path;

    // 加载模板文件
    if (file_exists($template_path)) {
        // 检查是否为HTML/HTM模板文件
        if (in_array(strtolower(pathinfo($template_path, PATHINFO_EXTENSION)), ['html', 'htm'], true)) {
            // 使用模板解析器处理HTML/HTM模板
            if (function_exists('parse_html_template')) {
                error_log('[TodoCMS] 使用模板解析器处理HTML模板: ' . $template_path . ' (page_type=' . $page_type . ')');

                // 准备模板数据
                $template_data = [];
                $cur_locale = function_exists('current_locale') ? current_locale() : (function_exists('todo_get_current_language') ? todo_get_current_language() : 'zh-CN');
                $template_data['current_locale'] = $cur_locale;
                $template_data['current_language'] = $cur_locale;
                $template_data['locale'] = $cur_locale;
                $template_data['lang'] = strtolower(explode('-', $cur_locale)[0]);

                // 自动载入并规范化全局变量 (global_vars)，使其在任意页面场景下均可通过全名、global_前缀及 _images / _display 扩展名访问
                $global_vars = function_exists('get_option') ? get_option('global_vars', []) : [];
                if (is_array($global_vars) && !empty($global_vars)) {
                    if (!class_exists('CMS_HTML_Template_Tags')) {
                        $tags_file = ABSPATH . 'core/classes/class-html-template-tags.php';
                        if (file_exists($tags_file)) {
                            require_once $tags_file;
                        }
                    }
                    if (class_exists('CMS_HTML_Template_Tags')) {
                        foreach ($global_vars as $gk => $gv) {
                            CMS_HTML_Template_Tags::enrich_field_variants($template_data, $gk, $gv, 'global_');
                        }
                    }
                }

                // 获取当前页面类型和相关数据
                $page_type = get_current_page_type();
                $template_data['page_type'] = $page_type;

                // 根据页面类型添加相应数据
                if ($page_type === 'single') {
                    global $post, $current_post;
                    $target_post = $current_post ?: ($post ?: (function_exists('get_current_post') ? get_current_post() : null));
                    if (!$target_post) {
                        $req_pid = (int) todo_get_query_context_value('id', 0);
                        if ($req_pid <= 0) {
                            $req_pid = (int) todo_get_query_context_value('p', todo_get_int('p'));
                        }
                        if ($req_pid <= 0) {
                            $req_pid = todo_get_int('id');
                        }
                        if ($req_pid <= 0) {
                            $req_pid = (int) todo_get_query_context_value('post', todo_get_int('post'));
                        }
                        if ($req_pid > 0 && function_exists('get_post')) {
                            $target_post = get_post($req_pid);
                        }
                    }
                    if ($target_post) {
                        $post = $target_post;
                        $current_post = $target_post;
                        $template_data['post'] = $target_post;
                        $pid = intval($target_post->ID);
                        $template_data['post_id'] = $pid;

                        // 绑定文章/商品的基本标量属性到 $template_data 根节点
                        foreach ($target_post as $pk => $pv) {
                            if (is_scalar($pv)) {
                                if (!isset($template_data[$pk])) {
                                    $template_data[$pk] = $pv;
                                }
                                if (!isset($template_data['post_' . $pk])) {
                                    $template_data['post_' . $pk] = $pv;
                                }
                            }
                        }

                        // 加载当前文章/商品的所有自定义字段与数据模型字段
                        $fields = [];
                        if (class_exists('Todo_Post_Fields_Manager')) {
                            $fields_mgr = Todo_Post_Fields_Manager::get_instance();
                            $fields = $fields_mgr->get_post_fields_values($pid);
                        }
                        $fields = is_array($fields) ? $fields : [];

                        // 补全 post_custom_fields 表中该 post_id 的全量自定义字段
                        global $tododb;
                        if (isset($tododb)) {
                            $cf_rows = $tododb->get_results($tododb->prepare("SELECT field_key, field_value FROM " . TABLE_PREFIX . "post_custom_fields WHERE post_id = %d", $pid), ARRAY_A);
                            if (is_array($cf_rows)) {
                                foreach ($cf_rows as $cfr) {
                                    $fk = $cfr['field_key'] ?? '';
                                    $fv = maybe_unserialize($cfr['field_value'] ?? '');
                                    if ($fk !== '' && strpos($fk, '_todo_') !== 0) {
                                        $clean_fk = strpos($fk, 'model_field_') === 0 ? str_replace('model_field_', '', $fk) : $fk;
                                        if (!isset($fields[$clean_fk]) || $fields[$clean_fk] === '' || $fields[$clean_fk] === null) {
                                            $fields[$clean_fk] = $fv;
                                        }
                                        if (!isset($fields[$fk]) || $fields[$fk] === '' || $fields[$fk] === null) {
                                            $fields[$fk] = $fv;
                                        }
                                    }
                                }
                            }
                        }

                        // 尝试补充数据模型专属固定字段表（如 model_fields_product）
                        $model_type = null;
                        if (class_exists('Todo_Post_Fields_Manager')) {
                            $model_type = Todo_Post_Fields_Manager::get_instance()->get_post_model_type($pid);
                        }
                        if ($model_type && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $model_type)) {
                            if (isset($tododb)) {
                                $m_table = TABLE_PREFIX . 'model_fields_' . $model_type;
                                if ($tododb->table_exists($m_table)) {
                                $m_row = $tododb->get_row($tododb->prepare("SELECT * FROM {$m_table} WHERE post_id = %d LIMIT 1", $pid));
                                if (is_object($m_row)) {
                                    $m_row = (array) $m_row;
                                }
                                if (is_array($m_row)) {
                                    foreach ($m_row as $mk => $mv) {
                                        if ($mk !== 'field_id' && $mk !== 'post_id' && $mk !== 'created_at' && $mk !== 'updated_at') {
                                            if (!isset($fields[$mk]) || $fields[$mk] === '' || $fields[$mk] === null) {
                                                $fields[$mk] = $mv;
                                            }
                                        }
                                    }
                                }
                                }
                            }
                        }

                        // 补充 get_post_meta 的字段
                        $all_meta = function_exists('get_post_meta') ? get_post_meta($pid) : [];
                        if (is_array($all_meta)) {
                            foreach ($all_meta as $mk => $mv) {
                                $val = $mv;
                                if (is_array($val) && count($val) === 1 && is_scalar($val[0])) {
                                    $val = $val[0];
                                }
                                if (!isset($fields[$mk]) || $fields[$mk] === '' || $fields[$mk] === null) {
                                    $fields[$mk] = $val;
                                }
                            }
                        }

                        // 通过统一调能函数向 $template_data 递归补充所有字段变体（绑定原始名、post_前缀、_images、_count、_first、_display 等）
                        if (class_exists('CMS_Html_Template_Tags')) {
                            foreach ($fields as $fk => $fv) {
                                CMS_Html_Template_Tags::enrich_field_variants($template_data, $fk, $fv, 'post_');
                            }
                        }
                    }
                } elseif ($page_type === 'page') {
                    global $page, $current_page;
                    if ($current_page) {
                        $template_data['page'] = $current_page;
                        $template_data['page_id'] = is_object($current_page) ? $current_page->ID : $current_page;
                    }
                } elseif ($page_type === 'home') {
                    // 首页：文章列表和分页由模板编译器 {{todo:loop posts}} 处理
                    // compileLoop 会自动读取 $_GET['paged'] 并注入 pagination 元数据
                } elseif ($page_type === 'category') {
                    $cat_id = get_current_category_id();
                    if ($cat_id) {
                        // 分类基础信息
                        $template_data['category_id'] = intval($cat_id);
                        $category = null;
                        if (class_exists('Todo_Category_Manager')) {
                            $category = Todo_Category_Manager::get_category($cat_id);
                        }
                        if (is_array($category)) {
                            $template_data['category_name'] = isset($category['name']) ? (string) $category['name'] : '';
                            $template_data['category_slug'] = isset($category['slug']) ? (string) $category['slug'] : '';
                            $template_data['category_description'] = isset($category['description']) ? (string) $category['description'] : '';
                        } elseif (is_object($category)) {
                            $template_data['category_name'] = isset($category->name) ? (string) $category->name : '';
                            $template_data['category_slug'] = isset($category->slug) ? (string) $category->slug : '';
                            $template_data['category_description'] = isset($category->description) ? (string) $category->description : '';
                        }
                        $template_data['category_url'] = todo_get_category_link($cat_id);

                        // 分类参数/自定义信息
                        $term_meta = get_term_meta($cat_id);
                        if (is_array($term_meta) && !empty($term_meta)) {
                            if (class_exists('CMS_Html_Template_Tags')) {
                                foreach ($term_meta as $key => $value) {
                                    CMS_Html_Template_Tags::enrich_field_variants($template_data, $key, $value, 'category_');
                                    CMS_Html_Template_Tags::enrich_field_variants($template_data, $key, $value, 'cat_');
                                }
                            }
                        }

                        // 获取顶级分类（向上追溯到 depth=0 的根祖先）
                        $top_category_id = 0;
                        $top_category_name = '';
                        $top_category_slug = '';
                        $ancestor_id = $cat_id;
                        $max_depth = 10; // 防止无限循环
                        $depth_counter = 0;
                        while ($ancestor_id > 0 && $depth_counter < $max_depth) {
                            $ancestor_cat = Todo_Category_Manager::get_category($ancestor_id);
                            $ancestor_parent = 0;
                            if (is_array($ancestor_cat)) {
                                $ancestor_parent = isset($ancestor_cat['parent_id']) ? intval($ancestor_cat['parent_id']) : (isset($ancestor_cat['parent']) ? intval($ancestor_cat['parent']) : 0);
                            } elseif (is_object($ancestor_cat)) {
                                $ancestor_parent = isset($ancestor_cat->parent_id) ? intval($ancestor_cat->parent_id) : (isset($ancestor_cat->parent) ? intval($ancestor_cat->parent) : 0);
                            }
                            if ($ancestor_parent === 0) {
                                // 当前 ancestor_cat 就是顶级分类
                                $top_category_id = $ancestor_id;
                                if (is_array($ancestor_cat)) {
                                    $top_category_name = isset($ancestor_cat['name']) ? (string) $ancestor_cat['name'] : '';
                                    $top_category_slug = isset($ancestor_cat['slug']) ? (string) $ancestor_cat['slug'] : '';
                                } elseif (is_object($ancestor_cat)) {
                                    $top_category_name = isset($ancestor_cat->name) ? (string) $ancestor_cat->name : '';
                                    $top_category_slug = isset($ancestor_cat->slug) ? (string) $ancestor_cat->slug : '';
                                }
                                break;
                            }
                            $ancestor_id = $ancestor_parent;
                            $depth_counter++;
                        }
                        // 如果没有 parent_id（本身就是顶级），直接用当前分类
                        if ($top_category_name === '' && $depth_counter === 0) {
                            $top_category_id = $cat_id;
                            $top_category_name = $template_data['category_name'];
                            $top_category_slug = $template_data['category_slug'];
                        }
                        $template_data['top_category_id'] = $top_category_id;
                        $template_data['top_category_name'] = $top_category_name;
                        $template_data['top_category_slug'] = $top_category_slug;
                        $template_data['top_category_url'] = todo_get_category_link($top_category_id);

                        // 子分类列表
                        $subcategories = array();
                        $is_showing_siblings = false;
                        if (class_exists('Todo_Category_Manager')) {
                            $is_cat_hidden_check = function ($check_cat_id) {
                                if ($check_cat_id <= 0 || !function_exists('get_term_meta')) {
                                    return false;
                                }
                                $h = get_term_meta($check_cat_id, 'is_hidden', true);
                                if (!empty($h) && $h != '0' && $h !== 'false') {
                                    return true;
                                }
                                $ch = get_term_meta($check_cat_id, 'cat_is_hidden', true);
                                if (!empty($ch) && $ch != '0' && $ch !== 'false') {
                                    return true;
                                }
                                $now_ts = function_exists('current_time') ? current_time('timestamp') : time();
                                $start = get_term_meta($check_cat_id, 'hidden_start', true);
                                $end = get_term_meta($check_cat_id, 'hidden_end', true);
                                if (!empty($start) && strtotime($start) > $now_ts) {
                                    return true;
                                }
                                if (!empty($end) && strtotime($end) < $now_ts) {
                                    return true;
                                }
                                return false;
                            };

                            $current_site_id = (defined('IS_ADMIN') && IS_ADMIN) ? todo_get_site_context_id(0) : (function_exists('todo_current_site_id') ? (int) todo_current_site_id(true) : 0);
                            $subs = Todo_Category_Manager::get_children($cat_id, false, $current_site_id);
                            if (is_array($subs)) {
                                foreach ($subs as $sub) {
                                    $sub_id = is_array($sub) ? (isset($sub['term_id']) ? intval($sub['term_id']) : (isset($sub['id']) ? intval($sub['id']) : 0)) : (isset($sub->term_id) ? intval($sub->term_id) : 0);
                                    if ($sub_id <= 0 || $is_cat_hidden_check($sub_id)) {
                                        continue;
                                    }
                                    $sub_name = is_array($sub) ? (isset($sub['name']) ? (string) $sub['name'] : '') : (isset($sub->name) ? (string) $sub->name : '');
                                    $sub_count = is_array($sub) ? (isset($sub['count']) ? intval($sub['count']) : 0) : (isset($sub->count) ? intval($sub->count) : 0);
                                    $sub_url = todo_get_category_link($sub_id);
                                    $subcategories[] = array(
                                        'sub_id' => $sub_id,
                                        'sub_name' => $sub_name,
                                        'sub_count' => $sub_count,
                                        'sub_url' => $sub_url,
                                    );
                                }
                            }
                            // 叶子节点（无子分类）：展示同级兄弟分类
                            if (empty($subcategories)) {
                                $parent_id = 0;
                                if (is_array($category)) {
                                    $parent_id = isset($category['parent_id']) ? intval($category['parent_id']) : (isset($category['parent']) ? intval($category['parent']) : 0);
                                } elseif (is_object($category)) {
                                    $parent_id = isset($category->parent_id) ? intval($category->parent_id) : (isset($category->parent) ? intval($category->parent) : 0);
                                }
                                if ($parent_id > 0) {
                                    $siblings = Todo_Category_Manager::get_children($parent_id, false, $current_site_id);
                                    if (is_array($siblings)) {
                                        foreach ($siblings as $sub) {
                                            $sub_id = is_array($sub) ? (isset($sub['term_id']) ? intval($sub['term_id']) : (isset($sub['id']) ? intval($sub['id']) : 0)) : (isset($sub->term_id) ? intval($sub->term_id) : 0);
                                            if ($sub_id <= 0 || $is_cat_hidden_check($sub_id)) {
                                                continue;
                                            }
                                            $sub_name = is_array($sub) ? (isset($sub['name']) ? (string) $sub['name'] : '') : (isset($sub->name) ? (string) $sub->name : '');
                                            $sub_count = is_array($sub) ? (isset($sub['count']) ? intval($sub['count']) : 0) : (isset($sub->count) ? intval($sub->count) : 0);
                                            $sub_url = todo_get_category_link($sub_id);
                                            $subcategories[] = array(
                                                'sub_id' => $sub_id,
                                                'sub_name' => $sub_name,
                                                'sub_count' => $sub_count,
                                                'sub_url' => $sub_url,
                                            );
                                        }
                                    }
                                    $is_showing_siblings = true;
                                    $template_data['parent_category_id'] = $parent_id;
                                    $template_data['parent_category_url'] = todo_get_category_link($parent_id);
                                    // 获取父分类信息
                                    $parent_cat = Todo_Category_Manager::get_category($parent_id);
                                    $parent_name = '';
                                    $parent_slug = '';
                                    if (is_array($parent_cat)) {
                                        $parent_name = isset($parent_cat['name']) ? (string) $parent_cat['name'] : '';
                                        $parent_slug = isset($parent_cat['slug']) ? (string) $parent_cat['slug'] : '';
                                    } elseif (is_object($parent_cat)) {
                                        $parent_name = isset($parent_cat->name) ? (string) $parent_cat->name : '';
                                        $parent_slug = isset($parent_cat->slug) ? (string) $parent_cat->slug : '';
                                    }
                                    $template_data['parent_category_name'] = $parent_name;
                                    $template_data['parent_category_slug'] = $parent_slug;
                                }
                            }
                        }
                        $template_data['subcategories'] = $subcategories;
                        $template_data['is_showing_siblings'] = $is_showing_siblings;
                        $template_data['current_subcategory'] = (int) todo_get_query_context_value('subcat', todo_get_int('subcat'));
                        // 展示同级分类时，高亮当前所在分类
                        if ($is_showing_siblings && $template_data['current_subcategory'] === 0) {
                            $template_data['current_subcategory'] = intval($cat_id);
                        }

                        // 分页数据
                        $per_page = max(1, (int) todo_get_query_context_value('per_page', todo_get_int('per_page', 10)));
                        $current_page = todo_get_paged_context('paged', 1);
                        $template_data['per_page'] = $per_page;
                        $template_data['page'] = $current_page;

                        $total_items = 0;
                        if (isset($GLOBALS['tododb'])) {
                            $db = $GLOBALS['tododb'];
                            $posts_table = $db->prefix . 'posts';
                            $tt_table = $db->prefix . 'term_taxonomy';
                            $tr_table = $db->prefix . 'term_relationships';

                            // 站点可见性过滤
                            $cat_site_id = (defined('IS_ADMIN') && IS_ADMIN) ? todo_get_site_context_id(0) : (function_exists('todo_current_site_id') ? (int) todo_current_site_id(true) : 0);
                            $site_join = '';
                            $site_where = '';
                            if ($cat_site_id > 0 && function_exists('todo_get_post_site_visibility_sql')) {
                                $site_posts_table = $db->prefix . 'site_posts';
                                $site_join = " LEFT JOIN {$site_posts_table} sp_cat ON (p.ID = sp_cat.post_id AND sp_cat.site_id = {$cat_site_id})";
                                $site_where = ' AND ' . todo_get_post_site_visibility_sql('sp_cat', $cat_site_id, 0, 'p.ID');
                            }

                            // 多语言过滤
                            $lang_filter = '';
                            if (!defined('IS_ADMIN') || !IS_ADMIN) {
                                $current_lang = function_exists('current_locale') ? current_locale() : (function_exists('todo_get_current_language') ? todo_get_current_language() : 'zh-CN');
                                $current_lang = str_replace('_', '-', $current_lang);

                                $default_lang = function_exists('get_default_language') ? get_default_language() : 'zh-CN';
                                $default_lang = str_replace('_', '-', $default_lang);

                                $is_main_lang = ($current_lang === $default_lang);

                                if (!$is_main_lang) {
                                    $lang_escaped = $db->esc_like($current_lang);
                                    $lang_filter = " AND p.ID IN (SELECT post_id FROM {$db->prefix}postmeta WHERE meta_key = '_enabled_languages' AND meta_value LIKE '%\"{$lang_escaped}\"%')";
                                }
                            }

                            // 可见性过滤（仅统计 public 文章）
                            $vis_join = "";
                            $vis_where = " AND p.visibility = 'public' AND NOT EXISTS (SELECT 1 FROM {$db->prefix}postmeta m_vis WHERE m_vis.post_id = p.ID AND m_vis.meta_key = '_visibility' AND m_vis.meta_value <> 'public')";

                            // 精确统计子分类中已发布(publish)的文章数，避免 term_taxonomy.count 包含草稿文章
                            if (!empty($subcategories)) {
                                $sub_ids = array();
                                foreach ($subcategories as $sc) {
                                    $sid = isset($sc['sub_id']) ? intval($sc['sub_id']) : 0;
                                    if ($sid > 0) {
                                        $sub_ids[] = $sid;
                                    }
                                }
                                $sub_ids = array_values(array_unique($sub_ids));
                                if (!empty($sub_ids)) {
                                    $sub_in = implode(',', $sub_ids);
                                    $sql_sub_counts = "SELECT tt.term_id, COUNT(DISTINCT p.ID) as cnt FROM {$posts_table} p{$site_join}{$vis_join} JOIN {$tr_table} tr ON tr.object_id = p.ID JOIN {$tt_table} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.term_id IN ({$sub_in}) AND tt.taxonomy = 'category' AND p.post_status = 'publish'{$vis_where}{$lang_filter}{$site_where} GROUP BY tt.term_id";
                                    $sub_cnt_results = $db->get_results($sql_sub_counts);
                                    $sub_counts_map = array();
                                    if (is_array($sub_cnt_results)) {
                                        foreach ($sub_cnt_results as $scr) {
                                            $stid = is_array($scr) ? intval($scr['term_id']) : intval($scr->term_id);
                                            $scnt = is_array($scr) ? intval($scr['cnt']) : intval($scr->cnt);
                                            $sub_counts_map[$stid] = $scnt;
                                        }
                                    }
                                    foreach ($subcategories as &$sc_item) {
                                        $sc_item['sub_count'] = isset($sub_counts_map[$sc_item['sub_id']]) ? $sub_counts_map[$sc_item['sub_id']] : 0;
                                    }
                                    unset($sc_item);

                                    // 过滤掉计数为 0 的子分类（前端侧边栏菜单不显示计数为0的栏目）
                                    $visible_subcategories = array();
                                    foreach ($subcategories as $sc_item) {
                                        if (intval($sc_item['sub_count']) > 0) {
                                            $visible_subcategories[] = $sc_item;
                                        }
                                    }
                                    $subcategories = $visible_subcategories;
                                    $template_data['subcategories'] = $subcategories;
                                }
                            }

                            $sql_total = "SELECT COUNT(p.ID) FROM {$posts_table} p{$site_join}{$vis_join} JOIN {$tr_table} tr ON tr.object_id = p.ID JOIN {$tt_table} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.term_id = ? AND tt.taxonomy = 'category' AND p.post_status = 'publish'{$vis_where}{$lang_filter}{$site_where}";
                            $total_items = intval($db->get_var($sql_total, [$cat_id]));
                        }

                        // 子分类侧栏的"全部"总数
                        $template_data['total_count'] = $total_items;
                        if ($is_showing_siblings) {
                            // 叶子节点：累加所有同级兄弟分类的已发布帖子数
                            $sibling_total = 0;
                            foreach ($subcategories as $sib) {
                                $sibling_total += intval($sib['sub_count']);
                            }
                            $template_data['total_count'] = $sibling_total;
                        } elseif (!empty($subcategories)) {
                            // 非同级节点（父分类展示子分类）：若子分类汇总大于直属文章数，使用子分类汇总已发布文章数
                            $sub_total = 0;
                            foreach ($subcategories as $sib) {
                                $sub_total += intval($sib['sub_count']);
                            }
                            if ($sub_total > $total_items) {
                                $template_data['total_count'] = $sub_total;
                            }
                        }
                        $total_pages = max(1, intval(ceil($total_items / $per_page)));
                        $prev_page = ($current_page > 1) ? ($current_page - 1) : 0;
                        $next_page = ($current_page < $total_pages) ? ($current_page + 1) : 0;
                        $template_data['pagination'] = ($total_pages > 1) ? 1 : 0;
                        $template_data['pagination_prev_page'] = $prev_page;
                        $template_data['pagination_next_page'] = $next_page;
                        $template_data['pagination_current_page'] = $current_page;
                        $template_data['pagination_total_pages'] = $total_pages;
                        $template_data['pagination_total'] = $total_items;

                        // 构建分页页码数组
                        $base_category_url = todo_get_category_link($cat_id);
                        $template_data['pagination_pages'] = CMS_Template_Compiler_V2::build_pagination_pages(
                            $current_page, $total_pages, $base_category_url, 'paged', 2, 1
                        );

                        // 查询该分类下的文章列表，存入 $template_data['articles'] 及对应的 model plural 键，避免模板编译中 condition if/loop articles 失败
                        $posts = array();
                        if (isset($GLOBALS['tododb'])) {
                            // 此处预加载 posts 的目的：
                            // 1. 供 {{todo:if posts}} / {{todo:if articles}} 判断分类下是否有内容
                            // 2. 供无自定义参数的 {{todo:loop posts}} 直接消费
                            // 始终加载第1页（offset=0），避免翻页时（如 /page/2/）因 per_page 不同
                            // 导致返回空数组，使 {{todo:if posts}} 错误走 false 分支。
                            // 模板内带 per_page 参数的块标签（如 {{todo:products|per_page=2}}）
                            // 会跳过此预加载，自行发起正确的分页查询。
                            $offset = 0; // 始终取第1页，翻页由模板块标签自行处理
                            $sql_posts = "SELECT p.* FROM {$posts_table} p{$site_join}{$vis_join} JOIN {$tr_table} tr ON tr.object_id = p.ID JOIN {$tt_table} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.term_id = ? AND tt.taxonomy = 'category' AND p.post_status = 'publish' {$vis_where}{$lang_filter}{$site_where} ORDER BY p.post_date DESC LIMIT ? OFFSET ?";
                            $posts = $db->get_results($sql_posts, [$cat_id, $per_page, $offset]);
                            
                            // 预加载相关多语言及 meta 数据，提升解析效率
                            if (!empty($posts)) {
                                $post_ids = array();
                                foreach ($posts as $row) {
                                    if (isset($row->ID)) {
                                        $post_ids[] = (int)$row->ID;
                                    } elseif (is_array($row) && isset($row['ID'])) {
                                        $post_ids[] = (int)$row['ID'];
                                    }
                                }
                                $post_ids = array_filter(array_unique($post_ids));
                                if (!empty($post_ids)) {
                                    if (class_exists('Todo_Post_ORM')) {
                                        Todo_Post_ORM::eager_load_all($post_ids, [
                                            'meta' => true,
                                            'terms' => true,
                                            'multilang' => true,
                                            'tags' => true,
                                            'categories' => true,
                                            'author' => true
                                        ]);
                                    }
                                    if (function_exists('update_post_meta_cache')) {
                                        update_post_meta_cache($post_ids);
                                    }
                                    if (function_exists('todo_preload_post_translations')) {
                                        $lang = function_exists('get_current_language') ? get_current_language() : (function_exists('todo_get_current_language') ? todo_get_current_language() : 'zh-CN');
                                        todo_preload_post_translations($post_ids, $lang);
                                    }
                                }
                            }
                        }
                        
                        $template_data['articles'] = $posts;
                        $template_data['posts'] = $posts;
                        
                        $model_key = '';
                        if (class_exists('Todo_Model_Manager')) {
                            $model_key = Todo_Model_Manager::get_category_model($cat_id) ?: '';
                        }
                        if ($model_key) {
                            $model_plural_map = [
                                'product' => 'products',
                                'article' => 'articles',
                                'recruit' => 'jobs',
                                'special' => 'specials',
                                'guestbook' => 'guestbooks',
                                'single' => 'single_pages',
                                'images' => 'images',
                                'download' => 'downloads',
                                'media' => 'media',
                            ];
                            $collection_name = isset($model_plural_map[$model_key]) ? $model_plural_map[$model_key] : '';
                            if ($collection_name && $collection_name !== 'articles') {
                                $template_data[$collection_name] = $posts;
                            }
                        }
                    }
                } elseif ($page_type === 'tag') {
                    $current_tag = function_exists('get_current_tag') ? get_current_tag() : null;
                    $tag_id = 0;
                    if ($current_tag) {
                        if (is_object($current_tag)) {
                            $tag_id = isset($current_tag->tag_id) ? intval($current_tag->tag_id) : (isset($current_tag->id) ? intval($current_tag->id) : 0);
                        } elseif (is_array($current_tag)) {
                            $tag_id = isset($current_tag['tag_id']) ? intval($current_tag['tag_id']) : (isset($current_tag['id']) ? intval($current_tag['id']) : 0);
                        }
                    }
                    if ($tag_id) {
                        // 标签基础信息
                        $template_data['tag_id'] = intval($tag_id);
                        if (is_array($current_tag)) {
                            $template_data['tag_name'] = isset($current_tag['name']) ? (string) $current_tag['name'] : (isset($current_tag['tag_title']) ? (string) $current_tag['tag_title'] : '');
                            $template_data['tag_slug'] = isset($current_tag['slug']) ? (string) $current_tag['slug'] : (isset($current_tag['tag_slug']) ? (string) $current_tag['tag_slug'] : '');
                            $template_data['tag_description'] = isset($current_tag['description']) ? (string) $current_tag['description'] : (isset($current_tag['tag_desc']) ? (string) $current_tag['tag_desc'] : '');
                        } elseif (is_object($current_tag)) {
                            $template_data['tag_name'] = isset($current_tag->name) ? (string) $current_tag->name : (isset($current_tag->tag_title) ? (string) $current_tag->tag_title : '');
                            $template_data['tag_slug'] = isset($current_tag->slug) ? (string) $current_tag->slug : (isset($current_tag->tag_slug) ? (string) $current_tag->tag_slug : '');
                            $template_data['tag_description'] = isset($current_tag->description) ? (string) $current_tag->description : (isset($current_tag->tag_desc) ? (string) $current_tag->tag_desc : '');
                        }
                        if (!empty($template_data['tag_name'])) {
                            if (function_exists('todo_parse_inline_translations')) {
                                $template_data['tag_name'] = todo_parse_inline_translations($template_data['tag_name']);
                            }
                            if (function_exists('todo_translate')) {
                                $template_data['tag_name'] = todo_translate($template_data['tag_name']);
                            }
                        }
                        $template_data['tag_url'] = todo_get_tag_link($tag_id);

                        // 分页数据
                        $per_page = max(1, (int) todo_get_query_context_value('per_page', todo_get_int('per_page', 10)));
                        $current_page = todo_get_paged_context('paged', 1);
                        $template_data['per_page'] = $per_page;
                        $template_data['page'] = $current_page;

                        // 查询该标签下的文章总数
                        $total_items = 0;
                        $posts = array();
                        if (isset($GLOBALS['tododb'])) {
                            $db = $GLOBALS['tododb'];
                            $posts_table = $db->prefix . 'posts';
                            $pt_table = $db->prefix . 'post_tags';

                            // 站点可见性过滤
                            $tag_site_id = (defined('IS_ADMIN') && IS_ADMIN) ? todo_get_site_context_id(0) : (function_exists('todo_current_site_id') ? (int) todo_current_site_id(true) : 0);
                            $tag_site_join = '';
                            $tag_site_where = '';
                            if ($tag_site_id > 0 && function_exists('todo_get_post_site_visibility_sql')) {
                                $site_posts_table = $db->prefix . 'site_posts';
                                $tag_site_join = " LEFT JOIN {$site_posts_table} sp_tag ON (p.ID = sp_tag.post_id AND sp_tag.site_id = {$tag_site_id})";
                                $tag_site_where = ' AND ' . todo_get_post_site_visibility_sql('sp_tag', $tag_site_id, 0, 'p.ID');
                            }

                            // 可见性过滤（仅统计 public 文章）
                            $vis_tag_join = "";
                            $vis_tag_where = " AND p.visibility = 'public' AND NOT EXISTS (SELECT 1 FROM {$db->prefix}postmeta m_vis WHERE m_vis.post_id = p.ID AND m_vis.meta_key = '_visibility' AND m_vis.meta_value <> 'public')";

                            $sql_total = "SELECT COUNT(p.ID) FROM {$posts_table} p{$tag_site_join}{$vis_tag_join} INNER JOIN {$pt_table} pt ON pt.post_id = p.ID WHERE pt.tag_id = ? AND p.post_status = 'publish'{$vis_tag_where}{$tag_site_where}";
                            $total_items = intval($db->get_var($sql_total, [$tag_id]));
                            $template_data['total_posts'] = $total_items;
                        }
                        $total_pages = max(1, intval(ceil($total_items / $per_page)));
                        $prev_page = ($current_page > 1) ? ($current_page - 1) : 0;
                        $next_page = ($current_page < $total_pages) ? ($current_page + 1) : 0;
                        $template_data['pagination'] = ($total_pages > 1) ? 1 : 0;
                        $template_data['pagination_prev_page'] = $prev_page;
                        $template_data['pagination_next_page'] = $next_page;
                        $template_data['pagination_current_page'] = $current_page;
                        $template_data['pagination_total_pages'] = $total_pages;
                        $template_data['pagination_total'] = $total_items;
                        $template_data['pagination_prev_url'] = ($prev_page > 0) ? todo_get_paginated_url($prev_page, todo_get_tag_link($tag_id)) : '';
                        $template_data['pagination_next_url'] = ($next_page > 0) ? todo_get_paginated_url($next_page, todo_get_tag_link($tag_id)) : '';

                        // 分页页码数组
                        $pagination_pages = array();
                        $base_tag_url = todo_get_tag_link($tag_id);
                        for ($i = 1; $i <= $total_pages; $i++) {
                            $pagination_pages[] = array(
                                'page_number' => $i,
                                'page_is_current' => ($i === $current_page) ? 1 : 0,
                                'page_url' => todo_get_paginated_url($i, $base_tag_url),
                            );
                        }
                        $template_data['pagination_pages'] = $pagination_pages;

                        // 查询该标签下的文章列表
                        $posts = array();
                        if (isset($GLOBALS['tododb']) && $tag_id > 0) {
                            $db = $GLOBALS['tododb'];
                            $posts_table = $db->prefix . 'posts';
                            $pt_table = $db->prefix . 'post_tags';
                            $offset = ($current_page - 1) * $per_page;

                            // 站点可见性过滤（与 count 查询一致）
                            $tag_site_join2 = '';
                            $tag_site_where2 = '';
                            if (isset($tag_site_id) && $tag_site_id > 0 && function_exists('todo_get_post_site_visibility_sql')) {
                                $tag_site_join2 = $tag_site_join;
                                $tag_site_where2 = $tag_site_where;
                            }
                            
                            // 可见性过滤（仅统计 public 文章）
                            $vis_tag_join2 = "";
                            $vis_tag_where2 = " AND p.visibility = 'public' AND NOT EXISTS (SELECT 1 FROM {$db->prefix}postmeta m_vis WHERE m_vis.post_id = p.ID AND m_vis.meta_key = '_visibility' AND m_vis.meta_value <> 'public')";

                            $sql_posts = "SELECT p.* FROM {$posts_table} p{$tag_site_join2}{$vis_tag_join2} INNER JOIN {$pt_table} pt ON pt.post_id = p.ID WHERE pt.tag_id = ? AND p.post_status = 'publish'{$vis_tag_where2}{$tag_site_where2} ORDER BY p.post_date DESC LIMIT ? OFFSET ?";
                            $rows = $db->get_results($sql_posts, [$tag_id, $per_page, $offset]);

                            // 预加载相关数据
                            if (!empty($rows) && class_exists('Todo_Post_ORM')) {
                                $post_ids = array();
                                foreach ($rows as $row) {
                                    $pid = isset($row->ID) ? intval($row->ID) : (is_array($row) && isset($row['ID']) ? intval($row['ID']) : 0);
                                    if ($pid > 0) $post_ids[] = $pid;
                                }
                                if (!empty($post_ids)) {
                                    Todo_Post_ORM::eager_load_all($post_ids, [
                                        'meta' => true,
                                        'terms' => true,
                                        'multilang' => true,
                                        'tags' => true,
                                        'categories' => true,
                                        'author' => true
                                    ]);
                                }
                            }

                            // 格式化每篇文章，添加 article_* 别名字段
                            $posts = array();
                            foreach ($rows as $row) {
                                $posts[] = CMS_Template_Compiler_V2::format_post_item($row, $tag_id);
                            }
                        }

                        $template_data['articles'] = $posts;
                        $template_data['posts'] = $posts;
                    }
                } elseif ($page_type === 'archive') {
                    $year = 0;
                    $month = 0;
                    $day = 0;
                    if (isset($GLOBALS['archive_year'])) $year = intval($GLOBALS['archive_year']);
                    if (isset($GLOBALS['archive_month'])) $month = intval($GLOBALS['archive_month']);
                    if (isset($GLOBALS['archive_day'])) $day = intval($GLOBALS['archive_day']);
                    if ($year <= 0) $year = (int) todo_get_query_context_value('archive_year', todo_get_int('archive_year'));
                    if ($month <= 0) $month = (int) todo_get_query_context_value('archive_month', todo_get_int('archive_month'));
                    if ($day <= 0) $day = (int) todo_get_query_context_value('archive_day', todo_get_int('archive_day'));
                    if ($year <= 0) $year = (int) todo_get_query_context_value('year', todo_get_int('year'));
                    if ($month <= 0) $month = (int) todo_get_query_context_value('month', todo_get_int('month'));
                    if ($month <= 0) $month = (int) todo_get_query_context_value('monthnum', todo_get_int('monthnum'));
                    if ($day <= 0) $day = (int) todo_get_query_context_value('day', todo_get_int('day'));

                    $template_data['archive_year'] = $year;
                    $template_data['archive_month'] = $month;
                    $template_data['archive_day'] = $day;

                    $per_page = max(1, (int) todo_get_query_context_value('per_page', todo_get_int('per_page', 10)));
                    $current_page = todo_get_paged_context('paged', 1);
                    $template_data['per_page'] = $per_page;
                    $template_data['page'] = $current_page;

                    $title = '';
                    if ($year > 0) {
                        if ($month > 0) {
                            if ($day > 0) {
                                $title = $year . '/' . $month . '/' . $day;
                            } else {
                                $title = $year . '/' . $month;
                            }
                        } else {
                            $title = $year;
                        }
                    }
                    $template_data['archive_title'] = $title;

                    $total_items = 0;
                    $posts = [];
                    if (isset($GLOBALS['tododb'])) {
                        $db = $GLOBALS['tododb'];
                        $posts_table = $db->prefix . 'posts';

                        $lang_filter = '';
                        if (!defined('IS_ADMIN') || !IS_ADMIN) {
                            $current_lang = function_exists('current_locale') ? current_locale() : (function_exists('todo_get_current_language') ? todo_get_current_language() : 'zh-CN');
                            $current_lang = str_replace('_', '-', $current_lang);

                            $default_lang = function_exists('get_default_language') ? get_default_language() : 'zh-CN';
                            $default_lang = str_replace('_', '-', $default_lang);

                            $is_main_lang = ($current_lang === $default_lang);
                            if (!$is_main_lang) {
                                $lang_escaped = $db->esc_like($current_lang);
                                $lang_filter = " AND p.ID IN (SELECT post_id FROM {$db->prefix}postmeta WHERE meta_key = '_enabled_languages' AND meta_value LIKE '%\"{$lang_escaped}\"%')";
                            }
                        }

                        // 站点过滤：仅展示当前站点可见的文章（含分类级回退）
                        $site_join = '';
                        $site_where = '';
                        $current_site_id = (defined('IS_ADMIN') && IS_ADMIN) ? todo_get_site_context_id(0) : (function_exists('todo_current_site_id') ? (int) todo_current_site_id(true) : 0);
                        if ($current_site_id > 0 && function_exists('todo_get_post_site_visibility_sql')) {
                            $site_posts_table = $db->prefix . 'site_posts';
                            $site_join = " LEFT JOIN {$site_posts_table} sp_archive ON (p.ID = sp_archive.post_id AND sp_archive.site_id = {$current_site_id})";
                            $site_where = ' AND ' . todo_get_post_site_visibility_sql('sp_archive', $current_site_id, 0, 'p.ID');
                        }

                        $vis_arch_join = "";
                        $vis_arch_where = " AND p.visibility = 'public' AND NOT EXISTS (SELECT 1 FROM {$db->prefix}postmeta m_vis WHERE m_vis.post_id = p.ID AND m_vis.meta_key = '_visibility' AND m_vis.meta_value <> 'public')";

                        $where = "p.post_status = 'publish'{$vis_arch_where}";
                        $params = [];
                        if ($year > 0) {
                            $where .= " AND YEAR(p.post_date) = ?";
                            $params[] = $year;
                        }
                        if ($month > 0) {
                            $where .= " AND MONTH(p.post_date) = ?";
                            $params[] = $month;
                        }
                        if ($day > 0) {
                            $where .= " AND DAY(p.post_date) = ?";
                            $params[] = $day;
                        }

                        $sql_total = "SELECT COUNT(p.ID) FROM {$posts_table} p {$site_join}{$vis_arch_join} WHERE {$where}{$lang_filter}{$site_where}";
                        $total_items = intval($db->get_var($sql_total, $params));
                        $template_data['total_posts'] = $total_items;

                        $total_pages = max(1, intval(ceil($total_items / $per_page)));
                        $prev_page = ($current_page > 1) ? ($current_page - 1) : 0;
                        $next_page = ($current_page < $total_pages) ? ($current_page + 1) : 0;
                        $template_data['pagination'] = ($total_pages > 1) ? 1 : 0;
                        $template_data['pagination_prev_page'] = $prev_page;
                        $template_data['pagination_next_page'] = $next_page;
                        $template_data['pagination_current_page'] = $current_page;
                        $template_data['pagination_total_pages'] = $total_pages;
                        $template_data['pagination_total'] = $total_items;

                        // 归档链接始终语义化（路由已支持任意模式）
                        if ($year > 0) {
                            $base_archive_url = ($month > 0)
                                ? todo_home_url(sprintf('/%04d/%02d/', $year, $month))
                                : todo_home_url(sprintf('/%04d/', $year));
                        } else {
                            $base_archive_url = todo_home_url('/?page=archive');
                        }
                        $template_data['pagination_prev_url'] = ($prev_page > 0) ? todo_get_paginated_url($prev_page, $base_archive_url) : '';
                        $template_data['pagination_next_url'] = ($next_page > 0) ? todo_get_paginated_url($next_page, $base_archive_url) : '';

                        $template_data['pagination_pages'] = CMS_Template_Compiler_V2::build_pagination_pages(
                            $current_page, $total_pages, $base_archive_url, 'paged', 2, 1
                        );

                        $offset = ($current_page - 1) * $per_page;
                        $sql_posts = "SELECT p.* FROM {$posts_table} p {$site_join}{$vis_arch_join} WHERE {$where}{$lang_filter}{$site_where} ORDER BY p.post_date DESC LIMIT ? OFFSET ?";
                        $params_posts = array_merge($params, [$per_page, $offset]);
                        $rows = $db->get_results($sql_posts, $params_posts);

                        if (!empty($rows) && class_exists('Todo_Post_ORM')) {
                            $post_ids = [];
                            foreach ($rows as $row) {
                                $pid = isset($row->ID) ? intval($row->ID) : (is_array($row) && isset($row['ID']) ? intval($row['ID']) : 0);
                                if ($pid > 0) $post_ids[] = $pid;
                            }
                            if (!empty($post_ids)) {
                                Todo_Post_ORM::eager_load_all($post_ids, [
                                    'meta' => true,
                                    'terms' => true,
                                    'multilang' => true,
                                    'tags' => true,
                                    'categories' => true,
                                    'author' => true
                                ]);
                            }
                        }

                        $posts = [];
                        foreach ((array) $rows as $row) {
                            $posts[] = CMS_Template_Compiler_V2::format_post_item($row);
                        }
                    }

                    $template_data['articles'] = $posts;
                    $template_data['posts'] = $posts;
                }

                if (function_exists('clear_template_cache')) {
                    clear_template_cache($template);
                }

                // 添加调试：检查解析器是否存在
                if (!function_exists('parse_html_template')) {
                    error_log('[TodoCMS] 错误：parse_html_template 函数不存在');
                }
                if (!function_exists('get_template_parser')) {
                    error_log('[TodoCMS] 错误：get_template_parser 函数不存在');
                } else {
                    $parser = get_template_parser();
                    if (!$parser) {
                        error_log('[TodoCMS] 错误：模板解析器实例不存在');
                    } else {
                        error_log('[TodoCMS] 调试：模板解析器实例存在，开始解析: ' . $template_path);
                    }
                }

                // 用户中心模板：注入用户中心数据与专属标签（必须在 parse_html_template 之前调用）
                if (strpos($template, 'users/') === 0) {
                    if (function_exists('todo_prepare_user_center_data')) {
                        $template_data = todo_prepare_user_center_data($template_data);
                    }
                    if (function_exists('todo_register_user_center_tags')) {
                        todo_register_user_center_tags();
                    }
                }

                $out = parse_html_template($template_path, $template_data);
                // 检查解析结果：如果结果为空或与原始内容相同（说明标签未被解析），记录错误
                if ($out === '' || trim($out) === '') {
                    error_log('[TodoCMS] 错误：模板解析结果为空: ' . $template_path);
                    // 解析失败时绝不回退输出原始源码（会泄露 {{todo:xxx}} 标签到前端）
                    $out = '<!-- Template Parse Error: ' . htmlspecialchars(basename($template_path)) . ' -->';
                } else {
                    // 检查解析结果是否包含未解析的标签
                    if (preg_match('/\{\{todo:[^}]+\}\}/', $out)) {
                        $unparsed_tags = [];
                        preg_match_all('/\{\{todo:[^}]+\}\}/', $out, $matches);
                        if (!empty($matches[0])) {
                            $unparsed_tags = array_unique($matches[0]);
                        }
                        error_log('[TodoCMS] 警告：模板解析结果中仍包含未解析的标签: ' . $template_path);
                        error_log('[TodoCMS] 调试：未解析的标签: ' . implode(', ', $unparsed_tags));
                    }
                    if (defined('TODO_DEBUG') && TODO_DEBUG) {
                        error_log('[TodoCMS] 模板解析完成，输出长度: ' . strlen($out));
                    }
                }

                // 手动发送Server-Timing头（因为即将输出内容）
                if (class_exists('Todo_Performance_Monitor')) {
                    Todo_Performance_Monitor::send_server_timing_header();
                }

                echo $out;

                // 输出HTML格式的性能数据注释
                if (class_exists('Todo_Performance_Monitor')) {
                    echo Todo_Performance_Monitor::get_timing_html_comment();
                }
            } else {
                // 如果解析器不可用，直接输出HTML内容
                error_log('[TodoCMS] 模板解析器不可用，直接输出HTML: ' . $template_path);

                // 手动发送Server-Timing头（因为即将输出内容）
                if (class_exists('Todo_Performance_Monitor')) {
                    Todo_Performance_Monitor::send_server_timing_header();
                }

                echo file_get_contents($template_path);
            }
        } else {
            // PHP模板文件直接包含
            error_log('[TodoCMS] 包含PHP模板文件: ' . $template_path . ' (page_type=' . get_current_page_type() . ')');

            // 手动发送Server-Timing头（因为即将输出内容）
            if (class_exists('Todo_Performance_Monitor')) {
                Todo_Performance_Monitor::send_server_timing_header();
            }

            include $template_path;

            // 输出HTML格式的性能数据注释
            if (class_exists('Todo_Performance_Monitor')) {
                echo Todo_Performance_Monitor::get_timing_html_comment();
            }
        }
    } else {
        error_log('[TodoCMS] 错误：模板文件不存在: ' . $template_path);
    }

    // 执行模板加载后钩子
    do_action('post_template_load', $template);
}

/**
 * 获取当前页面类型
 *
 * @return string 页面类型
 */
function get_current_page_type()
{
    global $current_page_type;
    return $current_page_type ?? 'home';
}

/**
 * 设置当前页面类型
 *
 * @param string $type 页面类型
 */
function set_current_page_type($type)
{
    global $current_page_type;
    $current_page_type = $type;
}

/**
 * 设置当前文章
 *
 * @param int $post_id 文章ID
 */
function set_current_post($post_id)
{
    global $current_post, $post;
    $current_post = get_post($post_id);
    $post = $current_post;
    $GLOBALS['todo_post'] = $current_post;
    set_current_page_type('single');

    if (function_exists('get_the_category')) {
        $cats = get_the_category($post_id);
        if (!empty($cats) && class_exists('Todo_Model_Manager') && method_exists('Todo_Model_Manager', 'get_category_model')) {
            foreach ($cats as $cat) {
                if (isset($cat->term_id)) {
                    $model_key = Todo_Model_Manager::get_category_model($cat->term_id);
                    if ($model_key === 'single') {
                        $GLOBALS['_todo_is_single_page_category'] = true;
                        break;
                    }
                }
            }
        }
    }
}

/**
 * 获取当前文章
 *
 * @return object 当前文章对象
 */
function get_current_post()
{
    global $current_post;
    return $current_post;
}

/**
 * 设置当前页面
 *
 * @param int|string $page_id 页面ID或特殊页面标识
 */
function set_current_page($page_id)
{
    global $current_page, $page;

    if ((string)$page_id === '404') {
        $current_page = '404';
        set_current_page_type('404');
    } elseif (is_numeric($page_id)) {
        $current_page = get_post($page_id);
        $page = $current_page;
        set_current_page_type('page');
    } else {
        $current_page = $page_id;
        set_current_page_type($page_id);
    }
}

/**
 * 获取当前页面
 *
 * @return mixed 当前页面对象或标识
 */
function get_current_page()
{
    global $current_page;
    return $current_page;
}

/**
 * 获取当前页面slug
 *
 * @return string 页面slug
 */
function get_current_page_slug()
{
    global $current_page;

    if (is_object($current_page) && isset($current_page->post_name)) {
        return $current_page->post_name;
    }

    return is_string($current_page) ? $current_page : '';
}

function todo_get_single_category_post_id($category_id, $context = 'frontend')
{
    $category_id = intval($category_id);
    if ($category_id <= 0) {
        return 0;
    }

    $is_admin_ctx = ($context === 'admin') || (defined('IS_ADMIN') && IS_ADMIN);
    $allow_future = (defined('TODO_VIEW_FUTURE') && TODO_VIEW_FUTURE);

    global $tododb;
    if (!isset($tododb)) {
        return 0;
    }

    // 请求内记忆：同一请求中该函数会被重复调用
    static $memo = [];
    $memo_key = $category_id . '|' . ($is_admin_ctx ? 'a' : 'f') . '|' . ($allow_future ? 'f' : 'n');
    if (isset($memo[$memo_key])) {
        return $memo[$memo_key];
    }
    $result_id = todo_get_single_category_post_id_uncached($category_id, $is_admin_ctx, $allow_future);
    $memo[$memo_key] = $result_id;
    return $result_id;
}

function todo_get_single_category_post_id_uncached($category_id, $is_admin_ctx, $allow_future)
{
    global $tododb;

    // 获取头条 (headline) 属性 ID
    $headline_prop_id = 0;
    $headline_row = $tododb->get_row("SELECT id FROM {$tododb->prefix}doc_properties WHERE value = 'headline' OR name = '头条' LIMIT 1");
    if ($headline_row && isset($headline_row->id)) {
        $headline_prop_id = intval($headline_row->id);
    }

    $now_gmt = function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s');

    $status_params = [];
    if ($is_admin_ctx) {
        $status_where = "p.post_status <> 'trash'";
    } elseif ($allow_future) {
        $status_where = "(p.post_status = 'future' OR p.post_status = 'publish')";
    } else {
        $status_where = "p.post_status = 'publish' AND (p.post_date_gmt IS NULL OR p.post_date_gmt <= ?)";
        $status_params[] = $now_gmt;
    }

    // 分类直连(category_id)与关系表两路各自取首条再合并，避免 OR + EXISTS 导致全表扫描
    $direct_cat  = "p.category_id = ?";
    $rel_cat     = "EXISTS (SELECT 1 FROM {$tododb->prefix}term_relationships tr INNER JOIN {$tododb->prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tr.object_id = p.ID AND tt.taxonomy = 'category' AND tt.term_id = ?)";

    $pick_first = function ($extra_where, $extra_params) use ($tododb, $direct_cat, $rel_cat, $status_where, $status_params, $category_id) {
        $parts = [];
        $args  = [];
        foreach ([$direct_cat, $rel_cat] as $cat_cond) {
            $parts[] = "(SELECT p.ID, p.post_date_gmt FROM {$tododb->prefix}posts p WHERE {$cat_cond} AND {$status_where}{$extra_where} ORDER BY p.post_date_gmt DESC, p.ID DESC LIMIT 1)";
            $args[]  = $category_id;
            foreach ($status_params as $sp) {
                $args[] = $sp;
            }
            foreach ($extra_params as $ep) {
                $args[] = $ep;
            }
        }
        $row = $tododb->get_row(
            "SELECT u.ID FROM (" . implode(' UNION ALL ', $parts) . ") u ORDER BY u.post_date_gmt DESC, u.ID DESC LIMIT 1",
            $args
        );
        return ($row && isset($row->ID)) ? intval($row->ID) : 0;
    };

    // 1. 优先定位带有“头条”属性的文章
    if ($headline_prop_id > 0) {
        $hid = $pick_first(" AND FIND_IN_SET(?, p.doc_properties) > 0", [$headline_prop_id]);
        if ($hid > 0) {
            return $hid;
        }
    }

    // 2. 回退：获取该分类下的首篇文章（按发布时间降序）
    return $pick_first('', []);
}

/**
 * 设置当前分类
 *
 * @param int $category_id 分类ID
 */
function set_current_category($category_id)
{
    global $current_category;
    $current_category = get_category($category_id);

    $category_id = intval($category_id);
    if ($category_id > 0 && (!defined('IS_ADMIN') || !IS_ADMIN) && class_exists('Todo_Model_Manager') && method_exists('Todo_Model_Manager', 'get_category_model')) {
        $model_key = Todo_Model_Manager::get_category_model($category_id);
        if ($model_key === 'single') {
            $post_id = todo_get_single_category_post_id($category_id, 'frontend');
            if ($post_id > 0) {
                $GLOBALS['_todo_is_single_page_category'] = true;
                set_current_post($post_id);
                return;
            }

            if (!headers_sent()) {
                header('HTTP/1.0 404 Not Found');
            }
            todo_sync_query_context(array('page' => '404'));
            set_current_page('404');
            return;
        }
    }

    // 检查分类是否被标记为隐藏（包括时间段动态隐藏），隐藏分类前台返回 404
    if ((!defined('IS_ADMIN') || !IS_ADMIN) && function_exists('get_term_meta')) {
        $is_hidden = get_term_meta($category_id, 'is_hidden', true);
        if ($is_hidden) {
            if (!headers_sent()) {
                header('HTTP/1.0 404 Not Found');
            }
            todo_sync_query_context(array('page' => '404'));
            set_current_page('404');
            return;
        }
    }

    set_current_page_type('category');
}

/**
 * 获取当前分类
 *
 * @return object|null 当前分类对象，如果没有则返回null
 */
function get_current_category()
{
    global $current_category;

    // 如果是数组，转换为对象
    if (is_array($current_category)) {
        return (object) $current_category;
    }

    return $current_category;
}

if (!function_exists('get_category')) {
    function get_category($id, $output = OBJECT, $filter = 'raw')
    {
        if (!class_exists('Todo_Category_Manager')) {
            return null;
        }
        $c = Todo_Category_Manager::get_category((int) $id);
        if (is_array($c)) {
            return (object) $c;
        }
        if (is_object($c)) {
            return $c;
        }
        return null;
    }
}

/**
 * 获取当前分类ID
 *
 * @return int 分类ID
 */
function get_current_category_id()
{
    global $current_category;
    if ($current_category) {
        // 如果是对象，访问属性
        if (is_object($current_category)) {
            if (isset($current_category->term_id))
                return (int) $current_category->term_id;
            if (isset($current_category->id))
                return (int) $current_category->id;
            return 0;
        }
        // 如果是数组，访问键值
        if (is_array($current_category)) {
            if (isset($current_category['term_id']))
                return (int) $current_category['term_id'];
            if (isset($current_category['id']))
                return (int) $current_category['id'];
            return 0;
        }
    }
    return 0;
}

/**
 * 获取当前分类的slug
 *
 * @return string 分类slug
 */
function get_category_slug()
{
    global $current_category;
    if ($current_category) {
        // 如果是对象，访问属性
        if (is_object($current_category)) {
            return isset($current_category->slug) ? $current_category->slug : '';
        }
        // 如果是数组，访问键值
        if (is_array($current_category)) {
            return isset($current_category['slug']) ? $current_category['slug'] : '';
        }
    }
    return '';
}

/**
 * 设置当前标签
 *
 * @param int $tag_id 标签ID
 */
function set_current_tag($tag_id)
{
    global $current_tag;
    $current_tag = todo_get_tag($tag_id);
    set_current_page_type('tag');
}

/**
 * 获取当前标签
 *
 * @return object|null 当前标签对象，如果没有则返回null
 */
function get_current_tag()
{
    global $current_tag;

    // 如果是数组，转换为对象
    if (is_array($current_tag)) {
        return (object) $current_tag;
    }

    return $current_tag;
}

/**
 * 设置当前作者
 *
 * @param int $author_id 作者ID
 */
function set_current_author($author_id)
{
    global $current_author;
    $current_author = get_user_by_id($author_id);
    set_current_page_type('author');
}

/**
 * 获取当前作者
 *
 * @return object 当前作者对象
 */
function get_current_author()
{
    global $current_author;
    return $current_author;
}

/**
 * 设置搜索查询
 *
 * @param string $query 搜索查询
 */
function set_search_query($query)
{
    global $search_query;
    $search_query = $query;
    set_current_page_type('search');

    $keyword = trim($query);
    if ($keyword === '') return;

    // ====== 加载搜索配置 ======
    $sc_raw = function_exists('todo_get_option') ? todo_get_option('search_config', '') : '';
    if (empty($sc_raw) && function_exists('get_option')) {
        $sc_raw = get_option('search_config', '');
    }
    $sc     = !empty($sc_raw) ? json_decode($sc_raw, true) : [];
    if (!is_array($sc)) $sc = [];

    // ====== 频率限制 (严格依照后台 search_config 配置生效) ======
    $rate_limit    = isset($sc['rate_limit']) && (int)$sc['rate_limit'] > 0 ? (int)$sc['rate_limit'] : 60;
    $rate_max      = isset($sc['rate_max']) && (int)$sc['rate_max'] > 0 ? (int)$sc['rate_max'] : 5;
    $lock_duration = isset($sc['lock_duration']) && (int)$sc['lock_duration'] > 0 ? (int)$sc['lock_duration'] : 120;
    $ip            = function_exists('todo_get_user_ip') ? todo_get_user_ip() : (function_exists('todo_server_string') ? todo_server_string('REMOTE_ADDR', '127.0.0.1') : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));

    if (function_exists('get_transient') && function_exists('set_transient')) {
        $ip_hash  = md5($ip);
        // 检查是否已被锁定
        $lock_key = 'search_lock_' . $ip_hash;
        if (get_transient($lock_key)) {
            if (!defined('SEARCH_LOCKED')) define('SEARCH_LOCKED', true);
            return;
        }

        // 频率计数
        $rate_key  = 'search_rate_' . $ip_hash;
        $rate_data = get_transient($rate_key);
        $now       = time();
        if (!$rate_data || !is_array($rate_data)) {
            $rate_data = ['count' => 1, 'first' => $now];
            set_transient($rate_key, $rate_data, $rate_limit);
        } else {
            $rate_data['count']++;
            if ($rate_data['count'] > $rate_max) {
                // 超出配置的允许最大次数，执行锁定
                set_transient($lock_key, $now + $lock_duration, $lock_duration);
                if (function_exists('delete_transient')) delete_transient($rate_key);
                if (!defined('SEARCH_LOCKED')) define('SEARCH_LOCKED', true);
                return;
            } else {
                $elapsed = $now - (int)($rate_data['first'] ?? $now);
                $remaining_window = max(1, $rate_limit - $elapsed);
                set_transient($rate_key, $rate_data, $remaining_window);
            }
        }
    }

    // ====== 敏感词检测 ======
    // 内置特殊字符过滤
    $special_chars = ['<', '>', '"', ';', ',', '@', '&', '#', '\\', '*'];
    foreach ($special_chars as $ch) {
        if (strpos($keyword, $ch) !== false) {
            if (!defined('SEARCH_SENSITIVE_BLOCKED')) define('SEARCH_SENSITIVE_BLOCKED', true);
            $search_query = '';
            return;
        }
    }

    // 自定义敏感词
    $sensitive_words = isset($sc['sensitive_words']) && is_array($sc['sensitive_words']) ? $sc['sensitive_words'] : [];
    foreach ($sensitive_words as $sw) {
        $sw = trim($sw);
        if ($sw !== '' && stripos($keyword, $sw) !== false) {
            if (!defined('SEARCH_SENSITIVE_BLOCKED')) define('SEARCH_SENSITIVE_BLOCKED', true);
            $search_query = '';
            return;
        }
    }

    // 记录搜索关键词
    todo_log_search_keyword($keyword, 0);
}

/**
 * 获取搜索查询
 *
 * @return string 搜索查询
 */
if (!function_exists('get_search_query')) {
    function todo_set_query_context_overrides($overrides)
    {
        $GLOBALS['todo_query_context_overrides'] = is_array($overrides) ? $overrides : array();
    }
}

if (!function_exists('todo_clear_query_context_overrides')) {
    function todo_clear_query_context_overrides()
    {
        unset($GLOBALS['todo_query_context_overrides']);
    }
}

if (!function_exists('todo_get_query_context_value')) {
    function todo_get_query_context_value($key, $default = null)
    {
        if (isset($GLOBALS['todo_query_context_overrides']) && is_array($GLOBALS['todo_query_context_overrides']) && array_key_exists($key, $GLOBALS['todo_query_context_overrides'])) {
            return $GLOBALS['todo_query_context_overrides'][$key];
        }
        return $default;
    }
}

if (!function_exists('todo_get_request_uri')) {
    function todo_get_request_uri()
    {
        $override = todo_get_query_context_value('request_uri', null);
        if (is_string($override) && $override !== '') {
            return $override;
        }
        return todo_server_string('REQUEST_URI', '/');
    }
}

if (!function_exists('todo_get_search_context')) {
    function todo_get_search_context()
    {
        $override = todo_get_query_context_value('search_term', null);
        if (is_string($override) && $override !== '') {
            return trim($override);
        }
        foreach (array('q', 's', 'search', 'keyword') as $key) {
            $value = todo_get_query_context_value($key, null);
            if (is_string($value) && $value !== '') {
                return trim($value);
            }
            $value = todo_get_string($key);
            if ($value !== '') {
                return trim($value);
            }
        }
        return '';
    }
}

if (!function_exists('todo_get_paged_context')) {
    function todo_get_paged_context($page_param = 'paged', $default = 1)
    {
        $page_param = sanitize_key((string) $page_param);
        if ($page_param === '') {
            $page_param = 'paged';
        }

        $override = todo_get_query_context_value($page_param, null);
        if ($override !== null && !is_array($override) && !is_object($override) && is_numeric((string) $override)) {
            return max(1, intval($override));
        }

        if ($page_param === 'paged') {
            $fallback_keys = array('paged', 'page', 'page_num');
        } else {
            $fallback_keys = array('paged_' . $page_param, $page_param);
        }

        foreach ($fallback_keys as $key) {
            $override = todo_get_query_context_value($key, null);
            if ($override !== null && !is_array($override) && !is_object($override) && is_numeric((string) $override)) {
                return max(1, intval($override));
            }
            $value = todo_get_string($key);
            if ($value !== '' && is_numeric($value)) {
                return max(1, intval($value));
            }
        }

        if ($page_param === 'paged') {
            $uri = todo_get_request_uri();
            if ($uri !== '' && preg_match('#/page/([0-9]+)/?#', $uri, $matches)) {
                return max(1, intval($matches[1]));
            }
        }

        return max(1, intval($default));
    }
}

if (!function_exists('todo_get_current_page_context')) {
    function todo_get_current_page_context($default = 'home')
    {
        $page = todo_get_query_context_value('page', null);
        if (is_string($page) && $page !== '') {
            return $page;
        }
        $page = todo_get_string('page', '');
        if ($page !== '') {
            return $page;
        }
        return $default;
    }
}

if (!function_exists('todo_get_current_action_context')) {
    function todo_get_current_action_context($default = '')
    {
        $action = todo_get_query_context_value('action', null);
        if (is_string($action) && $action !== '') {
            return $action;
        }
        return todo_get_string('action', $default);
    }
}

if (!function_exists('todo_get_site_context_id')) {
    function todo_get_site_context_id($default = 0)
    {
        $site = todo_get_query_context_value('site', null);
        if ($site !== null && !is_array($site) && !is_object($site) && is_numeric((string) $site) && (int) $site > 0) {
            return (int) $site;
        }
        $site = todo_get_string('site');
        if ($site !== '' && is_numeric($site) && (int) $site > 0) {
            return (int) $site;
        }
        return (int) $default;
    }
}

if (!function_exists('todo_merge_query_context_overrides')) {
    function todo_merge_query_context_overrides($overrides)
    {
        $existing = isset($GLOBALS['todo_query_context_overrides']) && is_array($GLOBALS['todo_query_context_overrides']) ? $GLOBALS['todo_query_context_overrides'] : array();
        $GLOBALS['todo_query_context_overrides'] = array_merge($existing, is_array($overrides) ? $overrides : array());
    }
}

if (!function_exists('todo_sync_query_context')) {
    function todo_sync_query_context($overrides)
    {
        $overrides = is_array($overrides) ? $overrides : array();
        foreach ($overrides as $key => $value) {
            if (!is_string($key) || $key === '' || $value === null || is_array($value) || is_object($value)) {
                continue;
            }
            $_GET[$key] = $value;
        }
        todo_merge_query_context_overrides($overrides);
    }
}

if (!function_exists('get_search_query')) {
    function get_search_query()
    {
        if (defined('SEARCH_SENSITIVE_BLOCKED') && SEARCH_SENSITIVE_BLOCKED) {
            return '';
        }
        global $search_query;
        if (isset($search_query) && $search_query !== '') {
            return $search_query;
        }
        $context_query = todo_get_search_context();
        if ($context_query !== '') {
            return $context_query;
        }
        return '';
    }
}

/**
 * 记录搜索关键词
 *
 * @param string $keyword 搜索关键词
 * @param int $results_count 搜索结果数量
 */
function todo_log_search_keyword($keyword, $results_count = 0)
{
    if (empty($keyword)) return;

    $keyword = mb_substr(trim($keyword), 0, 255);

    // 相同关键词 10 分钟内不重复记录
    $cache_key = 'search_log_' . md5($keyword);
    $cached = function_exists('get_transient') ? get_transient($cache_key) : false;
    if ($cached) return;

    global $tododb;
    if (!$tododb || !isset($tododb->prefix)) return;

    // 1) 写入搜索明细日志表
    $log_table = TABLE_PREFIX . 'search_logs';
    if ($tododb->table_exists($log_table)) {
        $tododb->insert($log_table, [
            'keyword'       => $keyword,
            'results_count' => (int)$results_count,
            'ip_address'    => function_exists('todo_get_user_ip') ? todo_get_user_ip() : (function_exists('todo_server_string') ? todo_server_string('REMOTE_ADDR', '') : ($_SERVER['REMOTE_ADDR'] ?? ''))
        ]);
    }

    // 2) 聚合表：累加搜索次数（INSERT ... ON DUPLICATE KEY UPDATE）
    $hot_table = TABLE_PREFIX . 'search_hot';
    if ($tododb->table_exists($hot_table)) {
        $escaped = method_exists($tododb, 'escape') ? $tododb->escape($keyword) : addslashes($keyword);
        $tododb->query(
            "INSERT INTO {$hot_table} (keyword, search_count, results_count) VALUES ('{$escaped}', 1, " . (int)$results_count . ") ON DUPLICATE KEY UPDATE search_count = search_count + 1, results_count = VALUES(results_count)"
        );
    }

    if (function_exists('set_transient')) {
        set_transient($cache_key, 1, 600); // 10 分钟去重
    }
}

/**
 * 获取热搜关键词列表
 *
 * @param int $limit 返回数量
 * @return array [{keyword, search_count, is_hot}, ...]
 */
function todo_get_hot_searches($limit = 10)
{
    global $tododb;
    if (!$tododb || !isset($tododb->prefix)) return [];

    $table = TABLE_PREFIX . 'search_hot';
    if (!$tododb->table_exists($table)) return [];

    // 手动标记的热搜优先，其次按搜索次数降序
    return $tododb->get_results(
        "SELECT keyword, search_count, is_hot FROM {$table} ORDER BY is_hot DESC, search_count DESC LIMIT " . (int)$limit
    ) ?: [];
}

/**
 * 重定向到指定URL
 *
 * @param string $url 目标URL
 * @param int $status 状态码
 */
function redirect($url, $status = 302)
{
    return todo_redirect($url, $status);
}

/**
 * 获取用户元数据
 *
 * @param int $user_id 用户ID
 * @param string $meta_key 元数据键名
 * @param bool $single 是否返回单个值
 * @return mixed 元数据值
 */
function get_user_meta($user_id, $meta_key = '', $single = false)
{
    global $tododb;

    if (empty($meta_key)) {
        $query = "SELECT meta_key, meta_value FROM {$tododb->prefix}user_meta WHERE user_id = ?";
        $metas = $tododb->get_results($query, array($user_id));

        $result = array();
        if (is_array($metas)) {
            foreach ($metas as $meta) {
                if (is_object($meta)) {
                    $k = isset($meta->meta_key) ? (string) $meta->meta_key : '';
                    $v = isset($meta->meta_value) ? $meta->meta_value : '';
                } elseif (is_array($meta)) {
                    $k = isset($meta['meta_key']) ? (string) $meta['meta_key'] : '';
                    $v = isset($meta['meta_value']) ? $meta['meta_value'] : '';
                } else {
                    continue;
                }
                if ($k !== '') {
                    $result[$k] = maybe_unserialize($v);
                }
            }
        }

        return $result;
    }

    $meta_value = $tododb->get_var("SELECT meta_value FROM {$tododb->prefix}user_meta WHERE user_id = ? AND meta_key = ?", [$user_id, $meta_key]);

    if ($meta_value === null) {
        return $single ? '' : array();
    }

    $meta_value = maybe_unserialize($meta_value);

    return $single ? $meta_value : array($meta_value);
}

/**
 * 添加用户元数据
 *
 * @param int $user_id 用户ID
 * @param string $meta_key 元数据键名
 * @param mixed $meta_value 元数据值
 * @return bool 是否成功添加
 */
function add_user_meta($user_id, $meta_key, $meta_value)
{
    global $tododb;

    $meta_value = maybe_serialize($meta_value);

    return $tododb->insert(
        "{$tododb->prefix}user_meta",
        array(
            'user_id' => $user_id,
            'meta_key' => $meta_key,
            'meta_value' => $meta_value
        )
    );
}

/**
 * 更新用户元数据
 *
 * @param int $user_id 用户ID
 * @param string $meta_key 元数据键名
 * @param mixed $meta_value 元数据值
 * @return bool 是否成功更新
 */
function update_user_meta($user_id, $meta_key, $meta_value)
{
    global $tododb;

    $meta_value = maybe_serialize($meta_value);

    $meta_id = $tododb->get_var("SELECT umeta_id FROM {$tododb->prefix}user_meta WHERE user_id = ? AND meta_key = ?", [$user_id, $meta_key]);

    if ($meta_id) {
        return $tododb->update(
            "{$tododb->prefix}user_meta",
            array('meta_value' => $meta_value),
            array('umeta_id' => $meta_id)
        );
    } else {
        return $tododb->insert(
            "{$tododb->prefix}user_meta",
            array(
                'user_id' => $user_id,
                'meta_key' => $meta_key,
                'meta_value' => $meta_value
            )
        );
    }
}

/**
 * 删除用户元数据
 *
 * @param int $user_id 用户ID
 * @param string $meta_key 元数据键名
 * @param mixed $meta_value 元数据值
 * @return bool 是否成功删除
 */
function delete_user_meta($user_id, $meta_key, $meta_value = '')
{
    global $tododb;

    $where = array('user_id' => $user_id, 'meta_key' => $meta_key);

    if (!empty($meta_value)) {
        $where['meta_value'] = maybe_serialize($meta_value);
    }

    return $tododb->delete("{$tododb->prefix}user_meta", $where);
}

/**
 * 获取站点URL
 *
 * @param string $path 路径
 * @return string 完整URL
 */
if (!function_exists('site_url')) {
    function site_url($path = '')
    {
        // 统一委托到更稳健的 todo_get_site_url，避免 AJAX 环境下误拼接 '/ajax'
        if (function_exists('todo_get_site_url')) {
            return todo_get_site_url($path);
        }
        // 安全回退：基于 DOCUMENT_ROOT 与 ABSPATH 计算安装前缀
        $https_val = function_exists('todo_server_string') ? todo_server_string('HTTPS', '') : (isset($_SERVER['HTTPS']) ? (string) $_SERVER['HTTPS'] : '');
        $protocol = (!empty($https_val) && strtolower($https_val) !== 'off') ? 'https://' : 'http://';
        $host = function_exists('todo_server_string') ? todo_server_string('HTTP_HOST', 'localhost') : ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $doc_root = function_exists('todo_server_string') ? str_replace('\\', '/', rtrim(todo_server_string('DOCUMENT_ROOT', ''), '/')) : (isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/')) : '');
        $abs_path = defined('ABSPATH') ? str_replace('\\', '/', rtrim(ABSPATH, '/')) : '';
        $prefix = '';
        if ($doc_root && $abs_path && strpos($abs_path, $doc_root) === 0) {
            $relative = substr($abs_path, strlen($doc_root));
            $relative = trim($relative, '/');
            if ($relative !== '') {
                $prefix = '/' . $relative;
            }
        } else {
            // 退化处理：从脚本路径剥离 admin 及其子目录
            $script_name = function_exists('todo_server_string') ? str_replace('\\', '/', todo_server_string('SCRIPT_NAME', '')) : (isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']) : '');
            $base_path = rtrim(dirname($script_name), '/');
            // 去除 /admin 及其后续子路径（如 /admin/ajax）
            $base_path = preg_replace('#/admin(/.*)?$#', '', $base_path);
            if ($base_path === '/' || $base_path === '\\' || $base_path === '.') {
                $base_path = '';
            }
            $prefix = $base_path;
        }
        $base = $protocol . $host . $prefix;
        $base = rtrim($base, '/');
        if (!empty($path)) {
            $base .= '/' . ltrim($path, '/');
        }
        return $base;
    }
}

/**
 * 获取站点URL的别名函数
 *
 * @param string $path 路径
 * @return string 站点URL
 */
if (!function_exists('get_site_url')) {
    function get_site_url($path = '')
    {
        return site_url($path);
    }
}

/**
 * Todo URL函数 - site_url的别名
 *
 * @param string $path 路径
 * @return string 站点URL
 */
if (!function_exists('todo_url')) {
    function todo_url($path = '')
    {
        return site_url($path);
    }
}

/**
 * 获取首页URL
 *
 * @param string $path 路径
 * @return string 首页URL
 */
if (!function_exists('home_url')) {
    function home_url($path = '')
    {
        return site_url($path);
    }
}

/**
 * 获取管理后台URL
 *
 * @param string $path 路径
 * @return string 管理后台URL
 */

/**
 * 获取内容URL
 *
 * @param string $path 路径
 * @return string 内容URL
 */

/**
 * 获取主题URL
 *
 * @param string $path 路径
 * @return string 主题URL
 */
function theme_url($path = '')
{
    $theme = get_theme_name();
    return content_url('themes/' . $theme . '/' . $path);
}

/**
 * 获取上传文件URL
 *
 * @param string $path 路径
 * @return string 上传文件URL
 */
function uploads_url($path = '')
{
    return content_url('uploads/' . $path);
}

/**
 * 获取登录URL
 *
 * @param string $redirect 登录后重定向的URL
 * @return string 登录URL
 */
function get_login_url($redirect = '')
{
    // 标准化使用 ?user=login 格式
    $url = site_url('?user=login');

    if (!empty($redirect)) {
        $url .= '&redirect=' . urlencode($redirect);
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
 * 获取注册URL
 *
 * @return string 注册URL
 */
function get_register_url()
{
    // 标准化使用 ?user=register 格式
    $url = site_url('?user=register');

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
 * 获取当前用户ID
 *
 * @return int 用户ID
 */
if (!function_exists('todo_get_current_user_id')) {
    function todo_get_current_user_id()
    {
        if (!class_exists('Todo_Session_Manager')) {
            // 如果类不存在，可能是常量未定义，尝试定义后再检查
            if (!defined('TODO_LOADED')) {
                define('TODO_LOADED', true);
            }
            // 再次检查类是否存在（Composer 会自动加载）
            if (!class_exists('Todo_Session_Manager')) {
                throw new Exception('Todo_Session_Manager 类无法加载，请检查 Composer 自动加载配置');
            }
        }

        Todo_Session_Manager::init();

        $request_uri = todo_get_request_uri();
        if ($request_uri !== '' && strpos($request_uri, '/api/') !== false && function_exists('cms_api')) {
            $api = cms_api();
            if (is_object($api) && method_exists($api, 'get_current_user_id')) {
                $api_user_id = (int) $api->get_current_user_id();
                if ($api_user_id > 0) {
                    return $api_user_id;
                }
            }
        }

        // 根据上下文返回对应的用户ID
        $is_admin = (defined('IS_ADMIN') && IS_ADMIN) ||
            (defined('ADMIN_ACCESS') && ADMIN_ACCESS) ||
            (function_exists('is_admin') && is_admin());

        if ($is_admin) {
            // 后台环境，返回后台用户ID
            $admin_user_id = Todo_Session_Manager::get_admin_user_id();
            if ($admin_user_id) {
                return $admin_user_id;
            }
        } else {
            // 前台环境，返回前台用户ID
            $frontend_user_id = Todo_Session_Manager::get_frontend_user_id();
            if ($frontend_user_id) {
                return $frontend_user_id;
            }
        }
        
        return 0;
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id()
    {
        if (function_exists('todo_get_current_user_id')) {
            return todo_get_current_user_id();
        }
        return 0;
    }
}

/**
 * 检查用户是否已登录
 *
 * @return bool 是否已登录
 */
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in()
    {
        if (!class_exists('Todo_Session_Manager')) {
            // 如果类不存在，可能是常量未定义，尝试定义后再检查
            if (!defined('TODO_LOADED')) {
                define('TODO_LOADED', true);
            }
            // 再次检查类是否存在（Composer 会自动加载）
            if (!class_exists('Todo_Session_Manager')) {
                throw new Exception('Todo_Session_Manager 类无法加载，请检查 Composer 自动加载配置');
            }
        }

        Todo_Session_Manager::init();

        // 根据上下文检查对应的登录状态
        $is_admin = (defined('IS_ADMIN') && IS_ADMIN) ||
            (defined('ADMIN_ACCESS') && ADMIN_ACCESS) ||
            (function_exists('is_admin') && is_admin());

        if ($is_admin) {
            return Todo_Session_Manager::is_admin_logged_in();
        } else {
            return Todo_Session_Manager::is_frontend_logged_in();
        }
    }
}

/**
 * 获取角色权限
 *
 * @param string $role 角色名
 * @return array 权限数组
 */
function get_role_capabilities($role)
{
    $role = is_string($role) ? strtolower(trim($role)) : '';
    if ($role === 'admin') {
        $role = 'administrator';
    }

    $roles = array(
        'administrator' => array(
            'access_admin' => true,
            'manage_options' => true,
            'edit_posts' => true,
            'publish_posts' => true,
            'moderate_comments' => true,
            'manage_categories' => true,
            'manage_links' => true,
            'upload_files' => true,
            'edit_others_posts' => true,
            'edit_published_posts' => true,
            'delete_others_posts' => true,
            'delete_published_posts' => true,
            'manage_users' => true,
            'create_users' => true,
            'delete_users' => true,
            'promote_users' => true,
            'edit_theme_options' => true,
            'import' => true,
            'export' => true,
            'list_users' => true,
            'manage_plugins' => true,
            'activate_plugins' => true,
            'edit_plugins' => true,
            'install_plugins' => true,
            'update_plugins' => true,
            'delete_plugins' => true,
            'install_themes' => true,
            'update_themes' => true,
            'edit_themes' => true,
            'delete_themes' => true,
            'switch_themes' => true,
            'customize' => true,
            'manage_themes' => true,
            'update_core' => true,
            'unfiltered_html' => true,
            'view_stats' => true,
            'manage_media' => true,
            'manage_comments' => true,
            'manage_ads' => true,
            'manage_models' => true,
            'edit_models' => true,
            'manage_languages' => true,
            'manage_user_levels' => true,
            'manage_upgrade_packages' => true,
            'manage_points' => true,
        ),
        'editor' => array(
            'access_admin' => true,
            'edit_posts' => true,
            'edit_others_posts' => true,
            'edit_published_posts' => true,
            'publish_posts' => true,
            'delete_posts' => true,
            'delete_others_posts' => true,
            'delete_published_posts' => true,
            'delete_private_posts' => true,
            'edit_private_posts' => true,
            'read_private_posts' => true,

            'manage_categories' => true,
            'manage_links' => true,
            'moderate_comments' => true,
            'upload_files' => true,
            'unfiltered_html' => true,
            'view_stats' => true,
            'manage_media' => true,
            'manage_comments' => true,
        ),
        'author' => array(
            'access_admin' => true,
            'edit_posts' => true,
            'edit_published_posts' => true,
            'publish_posts' => true,
            'delete_posts' => true,
            'delete_published_posts' => true,
            'upload_files' => true,
            'manage_media' => true,
        ),
        'subscriber' => array(
            'read' => true,
        ),
    );

    return isset($roles[$role]) ? $roles[$role] : array();
}

/**
 * 检查当前用户是否有指定权限 (TodoCMS Style)
 *
 * @param string $capability 权限名称
 * @return bool 是否有权限
 */
if (!function_exists('todo_current_user_can')) {
    function todo_current_user_can($capability)
    {
        // 这一步确保了 test 账户在 class-based 系统中仍能获得 UI 显示权限
        if (defined('TODO_DEMO_ADMIN_ENABLED') && TODO_DEMO_ADMIN_ENABLED) {
            if (function_exists('current_user_can') && current_user_can($capability)) {
                return true;
            }
        }

        if (class_exists('Todo_User_Manager')) {
            return Todo_User_Manager::user_can($capability);
        }
        if (function_exists('current_user_can')) {
            return current_user_can($capability);
        }
        return false;
    }
}

/**
 * 检查当前用户是否有指定权限
 *
 * @param string $capability 权限名称
 * @return bool 是否有权限
 */
if (!function_exists('current_user_can')) {
    function current_user_can($capability, $object_id = null)
    {
        $capability = is_string($capability) ? trim($capability) : '';
        if ($capability === '') {
            return false;
        }

        if ((defined('TODO_DEMO_ADMIN_ENABLED') && TODO_DEMO_ADMIN_ENABLED) && (defined('IS_ADMIN') && IS_ADMIN)) {
            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }

            $demo_username = defined('TODO_DEMO_ADMIN_USERNAME') ? (string) TODO_DEMO_ADMIN_USERNAME : '';
            $is_demo_session = isset($_SESSION['todo_demo_admin']) && (int) $_SESSION['todo_demo_admin'] === 1;

            $is_demo_user = $is_demo_session;
            $admin_user_id = null;
            if (class_exists('Todo_Session_Manager')) {
                Todo_Session_Manager::init();
                $admin_user_id = Todo_Session_Manager::get_admin_user_id();
            }
            if (!$admin_user_id && isset($_SESSION['admin_user_id'])) {
                $admin_user_id = (int) $_SESSION['admin_user_id'];
            }

            if ($admin_user_id && class_exists('CMS_User')) {
                $u = new CMS_User($admin_user_id);
                if ($u && method_exists($u, 'get_meta') && $u->get_meta('todo_demo_admin', '') === '1') {
                    $is_demo_user = true;
                }
                if ($demo_username !== '' && $u && method_exists($u, 'get_data') && (string) $u->get_data('user_login', '') === $demo_username) {
                    $is_demo_user = true;
                }
            }

            if ($is_demo_user) {
                $allowed = [
                    'access_admin', 'read', 'view_stats',
                    'manage_options', 'manage_categories', 'manage_links', 
                    'manage_users', 'manage_plugins', 'manage_themes', 
                    'manage_models', 'manage_ads', 'manage_tools', 'import', 'export',
                    'edit_posts', 'publish_posts', 'edit_others_posts', 'edit_published_posts', 
                    'delete_posts', 'delete_others_posts', 'delete_published_posts',
                    'edit_pages', 'publish_pages', 'edit_others_pages', 'edit_published_pages', 
                    'delete_pages', 'delete_others_pages', 'delete_published_pages',
                    'moderate_comments', 'manage_comments', 'upload_files', 'unfiltered_html',
                    'edit_theme_options', 'activate_plugins', 'update_plugins', 'delete_plugins', 
                    'install_plugins', 'install_themes', 'update_themes', 'edit_themes', 
                    'delete_themes', 'update_core', 'list_users', 'create_users', 'delete_users',
                    'edit_post', 'delete_post', 'edit_page', 'delete_page',
                    'switch_themes', 'customize', 'manage_media', 'view_orders', 'manage_orders', 
                    'edit_orders', 'delete_orders', 'manage_shipping', 'manage_payment',
                    'view_members', 'manage_members', 'edit_members', 'delete_members',
                    'manage_templates', 'edit_templates', 'manage_mail', 'manage_mail_settings',
                    'manage_languages', 'edit_languages', 'manage_menus', 'manage_widgets',
                    'edit_models', 'manage_upgrade_packages', 'manage_seo', 'manage_global_vars',
                    'manage_sites', 'manage_user_levels', 'manage_points', 'manage_cache',
                    'manage_trash', 'manage_api'
                ];
                if (defined('TODO_DEMO_ALLOWED_CAPABILITIES')) {
                    $custom = constant('TODO_DEMO_ALLOWED_CAPABILITIES');
                    if (is_array($custom)) {
                        $allowed = array_values(array_unique(array_filter(array_map(function ($v) {
                            return is_string($v) ? trim($v) : '';
                        }, $custom))));
                    } elseif (is_string($custom) && trim($custom) !== '') {
                        $parts = preg_split('/[,\s]+/', $custom);
                        if (is_array($parts)) {
                            $allowed = array_values(array_unique(array_filter(array_map(function ($v) {
                                return is_string($v) ? trim($v) : '';
                            }, $parts))));
                        }
                    }
                }
                return in_array($capability, $allowed, true);
            }
        }

        // 优先使用 Todo_User_Manager（性能优化后统一使用）
        if (class_exists('Todo_User_Manager')) {
            $object_id = ($object_id !== null && is_numeric($object_id)) ? (int) $object_id : null;

            if ($object_id !== null && in_array($capability, ['edit_post', 'delete_post', 'edit_page', 'delete_page'], true)) {
                $post = function_exists('get_post') ? get_post($object_id) : null;
                if (!is_object($post)) {
                    return false;
                }

                $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
                if ($user_id <= 0) {
                    return false;
                }

                $post_author = isset($post->post_author) ? (int) $post->post_author : 0;
                $is_own = ($post_author > 0 && $post_author === $user_id);
                $post_status = isset($post->post_status) ? (string) $post->post_status : '';
                $post_type = isset($post->post_type) ? (string) $post->post_type : '';
                $is_page = ($capability === 'edit_page' || $capability === 'delete_page' || $post_type === 'page');

                $can = function ($perm) use ($user_id) {
                    return Todo_User_Manager::user_can($perm, $user_id);
                };

                if ($capability === 'edit_post' || $capability === 'edit_page') {
                    if ($is_page) {
                        if (!$can('edit_pages')) return false;
                        if (!$is_own && !$can('edit_others_pages')) return false;
                        if ($post_status === 'publish' && !$can('edit_published_pages')) return false;
                        return true;
                    }

                    if (!$can('edit_posts')) return false;
                    if (!$is_own && !$can('edit_others_posts')) return false;
                    if ($post_status === 'publish' && !$can('edit_published_posts')) return false;
                    return true;
                }

                if ($capability === 'delete_post' || $capability === 'delete_page') {
                    if ($is_page) {
                        if (!$can('delete_pages')) return false;
                        if (!$is_own && !$can('delete_others_pages')) return false;
                        if ($post_status === 'publish' && !$can('delete_published_pages')) return false;
                        return true;
                    }

                    if (!$can('delete_posts')) return false;
                    if (!$is_own && !$can('delete_others_posts')) return false;
                    if ($post_status === 'publish' && !$can('delete_published_posts')) return false;
                    return true;
                }
            }

            return Todo_User_Manager::user_can($capability);
        }

        // 回退到原有逻辑
        $user_id = get_current_user_id();

        if (!$user_id) {
            error_log("TodoCMS Error [Core]:    - 用户ID为空，返回 false");
            return false;
        }

        $user = get_user_by_id($user_id);

        if (!$user) {
            error_log("TodoCMS Error [Core]:    - 用户不存在，返回 false");
            return false;
        }

        $result = $user->has_capability($capability, $object_id);
        return $result;
    }
}

/**
 * 获取当前时间的MySQL格式
 *
 * @param string $type 日期类型
 * @param int $gmt GMT偏移
 * @return string MySQL格式的日期时间
 */
if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0)
    {
        switch ($type) {
            case 'mysql':
                return $gmt ? todo_format_utc('Y-m-d H:i:s') : date('Y-m-d H:i:s');
            case 'timestamp':
                return time();
            case 'U':
                return time();
            default:
                return $gmt ? todo_format_utc($type) : date($type);
        }
    }
}

/**
 * 获取作者文章页面URL
 */
function get_author_posts_url($author_id, $author_nicename = '')
{
    global $cms_rewrite;

    return home_url('/author/' . intval($author_id) . '/');
}

/**
 * 获取当前文章作者信息
 */
function get_the_author()
{
    global $post;
    if (!$post)
        return '';

    $user = get_userdata($post->post_author);
    return $user ? $user->display_name : '';
}

/**
 * 获取当前文章作者的元数据
 */
function get_the_author_meta($field = '', $user_id = null)
{
    global $post;

    // 如果指定了用户ID，使用指定的用户ID，否则使用当前文章的作者
    if ($user_id !== null) {
        $author_id = $user_id;
    } else {
        if (!$post)
            return '';
        $author_id = $post->post_author;
    }

    $user = get_userdata($author_id);
    if (!$user)
        return '';

    switch ($field) {
        case 'ID':
            return $user->ID;
        case 'login':
        case 'user_login':
            return $user->user_login;
        case 'nicename':
        case 'user_nicename':
            return $user->user_nicename;
        case 'email':
        case 'user_email':
            return $user->user_email;
        case 'url':
        case 'user_url':
            if (isset($user->user_url)) {
                return $user->user_url;
            } else {
                return '';
            }
        case 'display_name':
            return $user->display_name;
        case 'first_name':
            return get_user_meta($user->ID, 'first_name', true);
        case 'phone':
            return get_user_meta($user->ID, 'phone', true);
        case 'description':
            return get_user_meta($user->ID, 'description', true);
        default:
            return $user->display_name;
    }
}

/**
 * 检查文章是否需要密码
 */
function post_password_required($post = null)
{
    if (!$post) {
        global $post;
    }

    if (!$post)
        return false;

    // 如果文章没有设置密码，则不需要密码
    $post_password = get_post_meta($post->ID, 'post_password', true);
    if (empty($post_password)) {
        return false;
    }

    // 处理表单提交的密码并同步更新当前请求的 $_COOKIE
    if (isset($_POST['cms_post_password']) && $_POST['cms_post_password'] !== '') {
        $submitted_pw = trim($_POST['cms_post_password']);
        if (!headers_sent()) {
            setcookie('cms_post_password_' . COOKIEHASH, $submitted_pw, time() + 86400 * 10, '/');
        }
        $_COOKIE['cms_post_password_' . COOKIEHASH] = $submitted_pw;
    }

    // 检查是否已经提供了正确的密码
    if (isset($_COOKIE['cms_post_password_' . COOKIEHASH])) {
        $cookie = $_COOKIE['cms_post_password_' . COOKIEHASH];
        if ($cookie == $post_password) {
            return false;
        }
    }

    return true;
}

/**
 * 统一判断当前用户是否可以阅读该文章（可见性优先，其后阅读权限）
 * 返回数组：
 * - allowed: bool 是否允许
 * - requires_password: bool 是否需要密码
 * - reason: string 拒绝原因（admin_only/private/password/unknown/以及 level_only:<slug> 动态等级）
 */
function todo_can_read_post($post = null, $user_id = null)
{
    if (!$post) {
        global $post;
    }
    if (!$post) {
        return array('allowed' => true, 'requires_password' => false, 'reason' => '');
    }

    // 获取用户信息
    if ($user_id === null) {
        $user_id = get_current_user_id();
    }
    $is_admin = function_exists('todo_current_user_can') ? todo_current_user_can('manage_options') : current_user_can('manage_options');

    // 先判定可见性（_visibility 元字段）：私密/密码保护
    $visibility = get_post_meta($post->ID, '_visibility', true);
    if ($visibility === 'private') {
        // 私密优先：仅作者和管理员可见，强于任何等级条件
        $is_author = ($user_id > 0) && ($user_id == $post->post_author);
        if ($is_author || $is_admin) {
            return array('allowed' => true, 'requires_password' => false, 'reason' => '');
        }
        return array('allowed' => false, 'requires_password' => false, 'reason' => 'private');
    } elseif ($visibility === 'password') {
        // 管理员可绕过密码保护；普通用户需先通过密码门槛
        if (!$is_admin) {
            if (post_password_required($post)) {
                return array('allowed' => false, 'requires_password' => true, 'reason' => 'password');
            }
        }
        // 若已通过密码（或管理员绕过），继续判定阅读权限
    }

    // 再判定阅读权限（等级/管理员/公开）
    // 读取阅读权限（posts表字段）：避免 get_post 冲突导致缺失
    $read_permission = isset($post->read_permission) ? $post->read_permission : null;
    if ($read_permission === null) {
        if (function_exists('todo_get_post_read_permission')) {
            $read_permission = todo_get_post_read_permission($post->ID);
        } else {
            global $tododb;
            $read_permission = $tododb->get_var("SELECT read_permission FROM {$tododb->prefix}posts WHERE ID = ?", [$post->ID]);
        }
    }
    if (!$read_permission) {
        $read_permission = 'public';
    }

    $perm_allowed = true;
    $deny_reason = '';
    // 动态支持：公开、管理员、以及所有用户等级（level_slug）
    if ($read_permission === 'public') {
        $perm_allowed = true;
    } elseif ($read_permission === 'admin') {
        $perm_allowed = $is_admin;
        $deny_reason = $perm_allowed ? '' : 'admin_only';
    } else {
        // 其他值视为用户等级要求
        if ($user_id > 0) {
            // 特判：member 与 vip 兼容旧值
            if ($read_permission === 'member') {
                $perm_allowed = true; // 登录即可
            } else {
                if ($read_permission === 'vip' && function_exists('todo_user_has_privilege')) {
                    $perm_allowed = todo_user_has_privilege($user_id, 'access_vip_content');
                    $deny_reason = $perm_allowed ? '' : ('level_only:' . $read_permission);
                } else {
                    $level_manager = function_exists('todo_get_user_level_manager') ? todo_get_user_level_manager() : null;
                    if ($level_manager && method_exists($level_manager, 'get_level_by_slug')) {
                        $required = $level_manager->get_level_by_slug($read_permission);
                        $current = $level_manager->get_user_current_level($user_id);
                        if ($required && $current) {
                            $perm_allowed = intval($current->level_order) >= intval($required->level_order);
                            if (!$perm_allowed) {
                                $deny_reason = 'level_only:' . $required->level_slug; // 动态提示：需要更高等级会员
                            }
                        } else {
                            // 若无法获取等级信息，则默认允许（避免误伤）
                            $perm_allowed = true;
                        }
                    } else {
                        // 无等级管理器时默认允许
                        $perm_allowed = true;
                    }
                }
            }
        } else {
            $perm_allowed = false;
            // 未登录时按要求等级动态提示
            $deny_reason = 'level_only:' . $read_permission;
        }

        // 管理员永享覆盖权
        if ($is_admin) {
            $perm_allowed = true;
            $deny_reason = '';
        }
    }

    if (!$perm_allowed) {
        return array('allowed' => false, 'requires_password' => false, 'reason' => $deny_reason ?: 'unknown');
    }

    return array('allowed' => true, 'requires_password' => false, 'reason' => '');
}

/**
 * 未授权提示块 HTML
 */
function todo_get_permission_block_html($reason = 'member_only')
{
    $login_url = function_exists('site_url') ? site_url('login') : '/login';
    $upgrade_url = function_exists('site_url') ? site_url('user/upgrade') : '/user/upgrade';

    // 动态等级提示：reason 形如 "level_only:slug"
    if (strpos($reason, 'level_only:') === 0) {
        $slug = substr($reason, strlen('level_only:'));
        $level_name = '';
        $level_manager = function_exists('todo_get_user_level_manager') ? todo_get_user_level_manager() : null;
        if ($level_manager && method_exists($level_manager, 'get_level_by_slug')) {
            $lvl = $level_manager->get_level_by_slug($slug);
            if ($lvl && isset($lvl->level_name)) {
                $level_name = $lvl->level_name;
            }
        }
        if (!$level_name) {
            $level_name = '指定等级';
        }
        $msg = '该内容仅' . esc_html($level_name) . '可阅读。';
        // member 等级以登录为主，其它等级以升级为主；未登录也提示登录
        $actions = array();
        if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
            $actions[] = '<a href="' . esc_url($login_url) . '">登录</a>';
        }
        if ($slug !== 'member') {
            $actions[] = '<a href="' . esc_url($upgrade_url) . '">升级会员</a>';
        }
        $action = implode(' 或 ', $actions);
        return '<div class="todo-access-denied"><p>' . esc_html($msg) . '</p>' . $action . '</div>';
    }

    switch ($reason) {
        case 'member_only':
            $msg = '该内容仅会员可阅读，请先登录。';
            $action = '<a href="' . esc_url($login_url) . '">登录</a>';
            break;
        case 'vip_only':
            $msg = '该内容仅高级会员可阅读，请升级后访问。';
            $action = '<a href="' . esc_url($upgrade_url) . '">升级会员</a>';
            break;
        case 'admin_only':
            $msg = '该内容仅管理员可阅读。';
            $action = '';
            break;
        case 'private':
            $msg = '该内容为私密内容，仅作者或管理员可见。';
            $action = '';
            break;
        default:
            $msg = '暂无法访问该内容。';
            $action = '';
    }
    return '<div class="todo-access-denied"><p>' . esc_html($msg) . '</p>' . $action . '</div>';
}

/**
 * 密码表单 HTML
 *
 * @param int|null $post_id 文章ID（可选）
 * @return string 密码表单HTML
 */
function todo_get_password_form_html($post_id = null)
{
    $post_id_attr = $post_id ? ' data-post-id="' . esc_attr($post_id) . '"' : '';
    $site_url = function_exists('todo_home_url') ? todo_home_url('') : '';

    $html = '<form class="todo-password-form" method="post"' . $post_id_attr . '>'
        . '<p class="todo-password-msg">此内容受密码保护。请输入密码后查看：</p>'
        . '<div class="todo-password-input-group">'
        . '<input type="password" name="cms_post_password" required />'
        . ($post_id ? '<input type="hidden" name="post_id" value="' . esc_attr($post_id) . '" />' : '')
        . '<button type="submit">提交</button>'
        . '</div>'
        . '</form>'
        . '<script>
        (function(){
            var forms = document.querySelectorAll(".todo-password-form");
            forms.forEach(function(form){
                if(form.dataset.ajaxInit) return;
                form.dataset.ajaxInit = "1";
                
                var doFetchPost = function() {
                    var pwInput = form.querySelector("input[name=\'cms_post_password\']");
                    var postIdInput = form.querySelector("input[name=\'post_id\']");
                    if(!pwInput || !pwInput.value) return;
                    
                    var cookieName = "cms_post_password_' . (defined('COOKIEHASH') ? COOKIEHASH : '') . '";
                    document.cookie = cookieName + "=" + encodeURIComponent(pwInput.value.trim()) + "; path=/; max-age=" + (86400 * 10);
                    
                    var postId = postIdInput ? postIdInput.value : (form.dataset.postId || "");
                    if(postId && window.fetch) {
                        var btn = form.querySelector("button[type=\'submit\']");
                        if(btn) btn.disabled = true;
                        
                        var apiUrl = ' . json_encode(function_exists('todo_home_url') ? todo_home_url('api/posts/') : '/api/posts/') . ' + postId;
                        fetch(apiUrl, { credentials: "same-origin" })
                        .then(function(res){
                            return res.json().then(function(resData){
                                return { status: res.status, ok: res.ok, data: resData };
                            });
                        })
                        .then(function(resObj){
                            var resData = resObj.data;
                            if(resObj.status === 429) {
                                throw new Error(resData.message || "尝试过于频繁，请稍后再试");
                            }
                            if(resObj.status === 403 && resData && resData.require_captcha) {
                                if(window.Captcha && typeof window.Captcha.show === "function") {
                                    window.Captcha.show(function(){
                                        doFetchPost();
                                    });
                                    if(btn) btn.disabled = false;
                                    return;
                                }
                            }
                            if(resObj.status === 401) {
                                throw new Error(resData.message || "密码错误");
                            }
                            if(!resObj.ok) {
                                throw new Error(resData.message || "验证密码失败");
                            }
                            
                            var data = resData.data || resData;
                            var content = data.content || (data.post && data.post.post_content) || "";
                            if(content !== "") {
                                var container = form.closest(".post-content, .article-body, .section-content") || form.parentElement;
                                container.innerHTML = content;
                            } else {
                                window.location.reload();
                            }
                        })
                        .catch(function(err){
                            alert(err.message || "密码错误，请重试");
                            if(btn) btn.disabled = false;
                        });
                    }
                };

                form.addEventListener("submit", function(e){
                    e.preventDefault();
                    doFetchPost();
                });
            });
        })();
        </script>';
    return $html;
}

/**
 * 检查文章是否开启评论
 */
function comments_open($post_id = 0)
{
    global $post;

    if (!$post_id && $post) {
        $post_id = $post->ID;
    }

    if (!$post_id)
        return false;

    $post_data = get_post($post_id);
    if (!$post_data)
        return false;

    return $post_data->comment_status === 'open';
}

/**
 * 检查文章是否开启引用通告
 */
function pings_open($post_id = 0)
{
    global $post;

    if (!$post_id && $post) {
        $post_id = $post->ID;
    }

    if (!$post_id)
        return false;

    $post_data = get_post($post_id);
    if (!$post_data)
        return false;

    return $post_data->ping_status === 'open';
}

/**
 * 显示评论弹出链接
 */
function comments_popup_link($zero = false, $one = false, $more = false, $css_class = '', $none = false)
{
    global $post;

    if (!$post)
        return;

    $post_id = $post->ID;
    $comment_count = get_comments_number($post_id);

    if ($zero === false)
        $zero = __('Leave a comment');
    if ($one === false)
        $one = __('1 Comment');
    if ($more === false)
        $more = __('% Comments');
    if ($none === false)
        $none = __('Comments off');

    if (!comments_open($post_id) && $comment_count == 0) {
        echo '<span' . ($css_class ? ' class="' . esc_attr($css_class) . '"' : '') . '>' . $none . '</span>';
        return;
    }

    $link_url = get_permalink($post_id) . '#comments';

    if ($comment_count == 0) {
        $link_text = $zero;
    } elseif ($comment_count == 1) {
        $link_text = $one;
    } else {
        $link_text = str_replace('%', $comment_count, $more);
    }

    echo '<a href="' . esc_url($link_url) . '"' . ($css_class ? ' class="' . esc_attr($css_class) . '"' : '') . '>' . $link_text . '</a>';
}

/**
 * 加载评论模板
 *
 * @param string $file 模板文件名
 * @param bool $separate_comments 是否分离评论
 */
function comments_template($file = '/comments.php', $separate_comments = false)
{
    global $post, $withcomments, $todo_query, $id, $comment, $user_login, $user_ID, $user_identity, $overridden_cpage;

    if (!(is_single() || is_page() || $withcomments) || empty($post)) {
        return;
    }

    if (empty($file)) {
        $file = '/comments.php';
    }

    $req = get_option('require_name_email');

    $commenter = todo_get_current_commenter();

    // 设置评论相关变量
    $comments = get_comments(array(
        'post_id' => $post->ID,
        'status' => 'approve',
        'order' => 'ASC'
    ));

    $comment_count = count($comments);

    // 查找评论模板文件
    $theme_template = get_template_directory() . $file;
    if (file_exists($theme_template)) {
        include($theme_template);
    } else {
        // 如果主题没有评论模板，使用默认的
        echo '<div id="comments" class="comments-area">';
        if ($comment_count > 0) {
            echo '<h2 class="comments-title">' . $comment_count . ' 条评论</h2>';
            echo '<ol class="comment-list">';
            foreach ($comments as $comment) {
                echo '<li class="comment">';
                echo '<div class="comment-body">';
                echo '<div class="comment-meta">';
                echo '<cite class="fn">' . $comment->comment_author . '</cite>';
                echo '<time>' . $comment->comment_date . '</time>';
                echo '</div>';
                echo '<div class="comment-content">' . $comment->comment_content . '</div>';
                echo '</div>';
                echo '</li>';
            }
            echo '</ol>';
        }

        // 评论表单
        if (comments_open()) {
            echo '<div id="respond" class="comment-respond">';
            echo '<h3 id="reply-title" class="comment-reply-title">发表评论</h3>';
            echo '<form action="' . todo_url('/todo-comments-post.php') . '" method="post" id="commentform" class="comment-form">';
            echo '<p class="comment-form-comment">';
            echo '<label for="comment">评论</label>';
            echo '<textarea id="comment" name="comment" cols="45" rows="8" required></textarea>';
            echo '</p>';

            if (!is_user_logged_in()) {
                echo '<p class="comment-form-author">';
                echo '<label for="author">姓名 ' . ($req ? '<span class="required">*</span>' : '') . '</label>';
                echo '<input id="author" name="author" type="text" value="" size="30"' . ($req ? ' required' : '') . ' />';
                echo '</p>';

                echo '<p class="comment-form-email">';
                echo '<label for="email">邮箱 ' . ($req ? '<span class="required">*</span>' : '') . '</label>';
                echo '<input id="email" name="email" type="email" value="" size="30"' . ($req ? ' required' : '') . ' />';
                echo '</p>';

                echo '<p class="comment-form-url">';
                echo '<label for="url">网站</label>';
                echo '<input id="url" name="url" type="url" value="" size="30" />';
                echo '</p>';
            }

            echo '<p class="form-submit">';
            echo '<input name="submit" type="submit" id="submit" class="submit" value="发表评论" />';
            echo '<input type="hidden" name="comment_post_ID" value="' . $post->ID . '" id="comment_post_ID" />';
            echo '</p>';
            echo '</form>';
            echo '</div>';
        }

        echo '</div>';
    }
}

/**
 * 获取当前评论者信息
 *
 * @return array 评论者信息
 */
function todo_get_current_commenter()
{
    $commenter = array();

    if (isset($_COOKIE['comment_author_' . COOKIEHASH])) {
        $commenter['comment_author'] = $_COOKIE['comment_author_' . COOKIEHASH];
    }

    if (isset($_COOKIE['comment_author_email_' . COOKIEHASH])) {
        $commenter['comment_author_email'] = $_COOKIE['comment_author_email_' . COOKIEHASH];
    }

    if (isset($_COOKIE['comment_author_url_' . COOKIEHASH])) {
        $commenter['comment_author_url'] = $_COOKIE['comment_author_url_' . COOKIEHASH];
    }

    return $commenter;
}

/**
 * 显示文章导航
 *
 * @param array $args 导航参数
 */
function the_post_navigation($args = array())
{
    $navigation = get_the_post_navigation($args);
    echo $navigation;
}

/**
 * 获取文章导航
 *
 * @param array $args 导航参数
 * @return string 导航HTML
 */
function get_the_post_navigation($args = array())
{
    global $post;

    if (!$post) {
        return '';
    }

    $defaults = array(
        'prev_text' => '上一篇',
        'next_text' => '下一篇',
        'in_same_term' => false,
        'excluded_terms' => '',
        'taxonomy' => 'category',
        'screen_reader_text' => '文章导航'
    );

    $args = array_merge($defaults, $args);

    $prev_post = get_previous_post($args['in_same_term'], $args['excluded_terms'], $args['taxonomy']);
    $next_post = get_next_post($args['in_same_term'], $args['excluded_terms'], $args['taxonomy']);

    if (!$prev_post && !$next_post) {
        return '';
    }

    $navigation = '<nav class="navigation post-navigation" role="navigation">';
    $navigation .= '<h2 class="screen-reader-text">' . $args['screen_reader_text'] . '</h2>';
    $navigation .= '<div class="nav-links">';

    if ($prev_post) {
        $navigation .= '<div class="nav-previous">';
        $navigation .= '<a href="' . get_permalink($prev_post->ID) . '" rel="prev">';
        $navigation .= '<span class="meta-nav">' . $args['prev_text'] . '</span> ';
        $navigation .= '<span class="post-title">' . get_the_title($prev_post->ID) . '</span>';
        $navigation .= '</a>';
        $navigation .= '</div>';
    }

    if ($next_post) {
        $navigation .= '<div class="nav-next">';
        $navigation .= '<a href="' . get_permalink($next_post->ID) . '" rel="next">';
        $navigation .= '<span class="meta-nav">' . $args['next_text'] . '</span> ';
        $navigation .= '<span class="post-title">' . get_the_title($next_post->ID) . '</span>';
        $navigation .= '</a>';
        $navigation .= '</div>';
    }

    $navigation .= '</div>';
    $navigation .= '</nav>';

    return $navigation;
}

/**
 * 获取上一篇文章
 *
 * @param bool $in_same_term 是否在同一分类
 * @param string $excluded_terms 排除的分类
 * @param string $taxonomy 分类法
 * @return object|null 文章对象
 */
function get_previous_post($in_same_term = false, $excluded_terms = '', $taxonomy = 'category')
{
    return get_adjacent_post(true, $excluded_terms, $in_same_term, $taxonomy);
}

/**
 * 获取下一篇文章
 *
 * @param bool $in_same_term 是否在同一分类
 * @param string $excluded_terms 排除的分类
 * @param string $taxonomy 分类法
 * @return object|null 文章对象
 */
function get_next_post($in_same_term = false, $excluded_terms = '', $taxonomy = 'category')
{
    return get_adjacent_post(false, $excluded_terms, $in_same_term, $taxonomy);
}

/**
 * 获取相邻文章
 *
 * @param bool $previous 是否获取上一篇
 * @param string $excluded_terms 排除的分类
 * @param bool $in_same_term 是否在同一分类
 * @param string $taxonomy 分类法
 * @return object|null 文章对象
 */
function get_adjacent_post($previous = true, $excluded_terms = '', $in_same_term = false, $taxonomy = 'category')
{
    global $post, $tododb;

    if (!$post) {
        return null;
    }

    $op = $previous ? '<' : '>';
    $order = $previous ? 'DESC' : 'ASC';

    // 使用预处理语句防止SQL注入
    $where_conditions = ["p.post_date {$op} ?", "p.post_type = ?", "p.post_status = ?"];
    $params = [$post->post_date, 'post', 'publish'];

    if ($in_same_term && !empty($post->post_category)) {
        $where_conditions[] = "p.post_category = ?";
        $params[] = intval($post->post_category);
    }

    if (!empty($excluded_terms)) {
        $excluded_terms = explode(',', $excluded_terms);
        $excluded_terms = array_map('intval', $excluded_terms);
        $excluded_terms = array_filter($excluded_terms); // 移除无效值
        if (!empty($excluded_terms)) {
            $placeholders = str_repeat('?,', count($excluded_terms) - 1) . '?';
            $where_conditions[] = "p.post_category NOT IN ({$placeholders})";
            $params = array_merge($params, $excluded_terms);
        }
    }

    $where_clause = "WHERE " . implode(' AND ', $where_conditions);

    // 验证ORDER方向
    $order = strtoupper($order);
    $order = ($order === 'DESC') ? 'DESC' : 'ASC';

    $sql = "SELECT p.* FROM " . TABLE_PREFIX . "posts p {$where_clause} ORDER BY p.post_date {$order} LIMIT 1";

    $result = $tododb->query($sql, $params);

    if ($result && !empty($result)) {
        $adjacent_post = new stdClass();
        foreach ($result[0] as $key => $value) {
            $adjacent_post->$key = $value;
        }
        return $adjacent_post;
    }

    return null;
}

/**
 * 检查是否为错误对象
 *
 * @param mixed $thing 要检查的对象
 * @return bool 是否为错误对象
 */
function is_todo_error($thing)
{
    return $thing instanceof TODO_Error;
}

/**
 * 显示错误信息并终止脚本执行
 *
 * @param string $message 错误消息
 * @param string $title 错误标题
 * @param array $args 附加参数
 */
if (!function_exists('todo_die')) {
    function todo_die($message = '', $title = '', $args = array())
    {
        $defaults = array(
            'response' => 500,
            'back_link' => true,
        );

        $args = array_merge($defaults, $args);

        if (empty($title)) {
            $title = '系统错误';
        }

        // 设置HTTP响应码
        http_response_code($args['response']);

        // 检查是否为AJAX请求
        $is_ajax = (defined('DOING_AJAX') && DOING_AJAX) ||
            (function_exists('todo_server_string')
                ? strtolower(todo_server_string('HTTP_X_REQUESTED_WITH', '')) === 'xmlhttprequest'
                : (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'));

        if ($is_ajax) {
            // AJAX请求返回JSON格式错误
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array(
                'success' => false,
                'error' => $message,
                'title' => $title,
                'code' => $args['response']
            ));
            exit;
        }

        // 输出错误页面
        echo '<!DOCTYPE html>';
        echo '<html>';
        echo '<head>';
        echo '<meta charset="utf-8">';
        echo '<meta name="generator" content="TodoCMS">';
        echo '<title>' . esc_html($title) . '</title>';
        echo '<style>';
        echo 'body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0 auto; }';
        echo 'h1 { color: #d54e21; }';
        echo '.error-message { padding: 15px; border-left: 4px solid #d54e21; margin: 20px 0; }';
        echo '.back-link { margin-top: 20px; }';
        echo '.back-link a { text-decoration: none;}';
        echo '</style>';
        echo '</head>';
        echo '<body>';
        echo '<h1>' . esc_html($title) . '</h1>';
        echo '<div class="error-message">' . $message . '</div>';

        if ($args['back_link']) {
            echo '<div class="back-link"><a href="javascript:history.back()" class="button button-secondary">❮ 返回</a></div>';
        }

        echo '</body>';
        echo '</html>';

        // 终止脚本执行
        exit;
    }
}

/**
 * 管理员错误处理函数
 *
 * @param string $message 错误消息
 * @param string $title 错误标题
 * @param array $args 附加参数
 */
function todo_admin_die($message = '', $title = '', $args = array())
{
    $defaults = array(
        'response' => 500,
        'back_link' => true,
        'admin_style' => true,
    );

    $args = array_merge($defaults, $args);

    if (empty($title)) {
        $title = '管理员错误';
    }

    // 设置HTTP响应码
    http_response_code($args['response']);

    // 如果是AJAX请求，返回JSON
    if (defined('DOING_AJAX') && DOING_AJAX) {
        header('Content-Type: application/json');
        echo json_encode(array(
            'success' => false,
            'error' => $message,
            'title' => $title
        ));
        exit;
    }

    // 输出管理员错误页面
    echo '<!DOCTYPE html>';
    echo '<html>';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="generator" content="TodoCMS">';
    echo '<title>' . esc_html($title) . '</title>';
    echo '<style>';
    echo 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; line-height: 1.6; color: #333; padding: 20px; max-width: 800px; margin: 0 auto; background: #f1f1f1; }';
    echo '.admin-error { background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }';
    echo 'h1 { color: #d63638; border-bottom: 1px solid #ddd; padding-bottom: 15px; margin-bottom: 20px; }';
    echo '.error-message { padding: 15px; border-left: 4px solid #d54e21; margin: 20px 0; }';
    echo '.back-link { margin-top: 20px; }';
    echo '.back-link a { text-decoration: none;}';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    echo '<div class="admin-error">';
    echo '<h1>' . esc_html($title) . '</h1>';
    echo '<div class="error-message">' . $message . '</div>';

    if ($args['back_link']) {
        echo '<div class="back-link"><a href="javascript:history.back()" class="button button-secondary">❮ 返回上一页</a></div>';
    }

    echo '</div>';
    echo '</body>';
    echo '</html>';

    // 终止脚本执行
    exit;
}

/**
 * 检查核心系统更新（集成授权服务器与分发服务器双验证）
 *
 * 优先通过授权服务器验证域名授权并获取更新信息；若未配置授权服务器，则回退到模拟数据。
 *
 * 依赖配置：
 * - 站点设置（优先）：get_option('todo_license_endpoint'), get_option('todo_license_public_key')
 * - 常量回退：TODO_LICENSE_ENDPOINT, TODO_LICENSE_SERVER_PUBLIC_KEY
 *
 * @return array|bool 更新信息数组或false
 */
function check_core_updates()
{
    $current_version = function_exists('todo_get_cms_version') ? todo_get_cms_version() : (defined('TODO_VERSION') ? TODO_VERSION : '1.0.0');

    // 读取授权服务器与公钥配置
    $endpoint = function_exists('get_option') ? get_option('todo_license_endpoint') : '';
    if (!$endpoint && defined('TODO_LICENSE_ENDPOINT')) {
        $endpoint = TODO_LICENSE_ENDPOINT;
    }

    // 未配置授权服务器，保持旧的模拟返回，避免报错
    if (!$endpoint) {
        return array(
            'current' => $current_version,
            'latest' => $current_version, // 默认不提示更新
            'download_url' => '',
            'changelog' => '',
            'requires_php' => '7.4',
            'requires_mysql' => '5.6'
        );
    }

    // 使用统一的更新服务助手与双验证协议
    $pub_key = function_exists('get_option') ? get_option('todo_license_public_key') : '';
    if (!$pub_key && defined('TODO_LICENSE_SERVER_PUBLIC_KEY')) {
        $pub_key = TODO_LICENSE_SERVER_PUBLIC_KEY;
    }

    if (function_exists('todo_check_core_updates_via_license')) {
        $result = todo_check_core_updates_via_license($endpoint, $pub_key, $current_version);
        if (is_array($result)) {
            return $result;
        }
        return false;
    }

    // 回退：若助手函数不存在，则保持旧实现（最小化风险）
    // 构建客户端指纹（多维度，降低伪造可能）
    $domain = function_exists('todo_get_http_host') ? todo_get_http_host() : (function_exists('todo_server_string') ? todo_server_string('HTTP_HOST', 'localhost') : ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $fp_parts = array(
        'd' => strtolower($domain),
        'p' => defined('ABSPATH') ? ABSPATH : '',
        'v' => PHP_VERSION,
        'db' => defined('DB_HOST') ? DB_HOST : '',
        's' => defined('AUTH_KEY') ? substr(sha1(AUTH_KEY), 0, 16) : ''
    );
    $fingerprint = hash('sha256', implode('|', $fp_parts));

    $payload = array(
        'domain' => $domain,
        'cms_version' => $current_version,
        'ts' => time(),
        'nonce' => bin2hex(random_bytes(8)),
        'fingerprint' => $fingerprint
    );

    $resp = todo_http_post_json($endpoint, $payload, array(), 12);
    if (!is_array($resp['json']) || empty($resp['json']['success'])) {
        return false;
    }
    $j = $resp['json'];

    // 验证授权服务器签名（非对称加密）
    if ($pub_key && !empty($j['signature']) && !empty($j['ts']) && !empty($j['data'])) {
        $data = $j['data'];
        $ok = todo_openssl_verify_signature(array(
            'latest' => isset($data['latest']) ? $data['latest'] : '',
            'download_url' => isset($data['download_url']) ? $data['download_url'] : '',
            'ts' => $j['ts']
        ), $j['signature'], $pub_key);
        if (!$ok) {
            return false;
        }
    }

    $data = isset($j['data']) && is_array($j['data']) ? $j['data'] : array();
    return array(
        'current' => $current_version,
        'latest' => isset($data['latest']) ? $data['latest'] : $current_version,
        'download_url' => isset($data['download_url']) ? $data['download_url'] : '',
        'token_endpoint' => isset($data['token_endpoint']) ? $data['token_endpoint'] : '',
        'changelog' => isset($data['changelog']) ? $data['changelog'] : '',
        'requires_php' => isset($data['requires_php']) ? $data['requires_php'] : '7.4',
        'requires_mysql' => isset($data['requires_mysql']) ? $data['requires_mysql'] : '5.6',
        'checksum_md5' => isset($data['checksum_md5']) ? $data['checksum_md5'] : '',
        'signature' => isset($j['signature']) ? $j['signature'] : '',
        'ts' => isset($j['ts']) ? $j['ts'] : 0
    );
}

/**
 * 检查插件更新
 *
 * @return array 需要更新的插件列表
 */
function check_plugin_updates()
{
    $plugins_need_update = array();

    // 获取已安装的插件
    $installed_plugins = Todo_Plugin_Manager::get_plugins();

    // 模拟检查更新
    foreach ($installed_plugins as $plugin_file => $plugin_data) {
        // 随机模拟部分插件需要更新
        if ($plugin_file == 'language-switcher/language-switcher.php') {
            $plugins_need_update[$plugin_file] = array(
                'slug' => dirname($plugin_file),
                'plugin' => $plugin_file,
                'new_version' => '2.0.0',
                'current_version' => $plugin_data['version'],
                'url' => $plugin_data['plugin_url'],
                'package' => 'http://todocms.com/plugins/language-switcher/2.0.0.zip'
            );
        }
    }

    return $plugins_need_update;
}

/**
 * 获取查询变量值
 *
 * @param string $var 查询变量名
 * @param mixed $default 默认值
 * @return mixed 查询变量值
 */
function get_query_var($var, $default = '')
{
    global $todo_query;

    $context_value = todo_get_query_context_value($var, null);
    if ($context_value !== null) {
        return $context_value;
    }

    // 检查常见的查询变量
    switch ($var) {
        case 'paged':
            return todo_get_paged_context('paged', 1);

        case 'page':
            return todo_get_paged_context('page', 1);

        case 'post_type':
            $post_type = (string) todo_get_query_context_value('post_type', todo_get_string('post_type'));
            if ($post_type !== '') {
                return sanitize_key($post_type);
            }
            return 'post';

        case 's':
            return sanitize_text_field(todo_get_search_context());

        case 'cat':
            $cat_id = todo_get_query_context_value('cat', todo_get_int('cat'));
            if ((int) $cat_id > 0) {
                return (int) $cat_id;
            }
            return '';

        case 'category_name':
            $category_name = (string) todo_get_query_context_value('category_name', todo_get_string('category_name'));
            if ($category_name !== '') {
                return sanitize_title($category_name);
            }
            return '';

        case 'tag':
            $tag_name = (string) todo_get_query_context_value('tag', todo_get_string('tag'));
            if ($tag_name !== '') {
                return sanitize_title($tag_name);
            }
            return '';

        case 'tag_id':
            $tag_id = todo_get_query_context_value('tag_id', todo_get_int('tag_id'));
            if ((int) $tag_id > 0) {
                return (int) $tag_id;
            }
            return '';

        case 'author':
            $author_id = todo_get_query_context_value('author', todo_get_int('author'));
            if ((int) $author_id > 0) {
                return (int) $author_id;
            }
            return '';

        case 'author_name':
            $author_name = (string) todo_get_query_context_value('author_name', todo_get_string('author_name'));
            if ($author_name !== '') {
                return sanitize_title($author_name);
            }
            return '';

        case 'year':
            $year = todo_get_query_context_value('year', todo_get_int('year'));
            if ((int) $year > 0) {
                return (int) $year;
            }
            $archive_year = todo_get_query_context_value('archive_year', todo_get_int('archive_year'));
            if ((int) $archive_year > 0) {
                return (int) $archive_year;
            }
            return '';

        case 'month':
        case 'monthnum':
            $month = todo_get_query_context_value('month', todo_get_int('month'));
            if ((int) $month > 0) {
                return (int) $month;
            }
            $monthnum = todo_get_query_context_value('monthnum', todo_get_int('monthnum'));
            if ((int) $monthnum > 0) {
                return (int) $monthnum;
            }
            $archive_month = todo_get_query_context_value('archive_month', todo_get_int('archive_month'));
            if ((int) $archive_month > 0) {
                return (int) $archive_month;
            }
            return '';

        case 'day':
            $day = todo_get_query_context_value('day', todo_get_int('day'));
            if ((int) $day > 0) {
                return (int) $day;
            }
            $archive_day = todo_get_query_context_value('archive_day', todo_get_int('archive_day'));
            if ((int) $archive_day > 0) {
                return (int) $archive_day;
            }
            return '';

        default:
            $fallback = (string) todo_get_query_context_value($var, todo_get_string($var));
            return $fallback !== '' ? $fallback : $default;
    }
}

/**
 * 检查是否已安排定时任务
 *
 * @param string $hook 钩子名称
 * @return bool|int 如果已安排返回时间戳，否则返回false
 */
function todo_next_scheduled($hook)
{
    global $tododb;
    static $cache = array();
    static $all_loaded = false;
    static $table_exists = null;

    if (isset($cache[$hook])) {
        return $cache[$hook];
    }

    // 如果已经预加载过，说明该hook没有计划任务
    if ($all_loaded) {
        return false;
    }

    // 检查表是否存在（仅检查一次）
    if ($table_exists === null) {
        if (!$tododb || !is_object($tododb)) {
            $table_exists = false;
            $cache[$hook] = false;
            return false;
        }
        $table_exists = $tododb->table_exists(TABLE_PREFIX . 'cron_jobs');
    }

    if (!$table_exists) {
        $cache[$hook] = false;
        return false;
    }

    // 预加载所有活动的定时任务
    if (!$all_loaded) {
        // 检查数据库连接
        if (!$tododb || !is_object($tododb)) {
            $all_loaded = true;
            $cache[$hook] = false;
            return false;
        }
        // 一次性查询所有活动任务，按执行时间排序
        $results = $tododb->get_results("SELECT hook, next_run FROM " . TABLE_PREFIX . "cron_jobs WHERE status = 'active' ORDER BY next_run ASC");

        if ($results) {
            foreach ($results as $row) {
                // 仅保留最早的一个时间（因为SQL已经排序，第一个遇到的即为最早）
                if (!isset($cache[$row->hook])) {
                    $cache[$row->hook] = strtotime($row->next_run);
                }
            }
        }
        $all_loaded = true;
    }

    // 再次检查缓存
    if (isset($cache[$hook])) {
        return $cache[$hook];
    }

    $cache[$hook] = false;
    return false;
}

/**
 * 安排定时任务
 *
 * @param int $timestamp 执行时间戳
 * @param string $recurrence 重复频率（hourly, daily, weekly）
 * @param string $hook 钩子名称
 * @param array $args 参数
 * @return bool 是否成功
 */
function todo_schedule_event($timestamp, $recurrence, $hook, $args = array())
{
    global $tododb;

    // 检查数据库连接是否存在
    if (!$tododb || !is_object($tododb)) {
        return false;
    }

    // 计算下次执行时间
    $next_run = date('Y-m-d H:i:s', $timestamp);

    // 计算重复间隔
    $interval = 0;
    switch ($recurrence) {
        case 'hourly':
            $interval = HOUR_IN_SECONDS;
            break;
        case 'daily':
            $interval = DAY_IN_SECONDS;
            break;
        case 'weekly':
            $interval = WEEK_IN_SECONDS;
            break;
    }

    // 检查表是否存在
    if (!method_exists($tododb, 'table_exists') || !$tododb->table_exists(TABLE_PREFIX . 'cron_jobs')) {
        return false;
    }

    return $tododb->insert(
        TABLE_PREFIX . "cron_jobs",
        array(
            'hook' => $hook,
            'next_run' => $next_run,
            'schedule' => $recurrence,
            'args' => serialize($args),
            'status' => 'active',
            'created_at' => current_time('mysql')
        )
    );
}

/**
 * 获取站点首页URL
 *
 * @param string $path 可选路径
 * @return string 首页URL
 */
if (!function_exists('todo_home_url')) {
    function todo_home_url($path = '')
    {
        $url = rtrim(get_site_url(), '/');
        if (!empty($path)) {
            $url .= '/' . ltrim($path, '/');
        }
        return $url;
    }
}

/**
 * 获取管理后台URL
 *
 * @param string $path 可选路径
 * @return string 管理后台URL
 */
if (!function_exists('admin_url')) {
    function admin_url($path = '')
    {
        // 兼容旧调用：统一委托到 TodoCMS 的 URL 构造函数
        return todo_admin_url($path);
    }
}

/**
 * 获取当前主题目录URI
 *
 * @return string 主题目录URI
 */
function todo_get_template_directory_uri()
{
    $theme = get_theme_name();
    return rtrim(get_site_url(), '/') . '/content/themes/' . $theme;
}

/**
 * 获取当前查询对象
 *
 * @return object|null
 */
function todo_get_queried_object()
{
    // 这里需要根据实际情况返回当前查询的对象
    // 暂时返回一个空对象
    return (object) array('name' => '', 'description' => '');
}

/**
 * 获取当前查询对象ID
 *
 * @return int
 */
function todo_get_queried_object_id()
{
    if (function_exists('is_category') && is_category()) {
        $cat_id = get_query_var('cat');
        if ($cat_id) {
            return intval($cat_id);
        }
        // 如果是分类名称，需要通过名称获取ID
        $cat_name = get_query_var('category_name');
        if ($cat_name) {
            $category = get_category_by_slug($cat_name);
            if (is_array($category) && isset($category['term_id'])) {
                return intval($category['term_id']);
            }
            if (is_object($category) && isset($category->term_id)) {
                return intval($category->term_id);
            }
            return 0;
        }
    } elseif (function_exists('is_tag') && is_tag()) {
        $tag_id = get_query_var('tag_id');
        if ($tag_id) {
            return intval($tag_id);
        }
        // 如果是标签名称，需要通过名称获取ID
        $tag_name = get_query_var('tag');
        if ($tag_name) {
            $tag = get_tag_by_slug($tag_name);
            return $tag ? $tag['tag_id'] : 0;
        }
    } elseif ((function_exists('is_single') && is_single()) || (function_exists('is_page') && is_page())) {
        global $post;
        return $post ? $post->ID : 0;
    } elseif (function_exists('is_author') && is_author()) {
        $author_id = get_query_var('author');
        return $author_id ? intval($author_id) : 0;
    }

    return 0;
}

/**
 * 获取当前查询对象ID (别名函数)
 *
 * @return int
 */
function get_queried_object_id()
{
    return todo_get_queried_object_id();
}

/**
 * 获取搜索查询字符串
 *
 * @return string
 */
function todo_get_search_query()
{
    $query = get_query_var('s', '');
    if ($query === '' && function_exists('get_search_query')) {
        $query = get_search_query();
    }
    if ($query === '') {
        $query = todo_get_search_context();
    }
    return $query;
}

/**
 * 通过别名获取分类信息
 *
 * @param string $slug 分类别名
 * @return object|null
 */
function get_category_by_slug($slug)
{
    return Todo_Category_Manager::get_category_by_slug($slug);
}

/**
 * 通过别名获取标签信息
 *
 * @param string $slug 标签别名
 * @return array|null
 */
function get_tag_by_slug($slug)
{
    return Todo_Tag_Manager::get_tag_by_slug($slug);
}

/**
 * 获取文章标题
 *
 * @param int $post_id 文章ID
 * @return string 文章标题
 */
function todo_get_the_title($post_id = null)
{
    global $post;
    if (!$post_id && $post) {
        $post_id = $post->ID;
    }
    if (!$post_id) {
        return '';
    }

    // 优先使用多语言聚合器
    if (function_exists('todo_get_post_multilang')) {
        try {
            $ml = todo_get_post_multilang($post_id, null, ['fallback' => true]);
            if (is_array($ml) && !empty($ml['title'])) {
                return $ml['title'];
            }
        } catch (Exception $e) {
            // 忽略聚合异常，回退到数据库读取
            error_log('todo_get_the_title i18n error: ' . $e->getMessage());
        }
    }

    global $tododb;
    $title = $tododb->get_var("SELECT post_title FROM {$tododb->prefix}posts WHERE ID = ?", [$post_id]);
    return $title ?: '';
}

/**
 * 获取文章标签
 *
 * @param int $post_id 文章ID
 * @return array 标签数组
 */
function todo_get_the_tags($post_id = null)
{
    global $tododb;
    if (!$post_id) {
        $todo_post = isset($GLOBALS['todo_post']) && is_object($GLOBALS['todo_post']) ? $GLOBALS['todo_post'] : null;
        if ($todo_post && isset($todo_post->ID)) {
            $post_id = $todo_post->ID;
        }
    }
    if (!$post_id) {
        return array();
    }
    $ttl = function_exists('todo_get_fragment_cache_ttl') ? (int) todo_get_fragment_cache_ttl() : 120;
    $lang = function_exists('todo_get_current_language') ? todo_get_current_language() : (function_exists('get_current_language') ? get_current_language() : null);
    $cache_key = 'post_tags_' . $post_id;
    if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
        $cached = Todo_Cache_Manager::get($cache_key, 'tags', $lang);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }
    }

    // 优先使用新的标签系统 (CMS_Tags_Manager)，利用 eager loading 缓存
    if (function_exists('todo_get_post_tags')) {
        $tags = todo_get_post_tags($post_id);
        if (!empty($tags)) {
            if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set($cache_key, $tags, $ttl, 'tags', $lang);
            }
            return $tags;
        }
    } elseif (class_exists('CMS_Tags_Manager')) {
        $tm = new CMS_Tags_Manager();
        $raw_tags = $tm->get_post_tags($post_id);
        if (!empty($raw_tags)) {
            $tags = [];
            foreach ($raw_tags as $tag) {
                $tag_obj = new stdClass();
                $tag_obj->term_id = $tag['tag_id'];
                $tag_obj->name = $tag['tag_title'];
                $tag_obj->slug = $tag['tag_slug'];
                $tag_obj->description = isset($tag['tag_desc']) ? $tag['tag_desc'] : '';
                $tag_obj->count = isset($tag['post_count']) ? $tag['post_count'] : 0;
                $tags[] = $tag_obj;
            }
            if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set($cache_key, $tags, $ttl, 'tags', $lang);
            }
            return $tags;
        }
    }

    // 回退到旧的分类法系统
    // 回退到旧的分类法系统
    // 性能优化：使用 ORM 缓存
    if (class_exists('Todo_Post_ORM')) {
        $orm = new Todo_Post_ORM(['ID' => $post_id], true);
        $terms = $orm->get_terms('post_tag');
        if (!empty($terms)) {
            $tags = [];
            foreach ($terms as $t) {
                $obj = (object) $t;
                // 确保字段完整
                if (!isset($obj->count))
                    $obj->count = 0;
                if (!isset($obj->description))
                    $obj->description = '';
                $tags[] = $obj;
            }
            if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set($cache_key, $tags, $ttl, 'tags', $lang);
            }
            return $tags;
        }
    }

    $query = "SELECT t.term_id, t.name, t.slug, tt.description, tt.count FROM {$tododb->prefix}terms t INNER JOIN {$tododb->prefix}term_taxonomy tt ON t.term_id = tt.term_id INNER JOIN {$tododb->prefix}term_relationships tr ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = ? AND tt.taxonomy = 'post_tag' ORDER BY t.name";
    $results = $tododb->get_results($query, array($post_id));
    if (!empty($results)) {
        $tags = array();
        foreach ($results as $tag) {
            $tags[] = (object) array(
                'term_id' => $tag->term_id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'description' => $tag->description,
                'count' => $tag->count
            );
        }
        if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::set($cache_key, $tags, $ttl, 'tags', $lang);
        }
        return $tags;
    }

    // 最后的 SQL 回退（针对新系统但没有 helper function 的情况）
    $fallback = $tododb->get_results("SELECT t.tag_id, t.tag_title, t.tag_slug, t.tag_desc, ts.post_count FROM {$tododb->prefix}tags t LEFT JOIN {$tododb->prefix}tag_stats ts ON t.tag_id = ts.tag_id INNER JOIN {$tododb->prefix}post_tags pt ON pt.tag_id = t.tag_id WHERE pt.post_id = ? ORDER BY t.tag_title", array($post_id));
    $tags = array();
    if (!empty($fallback)) {
        foreach ($fallback as $row) {
            $tags[] = (object) array(
                'term_id' => isset($row->tag_id) ? $row->tag_id : 0,
                'name' => isset($row->tag_title) ? $row->tag_title : '',
                'slug' => isset($row->tag_slug) ? $row->tag_slug : '',
                'description' => isset($row->tag_desc) ? $row->tag_desc : '',
                'count' => isset($row->post_count) ? $row->post_count : 0
            );
        }
        if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::set($cache_key, $tags, $ttl, 'tags', $lang);
        }
    }
    return $tags;
}

/**
 * 获取文章分类
 *
 * @param int $post_id 文章ID
 * @return array 分类数组
 */
function todo_get_the_category($post_id = null)
{
    global $tododb, $post;
    if (!$post_id && $post && isset($post->ID)) {
        $post_id = $post->ID;
    }
    $post_id = intval($post_id);
    if ($post_id <= 0) {
        return array();
    }

    $ttl = function_exists('todo_get_fragment_cache_ttl') ? (int) todo_get_fragment_cache_ttl() : 120;
    $lang = function_exists('todo_get_current_language') ? todo_get_current_language() : (function_exists('get_current_language') ? get_current_language() : null);
    $cache_key = 'post_categories_' . $post_id;
    if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
        $cached = Todo_Cache_Manager::get($cache_key, 'categories', $lang);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }
    }

    if (function_exists('todo_get_post_categories')) {
        $cats = todo_get_post_categories($post_id);
        $cats = is_array($cats) ? $cats : array();
        foreach ($cats as $i => $c) {
            if (is_array($c)) {
                $c = (object) $c;
            }
            if (!is_object($c)) {
                unset($cats[$i]);
                continue;
            }
            if (!isset($c->term_id) && isset($c->id)) {
                $c->term_id = intval($c->id);
            }
            if (!isset($c->slug) && isset($c->category_slug)) {
                $c->slug = (string) $c->category_slug;
            }
            if (!isset($c->name) && isset($c->category_name)) {
                $c->name = (string) $c->category_name;
            }
            if (isset($c->name) && is_string($c->name)) {
                if (function_exists('todo_parse_inline_translations')) {
                    $c->name = todo_parse_inline_translations($c->name);
                }
                if (function_exists('todo_translate')) {
                    $c->name = todo_translate($c->name);
                }
            }
            if (!isset($c->description)) {
                $c->description = '';
            }
            if (!isset($c->count)) {
                $c->count = 0;
            }
            $cats[$i] = $c;
        }
        $cats = array_values($cats);

        if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::set($cache_key, $cats, $ttl, 'categories', $lang);
        }
        return $cats;
    }

    $query = "SELECT t.term_id, t.name, t.slug, tt.description, tt.count FROM {$tododb->prefix}terms t INNER JOIN {$tododb->prefix}term_taxonomy tt ON t.term_id = tt.term_id INNER JOIN {$tododb->prefix}term_relationships tr ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = ? AND tt.taxonomy = 'category' ORDER BY t.name";
    $results = $tododb->get_results($query, array($post_id));
    $categories = array();
    if (!empty($results)) {
        foreach ($results as $category) {
            $cat_name = $category->name;
            if (function_exists('todo_parse_inline_translations')) {
                $cat_name = todo_parse_inline_translations($cat_name);
            }
            if (function_exists('todo_translate')) {
                $cat_name = todo_translate($cat_name);
            }
            $categories[] = (object) array(
                'term_id' => $category->term_id,
                'name' => $cat_name,
                'slug' => $category->slug,
                'description' => $category->description,
                'count' => $category->count
            );
        }
    }

    if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
        Todo_Cache_Manager::set($cache_key, $categories, $ttl, 'categories', $lang);
    }
    return $categories;
}

/**
 * 获取文章永久链接
 *
 * @param int $post_id 文章ID
 * @return string 永久链接
 */
function todo_get_permalink($post_id = null)
{
    global $post;
    if (!$post_id && $post) {
        $post_id = $post->ID;
    }

    // 基础URL
    if (!$post_id) {
        $url = todo_home_url();
    } else if (function_exists('get_permalink')) {
        $url = get_permalink($post_id);
    } else {
        // 兜底：查询字符串形式
        $url = todo_home_url('?p=' . $post_id);
    }

    // 语言前缀处理：统一通过 get_language_url 注入当前语言
    $lang = null;
    if (function_exists('current_locale')) {
        $lang = current_locale();
    } elseif (function_exists('get_current_language')) {
        $lang = get_current_language();
    }
    if (!empty($lang) && function_exists('get_language_url')) {
        $url = get_language_url($lang, $url);
    }

    return $url;
}

/**
 * 获取分类链接
 *
 * @param int $category_id 分类ID
 * @return string 分类链接
 */
function todo_get_category_link($category_id)
{
    $category_id = intval($category_id);

    if ($category_id > 0 && class_exists('Todo_Model_Manager') && method_exists('Todo_Model_Manager', 'get_category_model')) {
        $model_key = Todo_Model_Manager::get_category_model($category_id);
        if ($model_key === 'single') {
            $post_id = function_exists('todo_get_single_category_post_id') ? todo_get_single_category_post_id($category_id, 'frontend') : 0;
            if ($post_id > 0 && function_exists('todo_get_permalink')) {
                return todo_get_permalink($post_id);
            }
        }
    }

    $structure = function_exists('get_option') ? get_option('permalink_structure', '') : '';
    if (empty($structure)) {
        $url = todo_home_url('?cat=' . $category_id);
        if (function_exists('get_current_language') && function_exists('get_language_url')) {
            $lang = get_current_language();
            if (!empty($lang)) {
                $url = get_language_url($lang, $url);
            }
        }
        return $url;
    }
    global $tododb;
    $row = $tododb->get_row(
        "SELECT t.slug FROM " . $tododb->prefix . "terms t INNER JOIN " . $tododb->prefix . "term_taxonomy tt ON t.term_id = tt.term_id WHERE t.term_id = ? AND tt.taxonomy = 'category'",
        [$category_id]
    );
    $slug = ($row && isset($row->slug)) ? $row->slug : 'category';
    $path = '/' . trim($slug, '/') . '/';
    $url = todo_home_url($path);
    if (function_exists('get_current_language') && function_exists('get_language_url')) {
        $lang = get_current_language();
        if (!empty($lang)) {
            $url = get_language_url($lang, $url);
        }
    }
    return $url;
}

/**
 * 获取标签链接
 *
 * @param int $tag_id 标签ID
 * @return string 标签链接
 */
function todo_get_tag_link($tag_id)
{
    $tag_id = intval($tag_id);
    $structure = function_exists('get_option') ? get_option('permalink_structure', '') : '';
    if (empty($structure)) {
        $url = todo_home_url('?page=tag&id=' . $tag_id);
        if (function_exists('get_current_language') && function_exists('get_language_url')) {
            $lang = get_current_language();
            if (!empty($lang)) {
                $url = get_language_url($lang, $url);
            }
        }
        return $url;
    }
    global $tododb;
    $row = $tododb->get_row("SELECT tag_slug, tag_title FROM {$tododb->prefix}tags WHERE tag_id = ? LIMIT 1", array($tag_id));
    $slug = ($row && isset($row->tag_slug)) ? (string) $row->tag_slug : '';
    if ($slug === '' && $row && isset($row->tag_title)) {
        $slug = (string) $row->tag_title;
    }
    if ($slug === '') {
        $slug = (string) $tag_id;
    }
    $slug = trim(str_replace('/', '-', $slug));
    $path = '/tag/' . rawurlencode($slug) . '/';
    $url = todo_home_url($path);
    if (function_exists('get_current_language') && function_exists('get_language_url')) {
        $lang = get_current_language();
        if (!empty($lang)) {
            $url = get_language_url($lang, $url);
        }
    }
    return $url;
}

/**
 * 添加查询参数
 *
 * @param array|string $key 参数键或参数数组
 * @param string $value 参数值
 * @param string $url URL
 * @return string 带参数的URL
 */
function todo_add_query_arg($key, $value = null, $url = null)
{
    if (is_null($url)) {
        $url = todo_get_request_uri();
    }

    $parsed_url = parse_url($url);
    $query = array();

    if (isset($parsed_url['query'])) {
        parse_str($parsed_url['query'], $query);
    }

    if (is_array($key)) {
        $query = array_merge($query, $key);
    } else {
        $query[$key] = $value;
    }

    $parsed_url['query'] = http_build_query($query);

    return (isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '') .
        (isset($parsed_url['host']) ? $parsed_url['host'] : '') .
        (isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '') .
        (isset($parsed_url['path']) ? $parsed_url['path'] : '') .
        (isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '') .
        (isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '');
}

/**
 * 获取用户余额
 * TodoCMS的用户余额获取函数
 *
 * @param int $user_id 用户ID
 * @return float 用户余额
 */
if (!function_exists('todo_get_user_balance')) {
    function todo_get_user_balance($user_id)
    {
        global $tododb;

        if (!$user_id) {
            return 0;
        }

        $table_name = $tododb->prefix . 'member_balance';
        $query = "SELECT SUM(amount) FROM $table_name WHERE user_id = ?";
        $balance = $tododb->get_var($query, array($user_id));

        return $balance ? floatval($balance) : 0;
    }
}

/**
 * 更新用户总余额（存储在用户元数据中）
 * TodoCMS的用户余额更新函数
 *
 * @param int $user_id 用户ID
 * @return float 更新后的余额
 */
if (!function_exists('todo_update_user_total_balance')) {
    function todo_update_user_total_balance($user_id)
    {
        $balance = todo_get_user_balance($user_id);

        if (function_exists('update_user_meta')) {
            update_user_meta($user_id, 'todo_balance', $balance);
        }

        return $balance;
    }
}

/**
 * 检查用户余额是否足够
 * TodoCMS的余额检查函数
 *
 * @param int $user_id 用户ID
 * @param float $amount 需要的金额
 * @return bool 余额是否足够
 */
if (!function_exists('todo_check_user_balance')) {
    function todo_check_user_balance($user_id, $amount)
    {
        $balance = todo_get_user_balance($user_id);
        return $balance >= $amount;
    }
}

/**
 * 扣减用户余额
 */
if (!function_exists('todo_core_deduct_user_balance')) {
    function todo_core_deduct_user_balance($user_id, $amount, $type = 'consume', $description = '', $order_id = 0)
    {
        global $tododb;
        $user_id = intval($user_id);
        $amount = abs(floatval($amount));
        
        if ($amount <= 0) return true;
        
        if (!todo_check_user_balance($user_id, $amount)) {
            return false;
        }
        
        $cur_balance = todo_get_user_balance($user_id);
        $new_balance = $cur_balance - $amount;

        $table_name = $tododb->prefix . 'member_balance';
        $result = $tododb->insert($table_name, [
            'user_id' => $user_id,
            'amount' => -$amount,
            'balance_after' => $new_balance,
            'type' => $type,
            'description' => $description,
            'order_id' => $order_id > 0 ? $order_id : null,
            'created_at' => current_time('mysql')
        ]);
        
        if ($result) {
            todo_update_user_total_balance($user_id);
            return true;
        }
        
        return false;
    }
}

/**
 * 增加用户余额
 */
if (!function_exists('todo_core_add_user_balance')) {
    function todo_core_add_user_balance($user_id, $amount, $type = 'recharge', $description = '', $order_id = 0)
    {
        global $tododb;
        $user_id = intval($user_id);
        $amount = abs(floatval($amount));
        
        if ($amount <= 0) return true;
        
        $cur_balance = todo_get_user_balance($user_id);
        $new_balance = $cur_balance + $amount;

        $table_name = $tododb->prefix . 'member_balance';
        $result = $tododb->insert($table_name, [
            'user_id' => $user_id,
            'amount' => $amount,
            'balance_after' => $new_balance,
            'type' => $type,
            'description' => $description,
            'order_id' => $order_id > 0 ? $order_id : null,
            'created_at' => current_time('mysql')
        ]);
        
        if ($result) {
            todo_update_user_total_balance($user_id);
            return true;
        }
        
        return false;
    }
}

/**
 * TodoCMS的JSON响应函数
 * 发送JSON响应到AJAX请求
 *
 * @param mixed $response 响应数据
 * @param int $status_code HTTP状态码
 */
if (!function_exists('todo_send_json')) {
    function todo_send_json($response, $status_code = null)
    {
        header('Content-Type: application/json; charset=UTF-8');
        if ($status_code !== null) {
            http_response_code($status_code);
        }
        echo json_encode($response);
        exit;
    }
}

/**
 * 发送AJAX成功响应
 */
if (!function_exists('todo_send_json_success')) {
    function todo_send_json_success($data = null, $status_code = 200)
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        if ($status_code) {
            http_response_code($status_code);
        }
        if (function_exists('todo_ensure_utf8')) {
            $data = todo_ensure_utf8($data);
        }
        $json = json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false) {
            $json = json_encode(['success' => true, 'data' => []], JSON_UNESCAPED_UNICODE);
        }
        // PRG 幂等守卫：缓存成功响应（无激活守卫时无副作用）
        if (class_exists('Todo_PRG_Guard', false)) {
            Todo_PRG_Guard::capture(['success' => true, 'data' => $data]);
        }
        echo $json;
        exit;
    }
}

/**
 * 发送AJAX错误响应
 */
if (!function_exists('todo_send_json_error')) {
    function todo_send_json_error($message = '', $code = 400, $data = array())
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($code);

        $response = array(
            'success' => false,
            'error' => $message,
            'code' => $code
        );

        if (!empty($data)) {
            $response['data'] = $data;
        }

        if (function_exists('todo_ensure_utf8')) {
            $response = todo_ensure_utf8($response);
        }

        $json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false) {
            $json = json_encode(['success' => false, 'error' => (string)$message, 'code' => $code], JSON_UNESCAPED_UNICODE);
        }
        echo $json;
        exit;
    }
}

/**
 * 发送通用AJAX JSON响应
 */
if (!function_exists('todo_send_json')) {
    function todo_send_json($response, $status_code = 200)
    {
        header('Content-Type: application/json; charset=UTF-8');
        if ($status_code) {
            http_response_code($status_code);
        }
        echo json_encode($response);
        exit;
    }
}

/**
 * 递归创建目录
 * TodoCMS函数
 *
 * @param string $target 目标目录路径
 * @return bool 是否成功创建
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
                $folder_parts = explode('/', ltrim($target, '/'));
                for ($i = 1, $c = count($folder_parts); $i <= $c; $i++) {
                    $dir = '/' . implode('/', array_slice($folder_parts, 0, $i));
                    if (@is_dir($dir)) {
                        @chmod($dir, $dir_perms);
                    }
                }
            }

            return true;
        }

        return false;
    }
}

/**
 * 检查是否为流URL
 * TodoCMS函数
 *
 * @param string $path 路径
 * @return bool 是否为流URL
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
 * 获取上传目录路径
 * TodoCMS的上传目录获取函数
 *
 * @param string $subdir 子目录
 * @return string 上传目录路径
 */
if (!function_exists('todo_get_upload_dir')) {
    function todo_get_upload_dir($subdir = '', $create_dir = false)
    {
        // 使用现有的 todo_upload_dir 函数
        if (function_exists('todo_upload_dir')) {
            $upload_info = todo_upload_dir($create_dir);
            $upload_dir = $upload_info['path'];
        } else {
            // 备用方案
            $upload_dir = ABSPATH . 'content/uploads';

            // 创建基于年月的子目录
            $year = date('Y');
            $month = date('m');
            $upload_dir .= '/' . $year . '/' . $month;
        }

        // 添加子目录
        if (!empty($subdir)) {
            $upload_dir .= '/' . trim($subdir, '/');
        }

        // 仅在明确要求时创建目录
        if ($create_dir && !file_exists($upload_dir)) {
            todo_mkdir_p($upload_dir);
        }

        return $upload_dir;
    }
}

/**
 * 获取上传目录URL
 * TodoCMS的上传URL获取函数
 *
 * @param string $subdir 子目录
 * @return string 上传目录URL
 */
if (!function_exists('todo_get_upload_url')) {
    function todo_get_upload_url($subdir = '')
    {
        $upload_info = todo_upload_dir(false);
        $upload_url = $upload_info['url'];

        // 添加子目录
        if (!empty($subdir)) {
            $upload_url .= '/' . trim($subdir, '/');
        }

        return $upload_url;
    }
}

/**
 * TodoCMS的错误终止函数
 */
if (!function_exists('todo_die')) {
    function todo_die($message = '', $title = '', $args = array())
    {
        todo_die($message, $title, $args);
    }
}

/**
 * TodoCMS函数
 * 生成HTTP referer隐藏字段
 *
 * @param bool $echo 是否直接输出
 * @return string 生成的HTML字段
 */
if (!function_exists('todo_referer_field')) {
    function todo_referer_field($echo = true)
    {
        $referer_field = todo_get_referer_field();

        if ($echo) {
            echo $referer_field;
        }

        return $referer_field;
    }
}

/**
 * TodoCMS函数
 * 获取HTTP referer隐藏字段HTML
 *
 * @return string 生成的HTML字段
 */
if (!function_exists('todo_get_referer_field')) {
    function todo_get_referer_field()
    {
        $referer = function_exists('todo_server_string') ? todo_server_string('HTTP_REFERER', '') : (isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '');
        if ($referer !== '') {
            $referer = esc_url($referer);
        } else {
            $referer = esc_url(todo_get_request_uri());
        }

        return '<input type="hidden" name="_todocms_http_referer" value="' . esc_attr($referer) . '" />';
    }
}

/**
 * 获取评论摘要
 * TodoCMS的评论摘要生成函数
 *
 * @param int|object $comment 评论ID或评论对象
 * @param int $length 摘要长度，默认100字符
 * @return string 评论摘要
 */
if (!function_exists('get_comment_excerpt')) {
    function get_comment_excerpt($comment, $length = 100)
    {
        // 如果传入的是评论ID，获取评论对象
        if (is_numeric($comment)) {
            $comment_manager = new CMS_Comment_Manager();
            $comment = $comment_manager->get_comment($comment);
        }

        // 如果没有获取到评论，返回空字符串
        if (!$comment) {
            return '';
        }

        // 获取评论内容
        $content = '';
        if (is_object($comment)) {
            if (isset($comment->comment_content)) {
                $content = $comment->comment_content;
            } elseif (method_exists($comment, 'get_content')) {
                $content = $comment->get_content();
            }
        } elseif (is_array($comment)) {
            if (isset($comment['comment_content'])) {
                $content = $comment['comment_content'];
            }
        }

        // 如果内容为空，返回空字符串
        if (empty($content)) {
            return '';
        }

        // 清理HTML标签
        $content = strip_tags($content ?? '');

        // 清理多余的空白字符
        $content = preg_replace('/\s+/', ' ', $content ?? '');
        $content = trim($content);

        // 如果内容长度小于等于指定长度，直接返回
        if (mb_strlen($content, 'UTF-8') <= $length) {
            return $content;
        }

        // 截取指定长度的内容
        $excerpt = mb_substr($content, 0, $length, 'UTF-8');

        // 确保不在单词中间截断（对于英文）
        if (preg_match('/[a-zA-Z]$/', $excerpt) && preg_match('/^[a-zA-Z]/', mb_substr($content, $length, 1, 'UTF-8'))) {
            $last_space = mb_strrpos($excerpt, ' ', 0, 'UTF-8');
            if ($last_space !== false && $last_space > $length * 0.8) {
                $excerpt = mb_substr($excerpt, 0, $last_space, 'UTF-8');
            }
        }

        // 添加省略号
        $excerpt .= '...';

        return $excerpt;
    }
}

/**
 * TodoCMS的文本截取函数
 * 按单词数量截取文本
 *
 * @param string $text 要截取的文本
 * @param int $num_words 单词数量，默认55
 * @param string $more 更多文本的指示符，默认'...'
 * @return string 截取后的文本
 */
if (!function_exists('todo_trim_words')) {
    function todo_trim_words($text, $num_words = 55, $more = '...')
    {
        if (empty($text)) {
            return '';
        }

        // 移除HTML标签
        $text = strip_tags($text ?? '');

        // 清理多余的空白字符
        $text = preg_replace('/\s+/', ' ', $text ?? '');
        $text = trim($text);

        // 如果文本为空，返回空字符串
        if (empty($text)) {
            return '';
        }

        // 分割文本为单词数组
        $words = explode(' ', $text ?? '');

        // 如果单词数量小于等于指定数量，直接返回原文本
        if (count($words) <= $num_words) {
            return $text;
        }

        // 截取指定数量的单词
        $trimmed_words = array_slice($words, 0, $num_words);
        $trimmed_text = implode(' ', $trimmed_words);

        // 添加更多文本指示符
        if (!empty($more)) {
            $trimmed_text .= $more;
        }

        return $trimmed_text;
    }
}

/**
 * TodoCMS的文本截取函数别名
 * 为了保持向后兼容性，提供 todo_trim_words 别名
 *
 * @param string $text 要截取的文本
 * @param int $num_words 单词数量，默认55
 * @param string $more 更多文本的指示符，默认'...'
 * @return string 截取后的文本
 */
if (!function_exists('todo_trim_words')) {
    function todo_trim_words($text, $num_words = 55, $more = '...')
    {
        return todo_trim_words($text, $num_words, $more);
    }
}

/**
 * 获取当前查询对象
 * TodoCMS的查询对象获取函数
 *
 * @return object|null 当前查询对象
 */
if (!function_exists('todo_get_category')) {
    function todo_get_category($category_id)
    {
        $term_id = intval($category_id);
        if ($term_id <= 0) {
            return null;
        }

        if (function_exists('get_term')) {
            $term = get_term($term_id, 'category');
            if ($term) {
                return $term;
            }
        }

        global $tododb;
        if (!isset($tododb) || !$tododb) {
            return null;
        }

        $terms = $tododb->prefix . 'terms';
        $tt = $tododb->prefix . 'term_taxonomy';
        $row = $tododb->get_row(
            "SELECT t.*, tt.* FROM {$terms} t INNER JOIN {$tt} tt ON t.term_id = tt.term_id WHERE t.term_id = ? AND tt.taxonomy = 'category' LIMIT 1",
            [$term_id]
        );
        return $row ?: null;
    }
}

if (!function_exists('get_queried_object')) {
    function get_queried_object()
    {
        global $current_post, $current_category, $current_tag, $current_page;

        // Use local variables to avoid linter warnings about unassigned variables
        $obj_post = isset($current_post) ? $current_post : null;
        /** @var mixed $obj_category */
        $obj_category = isset($current_category) ? $current_category : null;
        /** @var mixed $obj_tag */
        $obj_tag = isset($current_tag) ? $current_tag : null;
        $obj_page = isset($current_page) ? $current_page : null;

        // 如果是文章页面
        if (!empty($obj_post)) {
            return $obj_post;
        }

        // 如果是分类页面
        if ($obj_category !== null && !empty($obj_category)) {
            if (is_object($obj_category)) {
                return $obj_category;
            }

            $categoryId = (is_numeric($obj_category)) ? (int) $obj_category : null;
            if ($categoryId !== null) {
                $category = todo_get_category($categoryId);
                if ($category) {
                    return $category;
                }
            }
        }

        // 如果是标签页面
        if ($obj_tag !== null && !empty($obj_tag)) {
            // 如果是对象，直接返回
            if (is_object($obj_tag)) {
                return $obj_tag;
            }

            $tagId = (is_numeric($obj_tag)) ? (int) $obj_tag : null;
            if ($tagId !== null) {
                $tag = todo_get_tag($tagId);
                if ($tag) {
                    return $tag;
                }
            }
        }

        // 如果是页面
        if (!empty($obj_page)) {
            // 如果是对象，直接返回
            if (is_object($obj_page)) {
                return $obj_page;
            }

            // 如果是ID，获取页面对象
            if (is_numeric($obj_page)) {
                $page = get_post($obj_page);
                if ($page) {
                    return $page;
                }
            }
        }

        // 尝试从URL参数与查询上下文获取查询对象
        $cat_val = (int) todo_get_query_context_value('cat', todo_get_int('cat'));
        if ($cat_val > 0) {
            $category = todo_get_category($cat_val);
            if ($category) {
                return $category;
            }
        }

        $tag_val = (int) todo_get_query_context_value('tag', todo_get_query_context_value('tag_id', todo_get_int('tag', todo_get_int('tag_id'))));
        if ($tag_val > 0) {
            $tag = todo_get_tag($tag_val);
            if ($tag) {
                return $tag;
            }
        }

        $p_val = (int) todo_get_query_context_value('p', todo_get_query_context_value('id', todo_get_int('p', todo_get_int('id'))));
        if ($p_val > 0) {
            $post = get_post($p_val);
            if ($post) {
                return $post;
            }
        }

        $page_id_val = (int) todo_get_query_context_value('page_id', todo_get_int('page_id'));
        if ($page_id_val > 0) {
            $page = get_post($page_id_val);
            if ($page) {
                return $page;
            }
        }

        // 默认返回null
        return null;
    }
}

/**
 * TodoCMS的 nonce URL 生成函数
 */
if (!function_exists('todo_nonce_url')) {
    function todo_nonce_url($actionurl, $action = -1, $name = '_todononce')
    {
        // 生成nonce值
        $nonce = todo_create_nonce($action);

        // 如果URL中已有参数，使用&连接，否则使用?
        $separator = (strpos($actionurl, '?') !== false) ? '&' : '?';

        // 构建包含nonce的URL
        return $actionurl . $separator . $name . '=' . $nonce;
    }
}

/**
 * TodoCMS的HTML清理函数
 */
if (!function_exists('todo_kses_post')) {
    function todo_kses_post($data)
    {
        // 允许的HTML标签和属性
        $allowed_tags = array(
            'a' => array(
                'href' => array(),
                'title' => array(),
                'target' => array(),
                'rel' => array(),
            ),
            'abbr' => array(
                'title' => array(),
            ),
            'acronym' => array(
                'title' => array(),
            ),
            'address' => array(),
            'b' => array(),
            'blockquote' => array(
                'cite' => array(),
            ),
            'br' => array(),
            'cite' => array(),
            'code' => array(),
            'del' => array(
                'datetime' => array(),
            ),
            'dd' => array(),
            'div' => array(
                'class' => array(),
                'id' => array(),
                'style' => array(),
            ),
            'dl' => array(),
            'dt' => array(),
            'em' => array(),
            'h1' => array(),
            'h2' => array(),
            'h3' => array(),
            'h4' => array(),
            'h5' => array(),
            'h6' => array(),
            'hr' => array(),
            'i' => array(),
            'img' => array(
                'alt' => array(),
                'src' => array(),
                'width' => array(),
                'height' => array(),
            ),
            'ins' => array(
                'datetime' => array(),
                'cite' => array(),
            ),
            'kbd' => array(),
            'li' => array(),
            'ol' => array(),
            'p' => array(),
            'pre' => array(),
            'q' => array(
                'cite' => array(),
            ),
            's' => array(),
            'span' => array(
                'class' => array(),
                'id' => array(),
                'style' => array(),
            ),
            'strike' => array(),
            'strong' => array(),
            'sub' => array(),
            'sup' => array(),
            'table' => array(),
            'tbody' => array(),
            'td' => array(),
            'tfoot' => array(),
            'th' => array(),
            'thead' => array(),
            'tr' => array(),
            'u' => array(),
            'ul' => array(),
        );

        return todo_kses($data, $allowed_tags);
    }
}

/**
 * TodoCMS HTML清理函数
 *
 * @param string $string 要清理的字符串
 * @param array $allowed_html 允许的HTML标签
 * @return string 清理后的字符串
 */
function todo_kses($string, $allowed_html = array())
{
    if (empty($allowed_html)) {
        return strip_tags($string ?? '');
    }

    // 简单的HTML清理实现
    $string = strip_tags($string ?? '', '<' . implode('><', array_keys($allowed_html)) . '>');

    return $string;
}

/**
 * TodoCMS 哈希函数
 * 
 * @param string $data 数据
 * @param string $scheme 方案 (auth, nonce)
 * @return string 哈希值
 */
if (!function_exists('todo_hash')) {
    function todo_hash($data, $scheme = 'auth')
    {
        $salt = defined('COOKIEHASH') ? COOKIEHASH : 'todo_default_salt';
        if ($scheme === 'nonce' && defined('NONCE_SALT')) {
            $salt = NONCE_SALT;
        }
        return hash_hmac('md5', $data, $salt);
    }
}

/**
 * TodoCMS 邮件发送核心函数
 * 使用 SMTP 方式发送邮件，支持自定义HTML邮件模板
 * 
 * @param string|array $to 收件人
 * @param string $subject 主题
 * @param string $message 内容（支持HTML格式）
 * @param string|array $headers 头信息
 * @param string|array $attachments 附件（暂不支持）
 * @return bool
 */
if (!function_exists('todo_mail')) {
    function todo_mail($to, $subject, $message, $headers = '', $attachments = array())
    {
        // 获取 SMTP 配置
        $smtp_host = CMS_Option::get('mail_smtp_host', '');
        $smtp_port = CMS_Option::get('mail_smtp_port', 465);
        $smtp_user = CMS_Option::get('mail_smtp_user', '');
        $smtp_pass = CMS_Option::get('mail_smtp_pass', '');
        
        // 验证 SMTP 配置
        if (empty($smtp_host) || empty($smtp_user) || empty($smtp_pass)) {
            if (function_exists('todo_set_last_mail_error')) {
                todo_set_last_mail_error('SMTP 配置不完整');
            } else {
                $GLOBALS['todo_last_mail_error'] = 'SMTP 配置不完整';
            }
            error_log('SMTP 配置不完整，请在后台设置邮件配置');
            return false;
        }
        
        // 使用 SMTP 发送
        try {
            return todo_send_mail_via_smtp($to, $subject, $message, $smtp_host, $smtp_port, $smtp_user, $smtp_pass, $headers);
        } catch (Throwable $e) {
            if (function_exists('todo_set_last_mail_error')) {
                todo_set_last_mail_error($e->getMessage());
            } else {
                $GLOBALS['todo_last_mail_error'] = $e->getMessage();
            }
            error_log('SMTP 发送失败: ' . $e->getMessage());
            return false;
        }
    }
}

/**
 * TodoCMS的邮件发送函数
 * 标准命名版本
 *
 * @param string|array $to 收件人邮箱地址
 * @param string $subject 邮件主题
 * @param string $message 邮件内容
 * @param string|array $headers 邮件头信息
 * @param string|array $attachments 附件
 * @return bool 是否发送成功
 */
if (!function_exists('todo_mail')) {
    function todo_mail($to, $subject, $message, $headers = '', $attachments = array())
    {
        return todo_mail($to, $subject, $message, $headers);
    }
}

/**
 * 使用 SMTP 发送邮件（原生socket实现）
 * 支持自定义HTML邮件模板，$message参数可以传入任何HTML内容
 * 
 * @param string $to 收件人
 * @param string $subject 主题
 * @param string $message 内容（支持HTML格式）
 * @param string $smtp_host SMTP 主机
 * @param int $smtp_port SMTP 端口（465为SSL，587为TLS）
 * @param string $smtp_user SMTP 用户名
 * @param string $smtp_pass SMTP 密码或授权码
 * @param string|array $headers 头信息
 * @return bool
 */
if (!function_exists('todo_send_mail_via_smtp')) {
    function todo_send_mail_via_smtp($to, $subject, $message, $smtp_host, $smtp_port, $smtp_user, $smtp_pass, $headers = '')
    {
        // 转换 headers 为数组
        if (is_string($headers)) {
            $headers = empty($headers) ? [] : explode("\r\n", $headers);
        }
        
        // 解析 From 头
        $from = $smtp_user;
        $from_name = '';
        $extra_headers = [];
        foreach ($headers as $header) {
            $header = trim((string) $header);
            if ($header === '' || strpos($header, "\n") !== false || strpos($header, "\r") !== false) {
                continue;
            }
            if (stripos($header, 'From:') === 0) {
                $from = trim(substr($header, 5));
                break;
            }
        }

        foreach ($headers as $header) {
            $header = trim((string) $header);
            if ($header === '' || strpos($header, "\n") !== false || strpos($header, "\r") !== false) {
                continue;
            }
            if (stripos($header, 'From:') === 0) {
                continue;
            }
            if (stripos($header, 'Content-Type:') === 0) {
                continue;
            }
            if (stripos($header, 'MIME-Version:') === 0) {
                continue;
            }
            if (stripos($header, 'Subject:') === 0) {
                continue;
            }
            if (stripos($header, 'To:') === 0) {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9-]+:\s*.+$/', $header)) {
                continue;
            }
            $hname = strtolower(trim(substr($header, 0, strpos($header, ':'))));
            $allowed = [
                'reply-to' => true,
                'cc' => true,
                'bcc' => true,
                'message-id' => true,
                'in-reply-to' => true,
                'references' => true,
                'thread-index' => true,
                'thread-topic' => true,
                'conversation-index' => true,
                'conversation-topic' => true,
            ];
            if (isset($allowed[$hname]) || strpos($hname, 'x-') === 0) {
                $extra_headers[] = $header;
            }
        }
        
        // 如果没有设置 From，使用 SMTP 用户名
        if (empty($from) || $from === $smtp_user) {
            $from = $smtp_user;
            $site_title = CMS_Option::get('site_title', 'TodoCMS');
            if (function_exists('todo_resolve_multilang_text')) {
                $cur_lang = function_exists('current_locale') ? current_locale() : 'en';
                $from_name = todo_resolve_multilang_text($site_title, $cur_lang);
            } else {
                $site_title_base = trim(preg_replace('/\[[^\]]*\]/', '', (string) $site_title));
                $from_name = $site_title_base !== '' ? $site_title_base : (string) $site_title;
            }
        }
        
        try {
            // 创建 socket 连接
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ]);
            
            // 判断是否使用 SSL
            $use_ssl = ($smtp_port == 465);
            $socket_host = ($use_ssl ? 'ssl://' : '') . $smtp_host;
            
            $errno = 0;
            $errstr = '';
            $socket = false;

            if (function_exists('stream_socket_client')) {
                $socket = @stream_socket_client(
                    $socket_host . ':' . $smtp_port,
                    $errno,
                    $errstr,
                    30,
                    STREAM_CLIENT_CONNECT,
                    $context
                );
            }

            // Fallback to fsockopen if stream_socket_client failed or is disabled
            if (!$socket && function_exists('fsockopen')) {
                $socket = @fsockopen(
                    $socket_host,
                    $smtp_port,
                    $errno,
                    $errstr,
                    30
                );
            }
            
            if (!$socket) {
                $error_msg = !empty($errstr) ? $errstr : 'Unknown Error';
                $error_code = !empty($errno) ? $errno : 0;
                throw new Exception("无法连接到 SMTP 服务器: $error_msg ($error_code)");
            }
            
            // 读取欢迎信息
            $response = fgets($socket, 515);
            if (substr($response, 0, 3) != '220') {
                throw new Exception('SMTP 服务器响应错误: ' . $response);
            }
            
            // EHLO
            fputs($socket, "EHLO " . $smtp_host . "\r\n");
            $response = fgets($socket, 515);
            if (substr($response, 0, 3) != '250') {
                throw new Exception('EHLO 失败: ' . $response);
            }
            // 循环读取所有响应行，直到遇到以空格分隔的最后一行 (250 OK)
            while (substr($response, 3, 1) != ' ') {
                $response = fgets($socket, 515);
            }
            
            // 如果是 587 端口，需要 STARTTLS
            if ($smtp_port == 587) {
                fputs($socket, "STARTTLS\r\n");
                $response = fgets($socket, 515);
                if (substr($response, 0, 3) != '220') {
                    throw new Exception('STARTTLS 失败: ' . $response);
                }
                
                if (function_exists('stream_socket_enable_crypto')) {
                    stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                }
                
                fputs($socket, "EHLO " . $smtp_host . "\r\n");
                $response = fgets($socket, 515);
                if (substr($response, 0, 3) != '250') {
                    throw new Exception('EHLO (TLS) 失败: ' . $response);
                }
                while (substr($response, 3, 1) != ' ') {
                    $response = fgets($socket, 515);
                }
            }
            
            // AUTH LOGIN
            fputs($socket, "AUTH LOGIN\r\n");
            $response = fgets($socket, 515);
            if (substr($response, 0, 3) != '334') {
                throw new Exception('AUTH LOGIN 失败: ' . $response);
            }
            
            // 发送用户名
            fputs($socket, base64_encode($smtp_user) . "\r\n");
            $response = fgets($socket, 515);
            if (substr($response, 0, 3) != '334') {
                throw new Exception('用户名验证失败: ' . $response);
            }
            
            // 发送密码
            fputs($socket, base64_encode($smtp_pass) . "\r\n");
            $response = fgets($socket, 515);
            if (substr($response, 0, 3) != '235') {
                throw new Exception('密码验证失败，请检查授权码是否正确: ' . $response);
            }
            
            // MAIL FROM
            fputs($socket, "MAIL FROM: <" . $smtp_user . ">\r\n");
            $response = fgets($socket, 515);
            if (substr($response, 0, 3) != '250') {
                throw new Exception('MAIL FROM 失败: ' . $response);
            }
            
            // RCPT TO
            $recipients = is_array($to) ? $to : explode(',', $to);
            foreach ($recipients as $recipient) {
                $recipient = trim($recipient);
                fputs($socket, "RCPT TO: <" . $recipient . ">\r\n");
                $response = fgets($socket, 515);
                if (substr($response, 0, 3) != '250') {
                    throw new Exception('RCPT TO 失败: ' . $response);
                }
            }
            
            // DATA
            fputs($socket, "DATA\r\n");
            $response = fgets($socket, 515);
            if (substr($response, 0, 3) != '354') {
                throw new Exception('DATA 失败: ' . $response);
            }
            
            $qp = function ($s) {
                $s = (string) $s;
                $s = str_replace(["\r\n", "\r"], "\n", $s);
                $s = str_replace("\n", "\r\n", $s);
                if (function_exists('quoted_printable_encode')) {
                    return quoted_printable_encode($s);
                }
                $lines = preg_split("/\r\n/", $s);
                if (!is_array($lines)) $lines = [$s];
                $out = [];
                foreach ($lines as $line) {
                    $line = (string) $line;
                    $encoded = '';
                    $len = 0;
                    $chars = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY);
                    if (!is_array($chars)) $chars = str_split($line);
                    foreach ($chars as $ch) {
                        $b = $ch;
                        $ord = ord($b[0]);
                        $safe = ($ord === 9) || ($ord === 32) || ($ord >= 33 && $ord <= 60) || ($ord >= 62 && $ord <= 126);
                        if ($safe && $ch !== '=' ) {
                            $piece = $ch;
                        } else {
                            $bytes = unpack('C*', $ch);
                            $piece = '';
                            if (is_array($bytes)) {
                                foreach ($bytes as $byte) {
                                    $piece .= '=' . strtoupper(str_pad(dechex($byte), 2, '0', STR_PAD_LEFT));
                                }
                            } else {
                                $piece = '=' . strtoupper(str_pad(dechex($ord), 2, '0', STR_PAD_LEFT));
                            }
                        }
                        $plen = strlen($piece);
                        if ($len + $plen > 73) {
                            $encoded .= "=\r\n";
                            $len = 0;
                        }
                        $encoded .= $piece;
                        $len += $plen;
                    }
                    $out[] = $encoded;
                }
                return implode("\r\n", $out);
            };

            $html_body = (string) $message;
            $text_body = (string) $message;
            $text_body = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $text_body);
            $text_body = preg_replace('/<\/\s*(p|div|li|tr|h[1-6])\s*>/i', "\n", $text_body);
            $text_body = html_entity_decode(strip_tags($text_body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text_body = preg_replace("/\n{3,}/", "\n\n", (string) $text_body);

            $boundary = '=_todocms_' . md5(uniqid('b', true));
            $to_header = is_array($to) ? implode(', ', $to) : (string) $to;

            $from_line = $from_name ? ("=?UTF-8?B?" . base64_encode($from_name) . "?= <" . $from . ">") : $from;
            $email_content = "From: " . $from_line . "\r\n";
            $email_content .= "To: " . $to_header . "\r\n";
            $email_content .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
            $email_content .= "Date: " . date('r') . "\r\n";
            $email_content .= "MIME-Version: 1.0\r\n";
            $email_content .= "Content-Type: multipart/alternative; boundary=\"" . $boundary . "\"\r\n";
            if (!empty($extra_headers)) {
                $email_content .= implode("\r\n", $extra_headers) . "\r\n";
            }
            $email_content .= "\r\n";
            $email_content .= "--" . $boundary . "\r\n";
            $email_content .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $email_content .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            $email_content .= $qp($text_body) . "\r\n\r\n";
            $email_content .= "--" . $boundary . "\r\n";
            $email_content .= "Content-Type: text/html; charset=UTF-8\r\n";
            $email_content .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            $email_content .= $qp($html_body) . "\r\n\r\n";
            $email_content .= "--" . $boundary . "--\r\n";

            if ($email_content !== '' && $email_content[0] === '.') {
                $email_content = '.' . $email_content;
            }
            $email_content = str_replace("\r\n.", "\r\n..", $email_content);
            $email_content .= "\r\n.\r\n";
            
            fputs($socket, $email_content);
            $response = fgets($socket, 515);
            if (substr($response, 0, 3) != '250') {
                throw new Exception('邮件发送失败: ' . $response);
            }
            
            // QUIT
            fputs($socket, "QUIT\r\n");
            fclose($socket);
            
            return true;
            
        } catch (Throwable $e) {
            if (function_exists('todo_set_last_mail_error')) {
                todo_set_last_mail_error($e->getMessage());
            } else {
                $GLOBALS['todo_last_mail_error'] = $e->getMessage();
            }
            error_log('SMTP 发送错误: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('todo_set_last_mail_error')) {
    function todo_set_last_mail_error($message)
    {
        $GLOBALS['todo_last_mail_error'] = trim((string) $message);
    }
}

if (!function_exists('todo_get_last_mail_error')) {
    function todo_get_last_mail_error()
    {
        return isset($GLOBALS['todo_last_mail_error']) ? trim((string) $GLOBALS['todo_last_mail_error']) : '';
    }
}

// 核心响应函数别名
if (!function_exists('todo_send_json_success')) {
    function todo_send_json_success($data = null, $status_code = 200)
    {
        return todo_send_json_success($data, $status_code);
    }
}

/**
 * 默认内容过滤器 - 处理HTML内容
 */
function todo_default_content_filter($content)
{
    // 确保HTML标签正确显示
    $content = html_entity_decode((string) $content, ENT_QUOTES, 'UTF-8');

    $is_html = $content !== strip_tags($content);
    if (!$is_html) {
        $content = nl2br($content);
    }

    // 应用短代码处理
    if (function_exists('do_shortcode')) {
        $content = do_shortcode($content);
    }

    return $content;
}

// 添加默认内容过滤器
if (function_exists('add_filter')) {
    add_filter('todo_the_content', 'todo_default_content_filter', 10);
}
