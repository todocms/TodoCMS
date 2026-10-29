<?php
/**
 * TodoCMS 媒体管理器
 * 
 * 管理文件上传、图片处理、媒体库等功能
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    exit('Direct access not permitted');
}

class Todo_Media_Manager {
    
    /**
     * 数据库连接
     * @var CMS_DB
     */
    private static $tododb;
    
    /**
     * 媒体类型常量
     */
    const TYPE_IMAGE = 'image';
    const TYPE_VIDEO = 'video';
    const TYPE_AUDIO = 'audio';
    const TYPE_DOCUMENT = 'document';
    const TYPE_ARCHIVE = 'archive';
    const TYPE_OTHER = 'other';
    
    /**
     * 允许的文件类型（已移除硬编码，统一改用 todo_get_mime_category 与设置项）
     */
    // 注：类型判断请使用 self::get_file_type()（转由统一 MIME 分类函数实现）
    
    /**
     * 最大文件大小（字节）
     */
    private static $max_file_size = 10485760; // 10MB
    
    /**
     * 上传目录
     */
    private static $upload_dir = 'content/uploads';
    
    /**
     * 媒体缓存
     * @var array
     */
    private static $media_cache = array();
    
    /**
     * 确保数据库连接有效
     */
    private static function ensure_db() {
        if (self::$tododb === null) {
            global $tododb, $cms_db;
            if ($tododb) {
                self::$tododb = $tododb;
            } elseif ($cms_db) {
                self::$tododb = $cms_db;
            } else {
                self::$tododb = new CMS_DB();
            }
        }
    }
    
    /**
     * 初始化媒体管理器
     */
    public static function init() {
        self::ensure_db();
        
        // 确保基础上传目录存在
        $base_dir = TODO_ROOT . '/' . self::$upload_dir;
        if (!is_dir($base_dir)) {
            mkdir($base_dir, 0755, true);
        }
        
        // 创建.htaccess文件保护上传目录
        $htaccess_file = $base_dir . '/.htaccess';
        if (!file_exists($htaccess_file)) {
            $htaccess_content = "# TodoCMS Uploads Protection\n";
            $htaccess_content .= "Options -Indexes\n";
            $htaccess_content .= "Options -ExecCGI\n";
            $htaccess_content .= "<IfModule mod_php7.c>\nphp_flag engine off\n</IfModule>\n";
            $htaccess_content .= "<IfModule mod_php8.c>\nphp_flag engine off\n</IfModule>\n";
            $htaccess_content .= "<FilesMatch \"\\.(php|phps|phtml|cgi|pl|exe|sh)$\">\n";
            $htaccess_content .= "  Require all denied\n";
            $htaccess_content .= "</FilesMatch>\n";
            $htaccess_content .= "<FilesMatch \"^(\\.htaccess|\\.env|\\.ini|\\.conf)$\">\n";
            $htaccess_content .= "  Require all denied\n";
            $htaccess_content .= "</FilesMatch>\n";
            $htaccess_content .= "<IfModule !mod_authz_core.c>\n";
            $htaccess_content .= "  <Files *.php>\n";
            $htaccess_content .= "    Order Deny,Allow\n";
            $htaccess_content .= "    Deny from all\n";
            $htaccess_content .= "  </Files>\n";
            $htaccess_content .= "</IfModule>\n";
            file_put_contents($htaccess_file, $htaccess_content);
        }
    }
    
    /**
     * 上传文件
     * 
     * @param array $file $_FILES数组中的文件信息
     * @param array $options 上传选项
     * @return array|false 成功返回媒体信息，失败返回false
     */
    public static function upload_file($file, $options = array()) {
        // 默认选项
        $defaults = array(
            'user_id' => 0,
            'title' => '',
            'description' => '',
            'alt_text' => '',
            'category' => '',
            'resize_image' => true,
            'max_width' => 1920,
            'max_height' => 1080,
            'create_thumbnails' => true,
            'thumbnail_sizes' => array(
                'thumbnail' => array(150, 150),
                'medium' => array(300, 300),
                'large' => array(800, 600)
            )
        );
        
        $options = array_merge($defaults, $options);
        
        // 验证文件
        $validation = self::validate_file($file);
        if ($validation !== true) {
            return array('error' => $validation);
        }
        
        // 获取文件信息
        $file_info = self::get_file_info($file);
        $file_type = self::get_file_type($file_info['extension']);
        
        // 生成文件名和路径
        $filename = self::generate_filename($file_info['name'], $file_info['extension']);
        $upload_path = self::get_upload_path($file_type);
        $file_path = $upload_path . '/' . $filename;
        $full_path = TODO_ROOT . '/' . $file_path;
        
        // realpath 前缀校验，确保上传目录位于 /content/uploads 下
        $uploads_base = rtrim(TODO_ROOT, '/') . '/content/uploads';
        $base_real = realpath($uploads_base);
        $dest_parent_real = realpath(dirname($full_path));
        if ($base_real === false || $dest_parent_real === false || strpos($dest_parent_real, $base_real) !== 0) {
            return array('error' => '非法上传目录');
        }
        
        // 仅允许来自 HTTP 上传的文件
        if (!is_uploaded_file($file['tmp_name'])) {
            return array('error' => '非法上传源');
        }
        
        if (!move_uploaded_file($file['tmp_name'], $full_path)) {
            return array('error' => '文件上传失败');
        }
        @chmod($full_path, 0644);
        
        // 处理图片
        $thumbnails = array();
        if ($file_type === self::TYPE_IMAGE) {
            $thumbnails = array();
        }
        
        // 获取文件大小
        $file_size = filesize($full_path);
        
        // 云存储推送
        $provider_type = 'local';
        if (class_exists('Todo_Storage_Manager')) {
            $storage_mgr = Todo_Storage_Manager::get_instance();
            $provider_type = $storage_mgr->get_provider_type();
            
            if (!$storage_mgr->is_local()) {
                // 上传主文件
                $upload_res = $storage_mgr->upload($full_path, $file_path, $file['type']);
                if (function_exists('is_todo_error') && is_todo_error($upload_res)) {
                    // 如果核心文件上传失败，可以选择直接返回错误或只记录日志
                    if (class_exists('Todo_Logger')) {
                        Todo_Logger::error("云存储上传失败: " . $upload_res->get_error_message());
                    }
                }
                
            }
        }

        
        // 保存到数据库
        $media_data = array(
            'user_id' => $options['user_id'],
            'title' => !empty($options['title']) ? $options['title'] : $file_info['name'],
            'filename' => $filename,
            'original_name' => $file['name'],
            'file_path' => $file_path,
            'file_size' => $file_size,
            'mime_type' => $file['type'],
            'file_type' => $file_type,
            'extension' => $file_info['extension'],
            'description' => $options['description'],
            'alt_text' => $options['alt_text'],
            'category' => $options['category'],
            'thumbnails' => json_encode($thumbnails)
        );
        
        $media_id = self::save_media($media_data);
        
        if ($media_id) {
            if ($provider_type !== 'local') {
                self::save_media_meta($media_id, 'storage_provider', $provider_type);
            }
            
            $media_data['id'] = $media_id;
            $media_data['url'] = self::get_media_url($file_path);
            $media_data['thumbnails'] = $thumbnails;
            
            return $media_data;
        }
        
        // 如果保存失败，删除已上传的文件
        unlink($full_path);
        return array('error' => '保存媒体信息失败');
    }
    
    /**
     * 获取媒体信息
     * 
     * @param int $media_id 媒体ID
     * @return array|null
     */
    public static function get_media($media_id) {
        if (isset(self::$media_cache[$media_id])) {
            return self::$media_cache[$media_id];
        }
        
        $query = "SELECT * FROM " . DB_PREFIX . "media WHERE id = ?";
        $row = self::$tododb->get_row($query, [$media_id], 'ARRAY_A');
        
        if ($row) {
            // 获取媒体元数据
            $row['meta'] = self::get_media_meta($media_id);
            $row['thumbnails'] = array();
            
            // 获取提供商类型
            $provider = isset($row['meta']['storage_provider']) ? $row['meta']['storage_provider'] : null;
            
            // 生成URL
            $row['url'] = self::get_media_url($row['file_path'], $provider);
            
            self::$media_cache[$media_id] = $row;
            return $row;
        }
        
        return null;
    }
    
    /**
     * 获取媒体列表
     * 
     * @param array $args 查询参数
     * @return array
     */
    public static function get_media_list($args = array()) {
        // 默认参数
        $defaults = array(
            'user_id' => null,
            'file_type' => null,
            'category' => null,
            'keyword' => null,
            'date_from' => null,
            'date_to' => null,
            'order_by' => 'created_at',
            'order' => 'DESC',
            'limit' => 20,
            'offset' => 0,
            'site_id' => null // Phase 4
        );
        
        $args = array_merge($defaults, $args);

        // Phase 4: Site Filtering - Scoped by default
        if ($args['site_id'] === null && function_exists('todo_current_site_id')) {
            $args['site_id'] = todo_current_site_id();
        }
        
        $where_clauses = array();
        $params = array();
        $types = '';
        
        // 构建WHERE条件
        if ($args['user_id'] !== null) {
            $where_clauses[] = 'user_id = ?';
            $params[] = intval($args['user_id']);
            $types .= 'i';
        }

        if ($args['site_id'] !== null) {
            $where_clauses[] = '(site_id = ? OR site_id = 0)';
            $params[] = intval($args['site_id']);
            $types .= 'i';
        }
        
        if (!empty($args['file_type'])) {
            if (is_array($args['file_type'])) {
                $placeholders = str_repeat('?,', count($args['file_type']) - 1) . '?';
                $where_clauses[] = "file_type IN ({$placeholders})";
                $params = array_merge($params, $args['file_type']);
                $types .= str_repeat('s', count($args['file_type']));
            } else {
                $where_clauses[] = 'file_type = ?';
                $params[] = $args['file_type'];
                $types .= 's';
            }
        }
        
        if (!empty($args['category'])) {
            $where_clauses[] = 'category = ?';
            $params[] = $args['category'];
            $types .= 's';
        }
        
        if (!empty($args['keyword'])) {
            $where_clauses[] = '(title LIKE ? OR original_name LIKE ? OR description LIKE ?)';
            $keyword = '%' . $args['keyword'] . '%';
            $params[] = $keyword;
            $params[] = $keyword;
            $params[] = $keyword;
            $types .= 'sss';
        }
        
        if (!empty($args['date_from'])) {
            $where_clauses[] = 'created_at >= ?';
            $params[] = $args['date_from'];
            $types .= 's';
        }
        
        if (!empty($args['date_to'])) {
            $where_clauses[] = 'created_at <= ?';
            $params[] = $args['date_to'] . ' 23:59:59';
            $types .= 's';
        }
        
        $where_sql = empty($where_clauses) ? '' : 'WHERE ' . implode(' AND ', $where_clauses);
        
        // 构建ORDER BY
        $allowed_order_fields = array('id', 'title', 'file_size', 'created_at', 'file_type');
        $order_by = in_array($args['order_by'], $allowed_order_fields) ? $args['order_by'] : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        $order_sql = "ORDER BY {$order_by} {$order}";
        
        // 构建LIMIT
        $limit_sql = "LIMIT " . intval($args['offset']) . ", " . intval($args['limit']);
        
        $query = "SELECT * FROM " . DB_PREFIX . "media 
                  {$where_sql} {$order_sql} {$limit_sql}";
        
        $rows = !empty($params)
            ? self::$tododb->get_results($query, $params, 'ARRAY_A')
            : self::$tododb->get_results($query, [], 'ARRAY_A');
        
        $media_ids = array_column($rows, 'id');
        $meta_data = array();
        
        if (!empty($media_ids)) {
            $placeholders = str_repeat('?,', count($media_ids) - 1) . '?';
            $meta_query = "SELECT media_id, meta_key, meta_value FROM " . DB_PREFIX . "media_meta 
                           WHERE meta_key = 'storage_provider' AND media_id IN ({$placeholders})";
            $meta_rows = self::$tododb->get_results($meta_query, $media_ids, 'ARRAY_A');
            if ($meta_rows) {
                foreach ($meta_rows as $mr) {
                    $meta_data[$mr['media_id']] = $mr['meta_value'];
                }
            }
        }
        
        $media_list = array();
        foreach ($rows as $row) {
            $row['thumbnails'] = array();
            
            $provider = isset($meta_data[$row['id']]) ? trim($meta_data[$row['id']], '"') : null;
            // 生成URL
            $row['url'] = self::get_media_url($row['file_path'], $provider);
            
            $media_list[] = $row;
        }
        
        return $media_list;
    }
    
    /**
     * 获取媒体数量
     * 
     * @param array $args 查询参数
     * @return int
     */
    public static function get_media_count($args = array()) {
        $where_clauses = array();
        $params = array();
        $types = '';
        
        // 构建WHERE条件（复用get_media_list的逻辑）
        if (isset($args['user_id']) && $args['user_id'] !== null) {
            $where_clauses[] = 'user_id = ?';
            $params[] = intval($args['user_id']);
            $types .= 'i';
        }

        // Phase 4: Site Filtering - Scoped by default
        if (!isset($args['site_id']) || $args['site_id'] === null) {
            if (function_exists('todo_current_site_id')) {
                $args['site_id'] = todo_current_site_id();
            }
        }
        if ($args['site_id'] !== null) {
            $where_clauses[] = '(site_id = ? OR site_id = 0)';
            $params[] = intval($args['site_id']);
            $types .= 'i';
        }
        
        if (isset($args['file_type']) && !empty($args['file_type'])) {
            $where_clauses[] = 'file_type = ?';
            $params[] = $args['file_type'];
            $types .= 's';
        }
        
        if (isset($args['category']) && !empty($args['category'])) {
            $where_clauses[] = 'category = ?';
            $params[] = $args['category'];
            $types .= 's';
        }
        
        $where_sql = empty($where_clauses) ? '' : 'WHERE ' . implode(' AND ', $where_clauses);
        
        $query = "SELECT COUNT(*) as count FROM " . DB_PREFIX . "media {$where_sql}";
        
        $count = !empty($params)
            ? self::$tododb->get_var($query, $params)
            : self::$tododb->get_var($query, []);
        
        return $count !== null ? intval($count) : 0;
    }
    
    /**
     * 更新媒体信息
     * 
     * @param int $media_id 媒体ID
     * @param array $data 更新数据
     * @return bool
     */
    public static function update_media($media_id, $data) {
        $set_clauses = array();
        $params = array();
        $types = '';
        
        // 可更新的字段
        $updatable_fields = array(
            'title' => 's',
            'description' => 's',
            'alt_text' => 's',
            'category' => 's'
        );
        
        foreach ($updatable_fields as $field => $type) {
            if (isset($data[$field])) {
                $set_clauses[] = "{$field} = ?";
                $params[] = $data[$field];
                $types .= $type;
            }
        }
        
        if (empty($set_clauses)) {
            return false;
        }
        
        $set_clauses[] = 'updated_at = NOW()';
        $params[] = $media_id;
        $types .= 'i';
        
        $query = "UPDATE " . DB_PREFIX . "media SET " . implode(', ', $set_clauses) . " WHERE id = ?";
        $ok = self::$tododb->query($query, $params);
        
        if ($ok) {
            // 更新自定义字段
            if (!empty($data['meta'])) {
                foreach ($data['meta'] as $meta_key => $meta_value) {
                    self::save_media_meta($media_id, $meta_key, $meta_value);
                }
            }
            
            // 清除缓存
            unset(self::$media_cache[$media_id]);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * 删除媒体
     * 
     * @param int $media_id 媒体ID
     * @param bool $delete_files 是否删除文件
     * @return bool
     */
    public static function delete_media($media_id, $delete_files = true) {
        $media = self::get_media($media_id);
        if (!$media) {
            return false;
        }
        
        // 开始事务
        self::$tododb->begin_transaction();
        
        try {
            // 删除媒体元数据
            $delete_meta_query = "DELETE FROM " . DB_PREFIX . "media_meta WHERE media_id = ?";
            self::$tododb->query($delete_meta_query, [$media_id]);
            
            // 删除媒体记录
            $delete_media_query = "DELETE FROM " . DB_PREFIX . "media WHERE id = ?";
            $ok = self::$tododb->query($delete_media_query, [$media_id]);
            
            if (!$ok) {
                throw new Exception('Failed to delete media record');
            }
            
            // 删除文件
            if ($delete_files) {
                // 获取提供商信息
                $provider = isset($media['meta']['storage_provider']) ? $media['meta']['storage_provider'] : null;
                $storage_handler = null;
                if ($provider && $provider !== 'local' && class_exists('Todo_Storage_Manager')) {
                    $storage_handler = Todo_Storage_Manager::get_provider_by_type($provider);
                }

                // 删除主文件
                $main_file = TODO_ROOT . '/' . $media['file_path'];
                if (file_exists($main_file)) {
                    unlink($main_file);
                }
                if ($storage_handler) {
                    $storage_handler->delete($media['file_path']);
                }
                
            }
            
            // 提交事务
            self::$tododb->commit();
            
            // 清除缓存
            unset(self::$media_cache[$media_id]);
            
            return true;
            
        } catch (Exception $e) {
            // 回滚事务
            self::$tododb->rollback();
            todo_log_error('Media deletion failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 保存媒体元数据
     * 
     * @param int $media_id 媒体ID
     * @param string $meta_key 元数据键
     * @param mixed $meta_value 元数据值
     * @return bool
     */
    public static function save_media_meta($media_id, $meta_key, $meta_value) {
        self::ensure_db();
        $prefix = isset(self::$tododb->prefix) ? self::$tododb->prefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : (defined('DB_PREFIX') ? DB_PREFIX : 'todo_'));
        $table = $prefix . "media_meta";
        
        $query = "INSERT INTO {$table} 
                  (media_id, meta_key, meta_value, created_at) 
                  VALUES (?, ?, ?, NOW()) 
                  ON DUPLICATE KEY UPDATE 
                  meta_value = VALUES(meta_value), updated_at = NOW()";
        
        $meta_value_json = is_array($meta_value) ? json_encode($meta_value) : $meta_value;
        try {
            return self::$tododb->query($query, [$media_id, $meta_key, $meta_value_json]);
        } catch (Throwable $e) {
            return false;
        }
    }
    
    /**
     * 获取媒体元数据
     * 
     * @param int $media_id 媒体ID
     * @param string $meta_key 元数据键（可选）
     * @return array|string|null
     */
    public static function get_media_meta($media_id, $meta_key = null) {
        self::ensure_db();
        $prefix = isset(self::$tododb->prefix) ? self::$tododb->prefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : (defined('DB_PREFIX') ? DB_PREFIX : 'todo_'));
        $table = $prefix . "media_meta";
        
        try {
            if ($meta_key) {
                $query = "SELECT meta_value FROM {$table} 
                          WHERE media_id = ? AND meta_key = ?";
                $value = self::$tododb->get_var($query, [$media_id, $meta_key]);
                if ($value !== null) {
                    $decoded = json_decode($value, true);
                    return $decoded !== null ? $decoded : $value;
                }
                return null;
            } else {
                $query = "SELECT meta_key, meta_value FROM {$table} WHERE media_id = ?";
                $rows = self::$tododb->get_results($query, [$media_id], 'ARRAY_A');
                $meta = array();
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        $value = $row['meta_value'];
                        $decoded = json_decode($value, true);
                        $meta[$row['meta_key']] = $decoded !== null ? $decoded : $value;
                    }
                }
                return $meta;
            }
        } catch (Throwable $e) {
            return $meta_key ? null : array();
        }
    }
    
    /**
     * 删除媒体元数据
     * 
     * @param int $media_id 媒体ID
     * @param string $meta_key 元数据键（可选）
     * @return bool
     */
    public static function delete_media_meta($media_id, $meta_key = null) {
        self::ensure_db();
        $prefix = isset(self::$tododb->prefix) ? self::$tododb->prefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : (defined('DB_PREFIX') ? DB_PREFIX : 'todo_'));
        $table = $prefix . "media_meta";
        
        try {
            if ($meta_key) {
                $query = "DELETE FROM {$table} WHERE media_id = ? AND meta_key = ?";
                return self::$tododb->query($query, [$media_id, $meta_key]);
            } else {
                $query = "DELETE FROM {$table} WHERE media_id = ?";
                return self::$tododb->query($query, [$media_id]);
            }
        } catch (Throwable $e) {
            return false;
        }
    }
    
    /**
     * 验证文件
     * 
     * @param array $file $_FILES数组中的文件信息
     * @return bool|string 验证通过返回true，失败返回错误信息
     */
    private static function validate_file($file) {
        if (function_exists('get_option') && !get_option('enable_upload', true)) {
            return '文件上传功能已被管理员禁用';
        }

        // 检查上传错误
        if ($file['error'] !== UPLOAD_ERR_OK) {
            switch ($file['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    return '文件大小超过限制';
                case UPLOAD_ERR_PARTIAL:
                    return '文件上传不完整';
                case UPLOAD_ERR_NO_FILE:
                    return '没有选择文件';
                case UPLOAD_ERR_NO_TMP_DIR:
                    return '临时目录不存在';
                case UPLOAD_ERR_CANT_WRITE:
                    return '文件写入失败';
                default:
                    return '文件上传失败';
            }
        }
        
        // 检查文件大小
        $max_size = self::$max_file_size;
        if (class_exists('TodoUploadConfig')) {
            $max_size = (int) TodoUploadConfig::get_max_upload_size();
        } elseif (function_exists('get_option')) {
            $mb = get_option('max_upload_size', null);
            if ($mb !== null && is_numeric($mb) && (int) $mb > 0) {
                $max_size = (int) $mb * 1024 * 1024;
            }
        }
        if ((int) $max_size <= 0) {
            return '文件上传功能已被管理员禁用';
        }
        if ($file['size'] > $max_size) {
            return '文件大小不能超过 ' . self::format_file_size($max_size);
        }
        
        // 计算真实 MIME
        $real_mime = null;
        if (function_exists('finfo_open')) {
            $f = @finfo_open(FILEINFO_MIME_TYPE);
            if ($f) {
                $detected = @finfo_file($f, $file['tmp_name']);
                @finfo_close($f);
                if ($detected) $real_mime = $detected;
            }
        }
        if (!$real_mime && function_exists('getimagesize')) {
            $img = @getimagesize($file['tmp_name']);
            if (is_array($img) && !empty($img['mime'])) {
                $real_mime = $img['mime'];
            }
        }
        if (!$real_mime && function_exists('mime_content_type')) {
            $detected = @mime_content_type($file['tmp_name']);
            if ($detected) $real_mime = $detected;
        }
        if (!$real_mime) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $real_mime = function_exists('todo_get_mime_by_extension') ? todo_get_mime_by_extension($ext) : null;
        }
        if (!$real_mime) $real_mime = 'application/octet-stream';

        // 类型归类判断（统一口径）
        $category = function_exists('todo_get_mime_category') ? todo_get_mime_category($real_mime) : self::TYPE_OTHER;
        if ($category === self::TYPE_OTHER) {
            return '不支持的文件类型';
        }

        // 严格按照设置的允许类型进行判断
        if (class_exists('TodoUploadConfig')) {
            if (!TodoUploadConfig::is_type_allowed($real_mime)) {
                return 'MIME类型不被允许';
            }
        }
        
        return true;
    }
    
    /**
     * 获取文件信息
     * 
     * @param array $file $_FILES数组中的文件信息
     * @return array
     */
    private static function get_file_info($file) {
        $pathinfo = pathinfo($file['name']);
        
        return array(
            'name' => $pathinfo['filename'],
            'extension' => strtolower($pathinfo['extension'] ?? ''),
            'basename' => $pathinfo['basename']
        );
    }
    
    /**
     * 获取文件类型
     * 
     * @param string $extension 文件扩展名
     * @return string
     */
    private static function get_file_type($extension) {
        $extension = strtolower(trim($extension));
        $mime = function_exists('todo_get_mime_by_extension') ? todo_get_mime_by_extension($extension) : null;
        if ($mime && function_exists('todo_get_mime_category')) {
            return todo_get_mime_category($mime);
        }
        return self::TYPE_OTHER;
    }
    
    /**
     * 生成文件名
     * 
     * @param string $original_name 原始文件名
     * @param string $extension 文件扩展名
     * @return string
     */
    private static function generate_filename($original_name, $extension) {
        // 清理文件名（保留合法中文/Unicode字符）
        $clean_name = function_exists('sanitize_file_name') ? sanitize_file_name($original_name) : preg_replace('/[<>:"\/\\|?*\x00-\x1F]/u', '', $original_name);
        $clean_name = mb_substr($clean_name, 0, 50, 'UTF-8'); // 限制长度
        
        // 添加时间戳和随机数
        $timestamp = date('YmdHis');
        $random = mt_rand(1000, 9999);
        
        return $clean_name . '_' . $timestamp . '_' . $random . '.' . $extension;
    }
    
    /**
     * 获取上传路径
     * 
     * @param string $file_type 文件类型（已废弃，保持兼容性）
     * @return string
     */
    private static function get_upload_path($file_type = null) {
        $year = date('Y');
        $month = date('m');
        $day = date('d');
        
        $path = self::$upload_dir;

        // Phase 4: Site Partitioning
        if (function_exists('todo_current_site_id')) {
            $site_id = todo_current_site_id();
            if ($site_id > 0) {
                $path .= '/sites' . $site_id;
            }
        }

        $path .= '/' . $year . '/' . $month . '/' . $day;
        
        // 确保目录存在
        $full_path = TODO_ROOT . '/' . $path;
        if (!is_dir($full_path)) {
            mkdir($full_path, 0755, true);
        }
        
        return $path;
    }
    

    
    /**
     * 保存媒体到数据库
     * 
     * @param array $media_data 媒体数据
     * @return int|false
     */
    private static function save_media($media_data) {
        $query = "INSERT INTO " . DB_PREFIX . "media 
                  (site_id, user_id, title, filename, original_name, file_path, file_size, 
                   mime_type, file_type, extension, description, alt_text, category, 
                   thumbnails, created_at) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $params = [
            function_exists('todo_current_site_id') ? todo_current_site_id() : 0,
            $media_data['user_id'],
            $media_data['title'],
            $media_data['filename'],
            $media_data['original_name'],
            $media_data['file_path'],
            $media_data['file_size'],
            $media_data['mime_type'],
            $media_data['file_type'],
            $media_data['extension'],
            $media_data['description'],
            $media_data['alt_text'],
            $media_data['category'],
            $media_data['thumbnails']
        ];
        
        if (self::$tododb->query($query, $params)) {
            return self::$tododb->insert_id();
        }
        
        return false;
    }
    
    /**
     * 调整图片大小
     * 
     * @param string $file_path 文件路径
     * @param int $max_width 最大宽度
     * @param int $max_height 最大高度
     * @return bool
     */
    private static function resize_image($file_path, $max_width, $max_height) {
        if (!extension_loaded('gd')) {
            return false;
        }
        
        $image_info = getimagesize($file_path);
        if (!$image_info) {
            return false;
        }
        
        list($width, $height, $type) = $image_info;
        
        // 如果图片尺寸已经符合要求，不需要调整
        if ($width <= $max_width && $height <= $max_height) {
            return true;
        }
        
        // 计算新尺寸
        $ratio = min($max_width / $width, $max_height / $height);
        $new_width = intval($width * $ratio);
        $new_height = intval($height * $ratio);
        
        // 创建源图像
        switch ($type) {
            case IMAGETYPE_JPEG:
                $source = imagecreatefromjpeg($file_path);
                break;
            case IMAGETYPE_PNG:
                $source = imagecreatefrompng($file_path);
                break;
            case IMAGETYPE_GIF:
                $source = imagecreatefromgif($file_path);
                break;
            default:
                return false;
        }
        
        if (!$source) {
            return false;
        }
        
        // 创建新图像
        $destination = imagecreatetruecolor($new_width, $new_height);
        
        // 保持透明度
        if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
            imagealphablending($destination, false);
            imagesavealpha($destination, true);
            $transparent = imagecolorallocatealpha($destination, 255, 255, 255, 127);
            imagefilledrectangle($destination, 0, 0, $new_width, $new_height, $transparent);
        }
        
        // 调整大小
        imagecopyresampled($destination, $source, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
        
        // 保存图像
        $result = false;
        switch ($type) {
            case IMAGETYPE_JPEG:
                $result = imagejpeg($destination, $file_path, 90);
                break;
            case IMAGETYPE_PNG:
                $result = imagepng($destination, $file_path, 9);
                break;
            case IMAGETYPE_GIF:
                $result = imagegif($destination, $file_path);
                break;
        }
        
        // 清理内存
        imagedestroy($source);
        imagedestroy($destination);
        
        return $result;
    }
    
    /**
     * 创建缩略图
     * 
     * @param string $file_path 原文件路径
     * @param array $sizes 缩略图尺寸
     * @return array
     */
    private static function create_thumbnails($file_path, $sizes) {
        return array();
    }
    
    /**
     * 获取媒体URL
     * 
     * @param string $file_path 文件路径
     * @return string
     */
    private static function get_media_url($file_path, $provider = null) {
        if ($provider && $provider !== 'local' && class_exists('Todo_Storage_Manager')) {
            $storage = Todo_Storage_Manager::get_provider_by_type($provider);
            if ($storage) {
                return $storage->get_url($file_path);
            }
        } elseif ($provider === 'local') {
            return function_exists('todo_media_url_to_relative') ? todo_media_url_to_relative($file_path) : ('/' . ltrim($file_path, '/'));
        } elseif (class_exists('Todo_Storage_Manager')) {
            $storage_mgr = Todo_Storage_Manager::get_instance();
            if (!$storage_mgr->is_local()) {
                $clean_path = ltrim($file_path, '/');
                $cloud_path = (strpos($clean_path, 'content/uploads') === 0) ? $clean_path : 'content/uploads/' . $clean_path;
                $root_dir = defined('TODO_ROOT') ? TODO_ROOT : (defined('ABSPATH') ? ABSPATH : '');
                $local_file = ($root_dir !== '') ? (rtrim($root_dir, '/') . '/' . $cloud_path) : '';
                if ($local_file !== '' && file_exists($local_file)) {
                    return function_exists('todo_media_url_to_relative') ? todo_media_url_to_relative($file_path) : ('/' . ltrim($file_path, '/'));
                }
                return $storage_mgr->get_url($file_path);
            }
        }
        
        return function_exists('todo_media_url_to_relative') ? todo_media_url_to_relative($file_path) : ('/' . ltrim($file_path, '/'));
    }
    
    /**
     * 获取允许的MIME类型
     * 
     * @return array
     */
    private static function get_allowed_mime_types() {
        // 从设置中读取允许的扩展名（逗号分隔，如：jpg,png,pdf）
        $exts_str = todo_get_option('allowed_file_types', '');
        $exts = array_filter(array_map(function($e){return strtolower(trim($e));}, explode(',', $exts_str)));
        $flat = function_exists('todo_supported_mime_types_flat') ? todo_supported_mime_types_flat() : array();
        $mimes = array();
        foreach ($exts as $ext) {
            if (isset($flat[$ext])) {
                $mimes[] = $flat[$ext];
            }
        }
        // 若设置为空，回退到所有支持的类型
        if (empty($mimes) && !empty($flat)) {
            $mimes = array_values(array_unique(array_values($flat)));
        }
        return array_values(array_unique($mimes));
    }
    
    /**
     * 格式化文件大小
     * 
     * @param int $size 文件大小（字节）
     * @return string
     */
    public static function format_file_size($size) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $unit_index = 0;
        
        while ($size >= 1024 && $unit_index < count($units) - 1) {
            $size /= 1024;
            $unit_index++;
        }
        
        return round($size, 2) . ' ' . $units[$unit_index];
    }
    
    /**
     * 获取图片尺寸信息
     * 
     * @param array $metadata 元数据
     * @return string 尺寸信息
     */
    public static function get_image_dimensions($metadata) {
        if (isset($metadata['width']) && isset($metadata['height'])) {
            return $metadata['width'] . ' × ' . $metadata['height'];
        }
        return '';
    }

    /**
     * 验证目录路径安全性
     * 
     * @param string $path 目录路径
     * @return bool
     */
    public static function validate_path($path) {
        // 禁止上级目录访问
        if (strpos($path, '../') !== false || strpos($path, '..\\') !== false) {
            return false;
        }
        
        // 限制路径在uploads目录下
        $base_dir = realpath(TODO_ROOT . '/' . self::$upload_dir);
        $full_path = realpath(TODO_ROOT . '/' . self::$upload_dir . '/' . $path);
        
        if ($full_path === false || strpos($full_path, $base_dir) !== 0) {
            return false;
        }
        
        return true;
    }

    /**
     * 获取目录内容
     * 
     * @param string $path 目录路径
     * @param int $page 当前页码
     * @param string $search 搜索关键词
     * @param string $type 文件类型过滤
     * @return array
     */
    public static function get_directory_content($path, $page = 1, $search = '', $type = '') {
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        $full_path = TODO_ROOT . '/' . self::$upload_dir . '/' . $path;
        
        if (!is_dir($full_path)) {
            return array();
        }
        
        $files = array();
        $items = scandir($full_path);
        $items = array_diff($items, array('.', '..'));
        
        // 过滤和排序
        foreach ($items as $item) {
            $item_path = $full_path . '/' . $item;
            $relative_path = ltrim($path . '/' . $item, '/');
            
            // 统一方式检测真实 MIME（供过滤与展示使用）
            $mime_detected = null;
            if (function_exists('finfo_open')) {
                $f = @finfo_open(FILEINFO_MIME_TYPE);
                if ($f) {
                    $detected = @finfo_file($f, $item_path);
                    @finfo_close($f);
                    if ($detected) $mime_detected = $detected;
                }
            }
            if (!$mime_detected && function_exists('getimagesize')) {
                $img = @getimagesize($item_path);
                if (is_array($img) && !empty($img['mime'])) {
                    $mime_detected = $img['mime'];
                }
            }
            if (!$mime_detected && function_exists('mime_content_type')) {
                $detected = @mime_content_type($item_path);
                if ($detected) $mime_detected = $detected;
            }
            if (!$mime_detected) {
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                $mime_detected = function_exists('todo_get_mime_by_extension') ? todo_get_mime_by_extension($ext) : null;
            }
            
            // 跳过目录
            if (is_dir($item_path)) {
                continue;
            }
            
            // 过滤搜索词
            if ($search && stripos($item, $search) === false) {
                continue;
            }
            
            // 过滤文件类型（统一按 MIME 归类）
            if ($type) {
                $category = function_exists('todo_get_mime_category') && isset($mime_detected) && $mime_detected ? todo_get_mime_category($mime_detected) : self::get_file_type(strtolower(pathinfo($item, PATHINFO_EXTENSION)));
                if ($category !== $type) {
                    continue;
                }
            }
            
            $files[] = array(
                'name' => $item,
                'path' => $relative_path,
                'url' => self::get_media_url($relative_path),
                'size' => self::format_file_size(filesize($item_path)),
                'date' => date('Y-m-d H:i:s', filemtime($item_path)),
                'type' => ($mime_detected ?: mime_content_type($item_path))
            );
        }
        
        // 分页
        $total_files = count($files);
        $files = array_slice($files, $offset, $per_page);
        
        return $files;
    }

    /**
     * 获取目录分页总数
     * 
     * @param string $path 目录路径
     * @param string $search 搜索关键词
     * @param string $type 文件类型过滤
     * @return int
     */
    public static function get_directory_page_count($path, $search = '', $type = '') {
        $full_path = TODO_ROOT . '/' . self::$upload_dir . '/' . $path;
        $per_page = 20;
        
        if (!is_dir($full_path)) {
            return 1;
        }
        
        $items = scandir($full_path);
        $items = array_diff($items, array('.', '..'));
        $count = 0;
        
        foreach ($items as $item) {
            $item_path = $full_path . '/' . $item;
            
            // 跳过目录
            if (is_dir($item_path)) {
                continue;
            }
            
            // 过滤搜索词
            if ($search && stripos($item, $search) === false) {
                continue;
            }
            
            // 过滤文件类型（统一按 MIME 归类）
            if ($type) {
                $category = function_exists('todo_get_mime_category') && isset($mime_detected) && $mime_detected ? todo_get_mime_category($mime_detected) : self::get_file_type(strtolower(pathinfo($item, PATHINFO_EXTENSION)));
                if ($category !== $type) {
                    continue;
                }
            }
            
            $count++;
        }
        
        return ceil($count / $per_page);
    }
    
    /**
     * 获取媒体统计
     * 
     * @return array
     */
    public static function get_media_stats() {
        $stats = array(
            'total' => 0,
            'by_type' => array(),
            'total_size' => 0
        );
        
        $query = "SELECT file_type, COUNT(*) as count, SUM(file_size) as total_size 
                  FROM " . DB_PREFIX . "media 
                  GROUP BY file_type";
        $result = self::$tododb->query($query);
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $stats['total'] += $row['count'];
                $stats['total_size'] += $row['total_size'];
                $stats['by_type'][$row['file_type']] = array(
                    'count' => $row['count'],
                    'size' => $row['total_size']
                );
            }
        }
        
        return $stats;
    }
    
    /**
     * 获取文件类型选项
     * 
     * @return array
     */
    public static function get_file_type_options() {
        return array(
            self::TYPE_IMAGE => '图片',
            self::TYPE_VIDEO => '视频',
            self::TYPE_AUDIO => '音频',
            self::TYPE_DOCUMENT => '文档',
            self::TYPE_ARCHIVE => '压缩包',
            self::TYPE_OTHER => '其他'
        );
    }
}

