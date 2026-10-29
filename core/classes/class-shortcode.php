<?php
/**
 * Todo短代码系统
 * 
 * 提供短代码注册和处理功能
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

class Todo_Shortcode {
    
    /**
     * 注册的短代码
     * 
     * @var array
     */
    private static $shortcodes = [];
    
    /**
     * 注册短代码
     * 
     * @param string $tag 短代码标签
     * @param callable $callback 回调函数
     */
    public static function add_shortcode($tag, $callback) {
        self::$shortcodes[$tag] = $callback;
    }
    
    /**
     * 移除短代码
     * 
     * @param string $tag 短代码标签
     */
    public static function remove_shortcode($tag) {
        unset(self::$shortcodes[$tag]);
    }
    
    /**
     * 检查短代码是否存在
     * 
     * @param string $tag 短代码标签
     * @return bool
     */
    public static function shortcode_exists($tag) {
        return isset(self::$shortcodes[$tag]);
    }
    
    /**
     * 处理内容中的短代码
     * 
     * @param string $content 内容
     * @return string 处理后的内容
     */
    public static function do_shortcode($content) {
        if (empty(self::$shortcodes) || !is_string($content)) {
            return $content;
        }
        
        // 构建短代码正则表达式
        $tagnames = array_keys(self::$shortcodes);
        $tagregexp = join('|', array_map('preg_quote', $tagnames));
        
        // 匹配短代码模式: [shortcode attr="value"]content[/shortcode] 或 [shortcode attr="value"]
        $pattern = '/\\[(' . $tagregexp . ')(?:\\s+([^\\]]*?))?(?:\\]([^\\[]*?)\\[\\/\\1\\]|\\])/';
        
        return preg_replace_callback($pattern, [self::class, 'do_shortcode_tag'], $content);
    }
    
    /**
     * 处理单个短代码标签
     * 
     * @param array $matches 正则匹配结果
     * @return string 处理后的内容
     */
    private static function do_shortcode_tag($matches) {
        $tag = $matches[1];
        $attr_string = isset($matches[2]) ? $matches[2] : '';
        $content = isset($matches[3]) ? $matches[3] : '';
        
        if (!isset(self::$shortcodes[$tag])) {
            return $matches[0];
        }
        
        // 解析属性
        $attributes = self::shortcode_parse_atts($attr_string);
        
        // 调用短代码回调函数
        $callback = self::$shortcodes[$tag];
        if (is_callable($callback)) {
            return call_user_func($callback, $attributes, $content, $tag);
        }
        
        return $matches[0];
    }
    
    /**
     * 解析短代码属性
     * 
     * @param string $text 属性字符串
     * @return array 解析后的属性数组
     */
    private static function shortcode_parse_atts($text) {
        $atts = [];
        $pattern = '/(\w+)\s*=\s*"([^"]*)"(?:\s|$)|(\w+)\s*=\s*\'([^\']*)\'(?:\s|$)|(\w+)\s*=\s*([^\s\'"]+)(?:\s|$)|"([^"]*)"(?:\s|$)|\'([^\']*)\'(?:\s|$)|(\S+)(?:\s|$)/';
        $text = preg_replace("/[\x{00a0}\x{200b}]+/u", " ", $text);
        
        if (preg_match_all($pattern, $text, $match, PREG_SET_ORDER)) {
            foreach ($match as $m) {
                if (!empty($m[1])) {
                    $atts[strtolower($m[1])] = stripcslashes($m[2]);
                } elseif (!empty($m[3])) {
                    $atts[strtolower($m[3])] = stripcslashes($m[4]);
                } elseif (!empty($m[5])) {
                    $atts[strtolower($m[5])] = stripcslashes($m[6]);
                } elseif (isset($m[7]) && strlen($m[7])) {
                    $atts[] = stripcslashes($m[7]);
                } elseif (isset($m[8]) && strlen($m[8])) {
                    $atts[] = stripcslashes($m[8]);
                } elseif (isset($m[9])) {
                    $atts[] = stripcslashes($m[9]);
                }
            }
        }
        
        return $atts;
    }
    
    /**
     * 获取所有注册的短代码
     * 
     * @return array
     */
    public static function get_shortcodes() {
        return self::$shortcodes;
    }
    
    /**
     * 清除所有短代码
     */
    public static function remove_all_shortcodes() {
        self::$shortcodes = [];
    }
}

/**
 * 注册短代码的便捷函数
 * 
 * @param string $tag 短代码标签
 * @param callable $callback 回调函数
 */
function add_shortcode($tag, $callback) {
    Todo_Shortcode::add_shortcode($tag, $callback);
}

/**
 * 移除短代码的便捷函数
 * 
 * @param string $tag 短代码标签
 */
function remove_shortcode($tag) {
    Todo_Shortcode::remove_shortcode($tag);
}

/**
 * 检查短代码是否存在的便捷函数
 * 
 * @param string $tag 短代码标签
 * @return bool
 */
function shortcode_exists($tag) {
    return Todo_Shortcode::shortcode_exists($tag);
}

/**
 * 处理短代码的便捷函数
 * 
 * @param string $content 内容
 * @return string 处理后的内容
 */
function do_shortcode($content) {
    return Todo_Shortcode::do_shortcode($content);
}