<?php
/**
 * TodoCMS Rewrite Functions
 *
 * @package TodoCMS
 * @subpackage Core
 */

if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    die('Direct access denied');
}

/**
 * 刷新重写规则
 * 
 * @param bool $hard 是否强制刷新
 */
function flush_rewrite_rules($hard = true) {
    global $todo_rewrite;
    
    if (!isset($todo_rewrite)) {
        $todo_rewrite = array();
    }
    
    // 如果是强制刷新，清空现有规则
    if ($hard) {
        $todo_rewrite = array();
    }
    
    // 重新生成重写规则
    do_action('generate_rewrite_rules');
    
    // 保存到数据库或文件
    update_option('rewrite_rules', $todo_rewrite);
    
    // 记录日志
    if (defined('TODO_DEBUG') && TODO_DEBUG) {
        error_log('TodoCMS: 重写规则已刷新');
    }
}

/**
 * 添加重写规则
 * 
 * @param string $regex 正则表达式
 * @param string $query 查询字符串
 * @param string $after 插入位置
 */
function add_rewrite_rule($regex, $query, $after = 'bottom') {
    global $todo_rewrite;
    
    if (!isset($todo_rewrite)) {
        $todo_rewrite = array();
    }
    
    if ($after === 'top') {
        $todo_rewrite = array($regex => $query) + $todo_rewrite;
    } else {
        $todo_rewrite[$regex] = $query;
    }
}

/**
 * 添加重写标签
 * 
 * @param string $tag 标签名
 * @param string $regex 正则表达式
 */
function add_rewrite_tag($tag, $regex) {
    global $todo_rewrite_tags;
    
    if (!isset($todo_rewrite_tags)) {
        $todo_rewrite_tags = array();
    }
    
    $todo_rewrite_tags[$tag] = $regex;
}

/**
 * 移除重写规则
 * 
 * @param string $regex 正则表达式
 */
function remove_rewrite_rule($regex) {
    global $todo_rewrite;
    
    if (isset($todo_rewrite[$regex])) {
        unset($todo_rewrite[$regex]);
    }
}

/**
 * 获取重写规则
 * 
 * @return array 重写规则数组
 */
function get_rewrite_rules() {
    global $todo_rewrite;
    
    if (!isset($todo_rewrite)) {
        $todo_rewrite = get_option('rewrite_rules', array());
    }
    
    return $todo_rewrite;
}

/**
 * 检查是否使用固定链接
 * 
 * @return bool
 */
function using_permalinks() {
    $permalink_structure = get_option('permalink_structure');
    return !empty($permalink_structure);
}

/**
 * 获取固定链接结构
 * 
 * @return string
 */
function get_permalink_structure() {
    $structure = get_option('permalink_structure', '');
    return $structure ? $structure : '/%postname%/';
}

/**
 * 设置固定链接结构
 * 
 * @param string $structure 固定链接结构
 */
function set_permalink_structure($structure) {
    update_option('permalink_structure', $structure);
    flush_rewrite_rules();
}