// 便捷函数
if (!function_exists('todo_upload_file')) {
    /**
     * 上传文件
     * 
     * @param array $file $_FILES数组中的文件信息
     * @param array $options 上传选项
     * @return array|false
     */
    function todo_upload_file($file, $options = array()) {
        return Todo_Media_Manager::upload_file($file, $options);
    }
}

if (!function_exists('todo_get_media')) {
    /**
     * 获取媒体信息
     * 
     * @param int $media_id 媒体ID
     * @return array|null
     */
    function todo_get_media($media_id) {
        return Todo_Media_Manager::get_media($media_id);
    }
}

if (!function_exists('todo_get_media_list')) {
    /**
     * 获取媒体列表
     * 
     * @param array $args 查询参数
     * @return array
     */
    function todo_get_media_list($args = array()) {
        return Todo_Media_Manager::get_media_list($args);
    }
}

if (!function_exists('todo_delete_media')) {
    /**
     * 删除媒体
     * 
     * @param int $media_id 媒体ID
     * @param bool $delete_files 是否删除文件
     * @return bool
     */
    function todo_delete_media($media_id, $delete_files = true) {
        return Todo_Media_Manager::delete_media($media_id, $delete_files);
    }
}

if (!function_exists('todo_format_file_size')) {
    /**
     * 格式化文件大小
     * 
     * @param int $size 文件大小（字节）
     * @return string
     */
    function todo_format_file_size($size) {
        return Todo_Media_Manager::format_file_size($size);
    }
}

if (!function_exists('todo_get_image_dimensions')) {
    /**
     * 获取图片尺寸信息
     * 
     * @param array $metadata 元数据
     * @return string 尺寸信息
     */
    function todo_get_image_dimensions($metadata) {
        return Todo_Media_Manager::get_image_dimensions($metadata);
    }
}

?>
