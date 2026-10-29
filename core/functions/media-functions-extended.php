<?php
/**
 * TodoCMS 媒体处理扩展函数
 * 
 * 提供图像处理、WebP支持等媒体相关功能
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 从WebP文件创建图像资源
 * @param string $filename WebP文件路径
 * @return resource|false 图像资源或false
 */
if (!function_exists('imagecreatefromwebp')) {
    function imagecreatefromwebp($filename) {
        if (!file_exists($filename)) {
            return false;
        }
        
        // 检查GD库是否支持WebP
        if (!function_exists('imagewebp') || !function_exists('imagecreatefromstring')) {
            error_log("GD库不支持WebP格式");
            return false;
        }
        
        try {
            $webp_data = file_get_contents($filename);
            if ($webp_data === false) {
                return false;
            }
            
            $image = imagecreatefromstring($webp_data);
            return $image;
        } catch (Exception $e) {
            error_log("创建WebP图像失败: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * 输出WebP格式图像
 * @param resource $image 图像资源
 * @param string $to 输出文件路径，null为直接输出
 * @param int $quality 图像质量 (0-100)
 * @return bool 成功返回true
 */
if (!function_exists('imagewebp')) {
    function imagewebp($image, $to = null, $quality = 80) {
        if (!is_resource($image)) {
            return false;
        }
        
        // 检查GD库是否支持WebP
        if (!function_exists('imagecreatefromstring')) {
            error_log("GD库不支持WebP格式");
            return false;
        }
        
        try {
            // 设置质量范围
            $quality = max(0, min(100, intval($quality)));
            
            if ($to === null) {
                // 直接输出到浏览器
                header('Content-Type: image/webp');
                return imagepng($image); // 如果不支持WebP，降级为PNG
            } else {
                // 保存到文件
                $result = imagepng($image, $to); // 如果不支持WebP，降级为PNG
                return $result;
            }
        } catch (Exception $e) {
            error_log("输出WebP图像失败: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * 处理媒体删除操作
 * @param int $media_id 媒体ID
 * @param bool $force_delete 是否强制删除文件
 * @return bool 成功返回true
 */
function todo_handle_media_delete($media_id, $force_delete = false) {
    global $tododb;
    
    if (!$media_id) {
        return false;
    }
    
    try {
        // 获取媒体信息
        $media = $tododb->get_row("
            SELECT * FROM " . TODO_TABLE_PREFIX . "media 
            WHERE id = ?
        ", array($media_id));
        
        if (!$media) {
            return false;
        }
        
        $tododb->beginTransaction();
        
        // 检查是否有其他地方在使用这个媒体
        $usage_count = 0;
        
        // 检查文章特色图片
        $usage_count += $tododb->get_var("
            SELECT COUNT(*) FROM " . TODO_TABLE_PREFIX . "posts 
            WHERE featured_image_id = ?
        ", array($media_id));
        
        // 检查文章内容中的引用
        $usage_count += $tododb->get_var("
            SELECT COUNT(*) FROM " . TODO_TABLE_PREFIX . "posts 
            WHERE content LIKE ?
        ", array('%' . $media['file_url'] . '%'));
        
        if ($usage_count > 0 && !$force_delete) {
            $tododb->rollBack();
            return false; // 媒体正在被使用，不能删除
        }
        
        // 删除数据库记录
        $tododb->query("
            DELETE FROM " . TODO_TABLE_PREFIX . "media 
            WHERE id = ?
        ", array($media_id));
        
        // 删除物理文件
        if ($force_delete && !empty($media['file_path']) && file_exists($media['file_path'])) {
            unlink($media['file_path']);
            
            // 删除缩略图
            $thumbnail_path = dirname($media['file_path']) . '/thumbs/' . basename($media['file_path']);
            if (file_exists($thumbnail_path)) {
                unlink($thumbnail_path);
            }
        }
        
        $tododb->commit();
        return true;
    } catch (Exception $e) {
        $tododb->rollBack();
        error_log("删除媒体失败: " . $e->getMessage());
        return false;
    }
}

/**
 * 获取媒体信息
 * @param int $media_id 媒体ID
 * @return array|false 媒体信息数组或false
 */
function todo_get_media_info($media_id) {
    global $tododb;
    
    if (!$media_id) {
        return false;
    }
    
    try {
        $media = $tododb->get_row("
            SELECT * FROM " . TODO_TABLE_PREFIX . "media 
            WHERE id = ?
        ", array($media_id));
        
        if ($media) {
            // 添加额外信息
            $media['file_size_formatted'] = todo_format_file_size($media['file_size']);
            $media['is_image'] = in_array(strtolower($media['file_extension']), ['jpg', 'jpeg', 'png', 'gif', 'webp']);
            $media['is_video'] = in_array(strtolower($media['file_extension']), ['mp4', 'avi', 'mov', 'wmv', 'flv']);
            $media['is_audio'] = in_array(strtolower($media['file_extension']), ['mp3', 'wav', 'ogg', 'aac']);
        }
        
        return $media;
    } catch (Exception $e) {
        error_log("获取媒体信息失败: " . $e->getMessage());
        return false;
    }
}

/**
 * 格式化文件大小
 * @param int $bytes 字节数
 * @return string 格式化后的文件大小
 */
function todo_format_file_size($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

/**
 * 根据文件扩展名获取文件类型
 * 
 * @param string $extension 文件扩展名
 * @return string 文件类型
 */
function todo_get_file_type_by_extension($extension) {
    $extension = strtolower($extension);
    
    // 图片类型
    $image_types = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico'];
    if (in_array($extension, $image_types)) {
        return 'image';
    }
    
    // 视频类型
    $video_types = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv', '3gp'];
    if (in_array($extension, $video_types)) {
        return 'video';
    }
    
    // 音频类型
    $audio_types = ['mp3', 'wav', 'ogg', 'aac', 'flac', 'wma', 'm4a'];
    if (in_array($extension, $audio_types)) {
        return 'audio';
    }
    
    // 文档类型
    $document_types = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf'];
    if (in_array($extension, $document_types)) {
        return 'document';
    }
    
    // 压缩文件类型
    $archive_types = ['zip', 'rar', '7z', 'tar', 'gz', 'bz2'];
    if (in_array($extension, $archive_types)) {
        return 'archive';
    }
    
    // 默认为其他类型
    return 'other';
}
