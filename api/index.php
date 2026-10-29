<?php
// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
/**
 * API入口文件
 *
 * 处理所有API请求的主入口
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// Load Composer Autoloader (must be loaded first to define ABSPATH via constants.php)
require_once dirname(__DIR__) . '/vendor/autoload.php';

// 加载配置文件
if (file_exists(ABSPATH . 'config.php')) {
    require_once ABSPATH . 'config.php';
} else {
    header('Content-Type: application/json');
    echo json_encode([
        'error' => true,
        'code' => 'config_missing',
        'message' => '配置文件不存在'
    ]);
    exit;
}

// 加载并启动 TodoCMS 防火墙防采集拦截模块 (防止 API 被直接批量拉取结构化数据)
if (file_exists(ABSPATH . 'core/classes/class-firewall-manager.php')) {
    Todo_Firewall_Manager::handle_request();
}

// 加载API函数
require_once ABSPATH . 'core/functions/api-functions.php';

// 加载bootstrap
require_once ABSPATH . 'core/bootstrap.php';

// 加载 API 辅助函数（避免缺失的主题/前台函数导致端点报错）
require_once ABSPATH . 'api/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
if ((defined('TODO_DEMO_ADMIN_ENABLED') && constant('TODO_DEMO_ADMIN_ENABLED')) && isset($_SESSION['todo_demo_admin']) && (int) $_SESSION['todo_demo_admin'] === 1) {
    $rm = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
    if (!in_array($rm, ['GET', 'HEAD', 'OPTIONS'], true)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['error' => true, 'code' => 'demo_readonly', 'message' => '演示模式！'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 初始化CMS
cms_init();

// 设置内容类型为JSON
header('Content-Type: application/json; charset=utf-8');

// 设置CORS头
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

// 处理OPTIONS请求（预检请求）
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// 获取请求路径
$request_path = isset($_SERVER['PATH_INFO']) ? trim($_SERVER['PATH_INFO'], '/') : '';
if (empty($request_path) && isset($_SERVER['REQUEST_URI'])) {
    $request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $base_path = dirname($_SERVER['SCRIPT_NAME']);
    if (strpos($request_uri, $base_path) === 0) {
        $request_path = substr($request_uri, strlen($base_path));
    } else {
        $request_path = $request_uri;
    }
    $request_path = trim($request_path, '/');
}

// 优先检查是否有对应的独立API脚本文件（例如 api/sms/send.php）
$clean_subpath = ltrim($request_path, '/');
if (preg_match('#^v[0-9]+/#', $clean_subpath)) {
    $clean_subpath = substr($clean_subpath, strpos($clean_subpath, '/') + 1);
}
$clean_subpath = str_replace(['../', '..\\'], '', $clean_subpath);
if ($clean_subpath !== '') {
    $target_script = ABSPATH . 'api/' . $clean_subpath;
    if (!is_file($target_script) && is_file($target_script . '.php')) {
        $target_script .= '.php';
    }
    $api_root = realpath(ABSPATH . 'api');
    $real_target = realpath($target_script);
    if ($real_target && $api_root && strpos($real_target, $api_root) === 0 && is_file($real_target)) {
        $target_basename = basename($real_target);
        if ($target_basename !== 'index.php' && $target_basename !== 'router.php' && $target_basename !== 'helpers.php') {
            require $real_target;
            exit;
        }
    }
}

// 获取请求方法
$request_method = $_SERVER['REQUEST_METHOD'];

// 获取请求参数
$request_params = [];
switch ($request_method) {
    case 'GET':
        $request_params = $_GET;
        break;
    case 'POST':
    case 'PUT':
    case 'DELETE':
        $input = file_get_contents('php://input');
        if (!empty($input)) {
            $json_params = json_decode($input, true);
            if ($json_params !== null) {
                $request_params = $json_params;
            }
        }

        // 合并POST参数
        if (!empty($_POST)) {
            $request_params = array_merge($request_params, $_POST);
        }
        break;
}

// 加载API类
$api = Todo_API::get_instance();

// 加载API端点文件
$api_endpoints_dir = ABSPATH . 'api/endpoints/';
if (is_dir($api_endpoints_dir)) {
    $endpoint_files = glob($api_endpoints_dir . '*.php');
    foreach ($endpoint_files as $endpoint_file) {
        require_once $endpoint_file;
    }
}

// 应用钩子，允许插件注册API路由
do_action('api_init');

// 处理API请求
try {
    $api->handle_request($request_path, $request_method, $request_params);
} catch (Throwable $e) {
    send_api_error(500, $e->getMessage());
}
