<?php
/**
 * 设置API端点
 *
 * 提供系统设置的API端点
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

/**
 * 获取所有设置
 *
 * @param array $params 请求参数
 * @return array 设置数据
 */
function api_get_settings($params) {
    // 检查权限
    if (!todo_current_user_can('manage_options')) {
        send_api_error(403, '没有权限访问设置');
    }
    
    // 获取常规设置
    $general_settings = [
        'site_title' => get_option('site_title'),
        'site_description' => get_option('site_description'),
        'site_url' => get_option('site_url'),
        'admin_email' => get_option('admin_email'),
        'posts_per_page' => get_option('posts_per_page', 10),
        'date_format' => get_option('date_format', 'Y-m-d'),
        'time_format' => get_option('time_format', 'H:i'),
        'timezone' => get_option('timezone', 'UTC'),
        'default_category' => get_option('default_category', 1),
        'default_comment_status' => get_option('default_comment_status', 'open')
    ];
    
    // 获取阅读设置
    $reading_settings = [
        'posts_per_rss' => get_option('posts_per_rss', 10),
        'rss_excerpt' => get_option('rss_excerpt', 'summary')
    ];
    
    // 获取讨论设置
    $discussion_settings = [
        'default_comment_status' => get_option('default_comment_status', 'open'),
        'require_name_email' => get_option('require_name_email', 1),
        'comment_registration' => get_option('comment_registration', 0),
        'close_comments_days_old' => get_option('close_comments_days_old', 14),
        'comments_per_page' => get_option('comments_per_page', 20),
        'comment_order' => get_option('comment_order', 'asc'),
        'comment_moderation' => get_option('comment_moderation', 1),
        'comment_blacklist' => get_option('comment_blacklist', '')
    ];
    
    // 获取媒体设置
    $media_settings = [
        'thumbnail_size_w' => get_option('thumbnail_size_w', 150),
        'thumbnail_size_h' => get_option('thumbnail_size_h', 150),
        'thumbnail_crop' => get_option('thumbnail_crop', 1),
        'medium_size_w' => get_option('medium_size_w', 300),
        'medium_size_h' => get_option('medium_size_h', 300),
        'large_size_w' => get_option('large_size_w', 1024),
        'large_size_h' => get_option('large_size_h', 1024),
        'uploads_use_yearmonth_folders' => get_option('uploads_use_yearmonth_folders', 1)
    ];
    
    // 获取固定链接设置
    $permalink_settings = [
        'permalink_structure' => get_option('permalink_structure', '/%year%/%monthnum%/%postname%/'),
        'category_base' => get_option('category_base', 'category'),
        'tag_base' => get_option('tag_base', 'tag')
    ];
    
    // 返回所有设置
    return [
        'general' => $general_settings,
        'reading' => $reading_settings,
        'discussion' => $discussion_settings,
        'media' => $media_settings,
        'permalinks' => $permalink_settings
    ];
}

/**
 * 获取指定设置组
 *
 * @param array $params 请求参数
 * @return array 设置数据
 */
