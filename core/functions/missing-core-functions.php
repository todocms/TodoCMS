<?php
/**
 * TodoCMS 缺失核心函数补充
 * 
 * 补充系统中缺失的核心函数，确保系统正常运行
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * ========================================
 * AJAX 响应函数
 * ========================================
 */

/**
 * 发送AJAX错误响应
 *
 * @param string $message 错误消息
 * @param int $code 错误代码
 * @param array $data 额外数据
 */
if (!function_exists('todo_send_json_error')) {
    function todo_send_json_error($message = '', $code = 400, $data = array())
    {
        header('Content-Type: application/json');
        http_response_code($code);

        $response = array(
            'success' => false,
            'error' => $message,
            'code' => $code
        );

        if (!empty($data)) {
            $response['data'] = $data;
        }

        echo json_encode($response);
        exit;
    }
}

/**
 * 发送AJAX成功响应
 *
 * @param mixed $data 响应数据
 * @param string $message 成功消息
 */
if (!function_exists('todo_send_json_success')) {
    function todo_send_json_success($data = array(), $message = '')
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(200);

        $response = array(
            'success' => true,
            'data' => $data
        );

        if (!empty($message)) {
            $response['message'] = $message;
        }

        if (function_exists('todo_ensure_utf8')) {
            $response = todo_ensure_utf8($response);
        }

        $json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false) {
            $json = json_encode(['success' => true, 'data' => []], JSON_UNESCAPED_UNICODE);
        }
        echo $json;
        exit;
    }
}

/**
 * 发送通用AJAX JSON响应
 *
 * @param array $data 响应数据
 * @param int $code HTTP状态码
 */
if (!function_exists('todo_send_json')) {
    function todo_send_json($data = array(), $code = 200)
    {
        header('Content-Type: application/json');
        http_response_code($code);

        echo json_encode($data);
        exit;
    }
}

/**
 * AJAX成功响应（别名函数）
 *
 * @param mixed $data 响应数据
 * @param string $message 成功消息
 */
if (!function_exists('todo_ajax_success')) {
    function todo_ajax_success($data = array(), $message = '')
    {
        return todo_send_json_success($data, $message);
    }
}

/**
 * ========================================
 * 错误处理函数
 * ========================================
 */

/**
 * 检查是否为Todo错误对象
 *
 * @param mixed $thing 要检查的对象
 * @return bool 是否为错误对象
 */
if (!function_exists('is_todo_error')) {
    function is_todo_error($thing)
    {
        if (
            is_object($thing) && (
                $thing instanceof Exception ||
                (class_exists('Todo_Error') && $thing instanceof Todo_Error) ||
                (class_exists('CMS_Error') && is_a($thing, 'CMS_Error')) ||
                (method_exists($thing, 'get_error_code') && method_exists($thing, 'get_error_message'))
            )
        ) {
            return true;
        }

        // 检查数组格式的错误
        if (is_array($thing) && isset($thing['error']) && isset($thing['code'])) {
            return true;
        }

        return false;
    }
}

/**
 * ========================================
 * 重定向函数
 * ========================================
 */

/**
 * TodoCMS重定向函数
 *
 * @param string $url 重定向URL
 * @param int $status 状态码
 */
