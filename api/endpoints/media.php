<?php
/**
 * 媒体API端点
 *
 * 提供媒体文件上传和管理的API端点
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

/**
 * 获取媒体文件列表
 *
 * @param array $params 请求参数
 * @return array 媒体文件列表
 */
function api_get_media($params) {
    global $tododb;
    
    // 默认参数
    $defaults = [
        'page' => 1,
        'per_page' => 20,
        'search' => '',
        'mime_type' => '',
        'orderby' => 'post_date',
        'order' => 'DESC'
    ];
    
    // 合并参数
    $args = array_merge($defaults, $params);
    
    // 验证参数
    $args['page'] = max(1, intval($args['page']));
    $args['per_page'] = max(1, min(100, intval($args['per_page'])));
    $args['order'] = in_array(strtoupper($args['order']), ['ASC', 'DESC']) ? strtoupper($args['order']) : 'DESC';
    
    // 构建查询
    $query = "SELECT * FROM " . TABLE_PREFIX . "posts WHERE post_type = 'attachment'";
    $params = [];
    
    // 添加搜索条件
    if (!empty($args['search'])) {
        $query .= " AND (post_title LIKE ? OR post_content LIKE ?)";
        $search_term = '%' . $args['search'] . '%';
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    // 添加MIME类型条件
    if (!empty($args['mime_type'])) {
        $query .= " AND post_mime_type LIKE ?";
        $mime_type = $args['mime_type'] . '%';
        $params[] = $mime_type;
    }
    
    // 添加排序
    $valid_orderby = [
        'post_date' => 'post_date',
        'date' => 'post_date',
        'ID' => 'ID',
        'id' => 'ID',
        'title' => 'post_title',
        'post_title' => 'post_title'
    ];
    $orderby_field = isset($valid_orderby[$args['orderby']]) ? $valid_orderby[$args['orderby']] : 'post_date';
    $order_dir = in_array($args['order'], ['ASC', 'DESC']) ? $args['order'] : 'DESC';
    $query .= " ORDER BY {$orderby_field} {$order_dir}";
    
    // 添加分页
    $offset = ($args['page'] - 1) * $args['per_page'];
    $query .= " LIMIT " . intval($args['per_page']) . " OFFSET " . intval($offset);
    
    // 执行查询
    $media = $tododb->query($query, $params);
    
    // 获取总数
    $count_query = "SELECT COUNT(*) as total FROM " . TABLE_PREFIX . "posts WHERE post_type = 'attachment'";
    $count_params = [];
    
    // 添加搜索条件
    if (!empty($args['search'])) {
        $count_query .= " AND (post_title LIKE ? OR post_content LIKE ?)";
        $search_term = '%' . $args['search'] . '%';
        $count_params[] = $search_term;
        $count_params[] = $search_term;
    }
    
    // 添加MIME类型条件
    if (!empty($args['mime_type'])) {
        $count_query .= " AND post_mime_type LIKE ?";
        $mime_type = $args['mime_type'] . '%';
        $count_params[] = $mime_type;
    }
    
    $count_result = $tododb->query($count_query, $count_params);
    $total = isset($count_result[0]['total']) ? intval($count_result[0]['total']) : 0;
    
    // 处理结果
    $result = [];
    foreach ($media as $item) {
        // 获取媒体元数据
        $meta = get_post_meta($item['ID']);
        
        // 构建媒体URL
        $file_path = isset($meta['_todo_attached_file']) ? $meta['_todo_attached_file'] : '';
        $upload_dir = todo_upload_dir(false);
        $url = $upload_dir['baseurl'] . '/' . $file_path;
        
        // 获取媒体尺寸信息
        $width = isset($meta['_width']) ? intval($meta['_width']) : 0;
        $height = isset($meta['_height']) ? intval($meta['_height']) : 0;
        $file_size = isset($meta['_filesize']) ? intval($meta['_filesize']) : 0;
        
        $result[] = [
            'id' => intval($item['ID']),
            'title' => $item['post_title'],
            'description' => $item['post_content'],
            'caption' => $item['post_excerpt'],
            'alt_text' => isset($meta['_todo_attachment_image_alt']) ? $meta['_todo_attachment_image_alt'] : '',
            'mime_type' => $item['post_mime_type'],
            'url' => $url,
            'width' => $width,
            'height' => $height,
            'file_size' => $file_size,
            'date' => $item['post_date'],
            'author' => intval($item['post_author'])
        ];
    }
    
    // 返回结果
    return [
        'media' => $result,
        'total' => $total,
        'page' => $args['page'],
        'per_page' => $args['per_page'],
        'total_pages' => ceil($total / $args['per_page'])
    ];
}

/**
 * 获取单个媒体文件
 *
 * @param array $params 请求参数
 * @return array 媒体文件数据
 */
function api_get_media_item($params) {
    global $tododb;
    
    // 验证参数
    if (!isset($params['id'])) {
        send_api_error(400, '缺少ID参数');
    }
    
    $media_id = intval($params['id']);
    
    // 查询媒体
    $query = "SELECT * FROM " . TABLE_PREFIX . "posts WHERE ID = ? AND post_type = 'attachment' LIMIT 1";
    $media = $tododb->query($query, [$media_id]);
    
    // 检查媒体是否存在
    if (empty($media)) {
        send_api_error(404, '媒体文件不存在');
    }
    
    $media = $media[0];
    
    // 获取媒体元数据
    $meta = get_post_meta($media['ID']);
    
    // 构建媒体URL
    $file_path = isset($meta['_todo_attached_file']) ? $meta['_todo_attached_file'] : '';
    $upload_dir = todo_upload_dir();
    $url = $upload_dir['baseurl'] . '/' . $file_path;
    
    // 获取媒体尺寸信息
    $width = isset($meta['_width']) ? intval($meta['_width']) : 0;
    $height = isset($meta['_height']) ? intval($meta['_height']) : 0;
    $file_size = isset($meta['_filesize']) ? intval($meta['_filesize']) : 0;
    
    // 返回结果
    return [
        'id' => intval($media['media_id']),
        'title' => $media['media_title'],
        'description' => $media['media_description'],
        'caption' => $media['media_alt'],
        'alt_text' => $media['media_alt'],
        'mime_type' => $media['media_type'],
        'url' => $url,
        'width' => $width,
        'height' => $height,
        'file_size' => $file_size,
        'date' => $media['upload_time'],
        'modified' => $media['modified_time'],
        'author' => intval($media['user_id'])
    ];
}

/**
 * 上传媒体文件
 *
 * @param array $params 请求参数
 * @return array 上传的媒体文件数据
 */
function api_upload_media($params) {
    global $tododb;
    
    // 检查是否有文件上传
    if (empty($_FILES)) {
        send_api_error(400, '没有文件上传');
    }
    
    // 获取上传的文件
    $file = isset($_FILES['file']) ? $_FILES['file'] : null;
    
    if (!$file || !isset($file['tmp_name']) || empty($file['tmp_name'])) {
        send_api_error(400, '文件上传失败');
    }
    
    // 检查文件名，拒绝系统文件
    $filename = $file['name'];
    $blocked_files = ['.DS_Store', 'Thumbs.db', '.htaccess', '.gitignore', '.git', '.svn'];
    $blocked_patterns = ['/^\./', '/~$/', '/\.tmp$/', '/\.temp$/'];
    
    // 检查是否为被阻止的文件名
    if (in_array($filename, $blocked_files)) {
        send_api_error(400, '不允许上传系统文件');
    }
    
    // 检查是否匹配被阻止的模式
    foreach ($blocked_patterns as $pattern) {
        if (preg_match($pattern, $filename)) {
            send_api_error(400, '不允许上传此类型文件');
        }
    }
    
    // 检查文件类型：严格依据后台设置（扩展名）+统一MIME清单
    $file_mime_type = null;
    if (function_exists('finfo_open')) {
        $f = finfo_open(FILEINFO_MIME_TYPE);
        if ($f) {
            $file_mime_type = finfo_file($f, $file['tmp_name']);
            finfo_close($f);
        }
    }
    if (!$file_mime_type && function_exists('mime_content_type')) {
        $file_mime_type = @mime_content_type($file['tmp_name']);
    }
    if (!$file_mime_type && function_exists('getimagesize')) {
        $info = @getimagesize($file['tmp_name']);
        if ($info && isset($info['mime'])) $file_mime_type = $info['mime'];
    }
    if (!$file_mime_type) $file_mime_type = 'application/octet-stream';
    
    // 加载Composer autoloader

    // 加载配置
    require_once ABSPATH . 'config.php';
    require_once ABSPATH . 'core/bootstrap.php';

    if (function_exists('get_option') && !get_option('enable_upload', true)) {
        send_api_error(403, '文件上传功能已被管理员禁用');
    }

    if (!TodoUploadConfig::is_type_allowed($file_mime_type)) {
        send_api_error(400, '不支持的文件类型');
    }
    
    // 检查文件大小
    $max_file_size = class_exists('TodoUploadConfig') ? (int) TodoUploadConfig::get_max_upload_size() : 0;
    if ((int) $max_file_size <= 0) {
        send_api_error(403, '文件上传功能已被管理员禁用');
    }
    
    if ($file['size'] > $max_file_size) {
        send_api_error(400, '文件大小超过限制');
    }
    
    // 准备上传目录
    $upload_dir = todo_upload_dir();
    $year_month = date('Y/m');
    $target_dir = $upload_dir['basedir'] . '/' . $year_month;
    
    // 创建目录（如果不存在）
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0755, true);
    }
    // realpath 前缀校验，确保上传路径在 uploads 下
    $basedir_real = realpath($upload_dir['basedir']);
    $target_dir_real = realpath($target_dir);
    if ($basedir_real === false || $target_dir_real === false || strpos($target_dir_real, $basedir_real) !== 0) {
        send_api_error(500, '非法上传目录');
    }
    
    // 生成唯一文件名
    $file_name = sanitize_file_name($file['name']);
    $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);
    $file_base = pathinfo($file_name, PATHINFO_FILENAME);
    $unique_filename = $file_base . '-' . uniqid() . '.' . $file_ext;
    $target_file = $target_dir . '/' . $unique_filename;
    // 再次保证目的路径合法
    $dest_parent_real = realpath(dirname($target_file));
    if ($dest_parent_real === false || strpos($dest_parent_real, $basedir_real) !== 0) {
        send_api_error(500, '非法目标路径');
    }
    
    // 移动上传的文件（仅允许 HTTP 上传源）
    if (!is_uploaded_file($file['tmp_name'])) {
        send_api_error(400, '非法上传源');
    }
    if (!move_uploaded_file($file['tmp_name'], $target_file)) {
        send_api_error(500, '文件上传失败');
    }
    // 设置权限
    @chmod($target_file, 0644);
    
    // 获取文件信息
    $file_size = filesize($target_file);
    $file_title = isset($params['title']) ? todo_sanitize_text_field($params['title']) : $file_base;
    $file_description = isset($params['description']) ? sanitize_textarea_field($params['description']) : '';
    $file_caption = isset($params['caption']) ? todo_sanitize_text_field($params['caption']) : '';
    $file_alt_text = isset($params['alt_text']) ? todo_sanitize_text_field($params['alt_text']) : '';
    
    // 获取图像尺寸（如果是图像）
    $width = 0;
    $height = 0;
    if (strpos($file_mime_type, 'image/') === 0) {
        $image_size = getimagesize($target_file);
        if ($image_size) {
            $width = $image_size[0];
            $height = $image_size[1];
        }
    }
    
    // 准备附件数据
    $attachment_data = [
        'post_title' => $file_title,
        'post_content' => $file_description,
        'post_excerpt' => $file_caption,
        'post_status' => 'inherit',
        'post_type' => 'attachment',
        'post_mime_type' => $file_mime_type,
        'post_author' => get_current_user_id(),
        'post_date' => current_time('mysql'),
        'post_modified' => current_time('mysql')
    ];
    
    // 插入附件
    $query = "INSERT INTO " . TABLE_PREFIX . "posts "
          . "(post_title, post_content, post_excerpt, post_status, post_type, post_mime_type, post_author, post_date, post_modified) "
          . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $result = $tododb->query($query, [
        $attachment_data['post_title'],
        $attachment_data['post_content'],
        $attachment_data['post_excerpt'],
        $attachment_data['post_status'],
        $attachment_data['post_type'],
        $attachment_data['post_mime_type'],
        $attachment_data['post_author'],
        $attachment_data['post_date'],
        $attachment_data['post_modified']
    ]);
    
    if (!$result) {
        // 删除上传的文件
        @unlink($target_file);
        send_api_error(500, '创建媒体记录失败');
    }
    
    $attachment_id = $tododb->insert_id();
    
    // 保存附件元数据
    $file_path = $year_month . '/' . $unique_filename;
    add_post_meta($attachment_id, '_todo_attached_file', $file_path);
    
    if ($width > 0 && $height > 0) {
        add_post_meta($attachment_id, '_width', $width);
        add_post_meta($attachment_id, '_height', $height);
    }
    
    add_post_meta($attachment_id, '_filesize', $file_size);
    
    if (!empty($file_alt_text)) {
        add_post_meta($attachment_id, '_todo_attachment_image_alt', $file_alt_text);
    }
    
    if (strpos($file_mime_type, 'image/') === 0) {
        $metadata = [
            'width' => $width,
            'height' => $height,
            'file' => $file_path,
            'sizes' => []
        ];
        add_post_meta($attachment_id, '_todo_attachment_metadata', $metadata);
    }
    
    // 返回上传的媒体文件
    return api_get_media_item(['id' => $attachment_id]);
}

