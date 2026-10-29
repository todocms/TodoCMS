<?php
/**
 * 模板解析器激进优化版本
 * 
 * 通过减少正则调用、优化递归、激进缓存等方式实现<1000ms目标
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit('Direct access denied.');
}

/**
 * 模板解析器激进优化类
 */
class Todo_Template_Parser_Aggressive {
    
    /**
     * 解析结果缓存（请求级）
     * @var array
     */
    private static $parse_cache = [];
    
    /**
     * 标签匹配缓存
     * @var array
     */
    private static $tag_match_cache = [];
    
    /**
     * 最大缓存大小
     * @var int
     */
    private static $max_cache_size = 100;
    
    /**
     * 统计信息
     * @var array
     */
    private static $stats = [
        'cache_hits' => 0,
        'cache_misses' => 0,
        'regex_calls_saved' => 0,
        'time_saved' => 0
    ];
    
    /**
     * 优化的标签解析（减少正则调用）
     * 
     * @param string $content 模板内容
     * @param array $data 数据
     * @param CMS_Template_Parser $parser 原始解析器实例
     * @return string
     */
    public static function parse_optimized($content, $data, $parser) {
        // 检查缓存
        $cache_key = md5($content . serialize($data));
        if (isset(self::$parse_cache[$cache_key])) {
            self::$stats['cache_hits']++;
            return self::$parse_cache[$cache_key];
        }
        
        $start_time = microtime(true);
        
        // 预扫描：一次性提取所有标签位置，减少正则调用
        $tag_positions = self::scan_tags($content);
        
        if (empty($tag_positions)) {
            // 没有标签，直接返回
            return $content;
        }
        
        // 按位置从后往前排序（避免位置偏移）
        usort($tag_positions, function($a, $b) {
            return $b['offset'] - $a['offset'];
        });
        
        // 批量替换
        foreach ($tag_positions as $pos) {
            $replacement = self::process_tag_at_position($content, $pos, $data, $parser);
            $content = substr_replace($content, $replacement, $pos['offset'], $pos['length']);
        }
        
        // 缓存结果
        if (count(self::$parse_cache) < self::$max_cache_size) {
            self::$parse_cache[$cache_key] = $content;
        }
        
        $time_taken = (microtime(true) - $start_time) * 1000;
        self::$stats['time_saved'] += $time_taken;
        self::$stats['cache_misses']++;
        
        return $content;
    }
    
    /**
     * 扫描所有标签位置（一次性正则调用）
     * 
     * @param string $content 模板内容
     * @return array
     */
    private static function scan_tags($content) {
        // 检查缓存
        $cache_key = md5($content);
        if (isset(self::$tag_match_cache[$cache_key])) {
            return self::$tag_match_cache[$cache_key];
        }
        
        $positions = [];
        
        // 匹配成对标签：{{todo:tag_name attr}}content{{/todo:tag_name}}
        $paired_pattern = '/\{\{todo:(\w+)([^}]*?)\}\}(.*?)\{\{\/todo:\1\}\}/s';
        if (preg_match_all($paired_pattern, $content, $paired_matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($paired_matches as $match) {
                $positions[] = [
                    'offset' => $match[0][1],
                    'length' => strlen($match[0][0]),
                    'tag_name' => $match[1][0],
                    'attributes' => isset($match[2][0]) ? trim($match[2][0]) : '',
                    'content' => isset($match[3][0]) ? $match[3][0] : '',
                    'full_match' => $match[0][0],
                    'is_paired' => true
                ];
            }
        }
        
        // 匹配自闭合标签：{{todo:tag_name attr}}
        $single_pattern = '/\{\{todo:(\w+)([^}\/]*(?:\/[^}]+)?)\}\}/';
        if (preg_match_all($single_pattern, $content, $single_matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($single_matches as $match) {
                $offset = $match[0][1];
                $full_match = $match[0][0];
                
                // 检查是否已经被成对标签匹配（避免重复）
                $already_matched = false;
                foreach ($positions as $pos) {
                    if ($offset >= $pos['offset'] && $offset < ($pos['offset'] + $pos['length'])) {
                        $already_matched = true;
                        break;
                    }
                }
                
                if (!$already_matched) {
                    $positions[] = [
                        'offset' => $offset,
                        'length' => strlen($full_match),
                        'tag_name' => $match[1][0],
                        'attributes' => isset($match[2][0]) ? trim($match[2][0]) : '',
                        'content' => '',
                        'full_match' => $full_match,
                        'is_paired' => false
                    ];
                }
            }
        }
        
        // 缓存匹配结果
        if (count(self::$tag_match_cache) < self::$max_cache_size) {
            self::$tag_match_cache[$cache_key] = $positions;
        }
        
        self::$stats['regex_calls_saved'] += max(0, count($positions) - 2); // 节省了正则调用
        
        return $positions;
    }
    
    /**
     * 处理特定位置的标签
     * 
     * @param string $content 完整内容
     * @param array $position 标签位置信息
     * @param array $data 数据
     * @param CMS_Template_Parser $parser 解析器实例
     * @return string
     */
    private static function process_tag_at_position($content, $position, $data, $parser) {
        $tag_name = $position['tag_name'];
        $attributes = $position['attributes'];
        $inner_content = $position['content'];
        $is_paired = isset($position['is_paired']) ? $position['is_paired'] : !empty($inner_content);
        
        // 调用原始解析器的方法（使用反射访问私有方法）
        try {
            $reflection = new ReflectionClass($parser);
            
            if ($is_paired && !empty($inner_content)) {
                // 成对标签
                $method = $reflection->getMethod('parse_paired_tag');
                $method->setAccessible(true);
                $result = $method->invoke($parser, $tag_name, $attributes, $inner_content, $data);
            } else {
                // 自闭合标签或单标签
                $method = $reflection->getMethod('parse_single_tag');
                $method->setAccessible(true);
                $result = $method->invoke($parser, $tag_name, $attributes, $data);
            }
            
            // 安全地转换结果为字符串
            if (is_object($result)) {
                return method_exists($result, '__toString') ? (string) $result : '';
            } elseif (is_array($result)) {
                return implode('', $result);
            } else {
                return (string) $result;
            }
        } catch (Exception $e) {
            // 如果反射失败，尝试直接调用parse_todo_tags方法
            try {
                // 回退：直接调用原始解析器的parse_todo_tags方法
                $method = $reflection->getMethod('parse_todo_tags');
                $method->setAccessible(true);
                $result = $method->invoke($parser, $position['full_match'], $data);
                return is_string($result) ? $result : $position['full_match'];
            } catch (Exception $e2) {
                // 完全失败，返回原始标签
                error_log('Todo_Template_Parser_Aggressive: Failed to parse tag: ' . $tag_name . ' - ' . $e->getMessage());
                return $position['full_match'];
            }
        }
    }
    
    /**
     * 清除缓存
     */
    public static function clear_cache() {
        self::$parse_cache = [];
        self::$tag_match_cache = [];
    }
    
    /**
     * 获取统计信息
     * @return array
     */
    public static function get_stats() {
        return self::$stats;
    }
}

