<?php
// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
/**
 * 媒体编辑AJAX处理
 * 
 * @package TodoCMS
 * @subpackage Admin
 */

// 定义必要常量
if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}
if (!defined('IS_ADMIN')) {
    define('IS_ADMIN', true);
}

// 检查是否为AJAX请求
if (
    !isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'
) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(array('error' => 'Direct access not permitted'));
    exit;
}

// 启动会话
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 加载核心文件
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
}
require_once ABSPATH . 'config.php';
require_once ABSPATH . 'core/bootstrap.php';
require_once ABSPATH . 'core/functions/formatting.php';
require_once ABSPATH . 'core/functions/media-functions-extended.php';
require_once ABSPATH . 'core/functions/url-functions.php';

// 定义数据库表前缀
if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', TABLE_PREFIX);
}

// 检查用户权限
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
    http_response_code(403);
    echo json_encode(array('error' => '权限不足'));
    exit;
}

// 设置响应头
header('Content-Type: application/json');

// 获取操作类型
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

switch ($action) {
    case 'get_media':
        handle_get_media();
        break;

    case 'update_media':
        handle_update_media();
        break;

    default:
        echo json_encode(array('error' => '无效的操作'));
        break;
}

/**
 * 获取媒体信息
 */
function handle_get_media()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(array('error' => '方法不允许'));
        return;
    }

    $media_id = isset($_GET['media_id']) ? intval($_GET['media_id']) : 0;

    if (!$media_id) {
        echo json_encode(array('error' => '无效的媒体ID'));
        return;
    }

    try {
        $media = new CMS_Media($media_id);

        if (!$media->exists()) {
            echo json_encode(array('error' => '媒体文件不存在'));
            return;
        }

        $metadata = $media->get_metadata();
        $file_size = isset($metadata['_filesize']) ? todo_format_file_size($metadata['_filesize']) : 'N/A';

        // 获取图片尺寸信息
        $dimensions = '';
        if (isset($metadata['width']) && isset($metadata['height'])) {
            $dimensions = $metadata['width'] . ' × ' . $metadata['height'] . ' 像素';
        }

        echo json_encode(array(
            'success' => true,
            'data' => array(
                'id' => $media->get_id(),
                'title' => $media->get_title(),
                'filename' => $media->get_filename(),
                'url' => $media->get_url(),
                'mime_type' => $media->get_mime_type(),
                'file_size' => $file_size,
                'dimensions' => $dimensions,
                'upload_date' => $media->get_date(),
                'alt_text' => $media->get_alt_text(),
                'description' => $media->get_description(),
                'caption' => $media->get_caption(),

                'seo_title' => $media->get_meta('_media_seo_title'),
                'seo_description' => $media->get_meta('_media_seo_description'),
                'metadata' => $metadata
            )
        ));

    } catch (Exception $e) {
        echo json_encode(array('error' => $e->getMessage()));
    }
}

/**
 * 更新媒体信息
 */
function handle_update_media()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(array('error' => '方法不允许'));
        return;
    }

    // CSRF验证
    if (!Todo_Security_Manager::verify_request_csrf('media_update', '_todononce')) {
        http_response_code(403);
        echo json_encode(array('error' => 'CSRF验证失败'));
        return;
    }

    $media_id = isset($_POST['media_id']) ? intval($_POST['media_id']) : 0;

    if (!$media_id) {
        echo json_encode(array('error' => '无效的媒体ID'));
        return;
    }

    try {
        $media = new CMS_Media($media_id);

        if (!$media->exists()) {
            echo json_encode(array('error' => '媒体文件不存在'));
            return;
        }

        // 获取要更新的字段
        $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
        $alt_text = isset($_POST['alt_text']) ? sanitize_text_field($_POST['alt_text']) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '';
        $caption = isset($_POST['caption']) ? sanitize_textarea_field($_POST['caption']) : '';
        $filename = isset($_POST['filename']) ? sanitize_text_field($_POST['filename']) : '';
        $copyright = isset($_POST['copyright']) ? sanitize_text_field($_POST['copyright']) : '';
        $license = isset($_POST['license']) ? sanitize_text_field($_POST['license']) : '';
        $featured = isset($_POST['featured']) ? intval($_POST['featured']) : 0;
        $seo_title = isset($_POST['seo_title']) ? sanitize_text_field($_POST['seo_title']) : '';
        $seo_description = isset($_POST['seo_description']) ? sanitize_textarea_field($_POST['seo_description']) : '';

        // 验证必填字段
        if (empty($title)) {
            echo json_encode(array('error' => '标题不能为空'));
            return;
        }

        // 更新媒体信息
        $update_data = array(
            'media_title' => $title,
            'media_alt' => $caption,
            'media_description' => $description
        );

        $result = $media->update($update_data);

        if ($result) {
            // 更新alt文本
            if ($alt_text !== null) {
                $media->update_meta('_todo_attachment_image_alt', $alt_text);
            }

            // 更新扩展字段
            if ($filename !== '') {
                $media->update_meta('_media_filename', $filename);
            }
            if ($copyright !== '') {
                $media->update_meta('_media_copyright', $copyright);
            }
            if ($license !== '') {
                $media->update_meta('_media_license', $license);
            }
            $media->update_meta('_media_featured', $featured);
            if ($seo_title !== '') {
                $media->update_meta('_media_seo_title', $seo_title);
            }
            if ($seo_description !== '') {
                $media->update_meta('_media_seo_description', $seo_description);
            }

            // 记录操作日志
            log_media_action('update', $media_id, 'success');

            echo json_encode(array(
                'success' => true,
                'message' => '媒体信息更新成功'
            ));
        } else {
            log_media_action('update', $media_id, 'failed');
            echo json_encode(array('error' => '更新失败'));
        }

    } catch (Exception $e) {
        log_media_action('update', $media_id, 'error: ' . $e->getMessage());
        echo json_encode(array('error' => $e->getMessage()));
    }
}

/**
 * 记录操作日志
 *
 * @param string $action 操作类型
 * @param int $media_id 媒体ID
 * @param string $result 操作结果
 */
function log_media_action($action, $media_id, $result)
{
    global $tododb;

    try {
        // 检查activity_log表是否存在
        $tables = $tododb->get_results("SHOW TABLES LIKE '" . DB_PREFIX . "activity_log'");
        if (empty($tables)) {
            return; // 表不存在，跳过日志记录
        }

        // 使用 Todo_Session_Manager 获取管理员用户ID
        $user_id = 0;
        if (class_exists('Todo_Session_Manager')) {
            Todo_Session_Manager::init();
            $admin_user_id = Todo_Session_Manager::get_admin_user_id();
            $user_id = $admin_user_id ? (int) $admin_user_id : 0;
        }
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $data = [
            'user_id' => $user_id,
            'action' => $action,
            'object_type' => 'media',
            'object_id' => $media_id,
            'result' => $result,
            'ip_address' => $ip_address,
            'user_agent' => $user_agent,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $tododb->insert(DB_PREFIX . 'activity_log', $data);
    } catch (PDOException $e) {
        error_log('记录操作日志失败: ' . $e->getMessage());
    }
}