if (!function_exists('todo_redirect')) {
    function todo_redirect($url, $status_code = 302, $message = '')
    {
        $is_admin_request = defined('IS_ADMIN') && IS_ADMIN;
        $is_post_request = isset($_SERVER['REQUEST_METHOD']) && strtoupper((string) $_SERVER['REQUEST_METHOD']) === 'POST';

        if ($is_admin_request && $is_post_request) {
            $page_mode = 'dynamic';
            if (function_exists('todo_get_option')) {
                $page_mode = (string) todo_get_option('page_mode', 'dynamic');
            } elseif (function_exists('get_option')) {
                $page_mode = (string) get_option('page_mode', 'dynamic');
            }

            if ($page_mode === 'static') {
                $has_refresh_param = false;
                $url_query = (string) parse_url((string) $url, PHP_URL_QUERY);
                if ($url_query !== '') {
                    parse_str($url_query, $url_query_args);
                    $has_refresh_param = isset($url_query_args['todocache']) && $url_query_args['todocache'] === 'refresh';
                }

                if (!$has_refresh_param) {
                    if (function_exists('add_query_arg')) {
                        $url = add_query_arg('todocache', 'refresh', $url);
                    } else {
                        $parsed = parse_url((string) $url);
                        $q = array();
                        if (isset($parsed['query']) && $parsed['query'] !== '') {
                            parse_str($parsed['query'], $q);
                        }
                        $q['todocache'] = 'refresh';
                        $parsed['query'] = http_build_query($q);
                        $rebuilt = '';
                        if (isset($parsed['scheme'])) {
                            $rebuilt .= $parsed['scheme'] . '://';
                        }
                        if (isset($parsed['host'])) {
                            if (isset($parsed['user'])) {
                                $rebuilt .= $parsed['user'];
                                if (isset($parsed['pass'])) {
                                    $rebuilt .= ':' . $parsed['pass'];
                                }
                                $rebuilt .= '@';
                            }
                            $rebuilt .= $parsed['host'];
                        }
                        if (isset($parsed['port'])) {
                            $rebuilt .= ':' . $parsed['port'];
                        }
                        $rebuilt .= $parsed['path'] ?? '';
                        if ($parsed['query'] !== '') {
                            $rebuilt .= '?' . $parsed['query'];
                        }
                        if (isset($parsed['fragment'])) {
                            $rebuilt .= '#' . $parsed['fragment'];
                        }
                        $url = $rebuilt;
                    }
                }
            }
        }

        if (headers_sent()) {
            echo '<script type="text/javascript">window.location.href="' . esc_js($url) . '";</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . esc_attr($url) . '"></noscript>';
            exit;
        }

        header('Location: ' . $url, true, $status_code);
        exit;
    }
}

/**
 * 基础重定向函数
 *
 * @param string $url 重定向URL
 * @param int $status 状态码
 */
if (!function_exists('redirect')) {
    function redirect($url, $status = 302)
    {
        return todo_redirect($url, $status);
    }
}

/**
 * ========================================
 * HTML 辅助函数
 * ========================================
 */

/**
 * HTML checked 属性辅助函数
 *
 * @param mixed $checked 检查值
 * @param mixed $current 当前值
 * @param bool $echo 是否输出
 * @return string checked属性
 */
if (!function_exists('checked')) {
    function checked($checked, $current = true, $echo = true)
    {
        return todo_checked_selected_helper($checked, $current, $echo, 'checked');
    }
}

if (!function_exists('todo_checked')) {
    function todo_checked($checked, $current = true, $echo = true)
    {
        return todo_checked_selected_helper($checked, $current, $echo, 'checked');
    }
}

/**
 * HTML disabled 属性辅助函数
 *
 * @param mixed $disabled 禁用值
 * @param mixed $current 当前值
 * @param bool $echo 是否输出
 * @return string disabled属性
 */
if (!function_exists('disabled')) {
    function disabled($disabled, $current = true, $echo = true)
    {
        return todo_checked_selected_helper($disabled, $current, $echo, 'disabled');
    }
}

if (!function_exists('todo_disabled')) {
    function todo_disabled($disabled, $current = true, $echo = true)
    {
        return todo_checked_selected_helper($disabled, $current, $echo, 'disabled');
    }
}

/**
 * HTML selected 属性辅助函数
 *
 * @param mixed $selected 选中值
 * @param mixed $current 当前值
 * @param bool $echo 是否输出
 * @return string selected属性
 */
if (!function_exists('selected')) {
    function selected($selected, $current = true, $echo = true)
    {
        return todo_checked_selected_helper($selected, $current, $echo, 'selected');
    }
}

