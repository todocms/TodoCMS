<?php
/**
 * 模板解析结果缓存
 * 
 * 缓存完整的模板解析结果，避免重复解析
 * 特别适用于首次加载后的性能优化
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 模板解析结果缓存类
 */
class Todo_Template_Result_Cache {
    
    /**
     * 是否启用
     * @var bool
     */
    private static $enabled = true;
    
    /**
     * 缓存TTL（秒）
     * @var int
     */
    private static $default_ttl = 3600; // 1小时
    
    /**
     * 统计信息
     * @var array
     */
    private static $stats = [
        'hits' => 0,
        'misses' => 0,
        'saves' => 0,
        'invalidations' => 0
    ];
    
    /**
     * 启用缓存
     */
    public static function enable() {
        self::$enabled = true;
    }
    
    /**
     * 禁用缓存
     */
    public static function disable() {
        self::$enabled = false;
    }
    
    /**
     * 获取缓存的模板解析结果
     * 
     * @param string $template_path 模板路径
     * @param array $data 模板数据
     * @return string|null 解析结果或null
     */
    public static function get($template_path, $data = []) {
        if (!self::$enabled) {
            return null;
        }
        
        // 生成缓存键（基于模板路径和数据）
        $cache_key = self::generate_cache_key($template_path, $data);
        
        // 使用Todo_Cache_Manager进行持久化缓存
        if (class_exists('Todo_Cache_Manager')) {
            $cached = Todo_Cache_Manager::get($cache_key, 'template_results');
            
            if ($cached !== false) {
                self::$stats['hits']++;
                return $cached;
            }
        }
        
        self::$stats['misses']++;
        return null;
    }
    
    /**
     * 保存模板解析结果到缓存
     * 
     * @param string $template_path 模板路径
     * @param array $data 模板数据
     * @param string $result 解析结果
     * @param int $ttl 缓存TTL（秒）
     */
    public static function set($template_path, $data, $result, $ttl = null) {
        if (!self::$enabled) {
            return;
        }
        
        if ($ttl === null) {
            $ttl = self::$default_ttl;
        }
        
        // 生成缓存键
        $cache_key = self::generate_cache_key($template_path, $data);
        
        // 使用Todo_Cache_Manager进行持久化缓存
        if (class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::set($cache_key, $result, $ttl, 'template_results');
            self::$stats['saves']++;
        }
    }
    
