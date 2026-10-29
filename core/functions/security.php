<?php
/**
 * 安全函数文件
 *
 * 包含数据验证、清理和安全相关的函数
 *
 * @package TodoCMS
 * @version 1.0.0
 */
 

/**
 * 检查当前连接是否使用SSL/TLS加密
 *
 * @return bool 如果使用SSL/TLS则返回true，否则返回false
 */
if (!function_exists('is_ssl')) {
    function is_ssl() {
        if (todo_server_string('HTTPS') === 'on' || todo_server_string('HTTPS') === '1') {
            return true;
        }
        
        if (todo_server_string('SERVER_PORT') === '443') {
            return true;
        }
        
        if (todo_server_string('HTTP_X_FORWARDED_PROTO') === 'https') {
            return true;
        }
        
        return false;
    }
}

if (!function_exists('todo_server_string')) {
    /**
     * Safely fetch a scalar server value as string.
     *
     * @param string $key
     * @param string $default
     * @return string
     */
    function todo_server_string($key, $default = '')
    {
        if (!isset($_SERVER[$key])) {
            return (string) $default;
        }
        $value = $_SERVER[$key];
        if (is_array($value) || is_object($value)) {
            return (string) $default;
        }
        return (string) $value;
    }
}

if (!function_exists('todo_get_string')) {
    /**
     * Safely fetch a GET parameter as string.
     *
     * @param string $key
     * @param string $default
     * @return string
     */
    function todo_get_string($key, $default = '')
    {
        if (!isset($_GET[$key]) || is_array($_GET[$key]) || is_object($_GET[$key])) {
            return (string) $default;
        }
        return (string) $_GET[$key];
    }
}

if (!function_exists('todo_post_string')) {
    /**
     * Safely fetch a POST parameter as string.
     *
     * @param string $key
     * @param string $default
     * @return string
     */
    function todo_post_string($key, $default = '')
    {
        if (!isset($_POST[$key]) || is_array($_POST[$key]) || is_object($_POST[$key])) {
            return (string) $default;
        }
        return (string) $_POST[$key];
    }
}

if (!function_exists('todo_request_string')) {
    /**
     * Safely fetch a REQUEST parameter as string.
     *
     * @param string $key
     * @param string $default
     * @return string
     */
    function todo_request_string($key, $default = '')
    {
        if (!isset($_REQUEST[$key]) || is_array($_REQUEST[$key]) || is_object($_REQUEST[$key])) {
            return (string) $default;
        }
        return (string) $_REQUEST[$key];
    }
}

if (!function_exists('todo_get_int')) {
    /**
     * Safely fetch a GET parameter as int.
     *
     * @param string $key
     * @param int $default
     * @return int
     */
    function todo_get_int($key, $default = 0)
    {
        return (int) todo_get_string($key, (string) $default);
    }
}

if (!function_exists('todo_post_int')) {
    /**
     * Safely fetch a POST parameter as int.
     *
     * @param string $key
     * @param int $default
     * @return int
     */
    function todo_post_int($key, $default = 0)
    {
        return (int) todo_post_string($key, (string) $default);
    }
}

/**
 * 转义HTML特殊字符
 *
 * @param string $text 需要转义的文本
 * @return string 转义后的文本
 */
