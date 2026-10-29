<?php
/**
 * TodoCMS 媒体处理函数
 * 中优先级缺失函数修复
 *
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 媒体处理函数
 * @param string $action
 * @param array $params
 * @return mixed
 */
function media($action = '', $params = []) {
    switch ($action) {
        case 'upload':
            return todo_handle_media_upload($params);
        case 'delete':
            return todo_handle_media_delete($params);
        case 'get':
            return todo_get_media_info($params);
        default:
            return false;
    }
}

/**
 * 处理媒体上传
 * @param array $params
 * @return array
 */
function todo_handle_media_upload($params) {
    if (!isset($_FILES['file'])) {
        return ['error' => 'No file uploaded'];
    }
    
    $file = $_FILES['file'];
    $upload_dir = ABSPATH . 'content/uploads/';

    if (function_exists('get_option') && !get_option('enable_upload', true)) {
        return ['error' => 'Upload disabled'];
    }

    $max_size = null;
    if (class_exists('TodoUploadConfig')) {
        $max_size = (int) TodoUploadConfig::get_max_upload_size();
    } elseif (function_exists('get_option')) {
        $mb = get_option('max_upload_size', null);
        if ($mb !== null && is_numeric($mb) && (int) $mb > 0) {
            $max_size = (int) $mb * 1024 * 1024;
        }
    }
    if ($max_size === null) {
        $max_size = min(convert_to_bytes(ini_get('upload_max_filesize')), convert_to_bytes(ini_get('post_max_size')));
    }
    if ((int) $max_size <= 0) {
        return ['error' => 'Upload disabled'];
    }

    // 允许的扩展名与MIME
    $allowed_exts = ['jpg','jpeg','png','gif','webp','svg'];
    $allowed_mimes = [
        'image/jpeg','image/png','image/gif','image/webp','image/svg+xml'
    ];
    // 基本错误检查
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'Upload error'];
    }
    if (isset($file['size']) && (int) $file['size'] > $max_size) {
        return ['error' => 'File too large'];
    }
    // 扩展名校验
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts, true)) {
        return ['error' => 'Invalid file type'];
    }
    // MIME校验（最佳努力）
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if ($mime && !in_array($mime, $allowed_mimes, true)) {
                return ['error' => 'Invalid MIME type'];
            }
        }
    }
    
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    // realpath前缀校验，防止路径穿越
    $uploads_real = realpath($upload_dir);
    if ($uploads_real === false) {
        return ['error' => 'Upload directory invalid'];
    }
    
    $filename = time() . '_' . sanitize_file_name($file['name']);
    $filepath = $upload_dir . $filename;
    
    // 再次保证写入路径在uploads目录下
    $dest_real_parent = realpath(dirname($filepath));
    if ($dest_real_parent === false || strpos($dest_real_parent, $uploads_real) !== 0) {
        return ['error' => 'Invalid destination path'];
    }
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        // 设置权限
        @chmod($filepath, 0644);
        return [
            'success' => true,
            'filename' => $filename,
            'url' => get_site_url() . '/content/uploads/' . $filename
        ];
    }
    
    return ['error' => 'Upload failed'];
}

/**
 * 获取最大上传大小
 * @return string
 */
function todo_max_upload_size() {
    $max_upload = ini_get('upload_max_filesize');
    $max_post = ini_get('post_max_size');
    $memory_limit = ini_get('memory_limit');
    
    $sizes = [$max_upload, $max_post, $memory_limit];
    $min_size = $max_upload;
    
    foreach ($sizes as $size) {
        if ($size && convert_to_bytes($size) < convert_to_bytes($min_size)) {
            $min_size = $size;
        }
    }
    
    return $min_size;
}

/**
 * 转换大小为字节
 * @param string $size
 * @return int
 */
function convert_to_bytes($size) {
    $size = trim($size);
    $last = strtolower($size[strlen($size) - 1]);
    $size = (int) $size;
    
    switch ($last) {
        case 'g':
            $size *= 1024;
        case 'm':
            $size *= 1024;
        case 'k':
            $size *= 1024;
    }
    
    return $size;
}

/**
 * 上传文件位
 * @param string $bits
 * @param string $filename
 * @return array
 */
