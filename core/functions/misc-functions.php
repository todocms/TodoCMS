<?php
/**
 * TodoCMS 其他缺失函数
 * 修复剩余的未定义函数
 *
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 获取消息
 * @param string $key
 * @param array $params
 * @return string
 */
function getMessage($key, $params = []) {
    $messages = [
        'success' => '操作成功',
        'error' => '操作失败',
        'invalid_request' => '无效的请求',
        'permission_denied' => '权限不足',
        'file_not_found' => '文件未找到',
        'upload_success' => '上传成功',
        'upload_failed' => '上传失败',
        'delete_success' => '删除成功',
        'delete_failed' => '删除失败',
        'save_success' => '保存成功',
        'save_failed' => '保存失败',
        'login_required' => '请先登录',
        'invalid_credentials' => '用户名或密码错误',
        'user_not_found' => '用户不存在',
        'email_exists' => '邮箱已存在',
        'username_exists' => '用户名已存在'
    ];
    
    $message = isset($messages[$key]) ? $messages[$key] : $key;
    
    // 替换参数
    if (!empty($params)) {
        foreach ($params as $param_key => $param_value) {
            $message = str_replace('{' . $param_key . '}', $param_value, $message);
        }
    }
    
    return $message;
}

/**
 * 生成渐变色
 * @param string $start_color
 * @param string $end_color
 * @param string $direction
 * @return string
 */
function gradient($start_color, $end_color, $direction = 'to right') {
    return "linear-gradient({$direction}, {$start_color}, {$end_color})";
}

/**
 * 获取当前用户
 * @return array|null
 */
if (!function_exists('todo_get_current_user')) {
    function todo_get_current_user() {
        // 委托到用户管理器，确保前台在存在 todo_impersonate + remember_token 时优先使用令牌登录
        // 使用 Todo_Session_Manager 确保前后台会话隔离
        if (class_exists('Todo_User_Manager')) {
            $user = Todo_User_Manager::get_current_user();
            if ($user) {
                return $user;
            }
        }
        // 回退：使用 Todo_Session_Manager 获取用户ID
        $user_id = null;
        if (class_exists('Todo_Session_Manager')) {
            Todo_Session_Manager::init();
            if (defined('IS_ADMIN') && IS_ADMIN) {
                $user_id = Todo_Session_Manager::get_admin_user_id();
            } else {
                $user_id = Todo_Session_Manager::get_frontend_user_id();
            }
        }
        if (!$user_id) {
            return null;
        }
        $db = get_database_connection();
        if (!$db) {
            return null;
        }
        try {
            $table = $db->prefix . 'users';
            $sql = "SELECT * FROM {$table} WHERE ID = ?";
            $user = $db->get_row($sql, [$user_id], 'OBJECT');
            return $user ?: null;
        } catch (PDOException $e) {
            todo_log('Get current user failed: ' . $e->getMessage(), 'error');
            return null;
        }
    }
}

/**
 * 检查用户是否已登录
 * @return bool
 */
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in() {
        // 若存在 remember_token 或 todo_impersonate 标记，优先使用 Todo_User_Manager 加载并验证用户
        $has_token = isset($_COOKIE['remember_token']) || isset($_COOKIE['todo_remember_token']) || (isset($_COOKIE['todo_impersonate']) && $_COOKIE['todo_impersonate'] === '1');
        if ($has_token && class_exists('Todo_User_Manager')) {
            $user = Todo_User_Manager::get_current_user();
            if ($user) {
                return true;
            }
        }
        // 使用 Todo_Session_Manager 检查登录状态
        if (class_exists('Todo_Session_Manager')) {
            Todo_Session_Manager::init();
            if (defined('IS_ADMIN') && IS_ADMIN) {
                return Todo_Session_Manager::is_admin_logged_in();
            } else {
                if (Todo_Session_Manager::is_frontend_logged_in()) {
                    return true;
                }
            }
        }
        // 回退：用户管理器检查
        if (class_exists('Todo_User_Manager')) {
            return Todo_User_Manager::is_logged_in() || (Todo_User_Manager::get_current_user() !== null);
        }
        return false;
    }
}

/**
 * 检测当前是否为iframe模式
 * 
 * @return bool
 */
function todo_is_iframe_mode() {
    return (isset($_GET['format']) && $_GET['format'] === 'iframe') || 
           (isset($_POST['format']) && $_POST['format'] === 'iframe');
}

