<?php
/**
 * 媒体处理函数
 *
 * 处理媒体文件的上传、处理和管理
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 确保 current_time 函数可用
if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0)
    {
        if ($gmt) {
            switch ($type) {
                case 'mysql':
                    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
                case 'timestamp':
                    return time();
                default:
                    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format($type);
            }
        } else {
            switch ($type) {
                case 'mysql':
                    return date('Y-m-d H:i:s');
                case 'timestamp':
                    return time();
                default:
                    return date($type);
            }
        }
    }
}

// 确保 get_post_mime_type 函数可用
if (!function_exists('get_post_mime_type')) {
    function get_post_mime_type($post = null)
    {
        $post = get_post($post);
        if (!$post) {
            return false;
        }
        return $post->post_mime_type;
    }
}

/**
 * 清理文章缓存
 */
if (!function_exists('clean_post_cache')) {
    /**
     * 清理文章缓存
     *
     * @param int $post_id 文章ID
     * @return bool 是否成功
     */
    function clean_post_cache($post_id)
    {
        return Todo_Unified_Cache_Manager::clean_post_cache($post_id);
    }
}

/**
 * 检查附件是否为图片
 *
 * @param int|object $attachment 附件ID或附件对象
 * @return bool 是否为图片
 */
function todo_attachment_is_image($attachment)
{
    if (is_numeric($attachment)) {
        $attachment = get_post($attachment);
    }

    if (!$attachment || $attachment->post_type !== 'attachment') {
        return false;
    }

    return strpos($attachment->post_mime_type, 'image/') === 0;
}

/**
 * 获取附件链接
 *
 * @param int $attachment_id 附件ID
 * @return string 附件链接
 */
function get_attachment_link($attachment_id)
{
    $attachment = get_post($attachment_id);

    if (!$attachment || $attachment->post_type !== 'attachment') {
        return '';
    }

    // 返回附件的查看链接
    return todo_admin_url('media.php?attachment_id=' . $attachment_id . '&action=edit');
}

/**
 * 获取MIME类型图标
 *
 * @param string $mime_type MIME类型
 * @return string 图标URL
 */
function todo_mime_type_icon($mime_type)
{
    $icon_dir = rtrim(todo_admin_url('assets/images/media-icons'), '/') . '/';

    // 优先针对常见文档类型提供更细致的图标
    $doc_specific = array(
        'application/pdf' => 'pdf.png',
        'application/msword' => 'doc.png',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'doc.png',
        'application/vnd.ms-excel' => 'xls.png',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xls.png',
        'application/vnd.ms-powerpoint' => 'ppt.png',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'ppt.png',
    );
    if (isset($doc_specific[$mime_type])) {
        return $icon_dir . $doc_specific[$mime_type];
    }

    // 基于统一的类型归类来选择图标
    $category = todo_get_mime_category($mime_type);
    switch ($category) {
        case 'image':
            return $icon_dir . 'image.png';
        case 'video':
            return $icon_dir . 'video.png';
        case 'audio':
            return $icon_dir . 'audio.png';
        case 'archive':
            return $icon_dir . 'archive.png';
        case 'text':
            return $icon_dir . 'text.png';
        case 'code':
            return $icon_dir . 'code.png';
        case 'document':
            // 文档类型的通用图标（若未匹配到更细致的映射）
            return $icon_dir . 'doc.png';
        default:
            // 未识别或其他类型
            return $icon_dir . 'default.png';
    }
}

// === 统一的 MIME 类型清单（系统唯一来源） ===

if (!function_exists('todo_get_mime_category')) {
    /**
     * 根据 MIME 类型返回统一的类型分类：image|video|audio|document|archive|code|text|other
     */
    function todo_get_mime_category($mime)
    {
        $mime = strtolower(trim((string) $mime));
        if ($mime === '')
            return 'other';

        // 前缀快速判断
        if (strpos($mime, 'image/') === 0)
            return 'image';
        if (strpos($mime, 'video/') === 0)
            return 'video';
        if (strpos($mime, 'audio/') === 0)
            return 'audio';
        if (strpos($mime, 'text/') === 0) {
            // 特定文本类型可能更偏向代码
            if (in_array($mime, ['text/html', 'text/css', 'text/javascript']))
                return 'code';
            return 'text';
        }

        // 文档类型
        $doc_mimes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/rtf',
        ];
        if (in_array($mime, $doc_mimes, true))
            return 'document';

        // 压缩/归档
        $archive_mimes = [
            'application/zip',
            'application/x-rar-compressed',
            'application/x-7z-compressed',
            'application/x-tar',
            'application/gzip',
        ];
        if (in_array($mime, $archive_mimes, true))
            return 'archive';

        // 代码相关
        $code_mimes = [
            'application/javascript',
            'application/json',
            'application/xml',
        ];
        if (in_array($mime, $code_mimes, true))
            return 'code';

        // 兜底：根据扩展名映射再判断一次
        if (function_exists('todo_get_mime_by_extension')) {
            // 找到所有扩展对应当前 mime 的可能性（通过反向匹配模式）
            $flat = todo_supported_mime_types_flat();
            foreach ($flat as $ext => $flat_mime) {
                if ($flat_mime === $mime) {
                    // 根据扩展的常见前缀再分类
                    if (strpos($flat_mime, 'image/') === 0)
                        return 'image';
                    if (strpos($flat_mime, 'video/') === 0)
                        return 'video';
                    if (strpos($flat_mime, 'audio/') === 0)
                        return 'audio';
                }
            }
        }

        return 'other';
    }
}

if (!function_exists('todo_supported_mime_types_flat')) {
    /**
     * 返回统一的 ext => mime 类型映射（扁平）
     */
    function todo_supported_mime_types_flat()
    {
        static $map = null;
        if ($map !== null)
            return $map;
        $map = [
            // 图片
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'jpe' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'heic' => 'image/heic',

            // 视频
            'mp4' => 'video/mp4',
            'm4v' => 'video/mp4',
            'avi' => 'video/x-msvideo',
            'mov' => 'video/quicktime',
            'wmv' => 'video/x-ms-wmv',
            'flv' => 'video/x-flv',
            'webm' => 'video/webm',
            'mkv' => 'video/x-matroska',

            // 音频
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'm4b' => 'audio/mp4',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',

            // 文档
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt' => 'text/plain',
            'rtf' => 'application/rtf',
            'csv' => 'text/csv',
            'xml' => 'application/xml',
            'json' => 'application/json',
            'sql' => 'text/sql',
            'html' => 'text/html',
            'htm' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'php' => 'application/x-php',
            'phtml' => 'application/x-php',

            // 3D 模型
            'gltf' => 'model/gltf+json',
            'glb' => 'model/gltf-binary',
            'obj' => 'model/obj',
            'fbx' => 'model/fbx',

            // 压缩/归档
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed',
            '7z' => 'application/x-7z-compressed',
            'tar' => 'application/x-tar',
            'gz' => 'application/gzip',
        ];
        return $map;
    }
}

if (!function_exists('todo_get_default_allowed_extensions_csv')) {
    /**
     * 系统默认允许上传的扩展名清单（由 TodoUploadConfig 单一数据源维护）
     */
    function todo_get_default_allowed_extensions_csv()
    {
        return TodoUploadConfig::get_default_allowed_extensions_csv();
    }
}

if (!function_exists('todo_get_allowed_extensions')) {
    /**
     * 返回当前系统允许上传的扩展名列表（全部受后端管理，已剔除超时敏感类型）
     */
    function todo_get_allowed_extensions()
    {
        return TodoUploadConfig::get_allowed_extensions();
    }
}

if (!function_exists('todo_supported_mime_patterns')) {
    /**
     * 返回 upload_mimes 风格的 extPattern => mime 映射
     * 例如：'jpg|jpeg|jpe' => 'image/jpeg'
     */
    function todo_supported_mime_patterns()
    {
        $flat = todo_supported_mime_types_flat();
        $grouped = [];
        foreach ($flat as $ext => $mime) {
            if (!isset($grouped[$mime]))
                $grouped[$mime] = [];
            $grouped[$mime][] = $ext;
        }
        $patterns = [];
        foreach ($grouped as $mime => $exts) {
            $patterns[join('|', array_unique($exts))] = $mime;
        }
        return $patterns;
    }
}

if (!function_exists('todo_get_supported_extensions')) {
    /** 返回所有支持的扩展名列表（小写） */
    function todo_get_supported_extensions()
    {
        return array_keys(todo_supported_mime_types_flat());
    }
}

if (!function_exists('todo_get_mime_by_extension')) {
    /** 根据扩展名获取MIME类型，失败返回 null */
    function todo_get_mime_by_extension($ext)
    {
        $flat = todo_supported_mime_types_flat();
        $ext = strtolower(trim($ext));
        return $flat[$ext] ?? null;
    }
}

/**
 * 获取编辑文章链接
 *
 * @param int $post_id 文章ID
 * @param string $context 上下文
 * @return string 编辑链接
 */
function get_edit_post_link($post_id, $context = 'display')
{
    $post = get_post($post_id);

    if (!$post) {
        return '';
    }

    if ($post->post_type === 'attachment') {
        return todo_admin_url('media.php?attachment_id=' . $post_id . '&action=edit');
    }

    return todo_admin_url('post.php?post=' . $post_id . '&action=edit');
}

/**
 * UEditor风格的路径格式处理函数
 *
 * @param string $format 路径格式模板，如：/content/uploads/{yyyy}/{mm}/{dd}/{time}{rand:6}
 * @param string $filename 文件名（可选）
 * @return string 格式化后的路径
 */
function todo_format_upload_path($format, $filename = '')
{
    $t = time();
    $d = explode('-', date("Y-y-m-d-H-i-s"));
    $format = str_replace("{yyyy}", $d[0], $format);
    $format = str_replace("{yy}", $d[1], $format);
    $format = str_replace("{mm}", $d[2], $format);
    $format = str_replace("{dd}", $d[3], $format);
    $format = str_replace("{hh}", $d[4], $format);
    $format = str_replace("{ii}", $d[5], $format);
    $format = str_replace("{ss}", $d[6], $format);
    $format = str_replace("{time}", $t, $format);

    if (preg_match("/\{rand:(\d+)\}/", $format, $matches)) {
        $format = preg_replace("/\{rand:\d+\}/", generate_random_string($matches[1]), $format);
    }

    if ($filename) {
        $ext = strtolower(strrchr($filename, '.'));
        $format = str_replace("{filename}", preg_replace("/\.[^.]+$/", "", $filename), $format);
        $format .= $ext;
    }

    return $format;
}

