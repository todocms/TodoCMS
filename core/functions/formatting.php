<?php
/**
 * 格式化函数文件
 *
 * 包含文本和数据格式化的函数
 *
 * @package TodoCMS
 * @version 1.0.0
 */

if (!function_exists('todo_ensure_utf8')) {
    /**
     * 递归/单值确保字符串编码为合法的 UTF-8 格式
     * 防止中文字符集或非 UTF-8 文件名导致 json_encode 返回 false
     *
     * @param mixed $data 待转换的数据
     * @return mixed 规范为 UTF-8 后的数据
     */
    function todo_ensure_utf8($data)
    {
        if (is_string($data)) {
            if ($data === '') {
                return '';
            }
            // 1. 如果已经是合法的 UTF-8，直接原样返回
            if (mb_check_encoding($data, 'UTF-8')) {
                return $data;
            }
            // 2. 仅检测真正需要的中文/繁体编码，切勿包含 ISO-8859-1
            if (function_exists('mb_detect_encoding')) {
                $encoding = mb_detect_encoding($data, array('UTF-8', 'GBK', 'GB2312', 'BIG5', 'CP936'), true);
                if ($encoding && $encoding !== 'UTF-8') {
                    return mb_convert_encoding($data, 'UTF-8', $encoding);
                }
            }
            // 3. 剔除无效 UTF-8 字节
            if (function_exists('iconv')) {
                $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $data);
                if ($clean !== false && $clean !== '') {
                    return $clean;
                }
            }
            return mb_convert_encoding($data, 'UTF-8', 'UTF-8');
        }
        if (is_array($data)) {
            $clean_array = array();
            foreach ($data as $k => $v) {
                $clean_array[todo_ensure_utf8($k)] = todo_ensure_utf8($v);
            }
            return $clean_array;
        }
        if (is_object($data)) {
            foreach ($data as $k => $v) {
                $data->$k = todo_ensure_utf8($v);
            }
            return $data;
        }
        return $data;
    }
}

if (!function_exists('todo_sanitize_keywords')) {
    /**
     * 自动清洗关键词：中文逗号转英文逗号，所有空格转英文逗号，合并连续逗号
     *
     * @param string $keywords 原始关键词字符串
     * @return string 清洗后的关键词字符串
     */
    function todo_sanitize_keywords($keywords)
    {
        if (empty($keywords) || !is_string($keywords)) {
            return '';
        }
        // 1. 中文逗号转英文逗号
        $keywords = str_replace('，', ',', $keywords);
        // 2. 所有空白字符（包括全角/半角空格、制表符、换行）转英文逗号
        $keywords = preg_replace('/[\s\x{3000}]+/u', ',', $keywords);
        // 3. 连续逗号合并为一个
        $keywords = preg_replace('/,+/u', ',', $keywords);
        // 4. 清除首尾空格和逗号
        return trim($keywords, " \t\n\r\0\x0B,");
    }
}

/**
 * 将MySQL日期格式转换为指定格式
 *
 * @param string $format 目标日期格式
 * @param string $date MySQL日期字符串
 * @return string 格式化后的日期
 */
function mysql2date($format, $date)
{
    if (empty($date) || $date === '0000-00-00 00:00:00') {
        return '';
    }

    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return '';
    }

    return date($format, $timestamp);
}

/**
 * 格式化日期
 *
 * @param string $date 日期字符串或时间戳
 * @param string $format 日期格式，默认使用系统设置
 * @return string 格式化后的日期
 */
function format_date($date, $format = '')
{
    if (empty($format)) {
        // 在安装过程中使用默认格式，避免调用get_option
        if (defined('INSTALLING') && INSTALLING) {
            $format = 'Y-m-d';
        } else {
            $format = function_exists('get_option') ? get_option('date_format', 'Y-m-d') : 'Y-m-d';
        }
    }

    if (is_numeric($date)) {
        $timestamp = $date;
    } else {
        $timestamp = strtotime($date);
    }
    
    // 确保使用系统配置的时区
    if (function_exists('get_option')) {
        $timezone = get_option('timezone_string', '');
        if (!empty($timezone)) {
            $current_timezone = @date_default_timezone_get();
            if ($current_timezone !== $timezone) {
                @date_default_timezone_set($timezone);
            }
        }
    }

    return date($format, $timestamp);
}

/**
 * 格式化时间
 *
 * @param string $time 时间字符串或时间戳
 * @param string $format 时间格式，默认使用系统设置
 * @return string 格式化后的时间
 */