/**
 * 更新媒体文件
 *
 * @param array $params 请求参数
 * @return array 更新后的媒体文件数据
 */
function api_update_media($params) {
    global $tododb;
    
    // 验证参数
    if (!isset($params['id'])) {
        send_api_error(400, '缺少ID参数');
    }
    
    $media_id = intval($params['id']);
    
    // 检查媒体是否存在
    $query = "SELECT * FROM " . TABLE_PREFIX . "posts WHERE ID = ? AND post_type = 'attachment' LIMIT 1";
    $media = $tododb->query($query, [$media_id]);
    
    if (empty($media)) {
        send_api_error(404, '媒体文件不存在');
    }
    
    // 准备更新数据
    $update_data = [];
    $update_params = [];
    
    if (isset($params['title'])) {
        $update_data[] = 'post_title = ?';
        $update_params[] = sanitize_text_field($params['title']);
    }
    
    if (isset($params['description'])) {
        $update_data[] = 'post_content = ?';
        $update_params[] = sanitize_textarea_field($params['description']);
    }
    
    if (isset($params['caption'])) {
        $update_data[] = 'post_excerpt = ?';
        $update_params[] = sanitize_text_field($params['caption']);
    }
    
    // 添加修改时间
    $update_data[] = 'post_modified = ?';
    $update_params[] = current_time('mysql');
    
    // 添加媒体ID
    $update_params[] = $media_id;
    
    // 执行更新
    if (!empty($update_data)) {
        $query = "UPDATE " . TABLE_PREFIX . "posts SET " . implode(', ', $update_data) . " WHERE ID = ?";
        $result = $tododb->query($query, $update_params);
        
        if (!$result) {
            send_api_error(500, '更新媒体文件失败');
        }
    }
    
    // 更新alt文本
    if (isset($params['alt_text'])) {
        update_post_meta($media_id, '_todo_attachment_image_alt', sanitize_text_field($params['alt_text']));
    }
    
    // 返回更新后的媒体文件
    return api_get_media_item(['id' => $media_id]);
}