/**
 * 获取UEditor风格的上传目录信息
 *
 * @param string $filename 文件名（可选）
 * @return array 包含上传目录路径和URL的数组
 */
function todo_upload_dir_ueditor($filename = '')
{
    // 使用UEditor风格的路径格式：/content/uploads/{yyyy}/{mm}/{dd}/{time}{rand:6}
    $path_format = "/content/uploads/{yyyy}/{mm}/{dd}/{time}{rand:6}";
    $relative_path = todo_format_upload_path($path_format, $filename);

    // 计算 uploads 基础目录并锚定
    $basedir = ABSPATH . 'content/uploads';
    if (!file_exists($basedir)) {
        mkdir($basedir, 0755, true);
    }

    // 站点子目录感知的基础URL（确保任意目录安装都正确）
    $baseurl = rtrim(todo_home_url('content/uploads'), '/');

    $subdir_rel = trim(preg_replace('#^/?content/uploads/?#', '', dirname($relative_path)), '/');
    
    $site_id = (defined('IS_ADMIN') && IS_ADMIN)
        ? (function_exists('todo_get_site_context_id') ? todo_get_site_context_id(0) : (isset($_GET['site']) && is_numeric($_GET['site']) && (int) $_GET['site'] > 0 ? (int) $_GET['site'] : 0))
        : (function_exists('todo_current_site_id') ? (int) todo_current_site_id() : 0);
    if ($site_id > 0) {
        $subdir_rel = 'sites' . $site_id . '/' . $subdir_rel;
    }

    $upload_dir = rtrim($basedir, '/') . ($subdir_rel !== '' ? '/' . $subdir_rel : '');
    $filename_final = basename($relative_path);

    // 确保目录存在
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    // realpath 前缀校验，防止越权写入
    $basedir_real = realpath($basedir);
    $upload_dir_real = realpath($upload_dir);
    if ($basedir_real === false || $upload_dir_real === false || strpos($upload_dir_real, $basedir_real) !== 0) {
        // 回退到基础目录
        $upload_dir = $basedir;
        $subdir_rel = '';
    }

    return [
        'path' => $upload_dir,
        'url' => $baseurl . ($subdir_rel !== '' ? '/' . $subdir_rel : ''),
        'basedir' => $basedir,
        'baseurl' => $baseurl,
        'subdir' => ($subdir_rel !== '' ? '/' . $subdir_rel : ''),
        'filename' => $filename_final,
        'full_path' => rtrim($upload_dir, '/') . '/' . $filename_final,
        'full_url' => $baseurl . ($subdir_rel !== '' ? '/' . $subdir_rel : '') . '/' . $filename_final
    ];
}

/**
 * 获取上传目录信息
 *
 * @return array 包含上传目录路径和URL的数组
 */
function todo_upload_dir($create_dir = true)
{
    $upload_path = ABSPATH . 'content/uploads';
    // 使用子目录感知的URL，确保在任意目录安装都正确
    $upload_url = rtrim(todo_home_url('content/uploads'), '/');

    // 创建基于年月日的子目录
    $time = current_time('mysql');
    $y = substr($time, 0, 4);
    $m = substr($time, 5, 2);
    $d = substr($time, 8, 2);

    $dir = "$y/$m/$d";

    $site_id = (defined('IS_ADMIN') && IS_ADMIN)
        ? (function_exists('todo_get_site_context_id') ? todo_get_site_context_id(0) : (isset($_GET['site']) && is_numeric($_GET['site']) && (int) $_GET['site'] > 0 ? (int) $_GET['site'] : 0))
        : (function_exists('todo_current_site_id') ? (int) todo_current_site_id() : 0);
    if ($site_id > 0) {
        $dir = "sites$site_id/$dir";
    }

    // 确保目录存在（仅在需要时创建）
    $path = $upload_path . '/' . $dir;
    if ($create_dir && !file_exists($path)) {
        mkdir($path, 0755, true);
    }

    // 应用钩子
    $upload_dir = apply_filters('upload_dir', [
        'path' => $path,
        'url' => $upload_url . '/' . $dir,
        'basedir' => $upload_path,
        'baseurl' => $upload_url,
        'subdir' => '/' . $dir,
    ]);

    return $upload_dir;
}

/**
 * 检查文件类型
 *
 * @param string $filename 文件名
 * @param array $mimes 允许的MIME类型（可选）
 * @return array 包含文件扩展名和MIME类型的数组
 */
function todo_check_filetype($filename, $mimes = null)
{
    // 默认允许的MIME类型使用统一清单
    if ($mimes === null) {
        $mimes = todo_supported_mime_patterns();
    }

    $mimes = apply_filters('upload_mimes', $mimes);

    // 确保 $mimes 是数组
    if (!is_array($mimes)) {
        $mimes = [];
    }

    $type = false;
    $ext = false;

    // 获取文件扩展名
    $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    // 查找匹配的MIME类型
    foreach ($mimes as $ext_pattern => $mime_type) {
        $ext_patterns = explode('|', $ext_pattern);

        foreach ($ext_patterns as $pattern) {
            if ($pattern === $file_ext) {
                $type = $mime_type;
                $ext = $file_ext;
                break 2;
            }
        }
    }

    return apply_filters('todo_check_filetype', [
        'ext' => $ext,
        'type' => $type
    ], $filename, $mimes);
}

/**
 * 生成唯一的文件名
 *
 * @param string $dir 目录路径
 * @param string $filename 原始文件名
 * @return string 唯一的文件名
 */
function todo_unique_filename($dir, $filename)
{
    // 清理文件名
    $filename = sanitize_file_name($filename);

    // 应用钩子
    $filename = apply_filters('todo_unique_filename', $filename, $dir);

    // 获取当前相对子目录（如 /2026/08/09）用于数据库比对
    $upload_dir = function_exists('todo_upload_dir') ? todo_upload_dir() : array('subdir' => '');
    $subdir = isset($upload_dir['subdir']) ? $upload_dir['subdir'] : '';

    $check_exists = function($fname) use ($dir, $subdir) {
        // 1. 检查物理磁盘文件是否存在
        $file_path = $dir . '/' . $fname;
        if (file_exists($file_path)) {
            return true;
        }

        // 2. 检查数据库 todo_media 表是否存在该 media_path 记录（防止云端存储清理本地副本后文件名重复）
        $rel_path = '/' . ltrim($subdir . '/' . $fname, '/');
        global $cms_db;
        $tododb = $cms_db;
        if (!$tododb && class_exists('CMS_DB')) {
            $tododb = new CMS_DB();
        }

        if ($tododb) {
            $table = defined('TABLE_PREFIX') ? TABLE_PREFIX . 'media' : (isset($tododb->prefix) ? $tododb->prefix . 'media' : 'todo_media');
            try {
                $sql = "SELECT COUNT(*) FROM {$table} WHERE media_path = ? OR media_path = ?";
                $count = $tododb->get_var($sql, [$rel_path, ltrim($rel_path, '/')]);
                if (intval($count) > 0) {
                    return true;
                }
            } catch (Throwable $e) {
            }
        }

        return false;
    };

    // 检查文件/数据库记录是否已存在
    if (!$check_exists($filename)) {
        return $filename;
    }

    // 如果文件或数据库记录已存在，自增后缀生成唯一名称
    $info = pathinfo($filename);
    $ext = !empty($info['extension']) ? '.' . $info['extension'] : '';
    $name = basename($filename, $ext);

    $number = 1;
    while ($check_exists($name . '-' . $number . $ext)) {
        $number++;
    }

    return $name . '-' . $number . $ext;
}

/**
 * 清理文件名
 *
 * @param string $filename 原始文件名
 * @return string 清理后的文件名
 */
function sanitize_file_name($filename)
{
    // 分离文件名和扩展名
    $pathinfo = pathinfo($filename);
    $extension = isset($pathinfo['extension']) ? '.' . $pathinfo['extension'] : '';
    $basename = isset($pathinfo['filename']) ? $pathinfo['filename'] : $filename;

    // 保存原始basename用于后续处理
    $original_basename = $basename;

    // 处理重复后缀问题（只处理文件名部分，不包含扩展名）
    $basename = remove_duplicate_extensions($basename);

    // 移除特殊字符
    $special_chars = array('?', '[', ']', '/', '\\', '=', '<', '>', ':', ';', ',', "'", '"', '&', '$', '#', '*', '(', ')', '|', '~', '`', '!', '{', '}', '%', '+', chr(0));
    $basename = str_replace($special_chars, '', $basename);

    // 移除非法字符
    $basename = preg_replace('/[\s-]+/', '-', $basename);
    $basename = trim($basename, '.-_');

    // 处理中文字符和其他Unicode字符
    // 保留中文字符，只移除控制字符和格式字符
    if (function_exists('transliterator_transliterate')) {
        // 只移除控制字符和格式字符，保留中文等Unicode字符
        $basename = transliterator_transliterate('[\\p{Cc}] remove; [\\p{Cf}] remove;', $basename);
    }

    // 如果包含中文字符，保留中文字符但确保文件名安全
    if (preg_match('/[\x{4e00}-\x{9fff}]/u', $basename)) {
        // 保留中文字符，只移除可能导致问题的特殊字符
        // 注意：因为使用了'/'作为正则分隔符，需对正斜杠进行转义，避免 Unknown modifier '\' 警告
        $basename = preg_replace('/[<>:"\/\\|?*]/', '', $basename);
        // 如果处理后的文件名为空或过短，则使用安全的替代名称
        if (empty($basename) || mb_strlen($basename, 'UTF-8') < 2) {
            $basename = 'chinese_' . date('YmdHis') . '_' . substr(md5($original_basename), 0, 8);
        }
    }

    // 重新组合文件名和扩展名
    $filename = $basename . $extension;

    // 应用钩子（如果存在的话）
    if (function_exists('apply_filters')) {
        return apply_filters('sanitize_file_name', $filename);
    }

    return $filename;
}

/**
 * 删除文件名中的扩展名后缀
 *
 * @param string $filename 文件名
 * @return string 处理后的文件名（不包含扩展名）
 */