function format_time($time, $format = '')
{
    if (empty($format)) {
        // 安装阶段避免访问数据库选项
        if (defined('INSTALLING') && INSTALLING) {
            $format = 'H:i';
        } else {
            $format = function_exists('get_option') ? get_option('time_format', 'H:i') : 'H:i';
        }
    }

    if (is_numeric($time)) {
        $timestamp = $time;
    } else {
        $timestamp = strtotime($time);
    }

    return date($format, $timestamp);
}

/**
 * 格式化日期时间
 *
 * @param string $datetime 日期时间字符串或时间戳
 * @param string $date_format 日期格式，默认使用系统设置
 * @param string $time_format 时间格式，默认使用系统设置
 * @return string 格式化后的日期时间
 */
function format_datetime($datetime, $date_format = '', $time_format = '')
{
    if (empty($date_format)) {
        if (defined('INSTALLING') && INSTALLING) {
            $date_format = 'Y-m-d';
        } else {
            $date_format = function_exists('get_option') ? get_option('date_format', 'Y-m-d') : 'Y-m-d';
        }
    }

    if (empty($time_format)) {
        if (defined('INSTALLING') && INSTALLING) {
            $time_format = 'H:i';
        } else {
            $time_format = function_exists('get_option') ? get_option('time_format', 'H:i') : 'H:i';
        }
    }

    if (is_numeric($datetime)) {
        $timestamp = $datetime;
    } else {
        $timestamp = strtotime($datetime);
    }

    return date($date_format . ' ' . $time_format, $timestamp);
}

/**
 * 截断文本到指定长度
 *
 * @param string $text 要截断的文本
 * @param int $length 截断长度
 * @param string $more 更多文本的指示符
 * @return string 截断后的文本
 */
function truncate_text($text, $length = 55, $more = '...')
{
    // 统一转换为字符串，避免 strip_tags/trim 对数组或对象报错
    if (is_array($text)) {
        $text = implode(' ', array_map(function ($item) {
            if (is_array($item)) {
                return implode(' ', array_map(function ($sub) {
                    return (is_scalar($sub) || is_string($sub)) ? (string) $sub : '';
                }, $item));
            } elseif (is_object($item)) {
                return method_exists($item, '__toString') ? (string) $item : '';
            }
            return (string) ($item ?? '');
        }, $text));
    } elseif (is_object($text)) {
        $text = method_exists($text, '__toString') ? (string) $text : '';
    } else {
        $text = (string) ($text ?? '');
    }

    $text = strip_tags($text);
    $text = trim($text);

    if (mb_strlen($text, 'UTF-8') > $length) {
        $text = mb_substr($text, 0, $length, 'UTF-8');
        $text = trim($text);
        $text .= $more;
    }

    return $text;
}

/**
 * 移除 HTML 标签中的所有属性，仅保留标签名（纯净标签）
 *
 * @param string $html 原始 HTML
 * @return string 清理后的 HTML
 */
function todo_strip_all_attributes($html)
{
    if (empty($html)) {
        return '';
    }

    // 匹配 <tag attr="val"> 并替换为 <tag>，同时保留自闭合斜杠
    // 允许的标签名包含字母和数字（如 h1-h6）
    return preg_replace('/<([a-z1-6]+)\b[^>]*?(\/?)>/i', '<$1$2>', $html);
}

/**
 * TodoCMS兼容的HTML内容清理函数
 *
 * @param string $data 要清理的HTML内容
 * @return string 清理后的HTML内容
 */
function todo_kses_post($data)
{
    if (empty($data)) {
        return '';
    }

    // 统一转换为字符串，避免 strip_tags 对数组/对象报错
    if (is_array($data)) {
        $data = implode("\n", array_map(function ($item) {
            if (is_array($item)) {
                return implode("\n", array_map(function ($sub) {
                    return (is_scalar($sub) || is_string($sub)) ? (string) $sub : ''; }, $item));
            } elseif (is_object($item)) {
                return method_exists($item, '__toString') ? (string) $item : '';
            }
            return (string) ($item ?? '');
        }, $data));
    } elseif (is_object($data)) {
        $data = method_exists($data, '__toString') ? (string) $data : '';
    } else {
        $data = (string) ($data ?? '');
    }

    // 允许的HTML标签
    $allowed_tags = '<p><br><strong><em><b><i><ul><ol><li><a><img><h1><h2><h3><h4><h5><h6><blockquote><div><span><table><tr><td><th><tbody><thead><tfoot>';

    return strip_tags($data, $allowed_tags);
}

