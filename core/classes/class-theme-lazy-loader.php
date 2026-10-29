<?php
/**
 * 主题资源延迟加载器
 * 
 * 按需加载主题资源，减少初始加载时间
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 主题资源延迟加载器类
 */
class Todo_Theme_Lazy_Loader {
    
    /**
     * 已加载的资源
     * @var array
     */
    private static $loaded_resources = [];
    
    /**
     * 资源加载队列
     * @var array
     */
    private static $load_queue = [];
    
    /**
     * 关键资源（必须立即加载）
     * @var array
     */
    private static $critical_resources = [
        'theme.json',
        'functions.php',
        'style.css'
    ];
    
    /**
     * 延迟加载资源（可以稍后加载）
     * @var array
     */
    private static $lazy_resources = [
        'screenshot.png',
        'readme.txt',
        'changelog.txt'
    ];
    
    /**
     * 加载主题资源
     * 
     * @param string $theme_name 主题名称
     * @param string $resource 资源名称
     * @param bool $force 是否强制加载
     * @return mixed 资源内容
     */
    public static function load_resource($theme_name, $resource, $force = false) {
        $cache_key = $theme_name . '_' . $resource;
        
        // 检查是否已加载
        if (isset(self::$loaded_resources[$cache_key]) && !$force) {
            return self::$loaded_resources[$cache_key];
        }
        
        // 检查是否为关键资源
        $is_critical = in_array($resource, self::$critical_resources);
        
        // 如果不是关键资源且未强制加载，加入延迟队列
        if (!$is_critical && !$force) {
            self::$load_queue[$cache_key] = [
                'theme' => $theme_name,
                'resource' => $resource
            ];
            return null;
        }
        
        // 立即加载
        $theme_dir = THEMES_DIR . '/' . $theme_name;
        $resource_path = $theme_dir . '/' . $resource;
        
        if (!file_exists($resource_path)) {
            return null;
        }
        
        $content = file_get_contents($resource_path);
        self::$loaded_resources[$cache_key] = $content;
        
        return $content;
    }
    
    /**
     * 批量加载延迟队列中的资源
     */
    public static function flush_lazy_queue() {
        foreach (self::$load_queue as $cache_key => $item) {
            self::load_resource($item['theme'], $item['resource'], true);
        }
        self::$load_queue = [];
    }
    
    /**
     * 预加载关键资源
     * 
     * @param string $theme_name 主题名称
     */
    public static function preload_critical($theme_name) {
        foreach (self::$critical_resources as $resource) {
            self::load_resource($theme_name, $resource, true);
        }
    }
    
    /**
     * 获取已加载的资源
     * @return array
     */
    public static function get_loaded_resources() {
        return self::$loaded_resources;
    }
    
    /**
     * 清除已加载的资源缓存
     */
    public static function clear_cache() {
        self::$loaded_resources = [];
        self::$load_queue = [];
    }
}

