<?php
/**
 * TodoCMS 安全函数库
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问（但允许AJAX请求）
if (!defined('ABSPATH') && !defined('DOING_AJAX')) {
    exit('Direct access denied');
}

/**
 * 创建nonce令牌
 *
 * @param string $action 动作名称
 * @return string
 */
if (!function_exists('todo_create_nonce')) {
    function todo_create_nonce($action = 'default')
    {
        return Todo_Security_Manager::generate_csrf_token($action);
    }
}

/**
 * 验证nonce令牌
 *
 * @param string $nonce 令牌
 * @param string $action 动作名称
 * @return bool
 */
if (!function_exists('todo_verify_nonce')) {
    function todo_verify_nonce($nonce, $action = 'default')
    {
        return Todo_Security_Manager::verify_csrf_token($nonce, $action);
    }
}

/**
 * 输出nonce字段
 *
 * @param string $action 动作名称
 * @param string $name 字段名称
 * @param bool $referer 是否包含referer
 * @param bool $echo 是否输出
 * @return string
 */
if (!function_exists('todo_nonce_field')) {
    function todo_nonce_field($action = -1, $name = "_todononce", $referer = true, $echo = true)
    {
        $nonce = todo_create_nonce($action);
        $field = '<input type="hidden" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr($nonce) . '" />';

        if ($referer) {
            $field .= todo_referer_field(false);
        }

        if ($echo) {
            echo $field;
        }

        return $field;
    }
}

/**
 * 清理键名
 *
 * @param string $key 键名
 * @return string
 */
if (!function_exists('todo_sanitize_key')) {
    function todo_sanitize_key($key)
    {
        $key = strtolower($key);
        $key = preg_replace('/[^a-z0-9_\-]/', '', $key ?? '');
        return $key;
    }
}

if (!function_exists('todo_pre_kses_less_than')) {
    function todo_pre_kses_less_than($text)
    {
        $text = (string) $text;
        if ($text === '' || strpos($text, '<') === false) {
            return $text;
        }
        $text = preg_replace('/<(?=\s)/', '&lt;', $text);
        $text = preg_replace('/<(?=[^a-zA-Z\\/!\\?%])/', '&lt;', $text);
        return (string) $text;
    }
}

/**
 * 内部文本字段清理函数
 *
 * @param string $str 要清理的字符串
 * @param bool $keep_newlines 是否保留换行符
 * @return string
 */
if (!function_exists('_sanitize_text_fields')) {
    function _sanitize_text_fields($str, $keep_newlines = false)
    {
        if (is_object($str) || is_array($str)) {
            return '';
        }

        $str = (string) $str;

        $filtered = todo_check_invalid_utf8($str);

        if (strpos($filtered, '<') !== false) {
            $filtered = todo_pre_kses_less_than($filtered);
            // 移除所有HTML标签，但保留内容
            $filtered = strip_tags($filtered);
            $filtered = str_replace('<', '&lt;', $filtered);
        }

        if (!$keep_newlines) {
            $filtered = preg_replace('/[\r\n\t ]+/', ' ', $filtered ?? '');
        }

        $filtered = trim($filtered);

        // 移除零宽度字符
        $filtered = str_replace(array("\0", "\x08", "\x0B", "\x0C", "\x0E", "\x0F", "\x10", "\x11", "\x12", "\x13", "\x14", "\x15", "\x16", "\x17", "\x18", "\x19", "\x1A"), '', $filtered);

        return $filtered;
    }
}

/**
 * 清理文本域内容
 *
 * @param string $str 文本内容
 * @return string
 */
if (!function_exists('todo_sanitize_textarea_field')) {
    function todo_sanitize_textarea_field($str)
    {
        $filtered = _sanitize_text_fields($str, true);
        return $filtered;
    }
}

if (!function_exists('todo_claim_submit_token')) {
    function todo_claim_submit_token($key, $ttl_seconds = 120)
    {
        $key = trim((string) $key);
        if ($key === '') {
            return true;
        }
        $ttl_seconds = intval($ttl_seconds);
        if ($ttl_seconds <= 0) {
            $ttl_seconds = 120;
        }
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return true;
        }
        if (!isset($_SESSION['todo_submit_tokens']) || !is_array($_SESSION['todo_submit_tokens'])) {
            $_SESSION['todo_submit_tokens'] = [];
        }
        $now = time();
        foreach ($_SESSION['todo_submit_tokens'] as $tk => $exp) {
            if (!is_int($exp) && !is_numeric($exp)) {
                unset($_SESSION['todo_submit_tokens'][$tk]);
                continue;
            }
            if ($now > intval($exp)) {
                unset($_SESSION['todo_submit_tokens'][$tk]);
            }
        }
        $k = hash('sha256', $key);
        if (isset($_SESSION['todo_submit_tokens'][$k]) && $now <= intval($_SESSION['todo_submit_tokens'][$k])) {
            return false;
        }
        $_SESSION['todo_submit_tokens'][$k] = $now + $ttl_seconds;
        return true;
    }
}
