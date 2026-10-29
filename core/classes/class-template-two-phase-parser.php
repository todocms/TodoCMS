<?php
/**
 * 两阶段模板解析器
 * 
 * 将模板解析分为两个阶段：
 * 1. 数据收集阶段：扫描模板，收集所有需要的ID
 * 2. 批量加载和渲染阶段：批量预加载数据，然后渲染
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit('Direct access denied.');
}

/**
 * 两阶段模板解析器类
 */
class Todo_Template_Two_Phase_Parser {
    
    /**
     * 数据需求收集结果
     * @var array
     */
    private static $data_requirements = [
        'post_ids' => [],
        'term_ids' => [],
        'user_ids' => [],
        'option_keys' => []
    ];
    
    /**
     * 是否已收集
     * @var bool
     */
    private static $collected = false;
    
    /**
     * 阶段1：收集数据需求
     * 
     * @param string $template_content 模板内容
     * @param string $template_path 模板路径（可选）
     * @return array 数据需求
     */
    public static function collect_requirements($template_content, $template_path = '') {
        self::$data_requirements = [
            'post_ids' => [],
            'term_ids' => [],
            'user_ids' => [],
            'option_keys' => []
        ];
        
        // 提取Post ID引用
        self::extract_post_ids($template_content);
        
        // 提取Term ID引用
        self::extract_term_ids($template_content);
        
        // 提取User ID引用
        self::extract_user_ids($template_content);
        
        // 提取Option键引用
        self::extract_option_keys($template_content);
        
        // 处理包含的模板文件
        if (!empty($template_path)) {
            self::collect_from_includes($template_path);
        }
        
        self::$collected = true;
        
        return self::$data_requirements;
    }
    
    /**
     * 提取Post ID
     * 
     * @param string $content 模板内容
     */
    private static function extract_post_ids($content) {
        // 匹配 {{todo:post id="123"}} 或 {{todo:post_id}} 等标签
        $patterns = [
            '/\{\{todo:post[^}]*id=["\']?(\d+)["\']?[^}]*\}\}/i',
            '/\{\{todo:post_id\}\}/i',
            '/\{\{todo:prev_post\}\}/i',
            '/\{\{todo:next_post\}\}/i',
            '/\{\{todo:related_posts[^}]*\}\}/i',
            '/\{\{todo:loop[^}]*posts[^}]*\}\}/i',
            '/\{\{todo:loop[^}]*items[^}]*\}\}/i'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                if (isset($matches[1])) {
                    foreach ($matches[1] as $id) {
                        if (is_numeric($id)) {
                            self::$data_requirements['post_ids'][] = (int)$id;
                        }
                    }
                } else {
                    // 对于没有ID的标签，需要从全局变量或上下文获取
                    // 这些会在渲染阶段处理
                }
            }
        }
        
