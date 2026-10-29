<?php
/**
 * 全局文件上传处理器
 * TodoCMS Global File Upload Handler
 * @version 2.1.0
 */

// 检查AJAX请求
if (!defined('DOING_AJAX') || !DOING_AJAX) {
    if (
        !isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'
    ) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Direct access denied']);
        exit;
    }
}

// 启动会话
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!defined('IS_ADMIN')) {
    define('IS_ADMIN', true);
}

// 加载核心文件
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
}
require_once ABSPATH . 'config.php';
require_once ABSPATH . 'core/bootstrap.php';

// 确保使用系统配置的时区（AJAX 请求中显式设置）
if (function_exists('get_option')) {
    $timezone = get_option('timezone_string', 'Asia/Shanghai');
    if (!empty($timezone)) {
        @date_default_timezone_set($timezone);
    }
}

// 初始化全局数据库连接
if (!isset($tododb)) {
    $tododb = new CMS_DB();
}

if (!function_exists('todo_get_option')) {
    function todo_get_option($option_name, $default = false)
    {
        // 简单的选项获取函数
        return $default;
    }
}

if (!function_exists('todo_url')) {
    function todo_url()
    {
        return 'http://' . $_SERVER['HTTP_HOST'];
    }
}

if (!function_exists('todo_home_url')) {
    function todo_home_url()
    {
        return todo_url();
    }
}

if (!function_exists('todo_mkdir_p')) {
    function todo_mkdir_p($target)
    {
        if (!is_dir($target)) {
            mkdir($target, 0755, true);
        }
        return is_dir($target);
    }
}

if (!function_exists('todo_convert_to_bytes')) {
    function todo_convert_to_bytes($val)
    {
        $val = trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        $val = (int) $val;
        switch ($last) {
            case 'g':
                $val *= 1024;
            case 'm':
                $val *= 1024;
            case 'k':
                $val *= 1024;
        }
        return $val;
    }
}

// 定义缺失的 todo_ 前缀函数

// AJAX响应函数
if (!function_exists('todo_ajax_success')) {
    function todo_ajax_success($data = null)
    {
        todo_send_json_success($data);
    }
}

if (!function_exists('todo_get_upload_chunk_size')) {
    function todo_get_upload_chunk_size()
    {
        return todo_get_option('todo_upload_chunk_size', 1024 * 1024); // 默认1MB
    }
}

if (!function_exists('todo_get_max_files_per_upload')) {
    function todo_get_max_files_per_upload()
    {
        return todo_get_option('todo_max_files_per_upload', 10); // 默认10个文件
    }
}

if (!function_exists('todo_get_thumbnail_sizes')) {
    function todo_get_thumbnail_sizes()
    {
        return todo_get_option('todo_thumbnail_sizes', [
            'thumbnail' => ['width' => 150, 'height' => 150, 'crop' => true],
            'medium' => ['width' => 300, 'height' => 300, 'crop' => false],
            'large' => ['width' => 1024, 'height' => 1024, 'crop' => false]
        ]);
    }
}

if (!function_exists('todo_get_media_library')) {
    function todo_get_media_library($args = [])
    {
        global $tododb;

        $defaults = [
            'page' => 1,
            'per_page' => 20,
            'search' => '',
            'type' => '',
            'user_id' => 0
        ];

        $args = array_merge($defaults, $args);

        $where_conditions = ['1=1'];
        $where_values = [];

        // 搜索条件
        if (!empty($args['search'])) {
            $where_conditions[] = "(filename LIKE ? OR original_name LIKE ?)";
            $search_term = '%' . $args['search'] . '%';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }

        // 文件类型过滤
        if (!empty($args['type'])) {
            $where_conditions[] = "file_type LIKE ?";
            $where_values[] = $args['type'] . '%';
        }

        // 用户过滤
        if ($args['user_id'] > 0) {
            $where_conditions[] = "user_id = ?";
            $where_values[] = $args['user_id'];
        }

        $where_clause = implode(' AND ', $where_conditions);

        // 计算总数
        $count_sql = "SELECT COUNT(*) FROM " . TABLE_PREFIX . "media WHERE " . $where_clause;
        $total = $tododb->get_var($count_sql, $where_values);

        // 计算分页
        $offset = ($args['page'] - 1) * $args['per_page'];

        // 获取数据
        $sql = "SELECT * FROM " . TABLE_PREFIX . "media WHERE " . $where_clause .
            " ORDER BY upload_time DESC LIMIT ? OFFSET ?";

        $query_values = array_merge($where_values, [$args['per_page'], $offset]);
        $items = $tododb->get_results($sql, $query_values);

        return [
            'items' => $items ?: [],
            'total' => intval($total),
            'page' => $args['page'],
            'per_page' => $args['per_page'],
            'total_pages' => ceil($total / $args['per_page'])
        ];
    }
}

