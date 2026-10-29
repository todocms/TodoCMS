<?php
/**
 * 广告 API 端点
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

/**
 * 获取单个广告详情
 */
function api_get_ad($params) {
    if (!isset($params['id'])) {
        send_api_error(400, '缺少广告ID');
    }
    
    $ad_id = intval($params['id']);
    
    if (class_exists('Todo_Ads_Manager')) {
        $ad = Todo_Ads_Manager::get_ad($ad_id);
        if ($ad) {
            // 规范化图片地址
            if (!empty($ad['image_url'])) {
                $ad['image_url'] = api_normalize_media_url($ad['image_url']);
            }
            if (!empty($ad['images'])) {
                $images = json_decode($ad['images'], true);
                if (is_array($images)) {
                    $ad['images'] = array_map('api_normalize_media_url', $images);
                }
            }
            return $ad;
        }
    }
    
    send_api_error(404, '广告不存在');
}

// 注册路由
if (function_exists('register_api_route')) {
    register_api_route('ads/([0-9]+)', 'GET', 'api_get_ad');
}