if (!function_exists('todo_selected')) {
    function todo_selected($selected, $current = true, $echo = true)
    {
        return todo_checked_selected_helper($selected, $current, $echo, 'selected');
    }
}

/**
 * 通用的checked/selected/disabled辅助函数
 *
 * @param mixed $helper 辅助值
 * @param mixed $current 当前值
 * @param bool $echo 是否输出
 * @param string $type 属性类型
 * @return string 属性字符串
 */
if (!function_exists('todo_checked_selected_helper')) {
    function todo_checked_selected_helper($helper, $current, $echo, $type)
    {
        if ((string) $helper === (string) $current) {
            $result = " $type='$type'";
        } else {
            $result = '';
        }

        if ($echo) {
            echo $result;
        }

        return $result;
    }
}

/**
 * ========================================
 * 媒体和上传函数
 * ========================================
 */

/**
 * 获取附件URL
 *
 * @param int $attachment_id 附件ID
 * @return string 附件URL
 */
if (!function_exists('todo_get_attachment_url')) {
    function todo_get_attachment_url($attachment_id)
    {
        global $tododb;

        if (empty($attachment_id)) {
            return '';
        }

        // 从数据库获取附件信息
        $attachment = $tododb->get_row(
            "SELECT * FROM {$tododb->prefix}posts WHERE ID = ? AND post_type = 'attachment'",
            array($attachment_id)
        );

        if (!$attachment) {
            return '';
        }

        // 获取文件路径
        $file_path = get_post_meta($attachment_id, '_todo_attached_file', true);

        if (empty($file_path)) {
            return '';
        }

        // 构建URL
        $upload_url = todo_get_upload_url();
        return trailingslashit($upload_url) . $file_path;
    }
}

/**
 * 获取上传目录路径
 *
 * @return string 上传目录路径
 */
if (!function_exists('todo_get_upload_dir')) {
    function todo_get_upload_dir()
    {
        $upload_path = get_option('upload_path', 'content/uploads');

        // 如果是相对路径，转换为绝对路径
        if (!path_is_absolute($upload_path)) {
            $upload_path = ABSPATH . ltrim($upload_path, '/');
        }

        return rtrim($upload_path, '/');
    }
}

/**
 * 获取上传目录URL
 *
 * @return string 上传目录URL
 */
if (!function_exists('todo_get_upload_url')) {
    function todo_get_upload_url()
    {
        $upload_url = get_option('upload_url_path', '');

        if (empty($upload_url)) {
            $site_url = function_exists('todo_get_site_url') ? todo_get_site_url() : get_option('site_url', home_url());
            $upload_url = trailingslashit($site_url) . 'content/uploads';
        }

        return rtrim($upload_url, '/');
    }
}

/**
 * 获取图片编辑器
 *
 * @param string $path 图片路径
 * @return object|false 图片编辑器对象或false
 */
if (!function_exists('todo_get_image_editor')) {
    function todo_get_image_editor($path)
    {
        if (!file_exists($path)) {
            return false;
        }

        // 检查GD扩展
        if (extension_loaded('gd')) {
            return new CMS_Image($path);
        }

        return false;
    }
}

/**
 * ========================================
 * 工具函数
 * ========================================
 */

/**
 * 格式化文件大小
 *
 * @param int $bytes 字节数
 * @param int $precision 精度
 * @return string 格式化后的大小
 */
if (!function_exists('size_format')) {
    function size_format($bytes, $precision = 2)
    {
        return todo_format_bytes($bytes, $precision);
    }
}

/**
 * 截取文字
 *
 * @param string $text 原文本
 * @param int $num_words 单词数量
 * @param string $more 省略符
 * @return string 截取后的文本
 */