if (!function_exists('todo_download_remote_file')) {
    function todo_download_remote_file($url)
    {
        $temp_file = tempnam(sys_get_temp_dir(), 'todo_import_');

        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'TodoCMS/2.1 (+' . todo_home_url() . ')',
                'follow_location' => true,
                'max_redirects' => 3
            ]
        ]);

        $data = file_get_contents($url, false, $context);

        if ($data === false) {
            throw new Exception('无法下载远程文件');
        }

        if (file_put_contents($temp_file, $data) === false) {
            throw new Exception('无法保存临时文件');
        }

        return $temp_file;
    }
}

if (!function_exists('todo_ajax_error')) {
    function todo_ajax_error($message = null)
    {
        todo_send_json_error($message);
    }
}

if (!function_exists('todo_generate_safe_filename')) {
    function todo_generate_safe_filename($filename)
    {
        return generate_safe_filename($filename);
    }
}

if (!function_exists('todo_ensure_unique_filename')) {
    function todo_ensure_unique_filename($file_path)
    {
        return ensure_unique_filename($file_path);
    }
}

if (!function_exists('todo_get_upload_directory')) {
    function todo_get_upload_directory()
    {
        $upload_dir = ABSPATH . 'content/uploads';
        $year = date('Y');
        $month = date('m');
        $full_path = $upload_dir . '/' . $year . '/' . $month;

        if (!file_exists($full_path)) {
            todo_mkdir_p($full_path);
        }

        return $full_path;
    }
}

if (!function_exists('todo_get_upload_url')) {
    function todo_get_upload_url()
    {
        $year = date('Y');
        $month = date('m');
        return todo_url() . '/content/uploads/' . $year . '/' . $month;
    }
}

if (!function_exists('todo_get_max_upload_size')) {
    function todo_get_max_upload_size()
    {
        if (class_exists('TodoUploadConfig')) {
            return TodoUploadConfig::get_max_upload_size();
        }
        $app_limit = null;
        if (function_exists('get_option')) {
            $mb = get_option('max_upload_size', null);
            if ($mb !== null && is_numeric($mb) && (int) $mb > 0) {
                $app_limit = (int) $mb * 1024 * 1024;
            }
        }
        $max_upload = todo_convert_to_bytes(ini_get('upload_max_filesize'));
        $max_post = todo_convert_to_bytes(ini_get('post_max_size'));
        $memory_limit = todo_convert_to_bytes(ini_get('memory_limit'));
        $server_limit = min($max_upload, $max_post, $memory_limit);
        if ($app_limit !== null) {
            return min($server_limit, $app_limit);
        }
        return $server_limit;
    }
}

if (!function_exists('todo_get_allowed_file_types')) {
    function todo_get_allowed_file_types()
    {
        if (class_exists('TodoUploadConfig')) {
            return TodoUploadConfig::get_allowed_types();
        }
        return [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/pdf',
            'text/plain',
            'application/zip',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ];
    }
}

if (!function_exists('todo_is_image_file')) {
    function todo_is_image_file($input)
    {
        $mime = null;
        if (is_string($input) && @is_file($input)) {
            if (function_exists('todo_get_real_mime_type')) {
                $mime = todo_get_real_mime_type($input);
            }
            if (!$mime && function_exists('mime_content_type')) {
                $mime = @mime_content_type($input);
            }
        } else {
            $mime = $input;
        }
        return is_string($mime) && stripos($mime, 'image/') === 0;
    }
}

if (!function_exists('todo_save_media_to_database')) {
    function todo_save_media_to_database($data)
    {
        global $tododb;

        $table = TABLE_PREFIX . 'media';

        $insert_data = [
            'filename' => $data['filename'],
            'original_name' => $data['original_name'],
            'file_path' => $data['file_path'],
            'file_url' => $data['file_url'],
            'file_type' => $data['file_type'],
            'file_size' => $data['file_size'],
            'source_url' => $data['source_url'] ?? null,
            'upload_time' => $data['upload_time'],
            'user_id' => $data['user_id']
        ];

        $result = $tododb->insert($table, $insert_data);

        if ($result) {
            return $tododb->insert_id();
        }

        return false;
    }
}

if (!function_exists('todo_current_time')) {
    function todo_current_time($type = 'mysql')
    {
        switch ($type) {
            case 'mysql':
                return date('Y-m-d H:i:s');
            case 'timestamp':
                return time();
            default:
                return date('Y-m-d H:i:s');
        }
    }
}

if (!function_exists('todo_get_current_user_id')) {
    function todo_get_current_user_id()
    {
        // 使用 Todo_Session_Manager 获取用户ID
        if (class_exists('Todo_Session_Manager')) {
            Todo_Session_Manager::init();
            if (defined('IS_ADMIN') && IS_ADMIN) {
                return Todo_Session_Manager::get_admin_user_id() ?: 0;
            } else {
                return Todo_Session_Manager::get_frontend_user_id() ?: 0;
            }
        }
        return 0;
    }
}

