<?php
/**
 * TodoCMS 核心引导文件
 * 
 * 初始化系统核心组件，包括常量定义、数据库连接等
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

$__todo_bootstrap_t0 = microtime(true);
$__todo_bootstrap_marks = [
    't0' => $__todo_bootstrap_t0,
];

// 基础路径定义
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

// 确保加载配置文件
if (defined('ABSPATH') && file_exists(ABSPATH . 'config.php')) {
    require_once ABSPATH . 'config.php';
}

// 确保加载 Composer 自动加载器
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}
// 开启系统核心 SPL 自动加载机制 (Lazy Loader)，避免到处 require_once 核心类文件
if (class_exists('Todo_Lazy_Loader')) {
    Todo_Lazy_Loader::getInstance();
}
$__todo_bootstrap_marks['autoload'] = microtime(true);
$__license_token_file = defined('ABSPATH') ? (ABSPATH . 'license.token') : '';
if (!defined('TODO_LICENSE_TOKEN') && $__license_token_file && is_file($__license_token_file)) {
    $token_raw = trim((string)@file_get_contents($__license_token_file));
    if ($token_raw !== '') {
        define('TODO_LICENSE_TOKEN', $token_raw);
    }
}
$__todo_bootstrap_marks['config'] = microtime(true);

if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_');
}

if (!defined('TABLE_PREFIX')) {
    define('TABLE_PREFIX', DB_PREFIX);
}

// 加载性能配置
if (defined('CORE_DIR') && file_exists(CORE_DIR . '/config/performance-config.php')) {
    require_once CORE_DIR . '/config/performance-config.php';
}
if (file_exists(ABSPATH . 'core/config/cache-config.php')) {
    require_once ABSPATH . 'core/config/cache-config.php';
}
$__todo_bootstrap_marks['perf_config'] = microtime(true);

// 标记引导文件已加载
if (!defined('TODO_BOOTSTRAP_LOADED')) {
    define('TODO_BOOTSTRAP_LOADED', true);
}

// 基础常量定义
if (!defined('TODO_LOADED')) define('TODO_LOADED', true);
if (!defined('TODO_LOG_DIR')) define('TODO_LOG_DIR', ABSPATH . 'logs');

// 初始化错误处理器
if (class_exists('Todo_Error_Handler')) {
    Todo_Error_Handler::init(array(
        'log_errors' => true,
    ));
}
$__todo_bootstrap_marks['error_handler'] = microtime(true);

if (file_exists(ABSPATH . 'core/classes/class-core-kernel.php')) {
    require_once ABSPATH . 'core/classes/class-core-kernel.php';
}
if (file_exists(ABSPATH . 'core/classes/class-user.php')) {
    require_once ABSPATH . 'core/classes/class-user.php';
}

global $tododb, $cms_db;
if (defined('INSTALLING') && INSTALLING) {
    $tododb = CMS_DB::get_instance();
} else {
    if (!class_exists('Todo_Core_Kernel') || !is_callable(['Todo_Core_Kernel', 'boot'])) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Kernel missing');
    }
    $tododb = call_user_func(['Todo_Core_Kernel', 'boot']);
    
    // 恢复因自动加载顺序而遗失的早期注册钩子
    if (class_exists('TodoHooks') && is_callable(['TodoHooks', 'flush_buffered_hooks'])) {
        TodoHooks::flush_buffered_hooks();
    }
}

$cms_db = $tododb;
$GLOBALS['tododb'] = $tododb;
$GLOBALS['cms_db'] = $cms_db;

if (!($tododb instanceof CMS_DB)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Database unavailable');
}
$__todo_bootstrap_marks['db'] = microtime(true);

// 尽早加载全局系统配置，消除框架初期 (如 Session, License, DB Version) 产生的大量 N+1 SQL 和 schema 查询
if (class_exists('CMS_Option') && function_exists('todo_db_table_exists') && todo_db_table_exists('system_config')) {
    CMS_Option::load_autoload_options();
}

if (function_exists('todo_maybe_run_db_updates')) {
    $dbu = todo_maybe_run_db_updates();
    if (is_array($dbu) && empty($dbu['ok']) && php_sapi_name() !== 'cli') {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        $msg = isset($dbu['error']) ? (string) $dbu['error'] : 'db_update_failed';
        exit('Database update failed: ' . $msg);
    }
}

if (class_exists('Todo_Core_Kernel') && is_callable(['Todo_Core_Kernel', 'check_runtime_license'])) {
    if (!call_user_func(['Todo_Core_Kernel', 'check_runtime_license'])) {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = (string) parse_url($request_uri, PHP_URL_PATH);
        $is_ajax = (defined('DOING_AJAX') && DOING_AJAX) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        
        $is_admin_request = (defined('IS_ADMIN') && IS_ADMIN) || (defined('TODO_ADMIN') && TODO_ADMIN) || (strpos($path, '/admin/') !== false);
        if (!$is_admin_request) {
            goto __todo_license_guard_done;
        }
        
        if ($is_admin_request && session_status() === PHP_SESSION_NONE && !headers_sent()) {
            if (class_exists('Todo_Session_Manager')) {
                Todo_Session_Manager::init();
            } else {
                @session_start();
            }
        }
        $is_logged_in = $is_admin_request && (isset($_SESSION['admin_user_id']) || isset($_SESSION['todo_admin_uid']));

        $is_update_page = (strpos($request_uri, 'page=updates') !== false || strpos($request_uri, 'page=setting/updates') !== false);
        $is_logout_request = $is_admin_request && (($_GET['action'] ?? '') === 'logout' || strpos($request_uri, 'action=logout') !== false);
        $is_license_verify_request = (strpos($request_uri, 'action=verify_license') !== false || strpos($request_uri, 'action=verify_license_key') !== false);

        $is_login_flow = $is_admin_request && !$is_logged_in;

        if (!$is_update_page && !$is_logout_request && !$is_license_verify_request && !$is_login_flow) {
            http_response_code(403);
            if ($is_ajax) {
                header('Content-Type: application/json; charset=utf-8');
                exit(json_encode(['error' => 'license_invalid', 'message' => '系统授权已失效，请联系管理员。']));
            } else {
                $msg = '<h1 style="font-weight: 500; color: #282832; margin-bottom: 0;">系统授权提醒</h1><p>您的系统授权已失效。</p><p>请联系管理员通过“系统更新”页面重新激活授权。</p>';
                if ($is_admin_request) {
                    $msg .= '<p><a href="index.php?page=updates" style="color:#007bff;text-decoration:none;">立即激活 &raquo;</a></p>';
                }
                exit('<div style="margin:100px auto; max-width:600px; font-family:sans-serif; text-align:center; padding:40px; border-radius: 3px; background-color: #fff; color: #6c6c75; box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);">' . $msg . '</div>');
            }
        }
    }
}
__todo_license_guard_done:

$is_demo_admin = false;
$is_real_admin = false;

if (php_sapi_name() !== 'cli') {
    if (class_exists('Todo_Session_Manager')) {
        Todo_Session_Manager::init();
    } elseif (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    $is_demo_admin = isset($_SESSION['todo_demo_admin']) && (int) $_SESSION['todo_demo_admin'] === 1;
    $is_real_admin = isset($_SESSION['admin_user_id']) || isset($_SESSION['todo_admin_uid']);

    // 全局永久安全规则：拦截匿名访问敏感路径（如 UEditor）
    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path = (string) parse_url($request_uri, PHP_URL_PATH);
    if (strpos($path, '/admin/assets/js/ueditor/php/') !== false) {
        if (!$is_real_admin && !$is_demo_admin) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=UTF-8');
            header('X-Robots-Tag: noindex, nofollow', true);
            exit('403 Forbidden: Administrator login required.');
        }
    }
}

$__todo_demo_active = (defined('TODO_DEMO_ADMIN_ENABLED') && TODO_DEMO_ADMIN_ENABLED && $is_demo_admin);

if ($__todo_demo_active && php_sapi_name() !== 'cli') {
    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
    $path = (string) parse_url($request_uri, PHP_URL_PATH);

    $is_admin_request = (defined('IS_ADMIN') && IS_ADMIN) || (defined('TODO_ADMIN') && TODO_ADMIN) || (strpos($path, '/admin/') !== false);

    if ($is_admin_request && !(isset($_GET['action']) && $_GET['action'] === 'logout')) {
        $deny = false;
        $dangerous_query = false;

        // 基础危险动作检测 (GET 请求中的写操作意图)
        if ($request_method === 'GET' || $request_method === 'HEAD') {
            $q_params = [];
            foreach ($_GET as $k => $v) {
                $k = is_string($k) ? strtolower(trim($k)) : '';
                if ($k === '') {
                    continue;
                }
                if (
                    $k === 'page'
                    || $k === 'auto_clear'
                    || $k === 'doaction'
                    || preg_match('/(^|_)action(\d+)?$/i', $k) === 1
                ) {
                    $q_params[$k] = $v;
                }
            }
            $dangerous_actions = ['delete', 'untrash', 'empty_trash', 'remove', 'purge', 'force_delete', 'update', 'save', 'apply', 'install', 'activate', 'deactivate', 'upgrade', 'import', 'export', 'upload', 'reset'];
            
            foreach ($q_params as $param_key => $val) {
                if ($param_key === 'page') {
                    continue;
                }
                if (is_array($val)) {
                    foreach ($val as $vv) {
                        if (in_array(strtolower(trim((string) $vv)), $dangerous_actions, true)) {
                            $dangerous_query = true;
                            break 2;
                        }
                    }
                } else {
                    if (in_array(strtolower(trim((string) $val)), $dangerous_actions, true)) {
                        $dangerous_query = true;
                        break;
                    }
                }
            }
        }

        // 路径级别拦截
        if (strpos($path, '/admin/assets/js/ueditor/php/') !== false) {
            $deny = true;
        } elseif (strpos($path, '/admin/pages/') !== false) {
            $deny = ($request_method !== 'GET' && $request_method !== 'HEAD');
        } elseif (strpos($path, '/admin/admin-ajax.php') !== false) {
            $action = trim((string)($_POST['action'] ?? $_GET['action'] ?? ''));
            $allowed_actions = ['get_media', 'media_library', 'get_orders', 'get_order_details', 'get_reviews', 'get_website_messages', 'get_message', 'get_product_inquiries', 'get_inquiry', 'get_comment_details', 'get_comment_tree', 'get_email_subscriptions', 'get_subscription', 'get_model_fields', 'get_view_templates', 'get_list_templates', 'field_preview', 'get_region_children', 'refresh_csrf_token', 'csrf_token', 'slug_generator', 'language_translations', 'language_terms', 'get_language_info', 'get_templates_by_category', 'get_template_content'];
            $deny = ($request_method !== 'GET' && $request_method !== 'HEAD') && !in_array($action, $allowed_actions, true);
        } elseif (strpos($path, '/admin/ajax/') !== false) {
            $script = basename($path);
            if ($request_method !== 'GET' && $request_method !== 'HEAD') {
                $deny = true;
            } else {
                $deny = preg_match('/(upload|delete|remove|trash|update|edit|save|import|export|upgrade|install|activate|deactivate|clean|handler|apply|tree|directory|files|unlinked|recalc|status|search|management|rename|move|copy)/i', $script) === 1;
            }
        } else {
            $deny = ($request_method !== 'GET' && $request_method !== 'HEAD');
        }

        if ($dangerous_query || $deny) {
            $is_ajax = (defined('DOING_AJAX') && DOING_AJAX) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
            http_response_code(403);
            if ($is_ajax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => '演示系统｜demo_readonly', 'message' => '演示模式！'], JSON_UNESCAPED_UNICODE);
            } else {
                header('Content-Type: text/plain; charset=UTF-8');
                echo '403 Forbidden: Demo account is in read-only mode.';
            }
            exit;
        }
    }
}

/**
 * --- 系统初始化后续步骤 ---
 */