function api_get_setting_group($params) {
    // 检查权限
    if (!todo_current_user_can('manage_options')) {
        send_api_error(403, '没有权限访问设置');
    }
    
    // 验证参数
    if (!isset($params['group'])) {
        send_api_error(400, '缺少组参数');
    }
    
    $group = sanitize_text_field($params['group']);
    
    // 根据组返回相应设置
    switch ($group) {
        case 'general':
            return [
                'site_title' => get_option('site_title'),
                'site_description' => get_option('site_description'),
                'site_url' => get_option('site_url'),
                'admin_email' => get_option('admin_email'),
                'posts_per_page' => get_option('posts_per_page', 10),
                'date_format' => get_option('date_format', 'Y-m-d'),
                'time_format' => get_option('time_format', 'H:i'),
                'timezone' => get_option('timezone', 'UTC'),
                'default_category' => get_option('default_category', 1),
                'default_comment_status' => get_option('default_comment_status', 'open')
            ];
            
        case 'reading':
            return [
                'posts_per_rss' => get_option('posts_per_rss', 10),
                'rss_excerpt' => get_option('rss_excerpt', 'summary')
            ];
            
        case 'discussion':
            return [
                'default_comment_status' => get_option('default_comment_status', 'open'),
                'require_name_email' => get_option('require_name_email', 1),
                'comment_registration' => get_option('comment_registration', 0),
                'close_comments_days_old' => get_option('close_comments_days_old', 14),
                'comments_per_page' => get_option('comments_per_page', 20),
                'comment_order' => get_option('comment_order', 'asc'),
                'comment_moderation' => get_option('comment_moderation', 1),
                'comment_blacklist' => get_option('comment_blacklist', '')
            ];
            
        case 'media':
            return [
                'thumbnail_size_w' => get_option('thumbnail_size_w', 150),
                'thumbnail_size_h' => get_option('thumbnail_size_h', 150),
                'thumbnail_crop' => get_option('thumbnail_crop', 1),
                'medium_size_w' => get_option('medium_size_w', 300),
                'medium_size_h' => get_option('medium_size_h', 300),
                'large_size_w' => get_option('large_size_w', 1024),
                'large_size_h' => get_option('large_size_h', 1024),
                'uploads_use_yearmonth_folders' => get_option('uploads_use_yearmonth_folders', 1)
            ];
            
        case 'permalinks':
            return [
                'permalink_structure' => get_option('permalink_structure', '/%year%/%monthnum%/%postname%/'),
                'category_base' => get_option('category_base', 'category'),
                'tag_base' => get_option('tag_base', 'tag')
            ];
            
        default:
            send_api_error(404, '设置组不存在');
    }
}

/**
 * 更新设置
 *
 * @param array $params 请求参数
 * @return array 更新后的设置
 */