function remove_duplicate_extensions($filename)
{
    // 常见的文件扩展名列表
    $common_extensions = array(
        'jpg',
        'jpeg',
        'png',
        'gif',
        'bmp',
        'webp',
        'ico',
        'svg',
        'pdf',
        'doc',
        'docx',
        'xls',
        'xlsx',
        'ppt',
        'pptx',
        'txt',
        'mp3',
        'wav',
        'ogg',
        'mp4',
        'avi',
        'mov',
        'wmv',
        'webm',
        'zip',
        'rar',
        '7z',
        'tar',
        'gz'
    );

    // 获取文件信息
    $pathinfo = pathinfo($filename);
    $extension = isset($pathinfo['extension']) ? strtolower($pathinfo['extension']) : '';
    $basename = isset($pathinfo['filename']) ? $pathinfo['filename'] : $filename;

    // 如果没有扩展名，直接返回原文件名
    if (empty($extension)) {
        return $filename;
    }

    // 检查是否是常见扩展名
    if (!in_array($extension, $common_extensions)) {
        return $filename;
    }

    // 检查文件名是否以相同的扩展名结尾，如果有则移除
    $pattern = '/\.' . preg_quote($extension, '/') . '$/i';

    // 移除重复的扩展名
    while (preg_match($pattern, $basename)) {
        $basename = preg_replace($pattern, '', $basename);
    }

    // 返回不包含扩展名的文件名
    return $basename;
}

/**
 * 插入附件到数据库
 *
 * @param array $attachment 附件数据
 * @param string $file 文件路径
 * @param int $parent_post_id 父文章ID（可选）
 * @return int|TODO_Error 附件ID或错误对象
 */
function todo_insert_attachment($attachment, $file, $parent_post_id = 0)
{
    global $tododb;

    // 确保有必要的数据
    if (!isset($attachment['post_mime_type'])) {
        $filetype = todo_check_filetype(basename($file), null);
        $attachment['post_mime_type'] = $filetype['type'];
    }

    // 设置默认值，确保满足数据库 NOT NULL 约束
    $attachment = array_merge([
        'site_id' => 0,
        'post_title' => basename($file),
        'post_content' => '',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'post_type' => 'attachment',
        'category_id' => 0,
        'model_id' => 1,
        'seo_title' => '',
        'seo_keywords' => '',
        'seo_description' => '',
        'custom_url' => '',
        'post_name' => '',
        'post_password' => '',
        'view_template' => '',
        'post_author' => get_current_user_id(),
        'post_date' => current_time('mysql'),
        'post_date_gmt' => current_time('mysql', true),
        'post_modified' => current_time('mysql'),
        'post_modified_gmt' => current_time('mysql', true)
    ], $attachment);

    if (empty($attachment['post_name'])) {
        $attachment['post_name'] = sanitize_title($attachment['post_title']);
    }

    // 执行前置钩子
    do_action('todo_insert_attachment_data', $attachment, $file);

    // 插入到数据库
    global $tododb;

    // 构建插入SQL
    $fields = array();
    $values = array();
    $placeholders = array();

    // 过滤掉数据库中不存在的字段，防止插入失败
    $valid_fields = [
        'ID', 'site_id', 'post_title', 'post_content', 'post_excerpt', 'post_status', 
        'post_author', 'category_id', 'featured_image', 'view_count', 'sort_order', 
        'model_id', 'post_type', 'seo_title', 'seo_keywords', 'seo_description', 
        'like_count', 'comment_status', 'comment_count', 'doc_properties', 
        'custom_url', 'post_name', 'read_permission', 'visibility', 'post_password', 
        'view_template', 'created_at', 'updated_at', 'post_date', 'post_date_gmt', 
        'post_modified', 'post_modified_gmt'
    ];

    foreach ($attachment as $field => $value) {
        if (in_array($field, $valid_fields)) {
            $fields[] = $field;
            $values[] = $value;
            $placeholders[] = '?';
        }
    }

    $table_name = TABLE_PREFIX . 'posts';
    $sql = "INSERT INTO {$table_name} (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $result = $tododb->query($sql, $values);

    if (!$result) {
        return new TODO_Error('db_insert_error', '无法插入附件到数据库');
    }

    $attachment_id = $tododb->insert_id();

    if (!$attachment_id) {
        return new TODO_Error('db_insert_error', '无法插入附件到数据库');
    }

    // Phase 3 & 4: Ensure site mapping
    if (function_exists('todo_ensure_post_site_mapping')) {
        todo_ensure_post_site_mapping($attachment_id);
    }

    // 保存附件元数据
    update_post_meta($attachment_id, '_todo_attached_file', todo_relative_upload_path($file));

    // 执行后置钩子
    do_action('todo_insert_attachment', $attachment_id, $attachment, $file);

    return $attachment_id;
}

/**
 * 获取相对于上传目录的路径
 *
 * @param string $file 文件的完整路径
 * @return string 相对于上传目录的路径
 */
function todo_relative_upload_path($file)
{
    $upload_dir = todo_upload_dir(false);
    $basedir = $upload_dir['basedir'];

    if (strpos($file, $basedir) === 0) {
        return ltrim(substr($file, strlen($basedir)), '/');
    }

    return $file;
}

/**
 * 生成附件的元数据
 *
 * @param int $attachment_id 附件ID
 * @param string $file 文件路径
 * @return array 附件元数据
 */
function todo_generate_attachment_metadata($attachment_id, $file)
{
    $metadata = [];
    $attachment = get_post($attachment_id);

    if (!$attachment) {
        return $metadata;
    }

    $mime_type = get_post_mime_type($attachment);

    // 处理图像
    if (strpos($mime_type, 'image/') === 0) {
        if (function_exists('getimagesize')) {
            $imagesize = getimagesize($file);
            if ($imagesize) {
                $metadata['width'] = $imagesize[0];
                $metadata['height'] = $imagesize[1];
                $metadata['sizes'] = [];
            }
        }
    }

    // 处理视频
    else if (strpos($mime_type, 'video/') === 0) {
        // 可以添加视频元数据提取
        $metadata['length'] = 0; // 视频长度，需要特定库支持
    }

    // 处理音频
    else if (strpos($mime_type, 'audio/') === 0) {
        // 可以添加音频元数据提取
        $metadata['length'] = 0; // 音频长度，需要特定库支持
    }

    // 处理文档
    else if (in_array($mime_type, ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])) {
        $metadata['pages'] = 0; // 页数，需要特定库支持
    }

    // 文件大小
    $metadata['_filesize'] = filesize($file);

    // 应用钩子
    return apply_filters('todo_generate_attachment_metadata', $metadata, $attachment_id, $file);
}

/**
 * 生成图像的不同尺寸
 *
 * @param string $file 原始图像路径
 * @param array $metadata 图像元数据
 * @return array 不同尺寸的图像信息
 */
function todo_generate_attachment_sizes($file, $metadata)
{
    return [];
}

/**
 * 选择合适的图像编辑器
 *
 * @param string $file 图像文件路径
 * @return object|false 图像编辑器对象或false
 */
function todo_image_editor_choose($file)
{
    // 检查GD库是否可用
    if (!function_exists('imagecreatefromjpeg') || !function_exists('imagejpeg')) {
        return false;
    }

    // 检查文件是否存在
    if (!file_exists($file)) {
        return false;
    }

    // 检查文件是否为图像
    $info = @getimagesize($file);
    if (!$info) {
        return false;
    }

    // 创建图像编辑器
    return new CMS_Image_Editor($file);
}

/**
 * 更新附件元数据
 *
 * @param int $attachment_id 附件ID
 * @param array $metadata 元数据
 * @return int|bool 成功返回附件ID，失败返回false
 */
function todo_update_attachment_metadata($attachment_id, $metadata)
{
    // 更新附件元数据
    $result = update_post_meta($attachment_id, '_todo_attachment_metadata', $metadata);

    if ($result) {
        // 清理缓存
        clean_post_cache($attachment_id);

        // 执行钩子
        do_action('todo_update_attachment_metadata', $attachment_id, $metadata);
        return $attachment_id;
    }

    return false;
}

/**
 * 获取附件URL
 *
 * @param int $attachment_id 附件ID
 * @return string|false 附件URL或false
 */
if (!function_exists('todo_get_attachment_url')) {
    function todo_get_attachment_url($attachment_id)
    {
        $attachment = get_post($attachment_id);

        if (!$attachment) {
            return false;
        }

        if ($attachment->post_type !== 'attachment') {
            return false;
        }

        $url = '';

        // 首先尝试从guid获取
        if (!empty($attachment->guid)) {
            $url = $attachment->guid;
        }

        // 如果没有guid，尝试从附件文件路径构建URL
        if (empty($url)) {
            $file = get_post_meta($attachment_id, '_todo_attached_file', true);
            if ($file) {
                $uploads = todo_upload_dir(false);

                // 处理绝对路径的情况
                if (strpos($file, $uploads['basedir']) === 0) {
                    $url = str_replace($uploads['basedir'], $uploads['baseurl'], $file);
                }
                // 处理相对路径的情况（统一使用 /content/uploads/{yyyy}/{mm}/{dd}/ 结构）
                else {
                    // 确保路径以 / 开头，并移除多余的斜杠
                    $file = '/' . ltrim($file, '/');

                    // 如果文件路径不是以 /content/uploads/ 开头，则添加基础路径
                    if (strpos($file, '/content/uploads/') !== 0) {
                        $url = $uploads['baseurl'] . $file;
                    } else {
                        // 文件路径已经包含完整的相对路径
                        $url = $file;
                    }
                }
            }
        }

        if ($url !== '' && function_exists('todo_media_url_to_relative')) {
            $url = todo_media_url_to_relative($url);
        }

        return apply_filters('todo_get_attachment_url', $url, $attachment_id);
    }
}

/**
 * 获取附件图像源
 *
 * @param int $attachment_id 附件ID
 * @param string|array $size 图像尺寸
 * @return array|false 包含URL、宽度和高度的数组，或false
 */
function todo_get_attachment_image_src($attachment_id, $size = 'thumbnail')
{
    $attachment = get_post($attachment_id);

    if (!$attachment) {
        return false;
    }

    if ($attachment->post_type !== 'attachment' || strpos($attachment->post_mime_type, 'image/') !== 0) {
        return false;
    }

    $metadata = todo_get_attachment_metadata($attachment_id);
    $url = todo_get_attachment_url($attachment_id);

    if (!$metadata || !$url) {
        return false;
    }

    $width = 0;
    $height = 0;
    $width = $metadata['width'] ?? 0;
    $height = $metadata['height'] ?? 0;

    return [$url, $width, $height];
}

