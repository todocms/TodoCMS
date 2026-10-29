<?php
/**
 * 缓存异步检测接口
 * 
 * 用于在前端后台异步检查公共内容层是否存在时间驱动的缓存失效事件。
 * 注意：这里只允许处理公共内容 provider，禁止接入用户私有状态（会员到期、礼品卡、会话等）。
 * 这个机制利用 Stale-While-Revalidate 模式，防止慢查询阻塞真实用户的页面渲染。
 * 
 * @package TodoCMS
 */

// 注册路由
register_api_route('cache/async-check', 'POST', function() {
    // 限制每30秒最多执行一次数据库检查，防止并发查爆数据库
    // 注意：节流与水位线状态存独立文件（Todo_Unified_Cache_Manager 内部维护），
    // 不能放对象缓存——flush_all（clear_data_cache/on_option_updated）会把状态清掉，
    // 导致水位线回退 + 已处理标记丢失，同一事件被重复清理（删→生→删循环）
    if (class_exists('Todo_Unified_Cache_Manager')
        && method_exists('Todo_Unified_Cache_Manager', 'acquire_async_cache_check_slot')) {
        if (!Todo_Unified_Cache_Manager::acquire_async_cache_check_slot(30)) {
            return ['status' => 'skipped', 'reason' => 'throttled'];
        }
    }

    $last_known_timed_check = 0;
    if (class_exists('Todo_Unified_Cache_Manager')
        && method_exists('Todo_Unified_Cache_Manager', 'get_timed_invalidation_last_check')) {
        $last_known_timed_check = Todo_Unified_Cache_Manager::get_timed_invalidation_last_check();
    }

    // 刷新底层持久化基准值，供其它缓存判断逻辑复用。
    if (function_exists('todo_get_latest_post_time')) {
        todo_get_latest_post_time('any', true);
    }
    if (function_exists('todo_get_latest_ad_change_time')) {
        todo_get_latest_ad_change_time(true);
    }

    $now_ts = time();
    $window_since = $last_known_timed_check > 0 ? $last_known_timed_check : ($now_ts - 30);

    $result = null;
    if (class_exists('Todo_Unified_Cache_Manager')
        && method_exists('Todo_Unified_Cache_Manager', 'process_due_public_template_cache_invalidations')) {
        $result = Todo_Unified_Cache_Manager::process_due_public_template_cache_invalidations($window_since, $now_ts);
    } elseif (class_exists('Todo_Unified_Cache_Manager')
        && method_exists('Todo_Unified_Cache_Manager', 'process_due_template_cache_invalidations')) {
        $result = Todo_Unified_Cache_Manager::process_due_template_cache_invalidations($window_since, $now_ts);
    }

    if (class_exists('Todo_Unified_Cache_Manager')
        && method_exists('Todo_Unified_Cache_Manager', 'set_timed_invalidation_last_check')) {
        Todo_Unified_Cache_Manager::set_timed_invalidation_last_check($now_ts);
    }

    if (empty($result) || empty($result['changed'])) {
        return ['status' => 'checked', 'changed' => false];
    }

    $reasons = (array) ($result['reasons'] ?? array());
    $scope = 'template_html_targeted';
    if (!empty($result['full_clear'])) {
        $scope = 'template_html';
    } elseif ($reasons === array('coupon_changed')) {
        $scope = 'data';
    } elseif (in_array('coupon_changed', $reasons, true) || in_array('category_visibility_changed', $reasons, true)) {
        $scope = 'mixed';
    }

    return [
        'status' => 'cleared',
        'scope' => $scope,
        'reason' => implode(',', $reasons),
        'providers' => $result['providers'] ?? array(),
        'private_providers' => $result['private_providers'] ?? array(),
        'post_ids' => $result['post_ids'] ?? array(),
        'category_ids' => $result['category_ids'] ?? array(),
        'coupon_ids' => $result['coupon_ids'] ?? array(),
        'matched_files' => $result['matched_files'] ?? 0,
        'removed_files' => $result['removed_files'] ?? 0,
    ];
});
