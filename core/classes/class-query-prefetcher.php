<?php
/**
 * 查询预取器
 * 
 * 在模板解析前，智能预取所有可能需要的查询结果
 * 基于模板内容分析，提前加载数据
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 查询预取器类
 */
class Todo_Query_Prefetcher {
    
    /**
     * 是否启用
     * @var bool
     */
    private static $enabled = true;
    
    /**
     * 预取统计
     * @var array
     */
    private static $stats = [
        'prefetched_queries' => 0,
        'cache_hits' => 0,
        'time_saved' => 0
    ];
    
    /**
     * 已预取的数据
     * @var array
     */
    private static $prefetched_data = [];
    
    /**
     * 启用预取器
     */
    public static function enable() {
        self::$enabled = true;
    }
    
    /**
     * 禁用预取器
     */
    public static function disable() {
        self::$enabled = false;
    }
    
    /**
     * 分析模板内容，预取所需数据
     * 
     * @param string $template_content 模板内容
     * @param array $context 上下文数据
     */
    public static function prefetch_from_template($template_content, $context = []) {
        if (!self::$enabled) {
            return;
        }
        
        // 提取所有post ID引用
        $post_ids = self::extract_post_ids($template_content, $context);
        if (!empty($post_ids)) {
            self::prefetch_posts($post_ids);
        }
        
        // 提取所有term ID引用
        $term_ids = self::extract_term_ids($template_content, $context);
        if (!empty($term_ids)) {
            self::prefetch_terms($term_ids);
        }
        
        // 提取所有user ID引用
        $user_ids = self::extract_user_ids($template_content, $context);
        if (!empty($user_ids)) {
            self::prefetch_users($user_ids);
        }
        
        // 预取常用选项
        self::prefetch_common_options();
    }
    
    /**
     * 从模板内容中提取Post ID
     * 
     * @param string $content 模板内容
     * @param array $context 上下文
     * @return array Post ID数组
     */
    private static function extract_post_ids($content, $context = []) {
        $ids = [];
        
        // 从上下文获取当前post ID
        if (isset($context['post_id'])) {
            $ids[] = (int)$context['post_id'];
        }
        if (isset($context['ID'])) {
            $ids[] = (int)$context['ID'];
        }
        
        // 从模板标签中提取
        // {{todo:post id="123"}}
        if (preg_match_all('/\{\{todo:post[^}]*id=["\']?(\d+)["\']?[^}]*\}\}/i', $content, $matches)) {
            foreach ($matches[1] as $id) {
                $ids[] = (int)$id;
            }
        }
        
        // {{todo:loop posts|limit=10}} - 需要查询posts
        if (preg_match('/\{\{todo:loop\s+posts/i', $content)) {
            // 预取最近的posts
            $ids = array_merge($ids, self::get_recent_post_ids(20));
        }
        
        // {{todo:item.id}} 在循环中
        if (preg_match('/\{\{todo:item\.id\}\}/i', $content)) {
            // 如果上下文中有items，提取ID
            if (isset($context['items']) && is_array($context['items'])) {
                foreach ($context['items'] as $item) {
                    if (isset($item['ID'])) {
                        $ids[] = (int)$item['ID'];
                    } elseif (isset($item['id'])) {
                        $ids[] = (int)$item['id'];
                    }
                }
            }
        }
        
        return array_unique(array_filter($ids));
    }
    
    /**
     * 从模板内容中提取Term ID
     * 
     * @param string $content 模板内容
     * @param array $context 上下文
     * @return array Term ID数组
     */
    private static function extract_term_ids($content, $context = []) {
        $ids = [];
        
        // 从上下文获取
        if (isset($context['term_id'])) {
            $ids[] = (int)$context['term_id'];
        }
        if (isset($context['category_id'])) {
            $ids[] = (int)$context['category_id'];
        }
        
        // 从模板标签中提取
        if (preg_match_all('/\{\{todo:term[^}]*id=["\']?(\d+)["\']?[^}]*\}\}/i', $content, $matches)) {
            foreach ($matches[1] as $id) {
                $ids[] = (int)$id;
            }
        }
        
        return array_unique(array_filter($ids));
    }
    
    /**
     * 从模板内容中提取User ID
     * 
     * @param string $content 模板内容
     * @param array $context 上下文
     * @return array User ID数组
     */
    private static function extract_user_ids($content, $context = []) {
        $ids = [];
        
        // 从上下文获取
        if (isset($context['user_id'])) {
            $ids[] = (int)$context['user_id'];
        }
        if (isset($context['author_id'])) {
            $ids[] = (int)$context['author_id'];
        }
        if (isset($context['post_author'])) {
            $ids[] = (int)$context['post_author'];
        }
        
        // 当前用户
        if (function_exists('get_current_user_id')) {
            $current_user_id = get_current_user_id();
            if ($current_user_id > 0) {
                $ids[] = $current_user_id;
            }
        }
        
        return array_unique(array_filter($ids));
    }
    