if (!function_exists('todo_trim_words')) {
    function todo_trim_words($text, $num_words = 55, $more = '...')
    {
        if (empty($text)) {
            return '';
        }

        // 移除HTML标签
        $text = strip_tags($text);

        // 中文按字符截取
        if (preg_match('/[\x{4e00}-\x{9fff}]/u', $text)) {
            if (mb_strlen($text, 'UTF-8') > $num_words) {
                return mb_substr($text, 0, $num_words, 'UTF-8') . $more;
            }
            return $text;
        }

        // 英文按单词截取
        $words = explode(' ', $text);
        if (count($words) > $num_words) {
            return implode(' ', array_slice($words, 0, $num_words)) . $more;
        }

        return $text;
    }
}

/**
 * 获取当前URL
 *
 * @return string 当前URL
 */
if (!function_exists('get_current_url')) {
    function get_current_url()
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = todo_get_http_host();
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';

        return $protocol . '://' . $host . $uri;
    }
}

/**
 * 管理员通知函数
 *
 * @param string $message 通知消息
 * @param string $type 通知类型
 */
if (!function_exists('todo_admin_notices')) {
    function todo_admin_notices()
    {
        // 在CLI环境下跳过session操作
        if (php_sapi_name() === 'cli') {
            return;
        }

        if (!session_id() && !headers_sent()) {
            session_start();
        }

        // 1. 处理 Session 中的通知
        if (isset($_SESSION['admin_notices']) && !empty($_SESSION['admin_notices'])) {
            foreach ($_SESSION['admin_notices'] as $notice) {
                $type = isset($notice['type']) ? $notice['type'] : 'info';
                $message = isset($notice['message']) ? $notice['message'] : '';

                $allowed_types = array('success', 'error', 'warning', 'info');
                $type = in_array($type, $allowed_types, true) ? $type : 'info';
                $class = 'notice notice-' . $type . ' is-dismissible';

                echo '<div class="' . esc_attr($class) . '">';
                echo '<p>' . esc_html($message) . '</p>';
                echo '<button type="button" class="notice-dismiss">✕</button>';
                echo '</div>';
            }

            // 清除已显示的通知
            unset($_SESSION['admin_notices']);
        }
    }
}

/**
 * 添加管理通知
 *
 * @param string $message 通知消息
 * @param string $type 通知类型
 */
if (!function_exists('todo_add_admin_notice')) {
    function todo_add_admin_notice($message, $type = 'info')
    {
        // 在CLI环境下跳过session操作
        if (php_sapi_name() === 'cli') {
            return;
        }

        if (!session_id()) {
            session_start();
        }

        if (!isset($_SESSION['admin_notices'])) {
            $_SESSION['admin_notices'] = array();
        }

        $_SESSION['admin_notices'][] = array(
            'message' => $message,
            'type' => $type,
            'time' => time()
        );
    }
}

/**
 * 显示管理通知 (todo_admin_notices 的别名)
 */
if (!function_exists('todo_show_admin_notices')) {
    function todo_show_admin_notices()
    {
        todo_admin_notices();
    }
}

/**
 * CSRF令牌字段
 *
 * @param string $action 动作名称
 * @param string $name 字段名称
 * @param bool $referer 是否包含referer
 * @param bool $echo 是否输出
 * @return string HTML字段
 */
if (!function_exists('todo_csrf_token_field')) {
    function todo_csrf_token_field($action = -1, $name = '_csrf_token', $referer = true, $echo = true)
    {
        $name = esc_attr($name);
        $nonce_field = '<input type="hidden" id="' . $name . '" name="' . $name . '" value="' . todo_create_nonce($action) . '" />';

        if ($referer) {
            $nonce_field .= todo_referer_field(false);
        }

        if ($echo) {
            echo $nonce_field;
        }

        return $nonce_field;
    }
}

/**
 * ========================================
 * 路径和URL辅助函数
 * ========================================
 */

/**
 * 检查路径是否为绝对路径
 *
 * @param string $path 路径
 * @return bool 是否为绝对路径
 */
