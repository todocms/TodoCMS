<?php
/**
 * Points API Endpoints
 *
 * @package TodoCMS
 */

if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

/**
 * Get current user points and history
 */
function api_get_my_points($params) {
    $user_id = get_current_user_id();
    if (!$user_id) {
        send_api_error(401, '未授权');
    }

    $manager = Points_Manager::get_instance();
    $balance = $manager->get_balance($user_id);
    
    $page = isset($params['page']) ? max(1, intval($params['page'])) : 1;
    $per_page = 20;
    $offset = ($page - 1) * $per_page;
    
    $history = $manager->get_history($user_id, $per_page, $offset);
    
    return [
        'balance' => $balance,
        'can_check_in' => $manager->can_check_in($user_id),
        'history' => $history,
        'page' => $page
    ];
}

/**
 * Handle Daily Check-in
 */
function api_points_checkin($params) {
    $user_id = get_current_user_id();
    if (!$user_id) {
        send_api_error(401, '未授权');
    }

    $manager = Points_Manager::get_instance();
    $result = $manager->check_in($user_id);
    
    if ($result['status'] === 'error') {
        send_api_error(400, $result['message']);
    }
    
    return $result;
}

// Register routes
register_api_route('me/points', 'GET', 'api_get_my_points');
register_api_route('me/check-in', 'POST', 'api_points_checkin');