/**
 * 获取附件元数据
 *
 * @param int $attachment_id 附件ID
 * @return array|false 附件元数据或false
 */
function todo_get_attachment_metadata($attachment_id)
{
    return get_post_meta($attachment_id, '_todo_attachment_metadata', true);
}

/**
 * 获取附件文件路径
 *
 * @param int $attachment_id 附件ID
 * @return string|false 附件文件路径或false
 */
function get_attached_file($attachment_id)
{
    $file = get_post_meta($attachment_id, '_todo_attached_file', true);

    if (!$file) {
        return false;
    }

    $uploads = todo_upload_dir(false);

    if (strpos($file, $uploads['basedir']) === 0) {
        return $file;
    }

    return $uploads['basedir'] . '/' . $file;
}

/**
 * 删除附件
 *
 * @param int $attachment_id 附件ID
 * @param bool $force_delete 是否强制删除（不放入回收站）
 * @return CMS_Post|false|null 成功返回删除的文章对象，失败返回false，文章不存在返回null
 */
function todo_delete_attachment($attachment_id, $force_delete = false)
{
    $attachment = get_post($attachment_id);

    if (!$attachment) {
        return null;
    }

    if ($attachment->post_type !== 'attachment') {
        return false;
    }

    // 执行前置钩子
    do_action('todo_delete_attachment', $attachment_id, $attachment);

    // 删除附件文件
    $file = get_attached_file($attachment_id);
    if ($file && file_exists($file)) {
        @unlink($file);
    }

    // 删除附件记录
    global $tododb;

    $table_name = TABLE_PREFIX . 'posts';

    if ($force_delete) {
        // 永久删除
        $sql = "DELETE FROM {$table_name} WHERE ID = ?";
        $result = $tododb->query($sql, [$attachment_id]);
    } else {
        // 移动到回收站
        $sql = "UPDATE {$table_name} SET post_status = 'trash' WHERE ID = ?";
        $result = $tododb->query($sql, [$attachment_id]);
    }

    if ($result) {
        // 删除附件元数据
        delete_post_meta($attachment_id, '_todo_attached_file');
        delete_post_meta($attachment_id, '_todo_attachment_metadata');

        // 执行后置钩子
        do_action('deleted_attachment', $attachment_id, $attachment);

        return $attachment;
    }

    return false;
}

/**
 * 格式化文件大小
 *
 * @param int $bytes 字节数
 * @param int $decimals 小数位数
 * @return string 格式化后的文件大小
 */
if (!function_exists('size_format')) {
    function size_format($bytes, $decimals = 0)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB', 'PB');

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $decimals) . ' ' . $units[$pow];
    }
}

/**
 * 检查文件类型和扩展名
 *
 * @param string $file 文件路径
 * @param string $filename 文件名
 * @param array $mimes 允许的MIME类型
 * @return array 包含ext、type和proper_filename的数组
 */
function todo_check_filetype_and_ext($file, $filename, $mimes = null)
{
    $proper_filename = false;

    // 基本扩展名验证和MIME映射
    $todo_filetype = todo_check_filetype($filename, $mimes);
    $ext = $todo_filetype['ext'];
    $type = $todo_filetype['type'];

    // 如果文件不存在，无法进行进一步验证
    if (!file_exists($file)) {
        return compact('ext', 'type', 'proper_filename');
    }

    $real_mime = false;

    // 验证图片类型
    if ($type && strpos($type, 'image/') === 0) {
        // 尝试确定实际的图片类型
        if (function_exists('getimagesize')) {
            $imagesize = getimagesize($file);
            if ($imagesize && isset($imagesize['mime'])) {
                $real_mime = $imagesize['mime'];
            }
        }

        if ($real_mime && $real_mime != $type) {
            // MIME类型到扩展名的映射
            $mime_to_ext = array(
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/bmp' => 'bmp',
                'image/webp' => 'webp',
            );

            // 替换文件名中最后一个点后的扩展名
            if (!empty($mime_to_ext[$real_mime])) {
                $filename_parts = explode('.', $filename);
                array_pop($filename_parts);
                $filename_parts[] = $mime_to_ext[$real_mime];
                $new_filename = implode('.', $filename_parts);

                if ($new_filename != $filename) {
                    $proper_filename = $new_filename; // 标记已更改
                }
                // 重新定义扩展名/MIME
                $todo_filetype = todo_check_filetype($new_filename, $mimes);
                $ext = $todo_filetype['ext'];
                $type = $todo_filetype['type'];
            }
        }
    }

    // 使用fileinfo扩展验证其他文件
    if ($type && !$real_mime && extension_loaded('fileinfo')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $real_mime = finfo_file($finfo, $file);
        finfo_close($finfo);

        // 检查MIME类型是否匹配
        if ($real_mime && $real_mime != $type) {
            // 如果实际MIME类型与预期不符，可能需要调整
            // 这里简化处理，保持原有类型
        }
    }

    return compact('ext', 'type', 'proper_filename');
}

/**
 * 调整图像大小
 *
 * @param string $file 原始图像文件路径
 * @param int $max_w 最大宽度
 * @param int $max_h 最大高度
 * @param bool $crop 是否裁剪
 * @param string $suffix 文件名后缀
 * @param string $dest_path 目标路径
 * @param int $jpeg_quality JPEG质量
 * @return string|TODO_Error 成功返回新文件路径，失败返回错误对象
 */
function image_resize($file, $max_w, $max_h, $crop = false, $suffix = null, $dest_path = null, $jpeg_quality = 90)
{
    // 检查文件是否存在
    if (!file_exists($file)) {
        return new TODO_Error('file_not_found', '文件不存在: ' . $file);
    }

    // 获取图像信息
    $image_info = getimagesize($file);
    if (!$image_info) {
        return new TODO_Error('invalid_image', '无效的图像文件: ' . $file);
    }

    list($orig_w, $orig_h, $orig_type) = $image_info;

    // 检查是否需要调整大小
    if ($orig_w <= $max_w && $orig_h <= $max_h && !$crop) {
        // 如果原图尺寸已经符合要求且不需要裁剪，直接返回原文件
        return $file;
    }

    // 使用CMS_Image类进行图像处理
    $image = new CMS_Image($file);
    if (!$image) {
        return new TODO_Error('image_load_failed', '无法加载图像: ' . $file);
    }

    // 计算新的尺寸
    if ($crop) {
        $new_w = $max_w;
        $new_h = $max_h;
    } else {
        // 按比例缩放
        $size_ratio = max($orig_w / $max_w, $orig_h / $max_h);
        $new_w = intval($orig_w / $size_ratio);
        $new_h = intval($orig_h / $size_ratio);
    }

    // 调整图像大小
    $resized = $image->resize($new_w, $new_h, $crop);
    if (!$resized) {
        $image->destroy();
        return new TODO_Error('resize_failed', '图像调整大小失败');
    }

    // 生成新文件名
    $info = pathinfo($file);
    $dir = $info['dirname'];
    $ext = $info['extension'];
    $name = basename($file, ".$ext");

    if (!$suffix) {
        $suffix = "{$new_w}x{$new_h}";
    }

    if ($dest_path) {
        $new_file = $dest_path;
    } else {
        $new_file = $dir . '/' . $name . '-' . $suffix . '.' . $ext;
    }

    // 保存调整后的图像
    $saved = $image->save($new_file, $jpeg_quality);
    $image->destroy();

    if (!$saved) {
        return new TODO_Error('save_failed', '保存调整后的图像失败');
    }

    return $new_file;
}

/**
 * 获取附件图片URL（TodoCMS兼容函数）
 *
 * @param int $attachment_id 附件ID
 * @param string|array $size 图像尺寸
 * @return string|false 图片URL或false
 */
function todo_get_attachment_image_url($attachment_id, $size = 'thumbnail')
{
    $image_src = todo_get_attachment_image_src($attachment_id, $size);

    if ($image_src && is_array($image_src)) {
        return $image_src[0]; // 返回URL部分
    }

    return false;
}
/**
 * 从URL下载文件到临时位置
 *
 * @param string $url 要下载的URL
 * @param int $timeout 超时时间（秒）
 * @param array $accepted_origins 允许的来源域名
 * @param string $filename 指定的文件名
 * @return string|TODO_Error 成功返回临时文件路径，失败返回错误对象
 */
function download_url($url, $timeout = 300, $accepted_origins = null, $filename = '')
{
    // 验证URL格式和协议
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return new TODO_Error('invalid_url', '无效的URL格式');
    }

    $parsed_url = parse_url($url);
    if (!in_array($parsed_url['scheme'], array('http', 'https'))) {
        return new TODO_Error('invalid_protocol', '不支持的协议，仅支持HTTP和HTTPS');
    }

    // 检查域名白名单
    if ($accepted_origins && is_array($accepted_origins) && !in_array($parsed_url['host'], $accepted_origins)) {
        return new TODO_Error('invalid_origin', '不允许的来源域名');
    }

    // 生成安全的文件名
    if (empty($filename)) {
        $filename = basename($parsed_url['path']) ?: 'download_' . time();
    }

    $safe_filename = function_exists('sanitize_file_name')
        ? sanitize_file_name($filename)
        : preg_replace('/[<>:"\/\\|?*\x00-\x1F]/u', '', $filename);

    if (empty($safe_filename)) {
        $safe_filename = 'download_' . time() . '.tmp';
    }

    // 创建临时文件
    $temp_dir = sys_get_temp_dir();

    // 如果系统临时目录不可用，尝试备用目录
    if (!is_dir($temp_dir) || !is_writable($temp_dir)) {
        $alternative_dirs = array(
            ini_get('upload_tmp_dir'),
            '/tmp',
            '/var/tmp',
            dirname(ABSPATH) . '/temp'
        );

        foreach ($alternative_dirs as $alt_dir) {
            if (!empty($alt_dir) && is_dir($alt_dir) && is_writable($alt_dir)) {
                $temp_dir = rtrim($alt_dir, '/');
                break;
            }
        }

        // 如果所有目录都不可用，返回错误
        if (!is_dir($temp_dir) || !is_writable($temp_dir)) {
            return new TODO_Error('temp_dir_error', '临时目录不可用: ' . $temp_dir . ' (已尝试备用目录)');
        }
    }

    $temp_file = $temp_dir . '/' . uniqid('todo_download_') . '_' . $safe_filename;
    if (strlen($temp_file) > 255) {
        $temp_file = $temp_dir . '/' . uniqid('todo_') . '_download.tmp';
    }

    // 执行下载
    $ch = curl_init();
    $file_handle = @fopen($temp_file, 'w');

    if (!$file_handle) {
        curl_close($ch);

        // 获取详细错误信息
        $error_details = array();
        $last_error = error_get_last();
        if ($last_error && $last_error['message']) {
            $error_details[] = 'PHP错误: ' . $last_error['message'];
        }

        // 检查目录权限
        if (!is_writable($temp_dir)) {
            $error_details[] = '临时目录不可写';
        }

        // 检查磁盘空间
        $free_space = disk_free_space($temp_dir);
        if ($free_space !== false && $free_space < 1024 * 1024) { // 小于1MB
            $error_details[] = '磁盘空间不足';
        }

        $error_msg = '无法创建临时文件: ' . $temp_file;
        if (!empty($error_details)) {
            $error_msg .= ' (' . implode(', ', $error_details) . ')';
        }

        return new TODO_Error('file_open_failed', $error_msg);
    }

    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FILE => $file_handle,
        CURLOPT_HEADER => false
    ));

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    fclose($file_handle);
    curl_close($ch);

    // 验证下载结果
    if ($result === false || !empty($error) || $http_code >= 400) {
        if (file_exists($temp_file)) {
            unlink($temp_file);
        }
        $error_msg = !empty($error) ? $error : 'HTTP错误: ' . $http_code;
        return new TODO_Error('download_failed', $error_msg);
    }

    // 检查文件有效性
    if (!file_exists($temp_file) || filesize($temp_file) == 0) {
        if (file_exists($temp_file)) {
            unlink($temp_file);
        }
        return new TODO_Error('empty_file', '下载的文件为空');
    }

    // 检查文件大小限制
    $max_size = apply_filters('todo_download_max_size', 50 * 1024 * 1024);
    if (filesize($temp_file) > $max_size) {
        unlink($temp_file);
        return new TODO_Error('file_too_large', '文件大小超过限制');
    }

    return $temp_file;
}

