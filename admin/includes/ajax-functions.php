<?php
/**
 * TodoCMS AJAX Functions
 *
 * @package TodoCMS
 * @subpackage Administration
 */

// 防止直接访问
if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    die('Direct access denied');
}

/**
 * AJAX Meta Box Loader
 * 
 * 处理元框的AJAX加载请求
 */
function todo_ajax_meta_box_loader() {
    // 检查用户权限
    if (!current_user_can('edit_posts')) {
        todo_ajax_response(false, '权限不足');
        return;
    }
    
    // 检查CSRF令牌
    if (!Todo_Security_Manager::verify_request_csrf()) {
        todo_ajax_response(false, 'CSRF验证失败');
        return;
    }
    
    $meta_box_id = sanitize_text_field($_POST['meta_box_id'] ?? '');
    $post_id = intval($_POST['post_id'] ?? 0);
    
    if (empty($meta_box_id)) {
        todo_ajax_response(false, '缺少元框ID');
        return;
    }
    
    // 这里可以根据meta_box_id加载相应的元框内容
    $content = '';
    switch ($meta_box_id) {
        case 'post_meta':
            $content = '<div class="meta-box-content">文章元数据内容</div>';
            break;
        case 'post_categories':
            $content = '<div class="meta-box-content">分类选择内容</div>';
            break;
        default:
            $content = '<div class="meta-box-content">未知元框类型</div>';
    }
    
    todo_ajax_response(true, '元框加载成功', array('content' => $content));
}

/**
 * AJAX Heartbeat
 * 
 * 处理心跳检测AJAX请求，用于保持会话活跃
 */
function todo_ajax_heartbeat() {
    // 检查用户是否登录
    if (!is_user_logged_in()) {
        todo_ajax_response(false, '用户未登录');
        return;
    }
    
    // 更新用户最后活动时间（模拟登录时不记录活动痕迹）
    $user_id = get_current_user_id();
    if ($user_id && empty($_COOKIE['todo_impersonate']) && empty($_SESSION['is_impersonating'])) {
        update_user_meta($user_id, 'last_activity', time());
    }
    
    // 返回心跳响应
    todo_ajax_response(true, '心跳正常', array(
        'timestamp' => time(),
        'user_id' => $user_id,
        'session_remaining' => ini_get('session.gc_maxlifetime')
    ));
}

/**
 * AJAX响应辅助函数
 * 
 * @param bool $success 是否成功
 * @param string $message 消息
 * @param array $data 额外数据
 */
function todo_ajax_response($success, $message, $data = array()) {
    $response = array(
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'timestamp' => time()
    );
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// 注册AJAX处理器
add_action('todo_ajax_meta_box_loader', 'todo_ajax_meta_box_loader');
add_action('todo_ajax_heartbeat', 'todo_ajax_heartbeat');