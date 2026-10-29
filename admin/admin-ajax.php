<?php
/**
 * TodoCMS 统一 AJAX 路由器
 * 处理所有后台 AJAX 请求的统一入口
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 禁用错误显示，确保AJAX响应为纯JSON
ini_set('display_errors', 0);
error_reporting(0);

// 定义 AJAX 请求常量
define('DOING_AJAX', true);

// 提前设置错误处理，确保能捕获所有错误
set_error_handler(function($severity, $message, $file, $line) {
    // 只处理致命错误
    if ($severity === E_PARSE || $severity === E_ERROR || $severity === E_CORE_ERROR) {
        if (ob_get_level() > 0) {
            ob_clean();
        }
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'PHP 错误',
            'message' => $message,
            'file' => defined('ABSPATH') ? str_replace(ABSPATH, '', $file) : $file,
            'line' => $line
        ]);
        exit;
    }
    return false; // 继续使用 PHP 的标准错误处理
}, E_ALL | E_STRICT);

// 只在没有输出缓冲区时启动
if (ob_get_level() === 0) {
    ob_start(null, 0);
}

// 设置基础路径
if (!defined('ABSPATH')) {
    define('ABSPATH', rtrim(dirname(dirname(__FILE__)), '/') . '/');
}

// 加载Composer Autoloader（唯一合法加载点）
require_once ABSPATH . 'vendor/autoload.php';

// 启动会话，确保权限与登录状态判定可用
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 检查是否为AJAX请求
if (
    !isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'
) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Direct access denied']);
    exit;
}

// 加载核心配置
require_once ABSPATH . 'config.php';

// 定义后台常量
if (!defined('IS_ADMIN')) {
    define('IS_ADMIN', true);
}
if (!defined('TODO_ADMIN')) {
    define('TODO_ADMIN', true);
}

// 加载核心引导文件（包含TODO_URL等常量定义）
require_once ABSPATH . 'core/bootstrap.php';

if (class_exists('Todo_Core_Kernel') && is_callable(['Todo_Core_Kernel', 'check_runtime_license']) && !call_user_func(['Todo_Core_Kernel', 'check_runtime_license'])) {
    http_response_code(403);
    echo json_encode(['error' => 'license_invalid', 'message' => '授权未激活'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 加载函数加载器
require_once ABSPATH . 'core/functions/function-loader.php';

// 使用函数加载器加载核心函数
if (class_exists('Todo_Function_Loader')) {
    Todo_Function_Loader::load_core_functions();
}

// 设置JSON响应头
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// 获取动作参数
$action = trim(sanitize_text_field($_POST['action'] ?? $_GET['action'] ?? ''));

if (empty($action)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => '缺少 action 参数']);
    exit;
}

// AJAX 动作路由映射
$ajax_routes = [
    // 媒体管理相关
    'get_media' => 'ajax/media-get.php',
    'update_media' => 'ajax/media-update.php',
    'delete_media' => 'ajax/media-delete.php',
    'media_library' => 'ajax/media-library.php',
    'media_upload' => 'ajax/global-upload.php',
    'media_url_download' => 'ajax/media-url-download.php',

    // 订单管理相关
    'get_orders' => 'ajax/order-management.php',
    'update_order_status' => 'ajax/order-management.php',
    'delete_order' => 'ajax/order-management.php',
    'export_orders' => 'ajax/order-management.php',
    'get_order_details' => 'ajax/order-handler.php',
    // TodoCMS 风格动作（兼容）
    'todo_get_order_details' => 'ajax/order-handler.php',
    'todo_get_order_for_edit' => 'ajax/order-handler.php',
    'update_order'            => 'ajax/order-handler.php',
    'create_order'            => 'ajax/order-handler.php',
    'todo_add_payment_record' => 'ajax/order-handler.php',
    'todo_add_shipping_record' => 'ajax/order-handler.php',
    'todo_test_logistics_query' => 'ajax/order-handler.php',
    'todo_fetch_order_logistics' => 'ajax/order-handler.php',
    'todo_ab_order_sync'        => 'ajax/order-handler.php',
    'send_order_email'          => 'ajax/order-handler.php',
    'todo_send_order_email'     => 'ajax/order-handler.php',

    // 评价管理相关
    'get_review_details'   => 'ajax/comment-handler.php',
    'get_reviews'          => 'ajax/review-handler.php',
    'update_review_status' => 'ajax/comment-handler.php',
    'delete_review'        => 'ajax/comment-handler.php',
    'reply_review'         => 'ajax/comment-handler.php',

    // 留言管理相关
    'get_website_messages' => 'ajax/website-message-handler.php',
    'update_message_status' => 'ajax/comment-handler.php',
    'delete_message' => 'ajax/comment-handler.php',
    'reply_message' => 'ajax/website-message-handler.php',
    'get_message' => 'ajax/comment-handler.php',
    'add_message' => 'ajax/comment-handler.php',
    'update_status' => 'ajax/website-message-handler.php',
    'export_messages' => 'ajax/website-message-handler.php',

    // 产品咨询相关
    'get_product_inquiries' => 'ajax/product-inquiry-handler.php',
    'update_inquiry_status' => 'ajax/comment-handler.php',
    'delete_inquiry' => 'ajax/comment-handler.php',
    'reply_inquiry' => 'ajax/comment-handler.php',
    'get_inquiry' => 'ajax/comment-handler.php',

    // 通用评论相关
    'get_comment_details' => 'ajax/comment-handler.php',
    'update_comment_status' => 'ajax/comment-handler.php',
    'delete_comment' => 'ajax/comment-handler.php',
    'reply_comment' => 'ajax/comment-handler.php',
    'get_comment_tree' => 'ajax/comment-handler.php',

    // 邮件订阅相关
    'get_email_subscriptions' => 'ajax/email-subscription-handler.php',
    'update_subscription_status' => 'ajax/email-subscription-handler.php',
    'delete_subscription' => 'ajax/email-subscription-handler.php',
    'send_newsletter' => 'ajax/email-subscription-handler.php',
    'add_subscription' => 'ajax/email-subscription-handler.php',
    'subscribe' => 'ajax/email-subscription-handler.php',
    'add_subscription_public' => 'ajax/email-subscription-handler.php',
    'send_bulk_email' => 'ajax/email-subscription-handler.php',
    'send_single_email' => 'ajax/email-subscription-handler.php',
    'export_subscriptions' => 'ajax/email-subscription-handler.php',
    'get_subscription' => 'ajax/email-subscription-handler.php',

    // 数据模型相关
    'get_model_fields' => 'ajax/get-model-fields.php',
    'get_view_templates' => 'ajax/get-view-templates.php',
    'get_list_templates' => 'ajax/get-list-templates.php',
    'field_preview' => 'ajax/field-preview.php',
    // 招聘投递相关
    'recruit_apply' => 'ajax/recruit-apply-handler.php',
    // 区域联动相关
    'get_region_children' => 'ajax/region-children.php',

    // 目录管理相关
    'get_directory_tree' => 'ajax/directory-tree.php',
    'get_directory_content' => 'ajax/directory-content.php',

    // 安全相关
    'refresh_csrf_token' => 'ajax/csrf-refresh.php',

    // 报表导出
    'export_order_report' => 'ajax/export-order-report.php',

    // 数据映射导入插件相关
    'get_database_structure' => 'ajax/data-mapping-import.php',
    'parse_sql_file' => 'ajax/data-mapping-import.php',
    'save_mapping_template' => 'ajax/data-mapping-import.php',
    'load_mapping_template' => 'ajax/data-mapping-import.php',
    'delete_mapping_template' => 'ajax/data-mapping-import.php',
    'execute_import' => 'ajax/data-mapping-import.php',
    'get_import_progress' => 'ajax/data-mapping-import.php',
    'get_import_logs' => 'ajax/data-mapping-import.php',
    'clear_import_logs' => 'ajax/data-mapping-import.php',
    'sync_db_schema' => 'ajax/data-mapping-import.php',

    // 升级套餐管理相关
    'upgrade_package' => 'ajax/upgrade-package.php',
    'update_package' => 'ajax/upgrade-package.php',
    'get_package' => 'ajax/upgrade-package.php',
    'delete_package' => 'ajax/upgrade-package.php',

    // 用户等级管理相关
    'user_level' => 'ajax/user-level.php',
    
    // 标识符自动生成（统一入口）
    'slug_generator' => 'ajax/slug-generator.php',

    // 主题工作室相关
    'theme_studio_get_workspace_meta' => 'ajax/theme-studio-handler.php',
    'theme_studio_get_theme_tree'     => 'ajax/theme-studio-handler.php',
    'theme_studio_read_file'          => 'ajax/theme-studio-handler.php',
    'theme_studio_build_scaffold'     => 'ajax/theme-studio-handler.php',
    'theme_studio_generate_spec'      => 'ajax/theme-studio-handler.php',
    'theme_studio_onekey_package'     => 'ajax/theme-studio-handler.php',
    'theme_studio_action'             => 'ajax/theme-studio-handler.php',

    // 升级套餐购买相关
    'upgrade_package_purchase' => 'ajax/upgrade-package-purchase.php',
    'upgrade_payment_status' => 'ajax/upgrade-payment-status.php',
    'check_upgrade_payment_status' => 'ajax/upgrade-payment-status.php',

    // 语言管理相关
    'language_translations' => 'ajax/language/translations.php',
    'language_terms' => 'ajax/language/terms.php',
    'get_language_info' => 'ajax/language/get-info.php',

    // 更新检查相关
    'get_updates' => 'ajax/update-check.php',
    'system_update' => 'ajax/system-update.php',

    'add_comment' => 'ajax/comment-handler.php',
    'add_inquiry' => 'ajax/comment-handler.php',
    'get_comments_list' => 'ajax/comment-handler.php',
    'like_comment' => 'ajax/comment-handler.php',
    'rate_media' => 'ajax/comment-handler.php',

    // 邮件模板相关
    'get_templates_by_category' => 'ajax/mail-templates.php',
    'get_template_content' => 'ajax/mail-templates.php',
    'mail_imap_poll' => 'ajax/mail-imap-poll.php',
    'search_tags' => 'ajax/tag-search.php',
    'save_remote_images' => 'ajax/post-ajax.php',
    'generate_sitemaps' => 'ajax/sitemap-generator.php',
];

// 检查动作是否存在
if (!isset($ajax_routes[$action])) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => '未知动作: ' . $action,
        'debug' => [
            'action_length' => strlen($action),
            'timestamp' => time()
        ]
    ]);
    exit;
}

if (function_exists('todo_is_demo_admin_session') && todo_is_demo_admin_session()) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' || (function_exists('todo_is_demo_write_request') && todo_is_demo_write_request())) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'error' => '演示模式！',
            'message' => '演示模式！'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $forbidden_demo_actions = [
        'get_database_structure', 'parse_sql_file', 'save_mapping_template', 
        'load_mapping_template', 'delete_mapping_template', 'execute_import', 
        'get_import_progress', 'get_import_logs', 'clear_import_logs', 'sync_db_schema',
        'media_url_download', 'get_updates', 'get_directory_tree', 'get_directory_content',
        'export_orders', 'export_subscriptions', 'export_messages', 'export_order_report',
        'get_unlinked_files', 'theme_studio_build_scaffold', 'theme_studio_onekey_package'
    ];
    if (in_array($action, $forbidden_demo_actions, true)) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'error' => '演示模式！'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 获取处理文件路径
$handler_file = dirname(__FILE__) . '/' . $ajax_routes[$action];

// 检查处理文件是否存在
if (!file_exists($handler_file)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'AJAX 处理文件不存在: ' . $ajax_routes[$action]]);
    exit;
}

// 确保输出缓冲区干净（被包含的文件可能会启动自己的输出缓冲区）
$initial_ob_level = ob_get_level();

// 在包含文件前，确保输出缓冲区已启动（防止被包含文件启动缓冲区时出现问题）
if (ob_get_level() === 0) {
    ob_start(null, 0);
    $initial_ob_level = 0;
}

try {
    // ── PRG 幂等守卫：对写操作的重复提交直接返回缓存结果 ──
    // 仅在请求中包含 _submit_key 或 X-Todo-Submit-Key 头时生效，向后兼容旧请求
    $__prg_guard = null;
    if (
        class_exists('Todo_PRG_Guard') &&
        ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    ) {
        $__submit_key = Todo_PRG_Guard::extract_submit_key();
        if (!empty($__submit_key)) {
            $__prg_guard = Todo_PRG_Guard::check($action, 30);
            if ($__prg_guard->is_duplicate()) {
                $__cached = $__prg_guard->get_cached_result();
                if (!headers_sent()) {
                    header('Content-Type: application/json; charset=utf-8');
                    header('X-Todo-PRG: hit'); // 调试标识
                }
                echo json_encode(
                    $__cached !== null ? $__cached : ['success' => true, 'prg' => 'cached'],
                    JSON_UNESCAPED_UNICODE
                );
                exit;
            }
        }
        unset($__submit_key);
    }

    // 处理器可能自行 exit，使用输出缓冲回调在最终刷新时缓存成功结果
    if ($__prg_guard instanceof Todo_PRG_Guard) {
        ob_start(function ($buf, $phase) use ($__prg_guard) {
            if (($phase & PHP_OUTPUT_HANDLER_FINAL) && is_string($buf)) {
                $d = json_decode(trim($buf), true);
                if (is_array($d) && !empty($d['success'])) {
                    $__prg_guard->cache_result($d);
                }
            }
            return $buf;
        });
        $initial_ob_level++;
        Todo_PRG_Guard::set_active($__prg_guard);
    }

    require_once $handler_file;
    
    // 获取所有输出缓冲区的内容
    $buffer = '';
    $current_level = ob_get_level();
    while ($current_level > $initial_ob_level) {
        $buffer = ob_get_clean() . $buffer;
        $current_level = ob_get_level();
    }
    // 如果还有内容在初始缓冲区中，也获取它
    if ($current_level === $initial_ob_level && ob_get_level() > 0) {
        $buffer = ob_get_clean() . $buffer;
    }

    // 处理输出（如果有内容）
    if (is_string($buffer) && trim($buffer) !== '') {
        $trim = trim($buffer);
        $first = substr($trim, 0, 1);
        $is_json_like = ($first === '{' || $first === '[');
        
        if (stripos($trim, 'Direct access denied') === 0) {
            if (!headers_sent()) {
                http_response_code(403);
            }
        }
        
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        if ($__prg_guard instanceof Todo_PRG_Guard && $is_json_like) {
            $__d = json_decode($trim, true);
            if (is_array($__d) && !empty($__d['success'])) {
                $__prg_guard->cache_result($__d);
            }
        }
        echo $is_json_like ? $trim : json_encode(['error' => $trim]);
        exit;
    } else {
        // 如果没有输出，返回成功（某些 AJAX 处理可能不输出内容）
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['success' => true]);
        exit;
    }
} catch (ParseError $e) {
    // 捕获语法错误，提供更详细的错误信息（必须先捕获，因为 ParseError 是 Error 的子类）
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    
    // 获取错误文件的完整路径
    $error_file = $e->getFile();
    $error_file_relative = str_replace(ABSPATH, '', $error_file);
    
    // 尝试读取出错行的代码
    $error_line_code = '';
    if (file_exists($error_file)) {
        $lines = file($error_file);
        if (isset($lines[$e->getLine() - 1])) {
            $error_line_code = trim($lines[$e->getLine() - 1]);
        }
    }
    
    echo json_encode([
        'error' => 'AJAX 处理文件语法错误',
        'message' => $e->getMessage(),
        'file' => $error_file_relative,
        'file_full' => $error_file,
        'line' => $e->getLine(),
        'line_code' => $error_line_code,
        'handler_file' => basename($handler_file),
        'handler_path' => str_replace(ABSPATH, '', $handler_file),
        'trace' => array_slice($e->getTrace(), 0, 5) // 只显示前5层调用栈
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
} catch (Error $e) {
    // 捕获其他类型的错误（如 TypeError、ArgumentCountError 等）
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    $error_file = str_replace(ABSPATH, '', $e->getFile());
    echo json_encode([
        'error' => 'AJAX 处理出错: ' . $e->getMessage(),
        'file' => $error_file,
        'line' => $e->getLine(),
        'handler_file' => basename($handler_file)
    ]);
    exit;
} catch (Exception $e) {
    // 捕获常规异常
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode(['error' => 'AJAX 处理出错: ' . $e->getMessage()]);
    exit;
}

// 注册关闭函数处理未捕获的输出（仅在正常执行完成后才执行）
register_shutdown_function(function () {
    // 检查是否有未处理的致命错误
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_PARSE, E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // 致命错误，确保输出 JSON 错误信息
        if (ob_get_level() > 0) {
            ob_clean();
        }
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => '致命错误',
            'message' => $error['message'],
            'file' => defined('ABSPATH') ? str_replace(ABSPATH, '', $error['file']) : $error['file'],
            'line' => $error['line']
        ]);
        return;
    }
    
});