/**
 * 下载远程图片并保存到素材库
 *
 * @param string $url 远程图片URL
 * @param int $parent_post_id 父文章ID
 * @param string $custom_filename 自定义文件名
 * @return string|TODO_Error 成功返回图片URL，失败返回错误对象
 */
function todo_download_remote_image($url, $parent_post_id = 0, $custom_filename = '') {
    if (empty($url)) {
        return new TODO_Error('invalid_url', 'URL不能为空');
    }

    // 下载文件到临时目录
    $temp_file = download_url($url);
    if (is_todo_error($temp_file)) {
        return $temp_file;
    }

    // 检查是否为图片
    $file_type = todo_check_filetype(basename($url));
    if (!$file_type['type'] || strpos($file_type['type'], 'image/') !== 0) {
        $real_type = get_file_mime_type($temp_file);
        if (strpos($real_type, 'image/') !== 0) {
            unlink($temp_file);
            return new TODO_Error('invalid_image', '远程文件不是有效的图片');
        }
        $file_type['type'] = $real_type;
    }

    // 准备上传
    $upload_dir = todo_upload_dir();
    if (!empty($custom_filename)) {
        $filename = function_exists('sanitize_file_name') ? sanitize_file_name($custom_filename) : $custom_filename;
    } else {
        $filename = basename($url);
        // 去掉URL参数
        if (strpos($filename, '?') !== false) {
            $filename = substr($filename, 0, strpos($filename, '?'));
        }
        $name_without_ext = pathinfo($filename, PATHINFO_FILENAME);
        // 如果文件名是纯数字（如 100, 64, 40）或通用词（avatar, head, headimg），防止三方头像重名冲突，增加随机 hash
        if (is_numeric($name_without_ext) || in_array(strtolower($name_without_ext), ['avatar', 'head', 'headimg', 'user', 'default', 'image', 'photo'])) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $filename = $name_without_ext . '_' . substr(md5($url . uniqid('', true)), 0, 8) . ($ext ? '.' . $ext : '');
        }
    }
    
    // 如果没有扩展名，尝试根据MIME类型补齐
    if (strpos($filename, '.') === false) {
        $ext_map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/bmp' => 'bmp'
        ];
        $filename .= '.' . ($ext_map[$file_type['type']] ?? 'jpg');
    }

    // 使用 CMS_Media 类处理上传，确保入库到 todo_media 表（媒体库 data-tab="library" 会用到）
    if (!class_exists('CMS_Media')) {
        require_once ABSPATH . 'core/classes/class-media.php';
    }

    $file_array = [
        'name'     => $filename,
        'tmp_name' => $temp_file,
        'size'     => filesize($temp_file),
        'type'     => $file_type['type'],
        'error'    => UPLOAD_ERR_OK
    ];

    $media_id = CMS_Media::upload($file_array, ['generate_thumbnails' => true]);

    if (!$media_id) {
        if (file_exists($temp_file)) {
            unlink($temp_file);
        }
        return new TODO_Error('upload_error', '无法保存图片到媒体库');
    }

    // 获取上传后的文件信息
    $media = new CMS_Media($media_id);

    $url = $media->get_url();
    if (function_exists('todo_media_url_to_relative')) {
        $url = todo_media_url_to_relative($url);
    }
    return $url;
}

/**
 * 检查文件是否已在媒体库中
 *
 * @param string $file_path 文件相对路径
 * @return bool 是否已在媒体库中
 */
if (!function_exists('todo_is_file_in_media_library')) {
    function todo_is_file_in_media_library($file_path, $site_id = null)
    {
        // 获取数据库连接
        global $tododb;
        if (!$tododb) {
            return false;
        }

        $norm = is_string($file_path) ? ltrim($file_path, '/') : '';
        if ($norm === '') {
            return false;
        }

        // 确定用于检查的 site_id
        if ($site_id === null && function_exists('todo_current_site_id')) {
            $site_id = todo_current_site_id();
        }
        $site_id = ($site_id !== null) ? intval($site_id) : 0;

        // 如果路径中包含 sitesX 前缀，且与当前检查的 site_id 匹配，则移除它以匹配数据库中的相对路径
        if ($site_id > 0) {
            $site_prefix = 'sites' . $site_id . '/';
            if (strpos($norm, $site_prefix) === 0) {
                $norm = substr($norm, strlen($site_prefix));
            }
        }

        $upload_info = todo_upload_dir(false);
        $baseurl = isset($upload_info['baseurl']) ? rtrim((string) $upload_info['baseurl'], '/') : '';
        $abs_url = ($baseurl !== '' ? ($baseurl . '/' . $norm) : '');
        $rel_url = $abs_url;
        if ($abs_url !== '' && function_exists('todo_media_url_to_relative')) {
            $rel_url = todo_media_url_to_relative($abs_url);
        }

        $candidates_path = [$norm, '/' . $norm];
        $candidates_url = [];
        if ($rel_url !== '') $candidates_url[] = $rel_url;
        if ($abs_url !== '' && $abs_url !== $rel_url) $candidates_url[] = $abs_url;

        $placeholders_path = implode(',', array_fill(0, count($candidates_path), '?'));
        $placeholders_url = $candidates_url ? implode(',', array_fill(0, count($candidates_url), '?')) : '';

        // 构建SQL，加入site_id过滤
        $sql = "SELECT COUNT(*) FROM {$tododb->prefix}media WHERE (media_path IN ({$placeholders_path})";
        $params = $candidates_path;
        if ($placeholders_url !== '') {
            $sql .= " OR media_url IN ({$placeholders_url})";
            $params = array_merge($params, $candidates_url);
        }
        $sql .= ")";
        
        // 增加 site_id 过滤
        $sql .= " AND site_id = ?";
        $params[] = $site_id;

        $count = $tododb->get_var($sql, $params);

        return intval($count) > 0;
    }
}

/**
 * 获取文件MIME类型
 *
 * @param string $file_path 文件路径
 * @return string MIME类型
 */
if (!function_exists('get_file_mime_type')) {
    function get_file_mime_type($file_path)
    {
        if (!file_exists($file_path)) {
            return 'application/octet-stream';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file_path);
        finfo_close($finfo);

        if (!$mime_type) {
            // 备用方法：根据文件扩展名判断
            $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
            $mime_types = array(
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'pdf' => 'application/pdf',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls' => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'mp4' => 'video/mp4',
                'mp3' => 'audio/mpeg',
                'zip' => 'application/zip',
                'txt' => 'text/plain'
            );

            $mime_type = isset($mime_types[$ext]) ? $mime_types[$ext] : 'application/octet-stream';
        }

        return $mime_type;
    }
}

/**
 * 根据媒体ID获取媒体数据的 seo_keywords（带静态缓存）
 *
 * @param int $media_id 媒体ID
 * @return string
 */
if (!function_exists('todo_get_media_seo_keywords_by_id')) {
    function todo_get_media_seo_keywords_by_id($media_id)
    {
        static $keywords_cache = [];
        $media_id = intval($media_id);
        if ($media_id <= 0) {
            return '';
        }
        if (isset($keywords_cache[$media_id])) {
            return $keywords_cache[$media_id];
        }

        $keywords = '';
        if (class_exists('CMS_Media')) {
            $media = new CMS_Media($media_id);
            if ($media->exists()) {
                $keywords = (string) ($media->get_data('seo_keywords') ?: '');
            }
        }

        $keywords_cache[$media_id] = $keywords;
        return $keywords;
    }
}

/**
 * 根据 URL 尝试从 ***_media 表查询媒体数据的 seo_keywords（带静态缓存）
 *
 * @param string $url 媒体 URL 或文件路径
 * @return string
 */
if (!function_exists('todo_get_media_seo_keywords_by_url')) {
    function todo_get_media_seo_keywords_by_url($url)
    {
        static $url_cache = [];
        $url = trim((string)$url);
        if ($url === '') {
            return '';
        }
        $cache_key = md5($url);
        if (array_key_exists($cache_key, $url_cache)) {
            return $url_cache[$cache_key];
        }

        $keywords = '';

        if (class_exists('CMS_HTML_Template_Tags') && method_exists('CMS_HTML_Template_Tags', 'get_media_info')) {
            $info = CMS_HTML_Template_Tags::get_media_info($url);
            if ($info && !empty($info->seo_keywords)) {
                $keywords = (string) $info->seo_keywords;
                $url_cache[$cache_key] = $keywords;
                return $keywords;
            }
        }

        global $cms_db, $tododb;
        $db = $tododb ?: $cms_db;
        if (!$db) {
            $db = new CMS_DB();
        }

        $filename = basename(parse_url($url, PHP_URL_PATH) ?: $url);
        if (!empty($filename)) {
            $table_name = defined('TABLE_PREFIX') ? TABLE_PREFIX . 'media' : 'todo_media';
            $row = $db->get_row(
                "SELECT seo_keywords FROM {$table_name} WHERE media_filename = ? OR media_path LIKE ? LIMIT 1",
                [$filename, '%' . $filename]
            );
            if ($row && !empty($row->seo_keywords)) {
                $keywords = (string) $row->seo_keywords;
            }
        }

        $url_cache[$cache_key] = $keywords;
        return $keywords;
    }
}

