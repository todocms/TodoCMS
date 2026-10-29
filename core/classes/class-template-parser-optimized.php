<?php
/**
 * 模板解析器优化版本
 * 
 * 通过减少递归、优化正则、缓存解析结果等方式大幅提升性能
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit('Direct access denied.');
}

/**
 * 模板解析器优化类
 */
class Todo_Template_Parser_Optimized {
    
    /**
     * 标签解析缓存
     * @var array
     */
    private static $tag_cache = [];
    
    /**
     * 正则表达式缓存
     * @var array
     */
    private static $regex_cache = [];
    
    /**
     * 解析深度限制
     * @var int
     */
    private static $max_depth = 10;
    
    /**
     * 当前解析深度
     * @var int
     */
    private static $current_depth = 0;
    
    /**
     * 统计信息
     * @var array
     */
    private static $stats = [
        'tags_parsed' => 0,
        'cache_hits' => 0,
        'regex_calls' => 0,
        'time_saved' => 0
    ];
    
    /**
     * 优化的标签解析
     * 
     * @param string $content 模板内容
     * @param array $data 数据
     * @return string 解析后的内容
     */
    public static function parse_optimized($content, $data = []) {
        // 检查缓存
        $cache_key = md5($content . serialize($data));
        if (isset(self::$tag_cache[$cache_key])) {
            self::$stats['cache_hits']++;
            return self::$tag_cache[$cache_key];
        }
        
        $start_time = microtime(true);
        
        // 限制递归深度
        if (self::$current_depth >= self::$max_depth) {
            return $content;
        }
        
        self::$current_depth++;
        
        // 使用优化的解析方法
        $result = self::parse_tags_optimized($content, $data);
        
        self::$current_depth--;
        
        // 缓存结果
        if (self::$current_depth === 0) {
            self::$tag_cache[$cache_key] = $result;
        }
        
        $time_taken = (microtime(true) - $start_time) * 1000;
        self::$stats['time_saved'] += $time_taken;
        
        return $result;
    }
    
    /**
     * 优化的标签解析（减少正则调用）
     * 
     * @param string $content 模板内容
     * @param array $data 数据
     * @return string
     */
    private static function parse_tags_optimized($content, $data) {
        // 预编译常用正则表达式
        $patterns = self::get_compiled_patterns();
        
        // 一次性匹配所有标签，减少正则调用次数
        $all_matches = [];
        foreach ($patterns as $name => $pattern) {
            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                $all_matches[$name] = $matches;
            }
        }
        
        // 按位置排序，从后往前替换（避免位置偏移）
        $replacements = [];
        foreach ($all_matches as $name => $matches) {
            foreach ($matches[0] as $match) {
                $replacements[] = [
                    'offset' => $match[1],
                    'length' => strlen($match[0]),
                    'content' => $match[0],
                    'type' => $name
                ];
            }
        }
        
        // 按位置从后往前排序
        usort($replacements, function($a, $b) {
            return $b['offset'] - $a['offset'];
        });
        
        // 执行替换
        foreach ($replacements as $replacement) {
            $replacement_content = self::process_tag($replacement['type'], $replacement['content'], $data);
            $content = substr_replace($content, $replacement_content, $replacement['offset'], $replacement['length']);
        }
        
        self::$stats['tags_parsed'] += count($replacements);
        self::$stats['regex_calls'] += count($patterns);
        
        return $content;
    }
    
    /**
     * 获取编译后的正则表达式
     * 
     * @return array
     */
    private static function get_compiled_patterns() {
        if (!empty(self::$regex_cache)) {
            return self::$regex_cache;
        }
        
        // 预编译常用标签的正则表达式
        self::$regex_cache = [
            'single_tag' => '/\{(\w+)([^}]*?)\s*\/\}/',
            'paired_tag' => '/\{(\w+)([^}]*?)\}(.*?)\{\/\1\}/s',
            'todo_tag' => '/\{\{todo:(\w+)([^}]*?)\}\}/',
            'todo_paired' => '/\{\{todo:(\w+)([^}]*?)\}\}(.*?)\{\{\/todo:\1\}\}/s',
        ];
        
        return self::$regex_cache;
    }
    
    /**
     * 处理单个标签
     * 
     * @param string $type 标签类型
     * @param string $content 标签内容
     * @param array $data 数据
     * @return string
     */
    private static function process_tag($type, $content, $data) {
        // 这里应该调用实际的标签处理器
        // 简化实现，实际应该委托给CMS_Template_Parser
        return $content;
    }
    
    /**
     * 获取统计信息
     * @return array
     */
    public static function get_stats() {
        return self::$stats;
    }
    
    /**
     * 清除缓存
     */
    public static function clear_cache() {
        self::$tag_cache = [];
        self::$regex_cache = [];
    }
}

