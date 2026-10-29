<?php
/**
 * TodoCMS 核心函数
 *
 * 提供TodoCMS的核心功能函数
 *
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 验证CSRF令牌函数
 * 这是Todo_Security_Manager::verify_csrf_token的包装函数
 * 
 * @param string $token 令牌
 * @param string $action 动作名称，默认为'default'
 * @return bool 是否验证通过
 */
if (!function_exists('todo_verify_csrf_token')) {
    function todo_verify_csrf_token($token = null, $action = 'default')
    {
        // 确保安全管理器已加载
        if (!class_exists('Todo_Security_Manager')) {
            // 尝试加载安全管理器
            $security_manager_path = ABSPATH . 'core/classes/class-security-manager.php';
            if (file_exists($security_manager_path)) {
                require_once $security_manager_path;
                Todo_Security_Manager::init();
            } else {
                error_log('Todo Security Manager not found: ' . $security_manager_path);
                return false;
            }
        }

        return Todo_Security_Manager::verify_csrf_token($token, $action);
    }
}

/**
 * 生成CSRF令牌函数
 * 这是Todo_Security_Manager::generate_csrf_token的包装函数
 * 
 * @param string $action 动作名称，默认为'default'
 * @return string 生成的令牌
 */
if (!function_exists('todo_generate_csrf_token')) {
    function todo_generate_csrf_token($action = 'default')
    {
        // 确保安全管理器已加载
        if (!class_exists('Todo_Security_Manager')) {
            // 尝试加载安全管理器
            $security_manager_path = ABSPATH . 'core/classes/class-security-manager.php';
            if (file_exists($security_manager_path)) {
                require_once $security_manager_path;
                Todo_Security_Manager::init();
            } else {
                error_log('Todo Security Manager not found: ' . $security_manager_path);
                return '';
            }
        }

        return Todo_Security_Manager::generate_csrf_token($action);
    }
}

/**
 * 生成CSRF字段HTML函数
 * 这是Todo_Security_Manager::csrf_field的包装函数
 * 
 * @param string $action 动作名称，默认为'default'
 * @param string $field_name 字段名称，默认为'_todononce'
 * @return string HTML字段
 */
if (!function_exists('todo_csrf_field')) {
    function todo_csrf_field($action = 'default', $field_name = '_todononce')
    {
        // 确保安全管理器已加载
        if (!class_exists('Todo_Security_Manager')) {
            // 尝试加载安全管理器
            $security_manager_path = ABSPATH . 'core/classes/class-security-manager.php';
            if (file_exists($security_manager_path)) {
                require_once $security_manager_path;
                Todo_Security_Manager::init();
            } else {
                error_log('Todo Security Manager not found: ' . $security_manager_path);
                return '';
            }
        }

        return Todo_Security_Manager::csrf_field($action, $field_name);
    }
}

/**
 * 检查是否为结账页面
 * 
 * @return bool
 */
if (!function_exists('todo_is_checkout')) {
    function todo_is_checkout()
    {
        $page = function_exists('todo_get_query_context_value') ? (string) todo_get_query_context_value('page', '') : '';
        if ($page === 'checkout' || $page === 'payment' || $page === 'cart') {
            return true;
        }

        // 检查当前页面是否为结账页面
        $current_page = function_exists('todo_get_request_uri') ? todo_get_request_uri() : (function_exists('todo_server_string') ? todo_server_string('REQUEST_URI', '') : ($_SERVER['REQUEST_URI'] ?? ''));

        // 检查是否包含结账相关的路径
        if (
            strpos($current_page, '/checkout') !== false ||
            strpos($current_page, '/order') !== false ||
            strpos($current_page, '/payment') !== false
        ) {
            return true;
        }

        // 检查是否有结账相关的参数
        $action = function_exists('todo_get_query_context_value')
            ? (string) todo_get_query_context_value('action', (function_exists('todo_get_string') ? todo_get_string('action') : ''))
            : (function_exists('todo_get_string') ? todo_get_string('action') : '');
        if (in_array($action, ['checkout', 'order', 'payment'], true)) {
            return true;
        }

        return false;
    }
}