        // 去重
        self::$data_requirements['post_ids'] = array_unique(self::$data_requirements['post_ids']);
    }
    
    /**
     * 提取Term ID
     * 
     * @param string $content 模板内容
     */
    private static function extract_term_ids($content) {
        // 匹配分类和标签ID
        $patterns = [
            '/\{\{todo:category[^}]*id=["\']?(\d+)["\']?[^}]*\}\}/i',
            '/\{\{todo:tag[^}]*id=["\']?(\d+)["\']?[^}]*\}\}/i',
            '/\{\{todo:term[^}]*id=["\']?(\d+)["\']?[^}]*\}\}/i',
            '/\{\{todo:tags[^}]*\}\}/i'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                if (isset($matches[1])) {
                    foreach ($matches[1] as $id) {
                        if (is_numeric($id)) {
                            self::$data_requirements['term_ids'][] = (int)$id;
                        }
                    }
                }
            }
        }
        
        // 去重
        self::$data_requirements['term_ids'] = array_unique(self::$data_requirements['term_ids']);
    }
    
    /**
     * 提取User ID
     * 
     * @param string $content 模板内容
     */
    private static function extract_user_ids($content) {
        // 匹配用户ID
        $patterns = [
            '/\{\{todo:user[^}]*id=["\']?(\d+)["\']?[^}]*\}\}/i',
            '/\{\{todo:author[^}]*id=["\']?(\d+)["\']?[^}]*\}\}/i',
            '/\{\{todo:author\}\}/i'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                if (isset($matches[1])) {
                    foreach ($matches[1] as $id) {
                        if (is_numeric($id)) {
                            self::$data_requirements['user_ids'][] = (int)$id;
                        }
                    }
                }
            }
        }
        
        // 去重
        self::$data_requirements['user_ids'] = array_unique(self::$data_requirements['user_ids']);
    }
    
    /**
     * 提取Option键
     * 
     * @param string $content 模板内容
     */
    private static function extract_option_keys($content) {
        // 匹配 {{todo:option key="site_name"}} 等
        if (preg_match_all('/\{\{todo:option[^}]*key=["\']([^"\']+)["\'][^}]*\}\}/i', $content, $matches)) {
            foreach ($matches[1] as $key) {
                self::$data_requirements['option_keys'][] = $key;
            }
        }
        
        // 去重
        self::$data_requirements['option_keys'] = array_unique(self::$data_requirements['option_keys']);
    }
    
    /**
     * 从包含的模板文件中收集
     * 
     * @param string $template_path 模板路径
     */
    private static function collect_from_includes($template_path) {
        // 读取模板内容
        if (!file_exists($template_path)) {
            return;
        }
        
        $content = file_get_contents($template_path);
        
        // 提取include标签
        if (preg_match_all('/\{\{todo:include[^}]*file=["\']([^"\']+)["\'][^}]*\}\}/i', $content, $matches)) {
            $template_dir = dirname($template_path);
            
            foreach ($matches[1] as $include_file) {
                $include_path = $template_dir . '/' . $include_file;
                if (file_exists($include_path)) {
                    $include_content = file_get_contents($include_path);
                    self::extract_post_ids($include_content);
                    self::extract_term_ids($include_content);
                    self::extract_user_ids($include_content);
                    self::extract_option_keys($include_content);
                }
            }
        }
    }
    
    /**
     * 阶段2：批量预加载数据
     * 
     * @param array $requirements 数据需求（可选，如果不提供则使用收集的结果）
     */
    public static function preload_data($requirements = null) {
        if ($requirements === null) {
            $requirements = self::$data_requirements;
        }
        
        // 批量预加载Posts
        if (!empty($requirements['post_ids'])) {
            $post_ids = array_unique(array_filter($requirements['post_ids']));
            if (!empty($post_ids)) {
                if (class_exists('Todo_Post_ORM')) {
                    Todo_Post_ORM::eager_load_all($post_ids, [
                        'meta' => true,
                        'terms' => true,
                        'multilang' => true,
                        'tags' => true,
                        'categories' => true
                    ]);
                } elseif (class_exists('Todo_Batch_Preloader')) {
                    Todo_Batch_Preloader::add($post_ids);
                    Todo_Batch_Preloader::load_all();
                }
            }
        }
        
        // 批量预加载Terms
        if (!empty($requirements['term_ids'])) {
            $term_ids = array_unique(array_filter($requirements['term_ids']));
            // TODO: 实现批量加载terms的逻辑
        }
        
        // 批量预加载Users
        if (!empty($requirements['user_ids'])) {
            $user_ids = array_unique(array_filter($requirements['user_ids']));
            // TODO: 实现批量加载users的逻辑
        }
        
        // 批量预加载Options
        if (!empty($requirements['option_keys'])) {
            $option_keys = array_unique($requirements['option_keys']);
            // 预加载常用选项
            if (class_exists('CMS_Option')) {
                foreach ($option_keys as $key) {
                    // 触发加载（如果使用autoload机制）
                    if (function_exists('get_option')) {
                        get_option($key);
                    }
                }
            }
        }
    }
    
    /**
     * 执行两阶段解析
     * 
     * @param string $template_path 模板路径
     * @param array $data 模板数据
     * @param callable $render_callback 渲染回调函数
     * @return string 解析后的内容
     */
    public static function parse($template_path, $data = [], $render_callback = null) {
        // 读取模板内容
        if (!file_exists($template_path)) {
            return '';
        }
        
        $template_content = file_get_contents($template_path);
        
        // 阶段1：收集数据需求
        $requirements = self::collect_requirements($template_content, $template_path);
        
        // 阶段2：批量预加载
        self::preload_data($requirements);
        
        // 渲染模板
        if ($render_callback !== null && is_callable($render_callback)) {
            return call_user_func($render_callback, $template_path, $data);
        } else {
            // 使用默认解析器
            return CMS_Template_Compiler_V2::parse_template_with_cache($template_path, $data);
        }
    }
    
    /**
     * 添加待预加载的Post ID
     * 
     * @param int|array $post_ids Post ID或ID数组
     */
    public static function add_post_ids($post_ids) {
        if (!is_array($post_ids)) {
            $post_ids = [$post_ids];
        }
        
        foreach ($post_ids as $id) {
            $id = (int)$id;
            if ($id > 0 && !in_array($id, self::$data_requirements['post_ids'])) {
                self::$data_requirements['post_ids'][] = $id;
            }
        }
    }
    
    /**
     * 添加待预加载的Term ID
     * 
     * @param int|array $term_ids Term ID或ID数组
     */
    public static function add_term_ids($term_ids) {
        if (!is_array($term_ids)) {
            $term_ids = [$term_ids];
        }
        
        foreach ($term_ids as $id) {
            $id = (int)$id;
            if ($id > 0 && !in_array($id, self::$data_requirements['term_ids'])) {
                self::$data_requirements['term_ids'][] = $id;
            }
        }
    }
    
    /**
     * 获取收集的数据需求
     * 
     * @return array
     */
    public static function get_requirements() {
        return self::$data_requirements;
    }
    
    /**
     * 查询模式统计
     * @var array
     */
    private static $query_patterns = [];
    
    /**
     * 重置收集状态
     */
    public static function reset() {
        self::$data_requirements = [
            'post_ids' => [],
            'term_ids' => [],
            'user_ids' => [],
            'option_keys' => []
        ];
        self::$collected = false;
        self::$query_patterns = [];
    }
}

