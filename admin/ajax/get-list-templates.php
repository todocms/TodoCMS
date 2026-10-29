<?php
/**
 * 获取列表模板 AJAX 处理器
 * 根据模型ID返回该模型可用的列表模板文件（lists_<model_key>*.html）
 *
 * @package TodoCMS
 * @subpackage Admin\Ajax
 */

// 开启输出缓冲，保证只输出JSON
if (function_exists('ob_start')) { ob_start(); }

if (!function_exists('todo_ajax_json')) {
    function todo_ajax_json($payload) {
        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) { ob_end_clean(); }
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    }
}

// 直接访问保护与AJAX标识
if (!defined('DOING_AJAX')) { define('DOING_AJAX', true); }
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    todo_ajax_json(['success' => false, 'error' => 'Direct access denied']);
}

// 设置基础路径
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
}
if (file_exists(ABSPATH . 'core/bootstrap.php')) {
    require_once ABSPATH . 'core/bootstrap.php';
}

// 管理端上下文与安全校验
if (!defined('IS_ADMIN')) { define('IS_ADMIN', true); }

if (!Todo_Security_Manager::verify_request_csrf('category_action')) {
    header('HTTP/1.1 403 Forbidden');
    todo_ajax_json(['success' => false, 'error' => 'CSRF verification failed']);
}

// 权限校验
if (!is_user_logged_in() || !current_user_can('manage_categories')) {
    header('HTTP/1.1 403 Forbidden');
    todo_ajax_json(['success' => false, 'error' => 'Permission denied']);
}

// 仅允许POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    todo_ajax_json(['success' => false, 'error' => 'Invalid request method']);
}

$action = isset($_POST['action']) ? trim($_POST['action']) : '';
if ($action !== 'get_list_templates') {
    todo_ajax_json(['success' => false, 'error' => 'Invalid action']);
}

// 读取模型ID
$model_id = isset($_POST['model_id']) ? intval($_POST['model_id']) : 0;
if ($model_id <= 0) {
    todo_ajax_json(['success' => false, 'error' => 'Missing model_id']);
}

try {
    require_once ABSPATH . 'config.php';
    // 加载模板函数，确保 get_template_directory 可用
    $CORE_PATH = defined('CORE_DIR') ? CORE_DIR : (defined('ABSPATH') ? ABSPATH . 'core' : __DIR__ . '/../../core');
    if (file_exists($CORE_PATH . '/functions/template.php')) {
        require_once $CORE_PATH . '/functions/template.php';
    }

    global $tododb;
    if (!isset($tododb)) {
        $tododb = new CMS_DB(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    }

    // 查询模型key 和 list_template
    $model = $tododb->get_row("SELECT model_key, list_template FROM {$tododb->prefix}models WHERE model_id = ?", [$model_id]);
    if (!$model || empty($model->model_key)) {
        todo_ajax_json(['success' => true, 'model' => '', 'default' => '', 'templates' => []]);
    }
    $model_key = $model->model_key;
    $model_list_template = $model->list_template ?? '';

    $template_dir = get_template_directory();
    $templates = [];

    if (is_dir($template_dir)) {
        $files = glob($template_dir . '/lists_' . $model_key . '*.html') ?: [];
        
        // 如果模型配置了自定义 list_template，按其词干也做 glob 匹配同类模板
        if (!empty($model_list_template)) {
            $stem = pathinfo($model_list_template, PATHINFO_FILENAME); // e.g. lists_custom-cat
            $siblings = glob($template_dir . '/' . $stem . '*.html') ?: [];
            $files = array_merge($files, $siblings);
        }
        $files = array_unique($files);

        $default_name = '';
        if (!empty($model_list_template) && file_exists($template_dir . '/' . $model_list_template)) {
            $default_name = $model_list_template;
        } else {
            $default_file = $template_dir . '/lists_' . $model_key . '.html';
            $default_name = file_exists($default_file) ? basename($default_file) : '';
        }

        foreach ($files as $file) {
            $name = basename($file);
            if ($name === $default_name) {
                // 跳过默认模板文件，默认由空值选项表示
                continue;
            }
            $templates[] = [
                'file' => $name,
                'display' => $name
            ];
        }
    }

    todo_ajax_json([
        'success' => true,
        'model' => $model_key,
        'default' => $default_name ? pathinfo($default_name, PATHINFO_FILENAME) : 'lists_' . $model_key,
        'templates' => $templates
    ]);
} catch (Exception $e) {
    error_log('获取列表模板失败: ' . $e->getMessage());
    todo_ajax_json(['success' => false, 'error' => '获取列表模板失败']);
}
