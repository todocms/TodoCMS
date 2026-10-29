<?php
/**
 * 模板解析优化器
 * 
 * 优化模板解析过程，减少数据库查询，提升解析性能
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 模板解析优化器类
 */
class Todo_Template_Optimizer {
    
    /**
     * 待预加载的Post IDs
     * @var array
     */
    private static $pending_post_ids = [];
    
    /**
     * 待预加载的Term IDs
     * @var array
     */
    private static $pending_term_ids = [];
    
    /**
     * 待预加载的User IDs
     * @var array
     */
    private static $pending_user_ids = [];
    
    /**
     * 是否已启用收集模式
     * @var bool
     */
    private static $collecting = false;
    
    /**
     * 已收集的查询模式
     * @var array
     */
    private static $query_patterns = [];
    
    /**
     * 启用收集模式
     */
    public static function enable_collecting() {
        self::$collecting = true;
        self::$pending_post_ids = [];
        self::$pending_term_ids = [];
        self::$pending_user_ids = [];
        self::$query_patterns = [];
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
    public static function add_post_ids($post_ids) {
        if (!self::$collecting) {
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
     * 添加待预加载的Term ID
     * 
     * @param int|array $term_ids Term ID或ID数组
     */
    public static function add_term_ids($term_ids) {
        if (!self::$collecting) {
            return;
        }
        
        if (!is_array($term_ids)) {
            $term_ids = [$term_ids];
        }
        
        foreach ($term_ids as $id) {
            $id = (int)$id;
            if ($id > 0 && !in_array($id, self::$pending_term_ids)) {
                self::$pending_term_ids[] = $id;
            }
        }
    }
    
    /**
     * 添加待预加载的User ID
     * 
     * @param int|array $user_ids User ID或ID数组
     */
    public static function add_user_ids($user_ids) {
        if (!self::$collecting) {
            return;
        }
        
        if (!is_array($user_ids)) {
            $user_ids = [$user_ids];
        }
        
        foreach ($user_ids as $id) {
            $id = (int)$id;
            if ($id > 0 && !in_array($id, self::$pending_user_ids)) {
                self::$pending_user_ids[] = $id;
            }
        }
    }
    
    /**
     * 记录查询模式（用于分析）
     * 
     * @param string $query SQL查询
     * @param array $params 参数
     */
    public static function log_query_pattern($query, $params = []) {
        if (!self::$collecting) {
            return;
        }
        
        $normalized = self::normalize_query($query, $params);
        if (!isset(self::$query_patterns[$normalized])) {
            self::$query_patterns[$normalized] = 0;
        }
        self::$query_patterns[$normalized]++;
    }
    
    /**
     * 批量加载所有收集的数据
     */
    public static function load_all() {
        // 批量加载Posts
        if (!empty(self::$pending_post_ids)) {
            $ids = array_unique(self::$pending_post_ids);
            
            // 优先使用ORM批量加载
            if (class_exists('Todo_Post_ORM')) {
                Todo_Post_ORM::eager_load_all($ids, [
                    'meta' => true,
                    'terms' => true,
                    'multilang' => true,
                    'tags' => true,
                    'categories' => true
                ]);
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
        }
        
        // 批量加载Terms
        if (!empty(self::$pending_term_ids)) {
            $ids = array_unique(self::$pending_term_ids);
            // TODO: 实现批量加载terms的逻辑
        }
        
        // 批量加载Users
        if (!empty(self::$pending_user_ids)) {
            $ids = array_unique(self::$pending_user_ids);
            // TODO: 实现批量加载users的逻辑
        }
        
        // 清空待加载列表
        self::$pending_post_ids = [];
        self::$pending_term_ids = [];
        self::$pending_user_ids = [];
    }
    
    /**
     * 规范化查询
     * 
     * @param string $query SQL查询
     * @param array $params 参数
     * @return string
     */
    private static function normalize_query($query, $params = []) {
        $normalized = $query;
        
        // 替换参数值为占位符
        foreach ($params as $param) {
            if (is_numeric($param)) {
                $normalized = preg_replace('/\?/', '?', $normalized, 1);
            } else {
                $normalized = preg_replace('/\?/', "'?'", $normalized, 1);
            }
        }
        
        // 移除多余空格
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        $normalized = trim($normalized);
        
        return $normalized;
    }
    
    /**
     * 获取待加载的Post IDs
     * 
     * @return array
     */
    public static function get_pending_post_ids() {
        return self::$pending_post_ids;
    }
    
    /**
     * 获取查询模式统计
     * 
     * @return array
     */
    public static function get_query_patterns() {
        return self::$query_patterns;
    }
    
    /**
     * 重置状态
     */
    public static function reset() {
        self::$pending_post_ids = [];
        self::$pending_term_ids = [];
        self::$pending_user_ids = [];
        self::$collecting = false;
        self::$query_patterns = [];
    }
    
    /**
     * 分析模板中的查询模式
     * 
     * @param string $template_content 模板内容
     * @return array 分析结果
     */
    public static function analyze_template($template_content) {
        $analysis = [
            'post_ids' => [],
            'term_ids' => [],
            'user_ids' => [],
            'potential_queries' => []
        ];
        
        // 提取可能的Post ID引用
        if (preg_match_all('/\{\{todo:post[^}]*id=["\']?(\d+)["\']?[^}]*\}\}/i', $template_content, $matches)) {
            $analysis['post_ids'] = array_unique(array_map('intval', $matches[1]));
        }
        
        // 提取可能的Term ID引用
        if (preg_match_all('/\{\{todo:(category|tag)[^}]*id=["\']?(\d+)["\']?[^}]*\}\}/i', $template_content, $matches)) {
            $analysis['term_ids'] = array_unique(array_map('intval', $matches[2]));
        }
        
        // 提取可能的User ID引用
        if (preg_match_all('/\{\{todo:user[^}]*id=["\']?(\d+)["\']?[^}]*\}\}/i', $template_content, $matches)) {
            $analysis['user_ids'] = array_unique(array_map('intval', $matches[1]));
        }
        
        return $analysis;
    }
}