if (!function_exists('todo_get_client_ip')) {
    function todo_get_client_ip()
    {
        return get_client_ip();
    }
}

if (!function_exists('todo_format_file_size')) {
    function todo_format_file_size($bytes, $precision = 2)
    {
        if (class_exists('TodoUploadConfig')) {
            return TodoUploadConfig::format_file_size($bytes);
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}

if (!function_exists('todo_convert_to_bytes')) {
    function todo_convert_to_bytes($value)
    {
        $value = trim($value);
        $last = strtolower($value[strlen($value) - 1]);
        $value = (int) $value;

        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }
}

if (!function_exists('todo_mkdir_p')) {
    function todo_mkdir_p($target)
    {
        if (file_exists($target)) {
            return @is_dir($target);
        }

        // 尝试创建目录
        if (@mkdir($target, 0755, true)) {
            return true;
        }

        // 如果失败，尝试逐级创建
        $target_parent = dirname($target);
        if ($target_parent !== $target) {
            if (todo_mkdir_p($target_parent)) {
                return @mkdir($target, 0755);
            }
        }

        return false;
    }
}

// 获取动作
$action = sanitize_text_field($_POST['action'] ?? '');

// 定义不需要登录权限的动作
$public_actions = ['todo_get_upload_config'];

// 验证用户权限（除了公开动作）
if (!in_array($action, $public_actions)) {
    // 使用 Todo_Session_Manager 检查后台登录状态
    if (!class_exists('Todo_Session_Manager')) {
        if (!defined('TODO_LOADED')) {
            define('TODO_LOADED', true);
        }
    }
    if (class_exists('Todo_Session_Manager')) {
        Todo_Session_Manager::init();
    }
    
    if (!class_exists('Todo_Session_Manager') || !Todo_Session_Manager::is_admin_logged_in()) {
        todo_ajax_error('权限不足');
    }
}

// 路由处理
switch ($action) {
    case 'todo_upload_files':
        handle_file_upload();
        break;
    case 'todo_get_upload_config':
        handle_get_config();
        break;
    case 'todo_get_media_library':
        handle_get_media_library();
        break;
    case 'todo_import_from_url':
        handle_url_import();
        break;
    default:
        todo_ajax_error('无效的操作');
}

/**
 * 处理文件上传
 */
function handle_file_upload()
{
    // CSRF验证
    if (!Todo_Security_Manager::verify_csrf_token($_POST['_csrf_token'] ?? '', 'todo_upload_files')) {
        todo_ajax_error('安全验证失败');
    }

    if (function_exists('get_option') && !get_option('enable_upload', true)) {
        todo_ajax_error('文件上传功能已被管理员禁用');
    }
    if ((int) todo_get_max_upload_size() <= 0) {
        todo_ajax_error('文件上传功能已被管理员禁用');
    }

    // 检查文件
    if (empty($_FILES['files'])) {
        todo_ajax_error('没有上传文件');
    }

    $files = $_FILES['files'];
    $field_name = sanitize_text_field($_POST['field_name'] ?? '');
    $results = [];

    // 初始化上传日志
    $logger = new TodoUploadLogger();

    // 处理多文件上传
    $file_count = is_array($files['name']) ? count($files['name']) : 1;

    for ($i = 0; $i < $file_count; $i++) {
        $file = [
            'name' => is_array($files['name']) ? $files['name'][$i] : $files['name'],
            'type' => is_array($files['type']) ? $files['type'][$i] : $files['type'],
            'tmp_name' => is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'],
            'error' => is_array($files['error']) ? $files['error'][$i] : $files['error'],
            'size' => is_array($files['size']) ? $files['size'][$i] : $files['size']
        ];

        $result = process_single_file($file, $field_name, $logger);
        $results[] = $result;
    }

    todo_ajax_success($results);
}

/**
 * 处理单个文件
 */
function process_single_file($file, $field_name, $logger)
{
    $start_time = microtime(true);

    try {
        // 检查上传错误
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception(get_upload_error_message($file['error']));
        }

        // 验证文件
        $validation = validate_uploaded_file($file);
        if (!$validation['valid']) {
            throw new Exception($validation['error']);
        }

        // 生成安全的文件名
        $filename = todo_generate_safe_filename($file['name']);

        // 确定上传目录
        $upload_dir = todo_get_upload_directory();
        $file_path = $upload_dir . '/' . $filename;

        // 确保文件名唯一
        $file_path = todo_ensure_unique_filename($file_path);
        $filename = basename($file_path);

        // realpath 前缀校验，确保写入路径合法
        $uploads_real = realpath(dirname($upload_dir));
        $dest_parent_real = realpath(dirname($file_path));
        if ($uploads_real === false || $dest_parent_real === false || strpos($dest_parent_real, $uploads_real) !== 0) {
            throw new Exception('非法上传路径');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new Exception('非法上传源');
        }

        // 移动文件
        if (!move_uploaded_file($file['tmp_name'], $file_path)) {
            throw new Exception('文件保存失败');
        }

        // 设置文件权限
        chmod($file_path, 0644);

        // 云存储推送
        $provider_type = 'local';
        if (class_exists('Todo_Storage_Manager')) {
            $storage_mgr = Todo_Storage_Manager::get_instance();
            $provider_type = $storage_mgr->get_provider_type();
            
            if (!$storage_mgr->is_local()) {
                // 计算相对路径供云端存储
                $relative_path = str_replace(TODO_ROOT . '/', '', $file_path);
                
                // 上传主文件
                $storage_mgr->upload($file_path, $relative_path, $file['type']);
            }
        }

        // 保存到数据库
        $media_id = todo_save_media_to_database([
            'filename' => $filename,
            'original_name' => $file['name'],
            'file_path' => str_replace(TODO_ROOT . '/', '', $file_path),
            'file_url' => function_exists('todo_media_url_to_relative') ? todo_media_url_to_relative(str_replace(TODO_ROOT . '/', '', $file_path)) : str_replace(TODO_ROOT . '/', '', $file_path),
            'file_type' => $file['type'],
            'file_size' => $file['size'],
            'field_name' => $field_name,
            'upload_time' => todo_current_time('mysql'),
            'user_id' => todo_get_current_user_id()
        ]);

        if ($media_id && $provider_type !== 'local' && class_exists('Todo_Media_Manager')) {
            Todo_Media_Manager::save_media_meta($media_id, 'storage_provider', $provider_type);
        }

        $end_time = microtime(true);
        $processing_time = round(($end_time - $start_time) * 1000, 2);

        // 记录成功日志
        $logger->log_upload([
            'media_id' => $media_id,
            'filename' => $filename,
            'original_name' => $file['name'],
            'file_size' => $file['size'],
            'file_type' => $file['type'],
            'field_name' => $field_name,
            'status' => 'success',
            'processing_time' => $processing_time,
            'user_id' => todo_get_current_user_id(),
            'ip_address' => todo_get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);

        return [
            'success' => true,
            'id' => $media_id,
            'filename' => $filename,
            'original_name' => $file['name'],
            'url' => todo_get_upload_url() . '/' . $filename,
            'filetype' => $file['type'],
            'filesize' => todo_format_file_size($file['size']),
            'processing_time' => $processing_time . 'ms'
        ];

    } catch (Exception $e) {
        $end_time = microtime(true);
        $processing_time = round(($end_time - $start_time) * 1000, 2);

        // 记录错误日志
        $logger->log_upload([
            'filename' => $file['name'] ?? 'unknown',
            'original_name' => $file['name'] ?? 'unknown',
            'file_size' => $file['size'] ?? 0,
            'file_type' => $file['type'] ?? 'unknown',
            'field_name' => $field_name,
            'status' => 'error',
            'error_message' => $e->getMessage(),
            'processing_time' => $processing_time,
            'user_id' => todo_get_current_user_id(),
            'ip_address' => todo_get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);

        return [
            'success' => false,
            'filename' => $file['name'],
            'error' => $e->getMessage()
        ];
    }
}

/**
 * 获取上传配置
 */
function handle_get_config()
{
    // 暂时跳过 CSRF 验证，直接返回配置
    $enable_upload = true;
    if (function_exists('get_option')) {
        $enable_upload = (bool) get_option('enable_upload', true);
    }
    $max_file_size = class_exists('TodoUploadConfig') ? (int) TodoUploadConfig::get_max_upload_size() : (int) todo_get_max_upload_size();
    if (!$enable_upload) {
        $max_file_size = 0;
    }
    $config = [
        'enableUpload' => $enable_upload,
        'maxFileSize' => $max_file_size,
        'allowedTypes' => class_exists('TodoUploadConfig') ? TodoUploadConfig::get_allowed_types() : todo_get_allowed_file_types(),
        'uploadUrl' => todo_admin_url('admin-ajax.php'),
        'chunkSize' => todo_get_upload_chunk_size(),
        'maxFiles' => todo_get_max_files_per_upload(),
        'autoResize' => todo_get_option('todo_auto_resize_images', true),
        'imageQuality' => todo_get_option('todo_image_quality', 85),
        'generateThumbnails' => todo_get_option('todo_generate_thumbnails', true),
        'thumbnailSizes' => todo_get_thumbnail_sizes()
    ];

    todo_ajax_success($config);
}

/**
 * 获取媒体库
 */
function handle_get_media_library()
{
    // CSRF验证
    if (!Todo_Security_Manager::verify_csrf_token($_POST['_csrf_token'] ?? '', 'todo_get_media_library')) {
        todo_ajax_error('安全验证失败');
    }

    $page = intval($_POST['page'] ?? 1);
    $per_page = intval($_POST['per_page'] ?? 20);
    $search = sanitize_text_field($_POST['search'] ?? '');
    $type = sanitize_text_field($_POST['type'] ?? '');

    $args = [
        'page' => $page,
        'per_page' => $per_page,
        'search' => $search,
        'type' => $type,
        'user_id' => todo_get_current_user_id()
    ];

    $result = todo_get_media_library($args);

    todo_ajax_success($result);
}

/**
 * 处理URL导入
 */
function handle_url_import()
{
    // CSRF验证
    if (!Todo_Security_Manager::verify_csrf_token($_POST['_csrf_token'] ?? '', 'todo_import_from_url')) {
        todo_ajax_error('安全验证失败');
    }

    $url = esc_url_raw($_POST['url'] ?? '');

    if (empty($url)) {
        todo_ajax_error('URL不能为空');
    }

    // 验证URL
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        todo_ajax_error('无效的URL格式');
    }

    // SSRF 安全检查
    if (!todo_is_public_url($url)) {
        todo_ajax_error('受限的请求地址：禁止访问私有网络或本地资源。');
    }

    // 检查URL是否可访问
    $headers = @get_headers($url, 1);
    if (!$headers || strpos($headers[0], '200') === false) {
        todo_ajax_error('无法访问该URL');
    }

    // 获取文件信息
    $file_info = pathinfo(parse_url($url, PHP_URL_PATH));
    $filename = $file_info['basename'] ?? 'imported_file';
    $extension = $file_info['extension'] ?? '';

    // 验证文件类型
    $mime_type = todo_get_mime_type_from_extension($extension);
    if (!todo_is_allowed_file_type($mime_type)) {
        todo_ajax_error('不支持的文件类型');
    }

    try {
        // 下载文件
        $temp_file = todo_download_remote_file($url);

        // ── 安全增强：对下载内容做真实 MIME 二次验证 ──
        // 仅凭 URL 扩展名判断不可信，必须读取文件实际内容
        $real_mime = null;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $real_mime = finfo_file($finfo, $temp_file);
            finfo_close($finfo);
        } elseif (function_exists('mime_content_type')) {
            $real_mime = mime_content_type($temp_file);
        }
        if ($real_mime) {
            // 绝对禁止任何服务端可执行的 MIME 类型
            $forbidden_mimes = [
                'application/x-php', 'application/php', 'text/x-php', 'text/php',
                'application/x-httpd-php', 'application/x-phar',
                'text/x-shellscript', 'application/x-sh', 'application/x-perl',
                'application/x-python', 'application/javascript',
                'text/x-python', 'text/x-perl',
            ];
            foreach ($forbidden_mimes as $fm) {
                if (stripos($real_mime, $fm) !== false) {
                    @unlink($temp_file);
                    throw new Exception('检测到危险文件内容，拒绝保存：' . $real_mime);
                }
            }
            // 与允许类型白名单对比
            if (!todo_is_allowed_file_type($real_mime)) {
                @unlink($temp_file);
                throw new Exception('下载文件的真实类型不被允许：' . $real_mime);
            }
        }
        // ── 二次验证结束 ──

        // 生成安全文件名
        $safe_filename = todo_generate_safe_filename($filename);

        // 移动到上传目录
        $upload_dir = todo_get_upload_directory();
        $file_path = $upload_dir . '/' . $safe_filename;
        $file_path = todo_ensure_unique_filename($file_path);

        if (!rename($temp_file, $file_path)) {
            throw new Exception('文件保存失败');
        }

        // 获取文件大小
        $file_size = filesize($file_path);

        // 保存到数据库
        $media_id = todo_save_media_to_database([
            'filename' => basename($file_path),
            'original_name' => $filename,
            'file_path' => $file_path,
            'file_url' => function_exists('todo_media_url_to_relative') ? todo_media_url_to_relative(str_replace(TODO_ROOT . '/', '', $file_path)) : str_replace(TODO_ROOT . '/', '', $file_path),
            'file_type' => $mime_type,
            'file_size' => $file_size,
            'source_url' => $url,
            'upload_time' => todo_current_time('mysql'),
            'user_id' => todo_get_current_user_id()
        ]);

        // 记录日志
        $logger = new TodoUploadLogger();
        $logger->log_upload([
            'media_id' => $media_id,
            'filename' => basename($file_path),
            'original_name' => $filename,
            'file_size' => $file_size,
            'file_type' => $mime_type,
            'source_url' => $url,
            'status' => 'success',
            'method' => 'url_import',
            'user_id' => todo_get_current_user_id(),
            'ip_address' => todo_get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);

        $result = [
            'id' => $media_id,
            'filename' => basename($file_path),
            'url' => todo_get_upload_url() . '/' . basename($file_path),
            'type' => $mime_type,
            'size' => todo_format_file_size($file_size)
        ];

        todo_ajax_success($result);

    } catch (Exception $e) {
        // 清理临时文件
        if (isset($temp_file) && file_exists($temp_file)) {
            unlink($temp_file);
        }

        todo_ajax_error($e->getMessage());
    }
}

/**
 * 验证上传文件
 */
function validate_uploaded_file($file)
{
    // 检查系统文件和危险文件名
    $filename = basename($file['name']);
    $dangerous_files = ['.DS_Store', 'Thumbs.db', '.htaccess', '.gitignore', '.git', '.svn', '.idea'];

    // 检查特定的危险文件名
    if (in_array($filename, $dangerous_files)) {
        return [
            'valid' => false,
            'error' => '不允许上传系统文件: ' . $filename
        ];
    }

    // 检查以点开头的隐藏文件
    if (strpos($filename, '.') === 0) {
        return [
            'valid' => false,
            'error' => '不允许上传隐藏文件: ' . $filename
        ];
    }

    // 检查以波浪号结尾的临时文件
    if (substr($filename, -1) === '~') {
        return [
            'valid' => false,
            'error' => '不允许上传临时文件: ' . $filename
        ];
    }

    // 检查临时文件扩展名
    $temp_extensions = ['.tmp', '.temp'];
    foreach ($temp_extensions as $ext) {
        if (substr($filename, -strlen($ext)) === $ext) {
            return [
                'valid' => false,
                'error' => '不允许上传临时文件: ' . $filename
            ];
        }
    }

    // 检查文件大小
    $max_size = todo_get_max_upload_size();
    if ((int) $max_size <= 0) {
        return [
            'valid' => false,
            'error' => '文件上传功能已被管理员禁用'
        ];
    }
    if ($file['size'] > $max_size) {
        return [
            'valid' => false,
            'error' => '文件大小超过限制 (' . todo_format_file_size($max_size) . ')'
        ];
    }

    // 检查文件类型
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!todo_is_allowed_file_type($file['type'], $file_ext)) {
        return [
            'valid' => false,
            'error' => '不支持的文件类型: ' . $file['type']
        ];
    }

    // 检查文件内容（防止伪造）
    $real_mime = todo_get_real_mime_type($file['tmp_name']);
    $browser_mime = $file['type'];
    $is_valid_mime = ($real_mime === $browser_mime);

    // 特殊情况处理：Office Open XML 格式 (xlsx, docx, pptx) 在服务器端常被识别为 application/zip
    if (!$is_valid_mime && $real_mime === 'application/zip') {
        if (TodoUploadConfig::is_type_allowed('application/zip', $file_ext)) {
            $is_valid_mime = true;
        }
    }

    // 允许 text/plain 替代其他文本类型（如 CSV）
    if (!$is_valid_mime && $real_mime === 'text/plain' && strpos($browser_mime, 'text/') === 0) {
        $is_valid_mime = true;
    }

    if (!$is_valid_mime) {
        return [
            'valid' => false,
            'error' => sprintf('文件类型验证失败 (预期: %s, 检测到: %s)', $browser_mime, $real_mime)
        ];
    }

    // 安全扫描
    if (todo_is_potentially_dangerous_file($file)) {
        return [
            'valid' => false,
            'error' => '检测到潜在危险文件'
        ];
    }

    return ['valid' => true];
}

/**
 * 生成安全文件名
 */
function generate_safe_filename($filename)
{
    // 获取文件扩展名
    $pathinfo = pathinfo($filename);
    $name = $pathinfo['filename'];
    $extension = $pathinfo['extension'] ?? '';

    // 清理文件名
    $name = todo_sanitize_file_name($name);
    // 支持中文文件名，只移除危险字符
    $name = preg_replace('/[\/<>:"|?*\\]/', '', $name ?? '');
    $name = trim($name, '.-');

    // 如果文件名为空，生成随机名称
    if (empty($name)) {
        $name = 'file_' . uniqid();
    }

    // 限制长度
    $name = substr($name, 0, 100);

    // 添加时间戳避免冲突
    $name .= '_' . time();

    return $extension ? $name . '.' . $extension : $name;
}

/**
 * 确保文件名唯一
 */
function ensure_unique_filename($file_path)
{
    $pathinfo = pathinfo($file_path);
    $dir = $pathinfo['dirname'];
    $name = $pathinfo['filename'];
    $extension = $pathinfo['extension'] ?? '';

    $counter = 1;
    $new_path = $file_path;

    while (file_exists($new_path)) {
        $new_name = $name . '_' . $counter;
        $new_path = $dir . '/' . $new_name . ($extension ? '.' . $extension : '');
        $counter++;
    }

    return $new_path;
}

/**
 * 获取上传错误信息
 */
function get_upload_error_message($error_code)
{
    $errors = [
        UPLOAD_ERR_INI_SIZE => '文件大小超过服务器限制',
        UPLOAD_ERR_FORM_SIZE => '文件大小超过表单限制',
        UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
        UPLOAD_ERR_NO_FILE => '没有文件被上传',
        UPLOAD_ERR_NO_TMP_DIR => '找不到临时文件夹',
        UPLOAD_ERR_CANT_WRITE => '文件写入失败',
        UPLOAD_ERR_EXTENSION => '文件上传被扩展程序阻止'
    ];

    return $errors[$error_code] ?? '未知上传错误';
}

/**
 * 下载远程文件
 */
function download_remote_file($url)
{
    $temp_file = tempnam(sys_get_temp_dir(), 'todo_import_');

    $context = stream_context_create([
        'http' => [
            'timeout' => 30,
            'user_agent' => 'TodoCMS/2.1 (+' . todo_home_url() . ')'
        ]
    ]);

    $data = file_get_contents($url, false, $context);

    if ($data === false) {
        throw new Exception('无法下载文件');
    }

    if (file_put_contents($temp_file, $data) === false) {
        throw new Exception('临时文件保存失败');
    }

    return $temp_file;
}

/**
 * 获取客户端IP
 */
function get_client_ip()
{
    $ip_keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];

    foreach ($ip_keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = $_SERVER[$key];
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip ?? '')[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }

    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}
