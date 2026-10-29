<?php
/**
 * TodoCMS Media Handler Class
 * 
 * Handles media upload, management and AJAX requests
 * Based on TodoCMS media handling functionality
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

 
class TodoCMS_Media_Handler {
    
    /**
     * Upload directory path
     */
    private $upload_dir;
    
    /**
     * Upload URL
     */
    private $upload_url;
    
    /**
     * Allowed file types
     */
    private $allowed_types = [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'],
        'audio' => ['mp3', 'wav', 'ogg', 'm4a'],
        'video' => ['mp4', 'avi', 'mov', 'wmv', 'flv', 'webm'],
        'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'],
        'archive' => ['zip', 'rar', '7z', 'tar', 'gz']
    ];
    
    /**
     * Maximum file size (in bytes)
     */
    private $max_file_size = 10485760; // 10MB
    
    /**
     * Constructor
     */
    public function __construct() {
        if (class_exists('TodoUploadConfig')) {
            $this->max_file_size = (int) TodoUploadConfig::get_max_upload_size();
        } elseif (function_exists('get_option')) {
            $mb = get_option('max_upload_size', null);
            if ($mb !== null && is_numeric($mb) && (int) $mb > 0) {
                $this->max_file_size = (int) $mb * 1024 * 1024;
            }
        }
        $this->setup_upload_dir();
        $this->init_hooks();
    }
    
    /**
     * Setup upload directory
     */
    private function setup_upload_dir() {
        $upload_base = TODO_ROOT . '/content/uploads';
        $year = date('Y');
        $month = date('m');
        $day = date('d');
        
        $this->upload_dir = $upload_base . '/' . $year . '/' . $month . '/' . $day;
        $this->upload_url = TODO_URL . '/content/uploads/' . $year . '/' . $month . '/' . $day;
        
        // Create directory if it doesn't exist
        if (!file_exists($this->upload_dir)) {
            todo_mkdir_p($this->upload_dir);
        }
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // AJAX hooks for logged-in users
        todo_add_action('ajax_upload_media', [$this, 'handle_upload']);
        todo_add_action('ajax_get_media_library', [$this, 'get_media_library']);
        todo_add_action('ajax_delete_media', [$this, 'delete_media']);
        todo_add_action('ajax_update_media', [$this, 'update_media']);
    }
    
    /**
     * Handle file upload
     */
    public function handle_upload() {
        // Verify nonce
        if (!Todo_Security_Manager::verify_csrf_token($_POST['nonce'], 'todocms_media_nonce')) {
            todo_die('Security check failed');
        }

        if (function_exists('get_option') && !get_option('enable_upload', true)) {
            todo_send_json_error('文件上传功能已被管理员禁用');
        }
        if ((int) $this->max_file_size <= 0) {
            todo_send_json_error('文件上传功能已被管理员禁用');
        }
        
        // Check if files were uploaded
        if (empty($_FILES['files'])) {
            todo_send_json_error('No files uploaded');
        }
        
        $uploaded_files = [];
        $errors = [];
        
        // Handle multiple files
        $files = $_FILES['files'];
        $file_count = count($files['name']);
        
        for ($i = 0; $i < $file_count; $i++) {
            $file = [
                'name' => $files['name'][$i],
                'type' => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error' => $files['error'][$i],
                'size' => $files['size'][$i]
            ];
            
            $result = $this->process_upload($file);
            
            if ($result instanceof Todo_Error) {
                $errors[] = $file['name'] . ': ' . $result->get_error_message();
            } else {
                $uploaded_files[] = $result;
            }
        }
        
        if (!empty($errors)) {
            todo_send_json_error(implode(', ', $errors));
        }
        
        todo_send_json_success($uploaded_files);
    }
    
    /**
     * Process single file upload
     */
    private function process_upload($file) {
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return new Todo_Error('upload_error', $this->get_upload_error_message($file['error']));
        }
        
        // Check for system files
        $filename = $file['name'];
        $blocked_files = ['.DS_Store', 'Thumbs.db', '.htaccess', '.gitignore', '.git', '.svn'];
        $blocked_patterns = ['/^\./', '/~$/', '/\.tmp$/', '/\.temp$/'];
        
        // Check if it's a blocked file
        if (in_array($filename, $blocked_files)) {
            return new Todo_Error('system_file', 'System files are not allowed');
        }
        
        // Check if it matches blocked patterns
        foreach ($blocked_patterns as $pattern) {
            if (preg_match($pattern, $filename)) {
                return new Todo_Error('invalid_filename', 'This file type is not allowed');
            }
        }
        
        // Validate file size
        if ($file['size'] > $this->max_file_size) {
            return new Todo_Error('file_too_large', 'File size exceeds maximum allowed size');
        }
        
        // Validate file type
        $file_info = pathinfo($file['name']);
        $extension = strtolower($file_info['extension']);
        
        if (!$this->is_allowed_file_type($extension)) {
            return new Todo_Error('invalid_file_type', 'File type not allowed');
        }
        
        // Generate unique filename
        $filename = $this->generate_unique_filename($file['name']);
        $file_path = $this->upload_dir . '/' . $filename;
        
        // realpath 前缀校验，确保上传目录位于 /content/uploads 下
        $uploads_base = (defined('ABSPATH') ? rtrim(ABSPATH, '/') : rtrim(TODO_ROOT, '/')) . '/content/uploads';
        $base_real = realpath($uploads_base);
        $dir_real = realpath($this->upload_dir);
        if ($base_real === false || $dir_real === false || strpos($dir_real, $base_real) !== 0) {
            return new Todo_Error('invalid_directory', '非法上传目录');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return new Todo_Error('invalid_source', '非法上传源');
        }
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $file_path)) {
            return new Todo_Error('move_failed', 'Failed to move uploaded file');
        }
        @chmod($file_path, 0644);
        
        // Get file info
        $file_url = $this->upload_url . '/' . $filename;
        $file_type = $this->get_file_type($extension);
        $file_size = filesize($file_path);
        
        // Save to database
        $attachment_id = $this->save_attachment([
            'filename' => $filename,
            'original_name' => $file['name'],
            'file_path' => $file_path,
            'file_url' => $file_url,
            'file_type' => $file_type,
            'file_size' => $file_size,
            'mime_type' => $file['type'],
            'upload_date' => date('Y-m-d H:i:s')
        ]);
        
        if (!$attachment_id) {
            // Clean up file if database save failed
            unlink($file_path);
            return new Todo_Error('db_save_failed', 'Failed to save file information');
        }
        
        return $this->get_attachment_data($attachment_id);
    }
    
    /**
     * Generate unique filename using UEditor format
     */
    private function generate_unique_filename($original_name) {
        $file_info = pathinfo($original_name);
        $extension = strtolower($file_info['extension']);
        
        // Generate filename using UEditor format: {time}{rand:6}
        $timestamp = time();
        $random_string = $this->generate_random_string(6);
        $filename = $timestamp . $random_string . '.' . $extension;
        
        // Ensure uniqueness (though very unlikely with timestamp + random)
        $counter = 1;
        while (file_exists($this->upload_dir . '/' . $filename)) {
            $filename = $timestamp . $random_string . '-' . $counter . '.' . $extension;
            $counter++;
        }
        
        return $filename;
    }
    
    /**
     * Generate random string
     */
    private function generate_random_string($length) {
        $chars = "abcdefghijklmnopqrstuvwxyz0123456789";
        $str = "";
        for ($i = 0; $i < $length; $i++) {
            $str .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
        }
        return $str;
    }
    
    /**
     * Check if file type is allowed
     */
    private function is_allowed_file_type($extension) {
        foreach ($this->allowed_types as $type => $extensions) {
            if (in_array($extension, $extensions)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Get file type category
     */
    private function get_file_type($extension) {
        foreach ($this->allowed_types as $type => $extensions) {
            if (in_array($extension, $extensions)) {
                return $type;
            }
        }
        return 'unknown';
    }
    
    /**
     * Save attachment to database
     */
    private function save_attachment($data) {
        global $tododb;
        
        $table_name = TABLE_PREFIX . 'media';
        
        $result = $tododb->query(
            "INSERT INTO {$table_name} (filename, original_name, file_path, file_url, file_type, file_size, mime_type, upload_date, title, alt_text, caption, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $data['filename'],
                $data['original_name'],
                $data['file_path'],
                $data['file_url'],
                $data['file_type'],
                $data['file_size'],
                $data['mime_type'],
                $data['upload_date'],
                pathinfo($data['original_name'], PATHINFO_FILENAME),
                '',
                '',
                ''
            ]
        );
        
        return $result ? $tododb->insert_id() : false;
    }
    
    /**
     * Get media library
     */
    public function get_media_library() {
        // Verify nonce
        if (!Todo_Security_Manager::verify_csrf_token($_POST['nonce'], 'todocms_media_nonce')) {
            todo_die('Security check failed');
        }
        
        global $tododb;
        $table_name = TABLE_PREFIX . 'media';
        
        $type = todo_sanitize_text_field($_POST['type'] ?? '');
        $search = todo_sanitize_text_field($_POST['search'] ?? '');
        $limit = intval($_POST['limit'] ?? 50);
        $offset = intval($_POST['offset'] ?? 0);
        
        $where_clauses = [];
        $where_values = [];
        
        if ($type && $type !== 'all') {
            $where_clauses[] = 'file_type = %s';
            $where_values[] = $type;
        }
        
        if ($search) {
            $where_clauses[] = '(original_name LIKE %s OR title LIKE %s)';
            $where_values[] = '%' . $search . '%';
            $where_values[] = '%' . $search . '%';
        }
        
        $where_sql = '';
        if (!empty($where_clauses)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
        }
        
        $sql = "SELECT * FROM {$table_name} {$where_sql} ORDER BY upload_date DESC LIMIT ? OFFSET ?";
        $attachments = $tododb->get_results($sql, array_merge($where_values, [$limit, $offset]), 'ARRAY_A');
        
        // Format attachment data
        $formatted_attachments = [];
        foreach ($attachments as $attachment) {
            $formatted_attachments[] = $this->format_attachment_data($attachment);
        }
        
        todo_send_json_success($formatted_attachments);
    }
    
    /**
     * Get attachment data
     */
    private function get_attachment_data($attachment_id) {
        global $tododb;
        $table_name = TABLE_PREFIX . 'media';
        
        $attachment = $tododb->get_results("SELECT * FROM {$table_name} WHERE id = ?", [$attachment_id], 'ARRAY_A');
        $attachment = $attachment ? $attachment[0] : null;
        
        return $attachment ? $this->format_attachment_data($attachment) : null;
    }
    
    /**
     * Format attachment data for frontend
     */
    private function format_attachment_data($attachment) {
        return [
            'id' => $attachment['id'],
            'filename' => $attachment['filename'],
            'title' => $attachment['title'],
            'alt' => $attachment['alt_text'],
            'caption' => $attachment['caption'],
            'description' => $attachment['description'],
            'url' => $attachment['file_url'],
            'thumbnail' => $attachment['file_url'],
            'type' => $attachment['file_type'],
            'subtype' => pathinfo($attachment['filename'], PATHINFO_EXTENSION),
            'mime' => $attachment['mime_type'],
            'size' => $attachment['file_size'],
            'filesizeHumanReadable' => $this->format_file_size($attachment['file_size']),
            'dateFormatted' => date('Y年m月d日', strtotime($attachment['upload_date'])),
            'uploadDate' => $attachment['upload_date']
        ];
    }
    
    /**
     * Update media
     */
    public function update_media() {
        // Verify nonce
        if (!Todo_Security_Manager::verify_csrf_token($_POST['nonce'], 'todocms_media_nonce')) {
            todo_die('Security check failed');
        }
        
        $attachment_id = intval($_POST['attachment_id']);
        $title = todo_sanitize_text_field($_POST['title'] ?? '');
        $alt_text = todo_sanitize_text_field($_POST['alt_text'] ?? '');
        $caption = strip_tags(trim($_POST['caption'] ?? ''));
        $description = strip_tags(trim($_POST['description'] ?? ''));
        
        global $tododb;
        $table_name = TABLE_PREFIX . 'media';
        
        $result = $tododb->query(
            "UPDATE {$table_name} SET title = ?, alt_text = ?, caption = ?, description = ? WHERE id = ?",
            [$title, $alt_text, $caption, $description, $attachment_id]
        );
        
        if ($result !== false) {
            todo_send_json_success('Media updated successfully');
        } else {
            todo_send_json_error('Failed to update media');
        }
    }
    
    /**
     * Delete media
     */
    public function delete_media() {
        // Verify nonce
        if (!Todo_Security_Manager::verify_csrf_token($_POST['nonce'], 'todocms_media_nonce')) {
            todo_die('Security check failed');
        }
        
        $attachment_id = intval($_POST['attachment_id']);
        
        global $tododb;
        $table_name = TABLE_PREFIX . 'media';
        
        // Get attachment info before deletion
        $attachment = $tododb->get_results("SELECT * FROM {$table_name} WHERE id = ?", [$attachment_id], 'ARRAY_A');
        $attachment = $attachment ? $attachment[0] : null;
        
        if (!$attachment) {
            todo_send_json_error('Attachment not found');
        }
        
        // Delete files
        if (file_exists($attachment['file_path'])) {
            unlink($attachment['file_path']);
        }
        
        // Delete from database
        $result = $tododb->query(
            "DELETE FROM {$table_name} WHERE id = ?",
            [$attachment_id]
        );
        
        if ($result !== false) {
            todo_send_json_success('Media deleted successfully');
        } else {
            todo_send_json_error('Failed to delete media');
        }
    }
    
    /**
     * Format file size - 使用统一的格式化函数
     */
    private function format_file_size($bytes) {
        return todo_format_file_size($bytes);
    }
    
    /**
     * Get upload error message
     */
    private function get_upload_error_message($error_code) {
        switch ($error_code) {
            case UPLOAD_ERR_INI_SIZE:
                return 'File size exceeds server limit';
            case UPLOAD_ERR_FORM_SIZE:
                return 'File size exceeds form limit';
            case UPLOAD_ERR_PARTIAL:
                return 'File was only partially uploaded';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing temporary folder';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk';
            case UPLOAD_ERR_EXTENSION:
                return 'File upload stopped by extension';
            default:
                return 'Unknown upload error';
        }
    }
    
    /**
     * Create media button HTML
     */
    public static function create_button($options = []) {
        $defaults = [
            'text' => '选择媒体',
            'class' => 'todocms-media-button',
            'target' => null,
            'preview' => null,
            'multiple' => false,
            'type' => 'image',
            'title' => '选择或上传媒体文件',
            'button_text' => '选择文件'
        ];
        
        $options = array_merge($defaults, $options);
        
        $data_attrs = [
            'data-media-options' => json_encode([
                'title' => $options['title'],
                'button' => ['text' => $options['button_text']],
                'multiple' => $options['multiple'],
                'library' => ['type' => $options['type']]
            ])
        ];
        
        if ($options['target']) {
            $data_attrs['data-target'] = $options['target'];
        }
        
        if ($options['preview']) {
            $data_attrs['data-preview'] = $options['preview'];
        }
        
        $attrs = '';
        foreach ($data_attrs as $key => $value) {
            $attrs .= ' ' . $key . '="' . todo_esc_attr($value) . '"';
        }
        
        return sprintf(
            '<button type="button" class="%s"%s>%s</button>',
            todo_esc_attr($options['class']),
            $attrs,
            todo_esc_html($options['text'])
        );
    }
    
    /**
     * Get media by ID
     */
    public static function get_media($id) {
        global $tododb;
        $table_name = TABLE_PREFIX . 'media';
        
        $attachment = $tododb->get_results("SELECT * FROM {$table_name} WHERE id = ?", [$id], 'ARRAY_A');
        $attachment = $attachment ? $attachment[0] : null;
        
        if (!$attachment) {
            return null;
        }
        
        $handler = new self();
        return $handler->format_attachment_data($attachment);
    }
    
    /**
     * Get media URL by ID
     */
    public static function get_media_url($id) {
        $media = self::get_media($id);
        return $media ? $media['url'] : '';
    }
}

// Initialize media handler
new TodoCMS_Media_Handler();
