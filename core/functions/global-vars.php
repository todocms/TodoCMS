<?php
/**
 * 全局变量函数
 *
 * 提供获取全局变量的函数
 *
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 获取指定的全局变量值
 *
 * @param string $key 全局变量的字段标识
 * @param mixed $default 默认值，当变量不存在时返回
 * @return mixed 全局变量的值
 */
function get_global_var($key, $default = '') {
    // 获取所有全局变量
    $global_vars = get_option('global_vars', array());
    
    // 检查变量是否存在
    if (isset($global_vars[$key])) {
        return $global_vars[$key];
    }
    
    return $default;
}

/**
 * 获取所有全局变量
 *
 * @return array 所有全局变量的数组
 */
function get_global_vars() {
    return get_option('global_vars', array());
}