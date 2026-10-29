<?php
/**
 * 数据映射导入插件AJAX处理器
 * 统一处理数据映射导入插件的所有AJAX请求
 */

if (!defined('DOING_AJAX') || !DOING_AJAX) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Direct access denied']);
    exit;
}

// 检查AJAX请求头
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Direct access denied']);
    exit;
}

// 确保必要的常量已定义

$security = Todo_Security_Manager::getInstance();
// 根据请求中的 action 构建对应的 nonce 字段名（与前端一致：`_<action>_todononce`）
$reqAction = sanitize_text_field($_POST['action'] ?? $_GET['action'] ?? 'default');
$nonceField = '_' . $reqAction . '_todononce';
if (!$security->verify_request_csrf($reqAction, $nonceField)) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: application/json');
    header('X-Robots-Tag: noindex, nofollow', true);
    echo json_encode(['error' => 'CSRF verification failed']);
    exit;
}

if (!defined('TODO_CONTENT_DIR')) {
    define('TODO_CONTENT_DIR', ABSPATH . 'content');
}

// 加载TodoCMS核心系统
require_once ABSPATH . 'core/bootstrap.php';

// 初始化安全管理器
if (class_exists('Todo_Security_Manager')) {
    Todo_Security_Manager::init();
}

// 获取动作参数
$action = sanitize_text_field($_POST['action'] ?? $_GET['action'] ?? '');

if (empty($action)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => '缺少 action 参数']);
    exit;
}

// 数据映射导入插件的动作映射
$plugin_actions = [
    'get_database_structure' => 'todo_ajax_get_database_structure',
    'parse_sql_file' => 'todo_ajax_parse_sql_file',
    'save_mapping_template' => 'todo_ajax_save_mapping_template',
    'load_mapping_template' => 'todo_ajax_load_mapping_template',
    'delete_mapping_template' => 'todo_ajax_delete_mapping_template',
    'execute_import' => 'todo_ajax_execute_import',
    'get_import_progress' => 'todo_ajax_get_import_progress',
    'get_import_logs' => 'todo_ajax_get_import_logs',
    'clear_import_logs' => 'todo_ajax_clear_import_logs',
    'sync_db_schema' => 'todo_ajax_sync_db_schema'
];

// 检查动作是否存在
if (!isset($plugin_actions[$action])) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => '未知的插件动作: ' . $action]);
    exit;
}

// 加载插件的AJAX处理器
$ajax_handlers_file = TODO_CONTENT_DIR . '/plugins/data-mapping-import/includes/ajax-handlers.php';
if (!file_exists($ajax_handlers_file)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => '数据映射导入插件AJAX处理器未找到']);
    exit;
}

require_once $ajax_handlers_file;

// 直接调用对应的AJAX处理器类
try {
    switch ($action) {
        case 'get_database_structure':
            new GetDatabaseStructureAjax();
            break;
        case 'parse_sql_file':
            new ParseSqlFileAjax();
            break;
        case 'save_mapping_template':
            new SaveMappingTemplateAjax();
            break;
        case 'load_mapping_template':
            new LoadMappingTemplateAjax();
            break;
        case 'delete_mapping_template':
            new DeleteMappingTemplateAjax();
            break;
        case 'execute_import':
            new ExecuteImportAjax();
            break;
        case 'get_import_progress':
            new GetImportProgressAjax();
            break;
        case 'get_import_logs':
            new GetImportLogsAjax();
            break;
        case 'clear_import_logs':
            new ClearImportLogsAjax();
            break;
        case 'sync_db_schema':
            new SyncDbSchemaAjax();
            break;
        default:
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => '未知的插件动作: ' . $action]);
            exit;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => '插件处理出错: ' . $e->getMessage()]);
    exit;
} catch (Error $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => '插件处理出错: ' . $e->getMessage()]);
    exit;
}
