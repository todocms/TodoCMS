<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}

// 开启输出缓冲
ob_start();

$t_page_start = microtime(true);

/**
 * 管理后台新建文章页面
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 定义 TODO_ADMIN_PATH 常量
if (!defined('TODO_ADMIN_PATH')) {
    define('TODO_ADMIN_PATH', ADMIN_DIR);
}

// 加载核心引导文件
require_once CORE_DIR . '/bootstrap.php';
$t_bootstrap = microtime(true);
if (class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::add_metric('post_new_bootstrap', 'require bootstrap.php', ($t_bootstrap - $t_page_start) * 1000);
}

require_once CORE_DIR . '/functions/auth.php';
require_once CORE_DIR . '/functions/core.php';
require_once CORE_DIR . '/functions/content.php';
require_once CORE_DIR . '/functions/media.php';
require_once CORE_DIR . '/functions/security.php';
require_once CORE_DIR . '/functions/formatting.php';
require_once ADMIN_DIR . '/includes/file.php';
require_once ADMIN_DIR . '/includes/todo-functions.php';
$t_functions = microtime(true);
if (class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::add_metric('post_new_functions', 'require functions', ($t_functions - $t_bootstrap) * 1000);
}

// 加载模型管理器和相关类
require_once ADMIN_DIR . '/components/model-fields-component.php';

// 初始化模型管理器
Todo_Model_Manager::init();

// 初始化模型字段管理器
$model_fields_manager = new Todo_Model_Fields_Manager();

// 引入特色图片组件
require_once TODO_ADMIN_PATH . '/components/featured-images-component.php';

// 引入层级分类选择器组件
require_once ADMIN_DIR . '/components/hierarchical-categories-selector.php';

// 引入推荐标签组件
require_once ADMIN_DIR . '/components/suggested-tags-component.php';

$t_components = microtime(true);
if (class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::add_metric('post_new_components', 'init components', ($t_components - $t_functions) * 1000);
}

/**
 * 格式化上传路径（UEditor风格）
 * @param string $format
 * @param string $filename
 * @return string
 */
function format_upload_path($format, $filename = '')
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

// 处理特色图片上传（使用UEditor风格路径）
function handle_featured_image_upload($file)
{
    if (function_exists('get_option') && !get_option('enable_upload', true)) {
        return array('error' => '文件上传功能已被管理员禁用');
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
        $parse_size = function ($size) {
            $unit = preg_replace('/[^bkmgtpezy]/i', '', $size ?? '');
            $num = preg_replace('/[^0-9\.]/', '', $size ?? '');
            $num = ($num === '' ? 0 : (float) $num);
            if ($unit) {
                return (int) round($num * pow(1024, stripos('bkmgtpezy', $unit[0])));
            }
            return (int) round($num);
        };
        $max_size = min($parse_size(ini_get('upload_max_filesize')), $parse_size(ini_get('post_max_size')));
    }
    if ((int) $max_size <= 0) {
        return array('error' => '文件上传功能已被管理员禁用');
    }

    // 检查文件类型
    $allowed_types = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
    if (!in_array($file['type'], $allowed_types)) {
        return array('error' => '只允许上传图片文件（JPG, PNG, GIF, WebP）');
    }

    if ($file['size'] > $max_size) {
        $max_mb = round($max_size / (1024 * 1024), 2);
        return array('error' => '文件大小不能超过' . $max_mb . 'MB');
    }

    // 使用UEditor风格的路径格式：/content/uploads/{yyyy}/{mm}/{dd}/{time}{rand:6}
    $path_format = "/content/uploads/{yyyy}/{mm}/{dd}/{time}{rand:6}";
    $relative_path = format_upload_path($path_format, $file['name']);

    // 构建完整的文件路径（锚定至 uploads 并进行前缀校验）
    $basedir = ABSPATH . 'content/uploads';
    if (!file_exists($basedir)) {
        mkdir($basedir, 0755, true);
    }
    $subdir_rel = trim(preg_replace('#^/?content/uploads/?#', '', dirname($relative_path)), '/');
    $upload_dir = rtrim($basedir, '/') . ($subdir_rel !== '' ? '/' . $subdir_rel : '');
    $filename = basename($relative_path);
    $target_file = rtrim($upload_dir, '/') . '/' . $filename;

    // 创建目录
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    // realpath 前缀校验
    $basedir_real = realpath($basedir);
    $upload_dir_real = realpath($upload_dir);
    if ($basedir_real === false || $upload_dir_real === false || strpos($upload_dir_real, $basedir_real) !== 0) {
        return array('error' => '非法保存路径');
    }

    // 移动上传的文件
    if (!is_uploaded_file($file['tmp_name'])) {
        return array('error' => '非法上传源');
    }
    if (move_uploaded_file($file['tmp_name'], $target_file)) {
        // 设置文件权限
        chmod($target_file, 0644);

        return array(
            'file' => $target_file,
            'url' => TODO_URL . $relative_path,
            'type' => $file['type']
        );
    } else {
        return array('error' => '文件上传失败');
    }
}

// 简化的media_handle_upload函数
if (!function_exists('media_handle_upload')) {
    function media_handle_upload($file_id, $post_id, $post_data = array(), $overrides = array())
    {
        if (!isset($_FILES[$file_id])) {
            return new TODO_Error('upload_error', '没有找到上传文件');
        }

        $file = $_FILES[$file_id];

        // 特色图片上传到 /upload/thumb 目录
        if ($file_id === 'featured_image') {
            $uploaded_file = handle_featured_image_upload($file);
        } else {
            // 使用todo_upload_file处理其他文件上传
            $upload_overrides = array('test_form' => false);
            $uploaded_file = todo_upload_file($file, $upload_overrides);
        }

        if (isset($uploaded_file['error'])) {
            return new TODO_Error('upload_error', $uploaded_file['error']);
        }

        // 创建附件记录
        $attachment = array(
            'post_mime_type' => $uploaded_file['type'],
            'post_title' => sanitize_file_name(basename($uploaded_file['file'])),
            'post_content' => '',
            'post_status' => 'inherit'
        );

        $attachment_id = todo_insert_post($attachment);

        if (is_todo_error($attachment_id)) {
            return $attachment_id;
        }

        $attachment_id = (int) $attachment_id;
        if ($attachment_id > 0) {
            update_post_meta($attachment_id, '_todo_attached_file', $uploaded_file['file']);
            return $attachment_id;
        }

        return new TODO_Error('db_error', '无法创建附件记录');
    }
}

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 启动会话
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 检查是否是AJAX请求，如果是则立即清理输出缓冲和设置JSON头部
if (isset($_POST['action']) && ($_POST['action'] === 'auto_save_draft' || $_POST['action'] === 'clear_auto_draft')) {
    // 清理任何之前的输出缓冲区
    while (ob_get_level()) {
        ob_end_clean();
    }
    // 重新开始一个干净的输出缓冲
    ob_start();
    header('Content-Type: application/json');
}

// 获取自动保存的草稿
$auto_draft_data = null;
$user_id = get_current_user_id();
$auto_draft_key = 'auto_draft_' . $user_id;
if (isset($_SESSION[$auto_draft_key])) {
    $auto_draft_data = $_SESSION[$auto_draft_key];
}
if ($auto_draft_data && trim((string) ($auto_draft_data['title'] ?? '')) === '' && trim((string) ($auto_draft_data['content'] ?? '')) === '') {
    unset($_SESSION[$auto_draft_key]);
    $auto_draft_data = null;
}

// 处理选择的分类参数，用于动态加载模型字段
$selected_categories = array();

if (isset($_GET['selected_categories']) && !empty($_GET['selected_categories'])) {
    $selected_categories = explode(',', $_GET['selected_categories']);
    $selected_categories = array_map('intval', $selected_categories);
    $selected_categories = array_filter($selected_categories);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($selected_categories)) {
    $requested_model_key = (isset($_GET['model']) && $_GET['model'] !== '') ? sanitize_text_field($_GET['model']) : '';
    $option_default_category_id = function_exists('get_option') ? intval(get_option('default_category', 0)) : 0;

    if ($option_default_category_id > 0) {
        if ($requested_model_key !== '' && class_exists('Todo_Model_Manager') && method_exists('Todo_Model_Manager', 'get_model_categories')) {
            $model_categories = Todo_Model_Manager::get_model_categories($requested_model_key);
            $in_model = false;
            foreach ($model_categories as $cat) {
                $term_id = is_object($cat) ? intval($cat->term_id ?? 0) : intval($cat['term_id'] ?? 0);
                if ($term_id === $option_default_category_id) {
                    $in_model = true;
                    break;
                }
            }
            if ($in_model) {
                $selected_categories = array($option_default_category_id);
            }
        } else {
            $selected_categories = array($option_default_category_id);
        }
    }

    if (empty($selected_categories) && $requested_model_key !== '' && class_exists('Todo_Model_Manager') && method_exists('Todo_Model_Manager', 'get_model_categories')) {
        $model_categories = Todo_Model_Manager::get_model_categories($requested_model_key);
        $default_category_id = 0;

        foreach ($model_categories as $cat) {
            $term_id = is_object($cat) ? intval($cat->term_id ?? 0) : intval($cat['term_id'] ?? 0);
            $parent_id = is_object($cat) ? intval($cat->parent ?? 0) : intval($cat['parent'] ?? 0);
            if ($term_id > 0 && $parent_id === 0) {
                $default_category_id = $term_id;
                break;
            }
        }

        if ($default_category_id === 0 && !empty($model_categories)) {
            $first = $model_categories[0];
            $default_category_id = is_object($first) ? intval($first->term_id ?? 0) : intval($first['term_id'] ?? 0);
        }

        if ($default_category_id > 0) {
            $selected_categories = array($default_category_id);
        }
    }
}

// 恢复表单数据（如果有的话）
if (isset($_GET['selected_categories']) && !empty($_GET['selected_categories'])) {
    // 从sessionStorage恢复表单数据的JavaScript将在页面底部处理
}

// 检查用户权限
if (!current_user_can('publish_posts')) {
    todo_die('您没有足够的权限创建文章。');
}

// 初始化空的$post对象用于新建文章
$post = new stdClass();
$post->post_title = '';
$post->post_content = '';
$post->post_excerpt = '';
$post->post_status = 'draft';
$post->comment_status = get_option('default_comment_status', 'open');
$post->seo_title = '';
$post->seo_keywords = '';
$post->seo_description = '';
$post->view_count = 0;
$post->read_permission = 'public';
$post->view_template = '';

$views_boost = isset($_POST['views_boost'])
    ? max(0, intval($_POST['views_boost']))
    : ((function_exists('random_int') ? random_int(30, 300) : rand(30, 300)));

$message = '';
$error = '';

// 处理自动保存请求
if (isset($_POST['action']) && $_POST['action'] === 'auto_save_draft') {
    $title = sanitize_text_field($_POST['post_title'] ?? '');
    // 将HTML实体转义后保存到自动草稿，保持与post-edit一致
    $content = isset($_POST['post_content']) ? todo_esc_html($_POST['post_content']) : '';
    if (function_exists('todo_convert_content_urls_to_relative')) {
        $content = todo_convert_content_urls_to_relative($content);
    }

    // 检查是否已有自动保存的草稿
    $user_id = get_current_user_id();
    $auto_draft_key = 'auto_draft_' . $user_id; // 新建文章不需要post_id

    // 保存到临时存储（这里使用session，实际项目中可以使用数据库）

    $_SESSION[$auto_draft_key] = [
        'title' => $title,
        'content' => $content,
        'saved_at' => time() // 使用时间戳更可靠
    ];

    // 清理输出缓冲区
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // 确保使用系统配置的时区
    $timezone = get_option('timezone_string', 'Asia/Shanghai');
    if (!empty($timezone)) {
        @date_default_timezone_set($timezone);
    }

    echo json_encode([
        'success' => true,
        'message' => '自动保存成功',
        'saved_at' => date('H:i')
    ]);

    exit;
}