/**
 * 检测当前是否为AJAX请求
 * 
 * @return bool
 */
function todo_is_ajax_request() {
    return strtolower(todo_server_string('HTTP_X_REQUESTED_WITH')) === 'xmlhttprequest';
}

/**
 * 检测当前是否需要返回JSON
 * 
 * @return bool
 */
function todo_should_return_json() {
    if (todo_is_ajax_request()) {
        return true;
    }

    $accept = isset($_SERVER['HTTP_ACCEPT']) ? strtolower((string) $_SERVER['HTTP_ACCEPT']) : '';
    if (
        $accept !== '' && (
            strpos($accept, 'application/json') !== false ||
            strpos($accept, 'text/json') !== false ||
            strpos($accept, '+json') !== false
        )
    ) {
        return true;
    }

    return false;
}

/**
 * 发送统一的AJAX响应
 * 
 * @param bool $success 操作是否成功
 * @param string $message 提示消息
 * @param mixed $data 返回的业务数据
 * @param string|null $redirect 重定向URL
 * @param array $errors 错误详情列表
 */
function todo_send_response($success, $message, $data = null, $redirect = null, $errors = []) {
    if (todo_should_return_json()) {
        // 清理输出缓冲区，确保只返回 JSON，防止 HTML 污染
        while (ob_get_level()) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
        }
        echo json_encode([
            'success' => (bool)$success,
            'message' => (string)$message,
            'data' => $data,
            'redirect' => $redirect,
            'errors' => (array)$errors
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        // 传统模式处理
        if ($success && $redirect) {
            if (!headers_sent()) {
                header('Location: ' . $redirect);
                exit;
            }
        } else {
            // 简单的消息显示
            echo '<html><body>';
            echo '<h1>' . ($success ? '成功' : '错误') . '</h1>';
            echo '<p>' . htmlspecialchars($message) . '</p>';
            echo '<a href="javascript:history.back()">返回</a>';
            echo '</body></html>';
            exit;
        }
    }
}

/**
 * 发送成功响应
 * 
 * @param string $message 成功消息
 * @param mixed $data 返回的业务数据
 * @param string|null $redirect 重定向URL
 */
function todo_send_success($message, $data = null, $redirect = null) {
    todo_send_response(true, $message, $data, $redirect);
}

/**
 * 发送错误响应
 * 
 * @param string $message 错误消息
 * @param array $errors 错误详情列表
 * @param mixed $data 返回的业务数据
 */
function todo_send_error($message, $errors = [], $data = null) {
    todo_send_response(false, $message, $data, null, $errors);
}

/**
 * 重定向到指定URL (Iframe感知)
 * 
 * 在iframe模式或AJAX请求下，返回JSON响应告知前端进行重定向。
 * 在普通模式下，发送Location头进行重定向。
 * 
 * @param string $url
 * @param int $status_code
 * @param string $message
 */
if (!function_exists('todo_redirect')) {
    function todo_redirect($url, $status_code = 302, $message = '') {
        $is_admin_request = defined('IS_ADMIN') && IS_ADMIN;
        $is_post_request = isset($_SERVER['REQUEST_METHOD']) && strtoupper((string) $_SERVER['REQUEST_METHOD']) === 'POST';

        if ($is_admin_request && $is_post_request) {
            $page_mode = function_exists('todo_get_option') ? (string) todo_get_option('page_mode', 'dynamic') : (function_exists('get_option') ? (string) get_option('page_mode', 'dynamic') : 'dynamic');
            if ($page_mode === 'static') {
                $has_refresh_param = false;
                $url_query = (string) parse_url((string) $url, PHP_URL_QUERY);
                if ($url_query !== '') {
                    parse_str($url_query, $url_query_args);
                    $has_refresh_param = isset($url_query_args['todocache']) && $url_query_args['todocache'] === 'refresh';
                }
                if (!$has_refresh_param && function_exists('add_query_arg')) {
                    $url = add_query_arg('todocache', 'refresh', $url);
                }
            }
        }

        if (function_exists('todo_is_iframe_mode') && todo_is_iframe_mode()) {
            $is_internal = strpos($url, 'http') !== 0 || strpos($url, todo_get_site_url()) === 0;
            if ($is_internal && strpos($url, 'format=iframe') === false) {
                 $path_parts = parse_url($url);
                 $path = isset($path_parts['path']) ? $path_parts['path'] : '';
                 $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                 $static_exts = array('js', 'css', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'woff', 'woff2', 'ttf', 'eot');
                 
                 if (!in_array($ext, $static_exts)) {
                     $url = add_query_arg('format', 'iframe', $url);
                 }
            }
        }

        if (todo_should_return_json()) {
            todo_send_response(true, $message, null, $url);
        } else {
            if (!headers_sent()) {
                while (ob_get_level()) {
                    ob_end_clean();
                }
                header("Location: {$url}", true, $status_code);
                exit;
            } else {
                echo '<script type="text/javascript">window.location.href="' . $url . '";</script>';
                echo '<noscript><meta http-equiv="refresh" content="0;url=' . $url . '" /></noscript>';
                exit;
            }
        }
    }
}

/**
 * 获取分页HTML
 * @param int $current_page
 * @param int $total_pages
 * @param string $base_url
 * @return string
 */
function get_pagination_html($current_page, $total_pages, $base_url) {
    if ($total_pages <= 1) {
        return '';
    }
    
    $html = '<div class="pagination">';
    
    // 上一页
    if ($current_page > 1) {
        $prev_url = $base_url . '?page=' . ($current_page - 1);
        $html .= '<a href="' . $prev_url . '" class="prev">上一页</a>';
    }
    
    // 页码
    $start = max(1, $current_page - 2);
    $end = min($total_pages, $current_page + 2);
    
    if ($start > 1) {
        $html .= '<a href="' . $base_url . '?page=1">1</a>';
        if ($start > 2) {
            $html .= '<span class="dots">...</span>';
        }
    }
    
    for ($i = $start; $i <= $end; $i++) {
        if ($i == $current_page) {
            $html .= '<span class="current">' . $i . '</span>';
        } else {
            $html .= '<a href="' . $base_url . '?page=' . $i . '">' . $i . '</a>';
        }
    }
    
    if ($end < $total_pages) {
        if ($end < $total_pages - 1) {
            $html .= '<span class="dots">...</span>';
        }
        $html .= '<a href="' . $base_url . '?page=' . $total_pages . '">' . $total_pages . '</a>';
    }
    
    // 下一页
    if ($current_page < $total_pages) {
        $next_url = $base_url . '?page=' . ($current_page + 1);
        $html .= '<a href="' . $next_url . '" class="next">下一页</a>';
    }
    
    $html .= '</div>';
    
    return $html;
}

/**
 * 获取面包屑导航
 * @param array $breadcrumbs
 * @return string
 */
function get_breadcrumb_html($breadcrumbs) {
    if (empty($breadcrumbs)) {
        return '';
    }
    
    $html = '<nav class="breadcrumb">';
    $html .= '<a href="' . todo_get_site_url() . '">首页</a>';
    
    foreach ($breadcrumbs as $breadcrumb) {
        $html .= ' > ';
        if (isset($breadcrumb['url'])) {
            $html .= '<a href="' . $breadcrumb['url'] . '">' . $breadcrumb['title'] . '</a>';
        } else {
            $html .= '<span>' . $breadcrumb['title'] . '</span>';
        }
    }
    
    $html .= '</nav>';
    
    return $html;
}

/**
 * 获取文件MIME类型
 * @param string $file_path
 * @return string
 */
function get_mime_type($file_path) {
    if (function_exists('finfo_file')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file_path);
        finfo_close($finfo);
        return $mime_type;
    }
    
    if (function_exists('mime_content_type')) {
        return mime_content_type($file_path);
    }
    
    // 备用方案：根据扩展名判断
    $extension = get_file_extension($file_path);
    $mime_types = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt' => 'text/plain',
        'zip' => 'application/zip'
    ];
    
    return $mime_types[$extension] ?? 'application/octet-stream';
}

/**
 * 安全地包含文件
 * @param string $file_path
 * @param array $variables
 * @return mixed
 */
function safe_include($file_path, $variables = []) {
    if (!file_exists($file_path)) {
        return false;
    }
    
    // 提取变量到当前作用域
    if (!empty($variables)) {
        extract($variables, EXTR_SKIP);
    }
    
    include $file_path;
    $content = ob_get_clean();
    
    return $content;
}

/**
 * 渲染模板
 * @param string $template_name
 * @param array $variables
 * @param string $theme
 * @return string
 */
function render_template($template_name, $variables = [], $theme = null) {
    $template_path = get_template_path($template_name);
    
    if (!$template_path) {
        return '';
    }
    
    return safe_include($template_path, $variables);
}