    /**
     * 生成缓存键
     * 
     * @param string $template_path 模板路径
     * @param array $data 模板数据
     * @return string
     */
    private static function generate_cache_key($template_path, $data = []) {
        // 包含模板文件及核心运行时文件修改时间，代码变更时自动失效
        $template_mtime = file_exists($template_path) ? filemtime($template_path) : 0;
        $core_mtime = 0;
        $_core_runtime = defined('ABSPATH') ? ABSPATH . 'core/functions/core.php' : '';
        if ($_core_runtime !== '' && file_exists($_core_runtime)) $core_mtime = max($core_mtime, filemtime($_core_runtime));
        $_core_tags = defined('ABSPATH') ? ABSPATH . 'core/classes/class-html-template-tags.php' : '';
        if ($_core_tags !== '' && file_exists($_core_tags)) $core_mtime = max($core_mtime, filemtime($_core_tags));
        
        // 提取关键数据（避免序列化整个数据数组）
        $key_data = [];
        if (isset($data['post_id'])) {
            $key_data['post_id'] = $data['post_id'];
        }
        if (isset($data['ID'])) {
            $key_data['ID'] = $data['ID'];
        }
        if (isset($data['term_id'])) {
            $key_data['term_id'] = $data['term_id'];
        }
        if (isset($data['user_id'])) {
            $key_data['user_id'] = $data['user_id'];
        }

        $pagination_params = [];
        $paged = function_exists('todo_get_paged_context') ? todo_get_paged_context('paged', 1) : 1;
        $pagination_params['paged'] = max(1, $paged);

        if (isset($_GET) && is_array($_GET)) {
            foreach ($_GET as $k => $v) {
                if (!is_string($k) || $k === '') continue;
                if (!preg_match('/^paged_[a-zA-Z0-9_]+$/', $k)) continue;
                if (is_array($v)) continue;
                if (!is_scalar($v)) continue;
                $n = intval($v);
                if ($n <= 0) continue;
                $pagination_params[$k] = $n;
            }
        }
        if (function_exists('todo_get_query_context_value')) {
            $overrides = todo_get_query_context_value('paged_overrides', array());
            if (is_array($overrides)) {
                foreach ($overrides as $k => $v) {
                    if (!is_string($k) || !preg_match('/^paged_[a-zA-Z0-9_]+$/', $k)) continue;
                    if (is_array($v) || is_object($v)) continue;
                    $n = intval($v);
                    if ($n <= 0) continue;
                    $pagination_params[$k] = $n;
                }
            }
        }
        if (!empty($pagination_params)) {
            ksort($pagination_params);
            $key_data['pagination'] = $pagination_params;
        }
        
        // 包含当前语言（如果支持多语言）
        if (function_exists('get_current_language')) {
            $key_data['lang'] = get_current_language();
        }

        $should_check_ads = false;
        $should_check_posts = false;
        if (function_exists('get_current_page_type')) {
            $pt = (string) get_current_page_type();
            $should_check_ads = in_array($pt, ['home', 'list', 'content', 'tag', 'category', 'single', 'page', 'archive', 'search'], true);
            $should_check_posts = in_array($pt, ['home', 'list', 'tag', 'category', 'archive', 'search'], true);
        }
        if (!$should_check_ads && $template_mtime > 0) {
            $should_check_ads = self::template_has_ads($template_path, $template_mtime);
        }

        if ($should_check_posts) {
            if (!function_exists('todo_get_latest_post_time') && defined('ABSPATH')) {
                $f = ABSPATH . 'core/functions/cache-functions.php';
                if (file_exists($f)) {
                    require_once $f;
                }
            }
            if (function_exists('todo_get_latest_post_time')) {
                $latest_post_time = todo_get_latest_post_time();
                if ($latest_post_time) {
                    $key_data['posts_v'] = (int) $latest_post_time;
                }
            }
        }

        if ($should_check_ads) {
            if (!function_exists('todo_get_latest_ad_change_time') && defined('ABSPATH')) {
                $f = ABSPATH . 'core/functions/cache-functions.php';
                if (file_exists($f)) {
                    require_once $f;
                }
            }
            if (function_exists('todo_get_latest_ad_change_time')) {
                $latest_ad_time = todo_get_latest_ad_change_time();
                if ($latest_ad_time) {
                    $key_data['ads_v'] = (int) $latest_ad_time;
                }
            }
        }
        
        return 'template_' . md5($template_path . $template_mtime . $core_mtime . serialize($key_data));
    }

    private static function template_has_ads($template_path, $template_mtime)
    {
        static $cache = [];
        $k = (string) $template_path . '|' . (int) $template_mtime;
        if (isset($cache[$k])) {
            return (bool) $cache[$k];
        }
        $c = @file_get_contents($template_path);
        $has = is_string($c) && ($c !== '') && (
            strpos($c, 'show_ads') !== false ||
            strpos($c, 'single_ads') !== false ||
            strpos($c, 'group_ads') !== false
        );
        $cache[$k] = $has;
        return $has;
    }
    
    /**
     * 清除模板缓存
     * 
     * @param string $template_path 模板路径（可选）
     */
    public static function clear($template_path = null) {
        if (class_exists('Todo_Cache_Manager')) {
            if ($template_path) {
                // 清除特定模板的缓存（需要匹配所有相关键）
                // 这里简化处理，清除所有模板缓存
                Todo_Cache_Manager::flush_group('template_results');
            } else {
                // 清除所有模板缓存
                Todo_Cache_Manager::flush_group('template_results');
            }
            self::$stats['invalidations']++;
        }
    }
    
    /**
     * 获取统计信息
     * @return array
     */
    public static function get_stats() {
        $total = self::$stats['hits'] + self::$stats['misses'];
        $hit_rate = $total > 0 ? (self::$stats['hits'] / $total * 100) : 0;
        
        return array_merge(self::$stats, [
            'hit_rate' => round($hit_rate, 2)
        ]);
    }
    
    /**
     * 配置缓存
     * 
     * @param array $config 配置
     */
    public static function configure($config) {
        if (isset($config['enabled'])) {
            self::$enabled = (bool)$config['enabled'];
        }
        if (isset($config['default_ttl'])) {
            self::$default_ttl = max(300, (int)$config['default_ttl']);
        }
    }
}