if (!function_exists('todo_decode_html_entities')) {
    function todo_decode_html_entities($text, $max_passes = 2)
    {
        $text = (string) ($text ?? '');
        $max_passes = (int) $max_passes;
        if ($max_passes < 1) {
            $max_passes = 1;
        }
        if ($max_passes > 5) {
            $max_passes = 5;
        }

        for ($i = 0; $i < $max_passes; $i++) {
            $decoded = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
            if ($decoded === $text) {
                break;
            }
            $text = $decoded;
        }

        return $text;
    }
}

if (!function_exists('todo_html_to_plain_text')) {
    function todo_html_to_plain_text($html)
    {
        $html = todo_decode_html_entities($html, 2);
        $html = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $html);
        $html = preg_replace('/<\/p>\s*/i', "\n", $html);
        $text = strip_tags($html);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }
}

/**
 * 生成摘要
 *
 * @param string $content 文章内容
 * @param int $length 摘要长度
 * @param string $more 更多文本的指示符
 * @return string 生成的摘要
 */
function generate_excerpt($content, $length = 55, $more = '...')
{
    // 统一转换为字符串，避免对数组/对象执行字符串函数时报错
    if (is_array($content)) {
        $content = implode(' ', array_map(function ($item) {
            if (is_array($item)) {
                return implode(' ', array_map(function ($sub) {
                    return (is_scalar($sub) || is_string($sub)) ? (string) $sub : ''; }, $item));
            } elseif (is_object($item)) {
                return method_exists($item, '__toString') ? (string) $item : '';
            }
            return (string) ($item ?? '');
        }, $content));
    } elseif (is_object($content)) {
        $content = method_exists($content, '__toString') ? (string) $content : '';
    } else {
        $content = (string) ($content ?? '');
    }

    // 移除短代码
    $content = preg_replace('/\[.*?\]/', '', $content);

    // 移除HTML标签
    $content = strip_tags($content);

    // 移除多余空白
    $content = preg_replace('/\s+/', ' ', $content);
    $content = trim($content);

    // 截断文本
    if (mb_strlen($content, 'UTF-8') > $length) {
        $content = mb_substr($content, 0, $length, 'UTF-8');
        $content = trim($content);
        $content .= $more;
    }

    return $content;
}

/**
 * HTML实体转义（TodoCMS风格）
 *
 * @param string $text 要转义的文本
 * @return string 转义后的文本
 */