// 处理清除自动保存草稿请求
if (isset($_POST['action']) && $_POST['action'] === 'clear_auto_draft') {

    // 验证nonce
    if (!Todo_Security_Manager::verify_request_csrf('create_post_nonce', '_todononce')) {
        // 清理输出缓冲区
        if (ob_get_level()) {
            ob_end_clean();
        }

        echo json_encode(['success' => false, 'message' => '安全验证失败']);
        exit;
    }

    $user_id = get_current_user_id();
    $auto_draft_key = 'auto_draft_' . $user_id;

    // 清除自动保存的草稿
    unset($_SESSION[$auto_draft_key]);

    // 清理输出缓冲区
    if (ob_get_level()) {
        ob_end_clean();
    }

    echo json_encode([
        'success' => true,
        'message' => '草稿已清除'
    ]);

    exit;
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_post') {
    // 获取表单数据
    $post_title = isset($_POST['post_title']) ? trim($_POST['post_title']) : '';
    $post_content = isset($_POST['post_content']) ? $_POST['post_content'] : '';
    // 强制转换为相对路径
    if (function_exists('todo_convert_content_urls_to_relative')) {
        $post_content = todo_convert_content_urls_to_relative($post_content);
    }
    // 实体保存：统一对标题与内容进行HTML实体转义
    $post_title = todo_esc_html($post_title);
    $post_content = todo_esc_html($post_content);
    $post_excerpt = isset($_POST['post_excerpt']) ? $_POST['post_excerpt'] : '';
    $post_status = isset($_POST['post_status']) ? $_POST['post_status'] : 'draft';
    $post_categories = isset($_POST['post_category']) ? (array) $_POST['post_category'] : array();
    $post_tags = isset($_POST['post_tags']) ? $_POST['post_tags'] : '';
    $post_author = isset($_POST['post_author']) ? intval($_POST['post_author']) : get_current_user_id();

    // 根据按钮点击设置状态，仅在“保存草稿/提交审核”时覆盖；“发布”不强制覆盖，使用下拉选择的 post_status
    if (isset($_POST['save'])) {
        if ($_POST['save'] === '保存草稿' || $_POST['save'] === '草稿' || $_POST['save'] === 'draft') {
            $post_status = 'draft';
        } elseif ($_POST['save'] === '提交审核' || $_POST['save'] === 'pending') {
            $post_status = 'pending';
        } elseif ($_POST['save'] === '发布' || $_POST['save'] === 'publish') {
            $post_status = 'publish';
        }
    }

    // 获取高级设置字段数据
    $comment_status = isset($_POST['comment_status']) ? trim($_POST['comment_status']) : 'open';

    // 获取数据
    $seo_title = isset($_POST['seo_title']) ? trim($_POST['seo_title']) : '';
    $seo_keywords = isset($_POST['seo_keywords']) ? trim($_POST['seo_keywords']) : '';
    $seo_description = isset($_POST['seo_description']) ? trim($_POST['seo_description']) : '';
    $seo_auto_update = isset($_POST['seo_auto_update']) ? 1 : 0;

    // 获取基础字段数据
    $view_count = 0;
    $views_boost = isset($_POST['views_boost']) ? max(0, intval($_POST['views_boost'])) : 0;
    $read_permission = isset($_POST['read_permission']) ? trim($_POST['read_permission']) : 'public';
    $view_template = isset($_POST['view_template']) ? trim($_POST['view_template']) : '';

    // 验证数据
    $errors = array();

    // CSRF验证（与post-edit保持一致的安全策略）
    if (!Todo_Security_Manager::verify_request_csrf('create_post_nonce', '_todononce')) {
        $errors[] = '安全验证失败，请刷新页面后重试。';
    }

    if (empty($post_title)) {
        $errors[] = '请输入文章标题。';
    }

    if (empty($post_content)) {
        $errors[] = '请输入文章内容。';
    }

    // 可见性与密码校验（前端选择的post_visibility与post_password）
    $post_visibility = isset($_POST['post_visibility']) ? trim($_POST['post_visibility']) : 'public';
    if (!in_array($post_visibility, ['public', 'readable', 'password', 'private'], true)) {
        $post_visibility = 'public';
    }
    $post_password = isset($_POST['post_password']) ? trim($_POST['post_password']) : '';
    if ($post_visibility === 'password' && $post_password === '') {
        $errors[] = '选择“密码保护”可见性时必须设置密码。';
    }

    // 如果没有错误，创建文章
    if (empty($errors)) {
        // 第一层：获取分类对应的模型信息
        $primary_model_id = 0;
        $category_model = 'post'; // 默认为post类型

        if (!empty($post_categories)) {
            $primary_category = $post_categories[0];
            $detected_model = Todo_Model_Manager::get_category_model($primary_category);
            if ($detected_model) {
                $category_model = $detected_model; // 使用检测到的模型作为post_type
                $primary_model_id = Todo_Model_Manager::get_model_id_by_key($detected_model);
            }
        }

        // 定时发布处理：将用户选择的发布时间写入第一层字段（与post-edit保持一致）
        $scheduled_post_date = null;
        $scheduled_post_date_gmt = null;
        if (isset($_POST['enable_schedule']) && !empty($_POST['schedule_time'])) {
            $raw_input = trim($_POST['schedule_time']);
            // 将 HTML5 datetime-local 值转换为标准格式 Y-m-d H:i:s
            $local_dt = str_replace('T', ' ', $raw_input);
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $local_dt)) {
                $local_dt .= ':00';
            }
            $ts = strtotime($local_dt);
            if ($ts) {
                $now_ts = time();
                $scheduled_post_date = date('Y-m-d H:i:s', $ts);
                $scheduled_post_date_gmt = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                // 仅当选择状态为“发布”时才应用定时/立即发布逻辑，避免覆盖“草稿/待审核”
                if ($post_status === 'publish') {
                    if ($ts > $now_ts) {
                        // 未来时间，设为定时状态
                        $post_status = 'future';
                    } else {
                        // 过去时间，直接按该时间发布
                        $post_status = 'publish';
                    }
                }
            }
        }

        // Prepare featured image data for posts table
        // Prepare featured image data for posts table (JSON format)
        $featured_image_content = '';
        if (isset($_POST['featured_images'])) {
            $fi_data = $_POST['featured_images'];
            if (is_string($fi_data)) {
                $fi_decoded = json_decode($fi_data, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($fi_decoded)) {
                    $featured_image_content = json_encode($fi_decoded, JSON_UNESCAPED_UNICODE);
                }
            } elseif (is_array($fi_data)) {
                $featured_image_content = json_encode($fi_data, JSON_UNESCAPED_UNICODE);
            }
        }

        $post_data = array(
            'post_title' => $post_title,
            'post_content' => $post_content,
            'post_excerpt' => $post_excerpt,
            'post_status' => $post_status,
            'post_author' => $post_author,
            'post_type' => $category_model, // 根据分类绑定的模型设置post_type
            'comment_status' => $comment_status,
            'visibility' => $post_visibility,
            'post_password' => $post_password,
            'featured_image' => $featured_image_content,
            'doc_properties' => isset($_POST['doc_properties']) ? implode(',', array_map('intval', $_POST['doc_properties'])) : ''
        );

        // 如果设置了定时发布时间或过去的发布时间，写入第一层字段
        if (!empty($scheduled_post_date)) {
            $post_data['post_date'] = $scheduled_post_date;
            $post_data['post_date_gmt'] = $scheduled_post_date_gmt;
        }

        // 添加model_id到基础数据
        $post_data['model_id'] = $primary_model_id;

        // 添加字段到基础数据（第一层）
        $post_data['seo_title'] = $seo_title;
        $post_data['seo_keywords'] = $seo_keywords;
        $post_data['seo_description'] = $seo_description;
        $post_data['seo_auto_update'] = $seo_auto_update;

        // 添加基础字段到基础数据（第一层包含基础字段）
        $post_data['view_count'] = $view_count;
        $post_data['read_permission'] = $read_permission;
        $post_data['view_template'] = $view_template;

        $post_id = todo_insert_post($post_data);

        if (is_todo_error($post_id) || (int) $post_id <= 0) {
            $msg = is_todo_error($post_id) ? $post_id->get_error_message() : '创建文章失败';
            if (function_exists('todo_should_return_json') && todo_should_return_json()) {
                todo_send_error('创建文章失败', [$msg]);
            }
            $errors[] = $msg;
        } else {
            update_post_meta($post_id, '_visibility', $post_visibility);
            if ($post_visibility === 'password') {
                update_post_meta($post_id, 'post_password', $post_password);
            } else {
                update_post_meta($post_id, 'post_password', '');
            }
            update_post_meta($post_id, '_views_boost', $views_boost);

            if (!empty($scheduled_post_date)) {
                update_post_meta($post_id, '_scheduled_enabled', 1);
                update_post_meta($post_id, '_scheduled_time', $scheduled_post_date);
                update_post_meta($post_id, '_scheduled_time_gmt', $scheduled_post_date_gmt);
            } else {
                update_post_meta($post_id, '_scheduled_enabled', 0);
                update_post_meta($post_id, '_scheduled_time', '');
                update_post_meta($post_id, '_scheduled_time_gmt', '');
            }

            // 设置分类
            if (!empty($post_categories)) {
                todo_set_post_categories($post_id, $post_categories);
            }

            // 设置标签
            if (!empty($post_tags)) {
                todo_set_post_tags($post_id, $post_tags);
            }

            // 三层数据存储架构处理
            $model_fields_manager = new Todo_Model_Fields_Manager();
            $post_fields_manager = new Todo_Post_Fields_Manager();

            // 分离不同层次的字段数据
            $model_preset_fields = array(); // 第二层：模型预设字段
            $custom_fields = array();       // 第三层：用户自定义字段

            // 获取当前文章关联的所有模型ID
            $post_model_ids = array();
            if (!empty($post_categories)) {
                foreach ($post_categories as $category_id) {
                    $category_model = Todo_Model_Manager::get_category_model($category_id);
                    if ($category_model) {
                        $model_id = Todo_Model_Manager::get_model_id_by_key($category_model);
                        if ($model_id) {
                            $post_model_ids[] = $model_id;
                        }
                    }
                }
            }

            // 分离字段数据 - 统一使用model_field_前缀
            $model_preset_fields = array();  // 第二层：模型预设字段
            $custom_fields = array();        // 第三层：用户自定义字段

            // 获取文章关联的模型ID和模型键
            $main_model_id = !empty($post_model_ids) ? $post_model_ids[0] : null;
            $main_model_key = 'article'; // 默认为文章模型

            // 根据分类获取模型键
            if (!empty($post_categories)) {
                $main_category_id = $post_categories[0];
                $main_category_model = Todo_Model_Manager::get_category_model($main_category_id);
                if ($main_category_model) {
                    $main_model_key = $main_category_model;
                }
            }

            // 获取模型的固定字段列表 - 使用动态获取方式保持一致性
            $fixed_field_keys = array();
            $model_class_name = 'CMS_Post_Type_' . ucfirst($main_model_key);
            if (class_exists($model_class_name) && method_exists($model_class_name, 'get_model_fixed_fields')) {
                $field_configs = call_user_func([$model_class_name, 'get_model_fixed_fields']);
                foreach ($field_configs as $config) {
                    if (isset($config['field_key'])) {
                        $fixed_field_keys[] = $config['field_key'];
                    }
                }
            } else {
                // 备用：使用硬编码的字段配置
                $fixed_fields = $model_fields_manager->get_model_fixed_fields($main_model_key);
                if (!empty($fixed_fields)) {
                    $fixed_field_keys = $fixed_fields;
                }
            }

            foreach ($_POST as $key => $value) {
                // 处理model_field_前缀的字段（自定义字段）
                if (str_starts_with($key, 'model_field_')) {
                    $clean_key = str_replace('model_field_', '', $key);

                    // 检查是否为模型预设字段
                    if ($main_model_key && $model_fields_manager->is_model_preset_field($main_model_key, $clean_key)) {
                        $model_preset_fields[$clean_key] = $value;
                        if ($main_model_key === 'product') {
                            $product_note_map = [
                                'before_payment_note' => 'memo_before_pay',
                                'after_payment_note' => 'memo_after_pay',
                                'confirm_receipt_note' => 'memo_after_confirm',
                            ];
                            if (isset($product_note_map[$clean_key])) {
                                $model_preset_fields[$product_note_map[$clean_key]] = $value;
                            }
                        }
                        // 添加调试日志
                        if ($clean_key === 'product_video') {
                            error_log("收集到产品视频字段 - clean_key: $clean_key, value: " . (is_array($value) ? json_encode($value) : $value));
                        }
                    } else {
                        // 兼容产品模型的友好字段名（before/after/confirm_*）映射为专用表字段
                        if ($main_model_key === 'product') {
                            $product_note_map = [
                                'before_payment_note' => 'memo_before_pay',
                                'after_payment_note' => 'memo_after_pay',
                                'confirm_receipt_note' => 'memo_after_confirm',
                            ];
                            if (isset($product_note_map[$clean_key])) {
                                $model_preset_fields[$product_note_map[$clean_key]] = $value;
                                continue;
                            }
                        }
                        // 用户自定义字段
                        $custom_fields[$key] = $value;
                    }
                } elseif (str_starts_with($key, 'custom_field_')) {
                    // 该值可能为JSON字符串（如 multi_file），保存器会根据字段类型自行处理
                    $custom_fields[$key] = $value;
                }
                // 产品模型的友好字段名映射到专用表字段名
                elseif ($main_model_key === 'product' && in_array($key, ['before_payment_note', 'after_payment_note', 'confirm_receipt_note'], true)) {
                    $product_note_map = [
                        'before_payment_note' => 'memo_before_pay',
                        'after_payment_note' => 'memo_after_pay',
                        'confirm_receipt_note' => 'memo_after_confirm',
                    ];
                    $mapped_key = $product_note_map[$key] ?? $key;
                    $model_preset_fields[$mapped_key] = $value;
                }
                // 处理固定字段（直接使用字段键作为name）
                elseif (in_array($key, $fixed_field_keys, true)) {
                    $model_preset_fields[$key] = $value;
                    // 避免数组转字符串的警告，统一做安全日志输出
                    if (is_array($value) || is_object($value)) {
                        $val_str = json_encode($value, JSON_UNESCAPED_UNICODE);
                    } else {
                        $val_str = (string) $value;
                    }
                    error_log("Found fixed field: {$key} = {$val_str}");
                }
            }

            // 特殊处理：产品模型自定义参数 custom_params 组装与保存
            if ($main_model_key === 'product') {
                $params = [];
                // 支持两种命名方式：
                // 1) 关联数组：param_id[ID] / sort_order[ID] / param_name[ID] / param_value[ID]
                // 2) 顺序数组：param_sort[] / param_name[] / param_value[]
                $hasAssoc = isset($_POST['param_id']) || (isset($_POST['param_name']) && is_array($_POST['param_name']) && array_keys($_POST['param_name']) !== range(0, count($_POST['param_name']) - 1));
                if ($hasAssoc) {
                    $param_ids = [];
                    if (isset($_POST['param_id']) && is_array($_POST['param_id'])) {
                        $param_ids = array_keys($_POST['param_id']);
                    } elseif (isset($_POST['param_name']) && is_array($_POST['param_name'])) {
                        $param_ids = array_keys($_POST['param_name']);
                    }
                    foreach ($param_ids as $pid) {
                        $pid = trim((string) $pid);
                        $name = isset($_POST['param_name'][$pid]) ? trim((string) $_POST['param_name'][$pid]) : '';
                        $val = isset($_POST['param_value'][$pid]) ? trim((string) $_POST['param_value'][$pid]) : '';
                        $sort = isset($_POST['sort_order'][$pid]) ? (int) $_POST['sort_order'][$pid] : (isset($_POST['param_sort'][$pid]) ? (int) $_POST['param_sort'][$pid] : 100);
                        if ($name === '' && $val === '') {
                            continue;
                        }
                        $params[$pid] = [
                            'sort_order' => $sort,
                            'param_name' => $name,
                            'param_value' => $val,
                        ];
                    }
                } else {
                    $names = isset($_POST['param_name']) && is_array($_POST['param_name']) ? $_POST['param_name'] : [];
                    $vals = isset($_POST['param_value']) && is_array($_POST['param_value']) ? $_POST['param_value'] : [];
                    $sorts = isset($_POST['param_sort']) && is_array($_POST['param_sort']) ? $_POST['param_sort'] : [];
                    $count = max(count($names), count($vals));
                    for ($i = 0; $i < $count; $i++) {
                        $name = isset($names[$i]) ? trim((string) $names[$i]) : '';
                        $val = isset($vals[$i]) ? trim((string) $vals[$i]) : '';
                        $sort = isset($sorts[$i]) ? (int) $sorts[$i] : 100;
                        if ($name === '' && $val === '') {
                            continue;
                        }
                        $pid = (string) ($i + 1);
                        $params[$pid] = [
                            'sort_order' => $sort,
                            'param_name' => $name,
                            'param_value' => $val,
                        ];
                    }
                }
                // 处理删除的参数ID（如果存在）
                if (!empty($_POST['del_param_id'])) {
                    $del_ids = array_filter(array_map('trim', explode(',', (string) $_POST['del_param_id'])), 'strlen');
                    foreach ($del_ids as $did) {
                        unset($params[$did]);
                    }
                }
                // 根据排序值进行排序，保持键不变
                if (!empty($params)) {
                    uasort($params, function ($a, $b) {
                        return ($a['sort_order'] ?? 100) <=> ($b['sort_order'] ?? 100);
                    });
                }
                // 写入模型预设字段（空数组则写空字符串以清理数据）
                $model_preset_fields['custom_params'] = !empty($params) ? json_encode($params, JSON_UNESCAPED_UNICODE) : '';
            }

            // 第二层：保存模型预设字段到对应的模型表
            if (!empty($model_preset_fields) && $main_model_key) {
                // 添加调试日志
                error_log("准备保存模型预设字段 - Post ID: $post_id, Model: $main_model_key, Fields: " . json_encode(array_keys($model_preset_fields), JSON_UNESCAPED_UNICODE));

                $model_save_result = $model_fields_manager->save_model_preset_fields(
                    $main_model_key,
                    $post_id,
                    $model_preset_fields
                );

                if (!$model_save_result) {
                    error_log("ERROR: 模型预设字段保存失败 - Post ID: $post_id, Model: $main_model_key");
                    // 不要继续执行，因为保存失败
                } else {
                    error_log("成功保存模型预设字段 - Post ID: $post_id, Model: $main_model_key");
                }
            } else {
                error_log("跳过保存模型预设字段 - model_preset_fields为空: " . (empty($model_preset_fields) ? 'true' : 'false') . ", main_model_key: " . ($main_model_key ?? 'null'));
            }

            // 第三层：保存用户自定义字段到 todo_post_custom_fields 表
            if (!empty($custom_fields)) {
                $custom_save_result = $post_fields_manager->save_custom_fields(
                    $post_id,
                    $main_model_id,
                    $custom_fields
                );

                if (!$custom_save_result) {
                    error_log("Failed to save custom fields for post ID: $post_id");
                }
            }

            // 清除自动保存的草稿
            if (isset($_SESSION[$auto_draft_key])) {
                unset($_SESSION[$auto_draft_key]);
            }

            // 重定向到编辑页面或文章列表（根据最终状态决定提示）
            if (isset($_POST['save']) && ($_POST['save'] === '发布' || $_POST['save'] === 'publish')) {
                if ($post_status === 'publish' || $post_status === 'future') {
                    if (function_exists('todo_add_admin_notice')) {
                        todo_add_admin_notice('文章已发布。', 'success');
                    }
                    todo_redirect(todo_admin_url('index.php?page=posts'));
                } elseif ($post_status === 'pending') {
                    if (function_exists('todo_add_admin_notice')) {
                        todo_add_admin_notice('文章已提交审核。', 'success');
                    }
                    todo_redirect(todo_admin_url('index.php?page=posts'));
                } else {
                    if (function_exists('todo_add_admin_notice')) {
                        todo_add_admin_notice('草稿已保存。', 'success');
                    }
                    todo_redirect(todo_admin_url('index.php?page=post-edit&id=' . $post_id));
                }
                exit;
            } elseif (isset($_POST['save']) && ($_POST['save'] === '保存草稿' || $_POST['save'] === '草稿' || $_POST['save'] === 'draft')) {
                if (function_exists('todo_add_admin_notice')) {
                    todo_add_admin_notice('草稿已保存。', 'success');
                }
                todo_redirect(todo_admin_url('index.php?page=post-edit&id=' . $post_id));
                exit;
            } elseif (isset($_POST['save']) && ($_POST['save'] === '提交审核' || $_POST['save'] === 'pending')) {
                if (function_exists('todo_add_admin_notice')) {
                    todo_add_admin_notice('文章已提交审核。', 'success');
                }
                todo_redirect(todo_admin_url('index.php?page=posts'));
                exit;
            } else {
                // 根据文章状态决定重定向
                if ($post_status === 'publish') {
                    if (function_exists('todo_add_admin_notice')) {
                        todo_add_admin_notice('文章已发布。', 'success');
                    }
                    todo_redirect(todo_admin_url('index.php?page=posts'));
                } elseif ($post_status === 'pending') {
                    if (function_exists('todo_add_admin_notice')) {
                        todo_add_admin_notice('文章已提交审核。', 'success');
                    }
                    todo_redirect(todo_admin_url('index.php?page=posts'));
                } else {
                    if (function_exists('todo_add_admin_notice')) {
                        todo_add_admin_notice('草稿已保存。', 'success');
                    }
                    todo_redirect(todo_admin_url('index.php?page=post-edit&id=' . $post_id));
                }
                exit;
            }
        }
    }

    if (!empty($errors) && function_exists('todo_should_return_json') && todo_should_return_json()) {
        $err_msg = '保存失败';
        if (!empty($errors) && is_string($errors[0] ?? null) && $errors[0] !== '') {
            $err_msg .= ': ' . $errors[0];
        }

        if (function_exists('todo_send_error')) {
            todo_send_error($err_msg, $errors);
        }

        if (function_exists('todo_send_json')) {
            todo_send_json([
                'success' => false,
                'message' => $err_msg,
                'errors' => $errors,
            ], 400);
        }
    }
}

// 获取所有分类
$categories = get_categories(array(
    'hide_empty' => false
));

// 获取所有用户
$users = get_users(array(
    'role__in' => array('administrator', 'editor', 'author'),
    'orderby' => 'display_name'
));

// 获取当前用户（todocms风格函数）
$current_user = todo_get_current_user();
?>

<!-- MathJax 支持 -->
<script id="MathJax-script" async src="<?php echo todo_admin_url('assets/js/mathjax.js'); ?>" type="text/javascript"></script>
<script type="text/javascript">
    window.MathJax = {
        tex: {
            inlineMath: [['$', '$'], ['\\(', '\\)']],
            displayMath: [['$$', '$$'], ['\\[', '\\]']],
            processEscapes: true,
            processEnvironments: true
        },
        options: {
            skipHtmlTags: ['script', 'noscript', 'style', 'textarea', 'pre']
        }
    };
</script>

