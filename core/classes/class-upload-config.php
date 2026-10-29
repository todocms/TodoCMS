<?php
/**
 * 上传配置管理器
 * TodoCMS Upload Configuration Manager
 *
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 上传配置管理器类
 */
class TodoUploadConfig {
    
    /**
     * 默认配置
     */
    private static $default_config = [
        'max_file_size' => 10485760, // 10MB
        'allowed_types' => [
            // 图片类型
            'image/jpeg',
            'image/png', 
            'image/gif',
            'image/webp',
            'image/svg+xml',
            'image/x-icon',
            'image/vnd.microsoft.icon',
            // 文档类型
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain',
            'text/csv',
            // 压缩包类型
            'application/zip',
            'application/x-rar-compressed',
            'application/x-tar',
            'application/gzip',
            'application/x-7z-compressed',
            // 音频类型
            'audio/mp3',          // legacy/alias
            'audio/mpeg',         // standard for .mp3
            'audio/wav',
            'audio/ogg',
            'audio/m4a',          // legacy/alias
            'audio/mp4',          // standard for .m4a
            'audio/aac',
            // 视频类型
            'video/mp4',
            'video/webm',
            'video/ogg',
            'video/avi',          // legacy/alias
            'video/x-msvideo',    // standard for .avi
            'video/quicktime',    // standard for .mov
            'video/mov',          // legacy/alias
            'video/x-ms-wmv',     // standard for .wmv
            'video/wmv'
        ],
        'upload_path' => 'content/uploads',
        'create_thumbnails' => true,
        'thumbnail_sizes' => [
            'small' => [150, 150],
            'medium' => [300, 300],
            'large' => [800, 600]
        ],
        'enable_logging' => true,
        'log_retention_days' => 30,
        'security_scan' => true,
        'virus_scan' => false,
        'watermark_enabled' => false,
        'watermark_image' => '',
        'watermark_position' => 'bottom-right',
        'watermark_opacity' => 50
    ];
    
    /**
     * 获取上传配置
     */
    public static function get_config($key = null) {
        $config = get_option('todo_upload_config', self::$default_config);
        $config = array_merge(self::$default_config, $config);
        
        if ($key !== null) {
            return isset($config[$key]) ? $config[$key] : null;
        }
        
        return $config;
    }
    
    /**
     * 更新上传配置
     */
    public static function update_config($key, $value = null) {
        $config = self::get_config();
        
        if (is_array($key)) {
            // 批量更新
            foreach ($key as $k => $v) {
                $config[$k] = $v;
            }
        } else {
            // 单个更新
            $config[$key] = $value;
        }
        
        return update_option('todo_upload_config', $config);
    }
    
    /**
     * 重置为默认配置
     */
    public static function reset_config() {
        return update_option('todo_upload_config', self::$default_config);
    }
    
    /**
     * 获取最大上传大小
     */
    public static function get_max_upload_size() {
        if (function_exists('get_option') && !get_option('enable_upload', true)) {
            return 0;
        }

        $config_size = self::get_config('max_file_size');
        if (function_exists('get_option')) {
            $mb = get_option('max_upload_size', null);
            if ($mb !== null && is_numeric($mb)) {
                $mb = (int) $mb;
                if ($mb <= 0) {
                    return 0;
                }
                $config_size = $mb * 1024 * 1024;
            }
        }

        $php_size = self::parse_size(ini_get('upload_max_filesize'));
        $post_size = self::parse_size(ini_get('post_max_size'));
        
        return min($config_size, $php_size, $post_size);
    }
    
    /**
     * 获取允许的文件类型
     */
    /**
     * 获取高危/敏感文件扩展名列表
     * 管理员在特殊需求下可主观勾选开启，并在1小时（或其他设定时限）后自动关闭
     */
    public static function get_sensitive_extensions() {
        return ['sql', 'html', 'htm', 'css', 'js', 'php'];
    }

    /**
     * 检查并自动关闭已超时的敏感格式上传权限
     */
    public static function check_sensitive_expiration() {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            return;
        }