function api_update_settings($params) {
    // 检查权限
    if (!todo_current_user_can('manage_options')) {
        send_api_error(403, '没有权限更新设置');
    }
    
    // 验证参数
    if (!isset($params['group'])) {
        send_api_error(400, '缺少组参数');
    }
    
    if (!isset($params['settings']) || !is_array($params['settings'])) {
        send_api_error(400, '缺少设置参数');
    }
    
    $group = sanitize_text_field($params['group']);
    $settings = $params['settings'];
    $updated = [];
    
    // 根据组更新相应设置
    switch ($group) {
        case 'general':
            // 更新常规设置
            $allowed_keys = [
                'site_title', 'site_description', 'site_url', 'admin_email',
                'posts_per_page', 'date_format', 'time_format', 'timezone',
                'default_category', 'default_comment_status'
            ];
            
            foreach ($settings as $key => $value) {
                if (in_array($key, $allowed_keys)) {
                    // 验证和清理值
                    switch ($key) {
                        case 'site_title':
                        case 'site_description':
                            $value = sanitize_text_field($value);
                            break;
                            
                        case 'site_url':
                        case 'admin_email':
                            $value = sanitize_text_field($value);
                            break;
                            
                        case 'posts_per_page':
                            $value = max(1, intval($value));
                            break;
                            
                        case 'date_format':
                        case 'time_format':
                            $value = sanitize_text_field($value);
                            break;
                            
                        case 'timezone':
                            $value = sanitize_text_field($value);
                            break;
                            
                        case 'default_category':
                            $value = intval($value);
                            break;
                            
                        case 'default_comment_status':
                            $value = in_array($value, ['open', 'closed']) ? $value : 'open';
                            break;
                    }
                    
                    // 更新选项
                    update_option($key, $value);
                    $updated[$key] = $value;
                }
            }
            break;
            
        case 'reading':
            // 更新阅读设置
            $allowed_keys = [
                'posts_per_rss', 'rss_excerpt'
            ];
            
            foreach ($settings as $key => $value) {
                if (in_array($key, $allowed_keys)) {
                    // 验证和清理值
                    switch ($key) {
                        case 'posts_per_rss':
                            $value = max(1, intval($value));
                            break;
                            
                        case 'rss_excerpt':
                            $value = in_array($value, ['full', 'summary']) ? $value : 'summary';
                            break;
                    }
                    
                    // 更新选项
                    update_option($key, $value);
                    $updated[$key] = $value;
                }
            }
            break;
            
        case 'discussion':
            // 更新讨论设置
            $allowed_keys = [
                'default_comment_status', 'require_name_email', 'comment_registration',
                'close_comments_days_old', 'comments_per_page', 'comment_order',
                'comment_moderation', 'comment_blacklist'
            ];
            
            foreach ($settings as $key => $value) {
                if (in_array($key, $allowed_keys)) {
                    // 验证和清理值
                    switch ($key) {
                        case 'default_comment_status':
                            $value = in_array($value, ['open', 'closed']) ? $value : 'open';
                            break;
                            
                        case 'require_name_email':
                        case 'comment_registration':
                        case 'comment_moderation':
                            $value = intval($value) ? 1 : 0;
                            break;
                            
                        case 'close_comments_days_old':
                            $value = max(0, intval($value));
                            break;
                            
                        case 'comments_per_page':
                            $value = max(1, intval($value));
                            break;
                            
                        case 'comment_order':
                            $value = in_array($value, ['asc', 'desc']) ? $value : 'asc';
                            break;
                            
                        case 'comment_blacklist':
                            $value = sanitize_textarea_field($value);
                            break;
                    }
                    
                    // 更新选项
                    update_option($key, $value);
                    $updated[$key] = $value;
                }
            }
            break;
            
        case 'media':
            // 更新媒体设置
            $allowed_keys = [
                'thumbnail_size_w', 'thumbnail_size_h', 'thumbnail_crop',
                'medium_size_w', 'medium_size_h', 'large_size_w', 'large_size_h',
                'uploads_use_yearmonth_folders'
            ];
            
            foreach ($settings as $key => $value) {
                if (in_array($key, $allowed_keys)) {
                    // 验证和清理值
                    switch ($key) {
                        case 'thumbnail_size_w':
                        case 'thumbnail_size_h':
                        case 'medium_size_w':
                        case 'medium_size_h':
                        case 'large_size_w':
                        case 'large_size_h':
                            $value = max(0, intval($value));
                            break;
                            
                        case 'thumbnail_crop':
                        case 'uploads_use_yearmonth_folders':
                            $value = intval($value) ? 1 : 0;
                            break;
                    }
                    
                    // 更新选项
                    update_option($key, $value);
                    $updated[$key] = $value;
                }
            }
            break;
            
        case 'permalinks':
            // 更新固定链接设置
            $allowed_keys = [
                'permalink_structure', 'category_base', 'tag_base'
            ];
            
            foreach ($settings as $key => $value) {
                if (in_array($key, $allowed_keys)) {
                    // 验证和清理值
                    switch ($key) {
                        case 'permalink_structure':
                            $value = sanitize_text_field($value);
                            // 如果选择了自定义结构，使用提供的自定义值
                            if ($value === 'custom' && isset($settings['permalink_structure_custom'])) {
                                $custom = sanitize_text_field($settings['permalink_structure_custom']);
                                if ($custom !== '') {
                                    if (strpos($custom, '/') !== 0) {
                                        $custom = '/' . $custom;
                                    }
                                    $value = $custom;
                                } else {
                                    $value = '';
                                }
                            }
                            // 使用统一方法设置并刷新重写规则
                            set_permalink_structure($value);
                            $updated[$key] = $value;
                            continue 2; // 已处理，不再走默认update_option
                            
                        case 'category_base':
                        case 'tag_base':
                            $value = sanitize_title($value);
                            break;
                    }
                    
                    // 更新选项
                    update_option($key, $value);
                    $updated[$key] = $value;
                }
            }
            break;
            
        default:
            send_api_error(404, '设置组不存在');
    }
    
    // 返回更新后的设置
    return [
        'group' => $group,
        'updated' => $updated
    ];
}

// 注册API路由
register_api_route('settings', 'GET', 'api_get_settings');
register_api_route('settings/([a-z]+)', 'GET', 'api_get_setting_group');
register_api_route('settings/([a-z]+)', 'PUT', 'api_update_settings');