/**
 * 删除媒体文件
 *
 * @param array $params 请求参数
 * @return array 操作结果
 */
function api_delete_media($params) {
    global $tododb;
    
    // 验证参数
    if (!isset($params['id'])) {
        send_api_error(400, '缺少ID参数');
    }
    
    $media_id = intval($params['id']);
    
    // 检查媒体是否存在
    $query = "SELECT * FROM " . TABLE_PREFIX . "posts WHERE ID = ? AND post_type = 'attachment' LIMIT 1";
    $media = $tododb->query($query, [$media_id]);
    
    if (empty($media)) {
        send_api_error(404, '媒体文件不存在');
    }
    
    $media = $media[0];
    
    // 获取媒体元数据
    $meta = get_post_meta($media_id);
    $file_path = isset($meta['_todo_attached_file']) ? $meta['_todo_attached_file'] : '';
    
    if (!empty($file_path)) {
        $upload_dir = todo_upload_dir(false);
        $file = $upload_dir['basedir'] . '/' . $file_path;
        
        // 删除原始文件
        if (file_exists($file)) {
            @unlink($file);
        }
        
    }
    
    // 删除媒体记录
    $query = "DELETE FROM " . TABLE_PREFIX . "posts WHERE ID = ?";
    $result = $tododb->query($query, [$media_id]);
    
    if (!$result) {
        send_api_error(500, '删除媒体文件失败');
    }
    
    // 删除媒体元数据
    delete_post_meta($media_id, '_todo_attached_file');
    delete_post_meta($media_id, '_todo_attachment_metadata');
    delete_post_meta($media_id, '_width');
    delete_post_meta($media_id, '_height');
    delete_post_meta($media_id, '_filesize');
    delete_post_meta($media_id, '_todo_attachment_image_alt');
    
    // 返回结果
    return [
        'id' => $media_id,
        'message' => '媒体文件已成功删除'
    ];
}

// 注册API路由
register_api_route('media', 'GET', 'api_get_media');
register_api_route('media/([0-9]+)', 'GET', 'api_get_media_item');
register_api_route('media', 'POST', 'api_upload_media');
register_api_route('media/([0-9]+)', 'PUT', 'api_update_media');
register_api_route('media/([0-9]+)', 'DELETE', 'api_delete_media');