        $sensitive_expires = (int) get_option('sensitive_upload_expires', 0);
        if ($sensitive_expires > 0 && time() > $sensitive_expires) {
            $sensitive_exts = self::get_sensitive_extensions();
            $allowed_exts_setting = get_option('allowed_file_types', '');
            $current_allowed = array_filter(array_map('trim', explode(',', (string)$allowed_exts_setting)));
            $new_allowed = array_diff($current_allowed, $sensitive_exts);

            if (count($current_allowed) !== count($new_allowed)) {
                $allowed_exts_setting = implode(',', $new_allowed);
                update_option('allowed_file_types', $allowed_exts_setting);

                // 同步刷新 TodoUploadConfig 中的 allowed_types 配置
                $flat = function_exists('todo_supported_mime_types_flat') ? todo_supported_mime_types_flat() : [];
                $mimes = [];
                foreach ($new_allowed as $ext) {
                    if (isset($flat[$ext])) {
                        $mimes[] = $flat[$ext];
                    }
                }
                if (!empty($mimes)) {
                    self::update_config('allowed_types', array_values(array_unique($mimes)));
                }
            }

            update_option('sensitive_upload_expires', 0);
        }
    }

    /**
     * 系统默认允许的文件扩展名（单一数据源，集中维护）
     */
    const DEFAULT_ALLOWED_EXTS = 'jpg,jpeg,png,gif,webp,svg,bmp,ico,mp4,avi,mov,wmv,flv,webm,mkv,mp3,wav,ogg,aac,flac,m4a,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,rtf,zip,rar,7z,tar,gz,csv,xml,json';

    public static function get_default_allowed_extensions_csv() {
        return self::DEFAULT_ALLOWED_EXTS;
    }

    /**
     * 获取后台当前允许上传的所有扩展名列表（全部受后端管理，已剔除超时敏感类型）
     */
    public static function get_allowed_extensions() {
        self::check_sensitive_expiration();
        $allowed_exts_setting = get_option('allowed_file_types');
        if (empty($allowed_exts_setting) || !is_string($allowed_exts_setting)) {
            $allowed_exts_setting = self::get_default_allowed_extensions_csv();
        }
        return array_values(array_filter(array_map('strtolower', array_map('trim', explode(',', (string)$allowed_exts_setting)))));
    }

    /**
     * 获取允许的文件类型（MIME列表）
     */
    public static function get_allowed_types() {
        self::check_sensitive_expiration();

        // 优先根据后台当前启用的扩展名映射真实 MIME
        $exts = self::get_allowed_extensions();
        $flat = function_exists('todo_supported_mime_types_flat') ? todo_supported_mime_types_flat() : [];
        $mimes = [];
        foreach ($exts as $ext) {
            if (isset($flat[$ext])) {
                $mimes[] = $flat[$ext];
            }
        }
        if (!empty($mimes)) {
            return array_values(array_unique($mimes));
        }

        // 兜底合并
        $stored = self::get_config('allowed_types');
        $defaults = isset(self::$default_config['allowed_types']) ? self::$default_config['allowed_types'] : [];
        if (!is_array($stored)) { $stored = []; }
        return array_values(array_unique(array_merge($defaults, $stored)));
    }
    
    /**
     * 检查文件类型是否允许（全部受后端配置动态管理）
     */
    public static function is_type_allowed($mime_type, $extension = '') {
        // 先检查高危/敏感文件类型是否超时，超时则自动关闭
        self::check_sensitive_expiration();

        $allowed_exts_setting = get_option('allowed_file_types');
        if (empty($allowed_exts_setting) || !is_string($allowed_exts_setting)) {
            $allowed_exts_setting = self::get_default_allowed_extensions_csv();
        }
        
        if (class_exists('Todo_Core_Kernel')) {
            $result = Todo_Core_Kernel::validate_upload_type($mime_type, $extension, $allowed_exts_setting);
            if ($result !== null) {
                return (bool)$result;
            }
        }

        $exts = array_filter(array_map('strtolower', array_map('trim', explode(',', $allowed_exts_setting ?? ''))));
        $flat = function_exists('todo_supported_mime_types_flat') ? todo_supported_mime_types_flat() : [];
        $allowed_mimes = [];
        foreach ($exts as $ext) {
            if (isset($flat[$ext])) {
                $allowed_mimes[] = $flat[$ext];
            }
        }
        if (empty($allowed_mimes)) {
            $allowed_mimes = self::get_allowed_types();
        }

        $normalize = function($m) {
            $m = strtolower(trim((string)$m));
            if ($m === '') return $m;
            $aliases = [
                'image/jpg' => 'image/jpeg',
                'image/svg' => 'image/svg+xml',
                'image/vnd.microsoft.icon' => 'image/x-icon',
                'image/x-ico' => 'image/x-icon',
                'image/icon' => 'image/x-icon',
                'audio/mp3' => 'audio/mpeg',
                'audio/x-m4a' => 'audio/mp4',
                'video/avi' => 'video/x-msvideo',
                'video/wmv' => 'video/x-ms-wmv',
                'video/mov' => 'video/quicktime',
            ];
            return $aliases[$m] ?? $m;
        };

        $mime_type_norm = $normalize($mime_type);
        $normalized_allowed = array_map($normalize, $allowed_mimes);

        if (in_array($mime_type_norm, $normalized_allowed, true)) {
            return true;
        }

        if ($mime_type_norm === 'application/zip' && !empty($extension)) {
            $extension = strtolower(ltrim($extension, '.'));
            $office_exts = ['xlsx', 'docx', 'pptx'];
            if (in_array($extension, $office_exts) && in_array($extension, $exts)) {
                return true;
            }
        }

        foreach ($normalized_allowed as $am) {
            $base = preg_replace('/\+.*/', '', $am);
            if ($base && strpos($mime_type_norm, $base) === 0) {
                return true;
            }
        }

        return false;
    }
    
    /**
     * 获取上传路径
     */
    public static function get_upload_path() {
        $upload_path = self::get_config('upload_path');
        $base_path = ABSPATH . 'content/uploads/';
        
        // 按年月创建目录
        $date_path = date('Y/m');
        $full_path = $base_path . $upload_path . '/' . $date_path . '/';
        
        // 确保目录存在
        if (!file_exists($full_path)) {
            todo_mkdir_p($full_path);
        }
        
        return $full_path;
    }
    
    /**
     * 获取上传URL
     */
    public static function get_upload_url() {
        $upload_path = self::get_config('upload_path');
        $base_url = home_url('/content/uploads/');
        
        $date_path = date('Y/m');
        return $base_url . $upload_path . '/' . $date_path . '/';
    }
    
    /**
     * 获取缩略图配置
     */
    public static function get_thumbnail_config() {
        return [
            'enabled' => self::get_config('create_thumbnails'),
            'sizes' => self::get_config('thumbnail_sizes')
        ];
    }
    
    /**
     * 获取安全配置
     */
    public static function get_security_config() {
        return [
            'security_scan' => self::get_config('security_scan'),
            'virus_scan' => self::get_config('virus_scan')
        ];
    }
    
    /**
     * 获取水印配置
     */
    public static function get_watermark_config() {
        return [
            'enabled' => self::get_config('watermark_enabled'),
            'image' => self::get_config('watermark_image'),
            'position' => self::get_config('watermark_position'),
            'opacity' => self::get_config('watermark_opacity')
        ];
    }
    
    /**
     * 获取日志配置
     */
    public static function get_logging_config() {
        return [
            'enabled' => self::get_config('enable_logging'),
            'retention_days' => self::get_config('log_retention_days')
        ];
    }
    
    /**
     * 解析文件大小字符串
     */
    private static function parse_size($size) {
        $unit = preg_replace('/[^bkmgtpezy]/i', '', $size);
        $size = preg_replace('/[^0-9\.]/', '', $size);
        
        if ($unit) {
            return round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
        }
        
        return round($size);
    }
    
    /**
     * 格式化文件大小
     */
    public static function format_file_size($bytes) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
    
    /**
     * 验证文件
     */
    public static function validate_file($file) {
        $errors = [];
        
        // 检查系统文件和隐藏文件
        $filename = basename($file['name']);
        $system_files = ['.DS_Store', 'Thumbs.db', '.htaccess', '.gitignore', '.git', '.svn', '.idea'];
        
        if (in_array($filename, $system_files) || 
            strpos($filename, '.') === 0 || 
            substr($filename, -1) === '~' || 
            substr($filename, -4) === '.tmp' || 
            substr($filename, -5) === '.temp') {
            $errors[] = '不允许上传系统文件或临时文件：' . $filename;
        }
        
        // 检查文件大小
        if ($file['size'] > self::get_max_upload_size()) {
            $errors[] = '文件大小超过限制：' . self::format_file_size(self::get_max_upload_size());
        }
        
        // 检查文件类型
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!self::is_type_allowed($mime_type)) {
            $errors[] = '不支持的文件类型：' . $mime_type;
        }
        
        // 安全检查
        if (self::get_config('security_scan')) {
            $security_errors = self::security_scan($file);
            $errors = array_merge($errors, $security_errors);
        }
        
        return $errors;
    }
    
    /**
     * 安全扫描
     */
    private static function security_scan($file) {
        // 检查文件扩展名：完全受后台配置控制，不再硬编码黑名单
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed_exts = self::get_allowed_extensions();
        if (!in_array($extension, $allowed_exts, true)) {
            $errors[] = '未在后台允许上传的文件扩展名：' . $extension;
            return $errors;
        }

        // 如果管理员主观开启了脚本类格式（如 php），在有效期内允许正常上传
        if (in_array($extension, ['php', 'phtml', 'sql'], true)) {
            return $errors;
        }
        
        // 检查文件内容
        $file_content = file_get_contents($file['tmp_name'], false, null, 0, 1024);
        $dangerous_patterns = [
            '/<\?php/i',
            '/<script/i',
            '/eval\s*\(/i',
            '/exec\s*\(/i',
            '/system\s*\(/i',
            '/shell_exec\s*\(/i'
        ];
        
        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $file_content)) {
                // 如果是 HTML/JS/CSS 类型且在后台允许列表中，则放行前端合法特征（如 <script, regex.exec 等）
                $frontend_exts = ['html', 'htm', 'js', 'css'];
                if (in_array($extension, $frontend_exts)) {
                    $backend_only_patterns = ['php', 'system', 'shell_exec'];
                    $is_backend_only = false;
                    foreach ($backend_only_patterns as $bp) {
                        if (strpos($pattern, $bp) !== false) {
                            $is_backend_only = true;
                            break;
                        }
                    }
                    
                    if ($is_backend_only) {
                        $errors[] = '文件包含非法的后端执行代码';
                        break;
                    }
                    continue; 
                }
                $errors[] = '文件包含可疑内容';
                break;
            }
        }
        
        return $errors;
    }
    
    /**
     * 生成安全的文件名
     */
    public static function generate_safe_filename($filename) {
        // 获取文件扩展名
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        
        // 清理文件名（保留合法中文/Unicode字符）
        $basename = sanitize_file_name($basename);
        $basename = preg_replace('/[<>:"\/\\|?*\x00-\x1F]/u', '', $basename);
        
        // 如果文件名为空，生成随机名称
        if (empty($basename)) {
            $basename = 'file_' . uniqid();
        }
        
        // 添加时间戳避免重复
        $timestamp = time();
        $safe_filename = $basename . '_' . $timestamp;
        
        // 添加扩展名
        if (!empty($extension)) {
            $safe_filename .= '.' . $extension;
        }
        
        return $safe_filename;
    }
    
    /**
     * 获取文件类型分类
     */
    public static function get_file_category($mime_type) {
        $categories = [
            'image' => ['image/'],
            'video' => ['video/'],
            'audio' => ['audio/'],
            'document' => [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument',
                'text/'
            ],
            'archive' => [
                'application/zip',
                'application/x-rar',
                'application/x-tar',
                'application/gzip',
                'application/x-7z'
            ]
        ];
        
        foreach ($categories as $category => $patterns) {
            foreach ($patterns as $pattern) {
                if (strpos($mime_type, $pattern) === 0) {
                    return $category;
                }
            }
        }
        
        return 'other';
    }
    
    /**
     * 获取文件图标
     */
    public static function get_file_icon($mime_type) {
        $category = self::get_file_category($mime_type);
        
        $icons = [
            'image' => 'custom-image',
            'video' => 'custom-video',
            'audio' => 'custom-audio',
            'document' => 'custom-document',
            'archive' => 'custom-archive',
            'other' => 'custom-default'
        ];
        
        return $icons[$category] ?? $icons['other'];
    }
    
    /**
     * 导出配置
     */
    public static function is_potentially_dangerous_file($file) {
        // 核心委托：优先使用加密内核中的安全扫描逻辑
        // 注意：不能用 method_exists() 检测，因为内核通过 __callStatic 代理
        if (class_exists('Todo_Core_Kernel')) {
            $result = Todo_Core_Kernel::validate_upload_security($file);
            if ($result !== null) {
                return $result === true ? false : true; // 内核返回 true 表示安全，返回数组表示得到错误
            }
        }

        // 备用逻辑 (本地副本)
        $errors = self::security_scan($file);
        return !empty($errors);
    }
    
    public static function export_config() {
        $config = self::get_config();
        $export_data = [
            'version' => '1.0.0',
            'timestamp' => current_time('mysql'),
            'config' => $config
        ];
        
        return json_encode($export_data, JSON_PRETTY_PRINT);
    }
    
    /**
     * 导入配置
     */
    public static function import_config($json_data) {
        $data = json_decode($json_data, true);
        
        if (!$data || !isset($data['config'])) {
            return false;
        }
        
        // 验证配置数据
        $config = $data['config'];
        $valid_keys = array_keys(self::$default_config);
        
        foreach ($config as $key => $value) {
            if (!in_array($key, $valid_keys)) {
                unset($config[$key]);
            }
        }
        
        return update_option('todo_upload_config', $config);
    }
}

// 初始化配置
if (function_exists('add_action')) {
    add_action('init', function() {
        // 确保配置表存在
        if (!get_option('todo_upload_config')) {
            TodoUploadConfig::reset_config();
        }
    });
} else {
    // 钩子系统未加载时不做初始化，避免未定义函数错误
    // TodoUploadConfig::get_config() 会使用默认配置，待系统初始化完成后由钩子确保配置写入
}
?>
