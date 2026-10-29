<?php
/**
 * 菜单 API 端点
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

/**
 * 获取菜单项详情
 */
function api_get_menu_items($params) {
    if (!isset($params['id'])) {
        send_api_error(400, '缺少菜单ID');
    }
    
    $menu_id = intval($params['id']);
    
    if (class_exists('Todo_Menu_Manager')) {
        $items = Todo_Menu_Manager::get_menu_items($menu_id, true);
        if ($items) {
            return [
                'id' => $menu_id,
                'items' => $items
            ];
        }
    }
    
    return [
        'id' => $menu_id,
        'items' => []
    ];
}

// 注册路由
if (function_exists('register_api_route')) {
    register_api_route('menus/([0-9]+)', 'GET', 'api_get_menu_items');
}