<?php
// 输出层级分类选择器的样式和脚本
enqueue_hierarchical_categories_selector_styles();
?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">内容发布</h2>
        <button type="button" class="button button-link" id="todo-super-paste" title="使用全量数据填充" style="margin-right: auto;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" style="vertical-align: middle;"><g fill="currentColor"><path d="M11 0H0v11h3V9H2V2h7v1h2z"/><path d="M5 5h11v11H5z"/></g></svg>
        </button>
        <div class="admin-page-actions">
            <?php
            $return_url = todo_admin_url('index.php?page=posts');
            $from = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : '';
            if ($from !== '') {
                $return_url = todo_admin_url('index.php?page=' . urlencode($from));
            }
            ?>
            <a href="<?php echo $return_url; ?>" class="button button-secondary">返回列表</a>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="notice notice-error is-dismissible">
            <?php foreach ($errors as $error): ?>
                <p><?php echo $error; ?></p>
            <?php endforeach; ?>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" id="post-form" action="<?php echo todo_admin_url('index.php?page=post-new'); ?>">
        <input type="hidden" name="action" value="create_post">
        <?php echo Todo_Security_Manager::csrf_field('create_post_nonce', '_todononce'); ?>

        <div class="admin-post-form-wrap">
            <div class="admin-post-main">
                <div class="admin-card">
                    <div class="admin-card-content">
                        <div class="form-group">
                            <label for="post-title">标题/名称</label>
                            <input type="text" name="post_title" id="post-title"
                                value="<?php echo $auto_draft_data ? esc_attr($auto_draft_data['title']) : (isset($post_title) ? esc_attr($post_title) : ''); ?>"
                                placeholder="在此输入标题 或 名称" class="admin-post-title-input">
                            <?php if ($auto_draft_data): ?>
                                <div class="auto-draft-notice">
                                    <p class="text-info">
                                        检测到自动保存的草稿（<?php echo format_date($auto_draft_data['saved_at'], 'Y-m-d H:i'); ?>），已自动恢复内容。
                                        <a href="javascript:void(0);" id="clear-auto-draft"
                                            class="text-danger ml-2">清除草稿</a>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- 文档属性关联 -->
                        <?php
                        // 获取前台显示的文档属性
                        require_once ABSPATH . 'admin/includes/doc-properties-functions.php';
                        $display_properties = get_display_doc_properties();

                        // 初始化文档属性数组（新建文章时为空）
                        $post_properties = array();
                        ?>
                        <?php if (!empty($display_properties)): ?>
                            <div class="form-group">
                                <h4>文档属性</h4>
                                <div class="doc-properties-wrapper">
                                    <?php foreach ($display_properties as $property): ?>
                                        <?php
                                        // 确保 $property 是数组（兼容对象返回）
                                        if (is_object($property)) {
                                            $property = (array) $property;
                                        }
                                        if (!is_array($property) || !isset($property['id'])) {
                                            continue;
                                        }
                                        ?>
                                        <label class="doc-property-item">
                                            <input type="checkbox" name="doc_properties[]"
                                                value="<?php echo esc_attr($property['id']); ?>" <?php checked(in_array($property['id'], $post_properties)); ?>>
                                            <span
                                                class="property-name"><?php echo esc_html(isset($property['name']) ? $property['name'] : ''); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <div class="editor-toolbar-wrapper">
                                <h4>内容</h4>
                            </div>
                            <div class="editor-container" id="editor-container">
                                <?php
                                // 使用文本编辑器
                                $content = $auto_draft_data ? $auto_draft_data['content'] : (isset($post_content) ? $post_content : '');
                                if (function_exists('todo_convert_content_urls_to_absolute')) {
                                    $content = todo_convert_content_urls_to_absolute($content);
                                }
                                ?>
                                <textarea id="post-content" name="post_content" rows="20"
                                    style="width: 100%; height: 400px;"><?php echo htmlspecialchars($content); ?></textarea>
                            </div>
                            <div class="editor-status items-center justify-between">
                                <div class="flex flex-col">
                                    <p>
                                        <span id="auto-save-status"></span>
                                        <span id="last-saved"></span>
                                    </p>
                                    <a href="javascript:void(0);" id="btn-clean-format" style="margin-right: auto;opacity: 0.7;" title="清理无用样式及多余属性，保留纯净排版"><i class="icon todofont todo-Clear" style="opacity: 0.5; font-size: 14px; margin-right: 1px;"></i>清理多余属性</a>
                                </div>
                                <div class="editor-quick-actions flex items-center gap-3" style="opacity: 0.5; filter: grayscale(1);">
                                    <?php 
                                    $is_cloud_storage = false;
                                    if (class_exists('Todo_Storage_Manager')) {
                                        $storage_mgr = Todo_Storage_Manager::get_instance();
                                        if (!$storage_mgr->is_local()) {
                                            $is_cloud_storage = true;
                                        }
                                    }
                                    ?>
                                    <label class="flex items-center gap-1 m-0"><input type="checkbox" id="chk-save-remote-images" name="chk_save_remote_images" <?php echo $is_cloud_storage ? 'disabled' : 'checked'; ?> title="<?php echo $is_cloud_storage ? '已开启云存储，禁止二次抓取保存' : ''; ?>">保存远程图片</label>
                                    <label class="flex items-center gap-1 m-0"><input type="checkbox" id="chk-clear-external-links" name="chk_clear_external_links" checked>清除外链地址</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="admin-card">
                    <div class="admin-card-content">
                        <div class="admin-post-status">
                            <label for="post-excerpt">摘要</label>
                            <textarea name="post_excerpt" id="post-excerpt" rows="3"
                                class="admin-post-excerpt-editor"><?php echo esc_textarea($post->post_excerpt); ?></textarea>
                        </div>
                    </div>
                </div>

                <?php
                // 引入数据模型字段组件
                require_once ADMIN_DIR . '/components/model-fields-component.php';

                // 渲染数据模型字段组件
                // 渲染数据模型字段组件
                render_model_fields_component(array(
                    'post_id' => 0, // 新建文章，ID为0
                    'selected_categories' => !empty($selected_categories) ? $selected_categories : (isset($post_categories) ? $post_categories : array()),
                    'field_values' => array(),
                    'container_id' => 'post-new-model-fields',
                    'show_title' => true
                ));

                // 输出组件样式
                enqueue_model_fields_component_styles(); ?>

                <!-- 多语言：语言版本面板（新建文章时不可编辑） -->
                <div class="admin-card" id="language-versions-card">
                    <div class="admin-card-header no-border">
                        <h3 class="admin-card-title">语言版本</h3>
                    </div>
                    <div class="admin-card-content">
                        <?php
                        $available_languages = function_exists('get_available_languages') ? get_available_languages() : array();
                        $default_language = function_exists('get_default_language') ? get_default_language() : 'zh-CN';
                        ?>
                        <div id="lang-versions-panel-new"
                            data-default-lang="<?php echo esc_attr($default_language); ?>">
                            <div class="lang-versions-list"></div>
                            <p class="description">
                                提示：在保存文章之前，无法添加或编辑翻译。请先点击右侧“发布/保存草稿”，系统将跳转到编辑页，在编辑页可管理各语言版本。
                            </p>
                            <style>
                                .lang-versions-list {
                                    display: flex;
                                    flex-wrap: wrap;
                                    gap: 8px;
                                }

                                .lang-chip {
                                    display: inline-flex;
                                    align-items: center;
                                    gap: 6px;
                                    border: 1px solid #eceef1;
                                    border-radius: 3px;
                                    padding: 2px 4px 2px 10px;
                                    cursor: pointer;
                                    background: #fafafa;
                                }

                                .lang-chip .code {
                                    font-weight: 500;
                                }

                                .lang-chip .name {
                                    font-size: 13px;
                                }

                                .lang-chip .status {
                                    font-size: 12px;
                                    padding: 2px 6px;
                                    border-radius: 3px;
                                    background: #eee;
                                }

                                .lang-chip.disabled {
                                    opacity: 0.6;
                                    cursor: not-allowed;
                                }

                                .lang-chip.default .status {
                                    background: #d4edda;
                                    color: #155724;
                                }
                            </style>
                            <script type="text/javascript">
                                (function () {
                                    var defaultLang = '<?php echo esc_attr($default_language); ?>';
                                    var LANGS = <?php echo json_encode($available_languages, JSON_UNESCAPED_UNICODE); ?> || {};
                                    var listEl = document.querySelector('#lang-versions-panel-new .lang-versions-list');
                                    function renderLangChips() {
                                        if (!listEl) return;
                                        listEl.innerHTML = '';
                                        var locs = Object.keys(LANGS);
                                        locs.sort(function(a, b) {
                                            if (a === defaultLang) return -1;
                                            if (b === defaultLang) return 1;
                                            return 0;
                                        });
                                        locs.forEach(function (loc) {
                                            var info = LANGS[loc] || {};
                                            var chip = document.createElement('div');
                                            chip.className = 'lang-chip disabled' + (loc === defaultLang ? ' default' : '');
                                            chip.dataset.lang = loc;
                                            var statusText = (loc === defaultLang) ? '默认' : '↓';
                                            chip.innerHTML = '<span class="code">' + loc + '</span>' +
                                                '<span class="name">' + (info.name || info.native_name || loc) + '</span>' +
                                                '<span class="status">' + statusText + '</span>';
                                            chip.addEventListener('click', function () {
                                                TodoModal.alert({
                                                    title: '提示',
                                                    message: '请先保存文章，再添加该语言的翻译。',
                                                    buttonText: '知道了'
                                                });
                                            });
                                            listEl.appendChild(chip);
                                        });
                                    }
                                    renderLangChips();
                                })();
                            </script>
                        </div>
                    </div>
                </div>
            </div>

            <div class="admin-post-sidebar">
                <div class="admin-card">
                    <div class="admin-card-header">
                        <h3 class="admin-card-title">发布</h3>
                    </div>
                    <div class="admin-card-content">
                        <?php if (current_user_can('publish_posts')): ?>
                            <div class="admin-post-status">
                                <label for="post-status">状态</label>
                                <select name="post_status" id="post-status">
                                    <option value="publish" <?php selected(isset($post_status) ? $post_status : '', 'publish'); ?>>已发布</option>
                                    <option value="draft" <?php selected(isset($post_status) ? $post_status : '', 'draft'); ?>>草稿</option>
                                    <option value="pending" <?php selected(isset($post_status) ? $post_status : '', 'pending'); ?>>等待审核</option>
                                </select>
                            </div>

                            <div class="admin-post-visibility">
                                <label for="post-visibility">可见性</label>
                                <select name="post_visibility" id="post-visibility">
                                    <option value="public" selected>公开</option>
                                    <option value="readable">可读</option>
                                    <option value="password">密码保护</option>
                                    <option value="private">私密</option>
                                </select>

                                <div id="password-visibility-fields" style="margin-top: 10px; display: none;">
                                    <label for="post-password">密码</label>
                                    <input type="text" name="post_password" id="post-password" value="">
                                </div>
                            </div>

                            <div class="admin-post-schedule">
                                <?php
                                // 计算datetime-local默认值（新建时默认空）
                                $schedule_default = '';
                                ?>
                                <label>
                                    <input type="checkbox" name="enable_schedule" id="enable-schedule" <?php echo (isset($_POST['enable_schedule']) ? 'checked' : ''); ?>> 定时发布
                                </label>
                                <div id="schedule-time-wrap"
                                    style="margin-top:8px; display: <?php echo (isset($_POST['enable_schedule']) ? 'block' : 'none'); ?>;">
                                    <input type="datetime-local" name="schedule_time" id="schedule-time"
                                        value="<?php echo isset($_POST['schedule_time']) ? esc_attr($_POST['schedule_time']) : esc_attr($schedule_default); ?>">
                                    <p class="description">设置将来的发布时间（若早于当前时间，保存时将按该时间直接发布）。</p>
                                </div>
                            </div>

                            <script type="text/javascript">
                                (function () {
                                    var enable = document.getElementById('enable-schedule');
                                    var wrap = document.getElementById('schedule-time-wrap');
                                    if (enable && wrap) {
                                        enable.addEventListener('change', function () {
                                            wrap.style.display = this.checked ? 'block' : 'none';
                                        });
                                    }
                                    // 根据文章状态显示/隐藏“定时发布”区块（仅不可见，不清除数据）
                                    var statusSelect = document.getElementById('post-status');
                                    var scheduleContainer = document.querySelector('.admin-post-schedule');
                                    function updateScheduleBlockVisibility() {
                                        if (!scheduleContainer || !statusSelect) return;
                                        if (statusSelect.value === 'publish') {
                                            // 显示整个定时发布区块
                                            scheduleContainer.style.display = '';
                                            // 保持内部显示与复选框一致
                                            if (enable && wrap) {
                                                wrap.style.display = enable.checked ? 'block' : 'none';
                                            }
                                        } else {
                                            // 隐藏区块（不清除输入值）
                                            scheduleContainer.style.display = 'none';
                                        }
                                    }
                                    if (statusSelect) {
                                        statusSelect.addEventListener('change', updateScheduleBlockVisibility);
                                        // 初始化一次
                                        updateScheduleBlockVisibility();
                                    }
                                })();
                            </script>

                            <div class="admin-post-publish-actions">
                                <input type="submit" name="save" value="发布" class="button button-primary">
                                <input type="submit" name="save" value="保存草稿" class="button button-secondary" id="save-draft-button">
                            </div>
                        <?php else: ?>
                            <div class="admin-post-status">
                                <label for="post-status">状态</label>
                                <select name="post_status" id="post-status">
                                    <option value="pending" selected>等待审核</option>
                                    <option value="draft">草稿</option>
                                </select>
                            </div>

                            <div class="admin-post-publish-actions">
                                <input type="submit" name="save" value="提交审核" class="button button-primary">
                                <input type="submit" name="save" value="保存草稿" class="button button-secondary" id="save-draft-button">
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="admin-card categoriesbox">
                    <div class="admin-card-header no-border">
                        <h3 class="admin-card-title">分类目录</h3>
                    </div>
                    <div class="admin-card-content category-box">
                        <?php
                        // 渲染层级分类选择器
                        $selected_cats = !empty($selected_categories) ? $selected_categories : (isset($post_categories) ? $post_categories : array());
                        render_hierarchical_categories_selector(array(
                            'name' => 'post_category[]',
                            'id' => 'post-categories',
                            'selected' => $selected_cats,
                            'multiple' => true,
                            'show_checkbox' => true,
                            'class' => 'hierarchical-categories-selector',
                            'description' => '选择文章所属的分类目录。',
                            'restrict_to_same_model' => true  // 启用同模型限制
                        ));
                        ?>
                        <p style="min-height: 22px;">
                        <?php if (current_user_can('manage_categories')): ?>
                            <a href="<?php echo todo_admin_url('index.php?page=categories&action=add'); ?>">+ 添加新分类目录</a>
                        <?php endif; ?>
                        </p>
                    </div>
                </div>

                <div class="admin-card">
                    <div class="admin-card-header no-border">
                        <h3 class="admin-card-title">标签</h3>
                    </div>
                    <div class="admin-card-content">
                        <?php
                        $display_post_tags = isset($post_tags) ? (string)$post_tags : '';
                        if ($display_post_tags !== '') {
                            $tag_parts = preg_split('/[,，]/u', $display_post_tags);
                            $cleaned_parts = array_map(function ($t) {
                                $t = trim($t);
                                if ($t === '') return '';
                                $clean = function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($t) : $t;
                                $clean = trim(preg_replace('/\[[a-z]{2}(?:-[a-z]{2})?\|.*?\]/is', '', $clean));
                                $clean = trim(preg_replace('/\[:[a-z]{2}(?:-[a-z]{2})?\].*?\[:\]/is', '', $clean));
                                return $clean ?: $t;
                            }, $tag_parts);
                            $display_post_tags = implode(', ', array_filter($cleaned_parts));
                        }
                        ?>
                        <textarea name="post_tags" id="post-tags" rows="3" class="regular-text" style="width: 100%;" placeholder="多个标签请用中/英逗号分隔"><?php echo esc_textarea($display_post_tags); ?></textarea>
                        <p class="description">每篇控制在 3~5 个 精准标签即可。</p>

                        <?php render_suggested_tags_component(10, 'post-tags'); ?>
                    </div>
                </div>

                <div class="admin-card">
                    <div class="admin-card-header">
                        <h3 class="admin-card-title">特色图片</h3>
                    </div>
                    <div class="admin-card-content">
                        <div class="admin-post-status">
                            <?php
                            // 加载特色图像组件
                            if (function_exists('todo_render_featured_images_component')) {
                                // 新建文章时没有现有的特色图片数据
                                $current_featured_images = array();

                                // 配置特色图片组件
                                $featured_images_config = array(
                                    'field_id' => 'post_featured_images',
                                    'field_name' => 'featured_images',
                                    'current_images' => $current_featured_images,
                                    'multiple' => true,
                                    'max_images' => 6,
                                    'enable_debug' => false
                                );

                                echo todo_render_featured_images_component($featured_images_config);
                                echo '<p class="description">最多可为文章设置6张特色图片。</p>';
                            } else {
                                echo '<p class="description">特色图片组件加载失败。</p>';
                            }
                            ?>
                        </div>
                    </div>
                </div>

                <div class="admin-card">
                    <div class="admin-card-content">
                        <?php if (current_user_can('edit_others_posts')): ?>
                            <div class="admin-post-status">
                                <label for="post-author">作者</label>
                                <select name="post_author" id="post-author">
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?php echo $user->ID; ?>" <?php selected(isset($post_author) ? $post_author : get_current_user_id(), $user->ID); ?>>
                                            <?php echo $user->display_name; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <div class="admin-post-status">
                            <label for="comment-status">评论</label>
                            <select name="comment_status" id="comment-status">
                                <option value="open" <?php selected($post->comment_status, 'open'); ?>>开启评论</option>
                                <option value="closed" <?php selected($post->comment_status, 'closed'); ?>>关闭评论</option>
                            </select>
                        </div>
                        <div class="admin-post-status">
                            <label for="read_permission">阅读权限</label>
                            <?php
                            $level_manager = function_exists('todo_get_user_level_manager') ? todo_get_user_level_manager() : null;
                            $user_levels = $level_manager ? $level_manager->get_user_levels(array('is_active' => 1)) : array();
                            ?>
                            <select name="read_permission" id="read_permission">
                                <option value="public" selected>公开</option>
                                <?php if (!empty($user_levels)): ?>
                                    <?php foreach ($user_levels as $lvl): ?>
                                        <option value="<?php echo htmlspecialchars($lvl->level_slug); ?>">
                                            <?php echo htmlspecialchars($lvl->level_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="member">会员</option>
                                    <option value="vip">高级会员</option>
                                <?php endif; ?>
                                <option value="admin">管理员</option>
                            </select>
                        </div>
                        <div class="admin-post-status">
                            <label for="views_boost" class="flex items-center justify-between">浏览统计<span style="font-size: 12px; color: #a0a0aa;">默认30~300随机</span></label>
                            <input type="number" name="views_boost" id="views_boost" min="0" step="1" value="<?php echo esc_attr($views_boost); ?>">
                        </div>
                        <div class="admin-post-status">
                            <label class="nofor">
                                <span style="display: block;">视图模版</span>
                                <select name="view_template" id="view-template">
                                    <?php
                                    // 根据选择的分类获取对应模型的视图模版
                                    $default_template_shown = false;
                                    // 计算当前选中的视图模版：优先文章自身，其次继承主分类设置的view_template（使用带扩展名的完整文件名）
                                    $selected_view_template = '';
                                    if (isset($post->view_template) && !empty($post->view_template)) {
                                        $selected_view_template = basename($post->view_template);
                                    }
                                    if (empty($selected_view_template) && !empty($selected_categories)) {
                                        $primary_category = intval($selected_categories[0]);
                                        $category_default_view = get_term_meta($primary_category, 'cat_view_template', true);
                                        if (!empty($category_default_view)) {
                                            // 使用完整文件名（含扩展名）用于选中对比
                                            $selected_view_template = basename($category_default_view);
                                        }
                                    }

                                    if (!empty($selected_categories)) {
                                        $primary_category = intval($selected_categories[0]);
                                        $category_model = Todo_Model_Manager::get_category_model($primary_category);

                                        if ($category_model) {
                                            $theme_dir = get_template_directory();
                                            if (is_dir($theme_dir)) {
                                                $template_files = glob($theme_dir . '/view_' . $category_model . '*.html') ?: [];

                                                // 计算“默认模版”对应的值：优先取分类设置的内容模板（不带扩展名），否则为模型默认模板名
                                                $cat_default_view = get_term_meta($primary_category, 'cat_view_template', true);
                                                $default_value_name = '';
                                                if (!empty($cat_default_view)) {
                                                    $default_value_name = pathinfo($cat_default_view, PATHINFO_FILENAME);
                                                } else {
                                                    $default_value_name = 'view_' . $category_model;
                                                }

                                                // 先输出“使用默认模板”选项（值为模型默认模板名，不带扩展名，如 view_article），并按需选中
                                                $default_model_value = 'view_' . $category_model;
                                                if ($selected_view_template === '') {
                                                    $selected_view_template = $default_model_value;
                                                }
                                                $default_selected_attr = ($selected_view_template === $default_model_value) ? ' selected="selected"' : '';
                                                echo '<option value="' . esc_attr($default_model_value) . '"' . $default_selected_attr . '>使用默认模板</option>';

                                                // 添加其它模板选项，跳过与模型默认模板同名的文件，选项值使用完整文件名（含扩展名），显示完整文件名
                                                foreach ($template_files as $template_file) {
                                                    $template_name = pathinfo($template_file, PATHINFO_FILENAME);
                                                    $template_display = basename($template_file); // 显示完整文件名
                                    
                                                    if ($template_name === 'view_' . $category_model) {
                                                        // 避免与“使用默认模板”重复（模型默认模板文件）
                                                        continue;
                                                    }

                                                    $selected_attr = ($selected_view_template === $template_display) ? ' selected="selected"' : '';
                                                    echo '<option value="' . esc_attr($template_display) . '"' . $selected_attr . '>' . esc_html($template_display) . '</option>';
                                                }
                                                $default_template_shown = true;
                                            }
                                        }
                                    }

                                    // 如果没有选择分类或没有找到模版，显示通用选项
                                    if (empty($selected_categories) || !$default_template_shown) {
                                        $default_selected_attr = ($selected_view_template === '') ? ' selected="selected"' : '';
                                        echo '<option value=""' . $default_selected_attr . '>使用默认模板</option>';

                                        // 如果没有选择分类，显示所有可用的视图模版（值使用完整文件名用于选中对比）
                                        if (empty($selected_categories)) {
                                            $theme_dir = get_template_directory();
                                            if (is_dir($theme_dir)) {
                                                $all_view_files = glob($theme_dir . '/view_*.html') ?: [];
                                                foreach ($all_view_files as $template_file) {
                                                    $template_display = basename($template_file);
                                                    $selected_attr = ($selected_view_template === $template_display) ? ' selected="selected"' : '';
                                                    echo '<option value="' . esc_attr($template_display) . '"' . $selected_attr . '>' . esc_html($template_display) . '</option>';
                                                }
                                            }
                                        }
                                    }
                                    ?>
                                </select>
                            </label>
                        </div>

                    </div>
                </div>

            </div>
        </div>
    </form>
</div>

<style>
    /* 新建文章页面特定样式 */
    .admin-post-form-wrap {
        display: flex;
        flex-wrap: wrap;
        margin: 0 -10px;
    }

    .mbody .admin-post-form-wrap {
        margin: 340px -30px 0;
    }

    .mbody .admin-post-form-wrap .admin-card {
        border-radius: 0;
    }

    .admin-post-main {
        flex: 1 500px;
        padding: 0 10px;
        min-width: 0;
        /* 防止flex子项溢出 */
    }

    .admin-post-sidebar {
        width: 280px;
        padding: 0 10px;
    }

    .mbody .admin-post-sidebar {
        flex: 1;
        display: flex;
        flex-direction: column-reverse;
    }

    .mbody .admin-post-sidebar .categoriesbox {
        position: absolute;
        top: 75px;
        left: 0;
        right: 0;
    }

    .admin-post-title-input {
        width: 100%;
        padding: 12px;
        color: #282832 !important;
        font-weight: 500;
        font-size: 1.7em;
        line-height: 1.3;
        border: 1px dashed #eceef1;
        box-shadow: none;
        background-color: transparent;
        margin: 0 0 15px;
        outline: none;
    }

    .admin-post-title-input:focus {
        border-color: #0073aa;
    }

    .admin-post-content-editor,
    .admin-post-excerpt-editor {
        width: 100%;
        min-height: 300px;
        border: 1px solid #eceef1;
        padding: 10px;
    }

    .admin-post-excerpt-editor {
        min-height: 60px;
    }

    .admin-post-status,
    .admin-post-visibility {
        margin-bottom: 15px;
    }

    .admin-post-status:last-child,
    .admin-post-visibility:last-child {
        margin-bottom: 0;
    }

    .admin-post-publish-actions {
        display: flex;
        justify-content: space-between;
        margin-top: 20px;
    }

    /* 分类选择器样式由通用组件提供 */
    .post-categories-selector {
        margin: 10px 0;
    }

    #featured-image-preview {
        margin-bottom: 10px;
        min-height: 100px;
        border: 1px dashed #eceef1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        color: #999;
    }

    #featured-image-preview:empty:before {
        content: "无预览图片";
    }

    #featured-image-preview img {
        max-width: 100%;
        max-height: 200px;
    }

    /* 编辑器增强样式 */
    .editor-toolbar-wrapper {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }

    .editor-meta-info {
        display: flex;
        gap: 15px;
        font-size: 12px;
        color: #666;
        align-items: center;
    }

    .editor-status {
        display: flex;
        gap: 15px;
        font-size: 12px;
        padding-top: 10px;
    }

    .auto-save-pending {
        color: #ffc107;
    }

    .auto-save-saving {
        color: #17a2b8;
    }

    .auto-save-saved {
        color: #28a745;
    }

    .auto-save-error {
        color: #dc3545;
    }

    /* 全屏编辑样式 */
    .editor-container.fullscreen {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        z-index: 9999;
        background: white;
        padding: 20px;
        overflow: auto;
    }

    .editor-container.fullscreen #post-content {
        height: calc(100vh - 200px);
        min-height: calc(100vh - 200px);
    }

    body.editor-fullscreen {
        overflow: hidden;
    }

    /* 自动草稿提示样式 */
    .auto-draft-notice {
        margin: 0 0 8px;
        padding: 8px 12px;
        background-color: #e3f2fd;
        border: 1px solid #bbdefb;
        border-radius: 4px;
        font-size: 12px;
    }

    .auto-draft-notice .text-info {
        color: #1976d2;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 5px;
    }

    .auto-draft-notice .text-danger {
        text-decoration: none;
        margin-left: auto;
    }

    .auto-draft-notice .text-danger:hover {
        text-decoration: underline;
    }

    /* 响应式调整 */
    @media (max-width: 768px) {
        .editor-toolbar-wrapper {
            flex-direction: column;
            gap: 10px;
            align-items: flex-start;
        }

        .editor-meta-info {
            order: 1;
        }

        .editor-status {
            flex-direction: column;
            gap: 5px;
            align-items: baseline;
        }

        .auto-draft-notice {
            font-size: 11px;
            padding: 6px 10px;
        }
    }

    /* 表格样式 */
    .table {
        width: 100%;
        border-collapse: collapse;
        margin: 15px 0;
        font-size: 14px;
    }

    .table th,
    .table td {
        padding: 8px 12px;
        text-align: left;
        border-bottom: 1px solid #eceef1;
    }

    .seo-settings {
        background: #fff;
        border: 1px solid #eceef1;
        border-radius: 4px;
        margin-bottom: 20px;
    }

    .seo-settings .card-header {
        background: #f9f9f9;
        border-bottom: 1px solid #eceef1;
        padding: 12px 15px;
        font-weight: 500;
        color: #333;
    }

    .seo-settings .card-body {
        padding: 15px;
    }

    .character-counter {
        font-weight: 500;
    }

    .seo-auto-update {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 10px;
    }

    .seo-auto-update input[type="checkbox"] {
        margin: 0;
    }

    .seo-auto-update label {
        margin: 0;
        font-weight: normal;
        cursor: pointer;
    }

    .table th {
        background-color: #f8f9fa;
        font-weight: 500;
        border-bottom: 2px solid #dee2e6;
    }

    .table-striped tbody tr:nth-child(odd) {
        background-color: #f9f9f9;
    }

    .table-bordered {
        border: 1px solid #dee2e6;
    }

    .mbody .mobile-table-bordered {
        overflow: auto;
        margin: 0px -15px 0px -30px;
    }

    .mbody .mobile-table-bordered table {
        margin-left: 30px;
        margin-right: 20px;
    }

    .table-bordered th,
    .table-bordered td {
        border: 1px solid #dee2e6;
    }

    .table-hover tbody tr:hover {
        background-color: #f5f5f5;
    }

    .todo-product-type-wrap > div div:first-child label {
        border: 1px solid #7eadd8;
        color: #006ebf;
        padding: 6px 20px 4px;
        border-radius: 3px;
    }
    .todo-product-type-wrap > div div:last-child label {
        border: 1px solid #d9c099;
        color: #c19100;
        padding: 6px 20px 4px;
        border-radius: 3px;
    }