/**
 * 根据当前语言环境过滤 featured_image 多图列表。
 * 若有匹配当前语言的图片，则仅返回匹配当前语言的图片列表；若无匹配项则回退返回全部图片。
 *
 * @param mixed $raw_featured_image featured_image 数据（JSON字符串或数组）
 * @param string|null $lang 指定语言代码（默认读取当前语言）
 * @return array 过滤后的图片项数组
 */
/**
 * 根据当前语言环境过滤 featured_image 多图列表。
 * - 若有精准匹配当前语言的图片，则仅返回匹配当前语言的图片列表；
 * - 若数组中包含特定语言标识的图片（如 en/hk），但在当前语言（如 zh）下无精准匹配，则过滤掉带有外语标识的图片，仅输出无外语标识（或匹配默认语言）的图片；
 * - 若整个数组的图片均无任何语言标识，则回退返回全部图片。
 *
 * @param mixed $raw_featured_image featured_image 数据（JSON字符串或数组）
 * @param string|null $lang 指定语言代码（默认读取当前语言）
 * @return array 过滤后的图片项数组
 */
if (!function_exists('todo_filter_featured_images_by_lang')) {
    function todo_filter_featured_images_by_lang($raw_featured_image, $lang = null)
    {
        if (empty($raw_featured_image)) {
            return [];
        }

        if ($lang === null) {
            $lang = function_exists('get_current_language') ? get_current_language() : '';
        }
        $target_lang = strtolower(trim((string) $lang));
        $short_lang = !empty($target_lang) ? explode('-', $target_lang)[0] : '';

        $items = [];
        if (is_string($raw_featured_image)) {
            $trim = trim($raw_featured_image);
            if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
                $decoded = json_decode($trim, true);
                if (is_array($decoded)) {
                    if (isset($decoded['images']) && is_array($decoded['images'])) {
                        $items = $decoded['images'];
                    } elseif (isset($decoded[0])) {
                        $items = $decoded;
                    } elseif (isset($decoded['url'])) {
                        $items = [$decoded];
                    }
                }
            } elseif (function_exists('maybe_unserialize')) {
                $unserialized = maybe_unserialize($trim);
                if (is_array($unserialized)) {
                    $items = $unserialized;
                }
            }
            if (empty($items) && (strpos($trim, 'http') === 0 || strpos($trim, '/') === 0)) {
                $items = [['url' => $trim]];
            }
        } elseif (is_array($raw_featured_image)) {
            if (isset($raw_featured_image['images']) && is_array($raw_featured_image['images'])) {
                $items = $raw_featured_image['images'];
            } elseif (isset($raw_featured_image[0])) {
                $items = $raw_featured_image;
            } elseif (isset($raw_featured_image['url'])) {
                $items = [$raw_featured_image];
            }
        }

        if (empty($items)) {
            return [];
        }

        // 整理每个 item 的语言标签，并统计整个数组是否包含任何语言标识
        $parsed_items = [];
        $has_any_lang_tags = false;

        foreach ($items as $item) {
            $url = '';
            $seo_kw = '';
            if (is_string($item)) {
                $url = trim($item);
                if ($url === '') continue;
                $seo_kw = todo_get_media_seo_keywords_by_url($url);
                $item_arr = ['url' => $url];
            } elseif (is_array($item)) {
                $url = isset($item['url']) ? trim((string) $item['url']) : '';
                if ($url === '') continue;
                $item_arr = $item;

                $seo_kw = isset($item['seo_keywords']) ? trim((string) $item['seo_keywords']) : '';
                if ($seo_kw === '' && !empty($item['id'])) {
                    $seo_kw = todo_get_media_seo_keywords_by_id($item['id']);
                }
                if ($seo_kw === '' && !empty($url)) {
                    $seo_kw = todo_get_media_seo_keywords_by_url($url);
                }
            } else {
                continue;
            }

            $tags = [];
            if ($seo_kw !== '') {
                $tags = array_filter(array_map('trim', preg_split('/[\s,]+/', strtolower($seo_kw))));
                if (!empty($tags)) {
                    $has_any_lang_tags = true;
                }
            }

            $parsed_items[] = [
                'data' => $item_arr,
                'tags' => $tags,
            ];
        }

        if (empty($parsed_items)) {
            return [];
        }

        // 如果整个数组没有任何一张图设置了语言关键词，则回退返回全部图片
        if (!$has_any_lang_tags) {
            return array_column($parsed_items, 'data');
        }

        // 1. 尝试寻找与 $target_lang 或 $short_lang 精准匹配的图片
        $explicit_matches = [];
        foreach ($parsed_items as $pi) {
            if (!empty($pi['tags'])) {
                if ((!empty($target_lang) && in_array($target_lang, $pi['tags'], true)) ||
                    (!empty($short_lang) && in_array($short_lang, $pi['tags'], true))) {
                    $explicit_matches[] = $pi['data'];
                }
            }
        }
        if (!empty($explicit_matches)) {
            return $explicit_matches;
        }

        // 2. 若未找到精准匹配项，但在数组中存在其他特定语言标识（说明这是一个区分语言的多图数组）：
        // 则为默认语言（如 zh）保留没有标注外语标识（或标注了当前语言/默认语言）的图片
        $unassigned_items = [];
        foreach ($parsed_items as $pi) {
            if (empty($pi['tags'])) {
                // 无语言标识，作为默认语言图片
                $unassigned_items[] = $pi['data'];
            } else {
                // 检查是否包含当前语言或 zh/cn 标识
                if ((!empty($target_lang) && in_array($target_lang, $pi['tags'], true)) ||
                    (!empty($short_lang) && in_array($short_lang, $pi['tags'], true)) ||
                    in_array('zh', $pi['tags'], true) || in_array('cn', $pi['tags'], true) || in_array('zh-cn', $pi['tags'], true)) {
                    $unassigned_items[] = $pi['data'];
                }
            }
        }

        if (!empty($unassigned_items)) {
            return $unassigned_items;
        }

        // 3. 极罕见兜底：若所有图片都带了非当前语言标签（如只有 en 和 hk，而当前是 fr），回退返回全部图片防空白
        return array_column($parsed_items, 'data');
    }
}

/**
 * 根据当前语言环境，从 featured_image 数据中寻找匹配 seo_keywords 的第一张图片 URL
 *
 * @param mixed $raw_featured_image featured_image 数据（JSON字符串或数组）
 * @param string|null $lang 指定语言代码（默认读取当前语言）
 * @return string|false 匹配成功的图片URL，若未匹配成功或无多图返回 false
 */
if (!function_exists('todo_match_featured_image_by_lang')) {
    function todo_match_featured_image_by_lang($raw_featured_image, $lang = null)
    {
        if (empty($raw_featured_image)) {
            return false;
        }
        $filtered = todo_filter_featured_images_by_lang($raw_featured_image, $lang);
        if (empty($filtered)) {
            return false;
        }
        $first = reset($filtered);
        $url = is_array($first) ? (isset($first['url']) ? trim((string) $first['url']) : '') : (is_string($first) ? trim($first) : '');
        if ($url !== '' && function_exists('todo_media_url_to_runtime_absolute')) {
            $url = todo_media_url_to_runtime_absolute($url);
        }
        return $url !== '' ? $url : false;
    }
}

/**
 * 根据 ogimg 标识 -> 当前语言标识 -> 默认第一张图 优先级匹配并获取 og:image URL
 *
 * @param mixed $raw_image_data 图像数据（JSON字符串、数组、URL或附件ID）
 * @param string|null $lang 指定语言代码（默认读取当前语言）
 * @return string|false 匹配到的绝对 URL，失败返回 false
 */