// 修复 AJAX 响应函数
if (!function_exists('todo_ajax_success')) {
    function todo_ajax_success($data = null)
    {
        todo_send_json_success($data);
    }
}

if (!function_exists('todo_ajax_error')) {
    function todo_ajax_error($message = null)
    {
        todo_send_json_error($message);
    }
}

// 修复文件处理函数
if (!function_exists('todo_generate_safe_filename')) {
    function todo_generate_safe_filename($filename)
    {
        return generate_safe_filename($filename);
    }
}

if (!function_exists('todo_ensure_unique_filename')) {
    function todo_ensure_unique_filename($file_path)
    {
        return ensure_unique_filename($file_path);
    }
}

if (!function_exists('todo_get_upload_directory')) {
    function todo_get_upload_directory()
    {
        $upload_dir = ABSPATH . 'content/uploads';
        $year = date('Y');
        $month = date('m');
        $full_path = $upload_dir . '/' . $year . '/' . $month;

        if (!file_exists($full_path)) {
            todo_mkdir_p($full_path);
        }

        return $full_path;
    }
}

if (!function_exists('todo_get_upload_url')) {
    function todo_get_upload_url()
    {
        $year = date('Y');
        $month = date('m');
        return todo_url() . '/content/uploads/' . $year . '/' . $month;
    }
}