</style>

<!-- CSRF管理器 -->
<script src="<?php echo todo_admin_url('assets/js/csrf-manager.js'); ?>" type="text/javascript"></script>

<!-- UEditor编辑器资源 -->
<script src="<?php echo todo_admin_url('assets/js/ueditor/ueditor.config.js'); ?>" type="text/javascript"></script>
<script src="<?php echo todo_admin_url('assets/js/ueditor/ueditor.all.js'); ?>" type="text/javascript"></script>
<script src="<?php echo todo_admin_url('assets/js/ueditor/lang/zh-cn/zh-cn.js'); ?>" type="text/javascript"></script>
<!-- UEditor对话框脚本由UEditor自动加载，无需手动引用 -->

<script type="text/javascript">
    // 文章编辑页面脚本
    // 全局变量
    var autoSaveTimer;
    var hasChanges = false;
    var autoSaveStatus;
    var lastSaved;
    var ajaxUrl = '<?php echo todo_admin_url('ajax/post-ajax.php'); ?>';

    // 全局函数：打开图片列表对话框
    window.OpenImagesList = function (event, element, multiple = true) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }

        // 根据来源元素或当前活动编辑器，解析目标UE实例
        function resolveEditorFromEvent(el) {
            if (!el || !window.UE || !UE.getEditor) return null;
            try {
                // 1. 优先尝试从 UEditor 实例池中匹配包含该元素的实例（最可靠）
                if (UE.instants) {
                    for (var id in UE.instants) {
                        var inst = UE.instants[id];
                        if (inst && inst.container && (inst.container == el || inst.container.contains(el))) {
                            return inst;
                        }
                    }
                }
                // 2. 兼容支持closest的浏览器，优先查找UE容器
                if (el.closest) {
                    var container = el.closest('#post-content, #post-translation-content, .todo-editor, .form-control.todo-editor');
                    if (container && container.id) {
                        return UE.getEditor(container.id);
                    }
                }
                // 3. 回退：逐层向上查找包含的容器id
                var node = el;
                var maxDepth = 50;
                while (node && node !== document && maxDepth-- > 0) {
                    if (node.id && (node.id === 'post-content' || node.id === 'post-translation-content' || node.id.indexOf('field_') === 0 || node.id.indexOf('editbox_') === 0)) {
                        return UE.getEditor(node.id);
                    }
                    node = node.parentNode;
                }
            } catch (e) { }
            return null;
        }

        var editorInstance = resolveEditorFromEvent(element) || window.currentUE || window.ue || window.editor || null;
        
        if (!editorInstance) {
            console.error('OpenImagesList: 找不到编辑器实例');
            TodoModal.toast('编辑器未就绪，请稍后再试', 'warning');
            return false;
        }

        // 检查编辑器是否已准备就绪
        if (!editorInstance.isReady) {
            // 等待编辑器就绪后再执行
            setTimeout(function () {
                window.OpenImagesList(event, element, multiple);
            }, 500);
            return false;
        }

        // 使用TodoCMS媒体模态框
        if (typeof window.selectMediaForField === 'function') {
            window.selectMediaForField({
                title: multiple ? '选择多张图片' : '选择图片',
                button_text: '插入图片',
                multiple: multiple,
                type: 'image',
                callback: function (selectedMedia) {
                    var mediaArray = Array.isArray(selectedMedia) ? selectedMedia : (selectedMedia ? [selectedMedia] : []);
                    if (mediaArray.length === 0) return;

                    var html = '';
                    mediaArray.forEach(function (media) {
                        if (media && media.url) {
                            if (typeof window.formatMediaHtmlForEditor === 'function') {
                                html += window.formatMediaHtmlForEditor(media);
                            } else {
                                var title = media.title || media.alt || media.filename || media.name || '';
                                var isImage = (media.type && media.type.indexOf('image/') === 0) || /\.(jpg|jpeg|png|gif|webp|bmp|svg)$/i.test(media.url || '');
                                if (isImage) {
                                    html += '<img src="' + media.url + '" alt="' + title + '" title="' + title + '" />';
                                } else {
                                    html += '<a href="' + media.url + '" rel="nofollow" target="_blank" title="' + title + '">' + (title || media.url) + '</a>';
                                }
                            }
                        }
                    });

                    if (html) {
                        try {
                            // 确保编辑器焦点
                            editorInstance.focus();
                            // 插入HTML内容
                            editorInstance.execCommand('inserthtml', html);
                        } catch (err) {
                            console.error('插入图片时出错:', err);
                            TodoModal.toast('插入图片失败，请重试', 'error');
                        }
                    }
                }
            });
        } else {
            // 降级到UEditor原生对话框
            if (editorInstance && editorInstance.execCommand) {
                editorInstance.execCommand('insertimage');
            } else {
                console.error('UEditor实例未找到，无法打开图片对话框');
                TodoModal.toast('编辑器未就绪，请稍后再试', 'warning');
            }
        }

        return false;
    };

    // 上传图片到服务器的函数
    function uploadImageToServer(file, callback) {
        var formData = new FormData();
        formData.append('upfile', file); // 使用UEditor配置的字段名

        // 使用fetch API上传文件
        fetch(((window.TODO && window.TODO.join) ? window.TODO.join(window.TODO.ADMIN_BASE, 'assets/js/ueditor/php/controller.php') : '/admin/assets/js/ueditor/php/controller.php') + '?action=uploadimage', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (result) {
                if (result.state === 'SUCCESS') {
                    callback(true, result);
                } else {
                    callback(false, result.state || '上传失败');
                }
            })
            .catch(function (error) {
                console.error('上传请求失败:', error);
                callback(false, '网络错误: ' + error.message);
            });
    }

    // 插入图片到编辑器的函数
    function insertImageToEditor(editorInstance, imgHtml, fileName) {
        try {
            // 确保编辑器获得焦点
            editorInstance.focus();

            var inserted = false;

            // 方法1: 使用execCommand插入HTML
            try {
                editorInstance.execCommand('inserthtml', imgHtml);
                inserted = true;
                console.log('图片插入成功 (方法1):', fileName);
            } catch (e1) {
                console.warn('方法1失败:', e1);
            }

            // 方法2: 如果方法1失败，尝试追加到内容
            if (!inserted) {
                try {
                    var currentContent = editorInstance.getContent();
                    editorInstance.setContent(currentContent + imgHtml);
                    inserted = true;
                    console.log('图片插入成功 (方法2):', fileName);
                } catch (e2) {
                    console.warn('方法2失败:', e2);
                }
            }

            // 方法3: 如果前两种方法都失败，使用selection和range
            if (!inserted) {
                try {
                    var selection = editorInstance.selection;
                    var range = selection.getRange();
                    var imgNode = UE.uNode.createElement(imgHtml);
                    range.insertNode(imgNode);
                    inserted = true;
                    console.log('图片插入成功 (方法3):', fileName);
                } catch (e3) {
                    console.warn('方法3失败:', e3);
                }
            }

            if (!inserted) {
                console.error('所有插入方法都失败了:', fileName);
                TodoModal.toast('插入图片 "' + fileName + '" 失败，请重试', 'error');
            } else {
                // 标记内容已更改
                markAsChanged();
            }

        } catch (insertError) {
            console.error('插入图片失败:', insertError, fileName);
            TodoModal.toast('插入图片 "' + fileName + '" 时发生错误', 'error');
        }
    }

    // 全局函数：标记内容已更改
    function markAsChanged() {
        hasChanges = true;
        if (autoSaveStatus) {
            autoSaveStatus.textContent = '自动保存: 有未保存的更改';
            autoSaveStatus.className = 'auto-save-pending';
        }

        // 清除之前的定时器
        if (autoSaveTimer) {
            clearTimeout(autoSaveTimer);
        }

        // 设置新的自动保存定时器（30秒后保存）
        autoSaveTimer = setTimeout(function () {
            autoSaveDraft();
        }, 30000);
    }

    // 全局函数：自动保存草稿
    function autoSaveDraft() {
        if (!hasChanges) return;
        // 提交中不触发自动保存，避免与表单提交竞争 session 写入
        if (window.__todo_is_submitting) return;

        var titleInput = document.getElementById('post-title');
        var content = '';
        if (window.ue && window.ue.ready) {
            content = window.ue.getContent();
        } else {
            var editor = document.getElementById('post-content');
            if (editor) {
                content = editor.value;
            }
        }

        var formData = new FormData();
        formData.append('action', 'auto_save');
        formData.append('title', titleInput ? titleInput.value : '');
        formData.append('content', content);
        formData.append('_todononce', document.querySelector('input[name="_todononce"]') ? document.querySelector('input[name="_todononce"]').value : '');

        if (autoSaveStatus) {
            autoSaveStatus.textContent = '自动保存: 保存中...';
            autoSaveStatus.className = 'auto-save-saving';
        }

        fetch(ajaxUrl, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                if (!data) return;
                if (data.success) {
                    hasChanges = false;
                    if (autoSaveStatus) {
                        autoSaveStatus.textContent = '自动保存: 已保存';
                        autoSaveStatus.className = 'auto-save-saved';
                    }
                    if (lastSaved) {
                        var t = data.saved_at_full || data.time || data.saved_at || '';
                        lastSaved.textContent = t ? ('最后保存: ' + t) : '';
                    }
                } else {
                    if (autoSaveStatus) {
                        autoSaveStatus.textContent = '自动保存: 保存失败';
                        autoSaveStatus.className = 'auto-save-error';
                    }
                }
            })
            .catch(error => {
                console.error('自动保存失败:', error);
                if (autoSaveStatus) {
                    autoSaveStatus.textContent = '自动保存: 保存失败';
                    autoSaveStatus.className = 'auto-save-error';
                }
            });
    }

    // 页面初始化函数
    function initPostNewPage() {
        // 初始化全局变量
        autoSaveStatus = document.getElementById('auto-save-status');
        lastSaved = document.getElementById('last-saved');

        // 初始化UEditor编辑器
        initUEditor();

        // 保留自动保存功能
        initAutoSave();

        // 初始化内容变化监听器
        initContentChangeListeners();

        // 快捷键支持
        initKeyboardShortcuts();

        // 初始化MathJax渲染
        initMathJaxRendering();

        // 初始化可见性处理
        initVisibilityHandling();

        // 初始化保存草稿按钮
        initSaveDraftButton();

        // 延迟初始化特色图片功能，确保媒体模态框已加载
        setTimeout(function () {
            initFeaturedImage();
        }, 100);
    }

    // 判断页面加载状态并执行初始化
    if (document.readyState === 'loading') {
        // DOM 还在加载中，使用 DOMContentLoaded 事件
        document.addEventListener('DOMContentLoaded', initPostNewPage);
    } else {
        // DOM 已经加载完成，直接执行初始化
        // 添加小延迟确保 UEditor 脚本已加载
        setTimeout(initPostNewPage, 100);
    }

    // 特色图片相关功能
    function setFeaturedImage(media) {
        document.getElementById('featured-image-id').value = media.id;
        document.getElementById('featured-image-url').value = media.url;

        var preview = document.getElementById('featured-image-preview');
        preview.innerHTML = '<img src="' + media.url + '" style="max-width: 200px; height: auto;"><a href="javascript:void(0);" id="remove-featured-image">移除特色图片</a>';

        // 更新按钮文本
        document.getElementById('set-featured-image-btn').textContent = '更换特色图片';
    }

    function removeFeaturedImage() {
        document.getElementById('featured-image-id').value = '';
        document.getElementById('featured-image-url').value = '';

        var preview = document.getElementById('featured-image-preview');
        preview.innerHTML = '<p class="no-featured-image">没有设置特色图片</p>';

        // 更新按钮文本
        document.getElementById('set-featured-image-btn').textContent = '设置特色图片';
    }

    // 初始化特色图片功能
    function initFeaturedImage() {
        var setFeaturedImageBtn = document.getElementById('set-featured-image-btn');
        if (setFeaturedImageBtn) {
            setFeaturedImageBtn.addEventListener('click', function () {
                // 使用媒体选择器
                if (typeof selectMediaForField === 'function') {
                    selectMediaForField({
                        title: '选择特色图片',
                        button_text: '设置为特色图片',
                        multiple: false,
                        type: 'image',
                        callback: setFeaturedImage
                    });
                } else {
                    console.error('selectMediaForField函数未定义');
                }
            });
        }
    }

    // 保存草稿按钮处理
    function initSaveDraftButton() {
        var saveDraftButton = document.getElementById('save-draft-button');

        if (saveDraftButton) {
            saveDraftButton.addEventListener('click', function () {
                var statusSelect = document.getElementById('post-status');
                if (statusSelect) {
                    statusSelect.value = 'draft';
                }
            });
        }
    }

    // 初始化UEditor编辑器
    function initUEditor() {
        if (typeof UE === 'undefined') {
            console.error('UEditor未加载');
            return;
        }

        initUEditorInstance();
    }

    // UEditor 实例初始化
    function initUEditorInstance() {
        // 检查容器元素是否存在
        var container = document.getElementById('post-content');
        if (!container) {
            console.error('找不到post-content元素');
            return;
        }

        // 修复iframe跨框架调用时UEditor的Mask组件访问错误
        // 在UEditor初始化之前就保护UI组件
        if (window.parent && window.parent !== window) {
            // 等待UEditor加载后再保护
            var protectUEditorUI = function() {
                try {
                    // 保护UEditor的Mask组件
                    if (window.UE && window.UE.ui && window.UE.ui.Mask) {
                        var originalIsHidden = window.UE.ui.Mask.prototype.isHidden;
                        if (!originalIsHidden._protected) {
                            window.UE.ui.Mask.prototype.isHidden = function() {
                                try {
                                    // 确保DOM元素存在再访问style
                                    if (this.getDom && typeof this.getDom === 'function') {
                                        var dom = this.getDom();
                                        if (!dom) {
                                            return true; // 如果DOM不存在，认为是隐藏的
                                        }
                                    }
                                    return originalIsHidden.call(this);
                                } catch (e) {
                                    console.warn('UEditor Mask.isHidden error (suppressed):', e.message);
                                    return true; // 出错时默认返回隐藏状态
                                }
                            };
                            window.UE.ui.Mask.prototype.isHidden._protected = true;
                        }
                    }

                    // 保护baidu.editor命名空间的Mask组件
                    if (window.baidu && window.baidu.editor && window.baidu.editor.ui && window.baidu.editor.ui.Mask) {
                        var baiduOriginalIsHidden = window.baidu.editor.ui.Mask.prototype.isHidden;
                        if (!baiduOriginalIsHidden._protected) {
                            window.baidu.editor.ui.Mask.prototype.isHidden = function() {
                                try {
                                    if (this.getDom && typeof this.getDom === 'function') {
                                        var dom = this.getDom();
                                        if (!dom) {
                                            return true;
                                        }
                                    }
                                    return baiduOriginalIsHidden.call(this);
                                } catch (e) {
                                    console.warn('Baidu Editor Mask.isHidden error (suppressed):', e.message);
                                    return true;
                                }
                            };
                            window.baidu.editor.ui.Mask.prototype.isHidden._protected = true;
                        }
                    }
                } catch (protectError) {
                    console.warn('Failed to protect UEditor UI components:', protectError);
                }
            };

            // 尝试立即保护，如果UEditor已加载
            protectUEditorUI();
            
            // 如果还没加载，在初始化后再次尝试
            setTimeout(protectUEditorUI, 100);
        }

        // 如果编辑器已经初始化，先销毁它
        if (window.ue) {
            try {
                window.ue.destroy();
                window.ue = null;
            } catch(e) {
                console.warn('销毁旧编辑器实例失败:', e);
            }
        }

        // 设置UEditor根路径
        window.UEDITOR_HOME_URL = (window.TODO && window.TODO.join) ? window.TODO.join(window.TODO.ADMIN_BASE, 'assets/js/ueditor/') : '/admin/assets/js/ueditor/';

        // 获取post-content元素的宽度，如果获取失败则默认为500
        var postContentElement = document.getElementById('post-content');
        // 在初始化UEditor之前，将textarea的值从实体形式解码为真实HTML
        try {
            function decodeHtmlEntities(str) {
                if (!str) return '';
                var textarea = document.createElement('textarea');
                textarea.innerHTML = str;
                return textarea.value;
            }
            var originalVal = postContentElement && typeof postContentElement.value === 'string' ? postContentElement.value : '';
            var decodedVal = decodeHtmlEntities(originalVal);
            if (decodedVal !== originalVal) {
                postContentElement.value = decodedVal;
            }
        } catch (e) {
            console.warn('解码HTML实体时出错:', e);
        }
        var initialWidth = postContentElement ? postContentElement.offsetWidth : 500;

        // 初始化UEditor
        window.ue = UE.getEditor('post-content', {
            initialFrameWidth: initialWidth,
            initialFrameHeight: 400,
            autoHeightEnabled: true,
            toolbars: [[
                'fullscreen', 'source', '|', 'undo', 'redo', '|',
                'bold', 'italic', 'underline', 'fontborder', 'strikethrough', 'superscript', 'subscript', 'removeformat', 'formatmatch', 'autotypeset', 'blockquote', 'pasteplain', '|', 'forecolor', 'backcolor', 'insertorderedlist', 'insertunorderedlist', '|',
                'rowspacingtop', 'rowspacingbottom', 'lineheight',
                'customstyle', 'paragraph', 'fontfamily', 'fontsize',
                'directionalityltr', 'directionalityrtl', 'indent', '|',
                'justifyleft', 'justifycenter', 'justifyright', 'justifyjustify', '|',
                'link', 'unlink', 'anchor', '|', 'imagenone', 'imageleft', 'imageright', 'imagecenter', '|',
                'simpleupload', 'insertimage', 'emotion', 'insertvideo', 'map', 'gmap', 'insertframe', 'insertcode', 'pagebreak', 'template', '|',
                'horizontal', 'date', 'time', 'spechars', '|',
                'inserttable', 'deletetable', 'insertparagraphbeforetable', 'insertrow', 'deleterow', 'insertcol', 'deletecol', 'mergecells', 'mergeright', 'mergedown', 'splittocells', 'splittorows', 'splittocols', '|',
                'print', 'preview', 'searchreplace', 'drafts', 'previewmobile'
            ]],
            elementPathEnabled: false,
            wordCount: true,
            maximumWords: 50000,
            // 图片上传配置
            serverUrl: ((window.TODO && window.TODO.join) ? window.TODO.join(window.TODO.ADMIN_BASE, 'assets/js/ueditor/php/controller.php') : '/admin/assets/js/ueditor/php/controller.php'),
            // 添加分类ID参数
            serverParam: {
                'category_id': function () {
                    // 获取当前文章的分类ID
                    var categorySelect = document.querySelector('select[name="post_category"]');
                    return categorySelect ? categorySelect.value : null;
                }
            },
            // 允许的文件类型
            allowDivTransToP: false,
            // 粘贴配置
            retainOnlyLabelPasted: false,
            pasteplain: false,
            // 自动保存
            enableAutoSave: false,
            saveInterval: 30000, // 30秒自动保存一次（已禁用）
            // 多图上传
            insertImageUrlPrefix: '',
            // 工具栏浮动
            autoFloatEnabled: false
        });

        // 编辑器准备就绪后的回调
        window.ue.ready(function () {
            // 设置全局editor变量以兼容OpenImagesList函数
            window.editor = window.ue;

            // 模式切换时忽略内容变化，防止点击“源代码”触发自动保存
            window.ue.addListener('beforeexeccommand', function (type, cmd) {
                if (cmd === 'source') {
                    window.ue.__todo_is_source_toggling = true;
                }
            });
            window.ue.addListener('afterexeccommand', function (type, cmd) {
                if (cmd === 'source') {
                    // 稍微延迟恢复，确保由切换模式引起的同步 contentChange 已被忽略
                    setTimeout(function() {
                        window.ue.__todo_is_source_toggling = false;
                    }, 200);
                }
            });

            // 监听内容变化，用于自动保存
            window.ue.addListener('contentChange', function () {
                // 如果正在切换源码模式，或者当前处于源码模式，跳过自动保存
                if (window.ue.__todo_is_source_toggling || window.ue.queryCommandState('source') === 1) {
                    return;
                }
                // 表单提交过程中不触发自动保存，避免与提交过程竞争
                if (window.__todo_is_submitting) {
                    return;
                }

                markAsChanged();
                // 延迟执行自动保存，避免频繁保存
                clearTimeout(window.autoSaveTimer);
                window.autoSaveTimer = setTimeout(function () {
                    autoSaveDraft();
                }, 5000); // 5秒后自动保存
            });
        });

        // 表单提交前同步编辑器内容
        var postForm = document.getElementById('post-form');
        if (postForm) {
            postForm.addEventListener('submit', function (e) {
                // 表单提交前同步所有编辑器内容
                if (window.UE && (window.UE.instants || window.UE.instances)) {
                    var instants = window.UE.instants || window.UE.instances;
                    for (var key in instants) {
                        if (Object.prototype.hasOwnProperty.call(instants, key)) {
                            try {
                                instants[key].sync();
                            } catch (err) {
                                console.warn('UEditor sync failed for ' + key, err);
                            }
                        }
                    }
                } else if (window.ue && typeof window.ue.hasContents === 'function' && window.ue.hasContents()) {
                    var pcEl = document.getElementById('post-content');
                    if (pcEl) {
                        pcEl.value = window.ue.getContent();
                    }
                }
                // 重置变更标志，避免离开页面时弹窗
                hasChanges = false;
            });
        }
    }

    // 自动保存功能
    function initAutoSave() {
        var titleInput = document.getElementById('post-title');

        // 清除自动保存草稿
        function clearAutoDraft() {
            var formData = new FormData();
            formData.append('action', 'clear_auto_draft');
            formData.append('_todononce', document.querySelector('input[name="_todononce"]') ? document.querySelector('input[name="_todononce"]').value : '');

            fetch(ajaxUrl, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // 隐藏自动草稿提示
                        var notice = document.querySelector('.auto-draft-notice');
                        if (notice) {
                            notice.style.display = 'none';
                        }
                        
                        // 清空表单内容
                        var titleInput = document.getElementById('post-title');
                        var contentTextarea = document.getElementById('post-content');
                        
                        if (titleInput) {
                            titleInput.value = '';
                        }
                        
                        if (contentTextarea) {
                            contentTextarea.value = '';
                        }
                        
                        // 如果使用了 UEditor，也清空编辑器内容
                        if (window.ue && window.ue.ready) {
                            window.ue.setContent('');
                        }
                        
                        // 显示成功提示
                        if (typeof TodoCMS !== 'undefined' && TodoCMS.Utils && TodoCMS.Utils.showMessage) {
                            TodoCMS.Utils.showMessage('草稿已清除', 'success');
                        }
                    } else {
                        // 显示错误提示
                        if (typeof TodoCMS !== 'undefined' && TodoCMS.Utils && TodoCMS.Utils.showMessage) {
                            TodoCMS.Utils.showMessage(data.message || '清除草稿失败', 'error');
                        }
                    }
                })
                .catch(error => {
                    console.error('清除草稿失败:', error);
                    if (typeof TodoCMS !== 'undefined' && TodoCMS.Utils && TodoCMS.Utils.showMessage) {
                        TodoCMS.Utils.showMessage('清除草稿失败', 'error');
                    }
                });
        }

        // 绑定清除草稿按钮事件
        var clearDraftBtn = document.getElementById('clear-auto-draft');
        if (clearDraftBtn) {
            clearDraftBtn.addEventListener('click', function (e) {
                e.preventDefault();
                TodoModal.confirm({
                    title: '清除草稿',
                    message: '确定要清除自动保存的草稿吗？',
                    confirmText: '确定',
                    cancelText: '取消',
                    onConfirm: function () {
                        clearAutoDraft();
                        return true; // 允许关闭弹窗
                    }
                });
            });
        }
    }

    // MathJax渲染支持
    function initMathJaxRendering() {
        // 创建一个预览区域用于显示渲染后的内容
        var editor = document.getElementById('post-content');
        if (!editor) return;

        // 创建预览容器
        var previewContainer = document.createElement('div');
        previewContainer.id = 'content-preview';
        previewContainer.style.cssText = `
            display: none;
            border: 1px solid #eceef1;
            padding: 15px;
            margin-top: 10px;
            background: #f9f9f9;
            border-radius: 4px;
            max-height: 400px;
            overflow-y: auto;
        `;

        // 插入预览容器
        var editorContainer = document.getElementById('editor-container');
        if (editorContainer) {
            editorContainer.appendChild(previewContainer);
        }

        // 创建预览切换按钮
        var previewToggle = document.createElement('button');
        previewToggle.type = 'button';
        previewToggle.className = 'button';
        previewToggle.textContent = '预览';
        previewToggle.style.marginLeft = '10px';

        // 添加预览按钮到工具栏
        var editorMeta = document.querySelector('.editor-meta-info');
        if (editorMeta) {
            editorMeta.appendChild(previewToggle);
        }

        // 预览切换功能
        var isPreviewMode = false;
        previewToggle.addEventListener('click', function () {
            if (isPreviewMode) {
                // 切换到编辑模式
                editor.style.display = 'block';
                previewContainer.style.display = 'none';
                previewToggle.textContent = '预览';
                isPreviewMode = false;
            } else {
                // 切换到预览模式
                editor.style.display = 'none';
                previewContainer.style.display = 'block';
                previewToggle.textContent = '编辑';
                isPreviewMode = true;

                // 更新预览内容
                updatePreview();
            }
        });

        // 更新预览内容
        function updatePreview() {
            var content = editor.value;

            // 转换Markdown风格的数学公式为MathJax格式
            content = content.replace(/\$\$([^$]+)\$\$/g, '\\[$1\\]'); // 块级公式
            content = content.replace(/\$([^$]+)\$/g, '\\($1\\)'); // 行内公式

            // 转换换行符为HTML
            content = content.replace(/\n/g, '<br>');

            previewContainer.innerHTML = content;

            // 重新渲染MathJax
            if (window.MathJax && window.MathJax.typesetPromise) {
                window.MathJax.typesetPromise([previewContainer]).catch(function (err) {
                    console.error('MathJax渲染错误:', err);
                });
            }
        }

        // 监听编辑器内容变化，实时更新预览
        var updateTimer;
        editor.addEventListener('input', function () {
            if (isPreviewMode) {
                // 防抖处理，避免频繁更新
                clearTimeout(updateTimer);
                updateTimer = setTimeout(updatePreview, 500);
            }
        });
    }

    // 显示数学公式插入对话框
    function showMathFormulaDialog() {
        if (!window.ue || !window.ue.ready) {
            console.error('UEditor编辑器未找到或未就绪');
            return;
        }

        // 创建对话框HTML
        var dialogHTML = `
             <div id="math-formula-dialog" style="
                 position: fixed;
                 top: 0;
                 left: 0;
                 right: 0;
                 bottom: 0;
                 background: rgba(0,0,0,0.5);
                 z-index: 10000;
                 display: flex;
                 align-items: center;
                 justify-content: center;
             ">
                 <div style="
                     background: white;
                     padding: 20px;
                     border-radius: 8px;
                     width: 500px;
                     max-width: 90vw;
                     box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                 ">
                     <h3 style="margin-top: 0;">插入数学公式</h3>
                     
                     <div style="margin-bottom: 15px;">
                         <label style="display: block; margin-bottom: 5px;">公式类型:</label>
                         <select id="formula-type" style="width: 100%; padding: 8px; border: 1px solid #eceef1; border-radius: 4px;">
                             <option value="inline">行内公式 $...$</option>
                             <option value="block">独立公式 $$...$$</option>
                         </select>
                     </div>
                     
                     <div style="margin-bottom: 15px;">
                         <label style="display: block; margin-bottom: 5px;">LaTeX公式:</label>
                         <textarea id="formula-input" placeholder="例如: E = mc^2 或 \\frac{a}{b} 或 \\sum_{i=1}^{n} x_i" style="
                             width: 100%;
                             height: 100px;
                             padding: 8px;
                             border: 1px solid #eceef1;
                             border-radius: 4px;
                             font-family: monospace;
                             resize: vertical;
                         "></textarea>
                     </div>
                     
                     <div style="margin-bottom: 15px;">
                         <label style="display: block; margin-bottom: 5px;">常用公式模板:</label>
                         <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 5px;">
                             <button type="button" class="formula-template" data-formula="E = mc^2">能量公式</button>
                             <button type="button" class="formula-template" data-formula="\\frac{a}{b}">分数</button>
                             <button type="button" class="formula-template" data-formula="\\sqrt{x}">平方根</button>
                             <button type="button" class="formula-template" data-formula="x^{2}">上标</button>
                             <button type="button" class="formula-template" data-formula="x_{i}">下标</button>
                             <button type="button" class="formula-template" data-formula="\\sum_{i=1}^{n} x_i">求和</button>
                             <button type="button" class="formula-template" data-formula="\\int_{a}^{b} f(x) dx">积分</button>
                             <button type="button" class="formula-template" data-formula="\\lim_{x \\to \\infty}">极限</button>
                             <button type="button" class="formula-template" data-formula="\\alpha \\beta \\gamma">希腊字母</button>
                             <button type="button" class="formula-template" data-formula="\\begin{matrix} a & b \\\\ c & d \\end{matrix}">矩阵</button>
                         </div>
                     </div>
                     
                     <div id="formula-preview" style="
                         margin-bottom: 15px;
                         padding: 10px;
                         border: 1px solid #eceef1;
                         border-radius: 4px;
                         background: #f9f9f9;
                         min-height: 40px;
                         display: flex;
                         align-items: center;
                         justify-content: center;
                     ">预览将在这里显示</div>
                     
                     <div style="text-align: right;">
                         <button type="button" id="cancel-formula" style="
                             margin-right: 10px;
                             padding: 8px 16px;
                             border: 1px solid #eceef1;
                             background: white;
                             border-radius: 4px;
                             cursor: pointer;
                         ">取消</button>
                         <button type="button" id="insert-formula" style="
                             padding: 8px 16px;
                             border: none;
                             background: #0073aa;
                             color: white;
                             border-radius: 4px;
                             cursor: pointer;
                         ">插入</button>
                     </div>
                 </div>
             </div>
         `;

        // 添加对话框到页面
        document.body.insertAdjacentHTML('beforeend', dialogHTML);

        var dialog = document.getElementById('math-formula-dialog');
        var formulaInput = document.getElementById('formula-input');
        var formulaType = document.getElementById('formula-type');
        var formulaPreview = document.getElementById('formula-preview');
        var insertBtn = document.getElementById('insert-formula');
        var cancelBtn = document.getElementById('cancel-formula');

        // 模板按钮事件
        var templateBtns = dialog.querySelectorAll('.formula-template');
        templateBtns.forEach(function (btn) {
            btn.style.cssText = 'padding: 5px 8px; margin: 2px; border: 1px solid #eceef1; background: white; border-radius: 3px; cursor: pointer; font-size: 12px;';
            btn.addEventListener('click', function () {
                formulaInput.value = this.dataset.formula;
                updatePreview();
            });
        });

        // 预览更新函数
        function updatePreview() {
            var formula = formulaInput.value.trim();
            if (!formula) {
                formulaPreview.innerHTML = '预览将在这里显示';
                return;
            }

            var type = formulaType.value;
            var displayFormula = type === 'block' ? '\\[' + formula + '\\]' : '\\(' + formula + '\\)';

            formulaPreview.innerHTML = displayFormula;

            // 渲染MathJax
            if (window.MathJax && window.MathJax.typesetPromise) {
                window.MathJax.typesetPromise([formulaPreview]).catch(function (err) {
                    formulaPreview.innerHTML = '<span style="color: #d43838;">公式语法错误</span>';
                });
            }
        }

        // 输入变化时更新预览
        formulaInput.addEventListener('input', updatePreview);
        formulaType.addEventListener('change', updatePreview);

        // 插入按钮事件
        insertBtn.addEventListener('click', function () {
            var formula = formulaInput.value.trim();
            if (!formula) {
                TodoModal.toast('请输入公式内容', 'warning');
                return;
            }

            var type = formulaType.value;
            var insertText = type === 'block' ? '$$' + formula + '$$' : '$' + formula + '$';

            // 插入到UEditor编辑器
            if (window.ue && window.ue.ready) {
                window.ue.execCommand('inserthtml', insertText);
            }

            // 关闭对话框
            dialog.remove();
        });

        // 取消按钮事件
        cancelBtn.addEventListener('click', function () {
            dialog.remove();
        });

        // 点击背景关闭
        dialog.addEventListener('click', function (e) {
            if (e.target === dialog) {
                dialog.remove();
            }
        });

        // ESC键关闭
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && dialog) {
                dialog.remove();
            }
        });

        // 聚焦输入框
        formulaInput.focus();
    }

    // 显示代码块插入对话框
    function showCodeBlockDialog() {
        if (!window.ue || !window.ue.ready) {
            console.error('UEditor编辑器未找到或未就绪');
            return;
        }

        // 创建对话框HTML
        var dialogHTML = `
             <div id="code-block-dialog" style="
                 position: fixed;
                 top: 0;
                 left: 0;
                 right: 0;
                 bottom: 0;
                 background: rgba(0,0,0,0.5);
                 z-index: 10000;
                 display: flex;
                 align-items: center;
                 justify-content: center;
             ">
                 <div style="
                     background: white;
                     padding: 20px;
                     border-radius: 8px;
                     width: 600px;
                     max-width: 90vw;
                     box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                 ">
                     <h3 style="margin-top: 0;">插入代码块</h3>
                     
                     <div style="margin-bottom: 15px;">
                         <label style="display: block; margin-bottom: 5px;">编程语言:</label>
                         <select id="code-language" style="width: 100%; padding: 8px; border: 1px solid #eceef1; border-radius: 4px;">
                             <option value="">纯文本</option>
                             <option value="javascript">JavaScript</option>
                             <option value="python">Python</option>
                             <option value="java">Java</option>
                             <option value="php">PHP</option>
                             <option value="html">HTML</option>
                             <option value="css">CSS</option>
                             <option value="sql">SQL</option>
                             <option value="bash">Bash</option>
                             <option value="json">JSON</option>
                             <option value="xml">XML</option>
                             <option value="c">C</option>
                             <option value="cpp">C++</option>
                             <option value="csharp">C#</option>
                             <option value="go">Go</option>
                             <option value="rust">Rust</option>
                             <option value="swift">Swift</option>
                             <option value="kotlin">Kotlin</option>
                             <option value="typescript">TypeScript</option>
                             <option value="ruby">Ruby</option>
                             <option value="perl">Perl</option>
                             <option value="r">R</option>
                             <option value="matlab">MATLAB</option>
                             <option value="scala">Scala</option>
                             <option value="dart">Dart</option>
                         </select>
                     </div>
                     
                     <div style="margin-bottom: 15px;">
                         <label style="display: block; margin-bottom: 5px;">代码内容:</label>
                         <textarea id="code-input" placeholder="在这里输入您的代码..." style="
                             width: 100%;
                             height: 200px;
                             padding: 8px;
                             border: 1px solid #eceef1;
                             border-radius: 4px;
                             font-family: 'Courier New', monospace;
                             font-size: 14px;
                             resize: vertical;
                             tab-size: 4;
                         "></textarea>
                     </div>
                     
                     <div style="margin-bottom: 15px;">
                         <label style="display: block; margin-bottom: 5px;">常用代码模板:</label>
                         <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 5px;">
                             <button type="button" class="code-template" data-lang="javascript" data-code="function example() {\n    console.log('Hello World!');\n}">JavaScript 函数</button>
                             <button type="button" class="code-template" data-lang="python" data-code="def example():\n    print('Hello World!')">Python 函数</button>
                             <button type="button" class="code-template" data-lang="html" data-code="<!DOCTYPE html>\n<html>\n<head>\n    <title>页面标题</title>\n</head>\n<body>\n    <h1>Hello World!</h1>\n</body>\n</html>">HTML 模板</button>
                             <button type="button" class="code-template" data-lang="css" data-code=".example {\n    color: #333;\n    font-size: 16px;\n    margin: 10px 0;\n}">CSS 样式</button>
                             <button type="button" class="code-template" data-lang="sql" data-code="SELECT * FROM users\nWHERE status = 'active'\nORDER BY created_at DESC;">SQL 查询</button>
                             <button type="button" class="code-template" data-lang="bash" data-code="#!/bin/bash\necho 'Hello World!'\nls -la">Bash 脚本</button>
                         </div>
                     </div>
                     
                     <div style="text-align: right;">
                         <button type="button" id="cancel-code" style="
                             margin-right: 10px;
                             padding: 8px 16px;
                             border: 1px solid #eceef1;
                             background: white;
                             border-radius: 4px;
                             cursor: pointer;
                         ">取消</button>
                         <button type="button" id="insert-code" style="
                             padding: 8px 16px;
                             border: none;
                             background: #0073aa;
                             color: white;
                             border-radius: 4px;
                             cursor: pointer;
                         ">插入</button>
                     </div>
                 </div>
             </div>
         `;

        // 添加对话框到页面
        document.body.insertAdjacentHTML('beforeend', dialogHTML);

        var dialog = document.getElementById('code-block-dialog');
        var codeInput = document.getElementById('code-input');
        var codeLanguage = document.getElementById('code-language');
        var insertBtn = document.getElementById('insert-code');
        var cancelBtn = document.getElementById('cancel-code');

        // 模板按钮事件
        var templateBtns = dialog.querySelectorAll('.code-template');
        templateBtns.forEach(function (btn) {
            btn.style.cssText = 'padding: 5px 8px; margin: 2px; border: 1px solid #eceef1; background: white; border-radius: 3px; cursor: pointer; font-size: 12px;';
            btn.addEventListener('click', function () {
                codeLanguage.value = this.dataset.lang;
                codeInput.value = this.dataset.code;
                codeInput.focus();
            });
        });

        // Tab键支持
        codeInput.addEventListener('keydown', function (e) {
            if (e.key === 'Tab') {
                e.preventDefault();
                var start = this.selectionStart;
                var end = this.selectionEnd;

                // 插入4个空格
                this.value = this.value.substring(0, start) + '    ' + this.value.substring(end);
                this.selectionStart = this.selectionEnd = start + 4;
            }
        });

        // 插入按钮事件
        insertBtn.addEventListener('click', function () {
            var code = codeInput.value.trim();
            if (!code) {
                TodoModal.toast('请输入代码内容', 'warning');
                return;
            }

            var language = codeLanguage.value;
            var insertText = '<pre><code class="language-' + language + '">' + code.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</code></pre>';

            // 插入到UEditor编辑器
            if (window.ue && window.ue.ready) {
                window.ue.execCommand('inserthtml', insertText);
            }

            // 关闭对话框
            dialog.remove();
        });

        // 取消按钮事件
        cancelBtn.addEventListener('click', function () {
            dialog.remove();
        });

        // 点击背景关闭
        dialog.addEventListener('click', function (e) {
            if (e.target === dialog) {
                dialog.remove();
            }
        });

        // ESC键关闭
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && dialog) {
                dialog.remove();
            }
        });

        // 聚焦输入框
        codeInput.focus();
    }

    // 显示表格插入对话框
    function showTableDialog() {
        if (!window.ue || !window.ue.ready) {
            console.error('UEditor编辑器未找到或未就绪');
            return;
        }

        // 创建对话框HTML
        var dialogHTML = `
             <div id="table-dialog" style="
                 position: fixed;
                 top: 0;
                 left: 0;
                 right: 0;
                 bottom: 0;
                 background: rgba(0,0,0,0.5);
                 z-index: 10000;
                 display: flex;
                 align-items: center;
                 justify-content: center;
             ">
                 <div style="
                     background: white;
                     padding: 20px;
                     border-radius: 8px;
                     width: 500px;
                     max-width: 90vw;
                     box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                 ">
                     <h3 style="margin-top: 0;">插入表格</h3>
                     
                     <div style="margin-bottom: 15px;">
                         <label style="display: block; margin-bottom: 5px;">行数:</label>
                         <input type="number" id="table-rows" min="1" max="20" value="3" style="width: 100%; padding: 8px; border: 1px solid #eceef1; border-radius: 4px;">
                     </div>
                     
                     <div style="margin-bottom: 15px;">
                         <label style="display: block; margin-bottom: 5px;">列数:</label>
                         <input type="number" id="table-cols" min="1" max="10" value="3" style="width: 100%; padding: 8px; border: 1px solid #eceef1; border-radius: 4px;">
                     </div>
                     
                     <div style="margin-bottom: 15px;">
                         <label style="display: flex; align-items: center; gap: 8px;">
                             <input type="checkbox" id="table-header" checked>
                             <span>包含表头</span>
                         </label>
                     </div>
                     
                     <div style="margin-bottom: 15px;">
                         <label style="display: block; margin-bottom: 5px;">表格样式:</label>
                         <select id="table-style" style="width: 100%; padding: 8px; border: 1px solid #eceef1; border-radius: 4px;">
                             <option value="basic">基础样式</option>
                             <option value="striped">斑马纹</option>
                             <option value="bordered">带边框</option>
                             <option value="hover">悬停效果</option>
                         </select>
                     </div>
                     
                     <div id="table-preview" style="
                         margin-bottom: 15px;
                         padding: 10px;
                         border: 1px solid #eceef1;
                         border-radius: 4px;
                         background: #f9f9f9;
                         max-height: 200px;
                         overflow: auto;
                     ">表格预览将在这里显示</div>
                     
                     <div style="text-align: right;">
                         <button type="button" id="cancel-table" style="
                             margin-right: 10px;
                             padding: 8px 16px;
                             border: 1px solid #eceef1;
                             background: white;
                             border-radius: 4px;
                             cursor: pointer;
                         ">取消</button>
                         <button type="button" id="insert-table" style="
                             padding: 8px 16px;
                             border: none;
                             background: #0073aa;
                             color: white;
                             border-radius: 4px;
                             cursor: pointer;
                         ">插入</button>
                     </div>
                 </div>
             </div>
         `;

        // 添加对话框到页面
        document.body.insertAdjacentHTML('beforeend', dialogHTML);

        var dialog = document.getElementById('table-dialog');
        var rowsInput = document.getElementById('table-rows');
        var colsInput = document.getElementById('table-cols');
        var headerCheckbox = document.getElementById('table-header');
        var styleSelect = document.getElementById('table-style');
        var tablePreview = document.getElementById('table-preview');
        var insertBtn = document.getElementById('insert-table');
        var cancelBtn = document.getElementById('cancel-table');

        // 生成表格HTML
        function generateTableHTML(rows, cols, hasHeader, style) {
            var tableClass = 'table';
            switch (style) {
                case 'striped': tableClass += ' table-striped'; break;
                case 'bordered': tableClass += ' table-bordered'; break;
                case 'hover': tableClass += ' table-hover'; break;
            }

            var html = '<table class="' + tableClass + '">\n';

            // 表头
            if (hasHeader) {
                html += '  <thead>\n    <tr>\n';
                for (var j = 0; j < cols; j++) {
                    html += '      <th>表头 ' + (j + 1) + '</th>\n';
                }
                html += '    </tr>\n  </thead>\n';
            }

            // 表体
            html += '  <tbody>\n';
            var startRow = hasHeader ? 1 : 0;
            for (var i = startRow; i < rows; i++) {
                html += '    <tr>\n';
                for (var j = 0; j < cols; j++) {
                    html += '      <td>单元格 ' + (i + 1) + '-' + (j + 1) + '</td>\n';
                }
                html += '    </tr>\n';
            }
            html += '  </tbody>\n';
            html += '</table>';

            return html;
        }

        // 更新预览
        function updatePreview() {
            var rows = parseInt(rowsInput.value) || 3;
            var cols = parseInt(colsInput.value) || 3;
            var hasHeader = headerCheckbox.checked;
            var style = styleSelect.value;

            var tableHTML = generateTableHTML(rows, cols, hasHeader, style);
            tablePreview.innerHTML = tableHTML;
        }

        // 监听输入变化
        rowsInput.addEventListener('input', updatePreview);
        colsInput.addEventListener('input', updatePreview);
        headerCheckbox.addEventListener('change', updatePreview);
        styleSelect.addEventListener('change', updatePreview);

        // 初始预览
        updatePreview();

        // 插入按钮事件
        insertBtn.addEventListener('click', function () {
            var rows = parseInt(rowsInput.value) || 3;
            var cols = parseInt(colsInput.value) || 3;
            var hasHeader = headerCheckbox.checked;
            var style = styleSelect.value;

            if (rows < 1 || cols < 1) {
                TodoModal.toast('行数和列数必须大于0', 'warning');
                return;
            }

            var tableHTML = generateTableHTML(rows, cols, hasHeader, style);

            // 插入到UEditor编辑器
            if (window.ue && window.ue.ready) {
                window.ue.execCommand('inserthtml', tableHTML);
            }

            // 关闭对话框
            dialog.remove();
        });

        // 取消按钮事件
        cancelBtn.addEventListener('click', function () {
            dialog.remove();
        });

        // 点击背景关闭
        dialog.addEventListener('click', function (e) {
            if (e.target === dialog) {
                dialog.remove();
            }
        });

        // ESC键关闭
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && dialog) {
                dialog.remove();
            }
        });

        // 聚焦行数输入框
        rowsInput.focus();
    }

    // 监听内容变化
    function initContentChangeListeners() {
        var titleInput = document.getElementById('post-title');

        // UEditor编辑器内容变化监听
        if (window.ue && window.ue.ready) {
            window.ue.addListener('contentChange', function () {
                // 如果正在切换源码模式，或者当前处于源码模式，跳过
                if (window.ue.__todo_is_source_toggling || window.ue.queryCommandState('source') === 1) {
                    return;
                }
                markAsChanged();
            });
        }

        if (titleInput) {
            titleInput.addEventListener('input', markAsChanged);
        }

        // 页面卸载前保存
        window.addEventListener('beforeunload', function (e) {
            if (hasChanges && !window.__todo_is_submitting) {
                autoSaveDraft();
                e.preventDefault();
                e.returnValue = '您有未保存的更改，确定要离开吗？';
                return e.returnValue;
            }
        });
    }

    // 快捷键支持
    function initKeyboardShortcuts() {
        document.addEventListener('keydown', function (e) {
            // Ctrl+S 保存草稿
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                var saveDraftBtn = document.getElementById('save-draft-button');
                if (saveDraftBtn) {
                    saveDraftBtn.click();
                }
            }

            // Ctrl+Enter 发布
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                var publishBtn = document.querySelector('input[value="发布"]');
                if (publishBtn) {
                    publishBtn.click();
                }
            }

            // Ctrl+Shift+P 预览
            if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'P') {
                e.preventDefault();
                previewPost();
            }
        });
    }

    // 字段增强功能
    function initSEOFields() {
        var titleInput = document.getElementById('post-title');
        var seoTitleInput = document.getElementById('seo-title');
        var seoDescInput = document.getElementById('seo-description');
        var seoAutoUpdate = document.getElementById('seo-auto-update');

        // 字符计数功能
        function addCharacterCounter(input, maxLength, recommendedLength) {
            var counter = document.createElement('span');
            counter.className = 'character-counter';
            counter.style.cssText = 'font-size: 12px; color: #666; margin-left: 10px;';

            function updateCounter() {
                var length = input.value.length;
                counter.textContent = length + '/' + maxLength + ' 字符';

                if (length > maxLength) {
                    counter.style.color = '#dc3545';
                } else if (length > recommendedLength) {
                    counter.style.color = '#ffc107';
                } else {
                    counter.style.color = '#28a745';
                }
            }

            input.addEventListener('input', updateCounter);
            input.parentNode.querySelector('.description').appendChild(counter);
            updateCounter();
        }

        // 为字段添加字符计数
        if (seoTitleInput) {
            addCharacterCounter(seoTitleInput, 60, 50);
        }

        if (seoDescInput) {
            addCharacterCounter(seoDescInput, 160, 150);
        }

        // 自动填充SEO标题
        if (titleInput && seoTitleInput) {
            titleInput.addEventListener('input', function () {
                if (!seoTitleInput.value || seoTitleInput.value === titleInput.dataset.lastValue) {
                    seoTitleInput.value = this.value;
                }
                titleInput.dataset.lastValue = this.value;
            });
        }

        // 自动更新SEO描述
        if (seoAutoUpdate && seoDescInput) {
            function updateSEODescription() {
                if (seoAutoUpdate.checked && window.ue && window.ue.hasContents()) {
                    var content = window.ue.getPlainTxt();
                    if (content) {
                        // 提取前150个字符作为描述
                        var description = content.substring(0, 150).replace(/\s+/g, ' ').trim();
                        if (content.length > 150) {
                            description += '...';
                        }
                        seoDescInput.value = description;
                    }
                }
            }

            // 监听编辑器内容变化
            if (window.ue && window.ue.ready) {
                window.ue.addListener('contentChange', function () {
                    // 如果正在切换源码模式，或者当前处于源码模式，跳过
                    if (window.ue.__todo_is_source_toggling || window.ue.queryCommandState('source') === 1) {
                        return;
                    }
                    if (seoAutoUpdate.checked) {
                        clearTimeout(window.seoUpdateTimer);
                        window.seoUpdateTimer = setTimeout(updateSEODescription, 1000);
                    }
                });
            }

            // 监听自动更新开关
            seoAutoUpdate.addEventListener('change', function () {
                if (this.checked) {
                    updateSEODescription();
                }
            });
        }
    }

    // 多图上传功能
    function OpenImagesList() {
        // 更健壮的编辑器检查
        if (typeof window.ue === 'undefined' || !window.ue || typeof window.ue.getContent !== 'function') {
            TodoModal.toast('编辑器尚未准备就绪，请稍后再试', 'warning');
            return;
        }

        // 创建文件输入元素
        var input = document.createElement('input');
        input.type = 'file';
        input.multiple = true;
        input.accept = 'image/*';

        input.onchange = function (e) {
            var files = e.target.files;
            if (files.length === 0) return;

            console.log('选择了', files.length, '个文件');

            // 遍历所有选择的文件
            for (var i = 0; i < files.length; i++) {
                var file = files[i];

                // 验证文件类型
                if (!file.type.startsWith('image/')) {
                    console.warn('跳过非图片文件:', file.name);
                    continue;
                }

                // 验证文件大小 (20MB)
                if (file.size > 20 * 1024 * 1024) {
                    TodoModal.toast('文件 "' + file.name + '" 太大，请选择小于20MB的图片', 'warning');
                    continue;
                }

                console.log('处理文件:', file.name, '大小:', (file.size / 1024 / 1024).toFixed(2) + 'MB');

                // 上传图片到服务器
                uploadImageToServer(file);
            }

            // 清理input元素
            document.body.removeChild(input);
        };

        // 添加到DOM并触发点击
        document.body.appendChild(input);
        input.click();
    }

    // 上传图片到服务器
    function uploadImageToServer(file) {
        var formData = new FormData();
        formData.append('upfile', file);

        fetch(((window.TODO && window.TODO.join) ? window.TODO.join(window.TODO.ADMIN_BASE, 'assets/js/ueditor/php/controller.php') : '/admin/assets/js/ueditor/php/controller.php') + '?action=uploadimage', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                if (data.state === 'SUCCESS') {
                    console.log('图片上传成功:', data.url);
                    insertImageToEditor(data.url, file.name);
                } else {
                    console.error('图片上传失败:', data.state);
                    TodoModal.toast('图片上传失败: ' + data.state, 'error');
                }
            })
            .catch(error => {
                console.error('上传错误:', error);
                TodoModal.toast('图片上传出错，请重试', 'error');
            });
    }

    // 插入图片到编辑器
    function insertImageToEditor(imageUrl, imageName) {
        if (!window.ue || typeof window.ue.getContent !== 'function') {
            console.error('编辑器未准备就绪');
            return;
        }

        var imageHtml = '<img src="' + imageUrl + '" alt="' + (imageName || '') + '">';

        try {
            // 方法1: 使用execCommand插入HTML
            var success = window.ue.execCommand('inserthtml', imageHtml);
            if (success !== false) {
                console.log('图片插入成功 (execCommand):', imageUrl);
                return;
            }

            // 方法2: 使用setContent追加内容
            var currentContent = window.ue.getContent();
            window.ue.setContent(currentContent + imageHtml);
            console.log('图片插入成功 (setContent):', imageUrl);

        } catch (error) {
            console.error('插入图片失败:', error);

            // 方法3: 使用selection和range
            try {
                var range = window.ue.selection.getRange();
                var img = window.ue.document.createElement('img');
                img.src = imageUrl;
                img.alt = imageName || '';
                img.style.maxWidth = '100%';
                img.style.height = 'auto';

                range.insertNode(img);
                range.selectNode(img);
                window.ue.selection.getSelection().removeAllRanges();
                window.ue.selection.getSelection().addRange(range);

                console.log('图片插入成功 (range):', imageUrl);
            } catch (rangeError) {
                console.error('所有插入方法都失败了:', rangeError);
                TodoModal.toast('图片插入失败，请手动插入', 'error');
            }
        }
    }

    // 可见性选择处理
    function initVisibilityHandling() {
        var visibilitySelect = document.getElementById('post-visibility');
        var passwordFields = document.getElementById('password-visibility-fields');

        if (visibilitySelect && passwordFields) {
            visibilitySelect.addEventListener('change', function () {
                if (this.value === 'password') {
                    passwordFields.style.display = 'block';
                } else {
                    passwordFields.style.display = 'none';
                }
            });
        }
    }

    // 预览功能
    function previewPost() {
        if (window.ue && window.ue.hasContents()) {
            document.getElementById('post-content').value = window.ue.getContent();
        }

        var form = document.getElementById('post-form');
        var originalAction = form.action;
        var originalTarget = form.target;

        // 创建预览表单
        var previewForm = document.createElement('form');
        previewForm.method = 'post';
        previewForm.action = 'preview.php';
        previewForm.target = '_blank';

        // 复制表单数据
        var formData = new FormData(form);
        formData.forEach(function (value, key) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = value;
            previewForm.appendChild(input);
        });

        // 添加预览标识
        var previewInput = document.createElement('input');
        previewInput.type = 'hidden';
        previewInput.name = 'preview';
        previewInput.value = '1';
        previewForm.appendChild(previewInput);

        document.body.appendChild(previewForm);
        previewForm.submit();
        document.body.removeChild(previewForm);
    }

    // 动态加载模型固定字段
    function loadModelFixedFields() {
        const categoryInputs = document.querySelectorAll('.hierarchical-categories-selector input.category-input:checked');
        const selectedCategories = Array.from(categoryInputs).map(input => input.value);

        // 获取当前URL中的selected_categories参数
        const currentUrl = new URL(window.location.href);
        const currentSelectedCategories = currentUrl.searchParams.get('selected_categories');
        const newSelectedCategories = selectedCategories.join(',');

        // 如果选择的分类没有变化，不需要重新加载页面
        if (currentSelectedCategories === newSelectedCategories) {
            return;
        }

        // 如果没有选择分类，清空固定字段区域
        if (selectedCategories.length === 0) {
            const fixedFieldsContainer = document.querySelector('.fixed-fields-container');
            if (fixedFieldsContainer) {
                fixedFieldsContainer.innerHTML = '';
            }
            // 清除URL中的selected_categories参数
            if (currentSelectedCategories) {
                currentUrl.searchParams.delete('selected_categories');
                window.history.replaceState({}, '', currentUrl.toString());
            }
            return;
        }

        // 重新加载页面以获取新的模型字段
        // 这样可以直接在PHP中处理模型字段的加载
        const newUrl = new URL(window.location.origin + window.location.pathname);
        newUrl.searchParams.set('page', 'post-new');
        newUrl.searchParams.set('selected_categories', newSelectedCategories);
        const currentModel = currentUrl.searchParams.get('model');
        if (currentModel) {
            newUrl.searchParams.set('model', currentModel);
        }

        // 保存当前表单数据
        const formData = new FormData(document.querySelector('form'));
        const formDataObj = {};
        for (let [key, value] of formData.entries()) {
            formDataObj[key] = value;
        }

        // 将表单数据存储到sessionStorage
        sessionStorage.setItem('post_form_data', JSON.stringify(formDataObj));

        // 跳转到新URL
        window.location.href = newUrl.toString();
    }

    // 更新视图模版选项（可选传入已选栏目ID数组，以便响应 jQuery 组件的 model-fields-updated 事件）
    function updateViewTemplates(selectedCategoriesProvided) {
        const categoryInputs = document.querySelectorAll('.hierarchical-categories-selector input.category-input:checked');
        const selectedCategories = Array.isArray(selectedCategoriesProvided) ? selectedCategoriesProvided : Array.from(categoryInputs).map(input => input.value);
        const viewTemplateSelect = document.getElementById('view-template');

        if (!viewTemplateSelect) {
            return;
        }

        // 如果没有选择分类，清空模版选项
        if (selectedCategories.length === 0) {
            viewTemplateSelect.innerHTML = '<option value="" selected>默认模版</option>';
            return;
        }

        const url = todoAjax.ajaxurl;
        const formData = new FormData();
        formData.append('action', 'get_view_templates');
        formData.append('category_ids', selectedCategories.join(','));
        (function () {
            var nonceEl = document.querySelector('input[name="_todononce"]');
            var token = nonceEl ? nonceEl.value : '';
            if (!token && window.TodoCSRFManager && typeof TodoCSRFManager.getToken === 'function') {
                token = TodoCSRFManager.getToken('create_post_nonce') || TodoCSRFManager.getToken('edit_post_nonce') || '';
            }
            formData.append('_todononce', token);
        })();

        function refreshToken() {
            var fd = new FormData();
            fd.append('csrf_action', 'create_post_nonce');
            return fetch(todoAjax.ajaxurl + '?action=refresh_csrf_token', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: fd
            }).then(function (r) { return r.json(); }).then(function (j) {
                if (j && j.success && j.token) { return j.token; }
                throw new Error('CSRF刷新失败');
            });
        }

        function requestOnce() {
            // 将令牌同时置于Header，兼容读取HTTP_X_CSRF_TOKEN的实现
            var currentToken = formData.get('_todononce') || '';
            return fetch(url, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': currentToken },
                credentials: 'same-origin',
                body: formData
            }).then(function (response) {
                if (!response.ok) {
                    if (response.status === 403) {
                        return refreshToken().then(function (newToken) {
                            formData.set('_todononce', newToken);
                            return fetch(url, {
                                method: 'POST',
                                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': newToken },
                                credentials: 'same-origin',
                                body: formData
                            }).then(function (r2) { return r2.text(); }).then(function (txt) {
                                try { return JSON.parse(txt); } catch (e) { return { success: false, error: txt }; }
                            });
                        });
                    }
                    throw new Error('HTTP ' + response.status);
                }
                return response.text().then(function (txt) {
                    try { return JSON.parse(txt); } catch (e) { return { success: false, error: txt }; }
                });
            });
        }

        requestOnce().then(data => {
            if (data.success) {
                // 保存当前选中的值
                const currentValue = viewTemplateSelect.value;

                // 清空现有选项并添加“默认模版”（值优先取分类设置的内容模板，不带扩展名；否则回退为模型默认模板名，如 view_article）
                const defaultValue = data.default ? data.default : (data.model ? ('view_' + data.model) : '');
                viewTemplateSelect.innerHTML = '';
                const defaultOption = document.createElement('option');
                defaultOption.value = defaultValue;
                defaultOption.textContent = '使用默认模板';
                viewTemplateSelect.appendChild(defaultOption);

                // 添加新的模版选项（值使用完整文件名，含扩展名）
                let selectionTarget = null;
                if (currentValue && /\.html$/i.test(currentValue)) {
                    // 用户之前明确选择了具体文件，优先保留
                    selectionTarget = currentValue;
                } else if (data.category_default_file) {
                    // 否则优先选中分类默认内容模板（完整文件名）
                    selectionTarget = data.category_default_file;
                } else {
                    // 都没有则回退为模型默认模板（不带扩展名）
                    selectionTarget = defaultValue;
                }

                let matchedFile = false;
                const options = [];
                data.templates.forEach(template => {
                    const option = document.createElement('option');
                    option.value = template.file;
                    option.textContent = template.display;
                    if (selectionTarget && selectionTarget === template.file) {
                        option.selected = true;
                        matchedFile = true;
                    }
                    viewTemplateSelect.appendChild(option);
                    options.push(option);
                });

                // 若按完整文件名未命中，但存在分类默认文件，则尝试按不带扩展名的“基名”匹配（.htm/.html差异容错）
                if (!matchedFile && data.category_default_file) {
                    const targetBase = data.category_default_file.replace(/\.(html?|htm)$/i, '');
                    for (const opt of options) {
                        const optBase = opt.value.replace(/\.(html?|htm)$/i, '');
                        if (optBase === targetBase) {
                            opt.selected = true;
                            matchedFile = true;
                            selectionTarget = opt.value; // 采用列表中实际存在的扩展名
                            break;
                        }
                    }
                }

                // 如果目标是默认值或未找到匹配文件，则选中“使用默认模板”
                if (!selectionTarget || selectionTarget === defaultValue || !matchedFile) {
                    defaultOption.selected = true;
                    // 明确设置下拉框的值为默认项，保证 selected 状态在所有浏览器一致
                    viewTemplateSelect.value = defaultValue;
                } else {
                    // 明确设置下拉框当前值为匹配的文件，确保 selected 生效
                    viewTemplateSelect.value = selectionTarget;
                }
            }
        })
            .catch(error => {
                console.error('获取视图模版失败:', error);
            });
    }

    // 监听分类选择变化
    function initCategoryChangeHandler() {
        const categoryInputs = document.querySelectorAll('.hierarchical-categories-selector input.category-input');
        categoryInputs.forEach(input => {
            input.addEventListener('change', function () {
                // 延迟执行，确保同模型限制逻辑先执行
                setTimeout(loadModelFixedFields, 100);
                // 更新视图模版选项
                setTimeout(updateViewTemplates, 150);
            });
        });

        // 页面加载时不自动执行，避免无限循环
        // setTimeout(loadModelFixedFields, 200);
    }

    // 恢复表单数据
    function restoreFormData() {
        const savedData = sessionStorage.getItem('post_form_data');
        if (savedData) {
            try {
                const formDataObj = JSON.parse(savedData);
                const form = document.querySelector('form');

                // 恢复表单字段值
                Object.keys(formDataObj).forEach(key => {
                    const element = form.querySelector(`[name="${key}"]`);
                    if (element) {
                        if (element.type === 'checkbox' || element.type === 'radio') {
                            element.checked = element.value === formDataObj[key];
                        } else {
                            element.value = formDataObj[key];
                        }
                    }
                });

                // 恢复编辑器内容
                if (formDataObj.post_content && window.ue) {
                    window.ue.ready(function () {
                        window.ue.setContent(formDataObj.post_content);
                    });
                }

                // 清除保存的数据
                sessionStorage.removeItem('post_form_data');
            } catch (error) {
                console.error('恢复表单数据失败:', error);
                sessionStorage.removeItem('post_form_data');
            }
        }
    }

    const TODO_SUPER_COPY_STORAGE_KEY = 'todocms_supercopy_post_form_v1';
    const TODO_SUPER_COPY_PENDING_KEY = 'todocms_supercopy_post_form_pending_v1';

    function todoShowMessage(msg, type) {
        TodoCMS.Utils.showMessage(msg, type || 'info');
    }

    function todoCssEscape(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') return window.CSS.escape(value);
        return String(value).replace(/[^a-zA-Z0-9_\u00A0-\uFFFF-]/g, function (ch) {
            return '\\' + ch;
        });
    }

    function todoToArray(value) {
        if (Array.isArray(value)) return value;
        if (value === undefined || value === null) return [];
        return [value];
    }

    function todoJsSingleQuoteEscape(value) {
        return String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
    }

    function todoTryParseJsonArray(str) {
        if (typeof str !== 'string') return null;
        const raw = str.trim();
        if (!raw) return null;
        try {
            const parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : null;
        } catch (e) {
            return null;
        }
    }

    function todoApplyValueToElements(elements, value) {
        if (!elements || elements.length === 0) return;
        const first = elements[0];
        const tag = (first.tagName || '').toLowerCase();
        const type = (first.type || '').toLowerCase();
        // 将 value 安全转换为字符串数组，对象/数组类型使用 JSON.stringify 而非 String()
        const values = todoToArray(value).map(v => {
            if (v === null || v === undefined) return '';
            if (typeof v === 'object') return JSON.stringify(v);
            return String(v);
        });

        if (type === 'radio') {
            elements.forEach(el => {
                const checked = values.includes(String(el.value));
                if (el.checked !== checked) {
                    el.checked = checked;
                    el.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
            return;
        }

        if (type === 'checkbox') {
            elements.forEach(el => {
                const v = String(el.value);
                const checked = values.includes(v) || (values.length === 1 && values[0] === 'on' && v === 'on');
                if (el.checked !== checked) {
                    el.checked = checked;
                    el.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
            return;
        }

        if (tag === 'select') {
            if (first.multiple) {
                const selected = new Set(values);
                Array.from(first.options).forEach(opt => {
                    opt.selected = selected.has(String(opt.value));
                });
            } else {
                first.value = values.length > 0 ? values[0] : '';
            }
            first.dispatchEvent(new Event('change', { bubbles: true }));
            return;
        }

        if (type === 'hidden') {
            const newVal = values.length > 0
                ? (Array.isArray(value)
                    ? JSON.stringify(value)
                    : (value !== null && typeof value === 'object'
                        ? JSON.stringify(value)
                        : values[0]))
                : '';
            if (first.value !== newVal) {
                first.value = newVal;
                // 特殊处理：联动 UI 组件（如单多规格切换、产品类型切换等）
                const wrapper = first.closest('.todo-spec-type-wrap, .todo-product-type-wrap, .todo-discount-type-wrap, .switch-field');
                if (wrapper) {
                    if (wrapper.classList.contains('switch-field')) {
                        const toggle = wrapper.querySelector('input[type="checkbox"].switch-checkbox');
                        if (toggle) {
                            toggle.checked = (String(newVal) === '1' || String(newVal) === 'true');
                            // 不需要 trigger change，以免形成死循环
                        }
                    } else {
                        const radios = wrapper.querySelectorAll('input[type="radio"]');
                        if (radios.length > 0) {
                            radios.forEach(r => {
                                r.checked = (String(r.value) === String(newVal));
                                // 此处必须触发 change 以便组件内部脚本感知
                                r.dispatchEvent(new Event('change', { bubbles: true }));
                            });
                        }
                    }
                }
                first.dispatchEvent(new Event('change', { bubbles: true }));
            }
            return;
        }

        // 默认输入项
        const newVal = values.length > 0 ? values[0] : '';
        if (first.value !== newVal) {
            first.value = newVal;

            // 特殊处理：如果该 textarea 绑定了 UEditor，根据 name 或包裹 div 去匹配并回填
            if (tag === 'textarea' && window.UE) {
                const instants = window.UE.instants || window.UE.instances || {};
                let editor = null;

                // 尝试 1: 通过 textarea name 去比对实例内部属性 (UEditor)
                for (let k in instants) {
                    if (Object.prototype.hasOwnProperty.call(instants, k)) {
                        const inst = instants[k];
                        if (inst && ((inst.textarea && inst.textarea.name === first.name) || 
                            (inst.options && inst.options.textarea === first.name))) {
                            editor = inst;
                            break;
                        }
                    }
                }

                // 尝试 2: 向上寻找外层容器
                if (!editor) {
                    const group = first.closest('.form-group, [data-field-type="editor"], .fixed-field');
                    if (group) {
                        const eduiNode = group.querySelector('.edui-default[id]');
                        if (eduiNode && eduiNode.id && instants[eduiNode.id]) {
                            editor = instants[eduiNode.id];
                        }
                    }
                }

                if (editor) {
                    try {
                        if (editor.isReady) {
                            editor.setContent(newVal);
                        } else {
                            editor.ready(function() {
                                editor.setContent(newVal);
                            });
                        }
                    } catch (err) {}
                }
            }

            first.dispatchEvent(new Event('input', { bubbles: true }));
            first.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    function todoRebuildCustomParams(form, payloadData) {
        if (!form || !payloadData) return;
        const fieldWrap = form.querySelector('[data-field-key="custom_params"]');
        if (!fieldWrap) return;

        const titleEl = fieldWrap.querySelector('[id^="customParamTitle-"]');
        const containerEl = fieldWrap.querySelector('[id^="customParam-"]');
        const delInput = fieldWrap.querySelector('input[type="hidden"][name="del_param_id"]');
        const addBtn = fieldWrap.querySelector('[id^="addCustomParamBtn-"][data-uid]');
        if (!containerEl || !delInput) return;

        const assoc = {};
        const assocKeys = Object.keys(payloadData).filter(k => /^param_name\[[^\]]+\]$/.test(k) || /^param_value\[[^\]]+\]$/.test(k) || /^sort_order\[[^\]]+\]$/.test(k) || /^param_id\[[^\]]+\]$/.test(k));

        if (assocKeys.length) {
            assocKeys.forEach(k => {
                const m = k.match(/^(param_name|param_value|sort_order|param_id)\[([^\]]+)\]$/);
                if (!m) return;
                const field = m[1];
                const pid = String(m[2] || '').trim();
                if (!pid) return;
                if (!assoc[pid]) assoc[pid] = { id: pid, sort_order: 100, param_name: '', param_value: '' };
                if (field === 'sort_order') assoc[pid].sort_order = parseInt(payloadData[k], 10) || 100;
                if (field === 'param_name') assoc[pid].param_name = String(payloadData[k] || '');
                if (field === 'param_value') assoc[pid].param_value = String(payloadData[k] || '');
                if (field === 'param_id') assoc[pid].id = String(payloadData[k] || pid);
            });
        } else {
            const names = todoToArray(payloadData['param_name[]']);
            const values = todoToArray(payloadData['param_value[]']);
            const sorts = todoToArray(payloadData['param_sort[]']);
            const count = Math.max(names.length, values.length);
            for (let i = 0; i < count; i++) {
                const name = names[i] != null ? String(names[i]) : '';
                const val = values[i] != null ? String(values[i]) : '';
                const sort = sorts[i] != null ? (parseInt(String(sorts[i]), 10) || 100) : 100;
                if (!name && !val) continue;
                const pid = String(i + 1);
                assoc[pid] = { id: pid, sort_order: sort, param_name: name, param_value: val };
            }
        }

        const rows = Object.keys(assoc).map(pid => assoc[pid]).filter(r => (r.param_name || r.param_value));
        rows.sort((a, b) => {
            const sa = parseInt(a.sort_order, 10) || 100;
            const sb = parseInt(b.sort_order, 10) || 100;
            if (sa !== sb) return sa - sb;
            return String(a.id).localeCompare(String(b.id));
        });

        while (containerEl.firstChild) containerEl.removeChild(containerEl.firstChild);
        containerEl.appendChild(delInput);
        delInput.value = '';

        const titleId = titleEl ? titleEl.id : '';
        const containerId = containerEl.id || '';
        const delId = delInput.id || '';

        let maxIdNum = 0;
        rows.forEach(r => {
            const pidRaw = String(r.id || '').trim();
            const pidNum = parseInt(pidRaw, 10);
            const pid = !isNaN(pidNum) ? String(pidNum) : String(++maxIdNum);
            if (!isNaN(pidNum) && pidNum > maxIdNum) maxIdNum = pidNum;

            const dl = document.createElement('dl');
            dl.className = 'flex gap-3';
            dl.style.margin = '0 0 5px';

            const sortVal = String(r.sort_order != null ? r.sort_order : 100);
            const nameVal = String(r.param_name != null ? r.param_name : '');
            const valueVal = String(r.param_value != null ? r.param_value : '');

            const delIdEsc = todoJsSingleQuoteEscape(delId);
            const titleIdEsc = todoJsSingleQuoteEscape(titleId);
            const containerIdEsc = todoJsSingleQuoteEscape(containerId);
            const pidEsc = todoJsSingleQuoteEscape(pid);

            const onRemove =
                'this.closest(\\\'dl\\\').remove();' +
                'var delInput=document.getElementById(\\\'' + delIdEsc + '\\\');' +
                'var currentDel=delInput.value;' +
                'delInput.value=currentDel ? currentDel + \\\',\\\' + \\\'' + pidEsc + '\\\' : \\\'' + pidEsc + '\\\';' +
                'var titleEl=document.getElementById(\\\'' + titleIdEsc + '\\\');' +
                'var containerEl=document.getElementById(\\\'' + containerIdEsc + '\\\');' +
                'if(titleEl && containerEl){titleEl.style.display = containerEl.children.length < 2 ? \\\'none\\\' : \\\'\\\';}';

            dl.innerHTML =
                '<input type="hidden" name="param_id[' + pid + ']" value="' + pid + '">' +
                '<dt><input type="text" name="sort_order[' + pid + ']" value="' + sortVal.replace(/"/g, '&quot;') + '"></dt>' +
                '<dt><input type="text" name="param_name[' + pid + ']" value="' + nameVal.replace(/"/g, '&quot;') + '"></dt>' +
                '<dd><input type="text" name="param_value[' + pid + ']" value="' + valueVal.replace(/"/g, '&quot;') + '">' +
                '<a class="btn btn-normal" href="javascript:void(0);" onclick="' + onRemove + '" style="margin-left: 10px;">×</a>' +
                '</dd>';

            containerEl.appendChild(dl);
        });

        if (titleEl) {
            titleEl.style.display = rows.length ? 'block' : 'none';
        }
        if (addBtn) {
            addBtn.setAttribute('data-param_id', String(maxIdNum + 1 || 1));
        }
    }

    function todoReinitSpecBuilderFromHidden() {
        const builder = document.getElementById('todo-specs-builder');
        if (!builder) return;

        const sc = document.getElementById('spec_config');
        const sd = document.getElementById('spec_details');
        const cfg = sc && typeof sc.value === 'string' ? todoTryParseJsonArray(sc.value) : null;
        const det = sd && typeof sd.value === 'string' ? todoTryParseJsonArray(sd.value) : null;
        if (!cfg && !det) return;

        const parent = builder.parentNode;
        if (!parent) return;
        const next = builder.nextSibling;
        const clone = builder.cloneNode(true);
        parent.removeChild(builder);
        parent.insertBefore(clone, next);

        const s = document.createElement('script');
        s.type = 'text/javascript';
        s.src = (window.TODO && window.TODO.join)
            ? window.TODO.join(window.TODO.ADMIN_BASE, 'assets/js/spec-builder.js') + '?r=' + Date.now()
            : '/admin/assets/js/spec-builder.js?r=' + Date.now();
        document.head.appendChild(s);
    }

    /**
     * 将 download_builder 隐藏域中的 JSON 数据反向渲染到可视 UI（供 SuperCopy 回填使用）
     */
    function todoRebuildDownloadBuilderFromHidden(form) {
        if (!form) return;
        const wrappers = Array.from(form.querySelectorAll('[data-field-type="download_builder"]'));
        if (!wrappers.length) return;

        wrappers.forEach(function(fieldWrap) {
            const fieldKey = fieldWrap.getAttribute('data-field-key') || '';
            // 找到隐藏域（name 属性与 data-field-key 相同）
            const hidden = fieldWrap.querySelector('input[type="hidden"][name="' + todoCssEscape(fieldKey) + '"]');
            if (!hidden) return;

            const downloads = todoTryParseJsonArray(hidden.value);
            if (!Array.isArray(downloads) || downloads.length === 0) return;

            const listContainer = fieldWrap.querySelector('.download-list');
            if (!listContainer) return;

            const builderWrapper = fieldWrap.querySelector('.download-builder-wrapper');
            const allowFileSelect = builderWrapper ? builderWrapper.getAttribute('data-allow-file-select') === '1' : false;
            const placeholder = builderWrapper ? (builderWrapper.getAttribute('data-placeholder') || '配置下载地址...') : '配置下载地址...';
            const fieldId = hidden.id || '';

            // 清空现有下载项（保留非 .download-item 子节点，如脚本标签）
            Array.from(listContainer.querySelectorAll('.download-item')).forEach(function(el) {
                el.remove();
            });

            downloads.forEach(function(item, index) {
                if (!item || typeof item !== 'object') return;
                const url           = String(item.url || '');
                const alias         = String(item.alias || '');
                const extractCode   = String(item.extract_code || '');
                const password      = String(item.password || '');
                const note          = String(item.note || '');

                const div = document.createElement('div');
                div.className = 'download-item';
                div.setAttribute('data-index', String(index));

                const selectBtnHtml = allowFileSelect
                    ? '<button type="button" class="btn button-link select-file-btn" onclick="selectMediaFile(this)">✚ 选择文件</button>'
                    : '';

                const esc = function(s) { return s.replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); };

                div.innerHTML = [
                    '<div class="download-item-header flex gap-5 items-center">',
                    '  <span class="download-item-title" style="font-size: 20px; color: #ccc; font-style: italic;">#' + (index + 1) + '</span>',
                    '  <button type="button" class="btn button-link remove-download-btn" onclick="removeDownloadItem(this)">✕</button>',
                    '</div>',
                    '<div class="download-item-content flex gap-x-10 items-top flex-wrap" style="margin: 10px 0 0 40px;">',
                    '  <div class="form-group">',
                    '    <h4>下载地址</h4>',
                    '    <div class="input-group flex flex-wrap gap-2">',
                    '      <input type="text" class="form-control download-url" value="' + esc(url) + '" placeholder="' + esc(placeholder) + '">',
                    '      ' + selectBtnHtml,
                    '    </div>',
                    '  </div>',
                    '  <div class="form-group">',
                    '    <h4>别名</h4>',
                    '    <input type="text" class="form-control download-alias" value="' + esc(alias) + '" placeholder="下载链接显示名称">',
                    '  </div>',
                    '  <div class="form-group">',
                    '    <h4>提取码:</h4>',
                    '    <input type="text" class="form-control download-extract-code" value="' + esc(extractCode) + '">',
                    '  </div>',
                    '  <div class="form-group">',
                    '    <h4>解压密码</h4>',
                    '    <input type="text" class="form-control download-password" value="' + esc(password) + '">',
                    '  </div>',
                    '  <div class="form-group">',
                    '    <h4>备注</h4>',
                    '    <textarea class="form-control download-note" rows="2">' + esc(note) + '</textarea>',
                    '  </div>',
                    '</div>'
                ].join('\n');

                listContainer.appendChild(div);
            });
        });
    }

    function todoSyncFeaturedImagesComponents(form) {
        if (!form) return;
        if (!window.jQuery) return;
        if (typeof window.FeaturedImagesComponent === 'undefined') return;

        const containers = Array.from(form.querySelectorAll('.featured-images-component'));
        if (containers.length === 0) return;

        containers.forEach(container => {
            try {
                const $container = window.jQuery(container);
                const fieldName = container.getAttribute('data-field-name') || ($container.data('field-name') || 'featured_images');
                if (!fieldName) return;

                const hidden = form.querySelector('input[type="hidden"][name="' + todoCssEscape(fieldName) + '"]');
                if (!hidden) return;

                let images = [];
                const raw = String(hidden.value || '').trim();
                if (raw) {
                    try {
                        const parsed = JSON.parse(raw);
                        if (Array.isArray(parsed)) images = parsed;
                    } catch (e) {
                    }
                }

                $container.data('current-images', images);
                $container.attr('data-current-images', JSON.stringify(images));

                let instance = $container.data('featuredImagesInstance');
                if (!instance) {
                    const cfg = {
                        containerEl: $container,
                        fieldId: container.getAttribute('id') || ('featured_' + Date.now()),
                        fieldName: fieldName,
                        multiple: $container.data('multiple') !== false,
                        maxImages: parseInt($container.data('max-images')) || 6,
                        enableDebug: $container.data('enable-debug') === true,
                        fileType: ($container.data('file-type') || 'any')
                    };
                    instance = new window.FeaturedImagesComponent(cfg);
                }

                const $preview = $container.find('.featured-images-preview, .universal-images-preview');
                if ($preview.length) {
                    $preview.empty();
                }

                if (instance && typeof instance.loadCurrentImages === 'function') {
                    instance.loadCurrentImages();
                }
            } catch (e) {
            }
        });
    }

    function todoParseRegionParts(val) {
        const raw = (val === null || typeof val === 'undefined') ? '' : String(val);
        const parts = raw.split('|');
        return {
            province: parts[0] ? String(parts[0]) : '',
            city: parts[1] ? String(parts[1]) : '',
            district: parts[2] ? String(parts[2]) : '',
            town: parts[3] ? String(parts[3]) : '',
            detail: parts[4] ? String(parts[4]) : ''
        };
    }

    function todoRebuildRegionSelectors(form, data) {
        if (!form) return;
        if (!data || typeof data !== 'object') return;
        if (!window.jQuery) return;
        const $ = window.jQuery;

        const selectors = Array.from(form.querySelectorAll('.region-selector'));
        if (!selectors.length) return;

        selectors.forEach(function(root) {
            const hidden = root.querySelector('input[type="hidden"]');
            if (!hidden) return;
            const base = hidden.getAttribute('name') || '';
            if (!base) return;

            const combined = Object.prototype.hasOwnProperty.call(data, base) ? data[base] : '';
            const parsed = todoParseRegionParts(combined);

            const province = Object.prototype.hasOwnProperty.call(data, base + '_province') ? data[base + '_province'] : parsed.province;
            const city = Object.prototype.hasOwnProperty.call(data, base + '_city') ? data[base + '_city'] : parsed.city;
            const district = Object.prototype.hasOwnProperty.call(data, base + '_district') ? data[base + '_district'] : parsed.district;
            const town = Object.prototype.hasOwnProperty.call(data, base + '_town') ? data[base + '_town'] : parsed.town;
            const detail = Object.prototype.hasOwnProperty.call(data, base + '_detail') ? data[base + '_detail'] : parsed.detail;

            $(root).trigger('todoRegion:fill', {
                province: province ? String(province) : '',
                city: city ? String(city) : '',
                district: district ? String(district) : '',
                town: town ? String(town) : '',
                detail: detail ? String(detail) : ''
            });
        });
    }

    function todoApplySuperCopyPayload(payload, options = {}) {
        if (!payload || !payload.data || typeof payload.data !== 'object') return;
        const form = document.getElementById('post-form') || document.querySelector('form');
        if (!form) return;

        const retryCount = options.retryCount || 0;
        const appliedKeys = options.appliedKeys || new Set();
        const missingKeys = [];

        Object.keys(payload.data).forEach(key => {
            // 排除敏感字段，尤其是 action，防止 create_post 被覆盖为 update_post 导致无法保存
            if (['_todononce', 'post_id', 'save', 'action'].includes(key)) return;
            if (appliedKeys.has(key)) return;

            let val = payload.data[key];
            // 针对标题特殊处理：加入“副本_”前缀及时间戳标识，避免重复
            if (key === 'post_title' && typeof val === 'string' && val && !val.startsWith('副本_')) {
                const now = new Date();
                const timeTag = now.getFullYear().toString().slice(-2) + 
                                (now.getMonth() + 1).toString().padStart(2, '0') + 
                                now.getDate().toString().padStart(2, '0') + 
                                now.getHours().toString().padStart(2, '0') + 
                                now.getMinutes().toString().padStart(2, '0');
                val = '副本_' + val + '_' + timeTag;
            }

            const selector = '[name="' + todoCssEscape(key) + '"]';
            const elements = Array.from(form.querySelectorAll(selector));
            if (elements.length === 0) {
                missingKeys.push(key);
                return;
            }
            
            todoApplyValueToElements(elements, val);
            appliedKeys.add(key);
        });

        // 处理 UEditor (仅在第一次执行或 UEditor 准备就绪时)
        const content = payload.data.post_content;
        if (typeof content === 'string' && !appliedKeys.has('__ue_content')) {
            if (window.ue && window.ue.isReady) {
                window.ue.setContent(content);
                appliedKeys.add('__ue_content');
            } else if (window.ue && typeof window.ue.ready === 'function') {
                window.ue.ready(function() {
                    window.ue.setContent(content);
                });
                appliedKeys.add('__ue_content');
            }
        }

        // 重组特殊组件
        todoRebuildCustomParams(form, payload.data);
        todoRebuildRegionSelectors(form, payload.data);
        todoReinitSpecBuilderFromHidden();
        todoRebuildDownloadBuilderFromHidden(form);
        todoSyncFeaturedImagesComponents(form);
        if (typeof window.todoRefreshLangChips === 'function') {
            window.todoRefreshLangChips();
        }

        // 如果有缺失字段且重试次数未达上限，则开启轮询重试（处理动态渲染的 DOM 元素）
        if (missingKeys.length > 0 && retryCount < 10) {
            setTimeout(() => {
                todoApplySuperCopyPayload(payload, {
                    retryCount: retryCount + 1,
                    appliedKeys: appliedKeys
                });
            }, 200);
        } else {
            // 最终依然缺失的字段 (处理语言版本或即便不在 DOM 中也需提交的隐藏数据)
            Object.keys(payload.data).forEach(key => {
                if (appliedKeys.has(key)) return;
                if (['_todononce', 'post_id', 'save', 'action'].includes(key)) return;

                const baseName = key.replace(/\[\]$/, '');
                // 进行隐式自动回填
                if (key.includes('model_field_') || baseName === 'post_category') {
                    const val = payload.data[key];
                    
                    if (Array.isArray(val) || key.endsWith('[]')) {
                        // 如果是数组，创建多个同名隐藏域，并确保 name 带有 []
                        const arrayName = baseName + '[]';
                        // 先清理可能已存在的同名元素（虽然按 logic 应该是没有）
                        form.querySelectorAll('input[name="' + todoCssEscape(arrayName) + '"]').forEach(el => el.remove());
                        
                        const values = Array.isArray(val) ? val : [val];
                        values.forEach(v => {
                            const hidden = document.createElement('input');
                            hidden.type = 'hidden';
                            hidden.name = arrayName;
                            hidden.value = (typeof v === 'object') ? JSON.stringify(v) : v;
                            form.appendChild(hidden);
                        });
                    } else {
                        let hidden = form.querySelector('input[name="' + todoCssEscape(key) + '"]');
                        if (!hidden) {
                            hidden = document.createElement('input');
                            hidden.type = 'hidden';
                            hidden.name = key;
                            form.appendChild(hidden);
                        }
                        hidden.value = (typeof val === 'object') ? JSON.stringify(val) : val;
                    }
                    appliedKeys.add(key);
                }
            });
        }
    }

    function todoMaybeRedirectForCategories(payload) {
        const cats = payload && payload.data ? payload.data['post_category[]'] : null;
        const catList = todoToArray(cats).map(v => String(v)).filter(Boolean);
        const target = catList.join(',');
        if (!target) return false;

        const currentUrl = new URL(window.location.href);
        const currentSelected = currentUrl.searchParams.get('selected_categories') || '';
        if (currentSelected === target) return false;

        try {
            sessionStorage.setItem(TODO_SUPER_COPY_PENDING_KEY, JSON.stringify(payload));
        } catch (e) {
            todoShowMessage('暂存失败：浏览器存储空间不足或被限制', 'error');
            return false;
        }

        const newUrl = new URL(window.location.origin + window.location.pathname);
        newUrl.searchParams.set('page', 'post-new');
        newUrl.searchParams.set('selected_categories', target);
        const currentModel = currentUrl.searchParams.get('model');
        if (currentModel) newUrl.searchParams.set('model', currentModel);
        window.location.href = newUrl.toString();
        return true;
    }

    function todoRestorePendingSuperCopy() {
        const pending = sessionStorage.getItem(TODO_SUPER_COPY_PENDING_KEY);
        if (!pending) return;
        try {
            const payload = JSON.parse(pending);
            todoApplySuperCopyPayload(payload);
            sessionStorage.removeItem(TODO_SUPER_COPY_PENDING_KEY);
            todoShowMessage('已填充表单（未入库，发布后才会保存）', 'success');
        } catch (e) {
            sessionStorage.removeItem(TODO_SUPER_COPY_PENDING_KEY);
        }
    }

    function initSuperPasteButton() {
        const btn = document.getElementById('todo-super-paste');
        if (!btn) return;

        let stored = null;
        try {
            stored = sessionStorage.getItem(TODO_SUPER_COPY_STORAGE_KEY);
        } catch (e) {
        }
        if (!stored) {
            btn.disabled = true;
            return;
        }

        btn.addEventListener('click', function () {
            let payload = null;
            try {
                payload = JSON.parse(sessionStorage.getItem(TODO_SUPER_COPY_STORAGE_KEY) || 'null');
            } catch (e) {
            }
            if (!payload) {
                todoShowMessage('没有找到可用的暂存数据', 'error');
                return;
            }

            if (todoMaybeRedirectForCategories(payload)) return;
            todoApplySuperCopyPayload(payload);
            todoShowMessage('已填充表单（未入库，发布后才会保存）', 'success');
        });
    }

    function initEditorQuickActions() {
        const chkSaveRemote = document.getElementById('chk-save-remote-images');
        const chkClearLinks = document.getElementById('chk-clear-external-links');
        const form = document.getElementById('post-form');

        if (!form || !chkSaveRemote || !chkClearLinks) return;

        let isProcessing = false;
        let submitType = null; // 记录点击了哪个提交按钮

        // 清理多余属性功能绑定
        const btnCleanFormat = document.getElementById('btn-clean-format');
        if (btnCleanFormat) {
            btnCleanFormat.addEventListener('click', function() {
                let content = '';
                if (window.ue && typeof window.ue.getContent === 'function') {
                    content = window.ue.getContent();
                } else {
                    const editorEl = document.getElementById('post-content');
                    content = editorEl ? editorEl.value : '';
                }
                if (!content) return;

                const parser = new DOMParser();
                const doc = parser.parseFromString(content, 'text/html');
                const elements = doc.body.getElementsByTagName('*');
                
                const allowedAttrs = {
                    'DIV': ['class'],
                    'IMG': ['src', 'alt', 'title', 'width', 'height'],
                    'A': ['href', 'title', 'target', 'rel'],
                    'VIDEO': ['src', 'controls', 'width', 'height', 'poster', 'autoplay', 'loop', 'muted'],
                    'AUDIO': ['src', 'controls', 'autoplay', 'loop'],
                    'SOURCE': ['src', 'type'],
                    'IFRAME': ['src', 'width', 'height', 'frameborder', 'allowfullscreen'],
                    'TD': ['colspan', 'rowspan'],
                    'TH': ['colspan', 'rowspan'],
                    'TABLE': ['border']
                };

                for (let i = 0; i < elements.length; i++) {
                    const el = elements[i];
                    const tagName = el.tagName;
                    const allowed = allowedAttrs[tagName] || [];
                    
                    // 倒序遍历并移除不在白名单中的属性
                    for (let j = el.attributes.length - 1; j >= 0; j--) {
                        const attrName = el.attributes[j].name.toLowerCase();
                        if (attrName === 'class' && tagName === 'DIV') {
                            const classList = Array.from(el.classList);
                            const preservedClasses = classList.filter(c => c === 'image-wrapper' || c === 'image-caption');
                            if (preservedClasses.length > 0) {
                                el.className = preservedClasses.join(' ');
                                continue;
                            }
                        }
                        if (!allowed.includes(attrName)) {
                            el.removeAttribute(attrName);
                        }
                    }
                }

                const finalContent = doc.body.innerHTML;
                if (window.ue && typeof window.ue.setContent === 'function') {
                    window.ue.setContent(finalContent);
                } else {
                    const editorEl = document.getElementById('post-content');
                    if (editorEl) editorEl.value = finalContent;
                }

                TodoModal.toast('清理格式成功，已去除多余样式及属性', 'success');
            });
        }

        // 监听所有提交按钮的点击，记录点击的是哪个（为了保留 name 和 value）
        form.querySelectorAll('input[type="submit"], button[type="submit"]').forEach(btn => {
            btn.addEventListener('click', function() {
                submitType = { name: btn.name, value: btn.value };
            });
        });

        form.addEventListener('submit', function(e) {
            // 如果已经在处理中，允许第二次提交直接通过
            if (isProcessing) return;

            // 设置提交中标识，防止由于编辑器同步内容导致 hasChanges 变回 true 从而触发浏览器弹窗
            window.__todo_is_submitting = true;
            hasChanges = false;
            
            // 清除任何待处理的自动保存定时器，防止提交过程中意外触发自动保存草稿
            if (window.autoSaveTimer) {
                clearTimeout(window.autoSaveTimer);
                window.autoSaveTimer = null;
            }
            
            // 如果两个都没勾选，直接通过
            if (!chkSaveRemote.checked && !chkClearLinks.checked) return;

            let content = '';
            if (window.ue && typeof window.ue.getContent === 'function') {
                content = window.ue.getContent();
            } else {
                const editorEl = document.getElementById('post-content');
                content = editorEl ? editorEl.value : '';
            }

            // 内容为空则不处理
            if (!content || content.trim() === '') return;

            // 开始拦截并处理
            e.preventDefault();
            isProcessing = true;

            if (window.TodoModal && typeof TodoModal.showLoading === 'function') {
                TodoModal.showLoading('正在处理文章内容，请稍候...');
            }

            // 使用 DOMParser 解析 HTML，确保处理健壮，避免正则匹配标签的局限性
            const parser = new DOMParser();
            const doc = parser.parseFromString(content, 'text/html');
            const homeUrl = window.location.origin;

            // 1. 清除外链 (同步处理)
            if (chkClearLinks.checked) {
                const links = Array.from(doc.querySelectorAll('a'));
                links.forEach(a => {
                    const href = a.getAttribute('href');
                    if (href && href.startsWith('http')) {
                        if (!href.startsWith(homeUrl)) {
                            // 是外链，用文本节点替换 a 标签
                            const fragment = document.createDocumentFragment();
                            while (a.firstChild) {
                                fragment.appendChild(a.firstChild);
                            }
                            a.parentNode.replaceChild(fragment, a);
                        }
                    }
                });
            }

            // 2. 保存远程媒体 (异步请求)
            if (chkSaveRemote.checked) {
                const mediaEls = Array.from(doc.querySelectorAll('img, video, audio, source, iframe, embed, object'));
                const remoteUrls = [];
                const elUrlMap = new Map();

                mediaEls.forEach(el => {
                    const url = el.getAttribute('src');
                    if (url && url.startsWith('http')) {
                        if (!url.startsWith(homeUrl)) {
                            if (!remoteUrls.includes(url)) {
                                remoteUrls.push(url);
                            }
                            // 记录这个元素依赖哪个 url，方便等会替换
                            elUrlMap.set(el, url);
                        }
                    }
                });

                if (remoteUrls.length > 0) {
                    const fd = new FormData();
                    fd.append('action', 'save_remote_images');
                    fd.append('urls', JSON.stringify(remoteUrls));
                    fd.append('post_id', document.querySelector('input[name="post_id"]')?.value || 0);
                    
                    const nonceInput = document.querySelector('input[name="_todononce"]');
                    if (nonceInput) fd.append('_todononce', nonceInput.value);

                    fetch((typeof ajaxUrl !== 'undefined' ? ajaxUrl : 'ajax/post-ajax.php'), {
                        method: 'POST',
                        body: fd,
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    })
                    .then(r => r.json())
                    .then(json => {
                        if (json.success && json.data) {
                            // json.data 格式为 { "原始URL": "新URL" }
                            elUrlMap.forEach((originalUrl, el) => {
                                if (json.data[originalUrl]) {
                                    el.setAttribute('src', json.data[originalUrl]);
                                }
                            });
                            // 特殊处理 href 指向原图的 a 标签
                            const links = Array.from(doc.querySelectorAll('a'));
                            links.forEach(a => {
                                const href = a.getAttribute('href');
                                if (href && json.data[href]) {
                                    a.setAttribute('href', json.data[href]);
                                }
                            });
                        }
                        finish(doc.body.innerHTML);
                    })
                    .catch(err => {
                        console.error('Remote images save failed:', err);
                        finish(doc.body.innerHTML);
                    });
                } else {
                    finish(doc.body.innerHTML);
                }
            } else {
                finish(doc.body.innerHTML);
            }

            function finish(finalContent) {
                // 更新编辑器内容（可能会触发 contentChange，导致 hasChanges 被设为 true）
                if (window.ue && typeof window.ue.setContent === 'function') {
                    window.ue.setContent(finalContent);
                }
                
                // 关键：手动同步内容到 textarea，防止 form.submit() 跳过 UEditor 的自动同步
                const editorEl = document.getElementById('post-content');
                if (editorEl) {
                    editorEl.value = finalContent;
                }
                
                // 在 setContent 之后强制重置变更标志和自动保存定时器，
                // 防止 setContent 触发的 contentChange 再触发自动保存草稿
                hasChanges = false;
                if (window.autoSaveTimer) {
                    clearTimeout(window.autoSaveTimer);
                    window.autoSaveTimer = null;
                }
                
                if (window.TodoModal && typeof TodoModal.hideLoading === 'function') {
                    TodoModal.hideLoading();
                }
                
                // 模拟原本被点击的按钮提交
                if (submitType && submitType.name) {
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = submitType.name;
                    hidden.value = submitType.value;
                    form.appendChild(hidden);
                }
                
                // 正式提交表单
                form.submit();
            }
        });
    }

    // 页面加载完成后初始化所有功能
    document.addEventListener('DOMContentLoaded', function () {
        initVisibilityHandling();
        initSEOFields();
        initCategoryChangeHandler();
        initFeaturedImage();

        // 监听 jQuery 组件触发的模型字段更新事件，联动刷新视图模版下拉框
        if (window.jQuery) {
            jQuery(document).on('model-fields-updated', function (e, categoryIds) {
                try {
                    updateViewTemplates(categoryIds);
                } catch (err) {
                    console.error('更新视图模版失败:', err);
                }
            });
        }

        // 恢复表单数据（如果有的话）
        restoreFormData();

        todoRestorePendingSuperCopy();
        initSuperPasteButton();
        
        // 初始化编辑器快捷操作
        initEditorQuickActions();
    });

</script>

<?php
// 引入媒体模态框组件
require_once TODO_ADMIN_PATH . '/components/media-modal.php';
?>