if (!function_exists('path_is_absolute')) {
    function path_is_absolute($path)
    {
        // Windows路径
        if (preg_match('/^[a-zA-Z]:[\\\\\/]/', $path)) {
            return true;
        }

        // Unix路径
        if (substr($path, 0, 1) === '/') {
            return true;
        }

        return false;
    }
}

/**
 * 确保路径以斜杠结尾
 *
 * @param string $string 字符串
 * @return string 处理后的字符串
 */
if (!function_exists('trailingslashit')) {
    function trailingslashit($string)
    {
        return untrailingslashit($string) . '/';
    }
}

/**
 * 移除路径末尾的斜杠
 *
 * @param string $string 字符串
 * @return string 处理后的字符串
 */
if (!function_exists('untrailingslashit')) {
    function untrailingslashit($string)
    {
        return rtrim($string, '/\\');
    }
}

/**
 * ========================================
 * 安全函数
 * ========================================
 */

/**
 * 创建nonce
 *
 * @param string $action 动作
 * @return string nonce值
 */
if (!function_exists('todo_create_nonce')) {
    function todo_create_nonce($action = 'default')
    {
        return Todo_Security_Manager::generate_csrf_token($action);
    }
}

/**
 * Referer字段
 *
 * @param bool $echo 是否输出
 * @return string HTML字段
 */
if (!function_exists('todo_referer_field')) {
    function todo_referer_field($echo = true)
    {
        $referer_field = '<input type="hidden" name="_todo_http_referer" value="' . esc_attr(todo_get_referer()) . '" />';

        if ($echo) {
            echo $referer_field;
        }

        return $referer_field;
    }
}

/**
 * 获取referer
 *
 * @return string referer URL
 */
if (!function_exists('todo_get_referer')) {
    function todo_get_referer()
    {
        if (!empty($_REQUEST['_todo_http_referer'])) {
            return $_REQUEST['_todo_http_referer'];
        } elseif (!empty($_SERVER['HTTP_REFERER'])) {
            return $_SERVER['HTTP_REFERER'];
        }

        return '';
    }
}

/**
 * ========================================
 * 字符串处理函数（PHP 8 兼容补充）
 * ========================================
 */
// str_starts_with (PHP 8) 兼容
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle)
    {
        $haystack = (string) $haystack;
        $needle = (string) $needle;
        if ($needle === '') {
            return true; // 空字符串始终匹配
        }
        return substr($haystack, 0, strlen($needle)) === $needle;
    }
}
// str_ends_with (PHP 8) 兼容
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle)
    {
        $haystack = (string) $haystack;
        $needle = (string) $needle;
        if ($needle === '') {
            return true; // 空字符串始终匹配
        }
        $needle_len = strlen($needle);
        if ($needle_len === 0) {
            return true;
        }
        if ($needle_len > strlen($haystack)) {
            return false;
        }
        return substr($haystack, -$needle_len) === $needle;
    }
}
// str_contains (PHP 8) 兼容
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle)
    {
        $haystack = (string) $haystack;
        $needle = (string) $needle;
        if ($needle === '') {
            return true; // 空字符串认为包含
        }
        return strpos($haystack, $needle) !== false;
    }
}

// TodoCMS 风格字符串辅助函数（建议在新代码中使用）
if (!function_exists('todo_str_starts_with')) {
    function todo_str_starts_with($haystack, $prefix)
    {
        return str_starts_with($haystack, $prefix);
    }
}
if (!function_exists('todo_str_ends_with')) {
    function todo_str_ends_with($haystack, $suffix)
    {
        return str_ends_with($haystack, $suffix);
    }
}
if (!function_exists('todo_str_contains')) {
    function todo_str_contains($haystack, $needle)
    {
        return str_contains($haystack, $needle);
    }
}

// 加载完成标记
if (!defined('TODO_MISSING_FUNCTIONS_LOADED')) {
    define('TODO_MISSING_FUNCTIONS_LOADED', true);
}