if (!function_exists('todo_get_max_upload_size')) {
    function todo_get_max_upload_size()
    {
        if (class_exists('TodoUploadConfig')) {
            return TodoUploadConfig::get_max_upload_size();
        }
        $max_upload = todo_convert_to_bytes(ini_get('upload_max_filesize'));
        $max_post = todo_convert_to_bytes(ini_get('post_max_size'));
        $memory_limit = todo_convert_to_bytes(ini_get('memory_limit'));
        return min($max_upload, $max_post, $memory_limit);
    }
}

if (!function_exists('todo_get_allowed_file_types')) {
    function todo_get_allowed_file_types()
    {
        if (class_exists('TodoUploadConfig')) {
            return TodoUploadConfig::get_allowed_types();
        }
        return [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/pdf',
            'text/plain',
            'application/zip',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ];
    }
}

if (!function_exists('todo_is_allowed_file_type')) {
    function todo_is_allowed_file_type($mime_type, $extension = '')
    {
        if (class_exists('TodoUploadConfig')) {
            return TodoUploadConfig::is_type_allowed($mime_type, $extension);
        }
        return in_array($mime_type, todo_get_allowed_file_types());
    }
}

if (!function_exists('todo_save_media_to_database')) {
    function todo_save_media_to_database($data)
    {
        global $tododb;

        $table = TABLE_PREFIX . 'media';

        $insert_data = [
            'filename' => $data['filename'],
            'original_name' => $data['original_name'],
            'file_path' => $data['file_path'],
            'file_url' => $data['file_url'],
            'file_type' => $data['file_type'],
            'file_size' => $data['file_size'],
            'source_url' => $data['source_url'] ?? null,
            'upload_time' => $data['upload_time'],
            'user_id' => $data['user_id']
        ];

        $result = $tododb->insert($table, $insert_data);

        if ($result) {
            return $tododb->insert_id();
        }

        return false;
    }
}

