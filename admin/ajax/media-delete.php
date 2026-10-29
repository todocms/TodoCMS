<?php
// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

// 禁用错误显示，确保AJAX响应为纯JSON
ini_set('display_errors', 0);
error_reporting(0);

/**
 * 媒体删除AJAX处理
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 定义必要常量
if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}
if (!defined('IS_ADMIN')) {
    define('IS_ADMIN', true);
}

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

// 初始化全局数据库连接
if (!isset($tododb)) {
    $tododb = new CMS_DB();
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

$admin_user_id = null;
if (class_exists('Todo_Session_Manager')) {
    $admin_user_id = Todo_Session_Manager::get_admin_user_id();
}
if (!$admin_user_id && function_exists('get_current_user_id')) {
    $admin_user_id = (int) get_current_user_id();
}
$admin_user = ($admin_user_id && class_exists('CMS_User')) ? new CMS_User((int) $admin_user_id) : null;
$is_platform_admin = false;
if ($admin_user_id && function_exists('todo_is_super_admin') && todo_is_super_admin((int) $admin_user_id)) {
    $is_platform_admin = true;
} elseif (is_object($admin_user) && method_exists($admin_user, 'has_role')) {
    $is_platform_admin = $admin_user->has_role('administrator') || $admin_user->has_role('admin');
}
if (!$is_platform_admin) {
    http_response_code(403);
    echo json_encode(array('error' => '权限不足'));
    exit;
}

// CSRF验证
if (!Todo_Security_Manager::verify_request_csrf('delete_media', '_delete_nonce')) {
    http_response_code(403);
    echo json_encode(array('error' => '安全验证失败，请重新提交'));
    exit;
}

// 检查请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('error' => '方法不允许'));
    exit;
}

// 获取操作类型
$action = isset($_POST['action']) ? $_POST['action'] : '';

switch ($action) {
    case 'delete_media':
        handle_delete_media();
        break;

    case 'bulk_delete':
        handle_bulk_delete();
        break;

    default:
        http_response_code(400);
        echo json_encode(array('error' => '无效的操作'));
        break;
}

/**
 * 处理单个媒体删除
 */
function handle_delete_media()
{
    // 检查媒体ID
    if (!isset($_POST['media_id']) || empty($_POST['media_id'])) {
        http_response_code(400);
        echo json_encode(array('error' => '缺少媒体ID'));
        return;
    }

    $media_id = intval($_POST['media_id']);

    if ($media_id <= 0) {
        http_response_code(400);
        echo json_encode(array('error' => '无效的媒体ID'));
        return;
    }

    try {
        // 检查媒体是否存在
        $media = new CMS_Media($media_id);
        if (!$media->exists()) {
            http_response_code(404);
            echo json_encode(array('error' => '媒体文件不存在'));
            return;
        }

        // 检查是否有其他内容引用此媒体
        $references = check_media_references($media_id);
        if (!empty($references)) {
            echo json_encode(array(
                'error' => '无法删除：此媒体文件正被以下内容引用：' . implode(', ', $references)
            ));
            return;
        }

        // 删除媒体文件
        $result = $media->delete(true);

        if ($result) {
            // 记录删除操作
            log_media_action('delete', $media_id, 'success');
            echo json_encode(array(
                'success' => true,
                'message' => '媒体文件删除成功'
            ));
        } else {
            log_media_action('delete', $media_id, 'failed');
            echo json_encode(array(
                'error' => '删除失败'
            ));
        }

    } catch (Exception $e) {
        log_media_action('delete', $media_id, 'error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(array(
            'error' => $e->getMessage()
        ));
    }
}

/**
 * 处理批量删除
 */
