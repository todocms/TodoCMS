<?php
/**
 * TodoCMS 辅助工具函数扩展
 * 
 * 提供各种辅助功能，包括文本处理、安全验证、缓存管理等
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 获取内容URL
 * @param string $path 相对路径
 * @return string 完整URL
 */
function todo_get_content_url($path = '') {
    $content_url = rtrim(get_site_url(), '/') . '/content';
    if ($path) {
        $content_url .= '/' . ltrim($path, '/');
    }
    return $content_url;
}

/**
 * 停用主题
 * @param string $theme_name 主题名称
 * @return bool 成功返回true
 */
function todo_theme_deactivate($theme_name) {
    global $tododb;
    
    if (!$theme_name) {
        return false;
    }
    
    try {
        // 获取当前激活的主题
        $active_theme = get_theme_name();
        
        if ($active_theme === $theme_name) {
            // 切换到默认主题
            activate_theme('default');
        }
        
        // 更新主题状态
        return $tododb->query("
            UPDATE " . TODO_TABLE_PREFIX . "themes 
            SET status = 'inactive', updated_at = NOW()
            WHERE directory = ?
        ", array($theme_name));
    } catch (Exception $e) {
        error_log("停用主题失败: " . $e->getMessage());
        return false;
    }
}

/**
 * 卸载主题
 * @param string $theme_name 主题名称
 * @param bool $delete_files 是否删除文件
 * @return bool 成功返回true
 */
function todo_theme_uninstall($theme_name, $delete_files = false) {
    global $tododb;
    
    if (!$theme_name || $theme_name === 'default') {
        return false; // 不能卸载默认主题
    }
    
    try {
        $tododb->beginTransaction();
        
        // 先停用主题
        todo_theme_deactivate($theme_name);
        
        // 删除数据库记录
        $tododb->query("
            DELETE FROM " . TODO_TABLE_PREFIX . "themes 
            WHERE name = ?
        ", array($theme_name));
        
        // 删除主题文件
        if ($delete_files) {
            $theme_path = CONTENT_DIR . '/themes/' . $theme_name;
            if (is_dir($theme_path)) {
                todo_delete_directory($theme_path);
            }
        }
        
        $tododb->commit();
        return true;
    } catch (Exception $e) {
        $tododb->rollBack();
        error_log("卸载主题失败: " . $e->getMessage());
        return false;
    }
}

/**
 * 清理文本内容
 * @param string $text 原始文本
 * @param array $options 清理选项
 * @return string 清理后的文本
 */
function todo_sanitize_text($text, $options = []) {
    if (empty($text)) {
        return '';
    }
    
    $defaults = [
        'strip_tags' => true,
        'trim' => true,
        'decode_entities' => true,
        'remove_breaks' => false,
        'max_length' => 0
    ];
    $options = array_merge($defaults, $options);
    
    // 解码HTML实体
    if ($options['decode_entities']) {
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    }
    
    // 移除HTML标签
    if ($options['strip_tags']) {
        $text = strip_tags($text);
    }
    
    // 移除换行符
    if ($options['remove_breaks']) {
        $text = str_replace(["\r", "\n"], ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
    }
    
    // 修剪空白字符
    if ($options['trim']) {
        $text = trim($text);
    }
    
    // 限制长度
    if ($options['max_length'] > 0 && mb_strlen($text) > $options['max_length']) {
        $text = mb_substr($text, 0, $options['max_length']) . '...';
    }
    
    return $text;
}

/**
 * 转义textarea内容
 * @param string $text 原始文本
 * @return string 转义后的文本
 */
function todo_esc_textarea($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

/**
 * 修剪摘要内容
 * @param string $text 原始文本
 * @param int $length 摘要长度
 * @param string $more 省略符号
 * @return string 摘要文本
 */
function todo_trim_excerpt($text, $length = 150, $more = '...') {
    if (empty($text)) {
        return '';
    }
    
    // 移除HTML标签
    $text = strip_tags($text);
    
    // 移除多余的空白字符
    $text = preg_replace('/\s+/', ' ', trim($text));
    
    // 如果文本长度小于等于指定长度，直接返回
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    
    // 截取指定长度的文本
    $excerpt = mb_substr($text, 0, $length);
    
    // 尝试在最后一个空格处截断，避免截断单词
    $last_space = mb_strrpos($excerpt, ' ');
    if ($last_space !== false && $last_space > $length * 0.8) {
        $excerpt = mb_substr($excerpt, 0, $last_space);
    }
    
    return $excerpt . $more;
}

/**
 * 检查是否正在安装
 * @return bool 正在安装返回true
 */
function todo_installing() {
    return defined('TODO_INSTALLING') && TODO_INSTALLING;
}

/**
 * 清空所有输出缓冲区
 */
function todo_ob_end_flush_all() {
    while (ob_get_level()) {
        ob_end_flush();
    }
}

/**
 * 清空所有输出缓冲区但不输出
 */
function todo_ob_end_clean_all() {
    while (ob_get_level()) {
        ob_end_clean();
    }
}

/**
 * 安全重定向
 * @param string $url 重定向URL
 * @param int $status 状态码
 */
function todo_safe_redirect($url, $status = 302) {
    // 验证URL安全性
    if (!todo_is_safe_redirect_url($url)) {
        $url = get_site_url();
    }
    
    // 清空输出缓冲区（不输出）
    todo_ob_end_clean_all();
    
    // 发送重定向头
    header('Location: ' . $url, true, $status);
    exit;
}

/**
 * 检查重定向URL是否安全
 * @param string $url 要检查的URL
 * @return bool 安全返回true
 */
function todo_is_safe_redirect_url($url) {
    if (empty($url)) {
        return false;
    }
    
    // 允许相对URL
    if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
        return true;
    }
    
    // 检查是否为当前域名
    $site_url = get_site_url();
    $parsed_site = parse_url($site_url);
    $parsed_url = parse_url($url);
    
    if (!$parsed_url || !isset($parsed_url['host'])) {
        return false;
    }
    
    return $parsed_url['host'] === $parsed_site['host'];
}

/**
 * 获取额外的图像尺寸
 * @return array 图像尺寸数组
 */
function todo_get_additional_image_sizes() {
    global $todo_image_sizes;
    
    if (!isset($todo_image_sizes)) {
        $todo_image_sizes = [];
    }
    
    return $todo_image_sizes;
}

/**
 * 检查附件类型
 * @param int $attachment_id 附件ID
 * @param string $type 类型 (image|video|audio)
 * @return bool 匹配返回true
 */
function todo_attachment_is($attachment_id, $type) {
    $media_info = todo_get_media_info($attachment_id);
    
    if (!$media_info) {
        return false;
    }
    
    $extension = strtolower($media_info['file_extension']);
    
    switch ($type) {
        case 'image':
            return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp']);
        case 'video':
            return in_array($extension, ['mp4', 'avi', 'mov', 'wmv', 'flv', 'webm']);
        case 'audio':
            return in_array($extension, ['mp3', 'wav', 'ogg', 'aac', 'flac']);
        default:
            return false;
    }
}

/**
 * 读取视频元数据
 * @param string $file_path 视频文件路径
 * @return array|false 元数据数组或false
 */
function todo_read_video_metadata($file_path) {
    if (!file_exists($file_path)) {
        return false;
    }
    
    $metadata = [];
    
    // 基本文件信息
    $metadata['file_size'] = filesize($file_path);
    $metadata['file_type'] = pathinfo($file_path, PATHINFO_EXTENSION);
    
    // 如果有FFmpeg，可以获取更详细的信息
    if (function_exists('exec')) {
        $output = [];
        exec("ffprobe -v quiet -print_format json -show_format -show_streams " . escapeshellarg($file_path), $output);
        $json_output = implode('', $output);
        $video_info = json_decode($json_output, true);
        
        if ($video_info && isset($video_info['format'])) {
            $metadata['duration'] = isset($video_info['format']['duration']) ? floatval($video_info['format']['duration']) : 0;
            $metadata['bitrate'] = isset($video_info['format']['bit_rate']) ? intval($video_info['format']['bit_rate']) : 0;
        }
    }
    
    return $metadata;
}

/**
 * 读取音频元数据
 * @param string $file_path 音频文件路径
 * @return array|false 元数据数组或false
 */
function todo_read_audio_metadata($file_path) {
    if (!file_exists($file_path)) {
        return false;
    }
    
    $metadata = [];
    
    // 基本文件信息
    $metadata['file_size'] = filesize($file_path);
    $metadata['file_type'] = pathinfo($file_path, PATHINFO_EXTENSION);
    
    // 如果有getID3库，可以获取更详细的信息
    if (class_exists('getID3')) {
        $getID3 = new getID3();
        $file_info = $getID3->analyze($file_path);
        
        if (isset($file_info['audio'])) {
            $metadata['duration'] = isset($file_info['playtime_seconds']) ? floatval($file_info['playtime_seconds']) : 0;
            $metadata['bitrate'] = isset($file_info['audio']['bitrate']) ? intval($file_info['audio']['bitrate']) : 0;
            $metadata['sample_rate'] = isset($file_info['audio']['sample_rate']) ? intval($file_info['audio']['sample_rate']) : 0;
        }
    }
    
    return $metadata;
}

/**
 * 安全的basename函数
 * @param string $path 文件路径
 * @param string $suffix 要移除的后缀
 * @return string 文件名
 */
function todo_basename($path, $suffix = '') {
    return basename(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path), $suffix);
}

/**
 * 设置多字节字符串安全编码
 */
function mbstring_binary_safe_encoding() {
    if (function_exists('mb_internal_encoding') && function_exists('mb_regex_encoding')) {
        mb_internal_encoding('ISO-8859-1');
        mb_regex_encoding('ISO-8859-1');
    }
}

/**
 * 重置多字节字符串编码
 */
function reset_mbstring_encoding() {
    if (function_exists('mb_internal_encoding') && function_exists('mb_regex_encoding')) {
        mb_internal_encoding('UTF-8');
        mb_regex_encoding('UTF-8');
    }
}

/**
 * 检查字符串是否为UTF-8编码
 * @param string $str 要检查的字符串
 * @return bool UTF-8编码返回true
 */
function seems_utf8($str) {
    mbstring_binary_safe_encoding();
    $length = strlen($str);
    reset_mbstring_encoding();
    
    for ($i = 0; $i < $length; $i++) {
        $c = ord($str[$i]);
        if ($c < 0x80) {
            $n = 0; // 0bbbbbbb
        } elseif (($c >> 5) === 0x06) {
            $n = 1; // 110bbbbb
        } elseif (($c >> 4) === 0x0e) {
            $n = 2; // 1110bbbb
        } elseif (($c >> 3) === 0x1e) {
            $n = 3; // 11110bbb
        } elseif (($c >> 2) === 0x3e) {
            $n = 4; // 111110bb
        } elseif (($c >> 1) === 0x7e) {
            $n = 5; // 1111110b
        } else {
            return false; // 0xxxxxxx
        }
        
        for ($j = 0; $j < $n; $j++) { // n bytes matching 10bbbbbb follow ?
            if ((++$i == $length) || ((ord($str[$i]) >> 6) != 0x02)) {
                return false;
            }
        }
    }
    
    return true;
}

/**
 * 深度清理POST数据
 * @param mixed $data 要清理的数据
 * @return mixed 清理后的数据
 */
function todo_kses_post_deep($data) {
    if (is_array($data)) {
        return array_map('todo_kses_post_deep', $data);
    } else {
        return todo_sanitize_text($data);
    }
}

/**
 * 路径连接函数
 * @param string $base 基础路径
 * @param string $path 要连接的路径
 * @return string 连接后的路径
 */
function path_join($base, $path) {
    return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
}