if (!function_exists('todo_current_time')) {
    function todo_current_time($type = 'mysql')
    {
        switch ($type) {
            case 'mysql':
                return date('Y-m-d H:i:s');
            case 'timestamp':
                return time();
            default:
                return date('Y-m-d H:i:s');
        }
    }
}

if (!function_exists('todo_get_current_user_id')) {
    function todo_get_current_user_id()
    {
        // 使用 Todo_Session_Manager 获取用户ID
        if (class_exists('Todo_Session_Manager')) {
            Todo_Session_Manager::init();
            if (defined('IS_ADMIN') && IS_ADMIN) {
                return Todo_Session_Manager::get_admin_user_id() ?: 0;
            } else {
                return Todo_Session_Manager::get_frontend_user_id() ?: 0;
            }
        }
        return 0;
    }
}

if (!function_exists('todo_get_client_ip')) {
    function todo_get_client_ip()
    {
        return get_client_ip();
    }
}

if (!function_exists('todo_format_file_size')) {
    function todo_format_file_size($bytes, $precision = 2)
    {
        if (class_exists('TodoUploadConfig')) {
            return TodoUploadConfig::format_file_size($bytes);
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}

if (!function_exists('todo_convert_to_bytes')) {
    function todo_convert_to_bytes($value)
    {
        $value = trim($value);
        $last = strtolower($value[strlen($value) - 1]);
        $value = (int) $value;

        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }
}

if (!function_exists('todo_get_mime_type_from_extension')) {
    function todo_get_mime_type_from_extension($extension)
    {
        $map = function_exists('todo_supported_mime_types_flat') ? todo_supported_mime_types_flat() : [];
        $extension = strtolower(trim($extension));
        return $map[$extension] ?? 'application/octet-stream';
    }
}

if (!function_exists('todo_get_real_mime_type')) {
    function todo_get_real_mime_type($file_path)
    {
        // 优先 finfo
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime_type = finfo_file($finfo, $file_path);
                finfo_close($finfo);
                if ($mime_type) {
                    // 移除 charset 信息（如 ; charset=binary）
                    if (strpos($mime_type, ';') !== false) {
                        $mime_type = explode(';', $mime_type)[0];
                    }
                    return strtolower(trim($mime_type));
                }
            }
        }
        // 其次图片信息
        if (function_exists('getimagesize')) {
            $info = @getimagesize($file_path);
            if ($info && isset($info['mime'])) {
                return $info['mime'];
            }
        }
        // 回退
        if (function_exists('mime_content_type')) {
            return @mime_content_type($file_path);
        }
        return 'application/octet-stream';
    }
}