function todo_esc_html($text)
{
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

if (!function_exists('todo_esc_attr')) {
    function todo_esc_attr($text)
    {
        return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * 格式化数字
 *
 * @param float $number 要格式化的数字
 * @param int $decimals 小数位数
 * @return string 格式化后的数字
 */
function format_number($number, $decimals = 0)
{
    return number_format($number, $decimals, '.', ',');
}

/**
 * 格式化文件大小
 *
 * @param int $bytes 字节数
 * @param int $decimals 小数位数
 * @return string 格式化后的文件大小
 */
function format_filesize($bytes, $decimals = 2)
{
    $size = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
    $factor = floor((strlen($bytes) - 1) / 3);

    return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . ' ' . @$size[$factor];
}

/**
 * 将换行符转换为HTML段落和换行标签
 *
 * @param string $text 要转换的文本
 * @return string 转换后的HTML
 */
function nl2p($text)
{
    // 移除空白行
    $text = preg_replace('/\n\s*\n/m', "\n\n", $text ?? '');

    // 将单个换行符转换为<br>
    $text = preg_replace('/([^\n])\n([^\n])/m', "$1<br>\n$2", $text ?? '');

    // 将双换行符转换为段落
    $paragraphs = explode("\n\n", $text);
    $text = '';

    foreach ($paragraphs as $paragraph) {
        if (trim($paragraph)) {
            $text .= '<p>' . trim($paragraph) . '</p>';
        }
    }

    return $text;
}

/**
 * 自动为URL添加链接
 *
 * @param string $text 要处理的文本
 * @return string 处理后的文本
 */
function make_clickable($text)
{
    $pattern = '/(https?:\/\/[^\s<]+)/i';
    $replacement = '<a href="$1" target="_blank">$1</a>';

    return preg_replace($pattern, $replacement, $text);
}

/**
 * 将文本转换为URL友好的别名
 *
 * @param string $text 要转换的文本
 * @return string URL友好的别名
 */
function sanitize_title($text)
{
    // 中文字符：移除常见虚词（结构助词、连词、介词、代词、量词、语气词、副词、判断词、方位词等）
    $chinese_stopwords = '/[的与之于和及以而且或但所为其在着了过被把从到对向往由因故若虽然则也就还又再更很都只才刚已将教会能可该各每某这那哪之么吗呢吧啊哦嗯与同跟给让叫使请令受为因用凭依据照按以自打由朝向到至在当于关于对于除了根据按照通过经过凭借由于因为所以因此如果假如即使即便尽管虽然然而但是可是不过并且而且或者要么与其不如虽然既然除非无论不管只要只有不仅而且乃至尤其特别甚至更加越发稍微略微何等何其多么极度极其非凡不胜无比格外分外尤其实在的确确实居然竟然毕竟终究总算终于终究终归必定必然一定必须必定务必必须得需要应当应该能够可以可能或许许或许也还就又再才便即却倒反偏偏偏就只仅光单单一味一味只管只顾尽管尽尽管尽仅尽尽尽仅只单仅仅]/u';
    $text = preg_replace($chinese_stopwords, '', $text ?? '');

    // 转换为小写
    $text = mb_strtolower($text, 'UTF-8');

    // 移除重音符号
    if (function_exists('transliterator_transliterate')) {
        $text = transliterator_transliterate('Any-Latin; Latin-ASCII; [\^`~¨\'ˆ] remove; [\p{Mn}] remove; [\p{Cc}] remove; [\p{Cf}] remove;', $text);
    }

    // 移除特殊字符
    $text = preg_replace('/[^a-z0-9\-\s]/', '', $text ?? '');

    // 将空格转换为连字符
    $text = preg_replace('/[\s]+/', '-', $text ?? '');

    // 移除多余的连字符
    $text = preg_replace('/\-{2,}/', '-', $text ?? '');

    // 修剪连字符
    $text = trim($text, '-');

    // 限制slug长度：控制在25个字符以内，且在连字符处截断
    if (mb_strlen($text) > 25) {
        $text = mb_substr($text, 0, 25);
        // 在最后一个连字符处截断，避免切断拼音音节
        $last_hyphen = mb_strrpos($text, '-');
        if ($last_hyphen !== false) {
            $text = mb_substr($text, 0, $last_hyphen);
        }
    }

    return $text;
}

/**
 * 清理键名
 *
 * 将键名转换为小写，并移除所有非字母数字字符
 *
 * @param string $key 需要清理的键名
 * @return string 清理后的键名
 */
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        $key = strtolower($key);
        // 移除所有非字母数字字符，只保留字母、数字、下划线和连字符
        $key = preg_replace('/[^a-z0-9_-]/', '', $key);
        return $key;
    }
}

/**
 * 生成标识符（拼音 + 可选ID）
 * 
 * @param string $name 名称
 * @param int $id 可选ID
 * @param string $default 默认值
 * @return string 生成的标识符
 */
function todo_generate_slug($name, $id = 0, $default = 'slug') {
    $slug_base = sanitize_title($name);
    if ($slug_base === '') {
        $slug_base = $default;
    }
    
    $slug = $slug_base;
    if ($id > 0) {
        $slug = $slug_base . '-' . $id;
    }
    
    return $slug;
}

/**
 * 生成随机字符串
 *
 * @param int $length 字符串长度
 * @param string $chars 可用字符集
 * @return string 随机字符串
 */
function generate_random_string($length = 10, $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789')
{
    $string = '';
    $max = strlen($chars) - 1;

    for ($i = 0; $i < $length; $i++) {
        $string .= $chars[mt_rand(0, $max)];
    }

    return $string;
}

/**
 * 检查数据是否已序列化
 *
 * @param mixed $data 要检查的数据
 * @return bool 是否已序列化
 */
if (!function_exists('is_serialized')) {
    function is_serialized($data)
    {
        // 如果不是字符串，肯定不是序列化数据
        if (!is_string($data)) {
            return false;
        }

        $data = trim($data);

        // 序列化数据至少有2个字符
        if (strlen($data) < 2) {
            return false;
        }

        // 检查数据大小，防止处理过大的数据导致内存溢出
        // 设置10MB的限制
        $size_limit = 10 * 1024 * 1024; // 10MB
        if (strlen($data) > $size_limit) {
            error_log('警告：尝试检查过大的序列化数据 (' . strlen($data) . ' 字节)，已跳过');
            return false;
        }

        // 检查序列化格式
        if ($data[0] != 'a' && $data[0] != 'O' && $data[0] != 's' && $data[0] != 'i' && $data[0] != 'd' && $data[0] != 'b' && $data[0] != 'N') {
            return false;
        }

        // 检查最后一个字符：数组和对象以}结尾，其他类型以;结尾
        $last_char = substr($data, -1);
        if ($data[0] === 'a' || $data[0] === 'O') {
            // 数组和对象以}结尾
            if ($last_char !== '}') {
                return false;
            }
        } else {
            // 其他类型以;结尾
            if ($last_char !== ';') {
                return false;
            }
        }

        // 使用正则表达式进行基本验证，避免不必要的反序列化尝试
        // 修复正则表达式以正确匹配序列化数组
        $pattern = '/^(s|i|d|b|a|O|N):/s';
        if (!preg_match($pattern, $data)) {
            return false;
        }

        // 对于数组类型，检查基本格式
        if ($data[0] === 'a') {
            if (!preg_match('/^a:\d+:\{.*\}$/s', $data)) {
                return false;
            }
        }

        // 对于小数据，尝试反序列化
        if (strlen($data) < 1024 * 1024) { // 1MB以下的数据才尝试反序列化
            // 保存当前内存限制
            $memory_limit = ini_get('memory_limit');

            // 临时增加内存限制
            ini_set('memory_limit', '256M');

            // 尝试反序列化
            $unserialized = @unserialize($data);

            // 恢复原来的内存限制
            ini_set('memory_limit', $memory_limit);

            // 如果反序列化失败且不是布尔值false，则不是序列化数据
            if ($unserialized === false && $data !== 's:0:"";' && $data !== 'b:0;') {
                return false;
            }

            // 反序列化成功，确认是序列化数据
            return true;
        }

        return true;
    }
}

/**
 * 如果需要，对数据进行序列化
 *
 * @param mixed $data 要序列化的数据
 * @return mixed 序列化后的数据或原始数据
 */
if (!function_exists('maybe_serialize')) {
    function maybe_serialize($data)
    {
        if (is_array($data) || is_object($data)) {
            return serialize($data);
        }

        return $data;
    }
}

/**
 * 如果数据已序列化，则反序列化
 *
 * @param string $data 可能已序列化的数据
 * @return mixed 反序列化后的数据或原始数据
 */
if (!function_exists('maybe_unserialize')) {
    function maybe_unserialize($data)
    {
        // 如果不是字符串，直接返回
        if (!is_string($data)) {
            return $data;
        }

        // 检查数据大小，防止反序列化过大的数据导致内存溢出
        // 设置10MB的限制
        $size_limit = 10 * 1024 * 1024; // 10MB
        if (strlen($data) > $size_limit) {
            error_log('警告：尝试反序列化过大的数据 (' . strlen($data) . ' 字节)，已跳过');
            return $data;
        }

        // 检查是否已序列化
        if (is_serialized($data)) {
            // 保存当前内存限制
            $memory_limit = ini_get('memory_limit');

            // 临时增加内存限制
            ini_set('memory_limit', '256M');

            // 尝试反序列化
            $unserialized = @unserialize($data);

            // 恢复原来的内存限制
            ini_set('memory_limit', $memory_limit);

            // 如果反序列化成功，返回反序列化后的数据
            if ($unserialized !== false || $data === 's:0:"";' || $data === 'b:0;') {
                return $unserialized;
            }
        }

        // 返回原始数据
        return $data;
    }
}

/**
 * 深度移除字符串中的反斜杠
 * 提供数组递归处理，非字符串类型原样返回
 */
if (!function_exists('stripslashes_deep')) {
    function stripslashes_deep($value)
    {
        if (is_array($value)) {
            return array_map('stripslashes_deep', $value);
        }
        return is_string($value) ? stripslashes($value) : $value;
    }
}

/**
 * TodoCMS风格：统一的反斜杠移除入口
 */
if (!function_exists('todo_unslash')) {
    function todo_unslash($value)
    {
        return stripslashes_deep($value);
    }
}

/**
 * 检查复选框是否应该被选中
 *
 * @param mixed $checked 要检查的值
 * @param mixed $current 当前值（可选）
 * @param bool $echo 是否输出结果，默认为true
 * @return string 如果$echo为false，返回结果字符串
 */
if (!function_exists('checked')) {
    function checked($checked, $current = true, $echo = true)
    {
        $result = $checked == $current ? ' checked="checked"' : '';

        if ($echo) {
            echo $result;
        } else {
            return $result;
        }
    }
}

/**
 * 检查选择框选项是否应该被选中
 *
 * @param mixed $selected 要检查的值
 * @param mixed $current 当前值
 * @param bool $echo 是否输出结果，默认为true
 * @return string 如果$echo为false，返回结果字符串
 */
if (!function_exists('selected')) {
    function selected($selected, $current = true, $echo = true)
    {
        $result = $selected == $current ? ' selected="selected"' : '';

        if ($echo) {
            echo $result;
        } else {
            return $result;
        }
    }
}

/**
 * 转义JavaScript字符串
 *
 * @param string $text 要转义的文本
 * @return string 转义后的文本
 */
if (!function_exists('esc_js')) {
    function esc_js($text)
    {
        $safe_text = todo_check_invalid_utf8($text);
        $safe_text = todo_specialchars($safe_text, ENT_COMPAT);
        $safe_text = preg_replace('/&#(x)?0*(?(1)27|39);?/i', "'", stripslashes($safe_text));
        $safe_text = str_replace("\r", '', $safe_text);
        $safe_text = str_replace("\n", '\\n', addslashes($safe_text));
        return $safe_text;
    }
}

/**
 * 检查无效的UTF-8字符
 *
 * @param string $string 要检查的字符串
 * @param bool $strip 是否移除无效字符
 * @return string 处理后的字符串
 */
function todo_check_invalid_utf8($string, $strip = false)
{
    $string = (string) $string;

    if (0 === strlen($string)) {
        return '';
    }

    // Store the site charset as a static to avoid multiple calls to get_option()
    static $is_utf8 = null;
    if (!isset($is_utf8)) {
        $charset = function_exists('todo_get_option') ? todo_get_option('blog_charset', 'UTF-8') : (function_exists('get_option') ? get_option('blog_charset') : 'UTF-8');
        $is_utf8 = in_array($charset, array('utf8', 'utf-8', 'UTF8', 'UTF-8'));
    }
    if (!$is_utf8) {
        return $string;
    }

    // Check for support for utf8 in the installed PCRE library once and store the result in a static
    static $utf8_pcre = null;
    if (!isset($utf8_pcre)) {
        $utf8_pcre = @preg_match('/^./u', 'a');
    }
    // We can't demand utf8 in the PCRE installation, so just return the string in those cases
    if (!$utf8_pcre) {
        return $string;
    }

    // preg_match fails when it encounters invalid UTF8 in $string
    if (1 === @preg_match('/^./us', $string)) {
        return $string;
    }

    // Attempt to strip the bad chars if requested (not recommended)
    if ($strip && function_exists('iconv')) {
        return iconv('utf-8', 'utf-8//IGNORE', $string);
    }

    return '';
}

/**
 * 转换特殊字符为HTML实体
 *
 * @param string $string 要转换的字符串
 * @param int $quote_style 引号样式
 * @return string 转换后的字符串
 */
function todo_specialchars($string, $quote_style = ENT_NOQUOTES, $charset = false, $double_encode = false)
{
    $string = (string) $string;

    if (0 === strlen($string)) {
        return '';
    }

    // Don't bother if there are no specialchars - saves some processing
    if (!preg_match('/[&<>"\']/', $string)) {
        return $string;
    }

    // Account for the previous behaviour of the function when the $quote_style is not an accepted value
    if (empty($quote_style)) {
        $quote_style = ENT_NOQUOTES;
    } elseif (!in_array($quote_style, array(0, 2, 3, 'single', 'double'), true)) {
        $quote_style = ENT_QUOTES;
    }

    // Store the site charset as a static to avoid multiple calls to get_option()
    if (!$charset) {
        static $charset_cache = null;
        if (!isset($charset_cache)) {
            $charset_cache = function_exists('todo_get_option') ? todo_get_option('blog_charset', 'UTF-8') : (function_exists('get_option') ? get_option('blog_charset') : 'UTF-8');
        }
        $charset = $charset_cache;
    }

    if (in_array($charset, array('utf8', 'utf-8', 'UTF8'))) {
        $charset = 'UTF-8';
    }

    $_quote_style = $quote_style;

    if ($quote_style === 'single') {
        $quote_style = ENT_NOQUOTES;
    } elseif ($quote_style === 'double') {
        $quote_style = ENT_COMPAT;
        $_quote_style = ENT_COMPAT;
    }

    $string = @htmlspecialchars($string, $quote_style, $charset, $double_encode);

    // Back-compat.
    if ('single' === $_quote_style) {
        $string = str_replace("'", '&#039;', $string);
    }

    return $string;
}