if (class_exists('Todo_Query_Preloader')) Todo_Query_Preloader::preload_critical_queries();
$__todo_bootstrap_marks['preloader'] = microtime(true);

if (file_exists(ABSPATH . 'core/classes/class-db-prepared.php')) require_once ABSPATH . 'core/classes/class-db-prepared.php';
if (function_exists('todo_init_timezone')) todo_init_timezone();
$__todo_bootstrap_marks['timezone'] = microtime(true);

if (file_exists(ABSPATH . 'core/classes/class-comment-manager.php')) require_once ABSPATH . 'core/classes/class-comment-manager.php';
$__todo_bootstrap_marks['comment_manager'] = microtime(true);

if (function_exists('do_action')) do_action('todo_bootstrap_loaded');
$__todo_bootstrap_marks['bootstrap_hook'] = microtime(true);

if (file_exists(ABSPATH . '/core/classes/class-update-automation.php')) {
    require_once ABSPATH . '/core/classes/class-update-automation.php';
    Todo_Update_Automation::init();
}

if (file_exists(ABSPATH . 'core/classes/class-agent-webhook.php')) {
    require_once ABSPATH . 'core/classes/class-agent-webhook.php';
}

if (file_exists(ABSPATH . 'core/classes/class-language-scanner.php')) {
    require_once ABSPATH . 'core/classes/class-language-scanner.php';
}

if (file_exists(ABSPATH . 'includes/language/init.php')) require_once ABSPATH . 'includes/language/init.php';
$__todo_bootstrap_marks['language_init'] = microtime(true);

// 性能监控输出
if (class_exists('Todo_Performance_Monitor')) {
    $t0 = $__todo_bootstrap_marks['t0'];
    $t_lang = $__todo_bootstrap_marks['language_init'] ?? microtime(true);
    Todo_Performance_Monitor::add_metric('boot_total', 'core/bootstrap.php total', ($t_lang - $t0) * 1000);
}