if (!function_exists('todo_match_og_image')) {
    function todo_match_og_image($raw_image_data, $lang = null)
    {
        if (empty($raw_image_data)) {
            return false;
        }

        if ($lang === null) {
            $lang = function_exists('get_current_language') ? get_current_language() : '';
        }
        $target_lang = strtolower(trim((string) $lang));
        $short_lang = !empty($target_lang) ? explode('-', $target_lang)[0] : '';

        $items = [];
        if (is_string($raw_image_data)) {
            $trim = trim($raw_image_data);
            if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
                $decoded = json_decode($trim, true);
                if (is_array($decoded)) {
                    if (isset($decoded['images']) && is_array($decoded['images'])) {
                        $items = $decoded['images'];
                    } elseif (isset($decoded[0])) {
                        $items = $decoded;
                    } elseif (isset($decoded['url'])) {
                        $items = [$decoded];
                    }
                }
            } elseif (function_exists('maybe_unserialize')) {
                $unserialized = maybe_unserialize($trim);
                if (is_array($unserialized)) {
                    $items = $unserialized;
                }
            }
            if (empty($items) && (strpos($trim, 'http') === 0 || strpos($trim, '/') === 0 || is_numeric($trim))) {
                $items = [$trim];
            }
        } elseif (is_array($raw_image_data)) {
            if (isset($raw_image_data['images']) && is_array($raw_image_data['images'])) {
                $items = $raw_image_data['images'];
            } elseif (isset($raw_image_data[0])) {
                $items = $raw_image_data;
            } elseif (isset($raw_image_data['url'])) {
                $items = [$raw_image_data];
            }
        } elseif (is_numeric($raw_image_data) && (int)$raw_image_data > 0) {
            $items = [(int)$raw_image_data];
        }

        if (empty($items)) {
            return false;
        }

        $parsed_items = [];
        foreach ($items as $item) {
            $url = '';
            $seo_kw = '';

            if (is_numeric($item)) {
                $media_id = (int)$item;
                if (!$url && class_exists('CMS_Media')) {
                    $media = new CMS_Media($media_id);
                    if ($media->exists()) {
                        $url = $media->get_url();
                    }
                }
                if (function_exists('todo_get_media_seo_keywords_by_id')) {
                    $seo_kw = todo_get_media_seo_keywords_by_id($media_id);
                }
            } elseif (is_string($item)) {
                $url = trim($item);
                if ($url !== '' && function_exists('todo_get_media_seo_keywords_by_url')) {
                    $seo_kw = todo_get_media_seo_keywords_by_url($url);
                }
            } elseif (is_array($item)) {
                $url = isset($item['url']) ? trim((string) $item['url']) : '';
                if ($url === '' && !empty($item['id'])) {
                    $media_id = (int)$item['id'];
                    if (!$url && class_exists('CMS_Media')) {
                        $media = new CMS_Media($media_id);
                        if ($media->exists()) {
                            $url = $media->get_url();
                        }
                    }
                }
                $seo_kw = isset($item['seo_keywords']) ? trim((string) $item['seo_keywords']) : (isset($item['seoKeywords']) ? trim((string) $item['seoKeywords']) : '');
                if ($seo_kw === '' && !empty($item['id']) && function_exists('todo_get_media_seo_keywords_by_id')) {
                    $seo_kw = todo_get_media_seo_keywords_by_id($item['id']);
                }
                if ($seo_kw === '' && !empty($url) && function_exists('todo_get_media_seo_keywords_by_url')) {
                    $seo_kw = todo_get_media_seo_keywords_by_url($url);
                }
            }

            if ($url === '') {
                continue;
            }

            $tags = [];
            if ($seo_kw !== '') {
                $tags = array_filter(array_map('trim', preg_split('/[\s,]+/', strtolower($seo_kw))));
            }

            $parsed_items[] = [
                'url' => $url,
                'tags' => $tags,
            ];
        }

        if (empty($parsed_items)) {
            return false;
        }

        // 1. 优先匹配 seoKeywords 包含 ogimg
        foreach ($parsed_items as $pi) {
            if (in_array('ogimg', $pi['tags'], true)) {
                $selected_url = $pi['url'];
                if (function_exists('todo_media_url_to_runtime_absolute')) {
                    $selected_url = todo_media_url_to_runtime_absolute($selected_url);
                } elseif (function_exists('todo_media_url_to_absolute')) {
                    $selected_url = todo_media_url_to_absolute($selected_url);
                }
                return $selected_url;
            }
        }

        // 2. 其次匹配相应语言 seoKeywords (如 en / target_lang / short_lang)
        if (!empty($target_lang) || !empty($short_lang)) {
            foreach ($parsed_items as $pi) {
                if (!empty($pi['tags'])) {
                    if ((!empty($target_lang) && in_array($target_lang, $pi['tags'], true)) ||
                        (!empty($short_lang) && in_array($short_lang, $pi['tags'], true))) {
                        $selected_url = $pi['url'];
                        if (function_exists('todo_media_url_to_runtime_absolute')) {
                            $selected_url = todo_media_url_to_runtime_absolute($selected_url);
                        } elseif (function_exists('todo_media_url_to_absolute')) {
                            $selected_url = todo_media_url_to_absolute($selected_url);
                        }
                        return $selected_url;
                    }
                }
            }
        }

        // 3. 最后默认第一张图
        $first = reset($parsed_items);
        $selected_url = $first['url'];
        if (function_exists('todo_media_url_to_runtime_absolute')) {
            $selected_url = todo_media_url_to_runtime_absolute($selected_url);
        } elseif (function_exists('todo_media_url_to_absolute')) {
            $selected_url = todo_media_url_to_absolute($selected_url);
        }
        return $selected_url !== '' ? $selected_url : false;
    }
}

/**
 * 获取符合当前（或指定）语言环境的站点 Logo 图片 URL
 *
 * @param string|null $lang 指定语言代码（默认读取当前语言）
 * @return string Logo 图片 URL，若未设置则返回空字符串
 */
if (!function_exists('todo_get_site_logo_url')) {
    function todo_get_site_logo_url($lang = null)
    {
        $raw_logo = function_exists('todo_get_option_ml') ? todo_get_option_ml('site_logo', (function_exists('todo_get_option') ? todo_get_option('site_logo', '') : '')) : (function_exists('todo_get_option') ? todo_get_option('site_logo', '') : '');
        if (empty($raw_logo) && function_exists('get_option')) {
            $raw_logo = get_option('site_logo', '');
        }

        if (empty($raw_logo)) {
            return '';
        }

        // 1. 优先精准寻找带有当前语言标识（如 en）的图片
        $matched_url = todo_match_featured_image_by_lang($raw_logo, $lang);
        if ($matched_url !== false && $matched_url !== '') {
            return function_exists('todo_media_url_to_runtime_absolute') ? todo_media_url_to_runtime_absolute($matched_url) : $matched_url;
        }

        // 2. 根据多语言规则过滤图片列表（默认语言剔除外语图，通用图片全量回退）
        $filtered = todo_filter_featured_images_by_lang($raw_logo, $lang);
        if (!empty($filtered)) {
            $first = $filtered[0];
            $url = is_array($first) ? (isset($first['url']) ? trim((string) $first['url']) : '') : (is_string($first) ? trim($first) : '');
            if ($url !== '') {
                return function_exists('todo_media_url_to_runtime_absolute') ? todo_media_url_to_runtime_absolute($url) : $url;
            }
        }

        // 3. 兜底情况：若 $raw_logo 为字符串且为单图片路径
        if (is_string($raw_logo)) {
            $url = trim($raw_logo);
            if ($url !== '' && strpos($url, '[{') !== 0 && strpos($url, '{') !== 0) {
                return function_exists('todo_media_url_to_runtime_absolute') ? todo_media_url_to_runtime_absolute($url) : $url;
            }
        }

        return '';
    }
}

/**
 * 获取符合当前（或指定）语言环境的站点 Favicon 图标 URL
 *
 * @param string|null $lang 指定语言代码（默认读取当前语言）
 * @return string Favicon 图标 URL，若未设置则返回空字符串
 */
if (!function_exists('todo_get_site_favicon_url')) {
    function todo_get_site_favicon_url($lang = null)
    {
        $raw_favicon = function_exists('todo_get_option_ml') ? todo_get_option_ml('site_favicon', (function_exists('todo_get_option') ? todo_get_option('site_favicon', '') : '')) : (function_exists('todo_get_option') ? todo_get_option('site_favicon', '') : '');
        if (empty($raw_favicon) && function_exists('get_option')) {
            $raw_favicon = get_option('site_favicon', '');
        }

        if (empty($raw_favicon)) {
            return '';
        }

        // 1. 优先精准寻找带有当前语言标识（如 en）的图片
        $matched_url = todo_match_featured_image_by_lang($raw_favicon, $lang);
        if ($matched_url !== false && $matched_url !== '') {
            return function_exists('todo_media_url_to_runtime_absolute') ? todo_media_url_to_runtime_absolute($matched_url) : $matched_url;
        }

        // 2. 根据多语言规则过滤图片列表
        $filtered = todo_filter_featured_images_by_lang($raw_favicon, $lang);
        if (!empty($filtered)) {
            $first = $filtered[0];
            $url = is_array($first) ? (isset($first['url']) ? trim((string) $first['url']) : '') : (is_string($first) ? trim($first) : '');
            if ($url !== '') {
                return function_exists('todo_media_url_to_runtime_absolute') ? todo_media_url_to_runtime_absolute($url) : $url;
            }
        }

        // 3. 兜底情况：若 $raw_favicon 为字符串且为单图标路径
        if (is_string($raw_favicon)) {
            $url = trim($raw_favicon);
            if ($url !== '' && strpos($url, '[{') !== 0 && strpos($url, '{') !== 0) {
                return function_exists('todo_media_url_to_runtime_absolute') ? todo_media_url_to_runtime_absolute($url) : $url;
            }
        }

        return '';
    }
}

/**
 * 统一处理前端图片上传（头像、评价晒图、售后退款凭证等）
 *
 * 核心安全与控制能力：
 * 1. 统一存储位置：标准落盘至 content/uploads/YYYY/MM/DD/，通过 todo_upload_dir(true) 感知多站点子目录并保证规范；
 * 2. 后端统一控制：严格受 enable_upload 开关以及 TodoUploadConfig::get_max_upload_size() 限制；
 * 3. 严格格式白名单：仅允许 jpg, jpeg, png, gif, webp，绝不允许 svg/php/phtml/html/phar 等高危格式；
 * 4. 深度图片检测：利用 getimagesizefromstring 检验真实图片尺寸与 IMAGETYPE 常量，拦截伪装成图片的木马；
 * 5. WebShell 签名扫描：强力检测文件流中的 <?php, <?=, <script, <%, eval, system 等脚本特征，粉碎图片马攻击；
 * 6. 文件名完全重构：强制基于随机安全哈希重命名，杜绝文件名截断、跨目录写入 (path traversal) 与覆盖攻击；
 * 7. 媒体库追溯：自动登记至媒体库 todo_media，便于溯源与集中管理。
 *
 * @param array|string $source $_FILES['field'] 数组或 base64 DataURL 或现有相对图片路径
 * @param string $prefix 文件名前缀，如 'avatar', 'review', 'refund'
 * @param int $user_id 上传用户ID
 * @param string $description 描述说明
 * @return array ['success' => bool, 'url' => string, 'abs_path' => string, 'error' => string, 'size' => int, 'media_id' => int]
 */