function todo_upload_bits($bits, $filename) {
    $upload_dir = ABSPATH . 'content/uploads/';

    if (function_exists('get_option') && !get_option('enable_upload', true)) {
        return false;
    }
    $max_size = null;
    if (class_exists('TodoUploadConfig')) {
        $max_size = (int) TodoUploadConfig::get_max_upload_size();
    } elseif (function_exists('get_option')) {
        $mb = get_option('max_upload_size', null);
        if ($mb !== null && is_numeric($mb) && (int) $mb > 0) {
            $max_size = (int) $mb * 1024 * 1024;
        }
    }
    if ($max_size === null) {
        $max_size = min(convert_to_bytes(ini_get('upload_max_filesize')), convert_to_bytes(ini_get('post_max_size')));
    }
    if ((int) $max_size <= 0) {
        return false;
    }
    if (strlen((string) $bits) > $max_size) {
        return false;
    }

    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    // realpath 前缀校验
    $uploads_real = realpath($upload_dir);
    if ($uploads_real === false) {
        return false;
    }

    $filename = sanitize_file_name($filename);
    // 限制允许的扩展名（防止可执行脚本写入）
    $allowed_exts = ['jpg','jpeg','png','gif','webp','svg','txt','json','csv','pdf'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === '') { $ext = 'txt'; $filename .= '.txt'; }
    if (!in_array($ext, $allowed_exts, true)) {
        return false;
    }

    $filepath = $upload_dir . $filename;
    // 再次保证写入路径在 uploads 目录下
    $dest_real_parent = realpath(dirname($filepath));
    if ($dest_real_parent === false || strpos($dest_real_parent, $uploads_real) !== 0) {
        return false;
    }

    if (file_put_contents($filepath, $bits, LOCK_EX) !== false) {
        @chmod($filepath, 0644);
        // 检测 MIME 类型
        $mime = null;
        if (function_exists('finfo_open')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            if ($f) {
                $mime = finfo_file($f, $filepath);
                finfo_close($f);
            }
        }
        if (!$mime && function_exists('mime_content_type')) {
            $mime = @mime_content_type($filepath) ?: '';
        }
        return [
            'file' => $filepath,
            'url' => get_site_url() . '/content/uploads/' . $filename,
            'type' => $mime
        ];
    }

    return false;
}

/**
 * 获取图片中间尺寸
 * @param int $attachment_id
 * @param string $size
 * @return array|false
 */
function image_get_intermediate_size($attachment_id, $size = 'medium') {
    $metadata = get_attachment_metadata($attachment_id);
    
    if (!$metadata || !isset($metadata['sizes'][$size])) {
        return false;
    }
    
    $size_data = $metadata['sizes'][$size];
    $upload_dir = get_upload_dir();
    
    return [
        'file' => $size_data['file'],
        'width' => $size_data['width'],
        'height' => $size_data['height'],
        'path' => $upload_dir['path'] . '/' . $size_data['file'],
        'url' => $upload_dir['url'] . '/' . $size_data['file']
    ];
}

/**
 * TodoUploadLogger 类
 */
function TodoUploadLogger() {
    return new class {
        public function log($message, $level = 'info') {
            $log_file = ABSPATH . 'logs/upload.log';
            $log_dir = dirname($log_file);
            
            if (!is_dir($log_dir)) {
                mkdir($log_dir, 0755, true);
            }
            
            $timestamp = date('Y-m-d H:i:s');
            $log_entry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
            
            file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        }
        
        public function info($message) {
            $this->log($message, 'info');
        }
        
        public function error($message) {
            $this->log($message, 'error');
        }
        
        public function warning($message) {
            $this->log($message, 'warning');
        }
    };
}

/**
 * TodoUploadConfig 类
 */
function TodoUploadConfig() {
    return new class {
        private $config = [
            'max_file_size' => '10M',
            'allowed_types' => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'],
            'upload_path' => 'content/uploads/'
        ];
        
        public function get($key = null) {
            if ($key === null) {
                return $this->config;
            }
            
            return isset($this->config[$key]) ? $this->config[$key] : null;
        }
        
        public function set($key, $value) {
            $this->config[$key] = $value;
        }
    };
}

/**
 * 获取附件元数据
 *
 * @param int $attachment_id 附件ID
 * @return array|false
 */
if (!function_exists('get_attachment_metadata')) {
    function get_attachment_metadata($attachment_id) {
        global $tododb;
        
        if (!$attachment_id) {
            return false;
        }
        
        $sql = "SELECT * FROM " . $tododb->prefix . "media WHERE id = ?";
        $media = $tododb->get_row($sql, array($attachment_id));
        
        if (!$media) {
            return false;
        }
        
        $metadata = array(
            'width' => $media->width,
            'height' => $media->height,
            'file' => $media->file_path,
            'filesize' => $media->file_size,
            'mime_type' => $media->mime_type,
            'sizes' => array()
        );
        
        // 如果有缩略图信息，添加到sizes数组
        if ($media->thumbnail_path) {
            $metadata['sizes']['thumbnail'] = array(
                'file' => basename($media->thumbnail_path),
                'width' => 150,
                'height' => 150,
                'mime_type' => $media->mime_type
            );
        }
        
        return $metadata;
    }
}

/**
 * 获取上传目录信息
 *
 * @return array
 */
if (!function_exists('get_upload_dir')) {
    function get_upload_dir() {
        $upload_path = TODO_CONTENT_DIR . '/uploads';
        $upload_url = TODO_CONTENT_URL . '/uploads';
        
        // 按年月创建子目录
        $time = current_time('mysql');
        $y = substr($time, 0, 4);
        $m = substr($time, 5, 2);
        
        $subdir = "/$y/$m";
        
        return array(
            'path' => $upload_path,
            'url' => $upload_url,
            'subdir' => $subdir,
            'basedir' => $upload_path,
            'baseurl' => $upload_url,
            'error' => false
        );
    }
}
