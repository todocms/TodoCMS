<?php
/**
 * DateTime字段处理助手函数
 * 解决MySQL datetime字段不能接受空字符串的问题
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

/**
 * 清理datetime字段值
 * 将空字符串、无效日期转换为NULL，避免MySQL错误
 * 
 * @param mixed $value 原始值
 * @return string|null 清理后的值
 */
function todo_clean_datetime_value($value) {
    // 如果是null或空字符串，返回null
    if (is_null($value) || $value === '' || $value === '0000-00-00 00:00:00') {
        return null;
    }
    
    // 如果是有效的日期时间字符串，验证格式
    if (is_string($value)) {
        // 先检查是否为标准格式
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return $value;
        }
        
        // 尝试解析日期
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }
        
        // 返回标准格式的日期时间
        return date('Y-m-d H:i:s', $timestamp);
    }
    
    // 如果是时间戳
    if (is_numeric($value)) {
        return date('Y-m-d H:i:s', (int)$value);
    }
    
    return null;
}

/**
 * 批量清理数据数组中的datetime字段
 * 
 * @param array $data 数据数组
 * @param array $datetime_fields datetime字段名数组
 * @return array 清理后的数据数组
 */
function todo_clean_datetime_fields($data, $datetime_fields = []) {
    // 如果没有指定字段，自动检测可能的datetime字段
    if (empty($datetime_fields)) {
        $datetime_fields = [];
        foreach ($data as $key => $value) {
            if (strpos($key, '_time') !== false || 
                strpos($key, '_date') !== false || 
                strpos($key, 'publish_time') !== false ||
                strpos($key, 'created_at') !== false ||
                strpos($key, 'updated_at') !== false) {
                $datetime_fields[] = $key;
            }
        }
    }
    
    // 清理指定的datetime字段
    foreach ($datetime_fields as $field) {
        if (isset($data[$field])) {
            $data[$field] = todo_clean_datetime_value($data[$field]);
        }
    }
    
    return $data;
}

/**
 * 获取当前时间的标准格式字符串
 * 
 * @return string 当前时间的Y-m-d H:i:s格式
 */
function todo_current_datetime() {
    return date('Y-m-d H:i:s');
}

/**
 * 检查是否为有效的datetime值
 * 
 * @param mixed $value 要检查的值
 * @return bool 是否为有效的datetime值
 */
function todo_is_valid_datetime($value) {
    if (is_null($value) || $value === '') {
        return true; // null和空字符串被认为是有效的（将被转换为null）
    }
    
    if (is_string($value)) {
        $timestamp = strtotime($value);
        return $timestamp !== false;
    }
    
    if (is_numeric($value)) {
        return true; // 数字被认为是时间戳
    }
    
    return false;
}
