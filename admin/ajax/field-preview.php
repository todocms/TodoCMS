<?php
/**
 * 字段预览处理器
 * Field Preview Handler for TodoCMS
 * @version 1.0.0
 */

$security = Todo_Security_Manager::getInstance();
if (!$security->verify_request_csrf()) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: application/json');
    header('X-Robots-Tag: noindex, nofollow', true);
    echo json_encode(['error' => 'CSRF verification failed']);
    exit;
}

// 检查AJAX请求
if (!defined('DOING_AJAX') || !DOING_AJAX) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Direct access denied']);
    exit;
}

// 检查请求头
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    todo_send_json_error(['message' => 'Direct access denied']);
}

/**
 * 字段预览处理器类
 */
class TodoFieldPreviewHandler {
    
    /**
     * 处理预览请求
     */
    public static function handle_request() {
        try {
            // 验证权限
            if (!current_user_can('edit_posts')) {
                throw new Exception('权限不足');
            }
            
            // 验证CSRF
            if (!todo_verify_nonce($_POST['_todononce'] ?? '', 'todo_field_preview')) {
                throw new Exception('安全验证失败');
            }
            
            $field_name = sanitize_text_field($_POST['field_name'] ?? '');
            $field_type = sanitize_text_field($_POST['field_type'] ?? '');
            $field_value = $_POST['field_value'] ?? '';
            
            if (empty($field_name) || empty($field_type)) {
                throw new Exception('缺少必要参数');
            }
            
            // 生成预览HTML
            $preview_html = self::generate_preview_html($field_type, $field_value, $field_name);
            
            todo_send_json_success([
                'preview_html' => $preview_html,
                'field_name' => $field_name,
                'field_type' => $field_type
            ]);
            
        } catch (Exception $e) {
            todo_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * 生成预览HTML
     */
    private static function generate_preview_html($field_type, $field_value, $field_name) {
        if (empty($field_value)) {
            return '<div class="no-files">暂无文件</div>';
        }
        
        // 解析字段值
        $files = self::parse_field_value($field_value);
        
        if (empty($files)) {
            return '<div class="no-files">暂无文件</div>';
        }
        ?>
        <div class="todo-file-previews">
            <?php foreach ($files as $file_id): ?>
                <?php echo self::render_file_preview($field_type, $file_id, $field_name); ?>
            <?php endforeach; ?>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * 解析字段值
     */
    private static function parse_field_value($field_value) {
        if (is_string($field_value)) {
            // 尝试JSON解码
            $decoded = json_decode($field_value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return is_array($decoded) ? $decoded : [$decoded];
            }
            
            // 单个值
            return [$field_value];
        }
        
        if (is_array($field_value)) {
            return $field_value;
        }
        
        return [$field_value];
    }
    
    /**
     * 渲染文件预览
     */
    private static function render_file_preview($field_type, $file_id, $field_name) {
        $file_info = self::get_file_info($file_id);
        
        if (!$file_info) {
            return '<div class="file-error">文件不存在 (ID: ' . esc_html($file_id) . ')</div>';
        }
        
        $is_image = strpos($file_info['mime_type'], 'image/') === 0;
        $preview_class = $is_image ? 'image-preview' : 'file-preview';
        ?>
        <div class="todo-file-preview <?php echo esc_attr($preview_class); ?>" data-file-id="<?php echo esc_attr($file_id); ?>">
            <?php if ($is_image): ?>
                <?php echo self::render_image_preview($file_info, $field_name); ?>
            <?php else: ?>
                <?php echo self::render_file_preview_content($file_info, $field_name); ?>
            <?php endif; ?>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * 渲染图片预览
     */
    private static function render_image_preview($file_info, $field_name) {
        ?>
        <!-- 图片容器 -->
        <div class="image-container">
            <img src="<?php echo esc_url($file_info['url']); ?>" 
                 alt="<?php echo esc_attr($file_info['filename']); ?>"
                 loading="lazy">
            <div class="image-overlay">
                <button type="button" class="button-link preview-btn" 
                        data-url="<?php echo esc_url($file_info['url']); ?>"
                        title="预览图片">
                    <i class="toicon">🔍</i>
                </button>
                <button type="button" class="button-link todo-remove-file" 
                        data-field="<?php echo esc_attr($field_name); ?>"
                        data-file-id="<?php echo esc_attr($file_info['id']); ?>"
                        title="删除文件">
                    <i class="toicon">✖</i>
                </button>
            </div>
        </div>
        
        <!-- 文件信息 -->
        <div class="file-info">
            <div class="filename" title="<?php echo esc_attr($file_info['filename']); ?>">
                <?php echo esc_html(self::truncate_filename($file_info['filename'], 30)); ?>
            </div>
            <div class="file-meta">
                <?php if (isset($file_info['width'], $file_info['height'])): ?>
                <span class="dimensions"><?php echo $file_info['width']; ?>×<?php echo $file_info['height']; ?></span>
                <?php endif; ?>
                <span class="file-size"><?php echo esc_html(self::format_file_size($file_info['file_size'])); ?></span>
            </div>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * 渲染文件预览内容
     */
    private static function render_file_preview_content($file_info, $field_name) {
        ?>
        <div class="file-container">
            <!-- 文件图标 -->
            <div class="file-icon">
                <span class="customicon <?php echo esc_attr(self::get_file_icon($file_info['mime_type'])); ?>"></span>
            </div>
            
            <!-- 文件详情 -->
            <div class="file-details">
                <div class="filename" title="<?php echo esc_attr($file_info['filename']); ?>">
                    <?php echo esc_html(self::truncate_filename($file_info['filename'], 25)); ?>
                </div>
                <div class="file-meta">
                    <span class="file-type"><?php echo esc_html(strtoupper(pathinfo($file_info['filename'], PATHINFO_EXTENSION))); ?></span>
                    <span class="file-size"><?php echo esc_html(self::format_file_size($file_info['file_size'])); ?></span>
                </div>
            </div>
            
            <!-- 文件操作 -->
            <div class="file-actions">
                <a href="<?php echo esc_url($file_info['url']); ?>" 
                   target="_blank" 
                   class="button-link download-btn"
                   title="下载文件">
                    <i class="toicon">⬇</i>
                </a>
                <button type="button" class="button-link todo-remove-file" 
                        data-field="<?php echo esc_attr($field_name); ?>"
                        data-file-id="<?php echo esc_attr($file_info['id']); ?>"
                        title="删除文件">
                    <i class="toicon">✖</i>
                </button>
            </div>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * 获取文件信息
     */
    private static function get_file_info($file_id) {
        global $tododb;
        
        $table_name = $tododb->prefix . 'todocms_media';
        $sql = "SELECT * FROM {$table_name} WHERE id = ? AND status = 'active'";
        $file_info = $tododb->get_row($sql, [$file_id], 'ARRAY_A');
        
        if (!$file_info) {
            return null;
        }
        
        // 添加完整URL
        $upload_config = TodoUploadConfig::get_config();
        $file_info['url'] = $upload_config['upload_url'] . $file_info['filename'];
        
        // 解析图片尺寸
        if (strpos($file_info['mime_type'], 'image/') === 0) {
            $file_path = $upload_config['upload_path'] . $file_info['filename'];
            if (file_exists($file_path)) {
                $image_size = getimagesize($file_path);
                if ($image_size) {
                    $file_info['width'] = $image_size[0];
                    $file_info['height'] = $image_size[1];
                }
            }
        }
        
        return $file_info;
    }
    
    /**
     * 获取文件图标
     */
    private static function get_file_icon($mime_type) {
        $icon_map = [
            // 图片
            'image/jpeg' => 'custom-image',
            'image/png' => 'custom-image',
            'image/gif' => 'custom-image',
            'image/webp' => 'custom-image',
            'image/svg+xml' => 'custom-image',
            
            // 视频
            'video/mp4' => 'custom-video',
            'video/webm' => 'custom-video',
            'video/ogg' => 'custom-video',
            'video/avi' => 'custom-video',
            'video/mov' => 'custom-video',
            
            // 音频
            'audio/mp3' => 'custom-audio',
            'audio/wav' => 'custom-audio',
            'audio/ogg' => 'custom-audio',
            'audio/m4a' => 'custom-audio',
            
            // 文档
            'application/pdf' => 'custom-document',
            'application/msword' => 'custom-document',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'custom-document',
            'application/vnd.ms-excel' => 'custom-document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'custom-document',
            'application/vnd.ms-powerpoint' => 'custom-document',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'custom-document',
            
            // 压缩文件
            'application/zip' => 'custom-archive',
            'application/x-rar-compressed' => 'custom-archive',
            'application/x-7z-compressed' => 'custom-archive',
            
            // 文本
            'text/plain' => 'custom-text',
            'text/html' => 'custom-code',
            'text/css' => 'custom-code',
            'text/javascript' => 'custom-code',
            'application/json' => 'custom-code',
            'application/xml' => 'custom-code',
        ];
        
        return $icon_map[$mime_type] ?? 'custom-default';
    }
    
    /**
     * 格式化文件大小
     */
    private static function format_file_size($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' B';
        }
    }
    
    /**
     * 截断文件名
     */
    private static function truncate_filename($filename, $max_length) {
        if (mb_strlen($filename) <= $max_length) {
            return $filename;
        }
        
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        
        $max_basename_length = $max_length - mb_strlen($extension) - 4; // 4 for "..." and "."
        
        if ($max_basename_length > 0) {
            return mb_substr($basename, 0, $max_basename_length) . '...' . ($extension ? '.' . $extension : '');
        }
        
        return mb_substr($filename, 0, $max_length - 3) . '...';
    }
}

// 处理请求
TodoFieldPreviewHandler::handle_request();
?>