if (!function_exists('todo_process_frontend_image_upload')) {
    function todo_process_frontend_image_upload($source, $prefix = 'upload', $user_id = 0, $description = '')
    {
        // 1. 检查后端上传开关
        if (function_exists('get_option') && !get_option('enable_upload', true)) {
            return ['success' => false, 'error' => __('文件上传功能已被管理员禁用')];
        }

        // 2. 检查后端最大上传大小控制
        if (!class_exists('TodoUploadConfig') && defined('ABSPATH') && file_exists(ABSPATH . 'core/classes/class-upload-config.php')) {
            require_once ABSPATH . 'core/classes/class-upload-config.php';
        }

        $max_size = 10485760; // 默认 10MB
        if (class_exists('TodoUploadConfig')) {
            $max_size = (int) TodoUploadConfig::get_max_upload_size();
        } elseif (function_exists('get_option')) {
            $mb = get_option('max_upload_size', null);
            if ($mb !== null && is_numeric($mb) && (int) $mb > 0) {
                $max_size = (int) $mb * 1024 * 1024;
            }
        }

        if ($max_size <= 0) {
            return ['success' => false, 'error' => __('文件上传功能已被管理员禁用')];
        }

        $size_limit_text = function_exists('size_format') ? size_format($max_size) : ($max_size / 1048576) . 'MB';

        // 3. 解析输入源
        $binary_data = null;

        if (empty($source)) {
            return ['success' => false, 'error' => __('未提供有效的图片上传数据')];
        }

        if (is_array($source)) {
            // $_FILES 结构
            if (!isset($source['error']) || $source['error'] !== UPLOAD_ERR_OK) {
                $err_code = $source['error'] ?? UPLOAD_ERR_NO_FILE;
                $err_msgs = [
                    UPLOAD_ERR_INI_SIZE   => __('上传的文件超过了服务器配置限制'),
                    UPLOAD_ERR_FORM_SIZE  => __('上传的文件超过了表单限制'),
                    UPLOAD_ERR_PARTIAL    => __('文件只有部分被上传'),
                    UPLOAD_ERR_NO_FILE    => __('没有文件被上传'),
                    UPLOAD_ERR_NO_TMP_DIR => __('缺少临时文件夹'),
                    UPLOAD_ERR_CANT_WRITE => __('文件写入失败'),
                    UPLOAD_ERR_EXTENSION  => __('文件上传被扩展程序阻止')
                ];
                return ['success' => false, 'error' => $err_msgs[$err_code] ?? __('文件上传出错')];
            }

            if (empty($source['tmp_name']) || !file_exists($source['tmp_name']) || !is_uploaded_file($source['tmp_name'])) {
                return ['success' => false, 'error' => __('非法上传源文件')];
            }

            $file_size = (int) ($source['size'] ?? filesize($source['tmp_name']));
            if ($file_size <= 0) {
                return ['success' => false, 'error' => __('上传的图片文件为空')];
            }
            if ($file_size > $max_size) {
                return ['success' => false, 'error' => sprintf(__('上传图片大小超过系统限制（最大允许 %s）'), $size_limit_text)];
            }

            $binary_data = file_get_contents($source['tmp_name']);

        } elseif (is_string($source)) {
            $source = trim($source);

            // 如果是已有的标准上传路径 (/content/uploads/...)，校验安全性并直接返回
            if (strpos($source, 'data:image/') !== 0) {
                $rel_path = function_exists('todo_media_url_to_relative') ? todo_media_url_to_relative($source) : $source;
                if (strpos($rel_path, '/content/uploads/') === 0 && strpos($rel_path, '..') === false) {
                    $ext = strtolower(pathinfo($rel_path, PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                        $basedir = defined('ABSPATH') ? ABSPATH . 'content/uploads' : (defined('TODO_ROOT') ? TODO_ROOT . '/content/uploads' : '');
                        $full_path = defined('ABSPATH') ? ABSPATH . ltrim($rel_path, '/') : '';
                        if (!empty($basedir) && !empty($full_path) && file_exists($full_path)) {
                            $real_basedir = realpath($basedir);
                            $real_fullpath = realpath($full_path);
                            if ($real_basedir && $real_fullpath && strpos($real_fullpath, $real_basedir) === 0) {
                                return [
                                    'success' => true,
                                    'url' => $rel_path,
                                    'abs_path' => $real_fullpath,
                                    'filename' => basename($rel_path),
                                    'size' => filesize($real_fullpath),
                                    'media_id' => 0
                                ];
                            }
                        }
                    }
                }
                return ['success' => false, 'error' => __('无效的图片路径或数据')];
            }

            // Base64 Data URL 方案
            if (!preg_match('/^data:image\/([a-zA-Z0-9_\-+]+);base64,(.+)$/s', $source, $matches)) {
                return ['success' => false, 'error' => __('无效的 Base64 图片数据格式')];
            }

            $raw_base64 = $matches[2];
            // 粗略防止超大 base64 payload 导致内存耗尽 (Base64体积约为原二进制的4/3)
            if (strlen($raw_base64) > ($max_size * 1.5 + 4096)) {
                return ['success' => false, 'error' => sprintf(__('上传图片大小超过系统限制（最大允许 %s）'), $size_limit_text)];
            }

            $binary_data = base64_decode($raw_base64, true);
            if ($binary_data === false || strlen($binary_data) === 0) {
                return ['success' => false, 'error' => __('Base64 图片数据解码失败')];
            }

            if (strlen($binary_data) > $max_size) {
                return ['success' => false, 'error' => sprintf(__('上传图片大小超过系统限制（最大允许 %s）'), $size_limit_text)];
            }
        } else {
            return ['success' => false, 'error' => __('不支持的图片数据格式')];
        }

        if (empty($binary_data)) {
            return ['success' => false, 'error' => __('未能读取到有效的图片数据')];
        }

        // 4. 深度图片检验：getimagesizefromstring 检验尺寸与真实类型
        $image_info = @getimagesizefromstring($binary_data);
        if ($image_info === false || empty($image_info[0]) || empty($image_info[1])) {
            return ['success' => false, 'error' => __('文件不是合法的图片或图片数据已损坏')];
        }

        $width = (int) $image_info[0];
        $height = (int) $image_info[1];
        if ($width < 1 || $height < 1 || $width > 10000 || $height > 10000) {
            return ['success' => false, 'error' => __('图片分辨率超出允许的安全范围 (最大 10000x10000)')];
        }

        // 严格格式白名单映射：仅支持 JPG, PNG, GIF, WebP
        $allowed_image_types = [
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_GIF  => 'gif',
        ];
        if (defined('IMAGETYPE_WEBP')) {
            $allowed_image_types[IMAGETYPE_WEBP] = 'webp';
        }

        $img_type = $image_info[2] ?? 0;
        if (!isset($allowed_image_types[$img_type])) {
            return ['success' => false, 'error' => __('不支持的图片类型，仅支持 JPG、PNG、GIF、WebP 格式')];
        }
        $final_ext = $allowed_image_types[$img_type];

        // 5. WebShell 与恶意代码检测（防止图片马及代码执行漏洞）
        $dangerous_patterns = [
            '<\?php',
            '<\?=',
            '<\?\s*(?:eval|assert|system|exec|passthru|shell_exec|popen|proc_open|include|require|phpinfo|\$[a-zA-Z_])',
            '<script[\s>]',
            '__halt_compiler\s*\(',
            'base64_decode\s*\(',
            'eval\s*\(',
            'assert\s*\(',
            'system\s*\(',
            'exec\s*\(',
            'passthru\s*\(',
            'shell_exec\s*\(',
            'popen\s*\(',
            'proc_open\s*\('
        ];
        $regex = '/' . implode('|', $dangerous_patterns) . '/i';
        if (preg_match($regex, $binary_data)) {
            return ['success' => false, 'error' => __('检测到图片包含非法脚本代码或潜在安全隐患，已被系统拒绝')];
        }

        // 6. 统一存储位置：使用 todo_upload_dir(true)
        $upload_dir = function_exists('todo_upload_dir') ? todo_upload_dir(true) : null;
        if (!$upload_dir || empty($upload_dir['path']) || empty($upload_dir['basedir'])) {
            return ['success' => false, 'error' => __('获取系统上传目录失败')];
        }

        $target_dir = $upload_dir['path'];
        $basedir = $upload_dir['basedir'];

        if (!is_dir($target_dir)) {
            @mkdir($target_dir, 0755, true);
        }

        // realpath 校验防止目录穿越
        $real_target = realpath($target_dir);
        $real_base = realpath($basedir);
        if ($real_target === false || $real_base === false || strpos($real_target, $real_base) !== 0) {
            return ['success' => false, 'error' => __('上传存储路径异常')];
        }

        // 7. 生成不可预测的安全文件名，强制使用校验后的扩展名
        $clean_prefix = preg_replace('/[^a-zA-Z0-9_-]/', '', $prefix) ?: 'upload';
        try {
            $random_token = bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            $random_token = substr(md5(uniqid(mt_rand(), true)), 0, 16);
        }
        $filename = sprintf('%s_%s_%s.%s', $clean_prefix, date('YmdHis'), $random_token, $final_ext);
        $abs_path = rtrim($real_target, '/') . '/' . $filename;

        // 写入物理磁盘
        if (file_put_contents($abs_path, $binary_data) === false) {
            return ['success' => false, 'error' => __('图片写入存储失败，请检查磁盘权限')];
        }
        @chmod($abs_path, 0644);

        // 8. 规范化相对 URL (例如 /content/uploads/YYYY/MM/DD/...)
        $rel_url = function_exists('todo_media_url_to_relative') 
            ? todo_media_url_to_relative($upload_dir['url'] . '/' . $filename)
            : ('/content/uploads/' . ltrim(todo_relative_upload_path($abs_path), '/'));

        // 9. 登记至媒体库 (todo_media) 追踪归档
        global $tododb;
        $media_id = 0;
        if ($tododb) {
            try {
                $table = defined('TABLE_PREFIX') ? TABLE_PREFIX . 'media' : (isset($tododb->prefix) ? $tododb->prefix . 'media' : 'todo_media');
                $mime_type = $image_info['mime'] ?? ('image/' . ($final_ext === 'jpg' ? 'jpeg' : $final_ext));
                $tododb->insert($table, [
                    'upload_user'       => (int) $user_id,
                    'media_title'       => $filename,
                    'media_filename'    => $filename,
                    'media_path'        => ltrim($rel_url, '/'),
                    'media_url'         => $rel_url,
                    'media_size'        => strlen($binary_data),
                    'media_type'        => $mime_type,
                    'media_description' => $description ?: __('前台图片上传'),
                    'media_alt'         => pathinfo($filename, PATHINFO_FILENAME),
                    'media_status'      => 'active',
                    'upload_time'       => current_time('mysql'),
                    'created_at'        => current_time('mysql'),
                    'updated_at'        => current_time('mysql'),
                ]);
                $media_id = (int) $tododb->insert_id();
            } catch (\Throwable $e) {
                if (function_exists('todo_log')) {
                    todo_log('Media record insert error: ' . $e->getMessage(), 'upload');
                }
            }
        }

        return [
            'success'   => true,
            'url'       => $rel_url,
            'abs_path'  => $abs_path,
            'filename'  => $filename,
            'size'      => strlen($binary_data),
            'media_id'  => $media_id,
            'mime'      => $image_info['mime'] ?? '',
            'width'     => $width,
            'height'    => $height
        ];
    }
}