function handle_bulk_delete()
{
    $media_ids = isset($_POST['media_ids']) ? $_POST['media_ids'] : array();

    if (!is_array($media_ids) || empty($media_ids)) {
        echo json_encode(array('error' => '请选择要删除的媒体文件'));
        return;
    }

    $deleted_count = 0;
    $failed_count = 0;
    $errors = array();

    foreach ($media_ids as $media_id) {
        $media_id = intval($media_id);
        if (!$media_id)
            continue;

        try {
            $media = new CMS_Media($media_id);

            if (!$media->exists()) {
                $failed_count++;
                $errors[] = "媒体ID {$media_id} 不存在";
                continue;
            }

            // 检查引用
            $references = check_media_references($media_id);
            if (!empty($references)) {
                $failed_count++;
                $errors[] = "媒体ID {$media_id} 正被其他内容引用，无法删除";
                continue;
            }

            if ($media->delete(true)) {
                $deleted_count++;
                log_media_action('bulk_delete', $media_id, 'success');
            } else {
                $failed_count++;
                $errors[] = "删除媒体ID {$media_id} 失败";
                log_media_action('bulk_delete', $media_id, 'failed');
            }

        } catch (Exception $e) {
            $failed_count++;
            $errors[] = "删除媒体ID {$media_id} 时出错: " . $e->getMessage();
            log_media_action('bulk_delete', $media_id, 'error: ' . $e->getMessage());
        }
    }

    $response = array(
        'success' => $deleted_count > 0,
        'deleted_count' => $deleted_count,
        'failed_count' => $failed_count,
        'message' => "成功删除 {$deleted_count} 个文件"
    );

    if ($failed_count > 0) {
        $response['message'] .= "，{$failed_count} 个文件删除失败";
        $response['errors'] = $errors;
    }

    echo json_encode($response);
}

/**
 * 检查媒体文件的引用
 *
 * @param int $media_id 媒体ID
 * @return array 引用列表
 */
function check_media_references($media_id)
{
    global $tododb;
    $references = array();

    try {
        // 检查文章内容中的引用
        $content_refs = $tododb->get_results(
            "SELECT p.ID, p.post_title, p.post_type 
            FROM " . TABLE_PREFIX . "posts p 
            WHERE p.post_content LIKE ? 
            AND p.post_status != 'trash'",
            ['%todo-image-' . $media_id . '%']
        );

        foreach ($content_refs as $ref) {
            $references[] = $ref->post_title . ' (' . $ref->post_type . ')';
        }

        // 获取媒体文件URL用于匹配
        $media_url = todo_get_attachment_url($media_id);

        // 检查作为特色图片的引用
        if ($media_url) {
            $thumbnail_refs = $tododb->get_results(
                "SELECT p.ID, p.post_title, p.post_type 
                FROM " . TABLE_PREFIX . "posts p 
                WHERE p.featured_image = ? 
                AND p.post_status != 'trash'",
                [$media_url]
            );

            foreach ($thumbnail_refs as $ref) {
                $references[] = $ref->post_title . ' (特色图片)';
            }
        }

        // 检查模型自定义字段中的引用
        if ($media_url) {
            $meta_refs = $tododb->get_results(
                "SELECT p.ID, p.post_title, p.post_type, mfd.field_name 
                FROM " . TABLE_PREFIX . "posts p 
                INNER JOIN " . TABLE_PREFIX . "model_fields_data mfd ON p.ID = mfd.post_id 
                WHERE (mfd.field_value LIKE ? OR mfd.field_value LIKE ?)
                AND p.post_status != 'trash'
                AND p.ID != ?",
                ['%' . $media_id . '%', '%' . $media_url . '%', $media_id]
            );

            foreach ($meta_refs as $ref) {
                $references[] = $ref->post_title . ' (' . $ref->field_name . ')';
            }
        }

    } catch (PDOException $e) {
        error_log('检查媒体引用失败: ' . $e->getMessage());
    }

    return array_unique($references);
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

        $tododb->insert(TABLE_PREFIX . 'activity_log', $data);
    } catch (Exception $e) {
        error_log('记录操作日志失败: ' . $e->getMessage());
    }
}