if (!function_exists('esc_html')) {
    function esc_html($text) {
        // 如果是数组，返回空字符串或处理数组
        if (is_array($text)) {
            return '';
        }
        // 如果不是字符串，转换为字符串
        if (!is_string($text)) {
            $text = (string) $text;
        }
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * 转义HTML属性
 *
 * @param string $text 需要转义的文本
 * @return string 转义后的文本
 */
if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        // 如果是数组，返回空字符串或处理数组
        if (is_array($text)) {
            return '';
        }
        // 如果不是字符串，转换为字符串
        if (!is_string($text)) {
            $text = (string) $text;
        }
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * 转义URL
 *
 * @param string $url 需要转义的URL
 * @return string 转义后的URL
 */
if (!function_exists('esc_url')) {
    function esc_url($url) {
        // 如果是数组，返回空字符串
        if (is_array($url)) {
            return '';
        }
        // 如果不是字符串，转换为字符串
        if (!is_string($url)) {
            $url = (string) $url;
        }

        // 去除首尾空白和控制字符
        $url = trim((string) $url);
        $url = preg_replace('/[\x00-\x1F\x7F]/', '', $url);
        if ($url === '') {
            return '';
        }

        // data: 协议 Data-URI 特例直接过滤控制字符放行，避免转义破坏 Base64
        if (strpos($url, 'data:image/') === 0) {
            return $url;
        }

        // 仅允许安全协议（含 Base64 占位图 data 协议），或站内相对路径
        if (preg_match('#^([a-z][a-z0-9+\.-]*):#i', $url, $m)) {
            $scheme = strtolower($m[1]);
            $allowed = array('http', 'https', 'ftp', 'mailto', 'tel', 'data');
            if (!in_array($scheme, $allowed, true)) {
                return '';
            }
        }

        // 修复形如 %HH_ 的错误百分号编码碎片（如 %E6_ → %E6）
        $url = preg_replace('/%([0-9A-Fa-f]{2})_/', '%$1', $url);

        // 分解（避免直接使用 parse_url 破坏中文字符）
        $scheme_authority = '';
        $path = $url;
        $query = '';
        $fragment = '';

        if (preg_match('~^([a-z][a-z0-9+\.-]*://[^/?#]*)([^?#]*)?(\?[^#]*)?(#.*)?$~i', $url, $m)) {
            $scheme_authority = $m[1];
            $path = isset($m[2]) ? $m[2] : '';
            $query = isset($m[3]) ? $m[3] : '';
            $fragment = isset($m[4]) ? $m[4] : '';
        } else {
            // 站内相对或路径型URL，拆出查询与片段
            $hashPos = strpos($path, '#');
            if ($hashPos !== false) {
                $fragment = substr($path, $hashPos);
                $path = substr($path, 0, $hashPos);
            }
            $qPos = strpos($path, '?');
            if ($qPos !== false) {
                $query = substr($path, $qPos);
                $path = substr($path, 0, $qPos);
            }
        }

        // 逐段编码 path，保护已存在的合法编码，正确处理中文等非ASCII字符
        $encoded_segments = array();
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                $encoded_segments[] = $seg;
                continue;
            }
            // 先对已有的 %XX 解码，防止重复编码
            $decoded = rawurldecode($seg);
            // 再进行 RFC3986 风格编码
            $encoded = rawurlencode($decoded);
            // 保留常见安全字符
            $encoded = str_replace(array('%2D','%5F','%2E','%7E'), array('-', '_', '.', '~'), $encoded);
            $encoded_segments[] = $encoded;
        }
        $safe_path = implode('/', $encoded_segments);

        // 清理查询与片段中的控制字符（不改动键值）
        if ($query !== '') {
            $query = preg_replace('/[\x00-\x1F\x7F]/', '', $query);
        }
        if ($fragment !== '') {
            $fragment = preg_replace('/[\x00-\x1F\x7F]/', '', $fragment);
        }

        // 重建URL
        $rebuilt = $scheme_authority . $safe_path . $query . $fragment;
        return $rebuilt;
    }
}

/**
 * 转义textarea内容
 *
 * @param string $text 需要转义的文本
 * @return string 转义后的文本
 */
if (!function_exists('esc_textarea')) {
    function esc_textarea($text) {
        // 如果是数组，返回空字符串
        if (is_array($text)) {
            return '';
        }
        // 如果不是字符串，转换为字符串
        if (!is_string($text)) {
            $text = (string) $text;
        }
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * 清理文本字段
 *
 * @param string $text 需要清理的文本
 * @return string 清理后的文本
 */
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) {
        if (is_array($text)) return '';
        if (!is_string($text)) $text = (string)$text;
        $text = trim($text);
        $text = strip_tags($text);
        $text = preg_replace('/[\r\n\t ]+/', ' ', $text ?? '');
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        return $text;
    }
}

/**
 * 清理邮箱地址
 *
 * @param string $email 需要清理的邮箱地址
 * @return string 清理后的邮箱
 */
if (!function_exists('sanitize_email')) {
    function sanitize_email($email) {
        if (is_array($email)) return '';
        $email = trim((string)$email);
        return filter_var($email, FILTER_SANITIZE_EMAIL);
    }
}