/**
 * 检查是否为订单支付页面
 * 
 * @return bool
 */
if (!function_exists('todo_is_order_pay')) {
    function todo_is_order_pay()
    {
        $page = function_exists('todo_get_query_context_value') ? (string) todo_get_query_context_value('page', '') : '';
        if ($page === 'payment') {
            return true;
        }

        // 检查当前页面是否为订单支付页面
        $current_page = function_exists('todo_get_request_uri') ? todo_get_request_uri() : (function_exists('todo_server_string') ? todo_server_string('REQUEST_URI', '') : ($_SERVER['REQUEST_URI'] ?? ''));

        // 检查是否包含支付相关的路径
        if (
            strpos($current_page, '/pay') !== false ||
            strpos($current_page, '/payment') !== false
        ) {
            return true;
        }

        // 检查是否有支付相关的参数
        $action = function_exists('todo_get_query_context_value')
            ? (string) todo_get_query_context_value('action', (function_exists('todo_get_string') ? todo_get_string('action') : ''))
            : (function_exists('todo_get_string') ? todo_get_string('action') : '');
        if (in_array($action, ['pay', 'payment'], true)) {
            return true;
        }

        // 检查是否有订单ID参数
        $order_id = function_exists('todo_get_query_context_value')
            ? todo_get_query_context_value('order_id', null)
            : null;
        if ($order_id !== null && $order_id !== '') {
            return true;
        }
        if (function_exists('todo_get_string') && todo_get_string('order_id') !== '') {
            return true;
        }
        if (function_exists('todo_post_string') && todo_post_string('order_id') !== '') {
            return true;
        }

        return false;
    }
}

/**
 * Tag Manager Functions
 * Moved from class-tag-manager.php to ensure global availability
 */

if (!function_exists('todo_get_tags')) {
    /**
     * 获取标签列表
     * 
     * @param array $args 查询参数
     * @return array
     */
    function todo_get_tags($args = array()) {
        return Todo_Tag_Manager::get_tags($args);
    }
}

if (!function_exists('todo_get_tag')) {
    /**
     * 获取标签信息
     * 
     * @param int $tag_id 标签ID
     * @return array|null
     */
    function todo_get_tag($tag_id) {
        return Todo_Tag_Manager::get_tag($tag_id);
    }
}

if (!function_exists('todo_get_tag_cloud')) {
    /**
     * 获取标签云
     * 
     * @param array $args 查询参数
     * @return array
     */
    function todo_get_tag_cloud($args = array()) {
        return Todo_Tag_Manager::get_tag_cloud($args);
    }
}

if (!function_exists('todo_get_tags_by_category')) {
    /**
     * 按栏目获取标签
     * 
     * @param int $category_id 栏目ID
     * @param bool $include_children 是否包含子栏目
     * @param array $args 额外查询参数
     * @return array
     */
    function todo_get_tags_by_category($category_id, $include_children = false, $args = array()) {
        return Todo_Tag_Manager::get_tags_by_category($category_id, $include_children, $args);
    }
}

if (!function_exists('todo_get_tags_by_model')) {
    /**
     * 按数据模型获取标签
     * 
     * @param string $model_key 模型标识
     * @param array $args 额外查询参数
     * @return array
     */
    function todo_get_tags_by_model($model_key, $args = array()) {
        return Todo_Tag_Manager::get_tags_by_model($model_key, $args);
    }
}

if (!function_exists('todo_get_popular_tags')) {
    /**
     * 获取热门标签
     * 
     * @param int $limit 数量限制
     * @param array $args 额外查询参数
     * @return array
     */
    function todo_get_popular_tags($limit = 10, $args = array()) {
        return Todo_Tag_Manager::get_popular_tags($limit, $args);
    }
}

if (!function_exists('todo_search_tags')) {
    /**
     * 搜索标签
     * 
     * @param string $keyword 关键词
     * @param int $limit 数量限制
     * @return array
     */
    function todo_search_tags($keyword, $limit = 20) {
        return Todo_Tag_Manager::search_tags($keyword, $limit);
    }
}
