<?php
/**
 * 批量预加载器
 * 
 * 在模板解析前收集所有需要的Post ID，然后一次性批量加载
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 批量预加载器类
 */
class Todo_Batch_Preloader {
    
    /**
     * 待预加载的Post IDs
     * @var array
     */
    private static $pending_post_ids = [];
    
    /**
     * 是否已启用收集模式
     * @var bool
     */
    private static $collecting = false;
    
    /**
     * 是否已执行批量加载
     * @var bool
     */
    private static $loaded = false;
    
    /**
     * 启用收集模式
     */
    public static function enable_collecting() {
        self::$collecting = true;
        self::$pending_post_ids = [];
        self::$loaded = false;
    }
    
    /**
     * 禁用收集模式并执行批量加载
     */
    public static function disable_and_load() {
        self::$collecting = false;
        self::load_all();
    }
    
    /**
     * 添加待预加载的Post ID
     * 
     * @param int|array $post_ids Post ID或ID数组
     */
    public static function add($post_ids) {
        if (!self::$collecting || self::$loaded) {
            return;
        }
        
        if (!is_array($post_ids)) {
            $post_ids = [$post_ids];
        }
        
        foreach ($post_ids as $id) {
            $id = (int)$id;
            if ($id > 0 && !in_array($id, self::$pending_post_ids)) {
                self::$pending_post_ids[] = $id;
            }
        }
    }
    
    /**
     * 批量加载所有收集的Post数据
     */
    public static function load_all() {
        if (self::$loaded || empty(self::$pending_post_ids)) {
            return;
        }
        
        $ids = array_unique(self::$pending_post_ids);
        if (empty($ids)) {
            return;
        }
        
        // 优先使用ORM批量加载
        if (class_exists('Todo_Post_ORM')) {
            Todo_Post_ORM::eager_load_all($ids);
        } else {
            // 回退到原有方法
            if (function_exists('update_post_meta_cache')) {
                update_post_meta_cache($ids);
            }
            if (function_exists('update_post_term_cache')) {
                update_post_term_cache($ids, 'category');
            }
            if (class_exists('CMS_Tags_Manager') && method_exists('CMS_Tags_Manager', 'update_post_tags_cache')) {
                CMS_Tags_Manager::update_post_tags_cache($ids);
            }
        }
        
        self::$loaded = true;
    }
    
    /**
     * 获取待加载的Post IDs
     * 
     * @return array
     */
    public static function get_pending_ids() {
        return self::$pending_post_ids;
    }
    
    /**
     * 重置状态
     */
    public static function reset() {
        self::$pending_post_ids = [];
        self::$collecting = false;
        self::$loaded = false;
    }
}