    /**
     * 预取Posts数据
     * 
     * @param array $post_ids Post ID数组
     */
    private static function prefetch_posts($post_ids) {
        if (empty($post_ids)) {
            return;
        }
        
        // 使用ORM批量加载
        if (class_exists('Todo_Post_ORM')) {
            Todo_Post_ORM::eager_load_all($post_ids);
            self::$stats['prefetched_queries']++;
        } else {
            // 回退到直接查询
            global $tododb;
            if (!$tododb) {
                return;
            }
            
            $placeholders = implode(',', array_fill(0, count($post_ids), '?'));
            $query = "SELECT * FROM {$tododb->prefix}posts WHERE ID IN ({$placeholders})";
            $results = $tododb->get_results($query, $post_ids, 'ARRAY_A');
            
            // 缓存结果
            foreach ($results as $post) {
                if (isset($post['ID'])) {
                    self::$prefetched_data['posts'][$post['ID']] = $post;
                }
            }
            
            self::$stats['prefetched_queries']++;
        }
    }
    
    /**
     * 预取Terms数据
     * 
     * @param array $term_ids Term ID数组
     */
    private static function prefetch_terms($term_ids) {
        if (empty($term_ids)) {
            return;
        }
        
        global $tododb;
        if (!$tododb) {
            return;
        }
        
        $placeholders = implode(',', array_fill(0, count($term_ids), '?'));
        $query = "SELECT * FROM {$tododb->prefix}terms WHERE term_id IN ({$placeholders})";
        $results = $tododb->get_results($query, $term_ids, 'ARRAY_A');
        
        // 缓存结果
        foreach ($results as $term) {
            if (isset($term['term_id'])) {
                self::$prefetched_data['terms'][$term['term_id']] = $term;
            }
        }
        
        self::$stats['prefetched_queries']++;
    }
    
    /**
     * 预取Users数据
     * 
     * @param array $user_ids User ID数组
     */
    private static function prefetch_users($user_ids) {
        if (empty($user_ids)) {
            return;
        }
        
        global $tododb;
        if (!$tododb) {
            return;
        }
        
        $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
        $query = "SELECT * FROM {$tododb->prefix}users WHERE ID IN ({$placeholders})";
        $results = $tododb->get_results($query, $user_ids, 'ARRAY_A');
        
        // 缓存结果
        foreach ($results as $user) {
            if (isset($user['ID'])) {
                self::$prefetched_data['users'][$user['ID']] = $user;
            }
        }
        
        self::$stats['prefetched_queries']++;
    }
    
    /**
     * 预取常用选项
     */
    private static function prefetch_common_options() {
        global $tododb;
        if (!$tododb) {
            return;
        }
        
        // 预取所有系统配置（system_config 表没有 autoload 字段，所以获取所有配置）
        // 注意：system_config 表使用 config_key 和 config_value 字段
        $query = "SELECT config_key, config_value, config_type FROM {$tododb->prefix}system_config";
        $results = $tododb->get_results($query, [], 'ARRAY_A');
        
        // 缓存到请求级缓存（通过执行查询来触发拦截器缓存）
        // 查询拦截器会自动缓存这些查询结果
        // 这里已经执行了批量查询，结果会被自动缓存
        
        self::$stats['prefetched_queries']++;
    }
    
    /**
     * 获取最近的Post ID
     * 
     * @param int $limit 数量限制
     * @return array Post ID数组
     */
    private static function get_recent_post_ids($limit = 20) {
        global $tododb;
        if (!$tododb) {
            return [];
        }
        
        $query = "SELECT ID FROM {$tododb->prefix}posts WHERE post_status = 'publish' ORDER BY post_date DESC LIMIT ?";
        $results = $tododb->get_results($query, [$limit], 'ARRAY_A');
        
        $ids = [];
        foreach ($results as $row) {
            if (isset($row['ID'])) {
                $ids[] = (int)$row['ID'];
            }
        }
        
        return $ids;
    }
    
    /**
     * 获取预取的数据
     * 
     * @param string $type 数据类型（posts, terms, users）
     * @param int $id ID
     * @return mixed 数据或null
     */
    public static function get_prefetched($type, $id) {
        if (isset(self::$prefetched_data[$type][$id])) {
            self::$stats['cache_hits']++;
            return self::$prefetched_data[$type][$id];
        }
        return null;
    }
    
    /**
     * 获取统计信息
     * @return array
     */
    public static function get_stats() {
        return self::$stats;
    }
    
    /**
     * 清除预取数据
     */
    public static function clear() {
        self::$prefetched_data = [];
        self::$stats = [
            'prefetched_queries' => 0,
            'cache_hits' => 0,
            'time_saved' => 0
        ];
    }
}

