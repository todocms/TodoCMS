<?php
/**
 * 站点API端点
 *
 * 提供多站点列表的API端点
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

/**
 * 获取所有活动站点列表
 *
 * @param array $params 请求参数
 * @return array 站点数据
 */
function api_get_sites($params) {
    if (!function_exists('todo_get_sites')) {
        return ['sites' => []];
    }
    
    try {
        // 获取所有状态为 active 的站点
        $sites = todo_get_sites(['status' => 'active']);
        $result = [];
        
        foreach ((array)$sites as $site) {
            // 处理 Logo: 可能是 JSON (来自特色图片组件) 或 纯URL
            $logo_url = '';
            if (!empty($site->site_logo)) {
                $raw_logo = $site->site_logo;
                if (is_string($raw_logo) && strpos($raw_logo, '[') === 0) {
                    $decoded = json_decode($raw_logo, true);
                    if (is_array($decoded) && !empty($decoded[0]['url'])) {
                        $logo_url = $decoded[0]['url'];
                    }
                } else {
                    $logo_url = $raw_logo;
                }
            }
            
            // 如果站点没有 Logo，回退到全局设置
            if (empty($logo_url)) {
                $global_logo = get_option('site_logo');
                if (is_string($global_logo) && strpos($global_logo, '[') === 0) {
                    $decoded = json_decode($global_logo, true);
                    if (is_array($decoded) && !empty($decoded[0]['url'])) {
                        $logo_url = $decoded[0]['url'];
                    }
                } else {
                    $logo_url = $global_logo;
                }
            }

            // 补全 URL
            if (!empty($logo_url) && strpos($logo_url, 'http') !== 0) {
                $logo_url = todo_home_url($logo_url);
            }

            $result[] = [
                'id' => (int)$site->id,
                'slug' => $site->slug,
                'name' => $site->name,
                'site_level' => isset($site->site_level) ? (string) $site->site_level : '',
                'site_logo' => $logo_url,
                'service_slogan' => isset($site->service_slogan) ? $site->service_slogan : '',
                'service_coords' => isset($site->service_coords) ? $site->service_coords : '',
                'service_address' => isset($site->service_address) ? str_replace('\n', "\n", html_entity_decode($site->service_address, ENT_QUOTES, 'UTF-8')) : '',
                'is_default' => (bool)$site->is_default,
                'config' => !empty($site->config) ? json_decode($site->config, true) : new stdClass()
            ];
        }
        
        return [
            'sites' => $result,
            'site_title' => get_option('site_title', '青翠集市')
        ];
    } catch (Throwable $e) {
        error_log('API Error (sites): ' . $e->getMessage());
        return ['sites' => []];
    }
}

// 注册API路由
if (function_exists('register_api_route')) {
    register_api_route('sites', 'GET', 'api_get_sites');
}