if (!function_exists('todo_is_potentially_dangerous_file')) {
    function todo_is_potentially_dangerous_file($file)
    {
        $dangerous_extensions = ['php', 'phtml', 'php3', 'php4', 'php5', 'pl', 'py', 'jsp', 'asp', 'sh', 'cgi'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        return in_array($extension, $dangerous_extensions);
    }
}

if (!function_exists('todo_sanitize_file_name')) {
    function todo_sanitize_file_name($filename)
    {
        if (function_exists('sanitize_file_name')) {
            return sanitize_file_name($filename);
        }
        $info = pathinfo($filename ?? '');
        $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
        $base = isset($info['filename']) ? $info['filename'] : $filename;
        $base = preg_replace('/[<>:"\/\\|?*\x00-\x1F]/u', '', $base ?? '');
        $base = trim($base, '.-_');
        return ($base !== '' ? $base : 'file_' . date('YmdHis')) . $ext;
    }
}

if (!function_exists('todo_home_url')) {
    function todo_home_url()
    {
        return todo_url();
    }
}

if (!function_exists('todo_mkdir_p')) {
    function todo_mkdir_p($target)
    {
        if (file_exists($target)) {
            return @is_dir($target);
        }

        // 尝试创建目录
        if (@mkdir($target, 0755, true)) {
            return true;
        }

        // 如果失败，尝试逐级创建
        $target_parent = dirname($target);
        if ($target_parent !== $target) {
            if (todo_mkdir_p($target_parent)) {
                return @mkdir($target, 0755);
            }
        }

        return false;
    }
}

// 添加缺失的配置函数
if (!function_exists('todo_get_max_upload_size')) {
    function todo_get_max_upload_size()
    {
        if (class_exists('TodoUploadConfig')) {
            return TodoUploadConfig::get_max_upload_size();
        }
        // 回退方案
        $max_upload = todo_convert_to_bytes(ini_get('upload_max_filesize'));
        $max_post = todo_convert_to_bytes(ini_get('post_max_size'));
        $memory_limit = todo_convert_to_bytes(ini_get('memory_limit'));
        return min($max_upload, $max_post, $memory_limit);
    }
}

if (!function_exists('todo_get_allowed_file_types')) {
    function todo_get_allowed_file_types()
    {
        if (class_exists('TodoUploadConfig')) {
            return TodoUploadConfig::get_allowed_types();
        }
        // 回退：返回常见 MIME 类型数组
        return [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/pdf',
            'text/plain',
            'application/zip',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ];
    }
}

if (!function_exists('todo_get_upload_chunk_size')) {
    function todo_get_upload_chunk_size()
    {
        return 1024 * 1024; // 1MB
    }
}

if (!function_exists('todo_get_max_files_per_upload')) {
    function todo_get_max_files_per_upload()
    {
        return 10;
    }
}

if (!function_exists('todo_get_thumbnail_sizes')) {
    function todo_get_thumbnail_sizes()
    {
        return [
            'thumbnail' => ['width' => 150, 'height' => 150],
            'medium' => ['width' => 300, 'height' => 300],
            'large' => ['width' => 800, 'height' => 600]
        ];
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '')
    {
        return todo_admin_url($path);
    }
}

?>