/**
 * 清理TextArea内容
 *
 * @param string $text 需要清理的文本
 * @return string 清理后的文本
 */
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($text) {
        if (function_exists('todo_sanitize_textarea_field')) {
            $text = todo_sanitize_textarea_field($text);
        } else {
            if (is_array($text) || is_object($text)) return '';
            $text = (string) $text;
            $text = strip_tags($text);
            $text = trim($text);
        }

        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
        $text = preg_replace("/\n{2,}/", "\n\n", $text ?? '');
        return trim((string) $text);
    }
}

/**
 * 别名函数
 */
if (!function_exists('todo_sanitize_text_field')) {
    function todo_sanitize_text_field($text) {
        return sanitize_text_field($text);
    }
}
if (!function_exists('todo_sanitize_email')) {
    function todo_sanitize_email($email) {
        return sanitize_email($email);
    }
}
if (!function_exists('todo_esc_url')) {
    function todo_esc_url($url) {
        return esc_url($url);
    }
}

/**
 * 清理邮箱地址
 *
 * @param string $email 需要验证的邮箱地址
 * @return bool 是否为有效的邮箱地址
 */
if (!function_exists('is_email')) {
    function is_email($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

/**
 * 检查是否为合法的公开URL（防御SSRF）
 *
 * @param string $url
 * @return bool
 */
if (!function_exists('todo_is_public_url')) {
    function todo_is_public_url($url) {
        $parts = parse_url($url);
        if (!$parts || !isset($parts['host'])) {
            return false;
        }

        $host = $parts['host'];

        // 排除本地主机名
        $localhosts = ['localhost', '127.0.0.1', '0.0.0.0', '::1', '::'];
        if (in_array(strtolower($host), $localhosts)) {
            return false;
        }

        // 如果是IP地址，检查是否为私有/保留地址
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false;
        }

        // 尝试解析域名以检查底层IP
        $ip = gethostbyname($host);
        if ($ip && $ip !== $host) {
            if (
                !filter_var(
                    $ip,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                )
            ) {
                return false;
            }
        }

        return true;
    }
}

/**
 * 检查邮箱是否已存在
 *
 * @param string $email 需要检查的邮箱地址
 * @return int|bool 如果存在返回用户ID，否则返回false
 */
if (!function_exists('email_exists')) {
    function email_exists($email) {
        // 统一使用预处理查询接口，避免直接调用 PDO::query 造成参数模式错误
        // 排除已注销账号 (user_status != -1)
        $row = tododb_get_row(
            "SELECT ID FROM " . TABLE_PREFIX . "users WHERE user_email = ? AND user_status != -1 LIMIT 1",
            array($email),
            's'
        );
        if (is_array($row) && isset($row['ID'])) {
            return (int)$row['ID'];
        }
        return false;
    }
}

/**
 * 安全比较两个字符串
 *
 * 注意：此函数仅在 PHP 内置的 hash_equals 函数不可用时才会被定义
 * PHP 5.6+ 已内置此函数
 *
 * @param string $a 第一个字符串
 * @param string $b 第二个字符串
 * @return bool 是否相等
 */
if (!function_exists('hash_equals')) {
    function hash_equals($a, $b) {
        if (strlen($a) !== strlen($b)) {
            return false;
        }
        
        $result = 0;
        for ($i = 0; $i < strlen($a); $i++) {
            $result |= ord($a[$i]) ^ ord($b[$i]);
        }
        
        return $result === 0;
    }
}

/**
 * 转义SQL查询中的字符串
 *
 * @param string $data 需要转义的数据
 * @return string 转义后的数据
 */
if (!function_exists('esc_sql')) {
    function esc_sql($data) {
        global $tododb, $cms_db;
        
        // 优先使用cms_db，如果不存在则使用db
        $database = isset($cms_db) ? $cms_db : $tododb;
        
        if (isset($database) && is_object($database) && method_exists($database, 'escape')) {
            return $database->escape($data);
        }
        
        return addslashes($data);
    }
}

/**
 * 定义时间常量
 */
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS);
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS);
}
if (!defined('WEEK_IN_SECONDS')) {
    define('WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS);
}
if (!defined('MONTH_IN_SECONDS')) {
    define('MONTH_IN_SECONDS', 30 * DAY_IN_SECONDS);
}
if (!defined('YEAR_IN_SECONDS')) {
    define('YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS);
}
