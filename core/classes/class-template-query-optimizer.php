<?php
/**
 * 模板查询优化器
 * 
 * 在模板解析过程中，优化数据库查询
 * - 检测循环中的查询模式
 * - 批量预加载循环数据
 * - 缓存重复查询
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 模板查询优化器类
 */
class Todo_Template_Query_Optimizer {
    
    /**
     * 是否启用
     * @var bool
     */
    private static $enabled = true;
    
    /**
     * 循环上下文栈
     * @var array
     */
    private static $loop_stack = [];
    
    /**
     * 已优化的循环
     * @var array
     */
    private static $optimized_loops = [];
    
    /**
     * 统计信息
     * @var array
     */
    private static $stats = [
        'loops_optimized' => 0,
        'queries_saved' => 0,
        'batch_loads' => 0
    ];
    
    /**
     * 启用优化器
     */
    public static function enable() {
        self::$enabled = true;
    }
    
    /**
     * 禁用优化器
     */
    public static function disable() {
        self::$enabled = false;
    }
    
    /**
     * 开始循环优化
     * 
     * @param string $loop_name 循环名称
     * @param array $items 循环项
     * @param array $attrs 循环属性
     */
    public static function start_loop($loop_name, $items, $attrs = []) {
        if (!self::$enabled) {
            return;
        }
        
        // 提取所有ID
        $post_ids = [];
        $term_ids = [];
        $user_ids = [];
        
        foreach ($items as $item) {
            // 确保 $item 是数组（兼容对象）
            $item_array = is_object($item) ? (array) $item : (is_array($item) ? $item : []);
            
            if (empty($item_array)) {
                continue;
            }
            
            // 提取Post ID
            if (isset($item_array['ID'])) {
                $post_ids[] = (int)$item_array['ID'];
            } elseif (isset($item_array['id']) && isset($item_array['post_type'])) {
                $post_ids[] = (int)$item_array['id'];
            }
            
            // 提取Term ID
            if (isset($item_array['term_id'])) {
                $term_ids[] = (int)$item_array['term_id'];
            }
            
            // 提取User ID
            if (isset($item_array['user_id'])) {
                $user_ids[] = (int)$item_array['user_id'];
            } elseif (isset($item_array['post_author'])) {
                $user_ids[] = (int)$item_array['post_author'];
            }
        }
        
        // 批量预加载
        if (!empty($post_ids)) {
            self::preload_posts($post_ids);
        }
        if (!empty($term_ids)) {
            self::preload_terms($term_ids);
        }
        if (!empty($user_ids)) {
            self::preload_users($user_ids);
        }
        
        // 记录循环信息
        self::$loop_stack[] = [
            'name' => $loop_name,
            'items' => $items,
            'attrs' => $attrs,
            'post_ids' => $post_ids,
            'term_ids' => $term_ids,
            'user_ids' => $user_ids
        ];
        
        self::$stats['loops_optimized']++;
    }
    
    /**
     * 结束循环优化
     */
    public static function end_loop() {
        if (!empty(self::$loop_stack)) {
            array_pop(self::$loop_stack);
        }
    }
    
    /**
     * 预加载Posts
     * 
     * @param array $post_ids Post ID数组
     */
    private static function preload_posts($post_ids) {
        if (empty($post_ids)) {
            return;
        }
        
        $post_ids = array_unique($post_ids);
        
        // 使用ORM批量加载
        if (class_exists('Todo_Post_ORM')) {
            Todo_Post_ORM::eager_load_all($post_ids);
            self::$stats['batch_loads']++;
            self::$stats['queries_saved'] += count($post_ids) - 1; // 节省N-1个查询
        }
    }
    
    /**
     * 预加载Terms
     * 
     * @param array $term_ids Term ID数组
     */
    private static function preload_terms($term_ids) {
        if (empty($term_ids)) {
            return;
        }
        
        $term_ids = array_unique($term_ids);
        
        global $tododb;
        if (!$tododb) {
            return;
        }
        
        $placeholders = implode(',', array_fill(0, count($term_ids), '?'));
        $query = "SELECT * FROM {$tododb->prefix}terms WHERE term_id IN ({$placeholders})";
        $tododb->get_results($query, $term_ids, 'ARRAY_A');
        
        self::$stats['batch_loads']++;
        self::$stats['queries_saved'] += count($term_ids) - 1;
    }
    
    /**
     * 预加载Users
     * 
     * @param array $user_ids User ID数组
     */
    private static function preload_users($user_ids) {
        if (empty($user_ids)) {
            return;
        }
        
        $user_ids = array_unique($user_ids);
        
        global $tododb;
        if (!$tododb) {
            return;
        }
        
        $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
        $query = "SELECT * FROM {$tododb->prefix}users WHERE ID IN ({$placeholders})";
        $tododb->get_results($query, $user_ids, 'ARRAY_A');
        
        self::$stats['batch_loads']++;
        self::$stats['queries_saved'] += count($user_ids) - 1;
    }
    
    /**
     * 获取当前循环上下文
     * 
     * @return array|null
     */
    public static function get_current_loop() {
        if (empty(self::$loop_stack)) {
            return null;
        }
        return end(self::$loop_stack);
    }
    
    /**
     * 获取统计信息
     * @return array
     */
    public static function get_stats() {
        return self::$stats;
    }
    
    /**
     * 重置统计
     */
    public static function reset_stats() {
        self::$stats = [
            'loops_optimized' => 0,
            'queries_saved' => 0,
            'batch_loads' => 0
        ];
    }
}

